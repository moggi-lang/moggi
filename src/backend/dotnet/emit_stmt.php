<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


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
            $env->m->emitLine($loc);
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

/** A call whose value is on the stack, without storing it in its destination temp. */
function emitStmt(EmitEnv $env, IR\Stmt $stmt): void
{
    emitManagedStmt($env, $stmt);
}

/**
 * Whether control never falls out of the end of `$stmt`, so no fall-through
 * `br` must be appended after it.
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
 * `IR\Loop` is the optimizer's self-tail-recursion rewrite: an endless block
 * whose back-edges are `IR\TailRecall`. Emitted as a label plus `br`.
 */
function emitTailLoop(EmitEnv $env, IR\Loop $stmt): void
{
    $head = 'tail_loop_' . $env->freshLabelId();
    $prevHead = $env->tailLoopHead;
    $env->tailLoopHead = $head;

    $env->m->label($head);
    emitBlock($env, $stmt->body);
    if (! blockTerminates($stmt->body)) {
        $env->m->emit('br ' . $head);
    }

    $env->tailLoopHead = $prevHead;
}

/**
 * Assign the recall's arguments to the function's parameter slots and jump back
 * to the loop head.
 *
 * Every argument is evaluated into a fresh local before any argument slot is
 * written, so an argument that mentions a parameter another argument overwrites
 * (e.g. a two-parameter swap) still reads the pre-recall value.
 */
function emitTailRecall(EmitEnv $env, IR\TailRecall $stmt): void
{
    $head = $env->tailLoopHead;
    $slots = $env->tailRecSlots;
    if ($head === null || \count($stmt->args) !== \count($slots)) {
        throw new \RuntimeException('DotNet emit: tail_recall outside a tail loop');
    }

    $values = [];
    foreach ($stmt->args as $arg) {
        emitOperand($env, $arg);
        $slot = $env->freshLocal();
        $env->m->store($slot);
        $values[] = $slot;
    }

    foreach ($slots as $i => $slot) {
        $env->m->load($values[$i]);
        $env->m->storeArg((int) \substr($slot, 4));
    }

    $env->m->emit('br ' . $head);
}

function emitFunctionReturn(EmitEnv $env): void
{
    $env->m->emit('ret');
}
