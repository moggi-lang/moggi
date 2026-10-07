#!/usr/bin/env php
<?php declare(strict_types=1);

// A package the registry marks bad is refused, not fetched: `bad` is a
// package-level field in the signed catalog shard, so it travels with the version
// list and a client reads it without a package file. These checks cover the
// marker shapes a shard may carry, the refusal line, and that a directory
// registry's `bad` reaches the client through the ordinary catalog read.

$root = __DIR__;
while (!is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Registry\badPackageMarker;
use function Moggi\Registry\badPackageProblem;
use function Moggi\Registry\badPackagesAmong;
use function Moggi\Registry\badPackagesRefusal;
use function Moggi\Registry\loadCatalog;
use function Moggi\Registry\sha256Digest;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$npub = 'npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm';
$short = \substr($npub, 0, 12);

// --- the shapes a shard may carry -------------------------------------------
$assert(badPackageMarker([]) === null, 'an entry with no `bad` is not marked');
$assert(badPackageMarker(['bad' => null]) === null, 'a null `bad` is not marked');
$assert(badPackageMarker(['bad' => false]) === null, 'a false `bad` is not marked');

$bare = badPackageMarker(['bad' => true]);
$assert($bare === ['reason' => 'no reason given', 'at' => null, 'by' => null], 'a bare `bad` still refuses: ' . \json_encode($bare));
$assert(badPackageMarker(['bad' => 'plain text'])['reason'] === 'plain text', 'a string `bad` is its own reason');

$full = badPackageMarker(['bad' => ['reason' => 'RCE in the decoder', 'at' => '2026-10-05', 'by' => $npub]]);
$assert($full === ['reason' => 'RCE in the decoder', 'at' => '2026-10-05', 'by' => $npub], 'a full marker is read: ' . \json_encode($full));
$assert(badPackageMarker(['bad' => ['at' => '2026-10-05']])['reason'] === 'no reason given', 'a marker without a reason still refuses');
$assert(badPackageMarker(['bad' => ['reason' => '   ']])['reason'] === 'no reason given', 'a blank reason is no reason');

// --- the refusal line and the derived set -----------------------------------
$problem = badPackageProblem('json', $full);
$assert(\str_contains($problem, '`json` is marked bad'), 'the line names the package: ' . $problem);
$assert(\str_contains($problem, 'RCE in the decoder'), 'the line carries the reason');
$assert(\str_contains($problem, 'on 2026-10-05'), 'the line carries the date');
$assert(\str_contains($problem, $short), 'the line names who marked it');

$among = badPackagesAmong([
    'json' => ['bad' => ['reason' => 'x']],
    'base' => [],
    'evil' => ['bad' => true],
]);
$assert(\array_keys($among) === ['json', 'evil'], 'only marked packages are collected: ' . \json_encode(\array_keys($among)));

// --- the shared refusal, so install/build/update phrase it the same way ------
$refusal = badPackagesRefusal(['json' => $full], 'in this lock', 'pass --allow-bad to install them anyway');
$assert(\str_contains($refusal, 'in this lock as bad'), 'the refusal names the context: ' . $refusal);
$assert(\str_contains($refusal, 'RCE in the decoder'), 'the refusal carries the reason: ' . $refusal);
$assert(\str_contains($refusal, 'pass --allow-bad'), 'the refusal names the way past: ' . $refusal);

// --- through a signed catalog shard -----------------------------------------
$work = \sys_get_temp_dir() . '/moggi-bad-' . \bin2hex(\random_bytes(6));
\mkdir($work . '/catalog', 0777, true);
\mkdir($work . '/broken/catalog', 0777, true);
\putenv('MOGGI_CACHE_DIR=' . $work . '/cache');
\putenv('MOGGI_USER_CACHE=' . $work . '/user-cache');

$remove = static function (string $path) use (&$remove): void {
    if (!\file_exists($path) && !\is_link($path)) {
        return;
    }
    if (\is_dir($path) && !\is_link($path)) {
        foreach (\scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $remove($path . '/' . $name);
            }
        }
        @\rmdir($path);

        return;
    }
    @\unlink($path);
};

$shard = static function (string $prefix, array $packages): string {
    return \json_encode(['format' => 1, 'prefix' => $prefix, 'packages' => $packages], \JSON_PRETTY_PRINT) . "\n";
};
$jsShard = $shard('js', [
    'json' => [
        'owner' => $npub,
        'authors' => [$npub],
        'latest' => '0.1.0',
        'description' => 'JSON encoding and decoding.',
        'bad' => ['reason' => 'RCE in the decoder', 'at' => '2026-10-05', 'by' => $npub],
        'versions' => ['0.1.0' => ['deps' => []]],
    ],
]);
$exShard = $shard('ex', [
    'evil' => [
        'owner' => $npub,
        'authors' => [$npub],
        'latest' => '1.0.0',
        'description' => 'a plain package',
        'versions' => ['1.0.0' => ['deps' => []]],
    ],
]);
\file_put_contents($work . '/catalog/js.json', $jsShard);
\file_put_contents($work . '/catalog/ex.json', $exShard);
\file_put_contents($work . '/index.json', \json_encode([
    'format' => 1,
    'version' => 1,
    'expires' => '2999-01-01T00:00:00Z',
    'registry_npub' => $npub,
    'shards' => ['js' => sha256Digest($jsShard), 'ex' => sha256Digest($exShard)],
], \JSON_PRETTY_PRINT) . "\n");

try {
    $catalog = loadCatalog($work, null, null, false);
    $json = $catalog->entry('json');
    $assert($json !== null, 'the marked package is listed');
    $assert(badPackageMarker((array) $json)['reason'] === 'RCE in the decoder', 'the shard `bad` reaches the client');
    $assert(badPackageMarker((array) $catalog->entry('evil')) === null, 'an unmarked package stays unmarked');

    $packages = $catalog->packages();
    $assert(\count($packages) === 2 && isset($packages['json'], $packages['evil']), 'every shard is read: ' . \json_encode(\array_keys($packages)));
    $assert(\array_keys(badPackagesAmong($packages)) === ['json'], 'the bad set is derived from the catalog read');

    // A shard that does not match the root it is named in refuses the whole read
    // rather than answering from the shards that did load.
    \file_put_contents($work . '/broken/catalog/js.json', $jsShard);
    \file_put_contents($work . '/broken/index.json', \json_encode([
        'format' => 1,
        'version' => 1,
        'expires' => '2999-01-01T00:00:00Z',
        'registry_npub' => $npub,
        'shards' => ['js' => 'sha256:' . \str_repeat('0', 64)],
    ], \JSON_PRETTY_PRINT) . "\n");
    $refused = false;
    try {
        loadCatalog($work . '/broken', null, null, false)->packages();
    } catch (\RuntimeException $error) {
        $refused = \str_contains($error->getMessage(), 'inconsistent');
    }
    $assert($refused, 'a shard that does not match the root refuses the whole read');

    echo "bad package tests passed ({$checks} checks)\n";
} finally {
    $remove($work);
}
