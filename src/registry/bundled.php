<?php declare(strict_types=1);

namespace Moggi\Registry;

use function Moggi\Cache\ensureDir;
use function Moggi\Install\installationRoot;
use function Moggi\Paths\globPaths;

/**
 * Packages that travel with the compiler, beyond the standard library.
 *
 * `base` is *provided* (`provided.php`): it names the toolchain, is never
 * locked and never fetched. Every other library under the installation's
 * `libraries/` tree — `json` today, `containers` tomorrow — is *bundled*: a
 * real dependency that is locked like any other, but resolves and installs
 * from the local copy instead of the network whenever the bundled version
 * satisfies what was asked for.
 *
 * The rule, in one sentence: a demand the bundled version satisfies resolves
 * to exactly the bundled version, with no catalog read for that name; anything
 * else goes to the registry as before. So `moggi add json` does zero web
 * requests, `moggi update` keeps a satisfied line on its bundled version, and
 * an explicit demand for newer (`moggi add json=0.99`) takes the registry path
 * — the escape hatch is the constraint itself, and it needs no new syntax.
 *
 * Trust: a bundled install is authenticated by the *distribution*, not by a
 * release signature — the same trust `base` already enjoys, and the same trust
 * the compiler binary itself enjoys. The lock still records version and digest,
 * so the install is reproducible and any drift in the local tree is a refusal,
 * not a silent substitution. This is deliberately not the `dir` source the
 * fetcher refuses (`source_fallback.php`): a `dir` source names the
 * *publisher's* directory inside a signed record, while a bundled path comes
 * from the installation layout and never from a release record.
 */

/**
 * The `libraries/` tree this compiler ships: `<root>/libraries`, where the
 * root is found the way the bundled stdlib is — `MOGGI_ROOT`, the installed
 * distribution, then the checkout — or null when none holds one.
 */
function bundledLibrariesDir(): ?string
{
    static $cached = null;
    static $done = false;
    if ($done) {
        return $cached;
    }
    $done = true;

    $candidates = [];
    $envRoot = \getenv('MOGGI_ROOT');
    if (\is_string($envRoot) && $envRoot !== '') {
        $candidates[] = \rtrim($envRoot, '/\\');
    }
    $installRoot = installationRoot();
    if ($installRoot !== null) {
        $candidates[] = $installRoot;
    }
    $candidates[] = \dirname(__DIR__, 2);

    foreach ($candidates as $root) {
        $libraries = \rtrim($root, '/\\') . \DIRECTORY_SEPARATOR . 'libraries';
        if (\is_dir($libraries)) {
            $cached = $libraries;

            return $cached;
        }
    }

    return $cached;
}

/**
 * The bundled packages: name => ['version' => ..., 'dir' => ..., 'manifest' => ...].
 *
 * Read off the shipped manifests (`libraries/<package>/<package>.moggi`), so a
 * future package is picked up with no code change. `base` is excluded: the
 * provided mechanism owns it. A package whose manifest cannot be read is left
 * out rather than failing every resolution — distribution tests guard the
 * shipped manifests, and the version and digest are re-checked at the point of
 * use, so a skipped entry can only fall back to the registry, never install
 * something unexpected.
 *
 * @return array<string, array{version: string, dir: string, manifest: string}>
 */
function bundledPackages(): array
{
    static $cache = [];
    $libraries = bundledLibrariesDir();
    if ($libraries === null) {
        return [];
    }
    if (isset($cache[$libraries])) {
        return $cache[$libraries];
    }

    $packages = [];
    // Forward slashes throughout: `glob()` reads `\` as an escape (see
    // `globPaths`), and PHP opens `/`-spelled paths on every host — so a
    // Windows scan compares equal to the `/`-joined dirs callers build.
    foreach (globPaths($libraries . '/*/*.moggi') as $manifest) {
        try {
            $descriptor = readDescriptor((string) $manifest);
        } catch (\RuntimeException) {
            continue;
        }
        $name = (string) ($descriptor['name'] ?? '');
        $version = (string) ($descriptor['version'] ?? '');
        if ($name === '' || $name === 'base' || $version === '' || packageNameProblem($name) !== null) {
            continue;
        }
        $packages[$name] = [
            'version' => $version,
            'dir' => \str_replace('\\', '/', \dirname((string) $manifest)),
            'manifest' => \str_replace('\\', '/', (string) $manifest),
        ];
    }
    \ksort($packages, \SORT_STRING);
    $cache[$libraries] = $packages;

    return $packages;
}

/**
 * The resolver's view of the bundled packages: a bundled name answers with
 * exactly the version this compiler carries, plus the dependencies its own
 * manifest declares, so the closure underneath it resolves the same offline.
 * Every other name falls through to the wrapped entry (the catalog, or null
 * when there is none).
 *
 * @param callable(string): ?array<string, mixed> $entry
 * @param array<string, array{version: string, dir: string, manifest: string}> $bundled
 * @return callable(string): ?array<string, mixed>
 */
function bundledEntry(callable $entry, array $bundled): callable
{
    if ($bundled === []) {
        return $entry;
    }

    return static function (string $name) use ($entry, $bundled): ?array {
        if (!isset($bundled[$name])) {
            return $entry($name);
        }
        $version = $bundled[$name]['version'];

        return ['versions' => [$version => ['deps' => bundledManifestDeps($bundled[$name]['manifest'] ?? '')]]];
    };
}

/**
 * The dependencies a bundled manifest declares, name => constraint.
 *
 * Unreadable manifests answer no dependencies rather than failing: the scan
 * already skipped them, and a resolution must never brick on a tree it does
 * not need.
 */
function bundledManifestDeps(string $manifest): array
{
    if ($manifest === '') {
        return [];
    }
    try {
        $descriptor = readDescriptor($manifest);
    } catch (\RuntimeException) {
        return [];
    }
    $deps = [];
    foreach ((array) ($descriptor['dependencies'] ?? []) as $name => $constraint) {
        $deps[(string) $name] = (string) $constraint;
    }

    return $deps;
}

/**
 * Pin the root demands the bundled versions satisfy: `json = 0.4` becomes
 * `json = =0.4.0` when the compiler carries exactly that, so the resolver
 * below can only choose the bundled copy. Demands the bundled version does
 * not satisfy — and names that are not bundled at all — pass through
 * untouched, which is what sends an explicit demand for newer to the
 * registry.
 *
 * An absent constraint satisfies everything (`satisfies()` answers true to no
 * clauses), so an unconstrained demand always pins. That is the whole point:
 * `moggi add json` means the copy in hand.
 *
 * @param array<string, string> $demands name => constraint, from the descriptor
 * @param array<string, array{version: string, dir: string, manifest: string}> $bundled
 * @return array{0: array<string, string>, 1: array<string, string>} [demands, pinned name => version]
 */
function pinBundledDemands(array $demands, array $bundled): array
{
    $pinned = [];
    foreach ($demands as $name => $constraint) {
        $name = (string) $name;
        $version = $bundled[$name]['version'] ?? null;
        if ($version !== null && satisfies($version, (string) $constraint)) {
            $demands[$name] = '=' . $version;
            $pinned[$name] = $version;
        }
    }

    return [$demands, $pinned];
}

/**
 * Whether a demand set closes over provided and bundled packages alone: every
 * name is provided or bundled, every constraint is satisfied by the carried
 * version, transitively. When true, no catalog read is needed at all — the
 * command can skip `loadVerifiedCatalog` and do zero web requests. Satisfaction
 * is per demand, not per name: a demand the carried version cannot meet needs
 * the catalog, so the gate answers false and the registry path runs.
 *
 * @param array<string, string> $demands name => constraint, from the descriptor
 * @param array<string, array{version: string, dir: string, manifest: string}> $bundled
 * @param array<string, string> $provided name => version, the packages the compiler carries
 */
function demandsCloseLocally(array $demands, array $bundled, array $provided): bool
{
    $seen = [];
    $queue = [];
    foreach ($demands as $name => $constraint) {
        $queue[] = [(string) $name, (string) $constraint];
    }
    while ($queue !== []) {
        [$name, $constraint] = \array_shift($queue);
        $key = $name . "\0" . $constraint;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        if (isset($provided[$name])) {
            if (!satisfies($provided[$name], $constraint)) {
                return false;
            }
            continue;
        }
        if (!isset($bundled[$name]) || !satisfies($bundled[$name]['version'], $constraint)) {
            return false;
        }
        try {
            $manifest = readDescriptor($bundled[$name]['manifest'] ?? '');
        } catch (\RuntimeException) {
            return false;
        }
        foreach ((array) ($manifest['dependencies'] ?? []) as $dependency => $sub) {
            $queue[] = [(string) $dependency, (string) $sub];
        }
    }

    return true;
}

/** Whether every package a lock names came bundled (provided packages are never locked). */
function lockIsLocal(array $lock): bool
{
    $packages = (array) ($lock['packages'] ?? []);
    if ($packages === []) {
        return true;
    }
    foreach ($packages as $entry) {
        if (($entry['bundled'] ?? false) !== true) {
            return false;
        }
    }

    return true;
}

/**
 * The new-release line a bare `moggi add <bundled>` records: the bundled
 * version's own major line, the same `X.Y` floor `registryConstraintFor`
 * derives from the registry's newest. `moggi update` may move within the line
 * when the registry is consulted; the bundled pin keeps the resolution itself
 * on the copy in hand until then.
 */
function bundledConstraintFor(string $version): string
{
    $parts = \explode('.', $version);

    return \count($parts) >= 2 ? $parts[0] . '.' . $parts[1] : $version;
}

/**
 * The digest a lock records for a bundled package: the blob digest of the
 * canonical archive the shipped tree packs to, via the same packer `publish`
 * uses. Deterministic in the tree's bytes, so the install re-packs and
 * compares rather than trusting the directory.
 */
function bundledArchiveDigest(string $dir): string
{
    return archiveDigest(packDirectory($dir));
}

/**
 * Install one locked bundled package from the shipped tree: the manifest must
 * still name the locked version, and the tree must still pack to the locked
 * digest — either check failing is a refusal naming what moved, never a quiet
 * substitution. The packed bytes enter the content cache under their digest
 * and unpack through the same atomic path as a fetched archive, so a bundled
 * install and a registry install of identical bytes are indistinguishable
 * downstream.
 *
 * @param array{version: string, dir: string, manifest: string} $spec
 * @return array{ok: bool, detail: string, roots: list<string>}
 */
function installBundledPackage(string $name, string $version, mixed $lockedDigest, array $spec): array
{
    $dir = $spec['dir'] ?? '';
    $manifest = $spec['manifest'] ?? '';
    if (!\is_string($dir) || $dir === '' || !\is_dir($dir)) {
        return ['ok' => false, 'detail' => "the compiler no longer ships `{$name}` — run `moggi update` against the registry", 'roots' => []];
    }
    try {
        $descriptor = readDescriptor((string) $manifest);
    } catch (\RuntimeException $error) {
        return ['ok' => false, 'detail' => "the shipped `{$name}` cannot be read: {$error->getMessage()}", 'roots' => []];
    }
    if ((string) ($descriptor['version'] ?? '') !== $version) {
        return [
            'ok' => false,
            'detail' => "the compiler now ships `{$name}` " . (string) ($descriptor['version'] ?? '?')
                . ", but the lock names {$version} — run `moggi update` to re-resolve",
            'roots' => [],
        ];
    }
    if (!\is_string($lockedDigest) || $lockedDigest === '') {
        return ['ok' => false, 'detail' => "`{$name}` names no digest in the lock — re-resolve and rewrite it", 'roots' => []];
    }
    try {
        $archive = packDirectory($dir);
    } catch (\RuntimeException $error) {
        return ['ok' => false, 'detail' => "the shipped `{$name}` cannot be packed: {$error->getMessage()}", 'roots' => []];
    }
    $actual = archiveDigest($archive);
    if ($actual !== $lockedDigest) {
        return [
            'ok' => false,
            'detail' => "`{$name}` {$version} in this compiler packs to {$actual}, not the locked {$lockedDigest} — the shipped tree moved under the lock; run `moggi update` to re-resolve",
            'roots' => [],
        ];
    }

    $path = downloadPath($lockedDigest);
    if (!(\is_file($path) && 'sha256:' . \hash_file('sha256', $path) === $lockedDigest)) {
        if (!ensureDownloadDir($path) || @\file_put_contents($path, $archive) === false) {
            return ['ok' => false, 'detail' => "cannot write {$path}", 'roots' => []];
        }
    }
    $install = lockedInstallDir($name, $lockedDigest);
    $problem = unpackArchive($path, $install);
    if ($problem !== null) {
        return ['ok' => false, 'detail' => $problem, 'roots' => []];
    }
    $manifestBytes = @\file_get_contents((string) $manifest);

    return [
        'ok' => true,
        'detail' => "bundled with the compiler ({$version}), unpacked to {$install}",
        'roots' => libraryRoots($install, \is_string($manifestBytes) ? $manifestBytes : ''),
    ];
}

/** Ensure the directory holding a content-cache path exists. */
function ensureDownloadDir(string $path): bool
{
    return ensureDir(\dirname($path));
}

/**
 * The build-time check for a locked bundled package: the compiler must still
 * ship the name, and the shipped manifest must still name the locked version.
 * Byte identity was established at install time (repack against the locked
 * digest) and crash-safety comes from `.unpacked`, exactly as for a fetched
 * package — so this is a version check, not a re-pack, and a compiler upgrade
 * that moved the bundled copy fails here with the re-resolve to run.
 *
 * @return ?string a problem, or null when the installed tree is the locked one
 */
function checkBundledInstalled(string $name, string $version): ?string
{
    $spec = bundledPackages()[$name] ?? null;
    if ($spec === null) {
        return "the compiler no longer ships `{$name}` — run `moggi update` against the registry";
    }
    try {
        $descriptor = readDescriptor($spec['manifest']);
    } catch (\RuntimeException $error) {
        return "the shipped `{$name}` cannot be read: {$error->getMessage()}";
    }
    if ((string) ($descriptor['version'] ?? '') !== $version) {
        return "the compiler now ships `{$name}` " . (string) ($descriptor['version'] ?? '?')
            . ", but the lock names {$version} — run `moggi update` to re-resolve";
    }

    return null;
}
