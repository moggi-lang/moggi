<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;

function emitIntrinsic(EmitEnv $env, IR\Intrinsic $op): void
{
    if (emitIntrinsicPrimop($env, $op) || emitIntrinsicCompare($env, $op) || emitIntrinsicState($env, $op) || emitIntrinsicValue($env, $op)) {
        return;
    }

    $args = $op->args;
    switch ($op->name) {
        case 'error#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitSrcLocValue($env, $op->srcLoc);
            $env->m->emit('call object Moggi.Rt.RT::ThrowErrorCall(string, object[])');

            return;
        case 'fix#':
            emitOperand($env, $args[0]);
            $env->m->emit('call object Moggi.Rt.RT::Fix(object)');

            return;
        case 'exceptionWrap#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[2]);
            $env->m->emit('call object[] Moggi.Rt.RT::ExceptionWrap(string, string, object)');

            return;
        case 'exceptionUnwrap#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[1]);
            $env->m->emit('call object Moggi.Rt.RT::ExceptionUnwrap(string, object)');

            return;
        case 'exceptionThrow#':
            emitOperand($env, $args[0]);
            emitSrcLocValue($env, $op->srcLoc);
            $env->m->emit('call object Moggi.Rt.RT::ThrowSomeException(object, object[])');

            return;
        case 'exceptionDisplay#':
            emitOperand($env, $args[0]);
            $env->m->emit('call string Moggi.Rt.RT::ExceptionDisplay(object)');

            return;
        case 'exceptionThrowIo#':
        case 'exceptionCatch#':
        case 'exceptionFinally#':
            throw new \RuntimeException(
                "DotNet emit: intrinsic `{$op->name}` must be lowered to IO exception IR",
            );
        default:
            throw new \RuntimeException("DotNet emit: unsupported intrinsic {$op->name}");
    }
}

// --- Match / patterns ---------------------------------------------------------
