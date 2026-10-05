<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Backend\implementedBackendIds;
use function Moggi\Modules\cachedModuleHeader;
use function Moggi\Modules\findMogFilesUnder;
use function Moggi\Modules\moduleFilenameWarning;
use function Moggi\Registry\descriptorProblems;
use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\lockPath;
use function Moggi\Registry\parsePhpExtensionEntry;
use function Moggi\Registry\phpExtensionDescriptors;
use function Moggi\Registry\phpExtensionProblems;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\readLock;

/**
 * `moggi check` — everything that can be wrong with a package, before a build
 * says so one mistake at a time.
 *
 * It is deliberately an *offline* command: the descriptor is checked for its own
 * sake, the sources against the layout the descriptor promises, and the lock
 * against both. Whether the registry agrees is `moggi verify`'s question, and it
 * needs the network.
 *
 * The descriptor half is not re-implemented here. `descriptorProblems()` in
 * `src/registry/descriptor.php` is the format — the parser refuses a descriptor it
 * rejects, and this command prints the same list — so there is exactly one idea of
 * what a descriptor may say.
 *
 * Two kinds of finding:
 *
 *   error    the package is wrong — a source-dir that is not there, a module
 *            defined twice, an executable whose `main` is missing, a dependency
 *            the lock does not have. Exit status 1.
 *   warning  the package is fine, but this machine or this setup would trip over
 *            it — a PHP extension the running PHP lacks, a lock written for an
 *            older version of the package.
 */

function checkUsage(): string
{
    return <<<HELP
    usage:
      moggi check [<dir|<name>.moggi>] [options]

    Check the package for common mistakes, without touching the network: the
    descriptor against the format it is written in, the source-dirs and modules [lib],
    [executable], [test-suite] and [docs] promise, and the dependencies against
    the lock. Errors exit 1.

    options:
      --json               machine-readable findings
      -o, --output FILE    lock file to check against (default: moggi.lock)
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runCheckCommand(array $argv): int
{
    foreach (\array_slice($argv, 2) as $argument) {
        if ($argument === 'help' || $argument === '--help' || $argument === '-h') {
            echo checkUsage() . "\n";

            return 0;
        }
    }

    foreach (\array_slice($argv, 2) as $argument) {
        if ($argument === '-r' || $argument === '--registry' || \str_starts_with($argument, '--registry=')) {
            \fwrite(STDERR, "error: `check` does not talk to a registry — use `moggi verify` for that\n\n" . checkUsage() . "\n");

            return 1;
        }
    }

    try {
        $options = packagingOptions($argv, ['json'], []);
    } catch (\InvalidArgumentException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n\n" . checkUsage() . "\n");

        return 1;
    }

    try {
        $report = checkPackage($options['path'], $options['output']);
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }

    if ($options['json']) {
        echo prettyJson($report) . "\n";

        return $report['ok'] ? 0 : 1;
    }

    reportCheck($report);

    return $report['ok'] ? 0 : 1;
}

/**
 * The whole check, as data.
 *
 * A descriptor the parser rejects is reported and then left alone — the rest of
 * the checks all read it, so there is nothing to check the sources or the lock
 * against until it is fixed. The list of problems is the parser's, so a package
 * `check` passes is a package `readDescriptor` accepts.
 *
 * @return array<string, mixed>
 */
function checkPackage(string $path, ?string $output = null): array
{
    $descriptorPath = findDescriptor($path);
    $root = \dirname(\realpath($descriptorPath) ?: $descriptorPath);

    $problems = descriptorProblems($descriptorPath);
    $findings = [];
    foreach ($problems as $problem) {
        $findings[] = ['level' => 'error', 'message' => $problem];
    }

    $notes = [];
    $name = \basename($root);
    $version = null;
    if ($problems === []) {
        $descriptor = readDescriptor($descriptorPath);
        $name = $descriptor['name'];
        $version = $descriptor['version'];

        $sources = checkSources($root, $descriptor);
        $findings = [...$findings, ...$sources['findings']];
        $notes = $sources['notes'];

        $findings = [...$findings, ...checkLock($descriptorPath, $descriptor, $output)];
        $findings = [...$findings, ...checkPhpRequirements($descriptor, readCheckLock($descriptorPath, $output))];
        if ($descriptor['php']['version'] !== null || $descriptor['php']['extensions'] !== []) {
            $notes['php'] = ($descriptor['php']['version'] ?? 'any version')
                . ($descriptor['php']['extensions'] === [] ? '' : ' — ' . \implode(', ', $descriptor['php']['extensions']));
        }
    }

    $errors = \array_values(\array_filter($findings, static fn (array $f): bool => $f['level'] === 'error'));
    $warnings = \array_values(\array_filter($findings, static fn (array $f): bool => $f['level'] !== 'error'));

    return [
        'ok' => $errors === [],
        'name' => $name,
        'version' => $version,
        'root' => $root,
        'descriptor' => $descriptorPath,
        'notes' => $notes,
        'findings' => [...$errors, ...$warnings],
        'errors' => \count($errors),
        'warnings' => \count($warnings),
    ];
}

/**
 * The layout the descriptor promises: every source-dir exists, every module in
 * them names itself consistently, no module is defined twice, and an executable
 * or test-suite's `main` is actually one of its modules.
 *
 * A file with no module header reads as the synthesized `module Main(main)
 * where`, which is reported as a naming warning; for such a file the filename
 * warning is skipped, since the missing header is the finding. That header
 * warning is itself skipped when the file is the `main` of its executable or
 * test-suite, an entry that is meant to be a synthesized `Main`.
 *
 * `main` names a module, and the file for a name lives at its path under the
 * block's source-dirs (`Foo.Bar` → `Foo/Bar.mog`), the same rule `build` uses. A
 * `main` with no such file is reported, and a file that declares a different
 * module than the descriptor names is reported by both names.
 *
 * @param array<string, mixed> $descriptor
 * @return array{findings: list<array{level: string, message: string}>, notes: array<string, string>}
 */
function checkSources(string $root, array $descriptor): array
{
    $findings = [];
    $modules = [];
    $scanned = [];

    $owners = [];
    if ($descriptor['lib']['sourceDirs'] !== []) {
        $owners['lib'] = ['dirs' => $descriptor['lib']['sourceDirs'], 'main' => null, 'backend' => null];
    }
    foreach (['executable' => $descriptor['executables'], 'test-suite' => $descriptor['testSuites']] as $section => $blocks) {
        foreach ($blocks as $id => $block) {
            $label = $id === '' ? $section : "{$section}.{$id}";
            $owners[$label] = ['dirs' => $block['sourceDirs'], 'main' => $block['main'], 'backend' => $block['backend']];

            if ($block['main'] === '') {
                $findings[] = ['level' => 'error', 'message' => "[{$label}] requires `main`"];
            }
            if ($block['sourceDirs'] === []) {
                $findings[] = ['level' => 'error', 'message' => "[{$label}] requires `source-dirs`"];
            }
        }
    }

    foreach ($owners as $label => $owner) {
        foreach ($owner['dirs'] as $directory) {
            $relative = \str_replace('\\', '/', $directory);
            if (\str_starts_with($relative, '/') || \preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
                $findings[] = ['level' => 'error', 'message' => "[{$label}] source-dirs `{$directory}` is outside the package"];
                continue;
            }
            $full = $root . '/' . \rtrim($directory, '/');
            if (!\is_dir($full)) {
                $findings[] = ['level' => 'error', 'message' => "[{$label}] source-dirs `{$directory}` is not a directory"];
                continue;
            }
            $scanned[\rtrim($relative, '/')] = true;

            foreach (findMogFilesUnder($full) as $file) {
                $header = cachedModuleHeader($file);
                $name = $header['module'] ?? null;
                if (!\is_string($name) || $name === '') {
                    continue;
                }
                if (($header['implicitMain'] ?? false) === true && $owner['main'] !== $name) {
                    $findings[] = ['level' => 'warning', 'message' => checkRelative($root, $file) . ': no module header, so the compiler reads it as `module Main` — name it if anything should import it'];
                } else if (($header['implicitMain'] ?? false) !== true) {
                    $warning = moduleFilenameWarning($file, $name);
                    if ($warning !== null) {
                        $findings[] = ['level' => 'warning', 'message' => $warning];
                    }
                }
                if (isset($modules[$name]) && $modules[$name] !== $file) {
                    $findings[] = ['level' => 'error', 'message' => "module `{$name}` is defined twice: " . checkRelative($root, $modules[$name]) . ' and ' . checkRelative($root, $file)];
                    continue;
                }
                $modules[$name] = $file;
            }
        }
    }

    foreach ($owners as $label => $owner) {
        if ($owner['main'] === null || $owner['main'] === '') {
            continue;
        }
        $mainFile = null;
        foreach ($owner['dirs'] as $directory) {
            $candidate = \rtrim($root, '/') . '/' . \trim($directory, '/') . '/' . \str_replace('.', '/', $owner['main']) . '.mog';
            if (\is_file($candidate)) {
                $mainFile = $candidate;

                break;
            }
        }
        if ($mainFile === null) {
            if (isset($modules[$owner['main']])) {
                $findings[] = ['level' => 'warning', 'message' => "[{$label}] main module `{$owner['main']}` is not under this block's source-dirs (" . \implode(', ', $owner['dirs']) . ')'];
            } else {
                $findings[] = ['level' => 'error', 'message' => "[{$label}] main module `{$owner['main']}` has no file `" . \str_replace('.', '/', $owner['main']) . ".mog` under " . (\implode(', ', $owner['dirs']) ?: 'any source-dir')];
            }
        } else {
            $declared = cachedModuleHeader($mainFile)['module'] ?? null;
            if ($declared !== $owner['main']) {
                $findings[] = ['level' => 'error', 'message' => "[{$label}] entry file " . checkRelative($root, $mainFile) . ' declares module `' . (string) $declared . "`, but `main = {$owner['main']}`"];
            }
        }

        $backend = $owner['backend'];
        if ($backend === null || $backend === '') {
            continue;
        }
        if (!\in_array($backend, implementedBackendIds(), true)) {
            $findings[] = ['level' => 'error', 'message' => "[{$label}] backend `{$backend}` is not one this compiler builds (" . \implode(', ', implementedBackendIds()) . ')'];
        } elseif (!\str_starts_with($label, 'test-suite') && !\in_array($backend, $descriptor['backends'], true)) {
            $findings[] = ['level' => 'warning', 'message' => "[{$label}] backend `{$backend}` is not in [package] backends (" . \implode(', ', $descriptor['backends']) . ')'];
        }
    }

    $targetDir = $descriptor['docs']['targetDir'];
    if ($targetDir !== null && !\is_dir($root . '/' . \rtrim($targetDir, '/'))) {
        $findings[] = ['level' => 'warning', 'message' => "[docs] target-dir `{$targetDir}` does not exist yet"];
    }

    $notes = [];
    if ($scanned !== []) {
        $notes['sources'] = \implode(', ', \array_keys($scanned)) . ' — ' . \count($modules) . ' module' . (\count($modules) === 1 ? '' : 's');
    }
    if ($descriptor['backends'] !== [] && $descriptor['backends'] !== implementedBackendIds()) {
        $notes['backends'] = \implode(', ', $descriptor['backends']);
    }
    if ($targetDir !== null) {
        $notes['docs'] = $targetDir;
    }

    return ['findings' => $findings, 'notes' => $notes];
}

/**
 * The descriptor against the lock.
 *
 * The dependency constraints themselves are the descriptor's business and are
 * already checked; what is checked here is the one thing only the lock can be
 * wrong about — a dependency the lock does not name, which turns a working
 * `build` into a two-command mystery, and a lock written for another version of
 * the package.
 *
 * @param array<string, mixed> $descriptor
 * @return list<array{level: string, message: string}>
 */
function checkLock(string $descriptorPath, array $descriptor, ?string $output): array
{
    $findings = [];
    $lockFile = lockPath($descriptorPath, $output);

    if (!\is_file($lockFile)) {
        if ($descriptor['dependencies'] !== []) {
            $findings[] = ['level' => 'warning', 'message' => "no lock yet ({$lockFile}) — run `moggi install`"];
        }

        return $findings;
    }

    try {
        $lock = readLock($lockFile);
    } catch (\RuntimeException $error) {
        $findings[] = ['level' => 'error', 'message' => $error->getMessage()];

        return $findings;
    }
    if ($lock === null) {
        return $findings;
    }

    $locked = (array) ($lock['packages'] ?? []);
    $root = (array) ($lock['root'] ?? []);
    if (isset($root['name']) && (string) $root['name'] !== $descriptor['name']) {
        $findings[] = ['level' => 'error', 'message' => "{$lockFile} is the lock of `{$root['name']}`, not `{$descriptor['name']}`"];
    }
    if (isset($root['version']) && (string) $root['version'] !== $descriptor['version']) {
        $findings[] = ['level' => 'warning', 'message' => "{$lockFile} was written for version {$root['version']}, the descriptor says {$descriptor['version']} — run `moggi update`"];
    }

    foreach (\array_keys($descriptor['dependencies']) as $name) {
        if (!isset($locked[$name])) {
            $findings[] = ['level' => 'error', 'message' => "[dependencies] `{$name}` is not in {$lockFile} — run `moggi install`"];
        }
    }

    return $findings;
}

/**
 * The lock `check` should read for the extension closure, or null when there is
 * none to read.
 *
 * `checkLock` reports a lock it cannot read as a finding; this only needs the
 * lock's package list, so an unreadable one contributes nothing here rather than
 * being announced twice.
 *
 * @return ?array<string, mixed>
 */
function readCheckLock(string $descriptorPath, ?string $output): ?array
{
    try {
        return readLock(lockPath($descriptorPath, $output));
    } catch (\RuntimeException) {
        return null;
    }
}

/**
 * The PHP the descriptor asks for, against the PHP running the check.
 *
 * Warnings rather than errors: a requirement is not wrong for being unmet here,
 * and this command is meant to be safe to run on any machine. It is still the
 * first thing worth knowing, since `[php]` is the one part of a descriptor no
 * package manager can satisfy for you.
 *
 * The extension half is gathered across the whole closure — this package and
 * every installed package the lock names — and reported against both runtimes a
 * build could use: the PHP running this, and the micro runtime a `--native`
 * build appends the PHAR to.
 *
 * Entries follow the descriptor's own syntax: `foo`, `?foo` (optional),
 * `foo^1.2` (a version), `foo=path/to/lib` (built against a path).
 *
 * @param array<string, mixed> $descriptor
 * @param ?array<string, mixed> $lock
 * @return list<array{level: string, message: string}>
 */
function checkPhpRequirements(array $descriptor, ?array $lock = null): array
{
    $findings = [];
    $declared = $descriptor['php']['version'];

    if ($declared !== null && $declared !== '' && \version_compare(\PHP_VERSION, $declared, '<')) {
        $findings[] = ['level' => 'warning', 'message' => "[php] version = {$declared} needs PHP {$declared} or newer; this is " . \PHP_VERSION];
    }

    foreach ($descriptor['php']['extensions'] as $entry) {
        $parsed = parsePhpExtensionEntry($entry);
        if ($parsed['path'] === null) {
            continue;
        }
        if (!\file_exists($parsed['path']) && !\file_exists(\dirname((string) $descriptor['path']) . '/' . $parsed['path'])) {
            $findings[] = ['level' => 'warning', 'message' => "[php] extension `{$entry}` is built against `{$parsed['path']}`, which is not there"];
        }
    }

    if (\in_array('php', (array) $descriptor['backends'], true) || $descriptor['backends'] === []) {
        foreach (phpExtensionProblems(phpExtensionDescriptors($descriptor, $lock ?? []))['problems'] as $message) {
            $findings[] = ['level' => 'warning', 'message' => $message];
        }
    }

    return $findings;
}

/** A path as the package sees it, so a report names the file, not the machine. */
function checkRelative(string $root, string $path): string
{
    $normalized = \str_replace('\\', '/', $path);
    $prefix = \rtrim(\str_replace('\\', '/', $root), '/') . '/';

    return \str_starts_with($normalized, $prefix) ? \substr($normalized, \strlen($prefix)) : $normalized;
}

/** @param array<string, mixed> $report */
function reportCheck(array $report): void
{
    $version = $report['version'] ?? null;
    \printf("%s%s — %s\n", (string) $report['name'], $version === null ? '' : " {$version}", (string) $report['root']);
    \printf("  %-10s %s\n", 'descriptor', (string) $report['descriptor']);
    foreach ((array) $report['notes'] as $label => $value) {
        \printf("  %-10s %s\n", (string) $label, (string) $value);
    }

    if ($report['findings'] === []) {
        echo "\nno problems found\n";

        return;
    }

    echo "\n";
    foreach ($report['findings'] as $finding) {
        \printf("  %-8s %s\n", $finding['level'], $finding['message']);
    }

    $parts = [];
    if ($report['errors'] > 0) {
        $parts[] = $report['errors'] . ' error' . ($report['errors'] === 1 ? '' : 's');
    }
    if ($report['warnings'] > 0) {
        $parts[] = $report['warnings'] . ' warning' . ($report['warnings'] === 1 ? '' : 's');
    }
    echo "\n" . \implode(', ', $parts) . "\n";
}
