<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;
use function Moggi\Backend\DotNet\Il\ilString;

function emitIntBinopToStack(EmitEnv $env, string $op, IR\Operand $left, IR\Operand $right): void
{
    emitOperand($env, $left);
    unboxInt($env);
    emitOperand($env, $right);
    unboxInt($env);
    match ($op) {
        'intAdd#' => $env->m->emit('add'),
        'intSub#' => $env->m->emit('sub'),
        'intMul#' => $env->m->emit('mul'),
        'intDiv#' => $env->m->emit('div'),
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
        'doubleAdd#' => $env->m->emit('add'),
        'doubleSub#' => $env->m->emit('sub'),
        'doubleMul#' => $env->m->emit('mul'),
        'doubleDiv#' => $env->m->emit('div'),
        default => throw new \RuntimeException("DotNet emit: unsupported float binop {$op}"),
    };
    boxDouble($env);
}

/** One-argument `System.Math` call on a boxed `Double` (Sqrt, Exp, Sin, ...). */
function emitDoubleUnaryMath(EmitEnv $env, string $method, IR\Operand $arg): void
{
    emitOperand($env, $arg);
    unboxDouble($env);
    $env->m->emit('call float64 [System.Runtime]System.Math::' . $method . '(float64)');
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
function emitNumericCompareToOrdering(EmitEnv $env, IR\Operand $left, IR\Operand $right, string $localType, callable $unbox): void
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

// --- Operands -----------------------------------------------------------------

/**
 * Whether the called overload proves its last parameter a `params` array.
 *
 * The inspector answer is cached per process, and the signature being
 * emitted was rebuilt verbatim from it at typecheck — so the lookup is an
 * exact string match, never a second inference. Never throws: emission must
 * not die on a metadata question the verifier already answered, and a `false`
 * simply keeps the legacy padding.
 *
 * @param list<string> $sigParams
 */
// --- Intrinsics (full allowlist for platform_* and scalar ops) ----------------

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
    $args = $op->args;
    switch ($op->name) {
        case 'intAdd#':
        case 'intSub#':
        case 'intMul#':
        case 'intDiv#':
            emitIntBinopToStack($env, $op->name, $args[0], $args[1]);

            return true;
        case 'doubleAdd#':
        case 'doubleSub#':
        case 'doubleMul#':
        case 'doubleDiv#':
            emitDoubleBinopToStack($env, $op->name, $args[0], $args[1]);

            return true;
        case 'doubleNegate#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->m->emit('neg');
            boxDouble($env);

            return true;
        case 'doubleAbs#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->m->emit('call float64 [System.Runtime]System.Math::Abs(float64)');
            boxDouble($env);

            return true;
        case 'doubleExp#':
            emitDoubleUnaryMath($env, 'Exp', $args[0]);

            return true;
        case 'doubleLog#':
            emitDoubleUnaryMath($env, 'Log', $args[0]);

            return true;
        case 'doubleSqrt#':
            emitDoubleUnaryMath($env, 'Sqrt', $args[0]);

            return true;
        case 'doubleSin#':
            emitDoubleUnaryMath($env, 'Sin', $args[0]);

            return true;
        case 'doubleCos#':
            emitDoubleUnaryMath($env, 'Cos', $args[0]);

            return true;
        case 'doubleAsin#':
            emitDoubleUnaryMath($env, 'Asin', $args[0]);

            return true;
        case 'doubleAcos#':
            emitDoubleUnaryMath($env, 'Acos', $args[0]);

            return true;
        case 'doubleAtan#':
            emitDoubleUnaryMath($env, 'Atan', $args[0]);

            return true;
        case 'doubleSinh#':
            emitDoubleUnaryMath($env, 'Sinh', $args[0]);

            return true;
        case 'doubleCosh#':
            emitDoubleUnaryMath($env, 'Cosh', $args[0]);

            return true;
        case 'doubleSignum#':
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
        case 'intNegate#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('neg');
            boxInt($env);

            return true;
        case 'intAnd#':
        case 'intOr#':
        case 'intXor#':
        case 'intShiftL#':
        case 'intShiftRA#':
        case 'intShiftRL#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            if ($op->name === 'intAnd#') {
                $env->m->emit('and');
            } elseif ($op->name === 'intOr#') {
                $env->m->emit('or');
            } elseif ($op->name === 'intXor#') {
                $env->m->emit('xor');
            } else {
                $env->m->emit('conv.i4');
                $env->m->emit([
                    'intShiftL#' => 'shl',
                    'intShiftRA#' => 'shr',
                    'intShiftRL#' => 'shr.un',
                ][$op->name]);
            }
            boxInt($env);

            return true;
        case 'intNot#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('not');
            boxInt($env);

            return true;
        case 'intPopCnt#':
        case 'intClz#':
        case 'intCtz#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int32 Moggi.Rt.Word64::' . [
                'intPopCnt#' => 'BitCount',
                'intClz#' => 'CountLeadingZeros',
                'intCtz#' => 'CountTrailingZeros',
            ][$op->name] . '(int64)');
            $env->m->emit('conv.i8');
            boxInt($env);

            return true;
        case 'intAbs#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int64 Moggi.Rt.RT::IntAbs(int64)');
            boxInt($env);

            return true;
        case 'intSignum#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int32 [System.Runtime]System.Math::Sign(int64)');
            $env->m->emit('conv.i8');
            boxInt($env);

            return true;
        case 'intFromInteger#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            $env->m->emit('call int64 Moggi.Rt.Word64::FromBigInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)');
            boxInt($env);

            return true;
        case 'intToInteger#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::op_Implicit(int64)');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');

            return true;
        case 'integerFromDigits#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::Parse(string)');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');

            return true;
        case 'doubleFromInteger#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            $env->m->emit('call float64 [System.Runtime.Numerics]System.Numerics.BigInteger::op_Explicit(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)');
            boxDouble($env);

            return true;
        case 'charToInt#':
        case 'intToChar#':
        case 'word8ToInt#':
        case 'word16ToInt#':
        case 'word32ToInt#':
        case 'word64ToInt#':
        case 'word64FromInt#':
        case 'int8ToInt#':
        case 'int16ToInt#':
        case 'int32ToInt#':
        case 'int64ToInt#':
        case 'int64FromInt#':
        case 'bytesFromString#':
        case 'bytesToString#':
            emitOperand($env, $args[0]);

            return true;
        case 'word8FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            boxInt($env);

            return true;
        case 'int8FromInt#':
        case 'int16FromInt#':
        case 'int32FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitSignExtend($env, $op->name);
            boxInt($env);

            return true;
        case 'int8Add#':
        case 'int8Sub#':
        case 'int8Mul#':
        case 'int16Add#':
        case 'int16Sub#':
        case 'int16Mul#':
        case 'int32Add#':
        case 'int32Sub#':
        case 'int32Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit(match ($op->name) {
                'int8Add#', 'int16Add#', 'int32Add#' => 'add',
                'int8Sub#', 'int16Sub#', 'int32Sub#' => 'sub',
                'int8Mul#', 'int16Mul#', 'int32Mul#' => 'mul',
                default => throw new \RuntimeException("DotNet emit: unsupported fixed-width int op {$op->name}"),
            });
            emitSignExtend($env, $op->name);
            boxInt($env);

            return true;
        case 'int64Add#':
        case 'int64Sub#':
        case 'int64Mul#':
            emitIntBinopToStack($env, 'int' . \substr($op->name, 5), $args[0], $args[1]);

            return true;
        case 'doubleToInt#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->m->emit('conv.i8');
            boxInt($env);

            return true;
        case 'word8Add#':
        case 'word8Sub#':
        case 'word8Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit(match ($op->name) {
                'word8Add#' => 'add',
                'word8Sub#' => 'sub',
                default => 'mul',
            });
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            boxInt($env);

            return true;
        case 'word16FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('ldc.i8 65535');
            $env->m->emit('and');
            boxInt($env);

            return true;
        case 'word16Add#':
        case 'word16Sub#':
        case 'word16Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit(match ($op->name) {
                'word16Add#' => 'add',
                'word16Sub#' => 'sub',
                default => 'mul',
            });
            $env->m->emit('ldc.i8 65535');
            $env->m->emit('and');
            boxInt($env);

            return true;
        case 'word32FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('ldc.i8 4294967295');
            $env->m->emit('and');
            boxInt($env);

            return true;
        case 'word32Add#':
        case 'word32Sub#':
        case 'word32Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit(match ($op->name) {
                'word32Add#' => 'add',
                'word32Sub#' => 'sub',
                default => 'mul',
            });
            $env->m->emit('ldc.i8 4294967295');
            $env->m->emit('and');
            boxInt($env);

            return true;
        case 'word64FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('ldc.i8 18446744073709551615');
            $env->m->emit('and');
            boxInt($env);

            return true;
        case 'word64Add#':
        case 'word64Sub#':
        case 'word64Mul#':
        case 'word64Quot#':
        case 'word64Rem#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $methodName = match ($op->name) {
                'word64Add#' => 'Add',
                'word64Sub#' => 'Subtract',
                'word64Mul#' => 'Multiply',
                'word64Quot#' => 'Divide',
                'word64Rem#' => 'Remainder',
                default => throw new \RuntimeException("Unsupported word64 op: {$op->name}"),
            };
            $env->m->emit('call int64 Moggi.Rt.Word64::' . $methodName . '(int64, int64)');
            boxInt($env);

            return true;
        case 'word64ToInteger#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger Moggi.Rt.Word64::ToBigInteger(int64)');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');

            return true;
        case 'word64FromInteger#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            $env->m->emit('call int64 Moggi.Rt.Word64::FromBigInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)');
            boxInt($env);

            return true;
        case 'word64Show#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call string Moggi.Rt.Word64::ToString(int64)');

            return true;

        case 'wordFromInt#':
        case 'wordToInt#':
            emitOperand($env, $args[0]);

            return true;
        case 'byteSwap16#':
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
        case 'byteSwap32#':
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
        case 'byteSwap64#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int64 Moggi.Rt.Word64::ByteSwap(int64)');
            boxInt($env);

            return true;
        case 'bitReverse8#':
            emitBitReverse($env, $args[0], 8, 255);

            return true;
        case 'bitReverse16#':
            emitBitReverse($env, $args[0], 16, 65535);

            return true;
        case 'bitReverse32#':
            emitBitReverse($env, $args[0], 32, 4294967295);

            return true;
        case 'bitReverse64#':
            emitBitReverse($env, $args[0], 64, -1);

            return true;
        case 'naturalToInteger#':
            emitOperand($env, $args[0]);

            return true;
        case 'integerToNatural#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            emitSrcLocValue($env, $op->srcLoc);
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger Moggi.Rt.RT::NaturalFromInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger, object[])');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');

            return true;
    }

    return false;
}

function emitIntrinsicCompare(EmitEnv $env, IR\Intrinsic $op): bool
{
    $args = $op->args;
    switch ($op->name) {
        case 'doubleEq#':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            invertBool($env);
            $env->m->emit('box bool');

            return true;
        case 'doubleNe#':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            $env->m->emit('box bool');

            return true;
        case 'doubleCompare#':
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
        case 'intEq#':
        case 'charEq#':
        case 'word8Eq#':
        case 'word16Eq#':
        case 'word32Eq#':
        case 'int8Eq#':
        case 'int16Eq#':
        case 'int32Eq#':
        case 'int64Eq#':
        case 'boolEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            $env->m->emit('box bool');

            return true;
        case 'intNe#':
        case 'charNe#':
        case 'word8Ne#':
        case 'word16Ne#':
        case 'word32Ne#':
        case 'int8Ne#':
        case 'int16Ne#':
        case 'int32Ne#':
        case 'int64Ne#':
        case 'boolNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return true;
        case 'intCompare#':
        case 'charCompare#':
        case 'word8Compare#':
        case 'word16Compare#':
        case 'word32Compare#':
        case 'int8Compare#':
        case 'int16Compare#':
        case 'int32Compare#':
        case 'int64Compare#':
            emitNumericCompareToOrdering($env, $args[0], $args[1], 'int64', static function (EmitEnv $env): void {
                unboxInt($env);
            });

            return true;
        case 'boolCompare#':
            emitNumericCompareToOrdering($env, $args[0], $args[1], 'bool', static function (EmitEnv $env): void {
                $env->m->emit('unbox.any bool');
            });

            return true;
        case 'boolAnd#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any bool');
            emitOperand($env, $args[1]);
            $env->m->emit('unbox.any bool');
            $env->m->emit('and');
            $env->m->emit('box bool');

            return true;
        case 'boolOr#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any bool');
            emitOperand($env, $args[1]);
            $env->m->emit('unbox.any bool');
            $env->m->emit('or');
            $env->m->emit('box bool');

            return true;
        case 'boolNot#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any bool');
            invertBool($env);
            $env->m->emit('box bool');

            return true;
        case 'orderingIsLt#':
            emitOrderingTagEq($env, $args[0], 'LT');

            return true;
        case 'orderingIsGt#':
            emitOrderingTagEq($env, $args[0], 'GT');

            return true;
        case 'orderingIsLte#':
            emitOrderingTagNe($env, $args[0], 'GT');

            return true;
        case 'orderingIsGte#':
            emitOrderingTagNe($env, $args[0], 'LT');

            return true;
        case 'orderingEq#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld string Moggi.Rt.Con::tag');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld string Moggi.Rt.Con::tag');
            $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
            $env->m->emit('box bool');

            return true;
        case 'orderingNe#':
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
        case 'orderingCompare#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('call int32 Moggi.Rt.RT::OrderingToInt(class Moggi.Rt.Con)');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('call int32 Moggi.Rt.RT::OrderingToInt(class Moggi.Rt.Con)');
            $env->m->emit('sub');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');

            return true;
        case 'word64Eq#':
        case 'word64Ne#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            if ($op->name === 'word64Ne#') {
                invertBool($env);
            }
            $env->m->emit('box bool');

            return true;
        case 'word64Compare#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit('call int32 Moggi.Rt.Word64::Compare(int64, int64)');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');

            return true;
        case 'wordEq#':
        case 'wordNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            if ($op->name === 'wordNe#') {
                invertBool($env);
            }
            $env->m->emit('box bool');

            return true;
        case 'wordCompare#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit('call int32 Moggi.Rt.Word64::Compare(int64, int64)');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');

            return true;
    }

    return false;
}
