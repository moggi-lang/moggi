<?php declare(strict_types=1);

namespace Moggi\Semantics\Kernel;

/**
 * What the language mandates, stated once.
 *
 * The library owns names (types, constructors, classes and methods); the compiler owns stages, IR
 * and target hosts. This file bridges them with facts needed before a library is in scope: the
 * two-valued type represented as a host boolean, foreign-boundary types, and facts not otherwise
 * declared. Each fact has one key, so compiler stages never hardcode library names.
 *
 * Undeclared facts must be keys here. A `foreign <backend> type` names a host type, not a type
 * declared by the library, and cannot identify boundary types on its own. Where declarations
 * suffice, this file reads them instead: `Types\impliedRoles` derives method roles from signatures
 * (bind: `m a -> (a -> m b) -> m b`; unit: `a -> f a`), the class table carries those roles, and
 * readers resolve them to the methods already specified by their types.
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

// --- The types the foreign boundary converts ----------------------------------

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
