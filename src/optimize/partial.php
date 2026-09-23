<?php declare(strict_types=1);

namespace Moggi\Optimize\Partial;

use Moggi\Backend\Php\Emit\emitApplyExpr;
use Moggi\IR;
use Moggi\Syntax\Ast;

use function Moggi\IR\Visit\mapOperandChildren;
use function Moggi\IR\Visit\stmtDirectOperands;
use function Moggi\IR\Visit\stmtNestedBlocks;
use function Moggi\Optimize\GlobalPass\rewriteStmtOperands;
use function Moggi\Optimize\Interproc\indexFunctions;
use function Moggi\Optimize\Interproc\isSimpleLambda;
use function Moggi\Optimize\Support\buildLambdaMeta;
use function Moggi\Optimize\Support\indexCapturedFunctions;
use function Moggi\Optimize\Support\isCapturedFnName;
use function Moggi\Optimize\Support\isLambdaName;
use function Moggi\Optimize\Support\mapStmtNestedBlocks;
use function Moggi\Optimize\Support\operandToExpr;

/** @param list<IR\FunctionDecl> $functions */
function bindLambdaCaptures(array $functions): array
{
    $meta = buildLambdaMeta(indexCapturedFunctions($functions));

    return \array_map(
        static fn (IR\FunctionDecl $function): IR\FunctionDecl => $function->withBody(
            new IR\Block(bindLambdaCapturesItems($function->body->items, $meta)),
        ),
        $functions,
    );
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 * @return list<IR\Stmt>
 */
function bindLambdaCapturesItems(array $items, array $meta): array
{
    return \array_map(
        static fn (IR\Stmt $item): IR\Stmt => bindLambdaCapturesStmt($item, $meta),
        $items,
    );
}

/**
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 */
function bindLambdaCapturesStmt(IR\Stmt $stmt, array $meta): IR\Stmt
{
    return match ($stmt::class) {
        IR\Ret::class => new IR\Ret(bindLambdaCapturesOperand($stmt->value, $meta)),
        IR\Assign::class => new IR\Assign($stmt->dest, bindLambdaCapturesOperand($stmt->value, $meta)),
        IR\Let::class => new IR\Let($stmt->name, bindLambdaCapturesOperand($stmt->value, $meta)),
        IR\Binop::class => new IR\Binop(
            $stmt->op,
            bindLambdaCapturesOperand($stmt->left, $meta),
            bindLambdaCapturesOperand($stmt->right, $meta),
            $stmt->dest,
        ),
        IR\Call::class => new IR\Call(
            $stmt->callee,
            captureArgsForCall($stmt->callee, \array_map(
                static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta),
                $stmt->args,
            ), $meta),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\CallValue::class => new IR\CallValue(
            bindLambdaCapturesOperand($stmt->callee, $meta),
            \array_map(static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta), $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\DictCall::class => new IR\DictCall(
            bindLambdaCapturesOperand($stmt->evidence, $meta),
            $stmt->method,
            \array_map(static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta), $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        default => mapStmtNestedBlocks(
            $stmt,
            static fn (IR\Block $block): IR\Block => new IR\Block(bindLambdaCapturesItems($block->items, $meta)),
        ),
    };
}

/**
 * Captures a direct call has to pass before the arguments the source wrote.
 *
 * A call by name carries only the declared arguments, so a lifted function's
 * free locals are missing from it: a recursive `let`/`where` group lowers to a
 * plain `__letrecN` call, and an immediately applied lambda is called by name.
 * A specialized call, an `IR\Partial`, and the adapter a constrained local
 * binding lowers to (`λ(dict, p) -> λ3(dict, p)`) already carry them, which is
 * why they are only prepended when the call carries exactly the declared
 * parameters and no capture of its own is among them.
 *
 * @param list<IR\Operand> $args
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 * @return list<IR\Operand>
 */
function captureArgsForCall(string $name, array $args, array $meta): array
{
    $info = $meta[$name] ?? null;
    $captures = $info['captures'] ?? [];
    if ($captures === [] || count($args) !== count($info['params']) || callArgsLeadWithCaptures($args, $captures)) {
        return $args;
    }

    return [
        ...\array_map(static fn (string $capture): IR\Operand => new IR\Local($capture), $captures),
        ...$args,
    ];
}

/**
 * Whether a call already passes its callee's captures in front of its
 * arguments, in which case they must not be added a second time.
 *
 * @param list<IR\Operand> $args
 * @param list<string> $captures
 */
function callArgsLeadWithCaptures(array $args, array $captures): bool
{
    if (count($args) < count($captures)) {
        return false;
    }

    foreach ($captures as $i => $capture) {
        $arg = $args[$i] ?? null;
        if (!($arg instanceof IR\Local) || $arg->name !== $capture) {
            return false;
        }
    }

    return true;
}

/**
 * A partial of a lifted function counts its captures in `arity` as well, so one
 * built from the declared parameters alone is widened to the full arity.
 *
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 */
function bindCapturedPartial(IR\Partial|IR\ExprPartial $partial, array $meta): IR\Partial|IR\ExprPartial
{
    $args = \array_map(
        static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta),
        $partial->args,
    );
    $info = $meta[$partial->fn] ?? null;
    $captures = $info['captures'] ?? [];
    if ($captures === [] || $partial->arity !== count($info['params']) || callArgsLeadWithCaptures($args, $captures)) {
        return $partial instanceof IR\Partial
            ? new IR\Partial($partial->fn, $partial->arity, $args)
            : new IR\ExprPartial($partial->fn, $partial->arity, $args);
    }

    $arity = $partial->arity + count($captures);
    $args = [
        ...\array_map(static fn (string $capture): IR\Operand => new IR\Local($capture), $captures),
        ...$args,
    ];

    return $partial instanceof IR\Partial
        ? new IR\Partial($partial->fn, $arity, $args)
        : new IR\ExprPartial($partial->fn, $arity, $args);
}

/**
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 */
function bindLambdaCapturesOperand(IR\Operand $operand, array $meta): IR\Operand
{
    if ($operand instanceof IR\FnRef && isCapturedFnName($operand->name)) {
        $info = $meta[$operand->name] ?? null;
        if ($info !== null && ($info['captures'] ?? []) !== []) {
            $captureArgs = \array_map(
                static fn (string $name): IR\Operand => new IR\Local($name),
                $info['captures'],
            );
            $arity = count($info['captures']) + count($info['params']);

            return new IR\Partial($operand->name, $arity, $captureArgs);
        }
    }

    return match ($operand::class) {
        IR\ExprCall::class => new IR\ExprCall(
            $operand->callee,
            captureArgsForCall($operand->callee, \array_map(
                static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta),
                $operand->args,
            ), $meta),
            $operand->srcLoc,
        ),
        IR\ExprCallValue::class => new IR\ExprCallValue(
            bindLambdaCapturesOperand($operand->callee, $meta),
            \array_map(static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta), $operand->args),
            $operand->srcLoc,
        ),
        IR\ExprBinop::class => new IR\ExprBinop(
            $operand->op,
            bindLambdaCapturesOperand($operand->left, $meta),
            bindLambdaCapturesOperand($operand->right, $meta),
        ),
        IR\ExprPartial::class, IR\Partial::class => bindCapturedPartial($operand, $meta),
        IR\Intrinsic::class => new IR\Intrinsic(
            $operand->name,
            \array_map(static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta), $operand->args),
            $operand->srcLoc,
        ),
        IR\ListLit::class => new IR\ListLit(
            \array_map(static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta), $operand->elements),
        ),
        IR\DictMethod::class => new IR\DictMethod(
            bindLambdaCapturesOperand($operand->evidence, $meta),
            $operand->method,
        ),
        default => $operand,
    };
}

/** @param list<IR\FunctionDecl> $functions */
function foldPartialApply(array $functions, string $moduleName = ''): array
{
    $index = indexFunctions($functions, $moduleName);

    return \array_map(
        static fn (IR\FunctionDecl $function): IR\FunctionDecl => $function->withBody(
            new IR\Block(foldPartialApplyItems($function->body->items, $index)),
        ),
        $functions,
    );
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, IR\FunctionDecl> $index
 * @return list<IR\Stmt>
 */
function foldPartialApplyItems(array $items, array $index): array
{
    $out = [];

    for ($i = 0; $i < count($items); ++$i) {
        $item = $items[$i];
        $next = $items[$i + 1] ?? null;

        if ($item instanceof IR\CallValue) {
            $folded = tryFoldCallValuePartial($item);
            if ($folded !== null) {
                $out[] = $folded;
                continue;
            }
        }

        if ($item instanceof IR\Assign && $next !== null && $next instanceof IR\CallValue) {
            $fusedAssign = tryFuseAssignPartialCallValue($item, $next);
            if ($fusedAssign !== null) {
                $out[] = $fusedAssign;
                ++$i;
                continue;
            }
        }

        if ($item instanceof IR\Call && $next !== null) {
            $fused = tryFusePartialCall($item, $next, $index);
            if ($fused !== null) {
                $out[] = $fused;
                ++$i;
                continue;
            }

            if ($next instanceof IR\Ret) {
                $fusedRet = tryFusePartialRet($item, $next, $index);
                if ($fusedRet !== null) {
                    $out[] = $fusedRet;
                    ++$i;
                    continue;
                }
            }
        }

        if ($item instanceof IR\Let && $next !== null && $next instanceof IR\Ret) {
            $fusedLet = tryFuseLetPartialRet($item, $next);
            if ($fusedLet !== null) {
                $out[] = $fusedLet;
                ++$i;
                continue;
            }
        }

        $out[] = foldPartialApplyStmt($item, $index);
    }

    return $out;
}

/**
 * @return IR\Stmt|null
 */
function tryFoldCallValuePartial(IR\CallValue $callValue): ?IR\Stmt
{
    $partial = partialFromOperand($callValue->callee);
    if ($partial === null) {
        return null;
    }

    $expr = saturatePartialExpr(operandToExpr($partial), $callValue->args);
    if ($expr === null) {
        return null;
    }

    return new IR\Assign($callValue->dest, $expr);
}

/**
 * @return IR\Stmt|null
 */
function tryFuseAssignPartialCallValue(IR\Assign $assign, IR\CallValue $callValue): ?IR\Stmt
{
    if (!($callValue->callee instanceof IR\Temp) || $callValue->callee->id !== $assign->dest) {
        return null;
    }

    // Both spellings reach here: `IR\Partial` from the frontend / lambda
    // captures, `IR\ExprPartial` from a preceding fusion in this same pass.
    $partial = partialFromOperand($assign->value);
    if ($partial === null) {
        return null;
    }

    $expr = saturatePartialExpr(operandToExpr($partial), $callValue->args);
    if ($expr === null) {
        return null;
    }

    return new IR\Assign($callValue->dest, $expr);
}

/**
 * @return IR\Stmt|null
 */
function tryFuseLetPartialRet(IR\Let $let, IR\Ret $ret): ?IR\Stmt
{
    $value = partialFromOperand($let->value);
    if ($value === null) {
        return null;
    }

    $retValue = $ret->value;
    if (!($retValue instanceof IR\ExprCallValue)
        || !($retValue->callee instanceof IR\Local)
        || $retValue->callee->name !== $let->name) {
        return null;
    }

    $expr = saturatePartialExpr(operandToExpr($value), $retValue->args);
    if ($expr === null) {
        return null;
    }

    return new IR\Ret($expr);
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @return IR\Stmt|null
 */
function tryFusePartialCall(IR\Stmt $call, IR\Stmt $next, array $index): ?IR\Stmt
{
    if ($next instanceof IR\CallValue) {
        return tryFusePartialCallValue($call, $next, $index);
    }

    return null;
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @return IR\Stmt|null
 */
function tryFusePartialCallValue(IR\Stmt $call, IR\CallValue $callValue, array $index): ?IR\Stmt
{
    if (!($callValue->callee instanceof IR\Temp) || $callValue->callee->id !== $call->dest) {
        return null;
    }

    $expr = fuseCallWithPartialCallee($call, $callValue->args, $index);
    if ($expr === null) {
        $inner = resolveCallValueCalleeExpr($callValue->callee, $index);
        if ($inner !== null) {
            $expr = saturatePartialExpr($inner, $callValue->args);
        }
    }
    if ($expr === null) {
        return null;
    }

    return new IR\Assign($callValue->dest, $expr);
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @return IR\Stmt|null
 */
function tryFusePartialRet(IR\Stmt $call, IR\Ret $ret, array $index): ?IR\Stmt
{
    $value = $ret->value;
    if (!($value instanceof IR\ExprCallValue)
        || !($value->callee instanceof IR\Temp)
        || $value->callee->id !== $call->dest) {
        return null;
    }

    $expr = fuseCallWithPartialCallee($call, $value->args, $index);
    if ($expr === null) {
        $inner = resolveCallValueCalleeExpr($value->callee, $index);
        if ($inner !== null) {
            $expr = saturatePartialExpr($inner, $value->args);
        }
    }
    if ($expr === null) {
        return null;
    }

    return new IR\Ret($expr);
}

/**
 * @param list<IR\Operand> $args
 * @param array<string, IR\FunctionDecl> $index
 * @return IR\Operand|null
 */
function fuseCallWithPartialCallee(IR\Stmt $call, array $args, array $index): ?IR\Operand
{
    $provider = $index[$call->callee] ?? null;
    if ($provider !== null) {
        $partial = returnedPartialExpr($provider, $call->args);
        if ($partial !== null) {
            return saturatePartialExpr($partial, $args);
        }

        $identity = returnedIdentityArg($provider, $call->args);
        if ($identity !== null) {
            return saturatePartialExpr($identity, $args);
        }
    }

    if ($call->callee === 'id' && count($call->args) === 1) {
        return saturatePartialExpr(operandToExpr($call->args[0]), $args);
    }

    return null;
}

/** @param list<IR\Operand> $providerArgs */
function returnedPartialExpr(IR\FunctionDecl $provider, array $providerArgs): ?IR\Operand
{
    $items = $provider->body->items;
    if (count($items) !== 1 || !($items[0] instanceof IR\Ret)) {
        return null;
    }

    $value = $items[0]->value;
    if ($value instanceof IR\Partial) {
        $value = operandToExpr($value);
    } elseif (!($value instanceof IR\ExprPartial)) {
        return null;
    }

    return substituteProviderParams($value, $provider, $providerArgs);
}

/**
 * Rewrite a partial lifted out of `$provider` so that its arguments name the
 * caller's values instead of the provider's parameters.
 *
 * `bindLambdaCaptures` turns a `@λN` value into `Partial(λN, arity, [captures])`
 * whose arguments are `Local`s of the *enclosing* function — for
 * `adder n = \x -> x + n` the body becomes `ret partial λ0(n)`. Fusing that
 * return with the call site's application (`t = call adder(10)` then
 * `call_value t(5)` → `call λ0(10, 5)`) is only sound when `n` is replaced by the
 * call's own argument, since the provider's parameter does not exist there.
 *
 * Returns `null` when the arguments cannot stand in for every captured name —
 * the call's argument count does not line up with the parameters, or a capture
 * is a `let`/pattern-bound local rather than a parameter. The caller then
 * leaves the application to the runtime `__apply`, which builds the closure
 * inside the provider where the names still exist.
 *
 * @param list<IR\Operand> $providerArgs
 */
function substituteProviderParams(IR\ExprPartial $expr, IR\FunctionDecl $provider, array $providerArgs): ?IR\Operand
{
    if (count($provider->params) !== count($providerArgs)) {
        return null;
    }

    $subst = \array_combine($provider->params, $providerArgs);
    $resolved = true;

    $substitute = null;
    $substitute = static function (IR\Operand $operand) use (&$substitute, $subst, &$resolved): IR\Operand {
        if ($operand instanceof IR\Local) {
            if (\array_key_exists($operand->name, $subst)) {
                return $subst[$operand->name];
            }
            // A capture the provider cannot hand over (a `let`-bound or
            // pattern-bound local): the fusion would leak it to the caller.
            $resolved = false;

            return $operand;
        }

        return mapOperandChildren($operand, $substitute);
    };

    $out = $substitute($expr);

    return $resolved ? $out : null;
}

/**
 * @param list<IR\Operand> $providerArgs
 * @return IR\Operand|null
 */
function returnedIdentityArg(IR\FunctionDecl $provider, array $providerArgs): ?IR\Operand
{
    if (count($provider->params) !== 1 || count($providerArgs) !== 1) {
        return null;
    }

    $items = $provider->body->items;
    if (count($items) !== 1 || !($items[0] instanceof IR\Ret)) {
        return null;
    }

    $value = $items[0]->value;
    if (!($value instanceof IR\Local) || $value->name !== $provider->params[0]) {
        return null;
    }

    return operandToExpr($providerArgs[0]);
}

/** @param list<IR\Operand> $args */
function saturatePartialExpr(IR\Operand $expr, array $args): ?IR\Operand
{
    if ($expr instanceof IR\ExprPartial) {
        $all = [...$expr->args, ...$args];
        if (count($all) === $expr->arity) {
            return new IR\ExprCall($expr->fn, $all);
        }

        if (count($all) < $expr->arity) {
            return new IR\ExprPartial($expr->fn, $expr->arity, $all);
        }

        return null;
    }

    if ($expr instanceof IR\Partial) {
        return saturatePartialExpr(operandToExpr($expr), $args);
    }

    return null;
}

/** @param array<string, IR\FunctionDecl> $index */
function foldPartialApplyStmt(IR\Stmt $stmt, array $index): IR\Stmt
{
    $mapped = match ($stmt::class) {
        IR\Ret::class => new IR\Ret(foldPartialApplyExpr($stmt->value, $index)),
        IR\Assign::class => new IR\Assign($stmt->dest, foldPartialApplyOperand($stmt->value, $index)),
        IR\Let::class => new IR\Let($stmt->name, foldPartialApplyOperand($stmt->value, $index)),
        IR\Binop::class => new IR\Binop($stmt->op, foldPartialApplyOperand($stmt->left, $index), foldPartialApplyOperand($stmt->right, $index), $stmt->dest),
        IR\Call::class => new IR\Call($stmt->callee, \array_map(static fn (IR\Operand $arg): IR\Operand => foldPartialApplyOperand($arg, $index), $stmt->args), $stmt->dest, $stmt->srcLoc),
        IR\CallValue::class => new IR\CallValue(foldPartialApplyOperand($stmt->callee, $index), \array_map(static fn (IR\Operand $arg): IR\Operand => foldPartialApplyOperand($arg, $index), $stmt->args), $stmt->dest, $stmt->srcLoc),
        default => $stmt,
    };

    return mapStmtNestedBlocks(
        $mapped,
        static fn (IR\Block $block): IR\Block => new IR\Block(foldPartialApplyItems($block->items, $index)),
    );
}

/** @param array<string, IR\FunctionDecl> $index */
function foldPartialApplyExpr(IR\Operand $expr, array $index): IR\Operand
{
    if ($expr instanceof IR\ExprCallValue) {
        $fused = fuseDictAdapterApply($expr, $index);
        if ($fused !== null) {
            return $fused;
        }

        $callee = foldPartialApplyOperand($expr->callee, $index);
        $args = \array_map(static fn (IR\Operand $arg): IR\Operand => foldPartialApplyOperand($arg, $index), $expr->args);
        $inner = resolveCallValueCalleeExpr($callee, $index);
        if ($inner !== null) {
            $saturated = saturatePartialExpr($inner, $args);
            if ($saturated !== null) {
                return $saturated;
            }
        }

        return new IR\ExprCallValue($callee, $args, $expr->srcLoc);
    }

    if ($expr instanceof IR\ExprPartial) {
        return $expr;
    }

    if ($expr instanceof IR\ExprBinop) {
        return new IR\ExprBinop(
            $expr->op,
            foldPartialApplyOperand($expr->left, $index),
            foldPartialApplyOperand($expr->right, $index),
        );
    }

    if ($expr instanceof IR\ExprCall) {
        return new IR\ExprCall(
            $expr->callee,
            \array_map(static fn (IR\Operand $arg): IR\Operand => foldPartialApplyOperand($arg, $index), $expr->args),
            $expr->srcLoc,
        );
    }

    return $expr;
}

/**
 * `adapter(ev)(x)` -> `λ(ev, x)` when `adapter` is the evidence adapter for a
 * locally bound constrained function.
 *
 * A local `Num a => a -> a` (a `let`/`where` binding) is represented as a
 * lambda that takes the evidence dictionary and returns a partial of the real
 * body, so its call sites lower to an application of an application. Nothing
 * can call that directly, so it would reach the emitter as a runtime `__apply`
 * over a freshly allocated partial thunk. Substituting the adapter's own
 * argument for its parameter and saturating turns it into the direct call the
 * same shape produces for a module-level function.
 *
 * @param array<string, IR\FunctionDecl> $index
 */
function fuseDictAdapterApply(IR\ExprCallValue $apply, array $index): ?IR\Operand
{
    $adapter = $apply->callee;
    if (!($adapter instanceof IR\ExprCallValue) || !($adapter->callee instanceof IR\FnRef)) {
        return null;
    }

    $provider = $index[$adapter->callee->name] ?? null;
    if ($provider === null) {
        return null;
    }

    $partial = returnedPartialExpr($provider, $adapter->args);
    if ($partial === null) {
        return null;
    }

    return saturatePartialExpr($partial, $apply->args);
}

/** @param array<string, IR\FunctionDecl> $index */
function foldPartialApplyOperand(IR\Operand $operand, array $index): IR\Operand
{
    if ($operand instanceof IR\Partial) {
        return $operand;
    }

    if ($operand instanceof IR\ExprBinop) {
        return foldPartialApplyExpr($operand, $index);
    }

    if ($operand instanceof IR\ExprCall) {
        return foldPartialApplyExpr($operand, $index);
    }

    if ($operand instanceof IR\ExprCallValue) {
        return foldPartialApplyExpr($operand, $index);
    }

    if ($operand instanceof IR\ExprPartial) {
        return $operand;
    }

    return $operand;
}

/**
 * Canonicalize applications whose callee arity is statically known.
 *
 * Two shapes reach here from inlining and curried call sites:
 *
 *   t = call_value @f(x)                 -- known function, |x| < its arity
 *   ret call_value call_value g(x)(y)    -- curried call on a parameter
 *
 * The first is the shape {@see lowerNamedApply} already normalizes for a
 * directly named apply; it reappears whenever inlining substitutes a `FnRef`
 * into a function-valued parameter (`apply2 add 1 n` inlines to
 * `call_value @add(1)` followed by `call_value t(n)`). The second appears when
 * the callee is a parameter whose declared type fixes its arity
 * (`apply2 f x y = f x y`).
 *
 * Both are rewritten into the canonical `ExprCall` / `Partial` / `ExprPartial`
 * forms, which lets {@see foldPartialApply} fuse `t = partial f(x)` with
 * `call_value t(y)` into a direct `f(x, y)`, and lets every backend's emitter
 * resolve the application without the runtime `__apply`.
 *
 * Deliberately conservative:
 *  - only module-level function names, never `@λN`: the emitters inline simple
 *    lambdas as arrows, and a capturing lambda's `Partial` arity counts its
 *    captures, so the two would not agree;
 *  - flattening requires the declaration's surface type to account for *every*
 *    parameter, otherwise instance/context evidence slots would shift the
 *    parameter/type correspondence;
 *  - flattening never merges past the declared arity, so this pass cannot turn
 *    an exact application into an over-application (which the backends do not
 *    agree on).
 *
 * @param list<IR\FunctionDecl> $functions
 * @return list<IR\FunctionDecl>
 */
function normalizeKnownApplies(array $functions, string $moduleName = ''): array
{
    $arity = knownFunctionArity($functions, $moduleName);

    return \array_map(
        static fn (IR\FunctionDecl $function): IR\FunctionDecl => $function->withBody(
            new IR\Block(normalizeApplyItems($function->body->items, $arity, declaredParamArities($function))),
        ),
        $functions,
    );
}

/** @param list<IR\FunctionDecl> $functions @return array<string, int> */
function knownFunctionArity(array $functions, string $moduleName): array
{
    $arity = [];
    foreach ($functions as $function) {
        if (isCapturedFnName($function->name)) {
            continue;
        }
        $count = count($function->params);
        $arity[$function->name] = $count;
        // Specialize qualifies local callees as `Module::name`.
        if ($moduleName !== '') {
            $arity[$moduleName . '::' . $function->name] ??= $count;
        }
    }

    return $arity;
}

/**
 * Map each parameter to the arity of its declared function type.
 *
 * Empty unless the declared surface type covers exactly the declared
 * parameters — instance methods and context-taking functions carry evidence
 * slots the surface type does not mention.
 *
 * @return array<string, int>
 */
function declaredParamArities(IR\FunctionDecl $function): array
{
    $type = $function->type;
    while ($type instanceof Ast\TypeConstrained) {
        $type = $type->body;
    }

    $paramTypes = [];
    while ($type instanceof Ast\TypeArrow) {
        $paramTypes[] = $type->from;
        $type = $type->to;
    }

    if ($paramTypes === [] || count($paramTypes) !== count($function->params)) {
        return [];
    }

    $out = [];
    foreach ($function->params as $i => $name) {
        $arrows = functionTypeArity($paramTypes[$i]);
        if ($arrows > 0) {
            $out[$name] = $arrows;
        }
    }

    return $out;
}

function functionTypeArity(Ast\TypeNode $type): int
{
    $arrows = 0;
    while ($type instanceof Ast\TypeArrow) {
        ++$arrows;
        $type = $type->to;
    }

    return $arrows;
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, int> $arity
 * @param array<string, int> $paramArities
 * @return list<IR\Stmt>
 */
function normalizeApplyItems(array $items, array $arity, array $paramArities): array
{
    return \array_map(
        static fn (IR\Stmt $item): IR\Stmt => normalizeApplyStmt($item, $arity, $paramArities),
        $items,
    );
}

/**
 * @param array<string, int> $arity
 * @param array<string, int> $paramArities
 */
function normalizeApplyStmt(IR\Stmt $stmt, array $arity, array $paramArities): IR\Stmt
{
    $rewriteOp = normalizeApplyOperandMapper($arity, $paramArities);

    if ($stmt instanceof IR\CallValue && $stmt->callee instanceof IR\FnRef) {
        $declared = $arity[$stmt->callee->name] ?? null;
        if ($declared !== null && count($stmt->args) <= $declared) {
            $args = \array_map($rewriteOp, $stmt->args);

            return new IR\Assign(
                $stmt->dest,
                count($args) === $declared
                    ? new IR\ExprCall($stmt->callee->name, $args)
                    : new IR\Partial($stmt->callee->name, $declared, $args),
            );
        }
    }

    // `mapStmtNestedBlocks` after `rewriteStmtOperands`: the latter already
    // rebuilds match arms and loop bodies, and this re-descends into them
    // (idempotent) plus any other nested-block statement.
    return mapStmtNestedBlocks(
        rewriteStmtOperands($stmt, $rewriteOp),
        static fn (IR\Block $block): IR\Block => new IR\Block(
            normalizeApplyItems($block->items, $arity, $paramArities),
        ),
    );
}

/**
 * @param array<string, int> $arity
 * @param array<string, int> $paramArities
 * @return callable(IR\Operand): IR\Operand
 */
function normalizeApplyOperandMapper(array $arity, array $paramArities): callable
{
    $rewrite = null;
    $rewrite = static function (IR\Operand $operand) use (&$rewrite, $arity, $paramArities): IR\Operand {
        $operand = mapOperandChildren($operand, $rewrite);

        $flattened = flattenApplyChain($operand, $paramArities);
        while ($flattened !== $operand) {
            $operand = $flattened;
            $flattened = flattenApplyChain($operand, $paramArities);
        }

        if ($operand instanceof IR\ExprCallValue && $operand->callee instanceof IR\FnRef) {
            $declared = $arity[$operand->callee->name] ?? null;
            if ($declared !== null) {
                if (count($operand->args) === $declared) {
                    return new IR\ExprCall($operand->callee->name, $operand->args);
                }
                if (count($operand->args) < $declared) {
                    return new IR\ExprPartial($operand->callee->name, $declared, $operand->args);
                }
            }
        }

        return $operand;
    };

    return $rewrite;
}

/**
 * `(f x) y` -> `f x y` when `f` takes at least `|x| + |y|` arguments.
 *
 * @param array<string, int> $paramArities
 */
function flattenApplyChain(IR\Operand $operand, array $paramArities): IR\Operand
{
    if (!($operand instanceof IR\ExprCallValue) || !($operand->callee instanceof IR\ExprCallValue)) {
        return $operand;
    }

    $inner = $operand->callee;
    $base = $inner->callee;
    if (!($base instanceof IR\Local)) {
        return $operand;
    }

    $declared = $paramArities[$base->name] ?? null;
    if ($declared === null || count($inner->args) + count($operand->args) > $declared) {
        return $operand;
    }

    return new IR\ExprCallValue($base, [...$inner->args, ...$operand->args], $operand->srcLoc);
}

/**
 * Absorb the tail of a `partial` a function returns into its own parameters.
 *
 * A lifted function's *effective* arity is its captures plus its declared
 * parameters: `bindLambdaCaptures` prepends the captures at every call site and
 * the emitters emit them as leading parameters, so a caller supplies
 * `count($captures) + count($params)` arguments. Only the declared parameters
 * may be grown here -- the captures are not this function's to add -- so the
 * comparison against the partial's missing arguments has to count them, or the
 * definition ends up one parameter longer than every call site
 * (`__letrec1` called with 3 arguments, declared with 4).
 *
 * @param list<string> $captures
 */
function specializePartialReturnFunction(IR\FunctionDecl $function, array $captures = []): IR\FunctionDecl
{
    $items = $function->body->items;
    if (count($items) !== 1 || !($items[0] instanceof IR\Ret)) {
        return $function;
    }

    $partial = partialFromOperand($items[0]->value);
    if ($partial === null) {
        return $function;
    }

    $missing = $partial->arity - count($partial->args);
    if ($missing <= 0) {
        return $function;
    }

    $existing = $function->params;
    if (count($captures) + count($existing) >= $missing) {
        return $function;
    }

    $newParams = [...$existing];
    while (count($newParams) < $missing) {
        $newParams[] = freshPartialParamName($newParams);
    }

    $callArgs = [
        ...$partial->args,
        ...\array_map(static fn (string $param): IR\Operand => new IR\Local($param), \array_slice($newParams, count($existing))),
    ];

    return new IR\FunctionDecl(
        $function->name,
        $newParams,
        $function->type,
        new IR\Block([new IR\Ret(new IR\ExprCall($partial->fn, $callArgs))]),
        $function->export,
        $function->instanceMethod,
        $function->entryKind,
        $function->ioEffect,
        $function->ioStraightLine,
        $function->foreign,
        $function->ioBodyKind,
        $function->srcLoc,
    );
}

/** @return (IR\Partial|IR\ExprPartial)|null */
function partialFromOperand(IR\Operand $value): IR\Partial|IR\ExprPartial|null
{
    if ($value instanceof IR\Partial || $value instanceof IR\ExprPartial) {
        return $value;
    }

    return null;
}

/** @param list<string> $existing */
function freshPartialParamName(array $existing): string
{
    $used = \array_fill_keys($existing, true);
    $i = 1;
    while (isset($used["_p{$i}"])) {
        ++$i;
    }

    return "_p{$i}";
}

function moduleUsesPartialApply(IR\Module $module): bool
{
    $lambdaIndex = indexFunctions($module->functions);

    foreach ($module->functions as $function) {
        if (blockNeedsRuntimeApply($function->body, $lambdaIndex)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, IR\FunctionDecl> $lambdaIndex */
function blockNeedsRuntimeApply(IR\Block $block, array $lambdaIndex): bool
{
    foreach ($block->items as $item) {
        if (stmtNeedsRuntimeApply($item, $lambdaIndex)) {
            return true;
        }
    }

    return false;
}

/**
 * Walked generically over {@see stmtDirectOperands} / {@see stmtNestedBlocks}:
 * a hand-written `match` kept missing node kinds (declared `default => false`),
 * so `tail_recall` — and every other statement added later — silently answered
 * "no runtime apply" and codegen emitted `__apply` without importing it.
 *
 * @param array<string, IR\FunctionDecl> $lambdaIndex
 */
function stmtNeedsRuntimeApply(IR\Stmt $stmt, array $lambdaIndex): bool
{
    if ($stmt instanceof IR\CallValue && calleeNeedsRuntimeApply($stmt->callee, $lambdaIndex)) {
        return true;
    }

    foreach (stmtDirectOperands($stmt) as $operand) {
        if (operandExprNeedsRuntimeApply($operand, $lambdaIndex)) {
            return true;
        }
    }

    return array_any(
        stmtNestedBlocks($stmt),
        static fn (IR\Block $nested): bool => blockNeedsRuntimeApply($nested, $lambdaIndex),
    );
}

/** @param array<string, IR\FunctionDecl> $lambdaIndex */
function exprNeedsRuntimeApply(IR\Operand $expr, array $lambdaIndex): bool
{
    return match ($expr::class) {
        IR\ExprCallValue::class => calleeNeedsRuntimeApply($expr->callee, $lambdaIndex)
            || array_any(
                $expr->args,
                static fn (IR\Operand $arg): bool => operandExprNeedsRuntimeApply($arg, $lambdaIndex),
            ),
        IR\ExprBinop::class => exprNeedsRuntimeApply($expr->left, $lambdaIndex)
            || exprNeedsRuntimeApply($expr->right, $lambdaIndex),
        IR\ExprCall::class => array_any(
            $expr->args,
            static fn (IR\Operand $arg): bool => operandExprNeedsRuntimeApply($arg, $lambdaIndex),
        ),
        IR\Intrinsic::class => array_any(
            $expr->args,
            static fn (IR\Operand $arg): bool => operandExprNeedsRuntimeApply($arg, $lambdaIndex),
        ),
        IR\ListLit::class => array_any(
            $expr->elements,
            static fn (IR\Operand $arg): bool => operandExprNeedsRuntimeApply($arg, $lambdaIndex),
        ),
        // A partial is emitted as an array, so building it needs no `__apply` --
        // but its arguments do, and they are emitted.
        IR\ExprPartial::class => array_any(
            $expr->args,
            static fn (IR\Operand $arg): bool => operandExprNeedsRuntimeApply($arg, $lambdaIndex),
        ),
        default => false,
    };
}

/** @param array<string, IR\FunctionDecl> $lambdaIndex */
function operandExprNeedsRuntimeApply(IR\Operand $operand, array $lambdaIndex): bool
{
    return match ($operand::class) {
        IR\ExprCallValue::class, IR\ExprBinop::class, IR\ExprCall::class, IR\ExprPartial::class,
        IR\Intrinsic::class, IR\ListLit::class => exprNeedsRuntimeApply($operand, $lambdaIndex),
        default => false,
    };
}

/**
 * Whether applying this callee has to go through the runtime `__apply`.
 *
 * Mirrors {@see emitApplyExpr}: the emitter resolves
 * partials, inlinable arrows and known-arity `FnRef`s without the runtime and
 * falls through to `__apply` for everything else. Answering `false` here makes
 * codegen omit both the runtime require and `use function Moggi\__apply`, which
 * is a fatal `Undefined function` at run time — so the only safe `false`s are
 * the forms the emitter provably resolves itself.
 *
 * @param array<string, IR\FunctionDecl> $lambdaIndex
 */
function calleeNeedsRuntimeApply(IR\Operand $callee, array $lambdaIndex): bool
{
    return match ($callee::class) {
        IR\Partial::class, IR\ExprPartial::class => false,
        IR\FnRef::class => !(isLambdaName($callee->name)
            && isset($lambdaIndex[$callee->name])
            && isSimpleLambda($lambdaIndex[$callee->name])),
        IR\Local::class, IR\Temp::class => true,
        IR\DictMethod::class => true,
        IR\ExprCall::class => true,
        // A primop / foreign call used as a function value is emitted as an
        // opaque callable, so its application is `__apply`.
        IR\Intrinsic::class, IR\ForeignCall::class => true,
        // An application result used as a callee always goes through `__apply`, so it must be
        // reported here or codegen emits `__apply` without importing it.
        IR\ExprBinop::class, IR\ExprCallValue::class => true,
        default => false,
    };
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @return IR\Operand|null
 */
function resolveCallValueCalleeExpr(IR\Operand $callee, array $index): ?IR\Operand
{
    if ($callee instanceof IR\Partial || $callee instanceof IR\ExprPartial) {
        return operandToExpr($callee);
    }

    if (!($callee instanceof IR\ExprCall) || count($callee->args) !== 1) {
        return null;
    }

    $provider = $index[$callee->callee] ?? null;
    if ($provider !== null) {
        $identity = returnedIdentityArg($provider, $callee->args);
        if ($identity !== null) {
            return $identity;
        }
    }

    if ($callee->callee === 'id' && count($callee->args) === 1) {
        return operandToExpr($callee->args[0]);
    }

    return null;
}
