<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Foreign;

use Moggi\IR;

use function Moggi\Backend\Php\Naming\phpTemp;
use function Moggi\Foreign\parseForeignPath;
use function Moggi\Foreign\phpClassFqn;

/** @param list<string> $argExprs */
function emitForeignExpression(IR\ForeignCall $foreign, array $argExprs): string
{
    $call = emitForeignExpressionRaw($foreign, $argExprs);
    if (($foreign->phpValueBox ?? false) === true) {
        return emitPhpValueBox($call);
    }

    return $call;
}

/** @param list<string> $argExprs */
function emitForeignExpressionRaw(IR\ForeignCall $foreign, array $argExprs): string
{
    if (($foreign->kind ?? '') === 'const') {
        return emitForeignConst($foreign);
    }

    $argExprs = emitForeignArgExprs($foreign, $argExprs);
    $info = foreignPathInfo($foreign);

    $call = match ($info['dispatch']) {
        'global' => '\\' . $foreign->path . '(' . join(', ', $argExprs) . ')',
        'static' => '\\' . phpClassFqn((string) $info['classPath']) . '::' . $info['member']
            . '(' . join(', ', $argExprs) . ')',
        'constructor' => 'new \\' . phpClassFqn((string) $info['classPath'])
            . '(' . join(', ', $argExprs) . ')',
        'instance' => emitInstanceCall($info['member'], $argExprs),
        default => throw new \RuntimeException("unsupported foreign dispatch `{$info['dispatch']}`"),
    };

    // A host diagnostic is not a Moggi value: the caller reads the failure from
    // the call's result (including error_get_last), and reports it itself.
    return '@' . $call;
}

function emitForeignConst(IR\ForeignCall $foreign): string
{
    $info = foreignPathInfo($foreign);
    if (($info['dispatch'] ?? '') !== 'static') {
        throw new \RuntimeException('foreign const requires static path');
    }

    if (($info['classPath'] ?? '') === 'PHP') {
        $expr = (string) $info['member'];

        return ($foreign->handleBox ?? false) === true ? emitHandleBox($expr) : $expr;
    }

    return '\\' . phpClassFqn((string) $info['classPath']) . '::' . $info['member'];
}

/** @return array{classPath: ?string, member: ?string, dispatch: string, path?: string} */
function foreignPathInfo(IR\ForeignCall $foreign): array
{
    if (isset($foreign->dispatch)) {
        return [
            'classPath' => $foreign->classPath ?? null,
            'member' => $foreign->member ?? null,
            'dispatch' => $foreign->dispatch,
        ];
    }

    return parseForeignPath($foreign->path);
}

/** @param list<string> $argExprs */
function emitInstanceCall(string $member, array $argExprs): string
{
    if ($argExprs === []) {
        throw new \RuntimeException('instance foreign call requires handle argument');
    }

    $handle = array_shift($argExprs);

    return $handle . '->' . $member . '(' . join(', ', $argExprs) . ')';
}

/** @param list<string> $argExprs */
function emitForeignIoStatement(
    IR\ForeignCall $foreign,
    array $argExprs,
    ?int $dest,
    string $pad,
    string $ioWrap,
): string {
    // Pure expression emit boxes PHPValue; IO path boxes once in emitForeignIoResult.
    $call = emitForeignExpressionRaw($foreign, $argExprs);
    $destVar = $dest === null ? null : phpTemp($dest);
    $assignPrefix = $destVar === null ? '' : $destVar . ' = ';
    $phpValueBox = ($foreign->phpValueBox ?? false) === true;
    $handleBox = ($foreign->handleBox ?? false) === true;

    return match ($ioWrap) {
        'either' => emitForeignIoEither($call, $assignPrefix, $destVar, $pad, $phpValueBox, $handleBox),
        'fgets_line' => emitForeignIoFgetsLine($call, $assignPrefix, $destVar, $pad),
        'maybe_string' => emitForeignIoMaybeString($call, $assignPrefix, $destVar, $pad),
        default => $pad . $assignPrefix . emitForeignIoResult($call, $phpValueBox, $handleBox) . ";\n",
    };
}

function emitForeignIoMaybeString(string $call, string $assignPrefix, ?string $destVar, string $pad): string
{
    if ($destVar === null) {
        throw new \RuntimeException('foreign IO Maybe String call requires destination temp');
    }

    $out = $pad . '$__moggiLine = ' . $call . ";\n";
    $out .= $pad . $assignPrefix . "match (true) {\n";
    $out .= $pad . "    \$__moggiLine === false => ['Nothing'],\n";
    $out .= $pad . "    default => ['Just', \$__moggiLine],\n";
    $out .= $pad . "};\n";

    return $out;
}

function emitForeignIoFgetsLine(string $call, string $assignPrefix, ?string $destVar, string $pad): string
{
    if ($destVar === null) {
        throw new \RuntimeException('foreign IO Maybe String call requires destination temp');
    }

    $out = $pad . '$__moggiLine = ' . $call . ";\n";
    $out .= $pad . $assignPrefix . "match (true) {\n";
    $out .= $pad . "    \$__moggiLine === false => ['Nothing'],\n";
    $out .= $pad . "    \$__moggiLine === '' => ['Nothing'],\n";
    $out .= $pad . "    default => ['Just', rtrim(\$__moggiLine, \"\\n\")],\n";
    $out .= $pad . "};\n";

    return $out;
}

function emitForeignIoResult(string $call, bool $phpValueBox, bool $handleBox): string
{
    if ($phpValueBox) {
        return emitPhpValueBox($call);
    }

    if ($handleBox) {
        return emitHandleBox($call);
    }

    return $call;
}

function emitHandleBox(string $expr): string
{
    // Evaluate once — fopen (and similar) must not run twice. A `false` return is
    // a raw host failure; `System.IO` classifies it (the emitter knows no errno).
    return '(static fn ($__moggiHandle) => is_resource($__moggiHandle)'
        . " ? ['MkHandle', ['MkResource', \$__moggiHandle]]"
        . " : throw new \\RuntimeException('fopen failed'))({$expr})";
}

function emitPhpValueBox(string $expr): string
{
    return '\\Moggi\\phpValueBox(' . $expr . ')';
}

function emitForeignIoEither(
    string $call,
    string $assignPrefix,
    ?string $destVar,
    string $pad,
    bool $phpValueBox,
    bool $handleBox,
): string {
    if ($destVar === null) {
        throw new \RuntimeException('foreign IO Either call requires destination temp');
    }

    $rightValue = match (true) {
        $phpValueBox => emitPhpValueBox('$__moggiResult'),
        $handleBox => emitHandleBox('$__moggiResult'),
        default => '$__moggiResult',
    };

    $out = $pad . "try {\n";
    $out .= $pad . '    $__moggiResult = ' . $call . ";\n";
    $out .= $pad . '    ' . $assignPrefix . "['Right', {$rightValue}];\n";
    $out .= $pad . "} catch (\\Throwable \$__moggiEx) {\n";
    $out .= $pad . '    ' . $destVar . " = ['Left', get_class(\$__moggiEx) . ': ' . \$__moggiEx->getMessage()];\n";
    $out .= $pad . "}\n";

    return $out;
}

/** @param list<string> $argExprs @return list<string> */
function emitForeignArgExprs(IR\ForeignCall $foreign, array $argExprs): array
{
    $unbox = $foreign->handleUnboxArgs ?? [];
    if ($unbox === []) {
        return $argExprs;
    }

    $unboxSet = \array_fill_keys($unbox, true);
    $processed = [];
    foreach ($argExprs as $i => $expr) {
        $processed[] = isset($unboxSet[$i])
            ? "(({$expr}[1] ?? null)[1] ?? null)"
            : $expr;
    }

    return $processed;
}
