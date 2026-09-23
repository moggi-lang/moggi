<?php declare(strict_types=1);

namespace Moggi\Backend\Php;

/**
 * PHP build finalization.
 *
 * Mirrors the JVM `Package` and .NET `Package` modules: the backend class only
 * collects the build inputs, and this module turns the output directory into
 * the deployable artifact. For PHP that is a single self-contained PHAR
 * (equivalent to the JVM JAR / .NET assembly); `--unpacked` leaves the
 * generated tree on disk for inspection instead.
 */

/**
 * Where the PHP runtime lands inside a build output, relative to its root.
 *
 * The source lives with the backend (`src/backend/php/runtime.php`) and is copied
 * to the root of the generated tree under this name, so every emitted module
 * `require`s the same relative path the PHAR stub loads. The leading underscore
 * keeps the deployed name out of the userland namespace: `runtime.php` (or a
 * `runtime/` directory) can still be a user module mirroring its `.mog` source.
 */
const RUNTIME_OUTPUT_PATH = '_runtime.php';

/**
 * Finalize a PHP build directory.
 *
 * Every mode needs the runtime on disk first: the PHAR is built from the output
 * directory, and `--unpacked` leaves it there as the artifact.
 *
 * @param list<string> $runtimeFiles absolute runtime source paths
 * @param array<string, mixed> $options unpacked?, pharName?
 */
function packagePhpOutput(string $outputRoot, array $options, array $runtimeFiles): void
{
    copyRuntimeToOutputDir($runtimeFiles, $outputRoot);

    // Explicit unpacked/development build: leave the generated PHP tree on disk
    // for inspection (same layout as the JVM/.NET unpacked build).
    if (!empty($options['unpacked'])) {
        return;
    }

    // Default: package the output directory into a single PHAR (the PHP counterpart of the
    // JVM JAR / .NET DLL); each module carries its own source map.
    $pharName = (string) ($options['pharName'] ?? 'moggi-app.phar');
    packagePhar($outputRoot, $outputRoot . '/' . $pharName, $options);
}

/** @param list<string> $runtimeFiles */
function copyRuntimeToOutputDir(array $runtimeFiles, string $outputRoot): void
{
    foreach ($runtimeFiles as $src) {
        $dst = $outputRoot . '/' . runtimeOutputPath($src);
        $dstDir = dirname($dst);
        if (!\is_dir($dstDir) && !mkdir($dstDir, 0777, true) && !\is_dir($dstDir)) {
            throw new \RuntimeException("cannot create runtime directory {$dstDir}");
        }
        if (!copy($src, $dst)) {
            throw new \RuntimeException("cannot copy runtime to {$dst}");
        }
    }
}


/**
 * Deployed name of one runtime source, relative to the output root.
 *
 * Runtime files sit flat at the root of the generated tree, next to the mirrored
 * module paths. The core runtime is renamed because its source name is a
 * plausible userland module name.
 */
function runtimeOutputPath(string $source): string
{
    if (realpath($source) === realpath(__DIR__ . '/runtime.php')) {
        return RUNTIME_OUTPUT_PATH;
    }

    return basename($source);
}

/**
 * Package the output directory into a single self-contained PHAR.
 *
 * The PHAR contains:
 *   - all emitted .php module files
 *   - `_runtime.php`
 *   - the source map of each module, in the module itself
 *
 * The stub locates the entry module (whose bootstrap runs `main`) and requires
 * it; maps are not touched until something reports an exception.
 *
 * @param array<string, mixed> $options
 */
function packagePhar(string $outputRoot, string $pharPath, array $options): void
{
    if (\is_file($pharPath)) {
        \unlink($pharPath);
    }

    $builder = pharBuilderCommand();
    if ($builder !== null) {
        packagePharInSubprocess($builder, $outputRoot, $pharPath);

        return;
    }

    writePhar($outputRoot, $pharPath, $options);
}

/**
 * The argv prefix of a PHP process that may create PHARs, or null when this one
 * already may. `phar.readonly` cannot be lowered once a process runs (`ini_set`
 * refuses it), so writing an archive takes a second process.
 *
 * @return list<string>|null
 */
function pharBuilderCommand(): ?array
{
    if (!(bool) \ini_get('phar.readonly')) {
        return null;
    }

    // Required, never named as a script: PHP's CLI cannot open a `phar://`
    // argument, and a distribution runs the compiler from inside one.
    return [
        \PHP_BINARY,
        '-d', 'phar.readonly=0',
        '-r', 'require ' . \var_export(__DIR__ . '/build_phar.php', true) . ';',
        '--',
    ];
}

/** @param list<string> $builder */
function packagePharInSubprocess(array $builder, string $outputRoot, string $pharPath): void
{
    $cmd = \array_merge($builder, [$outputRoot, $pharPath]);
    $cmdLine = \implode(' ', \array_map('escapeshellarg', $cmd));
    \passthru($cmdLine, $code);
    if ($code !== 0) {
        throw new \RuntimeException("building {$pharPath} failed (the phar.readonly=0 builder exited {$code})");
    }
}

/** Build the PHAR in-process (requires phar.readonly=0). @param array<string, mixed> $options */
function writePhar(string $outputRoot, string $pharPath, array $options = []): void
{
    $phar = new \Phar($pharPath);
    $phar->buildFromDirectory($outputRoot);
    $phar->setSignatureAlgorithm(\Phar::SHA256);
    $phar->setStub(buildPharStub());
    unset($phar); // flush to disk
}

function buildPharStub(): string
{
    return <<<'PHPSTUB'
#!/usr/bin/env php
<?php declare(strict_types=1);

// The runtime must be loaded before the entry module's bootstrap routes an
// uncaught exception through Moggi\reportUncaught. Source maps stay where they
// were emitted; a report loads only the ones its frames name.
require_once 'phar://' . __FILE__ . '/_runtime.php';

$entryFile = null;
$it = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator('phar://' . __FILE__, \FilesystemIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::LEAVES_ONLY,
);
foreach ($it as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $src = \file_get_contents($file->getPathname());
    if (\is_string($src) && \str_contains($src, '\\Moggi\\reportUncaught($__moggiUncaught)')) {
        $entryFile = $file->getPathname();

        break;
    }
}

// The entry module's embedded bootstrap runs main() (and routes uncaught
// exceptions through Moggi\reportUncaught) when required.
if ($entryFile !== null) {
    require_once $entryFile;
}

__halt_compiler();
PHPSTUB;
}
