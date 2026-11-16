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
        case 'coerce#':
        case 'unsafeCoerce#':
            emitOperand($env, $args[0]);

            return;
        case 'callStack#':
            $env->m->emit('call string Moggi.Rt.RT::CallStackFrames()');

            return;
        case 'errorWithoutStackTrace#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            $env->m->emit('ldnull');
            $env->m->emit('call object Moggi.Rt.RT::ThrowErrorCall(string, object[])');

            return;
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
        case 'mfixPure#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            emitOperand($env, $args[2]);
            $env->m->emit('call object Moggi.Rt.RT::MFixValue(object, object, object)');

            return;
        case 'mfixIO#':
            emitOperand($env, $args[0]);
            $env->m->emit('call object Moggi.Rt.RT::MFix(object)');

            return;
        case 'exceptionWrap#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[2]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[3]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[4]);
            $env->m->emit('call object[] Moggi.Rt.RT::ExceptionWrap(string, string, string, string, object)');

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
        case 'exceptionShow#':
            emitOperand($env, $args[0]);
            $env->m->emit('call string Moggi.Rt.RT::ExceptionShow(object)');

            return;
        case 'exceptionType#':
            emitOperand($env, $args[0]);
            $env->m->emit('call string Moggi.Rt.RT::ExceptionType(object)');

            return;
        case 'exceptionClearContext#':
            emitOperand($env, $args[0]);
            $env->m->emit('call object[] Moggi.Rt.RT::ExceptionClearContext(object)');

            return;
        case 'exceptionContext#':
            emitOperand($env, $args[0]);
            $env->m->emit('call object Moggi.Rt.RT::ExceptionContext(object)');

            return;
        case 'exceptionAddContext#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            emitOperand($env, $args[2]);
            $env->m->emit('call object[] Moggi.Rt.RT::ExceptionAddContext(object, object, object)');

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
