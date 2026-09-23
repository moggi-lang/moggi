<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Cache;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Semantics\Types\TypeError;

use function Moggi\Backend\compileBackend;
use function Moggi\Paths\canonicalPath;
use function Moggi\Paths\moduleNameToPath;
use function Moggi\Semantics\Effects\checkAndNormalize;
use function Moggi\Syntax\Ast\moduleName;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\importedFixityForImports;
use function Moggi\Syntax\Parser\mergeFixity;
use function Moggi\Syntax\Parser\parse;

/**
 * Whole-project preparation: parses, type-checks, and caches every module in
 * a closure, producing a `PreparedProject` ready to be lowered and emitted.
 */

/** @param list<string> $paths */
function prepareProject(array $paths, string $rootDir, ?string $onlyTypecheckModule = null): PreparedProject
{
    $root = realpath($rootDir);
    if ($root === false) {
        throw new \RuntimeException("cannot resolve project root {$rootDir}");
    }

    // When the common root is `/`, `$root . DIRECTORY_SEPARATOR` becomes `//`, and
    // `substr('/home/...', 2)` yields the broken `ome/...` relative paths.
    $rootPrefix = ($root === '/' || $root === '\\')
        ? '/'
        : $root . DIRECTORY_SEPARATOR;
    $units = [];
    $fixityByModule = [];
    $pending = [];

    foreach ($paths as $path) {
        // Prefer the header already parsed during module-closure discovery so we
        // do not re-lex every file just to read the module name / imports.
        $header = cachedModuleHeader($path);
        $source = $header['__source'] ?? null;
        if (!\is_string($source)) {
            $source = file_get_contents($path);
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
            // One file can arrive under two spellings — a symlinked temporary directory and the
            // path it resolves to — and that is one module, not two.
            if (canonicalPath($pending[$moduleName]['path']) === canonicalPath($path)) {
                continue;
            }
            $span = $header['headerSpan'] ?? null;
            throw new TypeError(
                "duplicate module `{$moduleName}` in project ({$pending[$moduleName]['path']} and {$path})",
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
            // Tokens are produced lazily in ensureUnitProgram when a full parse
            // is required — header discovery already consumed a lex pass.
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
        if (!($info['implicitMain'] ?? false)) {
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

    // Prim/IO are always available for import; they are not on-disk sources.
    injectSyntheticCompilerUnits($units);

    $fullParseModules = modulesNeedingFullProgram($pending, $onlyTypecheckModule);

    // Disk cache keys use header import graphs; order only affects dep-key
    // chaining and falls back to per-file fingerprints when a dep is not keyed yet.
    $moduleNames = \array_keys($units);
    \sort($moduleNames);
    assignModuleCacheKeys($units, $moduleNames);
    hydrateModuleDiskArtifacts($units, $moduleNames);

    // Parse before dependency sorting so invalid import syntax fails as a parse
    // error instead of being reported later as an unknown-module type error.
    $sortedModules = null;
    if ($onlyTypecheckModule === null) {
        foreach ($fullParseModules as $moduleName) {
            ensureUnitProgram($units, $moduleName);
        }
    } else {
        // Type/class declarations must be parsed before dependents build project env.
        foreach ($fullParseModules as $moduleName) {
            if ($moduleName === $onlyTypecheckModule) {
                ensureUnitProgram($units, $moduleName);
                continue;
            }

            if (moduleSourceDeclaresProjectTypes($units[$moduleName]['source'])) {
                ensureUnitProgram($units, $moduleName);
            }
        }

        $sortedModules = sortModulesByDependencies($units);
        $modulesToCheck = modulesForTypecheck($units, $sortedModules, $onlyTypecheckModule);
        $importDepsOfChecked = importDependencyClosure($units, $modulesToCheck);
        foreach ($fullParseModules as $moduleName) {
            if (($units[$moduleName]['parsed'] ?? false) === true) {
                continue;
            }

            // Facade impl modules must stay parsed so facades can merge their exports.
            if (facadeModuleForImpl($units, $moduleName) !== null) {
                ensureUnitProgram($units, $moduleName);
                continue;
            }

            if (\in_array($moduleName, $modulesToCheck, true)
                || isset($importDepsOfChecked[$moduleName])
            ) {
                ensureUnitProgram($units, $moduleName);
                continue;
            }

            if (isset($units[$moduleName]['localTypes'], $units[$moduleName]['exports'])
                && !moduleSourceDeclaresProjectTypes($units[$moduleName]['source'])
            ) {
                continue;
            }

            ensureUnitProgram($units, $moduleName);
        }
    }

    if ($sortedModules === null) {
        $sortedModules = sortModulesByDependencies($units);
    }

    $projectInstances = collectProjectInstances($units, $fullParseModules);
    $projectInstanceIndex = indexProjectInstances($projectInstances);
    $projectClasses = buildProjectTypeEnvironments(
        $units,
        $sortedModules,
        $projectInstances,
        $projectInstanceIndex,
    );
    enableCollectExportsCache();
    foreach ($sortedModules as $moduleName) {
        if (!isset($units[$moduleName]['localTypes'])) {
            continue;
        }

        // Synthetic Prim/IO already carry canonical exports.
        if (isSyntheticCompilerModuleName($moduleName) || ($units[$moduleName]['synthetic'] ?? false) === true) {
            if (!isset($units[$moduleName]['exports'])) {
                $units[$moduleName]['exports'] = syntheticExports(
                    $moduleName,
                    $units[$moduleName]['localTypes'],
                );
            }
            continue;
        }

        $contentKey = $units[$moduleName]['contentKey'] ?? null;
        if ($contentKey !== null) {
            $cachedExports = Cache\moduleGet(
                $units[$moduleName]['cacheRelPath'],
                'exports',
                $contentKey,
            );
            if (\is_array($cachedExports)) {
                $units[$moduleName]['exports'] = $cachedExports;
                continue;
            }
        }

        try {
            $units[$moduleName]['exports'] = collectExports(
                $units[$moduleName]['program'],
                $units[$moduleName]['localTypes'],
                $units,
                $moduleName,
            );
            if ($contentKey !== null) {
                Cache\modulePut(
                    $units[$moduleName]['cacheRelPath'],
                    'exports',
                    $contentKey,
                    $units[$moduleName]['exports'],
                );
            }
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
    }

    // Class scopes resolve default-body names through the modules' export
    // tables, so they are built once those exist.
    $classScopes = classModuleScopes($units, $projectClasses);
    $classScopeFns = classScopeFunctionRefs($units, $classScopes);

    $checked = [];
    $importContexts = [];
    $modulesToCheck = modulesForTypecheck($units, $sortedModules, $onlyTypecheckModule);

    foreach ($modulesToCheck as $moduleName) {
        if (!isset($units[$moduleName]['program'], $units[$moduleName]['localTypes'])) {
            continue;
        }

        if (($units[$moduleName]['synthetic'] ?? false) === true) {
            $checked[$moduleName] = $units[$moduleName]['checkedProgram'] ?? $units[$moduleName]['program'];
            $importContexts[$moduleName] = [
                'env' => [],
                'typeSynonyms' => [],
                'data' => [],
                'qualifiedModules' => [],
                'constructorRenames' => [],
                'intrinsicWrappers' => [],
                'codegen' => [
                    'requireLines' => [],
                    'functionUseLines' => [],
                    'namespaceUseLines' => [],
                    'externalFns' => [],
                    'moduleAsNames' => [],
                    'constructorRenames' => [],
                ],
                'classes' => $projectClasses,
                'classScopes' => $classScopes,
                'projectInstances' => $projectInstances,
                'projectInstanceIndex' => $projectInstanceIndex,
                'currentModule' => $moduleName,
            ];
            $importContexts[$moduleName] = mergeClassScopeRefs(
                $importContexts[$moduleName],
                $classScopeFns,
                moduleLocalValueNames($units, $moduleName),
                moduleLocalDataNames($units, $moduleName),
            );
            continue;
        }

        $path = $units[$moduleName]['path'];
        $cacheKey = checkedModuleCacheKey($path);

        $memoized = ProjectCache::checkedModule($cacheKey);
        if ($memoized !== null) {
            syncExportedInferredSchemesFromTypeEnv(
                $units,
                $moduleName,
                $memoized->exportedInferredSchemes,
            );
            $checked[$moduleName] = $memoized->program;
            $units[$moduleName]['checkedProgram'] = $memoized->program;
            // Full builds still need importContexts for codegen. LSP-style
            // onlyTypecheck prepares only need the focus module's context —
            // building import context for every inferred-export dep was ~1s+.
            if ($onlyTypecheckModule === null || $moduleName === $onlyTypecheckModule) {
                $outputRelative = mogPathToOutputRelative($path, $rootPrefix);
                $importContext = buildImportContext(
                    $units[$moduleName]['program']->imports,
                    $units,
                    $moduleName,
                    $outputRelative,
                    $rootPrefix,
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
                $importContexts[$moduleName] = $importContext;
            }
            continue;
        }

        // Disk L2 for the (expensive) type-check + normalize step, keyed by the
        // module's content key. Unchanged modules — the whole stdlib when only
        // the user's module was edited — are loaded instead of re-checked.
        $contentKey = $units[$moduleName]['contentKey'] ?? null;
        if ($contentKey !== null) {
            $cachedCheck = Cache\moduleGet(
                $units[$moduleName]['cacheRelPath'],
                'checked',
                $contentKey,
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
                ProjectCache::rememberCheckedModule($cacheKey, $fromDisk, !isStdlibSourcePath($path));
                $checked[$moduleName] = $fromDisk->program;
                $units[$moduleName]['checkedProgram'] = $fromDisk->program;
                if ($onlyTypecheckModule === null || $moduleName === $onlyTypecheckModule) {
                    $outputRelative = mogPathToOutputRelative($path, $rootPrefix);
                    $importContext = buildImportContext(
                        $units[$moduleName]['program']->imports,
                        $units,
                        $moduleName,
                        $outputRelative,
                        $rootPrefix,
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
                    $importContexts[$moduleName] = $importContext;
                }
                continue;
            }
        }

        $outputRelative = mogPathToOutputRelative($path, $rootPrefix);
        $importContext = buildImportContext(
            $units[$moduleName]['program']->imports,
            $units,
            $moduleName,
            $outputRelative,
            $rootPrefix,
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
        $importContexts[$moduleName] = $importContext;

        $purpose = $moduleName === 'Main'
            ? CompilePurpose::Executable
            : CompilePurpose::Library;
        mergeModuleLocalTypesIntoImportContext(
            $importContext,
            $units[$moduleName]['program'],
            $units[$moduleName]['localTypes'],
        );
        $checkedFull = checkAndNormalize(
            $units[$moduleName]['program'],
            $units[$moduleName]['source'],
            $path,
            $importContext,
            $purpose,
        );
        $exportedInferredSchemes = $checkedFull->exportedInferredSchemes;
        syncExportedInferredSchemesFromTypeEnv(
            $units,
            $moduleName,
            $exportedInferredSchemes,
        );
        if ($contentKey !== null) {
            Cache\modulePut(
                $units[$moduleName]['cacheRelPath'],
                'checked',
                $contentKey,
                [
                    'program' => $checkedFull,
                    'exportedInferredSchemes' => $exportedInferredSchemes,
                ],
            );
        }
        ProjectCache::rememberCheckedModule(
            $cacheKey,
            new CheckedModule($checkedFull, $exportedInferredSchemes),
            !isStdlibSourcePath($path),
        );
        $checked[$moduleName] = $checkedFull;
        $units[$moduleName]['checkedProgram'] = $checkedFull;
    }

    // Single-file compiles (`onlyTypecheckModule`) only need the target's
    // import context, which was already built during typecheck. Building
    // contexts for every other parsed dep is pure waste (hundreds of ms on
    // closures this large).
    if ($onlyTypecheckModule === null) {
        foreach ($sortedModules as $moduleName) {
            if (isset($importContexts[$moduleName]) || !isset($units[$moduleName]['program'], $units[$moduleName]['localTypes'])) {
                continue;
            }

            if (($units[$moduleName]['synthetic'] ?? false) === true) {
                continue;
            }

            $outputRelative = mogPathToOutputRelative($units[$moduleName]['path'], $rootPrefix);
            $importContexts[$moduleName] = buildImportContext(
                $units[$moduleName]['program']->imports,
                $units,
                $moduleName,
                $outputRelative,
                $rootPrefix,
            );
        }
    }

    disableCollectExportsCache();

    return new PreparedProject($rootPrefix, $units, $checked, $importContexts);
}

/** Memo key for a single module: path identity and compile backend. */
function checkedModuleCacheKey(string $path): string
{
    return sourceIdentityKey($path) . ':' . compileBackend();
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

/** @param list<string> $paths */
function prepareProjectCached(array $paths, string $rootDir, ?string $onlyTypecheckModule = null): PreparedProject
{
    $parts = [];
    foreach ($paths as $path) {
        $parts[] = checkedModuleCacheKey($path);
    }
    sort($parts);

    // Omit rootDir from the key: warm uses root=`lib/` while projectSourceClosure
    // often uses `/` for the same path set — including root forced ~2s re-prepares.
    $key = \implode('|', $parts)
        . '@' . ($onlyTypecheckModule ?? '*')
        . '@' . compileBackend();
    $memoized = ProjectCache::preparedProject($key);
    if ($memoized !== null) {
        return $memoized;
    }

    // LSP / onlyTypecheck: reuse a memoized base prepare for paths sans the
    // focus module (typically the warm stdlib) and typecheck only the focus.
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
        if (count($focusPaths) === 1 && $basePaths !== []) {
            $base = prepareProjectCached($basePaths, $rootDir, null);
            $prepared = prepareProjectExtendingFocus(
                $base,
                $focusPaths[0],
                $onlyTypecheckModule,
                $rootDir,
            );
            ProjectCache::rememberPreparedProject($key, $prepared);

            return $prepared;
        }
    }

    $prepared = prepareProject($paths, $rootDir, $onlyTypecheckModule);
    ProjectCache::rememberPreparedProject($key, $prepared);

    return $prepared;
}

/**
 * Typecheck one focus module on top of an already-prepared import closure.
 */
function prepareProjectExtendingFocus(
    PreparedProject $base,
    string $focusPath,
    string $focusModule,
    string $rootDir,
): PreparedProject {
    $rootPrefix = $base->rootPrefix;

    $header = cachedModuleHeader($focusPath);
    $source = $header['__source'] ?? null;
    if (!\is_string($source)) {
        $source = file_get_contents($focusPath);
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
    $fixityByModule = [$focusModule => $localFixity];
    foreach ($units as $name => $unit) {
        if (isset($unit['localFixity']) && \is_array($unit['localFixity'])) {
            $fixityByModule[$name] = $unit['localFixity'];
        }
    }
    $importedFixity = importedFixityForImports($imports, $fixityByModule);
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
        // Base had no import contexts (shouldn't happen after warm); fall back.
        $fallbackPaths = [$focusPath];
        foreach ($base->units as $unit) {
            if (isset($unit['path']) && \is_string($unit['path']) && ($unit['synthetic'] ?? false) !== true) {
                $fallbackPaths[] = $unit['path'];
            }
        }

        return prepareProject(array_values(array_unique($fallbackPaths)), $rootDir, $focusModule);
    }

    $projectInstances = $sampleCtx['projectInstances'] ?? [];
    $projectInstanceIndex = $sampleCtx['projectInstanceIndex'] ?? indexProjectInstances($projectInstances);
    $projectClasses = $sampleCtx['classes'] ?? [];

    // Merge instances declared in the focus module (e.g. Hier test).
    $focusExtra = collectProjectInstances($units, [$focusModule]);
    if ($focusExtra !== []) {
        $projectInstances = array_merge($projectInstances, $focusExtra);
        $projectInstanceIndex = indexProjectInstances($projectInstances);
        $sorted = sortModulesByDependencies($units);
        $projectClasses = buildProjectTypeEnvironments(
            $units,
            $sorted,
            $projectInstances,
            $projectInstanceIndex,
        );
    }

    $classScopes = classModuleScopes($units, $projectClasses);
    $classScopeFns = classScopeFunctionRefs($units, $classScopes);

    $sortedNames = sortModulesByDependencies($units);
    assignModuleCacheKeys($units, $sortedNames);
    hydrateModuleDiskArtifacts($units, [$focusModule]);
    if (!isset($units[$focusModule]['localTypes'])) {
        buildModuleLocalTypes($units, $focusModule, $projectClasses, $projectInstanceIndex);
    }

    if (!isset($units[$focusModule]['exports'])) {
        $units[$focusModule]['exports'] = collectExports(
            $units[$focusModule]['program'],
            $units[$focusModule]['localTypes'],
            $units,
            $focusModule,
        );
    }

    $outputRelative = mogPathToOutputRelative($focusPath, $rootPrefix);
    $importContext = buildImportContext(
        $units[$focusModule]['program']->imports,
        $units,
        $focusModule,
        $outputRelative,
        $rootPrefix,
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

    $purpose = $focusModule === 'Main'
        ? CompilePurpose::Executable
        : CompilePurpose::Library;
    mergeModuleLocalTypesIntoImportContext(
        $importContext,
        $units[$focusModule]['program'],
        $units[$focusModule]['localTypes'],
    );
    $checkedFull = checkAndNormalize(
        $units[$focusModule]['program'],
        $units[$focusModule]['source'],
        $focusPath,
        $importContext,
        $purpose,
    );
    syncExportedInferredSchemesFromTypeEnv(
        $units,
        $focusModule,
        $checkedFull->exportedInferredSchemes,
    );
    $units[$focusModule]['checkedProgram'] = $checkedFull;
    ProjectCache::rememberCheckedModule(
        checkedModuleCacheKey($focusPath),
        new CheckedModule($checkedFull, $checkedFull->exportedInferredSchemes),
        !isStdlibSourcePath($focusPath),
    );

    $checked = $base->checked;
    $checked[$focusModule] = $checkedFull;
    $importContexts = [$focusModule => $importContext];

    return new PreparedProject($rootPrefix, $units, $checked, $importContexts);
}

/**
 * @param list<string> $paths
 * Content-addressed key for a whole closure (build path): sensitive to any
 * source change, the project root, backend, and target. Used only for the
 * coarse whole-project `outputs` cache (`moggi compile`).
 */
function preparedProjectDiskKey(array $paths, string $rootDir, ?string $onlyTypecheckModule): string
{
    $root = realpath($rootDir) ?: $rootDir;
    $parts = ['root=' . $root, 'target=' . ($onlyTypecheckModule ?? '*'), 'backend=' . compileBackend()];

    $fps = [];
    foreach ($paths as $path) {
        $real = realpath($path) ?: $path;
        $rel = str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            ? substr($real, strlen($root) + 1)
            : $real;
        $fps[] = $rel . ':' . Cache\fileFingerprint($path);
    }
    sort($fps);

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
function computeModuleContentKeys(array $units, array $sortedModules): array
{
    $backend = compileBackend();
    $keys = [];

    foreach ($sortedModules as $moduleName) {
        if (!isset($units[$moduleName])) {
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
        foreach (moduleDependencyNames($units, $moduleName) as $dep) {
            if (isset($keys[$dep])) {
                $depKeys[] = $keys[$dep];
            } elseif (isset($units[$dep]['path'])) {
                // Dependency not yet keyed (e.g. facade impl ordering); fall back
                // to its own source hash so the key still reflects it.
                if (($units[$dep]['synthetic'] ?? false) === true) {
                    $depKeys[] = $keys[$dep] ?? Cache\hashContent('synthetic;' . $dep);
                } else {
                    $depKeys[] = Cache\fileFingerprint($units[$dep]['path']);
                }
            }
        }
        sort($depKeys);

        $keys[$moduleName] = Cache\hashContent(
            'b=' . $backend . ';m=' . $moduleName . ';s=' . $srcHash . ';d=' . \implode(',', $depKeys),
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
    $real = realpath($path) ?: $path;

    $cwd = getcwd();
    if ($cwd !== false && str_starts_with($real, $cwd . DIRECTORY_SEPARATOR)) {
        return substr($real, strlen($cwd) + 1);
    }

    if (isModuleUnderLibraryRoot($path)) {
        $libRoot = locateStdlibRoot($path);
        $realLib = $libRoot !== null ? (realpath($libRoot) ?: $libRoot) : null;
        if ($realLib !== null && str_starts_with($real, $realLib . DIRECTORY_SEPARATOR)) {
            return 'lib' . DIRECTORY_SEPARATOR . substr($real, strlen($realLib) + 1);
        }

        return 'lib' . DIRECTORY_SEPARATOR . basename($real);
    }

    // Module outside the working directory: keep a stable, collision-free name.
    return Cache\hashContent($real) . DIRECTORY_SEPARATOR . basename($real);
}

/** @param array<string, array<string, mixed>> $units @param list<string> $sortedModules */
function assignModuleCacheKeys(array &$units, array $sortedModules): void
{
    foreach (computeModuleContentKeys($units, $sortedModules) as $moduleName => $contentKey) {
        $units[$moduleName]['contentKey'] = $contentKey;
        if (($units[$moduleName]['synthetic'] ?? false) === true) {
            $units[$moduleName]['cacheRelPath'] = 'synthetic' . DIRECTORY_SEPARATOR . moduleNameToPath($moduleName) . '.mog';
            continue;
        }
        $units[$moduleName]['cacheRelPath'] = moduleCacheRelPath($units[$moduleName]['path']);
    }
}

/** @param array<string, array<string, mixed>> $units @param list<string> $sortedModules */
function hydrateModuleDiskArtifacts(array &$units, array $sortedModules): void
{
    if (!Cache\cacheEnabled()) {
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
        if (!isset($units[$moduleName]['localTypes'])) {
            $cached = Cache\moduleGet($relPath, 'localtypes', $contentKey);
            if (\is_array($cached)) {
                $units[$moduleName]['localTypes'] = $cached;
            }
        }

        if (!isset($units[$moduleName]['exports'])) {
            $cached = Cache\moduleGet($relPath, 'exports', $contentKey);
            if (\is_array($cached)) {
                $units[$moduleName]['exports'] = $cached;
            }
        }
    }
}

/**
 * @param array<string, array<string, mixed>> $units
 * @param list<string> $roots
 * @return array<string, true>
 */
function importDependencyClosure(array $units, array $roots): array
{
    $needed = [];
    $rootSet = \array_fill_keys($roots, true);
    $queue = $roots;
    while ($queue !== []) {
        $moduleName = array_shift($queue);
        if (!isset($units[$moduleName])) {
            continue;
        }

        foreach (moduleDependencyNames($units, $moduleName) as $dep) {
            if (isset($needed[$dep]) || isset($rootSet[$dep]) || !isset($units[$dep])) {
                continue;
            }

            $needed[$dep] = true;
            $queue[] = $dep;
        }
    }

    return $needed;
}

/** @param array<string, array<string, mixed>> $pending @return list<string> */
function modulesNeedingFullProgram(array $pending, ?string $onlyTypecheckModule): array
{
    if ($onlyTypecheckModule === null) {
        return \array_keys($pending);
    }

    if (!isset($pending[$onlyTypecheckModule])) {
        return \array_keys($pending);
    }

    $needed = [];
    $queue = [$onlyTypecheckModule];
    $queueHead = 0;
    while ($queueHead < count($queue)) {
        $moduleName = $queue[$queueHead++];
        if (isset($needed[$moduleName])) {
            continue;
        }

        $needed[$moduleName] = true;
        foreach ($pending[$moduleName]['imports'] as $import) {
            $importName = moduleName($import->path);
            if (isset($pending[$importName]) && !isset($needed[$importName])) {
                $queue[] = $importName;
            }
        }

        if (($pending[$moduleName]['backendMap'] ?? []) !== []) {
            foreach (facadeDependencyModules($pending, $moduleName) as $implName) {
                if (isset($pending[$implName]) && !isset($needed[$implName])) {
                    $queue[] = $implName;
                }
            }
        }
    }

    return \array_keys($needed);
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
