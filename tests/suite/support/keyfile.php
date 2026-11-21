<?php declare(strict_types=1);

/**
 * Write a secret key file that `readNsecKey()` accepts on this host.
 *
 * POSIX gets a plain `chmod 0600`. Windows has no mode bits to set: PHP reports `0666` for every
 * file and `chmod()` moves a read-only attribute, so there is nothing to restrict and the client
 * does not check one there either.
 */
function writePrivateKeyFile(string $path, string $contents): void
{
    if (\file_put_contents($path, $contents) === false) {
        throw new \RuntimeException("the test could not write {$path}");
    }

    if (\PHP_OS_FAMILY !== 'Windows') {
        \chmod($path, 0600);
    }
}
