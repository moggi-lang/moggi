<?php declare(strict_types=1);

require_once __DIR__ . '/progress_render.php';

/**
 * Live progress block: one row per group, repainted in place on stderr (drawing is in
 * `progress_render.php`). stdout is untouched, so `--json`, `--log` and redirection are unaffected.
 */

function progressEnabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    global $testArgs;

    if (($GLOBALS['moggi_test_worker'] ?? false) === true) {
        // Workers print the protocol stream the parent merges; keep them silent.
        return $enabled = false;
    }

    $forced = $testArgs['progress'] ?? null;
    if ($forced === true || $forced === false) {
        return $enabled = $forced;
    }

    return $enabled = progressStderrIsTerminal();
}

/** `MOGGI_TEST_FORCE_TTY` pins the terminal default without the test having to allocate a pty. */
function progressStderrIsTerminal(): bool
{
    if (\getenv('MOGGI_TEST_FORCE_TTY') === '1') {
        return true;
    }

    return \function_exists('stream_isatty') && @\stream_isatty(\STDERR);
}

/**
 * The block's rows, in the order the groups first ran.
 *
 * @return array{rows: array<string, array{label: string, backend: ?string, total: int, done: int, failed: int, startedAt: ?float, endedAt: ?float}>, order: list<string>, active: ?string, painted: int}
 */
function &progressState(): array
{
    static $state = null;
    if ($state === null) {
        $state = ['rows' => [], 'order' => [], 'active' => null, 'painted' => 0];
    }

    return $state;
}

/**
 * A row's key: the group scoped to its backend, since the same group runs on every backend.
 */
function progressRowKey(?string $backend, string $group): string
{
    return ($backend ?? '') . "\0" . $group;
}

/**
 * Announce a backend's whole selection before any case runs, so every group has a row from the first
 * second and nothing shifts under the reader.
 *
 * @param array<string, int> $totalByGroup group => case count, in execution order
 */
function progressPlan(array $totalByGroup, ?string $backend = null): void
{
    if (!progressEnabled() || $totalByGroup === []) {
        return;
    }

    $state = &progressState();
    foreach ($totalByGroup as $group => $total) {
        $key = progressRowKey($backend, (string) $group);
        if (isset($state['rows'][$key])) {
            continue;
        }
        $state['rows'][$key] = progressRow((string) $group, $backend, $total, null);
        $state['order'][] = $key;
    }

    progressRender();
}

/**
 * A row that has not started yet keeps a null start time, so its elapsed column reads `--`.
 *
 * @return array{label: string, backend: ?string, total: int, done: int, failed: int, startedAt: ?float, endedAt: ?float}
 */
function progressRow(string $label, ?string $backend, int $total, ?float $startedAt): array
{
    return [
        'label' => $label,
        'backend' => $backend,
        'total' => $total,
        'done' => 0,
        'failed' => 0,
        'startedAt' => $startedAt,
        'endedAt' => null,
    ];
}

/**
 * Give a group its row (or reuse it) and make it the one `progressAdvance` counts into. Returns the
 * row's key.
 */
function progressStart(string $group, int $total, ?string $backend = null): string
{
    $key = progressRowKey($backend, $group);
    if (!progressEnabled()) {
        return $key;
    }

    $state = &progressState();

    if (!isset($state['rows'][$key])) {
        $state['rows'][$key] = progressRow($group, $backend, $total, \microtime(true));
        $state['order'][] = $key;
    } else {
        $state['rows'][$key]['startedAt'] ??= \microtime(true);
        if ($total > $state['rows'][$key]['total']) {
            // Another shard saw more cases in this group; widen the row, keeping what it reported.
            $state['rows'][$key]['total'] = $total;
            if ($total > $state['rows'][$key]['done']) {
                $state['rows'][$key]['endedAt'] = null;
            }
        }
    }

    $state['active'] = $key;
    progressRender();

    return $key;
}

/** The key of the row currently being counted, or null when no group is running. */
function progressCurrent(): ?string
{
    $state = &progressState();

    return $state['active'];
}

/** Count one finished case into the running group's row. */
function progressAdvance(bool $failed): void
{
    $state = &progressState();
    $label = $state['active'];
    if ($label === null || !isset($state['rows'][$label])) {
        return;
    }

    $row = &$state['rows'][$label];
    ++$row['done'];
    if ($failed) {
        ++$row['failed'];
    }

    if ($row['endedAt'] === null && $row['done'] >= $row['total']) {
        $row['endedAt'] = \microtime(true);
    }

    if (!progressFlushFinishedSections()) {
        progressRender();
    }
}

/**
 * The block's rows in runs of one backend: the leading ones are the section that can be flushed.
 *
 * @return array{backend: ?string, keys: list<string>}
 */
function progressNextSection(array $state): array
{
    $backend = $state['rows'][$state['order'][0]]['backend'] ?? null;
    $keys = [];
    foreach ($state['order'] as $key) {
        if (($state['rows'][$key]['backend'] ?? null) !== $backend) {
            break;
        }
        $keys[] = $key;
    }

    return ['backend' => $backend, 'keys' => $keys];
}

/**
 * Print the sections of finished backends as plain lines and drop them from the block; a terminal-
 * bounded block would otherwise fold a section away exactly when it finished. The newest section is
 * never flushed.
 *
 * @return bool whether anything was flushed
 */
function progressFlushFinishedSections(): bool
{
    if (!progressEnabled()) {
        return false;
    }

    $state = &progressState();
    $columns = \max(20, terminalSize()['cols'] - 1);
    $printed = '';
    while ($state['order'] !== []) {
        $section = progressNextSection($state);
        if (\count($section['keys']) === \count($state['order'])) {
            break;
        }
        foreach ($section['keys'] as $key) {
            if ($state['rows'][$key]['done'] < $state['rows'][$key]['total']) {
                break 2;
            }
        }

        if ($section['backend'] !== null) {
            $printed .= 'Backend: ' . $section['backend'] . "\n";
        }
        foreach ($section['keys'] as $key) {
            $printed .= \rtrim(progressRowLine($state['rows'][$key], $columns)) . "\n";
            unset($state['rows'][$key]);
        }
        $printed .= "\n";
        $state['order'] = \array_values(\array_diff($state['order'], $section['keys']));
    }

    if ($printed === '') {
        return false;
    }

    progressSuspend();
    \fwrite(\STDERR, $printed);
    terminalNotePrinted($printed);
    progressRender();

    return true;
}

/** With the block on it *is* the per-case report, so the group rows and `OK` lines are not printed separately. */
function progressReported(): bool
{
    return progressEnabled();
}

/**
 * Print the rows as plain lines and empty the block: a finished section carried into the next one's
 * rows would be two reports under one cursor.
 */
function progressFlushAll(): void
{
    if (!progressEnabled()) {
        return;
    }

    $state = &progressState();
    if ($state['order'] === []) {
        return;
    }

    $columns = \max(20, terminalSize()['cols'] - 1);
    $printed = '';
    $previousBackend = null;
    foreach ($state['order'] as $key) {
        $row = $state['rows'][$key];
        if ($row['backend'] !== null && $row['backend'] !== $previousBackend) {
            if ($printed !== '') {
                $printed .= "\n";
            }
            $printed .= 'Backend: ' . $row['backend'] . "\n";
        }
        $previousBackend = $row['backend'];
        $printed .= \rtrim(progressRowLine($row, $columns)) . "\n";
    }

    progressSuspend();
    \fwrite(\STDERR, $printed . "\n");
    terminalNotePrinted($printed);
    progressForget();
}

/**
 * Keep the block on screen as the run's own report: repaint it in its final state and park the cursor
 * below it, so the recap and summary print under the block instead of replacing it.
 */
function progressFreeze(): void
{
    if (!progressEnabled()) {
        return;
    }

    $state = &progressState();
    if ($state['order'] === []) {
        return;
    }

    progressRender();
    \fwrite(\STDERR, "\n");
    progressForget();
}

/** Drop the rows and stop treating the block as an overlay: what is on screen is output now. */
function progressForget(): void
{
    $state = &progressState();

    $state['rows'] = [];
    $state['order'] = [];
    $state['active'] = null;
    $state['painted'] = 0;
}

/**
 * Erase the block, keeping the rows, so output can print on the line it started on.
 */
function progressSuspend(): void
{
    $state = &progressState();
    $painted = $state['painted'];
    if ($painted === 0) {
        return;
    }

    // Walk up to the first row, clear every line, then come back up to where the block started.
    $out = "\r" . progressCursorUp($painted - 1);
    for ($i = 0; $i < $painted; ++$i) {
        $out .= PROGRESS_ERASE_LINE;
        if ($i < $painted - 1) {
            $out .= "\n";
        }
    }
    $out .= "\r" . progressCursorUp($painted - 1);

    \fwrite(\STDERR, $out);
    $state['painted'] = 0;
}
