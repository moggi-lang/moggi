<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet;

use function Moggi\Backend\DotNet\Il\ilString;

/**
 * Generate the `Moggi.Frames` type: a compile-time table that translates a
 * host stack frame — `(declaring type, method, IL offset)` — into the
 * pre-rendered `.mog` frame line the Mogg trace shows.
 *
 * .NET debug info is not available here (this toolchain's ilasm cannot emit a
 * PDB), so a stack trace gives us only the method and its IL offset. The
 * compiler records every sequence point's offset while emitting, and the
 * runtime resolves a reported offset to the nearest preceding sequence point.
 *
 * Rows are `"<type>#<method>#<offset>\n<frame text>"`. Encoding the offset as
 * text keeps the whole table a single `string[]`, which is the cheapest thing
 * to build in hand-written IL; the lookup parses it on demand.
 */
const FRAMES_CHUNK = 800;

/**
 * @param list<array<string, mixed>> $frames
 */
function buildFrames(array $frames): string
{
    // First-wins on duplicate keys, matching the runtime's expectations.
    $rows = [];
    foreach ($frames as $frame) {
        if (!\is_array($frame)) {
            continue;
        }
        $class = (string) ($frame['class'] ?? '');
        $method = (string) ($frame['method'] ?? '');
        $text = (string) ($frame['text'] ?? '');
        if ($class === '' || $method === '' || $text === '' || !isset($frame['offset'])) {
            continue;
        }
        $key = $class . '#' . $method . '#' . (int) $frame['offset'];
        if (!isset($rows[$key])) {
            $rows[$key] = $key . "\n" . $text;
        }
    }
    $entries = \array_values($rows);
    // Deterministic artifact; the lookup is a scan, so order is purely cosmetic.
    \sort($entries, SORT_STRING);

    $initCalls = '';
    $initMethods = '';
    $chunks = $entries === [] ? [] : \array_chunk($entries, FRAMES_CHUNK);
    foreach ($chunks as $i => $chunk) {
        $initCalls .= "    call void Moggi.Frames::Init{$i}()\n";
        $body = '';
        $index = 0;
        foreach ($chunk as $row) {
            $body .= "    ldsfld string[] Moggi.Frames::Rows\n";
            $body .= '    ldc.i4 ' . ($i * FRAMES_CHUNK + $index) . "\n";
            $body .= '    ldstr ' . ilString($row) . "\n";
            $body .= "    stelem.ref\n";
            ++$index;
        }
        $initMethods .= <<<IL
  .method private hidebysig static void Init{$i}() cil managed
  {
    .maxstack 3
{$body}    ret
  }

IL;
    }

    $count = \count($entries);

    return <<<IL
.class public abstract auto ansi sealed beforefieldinit Moggi.Frames
       extends [System.Runtime]System.Object
{
  .field public static string[] Rows

  .method private hidebysig specialname rtspecialname static void .cctor() cil managed
  {
    .maxstack 3
    ldc.i4 {$count}
    newarr [System.Runtime]System.String
    stsfld string[] Moggi.Frames::Rows
{$initCalls}    ret
  }

{$initMethods}  .method public hidebysig static string Lookup(string typeName, string methodName, int32 ilOffset) cil managed
  {
    .maxstack 8
    .locals init (string prefix, string[] rows, int32 i, string row, int32 nlPos, int32 ilOff, string best, int32 bestOff)

    ldarg.0
    ldstr "#"
    call string [System.Runtime]System.String::Concat(string, string)
    ldarg.1
    call string [System.Runtime]System.String::Concat(string, string)
    ldstr "#"
    call string [System.Runtime]System.String::Concat(string, string)
    stloc prefix

    ldsfld string[] Moggi.Frames::Rows
    stloc rows
    ldc.i4.m1
    stloc bestOff
    ldnull
    stloc best
    ldc.i4.0
    stloc i

    Loop:
    ldloc i
    ldloc rows
    ldlen
    conv.i4
    bge Done

    ldloc rows
    ldloc i
    ldelem.ref
    stloc row

    ldloc row
    ldloc prefix
    callvirt instance bool [System.Runtime]System.String::StartsWith(string)
    brfalse Next

    ldloc row
    ldc.i4.s 10
    callvirt instance int32 [System.Runtime]System.String::IndexOf(char)
    stloc nlPos

    ldloc row
    ldloc prefix
    callvirt instance int32 [System.Runtime]System.String::get_Length()
    ldloc nlPos
    ldloc prefix
    callvirt instance int32 [System.Runtime]System.String::get_Length()
    sub
    callvirt instance string [System.Runtime]System.String::Substring(int32, int32)
    call int32 [System.Runtime]System.Int32::Parse(string)
    stloc ilOff

    // Keep the greatest sequence point at or before the reported offset.
    ldloc ilOff
    ldarg.2
    bgt Next
    ldloc ilOff
    ldloc bestOff
    ble Next
    ldloc ilOff
    stloc bestOff
    ldloc row
    ldloc nlPos
    ldc.i4.1
    add
    callvirt instance string [System.Runtime]System.String::Substring(int32)
    stloc best

    Next:
    ldloc i
    ldc.i4.1
    add
    stloc i
    br Loop

    Done:
    ldloc best
    ret
  }
}
IL;
}
