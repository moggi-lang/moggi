<?php declare(strict_types=1);

namespace Moggi\Optimize\Tco;

use Moggi\IR;

use function Moggi\Optimize\Support\isLambdaName;
use function Moggi\Optimize\Support\preserveMatchArm;

function eliminateTailRecursion(string $name, array $params, IR\Block $body): IR\Block
{
    if (isLambdaName($name) || $params === []) {
        return $body;
    }

    $plan = findTailRecPlan($name, $params, $body->items);
    if ($plan === null) {
        return $body;
    }

    return new IR\Block([new IR\Loop(new IR\Block(convertTailRecBody($name, $params, $body->items, $plan)))]);
}

/** @param list<IR\Stmt> $items @return array{kind: string, args?: list<IR\Operand>, match?: IR\MatchStmt}|null */
function findTailRecPlan(string $name, array $params, array $items): ?array
{
    if ($items === []) {
        return null;
    }

    $last = $items[count($items) - 1];
    if ($last instanceof IR\Ret) {
        $call = tailSelfCallFromRetValue($last->value, $name, $params, $items);
        if ($call !== null) {
            return ['kind' => 'direct', 'args' => $call];
        }
    }

    if (count($items) === 2 && $items[1] instanceof IR\Ret && $items[0] instanceof IR\MatchStmt) {
        return findMatchTailRecPlan($name, $params, $items[0], $items[1]);
    }

    return null;
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt>|null */
function tailSelfCallFromRetValue(IR\Operand $value, string $name, array $params, array $items): ?array
{
    if ($value instanceof IR\ExprCall && $value->callee === $name && count($value->args) === count($params)) {
        return $value->args;
    }

    if (!($value instanceof IR\ExprCallValue) || !($value->callee instanceof IR\Temp)) {
        return null;
    }

    $partialCall = findCallDefiningTemp($items, $value->callee->id);
    if ($partialCall === null) {
        return null;
    }

    return tailSelfCallFromCall($partialCall, $name, $params, $value->args);
}

/** @param list<IR\Operand> $extraArgs @return list<IR\Stmt>|null */
function tailSelfCallFromCall(IR\Stmt $call, string $name, array $params, array $extraArgs = []): ?array
{
    if (!($call instanceof IR\Call) || $call->callee !== $name) {
        return null;
    }

    $args = [...$call->args, ...$extraArgs];
    if (count($args) !== count($params)) {
        return null;
    }

    return $args;
}

/** @param list<IR\Stmt> $items @return array{kind: string, args?: list<IR\Operand>, match?: IR\MatchStmt}|null */
function findCallDefiningTemp(array $items, int $tempId): ?IR\Stmt
{
    foreach (array_reverse($items) as $item) {
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

    $last = $items[count($items) - 1];
    if ($last instanceof IR\Ret) {
        return tailSelfCallFromRetValue($last->value, $name, $params, $items);
    }

    if ($last instanceof IR\Assign && $last->dest === $matchDest) {
        if ($last->value instanceof IR\Temp) {
            $prev = $items[count($items) - 2] ?? null;
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
    if (!($callValue instanceof IR\CallValue) || !($callValue->callee instanceof IR\Temp)) {
        return null;
    }

    $partialCall = findCallDefiningTemp($items, $callValue->callee->id);
    if ($partialCall === null) {
        return null;
    }

    return tailSelfCallFromCall($partialCall, $name, $params, $callValue->args);
}

/** @return array{kind: string, args?: list<IR\Operand>, match?: IR\MatchStmt}|null */
function findMatchTailRecPlan(string $name, array $params, IR\MatchStmt $match, IR\Ret $ret): ?array
{
    if (!($ret->value instanceof IR\Temp) || $ret->value->id !== $match->dest) {
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

/** @param array{kind: string, args?: list<IR\Operand>, match?: IR\MatchStmt} $plan @param list<IR\Stmt> $items @return list<IR\Stmt> */
function convertTailRecBody(string $name, array $params, array $items, array $plan): array
{
    return match ($plan['kind']) {
        'direct' => [new IR\TailRecall($plan['args'])],
        'match' => [convertTailRecMatch($name, $params, $plan['match'])],
        default => throw new \RuntimeException("unknown tail recursion plan `{$plan['kind']}`"),
    };
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

    // The arms now end in `ret` / `tail_recall` themselves, so the match no
    // longer produces a value. It must be a `MatchReturn`: a `MatchStmt` carries
    // a result temp, and codegen turns an arm's `ret` into a *yield* into that
    // temp instead of a return when the match has a dest.
    return new IR\MatchReturn($match->scrutinee, $arms, false);
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function convertMatchArmToReturn(array $items, int $matchDest): array
{
    if ($items === []) {
        throw new \RuntimeException('empty match arm in tail recursion conversion');
    }

    $last = $items[count($items) - 1];
    if ($last instanceof IR\Ret) {
        return $items;
    }

    if ($last instanceof IR\Assign && $last->dest === $matchDest) {
        $items[count($items) - 1] = new IR\Ret($last->value);

        return $items;
    }

    throw new \RuntimeException('unsupported match arm shape for tail recursion conversion');
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function stripRecursiveTail(array $items, int $matchDest, string $name): array
{
    $items = array_values($items);
    if ($items === []) {
        return [];
    }

    $retValue = null;
    if ($items[count($items) - 1] instanceof IR\Ret) {
        $retValue = array_pop($items)->value;
    }

    if ($retValue !== null && $retValue instanceof IR\ExprCallValue && $retValue->callee instanceof IR\Temp) {
        $items = removeCallDefiningTemp($items, $retValue->callee->id);
    } elseif ($retValue === null && $items !== [] && $items[count($items) - 1] instanceof IR\CallValue) {
        // Only a `call_value` statement that *performs* the recursive call may
        // be stripped. When the ret value contained the recall directly (the
        // previous branch's shape) the trailing `call_value`, if any, merely
        // computes an argument of that recall — e.g. `t1 = f(x)` feeding
        // `tail_recall(rest, t1)` — and removing it leaves a dangling temp.
        $callValue = $items[count($items) - 1];
        if ($callValue->callee instanceof IR\Temp && isSelfCallDefiningTemp($items, $callValue->callee->id, $name)) {
            array_pop($items);
            $items = removeCallDefiningTemp($items, $callValue->callee->id);
        }
    }

    while ($items !== []) {
        $last = $items[count($items) - 1];
        if ($last instanceof IR\Assign && $last->dest === $matchDest) {
            array_pop($items);
            continue;
        }

        if ($last instanceof IR\Call && $last->callee === $name) {
            array_pop($items);
            continue;
        }

        break;
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
    foreach (array_reverse(\array_keys($items)) as $i) {
        if ($items[$i] instanceof IR\Call && $items[$i]->dest === $tempId) {
            unset($items[$i]);

            return array_values($items);
        }
    }

    return $items;
}
