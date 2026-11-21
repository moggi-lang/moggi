<?php declare(strict_types=1);

/**
 * A key file is read only while nobody but its owner (and the machine's root)
 * can read it — on Windows, where there are no mode bits to inspect.
 *
 * The decision is a function of the ACL listing rather than of the filesystem, so
 * the policy is pinned here on every platform: `Everyone`, `Users` and
 * `Authenticated Users` are the Windows spelling of `other` and `group`, a rule
 * whose SID cannot be resolved is not private, and a listing with no owner line
 * is not something to trust. The round trip at the end is the part that needs a
 * real machine.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/keyfile.php';

use function Moggi\Compiler\findExecutable;
use function Moggi\Registry\readNsecKey;
use function Moggi\Registry\windowsKeyAclIsPrivate;

$checks = 0;
$is = static function (bool $expected, string $listing, string $what) use (&$checks): void {
    ++$checks;
    $actual = windowsKeyAclIsPrivate($listing);
    if ($actual === $expected) {
        return;
    }

    throw new \RuntimeException(\sprintf(
        '%s: expected %s, got %s for %s',
        $what,
        $expected ? 'private' : 'not private',
        $actual ? 'private' : 'not private',
        $listing === '' ? 'an empty listing' : \json_encode($listing),
    ));
};

$me = 'S-1-5-21-11-22-33-1001';
$private = "user {$me}\n{$me}\nS-1-5-18\nS-1-5-32-544";

$is(true, $private, 'the owner, SYSTEM and Administrators is private');
$is(true, "user {$me}\n{$me}", 'a file only its owner and root may touch is private');
$is(true, "user {$me}\r\n{$me}\r\nS-1-5-18", 'CRLF line endings from PowerShell are read');
$is(true, "user {$me}\n{$me}\nS-1-3-4", 'OWNER RIGHTS is the owner');
$is(false, $private . "\nS-1-1-0", 'Everyone is `other`');
$is(false, $private . "\nS-1-5-32-545", 'Users is `group`');
$is(false, $private . "\nS-1-5-11", 'Authenticated Users is `group`');
$is(false, "user {$me}\n{$me}\nS-1-5-21-11-22-33-1002", 'a second account is a stranger');
$is(false, "user {$me}\nunresolved", 'a rule whose SID cannot be resolved is not private');
$is(false, '', 'an empty listing is not private');
$is(false, "{$me}\nS-1-5-18", 'a listing without an owner line is not trusted');
$is(false, "user {$me}", 'a listing with no rule at all is not trusted');

if (\PHP_OS_FAMILY !== 'Windows') {
    echo "nsec ACL tests passed ({$checks} checks; the ACL read itself needs Windows)\n";

    return;
}

$work = createTempDir('moggi-nsec-acl');
$key = $work . '/key.nsec';
$refusal = static function (string $path): string {
    try {
        readNsecKey($path);
    } catch (\Throwable $e) {
        return (string) $e->getMessage();
    }

    return '';
};

try {
    writePrivateKeyFile($key, "nsec1invalid\n");

    $message = $refusal($key);
    if (! \str_contains($message, 'does not hold an nsec')) {
        throw new \RuntimeException(
            'a private key file must reach key validation, got: '
            . ($message === '' ? 'no refusal' : $message),
        );
    }

    // `icacls` lives in System32. That is on PATH for a normal shell but not always for a CI
    // service process, and silently skipping the grant made this test assert on the ACL of a file
    // whose ACL was never changed. Resolve it from the Windows directory too, and say so instead of
    // passing when the tool is genuinely absent.
    $icacls = findExecutable('icacls');
    if ($icacls === null) {
        $systemRoot = \getenv('SystemRoot') ?: \getenv('windir');
        $candidate = \is_string($systemRoot) && $systemRoot !== ''
            ? \rtrim($systemRoot, '\\/') . '\\System32\\icacls.exe'
            : '';
        $icacls = $candidate !== '' && \is_file($candidate) ? $candidate : null;
    }
    if ($icacls === null) {
        echo "nsec ACL tests passed ({$checks} checks; `icacls` is unavailable, so the ACL read itself was not exercised)\n";

        return;
    }

    $grant = runCompiledProcess([$icacls, $key, '/grant', '*S-1-1-0:R'], 30);
    if ($grant['exitCode'] !== 0) {
        throw new \RuntimeException(
            'the test could not grant `Everyone` read access: ' . $grant['stdout'] . $grant['stderr'],
        );
    }

    $message = $refusal($key);
    if (! \str_contains($message, 'is not private')) {
        throw new \RuntimeException(
            'a key file the whole machine can read must be refused on its ACL, got: '
            . ($message === '' ? 'no refusal' : $message),
        );
    }

    echo "nsec ACL tests passed ({$checks} checks)\n";
} finally {
    removeDirectory($work);
}
