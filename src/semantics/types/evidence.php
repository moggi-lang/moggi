<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TNatLit;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TStringLit;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

/**
 * The class obligation behind an ambient-projected method reference, if it
 * names one: the constraint the enclosing scope discharged through its own
 * dictionary (`Ord a` for `<` under `Ord a => ...`).
 *
 * A bare reference is freshened before its arguments are known, which severs
 * the link between the method's type and the ambient dictionary baked into
 * the node -- a use at any other type (`r < 0` with `r :: Int`) would silently
 * keep the ambient dictionary. The caller freshens this obligation together
 * with the method type so the two stay aligned, records it on the node, and
 * {@see verifyMethodClassObligation} checks it once the use-site type is
 * known, rewriting the node to a concrete instance on mismatch.
 */
function methodClassObligation(array $info): ?Ast\PendingConstraint
{
    if (($info['evidence'] ?? '') === '') {
        return null;
    }
    $args = $info['constraintArgs'] ?? null;
    if ($args === null) {
        $head = $info['constraintHead'] ?? null;
        if ($head === null) {
            return null;
        }
        $args = [$head];
    }
    if ($args === []) {
        return null;
    }

    return new Ast\PendingConstraint(
        $info['class'],
        \array_values($args),
        '',
        false,
        null,
    );
}

/**
 * The type variables of the dictionary a method reference is projected from.
 *
 * A projected method's type is already written in terms of the resolved
 * constraint's arguments — `substituteClassMethod` puts them there — so the
 * *rigid* variables among them are the instance head, not method-local
 * polymorphism. Freshening those hands the use site a type that no longer
 * mentions the dictionary it dispatches on, which is what left an
 * associated-type result (`Rep a`) stuck on a variable where the constraint had
 * already fixed `a`.
 *
 * A flexible head variable is deliberately *not* kept: it is not yet the
 * dictionary's answer, so each use still gets its own copy and may constrain it
 * freely, which is how such declarations were typed before.
 *
 * @param array{class: string, method: string, type: Type, constraintArgs?: list<Type>, constraintHead?: ?Type} $info
 * @return array<string, true>
 */
function dictionaryHeadVars(TypeCheckState $state, array $info): array
{
    $args = $info['constraintArgs'] ?? null;
    if ($args === null) {
        $head = $info['constraintHead'] ?? null;
        $args = $head === null ? [] : [$head];
    }

    $vars = [];
    foreach ($args as $arg) {
        $vars += typeVars($arg);
    }

    return \array_intersect_key($vars, $state->rigidVars, associatedTypeArgumentVars($state, $info['type']));
}

/**
 * The variables an associated-type application in this method type passes to
 * its family (`Rep a` contributes `a`).
 *
 * Only these have to stay linked to the dictionary: they are what decides the
 * family's reduction. A head variable outside a family application is still
 * freshened, which is how the rest of the tree has always typed a projected
 * method, and keeps this slice to the defect it repairs.
 *
 * @return array<string, true>
 */
function associatedTypeArgumentVars(TypeCheckState $state, Type $type): array
{
    if ($type instanceof TCon && isset($state->associatedFamilies[$type->name])) {
        $vars = [];
        foreach ($type->args as $arg) {
            $vars += typeVars($arg) + associatedTypeArgumentVars($state, $arg);
        }

        return $vars;
    }

    $vars = [];
    foreach (typeChildren($type) as $child) {
        $vars += associatedTypeArgumentVars($state, $child);
    }

    return $vars;
}

/**
 * Freshen an ambient-projected method reference, keeping its class obligation
 * aligned with its type (see {@see methodClassObligation}) and keeping the
 * dictionary's own variables fixed (see {@see dictionaryHeadVars}). Returns the
 * freshened type; the method-local obligations land in pendingConstraints as
 * before, the class obligation on the node's classObligation field.
 *
 * @param array{class: string, method: string, type: Type, userConstraints?: list<Ast\PendingConstraint>, constraintArgs?: list<Type>, constraintHead?: ?Type} $info
 */
function freshenConstraintMethod(TypeCheckState $state, Ast\EvidenceMethod $expr, array $info): Type
{
    $obligation = methodClassObligation($info);
    $constraints = $info['userConstraints'] ?? [];
    if ($obligation !== null) {
        $constraints[] = $obligation;
    }
    $instantiated = freshenTypeWithConstraintsKeeping(
        $state,
        $info['type'],
        $constraints,
        dictionaryHeadVars($state, $info),
    );
    if ($obligation !== null) {
        $expr->classObligation = \array_pop($instantiated['constraints']);
    }
    if ($instantiated['constraints'] !== []) {
        setPendingObligations($state, $expr, $instantiated['constraints'], pendingDictsOf($state, $expr));
    }

    return $instantiated['type'];
}

/**
 * Instantiate a class method's dictionary head from the argument it is applied to.
 *
 * A class method's declared type is written in terms of the class's parameter —
 * `to :: Generic b => Rep b x -> b` — so the associated-family application in its domain stays
 * stuck until `b` is known, and a use site that supplies the head has been reaching the result by
 * comparing the two applications (`Rep b x` against `Rep Foo x`), which only an injectivity rule
 * could do: the family is not injective, so that comparison is neither the language's rule nor a
 * sound one to carry.
 *
 * The dictionary answers it instead. The operand is a `Rep Foo x`, so the `Generic` dictionary the
 * call dispatches on is `Foo`'s, and the whole method type instantiates at that head: the domain
 * becomes `Rep Foo x` — the same application the operand has, matched by ordinary unification —
 * and the result is `Foo` rather than a stuck `Rep b x`.
 *
 * The improvement names no family. It binds a dictionary head variable only when all of these hold:
 *
 *  - the variable is one of the callee's own obligations' arguments and is still flexible (a rigid
 *    skolem is already the dictionary's answer, and a bound one is not this call's to choose),
 *  - the domain applies an associated family **of that same class** at that variable — `Rep` belongs
 *    to `Generic`, so `Generic`'s head is the one its equation is looked up by,
 *  - the operand applies the same family at the same position at a head that names an instance (a
 *    constructor, a promoted constructor, or a rigid variable), not at another stuck application.
 */
function resolveMethodHeadFromArgument(
    TypeCheckState $state,
    Ast\AstNode $callee,
    Type $domain,
    Type $argType,
): void {
    $heads = methodDictionaryHeadVars($state, $callee);
    if ($heads === []) {
        return;
    }

    $improvements = [];
    collectFamilyHeadImprovements($state, $domain, $argType, $heads, $improvements);

    foreach ($improvements as $var => $head) {
        if (isset($state->subst[$var])) {
            continue;
        }
        $state->subst[$var] = $head;
    }
}

/**
 * The head variables of the callee's own obligations, mapped to the class that asks for them.
 *
 * @return array<string, string>
 */
function methodDictionaryHeadVars(TypeCheckState $state, Ast\AstNode $callee): array
{
    [$pending] = pendingConstraintsAndDicts($state, $callee);

    $heads = [];
    foreach ($pending as $constraint) {
        foreach ($constraint->args as $arg) {
            $arg = prune($state, $arg);
            if ($arg instanceof TVar && ! isset($state->rigidVars[$arg->name])) {
                $heads[$arg->name] = $constraint->class;
            }
        }
    }

    return $heads;
}

/**
 * Walk a domain and an operand side by side, recording the heads the operand fixes.
 *
 * @param array<string, string> $heads dictionary head variable => its class
 * @param array<string, Type> $out
 */
function collectFamilyHeadImprovements(
    TypeCheckState $state,
    Type $pattern,
    Type $actual,
    array $heads,
    array &$out,
): void {
    $pattern = prune($state, $pattern);
    $actual = prune($state, $actual);

    if ($pattern instanceof TArrow && $actual instanceof TArrow) {
        collectFamilyHeadImprovements($state, $pattern->from, $actual->from, $heads, $out);
        collectFamilyHeadImprovements($state, $pattern->to, $actual->to, $heads, $out);

        return;
    }

    if (! $pattern instanceof TCon || ! $actual instanceof TCon
        || $pattern->name !== $actual->name
        || \count($pattern->args) !== \count($actual->args)
    ) {
        if ($pattern instanceof TVarApp && $actual instanceof TVarApp
            && \count($pattern->args) === \count($actual->args)
        ) {
            foreach ($pattern->args as $i => $arg) {
                collectFamilyHeadImprovements($state, $arg, $actual->args[$i], $heads, $out);
            }
        }

        return;
    }

    $family = $state->associatedFamilies[$pattern->name] ?? null;
    foreach ($pattern->args as $i => $arg) {
        $var = prune($state, $arg);
        if ($var instanceof TVar
            && isset($heads[$var->name])
            && $family !== null
            && $heads[$var->name] === $family['class']
        ) {
            $head = prune($state, $actual->args[$i]);
            if (isInstanceHeadType($state, $head)) {
                $out[$var->name] ??= $head;
            }
        }

        collectFamilyHeadImprovements($state, $arg, $actual->args[$i], $heads, $out);
    }
}

/** Whether a type names a head an instance can be selected by, rather than a stuck application. */
function isInstanceHeadType(TypeCheckState $state, Type $type): bool
{
    if ($type instanceof TVar) {
        return isset($state->rigidVars[$type->name]);
    }

    if ($type instanceof TCon) {
        return ! isset($state->associatedFamilies[$type->name]);
    }

    return $type instanceof TPromoted;
}

/**
 * An operand type an infix rewrite already computed, taken out of the hand-off.
 *
 * See {@see inferRewrittenInfixFromOperands} for why the type is handed over at all. It is taken
 * rather than read, so a handed-over type reaches the rewrite that asked for it and nothing else;
 * an operand whose inference was not handed over is inferred here as before.
 */
function takeKnownOperandType(TypeCheckState $state, Ast\AstNode $operand): ?Type
{
    if ($state->knownOperandTypes === []) {
        return null;
    }

    $id = \spl_object_id($operand);
    $type = $state->knownOperandTypes[$id] ?? null;
    unset($state->knownOperandTypes[$id]);

    return $type;
}

/**
 * Park the obligation a curried call's callee is waiting for on the call.
 *
 * Constraint generation, not elaboration: whether the obligation can be
 * discharged right now is decided by {@see elaborateApplyEvidence}, which runs
 * as a separate step, so inference never inserts evidence of its own.
 */
function parkApplyConstraints(TypeCheckState $state, Ast\Apply $expr): void
{
    [$pending, $dicts] = pendingConstraintsAndDicts($state, $expr->function);
    if ($pending === []) {
        return;
    }

    setPendingObligations($state, $expr, $pending, $dicts);
}

/**
 * Discharge a call's parked obligation now, or leave it parked for the
 * post-pass.
 *
 * The elaboration half of the boundary: {@see tryResolveEvidenceStrict} decides
 * whether the call can be discharged now, and it is deliberately stricter than
 * the post-pass's {@see tryResolveEvidenceExprs}. Inference has to *park* an
 * obligation whose instance context still has an open variable -- that open
 * variable becomes a dictionary parameter of the enclosing binding -- where the
 * post-pass has to *try* the elaboration so an impossible one surfaces as
 * `ambiguous constraint` at the call rather than as a bare leftover. A single
 * shared predicate loses one diagnostic or the other (`Bad-Ambiguous-Constraint`,
 * `Bad-Ambiguous-Instance-Context`).
 *
 * The decision belongs here, right after the apply is inferred and its operand
 * types are known, and not at the end of the enclosing scope: resolvability is a
 * property of the substitutions in place at this moment, and a later pass sees
 * a different one. `Ord [t]` for `[1, 2] < [1, 3]` is discharged here through
 * the list instance, but is no longer a leaf any pass can default by the time
 * the body finishes, so moving it out changes which obligations numeric
 * defaulting can see (`Lists.mog`, `Ordering-Comparisons.mog`).
 */
function elaborateApplyEvidence(TypeCheckState $state, Ast\Apply $expr): void
{
    $root = rootCalleeExpr($expr);
    if ($root instanceof Ast\EvidenceMethod) {
        verifyMethodClassObligation($state, $root, $expr);
    }
    [$pending, $dicts] = pendingConstraintsAndDicts($state, $expr->function);
    if ($pending === []) {
        return;
    }

    $evidence = tryResolveEvidenceStrict($state, dictsForPending($state, $pending, $dicts), $expr);
    if ($evidence === null) {
        return;
    }

    clearPendingConstraints($state, $expr);
    prependEvidenceAtRoot($state, $expr, $evidence);
}

/**
 * Infer an apply and then discharge what its callee owes.
 *
 * The only way into `inferApply`: generation and elaboration stay separate
 * functions, and a caller that needs the call elaborated goes through here.
 * `inferApply` itself only parks; an apply it declines to elaborate (one whose
 * argument is already a dictionary) leaves nothing parked, and is skipped.
 */
function inferApplyAndElaborate(TypeCheckState $state, Ast\Apply $expr, array $env): Type
{
    $type = inferApply($state, $expr, $env);
    if (pendingConstraintsOf($state, $expr) !== [] || pendingDictsOf($state, $expr) !== []) {
        elaborateApplyEvidence($state, $expr);
    }

    return $type;
}

function propagateCallMetadata(Ast\Apply $expr): void
{
    $callee = rootCalleeExpr($expr);
    if (\property_exists($callee, 'intrinsicWrapper') && $callee->intrinsicWrapper !== null) {
        $expr->intrinsicWrapper = $callee->intrinsicWrapper;
    }
    if (\property_exists($callee, 'intrinsicId') && $callee->intrinsicId !== null) {
        $expr->intrinsicId = $callee->intrinsicId;
    }
}

function rootCalleeExpr(Ast\AstNode $expr): Ast\AstNode
{
    while ($expr instanceof Ast\Apply) {
        $expr = $expr->function;
    }

    return $expr;
}

function resolvePendingEvidenceInExpr(TypeCheckState $state, Ast\AstNode $expr): Ast\AstNode
{
    return match ($expr::class) {
        Ast\Apply::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\Apply) {
                return $expr;
            }

            tryResolveApplyEvidence($state, $expr);
            $expr->function = resolvePendingEvidenceInExpr($state, $expr->function);
            $expr->argument = resolvePendingEvidenceInExpr($state, $expr->argument);
            $expr->argument = tryResolveValueEvidence($state, $expr->argument);

            return $expr;
        })(),
        Ast\Infix::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\Infix) {
                return $expr;
            }

            $expr->left = resolvePendingEvidenceInExpr($state, $expr->left);
            $expr->right = resolvePendingEvidenceInExpr($state, $expr->right);
            $expr->left = tryResolveValueEvidence($state, $expr->left);
            $expr->right = tryResolveValueEvidence($state, $expr->right);

            if (pendingConstraintsOf($state, $expr) === []) {
                return $expr;
            }

            $constraints = dictsForPending($state, pendingConstraintsOf($state, $expr), pendingDictsOf($state, $expr));
            $evidence = tryResolveEvidenceExprs($state, $constraints, $expr);
            if ($evidence === null) {
                return $expr;
            }

            return prependEvidenceAtRoot(
                $state,
                infixApplicationNode(
                    $expr->operator,
                    $expr->left,
                    $expr->right,
                    $expr->line,
                    $expr->col,
                    $expr->endCol,
                ),
                $evidence,
            );
        })(),
        Ast\IntrinsicCall::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\IntrinsicCall) {
                return $expr;
            }

            foreach ($expr->args as &$arg) {
                $arg = tryResolveValueEvidence($state, resolvePendingEvidenceInExpr($state, $arg));
            }
            unset($arg);

            return $expr;
        })(),
        Ast\Lambda::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\Lambda) {
                return $expr;
            }

            $expr->body = resolvePendingEvidenceInExpr($state, $expr->body);
            $expr->body = tryResolveValueEvidence($state, $expr->body);

            return $expr;
        })(),
        Ast\Let::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\Let) {
                return $expr;
            }

            foreach ($expr->bindings as $binding) {
                $binding->value = resolvePendingEvidenceInExpr($state, $binding->value);
                $binding->value = tryResolveValueEvidence($state, $binding->value);
            }
            $expr->body = resolvePendingEvidenceInExpr($state, $expr->body);
            $expr->body = tryResolveValueEvidence($state, $expr->body);

            return $expr;
        })(),
        Ast\Where::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\Where) {
                return $expr;
            }

            foreach ($expr->bindings as $binding) {
                $binding->value = resolvePendingEvidenceInExpr($state, $binding->value);
                $binding->value = tryResolveValueEvidence($state, $binding->value);
            }
            $expr->expr = resolvePendingEvidenceInExpr($state, $expr->expr);
            $expr->expr = tryResolveValueEvidence($state, $expr->expr);

            return $expr;
        })(),
        Ast\CaseExpr::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\CaseExpr) {
                return $expr;
            }

            $expr->scrutinee = resolvePendingEvidenceInExpr($state, $expr->scrutinee);
            $expr->scrutinee = tryResolveValueEvidence($state, $expr->scrutinee);
            foreach ($expr->alts as $alt) {
                $alt->body = resolvePendingEvidenceInExpr($state, $alt->body);
                $alt->body = tryResolveValueEvidence($state, $alt->body);
            }

            return $expr;
        })(),
        Ast\GuardsExpr::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\GuardsExpr) {
                return $expr;
            }

            foreach ($expr->clauses as $clause) {
                $clause->guard = resolvePendingEvidenceInExpr($state, $clause->guard);
                $clause->guard = tryResolveValueEvidence($state, $clause->guard);
                $clause->body = resolvePendingEvidenceInExpr($state, $clause->body);
                $clause->body = tryResolveValueEvidence($state, $clause->body);
            }

            return $expr;
        })(),
        Ast\ListLit::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\ListLit) {
                return $expr;
            }

            foreach ($expr->elements as &$elem) {
                $elem = resolvePendingEvidenceInExpr($state, $elem);
                if ($elem instanceof Ast\Apply) {
                    tryResolveApplyEvidence($state, $elem);
                }
                $elem = tryResolveValueEvidence($state, $elem);
            }
            unset($elem);

            return $expr;
        })(),
        Ast\Tuple::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\Tuple) {
                return $expr;
            }

            foreach ($expr->elements as &$elem) {
                $elem = resolvePendingEvidenceInExpr($state, $elem);
                if ($elem instanceof Ast\Apply) {
                    tryResolveApplyEvidence($state, $elem);
                }
                $elem = tryResolveValueEvidence($state, $elem);
            }
            unset($elem);

            return $expr;
        })(),
        Ast\RecordCon::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\RecordCon) {
                return $expr;
            }

            foreach ($expr->fields as $field) {
                $field->expr = resolvePendingEvidenceInExpr($state, $field->expr);
                $field->expr = tryResolveValueEvidence($state, $field->expr);
            }

            return $expr;
        })(),
        Ast\RecordUpdate::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\RecordUpdate) {
                return $expr;
            }

            $expr->object = resolvePendingEvidenceInExpr($state, $expr->object);
            $expr->object = tryResolveValueEvidence($state, $expr->object);
            foreach ($expr->fields as $field) {
                $field->expr = resolvePendingEvidenceInExpr($state, $field->expr);
                $field->expr = tryResolveValueEvidence($state, $field->expr);
            }

            return $expr;
        })(),
        Ast\FieldAccess::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\FieldAccess) {
                return $expr;
            }

            $expr->object = resolvePendingEvidenceInExpr($state, $expr->object);
            $expr->object = tryResolveValueEvidence($state, $expr->object);

            return $expr;
        })(),
        Ast\DoExpr::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\DoExpr) {
                return $expr;
            }

            if (isset($expr->desugared)) {
                $expr->desugared = resolvePendingEvidenceInExpr($state, $expr->desugared);
            }

            return $expr;
        })(),
        Ast\TypeAsc::class => (static function () use ($state, $expr): Ast\AstNode {
            if (! $expr instanceof Ast\TypeAsc) {
                return $expr;
            }

            $expr->expr = resolvePendingEvidenceInExpr($state, $expr->expr);
            $expr->expr = tryResolveValueEvidence($state, $expr->expr);

            return $expr;
        })(),
        default => $expr,
    };
}

function tryResolveApplyEvidence(TypeCheckState $state, Ast\Apply $expr): void
{
    $root = rootCalleeExpr($expr);
    if ($root instanceof Ast\EvidenceMethod) {
        verifyMethodClassObligation($state, $root, $expr);
    }
    [$pending, $dicts] = pendingConstraintsAndDicts($state, $expr->function);
    if ($pending === []) {
        [$pending, $dicts] = [pendingConstraintsOf($state, $expr), pendingDictsOf($state, $expr)];
    }

    if ($pending === []) {
        return;
    }

    $constraints = dictsForPending($state, $pending, $dicts);
    $evidence = tryResolveEvidenceExprs($state, $constraints, $expr);
    if ($evidence === null) {
        return;
    }

    clearPendingConstraints($state, $expr);
    prependEvidenceAtRoot($state, $expr, $evidence);
}

/**
 * Resolve a constrained function that is used as a bare *value* (e.g. passed as
 * a higher-order argument like `foldr maxOfF ...`). Its dictionaries must be
 * supplied as leading arguments so the value has its concrete `a -> a -> ...`
 * type. Only fires for reference nodes that directly carry pending constraints;
 * apply spines are handled by tryResolveApplyEvidence.
 */
function tryResolveValueEvidence(TypeCheckState $state, Ast\AstNode $expr): Ast\AstNode
{
    if ($expr instanceof Ast\EvidenceMethod) {
        verifyMethodClassObligation($state, $expr, $expr);

        return $expr;
    }
    if (! $expr instanceof Ast\Variable && ! $expr instanceof Ast\OperatorRef && ! $expr instanceof Ast\QualifiedRef) {
        return $expr;
    }

    if (pendingConstraintsOf($state, $expr) === []) {
        return $expr;
    }

    $constraints = dictsForPending($state, pendingConstraintsOf($state, $expr), pendingDictsOf($state, $expr));
    $evidence = tryResolveEvidenceExprs($state, $constraints, $expr);
    if ($evidence === null) {
        return $expr;
    }

    setPendingObligations($state, $expr, []);

    return prependEvidenceToCall($state, $expr, $evidence);
}

function refreshConstraintArgs(TypeCheckState $state, array $constraints): array
{
    return \array_map(
        static fn (Ast\PendingConstraint $constraint): Ast\PendingConstraint => new Ast\PendingConstraint(
            $constraint->class,
            \array_map(
                static fn (Type $arg): Type => prune($state, $arg),
                $constraint->args,
            ),
            $constraint->evidence,
            $constraint->implicit,
            $constraint->instanceHeadAst,
        ),
        $constraints,
    );
}

/**
 * The type of a call head, resolving a method name two in-scope dictionaries
 * provide by looking at the argument.
 *
 * `negate` while both `Num a` and `Num b` hold has no single meaning, so the
 * bare name is rejected; `negate n` does have one as soon as `n`'s type is
 * known, and that is the case the argument decides. The candidate whose
 * constraint head *is* the argument's type is the dictionary the call means.
 *
 * Nothing is guessed: an argument whose type does not name one of the heads
 * still leaves the name ambiguous and is reported as before.
 */
function inferMethodByArgument(TypeCheckState $state, Ast\Apply $expr, array $env): Type
{
    $name = $expr->function instanceof Ast\Variable ? $expr->function->name : null;
    if ($name === null || ! isset($state->constraintMethodCandidates[$name])) {
        return inferExpr($state, $expr->function, $env);
    }

    $argType = argumentType($state, $expr->argument, $env);
    $chosen = $argType === null ? null : constraintMethodForOperandTypes($state, $name, $argType);
    if ($chosen === null) {
        $chosen = declaredMethodCandidate($state, $name);
    }
    if ($chosen === null) {
        return inferExpr($state, $expr->function, $env);
    }

    $savedMethods = $state->constraintMethods;
    $savedAmbiguities = $state->constraintMethodAmbiguities;
    $state->constraintMethods[$name] = $chosen;
    unset($state->constraintMethodAmbiguities[$name]);

    try {
        return inferExpr($state, $expr->function, $env);
    } finally {
        $state->constraintMethods = $savedMethods;
        $state->constraintMethodAmbiguities = $savedAmbiguities;
    }
}

/**
 * The argument's type, when it has one already.
 *
 * A parameter is bound to a scheme before the body is checked, so its type is
 * the rigid one from the signature, which is what a constraint head is compared
 * against. An argument the call itself builds (`negate (x + 1)`) has no type yet
 * and is not consulted.
 */
function argumentType(TypeCheckState $state, Ast\AstNode $argument, array $env): ?Type
{
    if ($argument instanceof Ast\Variable) {
        $scheme = resolveBinderScheme($state, $argument->name, $env)
            ?? $env[$argument->name]
            ?? $state->env[$argument->name]
            ?? null;

        return $scheme instanceof Scheme ? $scheme->type : null;
    }

    return $argument->inferredInternalType === null
        ? null
        : prune($state, $argument->inferredInternalType);
}

/**
 * The one candidate a written constraint provides, when the others come from a
 * superclass. `null` when two written constraints provide the name (a real
 * ambiguity) or when every candidate is derived.
 */
function declaredMethodCandidate(TypeCheckState $state, string $method): ?array
{
    $declared = [];
    $derived = false;
    foreach ($state->constraintMethodCandidates[$method] ?? [] as $candidate) {
        if (($candidate['declared'] ?? false) === true) {
            $declared[($candidate['class'] ?? '') . ':' . constraintArgKey($candidate['constraintHead'])] = $candidate;
        } else {
            $derived = true;
        }
    }

    $candidates = \array_values($declared);

    return $derived && \count($candidates) === 1 ? $candidates[0] : null;
}

/**
 * The candidate whose constraint head is one of the given operand types.
 *
 * Two candidates can both match (`(Num a, Num a)`, or an operand that is itself
 * a variable): then the name stays ambiguous and the answer is `null`.
 *
 * When no head matches outright, skeleton matching tries instead: some classes
 * wrap the head in method argument types (`Ix` takes `(a, a)`, `MArray` methods
 * take `a i e`), so the operand never *is* the head. A candidate then matches
 * when the operand types fit its method's value-argument types with the
 * candidate's given-constraint variables held rigid — a variable of the
 * candidate's own given constraint must meet itself, never a different
 * variable, so the trial cannot equate two distinct rigid variables the way a
 * plain unification could (there are no skolems to protect them). Method-scoped
 * variables stay flexible: they are what the real inference freshens and
 * unifies afterwards, and this trial records nothing into the substitution.
 * Exactly one matching candidate selects the dictionary; zero or several
 * leave the name ambiguous as before.
 */
function constraintMethodForOperandTypes(TypeCheckState $state, string $method, Type ...$types): ?array
{
    $keys = [];
    foreach ($types as $type) {
        $keys[constraintArgKey(prune($state, $type))] = true;
    }

    $matches = [];
    foreach ($state->constraintMethodCandidates[$method] ?? [] as $candidate) {
        if (isset($keys[constraintArgKey(prune($state, $candidate['constraintHead']))])) {
            $matches[] = $candidate;
        }
    }

    if (\count($matches) === 1) {
        return $matches[0];
    }

    if ($matches !== [] || $types === []) {
        return null;
    }

    $skeleton = null;
    foreach ($state->constraintMethodCandidates[$method] ?? [] as $candidate) {
        if (! methodCandidateSkeletonMatch($state, $candidate, $types)) {
            continue;
        }
        if ($skeleton !== null) {
            return null;
        }
        $skeleton = $candidate;
    }

    return $skeleton;
}

/**
 * Whether the operand types pick this candidate by shape: each operand fits
 * the corresponding value-argument type of the candidate's method type (which
 * is already substituted with the candidate's given constraint), holding the
 * given constraint's variables rigid.
 *
 * @param list<Type> $operandTypes
 */
function methodCandidateSkeletonMatch(TypeCheckState $state, array $candidate, array $operandTypes): bool
{
    $given = null;
    $evidence = $candidate['evidence'] ?? null;
    foreach ($state->ambientConstraints as $constraint) {
        if ($constraint->evidence !== '' && $constraint->evidence === $evidence) {
            $given = $constraint;

            break;
        }
    }
    if ($given === null) {
        return false;
    }

    $rigid = [];
    foreach ($given->args as $arg) {
        collectSkeletonRigidVars($state, $arg, $rigid);
    }

    $methodType = prune($state, $candidate['type']);
    $methodArgs = [];
    while ($methodType instanceof TArrow) {
        $methodArgs[] = prune($state, $methodType->from);
        $methodType = prune($state, $methodType->to);
    }
    if (\count($methodArgs) < \count($operandTypes)) {
        return false;
    }

    $bindings = [];
    foreach ($operandTypes as $k => $operand) {
        if (! skeletonTypesMatch($state, $methodArgs[$k], prune($state, $operand), $rigid, $bindings)) {
            return false;
        }
    }

    return true;
}

/** @param array<string, true> $rigid */
function collectSkeletonRigidVars(TypeCheckState $state, Type $type, array &$rigid): void
{
    $type = prune($state, $type);
    if ($type instanceof TVar) {
        $rigid[$type->name] = true;

        return;
    }
    if ($type instanceof TVarApp) {
        $rigid[$type->name] = true;
    }

    foreach (typeChildren($type) as $child) {
        collectSkeletonRigidVars($state, $child, $rigid);
    }
}

/**
 * Skeleton fit of a method argument type against an operand type.
 *
 * Rigid variables (the candidate's given constraint) meet only themselves;
 * every other variable is a wildcard that binds consistently (compared by
 * key). An unsettled (unbound flexible) operand variable is compatible with
 * anything — it can only ever produce several matches, hence ambiguity, never
 * a wrong unique pick. Constructors match by name and arity, recursively.
 *
 * @param array<string, true> $rigid
 * @param array<string, string> $bindings flexible variable name → bound type key
 */
function skeletonTypesMatch(TypeCheckState $state, Type $pattern, Type $actual, array $rigid, array &$bindings): bool
{
    $pattern = prune($state, $pattern);
    $actual = prune($state, $actual);

    if ($pattern instanceof TVar || $pattern instanceof TVarApp) {
        if (isset($rigid[$pattern->name])) {
            return ($actual instanceof TVar || $actual instanceof TVarApp)
                && $actual->name === $pattern->name
                && ($pattern instanceof TVarApp
                    ? skeletonArgsMatch($state, $pattern->args, $actual instanceof TVarApp ? $actual->args : null, $rigid, $bindings)
                    : true);
        }
        $key = constraintArgKey($actual);
        if (isset($bindings[$pattern->name])) {
            return $bindings[$pattern->name] === $key;
        }
        $bindings[$pattern->name] = $key;
        if ($pattern instanceof TVarApp && $actual instanceof TVarApp && $actual->name === $pattern->name) {
            return skeletonArgsMatch($state, $pattern->args, $actual->args, $rigid, $bindings);
        }

        return true;
    }

    if ($actual instanceof TVar || $actual instanceof TVarApp) {
        return ! isset($rigid[$actual->name]);
    }

    if ($pattern instanceof TCon && $actual instanceof TCon) {
        return $pattern->name === $actual->name
            && skeletonArgsMatch($state, $pattern->args, $actual->args, $rigid, $bindings);
    }

    if ($pattern instanceof TPromoted && $actual instanceof TPromoted) {
        return $pattern->name === $actual->name
            && skeletonArgsMatch($state, $pattern->args, $actual->args, $rigid, $bindings);
    }

    if ($pattern instanceof TArrow && $actual instanceof TArrow) {
        return skeletonTypesMatch($state, $pattern->from, $actual->from, $rigid, $bindings)
            && skeletonTypesMatch($state, $pattern->to, $actual->to, $rigid, $bindings);
    }

    if ($pattern instanceof TUnit || $actual instanceof TUnit) {
        return $pattern instanceof TUnit && $actual instanceof TUnit;
    }

    if ($pattern instanceof TStringLit && $actual instanceof TStringLit) {
        return $pattern->value === $actual->value;
    }

    if ($pattern instanceof TNatLit && $actual instanceof TNatLit) {
        return $pattern->digits === $actual->digits;
    }

    return $pattern::class === $actual::class;
}

/**
 * @param list<Type> $patternArgs
 * @param list<Type>|null $actualArgs
 * @param array<string, true> $rigid
 * @param array<string, string> $bindings
 */
function skeletonArgsMatch(TypeCheckState $state, array $patternArgs, ?array $actualArgs, array $rigid, array &$bindings): bool
{
    if ($actualArgs === null || \count($patternArgs) !== \count($actualArgs)) {
        return false;
    }
    foreach ($patternArgs as $k => $patternArg) {
        if (! skeletonTypesMatch($state, $patternArg, $actualArgs[$k], $rigid, $bindings)) {
            return false;
        }
    }

    return true;
}
