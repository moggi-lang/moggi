<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;
use function Moggi\Backend\DotNet\Il\ilString;
use function Moggi\Semantics\Kernel\boolCtorValue;

function emitPatternTest(EmitEnv $env, IR\Pattern $pat, string $scrut, string $fail): void
{
    if ($pat instanceof IR\PatWild) {
        return;
    }
    if ($pat instanceof IR\PatVar) {
        $env->locals[$pat->name] = $scrut;

        return;
    }
    if ($pat instanceof IR\PatNil) {
        $env->m->load($scrut);
        $env->m->emit('brtrue ' . $fail);

        return;
    }
    if ($pat instanceof IR\PatLit) {
        if (\is_string($pat->value)) {
            $env->m->load($scrut);
            $env->m->emit('castclass string');
            $env->m->emit('ldstr ' . ilString($pat->value));
            $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
            $env->m->emit('brfalse ' . $fail);

            return;
        }
        $env->m->load($scrut);
        unboxInt($env);
        $env->m->emit('ldc.i8 ' . (int) $pat->value);
        $env->m->emit('ceq');
        $env->m->emit('brfalse ' . $fail);

        return;
    }
    if ($pat instanceof IR\PatChar) {
        $env->m->load($scrut);
        unboxInt($env);
        $env->m->emit('ldc.i8 ' . $pat->value);
        $env->m->emit('ceq');
        $env->m->emit('brfalse ' . $fail);

        return;
    }
    if ($pat instanceof IR\PatCon) {
        if (($boolValue = boolCtorValue($pat->name)) !== null) {
            $env->m->load($scrut);
            $env->m->emit('unbox.any bool');
            if ($boolValue) {
                $env->m->emit('brfalse ' . $fail);
            } else {
                $env->m->emit('brtrue ' . $fail);
            }

            return;
        }
        if (isset($env->newtypeConstructors[$pat->name])) {
            foreach ($pat->args as $sub) {
                if ($sub instanceof IR\PatWild) {
                    continue;
                }
                if ($sub instanceof IR\PatVar) {
                    $env->locals[$sub->name] = $scrut;
                } else {
                    emitPatternTest($env, $sub, $scrut, $fail);
                }
            }

            return;
        }
        $env->m->load($scrut);
        $env->m->emit('castclass Moggi.Rt.Con');
        $env->m->emit('ldfld string Moggi.Rt.Con::tag');
        $env->m->emit('ldstr ' . ilString($pat->name));
        $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
        $env->m->emit('brfalse ' . $fail);
        foreach ($pat->args as $i => $sub) {
            if ($sub instanceof IR\PatWild) {
                continue;
            }
            $env->m->load($scrut);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld object[] Moggi.Rt.Con::fields');
            $env->m->emit("ldc.i4 {$i}");
            $env->m->emit('ldelem.ref');
            if ($sub instanceof IR\PatVar) {
                $slot = $env->freshLocal();
                $env->m->store($slot);
                $env->locals[$sub->name] = $slot;
            } else {
                $nested = $env->freshLocal();
                $env->m->store($nested);
                emitPatternTest($env, $sub, $nested, $fail);
            }
        }

        return;
    }
    if ($pat instanceof IR\PatCons) {
        $env->m->load($scrut);
        $env->m->emit('brfalse ' . $fail);
        $env->m->load($scrut);
        $env->m->emit('castclass Moggi.Rt.MList');
        $env->m->emit('ldfld object Moggi.Rt.MList::head');
        $headSlot = $env->freshLocal();
        $env->m->store($headSlot);
        emitPatternTest($env, $pat->head, $headSlot, $fail);
        $env->m->load($scrut);
        $env->m->emit('castclass Moggi.Rt.MList');
        $env->m->emit('ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail');
        $tailSlot = $env->freshLocal();
        $env->m->store($tailSlot);
        emitPatternTest($env, $pat->tail, $tailSlot, $fail);

        return;
    }
    if ($pat instanceof IR\PatTuple) {
        foreach ($pat->elements as $i => $element) {
            if ($element instanceof IR\PatWild) {
                continue;
            }
            $env->m->load($scrut);
            $env->m->emit('castclass object[]');
            $env->m->emit("ldc.i4 {$i}");
            $env->m->emit('ldelem.ref');
            if ($element instanceof IR\PatVar) {
                $slot = $env->freshLocal();
                $env->m->store($slot);
                $env->locals[$element->name] = $slot;
            } else {
                $nested = $env->freshLocal();
                $env->m->store($nested);
                emitPatternTest($env, $element, $nested, $fail);
            }
        }

        return;
    }

    throw new \RuntimeException('DotNet emit: unsupported pattern ' . $pat::class);
}

// --- Data declarations --------------------------------------------------------

