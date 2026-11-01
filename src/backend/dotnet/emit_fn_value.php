<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;

/**
 * Push a first-class function value for one static target.
 *
 * The value is a `TopLevelFn` (which is what keeps `RT.Apply` arity-aware) over the runtime's
 * `Moggi.Rt.StaticFn<arity>`, carrying the target's entry point as a `Moggi.Rt.Fn<arity>` delegate.
 * One pair of classes serves every target of an arity; the class-per-target this replaced was one
 * class per (owner, symbol, arity) triple, which is over a thousand classes in an ordinary program.
 *
 * @param array{owner: string, sym: string} $target
 */
function emitTopLevelFnValueFromTarget(EmitEnv $env, array $target, int $arity): void
{
    $params = \implode(', ', \array_fill(0, $arity, 'object'));
    $env->m->emit("ldc.i4 {$arity}");
    $env->m->emit('ldnull');
    $env->m->emit("ldftn object {$target['owner']}::{$target['sym']}({$params})");
    $env->m->emit("newobj instance void Moggi.Rt.Fn{$arity}::.ctor(object, native int)");
    $env->m->emit("newobj instance void Moggi.Rt.StaticFn{$arity}::.ctor(class Moggi.Rt.Fn{$arity})");
    $env->m->emit('newobj instance void Moggi.Rt.TopLevelFn::.ctor(int32, class Moggi.Rt.Fn)');
}

function emitTopLevelFnValue(EmitEnv $env, string $name, int $arity, ?string $ownerModule = null): void
{
    emitTopLevelFnValueFromTarget($env, resolveCallableTarget($env, $name, $ownerModule), $arity);
}

/** @param list<IR\Operand> $args */
function emitPartialValue(EmitEnv $env, string $fn, int $arity, array $args): void
{
    $runtimeArity = $env->functionArity[$fn] ?? $arity;
    $allArgs = callArgs($env, $fn, $args);
    $target = resolveCallableTarget($env, $fn);
    $env->m->emit("ldc.i4 {$runtimeArity}");
    emitTopLevelFnValueFromTarget($env, $target, $runtimeArity);
    pushObjectArray($env, $allArgs);
    $env->m->emit('newobj instance void Moggi.Rt.Partial::.ctor(int32, class Moggi.Rt.Fn, object[])');
}
