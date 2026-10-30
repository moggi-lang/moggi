<?php declare(strict_types=1);

namespace Moggi\Backend\Php;

use Moggi\Backend\Backend;
use Moggi\Foreign\ForeignTypeTable;

use function Moggi\Backend\Inspect\describePhpEmit;
use function Moggi\Backend\Php\Codegen\emitModule;

use function Moggi\Backend\Php\Dependencies\composerIsBundled;
use function Moggi\Backend\Php\Naming\basePhpFunctionName;
use function Moggi\Backend\Php\Verify\verifyForeignImportAgainstHost;

use Moggi\IR\Module;
use Moggi\Syntax\Ast\TypeNode;

require_once __DIR__ . '/codegen.php';
require_once __DIR__ . '/verify.php';

final class PhpBackend implements Backend
{
    public function extension(): string
    {
        return '.php';
    }

    public function artifactPath(string $moduleName): ?string
    {
        return null;
    }

    public function symbolName(string $name): string
    {
        return basePhpFunctionName($name);
    }

    /**
     * The PHP runtime source. The `Backend` contract is about sources; the
     * deployed name is `RUNTIME_OUTPUT_PATH` at the root of the generated tree
     * (see `runtimeOutputPath`).
     *
     * @return list<string>
     */
    public function runtimeFiles(): array
    {
        $path = __DIR__ . '/runtime.php';

        return \is_file($path) ? [$path] : [];
    }

    public function emit(Module $ir, string $sourcePath, array $options = []): string|array
    {
        return emitModule($ir, $sourcePath, $options);
    }

    /**
     * The PHP backend emits one file per module and no auxiliary files, so
     * there is nothing to consolidate.
     *
     * @param array<string, string> $emit
     * @return array<string, string>
     */
    public function foldProgramEmit(array $emit): array
    {
        return $emit;
    }

    public function describeEmit(string|array $emit, array $focusNames = []): string
    {
        return describePhpEmit($emit, $focusNames);
    }

    public function packageOutput(string $outputRoot, array $options = []): void
    {
        packagePhpOutput($outputRoot, $options, $this->runtimeFiles());
    }

    /**
     * A bundled Composer tree changes what the entry module emits (it requires
     * the autoloader), so the emit cache has to be able to tell the two builds
     * apart.
     */
    public function emitCacheDiscriminator(): string
    {
        return composerIsBundled() ? 'composer' : '';
    }

    public function foreignNativeSig(
        TypeNode $type,
        string $kind,
        array $pathInfo,
        string $name,
        ?string $module = null,
    ): ?string {
        return null;
    }

    public function validateForeignImport(
        string $kind,
        array $pathInfo,
        bool $instanceReceiverIsHandle,
    ): ?string {
        if ($pathInfo['dispatch'] === 'instance' && ! $instanceReceiverIsHandle) {
            return 'instance foreign import requires handle as first argument';
        }

        return null;
    }

    /**
     * PHP is the one backend whose host is the compiler's own process, so the declaration can be
     * checked against the functions, methods, constructors and constants that would run it. See
     * {@see \Moggi\Backend\Php\Verify\verifyForeignImportAgainstHost} for what is and is not asked.
     */
    public function verifyForeignImport(
        string $kind,
        array $pathInfo,
        TypeNode $type,
        ?string $nativeSig,
        string $name,
    ): ?string {
        return verifyForeignImportAgainstHost($kind, $pathInfo, $type, $name);
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
