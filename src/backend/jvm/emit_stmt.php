<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


require_once __DIR__ . '/../stack/source_lines.php';

use Moggi\IR;
use function Moggi\Backend\Meta\tempReadsForFunction;
use function Moggi\Backend\Meta\returnedCall;
use function Moggi\Backend\Stack\SourceLines\statementLoc;

function emitBlock(EmitEnv $env, IR\Block $block): void
{
    $items = \array_values($block->items);
    $tempReads = tempReadsForFunction($env->tempReads, $block);

    for ($i = 0; $i < \count($items); ++$i) {
        $call = returnedCall($items[$i], $items[$i + 1] ?? null, $tempReads);
        if ($call !== null) {
            noteSrcLoc($env, statementLoc($call));
            emitCallValue($env, $call);
            emitFunctionReturn($env);
            ++$i;

            continue;
        }

        emitStmt($env, $items[$i]);
    }
}

/**
 * Note a source location in the LineNumberTable, and keep it for the frame table.
 *
 * Frames are added from the finished line table ({@see emitFunction}), because a frame row only
 * means something when the host's own debug info can report that line. A line is noted more than
 * once (a function's entry line, then its first statement; a statement's head, then an expression
 * under it) and the first note on a line is the one its frame names — so a line that the line
 * table dropped (a later location claimed its PC) has no row, and a line it kept keeps the
 * location that opened it. The .NET sink reaches the same table from its sequence points.
 */
function noteSrcLoc(EmitEnv $env, ?IR\SrcLoc $loc): void
{
    if ($loc === null || $loc->line <= 0) {
        return;
    }
    $env->c->noteLine($loc->line);
    $env->locByLine[$loc->line] ??= $loc;
}

function emitStmt(EmitEnv $env, IR\Stmt $stmt): void
{
    noteSrcLoc($env, statementLoc($stmt));
    switch ($stmt::class) {
        case IR\Ret::class:
            /** @var IR\Ret $stmt */
            emitOperand($env, $stmt->value);
            emitFunctionReturn($env);

            return;

        case IR\Assign::class:
            /** @var IR\Assign $stmt */
            emitOperand($env, $stmt->value);
            $env->c->astore($env->localSlot('t' . $stmt->dest));

            return;

        case IR\Let::class:
            /** @var IR\Let $stmt */
            emitOperand($env, $stmt->value);
            $env->c->astore($env->localSlot($stmt->name));

            return;

        case IR\Call::class:
            /** @var IR\Call $stmt */
            emitStaticCall($env, $stmt->callee, $stmt->args);
            $env->c->astore($env->localSlot('t' . $stmt->dest));

            return;

        case IR\CallValue::class:
            /** @var IR\CallValue $stmt */
            emitOperand($env, $stmt->callee);
            emitRuntimeApply($env, $stmt->args);
            $env->c->astore($env->localSlot('t' . $stmt->dest));

            return;

        case IR\IoRun::class:
            /** @var IR\IoRun $stmt */
            emitOperand($env, $stmt->action);
            $env->c->invokestatic($env->cp->methodRef('moggi/rt/RT', 'ioRun', '(Ljava/lang/Object;)Ljava/lang/Object;'), 1, true);
            if ($stmt->dest !== null) {
                $env->c->astore($env->localSlot('t' . $stmt->dest));
            } else {
                $env->c->pop_();
            }

            return;

        case IR\IoCall::class:
            /** @var IR\IoCall $stmt */
            emitIoCall($env, $stmt);

            return;

        case IR\IoAssignAction::class:
            /** @var IR\IoAssignAction $stmt */
            emitIoAssignAction($env, $stmt);

            return;

        case IR\IoMatch::class:
            /** @var IR\IoMatch $stmt */
            emitMatchIo($env, $stmt);

            return;

        case IR\IoThrow::class:
            /** @var IR\IoThrow $stmt */
            emitOperand($env, $stmt->exception);
            emitSrcLocValue($env, $stmt->srcLoc);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'throwSomeException', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            if ($stmt->dest !== null) {
                $env->c->astore($env->localSlot('t' . $stmt->dest));
            } else {
                $env->c->pop_();
            }

            return;

        case IR\IoCatch::class:
            /** @var IR\IoCatch $stmt */
            emitOperand($env, $stmt->action);
            $env->c->checkcast($env->cp->class_('moggi/rt/IO'));
            emitOperand($env, $stmt->handler);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioCatch', '(Lmoggi/rt/IO;Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            if ($stmt->dest !== null) {
                $env->c->astore($env->localSlot('t' . $stmt->dest));
            } else {
                $env->c->pop_();
            }

            return;

        case IR\IoFinally::class:
            /** @var IR\IoFinally $stmt */
            emitOperand($env, $stmt->action);
            $env->c->checkcast($env->cp->class_('moggi/rt/IO'));
            emitOperand($env, $stmt->cleanup);
            $env->c->checkcast($env->cp->class_('moggi/rt/IO'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioFinally', '(Lmoggi/rt/IO;Lmoggi/rt/IO;)Ljava/lang/Object;'),
                2,
                true,
            );
            if ($stmt->dest !== null) {
                $env->c->astore($env->localSlot('t' . $stmt->dest));
            } else {
                $env->c->pop_();
            }

            return;

        case IR\MatchReturn::class:
            /** @var IR\MatchReturn $stmt */
            emitMatchReturn($env, $stmt);

            return;

        case IR\MatchStmt::class:
            /** @var IR\MatchStmt $stmt */
            emitMatchStmt($env, $stmt);

            return;

        case IR\Loop::class:
            /** @var IR\Loop $stmt */
            emitTailLoop($env, $stmt);

            return;

        case IR\TailRecall::class:
            /** @var IR\TailRecall $stmt */
            emitTailRecall($env, $stmt);

            return;

        case IR\DictCall::class:
            /** @var IR\DictCall $stmt */
            emitOperand($env, $stmt->evidence);
            $env->c->ldc($env->cp->string_($stmt->method));
            pushObjectArray($env, $stmt->args);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'dictCall', '(Ljava/lang/Object;Ljava/lang/String;[Ljava/lang/Object;)Ljava/lang/Object;'),
                3,
                true,
            );
            $env->c->astore($env->localSlot('t' . $stmt->dest));

            return;

        default:
            throw new \RuntimeException('JVM emit: unsupported stmt ' . $stmt::class);
    }
}

/**
 * Whether control never falls out of the end of `$stmt`.
 *
 * Used to decide whether a fall-through `goto` has to be appended: emitting one
 * after an unconditional transfer produces unreachable code that the verifier
 * still demands a stack map frame for.
 */
function stmtTerminates(IR\Stmt $stmt): bool
{
    if ($stmt instanceof IR\Ret
        || $stmt instanceof IR\MatchReturn
        || $stmt instanceof IR\TailRecall
        || $stmt instanceof IR\IoThrow) {
        return true;
    }
    if ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\IoMatch) {
        $arms = $stmt->arms;
        if ($arms === []) {
            return false;
        }
        foreach ($arms as $arm) {
            if (! blockTerminates($arm->body)) {
                return false;
            }
        }

        return true;
    }

    return false;
}

function blockTerminates(IR\Block $block): bool
{
    $items = $block->items;

    return $items !== [] && stmtTerminates($items[\array_key_last($items)]);
}

/**
 * `IR\Loop` is the optimizer's self-tail-recursion rewrite: a `while (true)`
 * whose back-edges are `IR\TailRecall`. Emitted as a label + backward jump.
 */
function emitTailLoop(EmitEnv $env, IR\Loop $stmt): void
{
    $head = 'tail_loop_' . $env->freshLabelId();
    $prevHead = $env->tailLoopHead;
    $env->tailLoopHead = $head;

    $env->c->label($head);
    $env->c->noteFrame($env->frameLocals());

    emitBlock($env, $stmt->body);
    if (! blockTerminates($stmt->body)) {
        $env->c->goto_($head);
    }

    $env->tailLoopHead = $prevHead;
}

/**
 * Assign the recall's arguments to the function's parameter slots and jump back
 * to the loop head.
 *
 * Every argument is evaluated into a fresh local before any parameter slot is
 * written. A direct parameter-slot assignment would read clobbered values
 * whenever an argument mentions a parameter that an earlier argument already
 * overwrote (e.g. a two-parameter swap).
 */
function emitTailRecall(EmitEnv $env, IR\TailRecall $stmt): void
{
    $head = $env->tailLoopHead;
    $slots = $env->tailRecSlots;
    if ($head === null || \count($stmt->args) !== \count($slots)) {
        throw new \RuntimeException('JVM emit: tail_recall outside a tail loop');
    }

    $values = [];
    foreach ($stmt->args as $arg) {
        emitOperand($env, $arg);
        $slot = $env->freshLocal();
        $env->c->astore($slot);
        $values[] = $slot;
    }

    foreach ($slots as $i => $slot) {
        $env->c->aload($values[$i]);
        $env->c->astore($slot);
    }

    $env->c->goto_($head);
}

function emitFunctionReturn(EmitEnv $env): void
{
    $env->c->areturn();
}

