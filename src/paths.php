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

/**
 * Directory expansion without `glob()`: backslash is an escape character in
 * glob patterns, so a pattern built with `DIRECTORY_SEPARATOR` silently
 * matches nothing on Windows (`C:\dir\*.dll` reads `\*` as an escaped
 * star), and separator spelling, duplicate slashes and trailing slashes all
 * behave slightly differently per host. Every scan in the compiler goes
 * through here instead, so matching means the same everywhere: `\` is
 * re-spelled to `/` up front and never acts as an escape, duplicate slashes
 * collapse, a trailing slash only marks directories, and `*` never matches
 * dotfiles — on any host. PHP opens forward-slash paths on every host, so
 * the returned paths are `/`-spelled on every host too.
 *
 * Only `GLOB_ONLYDIR`, `GLOB_MARK` and `GLOB_NOSORT` are honored; no caller
 * passes anything else. `**` matches one level, exactly as it does in
 * `glob()`.
 *
 * @return list<string>
 */
function globPaths(string $pattern, int $flags = 0): array
{
    $path = \str_replace('\\', '/', $pattern);
    $root = '';
    $rest = $path;
    if (\str_starts_with($rest, '//')) {
        $root = '//';
        $rest = (string) \substr($rest, 2);
    } elseif (\preg_match('#^[A-Za-z]:(?=/|$)#', $rest) === 1) {
        $root = (string) \substr($rest, 0, 2);
        $rest = (string) \substr($rest, 2);
    }
    $absolute = \str_starts_with($rest, '/');
    $rest = (string) \preg_replace('#/+#', '/', $rest);
    $dirMark = $rest !== '' && \str_ends_with($rest, '/');
    $segments = \array_values(\array_filter(
        \explode('/', $rest),
        static fn (string $segment): bool => $segment !== '' && $segment !== '.',
    ));
    if ($root === '//') {
        $segments[0] = '//' . ($segments[0] ?? '');
        $root = '';
    }
    if ($segments === []) {
        $seed = $root !== '' ? $root : ($absolute ? '/' : '');

        return $seed !== '' && @\file_exists($seed) ? [$seed] : [];
    }

    $join = static fn (string $base, string $segment): string => $base === ''
        ? $segment
        : ($base === '/' ? '/' . \ltrim($segment, '/') : $base . '/' . $segment);
    $fs = static fn (string $base): string => $base === '' ? '.' : $base;
    $matchFlags = \FNM_NOESCAPE | (\DIRECTORY_SEPARATOR === '\\' ? \FNM_CASEFOLD : 0);

    $current = $root !== '' ? [$root] : ($absolute ? ['/'] : ['']);
    $total = \count($segments);
    foreach ($segments as $index => $segment) {
        $last = $index === $total - 1;
        $next = [];
        if (\strpbrk($segment, '*?[') === false) {
            foreach ($current as $base) {
                $candidate = $join($base, $segment);
                if ($last ? @\file_exists($candidate) : @\is_dir($candidate)) {
                    $next[] = $candidate;
                }
            }
        } else {
            foreach ($current as $base) {
                if (!@\is_dir($fs($base))) {
                    continue;
                }
                $entries = @\scandir($fs($base));
                if (!\is_array($entries)) {
                    continue;
                }
                $skipDotfiles = !\str_starts_with($segment, '.');
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    if ($skipDotfiles && \str_starts_with($entry, '.')) {
                        continue;
                    }
                    if (!\fnmatch($segment, $entry, $matchFlags)) {
                        continue;
                    }
                    $candidate = $join($base, $entry);
                    if (!$last && !@\is_dir($candidate)) {
                        continue;
                    }
                    $next[] = $candidate;
                }
            }
        }
        $current = \array_values(\array_unique($next));
        if ($current === []) {
            return [];
        }
    }

    $onlyDirs = ($flags & \GLOB_ONLYDIR) !== 0 || $dirMark;
    $mark = ($flags & \GLOB_MARK) !== 0 || $dirMark;
    $results = [];
    foreach ($current as $candidate) {
        $isDir = @\is_dir($candidate);
        if ($onlyDirs && !$isDir) {
            continue;
        }
        $results[] = $isDir && $mark ? $candidate . '/' : $candidate;
    }
    if (($flags & \GLOB_NOSORT) === 0) {
        \sort($results, \SORT_STRING);
    }

    return $results;
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
