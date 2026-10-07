<?php declare(strict_types=1);

namespace Moggi\Modules;

use function Moggi\Backend\currentBackend;
use function Moggi\Paths\canonicalSeparators;

/**
 * Output-tree layout for module compilation: where a module's artifact sits under the output root.
 * Spelling and relative-path math live in Moggi\Paths; semantic import/export resolution and
 * stdlib discovery are not here.
 */

function moduleFilenameWarning(string $path, string $moduleName): ?string
{
    $fileBase = pathinfo(basename($path), PATHINFO_FILENAME);
    $parts = explode('.', $moduleName);
    $lastPart = $parts[count($parts) - 1];
    $flatBase = join('-', $parts);

    if ($lastPart === $fileBase || $flatBase === $fileBase) {
        return null;
    }

    return "module name `{$moduleName}` does not match filename `{$fileBase}.mog` "
        . "(expected last segment `{$lastPart}` or flat name `{$flatBase}`) in {$path}";
}

function mogPathToOutputRelative(string $mogPath, string $rootPrefix, ?string $extension = null): string
{
    $relative = outputRelativePath($mogPath, $rootPrefix);
    $extension ??= currentBackend()->extension();

    return preg_replace('/\.mog$/', $extension, $relative) ?? $relative;
}

/**
 * What is left of a source path once the project root is removed, spelled with `/`.
 *
 * A path that is not under the root keeps its own structure rather than losing
 * characters to a prefix it does not have. A closure that spans two roots has no
 * common one at all — `commonPathPrefix` reports the bare separator, and on
 * Windows resolving that lands on a drive root — so a temporary application
 * compiled against a checkout on the other drive is exactly this shape. Dropping
 * the drive leaves a legal relative path, the tree still holds every file, and
 * the paths that depend on the shape (`require`s between modules, the runtime's
 * depth from each file) stay consistent with it, which is all the tree needs.
 */
function outputRelativePath(string $mogPath, string $rootPrefix): string
{
    $path = canonicalSeparators($mogPath);
    $prefix = canonicalSeparators($rootPrefix);
    if ($prefix !== '' && str_starts_with($path, $prefix)) {
        return substr($path, strlen($prefix));
    }

    return ltrim(preg_replace('#^[A-Za-z]:#', '', $path) ?? $path, '/');
}

function projectRootFromPath(string $path): string
{
    $stdlib = configuredStdlibLibPath() ?? locateStdlibRoot($path);
    if ($stdlib !== null) {
        return dirname($stdlib);
    }

    $real = realpath($path);
    if ($real !== false) {
        return dirname($real);
    }

    return dirname($path);
}

function pathWithinProject(string $path, string $projectRoot): string
{
    $real = realpath($path);
    $root = realpath($projectRoot);
    if ($real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        return canonicalSeparators(substr($real, strlen($root) + 1));
    }

    return canonicalSeparators($path);
}
