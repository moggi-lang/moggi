#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Child of `fuzzFrontendSmoke()`: runs the corpus and reports one JSON document, so the parent can
 * tell "every input ended in output or a diagnostic" from "something crashed" (a fatal leaves no
 * document at all). Each input is written to the work directory before it runs, so a fatal names it.
 */

use function Moggi\Modules\setStdlibLibPath;

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

require $root . '/src/compiler.php';
require __DIR__ . '/fuzz.php';

setStdlibLibPath($root . '/lib');

[$seed, $count, $workDir] = [(int) $argv[1], (int) $argv[2], (string) $argv[3]];

$ok = 0;
$diagnostics = 0;
$spanless = [];
$unexpected = [];
$index = 0;

foreach (fuzzFrontendCorpus($seed, $count) as $source) {
    $path = $workDir . '/input-' . $index . '.mog';
    \file_put_contents($path, $source);

    $outcome = fuzzFrontendOutcome($source, $path);
    if ($outcome['kind'] === 'ok') {
        ++$ok;
    } elseif ($outcome['kind'] === 'diagnostic') {
        ++$diagnostics;
        if (\preg_match('/:\d+:\d+/', (string) $outcome['message']) !== 1) {
            $spanless[] = ['index' => $index, 'message' => $outcome['message'], 'source' => $source];
        }
    } else {
        // Minimised, so the report carries the smallest input that still reproduces it.
        $crashClass = (string) $outcome['class'];
        $unexpected[] = [
            'index' => $index,
            'kind' => $crashClass,
            'message' => $outcome['message'],
            'source' => fuzzMinimize($source, $path, $crashClass),
        ];
    }

    ++$index;
}

echo \json_encode([
    'seed' => $seed,
    'count' => $count,
    'ok' => $ok,
    'diagnostics' => $diagnostics,
    'spanless' => $spanless,
    'unexpected' => $unexpected,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
