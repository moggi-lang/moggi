<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Cache;
use Moggi\IR\EntryPointKind;
use Moggi\IR\Module;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Pipeline\PipelineRequest;
use Moggi\Pipeline\PipelineStage;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Backend\codegen;
use function Moggi\Backend\compileBackend;
use function Moggi\Backend\currentBackend;
use function Moggi\IR\Dump\dump as dumpIr;
use function Moggi\IR\Visit\freeLocalsInBlock;
use function Moggi\IR\Visit\collectIrCodegenUsage;
use function Moggi\Modules\resolvedSymbol;
use function Moggi\Optimize\Specialize\specializeAcrossModules;
use function Moggi\Optimize\Support\isCapturedFnName;
use function Moggi\Optimize\TreeShake\treeShakeModules;
use function Moggi\Pipeline\run as pipelineRun;
use function Moggi\Syntax\Ast\dump;

/**
 * Module compilation entry points: whole-project and single-file compiles,
 * built on top of `prepareProject`/`prepareProjectCached`.
 */

/** @param list<string> $paths @return array<string, string> relative output path => emitted artifact */
function compileProject(array $paths, string $rootDir, bool $optimize = true, bool $strip = false): array
{
    return compileProjectBoth($paths, $rootDir, $optimize, $strip)['emit'];
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
function compileProjectBoth(array $paths, string $rootDir, bool $optimize = true, bool $strip = false): array
{
    // Emitted output is a pure function of the prepared closure + opt level, so
    // cache it directly and skip lower/optimize/codegen for the whole project on
    // a warm hit (the build path emits every module).
    $outputsKey = Cache\hashContent(
        preparedProjectDiskKey($paths, $rootDir, null)
            . '|opt=' . ($optimize ? '1' : '0')
            . '|strip=' . ($strip ? '1' : '0')
            . '|backend=' . compileBackend()
            . '|out=emit',
    );
    $cached = Cache\artifactGet($outputsKey);
    if (\is_array($cached) && isset($cached['emit'], $cached['opt-ir'])) {
        $cached['entryModule'] ??= null;
        $cached['entryRelative'] ??= null;

        return $cached;
    }

    $outputs = compilePreparedProject(prepareProjectCached($paths, $rootDir), $optimize, $strip);
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
 */
function compilePreparedProject(PreparedProject $prepared, bool $optimize = true, bool $strip = false): array
{
    $optByModule = [];
    $relativeByModule = [];
    foreach ($prepared->units as $moduleName => $unit) {
        if (($unit['synthetic'] ?? false) === true) {
            continue;
        }
        if (!isset($prepared->checked[$moduleName], $prepared->importContexts[$moduleName])) {
            continue;
        }

        $relative = substr($unit['path'], strlen($prepared->rootPrefix));
        $relativeByModule[$moduleName] = $relative;
        $purpose = $moduleName === 'Main'
            ? CompilePurpose::Executable
            : CompilePurpose::Library;

        $irCacheKey = isset($unit['cacheRelPath'])
            ? ($unit['contentKey'] ?? '') . '|opt=' . ($optimize ? '1' : '0') . '|' . $purpose->name
            : null;
        if ($irCacheKey !== null) {
            $cachedIr = Cache\moduleGet($unit['cacheRelPath'], 'ir', $irCacheKey);
            if ($cachedIr instanceof Module) {
                $optByModule[$moduleName] = $cachedIr;
                continue;
            }
        }

        $artifacts = pipelineRun(new PipelineRequest(
            purpose: $purpose,
            filename: $unit['path'],
            typedProgram: $prepared->checked[$moduleName],
            optimize: $optimize,
            importContext: $prepared->importContexts[$moduleName],
        ), PipelineStage::IrOpt);
        $optByModule[$moduleName] = $artifacts->irOpt
            ?? throw new \RuntimeException("pipeline produced no IR for module `{$moduleName}`");
        if ($irCacheKey !== null) {
            Cache\modulePut($unit['cacheRelPath'], 'ir', $irCacheKey, $optByModule[$moduleName]);
        }
    }

    if ($optimize) {
        $externalFnsByModule = [];
        foreach ($prepared->importContexts as $moduleName => $importContext) {
            $codegen = $importContext['codegen'] ?? [];
            $externalFnsByModule[$moduleName] = \is_array($codegen['externalFns'] ?? null)
                ? $codegen['externalFns']
                : [];
        }
        $specialized = specializeAcrossModules($optByModule, $externalFnsByModule);
        $optByModule = $specialized['modules'];
    }

    if ($strip) {
        $optByModule = treeShakeModules($optByModule)['modules'];
    }

    // Whole-program evidence maps for JVM DictCall static resolution across modules.
    $globalEvidenceMaps = [];
    foreach ($optByModule as $moduleName => $optimizedIr) {
        foreach ($optimizedIr->instanceEvidence as $ev) {
            $globalEvidenceMaps[$ev->evidenceName] = [
                'module' => $moduleName,
                'methods' => $ev->methods,
            ];
        }
    }

    $globalFnArity = [];
    foreach ($optByModule as $moduleName => $optimizedIr) {
        $lambdaMeta = [];
        foreach ($optimizedIr->functions as $fn) {
            if (!isCapturedFnName($fn->name)) {
                continue;
            }
            $lambdaMeta[$fn->name] = [
                'captures' => freeLocalsInBlock($fn->body, array_fill_keys($fn->params, true)),
                'params' => $fn->params,
            ];
        }
        foreach ($optimizedIr->functions as $fn) {
            $arity = count($fn->params);
            if (isCapturedFnName($fn->name)) {
                $arity += count($lambdaMeta[$fn->name]['captures'] ?? []);
            }
            $globalFnArity[resolvedSymbol((string) $moduleName, $fn->name)] = $arity;
        }
    }

    $emitOutputs = [];
    $optIrOutputs = [];
    $ext = currentBackend()->extension();
    $allModuleOutputPaths = [];
    foreach ($relativeByModule as $mn => $rel) {
        $allModuleOutputPaths[$mn] = preg_replace('/\.mog$/', $ext, $rel) ?? $rel;
    }
    $entryModule = null;
    $entryRelative = null;
    foreach ($optByModule as $moduleName => $optimizedIr) {
        $unit = $prepared->units[$moduleName];
        $relative = $relativeByModule[$moduleName];
        $outputRelative = preg_replace('/\.mog$/', $ext, $relative) ?? $relative;
        $importContext = $prepared->importContexts[$moduleName];
        $irUsage = collectIrCodegenUsage($optimizedIr);

        $optIrOutputs[preg_replace('/\.mog$/', '.opt-ir', $relative) ?? $relative] = dumpIr($optimizedIr);

        // Nothing to emit and nothing that could name it: optimized away (an inlined prelude leaf),
        // and a module with no declarations cannot be referenced by another module's code.
        if ($optimizedIr->entry === null && !moduleDeclaresCode($optimizedIr)) {
            continue;
        }

        $codegenForFilter = $importContext['codegen'];
        $codegenForFilter['moduleOutputPaths'] = array_merge(
            $allModuleOutputPaths,
            $codegenForFilter['moduleOutputPaths'] ?? [],
        );

        $codegenImports = filterCodegenImportsForIr(
            $optimizedIr,
            $codegenForFilter,
            null,
            $irUsage['callees'],
        );
        $codegenImports['importedData'] = $importContext['data'];

        $emitted = codegen($optimizedIr, $unit['path'], [
            'namespace' => $unit['namespace'],
            'imports' => $codegenImports,
            'outputRelative' => $outputRelative,
            'irUsage' => $irUsage,
            'moduleName' => $moduleName,
            'externalFns' => $codegenImports['externalFns'] ?? [],
            'externalFnRuntimeArity' => $importContext['externalFnRuntimeArity'] ?? [],
            'globalEvidenceMaps' => $globalEvidenceMaps,
            'globalFnArity' => $globalFnArity,
        ]);

        if ($optimizedIr->entry !== null && $optimizedIr->entry->kind === EntryPointKind::Main) {
            $entryModule = $moduleName;
            $entryRelative = preg_replace('/\.mog$/', $ext, $relative) ?? $relative;
        }

        if (\is_array($emitted)) {
            foreach ($emitted as $path => $bytes) {
                $emitOutputs[$path] = $bytes;
            }
        } else {
            $emitRelative = preg_replace('/\.mog$/', $ext, $relative) ?? $relative;
            $emitOutputs[$emitRelative] = $emitted;
        }
    }

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
    string $mode = 'php',
    bool $optimize = true,
    ?string $outputFile = null,
    ?Ast\Program $routingProgram = null,
): string|array {
    if ($mode === 'ast' || $mode === 'ir') {
        $context = moduleCompileContext($path, $routingProgram);
        $purpose = ($context['checked']->module === 'Main')
            ? CompilePurpose::Executable
            : CompilePurpose::Library;
        $artifacts = pipelineRun(new PipelineRequest(
            purpose: $purpose,
            filename: $path,
            typedProgram: $context['checked'],
            optimize: false,
            importContext: $context['importContext'],
        ), $mode === 'ast' ? PipelineStage::TypedAst : PipelineStage::Ir);

        return match ($mode) {
            'ast' => dump($artifacts->typedAst ?? $context['checked']),
            'ir' => dumpIr($artifacts->ir ?? throw new \RuntimeException('missing IR')),
        };
    }

    $outputs = compileModuleFileOutputs($path, $optimize, $outputFile, $routingProgram);

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
    bool $optimize = true,
    ?string $outputFile = null,
    ?Ast\Program $routingProgram = null,
): array {
    $context = moduleCompileContext($path, $routingProgram);
    $purpose = ($context['checked']->module === 'Main')
        ? CompilePurpose::Executable
        : CompilePurpose::Library;
    $artifacts = pipelineRun(new PipelineRequest(
        purpose: $purpose,
        filename: $path,
        typedProgram: $context['checked'],
        optimize: $optimize,
        importContext: $context['importContext'],
    ), PipelineStage::IrOpt);
    $phpIr = $artifacts->irOpt
        ?? throw new \RuntimeException('pipeline produced no optimized IR');
    $projectRoot = projectRootFromPath($path);
    $fromOutputRelative = $outputFile !== null
        ? pathWithinProject($outputFile, $projectRoot)
        : $context['outputRelative'];
    $codegen = $context['importContext']['codegen'];
    $stdlibPhpRoot = detectStdlibPhpPath($outputFile);
    if ($stdlibPhpRoot !== null) {
        $codegen['moduleOutputPaths'] = remapStdlibModuleOutputPaths(
            $codegen['moduleOutputPaths'],
            $context['units'],
            $stdlibPhpRoot,
            $projectRoot,
        );
    }

    $irUsage = collectIrCodegenUsage($phpIr);
    $codegenImports = filterCodegenImportsForIr(
        $phpIr,
        $codegen,
        $fromOutputRelative,
        $irUsage['callees'],
    );
    $codegenImports['importedData'] = $context['importContext']['data'];

    ProjectCache::clearPreparedProjects();

    return [
        'emit' => codegen($phpIr, $context['unit']['path'], [
            'namespace' => $context['unit']['namespace'],
            'imports' => $codegenImports,
            'outputRelative' => $fromOutputRelative,
            'irUsage' => $irUsage,
            'moduleName' => $context['checked']->module,
            'externalFns' => $codegenImports['externalFns'] ?? [],
            'externalFnRuntimeArity' => $context['importContext']['externalFnRuntimeArity'] ?? [],
        ]),
        'opt-ir' => dumpIr($phpIr),
    ];
}

/**
 * @return array{
 *   unit: array<string, mixed>,
 *   checked: Ast\Program,
 *   importContext: array<string, mixed>,
 *   outputRelative: string,
 *   units: array<string, array<string, mixed>>
 * }
 */
function moduleCompileContext(string $path, ?Ast\Program $routingProgram = null): array
{
    [$files, $root] = moduleFileClosureCached($path);
    $realPath = resolvePath($path);
    $targetModule = $routingProgram?->module;

    if ($targetModule === null && \is_file($realPath)) {
        $targetModule = cachedModuleHeader($realPath)['module'] ?? null;
    }

    $prepared = prepareProjectCached($files, $root, $targetModule);

    if ($targetModule === null) {
        throw new TypeError("file `{$path}` is not part of module project under `{$root}`");
    }

    $unit = $prepared->units[$targetModule];
    $outputRelative = mogPathToOutputRelative($unit['path'], $prepared->rootPrefix);

    return [
        'unit' => $unit,
        'checked' => $prepared->checked[$targetModule],
        'importContext' => $prepared->importContexts[$targetModule],
        'outputRelative' => $outputRelative,
        'units' => $prepared->units,
    ];
}
