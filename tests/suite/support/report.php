<?php declare(strict_types=1);

/**
 * Reporting: the grouped table, the recap, the summary line, JSON output and per-case log files.
 */

/**
 * Remember whether the last terminal line was blank, so two blank lines never stack.
 */
function terminalNotePrinted(string $text): void
{
    $GLOBALS['moggi_last_printed_blank'] = $text === '' || \str_ends_with($text, "\n\n");
}

function terminalLastPrintedBlank(): bool
{
    return ($GLOBALS['moggi_last_printed_blank'] ?? true) === true;
}

/** The `===== PHP =====` banner, with exactly one blank line before it. */
function backendBanner(string $backend): string
{
    $banner = \sprintf('===== %s =====%s', \strtoupper($backend), "\n");
    $lead = terminalLastPrintedBlank() ? '' : "\n";
    terminalNotePrinted($lead . $banner);

    return $lead . $banner;
}

function reportLogDir(): string
{
    return \Moggi\Cache\cacheScratchDir() . '/logs';
}

function safeLogName(string $name): string
{
    return \preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'case';
}

/** Write the full failure text next to the cache dir and return the path. */
function writeCaseLog(string $name, string $message): ?string
{
    $dir = reportLogDir();
    if (!@\mkdir($dir, 0777, true) && !\is_dir($dir)) {
        return null;
    }

    $path = $dir . '/' . safeLogName($name) . '.log';
    $head = 'case: ' . $name . "\n";
    if (@\file_put_contents($path, $head . $message) === false) {
        return null;
    }

    return $path;
}

/** A case's message as printable lines — the whole message, in every block that prints one. */
function messageLines(string $message): array
{
    $trimmed = \rtrim($message, "\n");

    return $trimmed === '' ? [] : \explode("\n", $trimmed);
}

/** Lines of a case's message, each indented for printing under the line that names the case. */
function indentLines(array $lines, string $prefix = '    '): string
{
    return \implode('', \array_map(static fn (string $line): string => $prefix . $line . "\n", $lines));
}

/**
 * The run's first line: how many workers it uses, and whether the reader asked for that number.
 * A serial run that never had a reason to spread says nothing.
 *
 * `$requested` is the `--jobs` value given, or 0 when the count was chosen automatically.
 */
function renderWorkerBanner(int $jobs, int $requested): string
{
    if ($jobs > 1) {
        return $requested > 0
            ? \sprintf("using %d workers (--jobs %d)\n", $jobs, $requested)
            : \sprintf("using %d workers (auto; --jobs 1 to serialise)\n", $jobs);
    }

    // One worker, which is either what was asked for or all the selection could be split into.
    if ($requested > 1) {
        return \sprintf("using 1 worker (--jobs %d: only one shard to give)\n", $requested);
    }

    return $requested === 1 ? "using 1 worker (--jobs 1)\n" : '';
}

function formatDuration(float $ms): string
{
    if ($ms < 1000) {
        return \sprintf('%dms', (int) \round($ms));
    }

    return \sprintf('%.1fs', $ms / 1000);
}

/**
 * How a group's row counts its cases, in the order it reads. Timeouts count as failures here (the
 * recap tells them apart); `lost` and `n/a` do not — nothing ran, so nothing is known about them.
 */
const GROUP_BUCKETS = [
    'passed' => ['pass'],
    'failed' => ['fail', 'timeout'],
    'lost' => ['lost'],
    'skipped' => ['skip'],
    'n/a' => ['not-applicable'],
];

/** @param array<string, int> $counts @param list<string> $statuses */
function bucketCount(array $counts, array $statuses): int
{
    $count = 0;
    foreach ($statuses as $status) {
        $count += $counts[$status] ?? 0;
    }

    return $count;
}

/** @return array<string, list<CaseResult>> cases in selection order, grouped */
function groupResults(ResultSet $set): array
{
    $byGroup = [];
    foreach ($set->results as $result) {
        $byGroup[$result->group][] = $result;
    }

    return $byGroup;
}

/**
 * One group's row: its counts, and no time.
 *
 * The only time a group has is the sum of its cases, and under `--jobs N` those cases run at the
 * same time on different workers, so the sum can exceed the whole run and contradicts the summary
 * line. Per-case times are on the case lines, the per-group wall clock is in the live block, and the
 * run's own time is the summary line's.
 */
function renderGroupRow(string $group, array $results): string
{
    $counts = countStatuses($results);

    $parts = [];
    foreach (GROUP_BUCKETS as $label => $statuses) {
        $count = bucketCount($counts, $statuses);
        if ($count > 0 || $label === 'passed') {
            $parts[] = \sprintf('%d %s', $count, $label);
        }
    }

    return \sprintf("%-34s %s\n", $group, \implode('  ', $parts));
}

/** A group's row followed by the lines of the cases that ran under it (`n/a` gets no line). */
function renderGroup(string $group, array $results): string
{
    $out = renderGroupRow($group, $results);
    foreach ($results as $result) {
        if ($result->status === 'not-applicable') {
            continue;
        }
        $out .= renderCaseLine($result);
    }

    return $out;
}

/**
 * Grouped report: one line per group, then one line per case that ran (`n/a` gets no line). A
 * failure's diagnostic appears once, in the recap.
 */
function renderGroups(ResultSet $set): string
{
    $out = '';
    foreach (groupResults($set) as $group => $results) {
        $out .= renderGroup($group, $results);
    }

    return $out;
}

/** The group rows alone, for closing a backend whose case lines streamed as they finished. */
function renderGroupRows(ResultSet $set): string
{
    $out = '';
    foreach (groupResults($set) as $group => $results) {
        $out .= renderGroupRow($group, $results);
    }

    return $out;
}

/**
 * One case's line under its group; its diagnostic is the recap's job, and a `--native` pass is
 * marked so it is not mistaken for the managed one.
 *
 * `$backend` is named at the end of the line when one run covers several backends.
 */
function renderCaseLine(CaseResult $result, ?string $backend = null): string
{
    $name = $result->native ? $result->name . ' (native)' : $result->name;
    $tag = $backend === null ? '' : '  ' . $backend;

    return match ($result->status) {
        'pass' => \sprintf("  OK      %-44s %8s%s\n", $name, formatDuration($result->durationMs), $tag),
        'skip' => \sprintf("  SKIP    %-44s %s%s\n", $result->name, $result->skipReason ?? '', $tag),
        'lost' => \sprintf("  LOST    %s%s\n", $result->name, $tag),
        'timeout' => \sprintf("  TIMEOUT %s%s\n", $result->name, $tag),
        default => \sprintf("  FAIL    %s%s\n", $result->name, $tag),
    };
}

/**
 * End-of-run recap: every case that is not a clean pass, with its full diagnostic, repro line and
 * log path — so nobody has to scroll back through the run.
 */
function renderRecap(ResultSet $set): string
{
    $failures = $set->withStatus('fail', 'timeout');
    $lost = $set->withStatus('lost');
    $skips = $set->withStatus('skip');

    if ($failures === [] && $lost === [] && $skips === []) {
        return '';
    }

    $out = '';
    if ($failures !== []) {
        $out .= \sprintf("FAILURES (%d)\n\n", \count($failures));
        foreach ($failures as $result) {
            $label = $result->status === 'timeout' ? 'TIMEOUT' : 'FAIL';
            $out .= \sprintf("%s %s\n", $label, $result->name);
            $out .= indentLines(messageLines($result->message), '  ');
            if ($result->repro !== null) {
                $out .= '  repro: ' . $result->repro . "\n";
            }
            if ($result->logPath !== null) {
                $out .= '  log:   ' . $result->logPath . "\n";
            }
            $out .= "\n";
        }
    }

    // One block per dying worker, whose message carries the worker's own last words.
    if ($lost !== []) {
        $out .= \sprintf("LOST CASES (%d)\n", \count($lost));
        foreach ($lost as $result) {
            if ($result->message === '') {
                continue;
            }
            $out .= \sprintf("LOST %s\n", $result->name);
            $out .= indentLines(messageLines($result->message), '  ');
            if ($result->repro !== null) {
                $out .= '  repro: ' . $result->repro . "\n";
            }
            $out .= "\n";
        }
    }

    if ($skips !== []) {
        $out .= \sprintf("SKIPPED (%d)\n", \count($skips));
        foreach ($skips as $result) {
            $out .= \sprintf("SKIP %s — %s\n", $result->name, $result->skipReason ?? 'not applicable');
        }
        $out .= "\n";
    }

    return $out;
}

/**
 * Make a case that kills the process say so: a fatal error cannot be caught, so a serial run would
 * otherwise just stop with nothing naming the test that ended it. (`--jobs` runs report a dead
 * worker as a lost shard instead — see `driver/parallel.php`.)
 */
function installCaseDeathReporter(): void
{
    static $installed = false;
    if ($installed) {
        return;
    }
    $installed = true;

    \register_shutdown_function(static function (): void {
        $case = $GLOBALS['moggi_running_case'] ?? null;
        $error = \error_get_last();
        if ($case === null || $error === null) {
            return;
        }
        if (!\in_array($error['type'], [E_ERROR, E_USER_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        \fwrite(
            STDERR,
            \sprintf("\nthe suite died while running %s\n  %s\n", (string) $case, (string) $error['message']),
        );
    });
}

/** How a backend's non-green counts read in the `--backend all` verdict line. */
function backendVerdict(array $counts): string
{
    if (!countsFailed($counts)) {
        return 'ok';
    }

    $parts = [];
    foreach (['fail', 'timeout', 'lost'] as $status) {
        if (($counts[$status] ?? 0) > 0) {
            $parts[] = $counts[$status] . ' ' . CASE_STATUSES[$status]['summary'];
        }
    }

    return \implode(', ', $parts);
}

/** @param array<string, int> $counts status => count for one backend */
function renderSummary(array $counts, string $label, float $durationMs): string
{
    $parts = [];
    foreach (SUMMARY_ORDER as $status) {
        // Cases that were never candidates here are not worth reporting.
        if ($status === 'not-applicable') {
            continue;
        }
        $count = $counts[$status] ?? 0;
        if ($count > 0 || $status === 'pass') {
            $parts[] = \sprintf('%d %s', $count, CASE_STATUSES[$status]['summary']);
        }
    }

    return \sprintf("%s  (%s, %s)\n", \implode(', ', $parts), $label, formatDuration($durationMs));
}

/**
 * One backend as JSON: its cases, and its counts keyed by status (the same map the terminal report
 * counts from).
 *
 * @param array<string, int> $counts
 */
function renderJsonBackend(string $backend, ResultSet $set, array $counts, float $durationMs, float $startedAt): array
{
    $cases = [];
    foreach ($set->results as $result) {
        $cases[] = caseResultToArray($result);
    }

    return [
        'backend' => $backend,
        'startedAt' => \date(\DATE_ATOM, (int) $startedAt),
        'counts' => $counts,
        'total' => \count($set->results),
        'durationMs' => \round($durationMs, 1),
        'cases' => $cases,
    ];
}
