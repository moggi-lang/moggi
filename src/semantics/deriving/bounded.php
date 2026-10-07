<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeCheckState;

use function Moggi\Semantics\Types\typeFail;

/**
 * Stock `deriving Bounded`.
 *
 * - Nullary enumeration: minBound = first ctor, maxBound = last ctor.
 * - Single constructor whose fields are Bounded: minBound/maxBound = Ctor minBound…
 */
function deriveBounded(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    if ($decl->constructors === []) {
        throw typeFail(
            $state,
            'cannot derive Bounded: empty data type has no constructors',
            $ref,
        );
    }

    $allNullary = true;
    foreach ($decl->constructors as $ctor) {
        if ($ctor->fields !== []) {
            $allNullary = false;
            break;
        }
    }

    if ($allNullary) {
        $first = $decl->constructors[0]->name;
        $last = $decl->constructors[count($decl->constructors) - 1]->name;

        return new DerivedInstance(
            'Bounded',
            dataDeclHeadAst($decl),
            [],
            [
                methodDecl('minBound', [], new Ast\ConstructorRef($first), $ref),
                methodDecl('maxBound', [], new Ast\ConstructorRef($last), $ref),
            ],
            $ref,
            strategy: 'Stock',
        );
    }

    if (count($decl->constructors) !== 1) {
        throw typeFail(
            $state,
            'cannot derive Bounded: multiple constructors with fields are not supported',
            $ref,
        );
    }

    return deriveBoundedProduct($decl, $ref);
}

function deriveBoundedProduct(Ast\DataDecl $decl, Ast\DerivingClassRef $ref): DerivedInstance
{
    $ctor = $decl->constructors[0];
    $constraints = paramClassConstraints($decl, 'Bounded');

    $minArgs = [];
    $maxArgs = [];
    foreach ($ctor->fields as $_) {
        $minArgs[] = new Ast\Variable('minBound');
        $maxArgs[] = new Ast\Variable('maxBound');
    }

    return new DerivedInstance(
        'Bounded',
        dataDeclHeadAst($decl),
        $constraints,
        [
            methodDecl('minBound', [], applyCtor($ctor->name, $minArgs), $ref),
            methodDecl('maxBound', [], applyCtor($ctor->name, $maxArgs), $ref),
        ],
        $ref,
        strategy: 'Stock',
    );
}
