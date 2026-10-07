<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Registry\Catalog;
use Moggi\Registry\FetchLog;

use function Moggi\Registry\badPackageMarker;
use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\lockPath;
use function Moggi\Registry\lockRegistryProblem;
use function Moggi\Registry\newestFirst;
use function Moggi\Registry\providedPackages;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\readLock;
use function Moggi\Registry\resolveWithDetails;
use function Moggi\Registry\shortNpub;
use function Moggi\Registry\satisfies;
use function Moggi\Registry\unmaintainedMarker;

/**
 * `moggi outdated` — what a lock could move to.
 *
 * The read-only twin of `update`: same descriptor, same registry, same
 * resolution, but it neither writes nor moves the lock. Three versions per
 * package say what a person has to decide — what the lock holds now, what the
 * descriptor's own constraints allow on a fresh resolution, and what is newest in
 * the catalog. `current` below `wanted` means `moggi update`; `wanted` below
 * `latest` means the descriptor's floor is what blocks the move, and the
 * constraint that refuses the newest is named.
 *
 * A locked version the catalog no longer publishes is reported rather than
 * silently omitted: the resolution simply picks something else, and the row says
 * the old version is gone.
 */
function outdatedUsage(): string
{
    $default = DEFAULT_REGISTRY;

    return <<<HELP
    usage:
      moggi outdated [<dir|<name>.moggi>] [options]

    Report every package in the lock: what it is now, what the descriptor would
    resolve it to, and what is newest in the registry. Reads only — `update` is
    what moves a lock.

    options:
      --registry URL|DIR   registry to resolve against
                           (default: MOGGI_REGISTRY, else {$default})
      -o, --output FILE    lock file to read (default: moggi.lock)
      --no-cache           re-fetch metadata instead of using what is held
      --json               machine-readable envelope
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runOutdatedCommand(array $argv): int
{
    $spec = new CommandSpec('outdated', outdatedUsage(), [
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

        $resolution = resolveWithDetails($catalog->entry(...), $descriptor['dependencies'], 'root', providedPackages());
        if (!$resolution['ok']) {
            throw new \RuntimeException((string) $resolution['error']);
        }

        $rows = outdatedRows($lock, $resolution, $catalog);

        if ($options['json']) {
            printJsonEnvelope($lock, [
                'packages' => $rows,
                'requests' => $log->requests,
                'held' => $log->held,
            ]);

            return 0;
        }

        reportOutdated($descriptor, $lock, $rows, $log);

        return 0;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * One row per locked package: where it is, where the descriptor allows it, and
 * where the catalog's newest is.
 *
 * @param array<string, mixed> $lock
 * @param array{chosen: array<string, string>, reasons: array<string, list<array{constraint: string, path: string}>>} $resolution
 * @return list<array{name: string, current: string, wanted: ?string, latest: ?string, available: bool, constraint: ?string, bad: ?array{reason: string, at: ?string, by: ?string}, unmaintained: ?array{note: ?string, at: ?string}}>
 */
function outdatedRows(array $lock, array $resolution, Catalog $catalog): array
{
    $rows = [];
    foreach ((array) ($lock['packages'] ?? []) as $name => $entry) {
        $name = (string) $name;
        $current = (string) ($entry['version'] ?? '');
        $versions = publishedVersions($catalog, $name);
        $latest = $versions[0] ?? null;
        $wanted = isset($resolution['chosen'][$name]) ? (string) $resolution['chosen'][$name] : null;
        $constraint = null;
        if ($latest !== null && $latest !== $wanted) {
            $constraint = bindingConstraint($resolution['reasons'][$name] ?? [], $latest);
        }
        $rows[] = [
            'name' => $name,
            'current' => $current,
            'wanted' => $wanted,
            'latest' => $latest,
            'available' => \in_array($current, $versions, true),
            'constraint' => $constraint,
            'bad' => badPackageMarker((array) ($catalog->entry($name) ?? [])),
            'unmaintained' => unmaintainedMarker((array) ($catalog->entry($name) ?? [])),
        ];
    }

    return $rows;
}

/**
 * Every version the catalog publishes for a package, newest first.
 *
 * @return list<string>
 */
function publishedVersions(Catalog $catalog, string $name): array
{
    $versions = \array_map('strval', \array_keys((array) ($catalog->entry($name)['versions'] ?? [])));
    \usort($versions, newestFirst(...));

    return $versions;
}

/**
 * A constraint that refuses a version, so a report can name what holds it back.
 *
 * @param list<array{constraint: string, path: string}> $reasons
 */
function bindingConstraint(array $reasons, string $version): ?string
{
    foreach ($reasons as $reason) {
        if (!satisfies($version, $reason['constraint'])) {
            return $reason['constraint'];
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $descriptor
 * @param array<string, mixed> $lock
 * @param list<array{name: string, current: string, wanted: ?string, latest: ?string, available: bool, constraint: ?string, bad: ?array{reason: string, at: ?string, by: ?string}, unmaintained: ?array{note: ?string, at: ?string}}> $rows
 */
function reportOutdated(array $descriptor, array $lock, array $rows, FetchLog $log): void
{
    \printf(
        "moggi outdated — %s %s — %d dependenc%s\n",
        $descriptor['name'],
        $descriptor['version'],
        \count($rows),
        \count($rows) === 1 ? 'y' : 'ies',
    );
    \printf("registry   %s\n", shortNpub((string) ($lock['registry']['npub'] ?? '—')));
    \printf("requests   %s\n", $log->describe());

    $moving = \array_values(\array_filter(
        $rows,
        static fn (array $row): bool => $row['bad'] !== null
            || $row['unmaintained'] !== null
            || !$row['available']
            || ($row['wanted'] !== null && $row['wanted'] !== $row['current'])
            || ($row['latest'] !== null && $row['latest'] !== $row['current']),
    ));
    if ($moving === []) {
        echo "\neverything is at the newest version the descriptor allows\n";

        return;
    }

    echo "\n";
    foreach ($moving as $row) {
        $wanted = $row['wanted'] ?? '—';
        $arrow = $row['wanted'] !== null && $row['wanted'] !== $row['current'] ? '->' : '  ';
        \printf(
            "  %-20s %-12s %s  %-12s (latest %s)%s\n",
            $row['name'],
            $row['current'],
            $arrow,
            $wanted,
            $row['latest'] ?? '—',
            outdatedNote($row),
        );
    }
}

/**
 * @param array{name: string, current: string, wanted: ?string, latest: ?string, available: bool, constraint: ?string, bad: ?array{reason: string, at: ?string, by: ?string}, unmaintained: ?array{note: ?string, at: ?string}} $row
 */
function outdatedNote(array $row): string
{
    $notes = [];
    if ($row['bad'] !== null) {
        $notes[] = 'MARKED BAD — ' . $row['bad']['reason'];
    }
    if ($row['unmaintained'] !== null) {
        $notes[] = 'MARKED UNMAINTAINED' . ($row['unmaintained']['note'] === null ? '' : ' — ' . $row['unmaintained']['note']);
    }
    if (!$row['available']) {
        $notes[] = "(`{$row['current']}` is no longer published)";
    }
    if ($row['constraint'] !== null) {
        $notes[] = "held by `{$row['constraint']}`";
    }

    return $notes === [] ? '' : '  ' . \implode('  ', $notes);
}
