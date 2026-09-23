<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;

use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\astType;
use function Moggi\Semantics\Types\substitute;
use function Moggi\Semantics\Types\typeFail;
use function Moggi\Semantics\Types\typeToString;

/**
 * Deriving via: delegate a class instance to another representationally equal
 * type.
 *
 * The target may be any data/newtype declaration; the only requirement is that
 * the target and the via type share a representation (each is unwrapped through
 * its newtype chain). `deriving via` is gated on
 * `Coercible`, not on the target being a newtype.
 *
 * Rather than coercing dictionaries, Moggi generates explicit
 * constructor wrapping/unwrapping between the target and the via type.
 * Newtypes are erased in the backends, so the generated conversions are purely
 * type-level; they exist so the unqualified class-method call resolves at the
 * via type and therefore dispatches through the `C V` context.
 */
function deriveVia(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $className = $ref->name;
    $viaType = $ref->viaType;

    if ($viaType === null) {
        throw typeFail($state, 'via deriving requires a via type', $ref);
    }
    if (!isset($state->classes[$className])) {
        throw typeFail($state, "unknown class `{$className}`", $ref);
    }
    $head = dataDeclHeadAst($decl);
    $headType = astType($state, $head);
    $viaInternalType = astType($state, $viaType);

    $target = newtypeChain($state, $headType);
    $via = newtypeChain($state, $viaInternalType);
    if (typeToString($target['representation']) !== typeToString($via['representation'])) {
        throw typeFail(
            $state,
            "via type `" . typeToString($viaInternalType)
                . '` is not representationally compatible with target type',
            $ref,
        );
    }

    $classInfo = $state->classes[$className];
    $classParam = $classInfo['params'][0]['name'] ?? 'a';

    // The required context is `C V`; a concrete V resolves to the instance
    // directly, a polymorphic one becomes the instance dictionary parameter.
    $constraints = [
        new Ast\TypeApp(new Ast\TypeCon($className), [$viaType]),
    ];

    $methods = [];
    foreach ($classInfo['methods'] as $methodName => $methodInfo) {
        // A method taking the value under a constructor (`showList`) cannot be
        // converted by unwrapping one argument; its class default dispatches
        // through the methods that can.
        if (typeHasNestedClassParam($methodInfo['type'], $classParam)) {
            continue;
        }
        $methods[] = synthesizeViaMethod(
            $state,
            $methodName,
            $methodInfo['type'],
            $classParam,
            $target['ctors'],
            $via['ctors'],
            $ref,
        );
    }

    return new DerivedInstance(
        $className,
        $head,
        $constraints,
        $methods,
        $ref,
        strategy: 'Via',
    );
}

/**
 * Newtype constructors wrapping an outer type down to its representation.
 *
 * `newtype Outer = Outer Inner`, `newtype Inner = Inner Int` yields
 * `{ctors: [Outer, Inner], representation: Int}`. A plain `data` type has no
 * wrapping constructors and is its own representation.
 *
 * @return array{ctors: list<string>, representation: Type}
 */
function newtypeChain(TypeCheckState $state, Type $type): array
{
    $ctors = [];
    $current = $type;
    while (($layer = newtypeLayer($state, $current)) !== null) {
        $ctors[] = $layer['ctor'];
        $current = $layer['field'];
    }

    return ['ctors' => $ctors, 'representation' => $current];
}

/**
 * One newtype unwrap step, or `null` when `$type` is not a newtype.
 *
 * @return ?array{ctor: string, field: Type}
 */
function newtypeLayer(TypeCheckState $state, Type $type): ?array
{
    if (!$type instanceof TCon) {
        return null;
    }

    $info = $state->data[$type->name] ?? null;
    if ($info === null || empty($info['newtype'])) {
        return null;
    }

    $constructors = $info['constructors'] ?? [];
    if (count($constructors) !== 1) {
        return null;
    }

    $ctor = $constructors[array_key_first($constructors)];
    if (!is_array($ctor) || empty($ctor['fieldTypes'])) {
        return null;
    }

    $field = $ctor['fieldTypes'][0];
    $params = $info['params'] ?? [];
    if ($type->args !== [] && $params !== []) {
        $subst = [];
        foreach ($params as $i => $param) {
            if (isset($type->args[$i])) {
                $subst[$param] = $type->args[$i];
            }
        }
        $field = substitute($field, $subst);
    }

    return ['ctor' => (string) $ctor['name'], 'field' => $field];
}

/**
 * One instance method for `deriving via`: args are unwrapped to the shared
 * representation and re-wrapped as the via type; results are coerced back.
 *
 * @param list<string> $targetCtors
 * @param list<string> $viaCtors
 */
function synthesizeViaMethod(
    TypeCheckState $state,
    string $methodName,
    Type $methodType,
    string $classParam,
    array $targetCtors,
    array $viaCtors,
    Ast\DerivingClassRef $ref,
): Ast\FunctionDecl {
    [$argTypes, $resultType] = peelFunTypeInternal($methodType);
    $pats = [];
    $args = [];
    foreach ($argTypes as $i => $argType) {
        $binder = '__v' . $i;
        if (isClassParam($argType, $classParam)) {
            $pats[] = wrapPattern($targetCtors, new Ast\PatVar($binder));
            $args[] = wrapConstructors($viaCtors, new Ast\Variable($binder));
            continue;
        }

        assertNoNestedClassParam($state, $argType, $classParam, $methodName, $ref);
        $pats[] = new Ast\PatVar($binder);
        $args[] = new Ast\Variable($binder);
    }

    $call = new Ast\Variable($methodName);
    foreach ($args as $arg) {
        $call = new Ast\Apply($call, $arg);
    }

    if (isClassParam($resultType, $classParam)) {
        $call = coerceViaResult($viaCtors, $targetCtors, $call);
    } else {
        assertNoNestedClassParam($state, $resultType, $classParam, $methodName, $ref);
    }

    return methodDecl($methodName, $pats, $call, $ref);
}

/**
 * Coerce a via-typed result back to the target type: unwrap the via type to the
 * shared representation and wrap it as the target.
 *
 * @param list<string> $viaCtors
 * @param list<string> $targetCtors
 */
function coerceViaResult(array $viaCtors, array $targetCtors, Ast\AstNode $expr): Ast\AstNode
{
    if ($viaCtors === []) {
        return wrapConstructors($targetCtors, $expr);
    }

    $binder = '__r';

    return new Ast\CaseExpr($expr, [
        new Ast\Alt(
            wrapPattern($viaCtors, new Ast\PatVar($binder)),
            wrapConstructors($targetCtors, new Ast\Variable($binder)),
        ),
    ]);
}

/**
 * Build a nested constructor pattern: `Outer (Inner <inner>)`.
 *
 * @param list<string> $ctors
 */
function wrapPattern(array $ctors, Ast\AstNode $inner): Ast\AstNode
{
    foreach (array_reverse($ctors) as $ctor) {
        $inner = new Ast\PatCon($ctor, [$inner]);
    }

    return $inner;
}

/**
 * Build a nested constructor application: `Outer (Inner <expr>)`.
 *
 * @param list<string> $ctors
 */
function wrapConstructors(array $ctors, Ast\AstNode $expr): Ast\AstNode
{
    foreach (array_reverse($ctors) as $ctor) {
        $expr = new Ast\Apply(new Ast\ConstructorRef($ctor), $expr);
    }

    return $expr;
}

function isClassParam(Type $type, string $param): bool
{
    return $type instanceof TVar && $type->name === $param;
}

/**
 * Via conversions only handle the class parameter at the top level. A parameter
 * nested under a type constructor (`Parser a`, `[a]`, `f a`) would need a
 * structural coercion, which Moggi has no primitive for.
 */
function assertNoNestedClassParam(
    TypeCheckState $state,
    Type $type,
    string $classParam,
    string $methodName,
    Ast\DerivingClassRef $ref,
): void {
    if (!internalTypeMentionsParam($type, $classParam)) {
        return;
    }

    throw typeFail(
        $state,
        "via deriving: method `{$methodName}` uses the instance type under a type constructor, "
            . 'which via deriving cannot coerce',
        $ref,
    );
}

