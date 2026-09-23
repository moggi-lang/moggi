<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm;

/**
 * JVM language runtime ABI — FROZEN for the first execution slice.
 *
 * Do not expand facades / JDK surface until Closure, Partial, Dict, IO, and
 * Main entry can run `putStrLn (show (twice 21))` end-to-end.
 *
 * ── Representations (locked) ──────────────────────────────────────────
 *
 * Closure (Fn)
 *   Interface moggi.rt.Fn { Object invoke(Object[] args); }
 *   Top-level functions are static methods; as values they are compiler-owned
 *   TopLevelFn wrappers around those static methods.
 *
 * Partial application
 *   Class moggi.rt.Partial { int arity; Fn target; Object[] args; }
 *   RT.apply(callee, arg) saturates or extends Partial.
 *
 * ADT
 *   Class moggi.rt.Con { String tag; Object[] fields; }
 *   Constructors emit static factory methods returning Con.
 *
 * List
 *   null                    = Nil
 *   moggi.rt.MList(head,t)  = Cons  (tail is MList|null)
 *
 * Dictionary (typeclass evidence)
 *   Class moggi.rt.Dict { Object[] methods; }  // surface/ir name pairs or Fn slots
 *   Resolved evidence is a static method returning Dict; arity is the instance
 *   context count (`Num a => Monoid (Sum a)` → one dict parameter). Constrained
 *   evidence closes context into method Partials.
 *
 * IO
 *   Class moggi.rt.IO { Object action; }   // RT.apply(action, empty) runs the effect
 *   RT.ioRun(IO) executes; Main entry runs IO mains via ioRun.
 *   RT.ioCatch / ioFinally / throwSomeException back IoCatch/IoFinally/IoThrow.
 *
 * Exceptions (SomeException#)
 *   Object[] { "__se", stableTag, payload } — mirrors PHP ['__se', tag, payload]
 *   moggi.rt.MoggiException extends RuntimeException with:
 *     someException: Object[], throwSite: Object[]|null
 *   throwSite Object[] = {Integer siteId, String symbolId, String displayPath,
 *     Integer line, Integer col} — mirrors PHP Moggi\throwSite().
 *   Payload for ADTs is moggi.rt.Con (same as ordinary algebraic data).
 *   RT.formatExceptionReport / reportUncaught mirror PHP stderr reports
 *   (throwSite only — no runtime call-frame stacking).
 *
 * Scalars / Unit
 *   Int/Char → java.lang.Long (signed 64-bit).
 *              Bounded Int extremes remain i32-range (stdlib Data.Int).
 *   Integer# → java.math.BigInteger (arbitrary precision)
 *   Double    → java.lang.Double
 *   Bool     → java.lang.Boolean
 *   String   → java.lang.String
 *   Unit     → null (prefer null; Unit.INSTANCE is unused legacy)
 *
 * Main entry (JVM bridge on the entry module class)
 *   Moggi programs expose exactly: main :: IO ()
 *   public static void main(String[] args)
 *   - calls Moggi main ()Ljava/lang/Object;  (must be IO)
 *   - RT.ioRun(io); discard Unit
 *
 * Platform helpers back platform_* intrinsics (not foreign-importable).
 * Scalar arithmetic is IR intrinsics → bytecode
 * (box/unbox via Long/Double valueOf/longValue, not RT trampolines).
 *
 * JVM target policy:
 * - Target / minimum runtime: Java 21 LTS
 * - Preview features: never
 * - Bytecode: verified class files, major version 65
 * - Conservative emit only (ordinary classes/static methods/interfaces/invoke*)
 * - JVM-specific optimizations optional later; must not change language semantics
 */

use Moggi\Backend\Jvm\Classfile\ClassBuilder;
use Moggi\Backend\Jvm\Classfile\CodeBuilder;
use Moggi\Backend\Jvm\Classfile\ConstantPool;

require_once __DIR__ . '/classfile.php';

/** @return array<string, string> internalName => classfile bytes */
function languageRuntime(): array
{
    static $cached = null;
    if (\is_array($cached)) {
        return $cached;
    }

    $cached = [
        'moggi/rt/Fn' => buildFnInterface(),
        'moggi/rt/Partial' => buildPartialClass(),
        'moggi/rt/MList' => buildMListClass(),
        'moggi/rt/Con' => buildConClass(),
        'moggi/rt/IO' => buildIOClass(),
        'moggi/rt/Dict' => buildDictClass(),
        'moggi/rt/MoggiException' => buildMoggiExceptionClass(),
        'moggi/rt/RT' => buildRTClass(),
        'moggi/rt/ConstFn' => buildConstFnClass(),
        'moggi/rt/Rec' => buildRecClass(),
        'moggi/rt/TopLevelFn' => buildTopLevelFnClass(),
        'moggi/rt/Word64' => buildWord64Class(),
        // Platform: argv + IO handle helpers (System.IO.JVM). FS/StringOps via JDK FFI.
        'moggi/rt/Platform' => buildPlatformClass(),
    ];

    return $cached;
}

/**
 * @return array<string, string>
 */
function platformRuntime(): array
{
    return [];
}

function buildFnInterface(): string
{
    $cp = new ConstantPool();
    $thisC = $cp->class_('moggi/rt/Fn');
    $obj = $cp->class_('java/lang/Object');
    $mn = $cp->utf8('invoke');
    $md = $cp->utf8('([Ljava/lang/Object;)Ljava/lang/Object;');
    $method = pack('n', 0x0401) . pack('n', $mn) . pack('n', $md) . pack('n', 0);

    return pack('N', 0xCAFEBABE) . pack('n', 0) . pack('n', 65) . $cp->write()
        . pack('n', 0x0601) . pack('n', $thisC) . pack('n', $obj)
        . pack('n', 0) . pack('n', 0) . pack('n', 1) . $method . pack('n', 0);
}

function buildPartialClass(): string
{
    $b = new ClassBuilder('moggi/rt/Partial');
    $b->addField('arity', 'I', 0x0011);
    $b->addField('target', 'Lmoggi/rt/Fn;', 0x0011);
    $b->addField('args', '[Ljava/lang/Object;', 0x0011);

    $b->addMethod(
        '<init>',
        '(ILmoggi/rt/Fn;[Ljava/lang/Object;)V',
        0x0001,
        4,
        ['moggi/rt/Partial', 'int', 'moggi/rt/Fn', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokespecial($cp->methodRef('java/lang/Object', '<init>', '()V'), 0, false);
            $c->aload(0);
            $c->iload(1);
            $c->putfield($cp->fieldRef('moggi/rt/Partial', 'arity', 'I'));
            $c->aload(0);
            $c->aload(2);
            $c->putfield($cp->fieldRef('moggi/rt/Partial', 'target', 'Lmoggi/rt/Fn;'));
            $c->aload(0);
            $c->aload(3);
            $c->putfield($cp->fieldRef('moggi/rt/Partial', 'args', '[Ljava/lang/Object;'));
            $c->return_();
        }
    );

    return $b->toBytes();
}

function buildMListClass(): string
{
    $b = new ClassBuilder('moggi/rt/MList');
    $b->addField('head', 'Ljava/lang/Object;', 0x0011);
    $b->addField('tail', 'Lmoggi/rt/MList;', 0x0011);

    $b->addMethod(
        '<init>',
        '(Ljava/lang/Object;Lmoggi/rt/MList;)V',
        0x0001,
        3,
        ['moggi/rt/MList', 'java/lang/Object', 'moggi/rt/MList'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokespecial($cp->methodRef('java/lang/Object', '<init>', '()V'), 0, false);
            $c->aload(0);
            $c->aload(1);
            $c->putfield($cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
            $c->aload(0);
            $c->aload(2);
            $c->putfield($cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
            $c->return_();
        }
    );

    $b->addMethod(
        'equals',
        '(Ljava/lang/Object;)Z',
        0x0001,
        2,
        ['moggi/rt/MList', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(1);
            $c->instanceof_($cp->class_('moggi/rt/MList'));
            $c->ifne('compare');
            $c->iconst(0);
            $c->ireturn();

            $c->label('compare');
            $c->noteFrame(['moggi/rt/MList', 'java/lang/Object']);
            $c->aload(0);
            $c->aload(1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'listEq', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'), 2, true);
            $c->invokevirtual($cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $c->ireturn();
        }
    );

    return $b->toBytes();
}

function buildConClass(): string
{
    $b = new ClassBuilder('moggi/rt/Con');
    $b->addField('tag', 'Ljava/lang/String;', 0x0011);
    $b->addField('fields', '[Ljava/lang/Object;', 0x0011);

    $b->addMethod(
        '<init>',
        '(Ljava/lang/String;[Ljava/lang/Object;)V',
        0x0001,
        3,
        ['moggi/rt/Con', 'java/lang/String', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokespecial($cp->methodRef('java/lang/Object', '<init>', '()V'), 0, false);
            $c->aload(0);
            $c->aload(1);
            $c->putfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->aload(0);
            $c->aload(2);
            $c->putfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->return_();
        }
    );

    // Structural equality so `==` on ADTs (Sum, Maybe, …) works via Object.equals,
    // matching MList and RT.valueEq used by listEq.
    $b->addMethod(
        'equals',
        '(Ljava/lang/Object;)Z',
        0x0001,
        2,
        ['moggi/rt/Con', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->aload(1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'valueEq', '(Ljava/lang/Object;Ljava/lang/Object;)Z'), 2, true);
            $c->ireturn();
        }
    );

    return $b->toBytes();
}

function buildIOClass(): string
{
    $b = new ClassBuilder('moggi/rt/IO');
    $b->addField('action', 'Ljava/lang/Object;', 0x0011);

    $b->addMethod(
        '<init>',
        '(Ljava/lang/Object;)V',
        0x0001,
        2,
        ['moggi/rt/IO', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokespecial($cp->methodRef('java/lang/Object', '<init>', '()V'), 0, false);
            $c->aload(0);
            $c->aload(1);
            $c->putfield($cp->fieldRef('moggi/rt/IO', 'action', 'Ljava/lang/Object;'));
            $c->return_();
        }
    );

    return $b->toBytes();
}

/** Host throwable carrying a Moggi SomeException# (Object[] {__se, tag, payload}). */
function buildMoggiExceptionClass(): string
{
    $b = new ClassBuilder('moggi/rt/MoggiException', 'java/lang/RuntimeException');
    $b->addField('someException', '[Ljava/lang/Object;', 0x0011);
    $b->addField('throwSite', '[Ljava/lang/Object;', 0x0011);

    // Compatibility: MoggiException(se) → full ctor with null site/cause.
    $b->addMethod(
        '<init>',
        '([Ljava/lang/Object;)V',
        0x0001,
        2,
        ['moggi/rt/MoggiException', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->aload(1);
            $c->aconst_null();
            $c->aconst_null();
            $c->invokespecial(
                $cp->methodRef(
                    'moggi/rt/MoggiException',
                    '<init>',
                    '([Ljava/lang/Object;[Ljava/lang/Object;Ljava/lang/Throwable;)V',
                ),
                3,
                false,
            );
            $c->return_();
        }
    );

    // (someException, throwSite, cause) — never nest MoggiException as cause.
    $b->addMethod(
        '<init>',
        '([Ljava/lang/Object;[Ljava/lang/Object;Ljava/lang/Throwable;)V',
        0x0001,
        5,
        ['moggi/rt/MoggiException', '[Ljava/lang/Object;', '[Ljava/lang/Object;', 'java/lang/Throwable'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            // locals: 0=this, 1=se, 2=site, 3=cause, 4=msg
            // Call super(String) before any branch so this is initialized for stack maps.
            $c->aload(1);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'exceptionDisplay', '(Ljava/lang/Object;)Ljava/lang/String;'),
                1,
                true,
            );
            $c->astore(4);

            $c->aload(0);
            $c->aload(4);
            $c->invokespecial(
                $cp->methodRef('java/lang/RuntimeException', '<init>', '(Ljava/lang/String;)V'),
                1,
                false,
            );

            $c->aload(0);
            $c->aload(1);
            $c->putfield($cp->fieldRef('moggi/rt/MoggiException', 'someException', '[Ljava/lang/Object;'));

            $c->aload(0);
            $c->aload(2);
            $c->putfield($cp->fieldRef('moggi/rt/MoggiException', 'throwSite', '[Ljava/lang/Object;'));

            // Attach native cause only (never nest MoggiException).
            $c->aload(3);
            $c->ifnull('done');
            $c->aload(3);
            $c->instanceof_($cp->class_('moggi/rt/MoggiException'));
            $c->ifne('done');
            $c->aload(0);
            $c->aload(3);
            $c->invokevirtual(
                $cp->methodRef('java/lang/Throwable', 'initCause', '(Ljava/lang/Throwable;)Ljava/lang/Throwable;'),
                1,
                false,
            );
            $c->pop_();
            $c->label('done');
            $c->noteFrame(
                ['moggi/rt/MoggiException', '[Ljava/lang/Object;', '[Ljava/lang/Object;', 'java/lang/Throwable', 'java/lang/String'],
            );
            $c->return_();
        },
    );

    return $b->toBytes();
}

function buildDictClass(): string
{
    $b = new ClassBuilder('moggi/rt/Dict');
    $b->addField('methods', '[Ljava/lang/Object;', 0x0011);

    $b->addMethod(
        '<init>',
        '([Ljava/lang/Object;)V',
        0x0001,
        2,
        ['moggi/rt/Dict', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokespecial($cp->methodRef('java/lang/Object', '<init>', '()V'), 0, false);
            $c->aload(0);
            $c->aload(1);
            $c->putfield($cp->fieldRef('moggi/rt/Dict', 'methods', '[Ljava/lang/Object;'));
            $c->return_();
        }
    );

    $b->addMethod(
        'lookup',
        '(Ljava/lang/String;)Ljava/lang/Object;',
        0x0001,
        4,
        ['moggi/rt/Dict', 'java/lang/String'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->iconst(0);
            $c->istore(2);

            $c->label('loop');
            $c->noteFrame(['moggi/rt/Dict', 'java/lang/String', 'int']);
            $c->iload(2);
            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/Dict', 'methods', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->if_icmpge('missing');

            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/Dict', 'methods', '[Ljava/lang/Object;'));
            $c->iload(2);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->aload(1);
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('next');

            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/Dict', 'methods', '[Ljava/lang/Object;'));
            $c->iload(2);
            $c->iconst(1);
            $c->iadd();
            $c->aaload();
            $c->areturn();

            $c->label('next');
            $c->noteFrame(['moggi/rt/Dict', 'java/lang/String', 'int']);
            $c->iinc(2, 2);
            $c->goto_('loop');

            $c->label('missing');
            $c->noteFrame(['moggi/rt/Dict', 'java/lang/String', 'int']);
            $c->new_($cp->class_('java/lang/IllegalArgumentException'));
            $c->dup();
            $c->ldc($cp->string_('Dict.lookup: missing method'));
            $c->invokespecial($cp->methodRef('java/lang/IllegalArgumentException', '<init>', '(Ljava/lang/String;)V'), 1, false);
            $c->athrow();
        }
    );

    return $b->toBytes();
}

/** Fn that ignores arguments and returns a constant — used by RT.ioPure. */
function buildConstFnClass(): string
{
    $b = new ClassBuilder('moggi/rt/ConstFn');
    $b->implement('moggi/rt/Fn');
    $b->addField('value', 'Ljava/lang/Object;', 0x0011);

    $b->addMethod(
        '<init>',
        '(Ljava/lang/Object;)V',
        0x0001,
        2,
        ['moggi/rt/ConstFn', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokespecial($cp->methodRef('java/lang/Object', '<init>', '()V'), 0, false);
            $c->aload(0);
            $c->aload(1);
            $c->putfield($cp->fieldRef('moggi/rt/ConstFn', 'value', 'Ljava/lang/Object;'));
            $c->return_();
        }
    );

    $b->addMethod(
        'invoke',
        '([Ljava/lang/Object;)Ljava/lang/Object;',
        0x0001,
        2,
        ['moggi/rt/ConstFn', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/ConstFn', 'value', 'Ljava/lang/Object;'));
            $c->areturn();
        }
    );

    return $b->toBytes();
}

/**
 * Memoising self-reference backing the strict fixpoint (`RT.fix`).
 *
 * `Rec.invoke(args)` stands for `fix f` applied to `args`: the first time it is
 * reached it computes `f(this)` and remembers the result, then forwards `args`
 * to that value. The recursion is therefore only forced when `f` actually uses
 * the reference, which is what makes `fix` work under strict evaluation.
 *
 * `fn` is kept as Object (not Fn) so partially applied wrappers work too.
 */
function buildRecClass(): string
{
    $b = new ClassBuilder('moggi/rt/Rec');
    $b->implement('moggi/rt/Fn');
    // `value`/`done` are memo cells written by `invoke`, so they must not be
    // final (the JVM only allows final writes from the initializer method).
    $b->addField('fn', 'Ljava/lang/Object;', 0x0011);
    $b->addField('value', 'Ljava/lang/Object;', 0x0001);
    $b->addField('done', 'I', 0x0001);

    $b->addMethod(
        '<init>',
        '(Ljava/lang/Object;)V',
        0x0001,
        3,
        ['moggi/rt/Rec', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokespecial($cp->methodRef('java/lang/Object', '<init>', '()V'), 0, false);
            $c->aload(0);
            $c->aload(1);
            $c->putfield($cp->fieldRef('moggi/rt/Rec', 'fn', 'Ljava/lang/Object;'));
            $c->return_();
        }
    );

    $b->addMethod(
        'invoke',
        '([Ljava/lang/Object;)Ljava/lang/Object;',
        0x0001,
        4,
        ['moggi/rt/Rec', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/Rec', 'done', 'I'));
            $c->ifne('computed');

            // value = RT.apply(fn, [this]); done = true;
            $c->aload(0);
            $c->iconst(1);
            $c->putfield($cp->fieldRef('moggi/rt/Rec', 'done', 'I'));
            $c->aload(0);
            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/Rec', 'fn', 'Ljava/lang/Object;'));
            $c->iconst(1);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->dup();
            $c->iconst(0);
            $c->aload(0);
            $c->aastore();
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'apply', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            $c->putfield($cp->fieldRef('moggi/rt/Rec', 'value', 'Ljava/lang/Object;'));

            $c->label('computed');
            $c->noteFrame(['moggi/rt/Rec', '[Ljava/lang/Object;']);
            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/Rec', 'value', 'Ljava/lang/Object;'));
            $c->aload(1);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'apply', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            $c->areturn();
        }
    );

    return $b->toBytes();
}

function buildTopLevelFnClass(): string
{
    $b = new ClassBuilder('moggi/rt/TopLevelFn');
    $b->implement('moggi/rt/Fn');
    $b->addField('arity', 'I', 0x0011);
    $b->addField('target', 'Lmoggi/rt/Fn;', 0x0011);

    $b->addMethod(
        '<init>',
        '(ILmoggi/rt/Fn;)V',
        0x0001,
        3,
        ['moggi/rt/TopLevelFn', 'int', 'moggi/rt/Fn'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokespecial($cp->methodRef('java/lang/Object', '<init>', '()V'), 0, false);
            $c->aload(0);
            $c->iload(1);
            $c->putfield($cp->fieldRef('moggi/rt/TopLevelFn', 'arity', 'I'));
            $c->aload(0);
            $c->aload(2);
            $c->putfield($cp->fieldRef('moggi/rt/TopLevelFn', 'target', 'Lmoggi/rt/Fn;'));
            $c->return_();
        }
    );

    $b->addMethod(
        'invoke',
        '([Ljava/lang/Object;)Ljava/lang/Object;',
        0x0001,
        2,
        ['moggi/rt/TopLevelFn', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/TopLevelFn', 'target', 'Lmoggi/rt/Fn;'));
            $c->aload(1);
            $c->invokeinterface(
                $cp->ifaceMethodRef('moggi/rt/Fn', 'invoke', '([Ljava/lang/Object;)Ljava/lang/Object;'),
                1,
                true,
            );
            $c->areturn();
        }
    );

    return $b->toBytes();
}

/** Append a String constant to the StringBuilder on top of the stack. */
function rtAppendString(CodeBuilder $c, ConstantPool $cp, string $s): void
{
    $c->ldc($cp->string_($s));
    $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
}

/** Append a char to the StringBuilder on top of the stack. */
function rtAppendChar(CodeBuilder $c, ConstantPool $cp, int $ch): void
{
    $c->bipush($ch);
    $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(C)Ljava/lang/StringBuilder;'), 1, true);
}

/** Append the int on top of the stack to the StringBuilder under it. */
function rtAppendInt(CodeBuilder $c, ConstantPool $cp): void
{
    $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(I)Ljava/lang/StringBuilder;'), 1, true);
}

/** Append the String on top of the stack to the StringBuilder under it. */
function rtAppendTop(CodeBuilder $c, ConstantPool $cp): void
{
    $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
}

/**
 * Frame renderers for formatExceptionReport.
 *
 * A frame the baked `moggi/rt/Frames` table knows is printed in `.mog` terms;
 * the runtime's own frames are skipped. The native cause stack is rendered
 * raw and unfiltered.
 */
function addRtFrameAppenders(ClassBuilder $b): void
{
    $mgLocals = [
        'java/lang/StringBuilder', 'java/lang/Throwable', '[Ljava/lang/Object;', '[Ljava/lang/StackTraceElement;',
        'int', 'int', 'int', 'java/lang/StackTraceElement', 'java/lang/String', 'java/lang/String', 'java/lang/String',
    ];

    $b->addMethod(
        'appendMoggFrames',
        '(Ljava/lang/StringBuilder;Ljava/lang/Throwable;[Ljava/lang/Object;)V',
        0x0009,
        11,
        ['java/lang/StringBuilder', 'java/lang/Throwable', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp) use ($mgLocals): void {
            // locals: 0=sb, 1=t, 2=throwSite, 3=stack, 4=i, 5=shown,
            //         6=limit, 7=el, 8=text, 9=key, 10=throwSiteText
            $c->aload(1);
            $c->ifnonnull('has_t');
            $c->return_();

            $c->label('has_t');
            $c->noteFrame(['java/lang/StringBuilder', 'java/lang/Throwable', '[Ljava/lang/Object;']);
            // Walk the most specific trace: a wrapped host failure carries the
            // frames that actually threw, while the wrapper's own trace is only
            // the report site. Falls back to the throwable itself.
            $c->aload(1);
            $c->invokevirtual($cp->methodRef('java/lang/Throwable', 'getCause', '()Ljava/lang/Throwable;'), 0, true);
            $c->dup();
            $c->ifnonnull('walk');
            $c->pop_();
            $c->aload(1);
            $c->label('walk');
            $c->noteFrame(
                ['java/lang/StringBuilder', 'java/lang/Throwable', '[Ljava/lang/Object;'],
                ['java/lang/Throwable'],
            );
            $c->invokevirtual($cp->methodRef('java/lang/Throwable', 'getStackTrace', '()[Ljava/lang/StackTraceElement;'), 0, true);
            $c->astore(3);
            $c->iconst(0);
            $c->istore(5);
            $c->iconst(0);
            $c->istore(4);
            $c->aconst_null();
            $c->astore(7);
            $c->aconst_null();
            $c->astore(8);
            $c->aconst_null();
            $c->astore(9);

            // The stamped throw site is already the innermost frame: render its
            // line so its host-stack duplicates can be dropped, and count it
            // against the frame cap.
            $c->aload(2);
            $c->ifnull('no_site');
            $c->new_($cp->class_('java/lang/StringBuilder'));
            $c->dup();
            $c->invokespecial($cp->methodRef('java/lang/StringBuilder', '<init>', '()V'), 0, false);
            rtAppendString($c, $cp, '  at ');
            $c->aload(2);
            $c->iconst(1);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            rtAppendTop($c, $cp);
            rtAppendString($c, $cp, ' (');
            $c->aload(2);
            $c->iconst(2);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'displaySourcePath', '(Ljava/lang/String;)Ljava/lang/String;'), 1, true);
            rtAppendTop($c, $cp);
            rtAppendChar($c, $cp, 58);
            $c->aload(2);
            $c->iconst(3);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/Integer'));
            $c->invokevirtual($cp->methodRef('java/lang/Integer', 'intValue', '()I'), 0, true);
            rtAppendInt($c, $cp);
            rtAppendChar($c, $cp, 58);
            $c->aload(2);
            $c->iconst(4);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/Integer'));
            $c->invokevirtual($cp->methodRef('java/lang/Integer', 'intValue', '()I'), 0, true);
            rtAppendInt($c, $cp);
            rtAppendString($c, $cp, ")\n");
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'toString', '()Ljava/lang/String;'), 0, true);
            $c->astore(10);
            $c->bipush(49);
            $c->istore(6);
            $c->goto_('loop');

            $c->label('no_site');
            $c->noteFrame([
                'java/lang/StringBuilder', 'java/lang/Throwable', '[Ljava/lang/Object;', '[Ljava/lang/StackTraceElement;',
                'int', 'int', 'top', 'java/lang/StackTraceElement', 'java/lang/String', 'java/lang/String', 'top',
            ]);
            $c->aconst_null();
            $c->astore(10);
            $c->bipush(50);
            $c->istore(6);

            $c->label('loop');
            $c->noteFrame($mgLocals);
            $c->iload(4);
            $c->aload(3);
            $c->arraylength();
            $c->if_icmpge('end');

            $c->aload(3);
            $c->iload(4);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/StackTraceElement'));
            $c->astore(7);

            // The Mogg trace never names the runtime's own frames.
            $c->aload(7);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getClassName', '()Ljava/lang/String;'), 0, true);
            $c->ldc($cp->string_('moggi.rt.'));
            $c->invokevirtual($cp->methodRef('java/lang/String', 'startsWith', '(Ljava/lang/String;)Z'), 1, true);
            $c->ifne('next');

            $c->new_($cp->class_('java/lang/StringBuilder'));
            $c->dup();
            $c->invokespecial($cp->methodRef('java/lang/StringBuilder', '<init>', '()V'), 0, false);
            $c->aload(7);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getClassName', '()Ljava/lang/String;'), 0, true);
            rtAppendTop($c, $cp);
            rtAppendChar($c, $cp, 35); // '#'
            $c->aload(7);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getMethodName', '()Ljava/lang/String;'), 0, true);
            rtAppendTop($c, $cp);
            rtAppendChar($c, $cp, 35);
            $c->aload(7);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getLineNumber', '()I'), 0, true);
            rtAppendInt($c, $cp);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'toString', '()Ljava/lang/String;'), 0, true);
            $c->astore(9);

            $c->aload(9);
            $c->invokestatic($cp->methodRef('moggi/rt/Frames', 'lookup', '(Ljava/lang/String;)Ljava/lang/String;'), 1, true);
            $c->astore(8);
            $c->aload(8);
            $c->ifnull('next');

            // Drop host-stack duplicates of the stamped throw site.
            $c->aload(10);
            $c->ifnull('use_frame');
            $c->aload(8);
            $c->aload(10);
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('use_frame');
            $c->goto_('next');

            $c->label('use_frame');
            $c->noteFrame($mgLocals);
            $c->iload(5);
            $c->iload(6);
            $c->if_icmplt('append');
            $c->goto_('counted');

            $c->label('append');
            $c->noteFrame($mgLocals);
            $c->aload(0);
            $c->aload(8);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->pop_();

            $c->label('counted');
            $c->noteFrame($mgLocals);
            $c->iinc(5, 1);

            $c->label('next');
            $c->noteFrame($mgLocals);
            $c->iinc(4, 1);
            $c->goto_('loop');

            $c->label('end');
            $c->noteFrame($mgLocals);
            $c->iload(6);
            $c->iload(5);
            $c->if_icmplt('elide');
            $c->goto_('done');

            $c->label('elide');
            $c->noteFrame($mgLocals);
            $c->aload(0);
            rtAppendString($c, $cp, '  ... ');
            $c->iload(5);
            $c->iload(6);
            $c->isub();
            rtAppendInt($c, $cp);
            rtAppendString($c, $cp, " more\n");
            $c->pop_();

            $c->label('done');
            $c->noteFrame($mgLocals);
            $c->return_();
        }
    );

    $hostLocals = [
        'java/lang/StringBuilder', 'java/lang/Throwable', '[Ljava/lang/StackTraceElement;',
        'int', 'int', 'int', 'java/lang/StackTraceElement',
    ];

    $b->addMethod(
        'appendHostFrames',
        '(Ljava/lang/StringBuilder;Ljava/lang/Throwable;)V',
        0x0009,
        8,
        ['java/lang/StringBuilder', 'java/lang/Throwable'],
        static function (CodeBuilder $c, ConstantPool $cp) use ($hostLocals): void {
            // locals: 0=sb, 1=t, 2=stack, 3=i, 4=shown, 5=limit, 6=el
            $c->aload(1);
            $c->ifnonnull('has_t');
            $c->return_();

            $c->label('has_t');
            $c->noteFrame(['java/lang/StringBuilder', 'java/lang/Throwable']);
            $c->aload(1);
            $c->invokevirtual($cp->methodRef('java/lang/Throwable', 'getStackTrace', '()[Ljava/lang/StackTraceElement;'), 0, true);
            $c->astore(2);
            $c->bipush(50);
            $c->istore(5);
            $c->iconst(0);
            $c->istore(4);
            $c->iconst(0);
            $c->istore(3);
            $c->aconst_null();
            $c->astore(6);

            $c->label('loop');
            $c->noteFrame($hostLocals);
            $c->iload(3);
            $c->aload(2);
            $c->arraylength();
            $c->if_icmpge('done');

            $c->aload(2);
            $c->iload(3);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/StackTraceElement'));
            $c->astore(6);

            $c->aload(6);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getLineNumber', '()I'), 0, true);
            $c->ifle('next');
            $c->aload(6);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getFileName', '()Ljava/lang/String;'), 0, true);
            $c->ifnull('next');
            $c->aload(6);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getFileName', '()Ljava/lang/String;'), 0, true);
            $c->invokevirtual($cp->methodRef('java/lang/String', 'isEmpty', '()Z'), 0, true);
            $c->ifne('next');

            $c->iload(4);
            $c->iload(5);
            $c->if_icmpge('elide');

            $c->aload(0);
            rtAppendString($c, $cp, '  #');
            $c->iload(4);
            rtAppendInt($c, $cp);
            rtAppendChar($c, $cp, 32);
            $c->aload(6);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getClassName', '()Ljava/lang/String;'), 0, true);
            rtAppendTop($c, $cp);
            rtAppendChar($c, $cp, 46); // '.'
            $c->aload(6);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getMethodName', '()Ljava/lang/String;'), 0, true);
            rtAppendTop($c, $cp);
            // Only a Moggi frame gets a location: a host frame's line number
            // belongs to that library's build, not to this one.
            $c->aload(6);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getFileName', '()Ljava/lang/String;'), 0, true);
            $c->ldc($cp->string_('.mog'));
            $c->invokevirtual($cp->methodRef('java/lang/String', 'endsWith', '(Ljava/lang/String;)Z'), 1, true);
            $c->ifeq('no_location');

            $c->aload(0);
            rtAppendString($c, $cp, ' (');
            $c->aload(6);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getFileName', '()Ljava/lang/String;'), 0, true);
            rtAppendTop($c, $cp);
            rtAppendChar($c, $cp, 58); // ':'
            $c->aload(6);
            $c->invokevirtual($cp->methodRef('java/lang/StackTraceElement', 'getLineNumber', '()I'), 0, true);
            rtAppendInt($c, $cp);
            rtAppendChar($c, $cp, 41); // ')'
            $c->pop_();

            $c->label('no_location');
            $c->noteFrame($hostLocals, ['java/lang/StringBuilder']);
            $c->aload(0);
            rtAppendChar($c, $cp, 10); // '\n'
            $c->pop_();
            $c->pop_();
            $c->iinc(4, 1);

            $c->label('next');
            $c->noteFrame($hostLocals);
            $c->iinc(3, 1);
            $c->goto_('loop');

            $c->label('elide');
            $c->noteFrame($hostLocals);
            $c->aload(0);
            rtAppendString($c, $cp, '  ... ');
            $c->aload(2);
            $c->arraylength();
            $c->iload(3);
            $c->isub();
            rtAppendInt($c, $cp);
            rtAppendString($c, $cp, " more\n");
            $c->pop_();

            $c->label('done');
            $c->noteFrame($hostLocals);
            $c->return_();
        }
    );
}

/**
 * throwSite / formatExceptionReport helpers (PHP runtime parity; the PHP side
 * is `src/backend/php/_runtime.php`).
 * throwSite Object[] = {Integer siteId, String symbolId, String displayPath,
 *   Integer line, Integer col}.
 */
function addRtLogicalExceptionMethods(ClassBuilder $b): void
{
    // throwSite(siteId, symbolId, displayPath, line, col)
    $b->addMethod(
        'throwSite',
        '(ILjava/lang/String;Ljava/lang/String;II)[Ljava/lang/Object;',
        0x0009,
        5,
        ['int', 'java/lang/String', 'java/lang/String', 'int', 'int'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->iconst(5);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->dup();
            $c->iconst(0);
            $c->iload(0);
            $c->invokestatic($cp->methodRef('java/lang/Integer', 'valueOf', '(I)Ljava/lang/Integer;'), 1, true);
            $c->aastore();
            $c->dup();
            $c->iconst(1);
            $c->aload(1);
            $c->aastore();
            $c->dup();
            $c->iconst(2);
            $c->aload(2);
            $c->aastore();
            $c->dup();
            $c->iconst(3);
            $c->iload(3);
            $c->invokestatic($cp->methodRef('java/lang/Integer', 'valueOf', '(I)Ljava/lang/Integer;'), 1, true);
            $c->aastore();
            $c->dup();
            $c->iconst(4);
            $c->iload(4);
            $c->invokestatic($cp->methodRef('java/lang/Integer', 'valueOf', '(I)Ljava/lang/Integer;'), 1, true);
            $c->aastore();
            $c->areturn();
        },
    );

    // Deprecated compact fallback: srcLoc(module, function, file, line, col)
    // → throwSite(0, module.function, displayPath(file), line, col).
    $b->addMethod(
        'srcLoc',
        '(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;II)[Ljava/lang/Object;',
        0x0009,
        6,
        ['java/lang/String', 'java/lang/String', 'java/lang/String', 'int', 'int'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            // locals: 0=module, 1=function, 2=file, 3=line, 4=col, 5=symbolId
            $c->aload(0);
            $c->invokevirtual($cp->methodRef('java/lang/String', 'isEmpty', '()Z'), 0, true);
            $c->ifeq('with_mod');
            $c->aload(1);
            $c->astore(5);
            $c->goto_('have_sym');
            $c->label('with_mod');
            $c->noteFrame(['java/lang/String', 'java/lang/String', 'java/lang/String', 'int', 'int']);
            $c->new_($cp->class_('java/lang/StringBuilder'));
            $c->dup();
            $c->invokespecial($cp->methodRef('java/lang/StringBuilder', '<init>', '()V'), 0, false);
            $c->aload(0);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->bipush(46); // '.'
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(C)Ljava/lang/StringBuilder;'), 1, true);
            $c->aload(1);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'toString', '()Ljava/lang/String;'), 0, true);
            $c->astore(5);
            $c->label('have_sym');
            $c->noteFrame(['java/lang/String', 'java/lang/String', 'java/lang/String', 'int', 'int', 'java/lang/String']);
            $c->iconst(0);
            $c->aload(5);
            $c->aload(2);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'displaySourcePath', '(Ljava/lang/String;)Ljava/lang/String;'),
                1,
                true,
            );
            $c->iload(3);
            $c->iload(4);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'throwSite', '(ILjava/lang/String;Ljava/lang/String;II)[Ljava/lang/Object;'),
                5,
                true,
            );
            $c->areturn();
        },
    );

    $b->addMethod(
        'exceptionAttachWrapper',
        '([Ljava/lang/Object;Lmoggi/rt/MoggiException;)[Ljava/lang/Object;',
        0x0009,
        2,
        ['[Ljava/lang/Object;', 'moggi/rt/MoggiException'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            // One extra slot carries the wrapper a caught payload came from, so a
            // rethrow of this value recovers the site it was thrown at.
            $c->aload(0);
            $c->iconst(5);
            $c->invokestatic(
                $cp->methodRef('java/util/Arrays', 'copyOf', '([Ljava/lang/Object;I)[Ljava/lang/Object;'),
                2,
                true,
            );
            $c->dup();
            $c->iconst(4);
            $c->aload(1);
            $c->aastore();
            $c->areturn();
        }
    );

    $b->addMethod(
        'displaySourcePath',
        '(Ljava/lang/String;)Ljava/lang/String;',
        0x0009,
        4,
        ['java/lang/String'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->ifnonnull('nonnull');
            $c->ldc($cp->string_(''));
            $c->areturn();
            $c->label('nonnull');
            $c->noteFrame(['java/lang/String']);
            $c->aload(0);
            $c->invokevirtual($cp->methodRef('java/lang/String', 'isEmpty', '()Z'), 0, true);
            $c->ifne('as_is');
            $c->aload(0);
            $c->ldc($cp->string_('<interactive>'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('normalize');
            $c->label('as_is');
            $c->noteFrame(['java/lang/String']);
            $c->aload(0);
            $c->areturn();

            $c->label('normalize');
            $c->noteFrame(['java/lang/String']);
            $c->aload(0);
            $c->bipush(92); // '\\'
            $c->bipush(47); // '/'
            $c->invokevirtual($cp->methodRef('java/lang/String', 'replace', '(CC)Ljava/lang/String;'), 2, true);
            $c->astore(1);

            foreach (['/lib/', '/tests/'] as $i => $marker) {
                $found = 'found_' . $i;
                $next = 'next_' . $i;
                $c->aload(1);
                $c->ldc($cp->string_($marker));
                $c->invokevirtual($cp->methodRef('java/lang/String', 'indexOf', '(Ljava/lang/String;)I'), 1, true);
                $c->istore(2);
                $c->iload(2);
                $c->iconst(0);
                $c->if_icmplt($next);
                $c->aload(1);
                $c->iload(2);
                $c->iconst(1);
                $c->opcode(0x60, -1); // iadd → skip leading '/'
                $c->invokevirtual($cp->methodRef('java/lang/String', 'substring', '(I)Ljava/lang/String;'), 1, true);
                $c->areturn();
                $c->label($next);
                $c->noteFrame(['java/lang/String', 'java/lang/String', 'int']);
            }

            foreach (['lib/', 'tests/'] as $i => $marker) {
                $ok = 'rel_ok_' . $i;
                $next = 'rel_next_' . $i;
                $c->aload(1);
                $c->ldc($cp->string_($marker));
                $c->invokevirtual($cp->methodRef('java/lang/String', 'startsWith', '(Ljava/lang/String;)Z'), 1, true);
                $c->ifeq($next);
                $c->aload(1);
                $c->areturn();
                $c->label($next);
                $c->noteFrame(['java/lang/String', 'java/lang/String', 'int']);
            }

            $c->new_($cp->class_('java/io/File'));
            $c->dup();
            $c->aload(1);
            $c->invokespecial($cp->methodRef('java/io/File', '<init>', '(Ljava/lang/String;)V'), 1, false);
            $c->invokevirtual($cp->methodRef('java/io/File', 'getName', '()Ljava/lang/String;'), 0, true);
            $c->areturn();
        }
    );

    // Append "  at symbolId (displayPath:line:col)\n" for throwSite layout.
    $b->addMethod(
        'appendFrameReport',
        '(Ljava/lang/StringBuilder;[Ljava/lang/Object;)V',
        0x0009,
        6,
        ['java/lang/StringBuilder', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            // locals: 0=sb, 1=site, 2=symbolId, 3=file, 4=line
            $c->aload(1);
            $c->ifnonnull('have');
            $c->return_();
            $c->label('have');
            $c->noteFrame(['java/lang/StringBuilder', '[Ljava/lang/Object;']);
            $c->aload(1);
            $c->arraylength();
            $c->iconst(5);
            $c->if_icmplt('done');

            $c->aload(1);
            $c->iconst(1);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->astore(2);
            $c->aload(1);
            $c->iconst(2);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'displaySourcePath', '(Ljava/lang/String;)Ljava/lang/String;'),
                1,
                true,
            );
            $c->astore(3);
            $c->aload(1);
            $c->iconst(3);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/Integer'));
            $c->invokevirtual($cp->methodRef('java/lang/Integer', 'intValue', '()I'), 0, true);
            $c->istore(4);

            $c->aload(3);
            $c->invokevirtual($cp->methodRef('java/lang/String', 'isEmpty', '()Z'), 0, true);
            $c->ifne('no_file');
            $c->iload(4);
            $c->ifle('no_file');

            $c->aload(0);
            $c->ldc($cp->string_('  at '));
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->aload(2);
            $c->invokevirtual($cp->methodRef('java/lang/String', 'isEmpty', '()Z'), 0, true);
            $c->ifeq('use_sym');
            $c->ldc($cp->string_('<unknown>'));
            $c->goto_('have_sym');
            $c->label('use_sym');
            $c->noteFrame(
                ['java/lang/StringBuilder', '[Ljava/lang/Object;', 'java/lang/String', 'java/lang/String', 'int'],
                ['java/lang/StringBuilder'],
            );
            $c->aload(2);
            $c->label('have_sym');
            $c->noteFrame(
                ['java/lang/StringBuilder', '[Ljava/lang/Object;', 'java/lang/String', 'java/lang/String', 'int'],
                ['java/lang/StringBuilder', 'java/lang/String'],
            );
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->ldc($cp->string_(' ('));
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->aload(3);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->bipush(58); // ':'
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(C)Ljava/lang/StringBuilder;'), 1, true);
            $c->iload(4);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(I)Ljava/lang/StringBuilder;'), 1, true);
            $c->bipush(58);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(C)Ljava/lang/StringBuilder;'), 1, true);
            $c->aload(1);
            $c->iconst(4);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/Integer'));
            $c->invokevirtual($cp->methodRef('java/lang/Integer', 'intValue', '()I'), 0, true);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(I)Ljava/lang/StringBuilder;'), 1, true);
            $c->bipush(41); // ')'
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(C)Ljava/lang/StringBuilder;'), 1, true);
            $c->bipush(10);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(C)Ljava/lang/StringBuilder;'), 1, true);
            $c->pop_();
            $c->return_();

            $c->label('no_file');
            $c->noteFrame(['java/lang/StringBuilder', '[Ljava/lang/Object;', 'java/lang/String', 'java/lang/String', 'int']);
            $c->aload(2);
            $c->invokevirtual($cp->methodRef('java/lang/String', 'isEmpty', '()Z'), 0, true);
            $c->ifne('done');
            $c->aload(0);
            $c->ldc($cp->string_('  at '));
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->aload(2);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->bipush(10);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(C)Ljava/lang/StringBuilder;'), 1, true);
            $c->pop_();
            $c->label('done');
            $c->noteFrame(['java/lang/StringBuilder', '[Ljava/lang/Object;', 'top', 'top', 'top']);
            $c->return_();
        }
    );

    addRtFrameAppenders($b);

    $b->addMethod(
        'formatExceptionReport',
        '(Ljava/lang/Throwable;)Ljava/lang/String;',
        0x0009,
        6,
        ['java/lang/Throwable'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            // Fail-safe: any unexpected error while formatting yields a fixed message.
            $throwable = $cp->class_('java/lang/Throwable');
            $meInit = $cp->methodRef(
                'moggi/rt/MoggiException',
                '<init>',
                '([Ljava/lang/Object;[Ljava/lang/Object;Ljava/lang/Throwable;)V',
            );

            $c->label('try_start');
            // locals: 0=e, 1=me, 2=sb, 3=tag, 4=cause
            $c->aload(0);
            $c->instanceof_($cp->class_('moggi/rt/MoggiException'));
            $c->ifne('is_me');
            $c->new_($cp->class_('moggi/rt/MoggiException'));
            $c->dup();
            $c->aload(0);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'normalizeHostException', '(Ljava/lang/Throwable;)[Ljava/lang/Object;'),
                1,
                true,
            );
            $c->aconst_null(); // throwSite
            $c->aload(0); // cause
            $c->invokespecial($meInit, 3, false);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'formatExceptionReport', '(Ljava/lang/Throwable;)Ljava/lang/String;'),
                1,
                true,
            );
            $c->areturn();

            $c->label('is_me');
            $c->noteFrame(['java/lang/Throwable']);
            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/MoggiException'));
            $c->astore(1);

            $c->new_($cp->class_('java/lang/StringBuilder'));
            $c->dup();
            $c->invokespecial($cp->methodRef('java/lang/StringBuilder', '<init>', '()V'), 0, false);
            $c->astore(2);

            $c->aload(1);
            $c->getfield($cp->fieldRef('moggi/rt/MoggiException', 'someException', '[Ljava/lang/Object;'));
            $c->iconst(1);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->astore(3);

            $c->aload(2);
            $c->ldc($cp->string_('moggi: '));
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->aload(3);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->ldc($cp->string_(': '));
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->aload(1);
            $c->invokevirtual($cp->methodRef('java/lang/Throwable', 'getMessage', '()Ljava/lang/String;'), 0, true);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->bipush(10);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(C)Ljava/lang/StringBuilder;'), 1, true);
            $c->pop_();

            // Throw site: stamped at the throw, always exact.
            $c->aload(2);
            $c->aload(1);
            $c->getfield($cp->fieldRef('moggi/rt/MoggiException', 'throwSite', '[Ljava/lang/Object;'));
            $c->invokestatic(
                $cp->methodRef(
                    'moggi/rt/RT',
                    'appendFrameReport',
                    '(Ljava/lang/StringBuilder;[Ljava/lang/Object;)V',
                ),
                2,
                false,
            );

            // Caller frames, translated from the host stack through the baked table.
            $c->aload(2);
            $c->aload(1);
            $c->aload(1);
            $c->getfield($cp->fieldRef('moggi/rt/MoggiException', 'throwSite', '[Ljava/lang/Object;'));
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'appendMoggFrames', '(Ljava/lang/StringBuilder;Ljava/lang/Throwable;[Ljava/lang/Object;)V'),
                3,
                false,
            );

            $c->aload(1);
            $c->invokevirtual($cp->methodRef('java/lang/Throwable', 'getCause', '()Ljava/lang/Throwable;'), 0, true);
            $c->astore(4);
            $c->aload(4);
            $c->ifnull('no_host');

            $c->noteFrame([
                'java/lang/Throwable', 'moggi/rt/MoggiException', 'java/lang/StringBuilder',
                'java/lang/String', 'java/lang/Throwable',
            ]);
            $c->aload(2);
            $c->ldc($cp->string_('caused by: '));
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->aload(4);
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'getClass', '()Ljava/lang/Class;'), 0, true);
            $c->invokevirtual($cp->methodRef('java/lang/Class', 'getName', '()Ljava/lang/String;'), 0, true);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->ldc($cp->string_(': '));
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->aload(4);
            $c->invokevirtual($cp->methodRef('java/lang/Throwable', 'getMessage', '()Ljava/lang/String;'), 0, true);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(Ljava/lang/String;)Ljava/lang/StringBuilder;'), 1, true);
            $c->bipush(10);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'append', '(C)Ljava/lang/StringBuilder;'), 1, true);
            $c->pop_();
            $c->aload(2);
            $c->aload(4);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'appendHostFrames', '(Ljava/lang/StringBuilder;Ljava/lang/Throwable;)V'),
                2,
                false,
            );

            $c->label('no_host');
            $c->noteFrame([
                'java/lang/Throwable', 'moggi/rt/MoggiException', 'java/lang/StringBuilder',
                'java/lang/String', 'java/lang/Throwable',
            ]);
            $c->aload(2);
            $c->invokevirtual($cp->methodRef('java/lang/StringBuilder', 'toString', '()Ljava/lang/String;'), 0, true);
            $c->areturn();
            $c->label('try_end');

            $c->label('handler');
            $c->exception('try_start', 'try_end', 'handler', $throwable);
            $c->assumeStackDelta(1);
            $c->noteFrame(['java/lang/Throwable'], ['java/lang/Throwable']);
            $c->pop_();
            $c->ldc($cp->string_("moggi: error: failed to format exception report\n"));
            $c->areturn();
        }
    );

    $b->addMethod(
        'reportUncaught',
        '(Ljava/lang/Throwable;)V',
        0x0009,
        2,
        ['java/lang/Throwable'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->getstatic($cp->fieldRef('java/lang/System', 'err', 'Ljava/io/PrintStream;'));
            $c->aload(0);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'formatExceptionReport', '(Ljava/lang/Throwable;)Ljava/lang/String;'),
                1,
                true,
            );
            $c->invokevirtual($cp->methodRef('java/io/PrintStream', 'print', '(Ljava/lang/String;)V'), 1, false);
            $c->iconst(1);
            $c->invokestatic($cp->methodRef('java/lang/System', 'exit', '(I)V'), 1, false);
            $c->return_();
        }
    );
}

function buildRTClass(): string
{
    $b = new ClassBuilder('moggi/rt/RT');

    addRtLogicalExceptionMethods($b);

    $b->addMethod(
        'apply',
        '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;',
        0x0009,
        8,
        ['java/lang/Object', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->instanceof_($cp->class_('moggi/rt/Partial'));
            $c->ifeq('tryTopLevel');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;']);
            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/Partial'));
            $c->astore(2);
            $c->goto_('havePartial');

            $c->label('tryTopLevel');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;']);
            $c->aload(0);
            $c->instanceof_($cp->class_('moggi/rt/TopLevelFn'));
            $c->ifeq('tryFn');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;']);
            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/TopLevelFn'));
            $c->astore(3);
            $c->new_($cp->class_('moggi/rt/Partial'));
            $c->dup();
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/TopLevelFn', 'arity', 'I'));
            $c->aload(3);
            $c->iconst(0);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->invokespecial($cp->methodRef('moggi/rt/Partial', '<init>', '(ILmoggi/rt/Fn;[Ljava/lang/Object;)V'), 3, false);
            $c->astore(2);
            $c->goto_('havePartial');

            $c->label('tryFn');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;']);
            $c->aload(0);
            $c->instanceof_($cp->class_('moggi/rt/Fn'));
            $c->ifeq('fail');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;']);
            $c->aload(1);
            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/Fn'));
            $c->swap();
            $c->invokeinterface($cp->ifaceMethodRef('moggi/rt/Fn', 'invoke', '([Ljava/lang/Object;)Ljava/lang/Object;'), 1, true);
            $c->areturn();

            $c->label('havePartial');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;', 'moggi/rt/Partial']);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'arity', 'I'));
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'args', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->isub();
            $c->istore(3);
            $c->aload(1);
            $c->arraylength();
            $c->istore(4);
            $c->iload(4);
            $c->iload(3);
            $c->if_icmpge('enoughArgs');
            $c->new_($cp->class_('moggi/rt/Partial'));
            $c->dup();
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'arity', 'I'));
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'target', 'Lmoggi/rt/Fn;'));
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'args', '[Ljava/lang/Object;'));
            $c->aload(1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'concatArgs', '([Ljava/lang/Object;[Ljava/lang/Object;)[Ljava/lang/Object;'), 2, true);
            $c->invokespecial($cp->methodRef('moggi/rt/Partial', '<init>', '(ILmoggi/rt/Fn;[Ljava/lang/Object;)V'), 3, false);
            $c->areturn();

            $c->label('enoughArgs');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;', 'moggi/rt/Partial', 'int', 'int']);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'args', '[Ljava/lang/Object;'));
            $c->aload(1);
            $c->iload(3);
            // Single allocation: the old takeArgs+concatArgs pair allocated twice
            // and copied the applied arguments three times.
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'concatTake', '([Ljava/lang/Object;[Ljava/lang/Object;I)[Ljava/lang/Object;'), 3, true);
            $c->astore(5);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'target', 'Lmoggi/rt/Fn;'));
            $c->aload(5);
            $c->invokeinterface($cp->ifaceMethodRef('moggi/rt/Fn', 'invoke', '([Ljava/lang/Object;)Ljava/lang/Object;'), 1, true);
            $c->astore(6);
            $c->iload(4);
            $c->iload(3);
            $c->if_icmpne('applyRest');
            $c->aload(6);
            $c->areturn();

            $c->label('applyRest');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;', 'moggi/rt/Partial', 'int', 'int', '[Ljava/lang/Object;', 'java/lang/Object']);
            $c->aload(6);
            $c->aload(1);
            $c->iload(3);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'dropArgs', '([Ljava/lang/Object;I)[Ljava/lang/Object;'), 2, true);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'apply', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'), 2, true);
            $c->areturn();

            $c->label('fail');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;']);
            $c->new_($cp->class_('java/lang/IllegalArgumentException'));
            $c->dup();
            $c->ldc($cp->string_('RT.apply: not a Fn or Partial'));
            $c->invokespecial($cp->methodRef('java/lang/IllegalArgumentException', '<init>', '(Ljava/lang/String;)V'), 1, false);
            $c->athrow();
        }
    );

    // Scalar-argument specialization of apply — the dominant call shape: it skips
    // the caller-side Object[] and allocates one array instead of two. Non-Partial
    // callees and already-saturated cells defer to apply.
    $b->addMethod(
        'apply1',
        '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Object;',
        0x0009,
        5,
        ['java/lang/Object', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $object = $cp->class_('java/lang/Object');
            $partial = $cp->class_('moggi/rt/Partial');

            $c->aload(0);
            $c->instanceof_($partial);
            $c->ifeq('general');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->checkcast($partial);
            $c->astore(2);

            // remaining = partial.arity - partial.args.length
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'arity', 'I'));
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'args', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->isub();
            $c->istore(3);

            // Already saturated (remaining <= 0): defer, so surplus handling
            // stays in one place.
            $c->iload(3);
            $c->iconst(1);
            $c->if_icmplt('general');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Partial', 'int']);

            // Object[] grown = new Object[partial.args.length + 1];
            // System.arraycopy(partial.args, 0, grown, 0, partial.args.length);
            // grown[partial.args.length] = arg;
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'args', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->iconst(1);
            $c->iadd();
            $c->anewarray($object);
            $c->astore(4);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'args', '[Ljava/lang/Object;'));
            $c->iconst(0);
            $c->aload(4);
            $c->iconst(0);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'args', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->invokestatic($cp->methodRef('java/lang/System', 'arraycopy', '(Ljava/lang/Object;ILjava/lang/Object;II)V'), 5, false);
            $c->aload(4);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'args', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->aload(1);
            $c->aastore();

            $c->iload(3);
            $c->iconst(1);
            $c->if_icmpne('extend');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Partial', 'int', '[Ljava/lang/Object;']);

            // Exactly saturated: invoke directly.
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'target', 'Lmoggi/rt/Fn;'));
            $c->aload(4);
            $c->invokeinterface($cp->ifaceMethodRef('moggi/rt/Fn', 'invoke', '([Ljava/lang/Object;)Ljava/lang/Object;'), 1, true);
            $c->areturn();

            $c->label('extend');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Partial', 'int', '[Ljava/lang/Object;']);
            $c->new_($partial);
            $c->dup();
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'arity', 'I'));
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Partial', 'target', 'Lmoggi/rt/Fn;'));
            $c->aload(4);
            $c->invokespecial($cp->methodRef('moggi/rt/Partial', '<init>', '(ILmoggi/rt/Fn;[Ljava/lang/Object;)V'), 3, false);
            $c->areturn();

            $c->label('general');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->iconst(1);
            $c->anewarray($object);
            $c->dup();
            $c->iconst(0);
            $c->aload(1);
            $c->aastore();
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'apply', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'), 2, true);
            $c->areturn();
        }
    );

    $b->addMethod(
        'concatArgs',
        '([Ljava/lang/Object;[Ljava/lang/Object;)[Ljava/lang/Object;',
        0x0009,
        4,
        ['[Ljava/lang/Object;', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->arraylength();
            $c->aload(1);
            $c->arraylength();
            $c->iadd();
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->astore(2);
            $c->aload(0);
            $c->iconst(0);
            $c->aload(2);
            $c->iconst(0);
            $c->aload(0);
            $c->arraylength();
            $c->invokestatic($cp->methodRef('java/lang/System', 'arraycopy', '(Ljava/lang/Object;ILjava/lang/Object;II)V'), 5, false);
            $c->aload(1);
            $c->iconst(0);
            $c->aload(2);
            $c->aload(0);
            $c->arraylength();
            $c->aload(1);
            $c->arraylength();
            $c->invokestatic($cp->methodRef('java/lang/System', 'arraycopy', '(Ljava/lang/Object;ILjava/lang/Object;II)V'), 5, false);
            $c->aload(2);
            $c->areturn();
        }
    );

    // concatArgs(prefix, takeArgs(src, n)) without the intermediate array:
    // one allocation and two copies instead of two allocations and three.
    $b->addMethod(
        'concatTake',
        '([Ljava/lang/Object;[Ljava/lang/Object;I)[Ljava/lang/Object;',
        0x0009,
        4,
        ['[Ljava/lang/Object;', '[Ljava/lang/Object;', 'int'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            // Object[] out = new Object[prefix.length + n];
            $c->aload(0);
            $c->arraylength();
            $c->iload(2);
            $c->iadd();
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->astore(3);
            // System.arraycopy(prefix, 0, out, 0, prefix.length);
            $c->aload(0);
            $c->iconst(0);
            $c->aload(3);
            $c->iconst(0);
            $c->aload(0);
            $c->arraylength();
            $c->invokestatic($cp->methodRef('java/lang/System', 'arraycopy', '(Ljava/lang/Object;ILjava/lang/Object;II)V'), 5, false);
            // System.arraycopy(src, 0, out, prefix.length, n);
            $c->aload(1);
            $c->iconst(0);
            $c->aload(3);
            $c->aload(0);
            $c->arraylength();
            $c->iload(2);
            $c->invokestatic($cp->methodRef('java/lang/System', 'arraycopy', '(Ljava/lang/Object;ILjava/lang/Object;II)V'), 5, false);
            $c->aload(3);
            $c->areturn();
        }
    );

    $b->addMethod(
        'dropArgs',
        '([Ljava/lang/Object;I)[Ljava/lang/Object;',
        0x0009,
        3,
        ['[Ljava/lang/Object;', 'int'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->iload(1);
            $c->aload(0);
            $c->arraylength();
            $c->invokestatic($cp->methodRef('java/util/Arrays', 'copyOfRange', '([Ljava/lang/Object;II)[Ljava/lang/Object;'), 3, true);
            $c->areturn();
        }
    );

    $b->addMethod(
        'dictMethod',
        '(Ljava/lang/Object;Ljava/lang/String;)Ljava/lang/Object;',
        0x0009,
        2,
        ['java/lang/Object', 'java/lang/String'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/Dict'));
            $c->aload(1);
            $c->invokevirtual($cp->methodRef('moggi/rt/Dict', 'lookup', '(Ljava/lang/String;)Ljava/lang/Object;'), 1, true);
            $c->areturn();
        }
    );

    $b->addMethod(
        'dictCall',
        '(Ljava/lang/Object;Ljava/lang/String;[Ljava/lang/Object;)Ljava/lang/Object;',
        0x0009,
        3,
        ['java/lang/Object', 'java/lang/String', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->aload(1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'dictMethod', '(Ljava/lang/Object;Ljava/lang/String;)Ljava/lang/Object;'), 2, true);
            $c->aload(2);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'apply', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'), 2, true);
            $c->areturn();
        }
    );

    $b->addMethod(
        'cons',
        '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/MList;',
        0x0009,
        2,
        ['java/lang/Object', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->new_($cp->class_('moggi/rt/MList'));
            $c->dup();
            $c->aload(0);
            $c->aload(1);
            $c->checkcast($cp->class_('moggi/rt/MList'));
            $c->invokespecial($cp->methodRef('moggi/rt/MList', '<init>', '(Ljava/lang/Object;Lmoggi/rt/MList;)V'), 2, false);
            $c->areturn();
        }
    );

    $b->addMethod(
        'listAppend',
        '(Lmoggi/rt/MList;Lmoggi/rt/MList;)Lmoggi/rt/MList;',
        0x0009,
        4,
        ['moggi/rt/MList', 'moggi/rt/MList'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->ifnonnull('append_loop');
            $c->noteFrame(['moggi/rt/MList', 'moggi/rt/MList']);
            $c->aload(1);
            $c->areturn();

            $c->label('append_loop');
            $c->noteFrame(['moggi/rt/MList', 'moggi/rt/MList']);
            $c->aconst_null();
            $c->astore(2); // rev prefix
            $c->aload(0);
            $c->astore(3); // cursor

            $c->label('append_scan');
            $c->noteFrame(['moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList']);
            $c->aload(3);
            $c->ifnull('append_rebuild');
            $c->noteFrame(['moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList']);
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
            $c->aload(2);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'cons', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/MList;'), 2, true);
            $c->astore(2);
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
            $c->astore(3);
            $c->goto_('append_scan');

            $c->label('append_rebuild');
            $c->noteFrame(['moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList']);
            $c->aload(1);
            $c->astore(3);

            $c->label('append_emit');
            $c->noteFrame(['moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList']);
            $c->aload(2);
            $c->ifnull('append_done');
            $c->noteFrame(['moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList']);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
            $c->aload(3);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'cons', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/MList;'), 2, true);
            $c->astore(3);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
            $c->astore(2);
            $c->goto_('append_emit');

            $c->label('append_done');
            $c->noteFrame(['moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList', 'moggi/rt/MList']);
            $c->aload(3);
            $c->areturn();
        }
    );

    // Structural equality for scalars, lists, and Cons (tag + fields).
    $b->addMethod(
        'valueEq',
        '(Ljava/lang/Object;Ljava/lang/Object;)Z',
        0x0009,
        6,
        ['java/lang/Object', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->aload(1);
            $c->if_acmpne('ve_not_same');
            $c->iconst(1);
            $c->ireturn();

            $c->label('ve_not_same');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->ifnonnull('ve_left_some');
            $c->iconst(0);
            $c->ireturn();

            $c->label('ve_left_some');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(1);
            $c->ifnonnull('ve_right_some');
            $c->iconst(0);
            $c->ireturn();

            $c->label('ve_right_some');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->instanceof_($cp->class_('moggi/rt/MList'));
            $c->ifeq('ve_not_list');
            $c->aload(1);
            $c->instanceof_($cp->class_('moggi/rt/MList'));
            $c->ifeq('ve_not_list');
            $c->aload(0);
            $c->aload(1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'listEq', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'), 2, true);
            $c->invokevirtual($cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $c->ireturn();

            $c->label('ve_not_list');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->instanceof_($cp->class_('moggi/rt/Con'));
            $c->ifeq('ve_not_con');
            $c->aload(1);
            $c->instanceof_($cp->class_('moggi/rt/Con'));
            $c->ifeq('ve_not_con');
            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/Con'));
            $c->astore(2);
            $c->aload(1);
            $c->checkcast($cp->class_('moggi/rt/Con'));
            $c->astore(3);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('ve_false');
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->if_icmpne('ve_false');
            $c->iconst(0);
            $c->istore(4);
            $c->label('ve_field_loop');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con', 'int']);
            $c->iload(4);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->if_icmpge('ve_true');
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iload(4);
            $c->aaload();
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iload(4);
            $c->aaload();
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'valueEq', '(Ljava/lang/Object;Ljava/lang/Object;)Z'), 2, true);
            $c->ifeq('ve_false');
            $c->iinc(4, 1);
            $c->goto_('ve_field_loop');

            $c->label('ve_true');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con', 'int']);
            $c->iconst(1);
            $c->ireturn();

            $c->label('ve_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->iconst(0);
            $c->ireturn();

            // Tuples are `Object[]`: element-wise, and an array does not compare its elements.
            $c->label('ve_not_con');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->instanceof_($cp->class_('[Ljava/lang/Object;'));
            $c->ifeq('ve_fallback');
            $c->aload(1);
            $c->instanceof_($cp->class_('[Ljava/lang/Object;'));
            $c->ifeq('ve_fallback');
            $c->aload(0);
            $c->checkcast($cp->class_('[Ljava/lang/Object;'));
            $c->astore(2);
            $c->aload(1);
            $c->checkcast($cp->class_('[Ljava/lang/Object;'));
            $c->astore(3);
            // Lengths into locals first: every edge into the loop labels must already
            // declare the counter slot.
            $c->aload(2);
            $c->arraylength();
            $c->istore(4);
            $c->aload(3);
            $c->arraylength();
            $c->istore(5);
            $c->iload(4);
            $c->iload(5);
            $c->if_icmpne('ve_tuple_false');
            $c->iconst(0);
            $c->istore(4);
            $c->label('ve_tuple_loop');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', '[Ljava/lang/Object;', '[Ljava/lang/Object;', 'int', 'int']);
            $c->iload(4);
            $c->aload(2);
            $c->arraylength();
            $c->if_icmpge('ve_tuple_true');
            $c->aload(2);
            $c->iload(4);
            $c->aaload();
            $c->aload(3);
            $c->iload(4);
            $c->aaload();
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'valueEq', '(Ljava/lang/Object;Ljava/lang/Object;)Z'), 2, true);
            $c->ifeq('ve_tuple_false');
            $c->iinc(4, 1);
            $c->goto_('ve_tuple_loop');

            // Own targets: the Con loop reaches `ve_true`/`ve_false` with `Con` in those slots.
            $c->label('ve_tuple_true');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', '[Ljava/lang/Object;', '[Ljava/lang/Object;', 'int', 'int']);
            $c->iconst(1);
            $c->ireturn();

            $c->label('ve_tuple_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', '[Ljava/lang/Object;', '[Ljava/lang/Object;', 'int', 'int']);
            $c->iconst(0);
            $c->ireturn();

            $c->label('ve_fallback');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->aload(1);
            $c->invokestatic($cp->methodRef('java/util/Objects', 'equals', '(Ljava/lang/Object;Ljava/lang/Object;)Z'), 2, true);
            $c->ireturn();
        }
    );

    $b->addMethod(
        'listEq',
        '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;',
        0x0009,
        4,
        ['java/lang/Object', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->astore(2);
            $c->aload(1);
            $c->astore(3);

            $c->label('eq_loop');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->aload(2);
            $c->ifnonnull('eq_left_some');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->aload(3);
            $c->ifnonnull('eq_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->iconst(1);
            $c->invokestatic($cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            $c->areturn();

            $c->label('eq_left_some');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->aload(3);
            $c->ifnull('eq_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->aload(2);
            $c->checkcast($cp->class_('moggi/rt/MList'));
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
            $c->aload(3);
            $c->checkcast($cp->class_('moggi/rt/MList'));
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'valueEq', '(Ljava/lang/Object;Ljava/lang/Object;)Z'), 2, true);
            $c->ifeq('eq_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->aload(2);
            $c->checkcast($cp->class_('moggi/rt/MList'));
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
            $c->astore(2);
            $c->aload(3);
            $c->checkcast($cp->class_('moggi/rt/MList'));
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
            $c->astore(3);
            $c->goto_('eq_loop');

            $c->label('eq_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->iconst(0);
            $c->invokestatic($cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            $c->areturn();
        }
    );

    // int (-1/0/1) → Ordering Con
    $b->addMethod(
        'orderingFromInt',
        '(I)Lmoggi/rt/Con;',
        0x0009,
        2,
        ['int'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->iload(0);
            $c->iflt('lt');
            $c->iload(0);
            $c->ifne('gt');
            $c->ldc($cp->string_('EQ'));
            $c->goto_('mk');
            $c->label('lt');
            $c->noteFrame(['int']);
            $c->ldc($cp->string_('LT'));
            $c->goto_('mk');
            $c->label('gt');
            $c->noteFrame(['int']);
            $c->ldc($cp->string_('GT'));
            $c->label('mk');
            $c->noteFrame(['int'], ['java/lang/String']);
            $c->iconst(0);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'), 2, true);
            $c->areturn();
        }
    );

    // Ordering Con → int (-1/0/1)
    $b->addMethod(
        'orderingToInt',
        '(Lmoggi/rt/Con;)I',
        0x0009,
        2,
        ['moggi/rt/Con'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->astore(1);
            $c->aload(1);
            $c->ldc($cp->string_('LT'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('not_lt');
            $c->iconst(-1);
            $c->ireturn();
            $c->label('not_lt');
            $c->noteFrame(['moggi/rt/Con', 'java/lang/String']);
            $c->aload(1);
            $c->ldc($cp->string_('GT'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('eq');
            $c->iconst(1);
            $c->ireturn();
            $c->label('eq');
            $c->noteFrame(['moggi/rt/Con', 'java/lang/String']);
            $c->iconst(0);
            $c->ireturn();
        }
    );

    $b->addMethod(
        'isOrderingCon',
        '(Lmoggi/rt/Con;)Z',
        0x0009,
        2,
        ['moggi/rt/Con'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->astore(1);
            $c->aload(1);
            $c->ldc($cp->string_('LT'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifne('yes');
            $c->aload(1);
            $c->ldc($cp->string_('EQ'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifne('yes');
            $c->aload(1);
            $c->ldc($cp->string_('GT'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifne('yes');
            $c->iconst(0);
            $c->ireturn();
            $c->label('yes');
            $c->noteFrame(['moggi/rt/Con', 'java/lang/String']);
            $c->iconst(1);
            $c->ireturn();
        }
    );

    // Structural/Comparable value ordering used by list/maybe compare.
    $b->addMethod(
        'valueCompare',
        '(Ljava/lang/Object;Ljava/lang/Object;)I',
        0x0009,
        6,
        ['java/lang/Object', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->aload(1);
            $c->if_acmpne('not_same');
            $c->iconst(0);
            $c->ireturn();

            $c->label('not_same');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->ifnonnull('left_some');
            $c->iconst(-1);
            $c->ireturn();

            $c->label('left_some');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(1);
            $c->ifnonnull('right_some');
            $c->iconst(1);
            $c->ireturn();

            $c->label('right_some');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->instanceof_($cp->class_('moggi/rt/MList'));
            $c->ifeq('not_list');
            $c->aload(1);
            $c->instanceof_($cp->class_('moggi/rt/MList'));
            $c->ifeq('not_list');
            $c->aload(0);
            $c->aload(1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'listCompare', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/Con;'), 2, true);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingToInt', '(Lmoggi/rt/Con;)I'), 1, true);
            $c->ireturn();

            $c->label('not_list');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->instanceof_($cp->class_('moggi/rt/Con'));
            $c->ifeq('not_con');
            $c->aload(1);
            $c->instanceof_($cp->class_('moggi/rt/Con'));
            $c->ifeq('not_con');
            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/Con'));
            $c->astore(2);
            $c->aload(1);
            $c->checkcast($cp->class_('moggi/rt/Con'));
            $c->astore(3);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('tags_differ');
            $c->iconst(0);
            $c->istore(4); // unused at entry; field_loop stores cmp here
            $c->goto_('same_tag');

            // Different tags: Ordering uses semantic order (LT<EQ<GT); others
            // fall back to lexicographic tag order (declaration order is not
            // available at runtime).
            $c->label('tags_differ');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con']);
            $c->aload(2);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'isOrderingCon', '(Lmoggi/rt/Con;)Z'), 1, true);
            $c->ifeq('lex_tags');
            $c->aload(3);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'isOrderingCon', '(Lmoggi/rt/Con;)Z'), 1, true);
            $c->ifeq('lex_tags');
            $c->aload(2);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingToInt', '(Lmoggi/rt/Con;)I'), 1, true);
            $c->aload(3);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingToInt', '(Lmoggi/rt/Con;)I'), 1, true);
            $c->isub();
            $c->ireturn();

            $c->label('lex_tags');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con']);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->invokevirtual($cp->methodRef('java/lang/String', 'compareTo', '(Ljava/lang/String;)I'), 1, true);
            $c->ireturn();

            $c->label('same_tag');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con', 'int']);
            $c->iconst(0);
            $c->istore(5); // i
            $c->label('field_loop');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con', 'int', 'int']);
            $c->iload(5);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->if_icmpge('fields_done');
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iload(5);
            $c->aaload();
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iload(5);
            $c->aaload();
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'valueCompare', '(Ljava/lang/Object;Ljava/lang/Object;)I'), 2, true);
            $c->istore(4);
            $c->iload(4);
            $c->ifeq('field_next');
            $c->iload(4);
            $c->ireturn();
            $c->label('field_next');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con', 'int', 'int']);
            $c->iinc(5, 1);
            $c->goto_('field_loop');
            $c->label('fields_done');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con', 'int', 'int']);
            $c->iconst(0);
            $c->ireturn();

            $c->label('not_con');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->instanceof_($cp->class_('java/lang/Comparable'));
            $c->ifeq('fallback');
            $c->aload(0);
            $c->checkcast($cp->class_('java/lang/Comparable'));
            $c->aload(1);
            $c->invokeinterface($cp->ifaceMethodRef('java/lang/Comparable', 'compareTo', '(Ljava/lang/Object;)I'), 1, true);
            $c->ireturn();

            $c->label('fallback');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->aload(1);
            $c->invokestatic($cp->methodRef('java/util/Objects', 'equals', '(Ljava/lang/Object;Ljava/lang/Object;)Z'), 2, true);
            $c->ifeq('fallback_gt');
            $c->iconst(0);
            $c->ireturn();
            $c->label('fallback_gt');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->iconst(1);
            $c->ireturn();
        }
    );

    $b->addMethod(
        'listCompare',
        '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/Con;',
        0x0009,
        5,
        ['java/lang/Object', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->astore(2);
            $c->aload(1);
            $c->astore(3);

            $c->label('cmp_loop');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->aload(2);
            $c->ifnonnull('left_some');
            $c->aload(3);
            $c->ifnonnull('right_only');
            $c->iconst(0);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'), 1, true);
            $c->areturn();

            $c->label('right_only');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->iconst(-1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'), 1, true);
            $c->areturn();

            $c->label('left_some');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->aload(3);
            $c->ifnonnull('both_some');
            $c->iconst(1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'), 1, true);
            $c->areturn();

            $c->label('both_some');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object']);
            $c->aload(2);
            $c->checkcast($cp->class_('moggi/rt/MList'));
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
            $c->aload(3);
            $c->checkcast($cp->class_('moggi/rt/MList'));
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'valueCompare', '(Ljava/lang/Object;Ljava/lang/Object;)I'), 2, true);
            $c->istore(4);
            $c->iload(4);
            $c->ifeq('heads_eq');
            $c->iload(4);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'), 1, true);
            $c->areturn();

            $c->label('heads_eq');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'java/lang/Object', 'int']);
            $c->aload(2);
            $c->checkcast($cp->class_('moggi/rt/MList'));
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
            $c->astore(2);
            $c->aload(3);
            $c->checkcast($cp->class_('moggi/rt/MList'));
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
            $c->astore(3);
            $c->goto_('cmp_loop');
        }
    );

    $b->addMethod(
        'maybeCompare',
        '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/Con;',
        0x0009,
        6,
        ['java/lang/Object', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/Con'));
            $c->astore(2);
            $c->aload(1);
            $c->checkcast($cp->class_('moggi/rt/Con'));
            $c->astore(3);

            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->ldc($cp->string_('Nothing'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->istore(4);
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->ldc($cp->string_('Nothing'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->istore(5);

            $c->iload(4);
            $c->ifeq('left_just');
            $c->iload(5);
            $c->ifeq('left_nothing_right_just');
            $c->iconst(0);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'), 1, true);
            $c->areturn();

            $c->label('left_nothing_right_just');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con', 'int', 'int']);
            $c->iconst(-1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'), 1, true);
            $c->areturn();

            $c->label('left_just');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con', 'int', 'int']);
            $c->iload(5);
            $c->ifeq('both_just');
            $c->iconst(1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'), 1, true);
            $c->areturn();

            $c->label('both_just');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con', 'int', 'int']);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iconst(0);
            $c->aaload();
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iconst(0);
            $c->aaload();
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'valueCompare', '(Ljava/lang/Object;Ljava/lang/Object;)I'), 2, true);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'), 1, true);
            $c->areturn();
        }
    );

    $b->addMethod(
        'maybeEq',
        '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;',
        0x0009,
        4,
        ['java/lang/Object', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->aload(1);
            $c->if_acmpne('maybeCompare#');
            $c->iconst(1);
            $c->invokestatic($cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            $c->areturn();

            $c->label('maybeCompare#');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(0);
            $c->ifnull('maybe_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->aload(1);
            $c->ifnull('maybe_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);

            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/Con'));
            $c->astore(2);
            $c->aload(1);
            $c->checkcast($cp->class_('moggi/rt/Con'));
            $c->astore(3);

            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('maybe_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con']);

            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->if_icmpne('maybe_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con']);

            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->ifne('maybe_just');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con']);
            $c->iconst(1);
            $c->invokestatic($cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            $c->areturn();

            $c->label('maybe_just');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object', 'moggi/rt/Con', 'moggi/rt/Con']);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iconst(0);
            $c->aaload();
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iconst(0);
            $c->aaload();
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'valueEq', '(Ljava/lang/Object;Ljava/lang/Object;)Z'), 2, true);
            $c->invokestatic($cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            $c->areturn();

            $c->label('maybe_false');
            $c->noteFrame(['java/lang/Object', 'java/lang/Object']);
            $c->iconst(0);
            $c->invokestatic($cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            $c->areturn();
        }
    );

    $b->addMethod(
        'boxInt',
        '(J)Ljava/lang/Long;',
        0x0009,
        2,
        ['long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->invokestatic($cp->methodRef('java/lang/Long', 'valueOf', '(J)Ljava/lang/Long;'), 2, 1);
            $c->areturn();
        }
    );

    $b->addMethod(
        'unboxInt',
        '(Ljava/lang/Object;)J',
        0x0009,
        2,
        ['java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->checkcast($cp->class_('java/lang/Long'));
            $c->invokevirtual($cp->methodRef('java/lang/Long', 'longValue', '()J'), 0, 2);
            $c->lreturn();
        }
    );

    $b->addMethod(
        'ioRun',
        '(Lmoggi/rt/IO;)Ljava/lang/Object;',
        0x0009,
        1,
        ['moggi/rt/IO'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->ifnull('done');
            $c->noteFrame(['moggi/rt/IO']);
            $c->aload(0);
            $c->getfield($cp->fieldRef('moggi/rt/IO', 'action', 'Ljava/lang/Object;'));
            $c->iconst(0);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'apply', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'), 2, true);
            $c->areturn();
            $c->label('done');
            $c->noteFrame(['moggi/rt/IO']);
            $c->aconst_null();
            $c->areturn();
        }
    );

    // SomeException# = Object[]{"__se", tag, payload, display}. `display` is the
    // text rendered where the value still had its Exception dictionary; without it
    // a rethrow or an uncaught report could only guess the text from the payload.
    $b->addMethod(
        'exceptionWrap',
        '(Ljava/lang/String;Ljava/lang/String;Ljava/lang/Object;)[Ljava/lang/Object;',
        0x0009,
        4,
        ['java/lang/String', 'java/lang/String', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->iconst(4);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->dup();
            $c->iconst(0);
            $c->ldc($cp->string_('__se'));
            $c->aastore();
            $c->dup();
            $c->iconst(1);
            $c->aload(0);
            $c->aastore();
            $c->dup();
            $c->iconst(2);
            $c->aload(2);
            $c->aastore();
            $c->dup();
            $c->iconst(3);
            $c->aload(1);
            $c->aastore();
            $c->areturn();
        }
    );

    $b->addMethod(
        'exceptionUnwrap',
        '(Ljava/lang/String;Ljava/lang/Object;)Ljava/lang/Object;',
        0x0009,
        4,
        ['java/lang/String', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(1);
            $c->instanceof_($cp->class_('[Ljava/lang/Object;'));
            $c->ifeq('nothing');
            $c->aload(1);
            $c->checkcast($cp->class_('[Ljava/lang/Object;'));
            $c->astore(2);
            $c->aload(2);
            $c->arraylength();
            $c->iconst(3);
            $c->if_icmplt('nothing');
            $c->aload(2);
            $c->iconst(0);
            $c->aaload();
            $c->ldc($cp->string_('__se'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('nothing');
            $c->aload(2);
            $c->iconst(1);
            $c->aaload();
            $c->aload(0);
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('nothing');

            $c->ldc($cp->string_('Just'));
            $c->iconst(1);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->dup();
            $c->iconst(0);
            $c->aload(2);
            $c->iconst(2);
            $c->aaload();
            $c->aastore();
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'), 2, true);
            $c->areturn();

            $c->label('nothing');
            $c->noteFrame(['java/lang/String', 'java/lang/Object', 'top']);
            $c->ldc($cp->string_('Nothing'));
            $c->iconst(0);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'), 2, true);
            $c->areturn();
        }
    );

    $b->addMethod(
        'exceptionDisplay',
        '(Ljava/lang/Object;)Ljava/lang/String;',
        0x0009,
        4,
        ['java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->instanceof_($cp->class_('[Ljava/lang/Object;'));
            $c->ifeq('fallback');
            $c->aload(0);
            $c->checkcast($cp->class_('[Ljava/lang/Object;'));
            $c->astore(1);
            $c->aload(1);
            $c->arraylength();
            $c->iconst(3);
            $c->if_icmplt('fallback');
            $c->aload(1);
            $c->iconst(0);
            $c->aaload();
            $c->ldc($cp->string_('__se'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('fallback');

            // A stored display wins; it is the text the value rendered for
            // itself, which the payload's shape alone cannot reproduce.
            $c->aload(1);
            $c->arraylength();
            $c->iconst(4);
            $c->if_icmplt('from_payload');
            $c->aload(1);
            $c->iconst(3);
            $c->aaload();
            $c->ifnull('from_payload');
            $c->aload(1);
            $c->iconst(3);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->areturn();

            $c->label('from_payload');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;', 'top', 'top']);
            $c->aload(1);
            $c->iconst(1);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->astore(2);
            $c->aload(1);
            $c->iconst(2);
            $c->aaload();
            $c->astore(3);
            $c->aload(2);
            $c->aload(3);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'exceptionDisplayMessage', '(Ljava/lang/String;Ljava/lang/Object;)Ljava/lang/String;'),
                2,
                true,
            );
            $c->areturn();

            $c->label('fallback');
            $c->noteFrame(['java/lang/Object', 'top', 'top', 'top']);
            $c->ldc($cp->string_('SomeException'));
            $c->areturn();
        }
    );

    $b->addMethod(
        'exceptionDisplayMessage',
        '(Ljava/lang/String;Ljava/lang/Object;)Ljava/lang/String;',
        0x0009,
        4,
        ['java/lang/String', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            // ErrorCall / IOException / HostException payloads are Con
            $c->aload(1);
            $c->instanceof_($cp->class_('moggi/rt/Con'));
            $c->ifeq('not_con');
            $c->aload(1);
            $c->checkcast($cp->class_('moggi/rt/Con'));
            $c->astore(2);

            $c->aload(0);
            $c->ldc($cp->string_('ErrorCall'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('try_ioe');
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $c->ldc($cp->string_('ErrorCall'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('try_ioe');
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iconst(0);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->areturn();

            $c->label('try_ioe');
            $c->noteFrame(['java/lang/String', 'java/lang/Object', 'moggi/rt/Con']);
            $c->aload(0);
            $c->ldc($cp->string_('IOException'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('try_host');
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->iconst(4);
            $c->if_icmplt('try_host');
            // IOError's description field is the display text.
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iconst(3);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->areturn();

            $c->label('try_host');
            $c->noteFrame(['java/lang/String', 'java/lang/Object', 'moggi/rt/Con']);
            $c->aload(0);
            $c->ldc($cp->string_('HostException'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('tag_fallback');
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->iconst(3);
            $c->if_icmplt('host_legacy');
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iconst(2);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->areturn();
            $c->label('host_legacy');
            $c->noteFrame(['java/lang/String', 'java/lang/Object', 'moggi/rt/Con']);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->arraylength();
            $c->iconst(1);
            $c->if_icmplt('tag_fallback');
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $c->iconst(0);
            $c->aaload();
            $c->checkcast($cp->class_('java/lang/String'));
            $c->areturn();

            $c->label('not_con');
            $c->noteFrame(['java/lang/String', 'java/lang/Object']);
            $c->aload(1);
            $c->instanceof_($cp->class_('java/lang/String'));
            $c->ifeq('tag_fallback');
            $c->aload(1);
            $c->checkcast($cp->class_('java/lang/String'));
            $c->areturn();

            $c->label('tag_fallback');
            $c->noteFrame(['java/lang/String', 'java/lang/Object', 'top']);
            $c->aload(0);
            $c->areturn();
        }
    );

    // Declared Object return so callers can store/pop; always throws.
    // throwSite may be null.
    $b->addMethod(
        'throwSomeException',
        '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;',
        0x0009,
        5,
        ['java/lang/Object', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $meInit = $cp->methodRef(
                'moggi/rt/MoggiException',
                '<init>',
                '([Ljava/lang/Object;[Ljava/lang/Object;Ljava/lang/Throwable;)V',
            );

            $c->aload(0);
            $c->instanceof_($cp->class_('moggi/rt/MoggiException'));
            $c->ifeq('not_me');
            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/MoggiException'));
            $c->athrow();

            $c->label('not_me');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;']);
            $c->aload(0);
            $c->instanceof_($cp->class_('[Ljava/lang/Object;'));
            $c->ifeq('wrap_host');
            $c->aload(0);
            $c->checkcast($cp->class_('[Ljava/lang/Object;'));
            $c->astore(3);
            $c->aload(3);
            $c->arraylength();
            $c->iconst(3);
            $c->if_icmplt('wrap_host');
            $c->aload(3);
            $c->iconst(0);
            $c->aaload();
            $c->ldc($cp->string_('__se'));
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $c->ifeq('wrap_host');

            // A payload that came out of a catch handler names the wrapper it was
            // caught as (exceptionAttachWrapper); throw that one to keep its site.
            $c->iconst(4);
            $c->aload(3);
            $c->arraylength();
            $c->if_icmpge('fresh_se');
            $c->aload(3);
            $c->iconst(4);
            $c->aaload();
            $c->instanceof_($cp->class_('moggi/rt/MoggiException'));
            $c->ifeq('fresh_se');
            $c->aload(3);
            $c->iconst(4);
            $c->aaload();
            $c->checkcast($cp->class_('moggi/rt/MoggiException'));
            $c->athrow();

            $c->label('fresh_se');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;', 'top', '[Ljava/lang/Object;']);
            $c->new_($cp->class_('moggi/rt/MoggiException'));
            $c->dup();
            $c->aload(3);
            $c->aload(1); // throwSite
            $c->aconst_null(); // cause
            $c->invokespecial($meInit, 3, false);
            $c->athrow();

            $c->label('wrap_host');
            $c->noteFrame(['java/lang/Object', '[Ljava/lang/Object;', 'top', 'top']);
            $c->aload(0);
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'toString', '()Ljava/lang/String;'), 0, true);
            $c->astore(4);
            $c->aload(0);
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'getClass', '()Ljava/lang/Class;'), 0, true);
            $c->invokevirtual($cp->methodRef('java/lang/Class', 'getName', '()Ljava/lang/String;'), 0, true);
            $c->astore(2);
            $c->ldc($cp->string_('HostException'));
            $c->iconst(3);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->dup();
            $c->iconst(0);
            $c->ldc($cp->string_('jvm'));
            $c->aastore();
            $c->dup();
            $c->iconst(1);
            $c->aload(2);
            $c->aastore();
            $c->dup();
            $c->iconst(2);
            $c->aload(4);
            $c->aastore();
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'), 2, true);
            $c->astore(4);
            $c->ldc($cp->string_('HostException'));
            $c->aconst_null();  // runtime-built payload: display comes from its shape
            $c->aload(4);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'exceptionWrap', '(Ljava/lang/String;Ljava/lang/String;Ljava/lang/Object;)[Ljava/lang/Object;'),
                3,
                true,
            );
            $c->astore(3);
            $c->new_($cp->class_('moggi/rt/MoggiException'));
            $c->dup();
            $c->aload(3);
            $c->aload(1); // throwSite
            $c->aconst_null(); // cause
            $c->invokespecial($meInit, 3, false);
            $c->athrow();
        }
    );

    // Machine-integer exponentiation, reduced modulo `mask` at every step: the
    // `Num` body's square-and-multiply with its dictionaries and per-step boxing
    // removed, and base's own `ErrorCall` for a negative exponent. `mask = -1`
    // is the machine `Int`, whose own multiply already wraps at 64 bits.
    $b->addMethod(
        'intPow',
        '(JJJ[Ljava/lang/Object;)J',
        0x0009,
        9,
        ['long', 'long', 'long', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            // A long occupies two interpreter slots but one StackMapTable entry.
            $locals = ['long', 'long', 'long', '[Ljava/lang/Object;', 'long'];
            // Defined before the exponent is tested: a stack map frame describes
            // the locals every path to its label agrees on.
            $c->lconst(1);
            $c->lstore(7);
            $c->lload(2);
            $c->lconst(0);
            $c->lcmp();
            $c->iflt('int_pow_negative');

            $c->label('int_pow_loop');
            $c->noteFrame($locals);
            $c->lload(2);
            $c->lconst(0);
            $c->lcmp();
            $c->ifeq('int_pow_done');
            $c->lload(2);
            $c->lconst(1);
            $c->land();
            $c->lconst(0);
            $c->lcmp();
            $c->ifeq('int_pow_skip_mul');
            $c->lload(7);
            $c->lload(0);
            $c->lmul();
            $c->lload(4);
            $c->land();
            $c->lstore(7);

            $c->label('int_pow_skip_mul');
            $c->noteFrame($locals);
            $c->lload(0);
            $c->lload(0);
            $c->lmul();
            $c->lload(4);
            $c->land();
            $c->lstore(0);
            $c->lload(2);
            $c->iconst(1);
            $c->opcode(0x7b, -1); // lshr -- the exponent is known non-negative here
            $c->lstore(2);
            $c->goto_('int_pow_loop');

            $c->label('int_pow_done');
            $c->noteFrame($locals);
            $c->lload(7);
            $c->lreturn();

            $c->label('int_pow_negative');
            $c->noteFrame($locals);
            $c->ldc($cp->string_('Negative exponent'));
            $c->aload(6);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'throwErrorCall', '(Ljava/lang/String;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            // Never reached: the call always throws.
            $c->pop_();
            $c->lconst(0);
            $c->lreturn();
        }
    );

    // `Double` exponentiation: the same loop on unboxed doubles, in the library
    // body's own multiplication order, so the value is the one the dictionaries
    // would have produced.
    $b->addMethod(
        'doublePow',
        '(DJ[Ljava/lang/Object;)D',
        0x0009,
        7,
        ['double', 'long', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $locals = ['double', 'long', '[Ljava/lang/Object;', 'double'];
            $c->lconst(1);
            $c->l2d();
            $c->dstore(5);
            $c->lload(2);
            $c->lconst(0);
            $c->lcmp();
            $c->iflt('double_pow_negative');

            $c->label('double_pow_loop');
            $c->noteFrame($locals);
            $c->lload(2);
            $c->lconst(0);
            $c->lcmp();
            $c->ifeq('double_pow_done');
            $c->lload(2);
            $c->lconst(1);
            $c->land();
            $c->lconst(0);
            $c->lcmp();
            $c->ifeq('double_pow_skip_mul');
            $c->dload(5);
            $c->dload(0);
            $c->dmul();
            $c->dstore(5);

            $c->label('double_pow_skip_mul');
            $c->noteFrame($locals);
            $c->dload(0);
            $c->dload(0);
            $c->dmul();
            $c->dstore(0);
            $c->lload(2);
            $c->iconst(1);
            $c->opcode(0x7b, -1); // lshr -- the exponent is known non-negative here
            $c->lstore(2);
            $c->goto_('double_pow_loop');

            $c->label('double_pow_done');
            $c->noteFrame($locals);
            $c->dload(5);
            $c->dreturn();

            $c->label('double_pow_negative');
            $c->noteFrame($locals);
            $c->ldc($cp->string_('Negative exponent'));
            $c->aload(4);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'throwErrorCall', '(Ljava/lang/String;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            // Never reached: the call always throws.
            $c->pop_();
            $c->lconst(0);
            $c->l2d();
            $c->dreturn();
        }
    );

    $b->addMethod(
        'throwErrorCall',
        '(Ljava/lang/String;[Ljava/lang/Object;)Ljava/lang/Object;',
        0x0009,
        4,
        ['java/lang/String', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->ldc($cp->string_('ErrorCall'));
            $c->iconst(1);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->dup();
            $c->iconst(0);
            $c->aload(0);
            $c->aastore();
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'), 2, true);
            $c->astore(2);
            $c->ldc($cp->string_('ErrorCall'));
            $c->aconst_null();  // runtime-built payload: display comes from its shape
            $c->aload(2);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'exceptionWrap', '(Ljava/lang/String;Ljava/lang/String;Ljava/lang/Object;)[Ljava/lang/Object;'),
                3,
                true,
            );
            $c->astore(2);
            $c->new_($cp->class_('moggi/rt/MoggiException'));
            $c->dup();
            $c->aload(2);
            $c->aload(1); // throwSite
            $c->aconst_null(); // cause
            $c->invokespecial(
                $cp->methodRef(
                    'moggi/rt/MoggiException',
                    '<init>',
                    '([Ljava/lang/Object;[Ljava/lang/Object;Ljava/lang/Throwable;)V',
                ),
                3,
                false,
            );
            $c->athrow();
        }
    );

    // The only constructor of the Natural# type: the bignum representation is
    // shared with Integer#, so a negative input is rejected here.
    $b->addMethod(
        'naturalFromInteger',
        '(Ljava/math/BigInteger;[Ljava/lang/Object;)Ljava/math/BigInteger;',
        0x0009,
        3,
        ['java/math/BigInteger', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokevirtual($cp->methodRef('java/math/BigInteger', 'signum', '()I'), 1, false);
            $c->iflt('natural_negative');
            $c->aload(0);
            $c->areturn();
            $c->label('natural_negative');
            $c->noteFrame(['java/math/BigInteger', '[Ljava/lang/Object;']);
            $c->ldc($cp->string_('arithmetic underflow'));
            $c->aload(1);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'throwErrorCall', '(Ljava/lang/String;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            $c->checkcast($cp->class_('java/math/BigInteger'));
            $c->areturn();
        }
    );

    $b->addMethod(
        'normalizeHostException',
        '(Ljava/lang/Throwable;)[Ljava/lang/Object;',
        0x0009,
        6,
        ['java/lang/Throwable'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->instanceof_($cp->class_('moggi/rt/MoggiException'));
            $c->ifeq('not_me');
            $c->aload(0);
            $c->checkcast($cp->class_('moggi/rt/MoggiException'));
            $c->getfield($cp->fieldRef('moggi/rt/MoggiException', 'someException', '[Ljava/lang/Object;'));
            $c->areturn();

            $c->label('not_me');
            $c->noteFrame(['java/lang/Throwable']);
            $c->aload(0);
            $c->invokevirtual($cp->methodRef('java/lang/Throwable', 'getMessage', '()Ljava/lang/String;'), 0, true);
            $c->astore(1);
            $c->aload(1);
            $c->ifnonnull('have_msg');
            $c->ldc($cp->string_(''));
            $c->astore(1);

            // Every host Throwable → HostException (backend, nativeType, message).
            // Classification into IOException belongs to System.IO, which knows
            // the path an operation used.
            $c->label('have_msg');
            $c->noteFrame(['java/lang/Throwable', 'java/lang/String']);
            $c->aload(0);
            $c->invokevirtual($cp->methodRef('java/lang/Object', 'getClass', '()Ljava/lang/Class;'), 0, true);
            $c->invokevirtual($cp->methodRef('java/lang/Class', 'getName', '()Ljava/lang/String;'), 0, true);
            $c->astore(2);
            $c->ldc($cp->string_('HostException'));
            $c->iconst(3);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->dup();
            $c->iconst(0);
            $c->ldc($cp->string_('jvm'));
            $c->aastore();
            $c->dup();
            $c->iconst(1);
            $c->aload(2);
            $c->aastore();
            $c->dup();
            $c->iconst(2);
            $c->aload(1);
            $c->aastore();
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'), 2, true);
            $c->astore(3);
            $c->ldc($cp->string_('HostException'));
            $c->aconst_null();  // runtime-built payload: display comes from its shape
            $c->aload(3);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'exceptionWrap', '(Ljava/lang/String;Ljava/lang/String;Ljava/lang/Object;)[Ljava/lang/Object;'),
                3,
                true,
            );
            $c->areturn();
        }
    );

    $b->addMethod(
        'ioCatch',
        '(Lmoggi/rt/IO;Ljava/lang/Object;)Ljava/lang/Object;',
        0x0009,
        5,
        ['moggi/rt/IO', 'java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $throwable = $cp->class_('java/lang/Throwable');
            $meInit = $cp->methodRef(
                'moggi/rt/MoggiException',
                '<init>',
                '([Ljava/lang/Object;[Ljava/lang/Object;Ljava/lang/Throwable;)V',
            );
            $c->label('try_start');
            $c->aload(0);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'ioRun', '(Lmoggi/rt/IO;)Ljava/lang/Object;'), 1, true);
            $c->areturn();
            $c->label('try_end');

            $c->label('handler');
            $c->exception('try_start', 'try_end', 'handler', $throwable);
            $c->assumeStackDelta(1);
            $c->noteFrame(
                ['moggi/rt/IO', 'java/lang/Object'],
                ['java/lang/Throwable'],
            );
            $c->astore(2);
            $c->aload(2);
            $c->instanceof_($cp->class_('moggi/rt/MoggiException'));
            $c->ifeq('wrap');
            $c->aload(2);
            $c->checkcast($cp->class_('moggi/rt/MoggiException'));
            $c->astore(3);
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/MoggiException', 'someException', '[Ljava/lang/Object;'));
            $c->aload(3);
            $c->invokestatic(
                $cp->methodRef(
                    'moggi/rt/RT',
                    'exceptionAttachWrapper',
                    '([Ljava/lang/Object;Lmoggi/rt/MoggiException;)[Ljava/lang/Object;',
                ),
                2,
                true,
            );
            $c->astore(4);
            $c->goto_('dispatch');

            $c->label('wrap');
            $c->noteFrame(
                ['moggi/rt/IO', 'java/lang/Object', 'java/lang/Throwable'],
            );
            $c->new_($cp->class_('moggi/rt/MoggiException'));
            $c->dup();
            $c->aload(2);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'normalizeHostException', '(Ljava/lang/Throwable;)[Ljava/lang/Object;'),
                1,
                true,
            );
            $c->aconst_null(); // throwSite
            $c->aload(2); // cause
            $c->invokespecial($meInit, 3, false);
            $c->astore(3);
            $c->aload(3);
            $c->getfield($cp->fieldRef('moggi/rt/MoggiException', 'someException', '[Ljava/lang/Object;'));
            $c->aload(3);
            $c->invokestatic(
                $cp->methodRef(
                    'moggi/rt/RT',
                    'exceptionAttachWrapper',
                    '([Ljava/lang/Object;Lmoggi/rt/MoggiException;)[Ljava/lang/Object;',
                ),
                2,
                true,
            );
            $c->astore(4);

            $c->label('dispatch');
            $c->noteFrame(
                ['moggi/rt/IO', 'java/lang/Object', 'java/lang/Throwable', 'moggi/rt/MoggiException', '[Ljava/lang/Object;'],
            );
            $c->aload(1);
            $c->iconst(1);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->dup();
            $c->iconst(0);
            $c->aload(4);
            $c->aastore();
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'apply', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            $c->checkcast($cp->class_('moggi/rt/IO'));
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'ioRun', '(Lmoggi/rt/IO;)Ljava/lang/Object;'), 1, true);
            $c->areturn();
        }
    );

    $b->addMethod(
        'ioFinally',
        '(Lmoggi/rt/IO;Lmoggi/rt/IO;)Ljava/lang/Object;',
        0x0009,
        5,
        ['moggi/rt/IO', 'moggi/rt/IO'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $throwable = $cp->class_('java/lang/Throwable');
            // locals: 0=action, 1=cleanup, 2=savedEx, 3=result
            $c->aconst_null();
            $c->astore(2);
            $c->aconst_null();
            $c->astore(3);

            $c->label('try1_start');
            $c->aload(0);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'ioRun', '(Lmoggi/rt/IO;)Ljava/lang/Object;'), 1, true);
            $c->astore(3);
            $c->goto_('after_try1');
            $c->label('try1_end');

            $c->label('catch1');
            $c->exception('try1_start', 'try1_end', 'catch1', $throwable);
            $c->assumeStackDelta(1);
            $c->noteFrame(
                ['moggi/rt/IO', 'moggi/rt/IO', 'java/lang/Throwable', 'java/lang/Object'],
                ['java/lang/Throwable'],
            );
            $c->astore(2);

            $c->label('after_try1');
            $c->noteFrame(['moggi/rt/IO', 'moggi/rt/IO', 'java/lang/Throwable', 'java/lang/Object']);

            $c->label('try2_start');
            $c->aload(1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'ioRun', '(Lmoggi/rt/IO;)Ljava/lang/Object;'), 1, true);
            $c->pop_();
            $c->goto_('after_try2');
            $c->label('try2_end');

            $c->label('catch2');
            $c->exception('try2_start', 'try2_end', 'catch2', $throwable);
            $c->assumeStackDelta(1);
            $c->noteFrame(
                ['moggi/rt/IO', 'moggi/rt/IO', 'java/lang/Throwable', 'java/lang/Object'],
                ['java/lang/Throwable'],
            );
            // Cleanup failure wins: normalize so catch handlers never see raw host throwables.
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'normalizeHostException', '(Ljava/lang/Throwable;)[Ljava/lang/Object;'),
                1,
                true,
            );
            $c->aconst_null();
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'throwSomeException', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            $c->areturn();

            $c->label('after_try2');
            $c->noteFrame(['moggi/rt/IO', 'moggi/rt/IO', 'java/lang/Throwable', 'java/lang/Object']);
            $c->aload(2);
            $c->ifnull('ok');
            $c->aload(2);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'normalizeHostException', '(Ljava/lang/Throwable;)[Ljava/lang/Object;'),
                1,
                true,
            );
            $c->aconst_null();
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'throwSomeException', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            $c->areturn();

            $c->label('ok');
            $c->noteFrame(['moggi/rt/IO', 'moggi/rt/IO', 'java/lang/Throwable', 'java/lang/Object']);
            $c->aload(3);
            $c->areturn();
        }
    );

    // Already-evaluated IO value (eager boxing MVP for straight-line main).
    $b->addMethod(
        'ioPure',
        '(Ljava/lang/Object;)Lmoggi/rt/IO;',
        0x0009,
        3,
        ['java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            // IO with a Fn that returns the captured value — use Con as cheap holder?
            // Simpler: store value in Partial-like — for MVP return IO whose Fn ignores args and returns value.
            // Without nested classes, use RT.constFn helper:
            $c->aload(0);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'constFn', '(Ljava/lang/Object;)Lmoggi/rt/Fn;'),
                1,
                true,
            );
            $c->astore(1);
            $c->new_($cp->class_('moggi/rt/IO'));
            $c->dup();
            $c->aload(1);
            $c->invokespecial($cp->methodRef('moggi/rt/IO', '<init>', '(Ljava/lang/Object;)V'), 1, false);
            $c->areturn();
        }
    );

    // Fn that ignores args and returns a constant (for ioPure).
    $b->addMethod(
        'constFn',
        '(Ljava/lang/Object;)Lmoggi/rt/Fn;',
        0x0009,
        2,
        ['java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            // `Fn` is an interface, so a constant function needs a concrete class.
            $c->new_($cp->class_('moggi/rt/ConstFn'));
            $c->dup();
            $c->aload(0);
            $c->invokespecial($cp->methodRef('moggi/rt/ConstFn', '<init>', '(Ljava/lang/Object;)V'), 1, false);
            $c->areturn();
        }
    );

    // Strict fixpoint (`Data.Function.fix`): apply `fn` to a memoising
    // self-reference standing for `fix fn` applied to further arguments.
    $b->addMethod(
        'fix',
        '(Ljava/lang/Object;)Ljava/lang/Object;',
        0x0009,
        4,
        ['java/lang/Object'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->new_($cp->class_('moggi/rt/Rec'));
            $c->dup();
            $c->aload(0);
            $c->invokespecial($cp->methodRef('moggi/rt/Rec', '<init>', '(Ljava/lang/Object;)V'), 1, false);
            $c->astore(1);

            $c->iconst(1);
            $c->anewarray($cp->class_('java/lang/Object'));
            $c->dup();
            $c->iconst(0);
            $c->aload(1);
            $c->aastore();
            $c->astore(2);

            $c->aload(0);
            $c->aload(2);
            $c->invokestatic(
                $cp->methodRef('moggi/rt/RT', 'apply', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            $c->areturn();
        }
    );

    $b->addMethod(
        'con',
        '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;',
        0x0009,
        2,
        ['java/lang/String', '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->new_($cp->class_('moggi/rt/Con'));
            $c->dup();
            $c->aload(0);
            $c->aload(1);
            $c->invokespecial($cp->methodRef('moggi/rt/Con', '<init>', '(Ljava/lang/String;[Ljava/lang/Object;)V'), 2, false);
            $c->areturn();
        }
    );

    // Convert JDK Stream → MList (used when Moggi `[a]` is inferred as Stream for java.* foreigns).
    $b->addMethod(
        'streamToList',
        '(Ljava/util/stream/Stream;)Lmoggi/rt/MList;',
        0x0009,
        4,
        ['java/util/stream/Stream'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokeinterface($cp->ifaceMethodRef('java/util/stream/Stream', 'iterator', '()Ljava/util/Iterator;'), 0, true);
            $c->astore(1);
            $c->aconst_null();
            $c->astore(2); // acc = Nil

            $c->label('loop');
            $c->noteFrame(['java/util/stream/Stream', 'java/util/Iterator', 'moggi/rt/MList']);
            $c->aload(1);
            $c->invokeinterface($cp->ifaceMethodRef('java/util/Iterator', 'hasNext', '()Z'), 0, true);
            $c->ifeq('done');
            $c->noteFrame(['java/util/stream/Stream', 'java/util/Iterator', 'moggi/rt/MList']);
            $c->aload(1);
            $c->invokeinterface($cp->ifaceMethodRef('java/util/Iterator', 'next', '()Ljava/lang/Object;'), 0, true);
            $c->aload(2);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'cons', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/MList;'), 2, true);
            $c->astore(2);
            $c->goto_('loop');

            $c->label('done');
            $c->noteFrame(['java/util/stream/Stream', 'java/util/Iterator', 'moggi/rt/MList']);
            $c->aconst_null();
            $c->astore(3);
            $c->label('rev');
            $c->noteFrame(['java/util/stream/Stream', 'java/util/Iterator', 'moggi/rt/MList', 'moggi/rt/MList']);
            $c->aload(2);
            $c->ifnull('revDone');
            $c->noteFrame(['java/util/stream/Stream', 'java/util/Iterator', 'moggi/rt/MList', 'moggi/rt/MList']);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
            $c->aload(3);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'cons', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/MList;'), 2, true);
            $c->astore(3);
            $c->aload(2);
            $c->getfield($cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
            $c->astore(2);
            $c->goto_('rev');

            $c->label('revDone');
            $c->noteFrame(['java/util/stream/Stream', 'java/util/Iterator', 'moggi/rt/MList', 'moggi/rt/MList']);
            $c->aload(3);
            $c->areturn();
        }
    );

    return $b->toBytes();
}

/**
 * Minimal platform helpers for platform_* intrinsics when a JDK API is not a
 * clean 1:1 foreign. Prefer `foreign import jvm` of java.* paths; keep this tiny.
 */
function buildPlatformClass(): string
{
    $b = new ClassBuilder('moggi/rt/Platform');
    $b->addField('argv', '[Ljava/lang/String;', 0x000a); // private static

    // Standard input as one reader, kept across references. A fresh BufferedReader per
    // reference buffers the whole pipe into the reader it then discards, so the next one
    // sees EOF: `x <- getLine` twice read one line and then failed.
    $b->addField('stdin', 'Ljava/io/BufferedReader;', 0x000a); // private static

    // Returns Object: that is the host type the foreign-import boundary uses for a Handle.
    $b->addMethod(
        'stdin',
        '()Ljava/lang/Object;',
        0x0009,
        0,
        [],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->getstatic($cp->fieldRef('moggi/rt/Platform', 'stdin', 'Ljava/io/BufferedReader;'));
            $c->dup();
            $c->ifnonnull('have');
            $c->pop_();
            $c->new_($cp->class_('java/io/BufferedReader'));
            $c->dup();
            $c->new_($cp->class_('java/io/InputStreamReader'));
            $c->dup();
            $c->getstatic($cp->fieldRef('java/lang/System', 'in', 'Ljava/io/InputStream;'));
            $c->getstatic($cp->fieldRef('java/nio/charset/StandardCharsets', 'UTF_8', 'Ljava/nio/charset/Charset;'));
            $c->invokespecial(
                $cp->methodRef('java/io/InputStreamReader', '<init>', '(Ljava/io/InputStream;Ljava/nio/charset/Charset;)V'),
                2,
                false,
            );
            $c->invokespecial($cp->methodRef('java/io/BufferedReader', '<init>', '(Ljava/io/Reader;)V'), 1, false);
            $c->dup();
            $c->putstatic($cp->fieldRef('moggi/rt/Platform', 'stdin', 'Ljava/io/BufferedReader;'));
            $c->areturn();

            $c->label('have');
            $c->noteFrame([], ['java/io/BufferedReader']);
            $c->areturn();
        }
    );

    $b->addMethod(
        'setArgs',
        '([Ljava/lang/String;)V',
        0x0009,
        1,
        ['[Ljava/lang/String;'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->putstatic($cp->fieldRef('moggi/rt/Platform', 'argv', '[Ljava/lang/String;'));
            $c->return_();
        }
    );

    // Moggi text is UTF-8. `System.out` follows the platform's default charset, which on a POSIX
    // locale is ASCII — `putStrLn "café"` would print `caf?`. Replace both streams before any
    // module runs.
    $b->addMethod(
        'useUtf8Console',
        '()V',
        0x0009,
        0,
        [],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            foreach (
                [
                    ['out', 'java/lang/System', 'setOut', 'java/io/FileDescriptor', 'out'],
                    ['err', 'java/lang/System', 'setErr', 'java/io/FileDescriptor', 'err'],
                ] as [$field, $system, $setter, $fdOwner, $fdField]
            ) {
                $c->new_($cp->class_('java/io/PrintStream'));
                $c->dup();
                $c->new_($cp->class_('java/io/FileOutputStream'));
                $c->dup();
                $c->getstatic($cp->fieldRef($fdOwner, $fdField, 'Ljava/io/FileDescriptor;'));
                $c->invokespecial($cp->methodRef('java/io/FileOutputStream', '<init>', '(Ljava/io/FileDescriptor;)V'), 1, false);
                $c->iconst(1);
                $c->getstatic($cp->fieldRef('java/nio/charset/StandardCharsets', 'UTF_8', 'Ljava/nio/charset/Charset;'));
                $c->invokespecial(
                    $cp->methodRef('java/io/PrintStream', '<init>', '(Ljava/io/OutputStream;ZLjava/nio/charset/Charset;)V'),
                    3,
                    false,
                );
                $c->invokestatic($cp->methodRef($system, $setter, '(Ljava/io/PrintStream;)V'), 1, false);
            }
            $c->return_();
        }
    );

    $b->addMethod(
        'argv',
        '()Lmoggi/rt/MList;',
        0x0009,
        4,
        [],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->getstatic($cp->fieldRef('moggi/rt/Platform', 'argv', '[Ljava/lang/String;'));
            $c->astore(0);
            $c->aload(0);
            $c->ifnonnull('haveArgs');
            $c->aconst_null();
            $c->areturn();

            $c->label('haveArgs');
            $c->noteFrame(['[Ljava/lang/String;']);
            $c->aconst_null();
            $c->astore(1);
            $c->aload(0);
            $c->arraylength();
            $c->istore(2);

            $c->label('loop');
            $c->noteFrame(['[Ljava/lang/String;', 'moggi/rt/MList', 'int']);
            $c->iload(2);
            $c->ifeq('done');
            $c->iinc(2, -1);
            $c->aload(0);
            $c->iload(2);
            $c->aaload();
            $c->aload(1);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'cons', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/MList;'), 2, true);
            $c->astore(1);
            $c->goto_('loop');

            $c->label('done');
            $c->noteFrame(['[Ljava/lang/String;', 'moggi/rt/MList', 'int']);
            $c->aload(1);
            $c->areturn();
        }
    );


    return $b->toBytes();
}


/** Unsigned 64-bit helpers; bit patterns stored as signed Java long. */
function buildWord64Class(): string
{
    $b = new ClassBuilder('moggi/rt/Word64');

    $b->addMethod(
        'eq',
        '(JJ)Z',
        0x0009,
        4,
        ['long', 'long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->lload(2);
            $c->lcmp();
            $c->ifeq('yes');
            $c->iconst(0);
            $c->ireturn();
            $c->label('yes');
            $c->noteFrame(['long', 'long']);
            $c->iconst(1);
            $c->ireturn();
        }
    );
    $b->addMethod(
        'ne',
        '(JJ)Z',
        0x0009,
        4,
        ['long', 'long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->lload(2);
            $c->lcmp();
            $c->ifne('yes');
            $c->iconst(0);
            $c->ireturn();
            $c->label('yes');
            $c->noteFrame(['long', 'long']);
            $c->iconst(1);
            $c->ireturn();
        }
    );
    $b->addMethod(
        'compare',
        '(JJ)I',
        0x0009,
        4,
        ['long', 'long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->lload(2);
            $c->invokestatic($cp->methodRef('java/lang/Long', 'compareUnsigned', '(JJ)I'), 4, true);
            $c->ireturn();
        }
    );

    foreach (['add' => 'ladd', 'subtract' => 'lsub', 'multiply' => 'lmul'] as $name => $op) {
        $b->addMethod(
            $name,
            '(JJ)J',
            0x0009,
            4,
            ['long', 'long'],
            static function (CodeBuilder $c, ConstantPool $cp) use ($op): void {
                $c->lload(0);
                $c->lload(2);
                $c->{$op}();
                $c->lreturn();
            }
        );
    }

    $b->addMethod(
        'divide',
        '(JJ)J',
        0x0009,
        4,
        ['long', 'long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->lload(2);
            $c->invokestatic($cp->methodRef('java/lang/Long', 'divideUnsigned', '(JJ)J'), 4, 2);
            $c->lreturn();
        }
    );
    $b->addMethod(
        'remainder',
        '(JJ)J',
        0x0009,
        4,
        ['long', 'long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->lload(2);
            $c->invokestatic($cp->methodRef('java/lang/Long', 'remainderUnsigned', '(JJ)J'), 4, 2);
            $c->lreturn();
        }
    );
    $b->addMethod(
        'and',
        '(JJ)J',
        0x0009,
        4,
        ['long', 'long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->lload(2);
            $c->land();
            $c->lreturn();
        }
    );
    $b->addMethod(
        'or',
        '(JJ)J',
        0x0009,
        4,
        ['long', 'long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->lload(2);
            $c->opcode(0x81, -2);
            $c->lreturn();
        }
    );
    $b->addMethod(
        'xor',
        '(JJ)J',
        0x0009,
        4,
        ['long', 'long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->lload(2);
            $c->opcode(0x83, -2);
            $c->lreturn();
        }
    );
    $b->addMethod(
        'shiftLeft',
        '(JI)J',
        0x0009,
        3,
        ['long', 'int'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->iload(2);
            $c->bipush(63);
            $c->opcode(0x7e, -1);
            $c->opcode(0x79, -2);
            $c->lreturn();
        }
    );
    $b->addMethod(
        'shiftRight',
        '(JI)J',
        0x0009,
        3,
        ['long', 'int'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->iload(2);
            $c->bipush(63);
            $c->opcode(0x7e, -1);
            $c->opcode(0x7b, -2);
            $c->lreturn();
        }
    );
    $b->addMethod(
        'bitCount',
        '(J)I',
        0x0009,
        2,
        ['long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->invokestatic($cp->methodRef('java/lang/Long', 'bitCount', '(J)I'), 2, true);
            $c->ireturn();
        }
    );
    $b->addMethod(
        'numberOfLeadingZeros',
        '(J)I',
        0x0009,
        2,
        ['long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->invokestatic($cp->methodRef('java/lang/Long', 'numberOfLeadingZeros', '(J)I'), 2, true);
            $c->ireturn();
        }
    );
    $b->addMethod(
        'numberOfTrailingZeros',
        '(J)I',
        0x0009,
        2,
        ['long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->invokestatic($cp->methodRef('java/lang/Long', 'numberOfTrailingZeros', '(J)I'), 2, true);
            $c->ireturn();
        }
    );
    $b->addMethod(
        'toString',
        '(J)Ljava/lang/String;',
        0x0009,
        2,
        ['long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->invokestatic($cp->methodRef('java/lang/Long', 'toUnsignedString', '(J)Ljava/lang/String;'), 2, true);
            $c->areturn();
        }
    );
    $b->addMethod(
        'toBigInteger',
        '(J)Ljava/math/BigInteger;',
        0x0009,
        3,
        ['long'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->lload(0);
            $c->invokestatic($cp->methodRef('java/lang/Long', 'toUnsignedString', '(J)Ljava/lang/String;'), 2, true);
            $c->astore(2);
            $c->new_($cp->class_('java/math/BigInteger'));
            $c->dup();
            $c->aload(2);
            $c->invokespecial($cp->methodRef('java/math/BigInteger', '<init>', '(Ljava/lang/String;)V'), 1, false);
            $c->areturn();
        }
    );
    $b->addMethod('fromBigInteger', '(Ljava/math/BigInteger;)J', 0x0009, 1, ['java/math/BigInteger'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokevirtual($cp->methodRef('java/math/BigInteger', 'longValue', '()J'), 0, 2);
            $c->lreturn();
        });

    return $b->toBytes();
}
