<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Backend;
use Moggi\Cache;
use Moggi\Modules;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Backend\Php\Dependencies\composerVendorRoots;
use function Moggi\CLI\parseBackendValue;
use function Moggi\CLI\printUsage;
use function Moggi\CLI\resolveCompileInputs;
use function Moggi\CLI\resolveLibraryDirs;
use function Moggi\Compiler\compileFile;
use function Moggi\Compiler\executableName;
use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\findToolchainExecutable;
use function Moggi\Modules\bundledStdlibLibPath;
use function Moggi\Modules\cachedModuleHeader;
use function Moggi\Pipeline\setEntryModules;

/**
 * Pin the library roots this compile searches, so the backend dependency scanners
 * see the same set the module resolver does.
 *
 * The first root that carries a `base.moggi` becomes the primary standard library;
 * every other root is registered as an extra. Extra roots matter beyond module
 * lookup: a package's vendored jars, CLR metadata and PHP helpers live under
 * whichever root it was installed into, and a dependency tree resolved by
 * `moggi build` is one of those roots.
 *
 * @param list<string> $libDirs
 */
function registerLibraryRoots(array $libDirs): void
{
    $primary = null;
    foreach ($libDirs as $dir) {
        if (Modules\isLibraryRoot($dir)) {
            $primary = $dir;
            break;
        }
    }

    if ($primary !== null) {
        Modules\setStdlibLibPath($primary);
    }

    foreach ($libDirs as $dir) {
        if ($dir !== $primary && \is_dir($dir)) {
            Modules\addLibraryRoot($dir);
        }
    }
}

/**
 * Compile into a build root directory.
 *
 * Always emits the generated files (and directory structure) under $buildRoot
 * and runs backend packaging inside it. `moggi run` uses this directly; the
 * deployment artifact stays inside $buildRoot (php run skips packaging).
 *
 * $packageUnpacked mirrors the `--unpacked` flag for the backend packager.
 *
 * The entry is the module this compile was handed a file for; a directory build
 * keeps the conventional `Main`. Naming it here lets a package carry several
 * executables without forcing every entry to be called `Main`, and lets
 * `main = Suite` name a module called `Suite`.
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

    if ($native && !backendSupportsNative($backend)) {
        \fwrite(STDERR, "error: --native is only supported with --backend php, jvm or dotnet\n");

        return $empty(1);
    }

    Backend\setCompileBackend($backend);
    echo "backend: {$backend}\n";

    try {
        [$root, $files] = resolveCompileInputs($inputPath, $libDirs);
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

    $entryModule = 'Main';
    $realInput = \realpath($inputPath);
    if ($realInput !== false && \is_file($realInput)) {
        $header = cachedModuleHeader($realInput);
        if (\is_string($header['module'] ?? null) && $header['module'] !== '') {
            $entryModule = $header['module'];
        }
    }
    setEntryModules([$entryModule]);

    $libDirs = resolveLibraryDirs($libDirs);

    if ($libDirs !== []) {
        registerLibraryRoots($libDirs);

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

        if (!bundleComposerVendor($outputRoot, $backend)) {
            return $empty(1);
        }
        if (!bundleRuntime($outputRoot, $backend, [
            'entryRelative' => $entryRelative,
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

    if (!bundleComposerVendor($outputRoot, $backend)) {
        return $empty(1);
    }
    if (!bundleRuntime($outputRoot, $backend, [
        'entryModule' => $entryModule,
        'entryRelative' => $entryRelative,
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
 * With --native a second artifact is written beside the archive — the
 * executable — so the two never share a path: `-o app` names the executable
 * and puts the archive at `app.phar`. The executable is the primary artifact
 * then (`artifactPath`), reported first; without --native the archive is.
 *
 * With --unpacked, `-o` names an output directory and the whole generated
 * tree is kept for inspection (no packaging). A native executable is built
 * from the packaged archive, so --native and --unpacked are refused together.
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

    if ($native && !backendSupportsNative($backend)) {
        \fwrite(STDERR, "error: --native is only supported with --backend php, jvm or dotnet\n");

        return $empty(1);
    }

    if ($native && $unpacked) {
        \fwrite(STDERR, "error: --native cannot be combined with --unpacked; a native executable is built from the packaged archive, so drop one of the two\n");

        return $empty(1);
    }

    if ($unpacked) {
        if ($outputPath === null) {
            \fwrite(STDERR, "error: --unpacked requires -o <output-dir>\n\n");
            printUsage();

            return $empty(1);
        }

        $result = compileIntoRoot($inputPath, $outputPath, $optimize, $backend, $strip, $libDirs, $native, true);

        return $result + ['artifactPath' => null];
    }

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

    try {
        $moved = movePackagedArtifacts($staging, $destBase, $backend, $native);
    } catch (\RuntimeException $e) {
        removeOutputTree($staging);
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        return $empty(1);
    }
    removeOutputTree($staging);

    $order = artifactPrimaryRel($backend, $native);
    foreach ($order as $rel) {
        if (isset($moved[$rel])) {
            echo artifactLabel($rel) . ": {$moved[$rel]}\n";
        }
    }
    foreach ($moved as $rel => $final) {
        if (!\in_array($rel, $order, true)) {
            echo artifactLabel($rel) . ": {$final}\n";
        }
    }

    $primary = null;
    foreach ($order as $rel) {
        if (isset($moved[$rel])) {
            $primary = $moved[$rel];

            break;
        }
    }

    return $result + ['artifactPath' => $primary];
}

/**
 * Relative paths (inside the staging root) of each backend's packaged artifact,
 * primary first. A native build's deliverable is the executable, so it leads
 * the archive; without it the archive is what a consumer deploys.
 */
function artifactPrimaryRel(string $backend, bool $native = false): array
{
    $archive = match ($backend) {
        'php' => 'moggi-app.phar',
        'jvm' => 'moggi-app.jar',
        'dotnet' => 'moggi-app.dll',
    };
    $executable = executableName('moggi-app');
    $sidecars = $backend === 'dotnet'
        ? ['moggi-app.runtimeconfig.json', 'moggi-app.deps.json']
        : [];

    return $native
        ? [$executable, $archive, ...$sidecars]
        : [$archive, $executable, ...$sidecars];
}

function artifactLabel(string $rel): string
{
    return match ($rel) {
        'moggi-app.phar' => 'phar',
        'moggi-app.jar' => 'jar',
        'moggi-app.dll' => 'dll',
        default => $rel === executableName('moggi-app') ? 'native' : $rel,
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
 * A native build writes two artifacts, so they must never share a path: the
 * archive keeps the backend extension and the executable takes the name the
 * caller asked for. An `-o` that already carries that extension (`-o app.phar`)
 * is unchanged and only the executable is derived from it; any other `-o` names
 * the executable, so the archive gets the extension appended — `-o app` gives
 * `app.phar` beside `app` (and beside `app.exe` on Windows, whose suffix is the
 * executable's own), and `-o app.exe` gives `app.exe.phar` beside `app.exe`.
 *
 * For .NET, third-party assemblies the app is linked against travel out beside
 * it: the deps document names them, so they are packaged artifacts, not build
 * leftovers.
 *
 * The destinations are enumerated before anything moves, so a path claimed by
 * two artifacts of one run is refused instead of the second silently
 * overwriting the first. A pre-existing file at a destination is replaced: that
 * is what naming it with `-o` asks for.
 *
 * Every destination is the caller's `-o` with something appended, never a path
 * re-joined with the host's separator, so the two artifacts aimed at one file are
 * compared in one spelling: a vendored assembly derived from the destination
 * reaches the same string as the archive beside it on every host.
 *
 * Nothing is written to a final destination until every artifact is on disk:
 * each is first moved to a temporary name beside where it belongs, and only then
 * renamed into place. A failure part way through therefore leaves no half-built
 * artifact behind — the temporaries are removed, and so is any destination this
 * run created that was not already there. A destination that *did* already exist
 * is never deleted; the run replaced it, and removing the replacement would only
 * lose the file the caller asked to overwrite.
 *
 * @return array<string, string> rel => final absolute path, for the ones moved
 *
 * @throws \RuntimeException when two packaged artifacts resolve to one path, or
 *                           when an artifact cannot be written to its destination
 */
function movePackagedArtifacts(string $staging, string $destBase, string $backend, bool $native): array
{
    $binBase = $native && backendSupportsNative($backend)
        ? preg_replace('/\.(jar|dll|phar|exe)$/', '', $destBase) ?? $destBase
        : null;

    $archiveDest = $destBase;
    if ($binBase !== null && ($binBase === $destBase || executableName($binBase) === $destBase)) {
        $archiveDest = $destBase . artifactExtension($backend);
    }

    $sideBase = preg_replace('/\.dll$/', '', $archiveDest) ?? $archiveDest;

    $plan = [];
    if ($backend === 'php') {
        $plan[] = ['moggi-app.phar', $archiveDest];
    } elseif ($backend === 'jvm') {
        $plan[] = ['moggi-app.jar', $archiveDest];
    } elseif ($backend === 'dotnet') {
        $plan[] = ['moggi-app.dll', $archiveDest];
        $plan[] = ['moggi-app.runtimeconfig.json', $sideBase . '.runtimeconfig.json'];
        $plan[] = ['moggi-app.deps.json', $sideBase . '.deps.json'];
        foreach (\glob($staging . DIRECTORY_SEPARATOR . '*.dll') ?: [] as $dll) {
            $name = \basename($dll);
            if ($name !== 'moggi-app.dll') {
                $plan[] = [$name, \dirname($archiveDest) . '/' . $name];
            }
        }
    }

    if ($binBase !== null) {
        $plan[] = [executableName('moggi-app'), executableName($binBase)];
    }

    $sources = [];
    $destinations = [];
    foreach ($plan as [$rel, $dest]) {
        if (!\is_file($staging . DIRECTORY_SEPARATOR . $rel)) {
            continue;
        }
        if (isset($destinations[$dest])) {
            throw new \RuntimeException("output path {$dest} would be written twice by this build");
        }
        $sources[] = [$rel, $dest];
        $destinations[$dest] = true;
    }

    $temporaries = [];
    $staged = [];
    foreach ($sources as [$rel, $dest]) {
        $dir = dirname($dest);
        if (!\is_dir($dir) && !@mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw new \RuntimeException("cannot create directory {$dir}");
        }
        $temporary = $dest . '.moggi-tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $src = $staging . DIRECTORY_SEPARATOR . $rel;
        if (!@\rename($src, $temporary)) {
            if (!\copy($src, $temporary)) {
                removeStagedArtifacts($temporaries);
                throw new \RuntimeException("cannot write {$dest}");
            }
            @\unlink($src);
        }
        $temporaries[] = $temporary;
        $staged[] = [$rel, $temporary, $dest];
    }

    $moved = [];
    $created = [];
    foreach ($staged as $index => [$rel, $temporary, $dest]) {
        $existed = \is_file($dest) || \is_link($dest);
        if (!@\rename($temporary, $dest)) {
            removeStagedArtifacts(\array_slice($temporaries, $index));
            foreach ($created as $destination) {
                @\unlink($destination);
            }
            throw new \RuntimeException("cannot write {$dest}");
        }
        if (!$existed) {
            $created[] = $dest;
        }
        $moved[$rel] = $dest;
    }

    return $moved;
}

/**
 * Remove the staging files of an interrupted artifact move.
 *
 * @param list<string> $paths
 */
function removeStagedArtifacts(array $paths): void
{
    foreach ($paths as $path) {
        @\unlink($path);
    }
}

/**
 * Copy a resolved Composer tree into the build output.
 *
 * The entry module requires the autoloader by a path relative to itself, so the
 * tree has to sit beside the generated files — inside the PHAR for a packaged
 * build, on disk for `--unpacked`. One tree only: `moggi build` writes one
 * manifest for the whole project, so two roots offering one would be two vendors
 * with no defined merge order, which is an error rather than a silent pick.
 */
function bundleComposerVendor(string $outputRoot, string $backend): bool
{
    if ($backend !== 'php') {
        return true;
    }

    $roots = composerVendorRoots();
    if ($roots === []) {
        return true;
    }
    if (\count($roots) > 1) {
        \fwrite(
            STDERR,
            "error: more than one Composer tree in the library roots:\n  "
                . \implode("\n  ", $roots)
                . "\nrebuild so the coordinates resolve into one\n",
        );

        return false;
    }

    $source = \rtrim($roots[0], '/') . '/vendor';
    $target = \rtrim($outputRoot, '/') . '/vendor';
    if (!copyTreeInto($source, $target)) {
        \fwrite(STDERR, "error: cannot copy {$source} into {$target}\n");

        return false;
    }

    return true;
}

/** Copy a directory tree, replacing whatever is at the destination. */
function copyTreeInto(string $source, string $target): bool
{
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($items as $item) {
        $relative = \substr($item->getPathname(), \strlen($source) + 1);
        $destination = $target . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!\is_dir($destination) && !\mkdir($destination, 0777, true) && !\is_dir($destination)) {
                return false;
            }
            continue;
        }
        $parent = \dirname($destination);
        if (!\is_dir($parent) && !\mkdir($parent, 0777, true) && !\is_dir($parent)) {
            return false;
        }
        if (!\copy($item->getPathname(), $destination)) {
            return false;
        }
    }

    return true;
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

/** Does this backend have a native toolchain that builds an executable? */
function backendSupportsNative(string $backend): bool
{
    return \in_array($backend, ['php', 'jvm', 'dotnet'], true);
}

function buildNativeExecutable(string $outputRoot, string $backend, string $binaryName = 'moggi-app'): bool
{
    if ($backend === 'php') {
        return Backend\Php\buildPhpNativeExecutable($outputRoot, 'moggi-app.phar', $binaryName);
    }
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
/** @param list<string> $argv */
function parseCompileArgs(array $argv): array
{
    $spec = compileSpec();

    if (wantsHelp($argv)) {
        echo commandHelp($spec);
        exit(0);
    }

    try {
        $options = parseArgs($argv, $spec);
        requireDirectories($options['lib'], '--lib');
    } catch (\InvalidArgumentException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n\n" . $spec->help . "\n");
        exit(1);
    }

    $input = $options['positionals'][0] ?? null;
    if ($input === null) {
        \fwrite(STDERR, "error: compile requires <input-dir|source.mog>\n\n" . $spec->help . "\n");
        exit(1);
    }

    $output = $options['output'];
    if ($output === '') {
        \fwrite(STDERR, "error: -o requires a non-empty path\n\n" . $spec->help . "\n");
        exit(1);
    }

    $mode = 'php';
    foreach (['tokens', 'ast', 'typed-ast', 'ir', 'opt-ir'] as $flag) {
        if ($options[$flag]) {
            $mode = $flag;
            break;
        }
    }

    return [
        'input' => $input,
        'output' => $output,
        'mode' => $mode,
        'optimize' => !$options['noOpt'],
        'backend' => parseBackendValue($options['backend']),
        'strip' => !$options['noStrip'],
        'libDirs' => $options['lib'],
        'native' => (bool) $options['native'],
        'unpacked' => (bool) $options['unpacked'],
        'libPhp' => $options['lib-php'],
    ];
}

/**
 * `moggi compile`'s command line: one input, the backend, the library roots, the
 * print modes.
 *
 * The print modes (`--tokens`, `--ast`, `--typed-ast`, `--ir`, `--opt-ir`) are
 * mutually exclusive in intent; if more than one is given the first in the list
 * below wins, because a second would contradict the first.
 */
function compileUsage(): string
{
    return <<<HELP
    usage:
      moggi compile <input-dir|source.mog> [-o PATH] [options]

    Compile a directory (or one file) to a single deployable artifact. Without
    -o, the artifact is named after the entry source file.

    options:
      --backend B      compile target: php, jvm, dotnet (default: php)
      -o PATH          the artifact to write (with --unpacked, an output directory)
      --lib PATH       extra module search root. Repeatable.
      --lib-php PATH   a precompiled stdlib PHP tree to link against
      --unpacked       keep the generated tree on disk instead of packaging
      --native         build a native executable
      --no-opt         skip optimizations when generating code
      --no-strip       keep bindings unreachable from `main`
      --no-cache       bypass the on-disk compile cache for this run
      --tokens         print the token stream
      --ast            print the parsed AST
      --typed-ast      print the type-checked AST
      --ir             print IR before optimization
      --opt-ir         print optimized IR
      -h, --help       show this help
    HELP;
}

function compileSpec(): CommandSpec
{
    return new CommandSpec('compile', compileUsage(), [
        ['name' => 'tokens'],
        ['name' => 'ast'],
        ['name' => 'typed-ast'],
        ['name' => 'ir'],
        ['name' => 'opt-ir'],
        ['name' => 'noOpt'],
        ['name' => 'native'],
        ['name' => 'unpacked'],
        ['name' => 'strip'],
        ['name' => 'noStrip'],
        ['name' => 'noCache'],
        ['name' => 'backend', 'value' => true],
        ['name' => 'lib', 'value' => true, 'repeat' => true],
        ['name' => 'lib-php', 'value' => true],
    ]);
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
            $binary = $outputRoot . DIRECTORY_SEPARATOR . executableName('moggi-app');
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
            $binary = $outputRoot . DIRECTORY_SEPARATOR . executableName('moggi-app');
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
        if ($native) {
            $binary = $outputRoot . DIRECTORY_SEPARATOR . executableName('moggi-app');
            if (!\is_file($binary)) {
                \fwrite(STDERR, "error: missing {$binary}\n");

                return 1;
            }
            $cmd = \array_merge([$binary], $appArgs);
            $cmdLine = \implode(' ', \array_map('escapeshellarg', $cmd));
            passthru($cmdLine, $code);

            return $code;
        }
        if ($entryRelative === null || $entryRelative === '') {
            \fwrite(STDERR, "error: no entry `main` found to run\n");

            return 1;
        }
        $phpFile = $outputRoot . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $entryRelative);
        if (!\is_file($phpFile)) {
            \fwrite(STDERR, "error: missing entry file {$phpFile}\n");

            return 1;
        }
        $php = resolvePhpInterpreter();
        if ($php === null) {
            \fwrite(STDERR, "error: no PHP interpreter to run generated code (build with --native, or put `php` on PATH)\n");

            return 1;
        }
        $cmd = \array_merge([$php, $phpFile], $appArgs);
        $cmdLine = \implode(' ', \array_map('escapeshellarg', $cmd));
        passthru($cmdLine, $code);

        return $code;
    }

    \fwrite(STDERR, "error: cannot run backend `{$backend}`\n");

    return 1;
}

/**
 * The PHP that runs generated code: this process when it has one, else a bundled
 * or on-`PATH` `php`.
 *
 * A micro SAPI runtime has no command line and cannot start a second process, so
 * `PHP_BINARY` is empty in a native binary; with `--native` there is nothing to
 * interpret, which is the other way to run generated code.
 */
function resolvePhpInterpreter(): ?string
{
    if (PHP_BINARY !== '') {
        return PHP_BINARY;
    }

    $lib = bundledStdlibLibPath();
    if ($lib !== null) {
        $bundled = \dirname($lib) . '/runtime/php/bin/' . executableName('php');
        if (\is_file($bundled)) {
            return $bundled;
        }
    }

    return findExecutable('php');
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
        registerLibraryRoots($options['libDirs']);

        $output = compileFile($input, $options['mode'], $options['optimize']);
    } catch (LexError|ParseError|TypeError $e) {
        \fwrite(STDERR, $e->display());

        return 1;
    } catch (\RuntimeException $e) {
        \fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    }

    if (\is_array($output)) {
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
