<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;
use function Moggi\Backend\DotNet\Il\ilString;
use function Moggi\Backend\Meta\tempReadsForFunction;
use function Moggi\Backend\Meta\operandCallSrcLoc;
use function Moggi\Backend\Meta\returnedCall;

function emitBlock(EmitEnv $env, IR\Block $block): void
{
    $items = \array_values($block->items);
    $tempReads = tempReadsForFunction($env->tempReads, $block);

    for ($i = 0; $i < \count($items); ++$i) {
        $call = returnedCall($items[$i], $items[$i + 1] ?? null, $tempReads);
        if ($call !== null) {
            $env->m->emitLine($call->srcLoc);
            emitCallValue($env, $call);
            emitFunctionReturn($env);
            ++$i;

            continue;
        }

        emitStmt($env, $items[$i]);
    }
}

/** A call whose value is on the stack, without storing it in its destination temp. */
function emitStmt(EmitEnv $env, IR\Stmt $stmt): void
{
    $frameLoc = match ($stmt::class) {
        IR\Ret::class => operandCallSrcLoc($stmt->value),
        IR\Call::class,
        IR\IoCall::class,
        IR\IoRun::class,
        IR\IoAssignAction::class,
        IR\CallValue::class,
        IR\DictCall::class => $stmt->srcLoc ?? null,
        default => null,
    };
    $env->m->emitLine($frameLoc);
    switch ($stmt::class) {
        case IR\Ret::class:
            /** @var IR\Ret $stmt */
            emitOperand($env, $stmt->value);
            emitFunctionReturn($env);

            return;

        case IR\Assign::class:
            /** @var IR\Assign $stmt */
            emitOperand($env, $stmt->value);
            $env->m->store($env->localSlot('t' . $stmt->dest));

            return;

        case IR\Let::class:
            /** @var IR\Let $stmt */
            emitOperand($env, $stmt->value);
            $env->m->store($env->localSlot($stmt->name));

            return;

        case IR\Call::class:
            /** @var IR\Call $stmt */
            emitStaticCall($env, $stmt->callee, $stmt->args);
            $env->m->store($env->localSlot('t' . $stmt->dest));

            return;

        case IR\CallValue::class:
            /** @var IR\CallValue $stmt */
            emitOperand($env, $stmt->callee);
            emitRuntimeApply($env, $stmt->args);
            $env->m->store($env->localSlot('t' . $stmt->dest));

            return;

        case IR\IoRun::class:
            /** @var IR\IoRun $stmt */
            emitOperand($env, $stmt->action);
            $env->m->emit('call object Moggi.Rt.RT::IoRun(object)');
            if ($stmt->dest !== null) {
                $env->m->store($env->localSlot('t' . $stmt->dest));
            } else {
                $env->m->emit('pop');
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
            $env->m->emit('call object Moggi.Rt.RT::ThrowSomeException(object, object[])');
            if ($stmt->dest !== null) {
                $env->m->store($env->localSlot('t' . $stmt->dest));
            } else {
                $env->m->emit('pop');
            }

            return;

        case IR\IoCatch::class:
            /** @var IR\IoCatch $stmt */
            emitOperand($env, $stmt->action);
            $env->m->emit('castclass Moggi.Rt.IO');
            emitOperand($env, $stmt->handler);
            $env->m->emit('call object Moggi.Rt.RT::IoCatch(class Moggi.Rt.IO, object)');
            if ($stmt->dest !== null) {
                $env->m->store($env->localSlot('t' . $stmt->dest));
            } else {
                $env->m->emit('pop');
            }

            return;

        case IR\IoFinally::class:
            /** @var IR\IoFinally $stmt */
            emitOperand($env, $stmt->action);
            $env->m->emit('castclass Moggi.Rt.IO');
            emitOperand($env, $stmt->cleanup);
            $env->m->emit('castclass Moggi.Rt.IO');
            $env->m->emit('call object Moggi.Rt.RT::IoFinally(class Moggi.Rt.IO, class Moggi.Rt.IO)');
            if ($stmt->dest !== null) {
                $env->m->store($env->localSlot('t' . $stmt->dest));
            } else {
                $env->m->emit('pop');
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
            $env->m->emit('ldstr ' . ilString($stmt->method));
            pushObjectArray($env, $stmt->args);
            $env->m->emit('call object Moggi.Rt.RT::DictCall(object, string, object[])');
            $env->m->store($env->localSlot('t' . $stmt->dest));

            return;

        default:
            throw new \RuntimeException('DotNet emit: unsupported stmt ' . $stmt::class);
    }
}

/**
 * Whether control never falls out of the end of `$stmt`, so no fall-through
 * `br` must be appended after it.
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

