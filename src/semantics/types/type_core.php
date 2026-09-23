<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TBytes;
use Moggi\Semantics\TypeExpr\TChar;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TDouble;
use Moggi\Semantics\TypeExpr\TInt;
use Moggi\Semantics\TypeExpr\TInt16;
use Moggi\Semantics\TypeExpr\TInt32;
use Moggi\Semantics\TypeExpr\TInt64;
use Moggi\Semantics\TypeExpr\TInt8;
use Moggi\Semantics\TypeExpr\TNatLit;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TStr;
use Moggi\Semantics\TypeExpr\TStringLit;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TWord;
use Moggi\Semantics\TypeExpr\TWord16;
use Moggi\Semantics\TypeExpr\TWord32;
use Moggi\Semantics\TypeExpr\TWord64;
use Moggi\Semantics\TypeExpr\TWord8;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\TypeExpr\canonicalNatDigits;

require_once __DIR__ . '/../type_expr.php';
require_once __DIR__ . '/state.php';

function substituteInstanceTypeAst(Ast\TypeNode $type, array $mapping): Ast\TypeNode
{
    return match ($type::class) {
        Ast\TypeVar::class => isset($mapping[$type->name])
            ? internalTypeToAst($mapping[$type->name], [])
            : $type,
        Ast\TypeApp::class => new Ast\TypeApp(
            $type->con,
            \array_map(
                static fn (Ast\TypeNode $arg): Ast\TypeNode => substituteInstanceTypeAst($arg, $mapping),
                $type->args,
            ),
        ),
        Ast\TypeArrow::class => new Ast\TypeArrow(
            substituteInstanceTypeAst($type->from, $mapping),
            substituteInstanceTypeAst($type->to, $mapping),
        ),
        default => $type,
    };
}

/**
 * Substitute type variables for type-AST nodes (synonym expansion).
 *
 * @param array<string, Ast\TypeNode> $mapping
 */
function substituteTypeAstParams(Ast\TypeNode $type, array $mapping): Ast\TypeNode
{
    return match ($type::class) {
        Ast\TypeVar::class => $mapping[$type->name] ?? $type,
        Ast\TypeApp::class => typeAppAppend(
            substituteTypeAstParams($type->con, $mapping),
            \array_map(
                static fn (Ast\TypeNode $arg): Ast\TypeNode => substituteTypeAstParams($arg, $mapping),
                $type->args,
            ),
        ),
        Ast\TypeArrow::class => new Ast\TypeArrow(
            substituteTypeAstParams($type->from, $mapping),
            substituteTypeAstParams($type->to, $mapping),
        ),
        Ast\TypeConstrained::class => new Ast\TypeConstrained(
            \array_map(
                static fn (Ast\TypeNode $c): Ast\TypeNode => substituteTypeAstParams($c, $mapping),
                $type->constraints,
            ),
            substituteTypeAstParams($type->body, $mapping),
        ),
        default => $type,
    };
}

/**
 * The diagnostic for a name two imports brought in as different entities.
 *
 * Importing them is legal; naming one is not, so this is raised where the name
 * is used or re-exported, with that occurrence's own source position.
 *
 * @param array{origins: list<string>} $info
 */
function ambiguousImportMessage(string $name, array $info): string
{
    $origins = \array_values(\array_filter(
        $info['origins'] ?? [],
        static fn (string $module): bool => $module !== '',
    ));
    if (\count($origins) < 2) {
        return "ambiguous occurrence `{$name}`";
    }

    $qualified = \array_map(static fn (string $module): string => "`{$module}.{$name}`", $origins);

    return "ambiguous occurrence `{$name}`: it could refer to " . \implode(' or ', $qualified);
}

/**
 * @param list<Ast\TypeNode> $args
 */
function typeAppAppend(Ast\TypeNode $head, array $args): Ast\TypeNode
{
    if ($args === []) {
        return $head;
    }

    if ($head instanceof Ast\TypeApp) {
        return new Ast\TypeApp($head->con, [...$head->args, ...$args]);
    }

    return new Ast\TypeApp($head, $args);
}

function pushSubstFrame(TypeCheckState $state): void
{
    $state->substStack[] = $state->subst;
    $state->subst = [];
}

function popSubstFrame(TypeCheckState $state): void
{
    $state->subst = array_pop($state->substStack) ?? [];
}

/** @param list<Ast\PendingConstraint> $constraints @return list<string> */

function constraintBoundVars(array $constraints): array
{
    $bound = [];
    foreach ($constraints as $constraint) {
        foreach ($constraint->args as $arg) {
            foreach (typeVars($arg) as $name => $_) {
                $bound[$name] = true;
            }
        }
    }

    return \array_keys($bound);
}

/** @param array<string, Scheme> $env @param list<Ast\PendingConstraint> $constraints @return array<int, string> */

function schemeBoundVars(Type $type, array $env, array $constraints): array
{
    $free = [...typeVars($type), ...\array_fill_keys(constraintBoundVars($constraints), true)];
    foreach (envFreeVars($env) as $name => $_) {
        unset($free[$name]);
    }

    return \array_keys($free);
}

function freshType(TypeCheckState $state): Type
{
    return new TVar('t' . ($state->fresh++));
}

/** @return array{type: Type, constraints: list<Ast\PendingConstraint>} */

function instantiateScheme(TypeCheckState $state, Scheme $scheme): array
{
    $mapping = [];
    foreach ($scheme->bound as $var) {
        $mapping[$var] = freshType($state);
    }

    $constraints = [];
    foreach ($scheme->constraints as $constraint) {
        $constraints[] = new Ast\PendingConstraint(
            $constraint->class,
            \array_map(
                static fn (Type $arg): Type => substitute($arg, $mapping),
                $constraint->args,
            ),
            $constraint->evidence,
        );
    }

    return [
        'type' => substitute($scheme->type, $mapping),
        'constraints' => $constraints,
    ];
}

function instantiate(TypeCheckState $state, Scheme $scheme): Type
{
    return instantiateScheme($state, $scheme)['type'];
}

/** @param array<string, Scheme> $env @return array<string, true> */

function envFreeVars(array $env): array
{
    $free = [];
    foreach ($env as $scheme) {
        $free += $scheme->freeTypeVars;
    }

    return $free;
}

/** @return array<string, true> */

function typeVars(Type $type): array
{
    return match ($type::class) {
        TVar::class => [$type->name => true],
        TCon::class => \array_merge([], ...\array_map(typeVars(...), $type->args)),
        TArrow::class => [...typeVars($type->from), ...typeVars($type->to)],
        default => [],
    };
}

/** @return array<string, true> */

function typeAstVars(Ast\TypeNode $type): array
{
    return match ($type::class) {
        Ast\TypeVar::class => [$type->name => true],
        Ast\TypeUnit::class,
        Ast\TypeCon::class,
        Ast\TypePromoted::class,
        Ast\TypeStringLit::class,
        Ast\TypeNatLit::class,
        Ast\TypeQualified::class => [],
        Ast\TypeApp::class => \array_merge(
            typeAstVars($type->con),
            ...\array_map(typeAstVars(...), $type->args),
        ),
        Ast\TypeArrow::class => [...typeAstVars($type->from), ...typeAstVars($type->to)],
        Ast\TypeConstrained::class => \array_merge(
            typeAstVars($type->body),
            ...\array_map(typeAstVars(...), $type->constraints),
        ),
        default => throw new \InvalidArgumentException('unknown type AST `' . $type::class . '`'),
    };
}

function freshenTypeVars(TypeCheckState $state, Type $type): Type
{
    $mapping = [];
    foreach (\array_keys(typeVars($type)) as $var) {
        $mapping[$var] = freshType($state);
    }

    return substitute($type, $mapping);
}

/**
 * Freshen a method type together with its method-local constraints so shared
 * type variables stay aligned (e.g. `Applicative f` with `a -> f b`).
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return array{type: Type, constraints: list<Ast\PendingConstraint>}
 */
function freshenTypeWithConstraints(TypeCheckState $state, Type $type, array $constraints): array
{
    $vars = typeVars($type);
    foreach ($constraints as $constraint) {
        foreach ($constraint->args as $arg) {
            $vars += typeVars($arg);
        }
    }

    $mapping = [];
    foreach (\array_keys($vars) as $var) {
        $mapping[$var] = freshType($state);
    }

    $freshConstraints = [];
    foreach ($constraints as $constraint) {
        $freshConstraints[] = new Ast\PendingConstraint(
            $constraint->class,
            \array_map(
                static fn (Type $arg): Type => substitute($arg, $mapping),
                $constraint->args,
            ),
            $constraint->evidence,
            $constraint->implicit,
            $constraint->instanceHeadAst,
        );
    }

    return [
        'type' => substitute($type, $mapping),
        'constraints' => $freshConstraints,
    ];
}

/**
 * Like freshenTypeWithConstraints, but leave `$keep` variables unchanged so
 * instance-head vars stay aligned with ambient instance-context constraints.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @param array<string, true> $keep
 * @return array{type: Type, constraints: list<Ast\PendingConstraint>}
 */
function freshenTypeWithConstraintsKeeping(TypeCheckState $state, Type $type, array $constraints, array $keep): array
{
    $vars = typeVars($type);
    foreach ($constraints as $constraint) {
        foreach ($constraint->args as $arg) {
            $vars += typeVars($arg);
        }
    }

    $mapping = [];
    foreach (\array_keys($vars) as $var) {
        if (isset($keep[$var])) {
            continue;
        }
        $mapping[$var] = freshType($state);
    }

    $freshConstraints = [];
    foreach ($constraints as $constraint) {
        $freshConstraints[] = new Ast\PendingConstraint(
            $constraint->class,
            \array_map(
                static fn (Type $arg): Type => substitute($arg, $mapping),
                $constraint->args,
            ),
            $constraint->evidence,
            $constraint->implicit,
            $constraint->instanceHeadAst,
        );
    }

    return [
        'type' => substitute($type, $mapping),
        'constraints' => $freshConstraints,
    ];
}

/** Peel leading `__Dict_*` arrows that encode method-local constraints. */
function peelDictArrows(Type $type, int $count): Type
{
    for ($i = 0; $i < $count; ++$i) {
        if (!$type instanceof TArrow
            || !$type->from instanceof TCon
            || !str_starts_with($type->from->name, '__Dict_')) {
            break;
        }
        $type = $type->to;
    }

    return $type;
}

/** @param array{name: string, kind: Kinds\Kind|Ast\KindNode} $param */

function constructorMappingValue(TypeCheckState $state, array $param, Type $argType): Type
{
    $kind = Kinds\classParamKind($state, $param);
    if ($kind instanceof Kinds\KArrow && $argType instanceof TVar && $argType->name === $param['name']) {
        return new TCon($param['name']);
    }

    return $argType;
}

/** @param array<int, Type> $extraArgs @param array<string, Type> $mapping */

function applyConSubst(TCon $head, array $extraArgs, array $mapping): Type
{
    $mergedArgs = [...$head->args];
    foreach ($extraArgs as $arg) {
        $mergedArgs[] = substitute($arg, $mapping);
    }

    return new TCon($head->name, $mergedArgs);
}

/** @param array<string, Type> $mapping */

function substitute(Type $type, array $mapping): Type
{
    return match ($type::class) {
        TVar::class => $mapping[$type->name] ?? $type,
        TCon::class => substituteCon($type, $mapping),
        TPromoted::class => $type->args === []
            ? $type
            : new TPromoted(
                $type->name,
                \array_map(
                    static fn (Type $arg): Type => substitute($arg, $mapping),
                    $type->args,
                ),
            ),
        TArrow::class => new TArrow(
            substitute($type->from, $mapping),
            substitute($type->to, $mapping),
        ),
        default => $type,
    };
}

/**
 * Substitute inside a type constructor. A mapping may rename the constructor
 * head itself (higher-kinded type variables are encoded as nullary `TCon`s).
 *
 * @param array<string, Type> $mapping
 */
function substituteCon(TCon $type, array $mapping): Type
{
    $substitutedArgs = static fn (): array => \array_map(
        static fn (Type $arg): Type => substitute($arg, $mapping),
        $type->args,
    );

    $head = $mapping[$type->name] ?? null;

    return match (true) {
        $head === null => new TCon($type->name, $substitutedArgs()),
        $head instanceof TCon => applyConSubst($head, $type->args, $mapping),
        $head instanceof TVar => new TCon($head->name, $substitutedArgs()),
        default => throw new \InvalidArgumentException('invalid mapping for type constructor'),
    };
}

function unify(TypeCheckState $state, Type $left, Type $right, ?Ast\AstNode $at = null): void
{
    $left = prune($state, $left);
    $right = prune($state, $right);

    // Same associated family application: unify arguments without reducing.
    // Otherwise `Rep Foo x` normalizes to a concrete M1-tree and cannot unify
    // with a polymorphic `Rep a b` from an instantiated class method (`to`/`from`).
    if ($left instanceof TCon && $right instanceof TCon
        && $left->name === $right->name
        && isset($state->associatedFamilies[$left->name])
    ) {
        if (count($left->args) !== count($right->args)) {
            throw typeFail($state, 'type arity mismatch on `' . $left->name . '`', $at);
        }
        foreach ($left->args as $i => $arg) {
            unify($state, $arg, $right->args[$i], $at);
        }

        return;
    }

    // Bind type variables before associated-family reduction so
    // `from x :: Rep Foo t` stays `Rep Foo t` (not a concrete M1-tree) when
    // composed with polymorphic `to :: Rep a x -> a`.
    if ($left instanceof TVar) {
        if ($right instanceof TVar) {
            if ($left->name === $right->name) {
                return;
            }
            // A restricted variable is the representative every use site shares, and the module end
            // names it; binding it away would lose that identity.
            if (isset($state->restrictedVars[$left->name]) && ! isset($state->restrictedVars[$right->name])) {
                unify($state, $right, $left, $at);

                return;
            }
        }
        occurs($state, $left->name, $right, $at);
        $state->subst[$left->name] = $right;

        return;
    }

    if ($right instanceof TVar) {
        unify($state, $right, $left, $at);

        return;
    }

    // Normalize synonyms only — associated families stay stuck so `Rep Foo x` can still
    // unify with a polymorphic `Rep a b`.
    $left = normalizeType($state, $left, reduceFamilies: false);
    $right = normalizeType($state, $right, reduceFamilies: false);

    if ($left instanceof TCon && isset($state->associatedFamilies[$left->name])) {
        $reduced = reduceAssociatedFamily($state, $left);
        if ($reduced !== null) {
            unify($state, $reduced, $right, $at);

            return;
        }
    }

    if ($right instanceof TCon && isset($state->associatedFamilies[$right->name])) {
        $reduced = reduceAssociatedFamily($state, $right);
        if ($reduced !== null) {
            unify($state, $left, $reduced, $at);

            return;
        }
    }

    if (samePrimitiveType($left, $right)) {
        return;
    }

    if ($left instanceof TUnit && $right instanceof TUnit) {
        return;
    }

    if ($left instanceof TPromoted || $right instanceof TPromoted) {
        if (
            $left instanceof TPromoted
            && $right instanceof TPromoted
            && $left->name === $right->name
            && count($left->args) === count($right->args)
        ) {
            foreach ($left->args as $i => $arg) {
                unify($state, $arg, $right->args[$i], $at);
            }

            return;
        }

        throw typeFail($state, formatUnifyError($state, $left, $right), $at, 'unify');
    }

    if ($left instanceof TStringLit || $right instanceof TStringLit) {
        if ($left instanceof TStringLit && $right instanceof TStringLit && $left->value === $right->value) {
            return;
        }

        throw typeFail($state, formatUnifyError($state, $left, $right), $at, 'unify');
    }

    if ($left instanceof TNatLit || $right instanceof TNatLit) {
        if ($left instanceof TNatLit && $right instanceof TNatLit && $left->digits === $right->digits) {
            return;
        }

        throw typeFail($state, formatUnifyError($state, $left, $right), $at, 'unify');
    }

    if (!$left instanceof TCon || !$right instanceof TCon) {
        if ($left instanceof TArrow && $right instanceof TArrow) {
            unify($state, $left->from, $right->from, $at);
            unify($state, $left->to, $right->to, $at);

            return;
        }

        throw typeFail($state, formatUnifyError($state, $left, $right), $at, 'unify');
    }

    if ($left->name === $right->name) {
        if (count($left->args) !== count($right->args)) {
            throw typeFail($state, 'type arity mismatch on `' . $left->name . '`', $at);
        }
        foreach ($left->args as $i => $arg) {
            unify($state, $arg, $right->args[$i], $at);
        }

        return;
    }

    $leftHeadIsVar = !isset($state->kindEnv[$left->name]);
    $rightHeadIsVar = !isset($state->kindEnv[$right->name]);

    if ($leftHeadIsVar && !$rightHeadIsVar && count($left->args) === count($right->args)) {
        unify($state, new TVar($left->name), new TCon($right->name), $at);
        foreach ($left->args as $i => $arg) {
            unify($state, $arg, $right->args[$i], $at);
        }

        return;
    }

    // Symmetric case: concrete constructor on the left, HKT variable on the right
    // (e.g. unifying `IO ()` with `t0 ()` from an instantiated `f a`).
    if ($rightHeadIsVar && !$leftHeadIsVar && count($left->args) === count($right->args)) {
        unify($state, new TVar($right->name), new TCon($left->name), $at);
        foreach ($left->args as $i => $arg) {
            unify($state, $arg, $right->args[$i], $at);
        }

        return;
    }

    // Partial applications of concrete constructors as HKT heads, e.g.
    // `t0 a` ~ `K1 R Int Bool` ⇒ `t0 := K1 R Int`, `a := Bool`.
    // (Previously only the +1-arg case `t0 a` ~ `Maybe Int` was handled.)
    if ($leftHeadIsVar && !$rightHeadIsVar
        && count($left->args) >= 1
        && count($right->args) > count($left->args)) {
        unifyHktPartialApp($state, $left, $right, $at);

        return;
    }

    if ($rightHeadIsVar && !$leftHeadIsVar
        && count($right->args) >= 1
        && count($left->args) > count($right->args)) {
        unifyHktPartialApp($state, $right, $left, $at);

        return;
    }

    if ($leftHeadIsVar && $rightHeadIsVar
        && count($left->args) === count($right->args)
        && count($left->args) >= 1) {
        unify($state, new TVar($left->name), new TVar($right->name), $at);
        foreach ($left->args as $i => $arg) {
            unify($state, $arg, $right->args[$i], $at);
        }

        return;
    }

    throw typeFail($state, formatUnifyError($state, $left, $right), $at, 'unify');
}

/**
 * Unify HKT variable application `f a1 … ak` with concrete `C x1 … xm` (m > k):
 * bind `f := C x1 … x(m-k)` and unify each `ai` with `x(m-k+i)`.
 */
function unifyHktPartialApp(
    TypeCheckState $state,
    TCon $hktApp,
    TCon $concrete,
    ?Ast\AstNode $at,
): void {
    $k = count($hktApp->args);
    $m = count($concrete->args);
    $partialArgs = \array_slice($concrete->args, 0, $m - $k);
    unify($state, new TVar($hktApp->name), new TCon($concrete->name, $partialArgs), $at);
    for ($i = 0; $i < $k; ++$i) {
        unify($state, $hktApp->args[$i], $concrete->args[$m - $k + $i], $at);
    }
}

function formatUnifyError(TypeCheckState $state, Type $left, Type $right): string
{
    if (isset($state->unifyMessage) && is_callable($state->unifyMessage)) {
        return ($state->unifyMessage)($left, $right);
    }

    $rename = friendlyTypeVarNames([$left, $right]);

    return 'could not unify `' . typeToString($left, $rename) . '` with `' . typeToString($right, $rename) . '`';
}

/**
 * Build a rename map turning compiler-generated fresh type variables (t0, t1, …)
 * into readable letters (a, b, c, …) while leaving user-written names untouched.
 *
 * @param list<Type> $types @return array<string, string>
 */
function friendlyTypeVarNames(array $types): array
{
    $userVars = [];
    $freshOrder = [];

    $collect = function (Type $type) use (&$collect, &$userVars, &$freshOrder): void {
        if ($type instanceof TVar) {
            if (preg_match('/^t\d+$/', $type->name) !== 1) {
                $userVars[$type->name] = true;
            } elseif (!\in_array($type->name, $freshOrder, true)) {
                $freshOrder[] = $type->name;
            }

            return;
        }

        if ($type instanceof TCon) {
            foreach ($type->args as $arg) {
                $collect($arg);
            }

            return;
        }

        if ($type instanceof TArrow) {
            $collect($type->from);
            $collect($type->to);
        }
    };

    foreach ($types as $type) {
        $collect($type);
    }

    $rename = [];
    $next = 0;
    foreach ($freshOrder as $fresh) {
        do {
            $letter = typeVarLetter($next++);
        } while (isset($userVars[$letter]));
        $rename[$fresh] = $letter;
    }

    return $rename;
}

function typeVarLetter(int $index): string
{
    $letter = chr(97 + ($index % 26));
    $suffix = intdiv($index, 26);

    return $suffix === 0 ? $letter : $letter . $suffix;
}

function prune(TypeCheckState $state, Type $type): Type
{
    // Unification subst is empty during most registration / scheme work; skip
    // walking type spines that cannot change.
    if ($state->subst === []) {
        return $type;
    }

    if ($type instanceof TVar) {
        if (!isset($state->subst[$type->name])) {
            return $type;
        }

        $resolved = prune($state, $state->subst[$type->name]);
        $state->subst[$type->name] = $resolved;

        return $resolved;
    }

    if ($type instanceof TCon) {
        $args = [];
        $changed = false;
        foreach ($type->args as $i => $arg) {
            $pruned = prune($state, $arg);
            $args[$i] = $pruned;
            if ($pruned !== $arg) {
                $changed = true;
            }
        }

        // Higher-kinded variables are encoded as nullary/applied TCon heads.
        // Unification binds a TVar of the same name; rewrite the head here so
        // `t0 a` becomes `IO a` after `t0 := IO`.
        if (!isset($state->kindEnv[$type->name]) && isset($state->subst[$type->name])) {
            $head = prune($state, $state->subst[$type->name]);
            if ($head instanceof TCon) {
                return new TCon($head->name, [...$head->args, ...$args]);
            }
            if ($head instanceof TVar) {
                return new TCon($head->name, $args);
            }
        }

        return $changed ? new TCon($type->name, $args) : $type;
    }

    if ($type instanceof TArrow) {
        $from = prune($state, $type->from);
        $to = prune($state, $type->to);

        return $from === $type->from && $to === $type->to ? $type : new TArrow($from, $to);
    }

    return $type;
}

/**
 * Rewrite inferredType annotations after the body's unifications are complete,
 * so HKT heads like `t0 Handle` become `IO Handle` for later IO-boundary checks.
 */
function zonkInferredTypesInExpr(TypeCheckState $state, Ast\AstNode $expr): void
{
    if ($expr->inferredType !== null) {
        // Round-trip through prune: inferredType was snapshotted mid-inference,
        // before later unifications (e.g. HKT `t0 := IO`) landed in subst.
        $expr->inferredType = internalTypeToAst(
            prune($state, inferredTypeAstToInternal($expr->inferredType)),
            $state->subst,
        );
    }

    match ($expr::class) {
        Ast\Apply::class => (static function () use ($state, $expr): void {
            zonkInferredTypesInExpr($state, $expr->function);
            zonkInferredTypesInExpr($state, $expr->argument);
        })(),
        Ast\Infix::class => (static function () use ($state, $expr): void {
            zonkInferredTypesInExpr($state, $expr->left);
            zonkInferredTypesInExpr($state, $expr->right);
        })(),
        Ast\Lambda::class => (static function () use ($state, $expr): void {
            foreach ($expr->params as $param) {
                zonkInferredTypesInPattern($state, $param->pattern);
            }
            zonkInferredTypesInExpr($state, $expr->body);
        })(),
        Ast\Let::class => (static function () use ($state, $expr): void {
            foreach ($expr->bindings as $binding) {
                zonkInferredTypesInPattern($state, $binding->pattern);
                zonkInferredTypesInExpr($state, $binding->value);
            }
            zonkInferredTypesInExpr($state, $expr->body);
        })(),
        Ast\Where::class => (static function () use ($state, $expr): void {
            foreach ($expr->bindings as $binding) {
                zonkInferredTypesInPattern($state, $binding->pattern);
                zonkInferredTypesInExpr($state, $binding->value);
            }
            zonkInferredTypesInExpr($state, $expr->expr);
        })(),
        Ast\CaseExpr::class => (static function () use ($state, $expr): void {
            zonkInferredTypesInExpr($state, $expr->scrutinee);
            foreach ($expr->alts as $alt) {
                zonkInferredTypesInPattern($state, $alt->pattern);
                zonkInferredTypesInExpr($state, $alt->body);
            }
        })(),
        Ast\DoExpr::class => (static function () use ($state, $expr): void {
            if ($expr->desugared !== null) {
                zonkInferredTypesInExpr($state, $expr->desugared);
            }
            foreach ($expr->stmts as $stmt) {
                if ($stmt instanceof Ast\DoBind || $stmt instanceof Ast\DoExprStmt) {
                    zonkInferredTypesInPattern($state, $stmt->pattern ?? null);
                    zonkInferredTypesInExpr($state, $stmt->expr);
                }
                if ($stmt instanceof Ast\DoLet) {
                    foreach ($stmt->bindings as $binding) {
                        zonkInferredTypesInPattern($state, $binding->pattern ?? null);
                        zonkInferredTypesInExpr($state, $binding->expr);
                    }
                }
            }
        })(),
        Ast\GuardsExpr::class => (static function () use ($state, $expr): void {
            foreach ($expr->clauses as $clause) {
                zonkInferredTypesInExpr($state, $clause->guard);
                zonkInferredTypesInExpr($state, $clause->body);
            }
        })(),
        Ast\TypeAsc::class => zonkInferredTypesInExpr($state, $expr->expr),
        Ast\IntrinsicCall::class => (static function () use ($state, $expr): void {
            foreach ($expr->args as $arg) {
                zonkInferredTypesInExpr($state, $arg);
            }
        })(),
        Ast\Tuple::class, Ast\ListLit::class => (static function () use ($state, $expr): void {
            foreach ($expr->elements as $element) {
                zonkInferredTypesInExpr($state, $element);
            }
        })(),
        Ast\RecordCon::class => (static function () use ($state, $expr): void {
            foreach ($expr->fields as $field) {
                zonkInferredTypesInExpr($state, $field->expr);
            }
        })(),
        Ast\RecordUpdate::class => (static function () use ($state, $expr): void {
            zonkInferredTypesInExpr($state, $expr->object);
            foreach ($expr->fields as $field) {
                zonkInferredTypesInExpr($state, $field->expr);
            }
        })(),
        Ast\FieldAccess::class => zonkInferredTypesInExpr($state, $expr->object),
        default => null,
    };
}

/**
 * Re-snapshot inferred types on pattern binders after unification finished.
 *
 * bindPattern records each PatVar's type while checking, before later
 * unifications (of the function body, of sibling patterns, of the result)
 * resolve the fresh variables. Without this pass, hover/inlay show stale
 * internal variable names (e.g. `t71`) instead of the final type.
 */
function zonkInferredTypesInPattern(TypeCheckState $state, ?Ast\AstNode $pattern): void
{
    if ($pattern === null || !is_object($pattern) || $pattern instanceof Ast\TypeNode) {
        return;
    }
    if ($pattern->inferredType !== null) {
        $pattern->inferredType = internalTypeToAst(
            prune($state, inferredTypeAstToInternal($pattern->inferredType)),
            $state->subst,
        );
    }

    match ($pattern::class) {
        Ast\PatCon::class => (static function () use ($state, $pattern): void {
            foreach ($pattern->args as $arg) {
                if ($arg instanceof Ast\AstNode) {
                    zonkInferredTypesInPattern($state, $arg);
                }
            }
        })(),
        Ast\PatTuple::class => (static function () use ($state, $pattern): void {
            foreach ($pattern->elements as $el) {
                zonkInferredTypesInPattern($state, $el);
            }
        })(),
        Ast\PatCons::class => (static function () use ($state, $pattern): void {
            zonkInferredTypesInPattern($state, $pattern->head);
            zonkInferredTypesInPattern($state, $pattern->tail);
        })(),
        Ast\PatRecord::class => (static function () use ($state, $pattern): void {
            foreach ($pattern->fields as $field) {
                zonkInferredTypesInPattern($state, $field->pattern);
            }
        })(),
        default => null,
    };
}

/**
 * Lenient AST→internal conversion for inferredType snapshots (no kind checks).
 * Fresh HKT heads like `t0` are ordinary TCon names; prune rewrites them.
 */
function inferredTypeAstToInternal(Ast\TypeNode $type): Type
{
    return match ($type::class) {
        Ast\TypeVar::class => new TVar($type->name),
        Ast\TypeUnit::class => new TUnit(),
        Ast\TypeCon::class => new TCon($type->name),
        Ast\TypePromoted::class => new TPromoted($type->name),
        Ast\TypeStringLit::class => new TStringLit($type->value),
        Ast\TypeNatLit::class => new TNatLit(canonicalNatDigits($type->digits)),
        Ast\TypeApp::class => (static function () use ($type): Type {
            $args = \array_map(inferredTypeAstToInternal(...), $type->args);
            if ($type->con instanceof Ast\TypePromoted) {
                return new TPromoted($type->con->name, $args);
            }

            return new TCon(
                match (true) {
                    $type->con instanceof Ast\TypeCon,
                    $type->con instanceof Ast\TypeVar => $type->con->name,
                    default => throw new \InvalidArgumentException(
                        'unexpected inferred type app head: ' . $type->con::class,
                    ),
                },
                $args,
            );
        })(),
        Ast\TypeArrow::class => new TArrow(
            inferredTypeAstToInternal($type->from),
            inferredTypeAstToInternal($type->to),
        ),
        Ast\TypeConstrained::class => inferredTypeAstToInternal($type->body),
        default => throw new \InvalidArgumentException(
            'unexpected inferred type node: ' . $type::class,
        ),
    };
}

function occurs(TypeCheckState $state, string $var, Type $type, ?Ast\AstNode $at = null): void
{
    $type = prune($state, $type);

    if ($type instanceof TVar && $type->name === $var) {
        $rename = friendlyTypeVarNames([$type]);
        $shown = $rename[$var] ?? $var;

        throw typeFail($state, "infinite type: `{$shown}` occurs in itself", $at);
    }

    if ($type instanceof TCon) {
        foreach ($type->args as $arg) {
            occurs($state, $var, $arg, $at);
        }
    }

    if ($type instanceof TPromoted) {
        foreach ($type->args as $arg) {
            occurs($state, $var, $arg, $at);
        }
    }

    if ($type instanceof TArrow) {
        occurs($state, $var, $type->from, $at);
        occurs($state, $var, $type->to, $at);
    }
}

/**
 * The primitive type a zero-argument constructor names, or the type itself when
 * it is not one of the machine primitives.
 *
 * A type that has been through the AST and back arrives as the constructor the
 * type printer writes (`TCon('Int')`), where inference uses the primitive
 * (`TInt`). The two spell the same type, so code that inspects a type for a
 * machine representation has to see both alike.
 */
function canonicalPrimitiveType(Type $type): Type
{
    if (! $type instanceof TCon || $type->args !== []) {
        return $type;
    }

    return match ($type->name) {
        'Int' => new TInt(),
        'Int8' => new TInt8(),
        'Int16' => new TInt16(),
        'Int32' => new TInt32(),
        'Int64' => new TInt64(),
        'Word' => new TWord(),
        'Word8' => new TWord8(),
        'Word16' => new TWord16(),
        'Word32' => new TWord32(),
        'Word64' => new TWord64(),
        'Char' => new TChar(),
        'String' => new TStr(),
        'Double' => new TDouble(),
        'ByteString' => new TBytes(),
        default => $type,
    };
}

function samePrimitiveType(Type $left, Type $right): bool
{
    $normalize = static function (Type $type): ?string {
        return match ($type::class) {
            TInt::class => 'Int',
            TStr::class => 'String',
            TChar::class => 'Char',
            TWord::class => 'Word',
            TWord8::class => 'Word8',
            TWord16::class => 'Word16',
            TWord32::class => 'Word32',
            TWord64::class => 'Word64',
            TInt8::class => 'Int8',
            TInt16::class => 'Int16',
            TInt32::class => 'Int32',
            TInt64::class => 'Int64',
            TBytes::class => 'ByteString',
            TDouble::class => 'Double',
            TCon::class => count($type->args) === 0 ? $type->name : null,
            default => null,
        };
    };

    $leftName = $normalize($left);
    $rightName = $normalize($right);

    return $leftName !== null && $leftName === $rightName;
}


/**
 * A type in an operand position where only an arrow needs parentheses — an
 * arrow's domain or a tuple element (`(a -> b) -> c`, `(a -> b, c)`), because
 * application already binds tighter than `->` and `,`.
 *
 * @param array<string, string> $rename
 */
function typeToStringDomain(Type $type, array $rename = []): string
{
    $text = typeToString($type, $rename);

    return $type instanceof TArrow ? '(' . $text . ')' : $text;
}

/**
 * A type as a class argument: an applied or arrow type is parenthesized, an atomic one is not.
 *
 * @param array<string, string> $rename
 */
function typeToStringArgument(Type $type, array $rename = []): string
{
    $text = typeToString($type, $rename);
    $atomic = match ($type::class) {
        TInt::class, TStr::class, TChar::class, TWord::class, TWord8::class, TWord16::class,
        TWord32::class, TWord64::class, TInt8::class, TInt16::class, TInt32::class, TInt64::class,
        TBytes::class, TDouble::class, TUnit::class, TVar::class, TStringLit::class, TNatLit::class => true,
        TCon::class => $type->name === 'List' ? count($type->args) === 1 : $type->args === [],
        default => false,
    };

    return $atomic ? $text : '(' . $text . ')';
}

/** @param array<string, string> $rename */
function typeToString(Type $type, array $rename = []): string
{
    return match ($type::class) {
        TInt::class => 'Int',
        TStr::class => 'String',
        TChar::class => 'Char',
        TWord::class => 'Word',
        TWord8::class => 'Word8',
        TWord16::class => 'Word16',
        TWord32::class => 'Word32',
        TWord64::class => 'Word64',
        TInt8::class => 'Int8',
        TInt16::class => 'Int16',
        TInt32::class => 'Int32',
        TInt64::class => 'Int64',
        TBytes::class => 'ByteString',
        TDouble::class => 'Double',
        TUnit::class => '()',
        TVar::class => $rename[$type->name] ?? $type->name,
        TPromoted::class => "'" . $type->name . (count($type->args) > 0
            ? ' ' . join(' ', \array_map(static fn (Type $arg): string => typeToString($arg, $rename), $type->args))
            : ''),
        TStringLit::class => json_encode($type->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        TNatLit::class => $type->digits,
        TCon::class => (static function () use ($type, $rename): string {
            if ($type->name === 'List' && count($type->args) === 1) {
                return '[' . typeToString($type->args[0], $rename) . ']';
            }
            // A tuple is a `TupleN` constructor internally, `(a, b)` on the
            // surface — the shape every other printer and the source use.
            if (preg_match('/^Tuple(\d+)$/', $type->name, $tuple) === 1 && count($type->args) === (int) $tuple[1]) {
                return '(' . join(', ', \array_map(
                    static fn (Type $arg): string => typeToStringDomain($arg, $rename),
                    $type->args,
                )) . ')';
            }

            return $type->name . (count($type->args) > 0
                ? ' ' . join(' ', \array_map(static fn (Type $arg): string => typeToString($arg, $rename), $type->args))
                : '');
        })(),
        // An arrow domain needs its parentheses, or `(a -> b) -> c` would read
        // as `a -> b -> c`.
        TArrow::class => typeToStringDomain($type->from, $rename) . ' -> ' . typeToString($type->to, $rename),
    };
}

/** @param array<string, Type> $subst */

function internalTypeToAst(Type $type, array $subst): Ast\TypeNode
{
    $pruneState = new TypeCheckState('');
    $pruneState->subst = $subst;
    $type = prune($pruneState, $type);

    return match ($type::class) {
        TInt::class => new Ast\TypeCon('Int'),
        TStr::class => new Ast\TypeCon('String'),
        TChar::class => new Ast\TypeCon('Char'),
        TWord::class => new Ast\TypeCon('Word'),
        TWord8::class => new Ast\TypeCon('Word8'),
        TWord16::class => new Ast\TypeCon('Word16'),
        TWord32::class => new Ast\TypeCon('Word32'),
        TWord64::class => new Ast\TypeCon('Word64'),
        TInt8::class => new Ast\TypeCon('Int8'),
        TInt16::class => new Ast\TypeCon('Int16'),
        TInt32::class => new Ast\TypeCon('Int32'),
        TInt64::class => new Ast\TypeCon('Int64'),
        TBytes::class => new Ast\TypeCon('ByteString'),
        TDouble::class => new Ast\TypeCon('Double'),
        TUnit::class => new Ast\TypeUnit(),
        TVar::class => new Ast\TypeVar($type->name),
        TPromoted::class => count($type->args) === 0
            ? new Ast\TypePromoted($type->name)
            : new Ast\TypeApp(new Ast\TypePromoted($type->name), \array_map(
                static fn (Type $arg): Ast\TypeNode => internalTypeToAst($arg, $subst),
                $type->args,
            )),
        TStringLit::class => new Ast\TypeStringLit($type->value),
        TNatLit::class => new Ast\TypeNatLit($type->digits, false),
        TCon::class => count($type->args) === 0
            ? new Ast\TypeCon($type->name)
            : new Ast\TypeApp(new Ast\TypeCon($type->name), \array_map(
                static fn (Type $arg): Ast\TypeNode => internalTypeToAst($arg, $subst),
                $type->args,
            )),
        TArrow::class => new Ast\TypeArrow(
            internalTypeToAst($type->from, $subst),
            internalTypeToAst($type->to, $subst),
        ),
        default => throw new \InvalidArgumentException('unknown type: ' . $type::class),
    };
}

function knownTypeConstructorNames(TypeCheckState $state): array
{
    $names = [
        ...\array_keys($state->data),
        ...\array_keys($state->kindEnv),
        ...\array_keys($state->declaredTypeNames),
        ...\array_keys($state->typeSynonyms),
        ...\array_keys($state->associatedFamilies),
    ];

    sort($names);

    return array_values(array_unique($names));
}

function typeFail(TypeCheckState $state, string $message, Ast\AstNode|Ast\TypeNode|null $at = null, ?string $code = null): TypeError
{
    $line = 0;
    $col = 0;
    $endCol = 0;

    if ($at !== null && $at->line !== 0) {
        $line = $at->line;
        $col = $at->col;
        $endCol = $at->endCol;
    } elseif ($state->declSpan['line'] !== 0) {
        // A node without a position (a type node, a desugared match) still belongs to a
        // declaration, and pointing at it beats rendering against line 0.
        $line = $state->declSpan['line'];
        $col = $state->declSpan['col'];
        $endCol = $state->declSpan['endCol'];
    }

    $error = new TypeError(
        $message,
        $state->filename,
        $state->source,
        $line,
        $col,
        $endCol,
    );
    $error->diagnosticCode = $code;

    return $error;
}
