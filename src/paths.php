<?php declare(strict_types=1);

namespace Moggi\Paths;

/**
 * Path spelling shared by module preparation and code generation: what a module name is called on
 * this host, and how one output path reaches another. Nothing here knows the module graph, the
 * import graph or any backend's artifact format.
 */

/** A module name as a path on this host: `Foo.Bar` is `Foo` + DIRECTORY_SEPARATOR + `Bar`. */
function moduleNameToPath(string $moduleName): string
{
    $parts = explode('.', $moduleName);

    return join(DIRECTORY_SEPARATOR, $parts);
}

/**
 * The spelling every artifact path uses: `/` on all hosts, because these paths name artifacts,
 * reach generated code and are compared against goldens.
 */
function canonicalSeparators(string $path): string
{
    return DIRECTORY_SEPARATOR === '/' ? $path : \str_replace(DIRECTORY_SEPARATOR, '/', $path);
}

/** Whether a canonical path is anchored (a POSIX `/` or a Windows drive). */
function isAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/') || \preg_match('#^[A-Za-z]:#', $path) === 1;
}

function relativeRequirePath(string $fromOutputRelative, string $toOutputRelative): string
{
    $fromDir = canonicalSeparators(dirname($fromOutputRelative));
    if ($fromDir === '.') {
        $fromParts = [];
    } else {
        $fromParts = explode('/', $fromDir);
    }

    $toParts = explode('/', canonicalSeparators($toOutputRelative));

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
    $path = canonicalSeparators($path);
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

    $resolved = canonicalSeparators($resolved);

    return $tail === [] ? $resolved : rtrim($resolved, '/') . '/' . \implode('/', \array_reverse($tail));
}

/**
 * The path from `$fromFile` to `$toFile`, or the absolute target when the two are anchored on
 * different roots (a Windows temp directory and the project on another drive).
 */
function relativeFilePath(string $fromFile, string $toFile): string
{
    $fromDir = canonicalPath(dirname($fromFile));
    $toPath = canonicalPath($toFile);

    $fromParts = $fromDir === '' ? [] : explode('/', $fromDir);
    $toParts = explode('/', $toPath);
    $sameRoot = ($fromParts[0] ?? null) !== null && ($fromParts[0] ?? null) === ($toParts[0] ?? null);

    while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
        array_shift($fromParts);
        array_shift($toParts);
    }

    if (!$sameRoot && isAbsolutePath($fromDir) && isAbsolutePath($toPath)) {
        return $toPath;
    }

    $relParts = \array_merge(\array_fill(0, count($fromParts), '..'), $toParts);

    return join('/', $relParts);
}

/** The expression a generated `require_once` uses to reach `$toFile` from `$fromFile`. */
function requirePathExpression(string $fromFile, string $toFile): string
{
    $relative = relativeFilePath($fromFile, $toFile);

    return isAbsolutePath($relative) ? \var_export($relative, true) : "__DIR__ . '/{$relative}'";
}
