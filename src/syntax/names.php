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
    return \preg_match('/^' . IDENTIFIER_PATTERN . '$/', $name) === 1;
}

/** Whether `$name` is an identifier a target language can spell unchanged. */
function isSafeName(string $name): bool
{
    return \preg_match('/^' . SAFE_NAME_PATTERN . '$/', $name) === 1;
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
 * The child list an export list gives a type, when it names one: a module that
 * writes `Solo (Solo, MkSolo)` has one, `Solo(..)` does not.
 *
 * @param ?list<array{tag: string, name: string, path?: list<string>, children?: array{mode: string, names: list<string>}, section?: string}> $exports
 * @return ?array{mode: string, names: list<string>}
 */
function exportedChildrenSpec(?array $exports, string $typeName): ?array
{
    foreach ($exports ?? [] as $export) {
        if (($export['name'] ?? null) === $typeName && isset($export['children'])) {
            return $export['children'];
        }
    }

    return null;
}

/**
 * The constructor a type's own name aliases, read off the library's export list.
 *
 * `libraries/base/src/Data/Tuple.mog` writes `Solo (Solo, MkSolo)` for a type whose only
 * declared constructor is `MkSolo`: the export list is the library saying that
 * both names construct and match, and the compiler only reads it. A type with
 * more than one declared constructor has to say which one it means, so the
 * single-constructor case is the one that aliases.
 *
 * The rule is deliberately not a `Mk<Type>` naming convention: `MkMap`, `MkSet`,
 * `MkDay`, `MkHandle` and `MkResource` all have that shape and none of them asks
 * for an alias -- `System.IO.Types` wants `MkHandle` to stay the only way to
 * build a handle.
 *
 * @param list<string> $constructors the declared constructor names
 * @param ?array{mode: string, names: list<string>} $children the export item's child list
 * @return ?string the declared constructor `$typeName` aliases, or null
 */
function surfaceConstructorAlias(string $typeName, array $constructors, ?array $children): ?string
{
    if (($children['mode'] ?? 'none') !== 'listed') {
        return null;
    }
    if (! \in_array($typeName, $children['names'], true)) {
        return null;
    }
    if (\in_array($typeName, $constructors, true)) {
        return null;
    }

    return \count($constructors) === 1 ? $constructors[0] : null;
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
