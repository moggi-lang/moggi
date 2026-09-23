<?php declare(strict_types=1);

namespace Moggi\Optimize\Support;

use Moggi\IR;

use function Moggi\Backend\Meta\lambdaMetaFromCaptures;
use function Moggi\IR\Visit\freeLocalsInBlock;
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
        IR\ExprBinop::class => $left->op === $right->op
            && operandEqual($left->left, $right->left)
            && operandEqual($left->right, $right->right),
        IR\ExprCall::class => $left->callee === $right->callee
            && count($left->args) === count($right->args)
            && array_all(
                $left->args,
                static fn (IR\Operand $arg, int $i): bool => operandEqual($arg, $right->args[$i]),
            ),
        IR\Intrinsic::class => $left->name === $right->name
            && count($left->args) === count($right->args)
            && array_all(
                $left->args,
                static fn (IR\Operand $arg, int $i): bool => operandEqual($arg, $right->args[$i]),
            ),
        IR\ListLit::class => count($left->elements) === count($right->elements)
            && array_all(
                $left->elements,
                static fn (IR\Operand $arg, int $i): bool => operandEqual($arg, $right->elements[$i]),
            ),
        default => false,
    };
}

function operandKey(IR\Operand $operand): string
{
    // Structural keys: CSE must distinguish nested ExprCalls (`__ev_*(@Eq_Pair)` vs
    // `__ev_*(@Eq_Int)`), or the wrong codec is used for a field.
    return match ($operand::class) {
        IR\ConstInt::class, IR\ConstDouble::class, IR\ConstStr::class, IR\ConstChar::class => 'c' . $operand->value,
        IR\Local::class => 'l' . $operand->name,
        IR\Temp::class => 't' . $operand->id,
        IR\FnRef::class => 'f' . $operand->name,
        IR\Unit::class => 'unit',
        IR\ListLit::class => 'list:' . join(',', \array_map(operandKey(...), $operand->elements)),
        IR\ExprBinop::class => 'binop:' . $operand->op . ':' . operandKey($operand->left) . ':' . operandKey($operand->right),
        IR\ExprCall::class => 'call:' . $operand->callee . '(' . join(',', \array_map(operandKey(...), $operand->args)) . ')',
        IR\ExprCallValue::class => 'callv:' . operandKey($operand->callee) . '(' . join(',', \array_map(operandKey(...), $operand->args)) . ')',
        IR\Intrinsic::class => 'intr:' . $operand->name . '(' . join(',', \array_map(operandKey(...), $operand->args)) . ')',
        IR\Partial::class => 'part:' . $operand->fn . '/' . $operand->arity . '(' . join(',', \array_map(operandKey(...), $operand->args)) . ')',
        IR\ExprPartial::class => 'epart:' . $operand->fn . '/' . $operand->arity . '(' . join(',', \array_map(operandKey(...), $operand->args)) . ')',
        IR\DictMethod::class => 'dictm:' . operandKey($operand->evidence) . '.' . $operand->method,
        IR\ForeignCall::class => 'foreign:' . $operand->backend . ':' . $operand->kind . ':' . $operand->path
            . '(' . join(',', \array_map(operandKey(...), $operand->args)) . ')',
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
        IR\ExprBinop::class => isPropagatableExpr($expr->left) && isPropagatableExpr($expr->right),
        IR\Intrinsic::class => array_reduce(
            $expr->args,
            static fn (bool $ok, IR\Operand $arg): bool => $ok && isPropagatableExpr($arg),
            true,
        ),
        IR\ListLit::class => array_reduce(
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

function operandToExpr(IR\Operand $operand): IR\Operand
{
    return match ($operand::class) {
        IR\ExprBinop::class, IR\ExprCall::class, IR\ExprCallValue::class, IR\ExprPartial::class => $operand,
        IR\Partial::class => new IR\ExprPartial($operand->fn, $operand->arity, $operand->args),
        default => $operand,
    };
}

function combineOperandsToExpr(string $op, IR\Operand $left, IR\Operand $right): IR\Operand
{
    return foldExpr(new IR\ExprBinop($op, operandToExpr($left), operandToExpr($right)));
}

function foldExpr(IR\Operand $expr): IR\Operand
{
    $expr = match ($expr::class) {
        IR\ExprBinop::class => new IR\ExprBinop(
            $expr->op,
            foldExpr($expr->left),
            foldExpr($expr->right),
        ),
        IR\ExprCall::class => new IR\ExprCall(
            $expr->callee,
            \array_map(foldExpr(...), $expr->args),
            $expr->srcLoc,
        ),
        IR\ExprCallValue::class => new IR\ExprCallValue(
            foldExpr($expr->callee),
            \array_map(foldExpr(...), $expr->args),
            $expr->srcLoc,
        ),
        default => $expr,
    };

    if (!$expr instanceof IR\ExprBinop) {
        return $expr;
    }

    $left = $expr->left;
    $right = $expr->right;

    if ($left instanceof IR\ConstInt && $right instanceof IR\ConstInt) {
        $folded = tryFoldBinopInt($expr->op, $left->value, $right->value);
        if ($folded !== null) {
            return new IR\ConstInt($folded);
        }
    }

    $identity = foldBinopPeephole($expr->op, $left, $right);
    if ($identity !== null) {
        return foldExpr($identity);
    }

    return $expr;
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

function foldBinopPeephole(string $op, IR\Operand $left, IR\Operand $right): ?IR\Operand
{
    if ($op === '-' && operandEqual($left, $right)) {
        return new IR\ConstInt(0);
    }

    if ($op === '+' && operandEqual($left, $right)) {
        return new IR\ExprBinop('*', $left, new IR\ConstInt(2));
    }

    return foldBinopIdentity($op, $left, $right);
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

function exprFromDefiningStmt(IR\Stmt $stmt, int $dest): ?IR\Operand
{
    return match ($stmt::class) {
        IR\Assign::class => $stmt->dest === $dest ? $stmt->value : null,
        IR\Binop::class => $stmt->dest === $dest
            ? new IR\ExprBinop($stmt->op, $stmt->left, $stmt->right)
            : null,
        IR\Call::class => $stmt->dest === $dest
            ? new IR\ExprCall($stmt->callee, $stmt->args, $stmt->srcLoc)
            : null,
        IR\CallValue::class => $stmt->dest === $dest
            ? new IR\ExprCallValue($stmt->callee, $stmt->args, $stmt->srcLoc)
            : null,
        default => null,
    };
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
        IR\ExprBinop::class => tempUseCountInRewritableExpr($expr->left, $id) + tempUseCountInRewritableExpr($expr->right, $id),
        IR\ExprCall::class => tempUseCountInRewritableOperands($expr->args, $id),
        IR\ExprCallValue::class => tempUseCountInRewritableExpr($expr->callee, $id) + tempUseCountInRewritableOperands($expr->args, $id),
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
    return str_starts_with($name, 'λ');
}

/** The top-level function a recursive local `let`/`where` group is lifted to. */
function isLetrecName(string $name): bool
{
    return str_starts_with($name, '__letrec');
}

/**
 * A `Bool` constructor. `Bool` is wired into every backend, so its constructors
 * are spelled bare in IR (`True`/`False` become the host boolean) and must not
 * be qualified to `Data.Bool::True` the way a user data constructor is.
 */
function isWiredInBoolCtor(string $name): bool
{
    return $name === 'True' || $name === 'False';
}

/**
 * A lifted function that is called at its free locals followed by its declared
 * parameters: an anonymous lambda, or the `__letrecN` function a recursive
 * `let`/`where` group is lowered to.
 */
function isCapturedFnName(string $name): bool
{
    return isLambdaName($name) || isLetrecName($name);
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
 * The captures of every lifted function in `$byName` (see
 * {@see indexCapturedFunctions}).
 *
 * @param array<string, IR\FunctionDecl> $byName
 * @return array<string, array{captures: list<string>, params: list<string>}>
 */
function buildLambdaMeta(array $byName): array
{
    // Direct captures only (the transitive closure lives in the shared helper); pattern
    // binders and Lets are bound, not captured.
    return lambdaMetaFromCaptures(
        $byName,
        static fn (IR\FunctionDecl $function): array => freeLocalsInBlock(
            $function->body,
            \array_fill_keys($function->params, true),
        ),
    );
}

/** The lifted functions of a module, the ones that take captures. @param list<IR\FunctionDecl> $functions */
function indexCapturedFunctions(array $functions): array
{
    $index = [];
    foreach ($functions as $function) {
        if (isCapturedFnName($function->name)) {
            $index[$function->name] = $function;
        }
    }

    return $index;
}
