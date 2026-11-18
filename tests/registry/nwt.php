<?php declare(strict_types=1);

/**
 * Nostr Web Tokens: the event shape, the transport encoding, and the claims
 * that bind a write or a signed root.
 *
 * The event id is pinned against an independently computed sha256 so a change
 * to the canonical array cannot pass by changing both sides. Signing checks use
 * the real `schnorr` CLI and are skipped when it is absent, like the other
 * registry tests.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Registry\hexToNpub;
use function Moggi\Registry\loadCatalog;
use function Moggi\Registry\npubToHex;
use function Moggi\Registry\nwtDecodeEvent;
use function Moggi\Registry\nwtEventId;
use function Moggi\Registry\nwtEventJson;
use function Moggi\Registry\nwtEventNpub;
use function Moggi\Registry\nwtRootEvent;
use function Moggi\Registry\nwtSignEvent;
use function Moggi\Registry\nwtTag;
use function Moggi\Registry\nwtToken;
use function Moggi\Registry\nwtVerify;
use function Moggi\Registry\nwtVerifyWrite;
use function Moggi\Registry\nwtWriteEvent;
use function Moggi\Registry\rememberedRegistryNpub;
use function Moggi\Registry\schnorrBinary;
use function Moggi\Registry\verifyRootSignature;

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

// --- bech32 npub conversion --------------------------------------------------
$seedNpub = 'npub1wfvq9dhta9cnht06atsndhyqz3k649fm047ur0x54yy7f5ufvetsqlf0pz';
$seedHex = npubToHex($seedNpub);
$assert(\is_string($seedHex) && \preg_match('/^[0-9a-f]{64}$/', $seedHex) === 1, 'an npub converts to a 32-byte hex key');
$assert(hexToNpub((string) $seedHex) === $seedNpub, 'the hex key converts back to the same npub');
$assert(npubToHex('npub1') === null, 'a truncated npub is refused');
$assert(npubToHex(\substr($seedNpub, 0, -1) . 'q') === null, 'a changed bech32 checksum is refused');
$assert(hexToNpub(\str_repeat('A', 64)) === null, 'a pubkey that is not lowercase hex is refused');
$assert(hexToNpub(\str_repeat('a', 63)) === null, 'a 31-byte pubkey is refused');

// --- the NIP-01 event id -----------------------------------------------------
$pubkey = \str_repeat('1', 64);
$id = nwtEventId($pubkey, 1_700_000_000, [['aud', 'reg.example.org']], 'hello');
$assert(
    $id === '44dc0e9cfb03d8a5efe5eae5285dedda8f3b6088c68c469fc4aa935451891421',
    "the event id changed shape: {$id}",
);
$assert($id === nwtEventId($pubkey, 1_700_000_000, [['aud', 'reg.example.org']], 'hello'), 'the event id is deterministic');

// --- tokens decode, and an unsigned event cannot pass as signed --------------
$event = [
    'id' => \str_repeat('0', 64),
    'pubkey' => (string) $seedHex,
    'created_at' => 1_700_000_000,
    'kind' => 27519,
    'tags' => [['file', 'index.json'], ['sha256', \hash('sha256', 'root')]],
    'content' => 'Moggi registry root',
    'sig' => \str_repeat('0', 128),
];
$token = nwtToken($event);
$assert(! \str_contains($token, '='), 'the transport token is unpadded base64url');
$assert(! \str_contains($token, '+') && ! \str_contains($token, '/'), 'the transport token uses the url-safe alphabet');
$assert(nwtDecodeEvent($token) === $event, 'the token decodes to the same event');
$assert(nwtDecodeEvent(nwtEventJson($event)) === $event, 'a stored event JSON object decodes');
$assert(nwtDecodeEvent('not a token') === null, 'an arbitrary string is not an event');

$wrongKind = $event;
$wrongKind['kind'] = 1;
$verdict = nwtVerify($wrongKind, $seedNpub);
$assert(! $verdict['ok'] && \str_contains($verdict['note'], 'kind'), 'an event of another kind is refused');

$verdict = nwtVerify($event, $seedNpub);
$assert(! $verdict['ok'] && \str_contains($verdict['note'], 'event id'), 'an event whose id does not match its contents is refused');
$assert(nwtTag($event, 'file') === 'index.json', 'a tag value is read in order');

// --- signing, root binding and write binding ---------------------------------
$binary = schnorrBinary();
if ($binary === null) {
    echo "  (skipped the signing checks: no schnorr CLI in this installation)\n";
    echo "nwt tests passed ({$checks} checks)\n";

    return;
}

$schnorr = static function (array $arguments) use ($binary): string {
    $process = \proc_open(
        \array_merge([$binary], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (! \is_resource($process)) {
        throw new \RuntimeException("cannot run {$binary}");
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
[$otherNsec, $otherNpub] = \preg_split('/\s+/', $schnorr(['generate'])) ?: ['', ''];
$assert(\str_starts_with($nsec, 'nsec') && \str_starts_with($npub, 'npub'), 'the schnorr CLI generated a keypair');
$assert(\str_starts_with($otherNsec, 'nsec') && \str_starts_with($otherNpub, 'npub'), 'the schnorr CLI generated a second keypair');

$rootBytes = "{\"format\":1}\n";
$rootEvent = nwtRootEvent($rootBytes, $nsec, $npub, 1_700_000_000);
$assert(nwtEventNpub($rootEvent) === $npub, 'the event pubkey is the signer npub');
$assert(nwtTag($rootEvent, 'file') === 'index.json', 'the root event names the file it signs');
$assert(nwtTag($rootEvent, 'sha256') === \hash('sha256', $rootBytes), 'the root event carries the exact digest');

$verdict = nwtVerify($rootEvent, $npub, null, 1_700_000_000);
$assert($verdict['ok'], 'a signed root event verifies: ' . $verdict['note']);
$assert(verifyRootSignature($rootBytes, nwtEventJson($rootEvent), $npub)['ok'], 'the root signature verifies the root bytes');

$badRootId = $rootEvent;
$badRootId['id'] = \str_repeat('0', 64);
$assert(! verifyRootSignature($rootBytes, nwtEventJson($badRootId), $npub)['ok'], 'a root event with a changed id is refused');
$badRootSig = $rootEvent;
$badRootSig['sig'] = \str_repeat('0', 128);
$assert(! verifyRootSignature($rootBytes, nwtEventJson($badRootSig), $npub)['ok'], 'a root event with a changed signature is refused');
$assert(! verifyRootSignature($rootBytes . 'x', nwtEventJson($rootEvent), $npub)['ok'], 'different root bytes are refused');
$assert(! verifyRootSignature($rootBytes, nwtEventJson($rootEvent), $otherNpub)['ok'], 'a root signed by another key is refused');

$otherFile = nwtSignEvent(
    $nsec,
    $npub,
    'Moggi registry root',
    [['file', 'other.json'], ['sha256', \hash('sha256', $rootBytes)]],
    1_700_000_000,
);
$assert(! verifyRootSignature($rootBytes, nwtEventJson($otherFile), $npub)['ok'], 'an event naming another file is not a root signature');

// --- a write token is bound to the audience, route, body and nonce -----------
$write = nwtWriteEvent('reg.example.org', 'POST', '/publish', 'body', $nsec, $npub, 1_700_000_000);
$verdict = nwtVerifyWrite($write, 'reg.example.org', 'POST', '/publish', 'body', 1_700_000_000);
$assert($verdict['ok'], 'a freshly signed write verifies: ' . $verdict['note']);
$assert(nwtTag($write, 'aud') === 'reg.example.org', 'the audience names the write host');
$assert(nwtTag($write, 'payload') === \hash('sha256', 'body'), 'the payload claim is the body digest');
$assert(nwtTag($write, 'nonce') !== null, 'the write carries a nonce');

$assert(! nwtVerifyWrite($write, 'other.example.org', 'POST', '/publish', 'body', 1_700_000_000)['ok'], 'another audience is refused');
$assert(! nwtVerifyWrite($write, 'reg.example.org', 'PUT', '/publish', 'body', 1_700_000_000)['ok'], 'another method is refused');
$assert(! nwtVerifyWrite($write, 'reg.example.org', 'POST', '/other', 'body', 1_700_000_000)['ok'], 'another path is refused');
$assert(! nwtVerifyWrite($write, 'reg.example.org', 'POST', '/publish', 'other', 1_700_000_000)['ok'], 'another body is refused');
$assert(! nwtVerifyWrite($write, 'reg.example.org', 'POST', '/publish', 'body', 1_700_000_000 + 361)['ok'], 'an expired write is refused');

$now = 1_700_000_000;
$writeTags = [
    ['aud', 'reg.example.org'],
    ['iat', (string) $now],
    ['method', 'POST'],
    ['path', '/publish'],
    ['payload', \hash('sha256', 'body')],
];
$noExpiry = nwtSignEvent(
    $nsec,
    $npub,
    'Moggi registry write: POST /publish',
    [...$writeTags, ['nonce', '0123456789abcdef']],
    $now,
);
$assert(
    ! nwtVerifyWrite($noExpiry, 'reg.example.org', 'POST', '/publish', 'body', $now)['ok'],
    'a write with no expiry is refused',
);
$noNonce = nwtSignEvent(
    $nsec,
    $npub,
    'Moggi registry write: POST /publish',
    [...$writeTags, ['exp', (string) ($now + 300)]],
    $now,
);
$assert(
    ! nwtVerifyWrite($noNonce, 'reg.example.org', 'POST', '/publish', 'body', $now)['ok'],
    'a write with no nonce is refused',
);

$hugeExpiry = nwtSignEvent(
    $nsec,
    $npub,
    'Moggi registry write: POST /publish',
    [...$writeTags, ['nonce', '0123456789abcdef'], ['exp', '99999999999999999999']],
    $now,
);
$assert(
    ! nwtVerify($hugeExpiry, $npub, 'reg.example.org', $now)['ok'],
    'a time claim too large to represent is refused',
);

$extra = $write;
$extra['extra'] = 'field';
$verdict = nwtVerify($extra, $npub, 'reg.example.org', 1_700_000_000);
$assert(! $verdict['ok'] && \str_contains($verdict['note'], 'unknown field'), 'an event with an extra field is refused');

$duplicate = $write;
$duplicate['tags'][] = ['method', 'POST'];
$verdict = nwtVerify($duplicate, $npub, 'reg.example.org', 1_700_000_000);
$assert(! $verdict['ok'] && \str_contains($verdict['note'], 'repeats'), 'a repeated single-valued claim is refused');

$emptyTag = $write;
$emptyTag['tags'][] = [];
$verdict = nwtVerify($emptyTag, $npub, 'reg.example.org', 1_700_000_000);
$assert(! $verdict['ok'], 'an empty tag is refused');

$tampered = $write;
$tampered['content'] .= 'x';
$verdict = nwtVerify($tampered, $npub, 'reg.example.org', 1_700_000_000);
$assert(! $verdict['ok'] && \str_contains($verdict['note'], 'event id'), 'changing signed content breaks the event id');

$badId = $write;
$badId['id'] = \str_repeat('0', 64);
$verdict = nwtVerify($badId, $npub, 'reg.example.org', 1_700_000_000);
$assert(! $verdict['ok'] && \str_contains($verdict['note'], 'event id'), 'a changed event id is refused');

$rehashed = $write;
$rehashed['content'] .= 'x';
$rehashed['id'] = nwtEventId($rehashed['pubkey'], $rehashed['created_at'], $rehashed['tags'], $rehashed['content']);
$verdict = nwtVerify($rehashed, $npub, 'reg.example.org', 1_700_000_000);
$assert(! $verdict['ok'] && \str_contains($verdict['note'], 'signature'), 'a recomputed id with the old signature is refused');

$badSig = $write;
$badSig['sig'] = \str_repeat('0', 128);
$verdict = nwtVerify($badSig, $npub, 'reg.example.org', 1_700_000_000);
$assert(! $verdict['ok'] && \str_contains($verdict['note'], 'signature'), 'a changed signature is refused');
$assert(! nwtVerify($write, $otherNpub, 'reg.example.org', 1_700_000_000)['ok'], 'a write signed by another key is refused');
$assert(! nwtVerify($write, $npub, 'reg.example.org', 1_700_000_000 - 121)['ok'], 'a write whose nbf is in the future is refused');

$throws(
    static fn () => nwtSignEvent($nsec, $otherNpub, 'wrong key', [], 1_700_000_000),
    'signing with a key that is not the declared author is refused',
);

// --- an expired root must not establish trust on first use -------------------
$work = \sys_get_temp_dir() . '/moggi-nwt-' . \bin2hex(\random_bytes(6));
$registry = $work . '/registry';
\mkdir($registry, 0777, true);
$expiredRoot = \json_encode([
    'format' => 1,
    'registry_npub' => $npub,
    'version' => 1,
    'expires' => \gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
    'shards' => (object) [],
], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
\file_put_contents($registry . '/index.json', $expiredRoot);
\file_put_contents($registry . '/index.json.sig', nwtEventJson(nwtRootEvent($expiredRoot, $nsec, $npub)) . "\n");
$previousCache = \getenv('MOGGI_USER_CACHE');
$previousPin = \getenv('MOGGI_REGISTRY_NPUB');
\putenv('MOGGI_USER_CACHE=' . $work . '/user-cache');
\putenv('MOGGI_REGISTRY_NPUB');
try {
    $refused = false;
    try {
        loadCatalog($registry, null, null, false);
    } catch (\RuntimeException $error) {
        $refused = \str_contains($error->getMessage(), 'expired');
    }
    $assert($refused, 'an expired root is refused before it can be trusted');
    $assert(
        rememberedRegistryNpub($registry) === null,
        'an expired root must not be remembered as the registry identity',
    );

    $scalarRegistry = $work . '/scalar-registry';
    \mkdir($scalarRegistry, 0777, true);
    \file_put_contents($scalarRegistry . '/index.json', '"not an object"');
    $refused = false;
    try {
        loadCatalog($scalarRegistry, null, null, false);
    } catch (\RuntimeException $error) {
        $refused = \str_contains($error->getMessage(), 'not a JSON object');
    }
    $assert($refused, 'a root that is not a JSON object is refused without a type error');
} finally {
    \putenv($previousCache === false ? 'MOGGI_USER_CACHE' : 'MOGGI_USER_CACHE=' . $previousCache);
    \putenv($previousPin === false ? 'MOGGI_REGISTRY_NPUB' : 'MOGGI_REGISTRY_NPUB=' . $previousPin);
    $remove = static function (string $path) use (&$remove): void {
        if (\is_dir($path) && ! \is_link($path)) {
            foreach (\scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $remove($path . '/' . $name);
                }
            }
            @\rmdir($path);

            return;
        }
        @\unlink($path);
    };
    $remove($work);
}

echo "nwt tests passed ({$checks} checks)\n";
