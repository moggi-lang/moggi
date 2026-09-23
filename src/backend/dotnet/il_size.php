<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Il;

/**
 * CIL instruction sizing for the .NET frame table.
 *
 * There is no PDB in this toolchain, so the debug info a host stack trace can
 * give us is the IL offset (`StackFrame.GetILOffset()`), which is populated
 * without symbols. To translate that offset back to a Mogg statement we need
 * the offset of every sequence point at compile time, which means knowing the
 * exact byte size of every instruction we emit.
 *
 * ilasm would otherwise pick encodings for us, so we canonicalize each emitted
 * line to an explicitly sized form first (shortest local/constant forms) and
 * normalize every branch to its long encoding. `ilasm` is then invoked without
 * `-OPTIMIZE` (which is what would rewrite long branches back to short), so the
 * sizes we compute are the sizes that land in the assembly.
 */

/** True when the trimmed line is an IL instruction (not a label/directive/brace). */
function ilIsInstruction(string $trimmed): bool
{
    if ($trimmed === '' || $trimmed[0] === '{' || $trimmed[0] === '}') {
        return false;
    }
    if (str_ends_with($trimmed, ':')) {
        return false;
    }
    if ($trimmed[0] === '.' || str_starts_with($trimmed, '//')) {
        return false;
    }
    if (str_starts_with($trimmed, 'catch ') || str_starts_with($trimmed, 'filter ')) {
        return false;
    }

    return true;
}

/**
 * Rewrite one emitted line to an explicitly sized encoding.
 *
 * Symbols are the backend's own: locals are `V<n>` (allocation order is
 * declaration order, so the id *is* the index) and arguments are `arg:<n>`.
 */
function ilCanonicalize(string $line): string
{
    $trimmed = ltrim($line);
    $indent = substr($line, 0, strlen($line) - strlen($trimmed));

    if (preg_match('/^ldc\.i4\s+(-?\d+)$/', $trimmed, $m) === 1) {
        return $indent . ilIntConstForm((int) $m[1]);
    }

    // Local/argument slots are symbolic (`V12`) or numeric (`arg:3`).
    if (preg_match('/^(ldloca|ldarga|ldloc|stloc)\s+(V\d+|arg:\d+)$/', $trimmed, $m) === 1) {
        return $indent . ilLocalForm($m[1], ilSlotIndex($m[2]));
    }

    // `ldarg <n>` is also written numerically by the tail-call back-edge.
    if (preg_match('/^(ldarga|ldarg|starg)\s+(arg:|)(\d+)$/', $trimmed, $m) === 1) {
        return $indent . ilLocalForm($m[1], (int) $m[3]);
    }

    // Normalize short branches to their long encoding: distance-independent size.
    if (preg_match('/^(br|brtrue|brfalse|beq|bge|bgt|ble|blt|bne\.un|bge\.un|bgt\.un|ble\.un|blt\.un|leave)\.s(\s+.*)$/', $trimmed, $m) === 1) {
        return $indent . $m[1] . $m[2];
    }

    return $line;
}

/** Local id (`V12`) or argument slot (`arg:3`) to its operand index. */
function ilSlotIndex(string $slot): int
{
    return str_starts_with($slot, 'arg:') ? (int) substr($slot, 4) : (int) substr($slot, 1);
}

function ilIntConstForm(int $n): string
{
    if ($n === -1) {
        return 'ldc.i4.m1';
    }
    if ($n >= 0 && $n <= 8) {
        return 'ldc.i4.' . $n;
    }
    if ($n >= -128 && $n <= 127) {
        return 'ldc.i4.s ' . $n;
    }

    return 'ldc.i4 ' . $n;
}

function ilLocalForm(string $op, int $idx): string
{
    // Only ldarg/ldloc/stloc have compact `.0`..`.3` encodings; the
    // address-of and store-argument forms are indexed only.
    if ($idx >= 0 && $idx <= 3 && isset(IL_COMPACT_LOCAL[$op])) {
        return $op . '.' . $idx;
    }
    if ($idx <= 255) {
        return $op . '.s ' . $idx;
    }

    return $op . ' ' . $idx;
}

/** Byte size of one canonicalized instruction line. */
function ilInstructionSize(string $trimmed): int
{
    $space = strpos($trimmed, ' ');
    $op = strtolower($space === false ? $trimmed : substr($trimmed, 0, $space));
    $arg = $space === false ? '' : trim(substr($trimmed, $space + 1));

    if ($op === 'switch') {
        $n = $arg === '' ? 0 : count(explode(',', $arg));

        return 4 + 4 * $n;
    }
    if (isset(IL_ONE_BYTE[$op]) || isset(IL_TWO_BYTE[$op])) {
        return isset(IL_ONE_BYTE[$op]) ? 1 : 2;
    }
    if (isset(IL_SHORT_LOCAL[$op])) {
        return 2;
    }
    if (isset(IL_LONG_LOCAL[$op])) {
        return 4;
    }
    if (isset(IL_BRANCH_SHORT[$op])) {
        return 2;
    }
    if (isset(IL_BRANCH_LONG[$op])) {
        return 5;
    }
    if (isset(IL_TOKEN[$op])) {
        return 5;
    }
    if (isset(IL_PREFIXED_TOKEN[$op])) {
        return 6;
    }
    if ($op === 'ldc.i4') {
        return 5;
    }
    if ($op === 'ldc.r4') {
        return 5;
    }
    if ($op === 'ldc.i8' || $op === 'ldc.r8') {
        return 9;
    }

    throw new \RuntimeException("DotNet IL size model: unknown instruction `{$op}`");
}

/** @var array<string, true> */
const IL_ONE_BYTE = [
    'nop' => true, 'break' => true,
    'ldarg.0' => true, 'ldarg.1' => true, 'ldarg.2' => true, 'ldarg.3' => true,
    'ldloc.0' => true, 'ldloc.1' => true, 'ldloc.2' => true, 'ldloc.3' => true,
    'stloc.0' => true, 'stloc.1' => true, 'stloc.2' => true, 'stloc.3' => true,
    'ldnull' => true, 'ldc.i4.m1' => true,
    'ldc.i4.0' => true, 'ldc.i4.1' => true, 'ldc.i4.2' => true, 'ldc.i4.3' => true,
    'ldc.i4.4' => true, 'ldc.i4.5' => true, 'ldc.i4.6' => true, 'ldc.i4.7' => true,
    'ldc.i4.8' => true,
    'dup' => true, 'pop' => true,
    'add' => true, 'sub' => true, 'mul' => true, 'div' => true, 'div.un' => true,
    'rem' => true, 'rem.un' => true, 'and' => true, 'or' => true, 'xor' => true,
    'shl' => true, 'shr' => true, 'shr.un' => true, 'neg' => true, 'not' => true,
    'ceq' => true, 'cgt' => true, 'cgt.un' => true, 'clt' => true, 'clt.un' => true,
    'conv.i1' => true, 'conv.i2' => true, 'conv.i4' => true, 'conv.i8' => true,
    'conv.r4' => true, 'conv.r8' => true, 'conv.u1' => true, 'conv.u2' => true,
    'conv.u4' => true, 'conv.u8' => true, 'conv.r.un' => true,
    'throw' => true, 'ret' => true, 'endfinally' => true, 'endfilter' => true,
    'ldlen' => true,
    'ldelem.i1' => true, 'ldelem.i2' => true, 'ldelem.i4' => true, 'ldelem.i8' => true,
    'ldelem.r4' => true, 'ldelem.r8' => true, 'ldelem.ref' => true,
    'ldelem.u1' => true, 'ldelem.u2' => true, 'ldelem.u4' => true,
    'stelem.i1' => true, 'stelem.i2' => true, 'stelem.i4' => true, 'stelem.i8' => true,
    'stelem.r4' => true, 'stelem.r8' => true, 'stelem.ref' => true,
    'ldind.i1' => true, 'ldind.i2' => true, 'ldind.i4' => true, 'ldind.i8' => true,
    'ldind.r4' => true, 'ldind.r8' => true, 'ldind.ref' => true,
    'ldind.u1' => true, 'ldind.u2' => true, 'ldind.u4' => true,
    'stind.i1' => true, 'stind.i2' => true, 'stind.i4' => true, 'stind.i8' => true,
    'stind.r4' => true, 'stind.r8' => true, 'stind.ref' => true,
    'ldtoken' => true, 'mkrefany' => true,
    'ckfinite' => true,
];

/** Operand-less opcodes with the `FE` prefix. */
const IL_TWO_BYTE = [
    'rethrow' => true, 'refanytype' => true, 'arglist' => true, 'localloc' => true,
];

/** Opcodes with compact `.0`..`.3` encodings. */
const IL_COMPACT_LOCAL = [
    'ldarg' => true, 'ldloc' => true, 'stloc' => true,
];

/** @var array<string, true> */
const IL_SHORT_LOCAL = [
    'ldarg.s' => true, 'ldarga.s' => true,
    'ldloc.s' => true, 'ldloca.s' => true,
    'starg.s' => true, 'stloc.s' => true,
    'ldc.i4.s' => true,
];

/** @var array<string, true> */
const IL_LONG_LOCAL = [
    'ldarg' => true, 'ldarga' => true,
    'ldloc' => true, 'ldloca' => true,
    'starg' => true, 'stloc' => true,
];

/** @var array<string, true> */
const IL_BRANCH_SHORT = [
    'br.s' => true, 'brtrue.s' => true, 'brfalse.s' => true,
    'beq.s' => true, 'bge.s' => true, 'bgt.s' => true, 'ble.s' => true, 'blt.s' => true,
    'bne.un.s' => true, 'bge.un.s' => true, 'bgt.un.s' => true,
    'ble.un.s' => true, 'blt.un.s' => true, 'leave.s' => true,
];

/** @var array<string, true> */
const IL_BRANCH_LONG = [
    'br' => true, 'brtrue' => true, 'brfalse' => true,
    'beq' => true, 'bge' => true, 'bgt' => true, 'ble' => true, 'blt' => true,
    'bne.un' => true, 'bge.un' => true, 'bgt.un' => true,
    'ble.un' => true, 'blt.un' => true, 'leave' => true,
];

/** One-byte opcode followed by a 4-byte metadata token. */
const IL_TOKEN = [
    'call' => true, 'callvirt' => true, 'calli' => true, 'newobj' => true,
    'ldfld' => true, 'ldflda' => true, 'stfld' => true,
    'ldsfld' => true, 'stsfld' => true,
    'ldstr' => true, 'newarr' => true, 'castclass' => true, 'isinst' => true,
    'box' => true, 'unbox' => true, 'unbox.any' => true,
    'ldelema' => true,
    'mkrefany' => true, 'refanyval' => true,
];

/** `FE xx` opcode followed by a 4-byte metadata token. */
const IL_PREFIXED_TOKEN = [
    'ldftn' => true, 'ldvirtftn' => true,
    'ldsflda' => true, 'initobj' => true, 'ldobj' => true, 'stobj' => true,
    'cpobj' => true, 'sizeof' => true,
];
