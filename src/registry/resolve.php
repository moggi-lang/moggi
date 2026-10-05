<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * Dependency resolution, from the catalog alone — `resolver-sketch.md`.
 *
 * The input is the root descriptor's `[dependencies]` and the catalog; the output
 * is one version per package, or an error that names *why* every version in play
 * was demanded. Nothing here fetches a package file or a blob: versions and
 * constraints are in the catalog shard, and that is all a resolver reads. A name
 * met for the first time pulls in its shard — `Catalog::entry()` — so a
 * resolution reads only the shards its dependencies live in.
 *
 * Two passes. The **greedy walk** decides the package with the fewest surviving
 * candidates first, newest version first, and does not revisit a choice; on a
 * shallow tree it is the whole answer, with an error message that names the chains
 * in play. Only when it dead-ends does the **CDCL** solver run (`cdcl.php`), which
 * can backjump over a version choice the greedy walk cannot undo — the case where
 * a dependency's newest release names a package that does not exist while an older
 * release would resolve. When neither can satisfy the request, the greedy error is
 * what a human reads, because it names the constraints rather than a clause.
 */

/**
 * @param array<string, string> $dependencies name => constraint, from the descriptor
 * @return array{ok: bool, chosen: array<string, string>, error: ?string}
 */
function resolveDependencies(array $dependencies, Catalog $catalog, string $root = 'root'): array
{
    return resolveWith(static fn (string $name): ?array => $catalog->entry($name), $dependencies, $root);
}

/**
 * Resolve against any `entry()` — the catalog in production, an in-memory map in a
 * test — so the solver can be exercised without a registry on disk.
 *
 * @param callable(string): ?array<string, mixed> $entry
 * @param array<string, string> $dependencies
 * @return array{ok: bool, chosen: array<string, string>, error: ?string}
 */
function resolveWith(callable $entry, array $dependencies, string $root = 'root'): array
{
    $resolution = resolveWithDetails($entry, $dependencies, $root);

    return ['ok' => $resolution['ok'], 'chosen' => $resolution['chosen'], 'error' => $resolution['error']];
}

/**
 * Resolve, and keep the demand graph that explains the answer.
 *
 * `resolveWith` throws the reasons away the moment a version is chosen; a caller
 * that has to explain *why* a version is in the tree (`moggi why`) or what is
 * holding one back (`moggi outdated`) needs them. The greedy walk builds exactly
 * this already — one entry per constraint with the chain that introduced it — so
 * this exposes it rather than resolving a second time.
 *
 * A resolution the CDCL pass rescued has the greedy walk's reasons only as far as
 * it got: the choices are exact, the explanation is what the walk had seen.
 *
 * @param callable(string): ?array<string, mixed> $entry
 * @param array<string, string> $dependencies
 * @return array{ok: bool, chosen: array<string, string>, error: ?string, reasons: array<string, list<array{constraint: string, path: string}>>}
 */
function resolveWithDetails(callable $entry, array $dependencies, string $root = 'root'): array
{
    $demands = [];
    foreach ($dependencies as $name => $constraint) {
        $demands[$name] = [['constraint' => (string) $constraint, 'path' => "{$root} -> {$name}"]];
    }

    $greedy = greedySolve($entry, $demands);
    if ($greedy['ok']) {
        return $greedy;
    }

    $chosen = cdclResolve($entry, $dependencies);
    if ($chosen !== null) {
        return ['ok' => true, 'chosen' => $chosen, 'error' => null, 'reasons' => $greedy['reasons']];
    }

    return $greedy;
}

/**
 * The fast path: pick the most-constrained undecided package, take its newest
 * version that satisfies every constraint so far, add what that version requires,
 * and go again. A constraint that arrives later for an already-chosen package is
 * re-checked; a violation ends the walk rather than re-deciding, which is what
 * hands the problem to CDCL.
 *
 * @param callable(string): ?array<string, mixed> $entry
 * @param array<string, list<array{constraint: string, path: string}>> $demands
 * @return array{ok: bool, chosen: array<string, string>, error: ?string, reasons: array<string, list<array{constraint: string, path: string}>>}
 */
function greedySolve(callable $entry, array $demands): array
{
    $chosen = [];

    while (true) {
        foreach ($chosen as $name => $version) {
            if (!satisfiesAll($version, $demands[$name] ?? [])) {
                return ['ok' => false, 'chosen' => $chosen, 'error' => conflictMessage($name, (array) $entry($name), $demands[$name] ?? []), 'reasons' => $demands];
            }
        }

        $name = mostConstrained($entry, $demands, $chosen);
        if ($name === null) {
            \ksort($chosen);

            return ['ok' => true, 'chosen' => $chosen, 'error' => null, 'reasons' => $demands];
        }

        $record = $entry($name);
        if ($record === null) {
            return ['ok' => false, 'chosen' => $chosen, 'error' => unknownPackage($name, $demands[$name]), 'reasons' => $demands];
        }
        $candidates = candidatesFor($record['versions'] ?? [], $demands[$name]);
        if ($candidates === []) {
            return ['ok' => false, 'chosen' => $chosen, 'error' => conflictMessage($name, (array) $record, $demands[$name]), 'reasons' => $demands];
        }

        $version = $candidates[0];
        $chosen[$name] = $version;
        $base = shortestPath($demands[$name]);
        foreach ((array) ($record['versions'][$version]['deps'] ?? []) as $dependency => $constraint) {
            $demands[$dependency][] = ['constraint' => (string) $constraint, 'path' => "{$base} -> {$dependency}"];
        }
    }
}

/**
 * The undecided package with the fewest surviving candidates, lexicographic as a
 * tie-break so a resolution is reproducible.
 *
 * @param callable(string): ?array<string, mixed> $entry
 * @param array<string, list<array{constraint: string, path: string}>> $demands
 * @param array<string, string> $chosen
 */
function mostConstrained(callable $entry, array $demands, array $chosen): ?string
{
    $best = null;
    $bestCount = PHP_INT_MAX;
    $names = \array_diff(\array_keys($demands), \array_keys($chosen));
    \sort($names);

    foreach ($names as $name) {
        $record = $entry($name);
        $count = \count(candidatesFor((array) (($record ?? [])['versions'] ?? []), $demands[$name]));
        if ($count < $bestCount) {
            $best = $name;
            $bestCount = $count;
        }
    }

    return $best;
}

/**
 * Every version of one package that satisfies every constraint on it, newest
 * first.
 *
 * @param array<string, mixed> $versions the catalog entry's `versions` map
 * @param list<array{constraint: string, path: string}> $reasons
 * @return list<string>
 */
function candidatesFor(array $versions, array $reasons): array
{
    $candidates = [];
    foreach ($versions as $version => $_record) {
        if (satisfiesAll((string) $version, $reasons)) {
            $candidates[] = (string) $version;
        }
    }
    \usort($candidates, newestFirst(...));

    return $candidates;
}

/**
 * @param list<array{constraint: string, path: string}> $reasons
 */
function satisfiesAll(string $version, array $reasons): bool
{
    foreach ($reasons as $reason) {
        if (!satisfies($version, $reason['constraint'])) {
            return false;
        }
    }

    return true;
}

/**
 * The shortest chain that demanded a package — what the reader of a conflict
 * message should be shown, since the shortest path is the least surprising one.
 *
 * @param list<array{constraint: string, path: string}> $reasons
 */
function shortestPath(array $reasons): string
{
    $paths = \array_map(static fn (array $reason): string => $reason['path'], $reasons);
    \usort($paths, static fn (string $a, string $b): int => \strlen($a) <=> \strlen($b) ?: \strcmp($a, $b));

    return $paths[0] ?? 'root';
}

/**
 * "Cannot satisfy" is why `demands` records *why* each constraint exists: two
 * dependencies with disjoint majors have to see both chains, not just one.
 *
 * @param array<string, mixed> $entry
 * @param list<array{constraint: string, path: string}> $reasons
 */
function conflictMessage(string $name, array $entry, array $reasons): string
{
    $lines = ["cannot satisfy `{$name}`"];
    foreach ($reasons as $reason) {
        $lines[] = '  ' . $reason['path'] . '  ' . $reason['constraint'];
    }
    $available = \array_keys((array) ($entry['versions'] ?? []));
    \usort($available, newestFirst(...));
    $lines[] = $available === []
        ? '  no versions published'
        : '  available: ' . \implode(', ', $available);

    return \implode("\n", $lines);
}

/**
 * @param list<array{constraint: string, path: string}> $reasons
 */
function unknownPackage(string $name, array $reasons): string
{
    $lines = ["unknown package `{$name}`"];
    foreach ($reasons as $reason) {
        $lines[] = '  ' . $reason['path'] . '  ' . $reason['constraint'];
    }

    return \implode("\n", $lines);
}
