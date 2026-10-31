<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use function Moggi\Backend\Php\Naming\mangleVar;
use Moggi\IR;
use function Moggi\Backend\Php\Naming\phpTemp;
use function Moggi\Backend\Php\Codegen\emitOperand;
use function Moggi\Backend\Php\Codegen\isLeafOperand;

function emitOperand(IR\Operand $operand, EmitEnv $env): string
{
    if ($operand instanceof IR\FnRef && isset($env->lambdaIndex[$operand->name])) {
        return emitLambdaReference($operand->name, $env);
    }

    return match ($operand::class) {
        IR\ConstInt::class => emitIntLiteral($operand->value),
        IR\ConstDouble::class => formatDoubleLiteral($operand->value),
        IR\ConstStr::class => exportStringLiteral($operand->value),
        IR\ConstChar::class => (string) $operand->value,
        IR\Local::class => $env->patternLocals[$operand->name]
            ?? ('$' . mangleVar($operand->name)),
        IR\Temp::class => phpTemp($operand->id),
        IR\Unit::class => 'null',
        IR\FnRef::class => emitTopLevelFnValue($operand->name, $env),
        IR\ListLit::class => '[' . \implode(', ', \array_map(
            static fn (IR\Operand $element): string => emitOperand($element, $env),
            $operand->elements,
        )) . ']',
        IR\Partial::class => emitPartialValue($operand, $env),
        IR\Intrinsic::class => emitIntrinsic($env, $operand),
        IR\ForeignCall::class => emitForeign(
            $operand,
            \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $env), $operand->args),
        ),
        IR\DictMethod::class => emitDictMethodOperand($operand, $env),
        default => throw new \RuntimeException('unsupported operand `' . $operand::class . '` in codegen'),
    };
}

/** An operand that is free to repeat: a temp, a local or a literal. */
function isLeafOperand(IR\Operand $op): bool
{
    return $op instanceof IR\Temp
        || $op instanceof IR\Local
        || $op instanceof IR\ConstInt
        || $op instanceof IR\ConstStr
        || $op instanceof IR\ConstChar
        || $op instanceof IR\Unit
        || $op instanceof IR\FnRef;
}

