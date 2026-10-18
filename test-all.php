#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * All-tests entry point: the compiler suite plus every bundled library suite.
 *
 * Three stages, one after the other, with the same `--backend` surface as
 * `test.php` (`php`, `jvm`, `dotnet`, or `all` — repeatable, comma-separated):
 *
 *   1. compiler   `test.php --exclude-group base`
 *   2. base       `test.php --group base`
 *   3. json       the `Suite` test-suite in `libraries/json/tests`,
 *                 run with `moggi run` once per backend
 *   4. containers the `Suite` test-suite in `libraries/containers/tests`,
 *                 run with `moggi run` once per backend
 *   5. time       the `Suite` test-suite in `libraries/time/tests`,
 *                 run with `moggi run` once per backend
 *   6. filepath   the `Suite` test-suite in `libraries/filepath/tests`,
 *                 run with `moggi run` once per backend
 *
 * Anything besides `--backend` is forwarded to the two `test.php` stages as
 * is (`--jobs`, `--stop-on-failure`, selectors, …); the json stage only takes
 * the backend. Every stage runs even when an earlier one failed, and the exit
 * code is 1 when any stage failed.
 *
 * The json stage resolves the package's host dependencies (jackson on jvm,
 * System.Text.Json on dotnet) the way `moggi build` does and adds the
 * resolved tree as a library root, so those backends need their host tool
 * (maven, dotnet) and the network to fetch them — exactly as building a
 * package that declares them would. It also adds the bundled containers
 * sources, which the json package depends on for `Data.Map`.
 *
 * The containers stage needs no host dependencies: the package is pure
 * Moggi, so every backend runs the same command.
 */

const MOGGI_PROJECT_ROOT = __DIR__;

if (\getenv('MOGGI_CACHE_DIR') === false && \getenv('MOGGI_NO_CACHE') === false) {
    \putenv('MOGGI_CACHE_DIR=' . MOGGI_PROJECT_ROOT . '/.moggi');
}

require MOGGI_PROJECT_ROOT . '/src/compiler.php';

use function Moggi\Registry\hostToolCoordinates;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\resolveHostTools;

/** @return array{backends: list<string>, forward: list<string>} */
function parseAllTestArgs(array $argv): array
{
    $backends = [];
    $forward = [];
    $help = false;

    for ($i = 1, $count = \count($argv); $i < $count; ++$i) {
        $arg = $argv[$i];
        if ($arg === '-h' || $arg === '--help') {
            $help = true;

            continue;
        }
        $backendValue = null;
        if ($arg === '--backend') {
            $backendValue = $argv[$i + 1] ?? null;
            if ($backendValue === null || $backendValue === '') {
                \fwrite(STDERR, "error: --backend requires php, jvm, dotnet or all\n");
                exit(2);
            }
            ++$i;
        } elseif (\str_starts_with($arg, '--backend=')) {
            $backendValue = \substr($arg, \strlen('--backend='));
            if ($backendValue === '') {
                \fwrite(STDERR, "error: --backend requires php, jvm, dotnet or all\n");
                exit(2);
            }
        }
        if ($backendValue !== null) {
            foreach (\explode(',', $backendValue) as $one) {
                $one = \trim($one);
                if ($one === 'all') {
                    foreach (['php', 'jvm', 'dotnet'] as $backend) {
                        if (! \in_array($backend, $backends, true)) {
                            $backends[] = $backend;
                        }
                    }

                    continue;
                }
                if (! \in_array($one, ['php', 'jvm', 'dotnet'], true)) {
                    \fwrite(STDERR, "error: unknown backend `{$one}` (expected php, jvm, dotnet or all)\n");
                    exit(2);
                }
                if (! \in_array($one, $backends, true)) {
                    $backends[] = $one;
                }
            }

            continue;
        }
        $forward[] = $arg;
    }

    if ($help) {
        \fwrite(STDERR, allTestUsage());
        exit(0);
    }

    return ['backends' => $backends === [] ? ['php'] : $backends, 'forward' => $forward];
}

function allTestUsage(): string
{
    return <<<'TXT'
usage: test-all [--backend php|jvm|dotnet|all] [test.php options...]

Run everything, one stage after the other: the compiler suite without the
base group, the base group on its own, and the json, containers and time
packages' Suites once per backend. Options besides --backend are forwarded to
the test.php stages.

Examples
  php test-all.php
  php test-all.php --backend all
  php test-all.php --backend php --jobs 4

TXT;
}

/** Run a stage, echoing what it is; return its exit code. */
function runStage(string $title, array $argv): int
{
    echo "=== {$title}\n";
    \flush();

    // One pipe with stderr merged into it, streamed live. Inheriting a
    // redirected stdout lets concurrent workers overwrite each other's bytes
    // through the shared file offset, which garbles the log and can hide the
    // very diagnostic a failed stage printed.
    $command = \implode(' ', \array_map('escapeshellarg', $argv)) . ' 2>&1';
    $process = \proc_open($command, [0 => STDIN, 1 => ['pipe', 'w']], $pipes);
    if (! \is_resource($process)) {
        \fwrite(STDERR, "error: cannot start {$title}\n");

        return 1;
    }

    \fpassthru($pipes[1]);
    \fclose($pipes[1]);

    return (int) \proc_close($process);
}

/** Resolve json's host dependencies for one backend; null when there are none. */
function jsonToolRoot(string $backend): ?string
{
    $descriptor = readDescriptor(MOGGI_PROJECT_ROOT . '/libraries/json/json.moggi');
    $coordinates = hostToolCoordinates($descriptor, $backend);
    if ($coordinates === []) {
        return null;
    }

    $resolved = resolveHostTools($backend, $coordinates, 'json', true);
    if (! ($resolved['ok'] ?? false)) {
        \fwrite(STDERR, 'error: cannot resolve json host dependencies for ' . $backend . ': '
            . ($resolved['detail'] ?? 'unknown error') . "\n");
        exit(1);
    }

    $dir = $resolved['dir'] ?? null;

    return \is_string($dir) && $dir !== '' ? $dir : null;
}

$parsed = parseAllTestArgs($argv);
$backends = $parsed['backends'];
$php = PHP_BINARY;

$failed = [];

// Stage 1: the compiler suite without the standard library's own tests.
$compiler = [$php, MOGGI_PROJECT_ROOT . '/test.php', '--backend', \implode(',', $backends),
    '--exclude-group', 'base', ...$parsed['forward']];
if (runStage('compiler', $compiler) !== 0) {
    $failed[] = 'compiler';
}

// Stage 2: the standard library's own tests, on their own.
$base = [$php, MOGGI_PROJECT_ROOT . '/test.php', '--backend', \implode(',', $backends),
    '--group', 'base', ...$parsed['forward']];
if (runStage('base', $base) !== 0) {
    $failed[] = 'base';
}

// Stage 3: the json package's Suite, once per backend.
foreach ($backends as $backend) {
    $toolRoot = $backend === 'php' ? null : jsonToolRoot($backend);
    $json = [$php, MOGGI_PROJECT_ROOT . '/moggi.php', 'run',
        'libraries/json/tests/Suite.mog',
        '--lib', 'libraries/json/src',
        '--lib', 'libraries/json/tests',
        '--lib', 'libraries/containers/src',
        '--lib', 'libraries/time/src'];
    if ($toolRoot !== null) {
        $json[] = '--lib';
        $json[] = $toolRoot;
    }
    $json[] = '--backend';
    $json[] = $backend;
    if (runStage("json ({$backend})", $json) !== 0) {
        $failed[] = "json ({$backend})";
    }
}

// Stage 4: the containers package's Suite, once per backend.
foreach ($backends as $backend) {
    $containers = [$php, MOGGI_PROJECT_ROOT . '/moggi.php', 'run',
        'libraries/containers/tests/Suite.mog',
        '--lib', 'libraries/containers/src',
        '--lib', 'libraries/containers/tests',
        '--lib', 'libraries/array/src',
        '--backend',
        $backend];
    if (runStage("containers ({$backend})", $containers) !== 0) {
        $failed[] = "containers ({$backend})";
    }
}

// Stage 5: the time package's Suite, once per backend.
foreach ($backends as $backend) {
    $time = [$php, MOGGI_PROJECT_ROOT . '/moggi.php', 'run',
        'libraries/time/tests/Suite.mog',
        '--lib', 'libraries/time/src',
        '--lib', 'libraries/time/tests',
        '--backend',
        $backend];
    if (runStage("time ({$backend})", $time) !== 0) {
        $failed[] = "time ({$backend})";
    }
}

// Stage 6: the filepath package's Suite, once per backend.
foreach ($backends as $backend) {
    $filepath = [$php, MOGGI_PROJECT_ROOT . '/moggi.php', 'run',
        'libraries/filepath/tests/Suite.mog',
        '--lib', 'libraries/filepath/src',
        '--lib', 'libraries/filepath/tests',
        '--backend',
        $backend];
    if (runStage("filepath ({$backend})", $filepath) !== 0) {
        $failed[] = "filepath ({$backend})";
    }
}

if ($failed !== []) {
    echo "\nALL TESTS: failed (" . \implode(', ', $failed) . ")\n";
    exit(1);
}

echo "\nALL TESTS: passed (compiler, base, json, containers, time, filepath on " . \implode(', ', $backends) . ")\n";
exit(0);
