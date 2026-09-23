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

function normalizeDiagnosticPaths(string $text, string $projectRoot): string
{
    $root = realpath($projectRoot);
    if ($root === false) {
        return $text;
    }

    $prefix = $root . DIRECTORY_SEPARATOR;

    return \str_replace($prefix, '', $text);
}
