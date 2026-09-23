<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeCheckState;

/**
 * Stock `deriving Show`
 *
 * `showsPrec` is the rendered form, `show` is the class default
 * (`showsPrec 0`), so every constructor has to place itself at its own
 * precedence instead of concatenating blindly:
 *
 *   - a nullary constructor never parenthesizes;
 *   - a prefix constructor is application, so it renders at `appPrec = 10` and
 *     parenthesizes above it, with every field at `appPrec + 1 = 11`;
 *   - an infix constructor renders at its own fixity precedence, with both
 *     operands one tighter;
 *   - a record constructor renders as `C {field = value, ...}`, with field
 *     values at precedence 0 and the same parenthesis threshold as a prefix
 *     constructor.
 *
 * The parentheses are the difference between `Just (Just 3)` and `Just Just 3`.
 */
function deriveShow(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    return new DerivedInstance(
        'Show',
        dataDeclHeadAst($decl),
        paramClassConstraints($decl, 'Show'),
        [
            methodDecl(
                'showsPrec',
                [new Ast\PatVar('__d'), new Ast\PatVar('__x'), new Ast\PatVar('__s')],
                synthesizeShowBody($decl, '__x', '__d', '__s'),
                $ref,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

/** Application precedence: a prefix constructor and its fields. */
const SHOW_APP_PREC = 10;

/** An infix constructor with no `infix*` declaration is `infixl 9`. */
const SHOW_DEFAULT_OP_PREC = 9;

function synthesizeShowBody(Ast\DataDecl $decl, string $x, string $d, string $s): Ast\AstNode
{
    if ($decl->constructors === []) {
        return applyVar('error', new Ast\StringLit('Show.show: empty data type'));
    }

    $alts = [];
    foreach ($decl->constructors as $ctor) {
        $pats = freshPatVars('__f', count($ctor->fields));
        $alts[] = new Ast\Alt(
            new Ast\PatCon($ctor->name, $pats),
            synthesizeShowConstructor(
                $ctor,
                patVarNames($pats),
                array_map(static fn (Ast\CtorField $f): string => $f->name, $ctor->fields),
                $d,
                $s,
            ),
        );
    }

    return new Ast\CaseExpr(new Ast\Variable($x), $alts);
}

/**
 * One constructor's `ShowS`, parenthesized when the enclosing context is
 * tighter than the constructor.
 *
 * @param list<string> $fieldNames the bound variables holding each field
 * @param list<string> $fieldLabels the declared record labels (empty for a
 *   positional constructor)
 */
function synthesizeShowConstructor(
    Ast\ConstructorDecl $ctor,
    array $fieldNames,
    array $fieldLabels,
    string $d,
    string $s,
): Ast\AstNode {
    // A nullary constructor is its own name at every context.
    if ($fieldNames === []) {
        return applyVar(
            'showString',
            new Ast\StringLit(showConstructorName($ctor)),
            new Ast\Variable($s),
        );
    }

    if ($ctor->isRecord()) {
        $rendered = showRecordConstructor(showConstructorName($ctor), $fieldNames, $fieldLabels);

        return showParenIfTighter(SHOW_APP_PREC, $rendered, $d, $s);
    }

    $prec = $ctor->declaredInfix
        ? ($ctor->fixityPrec ?? SHOW_DEFAULT_OP_PREC)
        : SHOW_APP_PREC;

    // The rendered form is a `ShowS`, so `showParen` applies it to the
    // continuation it was handed.
    $rendered = $ctor->declaredInfix
        ? showInfixConstructor($ctor->name, $fieldNames, $prec + 1)
        : showPrefixConstructor(showConstructorName($ctor), $fieldNames, $prec + 1);

    return showParenIfTighter($prec, $rendered, $d, $s);
}

function showConstructorName(Ast\ConstructorDecl $ctor): string
{
    return isSymbolicName($ctor->name) ? '(' . $ctor->name . ')' : $ctor->name;
}

/**
 * Alphanumeric names never take constructor parens; a symbolic name is always
 * written `(:<>:)`, in the operator position as well as in record syntax.
 */
function isSymbolicName(string $name): bool
{
    return $name !== '' && !ctype_alpha($name[0]);
}

function showParenIfTighter(int $prec, Ast\AstNode $rendered, string $d, string $s): Ast\AstNode
{
    return applyVar(
        'showParen',
        applyOp('>', new Ast\Variable($d), new Ast\IntegerLit($prec)),
        new Ast\Lambda([new Ast\LambdaParam(new Ast\PatVar('__r'))], $rendered),
        new Ast\Variable($s),
    );
}

/** `showString "C " . showsPrec 11 a0 . showChar ' ' . showsPrec 11 a1` */
function showPrefixConstructor(string $ctorName, array $fieldNames, int $argPrec): Ast\AstNode
{
    $body = showPrecField($fieldNames[count($fieldNames) - 1], $argPrec, new Ast\Variable('__r'));
    for ($i = count($fieldNames) - 2; $i >= 0; --$i) {
        $body = showPrecField(
            $fieldNames[$i],
            $argPrec,
            applyVar('showChar', new Ast\CharLit(32), $body),
        );
    }

    return applyVar('showString', new Ast\StringLit($ctorName . ' '), $body);
}

/** `showsPrec 7 a . showString " :*: " . showsPrec 7 b` */
function showInfixConstructor(string $ctorName, array $fieldNames, int $argPrec): Ast\AstNode
{
    $body = showPrecField($fieldNames[1], $argPrec, new Ast\Variable('__r'));
    $body = applyVar('showString', new Ast\StringLit(' ' . $ctorName . ' '), $body);

    return showPrecField($fieldNames[0], $argPrec, $body);
}

/**
 * `showString "C {f = " . showsPrec 0 a . showString ", " . showString "g = "
 * . showsPrec 0 b . showString "}"`
 *
 * Record fields are shown at precedence 0, so they never pick up parentheses.
 *
 * @param list<string> $fieldNames the bound variables holding each field
 * @param list<string> $fieldLabels the declared record labels
 */
function showRecordConstructor(string $ctorName, array $fieldNames, array $fieldLabels): Ast\AstNode
{
    $body = applyVar('showString', new Ast\StringLit('}'), new Ast\Variable('__r'));

    for ($i = count($fieldNames) - 1; $i >= 0; --$i) {
        $body = showPrecField($fieldNames[$i], 0, $body);
        $body = applyVar('showString', new Ast\StringLit($fieldLabels[$i] . ' = '), $body);
        if ($i > 0) {
            $body = applyVar('showString', new Ast\StringLit(', '), $body);
        }
    }

    return applyVar('showString', new Ast\StringLit($ctorName . ' {'), $body);
}

function showPrecField(string $name, int $prec, Ast\AstNode $rest): Ast\AstNode
{
    return applyVar('showsPrec', new Ast\IntegerLit($prec), new Ast\Variable($name), $rest);
}
