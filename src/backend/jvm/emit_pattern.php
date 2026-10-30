<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\IR;
use function Moggi\Semantics\Kernel\boolCtorValue;

function emitPatternTest(EmitEnv $env, IR\Pattern $pat, int $scrutLocal, string $failLabel): void
{
    if ($pat instanceof IR\PatWild) {
        return;
    }
    if ($pat instanceof IR\PatVar) {
        $env->locals[$pat->name] = $scrutLocal;

        return;
    }
    if ($pat instanceof IR\PatNil) {
        $env->c->aload($scrutLocal);
        $env->c->ifnonnull($failLabel);

        return;
    }
    if ($pat instanceof IR\PatLit) {
        if (\is_string($pat->value)) {
            $env->c->aload($scrutLocal);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->ldc($env->cp->string_($pat->value));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->ifeq($failLabel);

            return;
        }
        $env->c->aload($scrutLocal);
        unboxInt($env);
        pushRawLong($env, (int) $pat->value);
        $env->c->lcmp();
        $env->c->ifne($failLabel);

        return;
    }
    if ($pat instanceof IR\PatChar) {
        $env->c->aload($scrutLocal);
        unboxInt($env);
        pushRawLong($env, $pat->value);
        $env->c->lcmp();
        $env->c->ifne($failLabel);

        return;
    }
    if ($pat instanceof IR\PatCon) {
        if (($boolValue = boolCtorValue($pat->name)) !== null) {
            $env->c->aload($scrutLocal);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            if ($boolValue) {
                $env->c->ifeq($failLabel);
            } else {
                $env->c->ifne($failLabel);
            }

            return;
        }
        if (isset($env->newtypeConstructors[$pat->name])) {
            foreach ($pat->args as $sub) {
                if ($sub instanceof IR\PatWild) {
                    continue;
                }
                if ($sub instanceof IR\PatVar) {
                    $env->locals[$sub->name] = $scrutLocal;
                } else {
                    emitPatternTest($env, $sub, $scrutLocal, $failLabel);
                }
            }

            return;
        }
        $env->c->aload($scrutLocal);
        $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
        $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
        $env->c->ldc($env->cp->string_($pat->name));
        $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
        $env->c->ifeq($failLabel);
        foreach ($pat->args as $i => $sub) {
            if ($sub instanceof IR\PatWild) {
                continue;
            }
            $env->c->aload($scrutLocal);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $env->c->iconst($i);
            $env->c->aaload();
            if ($sub instanceof IR\PatVar) {
                $slot = $env->freshLocal();
                $env->locals[$sub->name] = $slot;
                $env->c->astore($slot);
            } else {
                $nested = $env->freshLocal();
                $env->c->astore($nested);
                emitPatternTest($env, $sub, $nested, $failLabel);
            }
        }

        return;
    }
    if ($pat instanceof IR\PatCons) {
        $env->c->aload($scrutLocal);
        $env->c->ifnull($failLabel);
        $env->c->aload($scrutLocal);
        $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
        $env->c->getfield($env->cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
        $headSlot = $env->freshLocal();
        $env->c->astore($headSlot);
        emitPatternTest($env, $pat->head, $headSlot, $failLabel);
        $env->c->aload($scrutLocal);
        $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
        $env->c->getfield($env->cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
        $tailSlot = $env->freshLocal();
        $env->c->astore($tailSlot);
        emitPatternTest($env, $pat->tail, $tailSlot, $failLabel);

        return;
    }
    if ($pat instanceof IR\PatTuple) {
        foreach ($pat->elements as $i => $element) {
            if ($element instanceof IR\PatWild) {
                continue;
            }
            $env->c->aload($scrutLocal);
            $env->c->checkcast($env->cp->class_('[Ljava/lang/Object;'));
            $env->c->iconst($i);
            $env->c->aaload();
            if ($element instanceof IR\PatVar) {
                $slot = $env->freshLocal();
                $env->locals[$element->name] = $slot;
                $env->c->astore($slot);
            } else {
                $nested = $env->freshLocal();
                $env->c->astore($nested);
                emitPatternTest($env, $element, $nested, $failLabel);
            }
        }

        return;
    }

    throw new \RuntimeException('JVM emit: unsupported pattern ' . $pat::class);
}

