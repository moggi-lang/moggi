<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


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
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'callStackFrames', '()Ljava/lang/String;'),
                0,
                true,
            );

            return;
        case 'errorWithoutStackTrace#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->aconst_null();
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'throwErrorCall', '(Ljava/lang/String;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );

            return;
        case 'error#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitSrcLocValue($env, $op->srcLoc);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'throwErrorCall', '(Ljava/lang/String;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );

            return;
        case 'exceptionWrap#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[2]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[3]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[4]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionWrap', '(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/Object;)[Ljava/lang/Object;'),
                5,
                true,
            );

            return;
        case 'exceptionUnwrap#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionUnwrap', '(Ljava/lang/String;Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );

            return;
        case 'exceptionThrow#':
            emitOperand($env, $args[0]);
            emitSrcLocValue($env, $op->srcLoc);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'throwSomeException', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );

            return;
        case 'exceptionDisplay#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionDisplay', '(Ljava/lang/Object;)Ljava/lang/String;'),
                1,
                true,
            );

            return;
        case 'exceptionShow#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionShow', '(Ljava/lang/Object;)Ljava/lang/String;'),
                1,
                true,
            );

            return;
        case 'exceptionType#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionType', '(Ljava/lang/Object;)Ljava/lang/String;'),
                1,
                true,
            );

            return;
        case 'exceptionClearContext#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionClearContext', '(Ljava/lang/Object;)[Ljava/lang/Object;'),
                1,
                true,
            );

            return;
        case 'exceptionContext#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionContext', '(Ljava/lang/Object;)Ljava/lang/Object;'),
                1,
                true,
            );

            return;
        case 'exceptionAddContext#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            emitOperand($env, $args[2]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionAddContext', '(Ljava/lang/Object;Ljava/lang/Object;Ljava/lang/Object;)[Ljava/lang/Object;'),
                3,
                true,
            );

            return;
        case 'fix#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'fix', '(Ljava/lang/Object;)Ljava/lang/Object;'),
                1,
                true,
            );

            return;
        case 'mfixPure#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            emitOperand($env, $args[2]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'mfixValue', '(Ljava/lang/Object;Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Object;'),
                3,
                true,
            );

            return;
        case 'mfixIO#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'mfix', '(Ljava/lang/Object;)Ljava/lang/Object;'),
                1,
                true,
            );

            return;
        case 'exceptionThrowIo#':
        case 'exceptionCatch#':
        case 'exceptionFinally#':
            throw new \RuntimeException(
                "JVM emit: intrinsic `{$op->name}` must be lowered to IO exception IR",
            );
        default:
            throw new \RuntimeException("JVM emit: unsupported intrinsic {$op->name}");
    }
}
