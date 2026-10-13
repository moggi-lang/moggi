<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Backend;

use function Moggi\CLI\parseBackendValue;
use function Moggi\CLI\resolveLibraryDirs;
use function Moggi\Repl\createSession;
use function Moggi\Repl\destroySession;
use function Moggi\Repl\runLoop;
use function Moggi\Repl\runScript;

/**
 * `moggi repl` — the interactive read-eval-print loop.
 *
 * `--script FILE` runs commands from a file instead of a terminal, which is what
 * the tests drive; everything else is the backend and library roots the session
 * evaluates against.
 */
function replUsage(): string
{
    return <<<HELP
    usage:
      moggi repl [options]

    Start the interactive REPL.

    options:
      --backend B      evaluate against this backend (default: php)
      --lib PATH       extra module search root. Repeatable.
      --script PATH    run commands from a file instead of a terminal
      --no-cache       bypass the on-disk compile cache for this run
      -h, --help       show this help
    HELP;
}

/** @param list<string> $argv */
function runRepl(array $argv): int
{
    $spec = new CommandSpec('repl', replUsage(), [
        ['name' => 'backend', 'value' => true],
        ['name' => 'lib', 'value' => true, 'repeat' => true],
        ['name' => 'noCache'],
        ['name' => 'script', 'value' => true],
    ], positionals: 0);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec);
        requireDirectories($options['lib'], '--lib');
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $backend = parseBackendValue($options['backend']);
    Backend\setCompileBackend($backend);
    $state = createSession($backend, resolveLibraryDirs($options['lib']));
    try {
        if ($options['script'] !== null) {
            return runScript($state, $options['script']);
        }

        return runLoop($state);
    } finally {
        destroySession($state);
    }
}
