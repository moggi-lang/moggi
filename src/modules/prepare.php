<?php declare(strict_types=1);

namespace Moggi\Modules;

use function Moggi\Backend\compileBackend;

use Moggi\Cache;

use function Moggi\Paths\canonicalPath;
use function Moggi\Paths\canonicalSeparators;

use function Moggi\Paths\moduleNameToPath;
use function Moggi\Semantics\Effects\checkAndNormalize;
use function Moggi\Semantics\Types\clearInternedTypeNodes;

use Moggi\Pipeline\CompilationContext;

use Moggi\Semantics\Types\TypeError;

use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\importedFixityForImports;
use function Moggi\Syntax\Parser\mergeFixity;
use function Moggi\Syntax\Parser\parse;

/**
 * Whole-project preparation: parses, type-checks, and caches every module in
 * a closure, producing a `PreparedProject` ready to be lowered and emitted.
 */

/**
 * @param list<string> $paths
 * @param bool $shallow when true, build the import closure without loading
 *   checked programs: hydrated interfaces already carry final inferred
 *   schemes plus the elaboration facts (`facts` blob) importers read off
 *   checked programs, and the focus path that consumes a shallow base never
 *   lowers its modules. Skips straight to a fresh check when an interface is
 *   missing, so a cold cache still typechecks everything once.
 */
function prepareProject(array $paths, string $rootDir, CompilationContext $context, bool $shallow = false, bool $widened = false): PreparedProject
{
    $root = \realpath($rootDir);
    if ($root === false) {
        throw new \RuntimeException("cannot resolve project root {$rootDir}");
    }

    $rootPrefix = \rtrim(canonicalSeparators($root), '/') . '/';
    $units = [];
    $fixityByModule = [];
    $pending = [];

    foreach ($paths as $path) {
        $header = cachedModuleHeader($path);
        $source = $header['__source'] ?? null;
        if (! \is_string($source)) {
            $source = \file_get_contents($path);
            if ($source === false) {
                throw new \RuntimeException("cannot read {$path}");
            }
        }

        $localFixity = $header['localFixity'];
        $moduleName = $header['module'] ?? null;
        if ($moduleName === null) {
            throw new TypeError("missing `module` declaration in {$path}", $path, $source);
        }

        if (isset($pending[$moduleName])) {
            if (canonicalPath($pending[$moduleName]['path']) === canonicalPath($path)) {
                continue;
            }
            $span = $header['headerSpan'] ?? null;

            throw new TypeError(
                "duplicate module `{$moduleName}` in project ("
                . canonicalSeparators($pending[$moduleName]['path']) . ' and ' . canonicalSeparators($path) . ')',
                $path,
                $source,
                (int) ($span['line'] ?? 1),
                (int) ($span['col'] ?? 1),
                (int) ($span['endCol'] ?? 1),
            );
        }

        $fixityByModule[$moduleName] = $localFixity;
        $pending[$moduleName] = [
            'path' => $path,
            'source' => $source,
            'tokens' => null,
            'imports' => $header['imports'],
            'backendMap' => $header['backendMap'] ?? [],
            'localFixity' => $localFixity,
            'implicitMain' => (bool) ($header['implicitMain'] ?? false),
            'language' => $header['language'] ?? [],
            'headerSpan' => $header['headerSpan'] ?? null,
        ];
    }

    foreach ($pending as $moduleName => $info) {
        $pending[$moduleName]['imports'] = injectPreludeImport(
            $info['imports'],
            $info['path'],
            \array_fill_keys(\array_keys($pending), true),
            $info['language'] ?? [],
        );
    }

    $effectiveFixityByModule = computeEffectiveFixityByModule($pending, $fixityByModule);

    foreach ($pending as $moduleName => $info) {
        $importedFixity = importedFixityForImports($info['imports'], $effectiveFixityByModule);
        if (! ($info['implicitMain'] ?? false)) {
            $warning = moduleFilenameWarning($info['path'], $moduleName);
            if ($warning !== null) {
                \fwrite(STDERR, "warning: {$warning}\n");
            }
        }

        $units[$moduleName] = [
            'path' => $info['path'],
            'source' => $info['source'],
            'tokens' => $info['tokens'],
            'imports' => $info['imports'],
            'backendMap' => $info['backendMap'] ?? [],
            'headerSpan' => $info['headerSpan'] ?? null,
            'namespace' => moduleNameToNamespace($moduleName),
            'fixity' => mergeFixity($importedFixity, $fixityByModule[$moduleName]),
            'importedFixity' => $importedFixity,
            'localFixity' => $info['localFixity'],
            'program' => null,
            'parsed' => false,
        ];
    }

    injectSyntheticCompilerUnits($units);

    foreach ($units as $moduleName => &$unit) {
        $unit['compileBackend'] = $context->backendId;
        $unit['implModule'] = resolveImplModuleName($unit['backendMap'] ?? [], $context->backendId);
    }
    unset($unit);

    $fullParseModules = \array_keys($pending);

    $sortedModules = sortModulesByDependencies($units, $context->backendId);
    assignModuleCacheKeys($units, $sortedModules, $context);
    hydrateModuleDiskArtifacts($units, $sortedModules, $context);

    if ($shallow && ! $widened) {
        foreach ($units as $moduleName => $unit) {
            if (($unit['synthetic'] ?? false) === true) {
                continue;
            }
            if (isset($unit['localTypes']) || ! isModuleActiveForCompileBackend($units, $moduleName, $context->backendId)) {
                continue;
            }

            $extra = [];
            $stdlibRoot = configuredStdlibLibPath() ?? bundledStdlibLibPath();
            if ($stdlibRoot !== null && \is_dir($stdlibRoot)) {
                foreach (findMogFilesUnder($stdlibRoot) as $candidate) {
                    $extra[resolvePath($candidate)] = $candidate;
                }
            }
            foreach ($paths as $path) {
                unset($extra[resolvePath($path)]);
            }

            if ($extra !== []) {
                return prepareProject([...$paths, ...\array_values($extra)], $rootDir, $context, true, true);
            }

            break;
        }
    }

    foreach ($fullParseModules as $moduleName) {
        ensureUnitProgram($units, $moduleName);
    }

    $projectInstances = collectProjectInstances($units, $fullParseModules);
    $projectInstanceIndex = indexProjectInstances($projectInstances);
    $projectClasses = [];
    enableCollectExportsCache();
    $classScopes = [];
    $classScopeFns = classScopeFunctionRefs($units, $classScopes);

    $checked = [];
    $importContexts = [];

    foreach ($sortedModules as $moduleName) {
        if (! isset($units[$moduleName])) {
            continue;
        }

        if (($units[$moduleName]['synthetic'] ?? false) === true) {
            if (! isset($units[$moduleName]['exports'])) {
                $units[$moduleName]['exports'] = syntheticExports(
                    $moduleName,
                    $units[$moduleName]['localTypes'],
                );
            }
            if (! isModuleActiveForCompileBackend($units, $moduleName, $context->backendId)) {
                continue;
            }

            $checked[$moduleName] = $units[$moduleName]['checkedProgram'] ?? $units[$moduleName]['program'];
            $importContexts[$moduleName] = mergeClassScopeRefs([
                'interface' => ModuleInterface::withoutImports($moduleName),
                'plan' => EmitPlan::forTarget($context->backendId, ''),
                'classes' => $projectClasses,
                'classScopes' => $classScopes,
                'projectInstances' => $projectInstances,
                'projectInstanceIndex' => $projectInstanceIndex,
                'currentModule' => $moduleName,
            ],
                $classScopeFns,
                moduleLocalValueNames($units, $moduleName),
                moduleLocalDataNames($units, $moduleName),
            );

            continue;
        }

        if (! isModuleActiveForCompileBackend($units, $moduleName, $context->backendId)) {
            publishModuleExports($units, $moduleName, $context);

            continue;
        }

        updateProjectClassesForModule($units[$moduleName], $units, $moduleName, $projectClasses);

        if (($units[$moduleName]['parsed'] ?? false) !== true) {
            ensureModuleInterface($units, $moduleName, $projectClasses, $projectInstanceIndex, $context);
            publishModuleExports($units, $moduleName, $context);
            if (isset($units[$moduleName]['localTypes'])) {
                addModuleClassScope($units, $moduleName, $projectClasses, $classScopes, $classScopeFns, $context->backendId);
            }

            continue;
        }

        $path = $units[$moduleName]['path'];
        $cacheKey = checkedModuleCacheKey($path, $context);

        $memoized = ProjectCache::checkedModule($cacheKey);
        $interfacesReady = isset($units[$moduleName]['localTypes'], $units[$moduleName]['exports'], $units[$moduleName]['facts']);
        if ($memoized !== null && !($shallow && $interfacesReady)) {
            syncExportedInferredSchemesFromTypeEnv(
                $units,
                $moduleName,
                $memoized->exportedInferredSchemes,
            );
            $checked[$moduleName] = $memoized->program;
            $units[$moduleName]['checkedProgram'] = $memoized->program;
            $importContexts[$moduleName] = buildPreparedModuleContext(
                $units,
                $moduleName,
                $path,
                $rootPrefix,
                $projectClasses,
                $classScopes,
                $classScopeFns,
                $projectInstances,
                $projectInstanceIndex,
                $context,
            );

            continue;
        }

        $contentKey = $units[$moduleName]['contentKey'] ?? null;
        if ($contentKey !== null && !$shallow) {
            $cachedCheck = Cache\moduleGet(
                $units[$moduleName]['cacheRelPath'],
                'checked',
                $context->moduleKey($moduleName, $contentKey),
                $context->backendId,
            );
            if (\is_array($cachedCheck) && isset($cachedCheck['program'])) {
                $fromDisk = new CheckedModule(
                    $cachedCheck['program'],
                    $cachedCheck['exportedInferredSchemes'] ?? [],
                );
                syncExportedInferredSchemesFromTypeEnv(
                    $units,
                    $moduleName,
                    $fromDisk->exportedInferredSchemes,
                );
                ProjectCache::rememberCheckedModule($cacheKey, $fromDisk, ! isStdlibSourcePath($path));
                $checked[$moduleName] = $fromDisk->program;
                $units[$moduleName]['checkedProgram'] = $fromDisk->program;
                $importContexts[$moduleName] = buildPreparedModuleContext(
                    $units,
                    $moduleName,
                    $path,
                    $rootPrefix,
                    $projectClasses,
                    $classScopes,
                    $classScopeFns,
                    $projectInstances,
                    $projectInstanceIndex,
                    $context,
                );

                continue;
            }
        }

        if ($shallow && $interfacesReady) {
            $importContexts[$moduleName] = buildPreparedModuleContext(
                $units,
                $moduleName,
                $path,
                $rootPrefix,
                $projectClasses,
                $classScopes,
                $classScopeFns,
                $projectInstances,
                $projectInstanceIndex,
                $context,
            );

            continue;
        }

        $outputRelative = mogPathToOutputRelative($path, $rootPrefix, $context->backend->extension());
        $importContext = buildImportContext(
            $units[$moduleName]['program']->imports,
            $units,
            $moduleName,
            $outputRelative,
            $rootPrefix,
            $context->backendId,
        );
        $importContext['classes'] = $projectClasses;
        $importContext['classScopes'] = $classScopes;
        $importContext = mergeClassScopeRefs(
            $importContext,
            $classScopeFns,
            moduleLocalValueNames($units, $moduleName),
            moduleLocalDataNames($units, $moduleName),
            $units,
            $rootPrefix,
            $context->backendId,
        );
        $importContext['projectInstances'] = $projectInstances;
        $importContext['projectInstanceIndex'] = $projectInstanceIndex;
        $importContext['currentModule'] = $moduleName;
        $importContexts[$moduleName] = $importContext;

        $importContext['interface'] = $importContext['interface']->withExtraTypes(
            moduleFacadeExtraTypes($units, $moduleName, $projectClasses, $projectInstanceIndex),
        );

        $purpose = $context->purposeFor($moduleName);
        $interface = null;
        $checkedFull = checkAndNormalize(
            $units[$moduleName]['program'],
            $units[$moduleName]['source'],
            $path,
            $importContext,
            $purpose,
            $interface,
            entryModules: $context->entries,
            backendId: $context->backendId,
        );
        $units[$moduleName]['localTypes'] = $interface;
        $exportedInferredSchemes = $checkedFull->exportedInferredSchemes;
        syncExportedInferredSchemesFromTypeEnv(
            $units,
            $moduleName,
            $exportedInferredSchemes,
        );
        if ($contentKey !== null) {
            Cache\modulePut(
                $units[$moduleName]['cacheRelPath'],
                'localtypes',
                $context->moduleKey($moduleName, $contentKey),
                $units[$moduleName]['localTypes'],
                $context->backendId,
            );
        }

        publishModuleExports($units, $moduleName, $context);
        addModuleClassScope($units, $moduleName, $projectClasses, $classScopes, $classScopeFns, $context->backendId);

        $units[$moduleName]['facts'] = preparedModuleFacts($units[$moduleName], $checkedFull, $moduleName);
        if ($contentKey !== null) {
            Cache\modulePut(
                $units[$moduleName]['cacheRelPath'],
                'checked',
                $context->moduleKey($moduleName, $contentKey),
                [
                    'program' => $checkedFull,
                    'exportedInferredSchemes' => $exportedInferredSchemes,
                ],
                $context->backendId,
            );
            Cache\modulePut(
                $units[$moduleName]['cacheRelPath'],
                'facts',
                $context->moduleKey($moduleName, $contentKey),
                $units[$moduleName]['facts'],
                $context->backendId,
            );
        }

        if (! $shallow) {
            ProjectCache::rememberCheckedModule(
                $cacheKey,
                new CheckedModule($checkedFull, $exportedInferredSchemes),
                ! isStdlibSourcePath($path),
            );
            $checked[$moduleName] = $checkedFull;
            $units[$moduleName]['checkedProgram'] = $checkedFull;
        }
    }

    foreach ($sortedModules as $moduleName) {
        if (isset($importContexts[$moduleName]) || ! isset($units[$moduleName]['program'], $units[$moduleName]['localTypes'])) {
            continue;
        }

        if (($units[$moduleName]['synthetic'] ?? false) === true) {
            continue;
        }

        $outputRelative = mogPathToOutputRelative($units[$moduleName]['path'], $rootPrefix, $context->backend->extension());
        $importContexts[$moduleName] = buildImportContext(
            $units[$moduleName]['program']->imports,
            $units,
            $moduleName,
            $outputRelative,
            $rootPrefix,
            $context->backendId,
        );
    }

    foreach ($importContexts as $moduleName => $context) {
        $importContexts[$moduleName]['classes'] = $projectClasses;
        $importContexts[$moduleName]['classScopes'] = $classScopes;
    }

    disableCollectExportsCache();
    clearInternedTypeNodes();

    return new PreparedProject($rootPrefix, $units, $checked, $importContexts);
}

/**
 * Build one module's import context from its (hydrated or just-checked)
 * interface: publish exports, fold class scopes, and plan imports.
 *
 * @param array<string, array<string, mixed>> $units
 * @param array<string, mixed> $projectClasses
 * @param array<string, mixed> $classScopes
 * @param array<string, mixed> $classScopeFns
 * @param list<array<string, mixed>> $projectInstances
 * @param array<string, mixed> $projectInstanceIndex
 * @return array<string, mixed>
 */
function buildPreparedModuleContext(
    array &$units,
    string $moduleName,
    string $path,
    string $rootPrefix,
    array $projectClasses,
    array &$classScopes,
    array &$classScopeFns,
    array $projectInstances,
    array $projectInstanceIndex,
    CompilationContext $context,
): array {
    ensureModuleInterface($units, $moduleName, $projectClasses, $projectInstanceIndex, $context);
    publishModuleExports($units, $moduleName, $context);
    addModuleClassScope($units, $moduleName, $projectClasses, $classScopes, $classScopeFns, $context->backendId);
    $outputRelative = mogPathToOutputRelative($path, $rootPrefix, $context->backend->extension());
    $importContext = buildImportContext(
        $units[$moduleName]['program']->imports,
        $units,
        $moduleName,
        $outputRelative,
        $rootPrefix,
        $context->backendId,
    );
    $importContext['classes'] = $projectClasses;
    $importContext['classScopes'] = $classScopes;
    $importContext = mergeClassScopeRefs(
        $importContext,
        $classScopeFns,
        moduleLocalValueNames($units, $moduleName),
        moduleLocalDataNames($units, $moduleName),
    );
    $importContext['projectInstances'] = $projectInstances;
    $importContext['projectInstanceIndex'] = $projectInstanceIndex;
    $importContext['currentModule'] = $moduleName;

    return $importContext;
}

/**
 * Publish the callable facts a module's interface already determines, for a module whose bodies
 * have not been checked — the shallow library closure a single-file compile prepares.
 *
 * Without it an importer of such a module has only its scheme to go on, and counting arrows
 * disagrees with the declared parameter list for every eta-reduced definition (`($) f = f`
 * declares one parameter and has two arrows) and for every result that is a type synonym of a
 * function type. A checked module keeps the facts its check publishes.
 *
 * @param array<string, array<string, mixed>> $units
 */
function publishParsedCallableFacts(array &$units, string $moduleName): void
{
    $unit = $units[$moduleName] ?? null;
    if (! \is_array($unit) || isset($unit['facts']['callables'])) {
        return;
    }

    $program = $unit['program'] ?? null;
    $exports = $unit['exports'] ?? null;
    if (! $program instanceof Ast\Program || ! \is_array($exports)) {
        return;
    }

    $facts = \is_array($unit['facts'] ?? null) ? $unit['facts'] : [];
    $facts['callables'] = \Moggi\Modules\parsedCallableFacts($program, $exports);
    $units[$moduleName]['facts'] = $facts;
}

/**
 * Publish a module's exports, from the cache or from its local type environment.
 *
 * @param array<string, array<string, mixed>> $units
 */
function publishModuleExports(array &$units, string $moduleName, CompilationContext $context): void
{
    if (isset($units[$moduleName]['exports'])) {
        publishParsedCallableFacts($units, $moduleName);

        return;
    }

    if (! isset($units[$moduleName]['localTypes'], $units[$moduleName]['program'])) {
        return;
    }

    $contentKey = $units[$moduleName]['contentKey'] ?? null;
    if ($contentKey !== null) {
        $cached = Cache\moduleGet(
            $units[$moduleName]['cacheRelPath'],
            'exports',
            $context->moduleKey($moduleName, $contentKey),
            $context->backendId,
        );
        if (\is_array($cached)) {
            $units[$moduleName]['exports'] = $cached;
            publishParsedCallableFacts($units, $moduleName);

            return;
        }
    }

    try {
        $units[$moduleName]['exports'] = collectExports(
            $units[$moduleName]['program'],
            $units[$moduleName]['localTypes'],
            $units,
            $moduleName,
        );
    } catch (TypeError $e) {
        if ($e->filename !== '') {
            throw $e;
        }

        throw new TypeError(
            $e->getMessage(),
            $units[$moduleName]['path'],
            $units[$moduleName]['source'],
        );
    }

    if ($contentKey !== null) {
        Cache\modulePut(
            $units[$moduleName]['cacheRelPath'],
            'exports',
            $context->moduleKey($moduleName, $contentKey),
            $units[$moduleName]['exports'],
            $context->backendId,
        );
    }
}

/**
 * Give a module the interface it publishes when it is not checked in this run.
 *
 * A module the run checks publishes its interface from the check itself, so this
 * is only for one that is hydrated from disk or skipped by `--only`: its
 * interface is the registration pass of `buildModuleLocalTypes`.
 *
 * @param array<string, array<string, mixed>> $units
 * @param array<string, mixed> $projectClasses
 * @param array<string, mixed> $projectInstanceIndex
 */
function ensureModuleInterface(
    array &$units,
    string $moduleName,
    array $projectClasses,
    array $projectInstanceIndex,
    CompilationContext $context,
): void {
    if (isset($units[$moduleName]['localTypes']) || ($units[$moduleName]['parsed'] ?? false) !== true) {
        return;
    }

    buildModuleLocalTypes($units, $moduleName, $projectClasses, $projectInstanceIndex, $context);
}

/**
 * Fold a just-checked module's class scopes into the project-scope tables, so a
 * module checked later sees the scopes of the classes it may instantiate.
 *
 * @param array<string, array<string, mixed>> $units
 * @param array<string, mixed> $projectClasses
 * @param array<string, array<string, mixed>> $classScopes
 * @param array{externalFns: array<string, string>, arity: array<string, int>, dataRefs: array<string, array<string, mixed>>, actionReturn?: array<string, true>} $classScopeFns
 */
function addModuleClassScope(
    array $units,
    string $moduleName,
    array $projectClasses,
    array &$classScopes,
    array &$classScopeFns,
    string $backendId,
): void {
    $classScopes += classModuleScopes($units, $projectClasses, [$moduleName], $backendId);
    $classScopeFns = classScopeFunctionRefs($units, $classScopes);
}

/**
 * Memo key for a single module: path identity, compile backend, and purpose.
 *
 * Purpose is part of the key because it is no longer a function of the module's
 * name: the same module can be an entry for one compilation and a library for
 * another, and the checked program differs (the entry's `main` is promoted).
 */
function checkedModuleCacheKey(string $path, CompilationContext $context): string
{
    $module = cachedModuleHeader($path)['module'] ?? null;

    return sourceIdentityKey($path) . ':' . $context->moduleCacheMaterial($module);
}

function clearPrepareProjectCaches(): void
{
    ProjectCache::clearPreparedProjects();
    ProjectCache::clearCheckedModules();
}

/**
 * Is this file part of the standard library — the closure that every project in the process shares?
 */
function isStdlibSourcePath(string $path): bool
{
    $stdlib = locateStdlibRoot($path);

    return $stdlib !== null && \str_starts_with(resolvePath($path), $stdlib . DIRECTORY_SEPARATOR);
}

/**
 * Prepare an import closure as a base for single-focus compiles, without
 * loading any checked program: the focus path typechecks exactly one module
 * and never lowers the rest, while the hydrated interfaces already carry
 * final inferred schemes (see the `localtypes` write order in
 * `prepareProject`). Memoized under its own key space so a full prepare of
 * the same paths can never observe the missing `checked` entries.
 *
 * @param list<string> $paths
 */
function prepareFocusBase(array $paths, string $rootDir, CompilationContext $context): PreparedProject
{
    $parts = [];
    foreach ($paths as $path) {
        $parts[] = checkedModuleCacheKey($path, $context);
    }
    \sort($parts);

    $key = 'shallow|' . \implode('|', $parts) . '@' . $context->inputsKey();
    $memoized = ProjectCache::preparedProject($key);
    if ($memoized !== null) {
        return $memoized;
    }

    $prepared = prepareProject($paths, $rootDir, $context, true);
    ProjectCache::rememberPreparedProject($key, $prepared);

    return $prepared;
}

/** @param list<string> $paths */
function prepareProjectCached(array $paths, string $rootDir, CompilationContext $context, ?string $onlyTypecheckModule = null): PreparedProject
{
    $parts = [];
    foreach ($paths as $path) {
        $parts[] = checkedModuleCacheKey($path, $context);
    }
    \sort($parts);

    $key = \implode('|', $parts)
        . '@' . ($onlyTypecheckModule ?? '*')
        . '@' . $context->inputsKey();
    $memoized = ProjectCache::preparedProject($key);
    if ($memoized !== null) {
        return $memoized;
    }

    if ($onlyTypecheckModule !== null) {
        $focusPaths = [];
        $basePaths = [];
        foreach ($paths as $path) {
            $header = cachedModuleHeader($path);
            $mod = $header['module'] ?? null;
            if ($mod === $onlyTypecheckModule) {
                $focusPaths[] = $path;
            } else {
                $basePaths[] = $path;
            }
        }
        if (\count($focusPaths) === 1 && $basePaths !== []) {
            $base = prepareFocusBase($basePaths, $rootDir, $context);
            $prepared = prepareProjectExtendingFocus(
                $base,
                $focusPaths[0],
                $onlyTypecheckModule,
                $rootDir,
                $context,
            );

            return $prepared;
        }

        return prepareProjectCached($paths, $rootDir, $context, null);
    }

    return prepareProject($paths, $rootDir, $context);
}

/**
 * Typecheck one focus module on top of an already-prepared import closure.
 */
function prepareProjectExtendingFocus(
    PreparedProject $base,
    string $focusPath,
    string $focusModule,
    string $rootDir,
    CompilationContext $context,
): PreparedProject {
    $rootPrefix = $base->rootPrefix;

    $header = cachedModuleHeader($focusPath);
    $source = $header['__source'] ?? null;
    if (! \is_string($source)) {
        $source = \file_get_contents($focusPath);
        if ($source === false) {
            throw new \RuntimeException("cannot read {$focusPath}");
        }
    }

    $units = $base->units;
    if (isset($units[$focusModule], $base->checked[$focusModule], $base->importContexts[$focusModule])) {
        $existing = $units[$focusModule]['source'] ?? '';
        if ($existing === $source) {
            return $base;
        }
    }
    $localFixity = $header['localFixity'] ?? [];
    $knownModules = \array_fill_keys(\array_keys($units), true);
    $knownModules[$focusModule] = true;
    $imports = injectPreludeImport(
        $header['imports'] ?? [],
        $focusPath,
        $knownModules,
        $header['language'] ?? [],
    );
    $localFixityByModule = [$focusModule => $localFixity];
    $fixityPending = [$focusModule => ['imports' => $imports]];
    foreach ($units as $name => $unit) {
        if (isset($unit['localFixity']) && \is_array($unit['localFixity'])) {
            $localFixityByModule[$name] = $unit['localFixity'];
            $fixityPending[$name] = ['imports' => $unit['imports'] ?? []];
        }
    }
    $importedFixity = importedFixityForImports(
        $imports,
        computeEffectiveFixityByModule($fixityPending, $localFixityByModule),
    );
    $units[$focusModule] = [
        'path' => $focusPath,
        'source' => $source,
        'tokens' => null,
        'imports' => $imports,
        'backendMap' => $header['backendMap'] ?? [],
        'headerSpan' => $header['headerSpan'] ?? null,
        'namespace' => moduleNameToNamespace($focusModule),
        'fixity' => mergeFixity($importedFixity, $localFixity),
        'importedFixity' => $importedFixity,
        'localFixity' => $localFixity,
        'program' => null,
        'parsed' => false,
    ];

    ensureUnitProgram($units, $focusModule);

    $sampleCtx = null;
    foreach ($base->importContexts as $ctx) {
        $sampleCtx = $ctx;

        break;
    }
    if ($sampleCtx === null) {
        $fallbackPaths = [$focusPath];
        foreach ($base->units as $unit) {
            if (isset($unit['path']) && \is_string($unit['path']) && ($unit['synthetic'] ?? false) !== true) {
                $fallbackPaths[] = $unit['path'];
            }
        }

        return prepareProject(\array_values(\array_unique($fallbackPaths)), $rootDir, $context);
    }

    $projectInstances = $sampleCtx['projectInstances'] ?? [];
    $projectInstanceIndex = $sampleCtx['projectInstanceIndex'] ?? indexProjectInstances($projectInstances);
    $projectClasses = $sampleCtx['classes'] ?? [];
    $classScopes = $sampleCtx['classScopes'] ?? [];

    $focusExtra = collectProjectInstances($units, [$focusModule]);
    if ($focusExtra !== []) {
        $projectInstances = \array_merge($projectInstances, $focusExtra);
        $projectInstanceIndex = indexProjectInstances($projectInstances);
    }

    unset($classScopes[$focusModule]);
    $classScopeFns = classScopeFunctionRefs($units, $classScopes);

    $sortedNames = sortModulesByDependencies($units, $context->backendId);
    assignModuleCacheKeys($units, $sortedNames, $context);
    updateProjectClassesForModule($units[$focusModule], $units, $focusModule, $projectClasses);

    $outputRelative = mogPathToOutputRelative($focusPath, $rootPrefix, $context->backend->extension());
    $importContext = buildImportContext(
        $units[$focusModule]['program']->imports,
        $units,
        $focusModule,
        $outputRelative,
        $rootPrefix,
        $context->backendId,
    );
    $importContext['classes'] = $projectClasses;
    $importContext['classScopes'] = $classScopes;
    $importContext = mergeClassScopeRefs(
        $importContext,
        $classScopeFns,
        moduleLocalValueNames($units, $focusModule),
        moduleLocalDataNames($units, $focusModule),
    );
    $importContext['projectInstances'] = $projectInstances;
    $importContext['projectInstanceIndex'] = $projectInstanceIndex;
    $importContext['currentModule'] = $focusModule;

    $importContext['interface'] = $importContext['interface']->withExtraTypes(
        moduleFacadeExtraTypes($units, $focusModule, $projectClasses, $projectInstanceIndex),
    );

    $purpose = $context->purposeFor($focusModule);
    $interface = null;
    $checkedFull = checkAndNormalize(
        $units[$focusModule]['program'],
        $units[$focusModule]['source'],
        $focusPath,
        $importContext,
        $purpose,
        $interface,
        entryModules: $context->entries,
        backendId: $context->backendId,
    );
    $units[$focusModule]['localTypes'] = $interface;
    syncExportedInferredSchemesFromTypeEnv(
        $units,
        $focusModule,
        $checkedFull->exportedInferredSchemes,
    );
    $contentKey = $units[$focusModule]['contentKey'] ?? null;
    if ($contentKey !== null) {
        Cache\modulePut(
            $units[$focusModule]['cacheRelPath'],
            'localtypes',
            $context->moduleKey($focusModule, $contentKey),
            $units[$focusModule]['localTypes'],
            $context->backendId,
        );
    }

    publishModuleExports($units, $focusModule, $context);

    $units[$focusModule]['checkedProgram'] = $checkedFull;
    $units[$focusModule]['facts'] = preparedModuleFacts($units[$focusModule], $checkedFull, $focusModule);
    if ($contentKey !== null) {
        Cache\modulePut(
            $units[$focusModule]['cacheRelPath'],
            'checked',
            $context->moduleKey($focusModule, $contentKey),
            [
                'program' => $checkedFull,
                'exportedInferredSchemes' => $checkedFull->exportedInferredSchemes,
            ],
            $context->backendId,
        );
        Cache\modulePut(
            $units[$focusModule]['cacheRelPath'],
            'facts',
            $context->moduleKey($focusModule, $contentKey),
            $units[$focusModule]['facts'],
            $context->backendId,
        );
    }
    ProjectCache::rememberCheckedModule(
        checkedModuleCacheKey($focusPath, $context),
        new CheckedModule($checkedFull, $checkedFull->exportedInferredSchemes),
        ! isStdlibSourcePath($focusPath),
    );

    $checked = $base->checked;
    $checked[$focusModule] = $checkedFull;
    $importContexts = [$focusModule => $importContext];

    clearInternedTypeNodes();

    return new PreparedProject($rootPrefix, $units, $checked, $importContexts);
}

/**
 * @param list<string> $paths
 * Content-addressed key for a whole closure (build path): sensitive to any
 * source change, the project root, and backend. Used only for the coarse
 * whole-project `outputs` cache (`moggi compile`).
 */
function preparedProjectDiskKey(array $paths, string $rootDir, CompilationContext $context): string
{
    $root = \realpath($rootDir) ?: $rootDir;
    $parts = [
        'root=' . $root,
        'context=' . $context->cacheKey(),
    ];

    $fps = [];
    foreach ($paths as $path) {
        $real = \realpath($path) ?: $path;
        $rel = \str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            ? \substr($real, \strlen($root) + 1)
            : $real;
        $fps[] = $rel . ':' . Cache\fileFingerprint($path);
    }
    \sort($fps);

    return Cache\hashContent(\implode("\n", [...$parts, ...$fps]));
}

/**
 * Per-module content key (Merkle over source): a module's key changes iff its
 * own source or any transitive dependency's source changes. This is what makes
 * the stdlib stay cached when only the user's own module changes.
 *
 * @param array<string, array<string, mixed>> $units
 * @param list<string> $sortedModules  topological order (deps before dependents)
 * @return array<string, string>
 */
function computeModuleContentKeys(array $units, array $sortedModules, CompilationContext $context): array
{
    $backend = $context->backendId;
    $keys = [];

    foreach ($sortedModules as $moduleName) {
        if (! isset($units[$moduleName])) {
            continue;
        }

        if (($units[$moduleName]['synthetic'] ?? false) === true) {
            $keys[$moduleName] = Cache\hashContent('synthetic;' . $moduleName . ';b=' . $backend);

            continue;
        }

        $srcHash = isset($units[$moduleName]['path'])
            ? Cache\fileFingerprint($units[$moduleName]['path'])
            : 'none';

        $depKeys = [];
        foreach (moduleDependencyNames($units, $moduleName, $context->backendId) as $dep) {
            if (isset($keys[$dep])) {
                $depKeys[] = $keys[$dep];
            } elseif (isset($units[$dep]['path'])) {
                if (($units[$dep]['synthetic'] ?? false) === true) {
                    $depKeys[] = $keys[$dep] ?? Cache\hashContent('synthetic;' . $dep);
                } else {
                    $depKeys[] = Cache\fileFingerprint($units[$dep]['path']);
                }
            }
        }
        \sort($depKeys);

        $keys[$moduleName] = Cache\hashContent(
            'b=' . $backend . ';m=' . $moduleName . ';s=' . $srcHash
                . ';d=' . \implode(',', $depKeys)
                . ';' . $context->moduleCacheMaterial($moduleName),
        );
    }

    return $keys;
}

/**
 * Relative path used to mirror a module under the project `.moggi/` cache.
 *
 * Prefers a path under the working directory when possible; modules outside
 * cwd (e.g. a stdlib resolved from another checkout) fall back to a `lib/`
 * mirror or a content-hashed leaf.
 */
function moduleCacheRelPath(string $path): string
{
    $real = \realpath($path) ?: $path;

    $cwd = \getcwd();
    if ($cwd !== false && \str_starts_with($real, $cwd . DIRECTORY_SEPARATOR)) {
        return \substr($real, \strlen($cwd) + 1);
    }

    if (isModuleUnderLibraryRoot($path)) {
        $libRoot = locateStdlibRoot($path);
        $realLib = $libRoot !== null ? (\realpath($libRoot) ?: $libRoot) : null;
        if ($realLib !== null && \str_starts_with($real, $realLib . DIRECTORY_SEPARATOR)) {
            return 'lib' . DIRECTORY_SEPARATOR . \substr($real, \strlen($realLib) + 1);
        }

        return 'lib' . DIRECTORY_SEPARATOR . \basename($real);
    }

    return Cache\hashContent($real) . DIRECTORY_SEPARATOR . \basename($real);
}

/** @param array<string, array<string, mixed>> $units @param list<string> $sortedModules */
function assignModuleCacheKeys(array &$units, array $sortedModules, CompilationContext $context): void
{
    foreach (computeModuleContentKeys($units, $sortedModules, $context) as $moduleName => $contentKey) {
        $units[$moduleName]['contentKey'] = $contentKey;
        if (($units[$moduleName]['synthetic'] ?? false) === true) {
            $units[$moduleName]['cacheRelPath'] = 'synthetic' . DIRECTORY_SEPARATOR . moduleNameToPath($moduleName) . '.mog';

            continue;
        }
        $units[$moduleName]['cacheRelPath'] = moduleCacheRelPath($units[$moduleName]['path']);
    }
}

/** @param array<string, array<string, mixed>> $units @param list<string> $sortedModules */
function hydrateModuleDiskArtifacts(array &$units, array $sortedModules, CompilationContext $context): void
{
    if (! Cache\cacheEnabled()) {
        return;
    }

    foreach ($sortedModules as $moduleName) {
        if (($units[$moduleName]['synthetic'] ?? false) === true) {
            continue;
        }

        $contentKey = $units[$moduleName]['contentKey'] ?? null;
        if ($contentKey === null) {
            continue;
        }

        $relPath = $units[$moduleName]['cacheRelPath'];
        $moduleKey = $context->moduleKey($moduleName, $contentKey);
        if (! isset($units[$moduleName]['localTypes'])) {
            $cached = Cache\moduleGet($relPath, 'localtypes', $moduleKey, $context->backendId);
            if (\is_array($cached)) {
                $units[$moduleName]['localTypes'] = $cached;
            }
        }

        if (! isset($units[$moduleName]['exports'])) {
            $cached = Cache\moduleGet($relPath, 'exports', $moduleKey, $context->backendId);
            if (\is_array($cached)) {
                $units[$moduleName]['exports'] = $cached;
            }
        }

        if (! isset($units[$moduleName]['facts'])) {
            $cached = Cache\moduleGet($relPath, 'facts', $moduleKey, $context->backendId);
            if (\is_array($cached)) {
                $units[$moduleName]['facts'] = $cached;
            }
        }
    }
}

/**
 * @param array<string, array<string, mixed>> $units
 * @param list<string> $roots
 * @return array<string, true>
 */
function importDependencyClosure(array $units, array $roots, string $backendId): array
{
    $needed = [];
    $rootSet = \array_fill_keys($roots, true);
    $queue = $roots;
    while ($queue !== []) {
        $moduleName = \array_shift($queue);
        if (! isset($units[$moduleName])) {
            continue;
        }

        foreach (moduleDependencyNames($units, $moduleName, $backendId) as $dep) {
            if (isset($needed[$dep]) || isset($rootSet[$dep]) || ! isset($units[$dep])) {
                continue;
            }

            $needed[$dep] = true;
            $queue[] = $dep;
        }
    }

    return $needed;
}

/** @param array<string, array<string, mixed>> $units */
function ensureUnitProgram(array &$units, string $moduleName): void
{
    if (($units[$moduleName]['parsed'] ?? false) === true) {
        return;
    }

    $unit = &$units[$moduleName];
    $tokens = $unit['tokens'] ?? null;
    if ($tokens === null) {
        $tokens = lex($unit['source'], $unit['path']);
    }
    $program = parse(
        $tokens,
        $unit['source'],
        $unit['path'],
        $unit['importedFixity'],
        $unit['localFixity'],
        $unit['imports'],
    );
    $unit['program'] = $program;
    $unit['parsed'] = true;
    unset($unit['tokens']);
}
