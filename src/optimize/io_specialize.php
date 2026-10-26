<?php declare(strict_types=1);

namespace Moggi\Optimize\IoSpecialize;

use Moggi\IR;

use function Moggi\IR\Visit\stmtDirectOperands;
use function Moggi\IR\Visit\walkBlock;
use function Moggi\IR\Visit\walkStmt;

use function Moggi\Optimize\GlobalPass\usedInOperand;
use function Moggi\Semantics\IoBoundary\functionReturnsIo as ioBoundaryFunctionReturnsIo;

use Moggi\Syntax\Ast;

function foldIoActionBoxes(IR\Module $module): IR\Module
{
    $functions = \array_map(
        static fn (IR\FunctionDecl $function): IR\FunctionDecl => $function->withBody(
            new IR\Block(foldIoActionBoxesInItems($function->body->items)),
        ),
        $module->functions,
    );

    return new IR\Module(
        $functions,
        $module->data,
        $module->instanceEvidence,
        $module->entry,
        $module->moduleName,
        $module->sourceFile,
    );
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function foldIoActionBoxesInItems(array $items): array
{
    $assignByTemp = [];
    foreach ($items as $item) {
        if ($item instanceof IR\IoAssignAction) {
            $assignByTemp[$item->dest] = $item;
        }
    }

    $referenced = [];
    foreach ($items as $item) {
        foreach (tempRefsInStmt($item) as $tempId) {
            $referenced[$tempId] = true;
        }
    }

    $out = [];
    foreach ($items as $item) {
        if ($item instanceof IR\IoAssignAction) {
            if (! isset($referenced[$item->dest])) {
                continue;
            }
        }

        if ($item instanceof IR\IoRun) {
            $inlined = inlineIoRun($item, $assignByTemp);
            if ($inlined !== null) {
                \array_push($out, ...$inlined);

                continue;
            }
        }

        $out[] = $item;
    }

    return $out;
}

/**
 * The body of a box, standing in for the `io_run` that executed it.
 *
 * Only a box that binds its result as its last statement can stand in: its value is then that
 * binding. One whose arms `Ret` keeps a box and a run — inlining it would move that `Ret` into the
 * enclosing function and leave the run's dest unbound.
 *
 * @param array<int, IR\IoAssignAction> $assignByTemp
 * @return ?list<IR\Stmt>
 */
function inlineIoRun(IR\IoRun $stmt, array $assignByTemp): ?array
{
    $action = $stmt->action;
    if (! $action instanceof IR\Temp) {
        return null;
    }

    $assign = $assignByTemp[$action->id] ?? null;
    if ($assign === null || ! $assign->result instanceof IR\Temp) {
        return null;
    }

    $body = $assign->body->items;
    $last = $body === [] ? null : $body[\count($body) - 1];
    if (! $last instanceof IR\Assign || $last->dest !== $assign->result->id) {
        return null;
    }

    \array_pop($body);
    if ($stmt->dest !== null) {
        $body[] = new IR\Assign($stmt->dest, $last->value);
    }

    return $body;
}

/**
 * Every temp a statement reads, including the ones read by a block it owns.
 *
 * A box's body is a nested block; a temp defined in the enclosing function and read only there is
 * still live, so the walk has to descend or the box that defines it looks unreferenced.
 *
 * @return list<int>
 */
function tempRefsInStmt(IR\Stmt $stmt): array
{
    $refs = [];
    walkStmt(
        $stmt,
        static function (): void {
        },
        static function (IR\Operand $operand) use (&$refs): void {
            foreach (\array_keys(usedInOperand($operand)['temps']) as $tempId) {
                $refs[$tempId] = true;
            }
        },
    );

    return \array_keys($refs);
}

/** @return list<IR\Operand> */
function stmtOperands(IR\Stmt $stmt): array
{
    return stmtDirectOperands($stmt);
}

function recomputeIoStraightLine(IR\FunctionDecl $function): IR\FunctionDecl
{
    $hasBoxOrRun = false;
    walkBlock($function->body, static function (IR\Stmt $stmt) use (&$hasBoxOrRun): void {
        if ($stmt instanceof IR\IoRun || $stmt instanceof IR\IoAssignAction
            || $stmt instanceof IR\IoCatch || $stmt instanceof IR\IoFinally
            || $stmt instanceof IR\IoThrow) {
            $hasBoxOrRun = true;
        }
    }, static function (): void {
    });

    if ($hasBoxOrRun) {
        $function->ioStraightLine = false;
    } elseif ($function->type !== null && functionReturnsIo($function->type)) {
        $function->ioStraightLine = true;
    }

    return $function;
}

function functionReturnsIo(?Ast\AstNode $type): bool
{
    return ioBoundaryFunctionReturnsIo(
        $type instanceof Ast\TypeNode ? $type : null,
    );
}

/** @param list<IR\FunctionDecl> $functions @return list<IR\FunctionDecl> */
function recomputeIoStraightLineAll(array $functions): array
{
    return \array_map(recomputeIoStraightLine(...), $functions);
}
