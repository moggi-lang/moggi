<?php declare(strict_types=1);

/**
 * A registry without `blobs/<digest>` can still serve a release through
 * its own `source`: `blob` verifies the bytes regardless of origin.
 *
 * Cover upstream archive and git-commit retrieval, registry-blob
 * precedence, and the transport rule preventing a signed record from
 * directing the client to read local files.
 *
 * Source URLs must use HTTPS. Archive tests inject `$fetch` rather than
 * using the network, exercising unpacking, re-packing, and digest
 * verification independently of the socket.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Registry\archiveDigest;

use function Moggi\Registry\fetchBlob;
use function Moggi\Registry\fetchBlobFromSource;

use Moggi\Registry\FetchLog;

use function Moggi\Registry\packDirectory;
use function Moggi\Registry\sha256Digest;
use function Moggi\Registry\sourceUrlIsLocal;
use function Moggi\Registry\sourceUrlProblem;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$work = \sys_get_temp_dir() . '/moggi-source-fallback-' . \bin2hex(\random_bytes(6));
$tree = $work . '/pkg';
\mkdir($tree . '/sub', 0777, true);
\file_put_contents($tree . '/a.txt', "hello\n");
\file_put_contents($tree . '/sub/b.txt', "world\n");

$remove = static function (string $path) use (&$remove): void {
    if (! \file_exists($path) && ! \is_link($path)) {
        return;
    }
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

\putenv('MOGGI_CACHE_DIR=' . $work . '/cache');
\putenv('MOGGI_USER_CACHE=' . $work . '/user-cache');

try {
    $archive = packDirectory($tree);
    $blob = archiveDigest($archive);

    // --- the two transports a source can name --------------------------------
    // `https` and nothing else. A signed record is the attacker in the case that
    // matters, so the rule that keeps it from naming a path on this machine has no
    // opt-out: a local package is declared as a local dependency, not smuggled in
    // through a registry release.
    $assert(sourceUrlProblem('git', 'https://example.invalid/r') === null, 'https is a permitted source transport');
    foreach (['ext::sh -c id', 'ssh://h/r', 'http://h/r', 'file:///etc/passwd', '/tmp/repo', '../repo', 'C:\\repo'] as $refused) {
        $assert(sourceUrlProblem('git', $refused) !== null, "`{$refused}` must not be a permitted git source");
    }
    $assert(sourceUrlProblem('archive', 'ext::sh -c id') !== null, 'a remote helper is refused for an archive source too');
    $assert(\str_contains((string) sourceUrlProblem('git', 'http://h/r'), 'only `https` URLs are fetched'), 'a plain-http refusal names the rule: ' . (string) sourceUrlProblem('git', 'http://h/r'));
    $assert(\str_contains((string) sourceUrlProblem('git', '/tmp/repo'), "the publisher's"), 'a local refusal says whose path it is: ' . (string) sourceUrlProblem('git', '/tmp/repo'));

    // What the policy is built on, asserted on its own: the classifier decides what
    // "on this machine" means, and a URL it misreads is the difference between a
    // refusal and a local read.
    foreach (['file:///etc/passwd', '/tmp/repo', '../repo', 'C:\\repo', 'C:/repo', '\\\\server\\share', ''] as $local) {
        $assert(sourceUrlIsLocal($local), "`{$local}` names this machine");
    }
    foreach (['https://example.invalid/r', 'ssh://h/r', 'http://h/r', 'ext::sh -c id'] as $remote) {
        $assert(! sourceUrlIsLocal($remote), "`{$remote}` names another machine");
    }

    // No environment variable widens the transport: the opt-in that once allowed a
    // local path is gone, and setting its name to what it used to accept leaves the
    // refusal exactly as it was.
    foreach (['1', 'true', 'yes', 'on'] as $value) {
        \putenv('MOGGI_ALLOW_LOCAL_SOURCES=' . $value);
        $assert(sourceUrlProblem('git', '/tmp/repo') !== null, "MOGGI_ALLOW_LOCAL_SOURCES={$value} must not permit a local git source");
        $assert(sourceUrlProblem('archive', 'file:///tmp/pkg.tar') !== null, "MOGGI_ALLOW_LOCAL_SOURCES={$value} must not permit a `file://` source");
        $widened = fetchBlobFromSource(['source' => ['kind' => 'dir', 'path' => $tree]], null);
        $assert($widened['problem'] !== null, "MOGGI_ALLOW_LOCAL_SOURCES={$value} must not make a `dir` source readable");
    }
    \putenv('MOGGI_ALLOW_LOCAL_SOURCES');

    // --- a `dir` source is refused: it names the publisher's directory --------
    $fromDir = fetchBlobFromSource(['source' => ['kind' => 'dir', 'path' => $tree]], null);
    $assert($fromDir['problem'] !== null, 'a dir source is refused');
    $assert(\str_contains($fromDir['problem'], 'not reproducible'), 'the refusal says why: ' . $fromDir['problem']);
    $assert(! \str_contains($fromDir['problem'], 'MOGGI_'), 'and names no variable that would make it work: ' . $fromDir['problem']);

    // --- an upstream archive source is unpacked and re-packed ----------------
    $tarFile = $work . '/pkg.tar';
    \file_put_contents($tarFile, $archive);
    $servingTar = static fn (string $base, string $path): ?string => $path === 'pkg.tar' ? (string) \file_get_contents($tarFile) : null;

    $fromTar = fetchBlobFromSource([
        'source' => ['kind' => 'archive', 'url' => 'https://upstream.invalid/pkg.tar', 'sha256' => sha256Digest($archive)],
    ], $servingTar);
    $assert($fromTar['problem'] === null, 'a tar archive source resolves: ' . ($fromTar['problem'] ?? ''));
    $assert($fromTar['bytes'] === $archive, 'a tar archive source re-packs to the same archive');
    $assert($fromTar['origin'] === 'source:archive', 'the archive source names itself as the origin');

    $gzFile = $work . '/pkg.tar.gz';
    $gzipped = \gzencode($archive);
    \file_put_contents($gzFile, $gzipped);
    $servingGz = static fn (string $base, string $path): ?string => $path === 'pkg.tar.gz' ? (string) \file_get_contents($gzFile) : null;

    $fromGz = fetchBlobFromSource([
        'source' => ['kind' => 'archive', 'url' => 'https://upstream.invalid/pkg.tar.gz', 'sha256' => sha256Digest($gzipped)],
    ], $servingGz);
    $assert($fromGz['problem'] === null, 'a gzipped archive source resolves: ' . ($fromGz['problem'] ?? ''));
    $assert($fromGz['bytes'] === $archive, 'a gzipped archive source is unpacked and re-packs the same');

    // A release may leave the archive's own sha256 out; the blob digest is then the
    // only check, and it is still a check.
    $unsigned = fetchBlobFromSource([
        'source' => ['kind' => 'archive', 'url' => 'https://upstream.invalid/pkg.tar'],
    ], $servingTar);
    $assert(
        $unsigned['problem'] === null && $unsigned['bytes'] === $archive,
        'an archive source without its own sha256 is still unpacked and re-packed: ' . ($unsigned['problem'] ?? ''),
    );

    // A wrong archive digest is a refusal, not a silent unpack.
    $fromBad = fetchBlobFromSource([
        'source' => ['kind' => 'archive', 'url' => 'https://upstream.invalid/pkg.tar.gz', 'sha256' => 'sha256:' . \str_repeat('0', 64)],
    ], $servingGz);
    $assert($fromBad['problem'] !== null && \str_contains($fromBad['problem'], 'does not match the sha256'), 'a mismatched archive sha256 is refused');

    // --- a git source is an `https` clone at the declared commit -------------
    // The clone needs a remote this suite cannot host, so what is checked here is
    // the policy that decides whether the clone happens at all — the part a signed
    // record would otherwise get to bend.
    $unsourced = fetchBlobFromSource(['source' => ['kind' => 'git', 'url' => '', 'commit' => '']], null);
    $assert(
        $unsourced['problem'] !== null && \str_contains($unsourced['problem'], 'names no url and commit'),
        'a git source with no url or commit is refused',
    );
    $localRepo = fetchBlobFromSource(['source' => ['kind' => 'git', 'url' => '/tmp/repo', 'commit' => 'HEAD']], null);
    $assert(
        $localRepo['problem'] !== null && \str_contains($localRepo['problem'], 'not allowed'),
        'a local git source is refused as a transport rather than cloned: ' . ($localRepo['problem'] ?? ''),
    );

    // --- an unknown source kind is a named refusal ---------------------------
    $unknown = fetchBlobFromSource(['source' => ['kind' => 'rsync', 'url' => 'x']], null);
    $assert($unknown['problem'] !== null && \str_contains($unknown['problem'], 'not one this client can reproduce'), 'an unknown source kind is refused by name');

    // --- a registry-served blob wins over the source -------------------------
    $release = [
        'blob' => $blob,
        'source' => ['kind' => 'archive', 'url' => 'https://upstream.invalid/pkg.tar', 'sha256' => sha256Digest($archive)],
    ];
    $fetch = static function (string $base, string $path) use ($archive): ?string {
        return $path === 'blobs/' . \substr(archiveDigest($archive), 7) ? $archive : null;
    };
    $served = fetchBlob('https://registry.invalid', 'demo', '1.0.0', $release, $fetch, new FetchLog());
    $assert($served['problem'] === null, 'a served blob installs: ' . ($served['problem'] ?? ''));
    $assert($served['origin'] === 'registry' && $served['held'] === false, 'a served blob reports the registry as its origin');

    // --- and with the blob absent, the source is used ------------------------
    // Drop the cache entry the served fetch just wrote: the digest-named file is
    // the cache, and "the registry does not serve it" has to actually miss.
    \unlink((string) $served['path']);
    $unserved = fetchBlob('https://registry.invalid', 'demo', '1.0.0', $release, $servingTar, new FetchLog());
    $assert($unserved['problem'] === null, 'an absent blob falls back to the source: ' . ($unserved['problem'] ?? ''));
    $assert($unserved['origin'] === 'source:archive', 'the fallback reports the source origin: ' . $unserved['origin']);
    $assert($unserved['path'] !== null && \hash_file('sha256', $unserved['path']) === \substr($blob, 7), 'the fallback writes the verified archive to the cache');

    // --- a release whose only source is a directory cannot be installed ------
    // The whole path, not just the helper: with the blob absent and nothing but a
    // `dir` to fall back to, the install refuses and leaves no cache entry behind.
    \unlink((string) $unserved['path']);
    $dirOnly = fetchBlob('https://registry.invalid', 'demo', '1.0.0', [
        'blob' => $blob,
        'source' => ['kind' => 'dir', 'path' => $tree],
    ], $servingTar, new FetchLog());
    $assert($dirOnly['path'] === null, 'a dir-only release installs nothing');
    $assert(
        $dirOnly['problem'] !== null
            && \str_contains($dirOnly['problem'], 'demo 1.0.0')
            && \str_contains($dirOnly['problem'], 'not reproducible'),
        'and the refusal names the release and the reason: ' . ($dirOnly['problem'] ?? ''),
    );

    // --- a source that packs to other bytes is refused against the blob ------
    $mismatch = fetchBlob('https://registry.invalid', 'demo', '1.0.0', [
        'blob' => 'sha256:' . \str_repeat('0', 64),
        'source' => ['kind' => 'archive', 'url' => 'https://upstream.invalid/pkg.tar', 'sha256' => sha256Digest($archive)],
    ], $servingTar, new FetchLog());
    $assert(
        $mismatch['problem'] !== null && \str_contains($mismatch['problem'], 'does not match its blob digest'),
        'a source that packs to other bytes is refused: ' . ($mismatch['problem'] ?? ''),
    );
    $assert(\str_contains((string) $mismatch['problem'], sha256Digest($archive)), 'and the digest it did pack to is reported');

    echo "source fallback tests passed ({$checks} checks)\n";
} finally {
    $remove($work);
}
