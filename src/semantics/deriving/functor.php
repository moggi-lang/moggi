<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeCheckState;

/**
 * Stock `deriving Functor` over the last type parameter.
 */
function deriveFunctor(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $param = lastDataParamName($decl);
    if ($param === null) {
        throw typeFailDerive(
            $state,
            'cannot derive Functor: type has no type parameter',
            $ref,
        );
    }

    validateFunctorialStructure($state, $decl, $param, $ref, 'Functor');

    return new DerivedInstance(
        'Functor',
        functorialHeadAst($decl),
        functorialContextConstraints($decl, $param, 'Functor'),
        [
            methodDecl(
                'map',
                [new Ast\PatVar('__f'), new Ast\PatVar('__x')],
                synthesizeFunctorMap($decl, $param, '__f', '__x'),
                $ref,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

function validateFunctorialStructure(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    string $param,
    Ast\DerivingClassRef $ref,
    string $className,
): void {
    foreach ($decl->constructors as $ctor) {
        foreach ($ctor->fields as $field) {
            $c = classifyFunctorField($field->type, $param);
            if (($c['tag'] ?? '') === 'bad') {
                $reason = $c['reason'] ?? 'unsupported';
                throw typeFailDerive(
                    $state,
                    "cannot derive {$className}: field type `"
                        . Ast\dumpTypeInline($field->type)
                        . "` is not {$className}-derivable ({$reason})",
                    $ref,
                );
            }
        }
    }
}

/**
 * Extra `Functor f` (etc.) constraints for higher-kinded field heads.
 *
 * @return list<Ast\TypeNode>
 */
function functorialContextConstraints(
    Ast\DataDecl $decl,
    string $param,
    string $className,
): array {
    $needed = [];
    foreach ($decl->constructors as $ctor) {
        foreach ($ctor->fields as $field) {
            collectFunctorHeadConstraints($field->type, $param, $className, $needed);
        }
    }

    $out = [];
    foreach ($needed as $headName => $_) {
        $out[] = new Ast\TypeApp(new Ast\TypeCon($className), [new Ast\TypeVar($headName)]);
    }

    return $out;
}

/** @param array<string, true> $needed */
function collectFunctorHeadConstraints(
    Ast\TypeNode $type,
    string $param,
    string $className,
    array &$needed,
): void {
    if (!typeMentionsParam($type, $param)) {
        return;
    }
    if ($type instanceof Ast\TypeVar) {
        return;
    }
    if ($type instanceof Ast\TypeApp) {
        $head = $type->con;
        // Drop last arg and recurse into earlier args + head.
        $args = $type->args;
        if ($args !== []) {
            $last = $args[count($args) - 1];
            collectFunctorHeadConstraints($last, $param, $className, $needed);
            for ($i = 0; $i < count($args) - 1; ++$i) {
                if (typeMentionsParam($args[$i], $param)) {
                    // Param in non-last position of an application — reject elsewhere.
                }
            }
        }
        if ($head instanceof Ast\TypeVar) {
            $needed[$head->name] = true;
        } elseif ($head instanceof Ast\TypeApp) {
            collectFunctorHeadConstraints($head, $param, $className, $needed);
        }

        return;
    }
}

function synthesizeFunctorMap(
    Ast\DataDecl $decl,
    string $param,
    string $f,
    string $x,
): Ast\AstNode {
    if ($decl->constructors === []) {
        return applyVar('error', new Ast\StringLit('Functor.map: empty data type'));
    }

    $alts = [];
    foreach ($decl->constructors as $ctor) {
        $pats = freshPatVars('__v', count($ctor->fields));
        $names = patVarNames($pats);
        $mapped = [];
        foreach ($ctor->fields as $i => $field) {
            $mapped[] = synthesizeFunctorFieldMap($field->type, $param, new Ast\Variable($f), new Ast\Variable($names[$i]));
        }
        $body = applyCtor($ctor->name, $mapped);
        $alts[] = new Ast\Alt(new Ast\PatCon($ctor->name, $pats), $body);
    }

    return new Ast\CaseExpr(new Ast\Variable($x), $alts);
}

function synthesizeFunctorFieldMap(
    Ast\TypeNode $type,
    string $param,
    Ast\AstNode $f,
    Ast\AstNode $value,
): Ast\AstNode {
    $c = classifyFunctorField($type, $param);
    return match ($c['tag']) {
        'param' => new Ast\Apply($f, $value),
        'skip' => $value,
        // `map f`, `map (map f)`, … applied to the field value.
        'app' => new Ast\Apply(functorMapComposer($type, $param, $f), $value),
        default => $value,
    };
}

/**
 * Build `f`, `map f`, `map (map f)`, … for nested last-slot applications.
 */
function functorMapComposer(Ast\TypeNode $type, string $param, Ast\AstNode $f): Ast\AstNode
{
    if ($type instanceof Ast\TypeVar && $type->name === $param) {
        return $f;
    }
    if ($type instanceof Ast\TypeApp && $type->args !== []) {
        $last = $type->args[count($type->args) - 1];

        return new Ast\Apply(new Ast\Variable('map'), functorMapComposer($last, $param, $f));
    }

    return $f;
}
