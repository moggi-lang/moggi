<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;

use Moggi\Backend\Jvm\Classfile\ClassBuilder;
use Moggi\Backend\Jvm\Classfile\CodeBuilder;
use Moggi\Backend\Jvm\Classfile\ConstantPool;
use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;

use function Moggi\Backend\Jvm\Foreign\jvmIsInterface;
use function Moggi\Backend\Jvm\Foreign\resolveJvmForeignPath;
use function Moggi\Backend\Jvm\Naming\moduleInternalName;
use function Moggi\Backend\Jvm\Naming\symbolName;
use function Moggi\Backend\Meta\constructorArityMap;
use function Moggi\Backend\Meta\constructorArityMapFromRegistry;
use function Moggi\Backend\Meta\isBoolBackedData;
use function Moggi\Backend\Meta\lambdaCaptureNamesInBlock;
use function Moggi\Backend\Meta\newtypeConstructorMap;
use function Moggi\Backend\Meta\newtypeConstructorMapFromRegistry;
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

require_once __DIR__ . '/classfile.php';
require_once __DIR__ . '/naming.php';
require_once __DIR__ . '/foreign.php';

/**
 * @param array<string, mixed> $options
 * @return array<string, string>
 */
function emitModule(IR\Module $module, string $sourcePath, array $options = []): array
{
    $moduleName = $options['moduleName'] ?? '';
    $internal = moduleInternalName($moduleName !== '' ? $moduleName : 'Main');
    $b = new ClassBuilder($internal);
    $displaySource = normalizeDisplayPath($module->sourceFile !== '' ? $module->sourceFile : $sourcePath);
    if ($displaySource !== '') {
        $b->setSourceFile($displaySource);
    }
    $artifact = (string) ($options['outputRelative'] ?? ($internal . '.class'));
    $sourceMap = new SourceMapBuilder($moduleName !== '' ? $moduleName : 'Main', 'jvm', $artifact);
    $lambdaMeta = buildLambdaMeta(indexCapturedFunctions($module->functions));
    $wrapperRegistry = new JvmFnWrapperRegistry();
    $ioThunkRegistry = new JvmIoThunkRegistry();

    $functionArity = [];
    // The import tables are keyed by bare name, so a name this module defines must
    // win: an arity taken from an import would emit the call as a runtime partial.
    /** @var array<string, true> $localBindings */
    $localBindings = [];
    foreach ($module->functions as $fn) {
        $localBindings[$fn->name] = true;
        $functionArity[$fn->name] = count($fn->params)
            + count($lambdaMeta[$fn->name]['captures'] ?? []);
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
    foreach ($options['globalFnArity'] ?? [] as $name => $arity) {
        if (!isset($functionArity[$name])) {
            $functionArity[$name] = $arity;
        }
    }
    foreach ($module->instanceEvidence as $ev) {
        $functionArity[$ev->evidenceName] = count($ev->contextParams);
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
        emitFunction($b, $fn, $internal, $functionArity, $externalFns, $evidenceMaps, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $sourceMap, $localCallables);
    }

    foreach ($module->data as $decl) {
        emitDataConstructors($b, $decl);
    }

    foreach ($module->instanceEvidence as $ev) {
        emitEvidence($b, $ev, $internal, $functionArity, $externalFns, $wrapperRegistry, $localCallables);
    }

    if ($module->entry !== null && $module->entry->kind === IR\EntryPointKind::Main) {
        emitJvmMain($b, $internal, symbolName($module->entry->name), $functionArity[$module->entry->name] ?? 0);
    }

    emitPendingIoThunkMethods($b, $internal, $functionArity, $externalFns, $evidenceMaps, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $localCallables);

    $classes = [$internal => $b->toBytes()];
    foreach ($wrapperRegistry->specs() as $spec) {
        $classes[$spec['internal']] = buildJvmFnWrapperClass(
            $spec['internal'],
            $spec['targetOwner'],
            $spec['targetSym'],
            $spec['arity'],
        );
    }
    $classes['__moggi.map__'] = $sourceMap->toJson();

    return $classes;
}

/**
 * @param array<string, int> $functionArity
 * @param array<string, string> $externalFns
 * @param array<string, array{module?: string, methods: array<string, string>}|array<string, string>> $evidenceMaps
 * @param array<string, true> $localCallables
 */
function emitFunction(
    ClassBuilder $b,
    IR\FunctionDecl $fn,
    string $owner,
    array $functionArity,
    array $externalFns,
    array $evidenceMaps = [],
    array $lambdaMeta = [],
    ?JvmFnWrapperRegistry $wrapperRegistry = null,
    ?JvmIoThunkRegistry $ioThunkRegistry = null,
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
    $arity = count($params);
    $desc = '(' . str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
    $maxLocals = max($arity + 32, 16);
    $paramV = \array_fill(0, $arity, 'java/lang/Object');

    $b->addMethod(
        $name,
        $desc,
        0x0009,
        $maxLocals,
        $paramV,
        static function (CodeBuilder $c, ConstantPool $cp) use ($fn, $name, $owner, $functionArity, $externalFns, $evidenceMaps, $lambdaMeta, $params, $arity, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $sourceMap, $localCallables): void {
            $env = new EmitEnv($c, $cp, $owner, $functionArity, $externalFns, $arity, $evidenceMaps, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $sourceMap, $localCallables);
            $env->currentMethod = $name;
            foreach ($params as $i => $p) {
                $env->locals[$p] = $i;
            }
            // `IR\TailRecall` re-assigns the declared parameters in place. The
            // slot is captured now, before the body can shadow a parameter name
            // with a pattern binder, and before any local is allocated.
            $env->tailRecSlots = array_map(
                static fn (string $p): int => $env->locals[$p],
                $fn->params,
            );
            noteJvmLoc($env, $fn->srcLoc);
            try {
                emitBlock($env, $fn->body);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException($e->getMessage() . " while emitting `{$fn->name}`", 0, $e);
            }
            // Dead fallthrough after body returns — needs a stack map for the verifier.
            $env->c->noteFrame($env->frameLocals($arity));
            $env->c->aconst_null();
            emitJvmFunctionReturn($env);
        }
    );
}

function emitEvidence(
    ClassBuilder $b,
    IR\InstanceEvidence $ev,
    string $owner,
    array $functionArity,
    array $externalFns,
    ?JvmFnWrapperRegistry $wrapperRegistry = null,
    array $localCallables = [],
): void {
    $name = symbolName($ev->evidenceName);
    $ctxArity = count($ev->contextParams);
    $pairs = [];
    foreach ($ev->methods as $surface => $irName) {
        $arity = $functionArity[$irName] ?? null;
        if ($arity === null) {
            throw new \RuntimeException("JVM emit: evidence method `{$irName}` has unknown arity");
        }
        $target = jvmResolveCallableTarget($owner, $externalFns, $irName, null, $localCallables);
        $pairs[] = [
            'surface' => $surface,
            'owner' => $target['owner'],
            'sym' => $target['sym'],
            'arity' => $arity,
        ];
    }
    // Instance context dictionaries (`Num a => Monoid (Sum a)`) become leading
    // parameters. Method slots close over them as Partial args.
    $desc = '(' . str_repeat('Ljava/lang/Object;', $ctxArity) . ')Ljava/lang/Object;';
    $paramV = \array_fill(0, $ctxArity, 'java/lang/Object');
    $methodsLocal = $ctxArity; // do not clobber context params
    $maxLocals = max($ctxArity + 2, 4);
    $b->addMethod(
        $name,
        $desc,
        0x0009,
        $maxLocals,
        $paramV,
        static function (CodeBuilder $c, ConstantPool $cp) use ($pairs, $wrapperRegistry, $ctxArity, $methodsLocal): void {
            $n = count($pairs) * 2;
            $c->iconst($n);
            $c->anewarray($cp->class_('java/lang/Object'));
            foreach ($pairs as $i => $entry) {
                $c->dup();
                $c->iconst($i * 2);
                $c->ldc($cp->string_($entry['surface']));
                $c->aastore();

                $c->dup();
                $c->iconst(($i * 2) + 1);
                $wrapper = $wrapperRegistry?->ensure($entry['owner'], $entry['sym'], $entry['arity'])
                    ?? jvmFnWrapperInternalName($entry['owner'], $entry['sym'], $entry['arity']);
                $c->new_($cp->class_('moggi/rt/TopLevelFn'));
                $c->dup();
                $c->iconst($entry['arity']);
                $c->new_($cp->class_($wrapper));
                $c->dup();
                $c->invokespecial(
                    $cp->methodRef($wrapper, '<init>', '()V'),
                    0,
                    false,
                );
                $c->invokespecial(
                    $cp->methodRef('moggi/rt/TopLevelFn', '<init>', '(ILmoggi/rt/Fn;)V'),
                    2,
                    false,
                );
                if ($ctxArity > 0) {
                    // Partial(fullArity, TopLevelFn, [ctx0, ...]) so surface
                    // calls only supply the remaining user arguments.
                    $c->astore($methodsLocal + 1); // temp: TopLevelFn
                    $c->new_($cp->class_('moggi/rt/Partial'));
                    $c->dup();
                    $c->iconst($entry['arity']);
                    $c->aload($methodsLocal + 1);
                    $c->iconst($ctxArity);
                    $c->anewarray($cp->class_('java/lang/Object'));
                    for ($ci = 0; $ci < $ctxArity; ++$ci) {
                        $c->dup();
                        $c->iconst($ci);
                        $c->aload($ci);
                        $c->aastore();
                    }
                    $c->invokespecial(
                        $cp->methodRef('moggi/rt/Partial', '<init>', '(ILmoggi/rt/Fn;[Ljava/lang/Object;)V'),
                        3,
                        false,
                    );
                }
                $c->aastore();
            }
            $c->astore($methodsLocal);
            $c->new_($cp->class_('moggi/rt/Dict'));
            $c->dup();
            $c->aload($methodsLocal);
            $c->invokespecial($cp->methodRef('moggi/rt/Dict', '<init>', '([Ljava/lang/Object;)V'), 1, false);
            $c->areturn();
        }
    );
}

/**
 * JVM entry bridge for `Main.main :: IO a` (result discarded at runtime).
 *
 * Calls the Moggi entry. If it returns an IO action, run it via RT.ioRun.
 * Straight-line foreign IO may already have run eagerly (returns Unit/null);
 * in that case there is nothing left to execute.
 */
function emitJvmMain(ClassBuilder $b, string $owner, string $mainFn, int $arity): void
{
    $b->addMethod(
        'main',
        '([Ljava/lang/String;)V',
        0x0009,
        3,
        ['[Ljava/lang/String;'],
        static function (CodeBuilder $c, ConstantPool $cp) use ($owner, $mainFn, $arity): void {
            if ($arity !== 0) {
                $c->new_($cp->class_('java/lang/RuntimeException'));
                $c->dup();
                $c->ldc($cp->string_('entry main must be nullary'));
                $c->invokespecial($cp->methodRef('java/lang/RuntimeException', '<init>', '(Ljava/lang/String;)V'), 1, false);
                $c->athrow();

                return;
            }
            $throwable = $cp->class_('java/lang/Throwable');
            $c->label('main_try_start');
            $c->invokestatic($cp->methodRef('moggi/rt/Platform', 'useUtf8Console', '()V'), 0, false);
            $c->aload(0);
            $c->invokestatic($cp->methodRef('moggi/rt/Platform', 'setArgs', '([Ljava/lang/String;)V'), 1, false);
            $c->invokestatic($cp->methodRef($owner, $mainFn, '()Ljava/lang/Object;'), 0, true);
            $c->astore(1);
            $c->aload(1);
            $c->instanceof_($cp->class_('moggi/rt/IO'));
            $c->ifeq('done');
            $c->noteFrame(['[Ljava/lang/String;', 'java/lang/Object']);
            $c->aload(1);
            $c->checkcast($cp->class_('moggi/rt/IO'));
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'ioRun', '(Lmoggi/rt/IO;)Ljava/lang/Object;'), 1, true);
            $c->pop_();
            $c->label('done');
            $c->noteFrame(['[Ljava/lang/String;', 'java/lang/Object']);
            $c->return_();
            $c->label('main_try_end');

            $c->label('main_catch');
            $c->exception('main_try_start', 'main_try_end', 'main_catch', $throwable);
            $c->assumeStackDelta(1);
            $c->noteFrame(['[Ljava/lang/String;'], ['java/lang/Throwable']);
            $c->invokestatic($cp->methodRef('moggi/rt/RT', 'reportUncaught', '(Ljava/lang/Throwable;)V'), 1, false);
            $c->return_();
        }
    );
}

final class EmitEnv
{
    /** @var array<string, int> */
    public array $locals = [];
    /** @var array<int, string> */
    public array $localTypes = [];
    public int $nextLocal;
    private int $labelId = 0;
    /** When set, IR\Ret inside match arms yields to this temp instead of returning. */
    public ?int $matchYieldDest = null;
    public ?string $matchJoinLabel = null;

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
     * @param array<string, array<string, string>> $evidenceMaps
     * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
     * @param array<string, true> $newtypeConstructors
     * @param array<string, true> $localCallables
     */
    public function __construct(
        public CodeBuilder $c,
        public ConstantPool $cp,
        public string $owner,
        public array $functionArity,
        public array $externalFns,
        int $paramCount,
        public array $evidenceMaps = [],
        public array $lambdaMeta = [],
        public ?JvmFnWrapperRegistry $wrapperRegistry = null,
        public ?JvmIoThunkRegistry $ioThunkRegistry = null,
        public array $newtypeConstructors = [],
        public ?SourceMapBuilder $sourceMap = null,
        public array $localCallables = [],
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
            // ITEM_Long / ITEM_Double occupy two interpreter slots but one
            // StackMapTable entry — skip only the synthetic second half.
            // Uninitialized reference holes must remain ITEM_Top; skipping them
            // shifts later locals left and causes VerifyError at merges.
            if ($t === 'long' || $t === 'double') {
                $locals[] = $t;
                ++$i;
                continue;
            }
            $locals[] = $t;
        }
        while ($locals !== [] && $locals[array_key_last($locals)] === 'top') {
            array_pop($locals);
        }

        return $locals;
    }
}

final class JvmFnWrapperRegistry
{
    /** @var array<string, array{internal: string, targetOwner: string, targetSym: string, arity: int}> */
    private array $specs = [];

    public function ensure(string $targetOwner, string $targetSym, int $arity): string
    {
        $internal = jvmFnWrapperInternalName($targetOwner, $targetSym, $arity);
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
        return array_values($this->specs);
    }
}

final class JvmIoThunkRegistry
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
        $this->emitted = count($this->specs);
    }
}

/**
 * @param array<string, int> $functionArity
 * @param array<string, string> $externalFns
 * @param array<string, array{module?: string, methods: array<string, string>}|array<string, string>> $evidenceMaps
 * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
 * @param array<string, true> $localCallables
 */
function emitPendingIoThunkMethods(
    ClassBuilder $b,
    string $owner,
    array $functionArity,
    array $externalFns,
    array $evidenceMaps,
    array $lambdaMeta,
    ?JvmFnWrapperRegistry $wrapperRegistry,
    JvmIoThunkRegistry $ioThunkRegistry,
    array $newtypeConstructors = [],
    array $localCallables = [],
): void {
    while (($pending = $ioThunkRegistry->pending()) !== []) {
        $ioThunkRegistry->markEmitted();
        foreach ($pending as $spec) {
            $params = $spec['captures'];
            $arity = count($params);
            $desc = '(' . str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
            $maxLocals = max($arity + 32, 16);
            $paramV = \array_fill(0, $arity, 'java/lang/Object');
            $name = $spec['name'];
            $body = $spec['body'];
            $result = $spec['result'];
            $b->addMethod(
                $name,
                $desc,
                0x0009,
                $maxLocals,
                $paramV,
                static function (CodeBuilder $c, ConstantPool $cp) use ($owner, $functionArity, $externalFns, $evidenceMaps, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $params, $arity, $body, $result, $newtypeConstructors, $localCallables): void {
                    $env = new EmitEnv($c, $cp, $owner, $functionArity, $externalFns, $arity, $evidenceMaps, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, null, $localCallables);
                    foreach ($params as $i => $p) {
                        $env->locals[$p] = $i;
                    }
                    emitBlock($env, $body);
                    emitOperand($env, $result);
                    $resultSlot = $env->freshLocal();
                    $env->c->astore($resultSlot);
                    $done = 'io_thunk_done_' . $env->freshLabelId();
                    $env->c->aload($resultSlot);
                    $env->c->instanceof_($env->cp->class_('moggi/rt/IO'));
                    $env->c->ifeq($done);
                    $env->c->aload($resultSlot);
                    $env->c->checkcast($env->cp->class_('moggi/rt/IO'));
                    $env->c->invokestatic($env->cp->methodRef('moggi/rt/RT', 'ioRun', '(Lmoggi/rt/IO;)Ljava/lang/Object;'), 1, true);
                    $env->c->areturn();
                    $env->c->label($done);
                    $env->c->noteFrame($env->frameLocals());
                    $env->c->aload($resultSlot);
                    $env->c->areturn();
                }
            );
        }
    }
}

function jvmFnWrapperInternalName(string $targetOwner, string $targetSym, int $arity): string
{
    $sym = preg_replace('/[^A-Za-z0-9_$]/', '_', $targetSym) ?? $targetSym;

    return $targetOwner . '$fn$' . $sym . '$' . $arity;
}

function buildJvmFnWrapperClass(string $internal, string $targetOwner, string $targetSym, int $arity): string
{
    $b = new ClassBuilder($internal);
    $b->implement('moggi/rt/Fn');
    $b->addMethod(
        '<init>',
        '()V',
        0x0001,
        1,
        [$internal],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->aload(0);
            $c->invokespecial($cp->methodRef('java/lang/Object', '<init>', '()V'), 0, false);
            $c->return_();
        }
    );
    $b->addMethod(
        'invoke',
        '([Ljava/lang/Object;)Ljava/lang/Object;',
        0x0001,
        max($arity + 2, 2),
        [$internal, '[Ljava/lang/Object;'],
        static function (CodeBuilder $c, ConstantPool $cp) use ($targetOwner, $targetSym, $arity): void {
            for ($i = 0; $i < $arity; ++$i) {
                $c->aload(1);
                $c->iconst($i);
                $c->aaload();
            }
            $desc = '(' . str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
            $c->invokestatic($cp->methodRef($targetOwner, $targetSym, $desc), $arity, true);
            $c->areturn();
        }
    );

    return $b->toBytes();
}

function emitBlock(EmitEnv $env, IR\Block $block): void
{
    foreach ($block->items as $stmt) {
        emitStmt($env, $stmt);
    }
}

/**
 * Record a source location for both the LineNumberTable and the frame table
 * (the latter supplies the display path and column native debug info lacks).
 */
function noteJvmLoc(EmitEnv $env, ?IR\SrcLoc $loc): void
{
    if ($loc === null || $loc->line <= 0) {
        return;
    }
    $env->c->noteLine($loc->line);
    if ($env->sourceMap !== null) {
        $env->sourceMap->addFrame($env->owner, $env->currentMethod, $loc);
    }
}

function emitStmt(EmitEnv $env, IR\Stmt $stmt): void
{
    if (isset($stmt->srcLoc) && $stmt->srcLoc instanceof IR\SrcLoc) {
        noteJvmLoc($env, $stmt->srcLoc);
    }
    switch ($stmt::class) {
        case IR\Ret::class:
            /** @var IR\Ret $stmt */
            emitOperand($env, $stmt->value);
            if (jvmOperandNeverReturns($stmt->value)) {
                return;
            }
            if ($env->matchYieldDest !== null) {
                $env->c->astore($env->localSlot('t' . $env->matchYieldDest));
                if ($env->matchJoinLabel !== null) {
                    $env->c->goto_($env->matchJoinLabel);
                }

                return;
            }
            emitJvmFunctionReturn($env);

            return;

        case IR\Assign::class:
            /** @var IR\Assign $stmt */
            emitOperand($env, $stmt->value);
            $env->c->astore($env->localSlot('t' . $stmt->dest));

            return;

        case IR\Let::class:
            /** @var IR\Let $stmt */
            emitOperand($env, $stmt->value);
            $env->c->astore($env->localSlot($stmt->name));

            return;

        case IR\Binop::class:
            /** @var IR\Binop $stmt */
            emitIntBinopToStack($env, $stmt->op, $stmt->left, $stmt->right);
            $env->c->astore($env->localSlot('t' . $stmt->dest));

            return;

        case IR\Call::class:
            /** @var IR\Call $stmt */
            emitStaticCall($env, $stmt->callee, $stmt->args);
            $env->c->astore($env->localSlot('t' . $stmt->dest));

            return;

        case IR\CallValue::class:
            /** @var IR\CallValue $stmt */
            emitOperand($env, $stmt->callee);
            emitRuntimeApply($env, $stmt->args);
            $env->c->astore($env->localSlot('t' . $stmt->dest));

            return;

        case IR\IoRun::class:
            /** @var IR\IoRun $stmt */
            emitOperand($env, $stmt->action);
            $env->c->checkcast($env->cp->class_('moggi/rt/IO'));
            $env->c->invokestatic($env->cp->methodRef('moggi/rt/RT', 'ioRun', '(Lmoggi/rt/IO;)Ljava/lang/Object;'), 1, true);
            if ($stmt->dest !== null) {
                $env->c->astore($env->localSlot('t' . $stmt->dest));
            } else {
                $env->c->pop_();
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
            emitJvmPushSrcLoc($env, $stmt->srcLoc);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'throwSomeException', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            if ($stmt->dest !== null) {
                $env->c->astore($env->localSlot('t' . $stmt->dest));
            } else {
                $env->c->pop_();
            }

            return;

        case IR\IoCatch::class:
            /** @var IR\IoCatch $stmt */
            emitOperand($env, $stmt->action);
            $env->c->checkcast($env->cp->class_('moggi/rt/IO'));
            emitOperand($env, $stmt->handler);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioCatch', '(Lmoggi/rt/IO;Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            if ($stmt->dest !== null) {
                $env->c->astore($env->localSlot('t' . $stmt->dest));
            } else {
                $env->c->pop_();
            }

            return;

        case IR\IoFinally::class:
            /** @var IR\IoFinally $stmt */
            emitOperand($env, $stmt->action);
            $env->c->checkcast($env->cp->class_('moggi/rt/IO'));
            emitOperand($env, $stmt->cleanup);
            $env->c->checkcast($env->cp->class_('moggi/rt/IO'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioFinally', '(Lmoggi/rt/IO;Lmoggi/rt/IO;)Ljava/lang/Object;'),
                2,
                true,
            );
            if ($stmt->dest !== null) {
                $env->c->astore($env->localSlot('t' . $stmt->dest));
            } else {
                $env->c->pop_();
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
            $resolved = jvmResolveDictMethod($env, $stmt->evidence, $stmt->method);
            if ($resolved !== null) {
                emitStaticCall($env, $resolved['name'], $stmt->args, $resolved['module']);
                $env->c->astore($env->localSlot('t' . $stmt->dest));

                return;
            }
            emitOperand($env, $stmt->evidence);
            $env->c->ldc($env->cp->string_($stmt->method));
            pushObjectArray($env, $stmt->args);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'dictCall', '(Ljava/lang/Object;Ljava/lang/String;[Ljava/lang/Object;)Ljava/lang/Object;'),
                3,
                true,
            );
            $env->c->astore($env->localSlot('t' . $stmt->dest));

            return;

        default:
            throw new \RuntimeException('JVM emit: unsupported stmt ' . $stmt::class);
    }
}

/**
 * Whether control never falls out of the end of `$stmt`.
 *
 * Used to decide whether a fall-through `goto` has to be appended: emitting one
 * after an unconditional transfer produces unreachable code that the verifier
 * still demands a stack map frame for.
 */
function jvmStmtTerminates(IR\Stmt $stmt): bool
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
            if (!jvmBlockTerminates($arm->body)) {
                return false;
            }
        }

        return true;
    }

    return false;
}

function jvmBlockTerminates(IR\Block $block): bool
{
    $items = $block->items;

    return $items !== [] && jvmStmtTerminates($items[array_key_last($items)]);
}

/**
 * `IR\Loop` is the optimizer's self-tail-recursion rewrite: a `while (true)`
 * whose back-edges are `IR\TailRecall`. Emitted as a label + backward jump.
 */
function emitTailLoop(EmitEnv $env, IR\Loop $stmt): void
{
    $head = 'tail_loop_' . $env->freshLabelId();
    $prevHead = $env->tailLoopHead;
    $env->tailLoopHead = $head;

    $env->c->label($head);
    $env->c->noteFrame($env->frameLocals());

    emitBlock($env, $stmt->body);
    // Only a body that can fall out of its end needs the explicit back-edge;
    // emitting it after an unconditional `goto`/`return` leaves unreachable
    // code the verifier still wants a stack map frame for.
    if (!jvmBlockTerminates($stmt->body)) {
        $env->c->goto_($head);
    }

    $env->tailLoopHead = $prevHead;
}

/**
 * Assign the recall's arguments to the function's parameter slots and jump back
 * to the loop head.
 *
 * Every argument is evaluated into a fresh local before any parameter slot is
 * written. A direct parameter-slot assignment would read clobbered values
 * whenever an argument mentions a parameter that an earlier argument already
 * overwrote (e.g. a two-parameter swap).
 */
function emitTailRecall(EmitEnv $env, IR\TailRecall $stmt): void
{
    $head = $env->tailLoopHead;
    $slots = $env->tailRecSlots;
    if ($head === null || count($stmt->args) !== count($slots)) {
        throw new \RuntimeException('JVM emit: tail_recall outside a tail loop');
    }

    $values = [];
    foreach ($stmt->args as $arg) {
        emitOperand($env, $arg);
        $slot = $env->freshLocal();
        $env->c->astore($slot);
        $values[] = $slot;
    }

    foreach ($slots as $i => $slot) {
        $env->c->aload($values[$i]);
        $env->c->astore($slot);
    }

    $env->c->goto_($head);
}

function jvmResolveDictMethod(EmitEnv $env, IR\Operand $evidence, string $method): ?array
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
            $env->c->astore($env->localSlot('t' . $stmt->dest));
        } else {
            $env->c->pop_();
        }

        return;
    }

    $runtime = $stmt->runtime ?? null;
    if (\is_string($runtime) && str_starts_with($runtime, 'foreign:')) {
        $foreign = $stmt->foreign;
        if (!($foreign instanceof IR\ForeignCall)) {
            throw new \RuntimeException('foreign IO call missing metadata');
        }
        // Eager: run foreign effect and optionally store result (IO () → null).
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
            $env->c->astore($env->localSlot('t' . $stmt->dest));
        } else {
            $env->c->pop_();
        }

        return;
    }

    emitStaticCall($env, $stmt->callee, $stmt->args);
    if ($stmt->dest !== null) {
        $env->c->astore($env->localSlot('t' . $stmt->dest));
    } else {
        $env->c->pop_();
    }
}

/**
 * Box a delayed IO action: ['__io', fn] → new IO(Fn).
 * MVP: run body eagerly into a nullary thunk class is hard without nested classes;
 * instead emit body into a synthetic static method and wrap as Fn via RT.thunk.
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
        ?? throw new \RuntimeException('JVM emit: missing IO thunk registry');

    if ($captures === []) {
        emitTopLevelFnValue($env, $spec['name'], 0);
    } else {
        $env->c->new_($env->cp->class_('moggi/rt/Partial'));
        $env->c->dup();
        $env->c->iconst(count($captures));
        emitTopLevelFnValue($env, $spec['name'], count($captures));
        $captureArgs = \array_map(
            static fn (string $capture): IR\Operand => new IR\Local($capture),
            $captures,
        );
        pushObjectArray($env, $captureArgs);
        $env->c->invokespecial(
            $env->cp->methodRef('moggi/rt/Partial', '<init>', '(ILmoggi/rt/Fn;[Ljava/lang/Object;)V'),
            3,
            false,
        );
    }
    $actionSlot = $env->freshLocal();
    $env->c->astore($actionSlot);
    $env->c->new_($env->cp->class_('moggi/rt/IO'));
    $env->c->dup();
    $env->c->aload($actionSlot);
    $env->c->invokespecial(
        $env->cp->methodRef('moggi/rt/IO', '<init>', '(Ljava/lang/Object;)V'),
        1,
        false,
    );
    $env->c->astore($env->localSlot('t' . $stmt->dest));
}

/** @param list<IR\Operand> $args */
function emitStaticCall(EmitEnv $env, string $name, array $args, ?string $ownerModule = null): void
{
    if (emitJvmBuiltinCall($env, $name, $args)) {
        return;
    }
    $callArgs = jvmCallArgs($env, $name, $args);
    // A lifted function is callable at its captures followed by its parameters,
    // and applying it to fewer arguments than that is a *partial*, not a call:
    // the adapter a constrained local binding lowers to (`λ(dict, p) ->
    // λ3(dict, p)`) applies its body lambda before the last argument arrives.
    if (isCapturedFnName($name)) {
        $meta = $env->lambdaMeta[$name] ?? ['captures' => [], 'params' => []];
        $fullArity = count($meta['captures']) + count($meta['params']);
        if (count($callArgs) < $fullArity) {
            emitPartialValue($env, $name, $fullArity, $callArgs);

            return;
        }
    }
    $argSlots = [];
    foreach ($callArgs as $arg) {
        emitOperand($env, $arg);
        $slot = $env->freshLocal();
        $env->c->astore($slot);
        $argSlots[] = $slot;
    }
    foreach ($argSlots as $slot) {
        $env->c->aload($slot);
    }
    $arity = count($callArgs);
    $desc = '(' . str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
    $target = jvmResolveCallableTarget($env->owner, $env->externalFns, $name, $ownerModule, $env->localCallables);
    $env->c->invokestatic($env->cp->methodRef($target['owner'], $target['sym'], $desc), $arity, true);
}

/**
 * @param array<string, string> $externalFns
 * @param array<string, true> $localCallables
 * @return array{owner: string, sym: string}
 */
function jvmResolveCallableTarget(
    string $owner,
    array $externalFns,
    string $name,
    ?string $ownerModule = null,
    array $localCallables = [],
): array {
    $targetOwner = $owner;
    $sym = symbolName($name);
    if ($ownerModule !== null && $ownerModule !== '') {
        $targetOwner = moduleInternalName($ownerModule);
    } else {
        $parsed = parseResolvedSymbol($name);
        if ($parsed !== null) {
            $targetOwner = moduleInternalName($parsed['module']);
            $sym = symbolName($parsed['name']);
        } elseif (!isset($localCallables[$name]) && isset($externalFns[$name])) {
            // Prefer a local definition over an imported short name (e.g. List.map
            // must not resolve to Maybe.map / IO.map via externalFns).
            $extParsed = parseResolvedSymbol($externalFns[$name]);
            if ($extParsed !== null) {
                $targetOwner = moduleInternalName($extParsed['module']);
                $sym = symbolName($extParsed['name']);
            }
        }
    }

    return ['owner' => $targetOwner, 'sym' => $sym];
}

function emitTopLevelFnValue(EmitEnv $env, string $name, int $arity, ?string $ownerModule = null): void
{
    $target = jvmResolveCallableTarget($env->owner, $env->externalFns, $name, $ownerModule, $env->localCallables);
    $wrapper = $env->wrapperRegistry?->ensure($target['owner'], $target['sym'], $arity)
        ?? jvmFnWrapperInternalName($target['owner'], $target['sym'], $arity);
    $env->c->new_($env->cp->class_('moggi/rt/TopLevelFn'));
    $env->c->dup();
    $env->c->iconst($arity);
    $env->c->new_($env->cp->class_($wrapper));
    $env->c->dup();
    $env->c->invokespecial(
        $env->cp->methodRef($wrapper, '<init>', '()V'),
        0,
        false,
    );
    $env->c->invokespecial(
        $env->cp->methodRef('moggi/rt/TopLevelFn', '<init>', '(ILmoggi/rt/Fn;)V'),
        2,
        false,
    );
}

/** @param list<IR\Operand> $args */
function emitPartialValue(EmitEnv $env, string $fn, int $arity, array $args): void
{
    $runtimeArity = $env->functionArity[$fn] ?? $arity;
    $allArgs = jvmCallArgs($env, $fn, $args);
    $env->c->new_($env->cp->class_('moggi/rt/Partial'));
    $env->c->dup();
    $env->c->iconst($runtimeArity);
    emitTopLevelFnValue($env, $fn, $runtimeArity);
    pushObjectArray($env, $allArgs);
    $env->c->invokespecial(
        $env->cp->methodRef('moggi/rt/Partial', '<init>', '(ILmoggi/rt/Fn;[Ljava/lang/Object;)V'),
        3,
        false,
    );
}

/** @param list<IR\Operand> $args */
function emitJvmBuiltinCall(EmitEnv $env, string $name, array $args): bool
{
    if (str_starts_with($name, '__tuple_field')) {
        emitOperand($env, $args[0]);
        $env->c->checkcast($env->cp->class_('[Ljava/lang/Object;'));
        $env->c->iconst((int) substr($name, strlen('__tuple_field')));
        $env->c->aaload();
        return true;
    }
    if (str_starts_with($name, '__tuple')) {
        if ($args === []) {
            $env->c->aconst_null();
            return true;
        }
        pushObjectArray($env, $args);
        return true;
    }
    if (str_starts_with($name, '__field')) {
        emitOperand($env, $args[0]);
        $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
        $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
        $env->c->iconst((int) substr($name, strlen('__field')));
        $env->c->aaload();
        return true;
    }

    return false;
}

function emitIntBinopToStack(EmitEnv $env, string $op, IR\Operand $left, IR\Operand $right): void
{
    if ($op === '==' || $op === '/=') {
        emitOperand($env, $left);
        emitOperand($env, $right);
        // Structural, not `Object.equals`: a tuple is an `Object[]` and an array does not compare
        // its elements.
        $env->c->invokestatic($env->cp->methodRef('moggi/rt/RT', 'valueEq', '(Ljava/lang/Object;Ljava/lang/Object;)Z'), 2, true);
        if ($op === '/=') {
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
        }
        $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

        return;
    }
    if (\in_array($op, ['<', '<=', '>', '>='], true)) {
        if ($op === '>') {
            emitOperand($env, $right);
            unboxInt($env);
            emitOperand($env, $left);
            unboxInt($env);
        } else {
            emitOperand($env, $left);
            unboxInt($env);
            emitOperand($env, $right);
            unboxInt($env);
        }
        $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'compare', '(JJ)I'), 4, true);
        // `cmp` is -1/0/1 for the emitted operand order. The predicate is read
        // off its sign arithmetically rather than with a branch: a comparison is
        // an operand, so it can be emitted while an enclosing argument list is
        // already building its array on the stack, and a branch target there
        // needs that whole stack in its StackMapTable frame.
        $cmp = $env->freshLocal('int');
        $env->c->istore($cmp);
        $env->c->iload($cmp);
        if ($op === '<=') {
            // `<=` is `cmp < 1`, so the sign is taken one below the compare.
            $env->c->iconst(1);
            $env->c->isub();
        }
        $env->c->iconst(31);
        $env->c->opcode(0x7a, -1); // ishr: sign bit of the adjusted compare
        $env->c->iconst(1);
        $env->c->opcode(0x7e, -1); // iand: 1 when the strict relation holds
        if ($op === '>=') {
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1); // ixor: >= is the negation of <
        }
        $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

        return;
    }
    emitOperand($env, $left);
    unboxInt($env);
    emitOperand($env, $right);
    unboxInt($env);
    match ($op) {
        '+', 'intAdd#' => $env->c->ladd(),
        '-', 'intSub#' => $env->c->lsub(),
        '*', 'intMul#' => $env->c->lmul(),
        // Toward 0. Library Integral Int.div uses Math.floorDiv.
        '/', 'intDiv#' => $env->c->ldiv(),
        default => throw new \RuntimeException("JVM emit: unsupported binop {$op}"),
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
        'doubleAdd#' => $env->c->dadd(),
        'doubleSub#' => $env->c->dsub(),
        'doubleMul#' => $env->c->dmul(),
        'doubleDiv#' => $env->c->ddiv(),
        default => throw new \RuntimeException("JVM emit: unsupported float binop {$op}"),
    };
    boxDouble($env);
}

function emitOperand(EmitEnv $env, IR\Operand $op): void
{
    if ($op instanceof IR\ConstInt || $op instanceof IR\ConstChar) {
        pushBoxedInt($env, $op->value);

        return;
    }
    if ($op instanceof IR\ConstStr) {
        $env->c->ldc($env->cp->string_($op->value));

        return;
    }
    if ($op instanceof IR\ConstDouble) {
        $env->c->ldc($env->cp->string_(formatJvmDouble($op->value)));
        $env->c->invokestatic(
            $env->cp->methodRef('java/lang/Double', 'valueOf', '(Ljava/lang/String;)Ljava/lang/Double;'),
            1,
            true,
        );

        return;
    }
    if ($op instanceof IR\Unit) {
        $env->c->aconst_null();

        return;
    }
    if ($op instanceof IR\Local) {
        $env->c->aload($env->locals[$op->name] ?? throw new \RuntimeException("unknown local {$op->name}"));

        return;
    }
    if ($op instanceof IR\Temp) {
        $key = 't' . $op->id;
        $slot = $env->locals[$key] ?? null;
        if ($slot === null) {
            throw new \RuntimeException("unknown temp {$key} in {$env->owner}");
        }
        $env->c->aload($slot);

        return;
    }
    if ($op instanceof IR\Intrinsic) {
        noteJvmLoc($env, $op->srcLoc);
        emitIntrinsic($env, $op);

        return;
    }
    if ($op instanceof IR\ListLit) {
        emitListLit($env, $op);

        return;
    }
    if ($op instanceof IR\ExprCall) {
        noteJvmLoc($env, $op->srcLoc);
        emitStaticCall($env, $op->callee, $op->args);

        return;
    }
    if ($op instanceof IR\ExprCallValue) {
        noteJvmLoc($env, $op->srcLoc);
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
        $resolved = jvmResolveDictMethod($env, $op->evidence, $op->method);
        if ($resolved !== null) {
            // Method IR often lives in the evidence's defining module; arity is
            // keyed as `Module::name` in globalFnArity (bare short names are
            // frequently absent in the consumer module, e.g. Char8 → Ord.max).
            $arity = $env->functionArity[$resolved['name']] ?? null;
            if ($arity === null && \is_string($resolved['module'] ?? null) && $resolved['module'] !== '') {
                $arity = $env->functionArity[
                    resolvedSymbol($resolved['module'], $resolved['name'])
                ] ?? null;
            }
            if ($arity === null) {
                throw new \RuntimeException("JVM emit: DictMethod `{$op->method}` resolved to unknown arity");
            }
            emitTopLevelFnValue($env, $resolved['name'], $arity, $resolved['module']);

            return;
        }
        emitOperand($env, $op->evidence);
        $env->c->ldc($env->cp->string_($op->method));
        $env->c->invokestatic(
            $env->cp->methodRef('moggi/rt/RT', 'dictMethod', '(Ljava/lang/Object;Ljava/lang/String;)Ljava/lang/Object;'),
            2,
            true,
        );

        return;
    }
    if ($op instanceof IR\FnRef) {
        // `Bool` is wired in: every module has its constructors in scope, including
        // one that never imports Data.Bool (Data.Ord's class defaults are one).
        // The host boolean is what an imported `Data.Bool` lowers to as well, so
        // both spellings agree without a module to call.
        if ($op->name === 'True' || $op->name === 'False') {
            $env->c->iconst($op->name === 'True' ? 1 : 0);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return;
        }
        if (isCapturedFnName($op->name)) {
            $meta = $env->lambdaMeta[$op->name] ?? ['captures' => [], 'params' => []];
            $captures = $meta['captures'] ?? [];
            if ($captures !== []) {
                // Close over free locals: Partial(fullArity, fn, [capture…]).
                // (IR keeps bare @λ refs; bindLambdaCaptures only rewrites Partial/Call.)
                $captureArgs = \array_map(
                    static fn (string $capture): IR\Operand => new IR\Local($capture),
                    $captures,
                );
                $fullArity = count($captures) + count($meta['params'] ?? []);
                emitPartialValue($env, $op->name, $fullArity, $captureArgs);

                return;
            }
        }
        $arity = $env->functionArity[$op->name] ?? null;
        if ($arity === null) {
            $parsed = parseResolvedSymbol($op->name);
            // Never fall back to a bare `λN` from another module — short lambda
            // names collide across modules and pick the wrong arity.
            if ($parsed !== null && !isLambdaName($parsed['name'])) {
                $arity = $env->functionArity[$parsed['name']] ?? null;
            }
        }
        if ($arity === null) {
            throw new \RuntimeException("JVM emit: FnRef `{$op->name}` has unknown arity");
        }
        if ($arity === 0) {
            emitStaticCall($env, $op->name, []);

            return;
        }
        emitTopLevelFnValue($env, $op->name, $arity);

        return;
    }

    throw new \RuntimeException('JVM emit: unsupported operand ' . $op::class);
}

function jvmOperandNeverReturns(IR\Operand $op): bool
{
    // throwErrorCall / throwSomeException are typed as returning Object for the
    // verifier; emit sites must still areturn/astore/pop the dead result.
    return false;
}

function pushBoxedInt(EmitEnv $env, int $v): void
{
    // Language Int is signed 64-bit (java.lang.Long).
    if ($v === 0 || $v === 1) {
        $env->c->lconst($v);
    } elseif ($v >= -32768 && $v <= 32767) {
        $env->c->iconst($v); // iconst/bipush/sipush range via iconst helper
        $env->c->i2l();
    } else {
        $env->c->ldc2_w($env->cp->long($v));
    }
    boxInt($env);
}

function unboxInt(EmitEnv $env): void
{
    $env->c->checkcast($env->cp->class_('java/lang/Long'));
    $env->c->invokevirtual($env->cp->methodRef('java/lang/Long', 'longValue', '()J'), 0, 2);
}

function boxInt(EmitEnv $env): void
{
    $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'valueOf', '(J)Ljava/lang/Long;'), 2, 1);
}

/**
 * `base ^ exponent` at machine `Int`: the runtime's unboxed square-and-multiply.
 *
 * The loop lives in the runtime rather than inline because an intrinsic is
 * emitted mid-expression, where the operand stack already holds other values --
 * a stack map frame there would have to describe exactly those. The call needs
 * none.
 */
function emitIntPowToStack(EmitEnv $env, IR\Operand $base, IR\Operand $exponent, ?IR\SrcLoc $loc): void
{
    emitUnboxedIntPowToStack($env, $base, $exponent, $loc, -1);
    boxInt($env);
}

/**
 * The runtime's masked square-and-multiply, on unboxed operands.
 *
 * `-1` reduces not at all: the machine `Int`'s own multiply already wraps at 64
 * bits. A fixed-width type passes its own mask, and the signed ones are
 * sign-extended back by {@see emitSignExtend} at the call site.
 */
function emitUnboxedIntPowToStack(
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
    $env->c->ldc2_w($env->cp->long($mask));
    emitJvmPushSrcLoc($env, $loc);
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'intPow', '(JJJ[Ljava/lang/Object;)J'),
        5,
        true,
    );
}

/**
 * One of the fixed-width `^` primops: the masked loop, then the sign extension
 * the signed widths need (the 64-bit ones are already the host width).
 */
function emitPowFamilyToStack(EmitEnv $env, IR\Intrinsic $op): bool
{
    $name = $op->name;
    $mask = powWidthMask($name);
    if ($mask === null) {
        return false;
    }

    emitUnboxedIntPowToStack($env, $op->args[0], $op->args[1], $op->srcLoc, $mask);
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
function emitDoublePowToStack(EmitEnv $env, IR\Operand $base, IR\Operand $exponent, ?IR\SrcLoc $loc): void
{
    emitOperand($env, $base);
    unboxDouble($env);
    emitOperand($env, $exponent);
    unboxInt($env);
    emitJvmPushSrcLoc($env, $loc);
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'doublePow', '(DJ[Ljava/lang/Object;)D'),
        3,
        true,
    );
    boxDouble($env);
}

/** Sign-extend-truncate an i64 on the stack to the width named by an intN_* op. */
function emitSignExtend(EmitEnv $env, string $opName): void
{
    if (str_starts_with($opName, 'int8')) {
        $env->c->l2i();
        $env->c->i2b();
        $env->c->i2l();

        return;
    }
    if (str_starts_with($opName, 'int16')) {
        $env->c->l2i();
        $env->c->i2s();
        $env->c->i2l();

        return;
    }
    // int32
    $env->c->l2i();
    $env->c->i2l();
}

function unboxDouble(EmitEnv $env): void
{
    $env->c->checkcast($env->cp->class_('java/lang/Double'));
    $env->c->invokevirtual($env->cp->methodRef('java/lang/Double', 'doubleValue', '()D'), 0, 2);
}

function boxDouble(EmitEnv $env): void
{
    $env->c->invokestatic($env->cp->methodRef('java/lang/Double', 'valueOf', '(D)Ljava/lang/Double;'), 2, 1);
}

/**
 * A round-trippable decimal string for a float64 literal. PHP's default float
 * to string conversion keeps only `precision` significant digits, which drops
 * the low bits (`3.141592653589793` became `3.1415926535898`); `%.17G` always
 * round-trips.
 */
function formatJvmDouble(float $value): string
{
    if (\is_nan($value)) {
        return 'NaN';
    }
    if (\is_infinite($value)) {
        return $value < 0 ? "-Infinity" : "Infinity";
    }

    $text = \sprintf('%.17G', $value);
    if (!\str_contains($text, '.') && !\str_contains($text, 'E') && !\str_contains($text, 'e')) {
        $text .= '.0';
    }

    return $text;
}

/**
 * Stack: result of `dcmpl` squared — 0 iff the two doubles compare equal.
 *
 * `dcmpl` stands for NaN on the lesser side, so a NaN operand is unequal and
 * `-0.0 == 0.0` holds, both as `base`'s `==##` specifies. Squaring keeps the
 * result in {0,1} because `Boolean.valueOf` reads any non-zero int as true.
 */
function emitDoubleCmpFlag(EmitEnv $env, IR\Operand $left, IR\Operand $right): void
{
    emitOperand($env, $left);
    unboxDouble($env);
    emitOperand($env, $right);
    unboxDouble($env);
    $env->c->opcode(0x97, -3); // dcmpl: two doubles → int
    $env->c->dup();
    $env->c->imul();
}

function emitOrderingTagEq(EmitEnv $env, IR\Operand $ord, string $tag): void
{
    emitOperand($env, $ord);
    $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
    $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
    $env->c->ldc($env->cp->string_($tag));
    $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
    $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
}

/**
 * Stack: int cmp (-1/0/1) → Ordering Con.
 *
 * A comparison is an operand and can be emitted while an enclosing argument
 * list is already building its array on the stack; a branch target there would
 * need that whole stack in its StackMapTable frame, so the tag is chosen in the
 * runtime instead of with branches.
 */
function emitCompareIntToOrdering(EmitEnv $env): void
{
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'),
        1,
        true,
    );
}

/** Stack: long left, long right → Ordering Con */
function emitIntCompareToOrdering(EmitEnv $env): void
{
    $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'compare', '(JJ)I'), 4, true);
    emitCompareIntToOrdering($env);
}

function emitOrderingTagNe(EmitEnv $env, IR\Operand $ord, string $tag): void
{
    emitOperand($env, $ord);
    $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
    $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
    $env->c->ldc($env->cp->string_($tag));
    $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
    $env->c->iconst(1);
    $env->c->opcode(0x82, -1); // ixor → not
    $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
}

function emitJvmBitReverse(EmitEnv $env, IR\Operand $arg, int $bits, int $finalMask): void
{
    emitOperand($env, $arg);
    unboxInt($env);
    if ($bits === 64) {
        $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'reverse', '(J)J'), 2, 2);
        boxInt($env);

        return;
    }

    $env->c->l2i();
    $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'reverse', '(I)I'), 1, true);
    if ($bits < 32) {
        $env->c->iconst(32 - $bits);
        $env->c->opcode(0x7c, -1); // iushr
    }
    $env->c->i2l();
    $env->c->ldc2_w($env->cp->long((int) $finalMask));
    $env->c->land();
    boxInt($env);
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
            emitIntPowToStack($env, $args[0], $args[1], $op->srcLoc);
            return;
        case 'doublePow#':
            emitDoublePowToStack($env, $args[0], $args[1], $op->srcLoc);
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
            $env->c->dneg();
            boxDouble($env);
            return;
        case 'doubleEq#':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            $env->c->iconst(1);
            $env->c->swap();
            $env->c->isub(); // 1 - flag: the int compares equal only when the doubles do
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'doubleNe#':
            emitDoubleCmpFlag($env, $args[0], $args[1]);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'doubleCompare#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            emitOperand($env, $args[1]);
            unboxDouble($env);
            // `dcmpg` yields -1/0/1 with NaN on the greater side, which is what
            // `compare` specifies, so only the int → Ordering step is left.
            $env->c->opcode(0x98, -3); // dcmpg: two doubles → int
            emitCompareIntToOrdering($env);
            return;
        case 'doubleAbs#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Math', 'abs', '(D)D'), 2, 2);
            boxDouble($env);
            return;
        case 'doubleSignum#':
            emitOperand($env, $args[0]);
            unboxDouble($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Math', 'signum', '(D)D'), 2, 2);
            boxDouble($env);
            return;
        case 'intNegate#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->lneg();
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
                $env->c->land();
            } elseif ($op->name === 'intOr#') {
                $env->c->opcode(0x81, -2); // lor
            } elseif ($op->name === 'intXor#') {
                $env->c->opcode(0x83, -2); // lxor
            } else {
                $env->c->l2i(); // shift count is in [0,63]
                $env->c->opcode(
                    ['intShiftL#' => 0x79, 'intShiftRA#' => 0x7b, 'intShiftRL#' => 0x7d][$op->name],
                    -1
                );
            }
            boxInt($env);
            return;
        case 'intNot#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->ldc2_w($env->cp->long(-1));
            $env->c->opcode(0x83, -2); // lxor: ~x == x xor -1
            boxInt($env);
            return;
        case 'intPopCnt#':
        case 'intClz#':
        case 'intCtz#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('java/lang/Long', [
                    'intPopCnt#' => 'bitCount',
                    'intClz#' => 'numberOfLeadingZeros',
                    'intCtz#' => 'numberOfTrailingZeros',
                ][$op->name], '(J)I'),
                2,
                1
            );
            $env->c->i2l();
            boxInt($env);
            return;
        case 'wordFromInt#':
        case 'wordToInt#':
            emitOperand($env, $args[0]);
            return;
        case 'intEq#':
        case 'charEq#':
        case 'word8Eq#':
        case 'word16Eq#':
        case 'word32Eq#':
        case 'wordEq#':
        case 'int8Eq#':
        case 'int16Eq#':
        case 'int32Eq#':
        case 'int64Eq#':
        case 'boolEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'intNe#':
        case 'charNe#':
        case 'word8Ne#':
        case 'word16Ne#':
        case 'word32Ne#':
        case 'wordNe#':
        case 'int8Ne#':
        case 'int16Ne#':
        case 'int32Ne#':
        case 'int64Ne#':
        case 'boolNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1); // ixor
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
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
            // Spill both sides before Long.compare: the right operand may
            // itself contain ordering_pick / intCompare# with branches; leaving
            // the left long on the stack corrupts nested StackMapFrames.
            emitOperand($env, $args[0]);
            unboxInt($env);
            $left = $env->freshLocal('long');
            $env->c->lstore($left);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $right = $env->freshLocal('long');
            $env->c->lstore($right);
            $env->c->lload($left);
            $env->c->lload($right);
            emitIntCompareToOrdering($env);
            return;
        case 'boolCompare#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'compare', '(II)I'), 2, true);
            emitCompareIntToOrdering($env);
            return;
        case 'boolAnd#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->opcode(0x7e, -1); // iand
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'boolOr#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->opcode(0x80, -1); // ior
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'boolNot#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1); // ixor
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'stringCompare#':
        case 'bytesCompare#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/String', 'compareTo', '(Ljava/lang/String;)I'), 1, true);
            // compareTo already yields a three-way int; do not feed ints to Long.compare.
            emitCompareIntToOrdering($env);
            return;
        case 'stringNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
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
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'orderingNe#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1); // ixor
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'orderingCompare#':
            // Compare Ordering tags via RT.orderingToInt then int compare → Ordering.
            // `orderingToInt` yields ints, so the pair goes through Integer.compare
            // and the result is one three-way int.
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->invokestatic($env->cp->methodRef('moggi/rt/RT', 'orderingToInt', '(Lmoggi/rt/Con;)I'), 1, true);
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->invokestatic($env->cp->methodRef('moggi/rt/RT', 'orderingToInt', '(Lmoggi/rt/Con;)I'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'compare', '(II)I'), 2, true);
            emitCompareIntToOrdering($env);
            return;
        case 'intAbs#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Math', 'abs', '(J)J'), 2, 2);
            boxInt($env);
            return;
        case 'intSignum#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'signum', '(J)I'), 2, true);
            $env->c->i2l();
            boxInt($env);
            return;
        case 'intFromInteger#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            $env->c->invokevirtual(
                $env->cp->methodRef('java/math/BigInteger', 'longValue', '()J'),
                0,
                2,
            );
            boxInt($env);
            return;
        case 'intToInteger#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('java/math/BigInteger', 'valueOf', '(J)Ljava/math/BigInteger;'),
                2,
                true,
            );
            return;
        case 'integerFromDigits#':
            $env->c->new_($env->cp->class_('java/math/BigInteger'));
            $env->c->dup();
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->invokespecial(
                $env->cp->methodRef('java/math/BigInteger', '<init>', '(Ljava/lang/String;)V'),
                1,
                false,
            );
            return;
        case 'doubleFromInteger#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            $env->c->invokevirtual(
                $env->cp->methodRef('java/math/BigInteger', 'doubleValue', '()D'),
                0,
                2,
            );
            boxDouble($env);
            return;
        case 'ioPure#':
        case 'ioBind#':
            throw new \RuntimeException(
                "intrinsic `{$op->name}` must be erased by strict IO normalization before JVM emit",
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
            // Unsigned 64-bit operations via moggi.rt.Word64 (wrapping / unsigned).
        case 'word64Eq#':
        case 'word64Ne#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $eqName = $op->name === 'word64Ne#' ? 'ne' : 'eq';
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', $eqName, '(JJ)Z'),
                4,
                true,
            );
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
            // `Word` is the unsigned 64-bit type, so its comparison is unsigned too
            // (the bit pattern of a value above `maxBound :: Int` is negative).
        case 'wordCompare#':
        case 'word64Compare#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'compare', '(JJ)I'),
                4,
                true,
            );
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'orderingFromInt', '(I)Lmoggi/rt/Con;'),
                1,
                true,
            );
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
                'word64Add#' => 'add',
                'word64Sub#' => 'subtract',
                'word64Mul#' => 'multiply',
                'word64Quot#' => 'divide',
                'word64Rem#' => 'remainder',
                default => throw new \RuntimeException("Unsupported word64 op: {$op->name}"),
            };
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', $methodName, '(JJ)J'),
                4,
                2,
            );
            boxInt($env);
            return;
        case 'word64ToInteger#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'toBigInteger', '(J)Ljava/math/BigInteger;'),
                2,
                true,
            );
            return;
        case 'word64FromInteger#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'fromBigInteger', '(Ljava/math/BigInteger;)J'),
                1,
                2,
            );
            boxInt($env);
            return;
        case 'word64Show#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Word64', 'toString', '(J)Ljava/lang/String;'),
                2,
                true,
            );
            return;

        case 'word8FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->ldc2_w($env->cp->long(0xff));
            $env->c->land();
            boxInt($env);
            return;
        case 'word8Add#':
        case 'word8Sub#':
        case 'word8Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            match ($op->name) {
                'word8Add#' => $env->c->ladd(),
                'word8Sub#' => $env->c->lsub(),
                default => $env->c->lmul(),
            };
            $env->c->ldc2_w($env->cp->long(0xff));
            $env->c->land();
            boxInt($env);
            return;
        case 'word16FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->ldc2_w($env->cp->long(0xffff));
            $env->c->land();
            boxInt($env);
            return;
        case 'word16Add#':
        case 'word16Sub#':
        case 'word16Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            match ($op->name) {
                'word16Add#' => $env->c->ladd(),
                'word16Sub#' => $env->c->lsub(),
                default => $env->c->lmul(),
            };
            $env->c->ldc2_w($env->cp->long(0xffff));
            $env->c->land();
            boxInt($env);
            return;
        case 'word32FromInt#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->ldc2_w($env->cp->long(0xffffffff));
            $env->c->land();
            boxInt($env);
            return;
        case 'word32Add#':
        case 'word32Sub#':
        case 'word32Mul#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            emitOperand($env, $args[1]);
            unboxInt($env);
            match ($op->name) {
                'word32Add#' => $env->c->ladd(),
                'word32Sub#' => $env->c->lsub(),
                default => $env->c->lmul(),
            };
            $env->c->ldc2_w($env->cp->long(0xffffffff));
            $env->c->land();
            boxInt($env);
            return;
            // Signed fixed-width ints: i64 host rep, sign-extend-truncate after
            // each op (l2i/i2b/i2s/i2l) so values stay normalized for compares.
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
            match ($op->name) {
                'int8Add#', 'int16Add#', 'int32Add#' => $env->c->ladd(),
                'int8Sub#', 'int16Sub#', 'int32Sub#' => $env->c->lsub(),
                'int8Mul#', 'int16Mul#', 'int32Mul#' => $env->c->lmul(),
                default => throw new \RuntimeException("Unsupported fixed-width int op: {$op->name}"),
            };
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
            $env->c->d2l();
            boxInt($env);
            return;
        case 'byteSwap16#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->l2i();
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'reverseBytes', '(I)I'), 1, true);
            $env->c->iconst(16);
            $env->c->opcode(0x7c, -1); // iushr: high 16 bits hold the swapped word16
            $env->c->i2l();
            $env->c->ldc2_w($env->cp->long(0xffff));
            $env->c->land();
            boxInt($env);
            return;
        case 'byteSwap32#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->l2i();
            $env->c->invokestatic($env->cp->methodRef('java/lang/Integer', 'reverseBytes', '(I)I'), 1, true);
            $env->c->i2l();
            boxInt($env);
            return;
        case 'byteSwap64#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'reverseBytes', '(J)J'), 2, 2);
            boxInt($env);
            return;
        case 'bitReverse8#':
            emitJvmBitReverse($env, $args[0], 8, 255);
            return;
        case 'bitReverse16#':
            emitJvmBitReverse($env, $args[0], 16, 65535);
            return;
        case 'bitReverse32#':
            emitJvmBitReverse($env, $args[0], 32, 4294967295);
            return;
        case 'bitReverse64#':
            emitJvmBitReverse($env, $args[0], 64, -1);
            return;
        case 'listCons#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'cons', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/MList;'),
                2,
                true,
            );
            return;
        case 'listHead#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
            return;
        case 'listTail#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
            return;
        case 'listAppend#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'listAppend', '(Lmoggi/rt/MList;Lmoggi/rt/MList;)Lmoggi/rt/MList;'),
                2,
                true,
            );
            return;
        case 'listEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'listEq', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'),
                2,
                true,
            );
            return;
        case 'listNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'listEq', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'),
                2,
                true,
            );
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1); // ixor
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'listCompare#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'listCompare', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/Con;'),
                2,
                true,
            );
            return;
        case 'maybeEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'maybeEq', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'),
                2,
                true,
            );
            return;
        case 'maybeNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'maybeEq', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'),
                2,
                true,
            );
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1); // ixor
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'maybeCompare#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'maybeCompare', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/Con;'),
                2,
                true,
            );
            return;
        case 'stringAppend#':
        case 'bytesAppend#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->invokevirtual(
                $env->cp->methodRef('java/lang/String', 'concat', '(Ljava/lang/String;)Ljava/lang/String;'),
                1,
                true,
            );
            return;
        case 'stringCons#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->l2i();
            $env->c->i2c();
            $env->c->invokestatic(
                $env->cp->methodRef('java/lang/Character', 'toString', '(C)Ljava/lang/String;'),
                1,
                1,
            );
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->invokevirtual(
                $env->cp->methodRef('java/lang/String', 'concat', '(Ljava/lang/String;)Ljava/lang/String;'),
                1,
                true,
            );
            return;

        case 'platformArgv#':
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Platform', 'argv', '()Lmoggi/rt/MList;'),
                0,
                true,
            );
            return;
        case 'stringEq#':
        case 'bytesEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
        case 'bytesNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
            return;
            // Natural# shares the Integer# representation (java.math.BigInteger);
            // the conversion into it is the only constructor and rejects a
            // negative value, so a Natural can never be negative.
        case 'naturalToInteger#':
            emitOperand($env, $args[0]);
            return;
        case 'integerToNatural#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/math/BigInteger'));
            emitJvmPushSrcLoc($env, $op->srcLoc);
            $env->c->invokestatic(
                $env->cp->methodRef(
                    'moggi/rt/RT',
                    'naturalFromInteger',
                    '(Ljava/math/BigInteger;[Ljava/lang/Object;)Ljava/math/BigInteger;',
                ),
                2,
                false,
            );
            return;
        case 'error#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitJvmPushSrcLoc($env, $op->srcLoc);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'throwErrorCall', '(Ljava/lang/String;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            return;
        case 'exceptionWrap#':
            // tag -> display -> payload: the rendered text travels with the
            // exception so a rethrow or an uncaught report can print it without
            // a dictionary.
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[2]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionWrap', '(Ljava/lang/String;Ljava/lang/String;Ljava/lang/Object;)[Ljava/lang/Object;'),
                3,
                true,
            );
            return;
        case 'exceptionUnwrap#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionUnwrap', '(Ljava/lang/String;Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            return;
        case 'exceptionThrow#':
            emitOperand($env, $args[0]);
            emitJvmPushSrcLoc($env, $op->srcLoc);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'throwSomeException', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            return;
        case 'exceptionDisplay#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'exceptionDisplay', '(Ljava/lang/Object;)Ljava/lang/String;'),
                1,
                true,
            );
            return;
        case 'fix#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'fix', '(Ljava/lang/Object;)Ljava/lang/Object;'),
                1,
                true,
            );
            return;
        case 'exceptionThrowIo#':
        case 'exceptionCatch#':
        case 'exceptionFinally#':
            throw new \RuntimeException(
                "JVM emit: intrinsic `{$op->name}` must be lowered to IO exception IR",
            );
        default:
            if (emitPowFamilyToStack($env, $op)) {
                return;
            }
            throw new \RuntimeException("JVM emit: unsupported intrinsic {$op->name}");
    }
}

function emitListLit(EmitEnv $env, IR\ListLit $op): void
{
    $env->c->aconst_null();
    foreach (array_reverse($op->elements) as $item) {
        emitOperand($env, $item);
        $env->c->swap();
        $env->c->invokestatic(
            $env->cp->methodRef('moggi/rt/RT', 'cons', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/MList;'),
            2,
            true,
        );
    }
}

/** @param list<IR\Operand> $args */
function pushObjectArray(EmitEnv $env, array $args): void
{
    $env->c->iconst(count($args));
    $env->c->anewarray($env->cp->class_('java/lang/Object'));
    foreach ($args as $i => $arg) {
        $env->c->dup();
        $env->c->iconst($i);
        emitOperand($env, $arg);
        $env->c->aastore();
    }
}

/**
 * Emit `RT.apply` for the callee already on the stack followed by `$args`.
 *
 * A single argument goes through `RT.apply1`, which takes the scalar directly
 * and so avoids both the caller-side `Object[]` and, inside a partial
 * application, the second array allocation. `f x y z` emits one apply per
 * argument, so this is the shape that dominates curried code.
 */
/**
 * Emit-only foreign intrinsics — no host member is called.
 *
 * `__cast` re-types a value the host already returned: the Moggi ABI keeps
 * every value as an object ref, so the cast is one `checkcast` and the target
 * comes from the descriptor the signature already inferred.
 */
function emitJvmForeignIntrinsic(EmitEnv $env, IR\ForeignCall $op, string $member, string $desc): void
{
    if ($member !== '__cast') {
        throw new \RuntimeException("JVM foreign `{$op->path}`: unknown intrinsic `{$member}`");
    }
    if (count($op->args) !== 1) {
        throw new \RuntimeException("JVM foreign `{$op->path}`: __cast needs one argument");
    }

    emitOperand($env, $op->args[0]);

    $ret = jvmReturnDescriptor($desc);
    if ($ret !== 'Ljava/lang/Object;') {
        if (!str_starts_with($ret, 'L')) {
            throw new \RuntimeException(
                "JVM foreign `{$op->path}`: __cast target `{$ret}` is not a reference type",
            );
        }
        $env->c->checkcast($env->cp->class_(substr($ret, 1, -1)));
    }

    emitForeignIoWrap($env, $op->ioWrap);
}

function emitRuntimeApply(EmitEnv $env, array $args): void
{
    if (count($args) === 1) {
        emitOperand($env, $args[0]);
        $env->c->invokestatic(
            $env->cp->methodRef('moggi/rt/RT', 'apply1', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Object;'),
            2,
            true,
        );

        return;
    }

    pushObjectArray($env, $args);
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'apply', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
        2,
        true,
    );
}

function emitForeign(EmitEnv $env, IR\ForeignCall $op): void
{
    $resolved = resolveJvmForeignPath($op->path);

    $desc = $op->nativeSig;
    if ($desc === null || $desc === '') {
        throw new \RuntimeException(
            "JVM foreign `{$op->path}` missing inferred nativeSig (descriptor)",
        );
    }

    if ($resolved['dispatch'] === 'intrinsic') {
        emitJvmForeignIntrinsic($env, $op, $resolved['member'], $desc);

        return;
    }

    if (($op->kind ?? 'function') === 'const') {
        $env->c->getstatic($env->cp->fieldRef($resolved['class'], $resolved['member'], $desc));
        boxForeignReturn($env, $desc);
        emitForeignIoWrap($env, $op->ioWrap);

        return;
    }

    $argKinds = parseJvmParamKinds($desc);
    $args = $op->args;
    $argOffset = 0;

    if ($resolved['dispatch'] === 'instance') {
        if ($args === []) {
            throw new \RuntimeException("JVM instance foreign `{$op->path}` needs a receiver");
        }
        emitOperand($env, $args[0]);
        $env->c->checkcast($env->cp->class_($resolved['class']));
        $argOffset = 1;
    } elseif ($resolved['dispatch'] === 'constructor') {
        $env->c->new_($env->cp->class_($resolved['class']));
        $env->c->dup();
    }

    $paramArgs = \array_slice($args, $argOffset);
    if (count($paramArgs) !== count($argKinds)) {
        throw new \RuntimeException(
            "JVM foreign `{$op->path}`: arg count " . count($paramArgs)
            . ' does not match descriptor ' . $desc,
        );
    }

    foreach ($paramArgs as $i => $arg) {
        emitOperand($env, $arg);
        unboxForeignArg($env, $argKinds[$i]);
    }

    $retSlots = jvmReturnStackSlots($desc);
    $argc = countJvmArgSlots($argKinds);

    match ($resolved['dispatch']) {
        'static', 'global' => $env->c->invokestatic(
            $env->cp->methodRef($resolved['class'], $resolved['member'], $desc),
            $argc,
            $retSlots,
        ),
        'instance' => jvmIsInterface($resolved['class'])
            ? $env->c->invokeinterface(
                $env->cp->ifaceMethodRef($resolved['class'], $resolved['member'], $desc),
                $argc,
                $retSlots,
            )
            : $env->c->invokevirtual(
                $env->cp->methodRef($resolved['class'], $resolved['member'], $desc),
                $argc,
                $retSlots,
            ),
        'constructor' => (static function () use ($env, $resolved, $desc, $argc): void {
            $env->c->invokespecial(
                $env->cp->methodRef($resolved['class'], '<init>', $desc),
                $argc,
                false,
            );
        })(),
        default => throw new \RuntimeException("JVM foreign: unsupported dispatch {$resolved['dispatch']}"),
    };

    if ($resolved['dispatch'] === 'constructor') {
        emitForeignIoWrap($env, $op->ioWrap);

        return; // object ref already on stack
    }

    if ($retSlots === 0) {
        $env->c->aconst_null();
        emitForeignIoWrap($env, $op->ioWrap);

        return;
    }

    $ret = jvmReturnDescriptor($desc);
    if ($ret === 'Ljava/util/stream/Stream;') {
        $env->c->invokestatic(
            $env->cp->methodRef('moggi/rt/RT', 'streamToList', '(Ljava/util/stream/Stream;)Lmoggi/rt/MList;'),
            1,
            true,
        );
        emitForeignIoWrap($env, $op->ioWrap);

        return;
    }

    boxForeignReturn($env, $ret);
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
            'JVM foreign IoWrap::Either is not implemented; use Moggi try/catch',
        ),
        IR\IoWrap::None => null,
    };
}

/** Host null → Nothing; otherwise Just. */
function emitForeignMaybeStringWrap(EmitEnv $env): void
{
    $tmp = $env->freshLocal();
    $env->c->astore($tmp);
    $id = $env->freshLabelId();
    $just = 'foreign_maybe_just_' . $id;
    $done = 'foreign_maybe_done_' . $id;

    $env->c->aload($tmp);
    $env->c->ifnonnull($just);

    $env->c->ldc($env->cp->string_('Nothing'));
    $env->c->iconst(0);
    $env->c->anewarray($env->cp->class_('java/lang/Object'));
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'),
        2,
        true,
    );
    $env->c->goto_($done);

    $env->c->label($just);
    $env->c->noteFrame($env->frameLocals());
    $env->c->ldc($env->cp->string_('Just'));
    $env->c->iconst(1);
    $env->c->anewarray($env->cp->class_('java/lang/Object'));
    $env->c->dup();
    $env->c->iconst(0);
    $env->c->aload($tmp);
    $env->c->aastore();
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'),
        2,
        true,
    );
    $env->c->label($done);
}

/** @param list<string> $kinds */
function countJvmArgSlots(array $kinds): int
{
    $n = 0;
    foreach ($kinds as $k) {
        $n += ($k === 'D' || $k === 'J') ? 2 : 1;
    }

    return $n;
}

/** @return list<string> each param type descriptor (I, Z, Ljava/lang/String;, …) */
function parseJvmParamKinds(string $desc): array
{
    if ($desc[0] !== '(') {
        return []; // field descriptor
    }
    $i = 1;
    $kinds = [];
    $n = strlen($desc);
    while ($i < $n && $desc[$i] !== ')') {
        $start = $i;
        $c = $desc[$i];
        if ($c === 'L') {
            $semi = strpos($desc, ';', $i);
            if ($semi === false) {
                throw new \RuntimeException("bad JVM descriptor: {$desc}");
            }
            $i = $semi + 1;
        } elseif ($c === '[') {
            $i++;
            while ($i < $n && $desc[$i] === '[') {
                $i++;
            }
            if ($i < $n && $desc[$i] === 'L') {
                $semi = strpos($desc, ';', $i);
                $i = $semi === false ? $n : $semi + 1;
            } else {
                $i++;
            }
        } else {
            $i++;
        }
        $kinds[] = substr($desc, $start, $i - $start);
    }

    return $kinds;
}

function jvmReturnDescriptor(string $methodDesc): string
{
    $pos = strrpos($methodDesc, ')');
    if ($pos === false) {
        return $methodDesc;
    }

    return substr($methodDesc, $pos + 1);
}

function jvmReturnStackSlots(string $methodDesc): int
{
    $ret = jvmReturnDescriptor($methodDesc);

    return match ($ret) {
        'V' => 0,
        'J', 'D' => 2,
        default => 1,
    };
}

function unboxForeignArg(EmitEnv $env, string $kind): void
{
    match ($kind) {
        // JDK int: Moggi Int/Char are Long — Number.intValue truncates safely for Char.
        'I' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Number'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Number', 'intValue', '()I'), 0, true);
        })(),
        // Fixed-width Int8/Int16: truncate through int.
        'B' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Number'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Number', 'intValue', '()I'), 0, true);
            $env->c->i2b();
        })(),
        'S' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Number'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Number', 'intValue', '()I'), 0, true);
            $env->c->i2s();
        })(),
        'Z' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
        })(),
        'D' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Double'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Double', 'doubleValue', '()D'), 0, 2);
        })(),
        'J' => unboxInt($env),
        'F' => (static function () use ($env): void {
            $env->c->checkcast($env->cp->class_('java/lang/Float'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Float', 'floatValue', '()F'), 0, true);
        })(),
        default => (static function () use ($env, $kind): void {
            if (str_starts_with($kind, 'L') && str_ends_with($kind, ';')) {
                $internal = substr($kind, 1, -1);
                if ($internal !== 'java/lang/Object') {
                    $env->c->checkcast($env->cp->class_($internal));
                }
            }
        })(),
    };
}

function boxForeignReturn(EmitEnv $env, string $retOrFieldDesc): void
{
    $ret = str_starts_with($retOrFieldDesc, '(')
        ? jvmReturnDescriptor($retOrFieldDesc)
        : $retOrFieldDesc;

    match ($ret) {
        // JDK int → Moggi Int (Long)
        'I', 'B', 'S', 'C' => (static function () use ($env): void {
            $env->c->i2l();
            boxInt($env);
        })(),
        'Z' => $env->c->invokestatic(
            $env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'),
            1,
            true,
        ),
        'D' => $env->c->invokestatic(
            $env->cp->methodRef('java/lang/Double', 'valueOf', '(D)Ljava/lang/Double;'),
            2,
            1,
        ),
        'F' => $env->c->invokestatic(
            $env->cp->methodRef('java/lang/Float', 'valueOf', '(F)Ljava/lang/Float;'),
            1,
            true,
        ),
        'J' => boxInt($env),
        'V' => $env->c->aconst_null(),
        default => null, // already a reference
    };
}

function emitMatchReturn(EmitEnv $env, IR\MatchReturn $stmt): void
{
    // Nested MatchReturn must not inherit MatchStmt yieldDest: otherwise Ret
    // branches to this match's join label, which is omitted when dest=null.
    $prevDest = $env->matchYieldDest;
    $prevJoin = $env->matchJoinLabel;
    $env->matchYieldDest = null;
    emitMatchCommon($env, $stmt->scrutinee, $stmt->arms, null, true);
    $env->matchYieldDest = $prevDest;
    $env->matchJoinLabel = $prevJoin;
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
    $env->c->astore($scrut);
    $matchId = $env->freshLabelId();
    $end = 'match_end_' . $matchId;
    $prevJoin = $env->matchJoinLabel;
    $env->matchJoinLabel = $end;
    $needsEndLabel = $dest !== null;
    if ($dest !== null) {
        $env->localSlot('t' . $dest, 'top');
    }
    $armId = 0;
    foreach ($arms as $arm) {
        $savedLocals = $env->locals;
        $savedLocalTypes = $env->localTypes;
        $savedNextLocal = $env->nextLocal;
        $fail = 'match_fail_' . $matchId . '_' . $armId;
        ++$armId;
        emitPatternTest($env, $arm->pattern, $scrut, $fail);
        // A guard is tested after the pattern bound its variables, and a guard
        // that does not hold tries the next arm -- the same `$fail` a failed
        // pattern leaves through.
        foreach ($arm->guards as $guard) {
            // Statements the guard needs (`| ok (g x) = …`) run first: the arm
            // is only left through `$fail`, so a guard that does not hold tries
            // the next arm exactly like a failed pattern does.
            emitBlock($env, $guard->prep);
            emitOperand($env, $guard->cond);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->ifeq($fail);
        }
        $endsWithRet = jvmBlockTerminates($arm->body);
        emitBlock($env, $arm->body);
        if (!$endsWithRet) {
            $needsEndLabel = true;
            $env->c->goto_($end);
        }
        $env->c->label($fail);
        $env->locals = $savedLocals;
        $env->localTypes = $savedLocalTypes;
        $env->nextLocal = $savedNextLocal;
        $env->c->noteFrame($env->frameLocals());
    }
    $env->c->new_($env->cp->class_('java/lang/RuntimeException'));
    $env->c->dup();
    $env->c->ldc($env->cp->string_('non-exhaustive match'));
    $env->c->invokespecial($env->cp->methodRef('java/lang/RuntimeException', '<init>', '(Ljava/lang/String;)V'), 1, false);
    $env->c->athrow();
    $env->matchJoinLabel = $prevJoin;
    if (!$needsEndLabel) {
        return;
    }
    $env->c->label($end);
    if ($dest !== null) {
        $env->localSlot('t' . $dest);
    }
    $env->c->noteFrame($env->frameLocals());
    if ($dest !== null) {
        return;
    }
    if (!$returnNullOnNoDest) {
        return;
    }
    $env->c->aconst_null();
    emitJvmFunctionReturn($env);
}

function emitJvmPushSrcLoc(EmitEnv $env, ?IR\SrcLoc $loc): void
{
    if ($loc === null) {
        $env->c->aconst_null();

        return;
    }
    noteJvmLoc($env, $loc);
    if ($env->sourceMap !== null) {
        $site = $env->sourceMap->allocSite($loc, symbolName($loc->function));
        $env->c->iconst((int) $site['siteId']);
        $env->c->ldc($env->cp->string_($site['symbolId']));
        $env->c->ldc($env->cp->string_($site['displayPath']));
        $env->c->iconst((int) $site['line']);
        $env->c->iconst((int) $site['col']);
    } else {
        $symbolId = symbolId($loc->module, $loc->function);
        $display = normalizeDisplayPath($loc->file);
        $env->c->iconst(0);
        $env->c->ldc($env->cp->string_($symbolId));
        $env->c->ldc($env->cp->string_($display));
        $env->c->iconst($loc->line);
        $env->c->iconst($loc->col);
    }
    $env->c->invokestatic(
        $env->cp->methodRef(
            'moggi/rt/RT',
            'throwSite',
            '(ILjava/lang/String;Ljava/lang/String;II)[Ljava/lang/Object;',
        ),
        5,
        true,
    );
}

function emitJvmFunctionReturn(EmitEnv $env): void
{
    $env->c->areturn();
}

function emitPatternTest(EmitEnv $env, IR\Pattern $pat, int $scrutLocal, string $failLabel): void
{
    if ($pat instanceof IR\PatWild) {
        return;
    }
    if ($pat instanceof IR\PatVar) {
        $env->locals[$pat->name] = $scrutLocal;

        return;
    }
    if ($pat instanceof IR\PatNil) {
        $env->c->aload($scrutLocal);
        $env->c->ifnonnull($failLabel);

        return;
    }
    if ($pat instanceof IR\PatLit) {
        // IR\PatLit carries int|string (e.g. derived Read `case s of "Ctor" → …`).
        if (\is_string($pat->value)) {
            $env->c->aload($scrutLocal);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->ldc($env->cp->string_($pat->value));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->ifeq($failLabel);

            return;
        }
        $env->c->aload($scrutLocal);
        unboxInt($env);
        pushRawLong($env, (int) $pat->value);
        $env->c->lcmp();
        $env->c->ifne($failLabel);

        return;
    }
    if ($pat instanceof IR\PatChar) {
        $env->c->aload($scrutLocal);
        unboxInt($env);
        pushRawLong($env, $pat->value);
        $env->c->lcmp();
        $env->c->ifne($failLabel);

        return;
    }
    if ($pat instanceof IR\PatCon) {
        // Boolean may be java.lang.Boolean; ADTs are moggi.rt.Con
        if ($pat->name === 'True' || $pat->name === 'False') {
            $env->c->aload($scrutLocal);
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            if ($pat->name === 'True') {
                $env->c->ifeq($failLabel);
            } else {
                $env->c->ifne($failLabel);
            }

            return;
        }
        if (isset($env->newtypeConstructors[$pat->name])) {
            // Newtype: identity representation — bind the single field to the scrutinee.
            foreach ($pat->args as $sub) {
                if ($sub instanceof IR\PatWild) {
                    continue;
                }
                if ($sub instanceof IR\PatVar) {
                    $env->locals[$sub->name] = $scrutLocal;
                } else {
                    emitPatternTest($env, $sub, $scrutLocal, $failLabel);
                }
            }

            return;
        }
        $env->c->aload($scrutLocal);
        $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
        $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'tag', 'Ljava/lang/String;'));
        $env->c->ldc($env->cp->string_($pat->name));
        $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
        $env->c->ifeq($failLabel);
        foreach ($pat->args as $i => $sub) {
            if ($sub instanceof IR\PatWild) {
                continue;
            }
            $env->c->aload($scrutLocal);
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
            $env->c->iconst($i);
            $env->c->aaload();
            if ($sub instanceof IR\PatVar) {
                $slot = $env->freshLocal();
                $env->locals[$sub->name] = $slot;
                $env->c->astore($slot);
            } else {
                $nested = $env->freshLocal();
                $env->c->astore($nested);
                emitPatternTest($env, $sub, $nested, $failLabel);
            }
        }

        return;
    }
    if ($pat instanceof IR\PatCons) {
        $env->c->aload($scrutLocal);
        $env->c->ifnull($failLabel);
        $env->c->aload($scrutLocal);
        $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
        $env->c->getfield($env->cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));
        $headSlot = $env->freshLocal();
        $env->c->astore($headSlot);
        emitPatternTest($env, $pat->head, $headSlot, $failLabel);
        $env->c->aload($scrutLocal);
        $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
        $env->c->getfield($env->cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));
        $tailSlot = $env->freshLocal();
        $env->c->astore($tailSlot);
        emitPatternTest($env, $pat->tail, $tailSlot, $failLabel);

        return;
    }
    if ($pat instanceof IR\PatTuple) {
        foreach ($pat->elements as $i => $element) {
            if ($element instanceof IR\PatWild) {
                continue;
            }
            $env->c->aload($scrutLocal);
            $env->c->checkcast($env->cp->class_('[Ljava/lang/Object;'));
            $env->c->iconst($i);
            $env->c->aaload();
            if ($element instanceof IR\PatVar) {
                $slot = $env->freshLocal();
                $env->locals[$element->name] = $slot;
                $env->c->astore($slot);
            } else {
                $nested = $env->freshLocal();
                $env->c->astore($nested);
                emitPatternTest($env, $element, $nested, $failLabel);
            }
        }

        return;
    }

    throw new \RuntimeException('JVM emit: unsupported pattern ' . $pat::class);
}

function pushRawInt(EmitEnv $env, int $v): void
{
    if ($v >= -1 && $v <= 5) {
        $env->c->iconst($v);
    } elseif ($v >= -128 && $v <= 127) {
        $env->c->bipush($v);
    } elseif ($v >= -32768 && $v <= 32767) {
        $env->c->sipush($v);
    } else {
        $env->c->ldc($env->cp->integer($v));
    }
}

function pushRawLong(EmitEnv $env, int $v): void
{
    if ($v === 0 || $v === 1) {
        $env->c->lconst($v);
    } elseif ($v >= -32768 && $v <= 32767) {
        pushRawInt($env, $v);
        $env->c->i2l();
    } else {
        $env->c->ldc2_w($env->cp->long($v));
    }
}

function emitDataConstructors(ClassBuilder $b, IR\DataDecl $decl): void
{
    if (isBoolBackedData($decl)) {
        $falseName = symbolName($decl->constructors[0]->name);
        $trueName = symbolName($decl->constructors[1]->name);
        $b->addMethod(
            $falseName,
            '()Ljava/lang/Object;',
            0x0009,
            0,
            [],
            static function (CodeBuilder $c, ConstantPool $cp): void {
                $c->iconst(0);
                $c->invokestatic($cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
                $c->areturn();
            }
        );
        $b->addMethod(
            $trueName,
            '()Ljava/lang/Object;',
            0x0009,
            0,
            [],
            static function (CodeBuilder $c, ConstantPool $cp): void {
                $c->iconst(1);
                $c->invokestatic($cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
                $c->areturn();
            }
        );

        return;
    }

    if ($decl->isNewtype) {
        $ctor = $decl->constructors[0];
        $name = symbolName($ctor->name);
        $b->addMethod(
            $name,
            '(Ljava/lang/Object;)Ljava/lang/Object;',
            0x0009,
            1,
            ['java/lang/Object'],
            static function (CodeBuilder $c, ConstantPool $cp): void {
                $c->aload(0);
                $c->areturn();
            }
        );

        return;
    }

    foreach ($decl->constructors as $ctor) {
        $name = symbolName($ctor->name);
        $arity = count($ctor->fields);
        $desc = '(' . str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
        $b->addMethod(
            $name,
            $desc,
            0x0009,
            max($arity + 2, 4),
            \array_fill(0, $arity, 'java/lang/Object'),
            static function (CodeBuilder $c, ConstantPool $cp) use ($ctor, $arity): void {
                $c->ldc($cp->string_($ctor->name));
                $c->iconst($arity);
                $c->anewarray($cp->class_('java/lang/Object'));
                for ($i = 0; $i < $arity; ++$i) {
                    $c->dup();
                    $c->iconst($i);
                    $c->aload($i);
                    $c->aastore();
                }
                $c->invokestatic($cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'), 2, true);
                $c->areturn();
            }
        );
    }
}

/**
 * IR after bindLambdaCaptures already includes capture args on Partial/Call.
 * Do not prepend lambdaMeta captures again (that caused unknown-local emit
 * failures and Partial arity -1 → NegativeArraySizeException).
 *
 * @param list<IR\Operand> $args
 * @return list<IR\Operand>
 */
function jvmCallArgs(EmitEnv $env, string $name, array $args): array
{
    return $args;
}

function jvmLambdaHasFreeLocals(IR\FunctionDecl $fn): bool
{
    $bound = \array_fill_keys($fn->params, true);
    foreach ($fn->body->items as $stmt) {
        if ($stmt instanceof IR\Let) {
            $bound[$stmt->name] = true;
        }
        if ($stmt instanceof IR\Assign) {
            $bound['t' . $stmt->dest] = true;
        }
        if ($stmt instanceof IR\Call && $stmt->dest !== null) {
            $bound['t' . $stmt->dest] = true;
        }
        if ($stmt instanceof IR\Binop) {
            $bound['t' . $stmt->dest] = true;
        }
    }

    return jvmBlockRefsUnboundLocal($fn->body, $bound);
}

/** @param array<string, true> $bound */
function jvmBlockRefsUnboundLocal(IR\Block $block, array $bound): bool
{
    foreach ($block->items as $stmt) {
        if (jvmStmtRefsUnboundLocal($stmt, $bound)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, true> $bound */
function jvmStmtRefsUnboundLocal(IR\Stmt $stmt, array $bound): bool
{
    if ($stmt instanceof IR\Ret) {
        return jvmOperandRefsUnboundLocal($stmt->value, $bound);
    }
    if ($stmt instanceof IR\Assign) {
        return jvmOperandRefsUnboundLocal($stmt->value, $bound);
    }
    if ($stmt instanceof IR\Let) {
        return jvmOperandRefsUnboundLocal($stmt->value, $bound);
    }
    if ($stmt instanceof IR\Call) {
        foreach ($stmt->args as $arg) {
            if (jvmOperandRefsUnboundLocal($arg, $bound)) {
                return true;
            }
        }

        return false;
    }
    if ($stmt instanceof IR\MatchReturn) {
        if (jvmOperandRefsUnboundLocal($stmt->scrutinee, $bound)) {
            return true;
        }
        foreach ($stmt->arms as $arm) {
            $armBound = $bound;
            if ($arm->pattern instanceof IR\PatVar) {
                $armBound[$arm->pattern->name] = true;
            }
            if (jvmBlockRefsUnboundLocal($arm->body, $armBound)) {
                return true;
            }
        }
    }

    return false;
}

/** @param array<string, true> $bound */
function jvmOperandRefsUnboundLocal(IR\Operand $op, array $bound): bool
{
    if ($op instanceof IR\Local) {
        return !isset($bound[$op->name]);
    }
    if ($op instanceof IR\Intrinsic || $op instanceof IR\ExprCall || $op instanceof IR\ForeignCall || $op instanceof IR\ListLit) {
        foreach ($op->args ?? [] as $arg) {
            if ($arg instanceof IR\Operand && jvmOperandRefsUnboundLocal($arg, $bound)) {
                return true;
            }
        }
    }
    if ($op instanceof IR\ExprBinop) {
        return jvmOperandRefsUnboundLocal($op->left, $bound) || jvmOperandRefsUnboundLocal($op->right, $bound);
    }

    return false;
}
