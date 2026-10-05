<?php declare(strict_types=1);

namespace Moggi\CLI;

use function Moggi\CLI\Commands\runBuildCommand;
use function Moggi\CLI\Commands\runCheckCommand;
use function Moggi\CLI\Commands\runCompileCommand;
use function Moggi\CLI\Commands\runInstallCommand;
use function Moggi\CLI\Commands\runOutdatedCommand;
use function Moggi\CLI\Commands\runLspCommand;
use function Moggi\CLI\Commands\runCacheCommand;
use function Moggi\CLI\Commands\runMogdocCommand;
use function Moggi\CLI\Commands\runPackCommand;
use function Moggi\CLI\Commands\runPublishCommand;
use function Moggi\CLI\Commands\runMoogleCommand;
use function Moggi\CLI\Commands\runReplCommand;
use function Moggi\CLI\Commands\runRunCommand;
use function Moggi\CLI\Commands\runUpdateCommand;
use function Moggi\CLI\Commands\runWhyCommand;
use function Moggi\CLI\Commands\runVerifyCommand;
use function Moggi\CLI\Commands\runVersionCommand;
use function Moggi\Install\activateBundledRuntimes;

function main(array $argv): int
{
    activateBundledRuntimes();

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
        'update' => runUpdateCommand(...),
        'outdated' => runOutdatedCommand(...),
        'why' => runWhyCommand(...),
        'install' => runInstallCommand(...),
        'build' => runBuildCommand(...),
        'check' => runCheckCommand(...),
        'verify' => runVerifyCommand(...),
        'pack' => runPackCommand(...),
        'publish' => runPublishCommand(...),
        'cache' => runCacheCommand(...),
        'mogdoc' => runMogdocCommand(...),
        'moogle' => runMoogleCommand(...),
        'lsp' => runLspCommand(...),
        'version' => runVersionCommand(...),
    ];
}
