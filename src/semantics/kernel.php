<?php declare(strict_types=1);

namespace Moggi\Semantics\Kernel;

/**
 * What the language mandates, stated once.
 *
 * The library owns names: types, constructors, classes and their methods. The compiler owns the machine:
 * its stages, its IR, the hosts it emits. Between the two sit the few facts the language itself needs
 * *before* a library is in scope — the two-valued type the machine represents as the host boolean, what
 * a class method is *for*, and which of the library's types the foreign boundary converts — and this
 * file is the compiler's single statement of each: one key per fact, so no stage spells a library name
 * to ask which constructor is true, which method is the bind, which method converts a literal, or which
 * type crosses the boundary.
 *
 * A fact no declaration states has to be a key here, and most of them are: a `foreign <backend> type`
 * names a *host* type the library does not declare, so it cannot describe a type that already has a
 * `data` declaration, and nothing in the library says a type is the boundary's. Where a declaration
 * does state the fact, this file reads it instead: a role is implied by the method's own signature —
 * the bind is the method `m a -> (a -> m b) -> m b`, the unit is `a -> f a` — so `Types\impliedRoles`
 * reads the role off each method it registers, the class table carries it, and a reader asks for the
 * role and is told the method the declaration already stated by its type.
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

// --- The types the foreign boundary converts ---------------------------------

/** The type an open connection the host owns is written with in a foreign signature. */
function boundaryHandleTypeName(): string
{
    return 'Handle';
}

/** The backing resource an opaque handle wraps, as the backend represents it. */
function boundaryResourceTypeName(): string
{
    return 'Resource';
}

/** php's reified host value: an untyped host value boxed into an inspectable declaration. */
function boundaryReifiedTypeName(): string
{
    return 'PHPValue';
}

/** The container the boundary reads a payload that may be absent out of. */
function boundaryMaybeTypeName(): string
{
    return 'Maybe';
}

/** The container the boundary reads either a payload or the message a host call failed with out of. */
function boundaryEitherTypeName(): string
{
    return 'Either';
}

/** The declared name of the kernel's own string, the type a host call reports text with. */
function boundaryStringTypeName(): string
{
    return 'String';
}

/** Either name that means "an opaque value of the host's the program hands back". */
function isBoundaryHandleType(string $name): bool
{
    return $name === boundaryHandleTypeName() || $name === boundaryResourceTypeName();
}

/** Either of the transparent containers, `Maybe` and `Either`, whose payload is what crosses. */
function isBoundaryContainerType(string $name): bool
{
    return $name === boundaryMaybeTypeName() || $name === boundaryEitherTypeName();
}
