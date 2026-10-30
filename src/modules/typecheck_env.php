<?php declare(strict_types=1);

namespace Moggi\Modules;

/**
 * Type-environment plumbing for the module pipeline: project-wide class index,
 * export/type merging for the modules an interface hands on, class default-body
 * scopes, and the module subset selection used for `--only` typechecking.
 *
 * Nothing here produces a module's interface: that is the product of the module's
 * own check (`prepareProject`), read back from the module cache by a later run.
 */

use Moggi\IR;
use Moggi\Semantics\Deriving\ClassDeriveFacts;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\Types;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Kernel\boolTypeName;
use function Moggi\Semantics\Registry\projectInstancesFromProgram;
use function Moggi\Syntax\Ast\moduleName;
use function Moggi\Syntax\isConstructorName;

require_once \dirname(__DIR__) . '/semantics/deriving/framework.php';

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
    $state->module = $moduleName;
    $state->classes = $projectClasses;

    foreach ($unit['program']->imports as $import) {
        $targetName = moduleName($import->path);
        if (! isset($units[$targetName])) {
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

        if (! $item instanceof Ast\ClassDecl) {
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

function mergeExportedTypes(Types\TypeCheckState $state, array $unit, array $units = []): void
{
    if (isset($unit['localTypes'])) {
        $exports = $unit['exports'] ?? collectExports($unit['program'], $unit['localTypes'], $units, $unit['program']->module);
        foreach ($exports['data'] as $name => $info) {
            if (! isset($state->data[$name])) {
                $state->data[$name] = $info;
            }
            Kinds\registerTypeKind($state, $name, Kinds\kindFromDataInfo($info));
            Kinds\installPromotedFromDataInfo($state, $name, $info);
        }

        foreach ($exports['typeSynonyms'] as $name => $type) {
            if (! isset($state->typeSynonyms[$name])) {
                $state->typeSynonyms[$name] = $type;
            }
            Types\installTypeSynonymKind($state, $name, $type);
        }

        return;
    }

    $exportedTypes = exportedTypeItems($unit['program']);
    foreach ($unit['program']->items as $item) {
        if ($item instanceof Ast\DataDecl && isset($exportedTypes[$item->name])) {
            if (! isset($state->data[$item->name])) {
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
            if (! isset($state->typeSynonyms[$item->name])) {
                Types\registerTypeSynonym($state, $item);
            }
        }
    }
}

/**
 * Default bodies still hold `do`-notation: inference desugars each `DoExpr`
 * before walking free variables, but the class-scope scan runs earlier and
 * holds none of the elaborator's evidence bindings, so bind-bound names (and
 * bare statements) fall out of the variable set. Desugaring a private copy
 * with the monad bind -- exactly as inference does -- recovers the variables
 * elaboration will actually see.
 *
 * Only the bare variable names matter here (constructor versus not, local
 * versus not), and the bind operator itself is never one, so the exact
 * elaborated dictionary plumbing is irrelevant to the names found.
 */
function desugarDoForScope(Ast\AstWalkable $node): void
{
    if ($node instanceof Ast\DoExpr && $node->stmts !== []) {
        if ($node->desugared === null) {
            $node->desugared = Ast\desugarDo($node->stmts, '(>>=)');
        }
        if ($node->sourceFile !== null) {
            Ast\markSourceFile($node->desugared, $node->sourceFile);
        }
    }

    foreach ($node->childValues() as $child) {
        foreach (\is_array($child) ? $child : [$child] as $value) {
            if ($value instanceof Ast\AstWalkable) {
                desugarDoForScope($value);
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
function classModuleScopes(
    array $units,
    array $projectClasses,
    ?array $onlyModules,
    string $backendId,
): array {
    $wanted = $onlyModules !== null ? \array_fill_keys($onlyModules, true) : null;

    $referenced = [];
    foreach ($projectClasses as $classInfo) {
        $module = $classInfo['module'] ?? '';
        if ($module === '' || ($wanted !== null && ! isset($wanted[$module]))) {
            continue;
        }

        foreach ($classInfo['methods'] ?? [] as $methodInfo) {
            $body = $methodInfo['defaultBody'] ?? null;
            if ($body === null) {
                continue;
            }

            $scoped = Ast\copyNode($body);
            desugarDoForScope($scoped);
            $evidenceParameter = static fn (Ast\EvidenceMethod $method): ?string
                => $method->evidence === '' ? null : $method->evidence;

            foreach (IR\freeVarsExpr($scoped, [], $evidenceParameter) as $name) {
                $referenced[$module][$name] = true;
            }

            foreach (classScopeConstructorRefs($scoped) as $name => $_) {
                $referenced[$module][$name] = true;
            }
        }
    }

    $scopes = [];
    foreach ($referenced as $module => $names) {
        $env = $units[$module]['localTypes']['env'] ?? null;
        if (! \is_array($env)) {
            continue;
        }

        $origins = classModuleConstructorOrigins(
            $units,
            $module,
            classModuleValueOrigins($units, $module, $names),
            $names,
        );
        $fns = [];
        $dataRefs = [];
        foreach (\array_keys($names) as $name) {
            $scheme = $env[$name] ?? null;
            if (! $scheme instanceof Scheme || $scheme->classMethod) {
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

                continue;
            }

            $fns[$name] = [
                'origin' => $origin,
                'scheme' => $scheme,
            ];
        }

        $scopes[$module] = [
            'env' => $env,
            'fns' => $fns,
            'dataRefs' => $dataRefs,
            'visible' => classModuleVisibleTypes($units, $module, $backendId),
            'wrappers' => \is_array($units[$module]['localTypes']['intrinsicWrappers'] ?? null)
                ? $units[$module]['localTypes']['intrinsicWrappers']
                : [],
            'names' => $names,
            'path' => (string) ($units[$module]['path'] ?? ''),
        ];
    }

    return $scopes;
}

/**
 * Constructor names a default body mentions, as expression or as pattern.
 *
 * @return array<string, true>
 */
function classScopeConstructorRefs(Ast\AstWalkable $expr): array
{
    $names = [];
    if ($expr instanceof Ast\ConstructorRef) {
        $names[$expr->name] = true;
    } elseif ($expr instanceof Ast\PatCon) {
        $names[$expr->name] = true;
    } elseif ($expr instanceof Ast\OperatorRef && isConstructorName($expr->name)) {
        $names[$expr->name] = true;
    }

    foreach ($expr->childValues() as $child) {
        foreach (\is_array($child) ? $child : [$child] as $value) {
            if ($value instanceof Ast\AstWalkable) {
                foreach (classScopeConstructorRefs($value) as $name => $_) {
                    $names[$name] = true;
                }
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
 * Where a class module's own data constructors are declared.
 *
 * A default body may pattern match on a constructor of a type the class module
 * declares (`LT`, `EQ`, `GT` for `Ord`), and the re-check needs the type along
 * with the name -- {@see classModuleValueOrigins} covers functions only.
 *
 * @param array<string, array<string, mixed>> $units
 * @param array<string, array{module: string, namespace: string, phpName: string}> $origins
 * @param array<string, true> $names
 * @return array<string, array{module: string, namespace: string, phpName: string}>
 */
function classModuleConstructorOrigins(array $units, string $moduleName, array $origins, array $names): array
{
    $namespace = $units[$moduleName]['namespace'] ?? moduleNameToNamespace($moduleName);

    foreach ($units[$moduleName]['program']->items as $item) {
        if (! $item instanceof Ast\DataDecl) {
            continue;
        }

        foreach ($item->constructors as $constructor) {
            if (isset($names[$constructor->name])) {
                $origins[$constructor->name] ??= exportOrigin($moduleName, $namespace, $constructor->name);
            }
        }
    }

    return $origins;
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
                if (! isset($target['program'], $target['localTypes'])) {
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
    $program = $units[$moduleName]['program'] ?? null;
    if (! $program instanceof Ast\Program) {
        return [];
    }

    $names = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl || $item instanceof Ast\ForeignImportDecl) {
            $names[$item->name] = true;
        }
    }

    return $names;
}

/**
 * Data type names a module already has without the class-scope hand-over.
 *
 * The hand-over exists for the types a default body builds that only the
 * class's module can see (`Endo`, `Dual` in `Data.Foldable`). A type the target
 * module declares itself is not one of them: merging the boolean back into
 * `Data.Bool` makes `registerData` see a duplicate. The boolean is excluded for
 * every module because it is the machine's -- `Moggi.Internal.Prim` declares it,
 * and the kernel names it (`Kernel\boolTypeName`) -- and a module reaches it by
 * importing that module, which is the same import that puts a `case` on it in
 * scope.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array<string, true>
 */
function moduleLocalDataNames(array $units, string $moduleName): array
{
    $names = [boolTypeName() => true];
    $program = $units[$moduleName]['program'] ?? null;
    if (! $program instanceof Ast\Program) {
        return $names;
    }

    foreach ($program->items as $item) {
        if ($item instanceof Ast\DataDecl) {
            $names[$item->name] = true;
        }
    }

    return $names;
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
function classModuleVisibleTypes(array $units, string $moduleName, string $backendId): array
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
 * @return array{externalFns: array<string, string>, callables: array<string, array{evidence: int, params: int, arity: int}>, dataRefs: array<string, array<string, mixed>>, origins: array<string, string>, actionReturn: array<string, true>, workers: array<string, string>}
 */
function classScopeFunctionRefs(array $units, array $classScopes): array
{
    $externalFns = [];
    $callables = [];
    $dataRefs = [];
    $origins = [];
    $actionReturn = [];
    $workers = [];
    foreach ($classScopes as $scope) {
        foreach ($scope['fns'] ?? [] as $name => $fn) {
            $origin = $fn['origin'];
            $externalFns[$name] = resolvedSymbol($origin['module'], $origin['phpName']);
            $callables[$name] = publishedCallableLayout($units, $origin['module'], $origin['phpName']);
            $origins[$name] = $origin['module'];
            if (isset(moduleActionReturnFnNames($units, $origin['module'])[$origin['phpName']])) {
                $actionReturn[$name] = true;
            }
            $worker = importedWorkerSymbol($units, $origin);
            if ($worker !== null) {
                $workers[$name] = $worker;
            }
        }

        foreach ($scope['dataRefs'] ?? [] as $typeName => $info) {
            $dataRefs[$typeName] ??= $info;
        }
    }

    return ['externalFns' => $externalFns, 'callables' => $callables, 'dataRefs' => $dataRefs, 'origins' => $origins, 'actionReturn' => $actionReturn, 'workers' => $workers];
}

/**
 * Fold class-scope symbol and data references into a module's interface.
 *
 * Nothing is re-rendered here: the target plan is rendered from the interface
 * when the module is emitted, so a fact published after the import walk reaches
 * the emitter without a second view of the interface being kept in step.
 *
 * @param array{externalFns: array<string, string>, callables: array<string, array{evidence: int, params: int, arity: int}>, dataRefs: array<string, array<string, mixed>>, origins?: array<string, string>, actionReturn?: array<string, true>} $refs
 * @param array<string, true> $localNames names the module binds itself
 */
function mergeClassScopeRefs(
    ImportContext $importContext,
    array $refs,
    array $localNames = [],
    array $seenData = [],
): ImportContext {
    $interface = $importContext->interface->withClassScopeRefs($refs, $localNames);

    $dataRefs = [];
    foreach ($refs['dataRefs'] ?? [] as $typeName => $info) {
        if (! isset($seenData[$typeName])) {
            $dataRefs[$typeName] = $info;
        }
    }

    return $importContext->withInterface(
        $dataRefs === []
            ? $interface
            : $interface->withExtraTypes(['data' => $dataRefs]),
    );
}

/**
 * The modules a project-wide scan runs over, in one canonical order.
 *
 * The scans below (class declarations, instance records) feed the checked
 * product's instance world, so their order must not depend on the route that
 * prepared the units — a full project compile, a single-file compile's focus
 * base and the language server all reach here with different insertion orders.
 *
 * @param array<string, array<string, mixed>> $units
 * @param list<string>|null $onlyModules
 * @return list<string>
 */
function canonicalModuleOrder(array $units, ?array $onlyModules = null): array
{
    $names = \array_values(\array_unique(\array_map(
        static fn (mixed $name): string => (string) $name,
        $onlyModules ?? \array_keys($units),
    )));
    \sort($names);

    return $names;
}

/** @param array<string, array<string, mixed>> $units @param list<string>|null $onlyModules @return list<array{module: string, class: string, head: array<string, mixed>}> */
/**
 * Every class declaration the units parsed, for the strategy facts the instance
 * stub phase needs.
 *
 * A class's own declaration is where its parameter's kind and its associated
 * types are written, and that phase never runs a type check to answer either
 * question another way. The scan is the whole project rather than
 * `$onlyModules`: restricting it could only lose the declaration a stub is
 * asking about.
 *
 * @param array<string, array<string, mixed>> $units
 * @return list<Ast\ClassDecl>
 */
function projectClassDeclarations(array $units): array
{
    $declarations = [];
    foreach (canonicalModuleOrder($units) as $moduleName) {
        $program = $units[$moduleName]['program'] ?? null;
        if (! $program instanceof Ast\Program) {
            continue;
        }
        foreach ($program->items as $item) {
            if ($item instanceof Ast\ClassDecl) {
                $declarations[] = $item;
            }
        }
    }

    return $declarations;
}

/**
 * Every data/newtype declaration the units parsed, keyed by type constructor name.
 *
 * A standalone `deriving instance` may name a type another module declares, so the answer it
 * needs is the project's, not the declaring unit's. The first declaration of a name wins: two
 * modules cannot both declare it, and preparation has already refused that.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array<string, Ast\DataDecl>
 */
function projectDataDeclarations(array $units): array
{
    $declarations = [];
    foreach (canonicalModuleOrder($units) as $moduleName) {
        $program = $units[$moduleName]['program'] ?? null;
        if (! $program instanceof Ast\Program) {
            continue;
        }
        foreach ($program->items as $item) {
            if ($item instanceof Ast\DataDecl) {
                $declarations[$item->name] ??= $item;
            }
        }
    }

    return $declarations;
}

/**
 * The instance records the project's units declare, in canonical module order.
 *
 * {@see projectInstancesFromProgram()} is the only place a record is shaped, so
 * a fact added to one route's records cannot be missing from another's and give
 * the same modules two identities.
 *
 * A named module whose unit preparation did not parse is refused rather than
 * skipped: the world's identity names the module set it was collected over, so
 * a module that contributed no records would claim coverage it does not have,
 * and that is indistinguishable from a route that legitimately sees fewer
 * modules.
 *
 * @param array<string, array<string, mixed>> $units
 * @param list<string>|null $onlyModules
 * @return list<array<string, mixed>>
 */
function collectProjectInstances(array $units, ?array $onlyModules = null): array
{
    $facts = ClassDeriveFacts::fromClassDeclarations(projectClassDeclarations($units));
    $dataDecls = projectDataDeclarations($units);

    $instances = [];
    foreach (canonicalModuleOrder($units, $onlyModules) as $moduleName) {
        $unit = $units[$moduleName] ?? null;
        if ($unit === null || ($unit['parsed'] ?? false) !== true) {
            throw new \LogicException(
                "the instance world was asked for `{$moduleName}`, which preparation did not parse",
            );
        }

        foreach (projectInstancesFromProgram($unit['program'], $facts, $moduleName, $dataDecls) as $record) {
            $instances[] = $record;
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
        $headKey = Types\instanceHeadIndexKeyFromAst($instance['instanceHead']);

        if (($instance['fromDeriving'] ?? false) === true && $instance['instanceHead'] instanceof Ast\AstNode) {
            $headText = $className . "\0" . Ast\dumpTypeInline($instance['instanceHead']);
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
 * @param array<string, array<string, mixed>> $units
 * @param array<string, Scheme> $schemes
 */
function syncExportedInferredSchemesFromTypeEnv(
    array &$units,
    string $moduleName,
    array $schemes,
): void {
    if (! isset($units[$moduleName]['localTypes']) || $schemes === []) {
        return;
    }

    foreach ($schemes as $name => $scheme) {
        $units[$moduleName]['localTypes']['env'][$name] = $scheme;
        if (isset($units[$moduleName]['exports']['env'][$name])) {
            $units[$moduleName]['exports']['env'][$name] = $scheme;
        }
    }
}

