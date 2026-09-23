<?php declare(strict_types=1);

namespace Moggi\Cache;

use function Moggi\Backend\compileBackend;

/**
 * Per-project compile cache under `./.moggi` (override with MOGGI_CACHE_DIR):
 *   <cache>/<backend>/<source-mirror>.<kind>.mogc
 *
 * Mirrors the source tree under a per-backend directory so php/jvm/dotnet
 * caches coexist (switching backends must not overwrite each other). The
 * content key is stored inside and checked on read.
 *
 * The cache belongs to one compiler. The fingerprint of the compiler's own
 * sources sits in `<cache>/.fingerprint`, and a compiler whose fingerprint does
 * not match that file throws the whole tree away and rebuilds instead of reading
 * entries another compiler wrote. Set MOGGI_NO_CACHE=1 to bypass.
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

/** File naming the compiler the cache on disk was built by. */
function fingerprintPath(): string
{
    return cacheBaseDir() . '/.fingerprint';
}

/**
 * One cache tree, owned by whichever compiler built it.
 *
 * Called before the first cache read or write: a stored fingerprint that is not
 * this compiler's means everything under the cache root was written by a
 * different compiler, so it is deleted and the fingerprint is written again.
 * Entries, harness artifacts and the docs index all live under the same root, so
 * none of them can outlive the compiler that produced them.
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

    // The old tree is gone before the new name exists, so every process starting during the
    // transition sees a mismatch; the lock keeps them from deleting each other's tree.
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

/** Drop the tree the previous compiler filled and stamp the cache with the current one. */
function resetCacheTree(string $base, string $fingerprint): void
{
    removeTree($base);
    ensureDir($base);
    atomicWrite(fingerprintPath(), $fingerprint);
}

/**
 * Root the cache entries and the harness artifacts live under.
 *
 * Kept as a named function (rather than inlined `cacheBaseDir()` calls) because
 * the test harness publishes its scratch trees beside the entries.
 */
function cacheGenerationDir(): string
{
    return cacheBaseDir();
}

/** Scratch tree the test harness publishes under the *current* generation. */
function cacheScratchDir(): string
{
    return cacheGenerationDir() . '/test-artifacts';
}

/** Sanitize a mirrored relative source path (drop extension, keep directories). */
function mirrorPath(string $relPath): string
{
    $relPath = \str_replace('\\', '/', $relPath);
    $relPath = preg_replace('/\.mog$/', '', $relPath) ?? $relPath;
    // Defensive: never let `..` escape the cache root.
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

    return cacheGenerationDir() . '/' . $backend . '/' . mirrorPath($relPath) . '.' . $kind . '.mogc';
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

    $path = cacheGenerationDir() . '/.artifacts/' . artifactFileName($key) . '.blob';
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
    atomicWrite(cacheGenerationDir() . '/.artifacts/' . artifactFileName($key) . '.blob', $raw);
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
 * Remove everything under the cache root: the entries, the harness artifacts and
 * the fingerprint that named the compiler.
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
 * Summary of the compiler cache for `moggi cache info`.
 *
 * @return array{
 *   cache: string,
 *   fingerprint: string,
 *   entries: int,
 *   bytes: int,
 *   enabled: bool
 * }
 */
function info(): array
{
    $base = cacheBaseDir();
    $size = \is_dir($base) ? treeSize($base) : ['entries' => 0, 'bytes' => 0];

    return [
        'cache' => $base,
        'fingerprint' => compilerFingerprint(),
        'entries' => $size['entries'],
        'bytes' => $size['bytes'],
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
