<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Backend;
use Moggi\CLI\ArgCursor;

use function Moggi\CLI\parseBackendValue;
use function Moggi\LSP\runServer;

function parseLspArgs(array $argv): array
{
    $backend = 'php';
    $libDirs = [];

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
        // vscode-languageclient passes --stdio when using stdio transport; ignore it.
        if ($arg === '--stdio') {
            $cursor->take();
            continue;
        }
        \fwrite(STDERR, "error: unknown lsp option `{$arg}`\n\n");
        exit(1);
    }

    return [
        'backend' => $backend,
        'libDirs' => $libDirs,
    ];
}

function runLsp(array $argv): int
{
    $parsed = parseLspArgs($argv);
    Backend\setCompileBackend($parsed['backend']);

    // The LSP runs over stdio, so suppress any non-LSP output to stdout.
    return runServer($parsed['libDirs']);
}

function runLspCommand(array $argv): int
{
    return runLsp($argv);
}
