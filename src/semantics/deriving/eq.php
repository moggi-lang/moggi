<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeCheckState;

/**
 * Stock `deriving Eq`: synthesize `instance Eq … where (==) = …; (/=) = …`.
 *
 * Field comparisons use `(==)` so `Eq τ` is required via the constraint solver
 * (no hard-coded Maybe/List). Recursive fields resolve through the instance
 * being defined (projectInstances + stockDeriving polymorphic dispatch).
 */
function deriveEq(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $head = dataDeclHeadAst($decl);
    $constraints = paramClassConstraints($decl, 'Eq');
    $methods = [
        new Ast\FunctionDecl(
            '==',
            null,
            [new Ast\PatVar('__l'), new Ast\PatVar('__r')],
            synthesizeEqBody($decl, '__l', '__r'),
            instanceMethod: true,
            line: $ref->line,
            col: $ref->col,
            endCol: $ref->endCol,
        ),
        new Ast\FunctionDecl(
            '/=',
            null,
            [new Ast\PatVar('__l'), new Ast\PatVar('__r')],
            synthesizeNeBody($decl, '__l', '__r'),
            instanceMethod: true,
            line: $ref->line,
            col: $ref->col,
            endCol: $ref->endCol,
        ),
    ];

    return new DerivedInstance(
        'Eq',
        $head,
        $constraints,
        $methods,
        $ref,
        strategy: 'Stock',
    );
}

/**
 * Nested case over constructors; matching ctor pairs compare fields with `(==)`.
 */
function synthesizeEqBody(Ast\DataDecl $decl, string $left, string $right): Ast\AstNode
{
    if ($decl->constructors === []) {
        // Empty data: no values; still a total function on the uninhabited type.
        return new Ast\ConstructorRef('True');
    }

    $alts = [];
    foreach ($decl->constructors as $ctor) {
        $leftBinders = [];
        $leftArgs = [];
        foreach ($ctor->fields as $i => $_) {
            $name = '__a' . $i;
            $leftBinders[] = $name;
            $leftArgs[] = new Ast\PatVar($name);
        }
        $leftPat = new Ast\PatCon($ctor->name, $leftArgs);

        $rightBinders = [];
        $rightArgs = [];
        foreach ($ctor->fields as $i => $_) {
            $name = '__b' . $i;
            $rightBinders[] = $name;
            $rightArgs[] = new Ast\PatVar($name);
        }
        $rightPat = new Ast\PatCon($ctor->name, $rightArgs);

        $fieldEq = synthesizeFieldEqualities($leftBinders, $rightBinders);
        $matchBody = new Ast\CaseExpr(
            new Ast\Variable($right),
            [
                new Ast\Alt($rightPat, $fieldEq),
                new Ast\Alt(new Ast\PatWild(), new Ast\ConstructorRef('False')),
            ],
        );

        $alts[] = new Ast\Alt($leftPat, $matchBody);
    }

    return new Ast\CaseExpr(new Ast\Variable($left), $alts);
}

/**
 * @param list<string> $leftBinders
 * @param list<string> $rightBinders
 */
function synthesizeFieldEqualities(array $leftBinders, array $rightBinders): Ast\AstNode
{
    if ($leftBinders === []) {
        return new Ast\ConstructorRef('True');
    }

    $expr = null;
    foreach ($leftBinders as $i => $leftName) {
        // Use Apply/OperatorRef (not Infix) so `==` goes through class-method
        // evidence / pending `Eq τ` rather than the primitive infix fast path.
        $cmp = eqCall(new Ast\Variable($leftName), new Ast\Variable($rightBinders[$i]));
        $expr = $expr === null ? $cmp : new Ast\Infix('&&', $expr, $cmp);
    }

    return $expr ?? new Ast\ConstructorRef('True');
}

function eqCall(Ast\AstNode $left, Ast\AstNode $right): Ast\AstNode
{
    return new Ast\Apply(
        new Ast\Apply(new Ast\OperatorRef('=='), $left),
        $right,
    );
}

/**
 * `(/=) l r = case <eq-body> of { True -> False; False -> True }`
 *
 * Inlines the equality decision tree instead of calling `(==)`, so we do not
 * depend on resolving the class method through ambient/instance env while the
 * `/=` method itself is being checked.
 */
function synthesizeNeBody(Ast\DataDecl $decl, string $left, string $right): Ast\AstNode
{
    return new Ast\CaseExpr(
        synthesizeEqBody($decl, $left, $right),
        [
            new Ast\Alt(new Ast\PatCon('True', []), new Ast\ConstructorRef('False')),
            new Ast\Alt(new Ast\PatCon('False', []), new Ast\ConstructorRef('True')),
        ],
    );
}
