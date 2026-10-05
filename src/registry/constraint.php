<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * Version constraints, in the syntax a descriptor's `[dependencies]` uses:
 *
 *     1.2          caret floor — `>=1.2 <2`
 *     =1.2         that version, exactly
 *     >=1.2 <2     a range, spelled out
 *
 * A constraint is matched against a version string, so a resolver never needs a
 * package's descriptor to interpret one.
 *
 * Pre-release ordering follows semver: a pre-release sorts *below* the release
 * it precedes, so `0.3.0-alpha < 0.3.0` and the release wins a newest-first
 * candidate list.
 */

/**
 * Version order, semver-style.
 *
 * Numerical components compare component by component: `0.9 < 0.10`, and `1`
 * equals `1.0.0` because a missing component is zero. A pre-release sorts below
 * the release it precedes; two pre-releases compare identifier by identifier
 * (numeric identifiers numerically and below alphanumeric ones, alphanumeric
 * ones byte-wise, a longer set winning when the shared prefix is equal). Build
 * metadata after `+` is ignored, as semver says.
 */
function compareVersions(string $a, string $b): int
{
    [$aCore, $aPre] = versionParts($a);
    [$bCore, $bPre] = versionParts($b);
    for ($i = 0, $n = \max(\count($aCore), \count($bCore)); $i < $n; $i++) {
        $difference = ($aCore[$i] ?? 0) <=> ($bCore[$i] ?? 0);
        if ($difference !== 0) {
            return $difference;
        }
    }

    if ($aPre === [] || $bPre === []) {
        return $aPre === $bPre ? 0 : ($aPre === [] ? 1 : -1);
    }

    return comparePrereleaseIdentifiers($aPre, $bPre);
}

/**
 * @return array{0: list<int>, 1: list<string>} numeric core, pre-release identifiers
 */
function versionParts(string $version): array
{
    $version = \trim($version);
    $plus = \strpos($version, '+');
    if ($plus !== false) {
        $version = \substr($version, 0, $plus);
    }

    $prerelease = [];
    $dash = \strpos($version, '-');
    if ($dash !== false) {
        $prerelease = \array_values(\array_filter(
            \explode('.', \substr($version, $dash + 1)),
            static fn (string $part): bool => $part !== '',
        ));
        $version = \substr($version, 0, $dash);
    }

    $core = \array_map(
        static fn (string $part): int => (int) $part,
        \explode('.', $version),
    );

    return [$core, $prerelease];
}

/**
 * @param list<string> $a
 * @param list<string> $b
 */
function comparePrereleaseIdentifiers(array $a, array $b): int
{
    for ($i = 0, $n = \max(\count($a), \count($b)); $i < $n; $i++) {
        if (!isset($a[$i])) {
            return -1;
        }
        if (!isset($b[$i])) {
            return 1;
        }
        $difference = comparePrereleaseIdentifier($a[$i], $b[$i]);
        if ($difference !== 0) {
            return $difference;
        }
    }

    return 0;
}

function comparePrereleaseIdentifier(string $a, string $b): int
{
    $aNumeric = \preg_match('/^[0-9]+$/', $a) === 1;
    $bNumeric = \preg_match('/^[0-9]+$/', $b) === 1;
    if ($aNumeric && $bNumeric) {
        return (int) $a <=> (int) $b;
    }
    if ($aNumeric !== $bNumeric) {
        return $aNumeric ? -1 : 1;
    }

    return \strcmp($a, $b);
}

/**
 * Why a constraint is not one this syntax accepts, or null when it is.
 *
 * `satisfies()` answers "no" to a typo just as quickly as to a version that is
 * genuinely too old, and a resolver has no reason to tell them apart. A linter
 * does, so the grammar is also available as a question.
 */
function constraintProblem(string $constraint): ?string
{
    foreach (\preg_split('/\s+/', \trim($constraint)) ?: [] as $clause) {
        if ($clause === '') {
            continue;
        }
        if (\preg_match('/^(>=|<=|>|<|==|=)?\s*(\d[\w.\-+]*)$/', $clause) !== 1) {
            return "`{$clause}` is not a version or a comparison";
        }
    }

    return null;
}

/** Whether one version satisfies every clause of a constraint. */
function satisfies(string $version, string $constraint): bool
{
    if (constraintProblem($constraint) !== null) {
        return false;
    }

    foreach (\preg_split('/\s+/', \trim($constraint)) ?: [] as $clause) {
        if ($clause === '') {
            continue;
        }
        \preg_match('/^(>=|<=|>|<|==|=)?\s*(\d[\w.\-+]*)$/', $clause, $match);
        $operator = $match[1];
        $bound = $match[2];
        $order = compareVersions($version, $bound);
        $satisfied = match ($operator) {
            '=', '==' => $order === 0,
            '>=' => $order >= 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            '<' => $order < 0,
            default => $order >= 0 && compareVersions($version, (string) (((int) \explode('.', $bound)[0]) + 1)) < 0,
        };
        if (!$satisfied) {
            return false;
        }
    }

    return true;
}

/** Newest first, the order every candidate list is built in. */
function newestFirst(string $a, string $b): int
{
    return compareVersions($b, $a);
}
