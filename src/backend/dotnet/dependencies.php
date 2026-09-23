<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Dependencies;

use function Moggi\Modules\configuredStdlibLibPath;

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
 * Absolute paths of `dotnet` directories under the stdlib root that contain
 * metadata files.
 *
 * @return list<string>
 */
function discoverDotNetLibDirs(): array
{
    $lib = configuredStdlibLibPath();
    if ($lib === null || $lib === '') {
        $fallback = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lib';
        $lib = \is_dir($fallback) ? $fallback : null;
    }
    if ($lib === null) {
        return [];
    }

    $dirs = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'json') {
            continue;
        }
        $dir = $file->getPath();
        if (basename($dir) !== 'dotnet') {
            continue;
        }
        $dirs[$dir] = true;
    }

    $dirs = \array_keys($dirs);
    \sort($dirs);

    return $dirs;
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
                    // Overlap is a hard error: a CLR type has exactly one
                    // defining assembly and one value-type-ness, so two libs
                    // disagreeing cannot silently resolve either way.
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
    // Nested types are spelled `Outer/Inner`.
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
