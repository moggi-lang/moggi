<?php declare(strict_types=1);

namespace Moggi\LSP;

use function Moggi\Cache\compilerFingerprint;
use function Moggi\Cache\freshCompilerFingerprint;
use function Moggi\LSP\Protocol\sendNotification;

/**
 * How often the running revision is compared against the one on disk. The
 * comparison hashes `src/` (~6 ms), so it rides on idle ticks rather than every
 * request.
 */
const COMPILER_CHECK_INTERVAL_SECONDS = 10.0;

/**
 * The server keeps the compiler it was started with for as long as it runs, so
 * editing the compiler under a live editor leaves it answering from a stale one:
 * a fixed bug keeps appearing, a new one does not show up. It cannot restart
 * itself — the protocol has one `initialize` and the client owns the process —
 * so it says so instead, and **Moggi: Restart Language Server** applies it.
 *
 * The notice is a log line for every revision the sources move to, and one
 * notification per process: the first move is the one the reader has to act on.
 */
function watchCompilerRevision(): void
{
    static $running = null;
    static $toasted = false;
    static $lastCheck = 0.0;

    if ($running === null) {
        // Memoized on first use, by any caller: this is the revision the process
        // is running with, not the one on disk now.
        $running = compilerFingerprint();
    }

    $now = microtime(true);
    if ($now - $lastCheck < COMPILER_CHECK_INTERVAL_SECONDS) {
        return;
    }
    $lastCheck = $now;

    $onDisk = freshCompilerFingerprint();
    $warning = compilerRevisionWarning($running, $onDisk);
    if ($warning === null) {
        return;
    }

    sendNotification('window/logMessage', $warning);
    if (! $toasted) {
        $toasted = true;
        sendNotification('window/showMessage', $warning);
    }
}

/**
 * @return array{type: int, message: string}|null null when the two revisions agree
 */
function compilerRevisionWarning(string $running, string $onDisk): ?array
{
    if ($running === $onDisk) {
        return null;
    }

    return [
        'type' => 2,
        'message' => 'Moggi compiler changed on disk (running ' . $running . ', on disk '
            . $onDisk . '). Restart the language server to pick it up — diagnostics are '
            . 'still coming from the older one.',
    ];
}
