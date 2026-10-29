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
    $parts = \explode('.', $moduleName);

    return \implode(DIRECTORY_SEPARATOR, $parts);
}

/**
 * The spelling every artifact path uses: `/` on all hosts, because these paths name artifacts,
 * reach generated code and are compared against goldens.
 */
function canonicalSeparators(string $path): string
{
    return DIRECTORY_SEPARATOR === '/' ? $path : \str_replace(DIRECTORY_SEPARATOR, '/', $path);
}

/**
 * Directory listing without `glob()`: backslash is an escape character in
 * glob patterns, so a separator-spelled pattern silently matches nothing on
 * Windows (`C:\dir\*.dll` reads `\*` as an escaped star). This lists one
 * directory with `scandir` + `fnmatch(FNM_NOESCAPE)` instead, so `\` never
 * escapes anything: separators are re-spelled up front, duplicates
 * collapse, `*` skips dotfiles, and answers are `/`-spelled and sorted on
 * every host. A trailing slash only marks directories. A wildcard directory
 * recurses one level. Only `GLOB_ONLYDIR`, `GLOB_MARK`
 * and `GLOB_NOSORT` are honored; no caller passes anything else. `**`
 * matches one level, exactly as in `glob()`.
 *
 * @return list<string>
 */
function globPaths(string $pattern, int $flags = 0): array
{
    $prefix = '';
    $rest = \str_replace('\\', '/', $pattern);
    if (\str_starts_with($rest, '//')) {
        $prefix = '//';
        $rest = (string) \substr($rest, 2);
    }
    $rest = (string) \preg_replace('#(?<!:)/+#', '/', $rest);
    $path = $prefix === '' ? $rest : $prefix . \ltrim($rest, '/');
    $markDirs = $path !== '' && $path !== '/' && \str_ends_with($path, '/');
    $base = $markDirs ? (string) \substr($path, 0, -1) : $path;
    $dir = \dirname($base);
    $file = \basename($base);
    $dirs = \strpbrk($dir, '*?[') === false ? [$dir] : globPaths($dir);
    $matchFlags = \FNM_NOESCAPE | (\DIRECTORY_SEPARATOR === '\\' ? \FNM_CASEFOLD : 0);
    $onlyDirs = ($flags & \GLOB_ONLYDIR) !== 0 || $markDirs;
    $mark = $markDirs || ($flags & \GLOB_MARK) !== 0;
    $out = [];
    foreach ($dirs as $d) {
        if (\strpbrk($file, '*?[') === false) {
            $candidate = $d === '.' ? $file : $d . '/' . $file;
            if (@\file_exists($candidate) && (! $onlyDirs || @\is_dir($candidate))) {
                $out[] = $candidate . ($mark && @\is_dir($candidate) ? '/' : '');
            }

            continue;
        }
        if (! @\is_dir($d)) {
            continue;
        }
        $entries = @\scandir($d);
        if (! \is_array($entries)) {
            continue;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (! \str_starts_with($file, '.') && \str_starts_with($entry, '.')) {
                continue;
            }
            if (! \fnmatch($file, $entry, $matchFlags)) {
                continue;
            }
            $candidate = $d === '.' ? $entry : $d . '/' . $entry;
            $isDir = @\is_dir($candidate);
            if ($onlyDirs && ! $isDir) {
                continue;
            }
            $out[] = $isDir && $mark ? $candidate . '/' : $candidate;
        }
    }
    if (($flags & \GLOB_NOSORT) === 0) {
        \sort($out, \SORT_STRING);
    }

    return \array_values(\array_unique($out));
}

/** Whether a canonical path is anchored (a POSIX `/` or a Windows drive). */
function isAbsolutePath(string $path): bool
{
    return \str_starts_with($path, '/') || \preg_match('#^[A-Za-z]:#', $path) === 1;
}

function relativeRequirePath(string $fromOutputRelative, string $toOutputRelative): string
{
    $fromDir = canonicalSeparators(\dirname($fromOutputRelative));
    if ($fromDir === '.') {
        $fromParts = [];
    } else {
        $fromParts = \explode('/', $fromDir);
    }

    $toParts = \explode('/', canonicalSeparators($toOutputRelative));

    while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
        \array_shift($fromParts);
        \array_shift($toParts);
    }

    $relParts = \array_merge(\array_fill(0, \count($fromParts), '..'), $toParts);

    return '__DIR__ . \'/' . \implode('/', $relParts) . '\'';
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
        $parent = \dirname($candidate);
        if ($parent === $candidate) {
            return $path;
        }
        $tail[] = \basename($candidate);
        $candidate = $parent;
    }

    $resolved = canonicalSeparators($resolved);

    return $tail === [] ? $resolved : \rtrim($resolved, '/') . '/' . \implode('/', \array_reverse($tail));
}
