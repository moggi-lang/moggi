<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeCheckState;

/**
 * Stock `deriving Foldable` over the last type parameter (`foldr` only).
 */
function deriveFoldable(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $param = lastDataParamName($decl);
    if ($param === null) {
        throw typeFailDerive(
            $state,
            'cannot derive Foldable: type has no type parameter',
            $ref,
        );
    }

    validateFunctorialStructure($state, $decl, $param, $ref, 'Foldable');

    return new DerivedInstance(
        'Foldable',
        functorialHeadAst($decl),
        functorialContextConstraints($decl, $param, 'Foldable'),
        [
            methodDecl(
                'foldr',
                [new Ast\PatVar('__f'), new Ast\PatVar('__z'), new Ast\PatVar('__x')],
                synthesizeFoldableFoldr($decl, $param, '__f', '__z', '__x'),
                $ref,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

function synthesizeFoldableFoldr(
    Ast\DataDecl $decl,
    string $param,
    string $f,
    string $z,
    string $x,
): Ast\AstNode {
    if ($decl->constructors === []) {
        return new Ast\Variable($z);
    }

    $alts = [];
    foreach ($decl->constructors as $ctor) {
        $pats = freshPatVars('__v', count($ctor->fields));
        $names = patVarNames($pats);
        // Left-to-right: foldr f (foldr_fields_rest) field0
        $acc = new Ast\Variable($z);
        for ($i = count($ctor->fields) - 1; $i >= 0; --$i) {
            $acc = synthesizeFoldableField(
                $ctor->fields[$i]->type,
                $param,
                new Ast\Variable($f),
                $acc,
                new Ast\Variable($names[$i]),
            );
        }
        $alts[] = new Ast\Alt(new Ast\PatCon($ctor->name, $pats), $acc);
    }

    return new Ast\CaseExpr(new Ast\Variable($x), $alts);
}

function synthesizeFoldableField(
    Ast\TypeNode $type,
    string $param,
    Ast\AstNode $f,
    Ast\AstNode $z,
    Ast\AstNode $value,
): Ast\AstNode {
    $c = classifyFunctorField($type, $param);
    return match ($c['tag']) {
        'param' => new Ast\Apply(new Ast\Apply($f, $value), $z),
        'skip' => $z,
        'app' => foldableNestedFold(
            $type->args[count($type->args) - 1],
            $param,
            $f,
            $z,
            $value,
        ),
        default => $z,
    };
}

/**
 * `foldr` one constructor level of a nested field.
 *
 * `$inner` is the type of the elements the enclosing structure holds --
 * `Maybe a` for a `Maybe (Maybe a)` field -- so the call is
 * `foldr (step over $inner) z value`: `foldableFoldStep($inner)` for
 * `Maybe a` is `\x acc -> foldr f acc x`.
 */
function foldableNestedFold(
    Ast\TypeNode $inner,
    string $param,
    Ast\AstNode $f,
    Ast\AstNode $z,
    Ast\AstNode $value,
): Ast\AstNode {
    return new Ast\Apply(
        new Ast\Apply(
            new Ast\Apply(new Ast\Variable('foldr'), foldableFoldStep($inner, $param, $f)),
            $z,
        ),
        $value,
    );
}

/**
 * The callback one element of `$type` is folded with.
 *
 * For the parameter itself that is `f`. For a further application the inner fold
 * is the whole fold of that element, and `foldr` takes the accumulator *first*,
 * so the two arguments are flipped: composing the folds without the flip feeds
 * the enclosing accumulator to the inner element position.
 */
function foldableFoldStep(Ast\TypeNode $type, string $param, Ast\AstNode $f): Ast\AstNode
{
    if ($type instanceof Ast\TypeVar && $type->name === $param) {
        return $f;
    }

    return new Ast\Lambda(
        [new Ast\LambdaParam(new Ast\PatVar('__x')), new Ast\LambdaParam(new Ast\PatVar('__acc'))],
        foldableNestedFold(
            $type->args[count($type->args) - 1],
            $param,
            $f,
            new Ast\Variable('__acc'),
            new Ast\Variable('__x'),
        ),
    );
}
