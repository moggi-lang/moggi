<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * What a release signature covers, and whether it verifies.
 *
 * The signed message is the sha256 of a canonical
 * manifest — provenance, artifact and version, keys sorted, no insignificant
 * whitespace, UTF-8. Every implementation has to build those bytes identically
 * or nothing verifies, so the client builds them in one place: here. The
 * signing tool and the worker do the same in their own language, and a
 * difference shows up as a signature that fails to verify rather than as a
 * package that installs anyway.
 *
 * BIP-340 caps a message at 128 bytes, which is why a digest is signed rather
 * than the manifest text itself.
 */

/**
 * Canonical JSON: keys sorted, no insignificant whitespace, UTF-8.
 *
 * `JSON_UNESCAPED_SLASHES` is load-bearing — a source URL contains `//`, and the
 * worker's `JSON.stringify` does not escape it.
 *
 * `{}` and `[]` are different bytes and the signature covers the bytes, so a
 * `stdClass` is how a caller says "this is a map, even when empty": decoding a
 * package file into arrays loses that distinction.
 */
function canonicalJson(mixed $value): string
{
    if (!\is_array($value) && !$value instanceof \stdClass) {
        return \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    $members = [];
    foreach ($value as $key => $member) {
        if ($value instanceof \stdClass) {
            $key = (string) $key;
        }
        $members[$key] = canonicalJson($member);
    }

    if (!$value instanceof \stdClass && \array_is_list($value)) {
        return '[' . \implode(',', $members) . ']';
    }

    \ksort($members, \SORT_STRING);
    $pairs = [];
    foreach ($members as $key => $encoded) {
        $pairs[] = \json_encode((string) $key, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . ':' . $encoded;
    }

    return '{' . \implode(',', $pairs) . '}';
}

/** The digest that identifies a source, by kind: a commit, or a sha256. */
function sourceDigest(mixed $source): ?string
{
    if ($source instanceof \stdClass) {
        $source = (array) $source;
    }
    if (!\is_array($source)) {
        return null;
    }
    $kind = $source['kind'] ?? null;

    return $kind === 'git' ? ($source['commit'] ?? null) : ($source['sha256'] ?? null);
}

/**
 * The 32-byte message a release signature covers, as lowercase hex.
 *
 * The manifest is provenance, the artifact, the version, and — since the
 * `descriptor`/`docs` scope change — the digest of the verbatim descriptor and of
 * the rendered docs tree, so a release pins *what it declares* and *what it
 * documents*, not only the bytes it ships. A member whose input is absent is
 * dropped rather than emitted as null, because the worker and the website build
 * the same bytes and `undefined` members are dropped there; `{}` is still a map
 * even when empty, which is why the third-party map is restored by hand.
 *
 * @param array<string, mixed> $release
 */
function releaseMessage(string $name, string $version, array $release): string
{
    $thirdParty = [];
    foreach ((array) ($release['third_party'] ?? []) as $kind => $hashes) {
        $thirdParty[(string) $kind] = (object) ((array) $hashes);
    }

    $manifest = [
        'blob' => $release['blob'] ?? null,
        'name' => $name,
        'source' => sourceDigest($release['source'] ?? null),
        'third_party' => (object) $thirdParty,
        'version' => $version,
    ];
    if (\is_string($release['descriptor'] ?? null)) {
        $manifest['descriptor'] = sha256Digest($release['descriptor']);
    }
    if (\is_string($release['docs'] ?? null) && $release['docs'] !== '') {
        $manifest['docs'] = $release['docs'];
    }

    return \hash('sha256', canonicalJson($manifest));
}

/**
 * BIP-340 verification, by the `schnorr` CLI the distribution ships.
 *
 * Most checks go through one long-lived `schnorr verify-batch` process (below),
 * so verifying a dependency tree costs one spawn rather than one per signature;
 * `verifySignatureOnce` is the fallback, and the only path when there is no
 * binary or the batch channel cannot be used.
 *
 * @return array{ok: bool, note: string}
 */
function verifySignature(string $messageHex, string $signatureHex, string $npub): array
{
    $batch = batchVerifier();
    if ($batch !== null) {
        $verdict = $batch->verify($npub, $signatureHex, $messageHex);
        if ($verdict !== null) {
            return $verdict;
        }
    }

    return verifySignatureOnce($messageHex, $signatureHex, $npub);
}

/**
 * The shared `verify-batch` channel, keyed by the binary it was started from: a
 * changed `MOGGI_SCHNORR` (the test harness swaps fakes) starts a new process
 * rather than answering from the old. `null` means there is no binary at all, and
 * verification falls back to `verifySignatureOnce`, which reports exactly why.
 */
function batchVerifier(): ?BatchVerifier
{
    static $current = null;
    $binary = schnorrBinary();
    if ($binary === null) {
        return null;
    }
    if ($current === null || $current['binary'] !== $binary) {
        if ($current !== null) {
            $current['verifier']->close();
        }
        $current = ['binary' => $binary, 'verifier' => new BatchVerifier($binary)];
    }

    return $current['verifier'];
}

/**
 * A `schnorr verify-batch` subprocess: requests on stdin, `valid`/`invalid`
 * verdicts on stdout, one per line.
 *
 * The process is reused for the life of the run, so a resolution verifying N
 * releases pays one spawn, not N. A request whose answer cannot be read (the
 * process died, the pipe broke, a verdict that is neither token) marks the
 * channel broken and returns null, and the caller falls back to the single-shot
 * path rather than guessing.
 */
final class BatchVerifier
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private bool $broken = false;

    public function __construct(private readonly string $binary)
    {
    }

    /** @return array{ok: bool, note: string}|null null when the channel is unusable */
    public function verify(string $npub, string $signatureHex, string $messageHex): ?array
    {
        if ($this->broken) {
            return null;
        }
        if ($this->process === null && !$this->start()) {
            $this->broken = true;

            return null;
        }

        $request = $npub . ' ' . \trim($signatureHex) . ' ' . $messageHex . "\n";
        if (@\fwrite($this->pipes[0], $request) === false || !@\fflush($this->pipes[0])) {
            return $this->fail();
        }
        $line = @\fgets($this->pipes[1]);
        if (!\is_string($line)) {
            return $this->fail();
        }

        $verdict = \trim($line);
        if ($verdict === 'valid') {
            return ['ok' => true, 'note' => "verified against {$npub}"];
        }
        if ($verdict === 'invalid') {
            return ['ok' => false, 'note' => "the signature does not verify against {$npub}"];
        }

        return $this->fail();
    }

    private function start(): bool
    {
        $process = @\proc_open(
            [$this->binary, 'verify-batch'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            return false;
        }
        $this->process = $process;
        $this->pipes = $pipes;

        return true;
    }

    /** @return null always, so a caller can `return $this->fail();` */
    private function fail(): ?array
    {
        $this->broken = true;
        $this->close();

        return null;
    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
        $this->pipes = [];
        if (\is_resource($this->process)) {
            @\proc_close($this->process);
        }
        $this->process = null;
    }
}

/** The single-shot path: one `schnorr verify` per call. */
function verifySignatureOnce(string $messageHex, string $signatureHex, string $npub): array
{
    $binary = schnorrBinary();
    if ($binary === null) {
        return ['ok' => false, 'note' => schnorrMissingNote()];
    }

    $process = \proc_open(
        [$binary, 'verify', $npub, \trim($signatureHex), $messageHex],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!\is_resource($process)) {
        return ['ok' => false, 'note' => "cannot run {$binary}"];
    }
    \fclose($pipes[0]);
    $stdout = \trim((string) \stream_get_contents($pipes[1]));
    $stderr = \trim((string) \stream_get_contents($pipes[2]));
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $code = \proc_close($process);

    if ($code === 0 && \trim($stdout) === 'valid') {
        return ['ok' => true, 'note' => "verified against {$npub}"];
    }

    return [
        'ok' => false,
        'note' => $stderr !== ''
            ? $stderr
            : ($stdout !== '' ? 'the verifier said: ' . \trim($stdout) : "the signature does not verify against {$npub}"),
    ];
}

/**
 * One release, against the *catalog's* allowed users — never a key the package
 * file nominates for itself, or a release could appoint its own signer.
 *
 * @param array<string, mixed> $release
 * @param list<string> $authors
 * @return array{ok: bool, note: string, signer: ?string}
 */
function verifyRelease(string $name, string $version, array $release, array $authors): array
{
    $signature = $release['signature'] ?? null;
    if ($signature instanceof \stdClass) {
        $signature = (array) $signature;
    }
    $signer = \is_array($signature) ? ($signature['npub'] ?? null) : null;
    $sig = \is_array($signature) ? ($signature['sig'] ?? null) : null;

    if (!\is_string($signer) || !\is_string($sig)) {
        return ['ok' => false, 'note' => 'the release has no signature', 'signer' => null];
    }
    if (!\in_array($signer, $authors, true)) {
        return ['ok' => false, 'note' => 'the signer is not on the package\'s allowed list', 'signer' => $signer];
    }

    $verdict = verifySignature(releaseMessage($name, $version, $release), $sig, $signer);

    return ['ok' => $verdict['ok'], 'note' => $verdict['note'], 'signer' => $signer];
}

/**
 * The author whose npub signs the release.
 *
 * `--as` names one; without it a single-author descriptor is unambiguous, and
 * several authors without a choice is an error rather than a guess — signing as
 * the wrong identity is not a mistake that should be quiet.
 *
 * @param list<string> $authors
 */
function selectAuthor(array $authors, ?string $as): string
{
    if ($as !== null) {
        if (!\in_array($as, $authors, true)) {
            throw new \RuntimeException("`{$as}` is not an npub in this package's [author] blocks");
        }

        return $as;
    }
    if (\count($authors) === 1) {
        return $authors[0];
    }
    if ($authors === []) {
        throw new \RuntimeException('the descriptor names no author to sign as');
    }

    throw new \RuntimeException(
        'this package has several authors — choose one with --as NPUB: ' . \implode(', ', $authors),
    );
}

/**
 * The `source` member of a release record: where the code came from.
 *
 * `--url` plus `--commit` names a repository and a commit — the one form a
 * client can reproduce from when the registry does not serve the blob. Without
 * them the release records the directory it was packed from, which is
 * provenance rather than a fetch recipe: a path on this machine is nothing
 * another machine can read, so no client reproduces from it. Only `--url` plus
 * `--commit` records an origin another machine can follow — and the record
 * still says where the release was made either way.
 */
function releaseSource(?string $url, ?string $commit, string $directory, string $contentDigest): array
{
    if (($url === null) !== ($commit === null)) {
        throw new \RuntimeException('--url and --commit go together — a git source names both');
    }
    if ($url !== null) {
        return ['kind' => 'git', 'url' => $url, 'commit' => $commit];
    }

    return ['kind' => 'dir', 'path' => \basename((string) \realpath($directory)), 'sha256' => $contentDigest];
}

/**
 * The unsigned release record: provenance, artifact, version, declaration.
 *
 * The same shape `publish` signs inline, `pack --record` prints, and `publish
 * --signed` expects to read back — built here so the three cannot drift apart.
 * `descriptor` is the verbatim descriptor bytes (its digest is what the
 * signature covers), and the `docs` digest is present only when the docs were
 * rendered, so a record made with `--no-docs` says nothing about them.
 *
 * @param array<string, mixed> $source
 * @param array<string, mixed> $thirdParty
 */
function buildReleaseRecord(
    string $name,
    string $version,
    array $source,
    string $blob,
    array $thirdParty,
    string $descriptor,
    ?string $docsDigest,
): array {
    $release = [
        'name' => $name,
        'version' => $version,
        'source' => $source,
        'blob' => $blob,
        'third_party' => $thirdParty,
        'descriptor' => $descriptor,
    ];
    if ($docsDigest !== null && $docsDigest !== '') {
        $release['docs'] = $docsDigest;
    }

    return $release;
}

/**
 * Sign an unsigned release record with an author's nsec.
 *
 * The signature covers the canonical manifest (`releaseMessage`), and the
 * record is verified straight back against the declared author before it is
 * returned — the same check `verifyRelease` will run at the other end — so a
 * key that is not the author's fails here, next to the key, rather than at the
 * registry after a round trip. An already-signed record is refused rather than
 * re-signed: a second signature would silently replace the first.
 *
 * @param array<string, mixed> $release
 * @return array<string, mixed>
 */
function signRelease(string $name, string $version, array $release, string $author, string $nsec): array
{
    if (isset($release['signature'])) {
        throw new \RuntimeException('this record is already signed — signing again would silently replace the signature');
    }

    $signed = $release;
    $signed['signature'] = ['npub' => $author, 'sig' => signMessage(releaseMessage($name, $version, $release), $nsec)];

    $verdict = verifySignature(releaseMessage($name, $version, $signed), (string) $signed['signature']['sig'], $author);
    if (!$verdict['ok']) {
        throw new \RuntimeException("the key does not match author {$author}: {$verdict['note']}");
    }

    return $signed;
}

/**
 * Write a release record to disk as canonical JSON.
 *
 * @param array<string, mixed> $release
 */
function writeReleaseRecord(string $path, array $release): void
{
    if (@\file_put_contents($path, canonicalJson($release) . "\n") === false) {
        throw new \RuntimeException("cannot write {$path}");
    }
}

/**
 * Read a release record back: JSON, an object, naming a name and a version.
 *
 * The signature itself is not checked here — that is `verifyRelease`, against
 * the package's allowed list, which a file on disk cannot nominate for itself.
 *
 * @return array<string, mixed>
 */
function readReleaseRecord(string $path): array
{
    $bytes = @\file_get_contents($path);
    if ($bytes === false) {
        throw new \RuntimeException("cannot read {$path}");
    }
    $record = \json_decode($bytes, true);
    if (!\is_array($record) || (\array_is_list($record) && $record !== [])) {
        throw new \RuntimeException("{$path} is not a release record — it does not decode to a JSON object");
    }
    if (!\is_string($record['name'] ?? null) || !\is_string($record['version'] ?? null)) {
        throw new \RuntimeException("{$path} is not a release record — it names no name and version");
    }

    return $record;
}

/**
 * Check a pre-signed record against the tree it claims to release.
 *
 * `publish --signed` trusts the file for the signature but nothing else: the
 * blob, the verbatim descriptor and the docs digest are recomputed from the
 * directory and must match byte for byte, or the record describes some other
 * tree. The signature is verified against the descriptor's authors — never a
 * key the record nominates for itself. Returns the signer, who then
 * authenticates the upload's requests.
 *
 * @param array<string, mixed> $record
 * @param list<string> $authors
 */
function checkSignedRelease(
    array $record,
    string $name,
    string $version,
    string $blob,
    string $descriptor,
    ?string $docsDigest,
    array $authors,
): string {
    if (($record['name'] ?? null) !== $name || ($record['version'] ?? null) !== $version) {
        throw new \RuntimeException(
            'the record releases ' . (string) ($record['name'] ?? '?') . ' ' . (string) ($record['version'] ?? '?')
            . ", but this directory packs {$name} {$version} — it signs some other release",
        );
    }
    if (($record['blob'] ?? null) !== $blob) {
        throw new \RuntimeException('the record names a different blob — the tree changed since it was recorded; re-record');
    }
    if (($record['descriptor'] ?? null) !== $descriptor) {
        throw new \RuntimeException('the record carries a different descriptor — the descriptor changed since it was recorded; re-record');
    }
    $recordDocs = $record['docs'] ?? null;
    if ($recordDocs !== $docsDigest) {
        throw new \RuntimeException(
            $recordDocs === null
                ? 'the record names no docs, but this directory renders some — re-record without --no-docs, or pack with it'
                : 'the record names different docs — the documentation changed since it was recorded; re-record',
        );
    }
    if (!isset($record['signature'])) {
        throw new \RuntimeException('the record has no signature — sign it with `moggi sign` first');
    }

    $verdict = verifyRelease($name, $version, $record, $authors);
    if (!$verdict['ok'] || $verdict['signer'] === null) {
        throw new \RuntimeException('the record\'s signature does not verify: ' . $verdict['note']);
    }

    return $verdict['signer'];
}
