<?php declare(strict_types=1);

namespace Moggi\Syntax;

/**
 * The language's identifier alphabet as a regex fragment: a letter or `_` first,
 * then letters, digits, `_` and `'` — `map`, `_x`, `foo1`, `f'` (the character
 * classes behind it are `Lexer\isSmall`, `Lexer\isIdCont`).
 *
 * A pattern that has to recognise a name is written from this one, or asks
 * `isIdentifier`, rather than spelling the classes again.
 */
const IDENTIFIER_PATTERN = "[A-Za-z_][A-Za-z0-9_']*";

/**
 * The identifier rule with no prime — the names a backend can spell as-is.
 *
 * PHP variables and functions, class files and IL share this alphabet, and none
 * of them has `'` in it, so a name that this rejects is either mangled on the
 * way out (`Backend\Php\Naming\basePhpFunctionName`, `mangleVar`) or refused a
 * spelling and hashed instead (`Semantics\Types\isSimpleEvidenceHead`).
 */
const SAFE_NAME_PATTERN = '[A-Za-z_][A-Za-z0-9_]*';

/**
 * Whether `$name` is an identifier — `map`, `_x`, `x'` — and not an operator,
 * a qualified name or a constructor operator.
 */
function isIdentifier(string $name): bool
{
    return preg_match('/^' . IDENTIFIER_PATTERN . '$/', $name) === 1;
}

/** Whether `$name` is an identifier a target language can spell unchanged. */
function isSafeName(string $name): bool
{
    return preg_match('/^' . SAFE_NAME_PATTERN . '$/', $name) === 1;
}

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
