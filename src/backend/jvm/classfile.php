<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Classfile;

/**
 * Conservative JVM class-file writer targeting Java 21 (major 65).
 * Emits ordinary classes, static methods, interfaces, and invoke* only —
 * no invokedynamic, records, sealed classes, hidden classes, or preview attrs.
 *
 * Architecture does not depend on a particular third-party bytecode library;
 * this is a self-contained writer inside the Moggi compiler.
 */

final class ConstantPool
{
    /** @var list<array<string, mixed>|null> */
    private array $entries = [null]; // 1-based; index 0 unused

    /** @var array<string, int> */
    private array $utf8 = [];

    /** @var array<string, int> */
    private array $classes = [];

    /** @var array<string, int> */
    private array $strings = [];

    /** @var array<string, int> */
    private array $nameAndTypes = [];

    /** @var array<string, int> */
    private array $refs = [];

    /** @var array<int|string, int> */
    private array $ints = [];

    /** @var array<string, int> */
    private array $longs = [];

    public function utf8(string $s): int
    {
        if (isset($this->utf8[$s])) {
            return $this->utf8[$s];
        }
        $i = count($this->entries);
        $this->entries[] = ['tag' => 1, 'bytes' => $s];
        $this->utf8[$s] = $i;

        return $i;
    }

    public function class_(string $internalName): int
    {
        if (isset($this->classes[$internalName])) {
            return $this->classes[$internalName];
        }
        $name = $this->utf8($internalName);
        $i = count($this->entries);
        $this->entries[] = ['tag' => 7, 'name' => $name];
        $this->classes[$internalName] = $i;

        return $i;
    }

    public function string_(string $s): int
    {
        if (isset($this->strings[$s])) {
            return $this->strings[$s];
        }
        $utf = $this->utf8($s);
        $i = count($this->entries);
        $this->entries[] = ['tag' => 8, 'string' => $utf];
        $this->strings[$s] = $i;

        return $i;
    }

    public function integer(int $v): int
    {
        // Signed 32-bit CONSTANT_Integer (still used for rare int immediates).
        $key = $v & 0xFFFFFFFF;
        if (isset($this->ints[$key])) {
            return $this->ints[$key];
        }
        $i = count($this->entries);
        $this->entries[] = ['tag' => 3, 'bytes' => $v];
        $this->ints[$key] = $i;

        return $i;
    }

    /** CONSTANT_Long (tag 5) — occupies two constant-pool slots. */
    public function long(int $v): int
    {
        $key = (string) $v;
        if (isset($this->longs[$key])) {
            return $this->longs[$key];
        }
        $i = count($this->entries);
        $this->entries[] = ['tag' => 5, 'bytes' => $v];
        $this->entries[] = ['tag' => 0, 'pad' => true]; // second slot
        $this->longs[$key] = $i;

        return $i;
    }

    public function nameAndType(string $name, string $descriptor): int
    {
        $key = $name . "\0" . $descriptor;
        if (isset($this->nameAndTypes[$key])) {
            return $this->nameAndTypes[$key];
        }
        $n = $this->utf8($name);
        $d = $this->utf8($descriptor);
        $i = count($this->entries);
        $this->entries[] = ['tag' => 12, 'name' => $n, 'descriptor' => $d];
        $this->nameAndTypes[$key] = $i;

        return $i;
    }

    /** @param 9|10|11 $tag Fieldref / Methodref / InterfaceMethodref */
    public function ref(int $tag, string $classInternal, string $name, string $descriptor): int
    {
        $key = $tag . "\0" . $classInternal . "\0" . $name . "\0" . $descriptor;
        if (isset($this->refs[$key])) {
            return $this->refs[$key];
        }
        $c = $this->class_($classInternal);
        $nt = $this->nameAndType($name, $descriptor);
        $i = count($this->entries);
        $this->entries[] = ['tag' => $tag, 'class' => $c, 'nameAndType' => $nt];
        $this->refs[$key] = $i;

        return $i;
    }

    public function methodRef(string $classInternal, string $name, string $descriptor): int
    {
        return $this->ref(10, $classInternal, $name, $descriptor);
    }

    public function ifaceMethodRef(string $classInternal, string $name, string $descriptor): int
    {
        return $this->ref(11, $classInternal, $name, $descriptor);
    }

    public function fieldRef(string $classInternal, string $name, string $descriptor): int
    {
        return $this->ref(9, $classInternal, $name, $descriptor);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function write(): string
    {
        $out = u2($this->count());
        for ($i = 1; $i < count($this->entries); ++$i) {
            $e = $this->entries[$i];
            if (($e['tag'] ?? null) === 0) {
                continue; // second slot of Long/Double
            }
            $out .= match ($e['tag']) {
                1 => chr(1) . utf8Info($e['bytes']),
                3 => chr(3) . u4($e['bytes']),
                5 => chr(5) . u8($e['bytes']),
                7 => chr(7) . u2($e['name']),
                8 => chr(8) . u2($e['string']),
                9, 10, 11 => chr($e['tag']) . u2($e['class']) . u2($e['nameAndType']),
                12 => chr(12) . u2($e['name']) . u2($e['descriptor']),
                default => throw new \RuntimeException('unsupported cp tag ' . $e['tag']),
            };
        }

        return $out;
    }
}

final class CodeBuilder
{
    private string $code = '';

    /** @var list<array{start: int, end: int, handler: int, type: int}> */
    private array $exceptions = [];

    /** @var list<array{start: string, end: string, handler: string, type: int}> */
    private array $pendingExceptions = [];

    /** @var array<string, int> */
    private array $labels = [];

    /** @var list<array{at: int, label: string, wide: bool}> */
    private array $fixups = [];

    /** @var list<array{offset: int, locals: list<string|int>, stack: list<string|int>}> */
    private array $frames = [];

    /** @var list<array{pc: int, line: int}> */
    private array $lineNumbers = [];

    private int $maxStack = 0;
    private int $curStack = 0;
    private int $maxLocals;

    public function __construct(int $maxLocals)
    {
        $this->maxLocals = $maxLocals;
    }

    public function offset(): int
    {
        return strlen($this->code);
    }

    public function label(string $name): void
    {
        $this->labels[$name] = $this->offset();
    }

    public function noteFrame(array $locals, array $stack = []): void
    {
        $this->frames[] = [
            'offset' => $this->offset(),
            'locals' => $locals,
            'stack' => $stack,
        ];
    }

    /** Record a LineNumberTable entry at the current PC (Moggi source line). */
    public function noteLine(int $line): void
    {
        if ($line <= 0) {
            return;
        }
        $pc = $this->offset();
        foreach ($this->lineNumbers as $i => $entry) {
            if ($entry['pc'] === $pc) {
                $this->lineNumbers[$i] = ['pc' => $pc, 'line' => $line];

                return;
            }
        }
        $this->lineNumbers[] = ['pc' => $pc, 'line' => $line];
    }

    private function push(int $n = 1): void
    {
        $this->curStack += $n;
        if ($this->curStack > $this->maxStack) {
            $this->maxStack = $this->curStack;
        }
    }

    private function pop(int $n = 1): void
    {
        $this->curStack -= $n;
        if ($this->curStack < 0) {
            $this->curStack = 0;
        }
    }

    public function raw(string $bytes, int $stackDelta = 0): void
    {
        $this->code .= $bytes;
        if ($stackDelta > 0) {
            $this->push($stackDelta);
        } elseif ($stackDelta < 0) {
            $this->pop(-$stackDelta);
        }
    }

    public function opcode(int $op, int $stackDelta = 0): void
    {
        $this->raw(chr($op), $stackDelta);
    }

    public function bipush(int $v): void
    {
        $this->opcode(0x10, 1);
        $this->code .= chr($v & 0xff);
    }

    public function sipush(int $v): void
    {
        $this->opcode(0x11, 1);
        $this->code .= u2($v);
    }

    public function ldc(int $index): void
    {
        if ($index <= 255) {
            $this->opcode(0x12, 1);
            $this->code .= chr($index);
        } else {
            $this->opcode(0x13, 1);
            $this->code .= u2($index);
        }
    }

    public function aconst_null(): void
    {
        $this->opcode(0x01, 1);
    }

    public function iconst(int $v): void
    {
        if ($v === -1) {
            $this->opcode(0x02, 1);
        } elseif ($v >= 0 && $v <= 5) {
            $this->opcode(0x03 + $v, 1);
        } elseif ($v >= -128 && $v <= 127) {
            $this->bipush($v);
        } elseif ($v >= -32768 && $v <= 32767) {
            $this->sipush($v);
        } else {
            throw new \RuntimeException('iconst out of range; use ldc Integer');
        }
    }

    public function iload(int $i): void
    {
        if ($i <= 3) {
            $this->opcode(0x1a + $i, 1);
        } elseif ($i <= 255) {
            $this->opcode(0x15, 1);
            $this->code .= chr($i);
        } else {
            $this->emitWideLoadStore(0x15, $i, 1);
        }
        $this->maxLocals = max($this->maxLocals, $i + 1);
    }

    public function dload(int $i): void
    {
        if ($i <= 3) {
            $this->opcode(0x26 + $i, 2);
        } elseif ($i <= 255) {
            $this->opcode(0x18, 2);
            $this->code .= chr($i);
        } else {
            $this->emitWideLoadStore(0x18, $i, 2);
        }
        $this->maxLocals = max($this->maxLocals, $i + 2);
    }

    public function dstore(int $i): void
    {
        if ($i <= 3) {
            $this->opcode(0x47 + $i, -2);
        } elseif ($i <= 255) {
            $this->opcode(0x39, -2);
            $this->code .= chr($i);
        } else {
            $this->emitWideLoadStore(0x39, $i, -2);
        }
        $this->maxLocals = max($this->maxLocals, $i + 2);
    }

    public function dreturn(): void
    {
        $this->opcode(0xaf, -2);
    }

    public function aload(int $i): void
    {
        if ($i <= 3) {
            $this->opcode(0x2a + $i, 1);
        } elseif ($i <= 255) {
            $this->opcode(0x19, 1);
            $this->code .= chr($i);
        } else {
            $this->emitWideLoadStore(0x19, $i, 1);
        }
        $this->maxLocals = max($this->maxLocals, $i + 1);
    }

    public function istore(int $i): void
    {
        if ($i <= 3) {
            $this->opcode(0x3b + $i, -1);
        } elseif ($i <= 255) {
            $this->opcode(0x36, -1);
            $this->code .= chr($i);
        } else {
            $this->emitWideLoadStore(0x36, $i, -1);
        }
        $this->maxLocals = max($this->maxLocals, $i + 1);
    }

    public function iinc(int $i, int $delta): void
    {
        if ($i <= 255 && $delta >= -128 && $delta <= 127) {
            $this->opcode(0x84, 0);
            $this->code .= chr($i) . chr($delta & 0xff);
        } else {
            $this->opcode(0xc4, 0); // wide
            $this->code .= chr(0x84) . pack('n', $i) . pack('n', $delta & 0xffff);
        }
        $this->maxLocals = max($this->maxLocals, $i + 1);
    }

    public function astore(int $i): void
    {
        if ($i <= 3) {
            $this->opcode(0x4b + $i, -1);
        } elseif ($i <= 255) {
            $this->opcode(0x3a, -1);
            $this->code .= chr($i);
        } else {
            $this->emitWideLoadStore(0x3a, $i, -1);
        }
        $this->maxLocals = max($this->maxLocals, $i + 1);
    }

    private function emitWideLoadStore(int $opcode, int $i, int $stackDelta): void
    {
        if ($i > 65535) {
            throw new \RuntimeException("JVM local index {$i} exceeds wide limit");
        }
        $this->opcode(0xc4, $stackDelta); // wide
        $this->code .= chr($opcode) . pack('n', $i);
    }

    public function dup(): void
    {
        $this->opcode(0x59, 1);
    }

    public function pop_(): void
    {
        $this->opcode(0x57, -1);
    }

    public function swap(): void
    {
        $this->opcode(0x5f, 0);
    }

    public function getstatic(int $fieldRef): void
    {
        $this->opcode(0xb2, 1);
        $this->code .= u2($fieldRef);
    }

    public function putstatic(int $fieldRef): void
    {
        $this->opcode(0xb3, -1);
        $this->code .= u2($fieldRef);
    }

    public function getfield(int $fieldRef): void
    {
        $this->opcode(0xb4, 0); // -1 +1
        $this->code .= u2($fieldRef);
    }

    public function putfield(int $fieldRef): void
    {
        $this->opcode(0xb5, -2);
        $this->code .= u2($fieldRef);
    }

    public function invokevirtual(int $methodRef, int $argSlots, bool|int $returnsValue = false): void
    {
        $retSlots = \is_int($returnsValue) ? $returnsValue : ($returnsValue ? 1 : 0);
        // objectref + args consumed; return may push (doubles/longs = 2)
        $this->opcode(0xb6, -($argSlots + 1) + $retSlots);
        $this->code .= u2($methodRef);
    }

    public function invokespecial(int $methodRef, int $argSlots, bool|int $returnsValue = false): void
    {
        $retSlots = \is_int($returnsValue) ? $returnsValue : ($returnsValue ? 1 : 0);
        $this->opcode(0xb7, -($argSlots + 1) + $retSlots);
        $this->code .= u2($methodRef);
    }

    public function invokestatic(int $methodRef, int $argSlots, bool|int $returnsValue = false): void
    {
        $retSlots = \is_int($returnsValue) ? $returnsValue : ($returnsValue ? 1 : 0);
        $this->opcode(0xb8, -$argSlots + $retSlots);
        $this->code .= u2($methodRef);
    }

    public function invokeinterface(int $methodRef, int $argSlots, bool|int $returnsValue = false): void
    {
        $retSlots = \is_int($returnsValue) ? $returnsValue : ($returnsValue ? 1 : 0);
        $this->opcode(0xb9, -($argSlots + 1) + $retSlots);
        $this->code .= u2($methodRef);
        $this->code .= chr($argSlots + 1);
        $this->code .= chr(0);
    }

    public function new_(int $classIndex): void
    {
        $this->opcode(0xbb, 1);
        $this->code .= u2($classIndex);
    }

    public function checkcast(int $classIndex): void
    {
        $this->opcode(0xc0, 0);
        $this->code .= u2($classIndex);
    }

    public function instanceof_(int $classIndex): void
    {
        $this->opcode(0xc1, 0); // pop ref, push int
        $this->code .= u2($classIndex);
    }

    public function anewarray(int $classIndex): void
    {
        $this->opcode(0xbd, 0); // pop count, push arrayref
        $this->code .= u2($classIndex);
    }

    public function newarray(int $atype): void
    {
        $this->opcode(0xbc, 0);
        $this->code .= chr($atype);
    }

    public function arraylength(): void
    {
        $this->opcode(0xbe, 0);
    }

    public function aaload(): void
    {
        $this->opcode(0x32, -1);
    }

    public function aastore(): void
    {
        $this->opcode(0x53, -3);
    }

    public function areturn(): void
    {
        $this->opcode(0xb0, -1);
    }

    public function ireturn(): void
    {
        $this->opcode(0xac, -1);
    }

    public function return_(): void
    {
        $this->opcode(0xb1, 0);
    }

    public function athrow(): void
    {
        $this->opcode(0xbf, -1);
    }

    /**
     * Register an exception table entry resolved from labels at {@see resolve()}.
     * Catch type 0 = catch-all; otherwise a CONSTANT_Class index (e.g. Throwable).
     */
    public function exception(string $startLabel, string $endLabel, string $handlerLabel, int $catchType = 0): void
    {
        $this->pendingExceptions[] = [
            'start' => $startLabel,
            'end' => $endLabel,
            'handler' => $handlerLabel,
            'type' => $catchType,
        ];
    }

    /** Adjust tracked stack depth (e.g. exception object present at a handler entry). */
    public function assumeStackDelta(int $delta): void
    {
        if ($delta > 0) {
            $this->push($delta);
        } elseif ($delta < 0) {
            $this->pop(-$delta);
        }
    }

    public function ifeq(string $label): void
    {
        $this->branch(0x99, $label, -1);
    }

    public function ifne(string $label): void
    {
        $this->branch(0x9a, $label, -1);
    }

    public function iflt(string $label): void
    {
        $this->branch(0x9b, $label, -1);
    }

    public function ifle(string $label): void
    {
        $this->branch(0x9e, $label, -1);
    }

    public function ifnull(string $label): void
    {
        $this->branch(0xc6, $label, -1);
    }

    public function ifnonnull(string $label): void
    {
        $this->branch(0xc7, $label, -1);
    }

    public function if_icmpeq(string $label): void
    {
        $this->branch(0x9f, $label, -2);
    }

    public function if_icmpne(string $label): void
    {
        $this->branch(0xa0, $label, -2);
    }

    public function if_icmplt(string $label): void
    {
        $this->branch(0xa1, $label, -2);
    }

    public function if_icmpge(string $label): void
    {
        $this->branch(0xa2, $label, -2);
    }

    public function if_acmpeq(string $label): void
    {
        $this->branch(0xa5, $label, -2);
    }

    public function if_acmpne(string $label): void
    {
        $this->branch(0xa6, $label, -2);
    }

    public function goto_(string $label): void
    {
        $this->branch(0xa7, $label, 0);
    }

    private function branch(int $op, string $label, int $stackDelta): void
    {
        $this->fixups[] = ['at' => $this->offset(), 'label' => $label, 'wide' => false];
        $this->opcode($op, $stackDelta);
        $this->code .= "\0\0";
    }

    public function iadd(): void
    {
        $this->opcode(0x60, -1);
    }

    public function isub(): void
    {
        $this->opcode(0x64, -1);
    }

    public function imul(): void
    {
        $this->opcode(0x68, -1);
    }

    public function idiv(): void
    {
        $this->opcode(0x6c, -1);
    }

    public function ineg(): void
    {
        $this->opcode(0x74, 0);
    }

    public function ladd(): void
    {
        $this->opcode(0x61, -2);
    }

    public function land(): void
    {
        $this->opcode(0x7f, -2);
    }

    public function lsub(): void
    {
        $this->opcode(0x65, -2);
    }

    public function lmul(): void
    {
        $this->opcode(0x69, -2);
    }

    public function ldiv(): void
    {
        $this->opcode(0x6d, -2);
    }

    public function lneg(): void
    {
        $this->opcode(0x75, 0);
    }

    public function lrem(): void
    {
        $this->opcode(0x71, -2);
    }

    public function lcmp(): void
    {
        $this->opcode(0x94, -3); // two longs → int
    }

    public function l2i(): void
    {
        $this->opcode(0x88, -1);
    }

    public function i2l(): void
    {
        $this->opcode(0x85, 1);
    }

    public function i2b(): void
    {
        $this->opcode(0x91, 0);
    }

    public function i2s(): void
    {
        $this->opcode(0x93, 0);
    }

    public function i2c(): void
    {
        $this->opcode(0x92, 0);
    }

    public function d2l(): void
    {
        $this->opcode(0x8f, 0);
    }

    public function l2d(): void
    {
        $this->opcode(0x8a, 0);
    }

    public function lreturn(): void
    {
        $this->opcode(0xad, -2);
    }

    public function lload(int $i): void
    {
        if ($i <= 3) {
            $this->opcode(0x1e + $i, 2);
        } elseif ($i <= 255) {
            $this->opcode(0x16, 2);
            $this->code .= chr($i);
        } else {
            $this->emitWideLoadStore(0x16, $i, 2);
        }
        $this->maxLocals = max($this->maxLocals, $i + 2);
    }

    public function lstore(int $i): void
    {
        if ($i <= 3) {
            $this->opcode(0x3f + $i, -2);
        } elseif ($i <= 255) {
            $this->opcode(0x37, -2);
            $this->code .= chr($i);
        } else {
            $this->emitWideLoadStore(0x37, $i, -2);
        }
        $this->maxLocals = max($this->maxLocals, $i + 2);
    }

    public function lconst(int $v): void
    {
        if ($v === 0) {
            $this->opcode(0x09, 2); // lconst_0
        } elseif ($v === 1) {
            $this->opcode(0x0a, 2); // lconst_1
        } else {
            throw new \RuntimeException('lconst only supports 0/1; use ldc2_w');
        }
    }

    public function ldc2_w(int $index): void
    {
        $this->opcode(0x14, 2);
        $this->code .= u2($index);
    }

    public function dadd(): void
    {
        $this->opcode(0x63, -2); // two doubles → one double
    }

    public function dsub(): void
    {
        $this->opcode(0x67, -2);
    }

    public function dmul(): void
    {
        $this->opcode(0x6b, -2);
    }

    public function ddiv(): void
    {
        $this->opcode(0x6f, -2);
    }

    public function dneg(): void
    {
        $this->opcode(0x77, 0);
    }

    /** Resolve forward/back branches and exception table labels; must be called before write. */
    public function resolve(): void
    {
        foreach ($this->fixups as $f) {
            if (!isset($this->labels[$f['label']])) {
                throw new \RuntimeException("undefined label {$f['label']}");
            }
            $target = $this->labels[$f['label']];
            $at = $f['at'];
            $delta = $target - $at;
            if ($delta < -32768 || $delta > 32767) {
                throw new \RuntimeException('branch offset out of range');
            }
            $this->code[$at + 1] = chr(($delta >> 8) & 0xff);
            $this->code[$at + 2] = chr($delta & 0xff);
        }

        foreach ($this->pendingExceptions as $ex) {
            foreach (['start', 'end', 'handler'] as $key) {
                if (!isset($this->labels[$ex[$key]])) {
                    throw new \RuntimeException("undefined exception label {$ex[$key]}");
                }
            }
            $this->exceptions[] = [
                'start' => $this->labels[$ex['start']],
                'end' => $this->labels[$ex['end']],
                'handler' => $this->labels[$ex['handler']],
                'type' => $ex['type'],
            ];
        }
        $this->pendingExceptions = [];
    }

    /**
     * @param ConstantPool $cp
     * @param list<string|int> $argLocals verification types for parameters (and this if instance)
     */
    public function toCodeAttribute(ConstantPool $cp, array $argLocals): string
    {
        $this->resolve();
        $code = $this->code;
        $stackMap = buildStackMapTable($cp, $this->frames, $argLocals);
        $lineTable = buildLineNumberTable($this->lineNumbers);

        $attrs = '';
        $attrCount = 0;
        if ($stackMap !== '') {
            $attrs .= attribute($cp, 'StackMapTable', $stackMap);
            ++$attrCount;
        }
        if ($lineTable !== '') {
            $attrs .= attribute($cp, 'LineNumberTable', $lineTable);
            ++$attrCount;
        }

        $body = u2($this->maxStack)
            . u2($this->maxLocals)
            . u4(strlen($code))
            . $code
            . u2(count($this->exceptions));
        foreach ($this->exceptions as $ex) {
            $body .= u2($ex['start']) . u2($ex['end']) . u2($ex['handler']) . u2($ex['type']);
        }
        $body .= u2($attrCount) . $attrs;

        return attribute($cp, 'Code', $body);
    }
}

/**
 * @param list<array{pc: int, line: int}> $entries
 */
function buildLineNumberTable(array $entries): string
{
    if ($entries === []) {
        return '';
    }
    usort($entries, static fn ($a, $b) => $a['pc'] <=> $b['pc']);
    $deduped = [];
    foreach ($entries as $entry) {
        $deduped[$entry['pc']] = $entry;
    }
    $table = u2(count($deduped));
    foreach ($deduped as $entry) {
        $table .= u2($entry['pc']) . u2($entry['line'] & 0xffff);
    }

    return $table;
}

/**
 * Full-frame StackMapTable entries at noted offsets (conservative, simple).
 *
 * @param list<array{offset: int, locals: list<string|int>, stack: list<string|int>}> $frames
 * @param list<string|int> $initialLocals
 */
function buildStackMapTable(ConstantPool $cp, array $frames, array $initialLocals): string
{
    if ($frames === []) {
        return '';
    }

    // Sort and uniquify by offset, keeping the last frame at a given label so
    // repeated noteFrame calls do not produce negative deltas. Offset 0 needs an
    // explicit frame when a backward branch targets the method's first byte.
    usort($frames, static fn ($a, $b) => $a['offset'] <=> $b['offset']);
    $deduped = [];
    foreach ($frames as $frame) {
        $deduped[$frame['offset']] = $frame;
    }
    $entries = '';
    $count = 0;
    $prevOffset = -1;
    foreach ($deduped as $frame) {
        $off = $frame['offset'];
        $offsetDelta = $prevOffset < 0 ? $off : ($off - $prevOffset - 1);
        $prevOffset = $off;
        // full_frame = 255
        $entries .= chr(255) . u2($offsetDelta);
        $entries .= u2(count($frame['locals']));
        foreach ($frame['locals'] as $t) {
            $entries .= verificationType($cp, $t);
        }
        $entries .= u2(count($frame['stack']));
        foreach ($frame['stack'] as $t) {
            $entries .= verificationType($cp, $t);
        }
        ++$count;
    }

    if ($count === 0) {
        return '';
    }

    return u2($count) . $entries;
}

/** @param string|int $t 'int'|'float'|'null'|'uninit_this'|internal class name|object already class index */
function verificationType(ConstantPool $cp, string|int $t): string
{
    if (\is_int($t)) {
        return chr(7) . u2($t);
    }

    return match ($t) {
        'top' => chr(0),
        'int' => chr(1),
        'float' => chr(2),
        'double' => chr(3),
        'long' => chr(4),
        'null' => chr(5),
        'uninit_this' => chr(6),
        default => chr(7) . u2($cp->class_($t)),
    };
}

final class ClassBuilder
{
    public ConstantPool $cp;
    private string $thisInternal;
    private string $superInternal;
    private int $access;
    private ?string $sourceFile = null;

    /** @var list<string> */
    private array $interfaces = [];

    /** @var list<string> */
    private array $fields = [];

    /** @var list<string> */
    private array $methods = [];

    public function __construct(string $thisInternal, string $superInternal = 'java/lang/Object', int $access = 0x0021)
    {
        $this->cp = new ConstantPool();
        $this->thisInternal = $thisInternal;
        $this->superInternal = $superInternal;
        $this->access = $access; // public + super
        $this->cp->class_($thisInternal);
        $this->cp->class_($superInternal);
        $this->cp->utf8('Code');
        $this->cp->utf8('StackMapTable');
        $this->cp->utf8('LineNumberTable');
        $this->cp->utf8('SourceFile');
    }

    public function setSourceFile(string $displayPath): void
    {
        $this->sourceFile = $displayPath;
        // Must be in the constant pool before write().
        $this->cp->utf8(basename(\str_replace('\\', '/', $displayPath)));
    }

    public function implement(string $ifaceInternal): void
    {
        $this->cp->class_($ifaceInternal);
        $this->interfaces[] = $ifaceInternal;
    }

    public function addField(string $name, string $descriptor, int $access = 0x0001): void
    {
        $n = $this->cp->utf8($name);
        $d = $this->cp->utf8($descriptor);
        $this->fields[] = u2($access) . u2($n) . u2($d) . u2(0);
    }

    /**
     * @param callable(CodeBuilder, ConstantPool): void $build
     * @param list<string|int> $paramVtypes verification types for args (instance methods: include this as first)
     */
    public function addMethod(
        string $name,
        string $descriptor,
        int $access,
        int $maxLocals,
        array $paramVtypes,
        callable $build,
    ): void {
        $n = $this->cp->utf8($name);
        $d = $this->cp->utf8($descriptor);
        $code = new CodeBuilder($maxLocals);
        $build($code, $this->cp);
        $codeAttr = $code->toCodeAttribute($this->cp, $paramVtypes);
        $this->methods[] = u2($access) . u2($n) . u2($d) . u2(1) . $codeAttr;
    }

    public function toBytes(): string
    {
        $thisClass = $this->cp->class_($this->thisInternal);
        $superClass = $this->cp->class_($this->superInternal);

        $ifaces = '';
        foreach ($this->interfaces as $i) {
            $ifaces .= u2($this->cp->class_($i));
        }

        $out = u4(0xCAFEBABE)
            . u2(0)   // minor
            . u2(65)  // major — Java 21
            . $this->cp->write()
            . u2($this->access)
            . u2($thisClass)
            . u2($superClass)
            . u2(count($this->interfaces))
            . $ifaces
            . u2(count($this->fields));
        foreach ($this->fields as $f) {
            $out .= $f;
        }
        $out .= u2(count($this->methods));
        foreach ($this->methods as $m) {
            $out .= $m;
        }
        if ($this->sourceFile !== null && $this->sourceFile !== '') {
            $nameIdx = $this->cp->utf8('SourceFile');
            $fileIdx = $this->cp->utf8(basename(\str_replace('\\', '/', $this->sourceFile)));
            $out .= u2(1) . u2($nameIdx) . u4(2) . u2($fileIdx);
        } else {
            $out .= u2(0); // attributes
        }

        return $out;
    }
}

function u2(int $v): string
{
    return pack('n', $v & 0xffff);
}

function u4(int $v): string
{
    // unsigned 32-bit big-endian; PHP pack('N') treats as unsigned
    return pack('N', $v & 0xffffffff);
}

function u8(int $v): string
{
    // big-endian 64-bit two's complement
    return pack('NN', ($v >> 32) & 0xffffffff, $v & 0xffffffff);
}

function utf8Info(string $s): string
{
    $encoded = encodeModifiedUtf8($s);
    $len = strlen($encoded);
    if ($len > 0xffff) {
        throw new \RuntimeException('CONSTANT_Utf8 entry too large');
    }

    return u2($len) . $encoded;
}

function encodeModifiedUtf8(string $s): string
{
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len;) {
        $b0 = ord($s[$i]);
        if (($b0 & 0x80) === 0) {
            ++$i;
            if ($b0 === 0) {
                $out .= "\xc0\x80";
            } else {
                $out .= $s[$i - 1];
            }
            continue;
        }

        if (($b0 & 0xE0) === 0xC0) {
            if ($i + 1 >= $len) {
                throw new \RuntimeException('invalid UTF-8 in CONSTANT_Utf8');
            }
            $b1 = ord($s[$i + 1]);
            if (($b1 & 0xC0) !== 0x80) {
                throw new \RuntimeException('invalid UTF-8 in CONSTANT_Utf8');
            }
            $out .= $s[$i] . $s[$i + 1];
            $i += 2;
            continue;
        }

        if (($b0 & 0xF0) === 0xE0) {
            if ($i + 2 >= $len) {
                throw new \RuntimeException('invalid UTF-8 in CONSTANT_Utf8');
            }
            $b1 = ord($s[$i + 1]);
            $b2 = ord($s[$i + 2]);
            if ((($b1 | $b2) & 0xC0) !== 0x80) {
                throw new \RuntimeException('invalid UTF-8 in CONSTANT_Utf8');
            }
            $out .= $s[$i] . $s[$i + 1] . $s[$i + 2];
            $i += 3;
            continue;
        }

        if (($b0 & 0xF8) === 0xF0) {
            if ($i + 3 >= $len) {
                throw new \RuntimeException('invalid UTF-8 in CONSTANT_Utf8');
            }
            $b1 = ord($s[$i + 1]);
            $b2 = ord($s[$i + 2]);
            $b3 = ord($s[$i + 3]);
            if ((($b1 | $b2 | $b3) & 0xC0) !== 0x80) {
                throw new \RuntimeException('invalid UTF-8 in CONSTANT_Utf8');
            }

            $codePoint = (($b0 & 0x07) << 18)
                | (($b1 & 0x3F) << 12)
                | (($b2 & 0x3F) << 6)
                | ($b3 & 0x3F);
            if ($codePoint > 0x10FFFF) {
                throw new \RuntimeException('invalid UTF-8 in CONSTANT_Utf8');
            }

            $codePoint -= 0x10000;
            $high = 0xD800 | (($codePoint >> 10) & 0x3FF);
            $low = 0xDC00 | ($codePoint & 0x3FF);
            $out .= chr(0xE0 | (($high >> 12) & 0x0F))
                . chr(0x80 | (($high >> 6) & 0x3F))
                . chr(0x80 | ($high & 0x3F))
                . chr(0xE0 | (($low >> 12) & 0x0F))
                . chr(0x80 | (($low >> 6) & 0x3F))
                . chr(0x80 | ($low & 0x3F));
            $i += 4;
            continue;
        }

        throw new \RuntimeException('invalid UTF-8 in CONSTANT_Utf8');
    }

    return $out;
}

function attribute(ConstantPool $cp, string $name, string $info): string
{
    return u2($cp->utf8($name)) . u4(strlen($info)) . $info;
}
