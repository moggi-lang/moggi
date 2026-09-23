<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Cache;

function cacheUsage(): string
{
    return <<<HELP
    usage:
      moggi cache [info]
                   show the cache root, the current compiler fingerprint and the
                   total size of the cache
      moggi cache clear
                   delete the cache (it is rebuilt by the next compile)
    HELP;
}

function runCache(array $argv): int
{
    $sub = $argv[2] ?? 'info';

    // `--help` wins wherever it appears: `moggi cache clear --help` must never
    // reach the destructive path.
    foreach (\array_slice($argv, 2) as $arg) {
        if ($arg === 'help' || $arg === '--help' || $arg === '-h') {
            echo cacheUsage() . "\n";

            return 0;
        }
    }

    if ($sub === 'info') {
        return cacheInfo($argv);
    }

    if ($sub === 'clear') {
        return cacheClear($argv);
    }

    \fwrite(STDERR, "error: unknown cache command `{$sub}` (expected: info | clear)\n\n" . cacheUsage() . "\n");

    return 1;
}

/** @param list<string> $argv */
function cacheInfo(array $argv): int
{
    $unknown = unknownCacheFlags(\array_slice($argv, 3), []);
    if ($unknown !== []) {
        return cacheFlagError('info', $unknown);
    }

    $info = Cache\info();
    $status = $info['enabled'] ? 'enabled' : 'disabled (MOGGI_NO_CACHE)';
    \printf("cache:       %s\n", $status);
    \printf("cache dir:   %s\n", $info['cache']);
    \printf("fingerprint: %s\n", $info['fingerprint']);
    \printf("entries:     %d\n", $info['entries']);
    \printf("size:        %.1f MB\n", $info['bytes'] / 1048576);

    return 0;
}

/** @param list<string> $argv */
function cacheClear(array $argv): int
{
    $unknown = unknownCacheFlags(\array_slice($argv, 3), []);
    if ($unknown !== []) {
        return cacheFlagError('clear', $unknown);
    }

    $removed = Cache\clear();
    echo "cleared {$removed} cache file(s)\n";

    return 0;
}

/**
 * Flags in `$args` that this subcommand does not accept.
 *
 * @param list<string> $args
 * @param list<string> $allowed
 * @return list<string>
 */
function unknownCacheFlags(array $args, array $allowed): array
{
    $unknown = [];
    foreach ($args as $arg) {
        if (!str_starts_with($arg, '-')) {
            continue;
        }
        $name = str_contains($arg, '=') ? substr($arg, 0, (int) strpos($arg, '=')) : $arg;
        if (!\in_array($name, $allowed, true)) {
            $unknown[] = $arg;
        }
    }

    return $unknown;
}

/** @param list<string> $unknown */
function cacheFlagError(string $sub, array $unknown): int
{
    \fwrite(
        STDERR,
        'error: unknown option ' . \implode(' ', $unknown) . " for `moggi cache {$sub}`\n\n",
    );

    return 1;
}

function runCacheCommand(array $argv): int
{
    return runCache($argv);
}
