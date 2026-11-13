<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use function Moggi\Errors\appendDidYouMean;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\ForallBinder;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TForall;
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
    $constraintAsts = dropEqualityConstraints($constraintAsts);
    if ($constraintAsts === []) {
        return astType($state, $bodyTypeAst, $expanding);
    }

    $signatureConstraints = parseConstraints($state, $constraintAsts, $at);
    applyConstraintVarKinds($state, $signatureConstraints);

    return wrapDictionaryConstraints(astType($state, $bodyTypeAst, $expanding), $signatureConstraints);
}

/**
 * Add a signature's dictionary parameters, inside any `forall`.
 *
 * A dictionary arrow outside the quantifier makes `forall a. C a => t` look like
 * a function of the dictionary first, so parameter counting and skolemisation
 * see a shape the declaration never wrote.
 *
 * @param list<Ast\PendingConstraint> $constraints
 */
function wrapDictionaryConstraints(Type $type, array $constraints): Type
{
    if ($type instanceof TForall) {
        return new TForall($type->binders, wrapDictionaryConstraints($type->body, $constraints));
    }

    $result = $type;
    foreach (\array_reverse(dictionaryConstraints($constraints)) as $constraint) {
        $result = new TArrow(new TCon('__Dict_' . $constraint->class, $constraint->args), $result);
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

/**
 * The type a constraint list carries for `~`: a proof obligation, not a
 * dictionary.
 *
 * `(a ~ b) => r` states that `a` and `b` are the same type in `r`. Nothing
 * about it can be passed at run time, so it contributes no argument: the type
 * elaborates to `r`, and its sides are the same type wherever the code can use
 * them (`gcastWith Refl x` is well typed because `Refl :: a :~: a` names one
 * type twice). A use that would need the equality to hold for two distinct
 * types is rejected by ordinary unification at the point the two are used
 * together, which is the check that matters.
 *
 * @param list<Ast\TypeNode> $constraints
 * @return list<Ast\TypeNode>
 */
function dropEqualityConstraints(array $constraints): array
{
    $kept = [];
    foreach ($constraints as $constraint) {
        if ($constraint instanceof Ast\TypeApp
            && $constraint->con instanceof Ast\TypeCon
            && ($constraint->con->name === '~' || $constraint->con->name === '~~')
        ) {
            continue;
        }
        $kept[] = $constraint;
    }

    return $kept;
}

function astType(TypeCheckState $state, Ast\TypeNode $type, array $expanding = []): Type
{
    return match ($type::class) {
        Ast\TypeVar::class => new TVar(skolemScopedVar($state, $type->name)),
        Ast\TypeKindAnnot::class => astTypeKindAnnot($state, $type),
        Ast\TypeUnit::class => new TUnit(),
        Ast\TypeCon::class => resolveTypeCon($state, $type->name, $expanding, $type),
        Ast\TypePromoted::class => resolvePromotedType($state, $type->name, $type),
        Ast\TypeStringLit::class => new TStringLit($type->value),
        Ast\TypeNatLit::class => astTypeNatLit($state, $type),
        Ast\TypeApp::class => applyTypeApp($state, $type->con, $type->args, $expanding),
        Ast\TypeArrow::class => astTypeArrow($state, $type, $expanding),
        Ast\TypeConstrained::class => astTypeSignature($state, $type, $expanding),
        Ast\TypeForall::class => astTypeForall($state, $type, $expanding),
        Ast\TypeQualified::class => resolveQualifiedType($state, $type->module, $type->name, $expanding, $type),
        default => throw typeFail($state, 'unknown type ' . $type::class),
    };
}

function astTypeArrow(TypeCheckState $state, Ast\TypeArrow $type, array $expanding): Type
{
    $from = $type->from instanceof Ast\TypeConstrained
        ? dictArgType($state, $type->from, $expanding)
        : astType($state, $type->from, $expanding);
    $to = astType($state, $type->to, $expanding);
    Kinds\assertKind($state, $from, new Kinds\KType(), $state->varKinds, $type->from);
    Kinds\assertKind($state, $to, new Kinds\KType(), $state->varKinds, $type->to);

    return new TArrow($from, $to);
}

/**
 * The type of a parameter that states a context, `(C => r)`.
 *
 * Unlike a signature's own context, which becomes the arrows a function takes
 * its dictionaries in, a context written on a parameter describes the argument:
 * the caller supplies the dictionary implicitly and may use it while the
 * argument expression is checked, so nothing is passed at run time. The
 * constraint and the type it qualifies therefore travel as one marker
 * (`__DictArg_C`, with the constraint's arguments and `r` itself), which
 * {@see stripDictArgConstraints} turns back into `r` wherever the value is used.
 *
 * @param array<string, true> $expanding
 */
function dictArgType(TypeCheckState $state, Ast\TypeConstrained $type, array $expanding = []): Type
{
    $entries = [];
    foreach ($type->constraints as $constraint) {
        if ($constraint instanceof Ast\TypeApp
            && $constraint->con instanceof Ast\TypeCon
            && ($constraint->con->name === '~' || $constraint->con->name === '~~')
        ) {
            $entries[] = [$constraint->con->name, [
                astType($state, $constraint->args[0], $expanding),
                astType($state, $constraint->args[1], $expanding),
            ]];

            continue;
        }

        $parsed = parseConstraints($state, [$constraint]);
        $entries[] = [$parsed[0]->class, $parsed[0]->args];
    }

    $result = astType($state, $type->body, $expanding);
    foreach (\array_reverse($entries) as [$class, $args]) {
        $result = new TCon('__DictArg_' . $class, [...$args, $result]);
    }

    return $result;
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

    if (isTypeFamilyDeclared($state, $name)) {
        return new TCon($name);
    }

    if (isset($state->qualifiedData[$module][$name])) {
        return new TCon($name, [], qualifiedTypeDeclaringModule($state, $module, $name) ?? $namespace);
    }

    if (isset($state->qualifiedTypeSynonyms[$module][$name])) {
        if (isset($expanding[$name])) {
            throw typeFail($state, "cyclic type synonym `{$name}`", $at);
        }

        $syn = $state->qualifiedTypeSynonyms[$module][$name];
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

    rejectIfKindNameUsedAsType($state, $name, $at);

    throw typeFail($state, "unknown type `{$module}.{$name}`", $at);
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

    if (isTypeFamilyDeclared($state, $name)) {
        return new TCon($name);
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
        $module = $info['declaringModule'] ?? null;
        if (! \is_string($module) || $module === '') {
            $module = ($info['result'] ?? null)?->module ?? null;
        }

        return new TCon($name, [], \is_string($module) && $module !== '' ? $module : null);
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

/**
 * The module that actually declares a qualified name.
 *
 * An import's qualified table records the declaration, not the module the
 * import happened to name, so `NE.NonEmpty` names `Moggi.Internal.Base`'s type
 * and `Semigroup.First` names the kernel wrapper even though both are reached
 * through public re-export modules. The unqualified `$data` map can lose that
 * module when one spelling has two declarations, so it is only a fallback.
 */
function qualifiedTypeDeclaringModule(TypeCheckState $state, string $module, string $name): ?string
{
    $info = $state->qualifiedData[$module][$name] ?? null;
    if (\is_array($info)) {
        $declaring = $info['declaringModule'] ?? null;
        if (! \is_string($declaring) || $declaring === '') {
            $declaring = ($info['result'] ?? null)?->module ?? null;
        }
        if (\is_string($declaring) && $declaring !== '') {
            return $declaring;
        }
    }

    $info = $state->data[$name] ?? null;
    if (\is_array($info)) {
        $declaring = $info['declaringModule'] ?? null;
        if (! \is_string($declaring) || $declaring === '') {
            $declaring = ($info['result'] ?? null)?->module ?? null;
        }
        if (\is_string($declaring) && $declaring !== '') {
            return $declaring;
        }
    }

    return null;
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
/**
 * A type argument, where a kind name stands for itself.
 *
 * @c Type@, @Symbol@ and @Nat@ are kinds, but a kind is a type too: a
 * kind-polymorphic constructor takes one as an argument (`TypeRep Type`). The
 * rejection of a kind name where a *type* is expected stays, so a signature
 * `f :: Type` is still an error; this is only the argument position.
 */
function astTypeArgument(TypeCheckState $state, Ast\TypeNode $arg, array $expanding): Type
{
    if ($arg instanceof Ast\TypeCon
        && ($arg->name === 'Type' || $arg->name === 'Symbol' || $arg->name === 'Nat')
    ) {
        return new TCon($arg->name);
    }

    return astType($state, $arg, $expanding);
}

function applyTypeApp(TypeCheckState $state, Ast\TypeNode $con, array $argAsts, array $expanding): Type
{
    if ($argAsts === []) {
        throw typeFail($state, 'type application requires arguments', $con);
    }

    $headLabel = match ($con::class) {
        Ast\TypeCon::class, Ast\TypeVar::class, Ast\TypeQualified::class => $con->name,
        default => 'a type constructor',
    };
    $qualifiedHead = null;
    foreach ($argAsts as $argAst) {
        if ($argAst instanceof Ast\TypeForall) {
            throw typeFail(
                $state,
                "impredicative type: a `forall` cannot be an argument of `{$headLabel}`; "
                    . 'a type variable stands for a monotype, so give the `forall` its own signature',
                $argAst,
            );
        }
    }

    if ($con instanceof Ast\TypeVar || $con instanceof Ast\TypeKindAnnot) {
        $args = [];
        foreach ($argAsts as $argAst) {
            $args[] = astTypeArgument($state, $argAst, $expanding);
        }
        $head = skolemScopedVar($state, $con->name);
        if ($con instanceof Ast\TypeKindAnnot) {
            $state->varKinds[$head] = signatureKind($state, $con->kind, $con);
        }
        applyTypeAppKinds($state, $head, $args, $argAsts, $con, isVarHead: true);

        return new TVarApp($head, $args);
    }

    if ($con instanceof Ast\TypeCon && $con->name === Ast\instanceHeadMarker()) {
        $args = [];
        foreach ($argAsts as $argAst) {
            $args[] = astTypeArgument($state, $argAst, $expanding);
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
        $qualifiedHead = resolveQualifiedType($state, $con->module, $con->name, $expanding, $con);

        if (! $qualifiedHead instanceof TCon) {
            throw typeFail($state, "type `{$baseName}` does not take arguments", $con);
        }

        $args = [];
        foreach ($argAsts as $argAst) {
            $args[] = astTypeArgument($state, $argAst, $expanding);
        }

        applyTypeAppKinds(
            $state,
            $qualifiedHead->name,
            $args,
            $argAsts,
            $con,
            isVarHead: false,
            knownKind: qualifiedTypeKind($state, $qualifiedHead),
        );

        return new TCon($qualifiedHead->name, $args, $qualifiedHead->module);
    }

    if (isTypeFamilyDeclared($state, $baseName)) {
        $args = [];
        foreach ($argAsts as $argAst) {
            $args[] = astTypeArgument($state, $argAst, $expanding);
        }
        applyTypeAppKinds($state, $baseName, $args, $argAsts, $con, isVarHead: false);

        return new TCon($baseName, $args);
    }

    if (isset($state->typeSynonyms[$baseName])) {
        return expandSynonymApp($state, $baseName, $argAsts, $expanding, $con);
    }

    $args = [];
    foreach ($argAsts as $argAst) {
        $args[] = astTypeArgument($state, $argAst, $expanding);
    }

    $resolvedHead = $qualifiedHead ?? resolveTypeCon($state, $baseName, $expanding, $con);

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

    return new TCon($resolvedHead->name, $args, $resolvedHead->module);
}

/**
 * The kind a qualified head was declared with, read from its own module's table.
 *
 * A local synonym of the same short name shadows the imported declaration and
 * empties the unqualified kind tables, but `I.TypeRep` still names the imported
 * one: its kind is the one its declaring module registered.
 */
function qualifiedTypeKind(TypeCheckState $state, TCon $head): ?Kinds\Kind
{
    $module = $head->module;
    if (! \is_string($module) || $module === '') {
        return null;
    }

    return $state->kindEnvByModule[$module][Kinds\canonicalTypeConName($head->name)] ?? null;
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
        $args[] = astTypeArgument($state, $argAst, $expanding);
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

    return new TCon($headType->name, $allArgs, $headType->module);
}

/**
 * Incremental kind rule for each argument: `f :: k1 -> k2`, `a :: k1` ⊢ `f a :: k2`.
 * Kind variables are introduced when the head kind is not yet an arrow.
 *
 * A declared head's kind is instantiated here, per use, so a variable the
 * declaration generalised stands for whatever this application needs: `(:~:)`
 * is applied at `Type` and at `Type -> Type` in one signature without either
 * use fixing the declaration to it.
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
    ?Kinds\Kind $knownKind = null,
): void {
    if ($isVarHead) {
        if (! isset($state->varKinds[$headName])) {
            $state->varKinds[$headName] = Kinds\freshStateKindVar($state);
        }
        $headKind = $state->varKinds[$headName];
    } elseif ($knownKind !== null) {
        $headKind = Kinds\instantiateKindVars($state, $knownKind);
    } else {
        // Look the kind up by the arity being applied, as `kindOfTypeCon` does:
        // a module-local `TypeRep` (0 parameters) and an imported one (1) share
        // a name, and only the arity tells them apart.
        $canonical = Kinds\canonicalTypeConName($headName);
        $headKind = Kinds\instantiateKindVars(
            $state,
            $state->kindEnvByArity[$canonical][\count($args)]
                ?? $state->kindEnv[$headName]
                ?? new Kinds\KType(),
        );
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
        $headKind = Kinds\pruneKind($ctx, $result);
        if ($isVarHead) {
            $state->varKinds[$headName] = Kinds\pruneKind($ctx, $state->varKinds[$headName]);
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

/**
 * Elaborate `forall a. body` into a `TForall`.
 *
 * Binder kinds are scoped while the body elaborates and restored afterwards, so
 * an annotated binder constrains only its own body. The binder's *name* is kept:
 * the checker identifies variables by name, so an occurrence in the body already
 * elaborates to that name and the quantifier binds it by construction.
 */
/**
 * A type variable with an explicit kind annotation, `(a :: k)`, in a type.
 *
 * The annotation fixes the variable's kind for the rest of the signature, which
 * is what lets `TypeRep (a :: k) -> TypeRep k` say that the representation's
 * index and its kind are related. A lowercase kind name is a kind variable and
 * is stored under its own spelling, so a type position writing the same name
 * (`TypeRep k`) denotes that kind rather than a fresh one.
 */
function astTypeKindAnnot(TypeCheckState $state, Ast\TypeKindAnnot $type): Type
{
    $name = skolemScopedVar($state, $type->name);
    $state->varKinds[$name] = signatureKind($state, $type->kind, $type);

    return new TVar($name);
}

/**
 * A kind annotation written in a type: `Type` is the kind of ordinary types,
 * a lowercase name binds (or reuses) a kind variable, and an arrow composes.
 */
function signatureKind(TypeCheckState $state, Ast\KindNode $kind, ?Ast\AstNode $at = null): Kinds\Kind
{
    return match ($kind::class) {
        Ast\KindType::class => new Kinds\KType(),
        Ast\KindCon::class => Kinds\isKindVariableName($kind->name)
            ? ($state->varKinds[$kind->name] ??= Kinds\freshStateKindVar($state))
            : Kinds\namedKind($kind->name),
        Ast\KindArrow::class => new Kinds\KArrow(
            signatureKind($state, $kind->from, $at),
            signatureKind($state, $kind->to, $at),
        ),
        default => throw typeFail($state, 'unknown kind', $at ?? ($kind instanceof Ast\AstNode ? $kind : null)),
    };
}

function astTypeForall(TypeCheckState $state, Ast\TypeForall $type, array $expanding): Type
{
    $binders = [];
    $saved = [];
    foreach ($type->binders as $i => $name) {
        $saved[$name] = $state->varKinds[$name] ?? null;
        $kindAst = $type->kinds[$i] ?? null;
        $state->varKinds[$name] = $kindAst === null
            ? Kinds\freshStateKindVar($state)
            : Kinds\astKind($state, $kindAst, $type);
        $binders[$name] = true;
    }

    try {
        $body = astType($state, $type->body, $expanding);
        $resolvedBinders = [];
        foreach (\array_keys($binders) as $binder) {
            $ctx = Kinds\kindInferCtxFromState($state);
            $resolvedBinders[] = new ForallBinder(
                $binder,
                Kinds\defaultUnresolvedKindVars(Kinds\pruneKind($ctx, $state->varKinds[$binder])),
            );
        }
        $binders = $resolvedBinders;
    } finally {
        foreach ($saved as $name => $kind) {
            if ($kind === null) {
                unset($state->varKinds[$name]);
            } else {
                $state->varKinds[$name] = $kind;
            }
        }
    }

    return new TForall($binders, $body);
}
