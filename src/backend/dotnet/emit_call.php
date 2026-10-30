<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;
use function Moggi\Backend\DotNet\Naming\moduleTypeName;
use function Moggi\Backend\DotNet\Naming\symbolName;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\IR\Projection\parse as projectionParse;
use function Moggi\IR\Projection\tupleArity as projectionTupleArity;

/** A call whose value is on the stack, without storing it in its destination temp. */
function emitCallValue(EmitEnv $env, IR\Call|IR\CallValue $call): void
{
    if ($call instanceof IR\Call) {
        emitStaticCall($env, $call->callee, $call->args);

        return;
    }

    emitOperand($env, $call->callee);
    emitRuntimeApply($env, $call->args);
}


/** @param list<IR\Operand> $args */
function emitStaticCall(EmitEnv $env, string $name, array $args, ?string $ownerModule = null): void
{
    if (emitBuiltinCall($env, $name, $args)) {
        return;
    }
    $callArgs = callArgs($env, $name, $args);
    foreach ($callArgs as $arg) {
        emitOperand($env, $arg);
    }
    $target = resolveCallableTarget($env, $name, $ownerModule);
    $params = \implode(', ', \array_fill(0, \count($callArgs), 'object'));
    $env->m->emit("call object {$target['owner']}::{$target['sym']}({$params})");
}

/** @return array{owner: string, sym: string} */
function resolveCallableTarget(EmitEnv $env, string $name, ?string $ownerModule = null): array
{
    $targetOwner = $env->owner;
    $sym = symbolName($name);
    if ($ownerModule !== null && $ownerModule !== '') {
        $targetOwner = moduleTypeName($ownerModule);
    } else {
        $parsed = parseResolvedSymbol($name);
        if ($parsed !== null) {
            $targetOwner = moduleTypeName($parsed['module']);
            $sym = symbolName($parsed['name']);
        } elseif (! isset($env->localCallables[$name]) && isset($env->externalFns[$name])) {
            $extParsed = parseResolvedSymbol($env->externalFns[$name]);
            if ($extParsed !== null) {
                $targetOwner = moduleTypeName($extParsed['module']);
                $sym = symbolName($extParsed['name']);
            }
        }
    }

    return ['owner' => $targetOwner, 'sym' => $sym];
}

/** @param list<IR\Operand> $args */
function emitBuiltinCall(EmitEnv $env, string $name, array $args): bool
{
    $projection = projectionParse($name);
    if ($projection !== null) {
        emitOperand($env, $args[0]);
        if (! $projection['tuple']) {
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld object[] Moggi.Rt.Con::fields');
        } else {
            $env->m->emit('castclass object[]');
        }
        $env->m->emit('ldc.i4 ' . $projection['index']);
        $env->m->emit('ldelem.ref');

        return true;
    }
    $tupleArity = projectionTupleArity($name);
    if ($tupleArity !== null) {
        if ($tupleArity === 0) {
            $env->m->emit('ldnull');

            return true;
        }
        pushObjectArray($env, $args);

        return true;
    }

    return false;
}

/**
 * IR after bindLambdaCaptures already includes capture args on Partial/Call.
 * Do not prepend lambdaMeta captures again (mirrors JVM `callArgs`).
 *
 * @param list<IR\Operand> $args
 * @return list<IR\Operand>
 */
function callArgs(EmitEnv $env, string $name, array $args): array
{
    return $args;
}

/**
 * Emit `RT.Apply` for the callee already on the stack followed by `$args`.
 *
 * A single argument goes through `RT.Apply1`, which takes the scalar directly
 * and so avoids both the caller-side `object[]` and, inside a partial
 * application, the second array allocation. `f x y z` emits one apply per
 * argument, so this is the shape that dominates curried code.
 */
function emitRuntimeApply(EmitEnv $env, array $args): void
{
    if (\count($args) === 1) {
        emitOperand($env, $args[0]);
        $env->m->emit('call object Moggi.Rt.RT::Apply1(object, object)');

        return;
    }

    pushObjectArray($env, $args);
    $env->m->emit('call object Moggi.Rt.RT::Apply(object, object[])');
}

