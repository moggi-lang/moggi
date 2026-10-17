<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\IntrinsicRegistry;

use function Moggi\Semantics\Kinds\canonicalTypeConName;

use Moggi\Semantics\TypeExpr\Scheme;

use function Moggi\Semantics\TypeExpr\scheme;

use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;

use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

/**
 * The machine modules Moggi.Internal.Prim, Moggi.Internal.IO and
 * Moggi.Internal.ST, and the kernel the compiler holds for each.
 *
 * All three modules describe the machine rather than the library, so none is a
 * library source file: the compiler builds each as a unit whose whole contents
 * are the kernel (`syntheticLocalTypes`). That kernel is the MagicHash layer and
 * nothing else -- the `#`-suffixed types and primops -- plus the one public name
 * the library cannot declare below its users: the operator it spells a primop as
 * (`(:)` for `listCons#`). Every other public name (`Int`, `Bool`, `List`, ...)
 * is a library declaration in the module that owns it.
 *
 * A primop's MagicHash name is also its IR/backend id, so `intrinsicWrappers`
 * maps each exported name to itself; a public alias maps to the primop it stands
 * for.
 */

const SYNTHETIC_PRIM_PATH = '<compiler:Moggi.Internal.Prim>';
const SYNTHETIC_IO_PATH = '<compiler:Moggi.Internal.IO>';
const SYNTHETIC_ST_PATH = '<compiler:Moggi.Internal.ST>';

function isSyntheticCompilerModuleName(string $moduleName): bool
{
    return $moduleName === IntrinsicRegistry\MODULE_PRIM
        || $moduleName === IntrinsicRegistry\MODULE_IO
        || $moduleName === IntrinsicRegistry\MODULE_ST;
}

function isSyntheticCompilerPath(string $path): bool
{
    return $path === SYNTHETIC_PRIM_PATH
        || $path === SYNTHETIC_IO_PATH
        || $path === SYNTHETIC_ST_PATH;
}

/** @return array<string, string> module name → sentinel path */
function syntheticCompilerModuleIndex(): array
{
    return [
        IntrinsicRegistry\MODULE_PRIM => SYNTHETIC_PRIM_PATH,
        IntrinsicRegistry\MODULE_IO => SYNTHETIC_IO_PATH,
        IntrinsicRegistry\MODULE_ST => SYNTHETIC_ST_PATH,
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
    $isIo = $moduleName === IntrinsicRegistry\MODULE_IO;
    $isSt = $moduleName === IntrinsicRegistry\MODULE_ST;
    $typeTable = $isIo
        ? IntrinsicRegistry\ioTypeTable()
        : ($isSt ? IntrinsicRegistry\stTypeTable() : IntrinsicRegistry\primTypeTable());

    $env = [];
    $wrappers = [];
    foreach (IntrinsicRegistry\typeSchemes() as $name => $scheme) {
        if (IntrinsicRegistry\ownerModule($name) !== $moduleName) {
            continue;
        }
        $env[$name] = $scheme;
        $wrappers[$name] = $name;
    }

    $data = syntheticPrimitiveData($typeTable);

    if (! $isIo && ! $isSt) {
        foreach (IntrinsicRegistry\publicPrimopAliases() as $public => $primop) {
            $env[$public] = IntrinsicRegistry\typeSchemes()[$primop];
            $wrappers[$public] = $primop;
        }
    }

    return [
        'env' => $env,
        'data' => $data,
        'typeSynonyms' => [],
        'intrinsicWrappers' => $wrappers,
    ];
}

/**
 * The fixities of a machine module's own declarations.
 *
 * A machine module has no file to walk, so the parser reads its fixities from
 * the declaration the unit carries rather than from an `infix` line, and a
 * module that imports the machine module gets them from here.
 *
 * @return array<string, array<string, array{assoc: string, prec: int}>>
 */
function syntheticCompilerFixity(): array
{
    return [IntrinsicRegistry\MODULE_PRIM => IntrinsicRegistry\publicFixities()];
}

/**
 * The kernel as the module's interface, for a machine module with no source.
 *
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
        if (isset($localTypes['intrinsicWrappers'][$name])) {
            $exports['intrinsicWrappers'][$name] = $localTypes['intrinsicWrappers'][$name];
        }
    }

    foreach ($localTypes['data'] as $name => $info) {
        $exports['data'][$name] = $info;
    }

    foreach ($localTypes['typeSynonyms'] as $name => $info) {
        $exports['typeSynonyms'][$name] = $info;
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
 * Build a prepare-ready unit for a machine module: the whole module is the
 * compiler-owned kernel, and it has no source of its own.
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
    $fixity = syntheticCompilerFixity()[$moduleName] ?? [];

    return [
        'path' => $path,
        'source' => "-- compiler-provided module {$moduleName}\n",
        'tokens' => [],
        'imports' => [],
        'namespace' => moduleNameToNamespace($moduleName),
        'fixity' => $fixity,
        'importedFixity' => [],
        'localFixity' => $fixity,
        'program' => $program,
        'parsed' => true,
        'localTypes' => $localTypes,
        'exports' => $exports,
        'synthetic' => true,
        'language' => ['NoImplicitPrelude' => true],
        'backendMap' => [],
        'implicitMain' => false,
        'checkedProgram' => $program,
    ];
}

/**
 * Ensure a unit exists for the machine modules, which have no source of their
 * own for the library to load.
 *
 * @param array<string, array<string, mixed>> $units
 */
function injectSyntheticCompilerUnits(array &$units): void
{
    foreach (syntheticCompilerModuleIndex() as $moduleName => $_path) {
        if (isset($units[$moduleName])) {
            continue;
        }

        $units[$moduleName] = buildSyntheticCompilerUnit($moduleName);
    }
}
