<?php declare(strict_types=1);

use Moggi\Backend;

use function Moggi\Compiler\compileFileCapturingErrors;

/** @return array{output: string, exitCode: 0}|array{output: string, exitCode: 1} */
function compileTestFile(string $path, string $mode = 'emit', bool $optimize = true): array
{
    return compileFileCapturingErrors(
        testRelativePath($path),
        $mode,
        $optimize,
        Backend\compileBackend(),
    );
}

/** @return array{passed: bool, message: string} */
function runExecTest(string $path, string $projectRoot): array
{
    $name = basename($path, '.mog');
    $base = fixtureBasePath($path);
    $backend = Backend\compileBackend();
    $stdoutExpected = runtimeExpectedPath($base, '.stdout.expected', $backend);
    $stderrExpected = runtimeExpectedPath($base, '.stderr.expected', $backend);
    if ($backend !== 'php') {
        if (\is_file($stderrExpected)) {
            return runBackendReportFixture($path, $stderrExpected, $backend, $projectRoot);
        }
        if (!\is_file($stdoutExpected)) {
            return ['passed' => false, 'message' => "missing backend-neutral stdout expected file {$stdoutExpected}"];
        }

        return runBackendStdoutFixture($path, $stdoutExpected, $backend, $projectRoot);
    }
    // A php stderr report runs the packaged layout, like jvm/dotnet, so the run resolves exactly
    // the frames the artifact ships.
    if (\is_file($stderrExpected)) {
        return runBackendReportFixture($path, $stderrExpected, 'php', $projectRoot);
    }
    if (!\is_file($stdoutExpected)) {
        return ['passed' => false, 'message' => "missing expected output file {$stdoutExpected}"];
    }
    // A project entry builds its whole tree, like jvm/dotnet: the single-file path below compiles the
    // entry alone and cannot carry the sibling modules it requires.
    if (\is_file(\dirname($path) . '/Main.mog')) {
        return runBackendStdoutFixture($path, $stdoutExpected, 'php', $projectRoot);
    }

    $php = compileTestFile($path, 'php');
    if ($php['exitCode'] !== 0) {
        return ['passed' => false, 'message' => "compile failed:\n{$php['output']}"];
    }

    $libPhpDir = writeLibPhpOutputs($projectRoot);

    $execDir = \Moggi\Cache\cacheScratchDir() . '/exec';
    @mkdir($execDir, 0777, true);
    // Unique per run: `--jobs` workers share the scratch dir (each unlinks in `finally`).
    $compiled = $execDir . '/' . $name . '.' . getmypid() . '.' . uniqid('', true) . '.php';

    try {
        $phpSource = rewriteLibRequires($php['output'], $libPhpDir, $compiled);

        if (\file_put_contents($compiled, $phpSource) === false) {
            return ['passed' => false, 'message' => "cannot write compiled output for {$name}"];
        }

        $stdin = fixtureStdin($path);
        if (\is_file($stderrExpected)) {
            $result = runCompiledProcess([PHP_BINARY, $compiled], 60, stdin: $stdin);
            if ($result['timedOut']) {
                return ['passed' => false, 'message' => "compiled output timed out for {$name}"];
            }
            if ($result['exitCode'] === -1 && $result['stderr'] === 'cannot start process') {
                return ['passed' => false, 'message' => "cannot execute compiled output for {$name}"];
            }
            if (($result['exitCode'] ?? 0) === 0) {
                return ['passed' => false, 'message' => "{$name}: expected nonzero exit for stderr report fixture"];
            }
            $actual = moggiReportOutput($result);
            assertSameOutput($name, $stderrExpected, $actual, 'stderr');

            return ['passed' => true, 'message' => ''];
        }

        $result = runCompiledProcess([PHP_BINARY, $compiled], 60, stdin: $stdin);
        if ($result['timedOut']) {
            return ['passed' => false, 'message' => "compiled output timed out for {$name}"];
        }
        if ($result['exitCode'] === -1 && $result['stderr'] === 'cannot start process') {
            return ['passed' => false, 'message' => "cannot execute compiled output for {$name}"];
        }
        if ($result['exitCode'] !== 0) {
            return ['passed' => false, 'message' => childExitMessage("compiled output", $result)];
        }
        assertSameOutput($name, $stdoutExpected, $result['stdout'], 'stdout');
    } catch (\Throwable $e) {
        return ['passed' => false, 'message' => "runtime failure: {$e->getMessage()}"];
    } finally {
        if (\is_file($compiled)) {
            unlink($compiled);
        }
    }

    return ['passed' => true, 'message' => ''];
}

/**
 * A failing child, with both of its streams.
 *
 * php-cli prints a fatal error on *stdout* (`display_errors` defaults to it), so reporting stderr
 * alone makes a crash look like an empty failure with a bare exit code.
 */
function childExitMessage(string $what, array $result): string
{
    $sections = [];
    foreach (['stdout', 'stderr'] as $stream) {
        $text = trim((string) ($result[$stream] ?? ''));
        if ($text !== '') {
            $sections[] = "{$stream}:\n{$text}";
        }
    }

    return $what . ' exited ' . (int) ($result['exitCode'] ?? -1)
        . ($sections === [] ? ' with no output' : ":\n" . implode("\n", $sections));
}

/**
 * Resolve a runtime fixture expected file. A backend-specific
 * `<base>.<backend>.<suffix>` wins over the backend-neutral `<base>.<suffix>`,
 * which lets one fixture carry different reports per backend while keeping a
 * shared golden where they agree.
 */
function runtimeExpectedPath(string $base, string $suffix, string $backend): string
{
    $specific = $base . '.' . $backend . $suffix;
    if (\is_file($specific)) {
        return $specific;
    }

    return $base . $suffix;
}

/**
 * Output arguments for fixture builds.
 *
 * PHP fixtures run the entry .php directly, so they build the unpacked tree;
 * jvm/dotnet fixtures run the packaged jar/dll, so `-o` names the artifact
 * inside $outDir under its canonical name.
 *
 * @return list<string>
 */
function fixtureCompileOutputArgs(string $outDir, string $backend): array
{
    return match ($backend) {
        'php' => ['-o', $outDir, '--unpacked'],
        'jvm' => ['-o', $outDir . '/moggi-app.jar'],
        'dotnet' => ['-o', $outDir . '/moggi-app.dll'],
    };
}

/**
 * Run a packaged build for php / jvm / dotnet backends.
 *
 * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool}|array{passed: false, message: string}
 */
function runPackagedBackendApp(
    string $outDir,
    string $backend,
    string $name,
    int $timeoutSec = 60,
    ?string $stdin = null,
): array {
    if ($backend === 'jvm') {
        $jar = $outDir . '/moggi-app.jar';
        if (!\is_file($jar)) {
            return ['passed' => false, 'message' => "{$name}: missing {$jar}"];
        }
        $java = resolveJdkBinary('java') ?? 'java';

        return runCompiledProcess([$java, '-jar', $jar], $timeoutSec, stdin: $stdin);
    }

    if ($backend === 'dotnet') {
        $dll = $outDir . '/moggi-app.dll';
        if (!\is_file($dll)) {
            return ['passed' => false, 'message' => "{$name}: missing {$dll}"];
        }
        $dotnet = \Moggi\Backend\DotNet\resolveDotnetExecutable();
        if ($dotnet === null) {
            return ['passed' => false, 'message' => "{$name}: dotnet SDK not found"];
        }

        return runCompiledProcess([$dotnet, $dll], $timeoutSec, stdin: $stdin);
    }

    $entry = findBuiltPhpEntry($outDir);
    if ($entry === null) {
        return ['passed' => false, 'message' => "{$name}: missing PHP entry under {$outDir}"];
    }

    return runCompiledProcess([PHP_BINARY, $entry], $timeoutSec, stdin: $stdin);
}

/** The input a case's program reads on stdin, from the `.stdin` file beside it. */
function fixtureStdin(string $inputPath): ?string
{
    $path = \substr($inputPath, 0, -\strlen(inputExtension($inputPath) ?? '')) . '.stdin';

    return \is_file($path) ? (\file_get_contents($path) ?: '') : null;
}

function runBackendStdoutFixture(
    string $inputPath,
    string $stdoutExpected,
    string $backend,
    string $projectRoot,
): array {
    $name = testRelativePath($inputPath);
    $outDir = createTempDir('moggi-runtime-' . md5($inputPath . ':' . $backend));

    try {
        $moggi = $projectRoot . '/moggi.php';
        $build = runCompiledProcess([
            PHP_BINARY,
            $moggi,
            'compile',
            $inputPath,
            ...fixtureCompileOutputArgs($outDir, $backend),
            '--backend',
            $backend,
        ], 120);
        if (($build['exitCode'] ?? 1) !== 0) {
            return [
                'passed' => false,
                'message' => "{$name}: build failed:\n" . trim(($build['stdout'] ?? '') . "\n" . ($build['stderr'] ?? '')),
            ];
        }

        $run = runPackagedBackendApp($outDir, $backend, $name, 60, fixtureStdin($inputPath));
        if (isset($run['passed']) && $run['passed'] === false) {
            return $run;
        }

        if (($run['timedOut'] ?? false) === true) {
            return ['passed' => false, 'message' => "{$name}: timed out"];
        }
        if (($run['exitCode'] ?? 1) !== 0) {
            return [
                'passed' => false,
                'message' => "{$name}: " . childExitMessage('run', $run),
            ];
        }

        assertSameOutput(basename($inputPath, '.mog'), $stdoutExpected, $run['stdout'] ?? '', 'stdout');
    } catch (TestFailure $e) {
        return ['passed' => false, 'message' => $e->getMessage()];
    } finally {
        removeDirectory($outDir);
    }

    return ['passed' => true, 'message' => ''];
}

/** The native toolchain a backend can build with, or null when it has none (php). */
function nativeToolchainLabel(string $backend): ?string
{
    return match ($backend) {
        'jvm' => 'GraalVM native-image',
        'dotnet' => '.NET Native AOT',
        default => null,
    };
}

/** Is the toolchain its label names installed? */
function nativeToolchainAvailable(string $backend): bool
{
    return match ($backend) {
        'jvm' => resolveJdkBinary('native-image') !== null,
        'dotnet' => Backend\DotNet\resolveDotnetExecutable() !== null,
        default => false,
    };
}

/**
 * The `--native` mode: build one example into a native executable and run it, comparing its stdout
 * with the same golden the managed run uses.
 *
 * Such a build takes minutes and needs a toolchain most machines do not have, so it is opt-in, and
 * a toolchain that is not installed is a **skip** (the case applies, the machine cannot run it) —
 * never a silent pass.
 *
 * @return array{passed: bool, message: string, skip?: string}
 */
function runNativeExampleSmoke(string $exampleDir, string $backend, string $projectRoot): array
{
    $name = testRelativePath($exampleDir) . ' (native)';
    $stdoutExpected = runtimeExpectedPath($exampleDir . '/Main', '.stdout.expected', $backend);
    if (!\is_file($stdoutExpected)) {
        return ['passed' => false, 'message' => "{$name}: missing {$stdoutExpected}"];
    }

    $label = nativeToolchainLabel($backend);
    if ($label === null || !nativeToolchainAvailable($backend)) {
        return ['passed' => true, 'message' => '', 'skip' => ($label ?? $backend) . ' not available'];
    }

    $outDir = createTempDir('moggi-native-' . md5($exampleDir . ':' . $backend));

    try {
        $build = runCompiledProcess([
            PHP_BINARY,
            $projectRoot . '/moggi.php',
            'compile',
            $exampleDir,
            ...fixtureCompileOutputArgs($outDir, $backend),
            '--backend',
            $backend,
            '--native',
        ], 900);
        if (($build['exitCode'] ?? 1) !== 0) {
            return [
                'passed' => false,
                'message' => "{$name}: {$label} build failed:\n"
                    . trim(($build['stdout'] ?? '') . "\n" . ($build['stderr'] ?? '')),
            ];
        }

        $binary = $outDir . '/moggi-app';
        if (!\is_file($binary)) {
            return ['passed' => false, 'message' => "{$name}: missing {$binary}"];
        }

        $run = runCompiledProcess([$binary], 60);
        if (($run['timedOut'] ?? false) === true) {
            return ['passed' => false, 'message' => "{$name}: timed out"];
        }
        if (($run['exitCode'] ?? 1) !== 0) {
            return [
                'passed' => false,
                'message' => "{$name}: " . childExitMessage('run', $run),
            ];
        }

        assertSameOutput(basename($exampleDir), $stdoutExpected, $run['stdout'] ?? '', 'stdout');
    } catch (TestFailure $e) {
        return ['passed' => false, 'message' => $e->getMessage()];
    } finally {
        removeDirectory($outDir);
    }

    return ['passed' => true, 'message' => ''];
}

/** Prefer stderr Moggi report; fall back to stdout (.NET writes report to Console). */
function moggiReportOutput(array $run): string
{
    $stderr = trim((string) ($run['stderr'] ?? ''));
    if ($stderr !== '') {
        return $stderr . "\n";
    }

    return rtrim((string) ($run['stdout'] ?? ''), "\n") . "\n";
}

function runBackendReportFixture(
    string $inputPath,
    string $stderrExpected,
    string $backend,
    string $projectRoot,
): array {
    $name = testRelativePath($inputPath);
    $outDir = createTempDir('moggi-runtime-' . md5($inputPath . ':report:' . $backend));

    try {
        $moggi = $projectRoot . '/moggi.php';
        $build = runCompiledProcess([
            PHP_BINARY,
            $moggi,
            'compile',
            $inputPath,
            ...fixtureCompileOutputArgs($outDir, $backend),
            '--backend',
            $backend,
        ], 120);
        if (($build['exitCode'] ?? 1) !== 0) {
            return [
                'passed' => false,
                'message' => "{$name}: build failed:\n" . trim(($build['stdout'] ?? '') . "\n" . ($build['stderr'] ?? '')),
            ];
        }

        $run = runPackagedBackendApp($outDir, $backend, $name, 60, fixtureStdin($inputPath));
        if (isset($run['passed']) && $run['passed'] === false) {
            return $run;
        }
        if (($run['timedOut'] ?? false) === true) {
            return ['passed' => false, 'message' => "{$name}: timed out"];
        }
        if (($run['exitCode'] ?? 0) === 0) {
            return ['passed' => false, 'message' => "{$name}: expected nonzero exit for report fixture"];
        }
        assertSameOutput(basename($inputPath, '.mog'), $stderrExpected, moggiReportOutput($run), 'stderr');
    } catch (TestFailure $e) {
        return ['passed' => false, 'message' => $e->getMessage()];
    } finally {
        removeDirectory($outDir);
    }

    return ['passed' => true, 'message' => ''];
}

/**
 * Locate the compiled PHP program entry in a build tree.
 *
 * A build compiles exactly one program, and the PHP backend emits the
 * `main()` bootstrap into whichever module defines the entry point, keeping
 * the source's relative path (`Main.php`, `examples/Demo-Math.php`, …). So the
 * entry is found by that bootstrap, never by a guessed filename. `lib/` and
 * `runtime/` outputs are never the program entry point.
 */
function findBuiltPhpEntry(string $outDir): ?string
{
    $root = rtrim($outDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($outDir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $rel = \str_replace('\\', '/', substr($path, strlen($root)));
        if (str_starts_with($rel, 'lib/') || str_starts_with($rel, 'runtime/')) {
            continue;
        }

        $source = file_get_contents($path);
        if ($source !== false && str_contains($source, '\\Moggi\\reportUncaught($__moggiUncaught)')) {
            return $path;
        }
    }

    return null;
}

/**
 * A REPL transcript: run the script through the REPL and diff its stdout against the backend's
 * golden, falling back to the backend-neutral `<name>.stdout.expected`.
 *
 * @return array{passed: bool, message: string}
 */
function runReplScriptTest(string $scriptPath, string $expectedPath, string $backend, string $projectRoot): array
{
    $name = testRelativePath($scriptPath);
    if (!\is_file($scriptPath) || !\is_file($expectedPath)) {
        return ['passed' => false, 'message' => "{$name}: missing script or expected stdout"];
    }

    $moggi = $projectRoot . '/moggi.php';
    $cmd = [
        PHP_BINARY,
        $moggi,
        'repl',
        '--backend',
        $backend,
        '--script',
        $scriptPath,
    ];
    $result = runCompiledProcess($cmd, 120);
    $actual = $result['stdout'] ?? '';
    $expected = file_get_contents($expectedPath);
    if ($expected === false) {
        return ['passed' => false, 'message' => "{$name}: cannot read expected"];
    }
    if ($actual !== $expected) {
        return [
            'passed' => false,
            'message' => "{$name}: stdout mismatch\n--- expected ---\n{$expected}\n--- actual ---\n{$actual}\n--- stderr ---\n"
                . ($result['stderr'] ?? ''),
        ];
    }
    if (($result['exitCode'] ?? 1) !== 0) {
        return ['passed' => false, 'message' => "{$name}: exit " . ($result['exitCode'] ?? '?')];
    }

    return ['passed' => true, 'message' => ''];
}

/** @return array{passed: bool, message: string} */
function runReplCase(string $scriptPath, string $projectRoot): array
{
    $backend = \Moggi\Backend\compileBackend();
    $expected = \preg_replace('/\.script$/', '.' . $backend . '.stdout.expected', $scriptPath)
        ?? ($scriptPath . '.stdout.expected');
    if (!\is_file($expected)) {
        $expected = \preg_replace('/\.script$/', '.stdout.expected', $scriptPath)
            ?? ($scriptPath . '.stdout.expected');
    }

    return runReplScriptTest($scriptPath, $expected, $backend, $projectRoot);
}

/**
 * Build `lib/` for a backend and publish it under a content-addressed directory (the name is a
 * signature of backend, compiler fingerprint, lib sources and runtime).
 *
 * One worker builds it while the others wait on a lock: the tree is published by a single
 * `rename()`, and `.complete` is the last thing written into it, so a directory without that marker
 * is the wreckage of an interrupted build and is replaced instead of used. Two workers racing to
 * publish the same name would otherwise fight over a directory that cannot be renamed onto itself.
 */
function writeLibBackendOutputs(string $projectRoot, string $backend): string
{
    static $written = [];
    if (isset($written[$backend])) {
        return $written[$backend];
    }

    $libRoot = $projectRoot . '/lib';
    $outDir = \Moggi\Cache\cacheBaseDir() . '/test-lib-' . $backend . '-' . substr(testLibSignature($projectRoot, $backend), 0, 12);
    $complete = $outDir . '/.complete';
    if (\is_file($complete)) {
        return $written[$backend] = $outDir;
    }

    // Outside the cache root, which a cache reset removes, and keyed by the tree it guards.
    $lockPath = sys_get_temp_dir() . '/moggi-test-lib-' . substr(\Moggi\Cache\hashContent($outDir), 0, 16) . '.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock !== false) {
        flock($lock, LOCK_EX);
    }

    try {
        if (\is_file($complete)) {
            return $written[$backend] = $outDir;
        }

        $staging = $outDir . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        Backend\setCompileBackend($backend);
        $outputs = \Moggi\Modules\compileProject(findModuleMogFiles($libRoot), $libRoot);
        foreach ($outputs as $relative => $source) {
            $target = $staging . '/' . $relative;
            @mkdir(dirname($target), 0777, true);
            \file_put_contents($target, $source);
        }

        if ($backend === 'php') {
            $runtimeDst = $staging . '/_runtime.php';
            @mkdir(dirname($runtimeDst), 0777, true);
            // Link rather than copy: `require_once` de-duplicates by resolved path, so the fixture's
            // runtime and the harness's in-process copy must be the same file.
            if (!@symlink($projectRoot . '/src/backend/php/runtime.php', $runtimeDst)) {
                copy($projectRoot . '/src/backend/php/runtime.php', $runtimeDst);
            }
        }

        // The marker goes in last, before the tree becomes visible: its presence is what says the
        // tree is usable.
        \file_put_contents($staging . '/.complete', "");
        if (\is_dir($outDir)) {
            removeDirectory($outDir);
        }
        if (!@rename($staging, $outDir)) {
            removeDirectory($staging);
        }

        return $written[$backend] = $outDir;
    } finally {
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** Content signature of the stdlib build: backend + compiler + lib sources + the PHP runtime. */
function testLibSignature(string $projectRoot, string $backend): string
{
    $parts = [$backend, \Moggi\Cache\compilerFingerprint()];
    foreach (findModuleMogFiles($projectRoot . '/lib') as $file) {
        $parts[] = testRelativePath($file) . ':' . \Moggi\Cache\fileFingerprint($file);
    }
    if ($backend === 'php') {
        $parts[] = 'runtime:' . \Moggi\Cache\fileFingerprint($projectRoot . '/src/backend/php/runtime.php');
    }

    return hash('sha256', \implode("\n", $parts));
}

function writeLibPhpOutputs(string $projectRoot): string
{
    return writeLibBackendOutputs($projectRoot, 'php');
}

/** Point a compiled fixture at the published stdlib build instead of the source tree's `lib/`. */
function rewriteLibRequires(string $php, string $libPhpDir, string $compiledFile): string
{
    $libDir = rtrim(\str_replace('\\', '/', $libPhpDir), '/');
    $rewrite = static function (array $matches) use ($libDir, $compiledFile): string {
        $relative = \Moggi\Modules\relativeFilePath($compiledFile, $libDir . '/' . $matches[1]);

        return "require_once __DIR__ . '/{$relative}'";
    };

    $php = preg_replace_callback("#require_once __DIR__ \. '/(?:\.\./)+lib/([^']+)'#", $rewrite, $php) ?? $php;

    return preg_replace_callback(
        "#require_once __DIR__ \. '/(?:\.\./)+_runtime\.php'#",
        static fn (): string => $rewrite([0, '_runtime.php']),
        $php,
    ) ?? $php;
}

/** @return list<string> every `.mog` under `$root` */
function findModuleMogFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'mog') {
            $files[] = $file->getPathname();
        }
    }
    \sort($files);

    return $files;
}
