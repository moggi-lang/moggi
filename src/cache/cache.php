<?php declare(strict_types=1);

namespace Moggi\Cache;

use function Moggi\Backend\compileBackend;

/**
 * One cache root (`./.moggi`, override with MOGGI_CACHE_DIR) holding every kind of
 * thing moggi keeps between runs, each under a directory named for what put it
 * there:
 *
 *   fingerprint                              the compiler that filled the rest
 *   compile/<backend>/<mirror>.<kind>.mogc   a per-module compilation entry
 *   compile/artifacts/<key>.blob             a whole-project artifact
 *   compile/docs-index/<key>.json            the mogdoc/moogle index
 *   docs/                                    `moggi mogdoc serve` output
 *   catalog/<registry-key>/                  fetched registry metadata
 *   packages/<name>/<digest>/                an unpacked package
 *   runtime/<key>/                           an artifact a host tool fetched
 *   test/                                    the test harness's scratch trees
 *
 * Entries mirror the source tree under a per-backend directory so php/jvm/dotnet
 * caches coexist (switching backends must not overwrite each other). The content
 * key is stored inside and checked on read.
 *
 * One compiler owns the tree: `fingerprint` holds the hash of its sources, and a
 * compiler whose fingerprint does not match what is written there drops what the
 * compiler *derives* (`compile/`, `docs/`) and keeps what it merely *found*
 * (`catalog/`, `packages/`, `runtime/`) — those are addressed by digest, so they
 * are as valid to the new compiler as they were to the old one, and re-fetching
 * one costs a network round trip. Set MOGGI_NO_CACHE=1 to bypass.
 */

/** Explicit `--no-cache`/`--cache` choice, overriding MOGGI_NO_CACHE. */
final class CacheSwitch
{
    private static ?bool $override = null;

    public static function set(bool $enabled): void
    {
        self::$override = $enabled;
    }

    public static function override(): ?bool
    {
        return self::$override;
    }
}

function setCacheEnabled(bool $enabled): void
{
    CacheSwitch::set($enabled);
}

function cacheEnabled(): bool
{
    $override = CacheSwitch::override();
    if ($override !== null) {
        return $override;
    }

    $env = getenv('MOGGI_NO_CACHE');

    return !($env !== false && $env !== '' && $env !== '0');
}

function projectRoot(): string
{
    return dirname(__DIR__, 2);
}

/** Root of the local project cache (defaults to ./.moggi). */
function cacheBaseDir(): string
{
    $override = getenv('MOGGI_CACHE_DIR');
    if ($override !== false && $override !== '') {
        return rtrim($override, '/');
    }

    $cwd = getcwd();

    return ($cwd === false ? '.' : $cwd) . '/.moggi';
}

/** Hash of the compiler's own sources: changing the compiler drops all cache. */
function compilerFingerprint(): string
{
    static $fingerprint = null;

    return $fingerprint ??= hashCompilerSources();
}

/**
 * The same hash, computed now instead of reused from this process's memo.
 *
 * A long-running process (the language server) keeps the code it started with,
 * so the memoized value is the revision it is *running*; this one tells whether
 * the compiler on disk has moved on since.
 */
function freshCompilerFingerprint(): string
{
    return hashCompilerSources();
}

function hashCompilerSources(): string
{
    $root = projectRoot();
    $parts = [];

    $files = [];
    $srcDir = $root . '/src';
    if (\is_dir($srcDir)) {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);
    foreach ($files as $path) {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            continue;
        }
        $rel = substr($path, strlen($root) + 1);
        $parts[] = $rel . ':' . hashContent($contents);
    }

    array_unshift(
        $parts,
        'php:' . PHP_VERSION,
        'int:' . PHP_INT_SIZE,
    );

    return substr(hash('sha256', \implode("\n", $parts)), 0, 32);
}

function hashContent(string $data): string
{
    return hash('xxh128', $data);
}

/** Content fingerprint of a source file (memoized by realpath+mtime+size). */
function fileFingerprint(string $path): string
{
    static $memo = [];

    $real = realpath($path);
    if ($real === false) {
        return 'missing';
    }

    $stat = @stat($real);
    $statKey = $real . ':' . ($stat['mtime'] ?? 0) . ':' . ($stat['size'] ?? 0);
    if (isset($memo[$statKey])) {
        return $memo[$statKey];
    }

    $contents = @file_get_contents($real);
    $hash = $contents === false ? 'unreadable' : hashContent($contents);
    $memo[$statKey] = $hash;

    return $hash;
}

/** The file naming the compiler the rest of the cache was built by. */
function fingerprintPath(): string
{
    return cacheBaseDir() . '/fingerprint';
}

/**
 * One cache tree, owned by whichever compiler built it.
 *
 * Called before the first cache read or write: a stored fingerprint that is not
 * this compiler's means everything derived from sources was written by a
 * different compiler, so it is dropped and the fingerprint is written again.
 * Entries, artifacts, the docs index and the served site all sit under that same
 * compiler-owned half of the cache, so none of them outlives its compiler.
 */
function ensureCache(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $done = true;

    if (!cacheEnabled()) {
        return;
    }

    $base = cacheBaseDir();
    $fingerprint = compilerFingerprint();
    if (@file_get_contents(fingerprintPath()) === $fingerprint) {
        return;
    }

    $lock = @fopen(cacheLockPath(), 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        resetCacheTree($base, $fingerprint);

        return;
    }

    try {
        if (@file_get_contents(fingerprintPath()) !== $fingerprint) {
            resetCacheTree($base, $fingerprint);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * The lock a cache reset is serialized on.
 *
 * It lives outside the cache root, which the reset removes — a lock file inside could not outlive
 * the operation it guards. Keyed by the cache root so two checkouts with different caches do not
 * wait on each other.
 */
function cacheLockPath(): string
{
    return rtrim(\sys_get_temp_dir(), '/') . '/moggi-cache-' . substr(hash('sha256', cacheBaseDir()), 0, 16) . '.lock';
}

/**
 * Top-level cache directories a compiler change must not take with it.
 *
 * Fetched registry metadata, unpacked packages and tool-fetched runtime artifacts
 * live under the cache root too (`./.moggi`), but none is derived from the
 * compiler: all three are addressed by digest, so they are as valid after a
 * compiler change as before it, and re-fetching one costs a network round trip.
 * `moggi cache clear` still removes them — that is a user asking for the space back.
 */
const KEPT_ACROSS_RESET = ['catalog', 'packages', 'runtime'];

/** Drop the tree the previous compiler filled and stamp the cache with the current one. */
function resetCacheTree(string $base, string $fingerprint): void
{
    removeTreeExcept($base, KEPT_ACROSS_RESET);
    ensureDir($base);
    atomicWrite(fingerprintPath(), $fingerprint);
}

/** Remove everything under `$dir` except the named top-level entries. */
function removeTreeExcept(string $dir, array $keep): void
{
    if (!\is_dir($dir)) {
        removeTree($dir);

        return;
    }

    $entries = \scandir($dir);
    if ($entries === false) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || \in_array($entry, $keep, true)) {
            continue;
        }
        removeTree($dir . '/' . $entry);
    }
}

/**
 * What the compiler derives from sources: the entries, the project artifacts and
 * the docs index. Everything here is rebuildable, so a fingerprint mismatch takes
 * the whole directory.
 */
function compileDir(): string
{
    return cacheBaseDir() . '/compile';
}

/** The static site `moggi mogdoc serve` generated, reused until its sources move. */
function docsCacheDir(): string
{
    return cacheBaseDir() . '/docs';
}

/** Fetched registry metadata: one `<cache>/catalog/<base-key>/` per registry. */
function catalogDir(): string
{
    return cacheBaseDir() . '/catalog';
}

/**
 * Unpacked packages: `<cache>/packages/<name>/<digest>/`.
 *
 * Content-addressed, so one digest is unpacked once and re-used until the release
 * record it was unpacked from changes. It sits in the *project* cache, beside the
 * compiler cache it is built against: a package is compiled with one project's
 * compiler and lock, so every project keeps its own unpacked copy.
 */
function packagesDir(): string
{
    return cacheBaseDir() . '/packages';
}

/** Artifacts a host tool fetched (maven, composer, nuget) — content-addressed too. */
function runtimeCacheDir(): string
{
    return cacheBaseDir() . '/runtime';
}

/** The test harness's scratch trees: `exec/`, `logs/`, the stdlib build. */
function cacheScratchDir(): string
{
    return cacheBaseDir() . '/test';
}

/** Sanitize a mirrored relative source path (drop extension, keep directories). */
function mirrorPath(string $relPath): string
{
    $relPath = \str_replace('\\', '/', $relPath);
    $relPath = preg_replace('/\.mog$/', '', $relPath) ?? $relPath;
    $relPath = \str_replace('..', '__', $relPath);

    return ltrim($relPath, '/');
}

function ensureDir(string $dir): bool
{
    if (\is_dir($dir)) {
        return true;
    }

    return @mkdir($dir, 0777, true) || \is_dir($dir);
}

/** Atomically write $data to $path (same-directory tmp file then rename). */
function atomicWrite(string $path, string $data): bool
{
    if (!ensureDir(dirname($path))) {
        return false;
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
    if (@\file_put_contents($tmp, $data) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function decodePayload(string $raw): mixed
{
    try {
        $value = @unserialize($raw);
    } catch (\Throwable) {
        return null;
    }

    if (!\is_array($value)) {
        return null;
    }

    return $value;
}

/**
 * Read a cached per-module artifact.
 *
 * @param string $relPath    source path mirrored under the cache root
 * @param string $kind       artifact kind (e.g. 'checked', 'localtypes', 'exports')
 * @param string $contentKey Merkle content key of the module
 */
function moduleGet(string $relPath, string $kind, string $contentKey): mixed
{
    if (!cacheEnabled()) {
        return null;
    }

    ensureCache();

    $path = modulePath($relPath, $kind);
    if (!\is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $value = decodePayload($raw);
    if ($value === null) {
        return null;
    }

    if (($value['key'] ?? null) !== $contentKey) {
        return null;
    }

    return $value['payload'] ?? null;
}

function modulePut(string $relPath, string $kind, string $contentKey, mixed $payload): void
{
    if (!cacheEnabled()) {
        return;
    }

    ensureCache();

    $raw = serialize([
        'key' => $contentKey,
        'payload' => $payload,
    ]);
    atomicWrite(modulePath($relPath, $kind), $raw);
}

function modulePath(string $relPath, string $kind): string
{
    $backend = compileBackend();
    if (preg_match('/^[a-z0-9_-]+$/', $backend) !== 1) {
        $backend = 'unknown';
    }

    return compileDir() . '/' . $backend . '/' . mirrorPath($relPath) . '.' . $kind . '.mogc';
}

/**
 * An artifact key as a file name: a Windows name may not have the `:` the stdlib index key carries,
 * so an escaped key keeps a hash of its own spelling and cannot collide with an unescaped twin.
 */
function artifactFileName(string $key): string
{
    $safe = \preg_replace('/[^A-Za-z0-9._-]/', '_', $key) ?? $key;

    return $safe === $key ? $key : $safe . '-' . \substr(hash('sha256', $key), 0, 12);
}

/** Whole-project artifact cache (e.g. `moggi compile` outputs). */
function artifactGet(string $key): mixed
{
    if (!cacheEnabled()) {
        return null;
    }

    ensureCache();

    $path = compileDir() . '/artifacts/' . artifactFileName($key) . '.blob';
    if (!\is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $value = decodePayload($raw);

    return $value === null ? null : ($value['payload'] ?? null);
}

function artifactPut(string $key, mixed $payload): void
{
    if (!cacheEnabled()) {
        return;
    }

    ensureCache();

    $raw = serialize(['payload' => $payload]);
    atomicWrite(compileDir() . '/artifacts/' . artifactFileName($key) . '.blob', $raw);
}

function removeTree(string $dir): int
{
    if (!\is_dir($dir)) {
        if (\is_file($dir) && @unlink($dir)) {
            return 1;
        }
        return 0;
    }

    $count = 0;
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } elseif (@unlink($item->getPathname())) {
            ++$count;
        }
    }
    @rmdir($dir);

    return $count;
}

/**
 * The areas `moggi cache clear` can drop one at a time.
 *
 * An area is named for what it costs to lose: `compiler` is a rebuild, `catalog`
 * and `packages` a fetch. The user-level download cache is deliberately not here
 * — it is shared by every project on the machine, so the command handles it by
 * name rather than letting a project's clear reach it.
 *
 * @return array<string, list<string>>
 */
function cacheAreas(): array
{
    return [
        'compiler' => [compileDir(), docsCacheDir()],
        'catalog' => [catalogDir()],
        'packages' => [packagesDir()],
        'runtime' => [runtimeCacheDir()],
        'test' => [cacheScratchDir()],
    ];
}

/**
 * Remove one area, or `all` of them under the project cache root.
 *
 * `all` is the whole root rather than a walk over the areas: the fingerprint goes
 * with it, so the next compile starts from nothing instead of trusting a stamp
 * whose tree is gone.
 *
 * @throws \InvalidArgumentException when `$area` names no area
 */
function clearArea(string $area): int
{
    if ($area === 'all') {
        return clear();
    }

    $areas = cacheAreas();
    if (!isset($areas[$area])) {
        throw new \InvalidArgumentException(
            "unknown cache area `{$area}` (expected: " . \implode(' | ', \array_keys($areas)) . ' | all)',
        );
    }

    $removed = 0;
    foreach ($areas[$area] as $root) {
        $removed += removeTree($root);
    }

    return $removed;
}

/**
 * Remove everything under the project cache root: the entries, the artifacts, the
 * site, the harness scratch, the fingerprint that named the compiler, and — since
 * they live under the same root — the fetched catalog and the installed packages.
 */
function clear(): int
{
    $base = cacheBaseDir();
    if (!\is_dir($base)) {
        return 0;
    }

    return removeTree($base);
}

/**
 * Summary of the project cache for `moggi cache info`, area by area.
 *
 * `match` says whether the fingerprint on disk is this compiler's. When it is
 * not, the compiler-derived areas are a previous compiler's and the next run
 * drops them — which is what makes clearing after an upgrade optional rather than
 * required.
 *
 * @return array{
 *   cache: string,
 *   fingerprint: string,
 *   storedFingerprint: ?string,
 *   match: bool,
 *   areas: array<string, array{entries: int, bytes: int}>,
 *   entries: int,
 *   bytes: int,
 *   enabled: bool
 * }
 */
function info(): array
{
    $areas = [];
    $entries = 0;
    $bytes = 0;
    foreach (cacheAreas() as $name => $roots) {
        $size = ['entries' => 0, 'bytes' => 0];
        foreach ($roots as $root) {
            if (!\is_dir($root)) {
                continue;
            }
            $part = treeSize($root);
            $size['entries'] += $part['entries'];
            $size['bytes'] += $part['bytes'];
        }
        $areas[$name] = $size;
        $entries += $size['entries'];
        $bytes += $size['bytes'];
    }

    $stored = @\file_get_contents(fingerprintPath());
    $own = compilerFingerprint();

    return [
        'cache' => cacheBaseDir(),
        'fingerprint' => $own,
        'storedFingerprint' => $stored === false ? null : $stored,
        'match' => $stored === $own,
        'areas' => $areas,
        'entries' => $entries,
        'bytes' => $bytes,
        'enabled' => cacheEnabled(),
    ];
}

/** @return array{entries: int, bytes: int} */
function treeSize(string $dir): array
{
    $entries = 0;
    $bytes = 0;
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        if ($file->isFile()) {
            ++$entries;
            $bytes += $file->getSize();
        }
    }

    return ['entries' => $entries, 'bytes' => $bytes];
}
