<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Cache;

use function Moggi\Cache\clearArea;
use function Moggi\Cache\removeTree;
use function Moggi\Cache\treeSize;
use function Moggi\Registry\userCacheDir;

/**
 * `moggi cache info` / `moggi cache clear` — the two questions a cache has to
 * answer: what is in it, and how to get the space back.
 *
 * The cache holds several kinds of thing and they do not cost the same to lose,
 * so `clear` names one area at a time. `downloads` is the odd one out: it lives in
 * the *user* cache, shared by every project on the machine, so it is reachable
 * only by name for a project's own clear.
 */
function cacheUsage(): string
{
    return <<<HELP
    usage:
      moggi cache info
                   what is in the cache: the root, the compiler fingerprint it
                   was built by, and how much each area holds
      moggi cache clear [<area>]
                   delete one area, or all of them (the default)

    areas:
      compiler     module entries, project artifacts, the docs index and the
                   served site — the next compile rebuilds all of it
      catalog      fetched registry metadata
      packages     unpacked packages
      runtime      artifacts a host tool (maven, composer, nuget) fetched
      test         the test harness's scratch trees
      downloads    the user-level download cache (MOGGI_USER_CACHE), shared by
                   every project on the machine
      all          every area above (the default)
    HELP;
}

function runCache(array $argv): int
{
    $spec = new CommandSpec('cache', cacheUsage(), [], verb: true, positionals: 1);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    $sub = $argv[2] ?? 'info';
    if (!\in_array($sub, ['info', 'clear'], true)) {
        \fwrite(STDERR, "error: unknown cache command `{$sub}` (expected: info | clear)\n\n" . cacheUsage() . "\n");

        return 1;
    }

    try {
        $options = parseArgs($argv, $spec, 3);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    return $sub === 'info' ? cacheInfo($options) : cacheClear($options);
}

/** @param array<string, mixed> $options */
function cacheInfo(array $options): int
{
    $info = Cache\info();
    \printf("cache:       %s\n", $info['enabled'] ? 'enabled' : 'disabled (MOGGI_NO_CACHE)');
    \printf("cache dir:   %s\n", $info['cache']);
    \printf(
        "fingerprint: %s (%s)\n",
        $info['fingerprint'],
        $info['match'] ? 'matches the cache on disk' : 'the cache on disk was built by another compiler',
    );

    echo "\n";
    foreach ($info['areas'] as $name => $size) {
        \printf("  %-10s %6d file%s  %8.1f MB\n", $name, $size['entries'], $size['entries'] === 1 ? ' ' : 's', $size['bytes'] / 1048576);
    }
    \printf("  %-10s %6d file%s  %8.1f MB\n", 'total', $info['entries'], $info['entries'] === 1 ? ' ' : 's', $info['bytes'] / 1048576);

    $downloads = \is_dir(downloadsDir()) ? treeSize(downloadsDir()) : ['entries' => 0, 'bytes' => 0];
    \printf(
        "\n  %-10s %6d file%s  %8.1f MB  %s\n",
        'downloads',
        $downloads['entries'],
        $downloads['entries'] === 1 ? ' ' : 's',
        $downloads['bytes'] / 1048576,
        downloadsDir(),
    );

    return 0;
}

/** @param array<string, mixed> $options */
function cacheClear(array $options): int
{
    $area = $options['positionals'][0] ?? 'all';

    try {
        $removed = match ($area) {
            'downloads' => clearDownloads(),
            'all' => clearArea('all') + clearDownloads(),
            default => clearArea($area),
        };
    } catch (\InvalidArgumentException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n\n" . cacheUsage() . "\n");

        return 1;
    }

    echo 'cleared ' . $removed . ' file(s) from ' . ($area === 'all' ? 'the cache' : "cache area `{$area}`") . "\n";

    return 0;
}

/** The user-level content cache: `$XDG_CACHE_HOME/moggi/downloads`, or MOGGI_USER_CACHE. */
function downloadsDir(): string
{
    return userCacheDir() . '/downloads';
}

function clearDownloads(): int
{
    return removeTree(downloadsDir());
}



