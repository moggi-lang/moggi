<?php declare(strict_types=1);

namespace Moggi\Modules;

require_once __DIR__ . '/../paths.php';

use Moggi\Cache;
use Moggi\Semantics\Types\TypeError;

use function Moggi\Install\installationRoot;
use function Moggi\Paths\canonicalPath;
use function Moggi\Paths\canonicalSeparators;
use function Moggi\Paths\globPaths;

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
 * `--lib` source roots, the `--lib-composer` / `--lib-maven` / `--lib-nuget`
 * host trees, discovery of an unpinned stdlib by walking up from a source file,
 * and the memoized module-name index built over a library root. It has no
 * opinion on module graphs, exports, or codegen.
 */

/** The file that marks a package root, named after the package: `base.moggi`. */
const LIBRARY_ROOT_MARKER = 'base.moggi';

/**
 * The package descriptor marking `$dir` as a library root, either layout.
 *
 * A library root holds the `.mog` sources: either flat with the descriptor
 * beside them (the shape user libraries keep), or the
 * packaged layout where sources live under `src/` (`libraries/base/src`,
 * described by `libraries/base/*.moggi`). Null when neither holds.
 */
function libraryRootMarker(string $dir): ?string
{
    $dir = \rtrim($dir, '/');
    if (\is_file($dir . '/' . LIBRARY_ROOT_MARKER)) {
        return $dir . '/' . LIBRARY_ROOT_MARKER;
    }
    if (\basename($dir) === 'src') {
        $candidates = globPaths(\dirname($dir) . '/*.moggi');
        if ($candidates !== []) {
            \sort($candidates, \SORT_STRING);

            return $candidates[0];
        }
    }

    return null;
}

/** Whether `$dir` is a Moggi library root — the `base` package or any user library. */
function isLibraryRoot(string $dir): bool
{
    return libraryRootMarker($dir) !== null;
}

/**
 * The project root output paths are relativized against for a standard-library
 * source root: the workspace holding the library, not the `src/` dir itself.
 *
 * Flat layout (`<workspace>/lib`, descriptor beside the sources) keeps the old
 * answer, `dirname`. Packaged layout (`<workspace>/libraries/<package>/src`,
 * descriptor beside `src/`) answers `<workspace>`, so requires come out as
 * `libraries/<package>/src/...` — the same project-relative shape
 * whole-project builds emit and the test harness prebuilds against. A packaged
 * root outside a `libraries/` tree answers its package dir; an unmarked root
 * falls back to `dirname`, the pre-migration behaviour.
 */
function workspaceRootForStdlibRoot(string $stdlibRoot): string
{
    $root = \rtrim(resolvePath($stdlibRoot), '/\\');
    if (\basename($root) === 'src' && libraryRootMarker($root) !== null) {
        $package = \dirname($root);
        if (\basename(\dirname($package)) === 'libraries') {
            return \dirname(\dirname($package));
        }

        return $package;
    }

    return \dirname($root);
}

/**
 * The standard-library source root pinned with `--lib`, and any extra library
 * roots searched alongside it.
 *
 * The primary is "the" standard library, the root `base.moggi` marks. Extras are
 * dependency trees that ship separately from it — the `json` package beside it
 * in `libraries/` — and the standard library wins a name clash, because extras are
 * merged into its index rather than replacing it.
 */
final class ConfiguredStdlib
{
    private static ?string $libPath = null;

    /** @var list<string> */
    private static array $extraPaths = [];

    public static function setLibPath(?string $path): void
    {
        self::$libPath = $path;
        self::$extraPaths = [];
    }

    public static function libPath(): ?string
    {
        return self::$libPath;
    }

    public static function addPath(string $path): void
    {
        if (! \in_array($path, self::$extraPaths, true)) {
            self::$extraPaths[] = $path;
        }
    }

    /** @return list<string> */
    public static function extraPaths(): array
    {
        return self::$extraPaths;
    }
}

function setStdlibLibPath(?string $path): void
{
    if ($path === null) {
        ConfiguredStdlib::setLibPath(null);

        return;
    }

    $real = resolvePath($path);
    if (! isLibraryRoot($real)) {
        throw new TypeError(
            "library path `{$path}` is missing `" . LIBRARY_ROOT_MARKER . '` (expected a Moggi library root)',
        );
    }

    ConfiguredStdlib::setLibPath($real);
}

/**
 * Add a library root searched alongside the standard library.
 *
 * The compiler gets extra roots from `--lib`; this is the one place a *bundled*
 * extra is registered, so the compiler's own harness can reach a package that
 * ships separately from the base sources (`json`, beside it in `libraries/`).
 */
function addLibraryRoot(string $path): void
{
    $real = resolvePath($path);
    if (! \is_dir($real)) {
        throw new TypeError("invalid library path `{$path}`");
    }

    ConfiguredStdlib::addPath($real);
}

function configuredStdlibLibPath(): ?string
{
    return ConfiguredStdlib::libPath();
}

/**
 * Pin the library roots a session searches, so the module resolver and every
 * backend dependency scanner see the same set.
 *
 * The first root that carries a package descriptor becomes the primary standard
 * library; every other root is registered as an extra. The split matters beyond
 * module lookup: a package's vendored jars, CLR metadata and PHP helpers live
 * under whichever root it was installed into, and only a root registered here is
 * scanned for them.
 *
 * @param list<string> $libDirs
 */
function registerLibraryRoots(array $libDirs): void
{
    $primary = null;
    foreach ($libDirs as $dir) {
        if (isLibraryRoot($dir)) {
            $primary = $dir;

            break;
        }
    }

    if ($primary !== null) {
        setStdlibLibPath($primary);
    }

    foreach ($libDirs as $dir) {
        if ($dir !== $primary && \is_dir($dir)) {
            addLibraryRoot($dir);
        }
    }
}

/**
 * Every configured library root: the standard library first, then the extras.
 *
 * @return list<string>
 */
function configuredLibraryRoots(): array
{
    $roots = [];
    $primary = ConfiguredStdlib::libPath();
    if ($primary !== null) {
        $roots[] = $primary;
    }

    return [...$roots, ...configuredExtraPaths()];
}

/**
 * Extra library roots: the configured `--lib` / `addLibraryRoot()` ones, then
 * the packages bundled with the standard distribution.
 *
 * `base` cannot stand alone — `System.Directory.getModificationTime` answers
 * `IO UTCTime`, so the `time` package beside it in `libraries/` is part of the
 * standard distribution the same way `base` is, and every compilation that can
 * see `base` can see `time` without an explicit `--lib`. Anything else under
 * `libraries/` (`json`, `containers`) ships from its own repo and still joins
 * through `--lib` or `addLibraryRoot()`.
 *
 * Already-listed roots are not repeated, so an explicit `--lib
 * libraries/time/src` is the same root rather than a duplicate.
 *
 * @return list<string>
 */
function configuredExtraPaths(): array
{
    $extras = ConfiguredStdlib::extraPaths();
    foreach (bundledExtraLibPaths() as $bundled) {
        $seen = false;
        foreach ($extras as $extra) {
            if (canonicalPath($extra) === canonicalPath($bundled)) {
                $seen = true;

                break;
            }
        }
        if (! $seen) {
            $extras[] = $bundled;
        }
    }

    return $extras;
}

/**
 * Bundled packages that join every compilation alongside the standard
 * library: `<root>/libraries/{time,filepath,directory}/src`, where `<root>`
 * is found the way the bundled stdlib is — the installed distribution, or the
 * checkout. Empty when the distribution ships no such package.
 *
 * @return list<string>
 */
function bundledExtraLibPaths(): array
{
    static $memoKey = null;
    static $memoPaths = [];
    $key = installationCandidatesKey();
    if ($memoKey === $key) {
        return $memoPaths;
    }
    $memoKey = $key;
    $memoPaths = [];

    $packages = ['time', 'filepath', 'directory'];
    foreach (installationCandidates() as $root) {
        foreach ($packages as $package) {
            $dir = \rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'libraries'
                . DIRECTORY_SEPARATOR . $package . DIRECTORY_SEPARATOR . 'src';
            $real = resolvePath($dir);
            if (isLibraryRoot($real)) {
                $memoPaths[] = $real;
            }
        }
        if ($memoPaths !== []) {
            return $memoPaths;
        }
    }

    return $memoPaths;
}

/**
 * The roots a bundled `libraries/` tree is looked for under, most
 * authoritative first: the installation this process runs from, then the
 * checkout it was started from.
 *
 * Both are facts of the process, not settings: the installation is the one the
 * executable was started from (`MOGGI_INSTALL_ROOT`, or the archive's own
 * location) and the checkout is this tree. There is nothing to point elsewhere.
 *
 * @return list<string>
 */
function installationCandidates(): array
{
    $candidates = [];
    $installRoot = installationRoot();
    if ($installRoot !== null) {
        $candidates[] = $installRoot;
    }
    $checkout = \dirname(__DIR__, 2);
    if (! \in_array($checkout, $candidates, true)) {
        $candidates[] = $checkout;
    }

    return $candidates;
}

/**
 * The candidate list as a memo key.
 *
 * A lookup memoized on the installation must notice the root changing — a test
 * defines `MOGGI_INSTALL_ROOT` before its first lookup, and the memos must not
 * answer for the tree that was consulted before it.
 */
function installationCandidatesKey(): string
{
    return \implode("\0", installationCandidates());
}

/**
 * Every root a library dependency scan walks.
 *
 * The backend scanners (JVM `jvm/` jars, .NET `dotnet/` metadata, PHP `php/`
 * helpers) used to look only under the standard library. A package installed
 * beside it — the `json` package in `libraries/`, a dependency tree `moggi
 * build` resolved into the cache — vendors its dependencies in its own root, so
 * a scan of just the primary would silently drop them. Falls back to the
 * bundled `libraries/base/src` for the compiler's own runs, which configure
 * no root at all.
 *
 * @return list<string>
 */
function libraryScanRoots(): array
{
    $roots = configuredLibraryRoots();
    foreach ($roots as $root) {
        if (isLibraryRoot($root)) {
            return $roots;
        }
    }
    $bundled = bundledStdlibLibPath();

    return $bundled === null ? [] : [$bundled];
}

/**
 * Standard library shipped next to the compiler: `<root>/libraries/base/src`
 * where `<root>` is the installation an archive lives in
 * (`<installation>/bin/moggi.phar` → `<installation>/libraries/...`), the
 * checkout, or a root a test configured.
 */
function bundledStdlibLibPath(): ?string
{
    static $memoKey = null;
    static $memoPath = null;
    $key = installationCandidatesKey();
    if ($memoKey === $key) {
        return $memoPath;
    }
    $memoKey = $key;
    $memoPath = null;

    $candidates = [];
    foreach (installationCandidates() as $root) {
        $candidates[] = $root . DIRECTORY_SEPARATOR . 'libraries'
            . DIRECTORY_SEPARATOR . 'base' . DIRECTORY_SEPARATOR . 'src';
    }

    foreach ($candidates as $candidate) {
        $real = resolvePath($candidate);
        if (isLibraryRoot($real)) {
            $memoPath = $real;

            return $memoPath;
        }
    }

    return null;
}

/**
 * The configured library root containing `$file`, or null for project files.
 *
 * A package is a root: the standard library, an installed dependency, a `--lib` tree. A file
 * under none of them is the project's own, which owns every root it assembled. Longest match wins
 * so nested roots resolve to the inner package. Slashes are normalized but the filesystem is not
 * consulted, so this works for paths as the checker spells them.
 */
function declaringLibraryRoot(string $file): ?string
{
    $file = \str_replace('\\', '/', $file);
    $best = null;
    foreach (configuredLibraryRoots() as $root) {
        $root = \rtrim(\str_replace('\\', '/', (string) $root), '/');
        if ($root === '' || ($file !== $root && ! \str_starts_with($file, $root . '/'))) {
            continue;
        }
        if ($best === null || \strlen($root) > \strlen($best)) {
            $best = $root;
        }
    }

    return $best;
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

    $dir = \dirname(resolvePath($fromPath));
    while ($dir !== false) {
        $packaged = $dir . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'base' . DIRECTORY_SEPARATOR . 'src';
        if (isLibraryRoot($packaged)) {
            return $packaged;
        }

        $parent = \dirname($dir);
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
        "warning: stdlib auto-located at {$stdlibRoot} for " . (\realpath($fromPath) ?: $fromPath)
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

    return \str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR);
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
            $mtime = \max($mtime, \filemtime($path) ?: 0);
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
            return $cached['moduleIndex'];
        }

        if (Cache\cacheEnabled()) {
            $diskKey = 'stdlib-index:' . Cache\hashContent($key . ':' . $mtime);
            $fromDisk = Cache\artifactGet($diskKey);
            if (\is_array($fromDisk)) {
                self::$indexes[$key] = ['mtime' => $mtime, 'moduleIndex' => $fromDisk];

                return $fromDisk;
            }
        }

        $index = moduleIndexFromPaths(findMogFilesUnder($root));
        self::$indexes[$key] = ['mtime' => $mtime, 'moduleIndex' => $index];

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
    $mtime = LibraryModuleIndex::maxMtime($stdlibRoot);
    foreach (configuredExtraPaths() as $extra) {
        $mtime = \max($mtime, LibraryModuleIndex::maxMtime($extra));
    }

    return $mtime;
}

/**
 * The stdlib index merged with every extra root, and the names more than one of
 * them offers.
 *
 * A name an extra root and the standard library both offer is not a fault in
 * itself -- a program that never imports it is unaffected -- so the clash is
 * recorded for the compilation to refuse where the name is reached, exactly as
 * two `--lib` roots are ({@see libraryModuleScan}).
 *
 * The standard library is the first root, so a name it offers resolves to its
 * file (`stdlibModuleIndex` keeps that behaviour for callers that only want the
 * index) and an extra root's file of the same name is the recorded duplicate.
 *
 * @return array{0: array<string, string>, 1: array<string, list<string>>}
 *   the module name => source path table, then the name => other paths that offer
 *   it table
 */
function stdlibModuleScan(string $stdlibRoot): array
{
    $paths = [];
    $duplicates = [];
    foreach ([$stdlibRoot, ...configuredExtraPaths()] as $root) {
        foreach (LibraryModuleIndex::index($root) as $moduleName => $path) {
            $existing = $paths[$moduleName] ?? null;
            if ($existing === null) {
                $paths[$moduleName] = $path;

                continue;
            }

            if (canonicalPath($existing) !== canonicalPath($path)) {
                $duplicates[$moduleName][] = $path;
            }
        }
    }

    return [$paths, $duplicates];
}

/**
 * The stdlib index merged with every extra root.
 *
 * @return array<string, string>
 */
function stdlibModuleIndex(string $stdlibRoot): array
{
    [$paths] = stdlibModuleScan($stdlibRoot);

    return $paths;
}

/**
 * Host library roots registered with `--lib-composer`, `--lib-maven` or `--lib-nuget`.
 *
 * A host root is the resolved closure one package manager produced for this build: a Composer
 * project (or its `vendor/`), a directory of jars, a directory of DLLs. The backends' dependency
 * scanners look in these for third-party code; nothing here is searched for `.mog` modules, so a
 * vendor tree can never shadow a Moggi module, and a host root is not a Moggi library root.
 *
 * One compilation owns the set: `clearHostLibraryRoots()` empties it and
 * `registerHostLibraryRoots()` replaces it, so a second compile in one process cannot inherit the
 * first one's trees.
 */
final class ConfiguredHostLibraries
{
    /** @var list<string> */
    private static array $roots = [];

    /** The identity of {@see $roots}, or null until it is computed for this set. */
    private static ?string $material = null;

    /** @param list<string> $roots */
    public static function set(array $roots): void
    {
        self::$roots = $roots;
        self::$material = null;
    }

    /** @return list<string> */
    public static function get(): array
    {
        return self::$roots;
    }

    public static function material(): ?string
    {
        return self::$material;
    }

    public static function rememberMaterial(string $material): void
    {
        self::$material = $material;
    }
}

/** Forget the host roots a previous compilation in this process registered. */
function clearHostLibraryRoots(): void
{
    ConfiguredHostLibraries::set([]);
}

/**
 * Register the host libraries a compile links against.
 *
 * The flag names the package manager (`--lib-composer`, `--lib-maven`, `--lib-nuget`) and so the
 * target: artifacts have to be shaped for the backend being emitted. Each tree is checked for the
 * artifact that backend's package manager produces, so a path that resolves to nothing usable is
 * refused here rather than dropped silently.
 *
 * A Composer tree may be named by its `vendor/` directory or by the project that owns it; both
 * register the project, because the artifact always carries `vendor/autoload.php`.
 *
 * @param list<string> $paths
 */
function registerHostLibraryRoots(string $backend, array $paths): void
{
    $roots = [];
    foreach ($paths as $path) {
        $real = \realpath($path);
        if ($real === false || ! \is_dir($real)) {
            throw new TypeError("host library path `{$path}` is not a directory");
        }
        if ($backend === 'php' && \basename($real) === 'vendor' && \is_file($real . '/autoload.php')) {
            $real = \dirname($real);
        }
        assertHostLibraryShape($backend, $real, $path);
        if (! \in_array($real, $roots, true)) {
            $roots[] = $real;
        }
    }

    ConfiguredHostLibraries::set($roots);
}

/**
 * What each backend's package manager has to leave in the tree a compile is handed.
 *
 * One table, so the refusal at registration and the dependency scanners cannot disagree about what
 * a usable tree is: the paths, relative to the root, that must exist, and the sentence printed when
 * one is missing. Only the package manager's own artifact is required — a Composer autoloader, jars,
 * assemblies — never the `.json` type sidecars a Moggi library may add beside them, because a real
 * NuGet resolution ships DLLs alone and a program that calls only framework types is served by them.
 *
 * @return array{0: list<string>, 1: string}
 */
function hostLibraryShape(string $backend): array
{
    return match ($backend) {
        'php' => [['vendor/autoload.php'], 'a Composer project (or its `vendor/`), so it must hold `vendor/autoload.php`'],
        'jvm' => [['*.jar'], 'the classpath Maven resolved, so it must hold jars'],
        'dotnet' => [['*.dll'], 'the assemblies NuGet resolved, so it must hold DLLs (a package keeps them under `lib/<tfm>/` — name that directory, which is the set a build stages)'],
        default => throw new TypeError("no host package manager for backend `{$backend}`"),
    };
}

/** Whether `$root` holds the artifact set `$backend`'s package manager produces. */
function hostLibraryShapeSatisfied(string $backend, string $root): bool
{
    foreach (hostLibraryShape($backend)[0] as $relative) {
        $path = \rtrim($root, '/') . '/' . $relative;
        $present = \str_starts_with($relative, '*')
            ? globPaths($path) !== []
            : \is_file($path);
        if (! $present) {
            return false;
        }
    }

    return true;
}

/** Refuse a host tree that does not hold the artifact its package manager produces. */
function assertHostLibraryShape(string $backend, string $root, string $shown): void
{
    if (! hostLibraryShapeSatisfied($backend, $root)) {
        throw new TypeError("`{$shown}` is not " . hostLibraryShape($backend)[1]);
    }
}

/** @return list<string> */
function hostLibraryRoots(): array
{
    return ConfiguredHostLibraries::get();
}

/**
 * The host trees a compilation is linked against, as one cache-key component: each tree's canonical
 * path plus a content hash of every file under it, so an edit in place is a different identity too.
 * Empty when no tree is registered. Computed once per set, since keys are evaluated per module.
 */
function hostLibraryCacheMaterial(): string
{
    $roots = hostLibraryRoots();
    if ($roots === []) {
        return '';
    }

    $remembered = ConfiguredHostLibraries::material();
    if ($remembered !== null) {
        return $remembered;
    }

    \sort($roots, \SORT_STRING);
    $parts = [];
    foreach ($roots as $root) {
        $real = \realpath($root);
        if ($real === false || ! \is_dir($real)) {
            $parts[] = 'tree=' . $root . ':missing';

            continue;
        }
        $parts[] = 'tree=' . $real;
        $files = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            if (! $item->isFile()) {
                continue;
            }
            $path = $item->getPathname();
            $hash = @\hash_file('xxh128', $path);
            $files[] = \substr($path, \strlen($real) + 1) . ':' . ($hash === false ? 'unreadable' : $hash);
        }
        \sort($files, \SORT_STRING);
        $parts[] = \implode("\n", $files);
    }

    $material = \hash('xxh128', \implode("\n", $parts));
    ConfiguredHostLibraries::rememberMaterial($material);

    return $material;
}
