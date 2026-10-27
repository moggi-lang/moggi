<?php declare(strict_types=1);

namespace Moggi\Optimize\Support;


use Moggi\IR;

use function Moggi\IR\Visit\mapOperandChildren;
use function Moggi\IR\Visit\operandChildren;
use function Moggi\IR\Visit\stmtDirectOperands;
use function Moggi\IR\Visit\stmtNestedBlocks;
use function Moggi\IR\Visit\walkBlock;

function operandEqual(IR\Operand $left, IR\Operand $right): bool
{
    if ($left::class !== $right::class) {
        return false;
    }

    return match ($left::class) {
        IR\ConstInt::class, IR\ConstDouble::class, IR\ConstStr::class, IR\ConstChar::class => $left->value === $right->value,
        IR\Local::class => $left->name === $right->name,
        IR\Temp::class => $left->id === $right->id,
        IR\FnRef::class => $left->name === $right->name,
        IR\Intrinsic::class => $left->name === $right->name
            && \count($left->args) === \count($right->args)
            && \array_all(
                $left->args,
                static fn (IR\Operand $arg, int $i): bool => operandEqual($arg, $right->args[$i]),
            ),
        IR\ListLit::class => \count($left->elements) === \count($right->elements)
            && \array_all(
                $left->elements,
                static fn (IR\Operand $arg, int $i): bool => operandEqual($arg, $right->elements[$i]),
            ),
        default => false,
    };
}

function operandKey(IR\Operand $operand): string
{
    return match ($operand::class) {
        IR\ConstInt::class, IR\ConstDouble::class, IR\ConstStr::class, IR\ConstChar::class => 'c' . $operand->value,
        IR\Local::class => 'l' . $operand->name,
        IR\Temp::class => 't' . $operand->id,
        IR\FnRef::class => 'f' . $operand->name,
        IR\Unit::class => 'unit',
        IR\ListLit::class => 'list:' . \implode(',', \array_map(operandKey(...), $operand->elements)),
        IR\Intrinsic::class => 'intr:' . $operand->name . '(' . \implode(',', \array_map(operandKey(...), $operand->args)) . ')',
        IR\Partial::class => 'part:' . $operand->fn . '/' . $operand->arity . '(' . \implode(',', \array_map(operandKey(...), $operand->args)) . ')',
        IR\DictMethod::class => 'dictm:' . operandKey($operand->evidence) . '.' . $operand->method,
        IR\ForeignCall::class => 'foreign:' . $operand->backend . ':' . $operand->kind . ':' . $operand->path
            . '(' . \implode(',', \array_map(operandKey(...), $operand->args)) . ')',
        default => $operand::class,
    };
}

function stmtDest(IR\Stmt $stmt): ?int
{
    return match ($stmt::class) {
        IR\Assign::class, IR\Binop::class, IR\Call::class, IR\CallValue::class, IR\MatchStmt::class => $stmt->dest,
        default => null,
    };
}

/**
 * @param list<IR\Stmt> $items
 * @return array<int, int>
 */
function tempUseCountsInItems(array $items): array
{
    $counts = [];
    walkBlock(
        new IR\Block($items),
        static function (IR\Stmt $stmt): void {
        },
        static function (IR\Operand $operand) use (&$counts): void {
            if ($operand instanceof IR\Temp) {
                $counts[$operand->id] = ($counts[$operand->id] ?? 0) + 1;
            }
        },
    );

    return $counts;
}

function isPropagatableExpr(IR\Operand $expr): bool
{
    return match ($expr::class) {
        IR\ConstInt::class, IR\ConstDouble::class, IR\ConstStr::class, IR\ConstChar::class, IR\Local::class, IR\Temp::class, IR\FnRef::class, IR\Partial::class => true,
        IR\Intrinsic::class => \array_reduce(
            $expr->args,
            static fn (bool $ok, IR\Operand $arg): bool => $ok && isPropagatableExpr($arg),
            true,
        ),
        IR\ListLit::class => \array_reduce(
            $expr->elements,
            static fn (bool $ok, IR\Operand $arg): bool => $ok && isPropagatableExpr($arg),
            true,
        ),
        default => false,
    };
}

function isSimpleOperand(IR\Operand $operand): bool
{
    return $operand instanceof IR\ConstInt
        || $operand instanceof IR\ConstDouble
        || $operand instanceof IR\ConstStr
        || $operand instanceof IR\ConstChar
        || $operand instanceof IR\Local
        || $operand instanceof IR\Temp
        || $operand instanceof IR\FnRef
        || $operand instanceof IR\Partial;
}

/**
 * The operand a `Binop` statement folds to, or null when it stays a statement.
 *
 * A binop is a statement with a `dest`, so the only values that can stand for one are the constant
 * its arithmetic folds to and an operand an identity rule selects — never a binop expression of its
 * own, because a computation is not an operand.
 */
function foldedBinopOperand(string $op, IR\Operand $left, IR\Operand $right): ?IR\Operand
{
    if ($left instanceof IR\ConstInt && $right instanceof IR\ConstInt) {
        $folded = tryFoldBinopInt($op, $left->value, $right->value);
        if ($folded !== null) {
            return new IR\ConstInt($folded);
        }
    }

    return foldBinopIdentity($op, $left, $right);
}

/**
 * Fold compile-time Int arithmetic binops; comparisons stay runtime (Bool case
 * patterns). An overflowing fold is left alone: PHP promotes the host op to a
 * float, while Int arithmetic wraps at 64 bits, so the constant would disagree
 * with the code every backend emits for the same expression.
 */
function tryFoldBinopInt(string $op, int $left, int $right): ?int
{
    $folded = match ($op) {
        '+' => $left + $right,
        '-' => $left - $right,
        '*' => $left * $right,
        default => null,
    };

    return \is_int($folded) ? $folded : null;
}

function foldBinopIdentity(string $op, IR\Operand $left, IR\Operand $right): ?IR\Operand
{
    return match ($op) {
        '+' => match (true) {
            $left instanceof IR\ConstInt && $left->value === 0 => $right,
            $right instanceof IR\ConstInt && $right->value === 0 => $left,
            default => null,
        },
        '-' => match (true) {
            operandEqual($left, $right) => new IR\ConstInt(0),
            $right instanceof IR\ConstInt && $right->value === 0 => $left,
            default => null,
        },
        '*' => match (true) {
            $left instanceof IR\ConstInt && $left->value === 1 => $right,
            $right instanceof IR\ConstInt && $right->value === 1 => $left,
            $left instanceof IR\ConstInt && $left->value === 0 => new IR\ConstInt(0),
            $right instanceof IR\ConstInt && $right->value === 0 => new IR\ConstInt(0),
            default => null,
        },
        default => null,
    };
}

/**
 * The operand a defining statement stands for, or null when it must stay a statement.
 *
 * Only an `Assign` states a value. A `Binop` and a call are computations: folding one into its
 * reader would put a computation where the schema allows only values, so both stay statements and
 * their readers take the temp they define.
 */
function exprFromDefiningStmt(IR\Stmt $stmt, int $dest): ?IR\Operand
{
    return $stmt instanceof IR\Assign && $stmt->dest === $dest ? $stmt->value : null;
}

/**
 * Emit a call of a known callee as a statement and hand back the temp it defines.
 *
 * A rewrite that already knows which call it wants says so here, rather than building an operand
 * that some later walk has to take apart.
 *
 * `$dest` is the destination the caller already owns — the `dest` of the statement it is rewriting.
 * A caller that owns one passes it and the call writes it directly, so the value never needs a copy;
 * a caller that does not gets a fresh temp.
 *
 * @param list<IR\Operand> $args
 * @param list<IR\Stmt> $out appended to, in evaluation order
 * @return array{IR\Temp, int} the temp the call defines, and the cursor after it
 */
function scheduleCall(string $callee, array $args, array &$out, int $nextTemp, ?IR\SrcLoc $srcLoc = null, ?int $dest = null): array
{
    $dest ??= $nextTemp++;
    $out[] = new IR\Call($callee, $args, $dest, $srcLoc);

    return [new IR\Temp($dest), $nextTemp];
}

/**
 * How many times `t{$id}` is read at any depth, counted over the generic operand
 * tree ({@see operandChildren}). A hand-written `match` silently loses every read
 * on a node kind it forgot, and a caller that trusts such a count deletes a
 * definition something still reads.
 */
function tempUseCountInExpr(IR\Operand $expr, int $id): int
{
    $count = $expr instanceof IR\Temp && $expr->id === $id ? 1 : 0;
    foreach (operandChildren($expr) as $child) {
        $count += tempUseCountInExpr($child, $id);
    }

    return $count;
}

/**
 * Whether a single definition of `t{$id}` can be folded into `$expr` in place.
 *
 * {@see substituteTempInExpr} rewrites a fixed set of node kinds, so a read
 * anywhere else — inside an intrinsic, say — is not a read the fold can move,
 * and leaving it behind is what produced a reference to a deleted temp. The
 * generic count then has to agree: exactly one read, and one the rewrite covers.
 */
function tempFoldsIntoExpr(IR\Operand $expr, int $id): bool
{
    if (tempUseCountInExpr($expr, $id) !== 1) {
        return false;
    }

    return tempUseCountInRewritableExpr($expr, $id) === 1;
}

/** Reads of `t{$id}` on a path {@see substituteTempInExpr} rewrites. */
function tempUseCountInRewritableExpr(IR\Operand $expr, int $id): int
{
    return match ($expr::class) {
        IR\Temp::class => $expr->id === $id ? 1 : 0,
        default => 0,
    };
}

/** @param list<IR\Operand> $operands */
function tempUseCountInRewritableOperands(array $operands, int $id): int
{
    $count = 0;
    foreach ($operands as $operand) {
        $count += tempUseCountInRewritableExpr($operand, $id);
    }

    return $count;
}

/** @param list<IR\Stmt> $items */
function tempUsedInItems(array $items, int $id): bool
{
    foreach ($items as $item) {
        if ($item instanceof IR\Stmt && tempUseCountInStmt($item, $id) > 0) {
            return true;
        }
    }

    return false;
}

/** Counted over {@see stmtDirectOperands} + {@see stmtNestedBlocks}, so nested arm
 * bodies and the statements a guard needs count as uses too. */
function tempUseCountInStmt(IR\Stmt $stmt, int $id): int
{
    $count = 0;
    foreach (stmtDirectOperands($stmt) as $operand) {
        $count += tempUseCountInExpr($operand, $id);
    }

    foreach (stmtNestedBlocks($stmt) as $nested) {
        if (tempUsedInItems($nested->items, $id)) {
            $count += 1;
        }
    }

    return $count;
}

function substituteTempInExpr(IR\Operand $expr, int $id, IR\Operand $replacement): IR\Operand
{
    if ($expr instanceof IR\Temp && $expr->id === $id) {
        return $replacement;
    }

    return mapOperandChildren(
        $expr,
        static fn (IR\Operand $child): IR\Operand => substituteTempInExpr($child, $id, $replacement),
    );
}

function isLambdaName(string $name): bool
{
    return \str_starts_with($name, 'λ');
}

function preserveMatchArm(IR\MatchArm $arm, IR\Block $body): IR\MatchArm
{
    return $arm->withBody($body);
}

/** @param callable(IR\Block): IR\Block $mapBlock */
function mapStmtNestedBlocks(IR\Stmt $stmt, callable $mapBlock): IR\Stmt
{
    return match ($stmt::class) {
        IR\MatchStmt::class => new IR\MatchStmt(
            $stmt->scrutinee,
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => preserveMatchArm($arm, $mapBlock($arm->body)),
                $stmt->arms,
            ),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\MatchReturn::class => new IR\MatchReturn(
            $stmt->scrutinee,
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => preserveMatchArm($arm, $mapBlock($arm->body)),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        IR\Loop::class => new IR\Loop($mapBlock($stmt->body)),
        default => $stmt,
    };
}

/**
 * Whether reading a call's arguments runs nothing: a temp, a local, a literal or a function
 * reference is already a value, so a call built only from them can be dropped or repeated
 * without an observable effect.
 *
 * @param list<IR\Operand> $args
 */
function callArgsAreLeaves(array $args): bool
{
    foreach ($args as $arg) {
        if (! ($arg instanceof IR\Temp
            || $arg instanceof IR\Local
            || $arg instanceof IR\FnRef
            || $arg instanceof IR\ConstInt
            || $arg instanceof IR\ConstStr
            || $arg instanceof IR\ConstChar
            || $arg instanceof IR\ConstDouble
            || $arg instanceof IR\Unit)) {
            return false;
        }
    }

    return true;
}

/**
 * Drop the calls named in `$droppable` whose dest temp nothing else in the block reads.
 *
 * `$droppable` is the set of dests the caller has already established to be pure allocations
 * (a dictionary built from leaves, say). The set is decided from what the call *is* — its
 * indexed identity and its arguments — never from the callee's spelling, so an ordinary
 * declaration can never be mistaken for a factory. A call whose dest is not named is kept.
 *
 * @param list<IR\Stmt> $items
 * @param array<int, true> $droppable
 * @return list<IR\Stmt>
 */
function dropUnreadTemps(array $items, array $droppable): array
{
    if ($droppable === []) {
        return $items;
    }

    $used = [];
    walkBlock(
        new IR\Block($items),
        static function (IR\Stmt $stmt): void {
        },
        static function (IR\Operand $operand) use (&$used): void {
            if ($operand instanceof IR\Temp) {
                $used[$operand->id] = true;
            }
        },
    );

    $out = [];
    foreach ($items as $item) {
        if ($item instanceof IR\Call && isset($droppable[$item->dest]) && ! isset($used[$item->dest])) {
            continue;
        }

        $out[] = $item;
    }

    return $out;
}

/** The lifted functions of a module, the ones that take captures. @param list<IR\FunctionDecl> $functions */
function indexCapturedFunctions(array $functions): array
{
    $index = [];
    foreach ($functions as $function) {
        if ($function->kind->takesCaptures()) {
            $index[$function->name] = $function;
        }
    }

    return $index;
}
