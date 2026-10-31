<?php declare(strict_types=1);

namespace Moggi\IR;

use Moggi\Syntax\Ast;

use Moggi\Modules\ImportInterface;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;

use function Moggi\Modules\actionEntryFacts;
use function Moggi\Semantics\Exhaustiveness\guardIsConstantTrue;
use function Moggi\Semantics\IntrinsicRegistry\ioPrimopRole;
use function Moggi\Semantics\IntrinsicRegistry\stPrimopRole;
use function Moggi\Semantics\IoBoundary\isIoCheckedType;
use function Moggi\Semantics\IoBoundary\isIoUnitCheckedType;

/**
 * The IO statement spine — the boundary between the checked core and the action ABI.
 *
 * Ten construct families lower from terms and refuse a site their record does not plan
 * (`Modules\CheckedTerm`). What happens inside a `do` block is not one of them: the checker decides
 * it, and this file executes the decision.
 *
 * **The two facts it reads, and where they are published.** No question about a statement is decided
 * here. What it evaluates — a value, a `case` or nested `do` to lower in place, a `let`-bound action
 * to defer, an expression whose value is the action to run — is written on its expression
 * (`Ast\PublishesIoForm::$ioForm`) by `IoEscape\analyze`, where the type and the `let`-bound actions
 * in scope are both known. Which of the four statements it *is* — a binding group, an action bound
 * for later, a run that binds, a bare evaluation — is written beside that (`$ioKind`), because the
 * form cannot answer it: `x <- e` and `let x = e` give the same form for the same `e`. This file has
 * exactly one reader for each (`ioStatementFormOf()`, `statementKind()`) and every arm dispatches on
 * the answer; the two answers are one carrier (`Ast\PublishesIoForm`), written by the one producer in
 * `semantics/io_escape.php`. There is no second classifier, no `instanceof` chain standing in
 * for either fact, and nothing here re-derives one from what a lowering attempt happened to do: the
 * `Action` form's two emissions (an action call, or a value that is the action and gets run) are
 * chosen by asking `ioActionCall()` first, before anything is emitted. The class reads that remain are
 * two child pickers — which node carries a statement's facts ({@see statementFormNode()}) and the
 * `IoAction` wrapper around a value that *is* an action ({@see actionValueNode()}) — plus the
 * narrowing each arm does once the answer is known.
 *
 * **The one thing that is genuinely the ABI's business: deferred lowering.**
 * `LowerCtx::$ioActionStore` holds an *expression* that is lowered again later, under the context of
 * the use site (`inActionBox`, `isTail`); its key is the binder identity the checker allocated, the
 * same key `variableOperand()` resolves a use by. A term states a fact about one site; a `let`-bound
 * action needs a lowering that depends on its consumer. That is why statements are still walked here
 * rather than published as an action plan: a plan would have to carry a thunk, and mirroring the
 * statement classes into one would be a carrier plus a `match` that repeats these arms.
 *
 * **What was deleted to keep it honest.** The `let`-action fold lookahead (`ioFoldHint`) and its hint
 * producer are gone — the store path answers the same IR, byte for byte. The
 * `try { lowerExpr } catch { box }` recovery is gone: nothing in `src/ir/` catches a lowering failure
 * any more, and removing it exposed the one shape only it ever lowered — a nested `do` in action
 * position (`x <- (do …)`) — which is now classified like a `case` in that position. So is the
 * post-publication syntax construction it needed: lowering no longer builds an `IoAction` around an
 * expression, because the box a bare action value needs takes that expression directly
 * (`lowerActionValueToBox()`), and the desugarer is the only place an `IoAction` is created.
 *
 * **An IO expression in argument position, and why it needs no form.** A `catch`/`finally` handler,
 * an IO-typed argument to an action call, a lifted closure — none of them is a statement, no statement
 * list owns one, so none carries a published form and none needs one: the *value* of an
 * argument-position IO expression is the action, so it is lowered as the value it is and the callee
 * decides when to run it (`lowerActionValueToBox()`). A bare `do` block there is no exception, and this
 * note no longer claims it is: a `do` in a pure position keeps its desugared action-building primops
 * (`exceptionBind#`, `exceptionPure#`, …), arrives as an `Intrinsic` operand, and runs when its
 * receiver runs it. `Exec-IO-Operand-Position` pins every shape of that on php, jvm and .NET. What the
 * operand position costs is the rule stated on the two operand forms ({@see Intrinsic},
 * {@see ForeignCall}): operands are evaluated left to right before the enclosing statement, and an
 * effectful operand is not duplicable — `Exec-Foreign-Operand-Order` pins the first half.
 *
 * **The shapes that cannot be reached, stated rather than assumed.** `lowerExpr()`'s default arm
 * refuses every class it does not know, and the `Io*` family is among them. That refusal is unreachable
 * from a legal program, and the reason is structural, not empirical: an `Io*` node is constructed only
 * by `StrictIoNormalize\normalizeIoBody()`, which is entered only for the body of a declaration whose
 * declared type returns IO (`functionReturnsIo()`), and lowering sends that same body here by the same
 * predicate (`lower()` in `lower.php`). A declaration that does not return IO keeps its pure form, so a
 * `do` inside it stays a primop chain. Both halves are pinned in the register: the normalizer is the
 * only place an `Io*` node is built, and the normalizer has one caller. The refusal stays as an
 * assertion — it is what a hand-built tree hits, not a shape a user can write.
 * *
 * **What is bounded, not open.** `tests/ir/io_spine_boundary.php` pins the statement classes, every
 * file in `src/ir/` that names one, every `catch`, the action store's key and writer, the single
 * producer of each of the two answers and the single reader of each, and the absence of any `Io*`
 * node construction under `src/ir/`; `tests/ir/io_statement_forms.php` proves the publication covers
 * a real program's statements and bodies (all five forms, all four dispositions) and that a cleared
 * answer refuses by line rather than falling back. A new read, a new recovery, a new construction or a new statement class fails the register
 * until this note and its inventory change together.
 */

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
function importedWorkerEntries(ImportInterface $interface, array $local): array
{
    return \array_diff_key($interface->workers(), $local);
}

/**
 * Allocate one worker per action entry in this module: the entries `Modules\actionEntryFacts()`
 * names, which is also the set the module publishes to its importers.
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
    foreach (actionEntryFacts($items)['workers'] as $name => $_) {
        $worker = Callables\workerName($name);
        if (isset($declared[$worker])) {
            throw new \RuntimeException("the worker name `{$worker}` collides with a declaration");
        }

        $workers[$name] = $worker;
    }

    return $workers;
}

/**
 * The expression behind an action value.
 *
 * `IoAction` is the desugarer's wrapper for "this expression's value is the action"; five readers in
 * this file unwrap it (the form arm, the value arm, the unit question, the call question and the
 * frame's location) and each used to spell the `instanceof` itself. One function states it, so a
 * wrapper that changes shape breaks here instead of in one of five copies.
 */
function actionValueNode(Ast\AstNode $expr): Ast\AstNode
{
    return $expr instanceof Ast\IoAction ? $expr->expr : $expr;
}

/**
 * The node a statement's published facts are written on.
 *
 * A statement that evaluates an expression carries the run-or-value answer, and the source
 * coordinates its frame is reported at, on that expression; a binding group carries them on itself.
 * One function states that, because two readers ask it — {@see ioStatementFormOf()} and
 * {@see ioStmtSrcLoc()} — and the producer states the same fact once, in
 * {@see \Moggi\Semantics\IoEscape\annotateIoSequence()}.
 */
function statementFormNode(Ast\AstNode $stmt): Ast\AstNode
{
    return $stmt instanceof Ast\IoExpr
        || $stmt instanceof Ast\IoBind
        || $stmt instanceof Ast\IoLetAction
        ? $stmt->expr
        : $stmt;
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

/**
 * A body, lowered by the form the checker published for it — not by its class.
 *
 * `Sequence` and `Case` answer with statements and arms of their own; anything else is one
 * expression whose value the body answers with.
 */
function lowerIoFunctionBody(Ast\AstNode $body, LowerCtx $ctx): bool
{
    $form = ioStatementFormOf($body);

    if ($form === IoStatementForm::Sequence) {
        if (! $body instanceof Ast\IoSequence) {
            throw new \RuntimeException('a body published as a sequence is a `' . $body::class . '`');
        }

        return lowerIoSequence($body->stmts, $ctx);
    }

    if ($form === IoStatementForm::Case) {
        if (! $body instanceof Ast\IoCase) {
            throw new \RuntimeException('a body published as a `case` is a `' . $body::class . '`');
        }

        return lowerIoCase($body, $ctx);
    }

    $ctx->stmtSrcLoc = ioStmtSrcLoc($body, $ctx) ?? $ctx->stmtSrcLoc;
    $result = lowerIoReturnOrAction($body, $ctx);
    if ($result === null) {
        return false;
    }

    $ctx->items[] = new Ret($result);

    return true;
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
        $ctx->stmtSrcLoc = ioStmtSrcLoc(statementFormNode($stmt), $ctx) ?? $ctx->stmtSrcLoc;
        $ok = match (statementKind($stmt, $ctx)) {
            \Moggi\IR\IoStatementKind::Let => (function () use ($stmt, $ctx): bool {
                lowerIoLetGroup($stmt, $ctx);

                return true;
            })(),
            \Moggi\IR\IoStatementKind::LetAction => (function () use ($stmt, $ctx): bool {
                $planned = plannedPattern($stmt->pattern, $ctx);
                $binder = $planned->shape === \Moggi\Modules\PatternShape::Var ? $planned->binder : null;
                if ($binder !== null) {
                    $ctx->ioActionStore[$binder] = $stmt->expr;
                }

                return true;
            })(),
            \Moggi\IR\IoStatementKind::Bind => (function () use ($stmt, $ctx): bool {
                $result = lowerIoReturnOrAction($stmt->expr, $ctx);
                if ($result === null) {
                    return false;
                }
                bindPattern(lowerPattern($stmt->pattern, $ctx), $result, $ctx);

                return true;
            })(),
            \Moggi\IR\IoStatementKind::Expr => (function () use ($stmt, $last, $ctx): bool {
                $form = ioStatementFormOf(statementFormNode($stmt));

                if (! $last && ($form === IoStatementForm::Case || $form === IoStatementForm::Sequence)) {
                    $boxed = lowerIoActionToBox($stmt->expr, $ctx);
                    $ctx->items[] = new IoRun($boxed, null, ioStmtSrcLoc($stmt->expr, $ctx));
                    $ctx->hasIoRun = true;

                    return true;
                }

                return match ($form) {
                    IoStatementForm::Sequence => lowerIoSequence($stmt->expr->stmts, $ctx),
                    IoStatementForm::Case => lowerIoCase($stmt->expr, $ctx),
                    default => (function () use ($stmt, $last, $ctx, $form): bool {
                        $result = lowerIoReturnOrAction(
                            $stmt->expr,
                            $ctx,
                            asStatement: ! $last,
                            isTail: $last,
                        );
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
        };
        if (! $ok) {
            return false;
        }
    }

    return true;
}

/** A `do` statement's binding group: the group term owns its members and their order. */
function lowerIoLetGroup(Ast\IoLet $stmt, LowerCtx $ctx): void
{
    lowerBindingGroups(plannedLetGroup($stmt, $ctx), $ctx);
}

/**
 * What a statement does with its expression — the one reader of the published disposition.
 *
 * The producer ({@see \Moggi\Semantics\IoEscape\annotateIoSequence()}) writes `ioKind` while it walks
 * the statement list, so lowering dispatches on the answer instead of on the statement's class. A
 * statement that arrives here without one is a gap in the producer, not a shape to guess at.
 */
function statementKind(Ast\AstNode $stmt, LowerCtx $ctx): \Moggi\IR\IoStatementKind
{
    return $stmt->ioKind ?? throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a `do` statement (`" . $stmt::class
        . "` at line {$stmt->line}) with no published disposition",
    );
}

/**
 * The one reader of a statement's published form.
 *
 * The producer (`IoEscape\analyze`) writes `ioForm` on every statement expression and every `do`
 * body it walks; lowering reads that field and never re-asks the classifier, so one shape cannot be
 * classified twice with two answers. A missing form is a gap in the producer, not a shape to guess
 * at — the class of bug a nested `do` in bind position was.
 */
function ioStatementFormOf(Ast\AstNode $expr): IoStatementForm
{
    if (! $expr instanceof Ast\IoStatementExpression) {
        throw new \RuntimeException(
            'the IO expression at line ' . $expr->line . ' (`' . $expr::class
            . '`) is not a `do` statement expression and carries no published form',
        );
    }

    return $expr->ioForm ?? throw new \RuntimeException(
        'the IO expression at line ' . $expr->line . ' (`' . $expr::class
        . '`) reached lowering without a published form',
    );
}

/**
 * The deferred action expression a use of an action variable names, if this use is bound to one.
 *
 * Keyed by the binder identity the checker allocated for the binding site — the same key
 * `variableOperand()` resolves a use by — so the store's answer cannot cross to a neighbour that
 * happens to spell its name the same way. This replaced a name-keyed map, the spine's last
 * spelling read.
 */
function ioDeferredActionFor(Ast\Variable $use, LowerCtx $ctx): ?Ast\AstNode
{
    $term = siteTerm($use, $ctx);
    if (! $term instanceof \Moggi\Modules\CheckedVariable || $term->binder === null) {
        return null;
    }

    return $ctx->ioActionStore[$term->binder] ?? null;
}

/**
 * The action value a use of a deferred action names, or null when the name is not one.
 *
 * A `let`-bound action is kept as the expression its binding wrote, because the lowering it needs
 * depends on the consumer. The spine serves the statement consumer (run it); this serves the
 * expression one — `lowerExpr()` asks it before the binder slot, so `idIO act` passes the action
 * `act` denotes instead of failing to find a slot a deferred binding never allocated. A call's
 * value *is* the action, so it is lowered as that value; a body is boxed, which is what an action
 * value of a `do`-block or `case` is everywhere else.
 */
function ioDeferredActionValue(Ast\Variable $use, LowerCtx $ctx): ?Operand
{
    $expr = ioDeferredActionFor($use, $ctx);
    if ($expr === null) {
        return null;
    }

    $inner = actionValueNode($expr);

    return ioStatementFormOf($expr) === IoStatementForm::Action
        ? lowerExpr($inner, $ctx)
        : lowerIoActionToBox($inner, $ctx);
}

function ioActionExprReturnsUnit(Ast\AstNode $expr): bool
{
    $expr = actionValueNode($expr);
    $type = $expr->inferredInternalType;

    return $type !== null && isIoUnitCheckedType($type);
}

/**
 * Lower an IO-typed expression to the value it produced once it ran.
 *
 * The answer comes from the form the checker published on the expression ({@see ioStatementFormOf}),
 * not from trying one lowering and falling back to another: a statement or body the producer
 * classified as `Action` is an expression whose *value* is the action, so lowering emits either the
 * ABI's action call (worker, intrinsic, foreign call, or a call the callee convention says answers
 * with an action) or the value followed by `IoRun`, chosen by asking `ioActionCall()` first. Nothing
 * here attempts an emission it may have to undo.
 *
 * `$isTail` marks the value a deferred action box answers: only the tail
 * may take the early-`Ret` shortcut. A non-tail statement in a boxed
 * sequence (every instance-method body with a `do` block, which the escape
 * analysis unconditionally boxes) must run and continue instead — returning
 * early would answer the half-built action and strand the remaining
 * statements as dead code, silently dropping their effects.
 */
function lowerIoReturnOrAction(
    Ast\AstNode $expr,
    LowerCtx $ctx,
    bool $asStatement = false,
    bool $isTail = true,
): ?Operand {
    $form = ioStatementFormOf($expr);

    if ($form === IoStatementForm::Pure) {
        if ($asStatement) {
            lowerExpr($expr->expr, $ctx);

            return new Unit();
        }

        return lowerIoValueExpr($expr->expr, $ctx);
    }

    if ($form === IoStatementForm::Deferred) {
        $inner = actionValueNode($expr);
        $deferred = $inner instanceof Ast\Variable ? ioDeferredActionFor($inner, $ctx) : null;
        if ($deferred === null) {
            throw new \RuntimeException(
                'the `do` statement at line ' . $expr->line . ' is a deferred action whose binding'
                . ' this lowering no longer holds',
            );
        }

        return lowerIoReturnOrAction($deferred, $ctx, $asStatement, $isTail);
    }

    if ($form === IoStatementForm::Action) {
        return lowerActionValue($expr, $ctx, $asStatement, $isTail);
    }

    if ($form === IoStatementForm::Case) {
        if (! $expr instanceof Ast\IoCase) {
            throw new \RuntimeException('a statement published as a `case` is a `' . $expr::class . '`');
        }
        if ($ctx->inActionBox) {
            return lowerIoCase($expr, $ctx) ? new Unit() : null;
        }

        $boxed = lowerIoActionToBox($expr, $ctx);
        $dest = $asStatement ? null : freshTemp($ctx);
        $ctx->items[] = new IoRun($boxed, $dest, ioStmtSrcLoc($expr, $ctx));
        $ctx->hasIoRun = true;

        return $dest === null ? new Unit() : new Temp($dest);
    }

    if ($form === IoStatementForm::Sequence) {
        if (! $expr instanceof Ast\IoSequence) {
            throw new \RuntimeException('a statement published as a sequence is a `' . $expr::class . '`');
        }
        if ($ctx->inActionBox) {
            return lowerIoSequence($expr->stmts, $ctx) ? new Unit() : null;
        }

        $dest = $asStatement ? null : freshTemp($ctx);
        $boxed = lowerIoActionToBox($expr, $ctx);
        $ctx->items[] = new IoRun($boxed, $dest, ioStmtSrcLoc($expr, $ctx));
        $ctx->hasIoRun = true;

        return $dest === null ? new Unit() : new Temp($dest);
    }

    throw new \RuntimeException(
        'the IO expression at line ' . $expr->line . ' (`' . $expr::class . '`) has no published form'
        . ' this file knows how to lower',
    );
}

/**
 * Lower an expression whose *value* is the action: the `Action` form, whether it arrived as a
 * published statement or as a value expression the producer never filed.
 *
 * Two emissions answer it, and which one is decided before anything is emitted: an action call the
 * ABI classifies (worker, intrinsic, foreign call, or a callee convention that answers with an
 * action) is emitted as that call; every other expression is lowered as the value it is and the
 * result is run. A variable is the third case — an action in the local environment is run where it
 * stands, or called when it is a function reference.
 */
function lowerActionValue(Ast\AstNode $expr, LowerCtx $ctx, bool $asStatement, bool $isTail): Operand
{
    $inner = actionValueNode($expr);
    $dest = ($asStatement || ioActionExprReturnsUnit($expr)) ? null : freshTemp($ctx);

    if ($inner instanceof Ast\Variable) {
        $deferred = ioDeferredActionFor($inner, $ctx);
        if ($deferred !== null) {
            return lowerIoReturnOrAction($deferred, $ctx, $asStatement, $isTail) ?? new Unit();
        }

        $envRef = variableOperand($inner, $ctx);
        if ($envRef instanceof FnRef) {
            lowerIoNullaryCall($envRef->name, $ctx, $dest, ioStmtSrcLoc($expr, $ctx, $inner), $isTail);

            return $dest === null ? new Unit() : new Temp($dest);
        }

        lowerIoRun($envRef, $ctx, $dest, ioStmtSrcLoc($expr, $ctx, $inner));

        return $dest === null ? new Unit() : new Temp($dest);
    }

    $call = ioActionCall($inner, $ctx);
    if ($call === null) {
        $value = lowerExpr($inner, $ctx);
        $ctx->items[] = new IoRun($value, $dest, ioStmtSrcLoc($expr, $ctx));
        $ctx->hasIoRun = true;

        return $dest === null ? new Unit() : new Temp($dest);
    }

    lowerActionCall($call, $inner, $ctx, $dest, $isTail);

    return $dest === null ? new Unit() : new Temp($dest);
}

function lowerIoRun(Operand $action, LowerCtx $ctx, ?int $dest, ?SrcLoc $srcLoc = null): void
{
    $ctx->hasIoRun = true;
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
    $subCtx = actionBodyCtx($expr, $ctx, $deferred);

    $result = $deferred ? lowerIoReturnOrAction($expr, $subCtx) : null;
    if ($result === null) {
        if (! lowerIoFunctionBody($expr, $subCtx)) {
            throw new \RuntimeException(\sprintf(
                'failed to lower the body of `%s` (%s at line %d): an unlowered action body would '
                . 'answer `()` and drop its effects',
                $ctx->functionName,
                $expr::class,
                $expr->line,
            ));
        }
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

/**
 * The context an action body is lowered in.
 *
 * A child of the enclosing statement's, sharing its environment, stores and caches, remembering
 * whether the body itself allocates or runs an action (the worker's `ioStraightLine` hint) and
 * whether errors raised inside it belong to the enclosing action or to this box.
 */
function actionBodyCtx(Ast\AstNode $expr, LowerCtx $ctx, bool $inBox): LowerCtx
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
    $subCtx->inActionBox = $inBox;
    $subCtx->stmtSrcLoc = ioStmtSrcLoc(actionValueNode($expr), $ctx) ?? $ctx->stmtSrcLoc;

    return $subCtx;
}

/**
 * Box the action a published statement expression denotes.
 *
 * The box is the deferred body: its statements are lowered here, and its result is the value the
 * action answers with. What that body is comes from the form the checker published on `$expr`.
 */
function lowerIoActionToBox(Ast\AstNode $expr, LowerCtx $ctx): Operand
{
    [$items, $result] = lowerActionBody($expr, $ctx, true);

    return emitActionBox($expr, $items, $result, $ctx);
}

/**
 * Box the action a *value* expression denotes — one the checker published no form for.
 *
 * This is the action ABI's own business: an expression the producer never filed as a statement or a
 * body (an IO argument, a `catch` handler, a lifted closure) is lowered as the value it is, and
 * that value is the action the box runs. Nothing is synthesised into a syntax node, and no form is
 * invented for it: the spine asks the same `Action` lowering a published `Action` follows.
 */
function lowerActionValueToBox(Ast\AstNode $expr, LowerCtx $ctx): Operand
{
    $subCtx = actionBodyCtx($expr, $ctx, true);
    $result = lowerActionValue($expr, $subCtx, false, true);

    return emitActionBox($expr, $subCtx->items, $result, $ctx);
}

/** @param list<Stmt> $items */
function emitActionBox(Ast\AstNode $expr, array $items, Operand $result, LowerCtx $ctx): Operand
{
    $dest = freshTemp($ctx);
    $ctx->hasIoAssignAction = true;
    $ctx->items[] = new IoAssignAction($dest, new Block($items), $result, ioStmtSrcLoc($expr, $ctx));

    return new Temp($dest);
}

/**
 * Emit an action call the ABI classified, with the destination it answers into.
 *
 * `$expr` is the expression the call was read from (already unwrapped from any `IoAction`), for its
 * source location and its type.
 */
function lowerActionCall(
    Intrinsic|IoCall $call,
    Ast\AstNode $expr,
    LowerCtx $ctx,
    ?int $dest,
    bool $isTail = true,
): void {
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

            return;
        }

        $answersWithAction = stPrimopRole($call->name) === 'st-to-io';
        $callDest = $answersWithAction ? freshTemp($ctx) : $dest;
        $ctx->items[] = new IoCall(
            $call->name,
            $call->args,
            $callDest,
            new IoIntrinsic($call->name),
            $call->srcLoc,
        );

        if ($answersWithAction) {
            $ctx->items[] = new IoRun(new Temp($callDest), $dest, ioStmtSrcLoc($expr, $ctx));
            $ctx->hasIoRun = true;
        }

        return;
    }

    $worker = $ctx->workers[$call->callee] ?? null;
    if ($worker !== null) {
        $ctx->items[] = new Call($worker, $call->args, $dest ?? freshTemp($ctx), $call->srcLoc);

        return;
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
    if ($call->op instanceof IoForeign || $call->op instanceof IoIntrinsic) {
        return false;
    }

    if ($call->op instanceof IoRuntime && \str_starts_with($call->op->runtime, 'foreign:')) {
        return false;
    }

    if (isset($ctx->actionReturningEntries[$call->callee])) {
        return true;
    }

    if (isset($ctx->executingEntries[$call->callee])) {
        return false;
    }

    return $expr !== null && astExprReturnsIo(actionValueNode($expr));
}

/**
 * A `case` in a `do` block, lowered from the same term a plain `case` is.
 *
 * The arms, their order and their guard clauses are the term's ({@see plannedCase()}), not the
 * node's: `IoCase` and `CaseExpr` are one family with two lowering shapes, so neither reads
 * `$alts`.
 */
function lowerIoCase(Ast\IoCase $expr, LowerCtx $ctx): bool
{
    $term = plannedCase($expr, $ctx);
    $scrutinee = lowerExpr($expr->scrutinee, $ctx);
    $arms = [];
    $guardsAlwaysHold = true;

    foreach ($term->arms as $arm) {
        $lastGuard = $arm->lastGuard();
        if ($lastGuard !== null) {
            $guardsAlwaysHold = $guardsAlwaysHold && guardIsConstantTrue($lastGuard, $ctx->trueBindings);
        }

        foreach ($arm->clauses as $clause) {
            $armCtx = newArmCtx($ctx, $clause['body']);
            $armCtx->externalFnNames = $ctx->externalFnNames;
            $armCtx->functionArity = $ctx->functionArity;
            $armCtx->ioActionStore = $ctx->ioActionStore;
            $pattern = lowerPattern($arm->pattern, $ctx);
            bindPattern($pattern, $scrutinee, $armCtx);
            $guards = [];
            if ($clause['guard'] !== null) {
                $bindings = $armCtx->items;
                $isVarPattern = $pattern instanceof PatVar;
                $armCtx->items = [];
                $guards = [lowerArmGuard($clause['guard'], $armCtx, $isVarPattern ? $bindings : [])];
                $armCtx->items = $isVarPattern ? [] : $bindings;
            }
            if (! lowerIoFunctionBody($clause['body'], $armCtx)) {
                return false;
            }
            $arms[] = new MatchArm(
                eraseNewtypesInPattern($pattern, $armCtx->newtypes),
                new Block($armCtx->items),
                $guards,
            );
        }
    }

    $ctx->items[] = new IoMatch($scrutinee, $arms, exhaustive: $term->exhaustive && $guardsAlwaysHold);

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
                    $args[] = lowerActionValueToBox($arg, $ctx);

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
        $term = plannedForeignCall($expr, $ctx);

        $args = [];
        foreach ($expr->args as $arg) {
            $args[] = lowerExpr($arg, $ctx);
        }

        return new IoCall(
            '__foreign__' . $term->name,
            $args,
            null,
            new IoForeign(foreignCallOperand($term, $args), 'foreign:' . $term->ioWrap->value),
            srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
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
            $args[] = lowerActionValueToBox($arg, $ctx);

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
