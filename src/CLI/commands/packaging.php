<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Cache;
use Moggi\Registry\Catalog;
use Moggi\Registry\FetchLog;

use function Moggi\Registry\loadCatalog;
use function Moggi\Registry\lockDocument;
use function Moggi\Registry\lockPath;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\registryIdentityProblem;
use function Moggi\Registry\rememberRootVersion;
use function Moggi\Registry\resolveDependencies;
use function Moggi\Registry\rootIntegrityProblem;
use function Moggi\Registry\writeLock;

/**
 * What `update`, `install`, `build` and `verify` share: one option parser, one
 * way to load a catalog, and one way to resolve-and-write a lock. They differ in
 * what they do *with* a resolution, not in how they read one — and the lock check
 * `install` and `build` both run has to be the same check in both places or it is
 * not a check.
 */

/**
 * The registry the compiler defaults to.
 *
 * Canonical only by being the default and by sitting on our domain: any host
 * speaking `registry-spec.md` works, and `MOGGI_REGISTRY` selects another.
 */
const DEFAULT_REGISTRY = 'https://registry.moggi-lang.org';

/**
 * Parse the options every packaging command accepts, plus the ones only some do.
 *
 * Flags are accepted as `--flag value` and `--flag=value`; the booleans have no
 * value. Anything unrecognised is an error rather than ignored, because a
 * mistyped `--registy` silently resolving against the wrong registry is exactly
 * the mistake that should not be quiet.
 *
 * @param list<string> $argv
 * @param list<string> $booleans boolean flags this command accepts, without `--`
 * @param list<string> $valued valued flags this command accepts, without `--`
 * @return array<string, mixed>
 */
function packagingOptions(array $argv, array $booleans, array $valued): array
{
    $options = [
        'path' => '.',
        'registry' => (\getenv('MOGGI_REGISTRY') ?: DEFAULT_REGISTRY),
        'output' => null,
        'noCache' => false,
    ];
    foreach ($booleans as $flag) {
        $options[$flag] = false;
    }
    foreach ($valued as $flag) {
        $options[$flag] = null;
    }

    $positional = [];
    for ($i = 2, $n = \count($argv); $i < $n; $i++) {
        $argument = $argv[$i];
        $value = null;
        if (\str_contains($argument, '=')) {
            [$argument, $value] = \explode('=', $argument, 2);
        }
        $name = \ltrim($argument, '-');
        $short = match ($name) {
            'r' => 'registry',
            'o' => 'output',
            'no-cache' => 'noCache',
            'no-docs' => 'noDocs',
            'dry-run' => 'dryRun',
            default => $name,
        };

        if ($short === 'registry' || $short === 'output' || \in_array($short, $valued, true)) {
            $options[$short] = $value ?? ($argv[++$i] ?? throw new \InvalidArgumentException("{$argument} needs a value"));
            continue;
        }
        if (\in_array($short, $booleans, true)) {
            $options[$short] = true;
            continue;
        }
        if (\str_starts_with($argument, '-')) {
            throw new \InvalidArgumentException("unknown option `{$argument}`");
        }
        $positional[] = $argument;
    }

    if (\count($positional) > 1) {
        throw new \InvalidArgumentException('expected at most one path, got: ' . \implode(' ', $positional));
    }
    if ($positional !== []) {
        $options['path'] = $positional[0];
    }

    if ($options['noCache']) {
        Cache\setCacheEnabled(false);
    }

    return $options;
}

/**
 * Load a catalog for a command, refusing a root signature that does not verify
 * (§4).
 *
 * A signature that cannot be checked is not a signature that passed: a missing
 * `schnorr` binary refuses the catalog exactly as a bad signature does. It used
 * to warn and carry on, which made "the verifier is not installed" and "the
 * registry is authentic" indistinguishable to every command that resolves a
 * dependency. A shard that does not match the root is raised when the name that
 * lives in it is asked for, since shards are read on demand.
 */
function loadVerifiedCatalog(string $registry, bool $useCache, FetchLog $log): Catalog
{
    $catalog = loadCatalog($registry, null, $log, $useCache);
    $signature = $catalog->signature();

    if (!$signature['ok']) {
        throw new \RuntimeException("refusing to use {$registry}: {$signature['note']}");
    }

    $problem = registryIdentityProblem(
        $registry,
        (string) $catalog->npub(),
        (\getenv('MOGGI_REGISTRY_NPUB') ?: null) ?: null,
    );
    if ($problem !== '') {
        throw new \RuntimeException($problem);
    }

    $root = $catalog->root();
    $integrity = rootIntegrityProblem($registry, $root, \time());
    if ($integrity !== '') {
        throw new \RuntimeException("refusing to use {$registry}: {$integrity}");
    }
    rememberRootVersion($registry, (int) $root['version']);

    return $catalog;
}

/**
 * Resolve a descriptor's dependencies and write the lock.
 *
 * The one place a lock is written, so `update` and `install` cannot
 * drift in what they produce.
 *
 * @param array<string, mixed> $descriptor
 * @return array{document: array<string, mixed>, chosen: array<string, string>, lockFile: string}
 */
function writeResolution(array $descriptor, string $descriptorPath, string $registry, Catalog $catalog, ?string $output, bool $dryRun = false): array
{
    $resolution = resolveDependencies($descriptor['dependencies'], $catalog, $descriptor['name']);
    if (!$resolution['ok']) {
        throw new \RuntimeException((string) $resolution['error']);
    }

    $packages = [];
    foreach ($resolution['chosen'] as $name => $version) {
        $packages[$name] = [
            'version' => $version,
            'digest' => $catalog->entry($name)['digest'] ?? null,
        ];
    }

    $document = lockDocument([
        'root' => ['name' => $descriptor['name'], 'version' => $descriptor['version']],
        'registry' => [
            'base' => $registry,
            'npub' => $catalog->npub(),
            'verified' => $catalog->signature()['ok'],
        ],
        'packages' => $packages,
        'php' => $descriptor['php'],
    ]);

    $lockFile = lockPath($descriptorPath, $output);
    if (!$dryRun) {
        writeLock($lockFile, $document);
    }

    return ['document' => $document, 'chosen' => $resolution['chosen'], 'lockFile' => $lockFile];
}

/**
 * Refuse a backend a package does not declare, naming the package.
 *
 * `[package] backends` is a declaration, not a proof — the compiler still fails on
 * whatever the sources actually reach for — but it is enough to turn "this package
 * has no PHP behind it" into one sentence, at the point the backend is chosen,
 * instead of a missing symbol deep in code generation. An empty list means every
 * backend, which is what an absent key reads as.
 *
 * @param list<string> $backends
 */
function assertBackendSupported(string $name, array $backends, string $backend, string $what = ''): void
{
    if ($backends === [] || \in_array($backend, $backends, true)) {
        return;
    }

    throw new \RuntimeException(
        ($what === '' ? '' : $what . ' ') . "`{$name}` cannot be built for `{$backend}`"
        . ' — it declares backends: ' . \implode(', ', $backends),
    );
}

/**
 * Print a machine-readable envelope: the lock document plus what the run cost. An
 * envelope rather than the bare document, because the document is what gets
 * written to disk and a report is not part of it.
 *
 * @param array<string, mixed> $document
 * @param array<string, mixed> $extra
 */
function printJsonEnvelope(array $document, array $extra): void
{
    echo prettyJson(['lock' => $document] + $extra) . "\n";
}
