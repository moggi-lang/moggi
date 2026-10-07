<?php declare(strict_types=1);

namespace Moggi\Registry;

use function Moggi\Compiler\stdlibVersion;

/**
 * Packages the compiler provides, rather than fetching.
 *
 * `base` is the standard library and travels with the compiler, so a descriptor
 * that depends on it is naming the toolchain it needs, not asking for a download.
 * The resolver never reads it from a catalog, no lock ever names it, and an
 * install never fetches it: the requirement is checked against the version this
 * compiler actually carries, and a mismatch is a build error rather than a
 * registry lookup. `base` is still published like any other package, because a
 * release is what serves its documentation.
 */

/**
 * The packages this compiler provides, name => the version it carries.
 *
 * Empty when the bundled standard library cannot be found, since there is then no
 * version a requirement could be checked against.
 *
 * @return array<string, string>
 */
function providedPackages(): array
{
    $version = stdlibVersion();

    return $version === null ? [] : ['base' => $version];
}

/**
 * The resolver's view of the provided packages: a provided name answers with
 * exactly one version — the one this compiler carries — and requires nothing
 * itself.
 *
 * Wrapping the entry keeps provided packages out of every catalog read, so no
 * resolution downloads one however deep the request sits.
 *
 * @param callable(string): ?array<string, mixed> $entry
 * @param array<string, string> $provided
 * @return callable(string): ?array<string, mixed>
 */
function providedEntry(callable $entry, array $provided): callable
{
    if ($provided === []) {
        return $entry;
    }

    return static function (string $name) use ($entry, $provided): ?array {
        if (isset($provided[$name])) {
            return ['versions' => [$provided[$name] => ['deps' => []]]];
        }

        return $entry($name);
    };
}

/**
 * The provided-package requirements a dependency map declares that the bundled
 * version does not satisfy.
 *
 * A provided package is not fetched, so its constraint is answered here: the
 * requirement names a toolchain floor, and a floor this compiler does not meet is
 * an error rather than something a registry could supply.
 *
 * @param array<string, string> $dependencies
 * @return list<string>
 */
function providedDependencyProblems(array $dependencies): array
{
    $provided = providedPackages();
    $problems = [];
    foreach ($dependencies as $name => $constraint) {
        $name = (string) $name;
        $version = $provided[$name] ?? null;
        if ($version === null || satisfies($version, (string) $constraint)) {
            continue;
        }
        $problems[] = providedRequirementMessage($name, $version, [
            ['constraint' => (string) $constraint, 'path' => "root -> {$name}"],
        ]);
    }

    return $problems;
}

/**
 * The error a failed resolution deserves when a provided package is what could
 * not be satisfied, or null when the failure is an ordinary dependency conflict.
 *
 * The greedy walk records every constraint with the chain that introduced it, so
 * the demand a dependency placed on the toolchain can be named even when it is
 * several packages deep.
 *
 * @param array<string, list<array{constraint: string, path: string}>> $reasons
 * @param array<string, string> $provided
 */
function providedRequirementFailure(array $reasons, array $provided): ?string
{
    foreach ($provided as $name => $version) {
        $unmet = \array_values(\array_filter(
            $reasons[$name] ?? [],
            static fn (array $demand): bool => !satisfies($version, $demand['constraint']),
        ));
        if ($unmet !== []) {
            return providedRequirementMessage($name, $version, $unmet);
        }
    }

    return null;
}

/**
 * One provided-package refusal, naming every chain that asked for it and what it
 * asked for. One line, so a `check` finding and a resolver error read the same.
 *
 * @param list<array{constraint: string, path: string}> $demands
 */
function providedRequirementMessage(string $name, string $version, array $demands): string
{
    $required = \array_map(
        static fn (array $demand): string => $demand['path'] . ' requires ' . $demand['constraint'],
        $demands,
    );

    return "`{$name}` travels with the compiler rather than the registry: this compiler carries "
        . "{$name} {$version}, but " . \implode('; ', $required)
        . " — install a compiler that bundles a matching `{$name}`";
}
