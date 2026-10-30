<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use function Moggi\Errors\appendDidYouMean;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TNatLit;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TStringLit;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;
use function Moggi\Semantics\IntrinsicRegistry\primitiveTypeOwnerModule;
use function Moggi\Semantics\TypeExpr\canonicalNatDigits;

function astTypeSignature(TypeCheckState $state, Ast\TypeNode $typeAst, array $expanding = [], ?Ast\AstNode $at = null): Type
{
    [$constraintAsts, $bodyTypeAst] = splitTypeAst($typeAst);
    if ($constraintAsts === []) {
        return astType($state, $bodyTypeAst, $expanding);
    }

    $signatureConstraints = parseConstraints($state, $constraintAsts, $at);
    applyConstraintVarKinds($state, $signatureConstraints);
    $result = astType($state, $bodyTypeAst, $expanding);
    foreach (\array_reverse($signatureConstraints) as $constraint) {
        $result = new TArrow(new TCon('__Dict_' . $constraint->class), $result);
    }

    return $result;
}

/**
 * The variable a spelling means while a skolemized body is checked: the
 * skolem an enclosing signature bound it to, or the spelling itself outside
 * such a scope. Type variables are global by spelling, so a nested
 * annotation writing the same name has always meant the same variable;
 * resolving it to the enclosing skolem keeps that sharing (and the
 * name-based evidence lookup built on it) exactly as without skolems, only
 * rigid — while a nested signature of its own overwrites the entry and so
 * scopes its variables apart. See `TypeCheckState::$skolemScope`.
 */
function skolemScopedVar(TypeCheckState $state, string $name): string
{
    return $state->skolemScope[$name] ?? $name;
}

function astType(TypeCheckState $state, Ast\TypeNode $type, array $expanding = []): Type
{
    return match ($type::class) {
        Ast\TypeVar::class => new TVar(skolemScopedVar($state, $type->name)),
        Ast\TypeUnit::class => new TUnit(),
        Ast\TypeCon::class => resolveTypeCon($state, $type->name, $expanding, $type),
        Ast\TypePromoted::class => resolvePromotedType($state, $type->name, $type),
        Ast\TypeStringLit::class => new TStringLit($type->value),
        Ast\TypeNatLit::class => astTypeNatLit($state, $type),
        Ast\TypeApp::class => applyTypeApp($state, $type->con, $type->args, $expanding),
        Ast\TypeArrow::class => astTypeArrow($state, $type, $expanding),
        Ast\TypeConstrained::class => astTypeSignature($state, $type, $expanding),
        Ast\TypeQualified::class => resolveQualifiedType($state, $type->module, $type->name, $expanding, $type),
        default => throw typeFail($state, 'unknown type ' . $type::class),
    };
}

function astTypeArrow(TypeCheckState $state, Ast\TypeArrow $type, array $expanding): Type
{
    $from = astType($state, $type->from, $expanding);
    $to = astType($state, $type->to, $expanding);
    Kinds\assertKind($state, $from, new Kinds\KType(), $state->varKinds, $type->from);
    Kinds\assertKind($state, $to, new Kinds\KType(), $state->varKinds, $type->to);

    return new TArrow($from, $to);
}

function resolveQualifiedType(TypeCheckState $state, string $module, string $name, array $expanding, Ast\AstNode|Ast\TypeNode|null $at = null): Type
{
    $namespace = $state->qualifiedModules[$module] ?? null;
    if ($namespace === null) {
        throw typeFail(
            $state,
            appendDidYouMean(
                "unknown module prefix `{$module}` in type",
                $module,
                \array_keys($state->qualifiedModules),
            ),
            $at,
        );
    }

    $prim = resolveMagicHashTypeIfInScope($state, $name, $expanding, $at);
    if ($prim !== null) {
        return $prim;
    }

    if (isset($state->typeSynonyms[$name])) {
        if (isset($expanding[$name])) {
            throw typeFail($state, "cyclic type synonym `{$name}`", $at);
        }

        $syn = $state->typeSynonyms[$name];
        if ($syn['params'] !== []) {
            throw typeFail(
                $state,
                "type synonym `{$name}` is parametric and must be applied to "
                    . \count($syn['params']) . ' argument(s)',
                $at,
            );
        }

        return $syn['body'] ?? astType($state, $syn['rhs'], [...$expanding, $name => true]);
    }

    if (isset($state->data[$name])) {
        $info = $state->data[$name];

        return $info['result'];
    }

    rejectIfKindNameUsedAsType($state, $name, $at);

    return new TCon($name);
}

/**
 * MagicHash primitive types (Int#, IO#, …) resolve when imported from
 * Moggi.Internal.Prim / Moggi.Internal.IO, or while expanding a public synonym
 * whose RHS mentions them (`type IO = IO#` → use of `IO` without Magichash import).
 *
 * @param array<string, true> $expanding
 */
function resolveMagicHashTypeIfInScope(
    TypeCheckState $state,
    string $name,
    array $expanding = [],
    Ast\AstNode|Ast\TypeNode|null $at = null,
): ?Type {
    $resolved = primitiveTypeRepresentation($name);
    if ($resolved === null) {
        return null;
    }

    if (! isset($state->data[$name]) && $expanding === []) {
        $owner = primitiveTypeOwnerModule($name) ?? 'Moggi.Internal.Prim';

        throw typeFail(
            $state,
            "unknown type constructor `{$name}` (import `{$owner}` to use compiler primitives)",
            $at,
        );
    }

    return $resolved;
}

/** DataKinds: elaborate `'Red` into the internal promoted-type representation. */
function resolvePromotedType(TypeCheckState $state, string $name, Ast\AstNode|Ast\TypeNode|null $at = null): Type
{
    if (! isset($state->promoted[$name])) {
        if (constructorNameExists($state, $name)) {
            throw typeFail(
                $state,
                "data constructor `{$name}` is not promotable (fields must have promotable kinds)",
                $at,
            );
        }

        throw typeFail($state, "unknown promoted constructor `'{$name}`", $at);
    }

    return new TPromoted($name);
}

/** Reject built-in kind names (`Type`, `Symbol`, `Nat`) used where a type is expected. */
function rejectIfKindNameUsedAsType(TypeCheckState $state, string $name, Ast\AstNode|Ast\TypeNode|null $at = null): void
{
    if ($name === 'Type' || $name === 'Symbol' || $name === 'Nat') {
        throw typeFail($state, "`{$name}` is a kind, not a type", $at);
    }
}

function astTypeNatLit(TypeCheckState $state, Ast\TypeNatLit $type): TNatLit
{
    if ($type->negative) {
        throw typeFail(
            $state,
            'numeric type literal must be a natural number (Nat), got -' . $type->digits,
            $type,
        );
    }

    return new TNatLit(canonicalNatDigits($type->digits));
}

function resolveTypeCon(TypeCheckState $state, string $name, array $expanding, Ast\AstNode|Ast\TypeNode|null $at = null): Type
{
    $prim = resolveMagicHashTypeIfInScope($state, $name, $expanding, $at);
    if ($prim !== null) {
        return $prim;
    }

    rejectIfKindNameUsedAsType($state, $name, $at);

    if (isset($state->typeSynonyms[$name])) {
        if (isset($expanding[$name])) {
            throw typeFail($state, "cyclic type synonym `{$name}`", $at);
        }

        $syn = $state->typeSynonyms[$name];
        if ($syn['params'] !== []) {
            throw typeFail(
                $state,
                "type synonym `{$name}` is parametric and must be applied to "
                    . \count($syn['params']) . ' argument(s)',
                $at,
            );
        }

        return $syn['body'] ?? astType($state, $syn['rhs'], [...$expanding, $name => true]);
    }

    if (isUndeclaredNominalTypeCon($state, $name)) {
        throw typeFail(
            $state,
            appendDidYouMean(
                "unknown type constructor `{$name}`",
                $name,
                knownTypeConstructorNames($state),
            ),
            $at,
        );
    }

    return \ctype_lower($name[0]) ? new TVar(skolemScopedVar($state, $name)) : new TCon($name);
}

function isUndeclaredNominalTypeCon(TypeCheckState $state, string $name): bool
{
    if (isset($state->data[$name]) || isset($state->kindEnv[$name]) || isset($state->declaredTypeNames[$name])) {
        return false;
    }

    return $name !== '' && \ctype_upper($name[0]);
}

/**
 * @param list<Ast\TypeNode> $argAsts
 */
function applyTypeApp(TypeCheckState $state, Ast\TypeNode $con, array $argAsts, array $expanding): Type
{
    if ($argAsts === []) {
        throw typeFail($state, 'type application requires arguments', $con);
    }

    if ($con instanceof Ast\TypeVar) {
        $args = [];
        foreach ($argAsts as $argAst) {
            $args[] = astType($state, $argAst, $expanding);
        }
        $head = skolemScopedVar($state, $con->name);
        applyTypeAppKinds($state, $head, $args, $argAsts, $con, isVarHead: true);

        return new TVarApp($head, $args);
    }

    if ($con instanceof Ast\TypeCon && $con->name === Ast\instanceHeadMarker()) {
        $args = [];
        foreach ($argAsts as $argAst) {
            $args[] = astType($state, $argAst, $expanding);
        }

        return new TCon(Ast\instanceHeadMarker(), $args);
    }

    if ($con instanceof Ast\TypeApp) {
        $flat = flattenTypeAppAst($con);
        if ($flat !== null) {
            return applyTypeApp($state, $flat[0], [...$flat[1], ...$argAsts], $expanding);
        }
    }

    if ($con instanceof Ast\TypePromoted) {
        return applyPromotedTypeApp($state, $con, $argAsts, $expanding);
    }

    $baseName = match ($con::class) {
        Ast\TypeCon::class => $con->name,
        Ast\TypeQualified::class => $con->name,
        default => throw typeFail($state, 'invalid type application head', $con),
    };

    if ($con instanceof Ast\TypeQualified) {
        resolveQualifiedType($state, $con->module, $con->name, $expanding, $con);
    }

    if (isset($state->typeSynonyms[$baseName])) {
        return expandSynonymApp($state, $baseName, $argAsts, $expanding, $con);
    }

    $args = [];
    foreach ($argAsts as $argAst) {
        $args[] = astType($state, $argAst, $expanding);
    }

    $resolvedHead = resolveTypeCon($state, $baseName, $expanding, $con);

    if ($resolvedHead instanceof TVar) {
        applyTypeAppKinds($state, $resolvedHead->name, $args, $argAsts, $con, isVarHead: true);

        return new TVarApp($resolvedHead->name, $args);
    }

    if (! $resolvedHead instanceof TCon) {
        throw typeFail(
            $state,
            "type `{$baseName}` does not take arguments",
            $con,
        );
    }

    applyTypeAppKinds($state, $resolvedHead->name, $args, $argAsts, $con, isVarHead: false);

    return new TCon($resolvedHead->name, $args);
}

/**
 * DataKinds: `'InfixI 'LeftAssociative` — promoted constructor as type head.
 *
 * @param list<Ast\TypeNode> $argAsts
 */
function applyPromotedTypeApp(
    TypeCheckState $state,
    Ast\TypePromoted $con,
    array $argAsts,
    array $expanding,
): Type {
    resolvePromotedType($state, $con->name, $con);

    $args = [];
    foreach ($argAsts as $argAst) {
        $args[] = astType($state, $argAst, $expanding);
    }

    applyPromotedTypeAppKinds($state, $con->name, $args, $argAsts, $con);

    return new TPromoted($con->name, $args);
}

/**
 * @param list<Type> $args
 * @param list<Ast\TypeNode> $argAsts
 */
function applyPromotedTypeAppKinds(
    TypeCheckState $state,
    string $headName,
    array $args,
    array $argAsts,
    Ast\AstNode|Ast\TypeNode $headAt,
): void {
    $headKind = Kinds\kindOfPromoted($state, $headName);
    $label = "promoted constructor `'{$headName}`";

    foreach ($args as $i => $arg) {
        $ctx = Kinds\kindInferCtxFromState($state);
        $headKind = Kinds\pruneKind($ctx, $headKind);

        if (! $headKind instanceof Kinds\KArrow && ! $headKind instanceof Kinds\KVar) {
            throw typeFail($state, "{$label} applied to too many arguments", $headAt);
        }

        $argExpected = Kinds\freshKindVar($ctx);
        $result = Kinds\freshKindVar($ctx);

        try {
            Kinds\unifyKind($ctx, $headKind, new Kinds\KArrow($argExpected, $result));
        } catch (\InvalidArgumentException $e) {
            throw typeFail($state, "{$label} applied to too many arguments", $headAt);
        }
        Kinds\commitKindInferCtx($state, $ctx);

        checkTypeAppArgKind($state, $arg, Kinds\pruneKind($ctx, $argExpected), $argAsts[$i], $headAt);

        $ctx = Kinds\kindInferCtxFromState($state);
        $headKind = Kinds\pruneKind($ctx, $result);
    }
}

/**
 * @return array{0: Ast\TypeNode, 1: list<Ast\TypeNode>}|null
 */
function flattenTypeAppAst(Ast\TypeNode $type): ?array
{
    $args = [];
    while ($type instanceof Ast\TypeApp) {
        for ($i = \count($type->args) - 1; $i >= 0; $i--) {
            $args[] = $type->args[$i];
        }
        $type = $type->con;
    }

    if (
        $type instanceof Ast\TypeCon
        || $type instanceof Ast\TypeVar
        || $type instanceof Ast\TypeQualified
        || $type instanceof Ast\TypePromoted
    ) {
        return [$type, \array_reverse($args)];
    }

    return null;
}

/**
 * Expand a (possibly parametric) type synonym under application, then continue
 * elaborating any remaining arguments (`type T = Maybe` then `T Int`).
 *
 * @param list<Ast\TypeNode> $argAsts
 */
function expandSynonymApp(
    TypeCheckState $state,
    string $name,
    array $argAsts,
    array $expanding,
    Ast\AstNode|Ast\TypeNode $at,
): Type {
    if (isset($expanding[$name])) {
        throw typeFail($state, "cyclic type synonym `{$name}`", $at);
    }

    $syn = $state->typeSynonyms[$name];
    $params = $syn['params'];
    $arity = \count($params);
    if (\count($argAsts) < $arity) {
        throw typeFail(
            $state,
            "type synonym `{$name}` applied to too few arguments (expected {$arity})",
            $at,
        );
    }

    $body = $syn['body'] ?? null;
    if ($body !== null) {
        $bodyMapping = [];
        for ($i = 0; $i < $arity; ++$i) {
            $bodyMapping[$params[$i]] = astType($state, $argAsts[$i], $expanding);
        }
        $headType = substitute($body, $bodyMapping);
        $remaining = \array_slice($argAsts, $arity);
        if ($remaining === []) {
            return $headType;
        }

        return applyTypeHeadToArgAsts($state, $headType, $remaining, $expanding, $at);
    }

    $mapping = [];
    for ($i = 0; $i < $arity; ++$i) {
        $mapping[$params[$i]] = $argAsts[$i];
    }
    $expanded = substituteTypeAstParams($syn['rhs'], $mapping);
    $remaining = \array_slice($argAsts, $arity);
    $rhsExpanding = [...$expanding, $name => true];

    $headType = astType($state, $expanded, $rhsExpanding);
    if ($remaining === []) {
        return $headType;
    }

    return applyTypeHeadToArgAsts($state, $headType, $remaining, $expanding, $at);
}

/**
 * @param list<Ast\TypeNode> $argAsts
 */
function applyTypeHeadToArgAsts(
    TypeCheckState $state,
    Type $headType,
    array $argAsts,
    array $expanding,
    Ast\AstNode|Ast\TypeNode $at,
): Type {
    $headType = prune($state, $headType);
    if (! $headType instanceof TCon) {
        throw typeFail($state, 'type synonym expanded to a non-constructor type that cannot take arguments', $at);
    }

    $newArgs = [];
    foreach ($argAsts as $argAst) {
        $newArgs[] = astType($state, $argAst, $expanding);
    }
    $allArgs = [...$headType->args, ...$newArgs];
    $allAsts = [
        ...\array_fill(0, \count($headType->args), $at),
        ...$argAsts,
    ];

    applyTypeAppKinds($state, $headType->name, $allArgs, $allAsts, $at, isVarHead: false);

    return new TCon($headType->name, $allArgs);
}

/**
 * Incremental kind rule for each argument: `f :: k1 -> k2`, `a :: k1` ⊢ `f a :: k2`.
 * Kind variables are introduced when the head kind is not yet an arrow.
 *
 * @param list<Type> $args
 * @param list<Ast\TypeNode> $argAsts
 */
function applyTypeAppKinds(
    TypeCheckState $state,
    string $headName,
    array $args,
    array $argAsts,
    Ast\AstNode|Ast\TypeNode $headAt,
    bool $isVarHead,
): void {
    if ($isVarHead) {
        if (! isset($state->varKinds[$headName])) {
            $state->varKinds[$headName] = Kinds\freshStateKindVar($state);
        }
        $headKind = $state->varKinds[$headName];
    } else {
        $headKind = $state->kindEnv[$headName] ?? new Kinds\KType();
    }

    $label = $isVarHead ? "type variable `{$headName}`" : "type constructor `{$headName}`";

    foreach ($args as $i => $arg) {
        $ctx = Kinds\kindInferCtxFromState($state);
        $headKind = Kinds\pruneKind($ctx, $headKind);

        if ($isVarHead && ($headKind instanceof Kinds\KType || $headKind instanceof Kinds\KCon)) {
            throw typeFail($state, "type variable `{$headName}` is not a type constructor", $headAt);
        }

        if (! $isVarHead && $headKind instanceof Kinds\KArrow) {
            checkTypeAppArgKind($state, $arg, $headKind->from, $argAsts[$i], $headAt);
            $headKind = $headKind->to;

            continue;
        }

        if (! $headKind instanceof Kinds\KArrow && ! $headKind instanceof Kinds\KVar) {
            throw typeFail($state, "{$label} applied to too many arguments", $headAt);
        }

        $argExpected = Kinds\freshKindVar($ctx);
        $result = Kinds\freshKindVar($ctx);

        try {
            Kinds\unifyKind($ctx, $headKind, new Kinds\KArrow($argExpected, $result));
        } catch (\InvalidArgumentException $e) {
            throw typeFail($state, "{$label} applied to too many arguments", $headAt);
        }
        Kinds\commitKindInferCtx($state, $ctx);

        checkTypeAppArgKind($state, $arg, Kinds\pruneKind($ctx, $argExpected), $argAsts[$i], $headAt);

        $ctx = Kinds\kindInferCtxFromState($state);
        if ($isVarHead) {
            $headKind = Kinds\pruneKind($ctx, $state->varKinds[$headName]);
            $state->varKinds[$headName] = $headKind;
        } else {
            $headKind = Kinds\pruneKind($ctx, $result);
        }
    }
}

/**
 * Kind-check one argument of a type application. Free type variables take on
 * the expected argument kind (so `OnlyColor c` gives `c :: Color`).
 */
function checkTypeAppArgKind(
    TypeCheckState $state,
    Type $arg,
    Kinds\Kind $expected,
    Ast\AstNode|Ast\TypeNode $argAt,
    Ast\AstNode|Ast\TypeNode $headAt,
): void {
    $arg = prune($state, $arg);
    $ctx = Kinds\kindInferCtxFromState($state);
    $expected = Kinds\pruneKind($ctx, $expected);

    if ($arg instanceof TVar) {
        if (! isset($state->varKinds[$arg->name])) {
            $state->varKinds[$arg->name] = $expected;

            return;
        }

        try {
            Kinds\unifyKind($ctx, $state->varKinds[$arg->name], $expected);
        } catch (\InvalidArgumentException $e) {
            throw typeFail($state, $e->getMessage(), $argAt);
        }
        Kinds\commitKindInferCtx($state, $ctx);

        return;
    }

    $actual = Kinds\kindOfType($state, $arg, $state->varKinds);

    try {
        Kinds\unifyKind($ctx, $actual, $expected);
    } catch (\InvalidArgumentException $e) {
        $head = $headAt instanceof Ast\TypeCon || $headAt instanceof Ast\TypeVar
            ? $headAt->name
            : ($headAt instanceof Ast\TypeQualified ? $headAt->name : 'type');

        throw typeFail(
            $state,
            'kind mismatch applying `' . $head . '`: expected `'
                . Kinds\kindToString(Kinds\pruneKind($ctx, $expected)) . '`, got `'
                . Kinds\kindToString(Kinds\pruneKind($ctx, $actual)) . '`',
            $argAt,
        );
    }
    Kinds\commitKindInferCtx($state, $ctx);
}
