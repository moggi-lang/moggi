#!/usr/bin/env php
<?php declare(strict_types=1);

// The module cache must be transparent: compiling the same source twice, or once through a warm
// cache and once through a cold one, has to lower to the same IR. The program is deliberately
// signature-free — with a signature the operand types are fixed before the operator is reached,
// and that is where the two passes can disagree.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/assert.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$work = createTempDir('moggi-cache-determinism');
$source = $work . '/Determinism.mog';
\file_put_contents($source, <<<'MOG'
module Determinism where

add x y = x + y
mul x y = x * y
neg x = 0 - x
MOG . "\n");

$dumpIr = static function (string $cacheDir) use ($source, $root): array {
    $env = \getenv();
    $env['MOGGI_CACHE_DIR'] = $cacheDir;
    // The module cache is what differs between the runs; keep everything else equal.
    unset($env['MOGGI_NO_CACHE']);

    return runCompiledProcess([PHP_BINARY, $root . '/moggi.php', 'compile', $source, '--ir'], 60, $env, $root);
};

try {
    $runs = [
        'first (cold)' => $dumpIr($work . '/cache-a'),
        'second (warm)' => $dumpIr($work . '/cache-a'),
        'other cache dir (cold)' => $dumpIr($work . '/cache-b'),
    ];
} finally {
    removeDirectory($work);
}

foreach ($runs as $label => $result) {
    $assert(($result['exitCode'] ?? 0) === 0, "{$label}: compilation failed: " . \trim((string) ($result['stderr'] ?? '')));
    $assert(\str_contains((string) $result['stdout'], 'function add'), "{$label}: IR dump has no `add` function:\n" . $result['stdout']);
}

$cold = (string) $runs['first (cold)']['stdout'];
$assert(
    $cold === (string) $runs['second (warm)']['stdout'],
    "IR differs between a cold and a warm module cache — the cache must be transparent:\n" . diffText($cold, (string) $runs['second (warm)']['stdout']),
);
$assert(
    $cold === (string) $runs['other cache dir (cold)']['stdout'],
    "IR differs between two cold caches:\n" . diffText($cold, (string) $runs['other cache dir (cold)']['stdout']),
);

echo "cache determinism tests passed\n";
