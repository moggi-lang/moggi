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
 * The lock is bound to the descriptor it was resolved from: `descriptor` is
 * the sha256 of the descriptor bytes as resolved, and `dependencies` is the
 * `[dependencies]` table as resolved, so a descriptor edited without
 * re-resolving is a refusal naming the fix rather than a build from a stale
 * lock. `transitiveTools` records the admitted transitive host coordinates per
 * backend (policy (ii): reviewable opt-in), and `host` the resolved host-tool
 * state per backend, both written back by `build`. Every new member is omitted
 * when empty or unknown.
 *
 * Pre-1.0 the format version stays 1: the project evolves freely and keeps no
 * backwards compatibility, so there is no compat read for older shapes.
 *
 * @param array{
 *   root: array{name: string, version: string},
 *   registry: array{base: string, npub: ?string, verified: bool},
 *   packages: array<string, array{version: string, digest: ?string}>,
 *   descriptor?: string,
 *   dependencies?: array<string, string>,
 *   transitiveTools?: array<string, list<string>>,
 *   host?: array<string, array<string, mixed>>,
 *   php?: array{version: ?string, extensions: list<string>}
 * } $lock
 * @return array<string, mixed>
 */
function lockDocument(array $lock): array
{
    $packages = [];
    foreach ($lock['packages'] as $name => $entry) {
        $packages[$name] = ['version' => $entry['version'], 'digest' => $entry['digest']];
        if (($entry['bundled'] ?? false) === true) {
            $packages[$name]['bundled'] = true;
        }
    }
    \ksort($packages);

    $document = [
        'format' => 1,
        'root' => $lock['root'],
        'registry' => $lock['registry'],
        'packages' => $packages,
    ];
    if (isset($lock['descriptor'])) {
        $document['descriptor'] = $lock['descriptor'];
    }
    if (isset($lock['dependencies'])) {
        $dependencies = [];
        foreach ((array) $lock['dependencies'] as $name => $constraint) {
            $dependencies[(string) $name] = (string) $constraint;
        }
        \ksort($dependencies, \SORT_STRING);
        $document['dependencies'] = $dependencies;
    }
    if (!empty($lock['transitiveTools'])) {
        $admitted = [];
        foreach ((array) $lock['transitiveTools'] as $backend => $names) {
            $names = \array_values(\array_unique(\array_map('strval', (array) $names)));
            \sort($names, \SORT_STRING);
            if ($names !== []) {
                $admitted[(string) $backend] = $names;
            }
        }
        \ksort($admitted, \SORT_STRING);
        if ($admitted !== []) {
            $document['transitiveTools'] = $admitted;
        }
    }
    if (!empty($lock['host'])) {
        $host = (array) $lock['host'];
        \ksort($host, \SORT_STRING);
        $document['host'] = $host;
    }
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
 * Refuse a lock that resolves a different descriptor than the one on disk.
 *
 * A lock without a descriptor digest has nothing to compare and passes. A
 * digest that differs means the descriptor moved without re-resolving, and
 * the fix is the command that resolves.
 */
function assertLockMatchesDescriptor(?array $lock, string $descriptorPath, string $descriptorBytes): void
{
    $digest = \is_array($lock) ? ($lock['descriptor'] ?? null) : null;
    if (!\is_string($digest) || $digest === '') {
        return;
    }
    if ($digest !== sha256Digest($descriptorBytes)) {
        throw new \RuntimeException(
            'moggi.lock does not match ' . \basename($descriptorPath) . ' (run `moggi install`)',
        );
    }
}

/**
 * Write the lock's build-maintained members back when they moved.
 *
 * `build` owns `host` (the resolved host-tool state per backend) and
 * `transitiveTools` (the admitted transitive coordinates per backend); the
 * resolver owns everything else. Only the current backend's slice is touched,
 * and the file is rewritten only when a slice actually differs, so a build
 * that changes nothing leaves the lock byte-identical.
 *
 * @param array<string, mixed> $lock the lock as read
 * @param array<string, mixed>|null $hostEntry the current backend's state, or null to clear it
 * @param list<string> $transitivePackages the current backend's admitted packages
 * @return bool whether the file was rewritten
 */
function refreshLockExtras(string $lockFile, array $lock, string $backend, ?array $hostEntry, array $transitivePackages): bool
{
    $next = $lock;
    $host = isset($next['host']) && \is_array($next['host']) ? $next['host'] : [];
    if ($hostEntry === null) {
        unset($host[$backend]);
    } else {
        $host[$backend] = $hostEntry;
    }
    if ($host === []) {
        unset($next['host']);
    } else {
        \ksort($host, \SORT_STRING);
        $next['host'] = $host;
    }

    $admitted = \array_values(\array_unique(\array_map('strval', $transitivePackages)));
    \sort($admitted, \SORT_STRING);
    $transitive = isset($next['transitiveTools']) && \is_array($next['transitiveTools']) ? $next['transitiveTools'] : [];
    if ($admitted === []) {
        unset($transitive[$backend]);
    } else {
        $transitive[$backend] = $admitted;
    }
    if ($transitive === []) {
        unset($next['transitiveTools']);
    } else {
        \ksort($transitive, \SORT_STRING);
        $next['transitiveTools'] = $transitive;
    }

    if (($next['host'] ?? null) == ($lock['host'] ?? null)
        && ($next['transitiveTools'] ?? null) == ($lock['transitiveTools'] ?? null)) {
        return false;
    }
    writeLock($lockFile, lockDocument($next + [
        'root' => [],
        'registry' => [],
        'packages' => [],
    ]));

    return true;
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
