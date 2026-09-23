<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeCheckState;

/**
 * Stock `deriving Read` for nullary enumerations: `read "Ctor" = Ctor`.
 *
 * Multi-field `Read` (`readsPrec`-style) is deferred; use newtype deriving
 * to reuse an underlying `Read` instance, or nullary constructors only.
 */
function deriveRead(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    // Type parameters are fine: a nullary constructor holds no field that would
    // need `Read`, so `data Proxy t = Proxy` derives an instance with no context.
    assertNullaryConstructors($state, $decl, $ref, 'Read');

    return new DerivedInstance(
        'Read',
        dataDeclHeadAst($decl),
        [],
        [
            methodDecl(
                'read',
                [new Ast\PatVar('__s')],
                synthesizeReadEnum($decl->constructors, '__s'),
                $ref,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

/** @param list<Ast\ConstructorDecl> $ctors */
function synthesizeReadEnum(array $ctors, string $s): Ast\AstNode
{
    $alts = [];
    foreach ($ctors as $ctor) {
        $alts[] = new Ast\Alt(
            new Ast\PatLit($ctor->name),
            new Ast\ConstructorRef($ctor->name),
        );
    }
    $alts[] = new Ast\Alt(
        new Ast\PatWild(),
        applyVar('error', new Ast\StringLit('Read.read: no parse')),
    );

    return new Ast\CaseExpr(new Ast\Variable($s), $alts);
}
