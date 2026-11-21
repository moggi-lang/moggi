<?php declare(strict_types=1);

use function Moggi\Compiler\findExecutable;
use function Moggi\Install\platformPath;

require_once __DIR__ . '/process.php';

/**
 * Write a secret key file that `readNsecKey()` accepts on this host.
 *
 * POSIX gets a plain `chmod 0600`. Windows has no mode bits to set: PHP reports `0666` for every
 * file and `chmod()` moves a read-only attribute, so a key written into a temporary directory keeps
 * whatever that directory grants, which on a CI runner is not necessarily its owner alone. The
 * client reads an ACL there, so the test has to write one: `icacls` removes the inherited rules and
 * grants the file to the account running the test — the Windows spelling of "the owner, nobody
 * else", and the same set `chmod 600` leaves behind.
 *
 * Failing to restrict the file is a test failure, not a skip: a case that signs with a key it could
 * not protect would otherwise assert on a file whose ACL was never changed.
 */
function writePrivateKeyFile(string $path, string $contents): void
{
    if (\file_put_contents($path, $contents) === false) {
        throw new \RuntimeException("the test could not write {$path}");
    }

    if (\PHP_OS_FAMILY !== 'Windows') {
        \chmod($path, 0600);

        return;
    }

    // `icacls` lives in System32, which is on PATH for a normal shell but not always for a CI
    // service process; resolve it from the Windows directory too.
    $icacls = findExecutable('icacls');
    if ($icacls === null) {
        $systemRoot = \getenv('SystemRoot') ?: \getenv('windir');
        $candidate = \is_string($systemRoot) && $systemRoot !== ''
            ? \rtrim($systemRoot, '\\/') . '\\System32\\icacls.exe'
            : '';
        $icacls = $candidate !== '' && \is_file($candidate) ? $candidate : null;
    }
    if ($icacls === null) {
        throw new \RuntimeException('the test cannot make a key file private: `icacls` is unavailable');
    }

    $restricted = runCompiledProcess(
        [$icacls, platformPath($path), '/inheritance:r', '/grant:r', privateKeyGrantee()],
        30,
    );
    if ($restricted['exitCode'] !== 0) {
        throw new \RuntimeException(
            "the test could not restrict {$path} to its owner: "
                . \trim($restricted['stdout'] . $restricted['stderr']),
        );
    }
}

/**
 * The account `icacls` should hand a test key to: this one.
 *
 * `OWNER RIGHTS` (`S-1-3-4`) is the faithful spelling of "the owner, nobody else", and it is what
 * the client itself accepts, but it is a well-known SID some `icacls` builds decline to resolve —
 * and a grant that quietly changes nothing leaves the file readable by the whole temporary
 * directory. The account the test already runs as is always resolvable.
 */
function privateKeyGrantee(): string
{
    $user = \getenv('USERNAME');
    if (\is_string($user) && \trim($user) !== '') {
        return \trim($user) . ':F';
    }

    return '*S-1-3-4:F';
}
