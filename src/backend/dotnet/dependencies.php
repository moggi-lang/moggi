<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Dependencies;

use function Moggi\Modules\libraryScanRoots;

/**
 * Library-owned .NET host metadata.
 *
 * A library may drop `dotnet/*.json` files under its module directory (next to
 * the module's `.mog` sources), the same way it vendors JVM jars under a
 * `jvm/` directory. The files declare the CLR details a backend cannot infer
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
function discoverDotNetLibDirs(): array
{
    $dirs = [];
    foreach (libraryScanRoots() as $lib) {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $extension = $file->getExtension();
            if ($extension !== 'json' && $extension !== 'dll') {
                continue;
            }
            $dir = $file->getPath();
            if (basename($dir) !== 'dotnet') {
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
        foreach (\glob($dir . DIRECTORY_SEPARATOR . '*.dll') ?: [] as $dll) {
            $assemblies[\basename($dll)] = $dll;
        }
    }

    \ksort($assemblies, \SORT_STRING);

    return \array_values($assemblies);
}

/**
 * Merged metadata from every discovered library directory.
 *
 * @return array{
 *     types: array<string, array{assembly: string, valueType: bool}>,
 *     signatures: array<string, list<string>>
 * }
 */
function dotNetLibMetadata(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $types = [];
    $signatures = [];
    $typeSource = [];
    $signatureSource = [];

    foreach (discoverDotNetLibDirs() as $dir) {
        $files = \glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        \sort($files);
        foreach ($files as $file) {
            $raw = \file_get_contents($file);
            if ($raw === false) {
                throw new \RuntimeException("cannot read .NET library metadata `{$file}`");
            }
            $data = \json_decode($raw, true);
            if (!\is_array($data)) {
                throw new \RuntimeException("invalid .NET library metadata `{$file}`");
            }

            $assemblies = $data['assemblies'] ?? [];
            if (!\is_array($assemblies)) {
                continue;
            }
            foreach ($assemblies as $assembly => $spec) {
                if (!\is_string($assembly) || !\is_array($spec)) {
                    continue;
                }
                foreach (($spec['types'] ?? []) as $clrType => $typeSpec) {
                    if (!\is_string($clrType)) {
                        continue;
                    }
                    $valueType = \is_array($typeSpec) ? (bool) ($typeSpec['valueType'] ?? false) : false;
                    if (isset($types[$clrType])) {
                        if ($types[$clrType]['assembly'] !== $assembly) {
                            throw new \RuntimeException(
                                ".NET type `{$clrType}` declared in assemblies `{$types[$clrType]['assembly']}`"
                                . " ({$typeSource[$clrType]}) and `{$assembly}` ({$file})",
                            );
                        }
                        if ($types[$clrType]['valueType'] !== $valueType) {
                            throw new \RuntimeException(
                                ".NET type `{$clrType}` declared with conflicting valueType"
                                . " ({$typeSource[$clrType]} vs {$file})",
                            );
                        }
                    }
                    $types[$clrType] = ['assembly' => $assembly, 'valueType' => $valueType];
                    $typeSource[$clrType] = $file;
                }
                foreach (($spec['signatures'] ?? []) as $name => $params) {
                    if (!\is_string($name) || !\is_array($params)) {
                        continue;
                    }
                    $list = \array_values(\array_map(
                        static fn ($p): string => (string) $p,
                        $params,
                    ));
                    if (isset($signatures[$name]) && $signatures[$name] !== $list) {
                        throw new \RuntimeException(
                            ".NET foreign signature `{$name}` declared twice with different parameters"
                            . " ({$signatureSource[$name]} vs {$file})",
                        );
                    }
                    $signatures[$name] = $list;
                    $signatureSource[$name] = $file;
                }
            }
        }
    }

    return $cache = ['types' => $types, 'signatures' => $signatures];
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
 * Explicit IL parameter list declared for a foreign import, if any.
 *
 * @return list<string>|null
 */
function dotNetDeclaredSignature(string $name): ?array
{
    return dotNetLibMetadata()['signatures'][$name] ?? null;
}
