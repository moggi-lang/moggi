#!/usr/bin/env php
<?php declare(strict_types=1);

// The release manifest's scope: provenance, artifact, version, and now the
// digest of the verbatim descriptor and of the rendered docs tree. The exact
// bytes are pinned here as goldens, because the client (this PHP), the worker
// (`manifest.ts`) and the website (`verify.js`) must build them identically or a
// signature made by one will not verify under another. The signing checks need
// the real `schnorr` CLI; without it they are skipped rather than faked.

$root = __DIR__;
while (!is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Registry\releaseMessage;
use function Moggi\Registry\verifyRelease;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$digest = static fn (string $char): string => 'sha256:' . \str_repeat($char, 64);
$base = [
    'blob' => $digest('a'),
    'source' => ['kind' => 'git', 'url' => 'https://example.test/x.git', 'commit' => \str_repeat('b', 40)],
    'third_party' => [],
];
$descriptor = "[package]\nname = x\nversion = 1.0.0\n";
$full = $base + ['descriptor' => $descriptor, 'docs' => $digest('c')];

// --- canonical bytes, as every implementation must agree on them -------------
$assert(
    releaseMessage('x', '1.0.0', $base) === '74d7caa79c6322b49d3f445c0713e80ccbf91da414e9203079eda67e0fcc4aee',
    'a release with no descriptor/docs hashes without those members',
);
$assert(
    releaseMessage('x', '1.0.0', $base + ['docs' => $digest('c')]) === '154e0f7598de69206adf5038823c9f3fb61af64ef531448cb711d318f45f8973',
    'the docs digest is covered',
);
$assert(
    releaseMessage('x', '1.0.0', $full) === 'a38ee89e3a49be76704a5b128f39d0e284c1f37fd329bc5f9b181b2acf9ed85e',
    'the descriptor digest is covered',
);

// --- either digest moving changes the message --------------------------------
$assert(releaseMessage('x', '1.0.0', \array_merge($full, ['docs' => $digest('d')])) !== releaseMessage('x', '1.0.0', $full), 'a different docs digest is a different message');
$assert(releaseMessage('x', '1.0.0', ['descriptor' => $descriptor . 'x'] + $base + ['docs' => $digest('c')]) !== releaseMessage('x', '1.0.0', $full), 'a different descriptor is a different message');
$assert(releaseMessage('x', '1.0.1', $full) !== releaseMessage('x', '1.0.0', $full), 'the version is still covered');

// --- sign and verify with a throwaway key, if the CLI is here ----------------
$binary = \getenv('MOGGI_SCHNORR');
if (!\is_string($binary) || $binary === '') {
    $binary = $root . '/schnorr/schnorr';
}
if (!\is_file($binary) || !\is_executable($binary)) {
    echo "  (skipped the signing checks: no schnorr CLI at {$binary})\n";
} else {
    \putenv('MOGGI_SCHNORR=' . $binary);
    $schnorr = static function (array $arguments, ?string $stdin = null) use ($binary): string {
        $process = \proc_open(
            \array_merge([$binary], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
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

    $signed = $full;
    $signed['signature'] = ['npub' => $npub, 'sig' => $schnorr(['sign', releaseMessage('x', '1.0.0', $full)], $nsec)];

    $assert(verifyRelease('x', '1.0.0', $signed, [$npub])['ok'], 'a release signed over the extended manifest verifies');

    $tampered = $signed;
    $tampered['docs'] = $digest('0');
    $assert(!verifyRelease('x', '1.0.0', $tampered, [$npub])['ok'], 'tampering the docs digest breaks the signature');

    $tampered = $signed;
    $tampered['descriptor'] .= 'x';
    $assert(!verifyRelease('x', '1.0.0', $tampered, [$npub])['ok'], 'tampering the descriptor breaks the signature');

    $assert(
        !verifyRelease('x', '1.0.0', $signed, ['npub1' . \str_repeat('q', 58)])['ok'],
        'a signer not on the allowed list is refused',
    );
}

echo "manifest tests passed ({$checks} checks)\n";
