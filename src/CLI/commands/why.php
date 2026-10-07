<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Registry\Catalog;
use Moggi\Registry\FetchLog;

use function Moggi\Registry\badPackageMarker;
use function Moggi\Registry\badPackageProblem;
use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\lockPath;
use function Moggi\Registry\lockRegistryProblem;
use function Moggi\Registry\providedPackages;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\readLock;
use function Moggi\Registry\resolveWithDetails;
use function Moggi\Registry\shortNpub;
use function Moggi\Registry\satisfies;
use function Moggi\Registry\unmaintainedMarker;
use function Moggi\Registry\unmaintainedPackageNote;

/**
 * `moggi why` — which demands pulled a version in, and what holds it back.
 *
 * The demand graph the resolver already builds decides for a package: the chains
 * that asked for it, each at its own constraint, and — when a newer version is
 * published but no chain would accept it — the constraints doing the refusing.
 * With no name the whole lock is explained, in lock order; with one, only that
 * package.
 *
 * The lock is read, never resolved again: `why` explains the install that exists,
 * which is also why a package in the lock the descriptor no longer asks for is
 * reported as such rather than hidden.
 */
function whyUsage(): string
{
    $default = DEFAULT_REGISTRY;

    return <<<HELP
    usage:
      moggi why [<name>] [options]

    Explain which demands pulled a package into the lock: the chains that asked
    for it and their constraints, and — when a newer version is published but
    refused — the constraints refusing it. With no name, every package in the
    lock is explained.

    options:
      --registry URL|DIR   registry to read the catalog from
                           (default: MOGGI_REGISTRY, else {$default})
      -o, --output FILE    lock file to read (default: moggi.lock)
      --no-cache           re-fetch metadata instead of using what is held
      --json               machine-readable envelope
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runWhyCommand(array $argv): int
{
    $spec = new CommandSpec('why', whyUsage(), [
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

    $only = $options['path'] === '.' ? null : (string) $options['path'];

    try {
        $descriptorPath = findDescriptor('.');
        $descriptor = readDescriptor($descriptorPath);

        $log = new FetchLog();
        $catalog = loadVerifiedCatalog($options['registry'], !$options['noCache'], $log);

        $lockFile = lockPath($descriptorPath, $options['output']);
        $lock = readLock($lockFile);
        if ($lock === null) {
            throw new \RuntimeException("no lock at {$lockFile} — run `moggi update` first");
        }
        $registryProblem = lockRegistryProblem($lock, $catalog->npub());
        if ($registryProblem !== null) {
            throw new \RuntimeException($registryProblem . ' — point --registry at the registry the lock names');
        }

        $names = whyNames($lock, $only);

        $resolution = resolveWithDetails($catalog->entry(...), $descriptor['dependencies'], 'root', providedPackages());
        if (!$resolution['ok']) {
            throw new \RuntimeException((string) $resolution['error']);
        }

        $why = [];
        foreach ($names as $name) {
            $why[$name] = whyEntry($name, $lock, $resolution, $catalog);
        }

        if ($options['json']) {
            printJsonEnvelope($lock, [
                'why' => $why,
                'requests' => $log->requests,
                'held' => $log->held,
            ]);

            return 0;
        }

        reportWhy($descriptor, $lock, $why, $log);

        return 0;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * The packages to explain: one named one, or every package in the lock.
 *
 * @param array<string, mixed> $lock
 * @return list<string>
 */
function whyNames(array $lock, ?string $only): array
{
    $locked = \array_map('strval', \array_keys((array) ($lock['packages'] ?? [])));
    if ($only === null) {
        return $locked;
    }
    if (!\in_array($only, $locked, true)) {
        throw new \RuntimeException(
            "`{$only}` is not in the lock" . ($locked === [] ? '' : ' (locked: ' . \implode(', ', $locked) . ')'),
        );
    }

    return [$only];
}

/**
 * One package's explanation: the lock's version, what the descriptor would
 * resolve, every chain that demanded it, and any chain that refuses a newer one.
 *
 * @param array<string, mixed> $lock
 * @param array{chosen: array<string, string>, reasons: array<string, list<array{constraint: string, path: string}>>} $resolution
 * @return array{version: string, wanted: ?string, requiredBy: list<array{path: string, constraint: string}>, heldBy: list<array{path: string, constraint: string}>, available: list<string>, bad: ?array{reason: string, at: ?string, by: ?string}, unmaintained: ?array{note: ?string, at: ?string}}
 */
function whyEntry(string $name, array $lock, array $resolution, Catalog $catalog): array
{
    $version = (string) ($lock['packages'][$name]['version'] ?? '');
    $reasons = \array_values($resolution['reasons'][$name] ?? []);
    $available = publishedVersions($catalog, $name);
    $latest = $available[0] ?? null;

    $heldBy = [];
    if ($latest !== null && $latest !== $version) {
        foreach ($reasons as $reason) {
            if (!satisfies($latest, $reason['constraint'])) {
                $heldBy[] = $reason;
            }
        }
    }

    $entry = (array) ($catalog->entry($name) ?? []);

    return [
        'version' => $version,
        'wanted' => isset($resolution['chosen'][$name]) ? (string) $resolution['chosen'][$name] : null,
        'requiredBy' => $reasons,
        'heldBy' => $heldBy,
        'available' => $available,
        'bad' => badPackageMarker($entry),
        'unmaintained' => unmaintainedMarker($entry),
    ];
}

/**
 * @param array<string, mixed> $descriptor
 * @param array<string, mixed> $lock
 * @param array<string, array{version: string, wanted: ?string, requiredBy: list<array{path: string, constraint: string}>, heldBy: list<array{path: string, constraint: string}>, available: list<string>, bad: ?array{reason: string, at: ?string, by: ?string}, unmaintained: ?array{note: ?string, at: ?string}}> $why
 */
function reportWhy(array $descriptor, array $lock, array $why, FetchLog $log): void
{
    \printf("moggi why — %s %s\n", $descriptor['name'], $descriptor['version']);
    \printf("registry   %s\n", shortNpub((string) ($lock['registry']['npub'] ?? '—')));
    \printf("requests   %s\n", $log->describe());

    if ($why === []) {
        echo "\nthe lock is empty\n";

        return;
    }

    foreach ($why as $name => $entry) {
        \printf("\n%s  %s\n", $name, $entry['version']);
        if (\is_array($entry['bad'])) {
            \printf("  %s\n", badPackageProblem((string) $name, $entry['bad']));
        }
        if (\is_array($entry['unmaintained'])) {
            \printf("  %s\n", unmaintainedPackageNote((string) $name, $entry['unmaintained']));
        }

        echo "\n  required by\n";
        if ($entry['requiredBy'] === []) {
            echo "    (nothing in the current descriptor asks for it)\n";
        } else {
            foreach ($entry['requiredBy'] as $reason) {
                \printf("    %-28s %s\n", $reason['path'], $reason['constraint']);
            }
        }

        if ($entry['heldBy'] !== []) {
            \printf("\n  held at %s by\n", $entry['version']);
            foreach ($entry['heldBy'] as $reason) {
                \printf("    %-28s %s\n", $reason['path'], $reason['constraint']);
            }
            \printf("  available: %s\n", \implode(', ', $entry['available']));
        } elseif ($entry['wanted'] !== null && $entry['wanted'] !== $entry['version']) {
            \printf("\n  the lock is behind: the descriptor resolves to %s — run `moggi update`\n", $entry['wanted']);
        }
    }
}
