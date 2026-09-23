#!/usr/bin/env php
<?php declare(strict_types=1);

// Random input must never hang or crash the frontend: every generated input ends in output or a
// diagnostic. One fixed corpus pinned forever, one from a different seed so it cannot decay into
// "600 copies of the same shape".

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/assert.php';
require $root . '/tests/suite/support/fuzz.php';

\Moggi\Modules\setStdlibLibPath($root . '/lib');

$failures = [];
$notes = [];

foreach ([0x5eed, 0xc0ffee] as $seed) {
    $outcome = fuzzFrontendSmoke($root, $seed, 600);
    if ($outcome['passed'] !== true) {
        $failures[] = $outcome['message'];
        continue;
    }
    $notes[] = $outcome['message'];
}

if ($failures !== []) {
    \fwrite(\STDERR, \implode("\n\n", $failures) . "\n");
    exit(1);
}

echo \implode("\n", $notes) . "\n";
