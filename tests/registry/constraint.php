<?php declare(strict_types=1);

/**
 * Pin version ordering and constraint matching, including pre-releases.
 * A pre-release sorts below its release, so resolvers prefer `0.3.0`
 * over `0.3.0-rc.1`, and a floor below the release excludes the pre-release.
 * Ordering is not gating: a pre-release of the upper bound remains in range
 * through ordinary comparison, not a separate rule.
 */

$root = __DIR__;
while (! \is_file($root . '/src/registry/constraint.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/registry/constraint.php';

use function Moggi\Registry\compareVersions;
use function Moggi\Registry\newestFirst;
use function Moggi\Registry\satisfies;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};
$order = static function (string $a, string $b, int $expected) use ($assert): void {
    $assert(compareVersions($a, $b) === $expected, "compareVersions({$a}, {$b}) must be {$expected}");
    $assert(compareVersions($b, $a) === -$expected, "compareVersions({$b}, {$a}) must be " . -$expected);
};
$order('0.3', '0.3.0', 0);
$order('1', '1.0.0', 0);
$order('0.9', '0.10', -1);
$order('1.2.3', '1.2.4', -1);
$order('0.3.0-alpha', '0.3.0', -1);
$order('1.0.0-rc.1', '1.0.0', -1);
$assert(newestFirst('0.3.0', '0.3.0-alpha') < 0, 'newest-first puts the release before its pre-release');
$order('1.0.0-alpha', '1.0.0-beta', -1);
$order('1.0.0-1', '1.0.0-alpha', -1);       // numeric is below alphanumeric
$order('1.0.0-alpha.2', '1.0.0-alpha.10', -1);
$order('1.0.0+build.1', '1.0.0+build.2', 0);
$order('1.0.0-alpha+001', '1.0.0-alpha', 0);
$assert(! satisfies('0.3.0-rc.1', '0.3'), 'a caret floor does not match its pre-release');
$assert(satisfies('0.3.0-rc.1', '>=0.3.0-rc.1'), 'a range that names the pre-release matches it');
$assert(satisfies('0.3.0', '0.3'), 'the release still matches the caret floor');
$assert(satisfies('0.4.0-beta', '>=0.3.0 <0.4.0'), 'a pre-release of the exclusive bound orders inside the range');
$assert(satisfies('0.3.0-beta', '>=0.3.0-alpha <0.4.0'), 'a pre-release inside the range is in');

echo "constraint tests passed ({$checks} checks)\n";
