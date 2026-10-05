<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * `moggi.lock` — the resolved graph, pinned.
 *
 * JSON, unlike the descriptor: a command writes the lock, and it is mostly
 * digests. `format` is there so the shape can be revised the way the
 * descriptor's and the registry's can.
 *
 * What is pinned per package is the *release record* digest from the catalog,
 * not the blob. That is enough to re-check a lock without resolving again —
 * fetch `packages/<name>.json`, hash it, compare — and it keeps the resolver out
 * of package files, which is what the catalog split is for. Everything else
 * about a release stays in the package file that digest was checked against.
 */

const LOCK_FILE = 'moggi.lock';

/** Where the lock lives: beside the descriptor, unless the caller says otherwise. */
function lockPath(string $descriptorPath, ?string $output = null): string
{
    if ($output !== null) {
        return $output;
    }

    return \dirname($descriptorPath) . '/' . LOCK_FILE;
}

/**
 * The document itself — built once, so what a command prints and what it writes
 * are the same bytes.
 *
 * It carries no timestamp on purpose: a lock is content, and two locks with the
 * same content should be byte-identical, so a `git diff` of `moggi.lock` shows
 * dependency movement and nothing else.
 *
 * @param array{
 *   root: array{name: string, version: string},
 *   registry: array{base: string, npub: ?string, verified: bool},
 *   packages: array<string, array{version: string, digest: ?string}>,
 *   php?: array{version: ?string, extensions: list<string>}
 * } $lock
 * @return array<string, mixed>
 */
function lockDocument(array $lock): array
{
    $packages = [];
    foreach ($lock['packages'] as $name => $entry) {
        $packages[$name] = ['version' => $entry['version'], 'digest' => $entry['digest']];
    }
    \ksort($packages);

    $document = [
        'format' => 1,
        'root' => $lock['root'],
        'registry' => $lock['registry'],
        'packages' => $packages,
    ];
    if (isset($lock['php'])) {
        $document['php'] = $lock['php'];
    }

    return $document;
}

/**
 * @param array<string, mixed> $document
 */
function renderLock(array $document): string
{
    return prettyJson($document) . "\n";
}

/**
 * @param array<string, mixed> $document
 */
function writeLock(string $path, array $document): void
{
    if (!\Moggi\Cache\atomicWrite($path, renderLock($document))) {
        throw new \RuntimeException("cannot write {$path}");
    }
}

/**
 * Read a lock back, for `install` and `build` to re-check against a fresh
 * catalog. A lock that cannot be read is an error naming the file, not a crash:
 * it is a file in the user's repository, and a half-written one is a thing that
 * happens.
 *
 * @return ?array<string, mixed> null when there is no lock at `$path`
 */
function readLock(string $path): ?array
{
    if (!\is_file($path)) {
        return null;
    }

    try {
        $lock = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    } catch (\JsonException $error) {
        throw new \RuntimeException("{$path} is not valid JSON: {$error->getMessage()}");
    }
    if (!\is_array($lock) || ($lock['format'] ?? null) !== 1) {
        throw new \RuntimeException("{$path} is not a lock this compiler reads (expected format 1)");
    }

    return $lock;
}

/**
 * The lock against the registry it was resolved from.
 *
 * A lock records the registry key it was written under. Checking the lock's
 * digests against a *different* registry would be meaningless — the names are the
 * same, so a mirror or an impostor could answer for them — so a lock whose
 * recorded key is not the catalog's is a refusal, not a re-resolution.
 *
 * @param array<string, mixed> $lock
 * @return ?string a problem, or null when the lock belongs to this registry
 */
function lockRegistryProblem(array $lock, ?string $npub): ?string
{
    $recorded = $lock['registry']['npub'] ?? null;
    if (!\is_string($recorded) || $recorded === '' || $npub === null) {
        return null;
    }

    return $recorded === $npub
        ? null
        : "the lock was resolved against {$recorded}, but this registry is {$npub}";
}

/**
 * Two-space indent and a trailing newline, the way the registry's files are
 * written, so a lock diff is readable. `JSON_PRETTY_PRINT` indents by four.
 *
 * @param array<string, mixed> $document
 */
function prettyJson(array $document): string
{
    $json = \json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

    return (string) \preg_replace_callback(
        '/^ +/m',
        static fn (array $match): string => \str_repeat(' ', \strlen($match[0]) / 2),
        $json,
    );
}
