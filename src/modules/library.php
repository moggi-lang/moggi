<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Cache;
use Moggi\Semantics\Types\TypeError;

/** Whether `$path` names a stream (`phar://…`) rather than a real filesystem path. */
function isStreamPath(string $path): bool
{
    return \preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://#', $path) === 1;
}

/**
 * Canonicalize a path: `realpath()` for a real path, the path itself for a
 * stream (`phar://…`), where `realpath()` returns false. Every place that
 * canonicalizes a library root or a module path goes through here.
 */
function resolvePath(string $path): string
{
    if (isStreamPath($path)) {
        return $path;
    }

    return \realpath($path) ?: $path;
}

/**
 * Standard-library / library-root discovery and pinning.
 *
 * This layer owns everything about *where the library lives*: the pinned
 * `--lib` source root, the pinned `--lib-php` compiled-output root, discovery
 * of an unpinned stdlib by walking up from a source file, and the memoized
 * module-name index built over a library root. It has no opinion on module
 * graphs, exports, or codegen.
 */

/** The standard-library source root pinned with `--lib`, if any. */
final class ConfiguredStdlib
{
    private static ?string $libPath = null;

    public static function setLibPath(?string $path): void
    {
        self::$libPath = $path;
    }

    public static function libPath(): ?string
    {
        return self::$libPath;
    }
}

function setStdlibLibPath(?string $path): void
{
    if ($path === null) {
        ConfiguredStdlib::setLibPath(null);

        return;
    }

    $real = resolvePath($path);
    if (!\is_dir($real)) {
        throw new TypeError("invalid stdlib path `{$path}`");
    }

    $marker = $real . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'Eq.mog';
    if (!\is_file($marker)) {
        throw new TypeError(
            "library path `{$path}` is missing `Data/Eq.mog` (expected a Moggi library root)",
        );
    }

    ConfiguredStdlib::setLibPath($real);
}

function configuredStdlibLibPath(): ?string
{
    return ConfiguredStdlib::libPath();
}

/**
 * Standard library shipped next to the compiler: `<root>/lib` where `<root>` is
 * an explicit `$MOGGI_ROOT`, the installation an archive lives in
 * (`<installation>/bin/moggi.phar` → `<installation>/lib`), or the checkout.
 */
function bundledStdlibLibPath(): ?string
{
    static $cached = false;
    static $path = null;
    if ($cached) {
        return $path;
    }
    $cached = true;

    $candidates = [];
    $envRoot = getenv('MOGGI_ROOT');
    if (\is_string($envRoot) && $envRoot !== '') {
        $candidates[] = rtrim($envRoot, '/\\') . DIRECTORY_SEPARATOR . 'lib';
    }
    $archive = \Phar::running(false);
    if ($archive !== '') {
        $candidates[] = \dirname($archive, 2) . DIRECTORY_SEPARATOR . 'lib';
    }
    $candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib';

    foreach ($candidates as $candidate) {
        $real = resolvePath($candidate);
        if (\is_file($real . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'Eq.mog')) {
            $path = $real;

            return $path;
        }
    }

    return null;
}

function locateStdlibRoot(string $fromPath): ?string
{
    $configured = configuredStdlibLibPath();
    if ($configured !== null) {
        return $configured;
    }

    $bundled = bundledStdlibLibPath();
    if ($bundled !== null) {
        return $bundled;
    }

    $dir = dirname(resolvePath($fromPath));
    while ($dir !== false) {
        $marker = $dir . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'Eq.mog';
        if (\is_file($marker)) {
            return $dir . DIRECTORY_SEPARATOR . 'lib';
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }

        $dir = $parent;
    }

    return null;
}

function warnIfStdlibAutoDiscovered(string $fromPath, string $stdlibRoot): void
{
    if (configuredStdlibLibPath() !== null) {
        return;
    }

    $bundled = bundledStdlibLibPath();
    if ($bundled !== null && resolvePath($stdlibRoot) === $bundled) {
        return;
    }

    if (isStandaloneWorkspaceTest($fromPath)) {
        return;
    }

    \fwrite(
        STDERR,
        "warning: stdlib auto-located at {$stdlibRoot} for " . (realpath($fromPath) ?: $fromPath)
            . "; pass --lib PATH for an explicit standard-library dependency\n",
    );
}

/**
 * Whether `$path` lives inside the library root rather than the project being
 * built. This only decides where build artifacts go — library modules are
 * dependencies, so they are cached in the shared store instead of the project's
 * `.moggi/`. It has no bearing on how the module is compiled.
 */
function isModuleUnderLibraryRoot(string $path): bool
{
    $libraryRoot = locateStdlibRoot($path);
    if ($libraryRoot === null) {
        return false;
    }

    $realPath = resolvePath($path);
    $realRoot = resolvePath($libraryRoot);

    return str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR);
}

/**
 * Memoized `module name => path` index for a library root, plus the newest
 * mtime under it. The mtime doubles as the index's validity stamp, so an edit
 * anywhere in the root rebuilds it.
 */
final class LibraryModuleIndex
{
    /** @var array<string, array{mtime: int, index: array<string, string>}> */
    private static array $indexes = [];

    /** @var array<string, int> */
    private static array $mtimes = [];

    public static function maxMtime(string $root): int
    {
        $key = self::key($root);
        if (isset(self::$indexes[$key])) {
            return self::$indexes[$key]['mtime'];
        }

        if (isset(self::$mtimes[$key])) {
            return self::$mtimes[$key];
        }

        $mtime = 0;
        foreach (findMogFilesUnder($root) as $path) {
            $mtime = max($mtime, filemtime($path) ?: 0);
        }

        self::$mtimes[$key] = $mtime;

        return $mtime;
    }

    /** @return array<string, string> */
    public static function index(string $root): array
    {
        $key = self::key($root);
        $mtime = self::maxMtime($root);

        $cached = self::$indexes[$key] ?? null;
        if ($cached !== null && $cached['mtime'] === $mtime) {
            return $cached['index'];
        }

        if (Cache\cacheEnabled()) {
            $diskKey = 'stdlib-index:' . Cache\hashContent($key . ':' . $mtime);
            $fromDisk = Cache\artifactGet($diskKey);
            if (\is_array($fromDisk)) {
                self::$indexes[$key] = ['mtime' => $mtime, 'index' => $fromDisk];

                return $fromDisk;
            }
        }

        $index = moduleIndexFromPaths(findMogFilesUnder($root));
        self::$indexes[$key] = ['mtime' => $mtime, 'index' => $index];

        if (Cache\cacheEnabled()) {
            $diskKey = 'stdlib-index:' . Cache\hashContent($key . ':' . $mtime);
            Cache\artifactPut($diskKey, $index);
        }

        return $index;
    }

    private static function key(string $root): string
    {
        return resolvePath($root);
    }
}

function stdlibMaxMtime(string $stdlibRoot): int
{
    return LibraryModuleIndex::maxMtime($stdlibRoot);
}

/** @return array<string, string> */
function stdlibModuleIndex(string $stdlibRoot): array
{
    return LibraryModuleIndex::index($stdlibRoot);
}

/**
 * Compiled library PHP tree pinned with `--lib-php` (typically a prebuilt stdlib
 * output such as `build/lib`). Used only for single-file compiles so generated
 * `require`s can point at that tree instead of source `lib/*.php` paths.
 */
final class ConfiguredStdlibPhp
{
    private static ?string $path = null;

    public static function set(?string $path): void
    {
        self::$path = $path;
    }

    public static function get(): ?string
    {
        return self::$path;
    }
}

function setStdlibPhpPath(?string $path): void
{
    if ($path === null) {
        ConfiguredStdlibPhp::set(null);

        return;
    }

    $real = realpath($path);
    if ($real === false || !\is_dir($real)) {
        throw new TypeError("invalid stdlib PHP path `{$path}`");
    }

    $marker = $real . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'Eq.php';
    if (!\is_file($marker)) {
        throw new TypeError(
            "stdlib PHP path `{$path}` is missing `Data/Eq.php`; compile the stdlib first (e.g. `moggi compile lib -o out/lib`)",
        );
    }

    ConfiguredStdlibPhp::set($real);
}

function detectStdlibPhpPath(?string $outputFile): ?string
{
    $configured = ConfiguredStdlibPhp::get();
    if ($configured !== null) {
        return $configured;
    }

    if ($outputFile === null) {
        return null;
    }

    $dir = dirname(realpath($outputFile) ?: $outputFile);
    if (\is_file($dir . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'Eq.php')) {
        return $dir;
    }

    $sibling = $dir . DIRECTORY_SEPARATOR . 'lib';
    if (\is_file($sibling . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'Eq.php')) {
        return $sibling;
    }

    return null;
}

/**
 * @param array<string, string> $moduleOutputPaths
 * @param array<string, array<string, mixed>> $units
 * @return array<string, string>
 */
function remapStdlibModuleOutputPaths(
    array $moduleOutputPaths,
    array $units,
    string $stdlibPhpRoot,
    string $projectRoot,
): array {
    $stdlibLib = configuredStdlibLibPath();
    if ($stdlibLib === null) {
        return $moduleOutputPaths;
    }

    $out = $moduleOutputPaths;
    foreach ($moduleOutputPaths as $moduleName => $path) {
        if (!isset($units[$moduleName])) {
            continue;
        }

        if (!isModuleUnderLibraryRoot($units[$moduleName]['path'])) {
            continue;
        }

        $relative = mogPathToOutputRelative($units[$moduleName]['path'], $stdlibLib . DIRECTORY_SEPARATOR, '.php');
        $out[$moduleName] = pathWithinProject($stdlibPhpRoot . DIRECTORY_SEPARATOR . $relative, $projectRoot);
    }

    return $out;
}
