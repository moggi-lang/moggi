<?php declare(strict_types=1);

use Moggi\Backend;

/**
 * A fixture marked `<name>.noopt` also runs with the optimizer off and has to produce the *same*
 * expectation: the entry/wrapper/worker ABI has to hold where no pass has rewritten it, and the
 * only honest comparison is the one the optimized leg already makes.
 *
 * @return array{passed: bool, message: string}
 */
function runExecTest(string $path, string $projectRoot, string $backend): array
{
    $result = runExecTestOnce($path, $projectRoot, $backend);
    if ($result['passed'] !== true || ! \is_file(fixtureBasePath($path) . '.noopt')) {
        return $result;
    }

    $noOpt = runExecTestOnce($path, $projectRoot, $backend, noOpt: true);

    return $noOpt['passed'] === true
        ? $result
        : ['passed' => false, 'message' => 'no-optimization run — ' . $noOpt['message']];
}

/** @return array{passed: bool, message: string} */
function runExecTestOnce(string $path, string $projectRoot, string $backend, bool $noOpt = false): array
{
    $name = \basename($path, '.mog');
    $base = fixtureBasePath($path);
    $stdoutExpected = runtimeExpectedPath($base, '.stdout.expected', $backend);
    $stderrExpected = runtimeExpectedPath($base, '.stderr.expected', $backend);
    if ($noOpt) {
        if (\is_file($stderrExpected)) {
            return runBackendReportFixture($path, $stderrExpected, $backend, $projectRoot, noOpt: true);
        }
        if (! \is_file($stdoutExpected)) {
            return ['passed' => false, 'message' => "missing expected output file {$stdoutExpected}"];
        }

        return runBackendStdoutFixture($path, $stdoutExpected, $backend, $projectRoot, noOpt: true);
    }

    if ($backend !== 'php') {
        if (\is_file($stderrExpected)) {
            return runBackendReportFixture($path, $stderrExpected, $backend, $projectRoot);
        }
        if (! \is_file($stdoutExpected)) {
            return ['passed' => false, 'message' => "missing backend-neutral stdout expected file {$stdoutExpected}"];
        }

        return runBackendStdoutFixture($path, $stdoutExpected, $backend, $projectRoot);
    }
    if (\is_file($stderrExpected)) {
        return runBackendReportFixture($path, $stderrExpected, 'php', $projectRoot);
    }
    if (! \is_file($stdoutExpected)) {
        return ['passed' => false, 'message' => "missing expected output file {$stdoutExpected}"];
    }
    if (\is_file(\dirname($path) . '/Main.mog')) {
        return runBackendStdoutFixture($path, $stdoutExpected, 'php', $projectRoot);
    }
    $compiled = compileFixtureEntry($path, $projectRoot);
    if (isset($compiled['passed'])) {
        return $compiled;
    }
    $compiled = $compiled['entry'];

    try {
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
            return ['passed' => false, 'message' => childExitMessage('compiled output', $result)];
        }
        assertSameOutput($name, $stdoutExpected, $result['stdout'], 'stdout');
    } catch (\Throwable $e) {
        return ['passed' => false, 'message' => "runtime failure: {$e->getMessage()}"];
    } finally {
        @\unlink($compiled);
    }

    return ['passed' => true, 'message' => ''];
}

/**
 * Compile one fixture to its entry artifact inside the run's library tree.
 *
 * @return array{entry: string}|array{passed: false, message: string}
 */
function compileFixtureEntry(string $path, string $projectRoot): array
{
    $tree = execTreeRoot($projectRoot);

    $php = \Moggi\Compiler\compileFileCapturingErrors(testRelativePath($path), 'php', true, 'php');
    if ($php['exitCode'] !== 0) {
        return ['passed' => false, 'message' => "compile failed:\n{$php['output']}"];
    }

    $relative = \preg_replace('/\.mog$/', '.php', testRelativePath($path)) ?? testRelativePath($path) . '.php';
    $entry = $tree . '/' . $relative;
    $parent = \dirname($entry);
    if (! \is_dir($parent) && ! @\mkdir($parent, 0777, true) && ! \is_dir($parent)) {
        return ['passed' => false, 'message' => "cannot create {$parent}"];
    }
    if (\file_put_contents($entry, $php['output']) === false) {
        return ['passed' => false, 'message' => "cannot write {$entry}"];
    }

    return ['entry' => $entry];
}

/**
 * The run's library build, mirrored at its project-relative layout, once per process.
 */
function execTreeRoot(string $projectRoot): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }

    $lib = writeLibBackendOutputs($projectRoot, 'php');
    $root = createTempDir('moggi-exec-tree');
    mirrorTree($lib, $root);
    \register_shutdown_function(static function () use ($root): void {
        removeDirectory($root);
    });

    return $root;
}

function mirrorTree(string $source, string $target): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $relative = \substr($item->getPathname(), \strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($item->isDir()) {
            @\mkdir($destination, 0777, true);

            continue;
        }

        @\mkdir(\dirname($destination), 0777, true);
        if (! @\link($item->getPathname(), $destination)) {
            @\copy($item->getPathname(), $destination);
        }
    }
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
        $text = \trim((string) ($result[$stream] ?? ''));
        if ($text !== '') {
            $sections[] = "{$stream}:\n{$text}";
        }
    }

    return $what . ' exited ' . (int) ($result['exitCode'] ?? -1)
        . ($sections === [] ? ' with no output' : ":\n" . \implode("\n", $sections));
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
        if (! \is_file($jar)) {
            return ['passed' => false, 'message' => "{$name}: missing {$jar}"];
        }
        $java = resolveJdkBinary('java') ?? 'java';

        return runCompiledProcess([$java, '-jar', $jar], $timeoutSec, stdin: $stdin);
    }

    if ($backend === 'dotnet') {
        $dll = $outDir . '/moggi-app.dll';
        if (! \is_file($dll)) {
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
    bool $noOpt = false,
): array {
    $name = testRelativePath($inputPath);
    $outDir = createTempDir('moggi-runtime-' . \md5($inputPath . ':' . $backend));

    try {
        $moggi = $projectRoot . '/moggi.php';
        $build = runCompiledProcess([
            PHP_BINARY,
            $moggi,
            'compile',
            $inputPath,
            ...fixtureExtraLibArgs($projectRoot),
            ...fixtureCompileOutputArgs($outDir, $backend),
            ...($noOpt ? ['--no-opt'] : []),
            '--backend',
            $backend,
        ], 120);
        if (($build['exitCode'] ?? 1) !== 0) {
            return [
                'passed' => false,
                'message' => "{$name}: build failed:\n" . \trim(($build['stdout'] ?? '') . "\n" . ($build['stderr'] ?? '')),
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

        assertSameOutput(\basename($inputPath, '.mog'), $stdoutExpected, $run['stdout'] ?? '', 'stdout');
    } catch (TestFailure $e) {
        return ['passed' => false, 'message' => $e->getMessage()];
    } finally {
        removeDirectory($outDir);
    }

    return ['passed' => true, 'message' => ''];
}

/**
 * The native toolchain a backend can build with, or null when it has none.
 *
 * All three backends have one now: the micro PHP runtime the PHP backend
 * appends a PHAR to, GraalVM's `native-image`, and .NET's Native AOT.
 */
function nativeToolchainLabel(string $backend): ?string
{
    return match ($backend) {
        'php' => 'micro PHP runtime',
        'jvm' => 'GraalVM native-image',
        'dotnet' => '.NET Native AOT',
        default => null,
    };
}

/**
 * Is the toolchain its label names installed?
 *
 * The micro runtime is not on `PATH` like the other two: `MOGGI_MICRO_SFX`, or
 * one bundled by the distribution running the suite, is the only way to reach it.
 */
function nativeToolchainAvailable(string $backend): bool
{
    return match ($backend) {
        'php' => \Moggi\Backend\Php\resolveMicroSfx() !== null,
        'jvm' => resolveJdkBinary('native-image') !== null,
        'dotnet' => Backend\DotNet\resolveDotnetExecutable() !== null,
        default => false,
    };
}

/**
 * Output arguments for a native fixture build.
 *
 * `--native` turns the managed artifact into the executable beside it, so the
 * build must package one: php's `--unpacked` tree has no PHAR to append to a
 * micro runtime, so it names the archive itself.
 *
 * @return list<string>
 */
function nativeSmokeOutputArgs(string $outDir, string $backend): array
{
    return $backend === 'php'
        ? ['-o', $outDir . '/moggi-app.phar']
        : fixtureCompileOutputArgs($outDir, $backend);
}

/**
 * The `--native` mode: build one example into a native executable and run it, comparing its stdout
 * with the same program built the ordinary (managed) way and run the same way.
 *
 * The example itself carries no golden — its case is "it still compiles" — so the reference is the
 * managed run: what this mode adds is "…and also as an executable", and a native build that prints
 * something else than the managed one is the failure worth reporting.
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

    $label = nativeToolchainLabel($backend);
    if ($label === null || ! nativeToolchainAvailable($backend)) {
        return ['passed' => true, 'message' => '', 'skip' => ($label ?? $backend) . ' not available'];
    }

    $outDir = createTempDir('moggi-native-' . \md5($exampleDir . ':' . $backend));
    $managedDir = createTempDir('moggi-native-managed-' . \md5($exampleDir . ':' . $backend));

    try {
        $managedBuild = runCompiledProcess([
            PHP_BINARY,
            $projectRoot . '/moggi.php',
            'compile',
            $exampleDir,
            ...fixtureCompileOutputArgs($managedDir, $backend),
            '--backend',
            $backend,
        ], 300);
        if (($managedBuild['exitCode'] ?? 1) !== 0) {
            return [
                'passed' => false,
                'message' => "{$name}: managed build failed:\n"
                    . \trim(($managedBuild['stdout'] ?? '') . "\n" . ($managedBuild['stderr'] ?? '')),
            ];
        }

        $managed = runPackagedBackendApp($managedDir, $backend, $name . ' (managed)', 60);
        if (isset($managed['passed']) && $managed['passed'] === false) {
            return $managed;
        }
        if (($managed['timedOut'] ?? false) === true) {
            return ['passed' => false, 'message' => "{$name}: managed run timed out"];
        }
        if (($managed['exitCode'] ?? 1) !== 0) {
            return [
                'passed' => false,
                'message' => "{$name}: " . childExitMessage('managed run', $managed),
            ];
        }

        $build = runCompiledProcess([
            PHP_BINARY,
            $projectRoot . '/moggi.php',
            'compile',
            $exampleDir,
            ...nativeSmokeOutputArgs($outDir, $backend),
            '--backend',
            $backend,
            '--native',
        ], 900);
        if (($build['exitCode'] ?? 1) !== 0) {
            return [
                'passed' => false,
                'message' => "{$name}: {$label} build failed:\n"
                    . \trim(($build['stdout'] ?? '') . "\n" . ($build['stderr'] ?? '')),
            ];
        }

        $binary = $outDir . '/' . \Moggi\Compiler\executableName('moggi-app');
        if (! \is_file($binary)) {
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

        $expected = normalize((string) ($managed['stdout'] ?? ''));
        $actual = normalize((string) ($run['stdout'] ?? ''));
        if ($expected !== $actual) {
            throw new TestFailure("{$name}: stdout differs from the managed run\n--- managed\n+++ native\n"
                . diffText($expected, $actual));
        }
    } catch (TestFailure $e) {
        return ['passed' => false, 'message' => $e->getMessage()];
    } finally {
        removeDirectory($outDir);
        removeDirectory($managedDir);
    }

    return ['passed' => true, 'message' => ''];
}

/** Prefer stderr Moggi report; fall back to stdout (.NET writes report to Console). */
function moggiReportOutput(array $run): string
{
    $stderr = \trim((string) ($run['stderr'] ?? ''));
    if ($stderr !== '') {
        return $stderr . "\n";
    }

    return \rtrim((string) ($run['stdout'] ?? ''), "\n") . "\n";
}

function runBackendReportFixture(
    string $inputPath,
    string $stderrExpected,
    string $backend,
    string $projectRoot,
    bool $noOpt = false,
): array {
    $name = testRelativePath($inputPath);
    $outDir = createTempDir('moggi-runtime-' . \md5($inputPath . ':report:' . $backend));

    try {
        $moggi = $projectRoot . '/moggi.php';
        $build = runCompiledProcess([
            PHP_BINARY,
            $moggi,
            'compile',
            $inputPath,
            ...fixtureExtraLibArgs($projectRoot),
            ...fixtureCompileOutputArgs($outDir, $backend),
            ...($noOpt ? ['--no-opt'] : []),
            '--backend',
            $backend,
        ], 120);
        if (($build['exitCode'] ?? 1) !== 0) {
            return [
                'passed' => false,
                'message' => "{$name}: build failed:\n" . \trim(($build['stdout'] ?? '') . "\n" . ($build['stderr'] ?? '')),
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
        assertSameOutput(\basename($inputPath, '.mog'), $stderrExpected, moggiReportOutput($run), 'stderr');
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
    $root = \rtrim($outDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($outDir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $rel = \str_replace('\\', '/', \substr($path, \strlen($root)));
        if (\str_starts_with($rel, 'lib/') || \str_starts_with($rel, 'runtime/')) {
            continue;
        }

        $source = \file_get_contents($path);
        if ($source !== false && \str_contains($source, '\\Moggi\\reportUncaught($__moggiUncaught)')) {
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
    if (! \is_file($scriptPath) || ! \is_file($expectedPath)) {
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
    $expected = \file_get_contents($expectedPath);
    if ($expected === false) {
        return ['passed' => false, 'message' => "{$name}: cannot read expected"];
    }
    if (normalize($actual) !== normalize($expected)) {
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
function runReplCase(string $scriptPath, string $projectRoot, string $backend): array
{
    $expected = \preg_replace('/\.script$/', '.' . $backend . '.stdout.expected', $scriptPath)
        ?? ($scriptPath . '.stdout.expected');
    if (! \is_file($expected)) {
        $expected = \preg_replace('/\.script$/', '.stdout.expected', $scriptPath)
            ?? ($scriptPath . '.stdout.expected');
    }

    return runReplScriptTest($scriptPath, $expected, $backend, $projectRoot);
}

/**
 * The directory `$backend`'s prepared library tree is published under: named by a signature of the
 * backend, the compiler, the library sources and the PHP runtime, so a different compiler or
 * library source is a different tree rather than a stale one.
 */
function libBackendTreeDir(string $projectRoot, string $backend): string
{
    return \Moggi\Cache\cacheScratchDir() . '/lib-' . $backend . '-' . \substr(testLibSignature($projectRoot, $backend), 0, 12);
}

/** Whether that tree is already published, so waiting for it is not what the run is about to do. */
function libBackendTreeReady(string $projectRoot, string $backend): bool
{
    return \is_file(libBackendTreeDir($projectRoot, $backend) . '/.complete');
}

/**
 * Build `lib/` for a backend and publish it under a content-addressed directory (the name is a
 * signature of backend, compiler fingerprint, lib sources and runtime).
 *
 * One worker builds it while the others wait on a lock: the tree is published by a single
 * `rename()`, and `.complete` is the last thing written into it, so a directory without that marker
 * is the wreckage of an interrupted build and is replaced instead of used. Two workers racing to
 * publish the same name would otherwise fight over a directory that cannot be renamed onto itself.
 *
 * `$built` reports which of the two happened — this call built and published the tree, or it found
 * one already published (by an earlier call in this process or another worker) — so a caller that
 * reports the wait can name it.
 */
function writeLibBackendOutputs(string $projectRoot, string $backend, ?bool &$built = null): string
{
    $built = false;

    static $written = [];
    if (isset($written[$backend]) && \is_file($written[$backend] . '/.complete')) {
        return $written[$backend];
    }

    $outDir = libBackendTreeDir($projectRoot, $backend);
    $complete = $outDir . '/.complete';
    if (\is_file($complete)) {
        return $written[$backend] = $outDir;
    }

    $lockPath = \sys_get_temp_dir() . '/moggi-test-lib-' . \substr(\Moggi\Cache\hashContent($outDir), 0, 16) . '.lock';
    $lock = @\fopen($lockPath, 'c');
    if ($lock !== false) {
        \flock($lock, LOCK_EX);
    }

    try {
        if (\is_file($complete)) {
            return $written[$backend] = $outDir;
        }

        $staging = $outDir . '.tmp.' . \getmypid() . '.' . \bin2hex(\random_bytes(4));
        $outputs = \Moggi\Modules\compileProject(testLibraryFiles($projectRoot), $projectRoot, \Moggi\Pipeline\CompilationContext::for($backend, \Moggi\Pipeline\CompilePurpose::Library, sources: testLibraryFiles($projectRoot)));
        foreach ($outputs as $relative => $source) {
            $target = $staging . '/' . $relative;
            @\mkdir(\dirname($target), 0777, true);
            \file_put_contents($target, $source);
        }
        unset($outputs);
        \Moggi\Modules\clearPrepareProjectCaches();

        if ($backend === 'php') {
            $runtimeDst = $staging . '/_runtime.php';
            @\mkdir(\dirname($runtimeDst), 0777, true);
            if (! @\symlink($projectRoot . '/src/backend/php/runtime.php', $runtimeDst)) {
                \copy($projectRoot . '/src/backend/php/runtime.php', $runtimeDst);
            }
        }

        \file_put_contents($staging . '/.complete', '');
        if (\is_dir($outDir)) {
            removeDirectory($outDir);
        }
        if (! @\rename($staging, $outDir)) {
            removeDirectory($staging);
        }

        $built = \is_file($complete);

        return $written[$backend] = $outDir;
    } finally {
        if ($lock !== false) {
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }
    }
}

/**
 * The library roots the harness compiles: the standard library plus any extra
 * root registered beside it (the `json` package in this checkout).
 *
 * @return list<string>
 */
function testLibraryRoots(string $projectRoot): array
{
    $roots = \Moggi\Modules\configuredLibraryRoots();
    if ($roots === []) {
        $roots = [$projectRoot . '/libraries/base/src'];
    }
    $array = $projectRoot . '/libraries/array/src';
    if (\is_dir($array)) {
        $resolved = \array_map(
            static fn (string $root): string => \Moggi\Modules\resolvePath($root),
            $roots,
        );
        if (! \in_array(\Moggi\Modules\resolvePath($array), $resolved, true)) {
            $roots[] = $array;
        }
    }
    $containers = $projectRoot . '/libraries/containers/src';
    if (\is_dir($containers)) {
        $resolved = \array_map(
            static fn (string $root): string => \Moggi\Modules\resolvePath($root),
            $roots,
        );
        if (! \in_array(\Moggi\Modules\resolvePath($containers), $resolved, true)) {
            $roots[] = $containers;
        }
    }
    $filepath = $projectRoot . '/libraries/filepath/src';
    if (\is_dir($filepath)) {
        $resolved = \array_map(
            static fn (string $root): string => \Moggi\Modules\resolvePath($root),
            $roots,
        );
        if (! \in_array(\Moggi\Modules\resolvePath($filepath), $resolved, true)) {
            $roots[] = $filepath;
        }
    }
    $directory = $projectRoot . '/libraries/directory/src';
    if (\is_dir($directory)) {
        $resolved = \array_map(
            static fn (string $root): string => \Moggi\Modules\resolvePath($root),
            $roots,
        );
        if (! \in_array(\Moggi\Modules\resolvePath($directory), $resolved, true)) {
            $roots[] = $directory;
        }
    }
    $time = $projectRoot . '/libraries/time/src';
    if (\is_dir($time)) {
        $resolved = \array_map(
            static fn (string $root): string => \Moggi\Modules\resolvePath($root),
            $roots,
        );
        if (! \in_array(\Moggi\Modules\resolvePath($time), $resolved, true)) {
            $roots[] = $time;
        }
    }

    return $roots;
}

/**
 * `--lib` arguments for the extra roots, for a fixture build that runs as a child
 * process and so does not inherit what the harness registered in-process.
 *
 * The standard library is left out: the CLI discovers it on its own.
 *
 * @return list<string>
 */
function fixtureExtraLibArgs(string $projectRoot): array
{
    $primary = \Moggi\Modules\configuredStdlibLibPath();
    $args = [];
    foreach (testLibraryRoots($projectRoot) as $root) {
        if ($primary !== null && \Moggi\Modules\resolvePath($root) === \Moggi\Modules\resolvePath($primary)) {
            continue;
        }
        $args[] = '--lib';
        $args[] = $root;
    }

    return $args;
}

/** @return list<string> every `.mog` under every library root, sorted */
function testLibraryFiles(string $projectRoot): array
{
    $files = [];
    foreach (testLibraryRoots($projectRoot) as $root) {
        $files = [...$files, ...findModuleMogFiles($root)];
    }
    \sort($files);

    return $files;
}

/** Content signature of the library build: backend + compiler + library sources + the PHP runtime. */
function testLibSignature(string $projectRoot, string $backend): string
{
    $parts = [$backend, \Moggi\Cache\compilerFingerprint()];
    foreach (testLibraryFiles($projectRoot) as $file) {
        $parts[] = testRelativePath($file) . ':' . \Moggi\Cache\fileFingerprint($file);
    }
    if ($backend === 'php') {
        $parts[] = 'runtime:' . \Moggi\Cache\fileFingerprint($projectRoot . '/src/backend/php/runtime.php');
    }

    return \hash('xxh3', \implode("\n", $parts));
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
