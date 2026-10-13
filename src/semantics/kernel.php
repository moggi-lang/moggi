<?php declare(strict_types=1);

namespace Moggi\Semantics\Kernel;

/**
 * What the language mandates, stated once.
 *
 * The library owns names: types, constructors, classes and their methods. The compiler owns the machine:
 * its stages, its IR, the hosts it emits. Between the two sit the few facts the language itself needs
 * *before* a library is in scope — the two-valued type the machine represents as the host boolean, and
 * what a class method is *for* — and this file is the compiler's single statement of each: one key per
 * fact, so no stage spells a library name to ask which constructor is true, which method is the bind, or
 * which method converts a literal.
 *
 * Nothing here names a library declaration, and no declaration has to be annotated for the compiler's
 * benefit: a role is implied by the method's own signature — the bind is the method `m a -> (a -> m b)
 * -> m b`, the unit is `a -> f a` — so `Types\impliedRoles` reads the role off each method it
 * registers, the class table carries it, and a reader asks for the role and is told the method the
 * declaration already stated by its type.
 */

/** The declared type the boolean key is about. */
function boolTypeName(): string
{
    return 'Bool';
}

/** That declaration's first constructor — the host `false`. */
function boolFalseCtor(): string
{
    return 'False';
}

/** That declaration's second constructor — the host `true`. */
function boolTrueCtor(): string
{
    return 'True';
}

function isBoolCtor(string $name): bool
{
    return $name === boolFalseCtor() || $name === boolTrueCtor();
}

/** Which host boolean the constructor is, or null when the name is not one of them. */
function boolCtorValue(string $name): ?bool
{
    if ($name === boolFalseCtor()) {
        return false;
    }

    return $name === boolTrueCtor() ? true : null;
}

/** The method `do`-notation sequences with. */
function bindRole(): string
{
    return 'bind';
}

/** The action that produces a value and does nothing else: the monad's unit. */
function pureRole(): string
{
    return 'pure';
}

/** The method an integer literal is converted with. */
function literalRole(): string
{
    return 'literal';
}
