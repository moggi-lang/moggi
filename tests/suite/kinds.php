<?php declare(strict_types=1);

use Moggi\Backend;

use function Moggi\Compiler\compileFileCapturingErrorsBoth;
use function Moggi\Modules\bundledStdlibLibPath;
use function Moggi\Modules\compileProjectBoth;
use function Moggi\Modules\projectSourceClosure;

/**
 * The kind table — the suite's whole configuration.
 *
 * A golden is named `<base>[.<backend>].<kind>.expected`, beside the input it belongs to
 * (`Foo.mog`, `Foo.ir.php`, `Foo.script`, or a project's `Main.mog`). The kind says what is
 * compared and, with it, which stage produces the thing being compared:
 *
 *   tokens      lexer dump              ast        parse tree
 *   typed-ast   the tree after typechecking         ir   lowered IR
 *   opt-ir      optimized IR            emit       generated source
 *   stdout      the program's stdout   stderr      its stderr (uncaught error and trace)
 *   err         the compiler rejects the input: message, srcLoc, caret
 *
 * `pre` marks the kinds produced before codegen: no backend can change them, so a backend-qualified
 * one would only repeat the shared golden.
 *
 * @return array<string, array{pre: bool}>
 */
function testTreeKinds(): array
{
    return [
        'tokens' => ['pre' => true],
        'ast' => ['pre' => true],
        'typed-ast' => ['pre' => true],
        'ir' => ['pre' => true],
        'opt-ir' => ['pre' => true],
        'emit' => ['pre' => false],
        'stdout' => ['pre' => false],
        'stderr' => ['pre' => false],
        'err' => ['pre' => true],
    ];
}

/** The kinds that snapshots produce, in stage order. */
function snapshotKinds(): array
{
    return ['tokens', 'ast', 'typed-ast', 'ir', 'opt-ir', 'emit'];
}

/**
 * The one program `--native` builds and runs. A native build is the slowest thing the suite does, so
 * the mode is opt-in and one program proves the path end to end; it adds a verdict to that example's
 * case, never a case of its own.
 */
function nativeSmokeFixture(): string
{
    return 'examples/twice';
}

/**
 * Run one case on one backend. Every verdict in the suite goes through here.
 *
 * @return array{passed: bool, message: string, skip?: string, native?: bool}
 */
function runTestCase(TestCase $case, string $backend, string $projectRoot): array
{
    try {
        $outcome = $case->scriptCase
            ? runStandaloneTest($case->input)
            : ($case->project
                ? runProjectCase($case, $backend, $projectRoot)
                : runFixtureCase($case, $backend, $projectRoot));
    } catch (TestFailure $e) {
        return ['passed' => false, 'message' => $e->getMessage()];
    }

    if (($outcome['passed'] ?? false) !== true || !nativeSmokeRequested($case, $backend)) {
        return $outcome;
    }

    // A case that already passed gains a second verdict, and `native` says so in the report; a `skip`
    // verdict (no toolchain) replaces the pass.
    return ['native' => true] + runNativeExampleSmoke(\dirname($case->input), $backend, $projectRoot);
}

/** Does `--native` add a verdict to this case on this backend? */
function nativeSmokeRequested(TestCase $case, string $backend): bool
{
    global $testArgs;

    return ($testArgs['native'] ?? false) === true
        && !$case->scriptCase
        && \dirname(testRelativePath($case->input)) === nativeSmokeFixture()
        && nativeToolchainLabel($backend) !== null;
}

/** @return array{passed: bool, message: string} */
function runFixtureCase(TestCase $case, string $backend, string $projectRoot): array
{
    // A fixture is either rejected or accepted, never both: an `err` golden is the verdict.
    $errorGolden = $case->goldenBeside($case->base(), 'err', $backend);
    if ($errorGolden !== null) {
        return runErrorFixture($case, $errorGolden, $backend);
    }

    foreach (snapshotKinds() as $kind) {
        $golden = $case->goldenBeside($case->base(), $kind, $backend);
        if ($golden === null) {
            continue;
        }
        assertSameOutput($case->name, $golden, testKindSnapshot($kind, $case->input, $backend), $kind);
    }

    return runProgramVerdict($case);
}

/** @return array{passed: bool, message: string} */
function runProjectCase(TestCase $case, string $backend, string $projectRoot): array
{
    $errorGoldens = $case->goldensOfKind('err', $backend);
    if ($errorGoldens !== []) {
        return runProjectErrorCase($case, $errorGoldens, $backend, $projectRoot);
    }

    compareProjectSnapshots($case, $backend);

    return runProgramVerdict($case);
}

/**
 * A fixture that only runs: `stdout`/`stderr` goldens, or a `.exec.php` harness that asserts in code.
 * The runner resolves the backend's golden itself, so an unqualified one is shared.
 *
 * @return array{passed: bool, message: string}
 */
function runProgramVerdict(TestCase $case): array
{
    if ($case->goldenBeside($case->base(), 'stdout', Backend\compileBackend()) === null
        && $case->goldenBeside($case->base(), 'stderr', Backend\compileBackend()) === null
        && !\is_file($case->base() . '.exec.php')) {
        return ['passed' => true, 'message' => ''];
    }

    if (\str_ends_with($case->input, '.script')) {
        return runReplCase($case->input, MOGGI_PROJECT_ROOT);
    }

    return runExecTest($case->input, MOGGI_PROJECT_ROOT);
}

/**
 * The text a snapshot kind compares, produced from the file the snapshot is named after (the fixture
 * itself or one module of a project). IR fixtures carry IR directly; everything else is compiled.
 */
function testKindSnapshot(string $kind, string $input, string $backend): string
{
    $isIrFixture = \str_ends_with($input, '.ir.php');

    return match ($kind) {
        'tokens' => sourceStageDump($input, 'tokens'),
        'ast' => sourceStageDump($input, 'ast'),
        'typed-ast' => sourceStageDump($input, 'typed-ast'),
        'ir' => irLoweringDump($input),
        'opt-ir' => $isIrFixture ? optimizedIrDump($input) : optimizedIrFromSource($input, $backend),
        'emit' => $isIrFixture ? emitPhpArtifact($input) : emitFromSource($input),
        default => throw new TestFailure("`{$kind}` is not a snapshot kind"),
    };
}

/** The optimized IR of a source fixture: lower it, then optimize (`php` pins the backend). */
function optimizedIrFromSource(string $path, string $backend): string
{
    $outputs = compileFileCapturingErrorsBoth(testRelativePath($path), true, $backend);
    if ($outputs['opt-ir']['exitCode'] !== 0) {
        throw new TestFailure("opt-ir failed for {$path}:\n{$outputs['opt-ir']['output']}");
    }

    return entryArtifact($outputs['opt-ir']['output'], $path, '.opt-ir');
}

/** The source the entry module of `$path` compiles to; emit goldens are php-only. */
function emitFromSource(string $path): string
{
    $outputs = compileFileCapturingErrorsBoth(testRelativePath($path), true, 'php');
    if ($outputs['emit']['exitCode'] !== 0) {
        throw new TestFailure("emit failed for {$path}:\n{$outputs['emit']['output']}");
    }

    return entryArtifact($outputs['emit']['output'], $path, '.php');
}

/**
 * The artifact of the module a compile started from. A module compile also emits the imports, so the
 * artifacts are keyed by output path and the entry has to be picked out of them.
 */
function entryArtifact(mixed $outputs, string $input, string $suffix): string
{
    if (\is_string($outputs)) {
        return $outputs;
    }
    if (!\is_array($outputs)) {
        throw new TestFailure("no artifact produced for {$input}");
    }

    $want = \basename($input);
    $want = \substr($want, 0, (int) \strrpos($want, '.'));

    foreach ($outputs as $relative => $bytes) {
        if (\is_string($relative) && \is_string($bytes) && \str_ends_with($relative, '/' . $want . $suffix)) {
            return $bytes;
        }
    }
    foreach ($outputs as $relative => $bytes) {
        if (\is_string($relative) && \is_string($bytes) && \str_ends_with($relative, $want . $suffix)) {
            return $bytes;
        }
    }
    foreach ($outputs as $relative => $bytes) {
        if (\is_string($relative) && \is_string($bytes)
            && !\str_starts_with($relative, 'lib/') && \str_ends_with($relative, $suffix)) {
            return $bytes;
        }
    }

    throw new TestFailure("no {$suffix} artifact for {$input}");
}

/**
 * Every module of a project, entry included.
 *
 * @return list<string>
 */
function projectModuleFiles(string $input): array
{
    $dir = \dirname($input);
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'mog') {
            $files[] = $file->getPathname();
        }
    }
    \sort($files);

    return $files;
}

/** One project compile feeds every module's `emit` and `opt-ir` golden; the typed tree is per module. */
function compareProjectSnapshots(TestCase $case, string $backend): void
{
    $modules = projectModuleFiles($case->input);

    $emitted = null;
    $root = '';
    foreach ($modules as $module) {
        $base = \substr($module, 0, -4);
        if ($case->goldenBeside($base, 'typed-ast', $backend) !== null) {
            assertSameOutput($case->name, $case->goldenBeside($base, 'typed-ast', $backend), testKindSnapshot('typed-ast', $module, $backend), 'typed-ast');
        }
        if ($backend !== 'php') {
            continue;
        }
        if ($case->goldenBeside($base, 'emit', $backend) !== null || $case->goldenBeside($base, 'opt-ir', $backend) !== null) {
            if ($emitted === null) {
                [$files, $root] = projectClosure($case->input);
                $emitted = compileProjectBoth($files, $root);
            }
        }
        $key = \ltrim(\substr($base, \strlen($root)), '/');
        if ($case->goldenBeside($base, 'opt-ir', $backend) !== null) {
            $artifact = $emitted['opt-ir'][$key . '.opt-ir'] ?? null;
            if (!\is_string($artifact)) {
                throw new TestFailure("{$case->name}: no opt-ir output for {$key}");
            }
            assertSameOutput($case->name, $case->goldenBeside($base, 'opt-ir', $backend), $artifact, 'opt-ir');
        }
        if ($case->goldenBeside($base, 'emit', $backend) !== null) {
            $artifact = $emitted['emit'][$key . '.php'] ?? null;
            if (!\is_string($artifact)) {
                throw new TestFailure("{$case->name}: no emit output for {$key}");
            }
            assertSameOutput($case->name, $case->goldenBeside($base, 'emit', $backend), $artifact, 'emit');
        }
    }
}

/**
 * A project's source closure, entry modules included, and the root its artifacts are keyed from.
 *
 * This is what the CLI does before a project compile: without the library files a module that
 * imports the stdlib has nothing to resolve against, and the root is what the emitted `require`
 * paths between the app and `lib/` are relative to.
 *
 * @return array{list<string>, string}
 */
function projectClosure(string $input): array
{
    $libDirs = [];
    $bundled = bundledStdlibLibPath();
    if ($bundled !== null) {
        $libDirs[] = $bundled;
    }

    return projectSourceClosure(projectModuleFiles($input), $libDirs);
}

/**
 * The stage that owns a fixture's diagnostics: the directory says which one rejects the input.
 */
function errorStageFor(string $input): string
{
    $dir = \basename(\dirname($input));

    return match ($dir) {
        'lexer' => 'lexer',
        'parser' => 'parser',
        'ir' => 'ir',
        'optimize' => 'optimize',
        default => 'typecheck',
    };
}

/** @return array{passed: bool, message: string} */
function runErrorFixture(TestCase $case, string $golden, string $backend): array
{
    $stage = errorStageFor($case->input);

    try {
        match ($stage) {
            'lexer' => sourceStageDump($case->input, 'tokens'),
            'parser' => sourceStageDump($case->input, 'ast'),
            'ir' => irLoweringDump($case->input),
            'optimize' => optimizedIrFromSource($case->input, $backend),
            default => sourceStageDump($case->input, 'typed-ast'),
        };
    } catch (TestFailure $e) {
        // A harness failure is not a compiler diagnostic.
        throw $e;
    } catch (\Throwable $e) {
        $shape = diagnosticShapeFailure($e);
        if ($shape !== null) {
            return ['passed' => false, 'message' => $case->name . ': ' . $shape];
        }

        assertSameOutput(
            $case->name,
            $golden,
            normalizeDiagnosticPaths(diagnosticText($e), MOGGI_PROJECT_ROOT),
            'diagnostic',
        );

        return ['passed' => true, 'message' => ''];
    }

    return ['passed' => false, 'message' => 'expected the compiler to reject this fixture'];
}

/**
 * A project that must be rejected: one compile, one diagnostic, compared against the golden of the
 * module the diagnostic names. The compile goes through the project's source closure, like a build.
 *
 * @param list<string> $goldens
 * @return array{passed: bool, message: string}
 */
function runProjectErrorCase(TestCase $case, array $goldens, string $backend, string $projectRoot): array
{
    $modules = projectModuleFiles($case->input);

    try {
        [$files, $dir] = projectClosure($case->input);
        compileProjectBoth($files, $dir);
    } catch (TestFailure $e) {
        throw $e;
    } catch (\Throwable $e) {
        $diagnostic = normalizeDiagnosticPaths(diagnosticText($e), $projectRoot);
        $golden = goldenForDiagnostic($goldens, $modules, $diagnostic) ?? $goldens[0];
        $shape = diagnosticShapeFailure($e);
        if ($shape !== null) {
            return ['passed' => false, 'message' => $case->name . ': ' . $shape];
        }

        assertSameOutput($case->name, $golden, $diagnostic, 'diagnostic');

        return ['passed' => true, 'message' => ''];
    }

    return ['passed' => false, 'message' => 'expected the compiler to reject these modules'];
}

/**
 * The golden of the module the diagnostic is about: a project's goldens are named after the file
 * they describe, so the reported path picks the one to compare.
 *
 * @param list<string> $goldens
 * @param list<string> $modules
 */
function goldenForDiagnostic(array $goldens, array $modules, string $diagnostic): ?string
{
    foreach ($goldens as $golden) {
        $base = goldenModuleBase($golden);
        foreach ($modules as $module) {
            if (\substr($module, 0, -4) === $base && \str_contains($diagnostic, \basename($module))) {
                return $golden;
            }
        }
    }

    return null;
}

/** The module a golden belongs to: `lib/Platform.jvm.err.expected` → `lib/Platform`. */
function goldenModuleBase(string $golden): string
{
    $withoutKind = \substr($golden, 0, (int) \strrpos($golden, '.'));
    foreach (knownTestBackends() as $backend) {
        if (\str_ends_with($withoutKind, '.' . $backend)) {
            return \substr($withoutKind, 0, -\strlen($backend) - 1);
        }
    }

    return $withoutKind;
}
