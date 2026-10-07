<?php declare(strict_types=1);

namespace Moggi\CLI;

use function Moggi\CLI\Commands\runBadCommand;
use function Moggi\CLI\Commands\runBuildCommand;
use function Moggi\CLI\Commands\runCheckCommand;
use function Moggi\CLI\Commands\runCompileCommand;
use function Moggi\CLI\Commands\runInstallCommand;
use function Moggi\CLI\Commands\runOutdatedCommand;
use function Moggi\CLI\Commands\runSearchCommand;
use function Moggi\CLI\Commands\runShowCommand;
use function Moggi\CLI\Commands\runUnmaintainedCommand;
use function Moggi\CLI\Commands\runTakeoverCommand;
use function Moggi\CLI\Commands\runLsp;
use function Moggi\CLI\Commands\runCache;
use function Moggi\CLI\Commands\runMogdoc;
use function Moggi\CLI\Commands\runPackCommand;
use function Moggi\CLI\Commands\runPublishCommand;
use function Moggi\CLI\Commands\runMoogle;
use function Moggi\CLI\Commands\runRepl;
use function Moggi\CLI\Commands\runRun;
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
        'run' => runRun(...),
        'repl' => runRepl(...),
        'update' => runUpdateCommand(...),
        'outdated' => runOutdatedCommand(...),
        'show' => runShowCommand(...),
        'search' => runSearchCommand(...),
        'bad' => runBadCommand(...),
        'unmaintained' => runUnmaintainedCommand(...),
        'takeover' => runTakeoverCommand(...),
        'why' => runWhyCommand(...),
        'install' => runInstallCommand(...),
        'build' => runBuildCommand(...),
        'check' => runCheckCommand(...),
        'verify' => runVerifyCommand(...),
        'pack' => runPackCommand(...),
        'publish' => runPublishCommand(...),
        'cache' => runCache(...),
        'mogdoc' => runMogdoc(...),
        'moogle' => runMoogle(...),
        'lsp' => runLsp(...),
        'version' => runVersionCommand(...),
    ];
}
