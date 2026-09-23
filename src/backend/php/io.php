<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Io;

use Moggi\IR;

use function Moggi\Backend\Meta\lambdaCaptureNamesInBlock;
use function Moggi\Backend\Php\Codegen\emitBlock;
use function Moggi\Backend\Php\Codegen\emitOperand;
use function Moggi\Backend\Php\Codegen\emitRetValue;
use function Moggi\Backend\Php\Codegen\localsUsedInBlock;
use function Moggi\Backend\Php\Intrinsics\emitSrcLocExpr;
use function Moggi\Backend\Php\Naming\mangleVar;
use function Moggi\Backend\Php\Naming\phpTemp;

function emitIoRunExpr(string $actionExpr): string
{
    return '(' . $actionExpr . '[1])()';
}

function emitIoRunResultExpr(string $resultExpr): string
{
    return '(\is_array(' . $resultExpr . ') && (' . $resultExpr . '[0] ?? null) === \'__io\''
        . ' ? (' . $resultExpr . '[1])() : ' . $resultExpr . ')';
}

function emitIoAssignAction(IR\Stmt $stmt, int $indent, array $ctx): string
{
    $pad = str_repeat('    ', $indent);
    $dest = phpTemp($stmt->dest);
    $body = $stmt->body;
    $result = $stmt->result;

    $assignedLocals = [];
    $assignedTemps = [];
    $collectAssigned = static function (IR\Block $block) use (&$assignedLocals, &$assignedTemps, &$collectAssigned): void {
        foreach ($block->items as $item) {
            if ($item instanceof IR\Let) {
                $assignedLocals[$item->name] = true;
            }
            $definedTemp = match ($item::class) {
                IR\Assign::class,
                IR\Binop::class,
                IR\Call::class,
                IR\CallValue::class,
                IR\DictCall::class,
                IR\MatchStmt::class,
                IR\IoAssignAction::class => $item->dest,
                IR\IoRun::class, IR\IoMatch::class, IR\IoCall::class,
                IR\IoThrow::class, IR\IoCatch::class, IR\IoFinally::class => $item->dest,
                default => null,
            };
            if ($definedTemp !== null) {
                $assignedTemps[$definedTemp] = true;
            }
            foreach (IR\Visit\stmtNestedBlocks($item) as $nested) {
                $collectAssigned($nested);
            }
        }
    };
    $collectAssigned($body);

    $captured = [];
    foreach ([...localsUsedInBlock($body), ...IR\Visit\collectLocalsInOperand($result)] as $name) {
        if (!isset($assignedLocals[$name])) {
            $captured[$name] = '$' . mangleVar($name);
        }
    }

    $lambdaMeta = $ctx['lambdaMeta'] ?? [];
    foreach (lambdaCaptureNamesInBlock($body, $lambdaMeta) as $capture) {
        if (!isset($assignedLocals[$capture])) {
            $captured[$capture] = '$' . mangleVar($capture);
        }
    }

    $temps = [];
    IR\Visit\walkBlock(
        $body,
        static function (IR\Stmt $_stmt): void {
        },
        static function (IR\Operand $op) use (&$temps): void {
            if ($op instanceof IR\Temp) {
                $temps[$op->id] = true;
            }
        },
    );
    IR\Visit\walkOperand($result, static function (IR\Operand $op) use (&$temps): void {
        if ($op instanceof IR\Temp) {
            $temps[$op->id] = true;
        }
    });
    foreach (\array_keys($temps) as $tempId) {
        if (!isset($assignedTemps[$tempId])) {
            $captured['__t' . $tempId] = phpTemp($tempId);
        }
    }

    $useList = $captured === []
        ? ''
        : ' use (' . join(', ', array_values($captured)) . ')';

    $inner = emitBlock($body, $indent + 1, $ctx);
    $value = emitRetValue($result, $ctx);
    // Nested IO from continuations may return a box; a unit result never can, so the unwrap
    // is a real test and not dead code.
    $return = 'return ' . ($result instanceof IR\Unit ? $value : emitIoRunResultExpr($value)) . ';';

    return $pad . $dest . " = ['__io', static function(){$useList}: mixed {\n"
        . $inner
        . $pad . '    ' . $return . "\n"
        . $pad . "}];\n";
}

function emitIoRun(IR\Stmt $stmt, int $indent, array $ctx): string
{
    $pad = str_repeat('    ', $indent);
    $action = emitOperand($stmt->action, $ctx);
    // Accept both boxed `__io` actions and already-executed/null results
    // (straight-line IO () callees return null when used as first-class values).
    $run = emitIoRunResultExpr($action);
    $dest = $stmt->dest;

    if ($dest === null) {
        return $pad . $run . ";\n";
    }

    return $pad . phpTemp($dest) . ' = ' . $run . ";\n";
}

function emitIoThrow(IR\Stmt $stmt, int $indent, array $ctx): string
{
    $pad = str_repeat('    ', $indent);
    $ex = emitOperand($stmt->exception, $ctx);
    $loc = emitSrcLocExpr($stmt->srcLoc, $ctx);
    $out = $pad . '\\Moggi\\throwSomeException(' . $ex . ', ' . $loc . ");\n";
    // Unreachable, but keeps SSA result temps defined when throw is the box result.
    if ($stmt->dest !== null) {
        $out .= $pad . phpTemp($stmt->dest) . " = null;\n";
    }

    return $out;
}

function emitIoCatch(IR\Stmt $stmt, int $indent, array $ctx): string
{
    $pad = str_repeat('    ', $indent);
    $innerPad = $pad . '    ';
    $action = emitOperand($stmt->action, $ctx);
    $handler = emitOperand($stmt->handler, $ctx);
    $dest = $stmt->dest;
    $run = emitIoRunResultExpr($action);

    $out = $pad . "try {\n";
    if ($dest === null) {
        $out .= $innerPad . $run . ";\n";
    } else {
        $out .= $innerPad . phpTemp($dest) . ' = ' . $run . ";\n";
    }
    $out .= $pad . "} catch (\\Throwable \$__moggiEx) {\n";
    $out .= $innerPad . "if (\$__moggiEx instanceof \\Moggi\\MoggiException) {\n";
    $out .= $innerPad . "    \$__moggiSe = \\Moggi\\exceptionAttachWrapper("
        . "\$__moggiEx->someException, \$__moggiEx);\n";
    $out .= $innerPad . "} else {\n";
    $out .= $innerPad . "    \$__moggiWrapped = new \\Moggi\\MoggiException("
        . "\\Moggi\\normalizeHostException(\$__moggiEx), \$__moggiEx, null);\n";
    $out .= $innerPad . "    \$__moggiSe = \\Moggi\\exceptionAttachWrapper("
        . "\$__moggiWrapped->someException, \$__moggiWrapped);\n";
    $out .= $innerPad . "}\n";
    $out .= $innerPad . '$__moggiHandlerIo = \\Moggi\\__apply(' . $handler . ', $__moggiSe);' . "\n";
    $handlerRun = emitIoRunResultExpr('$__moggiHandlerIo');
    if ($dest === null) {
        $out .= $innerPad . $handlerRun . ";\n";
    } else {
        $out .= $innerPad . phpTemp($dest) . ' = ' . $handlerRun . ";\n";
    }
    $out .= $pad . "}\n";

    return $out;
}

function emitIoFinally(IR\Stmt $stmt, int $indent, array $ctx): string
{
    $pad = str_repeat('    ', $indent);
    $innerPad = $pad . '    ';
    $action = emitOperand($stmt->action, $ctx);
    $cleanup = emitOperand($stmt->cleanup, $ctx);
    $dest = $stmt->dest;
    $run = emitIoRunResultExpr($action);
    $cleanupRun = emitIoRunResultExpr($cleanup);

    // Sync finally: always cleanup; if cleanup throws it wins; else rethrow original.
    $out = $pad . "\$__moggiFinallyEx = null;\n";
    $out .= $pad . "\$__moggiFinallyResult = null;\n";
    $out .= $pad . "try {\n";
    $out .= $innerPad . "\$__moggiFinallyResult = " . $run . ";\n";
    $out .= $pad . "} catch (\\Throwable \$__moggiEx) {\n";
    $out .= $innerPad . "\$__moggiFinallyEx = \$__moggiEx;\n";
    $out .= $pad . "}\n";
    $out .= $pad . "try {\n";
    $out .= $innerPad . $cleanupRun . ";\n";
    $out .= $pad . "} catch (\\Throwable \$__moggiCleanupEx) {\n";
    $out .= $innerPad . "\\Moggi\\rethrowNormalized(\$__moggiCleanupEx);\n";
    $out .= $pad . "}\n";
    $out .= $pad . "if (\$__moggiFinallyEx !== null) {\n";
    $out .= $innerPad . "\\Moggi\\rethrowNormalized(\$__moggiFinallyEx);\n";
    $out .= $pad . "}\n";
    if ($dest !== null) {
        $out .= $pad . phpTemp($dest) . " = \$__moggiFinallyResult;\n";
    }

    return $out;
}
