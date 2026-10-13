<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Registry\Catalog;
use Moggi\Registry\FetchLog;

use function Moggi\Registry\badPackageMarker;
use function Moggi\Registry\badPackageProblem;
use function Moggi\Registry\fetchVerifiedRelease;
use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\lockPath;
use function Moggi\Registry\lockRegistryProblem;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\readLock;
use function Moggi\Registry\shortNpub;
use function Moggi\Registry\unmaintainedMarker;
use function Moggi\Registry\unmaintainedPackageNote;

/**
 * `moggi verify` — check that the lock is exactly what the registry signs.
 *
 * The two checks `install` runs before it unpacks anything, on their own and
 * read-only: each locked release record against the digest the catalog publishes
 * for it, and its signature against that package's *allowed authors* — never a
 * key the package file nominates for itself. No blob is fetched and nothing is
 * unpacked, so it is the cheap way to answer "is this lock still trustworthy"
 * after the registry or a checkout has moved.
 *
 * It verifies the lock rather than a name so the answer is about *this* project:
 * the set of releases a build would use.
 */
function verifyUsage(): string
{
    $default = DEFAULT_REGISTRY;

    return <<<HELP
    usage:
      moggi verify [<dir|<name>.moggi>] [options]

    Check every package in moggi.lock against the registry: the release record
    against the digest the catalog publishes, and its signature against the
    package's allowed authors. Nothing is downloaded and nothing is unpacked.

    A package the registry has marked bad or unmaintained is reported beside its
    verdict; the exit status still reflects the signature checks alone.

    options:
      --registry URL|DIR   registry to verify against
                           (default: MOGGI_REGISTRY, else {$default})
      -o, --output FILE    lock file to verify (default: moggi.lock)
      --no-cache           re-fetch metadata instead of using what is held
      --json               machine-readable envelope
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runVerifyCommand(array $argv): int
{
    $spec = new CommandSpec('verify', verifyUsage(), [
        ['name' => 'json'],
        ['name' => 'noCache'],
    ]);

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
        $descriptorPath = findDescriptor($options['path']);
        $descriptor = readDescriptor($descriptorPath);

        $lockFile = lockPath($descriptorPath, $options['output']);
        $lock = readLock($lockFile);
        if ($lock === null) {
            throw new \RuntimeException("no lock at {$lockFile} — run `moggi install` first");
        }

        $log = new FetchLog();
        $catalog = loadVerifiedCatalog($options['registry'], !$options['noCache'], $log);
        $registryProblem = lockRegistryProblem($lock, $catalog->npub());
        if ($registryProblem !== null) {
            throw new \RuntimeException($registryProblem . ' — run `moggi update` against the registry you mean');
        }
        $checks = verifyLockedReleases($options['registry'], $lock, $catalog, !$options['noCache'], $log);

        $ok = true;
        foreach ($checks as $check) {
            $ok = $ok && $check['ok'];
        }

        if ($options['json']) {
            echo prettyJson(['checks' => $checks, 'ok' => $ok, 'requests' => $log->requests, 'held' => $log->held]) . "\n";

            return $ok ? 0 : 1;
        }

        reportVerify($descriptor, $checks, $catalog, $log);

        return $ok ? 0 : 1;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * One verdict per locked package, with the registry's markers beside it.
 *
 * @param array<string, mixed> $lock
 * @return list<array{name: string, version: string, ok: bool, detail: string, bad: ?array{reason: string, at: ?string, by: ?string}, unmaintained: ?array{note: ?string, at: ?string}}>
 */
function verifyLockedReleases(string $registry, array $lock, Catalog $catalog, bool $useCache, FetchLog $log): array
{
    $checks = [];
    foreach (($lock['packages'] ?? []) as $name => $locked) {
        $name = (string) $name;
        $locked = (array) $locked;
        $version = (string) ($locked['version'] ?? '');

        $entry = $catalog->entry($name);
        if ($entry === null) {
            $checks[] = ['name' => $name, 'version' => $version, 'ok' => false, 'detail' => 'no longer in the catalog', 'bad' => null, 'unmaintained' => null];

            continue;
        }
        if (!isset($entry['versions'][$version])) {
            $checks[] = ['name' => $name, 'version' => $version, 'ok' => false, 'detail' => 'not published any more', 'bad' => badPackageMarker((array) $entry), 'unmaintained' => unmaintainedMarker((array) $entry)];

            continue;
        }

        $verified = fetchVerifiedRelease($registry, $name, $version, $locked['digest'] ?? null, $entry, $useCache, null, $log);
        $checks[] = [
            'name' => $name,
            'version' => $version,
            'ok' => $verified['ok'],
            'detail' => $verified['ok'] ? 'signed by ' . shortNpub((string) $verified['signer']) : $verified['detail'],
            'bad' => badPackageMarker((array) $entry),
            'unmaintained' => unmaintainedMarker((array) $entry),
        ];
    }

    return $checks;
}

/**
 * @param array<string, mixed> $descriptor
 * @param list<array{name: string, version: string, ok: bool, detail: string, bad: ?array{reason: string, at: ?string, by: ?string}, unmaintained: ?array{note: ?string, at: ?string}}> $checks
 */
function reportVerify(array $descriptor, array $checks, Catalog $catalog, FetchLog $log): void
{
    $count = \count($checks);
    \printf("%s %s — %d package%s\n", $descriptor['name'], $descriptor['version'], $count, $count === 1 ? '' : 's');
    \printf(
        "registry   %s (%s)\n",
        shortNpub((string) ($catalog->npub() ?? '—')),
        $catalog->signature()['ok'] ? 'signature verified' : 'signature NOT verified',
    );
    \printf("requests   %s\n", $log->describe());

    if ($checks === []) {
        echo "\nnothing to verify\n";

        return;
    }

    echo "\n";
    foreach ($checks as $check) {
        \printf("  %-20s %-12s %-4s %s\n", $check['name'], $check['version'], $check['ok'] ? 'ok' : 'FAIL', $check['detail']);
        if (\is_array($check['bad'])) {
            \printf("  %-38s %s\n", '', badPackageProblem($check['name'], $check['bad']));
        }
        if (\is_array($check['unmaintained'])) {
            \printf("  %-38s %s\n", '', unmaintainedPackageNote($check['name'], $check['unmaintained']));
        }
    }
}
