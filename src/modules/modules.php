<?php declare(strict_types=1);

namespace Moggi\Modules;

/**
 * Module compilation entry points: whole-project and single-file compiles,
 * built on top of `prepareProject`/`prepareProjectCached`.
 */

use Moggi\Cache;
use Moggi\IR\EntryPointKind;
use Moggi\IR\Module;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\PipelineRequest;
use Moggi\Pipeline\PipelineStage;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Backend\codegenWith;
use function Moggi\Semantics\Foreign\programDeclaresForeign;
use function Moggi\Backend\Jvm\Foreign\jvmInterfaceNamesFromPrograms;
use function Moggi\IR\Callables\captureCandidates;
use function Moggi\IR\Callables\captureMeta;
use function Moggi\IR\Dump\dump as dumpIr;
use function Moggi\IR\Validate\validateCallableLayouts;
use function Moggi\IR\Visit\collectIrCodegenUsage;
use function Moggi\Optimize\Specialize\specializeAcrossModules;
use function Moggi\Optimize\TreeShake\treeShakeModules;
use function Moggi\Pipeline\run as pipelineRun;

/**
 * The shape of a cached project product.
 *
 * Part of the artifact key, not a field patched on read: a product written by another shape is a
 * different key, so a reader either gets a record of exactly this shape or prepares the project.
 * Every field the product promises is present, including the two that may be null.
 */
const PROJECT_PRODUCT_SHAPE = 2;

/** @param list<string> $paths @return array<string, string> relative output path => emitted artifact */
function compileProject(array $paths, string $rootDir, CompilationContext $context, bool $optimize = true, bool $strip = false): array
{
    return compileProjectBoth($paths, $rootDir, $context, $optimize, $strip)['emit'];
}

/**
 * @param list<string> $paths
 * @return array{
 *   emit: array<string, string>,
 *   opt-ir: array<string, string>,
 *   entryModule: ?string,
 *   entryRelative: ?string
 * }
 */
function compileProjectBoth(array $paths, string $rootDir, CompilationContext $context, bool $optimize = true, bool $strip = false): array
{
    $outputsKey = Cache\hashContent(
        preparedProjectDiskKey($paths, $rootDir, $context)
            . '|opt=' . ($optimize ? '1' : '0')
            . '|strip=' . ($strip ? '1' : '0')
            . '|emit=' . $context->backend->emitCacheDiscriminator()
            . '|context=' . $context->cacheKey()
            . '|out=emit'
            . '|shape=' . PROJECT_PRODUCT_SHAPE,
    );
    $cached = Cache\artifactGet($outputsKey);
    if (\is_array($cached)) {
        return $cached;
    }

    /*
     * Releasing a checked program is what keeps a whole-project compile inside the
     * memory floor, and it is sound only where the module cache can hand the program
     * back. A compile with the cache off (`--no-cache`, `MOGGI_NO_CACHE=1`, a
     * read-only cache root) keeps its products in memory — the behaviour this call
     * had before releasing existed — instead of refusing to compile at all.
     */
    $outputs = compilePreparedProject(
        prepareProjectCached($paths, $rootDir, $context, releaseChecked: Cache\cacheEnabled()),
        $context,
        $optimize,
        $strip,
    );
    Cache\artifactPut($outputsKey, $outputs);

    return $outputs;
}

/**
 * Compile a prepared project into backend artifacts and optimized IR.
 *
 * @return array{
 *   emit: array<string, string>,
 *   opt-ir: array<string, string>,
 *   entryModule: ?string,
 *   entryRelative: ?string
 * }
 */
function compilePreparedProject(PreparedProject $prepared, CompilationContext $context, bool $optimize = true, bool $strip = false): array
{
    $optByModule = [];
    $relativeByModule = [];
    foreach ($prepared->units as $moduleName => $unit) {
        if (($unit['synthetic'] ?? false) === true) {
            continue;
        }
        if (! isset($prepared->importContexts[$moduleName]) || ! $prepared->has($moduleName)) {
            continue;
        }

        $relative = outputRelativePath($unit['path'], $prepared->rootPrefix);
        $relativeByModule[$moduleName] = $relative;

        $program = ($unit['program'] ?? null) instanceof Ast\Program ? $unit['program'] : null;
        $irCacheKey = isset($unit['cacheRelPath'])
            ? $context->moduleProductKey(
                $moduleName,
                ($unit['contentKey'] ?? '') . '|opt=' . ($optimize ? '1' : '0'),
                $program !== null && programDeclaresForeign($program),
            )
            : null;
        if ($irCacheKey !== null) {
            $cachedIr = Cache\moduleGet($unit['cacheRelPath'], 'ir', $irCacheKey, $context->backendId);
            if ($cachedIr instanceof Module) {
                $optByModule[$moduleName] = $cachedIr;

                continue;
            }
        }

        $artifacts = pipelineRun(new PipelineRequest(
            filename: $unit['path'],
            typedProgram: $prepared->program($moduleName, $context),
            optimize: $optimize,
            importContext: $prepared->importContexts[$moduleName],
            context: $context,
        ), PipelineStage::IrOpt);
        $optByModule[$moduleName] = $artifacts->irOpt
            ?? throw new \RuntimeException("pipeline produced no IR for module `{$moduleName}`");
        if ($irCacheKey !== null) {
            Cache\modulePut($unit['cacheRelPath'], 'ir', $irCacheKey, $optByModule[$moduleName], $context->backendId);
        }
    }

    if ($optimize) {
        $externalFnsByModule = [];
        foreach ($prepared->importContexts as $moduleName => $importContext) {
            $externalFnsByModule[$moduleName] = $importContext->interface->externalFns();
        }
        $specialized = specializeAcrossModules($optByModule, $externalFnsByModule);
        $optByModule = $specialized['modules'];
    }

    if ($strip) {
        $optByModule = treeShakeModules($optByModule)['modules'];
    }

    foreach ($optByModule as $moduleName => $optimizedIr) {
        validateCallableLayouts(
            $optimizedIr,
            'post-specialize',
            $prepared->importContexts[$moduleName]->interface->callableArity(),
        );
    }

    $foreignInterfaces = $context->backendId === 'jvm'
        ? jvmInterfaceNamesFromPrograms(\array_filter(\array_map(
            static fn (array $unit): ?Ast\Program => $unit['program'] ?? null,
            \array_values($prepared->units),
        )))
        : [];

    $globalFnArity = [];
    foreach ($optByModule as $moduleName => $optimizedIr) {
        $lambdaMeta = captureMeta(
            captureCandidates($optimizedIr->functions),
        );
        foreach ($optimizedIr->functions as $fn) {
            $arity = \count($fn->params);
            if ($fn->shape->kind->takesCaptures()) {
                $arity += \count($lambdaMeta[$fn->name]['captures'] ?? []);
            }
            $globalFnArity[resolvedSymbol((string) $moduleName, $fn->name)] = $arity;
        }
    }

    $emitOutputs = [];
    $optIrOutputs = [];
    $ext = $context->backend->extension();
    $allModuleOutputPaths = [];
    foreach ($relativeByModule as $mn => $rel) {
        $allModuleOutputPaths[$mn] = \preg_replace('/\.mog$/', $ext, $rel) ?? $rel;
    }

    $candidateOutputPaths = [];
    foreach ($optByModule as $moduleName => $optimizedIr) {
        if ($optimizedIr->entry === null && ! moduleDeclaresCode($optimizedIr)) {
            continue;
        }
        $candidateOutputPaths[$moduleName] = $allModuleOutputPaths[$moduleName]
            ?? throw new \LogicException("module `{$moduleName}` has no artifact to write");
    }

    /** @var array<string, array{unit: array<string, mixed>, outputRelative: string, plan: EmitPlan, ir: Module, irUsage: array{callees: array<string, true>, intrinsics: array<string, true>}}> $plans */
    $plans = [];
    $entryModule = null;
    $entryRelative = null;
    foreach ($optByModule as $moduleName => $optimizedIr) {
        $relative = $relativeByModule[$moduleName];
        $optIrOutputs[\preg_replace('/\.mog$/', '.opt-ir', $relative) ?? $relative] = dumpIr($optimizedIr);

        if (! isset($candidateOutputPaths[$moduleName])) {
            continue;
        }

        $unit = $prepared->units[$moduleName];
        $outputRelative = $candidateOutputPaths[$moduleName];
        $importContext = $prepared->importContexts[$moduleName];
        $irUsage = collectIrCodegenUsage($optimizedIr);

        $plan = $importContext->plan;
        if ($context->backendId === 'php') {
            $plan = $plan->withModuleOutputPaths($candidateOutputPaths);
        }

        $plans[$moduleName] = [
            'unit' => $unit,
            'outputRelative' => $outputRelative,
            'plan' => planEmission(
                $importContext->interface,
                $plan,
                $optimizedIr,
                $irUsage['callees'],
                $outputRelative,
            ),
            'ir' => $optimizedIr,
            'irUsage' => $irUsage,
        ];

        if ($optimizedIr->entry !== null && $optimizedIr->entry->kind === EntryPointKind::Main) {
            if ($entryModule !== null) {
                throw new \LogicException(
                    "this compilation has two entry modules, `{$entryModule}` and `{$moduleName}`; "
                    . 'a project compiles one `main`',
                );
            }
            $entryModule = $moduleName;
            $entryRelative = $outputRelative;
        }
    }

    $written = \array_keys($plans);
    if ($context->backendId === 'php' && $entryModule !== null) {
        $written = emittedClosure(
            \array_map(static fn (array $planned): EmitPlan => $planned['plan'], $plans),
            $entryModule,
        );
    }

    foreach ($written as $moduleName) {
        $planned = $plans[$moduleName];
        $unit = $planned['unit'];
        $optimizedIr = $planned['ir'];
        $irUsage = $planned['irUsage'];
        $outputRelative = $planned['outputRelative'];

        $emitted = codegenWith($context->backend, $optimizedIr, $unit['path'], [
            'namespace' => $unit['namespace'],
            'plan' => $planned['plan'],
            'outputRelative' => $outputRelative,
            'irUsage' => $irUsage,
            'artifactModuleName' => $moduleName,
            'globalFnArity' => $globalFnArity,
            'foreignInterfaces' => $foreignInterfaces,
        ]);

        if (\is_array($emitted)) {
            foreach ($emitted as $path => $bytes) {
                $emitOutputs[$path] = $bytes;
            }
        } else {
            $emitOutputs[$outputRelative] = $emitted;
        }
    }

    $emitOutputs = $context->backend->foldProgramEmit($emitOutputs);

    return [
        'emit' => $emitOutputs,
        'opt-ir' => $optIrOutputs,
        'entryModule' => $entryModule,
        'entryRelative' => $entryRelative,
    ];
}

/**
 * The modules a compile writes: the entry and everything its plan's require lines reach.
 *
 * This is the one place that decides what the tree holds. An emitted require can only name a
 * module this returns, and a module it returns is reachable from the entry, so a written tree is
 * the entry's own closure and no artifact can point at a file the same compile did not write.
 *
 * @param array<string, EmitPlan> $planByModule
 * @return list<string>
 * @throws \LogicException when a plan requires a module this compile does not emit
 */
function emittedClosure(array $planByModule, string $entryModule): array
{
    if (! isset($planByModule[$entryModule])) {
        throw new \LogicException("the entry module `{$entryModule}` has no emission plan");
    }

    $reachable = [$entryModule => true];
    $pending = [$entryModule];
    while ($pending !== []) {
        $moduleName = \array_pop($pending);
        foreach (\array_keys($planByModule[$moduleName]->requiredModules) as $dependency) {
            if (isset($reachable[$dependency])) {
                continue;
            }
            if (! isset($planByModule[$dependency])) {
                throw new \LogicException(
                    "module `{$moduleName}` requires `{$dependency}`, which this compilation does not emit",
                );
            }
            $reachable[$dependency] = true;
            $pending[] = $dependency;
        }
    }

    return \array_values(\array_filter(
        \array_keys($planByModule),
        static fn (string $moduleName): bool => isset($reachable[$moduleName]),
    ));
}

function moduleDeclaresCode(Module $module): bool
{
    return $module->functions !== []
        || $module->data !== []
        || $module->instanceEvidence !== [];
}

/** @throws ParseError|TypeError|\RuntimeException @return string|array<string, string> */
function compileModuleFile(
    string $path,
    CompilationContext $context,
    string $mode = 'php',
    bool $optimize = true,
    ?string $outputFile = null,
    ?Ast\Program $routingProgram = null,
): string|array {
    if ($mode === 'ir') {
        $moduleContext = moduleCompileContext($path, $context, $routingProgram);
        $artifacts = pipelineRun(new PipelineRequest(
            filename: $path,
            typedProgram: $moduleContext['typedProgram'],
            optimize: false,
            importContext: $moduleContext['importContext'],
            context: $context,
        ), PipelineStage::Ir);

        return dumpIr($artifacts->ir ?? throw new \RuntimeException('missing IR'));
    }

    $outputs = compileModuleFileOutputs($path, $context, $optimize, $outputFile, $routingProgram);

    return match ($mode) {
        'php', 'emit' => $outputs['emit'],
        'opt-ir' => $outputs['opt-ir'],
        default => throw new \InvalidArgumentException("unknown output mode `{$mode}`"),
    };
}

/**
 * @return array{emit: string|array<string, string>, opt-ir: string}
 */
function compileModuleFileOutputs(
    string $path,
    CompilationContext $context,
    bool $optimize = true,
    ?string $outputFile = null,
    ?Ast\Program $routingProgram = null,
): array {
    $moduleContext = moduleCompileContext($path, $context, $routingProgram);
    $artifacts = pipelineRun(new PipelineRequest(
        filename: $path,
        typedProgram: $moduleContext['typedProgram'],
        optimize: $optimize,
        importContext: $moduleContext['importContext'],
        context: $context,
    ), PipelineStage::IrOpt);
    $phpIr = $artifacts->irOpt
        ?? throw new \RuntimeException('pipeline produced no optimized IR');
    $projectRoot = projectRootFromPath($path);
    $fromOutputRelative = $outputFile !== null
        ? pathWithinProject($outputFile, $projectRoot)
        : $moduleContext['outputRelative'];
    $interface = $moduleContext['importContext']->interface;
    $plan = $moduleContext['importContext']->plan;
    if ($context->backendId === 'php') {
        $plan = $plan->withModuleOutputPaths(moduleOutputPathsForUnits(
            $moduleContext['units'],
            $moduleContext['rootPrefix'],
            $context->backendId,
        ));
    }

    $irUsage = collectIrCodegenUsage($phpIr);
    $plan = planEmission(
        $interface,
        $plan,
        $phpIr,
        $irUsage['callees'],
        $fromOutputRelative,
    );

    ProjectCache::releaseProjectCheckedModules();

    $emit = codegenWith($context->backend, $phpIr, $moduleContext['unit']['path'], [
        'namespace' => $moduleContext['unit']['namespace'],
        'plan' => $plan,
        'outputRelative' => $fromOutputRelative,
        'irUsage' => $irUsage,
        'artifactModuleName' => $moduleContext['typedProgram']->module,
    ]);

    return [
        'emit' => \is_array($emit) ? $context->backend->foldProgramEmit($emit) : $emit,
        'opt-ir' => dumpIr($phpIr),
    ];
}

/**
 * @return array{
 *   unit: array<string, mixed>,
 *   checked: Ast\Program,
 *   importContext: ImportContext,
 *   outputRelative: string,
 *   rootPrefix: string,
 *   units: array<string, array<string, mixed>>
 * }
 */
function moduleCompileContext(string $path, CompilationContext $context, ?Ast\Program $routingProgram = null): array
{
    [$files, $root] = moduleFileClosureCached($path, $context->backendId);
    $realPath = resolvePath($path);
    $targetModule = $routingProgram?->module;

    if ($targetModule === null && \is_file($realPath)) {
        $targetModule = cachedModuleHeader($realPath)['module'] ?? null;
    }

    $prepared = prepareProjectCached($files, $root, $context, $targetModule);

    if ($targetModule === null) {
        throw new TypeError("file `{$path}` is not part of module project under `{$root}`");
    }

    $unit = $prepared->units[$targetModule];
    $outputRelative = mogPathToOutputRelative(
        $unit['path'],
        $prepared->rootPrefix,
        $context->backend->extension(),
    );

    return [
        'unit' => $unit,
        'typedProgram' => $prepared->checked[$targetModule],
        'importContext' => $prepared->importContexts[$targetModule],
        'outputRelative' => $outputRelative,
        'rootPrefix' => $prepared->rootPrefix,
        'units' => $prepared->units,
    ];
}
