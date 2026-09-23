<?php declare(strict_types=1);

namespace Moggi\Optimize\IrTransform;

use Moggi\IR;

use function Moggi\IR\Visit\mapOperandTree;

/** @param array<int, string> $temps */
function remapTempsInOperand(IR\Operand $operand, array $temps): IR\Operand
{
    if ($temps === []) {
        return $operand;
    }

    return mapOperandTree($operand, static function (IR\Operand $op) use ($temps): IR\Operand {
        if ($op instanceof IR\Temp && isset($temps[$op->id])) {
            return new IR\Local($temps[$op->id]);
        }

        return $op;
    });
}

/** @param array<int, string> $temps */
function remapTempsInStmt(IR\Stmt $stmt, array $temps): IR\Stmt
{
    if ($temps === []) {
        return $stmt;
    }

    return match ($stmt::class) {
        IR\Ret::class => new IR\Ret(remapTempsInOperand($stmt->value, $temps)),
        IR\Binop::class => new IR\Binop(
            $stmt->op,
            remapTempsInOperand($stmt->left, $temps),
            remapTempsInOperand($stmt->right, $temps),
            $stmt->dest,
        ),
        IR\Call::class => new IR\Call(
            $stmt->callee,
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => remapTempsInOperand($arg, $temps),
                $stmt->args,
            ),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\CallValue::class => new IR\CallValue(
            remapTempsInOperand($stmt->callee, $temps),
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => remapTempsInOperand($arg, $temps),
                $stmt->args,
            ),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\DictCall::class => new IR\DictCall(
            remapTempsInOperand($stmt->evidence, $temps),
            $stmt->method,
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => remapTempsInOperand($arg, $temps),
                $stmt->args,
            ),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\IoCall::class => new IR\IoCall(
            $stmt->callee,
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => remapTempsInOperand($arg, $temps),
                $stmt->args,
            ),
            $stmt->dest,
            $stmt->intrinsic,
            $stmt->runtime,
            $stmt->foreign,
            $stmt->srcLoc,
        ),
        IR\IoRun::class => new IR\IoRun(
            remapTempsInOperand($stmt->action, $temps),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\IoThrow::class => new IR\IoThrow(
            remapTempsInOperand($stmt->exception, $temps),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\IoCatch::class => new IR\IoCatch(
            remapTempsInOperand($stmt->action, $temps),
            remapTempsInOperand($stmt->handler, $temps),
            $stmt->dest,
        ),
        IR\IoFinally::class => new IR\IoFinally(
            remapTempsInOperand($stmt->action, $temps),
            remapTempsInOperand($stmt->cleanup, $temps),
            $stmt->dest,
        ),
        IR\TailRecall::class => new IR\TailRecall(
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => remapTempsInOperand($arg, $temps),
                $stmt->args,
            ),
        ),
        IR\MatchStmt::class => new IR\MatchStmt(
            remapTempsInOperand($stmt->scrutinee, $temps),
            remapTempsInArms($stmt->arms, $temps),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\MatchReturn::class => new IR\MatchReturn(
            remapTempsInOperand($stmt->scrutinee, $temps),
            remapTempsInArms($stmt->arms, $temps),
            $stmt->exhaustive,
        ),
        IR\IoMatch::class => new IR\IoMatch(
            remapTempsInOperand($stmt->scrutinee, $temps),
            remapTempsInArms($stmt->arms, $temps),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\Loop::class => new IR\Loop(new IR\Block(remapTempsInItems($stmt->body->items, $temps))),
        IR\IoAssignAction::class => new IR\IoAssignAction(
            $stmt->dest,
            new IR\Block(remapTempsInItems($stmt->body->items, $temps)),
            remapTempsInOperand($stmt->result, $temps),
            $stmt->srcLoc,
        ),
        IR\Assign::class => new IR\Assign($stmt->dest, remapTempsInOperand($stmt->value, $temps)),
        IR\Let::class => new IR\Let($stmt->name, remapTempsInOperand($stmt->value, $temps)),
        default => $stmt,
    };
}

/**
 * @param list<IR\MatchArm> $arms
 * @param array<int, string> $temps
 * @return list<IR\MatchArm>
 */
function remapTempsInArms(array $arms, array $temps): array
{
    return \array_map(
        static fn (IR\MatchArm $arm): IR\MatchArm => new IR\MatchArm(
            $arm->pattern,
            new IR\Block(remapTempsInItems($arm->body->items, $temps)),
            IR\Visit\mapGuards(
                $arm->guards,
                static fn (IR\Operand $guard): IR\Operand => remapTempsInOperand($guard, $temps),
                static fn (IR\Stmt $s): IR\Stmt => remapTempsInStmt($s, $temps),
            ),
        ),
        $arms,
    );
}

/**
 * @param list<IR\Stmt> $items
 * @param array<int, string> $temps
 * @return list<IR\Stmt>
 */
function remapTempsInItems(array $items, array $temps): array
{
    if ($temps === []) {
        return $items;
    }

    return \array_map(
        static fn (IR\Stmt $item): IR\Stmt => remapTempsInStmt($item, $temps),
        $items,
    );
}
