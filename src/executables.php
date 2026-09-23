<?php declare(strict_types=1);

namespace Moggi\Compiler;

/**
 * The file name an executable has on this host: `.exe` is part of it on Windows.
 *
 * `native-image` and `.NET` Native AOT both write that suffix themselves, so a
 * caller that names the binary it expects has to ask here rather than append
 * nothing and look for a file that was never created.
 */
function executableName(string $name): string
{
    return \PHP_OS_FAMILY === 'Windows' ? $name . '.exe' : $name;
}

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
 * The loop ends when the process exits, not when its pipes reach EOF: build
 * tools leave descendants holding them open, which reads exactly like a hang.
 * `$timeoutSeconds` kills a process that outlives it and says so in `stderr`.
 *
 * @param list<string> $command
 * @param ?array<string, string> $env
 * @return array{exitCode: int, stdout: string, stderr: string} `exitCode` is -1
 *   when the command could not be started.
 */
function runProcess(
    array $command,
    ?string $cwd = null,
    ?array $env = null,
    bool $echo = false,
    ?int $timeoutSeconds = null,
): array {
    // There is no non-blocking pipe on Windows: `stream_set_blocking` only
    // reaches sockets there, so reading a pipe waits for it to close and the
    // child blocks on whichever stream nobody is draining — a hang with no
    // output. Files carry both streams instead, which cannot fill up.
    if (\PHP_OS_FAMILY === 'Windows') {
        return runProcessThroughFiles($command, $cwd, $env, $echo, $timeoutSeconds);
    }

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
    $drain = static function () use ($pipes, $echo, &$stdout, &$stderr): void {
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
    };

    $deadline = $timeoutSeconds === null ? null : \microtime(true) + $timeoutSeconds;
    $exitCode = -1;
    $timedOut = false;
    while (true) {
        $drain();
        $status = \proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        if ($deadline !== null && \microtime(true) > $deadline) {
            $timedOut = true;
            \proc_terminate($process);
            \usleep(100_000);
            break;
        }
        \usleep(2000);
    }

    // Drains what the command buffered; a closed pipe ends this at once.
    $grace = \microtime(true) + 0.2;
    while (!\feof($pipes[1]) || !\feof($pipes[2])) {
        $drain();
        if (\microtime(true) >= $grace) {
            break;
        }
        \usleep(2000);
    }

    \fclose($pipes[1]);
    \fclose($pipes[2]);
    \proc_close($process);

    if ($timedOut) {
        $stderr .= \sprintf("timed out after %d seconds: %s\n", $timeoutSeconds, \implode(' ', $command));
        $exitCode = -1;
    }

    return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * The Windows half of `runProcess`: the child writes both streams to files that
 * are read back while it runs, so nothing blocks on a pipe buffer.
 *
 * @param list<string> $command
 * @param ?array<string, string> $env
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runProcessThroughFiles(
    array $command,
    ?string $cwd,
    ?array $env,
    bool $echo,
    ?int $timeoutSeconds,
): array {
    $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'moggi-proc-' . \getmypid() . '-' . \bin2hex(\random_bytes(6));
    $stdoutPath = $base . '.out';
    $stderrPath = $base . '.err';
    $nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $descriptors = [
        0 => ['file', $nullDevice, 'r'],
        1 => ['file', $stdoutPath, 'w'],
        2 => ['file', $stderrPath, 'w'],
    ];

    $pipes = [];
    $process = @\proc_open($command, $descriptors, $pipes, $cwd, $env);
    if (!\is_resource($process)) {
        return ['exitCode' => -1, 'stdout' => '', 'stderr' => 'cannot start ' . \implode(' ', $command)];
    }

    $shownOut = 0;
    $shownErr = 0;
    $deadline = $timeoutSeconds === null ? null : \microtime(true) + $timeoutSeconds;
    $exitCode = -1;
    $timedOut = false;
    while (true) {
        if ($echo) {
            $shownOut = echoFileTail($stdoutPath, $shownOut, STDOUT);
            $shownErr = echoFileTail($stderrPath, $shownErr, STDERR);
        }
        $status = \proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        if ($deadline !== null && \microtime(true) > $deadline) {
            $timedOut = true;
            \proc_terminate($process);
            \usleep(100_000);
            break;
        }
        \usleep(2000);
    }

    if ($echo) {
        echoFileTail($stdoutPath, $shownOut, STDOUT);
        echoFileTail($stderrPath, $shownErr, STDERR);
    }
    $stdout = (string) @\file_get_contents($stdoutPath);
    $stderr = (string) @\file_get_contents($stderrPath);
    @\unlink($stdoutPath);
    @\unlink($stderrPath);
    \proc_close($process);

    if ($timedOut) {
        $stderr .= \sprintf("timed out after %d seconds: %s\n", $timeoutSeconds, \implode(' ', $command));
        $exitCode = -1;
    }

    return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

/** Print what the child appended to $path past $offset, and return the new offset. */
function echoFileTail(string $path, int $offset, $target): int
{
    \clearstatcache(true, $path);
    $size = @\filesize($path);
    if ($size === false || $size <= $offset) {
        return $offset;
    }
    $handle = @\fopen($path, 'rb');
    if ($handle === false) {
        return $offset;
    }
    \fseek($handle, $offset);
    $chunk = (string) \stream_get_contents($handle);
    \fclose($handle);
    if ($chunk !== '') {
        \fwrite($target, $chunk);
    }

    return $offset + \strlen($chunk);
}
