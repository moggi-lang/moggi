#!/usr/bin/env php
<?php declare(strict_types=1);

// `schnorr` is resolved from a fixed set of locations and never from `PATH`, so a
// source checkout can verify signed registries with no configuration. These
// checks cover that the locations include a checkout's own build output
// (`schnorr/schnorr`, what `make` writes) and not a `build/` directory that never
// holds it, that an explicit `MOGGI_SCHNORR` override wins and a missing one does
// not fall through to a bundled copy, and that the failure note names where it
// looked and how to fix it.

$root = __DIR__;
while (!is_file($root . '/src/registry/schnorr.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Registry\schnorrBinary;
use function Moggi\Registry\schnorrCandidatePaths;
use function Moggi\Registry\schnorrMissingNote;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$name = \PHP_OS_FAMILY === 'Windows' ? 'schnorr.exe' : 'schnorr';
$separator = \DIRECTORY_SEPARATOR;

// --- a checkout's own build is a known location -----------------------------
$checkout = \dirname(__DIR__, 2);
$expected = $checkout . $separator . 'schnorr' . $separator . $name;
$candidates = schnorrCandidatePaths();
$assert(
    \in_array($expected, $candidates, true),
    "the checkout's build output must be a candidate: " . \implode(', ', $candidates),
);
$assert(
    !\in_array($checkout . $separator . 'schnorr' . $separator . 'build' . $separator . $name, $candidates, true),
    'the build directory never holds the binary and must not be a candidate',
);

$work = \sys_get_temp_dir() . '/moggi-schnorr-' . \bin2hex(\random_bytes(6));
\mkdir($work . '/bin', 0777, true);
$remove = static function (string $path) use (&$remove): void {
    if (!\file_exists($path) && !\is_link($path)) {
        return;
    }
    if (\is_dir($path) && !\is_link($path)) {
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        @\rmdir($path);

        return;
    }
    @\unlink($path);
};

try {
    // A set-but-missing override is a refusal, never a fall-through to a bundled
    // copy: the caller asked for a specific binary, and silently using another
    // one would hide the misconfiguration.
    \putenv('MOGGI_SCHNORR=' . $work . '/no-such-schnorr');
    $assert(schnorrBinary() === null, 'a missing MOGGI_SCHNORR must not fall back to a bundled copy');

    if (\PHP_OS_FAMILY !== 'Windows') {
        $named = $work . '/schnorr';
        \file_put_contents($named, "#!/bin/sh\necho valid\n");
        \chmod($named, 0755);
        \putenv('MOGGI_SCHNORR=' . $named);
        $assert(schnorrBinary() === $named, 'an executable MOGGI_SCHNORR must be used');

        // A distribution puts the binary at `<root>/bin/schnorr`, which is what
        // `MOGGI_ROOT` names when it is set outside the checkout.
        $bundled = $work . '/bin/' . $name;
        \file_put_contents($bundled, "#!/bin/sh\necho valid\n");
        \chmod($bundled, 0755);
        \putenv('MOGGI_SCHNORR');
        $savedRoot = \getenv('MOGGI_ROOT');
        \putenv('MOGGI_ROOT=' . $work);
        $assert(schnorrBinary() === $bundled, 'a binary under MOGGI_ROOT/bin must be found');
        \putenv($savedRoot === false ? 'MOGGI_ROOT' : 'MOGGI_ROOT=' . $savedRoot);
    }

    // --- the failure note names where it looked and how to fix it -------------
    \putenv('MOGGI_SCHNORR=' . $work . '/no-such-schnorr');
    $note = schnorrMissingNote();
    $assert(\str_contains($note, 'no bundled'), 'the note says no bundled binary was found: ' . $note);
    $assert(\str_contains($note, 'MOGGI_SCHNORR'), 'the note names the override to set');
    $assert(\str_contains($note, $expected), 'the note lists the checkout location it tried: ' . $note);
    $assert(\str_contains($note, 'make -C schnorr'), 'the note says how to build it in a checkout');

    echo "schnorr discovery tests passed ({$checks} checks)\n";
} finally {
    \putenv('MOGGI_SCHNORR');
    $remove($work);
}
