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
    $relative = substr($mogPath, strlen($rootPrefix));
    $extension ??= currentBackend()->extension();

    return preg_replace('/\.mog$/', $extension, $relative) ?? $relative;
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
