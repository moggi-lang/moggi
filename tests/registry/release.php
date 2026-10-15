#!/usr/bin/env php
<?php declare(strict_types=1);

// The Step 3 release split: `pack --record`, `moggi sign` and `publish
// --signed`/`--dry-run` share one record shape and one signing path, in
// `src/registry/manifest.php`. These tests pin that shared path offline: the
// record's shape, the author and source selection, the file round trip, and —
// with the real `schnorr` CLI, never a fake — the sign/verify round trip,
// every mismatch `publish --signed` refuses, and the `sign` command's core.
// No network happens here; without the CLI the signing checks are skipped
// rather than faked.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\CLI\Commands\signRecordFile;
use function Moggi\Registry\buildReleaseRecord;
use function Moggi\Registry\canonicalJson;
use function Moggi\Registry\checkSignedRelease;
use function Moggi\Registry\readReleaseRecord;
use function Moggi\Registry\releaseSource;
use function Moggi\Registry\selectAuthor;
use function Moggi\Registry\signRelease;
use function Moggi\Registry\verifyRelease;
use function Moggi\Registry\writeReleaseRecord;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};
$throws = static function (callable $run, string $message) use (&$checks): void {
    ++$checks;

    try {
        $run();
    } catch (\RuntimeException) {
        return;
    }

    throw new \RuntimeException($message);
};

$digest = static fn (string $char): string => 'sha256:' . \str_repeat($char, 64);
$blob = $digest('a');
$descriptor = "[package]\nname = x\nversion = 1.0.0\n";
$source = ['kind' => 'git', 'url' => 'https://example.test/x.git', 'commit' => \str_repeat('b', 40)];

// --- the record's shape ------------------------------------------------------
$record = buildReleaseRecord('x', '1.0.0', $source, $blob, [], $descriptor, null);
$assert(! isset($record['signature']), 'a built record is unsigned');
$assert(! isset($record['docs']), 'no docs digest means no docs member');
$assert($record['descriptor'] === $descriptor, 'the descriptor travels verbatim');
$assert(
    buildReleaseRecord('x', '1.0.0', $source, $blob, [], $descriptor, $digest('c'))['docs'] === $digest('c'),
    'a docs digest is carried when the docs were rendered',
);

// --- the source ---------------------------------------------------------------
$assert(
    releaseSource('https://example.test/x.git', \str_repeat('b', 40), '/tmp/elsewhere', $digest('d'))
        === ['kind' => 'git', 'url' => 'https://example.test/x.git', 'commit' => \str_repeat('b', 40)],
    '--url plus --commit records a git source',
);
$dirSource = releaseSource(null, null, $root, $digest('d'));
$assert(
    $dirSource === ['kind' => 'dir', 'path' => \basename((string) \realpath($root)), 'sha256' => $digest('d')],
    'no url records the packed directory as provenance',
);
$throws(
    static fn () => releaseSource('https://example.test/x.git', null, '/tmp/d', $digest('d')),
    'a url without a commit is refused',
);
$throws(
    static fn () => releaseSource(null, \str_repeat('b', 40), '/tmp/d', $digest('d')),
    'a commit without a url is refused',
);

// --- the author ----------------------------------------------------------------
$assert(selectAuthor(['npub1solo'], null) === 'npub1solo', 'a single author is unambiguous');
$assert(selectAuthor(['npub1a', 'npub1b'], 'npub1b') === 'npub1b', '--as picks among several authors');
$throws(static fn () => selectAuthor(['npub1a', 'npub1b'], null), 'several authors without --as is an error, not a guess');
$throws(static fn () => selectAuthor(['npub1a'], 'npub1stranger'), '--as must name one of the authors');
$throws(static fn () => selectAuthor([], null), 'no authors is an error');

// --- the file round trip ---------------------------------------------------------
$tmp = \sys_get_temp_dir() . '/moggi-release-test-' . \getmypid();
@\mkdir($tmp, 0700, true);
$recordFile = $tmp . '/x-1.0.0.record.json';
writeReleaseRecord($recordFile, $record);
$assert(canonicalJson(readReleaseRecord($recordFile)) === canonicalJson($record), 'a written record reads back identical');
$assert(
    \str_ends_with((string) \file_get_contents($recordFile), "\n"),
    'the record file ends in a newline',
);

\file_put_contents($tmp . '/broken.json', '{"name": "x",');
$throws(static fn () => readReleaseRecord($tmp . '/broken.json'), 'invalid JSON is refused');
\file_put_contents($tmp . '/list.json', '["x", "1.0.0"]');
$throws(static fn () => readReleaseRecord($tmp . '/list.json'), 'a JSON list is not a record');
\file_put_contents($tmp . '/nameless.json', '{"version": "1.0.0"}');
$throws(static fn () => readReleaseRecord($tmp . '/nameless.json'), 'a record without a name is refused');
$throws(static fn () => readReleaseRecord($tmp . '/missing.json'), 'an unreadable file is refused');

// --- sign and verify with a throwaway key, if the CLI is here -------------------
$binary = \getenv('MOGGI_SCHNORR');
if (! \is_string($binary) || $binary === '') {
    $binary = $root . '/schnorr/schnorr';
}
if (! \is_file($binary) || ! \is_executable($binary)) {
    echo "  (skipped the signing checks: no schnorr CLI at {$binary})\n";
} else {
    \putenv('MOGGI_SCHNORR=' . $binary);
    $schnorr = static function (array $arguments, ?string $stdin = null) use ($binary): string {
        $process = \proc_open(
            \array_merge([$binary], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (! \is_resource($process)) {
            throw new \RuntimeException("cannot run {$binary}");
        }
        if ($stdin !== null) {
            \fwrite($pipes[0], $stdin . "\n");
        }
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($process);
        if ($code !== 0) {
            throw new \RuntimeException('schnorr: ' . (\trim($stderr) !== '' ? \trim($stderr) : "exited {$code}"));
        }

        return \trim($stdout);
    };

    [$nsec, $npub] = \preg_split('/\s+/', $schnorr(['generate'])) ?: ['', ''];
    $assert(\str_starts_with($nsec, 'nsec') && \str_starts_with($npub, 'npub'), 'the schnorr CLI generated a keypair');

    $signed = signRelease('x', '1.0.0', $record, $npub, $nsec);
    $assert(($signed['signature']['npub'] ?? null) === $npub, 'signing attaches the author');
    $assert(verifyRelease('x', '1.0.0', $signed, [$npub])['ok'], 'a signed record verifies');

    $throws(
        static fn () => signRelease('x', '1.0.0', $signed, $npub, $nsec),
        'an already-signed record is refused, not re-signed',
    );

    $tampered = $signed;
    $tampered['blob'] = $digest('0');
    $assert(! verifyRelease('x', '1.0.0', $tampered, [$npub])['ok'], 'tampering the blob breaks the signature');

    // --- what `publish --signed` checks --------------------------------------
    $docsDigest = $digest('c');
    $withDocs = buildReleaseRecord('x', '1.0.0', $source, $blob, [], $descriptor, $docsDigest);
    $signedDocs = signRelease('x', '1.0.0', $withDocs, $npub, $nsec);
    $assert(
        checkSignedRelease($signedDocs, 'x', '1.0.0', $blob, $descriptor, $docsDigest, [$npub]) === $npub,
        'a record matching the tree checks out, returning its signer',
    );
    $throws(
        static fn () => checkSignedRelease($signedDocs, 'x', '1.0.0', $digest('0'), $descriptor, $docsDigest, [$npub]),
        'a record over a different blob is refused',
    );
    $throws(
        static fn () => checkSignedRelease($signedDocs, 'x', '1.0.0', $blob, $descriptor . 'x', $docsDigest, [$npub]),
        'a record over a different descriptor is refused',
    );
    $throws(
        static fn () => checkSignedRelease($signedDocs, 'x', '1.0.0', $blob, $descriptor, $digest('d'), [$npub]),
        'a record over different docs is refused',
    );
    $throws(
        static fn () => checkSignedRelease($signedDocs, 'x', '1.0.0', $blob, $descriptor, null, [$npub]),
        'a record naming docs the tree did not render is refused',
    );
    $throws(
        static fn () => checkSignedRelease($signed, 'x', '1.0.0', $blob, $descriptor, $docsDigest, [$npub]),
        'a record silent about docs the tree rendered is refused',
    );
    $throws(
        static fn () => checkSignedRelease($withDocs, 'x', '1.0.0', $blob, $descriptor, $docsDigest, [$npub]),
        'an unsigned record is refused — sign it first',
    );
    $throws(
        static fn () => checkSignedRelease($signedDocs, 'y', '1.0.0', $blob, $descriptor, $docsDigest, [$npub]),
        'a record for another package is refused',
    );
    $throws(
        static fn () => checkSignedRelease($signedDocs, 'x', '1.0.0', $blob, $descriptor, $docsDigest, ['npub1' . \str_repeat('q', 58)]),
        'a signer outside the allowed list is refused',
    );

    // --- the `sign` command's core: file in, signed record out ----------------
    $keyFile = $tmp . '/author.nsec';
    \file_put_contents($keyFile, $nsec . "\n");
    \chmod($keyFile, 0600);
    $unsignedFile = $tmp . '/unsigned.record.json';
    writeReleaseRecord($unsignedFile, $record);
    $fromFile = signRecordFile($unsignedFile, $npub, $keyFile);
    $assert(verifyRelease('x', '1.0.0', $fromFile, [$npub])['ok'], 'signing a record file verifies');

    $throws(
        static fn () => signRecordFile($unsignedFile, null, $keyFile),
        'signing without --as is refused — a bare record names no authors',
    );
    $signedFile = $tmp . '/signed.record.json';
    writeReleaseRecord($signedFile, $fromFile);
    $throws(
        static fn () => signRecordFile($signedFile, $npub, $keyFile),
        'signing an already-signed file is refused',
    );

    [$otherNsec] = \preg_split('/\s+/', $schnorr(['generate'])) ?: [''];
    $otherKeyFile = $tmp . '/other.nsec';
    \file_put_contents($otherKeyFile, $otherNsec . "\n");
    \chmod($otherKeyFile, 0600);
    $throws(
        static fn () => signRecordFile($unsignedFile, $npub, $otherKeyFile),
        'a key that is not the declared author fails next to the key',
    );
}

// Best effort: the OS reclaims temp files anyway.
foreach (['x-1.0.0.record.json', 'broken.json', 'list.json', 'nameless.json', 'author.nsec', 'other.nsec', 'unsigned.record.json', 'signed.record.json'] as $file) {
    @\unlink($tmp . '/' . $file);
}
@\rmdir($tmp);

echo "release tests passed ({$checks} checks)\n";
