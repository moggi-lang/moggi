<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;
use function Moggi\Semantics\TypeExpr\scheme;

/**
 * Typecheck a let/where binding list with recursive groups (SCCs).
 *
 * @param list<Ast\Binding> $bindings
 * @param array<string, Scheme> $env
 * @return array<string, Scheme>
 */
function inferBindingGroups(TypeCheckState $state, array $bindings, array $env, bool $generalize): array
{
    $local = $env;
    foreach (bindingGroupSccs($bindings) as $component) {
        $group = [];
        foreach ($component['indices'] as $index) {
            $group[] = $bindings[$index];
        }
        $groupGeneralize = $generalize && ! isRestrictedBindingGroup($group);
        $local = $component['recursive']
            ? inferRecursiveBindings($state, $group, $local, $groupGeneralize)
            : inferSequentialBindings($state, $group, $local, $groupGeneralize);
    }

    return $local;
}

/**
 * Whether a let/where group is *restricted* in base's sense: a single pattern
 * binding -- `x = e`, `f = \p -> e`, `(a, b) = e` -- with no type signature.
 *
 * Such a group is not generalized: its constrained type variables stay
 * monomorphic, take the dictionaries of the enclosing declaration, and are
 * quantified there if its type mentions them. `f p = e` (arguments on the left)
 * and a binding that spells a signature out are unrestricted and do get a
 * scheme of their own.
 *
 * @param list<Ast\Binding> $group
 */
function isRestrictedBindingGroup(array $group): bool
{
    if (\count($group) !== 1) {
        return false;
    }

    $binding = $group[0];
    if ($binding->functionBinding) {
        return false;
    }

    return ! ($binding->value instanceof Ast\TypeAsc
        || $binding->value instanceof Ast\TypeConstrained);
}

/**
 * @param list<Ast\Binding> $bindings
 * @param array<string, Scheme> $env
 * @return array<string, Scheme>
 */
function inferSequentialBindings(TypeCheckState $state, array $bindings, array $env, bool $generalize): array
{
    $local = $env;
    foreach ($bindings as $binding) {
        $annotatedConstraints = localConstrainedAnnotation($binding->value);
        if ($annotatedConstraints !== null) {
            [$binding->value, $valueType, $constraints] = abstractAnnotatedLocalBinding(
                $state,
                $binding->value,
                $local,
            );
        } else {
            $valueType = inferExpr($state, $binding->value, $local);
            if ($generalize) {
                $binding->value = elaborateNumericLiterals($state, $binding->value);
            }
            $constraints = pendingConstraintsDeep($binding->value, $state);
            if ($generalize && $constraints !== []) {
                $solved = solveConstraints($state, $constraints);
                [$binding->value] = abstractLocalConstraints(
                    $state,
                    $binding->value,
                    $solved->dicts,
                );
            }
        }
        [, $local] = bindPattern(
            $state,
            $binding->pattern,
            $valueType,
            $local,
            generalize: $generalize || $annotatedConstraints !== null,
            constraints: $constraints,
        );
    }

    return $local;
}

/**
 * The constrained type annotation on a local binding value, if it has one.
 *
 * `go :: C a => a -> T` parses as a `TypeAsc` whose type is a
 * `TypeConstrained`; such a binding needs dictionary parameters exactly like a
 * top-level function of the same signature.
 */
function localConstrainedAnnotation(Ast\AstNode $value): ?Ast\TypeConstrained
{
    if ($value instanceof Ast\TypeAsc && $value->type instanceof Ast\TypeConstrained) {
        return $value->type;
    }

    return null;
}

/**
 * The scheme and body type an annotated local binding introduces.
 *
 * A constrained signature is a function of its dictionaries, so its scheme
 * carries the annotation's own constraints -- unexpanded, the list a use site
 * expands -- and the expanded list's length as its runtime arity. The body type
 * does not include the `__Dict_*` arrows `astType` puts in front of a
 * constrained signature, because the constraints travel in the scheme instead.
 *
 * A recursive member is installed this way before any body is checked, so its
 * own recursive calls forward the dictionaries its annotation promises.
 *
 * @param array<string, Scheme> $env
 * @return array{0: Scheme, 1: Type}
 */
function localAnnotationScheme(TypeCheckState $state, Ast\TypeNode $annot, Ast\AstNode $at, array $env): array
{
    if (! $annot instanceof Ast\TypeConstrained) {
        $body = astType($state, $annot);

        return [scheme($body, schemeBoundVars($body, $env, [])), $body];
    }

    [, $bodyTypeAst] = splitTypeAst($annot);
    $user = parseConstraints($state, $annot->constraints, $at);
    applyConstraintVarKinds($state, $user);
    $body = astType($state, $bodyTypeAst);

    $dicts = solveConstraints($state, $user)->dicts;

    return [
        scheme($body, schemeBoundVars($body, $env, $user), $user, \count($dicts), $dicts),
        $body,
    ];
}

/** Surface parameter type nodes of a (possibly curried) function type AST. */
function functionParamTypeAsts(Ast\TypeNode $type): array
{
    $params = [];
    $cursor = $type;
    while ($cursor instanceof Ast\TypeArrow) {
        $params[] = $cursor->from;
        $cursor = $cursor->to;
    }

    return $params;
}

/**
 * Check `go :: C a => a -> T` (a constrained local signature) the way a
 * top-level function is checked: the annotation's constraints become leading
 * dictionary parameters, the function's own parameters take the annotation's
 * types, and the body resolves its evidence through **those** dictionaries.
 *
 * Ownership is what keeps the group polymorphic. The annotation's variables are
 * the group's own: the body is checked against the parameters allocated here, so
 * nothing inside it can reach the enclosing declaration's evidence and tie the
 * two vocabularies together. A use site then wants the group's dictionaries at
 * the types it instantiates, which is where an enclosing inferred declaration
 * learns the obligation -- from the call, as a wanted constraint at its own
 * checkpoint, not from the group's body.
 *
 * The returned constraints are the annotation's own, superclasses unexpanded:
 * the parameters are allocated for the expanded list, while the binding's
 * scheme keeps this one so a use site expands it exactly once.
 *
 * @param array<string, Scheme> $env
 * @return array{0: Ast\AstNode, 1: Type, 2: list<Ast\PendingConstraint>}
 */
function abstractAnnotatedLocalBinding(TypeCheckState $state, Ast\AstNode $value, array $env): array
{
    if (! $value instanceof Ast\TypeAsc || ! $value->type instanceof Ast\TypeConstrained) {
        throw new \InvalidArgumentException('abstractAnnotatedLocalBinding expects a constrained type annotation');
    }

    [$constraintAsts, $bodyTypeAst] = splitTypeAst($value->type);
    $userConstraints = parseConstraints($state, $constraintAsts, $value);
    $constraints = solveConstraints($state, $userConstraints)->dicts;
    applyConstraintVarKinds($state, $constraints);

    [$params, $abstracted, $dictTypes] = allocateDictParams($state, $constraints);

    $ambient = $abstracted;

    $inner = $value->expr;
    if ($inner instanceof Ast\Lambda) {
        $paramAsts = functionParamTypeAsts($bodyTypeAst);
        foreach ($inner->params as $i => $param) {
            if (
                $i < \count($paramAsts)
                && $param instanceof Ast\LambdaParam
                && $param->type === null
            ) {
                $param->type = $paramAsts[$i];
            }
        }
    }

    $savedMethods = [
        $state->constraintMethods,
        $state->constraintMethodAmbiguities,
        $state->constraintMethodCandidates,
    ];
    $savedAllocation = $state->allocation;
    $saved = installAmbientConstraints($state, $ambient);

    try {
        $state->constraintMethods = [];
        $state->constraintMethodAmbiguities = [];
        $state->constraintMethodCandidates = [];
        $state->allocation = DeclarationAllocation::ofSolved($userConstraints, $abstracted);
        $innerEnv = $env;
        installConstraintMethods($state, $abstracted, $innerEnv);

        $valueType = inferExpr($state, $inner, $innerEnv);
        $bodyExpected = astType($state, $bodyTypeAst);
        unify($state, $valueType, $bodyExpected, $value);
        $valueType = prune($state, $bodyExpected);

        $inner = resolvePendingEvidenceInExpr($state, $inner);
        $inner = tryResolveValueEvidence($state, $inner);
        $inner = elaborateNumericLiterals($state, $inner);

        assertNoPendingConstraintsInExpr($state, $inner, $value);
    } finally {
        restoreAmbientConstraints($state, $saved);
        [$state->constraintMethods, $state->constraintMethodAmbiguities, $state->constraintMethodCandidates] = $savedMethods;
        $state->allocation = $savedAllocation;
    }

    $lambda = wrapDictLambda($state, $inner, $params, $dictTypes);

    return [$lambda, $valueType, $userConstraints];
}

/**
 * Turn an inferred local binding into a function of its class constraints.
 *
 * A let/where binding with a constrained type is checked the same way a
 * top-level constrained function is: the constraints become leading dictionary
 * parameters, and references in the RHS that need those dictionaries resolve to
 * the new parameters instead of demanding a concrete instance. Without this a
 * local helper such as `where go = show` could only ever be used at the single
 * type its body happened to fix.
 *
 * `$constraints` is expanded with its superclasses, the list the parameters are
 * allocated for; the binding's scheme keeps the unexpanded list its use sites
 * expand, so both sides build the same dictionaries in the same order.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return array{0: Ast\AstNode, 1: list<Ast\PendingConstraint>}
 */
function abstractLocalConstraints(TypeCheckState $state, Ast\AstNode $rhs, array $constraints): array
{
    [$params, $abstracted, $dictTypes] = allocateDictParams($state, $constraints);

    $saved = installAmbientConstraints($state, $abstracted);

    try {
        $rhs = resolvePendingEvidenceInExpr($state, $rhs);
        $rhs = tryResolveValueEvidence($state, $rhs);
        $rhs = elaborateNumericLiterals($state, $rhs);
    } finally {
        restoreAmbientConstraints($state, $saved);
    }

    $lambda = wrapDictLambda($state, $rhs, $params, $dictTypes);

    return [$lambda, $abstracted];
}

/**
 * The constraints a recursive binding group is generalized over: the union of
 * the obligations its members' bodies carry, one dictionary per class and type.
 *
 * A group is generalized as a whole, because its members share the variables
 * their bodies tie together: `go` calling itself at the variable its caller
 * pinned is one obligation, not one per member. Members with a signature are
 * left out -- their dictionaries come from the annotation.
 *
 * @param list<Ast\Binding> $bindings
 * @param array<int, list<Ast\PendingConstraint>> $bodyConstraints
 * @param array<string, true> $annotated
 * @return list<Ast\PendingConstraint>
 */
function recursiveGroupConstraints(
    TypeCheckState $state,
    array $bindings,
    array $bodyConstraints,
    array $annotated,
): array {
    $merged = [];
    $seen = [];
    foreach ($bindings as $bi => $binding) {
        if (! $binding->pattern instanceof Ast\PatVar
            || isset($annotated[$binding->pattern->name])
        ) {
            continue;
        }

        foreach (refreshConstraintArgs($state, $bodyConstraints[$bi] ?? []) as $constraint) {
            $key = $constraint->class . ':' . constraintArgsKey($constraint->args);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $constraint;
        }
    }

    return $merged;
}

/**
 * Allocate a dictionary parameter for each class constraint.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return array{0: list<Ast\LambdaParam>, 1: list<Ast\PendingConstraint>, 2: list<Type>}
 */
function allocateDictParams(TypeCheckState $state, array $constraints): array
{
    $params = [];
    $abstracted = [];
    $dictTypes = [];
    foreach ($constraints as $constraint) {
        $name = '__ev_dict' . $state->nextEvidenceParam++;
        $dictType = new TCon('__Dict_' . $constraint->class);
        $dictTypes[] = $dictType;
        $param = new Ast\PatVar($name);
        $dictAst = internalTypeToAst($dictType, $state->subst);
        $param->inferredInternalType = $dictType;
        $param->inferredType = $dictAst;
        $params[] = new Ast\LambdaParam($param, $dictAst);
        $abstracted[] = new Ast\PendingConstraint(
            $constraint->class,
            $constraint->args,
            $name,
            $constraint->implicit,
            $constraint->instanceHeadAst,
        );
    }

    return [$params, $abstracted, $dictTypes];
}

/**
 * Make the given constraints the in-scope dictionaries for evidence resolution.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return array{0: list<Ast\PendingConstraint>, 1: array<string, list<Ast\PendingConstraint>>}
 */
function installAmbientConstraints(TypeCheckState $state, array $constraints): array
{
    $saved = [$state->ambientConstraints, $state->ambientConstraintsByClass];
    $state->ambientConstraints = $constraints;
    $byClass = [];
    foreach ($constraints as $constraint) {
        $byClass[$constraint->class][] = $constraint;
    }
    $state->ambientConstraintsByClass = $byClass;

    return $saved;
}

/** @param array{0: list<Ast\PendingConstraint>, 1: array<string, list<Ast\PendingConstraint>>} $saved */
function restoreAmbientConstraints(TypeCheckState $state, array $saved): void
{
    [$state->ambientConstraints, $state->ambientConstraintsByClass] = $saved;
}

/**
 * Wrap a checked expression in a lambda over leading dictionary parameters, so
 * the binding's runtime value expects the dictionaries the type promises.
 *
 * @param list<Ast\LambdaParam> $params
 * @param list<Type> $dictTypes
 */
function wrapDictLambda(TypeCheckState $state, Ast\AstNode $rhs, array $params, array $dictTypes): Ast\Lambda
{
    $lambda = new Ast\Lambda($params, $rhs);
    $fnType = $rhs->inferredInternalType !== null
        ? prune($state, $rhs->inferredInternalType)
        : freshType($state);
    foreach (\array_reverse($dictTypes) as $dictType) {
        $fnType = new TArrow($dictType, $fnType);
    }
    publishInferredType($state, $lambda, $fnType);

    return $lambda;
}

/**
 * @param list<Ast\Binding> $bindings
 * @param array<int|string, Scheme> $env
 * @return array<int|string, Scheme>
 */
function inferRecursiveBindings(TypeCheckState $state, array $bindings, array $env, bool $generalize): array
{
    $local = $env;
    /** @var array<int, Type> $expected monotype per member, keyed by the member's binder */
    $expected = [];
    /** @var array<int, true> $annotated members whose own signature fixed their type */
    $annotated = [];
    /** @var array<int, int> $memberOf binding index => its binder identity */
    $memberOf = [];

    $declared = [];
    foreach ($bindings as $bi => $binding) {
        $declared[$bi] = declarePatternBinders($state, $binding->pattern, $local);
    }

    foreach ($bindings as $bi => $binding) {
        if (! $binding->pattern instanceof Ast\PatVar) {
            continue;
        }

        $binder = $declared[$bi][$binding->pattern->name];
        $memberOf[$bi] = $binder;
        [, $annot] = peelBindingAnnotation($binding->value);
        if ($annot !== null) {
            [$scheme, $annotType] = localAnnotationScheme($state, $annot, $binding->value, $env);
            $local[binderKey($binder)] = $scheme;
            $expected[$binder] = $annotType;
            $annotated[$binder] = true;

            continue;
        }

        $expected[$binder] = $local[binderKey($binder)]->type;
    }

    $rhsConstraints = [];
    $bodyConstraints = [];

    foreach ($bindings as $bi => $binding) {
        $constrained = $binding->pattern instanceof Ast\PatVar
            ? localConstrainedAnnotation($binding->value)
            : null;
        if ($constrained !== null) {
            [$wrapped, $valueType, $userConstraints] = abstractAnnotatedLocalBinding(
                $state,
                $binding->value,
                $local,
            );
            $binding->value = $wrapped;
            $rhsConstraints[$bi] = $userConstraints;
            $bodyConstraints[$bi] = [];

            continue;
        }

        [$rhs, $annot] = peelBindingAnnotation($binding->value);
        $valueType = inferExpr($state, $rhs, $local);

        if ($annot !== null) {
            $annotType = $binding->pattern instanceof Ast\PatVar
                ? $expected[$memberOf[$bi]]
                : astType($state, $annot);
            unify($state, $valueType, $annotType, $binding->value);
            $valueType = prune($state, $annotType);
        }

        if ($generalize) {
            if ($binding->value instanceof Ast\TypeAsc) {
                $binding->value->expr = elaborateNumericLiterals($state, $rhs);
            } else {
                $binding->value = elaborateNumericLiterals($state, $binding->value);
            }
        }

        $constraints = pendingConstraintsFromExpr($rhs);
        $rhsConstraints[$bi] = $constraints;
        $bodyConstraints[$bi] = $generalize ? pendingConstraintsDeep($rhs, $state) : [];

        if ($binding->value instanceof Ast\TypeAsc) {
            $binding->value->pendingConstraints = $constraints;
        }

        if ($binding->pattern instanceof Ast\PatVar) {
            unify($state, $valueType, $expected[$memberOf[$bi]], $binding->pattern);

            continue;
        }

        [, $local] = bindPattern(
            $state,
            $binding->pattern,
            $valueType,
            $local,
            generalize: false,
            constraints: $constraints,
        );
    }

    $groupPending = $generalize
        ? recursiveGroupConstraints($state, $bindings, $bodyConstraints, $annotated)
        : [];
    $group = $groupPending === []
        ? new SolvedGroup([], [])
        : solveConstraints($state, $groupPending);
    $groupConstraints = $group->user;
    $groupDictConstraints = $group->dicts;
    if ($groupConstraints !== []) {
        $members = [];
        foreach ($bindings as $bi => $binding) {
            if (! $binding->pattern instanceof Ast\PatVar) {
                continue;
            }

            if (! isset($annotated[$memberOf[$bi]])) {
                $members[$bi] = $memberOf[$bi];
            }
        }

        $byBinder = [];
        foreach ($members as $binder) {
            $byBinder[$binder] = $groupConstraints;
        }

        foreach ($members as $bi => $_) {
            $binding = $bindings[$bi];
            [$rhs] = peelBindingAnnotation($binding->value);
            markRecursiveGroupEvidence(
                $rhs,
                static fn (Ast\Variable $use): ?array => $use->binderId === null
                    ? null
                    : ($byBinder[$use->binderId] ?? null),
            );
            [$wrapped, $abstracted] = abstractLocalConstraints($state, $rhs, $groupDictConstraints);
            if ($binding->value instanceof Ast\TypeAsc) {
                $binding->value->expr = $wrapped;
            } else {
                $binding->value = $wrapped;
            }

            $rhsConstraints[$bi] = $abstracted;
        }
    }

    $abstractedThisPass = $groupConstraints !== [];

    foreach ($bindings as $bi => $binding) {
        $constraints = refreshConstraintArgs($state, $rhsConstraints[$bi] ?? []);
        if ($binding->pattern instanceof Ast\PatVar) {
            $binder = $memberOf[$bi];
            $isAnnotated = isset($annotated[$binder]);
            $groupAbstracted = $abstractedThisPass && ! $isAnnotated;
            $pruned = prune($state, $expected[$binder]);
            $schemeEnv = $isAnnotated ? $env : $local;
            if (! $isAnnotated) {
                unset($schemeEnv[binderKey($binder)]);
            }
            $quantify = $isAnnotated || $generalize;
            $schemeConstraints = $groupAbstracted ? $groupConstraints : $constraints;
            if ($groupAbstracted) {
                $schemeDicts = $groupDictConstraints;
            } elseif ($quantify && $schemeConstraints !== []) {
                $schemeDicts = solveConstraints($state, $schemeConstraints)->dicts;
            } else {
                $schemeDicts = [];
            }
            $memberScheme = scheme(
                $pruned,
                $quantify ? schemeBoundVars($pruned, $schemeEnv, $schemeConstraints) : [],
                $quantify ? $schemeConstraints : [],
                $schemeDicts !== [] ? \count($schemeDicts) : null,
                $schemeDicts,
            );
            $memberScheme->binderId = $binder;
            $local[binderKey($binder)] = $memberScheme;
            continue;
        }

        if ($generalize) {
            $local = generalizePatternBindings($state, $binding->pattern, $local, $constraints);
        }
    }

    return $local;
}
