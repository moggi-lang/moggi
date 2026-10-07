<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Registry\FetchLog;

use function Moggi\Registry\badPackageMarker;
use function Moggi\Registry\isPackageName;
use function Moggi\Registry\newestFirst;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\shortNpub;
use function Moggi\Registry\unmaintainedMarker;

/**
 * `moggi show` — one package, as the signed catalog describes it.
 *
 * Read-only and catalog-only: no package file is fetched, so it answers from the
 * shard the root already vouches for, and the `bad` marker the registry publishes
 * is printed first because it is the one thing a reader must not miss. `search`
 * is the other half of the same read.
 */
function showUsage(): string
{
    return <<<HELP
    usage:
      moggi show <name> [options]

    Print what the signed catalog says about one package: its owner and allowed
    users, its versions, its description, and the `bad` / unmaintained markers the
    registry publishes. Nothing heavy is fetched.

    options:
      --registry URL|DIR   registry to read (default: MOGGI_REGISTRY)
      --no-cache           re-fetch metadata instead of using what is held
      --json               machine-readable envelope
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runShowCommand(array $argv): int
{
    $spec = new CommandSpec('show', showUsage(), [
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

    $name = \trim((string) $options['path']);
    if ($name === '' || $name === '.' || !isPackageName($name)) {
        \fwrite(STDERR, "error: `moggi show` needs one package name\n\n" . showUsage() . "\n");

        return 1;
    }

    try {
        $log = new FetchLog();
        $catalog = loadVerifiedCatalog($options['registry'], !$options['noCache'], $log);
        $entry = $catalog->entry($name);
        if ($entry === null) {
            throw new \RuntimeException("no package named `{$name}` at {$options['registry']}");
        }

        $report = showReport($name, (array) $entry, (string) $options['registry']);
        if ($options['json']) {
            echo prettyJson($report) . "\n";

            return 0;
        }

        reportShow($report, $log);

        return 0;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * One package's catalog entry, as data.
 *
 * @param array<string, mixed> $entry
 * @return array<string, mixed>
 */
function showReport(string $name, array $entry, string $registry): array
{
    $versions = \array_map('strval', \array_keys((array) ($entry['versions'] ?? [])));
    \usort($versions, newestFirst(...));
    $bad = badPackageMarker($entry);
    $latest = isset($entry['latest']) ? (string) $entry['latest'] : ($versions[0] ?? null);

    return [
        'name' => $name,
        'registry' => $registry,
        'owner' => isset($entry['owner']) ? (string) $entry['owner'] : null,
        'authors' => \array_values(\array_map('strval', (array) ($entry['authors'] ?? []))),
        'latest' => $latest,
        'description' => isset($entry['description']) ? (string) $entry['description'] : null,
        'versions' => $versions,
        'docs' => showDocsLocation($name, $latest, $registry),
        'bad' => $bad,
        'unmaintained' => unmaintainedMarker($entry),
    ];
}

/** Where the registry serves this release's documentation, when a version is known. */
function showDocsLocation(string $name, ?string $version, string $registry): ?string
{
    if ($version === null) {
        return null;
    }
    $path = 'docs/' . $name . '/' . $version;

    return \preg_match('#^https?://#', $registry) === 1 ? \rtrim($registry, '/') . '/' . $path : $path;
}

/**
 * @param array<string, mixed> $report the result of {@see showReport}
 */
function reportShow(array $report, FetchLog $log): void
{
    \printf("%s%s — %s\n", (string) $report['name'], $report['latest'] === null ? '' : ' ' . $report['latest'], (string) $report['registry']);
    if ($report['owner'] !== null) {
        \printf("  %-12s %s\n", 'owner', shortNpub((string) $report['owner']));
    }
    if ($report['authors'] !== []) {
        \printf("  %-12s %s\n", 'authors', \implode(', ', \array_map(shortNpub(...), $report['authors'])));
    }
    if ($report['versions'] !== []) {
        \printf("  %-12s %s\n", 'versions', \implode(', ', $report['versions']));
    }
    if ($report['docs'] !== null) {
        \printf("  %-12s %s\n", 'docs', (string) $report['docs']);
    }
    if ($report['description'] !== null && $report['description'] !== '') {
        \printf("  %-12s %s\n", 'about', (string) $report['description']);
    }

    $bad = $report['bad'];
    if (\is_array($bad)) {
        $reason = (string) $bad['reason'];
        $by = $bad['by'] === null ? '' : ' (marked by ' . shortNpub((string) $bad['by']) . ')';
        $at = $bad['at'] === null ? '' : ' on ' . $bad['at'];
        echo "\n  BAD          do not use this package{$at}{$by}: {$reason}\n";
    }
    if (\is_array($report['unmaintained'])) {
        $at = $report['unmaintained']['at'] === null ? '' : ' since ' . $report['unmaintained']['at'];
        $note = $report['unmaintained']['note'] === null ? '' : ': ' . $report['unmaintained']['note'];
        echo "\n  UNMAINTAINED no longer maintained{$at}{$note}\n";
    }

    \printf("\n%s\n", $log->describe());
}
