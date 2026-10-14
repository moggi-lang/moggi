<?php declare(strict_types=1);

namespace Moggi\Registry\Bind;

use function Moggi\Cache\hashContent;
use function Moggi\Compiler\compilerVersion;
use function Moggi\Paths\moduleNameToPath;
use function Moggi\Registry\prettyJson;

/**
 * The `moggi build --bind` half of the binder: declared `[ffi] bindings`
 * become library roots, cached and marker-checked, so a rebuild without host
 * changes reuses the generated tree without touching a host tool.
 *
 * A binding is keyed the way the plan keys it — *(backend, coordinate,
 * artifact state, generator version)* — and lives where `bindCacheKey` says,
 * under the runtime tree beside the fetched host trees. A `.bind-complete`
 * marker (own name, not `.complete`, which the host-tool cache reads for its
 * own tool+version fields) records the inputs the tree was generated from; a
 * rebuild whose marker still matches skips inspection entirely. The generated
 * `.mog` rides the normal source closure (content-hashed), while the one
 * input no closure sees — a .NET sidecar, which changes emit without changing
 * any declaration — is folded into that backend's emit discriminator instead.
 */

/** Backends `build --bind` can generate for. */
const BINDABLE_BACKENDS = ['php', 'jvm', 'dotnet'];

/**
 * The `[ffi] bindings` entries a build for `$backend` must generate.
 *
 * Each entry is `backend:coordinate`; entries for other backends belong to
 * their own builds and are skipped. An entry with no backend prefix, or a
 * prefix no binder owns, is a typo no build would claim — refused here rather
 * than silently ignored.
 *
 * @param array{ffi?: array{bindings?: list<string>}} $descriptor
 * @return list<string> coordinates (without the `backend:` prefix)
 */
function ffiBindingsForBackend(array $descriptor, string $backend): array
{
    $bindings = (array) ($descriptor['ffi']['bindings'] ?? []);
    $wanted = [];
    foreach ($bindings as $entry) {
        $entry = (string) $entry;
        $colon = \strpos($entry, ':');
        if ($colon === false) {
            throw new \RuntimeException(
                "cannot bind `{$entry}` — an [ffi] bindings entry is `backend:coordinate`",
            );
        }
        $prefix = \substr($entry, 0, $colon);
        $rest = \substr($entry, $colon + 1);
        if ($prefix !== $backend) {
            if (!\in_array($prefix, BINDABLE_BACKENDS, true)) {
                throw new \RuntimeException(
                    "cannot bind `{$entry}` — no binder owns backend `{$prefix}` (this compiler binds: "
                    . \implode(', ', BINDABLE_BACKENDS) . ')',
                );
            }

            continue;
        }
        if ($rest === '') {
            throw new \RuntimeException(
                "cannot bind `{$entry}` — an [ffi] bindings entry is `backend:coordinate`",
            );
        }
        $wanted[] = $rest;
    }

    return $wanted;
}

/**
 * What the host tree looked like when it was bound: relpath, size and mtime
 * of every artifact the binder can read, so a touched jar/dll/source
 * regenerates and an untouched tree never re-runs a host tool.
 */
function bindTreeFingerprint(string $treeDir, string $backend): string
{
    $extensions = match ($backend) {
        'jvm' => ['jar' => true],
        'dotnet' => ['dll' => true],
        default => ['php' => true],
    };
    $rows = [];
    $scan = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($treeDir, \FilesystemIterator::SKIP_DOTS),
    );
    /** @var \SplFileInfo $file */
    foreach ($scan as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if (!isset($extensions[\strtolower($file->getExtension())])) {
            continue;
        }
        $path = $file->getPathname();
        $rows[] = $path . ':' . $file->getSize() . ':' . $file->getMTime();
    }
    \sort($rows);

    return hashContent($treeDir . "\n" . \implode("\n", $rows));
}

/** The marker beside a generated binding tree recording what it was built from. */
function bindCacheMarkerPath(string $root): string
{
    return \rtrim($root, '/') . '/.bind-complete';
}

/** A written path relative to its bind root, for the marker's file list. */
function moduleBindRelativePath(string $root, string $path): string
{
    $prefix = \rtrim($root, '/') . '/';
    if (\str_starts_with($path, $prefix)) {
        return (string) \substr($path, \strlen($prefix));
    }

    return $path;
}

/**
 * Generate (or reuse) one binding for a build: inspect the resolved host
 * tree, write the module (+ sidecar) under the bind cache dir, and record the
 * marker. A marker hit returns the cached root without running a host tool.
 *
 * With a facade module name, the portable skeleton over the binding is
 * written beside it and covered by the same marker.
 *
 * @return array{root: string, regenerated: bool, types: int, functions: int, consts: int, skipped: int, facade: ?string}
 */
function bindForBuild(string $backend, string $coordinate, string $treeDir, ?string $facadeModule = null): array
{
    if (!\in_array($backend, BINDABLE_BACKENDS, true)) {
        throw new \RuntimeException(
            "cannot bind `{$backend}:{$coordinate}` — only `php:`, `jvm:` and `dotnet:` coordinates bind",
        );
    }
    if (!\is_dir($treeDir)) {
        throw new \RuntimeException("cannot bind `{$treeDir}` — no such directory");
    }

    $root = bindCacheKey($backend, $coordinate);
    $wanted = [
        'format' => 1,
        'backend' => $backend,
        'coordinate' => $coordinate,
        'tree' => bindTreeFingerprint($treeDir, $backend),
        'generator' => compilerVersion() ?? 'unknown',
        'facade' => $facadeModule,
    ];

    $markerPath = bindCacheMarkerPath($root);
    $raw = @\file_get_contents($markerPath);
    if (\is_string($raw)) {
        $marker = \json_decode($raw, true);
        if (\is_array($marker)) {
            $same = true;
            foreach ($wanted as $key => $value) {
                if (($marker[$key] ?? null) !== $value) {
                    $same = false;

                    break;
                }
            }

            foreach ((array) ($marker['files'] ?? []) as $written) {
                if (!\is_file($root . '/' . $written)) {
                    $same = false;

                    break;
                }
            }

            if ($same) {
                $facadeFiles = [];
                foreach ((array) ($marker['files'] ?? []) as $written) {
                    if ($facadeModule !== null && $written === moduleNameToPath($facadeModule) . '.mog') {
                        $facadeFiles[] = $root . '/' . $written;
                    }
                }

                return [
                    'root' => $root,
                    'regenerated' => false,
                    'types' => (int) ($marker['types'] ?? 0),
                    'functions' => (int) ($marker['functions'] ?? 0),
                    'consts' => (int) ($marker['consts'] ?? 0),
                    'skipped' => (int) ($marker['skipped'] ?? 0),
                    'facade' => $facadeFiles === [] ? null : $facadeFiles[0],
                ];
            }
        }
    }

    if ($backend === 'jvm') {
        $parts = parseJvmCoordinate($coordinate);
        $descriptor = inspectJvmTree($treeDir, $parts['group'], $parts['artifact']);
        $modulePath = writeBinding($root, $descriptor);
        $files = [moduleBindRelativePath($root, $modulePath)];
    } elseif ($backend === 'dotnet') {
        $parts = parseDotNetCoordinate($coordinate);
        $descriptor = inspectDotNetTree($treeDir, $parts['id'], $parts['version']);
        $modulePath = writeDotNetBinding($root, $descriptor);
        $files = [
            moduleBindRelativePath($root, $modulePath),
            moduleBindRelativePath($root, dotNetBindingSidecarPath($root, (string) $descriptor['module'])),
        ];
    } else {
        $parts = parsePhpCoordinate($coordinate);
        $descriptor = inspectPhpTree($treeDir, $parts['vendor'], $parts['package']);
        $modulePath = writePhpBinding($root, $descriptor);
        $files = [moduleBindRelativePath($root, $modulePath)];
    }

    $facadePath = null;
    if ($facadeModule !== null) {
        $facadePath = writeFacade($root, $descriptor, $facadeModule);
        $files[] = moduleBindRelativePath($root, $facadePath);
    }

    $counts = [
        'types' => \count($descriptor['types']),
        'functions' => \count($descriptor['functions']),
        'consts' => \count($descriptor['consts']),
        'skipped' => \count($descriptor['skipped']),
    ];
    $marker = $wanted + $counts + ['files' => $files];
    if (@\file_put_contents($markerPath, prettyJson($marker) . "\n") === false) {
        throw new \RuntimeException("cannot bind into `{$markerPath}` — the file cannot be written");
    }

    return ['root' => $root, 'regenerated' => true, 'facade' => $facadePath] + $counts;
}

/**
 * Generate (or reuse) every `[ffi]` binding a build for `$backend` declares
 * from its resolved host tree. Returns the generated roots in binding order,
 * to ride as `--lib` roots, plus one summary line per binding.
 *
 * @param array{ffi?: array{bindings?: list<string>}} $descriptor
 * @return array{roots: list<string>, lines: list<string>}
 */
function bindDeclaredForBuild(string $backend, string $treeDir, array $descriptor): array
{
    $roots = [];
    $lines = [];
    foreach (ffiBindingsForBackend($descriptor, $backend) as $coordinate) {
        $bound = bindForBuild($backend, $coordinate, $treeDir);
        $roots[] = $bound['root'];
        $lines[] = ($bound['regenerated'] ? 'generated ' : 'reused ')
            . "{$backend}:{$coordinate} -> {$bound['root']}"
            . " ({$bound['types']} types, {$bound['functions']} functions,"
            . " {$bound['consts']} consts, {$bound['skipped']} skipped)";
    }

    return ['roots' => $roots, 'lines' => $lines];
}
