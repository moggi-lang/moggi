<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet;

use Moggi\Backend\Backend;
use Moggi\IR\Module;
use Moggi\Syntax\Ast\TypeNode;

use function Moggi\Backend\DotNet\Codegen\emitModule;
use function Moggi\Backend\DotNet\Dependencies\dotNetSignatureEntry;
use function Moggi\Backend\DotNet\Verify\verifyDotNetForeignImport;
use function Moggi\Backend\DotNet\Foreign\clrSigFromMoggiType;
use function Moggi\Backend\DotNet\Naming\artifactPath;
use function Moggi\Backend\DotNet\Naming\fnWrapperBundlePath;
use function Moggi\Backend\DotNet\Naming\isFnWrapperArtifact;
use function Moggi\Backend\DotNet\Naming\symbolName;
use function Moggi\Backend\DotNet\Naming\typeArtifactPath;
use function Moggi\Backend\Inspect\describeDotNetEmit;
use function Moggi\Debug\mapPathForArtifact;

require_once __DIR__ . '/dependencies.php';
require_once __DIR__ . '/naming.php';
require_once __DIR__ . '/il.php';
require_once __DIR__ . '/package.php';
require_once __DIR__ . '/runtime_abi.php';
require_once __DIR__ . '/foreign.php';
require_once __DIR__ . '/codegen.php';

/**
 * .NET / CLR backend: emits ILASM (ECMA-335 text) for Moggi modules and
 * integrates generated Moggi.Rt IL. {@see packageOutput} assembles with
 * CoreCLR `ilasm` when available (falls back to Microsoft.NET.Sdk.IL /
 * `dotnet build`); optional Native AOT via `dotnet publish`.
 */
final class DotNetBackend implements Backend
{
    public function extension(): string
    {
        return '.il';
    }

    public function artifactPath(string $moduleName): ?string
    {
        return artifactPath($moduleName);
    }

    public function symbolName(string $name): string
    {
        return symbolName($name);
    }

    /** @return list<string> */
    public function runtimeFiles(): array
    {
        return [];
    }

    /**
     * The content hash of every `dotnet/*.json` sidecar the compile sees —
     * or `''` when there is none, keeping the old key for sidecar-free builds.
     *
     * A sidecar changes emit without changing any declaration (the optional
     * tail it pads, the assembly scope it names), and the source closure only
     * hashes `.mog` files, so without this a regenerated binding reuses the
     * previous build's IL. The JVM and PHP backends need nothing here: their
     * binders emit declarations alone, which the closure already content-keys.
     */
    public function emitCacheDiscriminator(): string
    {
        return Dependencies\dotNetSidecarDiscriminator();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    public function emit(Module $ir, string $sourcePath, array $options = []): string|array
    {
        $types = emitModule($ir, $sourcePath, $options);
        $out = [];
        foreach ($types as $typeName => $il) {
            if ($typeName === '__moggi.map__') {
                $artifact = (string) ($options['outputRelative'] ?? 'Module.il');
                $out[mapPathForArtifact($artifact)] = $il;
                continue;
            }
            $out[typeArtifactPath($typeName)] = $il;
        }

        return $out;
    }

    /**
     * Gather every per-function Fn wrapper (`Moggi/Fn/*_<arity>.il`) into the
     * one shared bundle ({@see \Moggi\Backend\DotNet\Naming\fnWrapperBundlePath()}).
     *
     * Each wrapper class is a pure function of its (target, arity), so
     * duplicates from different modules already collapsed onto one path with
     * identical bytes during the merge; what remains is sorted for a
     * deterministic bundle. Module classes and source maps pass through
     * untouched.
     *
     * @param array<string, string> $emit
     * @return array<string, string>
     */
    public function foldProgramEmit(array $emit): array
    {
        $wrappers = [];
        foreach ($emit as $path => $bytes) {
            if (isFnWrapperArtifact($path)) {
                $wrappers[$path] = $bytes;
                unset($emit[$path]);
            }
        }
        if ($wrappers === []) {
            return $emit;
        }
        \ksort($wrappers, \SORT_STRING);
        $bundle = '';
        foreach ($wrappers as $bytes) {
            $bundle .= \rtrim($bytes, "\n") . "\n";
        }
        $emit[fnWrapperBundlePath()] = $bundle;

        return $emit;
    }

    public function describeEmit(string|array $emit, array $focusNames = []): string
    {
        return describeDotNetEmit($emit, $focusNames);
    }

    /** @param array<string, mixed> $options */
    public function packageOutput(string $outputRoot, array $options = []): void
    {
        packageDotNetOutput($outputRoot, $options);
    }

    public function foreignNativeSig(
        TypeNode $type,
        string $kind,
        array $pathInfo,
        string $name,
    ): ?string {
        $sidecar = ($kind === 'function' && ($pathInfo['dispatch'] ?? '') !== 'intrinsic')
            ? dotNetSignatureEntry($name, $pathInfo['declaringRoot'] ?? null)
            : null;

        return clrSigFromMoggiType(
            $type,
            $pathInfo['dispatch'],
            $kind,
            $pathInfo['classPath'],
            $pathInfo['hostMember'],
            $sidecar,
            $name,
        );
    }

    public function validateForeignImport(
        string $kind,
        array $pathInfo,
        bool $instanceReceiverIsHandle,
    ): ?string {
        if ($pathInfo['dispatch'] === 'global') {
            return 'dotnet foreign path must be `Type:member` or `Type.member`; bare host names are not supported';
        }
        $member = $pathInfo['hostMember'] ?? '';
        if ($pathInfo['dispatch'] === 'intrinsic'
            && !\in_array($member, ['__cast', '__default', '__null'], true)) {
            return "dotnet foreign intrinsic `{$member}` is not supported";
        }

        return null;
    }

    /**
     * The CLR verdict: the emitted signature is compared against reflection over the compile's
     * reference set (vendored assemblies plus the target framework), so a member that does not
     * exist — or a shape the host does not declare — is a compile error naming the host, the way
     * `javap` answers for the JVM backend. A missing `dotnet` refuses the declaration; a tool
     * that breaks trying warns and leaves it unverified.
     */
    public function verifyForeignImport(
        string $kind,
        array $pathInfo,
        TypeNode $type,
        ?string $nativeSig,
        string $name,
    ): ?string {
        return verifyDotNetForeignImport($kind, $pathInfo, $type, $nativeSig, $name);
    }
}
