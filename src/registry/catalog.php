<?php declare(strict_types=1);

namespace Moggi\Registry;

use function Moggi\Cache\cacheEnabled;

/**
 * Loading a registry's catalog — the read half of `registry-spec.md`.
 *
 * A resolver needs which versions exist, what each requires, and who may sign a
 * release, and all three are in the catalog: nothing here asks for a package
 * file, which is what keeps resolution off the heavy half of the tree.
 *
 * Everything read is authenticated before it is used — `index.json` against its
 * own `registry_npub`, every shard against the digest the root publishes — and a
 * mismatch is a failure, not a warning. The base may be a directory or an
 * `http(s)` URL, so a mirror, a checkout and the canonical registry are one code
 * path.
 *
 * A verified root is not enough on its own: the root names the key that signs it,
 * so a root can be self-consistent and still be a different registry than the one
 * this machine trusted yesterday. `registryIdentityProblem` closes that: a pin
 * if there is one, otherwise trust on first use, and never a quiet key change.
 */

/**
 * The most bytes one registry response may carry.
 *
 * Configurable with `MOGGI_MAX_RESPONSE_BYTES`, and *enforced* rather than
 * advisory: the reader stops at the cap while it streams, so a hostile registry
 * cannot make the client buffer a body without bound. The default is generous
 * for a package archive and still well inside the memory ceiling `compiler.php`
 * sets.
 */
function maxResponseBytes(): int
{
    $override = \getenv('MOGGI_MAX_RESPONSE_BYTES');
    if (\is_string($override) && $override !== '') {
        if (\preg_match('/^[0-9]+$/', $override) !== 1 || (int) $override < 1) {
            throw new \RuntimeException(
                'MOGGI_MAX_RESPONSE_BYTES must be a positive number of bytes, not ' . \var_export($override, true),
            );
        }

        return (int) $override;
    }

    return 256 * 1024 * 1024;
}

/**
 * Refuse a body over the configured cap, naming what was being read.
 *
 * The message is a refusal, not a crash: a registry that answers with something
 * larger than a caller expected is a case to explain, not to die on midway
 * through building a string.
 */
function assertResponseWithinCap(int $bytes, string $what): void
{
    $cap = maxResponseBytes();
    if ($bytes > $cap) {
        throw new \RuntimeException(
            "{$what} is {$bytes} bytes, over the {$cap}-byte response cap — "
            . 'raise MOGGI_MAX_RESPONSE_BYTES if a package this large is expected',
        );
    }
}

/**
 * Read one registry file.
 *
 * `$decode` is false for a caller that needs the bytes exactly as published — a
 * release's `archive` source is hashed *before* any transport coding is undone,
 * because the sha256 in the release covers the archive on the wire.
 *
 * @return ?string The bytes, or null when the file is absent (`404` included).
 */
function fetchBytes(string $base, string $path, bool $decode = true): ?string
{
    $location = \rtrim($base, '/') . '/' . $path;
    if (\preg_match('#^https?://#', $location) === 1) {
        return fetchHttpBytes($location, $path, $decode);
    }

    if (!\is_file($location)) {
        return null;
    }
    $bytes = (string) \file_get_contents($location);
    assertResponseWithinCap(\strlen($bytes), $path);
    if (!$decode) {
        return $bytes;
    }
    $decoded = decodeTransport($bytes);
    assertResponseWithinCap(\strlen($decoded), $path);

    return $decoded;
}

/**
 * Read one registry file through whichever reader is in play, holding its bytes
 * to the response cap.
 *
 * `fetchBytes` caps as it streams; a caller-supplied reader hands back a string
 * that is already materialized (a test hook today), so this is where *its* size
 * is measured — the cap should not be something a reader can opt out of.
 *
 * @param ?callable(string, string): ?string $fetch
 */
function fetchRegistryBytes(string $base, string $path, ?callable $fetch): ?string
{
    $bytes = $fetch !== null ? $fetch($base, $path) : fetchBytes($base, $path);
    if ($bytes !== null) {
        assertResponseWithinCap(\strlen($bytes), $path);
    }

    return $bytes;
}

/**
 * Read an `http(s)` response whole, refusing one larger than the cap.
 *
 * The size is checked twice: against `Content-Length` when the host sends it, so
 * an oversized body is refused before a byte of it is read, and again on what was
 * actually read — the header is the host's claim, and a claim is not a proof. The
 * read itself is bounded (`stream_get_contents` stops at `cap + 1`), so a host
 * that sends no length and never ends cannot make the client grow a buffer.
 */
function fetchHttpBytes(string $location, string $what, bool $decode = true): ?string
{
    $context = \stream_context_create(['http' => [
        'timeout' => 30,
        'ignore_errors' => true,
        'header' => "Accept-Encoding: identity\r\n",
    ]]);
    $handle = @\fopen($location, 'rb', false, $context);
    if ($handle === false) {
        return null;
    }

    $cap = maxResponseBytes();
    $status = 0;
    $declared = null;
    foreach ($http_response_header ?? [] as $header) {
        if (\preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
            $status = (int) $match[1];
        } elseif (\preg_match('#^Content-Length:\s*(\d+)#i', $header, $match) === 1) {
            $declared = (int) $match[1];
        }
    }
    if ($status !== 0 && $status !== 200) {
        \fclose($handle);

        return null;
    }
    if ($declared !== null && $declared > $cap) {
        \fclose($handle);
        assertResponseWithinCap($declared, $what);
    }

    $bytes = \stream_get_contents($handle, $cap + 1);
    \fclose($handle);
    if (!\is_string($bytes)) {
        return null;
    }
    assertResponseWithinCap(\strlen($bytes), $what);
    if (!$decode) {
        return $bytes;
    }
    $decoded = decodeTransport($bytes);
    assertResponseWithinCap(\strlen($decoded), $what);

    return $decoded;
}

/** The digest spelling every level of the tree uses. */
function sha256Digest(string $bytes): string
{
    return 'sha256:' . \hash('sha256', $bytes);
}

/**
 * Undo a transport coding, if one was applied.
 *
 * Content coding is transport, not format: every digest in the tree is over the
 * *decoded* bytes, so a host is free to gzip what it serves. Detected by magic
 * rather than by a header, because that works for a mirror, a CDN, a static host
 * and a plain directory alike.
 */
function decodeTransport(string $bytes): string
{
    if (!\str_starts_with($bytes, "\x1f\x8b")) {
        return $bytes;
    }
    $inflated = @\gzdecode($bytes);

    return $inflated === false ? $bytes : $inflated;
}

/**
 * The shard a package lives in: the catalog is sharded by the first two
 * characters of the name, so `json` is in `catalog/js.json`.
 */
function shardPrefix(string $name): string
{
    return \substr($name, 0, 2);
}

/**
 * `index.json.sig`: a BIP-340 signature over `sha256(index.json)`, made by the
 * key named *inside* the file it signs.
 *
 * Thin wrapper over `verifySignature()` so the root and a release cannot be
 * checked two different ways: what differs is only the message, which for the
 * root is the file's own digest and not a canonical manifest.
 *
 * @return array{ok: bool, note: string}
 */
function verifyRootSignature(string $rootBytes, string $signature, string $npub): array
{
    return verifySignature(\hash('sha256', $rootBytes), $signature, $npub);
}

/**
 * A registry catalog, read on demand.
 *
 * The root and its signature are read up front — learning a shard's digest means
 * reading the root — but a shard is fetched the first time a name in it is asked
 * for, so a resolution pays for the shards its dependencies touch rather than for
 * every shard the registry has. `entry()` is the whole interface the resolver
 * needs.
 *
 * What is cached is the *bytes*, never the verdict: a cached shard is reused
 * only while its digest still matches the one the root publishes, so a warm load
 * is as strict as a cold one.
 */
final class Catalog
{
    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    /** @var array<string, true> */
    private array $loaded = [];

    /** @var array<string, string> shard prefix => why it could not be trusted */
    private array $failures = [];

    /**
     * @param array<string, mixed> $root
     * @param array{ok: bool, note: string} $signature
     * @param ?\Closure(string, string): ?string $fetch
     */
    public function __construct(
        private readonly string $base,
        private readonly array $root,
        private readonly array $signature,
        private readonly ?\Closure $fetch,
        private readonly FetchLog $log,
        private readonly bool $useCache,
    ) {
    }

    /** @return array{ok: bool, note: string} */
    public function signature(): array
    {
        return $this->signature;
    }

    /** The registry's identity, as it names itself in the signed root. */
    public function npub(): ?string
    {
        $npub = $this->root['registry_npub'] ?? null;

        return \is_string($npub) ? $npub : null;
    }

    /** The signed root, decoded as published. */
    public function root(): array
    {
        return $this->root;
    }

    /**
     * Where blobs are read from, when the root names an origin other than the
     * registry itself (§3.1). The origin is a location, never a trust input: the
     * blob is still checked against the release's digest.
     */
    public function blobsBase(): ?string
    {
        $base = $this->root['blobs_base'] ?? null;

        return \is_string($base) && $base !== '' ? \rtrim($base, '/') : null;
    }

    /**
     * One package's catalog entry, fetching its shard the first time it is asked
     * for.
     *
     * @return ?array<string, mixed> null when the registry lists no such package
     * @throws \RuntimeException when the shard it should live in cannot be trusted
     */
    public function entry(string $name): ?array
    {
        assertPackageName($name, 'catalog lookup');

        $prefix = shardPrefix($name);
        $this->loadShard($prefix);
        if (isset($this->failures[$prefix])) {
            throw new \RuntimeException('the catalog is inconsistent: ' . $this->failures[$prefix]);
        }

        return $this->entries[$name] ?? null;
    }

    /**
     * The entries for several names, in one pass. Names the registry does not
     * list are left out, so a caller that needs all of them checks the count.
     *
     * @param iterable<string> $names
     * @return array<string, array<string, mixed>>
     */
    public function entries(iterable $names): array
    {
        $entries = [];
        foreach ($names as $name) {
            $name = (string) $name;
            $entry = $this->entry($name);
            if ($entry !== null) {
                $entries[$name] = $entry;
            }
        }

        return $entries;
    }

    private function loadShard(string $prefix): void
    {
        if (isset($this->loaded[$prefix])) {
            return;
        }
        $this->loaded[$prefix] = true;

        $digest = $this->root['shards'][$prefix] ?? null;
        if (!\is_string($digest)) {
            return;
        }

        $metadataDir = $this->useCache && cacheEnabled() ? catalogCacheDir($this->base) : null;
        $path = $metadataDir . '/catalog/' . $prefix . '.json';
        $bytes = $metadataDir === null ? null : cachedBytes($path, $digest);
        if ($bytes !== null) {
            $this->log->skipped("catalog/{$prefix}.json");
        } else {
            $this->log->requested("catalog/{$prefix}.json");
            $bytes = fetchRegistryBytes($this->base, "catalog/{$prefix}.json", $this->fetch);
            if ($bytes === null) {
                $this->failures[$prefix] = "catalog/{$prefix}.json is missing";

                return;
            }
            if (sha256Digest($bytes) !== $digest) {
                $this->failures[$prefix] = "catalog/{$prefix}.json does not match the digest the root publishes";

                return;
            }
        }

        if ($metadataDir !== null) {
            storeBytes($path, $bytes);
        }
        try {
            $decoded = \json_decode($bytes, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            $this->failures[$prefix] = "catalog/{$prefix}.json is not valid JSON ({$error->getMessage()})";

            return;
        }
        foreach ((\is_array($decoded) ? ($decoded['packages'] ?? []) : []) as $name => $entry) {
            if (!isPackageName((string) $name)) {
                $this->failures[$prefix] = "catalog/{$prefix}.json names an invalid package `{$name}`";

                return;
            }
            if (!\is_array($entry)) {
                $this->failures[$prefix] = "catalog/{$prefix}.json describes `{$name}` as something that is not an object";

                return;
            }
            $this->entries[(string) $name] = ['prefix' => $prefix] + $entry;
        }
    }
}

/**
 * What a command fetched, and what it did not have to: the second half is what
 * keying every cache by a digest buys.
 *
 * Beside `Catalog`, which is the thing that does most of the fetching; the
 * commands that own one pass it through and print it.
 */
final class FetchLog
{
    /** @var list<string> */
    public array $requests = [];

    /** @var list<string> */
    public array $held = [];

    public function requested(string $path): void
    {
        $this->requests[] = $path;
    }

    public function skipped(string $path): void
    {
        $this->held[] = $path;
    }

    public function describe(): string
    {
        return describeFetch($this->requests, $this->held);
    }
}

/** Where a registry's remembered identity lives: one file per base, in the user cache. */
function registryIdentityPath(string $base): string
{
    return userCacheDir() . '/registries/' . registryKey($base) . '.npub';
}

/** The key this machine already trusts for a registry, if one was recorded. */
function rememberedRegistryNpub(string $base): ?string
{
    $path = registryIdentityPath($base);
    if (!\is_file($path)) {
        return null;
    }
    $npub = \trim((string) \file_get_contents($path));

    return isNpub($npub) ? $npub : null;
}

/** Record a registry's key the first time it is seen. */
function rememberRegistryNpub(string $base, string $npub): void
{
    \Moggi\Cache\atomicWrite(registryIdentityPath($base), $npub . "\n");
}

/** The variable that turns the root freshness and rollback checks off for a development registry. */
const ALLOW_STALE_REGISTRY_ENV = 'MOGGI_ALLOW_STALE_REGISTRY';

/** Whether the freshness and rollback checks have been waived for this run. */
function staleRegistryAllowed(): bool
{
    $value = \getenv(ALLOW_STALE_REGISTRY_ENV);

    return \is_string($value) && \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
}

/** Where a registry's highest accepted root version is remembered: one file per base, in the user cache. */
function registryRootVersionPath(string $base): string
{
    return userCacheDir() . '/registries/' . registryKey($base) . '.root-version';
}

/** The highest root version this machine has accepted for a registry, if any. */
function rememberedRootVersion(string $base): ?int
{
    $path = registryRootVersionPath($base);
    if (!\is_file($path)) {
        return null;
    }
    $text = \trim((string) \file_get_contents($path));

    return \preg_match('/^[0-9]+$/', $text) === 1 ? (int) $text : null;
}

/** Raise the remembered root version to one, never lowering it. */
function rememberRootVersion(string $base, int $version): void
{
    $remembered = rememberedRootVersion($base);
    if ($remembered !== null && $remembered >= $version) {
        return;
    }
    \Moggi\Cache\atomicWrite(registryRootVersionPath($base), $version . "\n");
}

/** An RFC 3339 timestamp as unix seconds, or null when the text is not one. */
function registryTimestamp(string $text): ?int
{
    if (\preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', \trim($text)) !== 1) {
        return null;
    }
    $seconds = \strtotime(\trim($text));

    return $seconds === false ? null : $seconds;
}

/**
 * A root's freshness and monotonicity, against what this machine remembers.
 *
 * A signature proves a root is authentic; it says nothing about *when* it was
 * published, so a registry that goes silent — or an attacker who withholds the
 * current root and replays an old signed one — looks exactly like a healthy
 * registry. Two signed fields close that, and both are required:
 *
 *   - `expires`, an RFC 3339 instant: a root past it is refused, so a frozen
 *     registry is noticed rather than trusted indefinitely;
 *   - `version`, a positive integer: a root below the highest this machine has
 *     already accepted is refused, so an older root cannot be replayed.
 *
 * `MOGGI_ALLOW_STALE_REGISTRY` waives both, for a development registry whose
 * clock or versioning is not maintained; the highest version is still remembered,
 * so a later run without the waiver compares against the real high-water mark.
 *
 * Call this only for a root whose signature has already verified.
 *
 * @param array<string, mixed> $root
 * @return string a problem, or '' when the root is fresh and not a rollback
 */
function rootIntegrityProblem(string $base, array $root, int $now): string
{
    $expires = $root['expires'] ?? null;
    if (!\is_string($expires) || \trim($expires) === '') {
        return "the registry at {$base} declares no root expiry (`expires`), so a frozen root cannot be detected";
    }
    $at = registryTimestamp($expires);
    if ($at === null) {
        return "the registry at {$base} has an `expires` that is not an RFC 3339 timestamp ({$expires})";
    }

    $version = $root['version'] ?? null;
    if (!\is_int($version) && !(\is_string($version) && \preg_match('/^[0-9]+$/', $version) === 1)) {
        return "the registry at {$base} declares no root version (`version`), so a replayed root cannot be detected";
    }
    $version = (int) $version;

    if ($version < 1) {
        return "the registry at {$base} has a root version below 1 ({$version})";
    }
    if (staleRegistryAllowed()) {
        return '';
    }

    $remembered = rememberedRootVersion($base);
    if ($remembered !== null && $version < $remembered) {
        return "the registry at {$base} presents root version {$version}, older than {$remembered} which this machine already accepted "
            . '— refusing a rollback (set ' . ALLOW_STALE_REGISTRY_ENV . '=1 to accept a deliberate downgrade)';
    }
    if ($at < $now) {
        return "the registry at {$base} root expired at {$expires} — refresh the registry, or set "
            . ALLOW_STALE_REGISTRY_ENV . '=1 to use a stale one';
    }

    return '';
}

/**
 * Decide whether a registry is the one this machine should be talking to.
 *
 * A signature proves a root is internally consistent; it does not prove it is
 * *this* registry. The root names the key that signs it, so a wholly different
 * registry — an impostor, a fork, a mirror that swapped its key — verifies just
 * as well. Two rules close that, and the explicit one wins:
 *
 *   - `MOGGI_REGISTRY_NPUB` is a pin: the registry's key must equal it, always;
 *   - otherwise trust on first use — the first key seen for a base is recorded,
 *     and any later difference is refused rather than silently accepted.
 *
 * TOFU has the usual first-contact weakness, which is exactly why the pin exists;
 * what it buys is that every *later* contact is checked, with no configuration.
 *
 * @return string a problem, or '' when the identity is acceptable
 */
function registryIdentityProblem(string $base, string $npub, ?string $pin): string
{
    if ($pin !== null && $pin !== '') {
        if (!isNpub($pin)) {
            return "the pinned registry key `{$pin}` is not an npub";
        }

        return $npub === $pin
            ? ''
            : "{$base} presents `{$npub}`, not the pinned key `{$pin}`";
    }

    $remembered = rememberedRegistryNpub($base);
    if ($remembered !== null && $remembered !== $npub) {
        return "{$base} presents a different registry key than this machine first trusted "
            . "(`{$npub}` != `{$remembered}`) — if the change is expected, pin the new key with MOGGI_REGISTRY_NPUB";
    }
    if ($remembered === null) {
        rememberRegistryNpub($base, $npub);
    }

    return '';
}

/**
 * Read a registry's signed root and open its catalog.
 *
 * The root and its signature are read here; the shards are left to the
 * `Catalog`, which fetches each one only when a name needs it. A root that lists
 * no `registry_npub` is still returned, with a signature verdict that says so —
 * the caller decides whether an unverifiable registry is fatal.
 *
 * @param ?callable(string, string): ?string $fetch overrides how bytes are read;
 *   the default reads a local directory or an http(s) URL
 */
function loadCatalog(string $base, ?callable $fetch = null, ?FetchLog $log = null, bool $useCache = true): Catalog
{
    $log ??= new FetchLog();
    $get = static function (string $path) use ($base, $fetch, $log): ?string {
        $log->requested($path);

        return fetchRegistryBytes($base, $path, $fetch);
    };

    $metadataDir = $useCache && cacheEnabled() ? catalogCacheDir($base) : null;
    $rootPath = $metadataDir . '/index.json';
    $signaturePath = $metadataDir . '/index.json.sig';

    $rootBytes = $get('index.json');
    if ($rootBytes === null) {
        throw new \RuntimeException("no registry at {$base}: index.json is missing");
    }
    try {
        $root = \json_decode($rootBytes, true, 512, \JSON_THROW_ON_ERROR);
    } catch (\JsonException $error) {
        throw new \RuntimeException("no registry at {$base}: index.json is not valid JSON ({$error->getMessage()})");
    }

    $npub = $root['registry_npub'] ?? null;
    $signatureBytes = null;
    $signature = ['ok' => false, 'note' => 'index.json names no registry_npub'];

    if (\is_string($npub)) {
        if ($metadataDir !== null
            && cachedBytes($rootPath) === $rootBytes
            && ($cached = cachedBytes($signaturePath)) !== null
        ) {
            $signature = verifyRootSignature($rootBytes, $cached, $npub);
            if ($signature['ok'] || !\str_contains($signature['note'], 'does not verify')) {
                $signatureBytes = $cached;
                $log->skipped('index.json.sig');
            }
        }

        if ($signatureBytes === null) {
            $signatureBytes = $get('index.json.sig') ?? '';
            $signature = verifyRootSignature($rootBytes, $signatureBytes, $npub);
        }
    }

    if ($metadataDir !== null) {
        storeBytes($rootPath, $rootBytes);
        if ($signature['ok']) {
            storeBytes($signaturePath, (string) $signatureBytes);
        }
    }

    return new Catalog($base, $root, $signature, $fetch === null ? null : \Closure::fromCallable($fetch), $log, $useCache);
}

/**
 * What a load cost, and what it did not have to fetch: the count makes the
 * sharding claim checkable, the second half is what the caching buys.
 *
 * @param list<string> $requests
 * @param list<string> $held
 */
function describeFetch(array $requests, array $held = []): string
{
    $text = \count($requests) . ' request' . (\count($requests) === 1 ? '' : 's');
    if ($requests !== []) {
        $text .= ': ' . \implode(', ', $requests);
    }
    if ($held !== []) {
        $text .= ' (' . \count($held) . ' held, not re-fetched: ' . \implode(', ', $held) . ')';
    }

    return $text;
}
