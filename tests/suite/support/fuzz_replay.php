#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Replay one input from the fuzz corpus — the command a failure report prints. Regenerates the
 * corpus from its seed and runs only that input, so a failure can be re-checked without the other
 * 599; a saved reproducer can be replayed instead of a seed/index pair:
 *
 *   php tests/suite/support/fuzz_replay.php 18472931 48291 600
 *   php tests/suite/support/fuzz_replay.php .moggi/fuzz-failures/seed-18472931-input-48291.mog
 *
 * Exits 0 when the input ends in output or a diagnostic, and 1 when it still crashes.
 */

use function Moggi\Modules\setStdlibLibPath;

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

require $root . '/src/compiler.php';
require __DIR__ . '/fuzz.php';

setStdlibLibPath($root . '/lib');

$args = \array_slice($argv, 1);
if (\count($args) === 1 && \is_file($args[0])) {
    $source = (string) \file_get_contents($args[0]);
    $label = $args[0];
} elseif (\count($args) >= 2) {
    $seed = (int) $args[0];
    $index = (int) $args[1];
    $count = (int) ($args[2] ?? 600);
    $corpus = fuzzFrontendCorpus($seed, $count);
    if (!isset($corpus[$index])) {
        \fwrite(\STDERR, "input #{$index} does not exist: seed {$seed} generates {$count} input(s)\n");
        exit(2);
    }
    $source = $corpus[$index];
    $label = \sprintf('seed %d, input #%d of %d', $seed, $index, $count);
} else {
    \fwrite(
        \STDERR,
        "usage: php tests/suite/support/fuzz_replay.php <seed> <index> [count]\n"
            . "       php tests/suite/support/fuzz_replay.php <input.mog>\n",
    );
    exit(2);
}

// The path only reaches diagnostics, but it has to look like a source file the frontend was given.
$path = \sys_get_temp_dir() . '/moggi-fuzz-replay-' . \getmypid() . '.mog';
\file_put_contents($path, $source);

$minimized = '';
try {
    $outcome = fuzzFrontendOutcome($source, $path);
    if ($outcome['kind'] === 'crash') {
        $minimized = fuzzMinimize($source, $path, (string) $outcome['class']);
    }
} finally {
    @\unlink($path);
}

echo $label, "\n--- input ---\n", \rtrim($source), "\n--- outcome ---\n";

if ($outcome['kind'] === 'ok') {
    echo "accepted: lexed, parsed, dumped and type-checked\n";
    exit(0);
}

if ($outcome['kind'] === 'diagnostic') {
    echo \rtrim((string) $outcome['message']), "\n";
    echo "(rejected with a source location, which is the expected outcome)\n";
    exit(0);
}

echo $outcome['class'] . ': ' . $outcome['message'] . "\n";
echo "minimized to:\n", $minimized, "\n";
exit(1);
