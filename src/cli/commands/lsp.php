<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Backend;

use function Moggi\CLI\parseBackendValue;
use function Moggi\LSP\runServer;

/**
 * `moggi lsp` — the Language Server Protocol server on stdin/stdout.
 *
 * The editor speaks LSP to the process directly: there is no input to name and no
 * output file, so the command is only a choice of backend and library roots.
 */
function lspUsage(): string
{
    return <<<HELP
    usage:
      moggi lsp [options]

    Start the Language Server Protocol server. The editor launches it and speaks
    the protocol over stdin/stdout; there is nothing to point it at.

    options:
      --backend B      compile target (default: php)
      --lib PATH       extra module search root. Repeatable.
      --no-cache       bypass the on-disk compile cache for this run
      --stdio          accepted for compatibility; the server is always stdio
      -h, --help       show this help
    HELP;
}

/** @param list<string> $argv */
function runLsp(array $argv): int
{
    $spec = new CommandSpec('lsp', lspUsage(), [
        ['name' => 'backend', 'value' => true],
        ['name' => 'lib', 'value' => true, 'repeat' => true],
        ['name' => 'noCache'],
        ['name' => 'stdio'],
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

    Backend\setCompileBackend(parseBackendValue($options['backend']));

    return runServer($options['lib']);
}
