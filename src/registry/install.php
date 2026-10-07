<?php declare(strict_types=1);

namespace Moggi\Registry;

use Moggi\Cache;

use function Moggi\Cache\ensureDir;
use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\runProcess;

/**
 * The client's other half: check a lock, then fetch, verify and unpack what it
 * names. Four checks, none of them skippable, in order:
 *
 * 1. the lock against the catalog — a version that vanished, or a record whose
 *    digest moved, means the lock is stale or the registry rewrote history;
 * 2. the package file against the digest the catalog published for it, so two
 *    records cannot be swapped for one another;
 * 3. the release signature against the catalog's *allowed users*, never a key
 *    the package file nominates for itself;
 * 4. the archive against its blob digest, before anything is unpacked.
 *
 * Documentation is not a fifth check: it is served by the registry's website and
 * rendered locally from the sources an install just unpacked, and the client
 * never fetches it. Its digest still rides in the signed manifest, so the site
 * can be held to it, but a docs origin that is down does not fail an install.
 *
 * Where the bytes come from is not part of the contract: the registry's blob and
 * the release's own `source` are equal origins, checked the same way. When the
 * registry does not serve the blob, the `source` is reproduced instead — a clone
 * at the declared commit, a directory, or an upstream archive — and packed with
 * the same rule `moggi publish` used, so the `blob` digest is the check either
 * way and the origin never changes what the bytes are.
 *
 * The *transport* is not equal, though: the release that names a `source` is
 * attacker-controlled here, so a `source` URL is fetched over `https` only — no
 * git remote helpers, no `file://`, no bare paths — unless the operator opts into
 * local paths with `MOGGI_ALLOW_LOCAL_SOURCES`. See `sourceUrlProblem`.
 */

/**
 * How the lock and the catalog disagree — the check `install` and `build` both
 * run before touching anything.
 *
 * A *newer* version is not a disagreement: that is what `moggi update` is for.
 * A version that vanished, or a release record whose digest changed, is.
 *
 * @param array<string, mixed> $lock
 * @param array<string, array<string, mixed>> $packages the catalog's packages
 * @return list<string>
 */
function lockDisagreements(array $lock, array $packages): array
{
    $problems = [];
    foreach (($lock['packages'] ?? []) as $name => $entry) {
        $entry = (array) $entry;
        $nameProblem = packageNameProblem((string) $name);
        if ($nameProblem !== null) {
            $problems[] = "the lock names a package that is not a name: {$nameProblem}";
            continue;
        }
        $known = $packages[$name] ?? null;
        if ($known === null) {
            $problems[] = "{$name} is no longer in the catalog";
            continue;
        }
        $version = (string) ($entry['version'] ?? '');
        if (!isset($known['versions'][$version])) {
            $problems[] = "{$name} {$version} is not published any more";
            continue;
        }
        $digest = $entry['digest'] ?? null;
        if (!\is_string($digest) || $digest === '') {
            $problems[] = "{$name} names no release digest in the lock — re-resolve and rewrite it";
            continue;
        }
        $published = $known['digest'] ?? null;
        if (!\is_string($published) || $published === '') {
            $problems[] = "{$name} has no release digest in the catalog";
            continue;
        }
        if ($digest !== $published) {
            $problems[] = "{$name}'s release record changed since the lock was written ({$digest} -> {$published})";
        }
    }

    return $problems;
}

/**
 * One package file, held in the metadata cache and validated by the digest the
 * catalog published for it — the same validator a shard gets.
 *
 * @return array{bytes: ?string, path: string, held: bool, problem: ?string}
 */
function fetchPackageFile(string $base, string $name, ?string $digest, bool $useCache, ?callable $fetch, FetchLog $log): array
{
    assertPackageName($name, 'package file fetch');
    $path = catalogCacheDir($base) . '/packages/' . $name . '.json';
    $cacheable = $useCache && Cache\cacheEnabled();

    if ($cacheable && $digest !== null) {
        $bytes = cachedBytes($path, $digest);
        if ($bytes !== null) {
            $log->skipped("packages/{$name}.json");

            return ['bytes' => $bytes, 'path' => $path, 'held' => true, 'problem' => null];
        }
    }

    $log->requested("packages/{$name}.json");
    $bytes = fetchRegistryBytes($base, "packages/{$name}.json", $fetch);
    if ($bytes === null) {
        return ['bytes' => null, 'path' => $path, 'held' => false, 'problem' => "packages/{$name}.json is missing from the registry"];
    }
    $actual = sha256Digest($bytes);
    if ($digest !== null && $actual !== $digest) {
        return ['bytes' => null, 'path' => $path, 'held' => false, 'problem' => "packages/{$name}.json does not match the catalog's digest ({$actual} != {$digest})"];
    }

    if ($cacheable) {
        storeBytes($path, $bytes);
    }

    return ['bytes' => $bytes, 'path' => $path, 'held' => false, 'problem' => null];
}

/**
 * Fetch one package file and verify the release inside it: the record against
 * its digest, the signature against the catalog's *allowed users* — never a key
 * the package file nominates for itself.
 *
 * The two checks `install` and `verify` share, so a release cannot pass one and
 * fail the other.
 *
 * @param array<string, mixed> $entry the catalog entry (its `authors` are the verifier)
 * @return array{ok: bool, detail: string, release: ?array<string, mixed>, signer: ?string}
 */
function fetchVerifiedRelease(string $base, string $name, string $version, mixed $lockedDigest, array $entry, bool $useCache, ?callable $fetch, FetchLog $log): array
{
    $file = fetchPackageFile($base, $name, $lockedDigest ?? ($entry['digest'] ?? null), $useCache, $fetch, $log);
    if ($file['problem'] !== null) {
        return ['ok' => false, 'detail' => $file['problem'], 'release' => null, 'signer' => null];
    }

    $record = \json_decode((string) $file['bytes'], true, 512, \JSON_THROW_ON_ERROR);
    $release = $record['versions'][$version] ?? null;
    if (!\is_array($release)) {
        return ['ok' => false, 'detail' => "{$name} {$version} is not in its package file", 'release' => null, 'signer' => null];
    }

    $verdict = verifyRelease($name, $version, $release, (array) ($entry['authors'] ?? []));
    if (!$verdict['ok']) {
        return ['ok' => false, 'detail' => 'signature: ' . $verdict['note'], 'release' => null, 'signer' => $verdict['signer']];
    }

    return ['ok' => true, 'detail' => '', 'release' => $release, 'signer' => $verdict['signer']];
}

/**
 * One release's archive, in the user-level content cache, named by its own
 * digest — so it is fetched once per machine, not once per project.
 *
 * @return array{path: ?string, held: bool, problem: ?string, origin: string}
 */
function fetchBlob(string $base, string $name, string $version, array $release, ?callable $fetch, FetchLog $log, ?string $blobsBase = null): array
{
    $origin = $blobsBase ?? $base;
    $digest = $release['blob'] ?? null;
    if (!\is_string($digest) || !\str_starts_with($digest, 'sha256:')) {
        return ['path' => null, 'held' => false, 'problem' => "{$name} {$version} names no blob digest", 'origin' => ''];
    }

    $path = downloadPath($digest);
    if (\is_file($path) && 'sha256:' . \hash_file('sha256', $path) === $digest) {
        $log->skipped("blobs/{$digest}");

        return ['path' => $path, 'held' => true, 'problem' => null, 'origin' => 'cache'];
    }

    $blobPath = 'blobs/' . \str_replace('sha256:', '', $digest);
    $log->requested($blobPath);
    $bytes = fetchRegistryBytes($origin, $blobPath, $fetch);
    if (\is_string($bytes) && \str_starts_with($bytes, "\x1f\x8b")) {
        $inflated = @\gzdecode($bytes);
        if ($inflated === false) {
            return ['path' => null, 'held' => false, 'problem' => "{$name} {$version}: the archive is gzip-encoded and cannot be decoded", 'origin' => ''];
        }
        $bytes = $inflated;
        assertResponseWithinCap(\strlen($bytes), $blobPath);
    }
    $fromSource = null;
    if ($bytes === null) {
        $fallback = fetchBlobFromSource($release, $fetch);
        if ($fallback['problem'] !== null) {
            return ['path' => null, 'held' => false, 'problem' => "{$name} {$version}: " . $fallback['problem'], 'origin' => ''];
        }
        $bytes = $fallback['bytes'];
        $fromSource = $fallback['origin'];
    }
    $actual = sha256Digest((string) $bytes);
    if ($actual !== $digest) {
        $detail = originIsLocalSource($fromSource)
            ? "the archive does not match its blob digest {$digest} (reproduced from a local `source`; the produced digest is withheld)"
            : "the archive does not match its blob digest ({$actual} != {$digest})";

        return ['path' => null, 'held' => false, 'problem' => "{$name} {$version}: {$detail}", 'origin' => ''];
    }

    $staging = $path . '.part-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
    if (!ensureDir(\dirname($path)) || @\file_put_contents($staging, $bytes) === false || !@\rename($staging, $path)) {
        @\unlink($staging);

        return ['path' => null, 'held' => false, 'problem' => "cannot write {$path}", 'origin' => ''];
    }

    return ['path' => $path, 'held' => false, 'problem' => null, 'origin' => $fromSource ?? 'registry'];
}

/**
 * A release's bytes from its own `source`, when the registry does not serve its
 * blob — a clone at the declared commit, a directory, or an upstream archive.
 *
 * Whatever the kind, the result is packed with the same rule `moggi publish`
 * used, so it is checked against the same `blob` digest as a downloaded blob
 * (§3.3): the digest is the contract, the origin is not.
 *
 * @param array<string, mixed> $release
 * @return array{bytes: ?string, problem: ?string, origin: string}
 */
function fetchBlobFromSource(array $release, ?callable $fetch): array
{
    $source = $release['source'] ?? null;
    if ($source instanceof \stdClass) {
        $source = (array) $source;
    }
    if (!\is_array($source) || !\is_string($source['kind'] ?? null)) {
        return ['bytes' => null, 'problem' => 'the registry does not serve its blob, and the release names no `source` to reproduce it from', 'origin' => ''];
    }

    return match ($source['kind']) {
        'dir' => sourceBlobFromDir($source),
        'git' => sourceBlobFromGit($source),
        'archive' => sourceBlobFromArchive($source, $fetch),
        default => [
            'bytes' => null,
            'problem' => "the registry does not serve its blob, and its `{$source['kind']}` source is not one this client can reproduce",
            'origin' => '',
        ],
    };
}

/**
 * @param array<string, mixed> $source
 * @return array{bytes: ?string, problem: ?string, origin: string}
 */
function sourceBlobFromDir(array $source): array
{
    if (!localSourcesAllowed()) {
        return [
            'bytes' => null,
            'problem' => 'the registry does not serve its blob, and a `dir` source is a local path — set MOGGI_ALLOW_LOCAL_SOURCES=1 to allow it',
            'origin' => '',
        ];
    }

    $path = \is_string($source['path'] ?? null) ? $source['path'] : '';
    if ($path === '' || !\is_dir($path)) {
        return ['bytes' => null, 'problem' => "the registry does not serve its blob, and its `dir` source `{$path}` is not a directory here", 'origin' => ''];
    }

    return ['bytes' => packDirectory($path), 'problem' => null, 'origin' => 'source:dir'];
}

/**
 * The tree a `git` source names, packed by the publish rule.
 *
 * The checkout is the tree the release hashed, so the bytes on disk must be the
 * bytes the commit holds, not what this host's git would write: `core.autocrlf`
 * and `core.eol` decide between LF and CRLF at checkout time, and a Windows git
 * defaults to converting. Both are pinned to LF here, so the working tree is the
 * same on every host and the digest matches the one the release declares.
 *
 * @param array<string, mixed> $source
 * @return array{bytes: ?string, problem: ?string, origin: string}
 */
function sourceBlobFromGit(array $source): array
{
    $url = \is_string($source['url'] ?? null) ? $source['url'] : '';
    $commit = \is_string($source['commit'] ?? null) ? $source['commit'] : '';
    if ($url === '' || $commit === '') {
        return ['bytes' => null, 'problem' => 'the registry does not serve its blob, and its `git` source names no url and commit', 'origin' => ''];
    }
    $urlProblem = sourceUrlProblem('git', $url);
    if ($urlProblem !== null) {
        return ['bytes' => null, 'problem' => 'the registry does not serve its blob, and ' . $urlProblem, 'origin' => ''];
    }
    $git = findExecutable('git');
    if ($git === null) {
        return ['bytes' => null, 'problem' => 'the registry does not serve its blob, and reproducing a `git` source needs `git` on PATH', 'origin' => ''];
    }

    $work = sourceWorkDir();
    try {
        $clone = runProcess([$git, '-c', 'advice.detachedHead=false', 'clone', '--quiet', '--no-checkout', '--', $url, $work]);
        if ($clone['exitCode'] !== 0) {
            return ['bytes' => null, 'problem' => 'cannot clone its `git` source: ' . sourceProcessError($clone), 'origin' => ''];
        }
        $checkout = runProcess([$git, '-C', $work, '-c', 'core.autocrlf=false', '-c', 'core.eol=lf', 'checkout', '--quiet', $commit]);
        if ($checkout['exitCode'] !== 0) {
            return ['bytes' => null, 'problem' => "cannot check out commit {$commit} of its `git` source: " . sourceProcessError($checkout), 'origin' => ''];
        }

        return ['bytes' => packDirectory($work), 'problem' => null, 'origin' => sourceUrlIsLocal($url) ? 'source:git-local' : 'source:git'];
    } finally {
        removeTree($work);
    }
}

/**
 * @param array<string, mixed> $source
 * @return array{bytes: ?string, problem: ?string, origin: string}
 */
function sourceBlobFromArchive(array $source, ?callable $fetch): array
{
    $url = \is_string($source['url'] ?? null) ? $source['url'] : '';
    if ($url === '') {
        return ['bytes' => null, 'problem' => 'the registry does not serve its blob, and its `archive` source names no url', 'origin' => ''];
    }
    $urlProblem = sourceUrlProblem('archive', $url);
    if ($urlProblem !== null) {
        return ['bytes' => null, 'problem' => 'the registry does not serve its blob, and ' . $urlProblem, 'origin' => ''];
    }

    $raw = fetchRawSourceBytes($url, $fetch);
    if ($raw === null) {
        return ['bytes' => null, 'problem' => "the registry does not serve its blob, and its `archive` source {$url} cannot be read", 'origin' => ''];
    }
    $declared = \is_string($source['sha256'] ?? null) ? $source['sha256'] : '';
    if ($declared !== '') {
        $actual = sha256Digest($raw);
        if ($actual !== $declared) {
            return ['bytes' => null, 'problem' => "its `archive` source does not match the sha256 the release names ({$actual} != {$declared})", 'origin' => ''];
        }
    }

    $work = sourceWorkDir();
    try {
        if (!ensureDir($work)) {
            return ['bytes' => null, 'problem' => "cannot create {$work}", 'origin' => ''];
        }
        $problem = extractArchive(decodeTransport($raw), $work);
        if ($problem !== null) {
            return ['bytes' => null, 'problem' => "its `archive` source cannot be unpacked: {$problem}", 'origin' => ''];
        }

        return ['bytes' => packDirectory($work), 'problem' => null, 'origin' => sourceUrlIsLocal($url) ? 'source:archive-local' : 'source:archive'];
    } finally {
        removeTree($work);
    }
}

/**
 * Whether a `source` URL may be fetched from at all.
 *
 * A release's `source` travels inside a signed record, but the record is the
 * attacker in this scenario: a registry that answers 404 for `blobs/<digest>`
 * can name any `source` it likes, and `fetchBlobFromSource` would then obey it.
 * So the transport is narrowed to what reproducing a package actually needs —
 * ordinary `https` — which rejects git remote helpers (`ext::sh -c …` runs a
 * shell command), local repository reads (`file://`, a bare path) and every
 * other scheme. A local path is used only when the operator opts in with
 * `MOGGI_ALLOW_LOCAL_SOURCES=1`, which is what publishing from a checkout needs.
 */
function sourceUrlProblem(string $kind, string $url): ?string
{
    if (\str_starts_with($url, 'https://')) {
        return null;
    }
    if (localSourcesAllowed() && sourceUrlIsLocal($url)) {
        return null;
    }

    return "its `{$kind}` source url `{$url}` is not allowed — only `https` URLs are fetched"
        . (sourceUrlIsLocal($url) ? '; set MOGGI_ALLOW_LOCAL_SOURCES=1 to allow local paths' : '');
}

/** A `source` URL that names something on this machine: a `file://` URL or a path. */
function sourceUrlIsLocal(string $url): bool
{
    if (\preg_match('#^[A-Za-z]:[\\\\/]#', $url) === 1) {
        return true;
    }
    if (\str_starts_with($url, 'file://')) {
        return true;
    }

    return \preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $url) !== 1;
}

/** Whether `MOGGI_ALLOW_LOCAL_SOURCES` opts this run into local `source` paths. */
function localSourcesAllowed(): bool
{
    $value = \getenv('MOGGI_ALLOW_LOCAL_SOURCES');

    return \is_string($value) && \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
}

/** Whether a reproduced `source` was a local one, whose digest is not reported. */
function originIsLocalSource(?string $origin): bool
{
    return $origin === 'source:dir' || ($origin !== null && \str_ends_with($origin, '-local'));
}

/**
 * Read a `source` URL exactly as published.
 *
 * `fetchBytes` decodes transport coding; the sha256 a release names for an
 * archive source is over the archive itself, so the raw bytes are what is hashed.
 *
 * @param ?callable(string, string): ?string $fetch
 */
function fetchRawSourceBytes(string $url, ?callable $fetch): ?string
{
    $directory = \dirname($url);
    $file = \basename($url);
    $bytes = $fetch !== null ? $fetch($directory, $file) : fetchBytes($directory, $file, false);
    if (\is_string($bytes)) {
        assertResponseWithinCap(\strlen($bytes), $file);
    }

    return \is_string($bytes) ? $bytes : null;
}

/** An empty private working directory a source is reproduced into. */
function sourceWorkDir(): string
{
    return \sys_get_temp_dir() . '/moggi-source-' . \getmypid() . '-' . \bin2hex(\random_bytes(6));
}

/**
 * The useful tail of a failed tool invocation, for a refusal a human reads.
 *
 * @param array{exitCode: int, stdout: string, stderr: string} $result
 */
function sourceProcessError(array $result): string
{
    $output = \trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

    return $output === '' ? "the tool exited {$result['exitCode']}" : \substr($output, -500);
}

/**
 * Unpack a canonical archive into the cache, atomically.
 *
 * Our own reader rather than `PharData`, because a blob is named after its digest
 * and so has no extension for Phar to go by, and because a downloaded archive has
 * to be refused for the paths and entry types `extractArchive` rejects.
 *
 * The tree is extracted into a sibling staging directory and only then renamed
 * into place, so a reader — a concurrent `build`, or the next run after a crash —
 * sees either no tree or a complete one, never a half-written one. The `.unpacked`
 * marker is written into the staging tree *before* the rename, which is what
 * makes it meaningful: its presence at the destination means a finished extract,
 * so a destination without it is a leftover from an interrupted run and is
 * removed rather than merged into.
 *
 * `rename()` is atomic within a filesystem and the staging directory is a sibling
 * of the destination, so the two are on the same one. Windows cannot rename onto
 * an existing directory, so the leftover is removed first (above).
 *
 * @return ?string a problem, or null on success
 */
function unpackArchive(string $archivePath, string $destination): ?string
{
    if (\is_file($destination . '/.unpacked')) {
        return null;
    }

    $archive = @\file_get_contents($archivePath);
    if ($archive === false) {
        return "cannot read {$archivePath}";
    }

    if (\is_dir($destination)) {
        removeTree($destination);
    }
    if (!ensureDir(\dirname($destination))) {
        return 'cannot create ' . \dirname($destination);
    }

    $staging = $destination . '.unpack-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
    if (!ensureDir($staging)) {
        return "cannot create {$staging}";
    }

    $problem = extractArchive($archive, $staging);
    if ($problem !== null) {
        removeTree($staging);

        return $problem;
    }

    if (@\file_put_contents($staging . '/.unpacked', 'ok') === false) {
        removeTree($staging);

        return "cannot mark {$staging} unpacked";
    }

    if (!@\rename($staging, $destination)) {
        removeTree($staging);

        return "cannot move the unpacked tree into {$destination}";
    }

    return null;
}

/**
 * Resolve a relative path under `$base`, or null when it climbs out of it.
 *
 * Lexical, and deliberately so: the entry need not exist to be judged, and the
 * check is about the *declared* path rather than about whatever a symlink points
 * at today. A leading `/` is absolute and refused, as is any `..` that would
 * leave the base; interior `..` that stays inside is folded away.
 */
function containedPath(string $base, string $relative): ?string
{
    $relative = \str_replace('\\', '/', \trim($relative));
    if ($relative === '' || \str_starts_with($relative, '/') || \preg_match('#^[A-Za-z]:#', $relative) === 1) {
        return null;
    }

    $parts = [];
    foreach (\explode('/', $relative) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts === []) {
                return null;
            }
            \array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    $base = \rtrim($base, '/');

    return $parts === [] ? $base : $base . '/' . \implode('/', $parts);
}

/**
 * The library roots a package provides: one absolute path per `[lib]`
 * `source-dirs` entry, resolved *inside* the install. `build` turns these into
 * `--lib` roots.
 *
 * The containment is the point: a descriptor travels inside the package, and a
 * `source-dirs` of `../../..` or `/etc` would otherwise hand the compiler a root
 * outside the tree the signature covers. An entry that escapes is a refusal
 * naming the package, not a silently dropped root.
 *
 * @return list<string>
 */
function libraryRoots(string $installDir, string $descriptorText): array
{
    $ini = @\parse_ini_string($descriptorText, true, \INI_SCANNER_RAW);
    if ($ini === false || !\is_array($ini['lib'] ?? null)) {
        return [];
    }

    $base = \rtrim($installDir, '/');
    $roots = [];
    foreach (splitList((string) ($ini['lib']['source-dirs'] ?? '')) as $directory) {
        $root = containedPath($base, $directory);
        if ($root === null) {
            throw new \RuntimeException(
                "a [lib] source-dirs entry `{$directory}` climbs out of its package — refusing to use it as a library root",
            );
        }
        $roots[] = $root;
    }

    return $roots;
}

/**
 * Check the lock against a fresh catalog, then install every package it names:
 * fetch the release record, verify its signature, fetch the archive, verify its
 * digest against the blob the release names, unpack it, and say where its
 * library roots are. Documentation is not fetched: it is served by the registry's
 * website and rendered locally.
 *
 * @param array<string, mixed> $lock
 * @param array<string, array<string, mixed>> $packages
 * @return array{results: list<array<string, mixed>>, ok: bool}
 */
function installLockedPackages(string $base, array $lock, array $packages, bool $useCache = true, ?callable $fetch = null, ?FetchLog $log = null, ?string $blobsBase = null): array
{
    $log ??= new FetchLog();
    $results = [];
    $ok = true;

    foreach (($lock['packages'] ?? []) as $name => $locked) {
        $locked = (array) $locked;
        $version = (string) ($locked['version'] ?? '');
        $entry = $packages[$name] ?? [];
        $result = ['name' => $name, 'version' => $version, 'status' => 'installed', 'detail' => '', 'roots' => []];

        $verified = fetchVerifiedRelease($base, $name, $version, $locked['digest'] ?? null, $entry, $useCache, $fetch, $log);
        if (!$verified['ok']) {
            $result['status'] = 'refused';
            $result['detail'] = $verified['detail'];
            $results[] = $result;
            $ok = false;
            continue;
        }
        $release = (array) $verified['release'];

        $blob = fetchBlob($base, $name, $version, $release, $fetch, $log, $blobsBase);
        if ($blob['problem'] !== null) {
            $result['status'] = 'refused';
            $result['detail'] = $blob['problem'];
            $results[] = $result;
            $ok = false;
            continue;
        }

        $install = lockedInstallDir($name, $locked['digest'] ?? null);
        $problem = unpackArchive((string) $blob['path'], $install);
        if ($problem !== null) {
            $result['status'] = 'refused';
            $result['detail'] = $problem;
            $results[] = $result;
            $ok = false;
            continue;
        }

        $roots = libraryRoots($install, (string) ($release['descriptor'] ?? ''));
        $result['detail'] = \sprintf(
            'signed by %s, blob from %s, unpacked to %s',
            shortNpub((string) $verified['signer']),
            $blob['origin'],
            $install,
        );
        $result['roots'] = $roots;
        if ($blob['held']) {
            $result['status'] = 'cached';
        } else {
            $result['status'] = 'installed';
        }
        $results[] = $result;
    }

    return ['results' => $results, 'ok' => $ok];
}

/** `npub1abcd…wxyz`, for messages a human reads. */
function shortNpub(string $npub): string
{
    return \strlen($npub) > 20 ? \substr($npub, 0, 12) . '…' . \substr($npub, -4) : $npub;
}

/**
 * The install directory for one locked package: `packages/<name>/<digest>`,
 * keyed by the *release record* digest a lock holds — not by version, and not by
 * the source digest. So `build` goes from a lock to a directory without fetching
 * anything, and the directory changes whenever the provenance or the artifact
 * does.
 */
function lockedInstallDir(string $name, mixed $digest): string
{
    return installDir($name, \is_string($digest) ? $digest : '');
}

/** Whether a locked package has been unpacked — what `build` requires before it compiles. */
function isInstalled(string $name, mixed $digest): bool
{
    return \is_file(lockedInstallDir($name, $digest) . '/.unpacked');
}
