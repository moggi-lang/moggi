<?php declare(strict_types=1);

namespace Moggi\IR;

use Moggi\Syntax\Ast;

use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;

use function Moggi\Semantics\IntrinsicRegistry\ioPrimopRole;
use function Moggi\Semantics\IntrinsicRegistry\stPrimopRole;
use function Moggi\Semantics\IoBoundary\functionReturnsIo;
use function Moggi\Semantics\IoBoundary\isIoCheckedType;
use function Moggi\Semantics\IoBoundary\isIoUnitCheckedType;

/** @param array<int, Ast\AstNode> $items @return array<string, true> */
function actionReturningEntryNames(array $items): array
{
    $names = [];
    foreach ($items as $item) {
        if (! $item instanceof Ast\FunctionDecl || ! functionReturnsIo($item->type)) {
            continue;
        }

        if ($item->actionConvention === ActionConvention::ReturnsAction) {
            $names[$item->name] = true;
        }
    }

    return $names;
}

/**
 * Every FFI worker this module can call: a declaration that performs the host call and answers
 * with its value, so no caller runs what it returns.
 *
 * A foreign import is one wherever it is declared. An imported declaration is one exactly when its
 * published scheme returns `IO` and it is not in the interface's action-return set: under the
 * uniform convention every other `IO` result is an action, whatever the body does.
 *
 * @param array<int, Ast\AstNode> $items
 * @param array<string, mixed> $importedEnv
 * @param array<string, true> $actionReturn
 * @return array<string, true>
 */
function executingEntryNames(array $items, array $importedEnv, array $actionReturn): array
{
    $names = [];
    foreach ($items as $item) {
        if ($item instanceof Ast\FunctionDecl && $item->foreign) {
            $names[$item->name] = true;
        }
    }

    foreach ($importedEnv as $name => $scheme) {
        if (isset($actionReturn[$name]) || ! $scheme instanceof Scheme) {
            continue;
        }

        $type = $scheme->type;
        while ($type instanceof TArrow) {
            $type = $type->to;
        }

        if (isIoCheckedType($type)) {
            $names[$name] = true;
        }
    }

    return $names;
}

/**
 * The worker to call for an action entry this module did not declare.
 *
 * A run crosses a module boundary the same way it crosses a function boundary: the entry is the
 * action value and a run site calls the worker. The declaring module publishes which of its
 * entries have one, so the importer reads that set instead of deciding from the scheme.
 *
 * @param array<string, true> $local bindings this module declares itself
 * @return array<string, string> imported name => worker symbol
 */
function importedWorkerEntries(\Moggi\Modules\ImportInterface $interface, array $local): array
{
    return \array_diff_key($interface->workers, $local);
}

/**
 * Allocate one worker per action entry in this module: the entries `Modules\workerFnNames()` names,
 * which is also the set the module publishes to its importers.
 *
 * A worker keeps the entry's parameter vector, so a saturated call can be redirected without
 * changing the argument list.
 *
 * @param array<int, Ast\AstNode> $items
 * @return array<string, string>
 */
function workerEntries(array $items): array
{
    $declared = [];
    foreach ($items as $item) {
        if ($item instanceof Ast\FunctionDecl) {
            $declared[$item->name] = true;
        }
    }

    $workers = [];
    foreach (\Moggi\Modules\workerFnNames($items) as $name => $_) {
        $worker = Callables\workerName($name);
        if (isset($declared[$worker])) {
            throw new \RuntimeException("the worker name `{$worker}` collides with a declaration");
        }

        $workers[$name] = $worker;
    }

    return $workers;
}

/**
 * Source location for an IO statement frame. Prefers whichever node actually
 * carries source coordinates — wrapper nodes (e.g. Ast\IoAction) are not
 * always spanned.
 */
function ioStmtSrcLoc(Ast\AstNode $node, LowerCtx $ctx, ?Ast\AstNode $fallback = null): ?SrcLoc
{
    $pick = $node->line > 0 ? $node : $fallback;
    if ($pick === null || $pick->line <= 0) {
        return null;
    }

    return srcLocFromAst($pick, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile);
}

function lowerIoFunctionBody(Ast\AstNode $body, LowerCtx $ctx): bool
{
    return match ($body::class) {
        Ast\IoSequence::class => lowerIoSequence($body->stmts, $ctx),
        Ast\IoCase::class => lowerIoCase($body, $ctx),
        default => (function () use ($body, $ctx): bool {
            $ctx->stmtSrcLoc = ioStmtSrcLoc($body, $ctx) ?? $ctx->stmtSrcLoc;
            $result = lowerIoReturnOrAction($body, $ctx);
            if ($result === null) {
                return false;
            }

            $ctx->items[] = new Ret($result);

            return true;
        })(),
    };
}

/** @param list<Ast\AstNode> $stmts */
function lowerIoSequence(array $stmts, LowerCtx $ctx): bool
{
    if ($stmts === []) {
        return false;
    }

    $lastIndex = \count($stmts) - 1;
    for ($i = 0; $i <= $lastIndex; ++$i) {
        $stmt = $stmts[$i];
        $last = $i === $lastIndex;
        $ctx->stmtSrcLoc = ioStmtSrcLoc(
            match (true) {
                $stmt instanceof Ast\IoExpr,
                $stmt instanceof Ast\IoBind,
                $stmt instanceof Ast\IoLetAction => $stmt->expr,
                default => $stmt,
            },
            $ctx,
        ) ?? $ctx->stmtSrcLoc;
        $ok = match ($stmt::class) {
            Ast\IoLet::class => (function () use ($stmt, $ctx): bool {
                lowerBindingGroups($stmt->bindings, $ctx);

                return true;
            })(),
            Ast\IoLetAction::class => (function () use ($stmt, $stmts, &$i, $ctx): bool {
                $name = ioLetActionName($stmt->pattern);
                $next = $stmts[$i + 1] ?? null;
                if (
                    $name !== null
                    && $stmt->ioFoldHint
                    && $next instanceof Ast\IoBind
                    && ioExprReferencesVar($next->expr, $name)
                ) {
                    $result = lowerIoReturnOrAction($stmt->expr, $ctx);
                    if ($result === null) {
                        return false;
                    }
                    bindPattern(lowerPattern($next->pattern, $ctx->constructorRenames, $ctx->data), $result, $ctx);
                    ++$i;

                    return true;
                }

                if ($name !== null) {
                    $ctx->ioActionStore[$name] = $stmt->expr;
                }

                return true;
            })(),
            Ast\IoBind::class => (function () use ($stmt, $ctx): bool {
                $result = lowerIoReturnOrAction($stmt->expr, $ctx);
                if ($result === null) {
                    return false;
                }
                bindPattern(lowerPattern($stmt->pattern, $ctx->constructorRenames, $ctx->data), $result, $ctx);

                return true;
            })(),
            Ast\IoExpr::class => (function () use ($stmt, $last, $ctx): bool {
                return match ($stmt->expr::class) {
                    Ast\IoSequence::class => $last ? lowerIoSequence($stmt->expr->stmts, $ctx) : false,
                    Ast\IoCase::class => $last ? lowerIoCase($stmt->expr, $ctx) : false,
                    default => (function () use ($stmt, $last, $ctx): bool {
                        $result = lowerIoReturnOrAction($stmt->expr, $ctx, asStatement: ! $last, isTail: $last);
                        if ($result === null) {
                            return false;
                        }
                        if ($last) {
                            $ctx->items[] = new Ret($result);
                        }

                        return true;
                    })(),
                };
            })(),
            default => false,
        };
        if (! $ok) {
            return false;
        }
    }

    return true;
}

function ioLetActionName(Ast\AstNode $pattern): ?string
{
    return $pattern instanceof Ast\PatVar ? $pattern->name : null;
}

function ioExprReferencesVar(Ast\AstNode $expr, string $name): bool
{
    if ($expr instanceof Ast\IoAction) {
        $expr = $expr->expr;
    }

    return $expr instanceof Ast\Variable && $expr->name === $name;
}

function ioActionExprReturnsUnit(Ast\AstNode $expr): bool
{
    if ($expr instanceof Ast\IoAction) {
        $expr = $expr->expr;
    }

    $type = $expr->inferredInternalType;

    return $type !== null && isIoUnitCheckedType($type);
}

/**
 * Lower an IO-typed expression to the value it produced once it ran.
 *
 * When the callee is unknown the lowering cannot tell whether it came back with an action to run or
 * with its already executed result — which is what `IoRun` accepts.
 *
 * `$isTail` marks the value a deferred action box answers: only the tail
 * may take the early-`Ret` shortcut. A non-tail statement in a boxed
 * sequence (every instance-method body with a `do` block, which the escape
 * analysis unconditionally boxes) must run and continue instead — returning
 * early would answer the half-built action and strand the remaining
 * statements as dead code, silently dropping their effects.
 */
function lowerIoReturnOrAction(Ast\AstNode $expr, LowerCtx $ctx, bool $asStatement = false, bool $isTail = true): ?Operand
{
    if ($expr instanceof Ast\IoPure) {
        if ($asStatement) {
            lowerExpr($expr->expr, $ctx);

            return new Unit();
        }

        return lowerIoValueExpr($expr->expr, $ctx);
    }

    if ($expr instanceof Ast\IoAction) {
        $inner = $expr->expr;
        if ($inner instanceof Ast\Variable) {
            $name = $inner->name;
            if (isset($ctx->ioActionStore[$name])) {
                return lowerIoReturnOrAction($ctx->ioActionStore[$name], $ctx, $asStatement);
            }

            $envRef = $ctx->env[$name] ?? null;
            if ($envRef instanceof FnRef) {
                $dest = ($asStatement || ioActionExprReturnsUnit($expr)) ? null : freshTemp($ctx);
                lowerIoNullaryCall($name, $ctx, $dest, ioStmtSrcLoc($expr, $ctx, $inner), $isTail);

                return $dest === null ? new Unit() : new Temp($dest);
            }

            $dest = ($asStatement || ioActionExprReturnsUnit($expr)) ? null : freshTemp($ctx);
            lowerIoRunVariable($name, $ctx, $dest, ioStmtSrcLoc($expr, $ctx, $inner));

            return $dest === null ? new Unit() : new Temp($dest);
        }
    }

    if ($expr instanceof Ast\IoCase) {
        if ($ctx->inActionBox) {
            return lowerIoCase($expr, $ctx) ? new Unit() : null;
        }

        $boxed = lowerIoActionToBox($expr, $ctx);
        $dest = $asStatement ? null : freshTemp($ctx);
        $ctx->items[] = new IoRun($boxed, $dest, ioStmtSrcLoc($expr, $ctx));
        $ctx->hasIoRun = true;

        return $dest === null ? new Unit() : new Temp($dest);
    }

    $dest = ($asStatement || ioActionExprReturnsUnit($expr)) ? null : freshTemp($ctx);
    if (! lowerIoAction($expr, $ctx, $dest, $isTail)) {
        $inner = $expr instanceof Ast\IoAction ? $expr->expr : $expr;

        try {
            $value = lowerExpr($inner, $ctx);
        } catch (\Throwable) {
            if ($ctx->inActionBox) {
                return null;
            }

            $value = lowerIoActionToBox($expr, $ctx);
        }

        $ctx->items[] = new IoRun($value, $dest, ioStmtSrcLoc($expr, $ctx));
        $ctx->hasIoRun = true;

        return $dest === null ? new Unit() : new Temp($dest);
    }

    return $dest === null ? new Unit() : new Temp($dest);
}

function lowerIoRunVariable(string $name, LowerCtx $ctx, ?int $dest, ?SrcLoc $srcLoc = null): void
{
    $ctx->hasIoRun = true;
    $action = $ctx->env[$name] ?? new Local($name);
    $ctx->items[] = new IoRun($action, $dest, $ctx->stmtSrcLoc ?? $srcLoc);
}

/**
 * The body of an action: the statements it runs and the value it answers with.
 *
 * `deferred` says whose body it is, and the two are not interchangeable. A box body answers with
 * the *value* its action produces, so a nested action stays deferred (one run peels one layer). A
 * worker body *runs* under the action, so a nested action is executed where it stands — lowering a
 * worker as if it were a box answers with an un-run box, which is a wrong value, not a slow one.
 *
 * @return array{list<Stmt>, Operand, bool} the statements, the answered value, and whether the body
 *         itself allocates or runs an action (the worker's `ioStraightLine` hint)
 */
function lowerActionBody(Ast\AstNode $expr, LowerCtx $ctx, bool $deferred): array
{
    $subCtx = newCtx([], $ctx->state, $ctx->data, $ctx->newtypes, $ctx);
    $subCtx->env = $ctx->env;
    $subCtx->externalFnNames = $ctx->externalFnNames;
    $subCtx->functionArity = $ctx->functionArity;
    $subCtx->workers = $ctx->workers;
    $subCtx->executingEntries = $ctx->executingEntries;
    $subCtx->ioActionStore = $ctx->ioActionStore;
    $subCtx->hasIoRun = false;
    $subCtx->hasIoAssignAction = false;
    $subCtx->inActionBox = $deferred;
    $subCtx->stmtSrcLoc = ioStmtSrcLoc($expr instanceof Ast\IoAction ? $expr->expr : $expr, $ctx)
        ?? $ctx->stmtSrcLoc;

    $result = $deferred ? lowerIoReturnOrAction($expr, $subCtx) : null;
    if ($result === null) {
        lowerIoFunctionBody($expr, $subCtx);
        $result = new Unit();
    }

    $items = $subCtx->items;
    if ($items !== []) {
        $last = $items[\count($items) - 1];
        if ($last instanceof Ret) {
            \array_pop($items);
            $result = $last->value;
        }
    }

    return [$items, $result, $subCtx->hasIoRun || $subCtx->hasIoAssignAction];
}

function lowerIoActionToBox(Ast\AstNode $expr, LowerCtx $ctx): Operand
{
    [$items, $result] = lowerActionBody($expr, $ctx, true);

    $dest = freshTemp($ctx);
    $ctx->hasIoAssignAction = true;
    $ctx->items[] = new IoAssignAction($dest, new Block($items), $result, ioStmtSrcLoc($expr, $ctx));

    return new Temp($dest);
}

function lowerIoAction(Ast\AstNode $expr, LowerCtx $ctx, ?int $dest, bool $isTail = true): bool
{
    if ($expr instanceof Ast\IoAction) {
        $inner = $expr->expr;
        if ($inner instanceof Ast\Variable) {
            $name = $inner->name;
            if (isset($ctx->ioActionStore[$name])) {
                return lowerIoAction($ctx->ioActionStore[$name], $ctx, $dest, $isTail);
            }

            $envRef = $ctx->env[$name] ?? null;
            if ($envRef instanceof FnRef) {
                lowerIoNullaryCall($name, $ctx, $dest, ioStmtSrcLoc($inner, $ctx), $isTail);

                return true;
            }

            lowerIoRunVariable($name, $ctx, $dest, ioStmtSrcLoc($inner, $ctx));

            return true;
        }

        $expr = $inner;
    }

    $call = ioActionCall($expr, $ctx);
    if ($call === null) {
        return false;
    }

    if ($call instanceof Intrinsic) {
        $ioConstruct = match (ioPrimopRole($call->name)) {
            'io-throw' => new IoThrow(
                $call->args[0] ?? new Unit(),
                $dest,
                $call->srcLoc,
            ),
            'io-catch' => new IoCatch(
                $call->args[0] ?? new Unit(),
                $call->args[1] ?? new Unit(),
                $dest,
            ),
            'io-finally' => new IoFinally(
                $call->args[0] ?? new Unit(),
                $call->args[1] ?? new Unit(),
                $dest,
            ),
            default => null,
        };
        if ($ioConstruct !== null) {
            $ctx->items[] = $ioConstruct;

            return true;
        }

        $answersWithAction = stPrimopRole($call->name) === 'st-to-io';
        $callDest = $answersWithAction ? freshTemp($ctx) : $dest;
        $ctx->items[] = new IoCall(
            $call->name,
            $call->args,
            $callDest,
            intrinsic: $call->name,
            srcLoc: $call->srcLoc,
        );

        if ($answersWithAction) {
            $ctx->items[] = new IoRun(new Temp($callDest), $dest, ioStmtSrcLoc($expr, $ctx));
            $ctx->hasIoRun = true;
        }

        return true;
    }

    $worker = $ctx->workers[$call->callee] ?? null;
    if ($worker !== null) {
        $ctx->items[] = new Call($worker, $call->args, $dest ?? freshTemp($ctx), $call->srcLoc);

        return true;
    }

    /** @var IoCall $call */
    $call->dest = $dest;
    $ctx->items[] = $call;

    if (callYieldsAction($call, $ctx, $expr)) {
        $tmp = freshTemp($ctx);
        $call->dest = $tmp;
        $ctx->items[] = new IoRun(new Temp($tmp), $dest, ioStmtSrcLoc($expr, $ctx));
        $ctx->hasIoRun = true;
    }

    return true;
}

/**
 * A nullary call to an IO function, `x <- getContents`. The callee is either a
 * function that returns its result (`getLine`) or one whose body is a deferred
 * action (`getContents`, `isEOF`) — the second must be run where the action is
 * used, which is the same distinction the apply path makes below.
 *
 * Returns true when the call was run into `$dest` and the caller should hand
 * back `$dest`; false for the plain call.
 */
function lowerIoNullaryCall(string $name, LowerCtx $ctx, ?int $dest, ?SrcLoc $srcLoc, bool $isTail = true): bool
{
    $worker = $ctx->workers[$name] ?? null;
    if ($worker !== null) {
        $ctx->items[] = new Call($worker, [], $dest ?? freshTemp($ctx), $srcLoc);

        return true;
    }

    $call = new IoCall($name, [], null, srcLoc: $srcLoc);
    if (! callYieldsAction($call, $ctx)) {
        $call->dest = $dest;
        $ctx->items[] = $call;

        return false;
    }

    $tmp = freshTemp($ctx);
    $call->dest = $tmp;
    $ctx->items[] = $call;
    $ctx->items[] = new IoRun(new Temp($tmp), $dest, $srcLoc ?? $ctx->stmtSrcLoc);
    $ctx->hasIoRun = true;

    return true;
}

function callYieldsAction(IoCall $call, LowerCtx $ctx, ?Ast\AstNode $expr = null): bool
{
    if ($call->foreign !== null || $call->intrinsic !== null) {
        return false;
    }

    if (\is_string($call->runtime) && \str_starts_with($call->runtime, 'foreign:')) {
        return false;
    }

    if (isset($ctx->actionReturningEntries[$call->callee])) {
        return true;
    }

    if (isset($ctx->executingEntries[$call->callee])) {
        return false;
    }

    return $expr !== null
        && astExprReturnsIo($expr instanceof Ast\IoAction ? $expr->expr : $expr);
}

function lowerIoCase(Ast\IoCase $expr, LowerCtx $ctx): bool
{
    $scrutinee = lowerExpr($expr->scrutinee, $ctx);
    $arms = [];
    $guardsAlwaysHold = true;

    foreach ($expr->alts as $alt) {
        if ($alt->body instanceof Ast\GuardsExpr) {
            $guardsAlwaysHold = $guardsAlwaysHold && guardsExhaustive($alt->body, $ctx);
            $clauses = \array_map(
                static fn (Ast\Guarded $guarded): array => [$guarded->guard, $guarded->body],
                $alt->body->clauses,
            );
        } else {
            $clauses = [[null, $alt->body]];
        }

        foreach ($clauses as [$guard, $body]) {
            $armCtx = newArmCtx($ctx, $body);
            $armCtx->externalFnNames = $ctx->externalFnNames;
            $armCtx->functionArity = $ctx->functionArity;
            $armCtx->ioActionStore = $ctx->ioActionStore;
            $pattern = lowerPattern($alt->pattern, $ctx->constructorRenames, $ctx->data);
            bindPattern($pattern, $scrutinee, $armCtx);
            $guards = [];
            if ($guard !== null) {
                $bindings = $armCtx->items;
                $isVarPattern = $alt->pattern instanceof Ast\PatVar;
                $armCtx->items = [];
                $guards = [lowerArmGuard($guard, $armCtx, $isVarPattern ? $bindings : [])];
                $armCtx->items = $isVarPattern ? [] : $bindings;
            }
            if (! lowerIoFunctionBody($body, $armCtx)) {
                return false;
            }
            $arms[] = new MatchArm(
                eraseNewtypesInPattern($pattern, $armCtx->newtypes),
                new Block($armCtx->items),
                $guards,
            );
        }
    }

    $ctx->items[] = new IoMatch($scrutinee, $arms, exhaustive: $expr->exhaustive && $guardsAlwaysHold);

    return true;
}

/**
 * Intermediate call description produced while lowering an IO action.
 *
 * @return Intrinsic|IoCall|null
 */
function ioActionCall(Ast\AstNode $expr, LowerCtx $ctx): Intrinsic|IoCall|null
{
    while ($expr instanceof Ast\TypeAsc) {
        $expr = $expr->expr;
    }

    if ($expr instanceof Ast\IntrinsicCall) {
        $role = ioPrimopRole($expr->name);
        if ($role === 'io-catch' || $role === 'io-finally') {
            $args = [];
            foreach ($expr->args as $i => $arg) {
                $needsIoBox = $i === 0
                    || ($role === 'io-finally' && $i === 1);
                if ($needsIoBox) {
                    $args[] = lowerIoActionToBox(
                        $arg instanceof Ast\IoAction ? $arg : new Ast\IoAction($arg),
                        $ctx,
                    );

                    continue;
                }
                $args[] = lowerExpr($arg, $ctx);
            }

            return new Intrinsic(
                $expr->name,
                $args,
                srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
            );
        }

        $args = [];
        foreach ($expr->args as $arg) {
            $args[] = lowerExpr($arg, $ctx);
        }

        return new Intrinsic(
            $expr->name,
            $args,
            srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
        );
    }

    if ($expr instanceof Ast\ForeignCall) {
        $args = [];
        foreach ($expr->args as $arg) {
            $args[] = lowerExpr($arg, $ctx);
        }

        $foreign = lowerForeignCall($expr, $ctx);

        return new IoCall(
            '__foreign__' . $expr->name,
            $args,
            null,
            foreign: $foreign,
            runtime: 'foreign:' . $expr->ioWrap->value,
            srcLoc: srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
        );
    }

    if ($expr instanceof Ast\Variable) {
        return new IoCall(
            $expr->name,
            [],
            null,
            srcLoc: srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
        );
    }

    $parts = flattenApply($expr);
    $callee = resolveIoActionCalleeName($parts['function'], $ctx);
    if ($callee === null) {
        return null;
    }

    $emitCallee = ioActionEmitCalleeName($parts['function'], $ctx) ?? $callee;

    $knownArity = $ctx->functionArity[$callee]
        ?? ($emitCallee !== $callee ? ($ctx->functionArity[$emitCallee] ?? null) : null);
    if ($knownArity !== null && \count($parts['args']) !== $knownArity) {
        return null;
    }

    $args = [];
    foreach ($parts['args'] as $arg) {
        if (astExprReturnsIo($arg)) {
            $args[] = lowerIoActionToBox(
                $arg instanceof Ast\IoAction ? $arg : new Ast\IoAction($arg),
                $ctx,
            );

            continue;
        }

        $args[] = lowerExpr($arg, $ctx);
    }

    return new IoCall(
        $emitCallee,
        $args,
        null,
        srcLoc: srcLocFromAst($parts['function'], $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
    );
}

function resolveIoActionCalleeName(Ast\AstNode $fnExpr, LowerCtx $ctx): ?string
{
    $base = $fnExpr;
    while ($base instanceof Ast\Apply) {
        $base = $base->function;
    }

    if ($base instanceof Ast\Variable) {
        $letFn = resolveLetBoundFnName($base->name, $ctx);
        if ($letFn !== null) {
            return $letFn;
        }

        $envRef = $ctx->env[$base->name] ?? null;
        if ($envRef instanceof Local || $envRef instanceof Temp) {
            return null;
        }

        return $base->name;
    }

    return calleeName($fnExpr);
}

function resolveLetBoundFnName(string $name, LowerCtx $ctx): ?string
{
    foreach (\array_reverse($ctx->items) as $item) {
        if (! $item instanceof Let || $item->name !== $name) {
            continue;
        }

        if ($item->value instanceof FnRef) {
            return $item->value->name;
        }

        return null;
    }

    return null;
}

function ioActionEmitCalleeName(Ast\AstNode $fnExpr, LowerCtx $ctx): ?string
{
    $name = calleeName($fnExpr);
    if ($name !== null) {
        return $name;
    }

    $base = $fnExpr;
    while ($base instanceof Ast\Apply) {
        $base = $base->function;
    }

    return $base instanceof Ast\Variable ? $base->name : null;
}

/** @return array{function: Ast\AstNode, args: list<Ast\AstNode>} */
function flattenApply(Ast\AstNode $expr): array
{
    $args = [];
    while ($expr instanceof Ast\Apply) {
        \array_unshift($args, $expr->argument);
        $expr = $expr->function;
    }

    return ['function' => $expr, 'args' => $args];
}

function calleeName(Ast\AstNode $expr): ?string
{
    return match ($expr::class) {
        Ast\Variable::class => $expr->name,
        Ast\QualifiedRef::class => $expr->backendResolved ?? $expr->name,
        default => null,
    };
}
