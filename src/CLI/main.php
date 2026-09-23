<?php declare(strict_types=1);

namespace Moggi\CLI;

use function Moggi\CLI\Commands\runCompileCommand;
use function Moggi\CLI\Commands\runLspCommand;
use function Moggi\CLI\Commands\runCacheCommand;
use function Moggi\CLI\Commands\runMogdocCommand;
use function Moggi\CLI\Commands\runMoogleCommand;
use function Moggi\CLI\Commands\runReplCommand;
use function Moggi\CLI\Commands\runRunCommand;
use function Moggi\CLI\Commands\runVersionCommand;

function main(array $argv): int
{
    $cmd = $argv[1] ?? null;

    if ($cmd === null || $cmd === '-h' || $cmd === '--help') {
        printUsage();
        return $cmd === null ? 1 : 0;
    }

    foreach (commands() as $name => $handler) {
        if ($cmd === $name) {
            return $handler($argv);
        }
    }

    rejectUnknownSubcommand($cmd);
}

/** @return array<string, callable(array): int> */
function commands(): array
{
    return [
        'compile' => runCompileCommand(...),
        'run' => runRunCommand(...),
        'repl' => runReplCommand(...),
        'cache' => runCacheCommand(...),
        'mogdoc' => runMogdocCommand(...),
        'moogle' => runMoogleCommand(...),
        'lsp' => runLspCommand(...),
        'version' => runVersionCommand(...),
    ];
}
