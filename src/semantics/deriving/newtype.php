<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Kinds\classParamKind;
use function Moggi\Semantics\Types\typeFail;

/**
 * Generalized newtype deriving: reuse the underlying type's instance by
 * unwrapping / rewrapping the newtype constructor.
 *
 * Only `newtype` declarations qualify. Ordinary single-constructor `data`
 * never gets this path.
 */

/** Classes that stock-derive over a type constructor (* -> *). */
function isFunctorialClass(string $className): bool
{
    return $className === 'Functor'
        || $className === 'Foldable'
        || $className === 'Traversable';
}

/** Classes that must not use GND (associated types / structural Rep). */
function refusesNewtypeDeriving(string $className): bool
{
    return $className === 'Generic' || $className === 'Generic1';
}

function deriveViaNewtype(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    if (!$decl->isNewtype) {
        throw typeFail(
            $state,
            "cannot derive newtype `{$ref->name}`: not a newtype declaration",
            $ref,
        );
    }
    if (refusesNewtypeDeriving($ref->name)) {
        throw typeFail(
            $state,
            "cannot derive newtype `{$ref->name}`: use stock deriving for Generic",
            $ref,
        );
    }
    if (!isset($state->classes[$ref->name])) {
        throw typeFail(
            $state,
            "unknown class `{$ref->name}` in deriving clause",
            $ref,
        );
    }

    if (isFunctorialClass($ref->name)) {
        return deriveNewtypeFunctorial($state, $decl, $ref);
    }

    assertNominalGndEligible($state, $ref);

    return deriveNewtypeNominal($state, $decl, $ref);
}

/**
 * Nominal GND only supports classes whose parameter appears as a bare type
 * (`a`, not `m a`). Higher-kinded classes other than Functor/Foldable/
 * Traversable must be rejected.
 */
function assertNominalGndEligible(TypeCheckState $state, Ast\DerivingClassRef $ref): void
{
    $classInfo = $state->classes[$ref->name];
    $classParam = $classInfo['params'][0]['name'] ?? 'a';
    $paramIsHigherKinded = ($classInfo['params'][0] ?? null) !== null
        && classParamKind($state, $classInfo['params'][0]) instanceof Kinds\KArrow;
    foreach ($classInfo['methods'] as $methodName => $methodInfo) {
        if ($ref->name === 'Ord' && in_array($methodName, ['<', '<=', '>', '>=', 'min', 'max'], true)) {
            continue;
        }
        if (internalTypeUsesParamAsConstructor($methodInfo['type'], $classParam, $paramIsHigherKinded)) {
            throw typeFail(
                $state,
                "cannot derive newtype `{$ref->name}`: class is higher-kinded "
                    . '(only Functor, Foldable, and Traversable are supported for `* -> *`)',
                $ref,
            );
        }
    }
}

/**
 * True when `$param` appears as a type-constructor head (e.g. `m a`).
 *
 * An applied variable (`TVarApp`) is such an occurrence by construction. A bare
 * `TVar` only counts when the parameter is itself higher-kinded: at kind `Type`
 * a variable is the parameter used *as* the type, which is exactly what
 * nominal GND supports.
 */
function internalTypeUsesParamAsConstructor(Type $type, string $param, bool $paramIsHigherKinded): bool
{
    if ($type instanceof TVarApp) {
        if ($type->name === $param) {
            return true;
        }
    } elseif ($type instanceof TVar && $paramIsHigherKinded && $type->name === $param) {
        return true;
    }

    if ($type instanceof TVarApp || $type instanceof TCon) {
        foreach ($type->args as $arg) {
            if (internalTypeUsesParamAsConstructor($arg, $param, $paramIsHigherKinded)) {
                return true;
            }
        }

        return false;
    }

    if ($type instanceof TArrow) {
        return internalTypeUsesParamAsConstructor($type->from, $param, $paramIsHigherKinded)
            || internalTypeUsesParamAsConstructor($type->to, $param, $paramIsHigherKinded);
    }

    return false;
}

/**
 * GND for Eq/Ord/Show/Num/… : instance C NT where methods unwrap/wrap.
 */
function deriveNewtypeNominal(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $rep = newtypeRepresentation($decl);
    $ctor = $rep['ctor'];
    $underlying = $rep['field'];
    $className = $ref->name;
    $classInfo = $state->classes[$className];
    $classParam = $classInfo['params'][0]['name'] ?? 'a';

    $constraints = [
        new Ast\TypeApp(new Ast\TypeCon($className), [$underlying]),
    ];

    $methods = [];
    foreach ($classInfo['methods'] as $methodName => $methodInfo) {
        if ($className === 'Ord' && in_array($methodName, ['<', '<=', '>', '>=', 'min', 'max'], true)) {
            continue;
        }
        if (typeHasNestedClassParam($methodInfo['type'], $classParam)) {
            continue;
        }
        $methods[] = synthesizeNewtypeNominalMethod(
            $methodName,
            $methodInfo['type'],
            $classParam,
            $ctor,
            $ref,
        );
    }

    return new DerivedInstance(
        $className,
        dataDeclHeadAst($decl),
        $constraints,
        $methods,
        $ref,
        strategy: 'Newtype',
    );
}

/**
 * @param Type $methodType
 */
function synthesizeNewtypeNominalMethod(
    string $methodName,
    Type $methodType,
    string $classParam,
    string $ctor,
    Ast\DerivingClassRef $ref,
): Ast\FunctionDecl {
    [$argTypes, $resultType] = peelFunTypeInternal($methodType);
    $pats = [];
    $args = [];
    foreach ($argTypes as $i => $argType) {
        $binder = '__n' . $i;
        if (internalTypeIsVar($argType, $classParam)) {
            $pats[] = new Ast\PatCon($ctor, [new Ast\PatVar($binder)]);
            $args[] = new Ast\Variable($binder);
        } else {
            $pats[] = new Ast\PatVar($binder);
            $args[] = new Ast\Variable($binder);
        }
    }

    $call = new Ast\Variable($methodName);
    foreach ($args as $arg) {
        $call = new Ast\Apply($call, $arg);
    }

    if (internalTypeIsVar($resultType, $classParam)) {
        $call = new Ast\Apply(new Ast\ConstructorRef($ctor), $call);
    }

    return methodDecl($methodName, $pats, $call, $ref);
}

function internalTypeIsVar(Type $type, string $name): bool
{
    return $type instanceof TVar && $type->name === $name;
}

/**
 * @return array{0: list<Type>, 1: Type}
 */
function peelFunTypeInternal(Type $type): array
{
    while (
        $type instanceof TArrow
        && $type->from instanceof TCon
        && str_starts_with($type->from->name, '__Dict_')
    ) {
        $type = $type->to;
    }

    $args = [];
    while ($type instanceof TArrow) {
        $args[] = $type->from;
        $type = $type->to;
        while (
            $type instanceof TArrow
            && $type->from instanceof TCon
            && str_starts_with($type->from->name, '__Dict_')
        ) {
            $type = $type->to;
        }
    }

    return [$args, $type];
}

/**
 * GND for Functor/Foldable/Traversable: `newtype T a = T (f a)`.
 */
function deriveNewtypeFunctorial(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $param = lastDataParamName($decl);
    if ($param === null) {
        throw typeFail(
            $state,
            "cannot derive newtype `{$ref->name}`: newtype has no type parameter",
            $ref,
        );
    }

    $rep = newtypeRepresentation($decl);
    $field = $rep['field'];
    if (!$field instanceof Ast\TypeApp) {
        throw typeFail(
            $state,
            "cannot derive newtype `{$ref->name}`: representation must be an application `f {$param}`",
            $ref,
        );
    }
    $lastArg = $field->args[count($field->args) - 1] ?? null;
    if (!$lastArg instanceof Ast\TypeVar || $lastArg->name !== $param) {
        throw typeFail(
            $state,
            "cannot derive newtype `{$ref->name}`: representation must end in type parameter `{$param}`",
            $ref,
        );
    }

    $underArgs = array_slice($field->args, 0, -1);
    $underHead = $field->con;
    $underlying = $underArgs === []
        ? $underHead
        : new Ast\TypeApp($underHead, $underArgs);

    $constraints = [
        new Ast\TypeApp(new Ast\TypeCon($ref->name), [$underlying]),
    ];

    $ctor = $rep['ctor'];
    $methods = match ($ref->name) {
        'Functor' => [
            methodDecl(
                'map',
                [new Ast\PatVar('__f'), new Ast\PatCon($ctor, [new Ast\PatVar('__x')])],
                new Ast\Apply(
                    new Ast\ConstructorRef($ctor),
                    mapCall(new Ast\Variable('__f'), new Ast\Variable('__x')),
                ),
                $ref,
            ),
        ],
        'Foldable' => [
            methodDecl(
                'foldr',
                [
                    new Ast\PatVar('__f'),
                    new Ast\PatVar('__z'),
                    new Ast\PatCon($ctor, [new Ast\PatVar('__x')]),
                ],
                foldrCall(new Ast\Variable('__f'), new Ast\Variable('__z'), new Ast\Variable('__x')),
                $ref,
            ),
        ],
        'Traversable' => [
            methodDecl(
                'traverse',
                [new Ast\PatVar('__f'), new Ast\PatCon($ctor, [new Ast\PatVar('__x')])],
                mapCall(
                    new Ast\ConstructorRef($ctor),
                    traverseCall(new Ast\Variable('__f'), new Ast\Variable('__x')),
                ),
                $ref,
            ),
        ],
        default => throw typeFail($state, "internal: bad functorial class", $ref),
    };

    return new DerivedInstance(
        $ref->name,
        functorialHeadAst($decl),
        $constraints,
        $methods,
        $ref,
        strategy: 'Newtype',
    );
}
