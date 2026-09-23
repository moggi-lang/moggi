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

/**
 * Run a command and return its exit code with everything it printed.
 *
 * Both pipes are read while the process runs, never one to EOF before the
 * other: a child that fills the pipe nobody is reading blocks on the write, so
 * it never closes the stream being waited on. That buffer is ~4 KB on Windows
 * and 64 KB elsewhere, and an ordinary program printing to stderr reaches it.
 *
 * `$echo` passes the child's output through as it arrives.
 *
 * @param list<string> $command
 * @param ?array<string, string> $env
 * @return array{exitCode: int, stdout: string, stderr: string} `exitCode` is -1
 *   when the command could not be started.
 */
function runProcess(array $command, ?string $cwd = null, ?array $env = null, bool $echo = false): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // A toolchain that is not installed is an expected answer here, not a
    // warning: the caller sees it in the return value.
    $process = @\proc_open($command, $descriptors, $pipes, $cwd, $env);
    if (!\is_resource($process)) {
        return ['exitCode' => -1, 'stdout' => '', 'stderr' => 'cannot start ' . \implode(' ', $command)];
    }
    \fclose($pipes[0]);
    \stream_set_blocking($pipes[1], false);
    \stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    while (true) {
        $out = (string) \stream_get_contents($pipes[1]);
        if ($out !== '') {
            $stdout .= $out;
            if ($echo) {
                \fwrite(STDOUT, $out);
            }
        }
        $err = (string) \stream_get_contents($pipes[2]);
        if ($err !== '') {
            $stderr .= $err;
            if ($echo) {
                \fwrite(STDERR, $err);
            }
        }
        if (\feof($pipes[1]) && \feof($pipes[2])) {
            break;
        }
        \usleep(2000);
    }
    \fclose($pipes[1]);
    \fclose($pipes[2]);

    return ['exitCode' => \proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}
