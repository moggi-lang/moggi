<?php declare(strict_types=1);

/** Resolve `java` / `javac` from JAVA_HOME, PATH, or a sibling of `java`. */
function resolveJdkBinary(string $name): ?string
{
    $javaHome = getenv('JAVA_HOME');
    if (\is_string($javaHome) && $javaHome !== '') {
        $candidate = rtrim($javaHome, '/\\') . '/bin/' . $name;
        if (\is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    $which = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
    if ($which !== '' && is_executable($which)) {
        return $which;
    }

    if ($name === 'javac') {
        $java = resolveJdkBinary('java');
        if ($java !== null) {
            $sibling = dirname($java) . '/javac';
            if (\is_file($sibling) && is_executable($sibling)) {
                return $sibling;
            }
        }
    }

    return null;
}

/**
 * Run a compiled program to completion, reading stdout/stderr, and force-kill it at `$timeoutSec` so
 * a looping test cannot leave a runaway process behind. stdin is closed immediately (reads see EOF
 * instead of blocking) unless the case brings a `.stdin` file.
 *
 * @param array<int, string> $command
 * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool}
 */
function runCompiledProcess(
    array $command,
    int $timeoutSec = 60,
    ?array $env = null,
    ?string $cwd = null,
    ?string $stdin = null,
): array
{
    if (\PHP_OS_FAMILY === 'Windows') {
        return runCompiledProcessThroughFiles($command, $timeoutSec, $env, $cwd, $stdin);
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd, $env);
    if (!is_resource($process)) {
        return ['exitCode' => -1, 'stdout' => '', 'stderr' => 'cannot start process', 'timedOut' => false];
    }

    if ($stdin !== null && $stdin !== '') {
        @\fwrite($pipes[0], $stdin);
    }
    \fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $deadline = microtime(true) + $timeoutSec;
    $timedOut = false;

    while ($open !== []) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            $timedOut = true;
            break;
        }

        $read = array_values($open);
        $write = null;
        $except = null;
        $sec = (int) $remaining;
        $usec = (int) (($remaining - $sec) * 1_000_000);
        $ready = @stream_select($read, $write, $except, $sec, $usec);
        if ($ready === false) {
            break;
        }
        if ($ready === 0) {
            $timedOut = true;
            break;
        }

        foreach ($open as $fd => $stream) {
            if (!\in_array($stream, $read, true)) {
                continue;
            }
            $chunk = \fread($stream, 65536);
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                \fclose($stream);
                unset($open[$fd]);
                continue;
            }
            if ($fd === 1) {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
        }
    }

    if ($timedOut) {
        proc_terminate($process, 9);
        foreach ($open as $stream) {
            \fclose($stream);
        }
        proc_close($process);

        return ['exitCode' => -1, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => true];
    }

    $exitCode = proc_close($process);

    return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => false];
}

/**
 * `runCompiledProcess` on Windows, where the watchdog needs a stream it can poll: an anonymous
 * pipe cannot be put in non-blocking mode there, so a child that goes quiet blocks the parent
 * inside `fread` and the timeout never fires. Its streams are files instead, read back while it
 * runs, and the loop ends on process exit rather than on end-of-file.
 *
 * @param array<int, string> $command
 * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool}
 */
function runCompiledProcessThroughFiles(
    array $command,
    int $timeoutSec,
    ?array $env,
    ?string $cwd,
    ?string $stdin,
): array {
    $files = createProcessFiles($stdin);
    $pipes = [];
    $process = @proc_open($command, processFileDescriptors($files), $pipes, $cwd, $env);
    if (!\is_resource($process)) {
        removeProcessFiles($files);

        return ['exitCode' => -1, 'stdout' => '', 'stderr' => 'cannot start process', 'timedOut' => false];
    }

    $stdout = '';
    $stderr = '';
    $outOffset = 0;
    $errOffset = 0;
    $deadline = \microtime(true) + $timeoutSec;
    $timedOut = false;
    $exitCode = -1;

    while (true) {
        [$chunk, $outOffset] = readAppendedBytes($files['stdout'], $outOffset);
        $stdout .= $chunk;
        [$chunk, $errOffset] = readAppendedBytes($files['stderr'], $errOffset);
        $stderr .= $chunk;

        $status = \proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        if (\microtime(true) >= $deadline) {
            $timedOut = true;
            \proc_terminate($process, 9);
            \usleep(100_000);
            break;
        }
        \usleep(2000);
    }

    [$chunk, $outOffset] = readAppendedBytes($files['stdout'], $outOffset);
    $stdout .= $chunk;
    [$chunk, $errOffset] = readAppendedBytes($files['stderr'], $errOffset);
    $stderr .= $chunk;
    removeProcessFiles($files);
    \proc_close($process);

    return [
        'exitCode' => $timedOut ? -1 : $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timedOut' => $timedOut,
    ];
}

/**
 * Start every command, then multiplex: drain all of their pipes until each exits or its own
 * watchdog fires. This is what makes `--jobs N` possible without threads — the parent stays one
 * single-threaded event loop over N pipe sets.
 *
 * Results come back in the order the jobs were given, whatever order they finished in.
 *
 * `$onChunk` is called with `(job index, fd, chunk)` as output arrives, which is what lets the
 * parent render progress while the workers are still running instead of only when they exit.
 *
 * @param list<array{command: list<string>, timeout: int, env?: ?array, cwd?: ?string}> $jobs
 * @param null|callable(int, int, string): void $onChunk
 * @return list<array{exitCode: int, stdout: string, stderr: string, timedOut: bool}>
 */
function runProcessesInParallel(array $jobs, ?callable $onChunk = null): array
{
    if (\PHP_OS_FAMILY === 'Windows') {
        return runProcessesInParallelThroughFiles($jobs, $onChunk);
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    /** @var array<int, array<string, mixed>> $running */
    $running = [];
    $results = [];

    foreach ($jobs as $index => $job) {
        $process = @proc_open(
            $job['command'],
            $descriptorSpec,
            $pipes,
            $job['cwd'] ?? null,
            $job['env'] ?? null,
        );
        if (!\is_resource($process)) {
            $results[$index] = ['exitCode' => -1, 'stdout' => '', 'stderr' => 'cannot start process', 'timedOut' => false];
            continue;
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);
        $running[$index] = [
            'process' => $process,
            'pipes' => [1 => $pipes[1], 2 => $pipes[2]],
            'stdout' => '',
            'stderr' => '',
            'deadline' => \microtime(true) + (int) $job['timeout'],
            'timedOut' => false,
        ];
    }

    while ($running !== []) {
        $read = [];
        $lookup = [];
        foreach ($running as $index => $state) {
            foreach ($state['pipes'] as $fd => $stream) {
                $read[] = $stream;
                $lookup[(int) $stream] = [$index, (int) $fd];
            }
        }

        if ($read !== []) {
            $write = null;
            $except = null;
            if (@\stream_select($read, $write, $except, 1, 0) === false) {
                break;
            }

            foreach ($read as $stream) {
                [$index, $fd] = $lookup[(int) $stream] ?? [null, 0];
                if ($index === null) {
                    continue;
                }
                $chunk = \fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && \feof($stream))) {
                    \fclose($stream);
                    unset($running[$index]['pipes'][$fd]);
                    continue;
                }
                $running[$index][$fd === 1 ? 'stdout' : 'stderr'] .= $chunk;
                if ($onChunk !== null) {
                    $onChunk($index, (int) $fd, $chunk);
                }
            }
        }

        $now = \microtime(true);
        foreach ($running as $index => $state) {
            if ($state['pipes'] === []) {
                $exitCode = \proc_close($state['process']);
                $results[$index] = [
                    'exitCode' => $state['timedOut'] ? -1 : $exitCode,
                    'stdout' => $state['stdout'],
                    'stderr' => $state['stderr'],
                    'timedOut' => $state['timedOut'],
                ];
                unset($running[$index]);
                continue;
            }

            if ($now > $state['deadline']) {
                $running[$index]['timedOut'] = true;
                \proc_terminate($state['process'], 9);
                foreach ($state['pipes'] as $stream) {
                    \fclose($stream);
                }
                $running[$index]['pipes'] = [];
            }
        }
    }

    foreach ($jobs as $index => $job) {
        if (!isset($results[$index])) {
            $results[$index] = [
                'exitCode' => -1,
                'stdout' => '',
                'stderr' => 'process produced no result',
                'timedOut' => true,
            ];
        }
    }

    \ksort($results);

    return \array_values($results);
}

/**
 * `runProcessesInParallel` on Windows: each job owns its two files and the single loop polls them,
 * so every job's watchdog stays reachable. See `runCompiledProcessThroughFiles`.
 *
 * @param list<array{command: list<string>, timeout: int, env?: ?array, cwd?: ?string}> $jobs
 * @param null|callable(int, int, string): void $onChunk
 * @return list<array{exitCode: int, stdout: string, stderr: string, timedOut: bool}>
 */
function runProcessesInParallelThroughFiles(array $jobs, ?callable $onChunk): array
{
    /** @var array<int, array<string, mixed>> $running */
    $running = [];
    $results = [];

    foreach ($jobs as $index => $job) {
        $files = createProcessFiles(null);
        $pipes = [];
        $process = @proc_open(
            $job['command'],
            processFileDescriptors($files),
            $pipes,
            $job['cwd'] ?? null,
            $job['env'] ?? null,
        );
        if (!\is_resource($process)) {
            removeProcessFiles($files);
            $results[$index] = ['exitCode' => -1, 'stdout' => '', 'stderr' => 'cannot start process', 'timedOut' => false];
            continue;
        }

        $running[$index] = [
            'process' => $process,
            'files' => $files,
            'stdout' => '',
            'stderr' => '',
            'outOffset' => 0,
            'errOffset' => 0,
            'deadline' => \microtime(true) + (int) $job['timeout'],
        ];
    }

    while ($running !== []) {
        foreach ($running as $index => $state) {
            [$chunk, $outOffset] = readAppendedBytes($state['files']['stdout'], $state['outOffset']);
            if ($chunk !== '') {
                $state['stdout'] .= $chunk;
                $state['outOffset'] = $outOffset;
                if ($onChunk !== null) {
                    $onChunk($index, 1, $chunk);
                }
            }
            [$chunk, $errOffset] = readAppendedBytes($state['files']['stderr'], $state['errOffset']);
            if ($chunk !== '') {
                $state['stderr'] .= $chunk;
                $state['errOffset'] = $errOffset;
                if ($onChunk !== null) {
                    $onChunk($index, 2, $chunk);
                }
            }

            $status = \proc_get_status($state['process']);
            if (!$status['running']) {
                $results[$index] = [
                    'exitCode' => $status['exitcode'],
                    'stdout' => $state['stdout'],
                    'stderr' => $state['stderr'],
                    'timedOut' => false,
                ];
                removeProcessFiles($state['files']);
                \proc_close($state['process']);
                unset($running[$index]);
                continue;
            }

            if (\microtime(true) >= $state['deadline']) {
                \proc_terminate($state['process'], 9);
                \usleep(100_000);
                foreach ([1 => 'stdout', 2 => 'stderr'] as $fd => $stream) {
                    $offsetKey = $fd === 1 ? 'outOffset' : 'errOffset';
                    [$chunk, $offset] = readAppendedBytes($state['files'][$stream], $state[$offsetKey]);
                    $state[$stream] .= $chunk;
                    $state[$offsetKey] = $offset;
                }
                $results[$index] = [
                    'exitCode' => -1,
                    'stdout' => $state['stdout'],
                    'stderr' => $state['stderr'],
                    'timedOut' => true,
                ];
                removeProcessFiles($state['files']);
                \proc_close($state['process']);
                unset($running[$index]);
                continue;
            }

            $running[$index] = $state;
        }

        if ($running !== []) {
            \usleep(2000);
        }
    }

    \ksort($results);

    return \array_values($results);
}

/**
 * The scratch files a child's streams are staged through: `stdin` is null when the child is to see
 * end-of-file, and its streams are read back by offset once the child has written them.
 *
 * @return array{stdin: ?string, stdout: string, stderr: string}
 */
function createProcessFiles(?string $stdin): array
{
    $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR
        . 'moggi-suite-' . \getmypid() . '-' . \bin2hex(\random_bytes(6));

    $stdinPath = null;
    if ($stdin !== null && $stdin !== '') {
        $stdinPath = $base . '.in';
        \file_put_contents($stdinPath, $stdin);
    }

    return ['stdin' => $stdinPath, 'stdout' => $base . '.out', 'stderr' => $base . '.err'];
}

/** @param array{stdin: ?string, stdout: string, stderr: string} $files */
function removeProcessFiles(array $files): void
{
    foreach ([$files['stdin'], $files['stdout'], $files['stderr']] as $path) {
        if ($path !== null) {
            @\unlink($path);
        }
    }
}

/**
 * @param array{stdin: ?string, stdout: string, stderr: string} $files
 * @return array<int, array{0: string, 1: string}>
 */
function processFileDescriptors(array $files): array
{
    return [
        0 => ['file', $files['stdin'] ?? (\PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'r'],
        1 => ['file', $files['stdout'], 'w'],
        2 => ['file', $files['stderr'], 'w'],
    ];
}

/**
 * The bytes appended to `$path` since `$offset`, and the offset they end at — a file has no
 * end-of-file to wait for, so a reader asks it what is new instead of blocking on it.
 *
 * @return array{0: string, 1: int}
 */
function readAppendedBytes(string $path, int $offset): array
{
    \clearstatcache(true, $path);
    $size = @\filesize($path);
    if ($size === false || $size <= $offset) {
        return ['', $offset];
    }

    $handle = @\fopen($path, 'rb');
    if ($handle === false) {
        return ['', $offset];
    }
    \fseek($handle, $offset);
    $chunk = (string) \stream_get_contents($handle);
    \fclose($handle);

    return [$chunk, $offset + \strlen($chunk)];
}

function normalizeDiagnosticPaths(string $text, string $projectRoot): string
{
    $root = realpath($projectRoot);
    if ($root === false) {
        return $text;
    }

    // The root reaches the text with whatever separators built the path. A Windows checkout is the
    // awkward one: `__DIR__` is `D:\a\moggi\moggi` while the case paths below it are joined with
    // `/`, so the text carries `D:\a\moggi\moggi/tests/…` and no single prefix covers every case.
    return \str_replace(
        [$root . DIRECTORY_SEPARATOR, $root . '/', \str_replace('\\', '/', $root) . '/'],
        '',
        $text,
    );
}
