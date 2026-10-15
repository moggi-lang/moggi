<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Registry\archiveDigest;
use function Moggi\Registry\archiveTreeDigest;
use function Moggi\Registry\buildReleaseRecord;
use function Moggi\Registry\checkSignedRelease;
use function Moggi\Registry\directoryEntries;
use function Moggi\Registry\entriesDigest;
use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\httpSend;
use function Moggi\Registry\moggiIgnorePatterns;
use function Moggi\Registry\packDirectory;
use function Moggi\Registry\packEntries;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\readNsecKey;
use function Moggi\Registry\readReleaseRecord;
use function Moggi\Registry\registryError;
use function Moggi\Registry\releaseSource;
use function Moggi\Registry\requestHost;
use function Moggi\Registry\selectAuthor;
use function Moggi\Registry\signRelease;
use function Moggi\Registry\thirdPartyForDescriptor;
use function Moggi\Registry\writeAuthHeaders;

/**
 * `moggi publish` — pack, sign and upload one release.
 *
 * Four steps, in the order the registry needs them: the blob, then the docs if
 * any, then the release record, then the root the registry re-signs. The first
 * three are content-addressed or signed, so a retry is safe; the record is what
 * makes the version exist.
 *
 * Nothing here decides who may publish — the registry checks the signature
 * against the signed root and each package's allowed list. The client's job is
 * to sign the release and to sign each write with the author's npub, which is
 * what proves the request came from the identity it claims.
 */
function publishUsage(): string
{
    $default = writeRegistryDefault();

    return <<<HELP
    usage:
      moggi publish [<dir|<name>.moggi>] [options]

    Pack the directory, sign the release with an author's npub, and upload it:
    the archive to /blobs, the documentation to /docs (when the package has any),
    then the release record. There is no login — every request is signed with the
    key the release is signed with.

    options:
      --registry URL|DIR   registry to publish to (default: MOGGI_REGISTRY_WRITE,
                           else MOGGI_REGISTRY, else {$default})
      --as NPUB            which [author] signs (required when there is more than one)
      --nsec-file FILE     the signing key, mode 0600 (default: MOGGI_NSEC_FILE,
                           else a no-echo prompt on a terminal)
      --url URL            record a `git` source instead of the default `dir`
      --commit SHA         the commit that URL names (with --url)
      --no-docs            do not render or upload [docs] target-dir
      --yes                do not prompt for confirmation before uploading
      --signed FILE        publish a pre-signed record (from `pack --record`
                           or `moggi sign`) instead of signing here. The tree is
                           still packed and must match the record byte for byte;
                           the key is still needed to authenticate the uploads.
                           Cannot combine with --url, --commit or --as.
      --dry-run            pack and sign (or check --signed), print what would
                           be uploaded, and send nothing
      --json               machine-readable output
      -h, --help           show this help

    Signing can happen on another machine than the upload: `pack --record`
    prints the signed record, `moggi sign` signs a record file, and `--signed`
    uploads either one's output. `--dry-run` shows the same payload and the
    requests it would travel in, without sending them.

    A release's `source` says where the code came from. `--url` plus `--commit`
    names a repository and a commit — the one form a client can reproduce from when
    the registry does not serve the blob, which is the point of recording it.
    Without them the release records the directory it was packed from, which is
    provenance rather than a fetch recipe: a path on this machine is nothing
    another machine can read, so no client reproduces from it (`--url` plus
    `--commit` is what makes a release reproducible by anyone but you). The blob is
    what a client normally downloads and checks either way.
    HELP;
}

/** @param list<string> $argv */
function runPublishCommand(array $argv): int
{
    $spec = new CommandSpec('publish', publishUsage(), [
        ['name' => 'json'],
        ['name' => 'noDocs'],
        ['name' => 'yes'],
        ['name' => 'dryRun'],
        ['name' => 'signed', 'value' => true],
        ['name' => 'nsec-file', 'value' => true],
        ['name' => 'as', 'value' => true],
        ['name' => 'url', 'value' => true],
        ['name' => 'commit', 'value' => true],
    ], write: true);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    try {
        return publishRelease($options);
    } catch (\RuntimeException $error) {
        \fwrite(\STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * @param array<string, mixed> $options
 */
function publishRelease(array $options): int
{
    $signedPath = $options['signed'];
    if ($signedPath !== null && ($options['url'] !== null || $options['commit'] !== null)) {
        throw new \RuntimeException('--url and --commit describe the source being recorded — a --signed record already carries its source');
    }
    if ($signedPath !== null && $options['as'] !== null) {
        throw new \RuntimeException('--as chooses the signer — a --signed record names its own');
    }

    $descriptorPath = findDescriptor($options['path']);
    $descriptor = readDescriptor($descriptorPath);
    $directory = \dirname($descriptorPath);

    $name = $descriptor['name'];
    $version = $descriptor['version'];
    $descriptorBytes = (string) \file_get_contents($descriptorPath);
    $base = \rtrim($options['registry'], '/');

    $record = $signedPath === null ? null : readReleaseRecord($signedPath);

    if ($record === null) {
        $author = selectAuthor($descriptor['authors'], $options['as']);
    }
    $nsec = readNsecKey($options['nsec-file']);

    $output = $options['output'] ?? "{$directory}/{$name}-{$version}.tar";
    $exclude = [];
    if (\str_starts_with($output, \rtrim($directory, '/') . '/')) {
        $exclude[] = \ltrim(\substr($output, \strlen(\rtrim($directory, '/')) + 1), '/');
    }
    $docsTarget = $descriptor['docs']['targetDir'];
    if ($docsTarget !== null) {
        $exclude[] = \trim($docsTarget, '/');
    }

    $ignore = moggiIgnorePatterns($directory);
    $entries = directoryEntries($directory, $exclude, $ignore);
    $content = entriesDigest($entries);
    $archive = packEntries($entries);
    $blob = archiveDigest($archive);

    if (! $options['dryRun'] && ! $options['yes'] && \function_exists('posix_isatty') && \posix_isatty(\STDIN)) {
        \printf("%s %s — %d entr%s, %d bytes\n", $name, $version, \count($entries), \count($entries) === 1 ? 'y' : 'ies', \strlen($archive));
        foreach ($entries as $entry) {
            \printf("  %s\n", $entry['path']);
        }
        \fwrite(\STDERR, 'publish this? [y/N] ');
        $answer = \trim((string) \fgets(\STDIN));
        if ($answer !== 'y' && $answer !== 'Y' && $answer !== 'yes') {
            throw new \RuntimeException('cancelled');
        }
    }

    $docs = $docsTarget === null || $options['noDocs'] ? null : packDocs($directory, $descriptor);

    if ($record !== null) {
        $author = checkSignedRelease(
            $record,
            $name,
            $version,
            $blob,
            $descriptorBytes,
            $docs === null ? null : $docs['digest'],
            $descriptor['authors'],
        );
        $release = $record;
    } else {
        $release = signRelease(
            $name,
            $version,
            buildReleaseRecord(
                $name,
                $version,
                releaseSource($options['url'], $options['commit'], $directory, $content),
                $blob,
                thirdPartyForDescriptor($descriptor),
                $descriptorBytes,
                $docs === null ? null : $docs['digest'],
            ),
            $author,
            $nsec,
        );
    }

    if ($options['dryRun']) {
        return printPublishPlan(
            $options['json'],
            $base,
            $name,
            $version,
            $author,
            $blob,
            $docs === null ? null : $docs['digest'],
            $release,
        );
    }

    $existing = httpSend("{$base}/packages/{$name}.json", 'GET', []);
    if ($existing['status'] === 200) {
        $published = \json_decode($existing['body'], true);
        if (\is_array($published) && isset($published['versions'][$version])) {
            throw new \RuntimeException(
                "{$name} {$version} is already published — versions are immutable; bump the version",
            );
        }
    } elseif ($existing['status'] !== 404) {
        throw new \RuntimeException(
            'could not read the catalog at ' . $base . ' (' . $existing['status'] . '): ' . registryError($existing['body']),
        );
    }

    $blobPath = '/blobs/' . \substr($blob, 7);
    putOrFail($base . $blobPath, 'PUT', $archive, $nsec, $author, 'the blob');

    if ($docs !== null) {
        $docsArchive = packDirectory($docs['path'], ['.mogdoc-source-fingerprint']);

        $actualDocs = archiveTreeDigest($docsArchive);
        if ($actualDocs !== $docs['digest']) {
            throw new \RuntimeException(
                "the documentation archive hashes to {$actualDocs}, but the release would name {$docs['digest']} — "
                . 'the tree changed between rendering and upload; rerun so both name the same bytes',
            );
        }

        $docsPath = "/docs/{$name}/{$version}";
        putOrFail(
            $base . $docsPath,
            'PUT',
            $docsArchive,
            $nsec,
            $author,
            'the documentation',
            ['x-moggi-docs-digest: ' . $docs['digest']],
        );
    }

    $body = \json_encode($release, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    $publishUrl = $base . '/publish';
    $response = httpSend(
        $publishUrl,
        'POST',
        [...writeAuthHeaders(requestHost($publishUrl), 'POST', '/publish', $body, $nsec, $author), 'Content-Type: application/json'],
        $body,
        false,
    );
    if ($response['status'] !== 200) {
        throw new \RuntimeException('publish rejected (' . $response['status'] . '): ' . registryError($response['body']));
    }

    if ($options['json']) {
        echo prettyJson([
            'name' => $name,
            'version' => $version,
            'author' => $author,
            'registry' => $base,
            'blob' => $blob,
            'content' => $content,
            'docs' => $docs === null ? null : $docs['digest'],
            'entries' => \count($entries),
            'bytes' => \strlen($archive),
        ]) . "\n";

        return 0;
    }

    \printf("%s %s\n", $name, $version);
    \printf("author     %s\n", $author);
    \printf("registry   %s\n", $base);
    \printf("blob       %s (%d bytes, %d entr%s)\n", $blob, \strlen($archive), \count($entries), \count($entries) === 1 ? 'y' : 'ies');
    if ($docs !== null) {
        \printf("docs       %s (%d module(s))\n", $docs['digest'], $docs['modules']);
    }
    echo "published\n";

    return 0;
}

/**
 * What `--dry-run` prints instead of uploading: the requests the release would
 * travel in, and the signed release itself.
 *
 * @param array<string, mixed> $release
 */
function printPublishPlan(
    bool $json,
    string $base,
    string $name,
    string $version,
    string $author,
    string $blob,
    ?string $docsDigest,
    array $release,
): int {
    $writes = [
        ['method' => 'GET', 'path' => "/packages/{$name}.json"],
        ['method' => 'PUT', 'path' => '/blobs/' . \substr($blob, 7)],
    ];
    if ($docsDigest !== null) {
        $writes[] = ['method' => 'PUT', 'path' => "/docs/{$name}/{$version}"];
    }
    $writes[] = ['method' => 'POST', 'path' => '/publish'];

    if ($json) {
        echo prettyJson(['registry' => $base, 'author' => $author, 'writes' => $writes, 'release' => $release]) . "\n";

        return 0;
    }

    \printf("%s %s\n", $name, $version);
    \printf("author     %s\n", $author);
    \printf("registry   %s\n", $base);
    \printf("blob       %s\n", $blob);
    if ($docsDigest !== null) {
        \printf("docs       %s\n", $docsDigest);
    }
    echo "would send:\n";
    foreach ($writes as $write) {
        \printf("  %s %s\n", $write['method'], $write['path']);
    }
    echo "dry run — nothing uploaded\n";

    return 0;
}

/**
 * Upload one content-addressed body, or fail with the registry's own message.
 *
 * @param list<string> $extraHeaders
 */
function putOrFail(string $url, string $method, string $body, string $nsec, string $author, string $what, array $extraHeaders = []): void
{
    $path = \parse_url($url, \PHP_URL_PATH) ?: '/';
    $response = httpSend(
        $url,
        $method,
        [...writeAuthHeaders(requestHost($url), $method, (string) $path, $body, $nsec, $author), ...$extraHeaders],
        $body,
        false,
    );
    if ($response['status'] !== 200) {
        throw new \RuntimeException("uploading {$what} failed ({$response['status']}): " . registryError($response['body']));
    }
}
