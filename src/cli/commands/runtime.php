<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Backend\DotNet\dotnetCliEnv;
use function Moggi\Backend\DotNet\resolveDotnetExecutable;
use function Moggi\Backend\Php\Portable\buildPortableRuntime;
use function Moggi\Backend\Php\Portable\copyPortableTree;
use function Moggi\Backend\Php\Portable\directorySize;
use function Moggi\Backend\Php\Portable\portableRuntimeHome;
use function Moggi\Backend\Php\Store\removeSourceTree;
use function Moggi\Backend\implementedBackendIds;
use function Moggi\Compiler\compilerVersion;
use function Moggi\Compiler\distributionVariant;
use function Moggi\Compiler\findExecutable;
use function Moggi\Install\installationRoot;
use const Moggi\Backend\Php\Host\HOST_ENV;
use function Moggi\Backend\Php\Host\phpHost;
use function Moggi\Backend\Php\Host\phpHostAt;
use function Moggi\Backend\Php\Host\phpHostBinary;
use function Moggi\Backend\Php\Store\buildExtensionFromSource;
use function Moggi\Backend\Php\Store\builtExtensionFile;
use function Moggi\Backend\Php\Store\cachedNativeRuntimePath;
use function Moggi\Backend\Php\Store\declaredExtensions;
use function Moggi\Backend\Php\Store\disableExtension;
use function Moggi\Backend\Php\Store\enableExtension;
use function Moggi\Backend\Php\Store\extensionDir;
use function Moggi\Backend\Php\Store\extensionFileName;
use function Moggi\Backend\Php\Store\extensionLoads;
use function Moggi\Backend\Php\Store\forgetExtension;
use function Moggi\Backend\Php\Store\hostAbi;
use function Moggi\Backend\Php\Store\lockProblems;
use function Moggi\Backend\Php\Store\manifest;
use function Moggi\Backend\Php\Store\missingBuildTools;
use function Moggi\Backend\Php\Store\phpBinary;
use function Moggi\Backend\Php\Store\phpConfig;
use function Moggi\Backend\Php\Store\phpHome;
use function Moggi\Backend\Php\Store\phpIniPath;
use function Moggi\Backend\Php\Store\placeExtension;
use function Moggi\Backend\Php\Store\pieCommand;
use function Moggi\Backend\Php\Store\recordExtension;
use function Moggi\Backend\Php\Store\resolvePie;
use function Moggi\Backend\Php\Store\writeProjectLock;
use function Moggi\Backend\Php\resolveMicroSfx;
use function Moggi\Compiler\runProcess;
use function Moggi\Registry\normalizeExtensionName;
use function Moggi\Registry\runtimeExtensionsAt;

/**
 * `moggi runtime` — the runtimes a compiled program runs on, and what they carry.
 *
 * Moggi programmers are not necessarily PHP programmers, so this group is the
 * only place PHP-shaped words appear: it says what runtime the installation
 * runs programs on, what an extension is, and what the one command to run is.
 * Nothing here requires `php-config`, `phpize`, `pie` or an ini path to be
 * known — those are how the command works, not what a person types.
 *
 * The runtime is the noun and the backend is the qualifier: `runtime php` runs a
 * PHAR, `runtime jvm` a jar, `runtime dotnet` a dll. `runtime list` says which of
 * them this installation can run programs on and where each tool came from,
 * `runtime <backend> version` reports what was found. A runtime is
 * prepared once — its PHP, its extension set, its headers — and then used as is:
 * no PHP installed on the machine, no build tools, nothing a program needs left
 * to the host.
 *
 * An extension is *declared* by the project (`[php.extensions]` in its
 * descriptor), *installed* into the bundled PHP by
 * `moggi runtime php install-ext`, and *carried* by a `--native` runtime when
 * that runtime was built with it. This command shows all three, because "why
 * does my program say the function does not exist" is nearly always one of them
 * disagreeing with the others.
 */

/**
 * Where a release publishes its archives: `<base>/<tag>/<archive>`, with the
 * digests beside them in `<base>/<tag>/SHA256SUMS`.
 */
const RUNTIME_RELEASE_BASE = 'https://github.com/moggi-lang/moggi/releases/download';

/** The variable that moves that base, for a mirror or a local release host. */
const RUNTIME_RELEASE_BASE_ENV = 'MOGGI_RUNTIME_RELEASE_BASE';

/**
 * The base a release archive is fetched from: the published one, or the mirror
 * `MOGGI_RUNTIME_RELEASE_BASE` names.
 *
 * The override is the same layout in a different place, nothing more: the tag and
 * the archive name are still derived from this installation, so a mirror cannot
 * make it install something this version and platform did not name.
 */
function runtimeReleaseBase(): string
{
    $named = \getenv(RUNTIME_RELEASE_BASE_ENV);
    if (\is_string($named) && $named !== '') {
        return \rtrim($named, '/');
    }

    return RUNTIME_RELEASE_BASE;
}

function runtimeUsage(): string
{
    return <<<HELP
    usage:
      moggi runtime list [--json]     what this installation can run programs on
      moggi runtime <backend>         one backend's runtime, as `list` reports it
      moggi runtime <backend> version [--json]
                                      its version, its path, and the tools it brings

      moggi runtime php list-ext      declared vs installed vs carried
      moggi runtime php install-ext <name>   install an extension into the bundled PHP
      moggi runtime php remove-ext <name>    stop loading it (--purge deletes the file)
      moggi runtime php verify        check the declared set against a PHP
      moggi runtime php build         copy this runtime into a portable, self-contained tree
      moggi runtime php native        use/check a native runtime carrying this set

    A runtime is what a compiled program runs on: `php` runs a PHAR, `jvm` a jar,
    `dotnet` a dll. `runtime list` says which of them this installation can run
    programs on and where each came from — bundled in `runtime/`, or found on
    `PATH`. A backend with no runtime still compiles; nothing it builds runs.

    `runtime php` carries the PHP that does the running, and the extensions a
    program calls through `foreign php`; preparing it here is what makes the
    result portable — the archive runs under it, and nothing else has to be
    installed.

    An extension is a compiled PHP module — `curl`, `sqlite3`, `redis`. It has to
    be installed before the compiler can check those calls, and before the
    program can run.

    `--version` picks a release when PIE resolves one; `--from` builds an
    extension source tree you already have, with this runtime's own phpize.

    `build` writes a runtime you can copy anywhere: its own libraries, its own
    `php.ini`, no PHP and no libraries from the machine it was prepared on. The
    copy is cached by version, platform and extension set, so the same set is
    prepared once; `--out DIR` also puts it where you want to ship it from. Run
    it through `bin/php` on Linux, or `bin\php.cmd` on Windows.
    HELP;
}

function runtimePhpUsage(): string
{
    return <<<HELP
    usage:
      moggi runtime php list-ext
      moggi runtime php install-ext <name> [--version VERSION] [--from SOURCE-DIR]
      moggi runtime php remove-ext <name> [--purge]
      moggi runtime php verify [--php PATH]
      moggi runtime php build [--php PATH] [--out DIR] [--force]
      moggi runtime php native
    HELP;
}

/** @param list<string> $argv */
function runRuntimeCommand(array $argv): int
{
    $sub = $argv[2] ?? null;

    if (($sub === null || $sub === 'help' || \str_starts_with($sub, '-')) && wantsHelp($argv)) {
        echo runtimeUsage() . "\n";

        return 0;
    }
    if ($sub === null || $sub === 'list') {
        if (wantsHelp($argv)) {
            echo runtimeUsage() . "\n";

            return 0;
        }

        return runtimeList($argv);
    }
    if ($sub === 'php') {
        return runRuntimePhpCommand($argv);
    }
    if (\in_array($sub, implementedBackendIds(), true)) {
        return runRuntimeBackendCommand($sub, $argv);
    }

    \fwrite(
        STDERR,
        "error: unknown runtime `{$sub}` (backends: " . \implode(', ', implementedBackendIds()) . ")\n\n" . runtimeUsage() . "\n",
    );

    return 1;
}

/**
 * `moggi runtime php` and its verbs.
 *
 * A `-` in the verb position is `--help` about the runtime, not a verb that
 * happens to be spelled like a flag.
 *
 * @param list<string> $argv
 */
function runRuntimePhpCommand(array $argv): int
{
    $verb = $argv[3] ?? 'status';

    if (\str_starts_with($verb, '-') && wantsHelp($argv)) {
        echo runtimePhpUsage() . "\n";

        return 0;
    }

    return match ($verb) {
        'status' => runtimePhpStatus(),
        'version' => runtimeBackendVersion('php', $argv),
        'list-ext' => runtimePhpListExt(),
        'install-ext' => runtimePhpInstallExt($argv),
        'remove-ext' => runtimePhpRemoveExt($argv),
        'verify' => runtimePhpVerify($argv),
        'build' => runtimePhpBuild($argv),
        'native' => runtimePhpNative(),
        default => (static function () use ($verb): int {
            \fwrite(STDERR, "error: unknown runtime php command `{$verb}`\n\n" . runtimePhpUsage() . "\n");

            return 1;
        })(),
    };
}

/**
 * What this installation runs programs on, and what this project adds to it.
 *
 * A checkout or a variant with no bundled PHP has no runtime to install into,
 * but it still has a PHP to verify against: that is the other shape of an
 * installation, not an error.
 */
function runtimePhpStatus(): int
{
    $home = null;
    try {
        $home = phpHome();
    } catch (\RuntimeException) {
    }

    $declared = declaredExtensions('.');
    $native = resolveMicroSfx();
    $nativeSet = $native === null ? null : runtimeExtensionsAt(\dirname($native));

    if ($home === null) {
        try {
            $host = phpHost();
        } catch (\RuntimeException $error) {
            \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

            return 1;
        }
        \printf("php         %s\n", $host->label());
        \printf("extensions  %d loaded\n", \count($host->extensions()));
        if ($declared !== []) {
            \printf("declared    %s\n", \implode(', ', $declared));
        }
        \printf("native      %s\n", $native === null ? 'no runtime bundled (a --native build needs one)' : $native);
        echo "\nthis installation bundles no PHP to install extensions into;"
            . " use a distribution that bundles one (moggi, moggi-php)\n";

        return 0;
    }

    try {
        $abi = hostAbi($home);
        $record = manifest($home);
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }

    \printf("php         %s (%s%s)\n", phpBinary($home), $abi['version'], $abi['zts'] ? ', thread-safe' : '');
    \printf("ini         %s\n", phpIniPath($home));
    \printf("extensions  %d built in, %d installed\n", \count($record['extensions']), \count($record['installed']));
    if ($record['installed'] !== []) {
        \printf("installed   %s\n", \implode(', ', \array_map(
            static fn (array $entry): string => (string) ($entry['name'] ?? '?') . (isset($entry['version']) && $entry['version'] !== null ? ' ' . $entry['version'] : ''),
            $record['installed'],
        )));
    }
    if ($declared !== []) {
        \printf("declared    %s\n", \implode(', ', $declared));
    }
    if ($native === null) {
        echo "native      no runtime bundled (a --native build needs one)\n";
    } else {
        \printf(
            "native      %s, %s\n",
            $nativeSet === null ? 'records no extension set' : \implode(', ', $nativeSet),
            'used by --native',
        );
    }

    $problems = lockProblems('.', $home, $declared);
    if ($problems !== []) {
        echo "\n";
        foreach ($problems as $problem) {
            echo "  {$problem}\n";
        }
    }

    return 0;
}

function runtimePhpListExt(): int
{
    try {
        $home = phpHome();
        $record = manifest($home);
        $declared = declaredExtensions('.');
        $native = resolveMicroSfx();
        $nativeSet = $native === null ? null : \array_map(normalizeExtensionName(...), runtimeExtensionsAt(\dirname($native)) ?? []);
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }

    $installed = [];
    foreach ($record['extensions'] as $name) {
        $installed[normalizeExtensionName($name)] = $name;
    }

    $rows = [];
    foreach (\array_unique(\array_map(normalizeExtensionName(...), $declared)) as $key) {
        $rows[$key] = true;
    }
    foreach ($installed as $key => $name) {
        $rows[$key] = true;
    }

    if ($rows === []) {
        echo "no extensions declared or installed\n";
        echo "add one with `moggi runtime php install-ext <name>`\n";

        return 0;
    }

    \ksort($rows, \SORT_STRING);
    \printf("  %-22s %-9s %-9s %s\n", 'extension', 'declared', 'installed', 'native');
    foreach (\array_keys($rows) as $key) {
        \printf(
            "  %-22s %-9s %-9s %s\n",
            $installed[$key] ?? $key,
            \in_array($key, \array_map(normalizeExtensionName(...), $declared), true) ? 'yes' : '-',
            isset($installed[$key]) ? 'yes' : '-',
            $nativeSet === null ? 'no runtime' : (\in_array($key, $nativeSet, true) ? 'yes' : 'no'),
        );
    }

    return 0;
}

/** @param list<string> $argv */
function runtimePhpInstallExt(array $argv): int
{
    $spec = new CommandSpec('install-ext', runtimePhpUsage(), [
        ['name' => 'version', 'value' => true],
        ['name' => 'from', 'value' => true],
    ]);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec, 4);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $arg = (string) ($options['positionals'][0] ?? '');
    if ($arg === '') {
        return commandError($spec, new \InvalidArgumentException('name the extension to install, e.g. `moggi runtime php install-ext curl`'));
    }
    $bare = \explode(':', \str_replace('\\', '/', $arg), 2)[0];
    $name = \preg_replace('/[^a-z0-9_]/', '', \strtolower(\basename($bare))) ?? $bare;
    $package = \str_contains($arg, '/') ? $arg : 'pecl/' . $name;
    $version = \is_string($options['version']) ? $options['version'] : null;
    $from = \is_string($options['from']) ? $options['from'] : null;
    if ($version !== null && $from === null) {
        $package .= ':' . $version;
    }

    try {
        $home = phpHome();
        if ($from !== null) {
            echo "building {$name} from {$from} with the bundled PHP (" . hostAbi($home)['version'] . ")\n";
            $built = buildExtensionFromSource($home, $name, $from);
            $source = 'local:' . \rtrim($from, '/');
        } else {
            $pie = resolvePie();
            if ($pie === null) {
                throw new \RuntimeException(
                    'installing an extension needs PIE, the PHP extension installer'
                    . ' — this installation does not ship it; put `pie` on PATH or set MOGGI_PIE to pie.phar,'
                    . ' or build an extension you already have with `--from DIR`',
                );
            }
            $missing = missingBuildTools();
            if ($missing !== []) {
                throw new \RuntimeException(
                    "compiling `{$name}` needs build tools this machine does not have: " . \implode(', ', $missing),
                );
            }

            echo "installing {$name} into the bundled PHP (" . hostAbi($home)['version'] . ")\n";
            $target = \PHP_OS_FAMILY === 'Windows'
                ? ['--with-php-path=' . phpBinary($home)]
                : ['--with-php-config=' . phpConfig($home)];
            $installed = runProcess(
                [
                    ...pieCommand($pie),
                    'install',
                    $package,
                    ...$target,
                    '--skip-enable-extension',
                    '--no-interaction',
                ],
                $home,
                null,
                true,
            );
            if ($installed['exitCode'] !== 0) {
                throw new \RuntimeException(
                    "PIE could not install `{$package}`\n" . \trim($installed['stdout'] . "\n" . $installed['stderr']),
                );
            }

            $built = builtExtensionFile($home, $name);
            if ($built === null) {
                throw new \RuntimeException(
                    "PIE reported success but built no {$name} extension under {$home}"
                    . ' — the extension may be compiled into PHP already, or named differently',
                );
            }
            $source = $package;
        }

        $placed = placeExtension($home, $name, $built);
        enableExtension($home, $name);

        $loads = extensionLoads($home, $name);
        if (! $loads['loaded']) {
            throw new \RuntimeException(
                "the bundled PHP still does not load `{$name}` after installing it"
                . ($loads['stderr'] === '' ? '' : ":\n" . $loads['stderr']),
            );
        }

        recordExtension($home, $name, null, (string) \hash_file('sha256', $placed), $source);
        $declared = declaredExtensions('.');
        if ($declared !== []) {
            $lock = writeProjectLock('.', $home, $declared);
            \printf("  lock        %s\n", $lock);
        }

        \printf("  installed   %s\n", $placed);
        echo "\n";
        if ($declared === [] || ! \in_array(normalizeExtensionName($name), \array_map(normalizeExtensionName(...), $declared), true)) {
            echo "declare it in the project descriptor to make it a build requirement:\n";
            echo "  [php.extensions]\n  {$name} = *\n";
        }
        echo "a --native build does not carry it until `moggi runtime php native` can use a runtime built with it\n";

        return 0;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/** @param list<string> $argv */
function runtimePhpRemoveExt(array $argv): int
{
    $spec = new CommandSpec('remove-ext', runtimePhpUsage(), [
        ['name' => 'purge'],
    ]);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec, 4);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $name = (string) ($options['positionals'][0] ?? '');
    if ($name === '') {
        return commandError($spec, new \InvalidArgumentException('name the extension to remove'));
    }

    try {
        $home = phpHome();
        $disabled = disableExtension($home, $name);
        $forgotten = forgetExtension($home, $name);
        if (! $disabled && ! $forgotten) {
            \fwrite(STDERR, "error: `{$name}` is not installed in the bundled PHP\n");

            return 1;
        }

        if ($options['purge']) {
            $file = extensionDir($home) . '/' . extensionFileName($name);
            if (\is_file($file)) {
                @\unlink($file);
            }
        }

        $declared = declaredExtensions('.');
        if ($declared !== []) {
            writeProjectLock('.', $home, $declared);
        }

        echo "removed {$name} from the bundled PHP" . ($options['purge'] ? ' and deleted its file' : ' (the file is kept; --purge deletes it)') . "\n";

        return 0;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/** @param list<string> $argv */
function runtimePhpVerify(array $argv): int
{
    $spec = new CommandSpec('verify', runtimePhpUsage(), [
        ['name' => 'php', 'value' => true],
    ]);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec, 4);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $declared = declaredExtensions('.');
    if ($declared === []) {
        echo "this project declares no extensions, so there is nothing to verify\n";

        return 0;
    }

    try {
        $host = \is_string($options['php']) ? phpHostAt($options['php']) : phpHost();
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }

    $available = \array_map(normalizeExtensionName(...), $host->extensions());
    $missing = [];
    echo $host->label() . "\n\n";
    foreach ($declared as $name) {
        $ok = \in_array(normalizeExtensionName($name), $available, true);
        \printf("  %-22s %s\n", $name, $ok ? 'ok' : 'MISSING');
        if (! $ok) {
            $missing[] = $name;
        }
    }

    if ($missing !== []) {
        echo "\n";
        foreach ($missing as $name) {
            echo "  install it with `moggi runtime php install-ext {$name}`\n";
        }
        $host->close();

        return 1;
    }

    $host->close();

    return 0;
}

/**
 * `moggi runtime php build` — a copy of this runtime that runs where it is put.
 *
 * The bundled runtime is bound to the machine that built it: its binary names
 * the build's own library directory, and PHP only looks for `php.ini` at the
 * prefix it was compiled with. This writes the version of it that a person can
 * ship: libraries beside the binary, a runpath relative to wherever it lands,
 * and a launcher that knows both. Windows needs none of that — its loader and
 * its ini search already look beside `php.exe` — but it does need an extension
 * directory it can find, which is why the copy has a launcher there too. It is
 * prepared once per extension set and then reused, which is what makes it
 * something to copy rather than rebuild.
 *
 * It does not build PHP and does not change the set: an extension a project
 * adds is installed into the bundled runtime first (`install-ext`), and the
 * prepared copy then carries it. An extension DLL the copy cannot load as a
 * plain `extension` — a `zend_extension` such as opcache is the usual one — is
 * reported as skipped rather than enabled to fail on every start.
 *
 * @param list<string> $argv
 */
function runtimePhpBuild(array $argv): int
{
    $spec = new CommandSpec('build', runtimePhpUsage(), [
        ['name' => 'php', 'value' => true],
        ['name' => 'out', 'value' => true],
        ['name' => 'force'],
    ]);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec, 4);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $named = \is_string($options['php']) ? $options['php'] : null;

    try {
        $home = $named === null ? bundledRuntimeForCopy() : portableRuntimeHome($named);
        echo "preparing a portable runtime from {$home}\n";
        $built = buildPortableRuntime(
            $home,
            \is_string($options['out']) ? $options['out'] : null,
            (bool) $options['force'],
            static function (string $line): void {
                echo "  {$line}\n";
            },
        );
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }

    echo "\n";
    \printf("php         %s%s\n", $built['php'], $built['zts'] ? ' (thread-safe)' : '');
    \printf("extensions  %d carried\n", \count($built['extensions']));
    if ($built['unavailable'] !== []) {
        \printf(
            "skipped     %d (would not load as an extension: %s)\n",
            \count($built['unavailable']),
            \implode(', ', $built['unavailable']),
        );
    }
    \printf("runtime     %s\n", $built['path']);
    \printf("size        %.1f MiB\n", $built['size'] / 1048576);
    if ($built['out'] !== null) {
        \printf("copied to   %s\n", $built['out']);
    }
    $entry = ($built['out'] ?? $built['path'])
        . (\PHP_OS_FAMILY === 'Windows' ? '\bin\php.cmd' : '/bin/php');
    echo "\nthe copy needs nothing from this machine: move it anywhere and run\n";
    \printf("  %s app.phar\n", $entry);

    return 0;
}

/**
 * The runtime this installation carries, named for the command that copies it.
 *
 * `phpHome()` speaks of installing extensions, which is the wrong sentence for
 * someone who asked for a copy: this says what to do about a distribution that
 * has no PHP to copy.
 */
function bundledRuntimeForCopy(): string
{
    try {
        return phpHome();
    } catch (\RuntimeException) {
        throw new \RuntimeException(
            "this installation bundles no PHP runtime, so there is nothing to copy\n"
            . '  use a distribution that bundles one (moggi, moggi-php), or name a runtime with --php PATH',
        );
    }
}

/**
 * The native runtime a `--native` build appends a program to.
 *
 * An explicit `MOGGI_MICRO_SFX` wins, then the bundled runtime, then a cached
 * build for exactly this extension set: the cache is what makes a set outside
 * the baseline reusable instead of per-checkout.
 */
function runtimePhpNative(): int
{
    try {
        $home = phpHome();
        $record = manifest($home);
        $declared = declaredExtensions('.');
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }

    $required = $declared === [] ? \array_keys($record['installed']) : $declared;
    $required = \array_values(\array_unique(\array_map(normalizeExtensionName(...), $required)));

    $candidates = [];
    $named = \getenv('MOGGI_MICRO_SFX');
    if (\is_string($named) && $named !== '') {
        $candidates[] = $named;
    } else {
        $bundled = resolveMicroSfx();
        if ($bundled !== null) {
            $candidates[] = $bundled;
        }
        $candidates[] = cachedNativeRuntimePath($required);
    }

    $best = null;
    $bestMissing = null;
    foreach ($candidates as $candidate) {
        $sfx = \is_dir($candidate) ? $candidate . '/micro.sfx' : $candidate;
        if (! \is_file($sfx)) {
            continue;
        }
        $carried = \array_map(normalizeExtensionName(...), runtimeExtensionsAt(\dirname($sfx)) ?? []);
        $missing = \array_values(\array_filter(
            $required,
            static fn (string $name): bool => ! \in_array($name, $carried, true),
        ));
        if ($missing === []) {
            echo "native runtime  {$sfx}\n";
            echo "  carries every extension this project needs"
                . ($required === [] ? " (it has none)" : '') . "\n";

            return 0;
        }
        if ($bestMissing === null || \count($missing) < \count($bestMissing)) {
            $best = $sfx;
            $bestMissing = $missing;
        }
    }

    $carriedSet = $best === null
        ? 'none was found'
        : 'it carries ' . \implode(', ', runtimeExtensionsAt(\dirname($best)) ?? []);
    \fwrite(
        STDERR,
        "error: no native PHP runtime carries " . \implode(', ', $bestMissing ?? $required) . "\n"
        . "  the runtime a --native build appends a program to is static: it cannot load an extension\n"
        . "  at run time, so the extension set has to be baked into the runtime itself\n"
        . "\n"
        . "  looked at:\n"
        . \implode('', \array_map(static fn (string $path): string => "    {$path}\n", $candidates))
        . '  found: ' . ($best ?? 'nothing') . " ({$carriedSet})\n"
        . "\n"
        . "  building one is release work, not something an installation can do: it needs\n"
        . "    - spc (static-php-cli) 3.0.0-alpha1, the pinned build tool\n"
        . "    - the pinned Zig 0.17.1 toolchain and a C toolchain\n"
        . "    - dist/runtimes.json's php-native recipe, which only a source checkout has\n"
        . "  a release build produces it with `php packaging/assemble.php`; point\n"
        . "  MOGGI_MICRO_SFX at the result, or drop `--native` and run the archive under\n"
        . "  the bundled PHP\n",
    );

    return 1;
}

/**
 * The backends this compiler targets, and the runtime each one runs programs on.
 *
 * `moggi runtime` and `moggi runtime list` answer the question a distribution
 * raises before anything is built: which of php, jvm and dotnet can run here,
 * and where the tool came from. A backend with no runtime still compiles — the
 * artifact it writes simply has nothing on this machine to run it.
 *
 * @param list<string> $argv
 */
function runtimeList(array $argv): int
{
    $spec = new CommandSpec('list', runtimeUsage(), [
        ['name' => 'json'],
    ], positionals: 0);

    try {
        $options = parseArgs($argv, $spec, ($argv[2] ?? null) === 'list' ? 3 : 2);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $backends = [];
    foreach (implementedBackendIds() as $backend) {
        $backends[$backend] = backendRuntime($backend);
    }
    $root = installationRoot();

    if ($options['json']) {
        echo \json_encode([
            'installation' => $root,
            'variant' => distributionVariant(),
            'backends' => $backends,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";

        return 0;
    }

    echo '  ' . \sprintf("%-8s %-11s %-13s %s\n", 'backend', 'version', 'from', 'runtime');
    foreach ($backends as $backend => $status) {
        echo '  ' . \sprintf(
            "%-8s %-11s %-13s %s\n",
            $backend,
            $status['version'] ?? '-',
            $status['source'],
            listedRuntimePath((string) ($status['runtime'] ?? $status['binary'] ?? 'not installed'), $root),
        );
    }

    echo "\n";
    $variant = distributionVariant();
    echo $root === null
        ? "this is a source checkout: there is no runtime/ beside the compiler, so every backend\n"
            . "runs on the toolchains on PATH\n"
        : "this installation is `{$variant}`\n";

    $missing = \array_keys(\array_filter(
        $backends,
        static fn (array $status): bool => $status['source'] === 'missing',
    ));
    if ($missing !== []) {
        echo "\n";
        foreach ($missing as $backend) {
            echo "  {$backend} has no runtime: use a moggi distribution that bundles it, or point its environment variable at one\n";
        }
    }

    return 0;
}

/** A runtime path as this installation sees it: `runtime/php` inside it, absolute outside. */
function listedRuntimePath(string $path, ?string $root): string
{
    $normalized = \str_replace('\\', '/', $path);
    if ($root !== null) {
        $prefix = \rtrim(\str_replace('\\', '/', $root), '/') . '/';
        if (\str_starts_with($normalized, $prefix)) {
            return \substr($normalized, \strlen($prefix));
        }
    }

    return $normalized;
}

/** This installation's `runtime/` directory, or null in a source checkout. */
function runtimeRoot(): ?string
{
    $root = installationRoot();

    return $root === null ? null : $root . '/runtime';
}

/**
 * What one backend's runtime is, and where it came from.
 *
 * `source` is the answer a person needs when a build fails to run: `bundled`
 * for a runtime inside `runtime/`, the variable or `PATH` the tool was found
 * through, `this compiler` when the PHP running the compiler is the only one
 * there is, and `missing` when the backend cannot run what it builds.
 *
 * @return array{backend: string, runtime: ?string, binary: ?string, version: ?string, source: string, note: ?string}
 */
function backendRuntime(string $backend): array
{
    return match ($backend) {
        'php' => phpRuntimeStatus(),
        'jvm' => jvmRuntimeStatus(),
        'dotnet' => dotnetRuntimeStatus(),
        default => throw new \InvalidArgumentException("unknown backend `{$backend}`"),
    };
}

/** @return array{backend: string, runtime: ?string, binary: ?string, version: ?string, source: string, note: ?string} */
function runtimeRecord(string $backend, ?string $runtime, ?string $binary, ?string $version, string $source, ?string $note): array
{
    return [
        'backend' => $backend,
        'runtime' => $runtime,
        'binary' => $binary,
        'version' => $version,
        'source' => $source,
        'note' => $note,
    ];
}

/** @return array{backend: string, runtime: ?string, binary: ?string, version: ?string, source: string, note: ?string} */
function phpRuntimeStatus(): array
{
    $root = runtimeRoot();
    if ($root !== null) {
        $home = $root . '/php';
        $binary = phpBinary($home);
        if (\is_file($binary)) {
            return runtimeRecord('php', $home, $binary, phpRuntimeVersion($home), 'bundled', null);
        }
    }

    try {
        $host = phpHost();
        $binary = phpHostBinary();
    } catch (\RuntimeException $error) {
        return runtimeRecord('php', null, null, null, 'missing', $error->getMessage());
    }

    $named = \getenv(HOST_ENV);
    $source = \is_string($named) && $named !== '' ? HOST_ENV : 'this compiler';

    return runtimeRecord('php', null, $binary, $host->version(), $source, null);
}

/** The version a bundled PHP reports, from its manifest when it has one. */
function phpRuntimeVersion(string $home): ?string
{
    $record = manifest($home);
    foreach ([$record['abi']['version'], $record['php']] as $version) {
        if ($version !== '') {
            return $version;
        }
    }

    try {
        return hostAbi($home)['version'];
    } catch (\RuntimeException) {
        return null;
    }
}

/** @return array{backend: string, runtime: ?string, binary: ?string, version: ?string, source: string, note: ?string} */
function jvmRuntimeStatus(): array
{
    $binary = resolveJavaExecutable();
    if ($binary === null) {
        return runtimeRecord('jvm', null, null, null, 'missing', 'no `java` on PATH and no JAVA_HOME');
    }

    $named = \getenv('JAVA_HOME');
    $fromEnvironment = \is_string($named) && $named !== '';
    $home = $fromEnvironment ? \rtrim($named, '/\\') : \dirname($binary, 2);

    return runtimeRecord('jvm', $home, $binary, javaVersion(), runtimeSource($binary, $fromEnvironment ? 'JAVA_HOME' : 'PATH'), null);
}

/** @return array{backend: string, runtime: ?string, binary: ?string, version: ?string, source: string, note: ?string} */
function dotnetRuntimeStatus(): array
{
    $binary = resolveDotnetExecutable();
    if ($binary === null) {
        return runtimeRecord('dotnet', null, null, null, 'missing', 'no `dotnet` on PATH and no DOTNET_ROOT');
    }

    $named = \getenv('DOTNET_ROOT');
    $fromEnvironment = \is_string($named) && $named !== '';
    $home = $fromEnvironment ? \rtrim($named, '/\\') : \dirname($binary);

    return runtimeRecord('dotnet', $home, $binary, dotnetVersion(), runtimeSource($binary, $fromEnvironment ? 'DOTNET_ROOT' : 'PATH'), null);
}

/** `bundled` when the tool sits in this installation's `runtime/`, else where it was looked for. */
function runtimeSource(?string $binary, string $fallback): string
{
    $root = runtimeRoot();
    if ($root === null || $binary === null) {
        return $fallback;
    }

    $runtime = \realpath($root);
    $real = \realpath($binary);
    if ($runtime === false || $real === false) {
        return $fallback;
    }

    return \str_starts_with($real, $runtime . \DIRECTORY_SEPARATOR) ? 'bundled' : $fallback;
}

/**
 * `moggi runtime <backend>` for a backend with no verbs of its own.
 *
 * jvm and dotnet have one question worth asking — what version is installed,
 * and which of the tools the backend calls are there — so `status` and
 * `version` are the same report. php grew its own verbs and keeps them.
 *
 * @param list<string> $argv
 */
function runRuntimeBackendCommand(string $backend, array $argv): int
{
    $verb = $argv[3] ?? 'status';

    if (\str_starts_with($verb, '-') && wantsHelp($argv)) {
        echo runtimeBackendUsage($backend) . "\n";

        return 0;
    }

    if ($verb !== 'status' && $verb !== 'version') {
        \fwrite(STDERR, "error: unknown runtime {$backend} command `{$verb}`\n\n" . runtimeBackendUsage($backend) . "\n");

        return 1;
    }

    return runtimeBackendVersion($backend, $argv);
}

function runtimeBackendUsage(string $backend): string
{
    return <<<HELP
    usage:
      moggi runtime {$backend} [--json]
      moggi runtime {$backend} version [--json]

    What the `{$backend}` backend runs programs on: the version of the toolchain it
    found, the path it found it at, and where that is — bundled in `runtime/`, or
    on `PATH`. A missing runtime exits 1: the backend still compiles, but nothing
    it builds can run on this machine until one is installed.

    options:
      --json           machine-readable output
      -h, --help       show this help
    HELP;
}

/**
 * One backend's version report, for the backend and for `--json`.
 *
 * A missing runtime is a failing status rather than an empty report, because the
 * only reason to ask is to find out whether a build can run.
 *
 * @param list<string> $argv
 */
function runtimeBackendVersion(string $backend, array $argv): int
{
    $spec = new CommandSpec('version', runtimeBackendUsage($backend), [
        ['name' => 'json'],
    ], positionals: 0);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec, 4);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $status = backendRuntime($backend);
    $details = match ($backend) {
        'php' => phpRuntimeDetails($status),
        'jvm' => jvmRuntimeDetails($status),
        default => ['root' => $status['runtime']],
    };

    if ($options['json']) {
        echo \json_encode([...$status, 'details' => $details], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";

        return $status['source'] === 'missing' ? 1 : 0;
    }

    if ($status['source'] === 'missing') {
        \fwrite(STDERR, 'error: ' . ($status['note'] ?? "no {$backend} runtime") . "\n");
        \fwrite(STDERR, "  use a moggi distribution that bundles {$backend}, or set its environment variable\n");

        return 1;
    }

    match ($backend) {
        'php' => printPhpRuntime($status, $details),
        'jvm' => printJvmRuntime($status, $details),
        default => printDotnetRuntime($status, $details),
    };

    return 0;
}

/** One `label   value` line of a runtime report, with `-` for what is absent. */
function printRuntimeField(string $label, ?string $value): void
{
    \printf("%-13s%s\n", $label, $value ?? '-');
}

/**
 * The PHP-specific half of the report: the ini it loads, its ABI and its set.
 *
 * A bundled runtime answers from its manifest, which is what an extension
 * install writes; the host answers about itself.
 *
 * @param array{runtime: ?string, source: string} $status
 * @return array{ini: ?string, threadSafe: ?bool, extensions: ?string}
 */
function phpRuntimeDetails(array $status): array
{
    if ($status['source'] === 'bundled' && $status['runtime'] !== null) {
        $record = manifest($status['runtime']);
        $ini = phpIniPath($status['runtime']);

        return [
            'ini' => \is_file($ini) ? $ini : null,
            'threadSafe' => $record['abi']['version'] === '' ? null : $record['abi']['zts'],
            'extensions' => \sprintf('%d built in, %d installed', \count($record['extensions']), \count($record['installed'])),
        ];
    }

    try {
        $host = phpHost();
    } catch (\RuntimeException) {
        return ['ini' => null, 'threadSafe' => null, 'extensions' => null];
    }

    return ['ini' => null, 'threadSafe' => null, 'extensions' => \sprintf('%d loaded', \count($host->extensions()))];
}

/**
 * The JVM half of the report: the other tools a jvm build needs beside `java`.
 *
 * `javac` is looked for beside the `java` that was found, so a bundled JDK is
 * reported as itself rather than as whatever `javac` happens to be on `PATH`;
 * `native-image` and `maven` are found the way the backends find them.
 *
 * @param array{binary: ?string} $status
 * @return array{javac: ?string, nativeImage: ?string, maven: ?string}
 */
function jvmRuntimeDetails(array $status): array
{
    $javac = null;
    if ($status['binary'] !== null) {
        $candidate = \dirname($status['binary']) . '/javac' . (\PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $javac = \is_file($candidate) ? $candidate : findExecutable('javac');
    }

    return [
        'javac' => $javac === null ? null : versionFromTool([$javac, '-version']),
        'nativeImage' => nativeImageVersion(),
        'maven' => mavenVersion(),
    ];
}

/** The version-shaped word a tool prints, from stdout or stderr. */
function versionFromTool(array $command): ?string
{
    $output = captureProcessOutput($command);
    if ($output === null) {
        return null;
    }

    return \preg_match('/^(?:[A-Za-z][\w-]*\s+)?v?(\d+\S*)/m', \trim($output), $match) === 1 ? $match[1] : null;
}

/** `mvn -v` names its version on the first line: `Apache Maven 3.9.6`. */
function mavenVersion(): ?string
{
    $mvn = findExecutable('mvn');
    if ($mvn === null) {
        return null;
    }

    $output = captureProcessOutput([$mvn, '-v']);
    if ($output === null || \preg_match('/Apache Maven\s+(\S+)/', $output, $match) !== 1) {
        return null;
    }

    return $match[1];
}

/** @param array{version: ?string, binary: ?string, source: string} $status @param array{ini: ?string, threadSafe: ?bool, extensions: ?string} $details */
function printPhpRuntime(array $status, array $details): void
{
    printRuntimeField('php', $status['version']);
    printRuntimeField('binary', $status['binary']);
    if ($details['ini'] !== null) {
        printRuntimeField('ini', $details['ini']);
    }
    if ($details['extensions'] !== null) {
        printRuntimeField('extensions', $details['extensions']);
    }
    if ($details['threadSafe'] !== null) {
        printRuntimeField('thread-safe', $details['threadSafe'] ? 'yes' : 'no');
    }
    printRuntimeField('from', $status['source']);
}

/** @param array{version: ?string, binary: ?string, runtime: ?string, source: string} $status @param array{javac: ?string, nativeImage: ?string, maven: ?string} $details */
function printJvmRuntime(array $status, array $details): void
{
    printRuntimeField('java', $status['version']);
    printRuntimeField('java path', $status['binary']);
    printRuntimeField('home', $status['runtime']);
    printRuntimeField('javac', $details['javac']);
    printRuntimeField('native-image', $details['nativeImage']);
    printRuntimeField('maven', $details['maven']);
    printRuntimeField('from', $status['source']);
}

/** @param array{version: ?string, binary: ?string, source: string} $status @param array{root: ?string} $details */
function printDotnetRuntime(array $status, array $details): void
{
    printRuntimeField('sdk', $status['version']);
    printRuntimeField('dotnet', $status['binary']);
    printRuntimeField('root', $details['root']);
    printRuntimeField('from', $status['source']);
}

/**
 * `moggi runtime install <backend>` — put a runtime where this installation looks.
 *
 * An installed distribution is one directory: `bin/moggi` plus `libraries/` and
 * `runtime/`. A variant that does not bundle a backend can still target it, but
 * nothing it builds runs until that backend has a runtime in `runtime/`. The
 * runtime comes from one of three places: the release archive matching this
 * installation's own version and platform (`moggi-jvm-<version>-<target>`),
 * checked against that release's `SHA256SUMS`; an archive named with `--from` (a
 * `.tar.gz` or `.zip`), checked against `--sha256` when one is given; or a
 * directory named with `--from` — an unpacked JDK, .NET SDK or PHP build.
 *
 * An archive is unpacked beside `runtime/` rather than in it, so nothing enters
 * the installation before its digest has been checked. The runtime is then found
 * in the unpacked tree — past a wrapper directory, a macOS `Contents/Home`, or a
 * variant's own `runtime/<backend>` — copied to a staging directory inside
 * `runtime/`, and started once before it is moved into place: a runtime that does
 * not run is not installed and does not replace one that does. Installing over an
 * existing runtime is refused unless `--force` says otherwise; with the runtime
 * already there, the command is the no-op it should be.
 *
 * @param list<string> $argv
 */
function runRuntimeInstall(array $argv): int
{
    $spec = new CommandSpec('install', runtimeUsage(), [
        ['name' => 'from', 'value' => true],
        ['name' => 'sha256', 'value' => true],
        ['name' => 'force'],
    ]);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec, 3);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $backend = (string) ($options['positionals'][0] ?? '');
    $backends = implementedBackendIds();
    if ($backend === '') {
        return commandError($spec, new \InvalidArgumentException('name the backend to install: ' . \implode(', ', $backends)));
    }
    if (! \in_array($backend, $backends, true)) {
        return commandError($spec, new \InvalidArgumentException(
            "unknown backend `{$backend}`; this compiler targets " . \implode(', ', $backends),
        ));
    }

    $root = installationRoot();
    if ($root === null) {
        \fwrite(
            STDERR,
            "error: this is a source checkout, so there is no runtime/ to install into\n"
            . "  a checkout runs on the toolchains on PATH; `runtime install` belongs to a distribution\n",
        );

        return 1;
    }

    $from = \is_string($options['from']) ? $options['from'] : null;
    $digest = \is_string($options['sha256']) ? $options['sha256'] : null;
    if ($digest !== null && $from === null) {
        return commandError($spec, new \InvalidArgumentException(
            '--sha256 checks an archive named with --from; the release archive is checked against its SHA256SUMS',
        ));
    }

    try {
        $installed = installBackendRuntime(
            $backend,
            $root . '/runtime/' . $backend,
            $from,
            (bool) $options['force'],
            $digest,
        );
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }

    return reportInstalledRuntime($backend, $installed);
}

/**
 * Copy a runtime into place, or report that the one there is already usable.
 *
 * @return array{path: string, version: ?string, size: ?int, changed: bool, replaced: bool, sha256: ?string}
 */
function installBackendRuntime(string $backend, string $target, ?string $from, bool $force, ?string $sha256 = null): array
{
    $present = \is_dir($target) && \is_file(backendExecutable($backend, $target));
    if ($present && ! $force) {
        return [
            'path' => $target,
            'version' => backendRuntime($backend)['version'],
            'size' => null,
            'changed' => false,
            'replaced' => false,
            'sha256' => null,
        ];
    }

    $home = \dirname($target);
    if (! \is_dir($home) && ! \mkdir($home, 0777, true) && ! \is_dir($home)) {
        throw new \RuntimeException("cannot create {$home}");
    }

    $source = runtimeInstallSource($backend, $target, $home, $from, $sha256);
    $staging = $home . '/.' . $backend . '-install-' . \bin2hex(\random_bytes(4));
    $backup = null;
    echo "copying {$source['label']} into {$target}\n";
    try {
        if (! $source['move'] || ! @\rename($source['tree'], $staging)) {
            copyPortableTree($source['tree'], $staging);
        }

        $failure = runtimeDoesNotStart($backend, $staging);
        if ($failure !== null) {
            throw new \RuntimeException("the runtime at {$source['tree']} does not start, so it was not installed:\n" . $failure);
        }

        if (\is_dir($target)) {
            $backup = $home . '/.' . $backend . '-replaced-' . \bin2hex(\random_bytes(4));
            if (! @\rename($target, $backup)) {
                throw new \RuntimeException("cannot move the runtime at {$target} aside");
            }
        }
        if (! @\rename($staging, $target)) {
            if ($backup !== null) {
                @\rename($backup, $target);
            }
            throw new \RuntimeException("cannot move the prepared runtime into {$target}");
        }
    } catch (\RuntimeException $error) {
        removeSourceTree($staging);
        throw $error;
    } finally {
        if ($source['scratch'] !== null) {
            removeSourceTree($source['scratch']);
        }
    }
    if ($backup !== null) {
        removeSourceTree($backup);
    }

    return [
        'path' => $target,
        'version' => backendRuntime($backend)['version'],
        'size' => directorySize($target),
        'changed' => true,
        'replaced' => $backup !== null,
        'sha256' => $source['sha256'],
    ];
}

/**
 * Where the runtime to install comes from: a directory, an archive, or a release.
 *
 * A directory is copied as it is. An archive is unpacked into a scratch
 * directory beside `runtime/` — never inside it — so nothing enters the
 * installation before its digest has been checked, and the runtime is then found
 * in the tree that came out of it.
 *
 * @return array{tree: string, label: string, move: bool, scratch: ?string, sha256: ?string}
 */
function runtimeInstallSource(string $backend, string $target, string $home, ?string $from, ?string $sha256): array
{
    if ($from === null || $from === '') {
        return fetchReleaseRuntime($backend, $home);
    }

    $from = \rtrim($from, '/\\');
    if ($from !== '' && \is_file($from)) {
        return unpackRuntimeInstall($backend, $home, $from, $sha256);
    }
    if ($from === '' || ! \is_dir($from)) {
        throw new \RuntimeException(
            "nothing at {$from}: name an unpacked runtime directory, or the archive it came in (.tar.gz, .zip)",
        );
    }
    if ($sha256 !== null) {
        throw new \RuntimeException("--sha256 is for an archive: {$from} is a directory, and a directory has no one digest");
    }

    $source = \realpath($from);
    if ($source === false || ! \is_dir($source)) {
        throw new \RuntimeException("cannot resolve {$from}");
    }
    $source = \str_replace('\\', '/', $source);
    $destination = \str_replace('\\', '/', (string) (\realpath($target) ?: $target));
    if ($source === $destination) {
        throw new \RuntimeException('--from names the directory the runtime would be installed into');
    }
    if (\str_starts_with($source . '/', $destination . '/') || \str_starts_with($destination . '/', $source . '/')) {
        throw new \RuntimeException(
            "--from {$source} and the installation directory {$destination} contain one another,\n"
            . '  so a copy between them would never finish',
        );
    }

    $problem = runtimeSourceProblem($backend, $source);
    if ($problem !== null) {
        throw new \RuntimeException($problem);
    }

    return ['tree' => $source, 'label' => $source, 'move' => false, 'scratch' => null, 'sha256' => null];
}

/**
 * Unpack an archive beside `runtime/` and find the runtime inside it.
 *
 * @return array{tree: string, label: string, move: bool, scratch: string, sha256: ?string}
 */
function unpackRuntimeInstall(string $backend, string $home, string $archive, ?string $sha256): array
{
    $format = archiveFormat($archive);
    if ($format === null) {
        throw new \RuntimeException(
            "{$archive} is not an archive this command unpacks: it reads .tar.gz, .tgz, .tar, .tar.xz and .zip",
        );
    }

    $scratch = runtimeScratch($backend, $home);
    try {
        $digest = verifyArchive($archive, $sha256);
        $unpacked = $scratch . '/unpacked';
        unpackArchive($archive, $format, $unpacked);

        $tree = locateRuntimeTree($backend, $unpacked);
        if ($tree === null) {
            throw new \RuntimeException(
                "{$archive} does not contain a {$backend} runtime:\n" . runtimeArchiveProblem($backend, $unpacked),
            );
        }
        markRuntimeExecutable($backend, $tree);

        return ['tree' => $tree, 'label' => $archive, 'move' => true, 'scratch' => $scratch, 'sha256' => $digest];
    } catch (\RuntimeException $error) {
        removeSourceTree($scratch);
        throw $error;
    }
}

/**
 * Fetch the release archive this installation's own version and platform name.
 *
 * @return array{tree: string, label: string, move: bool, scratch: string, sha256: string}
 */
function fetchReleaseRuntime(string $backend, string $home): array
{
    $release = releaseRuntimeArchive($backend);
    $scratch = runtimeScratch($backend, $home);

    try {
        echo "fetching {$release['url']}\n";
        $archive = $scratch . '/' . $release['name'];
        try {
            downloadRuntimeFile($release['url'], $archive);
        } catch (\RuntimeException $error) {
            throw new \RuntimeException(
                $error->getMessage() . "\n"
                . "  this is the archive this installation's own release publishes\n"
                . suggestRuntimeSource($backend),
            );
        }

        $actual = archiveDigest($archive);
        if ($actual !== $release['sha256']) {
            throw new \RuntimeException(
                "{$release['name']} does not match the release's SHA256SUMS, so it was not installed\n"
                . "  expected {$release['sha256']}\n  actually {$actual}",
            );
        }
        echo "sha256    {$actual} (SHA256SUMS)\n";

        $unpacked = $scratch . '/unpacked';
        unpackArchive($archive, archiveFormat($release['name']) ?? 'tar', $unpacked);

        $tree = locateRuntimeTree($backend, $unpacked);
        if ($tree === null) {
            throw new \RuntimeException(
                "{$release['name']} carries no {$backend} runtime:\n" . runtimeArchiveProblem($backend, $unpacked),
            );
        }
        markRuntimeExecutable($backend, $tree);

        return ['tree' => $tree, 'label' => $release['name'], 'move' => true, 'scratch' => $scratch, 'sha256' => $actual];
    } catch (\RuntimeException $error) {
        removeSourceTree($scratch);
        throw $error;
    }
}

/**
 * The release archive that carries one backend's runtime, and the digest to check.
 *
 * A release publishes one archive per variant and target, and each variant is
 * named after the one backend it bundles, so the backend picks the archive:
 * `moggi-php`, `moggi-jvm`, `moggi-dotnet`. A build of an unreleased commit is
 * uploaded under the moving `dev-build` tag (see `package.yml`), which is why the
 * tag a version is looked up under is not always the version itself.
 *
 * @return array{url: string, name: string, sha256: string, version: string}
 */
function releaseRuntimeArchive(string $backend): array
{
    $version = compilerVersion();
    if ($version === null) {
        throw new \RuntimeException(
            "this installation does not know its own version, so there is no release archive to fetch\n"
            . "  (the VERSION beside the compiler is what names it)\n"
            . suggestRuntimeSource($backend),
        );
    }

    $target = distributionTarget();
    if ($target === null) {
        throw new \RuntimeException(
            \sprintf("this installation has no release archive for %s on %s\n", \PHP_OS_FAMILY, \php_uname('m'))
            . suggestRuntimeSource($backend),
        );
    }

    $name = \sprintf(
        '%s-%s-%s.%s',
        runtimeReleaseVariant($backend),
        $version,
        $target,
        \str_starts_with($target, 'windows-') ? 'zip' : 'tar.gz',
    );
    $tag = \str_contains($version, '-dev.') ? 'dev-build' : $version;
    $base = runtimeReleaseBase() . '/' . $tag;
    try {
        $sums = releaseChecksums($base . '/SHA256SUMS');
    } catch (\RuntimeException $error) {
        throw new \RuntimeException(
            $error->getMessage() . "\n"
            . "  the {$tag} release is where the digest an install is checked against is published\n"
            . "  name an archive yourself with --from ARCHIVE (and --sha256 HEX) instead\n",
        );
    }
    if (! isset($sums[$name])) {
        throw new \RuntimeException(
            "the {$tag} release publishes no digest for {$name}, so there is nothing to check it against\n"
            . "  name the archive yourself with --from ARCHIVE (and --sha256 HEX)\n",
        );
    }

    return ['url' => $base . '/' . $name, 'name' => $name, 'sha256' => $sums[$name], 'version' => $version];
}

/** The variant whose release archive carries one backend's runtime. */
function runtimeReleaseVariant(string $backend): string
{
    return match ($backend) {
        'php' => 'moggi-php',
        'jvm' => 'moggi-jvm',
        default => 'moggi-dotnet',
    };
}

/**
 * The distribution target this compiler runs on — `linux-x86_64` and the rest —
 * or null where no release is built.
 *
 * These are the target names in `dist/runtimes.json`, which is what a release
 * archive is named after. Windows on ARM is not a target at all: php.net
 * publishes no Windows ARM64 PHP and static-php-cli no Windows ARM64 tool, so
 * there is no micro runtime to build the compiler from either.
 */
function distributionTarget(): ?string
{
    $machine = \strtolower(\php_uname('m'));
    $arm = \in_array($machine, ['aarch64', 'arm64'], true);
    $x86 = \in_array($machine, ['x86_64', 'amd64'], true);
    if (! $arm && ! $x86) {
        return null;
    }

    return match (\PHP_OS_FAMILY) {
        'Linux' => $arm ? 'linux-aarch64' : 'linux-x86_64',
        'Darwin' => $arm ? 'macos-aarch64' : 'macos-x86_64',
        'Windows' => $arm ? null : 'windows-x86_64',
        default => null,
    };
}

/**
 * The digests a release publishes in its `SHA256SUMS`, as file name => sha256.
 *
 * `sha256sum` writes `<digest>  ./<name>`, and in binary mode `<digest> *<name>`,
 * so the name is what follows the whitespace with any `./` or `*` removed.
 *
 * @return array<string, string>
 */
function releaseChecksums(string $url): array
{
    $sums = [];
    foreach (\preg_split('/\R/', downloadRuntimeText($url)) ?: [] as $line) {
        $parts = \preg_split('/\s+/', \trim($line)) ?: [];
        if (\count($parts) < 2 || \preg_match('/^[0-9a-f]{64}$/i', $parts[0]) !== 1) {
            continue;
        }
        $sums[\basename(\ltrim($parts[1], '*'))] = \strtolower($parts[0]);
    }
    if ($sums === []) {
        throw new \RuntimeException("{$url} lists no sha256 digests");
    }

    return $sums;
}

/**
 * Copy a URL to a local file, following redirects, or say why it could not.
 *
 * The compiler carries no HTTP client and a distribution has no curl to borrow,
 * so this is the `http` stream wrapper: no extension needed, and a release asset
 * URL is a redirect, which it follows.
 */
function downloadRuntimeFile(string $url, string $destination): void
{
    $in = @\fopen($url, 'rb', false, runtimeStreamContext());
    $status = runtimeHttpStatus($http_response_header ?? []);
    if ($in === false) {
        throw new \RuntimeException("cannot fetch {$url}" . ($status === null ? '' : " (HTTP {$status})"));
    }
    if ($status !== null && $status >= 400) {
        \fclose($in);
        throw new \RuntimeException("cannot fetch {$url}: HTTP {$status}");
    }

    $out = @\fopen($destination, 'wb');
    if ($out === false) {
        \fclose($in);
        throw new \RuntimeException("cannot write {$destination}");
    }
    $copied = \stream_copy_to_stream($in, $out);
    \fclose($in);
    \fclose($out);

    if ($copied === false || $copied === 0) {
        @\unlink($destination);
        throw new \RuntimeException("cannot read {$url} into {$destination}");
    }
}

/** A small text file — a release's `SHA256SUMS` — fetched into a string. */
function downloadRuntimeText(string $url): string
{
    $file = \tempnam(\sys_get_temp_dir(), 'moggi-runtime-');
    if ($file === false) {
        throw new \RuntimeException('cannot create a download target');
    }

    try {
        downloadRuntimeFile($url, $file);
        $text = @\file_get_contents($file);
        if (! \is_string($text)) {
            throw new \RuntimeException("cannot read {$url}");
        }

        return $text;
    } finally {
        @\unlink($file);
    }
}

/** The stream context every fetch uses: a name for the request, and a long wait. */
function runtimeStreamContext()
{
    return \stream_context_create([
        'http' => [
            'user_agent' => 'moggi runtime install',
            'timeout' => 1800,
            'follow_location' => 1,
            'max_redirects' => 10,
        ],
    ]);
}

/** The last status code in a stream wrapper's response headers, or null. */
function runtimeHttpStatus(array $headers): ?int
{
    $status = null;
    foreach ($headers as $header) {
        if (\preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    return $status;
}

/** The archive format `$path` names, or null when this command cannot unpack it. */
function archiveFormat(string $path): ?string
{
    $lower = \strtolower(\rtrim($path));

    return match (true) {
        \str_ends_with($lower, '.zip') => 'zip',
        \str_ends_with($lower, '.tar.gz'),
        \str_ends_with($lower, '.tgz'),
        \str_ends_with($lower, '.tar'),
        \str_ends_with($lower, '.tar.xz'),
        \str_ends_with($lower, '.txz') => 'tar',
        default => null,
    };
}

/** A file's sha256, as lower-case hex. */
function archiveDigest(string $path): string
{
    $digest = @\hash_file('sha256', $path);
    if (! \is_string($digest)) {
        throw new \RuntimeException("cannot read {$path}");
    }

    return \strtolower($digest);
}

/**
 * Check an archive against the digest it is supposed to have.
 *
 * A digest that is given has to match before the archive is unpacked, which is
 * the point of giving one: a truncated or substituted download is refused while
 * it is still a file beside `runtime/`, not after it is what programs run on.
 */
function verifyArchive(string $archive, ?string $expected): ?string
{
    if ($expected === null || $expected === '') {
        return null;
    }

    $expected = \strtolower(\trim($expected));
    if (\preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
        throw new \RuntimeException("--sha256 wants 64 hex digits, not `{$expected}`");
    }
    $actual = archiveDigest($archive);
    if ($actual !== $expected) {
        throw new \RuntimeException(
            "{$archive} does not match --sha256, so it was not installed\n"
            . "  expected {$expected}\n  actually {$actual}",
        );
    }
    echo "sha256    {$actual} (--sha256)\n";

    return $actual;
}

/**
 * Unpack an archive into `$destination`.
 *
 * A zip is read with ext-zip rather than shelled out to `unzip`: GNU tar cannot
 * read a zip at all and `unzip` may be installed nowhere, while every runtime
 * this compiler runs on carries ext-zip — `dist/runtimes.json` compiles it into
 * the micro runtime, and the jvm backend writes its jars with it before a single
 * class runs. Reading it in process also lets each entry name be checked before
 * it becomes a path.
 * A tarball goes through `tar`, with its member names read before anything is
 * created and its links checked after: the pair of checks the distribution build
 * makes on the archives it pins.
 */
function unpackArchive(string $archive, string $format, string $destination): void
{
    if (! \is_dir($destination) && ! \mkdir($destination, 0777, true) && ! \is_dir($destination)) {
        throw new \RuntimeException("cannot create {$destination}");
    }

    if ($format === 'zip') {
        extractZipArchive($archive, $destination);

        return;
    }

    $tar = tarFor($archive);
    assertArchiveMemberNames($archive, $tar);

    $result = runProcess([$tar, '-xf', $archive, '-C', $destination]);
    if ($result['exitCode'] !== 0) {
        throw new \RuntimeException(
            "cannot unpack {$archive}:\n  " . \trim($result['stderr'] . "\n" . $result['stdout']),
        );
    }

    assertArchiveLinksContained($destination);
}

/**
 * Unpack a zip archive through ext-zip, without `unzip` or a tar that reads one.
 *
 * The archive is opened by the extension, so zip64 and every compression method
 * libzip knows are its business rather than this command's. Each entry is still
 * written where this command decides and checked against the size and CRC-32 the
 * archive's central directory declares: an entry name is checked before anything
 * is written, so no member chooses where in the tree it lands, and a symlink is
 * refused rather than created.
 *
 * `extractTo` would be shorter, and it does keep an archive inside the tree and
 * notice a bad CRC, but it keeps them its own way: a `..` name is folded into the
 * tree instead of refused, a symlink is written down as the plain file it points
 * at, and no mode bits survive. A runtime is where an archive's own idea of the
 * tree has to be a refusal rather than a differently-shaped tree.
 */
function extractZipArchive(string $archive, string $destination): void
{
    $zip = new \ZipArchive();
    $opened = @$zip->open($archive);
    if ($opened !== true) {
        throw new \RuntimeException("{$archive} is not a readable zip archive (ZipArchive status {$opened})");
    }

    try {
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            zipExtractEntry($zip, $archive, $destination, $index);
        }
    } finally {
        $zip->close();
    }
}

/**
 * One entry of a zip, written into `$destination` and checked against its header.
 */
function zipExtractEntry(\ZipArchive $zip, string $archive, string $destination, int $index): void
{
    $entry = @$zip->statIndex($index);
    $name = \is_array($entry) ? (string) $entry['name'] : '';
    if ($name === '') {
        throw new \RuntimeException("{$archive} is not a readable zip archive: entry {$index} has no header");
    }

    $mode = zipEntryMode($zip, $index);
    $path = zipEntryPath($destination, $name);
    if (\str_ends_with(\str_replace('\\', '/', $name), '/') || ($mode & 0170000) === 0040000) {
        if (! \is_dir($path) && ! \mkdir($path, 0777, true) && ! \is_dir($path)) {
            throw new \RuntimeException("cannot create {$path}");
        }

        return;
    }
    if (($mode & 0170000) === 0120000) {
        throw new \RuntimeException(
            "archive member {$name} is a symlink, which this command does not unpack\n"
            . '  unpack it yourself and name the runtime directory with --from DIR',
        );
    }

    $parent = \dirname($path);
    if (! \is_dir($parent) && ! \mkdir($parent, 0777, true) && ! \is_dir($parent)) {
        throw new \RuntimeException("cannot create {$parent}");
    }

    $in = @$zip->getStream($name);
    if ($in === false) {
        throw new \RuntimeException("archive member {$name} is unreadable");
    }
    $out = @\fopen($path, 'wb');
    if ($out === false) {
        \fclose($in);

        throw new \RuntimeException("cannot write {$path}");
    }

    $hash = \hash_init('crc32b');
    $written = 0;
    try {
        while (true) {
            $chunk = @\fread($in, 1048576);
            if ($chunk === false || $chunk === '') {
                break;
            }
            if (\fwrite($out, $chunk) !== \strlen($chunk)) {
                throw new \RuntimeException("cannot write {$name} into the unpacked tree");
            }
            \hash_update($hash, $chunk);
            $written += \strlen($chunk);
        }
    } finally {
        \fclose($in);
        \fclose($out);
    }

    $crc = (int) \hexdec((string) \hash_final($hash));
    if ($crc !== (int) $entry['crc'] || $written !== (int) $entry['size']) {
        throw new \RuntimeException("archive member {$name} did not unpack to what the archive declares");
    }
    if (($mode & 0777) !== 0) {
        @\chmod($path, $mode & 0777);
    }
}

/**
 * The Unix mode a zip entry's external attributes state, or 0 when it states none.
 *
 * php.net's Windows build and the archives the distribution build makes carry one;
 * an entry that says nothing about its mode is left as writing it created it, and
 * the launcher is made executable once the tree is in place.
 */
function zipEntryMode(\ZipArchive $zip, int $index): int
{
    $platform = 0;
    $attributes = 0;
    if (! $zip->getExternalAttributesIndex($index, $platform, $attributes)) {
        return 0;
    }

    return ($attributes >> 16) & 0xFFFF;
}

/**
 * Why a member name, slashes already normalized, cannot become a path in the tree.
 *
 * A zip and a tarball both carry names someone else wrote, and what either unpacks
 * to is moved into `runtime/`, where every later build trusts what it finds, so
 * one rule judges a name for both formats.
 */
function archiveNameProblem(string $name): ?string
{
    if (\str_starts_with($name, '/') || \preg_match('#^[A-Za-z]:#', $name) === 1) {
        return "archive member {$name} is an absolute path";
    }
    foreach (\explode('/', $name) as $part) {
        if ($part === '..') {
            return "archive member {$name} climbs out of the extraction directory";
        }
    }

    return null;
}

/** The path a zip member name means, once its name is judged. */
function zipEntryPath(string $destination, string $name): string
{
    $normalized = \str_replace('\\', '/', $name);
    $problem = archiveNameProblem($normalized);
    if ($problem !== null) {
        throw new \RuntimeException($problem);
    }

    return $destination . '/' . $normalized;
}

/** The `tar` an archive is unpacked with, or a refusal naming what cannot be read. */
function tarFor(string $archive): string
{
    $tar = findExecutable('tar');
    if ($tar === null) {
        throw new \RuntimeException("cannot read {$archive}: `tar` is not on PATH");
    }

    return $tar;
}

/**
 * Refuse a tarball whose member names are absolute or climb out of the tree.
 *
 * This listing pass has to come before `unpackArchive` runs `tar -xf`: a member's
 * name exists only in the archive, and once extracted, a name that climbed out is
 * whatever tar sanitized it to, indistinguishable from a member legitimately named
 * that. It is also this command's refusal to make, not tar's: measured with this
 * host's GNU tar, an absolute member is written inside the destination as a
 * `home/...` path with a warning, and only `..` makes tar itself fail.
 */
function assertArchiveMemberNames(string $archive, string $tar): void
{
    $result = runProcess([$tar, '-tf', $archive]);
    if ($result['exitCode'] !== 0) {
        throw new \RuntimeException(
            "cannot read {$archive}:\n  " . \trim($result['stderr'] . "\n" . $result['stdout']),
        );
    }

    foreach (\preg_split('/\R/', $result['stdout']) ?: [] as $member) {
        $problem = archiveNameProblem(\str_replace('\\', '/', $member));
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }
    }
}

/** Refuse a symlink in an unpacked tree whose target lands outside it. */
function assertArchiveLinksContained(string $destination): void
{
    $root = \realpath($destination);
    if ($root === false) {
        throw new \RuntimeException("cannot resolve {$destination}");
    }
    $prefix = \rtrim($root, '/\\') . \DIRECTORY_SEPARATOR;
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($destination, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($items as $item) {
        if (! $item->isLink()) {
            continue;
        }
        $path = $item->getPathname();
        $target = \readlink($path);
        if (! \is_string($target) || $target === '') {
            throw new \RuntimeException("archive link {$path} has no usable target");
        }
        if (\str_starts_with($target, '/') || \preg_match('#^[A-Za-z]:#', $target) === 1) {
            throw new \RuntimeException("archive link {$path} points outside the extraction directory");
        }
        $resolved = \realpath(\dirname($path) . \DIRECTORY_SEPARATOR . $target);
        if ($resolved === false || ($resolved !== $root && ! \str_starts_with($resolved, $prefix))) {
            throw new \RuntimeException("archive link {$path} points outside the extraction directory");
        }
    }
}

/**
 * The runtime inside an unpacked archive, or null when it holds none.
 *
 * An upstream archive usually wraps its tree in a directory — `jdk-21.0.12.1+1/`,
 * `php-8.6.0/` — a macOS JDK nests it one level further at `Contents/Home`, and
 * a Moggi release archive is a whole installation, so its runtime is at
 * `runtime/<backend>`. Each of those is a candidate here; the first one that has
 * the backend's executable is the runtime.
 */
function locateRuntimeTree(string $backend, string $unpacked): ?string
{
    $candidates = [$unpacked . '/runtime/' . $backend, $unpacked];
    foreach (\array_values(\array_diff(\scandir($unpacked) ?: [], ['.', '..'])) as $entry) {
        $directory = $unpacked . '/' . $entry;
        if (! \is_dir($directory) || \is_link($directory)) {
            continue;
        }
        $candidates[] = $directory;
        $candidates[] = $directory . '/Contents/Home';
        $candidates[] = $directory . '/runtime/' . $backend;
    }

    foreach ($candidates as $candidate) {
        if (\is_dir($candidate) && runtimeSourceProblem($backend, $candidate) === null) {
            return $candidate;
        }
    }

    return null;
}

/** What an unpacked archive does hold, so that a refusal can say so. */
function runtimeArchiveProblem(string $backend, string $unpacked): string
{
    $expected = match ($backend) {
        'php' => 'bin/php (or php.exe at its top)',
        'jvm' => 'bin/java and bin/javac',
        default => 'dotnet at its top',
    };
    $top = \array_values(\array_diff(\scandir($unpacked) ?: [], ['.', '..']));
    $listed = $top === [] ? 'nothing' : \implode(', ', \array_slice($top, 0, 8));

    return "  a {$backend} runtime has {$expected}\n  the archive holds: {$listed}";
}

/**
 * Make an unpacked runtime's launchers executable.
 *
 * `tar` keeps the mode a Unix archive recorded, but a zip carries none — Windows
 * never had one to record — so an unpacked `bin/java` can arrive without the bit
 * and fail the start check for a reason that is not the runtime's fault.
 */
function markRuntimeExecutable(string $backend, string $home): void
{
    if (\PHP_OS_FAMILY === 'Windows') {
        return;
    }

    $launchers = $backend === 'jvm'
        ? [$home . '/bin/java', $home . '/bin/javac']
        : [backendExecutable($backend, $home)];
    foreach ($launchers as $launcher) {
        if (\is_file($launcher)) {
            @\chmod($launcher, \fileperms($launcher) | 0111);
        }
    }
}

/**
 * A scratch directory for one install, beside `runtime/` rather than in it.
 *
 * Beside, because nothing may enter `runtime/` before an archive has been
 * checked, and because the two are then on one filesystem: a runtime is moved out
 * of the unpacked tree rather than copied across devices.
 */
function runtimeScratch(string $backend, string $home): string
{
    $scratch = \dirname($home) . '/.runtime-' . $backend . '-' . \bin2hex(\random_bytes(4));
    if (! \mkdir($scratch, 0777, true) && ! \is_dir($scratch)) {
        throw new \RuntimeException("cannot create {$scratch}");
    }

    return $scratch;
}

/** @param array{path: string, version: ?string, size: ?int, changed: bool, replaced: bool, sha256: ?string} $installed */
function reportInstalledRuntime(string $backend, array $installed): int
{
    if (! $installed['changed']) {
        \printf("%s is already installed at %s\n", $backend, $installed['path']);
        echo "nothing to do; --force replaces it\n";

        return 0;
    }

    echo "\n";
    printRuntimeField('installed', $installed['path']);
    printRuntimeField($backend === 'dotnet' ? 'sdk' : 'version', $installed['version']);
    if ($installed['sha256'] !== null) {
        printRuntimeField('sha256', $installed['sha256']);
    }
    \printf("%-13s%.1f MiB\n", 'size', ($installed['size'] ?? 0) / 1048576);
    echo "\nthe next `moggi` invocation finds it in runtime/ ahead of PATH\n";
    if ($backend === 'php') {
        echo "extensions install into it with `moggi runtime php install-ext`\n";
        if ($installed['replaced']) {
            echo "the runtime it replaced was not carried over: its installed extensions are gone with it\n";
        }
    }
    if ($backend === 'jvm') {
        echo "`--native` still needs GraalVM: put `native-image` on PATH, or point JAVA_HOME at one\n";
    }

    return 0;
}

/** The executable a backend's runtime directory has to contain to be one. */
function backendExecutable(string $backend, string $home): string
{
    $suffix = \PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

    return match ($backend) {
        'php' => \PHP_OS_FAMILY === 'Windows' ? $home . '/php.exe' : $home . '/bin/php',
        'jvm' => $home . '/bin/java' . $suffix,
        default => $home . '/dotnet' . $suffix,
    };
}

/** Why `$directory` is not a runtime of `$backend`, or null when it is one. */
function runtimeSourceProblem(string $backend, string $directory): ?string
{
    if (! \is_file(backendExecutable($backend, $directory))) {
        return match ($backend) {
            'php' => "{$directory} is not a PHP runtime: a PHP runtime is a build's prefix, with bin/php"
                . ' (`php.exe` at its top on Windows)',
            'jvm' => "{$directory} is not a JDK: a JDK is a directory with bin/java",
            default => "{$directory} is not a .NET SDK: an SDK is a directory with dotnet at its top",
        };
    }

    if ($backend === 'jvm' && ! \is_file($directory . '/bin/javac' . (\PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))) {
        return "{$directory} is a JRE, not a JDK: the jvm backend compiles with javac, which it does not have";
    }

    return null;
}

/**
 * Why a copied runtime cannot start, or null when it does.
 *
 * This is the difference between an installation and a directory that looks
 * like one: a PHP whose libraries were left behind, a JDK that was never fully
 * unpacked, a `dotnet` for another architecture all copy fine and fail on the
 * first run — which would otherwise be the moment a person finds out.
 */
function runtimeDoesNotStart(string $backend, string $home): ?string
{
    $suffix = \PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
    [$command, $env] = match ($backend) {
        'php' => [[backendExecutable('php', $home), '-n', '-r', 'echo PHP_VERSION;'], null],
        'jvm' => [[$home . '/bin/java' . $suffix, '-version'], null],
        default => [[backendExecutable('dotnet', $home), '--version'], dotnetCliEnv()],
    };

    $result = runProcess($command, $home, $env, false, 120);
    $output = \trim($result['stdout'] . "\n" . $result['stderr']);
    if ($result['exitCode'] === 0 && $output !== '') {
        return null;
    }

    return '  ' . ($output === ''
        ? "`{$command[0]}` exited {$result['exitCode']} without a word"
        : \str_replace("\n", "\n  ", $output));
}

/**
 * Where this machine already has a runtime for `$backend`, as lines of an error.
 *
 * A PHP on PATH is a system install rather than the prefix a program can be
 * copied from, so it is named as the reason `--from` is needed instead of being
 * offered as one.
 */
function suggestRuntimeSource(string $backend): string
{
    $status = backendRuntime($backend);
    if ($backend === 'php') {
        return "  a system `php` is a binary, not the prefix a program can be copied from;\n"
            . '  point --from at an unpacked PHP build (bin/php, ext/)';
    }
    if ($status['runtime'] === null || $status['source'] === 'missing') {
        return "  this machine has no {$backend} runtime to point it at either";
    }

    return "  this machine has one at {$status['runtime']}\n"
        . "    moggi runtime install {$backend} --from {$status['runtime']}";
}
