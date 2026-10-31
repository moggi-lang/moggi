<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Semantics\Kinds;

use function Moggi\Semantics\Kinds\classParamKind;

use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\typeChildren;
use function Moggi\Semantics\Types\typeFail;


/**
 * Generalized newtype deriving: reuse the underlying type's instance by
 * unwrapping / rewrapping the newtype constructor.
 *
 * Only `newtype` declarations qualify. Ordinary single-constructor `data`
 * never gets this path.
 *
 * One delegation covers both shapes a class's parameter comes in. A nominal
 * class (`Eq a`) is derived by unwrapping each argument a method takes at the
 * newtype and rewrapping a result that is the newtype -- `(==) (Age a) (Age b) =
 * a == b`. A functorial class (`Functor f`) is the same operation with the
 * parameter's occurrences unwrapped instead of its bare uses, and a result the
 * parameter sits *inside* reached with `map` instead of the constructor -- which
 * is exactly what `map` and `traverse` need:
 *
 *     map f (T x) = T (map f x)
 *     foldr f z (T x) = foldr f z x
 *     traverse f (T x) = map T (traverse f x)
 *
 * Every method of the class is derived, not just its minimal set: the delegation
 * is the same operation for each of them, and a class default would reach the
 * same answer a step later. A method whose shape the delegation cannot express
 * (the parameter under a container, `[a]`) is left to its class default -- or, in
 * a functorial class, refused, because there the default would not be the
 * underlying instance's answer.
 */

function deriveViaNewtype(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    if (! $decl->isNewtype) {
        throw typeFail(
            $state,
            "cannot derive newtype `{$ref->name}`: not a newtype declaration",
            $ref,
        );
    }
    if (! isset($state->classes[$ref->name])) {
        throw typeFail(
            $state,
            "unknown class `{$ref->name}` in deriving clause",
            $ref,
        );
    }

    $facts = ClassDeriveFacts::fromState($state);
    if ($facts->refusesNewtypeDeriving($ref->name)) {
        throw typeFail(
            $state,
            "cannot derive newtype `{$ref->name}`: the class has associated types, so "
                . 'there is no representation to reuse; use stock deriving',
            $ref,
        );
    }

    $classInfo = $state->classes[$ref->name];
    $classParam = $classInfo['params'][0]['name'] ?? 'a';
    $rep = newtypeRepresentation($decl);
    $functorial = $facts->takesOneTypeArgument($ref->name);

    if ($functorial) {
        $head = functorialHeadAst($decl);
    } else {
        assertNominalGndEligible($state, $ref);
        $head = dataDeclHeadAst($decl);
    }

    $methods = [];
    foreach ($classInfo['methods'] as $methodName => $methodInfo) {
        $plan = planDelegateMethod($methodInfo['type'], $classParam);
        if ($plan === null) {
            if ($functorial) {
                throw typeFail(
                    $state,
                    "cannot derive newtype `{$ref->name}`: class is higher-kinded "
                        . '(only Functor, Foldable, and Traversable are supported for `* -> *`)',
                    $ref,
                );
            }

            continue;
        }
        $methods[] = synthesizeDelegateMethod($methodName, $plan, $rep['ctor'], $ref);
    }

    return new DerivedInstance(
        $ref->name,
        $head,
        [newtypeDelegationConstraint($state, $decl, $ref, $facts)],
        $methods,
        $ref,
        strategy: 'Newtype',
    );
}

/**
 * The context a newtype-derived instance delegates to: `C underlying`, the representation (or,
 * for a class at `* -> *`, that representation without its last argument).
 *
 * The checked path and the project-instance stub that lets another module see the same instance
 * both ask this one question here, so the two cannot describe one instance differently.
 */
function newtypeDelegationConstraint(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
    ClassDeriveFacts $facts,
): Ast\TypeNode {
    $underlying = $facts->takesOneTypeArgument($ref->name)
        ? functorialUnderlying($state, $decl, $ref)
        : newtypeRepresentation($decl)['field'];

    return new Ast\TypeApp(new Ast\TypeCon($ref->name), [$underlying]);
}

/**
 * The type the functorial delegation delegates to: the newtype's field without
 * its last argument, so `newtype T a = T (G a)` delegates `Functor T` to
 * `Functor G`.
 */
function functorialUnderlying(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): Ast\TypeNode {
    $param = lastDataParamName($decl);
    if ($param === null) {
        throw typeFail(
            $state,
            "cannot derive newtype `{$ref->name}`: newtype has no type parameter",
            $ref,
        );
    }

    $field = newtypeRepresentation($decl)['field'];
    if (! $field instanceof Ast\TypeApp) {
        throw typeFail(
            $state,
            "cannot derive newtype `{$ref->name}`: representation must be an application `f {$param}`",
            $ref,
        );
    }
    $lastArg = $field->args[\count($field->args) - 1] ?? null;
    if (! $lastArg instanceof Ast\TypeVar || $lastArg->name !== $param) {
        throw typeFail(
            $state,
            "cannot derive newtype `{$ref->name}`: representation must end in type parameter `{$param}`",
            $ref,
        );
    }

    $underArgs = \array_slice($field->args, 0, -1);

    return $underArgs === []
        ? $field->con
        : new Ast\TypeApp($field->con, $underArgs);
}

/**
 * Nominal GND only supports classes whose parameter appears as a bare type
 * (`a`, not `p a b`). A class parameter of more than one type argument is not one
 * the delegation can unwrap, so it is rejected rather than derived wrongly.
 */
function assertNominalGndEligible(TypeCheckState $state, Ast\DerivingClassRef $ref): void
{
    $classInfo = $state->classes[$ref->name];
    $classParam = $classInfo['params'][0]['name'] ?? 'a';
    $paramIsHigherKinded = ($classInfo['params'][0] ?? null) !== null
        && classParamKind($state, $classInfo['params'][0]) instanceof Kinds\KArrow;
    foreach ($classInfo['methods'] as $methodInfo) {
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
    if ($type instanceof TVarApp && $type->name === $param) {
        return true;
    }
    if ($type instanceof TVar && $paramIsHigherKinded && $type->name === $param) {
        return true;
    }

    foreach (typeChildren($type) as $child) {
        if (internalTypeUsesParamAsConstructor($child, $param, $paramIsHigherKinded)) {
            return true;
        }
    }

    return false;
}

/**
 * How a method's arguments and result reach the underlying instance's call, or
 * null when the shape is one the delegation cannot express.
 *
 * An argument is unwrapped when its type *is* the parameter (`a`) or the
 * parameter applied to arguments that do not mention it (`f a`, `t (f a)`); a
 * parameter anywhere else in an argument (`t (t a)`, `[a]`) cannot be unwrapped,
 * so the whole method is left alone.
 *
 * A result is wrapped when the parameter is its own head (`f b`), rewrapped
 * under the result's own mapping when the parameter sits inside a result the
 * delegation's own functor produced (`f (t b)`, `m (t b)`), and otherwise
 * passed through (`b`).
 *
 * @return ?array{args: list<'unwrap'|'plain'>, result: 'wrap'|'wrapUnder'|'plain'}
 */
function planDelegateMethod(Type $type, string $param): ?array
{
    [$argTypes, $resultType] = peelFunTypeInternal($type);

    $args = [];
    foreach ($argTypes as $argType) {
        $mode = delegateArgMode($argType, $param);
        if ($mode === null) {
            return null;
        }
        $args[] = $mode;
    }

    $result = delegateResultMode($resultType, $param);
    if ($result === null) {
        return null;
    }

    return ['args' => $args, 'result' => $result];
}

/** @return ?'unwrap'|'plain' */
function delegateArgMode(Type $type, string $param): ?string
{
    if ($type instanceof TVar && $type->name === $param) {
        return 'unwrap';
    }
    if ($type instanceof TVarApp
        && $type->name === $param
        && ! delegateMentionsParam($type->args, $param)
    ) {
        return 'unwrap';
    }

    return delegateMentionsParam($type, $param) ? null : 'plain';
}

/** @return ?'wrap'|'wrapUnder'|'plain' */
function delegateResultMode(Type $type, string $param): ?string
{
    if (! delegateMentionsParam($type, $param)) {
        return 'plain';
    }
    if ($type instanceof TVar && $type->name === $param) {
        return 'wrap';
    }
    if ($type instanceof TVarApp
        && $type->name === $param
        && ! delegateMentionsParam($type->args, $param)
    ) {
        return 'wrap';
    }

    if ($type instanceof TVarApp) {
        $wrapped = 0;
        foreach ($type->args as $arg) {
            if (! delegateMentionsParam($arg, $param)) {
                continue;
            }
            ++$wrapped;
            if (! ($arg instanceof TVarApp && $arg->name === $param)
                || delegateMentionsParam($arg->args, $param)
            ) {
                return null;
            }
        }
        if ($wrapped === 1) {
            return 'wrapUnder';
        }
    }

    return null;
}

/**
 * Whether `$param` occurs in a method type at all -- as a bare variable, as the
 * head of an application (`f b`), or anywhere inside one.
 *
 * @param Type|list<Type> $type
 */
function delegateMentionsParam(Type|array $type, string $param): bool
{
    if (\is_array($type)) {
        foreach ($type as $one) {
            if (delegateMentionsParam($one, $param)) {
                return true;
            }
        }

        return false;
    }

    if ($type instanceof TVar) {
        return $type->name === $param;
    }
    if ($type instanceof TVarApp && $type->name === $param) {
        return true;
    }

    foreach (typeChildren($type) as $child) {
        if (delegateMentionsParam($child, $param)) {
            return true;
        }
    }

    return false;
}

/**
 * `method (T a) (T b) = T (method a b)` -- unwrap what the method takes at the
 * newtype, delegate to the same method, and rewrap what comes back.
 *
 * @param array{args: list<'unwrap'|'plain'>, result: 'wrap'|'wrapUnder'|'plain'} $plan
 */
function synthesizeDelegateMethod(
    string $methodName,
    array $plan,
    string $ctor,
    Ast\DerivingClassRef $ref,
): Ast\FunctionDecl {
    $pats = [];
    $args = [];
    foreach ($plan['args'] as $i => $mode) {
        $binder = '__n' . $i;
        $pats[] = $mode === 'unwrap'
            ? new Ast\PatCon($ctor, [new Ast\PatVar($binder)])
            : new Ast\PatVar($binder);
        $args[] = new Ast\Variable($binder);
    }

    $call = new Ast\Variable($methodName);
    foreach ($args as $arg) {
        $call = new Ast\Apply($call, $arg);
    }

    $body = match ($plan['result']) {
        'wrap' => new Ast\Apply(new Ast\ConstructorRef($ctor), $call),
        'wrapUnder' => new Ast\Apply(
            new Ast\Apply(new Ast\Variable('map'), new Ast\ConstructorRef($ctor)),
            $call,
        ),
        default => $call,
    };

    return methodDecl($methodName, $pats, $body, $ref);
}

/**
 * @return array{0: list<Type>, 1: Type}
 */
function peelFunTypeInternal(Type $type): array
{
    while (
        $type instanceof TArrow
        && $type->from instanceof TCon
        && \str_starts_with($type->from->name, '__Dict_')
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
            && \str_starts_with($type->from->name, '__Dict_')
        ) {
            $type = $type->to;
        }
    }

    return [$args, $type];
}
