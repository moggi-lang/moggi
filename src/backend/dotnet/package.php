<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet;

use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\findToolchainExecutable;
use function Moggi\Compiler\runProcess;
use function Moggi\Debug\sourceMapFrames;

require_once __DIR__ . '/../../executables.php';

/**
 * Package ILASM sources into a runnable net8.0 DLL.
 *
 * Fast path: invoke `ilasm` directly (same tool Microsoft.NET.Sdk.IL wraps).
 * That avoids ~1s of `dotnet`/MSBuild process overhead per package — dominant
 * in multi-program workloads such as the test suite.
 *
 * Fallback: flocked, once-restored Microsoft.NET.Sdk.IL workspace +
 * `dotnet build --no-restore` when `ilasm` is unavailable.
 *
 * Native AOT still uses `dotnet publish -p:PublishAot=true` on the ilproj.
 */

/** `$DOTNET_ROOT/dotnet` when set and executable, else `dotnet` on PATH. */
function resolveDotnetExecutable(): ?string
{
    return findToolchainExecutable('DOTNET_ROOT', 'dotnet', '')
        ?? findExecutable('dotnet');
}

/** @return array<string, string> */
function dotnetCliEnv(): array
{
    $env = [];
    foreach ($_ENV as $k => $v) {
        if (\is_string($k) && \is_string($v)) {
            $env[$k] = $v;
        }
    }
    foreach ($_SERVER as $k => $v) {
        if (\is_string($k) && \is_string($v) && !isset($env[$k])) {
            $env[$k] = $v;
        }
    }
    if (!isset($env['PATH'])) {
        $path = \getenv('PATH');
        if (\is_string($path)) {
            $env['PATH'] = $path;
        }
    }
    if (!isset($env['HOME'])) {
        $home = \getenv('HOME');
        if (\is_string($home)) {
            $env['HOME'] = $home;
        }
    }
    $env['DOTNET_NOLOGO'] = '1';
    $env['DOTNET_CLI_TELEMETRY_OPTOUT'] = '1';
    $env['DOTNET_SKIP_FIRST_TIME_EXPERIENCE'] = '1';

    return $env;
}

/**
 * Workspace/lock root for the two SDK-based .NET paths: the one-time ilasm
 * bootstrap and the `dotnet build` fallback. Holds no build output and no
 * NuGet packages, and is untouched when ilasm resolves directly.
 *
 * The first candidate that yields a writable directory wins, so a read-only
 * `$XDG_CACHE_HOME` or `$HOME` falls through to the system temp dir instead of
 * failing the build.
 */
function dotnetWorkspaceRoot(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }

    $candidates = [];
    $xdg = \getenv('XDG_CACHE_HOME');
    if (\is_string($xdg) && $xdg !== '') {
        $candidates[] = \rtrim($xdg, '/\\') . DIRECTORY_SEPARATOR . 'moggi' . DIRECTORY_SEPARATOR . 'dotnet-workspace';
    }
    $home = \getenv('HOME');
    if (\is_string($home) && $home !== '') {
        $candidates[] = $home . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'moggi'
            . DIRECTORY_SEPARATOR . 'dotnet-workspace';
    }
    $candidates[] = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moggi-dotnet-workspace';

    $seen = [];
    foreach ($candidates as $candidate) {
        if (isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;
        if (!\is_dir($candidate) && !@\mkdir($candidate, 0777, true) && !\is_dir($candidate)) {
            continue;
        }
        if (!\is_writable($candidate)) {
            continue;
        }

        return $root = $candidate;
    }

    throw new \RuntimeException(
        'cannot create a writable .NET workspace root (tried: ' . \implode(', ', $candidates) . ')',
    );
}

/**
 * Resolve the CoreCLR `ilasm` binary: `ilasm` on `PATH`, then the NuGet runtime
 * package, then a one-time Sdk.IL restore that puts that package in the NuGet
 * cache. Returns null when none of them yields a binary, which sends the caller
 * to the `dotnet build` fallback.
 */
function resolveIlasmExecutable(?string $dotnet = null): ?string
{
    static $cached = false;
    static $resolved = null;
    if ($cached) {
        return $resolved;
    }
    $cached = true;

    $onPath = findExecutable('ilasm');
    if ($onPath !== null) {
        return $resolved = $onPath;
    }

    $found = findIlasmInNuGetCaches();
    if ($found !== null) {
        return $resolved = $found;
    }

    $dotnet ??= resolveDotnetExecutable();
    if ($dotnet !== null) {
        try {
            ensureIlasmPackageRestored($dotnet);
        } catch (\RuntimeException) {
            // Offline, or a platform the RID mapping does not cover: leave the
            // build to the `dotnet build` path rather than failing here.
            return $resolved = null;
        }
        $found = findIlasmInNuGetCaches();
        if ($found !== null) {
            return $resolved = $found;
        }
    }

    return $resolved = null;
}

function findIlasmInNuGetCaches(): ?string
{
    $rid = nugetIlasmRuntimeId();
    $package = "runtime.{$rid}.microsoft.netcore.ilasm";
    $rel = $package . DIRECTORY_SEPARATOR . '6.0.0' . DIRECTORY_SEPARATOR
        . 'runtimes' . DIRECTORY_SEPARATOR . $rid . DIRECTORY_SEPARATOR
        . 'native' . DIRECTORY_SEPARATOR . 'ilasm';

    foreach (nugetPackageRoots() as $root) {
        $candidate = $root . DIRECTORY_SEPARATOR . $rel;
        if (\is_file($candidate) && \is_executable($candidate)) {
            return $candidate;
        }
        // Prefer any installed version if 6.0.0 is absent.
        $pkgDir = $root . DIRECTORY_SEPARATOR . $package;
        if (!\is_dir($pkgDir)) {
            continue;
        }
        $versions = \scandir($pkgDir) ?: [];
        \rsort($versions, SORT_NATURAL);
        foreach ($versions as $ver) {
            if ($ver === '.' || $ver === '..') {
                continue;
            }
            $candidate = $pkgDir . DIRECTORY_SEPARATOR . $ver . DIRECTORY_SEPARATOR
                . 'runtimes' . DIRECTORY_SEPARATOR . $rid . DIRECTORY_SEPARATOR
                . 'native' . DIRECTORY_SEPARATOR . 'ilasm';
            if (\is_file($candidate) && \is_executable($candidate)) {
                return $candidate;
            }
        }
    }

    return null;
}

/** @return list<string> */
function nugetPackageRoots(): array
{
    $roots = [];
    $seen = [];
    $add = static function (string $path) use (&$roots, &$seen): void {
        $real = \realpath($path) ?: $path;
        if (isset($seen[$real]) || !\is_dir($real)) {
            return;
        }
        $seen[$real] = true;
        $roots[] = $real;
    };

    $env = \getenv('NUGET_PACKAGES');
    if (\is_string($env) && $env !== '') {
        $add($env);
    }
    $home = \getenv('HOME');
    if (\is_string($home) && $home !== '') {
        $add($home . DIRECTORY_SEPARATOR . '.nuget' . DIRECTORY_SEPARATOR . 'packages');
    }
    // Nix / custom DOTNET_ROOT layouts sometimes mirror packages under the SDK.
    $dotnetRoot = \getenv('DOTNET_ROOT');
    if (\is_string($dotnetRoot) && $dotnetRoot !== '') {
        $add(\rtrim($dotnetRoot, '/\\') . DIRECTORY_SEPARATOR . 'packages');
    }

    return $roots;
}

function nugetIlasmRuntimeId(): string
{
    $os = \PHP_OS_FAMILY;
    $arch = \php_uname('m');
    $isArm = \in_array($arch, ['aarch64', 'arm64'], true);
    if ($os === 'Windows') {
        return $isArm ? 'win-arm64' : 'win-x64';
    }
    if ($os === 'Darwin') {
        return $isArm ? 'osx-arm64' : 'osx-x64';
    }

    return $isArm ? 'linux-arm64' : 'linux-x64';
}

/**
 * One-time restore of Microsoft.NET.Sdk.IL so the ilasm runtime package lands
 * in the NuGet cache. Does not assemble application IL.
 */
function ensureIlasmPackageRestored(string $dotnet): void
{
    $root = dotnetWorkspaceRoot();
    $marker = $root . DIRECTORY_SEPARATOR . 'ilasm-restored';
    if (\is_file($marker)) {
        return;
    }

    $lockPath = $root . DIRECTORY_SEPARATOR . 'build.lock';
    $lock = \fopen($lockPath, 'c+');
    if ($lock === false) {
        throw new \RuntimeException("cannot open {$lockPath}");
    }
    if (!\flock($lock, LOCK_EX)) {
        \fclose($lock);
        throw new \RuntimeException("cannot lock {$lockPath}");
    }

    try {
        if (\is_file($marker)) {
            return;
        }
        $ws = $root . DIRECTORY_SEPARATOR . 'ilasm-bootstrap';
        if (!\is_dir($ws) && !\mkdir($ws, 0777, true) && !\is_dir($ws)) {
            throw new \RuntimeException("cannot create {$ws}");
        }
        $proj = $ws . DIRECTORY_SEPARATOR . 'IlasmBootstrap.ilproj';
        $body = <<<'XML'
<Project Sdk="Microsoft.NET.Sdk.IL/8.0.0">
  <PropertyGroup>
    <TargetFramework>net8.0</TargetFramework>
    <OutputType>Library</OutputType>
    <EnableDefaultItems>false</EnableDefaultItems>
  </PropertyGroup>
</Project>
XML;
        \file_put_contents($proj, $body);
        runDotnetCli($dotnet, ['restore', $proj, '--nologo', '-v', 'q'], $ws, dotnetCliEnv());
        \file_put_contents($marker, "ok\n");
    } finally {
        \flock($lock, LOCK_UN);
        \fclose($lock);
    }
}

/**
 * Collect `.il` files under $outputRoot, add runtime IL, assemble to DLL.
 *
 * @param array<string, mixed> $options entryModule?, assemblyName?
 */
function packageDotNetOutput(string $outputRoot, array $options = []): void
{
    $dotnet = resolveDotnetExecutable();
    if ($dotnet === null && resolveIlasmExecutable(null) === null) {
        throw new \RuntimeException('dotnet SDK not found (install dotnet-sdk_8 or set PATH)');
    }

    $assemblyName = (string) ($options['assemblyName'] ?? 'moggi-app');
    $entryModule = $options['entryModule'] ?? null;
    $isExe = \is_string($entryModule) && $entryModule !== '';
    $outputType = $isExe ? 'Exe' : 'Library';

    require_once __DIR__ . '/runtime_abi.php';
    require_once __DIR__ . '/il.php';
    require_once __DIR__ . '/frames.php';
    $rtRel = 'rt' . DIRECTORY_SEPARATOR . 'Moggi.Rt.il';
    $rtPath = $outputRoot . DIRECTORY_SEPARATOR . $rtRel;
    $rtDir = \dirname($rtPath);
    if (!\is_dir($rtDir) && !\mkdir($rtDir, 0777, true) && !\is_dir($rtDir)) {
        throw new \RuntimeException("cannot create {$rtDir}");
    }
    \file_put_contents($rtPath, languageRuntime());

    // The frame table is assembled from every module's compile-time sequence
    // points, so it is emitted once here rather than per module.
    \file_put_contents(
        $outputRoot . DIRECTORY_SEPARATOR . 'rt' . DIRECTORY_SEPARATOR . 'Moggi.Frames.il',
        buildFrames(collectDotNetFrames($outputRoot)),
    );

    // Demand-driven: emit externs for exactly the assemblies the generated IL
    // references (beyond the fixed preamble). The set is discovered from the IL
    // itself, so no backend knowledge of any particular assembly is required.
    $extraAssemblies = dotNetOutputReferencedAssemblies($outputRoot);

    $headerPath = $outputRoot . DIRECTORY_SEPARATOR . '_header.il';
    $header = Il\assemblyExternPreamble()
        . Il\assemblyExternsFor($extraAssemblies)
        . ".assembly '{$assemblyName}'\n{\n}\n"
        . ".module '{$assemblyName}.dll'\n";
    \file_put_contents($headerPath, $header);

    // Kept for Native AOT / Sdk.IL fallback.
    $ilprojBody = <<<XML
<Project Sdk="Microsoft.NET.Sdk.IL/8.0.0">
  <PropertyGroup>
    <TargetFramework>net8.0</TargetFramework>
    <OutputType>{$outputType}</OutputType>
    <AssemblyName>{$assemblyName}</AssemblyName>
    <RootNamespace>Moggi</RootNamespace>
    <Nullable>disable</Nullable>
    <Optimize>true</Optimize>
    <DebugType>portable</DebugType>
    <DebugSymbols>true</DebugSymbols>
    <ProduceReferenceAssembly>false</ProduceReferenceAssembly>
    <EnableDefaultItems>false</EnableDefaultItems>
  </PropertyGroup>
  <ItemGroup>
    <Compile Include="**/*.il" Exclude="obj/**;bin/**" />
  </ItemGroup>
</Project>
XML;
    $projPath = $outputRoot . DIRECTORY_SEPARATOR . $assemblyName . '.ilproj';
    \file_put_contents($projPath, $ilprojBody);

    if (!empty($options['unpacked'])) {
        // Explicit unpacked/development build: keep the generated IL, runtime
        // sources, header and project file on disk (the tree can be rebuilt by
        // hand with `dotnet build <name>.ilproj`); assembly packaging is
        // skipped, like the PHAR in the PHP backend.
        return;
    }

    $ilasm = resolveIlasmExecutable($dotnet);
    if ($ilasm !== null) {
        assembleDotNetWithIlasm($ilasm, $outputRoot, $assemblyName, $isExe);

        return;
    }

    if ($dotnet === null) {
        throw new \RuntimeException('dotnet SDK not found (install dotnet-sdk_8 or set PATH)');
    }
    buildDotNetIlProject($dotnet, $outputRoot, $assemblyName, $ilprojBody);
}

/**
 * Assemble with CoreCLR ilasm directly (mirrors Sdk.IL's Exec of ilasm).
 */
function assembleDotNetWithIlasm(
    string $ilasm,
    string $outputRoot,
    string $assemblyName,
    bool $isExe,
): void {
    $ilFiles = collectIlSources($outputRoot);
    if ($ilFiles === []) {
        throw new \RuntimeException("no .il sources under {$outputRoot}");
    }

    $dllPath = $outputRoot . DIRECTORY_SEPARATOR . $assemblyName . '.dll';
    // Framework-dependent apps are always a .dll; OutputType=Exe only adds an
    // apphost via the SDK. `dotnet app.dll` runs either shape.
    // No `-OPTIMIZE`: it rewrites long branches to short, which would invalidate
    // the compile-time IL offsets baked into Moggi.Frames.
    $args = [
        $ilasm,
        '-NOLOGO',
        '-QUIET',
        '-DLL',
        '-OUTPUT=' . $dllPath,
        ...$ilFiles,
    ];
    $result = runProcess($args, $outputRoot);
    if ($result['exitCode'] !== 0 || !\is_file($dllPath)) {
        throw new \RuntimeException(
            'ilasm failed'
            . ($result['stdout'] !== '' || $result['stderr'] !== ''
                ? "\n" . \trim($result['stdout'] . "\n" . $result['stderr'])
                : ''),
        );
    }

    // Sidecars so `dotnet app.dll` resolves the shared framework.
    writeDotNetRuntimeConfig($outputRoot, $assemblyName);
    if ($isExe) {
        writeDotNetDepsJson($outputRoot, $assemblyName);
    }
}

/**
 * Collect every `.moggi.map` frame row from the output tree.
 *
 * The maps are the compiler's own artifacts; each frame carries a
 * `(class, method, IL offset)` key plus the pre-rendered `.mog` line.
 *
 * @return list<array<string, mixed>>
 */
function collectDotNetFrames(string $outputRoot): array
{
    $frames = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($outputRoot, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.moggi.map')) {
            continue;
        }
        $raw = (string) \file_get_contents($file->getPathname());
        if ($raw === '') {
            continue;
        }
        foreach (sourceMapFrames($raw) as $frame) {
            $frames[] = $frame;
        }
    }

    return $frames;
}

/**
 * Assemblies referenced by `[Assembly]Type` qualifiers in the generated IL,
 * excluding the fixed preamble. Sorted for deterministic output.
 *
 * @return list<string>
 */
function dotNetOutputReferencedAssemblies(string $outputRoot): array
{
    $found = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($outputRoot, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'il') {
            continue;
        }
        if ($file->getFilename() === '_header.il') {
            continue;
        }
        $chunk = \file_get_contents($file->getPathname(), false, null, 0, 512 * 1024);
        if (!\is_string($chunk)) {
            continue;
        }
        if (\preg_match_all('/\[([A-Za-z_][A-Za-z0-9_.]*)\]/', $chunk, $m) > 0) {
            foreach ($m[1] as $name) {
                $found[$name] = true;
            }
        }
    }

    foreach (Il\preambleAssemblyNames() as $name) {
        unset($found[$name]);
    }

    $names = \array_keys($found);
    \sort($names);

    return $names;
}

/** @return list<string> absolute paths; `_header.il` first */
function collectIlSources(string $outputRoot): array
{
    $header = null;
    $rest = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($outputRoot, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'il') {
            continue;
        }
        $full = $file->getPathname();
        $base = $file->getFilename();
        if ($base === '_header.il') {
            $header = $full;
            continue;
        }
        $rest[] = $full;
    }
    \sort($rest, SORT_STRING);
    if ($header === null) {
        return $rest;
    }

    return [$header, ...$rest];
}

function writeDotNetRuntimeConfig(string $outputRoot, string $assemblyName): void
{
    $path = $outputRoot . DIRECTORY_SEPARATOR . $assemblyName . '.runtimeconfig.json';
    $json = <<<'JSON'
{
  "runtimeOptions": {
    "tfm": "net8.0",
    "framework": {
      "name": "Microsoft.NETCore.App",
      "version": "8.0.0"
    }
  }
}
JSON;
    \file_put_contents($path, $json);
}

function writeDotNetDepsJson(string $outputRoot, string $assemblyName): void
{
    $path = $outputRoot . DIRECTORY_SEPARATOR . $assemblyName . '.deps.json';
    // Minimal deps document; framework-dependent host resolves Microsoft.NETCore.App.
    $json = \json_encode([
        'runtimeTarget' => [
            'name' => '.NETCoreApp,Version=v8.0',
            'signature' => '',
        ],
        'compilationOptions' => new \stdClass(),
        'targets' => [
            '.NETCoreApp,Version=v8.0' => [
                $assemblyName . '/1.0.0' => [
                    'runtime' => [
                        $assemblyName . '.dll' => new \stdClass(),
                    ],
                ],
            ],
        ],
        'libraries' => [
            $assemblyName . '/1.0.0' => [
                'type' => 'project',
                'serviceable' => false,
                'sha512' => '',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new \RuntimeException('failed to encode deps.json');
    }
    \file_put_contents($path, $json . "\n");
}

/**
 * Build via a flocked, once-restored workspace so each compile skips NuGet restore.
 */
function buildDotNetIlProject(
    string $dotnet,
    string $outputRoot,
    string $assemblyName,
    string $ilprojBody,
): void {
    $root = dotnetWorkspaceRoot();
    $lockPath = $root . DIRECTORY_SEPARATOR . 'build.lock';
    $lock = \fopen($lockPath, 'c+');
    if ($lock === false) {
        throw new \RuntimeException("cannot open {$lockPath}");
    }
    if (!\flock($lock, LOCK_EX)) {
        \fclose($lock);
        throw new \RuntimeException("cannot lock {$lockPath}");
    }

    try {
        $ws = $root . DIRECTORY_SEPARATOR . 'workspace';
        if (!\is_dir($ws) && !\mkdir($ws, 0777, true) && !\is_dir($ws)) {
            throw new \RuntimeException("cannot create {$ws}");
        }

        // Drop previous IL sources; keep obj/ so restore stays warm.
        clearDotNetWorkspaceSources($ws);
        mirrorIlTree($outputRoot, $ws);

        $wsProj = $ws . DIRECTORY_SEPARATOR . $assemblyName . '.ilproj';
        \file_put_contents($wsProj, $ilprojBody);

        $assets = $ws . DIRECTORY_SEPARATOR . 'obj' . DIRECTORY_SEPARATOR . 'project.assets.json';
        $env = dotnetCliEnv();
        if (!\is_file($assets)) {
            runDotnetCli($dotnet, ['restore', $wsProj, '--nologo', '-v', 'q'], $ws, $env);
        }

        $buildOut = $ws . DIRECTORY_SEPARATOR . 'out';
        runDotnetCli($dotnet, [
            'build', $wsProj,
            '-c', 'Release',
            '-o', $buildOut,
            '--nologo',
            '-v', 'q',
            '--no-restore',
        ], $ws, $env);

        $built = $buildOut . DIRECTORY_SEPARATOR . $assemblyName . '.dll';
        if (!\is_file($built)) {
            throw new \RuntimeException("dotnet build produced no {$assemblyName}.dll");
        }
        $dest = $outputRoot . DIRECTORY_SEPARATOR . $assemblyName . '.dll';
        if (!\rename($built, $dest) && !(\copy($built, $dest) && \unlink($built))) {
            throw new \RuntimeException("failed to copy DLL to {$dest}");
        }
        // Sidecar deps/runtimeconfig when present (Exe).
        foreach ([$assemblyName . '.deps.json', $assemblyName . '.runtimeconfig.json'] as $side) {
            $src = $buildOut . DIRECTORY_SEPARATOR . $side;
            if (\is_file($src)) {
                \copy($src, $outputRoot . DIRECTORY_SEPARATOR . $side);
            }
        }
    } finally {
        \flock($lock, LOCK_UN);
        \fclose($lock);
    }
}

function clearDotNetWorkspaceSources(string $ws): void
{
    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($ws, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iter as $file) {
        $path = $file->getPathname();
        $rel = \substr($path, \strlen($ws) + 1);
        if ($rel === false) {
            continue;
        }
        if (\str_starts_with($rel, 'obj' . DIRECTORY_SEPARATOR) || $rel === 'obj') {
            continue;
        }
        if (\str_starts_with($rel, 'out' . DIRECTORY_SEPARATOR) || $rel === 'out') {
            if ($file->isDir()) {
                \rmdir($path);
            } else {
                \unlink($path);
            }
            continue;
        }
        if ($file->isDir()) {
            \rmdir($path);
        } else {
            \unlink($path);
        }
    }
}

function mirrorIlTree(string $from, string $to): void
{
    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iter as $file) {
        $path = $file->getPathname();
        $rel = \substr($path, \strlen($from) + 1);
        if ($rel === false) {
            continue;
        }
        if (\str_starts_with($rel, 'obj' . DIRECTORY_SEPARATOR) || $rel === 'obj') {
            continue;
        }
        $dest = $to . DIRECTORY_SEPARATOR . $rel;
        if ($file->isDir()) {
            if (!\is_dir($dest) && !\mkdir($dest, 0777, true) && !\is_dir($dest)) {
                throw new \RuntimeException("cannot create {$dest}");
            }
            continue;
        }
        if (\str_ends_with($rel, '.il') || \str_ends_with($rel, '.ilproj')) {
            $dir = \dirname($dest);
            if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
                throw new \RuntimeException("cannot create {$dir}");
            }
            if (!\copy($path, $dest)) {
                throw new \RuntimeException("cannot copy {$path} → {$dest}");
            }
        }
    }
}

/**
 * @param list<string> $args
 * @param array<string, string> $env
 */
function runDotnetCli(string $dotnet, array $args, string $cwd, array $env): void
{
    $cmd = \array_merge([$dotnet], $args);
    $result = runProcess($cmd, $cwd, $env);
    if ($result['exitCode'] !== 0) {
        throw new \RuntimeException(
            \implode(' ', $cmd) . " failed\n" . \trim($result['stdout'] . "\n" . $result['stderr']),
        );
    }
}

/**
 * Native AOT via `dotnet publish -p:PublishAot=true` on the ilproj.
 */
function buildDotNetNativeExecutable(
    string $outputRoot,
    string $assemblyName = 'moggi-app',
    string $binaryName = 'moggi-app',
): bool {
    $dotnet = resolveDotnetExecutable();
    if ($dotnet === null) {
        \fwrite(STDERR, "error: dotnet not found (install SDK or set DOTNET_ROOT)\n");

        return false;
    }
    $proj = $outputRoot . DIRECTORY_SEPARATOR . $assemblyName . '.ilproj';
    if (!\is_file($proj)) {
        \fwrite(STDERR, "error: missing {$proj}\n");

        return false;
    }
    $pubDir = $outputRoot . DIRECTORY_SEPARATOR . 'native-out';
    $cmd = [
        $dotnet, 'publish', $proj,
        '-c', 'Release',
        '-o', $pubDir,
        '-p:PublishAot=true',
        '-p:AssemblyName=' . $binaryName,
        '--nologo',
        '-v', 'q',
    ];
    $result = runProcess($cmd, $outputRoot, dotnetCliEnv());
    $built = $pubDir . DIRECTORY_SEPARATOR . $binaryName;
    if ($result['exitCode'] !== 0 || !\is_file($built)) {
        \fwrite(
            STDERR,
            "error: Native AOT publish failed\n" . \trim($result['stdout'] . "\n" . $result['stderr']) . "\n",
        );

        return false;
    }
    \rename($built, $outputRoot . DIRECTORY_SEPARATOR . $binaryName);

    return true;
}
