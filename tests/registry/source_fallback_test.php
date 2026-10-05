#!/usr/bin/env php
<?php declare(strict_types=1);

// A registry that does not serve `blobs/<digest>` is not a dead end: a release's
// own `source` is the same bytes from another place, and `blob` is the check that
// makes the origin irrelevant. These checks exercise every source kind the spec
// names — a directory, a git commit, and an upstream archive — plus the rule that
// a registry-served blob still wins.

$root = __DIR__;
while (!is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Registry\FetchLog;

use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\runProcess;
use function Moggi\Registry\archiveDigest;
use function Moggi\Registry\fetchBlob;
use function Moggi\Registry\fetchBlobFromSource;
use function Moggi\Registry\packDirectory;
use function Moggi\Registry\sha256Digest;
use function Moggi\Registry\sourceUrlProblem;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$work = \sys_get_temp_dir() . '/moggi-source-fallback-' . \bin2hex(\random_bytes(6));
$tree = $work . '/pkg';
\mkdir($tree . '/sub', 0777, true);
\file_put_contents($tree . '/a.txt', "hello\n");
\file_put_contents($tree . '/sub/b.txt', "world\n");

$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_dir($path) && !is_link($path)) {
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
// The cases below reproduce `source`s from local paths, which the transport
// policy allows only by explicit opt-in.
\putenv('MOGGI_ALLOW_LOCAL_SOURCES=1');

try {
    $archive = packDirectory($tree);
    $blob = archiveDigest($archive);

    // --- a directory source is packed with the publish rule ------------------
    $fromDir = fetchBlobFromSource(['source' => ['kind' => 'dir', 'path' => $tree]], null);
    $assert($fromDir['problem'] === null, 'a dir source resolves: ' . ($fromDir['problem'] ?? ''));
    $assert($fromDir['bytes'] === $archive, 'a dir source packs to the same canonical archive');
    $assert($fromDir['origin'] === 'source:dir', 'the dir source names itself as the origin');

    // --- an upstream archive source is unpacked and re-packed ----------------
    $tarFile = $work . '/pkg.tar';
    \file_put_contents($tarFile, $archive);
    $fromTar = fetchBlobFromSource([
        'source' => ['kind' => 'archive', 'url' => $tarFile, 'sha256' => sha256Digest($archive)],
    ], null);
    $assert($fromTar['problem'] === null, 'a tar archive source resolves: ' . ($fromTar['problem'] ?? ''));
    $assert($fromTar['bytes'] === $archive, 'a tar archive source re-packs to the same archive');

    $gzFile = $work . '/pkg.tar.gz';
    $gzipped = \gzencode($archive);
    \file_put_contents($gzFile, $gzipped);
    $fromGz = fetchBlobFromSource([
        'source' => ['kind' => 'archive', 'url' => $gzFile, 'sha256' => sha256Digest($gzipped)],
    ], null);
    $assert($fromGz['problem'] === null, 'a gzipped archive source resolves: ' . ($fromGz['problem'] ?? ''));
    $assert($fromGz['bytes'] === $archive, 'a gzipped archive source is unpacked and re-packs the same');

    // A wrong archive digest is a refusal, not a silent unpack.
    $fromBad = fetchBlobFromSource([
        'source' => ['kind' => 'archive', 'url' => $gzFile, 'sha256' => 'sha256:' . \str_repeat('0', 64)],
    ], null);
    $assert($fromBad['problem'] !== null && \str_contains($fromBad['problem'], 'does not match the sha256'), 'a mismatched archive sha256 is refused');

    // --- a git source is a clone at the declared commit ----------------------
    if (findExecutable('git') !== null) {
        $repo = $work . '/repo';
        \mkdir($repo, 0777, true);
        \copy($tree . '/a.txt', $repo . '/a.txt');
        \mkdir($repo . '/sub', 0777, true);
        \copy($tree . '/sub/b.txt', $repo . '/sub/b.txt');
        $identity = ['-c', 'user.email=t@example.com', '-c', 'user.name=Test', '-c', 'commit.gpgsign=false'];
        runProcess(['git', 'init', '--quiet', $repo]);
        runProcess(['git', ...$identity, '-C', $repo, 'add', '-A']);
        runProcess(['git', ...$identity, '-C', $repo, 'commit', '--quiet', '-m', 'sources']);
        $head = \trim(runProcess(['git', '-C', $repo, 'rev-parse', 'HEAD'])['stdout']);

        $fromGit = fetchBlobFromSource(['source' => ['kind' => 'git', 'url' => $repo, 'commit' => $head]], null);
        $assert($fromGit['problem'] === null, 'a git source resolves: ' . ($fromGit['problem'] ?? ''));
        $assert($fromGit['bytes'] === $archive, 'a git source packs the same tree as the directory');
        $assert(\str_starts_with($fromGit['origin'], 'source:git'), 'the git source names itself as the origin');
    }

    // --- a registry-served blob wins over the source -------------------------
    $fetch = static function (string $base, string $path) use ($archive): ?string {
        return $path === 'blobs/' . \substr(archiveDigest($archive), 7) ? $archive : null;
    };
    $release = ['blob' => $blob, 'source' => ['kind' => 'dir', 'path' => $tree]];
    $served = fetchBlob('https://registry.invalid', 'demo', '1.0.0', $release, $fetch, new FetchLog());
    $assert($served['problem'] === null, 'a served blob installs: ' . ($served['problem'] ?? ''));
    $assert($served['origin'] === 'registry' && $served['held'] === false, 'a served blob reports the registry as its origin');

    // --- and with the blob absent, the source is used ------------------------
    // Drop the cache entry the served fetch just wrote: the digest-named file is
    // the cache, and "the registry does not serve it" has to actually miss.
    \unlink((string) $served['path']);
    $absent = static fn (string $base, string $path): ?string => null;
    $unserved = fetchBlob('https://registry.invalid', 'demo', '1.0.0', $release, $absent, new FetchLog());
    $assert($unserved['problem'] === null, 'an absent blob falls back to the source: ' . ($unserved['problem'] ?? ''));
    $assert($unserved['origin'] === 'source:dir', 'the fallback reports the source origin');
    $assert($unserved['path'] !== null && \hash_file('sha256', $unserved['path']) === \substr($blob, 7), 'the fallback writes the verified archive to the cache');

    // --- a local source that packs to the wrong bytes hides its own digest ---
    $wrongDigest = 'sha256:' . \str_repeat('0', 64);
    $localMismatch = fetchBlob('https://registry.invalid', 'demo', '1.0.0', [
        'blob' => $wrongDigest,
        'source' => ['kind' => 'dir', 'path' => $tree],
    ], $absent, new FetchLog());
    $assert($localMismatch['problem'] !== null && \str_contains($localMismatch['problem'], 'withheld'), 'a local source mismatch is refused as such');
    $assert(!\str_contains((string) $localMismatch['problem'], sha256Digest($archive)), 'the produced digest of a local tree is not echoed');

    // --- an unknown source kind is a named refusal ---------------------------
    $unknown = fetchBlobFromSource(['source' => ['kind' => 'rsync', 'url' => 'x']], null);
    $assert($unknown['problem'] !== null && \str_contains($unknown['problem'], 'not one this client can reproduce'), 'an unknown source kind is refused by name');

    // --- the transport policy ------------------------------------------------
    // Without the opt-in, a local path is not a fetchable source at all.
    \putenv('MOGGI_ALLOW_LOCAL_SOURCES');
    $assert(sourceUrlProblem('git', 'https://example.invalid/r') === null, 'https is a permitted source transport');
    foreach (['ext::sh -c id', 'ssh://h/r', 'http://h/r', 'file:///etc/passwd', '/tmp/repo', '../repo'] as $refused) {
        $assert(sourceUrlProblem('git', $refused) !== null, "`{$refused}` must not be a permitted git source");
    }
    $assert(sourceUrlProblem('archive', 'ext::sh -c id') !== null, 'a remote helper is refused for an archive source too');
    $assert(\str_contains((string) sourceUrlProblem('git', '/tmp/repo'), 'MOGGI_ALLOW_LOCAL_SOURCES'), 'a local refusal names the opt-in');

    $dirRefused = fetchBlobFromSource(['source' => ['kind' => 'dir', 'path' => $tree]], null);
    $assert($dirRefused['problem'] !== null, 'a `dir` source is refused without the opt-in');
    $assert(\str_contains((string) $dirRefused['problem'], 'MOGGI_ALLOW_LOCAL_SOURCES'), 'the `dir` refusal names the opt-in');

    // With the opt-in, local paths are reproduced (as the cases above did).
    \putenv('MOGGI_ALLOW_LOCAL_SOURCES=1');
    $assert(sourceUrlProblem('git', '/tmp/repo') === null, 'a local path is permitted with the opt-in');
    $assert(sourceUrlProblem('archive', 'file:///tmp/repo') === null, 'a file:// URL is permitted with the opt-in');
    $assert(sourceUrlProblem('archive', 'C:\\repo') === null, 'a Windows drive path is local');

    echo "source fallback tests passed ({$checks} checks)\n";
} finally {
    $remove($work);
}
