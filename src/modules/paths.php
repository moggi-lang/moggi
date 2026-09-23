<?php declare(strict_types=1);

namespace Moggi\Modules;

use function Moggi\Backend\currentBackend;

/**
 * Pure filesystem / output-path math for module compilation.
 *
 * These helpers are intentionally separated from semantic import/export
 * resolution and from stdlib/library discovery. They answer where module
 * artifacts live relative to one another, not which names are visible in a
 * module or where the standard library is.
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

function relativeRequirePath(string $fromOutputRelative, string $toOutputRelative): string
{
    $fromDir = \str_replace('\\', '/', dirname($fromOutputRelative));
    if ($fromDir === '.') {
        $fromParts = [];
    } else {
        $fromParts = explode('/', $fromDir);
    }

    $toParts = explode('/', \str_replace('\\', '/', $toOutputRelative));

    while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
        array_shift($fromParts);
        array_shift($toParts);
    }

    $relParts = \array_merge(\array_fill(0, count($fromParts), '..'), $toParts);

    return '__DIR__ . \'/' . join('/', $relParts) . '\'';
}

/**
 * `realpath()` for a path whose own file may not exist yet: the deepest existing ancestor is
 * resolved and the missing tail re-appended. macOS is why this is not just `realpath()` — its
 * temporary directory is reached through `/var`, a symlink to `/private/var`, so a path left
 * unresolved never compares equal to the `realpath()` of the same file.
 */
function canonicalPath(string $path): string
{
    $path = \str_replace('\\', '/', $path);
    $tail = [];
    $candidate = $path;
    while (($resolved = \realpath($candidate)) === false) {
        $parent = dirname($candidate);
        if ($parent === $candidate) {
            return $path;
        }
        $tail[] = basename($candidate);
        $candidate = $parent;
    }

    return $tail === [] ? $resolved : rtrim($resolved, '/') . '/' . \implode('/', \array_reverse($tail));
}

function relativeFilePath(string $fromFile, string $toFile): string
{
    $fromDir = canonicalPath(dirname($fromFile));
    $toPath = canonicalPath($toFile);

    $fromParts = $fromDir === '' ? [] : explode('/', $fromDir);
    $toParts = explode('/', $toPath);

    while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
        array_shift($fromParts);
        array_shift($toParts);
    }

    $relParts = \array_merge(\array_fill(0, count($fromParts), '..'), $toParts);

    return join('/', $relParts);
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
        return \str_replace('\\', '/', substr($real, strlen($root) + 1));
    }

    return \str_replace('\\', '/', $path);
}
