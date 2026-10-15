<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Dependencies;

use function Moggi\Cache\fileFingerprint;
use function Moggi\Cache\hashContent;
use function Moggi\Cache\runtimeCacheDir;
use function Moggi\Modules\declaringLibraryRoot;
use function Moggi\Modules\libraryScanRoots;
use function Moggi\Paths\globPaths;
use function Moggi\Registry\safeDigestKey;

/**
 * Library-owned .NET host metadata.
 *
 * A library may drop `dotnet/*.json` files under its module directory (next to
 * the module's `.mog` sources), the same way it vendors JVM jars under a
 * `jvm/` directory — except the standard library itself, which is a package
 * directory (`libraries/base/`, with `src/` for sources): its sidecars live
 * mirrored in `meta/` (`meta/Data/Char/dotnet/dependencies.json` describes
 * `src/Data/Char/DotNet.mog`) and are attributed back to the tree they mirror.
 * The files declare the CLR details a backend cannot infer
 * from a Moggi `foreign` declaration:
 *
 *   {
 *     "assemblies": {
 *       "Some.Assembly": {
 *         "types": {
 *           "Some.Assembly.Namespace.RefType": {},
 *           "Some.Assembly.Namespace.ValueType": { "valueType": true }
 *         },
 *         "signatures": {
 *           "someForeignBinding": [
 *             "int64",
 *             "class [System.Runtime]System.IFormatProvider"
 *           ]
 *         }
 *       }
 *     }
 *   }
 *
 * - `types` associates a CLR type with the assembly that defines it, and
 *   optionally says it is a value type. This scopes TypeRefs and picks
 *   `class`/`valuetype` without the compiler knowing any particular assembly.
 * - `signatures` gives the exact IL parameter list for one foreign import,
 *   keyed by the import's Moggi binding name. It is used when the real CLR
 *   signature cannot be derived from the Moggi type — C# optional parameters
 *   being the common case, where every parameter must still be passed in IL.
 *
 * Discovery is generic: every `dotnet/` directory under the stdlib root is
 * scanned. There is no per-library special case.
 */

/**
 * Absolute paths of every `dotnet` directory under a library root.
 *
 * Both kinds of content count: the `.json` metadata a library declares, and the
 * `.dll` assemblies it vendors. A directory with only assemblies is the shape a
 * NuGet resolution materializes into.
 *
 * @return list<string>
 */
/**
 * The tree a sidecar belongs to.
 *
 * Usually the library root containing the file — except a packaged library's
 * mirror: a sidecar under `<package>/meta/` describes the module it mirrors
 * under `<package>/src/`, so it is attributed to the sibling source root and
 * `signatures` entries keep applying to exactly the imports they applied to
 * when metadata lived beside sources. A mirror without its sources beside it
 * falls back to the plain rule rather than claiming a tree that is not there.
 */
function dotNetSidecarTreeRoot(string $file): ?string
{
    $path = \str_replace('\\', '/', $file);
    if (\preg_match('#^(.*)/meta(/|$)#', $path, $match) === 1 && \is_dir($match[1] . '/src')) {
        return $match[1] . '/src';
    }

    return declaringLibraryRoot($file);
}

/**
 * Metadata mirrors beside the roots being scanned.
 *
 * A packaged library keeps sources under `src/` and anything else under the
 * sibling `meta/` (`libraries/base/`, `libraries/json/`), so the mirror is
 * scanned too and `src/` stays pure `.mog`. Flat libraries (metadata beside
 * sources) keep working: their roots are scanned as before, mirror or not.
 *
 * @param list<string> $roots
 * @return list<string>
 */
function dotNetMetaMirrors(array $roots): array
{
    $mirrors = [];
    foreach ($roots as $root) {
        $slash = \rtrim(\str_replace('\\', '/', (string) $root), '/');
        if (\basename($slash) === 'src') {
            $meta = \dirname($slash) . '/meta';
            if (\is_dir($meta)) {
                $mirrors[] = $meta;
            }
        }
    }

    return $mirrors;
}

function discoverDotNetLibDirs(): array
{
    $roots = libraryScanRoots();
    $dirs = [];
    foreach ([...$roots, ...dotNetMetaMirrors($roots)] as $lib) {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $extension = $file->getExtension();
            if ($extension !== 'json' && $extension !== 'dll') {
                continue;
            }
            $dir = $file->getPath();
            if (\basename($dir) !== 'dotnet') {
                continue;
            }
            $dirs[$dir] = true;
        }
    }

    $dirs = \array_keys($dirs);
    \sort($dirs);

    return $dirs;
}

/**
 * Absolute paths of every sidecar a library root declares under `dotnet/`.
 *
 * Sorted, so the emit discriminator folds them deterministically. A sidecar
 * changes emit without changing any declaration — the optional tail it pads,
 * the assembly scope it names — so no emit cache key may ignore these bytes.
 *
 * @return list<string>
 */
function dotNetSidecarFiles(): array
{
    $files = [];
    foreach (discoverDotNetLibDirs() as $dir) {
        foreach (globPaths($dir . '/*.json') as $file) {
            $files[] = $file;
        }
    }
    \sort($files, \SORT_STRING);

    return $files;
}

/**
 * What sidecars contribute to an emit cache key: their content hash, or `''`
 * when the compile sees none (keeping the old key for sidecar-free builds).
 *
 * `$includeGenerated = false` skips sidecars under a bind cache root
 * (the `bindCacheKey` layout, `runtime/<backend>/bind-<slug>/`): those bytes are
 * derived from the tree and the generator, which the bind marker already
 * versions, so keying the *bind root* on them would move the root every
 * generation and the marker would never hit. The backend discriminator always
 * counts them — a regenerated sidecar beside an unchanged declaration must
 * still re-emit — while the bind root must stay put to be reusable.
 */
function dotNetSidecarDiscriminator(bool $includeGenerated = true): string
{
    $fingerprints = [];
    foreach (dotNetSidecarFiles() as $file) {
        if (! $includeGenerated && isGeneratedBindPath($file)) {
            continue;
        }
        $fingerprints[] = $file . ':' . fileFingerprint($file);
    }
    if ($fingerprints === []) {
        return '';
    }

    return hashContent(\implode("\n", $fingerprints));
}

/**
 * Whether a path lives under a generated binding tree: the `bindCacheKey`
 * layout (`runtime/<backend>/bind-<slug>/`), matched textually so this layer
 * takes no dependency on the registry's bind code.
 */
function isGeneratedBindPath(string $path): bool
{
    $flat = static fn (string $p): string => \str_replace('\\', '/', $p);
    $prefix = \rtrim($flat(runtimeCacheDir()), '/') . '/';
    $slashPath = $flat($path);
    if (! \str_starts_with($slashPath, $prefix)) {
        return false;
    }
    $rest = (string) \substr($slashPath, \strlen($prefix));
    $slash = \strpos($rest, '/');
    if ($slash === false) {
        return false;
    }

    return \str_starts_with((string) \substr($rest, $slash + 1), 'bind-');
}

/**
 * Absolute paths of every assembly a library root vendors under `dotnet/`.
 *
 * Third-party packages resolved by NuGet are materialized here (see
 * `Moggi\Registry\materializeHostToolArtifacts`), and a library may vendor a
 * dependent assembly by hand the same way. The packager copies them beside the
 * app so the runtime can load them, which is what a framework-dependent app
 * needs instead of a machine-wide package cache.
 *
 * @return list<string>
 */
function dotNetVendoredAssemblies(): array
{
    $assemblies = [];
    foreach (discoverDotNetLibDirs() as $dir) {
        foreach (globPaths($dir . '/*.dll') as $dll) {
            $assemblies[\basename($dll)] = $dll;
        }
    }

    \ksort($assemblies, \SORT_STRING);

    return \array_values($assemblies);
}

/**
 * The sidecar format this compiler writes and reads (see `docs/development/packaging.md`).
 *
 * Frozen at 1 for step 7: the schema below is what the binding generator will write, so the
 * loader treats the file as closed — an unknown key is an error — and the host-tool cache key
 * carries this version, so a cached resolution never outlives the format that produced it.
 */
const DOTNET_SIDECAR_FORMAT = 1;

/**
 * Merged metadata from every discovered library directory.
 *
 * A sidecar *describes* a declaration it shares a tree with; it never overrides one silently. What
 * that means per map:
 *
 * - `types` associates a CLR type with its defining assembly and value-typeness. Conflicting claims
 *   are hard errors; the first claim wins only when every claimant agrees.
 * - `signatures` gives the exact IL parameter list for one foreign import, keyed by the import's
 *   Moggi binding name. The entry must *extend* the declaration (see {@see dotNetSidecarMismatch})
 *   and applies only to imports from the same tree — a project file, under no library root, owns
 *   every root it assembled, so the project's own imports may use any sidecar.
 *
 * Provenance (`version`, `digest`, `generated-by`) is recorded per assembly. A `digest` is checked
 * against the resolved `<assembly>.dll` when that file is on disk, and a mismatch refuses the
 * compile — a stale sidecar must not describe a new assembly. Assemblies the resolution did not
 * materialize (framework assemblies) carry no checkable file, so their digests are recorded but not
 * verified; the `version` is recorded for the generator round-trip and likewise unchecked until a
 * metadata reader exists.
 *
 * @return array{
 *     types: array<string, array{assembly: string, valueType: bool, roots: list<string>}>
 *     signatures: array<string, array{
 *       params: list<string>, declared: list<string>, optionalTail: int,
 *       roots: list<string>, flat: bool
 *     }>
 * } `params` are the emit types, `declared` the types the declaration must infer for the shared
 * prefix (they differ only where the entry blesses an interface with `from`)
 */
function dotNetLibMetadata(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $types = [];
    $signatures = [];

    foreach (discoverDotNetLibDirs() as $dir) {
        $files = globPaths($dir . '/*.json');
        \sort($files);
        foreach ($files as $file) {
            $raw = \file_get_contents($file);
            if ($raw === false) {
                throw new \RuntimeException("cannot read .NET library metadata `{$file}`");
            }
            $data = \json_decode($raw, true);
            if (! \is_array($data)) {
                throw new \RuntimeException("invalid .NET library metadata `{$file}`");
            }
            $format = $data['format'] ?? 0;
            if (! \is_int($format) || $format > DOTNET_SIDECAR_FORMAT) {
                throw new \RuntimeException(
                    ".NET library metadata `{$file}` uses format {$format}, newer than this compiler's "
                    . DOTNET_SIDECAR_FORMAT . ' — update moggi',
                );
            }

            assertClosedDotNetSidecarKeys($data, ['format', 'assemblies'], 'top level', $file);

            $root = dotNetSidecarTreeRoot($file);
            $assemblies = $data['assemblies'] ?? [];
            if (! \is_array($assemblies)) {
                throw new \RuntimeException(
                    ".NET library metadata `{$file}` holds a non-object `assemblies` map",
                );
            }
            foreach ($assemblies as $assembly => $spec) {
                if (! \is_string($assembly) || ! \is_array($spec) || ($spec !== [] && \array_is_list($spec))) {
                    throw new \RuntimeException(
                        ".NET library metadata `{$file}` holds a malformed assembly entry",
                    );
                }
                assertClosedDotNetSidecarKeys(
                    $spec,
                    ['types', 'signatures', 'version', 'digest', 'generated-by'],
                    "assembly `{$assembly}`",
                    $file,
                );
                foreach (['version', 'digest', 'generated-by'] as $provenance) {
                    if (isset($spec[$provenance]) && ! \is_string($spec[$provenance])) {
                        throw new \RuntimeException(
                            ".NET library metadata `{$file}` holds a non-string `{$provenance}`"
                            . " for assembly `{$assembly}`",
                        );
                    }
                }
                checkDotNetAssemblyDigest($assembly, $spec, $file);
                $typesMap = $spec['types'] ?? [];
                if (! \is_array($typesMap)) {
                    throw new \RuntimeException(
                        ".NET library metadata `{$file}` holds a non-object `types` map"
                        . " for assembly `{$assembly}`",
                    );
                }
                foreach ($typesMap as $clrType => $typeSpec) {
                    if (! \is_string($clrType) || ! \is_array($typeSpec)
                        || ($typeSpec !== [] && \array_is_list($typeSpec))) {
                        throw new \RuntimeException(
                            ".NET library metadata `{$file}` holds a malformed `types` entry"
                            . " for assembly `{$assembly}`",
                        );
                    }
                    assertClosedDotNetSidecarKeys($typeSpec, ['valueType'], "type `{$clrType}`", $file);
                    if (isset($typeSpec['valueType']) && ! \is_bool($typeSpec['valueType'])) {
                        throw new \RuntimeException(
                            ".NET library metadata `{$file}` holds a non-boolean `valueType`"
                            . " for type `{$clrType}`",
                        );
                    }
                    $valueType = (bool) ($typeSpec['valueType'] ?? false);
                    if (isset($types[$clrType])) {
                        $prior = $types[$clrType];
                        if ($prior['assembly'] !== $assembly) {
                            throw new \RuntimeException(
                                ".NET type `{$clrType}` declared in assemblies `{$prior['assembly']}`"
                                . " and `{$assembly}` ({$file})",
                            );
                        }
                        if ($prior['valueType'] !== $valueType) {
                            throw new \RuntimeException(
                                ".NET type `{$clrType}` declared with conflicting valueType ({$file})",
                            );
                        }
                        if ($root !== null && ! \in_array($root, $prior['roots'], true)) {
                            $prior['roots'][] = $root;
                            $types[$clrType] = $prior;
                        }

                        continue;
                    }
                    $types[$clrType] = [
                        'assembly' => $assembly,
                        'valueType' => $valueType,
                        'roots' => $root === null ? [] : [$root],
                    ];
                }
                $signaturesMap = $spec['signatures'] ?? [];
                if (! \is_array($signaturesMap)) {
                    throw new \RuntimeException(
                        ".NET library metadata `{$file}` holds a non-object `signatures` map"
                        . " for assembly `{$assembly}`",
                    );
                }
                foreach ($signaturesMap as $name => $entry) {
                    if (! \is_string($name)) {
                        throw new \RuntimeException(
                            ".NET library metadata `{$file}` holds a malformed `signatures` entry"
                            . " for assembly `{$assembly}`",
                        );
                    }
                    $parsed = parseDotNetSignatureEntry($name, $entry, $file);
                    if ($parsed === null) {
                        continue;
                    }
                    if (isset($signatures[$name])) {
                        $prior = $signatures[$name];
                        if ($prior['params'] !== $parsed['params']
                            || $prior['declared'] !== $parsed['declared']
                            || $prior['optionalTail'] !== $parsed['optionalTail']) {
                            throw new \RuntimeException(
                                ".NET foreign signature `{$name}` declared twice with different parameters"
                                . " ({$file})",
                            );
                        }
                        if ($root !== null && ! \in_array($root, $prior['roots'], true)) {
                            $prior['roots'][] = $root;
                            $signatures[$name] = $prior;
                        }

                        continue;
                    }
                    $parsed['roots'] = $root === null ? [] : [$root];
                    $signatures[$name] = $parsed;
                }
            }
        }
    }

    return $cache = ['types' => $types, 'signatures' => $signatures];
}

/**
 * A `signatures` entry in normal form, or null when it is not an entry at all.
 *
 * Two spellings: the flat list (deprecated — warns once per file, and refuses extras, since a flat
 * list cannot say which tail is optional) and the object form
 * `{"params": [...], "optionalTail": N}`. A param is an IL type string, or
 * `{"emit": "<il>", "from": "<declared-il>"}` where the call passes a value of a subtype the
 * declaration names (an interface the host type implements) and the emitter converts it.
 *
 * @return ?array{params: list<string>, declared: list<string>, optionalTail: int, flat: bool}
 */
function parseDotNetSignatureEntry(string $name, mixed $entry, string $file): ?array
{
    if (\is_array($entry) && \array_is_list($entry)) {
        warnOnceFlatDotNetSidecar($file);
        $params = [];
        foreach ($entry as $param) {
            if (! \is_string($param)) {
                throw new \RuntimeException(
                    ".NET foreign signature `{$name}` in `{$file}` holds a non-string parameter",
                );
            }
            $params[] = $param;
        }

        return ['params' => $params, 'declared' => $params, 'optionalTail' => 0, 'flat' => true];
    }
    if (! \is_array($entry) || ! isset($entry['params']) || ! \is_array($entry['params'])) {
        throw new \RuntimeException(
            ".NET foreign signature `{$name}` in `{$file}` is neither a parameter list"
            . ' nor {"params": [...], "optionalTail": N}',
        );
    }
    assertClosedDotNetSidecarKeys($entry, ['params', 'optionalTail'], "signature `{$name}`", $file);
    if (! \array_is_list($entry['params'])) {
        throw new \RuntimeException(
            ".NET foreign signature `{$name}` in `{$file}` holds a non-list `params`",
        );
    }
    $params = [];
    $declared = [];
    foreach ($entry['params'] as $param) {
        if (\is_string($param)) {
            $params[] = $param;
            $declared[] = $param;

            continue;
        }
        if (! \is_array($param) || ! isset($param['emit']) || ! \is_string($param['emit'])) {
            throw new \RuntimeException(
                ".NET foreign signature `{$name}` in `{$file}` holds a parameter that is neither"
                . ' an IL type nor {"emit": ..., "from": ...}',
            );
        }
        assertClosedDotNetSidecarKeys($param, ['emit', 'from'], "signature `{$name}` parameter", $file);
        $params[] = $param['emit'];
        $from = $param['from'] ?? $param['emit'];
        if (! \is_string($from)) {
            throw new \RuntimeException(
                ".NET foreign signature `{$name}` in `{$file}` holds a non-string `from` type",
            );
        }
        $declared[] = $from;
    }
    $optionalTail = $entry['optionalTail'] ?? 0;
    if (! \is_int($optionalTail) || $optionalTail < 0 || $optionalTail > \count($params)) {
        throw new \RuntimeException(
            ".NET foreign signature `{$name}` in `{$file}` names an `optionalTail` outside its parameters",
        );
    }

    return ['params' => $params, 'declared' => $declared, 'optionalTail' => $optionalTail, 'flat' => false];
}

/**
 * The frozen schema's closedness: a key the schema does not declare is an error naming it.
 *
 * @param array<string, mixed> $map
 * @param list<string> $allowed
 */
function assertClosedDotNetSidecarKeys(array $map, array $allowed, string $where, string $file): void
{
    foreach ($map as $key => $_) {
        if (! \is_string($key) || ! \in_array($key, $allowed, true)) {
            $shown = \is_string($key) ? $key : '#' . (string) $key;

            throw new \RuntimeException(
                ".NET library metadata `{$file}` holds an unknown key `{$shown}` in {$where}"
                . ' (frozen schema, format ' . DOTNET_SIDECAR_FORMAT . ')',
            );
        }
    }
}

function warnOnceFlatDotNetSidecar(string $file): void
{
    static $warned = [];
    if (isset($warned[$file])) {
        return;
    }
    $warned[$file] = true;
    \fwrite(
        STDERR,
        "warning: `{$file}` uses the flat `signatures` form; spell entries as"
        . ' {"params": [...], "optionalTail": N} (format ' . DOTNET_SIDECAR_FORMAT . ")\n",
    );
}

/**
 * Refuse a compile whose sidecar no longer describes the resolved assembly.
 *
 * The digest is checked against the `<assembly>.dll` the resolution materialized, when it is on
 * disk. Assemblies the resolution did not materialize — framework assemblies, which never enter the
 * tree — carry no checkable file, so a digest without one is recorded but not verified.
 */
function checkDotNetAssemblyDigest(string $assembly, array $spec, string $file): void
{
    $digest = $spec['digest'] ?? null;
    if (! \is_string($digest) || safeDigestKey($digest) === null) {
        return;
    }
    foreach (dotNetVendoredAssemblies() as $dll) {
        if (\strcasecmp(\basename($dll, '.dll'), $assembly) !== 0) {
            continue;
        }
        $actual = 'sha256:' . \hash_file('sha256', $dll);
        if ($actual !== $digest) {
            throw new \RuntimeException(
                ".NET library metadata `{$file}` was generated from {$digest},"
                . " but the resolved `{$dll}` is {$actual} — regenerate the sidecar",
            );
        }

        return;
    }
}

/** Defining assembly declared by a library for a CLR type name, if any. */
function dotNetTypeAssembly(string $clrType): ?string
{
    $types = dotNetLibMetadata()['types'];
    if (isset($types[$clrType])) {
        return $types[$clrType]['assembly'];
    }
    foreach ($types as $name => $spec) {
        if (\str_starts_with($clrType, $name . '/')) {
            return $spec['assembly'];
        }
    }

    return null;
}

/** Whether a library declares a CLR type name as a value type, if any. */
function dotNetTypeIsValueType(string $clrType): ?bool
{
    $types = dotNetLibMetadata()['types'];
    if (isset($types[$clrType])) {
        return $types[$clrType]['valueType'];
    }
    foreach ($types as $name => $spec) {
        if (\str_starts_with($clrType, $name . '/')) {
            return $spec['valueType'];
        }
    }

    return null;
}

/**
 * A sidecar signature entry for one foreign import, or null when none applies.
 *
 * The entry applies only to imports from the same tree it was read from; a project file, under no
 * library root, assembles every root, so its imports may use any sidecar. This is a pure lookup:
 * whether the import is *verified* is the inspector's verdict, not the sidecar's presence. Whether
 * the entry *agrees* with the declaration is a separate question, asked by {@see dotNetSidecarMismatch}
 * where both are known.
 *
 * @return ?array{params: list<string>, declared: list<string>, optionalTail: int, flat: bool}
 */
function dotNetSignatureEntry(string $name, ?string $importerRoot = null): ?array
{
    $entry = dotNetLibMetadata()['signatures'][$name] ?? null;
    if ($entry === null) {
        return null;
    }
    if ($importerRoot !== null && $entry['roots'] !== [] && ! \in_array($importerRoot, $entry['roots'], true)) {
        return null;
    }

    return $entry;
}
