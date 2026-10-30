<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use function Moggi\Backend\Php\Naming\phpFunctionName;
use function Moggi\Debug\normalizeDisplayPath;
use Moggi\Debug\SourceMapBuilder;
use function Moggi\Debug\symbolId;
use Moggi\IR;
use Moggi\IR\SrcLoc;

/**
 * A constructor used as a value.
 *
 * A nullary constructor *is* its tag value; a constructor of arity ≥ 1 is a
 * function, emitted as a closure building the tag form.
 */
function emitConstructorValue(string $tag, ?int $arity): string
{
    $name = \json_encode($tag, JSON_UNESCAPED_UNICODE);
    if ($arity === 0) {
        return "[{$name}]";
    }

    if ($arity === null) {
        return "fn(...\$__a) => [{$name}, ...\$__a]";
    }

    $params = [];
    for ($i = 0; $i < $arity; ++$i) {
        $params[] = '$__a' . $i;
    }

    return 'fn(' . \implode(', ', $params) . ") => [{$name}, " . \implode(', ', $params) . ']';
}

function emitSrcLocValue(?SrcLoc $loc, EmitEnv $env): string
{
    if ($loc === null) {
        return 'null';
    }

    $map = $env->sourceMap;
    if ($map instanceof SourceMapBuilder) {
        $generated = $loc->function;
        $phpNames = $env->phpNames;
        if ($generated !== '' && \is_array($phpNames) && isset($phpNames[$generated])) {
            $generated = phpFunctionName($loc->function, $phpNames);
            $ns = $env->moduleNamespace ?? null;
            if (\is_string($ns) && $ns !== '') {
                $generated = $ns . '\\' . $generated;
            }
        }
        $site = $map->allocSite($loc, $generated !== '' ? $generated : ($loc->module . '.expr'));

        return '\\Moggi\\throwSite('
            . (int) $site['siteId'] . ', '
            . \var_export($site['symbolId'], true) . ', '
            . \var_export($site['displayPath'], true) . ', '
            . (int) $site['line'] . ', '
            . (int) $site['col'] . ')';
    }

    $display = normalizeDisplayPath($loc->file);
    $symbolId = symbolId($loc->module, $loc->function);

    return '\\Moggi\\throwSite(0, '
        . \var_export($symbolId, true) . ', '
        . \var_export($display, true) . ', '
        . (int) $loc->line . ', '
        . (int) $loc->col . ')';
}

/** @param list<string> $argExprs */
function emitIntrinsicValue(EmitEnv $env, IR\Intrinsic $op, array $argExprs): ?string
{
    return match ($op->name) {
        'stringEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'stringNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'stringAppend#' => "({$argExprs[0]} . {$argExprs[1]})",
        'stringCons#' => "(\\IntlChar::chr({$argExprs[0]}) . {$argExprs[1]})",
        'stringCompare#' => stringCompareExpr($argExprs[0], $argExprs[1]),
        'bytesEq#' => "({$argExprs[0]} === {$argExprs[1]})",
        'bytesNe#' => "({$argExprs[0]} !== {$argExprs[1]})",
        'bytesAppend#' => "({$argExprs[0]} . {$argExprs[1]})",
        'bytesCompare#' => stringCompareExpr($argExprs[0], $argExprs[1]),
        'bytesFromString#' => $argExprs[0],
        'bytesToString#' => $argExprs[0],
        'listCons#' => '[' . $argExprs[0] . ', ...' . $argExprs[1] . ']',
        'listHead#' => $argExprs[0] . '[0]',
        'listTail#' => '\array_slice(' . $argExprs[0] . ', 1)',
        'listAppend#' => '\array_merge(' . $argExprs[0] . ', ' . $argExprs[1] . ')',
        'listEq#' => '(' . $argExprs[0] . ' === ' . $argExprs[1] . ')',
        'listNe#' => '(' . $argExprs[0] . ' !== ' . $argExprs[1] . ')',
        'listCompare#' => listCompareExpr($argExprs[0], $argExprs[1]),
        'maybeEq#' => '(' . $argExprs[0] . ' === ' . $argExprs[1] . ')',
        'maybeNe#' => '(' . $argExprs[0] . ' !== ' . $argExprs[1] . ')',
        'maybeCompare#' => maybeCompareExpr($argExprs[0], $argExprs[1]),
        'platformArgv#' => '(function () { global $argv; $a = $argv ?? $GLOBALS[\'argv\'] ?? []; return \\array_values(\\array_slice($a, 1)); })()',
        default => null,
    };
}
