<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;
use function Moggi\Backend\DotNet\Il\ilString;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Semantics\Kernel\boolCtorValue;

// --- Operands -----------------------------------------------------------------

function emitOperand(EmitEnv $env, IR\Operand $op): void
{
    if ($op instanceof IR\ConstInt || $op instanceof IR\ConstChar) {
        pushBoxedInt($env, $op->value);

        return;
    }
    if ($op instanceof IR\ConstStr) {
        $env->m->emit('ldstr ' . ilString($op->value));

        return;
    }
    if ($op instanceof IR\ConstDouble) {
        $env->m->emit('ldc.r8 ' . formatDoubleLiteral($op->value));
        boxDouble($env);

        return;
    }
    if ($op instanceof IR\Unit) {
        $env->m->emit('ldnull');

        return;
    }
    if ($op instanceof IR\Local) {
        $slot = $env->locals[$op->name] ?? throw new \RuntimeException("unknown local {$op->name}");
        $env->m->load($slot);

        return;
    }
    if ($op instanceof IR\Temp) {
        $key = 't' . $op->id;
        $slot = $env->locals[$key] ?? throw new \RuntimeException("unknown temp {$key} in {$env->owner}");
        $env->m->load($slot);

        return;
    }
    if ($op instanceof IR\Intrinsic) {
        emitIntrinsic($env, $op);

        return;
    }
    if ($op instanceof IR\ListLit) {
        emitListLit($env, $op);

        return;
    }
    if ($op instanceof IR\ForeignCall) {
        emitForeign($env, $op);

        return;
    }
    if ($op instanceof IR\Partial) {
        emitPartialValue($env, $op->fn, $op->arity, $op->args);

        return;
    }
    if ($op instanceof IR\DictMethod) {
        emitOperand($env, $op->evidence);
        $env->m->emit('ldstr ' . ilString($op->method));
        $env->m->emit('call object Moggi.Rt.RT::DictMethod(object, string)');

        return;
    }
    if ($op instanceof IR\FnRef) {
        if (($boolValue = boolCtorValue($op->name)) !== null) {
            $env->m->emit($boolValue ? 'ldc.i4.1' : 'ldc.i4.0');
            $env->m->emit('box bool');

            return;
        }
        if (isset($env->lambdaMeta[$op->name])) {
            $arity = \count($env->lambdaMeta[$op->name]['params'] ?? []);
            if ($arity === 0) {
                emitStaticCall($env, $op->name, []);

                return;
            }
            emitTopLevelFnValue($env, $op->name, $arity);

            return;
        }
        $arity = $env->functionArity[$op->name] ?? null;
        if ($arity === null) {
            $parsed = parseResolvedSymbol($op->name);
            if ($parsed !== null && ! isset($env->lambdaMeta[$parsed['name']])) {
                $arity = $env->functionArity[$parsed['name']] ?? null;
            }
        }
        if ($arity === null) {
            throw new \RuntimeException("DotNet emit: FnRef `{$op->name}` has unknown arity");
        }
        if ($arity === 0) {
            emitStaticCall($env, $op->name, []);

            return;
        }
        emitTopLevelFnValue($env, $op->name, $arity);

        return;
    }

    throw new \RuntimeException('DotNet emit: unsupported operand ' . $op::class);
}

