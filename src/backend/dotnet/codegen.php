<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;

use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;

use function Moggi\Backend\DotNet\Foreign\dotNetHostIsValueType;
use function Moggi\Backend\DotNet\Foreign\isDotNetConstProperty;
use function Moggi\Backend\DotNet\Foreign\resolveDotNetForeignPath;
use function Moggi\Backend\DotNet\Il\ilCanonicalize;
use function Moggi\Backend\DotNet\Il\ilInstructionSize;
use function Moggi\Backend\DotNet\Il\ilIsInstruction;
use function Moggi\Backend\DotNet\Il\ilString;
use function Moggi\Backend\DotNet\Naming\bclAssemblyFor;
use function Moggi\Backend\DotNet\Naming\moduleTypeName;
use function Moggi\Backend\DotNet\Naming\runtimeMemberName;
use function Moggi\Backend\DotNet\Naming\symbolName;
use function Moggi\Backend\Meta\constructorArityMap;
use function Moggi\Backend\Meta\constructorArityMapFromRegistry;
use function Moggi\Backend\Meta\isBoolBackedData;
use function Moggi\Backend\Meta\lambdaCaptureNamesInBlock;
use function Moggi\Backend\Meta\newtypeConstructorMap;
use function Moggi\Backend\Meta\newtypeConstructorMapFromRegistry;
use function Moggi\Backend\Meta\operandCallSrcLoc;
use function Moggi\Debug\SourceMapBuilder;
use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\Debug\symbolId;
use function Moggi\IR\Visit\collectLocalsInBlock;
use function Moggi\IR\Visit\collectLocalsInOperand;
use function Moggi\IR\Visit\stmtNestedBlocks;
use function Moggi\IR\Visit\walkBlock;
use function Moggi\IR\Visit\walkOperand;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Modules\resolvedSymbol;
use function Moggi\Optimize\Support\buildLambdaMeta;
use function Moggi\Optimize\Support\indexCapturedFunctions;
use function Moggi\Optimize\Support\isCapturedFnName;
use function Moggi\Optimize\Support\isLambdaName;

require_once __DIR__ . '/naming.php';
require_once __DIR__ . '/foreign.php';
require_once __DIR__ . '/il.php';
require_once __DIR__ . '/il_size.php';

/**
 * ILASM module emitter for the .NET/CLR backend (ECMA-335 text only — no C#,
 * no raw PE). Lowers Moggi IR to `.class` blocks per module plus Fn-wrapper
 * types; targets the `Moggi.Rt.*` contract in runtime_abi.php.
 *
 * CIL is flat instruction text assembled by ilasm, so locals and labels are
 * tracked symbolically ({@see MethodIl}). List/tuple construction uses scratch
 * locals because CIL has no `swap`.
 *
 * @param array<string, mixed> $options
 * @return array<string, string> CLR type full name (dots) => ILASM `.class` text
 */
function emitModule(IR\Module $module, string $sourcePath, array $options = []): array
{
    $moduleName = $options['moduleName'] ?? '';
    $owner = moduleTypeName($moduleName !== '' ? $moduleName : 'Main');
    $classBuilder = new ClassIlBuilder($owner);
    $displaySource = normalizeDisplayPath($module->sourceFile !== '' ? $module->sourceFile : $sourcePath);
    $artifact = (string) ($options['outputRelative'] ?? ($owner . '.il'));
    $sourceMap = new SourceMapBuilder($moduleName !== '' ? $moduleName : 'Main', 'dotnet', $artifact);
    $lambdaMeta = buildLambdaMeta(indexCapturedFunctions($module->functions));
    $wrapperRegistry = new FnWrapperRegistry();
    $ioThunkRegistry = new IoThunkRegistry();

    $functionArity = [];
    // Import tables are keyed by bare name, so a name this module defines must
    // win: an imported arity would emit the call as a runtime partial.
    /** @var array<string, true> $localBindings */
    $localBindings = [];
    foreach ($module->functions as $fn) {
        $localBindings[$fn->name] = true;
        $functionArity[$fn->name] = \count($fn->params)
            + \count($lambdaMeta[$fn->name]['captures'] ?? []);
    }
    foreach (constructorArityMap($module->data) as $name => $arity) {
        $localBindings[$name] = true;
        $functionArity[$name] = $arity;
    }
    foreach (constructorArityMapFromRegistry($options['imports']['importedData'] ?? []) as $name => $arity) {
        if (! isset($localBindings[$name])) {
            $functionArity[$name] = $arity;
        }
    }
    foreach (\array_keys($options['globalEvidenceMaps'] ?? []) as $evidenceName) {
        // Fallback only; imports overwrite via externalFnRuntimeArity, and local
        // instance evidence overwrites via contextParams below.
        if (!isset($functionArity[$evidenceName])) {
            $functionArity[$evidenceName] = 0;
        }
    }
    foreach ($options['externalFnRuntimeArity'] ?? [] as $name => $arity) {
        if (! isset($localBindings[$name])) {
            $functionArity[$name] = $arity;
        }
        $resolved = $options['externalFns'][$name] ?? null;
        if (\is_string($resolved)) {
            $parsed = parseResolvedSymbol($resolved);
            if ($parsed !== null) {
                $functionArity[resolvedSymbol($parsed['module'], $parsed['name'])] = $arity;
            }
        }
    }
    foreach ($module->instanceEvidence as $ev) {
        $functionArity[$ev->evidenceName] = \count($ev->contextParams);
    }
    // Specialize qualifies local callees as `Module::name`; emit must resolve
    // those FnRefs the same as bare local names.
    if ($moduleName !== '') {
        foreach ($functionArity as $name => $arity) {
            if (!\is_string($name) || str_contains($name, '::')) {
                continue;
            }
            $functionArity[resolvedSymbol($moduleName, $name)] = $arity;
        }
    }
    foreach ($options['globalFnArity'] ?? [] as $name => $arity) {
        if (!isset($functionArity[$name])) {
            $functionArity[$name] = $arity;
        }
    }

    $externalFns = $options['externalFns'] ?? [];

    /** @var array<string, true> $localCallables */
    $localCallables = [];
    foreach ($module->functions as $fn) {
        $localCallables[$fn->name] = true;
    }
    foreach ($module->instanceEvidence as $ev) {
        $localCallables[$ev->evidenceName] = true;
        foreach ($ev->methods as $irName) {
            $localCallables[$irName] = true;
        }
    }

    $newtypeConstructors = [
        ...newtypeConstructorMapFromRegistry($options['imports']['importedData'] ?? []),
        ...newtypeConstructorMap($module->data),
    ];

    $evidenceMaps = $options['globalEvidenceMaps'] ?? [];
    foreach ($module->instanceEvidence as $ev) {
        $evidenceMaps[$ev->evidenceName] = [
            'module' => $moduleName !== '' ? $moduleName : ($options['moduleName'] ?? ''),
            'methods' => $ev->methods,
        ];
    }

    foreach ($module->functions as $fn) {
        $sourceMap->ensureSymbol(
            $fn->srcLoc ?? new IR\SrcLoc($moduleName, $fn->name, $displaySource, 0, 0),
            symbolName($fn->name),
        );
        emitFunction($classBuilder, $fn, $owner, $functionArity, $externalFns, $evidenceMaps, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $sourceMap, $localCallables);
    }

    foreach ($module->data as $decl) {
        emitDataConstructors($classBuilder, $decl);
    }

    foreach ($module->instanceEvidence as $ev) {
        emitEvidence($classBuilder, $ev, $owner, $functionArity, $externalFns, $wrapperRegistry, $localCallables);
    }

    if ($module->entry !== null && $module->entry->kind === IR\EntryPointKind::Main) {
        emitDotNetMain($classBuilder, $owner, symbolName($module->entry->name), $functionArity[$module->entry->name] ?? 0);
    }

    emitPendingIoThunkMethods($classBuilder, $owner, $functionArity, $externalFns, $evidenceMaps, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $localCallables);

    $types = [$owner => $classBuilder->toIl()];
    foreach ($wrapperRegistry->specs() as $spec) {
        $types[$spec['typeName']] = buildFnWrapperClass($spec['typeName'], $spec['targetOwner'], $spec['targetSym'], $spec['arity']);
    }
    $types['__moggi.map__'] = $sourceMap->toJson();

    return $types;
}

// ---------------------------------------------------------------------------
// IL text builders (symbolic locals/labels; no verifier stack-map pass).
// ---------------------------------------------------------------------------

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
        if (!ilIsInstruction($trimmed)) {
            $this->lines[] = $line;

            return;
        }
        // The encoded offset is this instruction's sequence point (see render()).
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
        $line = $loc->line;
        $col = max(1, $loc->col > 0 ? $loc->col : 1);
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
            // Only the TCO loop back-edge stores into a parameter slot
            // ({@see storeArg}); every other store gets a fresh local.
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
                $frameSink->addIlFrame($frameOwner, $member, $offset, $loc);
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
            $out .= ($line !== '' && !\str_ends_with($line, ':') ? '  ' : '') . $line . "\n";
        }
        $out .= "}\n\n";

        return $out;
    }
}

final class EmitEnv
{
    /** @var array<string, string> source name => IL local id, or `arg:N` */
    public array $locals = [];
    /** When set, IR\Ret inside match arms yields to this temp instead of returning. */
    public ?int $matchYieldDest = null;
    public ?string $matchJoinLabel = null;

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
     * @param array<string, array{module?: string, methods: array<string, string>}|array<string, string>> $evidenceMaps
     * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
     * @param array<string, true> $newtypeConstructors
     * @param array<string, true> $localCallables
     */
    public function __construct(
        public MethodIl $m,
        public string $owner,
        public array $functionArity,
        public array $externalFns,
        public array $evidenceMaps = [],
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
        if ($existing !== null && !\str_starts_with($existing, 'arg:')) {
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
        $typeName = fnWrapperTypeName($targetOwner, $targetSym, $arity);
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

// ---------------------------------------------------------------------------
// Function / evidence / entry emission
// ---------------------------------------------------------------------------

/**
 * @param array<string, int> $functionArity
 * @param array<string, string> $externalFns
 * @param array<string, array{module?: string, methods: array<string, string>}|array<string, string>> $evidenceMaps
 * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
 * @param array<string, true> $localCallables
 */
function emitFunction(
    ClassIlBuilder $b,
    IR\FunctionDecl $fn,
    string $owner,
    array $functionArity,
    array $externalFns,
    array $evidenceMaps,
    array $lambdaMeta,
    FnWrapperRegistry $wrapperRegistry,
    IoThunkRegistry $ioThunkRegistry,
    array $newtypeConstructors = [],
    ?SourceMapBuilder $sourceMap = null,
    array $localCallables = [],
): void {
    $name = symbolName($fn->name);
    $params = $fn->params;
    if (isCapturedFnName($fn->name)) {
        $params = [
            ...($lambdaMeta[$fn->name]['captures'] ?? []),
            ...$params,
        ];
    }
    $arity = \count($params);

    $m = new MethodIl();
    $env = new EmitEnv($m, $owner, $functionArity, $externalFns, $evidenceMaps, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $sourceMap, $localCallables);
    foreach ($params as $i => $p) {
        $env->locals[$p] = 'arg:' . $i;
    }
    // `IR\TailRecall` re-assigns the parameters in place, so capture their slots
    // before a pattern binder or `let` rebinds the name.
    $env->tailRecSlots = \array_map(
        static fn (string $p): string => $env->locals[$p],
        $fn->params,
    );
    $m->emitLine($fn->srcLoc);
    try {
        emitBlock($env, $fn->body);
    } catch (\RuntimeException $e) {
        throw new \RuntimeException($e->getMessage() . " while emitting `{$fn->name}`", 0, $e);
    }
    $m->emit('ldnull');
    emitDotNetFunctionReturn($env);

    $b->addMethod($m->render($name, \array_fill(0, $arity, 'object'), 'object', false, $sourceMap, $owner));
}

/**
 * @param array<string, int> $functionArity
 * @param array<string, string> $externalFns
 * @param array<string, true> $localCallables
 */
function emitEvidence(
    ClassIlBuilder $b,
    IR\InstanceEvidence $ev,
    string $owner,
    array $functionArity,
    array $externalFns,
    FnWrapperRegistry $wrapperRegistry,
    array $localCallables = [],
): void {
    $name = symbolName($ev->evidenceName);
    $ctxArity = \count($ev->contextParams);

    $m = new MethodIl();
    $env = new EmitEnv($m, $owner, $functionArity, $externalFns, [], [], $wrapperRegistry, null, [], null, $localCallables);
    foreach ($ev->contextParams as $i => $p) {
        $env->locals[$p] = 'arg:' . $i;
    }

    // Instance context dictionaries (`Num a => Monoid (Sum a)`) become leading
    // parameters; method slots close over them as Partial args on the evidence fn.
    $pairs = [];
    foreach ($ev->methods as $surface => $irName) {
        $arity = $functionArity[$irName] ?? null;
        if ($arity === null) {
            throw new \RuntimeException("DotNet emit: evidence method `{$irName}` has unknown arity");
        }
        $pairs[] = [
            'surface' => $surface,
            'target' => resolveCallableTarget($env, $irName),
            'arity' => $arity,
        ];
    }

    $n = \count($pairs) * 2;
    $m->emit("ldc.i4 {$n}");
    $m->emit('newarr object');
    foreach ($pairs as $i => $entry) {
        $m->emit('dup');
        $m->emit('ldc.i4 ' . ($i * 2));
        $m->emit('ldstr ' . ilString($entry['surface']));
        $m->emit('stelem.ref');

        $m->emit('dup');
        $m->emit('ldc.i4 ' . ($i * 2 + 1));
        emitEvidenceMethodValue($env, $entry['target'], $entry['arity'], $ev->contextParams);
        $m->emit('stelem.ref');
    }
    $methodsSlot = $env->freshLocal();
    $m->store($methodsSlot);
    $m->load($methodsSlot);
    $m->emit('newobj instance void Moggi.Rt.Dict::.ctor(object[])');
    $m->emit('ret');

    $b->addMethod($m->render($name, \array_fill(0, $ctxArity, 'object')));
}

/** @param array{owner: string, sym: string} $target @param list<string> $ctxParams */
function emitEvidenceMethodValue(EmitEnv $env, array $target, int $arity, array $ctxParams): void
{
    if ($ctxParams === []) {
        emitTopLevelFnValueFromTarget($env, $target, $arity);

        return;
    }
    $env->m->emit("ldc.i4 {$arity}");
    emitTopLevelFnValueFromTarget($env, $target, $arity);
    pushObjectArray($env, \array_map(
        static fn (string $p): IR\Operand => new IR\Local($p),
        $ctxParams,
    ));
    $env->m->emit('newobj instance void Moggi.Rt.Partial::.ctor(int32, class Moggi.Rt.Fn, object[])');
}

/**
 * DotNet entry bridge for `Main.main :: IO a` (result discarded at runtime).
 *
 * Calls the Moggi entry. If it returns an IO action, run it via RT.IoRun.
 * Straight-line foreign IO may already have run eagerly (returns Unit/null);
 * in that case there is nothing left to execute.
 */
function emitDotNetMain(ClassIlBuilder $b, string $owner, string $mainSym, int $arity): void
{
    $m = new MethodIl();
    if ($arity !== 0) {
        $m->emit('ldstr ' . ilString('entry main must be nullary'));
        $m->emit('newobj instance void [System.Runtime]System.Exception::.ctor(string)');
        $m->emit('throw');
        $b->addMethod($m->render('Main', ['string[]'], 'void', true));

        return;
    }

    $exLocal = $m->freshLocal('class [System.Runtime]System.Exception');
    $resultSlot = $m->freshLocal();
    $m->emit('.try');
    $m->emit('{');
    $m->emit('ldarg 0');
    $m->emit('call void Moggi.Rt.Platform::SetArgs(string[])');
    $m->emit("call object {$owner}::{$mainSym}()");
    $m->store($resultSlot);
    $m->load($resultSlot);
    $m->emit('isinst Moggi.Rt.IO');
    $m->emit('brfalse MAIN_DONE');
    $m->load($resultSlot);
    $m->emit('castclass Moggi.Rt.IO');
    $m->emit('call object Moggi.Rt.RT::IoRun(class Moggi.Rt.IO)');
    $m->emit('pop');
    $m->label('MAIN_DONE');
    $m->emit('leave MAIN_END');
    $m->emit('}');
    $m->emit('catch [System.Runtime]System.Exception');
    $m->emit('{');
    $m->store($exLocal);
    $m->load($exLocal);
    $m->emit('call void Moggi.Rt.RT::ReportUncaught(class [System.Runtime]System.Exception)');
    $m->emit('leave MAIN_END');
    $m->emit('}');
    $m->label('MAIN_END');
    $m->emit('ret');

    $b->addMethod($m->render('Main', ['string[]'], 'void', true));
}

/**
 * @param array<string, int> $functionArity
 * @param array<string, string> $externalFns
 * @param array<string, array{module?: string, methods: array<string, string>}|array<string, string>> $evidenceMaps
 * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
 */
function emitPendingIoThunkMethods(
    ClassIlBuilder $b,
    string $owner,
    array $functionArity,
    array $externalFns,
    array $evidenceMaps,
    array $lambdaMeta,
    FnWrapperRegistry $wrapperRegistry,
    IoThunkRegistry $ioThunkRegistry,
    array $newtypeConstructors = [],
    array $localCallables = [],
): void {
    while (($pending = $ioThunkRegistry->pending()) !== []) {
        $ioThunkRegistry->markEmitted();
        foreach ($pending as $spec) {
            $params = $spec['captures'];
            $arity = \count($params);
            $name = $spec['name'];
            $body = $spec['body'];
            $result = $spec['result'];

            $m = new MethodIl();
            $env = new EmitEnv($m, $owner, $functionArity, $externalFns, $evidenceMaps, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, null, $localCallables);
            foreach ($params as $i => $p) {
                $env->locals[$p] = 'arg:' . $i;
            }
            emitBlock($env, $body);
            emitOperand($env, $result);
            $resultSlot = $env->freshLocal();
            $m->store($resultSlot);
            $m->load($resultSlot);
            $m->emit('isinst Moggi.Rt.IO');
            $done = 'io_thunk_done_' . $env->freshLabelId();
            $m->emit('brfalse ' . $done);
            $m->load($resultSlot);
            $m->emit('castclass Moggi.Rt.IO');
            $m->emit('call object Moggi.Rt.RT::IoRun(class Moggi.Rt.IO)');
            $m->emit('ret');
            $m->label($done);
            $m->load($resultSlot);
            $m->emit('ret');

            $b->addMethod($m->render($name, \array_fill(0, $arity, 'object')));
        }
    }
}

function fnWrapperTypeName(string $targetOwner, string $targetSym, int $arity): string
{
    $tag = \preg_replace('/[^A-Za-z0-9_]/', '_', $targetOwner . '_' . $targetSym) ?? ($targetOwner . '_' . $targetSym);

    return 'Moggi.Fn.' . $tag . '_' . $arity;
}

function buildFnWrapperClass(string $typeName, string $targetOwner, string $targetSym, int $arity): string
{
    $lines = [];
    for ($i = 0; $i < $arity; ++$i) {
        $lines[] = 'ldarg 1';
        $lines[] = "ldc.i4 {$i}";
        $lines[] = 'ldelem.ref';
    }
    $callParams = \implode(', ', \array_fill(0, $arity, 'object'));
    $lines[] = "call object {$targetOwner}::{$targetSym}({$callParams})";
    $lines[] = 'ret';
    $body = '';
    foreach ($lines as $line) {
        $body .= '    ' . $line . "\n";
    }

    return <<<IL
.class public auto ansi sealed beforefieldinit {$typeName}
       extends [System.Runtime]System.Object
       implements Moggi.Rt.Fn
{
  .method public hidebysig specialname rtspecialname
          instance void .ctor() cil managed
  {
    .maxstack 8
    ldarg.0
    call instance void [System.Runtime]System.Object::.ctor()
    ret
  }

  .method public hidebysig newslot virtual final
          instance object Invoke(object[] args) cil managed
  {
    .maxstack 32
{$body}  }
}

IL;
}

// ---------------------------------------------------------------------------

function emitBlock(EmitEnv $env, IR\Block $block): void
{
    foreach ($block->items as $stmt) {
        emitStmt($env, $stmt);
    }
}

function emitStmt(EmitEnv $env, IR\Stmt $stmt): void
{
    // Only statements that are themselves a call site become frames; this
    // mirrors the PHP backend so all three traces agree.
    $frameLoc = match ($stmt::class) {
        IR\Ret::class => operandCallSrcLoc($stmt->value),
        IR\Call::class,
        IR\IoCall::class,
        IR\IoRun::class,
        IR\IoAssignAction::class,
        IR\CallValue::class,
        IR\DictCall::class => $stmt->srcLoc ?? null,
        default => null,
    };
    $env->m->emitLine($frameLoc);
    switch ($stmt::class) {
        case IR\Ret::class:
            /** @var IR\Ret $stmt */
            emitOperand($env, $stmt->value);
            if (operandNeverReturns($stmt->value)) {
                return;
            }
            if ($env->matchYieldDest !== null) {
                $env->m->store($env->localSlot('t' . $env->matchYieldDest));
                if ($env->matchJoinLabel !== null) {
                    $env->m->emit('br ' . $env->matchJoinLabel);
                }

                return;
            }
            emitDotNetFunctionReturn($env);

            return;

        case IR\Assign::class:
            /** @var IR\Assign $stmt */
            emitOperand($env, $stmt->value);
            $env->m->store($env->localSlot('t' . $stmt->dest));

            return;

        case IR\Let::class:
            /** @var IR\Let $stmt */
            emitOperand($env, $stmt->value);
            $env->m->store($env->localSlot($stmt->name));

            return;

        case IR\Binop::class:
            /** @var IR\Binop $stmt */
            emitIntBinopToStack($env, $stmt->op, $stmt->left, $stmt->right);
            $env->m->store($env->localSlot('t' . $stmt->dest));

            return;

        case IR\Call::class:
            /** @var IR\Call $stmt */
            emitStaticCall($env, $stmt->callee, $stmt->args);
            $env->m->store($env->localSlot('t' . $stmt->dest));

            return;

        case IR\CallValue::class:
            /** @var IR\CallValue $stmt */
            emitOperand($env, $stmt->callee);
            emitRuntimeApply($env, $stmt->args);
            $env->m->store($env->localSlot('t' . $stmt->dest));

            return;

        case IR\IoRun::class:
            /** @var IR\IoRun $stmt */
            emitOperand($env, $stmt->action);
            $env->m->emit('castclass Moggi.Rt.IO');
            $env->m->emit('call object Moggi.Rt.RT::IoRun(class Moggi.Rt.IO)');
            if ($stmt->dest !== null) {
                $env->m->store($env->localSlot('t' . $stmt->dest));
            } else {
                $env->m->emit('pop');
            }

            return;

        case IR\IoCall::class:
            /** @var IR\IoCall $stmt */
            emitIoCall($env, $stmt);

            return;

        case IR\IoAssignAction::class:
            /** @var IR\IoAssignAction $stmt */
            emitIoAssignAction($env, $stmt);

            return;

        case IR\IoMatch::class:
            /** @var IR\IoMatch $stmt */
            emitIoMatch($env, $stmt);

            return;

        case IR\IoThrow::class:
            /** @var IR\IoThrow $stmt */
            emitOperand($env, $stmt->exception);
            emitDotNetPushSrcLoc($env, $stmt->srcLoc);
            $env->m->emit('call object Moggi.Rt.RT::ThrowSomeException(object, object[])');
            if ($stmt->dest !== null) {
                $env->m->store($env->localSlot('t' . $stmt->dest));
            } else {
                $env->m->emit('pop');
            }

            return;

        case IR\IoCatch::class:
            /** @var IR\IoCatch $stmt */
            emitOperand($env, $stmt->action);
            $env->m->emit('castclass Moggi.Rt.IO');
            emitOperand($env, $stmt->handler);
            $env->m->emit('call object Moggi.Rt.RT::IoCatch(class Moggi.Rt.IO, object)');
            if ($stmt->dest !== null) {
                $env->m->store($env->localSlot('t' . $stmt->dest));
            } else {
                $env->m->emit('pop');
            }

            return;

        case IR\IoFinally::class:
            /** @var IR\IoFinally $stmt */
            emitOperand($env, $stmt->action);
            $env->m->emit('castclass Moggi.Rt.IO');
            emitOperand($env, $stmt->cleanup);
            $env->m->emit('castclass Moggi.Rt.IO');
            $env->m->emit('call object Moggi.Rt.RT::IoFinally(class Moggi.Rt.IO, class Moggi.Rt.IO)');
            if ($stmt->dest !== null) {
                $env->m->store($env->localSlot('t' . $stmt->dest));
            } else {
                $env->m->emit('pop');
            }

            return;

        case IR\MatchReturn::class:
            /** @var IR\MatchReturn $stmt */
            emitMatchReturn($env, $stmt);

            return;

        case IR\MatchStmt::class:
            /** @var IR\MatchStmt $stmt */
            emitMatchStmt($env, $stmt);

            return;

        case IR\Loop::class:
            /** @var IR\Loop $stmt */
            emitTailLoop($env, $stmt);

            return;

        case IR\TailRecall::class:
            /** @var IR\TailRecall $stmt */
            emitTailRecall($env, $stmt);

            return;

        case IR\DictCall::class:
            /** @var IR\DictCall $stmt */
            $resolved = resolveDictMethod($env, $stmt->evidence, $stmt->method);
            if ($resolved !== null) {
                emitStaticCall($env, $resolved['name'], $stmt->args, $resolved['module']);
                $env->m->store($env->localSlot('t' . $stmt->dest));

                return;
            }
            emitOperand($env, $stmt->evidence);
            $env->m->emit('ldstr ' . ilString($stmt->method));
            pushObjectArray($env, $stmt->args);
            $env->m->emit('call object Moggi.Rt.RT::DictCall(object, string, object[])');
            $env->m->store($env->localSlot('t' . $stmt->dest));

            return;

        default:
            throw new \RuntimeException('DotNet emit: unsupported stmt ' . $stmt::class);
    }
}

/**
 * Whether control never falls out of the end of `$stmt`, so no fall-through
 * `br` must be appended after it.
 */
function dotNetStmtTerminates(IR\Stmt $stmt): bool
{
    if ($stmt instanceof IR\Ret
        || $stmt instanceof IR\MatchReturn
        || $stmt instanceof IR\TailRecall
        || $stmt instanceof IR\IoThrow) {
        return true;
    }
    if ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\IoMatch) {
        $arms = $stmt->arms;
        if ($arms === []) {
            return false;
        }
        foreach ($arms as $arm) {
            if (!dotNetBlockTerminates($arm->body)) {
                return false;
            }
        }

        return true;
    }

    return false;
}

function dotNetBlockTerminates(IR\Block $block): bool
{
    $items = $block->items;

    return $items !== [] && dotNetStmtTerminates($items[array_key_last($items)]);
}

/**
 * `IR\Loop` is the optimizer's self-tail-recursion rewrite: an endless block
 * whose back-edges are `IR\TailRecall`. Emitted as a label plus `br`.
 */
function emitTailLoop(EmitEnv $env, IR\Loop $stmt): void
{
    $head = 'tail_loop_' . $env->freshLabelId();
    $prevHead = $env->tailLoopHead;
    $env->tailLoopHead = $head;

    $env->m->label($head);
    emitBlock($env, $stmt->body);
    // Only a body that can fall out of its end needs the explicit back-edge;
    // after an unconditional `br`/`ret` it would be unreachable code.
    if (!dotNetBlockTerminates($stmt->body)) {
        $env->m->emit('br ' . $head);
    }

    $env->tailLoopHead = $prevHead;
}

/**
 * Assign the recall's arguments to the function's parameter slots and jump back
 * to the loop head.
 *
 * Every argument is evaluated into a fresh local before any argument slot is
 * written, so an argument that mentions a parameter another argument overwrites
 * (e.g. a two-parameter swap) still reads the pre-recall value.
 */
function emitTailRecall(EmitEnv $env, IR\TailRecall $stmt): void
{
    $head = $env->tailLoopHead;
    $slots = $env->tailRecSlots;
    if ($head === null || \count($stmt->args) !== \count($slots)) {
        throw new \RuntimeException('DotNet emit: tail_recall outside a tail loop');
    }

    $values = [];
    foreach ($stmt->args as $arg) {
        emitOperand($env, $arg);
        $slot = $env->freshLocal();
        $env->m->store($slot);
        $values[] = $slot;
    }

    foreach ($slots as $i => $slot) {
        $env->m->load($values[$i]);
        $env->m->storeArg((int) \substr($slot, 4));
    }

    $env->m->emit('br ' . $head);
}

/** @return ?array{name: string, module: ?string} */
function resolveDictMethod(EmitEnv $env, IR\Operand $evidence, string $method): ?array
{
    $evName = null;
    if ($evidence instanceof IR\ExprCall && $evidence->args === []) {
        $evName = $evidence->callee;
    } elseif ($evidence instanceof IR\FnRef) {
        $evName = $evidence->name;
    }
    if ($evName === null) {
        return null;
    }

    $entry = $env->evidenceMaps[$evName] ?? null;
    if ($entry === null) {
        return null;
    }

    // Legacy: evidenceName => methods map
    if (isset($entry[$method]) && \is_string($entry[$method])) {
        return ['name' => $entry[$method], 'module' => null];
    }

    $methods = $entry['methods'] ?? null;
    if (!\is_array($methods) || !isset($methods[$method])) {
        return null;
    }

    return [
        'name' => $methods[$method],
        'module' => \is_string($entry['module'] ?? null) ? $entry['module'] : null,
    ];
}

function emitIoCall(EmitEnv $env, IR\IoCall $stmt): void
{
    if (\is_string($stmt->intrinsic) && $stmt->intrinsic !== '') {
        emitIntrinsic($env, new IR\Intrinsic($stmt->intrinsic, $stmt->args));
        if ($stmt->dest !== null) {
            $env->m->store($env->localSlot('t' . $stmt->dest));
        } else {
            $env->m->emit('pop');
        }

        return;
    }

    $runtime = $stmt->runtime ?? null;
    if (\is_string($runtime) && \str_starts_with($runtime, 'foreign:')) {
        $foreign = $stmt->foreign;
        if (!($foreign instanceof IR\ForeignCall)) {
            throw new \RuntimeException('foreign IO call missing metadata');
        }
        // Eager, and the result is optional (IO () → null).
        $call = new IR\ForeignCall(
            $foreign->backend,
            $foreign->kind,
            $foreign->path,
            $foreign->dispatch,
            $stmt->args,
            $foreign->classPath,
            $foreign->member,
            $foreign->ioWrap,
            $foreign->phpValueBox,
            $foreign->handleBox,
            $foreign->handleUnboxArgs,
            $foreign->nativeSig,
        );
        emitForeign($env, $call);
        if ($stmt->dest !== null) {
            $env->m->store($env->localSlot('t' . $stmt->dest));
        } else {
            $env->m->emit('pop');
        }

        return;
    }

    emitStaticCall($env, $stmt->callee, $stmt->args);
    if ($stmt->dest !== null) {
        $env->m->store($env->localSlot('t' . $stmt->dest));
    } else {
        $env->m->emit('pop');
    }
}

/**
 * Box a delayed IO action: body/result → IO(Fn). Registers a synthetic static
 * IO thunk on the owning type, closing over free locals as positional captures.
 */
function emitIoAssignAction(EmitEnv $env, IR\IoAssignAction $stmt): void
{
    $assigned = [];
    $collectAssigned = static function (IR\Block $block) use (&$assigned, &$collectAssigned): void {
        foreach ($block->items as $item) {
            if ($item instanceof IR\Let) {
                $assigned[$item->name] = true;
            }
            $definedTemp = match ($item::class) {
                IR\Assign::class,
                IR\Binop::class,
                IR\Call::class,
                IR\CallValue::class,
                IR\DictCall::class,
                IR\MatchStmt::class,
                IR\IoAssignAction::class => $item->dest,
                IR\IoRun::class, IR\IoMatch::class, IR\IoCall::class,
                IR\IoThrow::class, IR\IoCatch::class, IR\IoFinally::class => $item->dest,
                default => null,
            };
            if ($definedTemp !== null) {
                $assigned['t' . $definedTemp] = true;
            }
            foreach (stmtNestedBlocks($item) as $nested) {
                $collectAssigned($nested);
            }
        }
    };
    $collectAssigned($stmt->body);

    $captures = [];
    foreach ([...collectLocalsInBlock($stmt->body), ...collectLocalsInOperand($stmt->result)] as $name) {
        if (!isset($assigned[$name])) {
            $captures[$name] = true;
        }
    }
    // A lambda this body builds closes over its own captures, so they are
    // needed here too even though no operand names them.
    foreach (lambdaCaptureNamesInBlock($stmt->body, $env->lambdaMeta) as $capture) {
        if (!isset($assigned[$capture])) {
            $captures[$capture] = true;
        }
    }
    $temps = [];
    walkBlock(
        $stmt->body,
        static function (IR\Stmt $_stmt): void {
        },
        static function (IR\Operand $op) use (&$temps): void {
            if ($op instanceof IR\Temp) {
                $temps[$op->id] = true;
            }
        },
    );
    walkOperand($stmt->result, static function (IR\Operand $op) use (&$temps): void {
        if ($op instanceof IR\Temp) {
            $temps[$op->id] = true;
        }
    });
    foreach (\array_keys($temps) as $tempId) {
        $key = 't' . $tempId;
        if (!isset($assigned[$key])) {
            $captures[$key] = true;
        }
    }
    $captures = \array_keys($captures);

    $spec = $env->ioThunkRegistry?->register($captures, $stmt->body, $stmt->result)
        ?? throw new \RuntimeException('DotNet emit: missing IO thunk registry');

    if ($captures === []) {
        emitTopLevelFnValue($env, $spec['name'], 0);
    } else {
        $arity = \count($captures);
        $env->m->emit("ldc.i4 {$arity}");
        emitTopLevelFnValue($env, $spec['name'], $arity);
        $captureArgs = \array_map(
            static fn (string $capture): IR\Operand => new IR\Local($capture),
            $captures,
        );
        pushObjectArray($env, $captureArgs);
        $env->m->emit('newobj instance void Moggi.Rt.Partial::.ctor(int32, class Moggi.Rt.Fn, object[])');
    }
    $actionSlot = $env->freshLocal();
    $env->m->store($actionSlot);
    $env->m->load($actionSlot);
    $env->m->emit('newobj instance void Moggi.Rt.IO::.ctor(object)');
    $env->m->store($env->localSlot('t' . $stmt->dest));
}

/** @param list<IR\Operand> $args */
function emitStaticCall(EmitEnv $env, string $name, array $args, ?string $ownerModule = null): void
{
    if (emitBuiltinCall($env, $name, $args)) {
        return;
    }
    $callArgs = callArgsForLambda($env, $name, $args);
    // A lifted function is callable at its captures then its parameters, so
    // applying it to fewer is a partial: the adapter a constrained local binding
    // lowers to applies its body lambda before the last argument arrives.
    if (isCapturedFnName($name)) {
        $meta = $env->lambdaMeta[$name] ?? ['captures' => [], 'params' => []];
        $fullArity = count($meta['captures']) + count($meta['params']);
        if (count($callArgs) < $fullArity) {
            emitPartialValue($env, $name, $fullArity, $callArgs);

            return;
        }
    }
    foreach ($callArgs as $arg) {
        emitOperand($env, $arg);
    }
    $target = resolveCallableTarget($env, $name, $ownerModule);
    $params = \implode(', ', \array_fill(0, \count($callArgs), 'object'));
    $env->m->emit("call object {$target['owner']}::{$target['sym']}({$params})");
}

/** @return array{owner: string, sym: string} */
function resolveCallableTarget(EmitEnv $env, string $name, ?string $ownerModule = null): array
{
    $targetOwner = $env->owner;
    $sym = symbolName($name);
    if ($ownerModule !== null && $ownerModule !== '') {
        $targetOwner = moduleTypeName($ownerModule);
    } else {
        $parsed = parseResolvedSymbol($name);
        if ($parsed !== null) {
            $targetOwner = moduleTypeName($parsed['module']);
            $sym = symbolName($parsed['name']);
        } elseif (!isset($env->localCallables[$name]) && isset($env->externalFns[$name])) {
            $extParsed = parseResolvedSymbol($env->externalFns[$name]);
            if ($extParsed !== null) {
                $targetOwner = moduleTypeName($extParsed['module']);
                $sym = symbolName($extParsed['name']);
            }
        }
    }

    return ['owner' => $targetOwner, 'sym' => $sym];
}

function emitTopLevelFnValue(EmitEnv $env, string $name, int $arity, ?string $ownerModule = null): void
{
    emitTopLevelFnValueFromTarget($env, resolveCallableTarget($env, $name, $ownerModule), $arity);
}

/** @param array{owner: string, sym: string} $target */
function emitTopLevelFnValueFromTarget(EmitEnv $env, array $target, int $arity): void
{
    $wrapper = $env->wrapperRegistry?->ensure($target['owner'], $target['sym'], $arity)
        ?? fnWrapperTypeName($target['owner'], $target['sym'], $arity);
    $env->m->emit("ldc.i4 {$arity}");
    $env->m->emit("newobj instance void {$wrapper}::.ctor()");
    $env->m->emit('newobj instance void Moggi.Rt.TopLevelFn::.ctor(int32, class Moggi.Rt.Fn)');
}

/** @param list<IR\Operand> $args */
function emitPartialValue(EmitEnv $env, string $fn, int $arity, array $args): void
{
    $runtimeArity = $env->functionArity[$fn] ?? $arity;
    $allArgs = callArgsForLambda($env, $fn, $args);
    $target = resolveCallableTarget($env, $fn);
    $env->m->emit("ldc.i4 {$runtimeArity}");
    emitTopLevelFnValueFromTarget($env, $target, $runtimeArity);
    pushObjectArray($env, $allArgs);
    $env->m->emit('newobj instance void Moggi.Rt.Partial::.ctor(int32, class Moggi.Rt.Fn, object[])');
}

/** @param list<IR\Operand> $args */
function emitBuiltinCall(EmitEnv $env, string $name, array $args): bool
{
    if (\str_starts_with($name, '__tuple_field')) {
        emitOperand($env, $args[0]);
        $env->m->emit('castclass object[]');
        $env->m->emit('ldc.i4 ' . (int) \substr($name, \strlen('__tuple_field')));
        $env->m->emit('ldelem.ref');

        return true;
    }
    if (\str_starts_with($name, '__tuple')) {
        if ($args === []) {
            $env->m->emit('ldnull');

            return true;
        }
        pushObjectArray($env, $args);

        return true;
    }
    if (\str_starts_with($name, '__field')) {
        emitOperand($env, $args[0]);
        $env->m->emit('castclass Moggi.Rt.Con');
        $env->m->emit('ldfld object[] Moggi.Rt.Con::fields');
        $env->m->emit('ldc.i4 ' . (int) \substr($name, \strlen('__field')));
        $env->m->emit('ldelem.ref');

        return true;
    }

    return false;
}

/**
 * IR after bindLambdaCaptures already includes capture args on Partial/Call.
 * Do not prepend lambdaMeta captures again (mirrors JVM jvmCallArgs).
 *
 * @param list<IR\Operand> $args
 * @return list<IR\Operand>
 */
function callArgsForLambda(EmitEnv $env, string $name, array $args): array
{
    return $args;
}

// ---------------------------------------------------------------------------

function emitIntBinopToStack(EmitEnv $env, string $op, IR\Operand $left, IR\Operand $right): void
{
    if ($op === '==' || $op === '/=') {
        emitOperand($env, $left);
        emitOperand($env, $right);
        // Structural, not `Object.Equals`: a tuple is an `object[]` and an array does not compare
        // its elements.
        $env->m->emit('call bool Moggi.Rt.RT::ValueEq(object, object)');
        if ($op === '/=') {
            invertBool($env);
        }
        $env->m->emit('box bool');

        return;
    }
    if (\in_array($op, ['<', '<=', '>', '>='], true)) {
        emitOperand($env, $left);
        unboxInt($env);
        emitOperand($env, $right);
        unboxInt($env);
        match ($op) {
            '<' => $env->m->emit('clt'),
            '>' => $env->m->emit('cgt'),
            '<=' => (static function (EmitEnv $env): void {
                $env->m->emit('cgt');
                invertBool($env);
            })($env),
            '>=' => (static function (EmitEnv $env): void {
                $env->m->emit('clt');
                invertBool($env);
            })($env),
        };
        $env->m->emit('box bool');

        return;
    }
    emitOperand($env, $left);
    unboxInt($env);
    emitOperand($env, $right);
    unboxInt($env);
    match ($op) {
        '+', 'intAdd#' => $env->m->emit('add'),
        '-', 'intSub#' => $env->m->emit('sub'),
        '*', 'intMul#' => $env->m->emit('mul'),
        // Toward 0 (CIL signed `div`).
        '/', 'intDiv#' => $env->m->emit('div'),
        default => throw new \RuntimeException("DotNet emit: unsupported binop {$op}"),
    };
    boxInt($env);
}

function emitDoubleBinopToStack(EmitEnv $env, string $op, IR\Operand $left, IR\Operand $right): void
{
    emitOperand($env, $left);
    unboxDouble($env);
    emitOperand($env, $right);
    unboxDouble($env);
    match ($op) {
        'doubleAdd#' => $env->m->emit('add'),
        'doubleSub#' => $env->m->emit('sub'),
        'doubleMul#' => $env->m->emit('mul'),
        'doubleDiv#' => $env->m->emit('div'),
        default => throw new \RuntimeException("DotNet emit: unsupported float binop {$op}"),
    };
    boxDouble($env);
}

/** int32 0/1 boolean on the stack → its logical negation (still int32 0/1). */
function invertBool(EmitEnv $env): void
{
    $env->m->emit('ldc.i4.0');
    $env->m->emit('ceq');
}

/**
 * Stack: int 0/1 — 1 iff the two doubles are *not* IEEE-equal.
 *
 * CIL's `ceq` is unordered for NaN, so a NaN operand counts as different and
 * `-0.0` equals `0.0`, both as `base`'s `==##` specifies. `Boolean.valueOf`
 * reads any non-zero int as true, so the flag stays in {0,1}.
 */
function emitDoubleCmpFlag(EmitEnv $env, IR\Operand $left, IR\Operand $right): void
{
    emitOperand($env, $left);
    unboxDouble($env);
    emitOperand($env, $right);
    unboxDouble($env);
    $env->m->emit('ceq');
    invertBool($env);
}

/**
 * 3-way numeric compare → Ordering Con, without evaluating either operand
 * twice (CIL has no `swap`/duplicate-below-top, so both sides are staged
 * through fresh locals first).
 *
 * @param callable(EmitEnv): void $unbox
 */
function emitNumericCompareToOrdering(EmitEnv $env, IR\Operand $left, IR\Operand $right, string $localType, callable $unbox): void
{
    emitOperand($env, $left);
    $unbox($env);
    $l = $env->freshLocal($localType);
    $env->m->store($l);
    emitOperand($env, $right);
    $unbox($env);
    $r = $env->freshLocal($localType);
    $env->m->store($r);
    $env->m->load($l);
    $env->m->load($r);
    $env->m->emit('cgt');
    $env->m->load($l);
    $env->m->load($r);
    $env->m->emit('clt');
    $env->m->emit('sub');
    $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');
}

/** Ordering Con `tag` string compared against a literal, raw int32 0/1 on the stack. */
function emitOrderingTagEqRaw(EmitEnv $env, IR\Operand $ord, string $tag): void
{
    emitOperand($env, $ord);
    $env->m->emit('castclass Moggi.Rt.Con');
    $env->m->emit('ldfld string Moggi.Rt.Con::tag');
    $env->m->emit('ldstr ' . ilString($tag));
    $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
}

function emitOrderingTagEq(EmitEnv $env, IR\Operand $ord, string $tag): void
{
    emitOrderingTagEqRaw($env, $ord, $tag);
    $env->m->emit('box bool');
}

function emitOrderingTagNe(EmitEnv $env, IR\Operand $ord, string $tag): void
{
    emitOrderingTagEqRaw($env, $ord, $tag);
    invertBool($env);
    $env->m->emit('box bool');
}

// ---------------------------------------------------------------------------
// Operands
// ---------------------------------------------------------------------------

function emitOperand(EmitEnv $env, IR\Operand $op): void
{
    if ($op instanceof IR\ConstInt || $op instanceof IR\ConstChar) {
        pushBoxedInt($env, $op->value);

        return;
    }
    if ($op instanceof IR\ConstStr) {
        $env->m->emit('ldstr ' . ilString($op->value));

        return;
    }
    if ($op instanceof IR\ConstDouble) {
        $env->m->emit('ldc.r8 ' . formatIlDouble($op->value));
        boxDouble($env);

        return;
    }
    if ($op instanceof IR\Unit) {
        $env->m->emit('ldnull');

        return;
    }
    if ($op instanceof IR\Local) {
        $slot = $env->locals[$op->name] ?? throw new \RuntimeException("unknown local {$op->name}");
        $env->m->load($slot);

        return;
    }
    if ($op instanceof IR\Temp) {
        $key = 't' . $op->id;
        $slot = $env->locals[$key] ?? throw new \RuntimeException("unknown temp {$key} in {$env->owner}");
        $env->m->load($slot);

        return;
    }
    if ($op instanceof IR\Intrinsic) {
        // Throw sites emit their own sequence point via emitDotNetPushSrcLoc().
        emitIntrinsic($env, $op);

        return;
    }
    if ($op instanceof IR\ListLit) {
        emitListLit($env, $op);

        return;
    }
    if ($op instanceof IR\ExprCall) {
        emitStaticCall($env, $op->callee, $op->args);

        return;
    }
    if ($op instanceof IR\ExprCallValue) {
        emitOperand($env, $op->callee);
        emitRuntimeApply($env, $op->args);

        return;
    }
    if ($op instanceof IR\ExprBinop) {
        emitIntBinopToStack($env, $op->op, $op->left, $op->right);

        return;
    }
    if ($op instanceof IR\ForeignCall) {
        emitForeign($env, $op);

        return;
    }
    if ($op instanceof IR\Partial || $op instanceof IR\ExprPartial) {
        emitPartialValue($env, $op->fn, $op->arity, $op->args);

        return;
    }
    if ($op instanceof IR\DictMethod) {
        $resolved = resolveDictMethod($env, $op->evidence, $op->method);
        if ($resolved !== null) {
            // See JVM DictMethod: arity for cross-module evidence methods is
            // stored under `Module::name` in globalFnArity.
            $arity = $env->functionArity[$resolved['name']] ?? null;
            if ($arity === null && \is_string($resolved['module'] ?? null) && $resolved['module'] !== '') {
                $arity = $env->functionArity[
                    resolvedSymbol($resolved['module'], $resolved['name'])
                ] ?? null;
            }
            if ($arity === null) {
                throw new \RuntimeException("DotNet emit: DictMethod `{$op->method}` resolved to unknown arity");
            }
            emitTopLevelFnValue($env, $resolved['name'], $arity, $resolved['module']);

            return;
        }
        emitOperand($env, $op->evidence);
        $env->m->emit('ldstr ' . ilString($op->method));
        $env->m->emit('call object Moggi.Rt.RT::DictMethod(object, string)');

        return;
    }
    if ($op instanceof IR\FnRef) {
        // `Bool` is wired in (Data.Ord's class defaults never import it), and an
        // imported `Data.Bool` lowers to the host boolean as well.
        if ($op->name === 'True' || $op->name === 'False') {
            $env->m->emit($op->name === 'True' ? 'ldc.i4.1' : 'ldc.i4.0');
            $env->m->emit('box bool');

            return;
        }
        if (isCapturedFnName($op->name)) {
            $meta = $env->lambdaMeta[$op->name] ?? ['captures' => [], 'params' => []];
            $captures = $meta['captures'] ?? [];
            if ($captures !== []) {
                $captureArgs = \array_map(
                    static fn (string $capture): IR\Operand => new IR\Local($capture),
                    $captures,
                );
                $fullArity = \count($captures) + \count($meta['params'] ?? []);
                emitPartialValue($env, $op->name, $fullArity, $captureArgs);

                return;
            }
        }
        $arity = $env->functionArity[$op->name] ?? null;
        if ($arity === null) {
            $parsed = parseResolvedSymbol($op->name);
            if ($parsed !== null && !isLambdaName($parsed['name'])) {
                $arity = $env->functionArity[$parsed['name']] ?? null;
            }
        }
        if ($arity === null) {
            throw new \RuntimeException("DotNet emit: FnRef `{$op->name}` has unknown arity");
        }
        if ($arity === 0) {
            emitStaticCall($env, $op->name, []);

            return;
        }
        emitTopLevelFnValue($env, $op->name, $arity);

        return;
    }

    throw new \RuntimeException('DotNet emit: unsupported operand ' . $op::class);
}

function operandNeverReturns(IR\Operand $op): bool
{
    // ThrowErrorCall / ThrowSomeException are typed as returning object for the
    // verifier; emit sites must still ret/store/pop the dead result.
    return false;
}

function pushBoxedInt(EmitEnv $env, int $v): void
{
    // Moggi Int is signed 64-bit (boxed int64 on the CLR).
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

/**
 * `base ^ exponent` at machine `Int`: the runtime's unboxed square-and-multiply.
 *
 * The loop lives in the runtime so that every backend reaches the same
 * implementation, and so the intrinsic stays a plain call wherever it appears
 * in an expression.
 */
function emitDotNetIntPow(EmitEnv $env, IR\Operand $base, IR\Operand $exponent, ?IR\SrcLoc $loc): void
{
    emitDotNetUnboxedIntPow($env, $base, $exponent, $loc, -1);
    boxInt($env);
}

/**
 * The runtime's masked square-and-multiply, on unboxed operands.
 *
 * `-1` reduces not at all: the machine `Int`'s own multiply already wraps at 64
 * bits. A fixed-width type passes its own mask, and the signed ones are
 * sign-extended back by {@see emitSignExtend} at the call site.
 */
function emitDotNetUnboxedIntPow(
    EmitEnv $env,
    IR\Operand $base,
    IR\Operand $exponent,
    ?IR\SrcLoc $loc,
    int $mask,
): void {
    emitOperand($env, $base);
    unboxInt($env);
    emitOperand($env, $exponent);
    unboxInt($env);
    $env->m->emit('ldc.i8 ' . $mask);
    emitDotNetPushSrcLoc($env, $loc);
    $env->m->emit('call int64 Moggi.Rt.RT::IntPow(int64, int64, int64, object[])');
}

/**
 * One of the fixed-width `^` primops: the masked loop, then the sign extension
 * the signed widths need (the 64-bit ones are already the host width).
 */
function emitDotNetPowFamily(EmitEnv $env, IR\Intrinsic $op): bool
{
    $name = $op->name;
    $mask = powWidthMask($name);
    if ($mask === null) {
        return false;
    }

    emitDotNetUnboxedIntPow($env, $op->args[0], $op->args[1], $op->srcLoc, $mask);
    if (\str_starts_with($name, 'int') && $mask !== -1) {
        emitSignExtend($env, $name);
    }
    boxInt($env);

    return true;
}

/**
 * The reduction mask of a fixed-width `^` primop, or null for any other name.
 *
 * `-1` (every bit) is the 64-bit widths, which reduce not at all.
 */
function powWidthMask(string $name): ?int
{
    return match ($name) {
        'int8Pow#', 'word8Pow#' => 0xff,
        'int16Pow#', 'word16Pow#' => 0xffff,
        'int32Pow#', 'word32Pow#' => 0xffffffff,
        'int64Pow#', 'word64Pow#' => -1,
        default => null,
    };
}

/** `base ^ exponent` at `Double`: the runtime's unboxed loop on host doubles. */
function emitDotNetDoublePow(EmitEnv $env, IR\Operand $base, IR\Operand $exponent, ?IR\SrcLoc $loc): void
{
    emitOperand($env, $base);
    unboxDouble($env);
    emitOperand($env, $exponent);
    unboxInt($env);
    emitDotNetPushSrcLoc($env, $loc);
    $env->m->emit('call float64 Moggi.Rt.RT::DoublePow(float64, int64, object[])');
    boxDouble($env);
}

/** Sign-extend-truncate an i64 on the stack to the width named by an intN_* op. */
function emitSignExtend(EmitEnv $env, string $opName): void
{
    if (str_starts_with($opName, 'int8')) {
        $env->m->emit('conv.i1');
    } elseif (str_starts_with($opName, 'int16')) {
        $env->m->emit('conv.i2');
    } else {
        $env->m->emit('conv.i4');
    }
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

function formatIlDouble(float $v): string
{
    if (\is_nan($v) || \is_infinite($v)) {
        // Rare in generated code; encode as raw IEEE-754 bytes (ilasm accepts
        // a parenthesized hex byte sequence for float64 literals).
        $bytes = \unpack('C8', \pack('e', $v));
        $hex = \implode(' ', \array_map(static fn (int $b): string => \sprintf('%02X', $b), $bytes));

        return "({$hex})";
    }
    $s = \sprintf('%.17G', $v);
    if (!\str_contains($s, '.') && !\str_contains($s, 'E') && !\str_contains($s, 'e')) {
        $s .= '.0';
    }

    return $s;
}

function emitListLit(EmitEnv $env, IR\ListLit $op): void
{
    // Built right-to-left via an accumulator local (CIL has no `swap`).
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

/**
 * Emit `RT.Apply` for the callee already on the stack followed by `$args`.
 *
 * A single argument goes through `RT.Apply1`, which takes the scalar directly
 * and so avoids both the caller-side `object[]` and, inside a partial
 * application, the second array allocation. `f x y z` emits one apply per
 * argument, so this is the shape that dominates curried code.
 */
function emitRuntimeApply(EmitEnv $env, array $args): void
{
    if (\count($args) === 1) {
        emitOperand($env, $args[0]);
        $env->m->emit('call object Moggi.Rt.RT::Apply1(object, object)');

        return;
    }

    pushObjectArray($env, $args);
    $env->m->emit('call object Moggi.Rt.RT::Apply(object, object[])');
}

// ---------------------------------------------------------------------------

function qualifyType(string $dotted): string
{
    // Same-assembly types must not carry an [Assembly] qualifier.
    // Same-assembly language ABI (Moggi.Rt.*) — never [Assembly]-qualify.
    if (\str_starts_with($dotted, 'Moggi.')) {
        return $dotted;
    }

    return '[' . bclAssemblyFor($dotted) . ']' . $dotted;
}

function dotNetForeignClassIsValueType(string $classPath): bool
{
    return dotNetHostIsValueType($classPath);
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
        if (isDotNetConstProperty($resolved['class'], $resolved['member'])) {
            $env->m->emit("call {$desc} {$typeRef}::get_{$resolved['member']}()");
        } else {
            $env->m->emit("ldsfld {$desc} {$typeRef}::{$resolved['member']}");
        }
        boxForeignReturn($env, $desc);
        emitForeignIoWrap($env, $op->ioWrap);

        return;
    }

    $sig = parseClrSig($desc);
    $args = $op->args;

    if ($resolved['dispatch'] === 'intrinsic') {
        emitForeignIntrinsic($env, $resolved['member'], $typeRef, $sig, $args, $op->path);

        return;
    }

    $argOffset = 0;
    $valueTypeReceiver = $resolved['dispatch'] === 'instance'
        && dotNetForeignClassIsValueType($resolved['class']);

    if ($resolved['dispatch'] === 'instance') {
        if ($args === []) {
            throw new \RuntimeException("DotNet instance foreign `{$op->path}` needs a receiver");
        }
        emitOperand($env, $args[0]);
        if ($valueTypeReceiver) {
            // Valuetype instance `this` is a managed pointer (&T), not T.
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
    foreach ($paramArgs as $i => $arg) {
        emitOperand($env, $arg);
        unboxForeignArg($env, $sig['params'][$i]);
    }
    for ($i = $nProvided; $i < $nNeeded; $i++) {
        emitDefaultForeignArg($env, $sig['params'][$i]);
    }

    $paramList = \implode(', ', $sig['params']);
    match ($resolved['dispatch']) {
        'static', 'global' => $env->m->emit("call {$sig['ret']} {$typeRef}::{$resolved['member']}({$paramList})"),
        'instance' => $valueTypeReceiver
            ? $env->m->emit("call instance {$sig['ret']} {$typeRef}::{$resolved['member']}({$paramList})")
            : $env->m->emit("callvirt instance {$sig['ret']} {$typeRef}::{$resolved['member']}({$paramList})"),
        'constructor' => $env->m->emit("newobj instance void {$typeRef}::.ctor({$paramList})"),
        default => throw new \RuntimeException("DotNet foreign: unsupported dispatch {$resolved['dispatch']}"),
    };

    if ($resolved['dispatch'] === 'constructor') {
        // newobj of a valuetype leaves an unboxed value; box into Moggi's object ABI.
        if (dotNetForeignClassIsValueType($resolved['class'])) {
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
        IR\IoWrap::MaybeString, IR\IoWrap::FgetsLine => emitForeignMaybeStringWrap($env),
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
        // Boxed valuetype → object is already a ref; unbox.any then re-box if
        // casting between valuetypes is not supported — only ref casts here.
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
            // object/boxed → boxed valuetype.
            $env->m->emit('unbox.any ' . $ret);
            $env->m->emit('box ' . $ret);

            return;
        }
        throw new \RuntimeException("DotNet foreign `{$path}`: unsupported __cast target {$ret}");
    }

    if ($member === '__default') {
        $ret = $sig['ret'];
        if (!\str_starts_with($ret, 'valuetype ')) {
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

/** Push a default argument for BCL optional trailing parameters (initobj / ldnull). */
function emitDefaultForeignArg(EmitEnv $env, string $kind): void
{
    if (\str_starts_with($kind, 'valuetype ')) {
        $slot = $env->freshLocal($kind);
        $env->m->emit('ldloca ' . $slot);
        $env->m->emit('initobj ' . $kind);
        $env->m->emit('ldloc ' . $slot);

        return;
    }
    if ($kind === 'object' || \str_starts_with($kind, 'class ')) {
        $env->m->emit('ldnull');

        return;
    }
    if (\str_ends_with($kind, '[]')) {
        $env->m->emit('ldnull');

        return;
    }

    throw new \RuntimeException("DotNet foreign: cannot synthesize default for parameter type {$kind}");
}

function unboxForeignArg(EmitEnv $env, string $kind): void
{
    match ($kind) {
        // JIT-visible int32: Moggi Int/Char are boxed int64 — narrow via RT.
        'int32' => (static function (EmitEnv $env): void {
            unboxInt($env);
            $env->m->emit('conv.i4');
        })($env),
        // Fixed-width Int8/Int16: narrow through int64.
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

// ---------------------------------------------------------------------------
// Intrinsics (full allowlist for platform_* and scalar ops)
// ---------------------------------------------------------------------------

function emitDotNetBitReverse(EmitEnv $env, IR\Operand $arg, int $bits, int $finalMask): void
{
    emitOperand($env, $arg);
    $env->m->emit('call int64 Moggi.Rt.RT::UnboxInt(object)');
    $x = $env->freshLocal('int64');
    $env->m->store($x);
    $r = $env->freshLocal('int64');
    $env->m->emit('ldc.i8 0');
    $env->m->store($r);
    $i = $env->freshLocal('int32');
    $env->m->emit('ldc.i4.0');
    $env->m->store($i);
    $id = $env->freshLabelId();
    $loop = 'bitrev_loop_' . $id;
    $cond = 'bitrev_cond_' . $id;
    $env->m->emit('br ' . $cond);
    $env->m->label($loop);
    $env->m->load($r);
    $env->m->emit('ldc.i4.1');
    $env->m->emit('shl');
    $env->m->load($x);
    $env->m->emit('ldc.i8 1');
    $env->m->emit('and');
    $env->m->emit('or');
    $env->m->store($r);
    $env->m->load($x);
    $env->m->emit('ldc.i4.1');
    $env->m->emit('shr.un');
    $env->m->store($x);
    $env->m->load($i);
    $env->m->emit('ldc.i4.1');
    $env->m->emit('add');
    $env->m->store($i);
    $env->m->label($cond);
    $env->m->load($i);
    $env->m->emit('ldc.i4 ' . $bits);
    $env->m->emit('blt ' . $loop);
    $env->m->load($r);
    $env->m->emit('ldc.i8 ' . $finalMask);
    $env->m->emit('and');
    $env->m->emit('call object Moggi.Rt.RT::BoxInt(int64)');
}

function emitIntrinsic(EmitEnv $env, IR\Intrinsic $op): void
{
    $args = $op->args;
    switch ($op->name) {
        case 'intAdd#':
        case 'intSub#':
        case 'intMul#':
        case 'intDiv#':
            emitIntBinopToStack($env, $op->name, $args[0], $args[1]);

            return;
        case 'intPow#':
            emitDotNetIntPow($env, $args[0], $args[1], $op->srcLoc);

            return;
        case 'doublePow#':
            emitDotNetDoublePow($env, $args[0], $args[1], $op->srcLoc);

            return;
        case 'doubleAdd#':
        case 'doubleSub#':
        case 'doubleMul#':
        case 'doubleDiv#':
            emitDoubleBinopToStack($env, $op->name, $args[0], $args[1]);

            return;
        case 'doubleNegate#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->m->emit('neg');
            boxDouble($env);

            return;
        case 'doubleEq#':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            invertBool($env); // 1 - flag: the ints compare equal exactly when the doubles do
            $env->m->emit('box bool');

            return;
        case 'doubleNe#':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            $env->m->emit('box bool');

            return;
        case 'doubleCompare#':
            // `ceq` is false for NaN and true for (-0.0, 0.0), so a NaN neither
            // equals nor is less than anything: it lands on GT, like `base`.
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $l = $env->freshLocal('float64');
            $env->m->store($l);
            emitOperand($env, $args[1]);
            unboxDouble($env);
            $r = $env->freshLocal('float64');
            $env->m->store($r);
            $env->m->emit('ldc.i4.1');
            $env->m->load($l);
            $env->m->load($r);
            $env->m->emit('clt');
            $env->m->emit('ldc.i4.2');
            $env->m->emit('mul');
            $env->m->emit('sub');
            $env->m->load($l);
            $env->m->load($r);
            $env->m->emit('ceq');
            $env->m->emit('sub'); // 1 - 2*less - equal
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');

            return;
        case 'doubleAbs#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->m->emit('call float64 [System.Runtime]System.Math::Abs(float64)');
            boxDouble($env);

            return;
        case 'doubleSignum#':
            // `Math.Sign` throws on NaN and flattens (-0.0) to 0.0; only the two
            // strict orderings select ±1, everything else is its own signum.
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $x = $env->freshLocal('float64');
            $env->m->store($x);
            $id = $env->freshLabelId();
            $positive = 'double_signum_pos_' . $id;
            $negative = 'double_signum_neg_' . $id;
            $done = 'double_signum_done_' . $id;
            $env->m->load($x);
            $env->m->emit('ldc.r8 0.0');
            $env->m->emit('bgt ' . $positive);
            $env->m->load($x);
            $env->m->emit('ldc.r8 0.0');
            $env->m->emit('blt ' . $negative);
            $env->m->load($x);
            $env->m->emit('br ' . $done);
            $env->m->label($positive);
            $env->m->emit('ldc.r8 1.0');
            $env->m->emit('br ' . $done);
            $env->m->label($negative);
            $env->m->emit('ldc.r8 -1.0');
            $env->m->label($done);
            boxDouble($env);

            return;
        case 'intNegate#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('neg');
            boxInt($env);

            return;
        case 'intAnd#':
        case 'intOr#':
        case 'intXor#':
        case 'intShiftL#':
        case 'intShiftRA#':
        case 'intShiftRL#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            if ($op->name === 'intAnd#') {
                $env->m->emit('and');
            } elseif ($op->name === 'intOr#') {
                $env->m->emit('or');
            } elseif ($op->name === 'intXor#') {
                $env->m->emit('xor');
            } else {
                $env->m->emit('conv.i4'); // shift count is in [0,63]
                $env->m->emit([
                    'intShiftL#' => 'shl',
                    'intShiftRA#' => 'shr',
                    'intShiftRL#' => 'shr.un',
                ][$op->name]);
            }
            boxInt($env);

            return;
        case 'intNot#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('not');
            boxInt($env);

            return;
        case 'intPopCnt#':
        case 'intClz#':
        case 'intCtz#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int32 Moggi.Rt.Word64::' . [
                'intPopCnt#' => 'BitCount',
                'intClz#' => 'CountLeadingZeros',
                'intCtz#' => 'CountTrailingZeros',
            ][$op->name] . '(int64)');
            $env->m->emit('conv.i8');
            boxInt($env);

            return;
        case 'intEq#':
        case 'charEq#':
        case 'word8Eq#':
        case 'word16Eq#':
        case 'word32Eq#':
        case 'int8Eq#':
        case 'int16Eq#':
        case 'int32Eq#':
        case 'int64Eq#':
        case 'boolEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            $env->m->emit('box bool');

            return;
        case 'intNe#':
        case 'charNe#':
        case 'word8Ne#':
        case 'word16Ne#':
        case 'word32Ne#':
        case 'int8Ne#':
        case 'int16Ne#':
        case 'int32Ne#':
        case 'int64Ne#':
        case 'boolNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return;
        case 'intCompare#':
        case 'charCompare#':
        case 'word8Compare#':
        case 'word16Compare#':
        case 'word32Compare#':
        case 'int8Compare#':
        case 'int16Compare#':
        case 'int32Compare#':
        case 'int64Compare#':
            emitNumericCompareToOrdering($env, $args[0], $args[1], 'int64', static function (EmitEnv $env): void {
                unboxInt($env);
            });

            return;
        case 'boolCompare#':
            emitNumericCompareToOrdering($env, $args[0], $args[1], 'bool', static function (EmitEnv $env): void {
                $env->m->emit('unbox.any bool');
            });

            return;
        case 'boolAnd#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any bool');
            emitOperand($env, $args[1]);
            $env->m->emit('unbox.any bool');
            $env->m->emit('and');
            $env->m->emit('box bool');

            return;
        case 'boolOr#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any bool');
            emitOperand($env, $args[1]);
            $env->m->emit('unbox.any bool');
            $env->m->emit('or');
            $env->m->emit('box bool');

            return;
        case 'boolNot#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any bool');
            invertBool($env);
            $env->m->emit('box bool');

            return;
        case 'stringCompare#':
        case 'bytesCompare#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass string');
            $env->m->emit('callvirt instance int32 [System.Runtime]System.String::CompareTo(string)');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');

            return;
        case 'stringNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return;
        case 'orderingIsLt#':
            emitOrderingTagEq($env, $args[0], 'LT');

            return;
        case 'orderingIsGt#':
            emitOrderingTagEq($env, $args[0], 'GT');

            return;
        case 'orderingIsLte#':
            emitOrderingTagNe($env, $args[0], 'GT');

            return;
        case 'orderingIsGte#':
            emitOrderingTagNe($env, $args[0], 'LT');

            return;
        case 'orderingEq#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld string Moggi.Rt.Con::tag');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld string Moggi.Rt.Con::tag');
            $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
            $env->m->emit('box bool');

            return;
        case 'orderingNe#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld string Moggi.Rt.Con::tag');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld string Moggi.Rt.Con::tag');
            $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
            invertBool($env);
            $env->m->emit('box bool');

            return;
        case 'orderingCompare#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('call int32 Moggi.Rt.RT::OrderingToInt(class Moggi.Rt.Con)');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('call int32 Moggi.Rt.RT::OrderingToInt(class Moggi.Rt.Con)');
            $env->m->emit('sub');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');

            return;
        case 'intAbs#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            // Not `Math.Abs`: that throws on `minBound`, whose own absolute
            // value is itself (all arithmetic is modulo 2^n).
            $env->m->emit('call int64 Moggi.Rt.RT::IntAbs(int64)');
            boxInt($env);

            return;
        case 'intSignum#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int32 [System.Runtime]System.Math::Sign(int64)');
            $env->m->emit('conv.i8');
            boxInt($env);

            return;
        case 'intFromInteger#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            // The low 64 bits, like the JVM's `longValue`: `Int` and `Word64`
            // share the pattern, so this is the one wrapping conversion.
            $env->m->emit('call int64 Moggi.Rt.Word64::FromBigInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)');
            boxInt($env);

            return;
        case 'intToInteger#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::op_Implicit(int64)');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');

            return;
        case 'integerFromDigits#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger [System.Runtime.Numerics]System.Numerics.BigInteger::Parse(string)');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');

            return;
        case 'doubleFromInteger#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            $env->m->emit('call float64 [System.Runtime.Numerics]System.Numerics.BigInteger::op_Explicit(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)');
            boxDouble($env);

            return;
        case 'ioPure#':
        case 'ioBind#':
            throw new \RuntimeException(
                "intrinsic `{$op->name}` must be erased by strict IO normalization before DotNet emit",
            );
        case 'charToInt#':
        case 'intToChar#':
        case 'word8ToInt#':
        case 'word16ToInt#':
        case 'word32ToInt#':
        case 'word64ToInt#':
        case 'word64FromInt#':
        case 'int8ToInt#':
        case 'int16ToInt#':
        case 'int32ToInt#':
        case 'int64ToInt#':
        case 'int64FromInt#':
        case 'bytesFromString#':
        case 'bytesToString#':
            emitOperand($env, $args[0]);

            return;
        case 'word8FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            boxInt($env);

            return;
            // Signed fixed-width ints: i64 host rep, sign-extend-truncate after
            // each op (conv.i1/i2/i4 + conv.i8) so values stay normalized.
        case 'int8FromInt#':
        case 'int16FromInt#':
        case 'int32FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitSignExtend($env, $op->name);
            boxInt($env);

            return;
        case 'int8Add#':
        case 'int8Sub#':
        case 'int8Mul#':
        case 'int16Add#':
        case 'int16Sub#':
        case 'int16Mul#':
        case 'int32Add#':
        case 'int32Sub#':
        case 'int32Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit(match ($op->name) {
                'int8Add#', 'int16Add#', 'int32Add#' => 'add',
                'int8Sub#', 'int16Sub#', 'int32Sub#' => 'sub',
                'int8Mul#', 'int16Mul#', 'int32Mul#' => 'mul',
                default => throw new \RuntimeException("DotNet emit: unsupported fixed-width int op {$op->name}"),
            });
            emitSignExtend($env, $op->name);
            boxInt($env);

            return;
        case 'int64Add#':
        case 'int64Sub#':
        case 'int64Mul#':
            emitIntBinopToStack($env, 'int' . substr($op->name, 5), $args[0], $args[1]);

            return;
        case 'doubleToInt#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->m->emit('conv.i8');
            boxInt($env);

            return;
        case 'word8Add#':
        case 'word8Sub#':
        case 'word8Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit(match ($op->name) {
                'word8Add#' => 'add',
                'word8Sub#' => 'sub',
                default => 'mul',
            });
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            boxInt($env);

            return;
        case 'word16FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('ldc.i8 65535');
            $env->m->emit('and');
            boxInt($env);

            return;
        case 'word16Add#':
        case 'word16Sub#':
        case 'word16Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit(match ($op->name) {
                'word16Add#' => 'add',
                'word16Sub#' => 'sub',
                default => 'mul',
            });
            $env->m->emit('ldc.i8 65535');
            $env->m->emit('and');
            boxInt($env);

            return;
        case 'word32FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('ldc.i8 4294967295');
            $env->m->emit('and');
            boxInt($env);

            return;
        case 'word32Add#':
        case 'word32Sub#':
        case 'word32Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit(match ($op->name) {
                'word32Add#' => 'add',
                'word32Sub#' => 'sub',
                default => 'mul',
            });
            $env->m->emit('ldc.i8 4294967295');
            $env->m->emit('and');
            boxInt($env);

            return;
        case 'word64FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('ldc.i8 18446744073709551615');
            $env->m->emit('and');
            boxInt($env);

            return;
            // Unsigned 64-bit operations via BigInteger helper methods.
        case 'word64Eq#':
        case 'word64Ne#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            if ($op->name === 'word64Ne#') {
                invertBool($env);
            }
            $env->m->emit('box bool');
            return;
        case 'word64Compare#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit('call int32 Moggi.Rt.Word64::Compare(int64, int64)');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');
            return;
        case 'word64Add#':
        case 'word64Sub#':
        case 'word64Mul#':
        case 'word64Quot#':
        case 'word64Rem#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $methodName = match ($op->name) {
                'word64Add#' => 'Add',
                'word64Sub#' => 'Subtract',
                'word64Mul#' => 'Multiply',
                'word64Quot#' => 'Divide',
                'word64Rem#' => 'Remainder',
                default => throw new \RuntimeException("Unsupported word64 op: {$op->name}"),
            };
            $env->m->emit('call int64 Moggi.Rt.Word64::' . $methodName . '(int64, int64)');
            boxInt($env);
            return;
        case 'word64ToInteger#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger Moggi.Rt.Word64::ToBigInteger(int64)');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            return;
        case 'word64FromInteger#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            $env->m->emit('call int64 Moggi.Rt.Word64::FromBigInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger)');
            boxInt($env);
            return;
        case 'word64Show#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call string Moggi.Rt.Word64::ToString(int64)');
            return;

        case 'wordFromInt#':
        case 'wordToInt#':
            emitOperand($env, $args[0]);
            return;
        case 'wordEq#':
        case 'wordNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            if ($op->name === 'wordNe#') {
                invertBool($env);
            }
            $env->m->emit('box bool');
            return;
        case 'wordCompare#':
            // `Word` is the unsigned 64-bit type, so its comparison is unsigned.
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->m->emit('call int32 Moggi.Rt.Word64::Compare(int64, int64)');
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::OrderingFromInt(int32)');
            return;
        case 'byteSwap16#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $x = $env->freshLocal('int64');
            $env->m->store($x);
            $env->m->load($x);
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('ldc.i4.s 8');
            $env->m->emit('shl');
            $env->m->load($x);
            $env->m->emit('ldc.i4.s 8');
            $env->m->emit('shr.un');
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('or');
            $env->m->emit('ldc.i8 65535');
            $env->m->emit('and');
            boxInt($env);
            return;
        case 'byteSwap32#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $x = $env->freshLocal('int64');
            $env->m->store($x);
            $env->m->load($x);
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('ldc.i4.s 24');
            $env->m->emit('shl');
            $env->m->load($x);
            $env->m->emit('ldc.i4.s 8');
            $env->m->emit('shr.un');
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('ldc.i4.s 16');
            $env->m->emit('shl');
            $env->m->emit('or');
            $env->m->load($x);
            $env->m->emit('ldc.i4.s 16');
            $env->m->emit('shr.un');
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('ldc.i4.s 8');
            $env->m->emit('shl');
            $env->m->emit('or');
            $env->m->load($x);
            $env->m->emit('ldc.i4.s 24');
            $env->m->emit('shr.un');
            $env->m->emit('ldc.i8 255');
            $env->m->emit('and');
            $env->m->emit('or');
            $env->m->emit('ldc.i8 4294967295');
            $env->m->emit('and');
            boxInt($env);
            return;
        case 'byteSwap64#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('call int64 Moggi.Rt.Word64::ByteSwap(int64)');
            boxInt($env);
            return;
        case 'bitReverse8#':
            emitDotNetBitReverse($env, $args[0], 8, 255);
            return;
        case 'bitReverse16#':
            emitDotNetBitReverse($env, $args[0], 16, 65535);
            return;
        case 'bitReverse32#':
            emitDotNetBitReverse($env, $args[0], 32, 4294967295);
            return;
        case 'bitReverse64#':
            emitDotNetBitReverse($env, $args[0], 64, -1);
            return;
        case 'listCons#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.MList Moggi.Rt.RT::Cons(object, object)');

            return;
        case 'listHead#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.MList');
            $env->m->emit('ldfld object Moggi.Rt.MList::head');

            return;
        case 'listTail#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.MList');
            $env->m->emit('ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail');

            return;
        case 'listAppend#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.MList');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.MList');
            $env->m->emit('call class Moggi.Rt.MList Moggi.Rt.RT::ListAppend(class Moggi.Rt.MList, class Moggi.Rt.MList)');

            return;
        case 'listEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool Moggi.Rt.RT::ListEq(object, object)');
            $env->m->emit('box bool');

            return;
        case 'listNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool Moggi.Rt.RT::ListEq(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return;
        case 'listCompare#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::ListCompare(object, object)');

            return;
        case 'maybeEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool Moggi.Rt.RT::MaybeEq(object, object)');
            $env->m->emit('box bool');

            return;
        case 'maybeNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool Moggi.Rt.RT::MaybeEq(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return;
        case 'maybeCompare#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::MaybeCompare(object, object)');

            return;
        case 'stringAppend#':
        case 'bytesAppend#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass string');
            $env->m->emit('call string [System.Runtime]System.String::Concat(string, string)');

            return;
        case 'stringCons#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->m->emit('conv.u2');
            $env->m->emit('call string [System.Runtime]System.Char::ConvertFromUtf32(int32)');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass string');
            $env->m->emit('call string [System.Runtime]System.String::Concat(string, string)');

            return;

        case 'platformArgv#':
            $env->m->emit('call class Moggi.Rt.MList Moggi.Rt.Platform::Argv()');

            return;
        case 'stringEq#':
        case 'bytesEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            $env->m->emit('box bool');

            return;
        case 'bytesNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call bool [System.Runtime]System.Object::Equals(object, object)');
            invertBool($env);
            $env->m->emit('box bool');

            return;
            // Same representation as Integer#; its only constructor rejects a
            // negative value, so a Natural can never be negative.
        case 'naturalToInteger#':
            emitOperand($env, $args[0]);
            return;
        case 'integerToNatural#':
            emitOperand($env, $args[0]);
            $env->m->emit('unbox.any valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            emitDotNetPushSrcLoc($env, $op->srcLoc);
            $env->m->emit('call valuetype [System.Runtime.Numerics]System.Numerics.BigInteger Moggi.Rt.RT::NaturalFromInteger(valuetype [System.Runtime.Numerics]System.Numerics.BigInteger, object[])');
            $env->m->emit('box valuetype [System.Runtime.Numerics]System.Numerics.BigInteger');
            return;
        case 'error#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitDotNetPushSrcLoc($env, $op->srcLoc);
            $env->m->emit('call object Moggi.Rt.RT::ThrowErrorCall(string, object[])');

            return;
        case 'fix#':
            emitOperand($env, $args[0]);
            $env->m->emit('call object Moggi.Rt.RT::Fix(object)');

            return;
        case 'exceptionWrap#':
            // The rendered text travels with the exception (tag → display →
            // payload), so a rethrow can print it without a dictionary.
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[2]);
            $env->m->emit('call object[] Moggi.Rt.RT::ExceptionWrap(string, string, object)');

            return;
        case 'exceptionUnwrap#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass string');
            emitOperand($env, $args[1]);
            $env->m->emit('call object Moggi.Rt.RT::ExceptionUnwrap(string, object)');

            return;
        case 'exceptionThrow#':
            emitOperand($env, $args[0]);
            emitDotNetPushSrcLoc($env, $op->srcLoc);
            $env->m->emit('call object Moggi.Rt.RT::ThrowSomeException(object, object[])');

            return;
        case 'exceptionDisplay#':
            emitOperand($env, $args[0]);
            $env->m->emit('call string Moggi.Rt.RT::ExceptionDisplay(object)');

            return;
        case 'exceptionThrowIo#':
        case 'exceptionCatch#':
        case 'exceptionFinally#':
            throw new \RuntimeException(
                "DotNet emit: intrinsic `{$op->name}` must be lowered to IO exception IR",
            );
        default:
            if (emitDotNetPowFamily($env, $op)) {
                return;
            }
            throw new \RuntimeException("DotNet emit: unsupported intrinsic {$op->name}");
    }
}

// ---------------------------------------------------------------------------
// Match / patterns
// ---------------------------------------------------------------------------

function emitMatchReturn(EmitEnv $env, IR\MatchReturn $stmt): void
{
    emitMatchCommon($env, $stmt->scrutinee, $stmt->arms, null, true);
}

function emitMatchStmt(EmitEnv $env, IR\MatchStmt $stmt): void
{
    $prevDest = $env->matchYieldDest;
    $prevJoin = $env->matchJoinLabel;
    $env->matchYieldDest = $stmt->dest;
    emitMatchCommon($env, $stmt->scrutinee, $stmt->arms, $stmt->dest, false);
    $env->matchYieldDest = $prevDest;
    $env->matchJoinLabel = $prevJoin;
}

function emitIoMatch(EmitEnv $env, IR\IoMatch $stmt): void
{
    $prevDest = $env->matchYieldDest;
    $prevJoin = $env->matchJoinLabel;
    $env->matchYieldDest = $stmt->dest;
    emitMatchCommon($env, $stmt->scrutinee, $stmt->arms, $stmt->dest, false);
    $env->matchYieldDest = $prevDest;
    $env->matchJoinLabel = $prevJoin;
}

/** @param list<IR\MatchArm> $arms */
function emitMatchCommon(EmitEnv $env, IR\Operand $scrutinee, array $arms, ?int $dest, bool $returnNullOnNoDest): void
{
    emitOperand($env, $scrutinee);
    $scrut = $env->freshLocal();
    $env->m->store($scrut);
    $end = 'match_end_' . $scrut;
    // Always emit the join label. MatchReturn publishes `$end` as
    // matchJoinLabel for nested MatchStmt yields; skipping the label when
    // every arm ends with Ret leaves `br match_end_*` unresolved (ilasm).
    $needsEndLabel = true;
    if ($dest !== null) {
        $env->localSlot('t' . $dest);
    }
    $prevJoin = $env->matchJoinLabel;
    $env->matchJoinLabel = $end;

    $armId = 0;
    foreach ($arms as $arm) {
        $savedLocals = $env->locals;
        $fail = 'match_fail_' . $scrut . '_' . $armId;
        ++$armId;
        emitPatternTest($env, $arm->pattern, $scrut, $fail);
        // A guard runs after the pattern bound its variables and tries the next
        // arm through the same `$fail` a failed pattern leaves through.
        foreach ($arm->guards as $guard) {
            emitBlock($env, $guard->prep);
            emitOperand($env, $guard->cond);
            $env->m->emit('unbox.any bool');
            $env->m->emit('brfalse ' . $fail);
        }
        $endsWithRet = dotNetBlockTerminates($arm->body);
        emitBlock($env, $arm->body);
        if (!$endsWithRet) {
            $env->m->emit('br ' . $end);
        }
        $env->m->label($fail);
        $env->locals = $savedLocals;
    }
    $env->m->emit('ldstr ' . ilString('non-exhaustive match'));
    $env->m->emit('newobj instance void [System.Runtime]System.Exception::.ctor(string)');
    $env->m->emit('throw');

    $env->matchJoinLabel = $prevJoin;
    if (!$needsEndLabel) {
        return;
    }
    $env->m->label($end);
    if ($dest !== null) {
        return;
    }
    if (!$returnNullOnNoDest) {
        return;
    }
    $env->m->emit('ldnull');
    emitDotNetFunctionReturn($env);
}

function emitDotNetPushSrcLoc(EmitEnv $env, ?IR\SrcLoc $loc): void
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

function emitDotNetFunctionReturn(EmitEnv $env): void
{
    $env->m->emit('ret');
}

function emitPatternTest(EmitEnv $env, IR\Pattern $pat, string $scrut, string $fail): void
{
    if ($pat instanceof IR\PatWild) {
        return;
    }
    if ($pat instanceof IR\PatVar) {
        $env->locals[$pat->name] = $scrut;

        return;
    }
    if ($pat instanceof IR\PatNil) {
        $env->m->load($scrut);
        $env->m->emit('brtrue ' . $fail);

        return;
    }
    if ($pat instanceof IR\PatLit) {
        // IR\PatLit carries int|string (e.g. `lines "" = …`); emit a distinct
        // CIL comparison per representation.
        if (\is_string($pat->value)) {
            $env->m->load($scrut);
            $env->m->emit('castclass string');
            $env->m->emit('ldstr ' . ilString($pat->value));
            $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
            $env->m->emit('brfalse ' . $fail);

            return;
        }
        $env->m->load($scrut);
        unboxInt($env);
        $env->m->emit('ldc.i8 ' . (int) $pat->value);
        $env->m->emit('ceq');
        $env->m->emit('brfalse ' . $fail);

        return;
    }
    if ($pat instanceof IR\PatChar) {
        $env->m->load($scrut);
        unboxInt($env);
        $env->m->emit('ldc.i8 ' . $pat->value);
        $env->m->emit('ceq');
        $env->m->emit('brfalse ' . $fail);

        return;
    }
    if ($pat instanceof IR\PatCon) {
        // Boolean may be a boxed bool; ADTs are Moggi.Rt.Con.
        if ($pat->name === 'True' || $pat->name === 'False') {
            $env->m->load($scrut);
            $env->m->emit('unbox.any bool');
            if ($pat->name === 'True') {
                $env->m->emit('brfalse ' . $fail);
            } else {
                $env->m->emit('brtrue ' . $fail);
            }

            return;
        }
        if (isset($env->newtypeConstructors[$pat->name])) {
            foreach ($pat->args as $sub) {
                if ($sub instanceof IR\PatWild) {
                    continue;
                }
                if ($sub instanceof IR\PatVar) {
                    $env->locals[$sub->name] = $scrut;
                } else {
                    emitPatternTest($env, $sub, $scrut, $fail);
                }
            }

            return;
        }
        $env->m->load($scrut);
        $env->m->emit('castclass Moggi.Rt.Con');
        $env->m->emit('ldfld string Moggi.Rt.Con::tag');
        $env->m->emit('ldstr ' . ilString($pat->name));
        $env->m->emit('call bool [System.Runtime]System.String::Equals(string, string)');
        $env->m->emit('brfalse ' . $fail);
        foreach ($pat->args as $i => $sub) {
            if ($sub instanceof IR\PatWild) {
                continue;
            }
            $env->m->load($scrut);
            $env->m->emit('castclass Moggi.Rt.Con');
            $env->m->emit('ldfld object[] Moggi.Rt.Con::fields');
            $env->m->emit("ldc.i4 {$i}");
            $env->m->emit('ldelem.ref');
            if ($sub instanceof IR\PatVar) {
                $slot = $env->freshLocal();
                $env->m->store($slot);
                $env->locals[$sub->name] = $slot;
            } else {
                $nested = $env->freshLocal();
                $env->m->store($nested);
                emitPatternTest($env, $sub, $nested, $fail);
            }
        }

        return;
    }
    if ($pat instanceof IR\PatCons) {
        $env->m->load($scrut);
        $env->m->emit('brfalse ' . $fail);
        $env->m->load($scrut);
        $env->m->emit('castclass Moggi.Rt.MList');
        $env->m->emit('ldfld object Moggi.Rt.MList::head');
        $headSlot = $env->freshLocal();
        $env->m->store($headSlot);
        emitPatternTest($env, $pat->head, $headSlot, $fail);
        $env->m->load($scrut);
        $env->m->emit('castclass Moggi.Rt.MList');
        $env->m->emit('ldfld class Moggi.Rt.MList Moggi.Rt.MList::tail');
        $tailSlot = $env->freshLocal();
        $env->m->store($tailSlot);
        emitPatternTest($env, $pat->tail, $tailSlot, $fail);

        return;
    }
    if ($pat instanceof IR\PatTuple) {
        foreach ($pat->elements as $i => $element) {
            if ($element instanceof IR\PatWild) {
                continue;
            }
            $env->m->load($scrut);
            $env->m->emit('castclass object[]');
            $env->m->emit("ldc.i4 {$i}");
            $env->m->emit('ldelem.ref');
            if ($element instanceof IR\PatVar) {
                $slot = $env->freshLocal();
                $env->m->store($slot);
                $env->locals[$element->name] = $slot;
            } else {
                $nested = $env->freshLocal();
                $env->m->store($nested);
                emitPatternTest($env, $element, $nested, $fail);
            }
        }

        return;
    }

    throw new \RuntimeException('DotNet emit: unsupported pattern ' . $pat::class);
}

// ---------------------------------------------------------------------------
// Data declarations
// ---------------------------------------------------------------------------

function emitDataConstructors(ClassIlBuilder $b, IR\DataDecl $decl): void
{
    if (isBoolBackedData($decl)) {
        $falseName = symbolName($decl->constructors[0]->name);
        $trueName = symbolName($decl->constructors[1]->name);

        $mFalse = new MethodIl();
        $mFalse->emit('ldc.i4.0');
        $mFalse->emit('box bool');
        $mFalse->emit('ret');
        $b->addMethod($mFalse->render($falseName, []));

        $mTrue = new MethodIl();
        $mTrue->emit('ldc.i4.1');
        $mTrue->emit('box bool');
        $mTrue->emit('ret');
        $b->addMethod($mTrue->render($trueName, []));

        return;
    }

    if ($decl->isNewtype) {
        $ctor = $decl->constructors[0];
        $name = symbolName($ctor->name);
        $m = new MethodIl();
        $m->emit('ldarg 0');
        $m->emit('ret');
        $b->addMethod($m->render($name, ['object']));

        return;
    }

    foreach ($decl->constructors as $ctor) {
        $name = symbolName($ctor->name);
        $arity = \count($ctor->fields);
        $m = new MethodIl();
        $m->emit('ldstr ' . ilString($ctor->name));
        $m->emit("ldc.i4 {$arity}");
        $m->emit('newarr object');
        for ($i = 0; $i < $arity; ++$i) {
            $m->emit('dup');
            $m->emit("ldc.i4 {$i}");
            $m->emit("ldarg {$i}");
            $m->emit('stelem.ref');
        }
        $m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::Con(string, object[])');
        $m->emit('ret');
        $b->addMethod($m->render($name, \array_fill(0, $arity, 'object')));
    }
}
