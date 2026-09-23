<?php declare(strict_types=1);

namespace Moggi\Syntax;

/**
 * Constructor operators: symbolic names that start with `:`
 * (excluding reserved `:` list cons and `::` type ascription).
 */
function isConstructorOperator(string $name): bool
{
    return $name !== ''
        && $name[0] === ':'
        && $name !== ':'
        && $name !== '::';
}

/**
 * Type-level operators usable as type constructors (e.g. `:+:`, `:*:`, `:.:`).
 * Same surface rule as constructor operators for this roadmap.
 */
function isTypeOperator(string $name): bool
{
    return isConstructorOperator($name);
}

/**
 * Data constructor names: ConId (uppercase start) or constructor operators.
 */
function isConstructorName(string $name): bool
{
    if ($name === '') {
        return false;
    }

    if (isConstructorOperator($name)) {
        return true;
    }

    return $name[0] >= 'A' && $name[0] <= 'Z';
}
