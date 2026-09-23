<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Backend;
use Moggi\Cache;
use Moggi\CLI\ArgCursor;

use function Moggi\CLI\parseBackendValue;
use function Moggi\CLI\printUsage;
use function Moggi\CLI\resolveLibraryDirs;
use function Moggi\Repl\createSession;
use function Moggi\Repl\destroySession;
use function Moggi\Repl\runLoop;
use function Moggi\Repl\runScript;

function parseReplArgs(array $argv): array
{
    $backend = 'php';
    $libDirs = [];
    $script = null;

    $cursor = new ArgCursor($argv, 2);
    while (($arg = $cursor->current()) !== null) {
        if ($cursor->atHelp()) {
            exit(0);
        }
        if ($arg === '--backend') {
            $backend = parseBackendValue($cursor->takeValue('--backend'));
            continue;
        }
        if ($arg === '--lib') {
            $libDirs[] = $cursor->takeLibDir();
            continue;
        }
        if ($arg === '--no-cache') {
            $cursor->take();
            $cursor->takeNoCache();
            continue;
        }
        if ($arg === '--script') {
            $script = $cursor->takeValue('--script');
            continue;
        }
        \fwrite(STDERR, "error: unknown repl option `{$arg}`\n\n");
        printUsage();
        exit(1);
    }

    return [
        'backend' => $backend,
        'libDirs' => resolveLibraryDirs($libDirs),
        'script' => $script,
    ];
}

function runRepl(array $argv): int
{
    $parsed = parseReplArgs($argv);
    Backend\setCompileBackend($parsed['backend']);
    $state = createSession($parsed['backend'], $parsed['libDirs']);
    try {
        if ($parsed['script'] !== null) {
            return runScript($state, $parsed['script']);
        }

        return runLoop($state);
    } finally {
        // The scratch tree is only ever fed back into this process, so it dies
        // with the session instead of accumulating in the temp dir.
        destroySession($state);
    }
}

function runReplCommand(array $argv): int
{
    return runRepl($argv);
}
