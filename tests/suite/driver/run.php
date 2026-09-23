<?php declare(strict_types=1);

/**
 * Suite driver: discovery → selection → execution → reporting.
 *
 * One failing case never aborts the run (`--stop-on-failure` does), every non-passing case is
 * re-listed in the recap before the summary, and `--jobs N` runs the same selection in N workers.
 */

/** Print, unless this process is a `--shard` worker whose stdout is the parent's protocol stream. */
function suiteEcho(string $text): void
{
    if (($GLOBALS['moggi_test_worker'] ?? false) === true) {
        return;
    }

    progressSuspend();
    echo $text;
    terminalNotePrinted($text);
}

/**
 * Run one backend's selection and return its result set. `$onCase` sees every finished case as it
 * finishes — a `--shard` worker uses it to stream results to its parent.
 *
 * The grouped text is always built (so `--log` is the same document with the block on or off); with
 * the block off the terminal gets one line per case as it finishes.
 *
 * @param list<TestCase> $cases
 * @param null|callable(CaseResult): void $onCase
 * @return array{set: ResultSet, stopped: bool, plain: string}
 */
function runBackendCases(array $cases, string $backend, bool $multiBackend = false, ?callable $onCase = null): array
{
    global $testArgs;

    $args = $testArgs;
    $set = new ResultSet();
    $stopped = false;
    $currentGroup = null;
    $groupBuffer = [];
    $plain = '';

    // Planned up front, so a row never appears late and shifts the block.
    $groupTotals = [];
    foreach ($cases as $case) {
        $groupTotals[$case->group] = ($groupTotals[$case->group] ?? 0) + 1;
    }
    $progressBackend = $multiBackend ? $backend : null;
    progressPlan($groupTotals, $progressBackend);

    $flush = static function () use (&$groupBuffer, &$currentGroup, &$plain): void {
        if ($currentGroup === null || $groupBuffer === []) {
            return;
        }
        $bufferSet = new ResultSet();
        foreach ($groupBuffer as $result) {
            $bufferSet->add($result);
        }
        $plain .= renderGroups($bufferSet);
        $groupBuffer = [];
    };

    foreach ($cases as $order => $case) {
        if ($case->group !== $currentGroup) {
            $flush();
            $currentGroup = $case->group;
            progressStart($case->group, $groupTotals[$case->group] ?? 0, $progressBackend);
        }

        $skip = caseSkipReason($case, $backend);
        if ($skip !== null) {
            $result = new CaseResult($case->name, $case->group, 'not-applicable', skipReason: $skip['reason'], order: $order);
            $set->add($result);
            $groupBuffer[] = $result;
            progressAdvance(false);
            if ($onCase !== null) {
                $onCase($result);
            }
            continue;
        }

        $started = \microtime(true);
        // Read by the shutdown handler (`installCaseDeathReporter`) when the process dies here.
        $GLOBALS['moggi_running_case'] = $case->name;
        try {
            $outcome = runTestCase($case, $backend, projectRootPath());
        } catch (\Throwable $e) {
            $outcome = ['passed' => false, 'message' => \get_class($e) . ': ' . $e->getMessage()];
            if ($e instanceof TestFailure) {
                $outcome = ['passed' => false, 'message' => $e->getMessage()];
            }
        }
        $durationMs = (\microtime(true) - $started) * 1000.0;
        unset($GLOBALS['moggi_running_case']);

        releasePreparedProjects();

        $passed = (bool) ($outcome['passed'] ?? false);
        $message = (string) ($outcome['message'] ?? '');
        $skipOutcome = $outcome['skip'] ?? null;
        $status = 'pass';

        if (\is_string($skipOutcome) && $skipOutcome !== '') {
            $status = 'skip';
        } elseif (!$passed) {
            $status = ($outcome['timeout'] ?? false) === true ? 'timeout' : 'fail';
        }

        $result = new CaseResult(
            name: $case->name,
            group: $case->group,
            status: $status,
            message: $message,
            durationMs: $durationMs,
            skipReason: \is_string($skipOutcome) ? $skipOutcome : null,
            order: $order,
            native: ($outcome['native'] ?? false) === true,
        );

        if ($result->isFailure() && $message !== '') {
            $result->logPath = writeCaseLog($case->name, $message);
            $result->repro = \sprintf('runtest --backend %s %s', $backend, $case->name);
        }

        $set->add($result);
        $groupBuffer[] = $result;
        progressAdvance($result->isFailure());
        if (!progressReported()) {
            suiteEcho(renderCaseLine($result, $multiBackend ? $backend : null));
        }
        if ($onCase !== null) {
            $onCase($result);
        }

        if ($testArgs['stopOnFailure'] && $result->isFailure()) {
            $stopped = true;
            break;
        }
    }

    $flush();

    return ['set' => $set, 'stopped' => $stopped, 'plain' => $plain];
}

/**
 * How many workers to use when `--jobs` was not given.
 *
 * Sharding is a wall-clock decision, not a reporting one: a TTY changes what the run draws, never
 * how the cases run, so a redirect or a pipe keeps the pool. Each worker costs a process launch, so
 * a selection only spreads when every worker gets more than one case; `--stop-on-failure` and an
 * explicit `--jobs N` are never second-guessed. An automatic count is clipped to what free memory
 * can hold.
 */
const WORKER_MEMORY_BUDGET_BYTES = 512 * 1024 * 1024;

function resolveAutoJobs(int $selectedTotal): void
{
    global $testArgs;

    $testArgs['jobsRequested'] = (int) $testArgs['jobs'];

    if ($testArgs['jobs'] !== 0) {
        return;
    }

    $workers = 1;
    if (!$testArgs['stopOnFailure']) {
        // One worker per physical CPU; the probe already clamps that to a cgroup quota or cpuset.
        $workers = \max(1, \min(detectedCpuCount(), $selectedTotal));
        $affordable = workerLimitForMemory(WORKER_MEMORY_BUDGET_BYTES);
        if ($affordable !== null) {
            $workers = \min($workers, $affordable);
        }
        if ($selectedTotal < 2 * $workers) {
            $workers = 1;
        }
    }

    $testArgs['jobs'] = $workers;
}

function runSuite(): int
{
    global $testArgs;

    if ($testArgs['errors'] !== []) {
        foreach ($testArgs['errors'] as $error) {
            \fwrite(STDERR, "error: {$error}\n");
        }
        \fwrite(STDERR, testUsage());

        return 2;
    }

    $backends = $testArgs['backends'] === [] ? ['php'] : $testArgs['backends'];

    // php is the default backend and cannot build natively; say so instead of a green run that
    // never ran the mode.
    $nativeBackends = \array_filter($backends, static fn (string $b): bool => nativeToolchainLabel($b) !== null);
    if ($testArgs['native'] && $nativeBackends === []) {
        \fwrite(STDERR, "error: --native needs a backend with a native toolchain (jvm or dotnet)\n");

        return 2;
    }

    // Take the cache generation for this run before any worker starts. A compiler change makes the
    // first process delete and restamp the whole cache, and a worker doing that while its siblings
    // compile reads directories that vanish under them.
    \Moggi\Cache\ensureCache();

    $cases = discoverTestCases();
    installCaseDeathReporter();

    try {
        $pathFilters = resolveTestSelectors($testArgs['paths'], $cases);
    } catch (\InvalidArgumentException $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        return 2;
    }

    if ($testArgs['shard'] !== null) {
        return runShardMode($cases, $backends, $pathFilters);
    }

    if ($testArgs['list']) {
        return renderTestList($cases, $backends, $pathFilters, $testArgs['groups']);
    }

    $selectedTotal = 0;
    foreach ($backends as $backend) {
        $selectedTotal += \count(selectCases($cases, $pathFilters, $testArgs['groups'], $backend));
    }

    if ($selectedTotal === 0) {
        \fwrite(STDERR, "error: no tests are owned by the selected path(s)/groups\n");

        return 2;
    }

    resolveAutoJobs($selectedTotal);

    return runSelection($cases, $backends, $pathFilters);
}

/**
 * Run the selection: one loop over the backends, and one report path whether the cases run in this
 * process or in a `--jobs` pool, so the two modes cannot drift apart in what they report.
 *
 * The progress block is the run's per-case report while it runs and the last one stays on screen,
 * frozen, with the recap and summary under it; an earlier backend's block is flushed as plain rows.
 *
 * @param list<TestCase> $cases
 * @param list<string> $backends
 * @param list<array{path: string, directory: bool}> $pathFilters
 */
function runSelection(array $cases, array $backends, array $pathFilters): int
{
    global $testArgs;

    $pool = $testArgs['jobs'] > 1
        ? ShardPool::forSelection($cases, $backends, $pathFilters, $testArgs['jobs'], \count($backends) > 1)
        : null;

    if ($pool === null) {
        suiteEcho(renderWorkerBanner(1, (int) $testArgs['jobsRequested']));
    } else {
        suiteEcho(renderWorkerBanner($pool->jobCount(), (int) $testArgs['jobsRequested']));
        if ($testArgs['stopOnFailure']) {
            \fwrite(STDERR, 'note: --stop-on-failure with --jobs ' . $pool->jobCount()
                . ": work already dispatched runs to completion\n");
        }
    }

    $lastBackend = null;
    foreach ($backends as $backend) {
        if (selectCases($cases, $pathFilters, $testArgs['groups'], $backend) !== []) {
            $lastBackend = $backend;
        }
    }

    $report = '';
    $jsonBackends = [];
    $runStarted = \microtime(true);
    $failed = false;
    $multi = \count($backends) > 1;

    foreach ($backends as $backend) {
        \Moggi\Backend\setCompileBackend($backend);
        $selected = selectCases($cases, $pathFilters, $testArgs['groups'], $backend);
        if ($selected === []) {
            continue;
        }

        $streamedBackends = $pool !== null && !progressReported();
        if ($multi) {
            $banner = backendBanner($backend);
            $report .= $banner;
            if (!$streamedBackends) {
                suiteEcho($banner);
            }
        }

        $startedAt = $pool === null ? \microtime(true) : $runStarted;

        if ($pool === null) {
            $outcome = runBackendCases($selected, $backend, $multi);
            $set = $outcome['set'];
            $report .= $outcome['plain'];
            if ($outcome['stopped']) {
                suiteEcho("note: stopped after the first failure (--stop-on-failure)\n");
            }
            if ($backend === $lastBackend) {
                progressFreeze();
            } else {
                progressFlushAll();
            }
        } else {
            $set = $pool->resultSet($backend);
            $report .= renderGroups($set);
        }

        if (!progressReported()) {
            suiteEcho(renderGroupRows($set));
        }

        [$tail, $jsonEntry, $backendFailedOut] = emitBackendTail($set, $backend, $startedAt);
        $report .= $tail;
        suiteEcho($tail);
        $jsonBackends[] = $jsonEntry;
        $failed = $failed || $backendFailedOut;
    }

    if ($pool !== null && $pool->deadWorkerCount() > 0) {
        $line = \sprintf(
            "note: %d of %d worker(s) died; the cases they had already reported are counted above\n",
            $pool->deadWorkerCount(),
            $pool->jobCount(),
        );
        $report .= $line;
        suiteEcho($line);
        $failed = true;
    }

    return finishRun($report, $jsonBackends, $backends, $failed, $runStarted);
}

/**
 * The recap (every non-passing case, re-listed) plus the summary line — one path for serial and
 * `--jobs N` runs.
 *
 * @return array{0: string, 1: array<string, mixed>, 2: bool}
 */
function emitBackendTail(ResultSet $set, string $backend, float $startedAt): array
{
    global $testArgs;

    $counts = $set->counts();
    // Wall clock, not the sum of per-case durations: under `--jobs N` the cases overlap.
    $durationMs = \round((\microtime(true) - $startedAt) * 1000.0, 1);
    $out = '';

    $recap = renderRecap($set);
    if ($recap !== '') {
        $out .= "\n" . $recap;
    }

    $out .= renderSummary($counts, 'backend: ' . $backend, $durationMs);

    return [
        $out,
        renderJsonBackend($backend, $set, $counts, $durationMs, $startedAt),
        countsFailed($counts),
    ];
}

/**
 * Aggregate verdict, `--json` document, `--log` file and exit code.
 *
 * @param list<array<string, mixed>> $jsonBackends
 * @param list<string> $backends
 */
function finishRun(string $report, array $jsonBackends, array $backends, bool $failed, float $runStarted): int
{
    global $testArgs;

    if (\count($backends) > 1) {
        $verdict = [];
        foreach ($jsonBackends as $entry) {
            $verdict[] = $entry['backend'] . ' ' . backendVerdict($entry['counts']);
            $failed = $failed || countsFailed($entry['counts']);
        }
        $line = 'ALL BACKENDS: ' . \implode(' · ', $verdict) . "\n";
        $report .= $line;
        suiteEcho($line);
    }

    if ($testArgs['json']) {
        $document = \count($jsonBackends) === 1
            ? ['schema' => 1, ...$jsonBackends[0], 'durationMs' => \round((\microtime(true) - $runStarted) * 1000.0, 1)]
            : [
                'schema' => 1,
                'backends' => $jsonBackends,
                'failed' => $failed,
                'durationMs' => \round((\microtime(true) - $runStarted) * 1000.0, 1),
            ];
        if ($testArgs['log'] !== null && !\str_ends_with((string) $testArgs['log'], '.json')) {
            @\file_put_contents((string) $testArgs['log'], $report);
        }
        // Plain echo: in worker mode this document *is* the protocol stream.
        echo \json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        return $failed ? 1 : 0;
    }

    if ($testArgs['log'] !== null) {
        $written = @\file_put_contents((string) $testArgs['log'], $report);
        if ($written === false) {
            \fwrite(STDERR, "error: cannot write log {$testArgs['log']}\n");
        }
    }

    return $failed ? 1 : 0;
}
