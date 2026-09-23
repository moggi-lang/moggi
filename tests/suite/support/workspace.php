<?php declare(strict_types=1);

/**
 * Drop what the compiler memoized for the case that just finished: prepared projects are memoized by
 * fixture path set, so a run would otherwise retain one import closure per fixture.
 */
function releasePreparedProjects(): void
{
    if (\class_exists(\Moggi\Modules\ProjectCache::class)) {
        \Moggi\Modules\ProjectCache::clearPreparedProjects();
    }
}

function removeDirectory(string $path): void
{
    if (!\is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            unlink($item->getPathname());
            continue;
        }

        if ($item->isDir()) {
            rmdir($item->getPathname());
        }
    }

    rmdir($path);
}

/** A unique temporary directory; throws `TestFailure` when none can be created. */
function createTempDir(string $prefix): string
{
    for ($attempt = 0; $attempt < 10; ++$attempt) {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . getmypid() . '-' . bin2hex(random_bytes(8));
        if (@mkdir($dir, 0700)) {
            return $dir;
        }
    }

    throw new TestFailure("{$prefix}: cannot create a unique temporary directory");
}
