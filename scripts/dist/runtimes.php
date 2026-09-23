<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/../../src/executables.php';

use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\runProcess as runCompilerProcess;

/**
 * Third-party runtime acquisition for the distributions.
 *
 * One place resolves, downloads, verifies, extracts and validates the runtimes
 * that go into `runtime/`. Versions and asset templates live in
 * `dist/runtimes.json`; the exact resolved URLs and hashes live in
 * `dist/runtimes.lock.json`, written by `scripts/dist/pin.php`, so a build is
 * reproducible and an upstream change is a loud failure instead of a silent
 * substitution.
 *
 * Nothing here ever accepts a runtime for another architecture: the asset is
 * selected from the target's own coordinates, the hash is checked, and the
 * binary is executed to read its version — which a foreign-architecture build
 * cannot pass.
 */

const DIST_ROOT = __DIR__ . '/../../dist';

/** Runtimes a distribution may bundle, in the order they appear in `runtime/`. */
const RUNTIME_NAMES = ['php', 'dotnet', 'jvm', 'graalvm'];

function distRoot(): string
{
    return \realpath(DIST_ROOT) ?: DIST_ROOT;
}

/** @return array<string, mixed> */
function loadRuntimeConfig(): array
{
    $path = distRoot() . '/runtimes.json';
    $raw = \file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("cannot read {$path}");
    }
    $config = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    if (!\is_array($config)) {
        throw new \RuntimeException("{$path} is not a JSON object");
    }

    return $config;
}

/** @return array<string, array{url: string, sha256: string, size?: int, version?: string}> */
function loadRuntimeLock(): array
{
    $path = distRoot() . '/runtimes.lock.json';
    if (!\is_file($path)) {
        throw new \RuntimeException(
            'missing ' . $path . "; run `php scripts/dist/pin.php` to resolve runtime URLs and hashes",
        );
    }
    $raw = \file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("cannot read {$path}");
    }

    /** @var array<string, array{assets: array<string, array{url: string, sha256: string, size?: int, version?: string}>}> $lock */
    $lock = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

    return $lock['assets'];
}

/** @return list<string> */
function knownTargets(array $config): array
{
    return \array_keys($config['targets']);
}

/** Expand `{placeholders}` in an asset template from the target's coordinates. */
function expandAssetTemplate(string $template, string $runtime, string $target, array $config): string
{
    $coords = $config['targets'][$target] ?? throw new \RuntimeException("unknown target {$target}");
    $spec = $config['runtimes'][$runtime];
    $values = [
        'version' => (string) $spec['version'],
        'target' => $target,
        'os' => (string) $coords['os'],
        'arch' => (string) $coords['arch'],
        'dotnetRid' => (string) $coords['dotnetRid'],
        'jvmOs' => (string) $coords['jvmOs'],
        'jvmArch' => (string) $coords['jvmArch'],
        'graalvmAsset' => (string) ($coords['graalvmAsset'] ?? ''),
    ];

    return \preg_replace_callback(
        '/\{([A-Za-z]+)\}/',
        static function (array $m) use ($values, $runtime, $target): string {
            if (!\array_key_exists($m[1], $values)) {
                throw new \RuntimeException("unknown placeholder {{$m[1]}} in the {$runtime} asset for {$target}");
            }

            return $values[$m[1]];
        },
        $template,
    ) ?? $template;
}

/**
 * The asset for one runtime on one target, or null when upstream has no build.
 * Assets may be keyed by target, by platform (when the placeholder set differs),
 * or by the generic `asset`/`assetZip` templates; an entry without a `url` is a
 * selector, not an asset.
 *
 * @return array{format: string, url: string, stripComponents: int, build?: bool, binary: string, lockKey: string}|null
 */
function runtimeAsset(string $runtime, string $target, array $config): ?array
{
    $targetCoords = $config['targets'][$target] ?? throw new \RuntimeException("unknown target {$target}");
    $spec = $config['runtimes'][$runtime];

    if (isset($spec['unsupported'][$target])) {
        return null;
    }

    $asset = $spec[$target] ?? null;
    if (!\is_array($asset) || !isset($asset['url'])) {
        $platform = (string) $targetCoords['phpPlatform'];
        $candidate = $platform !== 'unavailable' ? ($spec[$platform] ?? null) : null;
        $asset = \is_array($candidate) && isset($candidate['url']) ? $candidate : null;
    }
    if ($asset === null) {
        $asset = ($targetCoords['archiveExt'] ?? '') === 'zip'
            ? ($spec['assetZip'] ?? null)
            : ($spec['asset'] ?? null);
    }
    if (!\is_array($asset) || !isset($asset['url'])) {
        return null;
    }

    $url = expandAssetTemplate((string) $asset['url'], $runtime, $target, $config);
    $asset['url'] = $url;
    $asset['format'] = $asset['format'] ?? $targetCoords['archiveExt'];
    $asset['lockKey'] = $runtime . '/' . $target;

    return $asset;
}

/** Why a runtime cannot be shipped for a target, for the build's report. A target
 *  with no PHP (`phpPlatform: unavailable`) still gets its other runtimes. */
function unsupportedReason(string $runtime, string $target, array $config): ?string
{
    $spec = $config['runtimes'][$runtime];
    if (isset($spec['unsupported'][$target])) {
        return (string) $spec['unsupported'][$target];
    }
    $platform = (string) ($config['targets'][$target]['phpPlatform'] ?? '');
    if ($runtime === 'php' && $platform === 'unavailable') {
        return 'no official PHP build for this platform';
    }
    if ($runtime === 'graalvm' && ($config['targets'][$target]['graalvmAsset'] ?? null) === null) {
        return 'no GraalVM build for this platform';
    }

    return null;
}

function httpGet(string $url, ?string $destination = null, bool $followRedirects = true): string
{
    $target = $destination ?? \tempnam(\sys_get_temp_dir(), 'moggi-download-');
    if ($target === false) {
        throw new \RuntimeException('cannot create a download target');
    }

    $out = \fopen($target, 'wb');
    if ($out === false) {
        throw new \RuntimeException("cannot write {$target}");
    }

    $ch = \curl_init($url);
    \curl_setopt_array($ch, [
        \CURLOPT_FILE => $out,
        \CURLOPT_FOLLOWLOCATION => $followRedirects,
        \CURLOPT_MAXREDIRS => 10,
        \CURLOPT_FAILONERROR => false,
        \CURLOPT_USERAGENT => 'moggi-dist-build',
        \CURLOPT_TIMEOUT => 1800,
    ]);
    $ok = \curl_exec($ch);
    $status = (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
    $error = \curl_error($ch);
    unset($ch);
    \fclose($out);

    if ($ok !== true || $status >= 400 || $status === 0) {
        @\unlink($target);
        throw new \RuntimeException("GET {$url} failed: " . ($error !== '' ? $error : "HTTP {$status}"));
    }

    return $target;
}

function sha256File(string $path): string
{
    $hash = \hash_file('sha256', $path);
    if ($hash === false) {
        throw new \RuntimeException("cannot hash {$path}");
    }

    return \strtolower($hash);
}

/**
 * Run a command and return [exitCode, stdout, stderr].
 *
 * `$echo` passes the child's own output through as it arrives, so a build that
 * stops producing output shows which command it stopped at.
 */
function runProcess(
    array $command,
    ?string $cwd = null,
    ?array $env = null,
    bool $echo = false,
    ?int $timeoutSeconds = null,
): array {
    $result = runCompilerProcess($command, $cwd, $env, $echo, $timeoutSeconds);

    return [$result['exitCode'], $result['stdout'], $result['stderr']];
}

function extractArchive(string $archive, string $format, string $destination, int $stripComponents): void
{
    if (!\is_dir($destination) && !\mkdir($destination, 0777, true) && !\is_dir($destination)) {
        throw new \RuntimeException("cannot create {$destination}");
    }

    if ($format === 'zip') {
        $staging = $destination . '.unzip';
        if (\is_dir($staging)) {
            removeTree($staging);
        }
        if (!\mkdir($staging, 0777, true) && !\is_dir($staging)) {
            throw new \RuntimeException("cannot create {$staging}");
        }
        [$code] = runProcess(['unzip', '-q', '-o', $archive, '-d', $staging]);
        if ($code !== 0) {
            [$code, , $err] = runProcess(['tar', '-xf', $archive, '-C', $staging]);
            if ($code !== 0) {
                throw new \RuntimeException("cannot extract {$archive}: {$err}");
            }
        }
        stripTopLevel($staging, $destination, $stripComponents);
        removeTree($staging);

        return;
    }

    $args = ['tar', '-xf', $archive, '-C', $destination];
    if ($stripComponents > 0) {
        $args[] = '--strip-components=' . $stripComponents;
    }
    [$code, , $err] = runProcess($args);
    if ($code !== 0) {
        throw new \RuntimeException("cannot extract {$archive}: {$err}");
    }
}

/** Move the contents of a single wrapper directory up when a zip has one. */
function stripTopLevel(string $staging, string $destination, int $stripComponents): void
{
    if ($stripComponents === 0) {
        moveTree($staging, $destination);

        return;
    }

    $entries = \array_values(\array_diff(\scandir($staging) ?: [], ['.', '..']));
    for ($i = 0; $i < $stripComponents; ++$i) {
        if (\count($entries) !== 1) {
            throw new \RuntimeException("cannot strip {$stripComponents} component(s) from an archive with " . \count($entries) . ' top-level entries');
        }
        $staging .= '/' . $entries[0];
        $entries = \array_values(\array_diff(\scandir($staging) ?: [], ['.', '..']));
    }
    moveTree($staging, $destination);
}

function moveTree(string $from, string $to): void
{
    foreach (\scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        $target = $to . '/' . $entry;
        if (!@\rename($source, $target)) {
            if (\is_dir($source)) {
                moveTree($source, $target);
                @\rmdir($source);
            } else {
                if (!\copy($source, $target)) {
                    throw new \RuntimeException("cannot move {$source} to {$target}");
                }
                @\unlink($source);
            }
        }
    }
}

function removeTree(string $path): void
{
    if (!\is_dir($path)) {
        if (\is_file($path)) {
            @\unlink($path);
        }

        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
    }
    @\rmdir($path);
}

/**
 * Copy a tree, preserving permissions and symlinks.
 *
 * A runtime tree is copied verbatim: JDKs and GraalVM are full of links and
 * executables, and a copied runtime that lost one of either is broken in ways
 * only the compiler's child processes would notice. `$keep` decides what is
 * shipped at all — it sees each entry's path relative to `$from` and its name,
 * and `null` copies everything.
 *
 * @param ?callable(string, string): bool $keep
 */
function copyTree(string $from, string $to, ?callable $keep = null): void
{
    if (!\is_dir($to) && !\mkdir($to, 0777, true) && !\is_dir($to)) {
        throw new \RuntimeException("cannot create {$to}");
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($items as $item) {
        $relative = \substr(\str_replace('\\', '/', $item->getPathname()), \strlen($from) + 1);
        if ($keep !== null && !$keep($relative, $item->getFilename())) {
            continue;
        }
        $target = $to . '/' . $relative;
        if ($item->isDir()) {
            if (!\is_dir($target)) {
                \mkdir($target, 0777, true);
            }
            continue;
        }
        $parent = \dirname($target);
        if (!\is_dir($parent)) {
            \mkdir($parent, 0777, true);
        }
        if ($item->isLink()) {
            @\symlink((string) \readlink($item->getPathname()), $target);
            continue;
        }
        if (!\copy($item->getPathname(), $target)) {
            throw new \RuntimeException("cannot copy {$item->getPathname()}");
        }
        @\chmod($target, $item->getPerms() & 0777);
    }
}

/**
 * Read the version a runtime reports about itself, and assert it is the version
 * the configuration pins. Executing the binary also proves it belongs to the
 * target architecture: a binary for another one cannot run at all.
 */
function runtimeVersionOutput(string $runtime, string $root, string $target, array $config): string
{
    $asset = runtimeAsset($runtime, $target, $config);
    $binary = $root . '/' . ($asset['binary'] ?? '');
    if (!\is_file($binary)) {
        throw new \RuntimeException("{$runtime}: expected binary {$binary} after extraction");
    }
    if (\PHP_OS_FAMILY !== 'Windows') {
        @\chmod($binary, 0755);
    }

    return match ($runtime) {
        'php' => runtimeProcess([$binary, '-n', '-r', 'echo PHP_VERSION, " ", PHP_OS_FAMILY, "-", php_uname("m");']),
        'dotnet' => runtimeProcess([$binary, '--version']),
        'jvm' => runtimeProcess([$binary, '-version']),
        'graalvm' => runtimeProcess([$binary, '--version']),
        default => throw new \RuntimeException("unknown runtime {$runtime}"),
    };
}

function runtimeProcess(array $command): string
{
    [$code, $stdout, $stderr] = runProcess($command);
    if ($code !== 0) {
        throw new \RuntimeException(
            'runtime check failed (' . $code . '): ' . \implode(' ', $command) . ' ' . \trim($stdout . ' ' . $stderr),
        );
    }

    return \trim($stdout . "\n" . $stderr);
}

/**
 * Runtime acquisition: from a pinned, verified upstream asset to a runtime
 * directory ready to be copied into a distribution.
 *
 * Everything is cached under `.dist-cache/` and keyed by the lock entry, so
 * preparing the runtimes for a target happens once and every variant is
 * assembled from the same result — one download and one build per target, at
 * most.
 */

/** Root of the local distribution cache (downloads, builds, prepared runtimes). */
function distCacheRoot(): string
{
    $override = \getenv('MOGGI_DIST_CACHE');
    if (\is_string($override) && $override !== '') {
        return \rtrim($override, '/\\');
    }

    return \dirname(distRoot()) . '/.dist-cache';
}

/** Where a prepared runtime for one target lives. */
function preparedRuntimeDir(string $runtime, string $target): string
{
    return distCacheRoot() . '/runtimes/' . $target . '/' . $runtime;
}

/**
 * Is the cached runtime still the one the lock describes? A rebuilt or
 * re-pinned upstream asset invalidates it, so a build never mixes revisions.
 * The marker records where the usable binary ended up, which a source build
 * moves from upstream's `sapi/cli/php` to `bin/php`.
 */
function runtimeIsPrepared(string $runtime, string $target, array $config): bool
{
    $dir = preparedRuntimeDir($runtime, $target);
    $marker = $dir . '/.prepared';
    if (!\is_file($marker)) {
        return false;
    }
    $recorded = \json_decode((string) \file_get_contents($marker), true);
    $entry = loadRuntimeLock()[$runtime . '/' . $target] ?? null;
    if (!\is_array($recorded) || !\is_array($entry)) {
        return false;
    }

    return ($recorded['hash'] ?? null) === ($entry['sha256'] ?? $entry['sha512'] ?? null)
        && \is_file($dir . '/' . ($recorded['binary'] ?? ''));
}

/**
 * Download the pinned asset into the cache and verify it before it is used.
 *
 * The hash is the only thing standing between "upstream published this" and
 * "some server handed us this", so a mismatch is fatal, never a warning.
 */
function fetchRuntimeAsset(string $runtime, string $target, array $config): array
{
    $lock = loadRuntimeLock();
    $key = $runtime . '/' . $target;
    $entry = $lock[$key] ?? throw new \RuntimeException("no pinned asset for {$key}; run `php scripts/dist/pin.php`");

    $downloads = distCacheRoot() . '/downloads';
    if (!\is_dir($downloads) && !\mkdir($downloads, 0777, true) && !\is_dir($downloads)) {
        throw new \RuntimeException("cannot create {$downloads}");
    }
    $archive = $downloads . '/' . $runtime . '-' . $target . '-' . \basename((string) \parse_url($entry['url'], \PHP_URL_PATH));

    if (!\is_file($archive)) {
        \fwrite(STDOUT, "  download {$entry['url']}\n");
        $partial = $archive . '.part';
        httpGet($entry['url'], $partial);
        if (!\rename($partial, $archive)) {
            throw new \RuntimeException("cannot move {$partial} to {$archive}");
        }
    }

    if (isset($entry['sha256'])) {
        $actual = sha256File($archive);
        if ($actual !== $entry['sha256']) {
            throw new \RuntimeException("sha256 mismatch for {$archive}\n  expected {$entry['sha256']}\n  actually {$actual}");
        }
    } elseif (isset($entry['sha512'])) {
        $actual = \strtolower((string) \hash_file('sha512', $archive));
        if ($actual !== \strtolower((string) $entry['sha512'])) {
            throw new \RuntimeException("sha512 mismatch for {$archive}");
        }
    } else {
        throw new \RuntimeException("{$key} is pinned without a checksum");
    }

    return ['entry' => $entry, 'archive' => $archive];
}

/**
 * Prepare one runtime for one target, and return the directory to copy from.
 *
 * PHP has no upstream binary for Linux or macOS, so the official php.net source
 * release is built with a pinned minimal configuration (this is the only
 * official upstream PHP for those platforms). Every other runtime is an
 * upstream binary archive that is simply unpacked.
 */
function prepareRuntime(string $runtime, string $target, array $config, bool $force = false): string
{
    $dir = preparedRuntimeDir($runtime, $target);
    if (!$force && runtimeIsPrepared($runtime, $target, $config)) {
        stageRuntimeLicense($runtime, $dir, $config);

        return $dir;
    }

    $reason = unsupportedReason($runtime, $target, $config);
    if ($reason !== null) {
        throw new \RuntimeException("{$runtime} is not available for {$target}: {$reason}");
    }

    $fetched = fetchRuntimeAsset($runtime, $target, $config);
    $entry = $fetched['entry'];
    $built = (bool) ($entry['build'] ?? false);

    removeTree($dir);
    if (!\mkdir($dir, 0777, true) && !\is_dir($dir)) {
        throw new \RuntimeException("cannot create {$dir}");
    }

    if ($built) {
        $reported = buildPhpFromSource($runtime, $target, $config, $fetched['archive'], $dir, $entry);
    } else {
        extractArchive($fetched['archive'], (string) $entry['format'], $dir, (int) $entry['stripComponents']);
        $reported = assertRuntimeVersion($runtime, runtimeVersionOutput($runtime, $dir, $target, $config), $config, $target);
    }

    if ($runtime === 'php') {
        assertPhpExtensions($built ? $dir . '/bin/php' : $dir . '/' . $entry['binary'], $dir, $target, $config);
    }

    stageRuntimeLicense($runtime, $dir, $config);

    \file_put_contents(
        $dir . '/.prepared',
        \json_encode([
            'runtime' => $runtime,
            'target' => $target,
            'version' => $entry['version'],
            'hash' => $entry['sha256'] ?? $entry['sha512'] ?? null,
            'url' => $entry['url'],
            'reported' => $reported,
            'built' => $built,
            'binary' => $built ? 'bin/php' : (string) $entry['binary'],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n",
    );

    return $dir;
}

/** The glibc floor the Linux distributions are built and checked against. */
function glibcFloor(array $config): string
{
    return (string) ($config['linuxGlibcFloor'] ?? '2.35');
}

/**
 * The newest `GLIBC_<major>.<minor>` a binary requires, or null if unreadable.
 *
 * Read from the binary's own dynamic symbol table: a build quietly linked
 * against a newer libc is exactly what must not ship, and no amount of care
 * about "where we build" replaces checking the artifact.
 */
function requiredGlibc(string $binary): ?string
{
    $objdump = \Moggi\Compiler\findExecutable('objdump');
    if ($objdump === null || !\is_file($binary)) {
        return null;
    }

    $result = runProcess([$objdump, '-T', $binary]);
    if ($result[0] !== 0) {
        return null;
    }

    $newest = null;
    if (\preg_match_all('/GLIBC_(\d+)\.(\d+)/', $result[1], $matches, \PREG_SET_ORDER) === false) {
        return null;
    }
    foreach ($matches as $match) {
        $version = $match[1] . '.' . $match[2];
        if ($newest === null || \version_compare($version, $newest, '>')) {
            $newest = $version;
        }
    }

    return $newest;
}

/**
 * Refuse a Linux artifact that needs a newer libc than the floor.
 *
 * Only meaningful inside the pinned build root — a build on a newer machine is
 * expected to miss it — so the assembler only calls this when asked to.
 */
function assertGlibcFloor(string $binary, string $floor, string $label): void
{
    $required = requiredGlibc($binary);
    if ($required === null) {
        throw new \RuntimeException("cannot read the glibc requirements of {$label} ({$binary}); is `objdump` installed?");
    }
    if (\version_compare($required, $floor, '>')) {
        throw new \RuntimeException(
            "{$label} requires glibc {$required}, above the {$floor} floor\n"
            . "  build it in a glibc {$floor} image (see .github/workflows/package.yml)",
        );
    }
}

/**
 * Make sure a prepared runtime carries its own licence.
 *
 * Bundling somebody else's runtime means shipping the terms it comes under. The
 * upstream archives bring their licence files along, but the PHP source build and
 * php.net's Windows zip do not, so those are fetched from the upstream licence URL
 * and placed next to the runtime.
 */
function stageRuntimeLicense(string $runtime, string $dir, array $config): void
{
    $license = $config['runtimes'][$runtime]['license'] ?? null;
    if (!\is_array($license)) {
        return;
    }

    $file = (string) ($license['file'] ?? 'LICENSE');
    if (\is_file($dir . '/' . $file)) {
        return;
    }

    $url = $license['url'] ?? null;
    if (!\is_string($url) || $url === '') {
        throw new \RuntimeException("{$runtime} ships no {$file} and no licence URL is configured");
    }

    $downloads = distCacheRoot() . '/downloads';
    if (!\is_dir($downloads) && !\mkdir($downloads, 0777, true) && !\is_dir($downloads)) {
        throw new \RuntimeException("cannot create {$downloads}");
    }
    $cached = $downloads . '/license-' . $runtime . '-' . \basename((string) \parse_url($url, \PHP_URL_PATH));
    if (!\is_file($cached)) {
        httpGet($url, $cached);
    }
    if (!\copy($cached, $dir . '/' . $file)) {
        throw new \RuntimeException("cannot copy the {$runtime} licence into place");
    }
}

/** The PHP extensions Moggi's compiler actually uses. */
function requiredPhpExtensions(array $config): array
{
    return (array) $config['runtimes']['php']['extensions'];
}

/**
 * Make a PHP runtime usable, and prove that it is.
 *
 * An extension may be compiled into the PHP binary or shipped as a DLL that an
 * ini has to name — and which is which differs between php.net's Windows build
 * and a source build, and has changed between PHP releases (`bcmath` and `ctype`
 * are compiled in on Windows 8.5, `mbstring` and `zip` are not). Guessing is how
 * a distribution ends up broken on one platform, so this asks the runtime what it
 * has (`php -n -m`), enables exactly the DLLs it actually ships, then loads the
 * result the way the launcher will and asserts every required extension is there.
 */
function assertPhpExtensions(string $binary, string $dir, string $target, array $config): void
{
    $required = requiredPhpExtensions($config);
    $compiledIn = phpModuleList($binary, $dir, ['-n']);

    $enable = [];
    foreach ($required as $extension) {
        if (phpHasModule($compiledIn, $extension)) {
            continue;
        }
        $dll = $dir . '/ext/php_' . $extension . '.dll';
        if (\is_file($dll)) {
            $enable[] = $extension;
        }
    }

    writeBundledPhpIni($dir, $compiledIn, $enable);

    $loaded = phpModuleList($binary, $dir, ['-c', $dir . '/php.ini', '-d', 'extension_dir=' . $dir . '/ext']);
    $missing = \array_values(\array_filter(
        $required,
        static fn (string $extension): bool => !phpHasModule($loaded, $extension),
    ));
    if ($missing !== []) {
        throw new \RuntimeException(
            'the PHP runtime for ' . $target . ' has no ' . \implode(', ', $missing)
            . ' (compiled in: ' . \implode(' ', $compiledIn) . ')',
        );
    }
}

/**
 * Does a `php -m` list carry this extension?
 *
 * PHP spells module names its own way — `Phar` but `zip`, `Bcmath` on Windows — while the pinned
 * configuration names every extension the way the workflow's `extensions:` input does, in lower
 * case. Comparing them byte for byte reports a bundled extension as missing.
 *
 * @param list<string> $modules
 */
function phpHasModule(array $modules, string $extension): bool
{
    foreach ($modules as $module) {
        if (\strcasecmp($module, $extension) === 0) {
            return true;
        }
    }

    return false;
}

/** Modules a PHP binary reports, run without touching the host's configuration. */
function phpModuleList(string $binary, string $dir, array $options): array
{
    if (\PHP_OS_FAMILY === 'Windows') {
        $output = runtimeProcess([$binary, ...$options, '-m']);
    } else {
        $output = runWithLibraryPath([$binary, ...$options, '-m'], $dir . '/lib');
    }

    $modules = [];
    foreach (\explode("\n", $output) as $line) {
        $line = \trim($line);
        // `-m` prints `[PHP Modules]` / `[Zend Modules]` group headers among the names.
        if ($line === '' || ($line[0] === '[' && \str_ends_with($line, ']'))) {
            continue;
        }
        $modules[] = $line;
    }

    return $modules;
}

/**
 * The `php.ini` a bundled PHP reads.
 *
 * The launcher points `PHPRC` at this directory, so this file is the only ini in
 * effect and the host's configuration stays out of the way. On Windows it is
 * load-bearing (PHP loads a DLL only when an ini names it, so `$enable` is the
 * list of DLLs this build really shipped); elsewhere the same extensions are
 * compiled in and the file records the configuration.
 *
 * @param list<string> $compiledIn
 * @param list<string> $enable
 */
function writeBundledPhpIni(string $dir, array $compiledIn, array $enable): void
{
    $lines = [
        '; Written by scripts/dist/assemble.php.',
        '; Configuration of the PHP runtime bundled with Moggi; the launcher sets PHPRC to this',
        '; directory, so no other php.ini takes part.',
        ';',
        '; Compiled in: ' . \implode(' ', $compiledIn),
    ];
    foreach ($enable as $extension) {
        $lines[] = 'extension=php_' . $extension . '.dll';
    }
    $lines[] = '';

    \file_put_contents($dir . '/php.ini', \implode("\n", $lines));
}

/**
 * Build the official php.net source release with the pinned configuration.
 *
 * `configure` and `make` run with the source tree as their working directory rather than through a
 * `chdir` here: changing this process's directory would re-base every relative path the caller still
 * holds, and `MOGGI_DIST_CACHE` is a path a user can give relative.
 */
function buildPhpFromSource(string $runtime, string $target, array $config, string $archive, string $dir, array $entry): string
{
    $spec = $config['runtimes'][$runtime];
    $work = distCacheRoot() . '/build/' . $target . '/' . $runtime . '-' . $spec['version'];
    $source = $work . '/src';

    removeTree($source);
    if (!\mkdir($source, 0777, true) && !\is_dir($source)) {
        throw new \RuntimeException("cannot create {$source}");
    }
    extractArchive($archive, (string) $entry['format'], $source, (int) $entry['stripComponents']);

    $roots = \array_values(\array_filter(\scandir($source) ?: [], static fn (string $n): bool => $n !== '.' && $n !== '..' && \is_dir($source . '/' . $n)));
    if (\count($roots) !== 1) {
        throw new \RuntimeException("expected one source directory in {$source}, found " . \count($roots));
    }
    $tree = $source . '/' . $roots[0];

    $jobs = (string) max(1, cpuCount());
    runOrFail(['./configure', '--prefix=' . $work . '/install', ...(array) $spec['configureFlags']], $tree);
    runOrFail(['make', '-j' . $jobs], $tree);

    if (\is_file($tree . '/LICENSE')) {
        \copy($tree . '/LICENSE', $dir . '/LICENSE');
    }

    $binary = $tree . '/sapi/cli/php';
    if (!\is_file($binary)) {
        throw new \RuntimeException("the source build produced no CLI binary at {$binary}");
    }
    if (!\is_dir($dir . '/bin') && !\mkdir($dir . '/bin', 0777, true) && !\is_dir($dir . '/bin')) {
        throw new \RuntimeException("cannot create {$dir}/bin");
    }
    if (!\copy($binary, $dir . '/bin/php')) {
        throw new \RuntimeException("cannot copy the built PHP binary");
    }
    @\chmod($dir . '/bin/php', 0755);
    stripBinary($dir . '/bin/php');
    copyNonBaselineLibraries($dir . '/bin/php', $dir . '/lib');

    $output = \PHP_OS_FAMILY === 'Windows'
        ? runtimeProcess([$dir . '/bin/php', '-n', '-r', 'echo PHP_VERSION, " ", PHP_OS_FAMILY, "-", php_uname("m");'])
        : runWithLibraryPath([$dir . '/bin/php', '-n', '-r', 'echo PHP_VERSION, " ", PHP_OS_FAMILY, "-", php_uname("m");'], $dir . '/lib');

    return assertRuntimeVersion($runtime, $output, $config, $target);
}

/** Run a command with `$libDir` added to the dynamic loader's search path. */
function runWithLibraryPath(array $command, string $libDir): string
{
    $name = \PHP_OS_FAMILY === 'Darwin' ? 'DYLD_FALLBACK_LIBRARY_PATH' : 'LD_LIBRARY_PATH';
    $env = \getenv();
    $existing = $env[$name] ?? '';
    $env[$name] = $existing === '' ? $libDir : $libDir . ':' . $existing;

    [$code, $stdout, $stderr] = runProcess($command, null, $env);
    if ($code !== 0) {
        throw new \RuntimeException(
            'runtime check failed (' . $code . '): ' . \implode(' ', $command) . ' ' . \trim($stdout . ' ' . $stderr),
        );
    }

    return \trim($stdout . "\n" . $stderr);
}

/**
 * Run a command, echoing it, and abort the build when it fails.
 *
 * `$echo` prints what the command prints, as it prints it.
 */
function runOrFail(array $command, ?string $cwd = null, bool $echo = false, ?int $timeoutSeconds = null): void
{
    $label = \implode(' ', $command);
    \fwrite(STDOUT, '  ' . $label . "\n");
    $started = \microtime(true);
    [$code, $stdout, $stderr] = runProcess($command, $cwd, null, $echo, $timeoutSeconds);
    if ($code !== 0) {
        throw new \RuntimeException(
            "{$label} failed ({$code})\n" . \substr($stdout . "\n" . $stderr, -4000),
        );
    }
    \fwrite(STDOUT, \sprintf('    ok (%.1fs)%s', \microtime(true) - $started, "\n"));
}

function cpuCount(): int
{
    $override = \getenv('MOGGI_DIST_JOBS');
    if (\is_string($override) && $override !== '' && \ctype_digit($override)) {
        return (int) $override;
    }
    if (\PHP_OS_FAMILY === 'Windows') {
        $count = \getenv('NUMBER_OF_PROCESSORS');

        return \is_string($count) && \ctype_digit($count) ? (int) $count : 2;
    }
    [$code, $out] = runProcess(['getconf', '_NPROCESSORS_ONLN']);

    return $code === 0 && \ctype_digit(\trim($out)) ? (int) \trim($out) : 2;
}

function stripBinary(string $binary): void
{
    $strip = findExecutable('strip');
    if ($strip === null) {
        return;
    }
    runProcess([$strip, $binary]);
}

/**
 * Copy the shared libraries a built runtime needs but a host cannot be assumed
 * to have, next to it, so the bundled runtime is self-contained.
 *
 * A source-built PHP links against libzip and oniguruma, which are not part of
 * any base system. System libraries (the C library, libm, the loader, libSystem
 * on macOS) are deliberately not copied: they belong to the host, and shipping
 * a second copy of them is how a distribution breaks in interesting ways. The
 * launcher puts this directory on the runtime's library search path.
 */
function copyNonBaselineLibraries(string $binary, string $libDir): void
{
    if (\PHP_OS_FAMILY === 'Windows') {
        return;
    }

    $baseline = '/^(libc|libm|libdl|librt|libpthread|libgcc_s|libstdc\+\+|libutil|libresolv|libnsl|ld-linux|linux-vdso|libSystem|libc\+\+|libobjc)/';
    $paths = \PHP_OS_FAMILY === 'Darwin'
        ? darwinLinkedLibraries($binary)
        : linuxLinkedLibraries($binary);

    /** @var array<string, string> $copied library name (as referenced) => source path */
    $copied = [];
    foreach ($paths as $path) {
        $name = \basename($path);
        if (\preg_match($baseline, $name) === 1) {
            continue;
        }
        if (!\is_file($path)) {
            if (\PHP_OS_FAMILY === 'Darwin') {
                throw new \RuntimeException("cannot make the bundled PHP portable: the unresolved dependency {$path}");
            }
            continue;
        }
        if (!\is_dir($libDir) && !\mkdir($libDir, 0777, true) && !\is_dir($libDir)) {
            throw new \RuntimeException("cannot create {$libDir}");
        }
        if (!\copy($path, $libDir . '/' . $name)) {
            throw new \RuntimeException("cannot copy {$path}");
        }
        $real = \realpath($path);
        if (\is_string($real) && \basename($real) !== $name && \is_file($real)) {
            @\copy($real, $libDir . '/' . \basename($real));
        }
        $copied[$name] = $path;
    }

    if ($copied === []) {
        return;
    }

    \fwrite(STDOUT, '    bundled libraries: ' . \implode(', ', \array_keys($copied)) . "\n");

    if (\PHP_OS_FAMILY === 'Darwin') {
        relinkDarwinLibraries($binary, $libDir, $copied);
    }
}

/**
 * Make the copied libraries resolvable on macOS.
 *
 * A dylib is referenced by its `install_name`, which a build of libzip or
 * oniguruma sets to an absolute path under the build prefix — and unlike Linux,
 * `DYLD_FALLBACK_LIBRARY_PATH` does not override that, so a copy beside the
 * binary would be ignored and the runtime would only work on a machine that has
 * the build prefix. Every reference is therefore rewritten to a path relative to
 * the file that makes it, which needs no rpath and no environment variable.
 *
 * @param array<string, string> $copied library name => source path
 */
function relinkDarwinLibraries(string $binary, string $libDir, array $copied): void
{
    $tool = findExecutable('install_name_tool');
    if ($tool === null) {
        throw new \RuntimeException('macOS needs install_name_tool (part of the Xcode command line tools) to make the bundled PHP portable');
    }

    foreach ($copied as $name => $source) {
        $copy = $libDir . '/' . $name;
        foreach (darwinLinkedLibraries($copy) as $dependency) {
            $base = \basename($dependency);
            if (!isset($copied[$base]) || $dependency === '@loader_path/' . $base) {
                continue;
            }
            runProcess([$tool, '-change', $dependency, '@loader_path/' . $base, $copy]);
        }
        runProcess([$tool, '-id', '@loader_path/' . $name, $copy]);
        unset($source);
    }

    foreach ($copied as $name => $source) {
        runProcess([$tool, '-change', $source, '@loader_path/../lib/' . $name, $binary]);
    }
}

/** @return list<string> */
function linuxLinkedLibraries(string $binary): array
{
    [$code, $stdout] = runProcess(['ldd', $binary]);
    if ($code !== 0) {
        return [];
    }
    $paths = [];
    foreach (\explode("\n", $stdout) as $line) {
        if (\preg_match('/=>\s+(\S+)/', $line, $m) === 1) {
            $paths[] = $m[1];
        }
    }

    return $paths;
}

/** @return list<string> */
function darwinLinkedLibraries(string $binary): array
{
    [$code, $stdout] = runProcess(['otool', '-L', $binary]);
    if ($code !== 0) {
        return [];
    }
    $paths = [];
    foreach (\explode("\n", $stdout) as $line) {
        $trimmed = \trim($line);
        if ($trimmed === '' || \str_ends_with($trimmed, ':')) {
            continue;
        }
        $path = \preg_split('/\s+\(/', $trimmed)[0] ?? '';
        if (\str_starts_with($path, '/usr/lib/') || \str_starts_with($path, '/System/')) {
            continue;
        }
        $paths[] = $path;
    }

    return $paths;
}

/** Assert the reported version matches the pinned one, and say what was found. */
function assertRuntimeVersion(string $runtime, string $output, array $config, string $target): string
{
    $expected = (string) $config['runtimes'][$runtime]['version'];
    $head = \trim(\explode("\n", \trim($output))[0]);
    $matches = match ($runtime) {
        'php' => \str_starts_with($head, $expected),
        'dotnet' => $head === $expected,
        'jvm' => \str_contains($head, '"' . $expected . '.') || \preg_match('/version "' . \preg_quote($expected, '/') . '[."]/', $head) === 1,
        'graalvm' => \str_contains($head, $expected),
        default => true,
    };
    if (!$matches) {
        throw new \RuntimeException(
            "{$runtime} for {$target} reports `{$head}`, expected {$expected}",
        );
    }

    return $head;
}
