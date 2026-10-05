<?php declare(strict_types=1);

namespace Moggi\Compiler;

use function Moggi\Cache\projectRoot;
use function Moggi\Modules\bundledStdlibLibPath;

/**
 * Version strings for the compiler and its bundled standard library.
 *
 * Both come from plain-text `VERSION` files — the compiler root and the library
 * root — so a release is a one-line edit that needs no code change. Everything
 * that reports a version reads them from here, so there is exactly one number
 * to bump per component.
 */

/**
 * Compiler version, from `<compiler root>/VERSION`.
 *
 * Inside a packaged archive that file carries the build identity rather than the
 * release number: the tag for a release, `0.1.0-dev.20260922+f60fb9f` for a build
 * of an unreleased commit (see `packaging/build-phar.php`).
 */
function compilerVersion(): ?string
{
    return readVersionFile(projectRoot() . '/VERSION');
}

/**
 * `dev` for an unreleased build of a commit, `release` for a packaged tag, and
 * `source` when this is a checkout (`php moggi.php`) rather than an archive.
 */
function compilerChannel(?string $version): string
{
    if (\Moggi\Install\installationRoot() === null) {
        return 'source';
    }

    return $version !== null && \str_contains($version, '-dev.') ? 'dev' : 'release';
}

/** The commit a dev build came from, or null for a release or a checkout. */
function compilerCommit(?string $version): ?string
{
    if ($version === null || \preg_match('/\+([0-9a-f]{7,})/', $version, $match) !== 1) {
        return null;
    }

    return $match[1];
}

/**
 * Which distribution this is, from the runtimes bundled beside the archive, or
 * null when this is not an installed distribution.
 *
 * The micro PHP runtime (`php-native`, see `Moggi\Backend\Php`) is a build input
 * for native executables rather than a backend toolchain: it is never the thing
 * that decides which distribution this is. Neither is `composer` or `maven`,
 * which are host tools rather than backends. The compiler itself is native in
 * every variant, so the PHP that is bundled is a *program* runtime, and its
 * presence and absence are the difference between `moggi-php` and the variants
 * that only target the JVM or .NET.
 */
function distributionVariant(): ?string
{
    $root = \Moggi\Install\installationRoot();
    if ($root === null) {
        return null;
    }

    $has = static fn (string $runtime): bool => \is_dir($root . '/runtime/' . $runtime);

    if (!$has('php') && !$has('dotnet') && !$has('jvm') && !$has('graalvm')) {
        return 'moggi-minimal';
    }
    if ($has('dotnet') && $has('jvm') && $has('graalvm')) {
        return 'moggi';
    }
    if ($has('jvm') && $has('graalvm')) {
        return 'moggi-jvm';
    }
    if ($has('dotnet')) {
        return 'moggi-dotnet';
    }
    if ($has('php')) {
        return 'moggi-php';
    }

    return 'custom';
}

/**
 * Standard-library version, from `<stdlib root>/VERSION`.
 *
 * The root honors `MOGGI_ROOT`, so this reports the stdlib actually in use
 * rather than the one shipped next to the compiler.
 */
function stdlibVersion(): ?string
{
    return readVersionFile((bundledStdlibLibPath() ?? projectRoot() . '/lib') . '/VERSION');
}

/**
 * First non-empty line of a version file, or null when it is absent, unreadable,
 * or blank. Callers decide how to render "no version known".
 */
function readVersionFile(string $path): ?string
{
    if (!\is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!\is_string($raw)) {
        return null;
    }
    $lines = \preg_split('/\R/', $raw, 2);
    $line = \trim(\is_array($lines) ? ($lines[0] ?? '') : '');

    return $line === '' ? null : $line;
}
