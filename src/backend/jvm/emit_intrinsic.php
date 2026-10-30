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
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionWrap', '(Ljava/lang/String;Ljava/lang/String;Ljava/lang/Object;)[Ljava/lang/Object;'),
                3,
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
        case 'fix#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'fix', '(Ljava/lang/Object;)Ljava/lang/Object;'),
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
