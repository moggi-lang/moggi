<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use Moggi\IR;

function stringCompareExpr(string $left, string $right): string
{
    return "match ({$left} <=> {$right}) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }";
}

function intCompareExpr(string $left, string $right): string
{
    return "match ({$left} <=> {$right}) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }";
}

function doubleCompareExpr(string $left, string $right): string
{
    return "match ({$left} <=> {$right}) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }";
}

function boolCompareExpr(string $left, string $right): string
{
    return "match (({$left} ? 1 : 0) <=> ({$right} ? 1 : 0)) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }";
}

function intSignumExpr(string $expr): string
{
    return "match ({$expr} <=> 0) { -1 => -1, 0 => 0, 1 => 1 }";
}

function doubleSignumExpr(string $expr): string
{
    return "(({$expr}) > 0.0 ? 1.0 : (({$expr}) < 0.0 ? -1.0 : ({$expr})))";
}

function orderingTagExpr(string $expr): string
{
    return "(($expr)[0] ?? null)";
}

function orderingEqExpr(string $left, string $right): string
{
    return '(' . orderingTagExpr($left) . ' === ' . orderingTagExpr($right) . ')';
}

function orderingNeExpr(string $left, string $right): string
{
    return '(' . orderingTagExpr($left) . ' !== ' . orderingTagExpr($right) . ')';
}

function orderingCompareExpr(string $left, string $right): string
{
    return "match ((['LT' => 0, 'EQ' => 1, 'GT' => 2][" . orderingTagExpr($left) . "] ?? 1) <=> (['LT' => 0, 'EQ' => 1, 'GT' => 2][" . orderingTagExpr($right) . "] ?? 1)) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }";
}

function listCompareExpr(string $left, string $right): string
{
    return "match ({$left} <=> {$right}) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }";
}

function maybeCompareExpr(string $left, string $right): string
{
    $leftTag = "(($left)[0] ?? null)";
    $rightTag = "(($right)[0] ?? null)";

    return 'match (true) {'
        . " ({$leftTag} === 'Nothing' && {$rightTag} === 'Nothing') => ['EQ'],"
        . " ({$leftTag} === 'Nothing') => ['LT'],"
        . " ({$rightTag} === 'Nothing') => ['GT'],"
        . " default => match (({$left}[1] ?? null) <=> ({$right}[1] ?? null)) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }"
        . ' }';
}

/** @param list<string> $argExprs */
function emitIntrinsicPrimop(EmitEnv $env, IR\Intrinsic $op, array $argExprs): ?string
{
    $argLeaves = $env->intrinsicArgLeaves;
    $locExpr = $op->name === 'integerToNatural#' ? emitSrcLocValue($op->srcLoc, $env) : 'null';

    return match ($op->name) {
        'charToInt#' => $argExprs[0],
        'intToChar#' => $argExprs[0],
        'word8ToInt#' => $argExprs[0],
        'word8FromInt#' => '((' . $argExprs[0] . ') & 0xff)',
        'word8Add#' => '(((' . $argExprs[0] . ' + ' . $argExprs[1] . ') & 0xff))',
        'word8Sub#' => '(((' . $argExprs[0] . ' - ' . $argExprs[1] . ') & 0xff))',
        'word8Mul#' => '(((' . $argExprs[0] . ' * ' . $argExprs[1] . ') & 0xff))',
        'word16ToInt#' => $argExprs[0],
        'word16FromInt#' => '((' . $argExprs[0] . ') & 0xffff)',
        'word16Add#' => '(((' . $argExprs[0] . ' + ' . $argExprs[1] . ') & 0xffff))',
        'word16Sub#' => '(((' . $argExprs[0] . ' - ' . $argExprs[1] . ') & 0xffff))',
        'word16Mul#' => '(((' . $argExprs[0] . ' * ' . $argExprs[1] . ') & 0xffff))',
        'word32ToInt#' => $argExprs[0],
        'word32FromInt#' => '((' . $argExprs[0] . ') & 0xffffffff)',
        'word32Add#' => '(((' . $argExprs[0] . ' + ' . $argExprs[1] . ') & 0xffffffff))',
        'word32Sub#' => '(((' . $argExprs[0] . ' - ' . $argExprs[1] . ') & 0xffffffff))',
        'word32Mul#' => '(moggi_u64_mul(' . $argExprs[0] . ', ' . $argExprs[1] . ') & 0xffffffff)',
        'word64ToInt#' => $argExprs[0],
        'word64FromInt#' => $argExprs[0],
        'word64Add#' => "moggi_u64_add({$argExprs[0]}, {$argExprs[1]})",
        'word64Sub#' => "moggi_u64_sub({$argExprs[0]}, {$argExprs[1]})",
        'word64Mul#' => "moggi_u64_mul({$argExprs[0]}, {$argExprs[1]})",
        'word64Quot#' => "moggi_u64_quot({$argExprs[0]}, {$argExprs[1]})",
        'word64Rem#' => "moggi_u64_rem({$argExprs[0]}, {$argExprs[1]})",
        'byteSwap16#' => 'moggi_byte_swap_16(' . $argExprs[0] . ')',
        'byteSwap32#' => 'moggi_byte_swap_32(' . $argExprs[0] . ')',
        'byteSwap64#' => 'moggi_byte_swap_64(' . $argExprs[0] . ')',
        'bitReverse8#' => 'moggi_bit_reverse_8(' . $argExprs[0] . ')',
        'bitReverse16#' => 'moggi_bit_reverse_16(' . $argExprs[0] . ')',
        'bitReverse32#' => 'moggi_bit_reverse_32(' . $argExprs[0] . ')',
        'bitReverse64#' => 'moggi_bit_reverse_64(' . $argExprs[0] . ')',
        'word64ToInteger#' => "moggi_u64_to_str({$argExprs[0]})",
        'word64FromInteger#' => "moggi_wrap_decimal({$argExprs[0]})",
        'word64Show#' => "moggi_u64_to_str({$argExprs[0]})",
        'wordToInt#' => $argExprs[0],
        'wordFromInt#' => $argExprs[0],
        'intAdd#' => intWrapExpr('moggi_i64_add', 'moggi_u64_add', '+', $argExprs, $argLeaves),
        'intSub#' => intWrapExpr('moggi_i64_sub', 'moggi_u64_sub', '-', $argExprs, $argLeaves),
        'intMul#' => intWrapExpr('moggi_i64_mul', 'moggi_u64_mul', '*', $argExprs, $argLeaves),
        'intDiv#' => '\\intdiv(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'intNegate#' => "moggi_i64_negate({$argExprs[0]})",
        'intAbs#' => "moggi_i64_abs({$argExprs[0]})",
        'intSignum#' => intSignumExpr($argExprs[0]),
        'intFromInteger#' => 'moggi_wrap_decimal((string) ' . $argExprs[0] . ')',
        'intToInteger#' => '\\strval(' . $argExprs[0] . ')',
        'integerFromDigits#' => $argExprs[0],
        'naturalToInteger#' => $argExprs[0],
        'integerToNatural#' => "(function (\$v) { if (\$v !== '' && \$v[0] === '-') { \\Moggi\\throwErrorCall('arithmetic underflow', {$locExpr}); } return \$v; })({$argExprs[0]})",
        'intAnd#' => "({$argExprs[0]} & {$argExprs[1]})",
        'intOr#' => "({$argExprs[0]} | {$argExprs[1]})",
        'intXor#' => "({$argExprs[0]} ^ {$argExprs[1]})",
        'intNot#' => "(~{$argExprs[0]})",
        'intShiftL#' => "({$argExprs[0]} << {$argExprs[1]})",
        'intShiftRA#' => "({$argExprs[0]} >> {$argExprs[1]})",
        'intShiftRL#' => "(({$argExprs[0]} >> {$argExprs[1]}) & ~(-1 << (64 - {$argExprs[1]})))",
        'intPopCnt#' => '\\substr_count(\\decbin(' . $argExprs[0] . '), "1")',
        'intClz#' => '(' . $argExprs[0] . ' === 0 ? 64 : 64 - \\strlen(\\decbin(' . $argExprs[0] . ')))',
        'intCtz#' => '(' . $argExprs[0] . ' === 0 ? 64 : \\strspn(\\strrev(\\decbin(' . $argExprs[0] . ')), "0"))',
        'int8ToInt#' => $argExprs[0],
        'int8FromInt#' => '(((' . $argExprs[0] . ') << 56) >> 56)',
        'int8Add#' => '((((' . $argExprs[0] . ' + ' . $argExprs[1] . ') << 56) >> 56))',
        'int8Sub#' => '((((' . $argExprs[0] . ' - ' . $argExprs[1] . ') << 56) >> 56))',
        'int8Mul#' => '((((' . $argExprs[0] . ' * ' . $argExprs[1] . ') << 56) >> 56))',
        'int16ToInt#' => $argExprs[0],
        'int16FromInt#' => '(((' . $argExprs[0] . ') << 48) >> 48)',
        'int16Add#' => '((((' . $argExprs[0] . ' + ' . $argExprs[1] . ') << 48) >> 48))',
        'int16Sub#' => '((((' . $argExprs[0] . ' - ' . $argExprs[1] . ') << 48) >> 48))',
        'int16Mul#' => '((((' . $argExprs[0] . ' * ' . $argExprs[1] . ') << 48) >> 48))',
        'int32ToInt#' => $argExprs[0],
        'int32FromInt#' => '(((' . $argExprs[0] . ') << 32) >> 32)',
        'int32Add#' => '((((' . $argExprs[0] . ' + ' . $argExprs[1] . ') << 32) >> 32))',
        'int32Sub#' => '((((' . $argExprs[0] . ' - ' . $argExprs[1] . ') << 32) >> 32))',
        'int32Mul#' => '((((' . $argExprs[0] . ' * ' . $argExprs[1] . ') << 32) >> 32))',
        'int64ToInt#' => $argExprs[0],
        'int64FromInt#' => $argExprs[0],
        'int64Add#' => "moggi_u64_add({$argExprs[0]}, {$argExprs[1]})",
        'int64Sub#' => "moggi_u64_sub({$argExprs[0]}, {$argExprs[1]})",
        'int64Mul#' => "moggi_u64_mul({$argExprs[0]}, {$argExprs[1]})",
        'doubleAdd#' => "({$argExprs[0]} + {$argExprs[1]})",
        'doubleSub#' => "({$argExprs[0]} - {$argExprs[1]})",
        'doubleMul#' => "({$argExprs[0]} * {$argExprs[1]})",
        'doubleDiv#' => '\\fdiv(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'doubleNegate#' => "(-{$argExprs[0]})",
        'doubleAbs#' => '\\abs(' . $argExprs[0] . ')',
        'doubleSignum#' => doubleSignumExpr($argExprs[0]),
        'doubleFromInteger#' => '(float) ' . $argExprs[0],
        'doubleToInt#' => '(int) ' . $argExprs[0],
        'doubleExp#' => '\\exp(' . $argExprs[0] . ')',
        'doubleLog#' => '\\log(' . $argExprs[0] . ')',
        'doubleSqrt#' => '\\sqrt(' . $argExprs[0] . ')',
        'doubleSin#' => '\\sin(' . $argExprs[0] . ')',
        'doubleCos#' => '\\cos(' . $argExprs[0] . ')',
        'doubleAsin#' => '\\asin(' . $argExprs[0] . ')',
        'doubleAcos#' => '\\acos(' . $argExprs[0] . ')',
        'doubleAtan#' => '\\atan(' . $argExprs[0] . ')',
        'doubleSinh#' => '\\sinh(' . $argExprs[0] . ')',
        'doubleCosh#' => '\\cosh(' . $argExprs[0] . ')',
        default => null,
    };
}

/** @param list<string> $argExprs */
function emitIntrinsicCompare(EmitEnv $env, IR\Intrinsic $op, array $argExprs): ?string
{
    return match ($op->name) {
        'charEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'charNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'charCompare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'word8Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'word8Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'word8Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'word16Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'word16Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'word16Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'word32Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'word32Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'word32Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'word64Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'word64Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'word64Compare#' => "match (moggi_u64_cmp({$argExprs[0]}, {$argExprs[1]})) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }",
        'wordEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'wordNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'wordCompare#' => "match (moggi_u64_cmp({$argExprs[0]}, {$argExprs[1]})) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }",
        'intEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'intNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'intCompare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'int8Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'int8Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'int8Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'int16Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'int16Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'int16Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'int32Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'int32Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'int32Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'int64Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'int64Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'int64Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'doubleEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'doubleNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'doubleCompare#' => doubleCompareExpr($argExprs[0], $argExprs[1]),
        'orderingEq#' => orderingEqExpr($argExprs[0], $argExprs[1]),
        'orderingNe#' => orderingNeExpr($argExprs[0], $argExprs[1]),
        'orderingCompare#' => orderingCompareExpr($argExprs[0], $argExprs[1]),
        'orderingIsLt#' => '(' . orderingTagExpr($argExprs[0]) . " === 'LT')",
        'orderingIsLte#' => '(' . orderingTagExpr($argExprs[0]) . " !== 'GT')",
        'orderingIsGt#' => '(' . orderingTagExpr($argExprs[0]) . " === 'GT')",
        'orderingIsGte#' => '(' . orderingTagExpr($argExprs[0]) . " !== 'LT')",
        'boolEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'boolNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'boolCompare#' => boolCompareExpr($argExprs[0], $argExprs[1]),
        'boolAnd#' => "({$argExprs[0]} && {$argExprs[1]})",
        'boolOr#' => "({$argExprs[0]} || {$argExprs[1]})",
        'boolNot#' => "(!{$argExprs[0]})",
        default => null,
    };
}

/**
 * Wrap an overflowing PHP integer op: the raw host op stays inline when both
 * operands are leaves (the common case) and the exact 64-bit helper is only
 * reached when PHP left the int domain. Using the helper unconditionally costs
 * about x3 in PHP call overhead, so the inline form matters; repeating an
 * operand expression is only free when it is a leaf, which is why the nested
 * case keeps the call.
 *
 * @param array<int, string> $args
 * @param array<int, bool> $leaves
 */
function intWrapExpr(string $helper, string $fallback, string $op, array $args, array $leaves): string
{
    $a = $args[0];
    $b = $args[1];
    if (($leaves[0] ?? false) && ($leaves[1] ?? false)) {
        return '(\\is_int($__m64 = ' . $a . ' ' . $op . ' ' . $b . ') ? $__m64 : '
            . $fallback . '(' . $a . ', ' . $b . '))';
    }

    return $helper . '(' . $a . ', ' . $b . ')';
}
