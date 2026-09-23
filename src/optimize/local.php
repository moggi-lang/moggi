<?php declare(strict_types=1);

namespace Moggi\Optimize\Local;

use Moggi\IR;
use Moggi\Optimize\Support;

use function Moggi\IR\Visit\mapGuards;
use function Moggi\IR\Visit\mapOperandChildren;
use function Moggi\IR\Visit\operandChildren;
use function Moggi\IR\Visit\stmtDirectOperands;
use function Moggi\IR\Visit\walkStmt;
use function Moggi\Optimize\GlobalPass\cleanupConstructorArm;
use function Moggi\Optimize\GlobalPass\lambdaCaptureIndex;
use function Moggi\Optimize\Interproc\denseStmtItems;
use function Moggi\Optimize\Interproc\operandIsNonDuplicable;

function optimizeBlock(IR\Block $block, ?int $matchDest = null): IR\Block
{
    $temps = [];
    $locals = [];
    $items = [];
    $captureIndex = null;

    foreach ($block->items as $itemIndex => $item) {
        $item = mapStmt($item, $temps, $locals);

        if ($item instanceof IR\Assign) {
            if ($matchDest !== null && $item->dest === $matchDest) {
                if (Support\isPropagatableExpr($item->value)
                    && !operandIsNonDuplicable($item->value)
                ) {
                    $items[] = new IR\Ret($item->value);
                    continue;
                }

                $items[] = $item;
                continue;
            }

            if (Support\isPropagatableExpr($item->value)) {
                // Nullary foreign seeds (@newLinkedHashMap) rematerialize on
                // every FnRef use — keep the Assign so one allocation is shared.
                if (operandIsNonDuplicable($item->value)) {
                    $items[] = $item;
                    continue;
                }
                $temps[$item->dest] = $item->value;
                continue;
            }

            $items[] = $item;
            continue;
        }

        if ($item instanceof IR\Let) {
            if (Support\isPropagatableExpr($item->value)) {
                // Never bind a Local to itself — that creates a copy-prop cycle.
                if (
                    $item->value instanceof IR\Local
                    && $item->value->name === $item->name
                ) {
                    $items[] = $item;
                    continue;
                }
                if (operandIsNonDuplicable($item->value)) {
                    $items[] = $item;
                    continue;
                }
                $captureIndex ??= lambdaCaptureIndex($block->items);
                if (($captureIndex[$item->name] ?? -1) > $itemIndex) {
                    $items[] = $item;
                    continue;
                }

                $locals[$item->name] = $item->value;
                continue;
            }

            $items[] = $item;
            continue;
        }

        if ($item instanceof IR\Binop) {
            $left = $item->left;
            $right = $item->right;

            if ($left instanceof IR\ConstInt && $right instanceof IR\ConstInt) {
                $folded = Support\tryFoldBinopInt($item->op, $left->value, $right->value);
                if ($folded !== null) {
                    $temps[$item->dest] = new IR\ConstInt($folded);
                    continue;
                }
            }

            $identity = Support\foldBinopPeephole($item->op, $left, $right);
            if ($identity !== null) {
                if (Support\isPropagatableExpr($identity)) {
                    $temps[$item->dest] = $identity;
                    continue;
                }

                $items[] = new IR\Assign($item->dest, $identity);
                $temps[$item->dest] = $identity;
                continue;
            }

            $expr = Support\combineOperandsToExpr($item->op, $left, $right);
            if (Support\isPropagatableExpr($expr)) {
                $temps[$item->dest] = $expr;
                continue;
            }

            if (Support\isSimpleOperand($left) && Support\isSimpleOperand($right)) {
                $items[] = new IR\Binop($item->op, $left, $right, $item->dest);
            } else {
                $items[] = new IR\Assign($item->dest, $expr);
            }

            continue;
        }

        if ($item instanceof IR\Call || $item instanceof IR\CallValue) {
            // Temps are single-assignment in well-formed IR; if a Call reuses a
            // dest that was copy-propagated earlier, drop the stale mapping so
            // later uses see the Call result (not the prior simple assign).
            unset($temps[$item->dest]);
            $items[] = $item;
            continue;
        }

        if ($item instanceof IR\MatchStmt) {
            unset($temps[$item->dest]);
            $nestedMatchDest = $item->dest;
            $scrutinee = mapOperand($item->scrutinee, $temps, $locals);
            $items[] = new IR\MatchStmt(
                $scrutinee,
                \array_map(
                    static fn (IR\MatchArm $arm): IR\MatchArm => mapMatchArm($arm, $temps, $locals, $nestedMatchDest, $scrutinee),
                    $item->arms,
                ),
                $nestedMatchDest,
                $item->exhaustive,
            );
            continue;
        }

        if ($item instanceof IR\MatchReturn) {
            $scrutinee = mapOperand($item->scrutinee, $temps, $locals);
            $items[] = new IR\MatchReturn(
                $scrutinee,
                \array_map(
                    static fn (IR\MatchArm $arm): IR\MatchArm => mapMatchArm($arm, $temps, $locals, null, $scrutinee),
                    $item->arms,
                ),
                $item->exhaustive,
            );
            continue;
        }

        $items[] = $item;
    }

    $items = foldTailReturn($items);
    if ($matchDest !== null) {
        $items = foldArmMatchReturn($items, $matchDest);
    }

    return new IR\Block($items);
}

/** @param array<int, IR\Operand> $temps @param array<string, IR\Operand> $locals */
function mapMatchArm(IR\MatchArm $arm, array $temps, array $locals, ?int $matchDest, IR\Operand $scrutinee): IR\MatchArm
{
    $armTemps = $temps;
    $armLocals = $locals;
    $mappedItems = [];
    foreach ($arm->body->items as $item) {
        $mappedItems[] = mapStmt($item, $armTemps, $armLocals);
    }

    $optimized = cleanupConstructorArm(
        new IR\MatchArm($arm->pattern, optimizeBlock(new IR\Block($mappedItems), $matchDest), $arm->guards),
        $scrutinee,
    );

    if ($arm->guards === []) {
        return $optimized;
    }

    // A guard is tested before the body but after the bindings, so it sees the
    // substitutions in scope when the arm starts -- not the ones the body grows.
    $guardTemps = $temps;
    $guardLocals = $locals;

    return new IR\MatchArm(
        $optimized->pattern,
        $optimized->body,
        mapGuards(
            $arm->guards,
            static fn (IR\Operand $g): IR\Operand => mapOperand($g, $temps, $locals),
            static function (IR\Stmt $s) use (&$guardTemps, &$guardLocals): IR\Stmt {
                return mapStmt($s, $guardTemps, $guardLocals);
            },
        ),
    );
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function foldMatchReturn(array $items): array
{
    if (count($items) !== 2) {
        return $items;
    }

    $match = $items[0];
    $ret = $items[1];
    if (!$match instanceof IR\MatchStmt || !$ret instanceof IR\Ret) {
        return $items;
    }

    if (!$ret->value instanceof IR\Temp || $ret->value->id !== $match->dest) {
        return $items;
    }

    if (!matchArmsAllReturn($match)) {
        return $items;
    }

    return [new IR\MatchReturn($match->scrutinee, $match->arms, $match->exhaustive)];
}

function matchArmsAllReturn(IR\MatchStmt $match): bool
{
    foreach ($match->arms as $arm) {
        $body = $arm->body->items;
        if ($body === [] || !($body[count($body) - 1] instanceof IR\Ret)) {
            return false;
        }
    }

    return true;
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function foldArmMatchReturn(array $items, int $matchDest): array
{
    if ($items === []) {
        return $items;
    }

    $last = $items[count($items) - 1];
    if ($last instanceof IR\Ret) {
        return $items;
    }

    if (!$last instanceof IR\Assign || $last->dest !== $matchDest) {
        return $items;
    }

    if ($last->value instanceof IR\Temp && count($items) >= 2) {
        $expr = Support\exprFromDefiningStmt($items[count($items) - 2], $last->value->id);
        if ($expr !== null) {
            array_pop($items);
            array_pop($items);
            $items[] = new IR\Ret($expr);

            return $items;
        }
    }

    array_pop($items);
    $items[] = new IR\Ret($last->value);

    return $items;
}

/** @param array<int, IR\Operand> $temps @param array<string, IR\Operand> $locals */
function mapStmt(IR\Stmt $stmt, array &$temps, array &$locals): IR\Stmt
{
    return match ($stmt::class) {
        IR\Ret::class => new IR\Ret(mapOperand($stmt->value, $temps, $locals)),
        IR\Binop::class => new IR\Binop(
            $stmt->op,
            mapOperand($stmt->left, $temps, $locals),
            mapOperand($stmt->right, $temps, $locals),
            $stmt->dest,
        ),
        IR\Call::class => new IR\Call(
            $stmt->callee,
            \array_map(static fn (IR\Operand $arg): IR\Operand => mapOperand($arg, $temps, $locals), $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\CallValue::class => new IR\CallValue(
            mapOperand($stmt->callee, $temps, $locals),
            \array_map(static fn (IR\Operand $arg): IR\Operand => mapOperand($arg, $temps, $locals), $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\DictCall::class => new IR\DictCall(
            mapOperand($stmt->evidence, $temps, $locals),
            $stmt->method,
            \array_map(static fn (IR\Operand $arg): IR\Operand => mapOperand($arg, $temps, $locals), $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\IoCall::class => new IR\IoCall(
            $stmt->callee,
            \array_map(static fn (IR\Operand $arg): IR\Operand => mapOperand($arg, $temps, $locals), $stmt->args),
            $stmt->dest,
            $stmt->intrinsic ?? null,
            $stmt->runtime ?? null,
            $stmt->foreign ?? null,
            $stmt->srcLoc,
        ),
        IR\IoRun::class => new IR\IoRun(
            mapOperand($stmt->action, $temps, $locals),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\IoThrow::class => new IR\IoThrow(
            mapOperand($stmt->exception, $temps, $locals),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\IoCatch::class => new IR\IoCatch(
            mapOperand($stmt->action, $temps, $locals),
            mapOperand($stmt->handler, $temps, $locals),
            $stmt->dest,
        ),
        IR\IoFinally::class => new IR\IoFinally(
            mapOperand($stmt->action, $temps, $locals),
            mapOperand($stmt->cleanup, $temps, $locals),
            $stmt->dest,
        ),
        IR\Assign::class => new IR\Assign($stmt->dest, mapOperand($stmt->value, $temps, $locals)),
        IR\Let::class => new IR\Let($stmt->name, mapOperand($stmt->value, $temps, $locals)),
        IR\MatchStmt::class => new IR\MatchStmt(
            mapOperand($stmt->scrutinee, $temps, $locals),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => mapMatchArmOperands($arm, $temps, $locals),
                $stmt->arms,
            ),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\MatchReturn::class => new IR\MatchReturn(
            mapOperand($stmt->scrutinee, $temps, $locals),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => mapMatchArmOperands($arm, $temps, $locals),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        IR\IoMatch::class => new IR\IoMatch(
            mapOperand($stmt->scrutinee, $temps, $locals),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => mapMatchArmOperands($arm, $temps, $locals),
                $stmt->arms,
            ),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\IoAssignAction::class => (function () use ($stmt, $temps, $locals): IR\IoAssignAction {
            [$body, $result] = optimizeIoAssignAction($stmt->body, $stmt->result, $temps, $locals);

            return new IR\IoAssignAction($stmt->dest, $body, $result, $stmt->srcLoc);
        })(),
        IR\TailRecall::class => new IR\TailRecall(
            \array_map(static fn (IR\Operand $arg): IR\Operand => mapOperand($arg, $temps, $locals), $stmt->args),
        ),
        IR\Loop::class => new IR\Loop(new IR\Block(\array_map(
            static fn (IR\Stmt $item): IR\Stmt => mapStmt($item, $temps, $locals),
            $stmt->body->items,
        ))),
        default => $stmt,
    };
}

/** Substitute temps/locals inside a match arm without re-running block optimization. */
function mapMatchArmOperands(IR\MatchArm $arm, array $temps, array $locals): IR\MatchArm
{
    return new IR\MatchArm(
        $arm->pattern,
        new IR\Block(\array_map(
            static fn (IR\Stmt $item): IR\Stmt => mapStmt($item, $temps, $locals),
            $arm->body->items,
        )),
        mapGuards(
            $arm->guards,
            static fn (IR\Operand $guard): IR\Operand => mapOperand($guard, $temps, $locals),
            static fn (IR\Stmt $s): IR\Stmt => mapStmt($s, $temps, $locals),
        ),
    );
}

/** @param array<int, IR\Operand> $temps @param array<string, IR\Operand> $locals */
function mapOperand(IR\Operand $operand, array $temps, array $locals): IR\Operand
{
    // Cycle-safe alias chase. Identity lets (`let n = n`) or a↔b Local cycles
    // previously recursed until OOM when PE-fusion left broken locals maps.
    $seenTemps = [];
    $seenLocals = [];
    while (true) {
        if ($operand instanceof IR\Temp && isset($temps[$operand->id])) {
            if (isset($seenTemps[$operand->id])) {
                break;
            }
            $seenTemps[$operand->id] = true;
            $resolved = $temps[$operand->id];
            if ($resolved === $operand) {
                break;
            }
            $operand = $resolved;
            continue;
        }
        if ($operand instanceof IR\Local && isset($locals[$operand->name])) {
            if (isset($seenLocals[$operand->name])) {
                break;
            }
            $seenLocals[$operand->name] = true;
            $resolved = $locals[$operand->name];
            if ($resolved instanceof IR\Local && $resolved->name === $operand->name) {
                break;
            }
            $operand = $resolved;
            continue;
        }
        break;
    }

    if (operandChildren($operand) === []) {
        return $operand;
    }

    $mapped = mapOperandChildren(
        $operand,
        static fn (IR\Operand $child): IR\Operand => mapOperand($child, $temps, $locals),
    );

    if ($mapped instanceof IR\ExprBinop) {
        return Support\foldExpr($mapped);
    }

    return $mapped;
}

/** @param array<int, IR\Operand> $temps @param array<string, IR\Operand> $locals @return array{0: IR\Block, 1: IR\Operand} */
function optimizeIoAssignAction(IR\Block $body, IR\Operand $result, array $temps, array $locals): array
{
    $bodyTemps = $temps;
    $bodyLocals = $locals;
    $mappedItems = [];
    foreach ($body->items as $item) {
        $mappedItems[] = mapStmt($item, $bodyTemps, $bodyLocals);
    }

    $mappedResult = mapOperand($result, $bodyTemps, $bodyLocals);
    $optimized = optimizeBlock(new IR\Block([...$mappedItems, new IR\Ret($mappedResult)]));
    $items = $optimized->items;
    $tail = array_pop($items);
    if (!$tail instanceof IR\Ret) {
        throw new \RuntimeException('local optimize: io_assign_action lost result tail');
    }

    return [new IR\Block($items), $tail->value];
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function foldTailReturn(array $items): array
{
    $count = count($items);
    if ($count < 2) {
        return $items;
    }

    $last = $items[$count - 1];
    if (!$last instanceof IR\Ret) {
        return $items;
    }

    $prev = $items[$count - 2];
    $dest = Support\stmtDest($prev);
    if ($dest === null) {
        return $items;
    }

    $defExpr = Support\exprFromDefiningStmt($prev, $dest);
    if ($defExpr === null) {
        return $items;
    }

    $retValue = $last->value;
    if ($retValue instanceof IR\Temp && $retValue->id === $dest) {
        $expr = $defExpr;
    } elseif (Support\tempFoldsIntoExpr($retValue, $dest)
        && !Support\tempUsedInItems(\array_slice($items, 0, -2), $dest)) {
        $expr = Support\substituteTempInExpr($retValue, $dest, $defExpr);
    } else {
        return $items;
    }

    if (!exprTempsDefinedBefore($expr, $items, $count - 2)) {
        return $items;
    }

    array_pop($items);
    array_pop($items);
    $items[] = new IR\Ret($expr);

    return $items;
}

/** @param list<IR\Stmt> $items */
function exprTempsDefinedBefore(IR\Operand $expr, array $items, int $beforeIndex): bool
{
    $defined = [];
    for ($i = 0; $i <= $beforeIndex; ++$i) {
        $dest = Support\stmtDest($items[$i]);
        if ($dest !== null) {
            $defined[$dest] = true;
        }
    }

    return exprOperandsDefined($expr, $defined);
}

/**
 * `t = @f(xs)` directly in front of the one statement that reads `t`, with `t`
 * as that reader's first operand, becomes the reader with `@f(xs)` in its
 * place. The call still runs exactly once, still before everything else the
 * reader evaluates, and the local that held its result is gone.
 *
 * Reading `t` anywhere but the first operand position is what keeps this
 * order-preserving: a reader that evaluated something else first would move the
 * call past that evaluation, and a reader that reads `t` from an arm of a match
 * would run the call once per arm, or not at all — both stay untouched.
 *
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function foldSingleUseCallResults(array $items): array
{
    $items = denseStmtItems($items);
    $count = count($items);
    if ($count < 2) {
        return $items;
    }

    $out = [];
    for ($i = 0; $i < $count; ++$i) {
        $stmt = $items[$i];
        $dest = Support\stmtDest($stmt);
        $value = $dest === null ? null : Support\exprFromDefiningStmt($stmt, $dest);
        if ($value === null || $i + 1 >= $count) {
            $out[] = $stmt;
            continue;
        }

        $operands = stmtDirectOperands($items[$i + 1]);
        if ($operands === []
            || !$operands[0] instanceof IR\Temp
            || $operands[0]->id !== $dest
            || tempUsesInStmt($items[$i + 1], $dest) !== 1
            || tempUsesInItems(\array_slice($items, $i + 2), $dest) > 0
        ) {
            $out[] = $stmt;
            continue;
        }

        $reader = withFirstOperandReplaced($items[$i + 1], $dest, $value);
        if ($reader === null) {
            $out[] = $stmt;
            continue;
        }

        $out[] = $reader;
        ++$i;
    }

    return $out;
}

/**
 * `$stmt` with its first operand replaced by `$value`, or null when its first
 * operand is not `t{dest}` — or when the statement is not a plain reader.
 */
function withFirstOperandReplaced(IR\Stmt $stmt, int $dest, IR\Operand $value): ?IR\Stmt
{
    $isDest = static fn (IR\Operand $operand): bool
        => $operand instanceof IR\Temp && $operand->id === $dest;
    $firstArgIsDest = static fn (array $args): bool
        => $args !== [] && $args[0] instanceof IR\Temp && $args[0]->id === $dest;

    return match ($stmt::class) {
        IR\Ret::class => $isDest($stmt->value) ? new IR\Ret($value) : null,
        IR\Assign::class => $isDest($stmt->value) ? new IR\Assign($stmt->dest, $value) : null,
        IR\Let::class => $isDest($stmt->value) ? new IR\Let($stmt->name, $value) : null,
        IR\Binop::class => $isDest($stmt->left)
            ? new IR\Binop($stmt->op, $value, $stmt->right, $stmt->dest)
            : null,
        IR\Call::class => $firstArgIsDest($stmt->args)
            ? new IR\Call($stmt->callee, [$value, ...\array_slice($stmt->args, 1)], $stmt->dest, $stmt->srcLoc)
            : null,
        IR\CallValue::class => $firstArgIsDest($stmt->args)
            ? new IR\CallValue($stmt->callee, [$value, ...\array_slice($stmt->args, 1)], $stmt->dest, $stmt->srcLoc)
            : null,
        IR\IoCall::class => $firstArgIsDest($stmt->args)
            ? new IR\IoCall(
                $stmt->callee,
                [$value, ...\array_slice($stmt->args, 1)],
                $stmt->dest,
                $stmt->intrinsic,
                $stmt->runtime,
                $stmt->foreign,
                $stmt->srcLoc,
            )
            : null,
        default => null,
    };
}

/**
 * How many times `t{dest}` is read by this statement, at any depth.
 *
 * Counted through the generic walk: `Support\tempUseCountInStmt` covers the
 * arithmetic statements only, and a fold that misses one read of the temp it
 * deletes leaves the program with an undefined temp.
 */
function tempUsesInStmt(IR\Stmt $stmt, int $dest): int
{
    $count = 0;
    walkStmt($stmt, static function (): void {
    }, static function (IR\Operand $operand) use (&$count, $dest): void {
        if ($operand instanceof IR\Temp && $operand->id === $dest) {
            ++$count;
        }
    });

    return $count;
}

/** @param list<IR\Stmt> $items */
function tempUsesInItems(array $items, int $dest): int
{
    $count = 0;
    foreach ($items as $item) {
        $count += tempUsesInStmt($item, $dest);
    }

    return $count;
}

/** @param array<int, true> $defined */
function exprOperandsDefined(IR\Operand $expr, array $defined): bool
{
    return match ($expr::class) {
        IR\Temp::class => isset($defined[$expr->id]),
        IR\Local::class, IR\ConstInt::class, IR\FnRef::class => true,
        IR\ExprBinop::class => exprOperandsDefined($expr->left, $defined)
            && exprOperandsDefined($expr->right, $defined),
        IR\ExprCall::class => operandsDefined($expr->args, $defined),
        IR\ExprCallValue::class => exprOperandsDefined($expr->callee, $defined)
            && operandsDefined($expr->args, $defined),
        default => true,
    };
}

/** @param list<IR\Operand> $operands @param array<int, true> $defined */
function operandsDefined(array $operands, array $defined): bool
{
    foreach ($operands as $operand) {
        if (!exprOperandsDefined($operand, $defined)) {
            return false;
        }
    }

    return true;
}
