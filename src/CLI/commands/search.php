<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Registry\FetchLog;

use function Moggi\Registry\badPackageMarker;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\unmaintainedMarker;

/**
 * `moggi search` — packages whose name or description matches a term.
 *
 * Catalog-only, like `show`: the shard carries the name, the newest version and
 * the description, so a search needs no package file. It reads every shard, which
 * is the one read proportional to the catalog's size, and prints the `bad` marker
 * beside anything the registry has flagged — the reason a search result must not
 * be a bare name.
 */
function searchUsage(): string
{
    return <<<HELP
    usage:
      moggi search <term> [options]

    List the packages whose name or description contains the term, newest version
    and description included, and flag any the registry has marked bad or
    unmaintained. Reads the catalog only — no package file is fetched.

    options:
      --registry URL|DIR   registry to search (default: MOGGI_REGISTRY)
      --no-cache           re-fetch metadata instead of using what is held
      --json               machine-readable envelope
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runSearchCommand(array $argv): int
{
    $spec = new CommandSpec('search', searchUsage(), [
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

    $term = \trim((string) $options['path']);
    if ($term === '' || $term === '.') {
        \fwrite(STDERR, "error: `moggi search` needs a term\n\n" . searchUsage() . "\n");

        return 1;
    }

    try {
        $log = new FetchLog();
        $catalog = loadVerifiedCatalog($options['registry'], !$options['noCache'], $log);
        $matches = searchCatalog($catalog->packages(), $term);

        if ($options['json']) {
            echo prettyJson([
                'term' => $term,
                'registry' => (string) $options['registry'],
                'matches' => $matches,
            ]) . "\n";

            return 0;
        }

        reportSearch($term, $matches, (string) $options['registry'], $log);

        return 0;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * The packages whose name or description contains the term, case-insensitively,
 * in name order. Each row carries the newest version, the description and the bad
 * marker, so a caller never has to fetch the package to know it is refused.
 *
 * @param array<string, array<string, mixed>> $packages name => catalog entry
 * @return list<array{name: string, latest: ?string, description: ?string, bad: ?array{reason: string, at: ?string, by: ?string}, unmaintained: ?array{note: ?string, at: ?string}}>
 */
function searchCatalog(array $packages, string $term): array
{
    $needle = \strtolower($term);
    $matches = [];
    foreach ($packages as $name => $entry) {
        $name = (string) $name;
        $entry = (array) $entry;
        $description = isset($entry['description']) ? (string) $entry['description'] : '';
        if (!\str_contains(\strtolower($name), $needle) && !\str_contains(\strtolower($description), $needle)) {
            continue;
        }
        $matches[$name] = [
            'name' => $name,
            'latest' => isset($entry['latest']) ? (string) $entry['latest'] : null,
            'description' => $description === '' ? null : $description,
            'bad' => badPackageMarker($entry),
            'unmaintained' => unmaintainedMarker($entry),
        ];
    }
    \ksort($matches, \SORT_STRING);

    return \array_values($matches);
}

/**
 * @param list<array{name: string, latest: ?string, description: ?string, bad: ?array{reason: string, at: ?string, by: ?string}, unmaintained: ?array{note: ?string, at: ?string}}> $matches
 */
function reportSearch(string $term, array $matches, string $registry, FetchLog $log): void
{
    \printf("%s — %d match%s for `%s`\n", $registry, \count($matches), \count($matches) === 1 ? '' : 'es', $term);
    \printf("requests   %s\n", $log->describe());
    if ($matches === []) {
        echo "\nno package matches\n";

        return;
    }

    echo "\n";
    foreach ($matches as $row) {
        $flags = '';
        if ($row['bad'] !== null) {
            $flags .= '  BAD: ' . $row['bad']['reason'];
        }
        if ($row['unmaintained'] !== null) {
            $flags .= '  UNMAINTAINED' . ($row['unmaintained']['note'] === null ? '' : ': ' . $row['unmaintained']['note']);
        }
        \printf("  %-20s %-10s %s%s\n", $row['name'], $row['latest'] ?? '—', $row['description'] ?? '', $flags);
    }
}
