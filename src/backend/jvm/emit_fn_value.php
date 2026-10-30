<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\Backend\Jvm\Classfile\ClassBuilder;
use Moggi\Backend\Jvm\Classfile\CodeBuilder;
use Moggi\Backend\Jvm\Classfile\ConstantPool;
use Moggi\IR;

function fnWrapperName(string $targetOwner, string $targetSym, int $arity): string
{
    $sym = \preg_replace('/[^A-Za-z0-9_$]/', '_', $targetSym) ?? $targetSym;

    return $targetOwner . '$fn$' . $sym . '$' . $arity;
}

function buildJvmFnWrapperClass(string $internal, string $targetOwner, string $targetSym, int $arity): string
{
    $b = new ClassBuilder($internal);
    $b->implement('moggi/rt/Fn');
    $b->addMethod(
        '<init>',
        '()V',
        0x0001,
        1,
        [$internal],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokespecial($cp->methodRef('java/lang/Object', '<init>', '()V'), 0, false);
            $c->return_();
        }
    );
    $b->addMethod(
        'invoke',
        '([Ljava/lang/Object;)Ljava/lang/Object;',
        0x0001,
        \max($arity + 2, 2),
        [$internal, '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp) use ($targetOwner, $targetSym, $arity): void {
            for ($i = 0; $i < $arity; ++$i) {
                $c->aload(1);
                $c->iconst($i);
                $c->aaload();
            }
            $desc = '(' . \str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
            $c->invokestatic($cp->methodRef($targetOwner, $targetSym, $desc), $arity, true);
            $c->areturn();
        }
    );

    return $b->toBytes();
}

function emitTopLevelFnValue(EmitEnv $env, string $name, int $arity, ?string $ownerModule = null): void
{
    $target = resolveCallableTarget($env->owner, $env->externalFns, $name, $ownerModule, $env->localCallables);
    $wrapper = $env->wrapperRegistry?->ensure($target['owner'], $target['sym'], $arity)
        ?? fnWrapperName($target['owner'], $target['sym'], $arity);
    $env->c->new_($env->cp->class_('moggi/rt/TopLevelFn'));
    $env->c->dup();
    $env->c->iconst($arity);
    $env->c->new_($env->cp->class_($wrapper));
    $env->c->dup();
    $env->c->invokespecial(
        $env->cp->methodRef($wrapper, '<init>', '()V'),
        0,
        false,
    );
    $env->c->invokespecial(
        $env->cp->methodRef('moggi/rt/TopLevelFn', '<init>', '(ILmoggi/rt/Fn;)V'),
        2,
        false,
    );
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

