<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeCheckState;

/**
 * Stock `deriving Enum` for nullary-constructor enumerations.
 */
function deriveEnum(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    assertNullaryConstructors($state, $decl, $ref, 'Enum');
    if ($decl->params !== []) {
        throw typeFailDerive(
            $state,
            'cannot derive Enum: type has parameters (Enum requires a nullary type)',
            $ref,
        );
    }

    $ctors = $decl->constructors;
    $head = dataDeclHeadAst($decl);

    return new DerivedInstance(
        'Enum',
        $head,
        [],
        [
            methodDecl('fromEnum', [new Ast\PatVar('__x')], synthesizeFromEnum($ctors, '__x'), $ref),
            methodDecl('toEnum', [new Ast\PatVar('__i')], synthesizeToEnum($ctors, '__i'), $ref),
            methodDecl('succ', [new Ast\PatVar('__x')], synthesizeSucc($ctors, '__x'), $ref),
            methodDecl('pred', [new Ast\PatVar('__x')], synthesizePred($ctors, '__x'), $ref),
        ],
        $ref,
        strategy: 'Stock',
    );
}

/** @param list<Ast\ConstructorDecl> $ctors */
function synthesizeFromEnum(array $ctors, string $x): Ast\AstNode
{
    $alts = [];
    foreach ($ctors as $i => $ctor) {
        $alts[] = new Ast\Alt(
            new Ast\PatCon($ctor->name, []),
            new Ast\IntegerLit($i),
        );
    }

    return new Ast\CaseExpr(new Ast\Variable($x), $alts);
}

/** @param list<Ast\ConstructorDecl> $ctors */
function synthesizeToEnum(array $ctors, string $i): Ast\AstNode
{
    $alts = [];
    foreach ($ctors as $k => $ctor) {
        $alts[] = new Ast\Alt(
            new Ast\PatLit($k),
            new Ast\ConstructorRef($ctor->name),
        );
    }
    $alts[] = new Ast\Alt(
        new Ast\PatWild(),
        applyVar('error', new Ast\StringLit('Enum.toEnum: out of range')),
    );

    return new Ast\CaseExpr(new Ast\Variable($i), $alts);
}

/** @param list<Ast\ConstructorDecl> $ctors */
function synthesizeSucc(array $ctors, string $x): Ast\AstNode
{
    $alts = [];
    $n = count($ctors);
    foreach ($ctors as $i => $ctor) {
        $body = $i + 1 < $n
            ? new Ast\ConstructorRef($ctors[$i + 1]->name)
            : applyVar('error', new Ast\StringLit('Enum.succ: bad argument'));
        $alts[] = new Ast\Alt(new Ast\PatCon($ctor->name, []), $body);
    }

    return new Ast\CaseExpr(new Ast\Variable($x), $alts);
}

/** @param list<Ast\ConstructorDecl> $ctors */
function synthesizePred(array $ctors, string $x): Ast\AstNode
{
    $alts = [];
    foreach ($ctors as $i => $ctor) {
        $body = $i > 0
            ? new Ast\ConstructorRef($ctors[$i - 1]->name)
            : applyVar('error', new Ast\StringLit('Enum.pred: bad argument'));
        $alts[] = new Ast\Alt(new Ast\PatCon($ctor->name, []), $body);
    }

    return new Ast\CaseExpr(new Ast\Variable($x), $alts);
}
