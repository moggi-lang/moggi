<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;
use function Moggi\Backend\DotNet\Il\ilString;
use function Moggi\Backend\DotNet\Naming\symbolName;
use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\Debug\symbolId;

function pushBoxedInt(EmitEnv $env, int $v): void
{
    $env->m->emit("ldc.i8 {$v}");
    boxInt($env);
}

/**
 * Int/Char are represented as boxed int64, backed by the dedicated
 * RT.BoxInt/RT.UnboxInt trampolines (see runtime_abi.php's header comment);
 * every other scalar (Double, Bool, Integer) boxes/unboxes with raw CIL
 * `box`/`unbox.any` directly.
 */
function unboxInt(EmitEnv $env): void
{
    $env->m->emit('call int64 Moggi.Rt.RT::UnboxInt(object)');
}

function boxInt(EmitEnv $env): void
{
    $env->m->emit('call object Moggi.Rt.RT::BoxInt(int64)');
}

/** Sign-extend-truncate an i64 on the stack to the width named by an intN_* op. */
function emitSignExtend(EmitEnv $env, int $bits): void
{
    $env->m->emit($bits === 8 ? 'conv.i1' : ($bits === 16 ? 'conv.i2' : 'conv.i4'));
    $env->m->emit('conv.i8');
}

function unboxDouble(EmitEnv $env): void
{
    $env->m->emit('unbox.any float64');
}

function boxDouble(EmitEnv $env): void
{
    $env->m->emit('box float64');
}

function emitListLit(EmitEnv $env, IR\ListLit $op): void
{
    $acc = $env->freshLocal();
    $env->m->emit('ldnull');
    $env->m->store($acc);
    foreach (\array_reverse($op->elements) as $item) {
        emitOperand($env, $item);
        $env->m->load($acc);
        $env->m->emit('call class Moggi.Rt.MList Moggi.Rt.RT::Cons(object, object)');
        $env->m->store($acc);
    }
    $env->m->load($acc);
}

/** @param list<IR\Operand> $args */
function pushObjectArray(EmitEnv $env, array $args): void
{
    $env->m->emit('ldc.i4 ' . \count($args));
    $env->m->emit('newarr object');
    foreach ($args as $i => $arg) {
        $env->m->emit('dup');
        $env->m->emit("ldc.i4 {$i}");
        emitOperand($env, $arg);
        $env->m->emit('stelem.ref');
    }
}

function emitSrcLocValue(EmitEnv $env, ?IR\SrcLoc $loc): void
{
    if ($loc === null) {
        $env->m->emit('ldnull');

        return;
    }
    $env->m->emitLine($loc);
    if ($env->sourceMap !== null) {
        $site = $env->sourceMap->allocSite($loc, symbolName($loc->function));
        $env->m->emit('ldc.i4 ' . (int) $site['siteId']);
        $env->m->emit('ldstr ' . ilString($site['symbolId']));
        $env->m->emit('ldstr ' . ilString($site['displayPath']));
        $env->m->emit('ldc.i4 ' . (int) $site['line']);
        $env->m->emit('ldc.i4 ' . (int) $site['col']);
    } else {
        $symbolId = symbolId($loc->module, $loc->function);
        $display = normalizeDisplayPath($loc->file);
        $env->m->emit('ldc.i4 0');
        $env->m->emit('ldstr ' . ilString($symbolId));
        $env->m->emit('ldstr ' . ilString($display));
        $env->m->emit('ldc.i4 ' . $loc->line);
        $env->m->emit('ldc.i4 ' . $loc->col);
    }
    $env->m->emit('call object[] Moggi.Rt.RT::ThrowSite(int32, string, string, int32, int32)');
}

function emitIntrinsicValue(EmitEnv $env, IR\Intrinsic $op): bool
{
    $args = $op->args;
    switch ($op->name) {
        case 'stringCompare#':
        case 'bytesCompare#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass string');
            $env->m->emit('callvirt instance int32 [System.Runtime]System.String::CompareTo(string)');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');

            return true;
        case 'stringNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return true;
        case 'listCons#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.MList Moggi.Rt.RT::Cons(object, object)');

            return true;
        case 'listHead#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.MList');
            $env->m->emit('ldfld object Moggi.Rt.MList::head');

            return true;
        case 'listTail#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.MList');
            $env->m->emit('ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail');

            return true;
        case 'listAppend#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.MList');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.MList');
            $env->m->emit('call class Moggi.Rt.MList Moggi.Rt.RT::ListAppend(class Moggi.Rt.MList, class Moggi.Rt.MList)');

            return true;
        case 'listEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool Moggi.Rt.RT::ListEq(object, object)');
            $env->m->emit('box bool');

            return true;
        case 'listNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool Moggi.Rt.RT::ListEq(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return true;
        case 'listCompare#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::ListCompare(object, object)');

            return true;
        case 'maybeEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool Moggi.Rt.RT::MaybeEq(object, object)');
            $env->m->emit('box bool');

            return true;
        case 'maybeNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool Moggi.Rt.RT::MaybeEq(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return true;
        case 'maybeCompare#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::MaybeCompare(object, object)');

            return true;
        case 'stringAppend#':
        case 'bytesAppend#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass string');
            $env->m->emit('call string [System.Runtime]System.String::Concat(string, string)');

            return true;
        case 'stringCons#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('conv.u2');
            $env->m->emit('call string [System.Runtime]System.Char::ConvertFromUtf32(int32)');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass string');
            $env->m->emit('call string [System.Runtime]System.String::Concat(string, string)');

            return true;

        case 'platformArgv#':
            $env->m->emit('call class Moggi.Rt.MList Moggi.Rt.Platform::Argv()');

            return true;
        case 'stringEq#':
        case 'bytesEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            $env->m->emit('box bool');

            return true;
        case 'bytesNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return true;
    }

    return false;
}
