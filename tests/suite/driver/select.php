<?php declare(strict_types=1);

/**
 * Selection: which discovered cases run on a backend, in what order, and why one does not (group
 * filters, path/name selectors).
 */

/** The tree positional selectors resolve against. */
function testPathFilterBase(): string
{
    return \Moggi\Test\PathFilter\base();
}

/** @param list<string> $paths @return list<array{path: string, directory: bool}> */
function normalizeTestPaths(array $paths): array
{
    return \Moggi\Test\PathFilter\normalize($paths, testPathFilterBase());
}

/** @param list<array{path: string, directory: bool}> $filters */
function matchesTestPathFilter(string $path, array $filters): bool
{
    return \Moggi\Test\PathFilter\matches($path, $filters, testPathFilterBase());
}

/**
 * @param list<TestCase> $cases
 * @param list<array{path: string, directory: bool}> $pathFilters
 * @param list<string> $groupFilters
 * @return list<TestCase>
 */
function selectCases(array $cases, array $pathFilters, array $groupFilters, string $backend): array
{
    global $testArgs;

    $selected = [];
    foreach ($cases as $case) {
        if ($groupFilters !== [] && !matchesAnyGroupFilter($case->name, $groupFilters)) {
            continue;
        }
        if ($pathFilters !== [] && !matchesAnyPathFilter($case, $pathFilters)) {
            continue;
        }
        // A case about another backend is selected anyway, so it is reported `n/a` rather than
        // vanishing from this backend's run.
        $selected[] = $case;
    }

    \usort($selected, static fn (TestCase $a, TestCase $b): int => [$a->group, $a->name] <=> [$b->group, $b->name]);

    return $selected;
}

/**
 * A `--group` filter is a group (`semantics`) or any subject path below one (`syntax/parser`); a case
 * name always begins with its group, so one prefix test covers both.
 *
 * @param list<string> $groupFilters
 */
function matchesAnyGroupFilter(string $name, array $groupFilters): bool
{
    foreach ($groupFilters as $filter) {
        if ($name === $filter || \str_starts_with($name, $filter . '/')) {
            return true;
        }
    }

    return false;
}

/** @param list<array{path: string, directory: bool}> $pathFilters */
function matchesAnyPathFilter(TestCase $case, array $pathFilters): bool
{
    foreach ($case->files as $file) {
        if (matchesTestPathFilter($file, $pathFilters)) {
            return true;
        }
    }

    // Logical-name selectors (`parser/Backend-Map`, `lsp/handlers`).
    foreach ($pathFilters as $filter) {
        if ($filter['path'] === '') {
            return true;
        }
        if ($case->name === $filter['path']
            || \str_starts_with($case->name, \rtrim($filter['path'], '/') . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * Why a case will not run here. The only answer is **not applicable** — a skip would claim something
 * was deferred, which nothing was.
 *
 * @return ?array{applicable: bool, reason: string}
 */
function caseSkipReason(TestCase $case, string $backend): ?array
{
    if ($case->runsOn($backend)) {
        return null;
    }

    return ['applicable' => false, 'reason' => 'case is about ' . \implode(', ', $case->caseBackends())];
}

/**
 * Turn positional selectors into filters. A selector is a **path** (file or directory subtree) or a
 * **logical test name** (`parser/Backend-Map`, `lsp/handlers`), which is what makes `runtest
 * parser/where` work without knowing whether the case is a fixture or a script. Anything else is an
 * error: a typo must not silently select nothing, or worse, everything.
 *
 * @param list<string> $selectors
 * @param list<TestCase> $cases
 * @return list<array{path: string, directory: bool}>
 */
function resolveTestSelectors(array $selectors, array $cases): array
{
    if ($selectors === []) {
        return [];
    }

    $names = [];
    foreach ($cases as $case) {
        $names[$case->name] = true;
    }

    $filters = [];
    foreach ($selectors as $selector) {
        try {
            foreach (normalizeTestPaths([$selector]) as $filter) {
                $filters[] = $filter;
            }
            continue;
        } catch (\InvalidArgumentException) {
            // Not a path on disk; it may name a test instead.
        }

        $trimmed = \trim($selector, '/');
        $known = false;
        foreach (\array_keys($names) as $name) {
            if ($name === $trimmed || \str_starts_with($name, $trimmed . '/')) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            throw new \InvalidArgumentException(
                "no test path or name matches `{$selector}` (try `--list`)",
            );
        }
        $filters[] = ['path' => $trimmed, 'directory' => false];
    }

    return $filters;
}
