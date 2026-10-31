<?php declare(strict_types=1);

namespace Moggi\IR\Projection;

/**
 * The projection a callee names — its field index and which of the two spellings it used — or null when
 * the callee names no projection.
 *
 * Lowering writes a field read as a call to a generated callee: `__fieldN` for a record, `__tuple_fieldN`
 * for a tuple, with `N` the field's position in its constructor. The index is therefore part of the
 * callee's spelling and is stated nowhere else, which makes the *spelling* the identity — so only the
 * canonical form answers. A name that merely begins with the prefix (`__fieldTotal`) is an ordinary
 * function, and reading it as field 0 would fold a call, or emit a field read, on a fact the IR never
 * stated. Every reader — the optimizer's folds and the three emitters — asks this one function.
 *
 * A canonical form is only ever written by lowering, because the checker refuses a source declaration
 * in these shapes (`assertNotGeneratedName()`), so a callee that reaches a reader in one of them was
 * generated and names a projection.
 *
 * @return array{index: int, tuple: bool}|null
 */
function parse(string $callee): ?array
{
    $leaf = leafName($callee);
    foreach (['__tuple_field' => true, '__field' => false] as $prefix => $tuple) {
        if (! \str_starts_with($leaf, $prefix)) {
            continue;
        }
        $digits = \substr($leaf, \strlen($prefix));
        if ($digits === '' || ! \ctype_digit($digits) || ($digits[0] === '0' && $digits !== '0')) {
            return null;
        }

        return ['index' => (int) $digits, 'tuple' => $tuple];
    }

    return null;
}

/** The field index a projection names, or null when the callee names no projection. */
function index(string $callee): ?int
{
    $projection = parse($callee);

    return $projection === null ? null : $projection['index'];
}

/**
 * The arity a tuple-construction callee names, or null when the callee is not one.
 *
 * The same spelling-identity rule as `parse()`: lowering writes tuple construction as `__tupleN`, and only
 * the canonical decimal form names an arity, so `__tuple2abc` is an ordinary function rather than a
 * two-element array.
 */
function tupleArity(string $callee): ?int
{
    $leaf = leafName($callee);
    $prefix = '__tuple';
    if (! \str_starts_with($leaf, $prefix)) {
        return null;
    }

    $digits = \substr($leaf, \strlen($prefix));
    if ($digits === '' || ! \ctype_digit($digits) || ($digits[0] === '0' && $digits !== '0')) {
        return null;
    }

    return (int) $digits;
}

/** The unqualified last segment of a callee name. */
function leafName(string $callee): string
{
    $separator = \strrpos($callee, '::');
    if ($separator !== false) {
        return \substr($callee, $separator + 2);
    }

    $separator = \strrpos($callee, '\\');
    if ($separator !== false) {
        return \substr($callee, $separator + 1);
    }

    return $callee;
}
