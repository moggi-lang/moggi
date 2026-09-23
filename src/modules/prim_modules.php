<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\IntrinsicRegistry;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Kinds\canonicalTypeConName;
use function Moggi\Semantics\TypeExpr\scheme;

/**
 * Compiler-synthesized modules Moggi.Internal.Prim and Moggi.Internal.IO.
 *
 * These do not exist as .mog sources. They export MagicHash types and `#`-suffixed
 * primops. A primop's MagicHash name is also its IR/backend id, so
 * `intrinsicWrappers` maps each exported name to itself.
 */

const SYNTHETIC_PRIM_PATH = '<compiler:Moggi.Internal.Prim>';
const SYNTHETIC_IO_PATH = '<compiler:Moggi.Internal.IO>';

function isSyntheticCompilerModuleName(string $moduleName): bool
{
    return $moduleName === IntrinsicRegistry\MODULE_PRIM
        || $moduleName === IntrinsicRegistry\MODULE_IO;
}

/**
 * Does this module import the primop module?
 *
 * Primops are only visible through that import, and a class default body keeps
 * the scope it was written in when an instance re-checks it.
 */
function moduleImportsPrim(Ast\Program $program): bool
{
    foreach ($program->imports as $import) {
        if (Ast\moduleName($import->path) === IntrinsicRegistry\MODULE_PRIM) {
            return true;
        }
    }

    return false;
}

function isSyntheticCompilerPath(string $path): bool
{
    return $path === SYNTHETIC_PRIM_PATH || $path === SYNTHETIC_IO_PATH;
}

/** @return array<string, string> module name → sentinel path */
function syntheticCompilerModuleIndex(): array
{
    return [
        IntrinsicRegistry\MODULE_PRIM => SYNTHETIC_PRIM_PATH,
        IntrinsicRegistry\MODULE_IO => SYNTHETIC_IO_PATH,
    ];
}

function syntheticPathForModule(string $moduleName): ?string
{
    return syntheticCompilerModuleIndex()[$moduleName] ?? null;
}

/**
 * @param array<string, array{params: list<string>, kindArity: int}> $typeTable
 * @return array<string, array{params: list<string>, result: Type, constructors: array<string, mixed>, primitive: true}>
 */
function syntheticPrimitiveData(array $typeTable): array
{
    $data = [];
    foreach ($typeTable as $name => $info) {
        $params = $info['params'];
        $args = \array_map(static fn (string $p): TVar => new TVar($p), $params);
        // Canonical internal head (Int# → Int) for TCon result identity.
        $canonical = canonicalTypeConName($name);
        $data[$name] = [
            'params' => $params,
            'result' => new TCon($canonical, $args),
            'constructors' => [],
            'primitive' => true,
        ];
    }

    return $data;
}

/**
 * @return array{
 *   env: array<string, Scheme>,
 *   data: array<string, mixed>,
 *   typeSynonyms: array<string, mixed>,
 *   intrinsicWrappers: array<string, string>
 * }
 */
function syntheticLocalTypes(string $moduleName): array
{
    $typeTable = $moduleName === IntrinsicRegistry\MODULE_IO
        ? IntrinsicRegistry\ioTypeTable()
        : IntrinsicRegistry\primTypeTable();

    $env = [];
    $wrappers = [];
    foreach (IntrinsicRegistry\typeSchemes() as $name => $scheme) {
        if (IntrinsicRegistry\ownerModule($name) !== $moduleName) {
            continue;
        }
        $env[$name] = $scheme;
        $wrappers[$name] = $name;
    }

    return [
        'env' => $env,
        'data' => syntheticPrimitiveData($typeTable),
        'typeSynonyms' => [],
        'intrinsicWrappers' => $wrappers,
    ];
}

/**
 * @param array{
 *   env: array<string, Scheme>,
 *   data: array<string, mixed>,
 *   typeSynonyms: array<string, mixed>,
 *   intrinsicWrappers: array<string, string>
 * } $localTypes
 * @return array<string, mixed>
 */
function syntheticExports(string $moduleName, array $localTypes): array
{
    $namespace = moduleNameToNamespace($moduleName);
    $exports = [
        'env' => [],
        'typeSynonyms' => [],
        'data' => [],
        'origins' => [],
        'intrinsicWrappers' => [],
        'qualifiedModules' => [],
        'moduleAsNames' => [],
    ];

    foreach ($localTypes['env'] as $name => $scheme) {
        $exports['env'][$name] = $scheme;
        // No origins: primops lower to IR\Intrinsic, not external PHP/JVM functions.
        $exports['intrinsicWrappers'][$name] = $localTypes['intrinsicWrappers'][$name];
    }

    foreach ($localTypes['data'] as $name => $info) {
        $exports['data'][$name] = $info;
    }

    return $exports;
}

function syntheticProgram(string $moduleName, array $localTypes): Ast\Program
{
    $exportItems = [];
    foreach (\array_keys($localTypes['data']) as $typeName) {
        $exportItems[] = ['tag' => 'type', 'name' => $typeName, 'children' => ['mode' => 'none', 'names' => []]];
    }
    foreach (\array_keys($localTypes['env']) as $valueName) {
        $exportItems[] = ['tag' => 'value', 'name' => $valueName];
    }

    return new Ast\Program(
        items: [],
        module: $moduleName,
        imports: [],
        exports: $exportItems,
        language: ['NoImplicitPrelude' => true],
    );
}

/**
 * Build a prepare-ready unit for a compiler-synthesized module.
 *
 * @return array<string, mixed>
 */
function buildSyntheticCompilerUnit(string $moduleName): array
{
    $path = syntheticPathForModule($moduleName);
    if ($path === null) {
        throw new \InvalidArgumentException("not a synthetic compiler module: {$moduleName}");
    }

    $localTypes = syntheticLocalTypes($moduleName);
    $program = syntheticProgram($moduleName, $localTypes);
    $exports = syntheticExports($moduleName, $localTypes);

    return [
        'path' => $path,
        'source' => "-- compiler-provided module {$moduleName}\n",
        'tokens' => [],
        'imports' => [],
        'namespace' => moduleNameToNamespace($moduleName),
        'fixity' => [],
        'importedFixity' => [],
        'localFixity' => [],
        'program' => $program,
        'parsed' => true,
        'localTypes' => $localTypes,
        'exports' => $exports,
        'synthetic' => true,
        'language' => ['NoImplicitPrelude' => true],
        'backendMap' => [],
        'implicitMain' => false,
        // Checked program is the empty synthetic program; no bodies to check.
        'checkedProgram' => $program,
    ];
}

/**
 * Ensure synthetic Prim/IO units exist whenever they appear in the closure
 * (or always, so imports resolve).
 *
 * @param array<string, array<string, mixed>> $units
 */
function injectSyntheticCompilerUnits(array &$units): void
{
    foreach (syntheticCompilerModuleIndex() as $moduleName => $_path) {
        if (!isset($units[$moduleName])) {
            $units[$moduleName] = buildSyntheticCompilerUnit($moduleName);
        }
    }
}
