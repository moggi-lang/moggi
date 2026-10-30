<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\Backend\Jvm\Classfile\CodeBuilder;
use Moggi\Backend\Jvm\Classfile\ConstantPool;
use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;
use function Moggi\Backend\Meta\tempReadsForFunction;

final class EmitEnv
{
    /** @var array<string, int> */
    public array $locals = [];

    /** @var array<int, string> */
    public array $localTypes = [];

    public int $nextLocal;

    private int $labelId = 0;

    /** Read counts of the body being emitted, computed once per function ({@see tempReadsForFunction}).
     * @var ?array<int, int> */
    public ?array $tempReads = null;

    /** Generated method name of the function currently being emitted. */
    public string $currentMethod = '';

    /**
     * Local slots of the enclosing function's declared parameters, in
     * `FunctionDecl::$params` order. `IR\TailRecall` writes these slots and
     * jumps back to {@see $tailLoopHead}.
     *
     * @var list<int>
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
     * @param array<string, true> $foreignInterfaces internal names of interface owners, from this
     *   compilation's `foreign jvm type` declarations
     */
    public function __construct(
        public CodeBuilder $c,
        public ConstantPool $cp,
        public string $owner,
        public array $functionArity,
        public array $externalFns,
        int $paramCount,
        public array $lambdaMeta = [],
        public ?FnWrapperRegistry $wrapperRegistry = null,
        public ?IoThunkRegistry $ioThunkRegistry = null,
        public array $newtypeConstructors = [],
        public ?SourceMapBuilder $sourceMap = null,
        public array $localCallables = [],
        public array $foreignInterfaces = [],
    ) {
        $this->nextLocal = $paramCount;
        for ($i = 0; $i < $paramCount; ++$i) {
            $this->localTypes[$i] = 'java/lang/Object';
        }
    }

    public function freshLocal(string $type = 'java/lang/Object'): int
    {
        $slot = $this->nextLocal++;
        $this->localTypes[$slot] = $type;
        if ($type === 'long' || $type === 'double') {
            $this->localTypes[$this->nextLocal++] = 'top';
        }

        return $slot;
    }

    public function localSlot(string $name, string $type = 'java/lang/Object'): int
    {
        if (isset($this->locals[$name])) {
            $slot = $this->locals[$name];
            if (($this->localTypes[$slot] ?? 'top') === 'top' && $type !== 'top') {
                $this->localTypes[$slot] = $type;
            }

            return $slot;
        }
        $slot = $this->freshLocal($type);
        $this->locals[$name] = $slot;

        return $slot;
    }

    public function freshLabelId(): int
    {
        return ++$this->labelId;
    }

    /** @return list<string> */
    public function frameLocals(?int $limit = null): array
    {
        $limit ??= $this->nextLocal;
        $locals = [];
        for ($i = 0; $i < $limit; ++$i) {
            $t = $this->localTypes[$i] ?? 'top';
            if ($t === 'long' || $t === 'double') {
                $locals[] = $t;
                ++$i;

                continue;
            }
            $locals[] = $t;
        }
        while ($locals !== [] && $locals[\array_key_last($locals)] === 'top') {
            \array_pop($locals);
        }

        return $locals;
    }
}

final class FnWrapperRegistry
{
    /** @var array<string, array{internal: string, targetOwner: string, targetSym: string, arity: int}> */
    private array $specs = [];

    public function ensure(string $targetOwner, string $targetSym, int $arity): string
    {
        $internal = fnWrapperName($targetOwner, $targetSym, $arity);
        $this->specs[$internal] ??= [
            'internal' => $internal,
            'targetOwner' => $targetOwner,
            'targetSym' => $targetSym,
            'arity' => $arity,
        ];

        return $internal;
    }

    /** @return list<array{internal: string, targetOwner: string, targetSym: string, arity: int}> */
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

