<?php declare(strict_types=1);

namespace Moggi\Optimize\GlobalPass;

use Moggi\IR;

use function Moggi\IR\Visit\mapOperandChildren;
use function Moggi\Optimize\Interproc\countLocalUsesInItems;
use function Moggi\Optimize\Interproc\operandIsNonDuplicable;
use function Moggi\Optimize\Support\combineOperandsToExpr;
use function Moggi\Optimize\Support\isLambdaName;
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
        IR\Binop::class => 'binop:' . $stmt->op . ':' . operandKey($stmt->left) . ':' . operandKey($stmt->right),
        // Do not CSE calls that may allocate or mutate host state (foreign /
        // host-effect). Nullary constructors like `newStringWriter` must not
        // collapse to a shared temp across unrelated uses, and must not be
        // treated as pure CAFs by later rematerialization.
        IR\Call::class => isHostEffectCallee($stmt->callee)
            ? null
            : 'call:' . $stmt->callee . ':' . join(',', \array_map(operandKey(...), $stmt->args)),
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
        if (!$changed) {
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
            $stmt->dest,
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
    global $propagateCopiesLambdaBodies, $propagateCopiesLambdaParams;

    /** @var array<string, array<string, true>> */
    $freeLocalsByLambda = [];

    foreach ($items as $item) {
        foreach (lambdaNamesReferencedInStmt($item) as $lambdaName) {
            $body = $propagateCopiesLambdaBodies[$lambdaName] ?? null;
            if ($body === null) {
                continue;
            }

            $free = $freeLocalsByLambda[$lambdaName] ?? null;
            if ($free === null) {
                $params = \array_fill_keys($propagateCopiesLambdaParams[$lambdaName] ?? [], true);
                $free = [];
                foreach (lambdaBodyLocals($body) as $localName => $_) {
                    if (!isset($params[$localName])) {
                        $free[$localName] = true;
                    }
                }
                $freeLocalsByLambda[$lambdaName] = $free;
            }

            foreach ($free as $localName => $_) {
                $used['locals'][$localName] = true;
            }
        }
    }
}

/** @param array{temps: array<int, true>, locals: array<string, true>} $used */
function collectUsedStmt(IR\Stmt $stmt, array &$used): void
{
    switch ($stmt::class) {
        case IR\Ret::class:
        case IR\Assign::class:
        case IR\Let::class:
            collectUsedExpr($stmt->value, $used);
            return;
        case IR\Binop::class:
            collectUsedExpr($stmt->left, $used);
            collectUsedExpr($stmt->right, $used);
            return;
        case IR\Call::class:
            collectUsedOperands($stmt->args, $used);
            return;
        case IR\IoCall::class:
            collectUsedOperands($stmt->args, $used);
            $callee = $stmt->callee ?? null;
            if (\is_string($callee) && $callee !== '') {
                $used['locals'][$callee] = true;
            }
            return;
        case IR\IoRun::class:
            collectUsedOperand($stmt->action, $used);
            return;
        case IR\IoThrow::class:
            collectUsedOperand($stmt->exception, $used);
            return;
        case IR\IoCatch::class:
            collectUsedOperand($stmt->action, $used);
            collectUsedOperand($stmt->handler, $used);
            return;
        case IR\IoFinally::class:
            collectUsedOperand($stmt->action, $used);
            collectUsedOperand($stmt->cleanup, $used);
            return;
        case IR\CallValue::class:
            collectUsedExpr($stmt->callee, $used);
            collectUsedOperands($stmt->args, $used);
            return;
        case IR\DictCall::class:
            collectUsedOperand($stmt->evidence, $used);
            collectUsedOperands($stmt->args, $used);
            return;
        case IR\TailRecall::class:
            collectUsedOperands($stmt->args, $used);
            return;
        case IR\MatchStmt::class:
        case IR\MatchReturn::class:
        case IR\IoMatch::class:
            collectUsedExpr($stmt->scrutinee, $used);
            foreach ($stmt->arms as $arm) {
                foreach ($arm->body->items as $armItem) {
                    collectUsedStmt($armItem, $used);
                }
                foreach ($arm->guards as $guard) {
                    collectUsedOperand($guard->cond, $used);
                    foreach ($guard->prep->items as $prepItem) {
                        collectUsedStmt($prepItem, $used);
                    }
                }
            }
            return;
        case IR\Loop::class:
            foreach ($stmt->body->items as $loopItem) {
                collectUsedStmt($loopItem, $used);
            }
            return;
        default:
            return;
    }
}

/** @param list<IR\Operand> $operands @param array{temps: array<int, true>, locals: array<string, true>} $used */
function collectUsedOperands(array $operands, array &$used): void
{
    foreach ($operands as $operand) {
        collectUsedOperand($operand, $used);
    }
}

/** @param array{temps: array<int, true>, locals: array<string, true>} $used */
function collectUsedOperand(IR\Operand $operand, array &$used): void
{
    if ($operand instanceof IR\ListLit) {
        collectUsedOperands($operand->elements, $used);

        return;
    }

    collectUsedExpr($operand, $used);
}

/** @param array{temps: array<int, true>, locals: array<string, true>} $used */
function collectUsedExpr(IR\Operand $expr, array &$used): void
{
    switch ($expr::class) {
        case IR\Temp::class:
            $used['temps'][$expr->id] = true;
            return;
        case IR\Local::class:
            $used['locals'][$expr->name] = true;
            return;
        case IR\ExprBinop::class:
            collectUsedExpr($expr->left, $used);
            collectUsedExpr($expr->right, $used);
            return;
        case IR\ExprCall::class:
            collectUsedOperands($expr->args, $used);
            return;
        case IR\ExprCallValue::class:
            collectUsedExpr($expr->callee, $used);
            collectUsedOperands($expr->args, $used);
            return;
        case IR\Intrinsic::class:
            collectUsedOperands($expr->args, $used);
            return;
        case IR\ListLit::class:
            collectUsedOperands($expr->elements, $used);
            return;
        case IR\Partial::class:
        case IR\ExprPartial::class:
            collectUsedOperands($expr->args, $used);
            return;
        case IR\DictMethod::class:
            collectUsedOperand($expr->evidence, $used);
            return;
        default:
            return;
    }
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
 * Callee names with host (foreign) effects — consulted by {@see isDeadStmt}.
 *
 * @var array<string, true>
 */
$GLOBALS['moggi_host_effect_fns'] = [];

/** @param array<string, true> $names */
function setHostEffectFunctions(array $names): void
{
    $GLOBALS['moggi_host_effect_fns'] = $names;
}

function clearHostEffectFunctions(): void
{
    $GLOBALS['moggi_host_effect_fns'] = [];
}

function isHostEffectCallee(string $name): bool
{
    return isset($GLOBALS['moggi_host_effect_fns'][$name]);
}

function isDeadStmt(IR\Stmt $stmt, array $used, ?int $matchDest = null): bool
{
    $unusedTemp = static function (int $dest) use ($used, $matchDest): bool {
        return !isset($used['temps'][$dest])
            && !($matchDest !== null && $dest === $matchDest);
    };

    return match ($stmt::class) {
        IR\Assign::class => $unusedTemp($stmt->dest) && !operandHasHostEffect($stmt->value),
        IR\Binop::class => $unusedTemp($stmt->dest),
        IR\Call::class => $unusedTemp($stmt->dest) && !isHostEffectCallee($stmt->callee),
        IR\CallValue::class => $unusedTemp($stmt->dest) && !operandHasHostEffect($stmt->callee),
        IR\Let::class => !isset($used['locals'][$stmt->name]) && !operandHasHostEffect($stmt->value),
        default => false,
    };
}

function operandHasHostEffect(IR\Operand $operand): bool
{
    return match ($operand::class) {
        IR\ForeignCall::class => true,
        IR\ExprCall::class => isHostEffectCallee($operand->callee) || operandsHaveHostEffect($operand->args),
        IR\ExprCallValue::class => operandHasHostEffect($operand->callee) || operandsHaveHostEffect($operand->args),
        IR\FnRef::class => isHostEffectCallee($operand->name),
        IR\Partial::class => isHostEffectCallee($operand->fn) || operandsHaveHostEffect($operand->args),
        IR\ListLit::class => operandsHaveHostEffect($operand->elements),
        IR\ExprBinop::class => operandHasHostEffect($operand->left) || operandHasHostEffect($operand->right),
        default => false,
    };
}

/** @param list<IR\Operand> $operands */
function operandsHaveHostEffect(array $operands): bool
{
    foreach ($operands as $operand) {
        if (operandHasHostEffect($operand)) {
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
    if (!($pattern instanceof IR\PatCon)) {
        return $arm;
    }

    $cleanup = cleanupFieldExtractLets($arm->body->items, $pattern, $scrutinee);
    // skipped temps are pattern locals (names), not temp-id remaps — replace
    // Temp(tN) uses with Local(var) so removing `__fieldN(scrut)` defs is safe.
    $items = rewriteSkippedFieldTempsToLocals($cleanup['items'], $cleanup['temps']);
    $items = rewriteFieldExprUses($items, $pattern, $scrutinee);

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
        IR\Binop::class => new IR\Binop(
            $stmt->op,
            $rewriteOp($stmt->left),
            $rewriteOp($stmt->right),
            $stmt->dest,
        ),
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
            $stmt->dest,
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
function isFieldExtractCall(IR\Call|IR\ExprCall $call, IR\Operand $scrutinee): bool
{
    return str_starts_with($call->callee, '__field')
        && count($call->args) === 1
        && operandEqual($call->args[0], $scrutinee);
}

function fieldExtractIndex(string $callee): int
{
    $leaf = $callee;
    if (str_contains($callee, '::')) {
        $leaf = substr($callee, strrpos($callee, '::') + 2);
    } elseif (str_contains($callee, '\\')) {
        $leaf = substr($callee, strrpos($callee, '\\') + 1);
    }
    if (str_starts_with($leaf, '__tuple_field')) {
        return (int) substr($leaf, strlen('__tuple_field'));
    }
    if (str_starts_with($leaf, '__field')) {
        return (int) substr($leaf, strlen('__field'));
    }

    return (int) substr($callee, strlen('__field'));
}

/**
 * @param list<IR\Stmt> $items
 * @param IR\Pattern $pattern
 * @param IR\Operand $scrutinee
 * @return list<IR\Stmt>
 */
function rewriteFieldExprUses(array $items, IR\Pattern $pattern, IR\Operand $scrutinee): array
{
    $fieldVars = constructorFieldVars($pattern);

    return \array_map(
        static fn (IR\Stmt $item): IR\Stmt => rewriteFieldTempsInStmt($item, $fieldVars, $scrutinee),
        $items,
    );
}

/**
 * @param array<int, string> $fieldVars
 * @param IR\Operand $scrutinee
 */
function rewriteFieldTempsInStmt(IR\Stmt $stmt, array $fieldVars, IR\Operand $scrutinee): IR\Stmt
{
    return match ($stmt::class) {
        IR\Ret::class => new IR\Ret(rewriteFieldExpr($stmt->value, $fieldVars, $scrutinee)),
        IR\Binop::class => new IR\Binop($stmt->op, rewriteFieldOperand($stmt->left, $fieldVars, $scrutinee), rewriteFieldOperand($stmt->right, $fieldVars, $scrutinee), $stmt->dest),
        IR\Call::class => new IR\Call($stmt->callee, \array_map(
            static fn (IR\Operand $arg): IR\Operand => rewriteFieldOperand($arg, $fieldVars, $scrutinee),
            $stmt->args,
        ), $stmt->dest, $stmt->srcLoc),
        IR\CallValue::class => new IR\CallValue(rewriteFieldOperand($stmt->callee, $fieldVars, $scrutinee), \array_map(
            static fn (IR\Operand $arg): IR\Operand => rewriteFieldOperand($arg, $fieldVars, $scrutinee),
            $stmt->args,
        ), $stmt->dest, $stmt->srcLoc),
        IR\DictCall::class => new IR\DictCall(
            rewriteFieldOperand($stmt->evidence, $fieldVars, $scrutinee),
            $stmt->method,
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => rewriteFieldOperand($arg, $fieldVars, $scrutinee),
                $stmt->args,
            ),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\Assign::class => new IR\Assign($stmt->dest, rewriteFieldOperand($stmt->value, $fieldVars, $scrutinee)),
        IR\Let::class => new IR\Let($stmt->name, rewriteFieldOperand($stmt->value, $fieldVars, $scrutinee)),
        IR\MatchStmt::class => new IR\MatchStmt(
            rewriteFieldOperand($stmt->scrutinee, $fieldVars, $scrutinee),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(\array_map(
                        static fn (IR\Stmt $s): IR\Stmt => rewriteFieldTempsInStmt($s, $fieldVars, $scrutinee),
                        denseItems($arm->body->items),
                    )),
                    IR\Visit\mapGuards(
                        $arm->guards,
                        static fn (IR\Operand $g): IR\Operand => rewriteFieldOperand($g, $fieldVars, $scrutinee),
                        static fn (IR\Stmt $s): IR\Stmt => rewriteFieldTempsInStmt($s, $fieldVars, $scrutinee),
                    ),
                ),
                $stmt->arms,
            ),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\MatchReturn::class => new IR\MatchReturn(
            rewriteFieldOperand($stmt->scrutinee, $fieldVars, $scrutinee),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(\array_map(
                        static fn (IR\Stmt $s): IR\Stmt => rewriteFieldTempsInStmt($s, $fieldVars, $scrutinee),
                        denseItems($arm->body->items),
                    )),
                    IR\Visit\mapGuards(
                        $arm->guards,
                        static fn (IR\Operand $g): IR\Operand => rewriteFieldOperand($g, $fieldVars, $scrutinee),
                        static fn (IR\Stmt $s): IR\Stmt => rewriteFieldTempsInStmt($s, $fieldVars, $scrutinee),
                    ),
                ),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        IR\TailRecall::class => new IR\TailRecall(\array_map(
            static fn (IR\Operand $arg): IR\Operand => rewriteFieldOperand($arg, $fieldVars, $scrutinee),
            $stmt->args,
        )),
        IR\Loop::class => new IR\Loop(new IR\Block(\array_map(
            static fn (IR\Stmt $s): IR\Stmt => rewriteFieldTempsInStmt($s, $fieldVars, $scrutinee),
            denseItems($stmt->body->items),
        ))),
        default => $stmt,
    };
}

/**
 * @param array<int, string> $fieldVars
 * @param IR\Operand $scrutinee
 */
function rewriteFieldOperand(IR\Operand $operand, array $fieldVars, IR\Operand $scrutinee): IR\Operand
{
    if ($operand instanceof IR\ExprBinop) {
        return rewriteFieldExpr($operand, $fieldVars, $scrutinee);
    }

    if ($operand instanceof IR\ExprCall || $operand instanceof IR\ExprCallValue) {
        return rewriteFieldExpr($operand, $fieldVars, $scrutinee);
    }

    return $operand;
}

/**
 * @param array<int, string> $fieldVars
 * @param IR\Operand $scrutinee
 */
function rewriteFieldExpr(IR\Operand $expr, array $fieldVars, IR\Operand $scrutinee): IR\Operand
{
    if ($expr instanceof IR\ExprCall
        && isFieldExtractCall($expr, $scrutinee)
    ) {
        $index = fieldExtractIndex($expr->callee);
        $var = $fieldVars[$index] ?? null;
        if ($var !== null) {
            return new IR\Local($var);
        }
    }

    if ($expr instanceof IR\ExprBinop) {
        return new IR\ExprBinop(
            $expr->op,
            rewriteFieldExpr($expr->left, $fieldVars, $scrutinee),
            rewriteFieldExpr($expr->right, $fieldVars, $scrutinee),
        );
    }

    if ($expr instanceof IR\ExprCall) {
        return new IR\ExprCall(
            $expr->callee,
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => rewriteFieldExpr($arg, $fieldVars, $scrutinee),
                $expr->args,
            ),
            $expr->srcLoc,
        );
    }

    if ($expr instanceof IR\ExprCallValue) {
        return new IR\ExprCallValue(
            rewriteFieldExpr($expr->callee, $fieldVars, $scrutinee),
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => rewriteFieldExpr($arg, $fieldVars, $scrutinee),
                $expr->args,
            ),
            $expr->srcLoc,
        );
    }

    return $expr;
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
    // Temps are single-assignment, so counting uses across the block says whether inlining a
    // compound value would duplicate it; computed lazily.
    $useCounts = null;
    // local-name => last statement index at which a referenced lambda captures
    // it; lazily built once so the let-vs-lambda-capture guard is O(1) per let
    // instead of re-slicing + re-walking every remaining lambda body.
    $captureIndex = null;

    foreach ($block->items as $itemIndex => $item) {
        $item = mapCopyStmt($item, $temps, $locals);

        if ($item instanceof IR\Assign && isPropagatableExpr($item->value)) {
            if ($matchDest !== null && $item->dest === $matchDest) {
                $items[] = $item;
                if (isSimpleOperand($item->value)
                    && !operandIsNonDuplicable($item->value)
                ) {
                    $temps[$item->dest] = $item->value;
                }
                continue;
            }

            // Nullary foreign seeds (@newStringWriter) rematerialize on every
            // FnRef use — keep the Assign when the temp is used more than once.
            // Single-use seeds may still copy-prop (Opt-Nullary-Ref / @report).
            if (operandIsNonDuplicable($item->value)) {
                $useCounts ??= tempUseCountsInItems($block->items);
                if (($useCounts[$item->dest] ?? 0) > 1) {
                    $items[] = $item;
                    continue;
                }
            }

            // Trivial operands are free to duplicate; compound expressions are
            // only inlined when the temp is used at most once, otherwise keep
            // the binding materialized to avoid duplication / blow-up.
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

            // Multi-use Local of a nullary seed must stay bound (a host-writer
            // seed pinned across calls). Single-use @report may still fold.
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

        if ($item instanceof IR\Binop) {
            if ($matchDest !== null && $item->dest === $matchDest) {
                $items[] = $item;
                continue;
            }

            $expr = combineOperandsToExpr($item->op, $item->left, $item->right);
            if (isPropagatableExpr($expr)) {
                if (isSimpleOperand($expr)) {
                    $temps[$item->dest] = $expr;
                    continue;
                }

                $useCounts ??= tempUseCountsInItems($block->items);
                if (($useCounts[$item->dest] ?? 0) <= 1) {
                    $temps[$item->dest] = $expr;
                    continue;
                }

                unset($temps[$item->dest]);
                $items[] = new IR\Assign($item->dest, $expr);
                continue;
            }

            if (isSimpleOperand($item->left) && isSimpleOperand($item->right)) {
                unset($temps[$item->dest]);
                $items[] = $item;
            } else {
                unset($temps[$item->dest]);
                $items[] = new IR\Assign($item->dest, $expr);
            }

            continue;
        }

        if ($item instanceof IR\Call || $item instanceof IR\CallValue || $item instanceof IR\DictCall) {
            unset($temps[$item->dest]);
        }
        if ($item instanceof IR\MatchStmt || $item instanceof IR\IoMatch) {
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
        // A guard and its preparation run before the arm body, so they see the
        // copies in scope at the arm start — not the ones the body adds.
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
        IR\Binop::class => new IR\Binop($stmt->op, mapCopyOperand($stmt->left, $temps, $locals), mapCopyOperand($stmt->right, $temps, $locals), $stmt->dest),
        IR\Call::class => new IR\Call($stmt->callee, \array_map(static fn (IR\Operand $a): IR\Operand => mapCopyOperand($a, $temps, $locals), $stmt->args), $stmt->dest, $stmt->srcLoc),
        IR\CallValue::class => new IR\CallValue(mapCopyOperand($stmt->callee, $temps, $locals), \array_map(static fn (IR\Operand $a): IR\Operand => mapCopyOperand($a, $temps, $locals), $stmt->args), $stmt->dest, $stmt->srcLoc),
        IR\DictCall::class => new IR\DictCall(mapCopyOperand($stmt->evidence, $temps, $locals), $stmt->method, \array_map(static fn (IR\Operand $a): IR\Operand => mapCopyOperand($a, $temps, $locals), $stmt->args), $stmt->dest, $stmt->srcLoc),
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
                static fn (IR\MatchArm $arm): IR\MatchArm => $mapArm($arm, $stmt->dest),
                $stmt->arms,
            ),
            $stmt->dest,
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

    // Every operand kind that can carry children must be rewritten here, or a
    // copy-propagated binding is dropped while a use of it survives (Partial
    // args were the concrete case: the use counter walks them, this mapper did
    // not, and `t = @f` was deleted under `partial g(t)`). `mapOperandChildren`
    // is the shared child walk, so the mapper cannot drift from the walker.
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
        if (isLambdaName($function->name)) {
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
    global $propagateCopiesLambdaBodies, $propagateCopiesLambdaParams;

    $lambdaLocals = [];
    $lastIndex = [];
    foreach ($items as $index => $item) {
        foreach (lambdaNamesReferencedInStmt($item) as $lambdaName) {
            $body = $propagateCopiesLambdaBodies[$lambdaName] ?? null;
            if ($body === null) {
                continue;
            }

            $locals = $lambdaLocals[$lambdaName] ??= (static function () use ($body, $lambdaName): array {
                global $propagateCopiesLambdaParams;
                $params = \array_fill_keys($propagateCopiesLambdaParams[$lambdaName] ?? [], true);
                $free = [];
                foreach (lambdaBodyLocals($body) as $localName => $_) {
                    if (!isset($params[$localName])) {
                        $free[$localName] = true;
                    }
                }

                return $free;
            })();
            foreach ($locals as $localName => $_) {
                $lastIndex[$localName] = $index;
            }
        }
    }

    return $lastIndex;
}

/** @param IR\Block $block @return array<string, true> */
function lambdaBodyLocals(IR\Block $block): array
{
    $locals = [];
    IR\Visit\walkBlock(
        $block,
        static function (IR\Stmt $stmt): void {
        },
        static function (IR\Operand $operand) use (&$locals): void {
            if ($operand instanceof IR\Local) {
                $locals[$operand->name] = true;
            }
        },
    );

    return $locals;
}

/** @return array<int, string> */
function lambdaNamesReferencedInStmt(IR\Stmt $stmt): array
{
    $names = [];
    $visit = static function (IR\Operand $operand) use (&$names): void {
        if ($operand instanceof IR\FnRef && isLambdaName($operand->name)) {
            $names[] = $operand->name;
        }

        if ($operand instanceof IR\Partial && isLambdaName($operand->fn)) {
            $names[] = $operand->fn;
        }

        if ($operand instanceof IR\ExprPartial && isLambdaName($operand->fn)) {
            $names[] = $operand->fn;
        }
    };

    IR\Visit\walkOperands(IR\Visit\stmtDirectOperands($stmt), $visit);

    return array_values(array_unique($names));
}
