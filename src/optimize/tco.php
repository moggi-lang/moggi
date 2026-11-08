<?php declare(strict_types=1);

namespace Moggi\Optimize\Tco;

use Moggi\IR;

use function Moggi\Optimize\Support\preserveMatchArm;

/**
 * Rewrite a self tail call into a loop.
 *
 * `$params` is the function's whole runtime slot vector — the capture prefix a
 * lifted callable is invoked with, then its declared parameters — because that
 * is what a recall has to overwrite, and what a call site applies.
 *
 * @param list<string> $params
 */
function eliminateTailRecursion(string $name, array $params, IR\Block $body): IR\Block
{
    if ($params === []) {
        return $body;
    }

    $plan = findTailRecPlan($name, $params, $body->items);
    if ($plan === null) {
        return $body;
    }

    return new IR\Block([new IR\Loop(new IR\Block(convertTailRecBody($name, $params, $body->items, $plan)))]);
}

/** @param list<IR\Stmt> $items @return array{kind: string, args?: list<IR\Operand>, match?: IR\MatchStmt|IR\MatchReturn}|null */
function findTailRecPlan(string $name, array $params, array $items): ?array
{
    if ($items === []) {
        return null;
    }

    $last = $items[\count($items) - 1];
    if ($last instanceof IR\Ret) {
        $call = tailSelfCallFromRetValue($last->value, $name, $params, $items);
        if ($call !== null) {
            return ['kind' => 'direct', 'args' => $call];
        }
    }

    if ($last instanceof IR\MatchReturn) {
        return findMatchReturnTailRecPlan($name, $params, $last);
    }

    if (\count($items) === 2 && $items[1] instanceof IR\Ret && $items[0] instanceof IR\MatchStmt) {
        return findMatchTailRecPlan($name, $params, $items[0], $items[1]);
    }

    return null;
}

/**
 * The recall arguments a value-position `ret` answers with, when the statement
 * right before it computes the function's own call.
 *
 * @param list<IR\Stmt> $items
 * @return list<IR\Operand>|null
 */
function tailSelfCallFromRetValue(IR\Operand $value, string $name, array $params, array $items): ?array
{
    if (! $value instanceof IR\Temp) {
        return null;
    }

    $defining = $items[\count($items) - 2] ?? null;
    if ($defining instanceof IR\Call && $defining->dest === $value->id) {
        return tailSelfCallFromCall($defining, $name, $params);
    }

    if ($defining instanceof IR\CallValue && $defining->dest === $value->id) {
        return tailSelfCallFromCallValueStmt($defining, $name, $params, $items);
    }

    return null;
}

/** @param list<IR\Operand> $extraArgs @return list<IR\Operand>|null */
function tailSelfCallFromCall(IR\Stmt $call, string $name, array $params, array $extraArgs = []): ?array
{
    if (! ($call instanceof IR\Call) || $call->callee !== $name) {
        return null;
    }

    return tailSelfCallFromOperands($call->args, $extraArgs, $params);
}

/**
 * The operand vector a recall supplies, when it fills every runtime slot.
 *
 * A partially applied self call hands its capture prefix through the `partial`
 * operand and its remaining slots through the application.
 *
 * @param list<IR\Operand> $prefix
 * @param list<IR\Operand> $supplied
 * @param list<string> $params
 * @return list<IR\Operand>|null
 */
function tailSelfCallFromOperands(array $prefix, array $supplied, array $params): ?array
{
    $recall = [...$prefix, ...$supplied];
    if (\count($recall) !== \count($params)) {
        return null;
    }

    return $recall;
}

/** @param list<IR\Stmt> $items @return array{kind: string, args?: list<IR\Operand>, match?: IR\MatchStmt}|null */
function findCallDefiningTemp(array $items, int $tempId): ?IR\Stmt
{
    foreach (\array_reverse($items) as $item) {
        if (($item instanceof IR\Call || $item instanceof IR\CallValue) && $item->dest === $tempId) {
            return $item;
        }
    }

    return null;
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt>|null */
function tailSelfCallInBlock(string $name, array $params, array $items, int $matchDest): ?array
{
    if ($items === []) {
        return null;
    }

    $last = $items[\count($items) - 1];
    if ($last instanceof IR\Ret) {
        return tailSelfCallFromRetValue($last->value, $name, $params, $items);
    }

    if ($last instanceof IR\Assign && $last->dest === $matchDest) {
        if ($last->value instanceof IR\Temp) {
            $prev = $items[\count($items) - 2] ?? null;
            if ($prev !== null && $prev instanceof IR\CallValue && $prev->dest === $last->value->id) {
                return tailSelfCallFromCallValueStmt($prev, $name, $params, $items);
            }

            if ($prev !== null && $prev instanceof IR\Call && $prev->dest === $last->value->id) {
                return tailSelfCallFromCall($prev, $name, $params);
            }
        }

        return tailSelfCallFromRetValue($last->value, $name, $params, $items);
    }

    if ($last instanceof IR\CallValue) {
        return tailSelfCallFromCallValueStmt($last, $name, $params, $items);
    }

    if ($last instanceof IR\Call && $last->dest === $matchDest) {
        return tailSelfCallFromCall($last, $name, $params);
    }

    return null;
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt>|null */
function tailSelfCallFromCallValueStmt(IR\CallValue $callValue, string $name, array $params, array $items): ?array
{
    if ($callValue->callee instanceof IR\Partial && $callValue->callee->fn === $name) {
        return tailSelfCallFromOperands($callValue->callee->args, $callValue->args, $params);
    }

    if (! ($callValue->callee instanceof IR\Temp)) {
        return null;
    }

    $partial = partialDefiningTemp($items, $callValue->callee->id);
    if ($partial !== null && $partial->fn === $name) {
        return tailSelfCallFromOperands($partial->args, $callValue->args, $params);
    }

    $partialCall = findCallDefiningTemp($items, $callValue->callee->id);
    if ($partialCall === null) {
        return null;
    }

    return tailSelfCallFromCall($partialCall, $name, $params, $callValue->args);
}

/** The `partial` holding the function's own entry, when a temp was assigned one. */
function partialDefiningTemp(array $items, int $tempId): ?IR\Partial
{
    foreach (\array_reverse($items) as $item) {
        if ($item instanceof IR\Assign && $item->dest === $tempId) {
            return $item->value instanceof IR\Partial ? $item->value : null;
        }
    }

    return null;
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function removeAssignDefiningTemp(array $items, int $tempId): array
{
    foreach (\array_reverse(\array_keys($items)) as $i) {
        if ($items[$i] instanceof IR\Assign && $items[$i]->dest === $tempId) {
            unset($items[$i]);

            return \array_values($items);
        }
    }

    return $items;
}

/** @return array{kind: string, args?: list<IR\Operand>, match?: IR\MatchStmt}|null */
function findMatchTailRecPlan(string $name, array $params, IR\MatchStmt $match, IR\Ret $ret): ?array
{
    if (! ($ret->value instanceof IR\Temp) || $ret->value->id !== $match->dest) {
        return null;
    }

    $recursiveArgs = null;
    foreach ($match->arms as $arm) {
        $call = tailSelfCallInBlock($name, $params, $arm->body->items, $match->dest);
        if ($call === null) {
            continue;
        }

        if ($recursiveArgs !== null) {
            return null;
        }

        $recursiveArgs = $call;
    }

    if ($recursiveArgs === null) {
        return null;
    }

    return ['kind' => 'match', 'match' => $match, 'args' => $recursiveArgs];
}

/** @param array{kind: string, args?: list<IR\Operand>, match?: IR\MatchStmt|IR\MatchReturn} $plan @param list<IR\Stmt> $items @return list<IR\Stmt> */
function convertTailRecBody(string $name, array $params, array $items, array $plan): array
{
    return match ($plan['kind']) {
        'direct' => [...stripRecursiveTail($items, -1, $name), new IR\TailRecall($plan['args'])],
        'match' => [
            ...\array_slice($items, 0, -2),
            convertTailRecMatch($name, $params, $plan['match']),
        ],
        'match_return' => [
            ...\array_slice($items, 0, -1),
            convertTailRecMatchReturn($name, $params, $plan['match']),
        ],
        default => throw new \RuntimeException("unknown tail recursion plan `{$plan['kind']}`"),
    };
}

/** @return array{kind: string, args: list<IR\Operand>, match: IR\MatchReturn}|null */
function findMatchReturnTailRecPlan(string $name, array $params, IR\MatchReturn $match): ?array
{
    $recursiveArgs = null;
    foreach ($match->arms as $arm) {
        $call = tailSelfCallInBlock($name, $params, $arm->body->items, -1);
        if ($call === null) {
            continue;
        }

        if ($recursiveArgs !== null) {
            return null;
        }

        $recursiveArgs = $call;
    }

    if ($recursiveArgs === null) {
        return null;
    }

    return ['kind' => 'match_return', 'match' => $match, 'args' => $recursiveArgs];
}

/** The late-stage match, whose arms already answer by returning rather than naming a region. */
function convertTailRecMatchReturn(string $name, array $params, IR\MatchReturn $match): IR\MatchReturn
{
    $arms = [];

    foreach ($match->arms as $arm) {
        $call = tailSelfCallInBlock($name, $params, $arm->body->items, -1);
        if ($call === null) {
            $arms[] = $arm;

            continue;
        }

        $arms[] = preserveMatchArm($arm, new IR\Block([
            ...stripRecursiveTail($arm->body->items, -1, $name),
            new IR\TailRecall($call),
        ]));
    }

    return new IR\MatchReturn($match->scrutinee, $arms, $match->exhaustive);
}

function convertTailRecMatch(string $name, array $params, IR\MatchStmt $match): IR\MatchReturn
{
    $dest = $match->dest;
    $arms = [];

    foreach ($match->arms as $arm) {
        $items = $arm->body->items;
        $call = tailSelfCallInBlock($name, $params, $items, $dest);
        if ($call !== null) {
            $arms[] = preserveMatchArm($arm, new IR\Block([
                ...stripRecursiveTail($items, $dest, $name),
                new IR\TailRecall($call),
            ]));

            continue;
        }

        $arms[] = preserveMatchArm(
            $arm,
            new IR\Block(convertMatchArmToReturn($arm->body->items, $dest)),
        );
    }

    return new IR\MatchReturn($match->scrutinee, $arms, false);
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function convertMatchArmToReturn(array $items, int $matchDest): array
{
    if ($items === []) {
        throw new \RuntimeException('empty match arm in tail recursion conversion');
    }

    $last = $items[\count($items) - 1];
    if ($last instanceof IR\Ret) {
        return $items;
    }

    if ($last instanceof IR\Assign && $last->dest === $matchDest) {
        $items[\count($items) - 1] = new IR\Ret($last->value);

        return $items;
    }

    throw new \RuntimeException('unsupported match arm shape for tail recursion conversion');
}

/**
 * The arm's statements without the return path the loop's back-edge replaces.
 *
 * A recursive arm ends in the recall, under the assignment that names the match
 * result and a `ret` when the arm answers the region with one. All three are
 * dropped, and what ran before the call — evaluating its arguments — stays,
 * because that is what runs again on every iteration.
 *
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function stripRecursiveTail(array $items, int $matchDest, string $name): array
{
    $items = \array_values($items);

    if ($items !== [] && $items[\count($items) - 1] instanceof IR\Ret) {
        \array_pop($items);
    }

    if ($items !== [] && $items[\count($items) - 1] instanceof IR\Assign
        && $items[\count($items) - 1]->dest === $matchDest) {
        \array_pop($items);
    }

    $tail = $items[\count($items) - 1] ?? null;
    if ($tail instanceof IR\Call && $tail->callee === $name) {
        \array_pop($items);
    } elseif ($tail instanceof IR\CallValue && $tail->callee instanceof IR\Partial && $tail->callee->fn === $name) {
        \array_pop($items);
    } elseif ($tail instanceof IR\CallValue && $tail->callee instanceof IR\Temp) {
        $partial = partialDefiningTemp($items, $tail->callee->id);
        if ($partial !== null && $partial->fn === $name) {
            \array_pop($items);
            $items = removeAssignDefiningTemp($items, $tail->callee->id);
        } elseif (isSelfCallDefiningTemp($items, $tail->callee->id, $name)) {
            \array_pop($items);
            $items = removeCallDefiningTemp($items, $tail->callee->id);
        }
    }

    return $items;
}

/**
 * True when the statement defining `$tempId` is a call to the function being
 * turned into a loop, i.e. the temp is a partial application of the recall.
 *
 * @param list<IR\Stmt> $items
 */
function isSelfCallDefiningTemp(array $items, int $tempId, string $name): bool
{
    $defining = findCallDefiningTemp($items, $tempId);

    return $defining instanceof IR\Call && $defining->callee === $name;
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function removeCallDefiningTemp(array $items, int $tempId): array
{
    foreach (\array_reverse(\array_keys($items)) as $i) {
        if ($items[$i] instanceof IR\Call && $items[$i]->dest === $tempId) {
            unset($items[$i]);

            return \array_values($items);
        }
    }

    return $items;
}
