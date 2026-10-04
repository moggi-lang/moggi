<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\IR;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\Types;
use Moggi\Syntax\Ast;

use function Moggi\Backend\compileBackend;
use function Moggi\Cache\moduleGet;
use function Moggi\Cache\modulePut;
use function Moggi\Semantics\Deriving\derivedProjectInstanceRecords;
use function Moggi\Semantics\Registry\foreignFunctionDecl;
use function Moggi\Syntax\Ast\moduleName;
use function Moggi\Syntax\isConstructorName;

require_once dirname(__DIR__) . '/semantics/deriving/framework.php';

/**
 * Type-environment plumbing for the module pipeline: project-wide class index,
 * per-module local type environments, import/export env merging, and the
 * module subset selection used for `--only` typechecking.
 */

/**
 * Types stay module-scoped: the state starts from the module's declared names and
 * the exported types of its imports, never from a project-wide union.
 *
 * @param array<string, mixed> $unit
 * @param array<string, array<string, mixed>> $units
 * @param array<string, mixed> $projectClasses
 */
function updateProjectClassesForModule(
    array $unit,
    array $units,
    string $moduleName,
    array &$projectClasses,
): void {
    $state = Types\newState($unit['source'], $unit['path']);
    $state->classes = $projectClasses;

    foreach ($unit['program']->imports as $import) {
        $targetName = moduleName($import->path);
        if (!isset($units[$targetName])) {
            throw new Types\TypeError("unknown module `{$targetName}`");
        }

        mergeExportedTypes($state, $units[$targetName], $units);
    }

    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\DataDecl || $item instanceof Ast\TypeSynonymDecl) {
            $state->declaredTypeNames[$item->name] = true;
        }
    }

    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\DataDecl) {
            Types\registerData($state, $item);
        }

        if ($item instanceof Ast\ForeignTypeDecl) {
            Types\registerForeignType($state, $item);
        }

        if ($item instanceof Ast\TypeSynonymDecl) {
            Types\registerTypeSynonym($state, $item);
        }

        if (!$item instanceof Ast\ClassDecl) {
            continue;
        }

        Types\registerClass($state, $item, $moduleName);
        $name = $item->name;
        if (isset($projectClasses[$name])) {
            throw new Types\TypeError("duplicate class `{$name}` in project");
        }

        $projectClasses[$name] = $state->classes[$name];
    }
}

/**
 * @param array<string, array<string, mixed>> $units
 * @param array<string, mixed> $projectClasses
 * @param array{
 *   byClass?: array<string, list<array<string, mixed>>>,
 *   byClassHead?: array<string, array<string, list<array<string, mixed>>>>,
 *   associatedEquations?: array<string, array<string, list<array<string, mixed>>>>
 * } $projectInstanceIndex an empty array is the "no project instances" index
 */
function buildModuleLocalTypes(
    array &$units,
    string $moduleName,
    array $projectClasses,
    array $projectInstanceIndex = [],
): void {
    $contentKey = $units[$moduleName]['contentKey'] ?? null;
    if ($contentKey !== null) {
        $cached = moduleGet(
            $units[$moduleName]['cacheRelPath'],
            'localtypes',
            $contentKey,
        );
        if (\is_array($cached)) {
            $units[$moduleName]['localTypes'] = $cached;
            return;
        }
    }

    $unit = $units[$moduleName];
    $state = Types\newState($unit['source'], $unit['path']);
    $state->classes = $projectClasses;
    Types\syncAssociatedFamiliesFromClasses($state);
    installProjectInstanceIndex($state, $projectInstanceIndex);

    foreach ($unit['program']->imports as $import) {
        $targetName = moduleName($import->path);
        if (isset($units[$targetName])) {
            $localPrefix = $import->asName ?? $targetName;
            $state->qualifiedModules[$localPrefix] = $units[$targetName]['namespace'];
            mergeExportedTypes($state, $units[$targetName], $units);
        }
    }

    mergeImportedEnv($state, $units, $moduleName);
    mergeFacadeTypesForImpl($state, $units, $moduleName);

    $localFunctionNames = [];
    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\FunctionDecl) {
            $localFunctionNames[$item->name] = true;
        }
    }

    mergeFacadeBackendEnv(
        $state->env,
        $state->intrinsicWrappers,
        $units,
        $moduleName,
        $localFunctionNames,
        $state,
    );

    $definedTypeSynonyms = [];
    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\TypeSynonymDecl) {
            $definedTypeSynonyms[$item->name] = true;
        }
    }

    Types\applyPrimitiveTypeSynonymBootstrap($state, $definedTypeSynonyms);

    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\DataDecl || $item instanceof Ast\TypeSynonymDecl) {
            $state->declaredTypeNames[$item->name] = true;
        }
    }

    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\FunctionDecl || $item instanceof Ast\ForeignImportDecl) {
            $state->localDeclNames[$item->name] = true;
        }
    }

    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\DataDecl) {
            Types\registerData($state, $item);
        }

        if ($item instanceof Ast\ForeignTypeDecl) {
            Types\registerForeignType($state, $item);
        }

        if ($item instanceof Ast\TypeSynonymDecl) {
            Types\registerTypeSynonym($state, $item);
        }

        if ($item instanceof Ast\ClassDecl) {
            if (!isset($state->classes[$item->name])) {
                Types\registerClass($state, $item, $moduleName);
            }

            Types\registerClassMethodExportSchemes($state, $item);
        }
    }

    $inferredFunctions = [];
    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\InstanceDecl) {
            Types\registerInstanceSchemes($state, $item);
            continue;
        }

        if ($item instanceof Ast\ForeignImportDecl) {
            Types\registerFunctionScheme($state, foreignFunctionDecl($item));
            continue;
        }

        if (!$item instanceof Ast\FunctionDecl) {
            continue;
        }

        if (Ast\hasDeclaredSignature($item)) {
            Types\registerFunctionScheme($state, $item);
        } elseif ($item->inferredSignatureType !== null) {
            Types\registerInferredSignatureScheme($state, $item);
        } elseif (!$item->signatureOnly) {
            $inferredFunctions[] = $item;
        }
    }

    foreach ($inferredFunctions as $function) {
        Types\registerInferredFunctionPlaceholder($state, $function);
    }

    Types\discoverInferredSignatures($state, $inferredFunctions);


    $units[$moduleName]['localTypes'] = [
        'env' => $state->env,
        'data' => $state->data,
        'typeSynonyms' => $state->typeSynonyms,
        'intrinsicWrappers' => $state->intrinsicWrappers,
    ];

    if ($contentKey !== null) {
        modulePut(
            $units[$moduleName]['cacheRelPath'],
            'localtypes',
            $contentKey,
            $units[$moduleName]['localTypes'],
        );
    }
}

/** @param array<string, array<string, mixed>> $units */
function mergeImportedEnv(Types\TypeCheckState $state, array $units, string $moduleName): void
{
    foreach ($units[$moduleName]['program']->imports as $import) {
        $targetName = moduleName($import->path);
        if (!isset($units[$targetName])) {
            continue;
        }

        $target = $units[$targetName];
        try {
            $exports = $target['exports'] ?? collectExports($target['program'], $target['localTypes'], $units, $targetName);
            $selected = selectImportedNames($import, $exports);
        } catch (Types\TypeError $e) {
            if ($e->filename !== '') {
                throw $e;
            }

            throw new Types\TypeError(
                $e->getMessage(),
                $units[$moduleName]['path'],
                $units[$moduleName]['source'],
                $import->line,
                $import->col,
                $import->endCol,
            );
        }

        $localPrefix = $import->asName ?? $targetName;
        foreach ($selected['env'] as $name => $scheme) {
            $state->qualifiedEnv[$localPrefix][$name] = $scheme;
            if (isset($selected['intrinsicWrappers'][$name])) {
                $state->intrinsicWrappers[$name] = $selected['intrinsicWrappers'][$name];
            }
            if ($import->qualifiedOnly) {
                continue;
            }

            if (!isset($state->env[$name]) || isset($state->standaloneEnvNames[$name])) {
                $state->env[$name] = $scheme;
                unset($state->standaloneEnvNames[$name]);
            }
        }

        foreach ($selected['typeSynonyms'] as $name => $type) {
            if (!isset($state->typeSynonyms[$name])) {
                $state->typeSynonyms[$name] = $type;
            }
        }

        foreach ($selected['data'] as $name => $info) {
            if (!isset($state->data[$name])) {
                $state->data[$name] = $info;
                Kinds\registerTypeKind(
                    $state,
                    $name,
                    Kinds\dataKind(count($info['params'])),
                );
            }
        }

        if (!$import->qualifiedOnly) {
            foreach ($selected['constructorRenames'] as $local => $canonical) {
                $state->constructorRenames[$local] = $canonical;
            }
        }
    }
}

function mergeExportedTypes(Types\TypeCheckState $state, array $unit, array $units = []): void
{
    if (isset($unit['localTypes'])) {
        $exports = $unit['exports'] ?? collectExports($unit['program'], $unit['localTypes'], $units, $unit['program']->module);
        foreach ($exports['data'] as $name => $info) {
            if (!isset($state->data[$name])) {
                $state->data[$name] = $info;
            }
            Kinds\registerTypeKind($state, $name, Kinds\kindFromDataInfo($info));
            Kinds\installPromotedFromDataInfo($state, $name, $info);
        }

        foreach ($exports['typeSynonyms'] as $name => $type) {
            if (!isset($state->typeSynonyms[$name])) {
                $state->typeSynonyms[$name] = $type;
            }
        }

        return;
    }

    $exportedTypes = exportedTypeItems($unit['program']);
    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\DataDecl && isset($exportedTypes[$item->name])) {
            if (!isset($state->data[$item->name])) {
                Types\registerData($state, $item);
            } else {
                Kinds\installPromotedFromDataInfo(
                    $state,
                    $item->name,
                    $state->data[$item->name],
                );
            }
        }

        if ($item instanceof Ast\TypeSynonymDecl && isset($exportedTypes[$item->name])) {
            if (!isset($state->typeSynonyms[$item->name])) {
                Types\registerTypeSynonym($state, $item);
            }
        }
    }
}

/**
 * Scope of every module that declares a class with default method bodies.
 *
 * A default body is re-checked at each instance site, in the instance's module,
 * so it needs the scope it was written in (a helper it calls, a primop it uses).
 * A module's interface is a product of its own check, so the caller adds each
 * module's scope as it is checked; `$onlyModules` limits the pass to the ones
 * whose interface just became available.
 *
 * @param array<string, array<string, mixed>> $units
 * @param array<string, array<string, mixed>> $projectClasses
 * @param ?list<string> $onlyModules
 * @return array<string, array<string, mixed>>
 */
function classModuleScopes(array $units, array $projectClasses, ?array $onlyModules = null): array
{
    $wanted = $onlyModules !== null ? \array_fill_keys($onlyModules, true) : null;

    /** @var array<string, array<string, true>> $referenced */
    $referenced = [];
    foreach ($projectClasses as $classInfo) {
        $module = $classInfo['module'] ?? '';
        if ($module === '' || ($wanted !== null && !isset($wanted[$module]))) {
            continue;
        }

        foreach ($classInfo['methods'] ?? [] as $methodInfo) {
            $body = $methodInfo['defaultBody'] ?? null;
            if ($body === null) {
                continue;
            }

            foreach (IR\freeVarsExpr($body, []) as $name) {
                $referenced[$module][$name] = true;
            }

            foreach (classScopeConstructorRefs($body) as $name => $_) {
                $referenced[$module][$name] = true;
            }
        }
    }

    $scopes = [];
    foreach ($referenced as $module => $names) {
        $env = $units[$module]['localTypes']['env'] ?? null;
        if (!\is_array($env)) {
            continue;
        }

        $origins = classModuleValueOrigins($units, $module, $names);
        $fns = [];
        $dataRefs = [];
        $constructorArity = [];
        foreach (\array_keys($names) as $name) {
            $scheme = $env[$name] ?? null;
            if (!$scheme instanceof Scheme || $scheme->classMethod) {
                continue;
            }
            $origin = $origins[$name] ?? null;
            if ($origin === null) {
                continue;
            }

            $owner = declaringDataTypeFor($units, $origin['module'], $name);
            if ($owner !== null) {
                $dataRefs[$owner] = $units[$origin['module']]['localTypes']['data'][$owner];
                $fns[$name] = [
                    'origin' => $origin,
                    'scheme' => $scheme,
                ];
                $constructorArity[$name] = \count(
                    $dataRefs[$owner]['constructors'][$name]['fields'] ?? [],
                );
                continue;
            }

            $fns[$name] = [
                'origin' => $origin,
                'scheme' => $scheme,
            ];
        }

        $scopes[$module] = [
            'env' => $env,
            'constructorArity' => $constructorArity,
            'fns' => $fns,
            'dataRefs' => $dataRefs,
            'visible' => classModuleVisibleTypes($units, $module),
        ];
    }

    return $scopes;
}

/**
 * Types a facade module needs from the impl it re-exports: the impl's foreign
 * types and the facade ADTs copied into it.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array{data: array<string, mixed>, typeSynonyms: array<string, mixed>}
 */
function facadeImplExtraTypes(array $units, string $moduleName, Ast\Program $program): array
{
    $implName = facadeImplModuleNameFor($moduleName, $units);
    if ($implName === null || !isset($units[$implName])) {
        return ['data' => [], 'typeSynonyms' => []];
    }

    $implExports = implExportsForFacadeMerge($units[$implName], $units, $implName);
    if ($implExports === null) {
        return ['data' => [], 'typeSynonyms' => []];
    }

    $declaredData = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\DataDecl || $item instanceof Ast\ForeignTypeDecl) {
            $declaredData[$item->name] = true;
        }
    }

    $data = [];
    foreach ($implExports['data'] ?? [] as $name => $info) {
        if (!isset($declaredData[$name])) {
            $data[$name] = $info;
        }
    }

    return ['data' => $data, 'typeSynonyms' => []];
}

/**
 * Types a module needs but does not declare: the facade's ADTs and the
 * primitive data its facade introduces (for an impl), or the impl's foreign
 * types and ADTs (for a facade).
 *
 * A module's check registers its own declarations, so the only interface data it
 * cannot derive is what the other side of the facade contributes; the caller
 * merges this into the check's import context, where the interface pass used to
 * put it as `localTypes`.
 *
 * @param array<string, array<string, mixed>> $units
 * @param array<string, mixed> $projectClasses
 * @param array<string, mixed> $projectInstanceIndex
 * @return array{data: array<string, mixed>, typeSynonyms: array<string, mixed>}
 */
function moduleFacadeExtraTypes(
    array $units,
    string $moduleName,
    array $projectClasses,
    array $projectInstanceIndex = [],
): array {
    $unit = $units[$moduleName];
    $program = $unit['program'] ?? null;
    if (! $program instanceof Ast\Program) {
        return ['data' => [], 'typeSynonyms' => []];
    }

    if (isFacadeProgram($program)) {
        return facadeImplExtraTypes($units, $moduleName, $program);
    }

    if (facadeModuleForImpl($units, $moduleName) === null) {
        return ['data' => [], 'typeSynonyms' => []];
    }

    $state = Types\newState($unit['source'], $unit['path']);
    $state->classes = $projectClasses;
    installProjectInstanceIndex($state, $projectInstanceIndex);

    foreach ($unit['program']->imports as $import) {
        $targetName = moduleName($import->path);
        if (isset($units[$targetName])) {
            mergeExportedTypes($state, $units[$targetName], $units);
        }
    }

    mergeFacadeTypesForImpl($state, $units, $moduleName);

    $declaredData = [];
    $declaredSynonyms = [];
    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\DataDecl || $item instanceof Ast\ForeignTypeDecl) {
            $declaredData[$item->name] = true;
        }

        if ($item instanceof Ast\TypeSynonymDecl) {
            $declaredSynonyms[$item->name] = true;
        }
    }

    $data = [];
    foreach ($state->data as $name => $info) {
        if (!isset($declaredData[$name])) {
            $data[$name] = $info;
        }
    }

    $synonyms = [];
    foreach ($state->typeSynonyms as $name => $type) {
        if (!isset($declaredSynonyms[$name])) {
            $synonyms[$name] = $type;
        }
    }

    return ['data' => $data, 'typeSynonyms' => $synonyms];
}

/**
 * Constructor names a default body mentions, as expression or as pattern.
 *
 * @return array<string, true>
 */
function classScopeConstructorRefs(Ast\AstNode $expr): array
{
    $names = [];
    if ($expr instanceof Ast\ConstructorRef) {
        $names[$expr->name] = true;
    } elseif ($expr instanceof Ast\PatCon || $expr instanceof Ast\PatCons) {
        $names[$expr->name] = true;
    } elseif ($expr instanceof Ast\OperatorRef && isConstructorName($expr->name)) {
        $names[$expr->name] = true;
    }

    foreach ($expr->childValues() as $child) {
        if ($child instanceof Ast\AstNode) {
            foreach (classScopeConstructorRefs($child) as $name => $_) {
                $names[$name] = true;
            }
        }
    }

    return $names;
}

/**
 * The data type of `$moduleName` that declares constructor `$name`, if any.
 *
 * @param array<string, array<string, mixed>> $units
 */
function declaringDataTypeFor(array $units, string $moduleName, string $name): ?string
{
    foreach ($units[$moduleName]['localTypes']['data'] ?? [] as $typeName => $info) {
        if (isset($info['constructors'][$name])) {
            return (string) $typeName;
        }
    }

    return null;
}

/**
 * Where each name a class's default bodies use is actually declared.
 *
 * The re-check happens in the *instance's* module, so the symbol has to be
 * spelled out in full -- and a name the class's module merely imports is not
 * that module's: `appEndo` reaching `Data.Foldable` from `Data.Monoid`
 * would otherwise be emitted as `Data.Foldable.appEndo`, which does not exist.
 *
 * @param array<string, array<string, mixed>> $units
 * @param array<string, true> $names
 * @return array<string, array{module: string, namespace: string, phpName: string}>
 */
function classModuleValueOrigins(array $units, string $moduleName, array $names): array
{
    $unit = $units[$moduleName];
    $namespace = $unit['namespace'] ?? moduleNameToNamespace($moduleName);
    $origins = [];

    foreach ($unit['program']->items as $item) {
        if (($item instanceof Ast\FunctionDecl || $item instanceof Ast\ForeignImportDecl)
            && isset($names[$item->name])
        ) {
            $origins[$item->name] = exportOrigin($moduleName, $namespace, $item->name);
        }
    }

    foreach ($unit['program']->imports as $import) {
        if ($import->qualifiedOnly) {
            continue;
        }

        $targetName = moduleName($import->path);
        $target = $units[$targetName] ?? null;
        if ($target === null) {
            continue;
        }

        try {
            $exports = $target['exports'] ?? null;
            if ($exports === null) {
                if (!isset($target['program'], $target['localTypes'])) {
                    continue;
                }

                $exports = collectExports($target['program'], $target['localTypes'], $units, $targetName);
            }

            $selected = selectImportedNames($import, $exports);
        } catch (Types\TypeError) {
            continue;
        }

        foreach ($selected['origins'] as $name => $origin) {
            if (isset($names[$name])) {
                $origins[$name] ??= $origin;
            }
        }
    }

    return $origins;
}

/**
 * Names a module binds itself.
 *
 * The class-scope symbol tables are keyed by bare name and describe *other*
 * modules, so they must never decide how a module's own declaration is named --
 * `Data.Monoid.appEndo` is a local call there, not an import.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array<string, true>
 */
function moduleLocalValueNames(array $units, string $moduleName): array
{
    static $cache = [];
    if (isset($cache[$moduleName])) {
        return $cache[$moduleName];
    }

    $program = $units[$moduleName]['program'] ?? null;
    if (!$program instanceof Ast\Program) {
        return $cache[$moduleName] = [];
    }

    $names = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl || $item instanceof Ast\ForeignImportDecl) {
            $names[$item->name] = true;
        }
    }

    return $cache[$moduleName] = $names;
}

/**
 * Data type names a module already has without the class-scope hand-over.
 *
 * The hand-over exists for the types a default body builds that only the
 * class's module can see (`Endo`, `Dual` in `Data.Foldable`). A type the target
 * module declares itself is not one of them: merging `Bool` back into
 * `Data.Bool` makes `registerData` see a duplicate. `Bool` is excluded for every
 * module because the compiler bootstraps it already
 * (`applyPrimitiveDataBootstrap`), so it never needs transferring.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array<string, true>
 */
function moduleLocalDataNames(array $units, string $moduleName): array
{
    static $cache = [];
    if (isset($cache[$moduleName])) {
        return $cache[$moduleName];
    }

    $names = ['Bool' => true];
    $program = $units[$moduleName]['program'] ?? null;
    if (!$program instanceof Ast\Program) {
        return $cache[$moduleName] = $names;
    }

    foreach ($program->items as $item) {
        if ($item instanceof Ast\DataDecl) {
            $names[$item->name] = true;
        }
    }

    return $cache[$moduleName] = $names;
}

/**
 * The type constructors a module's default method bodies may name.
 *
 * A default body is re-checked at each instance site, in the instance's module,
 * so the types it was written against -- `Endo` and `Dual` in `Data.Foldable`'s
 * `foldr`/`foldl` defaults, imported by the class's module and not by the
 * instance's -- have to stay resolvable there. The instance check installs these
 * names (with their arity) for the duration of the default-body check.
 *
 * The set is deliberately a union over the class module's own declarations and
 * everything its imports export: an over-wide set cannot admit anything, because
 * the body already compiled where it was written and can only name types that
 * were visible there.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array{
 *   types: array<string, int|null>,
 *   synonyms: array<string, mixed>
 * }
 */
function classModuleVisibleTypes(array $units, string $moduleName): array
{
    $types = [];
    $synonyms = [];
    $collect = static function (array $data, array $typeSynonyms) use (&$types, &$synonyms): void {
        foreach ($data as $name => $info) {
            $types[$name] = \is_array($info) ? \count($info['params'] ?? []) : null;
        }
        foreach ($typeSynonyms as $name => $info) {
            $types[$name] = null;
            $synonyms[$name] = $info;
        }
    };

    $collect($units[$moduleName]['localTypes']['data'] ?? [], $units[$moduleName]['localTypes']['typeSynonyms'] ?? []);

    foreach (moduleDependencyNames($units, $moduleName) as $imported) {
        $exports = $units[$imported]['exports'] ?? null;
        if ($exports === null && isset($units[$imported]['program'], $units[$imported]['localTypes'])) {
            $exports = collectExports(
                $units[$imported]['program'],
                $units[$imported]['localTypes'],
                $units,
                $imported,
            );
        }
        if (\is_array($exports)) {
            $collect($exports['data'] ?? [], $exports['typeSynonyms'] ?? []);
        }
    }

    return ['types' => $types, 'synonyms' => $synonyms];
}

/**
 * Symbol references a module needs for the default bodies it re-checks.
 *
 * A default body may call a function of its class's module that the instance's
 * module never imports (`showListWith`); lowering and codegen still have to name
 * that function's module symbol.
 *
 * @param array<string, array<string, mixed>> $units
 * @param array<string, array<string, mixed>> $classScopes
 * @return array{externalFns: array<string, string>, arity: array<string, int>, dataRefs: array<string, array<string, mixed>>}
 */
function classScopeFunctionRefs(array $units, array $classScopes): array
{
    $externalFns = [];
    $arity = [];
    $dataRefs = [];
    foreach ($classScopes as $scope) {
        foreach ($scope['fns'] ?? [] as $name => $fn) {
            $origin = $fn['origin'];
            $externalFns[$name] = resolvedSymbol($origin['module'], $origin['phpName']);
            $arity[$name] = $scope['constructorArity'][$name]
                ?? externalFunctionRuntimeArity($units, $origin['module'], $name, $fn['scheme']);
        }

        foreach ($scope['dataRefs'] ?? [] as $typeName => $info) {
            $dataRefs[$typeName] ??= $info;
        }
    }

    return ['externalFns' => $externalFns, 'arity' => $arity, 'dataRefs' => $dataRefs];
}

/**
 * Fold class-scope symbol and data references into a module's codegen imports.
 *
 * @param array<string, mixed> $importContext
 * @param array{externalFns: array<string, string>, arity: array<string, int>, dataRefs: array<string, array<string, mixed>>} $refs
 * @param array<string, true> $localNames names the module binds itself
 * @return array<string, mixed>
 */
function mergeClassScopeRefs(
    array $importContext,
    array $refs,
    array $localNames = [],
    array $seenData = [],
): array
{
    foreach ($refs['externalFns'] as $name => $symbol) {
        if (isset($localNames[$name])) {
            continue;
        }
        $importContext['codegen']['externalFns'][$name] ??= $symbol;
        $importContext['externalFnRuntimeArity'][$name] ??= $refs['arity'][$name] ?? 0;
    }

    foreach ($refs['dataRefs'] ?? [] as $typeName => $info) {
        if (isset($seenData[$typeName])) {
            continue;
        }
        $importContext['data'][$typeName] ??= $info;
    }

    return $importContext;
}

/** @param array<string, array<string, mixed>> $units @param list<string>|null $onlyModules @return list<array{module: string, class: string, head: array<string, mixed>}> */
function collectProjectInstances(array $units, ?array $onlyModules = null): array
{
    $instances = [];
    $allowed = $onlyModules !== null ? \array_fill_keys($onlyModules, true) : null;

    foreach ($units as $moduleName => $unit) {
        if ($allowed !== null && !isset($allowed[$moduleName])) {
            continue;
        }

        if (($unit['parsed'] ?? false) !== true) {
            continue;
        }

        foreach ($unit['program']->items as $item) {
            if ($item instanceof Ast\InstanceDecl) {
                $instances[] = [
                    'module' => $moduleName,
                    'class' => $item->class,
                    'head' => $item->head,
                    'constraints' => $item->constraints,
                    'associatedEquations' => Types\associatedEquationsMapFromDecl($item),
                ];
                continue;
            }

            if ($item instanceof Ast\DataDecl && $item->derivingClasses !== []) {
                foreach (derivedProjectInstanceRecords($item, $moduleName) as $record) {
                    $instances[] = $record;
                }
            }
        }
    }

    return $instances;
}

/**
 * Build class/head/associated-equation indexes once for the project instance set.
 *
 * Two modules that derive the same metadata instance for a shared shape
 * (`Selector` for a field name, say) produce identical heads and one evidence
 * name, so the first is indexed once and the second module's copy stands as its
 * own unused evidence.
 *
 * @param list<array<string, mixed>> $projectInstances
 * @return array{
 *   byClass: array<string, list<array<string, mixed>>>,
 *   byClassHead: array<string, array<string, list<array<string, mixed>>>>,
 *   associatedEquations: array<string, array<string, list<array<string, mixed>>>>
 * }
 */
function indexProjectInstances(array $projectInstances): array
{
    $byClass = [];
    $byClassHead = [];
    $associatedEquations = [];
    $seenDerivedHeads = [];
    foreach ($projectInstances as $instance) {
        $className = $instance['class'];
        $headKey = Types\instanceHeadIndexKeyFromAst($instance['head']);

        if (($instance['fromDeriving'] ?? false) === true && $instance['head'] instanceof Ast\AstNode) {
            $headText = $className . "\0" . Ast\dumpTypeInline($instance['head']);
            if (isset($seenDerivedHeads[$headText])) {
                continue;
            }
            $seenDerivedHeads[$headText] = true;
        }

        $byClass[$className][] = $instance;
        $byClassHead[$className][$headKey][] = $instance;
        $module = $instance['module'] ?? '';
        foreach ($instance['associatedEquations'] ?? [] as $famName => $eq) {
            $associatedEquations[$className][$famName][] = [
                'lhsArgs' => $eq['lhsArgs'],
                'rhs' => $eq['rhs'],
                'module' => $module,
            ];
        }
    }

    return [
        'byClass' => $byClass,
        'byClassHead' => $byClassHead,
        'associatedEquations' => $associatedEquations,
    ];
}

/**
 * @param array{
 *   byClass?: array<string, list<array<string, mixed>>>,
 *   byClassHead?: array<string, array<string, list<array<string, mixed>>>>,
 *   associatedEquations?: array<string, array<string, list<array<string, mixed>>>>
 * } $index
 */
function installProjectInstanceIndex(Types\TypeCheckState $state, array $index): void
{
    $state->projectInstancesByClass = $index['byClass'] ?? [];
    $state->projectInstancesByClassHead = $index['byClassHead'] ?? [];
    $state->associatedEquations = $index['associatedEquations'] ?? [];
}

/**
 * @param array<string, array<string, mixed>> $units
 * @param array<string, Scheme> $schemes
 */
function syncExportedInferredSchemesFromTypeEnv(
    array &$units,
    string $moduleName,
    array $schemes,
): void {
    if (!isset($units[$moduleName]['localTypes']) || $schemes === []) {
        return;
    }

    foreach ($schemes as $name => $scheme) {
        $units[$moduleName]['localTypes']['env'][$name] = $scheme;
        if (isset($units[$moduleName]['exports']['env'][$name])) {
            $units[$moduleName]['exports']['env'][$name] = $scheme;
        }
    }
}

/**
 * Backend impl modules for a non-selected compile backend are parsed for the
 * module graph but must not be typechecked/codegen'd (their foreign imports
 * target a different backend).
 *
 * @param array<string, array<string, mixed>> $units
 */
function isModuleActiveForCompileBackend(array $units, string $moduleName): bool
{
    $lower = strtolower($moduleName);
    foreach (['php', 'jvm', 'js', 'dotnet'] as $backend) {
        if (str_ends_with($lower, '.' . $backend)) {
            return $backend === compileBackend();
        }
    }

    $facade = facadeModuleForImpl($units, $moduleName);
    if ($facade === null) {
        return true;
    }

    $backendMap = $units[$facade]['program']->backendMap
        ?? ($units[$facade]['backendMap'] ?? []);

    return resolveImplModuleName($backendMap) === $moduleName;
}
