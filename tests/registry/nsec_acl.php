<?php declare(strict_types=1);

/**
 * A key file is read only while nobody but its owner (and the machine's root)
 * can read it — on Windows, where there are no mode bits to inspect.
 *
 * The decision is a function of the ACL text rather than of the filesystem, so
 * the policy is pinned here on every platform: `Everyone`, `Users` and
 * `Authenticated Users` are the Windows spelling of `other` and `group`, a
 * `null` DACL grants the machine everything, an empty one grants nobody, and a
 * string this client cannot read is not something to trust. The round trip at
 * the end is the part that needs a real machine.
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
$is = static function (bool $expected, string $sddl, string $what) use (&$checks): void {
    ++$checks;
    $actual = windowsKeyAclIsPrivate($sddl);
    if ($actual === $expected) {
        return;
    }

    throw new \RuntimeException(\sprintf(
        '%s: expected %s, got %s for %s',
        $what,
        $expected ? 'private' : 'not private',
        $actual ? 'private' : 'not private',
        $sddl === '' ? 'an empty listing' : \json_encode($sddl),
    ));
};

$me = 'S-1-5-21-11-22-33-1001';
$head = "O:{$me}G:{$me}";
$private = $head . 'D:PAI(A;;FA;;;' . $me . ')(A;;FA;;;SY)(A;;FA;;;BA)';

$is(true, $private, 'the owner, SYSTEM and Administrators is private');
$is(true, $head . "D:(A;;FA;;;{$me})(A;;FA;;;OW)", 'OWNER RIGHTS written as a code is the owner');
$is(false, $head . 'D:(A;;FA;;;BA)(A;;FA;;;WD)', 'a code for Everyone is still Everyone');
$is(true, $head . "D:(A;;FA;;;{$me})", 'a file only its owner may touch is private');
$is(true, $head . "D:(A;;FA;;;{$me})(A;;FA;;;S-1-3-4)", 'OWNER RIGHTS is the owner');
$is(true, $head . 'D:', 'an empty DACL grants nobody anything, which is private');
$is(true, $head . "D:(D;;FA;;;WD)(A;;FA;;;{$me})", 'a deny rule for others is not access');
$is(false, $head . 'D:NO_ACCESS_CONTROL', 'a null DACL is Everyone, full control');
$is(false, $head . 'D:(A;;FA;;;WD)', 'Everyone is `other`');
$is(false, $head . 'D:(A;;FA;;;BU)', 'Users is `group`');
$is(false, $head . 'D:(A;;FA;;;AU)', 'Authenticated Users is `group`');
$is(false, $head . 'D:(A;;FA;;;S-1-5-21-11-22-33-1002)', 'a second account is a stranger');
$is(false, $head . "D:(A;;FA;;;{$me})(A;;FA;;;WD)", 'one rule for Everyone is enough to refuse');
$is(false, '', 'an empty listing is not private');
$is(false, "{$me}D:(A;;FA;;;{$me})", 'a listing without an owner is not trusted');

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
