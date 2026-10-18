<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet;

/**
 * .NET/CLR language runtime ABI for Moggi — `Moggi.Rt.*` types and helpers
 * emitted as ILASM (ECMA-335 text), assembled by Microsoft.NET.Sdk.IL.
 *
 * ── Representations (Moggi.Rt contract) ───────────────────────────────
 *
 * Closure (Fn)
 *   Interface Moggi.Rt.Fn { object Invoke(object[] args); }
 *   Top-level functions are static methods; as values they are compiler-owned
 *   TopLevelFn wrappers around those static methods (via Fn.Invoke trampoline).
 *
 * Partial application
 *   Class Moggi.Rt.Partial { int32 arity; Fn target; object[] args; }
 *   RT.Apply(callee, args) saturates or extends Partial.
 *
 * ADT
 *   Class Moggi.Rt.Con { string tag; object[] fields; }
 *   Constructors emit static factory methods returning Con.
 *
 * List
 *   null                    = Nil
 *   Moggi.Rt.MList(head,t)  = Cons  (tail is MList|null)
 *
 * Dictionary (typeclass evidence)
 *   Class Moggi.Rt.Dict { object[] methods; }
 *   Resolved evidence is a static method returning Dict.
 *
 * IO
 *   Class Moggi.Rt.IO { object action; }
 *   RT.IoRun(IO) executes; Main entry runs IO mains via IoRun.
 *   RT.IoCatch / IoFinally / ThrowSomeException back IoCatch/IoFinally/IoThrow.
 *
 * Exceptions (SomeException#)
 *   object[] { "__se", stableTag, payload } — mirrors PHP ['__se', tag, payload]
 *   Moggi.Rt.MoggiException extends System.Exception with someException / throwSite
 *   throwSite object[] = {int siteId, string symbolId, string displayPath, int line, int col}
 *   RT.FormatExceptionReport / ReportUncaught mirror PHP stderr reports
 *   (throwSite only — no runtime call-frame stacking).
 *   Payload for ADTs is Moggi.Rt.Con (same as ordinary algebraic data).
 *
 * Scalars / Unit
 *   Int/Char → boxed int64 (signed 64-bit).
 *   Integer# → System.Numerics.BigInteger (arbitrary precision)
 *   Double   → boxed float64
 *   Bool     → boxed bool
 *   String   → string
 *   Unit     → null
 *
 * Main entry (CLR bridge on the entry module type)
 *   Moggi programs expose exactly: main :: IO ()
 *   public static void Main(string[] args)
 *   - calls Moggi main() (must be IO)
 *   - RT.IoRun(io); discard Unit
 *
 * Platform helpers back platform_* intrinsics (not foreign-importable).
 * Scalar arithmetic is IR intrinsics → CIL opcodes directly
 * (box/unbox via `box`/`unbox.any`, not RT trampolines, except BoxInt/UnboxInt
 * which back the Int/Char boxed-int64 representation explicitly).
 *
 * .NET target policy:
 * - Target / minimum runtime: net8.0 (framework-dependent); Native AOT optional
 * - Emitted as ILASM text, assembled by Microsoft.NET.Sdk.IL — never raw PE,
 *   never C#/F# source.
 * - This file returns **type definitions only** (`.class ...` blocks). It
 *   does not emit `.assembly`, `.assembly extern`, or `.module` — those are
 *   the wrapper/app file's job (see package.php), since Sdk.IL assembles all
 *   `.il` files of a project together as one compilation unit.
 */

require_once __DIR__ . '/il.php';

/** @return string ILASM text: `.class` definitions for the Moggi.Rt namespace only. */
function languageRuntime(): string
{
    static $cached = null;
    if (\is_string($cached)) {
        return $cached;
    }

    $cached = \implode("\n", [
        fnInterfaceIl(),
        partialClassIl(),
        mListClassIl(),
        conClassIl(),
        ioClassIl(),
        stClassIl(),
        stRefClassIl(),
        stBindFnClassIl(),
        stNewRefFnClassIl(),
        stReadRefFnClassIl(),
        stWriteRefFnClassIl(),
        arrHoleClassIl(),
        arrArrayClassIl(),
        arrSTArrayClassIl(),
        arrIOArrayClassIl(),
        newSTArrayFnClassIl(),
        readSTArrayFnClassIl(),
        writeSTArrayFnClassIl(),
        freezeSTArrayFnClassIl(),
        thawArrayFnClassIl(),
        dictClassIl(),
        moggiExceptionClassIl(),
        constFnClassIl(),
        recClassIl(),
        topLevelFnClassIl(),
        rtClassIl(),
        word64ClassIl(),
        platformRuntime(),
    ]);

    return $cached;
}

function fnInterfaceIl(): string
{
    return <<<'IL'
.class interface public abstract auto ansi Moggi.Rt.Fn
{
  .method public hidebysig newslot abstract virtual
          instance object Invoke(object[] args) cil managed
  {
  }
}

IL;
}

function partialClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.Partial
       extends [System.Runtime]System.Object
{
  .field public int32 arity
  .field public class Moggi.Rt.Fn target
  .field public object[] args

  .method public hidebysig specialname rtspecialname
          instance void .ctor(int32 arity, class Moggi.Rt.Fn target, object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld int32 Moggi.Rt.Partial::arity
    ldarg.0
    ldarg.2
    stfld class Moggi.Rt.Fn Moggi.Rt.Partial::target
    ldarg.0
    ldarg.3
    stfld object[] Moggi.Rt.Partial::args
    ret
  }
}

IL;
}

function mListClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.MList
       extends [System.Runtime]System.Object
{
  .field public object head
  .field public class Moggi.Rt.MList tail

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object head, class Moggi.Rt.MList tail) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld object Moggi.Rt.MList::head
    ldarg.0
    ldarg.2
    stfld class Moggi.Rt.MList Moggi.Rt.MList::tail
    ret
  }

  .method public hidebysig virtual instance bool Equals(object obj) cil managed
  {
    .maxstack 8
    ldarg.1
    isinst Moggi.Rt.MList
    brfalse NotList

    ldarg.0
    ldarg.1
    call bool Moggi.Rt.RT::ListEq(object, object)
    ret

    NotList:
    ldc.i4.0
    ret
  }
}

IL;
}

function conClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.Con
       extends [System.Runtime]System.Object
{
  .field public string tag
  .field public object[] fields

  .method public hidebysig specialname rtspecialname
          instance void .ctor(string tag, object[] fields) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld string Moggi.Rt.Con::tag
    ldarg.0
    ldarg.2
    stfld object[] Moggi.Rt.Con::fields
    ret
  }

  .method public hidebysig virtual instance bool Equals(object obj) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    call bool Moggi.Rt.RT::ValueEq(object, object)
    ret
  }
}

IL;
}

function ioClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.IO
       extends [System.Runtime]System.Object
{
  .field public object action

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object action) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld object Moggi.Rt.IO::action
    ret
  }
}

IL;
}

/**
 * An ST action: the same thunk-wrapper shape as `Moggi.Rt.IO` under a
 * distinct class, so the two media never mix. Building one is pure; each
 * run (`RT.StRun`) performs allocation and cell effects in bind order.
 */
function stClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.ST
       extends [System.Runtime]System.Object
{
  .field public object action

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object action) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld object Moggi.Rt.ST::action
    ret
  }
}

IL;
}

/** A mutable cell in one state thread. The field is deliberately mutable. */
function stRefClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.STRef
       extends [System.Runtime]System.Object
{
  .field public object v

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object v) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld object Moggi.Rt.STRef::v
    ret
  }
}

IL;
}

/** Thunk for `stBind#`: run `m`, feed the value through `k`, run the result. */
function stBindFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.StBindFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public class Moggi.Rt.ST m
  .field public object k

  .method public hidebysig specialname rtspecialname
          instance void .ctor(class Moggi.Rt.ST m, object k) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld class Moggi.Rt.ST Moggi.Rt.StBindFn::m
    ldarg.0
    ldarg.2
    stfld object Moggi.Rt.StBindFn::k
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld object Moggi.Rt.StBindFn::k
    ldarg.0
    ldfld class Moggi.Rt.ST Moggi.Rt.StBindFn::m
    call object Moggi.Rt.RT::StRun(class Moggi.Rt.ST)
    call object Moggi.Rt.RT::Apply1(object, object)
    castclass Moggi.Rt.ST
    call object Moggi.Rt.RT::StRun(class Moggi.Rt.ST)
    ret
  }
}

IL;
}

/** Thunk for `newSTRef#`: allocate the cell when run. */
function stNewRefFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.NewRefFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public object x

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object x) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld object Moggi.Rt.NewRefFn::x
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld object Moggi.Rt.NewRefFn::x
    newobj instance void Moggi.Rt.STRef::.ctor(object)
    ret
  }
}

IL;
}

/** Thunk for `readSTRef#`: read the cell when run. */
function stReadRefFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.ReadRefFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public class Moggi.Rt.STRef r

  .method public hidebysig specialname rtspecialname
          instance void .ctor(class Moggi.Rt.STRef r) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld class Moggi.Rt.STRef Moggi.Rt.ReadRefFn::r
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld class Moggi.Rt.STRef Moggi.Rt.ReadRefFn::r
    ldfld object Moggi.Rt.STRef::v
    ret
  }
}

IL;
}

/** Thunk for `writeSTRef#`: replace the cell value when run, answering null (Unit). */
function stWriteRefFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.WriteRefFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public class Moggi.Rt.STRef r
  .field public object x

  .method public hidebysig specialname rtspecialname
          instance void .ctor(class Moggi.Rt.STRef r, object x) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld class Moggi.Rt.STRef Moggi.Rt.WriteRefFn::r
    ldarg.0
    ldarg.2
    stfld object Moggi.Rt.WriteRefFn::x
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld class Moggi.Rt.STRef Moggi.Rt.WriteRefFn::r
    ldarg.0
    ldfld object Moggi.Rt.WriteRefFn::x
    stfld object Moggi.Rt.STRef::v
    ldnull
    ret
  }
}

IL;
}

/**
 * Marker for an array slot no value was ever written to. Fresh instances
 * fill new arrays and the test is `isinst`, so no singleton is needed; a
 * read that finds one raises instead of leaking the marker.
 */
function arrHoleClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.ArrHole
       extends [System.Runtime]System.Object
{
  .method public hidebysig specialname rtspecialname
          instance void .ctor() cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ret
  }
}

IL;
}

/**
 * One of the three array holders (immutable `ArrArray`, mutable `ArrSTArray`
 * and `ArrIOArray` share the shape: opaque bounds, element count, slots).
 */
function arrHolderClassIl(string $class): string
{
    return '.class public auto ansi beforefieldinit ' . $class . "\n"
        . "       extends [System.Runtime]System.Object\n"
        . "{\n"
        . "  .field public object bounds\n"
        . "  .field public int32 n\n"
        . "  .field public object[] elems\n"
        . "\n"
        . "  .method public hidebysig specialname rtspecialname\n"
        . "          instance void .ctor(object b, int32 n, object[] e) cil managed\n"
        . "  {\n"
        . "    .maxstack 8\n"
        . "    ldarg.0\n"
        . "    call instance void [System.Runtime]System.Object::.ctor()\n"
        . "    ldarg.0\n"
        . "    ldarg.1\n"
        . '    stfld object ' . $class . "::bounds\n"
        . "    ldarg.0\n"
        . "    ldarg.2\n"
        . '    stfld int32 ' . $class . "::n\n"
        . "    ldarg.0\n"
        . "    ldarg.3\n"
        . '    stfld object[] ' . $class . "::elems\n"
        . "    ret\n"
        . "  }\n"
        . "}\n\n";
}

function arrArrayClassIl(): string
{
    return arrHolderClassIl('Moggi.Rt.ArrArray');
}

function arrSTArrayClassIl(): string
{
    return arrHolderClassIl('Moggi.Rt.ArrSTArray');
}

function arrIOArrayClassIl(): string
{
    return arrHolderClassIl('Moggi.Rt.ArrIOArray');
}

/** Thunk for `newSTArray#`: allocate the mutable array when run. */
function newSTArrayFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.NewSTArrayFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public object bounds
  .field public object n

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object b, object n) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld object Moggi.Rt.NewSTArrayFn::bounds
    ldarg.0
    ldarg.2
    stfld object Moggi.Rt.NewSTArrayFn::n
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld object Moggi.Rt.NewSTArrayFn::bounds
    ldarg.0
    ldfld object Moggi.Rt.NewSTArrayFn::n
    call class Moggi.Rt.ArrSTArray Moggi.Rt.RT::StNewDirect(object, object)
    ret
  }
}

IL;
}

/** Thunk for `readSTArray#`: read the slot when run. */
function readSTArrayFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.ReadSTArrayFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public class Moggi.Rt.ArrSTArray arr
  .field public object idx

  .method public hidebysig specialname rtspecialname
          instance void .ctor(class Moggi.Rt.ArrSTArray arr, object idx) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld class Moggi.Rt.ArrSTArray Moggi.Rt.ReadSTArrayFn::arr
    ldarg.0
    ldarg.2
    stfld object Moggi.Rt.ReadSTArrayFn::idx
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld class Moggi.Rt.ArrSTArray Moggi.Rt.ReadSTArrayFn::arr
    ldarg.0
    ldfld object Moggi.Rt.ReadSTArrayFn::idx
    call object Moggi.Rt.RT::StReadDirect(class Moggi.Rt.ArrSTArray, object)
    ret
  }
}

IL;
}

/** Thunk for `writeSTArray#`: replace the slot when run, answering null (Unit). */
function writeSTArrayFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.WriteSTArrayFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public class Moggi.Rt.ArrSTArray arr
  .field public object idx
  .field public object x

  .method public hidebysig specialname rtspecialname
          instance void .ctor(class Moggi.Rt.ArrSTArray arr, object idx, object x) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld class Moggi.Rt.ArrSTArray Moggi.Rt.WriteSTArrayFn::arr
    ldarg.0
    ldarg.2
    stfld object Moggi.Rt.WriteSTArrayFn::idx
    ldarg.0
    ldarg.3
    stfld object Moggi.Rt.WriteSTArrayFn::x
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld class Moggi.Rt.ArrSTArray Moggi.Rt.WriteSTArrayFn::arr
    ldarg.0
    ldfld object Moggi.Rt.WriteSTArrayFn::idx
    ldarg.0
    ldfld object Moggi.Rt.WriteSTArrayFn::x
    call object Moggi.Rt.RT::StWriteDirect(class Moggi.Rt.ArrSTArray, object, object)
    ret
  }
}

IL;
}

/** Thunk for `freezeSTArray#`/`unsafeFreezeSTArray#`: snapshot (or share) the slots when run. */
function freezeSTArrayFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.FreezeSTArrayFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public class Moggi.Rt.ArrSTArray arr
  .field public bool copy

  .method public hidebysig specialname rtspecialname
          instance void .ctor(class Moggi.Rt.ArrSTArray arr, bool copy) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld class Moggi.Rt.ArrSTArray Moggi.Rt.FreezeSTArrayFn::arr
    ldarg.0
    ldarg.2
    stfld bool Moggi.Rt.FreezeSTArrayFn::copy
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld class Moggi.Rt.ArrSTArray Moggi.Rt.FreezeSTArrayFn::arr
    ldarg.0
    ldfld bool Moggi.Rt.FreezeSTArrayFn::copy
    call class Moggi.Rt.ArrArray Moggi.Rt.RT::StFreezeDirect(class Moggi.Rt.ArrSTArray, bool)
    ret
  }
}

IL;
}

/** Thunk for `thawArray#`/`unsafeThawArray#`: copy (or share) the slots into a mutable array when run. */
function thawArrayFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.ThawArrayFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public class Moggi.Rt.ArrArray arr
  .field public bool copy

  .method public hidebysig specialname rtspecialname
          instance void .ctor(class Moggi.Rt.ArrArray arr, bool copy) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld class Moggi.Rt.ArrArray Moggi.Rt.ThawArrayFn::arr
    ldarg.0
    ldarg.2
    stfld bool Moggi.Rt.ThawArrayFn::copy
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld class Moggi.Rt.ArrArray Moggi.Rt.ThawArrayFn::arr
    ldarg.0
    ldfld bool Moggi.Rt.ThawArrayFn::copy
    call class Moggi.Rt.ArrSTArray Moggi.Rt.RT::StThawDirect(class Moggi.Rt.ArrArray, bool)
    ret
  }
}

IL;
}

function moggiExceptionClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.MoggiException
       extends [System.Runtime]System.Exception
{
  .field public object[] someException
  .field public object[] throwSite
  .field public class [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace originalTrace

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object[] se) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    ldnull
    ldnull
    call instance void Moggi.Rt.MoggiException::.ctor(object[], object[], class [System.Runtime]System.Exception)
    ret
  }

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object[] se, object[] throwSite, class [System.Runtime]System.Exception cause) cil managed
  {
    .maxstack 8
    .locals init (string msg, class [System.Runtime]System.Exception filtered)

    ldarg.1
    call string Moggi.Rt.RT::ExceptionDisplay(object)
    stloc msg

    ldarg.3
    brfalse CauseNull
    ldarg.3
    isinst Moggi.Rt.MoggiException
    brtrue CauseNull
    ldarg.3
    stloc filtered
    br CauseDone
    CauseNull:
    ldnull
    stloc filtered
    CauseDone:

    ldarg.0
    ldloc msg
    ldloc filtered
    call instance void [System.Runtime]System.Exception::.ctor(string, class [System.Runtime]System.Exception)

    ldarg.0
    ldarg.1
    stfld object[] Moggi.Rt.MoggiException::someException
    ldarg.0
    ldarg.2
    stfld object[] Moggi.Rt.MoggiException::throwSite
    ldarg.0
    newobj instance void [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace::.ctor()
    stfld class [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace Moggi.Rt.MoggiException::originalTrace
    ret
  }
}

IL;
}

function dictClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.Dict
       extends [System.Runtime]System.Object
{
  .field public object[] methods

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object[] methods) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld object[] Moggi.Rt.Dict::methods
    ret
  }

  .method public hidebysig instance object Lookup(string name) cil managed
  {
    .maxstack 8
    .locals init (int32 i)
    ldc.i4.0
    stloc i

    Loop:
    ldloc i
    ldarg.0
    ldfld object[] Moggi.Rt.Dict::methods
    ldlen
    conv.i4
    bge Missing

    ldarg.0
    ldfld object[] Moggi.Rt.Dict::methods
    ldloc i
    ldelem.ref
    castclass string
    ldarg.1
    call bool [System.Runtime]System.String::Equals(string, string)
    brfalse Next

    ldarg.0
    ldfld object[] Moggi.Rt.Dict::methods
    ldloc i
    ldc.i4.1
    add
    ldelem.ref
    ret

    Next:
    ldloc i
    ldc.i4.2
    add
    stloc i
    br Loop

    Missing:
    ldstr "Dict.Lookup: missing method"
    newobj instance void [System.Runtime]System.ArgumentException::.ctor(string)
    throw
  }
}

IL;
}

/** Fn that ignores arguments and returns a constant — backs RT.IoPure. */
function constFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.ConstFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public object constant

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object 'constant') cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld object Moggi.Rt.ConstFn::constant
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld object Moggi.Rt.ConstFn::constant
    ret
  }
}

IL;
}

/**
 * Memoising self-reference backing the strict fixpoint (`RT.Fix`).
 *
 * `Rec.Invoke(args)` stands for `fix f` applied to `args`: the first time it is
 * reached it computes `f(this)` and remembers the result, then forwards `args`
 * to that value. The recursion is therefore only forced when `f` actually uses
 * the reference, which is what makes `fix` work under strict evaluation.
 *
 * `fn` is kept as object (not Fn) so partially applied wrappers work too.
 */
function recClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.Rec
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public object fn
  .field public object cached
  .field public int32 done

  .method public hidebysig specialname rtspecialname
          instance void .ctor(object fn) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld object Moggi.Rt.Rec::fn
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld int32 Moggi.Rt.Rec::done
    brtrue.s Computed

    ldarg.0
    ldc.i4.1
    stfld int32 Moggi.Rt.Rec::done

    ldarg.0
    ldarg.0
    ldfld object Moggi.Rt.Rec::fn
    ldc.i4.1
    newarr object
    dup
    ldc.i4.0
    ldarg.0
    stelem.ref
    call object Moggi.Rt.RT::Apply(object, object[])
    stfld object Moggi.Rt.Rec::cached

    Computed:
    ldarg.0
    ldfld object Moggi.Rt.Rec::cached
    ldarg.1
    call object Moggi.Rt.RT::Apply(object, object[])
    ret
  }
}

IL;
}

function topLevelFnClassIl(): string
{
    return <<<'IL'
.class public auto ansi beforefieldinit Moggi.Rt.TopLevelFn
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .field public int32 arity
  .field public class Moggi.Rt.Fn target

  .method public hidebysig specialname rtspecialname
          instance void .ctor(int32 arity, class Moggi.Rt.Fn target) cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ldarg.0
    ldarg.1
    stfld int32 Moggi.Rt.TopLevelFn::arity
    ldarg.0
    ldarg.2
    stfld class Moggi.Rt.Fn Moggi.Rt.TopLevelFn::target
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld class Moggi.Rt.Fn Moggi.Rt.TopLevelFn::target
    ldarg.1
    callvirt instance object Moggi.Rt.Fn::Invoke(object[])
    ret
  }
}

IL;
}

function rtClassIl(): string
{
    return \implode("\n", [
        rtClassHeader(),
        rtApplyIl(),
        rtApply1Il(),
        rtConcatArgsIl(),
        rtConcatTakeIl(),
        rtDropArgsIl(),
        rtDictMethodIl(),
        rtDictCallIl(),
        rtConsIl(),
        rtListAppendIl(),
        rtValueEqIl(),
        rtListEqIl(),
        rtOrderingFromIntIl(),
        rtOrderingToIntIl(),
        rtIsOrderingConIl(),
        rtValueCompareIl(),
        rtListCompareIl(),
        rtMaybeCompareIl(),
        rtMaybeEqIl(),
        rtBoxIntIl(),
        rtUnboxIntIl(),
        rtIoRunIl(),
        rtExceptionWrapIl(),
        rtExceptionUnwrapIl(),
        rtExceptionDisplayIl(),
        rtExceptionDisplayMessageIl(),
        rtThrowSiteIl(),
        rtExceptionAttachWrapperIl(),
        rtDisplaySourcePathIl(),
        rtAppendFrameReportIl(),
        rtAppendMoggFramesIl(),
        rtAppendHostFramesIl(),
        rtFormatExceptionReportIl(),
        rtReportUncaughtIl(),
        rtThrowSomeExceptionIl(),
        rtThrowErrorCallIl(),
        rtIntAbsIl(),
        rtNaturalFromIntegerIl(),
        rtNormalizeHostExceptionIl(),
        rtIoCatchIl(),
        rtIoFinallyIl(),
        rtIoPureIl(),
        rtStPureIl(),
        rtStBindIl(),
        rtStRunIl(),
        rtStToIOIl(),
        rtStNewRefIl(),
        rtStReadRefIl(),
        rtStWriteRefIl(),
        rtArrCheckedSizeIl(),
        rtArrOffsetIl(),
        rtArrAllocSlotsIl(),
        rtArrRangeCheckIl(),
        rtArrReadSlotIl(),
        rtArrCopySlotsIl(),
        rtArrayNegSizeIl(),
        rtArrayTooLargeIl(),
        rtArrayRangeIl(),
        rtArrayHoleIl(),
        rtArrayNewIl(),
        rtArraySizeIl(),
        rtArrayBoundsIl(),
        rtArrayReadIl(),
        rtStNewDirectIl(),
        rtStNewArrayIl(),
        rtStReadDirectIl(),
        rtStReadArrayIl(),
        rtStWriteDirectIl(),
        rtStWriteArrayIl(),
        rtStSizeArrayIl(),
        rtStBoundsArrayIl(),
        rtStFreezeDirectIl(),
        rtStFreezeArrayIl(),
        rtStThawDirectIl(),
        rtStThawArrayIl(),
        rtIoNewArrayIl(),
        rtIoReadArrayIl(),
        rtIoWriteArrayIl(),
        rtIoSizeArrayIl(),
        rtIoBoundsArrayIl(),
        rtIoFreezeArrayIl(),
        rtIoThawArrayIl(),
        rtConstFnIl(),
        rtFixIl(),
        rtConIl(),
        rtClassFooter(),
    ]);
}

function rtClassHeader(): string
{
    return <<<'IL'
.class public abstract auto ansi sealed beforefieldinit Moggi.Rt.RT
       extends [System.Runtime]System.Object
{

IL;
}

function rtClassFooter(): string
{
    return <<<'IL'
}

IL;
}

function rtApplyIl(): string
{
    return <<<'IL'
  .method public hidebysig static object Apply(object callee, object[] args) cil managed
  {
    .maxstack 8
    .locals init (class Moggi.Rt.Partial partial,
                  int32 remaining,
                  int32 argsLen,
                  object[] callArgs,
                  object result)

    ldarg.0
    isinst Moggi.Rt.Partial
    brfalse TryTopLevel
    ldarg.0
    castclass Moggi.Rt.Partial
    stloc partial
    br HavePartial

    TryTopLevel:
    ldarg.0
    isinst Moggi.Rt.TopLevelFn
    brfalse TryFn
    ldarg.0
    castclass Moggi.Rt.TopLevelFn
    ldfld int32 Moggi.Rt.TopLevelFn::arity
    ldarg.0
    castclass Moggi.Rt.Fn
    ldc.i4.0
    newarr object
    newobj instance void Moggi.Rt.Partial::.ctor(int32, class Moggi.Rt.Fn, object[])
    stloc partial
    br HavePartial

    TryFn:
    ldarg.0
    isinst Moggi.Rt.Fn
    brfalse Fail
    ldarg.0
    castclass Moggi.Rt.Fn
    ldarg.1
    callvirt instance object Moggi.Rt.Fn::Invoke(object[])
    ret

    HavePartial:
    ldloc partial
    ldfld int32 Moggi.Rt.Partial::arity
    ldloc partial
    ldfld object[] Moggi.Rt.Partial::args
    ldlen
    conv.i4
    sub
    stloc remaining

    ldarg.1
    ldlen
    conv.i4
    stloc argsLen

    ldloc argsLen
    ldloc remaining
    bge EnoughArgs

    ldloc partial
    ldfld int32 Moggi.Rt.Partial::arity
    ldloc partial
    ldfld class Moggi.Rt.Fn Moggi.Rt.Partial::target
    ldloc partial
    ldfld object[] Moggi.Rt.Partial::args
    ldarg.1
    call object[] Moggi.Rt.RT::ConcatArgs(object[], object[])
    newobj instance void Moggi.Rt.Partial::.ctor(int32, class Moggi.Rt.Fn, object[])
    ret

    EnoughArgs:
    ldloc partial
    ldfld object[] Moggi.Rt.Partial::args
    ldarg.1
    ldloc remaining
    call object[] Moggi.Rt.RT::ConcatTake(object[], object[], int32)
    stloc callArgs

    ldloc partial
    ldfld class Moggi.Rt.Fn Moggi.Rt.Partial::target
    ldloc callArgs
    callvirt instance object Moggi.Rt.Fn::Invoke(object[])
    stloc result

    ldloc argsLen
    ldloc remaining
    beq SameCount
    ldloc result
    ldarg.1
    ldloc remaining
    call object[] Moggi.Rt.RT::DropArgs(object[], int32)
    call object Moggi.Rt.RT::Apply(object, object[])
    ret

    SameCount:
    ldloc result
    ret

    Fail:
    ldstr "RT.Apply: not a Fn or Partial"
    newobj instance void [System.Runtime]System.ArgumentException::.ctor(string)
    throw
  }

IL;
}

function rtApply1Il(): string
{
    return <<<'IL'
  .method public hidebysig static object Apply1(object callee, object arg) cil managed
  {
    .maxstack 8
    .locals init (class Moggi.Rt.Partial partial,
                  int32 remaining,
                  int32 plen,
                  object[] grown,
                  int32 i)

    ldarg.0
    isinst Moggi.Rt.Partial
    brfalse General
    ldarg.0
    castclass Moggi.Rt.Partial
    stloc partial

    ldloc partial
    ldfld int32 Moggi.Rt.Partial::arity
    ldloc partial
    ldfld object[] Moggi.Rt.Partial::args
    ldlen
    conv.i4
    sub
    stloc remaining

    ldloc partial
    ldfld object[] Moggi.Rt.Partial::args
    ldlen
    conv.i4
    stloc plen

    ldloc remaining
    ldc.i4.1
    blt General

    ldloc plen
    ldc.i4.1
    add
    newarr object
    stloc grown

    ldc.i4.0
    stloc i
    GrowCopy:
    ldloc i
    ldloc plen
    bge GrowCopied
    ldloc grown
    ldloc i
    ldloc partial
    ldfld object[] Moggi.Rt.Partial::args
    ldloc i
    ldelem.ref
    stelem.ref
    ldloc i
    ldc.i4.1
    add
    stloc i
    br GrowCopy
    GrowCopied:

    ldloc grown
    ldloc plen
    ldarg.1
    stelem.ref

    ldloc remaining
    ldc.i4.1
    bne.un Extend

    ldloc partial
    ldfld class Moggi.Rt.Fn Moggi.Rt.Partial::target
    ldloc grown
    callvirt instance object Moggi.Rt.Fn::Invoke(object[])
    ret

    Extend:
    ldloc partial
    ldfld int32 Moggi.Rt.Partial::arity
    ldloc partial
    ldfld class Moggi.Rt.Fn Moggi.Rt.Partial::target
    ldloc grown
    newobj instance void Moggi.Rt.Partial::.ctor(int32, class Moggi.Rt.Fn, object[])
    ret

    General:
    ldarg.0
    ldc.i4.1
    newarr object
    dup
    ldc.i4.0
    ldarg.1
    stelem.ref
    call object Moggi.Rt.RT::Apply(object, object[])
    ret
  }

IL;
}

function rtConcatTakeIl(): string
{
    return <<<'IL'
  .method public hidebysig static object[] ConcatTake(object[] prefix, object[] src, int32 n) cil managed
  {
    .maxstack 8
    .locals init (object[] result, int32 i, int32 plen)

    ldarg.0
    ldlen
    conv.i4
    stloc plen
    ldloc plen
    ldarg.2
    add
    newarr object
    stloc result

    ldc.i4.0
    stloc i
    CopyPrefix:
    ldloc i
    ldloc plen
    bge CopyTaken
    ldloc result
    ldloc i
    ldarg.0
    ldloc i
    ldelem.ref
    stelem.ref
    ldloc i
    ldc.i4.1
    add
    stloc i
    br CopyPrefix

    CopyTaken:
    ldc.i4.0
    stloc i
    Take:
    ldloc i
    ldarg.2
    bge Done
    ldloc result
    ldloc plen
    ldloc i
    add
    ldarg.1
    ldloc i
    ldelem.ref
    stelem.ref
    ldloc i
    ldc.i4.1
    add
    stloc i
    br Take

    Done:
    ldloc result
    ret
  }

IL;
}

function rtConcatArgsIl(): string
{
    return <<<'IL'
  .method public hidebysig static object[] ConcatArgs(object[] a, object[] b) cil managed
  {
    .maxstack 8
    .locals init (object[] result, int32 i, int32 alen, int32 blen)

    ldarg.0
    ldlen
    conv.i4
    stloc alen
    ldarg.1
    ldlen
    conv.i4
    stloc blen
    ldloc alen
    ldloc blen
    add
    newarr object
    stloc result

    ldc.i4.0
    stloc i
    CopyA:
    ldloc i
    ldloc alen
    bge CopyADone
    ldloc result
    ldloc i
    ldarg.0
    ldloc i
    ldelem.ref
    stelem.ref
    ldloc i
    ldc.i4.1
    add
    stloc i
    br CopyA
    CopyADone:

    ldc.i4.0
    stloc i
    CopyB:
    ldloc i
    ldloc blen
    bge CopyBDone
    ldloc result
    ldloc i
    ldloc alen
    add
    ldarg.1
    ldloc i
    ldelem.ref
    stelem.ref
    ldloc i
    ldc.i4.1
    add
    stloc i
    br CopyB
    CopyBDone:

    ldloc result
    ret
  }

IL;
}

function rtDropArgsIl(): string
{
    return <<<'IL'
  .method public hidebysig static object[] DropArgs(object[] args, int32 n) cil managed
  {
    .maxstack 8
    .locals init (object[] result, int32 i, int32 srcLen, int32 outLen)

    ldarg.0
    ldlen
    conv.i4
    stloc srcLen
    ldloc srcLen
    ldarg.1
    sub
    stloc outLen
    ldloc outLen
    ldc.i4.0
    bgt PosLen
    ldc.i4.0
    stloc outLen
    PosLen:
    ldloc outLen
    newarr object
    stloc result

    ldc.i4.0
    stloc i
    Loop:
    ldloc i
    ldloc outLen
    bge Done
    ldloc result
    ldloc i
    ldarg.0
    ldloc i
    ldarg.1
    add
    ldelem.ref
    stelem.ref
    ldloc i
    ldc.i4.1
    add
    stloc i
    br Loop

    Done:
    ldloc result
    ret
  }

IL;
}

function rtDictMethodIl(): string
{
    return <<<'IL'
  .method public hidebysig static object DictMethod(object dict, string name) cil managed
  {
    .maxstack 8
    ldarg.0
    castclass Moggi.Rt.Dict
    ldarg.1
    callvirt instance object Moggi.Rt.Dict::Lookup(string)
    ret
  }

IL;
}

function rtDictCallIl(): string
{
    return <<<'IL'
  .method public hidebysig static object DictCall(object dict, string name, object[] args) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    call object Moggi.Rt.RT::DictMethod(object, string)
    ldarg.2
    call object Moggi.Rt.RT::Apply(object, object[])
    ret
  }

IL;
}

function rtConsIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.MList Cons(object head, object tail) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    castclass Moggi.Rt.MList
    newobj instance void Moggi.Rt.MList::.ctor(object, class Moggi.Rt.MList)
    ret
  }

IL;
}

function rtListAppendIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.MList ListAppend(class Moggi.Rt.MList a, class Moggi.Rt.MList b) cil managed
  {
    .maxstack 8
    .locals init (class Moggi.Rt.MList rev, class Moggi.Rt.MList cursor, class Moggi.Rt.MList result)

    ldarg.0
    brtrue Scan
    ldarg.1
    ret

    Scan:
    ldnull
    stloc rev
    ldarg.0
    stloc cursor

    ScanLoop:
    ldloc cursor
    brfalse Rebuild
    ldloc cursor
    ldfld object Moggi.Rt.MList::head
    ldloc rev
    call class Moggi.Rt.MList Moggi.Rt.RT::Cons(object, object)
    stloc rev
    ldloc cursor
    ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail
    stloc cursor
    br ScanLoop

    Rebuild:
    ldarg.1
    stloc result

    RebuildLoop:
    ldloc rev
    brfalse Done
    ldloc rev
    ldfld object Moggi.Rt.MList::head
    ldloc result
    call class Moggi.Rt.MList Moggi.Rt.RT::Cons(object, object)
    stloc result
    ldloc rev
    ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail
    stloc rev
    br RebuildLoop

    Done:
    ldloc result
    ret
  }

IL;
}

function rtValueEqIl(): string
{
    return <<<'IL'
  .method public hidebysig static bool ValueEq(object a, object b) cil managed
  {
    .maxstack 8
    .locals init (class Moggi.Rt.Con ca, class Moggi.Rt.Con cb, int32 i, object[] ta, object[] tb)

    ldarg.0
    ldarg.1
    beq Same
    ldarg.0
    brtrue LeftSome
    ldc.i4.0
    ret

    LeftSome:
    ldarg.1
    brtrue RightSome
    ldc.i4.0
    ret

    RightSome:
    ldarg.0
    isinst Moggi.Rt.MList
    brfalse NotList
    ldarg.1
    isinst Moggi.Rt.MList
    brfalse NotList
    ldarg.0
    ldarg.1
    call bool Moggi.Rt.RT::ListEq(object, object)
    ret

    NotList:
    ldarg.0
    isinst Moggi.Rt.Con
    brfalse NotCon
    ldarg.1
    isinst Moggi.Rt.Con
    brfalse NotCon
    ldarg.0
    castclass Moggi.Rt.Con
    stloc ca
    ldarg.1
    castclass Moggi.Rt.Con
    stloc cb

    ldloc ca
    ldfld string Moggi.Rt.Con::tag
    ldloc cb
    ldfld string Moggi.Rt.Con::tag
    call bool [System.Runtime]System.String::Equals(string, string)
    brfalse False

    ldloc ca
    ldfld object[] Moggi.Rt.Con::fields
    ldlen
    conv.i4
    ldloc cb
    ldfld object[] Moggi.Rt.Con::fields
    ldlen
    conv.i4
    bne.un False

    ldc.i4.0
    stloc i
    FieldLoop:
    ldloc i
    ldloc ca
    ldfld object[] Moggi.Rt.Con::fields
    ldlen
    conv.i4
    bge True
    ldloc ca
    ldfld object[] Moggi.Rt.Con::fields
    ldloc i
    ldelem.ref
    ldloc cb
    ldfld object[] Moggi.Rt.Con::fields
    ldloc i
    ldelem.ref
    call bool Moggi.Rt.RT::ValueEq(object, object)
    brfalse False
    ldloc i
    ldc.i4.1
    add
    stloc i
    br FieldLoop

    True:
    ldc.i4.1
    ret

    False:
    ldc.i4.0
    ret

    NotCon:
    ldarg.0
    isinst object[]
    brfalse Fallback
    ldarg.1
    isinst object[]
    brfalse Fallback
    ldarg.0
    castclass object[]
    stloc ta
    ldarg.1
    castclass object[]
    stloc tb

    ldloc ta
    ldlen
    conv.i4
    ldloc tb
    ldlen
    conv.i4
    bne.un TupleFalse

    ldc.i4.0
    stloc i
    TupleLoop:
    ldloc i
    ldloc ta
    ldlen
    conv.i4
    bge TupleTrue
    ldloc ta
    ldloc i
    ldelem.ref
    ldloc tb
    ldloc i
    ldelem.ref
    call bool Moggi.Rt.RT::ValueEq(object, object)
    brfalse TupleFalse
    ldloc i
    ldc.i4.1
    add
    stloc i
    br TupleLoop

    TupleTrue:
    ldc.i4.1
    ret

    TupleFalse:
    ldc.i4.0
    ret

    Fallback:
    ldarg.0
    ldarg.1
    call bool [System.Runtime]System.Object::Equals(object, object)
    ret

    Same:
    ldc.i4.1
    ret
  }

IL;
}

function rtListEqIl(): string
{
    return <<<'IL'
  .method public hidebysig static bool ListEq(object a, object b) cil managed
  {
    .maxstack 8
    .locals init (class Moggi.Rt.MList curA, class Moggi.Rt.MList curB)

    ldarg.0
    castclass Moggi.Rt.MList
    stloc curA
    ldarg.1
    castclass Moggi.Rt.MList
    stloc curB

    Loop:
    ldloc curA
    brtrue LeftSome
    ldloc curB
    brtrue False
    ldc.i4.1
    ret

    LeftSome:
    ldloc curB
    brfalse False

    ldloc curA
    ldfld object Moggi.Rt.MList::head
    ldloc curB
    ldfld object Moggi.Rt.MList::head
    call bool Moggi.Rt.RT::ValueEq(object, object)
    brfalse False

    ldloc curA
    ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail
    ldloc curB
    ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail
    stloc curB
    stloc curA
    br Loop

    False:
    ldc.i4.0
    ret
  }

IL;
}

function rtOrderingFromIntIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.Con OrderingFromInt(int32 n) cil managed
  {
    .maxstack 8
    .locals init (string tag)

    ldarg.0
    ldc.i4.0
    blt IsLt
    ldarg.0
    brtrue IsGt
    ldstr "EQ"
    stloc tag
    br Mk

    IsLt:
    ldstr "LT"
    stloc tag
    br Mk

    IsGt:
    ldstr "GT"
    stloc tag

    Mk:
    ldloc tag
    ldc.i4.0
    newarr object
    call class Moggi.Rt.Con Moggi.Rt.RT::Con(string, object[])
    ret
  }

IL;
}

function rtOrderingToIntIl(): string
{
    return <<<'IL'
  .method public hidebysig static int32 OrderingToInt(class Moggi.Rt.Con c) cil managed
  {
    .maxstack 8
    .locals init (string tag)

    ldarg.0
    ldfld string Moggi.Rt.Con::tag
    stloc tag

    ldloc tag
    ldstr "LT"
    call bool [System.Runtime]System.String::Equals(string, string)
    brfalse NotLt
    ldc.i4.m1
    ret

    NotLt:
    ldloc tag
    ldstr "GT"
    call bool [System.Runtime]System.String::Equals(string, string)
    brfalse Eq
    ldc.i4.1
    ret

    Eq:
    ldc.i4.0
    ret
  }

IL;
}

function rtIsOrderingConIl(): string
{
    return <<<'IL'
  .method public hidebysig static bool IsOrderingCon(class Moggi.Rt.Con c) cil managed
  {
    .maxstack 8
    .locals init (string tag)

    ldarg.0
    ldfld string Moggi.Rt.Con::tag
    stloc tag

    ldloc tag
    ldstr "LT"
    call bool [System.Runtime]System.String::Equals(string, string)
    brtrue Yes
    ldloc tag
    ldstr "EQ"
    call bool [System.Runtime]System.String::Equals(string, string)
    brtrue Yes
    ldloc tag
    ldstr "GT"
    call bool [System.Runtime]System.String::Equals(string, string)
    brtrue Yes
    ldc.i4.0
    ret

    Yes:
    ldc.i4.1
    ret
  }

IL;
}

function rtValueCompareIl(): string
{
    return <<<'IL'
  .method public hidebysig static int32 ValueCompare(object a, object b) cil managed
  {
    .maxstack 8
    .locals init (class Moggi.Rt.Con ca, class Moggi.Rt.Con cb, int32 i, int32 cmp, object[] ta, object[] tb)

    ldarg.0
    ldarg.1
    beq Same
    ldarg.0
    brtrue LeftSome
    ldc.i4.m1
    ret

    LeftSome:
    ldarg.1
    brtrue RightSome
    ldc.i4.1
    ret

    RightSome:
    ldarg.0
    isinst Moggi.Rt.MList
    brfalse NotList
    ldarg.1
    isinst Moggi.Rt.MList
    brfalse NotList
    ldarg.0
    ldarg.1
    call class Moggi.Rt.Con Moggi.Rt.RT::ListCompare(object, object)
    call int32 Moggi.Rt.RT::OrderingToInt(class Moggi.Rt.Con)
    ret

    NotList:
    ldarg.0
    isinst Moggi.Rt.Con
    brfalse NotCon
    ldarg.1
    isinst Moggi.Rt.Con
    brfalse NotCon
    ldarg.0
    castclass Moggi.Rt.Con
    stloc ca
    ldarg.1
    castclass Moggi.Rt.Con
    stloc cb

    ldloc ca
    ldfld string Moggi.Rt.Con::tag
    ldloc cb
    ldfld string Moggi.Rt.Con::tag
    call bool [System.Runtime]System.String::Equals(string, string)
    brfalse TagsDiffer

    ldc.i4.0
    stloc i
    FieldLoop:
    ldloc i
    ldloc ca
    ldfld object[] Moggi.Rt.Con::fields
    ldlen
    conv.i4
    bge FieldsDone
    ldloc ca
    ldfld object[] Moggi.Rt.Con::fields
    ldloc i
    ldelem.ref
    ldloc cb
    ldfld object[] Moggi.Rt.Con::fields
    ldloc i
    ldelem.ref
    call int32 Moggi.Rt.RT::ValueCompare(object, object)
    stloc cmp
    ldloc cmp
    brfalse FieldNext
    ldloc cmp
    ret
    FieldNext:
    ldloc i
    ldc.i4.1
    add
    stloc i
    br FieldLoop
    FieldsDone:
    ldc.i4.0
    ret

    TagsDiffer:
    ldloc ca
    call bool Moggi.Rt.RT::IsOrderingCon(class Moggi.Rt.Con)
    brfalse LexTags
    ldloc cb
    call bool Moggi.Rt.RT::IsOrderingCon(class Moggi.Rt.Con)
    brfalse LexTags
    ldloc ca
    call int32 Moggi.Rt.RT::OrderingToInt(class Moggi.Rt.Con)
    ldloc cb
    call int32 Moggi.Rt.RT::OrderingToInt(class Moggi.Rt.Con)
    sub
    ret

    LexTags:
    ldloc ca
    ldfld string Moggi.Rt.Con::tag
    ldloc cb
    ldfld string Moggi.Rt.Con::tag
    callvirt instance int32 [System.Runtime]System.String::CompareTo(string)
    ret

    NotCon:
    ldarg.0
    isinst object[]
    brfalse NotTuple
    ldarg.1
    isinst object[]
    brfalse NotTuple
    ldarg.0
    castclass object[]
    stloc ta
    ldarg.1
    castclass object[]
    stloc tb
    ldc.i4.0
    stloc i
    TupLoop:
    ldloc i
    ldloc ta
    ldlen
    conv.i4
    bge TupLenDiff
    ldloc i
    ldloc tb
    ldlen
    conv.i4
    bge TupLenDiff
    ldloc ta
    ldloc i
    ldelem.ref
    ldloc tb
    ldloc i
    ldelem.ref
    call int32 Moggi.Rt.RT::ValueCompare(object, object)
    stloc cmp
    ldloc cmp
    brfalse TupNext
    ldloc cmp
    ret
    TupNext:
    ldloc i
    ldc.i4.1
    add
    stloc i
    br TupLoop
    TupLenDiff:
    ldloc ta
    ldlen
    conv.i4
    ldloc tb
    ldlen
    conv.i4
    sub
    ret

    NotTuple:
    ldarg.0
    isinst [System.Runtime]System.IComparable
    brfalse Fallback
    ldarg.0
    castclass [System.Runtime]System.IComparable
    ldarg.1
    callvirt instance int32 [System.Runtime]System.IComparable::CompareTo(object)
    ret

    Fallback:
    ldarg.0
    ldarg.1
    call bool [System.Runtime]System.Object::Equals(object, object)
    brfalse FallbackGt
    ldc.i4.0
    ret
    FallbackGt:
    ldc.i4.1
    ret

    Same:
    ldc.i4.0
    ret
  }

IL;
}

function rtListCompareIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.Con ListCompare(object a, object b) cil managed
  {
    .maxstack 8
    .locals init (class Moggi.Rt.MList curA, class Moggi.Rt.MList curB, int32 cmp)

    ldarg.0
    castclass Moggi.Rt.MList
    stloc curA
    ldarg.1
    castclass Moggi.Rt.MList
    stloc curB

    Loop:
    ldloc curA
    brtrue LeftSome
    ldloc curB
    brtrue RightOnly
    ldc.i4.0
    call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)
    ret
    RightOnly:
    ldc.i4.m1
    call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)
    ret

    LeftSome:
    ldloc curB
    brtrue BothSome
    ldc.i4.1
    call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)
    ret

    BothSome:
    ldloc curA
    ldfld object Moggi.Rt.MList::head
    ldloc curB
    ldfld object Moggi.Rt.MList::head
    call int32 Moggi.Rt.RT::ValueCompare(object, object)
    stloc cmp
    ldloc cmp
    brfalse HeadsEq
    ldloc cmp
    call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)
    ret

    HeadsEq:
    ldloc curA
    ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail
    ldloc curB
    ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail
    stloc curB
    stloc curA
    br Loop
  }

IL;
}

function rtMaybeCompareIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.Con MaybeCompare(object a, object b) cil managed
  {
    .maxstack 8
    .locals init (class Moggi.Rt.Con ca, class Moggi.Rt.Con cb, bool leftNothing, bool rightNothing)

    ldarg.0
    castclass Moggi.Rt.Con
    stloc ca
    ldarg.1
    castclass Moggi.Rt.Con
    stloc cb

    ldloc ca
    ldfld string Moggi.Rt.Con::tag
    ldstr "Nothing"
    call bool [System.Runtime]System.String::Equals(string, string)
    stloc leftNothing
    ldloc cb
    ldfld string Moggi.Rt.Con::tag
    ldstr "Nothing"
    call bool [System.Runtime]System.String::Equals(string, string)
    stloc rightNothing

    ldloc leftNothing
    brfalse LeftJust
    ldloc rightNothing
    brfalse LeftNothingRightJust
    ldc.i4.0
    call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)
    ret

    LeftNothingRightJust:
    ldc.i4.m1
    call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)
    ret

    LeftJust:
    ldloc rightNothing
    brfalse BothJust
    ldc.i4.1
    call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)
    ret

    BothJust:
    ldloc ca
    ldfld object[] Moggi.Rt.Con::fields
    ldc.i4.0
    ldelem.ref
    ldloc cb
    ldfld object[] Moggi.Rt.Con::fields
    ldc.i4.0
    ldelem.ref
    call int32 Moggi.Rt.RT::ValueCompare(object, object)
    call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)
    ret
  }

IL;
}

function rtMaybeEqIl(): string
{
    return <<<'IL'
  .method public hidebysig static bool MaybeEq(object a, object b) cil managed
  {
    .maxstack 8
    .locals init (class Moggi.Rt.Con ca, class Moggi.Rt.Con cb)

    ldarg.0
    ldarg.1
    beq True
    ldarg.0
    brtrue LeftSome
    ldc.i4.0
    ret
    LeftSome:
    ldarg.1
    brtrue RightSome
    ldc.i4.0
    ret

    RightSome:
    ldarg.0
    castclass Moggi.Rt.Con
    stloc ca
    ldarg.1
    castclass Moggi.Rt.Con
    stloc cb

    ldloc ca
    ldfld string Moggi.Rt.Con::tag
    ldloc cb
    ldfld string Moggi.Rt.Con::tag
    call bool [System.Runtime]System.String::Equals(string, string)
    brfalse False

    ldloc ca
    ldfld object[] Moggi.Rt.Con::fields
    ldlen
    conv.i4
    ldloc cb
    ldfld object[] Moggi.Rt.Con::fields
    ldlen
    conv.i4
    bne.un False

    ldloc ca
    ldfld object[] Moggi.Rt.Con::fields
    ldlen
    conv.i4
    brtrue MaybeJust
    ldc.i4.1
    ret

    MaybeJust:
    ldloc ca
    ldfld object[] Moggi.Rt.Con::fields
    ldc.i4.0
    ldelem.ref
    ldloc cb
    ldfld object[] Moggi.Rt.Con::fields
    ldc.i4.0
    ldelem.ref
    call bool Moggi.Rt.RT::ValueEq(object, object)
    ret

    True:
    ldc.i4.1
    ret

    False:
    ldc.i4.0
    ret
  }

IL;
}

function rtBoxIntIl(): string
{
    return <<<'IL'
  .method public hidebysig static object BoxInt(int64 v) cil managed
  {
    .maxstack 8
    ldarg.0
    box int64
    ret
  }

IL;
}

function rtUnboxIntIl(): string
{
    return <<<'IL'
  .method public hidebysig static int64 UnboxInt(object o) cil managed
  {
    .maxstack 8
    ldarg.0
    unbox.any int64
    ret
  }

IL;
}

function rtIoRunIl(): string
{
    return <<<'IL'
  .method public hidebysig static object IoRun(object v) cil managed
  {
    .maxstack 8
    ldarg.0
    isinst Moggi.Rt.IO
    brfalse Passthrough
    ldarg.0
    castclass Moggi.Rt.IO
    ldfld object Moggi.Rt.IO::action
    ldc.i4.0
    newarr object
    call object Moggi.Rt.RT::Apply(object, object[])
    ret
    Passthrough:
    ldarg.0
    ret
  }

IL;
}

function rtExceptionWrapIl(): string
{
    return <<<'IL'
  .method public hidebysig static object[] ExceptionWrap(string tag, string display, object payload) cil managed
  {
    .maxstack 8
    ldc.i4.4
    newarr object
    dup
    ldc.i4.0
    ldstr "__se"
    stelem.ref
    dup
    ldc.i4.1
    ldarg.0
    stelem.ref
    dup
    ldc.i4.2
    ldarg.2
    stelem.ref
    dup
    ldc.i4.3
    ldarg.1
    stelem.ref
    ret
  }

IL;
}

function rtExceptionUnwrapIl(): string
{
    return <<<'IL'
  .method public hidebysig static object ExceptionUnwrap(string tag, object se) cil managed
  {
    .maxstack 8
    .locals init (object[] arr)

    ldarg.1
    isinst object[]
    brfalse Nothing
    ldarg.1
    castclass object[]
    stloc arr
    ldloc arr
    ldlen
    conv.i4
    ldc.i4.3
    blt Nothing
    ldloc arr
    ldc.i4.0
    ldelem.ref
    ldstr "__se"
    call bool [System.Runtime]System.Object::Equals(object, object)
    brfalse Nothing
    ldloc arr
    ldc.i4.1
    ldelem.ref
    ldarg.0
    call bool [System.Runtime]System.Object::Equals(object, object)
    brfalse Nothing

    ldstr "Just"
    ldc.i4.1
    newarr object
    dup
    ldc.i4.0
    ldloc arr
    ldc.i4.2
    ldelem.ref
    stelem.ref
    call class Moggi.Rt.Con Moggi.Rt.RT::Con(string, object[])
    ret

    Nothing:
    ldstr "Nothing"
    ldc.i4.0
    newarr object
    call class Moggi.Rt.Con Moggi.Rt.RT::Con(string, object[])
    ret
  }

IL;
}

function rtExceptionDisplayIl(): string
{
    return <<<'IL'
  .method public hidebysig static string ExceptionDisplay(object se) cil managed
  {
    .maxstack 8
    .locals init (object[] arr, string tag, object payload)

    ldarg.0
    isinst object[]
    brfalse Fallback
    ldarg.0
    castclass object[]
    stloc arr
    ldloc arr
    ldlen
    conv.i4
    ldc.i4.3
    blt Fallback
    ldloc arr
    ldc.i4.0
    ldelem.ref
    ldstr "__se"
    call bool [System.Runtime]System.Object::Equals(object, object)
    brfalse Fallback

    ldloc arr
    ldlen
    conv.i4
    ldc.i4.4
    blt FromPayload
    ldloc arr
    ldc.i4.3
    ldelem.ref
    isinst string
    stloc tag
    ldloc tag
    brfalse FromPayload
    ldloc tag
    ret

    FromPayload:
    ldloc arr
    ldc.i4.1
    ldelem.ref
    castclass string
    stloc tag
    ldloc arr
    ldc.i4.2
    ldelem.ref
    stloc payload
    ldloc tag
    ldloc payload
    call string Moggi.Rt.RT::ExceptionDisplayMessage(string, object)
    ret

    Fallback:
    ldstr "SomeException"
    ret
  }

IL;
}

function rtExceptionDisplayMessageIl(): string
{
    return <<<'IL'
  .method public hidebysig static string ExceptionDisplayMessage(string tag, object payload) cil managed
  {
    .maxstack 8
    .locals init (class Moggi.Rt.Con c)

    ldarg.1
    isinst Moggi.Rt.Con
    brfalse NotCon
    ldarg.1
    castclass Moggi.Rt.Con
    stloc c

    ldarg.0
    ldstr "ErrorCall"
    call bool [System.Runtime]System.String::Equals(string, string)
    brfalse TryIoe
    ldloc c
    ldfld string Moggi.Rt.Con::tag
    ldstr "ErrorCall"
    call bool [System.Runtime]System.String::Equals(string, string)
    brfalse TryIoe
    ldloc c
    ldfld object[] Moggi.Rt.Con::fields
    ldc.i4.0
    ldelem.ref
    castclass string
    ret

    TryIoe:
    ldarg.0
    ldstr "IOException"
    call bool [System.Runtime]System.String::Equals(string, string)
    brfalse TryHost
    ldloc c
    ldfld object[] Moggi.Rt.Con::fields
    ldlen
    conv.i4
    ldc.i4.4
    blt TryHost
    ldloc c
    ldfld object[] Moggi.Rt.Con::fields
    ldc.i4.3
    ldelem.ref
    castclass string
    ret

    TryHost:
    ldarg.0
    ldstr "HostException"
    call bool [System.Runtime]System.String::Equals(string, string)
    brfalse TagFallback
    ldloc c
    ldfld object[] Moggi.Rt.Con::fields
    ldlen
    conv.i4
    ldc.i4.3
    blt HostLegacy
    ldloc c
    ldfld object[] Moggi.Rt.Con::fields
    ldc.i4.2
    ldelem.ref
    castclass string
    ret
    HostLegacy:
    ldloc c
    ldfld object[] Moggi.Rt.Con::fields
    ldlen
    conv.i4
    ldc.i4.1
    blt TagFallback
    ldloc c
    ldfld object[] Moggi.Rt.Con::fields
    ldc.i4.0
    ldelem.ref
    castclass string
    ret

    NotCon:
    ldarg.1
    isinst string
    brfalse TagFallback
    ldarg.1
    castclass string
    ret

    TagFallback:
    ldarg.0
    ret
  }

IL;
}

function rtThrowSiteIl(): string
{
    return <<<'IL'
  .method public hidebysig static object[] ThrowSite(int32 siteId, string symbolId, string displayPath, int32 line, int32 col) cil managed
  {
    .maxstack 8
    ldc.i4.5
    newarr object
    dup
    ldc.i4.0
    ldarg.0
    box [System.Runtime]System.Int32
    stelem.ref
    dup
    ldc.i4.1
    ldarg.1
    stelem.ref
    dup
    ldc.i4.2
    ldarg.2
    stelem.ref
    dup
    ldc.i4.3
    ldarg.3
    box [System.Runtime]System.Int32
    stelem.ref
    dup
    ldc.i4.4
    ldarg.s col
    box [System.Runtime]System.Int32
    stelem.ref
    ret
  }

IL;
}

function rtExceptionAttachWrapperIl(): string
{
    return <<<'IL'
  .method public hidebysig static object[] AttachWrapper(object[] se, class Moggi.Rt.MoggiException e) cil managed
  {
    .maxstack 6
    .locals init (object[] grown, int32 n)
    ldc.i4.5
    newarr object
    stloc grown
    ldarg.0
    ldlen
    conv.i4
    ldc.i4.4
    call int32 [System.Runtime]System.Math::Min(int32, int32)
    stloc n
    ldarg.0
    ldc.i4.0
    ldloc grown
    ldc.i4.0
    ldloc n
    call void [System.Runtime]System.Array::Copy(class [System.Runtime]System.Array, int32, class [System.Runtime]System.Array, int32, int32)
    ldloc grown
    ldc.i4.4
    ldarg.1
    stelem.ref
    ldloc grown
    ret
  }

IL;
}

/**
 * Target-side twin of the compile-time display-path normalizers: the baked
 * `displayPath` strings pass through this at report time, so its segment
 * vocabulary must stay in sync with `Debug\normalizeDisplayPath` (flat `lib/`
 * and packaged `libraries/` layouts, plus the test/example trees) — a path
 * the two normalize differently prints twice, once per spelling.
 */
function rtDisplaySourcePathIl(): string
{
    return <<<'IL'
  .method public hidebysig static string DisplaySourcePath(string file) cil managed
  {
    .maxstack 8
    .locals init (string norm, int32 pos)

    ldarg.0
    brtrue NonNull
    ldstr ""
    ret
    NonNull:
    ldarg.0
    callvirt instance int32 [System.Runtime]System.String::get_Length()
    brfalse AsIs
    ldarg.0
    ldstr "<interactive>"
    call bool [System.Runtime]System.String::op_Equality(string, string)
    brfalse Normalize
    AsIs:
    ldarg.0
    ret
    Normalize:
    ldarg.0
    ldc.i4.s 92
    ldc.i4.s 47
    callvirt instance string [System.Runtime]System.String::Replace(char, char)
    stloc norm

    ldloc norm
    ldstr "/lib/"
    callvirt instance int32 [System.Runtime]System.String::IndexOf(string)
    stloc pos
    ldloc pos
    ldc.i4.0
    blt TryTests
    ldloc norm
    ldloc pos
    ldc.i4.1
    add
    callvirt instance string [System.Runtime]System.String::Substring(int32)
    ret
    TryTests:
    ldloc norm
    ldstr "/tests/"
    callvirt instance int32 [System.Runtime]System.String::IndexOf(string)
    stloc pos
    ldloc pos
    ldc.i4.0
    blt TryLibraries
    ldloc norm
    ldloc pos
    ldc.i4.1
    add
    callvirt instance string [System.Runtime]System.String::Substring(int32)
    ret
    TryLibraries:
    ldloc norm
    ldstr "/libraries/"
    callvirt instance int32 [System.Runtime]System.String::IndexOf(string)
    stloc pos
    ldloc pos
    ldc.i4.0
    blt TryRelLib
    ldloc norm
    ldloc pos
    ldc.i4.1
    add
    callvirt instance string [System.Runtime]System.String::Substring(int32)
    ret
    TryRelLib:
    ldloc norm
    ldstr "lib/"
    callvirt instance bool [System.Runtime]System.String::StartsWith(string)
    brtrue RelOk
    ldloc norm
    ldstr "libraries/"
    callvirt instance bool [System.Runtime]System.String::StartsWith(string)
    brtrue RelOk
    ldloc norm
    ldstr "tests/"
    callvirt instance bool [System.Runtime]System.String::StartsWith(string)
    brfalse Basename
    RelOk:
    ldloc norm
    ret
    Basename:
    ldloc norm
    call string [System.Runtime]System.IO.Path::GetFileName(string)
    ret
  }

IL;
}

function rtAppendFrameReportIl(): string
{
    return <<<'IL'
  .method public hidebysig static void AppendFrameReport(class [System.Runtime]System.Text.StringBuilder sb, object[] site) cil managed
  {
    .maxstack 8
    .locals init (string symbolId, string file, int32 line, int32 col)

    ldarg.1
    brfalse Done
    ldarg.1
    ldlen
    conv.i4
    ldc.i4.5
    blt Done

    ldarg.1
    ldc.i4.1
    ldelem.ref
    castclass string
    stloc symbolId
    ldarg.1
    ldc.i4.2
    ldelem.ref
    castclass string
    call string Moggi.Rt.RT::DisplaySourcePath(string)
    stloc file
    ldarg.1
    ldc.i4.3
    ldelem.ref
    unbox.any [System.Runtime]System.Int32
    stloc line
    ldarg.1
    ldc.i4.4
    ldelem.ref
    unbox.any [System.Runtime]System.Int32
    stloc col

    ldloc file
    callvirt instance int32 [System.Runtime]System.String::get_Length()
    brfalse NoFile
    ldloc line
    ldc.i4.0
    ble NoFile

    ldarg.0
    ldstr "  at "
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldloc symbolId
    callvirt instance int32 [System.Runtime]System.String::get_Length()
    brtrue UseSym
    ldstr "<unknown>"
    br HaveSym
    UseSym:
    ldloc symbolId
    HaveSym:
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldstr " ("
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldloc file
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldc.i4.s 58
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(char)
    ldloc line
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(int32)
    ldc.i4.s 58
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(char)
    ldloc col
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(int32)
    ldc.i4.s 41
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(char)
    ldc.i4.s 10
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(char)
    pop
    ret

    NoFile:
    ldloc symbolId
    callvirt instance int32 [System.Runtime]System.String::get_Length()
    brfalse Done
    ldarg.0
    ldstr "  at "
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldloc symbolId
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldc.i4.s 10
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(char)
    pop
    Done:
    ret
  }

IL;
}

function rtAppendMoggFramesIl(): string
{
    return <<<'IL'
  .method public hidebysig static void AppendMoggFrames(class [System.Runtime]System.Text.StringBuilder sb, class [System.Runtime]System.Exception e, object[] throwSite) cil managed
  {
    .maxstack 8
    .locals init (
      class [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace st,
      class [System.Diagnostics.StackTrace]System.Diagnostics.StackFrame[] frames,
      string siteText,
      int32 limit,
      int32 i,
      int32 shown,
      class [System.Diagnostics.StackTrace]System.Diagnostics.StackFrame f,
      class [System.Runtime]System.Reflection.MethodBase mb,
      class [System.Runtime]System.Type dt,
      string typeName,
      string methodName,
      int32 ilOff,
      string text,
      class [System.Runtime]System.Text.StringBuilder siteSb,
      class Moggi.Rt.MoggiException me,
      class [System.Runtime]System.Exception cause)

    ldarg.1
    brtrue HasE
    ret

    HasE:
    ldnull
    stloc st
    ldarg.1
    isinst Moggi.Rt.MoggiException
    stloc me
    ldloc me
    brfalse BuiltTrace
    ldloc me
    callvirt instance class [System.Runtime]System.Exception [System.Runtime]System.Exception::get_InnerException()
    stloc cause
    ldloc cause
    brfalse OwnTrace
    ldloc cause
    newobj instance void [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace::.ctor(class [System.Runtime]System.Exception)
    stloc st
    br TraceReady

    OwnTrace:
    ldloc me
    ldfld class [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace Moggi.Rt.MoggiException::originalTrace
    stloc st
    ldloc st
    brtrue TraceReady

    BuiltTrace:
    ldarg.1
    newobj instance void [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace::.ctor(class [System.Runtime]System.Exception)
    stloc st

    TraceReady:
    ldloc st
    callvirt instance class [System.Diagnostics.StackTrace]System.Diagnostics.StackFrame[] [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace::GetFrames()
    stloc frames

    ldnull
    stloc siteText
    ldarg.2
    brfalse NoSite
    newobj instance void [System.Runtime]System.Text.StringBuilder::.ctor()
    stloc siteSb
    ldloc siteSb
    ldarg.2
    call void Moggi.Rt.RT::AppendFrameReport(class [System.Runtime]System.Text.StringBuilder, object[])
    ldloc siteSb
    callvirt instance string [System.Runtime]System.Object::ToString()
    stloc siteText
    ldc.i4.s 49
    stloc limit
    br AfterLimit

    NoSite:
    ldc.i4.s 50
    stloc limit

    AfterLimit:
    ldc.i4.0
    stloc shown
    ldc.i4.0
    stloc i

    Loop:
    ldloc frames
    brfalse Done
    ldloc i
    ldloc frames
    ldlen
    conv.i4
    bge Done
    ldloc frames
    ldloc i
    ldelem.ref
    stloc f

    ldloc f
    callvirt instance class [System.Runtime]System.Reflection.MethodBase [System.Diagnostics.StackTrace]System.Diagnostics.StackFrame::GetMethod()
    stloc mb
    ldloc mb
    brfalse Next
    ldloc mb
    callvirt instance class [System.Runtime]System.Type [System.Runtime]System.Reflection.MemberInfo::get_DeclaringType()
    stloc dt
    ldloc dt
    brfalse Next
    ldloc dt
    callvirt instance string [System.Runtime]System.Type::get_FullName()
    stloc typeName
    ldloc typeName
    brfalse Next

    ldloc typeName
    ldstr "Moggi.Rt."
    callvirt instance bool [System.Runtime]System.String::StartsWith(string)
    brtrue Next
    ldloc typeName
    ldstr "Moggi.Frames"
    call bool [System.Runtime]System.String::op_Equality(string, string)
    brtrue Next

    ldloc mb
    callvirt instance string [System.Runtime]System.Reflection.MemberInfo::get_Name()
    stloc methodName
    ldloc f
    callvirt instance int32 [System.Diagnostics.StackTrace]System.Diagnostics.StackFrame::GetILOffset()
    stloc ilOff
    ldloc ilOff
    ldc.i4.0
    blt Next

    ldloc typeName
    ldloc methodName
    ldloc ilOff
    call string Moggi.Frames::Lookup(string, string, int32)
    stloc text
    ldloc text
    brfalse Next

    ldloc siteText
    brfalse UseFrame
    ldloc text
    ldloc siteText
    call bool [System.Runtime]System.String::op_Equality(string, string)
    brtrue Next

    UseFrame:
    ldloc shown
    ldloc limit
    bge Counted
    ldarg.0
    ldloc text
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    pop

    Counted:
    ldloc shown
    ldc.i4.1
    add
    stloc shown

    Next:
    ldloc i
    ldc.i4.1
    add
    stloc i
    br Loop

    Done:
    ldloc limit
    ldloc shown
    bge DoneAll
    ldarg.0
    ldstr "  ... "
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldloc shown
    ldloc limit
    sub
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(int32)
    ldstr " more\n"
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    pop

    DoneAll:
    ret
  }

IL;
}

function rtAppendHostFramesIl(): string
{
    return <<<'IL'
  .method public hidebysig static void AppendHostFrames(class [System.Runtime]System.Text.StringBuilder sb, class [System.Runtime]System.Exception e) cil managed
  {
    .maxstack 8
    .locals init (
      class [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace st,
      class [System.Diagnostics.StackTrace]System.Diagnostics.StackFrame[] frames,
      int32 i,
      int32 shown,
      int32 limit,
      class [System.Diagnostics.StackTrace]System.Diagnostics.StackFrame f,
      class [System.Runtime]System.Reflection.MethodBase mb,
      class [System.Runtime]System.Type dt,
      string typeName,
      int32 ilOff)

    ldarg.1
    brtrue HasE
    ret

    HasE:
    ldarg.1
    newobj instance void [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace::.ctor(class [System.Runtime]System.Exception)
    stloc st
    ldloc st
    callvirt instance class [System.Diagnostics.StackTrace]System.Diagnostics.StackFrame[] [System.Diagnostics.StackTrace]System.Diagnostics.StackTrace::GetFrames()
    stloc frames
    ldc.i4.s 50
    stloc limit
    ldc.i4.0
    stloc shown
    ldc.i4.0
    stloc i

    Loop:
    ldloc frames
    brfalse Done
    ldloc i
    ldloc frames
    ldlen
    conv.i4
    bge Done
    ldloc frames
    ldloc i
    ldelem.ref
    stloc f

    ldloc f
    callvirt instance class [System.Runtime]System.Reflection.MethodBase [System.Diagnostics.StackTrace]System.Diagnostics.StackFrame::GetMethod()
    stloc mb
    ldloc mb
    brfalse Next
    ldloc mb
    callvirt instance class [System.Runtime]System.Type [System.Runtime]System.Reflection.MemberInfo::get_DeclaringType()
    stloc dt
    ldloc dt
    brfalse Next
    ldloc dt
    callvirt instance string [System.Runtime]System.Type::get_FullName()
    stloc typeName
    ldloc typeName
    brfalse Next
    ldloc f
    callvirt instance int32 [System.Diagnostics.StackTrace]System.Diagnostics.StackFrame::GetILOffset()
    stloc ilOff
    ldloc ilOff
    ldc.i4.0
    blt Next

    ldloc shown
    ldloc limit
    bge Elide

    ldarg.0
    ldstr "  #"
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldloc shown
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(int32)
    ldc.i4.s 32
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(char)
    ldloc typeName
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldc.i4.s 46
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(char)
    ldloc mb
    callvirt instance string [System.Runtime]System.Reflection.MemberInfo::get_Name()
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldstr " (il:"
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldloc ilOff
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(int32)
    ldstr ")\n"
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    pop
    ldloc shown
    ldc.i4.1
    add
    stloc shown

    Next:
    ldloc i
    ldc.i4.1
    add
    stloc i
    br Loop

    Elide:
    ldarg.0
    ldstr "  ... "
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    ldloc frames
    ldlen
    conv.i4
    ldloc i
    sub
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(int32)
    ldstr " more\n"
    callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
    pop
    ret

    Done:
    ret
  }

IL;
}

function rtFormatExceptionReportIl(): string
{
    return <<<'IL'
  .method public hidebysig static string FormatExceptionReport(class [System.Runtime]System.Exception e) cil managed
  {
    .maxstack 8
    .locals init (
      class Moggi.Rt.MoggiException me,
      class [System.Runtime]System.Text.StringBuilder sb,
      string tag,
      class [System.Runtime]System.Exception cause,
      string result)

    .try
    {
      ldarg.0
      isinst Moggi.Rt.MoggiException
      brtrue IsMe
      ldarg.0
      call object[] Moggi.Rt.RT::NormalizeHostException(class [System.Runtime]System.Exception)
      ldnull
      ldarg.0
      newobj instance void Moggi.Rt.MoggiException::.ctor(object[], object[], class [System.Runtime]System.Exception)
      call string Moggi.Rt.RT::FormatExceptionReport(class [System.Runtime]System.Exception)
      stloc result
      leave Done

      IsMe:
      ldarg.0
      castclass Moggi.Rt.MoggiException
      stloc me
      newobj instance void [System.Runtime]System.Text.StringBuilder::.ctor()
      stloc sb
      ldloc me
      ldfld object[] Moggi.Rt.MoggiException::someException
      ldc.i4.1
      ldelem.ref
      castclass string
      stloc tag

      ldloc sb
      ldstr "moggi: "
      callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
      ldloc tag
      callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
      ldstr ": "
      callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
      ldloc me
      callvirt instance string [System.Runtime]System.Exception::get_Message()
      callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
      ldc.i4.s 10
      callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(char)
      pop

      ldloc sb
      ldloc me
      ldfld object[] Moggi.Rt.MoggiException::throwSite
      call void Moggi.Rt.RT::AppendFrameReport(class [System.Runtime]System.Text.StringBuilder, object[])

      ldloc sb
      ldloc me
      ldloc me
      ldfld object[] Moggi.Rt.MoggiException::throwSite
      call void Moggi.Rt.RT::AppendMoggFrames(class [System.Runtime]System.Text.StringBuilder, class [System.Runtime]System.Exception, object[])

      ldloc me
      callvirt instance class [System.Runtime]System.Exception [System.Runtime]System.Exception::get_InnerException()
      stloc cause
      ldloc cause
      brfalse NoHost
      ldloc sb
      ldstr "caused by: "
      callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
      ldloc cause
      callvirt instance class [System.Runtime]System.Type [System.Runtime]System.Object::GetType()
      callvirt instance string [System.Runtime]System.Type::get_FullName()
      callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
      ldstr ": "
      callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
      ldloc cause
      callvirt instance string [System.Runtime]System.Exception::get_Message()
      callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(string)
      ldc.i4.s 10
      callvirt instance class [System.Runtime]System.Text.StringBuilder [System.Runtime]System.Text.StringBuilder::Append(char)
      pop
      ldloc sb
      ldloc cause
      call void Moggi.Rt.RT::AppendHostFrames(class [System.Runtime]System.Text.StringBuilder, class [System.Runtime]System.Exception)
      NoHost:
      ldloc sb
      callvirt instance string [System.Runtime]System.Object::ToString()
      stloc result
      leave Done
    }
    catch [System.Runtime]System.Exception
    {
      pop
      ldstr "moggi: error: failed to format exception report\n"
      stloc result
      leave Done
    }
    Done:
    ldloc result
    ret
  }

IL;
}

function rtReportUncaughtIl(): string
{
    return <<<'IL'
  .method public hidebysig static void ReportUncaught(class [System.Runtime]System.Exception e) cil managed
  {
    .maxstack 8
    call class [System.Runtime]System.IO.TextWriter [System.Console]System.Console::get_Error()
    ldarg.0
    call string Moggi.Rt.RT::FormatExceptionReport(class [System.Runtime]System.Exception)
    callvirt instance void [System.Runtime]System.IO.TextWriter::Write(string)
    ldc.i4.1
    call void [System.Runtime]System.Environment::Exit(int32)
    ret
  }

IL;
}

function rtThrowSomeExceptionIl(): string
{
    return <<<'IL'
  .method public hidebysig static object ThrowSomeException(object se, object[] throwSite) cil managed
  {
    .maxstack 8
    .locals init (object[] arr, object payload, class Moggi.Rt.MoggiException origin)

    ldarg.0
    isinst Moggi.Rt.MoggiException
    brfalse NotMe
    ldarg.0
    castclass [System.Runtime]System.Exception
    call class [System.Runtime]System.Runtime.ExceptionServices.ExceptionDispatchInfo [System.Runtime]System.Runtime.ExceptionServices.ExceptionDispatchInfo::Capture(class [System.Runtime]System.Exception)
    callvirt instance void [System.Runtime]System.Runtime.ExceptionServices.ExceptionDispatchInfo::Throw()
    ldnull
    throw

    NotMe:
    ldarg.0
    isinst object[]
    brfalse WrapHost
    ldarg.0
    castclass object[]
    stloc arr
    ldloc arr
    ldlen
    conv.i4
    ldc.i4.3
    blt WrapHost
    ldloc arr
    ldc.i4.0
    ldelem.ref
    ldstr "__se"
    call bool [System.Runtime]System.Object::Equals(object, object)
    brfalse WrapHost
    ldloc arr
    ldlen
    conv.i4
    ldc.i4.4
    ble FreshSe
    ldloc arr
    ldc.i4.4
    ldelem.ref
    stloc origin
    ldloc origin
    isinst Moggi.Rt.MoggiException
    brfalse FreshSe
    ldloc origin
    call class [System.Runtime]System.Runtime.ExceptionServices.ExceptionDispatchInfo [System.Runtime]System.Runtime.ExceptionServices.ExceptionDispatchInfo::Capture(class [System.Runtime]System.Exception)
    callvirt instance void [System.Runtime]System.Runtime.ExceptionServices.ExceptionDispatchInfo::Throw()
    ldnull
    throw

    FreshSe:
    ldloc arr
    ldarg.1
    ldnull
    newobj instance void Moggi.Rt.MoggiException::.ctor(object[], object[], class [System.Runtime]System.Exception)
    throw

    WrapHost:
    ldarg.0
    callvirt instance string [System.Runtime]System.Object::ToString()
    stloc payload
    ldstr "HostException"
    ldc.i4.3
    newarr object
    dup
    ldc.i4.0
    ldstr "dotnet"
    stelem.ref
    dup
    ldc.i4.1
    ldstr "unknown"
    stelem.ref
    dup
    ldc.i4.2
    ldloc payload
    stelem.ref
    call class Moggi.Rt.Con Moggi.Rt.RT::Con(string, object[])
    stloc payload
    ldstr "HostException"
    ldnull
    ldloc payload
    call object[] Moggi.Rt.RT::ExceptionWrap(string, string, object)
    stloc arr
    ldloc arr
    ldarg.1
    ldnull
    newobj instance void Moggi.Rt.MoggiException::.ctor(object[], object[], class [System.Runtime]System.Exception)
    throw
  }

IL;
}

function rtThrowErrorCallIl(): string
{
    return <<<'IL'
  .method public hidebysig static object ThrowErrorCall(string msg, object[] throwSite) cil managed
  {
    .maxstack 8
    .locals init (object payload, object[] se)

    ldstr "ErrorCall"
    ldc.i4.1
    newarr object
    dup
    ldc.i4.0
    ldarg.0
    stelem.ref
    call class Moggi.Rt.Con Moggi.Rt.RT::Con(string, object[])
    stloc payload
    ldstr "ErrorCall"
    ldnull
    ldloc payload
    call object[] Moggi.Rt.RT::ExceptionWrap(string, string, object)
    stloc se
    ldloc se
    ldarg.1
    ldnull
    newobj instance void Moggi.Rt.MoggiException::.ctor(object[], object[], class [System.Runtime]System.Exception)
    throw
  }

IL;
}

/**
 * `Int` absolute value: `Math.Abs` throws on `minBound`, which base returns
 * unchanged (it is its own negation in two's complement).
 */
function rtIntAbsIl(): string
{
    return <<<'IL'
  .method public hidebysig static int64 IntAbs(int64 a) cil managed
  {
    .maxstack 2

    ldarg.0
    ldc.i4.0
    conv.i8
    bge int_abs_done
    ldarg.0
    neg
    ret
  int_abs_done:
    ldarg.0
    ret
  }

IL;
}

/**
 * `Double` exponentiation on unboxed floats, in the library body's own
 * multiplication order, so the value is the one the dictionaries produce.
 */
function rtNaturalFromIntegerIl(): string
{
    return <<<'IL'
  .method public hidebysig static valuetype [System.Runtime.Numerics]System.Numerics.BigInteger NaturalFromInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger v, object[] throwSite) cil managed
  {
    .maxstack 4

    ldarga.s 0
    call instance int32 [System.Runtime.Numerics]System.Numerics.BigInteger::get_Sign()
    ldc.i4.0
    bge.s natural_non_negative
    ldstr "arithmetic underflow"
    ldarg.1
    call object Moggi.Rt.RT::ThrowErrorCall(string, object[])
    pop
  natural_non_negative:
    ldarg.0
    ret
  }

IL;
}

function rtNormalizeHostExceptionIl(): string
{
    return <<<'IL'
  .method public hidebysig static object[] NormalizeHostException(class [System.Runtime]System.Exception e) cil managed
  {
    .maxstack 8
    .locals init (string msg, string nativeType, object payload)

    ldarg.0
    isinst Moggi.Rt.MoggiException
    brfalse NotMe
    ldarg.0
    castclass Moggi.Rt.MoggiException
    ldfld object[] Moggi.Rt.MoggiException::someException
    ret

    NotMe:
    ldarg.0
    callvirt instance string [System.Runtime]System.Exception::get_Message()
    stloc msg
    ldloc msg
    brtrue HaveMsg
    ldstr ""
    stloc msg

    HaveMsg:
    ldarg.0
    callvirt instance class [System.Runtime]System.Type [System.Runtime]System.Exception::GetType()
    callvirt instance string [System.Runtime]System.Type::get_FullName()
    stloc nativeType
    ldloc nativeType
    brtrue HaveNative
    ldstr "System.Exception"
    stloc nativeType
    HaveNative:
    ldstr "HostException"
    ldc.i4.3
    newarr object
    dup
    ldc.i4.0
    ldstr "dotnet"
    stelem.ref
    dup
    ldc.i4.1
    ldloc nativeType
    stelem.ref
    dup
    ldc.i4.2
    ldloc msg
    stelem.ref
    call class Moggi.Rt.Con Moggi.Rt.RT::Con(string, object[])
    stloc payload
    ldstr "HostException"
    ldnull
    ldloc payload
    call object[] Moggi.Rt.RT::ExceptionWrap(string, string, object)
    ret
  }

IL;
}

function rtIoCatchIl(): string
{
    return <<<'IL'
  .method public hidebysig static object IoCatch(class Moggi.Rt.IO action, object hnd) cil managed
  {
    .maxstack 8
    .locals init (object result, object se, object handlerIo, class Moggi.Rt.MoggiException wrapped, class [System.Runtime]System.Exception ex)

    .try
    {
      ldarg.0
      call object Moggi.Rt.RT::IoRun(object)
      stloc result
      leave Done
    }
    catch [System.Runtime]System.Exception
    {
      stloc ex
      ldloc ex
      isinst Moggi.Rt.MoggiException
      brfalse Wrap
      ldloc ex
      castclass Moggi.Rt.MoggiException
      stloc wrapped
      ldloc wrapped
      ldfld object[] Moggi.Rt.MoggiException::someException
      ldloc wrapped
      call object[] Moggi.Rt.RT::AttachWrapper(object[], class Moggi.Rt.MoggiException)
      stloc se
      br Dispatch
      Wrap:
      ldloc ex
      call object[] Moggi.Rt.RT::NormalizeHostException(class [System.Runtime]System.Exception)
      ldnull
      ldloc ex
      newobj instance void Moggi.Rt.MoggiException::.ctor(object[], object[], class [System.Runtime]System.Exception)
      stloc wrapped
      ldloc wrapped
      ldfld object[] Moggi.Rt.MoggiException::someException
      ldloc wrapped
      call object[] Moggi.Rt.RT::AttachWrapper(object[], class Moggi.Rt.MoggiException)
      stloc se
      Dispatch:
      ldarg.1
      ldc.i4.1
      newarr object
      dup
      ldc.i4.0
      ldloc se
      stelem.ref
      call object Moggi.Rt.RT::Apply(object, object[])
      stloc handlerIo
      ldloc handlerIo
      castclass Moggi.Rt.IO
      call object Moggi.Rt.RT::IoRun(object)
      stloc result
      leave Done
    }
    Done:
    ldloc result
    ret
  }

IL;
}

function rtIoFinallyIl(): string
{
    return <<<'IL'
  .method public hidebysig static object IoFinally(class Moggi.Rt.IO action, class Moggi.Rt.IO cleanup) cil managed
  {
    .maxstack 8
    .locals init (class [System.Runtime]System.Exception saved, object result)

    ldnull
    stloc saved
    ldnull
    stloc result

    .try
    {
      ldarg.0
      call object Moggi.Rt.RT::IoRun(object)
      stloc result
      leave AfterTry1
    }
    catch [System.Runtime]System.Exception
    {
      stloc saved
      leave AfterTry1
    }
    AfterTry1:

    .try
    {
      ldarg.1
      call object Moggi.Rt.RT::IoRun(object)
      pop
      leave AfterTry2
    }
    catch [System.Runtime]System.Exception
    {
      call object[] Moggi.Rt.RT::NormalizeHostException(class [System.Runtime]System.Exception)
      ldnull
      call object Moggi.Rt.RT::ThrowSomeException(object, object[])
      leave AfterTry2
    }
    AfterTry2:

    ldloc saved
    brfalse Ok
    ldloc saved
    call object[] Moggi.Rt.RT::NormalizeHostException(class [System.Runtime]System.Exception)
    ldnull
    call object Moggi.Rt.RT::ThrowSomeException(object, object[])
    ret
    Ok:
    ldloc result
    ret
  }

IL;
}

/** Already-evaluated IO value (eager boxing MVP for straight-line main). */
function rtIoPureIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.IO IoPure(object v) cil managed
  {
    .maxstack 8
    ldarg.0
    call class Moggi.Rt.Fn Moggi.Rt.RT::ConstFn(object)
    newobj instance void Moggi.Rt.IO::.ctor(object)
    ret
  }

IL;
}

/** Already-evaluated ST value: `stPure` wraps it so binds sequence over it. */
function rtStPureIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ST StPure(object v) cil managed
  {
    .maxstack 8
    ldarg.0
    call class Moggi.Rt.Fn Moggi.Rt.RT::ConstFn(object)
    newobj instance void Moggi.Rt.ST::.ctor(object)
    ret
  }

IL;
}

/** Sequenced ST descriptions: run `m`, feed the value through `k`, run the result. */
function rtStBindIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ST StBind(class Moggi.Rt.ST m, object k) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    newobj instance void Moggi.Rt.StBindFn::.ctor(class Moggi.Rt.ST, object)
    newobj instance void Moggi.Rt.ST::.ctor(object)
    ret
  }

IL;
}

/** Run an ST description now and answer its value. */
function rtStRunIl(): string
{
    return <<<'IL'
  .method public hidebysig static object StRun(class Moggi.Rt.ST st) cil managed
  {
    .maxstack 8
    ldarg.0
    brtrue Run
    ldnull
    ret
    Run:
    ldarg.0
    ldfld object Moggi.Rt.ST::action
    ldc.i4.0
    newarr object
    call object Moggi.Rt.RT::Apply(object, object[])
    ret
  }

IL;
}

/** An `ST RealWorld` description run as `IO`: the same thunk under IO's class. */
function rtStToIOIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.IO StToIO(class Moggi.Rt.ST st) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld object Moggi.Rt.ST::action
    newobj instance void Moggi.Rt.IO::.ctor(object)
    ret
  }

IL;
}

/** Cell allocation as an ST description (allocates when run). */
function rtStNewRefIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ST StNewRef(object v) cil managed
  {
    .maxstack 8
    ldarg.0
    newobj instance void Moggi.Rt.NewRefFn::.ctor(object)
    newobj instance void Moggi.Rt.ST::.ctor(object)
    ret
  }

IL;
}

/** Cell read as an ST description (reads when run). */
function rtStReadRefIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ST StReadRef(class Moggi.Rt.STRef r) cil managed
  {
    .maxstack 8
    ldarg.0
    newobj instance void Moggi.Rt.ReadRefFn::.ctor(class Moggi.Rt.STRef)
    newobj instance void Moggi.Rt.ST::.ctor(object)
    ret
  }

IL;
}

/** Cell write as an ST description (writes when run, answering Unit). */
function rtStWriteRefIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ST StWriteRef(class Moggi.Rt.STRef r, object v) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    newobj instance void Moggi.Rt.WriteRefFn::.ctor(class Moggi.Rt.STRef, object)
    newobj instance void Moggi.Rt.ST::.ctor(object)
    ret
  }

IL;
}

/** Fn that ignores args and returns a constant (for IoPure). */
/** Unbox a size to int32, raising on negative or oversized values. */
function rtArrCheckedSizeIl(): string
{
    return <<<'IL'
  .method public hidebysig static int32 ArrCheckedSize(object nObj) cil managed
  {
    .maxstack 8
    .locals init (int64 n)
    ldarg.0
    call int64 Moggi.Rt.RT::UnboxInt(object)
    stloc.0
    ldloc.0
    ldc.i4.0
    conv.i8
    blt Neg
    ldloc.0
    conv.i4
    conv.i8
    ldloc.0
    bne.un Large
    ldloc.0
    conv.i4
    ret
Neg:
    ldloc.0
    call void Moggi.Rt.RT::ArrayNegSize(int64)
    ldc.i4.0
    ret
Large:
    ldloc.0
    call void Moggi.Rt.RT::ArrayTooLarge(int64)
    ldc.i4.0
    ret
  }

IL;
}

/** Unbox an offset to int32 (range-checked by the caller). */
function rtArrOffsetIl(): string
{
    return <<<'IL'
  .method public hidebysig static int32 ArrOffset(object offObj) cil managed
  {
    .maxstack 8
    ldarg.0
    call int64 Moggi.Rt.RT::UnboxInt(object)
    conv.i4
    ret
  }

IL;
}

/** Allocate hole-filled slots, raising on a negative size. */
function rtArrAllocSlotsIl(): string
{
    return <<<'IL'
  .method public hidebysig static object[] ArrAllocSlots(int32 n) cil managed
  {
    .maxstack 8
    .locals init (object[] slots, int32 i)
    ldarg.0
    ldc.i4.0
    bge Ok
    ldarg.0
    conv.i8
    call void Moggi.Rt.RT::ArrayNegSize(int64)
    ldnull
    ret
Ok:
    ldarg.0
    newarr object
    stloc.0
    ldc.i4.0
    stloc.1
Loop:
    ldloc.1
    ldarg.0
    bge Done
    ldloc.0
    ldloc.1
    newobj instance void Moggi.Rt.ArrHole::.ctor()
    stelem.ref
    ldloc.1
    ldc.i4.1
    add
    stloc.1
    br Loop
Done:
    ldloc.0
    ret
  }

IL;
}

/** Raise unless 0 <= off < n. */
function rtArrRangeCheckIl(): string
{
    return <<<'IL'
  .method public hidebysig static void ArrRangeCheck(int32 n, int32 idx, string op) cil managed
  {
    .maxstack 8
    ldarg.1
    ldc.i4.0
    blt Err
    ldarg.1
    ldarg.0
    bge Err
    ret
Err:
    ldarg.2
    ldarg.1
    conv.i8
    ldarg.0
    conv.i8
    call void Moggi.Rt.RT::ArrayRange(string, int64, int64)
    ret
  }

IL;
}

/** Read a slot, raising on a hole. */
function rtArrReadSlotIl(): string
{
    return <<<'IL'
  .method public hidebysig static object ArrReadSlot(object[] elems, int32 idx, string op) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    ldelem.ref
    dup
    isinst Moggi.Rt.ArrHole
    brfalse Ok
    pop
    ldarg.2
    ldarg.1
    conv.i8
    call void Moggi.Rt.RT::ArrayHole(string, int64)
    ldnull
    ret
Ok:
    ret
  }

IL;
}

/** Duplicate slots. */
function rtArrCopySlotsIl(): string
{
    return <<<'IL'
  .method public hidebysig static object[] ArrCopySlots(object[] elems, int32 n) cil managed
  {
    .maxstack 8
    .locals init (object[] copy)
    ldarg.1
    newarr object
    stloc.0
    ldarg.0
    ldc.i4.0
    ldloc.0
    ldc.i4.0
    ldarg.1
    call void [System.Runtime]System.Array::Copy(class [System.Runtime]System.Array, int32, class [System.Runtime]System.Array, int32, int32)
    ldloc.0
    ret
  }

IL;
}

/** Throw a negative-size error. */
function rtArrayNegSizeIl(): string
{
    return <<<'IL'
  .method public hidebysig static void ArrayNegSize(int64 n) cil managed
  {
    .maxstack 8
    ldstr "array: negative size "
    ldarg.0
    box int64
    callvirt instance string [System.Runtime]System.Object::ToString()
    call string [System.Runtime]System.String::Concat(string, string)
    ldc.i4.0
    newarr object
    call object Moggi.Rt.RT::ThrowErrorCall(string, object[])
    pop
    ret
  }

IL;
}

/** Throw an oversized-size error. */
function rtArrayTooLargeIl(): string
{
    return <<<'IL'
  .method public hidebysig static void ArrayTooLarge(int64 n) cil managed
  {
    .maxstack 8
    ldstr "array: size "
    ldarg.0
    box int64
    callvirt instance string [System.Runtime]System.Object::ToString()
    call string [System.Runtime]System.String::Concat(string, string)
    ldstr " exceeds the maximum array size"
    call string [System.Runtime]System.String::Concat(string, string)
    ldc.i4.0
    newarr object
    call object Moggi.Rt.RT::ThrowErrorCall(string, object[])
    pop
    ret
  }

IL;
}

/** Throw an out-of-range error. */
function rtArrayRangeIl(): string
{
    return <<<'IL'
  .method public hidebysig static void ArrayRange(string op, int64 idx, int64 n) cil managed
  {
    .maxstack 8
    ldstr "array "
    ldarg.0
    call string [System.Runtime]System.String::Concat(string, string)
    ldstr ": offset "
    call string [System.Runtime]System.String::Concat(string, string)
    ldarg.1
    box int64
    callvirt instance string [System.Runtime]System.Object::ToString()
    call string [System.Runtime]System.String::Concat(string, string)
    ldstr " out of range for size "
    call string [System.Runtime]System.String::Concat(string, string)
    ldarg.2
    box int64
    callvirt instance string [System.Runtime]System.Object::ToString()
    call string [System.Runtime]System.String::Concat(string, string)
    ldc.i4.0
    newarr object
    call object Moggi.Rt.RT::ThrowErrorCall(string, object[])
    pop
    ret
  }

IL;
}

/** Throw an undefined-element error. */
function rtArrayHoleIl(): string
{
    return <<<'IL'
  .method public hidebysig static void ArrayHole(string op, int64 idx) cil managed
  {
    .maxstack 8
    ldstr "array "
    ldarg.0
    call string [System.Runtime]System.String::Concat(string, string)
    ldstr ": undefined element at offset "
    call string [System.Runtime]System.String::Concat(string, string)
    ldarg.1
    box int64
    callvirt instance string [System.Runtime]System.Object::ToString()
    call string [System.Runtime]System.String::Concat(string, string)
    ldc.i4.0
    newarr object
    call object Moggi.Rt.RT::ThrowErrorCall(string, object[])
    pop
    ret
  }

IL;
}

function rtArrayNewIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ArrArray ArrayNew(object bounds, object n) cil managed
  {
    .maxstack 8
    .locals init (int32 size)
    ldarg.1
    call int32 Moggi.Rt.RT::ArrCheckedSize(object)
    stloc.0
    ldarg.0
    ldloc.0
    ldloc.0
    call object[] Moggi.Rt.RT::ArrAllocSlots(int32)
    newobj instance void Moggi.Rt.ArrArray::.ctor(object, int32, object[])
    ret
  }

IL;
}

function rtArraySizeIl(): string
{
    return <<<'IL'
  .method public hidebysig static object ArraySize(class Moggi.Rt.ArrArray arr) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld int32 Moggi.Rt.ArrArray::n
    conv.i8
    call object Moggi.Rt.RT::BoxInt(int64)
    ret
  }

IL;
}

function rtArrayBoundsIl(): string
{
    return <<<'IL'
  .method public hidebysig static object ArrayBounds(class Moggi.Rt.ArrArray arr) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld object Moggi.Rt.ArrArray::bounds
    ret
  }

IL;
}

function rtArrayReadIl(): string
{
    return <<<'IL'
  .method public hidebysig static object ArrayRead(class Moggi.Rt.ArrArray arr, object idx) cil managed
  {
    .maxstack 8
    .locals init (int32 n, int32 i)
    ldarg.0
    ldfld int32 Moggi.Rt.ArrArray::n
    stloc.0
    ldarg.1
    call int32 Moggi.Rt.RT::ArrOffset(object)
    stloc.1
    ldloc.0
    ldloc.1
    ldstr "read"
    call void Moggi.Rt.RT::ArrRangeCheck(int32, int32, string)
    ldarg.0
    ldfld object[] Moggi.Rt.ArrArray::elems
    ldloc.1
    ldstr "read"
    call object Moggi.Rt.RT::ArrReadSlot(object[], int32, string)
    ret
  }

IL;
}

function rtStNewDirectIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ArrSTArray StNewDirect(object bounds, object n) cil managed
  {
    .maxstack 8
    .locals init (int32 size)
    ldarg.1
    call int32 Moggi.Rt.RT::ArrCheckedSize(object)
    stloc.0
    ldarg.0
    ldloc.0
    ldloc.0
    call object[] Moggi.Rt.RT::ArrAllocSlots(int32)
    newobj instance void Moggi.Rt.ArrSTArray::.ctor(object, int32, object[])
    ret
  }

IL;
}

/** Mutable allocation as an ST description (allocates when run). */
function rtStNewArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ST StNewArray(object bounds, object n) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    newobj instance void Moggi.Rt.NewSTArrayFn::.ctor(object, object)
    newobj instance void Moggi.Rt.ST::.ctor(object)
    ret
  }

IL;
}

function rtStReadDirectIl(): string
{
    return <<<'IL'
  .method public hidebysig static object StReadDirect(class Moggi.Rt.ArrSTArray arr, object idx) cil managed
  {
    .maxstack 8
    .locals init (int32 n, int32 i)
    ldarg.0
    ldfld int32 Moggi.Rt.ArrSTArray::n
    stloc.0
    ldarg.1
    call int32 Moggi.Rt.RT::ArrOffset(object)
    stloc.1
    ldloc.0
    ldloc.1
    ldstr "read"
    call void Moggi.Rt.RT::ArrRangeCheck(int32, int32, string)
    ldarg.0
    ldfld object[] Moggi.Rt.ArrSTArray::elems
    ldloc.1
    ldstr "read"
    call object Moggi.Rt.RT::ArrReadSlot(object[], int32, string)
    ret
  }

IL;
}

/** Mutable read as an ST description (reads when run). */
function rtStReadArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ST StReadArray(class Moggi.Rt.ArrSTArray arr, object idx) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    newobj instance void Moggi.Rt.ReadSTArrayFn::.ctor(class Moggi.Rt.ArrSTArray, object)
    newobj instance void Moggi.Rt.ST::.ctor(object)
    ret
  }

IL;
}

function rtStWriteDirectIl(): string
{
    return <<<'IL'
  .method public hidebysig static object StWriteDirect(class Moggi.Rt.ArrSTArray arr, object idx, object x) cil managed
  {
    .maxstack 8
    .locals init (int32 n, int32 i)
    ldarg.0
    ldfld int32 Moggi.Rt.ArrSTArray::n
    stloc.0
    ldarg.1
    call int32 Moggi.Rt.RT::ArrOffset(object)
    stloc.1
    ldloc.0
    ldloc.1
    ldstr "write"
    call void Moggi.Rt.RT::ArrRangeCheck(int32, int32, string)
    ldarg.0
    ldfld object[] Moggi.Rt.ArrSTArray::elems
    ldloc.1
    ldarg.2
    stelem.ref
    ldnull
    ret
  }

IL;
}

/** Mutable write as an ST description (writes when run, answering null for Unit). */
function rtStWriteArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ST StWriteArray(class Moggi.Rt.ArrSTArray arr, object idx, object x) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    ldarg.2
    newobj instance void Moggi.Rt.WriteSTArrayFn::.ctor(class Moggi.Rt.ArrSTArray, object, object)
    newobj instance void Moggi.Rt.ST::.ctor(object)
    ret
  }

IL;
}

function rtStSizeArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static object StSizeArray(class Moggi.Rt.ArrSTArray arr) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld int32 Moggi.Rt.ArrSTArray::n
    conv.i8
    call object Moggi.Rt.RT::BoxInt(int64)
    ret
  }

IL;
}

function rtStBoundsArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static object StBoundsArray(class Moggi.Rt.ArrSTArray arr) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld object Moggi.Rt.ArrSTArray::bounds
    ret
  }

IL;
}

function rtStFreezeDirectIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ArrArray StFreezeDirect(class Moggi.Rt.ArrSTArray arr, bool copy) cil managed
  {
    .maxstack 8
    .locals init (object[] slots)
    ldarg.1
    brfalse Share
    ldarg.0
    ldfld object[] Moggi.Rt.ArrSTArray::elems
    ldarg.0
    ldfld int32 Moggi.Rt.ArrSTArray::n
    call object[] Moggi.Rt.RT::ArrCopySlots(object[], int32)
    stloc.0
    br Build
Share:
    ldarg.0
    ldfld object[] Moggi.Rt.ArrSTArray::elems
    stloc.0
Build:
    ldarg.0
    ldfld object Moggi.Rt.ArrSTArray::bounds
    ldarg.0
    ldfld int32 Moggi.Rt.ArrSTArray::n
    ldloc.0
    newobj instance void Moggi.Rt.ArrArray::.ctor(object, int32, object[])
    ret
  }

IL;
}

/** Copying or sharing freeze as an ST description. */
function rtStFreezeArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ST StFreezeArray(class Moggi.Rt.ArrSTArray arr, bool copy) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    newobj instance void Moggi.Rt.FreezeSTArrayFn::.ctor(class Moggi.Rt.ArrSTArray, bool)
    newobj instance void Moggi.Rt.ST::.ctor(object)
    ret
  }

IL;
}

function rtStThawDirectIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ArrSTArray StThawDirect(class Moggi.Rt.ArrArray arr, bool copy) cil managed
  {
    .maxstack 8
    .locals init (object[] slots)
    ldarg.1
    brfalse Share
    ldarg.0
    ldfld object[] Moggi.Rt.ArrArray::elems
    ldarg.0
    ldfld int32 Moggi.Rt.ArrArray::n
    call object[] Moggi.Rt.RT::ArrCopySlots(object[], int32)
    stloc.0
    br Build
Share:
    ldarg.0
    ldfld object[] Moggi.Rt.ArrArray::elems
    stloc.0
Build:
    ldarg.0
    ldfld object Moggi.Rt.ArrArray::bounds
    ldarg.0
    ldfld int32 Moggi.Rt.ArrArray::n
    ldloc.0
    newobj instance void Moggi.Rt.ArrSTArray::.ctor(object, int32, object[])
    ret
  }

IL;
}

/** Copying or sharing thaw as an ST description. */
function rtStThawArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ST StThawArray(class Moggi.Rt.ArrArray arr, bool copy) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    newobj instance void Moggi.Rt.ThawArrayFn::.ctor(class Moggi.Rt.ArrArray, bool)
    newobj instance void Moggi.Rt.ST::.ctor(object)
    ret
  }

IL;
}

function rtIoNewArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ArrIOArray IoNewArray(object bounds, object n) cil managed
  {
    .maxstack 8
    .locals init (int32 size)
    ldarg.1
    call int32 Moggi.Rt.RT::ArrCheckedSize(object)
    stloc.0
    ldarg.0
    ldloc.0
    ldloc.0
    call object[] Moggi.Rt.RT::ArrAllocSlots(int32)
    newobj instance void Moggi.Rt.ArrIOArray::.ctor(object, int32, object[])
    ret
  }

IL;
}

function rtIoReadArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static object IoReadArray(class Moggi.Rt.ArrIOArray arr, object idx) cil managed
  {
    .maxstack 8
    .locals init (int32 n, int32 i)
    ldarg.0
    ldfld int32 Moggi.Rt.ArrIOArray::n
    stloc.0
    ldarg.1
    call int32 Moggi.Rt.RT::ArrOffset(object)
    stloc.1
    ldloc.0
    ldloc.1
    ldstr "read"
    call void Moggi.Rt.RT::ArrRangeCheck(int32, int32, string)
    ldarg.0
    ldfld object[] Moggi.Rt.ArrIOArray::elems
    ldloc.1
    ldstr "read"
    call object Moggi.Rt.RT::ArrReadSlot(object[], int32, string)
    ret
  }

IL;
}

function rtIoWriteArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static object IoWriteArray(class Moggi.Rt.ArrIOArray arr, object idx, object x) cil managed
  {
    .maxstack 8
    .locals init (int32 n, int32 i)
    ldarg.0
    ldfld int32 Moggi.Rt.ArrIOArray::n
    stloc.0
    ldarg.1
    call int32 Moggi.Rt.RT::ArrOffset(object)
    stloc.1
    ldloc.0
    ldloc.1
    ldstr "write"
    call void Moggi.Rt.RT::ArrRangeCheck(int32, int32, string)
    ldarg.0
    ldfld object[] Moggi.Rt.ArrIOArray::elems
    ldloc.1
    ldarg.2
    stelem.ref
    ldnull
    ret
  }

IL;
}

function rtIoSizeArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static object IoSizeArray(class Moggi.Rt.ArrIOArray arr) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld int32 Moggi.Rt.ArrIOArray::n
    conv.i8
    call object Moggi.Rt.RT::BoxInt(int64)
    ret
  }

IL;
}

function rtIoBoundsArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static object IoBoundsArray(class Moggi.Rt.ArrIOArray arr) cil managed
  {
    .maxstack 8
    ldarg.0
    ldfld object Moggi.Rt.ArrIOArray::bounds
    ret
  }

IL;
}

function rtIoFreezeArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ArrArray IoFreezeArray(class Moggi.Rt.ArrIOArray arr, bool copy) cil managed
  {
    .maxstack 8
    .locals init (object[] slots)
    ldarg.1
    brfalse Share
    ldarg.0
    ldfld object[] Moggi.Rt.ArrIOArray::elems
    ldarg.0
    ldfld int32 Moggi.Rt.ArrIOArray::n
    call object[] Moggi.Rt.RT::ArrCopySlots(object[], int32)
    stloc.0
    br Build
Share:
    ldarg.0
    ldfld object[] Moggi.Rt.ArrIOArray::elems
    stloc.0
Build:
    ldarg.0
    ldfld object Moggi.Rt.ArrIOArray::bounds
    ldarg.0
    ldfld int32 Moggi.Rt.ArrIOArray::n
    ldloc.0
    newobj instance void Moggi.Rt.ArrArray::.ctor(object, int32, object[])
    ret
  }

IL;
}

function rtIoThawArrayIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.ArrIOArray IoThawArray(class Moggi.Rt.ArrArray arr, bool copy) cil managed
  {
    .maxstack 8
    .locals init (object[] slots)
    ldarg.1
    brfalse Share
    ldarg.0
    ldfld object[] Moggi.Rt.ArrArray::elems
    ldarg.0
    ldfld int32 Moggi.Rt.ArrArray::n
    call object[] Moggi.Rt.RT::ArrCopySlots(object[], int32)
    stloc.0
    br Build
Share:
    ldarg.0
    ldfld object[] Moggi.Rt.ArrArray::elems
    stloc.0
Build:
    ldarg.0
    ldfld object Moggi.Rt.ArrArray::bounds
    ldarg.0
    ldfld int32 Moggi.Rt.ArrArray::n
    ldloc.0
    newobj instance void Moggi.Rt.ArrIOArray::.ctor(object, int32, object[])
    ret
  }

IL;
}

function rtConstFnIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.Fn ConstFn(object v) cil managed
  {
    .maxstack 8
    ldarg.0
    newobj instance void Moggi.Rt.ConstFn::.ctor(object)
    ret
  }

IL;
}

/**
 * Strict fixpoint (`Data.Function.fix`): apply `fn` to a memoising
 * self-reference standing for `fix fn` applied to further arguments.
 */
function rtFixIl(): string
{
    return <<<'IL'
  .method public hidebysig static object Fix(object fn) cil managed
  {
    .maxstack 8
    .locals init (object rec,
                  object[] args)

    ldarg.0
    newobj instance void Moggi.Rt.Rec::.ctor(object)
    stloc rec
    ldc.i4.1
    newarr object
    dup
    ldc.i4.0
    ldloc rec
    stelem.ref
    stloc args
    ldarg.0
    ldloc args
    call object Moggi.Rt.RT::Apply(object, object[])
    ret
  }

IL;
}

function rtConIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.Con Con(string tag, object[] fields) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    newobj instance void Moggi.Rt.Con::.ctor(string, object[])
    ret
  }

IL;
}

function platformRuntime(): string
{
    return \implode("\n", [
        <<<'IL'
.class public abstract auto ansi sealed beforefieldinit Moggi.Rt.Platform
       extends [System.Runtime]System.Object
{
  .field private static string[] argv

IL,
        platformSetArgsIl(),
        platformArgvIl(),
        <<<'IL'
}

IL,
    ]);
}

function platformSetArgsIl(): string
{
    return <<<'IL'
  .method public hidebysig static void SetArgs(string[] argv) cil managed
  {
    .maxstack 8
    ldarg.0
    stsfld string[] Moggi.Rt.Platform::argv
    ret
  }

IL;
}

function platformArgvIl(): string
{
    return <<<'IL'
  .method public hidebysig static class Moggi.Rt.MList Argv() cil managed
  {
    .maxstack 8
    .locals init (string[] a, class Moggi.Rt.MList acc, int32 i)

    ldsfld string[] Moggi.Rt.Platform::argv
    stloc a
    ldloc a
    brtrue HaveArgs
    ldnull
    ret

    HaveArgs:
    ldnull
    stloc acc
    ldloc a
    ldlen
    conv.i4
    stloc i

    Loop:
    ldloc i
    brfalse Done
    ldloc i
    ldc.i4.1
    sub
    stloc i
    ldloc a
    ldloc i
    ldelem.ref
    ldloc acc
    call class Moggi.Rt.MList Moggi.Rt.RT::Cons(object, object)
    stloc acc
    br Loop

    Done:
    ldloc acc
    ret
  }
IL;
}
function word64ClassIl(): string
{
    return <<<'IL'
.class public abstract auto ansi sealed beforefieldinit Moggi.Rt.Word64
{
  .method public hidebysig static int32 Compare(int64 a, int64 b) cil managed
  {
    .maxstack 4
    ldarg.0
    ldarg.1
    cgt.un
    ldarg.0
    ldarg.1
    clt.un
    sub
    ret
  }

  .method public hidebysig static int64 Add(int64 a, int64 b) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    add
    ret
  }

  .method public hidebysig static int64 Subtract(int64 a, int64 b) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    sub
    ret
  }

  .method public hidebysig static int64 Multiply(int64 a, int64 b) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    mul
    ret
  }

  .method public hidebysig static int64 Divide(int64 a, int64 b) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    div.un
    ret
  }

  .method public hidebysig static int64 Remainder(int64 a, int64 b) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    rem.un
    ret
  }

  .method public hidebysig static int64 And(int64 a, int64 b) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    and
    ret
  }

  .method public hidebysig static int64 Or(int64 a, int64 b) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    or
    ret
  }

  .method public hidebysig static int64 Xor(int64 a, int64 b) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    xor
    ret
  }

  .method public hidebysig static int64 ShiftLeft(int64 a, int32 n) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    ldc.i4.s 63
    and
    shl
    ret
  }

  .method public hidebysig static int64 ShiftRight(int64 a, int32 n) cil managed
  {
    .maxstack 8
    ldarg.0
    ldarg.1
    ldc.i4.s 63
    and
    shr.un
    ret
  }

  .method public hidebysig static int32 BitCount(int64 a) cil managed
  {
    .maxstack 4
    .locals init (int64 x, int32 c)
    ldarg.0
    stloc x
    ldc.i4.0
    stloc c
  loop:
    ldloc x
    ldc.i8 0
    ceq
    brtrue.s done
    ldloc c
    ldc.i4.1
    add
    stloc c
    ldloc x
    ldloc x
    ldc.i8 1
    sub
    and
    stloc x
    br.s loop
  done:
    ldloc c
    ret
  }

  .method public hidebysig static int32 CountLeadingZeros(int64 a) cil managed
  {
    .maxstack 4
    .locals init (int64 x, int32 c)
    ldarg.0
    stloc x
    ldloc x
    ldc.i8 0
    ceq
    brfalse.s nonzero
    ldc.i4 64
    ret
  nonzero:
    ldc.i4.0
    stloc c
  loop:
    ldloc x
    ldc.i8 -9223372036854775808
    and
    ldc.i8 0
    ceq
    brfalse.s done
    ldloc c
    ldc.i4.1
    add
    stloc c
    ldloc x
    ldc.i4.1
    shl
    stloc x
    br.s loop
  done:
    ldloc c
    ret
  }

  .method public hidebysig static int32 CountTrailingZeros(int64 a) cil managed
  {
    .maxstack 4
    .locals init (int64 x, int32 c)
    ldarg.0
    stloc x
    ldloc x
    ldc.i8 0
    ceq
    brfalse.s nonzero
    ldc.i4 64
    ret
  nonzero:
    ldc.i4.0
    stloc c
  loop:
    ldloc x
    ldc.i8 1
    and
    ldc.i8 0
    ceq
    brfalse.s done
    ldloc c
    ldc.i4.1
    add
    stloc c
    ldloc x
    ldc.i4.1
    shr.un
    stloc x
    br.s loop
  done:
    ldloc c
    ret
  }

  .method public hidebysig static string ToString(int64 a) cil managed
  {
    .maxstack 1
    ldarg.0
    box [System.Runtime]System.UInt64
    callvirt instance string [System.Runtime]System.Object::ToString()
    ret
  }

  .method public hidebysig static valuetype [System.Runtime.Numerics]System.Numerics.BigInteger ToBigInteger(int64 a) cil managed
  {
    .maxstack 1
    ldarg.0
    box [System.Runtime]System.UInt64
    callvirt instance string [System.Runtime]System.Object::ToString()
    call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::Parse(string)
    ret
  }

  .method public hidebysig static int64 FromBigInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger a) cil managed
  {
    .maxstack 3
    .locals init (int64 lo, int64 hi)

    ldarg.0
    ldc.i8 9223372036854775807
    newobj instance void [System.Runtime.Numerics]System.Numerics.BigInteger::.ctor(int64)
    call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::op_BitwiseAnd(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger, valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)
    call int64 [System.Runtime.Numerics]System.Numerics.BigInteger::op_Explicit(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)
    stloc lo

    ldarg.0
    ldc.i4.s 63
    call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::op_RightShift(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger, int32)
    call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::get_One()
    call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::op_BitwiseAnd(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger, valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)
    call int64 [System.Runtime.Numerics]System.Numerics.BigInteger::op_Explicit(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)
    stloc hi

    ldloc lo
    ldloc hi
    ldc.i4.s 63
    shl
    or
    ret
  }

  .method public hidebysig static int64 ByteSwap(int64 a) cil managed
  {
    .maxstack 8
    .locals init (int64 x, int64 r)
    ldarg.0
    stloc x
    ldc.i8 0
    stloc r

    ldloc x
    ldc.i8 255
    and
    ldc.i4 56
    shl
    ldloc r
    or
    stloc r

    ldloc x
    ldc.i4 8
    shr.un
    ldc.i8 255
    and
    ldc.i4 48
    shl
    ldloc r
    or
    stloc r

    ldloc x
    ldc.i4 16
    shr.un
    ldc.i8 255
    and
    ldc.i4 40
    shl
    ldloc r
    or
    stloc r

    ldloc x
    ldc.i4 24
    shr.un
    ldc.i8 255
    and
    ldc.i4 32
    shl
    ldloc r
    or
    stloc r

    ldloc x
    ldc.i4 32
    shr.un
    ldc.i8 255
    and
    ldc.i4 24
    shl
    ldloc r
    or
    stloc r

    ldloc x
    ldc.i4 40
    shr.un
    ldc.i8 255
    and
    ldc.i4 16
    shl
    ldloc r
    or
    stloc r

    ldloc x
    ldc.i4 48
    shr.un
    ldc.i8 255
    and
    ldc.i4 8
    shl
    ldloc r
    or
    stloc r

    ldloc x
    ldc.i4 56
    shr.un
    ldc.i8 255
    and
    ldloc r
    or
    ret
  }
}

IL;
}
