<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;

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
require_once __DIR__ . '/classfile.php';
require_once __DIR__ . '/naming.php';
require_once __DIR__ . '/foreign.php';
require_once __DIR__ . '/../managed/setup.php';


use Moggi\Backend\Jvm\Classfile\ClassBuilder;
use Moggi\Backend\Jvm\Classfile\CodeBuilder;
use Moggi\Backend\Jvm\Classfile\ConstantPool;
use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;
use Moggi\Modules\EmitPlan;
use function Moggi\Backend\Jvm\Naming\moduleInternalName;
use function Moggi\Backend\Jvm\Naming\symbolName;
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
 * @param array<string, mixed> $options
 * @return array<string, string>
 */
function emitModule(IR\Module $module, string $sourcePath, array $options = []): array
{
    $moduleName = $options['artifactModuleName'] ?? '';
    $internal = moduleInternalName($moduleName !== '' ? $moduleName : 'Main');
    $b = new ClassBuilder($internal);

    [
        $displaySource,
        $artifact,
        $sourceMap,
        $lambdaMeta,
        $functionArity,
        $externalFns,
        $localCallables,
        $newtypeConstructors,
    ] = managedModuleFacts($module, $sourcePath, $options, 'jvm', $internal . '.class');
    if ($displaySource !== '') {
        $b->setSourceFile($displaySource);
    }

    $wrapperRegistry = new FnWrapperRegistry();
    $ioThunkRegistry = new IoThunkRegistry();

    $foreignInterfaces = $options['foreignInterfaces'] ?? [];

    foreach ($module->functions as $fn) {
        $sourceMap->ensureSymbol(
            $fn->srcLoc ?? new IR\SrcLoc($moduleName, $fn->name, $displaySource, 0, 0),
            symbolName($fn->name),
        );
        emitFunction($b, $fn, $internal, $functionArity, $externalFns, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $sourceMap, $localCallables, $foreignInterfaces);
    }

    foreach ($module->data as $decl) {
        emitDataDecl($b, $decl);
    }

    foreach ($module->instanceEvidence as $ev) {
        emitEvidence($b, $ev, $internal, $functionArity, $externalFns, $wrapperRegistry, $localCallables);
    }

    if ($module->entry !== null && $module->entry->kind === IR\EntryPointKind::Main) {
        emitMain(
            $b,
            $internal,
            symbolName($module->entry->name),
            $functionArity[$module->entry->name] ?? 0,
            entryReturnsAction($module),
        );
    }

    emitPendingIoThunkMethods($b, $internal, $functionArity, $externalFns, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $localCallables, $foreignInterfaces);

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
 * @param array<string, string> $externalFns * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
 * @param array<string, true> $localCallables
 * @param array<string, true> $foreignInterfaces interface owners this compilation declares
 */
function emitFunction(
    ClassBuilder $b,
    IR\FunctionDecl $fn,
    string $owner,
    array $functionArity,
    array $externalFns,
    array $lambdaMeta = [],
    ?FnWrapperRegistry $wrapperRegistry = null,
    ?IoThunkRegistry $ioThunkRegistry = null,
    array $newtypeConstructors = [],
    ?SourceMapBuilder $sourceMap = null,
    array $localCallables = [],
    array $foreignInterfaces = [],
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
    $desc = '(' . \str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
    $maxLocals = \max($arity + 32, 16);
    $paramV = \array_fill(0, $arity, 'java/lang/Object');

    $b->addMethod(
        $name,
        $desc,
        0x0009,
        $maxLocals,
        $paramV,
        static function (CodeBuilder $c, ConstantPool $cp) use ($fn, $name, $owner, $functionArity, $externalFns, $lambdaMeta, $params, $arity, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $sourceMap, $localCallables, $foreignInterfaces): void {
            $env = new EmitEnv($c, $cp, $owner, $functionArity, $externalFns, $arity, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, $sourceMap, $localCallables, $foreignInterfaces);
            $env->currentMethod = $name;
            foreach ($params as $i => $p) {
                $env->locals[$p] = $i;
            }
            $env->tailRecSlots = \array_map(
                static fn (string $p): int => $env->locals[$p],
                $fn->params,
            );
            noteSrcLoc($env, $fn->srcLoc);

            try {
                emitBlock($env, $fn->body);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException($e->getMessage() . " while emitting `{$fn->name}`", 0, $e);
            }
            $env->c->noteFrame($env->frameLocals($arity));
            $env->c->aconst_null();
            emitFunctionReturn($env);

            if ($env->sourceMap !== null) {
                foreach ($env->c->lineTable() as $entry) {
                    $loc = $env->locByLine[$entry['line']] ?? null;
                    if ($loc !== null) {
                        $env->sourceMap->addFrame($env->owner, $name, $loc);
                    }
                }
            }
        }
    );
}

function emitEvidence(
    ClassBuilder $b,
    IR\InstanceEvidence $ev,
    string $owner,
    array $functionArity,
    array $externalFns,
    ?FnWrapperRegistry $wrapperRegistry = null,
    array $localCallables = [],
): void {
    $name = symbolName($ev->evidenceName);
    $ctxArity = \count($ev->contextParams);
    $pairs = [];
    foreach ($ev->methods as $surface => $irName) {
        $arity = $functionArity[$irName] ?? null;
        if ($arity === null) {
            throw new \RuntimeException("JVM emit: evidence method `{$irName}` has unknown arity");
        }
        $target = resolveCallableTarget($owner, $externalFns, $irName, null, $localCallables);
        $pairs[] = [
            'surface' => $surface,
            'owner' => $target['owner'],
            'sym' => $target['sym'],
            'arity' => $arity,
        ];
    }
    $desc = '(' . \str_repeat('Ljava/lang/Object;', $ctxArity) . ')Ljava/lang/Object;';
    $paramV = \array_fill(0, $ctxArity, 'java/lang/Object');
    $methodsLocal = $ctxArity;
    $maxLocals = \max($ctxArity + 2, 4);
    $b->addMethod(
        $name,
        $desc,
        0x0009,
        $maxLocals,
        $paramV,
        static function (CodeBuilder $c, ConstantPool $cp) use ($pairs, $wrapperRegistry, $ctxArity, $methodsLocal): void {
            $n = \count($pairs) * 2;
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
                    ?? fnWrapperName($entry['owner'], $entry['sym'], $entry['arity']);
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
                    $c->astore($methodsLocal + 1);
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
 * JVM entry bridge for `Main.main :: IO a` (result discarded at runtime).
 *
 * Calls the Moggi entry. If it returns an IO action, run it via RT.ioRun.
 * Straight-line foreign IO may already have run eagerly (returns Unit/null);
 * in that case there is nothing left to execute.
 */
function emitMain(ClassBuilder $b, string $owner, string $mainFn, int $arity, bool $returnsAction): void
{
    $b->addMethod(
        'main',
        '([Ljava/lang/String;)V',
        0x0009,
        3,
        ['[Ljava/lang/String;'],
        static function (CodeBuilder $c, ConstantPool $cp) use ($owner, $mainFn, $arity, $returnsAction): void {
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
            if ($returnsAction) {
                $c->invokestatic($cp->methodRef('moggi/rt/RT', 'ioRun', '(Ljava/lang/Object;)Ljava/lang/Object;'), 1, true);
            }
            $c->pop_();
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

