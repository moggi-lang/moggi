<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeCheckState;

use function Moggi\Semantics\Types\typeFail;

/**
 * Stock `deriving Traversable`: `traverse` with Applicative (`pure` / `<*>`).
 */
function deriveTraversable(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $param = lastDataParamName($decl);
    if ($param === null) {
        throw typeFail(
            $state,
            'cannot derive Traversable: type has no type parameter',
            $ref,
        );
    }

    validateFunctorialStructure($state, $decl, $param, $ref, 'Traversable');

    return new DerivedInstance(
        'Traversable',
        functorialHeadAst($decl),
        functorialContextConstraints($decl, $param, 'Traversable'),
        [
            methodDecl(
                'traverse',
                [new Ast\PatVar('__f'), new Ast\PatVar('__x')],
                synthesizeTraverse($decl, $param, '__f', '__x'),
                $ref,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

function synthesizeTraverse(
    Ast\DataDecl $decl,
    string $param,
    string $f,
    string $x,
): Ast\AstNode {
    if ($decl->constructors === []) {
        return applyVar('error', new Ast\StringLit('Traversable.traverse: empty data type'));
    }

    $alts = [];
    foreach ($decl->constructors as $ctor) {
        $pats = freshPatVars('__v', count($ctor->fields));
        $names = patVarNames($pats);
        $body = synthesizeTraverseConstructor($ctor, $param, new Ast\Variable($f), $names);
        $alts[] = new Ast\Alt(new Ast\PatCon($ctor->name, $pats), $body);
    }

    return new Ast\CaseExpr(new Ast\Variable($x), $alts);
}

/**
 * @param list<string> $fieldNames
 */
function synthesizeTraverseConstructor(
    Ast\ConstructorDecl $ctor,
    string $param,
    Ast\AstNode $f,
    array $fieldNames,
): Ast\AstNode {
    $expr = pureCall(new Ast\ConstructorRef($ctor->name));
    foreach ($ctor->fields as $i => $field) {
        $ti = synthesizeTraverseField(
            $field->type,
            $param,
            $f,
            new Ast\Variable($fieldNames[$i]),
        );
        $expr = apCall($expr, $ti);
    }

    return $expr;
}

function synthesizeTraverseField(
    Ast\TypeNode $type,
    string $param,
    Ast\AstNode $f,
    Ast\AstNode $value,
): Ast\AstNode {
    $c = classifyFunctorField($type, $param);
    return match ($c['tag']) {
        'param' => new Ast\Apply($f, $value),
        'skip' => pureCall($value),
        'app' => new Ast\Apply(traverseComposer($type, $param, $f), $value),
        default => pureCall($value),
    };
}

function traverseComposer(Ast\TypeNode $type, string $param, Ast\AstNode $f): Ast\AstNode
{
    if ($type instanceof Ast\TypeVar && $type->name === $param) {
        return $f;
    }
    if ($type instanceof Ast\TypeApp && $type->args !== []) {
        $last = $type->args[count($type->args) - 1];

        return new Ast\Apply(new Ast\Variable('traverse'), traverseComposer($last, $param, $f));
    }

    return $f;
}
