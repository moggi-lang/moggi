<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\IR;
use function Moggi\Backend\Jvm\Foreign\jvmIsInterface;
use function Moggi\Backend\Jvm\Foreign\jvmReturnDescriptor;
use function Moggi\Backend\Jvm\Foreign\parseJvmParamKinds;
use function Moggi\Backend\Jvm\Foreign\resolveJvmForeignPath;

function emitForeign(EmitEnv $env, IR\ForeignCall $op): void
{
    $resolved = resolveJvmForeignPath($op->path);

    $desc = $op->nativeSig;
    if ($desc === null || $desc === '') {
        throw new \RuntimeException(
            "JVM foreign `{$op->path}` missing inferred nativeSig (descriptor)",
        );
    }

    if ($resolved['dispatch'] === 'intrinsic') {
        emitForeignIntrinsic($env, $op, $resolved['hostMember'], $desc);

        return;
    }

    if (($op->kind ?? 'function') === 'const') {
        $env->c->getstatic(
            $env->cp->fieldRef($resolved['class'], $resolved['hostMember'], $desc),
            valueSlots($desc),
        );
        boxForeignReturn($env, $desc);
        emitForeignIoWrap($env, $op->ioWrap);

        return;
    }

    $argKinds = parseJvmParamKinds($desc);
    $args = $op->args;
    $argOffset = 0;

    if ($resolved['dispatch'] === 'instance') {
        if ($args === []) {
            throw new \RuntimeException("JVM instance foreign `{$op->path}` needs a receiver");
        }
        emitOperand($env, $args[0]);
        $env->c->checkcast($env->cp->class_($resolved['class']));
        $argOffset = 1;
    } elseif ($resolved['dispatch'] === 'constructor') {
        $env->c->new_($env->cp->class_($resolved['class']));
        $env->c->dup();
    }

    $paramArgs = \array_slice($args, $argOffset);
    $packIndex = packArgIndex($desc, $argKinds, $paramArgs);
    if ($packIndex !== null) {
        $last = \count($paramArgs) - 1;
        foreach ($paramArgs as $i => $arg) {
            if ($i === $last) {
                emitPackVarargs($env, $arg, (string) \end($argKinds));

                continue;
            }
            emitOperand($env, $arg);
            unboxForeignArg($env, $argKinds[$i]);
        }
    } else {
        if (\count($paramArgs) < \count($argKinds)) {
            foreach ($paramArgs as $i => $arg) {
                emitOperand($env, $arg);
                unboxForeignArg($env, $argKinds[$i]);
            }
            foreach (\array_slice($argKinds, \count($paramArgs)) as $missing) {
                emitEmptyArray($env, $missing, $op->path);
            }
        } else {
            if (\count($paramArgs) !== \count($argKinds)) {
                throw new \RuntimeException(
                    "JVM foreign `{$op->path}`: arg count " . \count($paramArgs)
                    . ' does not match descriptor ' . $desc,
                );
            }
            foreach ($paramArgs as $i => $arg) {
                emitOperand($env, $arg);
                unboxForeignArg($env, $argKinds[$i]);
            }
        }
    }

    $retSlots = returnStackSlots($desc);
    $argc = countArgSlots($argKinds);

    match ($resolved['dispatch']) {
        'static', 'global' => $env->c->invokestatic(
            $env->cp->methodRef($resolved['class'], $resolved['hostMember'], $desc),
            $argc,
            $retSlots,
        ),
        'instance' => ($env->foreignInterfaces[$resolved['class']] ?? false) || jvmIsInterface($resolved['class'])
            ? $env->c->invokeinterface(
                $env->cp->ifaceMethodRef($resolved['class'], $resolved['hostMember'], $desc),
                $argc,
                $retSlots,
            )
            : $env->c->invokevirtual(
                $env->cp->methodRef($resolved['class'], $resolved['hostMember'], $desc),
                $argc,
                $retSlots,
            ),
        'constructor' => (static function () use ($env, $resolved, $desc, $argc): void {
            $env->c->invokespecial(
                $env->cp->methodRef($resolved['class'], '<init>', $desc),
                $argc,
                false,
            );
        })(),
        default => throw new \RuntimeException("JVM foreign: unsupported dispatch {$resolved['dispatch']}"),
    };

    if ($resolved['dispatch'] === 'constructor') {
        emitForeignIoWrap($env, $op->ioWrap);

        return;
    }

    if ($retSlots === 0) {
        $env->c->aconst_null();
        emitForeignIoWrap($env, $op->ioWrap);

        return;
    }

    $ret = jvmReturnDescriptor($desc);
    if ($ret === 'Ljava/util/stream/Stream;') {
        $env->c->invokestatic(
            $env->cp->methodRef('moggi/rt/RT', 'streamToList', '(Ljava/util/stream/Stream;)Lmoggi/rt/MList;'),
            1,
            true,
        );
        emitForeignIoWrap($env, $op->ioWrap);

        return;
    }

    boxForeignReturn($env, $ret);
    emitForeignIoWrap($env, $op->ioWrap);
}

/**
 * When the resolved descriptor ends in a host array while the declaration
 * supplied every argument, the last operand is a Moggi list to pack: the
 * declaration named no array type (JVM foreign types cannot denote arrays),
 * so a trailing array in the resolved descriptor is always the pack shape.
 *
 * @param list<string> $argKinds descriptor parameters of the resolved call
 * @param list<IR\Operand> $paramArgs
 */
function packArgIndex(string $desc, array $argKinds, array $paramArgs): ?int
{
    if (\count($paramArgs) !== \count($argKinds) || $argKinds === []) {
        return null;
    }
    $last = \end($argKinds);
    if (! \is_string($last) || ! \str_starts_with($last, '[')) {
        return null;
    }

    return \count($paramArgs) - 1;
}

/**
 * Synthesize an empty array for one omitted variadic parameter.
 *
 * @throws \RuntimeException when the missing parameter is not an array (unreachable after
 *   typecheck resolution, which only pads proven-variadic tails)
 */
function emitEmptyArray(EmitEnv $env, string $kind, string $path): void
{
    if (! \str_starts_with($kind, '[') || \str_starts_with($kind, '[[')) {
        throw new \RuntimeException(
            "JVM foreign `{$path}`: cannot synthesize a default for parameter type {$kind}",
        );
    }
    $env->c->iconst(0);
    $component = \substr($kind, 1);
    $atype = ['Z' => 4, 'C' => 5, 'F' => 6, 'D' => 7, 'B' => 8, 'S' => 9, 'I' => 10, 'J' => 11][$component] ?? null;
    if ($atype !== null) {
        $env->c->newarray($atype);

        return;
    }
    $env->c->anewarray($env->cp->class_(\substr($component, 1, -1)));
}

/**
 * Pack a Moggi list operand into a fresh host array (two passes over the
 * `MList` spine: count, allocate, fill). Elements unbox exactly like scalar
 * foreign arguments of the component type.
 */
function emitPackVarargs(EmitEnv $env, IR\Operand $list, string $arrayKind): void
{
    $component = \substr($arrayKind, 1);
    $cursor = $env->freshLocal();
    $arr = $env->freshLocal();
    $idx = $env->freshLocal('int');
    $headRef = $env->cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;');
    $tailRef = $env->cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;');
    $id = $env->freshLabelId();

    emitOperand($env, $list);
    $env->c->astore($cursor);
    $env->c->iconst(0);
    $env->c->istore($idx);
    $env->c->label('varargs_count_' . $id);
    $env->c->noteFrame($env->frameLocals());
    $env->c->aload($cursor);
    $env->c->ifnull('varargs_sized_' . $id);
    $env->c->aload($cursor);
    $env->c->getfield($tailRef);
    $env->c->astore($cursor);
    $env->c->iinc($idx, 1);
    $env->c->goto_('varargs_count_' . $id);
    $env->c->label('varargs_sized_' . $id);
    $env->c->noteFrame($env->frameLocals());
    $env->c->iload($idx);
    $atype = ['Z' => 4, 'C' => 5, 'F' => 6, 'D' => 7, 'B' => 8, 'S' => 9, 'I' => 10, 'J' => 11][$component] ?? null;
    if ($atype !== null) {
        $env->c->newarray($atype);
    } else {
        $env->c->anewarray($env->cp->class_(\substr($component, 1, -1)));
    }
    $env->c->astore($arr);
    emitOperand($env, $list);
    $env->c->astore($cursor);
    $env->c->iconst(0);
    $env->c->istore($idx);
    $env->c->label('varargs_fill_' . $id);
    $env->c->noteFrame($env->frameLocals());
    $env->c->aload($cursor);
    $env->c->ifnull('varargs_done_' . $id);
    $env->c->aload($arr);
    $env->c->iload($idx);
    $env->c->aload($cursor);
    $env->c->getfield($headRef);
    emitPackElement($env, $component);
    $env->c->aload($cursor);
    $env->c->getfield($tailRef);
    $env->c->astore($cursor);
    $env->c->iinc($idx, 1);
    $env->c->goto_('varargs_fill_' . $id);
    $env->c->label('varargs_done_' . $id);
    $env->c->noteFrame($env->frameLocals());
    $env->c->aload($arr);
}

/**
 * Convert one list head (a boxed `Object` under `[arr, idx, head]`) and store
 * it: the stack holds exactly the array, index and converted value.
 */
function emitPackElement(EmitEnv $env, string $component): void
{
    if ($component === 'C') {
        unboxForeignArg($env, 'I');
        $env->c->i2c();
        $env->c->castore();

        return;
    }
    $store = ['J' => 'lastore', 'I' => 'iastore', 'S' => 'sastore', 'B' => 'bastore',
        'D' => 'dastore', 'F' => 'fastore', 'Z' => 'bastore'];
    if (isset($store[$component])) {
        unboxForeignArg($env, $component);
        $env->c->{$store[$component]}();

        return;
    }
    if (\str_starts_with($component, 'L') && \str_ends_with($component, ';')) {
        $internal = \substr($component, 1, -1);
        if ($internal !== 'java/lang/Object') {
            $env->c->checkcast($env->cp->class_($internal));
        }
        $env->c->aastore();

        return;
    }
    throw new \RuntimeException("JVM foreign: cannot pack list elements into `{$component}`");
}

/**
 * Apply IO result wraps after a foreign call (null → Nothing, value → Just, …).
 */
function emitForeignIoWrap(EmitEnv $env, IR\IoWrap $wrap): void
{
    match ($wrap) {
        IR\IoWrap::MaybeString => emitForeignMaybeStringWrap($env),
        IR\IoWrap::Either => throw new \RuntimeException(
            'JVM foreign IoWrap::Either is not implemented; use Moggi try/catch',
        ),
        IR\IoWrap::None => null,
    };
}

/** Host null → Nothing; otherwise Just. */
function emitForeignMaybeStringWrap(EmitEnv $env): void
{
    $tmp = $env->freshLocal();
    $env->c->astore($tmp);
    $id = $env->freshLabelId();
    $just = 'foreign_maybe_just_' . $id;
    $done = 'foreign_maybe_done_' . $id;

    $env->c->aload($tmp);
    $env->c->ifnonnull($just);

    $env->c->ldc($env->cp->string_('Nothing'));
    $env->c->iconst(0);
    $env->c->anewarray($env->cp->class_('java/lang/Object'));
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'),
        2,
        true,
    );
    $env->c->goto_($done);

    $env->c->label($just);
    $env->c->noteFrame($env->frameLocals());
    $env->c->ldc($env->cp->string_('Just'));
    $env->c->iconst(1);
    $env->c->anewarray($env->cp->class_('java/lang/Object'));
    $env->c->dup();
    $env->c->iconst(0);
    $env->c->aload($tmp);
    $env->c->aastore();
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'),
        2,
        true,
    );
    $env->c->label($done);
    $env->c->noteFrame($env->frameLocals(), ['moggi/rt/Con']);
}

/** @param list<string> $kinds */
function countArgSlots(array $kinds): int
{
    $n = 0;
    foreach ($kinds as $k) {
        $n += ($k === 'D' || $k === 'J') ? 2 : 1;
    }

    return $n;
}

function returnStackSlots(string $methodDesc): int
{
    $ret = jvmReturnDescriptor($methodDesc);

    return match ($ret) {
        'V' => 0,
        'J', 'D' => 2,
        default => 1,
    };
}

function unboxForeignArg(EmitEnv $env, string $kind): void
{
    match ($kind) {
        'I' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Number'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Number', 'intValue', '()I'), 0, true);
        })(),
        'B' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Number'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Number', 'intValue', '()I'), 0, true);
            $env->c->i2b();
        })(),
        'S' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Number'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Number', 'intValue', '()I'), 0, true);
            $env->c->i2s();
        })(),
        'Z' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
        })(),
        'D' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Double'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Double', 'doubleValue', '()D'), 0, 2);
        })(),
        'J' => unboxInt($env),
        'F' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Float'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Float', 'floatValue', '()F'), 0, true);
        })(),
        default => (static function () use ($env, $kind): void {
            if (\str_starts_with($kind, 'L') && \str_ends_with($kind, ';')) {
                $internal = \substr($kind, 1, -1);
                if ($internal !== 'java/lang/Object') {
                    $env->c->checkcast($env->cp->class_($internal));
                }
            }
        })(),
    };
}

/**
 * How many operand slots one value of this descriptor occupies.
 *
 * `J` and `D` take two; everything else, including every reference and `void`, takes one or
 * none. The stack accounting asks this rather than assuming one value is one slot.
 */
function valueSlots(string $desc): int
{
    return $desc === 'J' || $desc === 'D' ? 2 : 1;
}

function boxForeignReturn(EmitEnv $env, string $retOrFieldDesc): void
{
    $ret = \str_starts_with($retOrFieldDesc, '(')
        ? jvmReturnDescriptor($retOrFieldDesc)
        : $retOrFieldDesc;

    match ($ret) {
        'I', 'B', 'S', 'C' => (static function () use ($env): void {
            $env->c->i2l();
            boxInt($env);
        })(),
        'Z' => $env->c->invokestatic(
            $env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'),
            1,
            true,
        ),
        'D' => $env->c->invokestatic(
            $env->cp->methodRef('java/lang/Double', 'valueOf', '(D)Ljava/lang/Double;'),
            2,
            1,
        ),
        'F' => $env->c->invokestatic(
            $env->cp->methodRef('java/lang/Float', 'valueOf', '(F)Ljava/lang/Float;'),
            1,
            true,
        ),
        'J' => boxInt($env),
        'V' => $env->c->aconst_null(),
        default => null,
    };
}

/**
 * Emit-only foreign intrinsics — no host member is called.
 *
 * `__cast` re-types a value the host already returned: the Moggi ABI keeps
 * every value as an object ref, so the cast is one `checkcast` and the target
 * comes from the descriptor the signature already inferred.
 */
function emitForeignIntrinsic(EmitEnv $env, IR\ForeignCall $op, string $member, string $desc): void
{
    if ($member !== '__cast') {
        throw new \RuntimeException("JVM foreign `{$op->path}`: unknown intrinsic `{$member}`");
    }
    if (\count($op->args) !== 1) {
        throw new \RuntimeException("JVM foreign `{$op->path}`: __cast needs one argument");
    }

    emitOperand($env, $op->args[0]);

    $ret = jvmReturnDescriptor($desc);
    if ($ret !== 'Ljava/lang/Object;') {
        if (! \str_starts_with($ret, 'L')) {
            throw new \RuntimeException(
                "JVM foreign `{$op->path}`: __cast target `{$ret}` is not a reference type",
            );
        }
        $env->c->checkcast($env->cp->class_(\substr($ret, 1, -1)));
    }

    emitForeignIoWrap($env, $op->ioWrap);
}

