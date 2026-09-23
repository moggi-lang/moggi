<?php declare(strict_types=1);

namespace Moggi\Compiler;

/**
 * Find an executable the way a shell would, without invoking one.
 *
 * Host toolchains are discovered by probing directories directly. Asking a
 * shell (`command -v`, `which`, `where`) would mean the lookup behaves
 * differently on every platform — and fail outright on Windows, where neither
 * `command -v` nor `which` exists. PATH scanning is a few `is_file` calls.
 *
 * @param string $name A bare program name; a name that already contains a
 *   path separator is honoured as a path and only checked for existence.
 * @return ?string The resolved path, or null when nothing matches.
 */
function findExecutable(string $name): ?string
{
    if (\str_contains($name, '/') || \str_contains($name, '\\')) {
        return \is_file($name) && \is_executable($name) ? $name : null;
    }

    // On Windows a program is found by its extension, not by its exec bit.
    $suffixes = \PHP_OS_FAMILY === 'Windows' ? ['', '.exe', '.cmd', '.bat', '.com'] : [''];
    $directories = \explode(\PATH_SEPARATOR, (string) \getenv('PATH'));
    foreach ($directories as $directory) {
        if ($directory === '') {
            continue;
        }
        foreach ($suffixes as $suffix) {
            $candidate = \rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . $name . $suffix;
            if (\is_file($candidate) && (\PHP_OS_FAMILY === 'Windows' || \is_executable($candidate))) {
                return $candidate;
            }
        }
    }

    return null;
}

/**
 * The `$name` executable inside a toolchain root variable, and nowhere else.
 *
 * Used for toolchains that publish a root (`JAVA_HOME`, `DOTNET_ROOT`): a
 * bundled runtime is found there first, and only a caller that also accepts a
 * system toolchain falls back to `findExecutable()`.
 *
 * @param string $rootEnv The variable holding the toolchain root.
 * @param string $subdir Subdirectory of the root that holds the executables;
 *   empty when the root is the directory itself (as `DOTNET_ROOT` is).
 */
function findToolchainExecutable(string $rootEnv, string $name, string $subdir = 'bin'): ?string
{
    $root = \getenv($rootEnv);
    if (!\is_string($root) || $root === '') {
        return null;
    }
    $directory = \rtrim($root, '/\\');
    if ($subdir !== '') {
        $directory .= \DIRECTORY_SEPARATOR . $subdir;
    }
    $candidate = $directory . \DIRECTORY_SEPARATOR . $name;
    if (\PHP_OS_FAMILY === 'Windows') {
        foreach (['.exe', '.cmd', '.bat', ''] as $suffix) {
            if (\is_file($candidate . $suffix)) {
                return $candidate . $suffix;
            }
        }

        return null;
    }

    return \is_file($candidate) && \is_executable($candidate) ? $candidate : null;
}
