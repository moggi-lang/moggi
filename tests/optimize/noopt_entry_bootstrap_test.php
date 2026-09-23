#!/usr/bin/env php
<?php declare(strict_types=1);

// `--no-opt` must still produce a runnable program: the optimizer is not what writes the entry
// bootstrap, so the unoptimized PHP source has to keep the uncaught-report try/catch and the
// `main()` call, and the program has to run.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
\define('MOGGI_PROJECT_ROOT', $root);
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/bootstrap.php';

$fixture = __DIR__ . '/noopt-entry-bootstrap.mog';

$compiled = \Moggi\Compiler\compileFileCapturingErrors($fixture, 'php', false, 'php');
if (($compiled['exitCode'] ?? 1) !== 0) {
    \fwrite(\STDERR, "compile failed:\n" . $compiled['output'] . "\n");
    exit(1);
}

$source = (string) $compiled['output'];
// The bootstrap calls into the runtime, so the module that carries it has to
// require the runtime even when none of its own code does.
foreach (['reportUncaught', 'main();', '_runtime.php'] as $marker) {
    if (!\str_contains($source, $marker)) {
        \fwrite(\STDERR, "unoptimized output is missing the entry bootstrap `{$marker}`\n");
        exit(1);
    }
}

$dir = createTempDir('moggi-noopt');
try {
    $entry = $dir . '/Main.php';
    // The unspecialized stdlib requires are relative paths into the source tree; point them at the
    // published lib build so the program runs out of the temp directory.
    \file_put_contents($entry, rewriteLibRequires($source, writeLibPhpOutputs($root), $entry));
    $run = runCompiledProcess([PHP_BINARY, $entry], 60, null, $root);
    if (($run['exitCode'] ?? 1) !== 0) {
        \fwrite(\STDERR, "unoptimized program exited {$run['exitCode']}:\n" . \trim((string) ($run['stderr'] ?? '')) . "\n");
        exit(1);
    }
} finally {
    removeDirectory($dir);
}
