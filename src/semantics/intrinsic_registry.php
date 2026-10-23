<?php declare(strict_types=1);

namespace Moggi\Semantics\IntrinsicRegistry;

use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TBytes;
use Moggi\Semantics\TypeExpr\TChar;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TDouble;
use Moggi\Semantics\TypeExpr\TInt;
use Moggi\Semantics\TypeExpr\TInt16;
use Moggi\Semantics\TypeExpr\TInt32;
use Moggi\Semantics\TypeExpr\TInt64;
use Moggi\Semantics\TypeExpr\TInt8;
use Moggi\Semantics\TypeExpr\TStr;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TWord;
use Moggi\Semantics\TypeExpr\TWord16;
use Moggi\Semantics\TypeExpr\TWord32;
use Moggi\Semantics\TypeExpr\TWord64;
use Moggi\Semantics\TypeExpr\TWord8;
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
        'intDiv#' => scheme(new TArrow($int, new TArrow($int, $int)), []),
        'intNegate#' => scheme(new TArrow($int, $int), []),
        'intAbs#' => scheme(new TArrow($int, $int), []),
        'intSignum#' => scheme(new TArrow($int, $int), []),
        'intFromInteger#' => scheme(new TArrow($integer, $int), []),
        'intToInteger#' => scheme(new TArrow($int, $integer), []),
        'integerFromDigits#' => scheme(new TArrow($str, $integer), []),
        'naturalToInteger#' => scheme(new TArrow($natural, $integer), []),
        'integerToNatural#' => scheme(new TArrow($integer, $natural), []),
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
        'doubleExp#' => scheme(new TArrow($float, $float), []),
        'doubleLog#' => scheme(new TArrow($float, $float), []),
        'doubleSqrt#' => scheme(new TArrow($float, $float), []),
        'doubleSin#' => scheme(new TArrow($float, $float), []),
        'doubleCos#' => scheme(new TArrow($float, $float), []),
        'doubleAsin#' => scheme(new TArrow($float, $float), []),
        'doubleAcos#' => scheme(new TArrow($float, $float), []),
        'doubleAtan#' => scheme(new TArrow($float, $float), []),
        'doubleSinh#' => scheme(new TArrow($float, $float), []),
        'doubleCosh#' => scheme(new TArrow($float, $float), []),
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
        'fix#' => scheme(new TArrow(new TArrow($a, $a), $a), ['a']),
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
        'stPure#' => scheme(
            new TArrow($a, new TCon('ST', [new TVar('s'), $a])),
            ['s', 'a'],
        ),
        'stBind#' => scheme(
            new TArrow(
                new TCon('ST', [new TVar('s'), $a]),
                new TArrow(
                    new TArrow(new TVar('a'), new TCon('ST', [new TVar('s'), new TVar('b')])),
                    new TCon('ST', [new TVar('s'), new TVar('b')]),
                ),
            ),
            ['s', 'a', 'b'],
        ),
        'runST#' => scheme(
            new TArrow(new TCon('ST', [new TVar('s'), $a]), $a),
            ['s', 'a'],
        ),
        'stToIO#' => scheme(
            new TArrow(
                new TCon('ST', [new TCon('RealWorld', []), $a]),
                new TCon('IO', [$a]),
            ),
            ['a'],
        ),
        'newSTRef#' => scheme(
            new TArrow($a, new TCon('ST', [new TVar('s'), new TCon('STRef', [new TVar('s'), $a])])),
            ['s', 'a'],
        ),
        'readSTRef#' => scheme(
            new TArrow(
                new TCon('STRef', [new TVar('s'), $a]),
                new TCon('ST', [new TVar('s'), $a]),
            ),
            ['s', 'a'],
        ),
        'writeSTRef#' => scheme(
            new TArrow(
                new TCon('STRef', [new TVar('s'), $a]),
                new TArrow($a, new TCon('ST', [new TVar('s'), new TUnit()])),
            ),
            ['s', 'a'],
        ),
        'arrayNew#' => scheme(
            new TArrow(
                new TCon('Tuple2', [new TVar('i'), new TVar('i')]),
                new TArrow($int, new TCon('Array', [new TVar('i'), new TVar('e')])),
            ),
            ['i', 'e'],
        ),
        'arraySize#' => scheme(
            new TArrow(new TCon('Array', [new TVar('i'), new TVar('e')]), $int),
            ['i', 'e'],
        ),
        'arrayBounds#' => scheme(
            new TArrow(
                new TCon('Array', [new TVar('i'), new TVar('e')]),
                new TCon('Tuple2', [new TVar('i'), new TVar('i')]),
            ),
            ['i', 'e'],
        ),
        'arrayRead#' => scheme(
            new TArrow(
                new TCon('Array', [new TVar('i'), new TVar('e')]),
                new TArrow($int, new TVar('e')),
            ),
            ['i', 'e'],
        ),
        'newSTArray#' => scheme(
            new TArrow(
                new TCon('Tuple2', [new TVar('i'), new TVar('i')]),
                new TArrow(
                    $int,
                    new TCon('ST', [new TVar('s'), new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')])]),
                ),
            ),
            ['s', 'i', 'e'],
        ),
        'readSTArray#' => scheme(
            new TArrow(
                new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')]),
                new TArrow(
                    $int,
                    new TCon('ST', [new TVar('s'), new TVar('e')]),
                ),
            ),
            ['s', 'i', 'e'],
        ),
        'writeSTArray#' => scheme(
            new TArrow(
                new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')]),
                new TArrow(
                    $int,
                    new TArrow(
                        new TVar('e'),
                        new TCon('ST', [new TVar('s'), new TUnit()]),
                    ),
                ),
            ),
            ['s', 'i', 'e'],
        ),
        'sizeSTArray#' => scheme(
            new TArrow(new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')]), $int),
            ['s', 'i', 'e'],
        ),
        'boundsSTArray#' => scheme(
            new TArrow(
                new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')]),
                new TCon('Tuple2', [new TVar('i'), new TVar('i')]),
            ),
            ['s', 'i', 'e'],
        ),
        'sameSTArray#' => scheme(
            new TArrow(
                new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')]),
                new TArrow(
                    new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')]),
                    $bool,
                ),
            ),
            ['s', 'i', 'e'],
        ),
        'freezeSTArray#' => scheme(
            new TArrow(
                new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')]),
                new TCon('ST', [new TVar('s'), new TCon('Array', [new TVar('i'), new TVar('e')])]),
            ),
            ['s', 'i', 'e'],
        ),
        'thawArray#' => scheme(
            new TArrow(
                new TCon('Array', [new TVar('i'), new TVar('e')]),
                new TCon('ST', [new TVar('s'), new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')])]),
            ),
            ['s', 'i', 'e'],
        ),
        'unsafeFreezeSTArray#' => scheme(
            new TArrow(
                new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')]),
                new TCon('ST', [new TVar('s'), new TCon('Array', [new TVar('i'), new TVar('e')])]),
            ),
            ['s', 'i', 'e'],
        ),
        'unsafeThawArray#' => scheme(
            new TArrow(
                new TCon('Array', [new TVar('i'), new TVar('e')]),
                new TCon('ST', [new TVar('s'), new TCon('STArray', [new TVar('s'), new TVar('i'), new TVar('e')])]),
            ),
            ['s', 'i', 'e'],
        ),
        'newIOArray#' => scheme(
            new TArrow(
                new TCon('Tuple2', [new TVar('i'), new TVar('i')]),
                new TArrow(
                    $int,
                    new TCon('IO', [new TCon('IOArray', [new TVar('i'), new TVar('e')])]),
                ),
            ),
            ['i', 'e'],
        ),
        'readIOArray#' => scheme(
            new TArrow(
                new TCon('IOArray', [new TVar('i'), new TVar('e')]),
                new TArrow(
                    $int,
                    new TCon('IO', [new TVar('e')]),
                ),
            ),
            ['i', 'e'],
        ),
        'writeIOArray#' => scheme(
            new TArrow(
                new TCon('IOArray', [new TVar('i'), new TVar('e')]),
                new TArrow(
                    $int,
                    new TArrow(
                        new TVar('e'),
                        new TCon('IO', [new TUnit()]),
                    ),
                ),
            ),
            ['i', 'e'],
        ),
        'sizeIOArray#' => scheme(
            new TArrow(new TCon('IOArray', [new TVar('i'), new TVar('e')]), $int),
            ['i', 'e'],
        ),
        'boundsIOArray#' => scheme(
            new TArrow(
                new TCon('IOArray', [new TVar('i'), new TVar('e')]),
                new TCon('Tuple2', [new TVar('i'), new TVar('i')]),
            ),
            ['i', 'e'],
        ),
        'sameIOArray#' => scheme(
            new TArrow(
                new TCon('IOArray', [new TVar('i'), new TVar('e')]),
                new TArrow(
                    new TCon('IOArray', [new TVar('i'), new TVar('e')]),
                    $bool,
                ),
            ),
            ['i', 'e'],
        ),
        'freezeIOArray#' => scheme(
            new TArrow(
                new TCon('IOArray', [new TVar('i'), new TVar('e')]),
                new TCon('IO', [new TCon('Array', [new TVar('i'), new TVar('e')])]),
            ),
            ['i', 'e'],
        ),
        'thawIOArray#' => scheme(
            new TArrow(
                new TCon('Array', [new TVar('i'), new TVar('e')]),
                new TCon('IO', [new TCon('IOArray', [new TVar('i'), new TVar('e')])]),
            ),
            ['i', 'e'],
        ),
        'unsafeFreezeIOArray#' => scheme(
            new TArrow(
                new TCon('IOArray', [new TVar('i'), new TVar('e')]),
                new TCon('IO', [new TCon('Array', [new TVar('i'), new TVar('e')])]),
            ),
            ['i', 'e'],
        ),
        'unsafeThawIOArray#' => scheme(
            new TArrow(
                new TCon('Array', [new TVar('i'), new TVar('e')]),
                new TCon('IO', [new TCon('IOArray', [new TVar('i'), new TVar('e')])]),
            ),
            ['i', 'e'],
        ),
        'platformArgv#' => scheme(new TCon('IO', [new TCon('List', [$str])]), []),
        'platformStdin#' => scheme(new TCon('Handle', []), []),
    ];
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
const MODULE_ST = 'Moggi.Internal.ST';

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

/**
 * What a stage has to do differently for a primop, stated once beside the schemes.
 *
 * `typeSchemes()` owns a primop's type; the tables below own its behaviour, keyed by the kernel id
 * (the MagicHash name, which is also the IR id). A pass reads the property it needs instead of naming
 * the primop, so the same id cannot be given one meaning by the checker and another by the optimizer.
 * Each table answers one stage's question and they are deliberately not the same shape: the IO roles
 * answer the boundary and the normalizer, the short-circuit pair the two places that build the
 * conditional, the list forms the list folds, the fold strategies the optimizer's dispatch.
 */

/**
 * The IO primops by the construct each is: `pure`/`bind` are the sequencing constructs the
 * normalizer rewrites, `throw`/`catch`/`finally` the exception actions that survive to lowering.
 *
 * The values are the machine's own (`io-pure`, `io-bind`, …), not the library's vocabulary: a
 * stage asks about the construct, and the library's `pure` is a declaration the compiler must not
 * be keyed on.
 *
 * @return array<string, string> id → `io-pure`|`io-bind`|`io-throw`|`io-catch`|`io-finally`|`io-action`
 */
function ioPrimopRoles(): array
{
    static $table = null;

    return $table ??= [
        'ioPure#' => 'io-pure',
        'ioBind#' => 'io-bind',
        'exceptionThrowIo#' => 'io-throw',
        'exceptionCatch#' => 'io-catch',
        'exceptionFinally#' => 'io-finally',
        'newIOArray#' => 'io-action',
        'readIOArray#' => 'io-action',
        'writeIOArray#' => 'io-action',
        'sizeIOArray#' => 'io-action',
        'boundsIOArray#' => 'io-action',
        'freezeIOArray#' => 'io-action',
        'thawIOArray#' => 'io-action',
        'unsafeFreezeIOArray#' => 'io-action',
        'unsafeThawIOArray#' => 'io-action',
    ];
}

/** The IO construct a primop is, or null when it is not an IO primop. */
function ioPrimopRole(string $name): ?string
{
    return ioPrimopRoles()[$name] ?? null;
}

function isIoIntrinsic(string $name): bool
{
    return isset(ioPrimopRoles()[$name]);
}

/**
 * An IO primop that raises or handles an exception: the ones whose lowering is the IO action
 * itself, rather than a sequencing construct the normalizer rewrites away.
 */
function isIoExceptionPrimop(string $name): bool
{
    return match (ioPrimopRole($name)) {
        'io-throw', 'io-catch', 'io-finally' => true,
        default => false,
    };
}

/**
 * The ST primops by the construct each is: `pure`/`bind` are the sequencing
 * constructs the library's `Monad` instance is written in terms of, `run` is
 * the escape-checked eliminator, and the `ref` triple allocates and touches
 * a mutable cell. Unlike the IO roles these never reach a dedicated IR:
 * an ST action is a re-executable description (a thunk wrapper on every
 * backend), so building one is pure and the optimizer may share and drop
 * descriptions exactly as it does any other value — each *use* still runs
 * the description, which is what keeps allocation and read/write order.
 *
 * @return array<string, string> id → `st-pure`|`st-bind`|`st-run`|`st-to-io`|`st-newref`|`st-readref`|`st-writeref`|`st-newarray`|`st-readarray`|`st-writearray`|`st-sizearray`|`st-boundsarray`|`st-freeze`|`st-thaw`
 */
function stPrimopRoles(): array
{
    static $table = null;

    return $table ??= [
        'stPure#' => 'st-pure',
        'stBind#' => 'st-bind',
        'runST#' => 'st-run',
        'stToIO#' => 'st-to-io',
        'newSTRef#' => 'st-newref',
        'readSTRef#' => 'st-readref',
        'writeSTRef#' => 'st-writeref',
        'newSTArray#' => 'st-newarray',
        'readSTArray#' => 'st-readarray',
        'writeSTArray#' => 'st-writearray',
        'sizeSTArray#' => 'st-sizearray',
        'boundsSTArray#' => 'st-boundsarray',
        'freezeSTArray#' => 'st-freeze',
        'thawArray#' => 'st-thaw',
        'unsafeFreezeSTArray#' => 'st-freeze',
        'unsafeThawArray#' => 'st-thaw',
    ];
}

/** The ST construct a primop is, or null when it is not an ST primop. */
function stPrimopRole(string $name): ?string
{
    return stPrimopRoles()[$name] ?? null;
}

function isStIntrinsic(string $name): bool
{
    return isset(stPrimopRoles()[$name]);
}

/**
 * Primops that short-circuit: the right operand is evaluated only when the left one does not decide
 * the result.
 *
 * @return array<string, string> id → `bool-and`|`bool-or`
 */
function shortCircuitPrimops(): array
{
    static $table = null;

    return $table ??= ['boolAnd#' => 'bool-and', 'boolOr#' => 'bool-or'];
}

/**
 * Which side short-circuits: `bool-and` for the conjunction, `bool-or` for the disjunction, null
 * otherwise.
 */
function shortCircuitPrimop(string $name): ?string
{
    return shortCircuitPrimops()[$name] ?? null;
}

function isShortCircuitPrimop(string $name): bool
{
    return isset(shortCircuitPrimops()[$name]);
}

/**
 * The primop a piece of syntax means when it builds the short-circuit operator itself, rather than
 * resolving one from source: the id is the machine's choice, so it is read here rather than written
 * at the construction site.
 *
 * @param string $op `bool-and`|`bool-or`
 */
function shortCircuitPrimopId(string $op): string
{
    $id = \array_search($op, shortCircuitPrimops(), true);
    if (! \is_string($id)) {
        throw new \RuntimeException("no primop for the `{$op}` short-circuit operator");
    }

    return $id;
}

/**
 * The list's own forms: the cons cell, the primops that build or take a spine apart, and
 * `list_nil`, the empty-list marker the lowerer plants (it is not a primop, but it is a spine
 * value, so a pass asking "is this operand a list" has to see it).
 *
 * @return array<string, string> id → `list-cons`|`list-nil`|`list-head`|`list-tail`|`list-append`
 */
function listFormShapes(): array
{
    static $table = null;

    return $table ??= [
        'listCons#' => 'list-cons',
        'list_nil' => 'list-nil',
        'listHead#' => 'list-head',
        'listTail#' => 'list-tail',
        'listAppend#' => 'list-append',
    ];
}

/** Which of the list's own forms a name is (`cons`, `nil`, `head`, `tail`, `append`), or null. */
function listFormShape(string $name): ?string
{
    return listFormShapes()[$name] ?? null;
}

/** Any of the list's own forms — a pass asking whether an operand is a list. */
function isListForm(string $name): bool
{
    return isset(listFormShapes()[$name]);
}

/** The spine itself: a cons cell or the empty list. */
function isListSpineForm(string $name): bool
{
    return listFormShape($name) === 'list-cons' || listFormShape($name) === 'list-nil';
}

/** The cons cell itself. */
function isListConsForm(string $name): bool
{
    return listFormShape($name) === 'list-cons';
}

/**
 * The primops the optimizer folds at compile time, and the strategy that folds each.
 *
 * A strategy names what the primop means (`int-add` folds to the sum of its literals), which is a
 * fact about the primop and belongs here; the optimizer keeps the code that computes the folded
 * form, and refuses to run at all for a strategy it has no implementation for.
 *
 * @return array<string, string> id → strategy
 */
function foldStrategies(): array
{
    static $table = null;

    return $table ??= [
        'intAdd#' => 'int-add',
        'intSub#' => 'int-sub',
        'intMul#' => 'int-mul',
        'stringAppend#' => 'string-append',
        'bytesAppend#' => 'bytes-append',
        'listAppend#' => 'list-append',
        'listCons#' => 'list-cons',
        'listHead#' => 'list-head',
        'listTail#' => 'list-tail',
    ];
}

/** The compile-time fold strategy for a primop, or null when it does not fold. */
function foldStrategy(string $name): ?string
{
    return foldStrategies()[$name] ?? null;
}

/**
 * The number of arguments a primop takes, read off its declared scheme: the arity is stated once,
 * in the type, so a boundary check cannot disagree with the signature the checker uses.
 */
function primopArity(string $name): ?int
{
    $scheme = typeSchemes()[$name] ?? null;

    return $scheme === null ? null : schemeArity($scheme);
}

/** Owning compiler module for a primop (`Moggi.Internal.Prim`, `Moggi.Internal.IO` or `Moggi.Internal.ST`). */
function ownerModule(string $name): string
{
    if (isIoIntrinsic($name)) {
        return MODULE_IO;
    }

    return isStIntrinsic($name) ? MODULE_ST : MODULE_PRIM;
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
 * Intrinsics that are stack frames: the statement which reaches one is the frame a
 * host exception must report.
 *
 * The two that capture their call site are frames by construction
 * ({@see capturesCallSite}); the raw division and remainder primops are frames
 * because they fault: they are unchecked by design and trap on a zero divisor, so a
 * program reaching one directly still has to name the statement that faulted.
 *
 * A property of the operation, stated here beside `capturesCallSite` and
 * `isIoIntrinsic` rather than in a backend's metadata, because the Java, CLR and PHP
 * emitters all ask the same question about the same id.
 */
function reportsSourceLocation(string $name): bool
{
    return capturesCallSite($name)
        || $name === 'exceptionThrowIo#'
        || $name === 'intDiv#'
        || $name === 'word64Quot#'
        || $name === 'word64Rem#'
        || $name === 'doubleDiv#';
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
        'Array#' => ['params' => ['i', 'e'], 'kindArity' => 2],
        'SomeException#' => ['params' => [], 'kindArity' => 0],
    ];
}

/**
 * The public function names that stand for a primop.
 *
 * `(:)` is the list constructor as a function. Its pattern form (`x : xs`) is a
 * primitive branch of the language; this is the name the same operation takes
 * where a value is wanted. It is the machine's for the same reason the list
 * type is: the name has to sit below every module that conses, and `Data.List`
 * sits above `Control.Monad`, `Data.Foldable` and `Text.Show`.
 *
 * @return array<string, string> public name → primop id
 */
function publicPrimopAliases(): array
{
    static $table = null;

    return $table ??= [':' => 'listCons#'];
}

/**
 * The primop a public operator name stands for, or null when the name is not
 * one: `(:)` is `listCons#`.
 *
 * A name that is an alias has no declaration of its own -- the machine is its
 * only home -- so the call site means the primop wherever the name appears.
 */
function primopForPublicOperator(string $name): ?string
{
    return publicPrimopAliases()[$name] ?? null;
}

/**
 * The fixities of the machine's public names.
 *
 * @return array<string, array{assoc: string, prec: int}>
 */
function publicFixities(): array
{
    static $table = null;

    return $table ??= [':' => ['assoc' => 'infixr', 'precedence' => 5]];
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
        'IOArray#' => ['params' => ['i', 'e'], 'kindArity' => 2],
    ];
}

/**
 * Primitive type constructors owned by Moggi.Internal.ST.
 *
 * `ST#` is the state thread itself, `STRef#` a mutable cell that lives in
 * one, and `RealWorld#` the token that marks the thread `stToIO#` runs in
 * `IO`. None of them has a surface `forall`: `runST#` is compiler-blessed
 * and its escape check lives beside the ST boundary.
 *
 * @return array<string, array{params: list<string>, kindArity: int}>
 */
function stTypeTable(): array
{
    static $table = null;

    return $table ??= [
        'ST#' => ['params' => ['s', 'a'], 'kindArity' => 2],
        'STRef#' => ['params' => ['s', 'a'], 'kindArity' => 2],
        'STArray#' => ['params' => ['s', 'i', 'e'], 'kindArity' => 3],
        'RealWorld#' => ['params' => [], 'kindArity' => 0],
    ];
}

/** @return array<string, array{params: list<string>, kindArity: int}> */
function allPrimitiveTypeTable(): array
{
    static $table = null;

    return $table ??= primTypeTable() + ioTypeTable() + stTypeTable();
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
    if (isset(stTypeTable()[$name])) {
        return MODULE_ST;
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
