<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use Moggi\IR;
use function Moggi\Backend\Php\Naming\phpTemp;
use function Moggi\Foreign\phpClassFqn;
use function Moggi\Backend\Php\Foreign\foreignPathInfo;

/** One foreign call as a php expression. @param list<string> $argExprs */
function emitForeign(IR\ForeignCall $foreign, array $argExprs): string
{
    $call = emitForeignCall($foreign, $argExprs);

    return ($foreign->phpValueBox ?? false) === true ? emitPhpValueBox($call) : $call;
}

/** @param list<string> $argExprs */
function emitForeignCall(IR\ForeignCall $foreign, array $argExprs): string
{
    if (($foreign->kind ?? '') === 'const') {
        return emitForeignConst($foreign);
    }

    $argExprs = emitForeignArgExprs($foreign, $argExprs);
    $info = foreignPathInfo($foreign);

    $call = match ($info['dispatch']) {
        'global' => '\\' . $foreign->path . '(' . \implode(', ', $argExprs) . ')',
        'static' => '\\' . phpClassFqn((string) $info['classPath']) . '::' . $info['hostMember']
            . '(' . \implode(', ', $argExprs) . ')',
        'constructor' => 'new \\' . phpClassFqn((string) $info['classPath'])
            . '(' . \implode(', ', $argExprs) . ')',
        'instance' => emitInstanceCall($info['hostMember'], $argExprs),
        default => throw new \RuntimeException("unsupported foreign dispatch `{$info['dispatch']}`"),
    };

    return '@' . $call;
}

function emitForeignConst(IR\ForeignCall $foreign): string
{
    $info = foreignPathInfo($foreign);
    if (($info['dispatch'] ?? '') !== 'static') {
        throw new \RuntimeException('foreign const requires static path');
    }

    if (($info['classPath'] ?? '') === 'PHP') {
        $expr = (string) $info['hostMember'];

        return ($foreign->handleBox ?? false) === true ? emitHandleBox($expr) : $expr;
    }

    return '\\' . phpClassFqn((string) $info['classPath']) . '::' . $info['hostMember'];
}

/** @param list<string> $argExprs */
function emitInstanceCall(string $member, array $argExprs): string
{
    if ($argExprs === []) {
        throw new \RuntimeException('instance foreign call requires handle argument');
    }

    $handle = \array_shift($argExprs);

    return $handle . '->' . $member . '(' . \implode(', ', $argExprs) . ')';
}

/** @param list<string> $argExprs */
function emitForeignIoStatement(
    IR\ForeignCall $foreign,
    array $argExprs,
    ?int $dest,
    string $pad,
    IR\IoWrap $ioWrap,
): string {
    $destVar = $dest === null ? null : phpTemp($dest);

    return emitForeignIoWrap(emitForeignCall($foreign, $argExprs), $destVar, $pad, $ioWrap, $foreign);
}

function emitForeignIoWrap(string $call, ?string $destVar, string $pad, IR\IoWrap $wrap, IR\ForeignCall $foreign): string
{
    $assignPrefix = $destVar === null ? '' : $destVar . ' = ';

    if ($wrap === IR\IoWrap::None) {
        return $pad . $assignPrefix . emitForeignValueBox($call, $foreign) . ";\n";
    }

    if ($wrap === IR\IoWrap::MaybeString) {
        if ($destVar === null) {
            throw new \RuntimeException('foreign IO Maybe String call requires destination temp');
        }

        $out = $pad . '$__moggiLine = ' . $call . ";\n";
        $out .= $pad . $assignPrefix . "match (true) {\n";
        $out .= $pad . "    \$__moggiLine === false => ['Nothing'],\n";
        $out .= $pad . "    \$__moggiLine === null => ['Nothing'],\n";
        $out .= $pad . "    default => ['Just', \$__moggiLine],\n";
        $out .= $pad . "};\n";

        return $out;
    }

    if ($destVar === null) {
        throw new \RuntimeException('foreign IO Either call requires destination temp');
    }

    $rightValue = emitForeignValueBox('$__moggiResult', $foreign);
    $out = $pad . "try {\n";
    $out .= $pad . '    $__moggiResult = ' . $call . ";\n";
    $out .= $pad . '    ' . $assignPrefix . "['Right', {$rightValue}];\n";
    $out .= $pad . "} catch (\\Throwable \$__moggiEx) {\n";
    $out .= $pad . '    ' . $destVar . " = ['Left', get_class(\$__moggiEx) . ': ' . \$__moggiEx->getMessage()];\n";
    $out .= $pad . "}\n";

    return $out;
}

function emitForeignValueBox(string $call, IR\ForeignCall $foreign): string
{
    if (($foreign->phpValueBox ?? false) === true) {
        return emitPhpValueBox($call);
    }

    if (($foreign->handleBox ?? false) === true) {
        return emitHandleBox($call);
    }

    return $call;
}

function emitHandleBox(string $expr): string
{
    return '(static fn ($__moggiHandle) => is_resource($__moggiHandle)'
        . " ? ['MkHandle', ['MkResource', \$__moggiHandle]]"
        . " : throw new \\RuntimeException('fopen failed'))({$expr})";
}

function emitPhpValueBox(string $expr): string
{
    return '\\Moggi\\phpValueBox(' . $expr . ')';
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
