<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Registry\FetchLog;

use function Moggi\Registry\badPackageProblem;
use function Moggi\Registry\badPackagesAmong;
use function Moggi\Registry\badPackagesRefusal;
use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\installLockedPackages;
use function Moggi\Registry\lockDisagreements;
use function Moggi\Registry\lockPath;
use function Moggi\Registry\lockRegistryProblem;
use function Moggi\Registry\phpExtensionDescriptors;
use function Moggi\Registry\phpExtensionProblems;
use function Moggi\Registry\providedDependencyProblems;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\readLock;
use function Moggi\Registry\shortNpub;
use function Moggi\Registry\unmaintainedPackageNote;
use function Moggi\Registry\unmaintainedPackagesAmong;

/**
 * `moggi install` — make the lock true.
 *
 * With no lock, one is resolved and written, by the same code `update` runs. With
 * one, it is *checked against a fresh catalog* rather than re-resolved: silently
 * rewriting a lock on install is how a build stops being reproducible, so a lock
 * that no longer matches is an error naming `moggi update`.
 *
 * Then the checks in `installLockedPackages`, one refusal point per step.
 */
function installUsage(): string
{
    return <<<HELP
    usage:
      moggi install [<dir|<name>.moggi>] [options]

    Install every package the lock names. Without a lock, one is resolved and
    written first; with one, it is checked against the registry catalog and
    install refuses if it no longer matches.

    Each package is verified before anything is unpacked: the release record
    against the catalog's digest, the signature against the catalog's allowed
    users, and the archive against the blob digest the release names. Docs are
    not fetched — the registry's site serves them and the client renders them
    locally from the sources just unpacked. Archives land in the user-level
    cache, unpacked trees in the project cache — neither is fetched twice.

    options:
      --registry URL|DIR   registry to install from
      -o, --output FILE    lock file to use (default: moggi.lock)
      --frozen             never resolve: the lock must exist and match
      --allow-bad          install even though the registry marked a lock package bad
      --dry-run            report what would be installed, touch nothing
      --no-cache           re-fetch metadata instead of using what is held
      --json               machine-readable envelope
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runInstallCommand(array $argv): int
{
    $spec = new CommandSpec('install', installUsage(), [
        ['name' => 'dryRun'],
        ['name' => 'json'],
        ['name' => 'noCache'],
        ['name' => 'frozen'],
        ['name' => 'allowBad'],
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

        $provided = providedDependencyProblems($descriptor['dependencies']);
        if ($provided !== []) {
            throw new \RuntimeException(\implode("\n", $provided));
        }

        $log = new FetchLog();
        $catalog = loadVerifiedCatalog($options['registry'], !$options['noCache'], $log);

        $lockFile = lockPath($descriptorPath, $options['output']);
        $lock = readLock($lockFile);
        $resolved = false;

        if ($lock === null) {
            if ($options['frozen']) {
                throw new \RuntimeException("--frozen needs a lock, and there is none at {$lockFile}");
            }
            $resolution = writeResolution($descriptor, $descriptorPath, $options['registry'], $catalog, $options['output'], (bool) $options['dryRun'], (bool) $options['allowBad']);
            $lock = $resolution['document'];
            $resolved = true;
        }

        $entries = $catalog->entries(lockedPackageNames($lock));
        if (!$resolved) {
            $problems = lockDisagreements($lock, $entries);
            if ($problems !== []) {
                throw new \RuntimeException(
                    "the lock does not match the catalog:\n  " . \implode("\n  ", $problems)
                    . "\nrun `moggi update` to re-resolve, or fix the pin in the descriptor",
                );
            }
        }

        $registryProblem = lockRegistryProblem($lock, $catalog->npub());
        if ($registryProblem !== null) {
            throw new \RuntimeException($registryProblem . ' — run `moggi update` against the registry you mean');
        }

        $bad = badPackagesAmong($entries);
        if ($bad !== [] && !$options['allowBad']) {
            throw new \RuntimeException(badPackagesRefusal(
                $bad,
                'in this lock',
                'pass --allow-bad to install them anyway',
            ));
        }
        foreach ($bad as $name => $marker) {
            \fwrite(STDERR, 'warning: ' . badPackageProblem($name, $marker) . "\n");
        }
        foreach (unmaintainedPackagesAmong($entries) as $name => $marker) {
            \fwrite(STDERR, 'warning: ' . unmaintainedPackageNote($name, $marker) . "\n");
        }

        if ($options['dryRun']) {
            \printf("would install %d package%s from %s\n", \count($lock['packages'] ?? []), \count($lock['packages'] ?? []) === 1 ? '' : 's', $options['registry']);
            foreach (($lock['packages'] ?? []) as $name => $entry) {
                \printf("  %-20s %s\n", $name, $entry['version']);
            }

            return 0;
        }

        $result = installLockedPackages($options['registry'], $lock, $entries, !$options['noCache'], null, $log, $catalog->blobsBase());

        $extensionProblems = phpExtensionProblems(phpExtensionDescriptors($descriptor, $lock))['problems'];

        if ($options['json']) {
            printJsonEnvelope($lock, [
                'installed' => $result['results'],
                'requests' => $log->requests,
                'held' => $log->held,
                'extensions' => $extensionProblems,
                'ok' => $result['ok'],
            ]);

            return $result['ok'] ? 0 : 1;
        }

        reportInstall($descriptor, $lock, $result['results'], $log, $resolved, $lockFile);
        reportExtensionRequirements($extensionProblems);

        return $result['ok'] ? 0 : 1;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * Print the `[php.extensions]` requirements the closure declares that neither the
 * PHP running this nor the micro runtime provides, now that every locked package
 * is unpacked and its descriptor can be read.
 *
 * @param list<string> $problems
 */
function reportExtensionRequirements(array $problems): void
{
    if ($problems === []) {
        return;
    }

    echo "\nextension requirements:\n";
    foreach ($problems as $problem) {
        echo "  {$problem}\n";
    }
}

/**
 * The packages a lock names, in lock order — the set whose catalog entries a
 * command needs.
 *
 * @param array<string, mixed> $lock
 * @return list<string>
 */
function lockedPackageNames(array $lock): array
{
    return \array_map('strval', \array_keys((array) ($lock['packages'] ?? [])));
}

/**
 * @param array<string, mixed> $descriptor
 * @param array<string, mixed> $lock
 * @param list<array<string, mixed>> $results
 */
function reportInstall(array $descriptor, array $lock, array $results, FetchLog $log, bool $resolved, string $lockFile): void
{
    $count = \count($lock['packages'] ?? []);
    \printf("%s %s — %d package%s\n", $descriptor['name'], $descriptor['version'], $count, $count === 1 ? '' : 's');
    \printf("registry   %s\n", shortNpub((string) ($lock['registry']['npub'] ?? '—')));
    \printf("requests   %s\n", $log->describe());
    if ($resolved) {
        \printf("lock       resolved and written to %s\n", $lockFile);
    }

    if ($results === []) {
        echo "\nnothing to install\n";

        return;
    }

    echo "\n";
    foreach ($results as $result) {
        \printf(
            "  %-20s %-12s %-10s %s\n",
            $result['name'],
            $result['version'],
            $result['status'],
            $result['detail'],
        );
    }

    $roots = [];
    foreach ($results as $result) {
        foreach (($result['roots'] ?? []) as $root) {
            $roots[] = $root;
        }
    }
    if ($roots !== []) {
        echo "\nlibrary roots (--lib):\n";
        foreach ($roots as $root) {
            echo "  {$root}\n";
        }
    }
}
