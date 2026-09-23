<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Backend;
use Moggi\Cache;
use Moggi\Modules;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;
use Moggi\CLI\ArgCursor;

use function Moggi\CLI\parseBackendValue;
use function Moggi\CLI\printUsage;
use function Moggi\CLI\resolveCompileInputs;
use function Moggi\CLI\resolveLibraryDirs;
use function Moggi\Compiler\compileFile;
use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\findToolchainExecutable;

/**
 * Compile into a build root directory.
 *
 * Always emits the generated files (and directory structure) under $buildRoot
 * and runs backend packaging inside it. `moggi run` uses this directly; the
 * deployment artifact stays inside $buildRoot (php run skips packaging).
 *
 * $packageUnpacked mirrors the `--unpacked` flag for the backend packager.
 *
 * @return array{
 *   exitCode: int, outputRoot: ?string, entryModule: ?string, entryRelative: ?string,
 *   backend: string, native: bool
 * }
 */
function compileIntoRoot(
    string $inputPath,
    string $buildRoot,
    bool $optimize,
    string $backend,
    bool $strip = false,
    array $libDirs = [],
    bool $native = false,
    bool $packageUnpacked = false,
): array {
    $empty = static fn (int $code): array => [
        'exitCode' => $code,
        'outputRoot' => null,
        'entryModule' => null,
        'entryRelative' => null,
        'backend' => $backend,
        'native' => $native,
    ];

    if ($native && $backend !== 'jvm' && $backend !== 'dotnet') {
        \fwrite(STDERR, "error: --native is only supported with --backend jvm or --backend dotnet\n");

        return $empty(1);
    }

    Backend\setCompileBackend($backend);
    echo "backend: {$backend}\n";

    try {
        [$root, $files] = resolveCompileInputs($inputPath);
    } catch (\InvalidArgumentException $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        return $empty(1);
    } catch (LexError|ParseError|TypeError $e) {
        \fwrite(STDERR, $e->display());

        return $empty(1);
    }

    if ($files === []) {
        \fwrite(STDERR, "error: no .mog files found under {$inputPath}\n");

        return $empty(1);
    }

    $libDirs = resolveLibraryDirs($libDirs);

    // Resolve the whole-project module closure (app + every reachable library
    // module) so the output is a self-contained bundle.
    if ($libDirs !== []) {
        foreach ($libDirs as $dir) {
            if (\is_file(rtrim($dir, '/') . '/Data/Eq.mog')) {
                Modules\setStdlibLibPath($dir);
                break;
            }
        }

        try {
            [$files, $root] = Modules\projectSourceClosure($files, $libDirs);
        } catch (LexError|ParseError|TypeError|\RuntimeException $e) {
            if ($e instanceof LexError || $e instanceof ParseError || $e instanceof TypeError) {
                \fwrite(STDERR, $e->display());
            } else {
                \fwrite(STDERR, $e->getMessage() . "\n");
            }

            return $empty(1);
        }
    }

    if (\is_file($buildRoot)) {
        \fwrite(STDERR, "error: output path is a file: {$buildRoot}\n");

        return $empty(1);
    }

    if (!\is_dir($buildRoot) && !mkdir($buildRoot, 0777, true) && !\is_dir($buildRoot)) {
        \fwrite(STDERR, "error: cannot create output directory {$buildRoot}\n");

        return $empty(1);
    }

    $outputRoot = realpath($buildRoot);
    if ($outputRoot === false) {
        \fwrite(STDERR, "error: cannot resolve output directory {$buildRoot}\n");

        return $empty(1);
    }

    $rootPrefix = $root . DIRECTORY_SEPARATOR;

    if (!projectUsesModules($files)) {
        $entryRelative = count($files) === 1
            ? preg_replace('/\.mog$/', Backend\backendById($backend)->extension(), basename($files[0])) ?? basename($files[0])
            : null;
        foreach ($files as $sourcePath) {
            if (!str_starts_with($sourcePath, $rootPrefix)) {
                \fwrite(STDERR, "error: unexpected path outside input directory: {$sourcePath}\n");

                return $empty(1);
            }

            $relative = substr($sourcePath, strlen($rootPrefix));
            $ext = Backend\backendById($backend)->extension();

            try {
                Backend\setCompileBackend($backend);
                $output = compileFile($sourcePath, 'php', $optimize);
            } catch (LexError|ParseError|TypeError|\RuntimeException $e) {
                if ($e instanceof LexError || $e instanceof ParseError || $e instanceof TypeError) {
                    \fwrite(STDERR, $e->display());
                } else {
                    \fwrite(STDERR, $e->getMessage() . "\n");
                }

                return $empty(1);
            }

            $writes = \is_array($output)
                ? $output
                : [(preg_replace('/\.mog$/', $ext, $relative) ?? $relative) => $output];
            foreach ($writes as $writeRel => $bytes) {
                $writeRel = \str_replace('\\', '/', (string) $writeRel);
                $writePath = $outputRoot . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $writeRel);
                $writeParent = dirname($writePath);
                if (!\is_dir($writeParent) && !mkdir($writeParent, 0777, true) && !\is_dir($writeParent)) {
                    \fwrite(STDERR, "error: cannot create directory {$writeParent}\n");

                    return $empty(1);
                }
                if (\file_put_contents($writePath, $bytes) === false) {
                    \fwrite(STDERR, "error: cannot write {$writePath}\n");

                    return $empty(1);
                }
                echo "{$writeRel} -> {$writePath}\n";
            }
        }

        if (!bundleRuntime($outputRoot, $backend, [
            'jarName' => 'moggi-app.jar',
            'assemblyName' => 'moggi-app',
            'unpacked' => $packageUnpacked,
        ])) {
            return $empty(1);
        }
        if ($native && !buildNativeExecutable($outputRoot, $backend)) {
            return $empty(1);
        }

        echo count($files) . " file(s) compiled\n";

        return [
            'exitCode' => 0,
            'outputRoot' => $outputRoot,
            'entryModule' => null,
            'entryRelative' => $entryRelative,
            'backend' => $backend,
            'native' => $native,
        ];
    }

    try {
        $compiledBoth = Modules\compileProjectBoth($files, $root, $optimize, $strip);
        $compiled = $compiledBoth['emit'];
        $entryModule = $compiledBoth['entryModule'] ?? null;
        $entryRelative = $compiledBoth['entryRelative'] ?? null;
    } catch (LexError|ParseError|TypeError|\RuntimeException $e) {
        if ($e instanceof LexError || $e instanceof ParseError || $e instanceof TypeError) {
            \fwrite(STDERR, $e->display());
        } else {
            \fwrite(STDERR, $e->getMessage() . "\n");
        }

        return $empty(1);
    }

    foreach ($compiled as $relative => $output) {
        $outputPath = $outputRoot . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $outputParent = dirname($outputPath);
        if (!\is_dir($outputParent) && !mkdir($outputParent, 0777, true) && !\is_dir($outputParent)) {
            \fwrite(STDERR, "error: cannot create directory {$outputParent}\n");

            return $empty(1);
        }

        if (\file_put_contents($outputPath, $output) === false) {
            \fwrite(STDERR, "error: cannot write {$outputPath}\n");

            return $empty(1);
        }

        echo "{$relative} -> {$outputPath}\n";
    }

    if (!bundleRuntime($outputRoot, $backend, [
        'entryModule' => $entryModule,
        'jarName' => 'moggi-app.jar',
        'assemblyName' => 'moggi-app',
        'unpacked' => $packageUnpacked,
    ])) {
        return $empty(1);
    }
    if ($native && !buildNativeExecutable($outputRoot, $backend)) {
        return $empty(1);
    }

    echo count($compiled) . " file(s) compiled\n";

    return [
        'exitCode' => 0,
        'outputRoot' => $outputRoot,
        'entryModule' => \is_string($entryModule) ? $entryModule : null,
        'entryRelative' => \is_string($entryRelative) ? $entryRelative : null,
        'backend' => $backend,
        'native' => $native,
    ];
}

/**
 * `moggi compile`: produce the deployment artifact.
 *
 * Without --unpacked, the build happens in a private staging tree and only the
 * packaged artifact is placed at the destination:
 *   - `-o PATH`      → exactly PATH (moggi compile app -o app.phar)
 *   - no `-o`        → the entry file's basename plus the backend extension
 *                      (examples/twice/Main.mog → Main.phar; directory
 *                      builds use the entry module's file, usually Main)
 * Backend artifacts: php moggi-app.phar / jvm moggi-app.jar / dotnet
 * moggi-app.dll (+ .runtimeconfig.json / .deps.json sidecars).
 *
 * With --unpacked, `-o` names an output directory and the whole generated
 * tree is kept for inspection (no packaging).
 *
 * @return array{
 *   exitCode: int, outputRoot: ?string, artifactPath: ?string,
 *   entryModule: ?string, entryRelative: ?string, backend: string, native: bool
 * }
 */
function runCompile(
    string $inputPath,
    ?string $outputPath,
    bool $optimize,
    string $backend,
    bool $strip = false,
    array $libDirs = [],
    bool $native = false,
    bool $unpacked = false,
): array {
    $empty = static fn (int $code): array => [
        'exitCode' => $code,
        'outputRoot' => null,
        'artifactPath' => null,
        'entryModule' => null,
        'entryRelative' => null,
        'backend' => $backend,
        'native' => $native,
    ];

    if ($native && $backend !== 'jvm' && $backend !== 'dotnet') {
        \fwrite(STDERR, "error: --native is only supported with --backend jvm or --backend dotnet\n");

        return $empty(1);
    }

    if ($unpacked) {
        // Development build: -o names the output directory that keeps the
        // whole generated tree (files + directory structure) for inspection.
        if ($outputPath === null) {
            \fwrite(STDERR, "error: --unpacked requires -o <output-dir>\n\n");
            printUsage();

            return $empty(1);
        }

        $result = compileIntoRoot($inputPath, $outputPath, $optimize, $backend, $strip, $libDirs, $native, true);

        return $result + ['artifactPath' => null];
    }

    // Packaged build: -o names the artifact file itself.
    if ($outputPath !== null && \is_dir($outputPath)) {
        \fwrite(STDERR, "error: output path is a directory (pass --unpacked to build a directory): {$outputPath}\n");

        return $empty(1);
    }

    $staging = sys_get_temp_dir() . '/moggi-build-' . getmypid() . '-' . bin2hex(random_bytes(3));
    $result = compileIntoRoot($inputPath, $staging, $optimize, $backend, $strip, $libDirs, $native, false);
    if ($result['exitCode'] !== 0 || $result['outputRoot'] === null) {
        removeOutputTree($staging);

        return $result + ['artifactPath' => null];
    }

    $base = artifactBaseName($inputPath, $result['entryRelative']);
    $destBase = $outputPath !== null
        ? $outputPath
        : getcwd() . DIRECTORY_SEPARATOR . $base . artifactExtension($backend);

    $moved = movePackagedArtifacts($staging, $destBase, $backend, $native);
    removeOutputTree($staging);

    foreach ($moved as $rel => $final) {
        echo artifactLabel($rel) . ": {$final}\n";
    }

    $primary = null;
    foreach (artifactPrimaryRel($backend) as $rel) {
        if (isset($moved[$rel])) {
            $primary = $moved[$rel];

            break;
        }
    }

    return $result + ['artifactPath' => $primary];
}

/** Relative paths (inside the staging root) of each backend's packaged artifact, primary first. */
function artifactPrimaryRel(string $backend): array
{
    return match ($backend) {
        'php' => ['moggi-app.phar'],
        'jvm' => ['moggi-app.jar', 'moggi-app'],
        'dotnet' => ['moggi-app.dll', 'moggi-app', 'moggi-app.runtimeconfig.json', 'moggi-app.deps.json'],
    };
}

function artifactLabel(string $rel): string
{
    return match ($rel) {
        'moggi-app.phar' => 'phar',
        'moggi-app.jar' => 'jar',
        'moggi-app.dll' => 'dll',
        'moggi-app' => 'native',
        default => $rel,
    };
}

/**
 * Base name (no extension) the deployment artifact gets when no `-o` is given:
 * it is the entry source file's basename — `Demo-Math.mog`
 * produces `Demo-Math.phar`; a directory build with entry `Main.mog`
 * produces `Main.phar`.
 */
function artifactBaseName(string $inputPath, ?string $entryRelative): string
{
    if (\is_file($inputPath)) {
        $base = basename($inputPath);

        return preg_replace('/\.mog$/', '', $base) ?? $base;
    }

    if (\is_string($entryRelative) && $entryRelative !== '') {
        $base = basename($entryRelative);

        return preg_replace('/\.(php|class|il)$/', '', $base) ?? $base;
    }

    $base = basename(rtrim($inputPath, '/\\'));

    return $base !== '' ? $base : 'moggi-app';
}

function artifactExtension(string $backend): string
{
    return match ($backend) {
        'php' => '.phar',
        'jvm' => '.jar',
        'dotnet' => '.dll',
    };
}

/**
 * Move the packaged artifact (plus runtime sidecars) from the staging tree to
 * their final destination derived from $destBase.
 *
 * `moggi compile app -o app.phar` → `app.phar` (and, for .NET, the runtime
 * sidecars `app.runtimeconfig.json` / `app.deps.json` next to it, since the
 * runtime resolves the shared framework through them).
 *
 * @return array<string, string> rel => final absolute path, for the ones moved
 */
function movePackagedArtifacts(string $staging, string $destBase, string $backend, bool $native): array
{
    $moved = [];

    $move = static function (string $rel, string $dest) use ($staging, &$moved): bool {
        $src = $staging . DIRECTORY_SEPARATOR . $rel;
        if (!\is_file($src)) {
            return false;
        }
        $dir = dirname($dest);
        if (!\is_dir($dir) && !mkdir($dir, 0777, true) && !\is_dir($dir)) {
            return false;
        }
        if (!@\rename($src, $dest)) {
            if (!\copy($src, $dest)) {
                return false;
            }
            @\unlink($src);
        }
        $moved[$rel] = $dest;

        return true;
    };

    // -o is the exact artifact path, so sidecar names derive from it.
    $sideBase = preg_replace('/\.dll$/', '', $destBase) ?? $destBase;

    if ($backend === 'php') {
        $move('moggi-app.phar', $destBase);
    } elseif ($backend === 'jvm') {
        $move('moggi-app.jar', $destBase);
    } elseif ($backend === 'dotnet') {
        $move('moggi-app.dll', $destBase);
        $move('moggi-app.runtimeconfig.json', $sideBase . '.runtimeconfig.json');
        $move('moggi-app.deps.json', $sideBase . '.deps.json');
    }

    if ($native && ($backend === 'jvm' || $backend === 'dotnet')) {
        $binBase = preg_replace('/\.(jar|dll|phar|exe)$/', '', $destBase) ?? $destBase;
        $move('moggi-app', $binBase);
    }

    return $moved;
}

/**
 * Copy moggi's runtime support library into the build output.
 *
 * The emitted PHP `require`s the runtime at `<output>/_runtime.php` (see
 * runtimeRequirePath), so shipping it makes the build a self-contained bundle
 * that runs with no extra steps. This is the app's runtime dependency, not part
 * of the moggi toolchain, so it belongs in the bundle. PHP backend only.
 */
function bundleRuntime(string $outputRoot, string $backend, array $options = []): bool
{
    try {
        Backend\setCompileBackend($backend);
        Backend\backendById($backend)->packageOutput($outputRoot, $options);
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        return false;
    }

    return true;
}

/**
 * GraalVM's `native-image`: in `$JAVA_HOME/bin` when a GraalVM JDK is
 * configured, else on PATH. A plain JDK does not provide it.
 */
function resolveNativeImageExecutable(): ?string
{
    return findToolchainExecutable('JAVA_HOME', 'native-image') ?? findExecutable('native-image');
}

function buildNativeExecutable(string $outputRoot, string $backend, string $binaryName = 'moggi-app'): bool
{
    if ($backend === 'jvm') {
        return buildJvmNativeExecutable($outputRoot, 'moggi-app.jar', $binaryName);
    }
    if ($backend === 'dotnet') {
        return Backend\DotNet\buildDotNetNativeExecutable($outputRoot, 'moggi-app', $binaryName);
    }
    \fwrite(STDERR, "error: --native is not supported for backend `{$backend}`\n");

    return false;
}

function buildJvmNativeExecutable(string $outputRoot, string $jarName, string $binaryName): bool
{
    $nativeImage = resolveNativeImageExecutable();
    if ($nativeImage === null) {
        \fwrite(STDERR, "error: native-image not found (install GraalVM or set JAVA_HOME)\n");

        return false;
    }

    $jarPath = $outputRoot . DIRECTORY_SEPARATOR . $jarName;
    if (!\is_file($jarPath)) {
        \fwrite(STDERR, "error: missing {$jarPath}\n");

        return false;
    }

    $cmd = \implode(' ', \array_map('escapeshellarg', [
        $nativeImage,
        '-jar',
        $jarName,
        '--no-fallback',
        $binaryName,
    ]));
    $cwd = getcwd();
    if (chdir($outputRoot) === false) {
        \fwrite(STDERR, "error: cannot chdir to output directory {$outputRoot}\n");

        return false;
    }
    passthru($cmd, $code);
    if ($cwd !== false) {
        chdir($cwd);
    }

    if ($code !== 0) {
        \fwrite(STDERR, "error: native-image failed with exit code {$code}\n");

        return false;
    }

    return true;
}

/** @param list<string> $files */
function projectUsesModules(array $files): bool
{
    foreach ($files as $path) {
        try {
            // Memoized by realpath+mtime; shares the parse with later header reads
            // instead of doing an independent lex+parse for the pre-check.
            $header = Modules\cachedModuleHeader($path);
        } catch (\Throwable) {
            continue;
        }

        if (Modules\headerIsModuleSource($header)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{
 *   input: string, output: ?string, mode: string, optimize: bool,
 *   backend: string, strip: bool, libDirs: list<string>, native: bool,
 *   unpacked: bool, libPhp: ?string
 * }
 */
function parseCompileArgs(array $argv): array
{
    $input = null;
    $output = null;
    $mode = 'php';
    $optimize = true;
    // Dead-binding elimination is on by default for executable builds; it is a
    // no-op for libraries (no `main`), so it never drops reachable exports.
    $strip = true;
    $backend = 'php';
    $libDirs = [];
    $native = false;
    $unpacked = false;
    $libPhp = null;

    $cursor = new ArgCursor($argv, 2);

    while (($arg = $cursor->current()) !== null) {
        if ($cursor->atHelp()) {
            exit(0);
        }

        if ($arg === '--tokens') {
            $cursor->take();
            $mode = 'tokens';
            continue;
        }

        if ($arg === '--ast') {
            $cursor->take();
            $mode = 'ast';
            continue;
        }

        if ($arg === '--typed-ast') {
            $cursor->take();
            $mode = 'typed-ast';
            continue;
        }

        if ($arg === '--ir') {
            $cursor->take();
            $mode = 'ir';
            continue;
        }

        if ($arg === '--opt-ir') {
            $cursor->take();
            $mode = 'opt-ir';
            continue;
        }

        if ($arg === '--no-opt') {
            $cursor->take();
            $optimize = false;
            continue;
        }

        if ($arg === '--native') {
            $cursor->take();
            $native = true;
            continue;
        }

        if ($arg === '--unpacked') {
            $cursor->take();
            $unpacked = true;
            continue;
        }

        if ($arg === '--lib') {
            $libDirs[] = $cursor->takeLibDir();
            continue;
        }

        if ($arg === '--lib-php') {
            $libPhp = $cursor->takeValue('--lib-php');
            continue;
        }

        if ($arg === '--strip') {
            $cursor->take();
            $strip = true;
            continue;
        }

        if ($arg === '--no-strip') {
            $cursor->take();
            $strip = false;
            continue;
        }

        if ($arg === '--no-cache') {
            $cursor->take();
            $cursor->takeNoCache();
            continue;
        }

        if ($arg === '--backend') {
            $backend = parseBackendValue($cursor->takeValue('--backend'));
            continue;
        }

        if ($arg === '-o') {
            $output = $cursor->takeValue('-o');
            continue;
        }

        if (str_starts_with($arg, '-')) {
            \fwrite(STDERR, "error: unknown option {$arg}\n\n");
            printUsage();
            exit(1);
        }

        if ($input === null) {
            $input = $cursor->take();
            continue;
        }

        \fwrite(STDERR, "error: unexpected argument {$arg}\n\n");
        printUsage();
        exit(1);
    }

    if ($input === null) {
        \fwrite(STDERR, "error: compile requires <input-dir|source.mog>\n\n");
        printUsage();
        exit(1);
    }

    return [
        'input' => $input,
        'output' => $output,
        'mode' => $mode,
        'optimize' => $optimize,
        'backend' => $backend,
        'strip' => $strip,
        'libDirs' => $libDirs,
        'native' => $native,
        'unpacked' => $unpacked,
        'libPhp' => $libPhp,
    ];
}

function removeOutputTree(string $dir): void
{
    if (!\is_dir($dir)) {
        return;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

/**
 * @param list<string> $appArgs
 */
function jarManifestHasMainClass(string $jarPath): bool
{
    $zip = new \ZipArchive();
    if ($zip->open($jarPath) !== true) {
        return false;
    }
    $manifest = $zip->getFromName('META-INF/MANIFEST.MF');
    $zip->close();
    if (!\is_string($manifest) || $manifest === '') {
        return false;
    }

    return preg_match('/^Main-Class:\s*\S+/mi', $manifest) === 1;
}

function executeBuiltApp(
    string $outputRoot,
    string $backend,
    ?string $entryRelative,
    array $appArgs = [],
    bool $native = false,
): int {
    if ($backend === 'jvm') {
        if ($native) {
            $binary = $outputRoot . DIRECTORY_SEPARATOR . 'moggi-app';
            if (!\is_file($binary)) {
                \fwrite(STDERR, "error: missing {$binary}\n");

                return 1;
            }
            $cmd = \array_merge([$binary], $appArgs);
            $cmdLine = \implode(' ', \array_map('escapeshellarg', $cmd));
            passthru($cmdLine, $code);

            return $code;
        }
        $jar = $outputRoot . DIRECTORY_SEPARATOR . 'moggi-app.jar';
        if (!\is_file($jar)) {
            \fwrite(STDERR, "error: missing {$jar}\n");

            return 1;
        }
        if (!jarManifestHasMainClass($jar)) {
            \fwrite(STDERR, "error: no entry `main` found to run\n");

            return 1;
        }
        $java = getenv('JAVA_HOME')
            ? (rtrim((string) getenv('JAVA_HOME'), '/\\') . '/bin/java')
            : 'java';
        $cmd = \array_merge([$java, '-jar', $jar], $appArgs);
        $cmdLine = \implode(' ', \array_map('escapeshellarg', $cmd));
        passthru($cmdLine, $code);

        return $code;
    }

    if ($backend === 'dotnet') {
        if ($native) {
            $binary = $outputRoot . DIRECTORY_SEPARATOR . 'moggi-app';
            if (!\is_file($binary)) {
                \fwrite(STDERR, "error: missing {$binary}\n");

                return 1;
            }
            $cmd = \array_merge([$binary], $appArgs);
            $cmdLine = \implode(' ', \array_map('escapeshellarg', $cmd));
            passthru($cmdLine, $code);

            return $code;
        }
        $dll = $outputRoot . DIRECTORY_SEPARATOR . 'moggi-app.dll';
        if (!\is_file($dll)) {
            \fwrite(STDERR, "error: missing {$dll}\n");

            return 1;
        }
        $dotnet = Backend\DotNet\resolveDotnetExecutable();
        if ($dotnet === null) {
            \fwrite(STDERR, "error: dotnet SDK not found (install dotnet-sdk_8 or set DOTNET_ROOT)\n");

            return 1;
        }
        $cmd = \array_merge([$dotnet, $dll], $appArgs);
        $cmdLine = \implode(' ', \array_map('escapeshellarg', $cmd));
        passthru($cmdLine, $code);

        return $code;
    }

    if ($backend === 'php') {
        if ($entryRelative === null || $entryRelative === '') {
            \fwrite(STDERR, "error: no entry `main` found to run\n");

            return 1;
        }
        $phpFile = $outputRoot . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $entryRelative);
        if (!\is_file($phpFile)) {
            \fwrite(STDERR, "error: missing entry file {$phpFile}\n");

            return 1;
        }
        $cmd = \array_merge([PHP_BINARY, $phpFile], $appArgs);
        $cmdLine = \implode(' ', \array_map('escapeshellarg', $cmd));
        passthru($cmdLine, $code);

        return $code;
    }

    \fwrite(STDERR, "error: cannot run backend `{$backend}`\n");

    return 1;
}

function runCompileCommand(array $argv): int
{
    $options = parseCompileArgs($argv);
    $input = $options['input'];

    if (!\is_file($input) && !\is_dir($input)) {
        \fwrite(STDERR, "error: not a file or directory: {$input}\n");

        return 1;
    }

    if ($options['libPhp'] !== null) {
        try {
            Modules\setStdlibPhpPath($options['libPhp']);
        } catch (TypeError $e) {
            \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    // Introspection (`--tokens`/`--ast`/`--typed-ast`/`--ir`/`--opt-ir`) compiles a single file to
    // stdout (or to the -o FILE dump target).
    if ($options['mode'] !== 'php') {
        return compileSingleFile($input, $options);
    }

    $result = runCompile(
        $input,
        $options['output'],
        $options['optimize'],
        $options['backend'],
        $options['strip'],
        $options['libDirs'],
        $options['native'],
        $options['unpacked'],
    );

    return $result['exitCode'];
}

/**
 * Compile one file for introspection, writing the result to stdout (or to
 * `-o FILE` when a path is given, which is only meaningful for dump targets).
 *
 * @param array<string, mixed> $options
 */
function compileSingleFile(string $input, array $options): int
{
    if (\is_dir($input)) {
        \fwrite(STDERR, "error: introspection requires a single .mog file, got directory {$input}\n");

        return 1;
    }

    try {
        Backend\setCompileBackend($options['backend']);
        foreach ($options['libDirs'] as $dir) {
            if (\is_file(rtrim($dir, '/') . '/Data/Eq.mog')) {
                Modules\setStdlibLibPath($dir);
                break;
            }
        }

        $output = compileFile($input, $options['mode'], $options['optimize']);
    } catch (LexError|ParseError|TypeError $e) {
        \fwrite(STDERR, $e->display());

        return 1;
    } catch (\RuntimeException $e) {
        \fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    }

    if (\is_array($output)) {
        // jvm/.NET also emit an optional `.moggi.map` beside the artifact;
        // ignore it so one primary artifact can be piped to stdout.
        $primary = [];
        foreach ($output as $rel => $bytes) {
            if (!str_ends_with((string) $rel, '.moggi.map')) {
                $primary[$rel] = $bytes;
            }
        }
        if (count($primary) !== 1) {
            \fwrite(STDERR, "error: output has multiple files; pass -o <output-dir>\n");

            return 1;
        }
        $output = reset($primary);
    }

    if ($options['output'] !== null) {
        if (@\file_put_contents($options['output'], $output) === false) {
            \fwrite(STDERR, "error: cannot write {$options['output']}\n");

            return 1;
        }

        return 0;
    }

    echo $output;

    return 0;
}
