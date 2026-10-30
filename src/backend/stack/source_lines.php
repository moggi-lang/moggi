<?php declare(strict_types=1);

namespace Moggi\Backend\Stack\SourceLines;

use Moggi\IR;

/**
 * Derive stack-backend debug locations from the IR tree.
 *
 * Statements contribute their declared `$srcLoc`; intrinsic operands contribute
 * the location of the expression they replace. Both JVM and .NET use this same
 * walk for host line tables and compiler frame tables, avoiding divergent
 * per-emitter lists.
 *
 * PHP is intentionally excluded: it uses a runtime source map and records
 * throw sites only (`Moggi\Backend\Meta\operandCallSrcLoc`).
 */

/** The location a statement names, or null when the statement declares none. */
function statementLoc(IR\Stmt $stmt): ?IR\SrcLoc
{
    return $stmt->srcLoc ?? null;
}

/** The location an operand names: intrinsics carry the expression they replaced; nothing else does. */
function operandLoc(IR\Operand $op): ?IR\SrcLoc
{
    return $op instanceof IR\Intrinsic ? $op->srcLoc : null;
}
