<?php declare(strict_types=1);

namespace Moggi\CLI;

use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;
use Moggi\Semantics\Types\TypeError;

use function Moggi\Modules\bundledStdlibLibPath;
use function Moggi\Modules\cachedModuleHeader;
use function Moggi\Modules\configuredLibraryRoots;
use function Moggi\Modules\findBoundedModuleRoot;
use function Moggi\Modules\headerIsModuleSource;
use function Moggi\Modules\isStandaloneWorkspaceTest;
use function Moggi\Modules\moduleFileClosureCached;
use function Moggi\Modules\projectSourceClosure;

/**
 * @return array{0: string, 1: list<string>}
 * @throws \InvalidArgumentException
 */
function findMogFiles(string $inputDir): array
{
    $root = realpath($inputDir);
    if ($root === false || !\is_dir($root)) {
        throw new \InvalidArgumentException("not a directory: {$inputDir}");
    }

    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'mog') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return [$root, $files];
}

/** @return array{0: string, 1: list<string>} */
function findMogFilesOrExit(string $inputDir): array
{
    try {
        return findMogFiles($inputDir);
    } catch (\InvalidArgumentException $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

/**
 * The files to compile, and the root they are relative to.
 *
 * One file is resolved as a module: its imports are followed, so compiling
 * `Main.mog` alone is not a licence to know less than compiling its directory.
 * Explicit `--lib` roots are searched too — without them a module file could
 * only ever reach the stdlib, and a dependency would be invisible to exactly
 * the invocation `moggi build` makes.
 *
 * @param list<string> $extraLibDirs
 * @return array{0: string, 1: list<string>}
 * @throws LexError|ParseError|TypeError|\RuntimeException
 */
function resolveCompileInputs(string $inputPath, array $extraLibDirs = []): array
{
    if (\is_dir($inputPath)) {
        return findMogFiles($inputPath);
    }

    if (!\is_file($inputPath)) {
        \fwrite(STDERR, "error: not a file or directory: {$inputPath}\n");
        exit(1);
    }

    $real = realpath($inputPath);
    if ($real === false) {
        \fwrite(STDERR, "error: cannot resolve {$inputPath}\n");
        exit(1);
    }

    $header = cachedModuleHeader($real);
    if (!headerIsModuleSource($header)) {
        return [dirname($real), [$real]];
    }

    if ($extraLibDirs !== []) {
        $searchRoots = resolveLibraryDirs($extraLibDirs);
        if (!isStandaloneWorkspaceTest($real)) {
            $localRoot = findBoundedModuleRoot($real);
            if ($localRoot !== null) {
                $searchRoots[] = $localRoot;
            }
        }
        [$files, $root] = projectSourceClosure([$real], $searchRoots);

        return [$root, $files];
    }

    [$files, $root] = moduleFileClosureCached($real);

    return [$root, $files];
}

/**
 * Library search roots: compiler-bundled stdlib first, then any root registered
 * beside it, then extra --lib dirs.
 *
 * @param list<string> $extraLibDirs
 * @return list<string>
 */
function resolveLibraryDirs(array $extraLibDirs): array
{
    $dirs = [];
    $seen = [];

    $bundled = bundledStdlibLibPath();
    if ($bundled !== null) {
        $dirs[] = $bundled;
        $seen[$bundled] = true;
    }

    foreach (configuredLibraryRoots() as $root) {
        if (!isset($seen[$root])) {
            $dirs[] = $root;
            $seen[$root] = true;
        }
    }

    foreach ($extraLibDirs as $dir) {
        $real = realpath($dir);
        if ($real === false) {
            $dirs[] = $dir;
            continue;
        }
        if (!isset($seen[$real])) {
            $dirs[] = $real;
            $seen[$real] = true;
        }
    }

    return $dirs;
}
