<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm;

use Moggi\Backend\Backend;
use Moggi\Foreign\ForeignTypeTable;

use function Moggi\Backend\Inspect\describeJvmEmit;
use function Moggi\Backend\Jvm\Codegen\emitModule;

use function Moggi\Backend\Jvm\Foreign\jvmDescriptorFromMoggiType;
use function Moggi\Backend\Jvm\Foreign\resolveJvmForeignOverload;
use function Moggi\Backend\Jvm\Naming\artifactPath;
use function Moggi\Backend\Jvm\Verify\javapClassTable;
use function Moggi\Backend\Jvm\Verify\javapMethodIsVarargs;
use function Moggi\Backend\Jvm\Naming\symbolName;
use function Moggi\Backend\Jvm\Verify\verifyForeignImportAgainstHost;
use function Moggi\Debug\mapPathForArtifact;

use Moggi\IR\Module;
use Moggi\Syntax\Ast\TypeNode;

require_once __DIR__ . '/runtime_abi.php';
require_once __DIR__ . '/package.php';
require_once __DIR__ . '/codegen.php';
require_once __DIR__ . '/frames.php';
require_once __DIR__ . '/naming.php';
require_once __DIR__ . '/foreign.php';
require_once __DIR__ . '/dependencies.php';

final class JvmBackend implements Backend
{
    public function extension(): string
    {
        return '.class';
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

    /** The JVM emit is a function of the IR alone. */
    public function emitCacheDiscriminator(): string
    {
        return '';
    }

    /** @return array<string, string> */
    public function emit(Module $ir, string $sourcePath, array $options = []): array
    {
        $emitted = emitModule($ir, $sourcePath, $options);
        $out = [];
        foreach ($emitted as $key => $bytes) {
            if ($key === '__moggi.map__') {
                $artifact = (string) ($options['outputRelative'] ?? 'Module.class');
                $out[mapPathForArtifact($artifact)] = $bytes;

                continue;
            }
            $out[$key . '.class'] = $bytes;
        }

        return $out;
    }

    /**
     * The JVM backend emits one classfile per class and no auxiliary files of
     * its own, so there is nothing to consolidate.
     *
     * @param array<string, string> $emit
     * @return array<string, string>
     */
    public function foldProgramEmit(array $emit): array
    {
        return $emit;
    }

    public function describeEmit(array $emit, array $focusNames = []): string
    {
        return describeJvmEmit($emit, $focusNames);
    }

    public function packageOutput(string $outputRoot, array $options = []): void
    {
        packageJvmOutput($outputRoot, $options);
    }

    public function foreignNativeSig(
        TypeNode $type,
        string $kind,
        array $pathInfo,
        string $name,
        ?string $module = null,
    ): ?string {
        $inferred = jvmDescriptorFromMoggiType(
            $type,
            $pathInfo['dispatch'],
            $kind,
            $pathInfo['classPath'],
            $pathInfo['hostMember'],
            $module,
        );
        if ($kind !== 'function'
            || ($pathInfo['dispatch'] ?? '') === 'intrinsic'
            || ($pathInfo['classPath'] ?? '') === ''
        ) {
            return $inferred;
        }

        $classPath = (string) $pathInfo['classPath'];
        $member = (string) ($pathInfo['hostMember'] ?? '');
        $lookup = $member === '<init>' ? null : $member;
        $table = javapClassTable($classPath);
        if ($table === null || ! ($table['found'] ?? false)) {
            return $inferred;
        }
        if ($lookup === null) {
            $lookup = (string) ($table['simple'] ?? '');
        }
        $resolution = resolveJvmForeignOverload(
            $table['methods'][$lookup] ?? [],
            $inferred,
            static fn (string $descriptor): ?bool => javapMethodIsVarargs($classPath, $member, $descriptor),
            $type,
        );
        if ($resolution['status'] === 'omit' || $resolution['status'] === 'pack') {
            return $resolution['descriptor'];
        }
        if ($resolution['status'] === 'ambiguous') {
            throw new \RuntimeException(
                "jvm member `{$classPath}.{$member}` is ambiguous for `{$inferred}`:"
                . ' more than one host overload takes these arguments plus a trailing array'
                . ' (' . \implode(', ', $resolution['candidates'] ?? []) . ')'
                . ' — the FFI cannot choose between them',
            );
        }
        if (isset($resolution['detail'])) {
            throw new \RuntimeException(
                "jvm member `{$classPath}.{$member}` cannot take `{$inferred}`: " . $resolution['detail'],
            );
        }

        return $inferred;
    }

    public function validateForeignImport(
        string $kind,
        array $pathInfo,
        bool $instanceReceiverIsHandle,
    ): ?string {
        if ($pathInfo['dispatch'] === 'global') {
            return 'JVM foreign path must be `Class:member` or `Class.member`; bare host names are not supported';
        }

        return null;
    }

    /**
     * Ask the host class itself, through `javap`, whether the member and the descriptor this import
     * is about to emit exist on it. See
     * {@see \Moggi\Backend\Jvm\Verify\verifyForeignImportAgainstHost} for when the question is
     * skipped instead of answered.
     */
    public function verifyForeignImport(
        string $kind,
        array $pathInfo,
        TypeNode $type,
        ?string $nativeSig,
        string $name,
    ): ?string {
        return verifyForeignImportAgainstHost($kind, $pathInfo, $type, $nativeSig, $name);
    }

    /**
     * The host types this target's checked modules declare. One table per backend object, which is
     * the object the checker's `backendById()` and the emitter both reach.
     */
    private ?ForeignTypeTable $foreignTypes = null;

    public function foreignTypes(): ForeignTypeTable
    {
        return $this->foreignTypes ??= new ForeignTypeTable();
    }
}
