<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/filesystem.php';
require_once __DIR__ . '/phpbuild.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/extensions.php';

/**
 * Acquiring the third-party runtimes a distribution ships.
 *
 * One place resolves, downloads, verifies, extracts and validates the runtimes
 * that go into `runtime/`. Versions and asset templates live in
 * `dist/runtimes.json`; the exact resolved URLs and hashes live in
 * `dist/runtimes.lock.json`, written by `packaging/pin.php`, so a build is
 * reproducible and an upstream change is a loud failure instead of a silent
 * substitution.
 *
 * A runtime is either *downloaded* (an upstream archive, pinned in the lock) or
 * *derived*: its spec carries a `build` recipe instead of an asset, and it is
 * produced for the target by the compiler in `phpbuild.php`. Everything is
 * cached under `.dist-cache`, keyed by the pinned hash or, for a derived runtime,
 * by the content-addressed key in `phpbuild.php`.
 *
 * What this module reads from beside it is a contract of its own in each case:
 * the manifest in `manifest.php`, the filesystem and process primitives in
 * `filesystem.php`, the compiler for a derived runtime in `phpbuild.php`, the
 * extension set a bundled PHP must carry in `extensions.php`, and the platform
 * facts about the binaries that ship (glibc floors, the libraries a from-source
 * PHP links) in `platform.php`.
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
        'composer' => runtimeProcess([\PHP_BINARY, $binary, '--version']),
        'maven' => runtimeProcess([$binary, '--version']),
        'spc' => spcVersion($binary),
        default => throw new \RuntimeException("unknown runtime {$runtime}"),
    };
}

/**
 * Place a runtime that upstream distributes as a bare file rather than an archive.
 *
 * Composer is the only one: it publishes `composer.phar`, with no wrapper
 * directory to strip and nothing to unpack. The file is copied in under the name
 * the lock pins, and the runtime's launcher is generated beside it so the
 * distribution's `PATH` entry is an ordinary executable.
 *
 * @param array<string, mixed> $entry
 */
function installPlainAsset(string $runtime, string $archive, string $dir, array $entry): void
{
    $name = (string) $entry['binary'];
    if ($name === '') {
        throw new \RuntimeException("{$runtime}: the pinned asset names no file");
    }
    if (!\copy($archive, $dir . '/' . $name)) {
        throw new \RuntimeException("cannot copy {$archive} to {$dir}/{$name}");
    }
    @\chmod($dir . '/' . $name, 0644);

    if ($runtime === 'composer') {
        writeComposerShim($dir);
    }
}

/**
 * The `bin/composer` a distribution ships.
 *
 * Composer is a phar, so something has to name a PHP interpreter for it. The
 * shim prefers the PHP bundled beside it — which is what makes a distribution
 * self-contained — and falls back to `php` on `PATH` for a build that carries
 * Composer without PHP. Both spellings are written: the prepared runtime is
 * copied into whichever variant is being assembled, and the two files are a
 * hundred bytes each.
 */
function writeComposerShim(string $dir): void
{
    $bin = $dir . '/bin';
    if (!\is_dir($bin) && !\mkdir($bin, 0777, true) && !\is_dir($bin)) {
        throw new \RuntimeException("cannot create {$bin}");
    }

    $sh = <<<'SH'
        #!/bin/sh
        # Written by packaging/runtimes.php. Runs the bundled Composer on the PHP
        # beside it, so a distribution needs nothing installed; a build that ships
        # this runtime without PHP falls back to the `php` on PATH.
        here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
        php="$here/../../php/bin/php"
        if [ -x "$php" ]; then
            # A PHP built from source carries its libraries (libzip, oniguruma,
            # ICU) in `php/lib`, so the loader has to be told before it runs —
            # the compiler does this for its own children, and a direct run of
            # this shim has to do it for itself.
            lib="$here/../../php/lib"
            if [ -d "$lib" ]; then
                LD_LIBRARY_PATH="$lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"; export LD_LIBRARY_PATH
                DYLD_FALLBACK_LIBRARY_PATH="$lib${DYLD_FALLBACK_LIBRARY_PATH:+:$DYLD_FALLBACK_LIBRARY_PATH}"; export DYLD_FALLBACK_LIBRARY_PATH
            fi
        else
            php=php
        fi
        exec "$php" "$here/../composer.phar" "$@"
        SH;

    $bat = <<<'BAT'
        @echo off
        rem Written by packaging/runtimes.php. Runs the bundled Composer on the PHP
        rem beside it, so a distribution needs nothing installed; a build that ships
        rem this runtime without PHP falls back to the `php` on PATH.
        setlocal
        set "COMPOSER_HOME_DIR=%~dp0.."
        set "COMPOSER_PHP=%COMPOSER_HOME_DIR%\..\php\php.exe"
        if not exist "%COMPOSER_PHP%" set "COMPOSER_PHP=php"
        "%COMPOSER_PHP%" "%COMPOSER_HOME_DIR%\composer.phar" %*
        BAT;

    if (\file_put_contents($bin . '/composer', $sh . "\n") === false) {
        throw new \RuntimeException("cannot write {$bin}/composer");
    }
    @\chmod($bin . '/composer', 0755);
    $bat = \str_replace("\n", "\r\n", $bat);
    if (\file_put_contents($bin . '/composer.bat', $bat . "\r\n") === false) {
        throw new \RuntimeException("cannot write {$bin}/composer.bat");
    }
}

/**
 * The version `spc` reports, from the banner its `--version` prints.
 *
 * `spc` is a self-contained binary with no version command of its own beyond
 * Symfony Console's, so the number is read out of that banner rather than from
 * an exit status — and the pin is what decides whether the build is the one the
 * lock resolved. The banner is matched exactly rather than by pulling the first
 * `x.y.z` out of it, which would as happily return Symfony's own version as
 * `spc`'s.
 */
function spcVersion(string $binary): string
{
    [$code, $stdout, $stderr] = runProcess([$binary, '--version']);
    $text = \trim($stdout . "\n" . $stderr);
    if (\preg_match('/static-php-cli\s+v?([0-9]+\.[0-9]+\.[0-9]+)/i', $text, $match) === 1) {
        return $match[1];
    }

    throw new \RuntimeException(
        "spc printed no version (exit {$code}): " . \substr($text, 0, 200),
    );
}

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

function runtimeIsPrepared(string $runtime, string $target, array $config): bool
{
    $dir = preparedRuntimeDir($runtime, $target);
    $marker = $dir . '/.prepared';
    if (!\is_file($marker)) {
        return false;
    }
    $recorded = \json_decode((string) \file_get_contents($marker), true);
    if (!\is_array($recorded) || !\is_string($recorded['binary'] ?? null) || !\is_file($dir . '/' . $recorded['binary'])) {
        return false;
    }

    if (buildRecipe($runtime, $config) !== null) {
        return ($recorded['key'] ?? null) === derivedRuntimeKey($runtime, $target, $config);
    }

    $entry = loadRuntimeLock()[$runtime . '/' . $target] ?? null;
    if (!\is_array($entry)) {
        return false;
    }

    return ($recorded['hash'] ?? null) === ($entry['sha256'] ?? $entry['sha512'] ?? null);
}

function fetchRuntimeAsset(string $runtime, string $target, array $config): array
{
    $lock = loadRuntimeLock();
    $key = $runtime . '/' . $target;
    $entry = $lock[$key] ?? throw new \RuntimeException("no pinned asset for {$key}; run `php packaging/pin.php`");

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

function prepareRuntime(string $runtime, string $target, array $config, bool $force = false): string
{
    $dir = preparedRuntimeDir($runtime, $target);
    if (!$force && runtimeIsPrepared($runtime, $target, $config)) {
        stageRuntimeLicense($runtime, $dir, $config, $target);

        return $dir;
    }

    $reason = unsupportedReason($runtime, $target, $config);
    if ($reason !== null) {
        throw new \RuntimeException("{$runtime} is not available for {$target}: {$reason}");
    }

    removeTree($dir);
    if (!\mkdir($dir, 0777, true) && !\is_dir($dir)) {
        throw new \RuntimeException("cannot create {$dir}");
    }

    if (buildRecipe($runtime, $config) !== null) {
        $derived = buildDerivedRuntime($runtime, $target, $config, $dir);
        stageRuntimeLicense($runtime, $dir, $config, $target);
        writePreparedMarker($dir, [
            'runtime' => $runtime,
            'target' => $target,
            'version' => $derived['version'],
            'reported' => $derived['reported'],
            'key' => derivedRuntimeKey($runtime, $target, $config),
            'built' => true,
            'binary' => $derived['binary'],
        ]);

        return $dir;
    }

    $fetched = fetchRuntimeAsset($runtime, $target, $config);
    $entry = $fetched['entry'];
    $built = (bool) ($entry['build'] ?? false);

    if ($built) {
        $reported = buildPhpFromSource($runtime, $target, $config, $fetched['archive'], $dir, $entry);
    } elseif ((string) $entry['format'] === 'raw') {
        installRawAsset($fetched['archive'], $dir, (string) $entry['binary']);
        $reported = assertRuntimeVersion($runtime, runtimeVersionOutput($runtime, $dir, $target, $config), $config, $target);
    } else {
        if ((string) $entry['format'] === 'plain') {
            installPlainAsset($runtime, $fetched['archive'], $dir, $entry);
        } else {
            extractArchive($fetched['archive'], (string) $entry['format'], $dir, (int) $entry['stripComponents']);
            flattenDarwinBundleHome($dir, (string) $entry['binary']);
        }
        $reported = assertRuntimeVersion($runtime, runtimeVersionOutput($runtime, $dir, $target, $config), $config, $target);
    }

    if ($runtime === 'php') {
        $loaded = assertPhpExtensions($built ? $dir . '/bin/php' : $dir . '/' . $entry['binary'], $dir, $target, $config);
        writeRuntimeExtensionManifest($dir, $runtime, $loaded);
    }

    stageRuntimeLicense($runtime, $dir, $config, $target);

    writePreparedMarker($dir, [
        'runtime' => $runtime,
        'target' => $target,
        'version' => $entry['version'],
        'hash' => $entry['sha256'] ?? $entry['sha512'] ?? null,
        'url' => $entry['url'],
        'reported' => $reported,
        'built' => $built,
        'binary' => $built ? 'bin/php' : (string) $entry['binary'],
    ]);

    return $dir;
}

/**
 * Install an asset that *is* the runtime rather than an archive of it.
 *
 * static-php-cli's Windows `spc` is a bare `.exe`, so there is nothing to
 * extract and the downloaded file is the tool.
 */
function installRawAsset(string $archive, string $dir, string $binary): void
{
    if (!\copy($archive, $dir . '/' . $binary)) {
        throw new \RuntimeException("cannot install {$archive} as {$dir}/{$binary}");
    }
    if (\PHP_OS_FAMILY !== 'Windows') {
        @\chmod($dir . '/' . $binary, 0755);
    }
}

/** @param array<string, mixed> $fields */
function writePreparedMarker(string $dir, array $fields): void
{
    \file_put_contents(
        $dir . '/.prepared',
        \json_encode($fields, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n",
    );
}

function stageRuntimeLicense(string $runtime, string $dir, array $config, string $target): void
{
    $license = $config['runtimes'][$runtime]['license'] ?? null;
    if (!\is_array($license)) {
        return;
    }

    $file = (string) ($license['file'] ?? 'LICENSE');
    if (\is_file($dir . '/' . $file)) {
        return;
    }

    $configured = $license['url'] ?? null;
    if (!\is_string($configured) || $configured === '') {
        throw new \RuntimeException("{$runtime} ships no {$file} and no licence URL is configured");
    }
    $url = expandAssetTemplate($configured, $runtime, $target, $config);

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

/** Assert the reported version matches the pinned one, and say what was found. */
function assertRuntimeVersion(string $runtime, string $output, array $config, string $target): string
{
    $expected = (string) $config['runtimes'][$runtime]['version'];
    $head = \trim(\explode("\n", \trim($output))[0]);
    $matches = match ($runtime) {
        'php' => \str_starts_with($head, $expected),
        'php-native' => \str_starts_with($head, 'PHP') && versionSeriesMatches(\substr($head, 3), $expected),
        'spc' => $head === $expected,
        'dotnet' => $head === $expected,
        'jvm' => \str_contains($head, '"' . $expected . '.') || \preg_match('/version "' . \preg_quote($expected, '/') . '[."]/', $head) === 1,
        'graalvm' => \str_contains($head, $expected),
        'composer' => \str_contains($head, 'Composer version ' . $expected),
        'maven' => \str_starts_with($head, 'Apache Maven ' . $expected),
        default => true,
    };
    if (!$matches) {
        throw new \RuntimeException(
            "{$runtime} for {$target} reports `{$head}`, expected {$expected}",
        );
    }

    return $head;
}

/**
 * Whether a reported PHP version is the one a series pins.
 *
 * `8.5` matches `8.5` and `8.5.7`, and must not match `8.50` — so a shorter
 * series is only a match when the next character is the start of a new part.
 */
function versionSeriesMatches(string $reported, string $series): bool
{
    if ($reported === $series) {
        return true;
    }
    if (!\str_starts_with($reported, $series)) {
        return false;
    }

    return \substr($reported, \strlen($series), 1) === '.';
}
