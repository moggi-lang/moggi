<?php declare(strict_types=1);

require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/report.php';

/**
 * How the progress block is drawn (the state it draws from is in `progress.php`). The block is
 * bounded by the terminal in both directions: a wrapping row or an over-tall block makes the cursor
 * arithmetic land on lines the block does not own.
 */

/** Bar cells; the row's width is fixed, so one width is enough. */
const PROGRESS_BAR_WIDTH = 20;

/** Erase line: used when clearing the whole block. */
const PROGRESS_ERASE_LINE = "\033[2K";

/** Erase from the cursor rightwards: used after a row, to drop a longer previous row's tail. */
const PROGRESS_CLEAR_TAIL = "\033[K";

/** Cursor up, column preserved. Zero lines is a no-op (a one-row block never moves). */
function progressCursorUp(int $lines): string
{
    return $lines > 0 ? \sprintf("\033[%dA", $lines) : '';
}

/**
 * The terminal size in columns and rows. `MOGGI_TEST_COLUMNS`/`LINES` pin it for a test with no pty,
 * `COLUMNS`/`LINES` are what a shell exports, `tput` is the last resort.
 *
 * @return array{cols: int, rows: int}
 */
function terminalSize(): array
{
    static $size = null;
    if ($size !== null) {
        return $size;
    }

    return $size = [
        'cols' => terminalDimension('MOGGI_TEST_COLUMNS', 'COLUMNS', 'tput cols', 80),
        'rows' => terminalDimension('MOGGI_TEST_LINES', 'LINES', 'tput lines', 24),
    ];
}

/** One dimension of `terminalSize`: the test override, the exported variable, `tput`, a default. */
function terminalDimension(string $testEnv, string $shellVar, string $command, int $fallback): int
{
    $declared = \getenv($testEnv) ?: ($_SERVER[$shellVar] ?? null);
    if ($declared !== null && (int) $declared > 0) {
        return (int) $declared;
    }

    $detected = platformFirstNumber(platformShellOutput($command));

    return $detected !== null && $detected > 0 ? $detected : $fallback;
}

/**
 * One row: label, counts, bar, percent, elapsed time and failures. The case that finished last is
 * deliberately not shown (it would flicker), and a row is truncated rather than left to wrap.
 *
 * @param array{label: string, total: int, done: int, failed: int, startedAt: ?float, endedAt: ?float} $row
 */
function progressRowLine(array $row, int $columns): string
{
    $ratio = \min(1.0, $row['done'] / \max(1, $row['total']));
    $filled = (int) \round($ratio * PROGRESS_BAR_WIDTH);
    $elapsed = $row['startedAt'] === null
        ? '--'
        : formatDuration((($row['endedAt'] ?? \microtime(true)) - $row['startedAt']) * 1000);

    $line = \sprintf(
        '  %-20s %4d/%-4d [%s] %5.1f%% %7s',
        $row['label'],
        $row['done'],
        $row['total'],
        \str_repeat('=', $filled) . \str_repeat('-', PROGRESS_BAR_WIDTH - $filled),
        $ratio * 100,
        $elapsed,
    );
    if ($row['failed'] > 0) {
        $line .= \sprintf('  %d failed', $row['failed']);
    }

    return \strlen($line) > $columns ? \substr($line, 0, $columns) : $line;
}

/**
 * The rows that did not fit, folded into one. The block grows downwards, so those are the earliest
 * rows; folding them keeps the running work on screen while still showing what they did.
 *
 * @param list<array{label: string, total: int, done: int, failed: int, startedAt: ?float, endedAt: ?float}> $rows
 * @return array{label: string, total: int, done: int, failed: int, startedAt: ?float, endedAt: ?float}
 */
function progressFoldedRow(array $rows): array
{
    $folded = progressRow(\sprintf('+%d earlier groups', \count($rows)), $rows[0]['backend'] ?? null, 0, null);
    $starts = [];
    $ends = [];
    $running = false;

    foreach ($rows as $row) {
        $folded['total'] += $row['total'];
        $folded['done'] += $row['done'];
        $folded['failed'] += $row['failed'];
        if ($row['startedAt'] !== null) {
            $starts[] = $row['startedAt'];
        }
        if ($row['endedAt'] === null) {
            $running = $running || $row['startedAt'] !== null;
            continue;
        }
        $ends[] = $row['endedAt'];
    }

    $folded['startedAt'] = $starts === [] ? null : \min($starts);
    $folded['endedAt'] = $running || $ends === [] ? null : \max($ends);

    return $folded;
}

/**
 * The lines the block prints: a `Backend: <x>` header above each backend's groups, a blank line
 * between backends, one row per group. A single backend needs no header.
 *
 * @param list<array{label: string, backend: ?string, total: int, done: int, failed: int, startedAt: ?float, endedAt: ?float}> $rows
 * @return list<string>
 */
function progressLines(array $rows, int $columns): array
{
    $lines = [];
    $previousBackend = null;
    foreach ($rows as $row) {
        if ($row['backend'] !== null && $row['backend'] !== $previousBackend) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = 'Backend: ' . $row['backend'];
        }
        $previousBackend = $row['backend'];
        $lines[] = progressRowLine($row, $columns);
    }

    return $lines;
}

/**
 * Repaint the block in place: every row while they fit, then the earliest rows folded into one, so a
 * row never drops silently and the block stays repaintable.
 */
function progressRender(): void
{
    $state = &progressState();
    if ($state['order'] === []) {
        return;
    }

    // Leave the last column free: writing into it can itself wrap on some terminals.
    $columns = \max(20, terminalSize()['cols'] - 1);

    $rows = \array_map(static fn (string $key): array => $state['rows'][$key], $state['order']);

    // Fold the earliest rows away until the rest fits the screen, headers included. One at a time,
    // because the aggregate row itself takes lines and can change how many backends are on screen.
    $maxRows = \max(2, terminalSize()['rows'] - 2);
    $folded = [];
    $lines = progressLines($rows, $columns);
    while (\count($rows) > 1 && \count($lines) > $maxRows) {
        $folded[] = \array_shift($rows);
        $lines = progressLines([progressFoldedRow($folded), ...$rows], $columns);
    }
    if ($folded !== []) {
        \array_unshift($rows, progressFoldedRow($folded));
        $lines = progressLines($rows, $columns);
    }

    // The previous paint left the cursor on the last line; rewrite the block on its own lines.
    $out = $state['painted'] > 0 ? "\r" . progressCursorUp($state['painted'] - 1) : '';

    $last = \count($lines) - 1;
    foreach ($lines as $index => $line) {
        $out .= "\r" . $line . PROGRESS_CLEAR_TAIL;
        if ($index < $last) {
            $out .= "\n";
        }
    }

    \fwrite(\STDERR, $out);
    $state['painted'] = \count($lines);
}
