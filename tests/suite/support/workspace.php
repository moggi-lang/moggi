<?php declare(strict_types=1);

require_once __DIR__ . '/../../../src/paths.php';

/**
 * Drop what the compiler memoized for the case that just finished.
 *
 * Cases are separate projects that never recur, so their own checked modules
 * are pure retention; the shared dependency closures stay memoized under an
 * LRU cap, because every case shares them.
 */
function releaseCaseMemos(): void
{
    if (! \class_exists(\Moggi\Modules\ProjectCache::class)) {
        return;
    }

    \Moggi\Modules\ProjectCache::releaseProjectCheckedModules();
}

function removeDirectory(string $path): void
{
    if (! \is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            @\unlink($item->getPathname());

            continue;
        }

        if ($item->isDir()) {
            @\rmdir($item->getPathname());
        }
    }

    @\rmdir($path);
}

/**
 * A cache directory of the case's own that already knows the standard library.
 *
 * Every compile elaborates the whole standard library before it reaches the program, which is what
 * the cache exists to avoid — but a case that wants a cache to itself (its own module, its own
 * project) would pay that elaboration again from an empty directory, seconds each time. The
 * library modules' cached products (`compile/<backend>/libraries/`) and the `stdlib-index` artifact
 * the linker reads are hard-linked out of the run's cache, which the harness keeps warm, while
 * everything derived from the *case's* sources stays absent: the program under test is still
 * compiled from scratch, and the case still sees a cache that has never built it.
 *
 * Hard links are safe here because every cache write replaces its file atomically (temp + rename)
 * instead of editing one in place, so a seeded directory cannot reach back into the run's cache.
 */
/**
 * The compiler's cache root, read without loading the compiler: a case that only spawns `moggi.php`
 * must still be able to seed, which it could not while this asked the compiler what its cache was.
 */
function testCacheBaseDir(): string
{
    $override = \getenv('MOGGI_CACHE_DIR');
    if (\is_string($override) && $override !== '') {
        return \rtrim($override, '/');
    }
    $cwd = \getcwd();

    return ($cwd === false ? '.' : $cwd) . '/.moggi';
}

function seedCacheDir(string $dir): void
{
    $off = \getenv('MOGGI_NO_CACHE');
    if (\is_string($off) && $off !== '' && $off !== '0') {
        return;
    }

    $source = testCacheBaseDir();
    if ($source === \rtrim($dir, '/')) {
        return;
    }

    $fingerprint = $source . '/fingerprint';
    if (\is_file($fingerprint)) {
        @\mkdir($dir, 0700, true);
        @\copy($fingerprint, $dir . '/fingerprint');
    }

    foreach (\Moggi\Paths\globPaths($source . '/compile/*/libraries', \GLOB_ONLYDIR) as $libraries) {
        $backend = \basename(\dirname($libraries));
        hardlinkTree($libraries, $dir . '/compile/' . $backend . '/libraries');
    }

    foreach (\Moggi\Paths\globPaths($source . '/compile/artifacts/stdlib-index*.blob') as $blob) {
        $target = $dir . '/compile/artifacts/' . \basename($blob);
        @\mkdir(\dirname($target), 0700, true);
        if (! @\link($blob, $target)) {
            @\copy($blob, $target);
        }
    }
}

/** Link (or, when linking fails, copy) a whole tree under `$target`. */
function hardlinkTree(string $source, string $target): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $relative = \substr($item->getPathname(), \strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($item->isDir()) {
            @\mkdir($destination, 0700, true);

            continue;
        }

        @\mkdir(\dirname($destination), 0700, true);
        if (! @\link($item->getPathname(), $destination)) {
            @\copy($item->getPathname(), $destination);
        }
    }
}

/**
 * A unique temporary directory; throws `TestFailure` when none can be created.
 *
 * Always `/`-spelled, even where `sys_get_temp_dir()` answers with `\`
 * (Windows): every scan in the compiler returns `/`-spelled paths, so a test
 * that joins onto this root with `/` compares equal to what a scan found.
 * PHP opens `/`-spelled paths on every host.
 *
 * A case that seeds its cache pays a copy of the library tree across filesystems;
 * `seedCacheDir()` links where it can.
 */
function createTempDir(string $prefix): string
{
    $bases = [\rtrim(\str_replace('\\', '/', (string) \sys_get_temp_dir()), '/')];

    foreach ($bases as $base) {
        if (! \is_dir($base) && ! @\mkdir($base, 0700, true) && ! \is_dir($base)) {
            continue;
        }
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $dir = $base . '/' . $prefix . '-' . \getmypid() . '-' . \bin2hex(\random_bytes(8));
            if (@\mkdir($dir, 0700)) {
                return $dir;
            }
        }
    }

    throw new TestFailure("{$prefix}: cannot create a unique temporary directory");
}
