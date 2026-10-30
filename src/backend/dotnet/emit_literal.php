<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use function Moggi\Backend\DotNet\Il\ilString;
use function Moggi\Backend\DotNet\Verify\dotNetConstLiteralValue;

function formatDoubleLiteral(float $v): string
{
    if (\is_nan($v) || \is_infinite($v)) {
        $bytes = \unpack('C8', \pack('e', $v));
        $hex = \implode(' ', \array_map(static fn (int $b): string => \sprintf('%02X', $b), $bytes));

        return "({$hex})";
    }
    $s = \sprintf('%.17G', $v);
    if (! \str_contains($s, '.') && ! \str_contains($s, 'E') && ! \str_contains($s, 'e')) {
        $s .= '.0';
    }

    return $s;
}

/**
 * Inline a C# `const`/enum member as its constant value (see
 * {@see dotNetConstLiteralValue}). The load leaves exactly what an `ldsfld`
 * of $desc would have left, so the caller boxes the result the same way —
 * except enum members, which `Enum.ToObject` already returns boxed, and
 * which therefore skip the caller's box. Returns false when the literal
 * cannot be represented, keeping the `ldsfld` fallback.
 */
function emitConstLiteral(EmitEnv $env, array $lit, string $desc): bool
{
    $kind = $lit['kind'];
    $value = $lit['value'];
    $enum = $lit['enum'] ?? null;
    if (\is_string($enum) && $enum !== '') {
        $wide = $kind === 'i8';
        $env->m->emit('ldtoken valuetype ' . qualifyType($enum));
        $env->m->emit('call class [System.Runtime]System.Type'
            . ' [System.Runtime]System.Type::GetTypeFromHandle('
            . 'valuetype [System.Runtime]System.RuntimeTypeHandle)');
        $env->m->emit(($wide ? 'ldc.i8 ' : 'ldc.i4 ') . $value);
        $env->m->emit('call object [System.Runtime]System.Enum::ToObject('
            . 'class [System.Runtime]System.Type, ' . ($wide ? 'int64' : 'int32') . ')');

        return true;
    }
    switch ($kind) {
        case 'bool':
            $env->m->emit(((int) $value !== 0) ? 'ldc.i4.1' : 'ldc.i4.0');
            break;
        case 'i4':
            $env->m->emit('ldc.i4 ' . $value);
            break;
        case 'i8':
            $env->m->emit('ldc.i8 ' . $value);
            break;
        case 'r4':
            $env->m->emit('ldc.r4 ' . $value);
            break;
        case 'r8':
            $env->m->emit('ldc.r8 ' . $value);
            break;
        case 'string':
            $env->m->emit($value === null ? 'ldnull' : 'ldstr ' . ilString($value));
            break;
        default:
            return false;
    }
    boxForeignReturn($env, $desc);

    return true;
}

