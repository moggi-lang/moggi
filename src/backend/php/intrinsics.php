<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Intrinsics;

use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;
use Moggi\IR\SrcLoc;

use function Moggi\Backend\Php\Naming\phpFunctionName;
use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\Debug\symbolId;

/**
 * @param array<int, string> $argExprs
 * @param array<string, string> $localHelpers
 * @param array<int, bool> $argLeaves whether each argument is a leaf (a temp,
 *   local or literal), so an inlined wrapper can repeat it for free
 */
function emitCall(string $name, array $argExprs, array $localHelpers = [], ?SrcLoc $srcLoc = null, array $ctx = [], array $argLeaves = []): string
{
    if (isset($localHelpers[$name])) {
        return localHelperCall($name, $localHelpers[$name], $argExprs);
    }

    $ctx['intrinsicArgLeaves'] = $argLeaves;

    return emitQualifiedCall($name, $argExprs, $srcLoc, $ctx);
}

function emitSrcLocExpr(?SrcLoc $loc, array $ctx = []): string
{
    if ($loc === null) {
        return 'null';
    }

    $map = $ctx['sourceMap'] ?? null;
    if ($map instanceof SourceMapBuilder) {
        $generated = $loc->function;
        $phpNames = $ctx['phpNames'] ?? [];
        if ($generated !== '' && \is_array($phpNames) && isset($phpNames[$generated])) {
            $generated = phpFunctionName($loc->function, $phpNames);
            $ns = $ctx['moduleNamespace'] ?? null;
            if (\is_string($ns) && $ns !== '') {
                $generated = $ns . '\\' . $generated;
            }
        }
        $site = $map->allocSite($loc, $generated !== '' ? $generated : ($loc->module . '.expr'));

        return '\\Moggi\\throwSite('
            . (int) $site['siteId'] . ', '
            . var_export($site['symbolId'], true) . ', '
            . var_export($site['displayPath'], true) . ', '
            . (int) $site['line'] . ', '
            . (int) $site['col'] . ')';
    }

    // Fallback without a map builder (still compact, no absolute path identity).
    $display = normalizeDisplayPath($loc->file);
    $symbolId = symbolId($loc->module, $loc->function);

    return '\\Moggi\\throwSite(0, '
        . var_export($symbolId, true) . ', '
        . var_export($display, true) . ', '
        . (int) $loc->line . ', '
        . (int) $loc->col . ')';
}

/**
 * The fixed-width `^` primops: helper name, the mask that reduces the width,
 * and the shift that sign-extends the result back (0 for the unsigned ones).
 * The result of the width's own `*` is a value modulo the width, sign-extended
 * when the type is signed.
 *
 * @return array<string, array{helper: string, mask: string, signShift: int}>
 */
function powWidthTable(): array
{
    return [
        'int8Pow#' => ['helper' => 'moggi_i8_pow', 'mask' => '0xff', 'signShift' => 56],
        'int16Pow#' => ['helper' => 'moggi_i16_pow', 'mask' => '0xffff', 'signShift' => 48],
        'int32Pow#' => ['helper' => 'moggi_i32_pow', 'mask' => '0xffffffff', 'signShift' => 32],
        'int64Pow#' => ['helper' => 'moggi_i64_pow', 'mask' => '-1', 'signShift' => 0],
        'word8Pow#' => ['helper' => 'moggi_w8_pow', 'mask' => '0xff', 'signShift' => 0],
        'word16Pow#' => ['helper' => 'moggi_w16_pow', 'mask' => '0xffff', 'signShift' => 0],
        'word32Pow#' => ['helper' => 'moggi_w32_pow', 'mask' => '0xffffffff', 'signShift' => 0],
        'word64Pow#' => ['helper' => 'moggi_w64_pow', 'mask' => '-1', 'signShift' => 0],
    ];
}

/**
 * One fixed-width `^` helper: the library's square-and-multiply, reduced modulo
 * the width at every step, sign-extended once at the end for the signed widths.
 *
 * The multiply is the width's own: a 32- or 64-bit product can pass
 * PHP_INT_MAX, so those go through the split multiply, while an 8- or 16-bit
 * one cannot.
 *
 * @param array{helper: string, mask: string, signShift: int} $width
 */
function powWidthHelperSource(array $width): string
{
    $wide = $width['mask'] === '-1' || $width['mask'] === '0xffffffff';
    $multiply = static fn (string $a, string $b): string => $wide
        ? "(moggi_u64_mul({$a}, {$b}) & {$width['mask']})"
        : "(({$a} * {$b}) & {$width['mask']})";

    $result = $width['signShift'] === 0
        ? '$r'
        : "((\$r << {$width['signShift']}) >> {$width['signShift']})";

    return 'function ' . $width['helper'] . '(int $b, int $e) { $r = 1; $b = $b & ' . $width['mask']
        . '; while ($e !== 0) { if (($e & 1) !== 0) { $r = ' . $multiply('$r', '$b') . '; } $b = '
        . $multiply('$b', '$b') . '; $e >>= 1; } return ' . $result . '; }';
}

/** @param array<int, string> $argExprs */
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

function emitQualifiedCall(string $name, array $argExprs, ?SrcLoc $srcLoc = null, array $ctx = []): string
{
    $argLeaves = $ctx['intrinsicArgLeaves'] ?? [];
    $width = powWidthTable()[$name] ?? null;
    $needsSite = $name === 'error#' || $name === 'exceptionThrow#' || $name === 'intPow#'
        || $width !== null || $name === 'doublePow#';
    $locExpr = $needsSite ? emitSrcLocExpr($srcLoc, $ctx) : 'null';

    // A negative exponent is `base`'s own `ErrorCall`, raised at the `^` expression, so the
    // check sits at the call site and the loop helper cannot fail.
    if ($width !== null) {
        return '((' . $argExprs[1] . ' < 0) ? \\Moggi\\throwErrorCall("Negative exponent", ' . $locExpr
            . ') : ' . $width['helper'] . '(' . $argExprs[0] . ', ' . $argExprs[1] . '))';
    }

    if ($name === 'doublePow#') {
        return '((' . $argExprs[1] . ' < 0) ? \\Moggi\\throwErrorCall("Negative exponent", ' . $locExpr
            . ') : moggi_double_pow(' . $argExprs[0] . ', ' . $argExprs[1] . '))';
    }

    return match ($name) {
        'stringEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'stringNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'stringAppend#' => "({$argExprs[0]} . {$argExprs[1]})",
        'stringCons#' => "(\\IntlChar::chr({$argExprs[0]}) . {$argExprs[1]})",
        'stringCompare#' => stringCompareExpr($argExprs[0], $argExprs[1]),
        'bytesEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'bytesNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'bytesAppend#' => "({$argExprs[0]} . {$argExprs[1]})",
        'bytesCompare#' => stringCompareExpr($argExprs[0], $argExprs[1]),
        'bytesFromString#' => $argExprs[0],
        'bytesToString#' => $argExprs[0],
        'charEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'charNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'charCompare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'charToInt#' => $argExprs[0],
        'intToChar#' => $argExprs[0],
        'word8Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'word8Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'word8Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'word8ToInt#' => $argExprs[0],
        'word8FromInt#' => '((' . $argExprs[0] . ') & 0xff)',
        'word8Add#' => '(((' . $argExprs[0] . ' + ' . $argExprs[1] . ') & 0xff))',
        'word8Sub#' => '(((' . $argExprs[0] . ' - ' . $argExprs[1] . ') & 0xff))',
        'word8Mul#' => '(((' . $argExprs[0] . ' * ' . $argExprs[1] . ') & 0xff))',
        'word16Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'word16Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'word16Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'word16ToInt#' => $argExprs[0],
        'word16FromInt#' => '((' . $argExprs[0] . ') & 0xffff)',
        'word16Add#' => '(((' . $argExprs[0] . ' + ' . $argExprs[1] . ') & 0xffff))',
        'word16Sub#' => '(((' . $argExprs[0] . ' - ' . $argExprs[1] . ') & 0xffff))',
        'word16Mul#' => '(((' . $argExprs[0] . ' * ' . $argExprs[1] . ') & 0xffff))',
        'word32Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'word32Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'word32Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'word32ToInt#' => $argExprs[0],
        'word32FromInt#' => '((' . $argExprs[0] . ') & 0xffffffff)',
        'word32Add#' => '(((' . $argExprs[0] . ' + ' . $argExprs[1] . ') & 0xffffffff))',
        'word32Sub#' => '(((' . $argExprs[0] . ' - ' . $argExprs[1] . ') & 0xffffffff))',
        // Word32 mul can exceed PHP_INT_MAX; wrap via u64 helpers then mask.
        'word32Mul#' => '(moggi_u64_mul(' . $argExprs[0] . ', ' . $argExprs[1] . ') & 0xffffffff)',
        'word64Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'word64Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'word64Compare#' => "match (moggi_u64_cmp({$argExprs[0]}, {$argExprs[1]})) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }",
        'word64ToInt#' => $argExprs[0],
        // Full unsigned 64-bit wrap is host Int width; identity on this backend.
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
        // Machine Word is host Int-width (same representation as Int on PHP).
        'wordEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'wordNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'wordCompare#' => "match (moggi_u64_cmp({$argExprs[0]}, {$argExprs[1]})) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }",
        'wordToInt#' => $argExprs[0],
        'wordFromInt#' => $argExprs[0],
        'intEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'intNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        // Every machine-Int arithmetic op routes through a helper: PHP promotes
        // an overflowing host op to float instead of wrapping, and doing that
        // inline would repeat the operand expressions in the fallback.
        'intAdd#' => intWrapExpr('moggi_i64_add', 'moggi_u64_add', '+', $argExprs, $argLeaves),
        'intSub#' => intWrapExpr('moggi_i64_sub', 'moggi_u64_sub', '-', $argExprs, $argLeaves),
        'intMul#' => intWrapExpr('moggi_i64_mul', 'moggi_u64_mul', '*', $argExprs, $argLeaves),
        // The check is at the call site so the report names the `^` expression, and the multiply
        // is the same one `intMul#` emits.
        'intPow#' => '((' . $argExprs[1] . ' < 0) ? \\Moggi\\throwErrorCall("Negative exponent", ' . $locExpr . ') : moggi_int_pow(' . $argExprs[0] . ', ' . $argExprs[1] . '))',
        'intDiv#' => '\\intdiv(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'intNegate#' => "moggi_i64_negate({$argExprs[0]})",
        'intAbs#' => "moggi_i64_abs({$argExprs[0]})",
        'intSignum#' => intSignumExpr($argExprs[0]),
        'intFromInteger#' => 'moggi_wrap_decimal((string) ' . $argExprs[0] . ')',
        'intToInteger#' => '\\strval(' . $argExprs[0] . ')',
        // The Integer# representation is the decimal string itself, so the
        // digits of an out-of-range literal are the value already.
        'integerFromDigits#' => $argExprs[0],
        // Natural# shares the Integer# representation (a decimal string, which
        // carries no sign of its own); entering the type rejects a negative
        // value, so no Natural can be negative.
        'naturalToInteger#' => $argExprs[0],
        'integerToNatural#' => "(function (\$v) { if (\$v !== '' && \$v[0] === '-') { \\Moggi\\throwErrorCall('arithmetic underflow', {$locExpr}); } return \$v; })({$argExprs[0]})",
        // Bit ops on the i64 pattern. Shift counts are in [0,63] by contract
        // (Data.Bits clamps/masks first), so the native shifts are total here.
        'intAnd#' => "({$argExprs[0]} & {$argExprs[1]})",
        'intOr#' => "({$argExprs[0]} | {$argExprs[1]})",
        'intXor#' => "({$argExprs[0]} ^ {$argExprs[1]})",
        'intNot#' => "(~{$argExprs[0]})",
        'intShiftL#' => "({$argExprs[0]} << {$argExprs[1]})",
        'intShiftRA#' => "({$argExprs[0]} >> {$argExprs[1]})",
        // PHP's >> is arithmetic; clear the sign extension for Word semantics.
        'intShiftRL#' => "(({$argExprs[0]} >> {$argExprs[1]}) & ~(-1 << (64 - {$argExprs[1]})))",
        // decbin() renders negatives as their 64-bit two's complement pattern.
        'intPopCnt#' => '\\substr_count(\\decbin(' . $argExprs[0] . '), "1")',
        'intClz#' => '(' . $argExprs[0] . ' === 0 ? 64 : 64 - \\strlen(\\decbin(' . $argExprs[0] . ')))',
        'intCtz#' => '(' . $argExprs[0] . ' === 0 ? 64 : \\strspn(\\strrev(\\decbin(' . $argExprs[0] . ')), "0"))',
        'intCompare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        // Signed fixed-width ints: PHP int is i64; from_int sign-extends the
        // low N bits (arithmetic >>), arithmetic wraps at the declared width.
        'int8Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'int8Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'int8Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'int8ToInt#' => $argExprs[0],
        'int8FromInt#' => '(((' . $argExprs[0] . ') << 56) >> 56)',
        'int8Add#' => '((((' . $argExprs[0] . ' + ' . $argExprs[1] . ') << 56) >> 56))',
        'int8Sub#' => '((((' . $argExprs[0] . ' - ' . $argExprs[1] . ') << 56) >> 56))',
        'int8Mul#' => '((((' . $argExprs[0] . ' * ' . $argExprs[1] . ') << 56) >> 56))',
        'int16Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'int16Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'int16Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'int16ToInt#' => $argExprs[0],
        'int16FromInt#' => '(((' . $argExprs[0] . ') << 48) >> 48)',
        'int16Add#' => '((((' . $argExprs[0] . ' + ' . $argExprs[1] . ') << 48) >> 48))',
        'int16Sub#' => '((((' . $argExprs[0] . ' - ' . $argExprs[1] . ') << 48) >> 48))',
        'int16Mul#' => '((((' . $argExprs[0] . ' * ' . $argExprs[1] . ') << 48) >> 48))',
        'int32Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'int32Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'int32Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'int32ToInt#' => $argExprs[0],
        'int32FromInt#' => '(((' . $argExprs[0] . ') << 32) >> 32)',
        'int32Add#' => '((((' . $argExprs[0] . ' + ' . $argExprs[1] . ') << 32) >> 32))',
        'int32Sub#' => '((((' . $argExprs[0] . ' - ' . $argExprs[1] . ') << 32) >> 32))',
        'int32Mul#' => '((((' . $argExprs[0] . ' * ' . $argExprs[1] . ') << 32) >> 32))',
        // Int64 is host-int width: coercions are identity. Arithmetic reuses
        // the wrapping u64 helpers — two's-complement wrap is the same bit
        // pattern; PHP `+` would overflow to float past PHP_INT_MAX.
        'int64Eq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'int64Ne#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'int64Compare#' => intCompareExpr($argExprs[0], $argExprs[1]),
        'int64ToInt#' => $argExprs[0],
        'int64FromInt#' => $argExprs[0],
        'int64Add#' => "moggi_u64_add({$argExprs[0]}, {$argExprs[1]})",
        'int64Sub#' => "moggi_u64_sub({$argExprs[0]}, {$argExprs[1]})",
        'int64Mul#' => "moggi_u64_mul({$argExprs[0]}, {$argExprs[1]})",
        'doubleEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'doubleNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'doubleAdd#' => "({$argExprs[0]} + {$argExprs[1]})",
        'doubleSub#' => "({$argExprs[0]} - {$argExprs[1]})",
        'doubleMul#' => "({$argExprs[0]} * {$argExprs[1]})",
        // `fdiv` is IEEE division: `1.0 / 0.0` and `0.0 / 0.0` are Infinity and
        // NaN, where PHP's `/` raises a DivisionByZeroError.
        'doubleDiv#' => '\\fdiv(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'doubleNegate#' => "(-{$argExprs[0]})",
        'doubleAbs#' => '\\abs(' . $argExprs[0] . ')',
        'doubleSignum#' => doubleSignumExpr($argExprs[0]),
        'doubleFromInteger#' => '(float) ' . $argExprs[0],
        'doubleToInt#' => '(int) ' . $argExprs[0],
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
        'listCons#' => '[' . $argExprs[0] . ', ...' . $argExprs[1] . ']',
        'listHead#' => $argExprs[0] . '[0]',
        'listTail#' => '\array_slice(' . $argExprs[0] . ', 1)',
        'listAppend#' => '\array_merge(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'listEq#' => '(' . $argExprs[0] . ' === ' . $argExprs[1] . ')',
        'listNe#' => '(' . $argExprs[0] . ' !== ' . $argExprs[1] . ')',
        'listCompare#' => listCompareExpr($argExprs[0], $argExprs[1]),
        'maybeEq#' => '(' . $argExprs[0] . ' === ' . $argExprs[1] . ')',
        'maybeNe#' => '(' . $argExprs[0] . ' !== ' . $argExprs[1] . ')',
        'maybeCompare#' => maybeCompareExpr($argExprs[0], $argExprs[1]),
        'error#' => '\\Moggi\\throwErrorCall(' . $argExprs[0] . ', ' . $locExpr . ')',
        'fix#' => '\\Moggi\\fix(' . $argExprs[0] . ')',
        'exceptionWrap#' => '\\Moggi\\exceptionWrap(' . $argExprs[0] . ', ' . $argExprs[1] . ', ' . $argExprs[2] . ')',
        'exceptionUnwrap#' => '\\Moggi\\exceptionUnwrap(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'exceptionThrow#' => '\\Moggi\\throwSomeException(' . $argExprs[0] . ', ' . $locExpr . ')',
        'exceptionDisplay#' => '\\Moggi\\exceptionDisplay(' . $argExprs[0] . ')',
        // IO exception combinators are lowered to IoThrow/IoCatch/IoFinally.
        'exceptionThrowIo#', 'exceptionCatch#', 'exceptionFinally#' => throw new \InvalidArgumentException(
            "intrinsic `{$name}` must be lowered to IO exception IR",
        ),
        // Erased by strict_io_normalize before codegen; reaching here is a bug.
        'ioPure#', 'ioBind#' => throw new \InvalidArgumentException(
            "intrinsic `{$name}` must be erased by strict IO normalization",
        ),
        'platformArgv#' => '(function () { global $argv; $a = $argv ?? $GLOBALS[\'argv\'] ?? []; return \\array_values(\\array_slice($a, 1)); })()',
        default => throw new \InvalidArgumentException("unknown intrinsic `{$name}`"),
    };
}

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
    // Only the two strict orderings select ±1; NaN and (-0.0) are their own
    // signum, so they fall through to the value itself.
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

    return "match (true) {"
        . " ({$leftTag} === 'Nothing' && {$rightTag} === 'Nothing') => ['EQ'],"
        . " ({$leftTag} === 'Nothing') => ['LT'],"
        . " ({$rightTag} === 'Nothing') => ['GT'],"
        . " default => match (({$left}[1] ?? null) <=> ({$right}[1] ?? null)) { -1 => ['LT'], 0 => ['EQ'], 1 => ['GT'] }"
        . " }";
}

/** PHP helpers emitted once per module when those intrinsics are used. */
function phpHelpersForModule(array $used): string
{
    $helpers = [];

    if (isset($used['intPow#'])) {
        // The library's own `powAcc` order, on the same wrapping multiply.
        $helpers[] = 'function moggi_int_pow(int $b, int $e): int { $r = 1; while ($e !== 0) { if (($e & 1) !== 0) { $r = moggi_i64_mul($r, $b); } $b = moggi_i64_mul($b, $b); $e >>= 1; } return $r; }';
    }

    if (isset($used['doublePow#'])) {
        // `Double` is a host float here, and the loop is the library's own
        // multiplication order, so the value is the one `powAcc` would give.
        $helpers[] = 'function moggi_double_pow(float $b, int $e): float { $r = 1.0; while ($e !== 0) { if (($e & 1) !== 0) { $r = $r * $b; } $b = $b * $b; $e >>= 1; } return $r; }';
    }

    foreach (powWidthTable() as $name => $width) {
        if (isset($used[$name])) {
            $helpers[] = powWidthHelperSource($width);
        }
    }

    $any64 = static fn (string ...$names): bool =>
        \count(\array_filter($names, static fn ($n): bool => isset($used[$n]))) > 0;

    if ($any64('intAdd#', 'intSub#', 'intMul#', 'intNegate#', 'intAbs#', 'intPow#', 'intFromInteger#', 'word64Add#', 'word64Sub#', 'word64Mul#', 'word64Quot#', 'word64Rem#', 'word64ToInteger#', 'word64FromInteger#', 'word64Show#', 'word64Compare#', 'wordCompare#', 'byteSwap64#', 'bitReverse64#', 'word32Mul#', 'int64Add#', 'int64Sub#', 'int64Mul#', 'int32Pow#', 'int64Pow#', 'word32Pow#', 'word64Pow#')) {
        // Split into 32-bit halves so +/− never promote past PHP_INT_MAX into float.
        $helpers[] = 'function moggi_u64_add(int $a, int $b): int { $ao = $a & 0xffffffff; $ah = ($a >> 32) & 0xffffffff; $bo = $b & 0xffffffff; $bh = ($b >> 32) & 0xffffffff; $lo = $ao + $bo; $c = ($lo >> 32) & 0xffffffff; $hi = ($ah + $bh + $c) & 0xffffffff; return ($hi << 32) | ($lo & 0xffffffff); }';
        $helpers[] = 'function moggi_u64_sub(int $a, int $b): int { $ao = $a & 0xffffffff; $ah = ($a >> 32) & 0xffffffff; $bo = $b & 0xffffffff; $bh = ($b >> 32) & 0xffffffff; $lo = $ao - $bo; $bw = $lo < 0 ? 1 : 0; $hi = ($ah - $bh - $bw) & 0xffffffff; return ($hi << 32) | ($lo & 0xffffffff); }';
        // Low 64 bits of a*b from 16-bit limbs: a 32-bit split still overflows.
        $helpers[] = 'function moggi_u64_mul(int $a, int $b): int { $a0 = $a & 0xffff; $a1 = ($a >> 16) & 0xffff; $a2 = ($a >> 32) & 0xffff; $a3 = ($a >> 48) & 0xffff; $b0 = $b & 0xffff; $b1 = ($b >> 16) & 0xffff; $b2 = ($b >> 32) & 0xffff; $b3 = ($b >> 48) & 0xffff; $low = $a0 * $b0; $cy = ($low >> 16) & 0xffff; $m1 = $a0 * $b1 + $a1 * $b0 + $cy; $cy = ($m1 >> 16) & 0xffffffff; $m2 = $a0 * $b2 + $a1 * $b1 + $a2 * $b0 + $cy; $cy = ($m2 >> 16) & 0xffffffff; $hi = $a0 * $b3 + $a1 * $b2 + $a2 * $b1 + $a3 * $b0 + $cy; return (($hi & 0xffff) << 48) | (($m2 & 0xffff) << 32) | (($m1 & 0xffff) << 16) | ($low & 0xffff); }';
        // The host op first: PHP only leaves the int domain when it overflows,
        // and then the exact 64-bit pattern is computed from the operands.
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

        if (isset($used['intMul#']) || isset($used['intPow#'])) {
            $helpers[] = 'function moggi_i64_mul(int $a, int $b): int { $r = $a * $b; return \\is_int($r) ? $r : moggi_u64_mul($a, $b); }';
        }
    }

    if ($any64('intFromInteger#', 'word64FromInteger#', 'word64Quot#', 'word64Rem#')) {
        // The host int cast of out-of-range digits clamps, so the wrap is
        // computed from the digits themselves.
        $helpers[] = 'function moggi_wrap_decimal(string $s): int { $neg = $s !== "" && $s[0] === "-"; $d = $neg ? \\substr($s, 1) : $s; if (\\strlen($d) <= 18) { return (int) ($neg ? "-" . $d : $d); } $v = 0; foreach (\\str_split($d, 9) as $chunk) { $v = moggi_u64_add(moggi_u64_mul($v, 10 ** \\strlen($chunk)), (int) $chunk); } return $neg ? moggi_u64_sub(0, $v) : $v; }';
    }

    if ($any64('word64ToInteger#', 'word64Show#', 'word64Quot#', 'word64Rem#', 'word64Compare#', 'wordCompare#')) {
        $helpers[] = 'function moggi_u64_to_str(int $a): string { if ($a >= 0) return (string)$a; return (string)bcadd((string)$a, "18446744073709551616", 0); }';
        $helpers[] = 'function moggi_u64_cmp(int $a, int $b): int { $ah = ($a >> 32) & 0xffffffff; $bh = ($b >> 32) & 0xffffffff; if ($ah < $bh) return -1; if ($ah > $bh) return 1; $ao = $a & 0xffffffff; $bo = $b & 0xffffffff; return $ao <=> $bo; }';
        $helpers[] = 'function moggi_u64_quot(int $a, int $b): int { if ($b === 0) { \\Moggi\\throwErrorCall("divide by zero"); } return moggi_wrap_decimal(bcdiv(moggi_u64_to_str($a), moggi_u64_to_str($b), 0)); }';
        $helpers[] = 'function moggi_u64_rem(int $a, int $b): int { if ($b === 0) { \\Moggi\\throwErrorCall("divide by zero"); } return moggi_wrap_decimal(bcmod(moggi_u64_to_str($a), moggi_u64_to_str($b))); }';
    }

    $anySwap = static fn (string ...$names): bool =>
        \count(\array_filter($names, static fn ($n): bool => isset($used[$n]))) > 0;

    if ($anySwap('byteSwap16#', 'byteSwap32#', 'byteSwap64#', 'bitReverse8#', 'bitReverse16#', 'bitReverse32#', 'bitReverse64#')) {
        $helpers[] = 'function moggi_byte_swap_16(int $x): int { $x = $x & 0xffff; return (($x & 0xff) << 8) | (($x >> 8) & 0xff); }';
        $helpers[] = 'function moggi_byte_swap_32(int $x): int { $x = $x & 0xffffffff; return ((($x & 0x000000ff) << 24) | (($x & 0x0000ff00) << 8) | (($x & 0x00ff0000) >> 8) | (($x >> 24) & 0xff)) & 0xffffffff; }';
        // Byte-reverse within each half, then swap halves (lo bytes → high word).
        $helpers[] = 'function moggi_byte_swap_64(int $a): int { $lo = $a & 0xffffffff; $hi = ($a >> 32) & 0xffffffff; $revLo = (($lo & 0xff) << 24) | (($lo & 0xff00) << 8) | (($lo >> 8) & 0xff00) | (($lo >> 24) & 0xff); $revHi = (($hi & 0xff) << 24) | (($hi & 0xff00) << 8) | (($hi >> 8) & 0xff00) | (($hi >> 24) & 0xff); return (($revLo & 0xffffffff) << 32) | ($revHi & 0xffffffff); }';
        $helpers[] = 'function moggi_bit_reverse_n(int $x, int $bits, int $mask): int { $r = 0; for ($i = 0; $i < $bits; $i++) { if (($x & (1 << $i)) !== 0) { $r |= (1 << ($bits - 1 - $i)); } } return $r & $mask; }';
        $helpers[] = 'function moggi_bit_reverse_8(int $x): int { return moggi_bit_reverse_n($x, 8, 0xff); }';
        $helpers[] = 'function moggi_bit_reverse_16(int $x): int { return moggi_bit_reverse_n($x, 16, 0xffff); }';
        $helpers[] = 'function moggi_bit_reverse_32(int $x): int { return moggi_bit_reverse_n($x & 0xffffffff, 32, 0xffffffff); }';
        $helpers[] = 'function moggi_bit_reverse_64(int $a): int { $lo = $a & 0xffffffff; $hi = ($a >> 32) & 0xffffffff; $rlo = moggi_bit_reverse_n($lo, 32, 0xffffffff); $rhi = moggi_bit_reverse_n($hi, 32, 0xffffffff); return ($rlo << 32) | $rhi; }';
    }

    return (\count($helpers) > 0 ? implode("\n", $helpers) . "\n" : '');
}

/** @param array<string, true> $used @return array<string, string> */
function localHelpersForUsed(array $used): array
{
    return [];
}

/** @param array<int, string> $argExprs */
function localHelperCall(string $name, string $helper, array $argExprs): string
{
    throw new \InvalidArgumentException("no local helper call for intrinsic `{$name}`");
}

/** @return array<string, true> */
function helpersUsedByModule(IR\Module $module): array
{
    return IR\Visit\collectIrCodegenUsage($module)['intrinsics'];
}
