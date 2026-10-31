<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


require_once __DIR__ . '/../stack/source_lines.php';
require_once __DIR__ . '/../managed/block.php';
require_once __DIR__ . '/../managed/stmt.php';

use Moggi\IR;
use function Moggi\Backend\Managed\emitBlock as emitManagedBlock;
use function Moggi\Backend\Managed\emitManagedStmt;
use function Moggi\Backend\Managed\stmtTerminates as managedStmtTerminates;
use function Moggi\Backend\Managed\blockTerminates as managedBlockTerminates;

function emitBlock(EmitEnv $env, IR\Block $block): void
{
    emitManagedBlock(
        $env,
        $block,
        static function (?IR\SrcLoc $loc) use ($env): void {
            noteSrcLoc($env, $loc);
        },
        static function (IR\Stmt $call) use ($env): void {
            emitCallValue($env, $call);
        },
        static function (IR\Stmt $stmt) use ($env): void {
            emitStmt($env, $stmt);
        },
        static function () use ($env): void {
            emitFunctionReturn($env);
        },
    );
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
    emitManagedStmt($env, $stmt);
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
    return managedStmtTerminates($stmt);
}

function blockTerminates(IR\Block $block): bool
{
    return managedBlockTerminates($block);
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
