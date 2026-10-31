<?php declare(strict_types=1);

namespace Moggi\Semantics\Kinds;

use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TNatLit;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TStringLit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\prune;
use function Moggi\Semantics\Types\typeFail;
use function Moggi\Semantics\Types\typeVars;

/** Internal kind representation (distinct from surface `Ast\KindNode`). */
interface Kind
{
}

final class KType implements Kind
{
}

final class KVar implements Kind
{
    public function __construct(public readonly string $name)
    {
    }
}

final class KArrow implements Kind
{
    public function __construct(public readonly Kind $from, public readonly Kind $to)
    {
    }
}

/** A named kind promoted from a data type (DataKinds), e.g. `Color`. Compared nominally by name. */
final class KCon implements Kind
{
    public function __construct(public readonly string $name)
    {
    }
}

/** Mutable state for a single kind-inference walk. */
final class KindInferCtx
{
    /** @var array<string, Kind> */
    public array $subst = [];

    /** @var array<string, Kind> */
    public array $env = [];

    /** @var array<string, Kind> */
    public array $varKinds = [];

    /** @var array<string, array{data: string, argKinds?: list<Kind>}> */
    public array $promoted = [];

    /**
     * The declared parameter kinds of every class in scope, by class name.
     *
     * A class is the authority on its own parameters' kinds: `class Functor f`
     * with `map :: (a -> b) -> f a -> f b` says `f :: Type -> Type` by writing the
     * application, and {@see inferClassParamKinds} reads it off the declaration.
     * A constraint (`Functor f =>`) only *uses* that class, so it has to look the
     * kinds up rather than guess them from the class's name.
     *
     * @var array<string, list<Kind>>
     */
    public array $classParams = [];

    public function __construct(public int $fresh = 0)
    {
    }
}

/** Snapshot of kind-inference state restored when leaving an elaboration scope. */
final class KindScope
{
    /**
     * @param array<string, Kind> $subst
     * @param array<string, Kind|null> $varKinds
     */
    public function __construct(
        public array $subst,
        public int $fresh,
        public array $varKinds,
    ) {
    }
}

/** Push a kind-elaboration scope (subst + fresh + varKinds). */
function pushKindScope(TypeCheckState $state): KindScope
{
    return new KindScope($state->kindSubst, $state->kindFresh, $state->varKinds);
}

function restoreKindScope(TypeCheckState $state, KindScope $scope): void
{
    $state->kindSubst = $scope->subst;
    $state->kindFresh = $scope->fresh;
    $state->varKinds = $scope->varKinds;
}

/** KindInferCtx backed by the TC state's persistent kind subst / fresh. */
function kindInferCtxFromState(TypeCheckState $state): KindInferCtx
{
    $ctx = new KindInferCtx($state->kindFresh);
    $ctx->subst = $state->kindSubst;
    $ctx->env = $state->kindEnv;
    $ctx->varKinds = $state->varKinds;
    $ctx->promoted = $state->promoted;
    $ctx->classParams = stateClassParamKinds($state);

    return $ctx;
}

function commitKindInferCtx(TypeCheckState $state, KindInferCtx $ctx): void
{
    $state->kindSubst = $ctx->subst;
    $state->kindFresh = $ctx->fresh;
}

/** Allocate a fresh kind variable in the TC state's kind scope. */
function freshStateKindVar(TypeCheckState $state): KVar
{
    return new KVar('k' . ($state->kindFresh++));
}

/**
 * Fresh local kind-inference context for data/class kind walks.
 * Does not share `$state->kindSubst` — those walks are self-contained.
 */
function newKindInferCtx(TypeCheckState $state): KindInferCtx
{
    $ctx = new KindInferCtx();
    $ctx->env = $state->kindEnv;
    $ctx->promoted = $state->promoted;
    $ctx->classParams = stateClassParamKinds($state);

    return $ctx;
}

/**
 * Every registered class's declared parameter kinds, for a kind walk to consult.
 *
 * @return array<string, list<Kind>>
 */
function stateClassParamKinds(TypeCheckState $state): array
{
    if ($state->classParamKindsCache !== null) {
        return $state->classParamKindsCache;
    }

    $kinds = [];
    foreach ($state->classes as $className => $classInfo) {
        $params = [];
        foreach ($classInfo['params'] as $param) {
            $params[] = classParamKind($state, $param);
        }

        $kinds[$className] = $params;
    }

    return $state->classParamKindsCache = $kinds;
}

function kindToString(Kind $kind): string
{
    $kind = pruneKind(new KindInferCtx(), $kind);

    return match ($kind::class) {
        KType::class => 'Type',
        KVar::class => $kind->name,
        KCon::class => $kind->name,
        KArrow::class => parenthesizeArrowKind($kind->from) . ' -> ' . kindToString($kind->to),
        default => '?',
    };
}

function parenthesizeArrowKind(Kind $kind): string
{
    $kind = pruneKind(new KindInferCtx(), $kind);
    if (! $kind instanceof KArrow) {
        return kindToString($kind);
    }

    return '(' . kindToString($kind) . ')';
}

function peelKind(Kind $kind, int $applied): Kind
{
    for ($i = 0; $i < $applied; ++$i) {
        if (! $kind instanceof KArrow) {
            throw new \InvalidArgumentException('kind applied too many times');
        }
        $kind = $kind->to;
    }

    return $kind;
}

function sameKind(Kind $left, Kind $right): bool
{
    $left = pruneKind(new KindInferCtx(), $left);
    $right = pruneKind(new KindInferCtx(), $right);

    if ($left::class !== $right::class) {
        return false;
    }

    return match ($left::class) {
        KType::class => true,
        KVar::class => $left->name === $right->name,
        KCon::class => $left->name === $right->name,
        KArrow::class => sameKind($left->from, $right->from)
            && sameKind($left->to, $right->to),
        default => false,
    };
}

function freshKindVar(KindInferCtx $ctx): KVar
{
    return new KVar('k' . ($ctx->fresh++));
}

function pruneKind(KindInferCtx $ctx, Kind $kind): Kind
{
    while ($kind instanceof KVar && isset($ctx->subst[$kind->name])) {
        $kind = $ctx->subst[$kind->name];
    }

    if ($kind instanceof KArrow) {
        if ($ctx->subst === []) {
            return $kind;
        }
        $from = pruneKind($ctx, $kind->from);
        $to = pruneKind($ctx, $kind->to);
        if ($from === $kind->from && $to === $kind->to) {
            return $kind;
        }

        return new KArrow($from, $to);
    }

    return $kind;
}

function unifyKind(KindInferCtx $ctx, Kind $left, Kind $right): void
{
    $left = pruneKind($ctx, $left);
    $right = pruneKind($ctx, $right);

    if ($left instanceof KVar) {
        if ($right instanceof KVar && $left->name === $right->name) {
            return;
        }

        occursKind($ctx, $left->name, $right);
        $ctx->subst[$left->name] = $right;

        return;
    }

    if ($right instanceof KVar) {
        unifyKind($ctx, $right, $left);

        return;
    }

    if ($left instanceof KType && $right instanceof KType) {
        return;
    }

    if ($left instanceof KCon && $right instanceof KCon && $left->name === $right->name) {
        return;
    }

    if ($left instanceof KArrow && $right instanceof KArrow) {
        unifyKind($ctx, $left->from, $right->from);
        unifyKind($ctx, $left->to, $right->to);

        return;
    }

    throw new \InvalidArgumentException(
        'could not unify kind `' . kindToString($left) . '` with `' . kindToString($right) . '`',
    );
}

function occursKind(KindInferCtx $ctx, string $var, Kind $kind): void
{
    $kind = pruneKind($ctx, $kind);
    if ($kind instanceof KVar) {
        if ($kind->name === $var) {
            throw new \InvalidArgumentException("infinite kind involving {$var}");
        }

        return;
    }

    if ($kind instanceof KArrow) {
        occursKind($ctx, $var, $kind->from);
        occursKind($ctx, $var, $kind->to);
    }
}

/**
 * Pin the kind of a type variable a constraint's argument names, from the
 * class's own declaration: `Functor f =>` says `f :: Type -> Type` because
 * `Data.Functor` declares `map :: (a -> b) -> f a -> f b`. A constraint naming a
 * class nothing in scope declares leaves the variable alone -- there is no
 * declaration to read, and the rest of the signature decides.
 */
function bindClassConstraintKinds(KindInferCtx $ctx, Ast\AstNode $constraint): void
{
    if (! $constraint instanceof Ast\TypeApp || ! $constraint->con instanceof Ast\TypeCon) {
        return;
    }

    $className = $constraint->con->name;
    $args = $constraint->args;
    $paramKinds = $ctx->classParams[$className] ?? [];

    foreach ($args as $index => $arg) {
        if (! $arg instanceof Ast\TypeVar || ! isset($paramKinds[$index])) {
            continue;
        }

        $ctx->varKinds[$arg->name] = $paramKinds[$index];
    }
}

function inferKindAst(KindInferCtx $ctx, Ast\TypeNode $typeAst): Kind
{
    return match ($typeAst::class) {
        Ast\TypeVar::class => $ctx->varKinds[$typeAst->name] ?? new KType(),
        Ast\TypeUnit::class => new KType(),
        Ast\TypeCon::class => kindOfTypeConAst($ctx, $typeAst->name),
        Ast\TypePromoted::class => kindOfPromotedAst($ctx, $typeAst->name),
        Ast\TypeStringLit::class => new KCon('Symbol'),
        Ast\TypeNatLit::class => new KCon('Nat'),
        Ast\TypeApp::class => inferKindApp($ctx, $typeAst->con, $typeAst->args),
        Ast\TypeArrow::class => (static function () use ($ctx, $typeAst): Kind {
            $fromKind = inferKindAst($ctx, $typeAst->from);
            $toKind = inferKindAst($ctx, $typeAst->to);
            unifyKind($ctx, $fromKind, new KType());
            unifyKind($ctx, $toKind, new KType());

            return new KType();
        })(),
        Ast\TypeConstrained::class => (static function () use ($ctx, $typeAst): Kind {
            foreach ($typeAst->constraints as $constraint) {
                bindClassConstraintKinds($ctx, $constraint);
                unifyKind($ctx, inferKindAst($ctx, $constraint), new KType());
            }

            return inferKindAst($ctx, $typeAst->body);
        })(),
        Ast\TypeQualified::class => kindOfTypeConAst($ctx, $typeAst->name),
        default => throw new \InvalidArgumentException('unknown type for kind inference: ' . $typeAst::class),
    };
}

function kindOfPromotedAst(KindInferCtx $ctx, string $name): Kind
{
    $info = $ctx->promoted[$name] ?? null;
    if ($info === null) {
        return freshKindVar($ctx);
    }

    return promotedConstructorKind($info);
}

/** @param list<Ast\TypeNode> $args */
function inferKindApp(KindInferCtx $ctx, Ast\TypeNode $con, array $args): Kind
{
    $headKind = inferKindAst($ctx, $con);
    $result = null;

    foreach ($args as $arg) {
        $argKind = inferKindAst($ctx, $arg);
        $step = freshKindVar($ctx);
        unifyKind($ctx, $headKind, new KArrow($argKind, $step));
        $headKind = $step;
        $result = $step;
    }

    return $result ?? $headKind;
}

function kindOfTypeConAst(KindInferCtx $ctx, string $name): Kind
{
    if (isset($ctx->env[$name])) {
        return pruneKind($ctx, $ctx->env[$name]);
    }

    $kind = freshKindVar($ctx);
    $ctx->env[$name] = $kind;

    return $kind;
}

/** @param list<string> $params */
function dataResultTypeAst(string $name, array $params): Ast\AstNode
{
    $type = new Ast\TypeCon($name);
    foreach ($params as $param) {
        $type = new Ast\TypeApp($type, [new Ast\TypeVar($param)]);
    }

    return $type;
}

function inferDataKind(TypeCheckState $state, Ast\DataDecl $decl): Kind
{
    $ctx = newKindInferCtx($state);

    foreach ($decl->params as $param) {
        $ctx->varKinds[$param->name] = $param->kind instanceof Ast\KindInfer
            ? freshKindVar($ctx)
            : astKind($state, $param->kind, $decl);
    }

    $dataKind = freshKindVar($ctx);
    $ctx->env[$decl->name] = $dataKind;

    $paramNames = \array_map(static fn (Ast\DataParam $p): string => $p->name, $decl->params);
    $resultKind = inferKindAst($ctx, dataResultTypeAst($decl->name, $paramNames));
    unifyKind($ctx, $resultKind, new KType());

    foreach ($decl->constructors as $ctor) {
        foreach ($ctor->fields as $field) {
            $fieldKind = inferKindAst($ctx, $field->type);
            unifyKind($ctx, $fieldKind, new KType());
        }
    }

    return defaultUnresolvedKindVars(pruneKind($ctx, $dataKind));
}

/**
 * Default any kind variable left unconstrained by inference (e.g. a phantom,
 * unannotated data parameter never mentioned in a field) to `Type`, matching
 * the pre-DataKinds behavior of forcing every unannotated parameter to `Type`.
 */
function defaultUnresolvedKindVars(Kind $kind): Kind
{
    return match ($kind::class) {
        KVar::class => new KType(),
        KArrow::class => new KArrow(
            defaultUnresolvedKindVars($kind->from),
            defaultUnresolvedKindVars($kind->to),
        ),
        default => $kind,
    };
}

/** @return array<string, Kind> */
function inferClassParamKinds(TypeCheckState $state, Ast\ClassDecl $decl): array
{
    $ctx = newKindInferCtx($state);
    $paramKinds = [];

    foreach ($decl->params as $param) {
        if ($param->kind instanceof Ast\KindInfer) {
            $kind = freshKindVar($ctx);
        } else {
            $kind = astKind($state, $param->kind);
        }

        $paramKinds[$param->name] = $kind;
        $ctx->varKinds[$param->name] = $kind;
    }

    foreach ($decl->superclasses as $super) {
        $superKind = inferKindAst($ctx, $super);
        unifyKind($ctx, $superKind, new KType());
    }

    foreach ($decl->methods as $method) {
        $methodKind = inferKindAst($ctx, $method->type);
        unifyKind($ctx, $methodKind, new KType());
    }

    foreach ($decl->associatedTypes as $assoc) {
        foreach ($assoc->params as $paramName) {
            if (! isset($ctx->varKinds[$paramName])) {
                $ctx->varKinds[$paramName] = freshKindVar($ctx);
            }
        }
        if ($assoc->resultKind !== null) {
            astKind($state, $assoc->resultKind, $assoc);
        }
    }

    $resolved = [];
    foreach ($decl->params as $param) {
        $resolved[$param->name] = defaultUnresolvedKindVars(pruneKind($ctx, $paramKinds[$param->name]));
    }

    return $resolved;
}

function dataKind(int $paramCount): Kind
{
    $kind = new KType();
    for ($i = 0; $i < $paramCount; ++$i) {
        $kind = new KArrow(new KType(), $kind);
    }

    return $kind;
}

function astKind(TypeCheckState $state, Ast\KindNode $kindAst, ?Ast\AstNode $at = null): Kind
{
    return match ($kindAst::class) {
        Ast\KindType::class => new KType(),
        Ast\KindCon::class => new KCon($kindAst->name),
        Ast\KindArrow::class => new KArrow(
            astKind($state, $kindAst->from, $at),
            astKind($state, $kindAst->to, $at),
        ),
        default => throw typeFail($state, 'unknown kind', $at ?? ($kindAst instanceof Ast\AstNode ? $kindAst : null)),
    };
}

/** @param array<string, Kind> $varKinds */
function kindOfType(TypeCheckState $state, Type $type, array $varKinds = []): Kind
{
    $type = prune($state, $type);
    $ctx = kindInferCtxFromState($state);

    $kind = match ($type::class) {
        TVar::class => $varKinds[$type->name] ?? new KType(),
        TCon::class => kindOfAppliedTypeCon($state, $type, $varKinds),
        TPromoted::class => kindOfAppliedPromoted($state, $type),
        TStringLit::class => new KCon('Symbol'),
        TNatLit::class => new KCon('Nat'),
        default => new KType(),
    };

    return pruneKind($ctx, $kind);
}

/**
 * Kind of a (possibly applied) promoted data constructor, e.g.
 * `'Red :: Color` or `'InfixI 'LeftAssociative :: FixityI`.
 */
function kindOfAppliedPromoted(TypeCheckState $state, TPromoted $type): Kind
{
    $kind = kindOfPromoted($state, $type->name);
    $ctx = kindInferCtxFromState($state);
    foreach ($type->args as $arg) {
        $kind = pruneKind($ctx, $kind);
        if (! $kind instanceof KArrow) {
            return $kind;
        }
        $kind = $kind->to;
    }

    return $kind;
}

/** Unsaturated kind of a promoted data constructor (DataKinds). */
function kindOfPromoted(TypeCheckState $state, string $name): Kind
{
    $info = $state->promoted[$name] ?? null;
    if ($info === null) {
        return new KType();
    }

    return promotedConstructorKind($info);
}

/**
 * @param array{data: string, argKinds?: list<Kind>} $info
 */
function promotedConstructorKind(array $info): Kind
{
    $kind = new KCon($info['data']);
    foreach (\array_reverse($info['argKinds'] ?? []) as $argKind) {
        $kind = new KArrow($argKind, $kind);
    }

    return $kind;
}

/**
 * Peel a data type's inferred kind (`k1 -> k2 -> … -> Type`) into the kind of
 * each of its `$count` parameters, in order.
 *
 * @return list<Kind>
 */
function peelParamKinds(Kind $kind, int $count): array
{
    $result = [];
    for ($i = 0; $i < $count; ++$i) {
        if (! $kind instanceof KArrow) {
            throw new \InvalidArgumentException('kind applied too many times');
        }
        $result[] = $kind->from;
        $kind = $kind->to;
    }

    return $result;
}

/** @param array<string, Kind> $varKinds */
function kindOfAppliedTypeCon(TypeCheckState $state, TCon $type, array $varKinds): Kind
{
    $name = $type->name;
    $applied = \count($type->args);
    $ctx = kindInferCtxFromState($state);
    if (isset($varKinds[$name])) {
        return peelKind(pruneKind($ctx, $varKinds[$name]), $applied);
    }

    return kindOfTypeCon($state, $name, $applied);
}

function kindOfTypeCon(TypeCheckState $state, string $name, int $appliedArgs): Kind
{
    $full = $state->kindEnv[canonicalTypeConName($name)] ?? new KType();

    return peelKind($full, $appliedArgs);
}

/**
 * MagicHash primitives resolve to the canonical name.
 * Only canonical names live in kindEnv (Int, List, IO, …) — not Int#/List#/IO#.
 */
function canonicalTypeConName(string $name): string
{
    return match ($name) {
        'Int#' => 'Int',
        'Char#' => 'Char',
        'Word#' => 'Word',
        'Word8#' => 'Word8',
        'Word16#' => 'Word16',
        'Word32#' => 'Word32',
        'Word64#' => 'Word64',
        'Int8#' => 'Int8',
        'Int16#' => 'Int16',
        'Int32#' => 'Int32',
        'Int64#' => 'Int64',
        'Integer#' => 'Integer',
        'Natural#' => 'Natural',
        'String#' => 'String',
        'Bytes#' => 'ByteString',
        'Double#' => 'Double',
        'List#' => 'List',
        'IO#' => 'IO',
        'IOArray#' => 'IOArray',
        'ST#' => 'ST',
        'STRef#' => 'STRef',
        'STArray#' => 'STArray',
        'Array#' => 'Array',
        'RealWorld#' => 'RealWorld',
        'SomeException#' => 'SomeException',
        default => $name,
    };
}

/** @param array<string, Kind> $varKinds */
function assertKind(
    TypeCheckState $state,
    Type $head,
    Kind $expectedKind,
    array $varKinds = [],
    Ast\AstNode|Ast\TypeNode|null $at = null,
): void {
    $ctx = kindInferCtxFromState($state);
    $actual = kindOfType($state, $head, $varKinds);

    try {
        unifyKind($ctx, $actual, $expectedKind);
    } catch (\InvalidArgumentException $e) {
        throw typeFail(
            $state,
            'expected kind `' . kindToString(pruneKind($ctx, $expectedKind)) . '`, got `'
                . kindToString(pruneKind($ctx, $actual)) . '`',
            $at,
        );
    }
    commitKindInferCtx($state, $ctx);
}

/**
 * The kinds this declaration's signature fixed for its own type parameters.
 *
 * Run while the declaration's kind scope is live ({@see pushKindScope}), so the answer is the
 * declaration's alone: two declarations may spell one parameter name at different kinds, and
 * neither reaches the other. A parameter with no entry is `Type` — what the tree's own kind walk
 * assumes for a signature variable ({@see kindOfType}), stated here rather than left implied.
 *
 * @return array<string, string> parameter name => its kind, in the order the signature mentions it
 */
function declarationParameterKinds(TypeCheckState $state, Type $type, array $constraintArgs = []): array
{
    $vars = typeVars($type);
    foreach ($constraintArgs as $arg) {
        $vars += typeVars($arg);
    }

    $settled = [];
    foreach ($state->varKinds as $recorded => $kind) {
        $representative = prune($state, new TVar($recorded));
        if ($representative instanceof TVar) {
            $settled[$representative->name] ??= $kind;
        }
    }

    $kinds = [];
    $kindOf = static fn (string $name): Kind => defaultUnresolvedKindVars(
        $settled[$name] ?? $state->varKinds[$name] ?? new KType(),
    );
    foreach (\array_keys($vars) as $name) {
        $kinds[$name] = kindToString($kindOf($name));
    }

    return $kinds;
}

/**
 * A kind read back from the text {@see kindToString()} writes.
 *
 * A record publishes its kinds as text, so a check that holds them against the
 * declaration's type has to turn them into kinds again rather than count arrows
 * in a string.
 */
function parseKind(string $text): Kind
{
    $text = \trim($text);
    while (\str_starts_with($text, '(') && \str_ends_with($text, ')')) {
        $depth = 0;
        $wraps = true;
        for ($i = 0, $n = \strlen($text); $i < $n; ++$i) {
            $depth += match ($text[$i]) { '(' => 1, ')' => -1, default => 0 };
            if ($depth === 0 && $i < $n - 1) {
                $wraps = false;

                break;
            }
        }
        if (! $wraps) {
            break;
        }
        $text = \trim(\substr($text, 1, -1));
    }

    $depth = 0;
    for ($i = 0, $n = \strlen($text) - 1; $i < $n; ++$i) {
        $depth += match ($text[$i]) { '(' => 1, ')' => -1, default => 0 };
        if ($depth === 0 && \substr($text, $i, 2) === '->') {
            return new KArrow(parseKind(\substr($text, 0, $i)), parseKind(\substr($text, $i + 2)));
        }
    }

    return match ($text) {
        'Type' => new KType(),
        '' => throw new \InvalidArgumentException('empty kind'),
        default => new KCon($text),
    };
}

/**
 * What a type fixes about the kinds of the variables it mentions.
 *
 * Every expected kind is a fact the checker already owns: an arrow's two sides
 * are `Type`, and a type constructor, type synonym, associated family or
 * promoted constructor is applied at the kind its declaration gives it. Walking
 * the type with those facts answers two things:
 *
 *   - `vars` — variables whose kind a position fixes exactly, so `m` passed to a
 *     head at `Type -> Type` is a type function;
 *   - `applied` — variables the type applies, with the arity and the kind the
 *     result has to be, so a variable applied once in a `Type` position takes
 *     exactly one arrow whatever its argument's kind is.
 *
 * A head the facts say nothing about leaves its arguments undetermined: an
 * unknown kind is not a wrong kind, and answering `Type` for it is the defaulting
 * this exists in place of.
 *
 * @return array{vars: array<string, Kind>, applied: array<string, array{0: int, 1: Kind}>}
 */
function typeKindRequirements(TypeCheckState $facts, Type $type): array
{
    $vars = [];
    $applied = [];
    $walk = function (Type $node, ?Kind $want) use (&$walk, &$vars, &$applied, $facts): void {
        if ($node instanceof TArrow) {
            $walk($node->from, new KType());
            $walk($node->to, new KType());

            return;
        }

        if ($node instanceof TVar) {
            if ($want !== null && ! isset($vars[$node->name])) {
                $vars[$node->name] = $want;
            }

            return;
        }

        if ($node instanceof TVarApp) {
            if ($want !== null && ! isset($vars[$node->name]) && ! isset($applied[$node->name])) {
                $applied[$node->name] = [\count($node->args), $want];
            }
            foreach ($node->args as $argument) {
                $walk($argument, null);
            }

            return;
        }

        if (! $node instanceof TCon && ! $node instanceof TPromoted) {
            return;
        }

        $kind = $node instanceof TCon
            ? ($facts->kindEnv[canonicalTypeConName($node->name)] ?? null)
            : (isset($facts->promoted[$node->name])
                ? promotedConstructorKind($facts->promoted[$node->name])
                : null);
        foreach ($node->args as $argument) {
            if (! $kind instanceof KArrow) {
                return;
            }
            $walk($argument, $kind->from);
            $kind = $kind->to;
        }
    };
    $walk($type, new KType());

    return ['vars' => $vars, 'applied' => $applied];
}

/**
 * Hold a declaration's type against the kinds its parameters are published with.
 *
 * The requirements are {@see typeKindRequirements()}, so a published kind is
 * checked against the facts that fixed it rather than against a rule of its own:
 * a parameter published at `Type` that a head puts at `Type -> Type` contradicts
 * the type, and so does one published at `Type -> Type -> Type` that the type
 * applies once.
 *
 * Only published kinds are checked. A variable the scheme does not bind is not a
 * parameter — an inferred declaration the monomorphism restriction left open has
 * one, and a kind about it would be a statement the record cannot make.
 *
 * @param array<string, string> $parameterKinds the kinds the declaration publishes
 * @return list<string> every disagreement, empty when the type is well kinded
 */
function signatureKindErrors(TypeCheckState $facts, Type $type, array $parameterKinds): array
{
    $errors = [];
    $requirements = typeKindRequirements($facts, $type);
    $kindOf = static fn (string $name): ?Kind
        => isset($parameterKinds[$name]) ? parseKind($parameterKinds[$name]) : null;

    foreach ($requirements['vars'] as $name => $required) {
        $kind = $kindOf($name);
        if ($kind === null) {
            continue;
        }
        $spelled = $parameterKinds[$name];
        if (! sameKind($kind, $required)) {
            $errors[] = "states `{$name}` at kind `{$spelled}` where the type requires `"
                . kindToString($required) . '`';
        }
    }

    foreach ($requirements['applied'] as $name => [$count, $result]) {
        if (isset($requirements['vars'][$name])) {
            continue;
        }
        $kind = $kindOf($name);
        if ($kind === null) {
            continue;
        }
        $spelled = $parameterKinds[$name];
        for ($applied = 0; $applied < $count; ++$applied) {
            if (! $kind instanceof KArrow) {
                $errors[] = "states `{$name}` at kind `{$spelled}`, applied to {$count} argument(s) where"
                    . ' the result must be `' . kindToString($result) . '`';

                continue 2;
            }
            $kind = $kind->to;
        }
        if (! sameKind($kind, $result)) {
            $errors[] = "states `{$name}` at kind `{$spelled}`, applied to {$count} argument(s) where the"
                . ' result must be `' . kindToString($result) . '`';
        }
    }

    return $errors;
}

/**
 * Record a type constructor's kind.
 *
 * The key is canonical ({@see canonicalTypeConName}): every lookup goes through
 * `kindOfTypeCon`, which canonicalizes, and the machine's own types are declared
 * under their MagicHash names (`IO#`, `List#`, `Int#`) while `IO`, `List` and
 * `Int` are the names a module writes. Registering `IO#`'s kind under `IO#`
 * would put it where nothing looks.
 */
function registerTypeKind(TypeCheckState $state, string $name, Kind $kind): void
{
    $state->kindEnv[canonicalTypeConName($name)] = $kind;
}

/**
 * Rebuild a datatype's full kind from cached `paramKinds` (HKT-safe imports).
 * Falls back to `Type -> … -> Type` when param kinds are absent.
 *
 * @param array{params?: list<string>, paramKinds?: array<string, Kind>} $info
 */
function kindFromDataInfo(array $info): Kind
{
    $params = $info['params'] ?? [];
    $paramKinds = $info['paramKinds'] ?? null;
    if (! \is_array($paramKinds) || $paramKinds === []) {
        return dataKind(\count($params));
    }

    $kind = new KType();
    foreach (\array_reverse($params) as $pname) {
        $pk = $paramKinds[$pname] ?? new KType();
        $kind = new KArrow($pk, $kind);
    }

    return $kind;
}

/**
 * DataKinds: restore promoted constructors from exported data info
 * (nullary, or fields whose types are datatype kinds).
 *
 * @param array{constructors?: array<string, array{fields?: list<mixed>, fieldTypes?: list<mixed>}>} $info
 */
function installPromotedFromDataInfo(TypeCheckState $state, string $dataName, array $info): void
{
    foreach ($info['constructors'] ?? [] as $ctorName => $ctor) {
        if (isset($state->promoted[$ctorName])) {
            continue;
        }

        $fieldTypes = $ctor['fieldTypes'] ?? [];
        if ($fieldTypes === []) {
            $fields = $ctor['fields'] ?? [];
            if ($fields === []) {
                $state->promoted[$ctorName] = ['data' => $dataName];
            }

            continue;
        }

        $argKinds = [];
        $ok = true;
        foreach ($fieldTypes as $fieldType) {
            $argKind = promotableImportedFieldKind($state, $fieldType);
            if ($argKind === null) {
                $ok = false;

                break;
            }
            $argKinds[] = $argKind;
        }
        if (! $ok) {
            continue;
        }

        $entry = ['data' => $dataName];
        if ($argKinds !== []) {
            $entry['argKinds'] = $argKinds;
        }
        $state->promoted[$ctorName] = $entry;
    }
}

/** @param mixed $fieldType */
function promotableImportedFieldKind(TypeCheckState $state, mixed $fieldType): ?Kind
{
    if (! $fieldType instanceof TCon || $fieldType->args !== []) {
        return null;
    }

    return new KCon($fieldType->name);
}

/**
 * The initial kind environment contains only types intrinsic to language syntax.
 * All other named type constructors must be declared in an imported module;
 * missing imports are diagnosed, never supplied implicitly.
 */
function bootstrapKindEnv(TypeCheckState $state): void
{
    static $table = null;
    if ($table === null) {
        $table = ['List' => dataKind(1)];
        for ($arity = 2; $arity <= 64; ++$arity) {
            $table['Tuple' . $arity] = dataKind($arity);
        }
    }

    $state->kindEnv = $table;
}

/** @param array{name: string, kind?: Kind|Ast\KindNode, resolvedKind?: Kind} $param */
function classParamKind(TypeCheckState $state, array $param, ?Ast\AstNode $at = null): Kind
{
    if (isset($param['resolvedKind'])) {
        return $param['resolvedKind'];
    }

    return astKind($state, $param['kind'], $at);
}

/** @param list<array{name: string, kind?: mixed, resolvedKind?: mixed}> $params @return list<string> */
function classParamNames(array $params): array
{
    return \array_map(
        static fn (array $param): string => $param['name'],
        $params,
    );
}
