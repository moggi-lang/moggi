<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

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
use Moggi\Semantics\TypeExpr\TWord;
use Moggi\Semantics\TypeExpr\TWord16;
use Moggi\Semantics\TypeExpr\TWord32;
use Moggi\Semantics\TypeExpr\TWord64;
use Moggi\Semantics\TypeExpr\TWord8;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Ast\TypeNode;

use function Moggi\Semantics\TypeExpr\scheme;

require_once __DIR__ . '/type_core.php';
require_once __DIR__ . '/constraints.php';

/**
 * Scope a class's default bodies were written in.
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

    $scope = $state->classModuleScopes[$module] ?? null;

    return \is_array($scope['env'] ?? null) ? $scope['env'] : [];
}

/**
 * The type constructors the class module's default bodies may name.
 *
 * A default body is re-checked here, in the instance's module, so a type the
 * class module imported (`Endo`, `Dual`) is not in this module's type tables.
 * {@see installDefaultBodyTypes} installs them for the check.
 *
 * @param array<string, mixed> $classInfo
 * @return array{types: array<string, int|null>, synonyms: array<string, mixed>}
 */
function defaultBodyVisibleTypes(TypeCheckState $state, array $classInfo): array
{
    $module = $classInfo['module'] ?? '';
    $scope = $module === '' ? null : ($state->classModuleScopes[$module] ?? null);
    $visible = \is_array($scope) ? ($scope['visible'] ?? null) : null;
    if (! \is_array($visible)) {
        return ['types' => [], 'synonyms' => []];
    }

    return [
        'types' => \is_array($visible['types'] ?? null) ? $visible['types'] : [],
        'synonyms' => \is_array($visible['synonyms'] ?? null) ? $visible['synonyms'] : [],
    ];
}

/**
 * Make the class module's visible type constructors resolvable, and return what
 * has to be undone afterwards.
 *
 * @param array{types: array<string, int|null>, synonyms: array<string, mixed>} $visible
 * @return array{names: array<string, true>, kinds: list<string>, synonyms: list<string>}
 */
function installDefaultBodyTypes(TypeCheckState $state, array $visible): array
{
    $added = ['names' => [], 'kinds' => [], 'synonyms' => []];

    foreach ($visible['types'] as $name => $params) {
        if (isset($state->declaredTypeNames[$name])) {
            continue;
        }

        $state->declaredTypeNames[$name] = true;
        $added['names'][$name] = true;
        if ($params !== null && ! isset($state->kindEnv[$name])) {
            $state->kindEnv[$name] = Kinds\dataKind($params);
            $added['kinds'][] = $name;
        }
    }

    foreach ($visible['synonyms'] as $name => $info) {
        if (isset($state->typeSynonyms[$name])) {
            continue;
        }

        $state->typeSynonyms[$name] = $info;
        $added['names'][$name] = true;
        $added['synonyms'][] = $name;
    }

    return $added;
}

/** @param array{names: array<string, true>, kinds: list<string>, synonyms: list<string>} $added */
function removeDefaultBodyTypes(TypeCheckState $state, array $added): void
{
    foreach ($added['kinds'] as $name) {
        unset($state->kindEnv[$name]);
    }

    foreach ($added['synonyms'] as $name) {
        unset($state->typeSynonyms[$name]);
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
        $peeled = peelDictArrows($freshened['type'], count($freshened['constraints']));
        $schemes[$method->name] = scheme(
            prune($state, $peeled),
            [],
            $freshened['constraints'],
            count(expandConstraintsWithSuperclasses($state, $freshened['constraints'])),
        );
        $provided[$method->name] = true;
    }

    // Class methods with a parsed default implementation are available even
    // when the instance omits them. `deriving anyclass` relies on this
    // entirely: it declares an empty instance and every method comes from the
    // class defaults.
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
    validateInstance($state, $decl, $headType, $mapping);

    $className = $decl->class;
    $classInfo = $state->classes[$className];
    $functions = [];
    // The getter's context parameters, and the same constraints as written for
    // the method bodies (which expand them together with their own).
    $contextConstraints = instanceContextConstraints($state, $decl, $headType);
    $instanceConstraints = expandConstraintsWithSuperclasses($state, $contextConstraints);
    // Non-nullary context methods stay monomorphized for primitive ops (`==`); nullary
    // ones (`maxBound`) must not, and stock deriving skips ambient projections.
    $constraintEnv = $state->stockDeriving
        ? []
        : buildInstanceConstraintEnv($state, $decl->constraints, $headType);
    // Declared methods plus synthesized bodies for class defaults the instance
    // omits. Anything still missing is a hard error below.
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
        // The default body/params belong to the class registration and are
        // re-checked at every instance site; checking rewrites them in place,
        // so hand each site its own copy.
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
        // Eta-expand partial instance methods so dictionaries store a real arity-N function
        // instead of a nullary one returning a `__partial` cell.
        [$etaParams, $etaBody] = etaExpandInstanceMethod(
            $method->params,
            $method->body,
            peelDictArrows($freshened['type'], count($freshened['constraints'])),
        );
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
        // A default body was written in the class's module, so the names, types
        // and primops it uses are the ones in scope there. The body is re-checked
        // here, so hand it the scope it was written in.
        $defaulted = isset($defaultedMethods[$method->name]);
        $scope = $defaulted ? defaultBodyScope($state, $classInfo) : [];
        $installedTypes = $defaulted
            ? installDefaultBodyTypes($state, defaultBodyVisibleTypes($state, $classInfo))
            : ['names' => [], 'kinds' => [], 'synonyms' => []];
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
            );
        } finally {
            removeDefaultBodyTypes($state, $installedTypes);
        }
        $state->instanceMethodNames[$fn->name] = true;
        // Keep the polymorphic class-method scheme (`Bounded a => a`) so bare
        // uses like `maxBound` / `mempty = maxBound` still quantify over a fresh
        // constraint. Instance methods are dispatched via evidence dictionaries.
        $existing = $state->env[$fn->name] ?? null;
        if (!($existing?->classMethod)) {
            $state->env[$fn->name] = $registration['schemes'][$method->name];
        }
        $functions[] = $fn;
    }

    $providedMethods = [];
    foreach ($functions as $fn) {
        $providedMethods[$fn->name] = true;
    }

    foreach ($classInfo['methods'] as $methodName => $_) {
        if (!isset($providedMethods[$methodName])) {
            throw typeFail(
                $state,
                "instance for `{$className}` missing method `{$methodName}`",
                $decl,
            );
        }
    }

    // `{-# MINIMAL #-}` is a disjunction of conjunctions: an alternative whose methods all
    // have defaults would compile into a mutual recursion that never reaches a value.
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
        'head' => $headType,
        'module' => $state->currentModule ?? '',
    ];

    $headAst = internalTypeToAst($headType, []);
    $evidenceIndex = count($state->instanceEvidence);
    $methodMap = [];
    foreach ($functions as $fn) {
        if ($fn->instanceMethod) {
            $methodMap[$fn->name] = $fn->name;
        }
    }
    // Prefer the declared instance head for stable evidence naming so
    // findProjectInstanceHeadAst (which returns the project-instance AST)
    // agrees with the registered evidence function name.
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
            $instanceConstraints,
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

    /** @var array<int, array<string, string>> $renamesByEvidence */
    $renamesByEvidence = [];
    foreach ($bySurface as $surfaceName => $entries) {
        $collidesWithFree = isset($freeFnNames[$surfaceName]);
        if (count($entries) <= 1 && !$collidesWithFree) {
            continue;
        }

        foreach ($entries as $entry) {
            $evidence = $state->instanceEvidence[$entry['evidenceIndex']];
            $headAst = $evidence['headAst'] ?? null;
            $className = $evidence['className'] ?? null;
            if (!$headAst instanceof Ast\TypeNode || !\is_string($className)) {
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
        if ($surfaceToIr === [] || !isset($surfaceToIr[$entry['surfaceName']])) {
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
            if (isset($surfaceToIr[$expr->name]) && !isset($bound[$expr->name])) {
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

function validateInstance(TypeCheckState $state, Ast\InstanceDecl $decl, Type $headType, array $mapping): void
{
    validateInstanceSuperclasses($state, $decl, $mapping);
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
        if (!isset($required[$eq->name])) {
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
        if (!isset($seen[$famName])) {
            throw typeFail(
                $state,
                "instance for `{$decl->class}` missing associated type `{$famName}`",
                $decl,
            );
        }
    }
}

function validateInstanceSuperclasses(TypeCheckState $state, Ast\InstanceDecl $decl, array $mapping): void
{
    $classInfo = $state->classes[$decl->class] ?? null;
    if ($classInfo === null) {
        return;
    }

    foreach ($classInfo['superclasses'] as $super) {
        if (!$super instanceof Ast\TypeApp || !$super->con instanceof Ast\TypeCon) {
            throw typeFail($state, 'class superclass must be a type class application', $decl);
        }

        $superClass = $super->con->name;
        $superClassInfo = $state->classes[$superClass]
            ?? throw typeFail($state, "unknown superclass `{$superClass}`", $decl);

        if ($superClassInfo['params'] === []) {
            if (!findProjectInstance($state, $superClass, new TUnit())) {
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

        // Constrained peer: `Num a => Monoid (Sum a)` may rely on `Num a => Semigroup (Sum a)`.
        if (peerInstanceCoversSuperclass($state, $superClass, $superHead, $decl)) {
            continue;
        }

        if (!findProjectInstance($state, $superClass, $superHead)) {
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
 */
function peerInstanceCoversSuperclass(
    TypeCheckState $state,
    string $superClass,
    Type $superHead,
    Ast\InstanceDecl $decl,
): bool {
    if ($decl->constraints === []) {
        return false;
    }

    $headKey = instanceHeadIndexKeyFromType($superHead);
    // Concrete heads: empty head-bucket ⇒ no peer. Full-class fallback re-unifies
    // against every TupleN-sized head (pathological with arity 64).
    $candidates = $headKey !== '*'
        ? ($state->projectInstancesByClassHead[$superClass][$headKey] ?? [])
        : ($state->projectInstancesByClass[$superClass] ?? []);

    foreach ($candidates as $instance) {
        if (!instanceHeadsMatch($state, $superHead, $instance['head'])) {
            continue;
        }

        $peerConstraints = $instance['constraints'] ?? [];
        if ($peerConstraints === []) {
            return true;
        }

        if (instanceConstraintsCoveredByDecl($state, $peerConstraints, $decl->constraints)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<Ast\AstNode> $needed
 * @param list<Ast\AstNode> $available
 */
function instanceConstraintsCoveredByDecl(TypeCheckState $state, array $needed, array $available): bool
{
    $available = expandConstraintsWithImpliedSuperclasses($state, $available);

    foreach ($needed as $need) {
        if (!$need instanceof Ast\TypeApp || !$need->con instanceof Ast\TypeCon) {
            return false;
        }
        $found = false;
        foreach ($available as $have) {
            if (!$have instanceof Ast\TypeApp || !$have->con instanceof Ast\TypeCon) {
                continue;
            }
            if ($have->con->name !== $need->con->name) {
                continue;
            }
            if (count($have->args) !== count($need->args)) {
                continue;
            }
            $argsMatch = true;
            foreach ($need->args as $i => $needArg) {
                $haveArg = $have->args[$i];
                if ($needArg instanceof Ast\TypeVar && $haveArg instanceof Ast\TypeVar) {
                    if ($needArg->name !== $haveArg->name) {
                        $argsMatch = false;
                        break;
                    }
                    continue;
                }
                if ($needArg instanceof Ast\TypeCon && $haveArg instanceof Ast\TypeCon
                    && $needArg->name === $haveArg->name) {
                    continue;
                }
                $argsMatch = false;
                break;
            }
            if ($argsMatch) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return false;
        }
    }

    return true;
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
        $constraint = array_pop($queue);
        if (!$constraint instanceof Ast\TypeApp || !$constraint->con instanceof Ast\TypeCon) {
            continue;
        }

        $key = $constraint->con->name . "\0" . count($constraint->args);
        foreach ($constraint->args as $arg) {
            $key .= "\0" . ($arg instanceof Ast\TypeVar
                ? 'v:' . $arg->name
                : ($arg instanceof Ast\TypeCon ? 'c:' . $arg->name : '?'));
        }
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
            if (!$super instanceof Ast\TypeApp || !$super->con instanceof Ast\TypeCon) {
                continue;
            }
            $substituted = substituteTypeAstParams($super, $mapping);
            if (!$substituted instanceof Ast\TypeApp) {
                continue;
            }
            $out[] = $substituted;
            $queue[] = $substituted;
        }
    }

    return $out;
}

function validateNoDuplicateInstance(TypeCheckState $state, Ast\InstanceDecl $decl, Type $headType): void
{
    $headKey = instanceHeadIndexKeyFromType($headType);

    foreach ($state->checkedInstances as $instance) {
        if ($instance['class'] !== $decl->class) {
            continue;
        }
        if (instanceHeadIndexKeyFromType($instance['head']) !== $headKey) {
            continue;
        }

        if (instanceHeadsMatch($state, $headType, $instance['head'])) {
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

        if (instanceHeadsMatch($state, $headType, $instance['head'])) {
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

function instanceHeadIndexKeyFromType(Type $head): string
{
    if ($head instanceof TCon) {
        return $head->name;
    }
    if ($head instanceof TUnit) {
        return '()';
    }
    if ($head instanceof TPromoted) {
        return $head->name;
    }
    // Primitives must not share the `*` bucket — that re-unified every Eq Int
    // against Eq Word / Word8 / … during duplicate checks.
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
    // Project-instance heads are AST nodes, not internal types. Required-head
    // keys must be freshen-stable (see instanceLookupKey) so polymorphic
    // TupleN lookups reuse mappings instead of re-unifying every time.
    $instanceHeadKey = $instance['head'] instanceof Type
        ? instanceLookupKey($instance['class'], $instance['head'])
        : ($instance['head'] instanceof Ast\AstNode
            ? instanceHeadIndexKeyFromAst($instance['head'])
            : '?');

    return ($instance['module'] ?? '') . "\0"
        . $instance['class'] . "\0"
        . $instanceHeadKey . "\0"
        . instanceLookupKey($instance['class'], $requiredHead);
}

function findProjectInstance(TypeCheckState $state, string $className, Type $requiredHead, array $visited = []): bool
{
    $requiredHead = prune($state, $requiredHead);
    // Cache key must use the same normalization as findProjectInstanceRecord
    // (reduce Rep/families); otherwise a hit on an unreduced key disagrees with
    // HeadAst naming and we emit __ev_* hashes for concrete trees that were never
    // generated (MissingMethod at runtime).
    $normalized = prune($state, normalizeType($state, $requiredHead, reduceFamilies: true));
    $lookupKey = instanceLookupKey($className, $normalized);
    if (\array_key_exists($lookupKey, $state->instanceLookupCache)) {
        return $state->instanceLookupCache[$lookupKey];
    }

    $found = findProjectInstanceRecord($state, $className, $requiredHead, $visited) !== null;
    $state->instanceLookupCache[$lookupKey] = $found;

    return $found;
}

/**
 * @param array<string, true> $visited
 * @return array{instance: array<string, mixed>, mapping: array<string, mixed>}|null
 */
function findProjectInstanceRecord(TypeCheckState $state, string $className, Type $requiredHead, array $visited = []): ?array
{
    $requiredHead = prune($state, $requiredHead);
    // Reduce associated families (`Rep Foo`) so `C (Rep Foo)` can match concrete
    // instances; unify alone keeps `Rep` stuck on a polymorphic `Rep a`.
    $requiredHead = normalizeType($state, $requiredHead, reduceFamilies: true);
    $requiredHead = prune($state, $requiredHead);
    $lookupKey = instanceLookupKey($className, $requiredHead);
    if (isset($visited[$lookupKey])) {
        return null;
    }
    $visited[$lookupKey] = true;

    // One-way matching: a wanted constraint with a bare type-variable head
    // (e.g. `Eq a`) must not select concrete instances like `Eq (Min a)` by
    // unifying the variable with `Min …` — that loops on `C a => C (F a)`.
    if ($requiredHead instanceof TVar) {
        return null;
    }

    $headKey = instanceHeadIndexKeyFromType($requiredHead);
    // Concrete heads: empty head-bucket ⇒ no instance. Falling back to the full
    // class list re-introduces O(|instances|) unify against Tuple64-sized heads.
    $candidates = $headKey !== '*'
        ? ($state->projectInstancesByClassHead[$className][$headKey] ?? [])
        : ($state->projectInstancesByClass[$className] ?? []);

    foreach ($candidates as $instance) {
        if (!instanceHeadsMatch($state, $requiredHead, $instance['head'])) {
            continue;
        }

        $mapping = instanceMappingForUse($state, $instance, $requiredHead);
        if ($mapping === null || !instanceContextSatisfied($state, $instance, $mapping, $visited)) {
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
        if (!isset($rename[$type->name])) {
            $rename[$type->name] = 'v' . $n++;
        }

        return $rename[$type->name];
    }

    if ($type instanceof TCon) {
        if ($type->args === []) {
            return $type->name;
        }
        $args = [];
        foreach ($type->args as $arg) {
            $args[] = freshenStableTypeKeyRec($arg, $rename, $n);
        }

        return $type->name . '(' . join(',', $args) . ')';
    }

    if ($type instanceof TArrow) {
        return '(' . freshenStableTypeKeyRec($type->from, $rename, $n)
            . ')->(' . freshenStableTypeKeyRec($type->to, $rename, $n) . ')';
    }

    if ($type instanceof TUnit) {
        return '()';
    }

    if ($type instanceof TPromoted) {
        if ($type->args === []) {
            return "'" . $type->name;
        }
        $args = [];
        foreach ($type->args as $arg) {
            $args[] = freshenStableTypeKeyRec($arg, $rename, $n);
        }

        return "'" . $type->name . '(' . join(',', $args) . ')';
    }

    return typeToString($type);
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

    // Only a shape mismatch is remembered, with the head's type variables normalized away;
    // a hit is stated in terms of the head it was built from.
    $cacheKey = instanceMappingCacheKey($instance, $requiredHead);
    if (\array_key_exists($cacheKey, $state->instanceMappingCache)) {
        return null;
    }

    // Bind head vars from the *wanted* head before the subst frame so they
    // survive popSubstFrame (unify inside the frame would otherwise leave
    // ephemeral bindings that evaporate).
    $wantedHead = prune($state, $requiredHead);
    $headVarMapping = matchInstanceHeadVars($state, $instance['head'], $wantedHead);
    if ($headVarMapping === null) {
        $state->instanceMappingCache[$cacheKey] = null;

        return null;
    }

    // Prefer structural head match + param binding. Re-elaborating the AST head
    // (astType + freshen + unify) is linear in TupleN arity and was the bulk of
    // Traversable superclass checks; matchInstanceHeadVars already validated shape.
    try {
        $mapping = instanceParamMapping($state, $classInfo['params'], $wantedHead, $instance['head']);
        $mapping['__headVars'] = $headVarMapping;

        return $mapping;
    } catch (TypeError) {
        // Kind failures on exotic heads: fall back to unify path below.
    }

    pushSubstFrame($state);
    try {
        $instanceHeadType = freshenTypeVars($state, astType($state, $instance['head']));
        unify($state, $requiredHead, $instanceHeadType, null);

        $mapping = instanceParamMapping($state, $classInfo['params'], prune($state, $requiredHead), $instance['head']);
        $mapping['__headVars'] = $headVarMapping;

        return $mapping;
    } catch (TypeError) {
        // Structural matching already bound head vars. Kind/unify failures on
        // polymorphic heads (e.g. `M1 D d f` vs MetaData/Symbol apps) should
        // not reject an otherwise valid instance.
        $mapping = instanceParamMapping($state, $classInfo['params'], prune($state, $requiredHead), $instance['head']);
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
        $ctxHead = count($constraint->args) === 1
            ? prune($state, $constraint->args[0])
            : new TCon('__InstanceHead', $constraint->args);
        // Polymorphic context obligations (`Eq a` on `Eq (Min a)`) are discharged by the
        // caller's context, not by searching concrete instances for a bare variable.
        if ($ctxHead instanceof TVar) {
            continue;
        }
        if (!findProjectInstance($state, $constraint->class, prune($state, $ctxHead), $visited)) {
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
    /** @var array<string, Type> $headVars */
    $headVars = $mapping['__headVars'] ?? [];
    $context = [];

    foreach ($instance['constraints'] ?? [] as $constraintAst) {
        if (!$constraintAst instanceof Ast\TypeApp || !$constraintAst->con instanceof Ast\TypeCon) {
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
        // Some promoted nullaries elaborate to TCon.
        if ($target instanceof TCon && $target->name === $pattern->name && $target->args === []) {
            return [];
        }

        return null;
    }

    if ($pattern instanceof Ast\TypeCon) {
        // Nullary constructors compare by name when the target is concrete; target args need
        // not be empty (D/C/S under TypeApp), but different names must not match.
        if ($target instanceof TCon) {
            return $target->name === $pattern->name ? [] : null;
        }
        if ($target instanceof TPromoted) {
            return $target->name === $pattern->name ? [] : null;
        }
        // Non-concrete targets: keep prior lenient behavior for prim/synonym heads.
        return [];
    }

    if ($pattern instanceof Ast\TypeApp) {
        // Instance heads are often left-nested (`((M1 D) d) f`); flatten so we
        // match the same shape as internal TCon apps (`M1 D d f`).
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
            if ($target->name !== $headName || count($patternArgs) !== count($target->args)) {
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

        if (!$target instanceof TCon || $target->name !== $headName) {
            return null;
        }
        if (count($patternArgs) !== count($target->args)) {
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

    return null;
}

/**
 * @return array{0: Ast\TypeNode, 1: list<Ast\TypeNode>}|null
 */
function flattenInstanceHeadTypeApp(Ast\TypeNode $type): ?array
{
    // Iterative: left-nested `((Tuple64 a1) a2)…` is depth-N; the previous
    // recursive `[...$inner, ...$args]` rebuild was O(N²) and dominated
    // Traversable superclass checks for Tuple32+.
    $args = [];
    while ($type instanceof Ast\TypeApp) {
        for ($i = count($type->args) - 1; $i >= 0; $i--) {
            $args[] = $type->args[$i];
        }
        $type = $type->con;
    }

    if (
        $type instanceof Ast\TypeCon
        || $type instanceof Ast\TypeVar
        || $type instanceof Ast\TypePromoted
    ) {
        return [$type, array_reverse($args)];
    }

    return null;
}

function instanceHeadsMatch(TypeCheckState $state, Type $requiredHead, Ast\AstNode|Type $instanceHeadAst): bool
{
    // A checked-instance head is an internal type object, never an AST head.
    // astType previously failed to re-parse it and this always returned false;
    // preserve that without hitting a (fatal) argument type error.
    if ($instanceHeadAst instanceof Type) {
        return false;
    }

    // Unify-based match (not matchInstanceHeadVars alone): the TypeCon fallback
    // that returns [] for non-TCon targets made every TWord/TInt head match any
    // nullary TypeCon, so `Eq Word` collided with `Eq Int` / `Eq Word8`.
    pushSubstFrame($state);
    try {
        $other = freshenTypeVars($state, astType($state, $instanceHeadAst));
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

    if (count($classParams) === 0) {
        return [];
    }

    if (count($classParams) === 1) {
        $expectedKind = Kinds\classParamKind($state, $classParams[0], $at);
        Kinds\assertKind($state, $headType, $expectedKind, [], $at);

        return [$paramNames[0] => $headType];
    }

    if (!$headType instanceof TCon) {
        throw typeFail($state, 'instance head must be a type constructor', $at);
    }

    if (count($headType->args) !== count($classParams)) {
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
        if (!$constraint instanceof Ast\TypeApp || !$constraint->con instanceof Ast\TypeCon) {
            throw typeFail($state, 'instance constraint must be a type class application', $decl);
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
        if (!$constraint instanceof Ast\TypeApp || !$constraint->con instanceof Ast\TypeCon) {
            throw typeFail($state, 'instance constraint must be a type class application');
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
            $mappedType = prune($state, substitute($methodInfo['type'], $mapping));
            // Nullary methods must stay as polymorphic class methods so
            // `maxBound` / `mempty = maxBound` can select a different head.
            if (!$mappedType instanceof TArrow) {
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

    if (count($params) >= $arity) {
        return [$params, $body];
    }

    $i = count($params);
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
            if ($super instanceof Ast\TypeApp && $super->con instanceof Ast\TypeCon) {
                $pending[] = $super->con->name;
            }
        }
    }

    return $names;
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

    // Instance context dictionaries (`Bounded a` on `Bounded (Min a)`) plus any
    // method-local constraints become leading evidence parameters.
    $constraints = expandConstraintsWithSuperclasses($state, [
        ...$instanceConstraints,
        ...$methodUserConstraints,
    ]);
    $peeledType = peelDictArrows($expectedType, count($methodUserConstraints));

    // Match checkFunction: dict params for every expanded constraint (including
    // superclasses), then the user-facing parameters.
    $fnType = $peeledType;
    foreach (array_reverse($constraints) as $constraint) {
        $fnType = new TArrow(new TCon('__Dict_' . $constraint->class), $fnType);
    }

    if ($constraints !== []) {
        $evidencePatterns = [];
        foreach ($constraints as $constraint) {
            $evidencePatterns[] = new Ast\PatVar($constraint->evidence);
        }
        $fn->params = [...$evidencePatterns, ...$fn->params];
        $fn->constraints = $constraints;
    }

    $expected = $fnType;
    $paramTypes = [];
    foreach ($fn->params as $param) {
        if (!$expected instanceof TArrow) {
            throw typeFail($state, "method `{$fn->name}` has too many parameters", $fn);
        }
        $paramTypes[] = $expected->from;
        $expected = $expected->to;
    }
    $bodyExpected = $expected;

    foreach ($fn->params as $i => $param) {
        [, $env] = bindPattern($state, $param, $paramTypes[$i], $env, generalize: false);
    }

    $savedConstraintMethods = $state->constraintMethods;
    $savedConstraintMethodAmbiguities = $state->constraintMethodAmbiguities;
    $state->constraintMethods = [];
    $state->constraintMethodAmbiguities = [];

    if (!$state->stockDeriving) {
        foreach ($constraints as $constraint) {
            $classInfo = $state->classes[$constraint->class];
            $mapping = [];
            foreach ($classInfo['params'] as $i => $param) {
                $mapping[$param['name']] = constructorMappingValue($state, $param, $constraint->args[$i]);
            }

            foreach ($classInfo['methods'] as $methodName => $methodInfo) {
                $nestedUserConstraints = \array_map(
                    static function (Ast\PendingConstraint $nested) use ($mapping): Ast\PendingConstraint {
                        return new Ast\PendingConstraint(
                            $nested->class,
                            \array_map(
                                static fn (Type $arg): Type => substitute($arg, $mapping),
                                $nested->args,
                            ),
                            $nested->evidence,
                            $nested->implicit,
                            $nested->instanceHeadAst,
                        );
                    },
                    $methodInfo['userConstraints'] ?? [],
                );
                $methodType = peelDictArrows(
                    substitute($methodInfo['type'], $mapping),
                    count($nestedUserConstraints),
                );
                // Nullary ambient methods (`maxBound :: a`) must not shadow the
                // polymorphic class method: `mempty = maxBound` in
                // `Monoid (Min a)` needs `Bounded (Min a)`, not ambient `Bounded a`.
                if (!prune($state, $methodType) instanceof TArrow) {
                    continue;
                }
                $info = [
                    'class' => $constraint->class,
                    'method' => $methodName,
                    'evidence' => $constraint->evidence,
                    'type' => $methodType,
                    'userConstraints' => $nestedUserConstraints,
                    'constraintHead' => prune($state, $constraint->args[0]),
                    'implicit' => $constraint->implicit,
                    'instanceHeadAst' => $constraint->instanceHeadAst,
                ];

                if (isset($state->constraintMethods[$methodName])) {
                    $existing = $state->constraintMethods[$methodName];
                    if (($existing['class'] ?? null) !== $info['class']) {
                        // Truly ambiguous: same name from different classes.
                        unset($state->constraintMethods[$methodName]);
                        $state->constraintMethodAmbiguities[$methodName] = true;
                        unset($env[$methodName]);
                    } elseif (($existing['evidence'] ?? null) !== $info['evidence']) {
                        // Same class, multiple dictionaries (`Show a`, `Show b`):
                        // drop projection and keep the polymorphic class method so
                        // pending constraints pick the right ambient dict.
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
    // Stock deriving keeps polymorphic class methods in `$env` so field
    // `(==)` calls emit pending `Eq τ` constraints. Merging ambient
    // projections here would overwrite them with monomorphic `a -> a -> Bool`.
    if ($methodConstraintEnv !== [] && !$state->stockDeriving) {
        $env = [...$env, ...$methodConstraintEnv];
    }

    // A method of the instance's own class (or a superclass) is never the context
    // dictionary's method — projecting it would hand the body the wrong dictionary.
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
                // Another class owning the same method name keeps its scheme
                // (the ambiguity the module machinery already tracks).
                if ($existing === null
                    || !($existing->classMethod ?? false)
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

    // The polymorphic class-method scheme wins over the instance's own, so a call in the
    // body dispatches through the dictionary (including the recursive one).
    if ($savedClassMethod !== null) {
        $env[$fn->name] = $savedClassMethod;
    } elseif (!isset($env[$fn->name])) {
        // The member's own parameters, as written: a recursive call inside the
        // body re-expands them and must land on exactly `$constraints`.
        $env[$fn->name] = scheme(
            $peeledType,
            [],
            [...$instanceConstraints, ...$methodUserConstraints],
            count($constraints),
        );
    }

    $bodyType = inferExpr($state, $fn->body, $env);
    $state->constraintMethods = $savedConstraintMethods;
    $state->constraintMethodAmbiguities = $savedConstraintMethodAmbiguities;
    unify($state, $bodyType, $bodyExpected, $fn);
    defaultAmbiguousNumericVars($state, $fn->body, quantifiedVars($state, $peeledType));
    // Before the evidence pass: an operator whose operands the method's type pinned to a
    // primitive numeric type is emitted as that type's operation.
    resolveDeferredNativeInfixes($state, $fn->body);
    resolvePendingEvidenceInExpr($state, $fn->body);
    tryResolveValueEvidence($state, $fn->body);
    $fn->body = elaborateNumericLiterals($state, $fn->body);
    $state->ambientConstraints = $savedAmbientConstraints;
    $state->ambientConstraintsByClass = $savedAmbientByClass;
    assertNoPendingConstraintsInExpr($state, $fn->body, $fn);
    zonkInferredTypesInExpr($state, $fn->body);
    foreach ($fn->params as $param) {
        zonkInferredTypesInPattern($state, $param);
    }

    resolveMachineIntPow($state, $fn->body);
    $fn->body = rewriteIntrinsicApplies($fn->body);
    $wrapper = trivialIntrinsicWrapper($fn);
    if ($wrapper !== null) {
        $fn->intrinsicWrapper = $wrapper;
    }
    // Deliberately not in `intrinsicWrappers`: that table is keyed by the name a source
    // uses, and an instance method is only reachable through its dictionary.

    $fn->type = internalTypeToAst(prune($state, $fnType), $state->subst);
    $state->instanceHeadInScope = $savedInstanceHead;
    $state->instanceOwnMethods = $savedInstanceOwnMethods;

    return $fn;
}

function findProjectInstanceHeadAst(TypeCheckState $state, string $className, Type $requiredHead): Ast\TypeNode
{
    // Evidence factory names are keyed by the *declared* instance head, not the wanted
    // concrete tree; the wanted head caused MissingMethod.
    $match = findProjectInstanceRecord($state, $className, $requiredHead);
    if ($match !== null) {
        $head = $match['instance']['head'] ?? null;
        if ($head instanceof Ast\TypeNode) {
            return $head;
        }
        if ($head instanceof Type) {
            return internalTypeToAst($head, []);
        }
    }

    $normalized = prune($state, normalizeType($state, prune($state, $requiredHead), reduceFamilies: true));

    return internalTypeToAst($normalized, $state->subst);
}

/** @param list<array{name: string, kind: array<string, mixed>}> $classParams @param list<array<string, mixed>> $args */

function instanceHeadFromConstraintArgs(TypeCheckState $state, array $classParams, array $args, ?Ast\AstNode $at = null): Type
{
    if (count($classParams) === 1) {
        return prune($state, $args[0] ?? throw typeFail($state, 'constraint missing class argument', $at));
    }

    if (count($args) !== count($classParams)) {
        throw typeFail($state, 'constraint class arity mismatch', $at);
    }

    return new TCon('__InstanceHead', \array_map(
        static fn (Type $arg): Type => prune($state, $arg),
        $args,
    ));
}

/** @param list<array<string, mixed>> $evidence */
