<?php declare(strict_types=1);

// backends: php

/**
 * Deleting a source module invalidates the library index.
 *
 * The index used to be stamped with the newest mtime under the root, and a
 * deletion never moves that stamp forward: removing a file that is not the
 * newest one left it unchanged, so a later build read an index that still named
 * the module that was gone. The stamp now covers every source file, so a
 * removed name cannot come back.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Modules\LibraryModuleIndex;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$tree = \sys_get_temp_dir() . '/moggi-library-index-' . \bin2hex(\random_bytes(6));
@\mkdir($tree . '/Deep', 0700, true);

$older = $tree . '/Older.mog';
$newer = $tree . '/Deep/Newer.mog';
\file_put_contents($older, "module Older where\n");
\file_put_contents($newer, "module Newer where\n");
\touch($older, 1_600_000_000);
\touch($newer, 1_600_000_100);
\clearstatcache();

$before = LibraryModuleIndex::index($tree);
$assert(
    \array_key_exists('Older', $before),
    'the index did not record the older module: ' . \implode(',', \array_keys($before)),
);
$assert(
    \array_key_exists('Newer', $before),
    'the index did not record the newer module: ' . \implode(',', \array_keys($before)),
);

// The newest mtime under the root belongs to Newer, so removing Older leaves it
// where it was: exactly the deletion the old stamp could not see.
\unlink($older);
\clearstatcache();

$after = LibraryModuleIndex::index($tree);
$assert(
    ! \array_key_exists('Older', $after),
    'the index still offers a module whose source was deleted',
);
$assert(
    \array_key_exists('Newer', $after),
    'the index lost the module that is still there',
);

\unlink($newer);
@\rmdir($tree . '/Deep');
@\rmdir($tree);

echo "library index invalidation tests passed\n";
