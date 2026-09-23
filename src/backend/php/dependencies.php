<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Dependencies;

use function Moggi\Modules\configuredStdlibLibPath;

/**
 * Library-owned PHP dependency directories: `php/` next to a backend module's
 * `.mog` sources, mirroring the `jvm` (vendored jars) and `dotnet` (CLR
 * metadata) dependency directories.
 *
 * A library may bundle PHP sources its backend module needs; those live in the
 * module's sibling `php/` directory. External packages stay the job of Composer
 * — this only covers code a library ships itself, and never reimplements a
 * dependency solver.
 */

/** Absolute path of the stdlib `lib` root, when it can be resolved. */
function stdlibLibRoot(): ?string
{
    $lib = configuredStdlibLibPath();
    if ($lib !== null && $lib !== '') {
        return $lib;
    }
    $fallback = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lib';

    return \is_dir($fallback) ? $fallback : null;
}

/**
 * Absolute paths of every `php` dependency directory under the stdlib root.
 * Generic discovery, analogous to the JVM `jvm/` directory scan.
 *
 * @return list<string>
 */
function discoverVendorPhpDirs(): array
{
    $lib = stdlibLibRoot();
    if ($lib === null) {
        return [];
    }

    $dirs = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $dir = $file->getPath();
        if (basename($dir) !== 'php') {
            continue;
        }
        $dirs[$dir] = true;
    }

    $dirs = \array_keys($dirs);
    \sort($dirs);

    return $dirs;
}

/**
 * The `php/` dependency directory owned by one backend module, if any.
 *
 * Ownership is by directory: `lib/<path>/PHP.mog` owns `lib/<path>/php/`. Only
 * the PHP-backend module pulls in PHP helpers, so a sibling `.mog` in the same
 * directory (a JVM or .NET variant) never does.
 */
function phpCompanionDirForModule(string $sourcePath): ?string
{
    $stem = \pathinfo($sourcePath, \PATHINFO_FILENAME);
    if (\strcasecmp($stem, 'php') !== 0) {
        return null;
    }
    $dir = \dirname($sourcePath) . \DIRECTORY_SEPARATOR . 'php';

    return \is_dir($dir) ? $dir : null;
}

/**
 * Sorted absolute paths of the PHP sources a backend module bundles.
 *
 * @return list<string>
 */
function phpCompanionFilesForModule(string $sourcePath): array
{
    $dir = phpCompanionDirForModule($sourcePath);
    if ($dir === null) {
        return [];
    }
    $files = \glob($dir . \DIRECTORY_SEPARATOR . '*.php') ?: [];
    \sort($files);

    return $files;
}
