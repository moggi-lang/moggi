<?php declare(strict_types=1);

namespace Moggi\Optimize\FoldIntrinsic;

use Moggi\IR;

use function Moggi\IR\Visit\mapGuards;
use function Moggi\Optimize\CaseFold\isPlausibleConstructorName;
use function Moggi\Optimize\Interproc\denseStmtItems;
use function Moggi\Optimize\Interproc\specializationLookupNameSafe;
use function Moggi\Optimize\Support\foldExpr;
use function Moggi\Optimize\Support\mapStmtNestedBlocks;
use function Moggi\Optimize\Support\operandEqual;
use function Moggi\Optimize\Support\operandToExpr;
use function Moggi\Optimize\Support\tryFoldBinopInt;

/**
 * Fold intrinsics in a statement list, resolving Temps to known pure list/ctor
 * values defined earlier in the same block so `listAppend#(t0, t1)` folds when
 * `t0`/`t1` are known list spines (even if multi-use blocked copy-prop).
 *
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function foldIntrinsicsItems(array $items): array
{
    $known = [];
    $out = [];
    foreach (denseStmtItems($items) as $item) {
        $folded = foldIntrinsicsStmtWithKnown($item, $known);
        if ($folded instanceof IR\Assign) {
            $val = $folded->value;
            if (isKnownListOperand($val) || isKnownCtorOperand($val)) {
                $known[$folded->dest] = $val;
            }
        } elseif ($folded instanceof IR\Call) {
            // Pure ctor applications sometimes lower as Call.
            $asExpr = new IR\ExprCall($folded->callee, $folded->args);
            if (isKnownCtorOperand($asExpr)) {
                $known[$folded->dest] = $asExpr;
            }
        }
        $out[] = $folded;
    }

    return $out;
}

/** @param array<int, IR\Operand> $known */
function foldIntrinsicsStmtWithKnown(IR\Stmt $stmt, array $known): IR\Stmt
{
    $foldOp = static fn (IR\Operand $op): IR\Operand => foldOperandResolving($op, $known);

    $folded = match ($stmt::class) {
        IR\Ret::class => new IR\Ret($foldOp($stmt->value)),
        IR\Assign::class => new IR\Assign($stmt->dest, $foldOp($stmt->value)),
        IR\Let::class => new IR\Let($stmt->name, $foldOp($stmt->value)),
        IR\Binop::class => new IR\Binop($stmt->op, $foldOp($stmt->left), $foldOp($stmt->right), $stmt->dest),
        IR\Call::class => new IR\Call($stmt->callee, \array_map($foldOp, $stmt->args), $stmt->dest, $stmt->srcLoc),
        IR\CallValue::class => new IR\CallValue($foldOp($stmt->callee), \array_map($foldOp, $stmt->args), $stmt->dest, $stmt->srcLoc),
        IR\TailRecall::class => new IR\TailRecall(\array_map($foldOp, $stmt->args)),
        IR\MatchStmt::class => new IR\MatchStmt(
            $foldOp($stmt->scrutinee),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(foldIntrinsicsItems($arm->body->items)),
                    mapGuards($arm->guards, $foldOp, foldIntrinsicsStmt(...)),
                ),
                $stmt->arms,
            ),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\MatchReturn::class => new IR\MatchReturn(
            $foldOp($stmt->scrutinee),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(foldIntrinsicsItems($arm->body->items)),
                    mapGuards($arm->guards, $foldOp, foldIntrinsicsStmt(...)),
                ),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        default => mapStmtNestedBlocks(
            $stmt,
            static fn (IR\Block $block): IR\Block => new IR\Block(foldIntrinsicsItems($block->items)),
        ),
    };

    return $folded;
}

function foldIntrinsicsStmt(IR\Stmt $stmt): IR\Stmt
{
    return foldIntrinsicsStmtWithKnown($stmt, []);
}

/** @param array<int, IR\Operand> $known */
function foldOperandResolving(IR\Operand $operand, array $known): IR\Operand
{
    if ($operand instanceof IR\Temp && isset($known[$operand->id])) {
        return foldOperandResolving($known[$operand->id], $known);
    }

    return foldOperand($operand, $known);
}

/**
 * @param array<int, IR\Operand> $known
 */
function foldOperand(IR\Operand $operand, array $known = []): IR\Operand
{
    $resolve = static fn (IR\Operand $op): IR\Operand => foldOperandResolving($op, $known);

    // Intrinsic args are resolved once here; a second resolution re-walked every nested spine
    // and made arity-15+ optimization effectively non-terminating.
    return match ($operand::class) {
        IR\Intrinsic::class => foldIntrinsicNode(
            new IR\Intrinsic($operand->name, \array_map($resolve, $operand->args), $operand->srcLoc),
            $known,
            argsAlreadyResolved: true,
        ),
        IR\ExprBinop::class => $operand->op === '<>'
            ? foldOperand(new IR\Intrinsic('stringAppend#', [$resolve($operand->left), $resolve($operand->right)]), $known)
            : operandFromExpr(foldExpr(new IR\ExprBinop(
                $operand->op,
                operandToExpr($resolve($operand->left)),
                operandToExpr($resolve($operand->right)),
            ))),
        IR\ExprCall::class => new IR\ExprCall(
            $operand->callee,
            \array_map($resolve, $operand->args),
            $operand->srcLoc,
        ),
        IR\ExprCallValue::class => (static function () use ($operand, $resolve): IR\Operand {
            $callee = $resolve($operand->callee);
            $args = \array_map($resolve, $operand->args);
            // call_value @id(x) / @Module::id(x) → x (field-label / tag wrappers)
            if (
                count($args) === 1
                && $callee instanceof IR\FnRef
                && (
                    $callee->name === 'id'
                    || str_ends_with($callee->name, '::id')
                    || specializationLookupNameSafe($callee->name) === 'id'
                )
            ) {
                return $args[0];
            }

            return new IR\ExprCallValue($callee, $args, $operand->srcLoc);
        })(),
        IR\Partial::class => new IR\Partial($operand->fn, $operand->arity, \array_map($resolve, $operand->args)),
        IR\ExprPartial::class => new IR\ExprPartial($operand->fn, $operand->arity, \array_map(operandToExpr(...), $operand->args)),
        IR\ListLit::class => new IR\ListLit(\array_map($resolve, $operand->elements)),
        default => $operand,
    };
}

function operandFromExpr(IR\Operand $expr): IR\Operand
{
    return match ($expr::class) {
        IR\ConstInt::class => $expr,
        IR\ConstDouble::class => $expr,
        IR\ConstStr::class => $expr,
        IR\ConstChar::class => $expr,
        IR\ExprBinop::class, IR\ExprCall::class, IR\ExprCallValue::class, IR\ExprPartial::class => $expr,
        default => $expr,
    };
}

/**
 * @param array<int, IR\Operand> $known
 * @param bool $argsAlreadyResolved when true, skip re-walking args (caller folded them)
 */
function foldIntrinsicNode(IR\Intrinsic $node, array $known = [], bool $argsAlreadyResolved = false): IR\Operand
{
    $name = $node->name;
    $args = $argsAlreadyResolved
        ? $node->args
        : \array_map(
            static fn (IR\Operand $a): IR\Operand => foldOperandResolving($a, $known),
            $node->args,
        );        $folded = match ($name) {
        'intAdd#' => foldIntAdd($args),
        'intSub#' => foldIntBinop('-', $args),
        'intMul#' => foldIntMul($args),
        'stringAppend#' => foldStringAppend($args),
        'bytesAppend#' => foldStringAppend($args),
        'listAppend#' => foldListAppend($args),
        'listCons#' => foldListConsSpine($args),
        'listHead#' => foldListHead($args),
        'listTail#' => foldListTail($args),
        default => null,
    };

    if ($folded !== null) {
        // Recurse only for arithmetic/string rewrites that may still be
        // Intrinsic. Known-list folds must not re-enter (listCons#↔ListLit).
        // Rewrites rebuild args from already-resolved operands — do not resolve again.
        if (
            $folded instanceof IR\Intrinsic
            && !\in_array($folded->name, ['listCons#', 'listAppend#', 'list_nil', 'listHead#', 'listTail#'], true)
        ) {
            return foldIntrinsicNode($folded, $known, argsAlreadyResolved: true);
        }

        return $folded;
    }

    return new IR\Intrinsic($name, $args, $node->srcLoc);
}

/** @param list<IR\Operand> $args */
function foldIntAdd(array $args): ?IR\Operand
{
    $const = foldIntBinop('+', $args);
    if ($const !== null) {
        return $const;
    }

    if (operandEqual($args[0], $args[1])) {
        return new IR\Intrinsic('intMul#', [$args[0], new IR\ConstInt(2)]);
    }

    if ($args[0] instanceof IR\Intrinsic && $args[1] instanceof IR\Intrinsic) {
        $left = $args[0];
        $right = $args[1];
        if ($left->name === 'intMul#' && $right->name === 'intMul#'
            && operandEqual($left->args[0], $right->args[0])
            && $left->args[1] instanceof IR\ConstInt
            && $right->args[1] instanceof IR\ConstInt
            && \is_int($sum = $left->args[1]->value + $right->args[1]->value)) {
            return new IR\Intrinsic('intMul#', [$left->args[0], new IR\ConstInt($sum)]);
        }
    }

    return null;
}

/** @param list<IR\Operand> $args */
function foldIntMul(array $args): ?IR\Operand
{
    $const = foldIntBinop('*', $args);
    if ($const !== null) {
        return $const;
    }

    if ($args[1] instanceof IR\ConstInt && $args[1]->value === 1) {
        return $args[0];
    }

    if ($args[0] instanceof IR\ConstInt && $args[0]->value === 1) {
        return $args[1];
    }

    if ($args[0] instanceof IR\Intrinsic
        && $args[0]->name === 'intMul#'
        && ($args[0]->args[1] ?? null) instanceof IR\ConstInt
        && $args[1] instanceof IR\ConstInt
        && \is_int($product = $args[0]->args[1]->value * $args[1]->value)) {
        return new IR\Intrinsic('intMul#', [$args[0]->args[0], new IR\ConstInt($product)]);
    }

    return null;
}

/** @param list<IR\Operand> $args */
function foldIntBinop(string $op, array $args): ?IR\Operand
{
    if (!($args[0] instanceof IR\ConstInt) || !($args[1] instanceof IR\ConstInt)) {
        return null;
    }

    $folded = tryFoldBinopInt($op, $args[0]->value, $args[1]->value);

    return $folded === null ? null : new IR\ConstInt($folded);
}

/** @param array<int, IR\Operand> $args */
function foldStringAppend(array $args): ?IR\Operand
{
    if ($args[0] instanceof IR\ConstStr && $args[1] instanceof IR\ConstStr) {
        return new IR\ConstStr($args[0]->value . $args[1]->value);
    }

    return null;
}

/**
 * Collapse a known `listCons#` spine into ListLit when the tail is known.
 *
 * @param array<int, IR\Operand> $args
 */
function foldListConsSpine(array $args): ?IR\Operand
{
    if (count($args) !== 2) {
        return null;
    }
    $flat = flattenKnownList(new IR\Intrinsic('listCons#', $args));
    if ($flat === null) {
        return null;
    }
    // Always ListLit — never re-emit listCons# (that re-enters this fold).
    return new IR\ListLit($flat);
}

/** @param array<int, IR\Operand> $args */
function foldListHead(array $args): ?IR\Operand
{
    if (count($args) !== 1) {
        return null;
    }
    $xs = $args[0];
    if ($xs instanceof IR\Intrinsic && $xs->name === 'listCons#' && count($xs->args) === 2) {
        return $xs->args[0];
    }
    if ($xs instanceof IR\ListLit && $xs->elements !== []) {
        return $xs->elements[0];
    }

    return null;
}

/** @param array<int, IR\Operand> $args */
function foldListTail(array $args): ?IR\Operand
{
    if (count($args) !== 1) {
        return null;
    }
    $xs = $args[0];
    if ($xs instanceof IR\Intrinsic && $xs->name === 'listCons#' && count($xs->args) === 2) {
        return $xs->args[1];
    }
    if ($xs instanceof IR\ListLit && $xs->elements !== []) {
        return new IR\ListLit(\array_slice($xs->elements, 1));
    }

    return null;
}

/**
 * Fold `listAppend#` of known list spines into a single listCons# / ListLit chain.
 *
 * @param array<int, IR\Operand> $args
 */
function foldListAppend(array $args): ?IR\Operand
{
    if (count($args) !== 2) {
        return null;
    }

    $left = flattenKnownList($args[0]);
    $right = flattenKnownList($args[1]);
    if ($left === null || $right === null) {
        if (isKnownEmptyList($args[0])) {
            return $args[1];
        }
        if (isKnownEmptyList($args[1])) {
            return $args[0];
        }

        return null;
    }

    return rebuildKnownList([...$left, ...$right]);
}

function isKnownEmptyList(IR\Operand $operand): bool
{
    return ($operand instanceof IR\ListLit && $operand->elements === [])
        || ($operand instanceof IR\Intrinsic && $operand->name === 'list_nil' && $operand->args === []);
}

function isKnownListOperand(IR\Operand $operand): bool
{
    return flattenKnownList($operand) !== null || isKnownEmptyList($operand);
}

function isKnownCtorOperand(IR\Operand $operand): bool
{
    if (!($operand instanceof IR\ExprCall)) {
        return false;
    }
    $leaf = $operand->callee;
    if (str_contains($leaf, '::')) {
        $leaf = substr($leaf, strrpos($leaf, '::') + 2);
    }
    if (preg_match('/^__tuple\d+$/', $leaf) === 1) {
        return true;
    }

    return isPlausibleConstructorName($operand->callee);
}

/** @return list<IR\Operand>|null */
function flattenKnownList(IR\Operand $operand): ?array
{
    if ($operand instanceof IR\ListLit) {
        return $operand->elements;
    }
    if ($operand instanceof IR\Intrinsic && $operand->name === 'list_nil' && $operand->args === []) {
        return [];
    }
    if ($operand instanceof IR\Intrinsic && $operand->name === 'listCons#' && count($operand->args) === 2) {
        $tail = flattenKnownList($operand->args[1]);
        if ($tail === null) {
            return null;
        }

        return [$operand->args[0], ...$tail];
    }

    return null;
}

/** @param list<IR\Operand> $elems */
function rebuildKnownList(array $elems): IR\Operand
{
    $allSimple = true;
    foreach ($elems as $el) {
        if (
            !($el instanceof IR\Local)
            && !($el instanceof IR\Temp)
            && !($el instanceof IR\ConstInt)
            && !($el instanceof IR\ConstStr)
            && !($el instanceof IR\ConstChar)
            && !($el instanceof IR\ConstDouble)
            && !($el instanceof IR\FnRef)
            && !($el instanceof IR\ExprCall)
        ) {
            $allSimple = false;
            break;
        }
    }
    if ($allSimple) {
        return new IR\ListLit($elems);
    }

    $acc = new IR\ListLit([]);
    for ($i = count($elems) - 1; $i >= 0; --$i) {
        $acc = new IR\Intrinsic('listCons#', [$elems[$i], $acc]);
    }

    return $acc;
}
