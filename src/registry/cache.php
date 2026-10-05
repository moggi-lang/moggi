<?php declare(strict_types=1);

namespace Moggi\Registry;

use function Moggi\Cache\atomicWrite;
use function Moggi\Cache\catalogDir;
use function Moggi\Cache\packagesDir;

/**
 * Where a registry client keeps what it already fetched.
 *
 * Two roots, because they have different owners. **Metadata** — the signed root,
 * its signature, the shards, the package files — is small and per project, so it
 * lives under the project cache (`.moggi/`, `MOGGI_CACHE_DIR`). **Content** —
 * archives, later runtimes — is addressed by digest and can be large, so it lives
 * in the user-level cache (`$XDG_CACHE_HOME/moggi`, `MOGGI_USER_CACHE`), where one
 * copy serves every project on the machine.
 *
 * Unpacked package trees are addressed by digest too, but they are *not* shared:
 * a package is compiled against one project's compiler and lock, so its
 * `packages/<name>/<digest>/` tree lives in the project cache beside it. Only the
 * downloaded archive is fetched once per machine.
 *
 * Both roots are keyed so two registries never share a directory: metadata by the
 * base URL, content by the sha256 it is named after.
 */

/** A readable, collision-free directory name for one registry base. */
function registryKey(string $base): string
{
    $slug = \preg_replace('/[^A-Za-z0-9]+/', '-', \trim($base, '/')) ?? '';
    $slug = \trim(\substr($slug, -48), '-');

    return ($slug === '' ? 'registry' : $slug) . '-' . \substr(\hash('sha256', $base), 0, 8);
}

/** Per-project metadata cache: `<cache>/catalog/<base-key>/`. */
function catalogCacheDir(string $base): string
{
    return catalogDir() . '/' . registryKey($base);
}

/** User-level content cache: `$XDG_CACHE_HOME/moggi` by default, or `MOGGI_USER_CACHE`. */
function userCacheDir(): string
{
    $override = \getenv('MOGGI_USER_CACHE');
    if (\is_string($override) && $override !== '') {
        return \rtrim($override, '/');
    }

    $xdg = \getenv('XDG_CACHE_HOME');
    if (\is_string($xdg) && $xdg !== '') {
        return \rtrim($xdg, '/') . '/moggi';
    }

    $home = \getenv('HOME');

    return (\is_string($home) && $home !== '' ? \rtrim($home, '/') : '.') . '/.cache/moggi';
}

/** Where one archive lives: `downloads/<sha256>`, content-addressed. */
function downloadPath(string $digest): string
{
    return userCacheDir() . '/downloads/' . \str_replace('sha256:', '', $digest);
}

/** Where one release is unpacked: `packages/<name>/<digest>`, digest stripped of its prefix. */
function installDir(string $name, string $digest): string
{
    assertPackageName($name, 'install directory');
    $key = \str_replace('sha256:', '', $digest);
    if (\preg_match('/^[0-9a-f]{64}$/', $key) !== 1) {
        $key = 'unknown';
    }

    return packagesDir() . '/' . $name . '/' . $key;
}

/**
 * Read a cached file, but only if it is the version asked for. The digest *is*
 * the validator, so "is this stale?" is one hash away and needs no network —
 * which is what lets a warm resolve skip a request instead of revalidating it.
 */
function cachedBytes(string $path, ?string $expectedDigest = null): ?string
{
    if (!\is_file($path)) {
        return null;
    }
    $bytes = (string) \file_get_contents($path);
    if ($expectedDigest !== null && sha256Digest($bytes) !== $expectedDigest) {
        return null;
    }

    return $bytes;
}

/**
 * Write a fetched file for the next run. Failures are never fatal: a cache miss
 * just re-fetches. The write is atomic (`Moggi\Cache\atomicWrite`) so a reader
 * never sees a half-written shard or root.
 */
function storeBytes(string $path, string $bytes): void
{
    atomicWrite($path, $bytes);
}
