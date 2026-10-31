<?php declare(strict_types=1);

namespace Moggi\Backend\Managed;

require_once __DIR__ . '/../stack/source_lines.php';

use Moggi\IR;

use function Moggi\Backend\Meta\returnedCall;
use function Moggi\Backend\Meta\tempReadsForFunction;
use function Moggi\Backend\Stack\SourceLines\statementLoc;

/**
 * Walk one block, for any managed target.
 *
 * The two managed emitters carried the same loop: fold a returned call into the block's
 * destination, otherwise emit the statement, and note its source line. Only the four target
 * operations differ, so they are callables: note a location, emit a returned call value, emit a
 * statement, and return from the function. The target keeps its instruction encoding.
 *
 * @param callable(?IR\SrcLoc): void $note
 * @param callable(IR\Stmt): void $emitCallValue
 * @param callable(IR\Stmt): void $emitStmt
 * @param callable(): void $emitReturn
 */
function emitBlock(
    object $env,
    IR\Block $block,
    callable $note,
    callable $emitCallValue,
    callable $emitStmt,
    callable $emitReturn,
): void {
    $items = \array_values($block->items);
    $tempReads = tempReadsForFunction($env->tempReads, $block);

    for ($i = 0; $i < \count($items); ++$i) {
        $call = returnedCall($items[$i], $items[$i + 1] ?? null, $tempReads);
        if ($call !== null) {
            $note(statementLoc($call));
            $emitCallValue($call);
            $emitReturn();
            ++$i;

            continue;
        }

        $emitStmt($items[$i]);
    }
}

/**
 * Whether control never falls out of the end of `$stmt`.
 *
 * Used to decide whether a fall-through jump has to be appended: emitting one after an
 * unconditional transfer produces unreachable code that the verifier still demands a stack map
 * frame for. Shared because the answer is a property of the IR, not of the target.
 */
function stmtTerminates(IR\Stmt $stmt): bool
{
    if ($stmt instanceof IR\Ret
        || $stmt instanceof IR\MatchReturn
        || $stmt instanceof IR\TailRecall
        || $stmt instanceof IR\IoThrow) {
        return true;
    }
    if ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\IoMatch) {
        $arms = $stmt->arms;
        if ($arms === []) {
            return false;
        }
        foreach ($arms as $arm) {
            if (! blockTerminates($arm->body)) {
                return false;
            }
        }

        return true;
    }

    return false;
}

function blockTerminates(IR\Block $block): bool
{
    $items = $block->items;

    return $items !== [] && stmtTerminates($items[\array_key_last($items)]);
}
