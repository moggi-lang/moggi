<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


/**
 * A host int literal. PHP reads `-9223372036854775808` as a float (the minus
 * applies to a positive literal that does not fit), so `minBound` is spelled
 * out.
 */
function emitIntLiteral(int $value): string
{
    return $value === \PHP_INT_MIN ? '\\PHP_INT_MIN' : (string) $value;
}

function formatDoubleLiteral(float $value): string
{
    if (\is_nan($value)) {
        return 'NAN';
    }

    if (\is_infinite($value)) {
        return $value < 0 ? '-INF' : 'INF';
    }

    $text = \sprintf('%.17G', $value);

    return \str_contains($text, '.') || \str_contains($text, 'E') || \str_contains($text, 'e')
        ? $text
        : $text . '.0';
}

function exportStringLiteral(string $value): string
{
    return \json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

