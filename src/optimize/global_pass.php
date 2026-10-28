<?php declare(strict_types=1);

namespace Moggi\Optimize\GlobalPass;

use Moggi\IR;

use function Moggi\IR\Visit\boundLocalsInBlock;
use function Moggi\IR\Visit\freeLocalsInBlock;
use function Moggi\IR\Visit\mapOperandChildren;
use function Moggi\IR\Visit\walkOperand;
use function Moggi\IR\Visit\walkStmt;
use function Moggi\Modules\isQualifiedSymbol;
use function Moggi\Optimize\Interproc\countLocalUsesInItems;
use function Moggi\Optimize\Interproc\operandIsNonDuplicable;
use function Moggi\Optimize\Support\isPropagatableExpr;
use function Moggi\Optimize\Support\isSimpleOperand;
use function Moggi\Optimize\Support\mapStmtNestedBlocks;
use function Moggi\Optimize\Support\operandEqual;
use function Moggi\Optimize\Support\operandKey;
use function Moggi\Optimize\Support\preserveMatchArm;
use function Moggi\Optimize\Support\stmtDest;
use function Moggi\Optimize\Support\tempUseCountsInItems;

function cseBlock(IR\Block $block): IR\Block
{
    return new IR\Block(cseItems($block->items));
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function cseItems(array $items): array
{
    $seen = [];
    $out = [];

    foreach ($items as $item) {
        $item = cseStmt($item);
        $key = cseKey($item);
        if ($key !== null && isset($seen[$key])) {
            $dest = stmtDest($item);
            if ($dest !== null) {
                $out[] = new IR\Assign($dest, new IR\Temp($seen[$key]));

                continue;
            }
        }

        if ($key !== null) {
            $dest = stmtDest($item);
            if ($dest !== null) {
                $seen[$key] = $dest;
            }
        }

        $out[] = $item;
    }

    return $out;
}

function cseStmt(IR\Stmt $stmt): IR\Stmt
{
    return mapStmtNestedBlocks($stmt, cseBlock(...));
}

function cseKey(IR\Stmt $stmt): ?string
{
    return match ($stmt::class) {
        IR\Call::class => calleeIsEffectful($stmt->callee)
            ? null
            : 'call:' . $stmt->callee . ':' . \implode(',', \array_map(operandKey(...), $stmt->args)),
        default => null,
    };
}

function dceBlock(IR\Block $block, ?int $matchDest = null): IR\Block
{
    return new IR\Block(dceItems($block->items, $matchDest));
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function dceItems(array $items, ?int $matchDest = null): array
{
    $items = \array_map(dceStmt(...), $items);
    for ($round = 0; $round < 16; ++$round) {
        $used = usedInItems($items);
        if ($matchDest !== null) {
            $used['temps'][$matchDest] = true;
        }
        $out = [];
        $changed = false;

        foreach ($items as $item) {
            if (isDeadStmt($item, $used, $matchDest)) {
                $changed = true;

                continue;
            }

            $out[] = $item;
        }

        $items = $out;
        if (! $changed) {
            break;
        }
    }

    return $items;
}

function dceStmt(IR\Stmt $stmt): IR\Stmt
{
    return match ($stmt::class) {
        IR\MatchStmt::class => new IR\MatchStmt($stmt->scrutinee, \array_map(
            static fn (IR\MatchArm $arm): IR\MatchArm => preserveMatchArm(
                $arm,
                dceBlock($arm->body, $stmt->dest),
            ),
            $stmt->arms,
        ), $stmt->dest, $stmt->exhaustive),
        IR\MatchReturn::class => new IR\MatchReturn(
            $stmt->scrutinee,
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => preserveMatchArm(
                    $arm,
                    dceBlock($arm->body),
                ),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        IR\IoMatch::class => new IR\IoMatch(
            $stmt->scrutinee,
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => preserveMatchArm(
                    $arm,
                    dceBlock($arm->body),
                ),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        default => mapStmtNestedBlocks($stmt, static fn (IR\Block $block): IR\Block => dceBlock($block)),
    };
}

/**
 * Collect the temps/locals used across a list of statements.
 *
 * Accumulates into a single set in-place (linear in IR size) rather than
 * rebuilding merged sets per statement, which was quadratic.
 *
 * @param list<IR\Stmt> $items @return array{temps: array<int, true>, locals: array<string, true>}
 */
function usedInItems(array $items): array
{
    $used = ['temps' => [], 'locals' => []];
    foreach ($items as $item) {
        collectUsedStmt($item, $used);
    }
    markLambdaCaptureLocalsUsed($items, $used);

    return $used;
}

/**
 * Locals free in a referenced lambda body are live in the enclosing block
 * (closure captures), even when not mentioned as `Local` operands here.
 *
 * @param list<IR\Stmt> $items
 * @param array{temps: array<int, true>, locals: array<string, true>} $used
 */
function markLambdaCaptureLocalsUsed(array $items, array &$used): void
{
    global $propagateCopiesLambdaBodies;

    /** @var array<string, array<string, true>> $memo */
    $memo = [];

    foreach ($items as $item) {
        foreach (lambdaNamesReferencedInStmt($item) as $lambdaName) {
            if (! isset($propagateCopiesLambdaBodies[$lambdaName])) {
                continue;
            }

            foreach (lambdaCapturedLocals($lambdaName, $memo) as $localName => $_) {
                $used['locals'][$localName] = true;
            }
        }
    }
}

/**
 * Collect locals captured transitively through referenced lifted lambdas.
 * Excludes lambda-bound names; memoization breaks recursive reference cycles.
 *
 * @param array<string, array<string, true>> $memo
 * @return array<string, true>
 */
function lambdaCapturedLocals(string $lambdaName, array &$memo): array
{
    global $propagateCopiesLambdaBodies, $propagateCopiesLambdaParams;

    $body = $propagateCopiesLambdaBodies[$lambdaName] ?? null;
    if ($body === null) {
        return [];
    }

    if (isset($memo[$lambdaName])) {
        return $memo[$lambdaName];
    }

    $memo[$lambdaName] = [];

    $params = \array_fill_keys($propagateCopiesLambdaParams[$lambdaName] ?? [], true);
    $captures = \array_fill_keys(freeLocalsInBlock($body, $params), true);
    $owned = boundLocalsInBlock($body) + $params;

    foreach (lambdaNamesReferencedInBlock($body) as $referenced) {
        if ($referenced === $lambdaName) {
            continue;
        }

        foreach (lambdaCapturedLocals($referenced, $memo) as $localName => $_) {
            if (! isset($owned[$localName])) {
                $captures[$localName] = true;
            }
        }
    }

    $memo[$lambdaName] = $captures;

    return $captures;
}

/**
 * Lifted lambdas a whole block refers to, at any nesting depth.
 *
 * @return list<string>
 */
function lambdaNamesReferencedInBlock(IR\Block $block): array
{
    $names = [];
    IR\Visit\walkBlock(
        $block,
        static function (IR\Stmt $stmt) use (&$names): void {
            foreach (lambdaNamesReferencedInStmt($stmt) as $name) {
                $names[] = $name;
            }
        },
        static function (IR\Operand $operand): void {
        },
    );

    return \array_values(\array_unique($names));
}

/**
 * Collect all Temp and Local reads in a statement, including nested bodies.
 *
 * Uses exhaustive shared walkers so new statement kinds cannot hide reads
 * from DCE. IoCall callees count as local uses.
 *
 * @param array{temps: array<int, true>, locals: array<string, true>} $used
 */
function collectUsedStmt(IR\Stmt $stmt, array &$used): void
{
    walkStmt(
        $stmt,
        static function (IR\Stmt $inner) use (&$used): void {
            if ($inner instanceof IR\IoCall) {
                $callee = $inner->callee;
                if (\is_string($callee) && $callee !== '') {
                    $used['locals'][$callee] = true;
                }
            }
        },
        static function (IR\Operand $operand) use (&$used): void {
            if ($operand instanceof IR\Temp) {
                $used['temps'][$operand->id] = true;
            } elseif ($operand instanceof IR\Local) {
                $used['locals'][$operand->name] = true;
            }
        },
    );
}

/** @param array{temps: array<int, true>, locals: array<string, true>} $used */
function collectUsedOperand(IR\Operand $operand, array &$used): void
{
    walkOperand($operand, static function (IR\Operand $inner) use (&$used): void {
        if ($inner instanceof IR\Temp) {
            $used['temps'][$inner->id] = true;
        } elseif ($inner instanceof IR\Local) {
            $used['locals'][$inner->name] = true;
        }
    });
}

/** @param IR\Operand $operand @return array{temps: array<int, true>, locals: array<string, true>} */
function usedInOperand(IR\Operand $operand): array
{
    $used = ['temps' => [], 'locals' => []];
    collectUsedOperand($operand, $used);

    return $used;
}

/** @param array{temps: array<int, true>, locals: array<string, true>} $used @param int|null $matchDest */
/**
 * Callee names whose call is an effect — foreign imports, workers, and every body the IO
 * annotation marks. Consulted by {@see isDeadStmt}, which may not delete their call.
 *
 * @var array<string, true>
 */
$GLOBALS['moggi_effectful_callees'] = [];

/** @param array<string, true> $names */
function setEffectfulCallees(array $names): void
{
    $GLOBALS['moggi_effectful_callees'] = $names;
}

function clearEffectfulCallees(): void
{
    $GLOBALS['moggi_effectful_callees'] = [];
}

function calleeIsEffectful(string $name): bool
{
    if (isset($GLOBALS['moggi_effectful_callees'][$name])) {
        return true;
    }

    return isQualifiedSymbol($name);
}

function isDeadStmt(IR\Stmt $stmt, array $used, ?int $matchDest = null): bool
{
    $unusedTemp = static function (int $dest) use ($used, $matchDest): bool {
        return ! isset($used['temps'][$dest])
            && ! ($matchDest !== null && $dest === $matchDest);
    };

    return match ($stmt::class) {
        IR\Assign::class => $unusedTemp($stmt->dest) && ! operandHasEffect($stmt->value),
        IR\Call::class => $unusedTemp($stmt->dest) && ! calleeIsEffectful($stmt->callee),
        IR\CallValue::class => $unusedTemp($stmt->dest) && ! operandHasEffect($stmt->callee),
        IR\Let::class => ! isset($used['locals'][$stmt->name]) && ! operandHasEffect($stmt->value),
        default => false,
    };
}

function operandHasEffect(IR\Operand $operand): bool
{
    return match ($operand::class) {
        IR\ForeignCall::class => true,
        IR\FnRef::class => calleeIsEffectful($operand->name),
        IR\Partial::class => calleeIsEffectful($operand->fn) || operandsHaveEffect($operand->args),
        IR\ListLit::class => operandsHaveEffect($operand->elements),
        default => false,
    };
}

/** @param list<IR\Operand> $operands */
function operandsHaveEffect(array $operands): bool
{
    foreach ($operands as $operand) {
        if (operandHasEffect($operand)) {
            return true;
        }
    }

    return false;
}

/**
 * @param IR\MatchArm $arm
 * @param IR\Operand $scrutinee
 */
function cleanupConstructorArm(IR\MatchArm $arm, IR\Operand $scrutinee): IR\MatchArm
{
    $pattern = $arm->pattern;
    if (! ($pattern instanceof IR\PatCon)) {
        return $arm;
    }

    $cleanup = cleanupFieldExtractLets($arm->body->items, $pattern, $scrutinee);
    $items = rewriteSkippedFieldTempsToLocals($cleanup['items'], $cleanup['temps']);

    return new IR\MatchArm($pattern, new IR\Block($items), $arm->guards);
}

/**
 * After dropping `tN = __fieldK(scrutinee)`, rewrite remaining Temp(tN) to the
 * pattern binder Local that field K names.
 *
 * @param list<IR\Stmt> $items
 * @param array<int, string> $tempToLocal
 * @return list<IR\Stmt>
 */
function rewriteSkippedFieldTempsToLocals(array $items, array $tempToLocal): array
{
    if ($tempToLocal === []) {
        return $items;
    }

    $rewriteOp = null;
    $rewriteOp = static function (IR\Operand $op) use (&$rewriteOp, $tempToLocal): IR\Operand {
        if ($op instanceof IR\Temp && isset($tempToLocal[$op->id])) {
            return new IR\Local($tempToLocal[$op->id]);
        }

        return mapOperandChildren($op, $rewriteOp);
    };

    return \array_map(
        static fn (IR\Stmt $stmt): IR\Stmt => rewriteStmtOperands($stmt, $rewriteOp),
        $items,
    );
}

/**
 * Rewrite every operand a statement owns, descending into nested blocks.
 *
 * Covers every statement kind in {@see stmtDirectOperands} / {@see stmtNestedBlocks}.
 * The Io statements used to fall through to `default => $stmt`, so their
 * operands (and, for `io_match` / `io_assign_action`, their bodies) were left
 * untouched — a silent no-op for any pass that relies on this helper.
 *
 * @param callable(IR\Operand): IR\Operand $rewriteOp
 */
function rewriteStmtOperands(IR\Stmt $stmt, callable $rewriteOp): IR\Stmt
{
    return match ($stmt::class) {
        IR\Ret::class => new IR\Ret($rewriteOp($stmt->value)),
        IR\Assign::class => new IR\Assign($stmt->dest, $rewriteOp($stmt->value)),
        IR\Let::class => new IR\Let($stmt->name, $rewriteOp($stmt->value)),
        IR\Call::class => new IR\Call(
            $stmt->callee,
            \array_map($rewriteOp, $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\CallValue::class => new IR\CallValue(
            $rewriteOp($stmt->callee),
            \array_map($rewriteOp, $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\DictCall::class => new IR\DictCall(
            $rewriteOp($stmt->evidence),
            $stmt->method,
            \array_map($rewriteOp, $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
            $stmt->localEvidence,
        ),
        IR\MatchStmt::class => new IR\MatchStmt(
            $rewriteOp($stmt->scrutinee),
            rewriteMatchArms($stmt->arms, $rewriteOp),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\MatchReturn::class => new IR\MatchReturn(
            $rewriteOp($stmt->scrutinee),
            rewriteMatchArms($stmt->arms, $rewriteOp),
            $stmt->exhaustive,
        ),
        IR\TailRecall::class => new IR\TailRecall(\array_map($rewriteOp, $stmt->args)),
        IR\Loop::class => new IR\Loop(rewriteBlockOperands($stmt->body, $rewriteOp)),
        IR\IoCall::class => new IR\IoCall(
            $stmt->callee,
            \array_map($rewriteOp, $stmt->args),
            $stmt->dest,
            $stmt->intrinsic,
            $stmt->runtime,
            $stmt->foreign,
            $stmt->srcLoc,
        ),
        IR\IoRun::class => new IR\IoRun($rewriteOp($stmt->action), $stmt->dest, $stmt->srcLoc),
        IR\IoThrow::class => new IR\IoThrow($rewriteOp($stmt->exception), $stmt->dest, $stmt->srcLoc),
        IR\IoCatch::class => new IR\IoCatch(
            $rewriteOp($stmt->action),
            $rewriteOp($stmt->handler),
            $stmt->dest,
        ),
        IR\IoFinally::class => new IR\IoFinally(
            $rewriteOp($stmt->action),
            $rewriteOp($stmt->cleanup),
            $stmt->dest,
        ),
        IR\IoAssignAction::class => new IR\IoAssignAction(
            $stmt->dest,
            rewriteBlockOperands($stmt->body, $rewriteOp),
            $rewriteOp($stmt->result),
            $stmt->srcLoc,
        ),
        IR\IoMatch::class => new IR\IoMatch(
            $rewriteOp($stmt->scrutinee),
            rewriteMatchArms($stmt->arms, $rewriteOp),
            $stmt->exhaustive,
        ),
        default => $stmt,
    };
}

/**
 * @param list<IR\MatchArm> $arms
 * @param callable(IR\Operand): IR\Operand $rewriteOp
 * @return list<IR\MatchArm>
 */
function rewriteMatchArms(array $arms, callable $rewriteOp): array
{
    return \array_map(
        static fn (IR\MatchArm $arm): IR\MatchArm => new IR\MatchArm(
            $arm->pattern,
            rewriteBlockOperands($arm->body, $rewriteOp),
            IR\Visit\mapGuards(
                $arm->guards,
                $rewriteOp,
                static fn (IR\Stmt $s): IR\Stmt => rewriteStmtOperands($s, $rewriteOp),
            ),
        ),
        $arms,
    );
}

/** @param callable(IR\Operand): IR\Operand $rewriteOp */
function rewriteBlockOperands(IR\Block $block, callable $rewriteOp): IR\Block
{
    return new IR\Block(\array_map(
        static fn (IR\Stmt $s): IR\Stmt => rewriteStmtOperands($s, $rewriteOp),
        denseItems($block->items),
    ));
}

/** @param list<IR\Stmt|mixed> $items @return list<IR\Stmt> */
function denseItems(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        if ($item instanceof IR\Stmt) {
            $out[] = $item;
        }
    }

    return $out;
}

/**
 * @param list<IR\Stmt> $items
 * @param IR\Pattern $pattern
 * @param IR\Operand $scrutinee
 * @return array{items: list<IR\Stmt>, temps: array<int, string>}
 */
function cleanupFieldExtractLets(array $items, IR\Pattern $pattern, IR\Operand $scrutinee): array
{
    $fieldVars = constructorFieldVars($pattern);
    $out = [];
    $skipTemps = [];

    foreach ($items as $item) {
        if ($item instanceof IR\Call && isFieldExtractCall($item, $scrutinee)) {
            $index = fieldExtractIndex($item->callee);
            $var = $fieldVars[$index] ?? null;
            if ($var !== null) {
                $skipTemps[$item->dest] = $var;

                continue;
            }
        }

        if ($item instanceof IR\Let && $item->value instanceof IR\Temp) {
            $tempId = $item->value->id;
            if (isset($skipTemps[$tempId])) {
                continue;
            }
        }

        $out[] = $item;
    }

    return ['items' => $out, 'temps' => $skipTemps];
}

/** @param IR\Pattern $pattern @return list<string> */
function constructorFieldVars(IR\Pattern $pattern): array
{
    $vars = [];
    foreach ($pattern->args as $i => $arg) {
        if ($arg instanceof IR\PatVar) {
            $vars[$i] = $arg->name;
        }
    }

    return $vars;
}

/** @param IR\Stmt $call @param IR\Operand $scrutinee */
function isFieldExtractCall(IR\Call $call, IR\Operand $scrutinee): bool
{
    return \str_starts_with($call->callee, '__field')
        && \count($call->args) === 1
        && operandEqual($call->args[0], $scrutinee);
}

function fieldExtractIndex(string $callee): int
{
    $leaf = $callee;
    if (\str_contains($callee, '::')) {
        $leaf = \substr($callee, \strrpos($callee, '::') + 2);
    } elseif (\str_contains($callee, '\\')) {
        $leaf = \substr($callee, \strrpos($callee, '\\') + 1);
    }
    if (\str_starts_with($leaf, '__tuple_field')) {
        return (int) \substr($leaf, \strlen('__tuple_field'));
    }
    if (\str_starts_with($leaf, '__field')) {
        return (int) \substr($leaf, \strlen('__field'));
    }

    return (int) \substr($callee, \strlen('__field'));
}

/**
 * @param array<int, IR\Operand> $temps
 * @param array<string, IR\Operand> $locals
 * @param int|null $matchDest When optimizing a {@see IR\MatchStmt} arm, the
 *        match result temp. Assignments to that dest must stay materialized:
 *        their uses live in the *parent* block, so arm-local use counts look
 *        like zero and would otherwise drop the binding (empty match arms).
 */
function propagateCopiesBlock(IR\Block $block, array $temps = [], array $locals = [], ?int $matchDest = null): IR\Block
{
    $items = [];
    $useCounts = null;
    $captureIndex = null;

    foreach ($block->items as $itemIndex => $item) {
        $item = mapCopyStmt($item, $temps, $locals);

        if ($item instanceof IR\Assign && isPropagatableExpr($item->value)) {
            if ($matchDest !== null && $item->dest === $matchDest) {
                $items[] = $item;
                if (isSimpleOperand($item->value)
                    && ! operandIsNonDuplicable($item->value)
                ) {
                    $temps[$item->dest] = $item->value;
                }

                continue;
            }

            if (operandIsNonDuplicable($item->value)) {
                $useCounts ??= tempUseCountsInItems($block->items);
                if (($useCounts[$item->dest] ?? 0) > 1) {
                    $items[] = $item;

                    continue;
                }
            }

            if (isSimpleOperand($item->value)) {
                $temps[$item->dest] = $item->value;
                if ($item->value instanceof IR\Temp) {
                    $items[] = $item;
                }

                continue;
            }

            $useCounts ??= tempUseCountsInItems($block->items);
            if (($useCounts[$item->dest] ?? 0) <= 1) {
                $temps[$item->dest] = $item->value;
                if ($item->value instanceof IR\Temp) {
                    $items[] = $item;
                }

                continue;
            }

            $items[] = $item;

            continue;
        }

        if ($item instanceof IR\Let && isPropagatableExpr($item->value)) {
            $captureIndex ??= lambdaCaptureIndex($block->items);
            if (($captureIndex[$item->name] ?? -1) > $itemIndex) {
                $items[] = $item;

                continue;
            }

            if (operandIsNonDuplicable($item->value)) {
                $localUses = countLocalUsesInItems($block->items, $item->name);
                if ($localUses > 1) {
                    $items[] = $item;

                    continue;
                }
            }

            $locals[$item->name] = $item->value;

            continue;
        }

        if ($item instanceof IR\Call || $item instanceof IR\CallValue || $item instanceof IR\DictCall) {
            unset($temps[$item->dest]);
        }
        if ($item instanceof IR\MatchStmt) {
            unset($temps[$item->dest]);
        }

        $items[] = $item;
    }

    return new IR\Block($items);
}

/** @param array<int, IR\Operand> $temps @param array<string, IR\Operand> $locals */
function mapCopyStmt(IR\Stmt $stmt, array &$temps, array &$locals): IR\Stmt
{
    $mapArm = static function (IR\MatchArm $arm, ?int $armMatchDest = null) use (&$temps, &$locals): IR\MatchArm {
        $guardTemps = $temps;
        $guardLocals = $locals;

        return new IR\MatchArm(
            $arm->pattern,
            propagateCopiesBlock($arm->body, $temps, $locals, $armMatchDest),
            IR\Visit\mapGuards(
                $arm->guards,
                static fn (IR\Operand $guard): IR\Operand => mapCopyOperand($guard, $guardTemps, $guardLocals),
                static fn (IR\Stmt $s): IR\Stmt => mapCopyStmt($s, $guardTemps, $guardLocals),
            ),
        );
    };

    return match ($stmt::class) {
        IR\Ret::class => new IR\Ret(mapCopyOperand($stmt->value, $temps, $locals)),
        IR\Call::class => new IR\Call($stmt->callee, \array_map(static fn (IR\Operand $a): IR\Operand => mapCopyOperand($a, $temps, $locals), $stmt->args), $stmt->dest, $stmt->srcLoc),
        IR\CallValue::class => new IR\CallValue(mapCopyOperand($stmt->callee, $temps, $locals), \array_map(static fn (IR\Operand $a): IR\Operand => mapCopyOperand($a, $temps, $locals), $stmt->args), $stmt->dest, $stmt->srcLoc),
        IR\DictCall::class => new IR\DictCall(mapCopyOperand($stmt->evidence, $temps, $locals), $stmt->method, \array_map(static fn (IR\Operand $a): IR\Operand => mapCopyOperand($a, $temps, $locals), $stmt->args), $stmt->dest, $stmt->srcLoc, $stmt->localEvidence),
        IR\Assign::class => new IR\Assign($stmt->dest, mapCopyOperand($stmt->value, $temps, $locals)),
        IR\Let::class => new IR\Let($stmt->name, mapCopyOperand($stmt->value, $temps, $locals)),
        IR\IoCall::class => new IR\IoCall($stmt->callee, \array_map(static fn (IR\Operand $a): IR\Operand => mapCopyOperand($a, $temps, $locals), $stmt->args), $stmt->dest, $stmt->intrinsic ?? null, $stmt->runtime ?? null, $stmt->foreign ?? null, $stmt->srcLoc),
        IR\IoRun::class => new IR\IoRun(mapCopyOperand($stmt->action, $temps, $locals), $stmt->dest, $stmt->srcLoc),
        IR\IoThrow::class => new IR\IoThrow(mapCopyOperand($stmt->exception, $temps, $locals), $stmt->dest, $stmt->srcLoc),
        IR\IoCatch::class => new IR\IoCatch(
            mapCopyOperand($stmt->action, $temps, $locals),
            mapCopyOperand($stmt->handler, $temps, $locals),
            $stmt->dest,
        ),
        IR\IoFinally::class => new IR\IoFinally(
            mapCopyOperand($stmt->action, $temps, $locals),
            mapCopyOperand($stmt->cleanup, $temps, $locals),
            $stmt->dest,
        ),
        IR\TailRecall::class => new IR\TailRecall(\array_map(static fn (IR\Operand $a): IR\Operand => mapCopyOperand($a, $temps, $locals), $stmt->args)),
        IR\MatchStmt::class => new IR\MatchStmt(
            mapCopyOperand($stmt->scrutinee, $temps, $locals),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => $mapArm($arm, $stmt->dest),
                $stmt->arms,
            ),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\MatchReturn::class => new IR\MatchReturn(
            mapCopyOperand($stmt->scrutinee, $temps, $locals),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => $mapArm($arm, null),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        IR\IoMatch::class => new IR\IoMatch(
            mapCopyOperand($stmt->scrutinee, $temps, $locals),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => $mapArm($arm, null),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        IR\Loop::class => new IR\Loop(propagateCopiesBlock($stmt->body, $temps, $locals)),
        IR\IoAssignAction::class => new IR\IoAssignAction(
            $stmt->dest,
            propagateCopiesBlock($stmt->body, $temps, $locals),
            mapCopyOperand($stmt->result, $temps, $locals),
            $stmt->srcLoc,
        ),
        default => $stmt,
    };
}

/** @param array<int, IR\Operand> $temps @param array<string, IR\Operand> $locals */
function mapCopyOperand(IR\Operand $operand, array $temps, array $locals): IR\Operand
{
    if ($operand instanceof IR\Temp && isset($temps[$operand->id])) {
        return mapCopyOperand($temps[$operand->id], $temps, $locals);
    }

    if ($operand instanceof IR\Local && isset($locals[$operand->name])) {
        return mapCopyOperand($locals[$operand->name], $temps, $locals);
    }

    return mapOperandChildren(
        $operand,
        static fn (IR\Operand $child): IR\Operand => mapCopyOperand($child, $temps, $locals),
    );
}

/** @var array<string, IR\Block> */
$propagateCopiesLambdaBodies = [];

/** @var array<string, list<string>> */
$propagateCopiesLambdaParams = [];

/** @param list<IR\FunctionDecl> $functions */
function setPropagateCopiesLambdaBodies(array $functions): void
{
    global $propagateCopiesLambdaBodies, $propagateCopiesLambdaParams;
    $propagateCopiesLambdaBodies = [];
    $propagateCopiesLambdaParams = [];
    foreach ($functions as $function) {
        if ($function->kind->takesCaptures()) {
            $propagateCopiesLambdaBodies[$function->name] = $function->body;
            $propagateCopiesLambdaParams[$function->name] = $function->params;
        }
    }
}

/**
 * Map every local name to the *last* statement index at which a referenced
 * lambda's body captures it. A `let x = ...` at index i may then be copy-
 * propagated (rather than kept materialized) iff no lambda referenced *after*
 * i captures `x`, i.e. `captureIndex[x] <= i`.
 *
 * Built in one pass with per-lambda free-local sets memoized, replacing the old
 * per-let `\array_slice` + repeated full lambda-body walks (which were quadratic
 * to cubic in blocks with many lets and closures).
 *
 * @param list<IR\Stmt> $items
 * @return array<string, int>
 */
function lambdaCaptureIndex(array $items): array
{
    global $propagateCopiesLambdaBodies;

    /** @var array<string, array<string, true>> $memo */
    $memo = [];
    $lambdaLocals = [];
    $lastIndex = [];
    foreach ($items as $index => $item) {
        foreach (lambdaNamesReferencedInStmt($item) as $lambdaName) {
            if (! isset($propagateCopiesLambdaBodies[$lambdaName])) {
                continue;
            }

            $locals = $lambdaLocals[$lambdaName] ??= lambdaCapturedLocals($lambdaName, $memo);
            foreach ($locals as $localName => $_) {
                $lastIndex[$localName] = $index;
            }
        }
    }

    return $lastIndex;
}

/**
 * Lifted-function names a statement refers to.
 *
 * A recursive `let`/`where` group is reached by name, not through an operand:
 * `call __letrecN(…)` carries its callee as a string, so it is read off the
 * statement rather than the operand tree.
 *
 * Nested blocks (match arms, loop bodies, `io` blocks) are descended into:
 * a lambda referenced only inside a nested match still captures the
 * enclosing block's locals, so a shallow scan would let DCE delete the
 * `let`s that feed its captures (e.g. an outer case binder used only by a
 * closure in an inner case arm).
 *
 * @return array<int, string>
 */
function lambdaNamesReferencedInStmt(IR\Stmt $stmt): array
{
    $names = [];

    global $propagateCopiesLambdaBodies;

    $captured = static fn (string $name): bool => isset($propagateCopiesLambdaBodies[$name]);

    $visitStmt = static function (IR\Stmt $s) use (&$names, $captured): void {
        if ($s instanceof IR\Call && $captured($s->callee)) {
            $names[] = $s->callee;
        }
    };

    $visit = static function (IR\Operand $operand) use (&$names, $captured): void {
        if ($operand instanceof IR\FnRef && $captured($operand->name)) {
            $names[] = $operand->name;
        }

        if ($operand instanceof IR\Partial && $captured($operand->fn)) {
            $names[] = $operand->fn;
        }

    };

    IR\Visit\walkStmt($stmt, $visitStmt, $visit);

    return \array_values(\array_unique($names));
}
