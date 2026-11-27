<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Store;

use function Moggi\Cache\runtimeCacheDir;
use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\runProcess;
use function Moggi\Install\installationRoot;
use function Moggi\Registry\normalizeExtensionName;
use function Moggi\Registry\parsePhpExtensionEntry;

/**
 * The extensions installed into the bundled PHP.
 *
 * A distribution ships a PHP whose extension set is fixed at build time, and no
 * project can be asked to rebuild the compiler to add one. So the bundled
 * runtime is the one place a project's extensions go: `moggi runtime php
 * install-ext` compiles one against *that* runtime's `php-config` (through PIE),
 * writes the `.so` into its `ext/` directory, enables it in its `php.ini`, and
 * records it in `extensions.json` beside the binary.
 *
 * Three files, three jobs, and none of them is a cache:
 *
 *   - `extensions.json` — what this runtime has, so `--native` and a deploy can
 *     compare their own sets with it;
 *   - `php.ini` — what this runtime loads, so the probe and the emitted program
 *     agree;
 *   - `php-ext.lock` (project) — what this project asked for, so a teammate or a
 *     CI run gets the same set instead of whatever the machine happens to have.
 *
 * A micro SAPI `bin/moggi` cannot be the host here (no CLI, no `dl()`), which is
 * why everything goes through `runtime/php`.
 */

/** The file a prepared runtime records its extension set in, beside its binary. */
const EXTENSION_MANIFEST = 'extensions.json';

/** What `moggi runtime php` writes above the ini lines it owns. */
const INI_MARKER = '; Written by `moggi runtime php`';

/** The project file that pins the extensions a build asked for. */
const PROJECT_LOCK = 'php-ext.lock';

/**
 * The bundled PHP's home directory.
 *
 * A PHP-less variant (`moggi-minimal`, `moggi-jvm`, `moggi-dotnet`) has no home
 * to install into and says so, rather than pretending an extension is available
 * because the host machine happens to have it.
 */
function phpHome(): string
{
    $root = installationRoot();
    $home = $root === null ? null : $root . '/runtime/php';
    if ($home === null || ! \is_dir($home)) {
        throw new \RuntimeException(
            'this installation bundles no PHP, so it has no extensions to add'
            . ' — use a distribution that bundles PHP (moggi, moggi-php)',
        );
    }

    return $home;
}

function phpBinary(string $home): string
{
    return $home . (\PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/bin/php');
}

/** The bundled PHP's `php-config`, which is what PIE builds against. */
function phpConfig(string $home): string
{
    return $home . '/bin/php-config';
}

function phpIniPath(string $home): string
{
    return $home . '/php.ini';
}

function extensionDir(string $home): string
{
    return $home . '/ext';
}

/** Whether a string can be a PHP extension name at all. */
function isExtensionName(string $name): bool
{
    return \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
}

/** An extension file name as the manifest records it: no directory, never empty. */
function manifestFileName(string $name): string
{
    $name = \basename(\str_replace('\\', '/', $name));

    return \preg_match('/^[A-Za-z0-9_.+-]+$/', $name) === 1 ? $name : '';
}

function extensionFileName(string $name): string
{
    return \PHP_OS_FAMILY === 'Windows' ? 'php_' . $name . '.dll' : $name . '.so';
}

/**
 * The manifest of what the bundled runtime carries.
 *
 * `extensions` is the list `runtimeExtensionsAt()` and `--native` compare
 * against; `installed` adds what a person wants to know (version, which ini line,
 * where the file came from) without making the list shape depend on it;
 * `unavailable` is what a prepared copy found it could not load, so reusing that
 * copy reports the same thing its build did.
 *
 * Only module names count: an older manifest written while a startup warning was
 * mixed into `php -m` holds whole warning lines, and reading one back as an
 * extension makes every later check ask for it.
 *
 * @return array{php: string, abi: array{version: string, zts: bool}, extensions: list<string>, installed: array<string, array<string, mixed>>, unavailable: list<string>}
 */
function manifest(string $home): array
{
    $file = $home . '/' . EXTENSION_MANIFEST;
    $recorded = \is_file($file) ? \json_decode((string) \file_get_contents($file), true) : null;
    if (! \is_array($recorded)) {
        $recorded = [];
    }

    $names = [];
    foreach ((array) ($recorded['extensions'] ?? []) as $name) {
        if (isExtensionName((string) $name)) {
            $names[normalizeExtensionName((string) $name)] = (string) $name;
        }
    }

    $installed = [];
    foreach ((array) ($recorded['installed'] ?? []) as $name => $entry) {
        if (isExtensionName((string) $name)) {
            $installed[normalizeExtensionName((string) $name)] = (array) $entry;
        }
    }

    $abi = (array) ($recorded['abi'] ?? []);
    $unavailable = \array_values(\array_filter(\array_map(
        static fn ($name): string => manifestFileName((string) $name),
        (array) ($recorded['unavailable'] ?? []),
    )));

    return [
        'php' => (string) ($recorded['php'] ?? ''),
        'abi' => [
            'version' => (string) ($abi['version'] ?? ''),
            'zts' => (bool) ($abi['zts'] ?? false),
        ],
        'extensions' => \array_values($names),
        'installed' => $installed,
        'unavailable' => $unavailable,
    ];
}

/** @param array{php?: string, abi?: array{version?: string, zts?: bool}, extensions?: list<string>, installed?: array<string, array<string, mixed>>, unavailable?: list<string>} $record */
function writeManifest(string $home, array $record): void
{
    $names = [];
    foreach ($record['extensions'] ?? [] as $name) {
        if (isExtensionName((string) $name)) {
            $names[normalizeExtensionName((string) $name)] = (string) $name;
        }
    }
    $installed = [];
    foreach ($record['installed'] ?? [] as $name => $entry) {
        if (isExtensionName((string) $name)) {
            $installed[(string) $name] = $entry;
        }
    }
    \ksort($installed, \SORT_STRING);
    $unavailable = \array_values(\array_filter(\array_map(
        static fn ($name): string => manifestFileName((string) $name),
        $record['unavailable'] ?? [],
    )));
    \sort($unavailable, \SORT_STRING);

    $json = \json_encode([
        'runtime' => 'php',
        'php' => (string) ($record['php'] ?? ''),
        'abi' => [
            'version' => (string) ($record['abi']['version'] ?? ''),
            'zts' => (bool) ($record['abi']['zts'] ?? false),
        ],
        'extensions' => \array_values($names),
        'installed' => $installed,
        'unavailable' => $unavailable,
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

    if (\file_put_contents($home . '/' . EXTENSION_MANIFEST, $json . "\n") === false) {
        throw new \RuntimeException('cannot write ' . $home . '/' . EXTENSION_MANIFEST);
    }
}

/**
 * What the bundled PHP is, as the manifest records it.
 *
 * The ABI is not decoration: a `.so` built for PHP 8.6 ZTS does not load into
 * another PHP, so an upgrade of the runtime invalidates every installed
 * extension, and the manifest is where that fact is kept.
 *
 * @return array{version: string, zts: bool}
 */
function hostAbi(string $home): array
{
    $script = 'echo PHP_VERSION, " ", PHP_ZTS ? "zts" : "nts";';
    $result = runProcess([phpBinary($home), '-n', '-r', $script]);
    if ($result['exitCode'] !== 0) {
        throw new \RuntimeException('cannot ask the bundled PHP for its version: ' . \trim($result['stderr']));
    }
    $fields = \preg_split('/\s+/', \trim($result['stdout'])) ?: [];

    return ['version' => (string) ($fields[0] ?? ''), 'zts' => ($fields[1] ?? '') === 'zts'];
}

/**
 * Record one installed extension, folding the name the way PHP does.
 *
 * The manifest is initialised with the runtime's ABI the first time it is
 * written, so a stale entry can be told apart from a current one.
 */
function recordExtension(string $home, string $name, ?string $version, ?string $sha256, string $source): void
{
    $record = manifest($home);
    $record['extensions'][] = $name;
    $record['installed'][normalizeExtensionName($name)] = [
        'name' => $name,
        'version' => $version,
        'sha256' => $sha256,
        'ini' => extensionFileName($name),
        'source' => $source,
    ];
    if ($record['abi']['version'] === '' || $record['php'] === '') {
        $abi = hostAbi($home);
        $record['abi'] = $abi;
        $record['php'] = $abi['version'];
    }

    writeManifest($home, $record);
}

/** Forget one extension; the `.so` stays unless the caller purges it. */
function forgetExtension(string $home, string $name): bool
{
    $record = manifest($home);
    $key = normalizeExtensionName($name);
    if (! isset($record['installed'][$key])) {
        return false;
    }
    unset($record['installed'][$key]);
    $record['extensions'] = \array_values(\array_filter(
        $record['extensions'],
        static fn (string $entry): bool => normalizeExtensionName($entry) !== $key,
    ));
    writeManifest($home, $record);

    return true;
}

/**
 * The ini line that loads an installed extension, and the directory it lives in.
 *
 * `extension_dir` is written through `${PHPRC}` for the same reason packaging
 * does: the runtime is relocatable, and an absolute build-cache path would make
 * every extension vanish the moment the installation moved.
 */
function enableExtension(string $home, string $name): void
{
    $ini = phpIniPath($home);
    $lines = \is_file($ini) ? \explode("\n", (string) \file_get_contents($ini)) : [INI_MARKER];
    $file = extensionFileName($name);

    $lines = \array_values(\array_filter(
        $lines,
        static fn (string $line): bool => \preg_match('/^\s*extension\s*=\s*"?(?:.*\/)?' . \preg_quote($file, '/') . '"?\s*$/i', $line) !== 1,
    ));

    $hasExtensionDir = false;
    foreach ($lines as $line) {
        if (\preg_match('/^\s*extension_dir\s*=/', $line) === 1) {
            $hasExtensionDir = true;

            break;
        }
    }
    if (! $hasExtensionDir) {
        $lines[] = 'extension_dir="${PHPRC}/ext"';
    }

    while ($lines !== [] && \trim((string) \end($lines)) === '') {
        \array_pop($lines);
    }
    $lines[] = 'extension=' . $file;
    $lines[] = '';

    if (\file_put_contents($ini, \implode("\n", $lines)) === false) {
        throw new \RuntimeException("cannot write {$ini}");
    }
}

/** Drop the ini lines that load an extension, leaving the rest untouched. */
function disableExtension(string $home, string $name): bool
{
    $ini = phpIniPath($home);
    if (! \is_file($ini)) {
        return false;
    }
    $lines = \explode("\n", (string) \file_get_contents($ini));
    $file = \preg_quote(extensionFileName($name), '/');
    $kept = [];
    $removed = false;
    foreach ($lines as $line) {
        if (\preg_match('/^\s*extension\s*=\s*"?(?:.*\/)?' . $file . '"?\s*$/i', $line) === 1) {
            $removed = true;

            continue;
        }
        $kept[] = $line;
    }
    if ($removed) {
        \file_put_contents($ini, \implode("\n", $kept));
    }

    return $removed;
}

/**
 * Whether the bundled PHP actually loads an extension, asked of the runtime
 * rather than of the file system: an ini line that names a missing or
 * ABI-mismatched `.so` is not an installed extension.
 *
 * @return array{loaded: bool, stderr: string}
 */
function extensionLoads(string $home, string $name): array
{
    $script = 'echo extension_loaded(' . \var_export($name, true) . ') ? "yes" : "no";';
    $ini = phpIniPath($home);
    $command = [phpBinary($home)];
    if (\is_file($ini)) {
        $command[] = '-c';
        $command[] = $ini;
    } else {
        $command[] = '-n';
    }
    $command[] = '-d';
    $command[] = 'extension_dir=' . extensionDir($home);
    $command[] = '-r';
    $command[] = $script;
    $result = runProcess($command);

    return ['loaded' => $result['exitCode'] === 0 && \trim($result['stdout']) === 'yes', 'stderr' => \trim($result['stderr'])];
}

/**
 * The file PIE built, wherever this PHP's layout puts it.
 *
 * PIE writes into `extension_dir`; a source build's is
 * `lib/php/extensions/<api>/`, a Windows zip's is `ext/`. Searching both is the
 * difference between "PIE succeeded" and "the runtime has it".
 */
function builtExtensionFile(string $home, string $name): ?string
{
    $candidates = \array_merge(
        \glob($home . '/lib/php/extensions/*/' . extensionFileName($name)) ?: [],
        \glob($home . '/ext/' . extensionFileName($name)) ?: [],
        \glob($home . '/lib/php/extensions/*/' . $name . '.so') ?: [],
        \glob($home . '/ext/' . $name . '.dll') ?: [],
    );

    return $candidates[0] ?? null;
}

/** Move a built extension into the runtime's `ext/`, where its ini expects it. */
function placeExtension(string $home, string $name, string $built): string
{
    $dir = extensionDir($home);
    if (! \is_dir($dir) && ! \mkdir($dir, 0777, true) && ! \is_dir($dir)) {
        throw new \RuntimeException("cannot create {$dir}");
    }
    $destination = $dir . '/' . \basename($built);
    if (\realpath($built) !== \realpath($destination) && ! \copy($built, $destination)) {
        throw new \RuntimeException("cannot copy {$built} into {$dir}");
    }

    return $destination;
}

/**
 * PIE, the extension installer the distribution already uses for `ext-parallel`.
 *
 * A pinned `runtime/pie/pie.phar` wins, so a distribution can ship the installer
 * it was tested with; `MOGGI_PIE` and `PATH` are the development escapes.
 */
function resolvePie(): ?string
{
    $root = installationRoot();
    if ($root !== null && \is_file($root . '/runtime/pie/pie.phar')) {
        return $root . '/runtime/pie/pie.phar';
    }
    $configured = \getenv('MOGGI_PIE');
    if (\is_string($configured) && $configured !== '' && \is_file($configured)) {
        return $configured;
    }

    return findExecutable('pie') ?? findExecutable('pie.phar');
}

/**
 * The command that runs PIE: a phar needs a PHP, a binary does not.
 *
 * @return list<string>
 */
function pieCommand(string $pie): array
{
    if (\str_ends_with($pie, '.phar') || \str_ends_with($pie, '.php')) {
        return [\PHP_BINARY === '' ? phpBinary(phpHome()) : \PHP_BINARY, $pie];
    }

    return [$pie];
}

/**
 * The tools PIE needs before it can compile anything.
 *
 * A missing compiler is a missing tool, so the error names it here — before PIE
 * starts and prints a configure log no Moggi programmer should have to read.
 *
 * @return list<string> the missing tools, with the package that provides them
 */
function missingBuildTools(): array
{
    if (\PHP_OS_FAMILY === 'Windows') {
        return findExecutable('cl') === null && findExecutable('clang') === null && findExecutable('gcc') === null
            ? ['a C compiler (Visual Studio Build Tools, or clang/gcc)']
            : [];
    }

    $missing = [];
    if (findExecutable('cc') === null && findExecutable('clang') === null && findExecutable('gcc') === null) {
        $missing[] = 'a C compiler (build-essential / Xcode command line tools)';
    }
    foreach (['make', 'autoconf', 'pkg-config'] as $tool) {
        if (findExecutable($tool) === null) {
            $missing[] = "`{$tool}` (build-essential / Xcode command line tools)";
        }
    }

    return $missing;
}

/** The project's declared `[php.extensions]`, from the descriptor at `$dir`. */
function declaredExtensions(string $dir): array
{
    $descriptors = \glob(\rtrim($dir, '/') . '/*.moggi') ?: [];
    if (\count($descriptors) !== 1) {
        return [];
    }

    $ini = @\parse_ini_string((string) \file_get_contents($descriptors[0]), true, \INI_SCANNER_RAW);
    if (! \is_array($ini)) {
        return [];
    }

    $names = [];
    foreach (\array_keys((array) ($ini['php.extensions'] ?? [])) as $entry) {
        $name = parsePhpExtensionEntry((string) $entry)['name'];
        if ($name !== '') {
            $names[normalizeExtensionName($name)] = $name;
        }
    }

    return \array_values($names);
}

/**
 * The declaration side of the project lock: nothing about what is installed,
 * only whether the lock pins exactly what the descriptor declares.
 *
 * A project with no lock has nothing to drift from — `php-ext.lock` is created by
 * the first `moggi runtime php install-ext`, so existing projects that declare
 * keep compiling unchanged. Once the file exists it is enforced, because that is
 * the promise a teammate and a CI run rely on.
 *
 * @param list<string> $declared
 * @return list<string>
 */
function lockDrift(string $dir, array $declared): array
{
    $path = \rtrim($dir, '/') . '/' . PROJECT_LOCK;
    if (! \is_file($path)) {
        return [];
    }

    $lock = \json_decode((string) \file_get_contents($path), true);
    $entries = \is_array($lock) ? (array) ($lock['extensions'] ?? []) : [];
    $declaredKeys = \array_map(normalizeExtensionName(...), $declared);

    $problems = [];
    foreach ($declared as $name) {
        if (! isset($entries[normalizeExtensionName($name)])) {
            $problems[] = "`{$name}` is declared but not pinned in " . PROJECT_LOCK . " — run `moggi runtime php install-ext {$name}`";
        }
    }
    foreach (\array_keys($entries) as $key) {
        if (! \in_array(normalizeExtensionName((string) $key), $declaredKeys, true)) {
            $problems[] = "`{$key}` is pinned in " . PROJECT_LOCK . ' but no longer declared'
                . " — run `moggi runtime php remove-ext {$key}` to drop it from both";
        }
    }

    return $problems;
}

/**
 * The shared cache key of a runtime: what it is, on what platform, carrying what.
 *
 * One scheme for every prepared runtime, because it is one question: "has this
 * exact runtime already been built?". `php-native` keys a static runtime for an
 * extension set — the reason `--native` is not a rebuild per checkout; a
 * portable `php` runtime keys its copy of the bundled one the same way, with the
 * version of the runtime itself rather than the compiler's.
 *
 * @param list<string> $extensions
 */
function runtimeKey(string $kind, array $extensions, ?string $version = null): string
{
    $set = \array_map(normalizeExtensionName(...), $extensions);
    \sort($set, \SORT_STRING);

    return 'sha256:' . \hash('sha256', \implode('|', [
        $kind,
        \PHP_OS_FAMILY . '-' . \php_uname('m'),
        $version ?? \PHP_VERSION,
        \implode(',', $set),
    ]));
}

/**
 * The shared cache slot for a micro runtime built with exactly `$extensions`.
 *
 * Nothing populates this from an installation (building a micro runtime is
 * release work); a release build or a manually placed runtime does, and
 * `runtime php native` uses it when it is there.
 *
 * @param list<string> $extensions
 */
function nativeRuntimeKey(array $extensions): string
{
    return runtimeKey('php-native', $extensions);
}

/** Where a cached runtime for an extension set would sit, whether or not it does. */
function cachedNativeRuntimePath(array $extensions): string
{
    return runtimeCacheDir() . '/php-native/' . \substr(nativeRuntimeKey($extensions), 7) . '/micro.sfx';
}

/**
 * Build one extension from a local source tree with the bundled runtime's own
 * `phpize`.
 *
 * This is the path that does not need PIE or a network: the runtime ships
 * `phpize`, `php-config` and `include/php` for exactly this, and an extension
 * built here is built against the same PHP the compiler verifies with, which is
 * the property that matters. PIE remains the default because it resolves a
 * version for you; `--from` is for an extension that is already on disk (a
 * private one, a checkout, an air-gapped machine).
 *
 * The tree is copied first: `phpize` rewrites the source directory, and a build
 * must not leave a checkout in pieces. Only the artifact is kept, under the name
 * the build gave it — a build tree is tens of megabytes that nothing reads again,
 * while PHP loads `<name>.so`, so a renamed artifact is one the ini can never
 * find.
 */
function buildExtensionFromSource(string $home, string $name, string $source): string
{
    if (\PHP_OS_FAMILY === 'Windows') {
        throw new \RuntimeException('building an extension from source is not supported on Windows; PIE uses prebuilt DLLs there');
    }
    if (! \is_dir($source)) {
        throw new \RuntimeException("--from expects an extension source directory: {$source}");
    }

    $phpize = $home . '/bin/phpize';
    $config = phpConfig($home);
    if (! \is_file($phpize) || ! \is_file($config)) {
        throw new \RuntimeException(
            "the bundled PHP ships no phpize or php-config, so it cannot build extensions from source\n"
            . '  install a distribution whose runtime was built from source (moggi, moggi-php on Linux and macOS)',
        );
    }
    $make = findExecutable('make') ?? findExecutable('gmake');
    if ($make === null) {
        throw new \RuntimeException('building an extension from source needs `make` on PATH');
    }

    $work = \sys_get_temp_dir() . '/moggi-ext-' . \preg_replace('/[^a-z0-9_]/', '', \strtolower($name)) . '-' . \bin2hex(\random_bytes(4));
    copySourceTree($source, $work);

    foreach ([
        [$phpize],
        [$work . '/configure', '--with-php-config=' . $config],
        [$make, '-j4'],
    ] as $command) {
        $result = runProcess($command, $work);
        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException(
                'building `' . $name . '` failed at `' . \basename($command[0]) . "`:\n"
                . \substr(\trim($result['stdout'] . "\n" . $result['stderr']), -3000),
            );
        }
    }

    $built = \glob($work . '/modules/*.so') ?: [];
    if ($built === []) {
        throw new \RuntimeException("the build produced no shared extension in {$work}/modules");
    }

    $artifactDir = \sys_get_temp_dir() . '/moggi-ext-out-' . \bin2hex(\random_bytes(4));
    if (! \mkdir($artifactDir, 0777, true) && ! \is_dir($artifactDir)) {
        throw new \RuntimeException("cannot create {$artifactDir}");
    }
    $artifact = $artifactDir . '/' . \basename($built[0]);
    if (! \copy($built[0], $artifact)) {
        throw new \RuntimeException("cannot copy the built extension out of {$work}");
    }
    removeSourceTree($work);

    return $artifact;
}

/** Recursively delete a temporary source/build tree. */
function removeSourceTree(string $path): void
{
    if (! \file_exists($path) && ! \is_link($path)) {
        return;
    }
    if (\is_dir($path) && ! \is_link($path)) {
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeSourceTree($path . '/' . $entry);
            }
        }
        @\rmdir($path);

        return;
    }
    @\unlink($path);
}

/** Recursively copy a small source tree (the extension sources, not a runtime). */
function copySourceTree(string $from, string $to): void
{
    if (! \is_dir($to) && ! \mkdir($to, 0777, true) && ! \is_dir($to)) {
        throw new \RuntimeException("cannot create {$to}");
    }
    foreach (\scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        $target = $to . '/' . $entry;
        if (\is_dir($source) && ! \is_link($source)) {
            copySourceTree($source, $target);
        } elseif (! \copy($source, $target)) {
            throw new \RuntimeException("cannot copy {$source} to {$target}");
        }
    }
}

/**
 * Write the project's extension lock, for the extensions the bundled PHP now
 * has.
 *
 * The lock is a *subset* of the manifest — only what this project declared — so
 * moving the checkout to another machine asks for that machine to catch up, not
 * for it to reproduce the machine that wrote the file.
 *
 * @param list<string> $declared
 */
function writeProjectLock(string $dir, string $home, array $declared): string
{
    $record = manifest($home);
    $entries = [];
    foreach ($declared as $name) {
        $key = normalizeExtensionName($name);
        $installed = $record['installed'][$key] ?? null;
        if ($installed === null) {
            continue;
        }
        $entries[$key] = [
            'name' => $installed['name'] ?? $name,
            'version' => $installed['version'] ?? null,
            'sha256' => $installed['sha256'] ?? null,
        ];
    }
    \ksort($entries, \SORT_STRING);

    $path = \rtrim($dir, '/') . '/' . PROJECT_LOCK;
    $json = \json_encode([
        'php' => $record['abi']['version'],
        'extensions' => $entries,
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    \file_put_contents($path, $json . "\n");

    return $path;
}

/**
 * The declared extensions the project lock does not pin, or that the bundled PHP
 * no longer provides.
 *
 * A project without a lock is not drifted: `php-ext.lock` is written by the first
 * `moggi runtime php install-ext`, so every project that predates it keeps
 * lock exists, though, it is what the project promised — a compile that resolves
 * a different set is the "works on my machine" failure the lock exists to stop.
 *
 * @param list<string> $declared
 * @return list<string>
 */
function lockProblems(string $dir, string $home, array $declared): array
{
    $path = \rtrim($dir, '/') . '/' . PROJECT_LOCK;
    if (! \is_file($path)) {
        return [];
    }

    $lock = \json_decode((string) \file_get_contents($path), true);
    $entries = \is_array($lock) ? (array) ($lock['extensions'] ?? []) : [];
    $installed = manifest($home);
    $present = [];
    foreach ($installed['extensions'] as $name) {
        $present[normalizeExtensionName($name)] = true;
    }

    $problems = [];
    foreach ($declared as $name) {
        $key = normalizeExtensionName($name);
        if (! isset($entries[$key])) {
            $problems[] = "`{$name}` is declared but not pinned in " . PROJECT_LOCK;
        } elseif (! isset($present[$key])) {
            $problems[] = "`{$name}` is pinned but not installed in the bundled PHP — run `moggi runtime php install-ext {$name}`";
        }
    }

    return $problems;
}
