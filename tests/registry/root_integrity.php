#!/usr/bin/env php
<?php declare(strict_types=1);

// A signature proves a root is authentic; it says nothing about when it was
// published. These checks cover the two signed fields that close the gap:
// `expires` (a frozen registry is refused, not trusted forever) and `version`
// (an older signed root cannot be replayed over a newer one this machine has
// already accepted).

$root = __DIR__;
while (!is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use const Moggi\Registry\ALLOW_STALE_REGISTRY_ENV;

use function Moggi\Registry\registryTimestamp;
use function Moggi\Registry\rememberedRootVersion;
use function Moggi\Registry\rememberRootVersion;
use function Moggi\Registry\rootIntegrityProblem;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$work = \sys_get_temp_dir() . '/moggi-root-integrity-' . \bin2hex(\random_bytes(6));
\mkdir($work, 0777, true);
\putenv('MOGGI_USER_CACHE=' . $work . '/user-cache');
\putenv(ALLOW_STALE_REGISTRY_ENV);

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

$base = 'https://registry.invalid';
$now = 1_800_000_000;
$future = \gmdate('Y-m-d\TH:i:s\Z', $now + 86_400);
$past = \gmdate('Y-m-d\TH:i:s\Z', $now - 86_400);
$fresh = ['registry_npub' => 'npub1x', 'version' => 1, 'expires' => $future];

try {
    // --- timestamps ----------------------------------------------------------
    $assert(registryTimestamp('2027-01-02T03:04:05Z') === \strtotime('2027-01-02T03:04:05Z'), 'an RFC 3339 `Z` timestamp parses');
    $assert(registryTimestamp('2027-01-02T03:04:05+02:00') !== null, 'an RFC 3339 offset timestamp parses');
    foreach (['2027-01-02', '2027-01-02T03:04:05', 'tomorrow', ''] as $bad) {
        $assert(registryTimestamp($bad) === null, "`{$bad}` is not an RFC 3339 timestamp");
    }

    // --- a root must declare both fields ------------------------------------
    $noExpires = ['registry_npub' => 'npub1x', 'version' => 1];
    $assert(\str_contains(rootIntegrityProblem($base, $noExpires, $now), '`expires`'), 'a root with no expiry is refused');

    $badExpires = ['registry_npub' => 'npub1x', 'version' => 1, 'expires' => 'soon'];
    $assert(\str_contains(rootIntegrityProblem($base, $badExpires, $now), 'RFC 3339'), 'a root with an unparseable expiry is refused');

    $noVersion = ['registry_npub' => 'npub1x', 'expires' => $future];
    $assert(\str_contains(rootIntegrityProblem($base, $noVersion, $now), '`version`'), 'a root with no version is refused');

    $zeroVersion = ['registry_npub' => 'npub1x', 'version' => 0, 'expires' => $future];
    $assert(\str_contains(rootIntegrityProblem($base, $zeroVersion, $now), 'below 1'), 'a root version below 1 is refused');

    // --- fresh and monotonic -------------------------------------------------
    $assert(rootIntegrityProblem($base, $fresh, $now) === '', 'a fresh, versioned root is accepted');
    $assert(rememberedRootVersion($base) === null, 'reading the high-water mark alone records nothing');

    rememberRootVersion($base, 1);
    $assert(rememberedRootVersion($base) === 1, 'the accepted version is remembered');
    $assert(rootIntegrityProblem($base, $fresh, $now) === '', 'the same version again is accepted');

    rememberRootVersion($base, 1);
    $assert(rememberedRootVersion($base) === 1, 'remembering a lower or equal version does not lower the mark');

    $assert(rootIntegrityProblem($base, ['registry_npub' => 'npub1x', 'version' => 2, 'expires' => $future], $now) === '', 'a newer version is accepted');
    rememberRootVersion($base, 2);
    $assert(rememberedRootVersion($base) === 2, 'the newer version is remembered');

    $rollback = rootIntegrityProblem($base, $fresh, $now);
    $assert(\str_contains($rollback, 'older than 2'), 'an older signed root is refused as a rollback: ' . $rollback);
    $assert(\str_contains($rollback, ALLOW_STALE_REGISTRY_ENV), 'the rollback refusal names the waiver');

    // --- expiry --------------------------------------------------------------
    $expired = ['registry_npub' => 'npub1x', 'version' => 3, 'expires' => $past];
    $assert(\str_contains(rootIntegrityProblem($base, $expired, $now), 'expired'), 'an expired root is refused');

    // --- the waiver, and that it never lowers the mark -----------------------
    \putenv(ALLOW_STALE_REGISTRY_ENV . '=1');
    $assert(rootIntegrityProblem($base, $expired, $now) === '', 'the waiver accepts an expired root');
    $assert(rootIntegrityProblem($base, $fresh, $now) === '', 'the waiver accepts a rollback');
    rememberRootVersion($base, 3);
    $assert(rememberedRootVersion($base) === 3, 'the high-water mark still rises under the waiver');
    \putenv(ALLOW_STALE_REGISTRY_ENV);

    $assert(rootIntegrityProblem($base, $fresh, $now) !== '', 'without the waiver the rollback is refused again');

    \putenv(ALLOW_STALE_REGISTRY_ENV . '=yes');
    $assert(rootIntegrityProblem($base, $fresh, $now) === '', '`yes` is a waiver spelling');
    \putenv(ALLOW_STALE_REGISTRY_ENV . '=maybe');
    $assert(rootIntegrityProblem($base, $fresh, $now) !== '', 'a non-affirmative value is not a waiver');
    \putenv(ALLOW_STALE_REGISTRY_ENV);

    echo "root integrity tests passed ({$checks} checks)\n";
} finally {
    $remove($work);
}
