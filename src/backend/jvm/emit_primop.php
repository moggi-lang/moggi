<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\IR;

function emitIntBinopToStack(EmitEnv $env, string $op, IR\Operand $left, IR\Operand $right): void
{
    emitOperand($env, $left);
    unboxInt($env);
    emitOperand($env, $right);
    unboxInt($env);
    match ($op) {
        'intAdd#' => $env->c->ladd(),
        'intSub#' => $env->c->lsub(),
        'intMul#' => $env->c->lmul(),
        'intDiv#' => $env->c->ldiv(),
        default => throw new \RuntimeException("JVM emit: unsupported binop {$op}"),
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
        'doubleAdd#' => $env->c->dadd(),
        'doubleSub#' => $env->c->dsub(),
        'doubleMul#' => $env->c->dmul(),
        'doubleDiv#' => $env->c->ddiv(),
        default => throw new \RuntimeException("JVM emit: unsupported float binop {$op}"),
    };
    boxDouble($env);
}

/** One-argument `java.lang.Math` call on a boxed `Double` (sqrt, exp, sin, ...). */
function emitDoubleUnaryMath(EmitEnv $env, string $method, IR\Operand $arg): void
{
    emitOperand($env, $arg);
    unboxDouble($env);
    $env->c->invokestatic($env->cp->methodRef('java/lang/Math', $method, '(D)D'), 2, 2);
    boxDouble($env);
}

/**
 * Stack: result of `dcmpl` squared — 0 iff the two doubles compare equal.
 *
 * `dcmpl` stands for NaN on the lesser side, so a NaN operand is unequal and
 * `-0.0 == 0.0` holds, both as `base`'s `==##` specifies. Squaring keeps the
 * result in {0,1} because `Boolean.valueOf` reads any non-zero int as true.
 */
function emitDoubleCmpFlag(EmitEnv $env, IR\Operand $left, IR\Operand $right): void
{
    emitOperand($env, $left);
    unboxDouble($env);
    emitOperand($env, $right);
    unboxDouble($env);
    $env->c->opcode(0x97, -3);
    $env->c->dup();
    $env->c->imul();
}

function emitOrderingTagEq(EmitEnv $env, IR\Operand $ord, string $tag): void
{
    emitOperand($env, $ord);
    $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
    $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
    $env->c->ldc($env->cp->string_($tag));
    $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
    $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
}

/**
 * Stack: int cmp (-1/0/1) → Ordering Con.
 *
 * A comparison is an operand and can be emitted while an enclosing argument
 * list is already building its array on the stack; a branch target there would
 * need that whole stack in its StackMapTable frame, so the tag is chosen in the
 * runtime instead of with branches.
 */
function emitCompareIntToOrdering(EmitEnv $env): void
{
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'),
        1,
        true,
    );
}

/** Stack: long left, long right → Ordering Con */
function emitIntCompareToOrdering(EmitEnv $env): void
{
    $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'compare', '(JJ)I'), 4, true);
    emitCompareIntToOrdering($env);
}

function emitOrderingTagNe(EmitEnv $env, IR\Operand $ord, string $tag): void
{
    emitOperand($env, $ord);
    $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
    $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
    $env->c->ldc($env->cp->string_($tag));
    $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
    $env->c->iconst(1);
    $env->c->opcode(0x82, -1);
    $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
}

function emitBitReverse(EmitEnv $env, IR\Operand $arg, int $bits, int $finalMask): void
{
    emitOperand($env, $arg);
    unboxInt($env);
    if ($bits === 64) {
        $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'reverse', '(J)J'), 2, 2);
        boxInt($env);

        return;
    }

    $env->c->l2i();
    $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'reverse', '(I)I'), 1, true);
    if ($bits < 32) {
        $env->c->iconst(32 - $bits);
        $env->c->opcode(0x7c, -1);
    }
    $env->c->i2l();
    $env->c->ldc2_w($env->cp->long((int) $finalMask));
    $env->c->land();
    boxInt($env);
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
            $env->c->dneg();
            boxDouble($env);

            return true;
        case 'doubleAbs#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Math', 'abs', '(D)D'), 2, 2);
            boxDouble($env);

            return true;
        case 'doubleSignum#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Math', 'signum', '(D)D'), 2, 2);
            boxDouble($env);

            return true;
        case 'doubleExp#':
            emitDoubleUnaryMath($env, 'exp', $args[0]);

            return true;
        case 'doubleLog#':
            emitDoubleUnaryMath($env, 'log', $args[0]);

            return true;
        case 'doubleSqrt#':
            emitDoubleUnaryMath($env, 'sqrt', $args[0]);

            return true;
        case 'doubleSin#':
            emitDoubleUnaryMath($env, 'sin', $args[0]);

            return true;
        case 'doubleCos#':
            emitDoubleUnaryMath($env, 'cos', $args[0]);

            return true;
        case 'doubleAsin#':
            emitDoubleUnaryMath($env, 'asin', $args[0]);

            return true;
        case 'doubleAcos#':
            emitDoubleUnaryMath($env, 'acos', $args[0]);

            return true;
        case 'doubleAtan#':
            emitDoubleUnaryMath($env, 'atan', $args[0]);

            return true;
        case 'doubleSinh#':
            emitDoubleUnaryMath($env, 'sinh', $args[0]);

            return true;
        case 'doubleCosh#':
            emitDoubleUnaryMath($env, 'cosh', $args[0]);

            return true;
        case 'intNegate#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->lneg();
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
                $env->c->land();
            } elseif ($op->name === 'intOr#') {
                $env->c->opcode(0x81, -2);
            } elseif ($op->name === 'intXor#') {
                $env->c->opcode(0x83, -2);
            } else {
                $env->c->l2i();
                $env->c->opcode(
                    ['intShiftL#' => 0x79, 'intShiftRA#' => 0x7b, 'intShiftRL#' => 0x7d][$op->name],
                    -1
                );
            }
            boxInt($env);

            return true;
        case 'intNot#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->ldc2_w($env->cp->long(-1));
            $env->c->opcode(0x83, -2);
            boxInt($env);

            return true;
        case 'intPopCnt#':
        case 'intClz#':
        case 'intCtz#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('java/lang/Long', [
                    'intPopCnt#' => 'bitCount',
                    'intClz#' => 'numberOfLeadingZeros',
                    'intCtz#' => 'numberOfTrailingZeros',
                ][$op->name], '(J)I'),
                2,
                1
            );
            $env->c->i2l();
            boxInt($env);

            return true;
        case 'wordFromInt#':
        case 'wordToInt#':
            emitOperand($env, $args[0]);

            return true;
        case 'intAbs#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Math', 'abs', '(J)J'), 2, 2);
            boxInt($env);

            return true;
        case 'intSignum#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'signum', '(J)I'), 2, true);
            $env->c->i2l();
            boxInt($env);

            return true;
        case 'intFromInteger#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            $env->c->invokevirtual(
                $env->cp->methodRef('java/math/BigInteger', 'longValue', '()J'),
                0,
                2,
            );
            boxInt($env);

            return true;
        case 'intToInteger#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('java/math/BigInteger', 'valueOf', '(J)Ljava/math/BigInteger;'),
                2,
                true,
            );

            return true;
        case 'integerFromDigits#':
            $env->c->new_($env->cp->class_('java/math/BigInteger'));
            $env->c->dup();
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->invokespecial(
                $env->cp->methodRef('java/math/BigInteger', '<init>', '(Ljava/lang/String;)V'),
                1,
                false,
            );

            return true;
        case 'doubleFromInteger#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            $env->c->invokevirtual(
                $env->cp->methodRef('java/math/BigInteger', 'doubleValue', '()D'),
                0,
                2,
            );
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
                'word64Add#' => 'add',
                'word64Sub#' => 'subtract',
                'word64Mul#' => 'multiply',
                'word64Quot#' => 'divide',
                'word64Rem#' => 'remainder',
                default => throw new \RuntimeException("Unsupported word64 op: {$op->name}"),
            };
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', $methodName, '(JJ)J'),
                4,
                2,
            );
            boxInt($env);

            return true;
        case 'word64ToInteger#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'toBigInteger', '(J)Ljava/math/BigInteger;'),
                2,
                true,
            );

            return true;
        case 'word64FromInteger#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'fromBigInteger', '(Ljava/math/BigInteger;)J'),
                1,
                2,
            );
            boxInt($env);

            return true;
        case 'word64Show#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'toString', '(J)Ljava/lang/String;'),
                2,
                true,
            );

            return true;

        case 'word8FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->ldc2_w($env->cp->long(0xff));
            $env->c->land();
            boxInt($env);

            return true;
        case 'word8Add#':
        case 'word8Sub#':
        case 'word8Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            match ($op->name) {
                'word8Add#' => $env->c->ladd(),
                'word8Sub#' => $env->c->lsub(),
                default => $env->c->lmul(),
            };
            $env->c->ldc2_w($env->cp->long(0xff));
            $env->c->land();
            boxInt($env);

            return true;
        case 'word16FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->ldc2_w($env->cp->long(0xffff));
            $env->c->land();
            boxInt($env);

            return true;
        case 'word16Add#':
        case 'word16Sub#':
        case 'word16Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            match ($op->name) {
                'word16Add#' => $env->c->ladd(),
                'word16Sub#' => $env->c->lsub(),
                default => $env->c->lmul(),
            };
            $env->c->ldc2_w($env->cp->long(0xffff));
            $env->c->land();
            boxInt($env);

            return true;
        case 'word32FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->ldc2_w($env->cp->long(0xffffffff));
            $env->c->land();
            boxInt($env);

            return true;
        case 'word32Add#':
        case 'word32Sub#':
        case 'word32Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            match ($op->name) {
                'word32Add#' => $env->c->ladd(),
                'word32Sub#' => $env->c->lsub(),
                default => $env->c->lmul(),
            };
            $env->c->ldc2_w($env->cp->long(0xffffffff));
            $env->c->land();
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
            match ($op->name) {
                'int8Add#', 'int16Add#', 'int32Add#' => $env->c->ladd(),
                'int8Sub#', 'int16Sub#', 'int32Sub#' => $env->c->lsub(),
                'int8Mul#', 'int16Mul#', 'int32Mul#' => $env->c->lmul(),
                default => throw new \RuntimeException("Unsupported fixed-width int op: {$op->name}"),
            };
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
            $env->c->d2l();
            boxInt($env);

            return true;
        case 'byteSwap16#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->l2i();
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'reverseBytes', '(I)I'), 1, true);
            $env->c->iconst(16);
            $env->c->opcode(0x7c, -1);
            $env->c->i2l();
            $env->c->ldc2_w($env->cp->long(0xffff));
            $env->c->land();
            boxInt($env);

            return true;
        case 'byteSwap32#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->l2i();
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'reverseBytes', '(I)I'), 1, true);
            $env->c->i2l();
            boxInt($env);

            return true;
        case 'byteSwap64#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'reverseBytes', '(J)J'), 2, 2);
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
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            emitSrcLocValue($env, $op->srcLoc);
            $env->c->invokestatic(
                $env->cp->methodRef(
                    'moggi/rt/RT',
                    'naturalFromInteger',
                    '(Ljava/math/BigInteger;[Ljava/lang/Object;)Ljava/math/BigInteger;',
                ),
                2,
                false,
            );

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
            $env->c->iconst(1);
            $env->c->swap();
            $env->c->isub();
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'doubleNe#':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'doubleCompare#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            emitOperand($env, $args[1]);
            unboxDouble($env);
            $env->c->opcode(0x98, -3);
            emitCompareIntToOrdering($env);

            return true;
        case 'intEq#':
        case 'charEq#':
        case 'word8Eq#':
        case 'word16Eq#':
        case 'word32Eq#':
        case 'wordEq#':
        case 'int8Eq#':
        case 'int16Eq#':
        case 'int32Eq#':
        case 'int64Eq#':
        case 'boolEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'intNe#':
        case 'charNe#':
        case 'word8Ne#':
        case 'word16Ne#':
        case 'word32Ne#':
        case 'wordNe#':
        case 'int8Ne#':
        case 'int16Ne#':
        case 'int32Ne#':
        case 'int64Ne#':
        case 'boolNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

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
            emitOperand($env, $args[0]);
            unboxInt($env);
            $left = $env->freshLocal('long');
            $env->c->lstore($left);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $right = $env->freshLocal('long');
            $env->c->lstore($right);
            $env->c->lload($left);
            $env->c->lload($right);
            emitIntCompareToOrdering($env);

            return true;
        case 'boolCompare#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'compare', '(II)I'), 2, true);
            emitCompareIntToOrdering($env);

            return true;
        case 'boolAnd#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->opcode(0x7e, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'boolOr#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->opcode(0x80, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'boolNot#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

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
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'orderingNe#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'orderingCompare#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->invokestatic($env->cp->methodRef('moggi/rt/RT', 'orderingToInt', '(Lmoggi/rt/Con;)I'), 1, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->invokestatic($env->cp->methodRef('moggi/rt/RT', 'orderingToInt', '(Lmoggi/rt/Con;)I'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'compare', '(II)I'), 2, true);
            emitCompareIntToOrdering($env);

            return true;
        case 'word64Eq#':
        case 'word64Ne#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $eqName = $op->name === 'word64Ne#' ? 'ne' : 'eq';
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', $eqName, '(JJ)Z'),
                4,
                true,
            );
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'wordCompare#':
        case 'word64Compare#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'compare', '(JJ)I'),
                4,
                true,
            );
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'),
                1,
                true,
            );

            return true;
    }

    return false;
}
