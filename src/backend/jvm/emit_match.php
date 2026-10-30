<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\IR;

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
    $env->c->astore($scrut);
    $matchId = $env->freshLabelId();
    $end = 'match_end_' . $matchId;
    $needsEndLabel = $dest !== null;
    if ($dest !== null) {
        $env->localSlot('t' . $dest, 'top');
    }
    $armId = 0;
    foreach ($arms as $arm) {
        $savedLocals = $env->locals;
        $savedLocalTypes = $env->localTypes;
        $savedNextLocal = $env->nextLocal;
        $fail = 'match_fail_' . $matchId . '_' . $armId;
        ++$armId;
        emitPatternTest($env, $arm->pattern, $scrut, $fail);
        foreach ($arm->guards as $guard) {
            emitBlock($env, $guard->prep);
            emitOperand($env, $guard->cond);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->ifeq($fail);
        }
        $endsWithRet = blockTerminates($arm->body);
        emitBlock($env, $arm->body);
        if (! $endsWithRet) {
            $needsEndLabel = true;
            $env->c->goto_($end);
        }
        $env->c->label($fail);
        $env->locals = $savedLocals;
        $env->localTypes = $savedLocalTypes;
        $env->nextLocal = $savedNextLocal;
        $env->c->noteFrame($env->frameLocals());
    }
    $env->c->new_($env->cp->class_('java/lang/RuntimeException'));
    $env->c->dup();
    $env->c->ldc($env->cp->string_('non-exhaustive match'));
    $env->c->invokespecial($env->cp->methodRef('java/lang/RuntimeException', '<init>', '(Ljava/lang/String;)V'), 1, false);
    $env->c->athrow();
    if (! $needsEndLabel) {
        $env->c->noteFrame($env->frameLocals());

        return;
    }
    $env->c->label($end);
    if ($dest !== null) {
        $env->localSlot('t' . $dest);
    }
    $env->c->noteFrame($env->frameLocals());
    if ($dest !== null) {
        return;
    }
    if (! $returnNullOnNoDest) {
        return;
    }
    $env->c->aconst_null();
    emitFunctionReturn($env);
}

