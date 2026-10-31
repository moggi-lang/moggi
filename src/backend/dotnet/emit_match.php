<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;
use function Moggi\Backend\DotNet\Il\ilString;

// --- Match / patterns ---------------------------------------------------------

function emitMatchReturn(EmitEnv $env, IR\MatchReturn $stmt): void
{
    emitMatchCommon($env, $stmt->scrutinee, $stmt->arms, null, true);
}

function emitMatchStmt(EmitEnv $env, IR\MatchStmt $stmt): void
{
    emitMatchCommon($env, $stmt->scrutinee, $stmt->arms, $stmt->dest, false);
}

function emitMatchIo(EmitEnv $env, IR\IoMatch $stmt): void
{
    emitMatchCommon($env, $stmt->scrutinee, $stmt->arms, null, false);
}

/** @param list<IR\MatchArm> $arms */
function emitMatchCommon(EmitEnv $env, IR\Operand $scrutinee, array $arms, ?int $dest, bool $returnNullOnNoDest): void
{
    emitOperand($env, $scrutinee);
    $scrut = $env->freshLocal();
    $env->m->store($scrut);
    $end = 'match_end_' . $scrut;
    $needsEndLabel = true;
    if ($dest !== null) {
        $env->localSlot('t' . $dest);
    }

    $armId = 0;
    foreach ($arms as $arm) {
        $savedLocals = $env->locals;
        $fail = 'match_fail_' . $scrut . '_' . $armId;
        ++$armId;
        emitPatternTest($env, $arm->pattern, $scrut, $fail);
        foreach ($arm->guards as $guard) {
            emitBlock($env, $guard->prep);
            emitOperand($env, $guard->cond);
            $env->m->emit('unbox.any bool');
            $env->m->emit('brfalse ' . $fail);
        }
        $endsWithRet = blockTerminates($arm->body);
        emitBlock($env, $arm->body);
        if (! $endsWithRet) {
            $env->m->emit('br ' . $end);
        }
        $env->m->label($fail);
        $env->locals = $savedLocals;
    }
    $env->m->emit('ldstr ' . ilString('non-exhaustive match'));
    $env->m->emit('newobj instance void [System.Runtime]System.Exception::.ctor(string)');
    $env->m->emit('throw');

    if (! $needsEndLabel) {
        return;
    }
    $env->m->label($end);
    if ($dest !== null) {
        return;
    }
    if (! $returnNullOnNoDest) {
        return;
    }
    $env->m->emit('ldnull');
    emitFunctionReturn($env);
}

