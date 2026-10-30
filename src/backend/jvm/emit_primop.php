<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;

require_once __DIR__ . '/../stack/scalar_intrinsics.php';

use Moggi\IR;
use function Moggi\Backend\Stack\ScalarIntrinsics\compareJob;
use function Moggi\Backend\Stack\ScalarIntrinsics\primopJob;

function emitIntBinopToStack(EmitEnv $env, string $op, IR\Operand $left, IR\Operand $right): void
{
    emitOperand($env, $left);
    unboxInt($env);
    emitOperand($env, $right);
    unboxInt($env);
    match ($op) {
        'add' => $env->c->ladd(),
        'sub' => $env->c->lsub(),
        'mul' => $env->c->lmul(),
        'div' => $env->c->ldiv(),
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
        'add' => $env->c->dadd(),
        'sub' => $env->c->dsub(),
        'mul' => $env->c->dmul(),
        'div' => $env->c->ddiv(),
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
function emitCompareToOrdering(EmitEnv $env): void
{
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'),
        1,
        true,
    );
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
                'and' => $env->c->land(),
                'or' => $env->c->opcode(0x81, -2),
                default => $env->c->opcode(0x83, -2),
            };
            boxInt($env);

            return true;

        case 'intShift':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->c->l2i();
            $env->c->opcode(['shl' => 0x79, 'shr' => 0x7b, 'ushr' => 0x7d][$job[1]], -1);
            boxInt($env);

            return true;

        case 'intBitCount':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('java/lang/Long', [
                    'popcnt' => 'bitCount',
                    'clz' => 'numberOfLeadingZeros',
                    'ctz' => 'numberOfTrailingZeros',
                ][$job[1]], '(J)I'),
                2,
                1
            );
            $env->c->i2l();
            boxInt($env);

            return true;

        case 'intFixedBinop':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            match ($job[2]) {
                'add' => $env->c->ladd(),
                'sub' => $env->c->lsub(),
                default => $env->c->lmul(),
            };
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
            match ($job[2]) {
                'add' => $env->c->ladd(),
                'sub' => $env->c->lsub(),
                default => $env->c->lmul(),
            };
            $env->c->ldc2_w($env->cp->long(((1 << (int) $job[1]) - 1)));
            $env->c->land();
            boxInt($env);

            return true;

        case 'wordTruncate':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->ldc2_w($env->cp->long(((1 << (int) $job[1]) - 1)));
            $env->c->land();
            boxInt($env);

            return true;

        case 'word64Binop':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $methodName = match ($job[1]) {
                'add' => 'add',
                'sub' => 'subtract',
                'mul' => 'multiply',
                'quot' => 'divide',
                'rem' => 'remainder',
                default => throw new \RuntimeException("Unsupported word64 op: {$op->name}"),
            };
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', $methodName, '(JJ)J'),
                4,
                2,
            );
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
            $env->c->dneg();
            boxDouble($env);

            return true;

        case 'doubleAbs':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Math', 'abs', '(D)D'), 2, 2);
            boxDouble($env);

            return true;

        case 'doubleSignum':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Math', 'signum', '(D)D'), 2, 2);
            boxDouble($env);

            return true;

        case 'doubleToInt':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->c->d2l();
            boxInt($env);

            return true;

        case 'doubleFromInteger':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            $env->c->invokevirtual(
                $env->cp->methodRef('java/math/BigInteger', 'doubleValue', '()D'),
                0,
                2,
            );
            boxDouble($env);

            return true;

        case 'intNegate':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->lneg();
            boxInt($env);

            return true;

        case 'intNot':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->ldc2_w($env->cp->long(-1));
            $env->c->opcode(0x83, -2);
            boxInt($env);

            return true;

        case 'intAbs':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Math', 'abs', '(J)J'), 2, 2);
            boxInt($env);

            return true;

        case 'intSignum':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'signum', '(J)I'), 2, true);
            $env->c->i2l();
            boxInt($env);

            return true;

        case 'intFromInteger':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            $env->c->invokevirtual(
                $env->cp->methodRef('java/math/BigInteger', 'longValue', '()J'),
                0,
                2,
            );
            boxInt($env);

            return true;

        case 'intToInteger':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('java/math/BigInteger', 'valueOf', '(J)Ljava/math/BigInteger;'),
                2,
                true,
            );

            return true;

        case 'integerFromDigits':
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

        case 'integerToNatural':
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

        case 'word64ToInteger':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'toBigInteger', '(J)Ljava/math/BigInteger;'),
                2,
                true,
            );

            return true;

        case 'word64FromInteger':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'fromBigInteger', '(Ljava/math/BigInteger;)J'),
                1,
                2,
            );
            boxInt($env);

            return true;

        case 'word64Show':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'toString', '(J)Ljava/lang/String;'),
                2,
                true,
            );

            return true;

        case 'byteSwap16':
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

        case 'byteSwap32':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->l2i();
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'reverseBytes', '(I)I'), 1, true);
            $env->c->i2l();
            boxInt($env);

            return true;

        case 'byteSwap64':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'reverseBytes', '(J)J'), 2, 2);
            boxInt($env);

            return true;
    }

    throw new \RuntimeException("JVM emit: no emitter for primitive job {$job[0]}");
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
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;

        case 'objectNe':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;

        case 'word64Equality':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', $job[1] === 'ne' ? 'ne' : 'eq', '(JJ)Z'),
                4,
                true,
            );
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;

        case 'intCompareToOrdering':
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
            $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'compare', '(JJ)I'), 4, true);
            emitCompareToOrdering($env);

            return true;

        case 'wordCompare':
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

        case 'doubleEq':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            $env->c->iconst(1);
            $env->c->swap();
            $env->c->isub();
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;

        case 'doubleNe':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;

        case 'doubleCompare':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            emitOperand($env, $args[1]);
            unboxDouble($env);
            $env->c->opcode(0x98, -3);
            emitCompareToOrdering($env);

            return true;

        case 'boolCompare':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'compare', '(II)I'), 2, true);
            emitCompareToOrdering($env);

            return true;

        case 'boolAnd':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->opcode(0x7e, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;

        case 'boolOr':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->opcode(0x80, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;

        case 'boolNot':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

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
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;

        case 'orderingNe':
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

        case 'orderingCompare':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->invokestatic($env->cp->methodRef('moggi/rt/RT', 'orderingToInt', '(Lmoggi/rt/Con;)I'), 1, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->invokestatic($env->cp->methodRef('moggi/rt/RT', 'orderingToInt', '(Lmoggi/rt/Con;)I'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'compare', '(II)I'), 2, true);
            emitCompareToOrdering($env);

            return true;
    }

    throw new \RuntimeException("JVM emit: no emitter for comparison job {$job[0]}");
}
