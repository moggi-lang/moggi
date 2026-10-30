<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


require_once __DIR__ . '/../stack/source_lines.php';

use Moggi\IR;
use function Moggi\Backend\Stack\SourceLines\operandLoc;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Semantics\Kernel\boolCtorValue;

function emitOperand(EmitEnv $env, IR\Operand $op): void
{
    if ($op instanceof IR\ConstInt || $op instanceof IR\ConstChar) {
        pushBoxedInt($env, $op->value);

        return;
    }
    if ($op instanceof IR\ConstStr) {
        $env->c->ldc($env->cp->string_($op->value));

        return;
    }
    if ($op instanceof IR\ConstDouble) {
        $env->c->ldc($env->cp->string_(formatDoubleLiteral($op->value)));
        $env->c->invokestatic(
            $env->cp->methodRef('java/lang/Double', 'valueOf', '(Ljava/lang/String;)Ljava/lang/Double;'),
            1,
            true,
        );

        return;
    }
    if ($op instanceof IR\Unit) {
        $env->c->aconst_null();

        return;
    }
    if ($op instanceof IR\Local) {
        $env->c->aload($env->locals[$op->name] ?? throw new \RuntimeException("unknown local {$op->name}"));

        return;
    }
    if ($op instanceof IR\Temp) {
        $key = 't' . $op->id;
        $slot = $env->locals[$key] ?? null;
        if ($slot === null) {
            throw new \RuntimeException("unknown temp {$key} in {$env->owner}");
        }
        $env->c->aload($slot);

        return;
    }
    if ($op instanceof IR\Intrinsic) {
        noteSrcLoc($env, operandLoc($op));
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
        $env->c->ldc($env->cp->string_($op->method));
        $env->c->invokestatic(
            $env->cp->methodRef('moggi/rt/RT', 'dictMethod', '(Ljava/lang/Object;Ljava/lang/String;)Ljava/lang/Object;'),
            2,
            true,
        );

        return;
    }
    if ($op instanceof IR\FnRef) {
        if (($boolValue = boolCtorValue($op->name)) !== null) {
            $env->c->iconst($boolValue ? 1 : 0);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

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
            throw new \RuntimeException("JVM emit: FnRef `{$op->name}` has unknown arity");
        }
        if ($arity === 0) {
            emitStaticCall($env, $op->name, []);

            return;
        }
        emitTopLevelFnValue($env, $op->name, $arity);

        return;
    }

    throw new \RuntimeException('JVM emit: unsupported operand ' . $op::class);
}

