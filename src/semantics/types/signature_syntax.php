<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;
use function Moggi\Semantics\IntrinsicRegistry\isMagicHashName;

/**
 * The signature AST a body type and the obligations it takes on spell.
 *
 * One producer for the discovered signature and for the one a checked inferred
 * declaration publishes at its own checkpoint: two spellings of the same
 * obligations would make the source-facing type and the elaborating one drift.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @param array<string, int> $known type constructors in scope
 * @param ?Ast\AstNode $at the declaration whose position the node carries
 */
function inferredSignatureAst(
    Type $userType,
    array $constraints,
    array $known,
    ?Ast\AstNode $at = null,
): Ast\TypeNode {
    $toSignatureType = static fn (Type $type): Ast\TypeNode => signatureTypeAst(
        $type,
        $known,
    );

    $constraintNodes = [];
    foreach ($constraints as $constraint) {
        $constraintNodes[] = new Ast\TypeApp(
            new Ast\TypeCon($constraint->class),
            \array_map($toSignatureType, $constraint->args),
        );
    }

    $body = $toSignatureType($userType);
    if ($constraintNodes === []) {
        return $body;
    }

    return new Ast\TypeConstrained(
        $constraintNodes,
        $body,
        $at->line ?? 0,
        $at->col ?? 0,
        $at->endCol ?? 0,
    );
}

/**
 * An inferred type as a signature spells it. A name that is not a known type
 * constructor is a higher-kinded parameter -- the checker carries those as type
 * constructors (`Foldable f` is `TCon('f')`, applied as `TCon('f', [a])`) -- and
 * becomes a `TypeVar` here, so re-elaborating the signature yields the variable
 * again instead of applying an unknown constructor.
 *
 * @param array<string, int> $known type constructors in scope
 * @param array<string, Type> $subst
 */
function signatureTypeAst(Type $type, array $known, array $subst = []): Ast\TypeNode
{
    if ($type instanceof TCon) {
        $head = isset($known[$type->name]) || isInternalTypeConName($type->name)
            ? new Ast\TypeCon($type->name)
            : new Ast\TypeVar($type->name);
        if ($type->args === []) {
            return $head;
        }

        return new Ast\TypeApp(
            $head,
            \array_map(
                static fn (Type $arg): Ast\TypeNode => signatureTypeAst($arg, $known, $subst),
                $type->args,
            ),
        );
    }

    if ($type instanceof TArrow) {
        return new Ast\TypeArrow(
            signatureTypeAst($type->from, $known, $subst),
            signatureTypeAst($type->to, $known, $subst),
        );
    }

    return internalTypeToAst($type, $subst);
}

/** Names the checker owns, which no signature would ever quantify over. */
function isInternalTypeConName(string $name): bool
{
    return \str_starts_with($name, '__Dict_') || isMagicHashName($name);
}

/**
 * The constraints a function without a signature has to abstract: the pending
 * constraints left in its body that mention a type variable occurring in the
 * function's own type.
 *
 * Constraints over a head that is already known (`Show Integer` after a literal
 * has been pinned) are left alone -- they are resolved against the instance,
 * not turned into a parameter. A variable the function's type never mentions
 * cannot be generalized at all, so its constraint is left for the unresolved
 * constraint report.
 *
 * @return list<Ast\PendingConstraint>
 */
function inferredFunctionConstraints(TypeCheckState $state, Ast\AstNode $body, Type $fnType): array
{
    $pending = pendingConstraintsDeep($body, $state);
    if ($pending === []) {
        return [];
    }

    $known = \array_flip(knownTypeConstructorNames($state));
    $ownVars = signatureVarsInType(prune($state, $fnType), $known);
    $abstract = [];
    foreach ($pending as $constraint) {
        foreach ($constraint->args as $arg) {
            foreach (signatureVarsInType(prune($state, $arg), $known) as $name => $_) {
                if (isset($ownVars[$name])) {
                    $abstract[] = $constraint;

                    continue 3;
                }
            }
        }
    }

    return $abstract;
}

/**
 * Every variable a signature could quantify over: type variables, bare and
 * applied (`f` and `f a` are both the variable `f`), plus a type constructor
 * nothing else has pinned down -- an unbound `Functor f` constraint can still
 * arrive as a constructor-headed application on its way to being solved.
 *
 * @param array<string, int> $known
 * @return array<string, true>
 */
function signatureVarsInType(Type $type, array $known): array
{
    if ($type instanceof TVar) {
        return [$type->name => true];
    }

    $variables = [];
    if ($type instanceof TVarApp || ($type instanceof TCon && ! isset($known[$type->name]))) {
        $variables[$type->name] = true;
    }

    foreach (typeChildren($type) as $child) {
        $variables += signatureVarsInType($child, $known);
    }

    return $variables;
}
