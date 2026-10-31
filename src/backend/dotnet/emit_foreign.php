<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;
use function Moggi\Backend\DotNet\Il\ilString;
use function Moggi\Backend\DotNet\Foreign\dotNetHostIsValueType;
use function Moggi\Backend\DotNet\Foreign\isDotNetConstProperty;
use function Moggi\Backend\DotNet\resolveDotnetExecutable;
use function Moggi\Backend\DotNet\Verify\dotNetConstLiteralValue;
use function Moggi\Backend\DotNet\Verify\ensureInspectorBinary;
use function Moggi\Backend\DotNet\Verify\inspectDotNetType;
use function Moggi\Backend\DotNet\Foreign\resolveDotNetForeignPath;
use function Moggi\Backend\DotNet\Naming\bclAssemblyFor;

function qualifyType(string $dotted): string
{
    if (\str_starts_with($dotted, 'Moggi.')) {
        return $dotted;
    }

    return '[' . bclAssemblyFor($dotted) . ']' . $dotted;
}

/** @return array{params: list<string>, ret: string} */
function parseClrSig(string $sig): array
{
    if ($sig === '' || $sig[0] !== '(') {
        return ['params' => [], 'ret' => $sig];
    }
    $close = \strpos($sig, ')');
    if ($close === false) {
        throw new \RuntimeException("malformed CLR signature: {$sig}");
    }
    $paramsStr = \substr($sig, 1, $close - 1);
    $ret = \substr($sig, $close + 1);
    $params = $paramsStr === '' ? [] : \explode(',', $paramsStr);

    return ['params' => $params, 'ret' => $ret];
}

function emitForeign(EmitEnv $env, IR\ForeignCall $op): void
{
    $resolved = resolveDotNetForeignPath($op->path);
    $desc = $op->nativeSig;
    if ($desc === null || $desc === '') {
        throw new \RuntimeException(
            "DotNet foreign `{$op->path}` missing inferred nativeSig (signature)",
        );
    }
    $typeRef = qualifyType($resolved['class']);

    if (($op->kind ?? 'function') === 'const') {
        if (isDotNetConstProperty($resolved['class'], $resolved['hostMember'])) {
            $env->m->emit("call {$desc} {$typeRef}::get_{$resolved['hostMember']}()");
        } else {
            $literal = dotNetConstLiteralValue($resolved['class'], $resolved['hostMember']);
            if ($literal !== null && emitConstLiteral($env, $literal, $desc)) {
                emitForeignIoWrap($env, $op->ioWrap);

                return;
            }
            $env->m->emit("ldsfld {$desc} {$typeRef}::{$resolved['hostMember']}");
        }
        boxForeignReturn($env, $desc);
        emitForeignIoWrap($env, $op->ioWrap);

        return;
    }

    $sig = parseClrSig($desc);
    $args = $op->args;

    if ($resolved['dispatch'] === 'intrinsic') {
        emitForeignIntrinsic($env, $resolved['hostMember'], $typeRef, $sig, $args, $op->path);

        return;
    }

    $argOffset = 0;
    $valueTypeReceiver = $resolved['dispatch'] === 'instance'
        && dotNetHostIsValueType($resolved['class']);

    if ($resolved['dispatch'] === 'instance') {
        if ($args === []) {
            throw new \RuntimeException("DotNet instance foreign `{$op->path}` needs a receiver");
        }
        emitOperand($env, $args[0]);
        if ($valueTypeReceiver) {
            $env->m->emit('unbox valuetype ' . $typeRef);
        } else {
            $env->m->emit("castclass {$typeRef}");
        }
        $argOffset = 1;
    }

    $paramArgs = \array_slice($args, $argOffset);
    $nProvided = \count($paramArgs);
    $nNeeded = \count($sig['params']);
    if ($nProvided > $nNeeded) {
        throw new \RuntimeException(
            "DotNet foreign `{$op->path}`: arg count {$nProvided}"
            . ' does not match signature ' . $desc,
        );
    }
    $paramsTail = false;
    if ($nNeeded > 0 && ($nProvided === $nNeeded || $nProvided === $nNeeded - 1)) {
        $tail = \end($sig['params']);
        if (\is_string($tail) && \str_ends_with($tail, '[]') && ! \str_ends_with($tail, '[...]')) {
            $paramsTail = provenParamsTail(
                $resolved['class'],
                $resolved['hostMember'],
                $sig['params'],
                $sig['ret'],
            );
        }
    }
    if ($paramsTail && $nProvided === $nNeeded) {
        $last = $nProvided - 1;
        foreach ($paramArgs as $i => $arg) {
            if ($i === $last) {
                emitPackVarargs($env, $arg, (string) \end($sig['params']));

                continue;
            }
            emitOperand($env, $arg);
            unboxForeignArg($env, $sig['params'][$i]);
        }
    } elseif ($paramsTail) {
        foreach ($paramArgs as $i => $arg) {
            emitOperand($env, $arg);
            unboxForeignArg($env, $sig['params'][$i]);
        }
        emitEmptyParamsArray($env, (string) \end($sig['params']), $op->path);
    } else {
        foreach ($paramArgs as $i => $arg) {
            emitOperand($env, $arg);
            unboxForeignArg($env, $sig['params'][$i]);
        }
        for ($i = $nProvided; $i < $nNeeded; $i++) {
            emitDefaultForeignArg($env, $sig['params'][$i]);
        }
    }

    $paramList = \implode(', ', $sig['params']);
    match ($resolved['dispatch']) {
        'static', 'global' => $env->m->emit("call {$sig['ret']} {$typeRef}::{$resolved['hostMember']}({$paramList})"),
        'instance' => $valueTypeReceiver
            ? $env->m->emit("call instance {$sig['ret']} {$typeRef}::{$resolved['hostMember']}({$paramList})")
            : $env->m->emit("callvirt instance {$sig['ret']} {$typeRef}::{$resolved['hostMember']}({$paramList})"),
        'constructor' => $env->m->emit("newobj instance void {$typeRef}::.ctor({$paramList})"),
        default => throw new \RuntimeException("DotNet foreign: unsupported dispatch {$resolved['dispatch']}"),
    };

    if ($resolved['dispatch'] === 'constructor') {
        if (dotNetHostIsValueType($resolved['class'])) {
            $env->m->emit('box valuetype ' . $typeRef);
        }
        emitForeignIoWrap($env, $op->ioWrap);

        return;
    }

    boxForeignReturn($env, $sig['ret']);
    emitForeignIoWrap($env, $op->ioWrap);
}

/**
 * Apply IO result wraps after a foreign call (null → Nothing, value → Just, …).
 */
function emitForeignIoWrap(EmitEnv $env, IR\IoWrap $wrap): void
{
    match ($wrap) {
        IR\IoWrap::MaybeString => emitForeignMaybeStringWrap($env),
        IR\IoWrap::Either => throw new \RuntimeException(
            'DotNet foreign IoWrap::Either is not implemented; use Moggi try/catch',
        ),
        IR\IoWrap::None => null,
    };
}

/** Host null → Nothing; otherwise Just. */
function emitForeignMaybeStringWrap(EmitEnv $env): void
{
    $id = $env->freshLabelId();
    $tmp = $env->freshLocal('object');
    $env->m->store($tmp);
    $env->m->emit('ldloc ' . $tmp);
    $just = 'foreign_maybe_just_' . $id;
    $done = 'foreign_maybe_done_' . $id;
    $env->m->emit('brtrue ' . $just);

    $env->m->emit('ldstr ' . ilString('Nothing'));
    $env->m->emit('ldc.i4.0');
    $env->m->emit('newarr object');
    $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::Con(string, object[])');
    $env->m->emit('br ' . $done);

    $env->m->label($just);
    $env->m->emit('ldstr ' . ilString('Just'));
    $env->m->emit('ldc.i4.1');
    $env->m->emit('newarr object');
    $env->m->emit('dup');
    $env->m->emit('ldc.i4.0');
    $env->m->emit('ldloc ' . $tmp);
    $env->m->emit('stelem.ref');
    $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::Con(string, object[])');
    $env->m->label($done);
}

/**
 * Whether the called overload proves its last parameter a `params` array.
 *
 * The inspector answer is cached per process, and the signature being
 * emitted was rebuilt verbatim from it at typecheck — so the lookup is an
 * exact string match, never a second inference. Never throws: emission must
 * not die on a metadata question the verifier already answered, and a `false`
 * simply keeps the legacy padding.
 *
 * @param list<string> $sigParams
 */
function provenParamsTail(
    string $classPath,
    string $member,
    array $sigParams,
    string $sigRet,
): bool {
    try {
        if ($classPath === '' || $member === '' || $sigParams === []) {
            return false;
        }
        $dotnet = resolveDotnetExecutable();
        if ($dotnet === null) {
            return false;
        }
        $inspector = ensureInspectorBinary($dotnet);
        if ($inspector === null) {
            return false;
        }
        $table = inspectDotNetType($dotnet, $inspector, $classPath);
        if ($table === null || ! ($table['found'] ?? false)) {
            return false;
        }
        foreach ($table['methods'][$member] ?? [] as $candidate) {
            if (! ($candidate['public'] ?? false)) {
                continue;
            }
            if (($candidate['params'] ?? null) !== $sigParams) {
                continue;
            }
            if ((string) ($candidate['ret'] ?? '') !== $sigRet) {
                continue;
            }

            return ($candidate['paramsArray'] ?? -1) === \count($sigParams) - 1;
        }

        return false;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Synthesize an empty array for one omitted `params` parameter.
 *
 * C# omission passes an empty array, not null — so unlike the sidecar's
 * zero/null padding (see {@see emitDefaultForeignArg}), the proven tail is
 * built with `newarr`.
 *
 * @throws \RuntimeException when the missing parameter is not a single-dimensional array
 *   (unreachable after typecheck resolution, which only pads proven tails)
 */
function emitEmptyParamsArray(EmitEnv $env, string $kind, string $path): void
{
    if (! \str_ends_with($kind, '[]') || \str_ends_with($kind, '[...]')) {
        throw new \RuntimeException(
            "DotNet foreign `{$path}`: cannot synthesize a default for parameter type {$kind}",
        );
    }
    $env->m->emit('ldc.i4.0');
    $env->m->emit('newarr ' . \substr($kind, 0, -2));
}

/**
 * Pack a trailing Moggi list into a `params` array (resolution ran at
 * typecheck, so the last signature parameter is that array).
 *
 * Two walks: count the cons cells, allocate with `newarr`, then fill with
 * `stelem`. Each head unboxes exactly the way a passed argument would (see
 * {@see unboxForeignArg}). An empty list is null, and `castclass` passes
 * null through — so `[]` packs to an empty array, not a null one.
 */
function emitPackVarargs(EmitEnv $env, IR\Operand $arg, string $arrayType): void
{
    $elem = \substr($arrayType, 0, -2);
    emitOperand($env, $arg);
    $env->m->emit('castclass Moggi.Rt.MList');
    $list = $env->freshLocal('class Moggi.Rt.MList');
    $env->m->store($list);

    $id = $env->freshLabelId();
    $count = $env->freshLocal('int32');
    $cursor = $env->freshLocal('class Moggi.Rt.MList');
    $env->m->emit('ldc.i4.0');
    $env->m->emit('stloc ' . $count);
    $env->m->emit('ldloc ' . $list);
    $env->m->emit('stloc ' . $cursor);
    $env->m->label('foreign_pack_count_' . $id);
    $env->m->emit('ldloc ' . $cursor);
    $env->m->emit('brfalse foreign_pack_alloc_' . $id);
    $env->m->emit('ldloc ' . $count);
    $env->m->emit('ldc.i4.1');
    $env->m->emit('add');
    $env->m->emit('stloc ' . $count);
    $env->m->emit('ldloc ' . $cursor);
    $env->m->emit('ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail');
    $env->m->emit('stloc ' . $cursor);
    $env->m->emit('br foreign_pack_count_' . $id);

    $env->m->label('foreign_pack_alloc_' . $id);
    $env->m->emit('ldloc ' . $count);
    $env->m->emit('newarr ' . $elem);
    $arr = $env->freshLocal($arrayType);
    $env->m->store($arr);

    $index = $env->freshLocal('int32');
    $env->m->emit('ldc.i4.0');
    $env->m->emit('stloc ' . $index);
    $env->m->emit('ldloc ' . $list);
    $env->m->emit('stloc ' . $cursor);
    $env->m->label('foreign_pack_fill_' . $id);
    $env->m->emit('ldloc ' . $cursor);
    $env->m->emit('brfalse foreign_pack_done_' . $id);
    $env->m->emit('ldloc ' . $arr);
    $env->m->emit('ldloc ' . $index);
    $env->m->emit('ldloc ' . $cursor);
    $env->m->emit('ldfld object Moggi.Rt.MList::head');
    unboxForeignArg($env, $elem);
    $env->m->emit(packStelem($elem));
    $env->m->emit('ldloc ' . $index);
    $env->m->emit('ldc.i4.1');
    $env->m->emit('add');
    $env->m->emit('stloc ' . $index);
    $env->m->emit('ldloc ' . $cursor);
    $env->m->emit('ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail');
    $env->m->emit('stloc ' . $cursor);
    $env->m->emit('br foreign_pack_fill_' . $id);

    $env->m->label('foreign_pack_done_' . $id);
    $env->m->emit('ldloc ' . $arr);
}

/** The `stelem` form that stores one unpacked value of an array component. */
function packStelem(string $elem): string
{
    return match ($elem) {
        'int8' => 'stelem.i1',
        'int16', 'char' => 'stelem.i2',
        'int32' => 'stelem.i4',
        'int64', 'uint64' => 'stelem.i8',
        'float32' => 'stelem.r4',
        'float64' => 'stelem.r8',
        default => 'stelem.ref',
    };
}

/**
 * Push a default argument for optional trailing parameters (zero / null).
 *
 * An omitted tail is filled with the type's zero, not the C# default value:
 * the sidecar carries the parameter list and the tail length, never the
 * defaults, so `f(x)` with an omitted `int` tail calls `f(x, 0)`. The shape
 * matches what a passed argument leaves on the stack (see
 * {@see unboxForeignArg}): a native int32 for every 32-bit-or-narrower
 * integer, `char` and `bool`, a native int64/float64 for those, a null
 * reference for `string`, and a zeroed value for a `valuetype`.
 */
function emitDefaultForeignArg(EmitEnv $env, string $kind): void
{
    if (\str_starts_with($kind, 'valuetype ')) {
        $slot = $env->freshLocal($kind);
        $env->m->emit('ldloca ' . $slot);
        $env->m->emit('initobj ' . $kind);
        $env->m->emit('ldloc ' . $slot);

        return;
    }
    if ($kind === 'object' || $kind === 'string' || \str_starts_with($kind, 'class ')) {
        $env->m->emit('ldnull');

        return;
    }
    if (\str_ends_with($kind, '[]') || \str_ends_with($kind, '[...]')) {
        $env->m->emit('ldnull');

        return;
    }
    if (\in_array($kind, ['int8', 'int16', 'int32', 'uint8', 'uint16', 'uint32', 'char', 'bool'], true)) {
        $env->m->emit('ldc.i4.0');

        return;
    }
    if (\in_array($kind, ['int64', 'uint64'], true)) {
        $env->m->emit('ldc.i8 0');

        return;
    }
    if ($kind === 'float32') {
        $env->m->emit('ldc.r4 0');

        return;
    }
    if ($kind === 'float64') {
        $env->m->emit('ldc.r8 0.0');

        return;
    }

    throw new \RuntimeException("DotNet foreign: cannot synthesize default for parameter type {$kind}");
}

function unboxForeignArg(EmitEnv $env, string $kind): void
{
    match ($kind) {
        'int32' => (static function (EmitEnv $env): void {
            unboxInt($env);
            $env->m->emit('conv.i4');
        })($env),
        'int8' => (static function (EmitEnv $env): void {
            unboxInt($env);
            $env->m->emit('conv.i1');
        })($env),
        'int16' => (static function (EmitEnv $env): void {
            unboxInt($env);
            $env->m->emit('conv.i2');
        })($env),
        'char' => (static function (EmitEnv $env): void {
            unboxInt($env);
            $env->m->emit('conv.u2');
        })($env),
        'int64' => unboxInt($env),
        'bool' => $env->m->emit('unbox.any bool'),
        'float64' => $env->m->emit('unbox.any float64'),
        'string' => $env->m->emit('castclass string'),
        'class Moggi.Rt.MList' => $env->m->emit('castclass Moggi.Rt.MList'),
        default => (static function (EmitEnv $env, string $kind): void {
            if (\str_starts_with($kind, 'valuetype ')) {
                $env->m->emit('unbox.any ' . $kind);
            } elseif (\str_starts_with($kind, 'class ')) {
                $env->m->emit('castclass ' . \substr($kind, \strlen('class ')));
            }
        })($env, $kind),
    };
}

function boxForeignReturn(EmitEnv $env, string $retOrFieldDesc): void
{
    match ($retOrFieldDesc) {
        'void' => $env->m->emit('ldnull'),
        'int32', 'int8', 'int16', 'char' => (static function (EmitEnv $env): void {
            $env->m->emit('conv.i8');
            boxInt($env);
        })($env),
        'int64' => boxInt($env),
        'bool' => $env->m->emit('box bool'),
        'float64' => $env->m->emit('box float64'),
        default => (static function (EmitEnv $env, string $desc): void {
            if (\str_starts_with($desc, 'valuetype ')) {
                $env->m->emit('box ' . $desc);
            }
        })($env, $retOrFieldDesc),
    };
}
// --- Intrinsics (full allowlist for platform_* and scalar ops) ----------------

/**
 * Emit-only foreign intrinsics: reference cast, valuetype default, typed null.
 *
 * @param array{params: list<string>, ret: string} $sig
 * @param list<IR\Operand> $args
 */
function emitForeignIntrinsic(
    EmitEnv $env,
    string $member,
    string $typeRef,
    array $sig,
    array $args,
    string $path,
): void {
    if ($member === '__cast') {
        if (\count($args) !== 1) {
            throw new \RuntimeException("DotNet foreign `{$path}`: __cast needs one argument");
        }
        emitOperand($env, $args[0]);
        $src = $sig['params'][0] ?? 'object';
        if (\str_starts_with($src, 'valuetype ')) {
        } elseif (\str_starts_with($src, 'class ') || $src === 'object') {
        }
        $ret = $sig['ret'];
        if ($ret === 'object') {
            return;
        }
        if (\str_starts_with($ret, 'class ')) {
            $env->m->emit('castclass ' . \substr($ret, \strlen('class ')));

            return;
        }
        if (\str_starts_with($ret, 'valuetype ')) {
            $env->m->emit('unbox.any ' . $ret);
            $env->m->emit('box ' . $ret);

            return;
        }

        throw new \RuntimeException("DotNet foreign `{$path}`: unsupported __cast target {$ret}");
    }

    if ($member === '__default') {
        $ret = $sig['ret'];
        if (! \str_starts_with($ret, 'valuetype ')) {
            throw new \RuntimeException("DotNet foreign `{$path}`: __default requires a valuetype result");
        }
        emitDefaultForeignArg($env, $ret);
        $env->m->emit('box ' . $ret);

        return;
    }

    if ($member === '__null') {
        $env->m->emit('ldnull');

        return;
    }

    throw new \RuntimeException("DotNet foreign: unknown intrinsic {$member}");
}

