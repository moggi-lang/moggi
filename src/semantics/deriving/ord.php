<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeCheckState;

/**
 * Stock `deriving Ord`: emit `compare`; TC fills `< <= > >= min max`.
 */
function deriveOrd(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $head = dataDeclHeadAst($decl);
    // Stock Ord context: Ord on parameters that appear in fields (Eq comes
    // from the Ord superclass).
    $constraints = paramClassConstraints($decl, 'Ord');

    return new DerivedInstance(
        'Ord',
        $head,
        $constraints,
        [
            methodDecl(
                'compare',
                [new Ast\PatVar('__l'), new Ast\PatVar('__r')],
                synthesizeOrdCompare($decl, '__l', '__r'),
                $ref,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

function synthesizeOrdCompare(Ast\DataDecl $decl, string $left, string $right): Ast\AstNode
{
    if ($decl->constructors === []) {
        return new Ast\ConstructorRef('EQ');
    }

    // Tag index: earlier constructor is smaller.
    $alts = [];
    foreach ($decl->constructors as $i => $ctor) {
        $leftPats = freshPatVars('__a', count($ctor->fields));
        $leftPat = new Ast\PatCon($ctor->name, $leftPats);
        $leftNames = patVarNames($leftPats);

        $rightAlts = [];
        foreach ($decl->constructors as $j => $other) {
            $rightPats = freshPatVars('__b', count($other->fields));
            $rightPat = new Ast\PatCon($other->name, $rightPats);
            if ($i < $j) {
                $body = new Ast\ConstructorRef('LT');
            } elseif ($i > $j) {
                $body = new Ast\ConstructorRef('GT');
            } else {
                $body = synthesizeFieldCompares($leftNames, patVarNames($rightPats));
            }
            $rightAlts[] = new Ast\Alt($rightPat, $body);
        }

        $alts[] = new Ast\Alt(
            $leftPat,
            new Ast\CaseExpr(new Ast\Variable($right), $rightAlts),
        );
    }

    return new Ast\CaseExpr(new Ast\Variable($left), $alts);
}

/**
 * @param list<string> $left
 * @param list<string> $right
 */
function synthesizeFieldCompares(array $left, array $right): Ast\AstNode
{
    if ($left === []) {
        return new Ast\ConstructorRef('EQ');
    }

    // Lexicographic: compare field0; on EQ continue.
    $expr = new Ast\ConstructorRef('EQ');
    for ($i = count($left) - 1; $i >= 0; --$i) {
        $cmp = compareCall(new Ast\Variable($left[$i]), new Ast\Variable($right[$i]));
        $expr = new Ast\CaseExpr($cmp, [
            new Ast\Alt(new Ast\PatCon('EQ', []), $expr),
            new Ast\Alt(new Ast\PatCon('LT', []), new Ast\ConstructorRef('LT')),
            new Ast\Alt(new Ast\PatCon('GT', []), new Ast\ConstructorRef('GT')),
        ]);
    }

    return $expr;
}
