<?php declare(strict_types=1);

namespace Moggi\Semantics\IntrinsicRegistry;

use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TBytes;
use Moggi\Semantics\TypeExpr\TChar;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TDouble;
use Moggi\Semantics\TypeExpr\TInt;
use Moggi\Semantics\TypeExpr\TInt8;
use Moggi\Semantics\TypeExpr\TInt16;
use Moggi\Semantics\TypeExpr\TInt32;
use Moggi\Semantics\TypeExpr\TInt64;
use Moggi\Semantics\TypeExpr\TStr;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TWord8;
use Moggi\Semantics\TypeExpr\TWord16;
use Moggi\Semantics\TypeExpr\TWord32;
use Moggi\Semantics\TypeExpr\TWord64;
use Moggi\Semantics\TypeExpr\TWord;
use Moggi\Semantics\TypeExpr\Type;

use function Moggi\Semantics\TypeExpr\scheme;

require_once __DIR__ . '/type_expr.php';

/**
 * The intrinsic scheme table.
 *
 * Built once per process: `resolveIntrinsicName()` runs on every name
 * resolution during inference and lowering, and the table itself is a pure
 * function of the compiler build. `Scheme` is immutable (all properties are
 * `readonly`), so sharing one table across modules is safe.
 *
 * @return array<string, Scheme>
 */
function typeSchemes(): array
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }

    $bool = new TCon('Bool');
    $ordering = new TCon('Ordering');
    $str = new TStr();
    $bytes = new TBytes();
    $char = new TChar();
    $int = new TInt();
    $float = new TDouble();
    $integer = new TCon('Integer');
    $natural = new TCon('Natural');
    $a = new TVar('a');
    $b = new TVar('b');
    $listA = new TCon('List', [$a]);
    $maybeA = new TCon('Maybe', [$a]);

    return $table = [
        'stringEq#' => scheme(new TArrow($str, new TArrow($str, $bool)), []),
        'stringNe#' => scheme(new TArrow($str, new TArrow($str, $bool)), []),
        'stringCompare#' => scheme(new TArrow($str, new TArrow($str, $ordering)), []),
        'stringAppend#' => scheme(new TArrow($str, new TArrow($str, $str)), []),
        'stringCons#' => scheme(new TArrow($char, new TArrow($str, $str)), []),
        'bytesEq#' => scheme(new TArrow($bytes, new TArrow($bytes, $bool)), []),
        'bytesNe#' => scheme(new TArrow($bytes, new TArrow($bytes, $bool)), []),
        'bytesCompare#' => scheme(new TArrow($bytes, new TArrow($bytes, $ordering)), []),
        'bytesAppend#' => scheme(new TArrow($bytes, new TArrow($bytes, $bytes)), []),
        'bytesFromString#' => scheme(new TArrow($str, $bytes), []),
        'bytesToString#' => scheme(new TArrow($bytes, $str), []),
        'charEq#' => scheme(new TArrow($char, new TArrow($char, $bool)), []),
        'charNe#' => scheme(new TArrow($char, new TArrow($char, $bool)), []),
        'charCompare#' => scheme(new TArrow($char, new TArrow($char, $ordering)), []),
        'charToInt#' => scheme(new TArrow($char, $int), []),
        'intToChar#' => scheme(new TArrow($int, $char), []),
        'word8Eq#' => scheme(new TArrow(new TWord8(), new TArrow(new TWord8(), $bool)), []),
        'word8Ne#' => scheme(new TArrow(new TWord8(), new TArrow(new TWord8(), $bool)), []),
        'word8Compare#' => scheme(new TArrow(new TWord8(), new TArrow(new TWord8(), $ordering)), []),
        'word8ToInt#' => scheme(new TArrow(new TWord8(), $int), []),
        'word8FromInt#' => scheme(new TArrow($int, new TWord8()), []),
        'word8Add#' => scheme(new TArrow(new TWord8(), new TArrow(new TWord8(), new TWord8())), []),
        'word8Sub#' => scheme(new TArrow(new TWord8(), new TArrow(new TWord8(), new TWord8())), []),
        'word8Mul#' => scheme(new TArrow(new TWord8(), new TArrow(new TWord8(), new TWord8())), []),
        'word16Eq#' => scheme(new TArrow(new TWord16(), new TArrow(new TWord16(), $bool)), []),
        'word16Ne#' => scheme(new TArrow(new TWord16(), new TArrow(new TWord16(), $bool)), []),
        'word16Compare#' => scheme(new TArrow(new TWord16(), new TArrow(new TWord16(), $ordering)), []),
        'word16ToInt#' => scheme(new TArrow(new TWord16(), $int), []),
        'word16FromInt#' => scheme(new TArrow($int, new TWord16()), []),
        'word16Add#' => scheme(new TArrow(new TWord16(), new TArrow(new TWord16(), new TWord16())), []),
        'word16Sub#' => scheme(new TArrow(new TWord16(), new TArrow(new TWord16(), new TWord16())), []),
        'word16Mul#' => scheme(new TArrow(new TWord16(), new TArrow(new TWord16(), new TWord16())), []),
        'word32Eq#' => scheme(new TArrow(new TWord32(), new TArrow(new TWord32(), $bool)), []),
        'word32Ne#' => scheme(new TArrow(new TWord32(), new TArrow(new TWord32(), $bool)), []),
        'word32Compare#' => scheme(new TArrow(new TWord32(), new TArrow(new TWord32(), $ordering)), []),
        'word32ToInt#' => scheme(new TArrow(new TWord32(), $int), []),
        'word32FromInt#' => scheme(new TArrow($int, new TWord32()), []),
        'word32Add#' => scheme(new TArrow(new TWord32(), new TArrow(new TWord32(), new TWord32())), []),
        'word32Sub#' => scheme(new TArrow(new TWord32(), new TArrow(new TWord32(), new TWord32())), []),
        'word32Mul#' => scheme(new TArrow(new TWord32(), new TArrow(new TWord32(), new TWord32())), []),
        'word64Eq#' => scheme(new TArrow(new TWord64(), new TArrow(new TWord64(), $bool)), []),
        'word64Ne#' => scheme(new TArrow(new TWord64(), new TArrow(new TWord64(), $bool)), []),
        'word64Compare#' => scheme(new TArrow(new TWord64(), new TArrow(new TWord64(), $ordering)), []),
        'word64ToInt#' => scheme(new TArrow(new TWord64(), $int), []),
        'word64FromInt#' => scheme(new TArrow($int, new TWord64()), []),
        'word64Add#' => scheme(new TArrow(new TWord64(), new TArrow(new TWord64(), new TWord64())), []),
        'word64Sub#' => scheme(new TArrow(new TWord64(), new TArrow(new TWord64(), new TWord64())), []),
        'word64Mul#' => scheme(new TArrow(new TWord64(), new TArrow(new TWord64(), new TWord64())), []),
        'word64Quot#' => scheme(new TArrow(new TWord64(), new TArrow(new TWord64(), new TWord64())), []),
        'word64Rem#' => scheme(new TArrow(new TWord64(), new TArrow(new TWord64(), new TWord64())), []),
        'byteSwap16#' => scheme(new TArrow(new TWord16(), new TWord16()), []),
        'byteSwap32#' => scheme(new TArrow(new TWord32(), new TWord32()), []),
        'byteSwap64#' => scheme(new TArrow(new TWord64(), new TWord64()), []),
        'bitReverse8#' => scheme(new TArrow(new TWord8(), new TWord8()), []),
        'bitReverse16#' => scheme(new TArrow(new TWord16(), new TWord16()), []),
        'bitReverse32#' => scheme(new TArrow(new TWord32(), new TWord32()), []),
        'bitReverse64#' => scheme(new TArrow(new TWord64(), new TWord64()), []),
        'word64ToInteger#' => scheme(new TArrow(new TWord64(), $integer), []),
        'word64FromInteger#' => scheme(new TArrow($integer, new TWord64()), []),
        'word64Show#' => scheme(new TArrow(new TWord64(), $str), []),
        // Machine word (platform-sized word, same as Int)
        'wordEq#' => scheme(new TArrow(new TWord(), new TArrow(new TWord(), $bool)), []),
        'wordNe#' => scheme(new TArrow(new TWord(), new TArrow(new TWord(), $bool)), []),
        'wordCompare#' => scheme(new TArrow(new TWord(), new TArrow(new TWord(), $ordering)), []),
        'wordToInt#' => scheme(new TArrow(new TWord(), $int), []),
        'wordFromInt#' => scheme(new TArrow($int, new TWord()), []),
        'intEq#' => scheme(new TArrow($int, new TArrow($int, $bool)), []),
        'intNe#' => scheme(new TArrow($int, new TArrow($int, $bool)), []),
        'intCompare#' => scheme(new TArrow($int, new TArrow($int, $ordering)), []),
        'intAdd#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        'intSub#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        'intMul#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        // `x ^ n` at machine `Int`, so it need not go through Num/Integral dictionaries: the
        // same square-and-multiply as the library body, same ErrorCall for `n < 0`.
        'intPow#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        // The same primop at the fixed-width machine integers. There the wrapped
        // multiply is the width's own `*`, so the loop reduces modulo the width
        // each step. The exponent stays the machine `Int`.
        'int8Pow#' => scheme(new TArrow(new TInt8(), new TArrow($int, new TInt8())), []),
        'int16Pow#' => scheme(new TArrow(new TInt16(), new TArrow($int, new TInt16())), []),
        'int32Pow#' => scheme(new TArrow(new TInt32(), new TArrow($int, new TInt32())), []),
        'int64Pow#' => scheme(new TArrow(new TInt64(), new TArrow($int, new TInt64())), []),
        'word8Pow#' => scheme(new TArrow(new TWord8(), new TArrow($int, new TWord8())), []),
        'word16Pow#' => scheme(new TArrow(new TWord16(), new TArrow($int, new TWord16())), []),
        'word32Pow#' => scheme(new TArrow(new TWord32(), new TArrow($int, new TWord32())), []),
        'word64Pow#' => scheme(new TArrow(new TWord64(), new TArrow($int, new TWord64())), []),
        // `^`/`^^` at `Double`: the library body's own multiplication order
        // (`powAcc`'s left-to-right accumulator), so the results are the same
        // values the dictionaries would have produced.
        'doublePow#' => scheme(new TArrow($float, new TArrow($int, $float)), []),
        'intDiv#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        'intNegate#' => scheme(new TArrow($int, $int), []),
        'intAbs#' => scheme(new TArrow($int, $int), []),
        'intSignum#' => scheme(new TArrow($int, $int), []),
        'intFromInteger#' => scheme(new TArrow($integer, $int), []),
        'intToInteger#' => scheme(new TArrow($int, $integer), []),
        // An integer literal that does not fit the host `Int`, carried as its
        // decimal digits: the only way to reach a value the host integer cannot
        // hold without losing digits.
        'integerFromDigits#' => scheme(new TArrow($str, $integer), []),
        // Non-negative bignum; the conversion into it is the only constructor and rejects a
        // negative value, which makes a negative `Natural` unrepresentable.
        'naturalToInteger#' => scheme(new TArrow($natural, $integer), []),
        'integerToNatural#' => scheme(new TArrow($integer, $natural), []),
        // Data.Bits i64 ops. Shift counts are in [0,63] (Data.Bits clamps/masks first), so
        // the saturating and width-specific behaviour stays in the library.
        'intAnd#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        'intOr#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        'intXor#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        'intNot#' => scheme(new TArrow($int, $int), []),
        'intShiftL#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        'intShiftRA#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        'intShiftRL#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        'intPopCnt#' => scheme(new TArrow($int, $int), []),
        'intClz#' => scheme(new TArrow($int, $int), []),
        'intCtz#' => scheme(new TArrow($int, $int), []),
        // Signed fixed-width ints (Data.Int): same i64 host rep as Int;
        // from_int / arithmetic sign-extend-truncate to the declared width.
        'int8Eq#' => scheme(new TArrow(new TInt8(), new TArrow(new TInt8(), $bool)), []),
        'int8Ne#' => scheme(new TArrow(new TInt8(), new TArrow(new TInt8(), $bool)), []),
        'int8Compare#' => scheme(new TArrow(new TInt8(), new TArrow(new TInt8(), $ordering)), []),
        'int8ToInt#' => scheme(new TArrow(new TInt8(), $int), []),
        'int8FromInt#' => scheme(new TArrow($int, new TInt8()), []),
        'int8Add#' => scheme(new TArrow(new TInt8(), new TArrow(new TInt8(), new TInt8())), []),
        'int8Sub#' => scheme(new TArrow(new TInt8(), new TArrow(new TInt8(), new TInt8())), []),
        'int8Mul#' => scheme(new TArrow(new TInt8(), new TArrow(new TInt8(), new TInt8())), []),
        'int16Eq#' => scheme(new TArrow(new TInt16(), new TArrow(new TInt16(), $bool)), []),
        'int16Ne#' => scheme(new TArrow(new TInt16(), new TArrow(new TInt16(), $bool)), []),
        'int16Compare#' => scheme(new TArrow(new TInt16(), new TArrow(new TInt16(), $ordering)), []),
        'int16ToInt#' => scheme(new TArrow(new TInt16(), $int), []),
        'int16FromInt#' => scheme(new TArrow($int, new TInt16()), []),
        'int16Add#' => scheme(new TArrow(new TInt16(), new TArrow(new TInt16(), new TInt16())), []),
        'int16Sub#' => scheme(new TArrow(new TInt16(), new TArrow(new TInt16(), new TInt16())), []),
        'int16Mul#' => scheme(new TArrow(new TInt16(), new TArrow(new TInt16(), new TInt16())), []),
        'int32Eq#' => scheme(new TArrow(new TInt32(), new TArrow(new TInt32(), $bool)), []),
        'int32Ne#' => scheme(new TArrow(new TInt32(), new TArrow(new TInt32(), $bool)), []),
        'int32Compare#' => scheme(new TArrow(new TInt32(), new TArrow(new TInt32(), $ordering)), []),
        'int32ToInt#' => scheme(new TArrow(new TInt32(), $int), []),
        'int32FromInt#' => scheme(new TArrow($int, new TInt32()), []),
        'int32Add#' => scheme(new TArrow(new TInt32(), new TArrow(new TInt32(), new TInt32())), []),
        'int32Sub#' => scheme(new TArrow(new TInt32(), new TArrow(new TInt32(), new TInt32())), []),
        'int32Mul#' => scheme(new TArrow(new TInt32(), new TArrow(new TInt32(), new TInt32())), []),
        'int64Eq#' => scheme(new TArrow(new TInt64(), new TArrow(new TInt64(), $bool)), []),
        'int64Ne#' => scheme(new TArrow(new TInt64(), new TArrow(new TInt64(), $bool)), []),
        'int64Compare#' => scheme(new TArrow(new TInt64(), new TArrow(new TInt64(), $ordering)), []),
        'int64ToInt#' => scheme(new TArrow(new TInt64(), $int), []),
        'int64FromInt#' => scheme(new TArrow($int, new TInt64()), []),
        'int64Add#' => scheme(new TArrow(new TInt64(), new TArrow(new TInt64(), new TInt64())), []),
        'int64Sub#' => scheme(new TArrow(new TInt64(), new TArrow(new TInt64(), new TInt64())), []),
        'int64Mul#' => scheme(new TArrow(new TInt64(), new TArrow(new TInt64(), new TInt64())), []),
        'doubleEq#' => scheme(new TArrow($float, new TArrow($float, $bool)), []),
        'doubleNe#' => scheme(new TArrow($float, new TArrow($float, $bool)), []),
        'doubleCompare#' => scheme(new TArrow($float, new TArrow($float, $ordering)), []),
        'doubleAdd#' => scheme(new TArrow($float, new TArrow($float, $float)), []),
        'doubleSub#' => scheme(new TArrow($float, new TArrow($float, $float)), []),
        'doubleMul#' => scheme(new TArrow($float, new TArrow($float, $float)), []),
        'doubleDiv#' => scheme(new TArrow($float, new TArrow($float, $float)), []),
        'doubleNegate#' => scheme(new TArrow($float, $float), []),
        'doubleAbs#' => scheme(new TArrow($float, $float), []),
        'doubleSignum#' => scheme(new TArrow($float, $float), []),
        'doubleFromInteger#' => scheme(new TArrow($integer, $float), []),
        'doubleToInt#' => scheme(new TArrow($float, $int), []),
        'orderingEq#' => scheme(new TArrow($ordering, new TArrow($ordering, $bool)), []),
        'orderingNe#' => scheme(new TArrow($ordering, new TArrow($ordering, $bool)), []),
        'orderingCompare#' => scheme(new TArrow($ordering, new TArrow($ordering, $ordering)), []),
        'orderingIsLt#' => scheme(new TArrow($ordering, $bool), []),
        'orderingIsLte#' => scheme(new TArrow($ordering, $bool), []),
        'orderingIsGt#' => scheme(new TArrow($ordering, $bool), []),
        'orderingIsGte#' => scheme(new TArrow($ordering, $bool), []),
        'boolEq#' => scheme(new TArrow($bool, new TArrow($bool, $bool)), []),
        'boolNe#' => scheme(new TArrow($bool, new TArrow($bool, $bool)), []),
        'boolCompare#' => scheme(new TArrow($bool, new TArrow($bool, $ordering)), []),
        'boolAnd#' => scheme(new TArrow($bool, new TArrow($bool, $bool)), []),
        'boolOr#' => scheme(new TArrow($bool, new TArrow($bool, $bool)), []),
        'boolNot#' => scheme(new TArrow($bool, $bool), []),
        'listCons#' => scheme(new TArrow($a, new TArrow($listA, $listA)), ['a']),
        'listHead#' => scheme(new TArrow($listA, $a), ['a']),
        'listTail#' => scheme(new TArrow($listA, $listA), ['a']),
        'listAppend#' => scheme(new TArrow($listA, new TArrow($listA, $listA)), ['a']),
        'listEq#' => scheme(new TArrow($listA, new TArrow($listA, $bool)), ['a']),
        'listNe#' => scheme(new TArrow($listA, new TArrow($listA, $bool)), ['a']),
        'listCompare#' => scheme(new TArrow($listA, new TArrow($listA, $ordering)), ['a']),
        'maybeEq#' => scheme(new TArrow($maybeA, new TArrow($maybeA, $bool)), ['a']),
        'maybeNe#' => scheme(new TArrow($maybeA, new TArrow($maybeA, $bool)), ['a']),
        'maybeCompare#' => scheme(new TArrow($maybeA, new TArrow($maybeA, $ordering)), ['a']),
        'error#' => scheme(new TArrow($str, $a), ['a']),
        // Least fixed point of a function (Data.Function.fix). Strict
        // recursion cannot be expressed with an ordinary Moggi `let`, so it is
        // a primop; each backend lowers it to a self-referential closure.
        'fix#' => scheme(new TArrow(new TArrow($a, $a), $a), ['a']),
        // Exception Magichash ops: tag → display → payload → SomeException#. The display is
        // computed by `displayException`, since the runtime has no dictionaries.
        'exceptionWrap#' => scheme(
            new TArrow($str, new TArrow($str, new TArrow($a, new TCon('SomeException', [])))),
            ['a'],
        ),
        'exceptionUnwrap#' => scheme(
            new TArrow($str, new TArrow(new TCon('SomeException', []), new TCon('Maybe', [$a]))),
            ['a'],
        ),
        'exceptionThrow#' => scheme(new TArrow(new TCon('SomeException', []), $a), ['a']),
        'exceptionDisplay#' => scheme(new TArrow(new TCon('SomeException', []), $str), []),
        'exceptionThrowIo#' => scheme(
            new TArrow(new TCon('SomeException', []), new TCon('IO', [$a])),
            ['a'],
        ),
        'exceptionCatch#' => scheme(
            new TArrow(
                new TCon('IO', [$a]),
                new TArrow(
                    new TArrow(new TCon('SomeException', []), new TCon('IO', [$a])),
                    new TCon('IO', [$a]),
                ),
            ),
            ['a'],
        ),
        'exceptionFinally#' => scheme(
            new TArrow(
                new TCon('IO', [$a]),
                new TArrow(new TCon('IO', [$b]), new TCon('IO', [$a])),
            ),
            ['a', 'b'],
        ),
        // Strict IO primitives: erased to IoPure / IoBind by strict_io_normalize.
        // Not a state encoding — just the argument types the Monad IO methods need.
        'ioPure#' => scheme(new TArrow($a, new TCon('IO', [$a])), ['a']),
        'ioBind#' => scheme(
            new TArrow(
                new TCon('IO', [$a]),
                new TArrow(
                    new TArrow(new TVar('a'), new TCon('IO', [new TVar('b')])),
                    new TCon('IO', [new TVar('b')]),
                ),
            ),
            ['a', 'b'],
        ),
        // Entry-point ABI only: argv must be captured by the generated `main`
        // stub (Platform.setArgs); no JDK/BCL FFI surface exposes process args.
        // Everything else platform-level is ordinary FFI in the System.* stdlib.
        'platformArgv#' => scheme(new TCon('IO', [new TCon('List', [$str])]), []),
    ];
}

/**
 * Operator schemes that stand alone (no `Prim` import needed).
 *
 * Memoized: every scheme binds all of its type variables, so the immutable
 * `Scheme` objects are safe to share across modules.
 *
 * @return array<string, Scheme>
 */
function standaloneOperatorSchemes(): array
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }

    $intOp = static fn (): Type => new TArrow(new TInt(), new TArrow(new TInt(), new TInt()));
    $bool = new TCon('Bool');
    $poly = static fn (Type $type): Type => new TArrow($type, new TArrow($type, $bool));

    return $table = [
        '+' => scheme($intOp(), []),
        '-' => scheme($intOp(), []),
        '*' => scheme($intOp(), []),
        '==' => scheme($poly(new TVar('a')), ['a']),
        '/=' => scheme($poly(new TVar('b')), ['b']),
        '<>' => scheme(new TArrow(new TVar('c'), new TArrow(new TVar('c'), new TVar('c'))), ['c']),
        ':' => scheme(
            new TArrow(
                new TVar('a'),
                new TArrow(new TCon('List', [new TVar('a')]), new TCon('List', [new TVar('a')])),
            ),
            ['a'],
        ),
    ];
}

function resolveMonomorphicOperator(string $op, Type $left, Type $right): ?string
{
    // `:` is cons whenever the right operand is a list, checked first so a list-of-lists
    // cons is not shadowed by the List/List branch below.
    if ($op === ':' && $right instanceof TCon && $right->name === 'List') {
        return 'listCons#';
    }

    if ($left instanceof TInt && $right instanceof TInt) {
        return match ($op) {
            '+' => 'intAdd#',
            '-' => 'intSub#',
            '*' => 'intMul#',
            '==' => 'intEq#',
            '/=' => 'intNe#',
            default => null,
        };
    }

    // Word64 must not fall through to host Binop: PHP `*`/`+`/`-` overflow to
    // float past 2^63-1. Resolve to wrapping u64 intrinsics (see Data.Word).
    if ($left instanceof TWord64 && $right instanceof TWord64) {
        return match ($op) {
            '+' => 'word64Add#',
            '-' => 'word64Sub#',
            '*' => 'word64Mul#',
            '==' => 'word64Eq#',
            '/=' => 'word64Ne#',
            default => null,
        };
    }

    // Fixed-width words: host Binop skips from_int masks (255+1 → 256, not 0).
    if ($left instanceof TWord8 && $right instanceof TWord8) {
        return match ($op) {
            '+' => 'word8Add#',
            '-' => 'word8Sub#',
            '*' => 'word8Mul#',
            '==' => 'word8Eq#',
            '/=' => 'word8Ne#',
            default => null,
        };
    }
    if ($left instanceof TWord16 && $right instanceof TWord16) {
        return match ($op) {
            '+' => 'word16Add#',
            '-' => 'word16Sub#',
            '*' => 'word16Mul#',
            '==' => 'word16Eq#',
            '/=' => 'word16Ne#',
            default => null,
        };
    }
    if ($left instanceof TWord32 && $right instanceof TWord32) {
        return match ($op) {
            '+' => 'word32Add#',
            '-' => 'word32Sub#',
            '*' => 'word32Mul#',
            '==' => 'word32Eq#',
            '/=' => 'word32Ne#',
            default => null,
        };
    }

    // Signed fixed-width ints: host Binop skips from_int sign-extension.
    if ($left instanceof TInt8 && $right instanceof TInt8) {
        return match ($op) {
            '+' => 'int8Add#',
            '-' => 'int8Sub#',
            '*' => 'int8Mul#',
            '==' => 'int8Eq#',
            '/=' => 'int8Ne#',
            default => null,
        };
    }
    if ($left instanceof TInt16 && $right instanceof TInt16) {
        return match ($op) {
            '+' => 'int16Add#',
            '-' => 'int16Sub#',
            '*' => 'int16Mul#',
            '==' => 'int16Eq#',
            '/=' => 'int16Ne#',
            default => null,
        };
    }
    if ($left instanceof TInt32 && $right instanceof TInt32) {
        return match ($op) {
            '+' => 'int32Add#',
            '-' => 'int32Sub#',
            '*' => 'int32Mul#',
            '==' => 'int32Eq#',
            '/=' => 'int32Ne#',
            default => null,
        };
    }
    if ($left instanceof TInt64 && $right instanceof TInt64) {
        return match ($op) {
            '+' => 'int64Add#',
            '-' => 'int64Sub#',
            '*' => 'int64Mul#',
            '==' => 'int64Eq#',
            '/=' => 'int64Ne#',
            default => null,
        };
    }

    if ($left instanceof TWord && $right instanceof TWord) {
        return match ($op) {
            '==' => 'wordEq#',
            '/=' => 'wordNe#',
            default => null,
        };
    }

    if ($left instanceof TChar && $right instanceof TChar) {
        return match ($op) {
            '==' => 'charEq#',
            '/=' => 'charNe#',
            default => null,
        };
    }

    if ($left instanceof TStr && $right instanceof TStr) {
        return match ($op) {
            '<>' => 'stringAppend#',
            '==' => 'stringEq#',
            '/=' => 'stringNe#',
            default => null,
        };
    }

    if ($left instanceof TBytes && $right instanceof TBytes) {
        return match ($op) {
            '<>' => 'bytesAppend#',
            '==' => 'bytesEq#',
            '/=' => 'bytesNe#',
            default => null,
        };
    }

    if ($left instanceof TDouble && $right instanceof TDouble) {
        return match ($op) {
            '+' => 'doubleAdd#',
            '-' => 'doubleSub#',
            '*' => 'doubleMul#',
            '/' => 'doubleDiv#',
            '==' => 'doubleEq#',
            '/=' => 'doubleNe#',
            default => null,
        };
    }

    if (isNullaryCon($left, 'Bool') && isNullaryCon($right, 'Bool')) {
        return match ($op) {
            '==' => 'boolEq#',
            '/=' => 'boolNe#',
            '&&' => 'boolAnd#',
            '||' => 'boolOr#',
            default => null,
        };
    }

    if (isCon($left, 'List') && isCon($right, 'List')) {
        return match ($op) {
            '<>' => 'listAppend#',
            '==' => 'listEq#',
            '/=' => 'listNe#',
            default => null,
        };
    }

    if (isCon($left, 'Maybe') && isCon($right, 'Maybe')) {
        return match ($op) {
            '==' => 'maybeEq#',
            '/=' => 'maybeNe#',
            default => null,
        };
    }

    return null;
}

function isCon(Type $type, string $name): bool
{
    return $type instanceof TCon && $type->name === $name;
}

function isNullaryCon(Type $type, string $name): bool
{
    return isCon($type, $name) && $type->args === [];
}

const MODULE_PRIM = 'Moggi.Internal.Prim';
const MODULE_IO = 'Moggi.Internal.IO';

/**
 * Resolve an intrinsic name, or null when `$name` is not a registered primop.
 *
 * A primop has exactly one name: the MagicHash name used in Moggi source
 * (`intAdd#`, `word32Eq#`, `error#`, …). That name is also the IR/backend id,
 * so resolving is a plain membership check.
 */
function resolveIntrinsicName(string $name): ?string
{
    return isset(typeSchemes()[$name]) ? $name : null;
}

function isIoIntrinsic(string $name): bool
{
    return $name === 'ioPure#'
        || $name === 'ioBind#'
        || $name === 'exceptionThrowIo#'
        || $name === 'exceptionCatch#'
        || $name === 'exceptionFinally#';
}

/** Owning compiler module for a primop (`Moggi.Internal.Prim` or `Moggi.Internal.IO`). */
function ownerModule(string $name): string
{
    return isIoIntrinsic($name) ? MODULE_IO : MODULE_PRIM;
}

function isMagicHashName(string $name): bool
{
    return $name !== '' && \str_ends_with($name, '#');
}

/**
 * Intrinsics whose lowering embeds the *call site* (`error#` reports the
 * offending source position). Wrapping one of these in a library function is a
 * real call, not an inline alias: the wrapper is the frame users see, so
 * `trivialIntrinsicWrapper` must leave it alone.
 */
function capturesCallSite(string $name): bool
{
    return $name === 'error#' || $name === 'exceptionThrow#';
}

/**
 * Primitive type constructors owned by Moggi.Internal.Prim (not IO).
 *
 * @return array<string, array{params: list<string>, kindArity: int}>
 */
function primTypeTable(): array
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }

    return $table = [
        'Int#' => ['params' => [], 'kindArity' => 0],
        'Word#' => ['params' => [], 'kindArity' => 0],
        'Integer#' => ['params' => [], 'kindArity' => 0],
        'Natural#' => ['params' => [], 'kindArity' => 0],
        'Char#' => ['params' => [], 'kindArity' => 0],
        'Word8#' => ['params' => [], 'kindArity' => 0],
        'Word16#' => ['params' => [], 'kindArity' => 0],
        'Word32#' => ['params' => [], 'kindArity' => 0],
        'Word64#' => ['params' => [], 'kindArity' => 0],
        'Int8#' => ['params' => [], 'kindArity' => 0],
        'Int16#' => ['params' => [], 'kindArity' => 0],
        'Int32#' => ['params' => [], 'kindArity' => 0],
        'Int64#' => ['params' => [], 'kindArity' => 0],
        'String#' => ['params' => [], 'kindArity' => 0],
        'Bytes#' => ['params' => [], 'kindArity' => 0],
        'Double#' => ['params' => [], 'kindArity' => 0],
        'List#' => ['params' => ['a'], 'kindArity' => 1],
        'SomeException#' => ['params' => [], 'kindArity' => 0],
    ];
}

/**
 * Primitive type constructors owned by Moggi.Internal.IO.
 *
 * @return array<string, array{params: list<string>, kindArity: int}>
 */
function ioTypeTable(): array
{
    static $table = null;

    return $table ??= [
        'IO#' => ['params' => ['a'], 'kindArity' => 1],
    ];
}

/** @return array<string, array{params: list<string>, kindArity: int}> */
function allPrimitiveTypeTable(): array
{
    static $table = null;

    return $table ??= primTypeTable() + ioTypeTable();
}

function isKnownPrimitiveType(string $name): bool
{
    return isset(allPrimitiveTypeTable()[$name]);
}

function primitiveTypeOwnerModule(string $name): ?string
{
    if (isset(ioTypeTable()[$name])) {
        return MODULE_IO;
    }
    if (isset(primTypeTable()[$name])) {
        return MODULE_PRIM;
    }

    return null;
}

/** Count leading arrows in a scheme for saturated intrinsic application. */
function schemeArity(Scheme $scheme): int
{
    $type = $scheme->type;
    $arity = 0;
    while ($type instanceof TArrow) {
        ++$arity;
        $type = $type->to;
    }

    return $arity;
}
