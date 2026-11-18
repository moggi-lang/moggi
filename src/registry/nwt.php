<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * Nostr Web Tokens: a signed kind-27519 event carrying registry claims.
 *
 * The Schnorr CLI remains the raw BIP-340 primitive. Everything above it is a
 * NIP-01 event: the id is the sha256 of the canonical event array, the
 * signature is over that id, and the HTTP form is the event JSON encoded as
 * unpadded base64url in an `Authorization: Nostr <token>` header.
 */

const NWT_KIND = 27519;
const NWT_AUTH_SCHEME = 'Nostr';
const NWT_CLOCK_SKEW = 60;
const NWT_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

/** The only fields a NWT event may carry. */
const NWT_FIELDS = ['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'];

/** Claims that mean one thing, and so may appear at most once. */
const NWT_SINGLE_CLAIMS = [
    'type', 'name', 'version', 'manifest', 'file', 'sha256', 'method', 'path',
    'payload', 'nonce', 'exp', 'nbf', 'iat',
];

/** The 32-byte public key an npub names, as lowercase hex. */
function npubToHex(string $npub): ?string
{
    if (\strlen($npub) !== 63 || ! \str_starts_with($npub, 'npub1')) {
        return null;
    }
    $values = nwtBech32Decode($npub, 'npub');
    if ($values === null) {
        return null;
    }
    $bytes = nwtConvertBits($values, 5, 8, false);

    return $bytes === null || \count($bytes) !== 32 ? null : \bin2hex(\pack('C*', ...$bytes));
}

/** The npub spelling of a 32-byte public key, as lowercase hex. */
function hexToNpub(string $hex): ?string
{
    if (\preg_match('/^[0-9a-f]{64}$/', $hex) !== 1) {
        return null;
    }
    $bytes = \array_values(\unpack('C*', (string) \hex2bin($hex)));
    $five = nwtConvertBits($bytes, 8, 5, true);
    if ($five === null) {
        return null;
    }
    $values = [...nwtHrpExpand('npub'), ...$five];
    $mod = nwtPolymod([...$values, 0, 0, 0, 0, 0, 0]) ^ 1;
    $checksum = [];
    for ($i = 0; $i < 6; ++$i) {
        $checksum[] = ($mod >> (5 * (5 - $i))) & 31;
    }
    $encoded = '';
    foreach ([...$five, ...$checksum] as $value) {
        $encoded .= NWT_CHARSET[$value];
    }

    return 'npub1' . $encoded;
}

/**
 * The event id NIP-01 defines: sha256 of the event's canonical array.
 *
 * This is deliberately not the compiler's `canonicalJson()`: NIP-01 hashes a
 * fixed array, not an object, and the order is part of the format.
 */
function nwtEventId(string $pubkey, int $createdAt, array $tags, string $content): string
{
    $serialized = \json_encode(
        [0, $pubkey, $createdAt, NWT_KIND, $tags, $content],
        \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
    );

    return \hash('sha256', $serialized);
}

/**
 * Sign one NWT event.
 *
 * `$npub` is supplied by the caller rather than derived from the nsec: the
 * author of a release and the identity on a write are already known, and the
 * event's pubkey must be the hex form of that identity.
 */
function nwtSignEvent(
    string $nsec,
    string $npub,
    string $content,
    array $tags,
    ?int $createdAt = null,
): array {
    $pubkey = npubToHex($npub);
    if ($pubkey === null) {
        throw new \RuntimeException("`{$npub}` is not an npub");
    }
    $createdAt ??= \time();
    $tags = \array_values($tags);
    $id = nwtEventId($pubkey, $createdAt, $tags, $content);
    $sig = signMessage($id, $nsec);

    $verdict = verifySignature($id, $sig, $npub);
    if (! $verdict['ok']) {
        throw new \RuntimeException("the key does not match {$npub}: {$verdict['note']}");
    }

    return [
        'id' => $id,
        'pubkey' => $pubkey,
        'created_at' => $createdAt,
        'kind' => NWT_KIND,
        'tags' => $tags,
        'content' => $content,
        'sig' => $sig,
    ];
}

/** The event as the JSON object stored in a registry record. */
function nwtEventJson(array $event): string
{
    return \json_encode($event, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
}

/** The HTTP transport form: unpadded base64url of the event JSON. */
function nwtToken(array $event): string
{
    return \rtrim(\strtr(\base64_encode(nwtEventJson($event)), '+/', '-_'), '=');
}

/** Decode an event from a token or a JSON object/string. */
function nwtDecodeEvent(array|string $token): ?array
{
    if (\is_array($token)) {
        return $token;
    }
    $token = \trim($token);
    if ($token === '') {
        return null;
    }
    if ($token[0] === '{') {
        $json = $token;
    } else {
        $padded = \str_pad(\strtr($token, '-_', '+/'), (4 - \strlen($token) % 4) % 4, '=', \STR_PAD_RIGHT);
        $json = \base64_decode($padded, true);
        if ($json === false) {
            return null;
        }
    }
    $event = \json_decode($json, true);

    return \is_array($event) && ! \array_is_list($event) ? $event : null;
}

/** The values of one claim, in event order. */
function nwtTagValues(array $event, string $name): array
{
    $values = [];
    foreach (\is_array($event['tags'] ?? null) ? $event['tags'] : [] as $tag) {
        if (\is_array($tag) && isset($tag[0], $tag[1]) && $tag[0] === $name && \is_string($tag[1])) {
            $values[] = $tag[1];
        }
    }

    return $values;
}

/**
 * A numeric time claim as unix seconds, or null when it is not representable.
 *
 * The digit cap is deliberate: it keeps a hostile claim from overflowing the
 * integer conversion on 32-bit PHP and becoming an expiry that never arrives.
 */
function nwtTimestampValue(string $value): ?int
{
    if (\preg_match('/^[0-9]+$/', $value) !== 1) {
        return null;
    }
    $digits = \ltrim($value, '0');
    $digits = $digits === '' ? '0' : $digits;
    if (\strlen($digits) > 10) {
        return null;
    }
    $at = \filter_var($digits, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

    return $at === false ? null : $at;
}

/** One claim's value, or null when the event does not carry it. */
function nwtTag(array $event, string $name): ?string
{
    $values = nwtTagValues($event, $name);

    return $values[0] ?? null;
}

/** The npub that signed an event, or null when the event's pubkey is malformed. */
function nwtEventNpub(array $event): ?string
{
    $pubkey = $event['pubkey'] ?? null;

    return \is_string($pubkey) ? hexToNpub($pubkey) : null;
}

/**
 * Verify the event, its signature, its time window, and optionally its
 * audience. `$npub` pins the signer; without it the event's own pubkey is the
 * only identity the signature can prove.
 *
 * @return array{ok: bool, note: string, event?: array, npub?: string}
 */
function nwtVerify(
    array|string $token,
    ?string $npub = null,
    ?string $audience = null,
    ?int $now = null,
): array {
    $event = nwtDecodeEvent($token);
    if ($event === null) {
        return ['ok' => false, 'note' => 'the token is not a NWT event'];
    }
    $extra = \array_diff(\array_keys($event), NWT_FIELDS);
    if ($extra !== []) {
        return ['ok' => false, 'note' => 'the event has an unknown field `' . \reset($extra) . '`'];
    }
    if (($event['kind'] ?? null) !== NWT_KIND) {
        return ['ok' => false, 'note' => 'the event kind is not ' . NWT_KIND];
    }
    foreach (['id', 'pubkey', 'sig', 'content'] as $field) {
        if (! \is_string($event[$field] ?? null)) {
            return ['ok' => false, 'note' => "the event has no string `{$field}`"];
        }
    }
    if (! \is_int($event['created_at'] ?? null) || ! \is_array($event['tags'] ?? null)) {
        return ['ok' => false, 'note' => 'the event has no numeric `created_at` or tag list'];
    }
    foreach ($event['tags'] as $tag) {
        if (! \is_array($tag) || $tag === [] || ! \array_is_list($tag)) {
            return ['ok' => false, 'note' => 'the event tags are not non-empty lists'];
        }
        foreach ($tag as $part) {
            if (! \is_string($part)) {
                return ['ok' => false, 'note' => 'the event tags are not string lists'];
            }
        }
        if ($tag[0] === '') {
            return ['ok' => false, 'note' => 'the event has an empty tag name'];
        }
    }
    foreach (NWT_SINGLE_CLAIMS as $claim) {
        if (\count(nwtTagValues($event, $claim)) > 1) {
            return ['ok' => false, 'note' => "the event repeats the `{$claim}` claim"];
        }
    }
    if (\preg_match('/^[0-9a-f]{64}$/', $event['pubkey']) !== 1
        || \preg_match('/^[0-9a-f]{64}$/', $event['id']) !== 1
        || \preg_match('/^[0-9a-f]{128}$/', $event['sig']) !== 1
    ) {
        return ['ok' => false, 'note' => 'the event id, pubkey or signature is not lowercase hex'];
    }

    $eventNpub = hexToNpub($event['pubkey']);
    if ($eventNpub === null) {
        return ['ok' => false, 'note' => 'the event pubkey is not a valid public key'];
    }
    if ($npub !== null) {
        $expected = npubToHex($npub);
        if ($expected === null) {
            return ['ok' => false, 'note' => "`{$npub}` is not an npub"];
        }
        if (! \hash_equals($expected, $event['pubkey'])) {
            return ['ok' => false, 'note' => "the event is signed by {$eventNpub}, not {$npub}"];
        }
    }

    $id = nwtEventId($event['pubkey'], $event['created_at'], $event['tags'], $event['content']);
    if (! \hash_equals($id, $event['id'])) {
        return ['ok' => false, 'note' => 'the event id does not match its contents'];
    }

    $verdict = verifySignature($event['id'], $event['sig'], $eventNpub);
    if (! $verdict['ok']) {
        return ['ok' => false, 'note' => $verdict['note']];
    }

    $now ??= \time();
    foreach (['exp', 'nbf', 'iat'] as $claim) {
        $value = nwtTag($event, $claim);
        if ($value === null) {
            continue;
        }
        $at = nwtTimestampValue($value);
        if ($at === null) {
            return ['ok' => false, 'note' => "the `{$claim}` claim is not a representable non-negative integer"];
        }
        if ($claim === 'exp' && $now > $at + NWT_CLOCK_SKEW) {
            return ['ok' => false, 'note' => 'the NWT has expired'];
        }
        if ($claim === 'nbf' && $at > $now + NWT_CLOCK_SKEW) {
            return ['ok' => false, 'note' => 'the NWT is not valid yet'];
        }
        if ($claim === 'iat' && $at > $now + NWT_CLOCK_SKEW) {
            return ['ok' => false, 'note' => 'the NWT was issued in the future'];
        }
    }

    if ($audience !== null && ! \in_array($audience, nwtTagValues($event, 'aud'), true)) {
        return ['ok' => false, 'note' => "the audience does not name {$audience}"];
    }

    return ['ok' => true, 'note' => "verified against {$eventNpub}", 'event' => $event, 'npub' => $eventNpub];
}

/** The NWT a registry write carries. The event id is unique because of the nonce. */
function nwtWriteEvent(
    string $host,
    string $method,
    string $path,
    string $body,
    string $nsec,
    string $npub,
    ?int $now = null,
): array {
    $now ??= \time();

    return nwtSignEvent(
        $nsec,
        $npub,
        "Moggi registry write: {$method} {$path}",
        [
            ['aud', $host],
            ['iat', (string) $now],
            ['exp', (string) ($now + 300)],
            ['nbf', (string) ($now - NWT_CLOCK_SKEW)],
            ['method', $method],
            ['path', $path],
            ['payload', \hash('sha256', $body)],
            ['nonce', \bin2hex(\random_bytes(16))],
        ],
        $now,
    );
}

/**
 * Verify a write token and bind it to this request.
 *
 * @return array{ok: bool, note: string, event?: array, npub?: string}
 */
function nwtVerifyWrite(
    array|string $token,
    string $host,
    string $method,
    string $path,
    string $body,
    ?int $now = null,
): array {
    $verdict = nwtVerify($token, null, $host, $now);
    if (! $verdict['ok']) {
        return $verdict;
    }
    $event = $verdict['event'];
    if (nwtTag($event, 'method') !== $method) {
        return ['ok' => false, 'note' => "the NWT is for method {$method}, not " . (nwtTag($event, 'method') ?? 'nothing')];
    }
    if (nwtTag($event, 'path') !== $path) {
        return ['ok' => false, 'note' => "the NWT is for path {$path}, not " . (nwtTag($event, 'path') ?? 'nothing')];
    }
    $payload = nwtTag($event, 'payload');
    if ($payload === null || ! \hash_equals(\hash('sha256', $body), $payload)) {
        return ['ok' => false, 'note' => 'the NWT payload does not match the request body'];
    }
    // A bearer token with no expiry would be replayable forever. The signer
    // always includes one; requiring it here means an older or alternative
    // signer cannot quietly opt out of the replay window.
    if (nwtTag($event, 'exp') === null) {
        return ['ok' => false, 'note' => 'the NWT carries no expiry'];
    }
    $nonce = nwtTag($event, 'nonce');
    if ($nonce === null || $nonce === '') {
        return ['ok' => false, 'note' => 'the NWT carries no nonce'];
    }

    return $verdict;
}

/** The NWT that signs a registry root. The file claim names what the digest covers. */
function nwtRootEvent(string $rootBytes, string $nsec, string $npub, ?int $createdAt = null): array
{
    return nwtSignEvent(
        $nsec,
        $npub,
        'Moggi registry root',
        [
            ['file', 'index.json'],
            ['sha256', \hash('sha256', $rootBytes)],
        ],
        $createdAt,
    );
}

/** @return list<int> */
function nwtHrpExpand(string $hrp): array
{
    $high = [];
    $low = [];
    foreach (\str_split($hrp) as $char) {
        $code = \ord($char);
        $high[] = $code >> 5;
        $low[] = $code & 31;
    }

    return [...$high, 0, ...$low];
}

/** @param list<int> $values */
function nwtPolymod(array $values): int
{
    $checksum = 1;
    $generators = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    foreach ($values as $value) {
        $top = $checksum >> 25;
        $checksum = (($checksum & 0x1ffffff) << 5) ^ $value;
        for ($i = 0; $i < 5; ++$i) {
            if (($top >> $i) & 1) {
                $checksum ^= $generators[$i];
            }
        }
    }

    return $checksum;
}

/** @return ?list<int> the data values without the bech32 checksum */
function nwtBech32Decode(string $text, string $hrp): ?array
{
    if (\strlen($text) > 90) {
        return null;
    }
    $lower = \strtolower($text);
    if ($text !== $lower && $text !== \strtoupper($text)) {
        return null;
    }
    $text = $lower;
    $separator = \strrpos($text, '1');
    if ($separator === false || $separator < 1 || \substr($text, 0, $separator) !== $hrp) {
        return null;
    }
    $encoded = \substr($text, $separator + 1);
    if (\strlen($encoded) < 6) {
        return null;
    }
    $values = [];
    foreach (\str_split($encoded) as $char) {
        $index = \strpos(NWT_CHARSET, $char);
        if ($index === false) {
            return null;
        }
        $values[] = $index;
    }
    if (nwtPolymod([...nwtHrpExpand($hrp), ...$values]) !== 1) {
        return null;
    }

    return \array_slice($values, 0, -6);
}

/** @param list<int> $data @return ?list<int> */
function nwtConvertBits(array $data, int $fromBits, int $toBits, bool $pad): ?array
{
    $maxValue = (1 << $toBits) - 1;
    $maxAccumulator = (1 << ($fromBits + $toBits - 1)) - 1;
    $accumulator = 0;
    $bits = 0;
    $result = [];
    foreach ($data as $value) {
        if ($value < 0 || ($value >> $fromBits) !== 0) {
            return null;
        }
        $accumulator = (($accumulator << $fromBits) | $value) & $maxAccumulator;
        $bits += $fromBits;
        while ($bits >= $toBits) {
            $bits -= $toBits;
            $result[] = ($accumulator >> $bits) & $maxValue;
        }
    }
    if ($pad) {
        if ($bits > 0) {
            $result[] = ($accumulator << ($toBits - $bits)) & $maxValue;
        }
    } elseif ($bits >= $fromBits || (($accumulator << ($toBits - $bits)) & $maxValue) !== 0) {
        return null;
    }

    return $result;
}
