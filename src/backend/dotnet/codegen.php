<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;

require_once __DIR__ . '/codegen.php';
require_once __DIR__ . '/emit_env.php';
require_once __DIR__ . '/emit_stmt.php';
require_once __DIR__ . '/emit_expr.php';
require_once __DIR__ . '/emit_call.php';
require_once __DIR__ . '/emit_fn_value.php';
require_once __DIR__ . '/emit_value.php';
require_once __DIR__ . '/emit_literal.php';
require_once __DIR__ . '/emit_intrinsic.php';
require_once __DIR__ . '/emit_primop.php';
require_once __DIR__ . '/emit_foreign.php';
require_once __DIR__ . '/emit_match.php';
require_once __DIR__ . '/emit_pattern.php';
require_once __DIR__ . '/emit_data.php';
require_once __DIR__ . '/emit_io.php';
require_once __DIR__ . '/naming.php';
require_once __DIR__ . '/../managed/setup.php';
require_once __DIR__ . '/foreign.php';
require_once __DIR__ . '/il.php';
require_once __DIR__ . '/il_size.php';


use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;
use Moggi\Modules\EmitPlan;
use function Moggi\Backend\DotNet\Il\ilString;
use function Moggi\Backend\DotNet\Naming\moduleTypeName;
use function Moggi\Backend\DotNet\Naming\symbolName;
use function Moggi\Backend\Managed\managedModuleFacts;
use function Moggi\Backend\Meta\constructorArityMap;
use function Moggi\Backend\Meta\constructorArityMapFromRegistry;
use function Moggi\Backend\Meta\newtypeConstructorMap;
use function Moggi\Backend\Meta\newtypeConstructorMapFromRegistry;
use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\IR\Callables\arityBySymbol;
use function Moggi\IR\Callables\captureMeta;
use function Moggi\IR\Callables\table;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Modules\resolvedSymbol;
use function Moggi\Optimize\Support\indexCapturedFunctions;

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
    $moduleName = $options['artifactModuleName'] ?? '';
    $owner = moduleTypeName($moduleName !== '' ? $moduleName : 'Main');
    $classBuilder = new ClassIlBuilder($owner);

    [
        $displaySource,
        $artifact,
        $sourceMap,
        $lambdaMeta,
        $functionArity,
        $externalFns,
        $localCallables,
        $newtypeConstructors,
    ] = managedModuleFacts($module, $sourcePath, $options, 'dotnet', $owner . '.il');

    $wrapperRegistry = new FnWrapperRegistry();
    $ioThunkRegistry = new IoThunkRegistry();

    foreach ($module->functions as $fn) {
        $sourceMap->ensureSymbol(
            $fn->srcLoc ?? new IR\SrcLoc($moduleName, $fn->name, $displaySource, 0, 0),
            symbolName($fn->name),
        );
        emitFunction($classBuilder, $fn, $owner, $functionArity, $externalFns, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $sourceMap, $localCallables);
    }

    foreach ($module->data as $decl) {
        emitDataDecl($classBuilder, $decl);
    }

    foreach ($module->instanceEvidence as $ev) {
        emitEvidence($classBuilder, $ev, $owner, $functionArity, $externalFns, $wrapperRegistry, $localCallables);
    }

    if ($module->entry !== null && $module->entry->kind === IR\EntryPointKind::Main) {
        emitMain(
            $classBuilder,
            $owner,
            symbolName($module->entry->name),
            $functionArity[$module->entry->name] ?? 0,
            entryReturnsAction($module),
        );
    }

    emitPendingIoThunkMethods($classBuilder, $owner, $functionArity, $externalFns, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $localCallables);

    $types = [$owner => $classBuilder->toIl()];
    foreach ($wrapperRegistry->specs() as $spec) {
        $types[$spec['typeName']] = buildFnWrapperClass($spec['typeName'], $spec['targetOwner'], $spec['targetSym'], $spec['arity']);
    }
    $types['__moggi.map__'] = $sourceMap->toJson();

    return $types;
}

// --- IL text builders (symbolic locals/labels; no verifier stack-map pass). ---

// --- Function / evidence / entry emission -------------------------------------

/**
 * @param array<string, int> $functionArity
 * @param array<string, string> $externalFns
 * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
 * @param array<string, true> $localCallables
 */
function emitFunction(
    ClassIlBuilder $b,
    IR\FunctionDecl $fn,
    string $owner,
    array $functionArity,
    array $externalFns,
    array $lambdaMeta,
    FnWrapperRegistry $wrapperRegistry,
    IoThunkRegistry $ioThunkRegistry,
    array $newtypeConstructors = [],
    ?SourceMapBuilder $sourceMap = null,
    array $localCallables = [],
): void {
    $name = symbolName($fn->name);
    $params = $fn->params;
    if ($fn->shape->kind->takesCaptures()) {
        $params = [
            ...($lambdaMeta[$fn->name]['captures'] ?? []),
            ...$params,
        ];
    }
    $arity = \count($params);

    $m = new MethodIl();
    $env = new EmitEnv($m, $owner, $functionArity, $externalFns, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $sourceMap, $localCallables);
    foreach ($params as $i => $p) {
        $env->locals[$p] = 'arg:' . $i;
    }
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
    emitFunctionReturn($env);

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
    $env = new EmitEnv($m, $owner, $functionArity, $externalFns, [], $wrapperRegistry, null, [], null, $localCallables);
    foreach ($ev->contextParams as $i => $p) {
        $env->locals[$p] = 'arg:' . $i;
    }

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

/** Whether the module's entry answers with an action value (its published convention). */
function entryReturnsAction(IR\Module $module): bool
{
    foreach ($module->functions as $function) {
        if ($function->name === $module->entry?->name) {
            return $function->shape->actionConvention === IR\ActionConvention::ReturnsAction;
        }
    }

    throw new \LogicException('the entry `' . ($module->entry?->name ?? '?') . '` has no lowered declaration');
}

/**
 * DotNet entry bridge for `Main.main :: IO a` (result discarded at runtime).
 *
 * Calls the Moggi entry. If it returns an IO action, run it via RT.IoRun.
 * Straight-line foreign IO may already have run eagerly (returns Unit/null);
 * in that case there is nothing left to execute.
 */
function emitMain(ClassIlBuilder $b, string $owner, string $mainSym, int $arity, bool $returnsAction): void
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
    if ($returnsAction) {
        $m->emit('call object Moggi.Rt.RT::IoRun(object)');
    }
    $m->emit('pop');
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

