<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


/**
 * A round-trippable decimal string for a float64 literal. PHP's default float
 * to string conversion keeps only `precision` significant digits, which drops
 * the low bits (`3.141592653589793` became `3.1415926535898`); `%.17G` always
 * round-trips.
 */
function formatDoubleLiteral(float $value): string
{
    if (\is_nan($value)) {
        return 'NaN';
    }
    if (\is_infinite($value)) {
        return $value < 0 ? '-Infinity' : 'Infinity';
    }

    $text = \sprintf('%.17G', $value);
    if (! \str_contains($text, '.') && ! \str_contains($text, 'E') && ! \str_contains($text, 'e')) {
        $text .= '.0';
    }

    return $text;
}

function pushRawInt(EmitEnv $env, int $v): void
{
    if ($v >= -1 && $v <= 5) {
        $env->c->iconst($v);
    } elseif ($v >= -128 && $v <= 127) {
        $env->c->bipush($v);
    } elseif ($v >= -32768 && $v <= 32767) {
        $env->c->sipush($v);
    } else {
        $env->c->ldc($env->cp->integer($v));
    }
}

function pushRawLong(EmitEnv $env, int $v): void
{
    if ($v === 0 || $v === 1) {
        $env->c->lconst($v);
    } elseif ($v >= -32768 && $v <= 32767) {
        pushRawInt($env, $v);
        $env->c->i2l();
    } else {
        $env->c->ldc2_w($env->cp->long($v));
    }
}

