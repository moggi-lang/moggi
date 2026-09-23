<?php declare(strict_types=1);

namespace Moggi\Repl\EvalRunner;

use Moggi\Modules;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Pipeline\PipelineArtifacts;
use Moggi\Pipeline\PipelineRequest;
use Moggi\Pipeline\PipelineStage;
use Moggi\Repl\State;
use Moggi\Syntax\Ast;

use function Moggi\Repl\ensureBasePrepared;
use function Moggi\Backend\backendById;
use function Moggi\Backend\Php\runtimeOutputPath;
use function Moggi\Backend\codegen;
use function Moggi\Backend\Inspect\captureProcess;
use function Moggi\Backend\Inspect\removeTree;
use function Moggi\Backend\DotNet\resolveDotnetExecutable;
use function Moggi\Backend\setCompileBackend;
use function Moggi\Modules\filterCodegenImportsForIr;
use function Moggi\Pipeline\run as pipelineRun;

/**
 * Evaluate Interactive.<entryName>.
 * PHP: in-process include of a generation namespace (deps compiled once).
 * JVM / DotNet: temporary Main wrapper + subprocess.
 */
function runEntry(State $state, string $entryName): string
{
    if ($state->backend === 'php') {
        return runEntryPhp($state, $entryName);
    }

    return runEntrySubprocess($state, $entryName);
}

function runEntryPhp(State $state, string $entryName): string
{
    setCompileBackend('php');
    if (!$state->prepared instanceof Modules\PreparedProject
        || !$state->typedInteractive instanceof Ast\Program) {
        throw new \RuntimeException('interactive module is not prepared');
    }

    $depsDir = ensurePhpDeps($state);
    $gen = $state->generation;
    $ns = 'Interactive\\G' . $gen;
    $liveDir = $state->sessionDir . '/php-live/G' . $gen;
    if (!is_dir($liveDir) && !mkdir($liveDir, 0777, true) && !is_dir($liveDir)) {
        throw new \RuntimeException("cannot create {$liveDir}");
    }

    $ctx = [
        'prepared' => $state->prepared,
        'checked' => $state->typedInteractive,
        'importContext' => $state->prepared->importContexts[$state->moduleName] ?? [],
    ];
    $artifacts = emitInteractivePhp($state, $ctx, $ns, $depsDir);
    $state->lastFragment = $artifacts;
    $state->lastFragmentFocus = [$entryName];

    $phpPath = $liveDir . '/Interactive.php';
    $emit = $artifacts->emit;
    $phpSource = null;
    if (\is_string($emit)) {
        $phpSource = $emit;
    } elseif (\is_array($emit)) {
        foreach ($emit as $rel => $bytes) {
            if (\is_string($rel) && \is_string($bytes) && str_ends_with($rel, '.php')) {
                $phpSource = $bytes;
            }
        }
    }
    if (!\is_string($phpSource)) {
        throw new \RuntimeException('expected PHP emit string');
    }
    // The module carries its own map, so an interactive trace resolves the same
    // way a compiled module's does.
    file_put_contents($phpPath, $phpSource);

    $fqEntry = '\\' . $ns . '\\' . backendById('php')->symbolName($entryName);
    $stdoutCapture = beginStdoutCapture();
    ob_start();
    try {
        require $phpPath;
        if (!\is_callable($fqEntry)) {
            throw new \RuntimeException("missing entry {$fqEntry}");
        }
        $result = $fqEntry();
        // Nullary IO () thunks return a boxed `__io` action; Main bootstrap runs
        // them, so the in-process REPL must unwrap here too.
        while (\is_array($result) && ($result[0] ?? null) === '__io' && \is_callable($result[1] ?? null)) {
            $result = ($result[1])();
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        endStdoutCapture($stdoutCapture);
        throw $e;
    } finally {
        if (ob_get_level() > 0) {
            $out = ob_get_clean();
        } else {
            $out = '';
        }
    }

    $out = endStdoutCapture($stdoutCapture) . $out;

    return $out;
}

/**
 * Capture fwrite/STDOUT from evaluated IO (ob_start alone misses native fwrite).
 *
 * @return array{filter: resource|false}
 */
function beginStdoutCapture(): array
{
    ReplStdoutCaptureFilter::$buffer = '';
    if (!\defined('STDOUT') || !\is_resource(STDOUT)) {
        return ['filter' => false];
    }

    if (!\in_array('moggi_repl_stdout_capture', \stream_get_filters(), true)) {
        \stream_filter_register('moggi_repl_stdout_capture', ReplStdoutCaptureFilter::class);
    }

    $filter = \stream_filter_append(STDOUT, 'moggi_repl_stdout_capture', STREAM_FILTER_WRITE);

    return ['filter' => $filter];
}

/**
 * @param array{filter: resource|false} $capture
 */
function endStdoutCapture(array $capture): string
{
    if (\is_resource($capture['filter'])) {
        \stream_filter_remove($capture['filter']);
        $capture['filter'] = false;
    }
    $out = ReplStdoutCaptureFilter::$buffer;
    ReplStdoutCaptureFilter::$buffer = '';

    return $out;
}

/** @internal */
final class ReplStdoutCaptureFilter extends \php_user_filter
{
    public static string $buffer = '';

    /**
     * @param resource $in
     * @param resource $out
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = \stream_bucket_make_writeable($in)) {
            self::$buffer .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_FEED_ME;
    }
}

/**
 * Compile/cache non-Interactive PHP modules once per loaded dependency set.
 * Built from the stdlib (and :load paths), not from the Interactive-only prepare,
 * so emit paths stay under the library root (Data/Int.php) instead of a /tmp + /home
 * common-prefix layout.
 *
 * The tree is not packaged: it is never run as an artifact, only `require`d into
 * this process.
 */
function ensurePhpDeps(State $state): string
{
    $depsDir = $state->sessionDir . '/php-deps';
    $fingerprint = phpDepsFingerprint($state);
    if ($state->phpDepsFingerprint === $fingerprint
        && is_dir($depsDir . '/runtime')
        && $state->phpDepsModuleRel !== []) {
        return $depsDir;
    }

    // A rebuilt dependency tree goes to an empty directory: a stale module left behind would
    // be a second copy of one this process already loaded (`Cannot redeclare`).
    if (is_dir($depsDir)) {
        removeTree($depsDir);
    }
    if (!mkdir($depsDir, 0777, true) && !is_dir($depsDir)) {
        throw new \RuntimeException("cannot create {$depsDir}");
    }

    // Reuse the typecheck backdrop prepare (same Prelude/:load closure).
    $prepared = ensureBasePrepared($state);
    $files = [];
    foreach ($prepared->units as $unit) {
        if (($unit['synthetic'] ?? false) === true || !isset($unit['path'])) {
            continue;
        }
        $files[] = $unit['path'];
    }
    if ($files === []) {
        throw new \RuntimeException('REPL PHP deps: no Prelude found under --lib');
    }

    $built = Modules\compileProjectBoth($files, $prepared->rootPrefix, $state->optimize, strip: false);
    $moduleRel = [];
    foreach ($prepared->units as $name => $unit) {
        if (($unit['synthetic'] ?? false) === true || !isset($unit['path'])) {
            continue;
        }
        $moduleRel[$name] = Modules\mogPathToOutputRelative($unit['path'], $prepared->rootPrefix);
    }

    foreach ($built['emit'] as $rel => $bytes) {
        $path = $depsDir . '/' . $rel;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        file_put_contents($path, $bytes);
    }

    writeDepsRuntimeShim($depsDir);

    $state->phpDepsModuleRel = $moduleRel;
    $state->phpDepsFingerprint = $fingerprint;

    return $depsDir;
}

/**
 * Point the dependency tree's `_runtime.php` at the compiler's own runtime.
 *
 * The tree is loaded into the running REPL process, and the runtime declares
 * top-level functions: PHP binds those while *compiling* a file, so two copies of
 * the runtime in one process are a `Cannot redeclare` fatal that no guard inside
 * the file can prevent. The host may already have loaded the compiler's copy —
 * host exceptions are rendered through it, see `formatReplThrowable` — so the
 * emitted `require_once __DIR__ . '/../_runtime.php'` lines have to resolve to
 * that same file. `require_once` dedupes on the resolved path, so a bootstrap
 * that forwards to the compiler's runtime keeps exactly one copy in memory.
 */
function writeDepsRuntimeShim(string $depsDir): void
{
    $runtimeFiles = backendById('php')->runtimeFiles();
    if ($runtimeFiles === []) {
        return;
    }

    $lines = [
        '<?php declare(strict_types=1);',
        '',
        '// REPL dependency tree: share the compiler runtime. A second copy of it in',
        '// this process would redeclare its top-level functions.',
    ];
    foreach ($runtimeFiles as $file) {
        $lines[] = 'require_once ' . var_export($file, true) . ';';
    }
    $shim = implode("\n", $lines) . "\n";

    foreach ($runtimeFiles as $file) {
        $path = $depsDir . '/' . runtimeOutputPath($file);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        file_put_contents($path, $shim);
    }
}

function phpDepsFingerprint(State $state): string
{
    $parts = [
        'backend=php',
        'opt=' . ($state->optimize ? '1' : '0'),
        'loaded=' . implode(',', $state->loadedPaths),
        'libs=' . implode(',', $state->libDirs),
    ];

    return hash('sha256', implode('|', $parts));
}

/**
 * @param array{prepared: Modules\PreparedProject, checked: Ast\Program, importContext: array<string, mixed>} $ctx
 */
function emitInteractivePhp(
    State $state,
    array $ctx,
    string $namespace,
    ?string $absoluteDepsRoot,
    bool $optimize = true,
): PipelineArtifacts {
    $artifacts = pipelineRun(new PipelineRequest(
        purpose: CompilePurpose::Repl,
        filename: $state->interactivePath(),
        typedProgram: $ctx['checked'],
        optimize: $optimize,
        backend: 'php',
        importContext: $ctx['importContext'],
        emitOptions: [],
    ), PipelineStage::IrOpt);

    $ir = $artifacts->irOpt ?? throw new \RuntimeException('missing optimized IR');
    $codegen = $ctx['importContext']['codegen'] ?? [];
    if ($absoluteDepsRoot !== null && $state->phpDepsModuleRel !== []) {
        // Prefer deps-layout paths so requires resolve under php-deps/.
        $codegen = [
            ...$codegen,
            'moduleOutputPaths' => [
                ...($codegen['moduleOutputPaths'] ?? []),
                ...$state->phpDepsModuleRel,
            ],
        ];
    }
    $imports = filterCodegenImportsForIr(
        $ir,
        $codegen,
        'Interactive.php',
        null,
        $absoluteDepsRoot,
    );
    $imports['importedData'] = $ctx['importContext']['data'] ?? [];
    $imports['externalFnRuntimeArity'] = $ctx['importContext']['externalFnRuntimeArity']
        ?? ($codegen['externalFnRuntimeArity'] ?? []);

    $emitOptions = [
        'namespace' => $namespace,
        'moduleName' => $state->moduleName,
        'imports' => $imports,
        'outputRelative' => 'Interactive.php',
        'externalFns' => $imports['externalFns'] ?? [],
        'externalFnRuntimeArity' => $imports['externalFnRuntimeArity'] ?? [],
    ];
    if ($absoluteDepsRoot !== null) {
        $runtime = rtrim(\str_replace('\\', '/', $absoluteDepsRoot), '/') . '/_runtime.php';
        $emitOptions['runtimeRequire'] = \var_export($runtime, true);
    }

    $artifacts->emit = codegen($ir, $state->interactivePath(), $emitOptions);

    return $artifacts;
}

/**
 * Evaluate via a temporary Main wrapper and a packaged subprocess.
 *
 * The whole closure is compiled and packaged per evaluation, so this is the
 * dominant cost on the JVM / .NET backends. The scratch tree is removed again
 * once the process has run; only the captured output survives.
 */
function runEntrySubprocess(State $state, string $entryName): string
{
    setCompileBackend($state->backend);
    $work = $state->sessionDir . '/eval-g' . $state->generation;
    if (!is_dir($work) && !mkdir($work, 0777, true) && !is_dir($work)) {
        throw new \RuntimeException("cannot create {$work}");
    }

    try {
        $interactiveSrc = file_get_contents($state->interactivePath());
        if ($interactiveSrc === false) {
            throw new \RuntimeException('missing interactive source');
        }
        file_put_contents($work . '/Interactive.mog', $interactiveSrc);

        $mainSrc = "module Main where\n\nimport {$state->moduleName}\n\n"
            . "main :: IO ()\n"
            . "main = {$entryName}\n";
        file_put_contents($work . '/Main.mog', $mainSrc);

        $inputFiles = [$work . '/Main.mog', $work . '/Interactive.mog', ...$state->loadedPaths];
        [$files, $root] = Modules\projectSourceClosure($inputFiles, $state->libDirs);
        $outDir = $work . '/out';
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            throw new \RuntimeException("cannot create {$outDir}");
        }

        $built = Modules\compileProjectBoth($files, $root, $state->optimize, strip: true);
        foreach ($built['emit'] as $rel => $bytes) {
            $path = $outDir . '/' . $rel;
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException("cannot create {$dir}");
            }
            file_put_contents($path, $bytes);
        }

        $ok = packageAndCapture($state->backend, $outDir, $built['entryModule'] ?? 'Main', $built['entryRelative'] ?? null);
        if ($ok['exitCode'] !== 0) {
            $report = trim($ok['stderr'] !== '' ? $ok['stderr'] : $ok['stdout']);
            throw new \RuntimeException($report !== '' ? $report : 'REPL evaluation failed');
        }

        return $ok['stdout'];
    } finally {
        removeTree($work);
    }
}

/**
 * @return array{stdout: string, stderr: string, exitCode: int}
 */
function packageAndCapture(string $backend, string $outDir, string $entryModule, ?string $entryRelative): array
{
    setCompileBackend($backend);
    backendById($backend)->packageOutput($outDir, [
        'entryModule' => $entryModule,
    ]);

    if ($backend === 'php') {
        $entry = $entryRelative ?? (Modules\moduleNameToPath($entryModule) . '.php');
        $candidates = [
            $outDir . DIRECTORY_SEPARATOR . 'Main.php',
            $outDir . DIRECTORY_SEPARATOR . $entry,
        ];
        $phpFile = null;
        foreach ($candidates as $c) {
            if (is_file($c)) {
                $phpFile = $c;
                break;
            }
        }
        if ($phpFile === null) {
            $found = glob($outDir . '/**/Main.php') ?: [];
            $phpFile = $found[0] ?? null;
        }
        if ($phpFile === null || !is_file($phpFile)) {
            return ['stdout' => '', 'stderr' => 'missing Main.php', 'exitCode' => 1];
        }

        return captureProcess(['php', $phpFile], dirname($phpFile));
    }

    if ($backend === 'dotnet') {
        require_once dirname(__DIR__) . '/backend/dotnet/package.php';
        $dll = $outDir . '/moggi-app.dll';
        if (!is_file($dll)) {
            return ['stdout' => '', 'stderr' => 'missing moggi-app.dll', 'exitCode' => 1];
        }
        $dotnet = resolveDotnetExecutable();
        if ($dotnet === null) {
            return ['stdout' => '', 'stderr' => 'dotnet SDK not found', 'exitCode' => 1];
        }

        return captureProcess([$dotnet, $dll], $outDir);
    }

    $jar = $outDir . '/moggi-app.jar';
    if (!is_file($jar)) {
        return ['stdout' => '', 'stderr' => 'missing moggi-app.jar', 'exitCode' => 1];
    }

    return captureProcess(['java', '-jar', $jar], $outDir);
}
