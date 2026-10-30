<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use Moggi\IR;
use function Moggi\Backend\Php\Codegen\emitOperand;
use function Moggi\Backend\Php\Codegen\isLeafOperand;

/**
 * One intrinsic operation as a php expression.
 *
 * The stack backends push from `emitIntrinsic(EmitEnv, IR\Intrinsic)`; this one answers the text, so each
 * family reader returns the expression or null instead of a handled flag. A reader takes the argument
 * expressions already emitted by the caller: they are the same text whichever reader answers.
 */
function emitIntrinsic(EmitEnv $env, IR\Intrinsic $op): string
{
    $argExprs = \array_map(
        static fn (IR\Operand $arg): string => emitOperand($arg, $env),
        $op->args,
    );

    $inner = clone $env;
    $inner->intrinsicArgLeaves = \array_map(
        static fn (IR\Operand $arg): bool => isLeafOperand($arg),
        $op->args,
    );

    $expr = emitIntrinsicPrimop($inner, $op, $argExprs)
        ?? emitIntrinsicCompare($inner, $op, $argExprs)
        ?? emitIntrinsicState($inner, $op, $argExprs)
        ?? emitIntrinsicValue($inner, $op, $argExprs);
    if ($expr !== null) {
        return $expr;
    }

    $name = $op->name;
    $locExpr = ($name === 'error#' || $name === 'exceptionThrow#') ? emitSrcLocValue($op->srcLoc, $inner) : 'null';

    return match ($name) {
        'error#' => '\\Moggi\\throwErrorCall(' . $argExprs[0] . ', ' . $locExpr . ')',
        'exceptionWrap#' => '\\Moggi\\exceptionWrap(' . $argExprs[0] . ', ' . $argExprs[1] . ', ' . $argExprs[2] . ')',
        'exceptionUnwrap#' => '\\Moggi\\exceptionUnwrap(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'exceptionThrow#' => '\\Moggi\\throwSomeException(' . $argExprs[0] . ', ' . $locExpr . ')',
        'exceptionDisplay#' => '\\Moggi\\exceptionDisplay(' . $argExprs[0] . ')',
        'exceptionThrowIo#', 'exceptionCatch#', 'exceptionFinally#' => throw new \InvalidArgumentException(
            "intrinsic `{$name}` must be lowered to IO exception IR",
        ),
        'fix#' => '\\Moggi\\fix(' . $argExprs[0] . ')',
        default => throw new \InvalidArgumentException("unknown intrinsic `{$name}`"),
    };
}

/** PHP helpers emitted once per module when those intrinsics are used. */
function phpHelpersForModule(array $used): string
{
    $helpers = [];

    $any64 = static fn (string ...$names): bool => \count(\array_filter($names, static fn ($n): bool => isset($used[$n]))) > 0;

    if ($any64('intAdd#', 'intSub#', 'intMul#', 'intNegate#', 'intAbs#', 'intFromInteger#', 'word64Add#', 'word64Sub#', 'word64Mul#', 'word64Quot#', 'word64Rem#', 'word64ToInteger#', 'word64FromInteger#', 'word64Show#', 'word64Compare#', 'wordCompare#', 'byteSwap64#', 'bitReverse64#', 'word32Mul#', 'int64Add#', 'int64Sub#', 'int64Mul#')) {
        $helpers[] = 'function moggi_u64_add(int $a, int $b): int { $ao = $a & 0xffffffff; $ah = ($a >> 32) & 0xffffffff; $bo = $b & 0xffffffff; $bh = ($b >> 32) & 0xffffffff; $lo = $ao + $bo; $c = ($lo >> 32) & 0xffffffff; $hi = ($ah + $bh + $c) & 0xffffffff; return ($hi << 32) | ($lo & 0xffffffff); }';
        $helpers[] = 'function moggi_u64_sub(int $a, int $b): int { $ao = $a & 0xffffffff; $ah = ($a >> 32) & 0xffffffff; $bo = $b & 0xffffffff; $bh = ($b >> 32) & 0xffffffff; $lo = $ao - $bo; $bw = $lo < 0 ? 1 : 0; $hi = ($ah - $bh - $bw) & 0xffffffff; return ($hi << 32) | ($lo & 0xffffffff); }';
        $helpers[] = 'function moggi_u64_mul(int $a, int $b): int { $a0 = $a & 0xffff; $a1 = ($a >> 16) & 0xffff; $a2 = ($a >> 32) & 0xffff; $a3 = ($a >> 48) & 0xffff; $b0 = $b & 0xffff; $b1 = ($b >> 16) & 0xffff; $b2 = ($b >> 32) & 0xffff; $b3 = ($b >> 48) & 0xffff; $low = $a0 * $b0; $cy = ($low >> 16) & 0xffff; $m1 = $a0 * $b1 + $a1 * $b0 + $cy; $cy = ($m1 >> 16) & 0xffffffff; $m2 = $a0 * $b2 + $a1 * $b1 + $a2 * $b0 + $cy; $cy = ($m2 >> 16) & 0xffffffff; $hi = $a0 * $b3 + $a1 * $b2 + $a2 * $b1 + $a3 * $b0 + $cy; return (($hi & 0xffff) << 48) | (($m2 & 0xffff) << 32) | (($m1 & 0xffff) << 16) | ($low & 0xffff); }';
        $int64Wrappers = [
            'intAdd#' => 'function moggi_i64_add(int $a, int $b): int { $r = $a + $b; return \\is_int($r) ? $r : moggi_u64_add($a, $b); }',
            'intSub#' => 'function moggi_i64_sub(int $a, int $b): int { $r = $a - $b; return \\is_int($r) ? $r : moggi_u64_sub($a, $b); }',
            'intNegate#' => 'function moggi_i64_negate(int $a): int { $r = -$a; return \\is_int($r) ? $r : moggi_u64_sub(0, $a); }',
            'intAbs#' => 'function moggi_i64_abs(int $a): int { $r = \\abs($a); return \\is_int($r) ? $r : moggi_u64_sub(0, $a); }',
        ];
        foreach ($int64Wrappers as $name => $source) {
            if (isset($used[$name])) {
                $helpers[] = $source;
            }
        }

        if (isset($used['intMul#'])) {
            $helpers[] = 'function moggi_i64_mul(int $a, int $b): int { $r = $a * $b; return \\is_int($r) ? $r : moggi_u64_mul($a, $b); }';
        }
    }

    if ($any64('intFromInteger#', 'word64FromInteger#', 'word64Quot#', 'word64Rem#')) {
        $helpers[] = 'function moggi_wrap_decimal(string $s): int { $neg = $s !== "" && $s[0] === "-"; $d = $neg ? \\substr($s, 1) : $s; if (\\strlen($d) <= 18) { return (int) ($neg ? "-" . $d : $d); } $v = 0; foreach (\\str_split($d, 9) as $chunk) { $v = moggi_u64_add(moggi_u64_mul($v, 10 ** \\strlen($chunk)), (int) $chunk); } return $neg ? moggi_u64_sub(0, $v) : $v; }';
    }

    if ($any64('word64ToInteger#', 'word64Show#', 'word64Quot#', 'word64Rem#', 'word64Compare#', 'wordCompare#')) {
        $helpers[] = 'function moggi_u64_to_str(int $a): string { if ($a >= 0) return (string)$a; return (string)bcadd((string)$a, "18446744073709551616", 0); }';
        $helpers[] = 'function moggi_u64_cmp(int $a, int $b): int { $ah = ($a >> 32) & 0xffffffff; $bh = ($b >> 32) & 0xffffffff; if ($ah < $bh) return -1; if ($ah > $bh) return 1; $ao = $a & 0xffffffff; $bo = $b & 0xffffffff; return $ao <=> $bo; }';
        $helpers[] = 'function moggi_u64_quot(int $a, int $b): int { if ($b === 0) { \\Moggi\\throwErrorCall("divide by zero"); } return moggi_wrap_decimal(bcdiv(moggi_u64_to_str($a), moggi_u64_to_str($b), 0)); }';
        $helpers[] = 'function moggi_u64_rem(int $a, int $b): int { if ($b === 0) { \\Moggi\\throwErrorCall("divide by zero"); } return moggi_wrap_decimal(bcmod(moggi_u64_to_str($a), moggi_u64_to_str($b))); }';
    }

    $anySwap = static fn (string ...$names): bool => \count(\array_filter($names, static fn ($n): bool => isset($used[$n]))) > 0;

    if ($anySwap('byteSwap16#', 'byteSwap32#', 'byteSwap64#', 'bitReverse8#', 'bitReverse16#', 'bitReverse32#', 'bitReverse64#')) {
        $helpers[] = 'function moggi_byte_swap_16(int $x): int { $x = $x & 0xffff; return (($x & 0xff) << 8) | (($x >> 8) & 0xff); }';
        $helpers[] = 'function moggi_byte_swap_32(int $x): int { $x = $x & 0xffffffff; return ((($x & 0x000000ff) << 24) | (($x & 0x0000ff00) << 8) | (($x & 0x00ff0000) >> 8) | (($x >> 24) & 0xff)) & 0xffffffff; }';
        $helpers[] = 'function moggi_byte_swap_64(int $a): int { $lo = $a & 0xffffffff; $hi = ($a >> 32) & 0xffffffff; $revLo = (($lo & 0xff) << 24) | (($lo & 0xff00) << 8) | (($lo >> 8) & 0xff00) | (($lo >> 24) & 0xff); $revHi = (($hi & 0xff) << 24) | (($hi & 0xff00) << 8) | (($hi >> 8) & 0xff00) | (($hi >> 24) & 0xff); return (($revLo & 0xffffffff) << 32) | ($revHi & 0xffffffff); }';
        $helpers[] = 'function moggi_bit_reverse_n(int $x, int $bits, int $mask): int { $r = 0; for ($i = 0; $i < $bits; $i++) { if (($x & (1 << $i)) !== 0) { $r |= (1 << ($bits - 1 - $i)); } } return $r & $mask; }';
        $helpers[] = 'function moggi_bit_reverse_8(int $x): int { return moggi_bit_reverse_n($x, 8, 0xff); }';
        $helpers[] = 'function moggi_bit_reverse_16(int $x): int { return moggi_bit_reverse_n($x, 16, 0xffff); }';
        $helpers[] = 'function moggi_bit_reverse_32(int $x): int { return moggi_bit_reverse_n($x & 0xffffffff, 32, 0xffffffff); }';
        $helpers[] = 'function moggi_bit_reverse_64(int $a): int { $lo = $a & 0xffffffff; $hi = ($a >> 32) & 0xffffffff; $rlo = moggi_bit_reverse_n($lo, 32, 0xffffffff); $rhi = moggi_bit_reverse_n($hi, 32, 0xffffffff); return ($rlo << 32) | $rhi; }';
    }

    return \count($helpers) > 0 ? \implode("\n", $helpers) . "\n" : '';
}
