<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet;

use Moggi\Backend\Backend;
use Moggi\IR\Module;
use Moggi\Syntax\Ast\TypeNode;

use function Moggi\Backend\DotNet\Codegen\emitModule;
use function Moggi\Backend\DotNet\Dependencies\dotNetDeclaredSignature;
use function Moggi\Backend\DotNet\Foreign\clrSigFromMoggiType;
use function Moggi\Backend\DotNet\Naming\artifactPath;
use function Moggi\Backend\DotNet\Naming\symbolName;
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
        return []; // Moggi.Rt is generated into the build, not copied
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
            $out[\str_replace('.', '/', $typeName) . '.il'] = $il;
        }

        return $out;
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
        return clrSigFromMoggiType(
            $type,
            $pathInfo['dispatch'],
            $kind,
            $pathInfo['classPath'],
            $pathInfo['member'],
            dotNetDeclaredSignature($name),
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
        $member = $pathInfo['member'] ?? '';
        if ($pathInfo['dispatch'] === 'intrinsic'
            && !\in_array($member, ['__cast', '__default', '__null'], true)) {
            return "dotnet foreign intrinsic `{$member}` is not supported";
        }

        return null;
    }
}
