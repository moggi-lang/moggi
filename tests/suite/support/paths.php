<?php declare(strict_types=1);

namespace Moggi\Test\PathFilter;

/** The directory positional selectors resolve against and must stay inside. */
function base(): string
{
    return \MOGGI_PROJECT_ROOT;
}

/**
 * @return list<array{path: string, directory: bool}>
 */
function normalize(array $paths, string $projectRoot): array
{
    $root = realpath($projectRoot);
    if ($root === false) {
        throw new \InvalidArgumentException("cannot resolve project root `{$projectRoot}`");
    }

    $filters = [];
    foreach ($paths as $path) {
        $resolved = realpath($path);
        if ($resolved === false) {
            $resolved = realpath($root . DIRECTORY_SEPARATOR . $path);
        }
        if ($resolved === false) {
            throw new \InvalidArgumentException("test path does not exist: {$path}");
        }
        if ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException("test path is outside the project: {$path}");
        }

        $relative = $resolved === $root
            ? ''
            : substr($resolved, strlen($root) + 1);
        $filters[] = [
            'path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
            'directory' => is_dir($resolved),
        ];
    }

    return $filters;
}

/**
 * A file path matches exactly. A directory filter selects every path beneath it.
 *
 * @param list<array{path: string, directory: bool}> $filters
 */
function matches(string $path, array $filters, string $projectRoot): bool
{
    if ($filters === []) {
        return true;
    }

    $root = realpath($projectRoot);
    if ($root === false) {
        return false;
    }

    $relative = relativeUnderRoot($path, $root);
    if ($relative === null) {
        return false;
    }

    foreach ($filters as $filter) {
        if ($filter['path'] === '') {
            return true;
        }
        if ($relative === $filter['path']) {
            return true;
        }
        if ($filter['directory'] && str_starts_with($relative, rtrim($filter['path'], '/') . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * `$path` relative to `$root`, or null when it falls outside the project. A path that does not exist
 * on disk is still mapped lexically: a case whose file is missing must be *selected* (and fail
 * loudly), not vanish from the run.
 */
function relativeUnderRoot(string $path, string $root): ?string
{
    $resolved = realpath($path);
    $absolute = $resolved !== false
        ? $resolved
        : (str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : $root . DIRECTORY_SEPARATOR . $path);

    if ($absolute === $root) {
        return '';
    }
    if (!str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1));
}
