<?php declare(strict_types=1);

/**
 * Resolve `schnorr` from fixed locations, never `PATH`, so source checkouts
 * can verify signed registries without configuration.
 *
 * Cover the checkout's `schnorr/schnorr` build output, not the unused
 * `build/` directory; that no variable, no `PATH` entry and no path a caller
 * names can move the lookup; and an actionable failure message listing the
 * searched locations and how to fix it.
 */

$root = __DIR__;
while (! \is_file($root . '/src/registry/schnorr.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Registry\schnorrBinary;
use function Moggi\Registry\schnorrCandidatePaths;
use function Moggi\Registry\schnorrMissingNote;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
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
    ! \in_array($checkout . $separator . 'schnorr' . $separator . 'build' . $separator . $name, $candidates, true),
    'the build directory never holds the binary and must not be a candidate',
);

$work = \sys_get_temp_dir() . '/moggi-schnorr-' . \bin2hex(\random_bytes(6));
\mkdir($work . '/bin', 0777, true);
$remove = static function (string $path) use (&$remove): void {
    if (! \file_exists($path) && ! \is_link($path)) {
        return;
    }
    if (\is_dir($path) && ! \is_link($path)) {
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
    if (\PHP_OS_FAMILY !== 'Windows') {
        // The lookup is fixed: an executable the caller names or drops in the working
        // directory is never a candidate, whatever it is called and however it is
        // reachable.
        $elsewhere = $work . '/elsewhere/' . $name;
        \mkdir($work . '/elsewhere', 0777, true);
        \file_put_contents($elsewhere, "#!/bin/sh\necho forged\n");
        \chmod($elsewhere, 0755);
        $assert(
            ! \in_array($elsewhere, schnorrCandidatePaths(), true),
            'a binary outside the installation is never a candidate',
        );

        // A `schnorr` on `PATH` is not the verifier either.
        $savedPath = (string) \getenv('PATH');
        \putenv('PATH=' . $work . '/elsewhere');
        $found = schnorrBinary();
        \putenv('PATH=' . $savedPath);
        $assert(
            $found !== $elsewhere,
            'a `schnorr` on PATH must not be used: ' . \var_export($found, true),
        );
    }

    // --- the failure note names where it looked and how to fix it -------------
    $note = schnorrMissingNote();
    $assert(\str_contains($note, 'no bundled'), 'the note says no bundled binary was found: ' . $note);
    $assert(\str_contains($note, $expected), 'the note lists the checkout location it tried: ' . $note);
    $assert(\str_contains($note, 'make -C schnorr'), 'the note says how to build it in a checkout');
    $assert(! \str_contains($note, 'MOGGI_'), 'the note names no environment variable: ' . $note);

    echo "schnorr discovery tests passed ({$checks} checks)\n";
} finally {
    $remove($work);
}
