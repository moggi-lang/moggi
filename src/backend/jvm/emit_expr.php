<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;

require_once __DIR__ . '/../stack/source_lines.php';
require_once __DIR__ . '/../managed/operand.php';

use Moggi\IR;

use function Moggi\Backend\Managed\emitOperand as emitManagedOperand;

/**
 * One operand, dispatched by the backend-neutral traversal.
 *
 * The node chain this used to spell out — literals, unit, locals, temps, intrinsics, list
 * literals, foreign calls, partials, dictionary methods and function references — now lives in
 * `Moggi\Backend\Managed\emitOperand()`, shared with the .NET emitter. What stays in this file is
 * the delegating entry point; what stays target-specific is `EmitEnv`'s `OperandTarget` hooks,
 * which are how a JVM pushes a value, loads a slot and calls a method.
 */
function emitOperand(EmitEnv $env, IR\Operand $op): void
{
    emitManagedOperand($env, $op, $env);
}
