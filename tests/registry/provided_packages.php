#!/usr/bin/env php
<?php declare(strict_types=1);

// `base` is the standard library and travels with the compiler, so a descriptor
// that depends on it names a toolchain floor rather than a download. These checks
// cover the two halves of that: a requirement the bundled version meets resolves
// with no catalog read at all, and one it does not meet is refused with the
// chains that asked for it — while `base` stays absent from the resolver's view of
// a catalog it is never fetched from.

$root = __DIR__;
while (!is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Compiler\stdlibVersion;
use function Moggi\Registry\providedDependencyProblems;
use function Moggi\Registry\providedEntry;
use function Moggi\Registry\providedPackages;
use function Moggi\Registry\resolveWith;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

/** Build an `entry()` from `name => [version => deps]`; an unknown name is null. */
$entryFor = static function (array $packages): callable {
    return static function (string $name) use ($packages): ?array {
        if (!isset($packages[$name])) {
            return null;
        }
        $versions = [];
        foreach ($packages[$name] as $version => $deps) {
            $versions[$version] = ['deps' => $deps];
        }

        return ['versions' => $versions];
    };
};

// --- what the compiler carries ----------------------------------------------
$provided = providedPackages();
$assert($provided['base'] === stdlibVersion(), 'the provided base is the bundled stdlib version: ' . \var_export($provided, true));
$assert(\array_keys($provided) === ['base'], 'base is the only provided package');

// --- a requirement the bundled version meets --------------------------------
$assert(providedDependencyProblems(['base' => '>=' . $provided['base']]) === [], 'a floor the compiler meets is no finding');
$assert(providedDependencyProblems(['base' => '<9.0.0']) === [], 'a ceiling the compiler meets is no finding');
$assert(providedDependencyProblems(['other' => '>=1.0.0']) === [], 'a package the compiler does not provide is not checked here');

// --- a requirement the bundled version does not meet ------------------------
$problems = providedDependencyProblems(['base' => '>=9.0.0']);
$assert(\count($problems) === 1, 'a floor the compiler misses is one finding: ' . \json_encode($problems));
$assert(\str_contains($problems[0], 'this compiler carries base ' . $provided['base']), 'the finding names the bundled version: ' . $problems[0]);
$assert(\str_contains($problems[0], 'install a compiler that bundles a matching `base`'), 'the finding points at the toolchain: ' . $problems[0]);
$assert(\str_contains($problems[0], 'root -> base requires >=9.0.0'), 'the finding names the chain and the constraint: ' . $problems[0]);

// --- providedEntry answers for a provided name, delegates for any other ------
$entry = $entryFor(['app' => ['1.0.0' => []]]);
$wrapped = providedEntry($entry, ['base' => '0.2.0']);
$assert($wrapped('base') === ['versions' => ['0.2.0' => ['deps' => []]]], 'a provided name answers with exactly the bundled version');
$assert($wrapped('app') === $entry('app'), 'any other name is read through to the catalog');
$assert($wrapped('absent') === null, 'an unknown name still reads as unknown');
$assert(providedEntry($entry, []) === $entry, 'with nothing provided the entry is passed through untouched');

// --- a resolution that never reads a catalog for base ------------------------
// The catalog has no `base` entry at all: only the provided set can answer it.
$result = resolveWith($entryFor(['app' => ['1.0.0' => ['base' => '>=0.1.0']]]), ['app' => '>=1'], 'root', $provided);
$assert($result['ok'], 'a dependency on base resolves without a catalog entry: ' . ($result['error'] ?? ''));
$assert($result['chosen']['base'] === $provided['base'], 'the chosen base is the bundled one: ' . \var_export($result['chosen'], true));

// --- a transitive requirement the bundled version cannot meet ---------------
$tooOld = resolveWith($entryFor(['app' => ['1.0.0' => ['base' => '>=9.0.0']]]), ['app' => '>=1'], 'root', $provided);
$assert(!$tooOld['ok'], 'a transitive floor the compiler misses fails the resolution');
$assert(\str_contains((string) $tooOld['error'], 'travels with the compiler'), 'the failure is the toolchain refusal: ' . $tooOld['error']);
$assert(\str_contains((string) $tooOld['error'], 'app -> base requires >=9.0.0'), 'the failure names the chain that asked: ' . $tooOld['error']);

// --- a root requirement the bundled version cannot meet ---------------------
$rootTooOld = resolveWith($entryFor([]), ['base' => '>=9.0.0'], 'myapp', $provided);
$assert(!$rootTooOld['ok'], 'a root floor the compiler misses fails the resolution');
$assert(\str_contains((string) $rootTooOld['error'], 'myapp -> base requires >=9.0.0'), 'the failure names the root chain: ' . $rootTooOld['error']);

echo "provided package tests passed ({$checks} checks)\n";
