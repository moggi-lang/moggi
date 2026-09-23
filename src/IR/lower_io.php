<?php declare(strict_types=1);

namespace Moggi\IR;

use Moggi\Syntax\Ast;

use function Moggi\Semantics\IoBoundary\isIoUnitType;

/** @param array<int, Ast\AstNode> $items @return array<string, true> */
function ioActionReturnFnNames(array $items): array
{
    $names = [];
    foreach ($items as $item) {
        if ($item instanceof Ast\FunctionDecl && $item->ioBodyKind === IoBodyKind::ActionReturn) {
            $names[$item->name] = true;
        }
    }

    return $names;
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

    $lastIndex = count($stmts) - 1;
    for ($i = 0; $i <= $lastIndex; ++$i) {
        $stmt = $stmts[$i];
        $last = $i === $lastIndex;
        // Each `do` statement is one frame; the calls inside it are operands of
        // that statement, not frames of their own.
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
                foreach ($stmt->bindings as $binding) {
                    bindPattern(
                        lowerPattern($binding->pattern, $ctx->constructorRenames, $ctx->data),
                        lowerExpr($binding->value, $ctx),
                        $ctx,
                    );
                }

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
                        $result = lowerIoReturnOrAction($stmt->expr, $ctx, asStatement: !$last);
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
        if (!$ok) {
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

    $type = $expr->inferredType;

    return $type !== null && isIoUnitType($type);
}

/**
 * Lower an IO-typed expression to the value it produced once it ran.
 *
 * When the callee is unknown the lowering cannot tell whether it came back with an action to run or
 * with its already executed result — which is what `IoRun` accepts.
 */
function lowerIoReturnOrAction(Ast\AstNode $expr, LowerCtx $ctx, bool $asStatement = false): ?Operand
{
    if ($expr instanceof Ast\IoPure) {
        if ($asStatement) {
            lowerExpr($expr->expr, $ctx);

            return new Unit();
        }

        // `pure <action>` is an IO-typed *value* (`pure (putStrLn "x")`,
        // `pure getLine`): the action runs where the value is run, not where it
        // is written. Lowering it as a pure expression emits the call on the
        // spot and runs the effect early — `nested = do putStrLn "outer";
        // pure (putStrLn "inner")` printed "inner" before its caller's next
        // statement. Boxing the value is what a tuple or list element of IO
        // type already does.
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
                lowerIoNullaryCall($name, $ctx, $dest, ioStmtSrcLoc($expr, $ctx, $inner));

                return $dest === null ? new Unit() : new Temp($dest);
            }

            $dest = ($asStatement || ioActionExprReturnsUnit($expr)) ? null : freshTemp($ctx);
            lowerIoRunVariable($name, $ctx, $dest, ioStmtSrcLoc($expr, $ctx, $inner));

            return $dest === null ? new Unit() : new Temp($dest);
        }
    }

    // A match's arms leave through `Ret`, so a match is only an action in tail position: anywhere
    // its value is needed it is boxed and run, exactly as the statement path below does. Building
    // that box calls this function, so inside one the match is the body itself.
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
    if (!lowerIoAction($expr, $ctx, $dest)) {
        // Higher-order applies (e.g. `k x` where `k` is a parameter) are not known IO callees:
        // lower as an ordinary expression, whose value is either the action the callee returned
        // or, when it ran the effect itself, its result.
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

function lowerIoActionToBox(Ast\AstNode $expr, LowerCtx $ctx): Operand
{
    $subCtx = newCtx([], $ctx->state, $ctx->data, $ctx->newtypes, $ctx);
    $subCtx->env = $ctx->env;
    $subCtx->externalFnNames = $ctx->externalFnNames;
    $subCtx->functionArity = $ctx->functionArity;
    $subCtx->ioActionStore = $ctx->ioActionStore;
    $subCtx->hasIoRun = false;
    $subCtx->hasIoAssignAction = false;
    $subCtx->inActionBox = true;
    $subCtx->stmtSrcLoc = ioStmtSrcLoc($expr instanceof Ast\IoAction ? $expr->expr : $expr, $ctx)
        ?? $ctx->stmtSrcLoc;

    $result = lowerIoReturnOrAction($expr, $subCtx);
    if ($result === null) {
        lowerIoFunctionBody($expr, $subCtx);
        $result = new Unit();
    }

    // lowerIoSequence ends with Ret; peel it into the box result so codegen
    // emits a single return (and can capture locals from that operand).
    $items = $subCtx->items;
    if ($items !== []) {
        $last = $items[count($items) - 1];
        if ($last instanceof Ret) {
            array_pop($items);
            $result = $last->value;
        }
    }

    $dest = freshTemp($ctx);
    $ctx->hasIoAssignAction = true;
    $ctx->items[] = new IoAssignAction($dest, new Block($items), $result, ioStmtSrcLoc($expr, $ctx));

    return new Temp($dest);
}

function lowerIoAction(Ast\AstNode $expr, LowerCtx $ctx, ?int $dest): bool
{
    if ($expr instanceof Ast\IoAction) {
        $inner = $expr->expr;
        if ($inner instanceof Ast\Variable) {
            $name = $inner->name;
            if (isset($ctx->ioActionStore[$name])) {
                return lowerIoAction($ctx->ioActionStore[$name], $ctx, $dest);
            }

            $envRef = $ctx->env[$name] ?? null;
            if ($envRef instanceof FnRef) {
                lowerIoNullaryCall($name, $ctx, $dest, ioStmtSrcLoc($inner, $ctx));

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
        if ($call->name === 'exceptionThrowIo#') {
            $ctx->items[] = new IoThrow(
                $call->args[0] ?? new Unit(),
                $dest,
                $call->srcLoc,
            );

            return true;
        }
        if ($call->name === 'exceptionCatch#') {
            $ctx->items[] = new IoCatch(
                $call->args[0] ?? new Unit(),
                $call->args[1] ?? new Unit(),
                $dest,
            );

            return true;
        }
        if ($call->name === 'exceptionFinally#') {
            $ctx->items[] = new IoFinally(
                $call->args[0] ?? new Unit(),
                $call->args[1] ?? new Unit(),
                $dest,
            );

            return true;
        }

        // Effectful IO intrinsics (platform_*, error, …): run as IoCall so
        // Unit effects mid-sequence are not turned into Ret.
        $ctx->items[] = new IoCall(
            $call->name,
            $call->args,
            $dest,
            intrinsic: $call->name,
            srcLoc: $call->srcLoc,
        );

        return true;
    }

    /** @var IoCall $call */
    $call->dest = $dest;
    $ctx->items[] = $call;

    if (ioCallNeedsRunBoxedResult($call, $ctx)) {
        $tmp = freshTemp($ctx);
        $call->dest = $tmp;
        if ($ctx->inActionBox && $dest === null) {
            // First-class IO value: keep the ActionReturn box, do not run it.
            $ctx->items[] = new Ret(new Temp($tmp));
        } elseif ($dest === null) {
            $ctx->items[] = new IoRun(new Temp($tmp), null, ioStmtSrcLoc($expr, $ctx));
            $ctx->hasIoRun = true;
        } else {
            $ctx->items[] = new IoRun(new Temp($tmp), $dest, ioStmtSrcLoc($expr, $ctx));
            $ctx->hasIoRun = true;
        }
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
function lowerIoNullaryCall(string $name, LowerCtx $ctx, ?int $dest, ?SrcLoc $srcLoc): bool
{
    $call = new IoCall($name, [], null, srcLoc: $srcLoc);
    if (!ioCallNeedsRunBoxedResult($call, $ctx)) {
        $call->dest = $dest;
        $ctx->items[] = $call;

        return false;
    }

    $tmp = freshTemp($ctx);
    $call->dest = $tmp;
    $ctx->items[] = $call;
    if ($ctx->inActionBox && $dest === null) {
        // First-class IO value: keep the ActionReturn box, do not run it.
        $ctx->items[] = new Ret(new Temp($tmp));

        return true;
    }

    $ctx->items[] = new IoRun(new Temp($tmp), $dest, $srcLoc ?? $ctx->stmtSrcLoc);
    $ctx->hasIoRun = true;

    return true;
}

function ioCallNeedsRunBoxedResult(IoCall $call, LowerCtx $ctx): bool
{
    if ($call->foreign !== null || $call->intrinsic !== null) {
        return false;
    }

    $runtime = $call->runtime;
    if (\is_string($runtime) && (str_starts_with($runtime, 'foreign:') || \in_array($runtime, ['stdout', 'stdout_line'], true))) {
        return false;
    }

    if (isset($ctx->ioActionReturnFns[$call->callee])) {
        return true;
    }

    // Polymorphic Monad helpers return boxed IO (their bodies are not
    // IO-normalized). Calling them as IO statements must run the box.
    return \in_array($call->callee, boxedIoMonadHelperNames(), true);
}

/** @return list<string> */
function boxedIoMonadHelperNames(): array
{
    return ['traverse_', 'for_', 'sequenceA_', 'when', 'unless', 'void'];
}

function lowerIoCase(Ast\IoCase $expr, LowerCtx $ctx): bool
{
    $scrutinee = lowerExpr($expr->scrutinee, $ctx);
    $arms = [];
    // A guarded alternative whose guards all fail is no more taken than one
    // whose pattern failed, so the match stays fallible while a guard can fail.
    $guardsAlwaysHold = true;

    foreach ($expr->alts as $alt) {
        // A guarded alternative contributes one arm per guarded clause: the
        // alternative's pattern plus that guard as the arm's condition, so a
        // guard that does not hold tries the next alternative instead of ending
        // the match.
        if ($alt->body instanceof Ast\GuardsExpr) {
            $guardsAlwaysHold = $guardsAlwaysHold && guardsExhaustive($alt->body);
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
                // A variable pattern is bound by a statement of its own, which
                // the guard reads and therefore runs with the guard; every other
                // pattern's binders are bound by the pattern test itself.
                $bindings = $armCtx->items;
                $isVarPattern = $alt->pattern instanceof Ast\PatVar;
                $armCtx->items = [];
                $guards = [lowerArmGuard($guard, $armCtx, $isVarPattern ? $bindings : [])];
                $armCtx->items = $isVarPattern ? [] : $bindings;
            }
            if (!lowerIoFunctionBody($body, $armCtx)) {
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
    // `(action :: IO a)` is the action — the annotation says nothing about how it runs — so
    // the wrapper must not lower the inner expression as a pure value.
    while ($expr instanceof Ast\TypeAsc) {
        $expr = $expr->expr;
    }

    if ($expr instanceof Ast\IntrinsicCall) {
        if ($expr->name === 'exceptionCatch#' || $expr->name === 'exceptionFinally#') {
            $args = [];
            foreach ($expr->args as $i => $arg) {
                $needsIoBox = $i === 0
                    || ($expr->name === 'exceptionFinally#' && $i === 1);
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

    $args = [];
    foreach ($parts['args'] as $arg) {
        if (astExprReturnsIo($arg)) {
            // Functions that take `IO a` need the boxed action, not its result.
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
        // Higher-order local (parameter / let-bound function value): not a
        // known top-level IO callee — fall back to ordinary apply lowering.
        if ($envRef instanceof Local || $envRef instanceof Temp) {
            return null;
        }

        return $base->name;
    }

    return calleeName($fnExpr);
}

function resolveLetBoundFnName(string $name, LowerCtx $ctx): ?string
{
    foreach (array_reverse($ctx->items) as $item) {
        if (!$item instanceof Let || $item->name !== $name) {
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
        array_unshift($args, $expr->argument);
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
