<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Cache;
use Moggi\CLI\ArgCursor;

use function Moggi\CLI\parseBackendValue;
use function Moggi\CLI\printUsage;

function parseRunArgs(array $argv): array
{
    $input = null;
    $outputDir = null;
    $optimize = true;
    $strip = true;
    $backend = 'php';
    $libDirs = [];
    $appArgs = [];
    $native = false;

    $cursor = new ArgCursor($argv, 2);
    while (($arg = $cursor->current()) !== null) {
        if ($arg === '--') {
            $cursor->take();
            $appArgs = \array_slice($argv, $cursor->index);
            break;
        }
        if ($cursor->atHelp()) {
            exit(0);
        }
        if ($arg === '--no-opt') {
            $cursor->take();
            $optimize = false;
            continue;
        }
        if ($arg === '--native') {
            $cursor->take();
            $native = true;
            continue;
        }
        if ($arg === '--no-strip') {
            $cursor->take();
            $strip = false;
            continue;
        }
        if ($arg === '--strip') {
            $cursor->take();
            $strip = true;
            continue;
        }
        if ($arg === '--no-cache') {
            $cursor->take();
            $cursor->takeNoCache();
            continue;
        }
        if ($arg === '--backend') {
            $backend = parseBackendValue($cursor->takeValue('--backend'));
            continue;
        }
        if ($arg === '--lib') {
            $libDirs[] = $cursor->takeLibDir();
            continue;
        }
        if ($arg === '-o') {
            $outputDir = $cursor->takeValue('-o');
            continue;
        }
        if (str_starts_with($arg, '-')) {
            \fwrite(STDERR, "error: unknown option {$arg}\n\n");
            printUsage();
            exit(1);
        }
        if ($input === null) {
            $input = $cursor->take();
            continue;
        }
        \fwrite(STDERR, "error: unexpected argument {$arg}\n\n");
        printUsage();
        exit(1);
    }

    if ($input === null) {
        \fwrite(STDERR, "error: run requires <source.mog|input-dir>\n\n");
        printUsage();
        exit(1);
    }

    return [
        'input' => $input,
        'outputDir' => $outputDir,
        'optimize' => $optimize,
        'backend' => $backend,
        'strip' => $strip,
        'libDirs' => $libDirs,
        'appArgs' => $appArgs,
        'native' => $native,
    ];
}

function runRun(array $argv): int
{
    $parsed = parseRunArgs($argv);
    $input = $parsed['input'];

    if (!\is_dir($input) && !\is_file($input)) {
        \fwrite(STDERR, "error: not a file or directory: {$input}\n");

        return 1;
    }

    $keepOutput = $parsed['outputDir'] !== null;
    $outputDir = $parsed['outputDir'] ?? (sys_get_temp_dir() . '/moggi-run-' . getmypid());

    // PHP compiles to runnable files, so `run` skips PHAR packaging;
    // JVM/.NET run the packaged jar/dll from the build root.
    $built = compileIntoRoot(
        $input,
        $outputDir,
        $parsed['optimize'],
        $parsed['backend'],
        $parsed['strip'],
        $parsed['libDirs'],
        $parsed['native'],
        $parsed['backend'] === 'php',
    );

    if ($built['exitCode'] !== 0 || $built['outputRoot'] === null) {
        if (!$keepOutput) {
            removeOutputTree($outputDir);
        }

        return $built['exitCode'] !== 0 ? $built['exitCode'] : 1;
    }

    $code = executeBuiltApp(
        $built['outputRoot'],
        $built['backend'],
        $built['entryRelative'],
        $parsed['appArgs'],
        $parsed['native'],
    );

    if (!$keepOutput) {
        removeOutputTree($built['outputRoot']);
    }

    return $code;
}

function runRunCommand(array $argv): int
{
    return runRun($argv);
}
