<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/../src/executables.php';

use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\runProcess as runCompilerProcess;

/**
 * Filesystem and process primitives for the distribution build.
 *
 * Downloading an asset, unpacking it, moving a tree, running a command and
 * reading a version back are the verbs every part of the runtime layer uses, so
 * they live together and know nothing about runtimes in particular. `runProcess`
 * is the one place the build shell-outs to `Moggi\Compiler\runProcess`, which
 * reads both pipes without deadlocking on a full buffer.
 */

function httpGet(string $url, ?string $destination = null, bool $followRedirects = true): string
{
    $target = $destination ?? \tempnam(\sys_get_temp_dir(), 'moggi-download-');
    if ($target === false) {
        throw new \RuntimeException('cannot create a download target');
    }

    $out = \fopen($target, 'wb');
    if ($out === false) {
        throw new \RuntimeException("cannot write {$target}");
    }

    $ch = \curl_init($url);
    \curl_setopt_array($ch, [
        \CURLOPT_FILE => $out,
        \CURLOPT_FOLLOWLOCATION => $followRedirects,
        \CURLOPT_MAXREDIRS => 10,
        \CURLOPT_FAILONERROR => false,
        \CURLOPT_USERAGENT => 'moggi-dist-build',
        \CURLOPT_TIMEOUT => 1800,
    ]);
    $ok = \curl_exec($ch);
    $status = (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
    $error = \curl_error($ch);
    unset($ch);
    \fclose($out);

    if ($ok !== true || $status >= 400 || $status === 0) {
        @\unlink($target);
        throw new \RuntimeException("GET {$url} failed: " . ($error !== '' ? $error : "HTTP {$status}"));
    }

    return $target;
}

function sha256File(string $path): string
{
    $hash = \hash_file('sha256', $path);
    if ($hash === false) {
        throw new \RuntimeException("cannot hash {$path}");
    }

    return \strtolower($hash);
}

function runProcess(
    array $command,
    ?string $cwd = null,
    ?array $env = null,
    bool $echo = false,
    ?int $timeoutSeconds = null,
): array {
    $result = runCompilerProcess($command, $cwd, $env, $echo, $timeoutSeconds);

    return [$result['exitCode'], $result['stdout'], $result['stderr']];
}

function extractArchive(string $archive, string $format, string $destination, int $stripComponents): void
{
    if (!\is_dir($destination) && !\mkdir($destination, 0777, true) && !\is_dir($destination)) {
        throw new \RuntimeException("cannot create {$destination}");
    }

    if ($format === 'zip') {
        $staging = $destination . '.unzip';
        if (\is_dir($staging)) {
            removeTree($staging);
        }
        if (!\mkdir($staging, 0777, true) && !\is_dir($staging)) {
            throw new \RuntimeException("cannot create {$staging}");
        }
        [$code] = runProcess(['unzip', '-q', '-o', $archive, '-d', $staging]);
        if ($code !== 0) {
            [$code, , $err] = runProcess(['tar', '-xf', $archive, '-C', $staging]);
            if ($code !== 0) {
                throw new \RuntimeException("cannot extract {$archive}: {$err}");
            }
        }
        stripTopLevel($staging, $destination, $stripComponents);
        removeTree($staging);

        return;
    }

    $args = ['tar', '-xf', $archive, '-C', $destination];
    if ($stripComponents > 0) {
        $args[] = '--strip-components=' . $stripComponents;
    }
    [$code, , $err] = runProcess($args);
    if ($code !== 0) {
        throw new \RuntimeException("cannot extract {$archive}: {$err}");
    }
}

/** Move the contents of a single wrapper directory up when a zip has one. */
function stripTopLevel(string $staging, string $destination, int $stripComponents): void
{
    if ($stripComponents === 0) {
        moveTree($staging, $destination);

        return;
    }

    $entries = \array_values(\array_diff(\scandir($staging) ?: [], ['.', '..']));
    for ($i = 0; $i < $stripComponents; ++$i) {
        if (\count($entries) !== 1) {
            throw new \RuntimeException("cannot strip {$stripComponents} component(s) from an archive with " . \count($entries) . ' top-level entries');
        }
        $staging .= '/' . $entries[0];
        $entries = \array_values(\array_diff(\scandir($staging) ?: [], ['.', '..']));
    }
    moveTree($staging, $destination);
}

function moveTree(string $from, string $to): void
{
    foreach (\scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        $target = $to . '/' . $entry;
        if (!@\rename($source, $target)) {
            if (\is_dir($source)) {
                moveTree($source, $target);
                @\rmdir($source);
            } else {
                if (!\copy($source, $target)) {
                    throw new \RuntimeException("cannot move {$source} to {$target}");
                }
                @\unlink($source);
            }
        }
    }
}

function removeTree(string $path): void
{
    if (!\is_dir($path)) {
        if (\is_file($path)) {
            @\unlink($path);
        }

        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
    }
    @\rmdir($path);
}

/** @param ?callable(string, string): bool $keep */
function copyTree(string $from, string $to, ?callable $keep = null): void
{
    if (!\is_dir($to) && !\mkdir($to, 0777, true) && !\is_dir($to)) {
        throw new \RuntimeException("cannot create {$to}");
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($items as $item) {
        $relative = \substr(\str_replace('\\', '/', $item->getPathname()), \strlen($from) + 1);
        if ($keep !== null && !$keep($relative, $item->getFilename())) {
            continue;
        }
        $target = $to . '/' . $relative;
        if ($item->isDir()) {
            if (!\is_dir($target)) {
                \mkdir($target, 0777, true);
            }
            continue;
        }
        $parent = \dirname($target);
        if (!\is_dir($parent)) {
            \mkdir($parent, 0777, true);
        }
        if ($item->isLink()) {
            @\symlink((string) \readlink($item->getPathname()), $target);
            continue;
        }
        if (!\copy($item->getPathname(), $target)) {
            throw new \RuntimeException("cannot copy {$item->getPathname()}");
        }
        @\chmod($target, $item->getPerms() & 0777);
    }
}

/**
 * Move a runtime out of the macOS bundle layout.
 *
 * A JDK archive for macOS nests the runtime — `jdk-21.0.12.1+1/Contents/Home/bin/java` — so the
 * one-component strip every asset uses leaves it at `Contents/Home`, where nothing looks for it.
 * The JDK and GraalVM assets are both built like that, and only on macOS. Nothing happens when the
 * runtime's own binary is already at the root, so a flat archive is untouched.
 */
function flattenDarwinBundleHome(string $dir, string $binary): void
{
    $home = $dir . '/Contents/Home';
    if (\is_file($dir . '/' . $binary) || !\is_file($home . '/' . $binary)) {
        return;
    }

    foreach (\scandir($home) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!\rename($home . '/' . $name, $dir . '/' . $name)) {
            throw new \RuntimeException("cannot move {$home}/{$name} into {$dir}");
        }
    }
    removeTree($dir . '/Contents');
}

function runtimeProcess(array $command): string
{
    [$code, $stdout, $stderr] = runProcess($command);
    if ($code !== 0) {
        throw new \RuntimeException(
            'runtime check failed (' . $code . '): ' . \implode(' ', $command) . ' ' . \trim($stdout . ' ' . $stderr),
        );
    }

    return \trim($stdout . "\n" . $stderr);
}

/** Run a command with `$libDir` added to the dynamic loader's search path. */
function runWithLibraryPath(array $command, string $libDir): string
{
    $name = \PHP_OS_FAMILY === 'Darwin' ? 'DYLD_FALLBACK_LIBRARY_PATH' : 'LD_LIBRARY_PATH';
    $env = \getenv();
    $existing = $env[$name] ?? '';
    $env[$name] = $existing === '' ? $libDir : $libDir . ':' . $existing;

    [$code, $stdout, $stderr] = runProcess($command, null, $env);
    if ($code !== 0) {
        throw new \RuntimeException(
            'runtime check failed (' . $code . '): ' . \implode(' ', $command) . ' ' . \trim($stdout . ' ' . $stderr),
        );
    }

    return \trim($stdout . "\n" . $stderr);
}

function runOrFail(array $command, ?string $cwd = null, bool $echo = false, ?int $timeoutSeconds = null, ?array $env = null): void
{
    $label = \implode(' ', $command) . ($cwd === null ? '' : '  [cwd ' . $cwd . ']');
    \fwrite(STDOUT, '  ' . $label . "\n");
    assertProgramRunnable((string) $command[0], $cwd);
    $started = \microtime(true);
    [$code, $stdout, $stderr] = runProcess($command, $cwd, $env, $echo, $timeoutSeconds);
    if ($code !== 0) {
        throw new \RuntimeException(
            "{$label} failed ({$code})\n" . \substr($stdout . "\n" . $stderr, -4000),
        );
    }
    \fwrite(STDOUT, \sprintf('    ok (%.1fs)%s', \microtime(true) - $started, "\n"));
}

function assertProgramRunnable(string $program, ?string $cwd): void
{
    $resolved = \strpbrk($program, '/\\') === false ? findExecutable($program) : $program;
    $where = $cwd === null ? '' : " (cwd {$cwd})";
    if ($resolved === null) {
        throw new \RuntimeException("cannot start {$program}: not found on PATH{$where}");
    }
    if (!\is_file($resolved)) {
        throw new \RuntimeException("cannot start {$program}: no such file {$resolved}{$where}");
    }
    if (\PHP_OS_FAMILY !== 'Windows' && !\is_executable($resolved)) {
        throw new \RuntimeException("cannot start {$program}: {$resolved} is not executable");
    }
}

function assertChildCwd(string $cwd): void
{
    [$code, $stdout] = runProcess([\PHP_BINARY, '-r', 'echo getcwd();'], $cwd);
    $expected = \rtrim(\str_replace('\\', '/', (string) \realpath($cwd)), '/');
    $actual = \rtrim(\str_replace('\\', '/', (string) \realpath(\trim($stdout))), '/');
    if ($code !== 0 || $actual !== $expected) {
        throw new \RuntimeException(
            "a child process does not start in {$cwd} (got " . \trim($stdout) . ', exit ' . $code . ')',
        );
    }
}

function cpuCount(): int
{
    $override = \getenv('MOGGI_DIST_JOBS');
    if (\is_string($override) && $override !== '' && \ctype_digit($override)) {
        return (int) $override;
    }
    if (\PHP_OS_FAMILY === 'Windows') {
        $count = \getenv('NUMBER_OF_PROCESSORS');

        return \is_string($count) && \ctype_digit($count) ? (int) $count : 2;
    }
    [$code, $out] = runProcess(['getconf', '_NPROCESSORS_ONLN']);

    return $code === 0 && \ctype_digit(\trim($out)) ? (int) \trim($out) : 2;
}
