<?php declare(strict_types=1);

namespace Moggi\Optimize\CaseFold;

use Moggi\IR;

use function Moggi\IR\Visit\mapGuards;
use function Moggi\IR\Visit\mapOperandChildren;
use function Moggi\IR\Visit\stmtDirectOperands;
use function Moggi\IR\Visit\stmtNestedBlocks;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Optimize\GlobalPass\fieldExtractIndex;
use function Moggi\Optimize\Interproc\denseStmtItems;
use function Moggi\Optimize\Interproc\maxTempInItems;
use function Moggi\Optimize\Interproc\operandIsNonDuplicable;
use function Moggi\Optimize\Interproc\replaceTempsInOperand;
use function Moggi\Optimize\Interproc\substituteStmt;
use function Moggi\Optimize\Support\scheduleCall;
use function Moggi\Semantics\IntrinsicRegistry\isListConsForm;
use function Moggi\Semantics\IntrinsicRegistry\isListSpineForm;
use function Moggi\Semantics\IntrinsicRegistry\listFormShape;
use function Moggi\Semantics\IntrinsicRegistry\primopForPublicOperator;
use function Moggi\Syntax\isConstructorName;

/**
 * Known constructor tree used for case-of-known-constructor and field folding.
 * Leaves are ordinary operands (locals, temps, constants, …).
 */
final class CtorTree
{
    /**
     * @param list<CtorTree|IR\Operand> $args
     * @param ?IR\SrcLoc $srcLoc where the constructor application was written, when it came from a
     *   statement: a tree is materialized into an operand later, and that operand has to carry the
     *   site the call had or the frame it should own leaves the module's map.
     */
    public function __construct(
        public string $name,
        public array $args,
        public ?IR\SrcLoc $srcLoc = null,
    ) {
    }
}

/**
 * Fold matches on known constructors, fold __fieldN of known ctors, and
 * push a trailing consumer into MatchStmt arms (case-of-case).
 *
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function foldKnownConstructors(array $items, int $depth = 0): array
{
    if ($depth > 64) {
        return denseStmtItems($items);
    }

    $items = denseStmtItems($items);
    for ($round = 0; $round < 8; ++$round) {
        $before = stmtFingerprint($items);
        $items = joinMatchStmtConsumer($items, $depth);
        $items = foldKnownMatchesInItems($items, $depth);
        $items = foldFieldExtractsInItems($items, $depth);
        $items = denseStmtItems($items);
        if (stmtFingerprint($items) === $before) {
            break;
        }
    }

    return $items;
}

/**
 * Structural fingerprint for case-fold fixpoint.
 *
 * Must change when operand shapes change (e.g. Assign t = __field0(K1(x)) →
 * Assign t = x). Counting stmt classes + dest alone is too weak and stranded
 * PE after a single specialize final-optimize pass.
 *
 * @param list<IR\Stmt> $items
 */
function stmtFingerprint(array $items): string
{
    $parts = [];
    foreach (denseStmtItems($items) as $s) {
        $parts[] = stmtShapeKey($s);
    }

    return \hash('xxh3', \implode("\n", $parts));
}

function stmtShapeKey(IR\Stmt $stmt): string
{
    return match ($stmt::class) {
        IR\Assign::class => 'Assign:' . $stmt->dest . '=' . operandShapeKey($stmt->value),
        IR\Let::class => 'Let:' . $stmt->name . '=' . operandShapeKey($stmt->value),
        IR\Ret::class => 'Ret:' . operandShapeKey($stmt->value),
        IR\Call::class => 'Call:' . $stmt->dest . '=' . $stmt->callee
            . '(' . operandListShapeKey($stmt->args) . ')',
        IR\CallValue::class => 'CallV:' . $stmt->dest . '=' . operandShapeKey($stmt->callee)
            . '(' . operandListShapeKey($stmt->args) . ')',
        IR\DictCall::class => 'Dict:' . $stmt->dest . '=' . operandShapeKey($stmt->evidence)
            . '.' . $stmt->method . '(' . operandListShapeKey($stmt->args) . ')',
        IR\MatchStmt::class => 'MatchS:' . $stmt->dest . '@' . operandShapeKey($stmt->scrutinee)
            . '#' . \count($stmt->arms),
        IR\MatchReturn::class => 'MatchR:@' . operandShapeKey($stmt->scrutinee)
            . '#' . \count($stmt->arms),
        default => $stmt::class,
    };
}

/** @param list<IR\Operand> $ops */
function operandListShapeKey(array $ops): string
{
    return \implode(',', \array_map(operandShapeKey(...), $ops));
}

function operandShapeKey(IR\Operand $op): string
{
    return match ($op::class) {
        IR\Local::class => 'l:' . $op->name,
        IR\Temp::class => 't:' . $op->id,
        IR\FnRef::class => 'f:' . $op->name,
        IR\ConstInt::class => 'i:' . $op->value,
        IR\ConstStr::class => 's:' . \strlen($op->value),
        IR\ConstChar::class => 'c:' . $op->value,
        IR\ConstDouble::class => 'd:' . $op->value,
        IR\Unit::class => 'unit',
        IR\Partial::class => 'p:' . $op->fn . '/' . $op->arity
            . '(' . operandListShapeKey($op->args) . ')',
        IR\Intrinsic::class => 'in:' . $op->name . '(' . operandListShapeKey($op->args) . ')',
        IR\DictMethod::class => 'dm:' . operandShapeKey($op->evidence) . '.' . $op->method,
        IR\ListLit::class => 'L[' . operandListShapeKey($op->elements) . ']',
        IR\ForeignCall::class => 'fc:' . $op->path . '(' . operandListShapeKey($op->args) . ')',
        default => $op::class,
    };
}

function foldKnownConstructorsBlock(IR\Block $block): IR\Block
{
    $items = denseStmtItems($block->items);
    $items = rewriteMatchStmtArmRets($items);
    $items = rewriteMidBlockMatchReturns($items);
    if (\count($items) > 80) {
        return new IR\Block($items);
    }

    return new IR\Block(foldKnownConstructors($items));
}

/**
 * MatchStmt arms must assign to dest; Ret inside a MatchStmt would return from
 * the enclosing function (wrong for `t = match ...`).
 *
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function rewriteMatchStmtArmRets(array $items): array
{
    $out = [];
    foreach (denseStmtItems($items) as $item) {
        if ($item instanceof IR\MatchStmt) {
            $arms = [];
            foreach ($item->arms as $arm) {
                $armItems = rewriteMatchStmtArmRets(denseStmtItems($arm->body->items));
                $last = $armItems !== [] ? $armItems[\count($armItems) - 1] : null;
                if ($last instanceof IR\Ret) {
                    \array_pop($armItems);
                    $armItems[] = new IR\Assign($item->dest, $last->value);
                }
                $arms[] = new IR\MatchArm($arm->pattern, new IR\Block($armItems), $arm->guards);
            }
            $out[] = new IR\MatchStmt($item->scrutinee, $arms, $item->dest, $item->exhaustive);

            continue;
        }
        if ($item instanceof IR\MatchReturn) {
            $out[] = mapMatchArms($item, static function (IR\MatchArm $arm): IR\MatchArm {
                return new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(rewriteMatchStmtArmRets($arm->body->items)),
                    $arm->guards,
                );
            });

            continue;
        }
        $out[] = $item;
    }

    return denseStmtItems($out);
}

/**
 * When `t = match …` is immediately consumed by `ret t`, `ret call f(t)`,
 * or `match_return t`, push the consumer into each arm.
 *
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function joinMatchStmtConsumer(array $items, int $depth = 0): array
{
    $items = denseStmtItems($items);
    if (\count($items) < 2) {
        return $items;
    }

    $out = [];
    $n = \count($items);
    for ($i = 0; $i < $n; ++$i) {
        $item = $items[$i];
        if (
            $item instanceof IR\MatchStmt
            && $i + 1 < $n
            && ! tempUsedInItems(\array_slice($items, $i + 2), $item->dest)
        ) {
            $next = $items[$i + 1];
            $consumer = matchStmtTailConsumer($next, $item->dest);
            if ($consumer !== null) {
                $arms = [];
                $nextTemp = maxTempInItems([...$out, $item]) + 1;
                foreach ($item->arms as $arm) {
                    $arms[] = new IR\MatchArm(
                        $arm->pattern,
                        new IR\Block(appendConsumerToArm($arm->body->items, $item->dest, $consumer, $nextTemp)),
                        $arm->guards,
                    );
                }
                $out[] = new IR\MatchReturn($item->scrutinee, $arms, $item->exhaustive);
                ++$i;

                continue;
            }

            if (
                $next instanceof IR\MatchReturn
                && $next->scrutinee instanceof IR\Temp
                && $next->scrutinee->id === $item->dest
            ) {
                $arms = [];
                foreach ($item->arms as $arm) {
                    $arms[] = new IR\MatchArm(
                        $arm->pattern,
                        new IR\Block(appendMatchReturnToArm($arm->body->items, $item->dest, $next)),
                        $arm->guards,
                    );
                }
                $out[] = new IR\MatchReturn($item->scrutinee, $arms, $item->exhaustive);
                ++$i;

                continue;
            }
        }

        if ($item instanceof IR\MatchStmt) {
            $dest = $item->dest;
            $out[] = mapMatchArms($item, static function (IR\MatchArm $arm) use ($depth, $dest): IR\MatchArm {
                $body = joinMatchStmtConsumer($arm->body->items, $depth + 1);
                $body = rewriteRetsToAssigns($body, $dest);

                return new IR\MatchArm($arm->pattern, new IR\Block($body), $arm->guards);
            });

            continue;
        }
        if ($item instanceof IR\MatchReturn) {
            $out[] = mapMatchArms($item, static function (IR\MatchArm $arm) use ($depth): IR\MatchArm {
                return new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(joinMatchStmtConsumer($arm->body->items, $depth + 1)),
                    $arm->guards,
                );
            });

            continue;
        }

        $out[] = $item;
    }

    return denseStmtItems($out);
}

/**
 * When a MatchStmt dest temp is rewritten to a different scrutinee (e.g. after
 * pushing a MatchReturn consumer into a Ret-ending arm), rewrite consumer arms
 * that still mention Temp($oldDest).
 *
 * @param array<int, IR\Operand> $tempEnv
 */
function replaceTempsInMatchReturn(IR\MatchReturn $match, array $tempEnv): IR\MatchReturn
{
    if ($tempEnv === []) {
        return $match;
    }

    $scrutinee = replaceTempsInOperand($match->scrutinee, $tempEnv);
    $arms = [];
    foreach ($match->arms as $arm) {
        $guards = mapGuards(
            $arm->guards,
            static fn (IR\Operand $g): IR\Operand => replaceTempsInOperand($g, $tempEnv),
            static fn (IR\Stmt $s): IR\Stmt => replaceTempsInItems([$s], $tempEnv)[0] ?? $s,
        );
        $arms[] = new IR\MatchArm(
            $arm->pattern,
            new IR\Block(replaceTempsInItems($arm->body->items, $tempEnv)),
            $guards,
        );
    }

    return new IR\MatchReturn($scrutinee, $arms, $match->exhaustive);
}

/** @param array<int, IR\Operand> $tempEnv @return list<IR\Stmt> */
function replaceTempsInItems(array $items, array $tempEnv): array
{
    if ($tempEnv === []) {
        return denseStmtItems($items);
    }

    $out = [];
    foreach (denseStmtItems($items) as $item) {
        if ($item instanceof IR\MatchStmt) {
            $arms = [];
            foreach ($item->arms as $arm) {
                $guards = \array_map(
                    static fn (IR\Operand $g): IR\Operand => replaceTempsInOperand($g, $tempEnv),
                    $arm->guards,
                );
                $arms[] = new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(replaceTempsInItems($arm->body->items, $tempEnv)),
                    $guards,
                );
            }
            $out[] = new IR\MatchStmt(
                replaceTempsInOperand($item->scrutinee, $tempEnv),
                $arms,
                $item->dest,
                $item->exhaustive,
            );

            continue;
        }
        if ($item instanceof IR\MatchReturn) {
            $out[] = replaceTempsInMatchReturn($item, $tempEnv);

            continue;
        }
        if ($item instanceof IR\Ret) {
            $out[] = new IR\Ret(replaceTempsInOperand($item->value, $tempEnv));

            continue;
        }
        if ($item instanceof IR\Assign) {
            $out[] = new IR\Assign($item->dest, replaceTempsInOperand($item->value, $tempEnv));

            continue;
        }
        if ($item instanceof IR\Let) {
            $out[] = new IR\Let($item->name, replaceTempsInOperand($item->value, $tempEnv));

            continue;
        }
        if ($item instanceof IR\Call) {
            $out[] = new IR\Call(
                $item->callee,
                \array_map(
                    static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv),
                    $item->args,
                ),
                $item->dest,
                $item->srcLoc,
            );

            continue;
        }
        if ($item instanceof IR\CallValue) {
            $out[] = new IR\CallValue(
                replaceTempsInOperand($item->callee, $tempEnv),
                \array_map(
                    static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv),
                    $item->args,
                ),
                $item->dest,
                $item->srcLoc,
            );

            continue;
        }
        if ($item instanceof IR\DictCall) {
            $out[] = new IR\DictCall(
                replaceTempsInOperand($item->evidence, $tempEnv),
                $item->method,
                \array_map(
                    static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv),
                    $item->args,
                ),
                $item->dest,
                $item->srcLoc,
                $item->localEvidence,
            );

            continue;
        }
        $out[] = $item;
    }

    return denseStmtItems($out);
}

function rewriteMatchReturnConsumerDest(
    IR\MatchReturn $consumer,
    int $oldDest,
    IR\Operand $newScrut,
): IR\MatchReturn {
    if ($newScrut instanceof IR\Temp && $newScrut->id === $oldDest) {
        return $consumer;
    }

    return replaceTempsInMatchReturn($consumer, [$oldDest => $newScrut]);
}

/**
 * @param list<IR\Stmt> $armItems
 * @return list<IR\Stmt>
 */
function appendMatchReturnToArm(array $armItems, int $dest, IR\MatchReturn $consumer): array
{
    $armItems = denseStmtItems($armItems);
    if ($armItems === []) {
        return [new IR\MatchReturn(new IR\Temp($dest), $consumer->arms, $consumer->exhaustive)];
    }

    $last = $armItems[\count($armItems) - 1];

    if ($last instanceof IR\Ret) {
        \array_pop($armItems);
        $rewritten = rewriteMatchReturnConsumerDest($consumer, $dest, $last->value);
        $armItems[] = new IR\MatchReturn($last->value, $rewritten->arms, $rewritten->exhaustive);

        return denseStmtItems($armItems);
    }

    if ($last instanceof IR\MatchReturn) {
        \array_pop($armItems);
        $arms = [];
        foreach ($last->arms as $arm) {
            $arms[] = new IR\MatchArm(
                $arm->pattern,
                new IR\Block(appendMatchReturnToArm($arm->body->items, $dest, $consumer)),
                $arm->guards,
            );
        }
        $armItems[] = new IR\MatchReturn($last->scrutinee, $arms, $last->exhaustive);

        return denseStmtItems($armItems);
    }

    if (
        ($last instanceof IR\Assign || $last instanceof IR\Call)
        && $last->dest === $dest
    ) {
        $armItems[] = new IR\MatchReturn(new IR\Temp($dest), $consumer->arms, $consumer->exhaustive);

        return denseStmtItems($armItems);
    }

    $armItems[] = new IR\MatchReturn(new IR\Temp($dest), $consumer->arms, $consumer->exhaustive);

    return denseStmtItems($armItems);
}

/**
 * @return null|array{kind: 'ret_temp'}
 */
function matchStmtTailConsumer(IR\Stmt $stmt, int $dest): ?array
{
    if (! ($stmt instanceof IR\Ret)) {
        return null;
    }

    if ($stmt->value instanceof IR\Temp && $stmt->value->id === $dest) {
        return ['kind' => 'ret_temp'];
    }

    return null;
}

/**
 * @param list<IR\Stmt> $armItems
 * @param array{kind: 'ret_temp'} $consumer
 * @param int $nextTemp the allocator's cursor, threaded across the arms of one match
 * @return list<IR\Stmt>
 */
function appendConsumerToArm(array $armItems, int $dest, array $consumer, int &$nextTemp): array
{
    $armItems = denseStmtItems($armItems);
    if ($armItems === []) {
        return appendConsumerValue(new IR\Temp($dest), $armItems, $consumer, $nextTemp);
    }

    $last = $armItems[\count($armItems) - 1];

    if ($last instanceof IR\MatchReturn) {
        \array_pop($armItems);
        $arms = [];
        foreach ($last->arms as $arm) {
            $arms[] = new IR\MatchArm(
                $arm->pattern,
                new IR\Block(appendConsumerToArm($arm->body->items, $dest, $consumer, $nextTemp)),
                $arm->guards,
            );
        }
        $armItems[] = new IR\MatchReturn($last->scrutinee, $arms, $last->exhaustive);

        return denseStmtItems($armItems);
    }

    $value = null;
    if ($last instanceof IR\Assign && $last->dest === $dest) {
        $value = $last->value;
        \array_pop($armItems);
    } elseif ($last instanceof IR\Ret) {
        $value = $last->value;
        \array_pop($armItems);
    } elseif (($last instanceof IR\Call || $last instanceof IR\CallValue) && $last->dest === $dest) {
        $value = new IR\Temp($dest);
    }

    return appendConsumerValue($value ?? new IR\Temp($dest), $armItems, $consumer, $nextTemp);
}

/**
 * The arm's statements, ending in the value the consumer asked for.
 *
 * The consumer's call becomes a statement of its own and the arm returns the temp the scheduling
 * primitive chose, so an arm never puts a computation where the schema allows only a value.
 *
 * @param list<IR\Stmt> $armItems
 * @param array{kind: 'ret_temp'} $consumer
 * @return list<IR\Stmt>
 */
function appendConsumerValue(IR\Operand $value, array $armItems, array $consumer, int &$nextTemp): array
{
    $armItems[] = new IR\Ret($value);

    return denseStmtItems($armItems);
}

/**
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function foldKnownMatchesInItems(array $items, int $depth = 0): array
{
    if ($depth > 64) {
        return denseStmtItems($items);
    }

    $items = denseStmtItems($items);
    $ctors = [];
    $aliases = [];
    $out = [];
    $n = \count($items);

    foreach ($items as $i => $item) {
        if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
            $item = mapMatchArms($item, static function (IR\MatchArm $arm) use ($depth): IR\MatchArm {
                return new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(foldKnownMatchesInItems($arm->body->items, $depth + 1)),
                    $arm->guards,
                );
            });

            if ($item instanceof IR\MatchStmt) {
                $dest = $item->dest;
                $item = mapMatchArms($item, static function (IR\MatchArm $arm) use ($dest): IR\MatchArm {
                    return new IR\MatchArm(
                        $arm->pattern,
                        new IR\Block(rewriteRetsToAssigns($arm->body->items, $dest)),
                        $arm->guards,
                    );
                });
            }

            if ($item instanceof IR\MatchReturn && $i !== $n - 1) {
                $dest = midBlockMatchReturnDest($out, \array_slice($items, $i + 1), $items);
                if ($dest !== null) {
                    $converted = rewriteRetsToAssigns([$item], $dest);
                    $item = $converted[0] ?? $item;
                    if (! ($item instanceof IR\MatchStmt)) {
                        $out[] = $item;

                        continue;
                    }
                }
            }

            $scrut = resolveOperand($item->scrutinee, $ctors, $aliases);
            $nextTemp = maxTempInItems([...$out, $item]) + 1;
            $folded = tryFoldMatch($item, $scrut, $ctors, $aliases, $nextTemp);
            if ($folded !== null) {
                if ($item instanceof IR\MatchStmt) {
                    $folded = rewriteRetsToAssigns($folded, $item->dest);
                }
                foreach ($folded as $stmt) {
                    $out[] = $stmt;
                    recordCtorDef($stmt, $ctors, $aliases);
                }

                continue;
            }

            $out[] = $item;

            continue;
        }

        $out[] = $item;
        recordCtorDef($item, $ctors, $aliases);
    }

    return denseStmtItems($out);
}

/**
 * Dest for a mid-block MatchReturn: the unique temp used by following stmts
 * that is not yet defined. Returns null when ambiguous — callers must leave
 * the MatchReturn alone rather than inventing a fresh dest.
 *
 * @param list<IR\Stmt> $before
 * @param list<IR\Stmt> $following
 * @param list<IR\Stmt> $all
 */
function midBlockMatchReturnDest(array $before, array $following, array $all): ?int
{
    $defined = [];
    collectTempDefsInItems($before, $defined);
    $usedAfter = [];
    collectTempUsesInItems($following, $usedAfter);
    $definedAfter = [];
    collectTempDefsInItems($following, $definedAfter);

    $candidates = [];
    foreach ($usedAfter as $id => $_) {
        if (! isset($defined[$id]) && ! isset($definedAfter[$id])) {
            $candidates[$id] = true;
        }
    }
    if (\count($candidates) === 1) {
        return (int) \array_key_first($candidates);
    }

    return null;
}

/**
 * Convert mid-block MatchReturn to MatchStmt when a unique consumer temp is
 * clear. Recurses into arm bodies. Safe to run on oversized blocks that skip
 * known-ctor folding.
 *
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function rewriteMidBlockMatchReturns(array $items): array
{
    $items = denseStmtItems($items);
    $out = [];
    $n = \count($items);

    foreach ($items as $i => $item) {
        if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
            $item = mapMatchArms($item, static function (IR\MatchArm $arm): IR\MatchArm {
                return new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(rewriteMidBlockMatchReturns($arm->body->items)),
                    $arm->guards,
                );
            });
            if ($item instanceof IR\MatchReturn && $i !== $n - 1) {
                $dest = midBlockMatchReturnDest($out, \array_slice($items, $i + 1), $items);
                if ($dest !== null) {
                    $converted = rewriteRetsToAssigns([$item], $dest);
                    $item = $converted[0] ?? $item;
                }
            }
        }
        $out[] = $item;
    }

    return denseStmtItems($out);
}

/** @param list<IR\Stmt> $items @param array<int, true> $defs */
function collectTempDefsInItems(array $items, array &$defs): void
{
    foreach (denseStmtItems($items) as $item) {
        if ($item instanceof IR\Assign || $item instanceof IR\Call || $item instanceof IR\CallValue
            || $item instanceof IR\DictCall) {
            if (isset($item->dest)) {
                $defs[$item->dest] = true;
            }
        }
        if ($item instanceof IR\MatchStmt) {
            $defs[$item->dest] = true;
            foreach ($item->arms as $arm) {
                collectTempDefsInItems($arm->body->items, $defs);
            }
        }
        if ($item instanceof IR\MatchReturn) {
            foreach ($item->arms as $arm) {
                collectTempDefsInItems($arm->body->items, $defs);
            }
        }
        if ($item instanceof IR\Let) {
        }
    }
}

/** @param list<IR\Stmt> $items @param array<int, true> $uses */
function collectTempUsesInItems(array $items, array &$uses): void
{
    foreach (denseStmtItems($items) as $item) {
        foreach (tempsReferencedInStmt($item) as $id) {
            $uses[$id] = true;
        }
    }
}

/** @return list<int> */
function tempsReferencedInStmt(IR\Stmt $stmt): array
{
    /** @var array<int, true> $ids operand ids, filled through `$add` below */
    $ids = [];
    $add = static function (IR\Operand $op) use (&$add, &$ids): void {
        if ($op instanceof IR\Temp) {
            $ids[$op->id] = true;

            return;
        }
        if ($op instanceof IR\Intrinsic) {
            foreach ($op->args as $arg) {
                $add($arg);
            }

            return;
        }
        if ($op instanceof IR\ListLit) {
            foreach ($op->elements as $el) {
                $add($el);
            }

            return;
        }
    };

    if ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\MatchReturn) {
        $add($stmt->scrutinee);
        foreach ($stmt->arms as $arm) {
            foreach (denseStmtItems($arm->body->items) as $inner) {
                foreach (tempsReferencedInStmt($inner) as $id) {
                    $ids[$id] = true;
                }
            }
        }

        return \array_map('intval', \array_keys($ids));
    }

    match ($stmt::class) {
        IR\Ret::class => $add($stmt->value),
        IR\Assign::class, IR\Let::class => $add($stmt->value),
        IR\Call::class => (static function () use ($stmt, $add): void {
            foreach ($stmt->args as $arg) {
                $add($arg);
            }
        })(),
        IR\CallValue::class => (static function () use ($stmt, $add): void {
            $add($stmt->callee);
            foreach ($stmt->args as $arg) {
                $add($arg);
            }
        })(),
        IR\DictCall::class => (static function () use ($stmt, $add): void {
            $add($stmt->evidence);
            foreach ($stmt->args as $arg) {
                $add($arg);
            }
        })(),
        default => null,
    };

    return \array_map('intval', \array_keys($ids));
}

/**
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function rewriteRetsToAssigns(array $items, int $dest): array
{
    $out = [];
    foreach (denseStmtItems($items) as $stmt) {
        if ($stmt instanceof IR\Ret) {
            $out[] = new IR\Assign($dest, $stmt->value);

            continue;
        }
        if ($stmt instanceof IR\MatchReturn) {
            $arms = [];
            foreach ($stmt->arms as $arm) {
                $arms[] = new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(rewriteRetsToAssigns($arm->body->items, $dest)),
                    $arm->guards,
                );
            }
            $out[] = new IR\MatchStmt($stmt->scrutinee, $arms, $dest, $stmt->exhaustive);

            continue;
        }
        $out[] = $stmt;
    }

    return denseStmtItems($out);
}

/**
 * @param array<int, CtorTree|IR\Operand> $ctors
 * @param array<int, IR\Operand> $aliases
 * @return list<IR\Stmt>|null
 */
function tryFoldMatch(
    IR\MatchStmt|IR\MatchReturn $match,
    CtorTree|IR\Operand $scrut,
    array $ctors,
    array $aliases,
    int &$nextTemp,
): ?array {
    $scrut = resolveMatchScrutinee($scrut, $ctors, $aliases);
    if (
        ! ($scrut instanceof CtorTree)
        && ! ($scrut instanceof IR\ListLit)
        && ! (
            $scrut instanceof IR\Intrinsic
            && isListSpineForm($scrut->name)
        )
    ) {
        return null;
    }

    foreach ($match->arms as $arm) {
        if ($arm->guards !== []) {
            return null;
        }

        $bindings = [];
        $decisive = true;
        if (! matchPattern($arm->pattern, $scrut, $bindings, $decisive)) {
            if (! $decisive) {
                return null;
            }

            continue;
        }
        if (! $decisive) {
            return null;
        }

        $leaves = [];
        if (! collectPatternLeaves($arm->pattern, $scrut, $leaves)) {
            continue;
        }

        $body = [];
        static $seedSeq = 0;
        $params = [];
        $args = [];
        foreach ($bindings as $name => $operand) {
            $leaf = materializeOperand($operand, $body, $nextTemp);
            if (operandIsNonDuplicable($leaf)) {
                $fresh = $name . '__seed' . ($seedSeq++);
                $body[] = new IR\Let($fresh, $leaf);
                $params[] = $name;
                $args[] = new IR\Local($fresh);
            } else {
                $params[] = $name;
                $args[] = $leaf;
            }
        }
        foreach ($arm->body->items as $stmt) {
            $body[] = $params === []
                ? $stmt
                : substituteStmt($stmt, $params, $args);
        }

        return denseStmtItems($body);
    }

    return null;
}

/**
 * @param list<CtorTree|IR\Operand> $leaves
 */
function collectPatternLeaves(IR\Pattern $pattern, CtorTree|IR\Operand $value, array &$leaves): bool
{
    if ($pattern instanceof IR\PatWild) {
        return true;
    }

    if ($pattern instanceof IR\PatVar) {
        $leaves[] = $value;

        return true;
    }

    if ($pattern instanceof IR\PatLit) {
        if ($value instanceof IR\ConstInt && \is_int($pattern->value)) {
            return $value->value === $pattern->value;
        }
        if ($value instanceof IR\ConstStr && \is_string($pattern->value)) {
            return $value->value === $pattern->value;
        }

        return false;
    }

    if ($pattern instanceof IR\PatChar) {
        return $value instanceof IR\ConstChar && $value->value === $pattern->value;
    }

    if ($pattern instanceof IR\PatNil) {
        return ($value instanceof IR\ListLit && $value->elements === [])
            || ($value instanceof IR\Intrinsic && listFormShape($value->name) === 'list-nil' && $value->args === []);
    }

    if ($pattern instanceof IR\PatCons) {
        if ($value instanceof IR\ListLit && $value->elements !== []) {
            $head = $value->elements[0];
            $tail = new IR\ListLit(\array_slice($value->elements, 1));

            return collectPatternLeaves($pattern->head, $head instanceof CtorTree ? $head : $head, $leaves)
                && collectPatternLeaves($pattern->tail, $tail, $leaves);
        }
        if ($value instanceof IR\Intrinsic && isListConsForm($value->name) && \count($value->args) === 2) {
            return collectPatternLeaves($pattern->head, $value->args[0], $leaves)
                && collectPatternLeaves($pattern->tail, $value->args[1], $leaves);
        }
        if ($value instanceof CtorTree && constructorNamesMatch($value->name, ':') && \count($value->args) === 2) {
            return collectPatternLeaves($pattern->head, $value->args[0], $leaves)
                && collectPatternLeaves($pattern->tail, $value->args[1], $leaves);
        }

        return false;
    }

    if ($pattern instanceof IR\PatCon) {
        if (! ($value instanceof CtorTree) || ! constructorNamesMatch($value->name, $pattern->name)) {
            return false;
        }
        if (\count($value->args) !== \count($pattern->args)) {
            return false;
        }
        foreach ($pattern->args as $i => $sub) {
            $arg = $value->args[$i];
            if (! collectPatternLeaves($sub, $arg instanceof CtorTree ? $arg : $arg, $leaves)) {
                return false;
            }
        }

        return true;
    }

    if ($pattern instanceof IR\PatTuple) {
        if (! ($value instanceof CtorTree) || ! isTupleCtorTree($value)) {
            return false;
        }
        if (\count($value->args) !== \count($pattern->elements)) {
            return false;
        }
        foreach ($pattern->elements as $i => $sub) {
            $arg = $value->args[$i];
            if (! collectPatternLeaves($sub, $arg instanceof CtorTree ? $arg : $arg, $leaves)) {
                return false;
            }
        }

        return true;
    }

    return false;
}

/**
 * @param array<string, CtorTree|IR\Operand> $bindings
 */
function matchPattern(
    IR\Pattern $pattern,
    CtorTree|IR\Operand $value,
    array &$bindings,
    ?bool &$decisive = null,
): bool {
    if ($pattern instanceof IR\PatWild) {
        return true;
    }

    if ($pattern instanceof IR\PatVar) {
        $bindings[$pattern->name] = $value;

        return true;
    }

    if ($pattern instanceof IR\PatLit) {
        if ($value instanceof IR\ConstInt && \is_int($pattern->value)) {
            return $value->value === $pattern->value;
        }
        if ($value instanceof IR\ConstStr && \is_string($pattern->value)) {
            return $value->value === $pattern->value;
        }
        if ($decisive !== null) {
            $decisive = false;
        }

        return false;
    }

    if ($pattern instanceof IR\PatChar) {
        if ($value instanceof IR\ConstChar && $value->value === $pattern->value) {
            return true;
        }
        if ($decisive !== null && ! ($value instanceof IR\ConstChar)) {
            $decisive = false;
        }

        return false;
    }

    if ($pattern instanceof IR\PatNil) {
        if ($value instanceof IR\ListLit) {
            return $value->elements === [];
        }
        if ($value instanceof IR\Intrinsic && listFormShape($value->name) === 'list-nil' && $value->args === []) {
            return true;
        }

        return undecidedShape($value, $decisive);
    }

    if ($pattern instanceof IR\PatCons) {
        if ($value instanceof IR\ListLit && $value->elements !== []) {
            $head = $value->elements[0];
            $tail = new IR\ListLit(\array_slice($value->elements, 1));

            return matchPattern($pattern->head, $head, $bindings, $decisive)
                && matchPattern($pattern->tail, $tail, $bindings, $decisive);
        }
        if ($value instanceof IR\Intrinsic && isListConsForm($value->name) && \count($value->args) === 2) {
            return matchPattern($pattern->head, $value->args[0], $bindings, $decisive)
                && matchPattern($pattern->tail, $value->args[1], $bindings, $decisive);
        }
        if ($value instanceof CtorTree && constructorNamesMatch($value->name, ':') && \count($value->args) === 2) {
            return matchPattern($pattern->head, $value->args[0], $bindings, $decisive)
                && matchPattern($pattern->tail, $value->args[1], $bindings, $decisive);
        }

        return undecidedShape($value, $decisive);
    }

    if ($pattern instanceof IR\PatCon) {
        if (! ($value instanceof CtorTree)) {
            return undecidedShape($value, $decisive);
        }
        if (! constructorNamesMatch($value->name, $pattern->name)) {
            return false;
        }
        if (\count($value->args) !== \count($pattern->args)) {
            return false;
        }
        foreach ($pattern->args as $i => $sub) {
            $arg = $value->args[$i];
            if (! matchPattern($sub, $arg instanceof CtorTree ? $arg : $arg, $bindings, $decisive)) {
                return false;
            }
        }

        return true;
    }

    if ($pattern instanceof IR\PatTuple) {
        if (! ($value instanceof CtorTree)) {
            return undecidedShape($value, $decisive);
        }
        if (! isTupleCtorTree($value)) {
            return false;
        }
        if (\count($value->args) !== \count($pattern->elements)) {
            return false;
        }
        foreach ($pattern->elements as $i => $sub) {
            if (! matchPattern($sub, $value->args[$i], $bindings, $decisive)) {
                return false;
            }
        }

        return true;
    }

    return false;
}

/**
 * Is the scrutinee a value whose constructor is statically known? Only then can a
 * constructor-shaped pattern be disproved.
 */
function shapeIsKnown(CtorTree|IR\Operand $value): bool
{
    if ($value instanceof CtorTree || $value instanceof IR\ListLit) {
        return true;
    }

    return $value instanceof IR\Intrinsic
        && isListSpineForm($value->name);
}

/**
 * A constructor-shaped pattern against an operand whose constructor is unknown is
 * undecided, not a proven non-match. Reporting it as a non-match let a fold walk
 * past every real arm and take a later wildcard one — `case (m, xs) of (Just _, [])
 * -> …; _ -> …` folded to the wildcard body.
 *
 * @param ?bool $decisive
 */
function undecidedShape(CtorTree|IR\Operand $value, ?bool &$decisive): bool
{
    if ($decisive !== null && ! shapeIsKnown($value)) {
        $decisive = false;
    }

    return false;
}

/**
 * @param array<int, CtorTree|IR\Operand> $ctors
 * @param array<int, IR\Operand> $aliases
 */
function resolveMatchScrutinee(
    CtorTree|IR\Operand $scrut,
    array $ctors,
    array $aliases,
): CtorTree|IR\Operand {
    if ($scrut instanceof CtorTree) {
        return $scrut;
    }

    $resolved = resolveOperand($scrut, $ctors, $aliases);
    if ($resolved instanceof CtorTree) {
        return $resolved;
    }
    if ($resolved instanceof IR\ListLit) {
        return listLitToCtorSpine($resolved, $ctors, $aliases);
    }
    if ($resolved instanceof IR\Intrinsic && listFormShape($resolved->name) === 'list-nil' && $resolved->args === []) {
        return new IR\ListLit([]);
    }
    if ($resolved instanceof IR\Intrinsic && isListConsForm($resolved->name) && \count($resolved->args) === 2) {
        $asList = flattenResolvedListCons($resolved, $ctors, $aliases);
        if ($asList !== null) {
            return listLitToCtorSpine($asList, $ctors, $aliases);
        }
    }

    return $resolved;
}

/**
 * @param array<int, CtorTree|IR\Operand> $ctors
 * @param array<int, IR\Operand> $aliases
 */
function listLitToCtorSpine(IR\ListLit $lit, array $ctors, array $aliases): CtorTree|IR\ListLit
{
    if ($lit->elements === []) {
        return $lit;
    }

    $head = resolveOperand($lit->elements[0], $ctors, $aliases);
    $tail = listLitToCtorSpine(
        new IR\ListLit(\array_slice($lit->elements, 1)),
        $ctors,
        $aliases,
    );

    return new CtorTree(':', [$head, $tail]);
}

/**
 * @param array<int, CtorTree|IR\Operand> $ctors
 * @param array<int, IR\Operand> $aliases
 */
function flattenResolvedListCons(
    IR\Intrinsic $node,
    array $ctors,
    array $aliases,
): ?IR\ListLit {
    $elems = [];
    $cur = $node;
    $guard = 0;
    while ($cur instanceof IR\Intrinsic && isListConsForm($cur->name) && \count($cur->args) === 2) {
        if (++$guard > 256) {
            return null;
        }
        $elems[] = resolveOperand($cur->args[0], $ctors, $aliases);
        $tail = resolveOperand($cur->args[1], $ctors, $aliases);
        if ($tail instanceof IR\ListLit) {
            $head = ctorTreeValues($elems);
            if ($head === null) {
                return null;
            }

            return new IR\ListLit([...$head, ...$tail->elements]);
        }
        if ($tail instanceof IR\Intrinsic && listFormShape($tail->name) === 'list-nil' && $tail->args === []) {
            $head = ctorTreeValues($elems);
            if ($head === null) {
                return null;
            }

            return new IR\ListLit($head);
        }
        if (! ($tail instanceof IR\Intrinsic && isListConsForm($tail->name))) {
            return null;
        }
        $cur = $tail;
    }

    return null;
}

function isTupleCtorTree(CtorTree $tree): bool
{
    if (constructorNamesMatch($tree->name, '()')) {
        return true;
    }
    $leaf = constructorLeafName($tree->name);

    return \preg_match('/^__tuple\d+$/', $leaf) === 1;
}

function isTupleCtorCallee(string $callee): bool
{
    $leaf = constructorLeafName($callee);

    return \preg_match('/^__tuple\d+$/', $leaf) === 1;
}

/**
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function foldFieldExtractsInItems(array $items, int $depth = 0): array
{
    if ($depth > 64) {
        return denseStmtItems($items);
    }

    $items = denseStmtItems($items);
    $ctors = [];
    $aliases = [];
    $out = [];
    $nextTemp = maxTempInItems($items) + 1;

    foreach ($items as $item) {
        if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
            $out[] = mapMatchArms($item, static function (IR\MatchArm $arm) use ($depth): IR\MatchArm {
                return new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(foldFieldExtractsInItems($arm->body->items, $depth + 1)),
                    $arm->guards,
                );
            });

            continue;
        }

        if ($item instanceof IR\Call && isFieldExtractCallee($item->callee) && \count($item->args) === 1) {
            $scrut = resolveOperand($item->args[0], $ctors, $aliases);
            if ($scrut instanceof CtorTree) {
                $idx = fieldExtractIndex($item->callee);
                if (isset($scrut->args[$idx])) {
                    $from = \count($out);
                    $out[] = new IR\Assign($item->dest, materializeOperand($scrut->args[$idx], $out, $nextTemp));
                    foreach (\array_slice($out, $from) as $fresh) {
                        recordCtorDef($fresh, $ctors, $aliases);
                    }

                    continue;
                }
            }
        }

        $folded = foldNestedFieldExtractsInStmt($item, $ctors, $aliases);
        $out[] = $folded;
        recordCtorDef($folded, $ctors, $aliases);
    }

    return denseStmtItems($out);
}

/**
 * @param array<int, CtorTree|IR\Operand> $ctors
 * @param array<int, IR\Operand> $aliases
 */
function foldNestedFieldExtractsInStmt(IR\Stmt $stmt, array $ctors, array $aliases): IR\Stmt
{
    if ($stmt instanceof IR\Call && \count($stmt->args) === 1) {
        $idx = fieldAccessorCafIndex($stmt->callee);
        if ($idx === null && isFieldExtractCallee($stmt->callee)) {
            $idx = fieldExtractIndex($stmt->callee);
        }
        if ($idx !== null) {
            $scrut = resolveOperand(
                foldNestedFieldExtractOperand($stmt->args[0], $ctors, $aliases),
                $ctors,
                $aliases,
            );
            if ($scrut instanceof CtorTree && isset($scrut->args[$idx])) {
                $field = $scrut->args[$idx];
                $value = $field instanceof CtorTree ? ctorTreeValue($field) : $field;
                if ($value !== null) {
                    return new IR\Assign($stmt->dest, $value);
                }
            }
        }
    }

    return match ($stmt::class) {
        IR\Ret::class => new IR\Ret(foldNestedFieldExtractOperand($stmt->value, $ctors, $aliases)),
        IR\Assign::class => new IR\Assign(
            $stmt->dest,
            foldNestedFieldExtractOperand($stmt->value, $ctors, $aliases),
        ),
        IR\Let::class => new IR\Let(
            $stmt->name,
            foldNestedFieldExtractOperand($stmt->value, $ctors, $aliases),
        ),
        IR\Call::class => new IR\Call(
            $stmt->callee,
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => foldNestedFieldExtractOperand($arg, $ctors, $aliases),
                $stmt->args,
            ),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\CallValue::class => new IR\CallValue(
            foldNestedFieldExtractOperand($stmt->callee, $ctors, $aliases),
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => foldNestedFieldExtractOperand($arg, $ctors, $aliases),
                $stmt->args,
            ),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        default => $stmt,
    };
}

/**
 * @param array<int, CtorTree|IR\Operand> $ctors
 * @param array<int, IR\Operand> $aliases
 */
function foldNestedFieldExtractOperand(IR\Operand $operand, array $ctors, array $aliases): IR\Operand
{
    $operand = mapOperandChildren(
        $operand,
        static fn (IR\Operand $child): IR\Operand => foldNestedFieldExtractOperand($child, $ctors, $aliases),
    );

    return $operand;
}

/**
 * @param array<int, CtorTree|IR\Operand> $ctors
 * @param array<int, IR\Operand> $aliases
 */
function recordCtorDef(IR\Stmt $stmt, array &$ctors, array &$aliases): void
{
    if ($stmt instanceof IR\Call) {
        if (isTupleCtorCallee($stmt->callee)) {
            $ctors[$stmt->dest] = new CtorTree(
                '()',
                \array_map(
                    static fn (IR\Operand $arg): CtorTree|IR\Operand => resolveOperand($arg, $ctors, $aliases),
                    $stmt->args,
                ),
                $stmt->srcLoc,
            );

            return;
        }
        if (isFieldExtractCallee($stmt->callee) || ! isPlausibleConstructorName($stmt->callee)) {
            return;
        }
        $ctors[$stmt->dest] = new CtorTree(
            $stmt->callee,
            \array_map(
                static fn (IR\Operand $arg): CtorTree|IR\Operand => resolveOperand($arg, $ctors, $aliases),
                $stmt->args,
            ),
            $stmt->srcLoc,
        );

        return;
    }

    if ($stmt instanceof IR\Assign) {
        $resolved = resolveOperand($stmt->value, $ctors, $aliases);
        if ($resolved instanceof CtorTree) {
            $ctors[$stmt->dest] = $resolved;
        } else {
            $aliases[$stmt->dest] = $stmt->value instanceof IR\Temp
                ? ($aliases[$stmt->value->id] ?? $stmt->value)
                : $stmt->value;
            if ($stmt->value instanceof IR\Temp && isset($ctors[$stmt->value->id])) {
                $ctors[$stmt->dest] = $ctors[$stmt->value->id];
            }
        }
    }
}

/** Leaf identifier of a possibly Module::name / ns\name qualified symbol. */
function constructorLeafName(string $name): string
{
    $parsed = parseResolvedSymbol($name);
    if ($parsed !== null) {
        return $parsed['name'];
    }

    if (\str_contains($name, '\\')) {
        return \substr($name, \strrpos($name, '\\') + 1);
    }

    return $name;
}

/** Pattern / CtorTree names match ignoring Module:: qualification. */
function constructorNamesMatch(string $a, string $b): bool
{
    return constructorLeafName($a) === constructorLeafName($b);
}

/** Heuristic: data constructors are capitalized or symbolic operators. */
function isPlausibleConstructorName(string $name): bool
{
    if ($name === '' || \str_starts_with($name, '__')) {
        return false;
    }

    $leaf = constructorLeafName($name);

    if ($leaf === '' || \str_starts_with($leaf, '__')) {
        return false;
    }

    return isConstructorName($leaf);
}

/**
 * @param array<int, CtorTree|IR\Operand> $ctors
 * @param array<int, IR\Operand> $aliases
 * @param array<int, true> $seen
 */
function resolveOperand(IR\Operand $operand, array $ctors, array $aliases, array $seen = []): CtorTree|IR\Operand
{
    if ($operand instanceof IR\Temp) {
        if (isset($seen[$operand->id])) {
            return $operand;
        }
        $seen[$operand->id] = true;
        if (isset($ctors[$operand->id])) {
            return $ctors[$operand->id];
        }
        if (isset($aliases[$operand->id])) {
            return resolveOperand($aliases[$operand->id], $ctors, $aliases, $seen);
        }

        return $operand;
    }

    if ($operand instanceof IR\FnRef && isPlausibleConstructorName($operand->name)) {
        return new CtorTree($operand->name, []);
    }

    if ($operand instanceof IR\FnRef) {
        $caf = nullaryCtorCaf($operand->name);
        if ($caf !== null) {
            return $caf;
        }
    }

    if ($operand instanceof IR\Intrinsic) {
        if (listFormShape($operand->name) === 'list-nil' && $operand->args === []) {
            return new IR\ListLit([]);
        }
        if (isListConsForm($operand->name) && \count($operand->args) === 2) {
            $head = resolveOperand($operand->args[0], $ctors, $aliases, $seen);
            $tail = resolveOperand($operand->args[1], $ctors, $aliases, $seen);
            $values = ctorTreeValues([$head, $tail]);
            if ($values === null) {
                return $operand;
            }
            [$headValue, $tailValue] = $values;
            if ($tailValue instanceof IR\ListLit) {
                return new IR\ListLit([$headValue, ...$tailValue->elements]);
            }

            return new IR\Intrinsic('listCons#', [$headValue, $tailValue]);
        }
    }

    return $operand;
}

/** @param array<string, CtorTree> $cafs */
function setNullaryCtorCafs(array $cafs): void
{
    $GLOBALS['__moggi_nullary_ctor_cafs'] = $cafs;
}

function clearNullaryCtorCafs(): void
{
    unset($GLOBALS['__moggi_nullary_ctor_cafs'], $GLOBALS['__moggi_field_accessor_cafs']);

}

function nullaryCtorCaf(string $name): ?CtorTree
{
    $cafs = $GLOBALS['__moggi_nullary_ctor_cafs'] ?? null;
    if (! \is_array($cafs)) {
        return null;
    }
    $tree = $cafs[$name] ?? null;

    return $tree instanceof CtorTree ? $tree : null;
}

/** @param array<string, int> $accessors leaf/qualified name → __field index */
function setFieldAccessorCafs(array $accessors): void
{
    $GLOBALS['__moggi_field_accessor_cafs'] = $accessors;
}

function fieldAccessorCafIndex(string $name): ?int
{
    $accessors = $GLOBALS['__moggi_field_accessor_cafs'] ?? null;
    if (! \is_array($accessors)) {
        return null;
    }
    $idx = $accessors[$name] ?? null;

    return \is_int($idx) ? $idx : null;
}

/**
 * A tree as a value, when it already is one.
 *
 * A nullary constructor is an `FnRef`. A constructor application is a computation, and a site with no
 * block to schedule it into cannot hand one back — {@see materializeOperand} is that form, for a site
 * that has a block — so the answer is null and the caller declines the fold.
 */
function ctorTreeValue(CtorTree $tree): ?IR\Operand
{
    return $tree->args === [] ? new IR\FnRef($tree->name) : null;
}

/**
 * @param list<CtorTree|IR\Operand> $values
 * @return ?list<IR\Operand> null when one of them is a computation
 */
function ctorTreeValues(array $values): ?array
{
    $out = [];
    foreach ($values as $value) {
        $operand = $value instanceof CtorTree ? ctorTreeValue($value) : $value;
        if ($operand === null) {
            return null;
        }
        $out[] = $operand;
    }

    return $out;
}

/**
 * A `CtorTree` read as a value, with the constructor application scheduled in the reading block.
 *
 * A nullary constructor is already a value (`FnRef`); a constructor application is a computation, so
 * it becomes a `Call` statement in the block that reads it and the reader takes its temp — the schema
 * has no value-shaped call, so no `CtorTree` may be handed back as one.
 *
 * A name that stands for a primop is not a callable at all: `(:)` is `listCons#` wherever it appears,
 * so it becomes the intrinsic every other cons in the tree is built from.
 *
 * @param list<IR\Stmt> $out appended to, in evaluation order
 */
function materializeOperand(CtorTree|IR\Operand $value, array &$out, int &$nextTemp): IR\Operand
{
    if ($value instanceof IR\Operand) {
        return $value;
    }

    if ($value->args === []) {
        return new IR\FnRef($value->name);
    }

    $args = [];
    foreach ($value->args as $arg) {
        $args[] = materializeOperand($arg, $out, $nextTemp);
    }

    $primop = primopForPublicOperator($value->name);
    if ($primop !== null) {
        return new IR\Intrinsic($primop, $args);
    }

    [$temp, $nextTemp] = scheduleCall($value->name, $args, $out, $nextTemp, $value->srcLoc);

    return $temp;
}

/**
 * @param callable(IR\MatchArm): IR\MatchArm $fn
 */
function mapMatchArms(IR\MatchStmt|IR\MatchReturn $match, callable $fn): IR\MatchStmt|IR\MatchReturn
{
    $arms = \array_map($fn, $match->arms);
    if ($match instanceof IR\MatchStmt) {
        return new IR\MatchStmt($match->scrutinee, $arms, $match->dest, $match->exhaustive);
    }

    return new IR\MatchReturn($match->scrutinee, $arms, $match->exhaustive);
}

/** @param list<IR\Stmt> $items */
function tempUsedInItems(array $items, int $tempId): bool
{
    foreach (denseStmtItems($items) as $item) {
        if (tempUsedInStmt($item, $tempId)) {
            return true;
        }
    }

    return false;
}

/**
 * Walked generically over {@see stmtDirectOperands} / {@see stmtNestedBlocks}:
 * a hand-written `match` kept missing node kinds, and it read an arm's guards as
 * plain operands — a guard owns the statements that compute it, so a temp used
 * only there looked dead.
 */
function tempUsedInStmt(IR\Stmt $stmt, int $tempId): bool
{
    foreach (stmtDirectOperands($stmt) as $operand) {
        if (tempUsedInOperand($operand, $tempId)) {
            return true;
        }
    }

    return \array_any(
        stmtNestedBlocks($stmt),
        static fn (IR\Block $nested): bool => tempUsedInItems($nested->items, $tempId),
    );
}

function isFieldExtractCallee(string $callee): bool
{
    $leaf = constructorLeafName($callee);

    return \str_starts_with($leaf, '__field') || \str_starts_with($leaf, '__tuple_field');
}

function tempUsedInOperand(IR\Operand $operand, int $tempId): bool
{
    if ($operand instanceof IR\Temp) {
        return $operand->id === $tempId;
    }

    if ($operand instanceof IR\Intrinsic) {
        return tempsUsedInOperands($operand->args, $tempId);
    }

    if ($operand instanceof IR\ListLit) {
        return tempsUsedInOperands($operand->elements, $tempId);
    }

    return false;
}

/** @param list<IR\Operand> $operands */
function tempsUsedInOperands(array $operands, int $tempId): bool
{
    foreach ($operands as $operand) {
        if ($operand instanceof IR\Operand && tempUsedInOperand($operand, $tempId)) {
            return true;
        }
    }

    return false;
}
