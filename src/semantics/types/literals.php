<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Syntax\Ast;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TDouble;
use Moggi\Semantics\TypeExpr\TInt;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;

/**
 * An integer literal denotes the value `fromInteger` returns for the integer it
 * spells, so its type is whatever the context asks for: a fresh variable
 * constrained by `Num`. The constraint is recorded on the node and resolved --
 * through the ambient dictionary, the type's own instance, or defaulting -- once
 * the type is known.
 *
 * Without `Num` in scope there is no `fromInteger` to denote the value with, so
 * the literal is the host `Int` it already is; a module that spells an `Int`
 * without importing the class that defines the other number types still works.
 */
function inferIntegerLit(TypeCheckState $state, Ast\IntegerLit $expr): Type
{
    if (! isset($state->classes['Num'])) {
        return new TInt();
    }

    $type = freshType($state);
    $expr->pendingConstraints = [new Ast\PendingConstraint('Num', [$type])];

    return $type;
}

/**
 * Every variable a type quantifies over: what a constraint on one of them is
 * generalized over instead of being defaulted.
 *
 * @return array<string, true>
 */
function quantifiedVars(TypeCheckState $state, Type $type): array
{
    return signatureVarsInType(prune($state, $type), \array_flip(knownTypeConstructorNames($state)));
}

/**
 * The candidates an ambiguous numeric variable is resolved to, in order.
 *
 * `Int` first, because that is the intrinsic machine integer Moggi's backends
 * evaluate with: defaulting to `Integer` would put a library type -- whose php
 * representation is a decimal string for BCMath -- in front of every
 * unannotated number. `Integer` stays one annotation away, and a module can
 * override the list the way a `default (...)` declaration does.
 *
 * @return list<Type>
 */
function numericDefaultTypes(): array
{
    return [new TInt(), new TDouble()];
}

/** The classes a numeric default may resolve to. */
function numericDefaultClasses(): array
{
    return ['Num', 'Real', 'Integral', 'Fractional', 'RealFrac', 'Floating', 'RealFloat'];
}

/**
 * Give every constraint whose type nothing can determine a default type.
 *
 * A literal in `putStrLn (show 1)` is the only thing that constrains `t`, and no
 * part of the declaration's own type mentions it, so `Num t` (with `Show t`, `Eq
 * t`, ...) has to be resolved rather than generalized: base defaults it to the
 * first of `Integer`, `Double` that satisfies every constraint on it, which is
 * why `show 1` shows an `Integer`.
 *
 * @param array<string, true> $bound variables the enclosing declaration
 *        quantifies over
 */
function defaultAmbiguousNumericVars(TypeCheckState $state, Ast\AstNode $body, array $bound): void
{
    if (! isset($state->classes['Num'])) {
        return;
    }

    $pending = pendingConstraintsDeep($body, $state);
    if ($pending === []) {
        return;
    }

    $known = \array_flip(knownTypeConstructorNames($state));
    /** @var array<string, list<Ast\PendingConstraint>> $mentioned */
    $mentioned = [];
    foreach ($pending as $constraint) {
        if (! isset($state->classes[$constraint->class])) {
            continue;
        }

        $vars = [];
        foreach ($constraint->args as $arg) {
            foreach (signatureVarsInType(prune($state, $arg), $known) as $name => $_) {
                $vars[$name] = true;
            }
        }
        foreach ($vars as $name => $_) {
            $mentioned[$name][] = $constraint;
        }
    }

    foreach ($mentioned as $name => $constraints) {
        if (isset($bound[$name])) {
            continue;
        }

        $defaultable = defaultableConstraintsOnVar($state, $constraints, $name);
        if ($defaultable === null) {
            continue;
        }

        foreach (numericDefaultTypes() as $candidate) {
            if (constraintsHoldAtType($state, $defaultable, $name, $candidate)) {
                unify($state, new TVar($name), $candidate);
                break;
            }
        }
    }
}

/**
 * The requirements a default for `$varName` has to satisfy, or null when the
 * variable cannot be defaulted.
 *
 * Each constraint is reduced through the instance that would discharge it
 * (`Show [a]` needs `Show a`, so that is what has to hold), and the variable has
 * to be the whole argument of every reduced constraint that mentions it -- a
 * variable buried in a bigger argument (`Show (List a)`) is not something an
 * instance can pick a type for. At least one of the requirements has to be a
 * numeric class, which is what tells a default apart from a genuine ambiguity
 * (`show []` stays an error).
 *
 * @param list<Ast\PendingConstraint> $constraints every constraint mentioning the variable
 * @return ?list<Ast\PendingConstraint>
 */
function defaultableConstraintsOnVar(TypeCheckState $state, array $constraints, string $varName): ?array
{
    $known = \array_flip(knownTypeConstructorNames($state));
    $numericClasses = numericDefaultClasses();
    $required = [];
    $numeric = false;

    foreach ($constraints as $constraint) {
        $leaves = instanceLeafConstraints($state, $constraint) ?? [$constraint];
        foreach ($leaves as $leaf) {
            $whole = false;
            foreach ($leaf->args as $arg) {
                $arg = prune($state, $arg);
                if ($arg instanceof TVar && $arg->name === $varName) {
                    $whole = true;
                    continue;
                }
                if (isset(signatureVarsInType($arg, $known)[$varName])) {
                    return null;
                }
            }

            if (! $whole) {
                continue;
            }

            $numeric = $numeric || \in_array($leaf->class, $numericClasses, true);
            $required[] = $leaf;
        }
    }

    return ($numeric && $required !== []) ? $required : null;
}

/**
 * Reduce a constraint through the instance that would discharge it until only
 * constraints over a bare type variable are left: a `Show [a]` obligation is
 * discharged by `instance Show a => Show [a]`, so `Show a` is what remains.
 *
 * A constraint with no instance, a head that is still a variable, or an instance
 * whose context is not a plain class application (an associated family, say) is
 * its own leaf.
 *
 * @param array<string, true> $seen instance heads already reduced, so a
 *        self-referential instance cannot recurse forever
 * @return ?list<Ast\PendingConstraint>
 */
function instanceLeafConstraints(TypeCheckState $state, Ast\PendingConstraint $constraint, array $seen = []): ?array
{
    $classInfo = $state->classes[$constraint->class] ?? null;
    if ($classInfo === null) {
        return [$constraint];
    }

    try {
        $head = instanceHeadFromConstraintArgs($state, $classInfo['params'], $constraint->args);
    } catch (TypeError) {
        return [$constraint];
    }

    $head = prune($state, $head);
    if ($head instanceof TVar) {
        return [$constraint];
    }

    $record = findProjectInstanceRecord($state, $constraint->class, $head);
    if ($record === null) {
        return [$constraint];
    }

    $key = $constraint->class . ':' . typeToString($head);
    if (isset($seen[$key])) {
        return [$constraint];
    }
    $seen[$key] = true;

    $context = instanceContextRequirements($state, $record['instance'], $record['mapping']);
    if ($context === null || $context === []) {
        return [$constraint];
    }

    $leaves = [];
    foreach ($context as $nested) {
        foreach (instanceLeafConstraints($state, $nested, $seen) ?? [$nested] as $leaf) {
            $leaves[] = $leaf;
        }
    }

    return $leaves;
}

/**
 * Whether every requirement has an instance once `$varName` is `$candidate` --
 * the test that picks the first default type that fits.
 *
 * @param list<Ast\PendingConstraint> $constraints
 */
function constraintsHoldAtType(TypeCheckState $state, array $constraints, string $varName, Type $candidate): bool
{
    $substituted = [];
    foreach ($constraints as $constraint) {
        $substituted[] = new Ast\PendingConstraint(
            $constraint->class,
            \array_map(
                static fn (Type $arg): Type => substitute(prune($state, $arg), [$varName => $candidate]),
                $constraint->args,
            ),
        );
    }

    try {
        return tryResolveEvidenceExprs($state, expandConstraintsWithSuperclasses($state, $substituted)) !== null;
    } catch (TypeError) {
        // A context the candidate leaves unselectable (`Show [a]` needing `Show a`) is not
        // satisfied by it; reporting belongs to the resolution site.
        return false;
    }
}

/**
 * Replace every numeric literal whose type is not the host integer with the
 * value that type denotes: `intToInteger#` for `Integer` (whose `fromInteger` is
 * the identity), and the type's own `fromInteger` from its `Num` dictionary
 * otherwise. A literal left with its constraint pending is one nothing
 * determined, and the unresolved-constraint report names it.
 */
function elaborateNumericLiterals(TypeCheckState $state, Ast\AstNode $expr): Ast\AstNode
{
    if ($expr instanceof Ast\IntegerLit) {
        return elaborateNumericLiteral($state, $expr);
    }

    if ($expr instanceof Ast\Apply) {
        $expr->function = elaborateNumericLiterals($state, $expr->function);
        $expr->argument = elaborateNumericLiterals($state, $expr->argument);

        return $expr;
    }

    if ($expr instanceof Ast\IntrinsicCall) {
        foreach ($expr->args as $i => $arg) {
            $expr->args[$i] = elaborateNumericLiterals($state, $arg);
        }

        return $expr;
    }

    if ($expr instanceof Ast\Lambda) {
        $expr->body = elaborateNumericLiterals($state, $expr->body);

        return $expr;
    }

    if ($expr instanceof Ast\Let) {
        foreach ($expr->bindings as $binding) {
            $binding->value = elaborateNumericLiterals($state, $binding->value);
        }
        $expr->body = elaborateNumericLiterals($state, $expr->body);

        return $expr;
    }

    if ($expr instanceof Ast\Where) {
        foreach ($expr->bindings as $binding) {
            $binding->value = elaborateNumericLiterals($state, $binding->value);
        }
        $expr->expr = elaborateNumericLiterals($state, $expr->expr);

        return $expr;
    }

    if ($expr instanceof Ast\CaseExpr) {
        $expr->scrutinee = elaborateNumericLiterals($state, $expr->scrutinee);
        foreach ($expr->alts as $alt) {
            $alt->body = elaborateNumericLiterals($state, $alt->body);
        }

        return $expr;
    }

    if ($expr instanceof Ast\GuardsExpr) {
        foreach ($expr->clauses as $clause) {
            $clause->guard = elaborateNumericLiterals($state, $clause->guard);
            $clause->body = elaborateNumericLiterals($state, $clause->body);
        }

        return $expr;
    }

    if ($expr instanceof Ast\DoExpr) {
        if ($expr->desugared !== null) {
            $expr->desugared = elaborateNumericLiterals($state, $expr->desugared);
        }

        return $expr;
    }

    if ($expr instanceof Ast\Infix) {
        $expr->left = elaborateNumericLiterals($state, $expr->left);
        $expr->right = elaborateNumericLiterals($state, $expr->right);

        return $expr;
    }

    if ($expr instanceof Ast\Tuple) {
        foreach ($expr->elements as $i => $element) {
            $expr->elements[$i] = elaborateNumericLiterals($state, $element);
        }

        return $expr;
    }

    if ($expr instanceof Ast\ListLit) {
        foreach ($expr->elements as $i => $element) {
            $expr->elements[$i] = elaborateNumericLiterals($state, $element);
        }

        return $expr;
    }

    if ($expr instanceof Ast\TypeAsc) {
        $expr->expr = elaborateNumericLiterals($state, $expr->expr);

        return $expr;
    }

    if ($expr instanceof Ast\RecordCon) {
        foreach ($expr->fields as $field) {
            $field->expr = elaborateNumericLiterals($state, $field->expr);
        }

        return $expr;
    }

    if ($expr instanceof Ast\RecordUpdate) {
        $expr->object = elaborateNumericLiterals($state, $expr->object);
        foreach ($expr->fields as $field) {
            $field->expr = elaborateNumericLiterals($state, $field->expr);
        }

        return $expr;
    }

    if ($expr instanceof Ast\FieldAccess) {
        $expr->object = elaborateNumericLiterals($state, $expr->object);

        return $expr;
    }

    return $expr;
}

function elaborateNumericLiteral(TypeCheckState $state, Ast\IntegerLit $expr): Ast\AstNode
{
    if ($expr->pendingConstraints === []) {
        return $expr;
    }

    $constraints = refreshConstraintArgs($state, $expr->pendingConstraints);
    // The literal's type may spell a machine type as the constructor the printer writes
    // (`TCon('Int')`); normalise before asking whether it is the host integer.
    $type = canonicalPrimitiveType(prune($state, $constraints[0]->args[0] ?? freshType($state)));

    // The host integer is the `Int` value itself.
    if ($type instanceof TInt) {
        $expr->pendingConstraints = [];

        return $expr;
    }

    if (! isset($state->classes['Num'])) {
        return $expr;
    }

    // `fromInteger` takes an `Integer`, so the argument is a bignum on every backend; a
    // literal too large for the host int is built from its digits.
    $integerValue = $expr->digits === null
        ? new Ast\IntrinsicCall(
            'intToInteger#',
            [new Ast\IntegerLit($expr->value, $expr->line, $expr->col, $expr->endCol)],
            $expr->line,
            $expr->col,
            $expr->endCol,
        )
        : new Ast\IntrinsicCall(
            'integerFromDigits#',
            [new Ast\StringLit($expr->digits, $expr->line, $expr->col, $expr->endCol)],
            $expr->line,
            $expr->col,
            $expr->endCol,
        );
    $integerValue->intrinsicId = $expr->digits === null ? 'intToInteger#' : 'integerFromDigits#';
    $integerValue->inferredType = $expr->inferredType;

    if ($type instanceof TCon && $type->name === 'Integer' && $type->args === []) {
        $expr->pendingConstraints = [];

        return $integerValue;
    }

    $evidence = tryResolveEvidenceExprs(
        $state,
        expandConstraintsWithSuperclasses($state, $constraints),
    );
    if ($evidence === null) {
        return $expr;
    }

    $method = prependEvidenceToCall(
        $state,
        new Ast\Variable('fromInteger', $expr->line, $expr->col, $expr->endCol),
        $evidence,
    );
    $expr->pendingConstraints = [];
    $converted = new Ast\Apply($method, $integerValue, $expr->line, $expr->col, $expr->endCol);
    $converted->inferredType = $expr->inferredType;

    return $converted;
}
