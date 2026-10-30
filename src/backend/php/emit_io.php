<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use function Moggi\Backend\Php\Naming\mangleVar;
use Moggi\IR;
use function Moggi\Backend\Php\Naming\phpTemp;
use function Moggi\IR\Visit\collectLocalsInOperand;
use function Moggi\IR\Visit\walkOperand;
use function Moggi\Backend\Php\Codegen\emitOperand;
use function Moggi\Backend\Meta\lambdaCaptureNamesInBlock;
use function Moggi\Backend\Php\Codegen\emitBlock;
use function Moggi\Backend\Php\Codegen\emitFunctionReturn;
use function Moggi\IR\Visit\collectLocalsInBlock;

/**
 * A run site: one layer of the action value the compiler resolved for it.
 *
 * Inline, not through a runtime helper that names a wrong shape: the guard costs 37 ns more per run
 * (2.4× the bare dispatch, `php -r` dispatch bench, 3 000 000 runs) and, called from the program
 * entry, its frame lands in every uncaught-exception report (`Exec-Exception-HostFrame`,
 * `Exec-Exception-HostCause-PHP`). Every producer of an `IO` value emits `['__io', thunk]`, so a
 * value of another shape here is a fatal error at this line already.
 */
function emitIoRunResultExpr(string $resultExpr): string
{
    return '(' . $resultExpr . '[1])()';
}

function emitIoAssignAction(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);
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
                IR\Call::class,
                IR\CallValue::class,
                IR\DictCall::class,
                IR\MatchStmt::class,
                IR\IoAssignAction::class => $item->dest,
                IR\IoRun::class, IR\IoCall::class,
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
    foreach ([...collectLocalsInBlock($body), ...IR\Visit\collectLocalsInOperand($result)] as $name) {
        if (! isset($assignedLocals[$name])) {
            $captured[$name] = '$' . mangleVar($name);
        }
    }

    $lambdaMeta = $env->lambdaMeta;
    foreach (lambdaCaptureNamesInBlock($body, $lambdaMeta) as $capture) {
        if (! isset($assignedLocals[$capture])) {
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
        if (! isset($assignedTemps[$tempId])) {
            $captured['__t' . $tempId] = phpTemp($tempId);
        }
    }

    $useList = $captured === []
        ? ''
        : ' use (' . \implode(', ', \array_map(
            static fn (string $var): string => '&' . $var,
            \array_values($captured),
        )) . ')';

    $inner = emitBlock($body, $indent + 1, $env);
    $value = emitFunctionReturn($result, $env);
    $return = 'return ' . $value . ';';

    return $pad . $dest . " = ['__io', static function(){$useList}: mixed {\n"
        . $inner
        . $pad . '    ' . $return . "\n"
        . $pad . "}];\n";
}

function emitIoRun(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);
    $action = emitOperand($stmt->action, $env);
    $run = emitIoRunResultExpr($action);
    $dest = $stmt->dest;

    if ($dest === null) {
        return $pad . $run . ";\n";
    }

    return $pad . phpTemp($dest) . ' = ' . $run . ";\n";
}

function emitIoThrow(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);
    $ex = emitOperand($stmt->exception, $env);
    $loc = emitSrcLocValue($stmt->srcLoc, $env);
    $out = $pad . '\\Moggi\\throwSomeException(' . $ex . ', ' . $loc . ");\n";
    if ($stmt->dest !== null) {
        $out .= $pad . phpTemp($stmt->dest) . " = null;\n";
    }

    return $out;
}

function emitIoCatch(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);
    $innerPad = $pad . '    ';
    $action = emitOperand($stmt->action, $env);
    $handler = emitOperand($stmt->handler, $env);
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
    $out .= $innerPad . '    $__moggiSe = \\Moggi\\exceptionAttachWrapper('
        . "\$__moggiEx->someException, \$__moggiEx);\n";
    $out .= $innerPad . "} else {\n";
    $out .= $innerPad . '    $__moggiWrapped = new \\Moggi\\MoggiException('
        . "\\Moggi\\normalizeHostException(\$__moggiEx), \$__moggiEx, null);\n";
    $out .= $innerPad . '    $__moggiSe = \\Moggi\\exceptionAttachWrapper('
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

function emitIoFinally(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);
    $innerPad = $pad . '    ';
    $action = emitOperand($stmt->action, $env);
    $cleanup = emitOperand($stmt->cleanup, $env);
    $dest = $stmt->dest;
    $run = emitIoRunResultExpr($action);
    $cleanupRun = emitIoRunResultExpr($cleanup);

    $out = $pad . "\$__moggiFinallyEx = null;\n";
    $out .= $pad . "\$__moggiFinallyResult = null;\n";
    $out .= $pad . "try {\n";
    $out .= $innerPad . '$__moggiFinallyResult = ' . $run . ";\n";
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

/** @param list<string> $argExprs */
function emitIntrinsicState(EmitEnv $env, IR\Intrinsic $op, array $argExprs): ?string
{
    return match ($op->name) {
        'ioPure#', 'ioBind#' => throw new \InvalidArgumentException(
            "intrinsic `{$name}` must be erased by strict IO normalization",
        ),
        'stPure#' => '\\Moggi\\stPure(' . $argExprs[0] . ')',
        'stBind#' => '\\Moggi\\stBind(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'runST#' => '\\Moggi\\stRun(' . $argExprs[0] . ')',
        'stToIO#' => '\\Moggi\\stToIO(' . $argExprs[0] . ')',
        'newSTRef#' => '\\Moggi\\stNewRef(' . $argExprs[0] . ')',
        'readSTRef#' => '\\Moggi\\stReadRef(' . $argExprs[0] . ')',
        'writeSTRef#' => '\\Moggi\\stWriteRef(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'arrayNew#' => '\\Moggi\\arrayNew(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'arraySize#' => '\\Moggi\\arraySize(' . $argExprs[0] . ')',
        'arrayBounds#' => '\\Moggi\\arrayBounds(' . $argExprs[0] . ')',
        'arrayRead#' => '\\Moggi\\arrayRead(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'newSTArray#' => '\\Moggi\\stNewArray(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'readSTArray#' => '\\Moggi\\stReadArray(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'writeSTArray#' => '\\Moggi\\stWriteArray(' . $argExprs[0] . ', ' . $argExprs[1] . ', ' . $argExprs[2] . ')',
        'sizeSTArray#' => '\\Moggi\\stSizeArray(' . $argExprs[0] . ')',
        'boundsSTArray#' => '\\Moggi\\stBoundsArray(' . $argExprs[0] . ')',
        'sameSTArray#' => "({$argExprs[0]} === {$argExprs[1]})",
        'freezeSTArray#' => '\\Moggi\\stFreezeArray(' . $argExprs[0] . ', true)',
        'thawArray#' => '\\Moggi\\stThawArray(' . $argExprs[0] . ', true)',
        'unsafeFreezeSTArray#' => '\\Moggi\\stFreezeArray(' . $argExprs[0] . ', false)',
        'unsafeThawArray#' => '\\Moggi\\stThawArray(' . $argExprs[0] . ', false)',
        'newIOArray#' => '\\Moggi\\ioNewArray(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'readIOArray#' => '\\Moggi\\ioReadArray(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'writeIOArray#' => '\\Moggi\\ioWriteArray(' . $argExprs[0] . ', ' . $argExprs[1] . ', ' . $argExprs[2] . ')',
        'sizeIOArray#' => '\\Moggi\\ioSizeArray(' . $argExprs[0] . ')',
        'boundsIOArray#' => '\\Moggi\\ioBoundsArray(' . $argExprs[0] . ')',
        'sameIOArray#' => "({$argExprs[0]} === {$argExprs[1]})",
        'freezeIOArray#' => '\\Moggi\\ioFreezeArray(' . $argExprs[0] . ', true)',
        'thawIOArray#' => '\\Moggi\\ioThawArray(' . $argExprs[0] . ', true)',
        'unsafeFreezeIOArray#' => '\\Moggi\\ioFreezeArray(' . $argExprs[0] . ', false)',
        'unsafeThawIOArray#' => '\\Moggi\\ioThawArray(' . $argExprs[0] . ', false)',
        default => null,
    };
}
