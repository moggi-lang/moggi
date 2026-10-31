<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use function Moggi\Backend\Meta\tempReadCounts;
use function Moggi\Backend\Php\Naming\mangleVar;
use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;
use function Moggi\Backend\Meta\operandCallSrcLoc;
use function Moggi\Backend\Meta\returnedCall;
use function Moggi\Backend\Php\Naming\phpTemp;
use function Moggi\IR\Visit\collectLocalsInOperand;
use function Moggi\Backend\Php\Codegen\emitOperand;
use Moggi\IR\SrcLoc;
use function Moggi\Backend\Php\Codegen\emitBlock;
use function Moggi\Backend\Php\Codegen\emitFunctionReturn;

function emitTailRecall(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);
    $params = $env->tailRecParams;
    $args = $stmt->args;
    $argExprs = \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $env), $args);
    $order = tailRecallAssignOrder($params, $args);
    $out = '';

    if ($order === null) {
        foreach (\array_keys($params) as $i) {
            $out .= $pad . '$__tail' . $i . ' = ' . $argExprs[$i] . ";\n";
        }
        foreach ($params as $i => $param) {
            $out .= $pad . '$' . mangleVar($param) . ' = $__tail' . $i . ";\n";
        }

        return $out . $pad . "continue;\n";
    }

    foreach ($order as $i) {
        $out .= $pad . '$' . mangleVar($params[$i]) . ' = ' . $argExprs[$i] . ";\n";
    }

    return $out . $pad . "continue;\n";
}

/** @param array<int, string> $params @param list<IR\Operand> $args @return ?array<int, int> */
function tailRecallAssignOrder(array $params, array $args): ?array
{
    $n = \count($params);
    if ($n <= 1) {
        return \range(0, \max(0, $n - 1));
    }

    $paramIndex = [];
    foreach ($params as $i => $param) {
        $paramIndex[$param] = $i;
    }

    $mustAssignFirst = \array_fill(0, $n, []);
    for ($i = 0; $i < $n; ++$i) {
        foreach (collectLocalsInOperand($args[$i]) as $local) {
            if (! isset($paramIndex[$local])) {
                continue;
            }

            $j = $paramIndex[$local];
            if ($j !== $i) {
                $mustAssignFirst[$j][] = $i;
            }
        }
    }

    $inDegree = \array_fill(0, $n, 0);
    foreach ($mustAssignFirst as $j => $predecessors) {
        $predecessors = \array_values(\array_unique($predecessors));
        $mustAssignFirst[$j] = $predecessors;
        $inDegree[$j] = \count($predecessors);
    }

    $queue = [];
    for ($i = 0; $i < $n; ++$i) {
        if ($inDegree[$i] === 0) {
            $queue[] = $i;
        }
    }

    $order = [];
    while ($queue !== []) {
        $i = \array_shift($queue);
        $order[] = $i;
        for ($j = 0; $j < $n; ++$j) {
            if (! \in_array($i, $mustAssignFirst[$j], true)) {
                continue;
            }

            --$inDegree[$j];
            if ($inDegree[$j] === 0) {
                $queue[] = $j;
            }
        }
    }

    return \count($order) === $n ? $order : null;
}

function emitBlock(IR\Block $block, int $indent, EmitEnv $env): string
{
    $items = \array_values($block->items);
    $tempReads = $env->tempReads
        ?? throw new \TypeError('php codegen: the context carries no tempReads slot ({@see tempReadCounts})');
    $out = '';

    for ($i = 0; $i < \count($items); ++$i) {
        $call = returnedCall($items[$i], $items[$i + 1] ?? null, $tempReads);
        if ($call !== null) {
            $out .= frameMark($env, $call->srcLoc)
                . \str_repeat('    ', $indent) . 'return ' . emitReturnedCall($call, $env) . ";\n";
            ++$i;

            continue;
        }

        $out .= emitStmt($items[$i], $indent, $env);
    }

    return $out;
}

/**
 * Zero-width marker recording that the following generated line is a stack
 * frame for `$loc`. Stripped (and turned into a line mapping the module's map
 * constant carries) by SourceMapBuilder::resolveFrames().
 */
function frameMark(EmitEnv $env, ?IR\SrcLoc $loc): string
{
    $map = $env->sourceMap;
    if (! $map instanceof SourceMapBuilder || $loc === null) {
        return '';
    }

    $generated = (string) $env->frameFunction;

    return $map->allocFrame($loc, $generated !== '' ? $generated : $loc->function);
}

/** The first tail-call argument that is itself a call or a faulting intrinsic. */
function tailRecallSrcLoc(IR\Stmt $stmt): ?IR\SrcLoc
{
    if (! ($stmt instanceof IR\TailRecall)) {
        return null;
    }
    foreach ($stmt->args as $arg) {
        $loc = operandCallSrcLoc($arg);
        if ($loc !== null) {
            return $loc;
        }
    }

    return null;
}

function emitStmt(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);

    return match ($stmt::class) {
        IR\Ret::class => frameMark($env, operandCallSrcLoc($stmt->value))
            . $pad . 'return ' . emitFunctionReturn($stmt->value, $env) . ";\n",
        IR\Call::class => frameMark($env, $stmt->srcLoc) . emitCallStmt($stmt->callee, $stmt->args, $stmt->dest, $indent, $env),
        IR\IoCall::class => frameMark($env, $stmt->srcLoc) . emitIoCall($stmt, $indent, $env),
        IR\IoRun::class => frameMark($env, $stmt->srcLoc) . emitIoRun($stmt, $indent, $env),
        IR\IoAssignAction::class => frameMark($env, $stmt->srcLoc) . emitIoAssignAction($stmt, $indent, $env),
        IR\IoThrow::class => emitIoThrow($stmt, $indent, $env),
        IR\IoCatch::class => emitIoCatch($stmt, $indent, $env),
        IR\IoFinally::class => emitIoFinally($stmt, $indent, $env),
        IR\CallValue::class => frameMark($env, $stmt->srcLoc) . $pad . phpTemp($stmt->dest) . ' = ' . emitCallValue($stmt->callee, $stmt->args, $env) . ";\n",
        IR\DictCall::class => frameMark($env, $stmt->srcLoc) . $pad . phpTemp($stmt->dest) . ' = (' . emitOperand($stmt->evidence, $env)
            . '[' . \json_encode($stmt->method, JSON_UNESCAPED_UNICODE) . '])('
            . \implode(', ', \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $env), $stmt->args))
            . ");\n",
        IR\Assign::class => frameMark($env, operandCallSrcLoc($stmt->value))
            . $pad . phpTemp($stmt->dest) . ' = ' . emitOperand($stmt->value, $env) . ";\n",
        IR\Let::class => frameMark($env, operandCallSrcLoc($stmt->value))
            . $pad . '$' . mangleVar($stmt->name) . ' = ' . emitOperand($stmt->value, $env) . ";\n",
        IR\MatchStmt::class, IR\MatchReturn::class => emitMatchStmt($stmt, $indent, $env),
        IR\IoMatch::class => emitMatchIo($stmt, $indent, $env),
        IR\Loop::class => $pad . "while (true) {\n" . emitBlock($stmt->body, $indent + 1, $env) . $pad . "}\n",
        IR\TailRecall::class => frameMark($env, tailRecallSrcLoc($stmt))
            . emitTailRecall($stmt, $indent, $env),
        default => throw new \RuntimeException('unsupported ir stmt `' . $stmt::class . '` in codegen'),
    };
}

function emitIoCall(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);
    $dest = $stmt->dest;
    $assign = $dest === null ? '' : phpTemp($dest) . ' = ';
    $op = $stmt->op;

    if ($op instanceof IR\IoIntrinsic) {
        $expr = emitIntrinsic($env, new IR\Intrinsic($op->name, $stmt->args));

        return $pad . $assign . $expr . ";\n";
    }

    if ($op instanceof IR\IoForeign && \is_string($op->runtime) && \str_starts_with($op->runtime, 'foreign:')) {
        $foreign = $op->call;

        return emitForeignIoStatement(
            $foreign,
            \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $env), $stmt->args),
            $dest,
            $pad,
            $foreign->ioWrap,
        );
    }

    return $pad . $assign . emitStaticCall($stmt->callee, $stmt->args, $env) . ";\n";
}

function emitFunctionReturn(IR\Operand $value, EmitEnv $env): string
{
    return match ($value::class) {
        IR\Partial::class => emitPartialValue($value, $env),
        IR\Intrinsic::class => emitOperand($value, $env),
        IR\FnRef::class => isset($env->lambdaIndex[$value->name])
            ? emitLambdaReference($value->name, $env)
            : emitTopLevelFnValue($value->name, $env),
        default => emitOperand($value, $env),
    };
}

