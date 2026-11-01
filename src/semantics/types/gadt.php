<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\TypeExpr\scheme;

require_once __DIR__ . '/type_core.php';
require_once __DIR__ . '/patterns.php';

/**
 * GADT refinement.
 *
 * An ordinary constructor's result is the datatype applied to the parameters
 * the declaration bound, so matching one instantiates those parameters and
 * unification says everything there is to say. A GADT constructor writes its
 * result type out: `Lit :: Int -> Expr Int` says that matching `Lit` at
 * scrutinee type `Expr a` proves `a ~ Int`. That proof is a *local* equality
 * assumption — it holds for the rest of that alternative and nowhere else.
 *
 * The assumption is kept in {@see TypeCheckState::$gadtEqualities}, a map from
 * a variable to the type it equals here, which {@see prune()} follows exactly
 * as it follows an ordinary substitution. The scope is the alternative: the
 * caller snapshots the state before binding a pattern and restores it after the
 * alternative has been checked, so two alternatives may assume conflicting
 * equalities and neither leaks into the declaration's own type.
 */

/**
 * Everything a pattern alternative may assume, snapshot for restore.
 *
 * Refinement changes four pieces of state: the equalities themselves, the rigid
 * variables the pattern introduced (existentials are rigid), and the ambient
 * constraints and method projections the constructor's context contributes.
 *
 * @return array{
 *   equal: array<string, Type>,
 *   rigid: array<string, true>,
 *   ambient: list<Ast\PendingConstraint>,
 *   ambientByClass: array<string, list<Ast\PendingConstraint>>,
 *   methods: array<string, mixed>,
 *   methodAmbiguities: array<string, true>
 * }
 */
function gadtPatternSnapshot(TypeCheckState $state): array
{
    return [
        'equal' => $state->gadtEqualities,
        'rigid' => $state->rigidVars,
        'ambient' => $state->ambientConstraints,
        'ambientByClass' => $state->ambientConstraintsByClass,
        'gadtAmbient' => $state->gadtAmbientConstraints,
        'methods' => $state->constraintMethods,
        'methodAmbiguities' => $state->constraintMethodAmbiguities,
    ];
}

function gadtPatternRestore(TypeCheckState $state, array $snapshot): void
{
    $state->gadtEqualities = $snapshot['equal'];
    $state->rigidVars = $snapshot['rigid'];
    $state->ambientConstraints = $snapshot['ambient'];
    $state->ambientConstraintsByClass = $snapshot['ambientByClass'];
    $state->gadtAmbientConstraints = $snapshot['gadtAmbient'];
    $state->constraintMethods = $snapshot['methods'];
    $state->constraintMethodAmbiguities = $snapshot['methodAmbiguities'];
}

/**
 * Record that `$name` equals `$type` from here on.
 *
 * A variable is refined at most once: GADT refinement comes from matching on a
 * constructor whose written result type disagrees with the scrutinee at a
 * variable, and the disagreeing variable is rigid in the alternative, so the
 * same variable cannot be refined twice with two different types. A repeated
 * refinement to an equal type is a no-op.
 */
function refineGadt(TypeCheckState $state, string $name, Type $type): void
{
    $type = prune($state, $type);

    if ($type instanceof TVar && $type->name === $name) {
        return;
    }

    $existing = $state->gadtEqualities[$name] ?? null;
    if ($existing !== null) {
        return;
    }

    if (isset(freeTypeVars($type)[$name])) {
        throw typeFail(
            $state,
            'GADT refinement would make `' . skolemTypeVarBase($name) . '` equal to a type mentioning itself',
        );
    }

    $state->gadtEqualities[$name] = $type;
}

/**
 * Refuse to equate an existential with anything but itself.
 *
 * `MkBox :: a -> Box` hides its `a`: the match knows it only as a rigid
 * variable, and equating it with another rigid variable -- typically the
 * declaration's own result variable -- is exactly the escape the scope rule
 * forbids. Saying so is more use than "could not unify `a` with `a`".
 */
function assertNotExistentialEscape(TypeCheckState $state, TVar $existential, Type $other, ?Ast\AstNode $at): void
{
    if (! isset($state->gadtExistentials[$existential->name])) {
        return;
    }
    if ($other instanceof TVar && $other->name === $existential->name) {
        return;
    }

    throw typeFail($state, existentialEscapeMessage($existential), $at);
}

/** The one wording for "a type the match invented is being used outside it". */
function existentialEscapeMessage(TVar $existential): string
{
    return 'an existential type variable `' . skolemTypeVarBase($existential->name)
        . '` would escape its scope';
}

/**
 * Unify a constructor's written result type with the scrutinee's type,
 * recording an equality wherever a rigid variable meets something else.
 *
 * This is ordinary unification except at a rigid variable: the scrutinee of a
 * signature is rigid (`Expr a$sk`), and matching `Lit :: Expr Int` there is not
 * a mismatch but a proof. A flexible variable still unifies the ordinary way —
 * the branch can only be as general as the variable it met.
 */
function unifyGadtResult(TypeCheckState $state, Type $expected, Type $result, ?Ast\AstNode $at = null): void
{
    $expected = prune($state, $expected);
    $result = prune($state, $result);

    if ($expected instanceof TVar && isset($state->rigidVars[$expected->name])) {
        if ($result instanceof TVar && ! isset($state->rigidVars[$result->name])) {
            unify($state, $result, $expected, $at);

            return;
        }
        assertNotExistentialEscape($state, $expected, $result, $at);
        refineGadt($state, $expected->name, $result);

        return;
    }

    if ($result instanceof TVar && isset($state->rigidVars[$result->name])) {
        if ($expected instanceof TVar && ! isset($state->rigidVars[$expected->name])) {
            unify($state, $expected, $result, $at);

            return;
        }
        assertNotExistentialEscape($state, $result, $expected, $at);
        refineGadt($state, $result->name, $expected);

        return;
    }

    if ($expected instanceof TArrow && $result instanceof TArrow) {
        unifyGadtResult($state, $expected->from, $result->from, $at);
        unifyGadtResult($state, $expected->to, $result->to, $at);

        return;
    }

    if ($expected instanceof TVarApp && $result instanceof TVarApp
        && $expected->name === $result->name
        && \count($expected->args) === \count($result->args)
    ) {
        foreach ($expected->args as $i => $arg) {
            unifyGadtResult($state, $arg, $result->args[$i], $at);
        }

        return;
    }

    $matchingHeads = ($expected instanceof TCon && $result instanceof TCon
            && $expected->name === $result->name)
        || ($expected instanceof TPromoted && $result instanceof TPromoted
            && $expected->name === $result->name);

    if ($matchingHeads && \count($expected->args) === \count($result->args)) {
        foreach ($expected->args as $i => $arg) {
            unifyGadtResult($state, $arg, $result->args[$i], $at);
        }

        return;
    }

    unify($state, $expected, $result, $at);
}

/**
 * Instantiate a GADT constructor's scheme for a pattern.
 *
 * A constructor's variables split in two. Those the result type mentions are
 * determined by the scrutinee — `Lit :: Int -> Expr Int` has none, and
 * `MkT :: a -> T a` has one, the datatype's own parameter. Those it does not
 * mention are existential: the value carries a type that the scrutinee does
 * not name. An existential is *rigid* here, because nothing outside the
 * constructor may decide what it is; letting it be a flexible variable would
 * let `f (MkT x) = x` give `x` the function's result type and leak a type that
 * does not exist outside the match.
 *
 * @param list<string> $existentials
 * @return array{type: Type, constraints: list<Ast\PendingConstraint>, skolems: array<string, TVar>}
 */
function instantiateGadtConstructor(TypeCheckState $state, Scheme $scheme, array $existentials): array
{
    $existential = \array_fill_keys($existentials, true);
    $mapping = [];
    $skolems = [];
    foreach ($scheme->bound as $var) {
        if (isset($existential[$var])) {
            $skolem = freshSkolem($state, $var);
            $mapping[$var] = $skolem;
            $skolems[$var] = $skolem;
            $state->rigidVars[$skolem->name] = true;
            $state->gadtExistentials[$skolem->name] = true;

            continue;
        }

        $mapping[$var] = freshType($state);
    }

    return [
        'type' => substitute(peelForalls($scheme->type), $mapping),
        'constraints' => substitutePendingConstraints($scheme->constraints, $mapping),
        'skolems' => $skolems,
    ];
}

/**
 * The name of the dictionary field a constructor's context stores for one
 * constraint.
 *
 * The name is unspellable (`$` starts no identifier), so it can never collide
 * with a field the source wrote, and it is stable across modules because it is
 * derived from the constraint position and class rather than allocated.
 */
function gadtContextFieldName(int $index, string $className): string
{
    return '$dict' . $index . '_' . $className;
}

/**
 * Bind the dictionary fields a constructor's context contributes.
 *
 * A GADT constructor with a context (`MkT :: Show a => a -> Box`) stores one
 * dictionary per constraint in the value: the context may mention an
 * existential, whose dictionary nothing at the match site could invent, so the
 * pair (value, dictionary) is what the constructor actually is. The fields are
 * the constructor's first fields, so the pattern binds them first — the source
 * never writes them, and the alternatives' bodies see them as the given
 * evidence for the context.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return array{0: list<Ast\AstNode>, 1: array<string, Scheme>, 2: list<string>}
 *   the synthesized sub-patterns and their field names, in field order, and the
 *   environment with their schemes
 */
function bindGadtContextFields(
    TypeCheckState $state,
    Ast\AstNode $pattern,
    array $constraints,
    array $env,
): array {
    $subpatterns = [];
    $fieldNames = [];
    foreach ($constraints as $i => $constraint) {
        $name = $constraint->evidence;
        $binder = new Ast\PatVar($name, $pattern->line, $pattern->col, $pattern->endCol);
        $binder->binderId = ++$state->nextBinderId;
        $state->registerBinder($name, $binder->binderId);
        $type = new TCon('__Dict_' . $constraint->class);
        $binder->inferredInternalType = $type;
        $env[binderKey($binder->binderId)] = scheme($type, []);
        $subpatterns[] = $binder;
        $fieldNames[] = gadtContextFieldName($i, $constraint->class);

        $ambient = new Ast\PendingConstraint(
            $constraint->class,
            $constraint->args,
            $name,
        );
        $state->ambientConstraints[] = $ambient;
        $state->ambientConstraintsByClass[$constraint->class][] = $ambient;
        $state->gadtAmbientConstraints[] = $ambient;
    }

    return [$subpatterns, $env, $fieldNames];
}

/**
 * Whether two types could ever be equal, for the exhaustiveness checker.
 *
 * A variable could be anything, so it never rules a constructor out — matching
 * one refines it. Two different concrete heads never agree, so a constructor
 * returning `T Bool` is not a case of `T Int` at all.
 *
 * The check must not unify: the scrutinee's variables belong to the case, and
 * asking whether a constructor is *possible* may not decide what they are.
 */
function typesCouldUnify(TypeCheckState $state, Type $left, Type $right): bool
{
    $left = prune($state, $left);
    $right = prune($state, $right);

    if ($left instanceof TVar || $right instanceof TVar) {
        return true;
    }

    if ($left instanceof TArrow || $right instanceof TArrow) {
        return $left instanceof TArrow
            && $right instanceof TArrow
            && typesCouldUnify($state, $left->from, $right->from)
            && typesCouldUnify($state, $left->to, $right->to);
    }

    if ($left instanceof TVarApp || $right instanceof TVarApp) {
        if (! $left instanceof TVarApp || ! $right instanceof TVarApp) {
            return true;
        }
        if ($left->name !== $right->name || \count($left->args) !== \count($right->args)) {
            return true;
        }
        foreach ($left->args as $i => $arg) {
            if (! typesCouldUnify($state, $arg, $right->args[$i])) {
                return false;
            }
        }

        return true;
    }

    if ($left instanceof TCon && $right instanceof TCon) {
        if ($left->name !== $right->name || \count($left->args) !== \count($right->args)) {
            return false;
        }
        foreach ($left->args as $i => $arg) {
            if (! typesCouldUnify($state, $arg, $right->args[$i])) {
                return false;
            }
        }

        return true;
    }

    if ($left instanceof TPromoted && $right instanceof TPromoted) {
        if ($left->name !== $right->name || \count($left->args) !== \count($right->args)) {
            return false;
        }
        foreach ($left->args as $i => $arg) {
            if (! typesCouldUnify($state, $arg, $right->args[$i])) {
                return false;
            }
        }

        return true;
    }

    return $left::class === $right::class;
}

/**
 * Whether a registered constructor can produce a value of the scrutinee type.
 *
 * Only a GADT constructor can fail to: its result type is written out, so the
 * datatype's own indices rule it out. An ordinary constructor always can.
 *
 * @param array<string, mixed> $ctor a registered constructor entry
 */
function gadtConstructorMatches(TypeCheckState $state, array $ctor, Type $scrutineeType): bool
{
    $result = $ctor['resultType'] ?? null;
    if ($result === null) {
        return true;
    }

    return typesCouldUnify($state, $result, $scrutineeType);
}

/**
 * Refuse a declaration whose own type mentions a type the match invented.
 *
 * `f (MkBox x) = x` has no type: `x`'s type exists only inside the match, and
 * the declaration would have to return it. A signature's result type is rigid,
 * so this mostly fires for inferred declarations — where the result variable
 * happily unified with the existential skolem — and for constraints.
 *
 * @param list<Ast\PendingConstraint> $constraints
 */
function assertNoGadtEscape(
    TypeCheckState $state,
    Type $fnType,
    array $constraints,
    Ast\AstNode $at,
): void {
    if ($state->gadtExistentials === []) {
        return;
    }

    $mentioned = typeVars(prune($state, $fnType));
    foreach ($constraints as $constraint) {
        foreach ($constraint->args as $arg) {
            $mentioned += typeVars(prune($state, $arg));
        }
    }

    foreach (\array_keys($state->gadtExistentials) as $name) {
        if (isset($mentioned[$name])) {
            throw typeFail(
                $state,
                'an existential type variable `' . skolemTypeVarBase($name)
                    . '` would escape its scope',
                $at,
            );
        }
    }
}
