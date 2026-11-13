<?php declare(strict_types=1);

namespace Moggi\Modules;

/**
 * Whole-project preparation: parses, type-checks, and caches every module in
 * a closure, producing a `PreparedProject` ready to be lowered and emitted.
 */


use Moggi\Cache;
use Moggi\Memory;
use Moggi\Profile;
use Moggi\Pipeline\CompilationContext;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;


use function Moggi\Paths\canonicalPath;
use function Moggi\Paths\canonicalSeparators;
use function Moggi\Paths\moduleNameToPath;
use function Moggi\Semantics\Effects\checkAndNormalize;
use function Moggi\Semantics\Foreign\programDeclaresForeign;
use function Moggi\Semantics\Types\clearInternedTypeNodes;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\importedFixityForImports;
use function Moggi\Syntax\Parser\mergeFixity;
use function Moggi\Syntax\Parser\parse;

/**
 * @param list<string> $paths
 * @param bool $shallow Build the import closure without loading checked
 *   programs. A module's interface is the product of that module's own check:
 *   a shallow-base module reads the one its check wrote to the module cache, and
 *   a module with no cached interface is checked here like any other, so a cold
 *   cache still typechecks once. Nothing else produces an interface. Shallow-base
 *   modules are never lowered.
 * @param bool $releaseChecked Write checked programs to the module cache
 *   and release them from memory; callers reload them via
 *   {@see PreparedProject::program()} when lowering. This keeps whole-project
 *   builds from retaining the entire closure (which exceeded 1 GB for
 *   library builds). Requires the module cache, which is authoritative
 *   in this mode.
 */
function prepareProject(
    array $paths,
    string $rootDir,
    CompilationContext $context,
    bool $shallow = false,
    bool $releaseChecked = false,
): PreparedProject {
    if ($releaseChecked && ! Cache\cacheEnabled()) {
        throw new \LogicException(
            'a prepare that releases its checked programs needs the module cache: the disk product is'
            . ' what the program is read back from',
        );
    }

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

    mergeFacadeHalves($pending, $fixityByModule, $context->backendId, $paths[0] ?? null);

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
        foreach (mergedHalfFields() as $halfField) {
            if (isset($info[$halfField])) {
                $units[$moduleName][$halfField] = $info[$halfField];
            }
        }
    }

    injectSyntheticCompilerUnits($units);

    foreach ($units as $moduleName => &$unit) {
        $unit['compileBackend'] = $context->backendId;
        if (isset($unit['halfImports'])) {
            $unit['halfImportedFixity'] = importedFixityForImports($unit['halfImports'], $effectiveFixityByModule);
        }
    }
    unset($unit);

    $fullParseModules = \array_keys($pending);

    foreach ($fullParseModules as $moduleName) {
        ensureUnitProgram($units, $moduleName);
    }

    // One record of the fact every product key of the module mixes in, taken while the source AST
    // is in hand: the AST itself does not survive to the emit of a released project.
    foreach ($units as &$unit) {
        $program = $unit['program'] ?? null;
        if ($program instanceof Ast\Program) {
            $unit['declaresForeign'] = programDeclaresForeign($program);
        }
    }
    unset($unit);

    synthesizeTypeableInstances($units);

    $sortedModules = sortModulesByDependencies($units, $context->backendId);
    assignModuleCacheKeys($units, $sortedModules, $context);
    hydrateModuleDiskArtifacts($units, $sortedModules, $context);

    $instanceWorld = InstanceWorld::forUnits($units, $fullParseModules);
    $projectClasses = [];
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

            $checked[$moduleName] = $units[$moduleName]['checkedProgram'] ?? $units[$moduleName]['program'];
            $importContexts[$moduleName] = mergeClassScopeRefs(
                (new ImportContext(
                    ImportInterface::withoutImports($moduleName),
                    EmitPlan::forTarget(
                        $context->backendId,
                        isset($units[$moduleName]['path'])
                            ? mogPathToOutputRelative(
                                $units[$moduleName]['path'],
                                $rootPrefix,
                                $context->backend->extension(),
                            )
                            : '',
                    ),
                    $instanceWorld,
                ))->withProjectScopes(
                    $projectClasses,
                    $classScopes,
                    $moduleName,
                ),
                $classScopeFns,
                moduleLocalValueNames($units, $moduleName),
                moduleLocalDataNames($units, $moduleName),
            );

            continue;
        }

        updateProjectClassesForModule($units[$moduleName], $units, $moduleName, $projectClasses);

        if (($units[$moduleName]['parsed'] ?? false) !== true) {
            throw new \LogicException(
                "the closure hands `{$moduleName}` over for classification without its program: every"
                . ' module is parsed before any is classified, so an unparsed unit means the run was'
                . ' handed a module it does not check',
            );
        }

        $path = $units[$moduleName]['path'];
        $cacheKey = checkedModuleCacheKey($path, $context);

        $interfaceInHand = isset($units[$moduleName]['localTypes']);
        $memoized = ProjectCache::checkedModule($cacheKey);
        $interfacesReady = $interfaceInHand
            && isset($units[$moduleName]['exports'], $units[$moduleName]['facts']);
        if ($interfaceInHand && $memoized !== null && !($shallow && $interfacesReady)) {
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
                $instanceWorld,
                $context,
            );

            continue;
        }

        $contentKey = $units[$moduleName]['contentKey'] ?? null;
        if ($contentKey !== null && !$shallow) {
            $cachedCheck = Cache\moduleGet(
                $units[$moduleName]['cacheRelPath'],
                'checked',
                $context->moduleProductKey(
                    $moduleName,
                    $contentKey,
                    programDeclaresForeign($units[$moduleName]['program']),
                ),
                $context->backendId,
            );
            if ($interfaceInHand && \is_array($cachedCheck) && isset($cachedCheck['program'])) {
                $fromDisk = new CheckedModule(
                    $cachedCheck['program'],
                    $cachedCheck['exportedInferredSchemes'] ?? [],
                );
                syncExportedInferredSchemesFromTypeEnv(
                    $units,
                    $moduleName,
                    $fromDisk->exportedInferredSchemes,
                );
                if (! $releaseChecked) {
                    ProjectCache::rememberCheckedModule($cacheKey, $fromDisk, ! isStdlibSourcePath($path));
                    $checked[$moduleName] = $fromDisk->program;
                    $units[$moduleName]['checkedProgram'] = $fromDisk->program;
                }
                $importContexts[$moduleName] = buildPreparedModuleContext(
                    $units,
                    $moduleName,
                    $path,
                    $rootPrefix,
                    $projectClasses,
                    $classScopes,
                    $classScopeFns,
                    $instanceWorld,
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
                $instanceWorld,
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
            $instanceWorld,
        )->withProjectScopes(
            $projectClasses,
            $classScopes,
            $moduleName,
        );
        $importContext = mergeClassScopeRefs(
            $importContext,
            $classScopeFns,
            moduleLocalValueNames($units, $moduleName),
            moduleLocalDataNames($units, $moduleName),
        );
        $importContexts[$moduleName] = $importContext;

        $importedPatternSynonyms = $importContext->interface->patternSynonyms();
        $qualifiedPatternSynonyms = $importContext->interface->qualifiedPatternSynonyms();
        if ($importedPatternSynonyms !== [] || $qualifiedPatternSynonyms !== []) {
            reparseModuleWithPatternSynonyms(
                $units,
                $moduleName,
                $importedPatternSynonyms,
                $qualifiedPatternSynonyms,
            );
        }

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
        $units[$moduleName]['facts'] = preparedModuleFacts($units[$moduleName], $checkedFull, $moduleName);
        addModuleClassScope($units, $moduleName, $projectClasses, $classScopes, $classScopeFns, $context->backendId);

        if ($contentKey !== null) {
            Cache\modulePut(
                $units[$moduleName]['cacheRelPath'],
                'checked',
                $context->moduleProductKey(
                    $moduleName,
                    $contentKey,
                    programDeclaresForeign($units[$moduleName]['program']),
                ),
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

        $releasable = $releaseChecked && $contentKey !== null
            && isset($units[$moduleName]['cacheRelPath']);
        if (! $shallow && ! $releasable) {
            ProjectCache::rememberCheckedModule(
                $cacheKey,
                new CheckedModule($checkedFull, $exportedInferredSchemes),
                ! isStdlibSourcePath($path),
            );
            $checked[$moduleName] = $checkedFull;
            $units[$moduleName]['checkedProgram'] = $checkedFull;
        } else {
            // Nothing below this module needs its checked program: it is on disk (a released
            // product) or this prepare never holds one. Drop the local reference before the
            // collector runs — while one exists the graph cannot be reclaimed, and a module's
            // checked program is the largest thing a build allocates.
            unset($checkedFull, $exportedInferredSchemes);
        }

        Memory\tick();
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
            $instanceWorld,
        )->withProjectScopes(
            $projectClasses,
            $classScopes,
            $moduleName,
        );
    }

    foreach ($importContexts as $moduleName => $importContext) {
        $importContexts[$moduleName] = $importContext->withClassScopes($projectClasses, $classScopes);
    }

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
 */
function buildPreparedModuleContext(
    array &$units,
    string $moduleName,
    string $path,
    string $rootPrefix,
    array $projectClasses,
    array &$classScopes,
    array &$classScopeFns,
    InstanceWorld $instanceWorld,
    CompilationContext $context,
): ImportContext {
    publishModuleExports($units, $moduleName, $context);
    addModuleClassScope($units, $moduleName, $projectClasses, $classScopes, $classScopeFns, $context->backendId);
    $outputRelative = mogPathToOutputRelative($path, $rootPrefix, $context->backend->extension());

    return mergeClassScopeRefs(
        buildImportContext(
            $units[$moduleName]['program']->imports,
            $units,
            $moduleName,
            $outputRelative,
            $rootPrefix,
            $context->backendId,
            $instanceWorld,
        )->withProjectScopes(
            $projectClasses,
            $classScopes,
            $moduleName,
        ),
        $classScopeFns,
        moduleLocalValueNames($units, $moduleName),
        moduleLocalDataNames($units, $moduleName),
    );
}

/**
 * Publish the callable facts a module's interface already determines, for a module whose bodies are
 * not checked in this run — the shallow library closure a single-file compile prepares.
 *
 * Without it an importer of such a module has only its scheme to go on, and counting arrows
 * disagrees with the declared parameter list for every eta-reduced definition (`($) f = f`
 * declares one parameter and has two arrows) and for every result that is a type synonym of a
 * function type. A module checked in this run keeps the facts its check publishes.
 *
 * The tree is the one the record is rendered from everywhere: a unit hydrated from the checked
 * cache publishes from that checked program, since checking a backend facade synthesizes foreign
 * bindings the parsed tree does not carry.
 *
 * @param array<string, array<string, mixed>> $units
 */
function publishCallableFacts(array &$units, string $moduleName): void
{
    $unit = $units[$moduleName] ?? null;
    if (! \is_array($unit) || isset($unit['facts']['callables'])) {
        return;
    }

    $program = $unit['checkedProgram'] ?? $unit['program'] ?? null;
    $exports = $unit['exports'] ?? null;
    if (! $program instanceof Ast\Program || ! \is_array($exports)) {
        return;
    }

    $facts = \is_array($unit['facts'] ?? null) ? $unit['facts'] : [];
    $unit['facts'] = $facts;
    $facts['callables'] = [
        'entries' => unitCallableEntries($unit, $units, $moduleName),
    ];
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
        publishCallableFacts($units, $moduleName);

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
            publishCallableFacts($units, $moduleName);

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
 * Memo key for a single module: path identity, compile backend, purpose, and the host trees the
 * compilation links against.
 *
 * Purpose is part of the key because it is no longer a function of the module's
 * name: the same module can be an entry for one compilation and a library for
 * another, and the checked program differs (the entry's `main` is promoted).
 *
 * The host trees are part of it because the *checked* product records which foreign declarations the
 * checker accepted against them: a module whose `foreign php function` was verified with a Composer
 * tree must not answer a compile that was given no tree. Nothing else in the key can see that
 * state — it lives in `ConfiguredHostLibraries`, not in the module roots.
 */
function checkedModuleCacheKey(string $path, CompilationContext $context): string
{
    $module = cachedModuleHeader($path)['module'] ?? null;

    return sourceIdentityKey($path) . ':' . $context->moduleCacheMaterial($module)
        . ':host=' . $context->host;
}

function clearPrepareProjectCaches(): void
{
    ProjectCache::clearPreparedProjects();
    ProjectCache::clearCheckedModules();
    ExportsCache::clear();
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

/**
 * A prepared project, memoized for the routes that re-prepare the same closure.
 *
 * `$releaseChecked` is the whole-project compile's mode ({@see prepareProject()}): the product is
 * not memoized, so releasing is safe here, and the compile lowers one module at a time.
 *
 * @param list<string> $paths
 */
function prepareProjectCached(
    array $paths,
    string $rootDir,
    CompilationContext $context,
    ?string $onlyTypecheckModule = null,
    bool $releaseChecked = false,
): PreparedProject {
    $parts = [];
    foreach ($paths as $path) {
        $parts[] = checkedModuleCacheKey($path, $context);
    }
    \sort($parts);

    $key = \implode('|', $parts)
        . '@' . ($onlyTypecheckModule ?? '*')
        . '@' . $context->inputsKey()
        . ($releaseChecked ? '@released' : '');
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

        return prepareProjectCached($paths, $rootDir, $context, null, $releaseChecked);
    }

    return prepareProject($paths, $rootDir, $context, releaseChecked: $releaseChecked);
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
    synthesizeTypeableInstances($units);

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

    $instanceWorld = InstanceWorld::forUnits($units);
    $projectClasses = $sampleCtx->classes;
    $classScopes = $sampleCtx->classScopes;

    unset($classScopes[$focusModule]);
    $classScopeFns = classScopeFunctionRefs($units, $classScopes);

    $sortedNames = sortModulesByDependencies($units, $context->backendId);
    assignModuleCacheKeys($units, $sortedNames, $context);
    updateProjectClassesForModule($units[$focusModule], $units, $focusModule, $projectClasses);

    $outputRelative = mogPathToOutputRelative($focusPath, $rootPrefix, $context->backend->extension());
    $importContext = mergeClassScopeRefs(
        buildImportContext(
            $units[$focusModule]['program']->imports,
            $units,
            $focusModule,
            $outputRelative,
            $rootPrefix,
            $context->backendId,
            $instanceWorld,
        )->withProjectScopes(
            $projectClasses,
            $classScopes,
            $focusModule,
        ),
        $classScopeFns,
        moduleLocalValueNames($units, $focusModule),
        moduleLocalDataNames($units, $focusModule),
    );

    $importedPatternSynonyms = $importContext->interface->patternSynonyms();
    $qualifiedPatternSynonyms = $importContext->interface->qualifiedPatternSynonyms();
    if ($importedPatternSynonyms !== [] || $qualifiedPatternSynonyms !== []) {
        reparseModuleWithPatternSynonyms(
            $units,
            $focusModule,
            $importedPatternSynonyms,
            $qualifiedPatternSynonyms,
        );
    }

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
            $context->moduleProductKey(
                $focusModule,
                $contentKey,
                programDeclaresForeign($units[$focusModule]['program']),
            ),
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

        if (isset($units[$moduleName]['halfPath'])) {
            $srcHash .= ';half=' . Cache\fileFingerprint($units[$moduleName]['halfPath']);
        }

        $depKeys = [];
        foreach (moduleDependencyNames($units, $moduleName) as $dep) {
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

/** @param array<string, array<string, mixed>> $units */
function ensureUnitProgram(array &$units, string $moduleName, array $importedPatternSynonyms = []): void
{
    if (($units[$moduleName]['parsed'] ?? false) === true) {
        return;
    }

    $unit = &$units[$moduleName];
    $importedPatternSynonyms = $unit['importedPatternSynonyms'] ?? $importedPatternSynonyms;
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
        $importedPatternSynonyms,
    );

    if (isset($unit['halfSource']) && $unit['halfSource'] !== '') {
        $halfProgram = parse(
            lex($unit['halfSource'], $unit['halfPath']),
            $unit['halfSource'],
            $unit['halfPath'],
            $unit['halfImportedFixity'] ?? [],
            $unit['halfLocalFixity'] ?? [],
            $unit['halfImports'] ?? [],
            $importedPatternSynonyms,
        );
        $program = mergeBackendHalfProgram(
            $program,
            $halfProgram,
            (string) ($unit['backendMap'][$unit['compileBackend']] ?? ''),
            (string) $unit['halfPath'],
        );
    }

    appendSyntheticUnitDecls($program, $unit);
    $unit['program'] = $program;
    $unit['parsed'] = true;
    unset($unit['tokens']);
}

/**
 * Add the declarations preparation synthesized to a module's parsed program.
 *
 * A module is parsed more than once -- pattern synonyms reparse it once their
 * definitions are known -- so the synthetic parts are kept on the unit and
 * re-applied to every parse rather than living only in the first tree.
 *
 * @param array<string, mixed> $unit
 */
function appendSyntheticUnitDecls(Ast\Program $program, array $unit): void
{
    $synthetic = [];
    foreach ($unit['typeableInstances'] ?? [] as $instance) {
        if ($instance instanceof Ast\InstanceDecl) {
            $name = syntheticInstanceTypeName($instance);
            if ($name !== null) {
                $synthetic[$name][] = $instance;
            } else {
                $program->items[] = $instance;
            }
        }
    }

    if ($synthetic === []) {
        return;
    }

    // A synthesized instance belongs to the declaration it was built from, the
    // way a deriving clause does: it goes right after the data declaration, so
    // the type it is about is in scope and anything written later -- an
    // `instance Data (W a)`, whose `Typeable` superclass this instance is what
    // discharges -- sees it already registered.
    $items = [];
    foreach ($program->items as $item) {
        $items[] = $item;
        if ($item instanceof Ast\DataDecl) {
            foreach ($synthetic[$item->name] ?? [] as $instance) {
                $items[] = $instance;
            }
            unset($synthetic[$item->name]);
        }
    }
    foreach ($synthetic as $leftovers) {
        foreach ($leftovers as $instance) {
            $items[] = $instance;
        }
    }

    $program->items = $items;
}

/**
 * The type constructor a synthesized instance is for, read off its head.
 *
 * The head is the declaration's name, applied to its parameters when the
 * instance is the one that reflects them; walking the application's spine
 * finds the name either way.
 */
function syntheticInstanceTypeName(Ast\InstanceDecl $instance): ?string
{
    $head = $instance->head;
    while ($head instanceof Ast\TypeApp) {
        $head = $head->con;
    }

    return $head instanceof Ast\TypeCon ? $head->name : null;
}

/**
 * Give every data type a 'Typeable' instance, built from its declaration.
 *
 * A type's representation is its constructor's module and name applied to its
 * parameters' representations, which an instance can state without knowing
 * anything a deriving strategy would need. The instances are synthesized once
 * the whole project is parsed and before the instance world is built, so every
 * module -- not only the one declaring the type -- resolves them like written
 * instances.
 *
 * Nothing is synthesized unless Type.Reflection is part of the build, and a
 * module Type.Reflection itself imports is left alone: it cannot import the
 * reflection API back without an import cycle. Its types either have written
 * instances or do not participate in reflection.
 *
 * @param array<string, array<string, mixed>> $units
 */
function synthesizeTypeableInstances(array &$units): void
{
    if (! isset($units['Type.Reflection'])) {
        return;
    }

    $reflection = $units['Type.Reflection'];
    if (($reflection['parsed'] ?? false) !== true || ! ($reflection['program'] ?? null) instanceof Ast\Program) {
        return;
    }

    if (! \array_key_exists('typeableInstances', $units['Type.Reflection'])) {
        $tupleInstances = synthesizedTupleTypeableInstances();
        $units['Type.Reflection']['typeableInstances'] = $tupleInstances;
        appendSyntheticUnitDecls($units['Type.Reflection']['program'], $units['Type.Reflection']);
    }

    $reachedByReflection = typeableReachedModules($units, 'Type.Reflection');
    $writtenInstances = manualTypeableTypeNames($units);

    foreach ($units as $moduleName => &$unit) {
        if (($unit['synthetic'] ?? false) === true || \array_key_exists('typeableInstances', $unit)) {
            continue;
        }

        if (($unit['parsed'] ?? false) !== true) {
            continue;
        }

        $program = $unit['program'] ?? null;
        if (! $program instanceof Ast\Program) {
            continue;
        }

        if ($moduleName === 'Type.Reflection' || isset($reachedByReflection[$moduleName])) {
            continue;
        }

        $package = \str_contains((string) ($unit['path'] ?? ''), '/libraries/') ? 'base' : 'main';
        $instances = [];
        foreach ($program->items as $item) {
            if (! $item instanceof Ast\DataDecl || isset($writtenInstances[$moduleName . "\0" . $item->name])) {
                continue;
            }

            // A constructor is typeable at its own kind as well as at the kind
            // of its saturation: `Typeable (W a)` is the declared type, and
            // `Typeable W` is the constructor itself, which anything that
            // builds a representation of a partially applied type asks for
            // (`gcast1` needs it for the `W` in `c (W a)`). GHC keeps the
            // latter in the solver rather than in an instance; here instances
            // are what a data declaration generates, so both are generated.
            if ($item->params === []) {
                $instances[] = synthesizedTypeableInstance($item, $moduleName, $package, false);
            } else {
                $instances[] = synthesizedTypeableInstance($item, $moduleName, $package, true);
                if (typeableConstructorInstanceKindIsConcrete($item)) {
                    $instances[] = synthesizedTypeableInstance($item, $moduleName, $package, false);
                }
            }
        }

        $unit['typeableInstances'] = $instances;
        if ($instances !== [] && $moduleName !== 'Type.Reflection') {
            foreach (synthesizedTypeableImports() as $import) {
                $unit['imports'][] = $import;
                $program->imports[] = $import;
            }
        }

        appendSyntheticUnitDecls($program, $unit);
    }
    unset($unit);
}

/**
 * The modules whose imports `$start` reaches, itself included.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array<string, true>
 */
function typeableReachedModules(array $units, string $start): array
{
    $reached = [];
    $stack = [$start];
    while ($stack !== []) {
        $moduleName = \array_pop($stack);
        if (isset($reached[$moduleName])) {
            continue;
        }
        $reached[$moduleName] = true;

        foreach ($units[$moduleName]['imports'] ?? [] as $import) {
            if ($import instanceof Ast\ImportDecl) {
                $stack[] = Ast\moduleName($import->path);
            }
        }
    }

    return $reached;
}

/**
 * The type constructors a module already gives a written 'Typeable' instance.
 *
 * A type constructor's name is not unique across a project -- `Sum` and `Product`
 * are declared both by `Data.Semigroup` and by `Data.Functor.Sum`/`Product` -- so
 * the answer is which *declaration* an instance covers, resolved the way the
 * writing module resolves the name: its own declarations first, then whatever it
 * imports. Keying by the bare name instead let `Type.Reflection`'s written
 * `Typeable Sum` suppress the instances `Data.Functor.Sum`'s own `Sum` needs.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array<string, true> keyed `"<module>\0<type>"`
 */
function manualTypeableTypeNames(array $units): array
{
    /** @var array<string, list<string>> $declaredIn */
    $declaredIn = [];
    /** @var list<array{0: string, 1: string}> $written */
    $written = [];
    foreach ($units as $moduleName => $unit) {
        $program = $unit['program'] ?? null;
        if (! $program instanceof Ast\Program) {
            continue;
        }

        foreach ($program->items as $item) {
            if ($item instanceof Ast\DataDecl) {
                $declaredIn[$item->name][] = $moduleName;

                continue;
            }
            if (! $item instanceof Ast\InstanceDecl) {
                continue;
            }

            $className = $item->class;
            $separator = \strrpos($className, '.');
            if ($separator !== false) {
                $className = \substr($className, $separator + 1);
            }
            if ($className !== 'Typeable') {
                continue;
            }

            $name = typeableTypeName($item->head);
            if ($name !== null) {
                $written[] = [$moduleName, $name];
            }
        }
    }

    $names = [];
    foreach ($written as [$moduleName, $name]) {
        $candidates = $declaredIn[$name] ?? [];
        $reachable = $candidates === [] ? [] : typeableReachedModules($units, $moduleName);
        $selected = [];
        foreach ($candidates as $candidate) {
            if ($candidate === $moduleName || isset($reachable[$candidate])) {
                $selected[] = $candidate;
            }
        }
        // Nothing in the writer's own imports declares the name: the instance can
        // only be about the declaration the checker will resolve it to, but that is
        // not known here, so every declaration of the name keeps its suppression.
        if ($selected === []) {
            $selected = $candidates;
        }

        foreach ($selected as $candidate) {
            $names[$candidate . "\0" . $name] = true;
        }
    }

    return $names;
}

/** The type constructor an instance head is applied to, or null when it is not a name. */
function typeableTypeName(Ast\TypeNode $head): ?string
{
    while ($head instanceof Ast\TypeApp) {
        $head = $head->con;
    }

    return $head instanceof Ast\TypeCon || $head instanceof Ast\TypeQualified ? $head->name : null;
}

/**
 * One synthesized instance and its method body.
 *
 * The head names the declaration's own spelling, which the module declaring
 * the type has in scope; the body reads each parameter's representation through
 * the instance's `Typeable` context, so the instance is as general as the data
 * declaration.
 */
/**
 * A parameter's type in a synthesized instance head.
 *
 * A GADT written without kind annotations (`data Coercion a b where`) leaves
 * its parameters as kind variables, so the instance's `Typeable a` context
 * never resolves. Pinning those parameters to `Type` gives the context a
 * concrete kind. Regular declarations keep their inferred kinds: `Rec1 f p`
 * needs `f` at `Type -> Type`.
 */
function typeableInstanceParameterType(Ast\DataParam $param, Ast\DataDecl $decl): Ast\TypeNode
{
    $isGadt = false;
    foreach ($decl->constructors as $ctor) {
        if ($ctor->signature !== null) {
            $isGadt = true;
            break;
        }
    }

    if ($isGadt && $param->kind instanceof Ast\KindInfer) {
        return new Ast\TypeKindAnnot($param->name, new Ast\KindType());
    }

    return new Ast\TypeVar($param->name);
}

/** Whether a bare instance for the constructor has a monomorphic kind. */
function typeableConstructorInstanceKindIsConcrete(Ast\DataDecl $decl): bool
{
    foreach ($decl->params as $param) {
        if (! $param->kind instanceof Ast\KindType) {
            return false;
        }
    }

    return true;
}

/**
 * `Typeable` instances for the built-in tuple constructors `Tuple8`..`Tuple64`.
 *
 * Lower arities are written in `Type.Reflection`; these are the same shape
 * generated once, so the representation and its kind stay in step with the
 * compiler's tuple table instead of being repeated 57 times.
 *
 * @return list<Ast\InstanceDecl>
 */
function synthesizedTupleTypeableInstances(): array
{
    $instances = [];
    for ($arity = 8; $arity <= 64; ++$arity) {
        $constraints = [];
        $arguments = [];
        $head = new Ast\TypeCon('Tuple' . $arity);

        for ($i = 1; $i <= $arity; ++$i) {
            $name = 'a' . $i;
            $head = new Ast\TypeApp($head, [new Ast\TypeVar($name)]);
            $constraints[] = new Ast\TypeApp(new Ast\TypeCon('Typeable'), [new Ast\TypeVar($name)]);
            $arguments[] = new Ast\Apply(
                new Ast\Variable('SomeTypeRep'),
                new Ast\TypeAsc(
                    new Ast\Variable('typeRep'),
                    new Ast\TypeApp(new Ast\TypeCon('TypeRep'), [new Ast\TypeVar($name)]),
                ),
            );
        }

        $kind = new Ast\Variable('trLiftedRep');
        for ($i = 0; $i < $arity; ++$i) {
            $kind = synthesizedApply(new Ast\Variable('kindFun'), [
                new Ast\Variable('trLiftedRep'),
                $kind,
            ]);
        }

        $body = synthesizedApply(new Ast\Variable('tupleRep'), [
            new Ast\StringLit('(' . \str_repeat(',', $arity - 1) . ')'),
            $kind,
            new Ast\ListLit($arguments),
        ]);

        $instances[] = new Ast\InstanceDecl(
            'Typeable',
            $head,
            [new Ast\FunctionDecl(
                'typeRepOf',
                null,
                [],
                $body,
                instanceMethod: true,
                line: 1,
                col: 1,
                endCol: 1,
            )],
            $constraints,
            1,
            1,
            1,
        );
    }

    return $instances;
}

function synthesizedTypeableInstance(Ast\DataDecl $decl, string $moduleName, string $package, bool $withArguments): Ast\InstanceDecl
{
    $head = new Ast\TypeCon($decl->name);
    $constraints = [];
    $arguments = [];
    if ($withArguments) {
        foreach ($decl->params as $param) {
            $head = new Ast\TypeApp($head, [typeableInstanceParameterType($param, $decl)]);
            $constraints[] = new Ast\TypeApp(new Ast\TypeCon('Typeable'), [new Ast\TypeVar($param->name)]);
            $arguments[] = new Ast\Apply(
                new Ast\QualifiedRef('MoggiTypeable', 'SomeTypeRep'),
                new Ast\TypeAsc(
                    new Ast\QualifiedRef('MoggiTypeable', 'typeRep'),
                    new Ast\TypeApp(new Ast\TypeQualified('MoggiTypeable', 'TypeRep'), [new Ast\TypeVar($param->name)]),
                ),
            );
        }
    }

    $body = synthesizedApply(new Ast\QualifiedRef('MoggiTypeable', 'mkTyConApp'), [
        synthesizedApply(new Ast\QualifiedRef('MoggiTypeable', 'mkTyCon'), [
            new Ast\StringLit($moduleName),
            new Ast\StringLit($package),
            new Ast\StringLit($decl->name),
        ]),
        new Ast\ListLit($arguments),
        typeableKindRepresentation(typeableDeclarationKind($decl->params)),
    ]);

    return new Ast\InstanceDecl(
        'Typeable',
        $head,
        [new Ast\FunctionDecl(
            'typeRepOf',
            null,
            [],
            $body,
            instanceMethod: true,
            line: $decl->line,
            col: $decl->col,
            endCol: $decl->endCol,
        )],
        $constraints,
        $decl->line,
        $decl->col,
        $decl->endCol,
    );
}

/** @param list<Ast\AstNode> $arguments */
function synthesizedApply(Ast\AstNode $function, array $arguments): Ast\AstNode
{
    foreach ($arguments as $argument) {
        $function = new Ast\Apply($function, $argument);
    }

    return $function;
}

/**
 * The kind of the declared type: one arrow per parameter, ending in `Type`.
 *
 * @param list<Ast\DataParam> $params
 */
function typeableDeclarationKind(array $params): Ast\KindNode
{
    $kind = new Ast\KindType();
    foreach (\array_reverse($params) as $param) {
        $kind = new Ast\KindArrow($param->kind, $kind);
    }

    return $kind;
}

/** The representation of a kind, as the builders 'kindFun' and 'trLiftedRep' spell it. */
function typeableKindRepresentation(Ast\KindNode $kind): Ast\AstNode
{
    if ($kind instanceof Ast\KindArrow) {
        return synthesizedApply(new Ast\QualifiedRef('MoggiTypeable', 'kindFun'), [
            typeableKindRepresentation($kind->from),
            typeableKindRepresentation($kind->to),
        ]);
    }

    return new Ast\QualifiedRef('MoggiTypeable', 'trLiftedRep');
}

/**
 * The imports that put the class and the representation builders in scope.
 *
 * Only the class is imported unqualified: an instance head names its class
 * without a qualifier. The builders are imported under an alias of the
 * compiler's own, because the names a module has in scope are not the
 * compiler's to rely on -- `Data.Typeable` exports @TypeRep@ as the synonym
 * @SomeTypeRep@, so an unqualified @TypeRep a@ in generated code would be an
 * application of a constructor that takes no arguments.
 *
 * @return list<Ast\ImportDecl>
 */
function synthesizedTypeableImports(): array
{
    return [
        new Ast\ImportDecl(
            ['Type.Reflection'],
            'named',
            [new Ast\ImportItem('Typeable')],
        ),
        new Ast\ImportDecl(
            ['Type.Reflection'],
            'qualifiedAs',
            [
                new Ast\ImportItem('typeRep'),
                new Ast\ImportItem('TypeRep'),
                new Ast\ImportItem('SomeTypeRep', methods: 'every'),
                new Ast\ImportItem('mkTyCon'),
                new Ast\ImportItem('mkTyConApp'),
                new Ast\ImportItem('kindFun'),
                new Ast\ImportItem('trLiftedRep'),
            ],
            asName: 'MoggiTypeable',
            qualifiedOnly: true,
        ),
    ];
}

/**
 * Parse a module again, this time knowing the pattern synonyms it imports.
 *
 * A synonym expands while the module is parsed, so an imported one has to be in
 * the parser's table before that parse. The definitions exist only once the
 * declaring modules are checked -- which the importing module's own check
 * already requires -- so the parse is redone immediately before that check.
 *
 * @param array<string, array<string, mixed>> $units
 * @param array<string, array<string, mixed>> $patternSynonyms
 */
function reparseModuleWithPatternSynonyms(
    array &$units,
    string $moduleName,
    array $patternSynonyms,
    array $qualifiedPatternSynonyms = [],
): void {
    $table = $patternSynonyms;
    foreach ($units[$moduleName]['imports'] ?? [] as $import) {
        if (! $import instanceof Ast\ImportDecl) {
            continue;
        }

        $target = Ast\moduleName($import->path);
        $prefixes = [$import->asName ?? $target];
        if ($import->asName !== null && ! $import->qualifiedOnly) {
            $prefixes[] = $target;
        }

        foreach ($qualifiedPatternSynonyms as $name => $definition) {
            if (($definition['module'] ?? null) !== $target) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                $table[$prefix . '.' . $name] = $definition;
            }
        }
    }

    $units[$moduleName]['importedPatternSynonyms'] = $table;
    $units[$moduleName]['parsed'] = false;
    unset($units[$moduleName]['tokens'], $units[$moduleName]['program']);
    ensureUnitProgram($units, $moduleName, $table);
}

/** @return list<string> */
function mergedHalfFields(): array
{
    return ['halfPath', 'halfSource', 'halfImports', 'halfLocalFixity'];
}

/**
 * One compilation unit per module: the half a facade's pragma selects stops being
 * a module of its own and its source joins the facade's unit, so the checked
 * program, the emitted path and the symbols all carry the facade's name.
 *
 * @param array<string, array<string, mixed>> $pending
 * @param array<string, array<string, array{assoc: string, prec: int}>> $fixityByModule
 * @param ?string $entryPath the file the compile started from
 */
function mergeFacadeHalves(array &$pending, array &$fixityByModule, string $backendId, ?string $entryPath): void
{
    $halves = [];
    $selected = [];
    foreach ($pending as $moduleName => $info) {
        $backendMap = $info['backendMap'] ?? [];
        if ($backendMap === []) {
            continue;
        }

        $halfName = resolveImplModuleName($backendMap, $backendId);
        if ($halfName === null) {
            throw facadeMissingBackendError($moduleName, $backendMap, $backendId, $info);
        }

        if (! isset($pending[$halfName])) {
            throw new TypeError(
                "module `{$moduleName}` names `{$halfName}` as its `{$backendId}` implementation, which is not"
                . ' part of this compilation',
                (string) $info['path'],
                (string) $info['source'],
                (int) ($info['headerSpan']['line'] ?? 0),
                (int) ($info['headerSpan']['col'] ?? 0),
                (int) ($info['headerSpan']['endCol'] ?? 0),
            );
        }

        foreach ($backendMap as $impl) {
            $owner = $halves[$impl] ?? null;
            if ($owner !== null && $owner !== $moduleName) {
                throw new TypeError(
                    "module `{$impl}` is the implementation of `{$owner}` and of `{$moduleName}`:"
                    . ' one half supplies the bindings of one facade',
                );
            }

            $halves[$impl] = $moduleName;
        }

        $selected[$moduleName] = $halfName;
    }

    if ($entryPath !== null) {
        $entryModule = cachedModuleHeader($entryPath)['module'] ?? null;
        if (\is_string($entryModule) && isset($halves[$entryModule])) {
            throw new TypeError(
                "`{$entryModule}` is the `{$backendId}` implementation of `{$halves[$entryModule]}`: it is merged"
                . " into that module, so compile `{$halves[$entryModule]}` instead",
                $entryPath,
                (string) \file_get_contents($entryPath),
            );
        }
    }

    foreach ($selected as $facadeName => $halfName) {
        $half = $pending[$halfName];
        $facade = &$pending[$facadeName];
        $facade['halfPath'] = $half['path'];
        $facade['halfSource'] = $half['source'];
        $facade['halfImports'] = $half['imports'];
        $facade['halfLocalFixity'] = $half['localFixity'];
        $facade['imports'] = mergeUnitImports($facade['imports'], $half['imports']);
        $facade['language'] = ($facade['language'] ?? []) + ($half['language'] ?? []);
        $fixityByModule[$facadeName] = mergeFixity($fixityByModule[$facadeName] ?? [], $half['localFixity']);
        unset($facade);
    }

    foreach (\array_keys($halves) as $halfName) {
        unset($pending[$halfName], $fixityByModule[$halfName]);
    }
}
