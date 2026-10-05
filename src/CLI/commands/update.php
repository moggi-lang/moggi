<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Registry\FetchLog;

use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\lockPath;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\readLock;
use function Moggi\Registry\shortNpub;

/**
 * `moggi update` — the same resolution as `install`, but allowed to move, and the
 * only command that rewrites a lock on purpose. So it is the one that reports
 * *what* moved; `install` and `build` refuse a stale lock precisely so that
 * moving a version is something a person asks for.
 *
 * Selecting one package is deliberately not offered: the descriptor pins a floor
 * per dependency, so `update json` could only mean the same thing as `update`.
 */
function updateUsage(): string
{
    $default = DEFAULT_REGISTRY;

    return <<<HELP
    usage:
      moggi update [<dir|<name>.moggi>] [options]

    Re-resolve every dependency to the newest version the descriptor allows and
    rewrite moggi.lock, reporting what changed.

    options:
      --registry URL|DIR   registry to resolve against
                           (default: MOGGI_REGISTRY, else {$default})
      -o, --output FILE    lock file to rewrite (default: moggi.lock)
      --dry-run            report the changes without writing the lock
      --no-cache           re-fetch metadata instead of using what is held
      --json               machine-readable envelope
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runUpdateCommand(array $argv): int
{
    foreach (\array_slice($argv, 2) as $argument) {
        if ($argument === 'help' || $argument === '--help' || $argument === '-h') {
            echo updateUsage() . "\n";

            return 0;
        }
    }

    try {
        $options = packagingOptions($argv, ['dryRun', 'json', 'noCache'], []);
    } catch (\InvalidArgumentException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n\n" . updateUsage() . "\n");

        return 1;
    }

    try {
        $descriptorPath = findDescriptor($options['path']);
        $descriptor = readDescriptor($descriptorPath);

        $log = new FetchLog();
        $catalog = loadVerifiedCatalog($options['registry'], !$options['noCache'], $log);

        $lockFile = lockPath($descriptorPath, $options['output']);
        $before = readLock($lockFile);
        $resolution = writeResolution($descriptor, $descriptorPath, $options['registry'], $catalog, $options['output'], (bool) $options['dryRun']);

        $changes = lockChanges($before, $resolution['chosen']);

        if ($options['json']) {
            printJsonEnvelope($resolution['document'], ['changes' => $changes, 'requests' => $log->requests, 'held' => $log->held]);

            return 0;
        }

        \printf("%s %s — %d dependenc%s\n", $descriptor['name'], $descriptor['version'], \count($resolution['chosen']), \count($resolution['chosen']) === 1 ? 'y' : 'ies');
        \printf("registry   %s\n", shortNpub((string) ($catalog->npub() ?? '—')));
        \printf("requests   %s\n", $log->describe());

        if ($changes === []) {
            echo "\nthe lock is already at the newest versions\n";
        } else {
            echo "\n";
            foreach ($changes as $change) {
                \printf("  %-20s %s\n", $change['name'], $change['detail']);
            }
        }

        echo "\n" . ($options['dryRun'] ? 'not written (--dry-run): ' : 'wrote ') . $resolution['lockFile'] . "\n";

        return 0;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * What a re-resolution moved, in the order it will be read.
 *
 * @param ?array<string, mixed> $before the lock as it was
 * @param array<string, string> $after the versions just resolved
 * @return list<array{name: string, detail: string}>
 */
function lockChanges(?array $before, array $after): array
{
    $was = [];
    foreach (($before['packages'] ?? []) as $name => $entry) {
        $was[$name] = (string) ($entry['version'] ?? '');
    }

    $changes = [];
    foreach ($after as $name => $version) {
        if (!isset($was[$name])) {
            $changes[] = ['name' => $name, 'detail' => "added {$version}"];
        } elseif ($was[$name] !== $version) {
            $changes[] = ['name' => $name, 'detail' => "{$was[$name]} -> {$version}"];
        }
    }
    foreach ($was as $name => $version) {
        if (!isset($after[$name])) {
            $changes[] = ['name' => $name, 'detail' => "removed (was {$version})"];
        }
    }

    return $changes;
}
