<?php declare(strict_types=1);

/**
 * Exercise the resolver against an in-memory catalog, without a registry.
 * The greedy walk handles the common case; CDCL fallback recovers from
 * dead ends it cannot undo, such as a newest release with a missing
 * dependency.
 *
 * Human-readable errors still come from the greedy walk, preserving the
 * relevant dependency chains rather than exposing learned clauses.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Registry\cdclResolve;
use function Moggi\Registry\resolveWith;
use function Moggi\Registry\satisfies;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

/** Build an `entry()` from `name => [version => deps]`, wrapping each deps map
 * the way a catalog entry carries it. */
$entryFor = static function (array $packages): callable {
    return static function (string $name) use ($packages): ?array {
        if (! isset($packages[$name])) {
            return null;
        }
        $versions = [];
        foreach ($packages[$name] as $version => $deps) {
            $versions[$version] = ['deps' => $deps];
        }

        return ['versions' => $versions];
    };
};

// --- the common case: newest first, no backtracking needed -------------------
$catalog = $entryFor([
    'a' => ['1.0.0' => [], '1.1.0' => []],
    'b' => ['1.0.0' => ['c' => '>=1.0.0']],
    'c' => ['1.0.0' => [], '2.0.0' => []],
]);
$result = resolveWith($catalog, ['a' => '>=1', 'b' => '>=1']);
$assert($result['ok'], 'a diamond resolves: ' . ($result['error'] ?? ''));
$assert($result['chosen'] === ['a' => '1.1.0', 'b' => '1.0.0', 'c' => '2.0.0'], 'newest versions win: ' . \var_export($result['chosen'], true));

// --- a dead end the greedy walk cannot undo, rescued by CDCL -----------------
// `a`'s newest release requires a package that does not exist; its older release
// resolves. Greedy commits to the newest and stops, so only CDCL finds this.
$backtrack = $entryFor([
    'a' => [
        '2.0.0' => ['missing' => '=2.0.0'],
        '1.0.0' => [],
    ],
    'b' => ['1.0.0' => []],
]);
$result = resolveWith($backtrack, ['a' => '>=1', 'b' => '>=1']);
$assert($result['ok'], 'CDCL recovers from a new-version dead end: ' . ($result['error'] ?? ''));
$assert($result['chosen'] === ['a' => '1.0.0', 'b' => '1.0.0'], 'CDCL falls back to the resolvable release: ' . \var_export($result['chosen'], true));

// --- a genuine conflict is still a named error -------------------------------
$conflict = $entryFor([
    'a' => ['1.0.0' => ['c' => '=1.0.0']],
    'b' => ['1.0.0' => ['c' => '=2.0.0']],
    'c' => ['1.0.0' => [], '2.0.0' => []],
]);
$result = resolveWith($conflict, ['a' => '>=1', 'b' => '>=1']);
$assert(! $result['ok'], 'two disjoint constraints on c do not resolve');
$assert(\str_contains($result['error'] ?? '', 'cannot satisfy `c`'), 'the error names the conflicted package: ' . ($result['error'] ?? ''));
$assert(\str_contains($result['error'] ?? '', 'a -> c') && \str_contains($result['error'] ?? '', 'b -> c'), 'the error names both demanding chains: ' . ($result['error'] ?? ''));
$assert(cdclResolve($conflict, ['a' => '>=1', 'b' => '>=1']) === null, 'the CDCL core agrees the request is unsatisfiable');

$result = resolveWith($entryFor(['a' => ['1.0.0' => []]]), ['nope' => '>=1']);
$assert(! $result['ok'] && \str_contains($result['error'] ?? '', 'unknown package `nope`'), 'an unknown root package is named: ' . ($result['error'] ?? ''));

$result = resolveWith(
    $entryFor(['a' => ['1.0.0-alpha' => [], '1.0.0' => []]]),
    ['a' => '>=1.0.0-alpha'],
);
$assert($result['ok'] && $result['chosen'] === ['a' => '1.0.0'], 'a release outranks its pre-release: ' . \var_export($result['chosen'] ?? null, true));
$assert(
    ! resolveWith($entryFor(['a' => ['1.0.0-alpha' => []]]), ['a' => '>=1.0.0'])['ok'],
    'a floor the pre-release sits below excludes it',
);

$result = resolveWith($entryFor(['a' => ['1.0.0' => ['a' => '>=1.0.0']]]), ['a' => '>=1']);
$assert($result['ok'] && $result['chosen'] === ['a' => '1.0.0'], 'a package depending on itself resolves once');

$valid = static function (array $chosen, array $packages, array $rootDeps): bool {
    foreach ($rootDeps as $name => $constraint) {
        if (! isset($chosen[$name]) || ! satisfies($chosen[$name], $constraint)) {
            return false;
        }
    }
    $stack = \array_keys($rootDeps);
    $seen = [];
    while ($stack !== []) {
        $name = (string) \array_pop($stack);
        if (isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        if (! isset($chosen[$name], $packages[$name][$chosen[$name]])) {
            return false;
        }
        foreach ((array) $packages[$name][$chosen[$name]] as $dependency => $constraint) {
            if (! isset($chosen[$dependency]) || ! satisfies($chosen[$dependency], (string) $constraint)) {
                return false;
            }
            $stack[] = (string) $dependency;
        }
    }

    return true;
};

\mt_srand(20261002);
$pool = ['1.0.0', '2.0.0', '3.0.0'];
$ranges = ['>=1.0.0', '=1.0.0', '=2.0.0', '=3.0.0', '>=2.0.0', '>=1 <3'];
for ($case = 0; $case < 200; $case++) {
    $count = \mt_rand(2, 4);
    $names = [];
    for ($i = 0; $i < $count; $i++) {
        $names[] = 'p' . $i;
    }

    $packages = [];
    foreach ($names as $name) {
        $versions = (array) \array_slice($pool, 0, \mt_rand(1, 3));
        foreach ($versions as $version) {
            $deps = [];
            foreach ($names as $other) {
                if (\mt_rand(0, 3) === 0) {
                    $deps[$other] = $ranges[\array_rand($ranges)];
                }
            }
            $packages[$name][$version] = $deps;
        }
    }

    $rootDeps = [$names[0] => $ranges[\array_rand($ranges)]];
    if ($count > 1 && \mt_rand(0, 1) === 1) {
        $rootDeps[$names[1]] = $ranges[\array_rand($ranges)];
    }

    // Brute force: does any assignment of the names survive the closure check?
    $oracle = false;
    $walk = static function (int $at, array $assign) use (&$walk, &$oracle, $names, $packages, $valid, $rootDeps): void {
        if ($oracle) {
            return;
        }
        if ($at === \count($names)) {
            $oracle = $valid($assign, $packages, $rootDeps);

            return;
        }
        foreach (\array_keys($packages[$names[$at]]) as $version) {
            $walk($at + 1, $assign + [$names[$at] => $version]);
        }
    };
    $walk(0, []);

    $result = resolveWith($entryFor($packages), $rootDeps);
    if ($result['ok']) {
        $assert($valid($result['chosen'], $packages, $rootDeps), "case {$case}: a returned choice must satisfy every constraint\n" . \json_encode([$packages, $rootDeps, $result['chosen']], \JSON_PRETTY_PRINT));
    } else {
        $assert(! $oracle, "case {$case}: the resolver refused a request that has a solution");
    }
    $assert($result['ok'] === $oracle, "case {$case}: the resolver disagrees with the oracle");
}

echo "resolver tests passed ({$checks} checks)\n";
