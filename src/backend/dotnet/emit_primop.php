<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;

require_once __DIR__ . '/../stack/scalar_intrinsics.php';

use Moggi\IR;
use function Moggi\Backend\DotNet\Il\ilString;
use function Moggi\Backend\Stack\ScalarIntrinsics\compareJob;
use function Moggi\Backend\Stack\ScalarIntrinsics\primopJob;

function emitIntBinopToStack(EmitEnv $env, string $op, IR\Operand $left, IR\Operand $right): void
{
    emitOperand($env, $left);
    unboxInt($env);
    emitOperand($env, $right);
    unboxInt($env);
    match ($op) {
        'add' => $env->m->emit('add'),
        'sub' => $env->m->emit('sub'),
        'mul' => $env->m->emit('mul'),
        'div' => $env->m->emit('div'),
        default => throw new \RuntimeException("DotNet emit: unsupported binop {$op}"),
    };
    boxInt($env);
}

function emitDoubleBinopToStack(EmitEnv $env, string $op, IR\Operand $left, IR\Operand $right): void
{
    emitOperand($env, $left);
    unboxDouble($env);
    emitOperand($env, $right);
    unboxDouble($env);
    match ($op) {
        'add' => $env->m->emit('add'),
        'sub' => $env->m->emit('sub'),
        'mul' => $env->m->emit('mul'),
        'div' => $env->m->emit('div'),
        default => throw new \RuntimeException("DotNet emit: unsupported float binop {$op}"),
    };
    boxDouble($env);
}

/** One-argument `System.Math` call on a boxed `Double` (sqrt, exp, sin, ...). */
function emitDoubleUnaryMath(EmitEnv $env, string $method, IR\Operand $arg): void
{
    emitOperand($env, $arg);
    unboxDouble($env);
    $env->m->emit('call float64 [System.Runtime]System.Math::' . \ucfirst($method) . '(float64)');
    boxDouble($env);
}

/** int32 0/1 boolean on the stack → its logical negation (still int32 0/1). */
function invertBool(EmitEnv $env): void
{
    $env->m->emit('ldc.i4.0');
    $env->m->emit('ceq');
}

/**
 * Stack: int 0/1 — 1 iff the two doubles are *not* IEEE-equal.
 *
 * CIL's `ceq` is unordered for NaN, so a NaN operand counts as different and
 * `-0.0` equals `0.0`, both as `base`'s `==##` specifies. `Boolean.valueOf`
 * reads any non-zero int as true, so the flag stays in {0,1}.
 */
function emitDoubleCmpFlag(EmitEnv $env, IR\Operand $left, IR\Operand $right): void
{
    emitOperand($env, $left);
    unboxDouble($env);
    emitOperand($env, $right);
    unboxDouble($env);
    $env->m->emit('ceq');
    invertBool($env);
}

/**
 * 3-way numeric compare → Ordering Con, without evaluating either operand
 * twice (CIL has no `swap`/duplicate-below-top, so both sides are staged
 * through fresh locals first).
 *
 * @param callable(EmitEnv): void $unbox
 */
function emitCompareToOrdering(EmitEnv $env, IR\Operand $left, IR\Operand $right, string $localType, callable $unbox): void
{
    emitOperand($env, $left);
    $unbox($env);
    $l = $env->freshLocal($localType);
    $env->m->store($l);
    emitOperand($env, $right);
    $unbox($env);
    $r = $env->freshLocal($localType);
    $env->m->store($r);
    $env->m->load($l);
    $env->m->load($r);
    $env->m->emit('cgt');
    $env->m->load($l);
    $env->m->load($r);
    $env->m->emit('clt');
    $env->m->emit('sub');
    $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');
}

/** Ordering Con `tag` string compared against a literal, raw int32 0/1 on the stack. */
function emitOrderingTagEqRaw(EmitEnv $env, IR\Operand $ord, string $tag): void
{
    emitOperand($env, $ord);
    $env->m->emit('castclass Moggi.Rt.Con');
    $env->m->emit('ldfld string Moggi.Rt.Con::tag');
    $env->m->emit('ldstr ' . ilString($tag));
    $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
}

function emitOrderingTagEq(EmitEnv $env, IR\Operand $ord, string $tag): void
{
    emitOrderingTagEqRaw($env, $ord, $tag);
    $env->m->emit('box bool');
}

function emitOrderingTagNe(EmitEnv $env, IR\Operand $ord, string $tag): void
{
    emitOrderingTagEqRaw($env, $ord, $tag);
    invertBool($env);
    $env->m->emit('box bool');
}

function emitBitReverse(EmitEnv $env, IR\Operand $arg, int $bits, int $finalMask): void
{
    emitOperand($env, $arg);
    $env->m->emit('call int64 Moggi.Rt.RT::UnboxInt(object)');
    $x = $env->freshLocal('int64');
    $env->m->store($x);
    $r = $env->freshLocal('int64');
    $env->m->emit('ldc.i8 0');
    $env->m->store($r);
    $i = $env->freshLocal('int32');
    $env->m->emit('ldc.i4.0');
    $env->m->store($i);
    $id = $env->freshLabelId();
    $loop = 'bitrev_loop_' . $id;
    $cond = 'bitrev_cond_' . $id;
    $env->m->emit('br ' . $cond);
    $env->m->label($loop);
    $env->m->load($r);
    $env->m->emit('ldc.i4.1');
    $env->m->emit('shl');
    $env->m->load($x);
    $env->m->emit('ldc.i8 1');
    $env->m->emit('and');
    $env->m->emit('or');
    $env->m->store($r);
    $env->m->load($x);
    $env->m->emit('ldc.i4.1');
    $env->m->emit('shr.un');
    $env->m->store($x);
    $env->m->load($i);
    $env->m->emit('ldc.i4.1');
    $env->m->emit('add');
    $env->m->store($i);
    $env->m->label($cond);
    $env->m->load($i);
    $env->m->emit('ldc.i4 ' . $bits);
    $env->m->emit('blt ' . $loop);
    $env->m->load($r);
    $env->m->emit('ldc.i8 ' . $finalMask);
    $env->m->emit('and');
    $env->m->emit('call object Moggi.Rt.RT::BoxInt(int64)');
}

function emitIntrinsicPrimop(EmitEnv $env, IR\Intrinsic $op): bool
{
    $job = primopJob($op->name);
    if ($job === null) {
        return false;
    }

    $args = $op->args;
    switch ($job[0]) {
        case 'intBinop':
            emitIntBinopToStack($env, $job[1], $args[0], $args[1]);

            return true;

        case 'doubleBinop':
            emitDoubleBinopToStack($env, $job[1], $args[0], $args[1]);

            return true;

        case 'doubleUnaryMath':
            emitDoubleUnaryMath($env, $job[1], $args[0]);

            return true;

        case 'intBitop':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            match ($job[1]) {
                'and' => $env->m->emit('and'),
                'or' => $env->m->emit('or'),
                default => $env->m->emit('xor'),
            };
            boxInt($env);

            return true;

        case 'intShift':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit('conv.i4');
            $env->m->emit(['shl' => 'shl', 'shr' => 'shr', 'ushr' => 'shr.un'][$job[1]]);
            boxInt($env);

            return true;

        case 'intBitCount':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int32 Moggi.Rt.Word64::' . [
                'popcnt' => 'BitCount',
                'clz' => 'CountLeadingZeros',
                'ctz' => 'CountTrailingZeros',
            ][$job[1]] . '(int64)');
            $env->m->emit('conv.i8');
            boxInt($env);

            return true;

        case 'intFixedBinop':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit(match ($job[2]) {
                'add' => 'add',
                'sub' => 'sub',
                default => 'mul',
            });
            emitSignExtend($env, (int) $job[1]);
            boxInt($env);

            return true;

        case 'intTruncate':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitSignExtend($env, (int) $job[1]);
            boxInt($env);

            return true;

        case 'wordBinop':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit(match ($job[2]) {
                'add' => 'add',
                'sub' => 'sub',
                default => 'mul',
            });
            $env->m->emit('ldc.i8 ' . ((1 << (int) $job[1]) - 1));
            $env->m->emit('and');
            boxInt($env);

            return true;

        case 'wordTruncate':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('ldc.i8 ' . ((1 << (int) $job[1]) - 1));
            $env->m->emit('and');
            boxInt($env);

            return true;

        case 'word64Binop':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $methodName = match ($job[1]) {
                'add' => 'Add',
                'sub' => 'Subtract',
                'mul' => 'Multiply',
                'quot' => 'Divide',
                'rem' => 'Remainder',
                default => throw new \RuntimeException("Unsupported word64 op: {$op->name}"),
            };
            $env->m->emit('call int64 Moggi.Rt.Word64::' . $methodName . '(int64, int64)');
            boxInt($env);

            return true;

        case 'bitReverse':
            $bits = (int) $job[1];
            emitBitReverse($env, $args[0], $bits, $bits === 64 ? -1 : (1 << $bits) - 1);

            return true;

        case 'identity':
            emitOperand($env, $args[0]);

            return true;

        case 'naturalToInteger':
            emitOperand($env, $args[0]);

            return true;

        case 'doubleNegate':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->m->emit('neg');
            boxDouble($env);

            return true;

        case 'doubleAbs':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->m->emit('call float64 [System.Runtime]System.Math::Abs(float64)');
            boxDouble($env);

            return true;

        case 'doubleSignum':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $x = $env->freshLocal('float64');
            $env->m->store($x);
            $id = $env->freshLabelId();
            $positive = 'double_signum_pos_' . $id;
            $negative = 'double_signum_neg_' . $id;
            $done = 'double_signum_done_' . $id;
            $env->m->load($x);
            $env->m->emit('ldc.r8 0.0');
            $env->m->emit('bgt ' . $positive);
            $env->m->load($x);
            $env->m->emit('ldc.r8 0.0');
            $env->m->emit('blt ' . $negative);
            $env->m->load($x);
            $env->m->emit('br ' . $done);
            $env->m->label($positive);
            $env->m->emit('ldc.r8 1.0');
            $env->m->emit('br ' . $done);
            $env->m->label($negative);
            $env->m->emit('ldc.r8 -1.0');
            $env->m->label($done);
            boxDouble($env);

            return true;

        case 'doubleToInt':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->m->emit('conv.i8');
            boxInt($env);

            return true;

        case 'doubleFromInteger':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            $env->m->emit('call float64 [System.Runtime.Numerics]System.Numerics.BigInteger::op_Explicit(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)');
            boxDouble($env);

            return true;

        case 'intNegate':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('neg');
            boxInt($env);

            return true;

        case 'intNot':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('not');
            boxInt($env);

            return true;

        case 'intAbs':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int64 Moggi.Rt.RT::IntAbs(int64)');
            boxInt($env);

            return true;

        case 'intSignum':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int32 [System.Runtime]System.Math::Sign(int64)');
            $env->m->emit('conv.i8');
            boxInt($env);

            return true;

        case 'intFromInteger':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            $env->m->emit('call int64 Moggi.Rt.Word64::FromBigInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)');
            boxInt($env);

            return true;

        case 'intToInteger':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::op_Implicit(int64)');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');

            return true;

        case 'integerFromDigits':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::Parse(string)');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');

            return true;

        case 'integerToNatural':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            emitSrcLocValue($env, $op->srcLoc);
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger Moggi.Rt.RT::NaturalFromInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger, object[])');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');

            return true;

        case 'word64ToInteger':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger Moggi.Rt.Word64::ToBigInteger(int64)');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');

            return true;

        case 'word64FromInteger':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            $env->m->emit('call int64 Moggi.Rt.Word64::FromBigInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)');
            boxInt($env);

            return true;

        case 'word64Show':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call string Moggi.Rt.Word64::ToString(int64)');

            return true;

        case 'byteSwap16':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $x = $env->freshLocal('int64');
            $env->m->store($x);
            $env->m->load($x);
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('ldc.i4.s 8');
            $env->m->emit('shl');
            $env->m->load($x);
            $env->m->emit('ldc.i4.s 8');
            $env->m->emit('shr.un');
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('or');
            $env->m->emit('ldc.i8 65535');
            $env->m->emit('and');
            boxInt($env);

            return true;

        case 'byteSwap32':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $x = $env->freshLocal('int64');
            $env->m->store($x);
            $env->m->load($x);
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('ldc.i4.s 24');
            $env->m->emit('shl');
            $env->m->load($x);
            $env->m->emit('ldc.i4.s 8');
            $env->m->emit('shr.un');
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('ldc.i4.s 16');
            $env->m->emit('shl');
            $env->m->emit('or');
            $env->m->load($x);
            $env->m->emit('ldc.i4.s 16');
            $env->m->emit('shr.un');
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('ldc.i4.s 8');
            $env->m->emit('shl');
            $env->m->emit('or');
            $env->m->load($x);
            $env->m->emit('ldc.i4.s 24');
            $env->m->emit('shr.un');
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('or');
            $env->m->emit('ldc.i8 4294967295');
            $env->m->emit('and');
            boxInt($env);

            return true;

        case 'byteSwap64':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int64 Moggi.Rt.Word64::ByteSwap(int64)');
            boxInt($env);

            return true;
    }

    throw new \RuntimeException("DotNet emit: no emitter for primitive job {$job[0]}");
}

function emitIntrinsicCompare(EmitEnv $env, IR\Intrinsic $op): bool
{
    $job = compareJob($op->name);
    if ($job === null) {
        return false;
    }

    $args = $op->args;
    switch ($job[0]) {
        case 'objectEq':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            $env->m->emit('box bool');

            return true;

        case 'objectNe':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return true;

        case 'word64Equality':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            if ($job[1] === 'ne') {
                invertBool($env);
            }
            $env->m->emit('box bool');

            return true;

        case 'intCompareToOrdering':
            emitCompareToOrdering($env, $args[0], $args[1], 'int64', static function (EmitEnv $env): void {
                unboxInt($env);
            });

            return true;

        case 'wordCompare':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit('call int32 Moggi.Rt.Word64::Compare(int64, int64)');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');

            return true;

        case 'doubleEq':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            invertBool($env);
            $env->m->emit('box bool');

            return true;

        case 'doubleNe':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            $env->m->emit('box bool');

            return true;

        case 'doubleCompare':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $l = $env->freshLocal('float64');
            $env->m->store($l);
            emitOperand($env, $args[1]);
            unboxDouble($env);
            $r = $env->freshLocal('float64');
            $env->m->store($r);
            $env->m->emit('ldc.i4.1');
            $env->m->load($l);
            $env->m->load($r);
            $env->m->emit('clt');
            $env->m->emit('ldc.i4.2');
            $env->m->emit('mul');
            $env->m->emit('sub');
            $env->m->load($l);
            $env->m->load($r);
            $env->m->emit('ceq');
            $env->m->emit('sub');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');

            return true;

        case 'boolCompare':
            emitCompareToOrdering($env, $args[0], $args[1], 'bool', static function (EmitEnv $env): void {
                $env->m->emit('unbox.any bool');
            });

            return true;

        case 'boolAnd':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any bool');
            emitOperand($env, $args[1]);
            $env->m->emit('unbox.any bool');
            $env->m->emit('and');
            $env->m->emit('box bool');

            return true;

        case 'boolOr':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any bool');
            emitOperand($env, $args[1]);
            $env->m->emit('unbox.any bool');
            $env->m->emit('or');
            $env->m->emit('box bool');

            return true;

        case 'boolNot':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any bool');
            invertBool($env);
            $env->m->emit('box bool');

            return true;

        case 'orderingTag':
            if ($job[1] === 'ne') {
                emitOrderingTagNe($env, $args[0], $job[2]);
            } else {
                emitOrderingTagEq($env, $args[0], $job[2]);
            }

            return true;

        case 'orderingEq':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld string Moggi.Rt.Con::tag');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld string Moggi.Rt.Con::tag');
            $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
            $env->m->emit('box bool');

            return true;

        case 'orderingNe':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld string Moggi.Rt.Con::tag');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld string Moggi.Rt.Con::tag');
            $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
            invertBool($env);
            $env->m->emit('box bool');

            return true;

        case 'orderingCompare':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('call int32 Moggi.Rt.RT::OrderingToInt(class Moggi.Rt.Con)');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('call int32 Moggi.Rt.RT::OrderingToInt(class Moggi.Rt.Con)');
            $env->m->emit('sub');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');

            return true;
    }

    throw new \RuntimeException("DotNet emit: no emitter for comparison job {$job[0]}");
}
