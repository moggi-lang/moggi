<?php declare(strict_types=1);

/**
 * A secret key file that group or other can read is refused, on every
 * filesystem that reports POSIX mode bits.
 *
 * The check is the only thing between a `chmod 644` accident and a signing key
 * on a shared machine, so it is pinned here rather than left to the registry
 * cases that happen to create their keys with the right mode: one file at 0600
 * must reach key validation (the gate let it through), and the same file at 0644
 * must fail on its mode and nothing else.
 *
 * Windows reports `0666` for every file, so the check is skipped there and this
 * case skips with it — the platform's own ACLs are what protects the key.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

use function Moggi\Registry\readNsecKey;
use function Moggi\Registry\reportsPosixModeBits;

$refusal = static function (string $path): string {
    try {
        readNsecKey($path);
    } catch (\Throwable $e) {
        return (string) $e->getMessage();
    }

    return '';
};

if (! reportsPosixModeBits()) {
    echo "nsec mode tests skipped: this filesystem reports no POSIX permission bits\n";

    return;
}

$work = createTempDir('moggi-nsec-mode');
$key = $work . '/key.nsec';

try {
    \file_put_contents($key, "nsec1invalid\n");

    \chmod($key, 0600);
    \clearstatcache(true, $key);
    $mode = \fileperms($key) & 0o777;
    if ($mode !== 0600) {
        throw new \RuntimeException(
            \sprintf('the test could not put the key at 0600 (it is %04o)', $mode),
        );
    }
    $message = $refusal($key);
    if (! \str_contains($message, 'does not hold an nsec')) {
        throw new \RuntimeException(
            'a 0600 key file must reach key validation, got: '
            . ($message === '' ? 'no refusal' : $message),
        );
    }

    \chmod($key, 0644);
    \clearstatcache(true, $key);
    $message = $refusal($key);
    if (! \str_contains($message, 'must not be readable by group or other')) {
        throw new \RuntimeException(
            'a group-readable key file must be refused on its mode, got: '
            . ($message === '' ? 'no refusal' : $message),
        );
    }
    if (! \str_contains($message, 'chmod 600')) {
        throw new \RuntimeException(
            'the refusal must say how to fix the mode, got: ' . $message,
        );
    }

    echo "nsec mode tests passed\n";
} finally {
    \chmod($key, 0600);
    removeDirectory($work);
}
