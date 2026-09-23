<?php declare(strict_types=1);

/**
 * `--jobs N`: the same selection, run in N worker processes.
 *
 * Cases are independent (atomic cache writes, per-process scratch, per-case logs), so workers need no
 * locking; results merge on the case's position in the selection, so the report does not depend on
 * which worker finished first.
 */

/**
 * The selection split into `--jobs` shards: round-robin, so a heavy group spreads over workers.
 *
 * @param list<TestCase> $cases
 * @param list<string> $backends
 * @param list<array{path: string, directory: bool}> $pathFilters
 * @return list<list<array{backend: string, index: int, case: TestCase}>>
 */
function shardUnitMap(array $cases, array $backends, array $pathFilters, int $jobs): array
{
    global $testArgs;

    $shards = \array_fill(0, \max(1, $jobs), []);
    $unit = 0;
    foreach ($backends as $backend) {
        foreach (selectCases($cases, $pathFilters, $testArgs['groups'], $backend) as $index => $case) {
            $shards[$unit % \count($shards)][] = ['backend' => $backend, 'index' => $index, 'case' => $case];
            ++$unit;
        }
    }

    return $shards;
}

/**
 * This process is a `--shard I/N` worker: run only its share and stream the results to the parent as
 * line-delimited JSON, one line per finished case, then one `done` line.
 *
 * @param list<TestCase> $cases
 * @param list<string> $backends
 * @param list<array{path: string, directory: bool}> $pathFilters
 */
function runShardMode(array $cases, array $backends, array $pathFilters): int
{
    global $testArgs;

    $GLOBALS['moggi_test_worker'] = true;
    [$shard, $jobs] = $testArgs['shard'];

    $grouped = [];
    foreach (shardUnitMap($cases, $backends, $pathFilters, $jobs)[$shard] ?? [] as $unit) {
        $grouped[$unit['backend']][] = $unit['case'];
    }

    $runStarted = \microtime(true);
    $failed = false;

    foreach ($backends as $backend) {
        if (!isset($grouped[$backend])) {
            continue;
        }

        \Moggi\Backend\setCompileBackend($backend);
        $emit = static function (CaseResult $result) use ($backend): void {
            workerEmitCase($backend, $result);
        };
        $outcome = runBackendCases($grouped[$backend], $backend, false, $emit);
        $failed = $failed || countsFailed($outcome['set']->counts());
    }

    workerEmitLine(['kind' => 'done', 'worker' => $shard . '/' . $jobs, 'failed' => $failed]);

    return $failed ? 1 : 0;
}

/** One line of a worker's stdout, flushed so the parent reads it as the run goes on. */
function workerEmitLine(array $event): void
{
    $line = \json_encode($event, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    echo $line . "\n";
    \fflush(\STDOUT);
}

/** One finished case, as a line of the worker's stdout stream. */
function workerEmitCase(string $backend, CaseResult $result): void
{
    workerEmitLine(['kind' => 'case', 'backend' => $backend] + caseResultToArray($result));
}

/**
 * Fold one line of a worker's stdout into the run state: a case line moves its group's row, a `done`
 * line marks the worker as finished — its absence is how a dead worker is detected.
 *
 * @param array<string, mixed> $stream
 */
function ingestWorkerLine(array &$stream, int $shard, string $line): void
{
    $event = \json_decode($line, true);
    if (!\is_array($event)) {
        return;
    }

    if (($event['kind'] ?? '') !== 'case') {
        if (($event['kind'] ?? '') === 'done') {
            $stream['done'][$shard] = true;
        }

        return;
    }

    $backend = (string) $event['backend'];
    // The worker numbers cases from zero within its shard; map that to the selection's position.
    $position = $stream['globalByLocal'][$shard][$backend][(int) ($event['order'] ?? -1)] ?? null;
    if ($position === null) {
        return;
    }

    $result = caseResultFromArray($event, $backend);
    $result->order = $position;
    $stream['merged'][$backend][$position] = $result;

    $multi = ($stream['multiBackend'] ?? false) === true;
    if (!progressReported() && $result->status !== 'not-applicable') {
        suiteEcho(renderCaseLine($result, $multi ? $backend : null));
    }

    // The parent is the only thing a pool run prints, so the block is fed from the stream here.
    if (!($stream['announced'][$backend] ?? false)) {
        $stream['announced'][$backend] = true;
        progressPlan($stream['planByBackend'][$backend] ?? [], $multi ? $backend : null);
    }

    $section = $multi ? $backend : null;
    if (progressCurrent() !== progressRowKey($section, $result->group)) {
        progressStart($result->group, $stream['planByBackend'][$backend][$result->group] ?? 0, $section);
    }
    progressAdvance($result->isFailure());
}

/**
 * The `--jobs N` pool: dispatch the shards, merge what they stream back, and hand out one result set
 * per backend, ordered as the selection is.
 */
final class ShardPool
{
    /** @var array<string, array<int, CaseResult>>|null backend => position in the selection => result */
    private ?array $merged = null;

    /** @var list<int> shards that never sent their `done` line */
    private array $dead = [];

    /**
     * @param list<list<array{backend: string, index: int, case: TestCase}>> $shards
     * @param array<int, array<string, array<int, int>>> $globalByLocal shard => backend => the
     *        worker's own numbering => position in the selection
     */
    private function __construct(
        private array $shards,
        private array $globalByLocal,
        private bool $multiBackend,
    ) {
    }

    /**
     * The pool a selection needs, or null when workers would buy nothing: one shard is a serial run,
     * so the caller runs it in this process instead of launching a worker for it.
     *
     * @param list<TestCase> $cases
     * @param list<string> $backends
     * @param list<array{path: string, directory: bool}> $pathFilters
     */
    public static function forSelection(array $cases, array $backends, array $pathFilters, int $jobs, bool $multiBackend): ?self
    {
        $shards = \array_values(\array_filter(
            shardUnitMap($cases, $backends, $pathFilters, $jobs),
            static fn (array $shard): bool => $shard !== [],
        ));
        if (\count($shards) <= 1) {
            return null;
        }

        $globalByLocal = [];
        foreach ($shards as $shardIndex => $shard) {
            $localByBackend = [];
            foreach ($shard as $unit) {
                $backend = $unit['backend'];
                $local = $localByBackend[$backend] ?? 0;
                $localByBackend[$backend] = $local + 1;
                $globalByLocal[$shardIndex][$backend][$local] = $unit['index'];
            }
        }

        return new self($shards, $globalByLocal, $multiBackend);
    }

    /** How many workers this pool actually launches (the shard count, not the requested `--jobs`). */
    public function jobCount(): int
    {
        return \count($this->shards);
    }

    /** How many workers died before finishing: the run is inconclusive when this is not zero. */
    public function deadWorkerCount(): int
    {
        return \count($this->dead);
    }

    /**
     * One backend's results, in selection order. The first call runs the pool to completion; the rest
     * are answered from what it merged.
     */
    public function resultSet(string $backend): ResultSet
    {
        if ($this->merged === null) {
            $this->run();
        }

        $rows = $this->merged[$backend] ?? [];
        \ksort($rows);

        $set = new ResultSet();
        foreach ($rows as $result) {
            $set->add($result);
        }

        return $set;
    }

    private function run(): void
    {
        $commands = [];
        foreach (\array_keys($this->shards) as $shard) {
            $commands[] = [
                'command' => shardWorkerCommand($shard, $this->jobCount()),
                'timeout' => 3600,
            ];
        }

        /** @var array<string, array<string, int>> $planByBackend backend => group => case count */
        $planByBackend = [];
        foreach ($this->shards as $shard) {
            foreach ($shard as $unit) {
                $group = $unit['case']->group;
                $planByBackend[$unit['backend']][$group] = ($planByBackend[$unit['backend']][$group] ?? 0) + 1;
            }
        }

        /** @var array<string, mixed> $stream */
        $stream = [
            'merged' => [],
            'pending' => [],
            'done' => [],
            'announced' => [],
            'globalByLocal' => $this->globalByLocal,
            'planByBackend' => $planByBackend,
            'multiBackend' => $this->multiBackend,
        ];

        $onChunk = static function (int $shard, int $fd, string $chunk) use (&$stream): void {
            if ($fd !== 1) {
                return;
            }

            $stream['pending'][$shard] = ($stream['pending'][$shard] ?? '') . $chunk;
            while (($newline = \strpos($stream['pending'][$shard], "\n")) !== false) {
                $line = \substr($stream['pending'][$shard], 0, $newline);
                $stream['pending'][$shard] = \substr($stream['pending'][$shard], $newline + 1);
                ingestWorkerLine($stream, $shard, $line);
            }
        };

        $results = runProcessesInParallel($commands, $onChunk);
        // The pool is complete, so the last section's block is the one the run keeps on screen.
        progressFreeze();

        /** @var array<string, array<int, CaseResult>> $merged */
        $merged = $stream['merged'];
        /** @var array<int, string> $stderr shard => its last words */
        $stderr = [];
        foreach ($results as $shard => $result) {
            // A worker that never said `done` died; its stderr is the only explanation why.
            if (($stream['done'][$shard] ?? false) !== true) {
                $this->dead[] = (int) $shard;
                $stderr[(int) $shard] = (string) $result['stderr'];
            }
        }

        // A dead worker still reported every case it finished, so only the rest are lost — reported
        // once per shard, not once per case.
        foreach ($this->dead as $shard) {
            $this->markLost($merged, $shard, $stderr[$shard] ?? '');
        }

        $this->merged = $merged;
    }

    /**
     * Give a dead worker's unreported cases a `lost` row, with the shard's last words on the first of
     * them per backend.
     *
     * @param array<string, array<int, CaseResult>> $merged
     */
    private function markLost(array &$merged, int $shard, string $stderr): void
    {
        /** @var array<string, array{first: ?int, cases: list<string>}> $lostByBackend */
        $lostByBackend = [];
        foreach ($this->shards[$shard] ?? [] as $unit) {
            $backend = $unit['backend'];
            if (isset($merged[$backend][$unit['index']])) {
                continue;
            }

            $merged[$backend][$unit['index']] = new CaseResult(
                name: $unit['case']->name,
                group: $unit['case']->group,
                status: 'lost',
                order: $unit['index'],
            );
            $lostByBackend[$backend] ??= ['first' => null, 'cases' => []];
            $lostByBackend[$backend]['first'] ??= $unit['index'];
            $lostByBackend[$backend]['cases'][] = $unit['case']->name;
        }

        $shardTotal = \count($this->shards[$shard] ?? []);
        $lostTotal = 0;
        foreach ($lostByBackend as $lost) {
            $lostTotal += \count($lost['cases']);
        }

        foreach ($lostByBackend as $backend => $lost) {
            $block = $merged[$backend][$lost['first']] ?? null;
            if ($block === null) {
                continue;
            }
            $block->message = shardDeathReport(
                $shard,
                $this->jobCount(),
                $lost['cases'],
                $shardTotal - $lostTotal,
                $shardTotal,
                $stderr,
            );
            $block->repro = 'runtest --backend ' . $backend . ' ' . $lost['cases'][0];
        }
    }
}

/**
 * What a dead worker's cases are reported with: which shard died, where it stopped, and its last
 * words — stderr is the only way to tell an allocation failure from a watchdog.
 *
 * @param list<string> $lost
 */
function shardDeathReport(int $shard, int $jobs, array $lost, int $recovered, int $total, string $stderr): string
{
    $lostCount = \count($lost);
    $lines = [];
    $lines[] = \sprintf('shard %d/%d died after finishing %d of its %d case(s)', $shard, $jobs, $recovered, $total);
    $lines[] = \sprintf(
        'did not run: %s%s',
        \implode(', ', \array_slice($lost, 0, 6)),
        $lostCount > 6 ? \sprintf(' … and %d more', $lostCount - 6) : '',
    );
    $lines[] = $lostCount === 1
        ? 'rerun that case on its own to see it fail'
        : 'rerun one of them on its own to see it fail';

    $tail = \array_values(\array_filter(
        \preg_split('/\R/', $stderr) ?: [],
        static fn (string $line): bool => $line !== '',
    ));
    if ($tail !== []) {
        $lines[] = 'worker stderr (last lines):';
        foreach (\array_slice($tail, -12) as $line) {
            $lines[] = '  ' . $line;
        }
    }

    return \implode("\n", $lines);
}

/**
 * The command a worker runs: this entry point with the parent's arguments, `--jobs`/`--log` dropped
 * and `--shard k/N` appended.
 *
 * @return list<string>
 */
function shardWorkerCommand(int $shard, int $jobs): array
{
    $command = [PHP_BINARY, MOGGI_PROJECT_ROOT . '/test.php'];
    $argv = $GLOBALS['argv'] ?? [];

    for ($i = 1, $count = \count($argv); $i < $count; ++$i) {
        $arg = (string) $argv[$i];
        if ($arg === '--jobs' || $arg === '-j' || $arg === '--shard' || $arg === '--log') {
            ++$i;
            continue;
        }
        if ($arg === '--json' || \str_starts_with($arg, '--log=')) {
            continue;
        }
        $command[] = $arg;
    }

    return [...$command, '--shard', $shard . '/' . $jobs];
}
