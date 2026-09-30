#!/usr/bin/env php
<?php declare(strict_types=1);

// A module's interface is now a product of its typecheck, published to the cache with it. That
// makes the cache load-bearing in a new way: a warm run reads the checked interface from disk and
// must agree with the cold run that computed it. This pins that across a module boundary with an
// *inferred* constrained export (`tests/semantics/inferred-constrained-export`), where a differing
// interface shows up as a differing dictionary argument at the consumer's call site.

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

$consumer = $root . '/tests/semantics/inferred-constrained-export/Consumer.mog';
$work = createTempDir('moggi-cache-inferred-interface');
try {
    $dumpIr = static function (string $cacheDir) use ($consumer, $root): array {
        $env = \getenv();
        $env['MOGGI_CACHE_DIR'] = $cacheDir;
        // The module cache is what differs between the runs; keep everything else equal.
        unset($env['MOGGI_NO_CACHE']);

        return runCompiledProcess([PHP_BINARY, $root . '/moggi.php', 'compile', $consumer, '--ir'], 60, $env, $root);
    };

    $runs = [
        'first (cold)' => $dumpIr($work . '/cache-a'),
        'second (warm)' => $dumpIr($work . '/cache-a'),
        'other cache dir (cold)' => $dumpIr($work . '/cache-b'),
    ];

    foreach ($runs as $label => $result) {
        $assert(($result['exitCode'] ?? 0) === 0, "{$label}: compilation failed: " . \trim((string) ($result['stderr'] ?? '')));
        $assert(
            \str_contains((string) $result['stdout'], 'renderOne(@__ev_Show_Int'),
            "{$label}: the imported inferred constraint vanished from the consumer's IR:\n" . $result['stdout'],
        );
    }

    $cold = (string) $runs['first (cold)']['stdout'];
    $assert(
        $cold === (string) $runs['second (warm)']['stdout'],
        "IR differs between a cold and a warm module cache across an inferred-constrained boundary:\n"
            . diffText($cold, (string) $runs['second (warm)']['stdout']),
    );
    $assert(
        $cold === (string) $runs['other cache dir (cold)']['stdout'],
        "IR differs between two cold caches:\n" . diffText($cold, (string) $runs['other cache dir (cold)']['stdout']),
    );

    echo "inferred interface cache tests passed\n";
} finally {
    removeDirectory($work);
}
