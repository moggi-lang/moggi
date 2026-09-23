<?php declare(strict_types=1);

namespace Moggi\Backend\Php;

use Moggi\Backend\Backend;
use Moggi\IR\Module;
use Moggi\Syntax\Ast\TypeNode;

use function Moggi\Backend\Inspect\describePhpEmit;
use function Moggi\Backend\Php\Codegen\emit as emitPhp;
use function Moggi\Backend\Php\Naming\basePhpFunctionName;

final class PhpBackend implements Backend
{
    public function extension(): string
    {
        return '.php';
    }

    public function artifactPath(string $moduleName): ?string
    {
        // PHP modules map to files by source path, not by module name; the
        // caller keeps the source-relative layout.
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
        return emitPhp($ir, $sourcePath, $options);
    }

    public function describeEmit(string|array $emit, array $focusNames = []): string
    {
        return describePhpEmit($emit, $focusNames);
    }

    public function packageOutput(string $outputRoot, array $options = []): void
    {
        packagePhpOutput($outputRoot, $options, $this->runtimeFiles());
    }

    public function foreignNativeSig(
        TypeNode $type,
        string $kind,
        array $pathInfo,
        string $name,
    ): ?string {
        return null;
    }

    public function validateForeignImport(
        string $kind,
        array $pathInfo,
        bool $instanceReceiverIsHandle,
    ): ?string {
        if ($pathInfo['dispatch'] === 'instance' && !$instanceReceiverIsHandle) {
            return 'instance foreign import requires handle as first argument';
        }

        return null;
    }
}
