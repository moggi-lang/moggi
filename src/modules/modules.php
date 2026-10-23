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
use function Moggi\Backend\Jvm\Foreign\jvmInterfaceNamesFromPrograms;
use function Moggi\IR\Dump\dump as dumpIr;
use function Moggi\IR\Validate\validateCallableLayouts;
use function Moggi\IR\Visit\collectIrCodegenUsage;
use function Moggi\Optimize\Specialize\specializeAcrossModules;
use function Moggi\Optimize\TreeShake\treeShakeModules;
use function Moggi\Pipeline\run as pipelineRun;

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
            . '|out=emit',
    );
    $cached = Cache\artifactGet($outputsKey);
    if (\is_array($cached) && isset($cached['emit'], $cached['opt-ir'])) {
        $cached['entryModule'] ??= null;
        $cached['entryRelative'] ??= null;

        return $cached;
    }

    $outputs = compilePreparedProject(prepareProjectCached($paths, $rootDir, $context), $context, $optimize, $strip);
    Cache\artifactPut($outputsKey, $outputs);

    return $outputs;
}

/**
 * @return array{
 *   emit: array<string, string>,
 *   opt-ir: array<string, string>,
 *   entryModule: ?string,
 *   entryRelative: ?string
 * }
 *
 * Every artifact path is spelled by `outputRelativePath()` — the same function
 * the import contexts were planned with — so the tree a module is written to and
 * the `require`s that count their way back to `_runtime.php` from inside it are
 * one spelling. They are two independent computations of the same thing, and a
 * root prefix that only one of them can subtract (a drive root on Windows) used
 * to leave them disagreeing, which put every generated file at one depth and
 * every require at another.
 */
function compilePreparedProject(PreparedProject $prepared, CompilationContext $context, bool $optimize = true, bool $strip = false): array
{
    $optByModule = [];
    $relativeByModule = [];
    foreach ($prepared->units as $moduleName => $unit) {
        if (($unit['synthetic'] ?? false) === true) {
            continue;
        }
        if (! isset($prepared->checked[$moduleName], $prepared->importContexts[$moduleName])) {
            continue;
        }

        $relative = outputRelativePath($unit['path'], $prepared->rootPrefix);
        $relativeByModule[$moduleName] = $relative;

        $irCacheKey = isset($unit['cacheRelPath'])
            ? $context->moduleKey(
                $moduleName,
                ($unit['contentKey'] ?? '') . '|opt=' . ($optimize ? '1' : '0'),
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
            typedProgram: $prepared->checked[$moduleName],
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
            $externalFnsByModule[$moduleName] = $importContext['interface']->externalFns;
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
            $prepared->importContexts[$moduleName]['interface']->callableArity(),
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
        $lambdaMeta = \Moggi\IR\Callables\captureMeta(
            \Moggi\IR\Callables\captureCandidates($optimizedIr->functions),
        );
        foreach ($optimizedIr->functions as $fn) {
            $arity = \count($fn->params);
            if ($fn->kind->takesCaptures()) {
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
    $entryModule = null;
    $entryRelative = null;
    foreach ($optByModule as $moduleName => $optimizedIr) {
        $unit = $prepared->units[$moduleName];
        $relative = $relativeByModule[$moduleName];
        $outputRelative = \preg_replace('/\.mog$/', $ext, $relative) ?? $relative;
        $importContext = $prepared->importContexts[$moduleName];
        $irUsage = collectIrCodegenUsage($optimizedIr);

        $optIrOutputs[\preg_replace('/\.mog$/', '.opt-ir', $relative) ?? $relative] = dumpIr($optimizedIr);

        if ($optimizedIr->entry === null && ! moduleDeclaresCode($optimizedIr)) {
            continue;
        }

        $plan = $importContext['plan'];
        if ($context->backendId === 'php') {
            $plan = $plan->withModuleOutputPaths($allModuleOutputPaths);
        }

        $plan = planEmission(
            $importContext['interface'],
            $plan,
            $optimizedIr,
            $irUsage['callees'],
            $outputRelative,
        );

        $emitted = codegenWith($context->backend, $optimizedIr, $unit['path'], [
            'namespace' => $unit['namespace'],
            'plan' => $plan,
            'outputRelative' => $outputRelative,
            'irUsage' => $irUsage,
            'artifactModuleName' => $moduleName,
            'globalFnArity' => $globalFnArity,
            'foreignInterfaces' => $foreignInterfaces,
        ]);

        if ($optimizedIr->entry !== null && $optimizedIr->entry->kind === EntryPointKind::Main) {
            $entryModule = $moduleName;
            $entryRelative = \preg_replace('/\.mog$/', $ext, $relative) ?? $relative;
        }

        if (\is_array($emitted)) {
            foreach ($emitted as $path => $bytes) {
                $emitOutputs[$path] = $bytes;
            }
        } else {
            $emitRelative = \preg_replace('/\.mog$/', $ext, $relative) ?? $relative;
            $emitOutputs[$emitRelative] = $emitted;
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
    $interface = $moduleContext['importContext']['interface'];
    $plan = $moduleContext['importContext']['plan'];
    if ($context->backendId === 'php') {
        $plan = $plan->withModuleOutputPaths(moduleOutputPathsForUnits(
            $moduleContext['units'],
            $moduleContext['rootPrefix'],
            $context->backendId,
        ));
    }

    $stdlibPhpRoot = detectStdlibPhpPath($outputFile);
    if ($stdlibPhpRoot !== null) {
        $plan = $plan->withModuleOutputPaths(remapStdlibModuleOutputPaths(
            $plan->moduleOutputPaths,
            $moduleContext['units'],
            $stdlibPhpRoot,
            $projectRoot,
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
 *   importContext: array<string, mixed>,
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
