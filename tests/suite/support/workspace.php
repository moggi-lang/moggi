<?php declare(strict_types=1);

/**
 * Drop what the compiler memoized for the case that just finished.
 *
 * Cases are separate projects that never recur, so their import closures and their own checked
 * modules are pure retention; the dependency closure stays memoized, because every case shares it.
 */
function releaseCaseMemos(): void
{
    if (!\class_exists(\Moggi\Modules\ProjectCache::class)) {
        return;
    }

    \Moggi\Modules\ProjectCache::clearPreparedProjects();
    \Moggi\Modules\ProjectCache::releaseProjectCheckedModules();
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
