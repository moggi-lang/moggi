<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\Backend\Jvm\Classfile\CodeBuilder;
use Moggi\Backend\Jvm\Classfile\ConstantPool;
use Moggi\IR;

/**
 * Push a first-class function value for one static target.
 *
 * The value is a `TopLevelFn` (which is what lets `RT.Apply` apply the whole arity at once) over a
 * single `moggi/rt/StaticFn`, which carries the target as a method handle. One adapter serves every
 * target and arity; the class-per-target wrapper this replaced was one class per
 * (owner, symbol, arity) pair, which is thousands of class files in an ordinary program.
 */
function pushTopLevelFn(CodeBuilder $c, ConstantPool $cp, string $targetOwner, string $targetSym, int $arity): void
{
    $descriptor = '(' . \str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
    $handle = $cp->methodHandle(6, $cp->methodRef($targetOwner, $targetSym, $descriptor));

    $c->new_($cp->class_('moggi/rt/TopLevelFn'));
    $c->dup();
    $c->iconst($arity);
    $c->new_($cp->class_('moggi/rt/StaticFn'));
    $c->dup();
    $c->ldc($handle);
    $c->invokespecial(
        $cp->methodRef('moggi/rt/StaticFn', '<init>', '(Ljava/lang/invoke/MethodHandle;)V'),
        1,
        false,
    );
    $c->invokespecial(
        $cp->methodRef('moggi/rt/TopLevelFn', '<init>', '(ILmoggi/rt/Fn;)V'),
        2,
        false,
    );
}

function emitTopLevelFnValue(EmitEnv $env, string $name, int $arity, ?string $ownerModule = null): void
{
    $target = resolveCallableTarget($env->owner, $env->externalFns, $name, $ownerModule, $env->localCallables);
    pushTopLevelFn($env->c, $env->cp, $target['owner'], $target['sym'], $arity);
}

/** @param list<IR\Operand> $args */
function emitPartialValue(EmitEnv $env, string $fn, int $arity, array $args): void
{
    $runtimeArity = $env->functionArity[$fn] ?? $arity;
    $allArgs = callArgs($env, $fn, $args);
    $env->c->new_($env->cp->class_('moggi/rt/Partial'));
    $env->c->dup();
    $env->c->iconst($runtimeArity);
    emitTopLevelFnValue($env, $fn, $runtimeArity);
    pushObjectArray($env, $allArgs);
    $env->c->invokespecial(
        $env->cp->methodRef('moggi/rt/Partial', '<init>', '(ILmoggi/rt/Fn;[Ljava/lang/Object;)V'),
        3,
        false,
    );
}
