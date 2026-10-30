<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;

/**
 * @param array<string, int> $functionArity
 * @param array<string, string> $externalFns
 * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
 */
function fnWrapperName(string $targetOwner, string $targetSym, int $arity): string
{
    $tag = \preg_replace('/[^A-Za-z0-9_]/', '_', $targetOwner . '_' . $targetSym) ?? ($targetOwner . '_' . $targetSym);

    return 'Moggi.Fn.' . $tag . '_' . $arity;
}

function buildFnWrapperClass(string $typeName, string $targetOwner, string $targetSym, int $arity): string
{
    $lines = [];
    for ($i = 0; $i < $arity; ++$i) {
        $lines[] = 'ldarg 1';
        $lines[] = "ldc.i4 {$i}";
        $lines[] = 'ldelem.ref';
    }
    $callParams = \implode(', ', \array_fill(0, $arity, 'object'));
    $lines[] = "call object {$targetOwner}::{$targetSym}({$callParams})";
    $lines[] = 'ret';
    $body = '';
    foreach ($lines as $line) {
        $body .= '    ' . $line . "\n";
    }

    return <<<IL
.class public auto ansi sealed beforefieldinit {$typeName}
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .method public hidebysig specialname rtspecialname
          instance void .ctor() cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 32
{$body}  }
}

IL;
}

function emitTopLevelFnValue(EmitEnv $env, string $name, int $arity, ?string $ownerModule = null): void
{
    emitTopLevelFnValueFromTarget($env, resolveCallableTarget($env, $name, $ownerModule), $arity);
}

/** @param array{owner: string, sym: string} $target */
function emitTopLevelFnValueFromTarget(EmitEnv $env, array $target, int $arity): void
{
    $wrapper = $env->wrapperRegistry?->ensure($target['owner'], $target['sym'], $arity)
        ?? fnWrapperName($target['owner'], $target['sym'], $arity);
    $env->m->emit("ldc.i4 {$arity}");
    $env->m->emit("newobj instance void {$wrapper}::.ctor()");
    $env->m->emit('newobj instance void Moggi.Rt.TopLevelFn::.ctor(int32, class Moggi.Rt.Fn)');
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

