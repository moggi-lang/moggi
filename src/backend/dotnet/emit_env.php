<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;
use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\Backend\DotNet\Il\ilCanonicalize;
use function Moggi\Backend\DotNet\Il\ilInstructionSize;
use function Moggi\Backend\DotNet\Il\ilIsInstruction;
use function Moggi\Backend\DotNet\Naming\runtimeMemberName;
use function Moggi\Backend\Meta\tempReadsForFunction;

// --- IL text builders (symbolic locals/labels; no verifier stack-map pass). ---

final class ClassIlBuilder
{
    /** @var list<string> */
    private array $methods = [];

    public function __construct(private readonly string $typeName)
    {
    }

    public function addMethod(string $text): void
    {
        $this->methods[] = $text;
    }

    public function toIl(): string
    {
        $out = ".class public abstract auto ansi sealed beforefieldinit {$this->typeName}\n";
        $out .= "       extends [System.Runtime]System.Object\n{\n";
        foreach ($this->methods as $method) {
            $out .= $method;
        }
        $out .= "}\n\n";

        return $out;
    }
}

/**
 * Accumulates one `.method` body. Locals are symbolic ids (`V0`, `V1`, …)
 * declared once in `.locals init (...)`; parameters are referenced as
 * `arg:N` slots (dispatches to `ldarg`, never `ldloc`) — CIL keeps arguments
 * and locals in separate address spaces.
 */
final class MethodIl
{
    /** @var array<string, string> id => CIL type */
    private array $locals = [];

    private int $localCounter = 0;

    private int $labelCounter = 0;

    /** @var list<string> */
    private array $lines = [];

    /** Byte offset of the next instruction from the start of the method body. */
    private int $codeOffset = 0;

    /** Source location awaiting the next instruction (a `.line` sequence point). */
    private ?IR\SrcLoc $pendingLoc = null;

    /** @var list<array{0: int, 1: IR\SrcLoc}> sequence point offset => location */
    private array $sequencePoints = [];

    /**
     * The first location noted on each line: the frame text a line resolves to.
     *
     * A line is noted more than once (a statement's head, then an expression under it) and only
     * the last note before an instruction becomes that instruction's sequence point, so the rows
     * alone would name whichever expression happened to sit closest to the call. The JVM frame
     * table is keyed by line and keeps the first row, so this is the same answer.
     *
     * @var array<int, IR\SrcLoc>
     */
    private array $firstLocOnLine = [];

    public function freshLocal(string $type = 'object'): string
    {
        $id = 'V' . $this->localCounter++;
        $this->locals[$id] = $type;

        return $id;
    }

    public function freshLabelId(): int
    {
        return ++$this->labelCounter;
    }

    public function emit(string $line): void
    {
        $trimmed = \trim($line);
        if (! ilIsInstruction($trimmed)) {
            $this->lines[] = $line;

            return;
        }
        $line = ilCanonicalize($line);
        if ($this->pendingLoc !== null) {
            $this->sequencePoints[] = [$this->codeOffset, $this->pendingLoc];
            $this->pendingLoc = null;
        }
        $this->codeOffset += ilInstructionSize(\trim($line));
        $this->lines[] = $line;
    }

    /**
     * Emit an ILASM sequence point for a Moggi source location. The offset is
     * attached to the next instruction emitted, which is what a host stack
     * trace reports for a frame executing in that statement.
     */
    public function emitLine(?IR\SrcLoc $loc): void
    {
        if ($loc === null || $loc->line <= 0) {
            return;
        }
        $this->pendingLoc = $loc;
        $this->firstLocOnLine[$loc->line] ??= $loc;
        $line = $loc->line;
        $col = \max(1, $loc->col > 0 ? $loc->col : 1);
        $file = normalizeDisplayPath($loc->file ?? '');
        if ($file === '') {
            $this->emit(".line {$line}");

            return;
        }
        $escaped = \str_replace("'", "\\'", \str_replace('\\', '/', $file));
        $this->emit(".line {$line}, {$line} : {$col}, {$col} '{$escaped}'");
    }

    public function label(string $name): void
    {
        $this->lines[] = $name . ':';
    }

    public function load(string $slot): void
    {
        if (\str_starts_with($slot, 'arg:')) {
            $this->emit('ldarg ' . \substr($slot, 4));

            return;
        }
        $this->emit('ldloc ' . $slot);
    }

    public function store(string $slot): void
    {
        if (\str_starts_with($slot, 'arg:')) {
            throw new \RuntimeException('DotNet emit: cannot store into a parameter slot');
        }
        $this->emit('stloc ' . $slot);
    }

    /** Overwrite argument slot `$index` — `IR\TailRecall`'s loop back-edge. */
    public function storeArg(int $index): void
    {
        $this->emit('starg ' . $index);
    }

    /**
     * The finished method body, and its frame rows when a sink is given.
     *
     * Rows are keyed by IL offset because that is what a host stack trace reports, but their text
     * is the *first* location recorded on the row's line: several offsets share a line (a
     * statement's head and the argument expressions under it), and a frame naming the last of them
     * would point at an argument instead of at the call that faulted. The JVM table is keyed by
     * line and keeps the first row, so this is the same answer there by a different shape.
     *
     * @param list<string> $paramTypes
     */
    public function render(
        string $name,
        array $paramTypes,
        string $returnType = 'object',
        bool $entryPoint = false,
        ?SourceMapBuilder $frameSink = null,
        string $frameOwner = '',
    ): string {
        if ($frameSink !== null && $frameOwner !== '') {
            $member = runtimeMemberName($name);
            foreach ($this->sequencePoints as [$offset, $loc]) {
                $frameSink->addIlFrame($frameOwner, $member, $offset, $this->firstLocOnLine[$loc->line] ?? $loc);
            }
        }
        $params = \implode(', ', $paramTypes);
        $out = ".method public hidebysig static {$returnType} {$name}({$params}) cil managed\n{\n";
        if ($entryPoint) {
            $out .= "  .entrypoint\n";
        }
        $out .= "  .maxstack 64\n";
        if ($this->locals !== []) {
            $decls = [];
            foreach ($this->locals as $id => $type) {
                $decls[] = "{$type} {$id}";
            }
            $out .= '  .locals init (' . \implode(', ', $decls) . ")\n";
        }
        foreach ($this->lines as $line) {
            $out .= ($line !== '' && ! \str_ends_with($line, ':') ? '  ' : '') . $line . "\n";
        }
        $out .= "}\n\n";

        return $out;
    }
}

final class EmitEnv
{
    /** @var array<string, string> source name => IL local id, or `arg:N` */
    public array $locals = [];


    /** Read counts of the body being emitted, computed once per function ({@see tempReadsForFunction}).
     * @var ?array<int, int> */
    public ?array $tempReads = null;

    /**
     * `arg:N` slots of the enclosing function's declared parameters, in
     * `FunctionDecl::$params` order. `IR\TailRecall` overwrites these slots
     * with `starg` and jumps back to {@see $tailLoopHead}.
     *
     * @var list<string>
     */
    public array $tailRecSlots = [];

    /** Label of the innermost `IR\Loop` head, or null outside a tail loop. */
    public ?string $tailLoopHead = null;

    /**
     * @param array<string, int> $functionArity
     * @param array<string, string> $externalFns
     * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
     * @param array<string, true> $newtypeConstructors
     * @param array<string, true> $localCallables
     */
    public function __construct(
        public MethodIl $m,
        public string $owner,
        public array $functionArity,
        public array $externalFns,
        public array $lambdaMeta = [],
        public ?FnWrapperRegistry $wrapperRegistry = null,
        public ?IoThunkRegistry $ioThunkRegistry = null,
        public array $newtypeConstructors = [],
        public ?SourceMapBuilder $sourceMap = null,
        public array $localCallables = [],
    ) {
    }

    public function freshLocal(string $type = 'object'): string
    {
        return $this->m->freshLocal($type);
    }

    /**
     * Reuses an existing binding unless it is a parameter (`arg:N`) — CIL
     * cannot `stloc` an argument, so a fresh local is allocated instead
     * (covers e.g. a `let` shadowing a parameter name).
     */
    public function localSlot(string $name, string $type = 'object'): string
    {
        $existing = $this->locals[$name] ?? null;
        if ($existing !== null && ! \str_starts_with($existing, 'arg:')) {
            return $existing;
        }
        $slot = $this->freshLocal($type);
        $this->locals[$name] = $slot;

        return $slot;
    }

    public function freshLabelId(): int
    {
        return $this->m->freshLabelId();
    }
}

final class FnWrapperRegistry
{
    /** @var array<string, array{typeName: string, targetOwner: string, targetSym: string, arity: int}> */
    private array $specs = [];

    public function ensure(string $targetOwner, string $targetSym, int $arity): string
    {
        $typeName = fnWrapperName($targetOwner, $targetSym, $arity);
        $this->specs[$typeName] ??= [
            'typeName' => $typeName,
            'targetOwner' => $targetOwner,
            'targetSym' => $targetSym,
            'arity' => $arity,
        ];

        return $typeName;
    }

    /** @return list<array{typeName: string, targetOwner: string, targetSym: string, arity: int}> */
    public function specs(): array
    {
        return \array_values($this->specs);
    }
}

final class IoThunkRegistry
{
    /** @var list<array{name: string, captures: list<string>, body: IR\Block, result: IR\Operand}> */
    private array $specs = [];

    private int $nextId = 0;

    private int $emitted = 0;

    /** @param list<string> $captures */
    public function register(array $captures, IR\Block $body, IR\Operand $result): array
    {
        $name = '__ioThunk' . $this->nextId++;
        $spec = [
            'name' => $name,
            'captures' => $captures,
            'body' => $body,
            'result' => $result,
        ];
        $this->specs[] = $spec;

        return $spec;
    }

    /** @return list<array{name: string, captures: list<string>, body: IR\Block, result: IR\Operand}> */
    public function pending(): array
    {
        return \array_slice($this->specs, $this->emitted);
    }

    public function markEmitted(): void
    {
        $this->emitted = \count($this->specs);
    }
}

// --- Function / evidence / entry emission -------------------------------------

