#!/usr/bin/env php
<?php declare(strict_types=1);

// A module's artifact path is its source path with the project root removed. The
// root is not always an ancestor of every source in a closure: a temporary
// application compiled against a checkout on another drive has no common root at
// all — on Windows `commonPathPrefix` reports the bare separator, resolving it
// lands on a drive root, and subtracting that length loses characters the path
// never had. The tree then holds a path that is not the file's, and the
// `require`s that count their way to `_runtime.php` count to the wrong place.
//
// The inputs are spelled with `/` on every host, which is the spelling Windows
// resolves to as well (`canonicalSeparators`), so one expectation covers both.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Modules\outputRelativePath;

$checks = 0;
$assertSame = static function (string $expected, string $actual, string $message) use (&$checks): void {
    ++$checks;
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $message . ' (expected ' . \var_export($expected, true) . ', got ' . \var_export($actual, true) . ')',
        );
    }
};

$assertSame(
    'proj/lib/Data/Ord.mog',
    outputRelativePath('/proj/lib/Data/Ord.mog', '/'),
    'the root of the filesystem leaves everything below it',
);
$assertSame(
    'lib/Data/Ord.mog',
    outputRelativePath('/proj/lib/Data/Ord.mog', '/proj/'),
    'a path under the root is what is left once the root is removed',
);
$assertSame(
    'other/lib/X.mog',
    outputRelativePath('/other/lib/X.mog', '/proj/'),
    'a path outside the root keeps its own structure rather than losing characters to the prefix',
);
$assertSame(
    'tmp/app/src/App.mog',
    outputRelativePath('C:/tmp/app/src/App.mog', 'D:/'),
    'a path on another drive keeps its own structure, with the drive dropped',
);
$assertSame(
    'a/moggi/moggi/lib/Data/Ord.mog',
    outputRelativePath('D:/a/moggi/moggi/lib/Data/Ord.mog', 'C://'),
    'a root the path cannot be joined to leaves the path intact, drive dropped',
);

echo "output relative path tests passed ({$checks} checks)\n";
