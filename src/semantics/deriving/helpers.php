<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\typeChildren;
use function Moggi\Semantics\Types\typeFail;


/**
 * Shared AST helpers for stock / newtype deriving backends.
 */

/**
 * The fields a constructor contributes to a derived body.
 *
 * An ordinary constructor states them in its field list. A GADT constructor
 * states them in its signature instead, and the dictionary fields a GADT
 * context stores are not among them: the pattern binder prepends one for each
 * constraint by itself, so a derived pattern names only what the source wrote.
 *
 * @return list<Ast\CtorField>
 */
function constructorDeriveFields(Ast\ConstructorDecl $ctor): array
{
    if ($ctor->fields !== []) {
        return $ctor->fields;
    }

    if (! $ctor->isGadt()) {
        return [];
    }

    $labels = [];
    foreach ($ctor->resolvedFieldNames ?? [] as $name) {
        if (! \str_starts_with($name, '$')) {
            $labels[] = $name;
        }
    }

    $fields = [];
    foreach ($ctor->fieldTypeAsts() as $i => $type) {
        $fields[] = new Ast\CtorField($labels[$i] ?? '', $type);
    }

    return $fields;
}

/**
 * Whether a GADT constructor returns the datatype at its own parameters, the
 * shape a functorial derivation needs (`C :: f a -> T a`).
 *
 * `C :: Int -> T Int` does not: mapping a constructor that fixes its index
 * cannot produce a `T a` for an arbitrary `a`, which is why Haskell refuses
 * `deriving Functor` there.
 */
function constructorReturnsParams(Ast\ConstructorDecl $ctor, Ast\DataDecl $decl): bool
{
    $result = $ctor->resultTypeAst();
    if ($result === null) {
        return true;
    }

    $head = $result;
    $args = [];
    if ($result instanceof Ast\TypeApp) {
        $head = $result->con;
        $args = $result->args;
    }

    if (! $head instanceof Ast\TypeCon || $head->name !== $decl->name) {
        return false;
    }

    if (\count($args) !== \count($decl->params)) {
        return false;
    }

    foreach ($decl->params as $i => $param) {
        $arg = $args[$i];
        if (! $arg instanceof Ast\TypeVar || $arg->name !== $param->name) {
            return false;
        }
    }

    return true;
}

/** Last data parameter name, or null if the decl is nullary. */
function lastDataParamName(Ast\DataDecl $decl): ?string
{
    if ($decl->params === []) {
        return null;
    }

    return $decl->params[\count($decl->params) - 1]->name;
}

/**
 * Instance head for (* -> *) classes: drop the last type parameter.
 * `data T a b` ⇒ `T a`.
 */
function functorialHeadAst(Ast\DataDecl $decl): Ast\TypeNode
{
    if ($decl->params === []) {
        throw new \InvalidArgumentException('functorial head requires a type parameter');
    }

    $args = [];
    $n = \count($decl->params);
    for ($i = 0; $i < $n - 1; ++$i) {
        $args[] = new Ast\TypeVar($decl->params[$i]->name);
    }

    $head = new Ast\TypeCon($decl->name, $decl->line, $decl->col, $decl->endCol);
    if ($args === []) {
        return $head;
    }

    return new Ast\TypeApp($head, $args);
}

/**
 * The context `C t` a derived instance of `$className` needs for each type `t`
 * its fields constrain: a bare parameter stays a variable, a parameter the
 * field applies keeps its arguments.
 *
 * @param array<string, true>|null $onlyParams restrict to these param names
 * @return list<Ast\TypeNode>
 */
function paramClassConstraints(
    Ast\DataDecl $decl,
    string $className,
    ?array $onlyParams = null,
): array {
    $paramNames = [];
    foreach ($decl->params as $param) {
        $paramNames[$param->name] = true;
    }

    $constraints = [];
    $seen = [];
    foreach ($decl->constructors as $ctor) {
        foreach (constructorDeriveFields($ctor) as $field) {
            foreach (constrainedTypesIn($field->type, $paramNames, $onlyParams) as $type) {
                $key = \serialize($type);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $constraints[] = new Ast\TypeApp(
                    new Ast\TypeCon($className),
                    [$type],
                );
            }
        }
    }

    return $constraints;
}

/**
 * The types one field type constrains, in source order.
 *
 * A field that *is* a parameter constrains that variable (`T a = T (Maybe a)`
 * gives `Eq a`), and a parameter the field applies constrains the whole
 * application (`T f a = T (f a)` gives `Eq (f a)`) -- the parameter alone is a
 * constructor of kind `Type -> Type` there and has no instance of its own.
 *
 * @param array<string, true> $paramNames
 * @param array<string, true>|null $onlyParams
 * @return list<Ast\TypeNode>
 */
function constrainedTypesIn(Ast\TypeNode $type, array $paramNames, ?array $onlyParams): array
{
    $wanted = static fn (string $name): bool => isset($paramNames[$name])
        && ($onlyParams === null || isset($onlyParams[$name]));

    if ($type instanceof Ast\TypeVar) {
        return $wanted($type->name) ? [$type] : [];
    }

    if ($type instanceof Ast\TypeApp) {
        if ($type->con instanceof Ast\TypeVar && isset($paramNames[$type->con->name])) {
            return $wanted($type->con->name) ? [$type] : [];
        }

        $found = constrainedTypesIn($type->con, $paramNames, $onlyParams);
        foreach ($type->args as $arg) {
            $found = \array_merge($found, constrainedTypesIn($arg, $paramNames, $onlyParams));
        }

        return $found;
    }

    if ($type instanceof Ast\TypeArrow) {
        return \array_merge(
            constrainedTypesIn($type->from, $paramNames, $onlyParams),
            constrainedTypesIn($type->to, $paramNames, $onlyParams),
        );
    }

    if ($type instanceof Ast\TypeConstrained) {
        $found = constrainedTypesIn($type->body, $paramNames, $onlyParams);
        foreach ($type->constraints as $constraint) {
            $found = \array_merge($found, constrainedTypesIn($constraint, $paramNames, $onlyParams));
        }

        return $found;
    }

    return [];
}

/** @return list<Ast\PatVar> */
function freshPatVars(string $prefix, int $count): array
{
    $vars = [];
    for ($i = 0; $i < $count; ++$i) {
        $vars[] = new Ast\PatVar($prefix . $i);
    }

    return $vars;
}

/** @return list<string> */
function patVarNames(array $pats): array
{
    $names = [];
    foreach ($pats as $pat) {
        $names[] = $pat->name;
    }

    return $names;
}

/** @param list<Ast\AstNode> $args */
function applyCtor(string $name, array $args): Ast\AstNode
{
    $expr = new Ast\ConstructorRef($name);
    foreach ($args as $arg) {
        $expr = new Ast\Apply($expr, $arg);
    }

    return $expr;
}

/**
 * @return array<string, true>
 */
function typeNodeFreeVars(Ast\TypeNode $type): array
{
    return match ($type::class) {
        Ast\TypeVar::class => [$type->name => true],
        Ast\TypeApp::class => (static function () use ($type): array {
            $vars = typeNodeFreeVars($type->con);
            foreach ($type->args as $arg) {
                $vars = [...$vars, ...typeNodeFreeVars($arg)];
            }

            return $vars;
        })(),
        Ast\TypeCon::class,
        Ast\TypePromoted::class,
        Ast\TypeStringLit::class,
        Ast\TypeNatLit::class,
        Ast\TypeUnit::class => [],
        Ast\TypeArrow::class => [...typeNodeFreeVars($type->from), ...typeNodeFreeVars($type->to)],
        Ast\TypeConstrained::class => (static function () use ($type): array {
            $vars = typeNodeFreeVars($type->body);
            foreach ($type->constraints as $c) {
                $vars = [...$vars, ...typeNodeFreeVars($c)];
            }

            return $vars;
        })(),
        default => [],
    };
}

function applyVar(string $fn, Ast\AstNode ...$args): Ast\AstNode
{
    $expr = new Ast\Variable($fn);
    foreach ($args as $arg) {
        $expr = new Ast\Apply($expr, $arg);
    }

    return $expr;
}

function applyOp(string $op, Ast\AstNode $left, Ast\AstNode $right): Ast\AstNode
{
    return new Ast\Apply(
        new Ast\Apply(new Ast\OperatorRef($op), $left),
        $right,
    );
}

function compareCall(Ast\AstNode $left, Ast\AstNode $right): Ast\AstNode
{
    return new Ast\Apply(
        new Ast\Apply(new Ast\Variable('compare'), $left),
        $right,
    );
}

function pureCall(Ast\AstNode $value): Ast\AstNode
{
    return new Ast\Apply(new Ast\Variable('pure'), $value);
}

function apCall(Ast\AstNode $left, Ast\AstNode $right): Ast\AstNode
{
    return applyOp('<*>', $left, $right);
}

/**
 * Does type `t` mention type variable `$param`?
 */
function typeMentionsParam(Ast\TypeNode $type, string $param): bool
{
    return isset(typeNodeFreeVars($type)[$param]);
}

/**
 * Does the type mention the class parameter under a type constructor?
 *
 * `a` in an argument or result position is converted by wrapping or unwrapping
 * it; `[a]`, `Maybe a` or `f a` would need a structural conversion of the whole
 * container, which Moggi has no primitive for. A method with such a type is left
 * to its class default instead of being derived.
 */
function typeHasNestedClassParam(Type $type, string $param): bool
{
    if ($type instanceof TCon || $type instanceof TVarApp || $type instanceof TPromoted) {
        return internalTypeMentionsParam($type, $param);
    }
    
    if ($type instanceof TArrow) {
        return typeHasNestedClassParam($type->from, $param)
            || typeHasNestedClassParam($type->to, $param);
    }

    return false;
}

/** True when `$param` occurs anywhere in `$type`. */
function internalTypeMentionsParam(Type $type, string $param): bool
{
    if ($type instanceof TVar) {
        return $type->name === $param;
    }

    foreach (typeChildren($type) as $child) {
        if (internalTypeMentionsParam($child, $param)) {
            return true;
        }
    }

    return false;
}

/**
 * Classify how the last type parameter occurs in a field type for Functor-like
 * derives. Returns a tag used by the structural synthesizers.
 *
 * @return array{tag: string, ...}
 */
function classifyFunctorField(Ast\TypeNode $type, string $param): array
{
    if ($type instanceof Ast\TypeVar && $type->name === $param) {
        return ['tag' => 'param'];
    }

    if (! typeMentionsParam($type, $param)) {
        return ['tag' => 'skip'];
    }

    if ($type instanceof Ast\TypeArrow) {
        return ['tag' => 'bad', 'reason' => 'function'];
    }

    if ($type instanceof Ast\TypeApp) {
        $args = $type->args;
        if ($args === []) {
            return ['tag' => 'bad', 'reason' => 'unsupported'];
        }
        for ($i = 0; $i < \count($args) - 1; ++$i) {
            if (typeMentionsParam($args[$i], $param)) {
                return ['tag' => 'bad', 'reason' => 'type parameter in non-last argument'];
            }
        }
        $last = classifyFunctorField($args[\count($args) - 1], $param);
        if ($last['tag'] === 'bad') {
            return $last;
        }

        return ['tag' => 'app', 'type' => $type];
    }

    return ['tag' => 'bad', 'reason' => 'unsupported'];
}

/**
 * Newtype unwrap: single constructor, single field.
 *
 * @return array{ctor: string, field: Ast\TypeNode}
 */
function newtypeRepresentation(Ast\DataDecl $decl): array
{
    if (! $decl->isNewtype) {
        throw new \InvalidArgumentException('expected newtype');
    }
    $ctor = $decl->constructors[0];

    return [
        'ctor' => $ctor->name,
        'field' => constructorDeriveFields($ctor)[0]->type,
    ];
}

function methodDecl(
    string $name,
    array $patterns,
    Ast\AstNode $body,
    Ast\DerivingClassRef $ref,
): Ast\FunctionDecl {
    return new Ast\FunctionDecl(
        $name,
        null,
        $patterns,
        $body,
        instanceMethod: true,
        line: $ref->line,
        col: $ref->col,
        endCol: $ref->endCol,
    );
}

/** Require all constructors to be nullary (Enum / Bounded / simple Read). */
function assertNullaryConstructors(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
    string $className,
): void {
    if ($decl->constructors === []) {
        throw typeFail(
            $state,
            "cannot derive {$className}: empty data type has no constructors",
            $ref,
        );
    }

    foreach ($decl->constructors as $ctor) {
        if (constructorDeriveFields($ctor) !== []) {
            throw typeFail(
                $state,
                "cannot derive {$className}: constructor `{$ctor->name}` has fields (only nullary constructors are allowed)",
                $ref,
            );
        }
    }
}
