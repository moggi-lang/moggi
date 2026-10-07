<?php declare(strict_types=1);

namespace Moggi\Docs;

use Moggi\Modules\PreparedProject;
use Moggi\Syntax\Ast\Program;

use function Moggi\Backend\setCompileBackend;
use function Moggi\CLI\findMogFiles;
use function Moggi\CLI\resolveLibraryDirs;
use function Moggi\Modules\isLibraryRoot;
use function Moggi\Modules\moduleFileClosureCached;
use function Moggi\Modules\prepareProjectCached;
use function Moggi\Modules\projectSourceClosure;
use function Moggi\Modules\setStdlibLibPath;
use function Moggi\Paths\canonicalSeparators;

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
        'modules' => parsedModulesFromPrepared($prepared, docRootFor($inputPath)),
    ];
}

/**
 * The documented root, with a trailing separator, or '' when it cannot be
 * resolved.
 *
 * Modules outside the documented root arrived as imports: they must be parsed
 * and type-checked for any module to be documented, but they are a dependency's
 * API, not this release's, and their pages belong to their own site. This is
 * deliberately not `PreparedProject::rootPrefix` — with a library root in play
 * that is the filesystem root, so it cannot tell a project module from a
 * dependency's.
 */
function docRootFor(string $inputPath): string
{
    $dir = \is_dir($inputPath) ? $inputPath : \dirname($inputPath);
    $real = realpath($dir);
    if ($real === false) {
        return '';
    }

    return $real === '/' || $real === '\\' ? '/' : $real . DIRECTORY_SEPARATOR;
}

/**
 * The banner title of a generated tree: the package and version the descriptor
 * at `$inputPath`'s root declares, e.g. `json 0.1.0`.
 *
 * A generated tree should name what it documents rather than the tool that
 * wrote it, and the descriptor is the only place that identity is written down.
 * '' when there is no descriptor to name it, so the shell can fall back.
 */
function docSiteTitle(string $inputPath): string
{
    $dir = \is_dir($inputPath) ? $inputPath : \dirname($inputPath);
    foreach (glob(rtrim($dir, '/\\') . '/*.moggi') ?: [] as $descriptor) {
        $ini = parse_ini_string((string) file_get_contents($descriptor), true, \INI_SCANNER_RAW);
        if ($ini === false) {
            continue;
        }
        $package = \is_array($ini['package'] ?? null) ? $ini['package'] : [];
        $name = \trim((string) ($package['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        return \trim($name . ' ' . \trim((string) ($package['version'] ?? '')));
    }

    return '';
}

/**
 * The documented modules: those under `$docRoot` — plus the compiler's synthetic
 * modules, but only when the documented root *is* the standard library.
 *
 * A compiler-synthesized module (`Moggi.Internal.Prim`, `Moggi.Internal.IO`) has
 * a sentinel path rather than a file, so the root test cannot judge it. It is the
 * toolchain's: the toolchain's own docs may show its intrinsics, but a package's
 * docs must not — a reader asked for the package's API, not for the compiler's.
 *
 * @param PreparedProject $prepared
 * @return array<string, ParsedModule>
 */
function parsedModulesFromPrepared(PreparedProject $prepared, string $docRoot = ''): array
{
    $stdlib = $docRoot !== '' && isLibraryRoot(\rtrim($docRoot, '/\\'));
    $modules = [];
    foreach ($prepared->units as $moduleName => $unit) {
        $program = $unit['program'] ?? null;
        if (!$program instanceof Program || $program->module === null) {
            continue;
        }
        if (($unit['synthetic'] ?? false) === true) {
            if (!$stdlib) {
                continue;
            }
        } elseif (!sourceUnderRoot((string) ($unit['path'] ?? ''), $docRoot)) {
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
            if (isLibraryRoot($dir)) {
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
 * The source directories a descriptor at the root declares for its test suites.
 *
 * A test suite is not a package's API: a release's docs should not carry the
 * fixtures and helpers only the tests use. The descriptor is where those
 * directories are named, so it is where they are read from.
 *
 * @return list<string>
 */
function descriptorTestSourceDirs(string $root): array
{
    $dirs = [];
    foreach (glob(rtrim($root, '/\\') . '/*.moggi') ?: [] as $descriptor) {
        $ini = parse_ini_string((string) file_get_contents($descriptor), true, \INI_SCANNER_RAW);
        if ($ini === false) {
            continue;
        }
        $value = $ini['test-suite']['source-dirs'] ?? null;
        if (!\is_string($value)) {
            continue;
        }
        foreach (\preg_split('/[\s,]+/', $value) ?: [] as $entry) {
            $entry = \trim($entry);
            if ($entry !== '') {
                $dirs[] = $entry;
            }
        }
    }

    return array_values(array_unique($dirs));
}

/**
 * The files with a descriptor's test-suite source directories removed.
 *
 * The comparison is spelled one way on every host: a walked directory comes back
 * with the host's separator and the descriptor's entry is the author's own, so a
 * Windows root joined with `/` would match nothing and a suite would be
 * documented as if it were API.
 *
 * @param list<string> $files
 * @return list<string>
 */
function withoutTestSuiteSources(string $root, array $files): array
{
    $dirs = descriptorTestSourceDirs($root);
    if ($dirs === []) {
        return $files;
    }

    $prefixes = [];
    foreach ($dirs as $dir) {
        $prefixes[] = canonicalSeparators(rtrim($root, '/\\')) . '/' . trim(canonicalSeparators($dir), '/\\') . '/';
    }

    return array_values(array_filter(
        $files,
        static function (string $file) use ($prefixes): bool {
            $canonical = canonicalSeparators($file);
            foreach ($prefixes as $prefix) {
                if (str_starts_with($canonical, $prefix)) {
                    return false;
                }
            }

            return true;
        },
    ));
}

/**
 * @return array{0: string, 1: list<string>}
 */
function resolveDocInputs(string $inputPath): array
{
    if (\is_dir($inputPath)) {
        [$root, $files] = findMogFiles($inputPath);

        return [$root, withoutTestSuiteSources($root, $files)];
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
