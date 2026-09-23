<?php declare(strict_types=1);

namespace Moggi\Docs;

use Moggi\Modules\PreparedProject;
use Moggi\Syntax\Ast\Program;

use function Moggi\Backend\setCompileBackend;
use function Moggi\CLI\findMogFiles;
use function Moggi\CLI\resolveLibraryDirs;
use function Moggi\Modules\moduleFileClosureCached;
use function Moggi\Modules\prepareProjectCached;
use function Moggi\Modules\projectSourceClosure;
use function Moggi\Modules\setStdlibLibPath;

final class ParsedModule
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly string $source,
        public readonly Program $program,
    ) {
    }
}

/**
 * Parse and type-check modules for documentation (single prepare pass).
 *
 * @param list<string> $extraLibDirs
 * @return array{prepared: PreparedProject, modules: array<string, ParsedModule>}
 */
function loadDocProject(string $inputPath, array $extraLibDirs = [], ?array $docClosure = null): array
{
    if ($docClosure !== null) {
        [$root, $files] = $docClosure;
        $prepared = prepareProjectCached($files, $root);
    } else {
        $prepared = loadPreparedProject($inputPath, $extraLibDirs);
    }

    return [
        'prepared' => $prepared,
        'modules' => parsedModulesFromPrepared($prepared),
    ];
}

/**
 * @param PreparedProject $prepared
 * @return array<string, ParsedModule>
 */
function parsedModulesFromPrepared(PreparedProject $prepared): array
{
    $modules = [];
    foreach ($prepared->units as $moduleName => $unit) {
        $program = $unit['program'] ?? null;
        if (!$program instanceof Program || $program->module === null) {
            continue;
        }

        $modules[$moduleName] = new ParsedModule(
            $moduleName,
            (string) ($unit['path'] ?? ''),
            (string) ($unit['source'] ?? ''),
            $program,
        );
    }

    ksort($modules);

    return $modules;
}

/**
 * @param list<string> $extraLibDirs
 * @return array<string, Program>
 */
function loadCheckedPrograms(string $inputPath, array $extraLibDirs = []): array
{
    return loadPreparedProject($inputPath, $extraLibDirs)->checked;
}

function loadPreparedProject(string $inputPath, array $extraLibDirs = []): PreparedProject
{
    [$root, $files] = resolveDocFileClosure($inputPath, $extraLibDirs);
    if ($files === []) {
        throw new \InvalidArgumentException("no .mog files found under {$inputPath}");
    }

    return prepareProjectCached($files, $root);
}

/**
 * Resolve the module graph for documentation (mirrors build's --lib handling).
 *
 * @param list<string> $extraLibDirs
 * @return array{0: string, 1: list<string>}
 */
function resolveDocFileClosure(string $inputPath, array $extraLibDirs = []): array
{
    setCompileBackend('php');
    [$root, $files] = resolveDocInputs($inputPath);
    if ($files === []) {
        return [$root, $files];
    }

    $libDirs = resolveLibraryDirs($extraLibDirs);
    if ($libDirs !== []) {
        foreach ($libDirs as $dir) {
            if (\is_file(rtrim($dir, '/') . '/Data/Eq.mog')) {
                setStdlibLibPath($dir);
                break;
            }
        }

        [$files, $root] = projectSourceClosure($files, $libDirs);

        return [$root, $files];
    }

    if (!\is_dir($inputPath)) {
        $real = realpath($inputPath);
        if ($real !== false) {
            try {
                [$files, $root] = moduleFileClosureCached($real);

                return [$root, $files];
            } catch (\Throwable) {
                // Keep the single-file input when closure discovery fails.
            }
        }
    }

    return [$root, $files];
}

/**
 * @param list<string> $files
 */
function docSourceFingerprintMtime(array $files): string
{
    $paths = $files;
    sort($paths);
    $parts = [];
    foreach ($paths as $path) {
        $real = realpath($path) ?: $path;
        $mtime = @filemtime($real);
        $size = @filesize($real);
        $parts[] = $real . '|' . ($mtime === false ? '' : (string) $mtime) . '|' . ($size === false ? '' : (string) $size);
    }

    return hash('sha256', implode("\n", $parts));
}

/**
 * @param list<string> $files
 */
function docSourceFingerprint(array $files): string
{
    $paths = $files;
    sort($paths);
    $parts = [];
    foreach ($paths as $path) {
        $real = realpath($path) ?: $path;
        $content = @file_get_contents($real);
        $parts[] = $real . '|' . ($content === false ? '' : hash('sha256', $content));
    }

    return hash('sha256', implode("\n", $parts));
}

/**
 * @return array{0: string, 1: list<string>}
 */
function resolveDocInputs(string $inputPath): array
{
    if (\is_dir($inputPath)) {
        return findMogFiles($inputPath);
    }

    if (!\is_file($inputPath)) {
        throw new \InvalidArgumentException("not a file or directory: {$inputPath}");
    }

    $real = realpath($inputPath);
    if ($real === false) {
        throw new \InvalidArgumentException("cannot resolve {$inputPath}");
    }

    return [dirname($real), [$real]];
}
