<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Modules;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TChar;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TInt;
use Moggi\Semantics\TypeExpr\TInt16;
use Moggi\Semantics\TypeExpr\TInt32;
use Moggi\Semantics\TypeExpr\TInt64;
use Moggi\Semantics\TypeExpr\TInt8;
use Moggi\Semantics\TypeExpr\TNatLit;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TStr;
use Moggi\Semantics\TypeExpr\TStringLit;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\TWord;
use Moggi\Semantics\TypeExpr\TWord16;
use Moggi\Semantics\TypeExpr\TWord32;
use Moggi\Semantics\TypeExpr\TWord64;
use Moggi\Semantics\TypeExpr\TWord8;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Ast\TypeNode;

use function Moggi\Semantics\IntrinsicRegistry\isIoIntrinsic;
use function Moggi\Semantics\IntrinsicRegistry\isStIntrinsic;
use function Moggi\Semantics\TypeExpr\collectTypeVars;
use function Moggi\Semantics\TypeExpr\scheme;

require_once __DIR__ . '/type_core.php';
require_once __DIR__ . '/constraints.php';

/**
 * Scope a class's default bodies were written in.
 *
 * A class module being checked is its own scope: its interface is the live env
 * (the interface is published by the check, so `classModuleScopes` has no entry
 * for it yet).
 *
 * @param array<string, mixed> $classInfo
 * @return array<string, Scheme>
 */
function defaultBodyScope(TypeCheckState $state, array $classInfo): array
{
    $module = $classInfo['module'] ?? '';
    if ($module === '') {
        return [];
    }

    if ($module === $state->currentModule) {
        return $state->env;
    }

    $scope = $state->classModuleScopes[$module] ?? null;

    return \is_array($scope['env'] ?? null) ? $scope['env'] : [];
}

/**
 * The type constructors the class module's default bodies may name.
 *
 * A default body is re-checked here, in the instance's module, so a type the
 * class module imported (`Endo`, `Dual`) is not in this module's type tables.
 * {@see installDefaultBodyTypes} installs them for the check, along with the
 * primop wrappers the class module's scope gave its operators and the values
 * and data types the bodies name (`True` in the `Ord` defaults is one). No such
 * install is needed when the class's own module is the one being checked -- the
 * types, the values and the wrappers are already in scope there.
 *
 * @param array<string, mixed> $classInfo
 * @return array{
 *   types: array<string, int|null>,
 *   synonyms: array<string, mixed>,
 *   wrappers: array<string, string>,
 *   env: array<string, Scheme>,
 *   data: array<string, mixed>
 * }
 */
function defaultBodyVisibleTypes(TypeCheckState $state, array $classInfo): array
{
    $empty = ['types' => [], 'synonyms' => [], 'wrappers' => [], 'env' => [], 'data' => []];

    $module = $classInfo['module'] ?? '';
    if ($module === $state->currentModule) {
        return $empty;
    }

    $scope = $module === '' ? null : ($state->classModuleScopes[$module] ?? null);
    $visible = \is_array($scope) ? ($scope['visible'] ?? null) : null;
    if (! \is_array($visible)) {
        return $empty;
    }

    $wrappers = \is_array($scope['wrappers'] ?? null) ? $scope['wrappers'] : [];

    $moduleEnv = \is_array($scope['env'] ?? null) ? $scope['env'] : [];
    $env = [];
    foreach (\array_keys(\is_array($scope['names'] ?? null) ? $scope['names'] : []) as $name) {
        $scheme = $moduleEnv[$name] ?? null;
        if ($scheme instanceof Scheme && ! $scheme->classMethod) {
            $env[$name] = $scheme;
        }
    }

    return [
        'types' => \is_array($visible['types'] ?? null) ? $visible['types'] : [],
        'synonyms' => \is_array($visible['synonyms'] ?? null) ? $visible['synonyms'] : [],
        'wrappers' => $wrappers,
        'env' => $env,
        'data' => \is_array($scope['dataRefs'] ?? null) ? $scope['dataRefs'] : [],
    ];
}

/**
 * Make the class module's visible type constructors resolvable, and return what
 * has to be undone afterwards.
 *
 * @param array{types: array<string, int|null>, synonyms: array<string, mixed>, env?: array<string, Scheme>, data?: array<string, mixed>} $visible
 * @return array{names: array<string, true>, kinds: list<string>, synonyms: list<string>, synonymKinds: list<string>, wrappers: list<string>, env: list<string>, data: list<string>, promoted: list<string>}
 */
function installDefaultBodyTypes(TypeCheckState $state, array $visible): array
{
    $added = ['names' => [], 'kinds' => [], 'synonyms' => [], 'wrappers' => [], 'env' => [], 'data' => [], 'promoted' => []];

    foreach ($visible['env'] ?? [] as $name => $scheme) {
        if (isset($state->env[$name])) {
            continue;
        }

        $state->env[$name] = $scheme;
        $added['env'][] = $name;
    }

    foreach ($visible['data'] ?? [] as $typeName => $info) {
        if (isset($state->data[$typeName])) {
            continue;
        }

        $state->data[$typeName] = $info;
        $added['data'][] = $typeName;
        if (! isset($state->kindEnv[Kinds\canonicalTypeConName($typeName)])) {
            Kinds\registerTypeKind($state, $typeName, Kinds\kindFromDataInfo($info));
            $added['kinds'][] = $typeName;
        }

        $promotedBefore = $state->promoted;
        Kinds\installPromotedFromDataInfo($state, $typeName, $info);
        foreach ($state->promoted as $ctorName => $_) {
            if (! isset($promotedBefore[$ctorName])) {
                $added['promoted'][] = $ctorName;
            }
        }
    }

    foreach ($visible['wrappers'] ?? [] as $name => $primop) {
        if (isset($state->intrinsicWrappers[$name])) {
            continue;
        }

        $state->intrinsicWrappers[$name] = $primop;
        $added['wrappers'][] = $name;
    }

    foreach ($visible['types'] as $name => $params) {
        if (isset($state->declaredTypeNames[$name])) {
            continue;
        }

        $state->declaredTypeNames[$name] = true;
        $added['names'][$name] = true;
        if ($params !== null && ! isset($state->kindEnv[Kinds\canonicalTypeConName($name)])) {
            Kinds\registerTypeKind($state, $name, Kinds\dataKind($params));
            $added['kinds'][] = $name;
        }
    }

    foreach ($visible['synonyms'] as $name => $info) {
        if (isset($state->typeSynonyms[$name])) {
            continue;
        }

        $state->typeSynonyms[$name] = $info;
        if (installTypeSynonymKind($state, $name, $info)) {
            $added['synonymKinds'][] = $name;
        }
        $added['names'][$name] = true;
        $added['synonyms'][] = $name;
    }

    return $added;
}

/** @param array{names: array<string, true>, kinds: list<string>, synonyms: list<string>, synonymKinds: list<string>, wrappers: list<string>, env: list<string>, data: list<string>, promoted: list<string>} $added */
function removeDefaultBodyTypes(TypeCheckState $state, array $added): void
{
    foreach ($added['kinds'] as $name) {
        unset($state->kindEnv[Kinds\canonicalTypeConName($name)]);
    }

    foreach ($added['synonyms'] as $name) {
        unset($state->typeSynonyms[$name]);
    }

    foreach ($added['synonymKinds'] ?? [] as $name) {
        unset($state->kindEnv[Kinds\canonicalTypeConName($name)]);
    }

    foreach ($added['wrappers'] ?? [] as $name) {
        unset($state->intrinsicWrappers[$name]);
    }

    foreach ($added['env'] ?? [] as $name) {
        unset($state->env[$name]);
    }

    foreach ($added['data'] ?? [] as $typeName) {
        unset($state->data[$typeName]);
    }

    foreach ($added['promoted'] ?? [] as $ctorName) {
        unset($state->promoted[$ctorName]);
    }

    foreach ($added['names'] as $name => $_) {
        unset($state->declaredTypeNames[$name]);
    }
}

function instanceMethodSchemes(TypeCheckState $state, Ast\InstanceDecl $decl): array
{
    $className = $decl->class;
    $classInfo = $state->classes[$className] ?? null;
    if ($classInfo === null) {
        throw typeFail($state, "unknown class `{$className}`", $decl);
    }

    foreach ($decl->constraints as $constraint) {
        if (!$constraint instanceof Ast\TypeApp || !$constraint->con instanceof Ast\TypeCon) {
            continue;
        }
        $contextInfo = $state->classes[$constraint->con->name] ?? null;
        if ($contextInfo === null) {
            continue;
        }
        foreach ($contextInfo['params'] as $j => $param) {
            $argAst = $constraint->args[$j] ?? null;
            if ($argAst instanceof Ast\TypeVar) {
                $state->varKinds[$argAst->name] = Kinds\classParamKind($state, $param);
            }
        }
    }

    $headType = freshenTypeVars($state, astType($state, $decl->head));
    $mapping = instanceParamMapping($state, $classInfo['params'], $headType, $decl);

    $schemes = [];
    $provided = [];
    foreach ($decl->methods as $method) {
        if (isset($provided[$method->name])) {
            throw typeFail($state, "duplicate method `{$method->name}` in instance for `{$className}`", $method);
        }

        $methodInfo = $classInfo['methods'][$method->name] ?? null;
        if ($methodInfo === null) {
            throw typeFail($state, "class `{$className}` has no method `{$method->name}`", $method);
        }

        $substitutedConstraints = \array_map(
            static function (Ast\PendingConstraint $constraint) use ($mapping): Ast\PendingConstraint {
                return new Ast\PendingConstraint(
                    $constraint->class,
                    \array_map(
                        static fn (Type $arg): Type => substitute($arg, $mapping),
                        $constraint->args,
                    ),
                    $constraint->evidence,
                    $constraint->implicit,
                    $constraint->instanceHeadAst,
                );
            },
            $methodInfo['userConstraints'] ?? [],
        );
        $freshened = freshenTypeWithConstraints(
            $state,
            substitute($methodInfo['type'], $mapping),
            $substitutedConstraints,
        );
        $peeled = peelDictArrows($freshened['type'], \count($freshened['constraints']));
        $methodDicts = expandConstraintsWithSuperclasses($state, $freshened['constraints']);
        $schemes[$method->name] = scheme(
            prune($state, $peeled),
            [],
            $freshened['constraints'],
            \count($methodDicts),
            $methodDicts,
        );
        $provided[$method->name] = true;
    }

    foreach ($classInfo['methods'] as $methodName => $methodInfo) {
        if (isset($provided[$methodName]) || ($methodInfo['defaultBody'] ?? null) === null) {
            continue;
        }

        $expectedType = freshenTypeVars($state, substitute($methodInfo['type'], $mapping));
        $schemes[$methodName] = scheme(prune($state, $expectedType), []);
        $provided[$methodName] = true;
    }

    return [
        'headType' => $headType,
        'mapping' => $mapping,
        'schemes' => $schemes,
        'provided' => $provided,
    ];
}

/**
 * @return list<Ast\FunctionDecl>
 */

function checkInstance(TypeCheckState $state, Ast\InstanceDecl $decl): array
{
    $state->subst = [];
    $registration = instanceMethodSchemes($state, $decl);
    $headType = $registration['headType'];
    $mapping = $registration['mapping'];
    $state->varKinds = [...$state->varKinds, ...Kinds\typeKindRequirements($state, $headType)['vars']];
    validateInstance($state, $decl, $headType);
    assertCoercionInstanceAllowed($state, $decl);

    $className = $decl->class;
    $classInfo = $state->classes[$className];
    $functions = [];
    $contextConstraints = instanceContextConstraints($state, $decl, $headType);
    $instanceConstraints = expandConstraintsWithSuperclasses($state, $contextConstraints);
    $constraintEnv = $state->stockDeriving
        ? []
        : buildInstanceConstraintEnv($state, $decl->constraints, $headType);
    $declaredMethodNames = [];
    foreach ($decl->methods as $declaredMethod) {
        $declaredMethodNames[$declaredMethod->name] = true;
    }
    $instanceMethods = $decl->methods;
    $defaultedMethods = [];
    foreach ($classInfo['methods'] as $defaultName => $defaultInfo) {
        if (isset($declaredMethodNames[$defaultName]) || ($defaultInfo['defaultBody'] ?? null) === null) {
            continue;
        }
        $instanceMethods[] = new Ast\FunctionDecl(
            $defaultName,
            null,
            \array_map(Ast\copyNode(...), $defaultInfo['defaultParams'] ?? []),
            Ast\copyNode($defaultInfo['defaultBody']),
            instanceMethod: true,
        );
        $defaultedMethods[$defaultName] = true;
    }

    $keepHeadVars = typeVars($headType);
    foreach ($instanceMethods as $method) {
        $methodInfo = $classInfo['methods'][$method->name];
        $substitutedConstraints = \array_map(
            static function (Ast\PendingConstraint $constraint) use ($mapping): Ast\PendingConstraint {
                return new Ast\PendingConstraint(
                    $constraint->class,
                    \array_map(
                        static fn (Type $arg): Type => substitute($arg, $mapping),
                        $constraint->args,
                    ),
                    $constraint->evidence,
                    $constraint->implicit,
                    $constraint->instanceHeadAst,
                );
            },
            $methodInfo['userConstraints'] ?? [],
        );
        $freshened = freshenTypeWithConstraintsKeeping(
            $state,
            substitute($methodInfo['type'], $mapping),
            $substitutedConstraints,
            $keepHeadVars,
        );
        [$etaParams, $etaBody] = etaExpandInstanceMethod(
            $method->params,
            $method->body,
            peelDictArrows($freshened['type'], \count($freshened['constraints'])),
        );
        $defaulted = isset($defaultedMethods[$method->name]);
        $fn = new Ast\FunctionDecl(
            $method->name,
            null,
            $etaParams,
            $etaBody,
            instanceMethod: true,
        );
        if ($method->doc !== null) {
            $fn->doc = $method->doc;
        }
        $defaultModule = (string) ($classInfo['module'] ?? '');
        $defaultLine = (int) ($methodInfo['line'] ?? 0);
        if ($defaulted && $defaultLine > 0) {
            $written = $methodInfo['defaultBody'] ?? null;
            $siteModule = $defaultModule !== '' ? $defaultModule : (string) $state->currentModule;
            $siteFile = $written?->sourceFile
                ?? ($state->classModuleScopes[$defaultModule]['path'] ?? null);
            $siteLine = $defaultLine;
            $siteCol = (int) ($methodInfo['col'] ?? 0);
        } else {
            $written = $method->line > 0 ? $method : $decl;
            $siteModule = (string) $state->currentModule;
            $siteFile = $written->sourceFile;
            $siteLine = $written->line;
            $siteCol = $written->col;
        }
        $site = Modules\sourceSite($siteModule, $siteFile ?? $state->filename, $siteLine, $siteCol);
        if ($site !== null) {
            $fn->sourceOrigin = $site;
        }
        $scope = $defaulted ? defaultBodyScope($state, $classInfo) : [];
        $defaultOrigins = [];
        if ($defaulted) {
            $classScope = $defaultModule === $state->currentModule
                ? null
                : ($state->classModuleScopes[$defaultModule] ?? null);
            $classFns = \is_array($classScope['fns'] ?? null) ? $classScope['fns'] : [];
            foreach ($scope as $name => $scheme) {
                if (! $scheme instanceof Scheme || $scheme->classMethod || isset($constraintEnv[$name])) {
                    continue;
                }
                $origin = $classFns[$name]['origin'] ?? null;
                if (\is_array($origin)) {
                    $defaultOrigins[$name] = $origin['module'] . '::' . $origin['phpName'];
                } elseif ($defaultModule === $state->currentModule) {
                    $defaultOrigins[$name] = $state->externalFns[$name]
                        ?? $defaultModule . '::' . $name;
                }
            }
        }
        $savedDefaultOrigins = $state->defaultBodyOrigins;
        $state->defaultBodyOrigins = $defaultOrigins;
        $installedTypes = $defaulted
            ? installDefaultBodyTypes($state, defaultBodyVisibleTypes($state, $classInfo))
            : ['names' => [], 'kinds' => [], 'synonyms' => [], 'wrappers' => [], 'env' => [], 'data' => [], 'promoted' => []];
        $methodEnv = $scope === [] ? $constraintEnv : [...$scope, ...$constraintEnv];

        try {
            $fn = checkInstanceMethod(
                $state,
                $fn,
                $freshened['type'],
                $methodEnv,
                $freshened['constraints'],
                $contextConstraints,
                $className,
                $headType,
                $methodInfo['kinds'] ?? [],
                $mapping + ($freshened['mapping'] ?? []),
            );
        } finally {
            $state->defaultBodyOrigins = $savedDefaultOrigins;
            removeDefaultBodyTypes($state, $installedTypes);
        }
        $state->instanceMethodNames[$fn->name] = true;
        $existing = $state->env[$fn->name] ?? null;
        if (! ($existing?->classMethod)) {
            $state->env[$fn->name] = $registration['schemes'][$method->name];
        }
        $functions[] = $fn;
    }

    $providedMethods = [];
    foreach ($functions as $fn) {
        $providedMethods[$fn->name] = true;
    }

    foreach ($classInfo['methods'] as $methodName => $_) {
        if (! isset($providedMethods[$methodName])) {
            throw typeFail(
                $state,
                "instance for `{$className}` missing method `{$methodName}`",
                $decl,
            );
        }
    }

    $minimalGroups = $classInfo['minimalGroups'] ?? [];
    $satisfied = false;
    foreach ($minimalGroups as $group) {
        $complete = true;
        foreach ($group as $methodName) {
            if (! isset($declaredMethodNames[$methodName])) {
                $complete = false;

                break;
            }
        }
        if ($complete) {
            $satisfied = true;

            break;
        }
    }

    if ($minimalGroups !== [] && ! $satisfied) {
        throw typeFail(
            $state,
            "instance for `{$className}` implements none of the alternatives its "
            . 'MINIMAL pragma requires: '
            . \implode(' | ', \array_map(static fn (array $group): string => \implode(', ', $group), $minimalGroups)),
            $decl,
        );
    }

    $state->checkedInstances[] = [
        'class' => $className,
        'instanceHead' => $headType,
        'module' => $state->currentModule ?? '',
    ];

    $headAst = internalTypeToAst($headType, []);
    $evidenceIndex = \count($state->instanceEvidence);
    $methodMap = [];
    foreach ($functions as $fn) {
        if ($fn->instanceMethod) {
            $methodMap[$fn->name] = $fn->name;
        }
    }
    $evidenceHeadAst = $decl->head instanceof TypeNode
        ? $decl->head
        : $headAst;
    $evName = evidenceFunctionName($className, $evidenceHeadAst);
    $state->instanceEvidence[] = [
        'evidenceName' => $evName,
        'methods' => $methodMap,
        'headAst' => $headAst,
        'className' => $className,
        'contextParams' => \array_map(
            static fn (Ast\PendingConstraint $c): string => $c->evidence,
            dictionaryConstraints($instanceConstraints),
        ),
    ];

    foreach ($functions as $fn) {
        $state->instanceMethodsPendingUniq[] = [
            'fn' => $fn,
            'evidenceIndex' => $evidenceIndex,
            'surfaceName' => $fn->name,
        ];
    }

    return $functions;
}

/**
 * When multiple instances in one module define the same surface method name
 * (Eq Handle and Eq IOMode both define `==`), or an instance method collides
 * with a free function (`Data.Map.map` vs `Functor.map`), give each instance
 * method a unique IR name and point the evidence dictionary at those IR names.
 *
 * @param array<string, true> $freeFnNames
 */
function uniquifyCollidingInstanceMethods(TypeCheckState $state, array $freeFnNames = []): void
{
    $bySurface = [];
    foreach ($state->instanceMethodsPendingUniq as $entry) {
        $bySurface[$entry['surfaceName']][] = $entry;
    }

    $renamesByEvidence = [];
    foreach ($bySurface as $surfaceName => $entries) {
        $collidesWithFree = isset($freeFnNames[$surfaceName]);
        if (\count($entries) <= 1 && ! $collidesWithFree) {
            continue;
        }

        foreach ($entries as $entry) {
            $evidence = $state->instanceEvidence[$entry['evidenceIndex']];
            $headAst = $evidence['headAst'] ?? null;
            $className = $evidence['className'] ?? null;
            if (! $headAst instanceof Ast\TypeNode || ! \is_string($className)) {
                throw new \RuntimeException('instance evidence missing headAst/className for uniquify');
            }

            $renamesByEvidence[$entry['evidenceIndex']][$surfaceName] = instanceMethodIrName(
                $className,
                $headAst,
                $surfaceName,
            );
        }
    }

    foreach ($state->instanceMethodsPendingUniq as $entry) {
        $surfaceToIr = $renamesByEvidence[$entry['evidenceIndex']] ?? [];
        if ($surfaceToIr === [] || ! isset($surfaceToIr[$entry['surfaceName']])) {
            continue;
        }

        $fn = $entry['fn'];
        $fn->body = renameInstanceMethodRefs($fn->body, $surfaceToIr, patternBoundNames($fn->params));
        $fn->name = $surfaceToIr[$entry['surfaceName']];
        $state->instanceEvidence[$entry['evidenceIndex']]['methods'][$entry['surfaceName']] = $fn->name;
    }

    foreach ($state->instanceEvidence as &$evidence) {
        unset($evidence['headAst'], $evidence['className']);
    }
    unset($evidence);
}

/**
 * @param array<string, string> $surfaceToIr
 * @param array<string, true> $bound
 */
function renameInstanceMethodRefs(Ast\AstNode $expr, array $surfaceToIr, array $bound): Ast\AstNode
{
    return match ($expr::class) {
        Ast\Variable::class => (function () use ($expr, $surfaceToIr, $bound): Ast\AstNode {
            if (isset($surfaceToIr[$expr->name]) && ! isset($bound[$expr->name])) {
                $expr->name = $surfaceToIr[$expr->name];
            }

            return $expr;
        })(),
        Ast\Lambda::class => (function () use ($expr, $surfaceToIr, $bound): Ast\AstNode {
            $lambdaBound = $bound;
            foreach ($expr->params as $param) {
                foreach (patternBoundNames([$param->pattern]) as $name => $_) {
                    $lambdaBound[$name] = true;
                }
            }
            $expr->body = renameInstanceMethodRefs($expr->body, $surfaceToIr, $lambdaBound);

            return $expr;
        })(),
        Ast\Let::class => (function () use ($expr, $surfaceToIr, $bound): Ast\AstNode {
            $letBound = $bound;
            foreach ($expr->bindings as $binding) {
                foreach (patternBoundNames([$binding->pattern]) as $name => $_) {
                    $letBound[$name] = true;
                }
                $binding->value = renameInstanceMethodRefs($binding->value, $surfaceToIr, $bound);
            }
            $expr->body = renameInstanceMethodRefs($expr->body, $surfaceToIr, $letBound);

            return $expr;
        })(),
        Ast\Apply::class => (function () use ($expr, $surfaceToIr, $bound): Ast\AstNode {
            $expr->function = renameInstanceMethodRefs($expr->function, $surfaceToIr, $bound);
            $expr->argument = renameInstanceMethodRefs($expr->argument, $surfaceToIr, $bound);

            return $expr;
        })(),
        Ast\Infix::class => (function () use ($expr, $surfaceToIr, $bound): Ast\AstNode {
            $expr->left = renameInstanceMethodRefs($expr->left, $surfaceToIr, $bound);
            $expr->right = renameInstanceMethodRefs($expr->right, $surfaceToIr, $bound);

            return $expr;
        })(),
        Ast\CaseExpr::class => (function () use ($expr, $surfaceToIr, $bound): Ast\AstNode {
            $expr->scrutinee = renameInstanceMethodRefs($expr->scrutinee, $surfaceToIr, $bound);
            foreach ($expr->alts as $alt) {
                $altBound = $bound;
                foreach (patternBoundNames([$alt->pattern]) as $name => $_) {
                    $altBound[$name] = true;
                }
                $alt->body = renameInstanceMethodRefs($alt->body, $surfaceToIr, $altBound);
            }

            return $expr;
        })(),
        Ast\IntrinsicCall::class => (function () use ($expr, $surfaceToIr, $bound): Ast\AstNode {
            $expr->args = \array_map(
                static fn (Ast\AstNode $arg): Ast\AstNode => renameInstanceMethodRefs($arg, $surfaceToIr, $bound),
                $expr->args,
            );

            return $expr;
        })(),
        Ast\IoAction::class => (function () use ($expr, $surfaceToIr, $bound): Ast\AstNode {
            $expr->expr = renameInstanceMethodRefs($expr->expr, $surfaceToIr, $bound);

            return $expr;
        })(),
        Ast\Tuple::class,
        Ast\ListLit::class => (function () use ($expr, $surfaceToIr, $bound): Ast\AstNode {
            $expr->elements = \array_map(
                static fn (Ast\AstNode $el): Ast\AstNode => renameInstanceMethodRefs($el, $surfaceToIr, $bound),
                $expr->elements,
            );

            return $expr;
        })(),
        default => $expr,
    };
}

/**
 * @param array<int, Ast\AstNode> $patterns
 * @return array<string, true>
 */
function patternBoundNames(array $patterns): array
{
    $bound = [];
    foreach ($patterns as $pattern) {
        collectPatternBoundNames($pattern, $bound);
    }

    return $bound;
}

/** @param array<string, true> $bound */
function collectPatternBoundNames(Ast\AstNode $pattern, array &$bound): void
{
    match ($pattern::class) {
        Ast\PatVar::class => $bound[$pattern->name] = true,
        Ast\PatCon::class => (function () use ($pattern, &$bound): void {
            foreach ($pattern->args as $arg) {
                if ($arg instanceof Ast\AstNode) {
                    collectPatternBoundNames($arg, $bound);
                }
            }
        })(),
        Ast\PatCons::class => (function () use ($pattern, &$bound): void {
            collectPatternBoundNames($pattern->head, $bound);
            collectPatternBoundNames($pattern->tail, $bound);
        })(),
        Ast\PatTuple::class => (function () use ($pattern, &$bound): void {
            foreach ($pattern->elements as $element) {
                collectPatternBoundNames($element, $bound);
            }
        })(),
        Ast\PatRecord::class => (function () use ($pattern, &$bound): void {
            foreach ($pattern->fields as $field) {
                collectPatternBoundNames($field->pattern, $bound);
            }
        })(),
        default => null,
    };
}

function validateInstance(TypeCheckState $state, Ast\InstanceDecl $decl, Type $headType): void
{
    validateInstanceSuperclasses($state, $decl);
    validateNoDuplicateInstance($state, $decl, $headType);
    validateAssociatedTypeEquations($state, $decl);
}

/**
 * Every associated type declared on the class must have exactly one equation
 * in the instance; reject unknown / duplicate equation names.
 */
function validateAssociatedTypeEquations(TypeCheckState $state, Ast\InstanceDecl $decl): void
{
    $classInfo = $state->classes[$decl->class] ?? null;
    if ($classInfo === null) {
        return;
    }

    $required = $classInfo['associatedTypes'] ?? [];
    $seen = [];
    foreach ($decl->associatedEquations as $eq) {
        if (! isset($required[$eq->name])) {
            throw typeFail(
                $state,
                "class `{$decl->class}` has no associated type `{$eq->name}`",
                $eq,
            );
        }
        if (isset($seen[$eq->name])) {
            throw typeFail(
                $state,
                "duplicate associated type equation `{$eq->name}` in instance for `{$decl->class}`",
                $eq,
            );
        }
        $seen[$eq->name] = true;

        $arity = \count($required[$eq->name]['params']);
        if (\count($eq->lhsArgs) !== $arity) {
            throw typeFail(
                $state,
                "associated type `{$eq->name}` expects {$arity} argument(s), got "
                    . \count($eq->lhsArgs),
                $eq,
            );
        }
    }

    foreach ($required as $famName => $_) {
        if (! isset($seen[$famName])) {
            throw typeFail(
                $state,
                "instance for `{$decl->class}` missing associated type `{$famName}`",
                $decl,
            );
        }
    }
}

function validateInstanceSuperclasses(TypeCheckState $state, Ast\InstanceDecl $decl): void
{
    $classInfo = $state->classes[$decl->class] ?? null;
    if ($classInfo === null) {
        return;
    }

    $mapping = instanceParamMapping($state, $classInfo['params'], astType($state, $decl->head), $decl);

    foreach ($classInfo['superclasses'] as $super) {
        $implication = implicationSuperclass($state, $super, $mapping, $decl);
        if ($implication !== null) {
            [$head, $givens] = $implication;
            $headInfo = $state->classes[$head->class]
                ?? throw typeFail($state, "unknown superclass `{$head->class}`", $decl);
            $headType = instanceHeadFromConstraintArgs($state, $headInfo['params'], $head->args, $decl);

            if (peerInstanceCoversGiven($state, $head->class, $headType, $givens, $decl)) {
                continue;
            }

            throw typeFail(
                $state,
                "missing superclass instance: `{$head->class} "
                    . typeToString($headType, friendlyTypeVarNames([$headType])) . '`',
                $decl,
            );
        }

        if (superclassName($super) === null) {
            throw typeFail($state, 'class superclass must be a type class application', $decl);
        }

        if ($super instanceof Ast\TypeForall) {
            $implication = implicationSuperclass($state, $super, $mapping, $decl);
            $contextGivens = [];
            if ($implication !== null) {
                [$superHeadConstraint, $contextConstraints] = $implication;
                $constraint = $superHeadConstraint;
                foreach ($contextConstraints as $contextGiven) {
                    $contextGivens[] = ['class' => $contextGiven->class, 'args' => $contextGiven->args];
                }
            } else {
                $constraint = constraintFromSuperclassAst($state, $super, $mapping);
            }

            $quantifiedInfo = $state->classes[$constraint->class]
                ?? throw typeFail($state, "unknown superclass `{$constraint->class}`", $decl);
            $quantifiedHead = instanceHeadFromConstraintArgs($state, $quantifiedInfo['params'], $constraint->args, $decl);

            if (peerInstanceCoversSuperclass($state, $constraint->class, $quantifiedHead, $decl, null, $contextGivens)) {
                continue;
            }

            if (! findProjectInstance($state, $constraint->class, $quantifiedHead)) {
                throw typeFail(
                    $state,
                    "missing superclass instance: `{$constraint->class} "
                        . typeToString($quantifiedHead, friendlyTypeVarNames([$quantifiedHead])) . '`',
                    $decl,
                );
            }

            continue;
        }

        $superClass = $super->con->name;
        $superClassInfo = $state->classes[$superClass]
            ?? throw typeFail($state, "unknown superclass `{$superClass}`", $decl);

        if ($superClassInfo['params'] === []) {
            if (! findProjectInstance($state, $superClass, new TUnit())) {
                throw typeFail($state, "missing superclass instance: `{$superClass}`", $decl);
            }

            continue;
        }

        $superHead = null;
        foreach ($superClassInfo['params'] as $i => $param) {
            $arg = $super->args[$i] ?? throw typeFail($state, "class `{$superClass}` applied to too few arguments", $decl);
            if ($arg instanceof Ast\TypeVar && isset($mapping[$arg->name])) {
                $superHead = $mapping[$arg->name];

                break;
            }
        }

        if ($superHead === null) {
            $superHead = astType($state, substituteInstanceTypeAst($super, $mapping));
        }

        if (peerInstanceCoversSuperclass($state, $superClass, $superHead, $decl)) {
            continue;
        }

        if (! findProjectInstance($state, $superClass, $superHead)) {
            throw typeFail(
                $state,
                "missing superclass instance: `{$superClass} " . typeToString($superHead, friendlyTypeVarNames([$superHead])) . '`',
                $decl,
            );
        }
    }
}

/**
 * True when some project instance of $superClass matches $superHead and each of
 * its constraints appears (by class + args) among $decl->constraints.
 *
 * The peer's constraints are read through the instance head's own variables,
 * the way {@see peerInstanceCoversGiven} reads a quantified superclass's:
 * `instanceMappingForUse()` publishes the match in `__headVars`, so what is
 * compared is the type each constraint becomes at `$superHead`, not the
 * variable names the two declarations happened to choose. Without that, a peer
 * instance's `Eq (f a)` could not be lined up with a declaration that spells the
 * same constraint differently, and could not be substituted at all.
 *
 * The mapping is the structural one `instanceMappingForUse()` publishes as
 * `__headVars`, asked for directly: a superclass check must not unify, because a
 * probe whose head merely came close would leave bindings behind, and it must
 * not record a mapping miss for a head that later becomes more specific.
 */
function peerInstanceCoversSuperclass(
    TypeCheckState $state,
    string $superClass,
    Type $superHead,
    Ast\InstanceDecl $decl,
    ?array $available = null,
    array $extraGivens = [],
): bool {
    $given = constraintGivensFromAsts($state, $available ?? $decl->constraints);
    $given = [...$given, ...$extraGivens];
    if ($given === []) {
        return false;
    }

    return constraintProvableFrom($state, $superClass, $superHead, $given);
}

/**
 * Match a class application written as AST against a checked class head.
 *
 * The head's argument is one type (`Eq (f a)` is `Eq` applied to `f a`), so the
 * pattern's arguments are matched one by one. Matching the whole application at
 * once would read `Eq (f a)` as `Eq` applied to two arguments and reject every
 * head whose argument is itself an application.
 *
 * @return array<string, Type>|null
 */
function matchConstraintPattern(TypeCheckState $state, Ast\TypeNode $pattern, Type $head): ?array
{
    if (! $pattern instanceof Ast\TypeApp || ! $pattern->con instanceof Ast\TypeCon) {
        return matchInstanceHeadVars($state, $pattern, $head);
    }

    if (! $head instanceof TCon || $head->name !== $pattern->con->name) {
        return null;
    }

    if (\count($pattern->args) !== \count($head->args)) {
        return null;
    }

    $bindings = [];
    foreach ($pattern->args as $i => $argPattern) {
        $matched = matchInstanceHeadVars($state, $argPattern, $head->args[$i]);
        if ($matched === null) {
            return null;
        }
        $bindings = [...$bindings, ...$matched];
    }

    return $bindings;
}

/**
 * The givens of a declaration, as class applications over checked types.
 *
 * @param list<Ast\TypeNode> $constraints
 * @return list<array{class: string, args: list<Type>}>
 */
function constraintGivensFromAsts(TypeCheckState $state, array $constraints): array
{
    $givens = [];
    foreach ($constraints as $constraint) {
        if (! $constraint instanceof Ast\TypeNode) {
            continue;
        }
        if ($constraint instanceof Ast\TypeConstrained) {
            $givens = \array_merge($givens, constraintGivensFromAsts($state, $constraint->constraints));
            $constraint = $constraint->body;
        }
        if (! $constraint instanceof Ast\TypeApp || ! $constraint->con instanceof Ast\TypeCon) {
            continue;
        }

        $args = [];
        foreach ($constraint->args as $argAst) {
            $args[] = prune($state, astType($state, $argAst));
        }
        $givens[] = ['class' => $constraint->con->name, 'args' => $args];
    }

    return $givens;
}

/**
 * Prove `C t` from the declaration's givens.
 *
 * A given proves what its class's superclasses say it proves: `Ord a` gives
 * `Eq a`, and a quantified superclass gives one instance per type its context
 * proves -- `Eq1 f` gives `Eq (f a)` for every `a` with `Eq a`, which is what a
 * derived `Eq (Sum f g a)` asks for. A project instance's head proves its class
 * only when each of its own constraints is provable in turn, so a proof may take
 * two steps (`Eq1 g` then `Eq1 f` for `Eq (f (g a))`).
 *
 * @param list<array{class: string, args: list<Type>}> $givens
 */
function constraintProvableFrom(
    TypeCheckState $state,
    string $className,
    Type $head,
    array $givens,
    int $depth = 0,
): bool {
    if ($depth > 8) {
        return false;
    }

    if (\getenv('MOGGI_DEBUG_PROOF') !== false) {
        \fwrite(STDERR, "[proof] want {$className} " . typeToString($head)
            . ' depth=' . $depth . ' givens=' . \count($givens) . "\n");
        foreach ($givens as $g) {
            \fwrite(STDERR, '[proof]   given ' . $g['class'] . '(' . constraintArgsKey($g['args']) . ")\n");
        }
    }

    $available = $givens;
    $givenKeys = [];
    $queue = $givens;
    while ($queue !== []) {
        $given = \array_pop($queue);
        $key = $given['class'] . ':' . constraintArgsKey($given['args']);
        if (isset($givenKeys[$key])) {
            continue;
        }
        $givenKeys[$key] = true;

        $classInfo = $state->classes[$given['class']] ?? null;
        if ($classInfo === null) {
            continue;
        }

        $mapping = [];
        foreach ($classInfo['params'] as $i => $param) {
            if (isset($given['args'][$i])) {
                $mapping[$param['name']] = $given['args'][$i];
            }
        }

        foreach ($classInfo['superclasses'] as $super) {
            if ($super instanceof Ast\TypeForall || ! $super instanceof Ast\TypeApp) {
                continue;
            }
            $substituted = substituteInstanceTypeAst($super, $mapping);
            if (! $substituted instanceof Ast\TypeApp || ! $substituted->con instanceof Ast\TypeCon) {
                continue;
            }
            $args = [];
            foreach ($substituted->args as $argAst) {
                $args[] = prune($state, astType($state, $argAst));
            }
            $expanded = ['class' => $substituted->con->name, 'args' => $args];
            $available[] = $expanded;
            $queue[] = $expanded;
        }
    }

    if (isset($givenKeys[$className . ':' . constraintArgsKey([prune($state, $head)])])) {
        return true;
    }

    foreach ($available as $given) {
        $classInfo = $state->classes[$given['class']] ?? null;
        if ($classInfo === null) {
            continue;
        }

        $mapping = [];
        foreach ($classInfo['params'] as $i => $param) {
            if (isset($given['args'][$i])) {
                $mapping[$param['name']] = $given['args'][$i];
            }
        }

        foreach ($classInfo['superclasses'] as $super) {
            $node = $super;
            $contextConstraints = [];
            while (true) {
                if ($node instanceof Ast\TypeForall) {
                    $node = $node->body;

                    continue;
                }
                if ($node instanceof Ast\TypeConstrained) {
                    $contextConstraints = [...$contextConstraints, ...$node->constraints];
                    $node = $node->body;

                    continue;
                }

                break;
            }

            if (\getenv('MOGGI_DEBUG_PROOF') !== false) {
                \fwrite(STDERR, '[proof]   super of ' . $given['class'] . ' node=' . $node::class
                    . ' ctx=' . \count($contextConstraints) . "\n");
            }

            if ($contextConstraints === []) {
                continue;
            }

            $headPattern = substituteInstanceTypeAst($node, $mapping);
            if (! $headPattern instanceof Ast\TypeApp || ! $headPattern->con instanceof Ast\TypeCon) {
                continue;
            }
            if ($headPattern->con->name !== $className) {
                continue;
            }

            $bindings = matchConstraintPattern($state, $headPattern, $head);
            if ($bindings === null) {
                continue;
            }

            $proved = true;
            foreach ($contextConstraints as $context) {
                $instantiated = substituteInstanceTypeAst(substituteInstanceTypeAst($context, $mapping), $bindings);
                if (! $instantiated instanceof Ast\TypeApp || ! $instantiated->con instanceof Ast\TypeCon) {
                    $proved = false;

                    break;
                }
                $args = [];
                foreach ($instantiated->args as $argAst) {
                    $args[] = prune($state, astType($state, $argAst));
                }
                $contextClass = $instantiated->con->name;
                $contextInfo = $state->classes[$contextClass] ?? null;
                if ($contextInfo === null || ! constraintProvableFrom(
                    $state,
                    $contextClass,
                    instanceHeadFromConstraintArgs($state, $contextInfo['params'], $args, null),
                    $available,
                    $depth + 1,
                )) {
                    $proved = false;

                    break;
                }
            }

            if ($proved) {
                return true;
            }
        }
    }

    foreach ($state->projectInstancesByClass[$className] ?? [] as $instance) {
        $headVars = matchConstraintPattern($state, $instance['instanceHead'], $head);
        if ($headVars === null) {
            continue;
        }

        $proved = true;
        foreach ($instance['constraints'] ?? [] as $need) {
            if (! $need instanceof Ast\TypeNode) {
                $proved = false;

                break;
            }

            $instantiated = substituteInstanceTypeAst($need, $headVars);
            if (! $instantiated instanceof Ast\TypeApp || ! $instantiated->con instanceof Ast\TypeCon) {
                $proved = false;

                break;
            }
            $args = [];
            foreach ($instantiated->args as $argAst) {
                $args[] = prune($state, astType($state, $argAst));
            }
            $contextClass = $instantiated->con->name;
            $contextInfo = $state->classes[$contextClass] ?? null;
            if ($contextInfo === null || ! constraintProvableFrom(
                $state,
                $contextClass,
                instanceHeadFromConstraintArgs($state, $contextInfo['params'], $args, null),
                $available,
                $depth + 1,
            )) {
                $proved = false;

                break;
            }
        }

        if ($proved) {
            return true;
        }
    }

    return false;
}

/**
 * True when a project instance of `$superClass` at `$superHead` asks for nothing
 * beyond what a quantified superclass's context already gives.
 *
 * `forall a. Ord a => Ord (f a)` is proved by finding the `Ord (f a)` instance and
 * showing that its own constraints, instantiated at the matched head, are among
 * the `Ord a` givens. Reading the match is what lets the comparison see through
 * the two declarations' different variable names.
 *
 * @param list<Ast\PendingConstraint> $givens
 */
function peerInstanceCoversGiven(
    TypeCheckState $state,
    string $superClass,
    Type $superHead,
    array $givens,
    Ast\InstanceDecl $decl,
): bool {
    $available = constraintGivensFromAsts($state, $decl->constraints);
    foreach ($givens as $given) {
        $available[] = ['class' => $given->class, 'args' => $given->args];
    }

    if ($available === []) {
        return false;
    }

    return constraintProvableFrom($state, $superClass, $superHead, $available);
}

/**
 * A structural key for a type AST: two nodes get the same key iff they are the
 * same type, however deeply their arguments nest (variable names included —
 * within one instance declaration a variable name denotes one type).
 *
 * The comparison it replaces looked only one level below the class head and
 * only recognised `TypeVar`/`TypeCon` arguments, so a constraint whose argument
 * is itself an application — `Eq (f a)` — never matched, and any instance whose
 * superclass needed it was rejected as "missing superclass instance".
 */
function typeAstKey(TypeNode $type): string
{
    return match ($type::class) {
        Ast\TypeVar::class => 'v:' . $type->name,
        Ast\TypeCon::class => 'c:' . $type->name,
        Ast\TypePromoted::class => 'p:' . $type->name,
        Ast\TypeUnit::class => 'unit',
        Ast\TypeNatLit::class => 'nat:' . ($type->negative ? '-' : '') . $type->digits,
        Ast\TypeStringLit::class => 'str:' . $type->value,
        Ast\TypeApp::class => 'app(' . typeAstKey($type->con) . ';'
            . \implode(',', \array_map(static fn (TypeNode $arg): string => typeAstKey($arg), $type->args)) . ')',
        Ast\TypeArrow::class => 'fn(' . typeAstKey($type->from) . '->' . typeAstKey($type->to) . ')',
        Ast\TypeForall::class => 'forall(' . \implode(',', $type->binders) . ').' . typeAstKey($type->body),
        default => 'node:' . $type::class,
    };
}

/**
 * `Ord a` implies `Eq a` (and similarly for other superclass chains), so a peer
 * `Eq (Min a)` is covered by an `Ord a => Ord (Min a)` instance.
 *
 * @param list<Ast\AstNode> $constraints
 * @return list<Ast\AstNode>
 */
function expandConstraintsWithImpliedSuperclasses(TypeCheckState $state, array $constraints): array
{
    $out = $constraints;
    $seen = [];
    $queue = $constraints;

    while ($queue !== []) {
        $constraint = \array_pop($queue);
        if (! $constraint instanceof Ast\TypeApp || ! $constraint->con instanceof Ast\TypeCon) {
            continue;
        }

        $key = typeAstKey($constraint);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $classInfo = $state->classes[$constraint->con->name] ?? null;
        if ($classInfo === null) {
            continue;
        }

        $mapping = [];
        foreach ($classInfo['params'] as $i => $param) {
            $mapping[$param['name']] = $constraint->args[$i]
                ?? throw typeFail($state, "class `{$constraint->con->name}` applied to too few arguments");
        }

        foreach ($classInfo['superclasses'] as $super) {
            if (! $super instanceof Ast\TypeApp || ! $super->con instanceof Ast\TypeCon) {
                continue;
            }
            $substituted = substituteTypeAstParams($super, $mapping);
            if (! $substituted instanceof Ast\TypeApp) {
                continue;
            }
            $out[] = $substituted;
            $queue[] = $substituted;
        }
    }

    return $out;
}

/**
 * Refuse a duplicate instance whose head coincides with one already checked in
 * this module or with the project's derived set.
 *
 * Two modules that derive the same metadata head produce one instance — the
 * evidence name is a hash of the head — so a derived copy of an identical head
 * coincides with the one already indexed and the first is kept.
 */
function validateNoDuplicateInstance(TypeCheckState $state, Ast\InstanceDecl $decl, Type $headType): void
{
    $headKey = instanceHeadIndexKeyFromType($headType);

    foreach ($state->checkedInstances as $instance) {
        if ($instance['class'] !== $decl->class) {
            continue;
        }
        if (instanceHeadIndexKeyFromType($instance['instanceHead']) !== $headKey) {
            continue;
        }
        if (instanceHeadNamesAnotherDeclaration($state, $headKey, $instance)) {
            continue;
        }

        if (freshenStableTypeKey(prune($state, $headType))
            === freshenStableTypeKey(prune($state, $instance['instanceHead']))) {
            throw typeFail(
                $state,
                'duplicate instance for `' . $decl->class . ' ' . typeToString($headType, friendlyTypeVarNames([$headType])) . '`',
                $decl,
            );
        }
    }

    $candidates = $headKey !== '*'
        ? ($state->projectInstancesByClassHead[$decl->class][$headKey] ?? [])
        : ($state->projectInstancesByClass[$decl->class] ?? []);

    foreach ($candidates as $instance) {
        if ($instance['module'] === ($state->currentModule ?? '')) {
            continue;
        }

        if (instanceHeadNamesAnotherDeclaration($state, $headKey, $instance)) {
            continue;
        }

        if (($state->stockDeriving ?? false)
            && ($instance['fromDeriving'] ?? false) === true
            && freshenStableTypeKey(prune($state, $headType))
                === freshenStableTypeKey(prune($state, astType($state, $instance['instanceHead'])))
        ) {
            continue;
        }

        if (instanceHeadsMatch($state, $headType, $instance['instanceHead'])) {
            throw typeFail(
                $state,
                'duplicate instance for `' . $decl->class . ' ' . typeToString($headType, friendlyTypeVarNames([$headType])) . '`',
                $decl,
            );
        }
    }
}

/** @param array<string, mixed> $mapping */

function instanceHeadIndexKeyFromAst(Ast\AstNode $headAst): string
{
    if ($headAst instanceof Ast\TypeCon) {
        return $headAst->name;
    }
    if ($headAst instanceof Ast\TypePromoted) {
        return $headAst->name;
    }
    if ($headAst instanceof Ast\TypeUnit) {
        return '()';
    }
    if ($headAst instanceof Ast\TypeApp) {
        $flat = flattenInstanceHeadTypeApp($headAst);
        if ($flat !== null) {
            $con = $flat[0];
            if ($con instanceof Ast\TypeCon) {
                return $con->name;
            }
            if ($con instanceof Ast\TypePromoted) {
                return $con->name;
            }
        }
        if ($headAst->con instanceof Ast\TypeCon) {
            return $headAst->con->name;
        }
        if ($headAst->con instanceof Ast\TypePromoted) {
            return $headAst->con->name;
        }
    }

    return '*';
}

/**
 * The methods of an instance that are exactly a primop applied to their own
 * parameters (`(+) a b = intAdd# a b`).
 *
 * Such a method *is* the primop: a use site that resolves to this instance can
 * be lowered to the primop directly instead of calling through the dictionary.
 * The body shape is what decides and checking does not change it, so it is read
 * off the parsed declaration — which also lets a module that merely imports the
 * class see it, without the instance's own module being in the compilation.
 *
 * @return array<string, string> surface method name => IR primop id
 */
function instanceIntrinsicMethods(Ast\InstanceDecl $decl): array
{
    $wrappers = [];
    foreach ($decl->methods as $method) {
        $intrinsic = trivialIntrinsicWrapper($method);

        if ($intrinsic !== null && ! isIoIntrinsic($intrinsic) && ! isStIntrinsic($intrinsic)) {
            $wrappers[$method->name] = $intrinsic;
        }
    }

    return $wrappers;
}

function instanceHeadIndexKeyFromType(Type $head): string
{
    if ($head instanceof TCon) {
        return $head->module === null ? $head->name : $head->module . '::' . $head->name;
    }
    if ($head instanceof TUnit) {
        return '()';
    }
    if ($head instanceof TPromoted) {
        return $head->name;
    }

    return match ($head::class) {
        TInt::class => 'Int',
        TStr::class => 'String',
        TChar::class => 'Char',
        TWord::class => 'Word',
        TWord8::class => 'Word8',
        TWord16::class => 'Word16',
        TWord32::class => 'Word32',
        TWord64::class => 'Word64',
        TInt8::class => 'Int8',
        TInt16::class => 'Int16',
        TInt32::class => 'Int32',
        TInt64::class => 'Int64',
        default => '*',
    };
}

/** @param array{class: string, head: mixed, module?: string} $instance */

function instanceMappingCacheKey(array $instance, Type $requiredHead): string
{
    $instanceHeadKey = $instance['instanceHead'] instanceof Type
        ? instanceLookupKey($instance['class'], $instance['instanceHead'])
        : ($instance['instanceHead'] instanceof Ast\AstNode
            ? instanceHeadIndexKeyFromAst($instance['instanceHead'])
            : '?');

    return ($instance['module'] ?? '') . "\0"
        . $instance['class'] . "\0"
        . $instanceHeadKey . "\0"
        . instanceLookupKey($instance['class'], $requiredHead);
}

/** The head form instance lookup indexes on: prune, reduce families, prune. */
function normalizedInstanceHead(TypeCheckState $state, Type $head): Type
{
    return prune($state, normalizeType($state, prune($state, $head), reduceFamilies: true));
}

/** @return list<array<string, mixed>> */
function projectInstanceCandidates(TypeCheckState $state, string $className, Type $head): array
{
    $headKey = instanceHeadIndexKeyFromType($head);
    if ($headKey === '*') {
        return $state->projectInstancesByClass[$className] ?? [];
    }

    /*
     * A head that is a bare variable is indexed under `*` because it matches
     * every head, so a lookup by a concrete head has to consult that bucket too
     * -- otherwise `instance C a` would be recorded and never found. It goes
     * last: a specific instance answers first, and an overlap between the two
     * is several matches, which the caller reports as ambiguous rather than
     * silently resolving.
     */
    $universal = $state->projectInstancesByClassHead[$className]['*'] ?? [];

    $module = null;
    $plainKey = $headKey;
    $separator = \strpos($headKey, '::');
    if ($separator !== false) {
        $module = \substr($headKey, 0, $separator);
        $plainKey = \substr($headKey, $separator + 2);
    }
    $module ??= instanceHeadDeclaringModule($state, $plainKey);

    if ($module !== null) {
        $qualified = $state->projectInstancesByClassHead[$className][$module . '::' . $plainKey] ?? [];
        if ($qualified !== []) {
            return [...$qualified, ...$universal];
        }
    }

    $candidates = $state->projectInstancesByClassHead[$className][$plainKey] ?? [];

    return $universal === [] ? $candidates : [...$candidates, ...$universal];
}





/**
 * The module a type constructor the current module can name is declared in.
 *
 * A spelling denotes one declaration per module, not one per project, so this
 * is what keeps `Data.Monoid.First` and `Data.Semigroup.First` apart when an
 * instance is selected or a duplicate is refused.
 */
function instanceHeadDeclaringModule(TypeCheckState $state, string $headKey): ?string
{
    $info = $state->data[$headKey] ?? null;
    $module = \is_array($info) ? ($info['declaringModule'] ?? null) : null;

    return \is_string($module) && $module !== '' ? $module : null;
}

/**
 * True when a recorded instance's head names a *different* declaration than the
 * head being checked, both spelled the same way.
 *
 * @param array<string, mixed> $instance
 */
function instanceHeadNamesAnotherDeclaration(TypeCheckState $state, string $headKey, array $instance): bool
{
    $theirs = $instance['headModule'] ?? null;
    if (! \is_string($theirs) || $theirs === '') {
        return false;
    }

    $mine = instanceHeadDeclaringModule($state, $headKey) ?? $state->currentModule;

    return \is_string($mine) && $mine !== '' && $mine !== $theirs;
}

/**
 * The project instances whose head unifies with `$requiredHead` (already
 * normalized by the caller), in declaration order.
 *
 * Yielded lazily so a caller that only needs the first match, or needs to stop
 * at the second to reject an ambiguous one, does no more unification than it
 * has to.
 *
 * @return \Generator<int, array<string, mixed>>
 */
function matchingProjectInstances(TypeCheckState $state, string $className, Type $requiredHead): \Generator
{
    foreach (projectInstanceCandidates($state, $className, $requiredHead) as $instance) {
        if (instanceHeadsMatch($state, $requiredHead, $instance['instanceHead'])) {
            yield $instance;
        }
    }
}

function findProjectInstance(TypeCheckState $state, string $className, Type $requiredHead, array $visited = []): bool
{
    $lookupKey = instanceLookupKey($className, normalizedInstanceHead($state, $requiredHead));

    if (classHasErasedContextInstance($state, $className)) {
        $lookupKey .= '#' . ambientCoercionFingerprint($state);
    }

    if (\array_key_exists($lookupKey, $state->instanceLookupCache)) {
        return $state->instanceLookupCache[$lookupKey];
    }

    $found = findProjectInstanceRecord($state, $className, $requiredHead, $visited) !== null;
    $state->instanceLookupCache[$lookupKey] = $found;

    return $found;
}

/**
 * Whether any project instance of `$className` declares a coercion constraint,
 * whose satisfaction depends on the givens of the body being checked.
 */
function classHasErasedContextInstance(TypeCheckState $state, string $className): bool
{
    if (isset($state->erasedContextClasses[$className])) {
        return $state->erasedContextClasses[$className];
    }

    $erased = classDeclaresErasedContextInstance($state, $className);

    return $state->erasedContextClasses[$className] = $erased;
}

function classDeclaresErasedContextInstance(TypeCheckState $state, string $className): bool
{
    foreach ($state->projectInstancesByClass[$className] ?? [] as $instance) {
        foreach ($instance['constraints'] ?? [] as $constraintAst) {
            if ($constraintAst instanceof Ast\TypeApp
                && $constraintAst->con instanceof Ast\TypeCon
                && isCoercionClass($constraintAst->con->name)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * True when exactly one project instance's head unifies with `$requiredHead`.
 *
 * A head with unresolved arguments can match several instances at once --
 * `Both Int a` matches both `Both Int Bool` and `Both Int Int` -- and taking
 * whichever is declared first would be unsound. Such an obligation has to wait
 * until unification makes the choice unique, so the eager solver declines it and
 * the post-pass retries once the argument is known.
 */
function findUniqueProjectInstance(TypeCheckState $state, string $className, Type $requiredHead): bool
{
    $requiredHead = normalizedInstanceHead($state, $requiredHead);

    $count = 0;
    foreach (matchingProjectInstances($state, $className, $requiredHead) as $_) {
        if (++$count > 1) {
            return false;
        }
    }

    return $count === 1;
}

/**
 * @param array<string, true> $visited
 * @return array{instance: array<string, mixed>, mapping: array<string, mixed>}|null
 */
function findProjectInstanceRecord(TypeCheckState $state, string $className, Type $requiredHead, array $visited = []): ?array
{
    $requiredHead = normalizedInstanceHead($state, $requiredHead);
    $lookupKey = instanceLookupKey($className, $requiredHead);
    if (isset($visited[$lookupKey])) {
        return null;
    }
    $visited[$lookupKey] = true;

    if ($requiredHead instanceof TVar) {
        return null;
    }

    foreach (matchingProjectInstances($state, $className, $requiredHead) as $instance) {
        $mapping = instanceMappingForUse($state, $instance, $requiredHead);
        if ($mapping === null || ! instanceContextSatisfied($state, $instance, $mapping, $visited)) {
            continue;
        }

        return ['instance' => $instance, 'mapping' => $mapping];
    }

    return null;
}

/**
 * Freshen-stable type key for instance caches. Fresh `tN` names must not
 * fragment the cache (that re-rendered huge TupleN heads on every lookup).
 * Concrete args (Int vs Bool) stay distinct so context-sensitive results remain sound.
 */
function freshenStableTypeKey(Type $type): string
{
    $rename = [];
    $n = 0;

    return freshenStableTypeKeyRec($type, $rename, $n);
}

/** @param array<string, string> $rename */
function freshenStableTypeKeyRec(Type $type, array &$rename, int &$n): string
{
    if ($type instanceof TVar) {
        if (! isset($rename[$type->name])) {
            $rename[$type->name] = 'v' . $n++;
        }

        return $rename[$type->name];
    }

    if ($type instanceof TUnit) {
        return '()';
    }

    if (! $type instanceof TArrow && ! $type instanceof TCon && ! $type instanceof TPromoted) {
        return typeToString($type);
    }

    $keys = [];
    foreach (typeChildren($type) as $child) {
        $keys[] = freshenStableTypeKeyRec($child, $rename, $n);
    }

    return match ($type::class) {
        TArrow::class => '(' . $keys[0] . ')->(' . $keys[1] . ')',
        TCon::class => ($type->module === null ? '' : $type->module . '::')
            . $type->name . ($keys === [] ? '' : '(' . \implode(',', $keys) . ')'),
        default => "'" . $type->name . ($keys === [] ? '' : '(' . \implode(',', $keys) . ')'),
    };
}

/**
 * Cache key for instance lookup. Must be stable across freshening so polymorphic
 * TupleN / Maybe a lookups reuse work instead of re-stringifying huge heads.
 */
function instanceLookupKey(string $className, Type $head): string
{
    return $className . "\0" . freshenStableTypeKey($head);
}

/**
 * @param array{class: string, head: Ast\TypeNode, constraints?: list<Ast\TypeNode>} $instance
 * @return array<string, Type|array<string, Type>>|null
 */
function instanceMappingForUse(TypeCheckState $state, array $instance, Type $requiredHead): ?array
{
    $classInfo = $state->classes[$instance['class']] ?? null;
    if ($classInfo === null) {
        return null;
    }

    $cacheKey = instanceMappingCacheKey($instance, $requiredHead);
    if (\array_key_exists($cacheKey, $state->instanceMappingCache)) {
        return null;
    }

    $wantedHead = prune($state, $requiredHead);
    $headVarMapping = matchInstanceHeadVars($state, $instance['instanceHead'], $wantedHead);
    if ($headVarMapping === null) {
        $state->instanceMappingCache[$cacheKey] = null;

        return null;
    }

    try {
        $mapping = instanceParamMapping($state, $classInfo['params'], $wantedHead, $instance['instanceHead']);
        $mapping['__headVars'] = $headVarMapping;

        return $mapping;
    } catch (TypeError) {
    }

    pushSubstFrame($state);

    try {
        $instanceHeadType = freshenTypeVars($state, astType($state, $instance['instanceHead']));
        unify($state, $requiredHead, $instanceHeadType, null);

        $mapping = instanceParamMapping($state, $classInfo['params'], prune($state, $requiredHead), $instance['instanceHead']);
        $mapping['__headVars'] = $headVarMapping;

        return $mapping;
    } catch (TypeError) {
        $mapping = instanceParamMapping($state, $classInfo['params'], prune($state, $requiredHead), $instance['instanceHead']);
        $mapping['__headVars'] = $headVarMapping;

        return $mapping;
    } finally {
        popSubstFrame($state);
    }
}

/**
 * @param array{class: string, head: array<string, mixed>, constraints?: list<array<string, mixed>>} $instance
 * @param array<string, array<string, mixed>> $mapping
 * @param array<string, true> $visited
 */

function instanceContextSatisfied(TypeCheckState $state, array $instance, array $mapping, array $visited = []): bool
{
    $context = instanceContextRequirements($state, $instance, $mapping);

    if ($context === null) {
        return false;
    }

    foreach ($context as $constraint) {
        if (($constraint->class === '~' || $constraint->class === '~~')
            && ! isset($state->classes[$constraint->class])
        ) {
            if (\count($constraint->args) !== 2) {
                return false;
            }
            $left = prune($state, $constraint->args[0]);
            $right = prune($state, $constraint->args[1]);
            if ($left instanceof TVar && $right instanceof TVar) {
                if (! equalityGiven($state, $left->name, $right->name)) {
                    return false;
                }

                continue;
            }
            pushSubstFrame($state);
            try {
                unify($state, $left, $right, null);
            } catch (TypeError) {
                return false;
            } finally {
                popSubstFrame($state);
            }

            continue;
        }

        if (constraintIsErased($constraint)) {
            if (coercionObligation($state, $constraint) !== CoercionObligation::Satisfied) {
                return false;
            }

            continue;
        }

        $ctxHead = prune($state, constraintHeadType($state, $constraint->args));

        if (typeHeadIsVariable($ctxHead)) {
            continue;
        }

        if (! findProjectInstance($state, $constraint->class, $ctxHead, $visited)) {
            return false;
        }
    }

    return true;
}

/**
 * The class constraints an instance's context requires, over the instance's head
 * variables: `instance Show a => Show [a]` needs `Show a` to show a `[a]`.
 *
 * @param array<string, mixed> $instance
 * @param array<string, mixed> $mapping
 * @return ?list<Ast\PendingConstraint> null when the context is not a plain class application
 */
function instanceContextRequirements(TypeCheckState $state, array $instance, array $mapping): ?array
{
    $headVars = $mapping['__headVars'] ?? [];
    $context = [];

    foreach ($instance['constraints'] ?? [] as $constraintAst) {
        if (! $constraintAst instanceof Ast\TypeApp || ! $constraintAst->con instanceof Ast\TypeCon) {
            return null;
        }

        $args = [];
        foreach ($constraintAst->args as $arg) {
            $args[] = instantiateContextArg($state, $arg, $headVars);
        }

        $context[] = new Ast\PendingConstraint($constraintAst->con->name, $args);
    }

    return $context;
}

/**
 * @return array<string, Type>|null
 */
function matchInstanceHeadVars(TypeCheckState $state, Ast\AstNode $pattern, Type $target): ?array
{
    $target = prune($state, $target);

    if ($pattern instanceof Ast\TypeVar) {
        return [$pattern->name => $target];
    }

    if ($pattern instanceof Ast\TypeStringLit) {
        return ($target instanceof TStringLit && $target->value === $pattern->value) ? [] : null;
    }

    if ($pattern instanceof Ast\TypeNatLit) {
        $digits = $pattern->negative ? '-' . $pattern->digits : $pattern->digits;

        return ($target instanceof TNatLit && $target->digits === $digits) ? [] : null;
    }

    if ($pattern instanceof Ast\TypePromoted) {
        if ($target instanceof TPromoted && $target->name === $pattern->name && $target->args === []) {
            return [];
        }
        if ($target instanceof TCon && $target->name === $pattern->name && $target->args === []) {
            return [];
        }

        return null;
    }

    if ($pattern instanceof Ast\TypeUnit) {
        return $target instanceof TUnit ? [] : null;
    }

    if ($pattern instanceof Ast\TypeCon) {
        if ($target instanceof TCon) {
            return $target->name === $pattern->name ? [] : null;
        }
        if ($target instanceof TPromoted) {
            return $target->name === $pattern->name ? [] : null;
        }

        return [];
    }

    if ($pattern instanceof Ast\TypeApp) {
        $flat = flattenInstanceHeadTypeApp($pattern);
        if ($flat === null) {
            return null;
        }
        [$headCon, $patternArgs] = $flat;
        $headName = null;
        if ($headCon instanceof Ast\TypeCon) {
            $headName = $headCon->name;
        } elseif ($headCon instanceof Ast\TypePromoted) {
            $headName = $headCon->name;
        }
        if ($headName === null) {
            return null;
        }

        if ($target instanceof TPromoted) {
            if ($target->name !== $headName || \count($patternArgs) !== \count($target->args)) {
                return null;
            }
            $mapping = [];
            foreach ($patternArgs as $i => $arg) {
                $sub = matchInstanceHeadVars($state, $arg, $target->args[$i]);
                if ($sub === null) {
                    return null;
                }
                foreach ($sub as $name => $type) {
                    $mapping[$name] = $type;
                }
            }

            return $mapping;
        }

        if (! $target instanceof TCon || $target->name !== $headName) {
            return null;
        }
        if (\count($patternArgs) !== \count($target->args)) {
            return null;
        }
        $mapping = [];
        foreach ($patternArgs as $i => $arg) {
            $sub = matchInstanceHeadVars($state, $arg, $target->args[$i]);
            if ($sub === null) {
                return null;
            }
            foreach ($sub as $name => $type) {
                $mapping[$name] = $type;
            }
        }

        return $mapping;
    }

    if ($pattern instanceof Ast\TypeArrow) {
        if (! $target instanceof TArrow) {
            return null;
        }
        $from = matchInstanceHeadVars($state, $pattern->from, $target->from);
        $to = matchInstanceHeadVars($state, $pattern->to, $target->to);
        if ($from === null || $to === null) {
            return null;
        }

        return $from + $to;
    }

    return null;
}

/**
 * @return array{0: Ast\TypeNode, 1: list<Ast\TypeNode>}|null
 */
function flattenInstanceHeadTypeApp(Ast\TypeNode $type): ?array
{
    $args = [];
    while ($type instanceof Ast\TypeApp) {
        for ($i = \count($type->args) - 1; $i >= 0; $i--) {
            $args[] = $type->args[$i];
        }
        $type = $type->con;
    }

    if (
        $type instanceof Ast\TypeCon
        || $type instanceof Ast\TypeVar
        || $type instanceof Ast\TypePromoted
    ) {
        return [$type, \array_reverse($args)];
    }

    return null;
}

/**
 * The type a declaration writes, read without the kind table.
 *
 * A declaration's variables are bound by the declaration: `instance Alternative (ArrowMonad a)`
 * says `a` is a `Type -> Type -> Type` by the position it stands in, and a superclass's `f` says
 * `Type -> Type` the same way. The kind table is keyed by variable spelling, though, and holds
 * whatever the module reading the declaration has called `a` or `f`, so converting the declaration
 * through that table asks about a variable it does not own and refuses the declaration at a kind it
 * never gave itself. The shape is what naming, matching and evidence grouping ask for, so it is
 * read from the syntax: a constructor keeps its name, every variable keeps its spelling, and only
 * the reader's kind table goes unconsulted.
 */
function declarationHeadType(TypeCheckState $state, Ast\TypeNode $ast): Type
{
    if ($ast instanceof Ast\TypeVar) {
        return new TVar(skolemScopedVar($state, $ast->name));
    }

    if ($ast instanceof Ast\TypeCon) {
        return new TCon($ast->name);
    }

    if ($ast instanceof Ast\TypeApp) {
        $flat = flattenInstanceHeadTypeApp($ast)
            ?? throw typeFail($state, 'type is not a type constructor applied to arguments', $ast);

        [$con, $args] = $flat;
        $conType = declarationHeadType($state, $con);
        $argTypes = [];
        foreach ($args as $arg) {
            $argTypes[] = declarationHeadType($state, $arg);
        }

        return match (true) {
            $conType instanceof TVar => new TVarApp($conType->name, $argTypes),
            $conType instanceof TPromoted => new TPromoted($conType->name, $argTypes),
            $conType instanceof TCon => new TCon($conType->name, $argTypes, $conType->module),
            default => throw typeFail($state, 'type is not a type constructor', $ast),
        };
    }

    if ($ast instanceof Ast\TypeArrow) {
        return new TArrow(
            declarationHeadType($state, $ast->from),
            declarationHeadType($state, $ast->to),
        );
    }

    return match (true) {
        $ast instanceof Ast\TypePromoted => new TPromoted($ast->name),
        $ast instanceof Ast\TypeStringLit => new TStringLit($ast->value),
        $ast instanceof Ast\TypeNatLit => new TNatLit(canonicalNatDigits($ast->digits)),
        $ast instanceof Ast\TypeUnit => new TUnit(),
        default => astType($state, $ast),
    };
}

/**
 * The type a declaration writes, with the kind table out of the way.
 *
 * Converting the declaration as usual is what normally answers, and is the only reading that
 * expands synonyms the way a use does. A kind failure is the one case where the reading is not
 * about this module's variables at all -- the declaration's own variable spelling met an entry the
 * reader left in the table -- so it is answered from the shape instead.
 */
function declarationType(TypeCheckState $state, Ast\TypeNode $ast): Type
{
    try {
        return astType($state, $ast);
    } catch (TypeError $e) {
        if ($e->diagnosticCode === 'unify') {
            throw $e;
        }

        return declarationHeadType($state, $ast);
    }
}

function instanceHeadsMatch(TypeCheckState $state, Type $requiredHead, Ast\AstNode|Type $instanceHeadAst): bool
{
    if ($instanceHeadAst instanceof Type) {
        return false;
    }

    pushSubstFrame($state);
    $matched = false;
    $kindFailure = false;

    try {
        $other = freshenTypeVars($state, astType($state, $instanceHeadAst));
        unify($state, $requiredHead, $other, null);
        $matched = true;
    } catch (TypeError $e) {
        $kindFailure = $e->diagnosticCode !== 'unify';
    } finally {
        popSubstFrame($state);
    }

    if ($matched) {
        return true;
    }

    if (! $kindFailure) {
        return false;
    }

    pushSubstFrame($state);

    try {
        $other = freshenTypeVars($state, declarationHeadType($state, $instanceHeadAst));
        unify($state, $requiredHead, $other, null);

        return true;
    } catch (TypeError) {
        return false;
    } finally {
        popSubstFrame($state);
    }
}

/**
 * @param list<array{name: string, kind?: mixed, resolvedKind?: mixed}> $classParams
 * @return array<string, Type>
 */
function instanceParamMapping(TypeCheckState $state, array $classParams, Type $headType, Ast\AstNode $at): array
{
    $paramNames = Kinds\classParamNames($classParams);

    if (\count($classParams) === 0) {
        return [];
    }

    if (\count($classParams) === 1) {
        $expectedKind = Kinds\classParamKind($state, $classParams[0], $at);
        Kinds\assertKind($state, $headType, $expectedKind, [], $at);

        return [$paramNames[0] => $headType];
    }

    if (! $headType instanceof TCon) {
        throw typeFail($state, 'instance head must be a type constructor', $at);
    }

    if (\count($headType->args) !== \count($classParams)) {
        throw typeFail($state, 'instance head arity mismatch', $at);
    }

    $mapping = [];
    foreach ($classParams as $i => $param) {
        $expectedKind = Kinds\classParamKind($state, $param, $at);
        Kinds\assertKind($state, $headType->args[$i], $expectedKind, [], $at);
        $mapping[$param['name']] = $headType->args[$i];
    }

    return $mapping;
}

/**
 * Parse instance context constraints with head variables remapped to the
 * freshened instance head (so `Bounded a` on `Bounded (Min a)` uses the same
 * `a` as the method body's `Min a`).
 *
 * @return list<Ast\PendingConstraint>
 */
function instanceContextConstraints(TypeCheckState $state, Ast\InstanceDecl $decl, Type $headType): array
{
    $headVars = matchInstanceHeadVars($state, $decl->head, $headType) ?? [];
    $parsed = [];
    foreach ($decl->constraints as $i => $constraint) {
        if ($constraint instanceof Ast\TypeForall) {
            throw typeFail(
                $state,
                'a quantified constraint cannot appear in an instance context; '
                    . 'quantify a superclass instead (`class (forall a. C (f a)) => D f`)',
                $decl,
            );
        }

        if (! $constraint instanceof Ast\TypeApp || ! $constraint->con instanceof Ast\TypeCon) {
            throw typeFail($state, 'instance constraint must be a type class application', $decl);
        }

        if (($constraint->con->name === '~' || $constraint->con->name === '~~')
            && ! isset($state->classes[$constraint->con->name])
        ) {
            assertEqualityConstraintHolds($state, $constraint, $decl, $headVars);

            continue;
        }

        $className = $constraint->con->name;
        $classInfo = $state->classes[$className]
            ?? throw typeFail($state, "unknown class `{$className}` in instance constraint", $decl);
        assertConstraintArity($state, $className, $classInfo, $constraint);
        $args = [];
        foreach ($classInfo['params'] as $j => $param) {
            $argAst = $constraint->args[$j]
                ?? throw typeFail($state, "class `{$className}` applied to too few arguments", $decl);
            $args[] = instantiateContextArg($state, $argAst, $headVars);
        }
        $parsed[] = new Ast\PendingConstraint(
            $className,
            $args,
            evidenceParamName($className, $i),
        );
    }

    return $parsed;
}

/** @param list<Ast\AstNode> $constraints @return array<string, Scheme> */
function buildInstanceConstraintEnv(TypeCheckState $state, array $constraints, Type $headType): array
{
    $env = [];
    foreach ($constraints as $constraint) {
        if ($constraint instanceof Ast\TypeForall) {
            throw typeFail(
                $state,
                'a quantified constraint cannot appear in an instance context; '
                    . 'quantify a superclass instead (`class (forall a. C (f a)) => D f`)',
            );
        }

        if (! $constraint instanceof Ast\TypeApp || ! $constraint->con instanceof Ast\TypeCon) {
            throw typeFail($state, 'instance constraint must be a type class application');
        }

        if (($constraint->con->name === '~' || $constraint->con->name === '~~')
            && ! isset($state->classes[$constraint->con->name])
        ) {
            continue;
        }

        $className = $constraint->con->name;
        $classInfo = $state->classes[$className] ?? throw typeFail($state, "unknown class `{$className}` in instance constraint");
        assertConstraintArity($state, $className, $classInfo, $constraint);
        $mapping = [];
        foreach ($classInfo['params'] as $i => $param) {
            $mapping[$param['name']] = constructorMappingValue(
                $state,
                $param,
                constraintArgType(
                    $state,
                    $constraint->args[$i] ?? throw typeFail($state, "class `{$className}` applied to too few arguments"),
                    $headType,
                    $i,
                ),
            );
        }

        foreach ($classInfo['methods'] as $methodName => $methodInfo) {
            $mappedType = prune($state, substituteClassMethod(
                $state,
                $methodInfo['type'],
                [],
                $classInfo['params'],
                $mapping,
            )['type']);
            if (! $mappedType instanceof TArrow) {
                continue;
            }

            $env[$methodName] = scheme(
                prune($state, freshenTypeVars($state, $mappedType)),
                [],
            );
        }
    }

    return $env;
}

function constraintArgType(TypeCheckState $state, Ast\AstNode $arg, Type $headType, int $index): Type
{
    if ($headType instanceof TCon && isset($headType->args[$index])) {
        return $headType->args[$index];
    }

    return astType($state, $arg);
}

/**
 * Eta-expand an instance method written as a partial application
 * (`meth = f arg`) so dictionaries store a real arity-N callable instead of
 * a nullary function returning a `__partial` cell.
 *
 * @param list<Ast\PatVar|Ast\AstNode> $params
 * @return array{0: list<Ast\AstNode>, 1: Ast\AstNode}
 */
function etaExpandInstanceMethod(array $params, Ast\AstNode $body, Type $userFacingType): array
{
    $arity = 0;
    $cursor = $userFacingType;
    while ($cursor instanceof TArrow) {
        $arity++;
        $cursor = $cursor->to;
    }

    if (\count($params) >= $arity) {
        return [$params, $body];
    }

    $i = \count($params);
    while ($i < $arity) {
        $name = '__eta' . $i;
        $params[] = new Ast\PatVar($name);
        $body = new Ast\Apply($body, new Ast\Variable($name));
        $i++;
    }

    return [$params, $body];
}

/**
 * Every method name of a class and, transitively, of its superclasses.
 *
 * @return array<string, true>
 */
function ownClassMethodNames(TypeCheckState $state, string $className): array
{
    $names = [];
    $seen = [];
    $pending = [$className];
    while ($pending !== []) {
        $current = \array_pop($pending);
        if (isset($seen[$current])) {
            continue;
        }
        $seen[$current] = true;
        $classInfo = $state->classes[$current] ?? null;
        if ($classInfo === null) {
            continue;
        }
        foreach ($classInfo['methods'] as $methodName => $_) {
            $names[$methodName] = true;
        }
        foreach ($classInfo['superclasses'] as $super) {
            $superName = superclassName($super);
            if ($superName !== null) {
                $pending[] = $superName;
            }
        }
    }

    return $names;
}

/**
 * Whether the datatype an instance head is about declares a GADT constructor.
 *
 * A GADT constructor's written result type is what a pattern refines, so this
 * is the condition under which an instance's head variables must be rigid.
 */
function instanceHeadIsGadt(TypeCheckState $state, Type $headType): bool
{
    $headType = prune($state, $headType);
    if (! $headType instanceof TCon) {
        return false;
    }

    foreach ($state->data[$headType->name]['constructors'] ?? [] as $ctor) {
        if (! empty($ctor['gadt'])) {
            return true;
        }
    }

    return false;
}

function checkInstanceMethod(
    TypeCheckState $state,
    Ast\FunctionDecl $fn,
    Type $expectedType,
    array $extraEnv = [],
    array $methodUserConstraints = [],
    array $instanceConstraints = [],
    string $className = '',
    ?Type $headType = null,
    array $declaredKinds = [],
    array $freshening = [],
): Ast\FunctionDecl {
    $state->subst = [];
    $savedInstanceHead = $state->instanceHeadInScope;
    $savedInstanceOwnMethods = $state->instanceOwnMethods;
    $state->instanceHeadInScope = $headType;
    $state->instanceOwnMethods = [];
    $env = $state->env;
    $savedClassMethod = (($state->env[$fn->name] ?? null)?->classMethod ?? false)
        ? $state->env[$fn->name]
        : null;
    unset($env[$fn->name]);
    if ($extraEnv !== []) {
        $env = [...$env, ...$extraEnv];
    }
    $constraints = expandConstraintsWithSuperclasses($state, [
        ...$instanceConstraints,
        ...$methodUserConstraints,
    ]);
    $peeledType = peelDictArrows($expectedType, \count($methodUserConstraints));

    [$fnType, $forallSkolems] = openSignatureForalls($state, $peeledType);
    $savedRigidVars = $state->rigidVars;
    $savedSkolemScope = $state->skolemScope;
    $skolemBack = [];
    foreach ($forallSkolems as $source => $skolem) {
        $state->rigidVars[$skolem->name] = true;
        $state->skolemScope[$source] = $skolem->name;
        $skolemBack[$skolem->name] = new TVar($source);
    }
    if ($forallSkolems !== []) {
        $constraints = substitutePendingConstraints($constraints, $forallSkolems);
    }

    $headVars = $headType === null || ! instanceHeadIsGadt($state, $headType)
        ? []
        : typeVars($headType);
    if ($headType !== null && ! instanceHeadIsGadt($state, $headType)) {
        foreach (\array_keys(typeVars($headType)) as $name) {
            if (isset($state->rigidVars[$name])) {
                continue;
            }
            $state->rigidVars[$name] = true;
        }
    }
    $headSkolems = [];
    foreach (\array_keys(typeVars($fnType)) as $name) {
        if (! isset($headVars[$name]) || isset($state->rigidVars[$name]) || \preg_match('/\$sk\d+$/', $name) === 1) {
            continue;
        }
        $headSkolems[$name] = freshSkolem($state, $name);
    }
    foreach ($headSkolems as $source => $skolem) {
        $state->rigidVars[$skolem->name] = true;
        $state->skolemScope[$source] ??= $skolem->name;
        $skolemBack[$skolem->name] = new TVar($source);
    }
    if ($headSkolems !== []) {
        $fnType = substitute($fnType, $headSkolems);
        $constraints = substitutePendingConstraints($constraints, $headSkolems);
    }

    $unskolemize = static fn (Type $type): Type => $skolemBack === [] ? $type : substitute($type, $skolemBack);
    foreach (\array_reverse(dictionaryConstraints($constraints)) as $constraint) {
        $fnType = new TArrow(new TCon('__Dict_' . $constraint->class), $fnType);
    }

    $dictConstraints = dictionaryConstraints($constraints);
    if ($dictConstraints !== []) {
        $evidencePatterns = [];
        foreach ($dictConstraints as $constraint) {
            $evidencePatterns[] = new Ast\PatVar($constraint->evidence);
        }
        $fn->params = [...$evidencePatterns, ...$fn->params];
        $fn->constraints = $dictConstraints;
    }

    $expected = $fnType;
    $paramTypes = [];
    foreach ($fn->params as $param) {
        if (! $expected instanceof TArrow) {
            throw typeFail($state, "method `{$fn->name}` has too many parameters", $fn);
        }
        $paramTypes[] = stripDictArgConstraints($expected->from);
        $expected = $expected->to;
    }
    $bodyExpected = $expected;


    $state->pushBinderScope();
    foreach ($fn->params as $i => $param) {
        [, $env] = bindPattern($state, $param, $paramTypes[$i], $env, generalize: false);
    }

    $savedConstraintMethods = $state->constraintMethods;
    $savedConstraintMethodAmbiguities = $state->constraintMethodAmbiguities;
    $state->constraintMethods = [];
    $state->constraintMethodAmbiguities = [];

    if (! $state->stockDeriving) {
        foreach ($constraints as $constraint) {
            $classInfo = $state->classes[$constraint->class];
            $mapping = [];
            foreach ($classInfo['params'] as $i => $param) {
                $mapping[$param['name']] = constructorMappingValue($state, $param, $constraint->args[$i]);
            }

            foreach ($classInfo['methods'] as $methodName => $methodInfo) {
                $substituted = substituteClassMethod(
                    $state,
                    $methodInfo['type'],
                    $methodInfo['userConstraints'] ?? [],
                    $classInfo['params'],
                    $mapping,
                );
                $nestedUserConstraints = $substituted['constraints'];
                $methodType = peelDictArrows(
                    $substituted['type'],
                    \count($nestedUserConstraints),
                );
                if (! prune($state, $methodType) instanceof TArrow) {
                    continue;
                }
                $info = [
                    'class' => $constraint->class,
                    'method' => $methodName,
                    'evidence' => $constraint->evidence,
                    'type' => $methodType,
                    'userConstraints' => $nestedUserConstraints,
                    'constraintHead' => prune($state, $constraint->args[0]),
                    'constraintArgs' => $constraint->args,
                    'implicit' => $constraint->implicit,
                    'instanceHeadAst' => $constraint->instanceHeadAst,
                ];

                if (isset($state->constraintMethods[$methodName])) {
                    $existing = $state->constraintMethods[$methodName];
                    if (($existing['class'] ?? null) !== $info['class']) {
                        unset($state->constraintMethods[$methodName]);
                        $state->constraintMethodAmbiguities[$methodName] = true;
                        unset($env[$methodName]);
                    } elseif (($existing['evidence'] ?? null) !== $info['evidence']) {
                        unset($state->constraintMethods[$methodName]);
                        $orig = $state->env[$methodName] ?? null;
                        if ($orig?->classMethod) {
                            $env[$methodName] = $orig;
                        }
                    }

                    continue;
                }

                if (isset($state->constraintMethodAmbiguities[$methodName])) {
                    unset($env[$methodName]);

                    continue;
                }

                $state->constraintMethods[$methodName] = $info;
                unset($env[$methodName]);
            }
        }
    }

    $methodConstraintEnv = buildConstraintEnv($state, $constraints);
    if ($methodConstraintEnv !== [] && ! $state->stockDeriving) {
        $env = [...$env, ...$methodConstraintEnv];
    }

    if ($className !== '') {
        $state->instanceOwnMethods = ownClassMethodNames($state, $className);
        $ownSchemes = ownClassMethodSchemes($state, $className);
        foreach ($state->instanceOwnMethods as $methodName => $_) {
            unset(
                $state->constraintMethods[$methodName],
                $state->constraintMethodAmbiguities[$methodName],
            );
            $scheme = $ownSchemes[$methodName] ?? null;
            if ($scheme !== null) {
                $env[$methodName] = $scheme;
                $existing = $state->env[$methodName] ?? null;
                if ($existing === null
                    || ! ($existing->classMethod ?? false)
                    || $existing->class === $scheme->class) {
                    $state->env[$methodName] = $scheme;
                }
            } elseif (isset($state->env[$methodName])) {
                $env[$methodName] = $state->env[$methodName];
            } else {
                unset($env[$methodName]);
            }
        }
    }

    $savedAmbientConstraints = $state->ambientConstraints;
    $savedAmbientByClass = $state->ambientConstraintsByClass;
    $state->ambientConstraints = $constraints;
    $byClass = [];
    foreach ($constraints as $constraint) {
        $byClass[$constraint->class][] = $constraint;
    }
    $state->ambientConstraintsByClass = $byClass;

    if ($savedClassMethod !== null) {
        $env[$fn->name] = $savedClassMethod;
    } elseif (! isset($env[$fn->name])) {
        $env[$fn->name] = scheme(
            $peeledType,
            [],
            [...$instanceConstraints, ...$methodUserConstraints],
            \count(dictionaryConstraints($constraints)),
            $constraints,
        );
    }

    $bodyType = inferExpr($state, $fn->body, $env);
    assertPendingDictsConsistentInExpr($state, $fn->body);
    $state->constraintMethods = $savedConstraintMethods;
    $state->constraintMethodAmbiguities = $savedConstraintMethodAmbiguities;
    unify($state, $bodyType, $bodyExpected, $fn);
    defaultAmbiguousNumericVars($state, $fn->body, quantifiedVars($state, $peeledType), $fn);
    resolveDeferredInfixPrimops($state, $fn->body);
    $fn->body = resolvePendingEvidenceInExpr($state, $fn->body);
    $fn->body = tryResolveValueEvidence($state, $fn->body);
    $fn->body = elaborateNumericLiterals($state, $fn->body);
    $state->ambientConstraints = $savedAmbientConstraints;
    $state->ambientConstraintsByClass = $savedAmbientByClass;
    assertNoPendingConstraintsInExpr($state, $fn->body, $fn);
    zonkInferredTypesInExpr($state, $fn->body);
    foreach ($fn->params as $param) {
        zonkInferredTypesInPattern($state, $param);
    }

    $wrapper = trivialIntrinsicWrapper($fn);
    resolveInstanceIntrinsicMethods($state, $fn->body);
    $fn->body = rewriteIntrinsicApplies($state, $fn->body);
    if ($wrapper !== null) {
        $fn->intrinsicWrapper = $wrapper;
    }

    $fn->type = internalTypeToAst(prune($state, $unskolemize($fnType)), $state->subst);

    $settledType = prune($state, $unskolemize($fnType));
    $userType = peelDictArrows($settledType, \count($constraints));
    $userObligations = refreshConstraintArgs($state, [...$instanceConstraints, ...$methodUserConstraints]);
    $settledConstraintList = refreshConstraintArgs($state, $constraints);
    $fn->constraints = $settledConstraintList;
    $dictArgs = [];
    foreach ($settledConstraintList as $dict) {
        foreach ($dict->args as $arg) {
            $dictArgs[] = $arg;
        }
    }
    $bound = \array_keys(collectTypeVars($settledType, []));
    foreach ($userObligations as $obligation) {
        foreach ($obligation->args as $arg) {
            foreach (\array_keys(collectTypeVars(prune($state, $arg), [])) as $var) {
                if (! \in_array($var, $bound, true)) {
                    $bound[] = $var;
                }
            }
        }
    }
    $carriedKinds = [];
    foreach ($declaredKinds as $source => $kind) {
        $fresh = $freshening[$source] ?? null;
        if (! $fresh instanceof TVar) {
            continue;
        }
        $settled = prune($state, $fresh);
        if ($settled instanceof TVar) {
            $carriedKinds[$settled->name] = $kind;
        }
    }
    $state->checkedParameterKinds[\spl_object_id($fn)] = $carriedKinds + Kinds\declarationParameterKinds(
        $state,
        $userType,
        $dictArgs,
    );
    $state->checkedSchemes[\spl_object_id($fn)] = scheme(
        $userType,
        $bound,
        $userObligations,
        \count(dictionaryConstraints($settledConstraintList)),
        $settledConstraintList,
    );

    $state->instanceHeadInScope = $savedInstanceHead;
    $state->instanceOwnMethods = $savedInstanceOwnMethods;
    $state->rigidVars = $savedRigidVars;
    $state->skolemScope = $savedSkolemScope;
    $state->popBinderScope();

    return $fn;
}

/**
 * The head a constraint was resolved at, as written, for the evidence a call
 * site carries.
 *
 * The declaration the solver picks names the head, because that is the spelling
 * the instance and its evidence symbol are written in. Building the instantiated
 * head instead would convert the whole required type at every reference, and a
 * generic `Rep` head is large -- a derived body mentions it once per field -- so
 * the instantiated form is reserved for the fallback, where no instance answers
 * the constraint and there is no declaration to name.
 */
function findProjectInstanceHeadAst(TypeCheckState $state, string $className, Type $requiredHead): Ast\TypeNode
{
    $declared = instanceDeclaredHeadAst($state, $className, $requiredHead);
    if ($declared !== null) {
        return $declared;
    }

    $normalized = prune($state, normalizeType($state, prune($state, $requiredHead), reduceFamilies: true));

    return internalTypeToAst($normalized, $state->subst);
}

/**
 * The head the instance for `$requiredHead` declares, for naming its evidence
 * function.
 *
 * An evidence symbol is named from the declaration (`instance C (F a)` gives a
 * name in terms of `F a`), and a reference to it has to spell the same name. The
 * instantiated constraint can differ from the declaration, and several
 * declarations can match one head (`C (K1 i (Maybe a))` and `C (K1 i a)` both
 * match `C (K1 i (Maybe Int))`), so the declaration is read from the instance
 * the solver actually picks: naming the instantiated head instead would build a
 * symbol no declaration defines.
 */
function instanceDeclaredHeadAst(TypeCheckState $state, string $className, Type $requiredHead): ?Ast\TypeNode
{
    $declared = findProjectInstanceRecord($state, $className, $requiredHead)['instance']['instanceHead'] ?? null;
    if ($declared instanceof Ast\TypeNode) {
        return $declared;
    }

    return $declared instanceof Type ? internalTypeToAst($declared, []) : null;
}

/**
 * The instance head type for a class's argument list: the single argument
 * itself for a one-parameter class, or the `__InstanceHead` marker applied to
 * all of them for a multi-parameter class. Arguments are pruned.
 *
 * @param list<Type> $args
 */
function constraintHeadType(TypeCheckState $state, array $args): Type
{
    if (\count($args) === 1) {
        return prune($state, $args[0]);
    }

    return new TCon(Ast\instanceHeadMarker(), \array_map(
        static fn (Type $arg): Type => prune($state, $arg),
        $args,
    ));
}

/**
 * The instance head type a class's constraint arguments denote, after the
 * argument count is checked against the class's parameter list.
 *
 * @param list<array{name: string, kind: array<string, mixed>}> $classParams
 * @param list<array<string, mixed>> $args
 */
function instanceHeadFromConstraintArgs(TypeCheckState $state, array $classParams, array $args, ?Ast\AstNode $at = null): Type
{
    if (\count($classParams) === 1) {
        return prune($state, $args[0] ?? throw typeFail($state, 'constraint missing class argument', $at));
    }

    if (\count($args) !== \count($classParams)) {
        throw typeFail($state, 'constraint class arity mismatch', $at);
    }

    return constraintHeadType($state, $args);
}

/** @param list<array<string, mixed>> $evidence */
