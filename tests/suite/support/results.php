<?php declare(strict_types=1);

/**
 * Result model. `skip` applies here but could not run (a missing toolchain); `not-applicable` was
 * never a candidate here — it belongs to other backends.
 */

/**
 * Every status a case can end in, and how the report reads it.
 *
 * @var array<string, array{summary: string, failure: bool}>
 */
const CASE_STATUSES = [
    'pass' => ['summary' => 'passed', 'failure' => false],
    'fail' => ['summary' => 'failed', 'failure' => true],
    'timeout' => ['summary' => 'timed out', 'failure' => true],
    'lost' => ['summary' => 'lost', 'failure' => true],
    'skip' => ['summary' => 'skipped', 'failure' => false],
    'not-applicable' => ['summary' => 'not applicable', 'failure' => false],
];

/** How a summary line reads its counts, in order: what passed, what went wrong, what did not run. */
const SUMMARY_ORDER = ['pass', 'fail', 'timeout', 'lost', 'skip', 'not-applicable'];

/** Does this set of counts fail the run? A `lost` case is inconclusive, but not green either.
 *
 * @param array<string, int> $counts
 */
function countsFailed(array $counts): bool
{
    foreach ($counts as $status => $count) {
        if ($count === 0) {
            continue;
        }
        // An unknown status is a harness bug; fail on it rather than let it count for nothing.
        $info = CASE_STATUSES[$status] ?? null;
        if ($info === null || $info['failure']) {
            return true;
        }
    }

    return false;
}

/**
 * @param iterable<CaseResult> $results
 * @return array<string, int>
 */
function countStatuses(iterable $results): array
{
    $counts = \array_fill_keys(\array_keys(CASE_STATUSES), 0);
    foreach ($results as $result) {
        $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;
    }

    return $counts;
}

final class CaseResult
{
    public function __construct(
        public string $name,
        public string $group,
        public string $status,
        public string $message = '',
        public float $durationMs = 0.0,
        public ?string $skipReason = null,
        public ?string $logPath = null,
        public ?string $repro = null,
        /** Position in the selection: the merge key for `--jobs N` workers. */
        public int $order = 0,
        /** The verdict was the `--native` build+run of the case's program, not the managed run. */
        public bool $native = false,
    ) {
    }

    public function isFailure(): bool
    {
        return CASE_STATUSES[$this->status]['failure'] ?? true;
    }
}

/**
 * A case as a plain array: the shape `--json` emits and `--jobs` workers stream to their parent.
 *
 * @return array{name: string, group: string, status: string, order: int, durationMs: float, message: string, skipReason: ?string, log: ?string, native: bool}
 */
function caseResultToArray(CaseResult $result): array
{
    return [
        'name' => $result->name,
        'group' => $result->group,
        'status' => $result->status,
        // `--jobs N` workers merge on this, so the report does not depend on finish order.
        'order' => $result->order,
        'durationMs' => \round($result->durationMs, 1),
        'message' => $result->message,
        'skipReason' => $result->skipReason,
        'log' => $result->logPath,
        // Part of the wire form, or a `--jobs` run would report the case without its mode.
        'native' => $result->native,
    ];
}

/**
 * The inverse of `caseResultToArray`; `$repro` is supplied by the reader, which is the one thing a
 * wire form cannot carry back.
 *
 * @param array<string, mixed> $data
 */
function caseResultFromArray(array $data, ?string $backend = null): CaseResult
{
    $name = (string) ($data['name'] ?? '');

    return new CaseResult(
        name: $name,
        group: (string) ($data['group'] ?? ''),
        status: (string) ($data['status'] ?? 'fail'),
        message: (string) ($data['message'] ?? ''),
        durationMs: (float) ($data['durationMs'] ?? 0.0),
        skipReason: isset($data['skipReason']) && $data['skipReason'] !== null ? (string) $data['skipReason'] : null,
        logPath: isset($data['log']) ? (string) $data['log'] : null,
        repro: $backend === null ? null : 'runtest --backend ' . $backend . ' ' . $name,
        order: (int) ($data['order'] ?? 0),
        native: ($data['native'] ?? false) === true,
    );
}

final class ResultSet
{
    /** @var list<CaseResult> */
    public array $results = [];

    public function add(CaseResult $result): void
    {
        $this->results[] = $result;
    }

    /** @return list<CaseResult> */
    public function withStatus(string ...$statuses): array
    {
        return \array_values(\array_filter(
            $this->results,
            static fn (CaseResult $r): bool => \in_array($r->status, $statuses, true),
        ));
    }

    public function durationMs(): float
    {
        $total = 0.0;
        foreach ($this->results as $result) {
            $total += $result->durationMs;
        }

        return $total;
    }

    /**
     * @return array<string, int> status => count
     */
    public function counts(): array
    {
        return countStatuses($this->results);
    }
}
