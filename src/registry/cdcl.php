<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * A conflict-driven clause-learning (CDCL) solver — the fallback a resolution
 * takes when the greedy newest-first walk cannot finish (`resolver-sketch.md`).
 *
 * Dependency resolution is encoded as a small SAT problem. Every (name, version)
 * pair is a proposition; `at most one version of a package` is a pairwise clause;
 * `choosing v requires choosing some w in range` is one clause per (v, dependency);
 * and the root's `[dependencies]` are the clauses that must hold outright. A
 * conflict teaches a clause and the search backjumps over assignments that cannot
 * matter, so a tangle of mutually-exclusive majors is still solved — or proved
 * unsolvable — in one pass instead of by re-walking an exponential tree.
 *
 * It is deliberately *not* what runs first: the greedy walk answers the common
 * case with a handful of `entry()` lookups and no clause database, and only a
 * conflict reaches here. The greedy path is also what supplies the human-facing
 * error when nothing is satisfiable, because it names the constraints in play.
 */

/**
 * A minimal but complete CDCL core: two-watched literals, phase saving, first-UIP
 * conflict analysis and non-chronological backjumping.
 *
 * The caller adds clauses and then runs `solve()` once; there is no incremental
 * interface, because a resolution is a single question.
 */
final class CdclSolver
{
    /** @var list<list<int>> one literal list per clause; its index is the clause id */
    private array $clauses = [];

    /** @var list<int> assigned literals, in the order they were assigned */
    private array $trail = [];

    /** @var array<int, bool> variable => value */
    private array $value = [];

    /** @var array<int, int> variable => decision level */
    private array $level = [];

    /** @var array<int, int|null> variable => the clause that implied it, or null for a decision */
    private array $reason = [];

    /** @var list<int> the trail length at which each decision level began */
    private array $trailLim = [];

    /** @var array<int, list<int>> literal key => clause ids watching it */
    private array $watch = [];

    /** @var array<int, bool> variable => the polarity it was last given, for phase saving */
    private array $phase = [];

    /** @var list<int> the literals a one-literal clause asserts */
    private array $units = [];

    private int $numVars = 0;
    private int $qhead = 0;
    private bool $unsatisfiable = false;

    public function newVar(): int
    {
        return ++$this->numVars;
    }

    public function isUnsatisfiable(): bool
    {
        return $this->unsatisfiable;
    }

    public function isTrue(int $var): bool
    {
        return ($this->value[$var] ?? null) === true;
    }

    /**
     * Add a clause, returning its id (or -1 for a clause kept out of the watch
     * lists — a unit, a tautology, or the empty clause). An empty clause makes the
     * whole formula unsatisfiable, which the next `solve()` reports.
     *
     * @param list<int> $literals
     */
    public function addClause(array $literals): int
    {
        $literals = \array_values(\array_unique($literals));
        $present = \array_flip($literals);
        foreach ($literals as $literal) {
            if (isset($present[-$literal])) {
                return -1;
            }
        }
        if ($literals === []) {
            $this->unsatisfiable = true;

            return -1;
        }
        if (\count($literals) === 1) {
            $this->units[] = $literals[0];

            return -1;
        }

        $id = \count($this->clauses);
        $this->clauses[] = $literals;
        $this->watch[$this->literalKey($literals[0])][] = $id;
        $this->watch[$this->literalKey($literals[1])][] = $id;

        return $id;
    }

    /** Whether the formula is satisfiable; the assignment is then readable by `isTrue()`. */
    public function solve(): bool
    {
        if ($this->unsatisfiable) {
            return false;
        }
        foreach ($this->units as $literal) {
            $already = $this->value($literal);
            if ($already === true) {
                continue;
            }
            if ($already === false) {
                return false;
            }
            $this->enqueue($literal, null);
        }
        if ($this->propagate() !== null) {
            return false;
        }

        while (true) {
            $conflict = $this->propagate();
            if ($conflict !== null) {
                if ($this->decisionLevel() === 0) {
                    return false;
                }
                [$learned, $backtrack] = $this->analyze($conflict);
                if ($learned === []) {
                    return false;
                }
                $this->backtrack($backtrack);
                if (\count($learned) === 1) {
                    $this->enqueue($learned[0], null);
                } else {
                    $id = $this->addClause($learned);
                    $this->enqueue($learned[0], $id < 0 ? null : $id);
                }
                continue;
            }

            $decision = $this->decide();
            if ($decision === null) {
                return true;
            }
            $this->trailLim[] = \count($this->trail);
            $this->enqueue($decision, null);
        }
    }

    private function decisionLevel(): int
    {
        return \count($this->trailLim);
    }

    public function value(int $literal): ?bool
    {
        $value = $this->value[\abs($literal)] ?? null;

        return $value === null ? null : ($literal > 0 ? $value : !$value);
    }

    /**
     * The unit-propagate loop: for every literal just made true, visit the clauses
     * watching its negation and either move the watch, satisfy it, or discover a
     * unit or a conflict. Returns the conflicting clause id, or null when the
     * assignment is consistent.
     */
    private function propagate(): ?int
    {
        while ($this->qhead < \count($this->trail)) {
            $true = $this->trail[$this->qhead++];
            $key = $this->literalKey(-$true);
            $watching = $this->watch[$key] ?? [];
            $index = 0;

            while ($index < \count($watching)) {
                $id = $watching[$index];
                $clause = $this->clauses[$id];
                if ($clause[0] === -$true) {
                    $swap = $clause[0];
                    $clause[0] = $clause[1];
                    $clause[1] = $swap;
                }

                if ($this->value($clause[0]) === true) {
                    $index++;
                    continue;
                }

                $replacement = null;
                for ($k = 2, $n = \count($clause); $k < $n; $k++) {
                    if ($this->value($clause[$k]) !== false) {
                        $replacement = $k;
                        break;
                    }
                }
                if ($replacement !== null) {
                    $newWatch = $clause[$replacement];
                    $clause[1] = $newWatch;
                    $clause[$replacement] = -$true;
                    $this->clauses[$id] = $clause;
                    $this->watch[$this->literalKey($newWatch)][] = $id;
                    $watching[$index] = $watching[\count($watching) - 1];
                    \array_pop($watching);
                    continue;
                }

                $other = $this->value($clause[0]);
                if ($other === false) {
                    $this->watch[$key] = $watching;

                    return $id;
                }
                $this->clauses[$id] = $clause;
                $this->enqueue($clause[0], $id);
                $index++;
            }

            $this->watch[$key] = $watching;
        }

        return null;
    }

    /**
     * First-UIP conflict analysis: walk back from the conflict, resolving on the
     * most recent current-level literal each time, until one literal from the
     * current level remains. That literal's negation is the learned clause's
     * asserting literal, and the highest level among the others is where to
     * backjump.
     *
     * @return array{0: list<int>, 1: int} [learned clause, backjump level]
     */
    private function analyze(int $conflict): array
    {
        $learned = [0];
        $seen = [];
        $counter = 0;
        $index = \count($this->trail) - 1;
        $clause = $conflict;
        $resolved = 0;
        $asserting = 0;

        do {
            foreach ($this->clauses[$clause] as $literal) {
                $var = \abs($literal);
                if ($var === \abs($resolved) || isset($seen[$var]) || ($this->level[$var] ?? 0) === 0) {
                    continue;
                }
                $seen[$var] = true;
                if (($this->level[$var] ?? 0) === $this->decisionLevel()) {
                    $counter++;
                } else {
                    $learned[] = $literal;
                }
            }

            while ($index >= 0 && !isset($seen[\abs($this->trail[$index])])) {
                $index--;
            }
            if ($index < 0) {
                return [[], 0];
            }
            $resolved = $this->trail[$index];
            $asserting = -$resolved;
            $index--;
            $seen[\abs($resolved)] = false;
            $counter--;
            $reason = $this->reason[\abs($resolved)] ?? null;
            if ($reason === null) {
                break;
            }
            $clause = $reason;
        } while ($counter > 0);

        $learned[0] = $asserting;

        $backjump = 0;
        for ($i = 1, $n = \count($learned); $i < $n; $i++) {
            $level = $this->level[\abs($learned[$i])] ?? 0;
            if ($level > $backjump) {
                $backjump = $level;
            }
        }

        return [$learned, $backjump];
    }

    private function backtrack(int $level): void
    {
        while (\count($this->trailLim) > $level) {
            $limit = \array_pop($this->trailLim);
            for ($i = \count($this->trail) - 1; $i >= $limit; $i--) {
                $var = \abs($this->trail[$i]);
                unset($this->value[$var], $this->level[$var], $this->reason[$var]);
            }
            $this->trail = \array_slice($this->trail, 0, $limit);
        }
        $this->qhead = \count($this->trail);
    }

    private function decide(): ?int
    {
        for ($var = 1; $var <= $this->numVars; $var++) {
            if (!isset($this->value[$var])) {
                return ($this->phase[$var] ?? false) ? -$var : $var;
            }
        }

        return null;
    }

    private function enqueue(int $literal, ?int $reason): void
    {
        $var = \abs($literal);
        $this->value[$var] = $literal > 0;
        $this->level[$var] = $this->decisionLevel();
        $this->reason[$var] = $reason;
        $this->phase[$var] = $literal > 0;
        $this->trail[] = $literal;
    }

    /**
     * A clause key that separates `+var` from `-var` without a map per literal.
     * It is only called after every variable exists, so it is a total function.
     */
    private function literalKey(int $literal): int
    {
        return $literal > 0 ? $literal : $this->numVars - $literal;
    }
}

/**
 * Solve a resolution with CDCL, when the greedy walk could not.
 *
 * The reachable packages are collected first (a breadth-first walk over every
 * version's dependencies, so all alternatives are in play), then encoded and
 * handed to `CdclSolver`. The answer is the model pruned to the packages the
 * root actually reaches, so it names no package the greedy walk would not have.
 *
 * @param callable(string): ?array<string, mixed> $entry name => package record, or null when unknown
 * @param array<string, string> $dependencies the root descriptor's `[dependencies]`
 * @return ?array<string, string> name => version, or null when nothing is satisfiable
 */
function cdclResolve(callable $entry, array $dependencies): ?array
{
    $records = [];
    $queue = \array_keys($dependencies);
    while ($queue !== []) {
        $name = \array_shift($queue);
        if (\array_key_exists($name, $records)) {
            continue;
        }
        $record = $entry($name);
        $records[$name] = $record;
        if (!\is_array($record)) {
            continue;
        }
        foreach ((array) ($record['versions'] ?? []) as $data) {
            foreach ((array) (((array) $data)['deps'] ?? []) as $dependency => $_constraint) {
                if (!\array_key_exists((string) $dependency, $records)) {
                    $queue[] = (string) $dependency;
                }
            }
        }
    }

    $names = \array_keys($records);
    \sort($names, \SORT_STRING);
    $versionsOf = [];
    $solver = new CdclSolver();
    foreach ($names as $name) {
        $versions = \array_keys((array) (($records[$name] ?? [])['versions'] ?? []));
        \usort($versions, newestFirst(...));
        foreach ($versions as $version) {
            $versionsOf[$name][] = [(string) $version, $solver->newVar()];
        }
    }

    foreach ($versionsOf as $versions) {
        for ($i = 0, $n = \count($versions); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $solver->addClause([-$versions[$i][1], -$versions[$j][1]]);
            }
        }
    }

    foreach ($dependencies as $name => $constraint) {
        $solver->addClause(candidateLiterals($versionsOf[$name] ?? [], (string) $constraint));
    }

    foreach ($versionsOf as $name => $versions) {
        $packageVersions = (array) (($records[$name] ?? [])['versions'] ?? []);
        foreach ($versions as [$version, $var]) {
            foreach ((array) (((array) ($packageVersions[$version] ?? []))['deps'] ?? []) as $dependency => $constraint) {
                $literals = [-$var, ...candidateLiterals($versionsOf[(string) $dependency] ?? [], (string) $constraint)];
                $solver->addClause($literals);
            }
        }
    }

    if ($solver->isUnsatisfiable() || !$solver->solve()) {
        return null;
    }

    $selected = [];
    foreach ($versionsOf as $name => $versions) {
        foreach ($versions as [$version, $var]) {
            if ($solver->isTrue($var)) {
                $selected[$name] = $version;
            }
        }
    }

    $chosen = [];
    $stack = \array_keys($dependencies);
    while ($stack !== []) {
        $name = (string) \array_pop($stack);
        if (isset($chosen[$name]) || !isset($selected[$name])) {
            continue;
        }
        $chosen[$name] = $selected[$name];
        $deps = (array) ((((array) ($records[$name]['versions'][$selected[$name]] ?? []))['deps']) ?? []);
        foreach ($deps as $dependency => $_constraint) {
            $stack[] = (string) $dependency;
        }
    }
    \ksort($chosen);

    return $chosen;
}

/**
 * The positive literals for the versions of `$name` that satisfy `$constraint`.
 *
 * @param list<array{0: string, 1: int}> $versions
 * @return list<int>
 */
function candidateLiterals(array $versions, string $constraint): array
{
    $literals = [];
    foreach ($versions as [$version, $var]) {
        if (satisfies($version, $constraint)) {
            $literals[] = $var;
        }
    }

    return $literals;
}
