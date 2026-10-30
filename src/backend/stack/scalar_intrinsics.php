<?php declare(strict_types=1);

namespace Moggi\Backend\Stack\ScalarIntrinsics;

/**
 * The scalar intrinsic vocabulary: every name the stack backends emit, and the job it does.
 *
 * Each backend switches on the job, so a name cannot exist on one target and be missing on the
 * other, and what a job needs — which host operation, how many bits — is stated here rather than
 * re-derived from the spelling of the name. A job no backend implements is a `RuntimeException`
 * at emit time, never a silent fallthrough.
 *
 * Two jobs ask the same question of different hosts (`word64Equality` is `moggi/rt/Word64.eq` on
 * the JVM and object equality on the CLR); the job names the question, each backend answers it
 * with the operation it has.
 *
 * @var array<string, array{0: string, 1?: string, 2?: string}>
 */
const PRIMOP_JOBS = [
    'intAnd#' => ['intBitop', 'and'],
    'intOr#' => ['intBitop', 'or'],
    'intXor#' => ['intBitop', 'xor'],
    'intShiftL#' => ['intShift', 'shl'],
    'intShiftRA#' => ['intShift', 'shr'],
    'intShiftRL#' => ['intShift', 'ushr'],
    'intPopCnt#' => ['intBitCount', 'popcnt'],
    'intClz#' => ['intBitCount', 'clz'],
    'intCtz#' => ['intBitCount', 'ctz'],
    'intAdd#' => ['intBinop', 'add'],
    'intSub#' => ['intBinop', 'sub'],
    'intMul#' => ['intBinop', 'mul'],
    'intDiv#' => ['intBinop', 'div'],
    'int64Add#' => ['intBinop', 'add'],
    'int64Sub#' => ['intBinop', 'sub'],
    'int64Mul#' => ['intBinop', 'mul'],
    'doubleAdd#' => ['doubleBinop', 'add'],
    'doubleSub#' => ['doubleBinop', 'sub'],
    'doubleMul#' => ['doubleBinop', 'mul'],
    'doubleDiv#' => ['doubleBinop', 'div'],
    'doubleExp#' => ['doubleUnaryMath', 'exp'],
    'doubleLog#' => ['doubleUnaryMath', 'log'],
    'doubleSqrt#' => ['doubleUnaryMath', 'sqrt'],
    'doubleSin#' => ['doubleUnaryMath', 'sin'],
    'doubleCos#' => ['doubleUnaryMath', 'cos'],
    'doubleAsin#' => ['doubleUnaryMath', 'asin'],
    'doubleAcos#' => ['doubleUnaryMath', 'acos'],
    'doubleAtan#' => ['doubleUnaryMath', 'atan'],
    'doubleSinh#' => ['doubleUnaryMath', 'sinh'],
    'doubleCosh#' => ['doubleUnaryMath', 'cosh'],
    'word8FromInt#' => ['wordTruncate', '8'],
    'word16FromInt#' => ['wordTruncate', '16'],
    'word32FromInt#' => ['wordTruncate', '32'],
    'int8FromInt#' => ['intTruncate', '8'],
    'int16FromInt#' => ['intTruncate', '16'],
    'int32FromInt#' => ['intTruncate', '32'],
    'int8Add#' => ['intFixedBinop', '8', 'add'],
    'int8Sub#' => ['intFixedBinop', '8', 'sub'],
    'int8Mul#' => ['intFixedBinop', '8', 'mul'],
    'int16Add#' => ['intFixedBinop', '16', 'add'],
    'int16Sub#' => ['intFixedBinop', '16', 'sub'],
    'int16Mul#' => ['intFixedBinop', '16', 'mul'],
    'int32Add#' => ['intFixedBinop', '32', 'add'],
    'int32Sub#' => ['intFixedBinop', '32', 'sub'],
    'int32Mul#' => ['intFixedBinop', '32', 'mul'],
    'word8Add#' => ['wordBinop', '8', 'add'],
    'word8Sub#' => ['wordBinop', '8', 'sub'],
    'word8Mul#' => ['wordBinop', '8', 'mul'],
    'word16Add#' => ['wordBinop', '16', 'add'],
    'word16Sub#' => ['wordBinop', '16', 'sub'],
    'word16Mul#' => ['wordBinop', '16', 'mul'],
    'word32Add#' => ['wordBinop', '32', 'add'],
    'word32Sub#' => ['wordBinop', '32', 'sub'],
    'word32Mul#' => ['wordBinop', '32', 'mul'],
    'word64Add#' => ['word64Binop', 'add'],
    'word64Sub#' => ['word64Binop', 'sub'],
    'word64Mul#' => ['word64Binop', 'mul'],
    'word64Quot#' => ['word64Binop', 'quot'],
    'word64Rem#' => ['word64Binop', 'rem'],
    'bitReverse8#' => ['bitReverse', '8'],
    'bitReverse16#' => ['bitReverse', '16'],
    'bitReverse32#' => ['bitReverse', '32'],
    'bitReverse64#' => ['bitReverse', '64'],
    'charToInt#' => ['identity'],
    'intToChar#' => ['identity'],
    'wordFromInt#' => ['identity'],
    'wordToInt#' => ['identity'],
    'word8ToInt#' => ['identity'],
    'word16ToInt#' => ['identity'],
    'word32ToInt#' => ['identity'],
    'word64ToInt#' => ['identity'],
    'word64FromInt#' => ['identity'],
    'int8ToInt#' => ['identity'],
    'int16ToInt#' => ['identity'],
    'int32ToInt#' => ['identity'],
    'int64ToInt#' => ['identity'],
    'int64FromInt#' => ['identity'],
    'bytesFromString#' => ['identity'],
    'bytesToString#' => ['identity'],
    'naturalToInteger#' => ['naturalToInteger'],
    'doubleNegate#' => ['doubleNegate'],
    'doubleAbs#' => ['doubleAbs'],
    'doubleSignum#' => ['doubleSignum'],
    'doubleToInt#' => ['doubleToInt'],
    'doubleFromInteger#' => ['doubleFromInteger'],
    'intNegate#' => ['intNegate'],
    'intNot#' => ['intNot'],
    'intAbs#' => ['intAbs'],
    'intSignum#' => ['intSignum'],
    'intFromInteger#' => ['intFromInteger'],
    'intToInteger#' => ['intToInteger'],
    'integerFromDigits#' => ['integerFromDigits'],
    'integerToNatural#' => ['integerToNatural'],
    'word64ToInteger#' => ['word64ToInteger'],
    'word64FromInteger#' => ['word64FromInteger'],
    'word64Show#' => ['word64Show'],
    'byteSwap16#' => ['byteSwap16'],
    'byteSwap32#' => ['byteSwap32'],
    'byteSwap64#' => ['byteSwap64'],
];

/**
 * @var array<string, array{0: string, 1?: string, 2?: string}>
 */
const COMPARE_JOBS = [
    'intEq#' => ['objectEq'],
    'charEq#' => ['objectEq'],
    'boolEq#' => ['objectEq'],
    'word8Eq#' => ['objectEq'],
    'word16Eq#' => ['objectEq'],
    'word32Eq#' => ['objectEq'],
    'wordEq#' => ['objectEq'],
    'int8Eq#' => ['objectEq'],
    'int16Eq#' => ['objectEq'],
    'int32Eq#' => ['objectEq'],
    'int64Eq#' => ['objectEq'],
    'intNe#' => ['objectNe'],
    'charNe#' => ['objectNe'],
    'boolNe#' => ['objectNe'],
    'word8Ne#' => ['objectNe'],
    'word16Ne#' => ['objectNe'],
    'word32Ne#' => ['objectNe'],
    'wordNe#' => ['objectNe'],
    'int8Ne#' => ['objectNe'],
    'int16Ne#' => ['objectNe'],
    'int32Ne#' => ['objectNe'],
    'int64Ne#' => ['objectNe'],
    'word64Eq#' => ['word64Equality', 'eq'],
    'word64Ne#' => ['word64Equality', 'ne'],
    'intCompare#' => ['intCompareToOrdering'],
    'charCompare#' => ['intCompareToOrdering'],
    'word8Compare#' => ['intCompareToOrdering'],
    'word16Compare#' => ['intCompareToOrdering'],
    'word32Compare#' => ['intCompareToOrdering'],
    'int8Compare#' => ['intCompareToOrdering'],
    'int16Compare#' => ['intCompareToOrdering'],
    'int32Compare#' => ['intCompareToOrdering'],
    'int64Compare#' => ['intCompareToOrdering'],
    'wordCompare#' => ['wordCompare'],
    'word64Compare#' => ['wordCompare'],
    'orderingIsLt#' => ['orderingTag', 'eq', 'LT'],
    'orderingIsGt#' => ['orderingTag', 'eq', 'GT'],
    'orderingIsLte#' => ['orderingTag', 'ne', 'GT'],
    'orderingIsGte#' => ['orderingTag', 'ne', 'LT'],
    'doubleEq#' => ['doubleEq'],
    'doubleNe#' => ['doubleNe'],
    'doubleCompare#' => ['doubleCompare'],
    'boolCompare#' => ['boolCompare'],
    'boolAnd#' => ['boolAnd'],
    'boolOr#' => ['boolOr'],
    'boolNot#' => ['boolNot'],
    'orderingEq#' => ['orderingEq'],
    'orderingNe#' => ['orderingNe'],
    'orderingCompare#' => ['orderingCompare'],
];

/** @return array{0: string, 1?: string, 2?: string}|null */
function primopJob(string $name): ?array
{
    return PRIMOP_JOBS[$name] ?? null;
}

/** @return array{0: string, 1?: string, 2?: string}|null */
function compareJob(string $name): ?array
{
    return COMPARE_JOBS[$name] ?? null;
}
