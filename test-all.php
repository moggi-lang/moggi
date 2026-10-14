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
 *   3. json       the `Suite` test-suite in `libraries/json/tests`, run with
 *                 `moggi run` once per backend
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
 * package that declares them would.
 */

const MOGGI_PROJECT_ROOT = __DIR__;

if (getenv('MOGGI_CACHE_DIR') === false && getenv('MOGGI_NO_CACHE') === false) {
    putenv('MOGGI_CACHE_DIR=' . MOGGI_PROJECT_ROOT . '/.moggi');
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
        if ($arg === '--backend') {
            $value = $argv[$i + 1] ?? null;
            if ($value === null || $value === '') {
                \fwrite(STDERR, "error: --backend requires php, jvm, dotnet or all\n");
                exit(2);
            }
            ++$i;
            foreach (\explode(',', $value) as $one) {
                $one = \trim($one);
                if ($one === 'all') {
                    foreach (['php', 'jvm', 'dotnet'] as $backend) {
                        if (!\in_array($backend, $backends, true)) {
                            $backends[] = $backend;
                        }
                    }
                    continue;
                }
                if (!\in_array($one, ['php', 'jvm', 'dotnet'], true)) {
                    \fwrite(STDERR, "error: unknown backend `{$one}` (expected php, jvm, dotnet or all)\n");
                    exit(2);
                }
                if (!\in_array($one, $backends, true)) {
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
base group, the base group on its own, and the json package's Suite once per
backend. Options besides --backend are forwarded to the test.php stages.

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

    $process = \proc_open(\implode(' ', \array_map('escapeshellarg', $argv)), [STDIN, STDOUT, STDERR], $pipes);
    if (!\is_resource($process)) {
        \fwrite(STDERR, "error: cannot start {$title}\n");

        return 1;
    }

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
    if (!($resolved['ok'] ?? false)) {
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
        '--lib', 'libraries/json/tests'];
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

if ($failed !== []) {
    echo "\nALL TESTS: failed (" . \implode(', ', $failed) . ")\n";
    exit(1);
}

echo "\nALL TESTS: passed (compiler, base, json on " . \implode(', ', $backends) . ")\n";
exit(0);
