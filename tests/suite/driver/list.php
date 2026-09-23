<?php declare(strict_types=1);

/**
 * `--list`: what the suite contains, and what each backend would actually run (output only —
 * discovery and selection live in `run.php`).
 */

/**
 * @param list<TestCase> $cases
 * @param list<string> $backends
 * @param list<array{path: string, directory: bool}> $pathFilters
 * @param list<string> $groupFilters
 */
function renderTestList(array $cases, array $backends, array $pathFilters, array $groupFilters): int
{
    global $testArgs;

    $entries = [];
    foreach ($cases as $case) {
        if ($groupFilters !== [] && !matchesAnyGroupFilter($case->name, $groupFilters)) {
            continue;
        }
        if ($pathFilters !== [] && !matchesAnyPathFilter($case, $pathFilters)) {
            continue;
        }
        $entries[] = [
            'name' => $case->name,
            'group' => $case->group,
            // The *case*'s backends, not this run's, so the list reads the same under any `--backend`.
            'backends' => $case->caseBackends(),
            'files' => \array_map(testRelativePath(...), $case->files),
            'runsOn' => backendMatrixForCase($case),
        ];
    }

    if ($entries === []) {
        \fwrite(STDERR, "error: no tests match the selection\n");

        return 2;
    }

    $matrix = listBackendMatrix($cases, $pathFilters, $groupFilters);

    if ($testArgs['json']) {
        echo \json_encode(
            ['schema' => 1, 'count' => \count($entries), 'matrix' => $matrix, 'tests' => $entries],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";

        return 0;
    }

    $group = null;
    foreach ($entries as $entry) {
        if ($entry['group'] !== $group) {
            $group = $entry['group'];
            echo "\n{$group}\n";
        }
        $backendNote = \count($entry['backends']) === \count(knownTestBackends())
            ? ''
            : '  (' . \implode(',', $entry['backends']) . ' only)';
        echo '  ' . $entry['name'] . $backendNote . "\n";
    }

    echo \sprintf("\n%d tests\n", \count($entries));
    echo renderBackendMatrix($matrix);

    return 0;
}

/** @return array<string, bool> */
function backendMatrixForCase(TestCase $case): array
{
    $runsOn = [];
    foreach (knownTestBackends() as $backend) {
        $runsOn[$backend] = caseSkipReason($case, $backend) === null;
    }

    return $runsOn;
}

/**
 * @param list<TestCase> $cases
 * @param list<array{path: string, directory: bool}> $pathFilters
 * @param list<string> $groupFilters
 * @return array<string, array{total: int, run: int, skipped: int}>
 */
function listBackendMatrix(array $cases, array $pathFilters, array $groupFilters): array
{
    $matrix = [];
    foreach (knownTestBackends() as $backend) {
        $entry = ['total' => 0, 'run' => 0, 'skipped' => 0];
        foreach (selectCases($cases, $pathFilters, $groupFilters, $backend) as $case) {
            ++$entry['total'];
            $reason = caseSkipReason($case, $backend);
            // Not this backend's case: neither counted nor named. A real skip (missing toolchain) is.
            if ($reason === null) {
                ++$entry['run'];
            } elseif ($reason['applicable']) {
                ++$entry['skipped'];
            }
        }
        $matrix[$backend] = $entry;
    }

    return $matrix;
}

/** @param array<string, array{total: int, run: int, skipped: int}> $matrix */
function renderBackendMatrix(array $matrix): string
{
    $out = "\nper backend\n";
    foreach ($matrix as $backend => $entry) {
        $out .= \sprintf("  %-8s %4d run", $backend, $entry['run']);
        if ($entry['skipped'] > 0) {
            $out .= \sprintf('  %2d skipped', $entry['skipped']);
        }
        $out .= "\n";
    }

    return $out;
}
