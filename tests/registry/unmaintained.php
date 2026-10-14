#!/usr/bin/env php
<?php declare(strict_types=1);

// The `unmaintained` marker is the package-level maintenance signal beside
// `bad`: it travels in the signed catalog shard, warns instead of refusing, and
// a marker without a note is still a marker. These checks cover the shapes a
// shard may carry, that the two markers are independent of each other, and the
// one warning line every command prints.

$root = __DIR__;
while (!\is_file($root . '/src/registry/unmaintained.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Registry\badPackageMarker;
use function Moggi\Registry\unmaintainedMarker;
use function Moggi\Registry\unmaintainedPackageNote;
use function Moggi\Registry\unmaintainedPackagesAmong;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

// --- the shapes a shard may carry -------------------------------------------
$assert(unmaintainedMarker([]) === null, 'an entry with no `unmaintained` is not marked');
$assert(unmaintainedMarker(['unmaintained' => null]) === null, 'a null `unmaintained` is not marked');
$assert(unmaintainedMarker(['unmaintained' => false]) === null, 'a false `unmaintained` is not marked');
$assert(unmaintainedMarker(['unmaintained' => true]) === ['note' => null, 'at' => null], 'a bare marker has no note');
$assert(unmaintainedMarker(['unmaintained' => 'seeking a maintainer'])['note'] === 'seeking a maintainer', 'a string is its own note');

$full = unmaintainedMarker(['unmaintained' => ['at' => '2026-09-01', 'note' => 'no releases since 0.9']]);
$assert($full === ['note' => 'no releases since 0.9', 'at' => '2026-09-01'], 'a full marker is read: ' . \json_encode($full));
$assert(unmaintainedMarker(['unmaintained' => ['at' => '2026-09-01']]) === ['note' => null, 'at' => '2026-09-01'], 'a marker without a note keeps its date');
$assert(unmaintainedMarker(['unmaintained' => ['note' => '   ']])['note'] === null, 'a blank note is no note');

// --- the two markers are independent ----------------------------------------
$both = ['bad' => ['reason' => 'RCE'], 'unmaintained' => ['note' => 'dead']];
$assert(badPackageMarker($both) !== null && unmaintainedMarker($both) !== null, 'an entry may carry both markers');

// --- the derived set ---------------------------------------------------------
$among = unmaintainedPackagesAmong([
    'json' => ['unmaintained' => ['note' => 'x']],
    'base' => [],
    'stale' => ['unmaintained' => true],
]);
$assert(\array_keys($among) === ['json', 'stale'], 'only marked packages are collected: ' . \json_encode(\array_keys($among)));

// --- the warning line --------------------------------------------------------
$line = unmaintainedPackageNote('stale', $full);
$assert(\str_contains($line, '`stale` is marked unmaintained'), 'the line names the package: ' . $line);
$assert(\str_contains($line, 'since 2026-09-01'), 'the line carries the date');
$assert(\str_contains($line, 'no releases since 0.9'), 'the line carries the note');
$assert(\str_contains($line, 'still works'), 'the marker warns, it does not refuse: ' . $line);

echo "unmaintained marker tests passed ({$checks} checks)\n";
