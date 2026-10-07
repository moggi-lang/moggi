<?php declare(strict_types=1);

namespace Moggi\Registry;

use function Moggi\Cache\runtimeCacheDir;
use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\runProcess;

/**
 * Host-tool dependencies: `[php] composer`, `[jvm] maven`, `[dotnet] nuget`.
 *
 * Moggi does not resolve these itself. It writes the manifest the tool already
 * understands — `composer.json`, `pom.xml`, a `.csproj` — and lets that tool
 * resolve the artifacts, which is the only way the coordinates can mean what they
 * mean everywhere else. What Moggi owns is the part in between: gathering the
 * coordinates from the descriptor and every installed package, deciding when a
 * resolution can be reused, and handing the resulting tree to the compiler.
 *
 * One tree per (backend, coordinate set) under `<cache>/runtime/<backend>/<key>/`,
 * content-addressed by the coordinates rather than by the compiler fingerprint:
 * what Maven resolves does not depend on our compiler, which is exactly why
 * `runtime/` survives a cache reset. A `.complete` marker records the tool and its
 * version, so upgrading Composer re-resolves instead of silently serving artifacts
 * of a resolver that is no longer installed.
 *
 * The tools themselves are shipped with the matching distribution and put on
 * `PATH` by the compiler as it starts (`Moggi\Install`); a missing one is an
 * error naming it, never a fallback.
 */

/** The host tool each backend's coordinates go through. */
const HOST_TOOLS = ['php' => 'composer', 'jvm' => 'mvn', 'dotnet' => 'dotnet'];

/** The descriptor key that names each backend's coordinates. */
const HOST_TOOL_KEYS = ['php' => 'composer', 'jvm' => 'maven', 'dotnet' => 'nuget'];

/** The manifest file each tool reads, and the name it is written under. */
const HOST_TOOL_MANIFESTS = ['php' => 'composer.json', 'jvm' => 'pom.xml', 'dotnet' => 'build.csproj'];

/** The target framework the .NET backend emits for (see `backend/dotnet/package.php`). */
const HOST_TOOL_DOTNET_TFM = 'net8.0';

/** The backend's host tool, or null when its dependencies are not tool-driven. */
function hostToolFor(string $backend): ?string
{
    return HOST_TOOLS[$backend] ?? null;
}

/**
 * The coordinates one descriptor declares for one backend.
 *
 * @param array<string, mixed> $descriptor
 * @return list<string>
 */
function hostToolCoordinates(array $descriptor, string $backend): array
{
    $key = HOST_TOOL_KEYS[$backend] ?? null;
    if ($key === null) {
        return [];
    }

    return \array_values((array) ($descriptor[$backend][$key] ?? []));
}

/**
 * The parts of a *linked* package's descriptor that decide its host-tool
 * dependencies.
 *
 * Deliberately not `readDescriptor`: an installed tree must not have to satisfy
 * every rule a local descriptor does — its `<name>.moggi` was checked when it was
 * packed — and the only questions here are whether the package supports the
 * backend and what coordinates it declares.
 *
 * @return array<string, mixed>
 */
function hostToolDescriptor(string $iniBytes): array
{
    $ini = @\parse_ini_string($iniBytes, true, \INI_SCANNER_RAW);
    if (!\is_array($ini)) {
        return ['backends' => allBackends(), 'php' => [], 'jvm' => [], 'dotnet' => []];
    }

    return [
        'backends' => declaredBackends($iniBytes),
        'php' => ['composer' => splitList((string) ($ini['php']['composer'] ?? ''))],
        'jvm' => ['maven' => backendTableCoordinates($ini, 'jvm.maven')],
        'dotnet' => ['nuget' => backendTableCoordinates($ini, 'dotnet.nuget')],
    ];
}

/**
 * The coordinates of every descriptor that supports this backend, sorted and
 * deduplicated into one set.
 *
 * Deduplication is the point: two packages asking for the same jar is one
 * dependency, and the manifest is written for the *project* rather than once per
 * package.
 *
 * @param list<array<string, mixed>> $descriptors
 * @return list<string>
 */
function collectHostToolCoordinates(array $descriptors, string $backend): array
{
    $coordinates = [];
    foreach ($descriptors as $descriptor) {
        if (!\in_array($backend, (array) ($descriptor['backends'] ?? []), true)) {
            continue;
        }
        foreach (hostToolCoordinates($descriptor, $backend) as $coordinate) {
            $coordinates[(string) $coordinate] = true;
        }
    }

    $set = \array_keys($coordinates);
    \sort($set, \SORT_STRING);

    return $set;
}

/**
 * The signed `third_party` map of a package's own coordinates, per backend.
 *
 * Publishing a package that declares third-party coordinates means asking the same
 * tools a build will ask, now, and recording the bytes they produced. A tool that
 * is not installed is a refusal rather than an empty map: an empty map would sign
 * a promise that nothing was pinned, which is the opposite of what the field is
 * for.
 *
 * @param array<string, mixed> $descriptor a descriptor read by `readDescriptor`
 * @return array<string, \stdClass> backend => (relative path => digest)
 */
function thirdPartyForDescriptor(array $descriptor): array
{
    $map = [];
    foreach (\array_keys(HOST_TOOLS) as $backend) {
        if (hostToolCoordinates($descriptor, $backend) === []) {
            continue;
        }
        $resolved = resolveHostTools($backend, hostToolCoordinates($descriptor, $backend), (string) ($descriptor['name'] ?? 'package'), true);
        if (!$resolved['ok']) {
            throw new \RuntimeException("[{$backend}] {$resolved['detail']}");
        }
        $map[$backend] = (object) thirdPartyMap($backend, (string) $resolved['dir']);
    }

    return $map;
}

/** The content address of a coordinate set: the backend and the coordinates, sorted. */
function hostToolKey(string $backend, array $coordinates): string
{
    return \hash('sha256', $backend . "\n" . \implode("\n", $coordinates) . "\n");
}

/** Where a coordinate set's artifacts live. */
function hostToolCacheDir(string $backend, array $coordinates): string
{
    return runtimeCacheDir() . '/' . $backend . '/' . hostToolKey($backend, $coordinates);
}

/** The first line of a tool's version output, or null when it is not installed. */
function hostToolVersion(string $tool): ?string
{
    $binary = findExecutable($tool);
    if ($binary === null) {
        return null;
    }

    $versionArgs = $tool === 'mvn' ? ['-version'] : ['--version'];
    $result = runProcess([$binary, ...$versionArgs]);
    if ($result['exitCode'] !== 0) {
        return null;
    }
    $line = \trim(\explode("\n", \trim($result['stdout'] . "\n" . $result['stderr']))[0]);

    return $line === '' ? null : $line;
}

/**
 * The manifest a tool reads, as `[file name, contents]`.
 *
 * Deterministic: coordinates sorted, no timestamps, no machine-specific paths —
 * the same set of dependencies always writes the same bytes, so a diff of a
 * generated manifest means the dependencies changed and nothing else.
 *
 * @param list<string> $coordinates
 * @return array{file: string, contents: string}|null
 */
function hostToolManifest(string $backend, array $coordinates, string $project): ?array
{
    $file = HOST_TOOL_MANIFESTS[$backend] ?? null;
    if ($file === null || $coordinates === []) {
        return null;
    }
    $coordinates = \array_values(\array_unique($coordinates));
    \sort($coordinates, \SORT_STRING);

    $contents = match ($backend) {
        'php' => hostToolComposerJson($coordinates, $project),
        'jvm' => hostToolPom($coordinates, $project),
        'dotnet' => hostToolCsproj($coordinates, $project),
        default => null,
    };

    return $contents === null ? null : ['file' => $file, 'contents' => $contents];
}

/** `vendor/package:constraint` → a `composer.json` `require` entry. */
function hostToolSplitCoordinate(string $coordinate, string $tool): array
{
    $parts = \explode(':', $coordinate, 2);
    $name = \trim($parts[0]);
    $constraint = isset($parts[1]) ? \trim($parts[1]) : '';
    if ($name === '') {
        throw new \RuntimeException("[{$tool}] `{$coordinate}` names no package");
    }

    return [$name, $constraint === '' ? '*' : $constraint];
}

/** @param list<string> $coordinates */
function hostToolComposerJson(array $coordinates, string $project): string
{
    $require = [];
    foreach ($coordinates as $coordinate) {
        [$package, $constraint] = hostToolSplitCoordinate($coordinate, 'composer');
        $require[$package] = $constraint;
    }
    \ksort($require, \SORT_STRING);

    $manifest = [
        'name' => 'moggi/build',
        'description' => "Composer dependencies of {$project}, generated by `moggi build`. Do not edit.",
        'type' => 'project',
        'require' => (object) $require,
        'config' => ['allow-plugins' => false],
        'prefer-stable' => true,
        'minimum-stability' => 'stable',
    ];

    return \json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
}

/** `group:artifact:version` → a `pom.xml` `<dependency>`. */
function hostToolPom(array $coordinates, string $project): string
{
    $dependencies = '';
    foreach ($coordinates as $coordinate) {
        $parts = \explode(':', $coordinate);
        if (\count($parts) !== 3 || \trim($parts[0]) === '' || \trim($parts[1]) === '' || \trim($parts[2]) === '') {
            throw new \RuntimeException("[jvm] `{$coordinate}` is not `groupId:artifactId:version`");
        }
        $dependencies .= "    <dependency>\n"
            . '      <groupId>' . \htmlspecialchars(\trim($parts[0]), \ENT_XML1) . "</groupId>\n"
            . '      <artifactId>' . \htmlspecialchars(\trim($parts[1]), \ENT_XML1) . "</artifactId>\n"
            . '      <version>' . \htmlspecialchars(\trim($parts[2]), \ENT_XML1) . "</version>\n"
            . "    </dependency>\n";
    }

    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <!-- Maven dependencies of {$project}, generated by `moggi build`. Do not edit. -->
    <project xmlns="http://maven.apache.org/POM/4.0.0"
             xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
             xsi:schemaLocation="http://maven.apache.org/POM/4.0.0 http://maven.apache.org/xsd/maven-4.0.0.xsd">
      <modelVersion>4.0.0</modelVersion>
      <groupId>org.moggi</groupId>
      <artifactId>build</artifactId>
      <version>1</version>
      <packaging>pom</packaging>
      <dependencies>
    {$dependencies}  </dependencies>
    </project>

    XML;
}

/** `package:version` → a `.csproj` `<PackageReference>`. */
function hostToolCsproj(array $coordinates, string $project): string
{
    $references = '';
    foreach ($coordinates as $coordinate) {
        [$package, $constraint] = hostToolSplitCoordinate($coordinate, 'nuget');
        $references .= '    <PackageReference Include="' . \htmlspecialchars($package, \ENT_XML1)
            . '" Version="' . \htmlspecialchars($constraint, \ENT_XML1) . "\" />\n";
    }
    $tfm = HOST_TOOL_DOTNET_TFM;

    return <<<XML
    <Project Sdk="Microsoft.NET.Sdk">
      <!-- NuGet dependencies of {$project}, generated by `moggi build`. Do not edit. -->
      <PropertyGroup>
        <TargetFramework>{$tfm}</TargetFramework>
        <!-- Restore only: there are no sources here, and the SDK must not look for any. -->
        <EnableDefaultItems>false</EnableDefaultItems>
        <GenerateAssemblyInfo>false</GenerateAssemblyInfo>
      </PropertyGroup>
      <ItemGroup>
    {$references}  </ItemGroup>
    </Project>

    XML;
}

/**
 * The command that resolves a manifest, and where its artifacts end up.
 *
 * @return list<string>
 */
function hostToolCommand(string $backend, string $manifest): array
{
    return match ($backend) {
        'php' => ['composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins'],
        'jvm' => ['mvn', '-B', '-q', 'dependency:copy-dependencies', '-DoutputDirectory=jvm', '-DincludeScope=runtime'],
        'dotnet' => ['dotnet', 'restore', $manifest],
        default => throw new \RuntimeException("no host tool for backend `{$backend}`"),
    };
}

/**
 * Resolve a coordinate set, reusing the tree when it is already there.
 *
 * @param list<string> $coordinates
 * @return array{ok: bool, detail: string, dir: ?string, cached: bool, tool: ?string, version: ?string, manifest: ?string, artifacts: list<string>}
 */
function resolveHostTools(string $backend, array $coordinates, string $project, bool $useCache = true): array
{
    $empty = ['dir' => null, 'cached' => false, 'tool' => null, 'version' => null, 'manifest' => null, 'artifacts' => []];

    if ($coordinates === []) {
        return ['ok' => true, 'detail' => 'nothing declared'] + $empty;
    }

    $tool = hostToolFor($backend);
    if ($tool === null) {
        return ['ok' => false, 'detail' => "backend `{$backend}` has no host tool for third-party dependencies"] + $empty;
    }

    $manifest = hostToolManifest($backend, $coordinates, $project);
    if ($manifest === null) {
        return ['ok' => false, 'detail' => "cannot write a {$tool} manifest for those coordinates"] + $empty;
    }

    $vocabulary = HOST_TOOL_KEYS[$backend];
    $version = hostToolVersion($tool);
    if ($version === null) {
        $binary = findExecutable($tool);

        return [
            'ok' => false,
            'detail' => $binary === null
                ? "{$tool} is needed for [{$backend}] {$vocabulary} dependencies and is not on PATH"
                    . ' — install it, or use a distribution that bundles it'
                : "{$tool} is on PATH ({$binary}) but `{$tool}" . ($tool === 'mvn' ? ' -version' : ' --version')
                    . '` failed — it is probably missing a runtime it needs',
            'tool' => $tool,
        ] + $empty;
    }

    $dir = hostToolCacheDir($backend, $coordinates);
    $marker = $dir . '/.complete';
    $tool = (string) $tool;

    $lock = @\fopen($dir . '.lock', 'c');
    if ($lock !== false) {
        @\flock($lock, \LOCK_EX);
    }
    try {
        return resolveHostToolsLocked($backend, $coordinates, $useCache, $dir, $marker, $manifest, $tool, $version, $empty);
    } finally {
        if ($lock !== false) {
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
    }
}

/**
 * The half of `resolveHostTools` that runs while the coordinate set is locked:
 * reuse the marked tree, or replace it with a fresh resolution.
 *
 * @param array{file: string, contents: string} $manifest
 * @param array<string, mixed> $empty
 * @return array<string, mixed>
 */
function resolveHostToolsLocked(string $backend, array $coordinates, bool $useCache, string $dir, string $marker, array $manifest, string $tool, string $version, array $empty): array
{
    if ($useCache && \is_file($marker)) {
        $recorded = \json_decode((string) \file_get_contents($marker), true);
        if (\is_array($recorded)
            && ($recorded['tool'] ?? null) === $tool
            && ($recorded['version'] ?? null) === $version
            && ($recorded['coordinates'] ?? null) === $coordinates) {
            materializeHostToolArtifacts($backend, $dir);

            return [
                'ok' => true,
                'detail' => "reused {$tool} {$version}",
                'dir' => $dir,
                'cached' => true,
                'tool' => $tool,
                'version' => $version,
                'manifest' => $dir . '/' . $manifest['file'],
                'artifacts' => hostToolArtifacts($backend, $dir),
            ];
        }
    }

    removeTree($dir);
    if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
        return ['ok' => false, 'detail' => "cannot create {$dir}", 'tool' => $tool, 'version' => $version] + $empty;
    }
    if (\file_put_contents($dir . '/' . $manifest['file'], $manifest['contents']) === false) {
        return ['ok' => false, 'detail' => "cannot write {$dir}/{$manifest['file']}", 'tool' => $tool, 'version' => $version] + $empty;
    }

    $binary = findExecutable($tool) ?? $tool;
    $result = runProcess([$binary, ...\array_slice(hostToolCommand($backend, $manifest['file']), 1)], $dir, null, false, 1800);
    if ($result['exitCode'] !== 0) {
        $output = \trim($result['stdout'] . "\n" . $result['stderr']);

        return [
            'ok' => false,
            'detail' => "{$tool} failed (" . $result['exitCode'] . ") for " . \implode(', ', $coordinates)
                . "\n" . \substr($output, -2000),
            'dir' => $dir,
            'tool' => $tool,
            'version' => $version,
            'manifest' => $dir . '/' . $manifest['file'],
        ] + $empty;
    }

    materializeHostToolArtifacts($backend, $dir);
    $artifacts = hostToolArtifacts($backend, $dir);
    \file_put_contents(
        $marker,
        \json_encode([
            'backend' => $backend,
            'tool' => $tool,
            'version' => $version,
            'coordinates' => $coordinates,
            'manifest' => $manifest['file'],
            'artifacts' => \count($artifacts),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n",
    );

    return [
        'ok' => true,
        'detail' => "resolved {$tool} {$version}",
        'dir' => $dir,
        'cached' => false,
        'tool' => $tool,
        'version' => $version,
        'manifest' => $dir . '/' . $manifest['file'],
        'artifacts' => $artifacts,
    ];
}

/**
 * What a resolved tree holds, as a short list for the report.
 *
 * @return list<string>
 */
function hostToolArtifacts(string $backend, string $dir): array
{
    return hostToolArtifactPaths($backend, $dir) === []
        ? []
        : match ($backend) {
            'php' => ['vendor/ (' . countTreeFiles($dir . '/vendor') . ' files)'],
            'jvm' => \array_map(static fn (string $jar): string => \basename($jar), hostToolArtifactPaths($backend, $dir)),
            'dotnet' => \array_map(static fn (string $dll): string => \basename($dll), hostToolArtifactPaths($backend, $dir)),
            default => [],
        };
}

/**
 * The absolute paths a resolved tree contributes to a compile.
 *
 * This is the list the compiler is handed: jars to put on the classpath, DLLs to
 * reference, `vendor/autoload.php` to require. `hostToolArtifacts` is the same
 * thing rendered short for the report.
 *
 * @return list<string>
 */
function hostToolArtifactPaths(string $backend, string $dir): array
{
    return match ($backend) {
        'php' => \is_file($dir . '/vendor/autoload.php') ? [$dir . '/vendor/autoload.php'] : [],
        'jvm' => \glob($dir . '/jvm/*.jar') ?: [],
        'dotnet' => \glob($dir . '/dotnet/*.dll') ?: [],
        default => [],
    };
}

/**
 * The resolved tree's artifact files whose bytes are *reproducible*, as
 * `<tree-relative path> => <absolute path>`.
 *
 * This is the set the signed `third_party` map covers, and it is deliberately
 * narrower than `hostToolArtifactPaths()`: what the compiler is handed includes
 * files the resolver *generates* (Composer's `vendor/autoload.php` and the
 * `vendor/composer/` autoloader, which embed absolute install paths), and a hash
 * of a generated file is a hash of the machine that generated it rather than of
 * the dependency. So the map pins the dependency bytes — the packages Composer
 * installed, the jars Maven copied, the assemblies NuGet produced — and not the
 * autoloader that enumerates them.
 *
 * A path two releases disagree about is not silently resolved: the caller is told,
 * because two packages pinning different bytes for the same artifact is exactly
 * the condition this map exists to surface.
 *
 * @return array<string, string>
 */
function hostToolArtifactFiles(string $backend, string $dir): array
{
    $root = \rtrim($dir, '/');
    $files = [];

    if ($backend === 'php') {
        $vendor = $root . '/vendor';
        if (!\is_dir($vendor)) {
            return [];
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendor, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($items as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $path = \str_replace('\\', '/', $item->getPathname());
            $relative = \ltrim(\substr($path, \strlen($root)), '/');
            if (\str_starts_with($relative, 'vendor/composer/') || $relative === 'vendor/autoload.php') {
                continue;
            }
            $files[$relative] = $item->getPathname();
        }
    } elseif ($backend === 'jvm') {
        foreach (\glob($root . '/jvm/*.jar') ?: [] as $jar) {
            $files['jvm/' . \basename($jar)] = $jar;
        }
    } elseif ($backend === 'dotnet') {
        foreach (\glob($root . '/dotnet/*.dll') ?: [] as $dll) {
            $files['dotnet/' . \basename($dll)] = $dll;
        }
    }

    \ksort($files, \SORT_STRING);

    return $files;
}

/**
 * The signed expectations of one resolved tree: `<relative path> => sha256 digest>`.
 *
 * Used by `publish` to record what a package's own coordinates resolved to. A file
 * that cannot be read is an error, not an omission: a map that quietly drops a
 * dependency would let a build skip a check the publisher meant to be binding.
 *
 * @return array<string, string>
 */
function thirdPartyMap(string $backend, string $dir): array
{
    $map = [];
    foreach (hostToolArtifactFiles($backend, $dir) as $relative => $path) {
        $digest = @\hash_file('sha256', $path);
        if ($digest === false) {
            throw new \RuntimeException("cannot read resolved artifact {$relative} in {$dir}");
        }
        $map[$relative] = 'sha256:' . $digest;
    }

    return $map;
}

/**
 * The resolved artifacts against the bytes the signed `third_party` maps pinned.
 *
 * Every artifact the resolver produced that a release pins must hash to what that
 * release signed: a substituted jar, package or assembly fails here. An artifact
 * a release pins whose path the merged resolution did not produce is *not* a
 * failure — a project-wide resolution may legitimately pick a different transitive
 * version than a single package's publish-time resolution did.
 *
 * `$strict` additionally refuses an artifact no release declares at all, which is
 * what catches the resolver injecting a classpath entry. It is off when the local
 * descriptor contributes its own coordinates for the backend: those artifacts are
 * the project's own and are not signed by anyone, so they cannot be attributed and
 * must not be read as injected.
 *
 * @param array<string, string> $expected relative path => signed digest
 * @return list<string>
 */
function thirdPartyProblems(string $backend, string $dir, array $expected, bool $strict = false): array
{
    if ($expected === []) {
        return [];
    }

    $problems = [];
    foreach (hostToolArtifactFiles($backend, $dir) as $relative => $path) {
        if (!isset($expected[$relative])) {
            if ($strict) {
                $problems[] = "the resolution produced {$relative}, which no signed release declares";
            }
            continue;
        }
        $digest = @\hash_file('sha256', $path);
        if ($digest === false) {
            $problems[] = "cannot read resolved artifact {$relative}";
            continue;
        }
        $actual = 'sha256:' . $digest;
        if ($actual !== $expected[$relative]) {
            $problems[] = "{$relative} does not match the signed third_party hash ({$actual} != {$expected[$relative]})";
        }
    }

    return $problems;
}

/**
 * Fold several releases' `third_party` maps into one expectation per backend and
 * artifact path, refusing a path two releases pin to different bytes.
 *
 * @param iterable<array<string, mixed>> $maps each release's `third_party`
 * @return array{map: array<string, array<string, string>>, problems: list<string>}
 */
function mergeThirdParty(iterable $maps): array
{
    $merged = [];
    $problems = [];
    foreach ($maps as $map) {
        foreach ((array) $map as $backend => $hashes) {
            foreach ((array) $hashes as $relative => $digest) {
                $backend = (string) $backend;
                $relative = (string) $relative;
                $digest = (string) $digest;
                if (isset($merged[$backend][$relative]) && $merged[$backend][$relative] !== $digest) {
                    $problems[] = "two releases pin different bytes for [{$backend}] {$relative}";
                    continue;
                }
                $merged[$backend][$relative] = $digest;
            }
        }
    }

    return ['map' => $merged, 'problems' => $problems];
}

/**
 * Put a resolved tree's artifacts where the backend looks for a library's own
 * dependencies.
 *
 * The JVM tool already writes into `jvm/` itself, and Composer into `vendor/`,
 * but `dotnet restore` leaves the assemblies in the machine-wide NuGet cache and
 * only records where. Copying them into the tree is what makes the resolution
 * self-contained: the tree no longer depends on a cache path, which is also the
 * only way a cached resolution stays valid after `dotnet nuget locals` runs.
 */
function materializeHostToolArtifacts(string $backend, string $dir): void
{
    if ($backend !== 'dotnet') {
        return;
    }
    $references = dotnetReferences($dir);
    if ($references === []) {
        return;
    }
    $into = $dir . '/dotnet';
    if (!\is_dir($into) && !\mkdir($into, 0777, true) && !\is_dir($into)) {
        throw new \RuntimeException("cannot create {$into}");
    }
    foreach ($references as $reference) {
        $target = $into . '/' . \basename($reference);
        if (!\is_file($target) && \copy($reference, $target) === false) {
            throw new \RuntimeException("cannot copy {$reference} into {$into}");
        }
    }
}

/**
 * The assemblies a restored .NET project references, from NuGet's own assets file.
 *
 * `dotnet restore` does not produce a directory of DLLs — it records what it
 * resolved in `obj/project.assets.json`, whose `compile` entries are paths
 * relative to the package folders it also names. Reading that is what turns a
 * restore into something the IL emitter can be pointed at.
 *
 * The on-disk directory is *not* the `targets` key: that is `<id>/<version>` as
 * written, while the restored folder is the lowercased `path` under `libraries`.
 * On a case-sensitive filesystem joining the key directly finds nothing, so the
 * library table is what has to be consulted.
 *
 * @return list<string>
 */
function dotnetReferences(string $dir): array
{
    $assets = $dir . '/obj/project.assets.json';
    if (!\is_file($assets)) {
        return [];
    }
    $data = \json_decode((string) \file_get_contents($assets), true);
    if (!\is_array($data)) {
        return [];
    }

    $folders = [];
    foreach ((array) ($data['packageFolders'] ?? []) as $folder => $_) {
        $folders[] = \rtrim(\str_replace('\\', '/', (string) $folder), '/');
    }
    if ($folders === []) {
        return [];
    }

    $libraries = [];
    foreach ((array) ($data['libraries'] ?? []) as $key => $library) {
        $path = \ltrim(\str_replace('\\', '/', (string) ($library['path'] ?? $key)), '/');
        if ($path !== '') {
            $libraries[(string) $key] = $path;
        }
    }

    $references = [];
    foreach ((array) ($data['targets'] ?? []) as $packages) {
        foreach ((array) $packages as $packageKey => $package) {
            $packageDir = $libraries[(string) $packageKey]
                ?? \strtolower(\ltrim(\str_replace('\\', '/', (string) $packageKey), '/'));
            foreach ((array) ($package['compile'] ?? []) as $relative => $_) {
                $relative = (string) $relative;
                if (!\str_ends_with($relative, '.dll')) {
                    continue;
                }
                $relative = \ltrim(\str_replace('\\', '/', $relative), '/');
                foreach ($folders as $folder) {
                    $candidate = $folder . '/' . $packageDir . '/' . $relative;
                    if (\is_file($candidate)) {
                        $references[$candidate] = true;
                    }
                }
            }
        }
    }

    $paths = \array_keys($references);
    \sort($paths, \SORT_STRING);

    return $paths;
}

function countTreeFiles(string $dir): int
{
    $count = 0;
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($items as $item) {
        if ($item->isFile()) {
            ++$count;
        }
    }

    return $count;
}

function removeTree(string $path): void
{
    if (!\is_dir($path)) {
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
