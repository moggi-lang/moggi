<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\IR;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;

use function Moggi\Backend\Php\Naming\basePhpFunctionName;
use function Moggi\Backend\compileBackend;
use function Moggi\Errors\appendDidYouMean;
use function Moggi\IR\Visit\collectIrCodegenUsage;
use function Moggi\Semantics\Kinds\dataKind;
use function Moggi\Semantics\Kinds\registerTypeKind;
use function Moggi\Semantics\TypeExpr\schemeRuntimeArity;
use function Moggi\Semantics\Types\evidenceFunctionName;
use function Moggi\Syntax\Ast\moduleName;
use function Moggi\Syntax\isConstructorName;

/**
 * @return array{
 *   origins: array<string, array{namespace: string, phpName: string, module: string}>,
 *   arity: array<string, int>
 * }
 */
function moduleEvidenceMeta(
    Ast\Program $program,
    string $moduleName,
    string $namespace,
    string $cacheKey = '',
): array {
    // Stdlib evidence seeding runs on every prepare. Cache by module+source so
    // LSP analyzing many small files does not re-hash TupleN heads each time.
    static $cache = [];
    $key = $cacheKey !== ''
        ? $cacheKey
        : ($moduleName . "\0" . $namespace . "\0" . spl_object_id($program));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $origins = [];
    $arity = [];
    foreach ($program->instanceEvidence as $ev) {
        if (\is_array($ev)) {
            $name = $ev['evidenceName'] ?? null;
            if (!\is_string($name)) {
                continue;
            }
            $arity[$name] = count($ev['contextParams'] ?? []);
            $origins[$name] = [
                'namespace' => $namespace,
                'phpName' => $name,
                'module' => $moduleName,
            ];
            continue;
        }
        $arity[$ev->evidenceName] = count($ev->contextParams);
        $origins[$ev->evidenceName] = [
            'namespace' => $namespace,
            'phpName' => $ev->evidenceName,
            'module' => $moduleName,
        ];
    }

    foreach ($program->items as $item) {
        if (!$item instanceof Ast\InstanceDecl) {
            continue;
        }
        $name = evidenceFunctionName($item->class, $item->head);
        if (!isset($origins[$name])) {
            $origins[$name] = [
                'namespace' => $namespace,
                'phpName' => $name,
                'module' => $moduleName,
            ];
        }
        if (!isset($arity[$name])) {
            $arity[$name] = count($item->constraints);
        }
    }

    return $cache[$key] = ['origins' => $origins, 'arity' => $arity];
}

/** @return array<string, array{namespace: string, phpName: string, module: string}> */
function moduleEvidenceOrigins(Ast\Program $program, string $moduleName, string $namespace): array
{
    return moduleEvidenceMeta($program, $moduleName, $namespace)['origins'];
}

/**
 * Evidence origins/arity for one unit, merging its pre-check program with its
 * checked program.
 *
 * The pre-check AST carries hand-written `instance` declarations, but inline
 * `deriving (…)` clauses only expand into evidence functions during type
 * checking, so their factories exist solely on the checked program. Scanning
 * just one of the two misses cases: the pre-check program misses every derived
 * instance (`instance Eq (WrapA)` synthesized from `deriving`), while the
 * checked program can drop declarations the pre-check tree still holds.
 *
 * @param array<string, mixed> $unit
 * @return array{origins: array<string, array{namespace: string, phpName: string, module: string}>, arity: array<string, int>}
 */
function unitEvidenceMeta(array $unit, string $moduleName, string $namespace, string $cacheKey = ''): array
{
    $origins = [];
    $arity = [];
    foreach (['program', 'checkedProgram'] as $slot) {
        $program = $unit[$slot] ?? null;
        if (!($program instanceof Ast\Program)) {
            continue;
        }

        $key = $cacheKey === '' ? '' : $cacheKey . "\0" . $slot;
        $meta = moduleEvidenceMeta($program, $moduleName, $namespace, $key);
        foreach ($meta['origins'] as $evName => $origin) {
            if (isset($origins[$evName])) {
                continue;
            }
            $origins[$evName] = $origin;
            $arity[$evName] = $meta['arity'][$evName] ?? 0;
        }
    }

    return ['origins' => $origins, 'arity' => $arity];
}

/**
 * Map evidence factory name → runtime arity for one program.
 *
 * @return array<string, int>
 */
function moduleEvidenceArityMap(Ast\Program $program): array
{
    return moduleEvidenceMeta($program, '', '')['arity'];
}

/**
 * Names a module defines whose body is an action *value*: callers receive a
 * boxed action and have to run it. `ioBodyKind` never reaches the exported
 * scheme, so this is read straight off the defining module's checked program.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array<string, true>
 */
function moduleActionReturnFnNames(array $units, string $moduleName): array
{
    /** @var array<string, array<string, true>> $cache */
    static $cache = [];

    $unit = $units[$moduleName] ?? null;
    $key = \is_array($unit)
        ? $moduleName . "\0" . hash('xxh3', (string) ($unit['source'] ?? '') . "\0" . (string) ($unit['path'] ?? ''))
        : $moduleName;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $program = \is_array($unit) ? ($unit['checkedProgram'] ?? null) : null;
    $names = [];
    if ($program instanceof Ast\Program) {
        foreach ($program->items as $item) {
            if ($item instanceof Ast\FunctionDecl && $item->ioBodyKind === IR\IoBodyKind::ActionReturn) {
                $names[$item->name] = true;
            }
        }
    }

    return $cache[$key] = $names;
}

/**
 * Runtime arity of an evidence factory: unconstrained instances are nullary;
 * `C a => C (F a)` takes one dictionary per instance-context constraint
 * (including superclasses once the defining module has been type-checked).
 *
 * @param array<string, array<string, mixed>> $units
 */
function moduleEvidenceArity(array $units, string $moduleName, string $evName): int
{
    $unit = $units[$moduleName] ?? null;
    $program = \is_array($unit)
        ? ($unit['checkedProgram'] ?? $unit['program'] ?? null)
        : null;
    if (!($program instanceof Ast\Program)) {
        return 0;
    }
    $namespace = (string) ($unit['namespace'] ?? '');
    $cacheKey = $moduleName . "\0" . $namespace . "\0" . hash(
        'xxh3',
        (string) ($unit['source'] ?? '') . "\0" . (string) ($unit['path'] ?? ''),
    );

    return moduleEvidenceMeta($program, $moduleName, $namespace, $cacheKey)['arity'][$evName] ?? 0;
}

/**
 * Runtime parameter count of an exported function, from the defining module's
 * *checked* program (constraint evidence parameters are already included, so
 * this agrees with the scheme's runtime arity for ordinary definitions).
 *
 * Returns null when the module has not been checked yet, in which case callers
 * fall back to the scheme-derived arity.
 *
 * @param array<string, array<string, mixed>> $units
 */
function moduleDeclaredFunctionArity(array $units, string $moduleName, string $name): ?int
{
    $unit = $units[$moduleName] ?? null;
    if (!\is_array($unit)) {
        return null;
    }

    $program = $unit['checkedProgram'] ?? null;
    if (!($program instanceof Ast\Program)) {
        return null;
    }

    static $cache = [];
    $key = $moduleName . "\0" . hash(
        'xxh3',
        (string) ($unit['source'] ?? '') . "\0" . (string) ($unit['path'] ?? ''),
    );
    if (!isset($cache[$key])) {
        $arities = [];
        foreach ($program->items as $item) {
            if ($item instanceof Ast\FunctionDecl && !$item->signatureOnly) {
                $arities[$item->name] = \count($item->params);
            }
        }
        $cache[$key] = $arities;
    }

    return $cache[$key][$name] ?? null;
}

/**
 * Runtime arity of an imported function: the number of parameters its
 * definition actually declares, not the arrow count of its type. The two
 * differ for eta-reduced definitions — `($) f = f` has type
 * `(a -> b) -> a -> b` but a single parameter — and handing such a function
 * more arguments than it declares has to go through the runtime `__apply`
 * trampoline instead of a direct call.
 *
 * @param array<string, array<string, mixed>> $units
 */
function externalFunctionRuntimeArity(
    array $units,
    ?string $moduleName,
    string $name,
    Scheme $scheme,
): int {
    if ($moduleName !== null) {
        $declared = moduleDeclaredFunctionArity($units, $moduleName, $name);
        if ($declared !== null) {
            return $declared;
        }
    }

    return schemeRuntimeArity($scheme);
}

/**
 * @param list<Ast\ImportDecl> $imports
 * @param array<string, array<string, mixed>> $units
 * @return array{
 *   env: array<string, Scheme>,
 *   typeSynonyms: array<string, array<string, mixed>>,
 *   data: array<string, array<string, mixed>>,
 *   qualifiedModules: array<string, string>,
 *   constructorRenames: array<string, string>,
 *   codegen: array{
 *     requireLines: list<string>,
 *     functionUseLines: list<string>,
 *     namespaceUseLines: list<string>,
 *     externalFns: array<string, string>,
 *     moduleAsNames: array<string, string>,
 *     constructorRenames: array<string, string>
 *   }
 * }
 */
function buildImportContext(
    array $imports,
    array $units,
    string $currentModule,
    string $currentOutputRelative,
    string $rootPrefix,
): array {
    $planPhpImports = compileBackend() === 'php';
    $env = [];
    $typeSynonyms = [];
    $data = [];
    $qualifiedModules = [];
    $constructorRenames = [];
    $moduleAsNames = [];
    $qualifiedOrigins = [];
    $qualifiedEnv = [];
    $requireTargets = [];
    $namespaceUseLines = [];
    $externalFns = [];
    $externalFnRuntimeArity = [];
    $actionReturnFns = [];
    $fnOriginModules = [];
    $intrinsicWrappers = [];
    $moduleOutputPaths = [];
    $namespaceModules = [];
    $implicitNames = [];
    $ambiguousNames = [];

    foreach ($imports as $import) {
        $importLine = $import->line;
        $importCol = $import->col;
        $importEndCol = $import->endCol;
        $targetName = moduleName($import->path);
        if (!isset($units[$targetName])) {
            throw new TypeError(
                appendDidYouMean(
                    "unknown module `{$targetName}`",
                    $targetName,
                    \array_keys($units),
                ),
                $units[$currentModule]['path'] ?? '',
                $units[$currentModule]['source'] ?? '',
                $importLine,
                $importCol,
                $importEndCol,
            );
        }

        $target = $units[$targetName];
        $namespace = $target['namespace'];
        try {
            $exports = $target['exports'] ?? collectExports(
                $target['program'],
                $target['localTypes'],
                $units,
                $targetName,
            );
        } catch (TypeError $e) {
            if ($e->filename !== '') {
                throw $e;
            }

            throw new TypeError(
                $e->getMessage(),
                $units[$currentModule]['path'],
                $units[$currentModule]['source'],
                $importLine,
                $importCol,
                $importEndCol,
            );
        }

        $localPrefix = $import->asName ?? $targetName;
        $qualifiedModules[$localPrefix] = $targetName;

        if ($targetName !== $currentModule) {
            if (!(($target['synthetic'] ?? false) === true)) {
                $targetOutputRelative = mogPathToOutputRelative($target['path'], $rootPrefix);
                $moduleOutputPaths[$targetName] = $targetOutputRelative;
                if ($planPhpImports) {
                    $requireTargets[$targetName] = relativeRequirePath($currentOutputRelative, $targetOutputRelative);
                }
            }
        }

        $namespaceModules[$namespace] = $targetName;

        try {
            $selected = selectImportedNames($import, $exports);
        } catch (TypeError $e) {
            if ($e->filename !== '') {
                throw $e;
            }

            throw new TypeError(
                $e->getMessage(),
                $units[$currentModule]['path'],
                $units[$currentModule]['source'],
                $importLine,
                $importCol,
                $importEndCol,
            );
        }

        foreach ($selected['env'] as $name => $scheme) {
            $origin = (!$scheme->classMethod && isset($selected['origins'][$name]))
                ? $selected['origins'][$name]
                : null;

            // Always record under the module prefix so qualified refs work
            // (including `import M qualified as P` and local shadowing).
            if (!$scheme->classMethod) {
                $qualifiedEnv[$localPrefix][$name] = $scheme;
                if ($origin !== null && $origin['module'] !== $targetName) {
                    $qualifiedOrigins[$localPrefix][$name] = $origin;
                }
                if ($origin !== null && $planPhpImports) {
                    addOriginRequire($requireTargets, $moduleOutputPaths, $origin, $currentModule, $currentOutputRelative, $units, $rootPrefix);
                }
                if (isset($selected['intrinsicWrappers'][$name])) {
                    $intrinsicWrappers[$name] = $selected['intrinsicWrappers'][$name];
                }
            }

            if ($import->qualifiedOnly) {
                // Codegen still needs bare-name → resolved mapping (and arity)
                // for `Alias.name` FnRefs even when the name is not in scope.
                // Never clobber an origin already established by an unqualified import.
                if ($origin !== null && !isset($fnOriginModules[$name])) {
                    $externalFns[$name] = resolvedSymbol($origin['module'], $origin['phpName']);
                    $externalFnRuntimeArity[$name] = externalFunctionRuntimeArity(
                        $units,
                        $origin['module'],
                        $name,
                        $scheme,
                    );
                    $fnOriginModules[$name] = $origin['module'];
                    if (isset(moduleActionReturnFnNames($units, $origin['module'])[$origin['phpName']])) {
                        $actionReturnFns[$name] = true;
                    }
                }
                continue;
            }

            if (isset($env[$name])) {
                if ($import->implicit) {
                    continue;
                }

                if ($env[$name]->classMethod !== $scheme->classMethod) {
                    // Class-method slots and concrete exports may share a name.
                } elseif (!isset($implicitNames[$name])
                    && !($env[$name]->classMethod && $scheme->classMethod)
                    && (($fnOriginModules[$name] ?? null) !== ($selected['origins'][$name]['module'] ?? null))) {
                    // Importing two entities under one name is legal -- the name is
                    // simply ambiguous, and naming it is the error. Recorded here,
                    // reported where it is used or re-exported.
                    $ambiguousNames[$name] = [
                        'origins' => \array_values(\array_unique([
                            ...($ambiguousNames[$name]['origins'] ?? [$fnOriginModules[$name] ?? '']),
                            $selected['origins'][$name]['module'] ?? '',
                        ])),
                    ];
                    // No symbol either: an unspellable name needs no import, and a
                    // `use function` for one alias would collide in the artifact.
                    unset($externalFns[$name], $externalFnRuntimeArity[$name]);
                }
            }

            if (isset($ambiguousNames[$name])) {
                continue;
            }

            $env[$name] = $scheme;
            if ($import->implicit) {
                $implicitNames[$name] = true;
            }

            if ($scheme->classMethod || $origin === null) {
                continue;
            }

            $externalFns[$name] = resolvedSymbol($origin['module'], $origin['phpName']);
            $externalFnRuntimeArity[$name] = externalFunctionRuntimeArity(
                $units,
                $origin['module'],
                $name,
                $scheme,
            );
            $fnOriginModules[$name] = $origin['module'];
            if (isset(moduleActionReturnFnNames($units, $origin['module'])[$origin['phpName']])) {
                $actionReturnFns[$name] = true;
            }
        }

        if ($targetName !== $currentModule) {
            $targetMeta = unitEvidenceMeta($target, $targetName, $namespace);
            foreach ($targetMeta['origins'] as $evName => $origin) {
                $externalFns[$evName] = resolvedSymbol($origin['module'], $origin['phpName']);
                $externalFnRuntimeArity[$evName] = moduleEvidenceArity($units, $targetName, $evName);
                $fnOriginModules[$evName] = $origin['module'];
            }
        }

        if (!$import->qualifiedOnly) {
            foreach ($selected['constructorRenames'] as $local => $canonical) {
                $constructorRenames[$local] = $canonical;
            }
        }

        // Types remain resolvable for `Alias.T` even under qualified-only imports.
        foreach ($selected['typeSynonyms'] as $name => $type) {
            $typeSynonyms[$name] = $type;
        }

        foreach ($selected['data'] as $name => $info) {
            $data[$name] = $info;
        }

        if ($planPhpImports && ($import->kind === 'glob' || $import->kind === 'named')) {
            foreach ($exports['qualifiedModules'] as $prefix => $originModule) {
                $qualifiedModules[$prefix] = $originModule;
            }

            foreach ($exports['moduleAsNames'] as $originNamespace => $asName) {
                $moduleAsNames[$originNamespace] = $asName;
                $namespaceUseLines[] = "{$originNamespace} as {$asName}";
            }
        } elseif ($import->kind === 'glob' || $import->kind === 'named') {
            foreach ($exports['qualifiedModules'] as $prefix => $originModule) {
                $qualifiedModules[$prefix] = $originModule;
            }
        }

        if ($planPhpImports && $import->kind === 'qualifiedAs' && $import->asName !== null) {
            $namespaceUseLines[] = "{$namespace} as {$import->asName}";
            $moduleAsNames[$namespace] = $import->asName;
        }
    }

    // Instances resolve globally, so every project instance's evidence is seeded as a
    // codegen candidate; import pruning keeps only what is used.
    foreach ($units as $unitName => $unit) {
        if ($unitName === $currentModule) {
            continue;
        }

        $cacheKey = $unitName . "\0" . $unit['namespace'] . "\0" . hash(
            'xxh3',
            (string) ($unit['source'] ?? '') . "\0" . (string) ($unit['path'] ?? ''),
        );
        $meta = unitEvidenceMeta($unit, $unitName, $unit['namespace'], $cacheKey);
        foreach ($meta['origins'] as $evName => $origin) {
            if (isset($externalFns[$evName])) {
                continue;
            }

            $externalFns[$evName] = resolvedSymbol($origin['module'], $origin['phpName']);
            $externalFnRuntimeArity[$evName] = $meta['arity'][$evName] ?? 0;
            $fnOriginModules[$evName] = $origin['module'];
            if ($planPhpImports) {
                addOriginRequire(
                    $requireTargets,
                    $moduleOutputPaths,
                    $origin,
                    $currentModule,
                    $currentOutputRelative,
                    $units,
                    $rootPrefix,
                );
            }
        }
    }

    $namespaceUseLines = $planPhpImports ? dedupeUseLines($namespaceUseLines) : [];

    mergeFacadeBackendExports(
        $env,
        $externalFns,
        $externalFnRuntimeArity,
        $actionReturnFns,
        $fnOriginModules,
        $intrinsicWrappers,
        $requireTargets,
        $moduleOutputPaths,
        $units,
        $currentModule,
        $currentOutputRelative,
        $rootPrefix,
        $planPhpImports,
    );

    mergePreludeBuiltinEnv(
        $env,
        $externalFns,
        $externalFnRuntimeArity,
        $actionReturnFns,
        $fnOriginModules,
        $intrinsicWrappers,
        $requireTargets,
        $moduleOutputPaths,
        $units,
        $currentModule,
        $currentOutputRelative,
        $rootPrefix,
        $planPhpImports,
    );

    $requireLines = [];
    $functionUseLines = [];
    if ($planPhpImports) {
        $requireLines = array_values(array_unique($requireTargets));
        sort($requireLines, SORT_STRING);

        foreach ($externalFns as $local => $resolved) {
            $functionUseLines[] = functionUseLine($local, $resolved);
        }
        $functionUseLines = dedupeUseLines($functionUseLines);
    }

    return [
        'env' => $env,
        'typeSynonyms' => $typeSynonyms,
        'data' => $data,
        'qualifiedModules' => $qualifiedModules,
        'constructorRenames' => $constructorRenames,
        'qualifiedOrigins' => $qualifiedOrigins,
        'qualifiedEnv' => $qualifiedEnv,
        'codegen' => [
            'requireLines' => $requireLines,
            'functionUseLines' => $functionUseLines,
            'namespaceUseLines' => $namespaceUseLines,
            'externalFns' => $externalFns,
            'externalFnRuntimeArity' => $externalFnRuntimeArity,
            'moduleAsNames' => $moduleAsNames,
            'constructorRenames' => $constructorRenames,
            'fnOriginModules' => $fnOriginModules,
            'moduleOutputPaths' => $moduleOutputPaths,
            'currentOutputRelative' => $currentOutputRelative,
            'namespaceModules' => $namespaceModules,
        ],
        'externalFnRuntimeArity' => $externalFnRuntimeArity,
        'externalActionReturnFns' => $actionReturnFns,
        'intrinsicWrappers' => $intrinsicWrappers,
        'ambiguousNames' => $ambiguousNames,
    ];
}

/**
 * @param array<string, mixed> $impl
 * @param array<string, array<string, mixed>> $units
 * @return array<string, mixed>|null
 */
function implExportsForFacadeMerge(array $impl, array $units, string $implName): ?array
{
    if (isset($impl['exports']) && \is_array($impl['exports'])) {
        return $impl['exports'];
    }

    if (isset($impl['program'], $impl['localTypes']) && \is_array($impl['localTypes'])) {
        return collectExports(
            $impl['program'],
            $impl['localTypes'],
            $units,
            $implName,
        );
    }

    return null;
}

/**
 * @param array<string, Scheme> $env
 * @param array<string, string> $intrinsicWrappers
 * @param array<string, true> $skipNames
 * @param array<string, array<string, mixed>> $units
 */
function mergeFacadeBackendEnv(
    array &$env,
    array &$intrinsicWrappers,
    array $units,
    string $currentModule,
    array $skipNames = [],
    ?TypeCheckState $state = null,
): void {
    $currentProgram = $units[$currentModule]['program'] ?? null;
    if (!$currentProgram instanceof Ast\Program || !isFacadeProgram($currentProgram)) {
        return;
    }

    // A facade with no implementation for this backend is a compile error, reported here rather
    // than silently leaving its API unimplemented.
    $implName = facadeImplModuleNameFor($currentModule, $units);
    if ($implName === null || !isset($units[$implName])) {
        return;
    }

    $impl = $units[$implName];
    $implExports = implExportsForFacadeMerge($impl, $units, $implName);
    if ($implExports === null) {
        return;
    }

    foreach ($implExports['env'] as $name => $scheme) {
        if (isset($env[$name]) || isset($skipNames[$name])) {
            continue;
        }

        $env[$name] = $scheme;
        if (isset($implExports['intrinsicWrappers'][$name])) {
            $intrinsicWrappers[$name] = $implExports['intrinsicWrappers'][$name];
        }
    }

    // Facade signatures name foreign types declared only in the impl
    // (e.g. Encoding). Install those types into the facade typecheck state.
    if ($state !== null) {
        foreach ($implExports['data'] ?? [] as $name => $info) {
            if (isset($state->data[$name])) {
                continue;
            }
            $state->data[$name] = $info;
            if (isset($info['foreign']) || ($info['constructors'] ?? null) !== null) {
                registerTypeKind(
                    $state,
                    $name,
                    dataKind(count($info['params'] ?? [])),
                );
            }
        }
    }
}

/**
 * @param array<string, Scheme> $env
 * @param array<string, string> $externalFns
 * @param array<string, int> $externalFnRuntimeArity
 * @param array<string, string> $fnOriginModules
 * @param array<string, string> $intrinsicWrappers
 * @param array<string, string> $requireTargets
 * @param array<string, string> $moduleOutputPaths
 * @param array<string, true> $actionReturnFns
 * @param array<string, array<string, mixed>> $units
 */
function mergeFacadeBackendExports(
    array &$env,
    array &$externalFns,
    array &$externalFnRuntimeArity,
    array &$actionReturnFns,
    array &$fnOriginModules,
    array &$intrinsicWrappers,
    array &$requireTargets,
    array &$moduleOutputPaths,
    array $units,
    string $currentModule,
    string $currentOutputRelative,
    string $rootPrefix,
    bool $planPhpImports = true,
): void {
    mergeFacadeBackendEnv($env, $intrinsicWrappers, $units, $currentModule);

    $currentProgram = $units[$currentModule]['program'] ?? null;
    if (!$currentProgram instanceof Ast\Program || !isFacadeProgram($currentProgram)) {
        return;
    }

    $implName = facadeImplModuleNameFor($currentModule, $units);
    if ($implName === null || !isset($units[$implName])) {
        return;
    }

    $impl = $units[$implName];
    $implExports = implExportsForFacadeMerge($impl, $units, $implName);
    if ($implExports === null) {
        return;
    }

    foreach ($implExports['env'] as $name => $scheme) {
        if ($scheme->classMethod) {
            continue;
        }

        if (isset($externalFns[$name])) {
            continue;
        }

        $origin = $implExports['origins'][$name];
        $externalFns[$name] = resolvedSymbol($origin['module'], $origin['phpName']);
        $externalFnRuntimeArity[$name] = externalFunctionRuntimeArity(
            $units,
            $origin['module'],
            $name,
            $scheme,
        );
        $fnOriginModules[$name] = $origin['module'];
        if (isset(moduleActionReturnFnNames($units, $origin['module'])[$origin['phpName']])) {
            $actionReturnFns[$name] = true;
        }

        if ($planPhpImports) {
            addOriginRequire(
                $requireTargets,
                $moduleOutputPaths,
                $origin,
                $currentModule,
                $currentOutputRelative,
                $units,
                $rootPrefix,
            );
        }
    }
}

/**
 * @param array<string, string> $requireTargets
 * @param array<string, string> $moduleOutputPaths
 * @param array{module: string, namespace: string, phpName: string} $origin
 * @param array<string, array<string, mixed>> $units
 */
function addOriginRequire(
    array &$requireTargets,
    array &$moduleOutputPaths,
    array $origin,
    string $currentModule,
    string $currentOutputRelative,
    array $units,
    string $rootPrefix,
): void {
    $originModule = $origin['module'];
    if ($originModule === $currentModule || !isset($units[$originModule])) {
        return;
    }

    $originOutputRelative = mogPathToOutputRelative($units[$originModule]['path'], $rootPrefix);
    $moduleOutputPaths[$originModule] = $originOutputRelative;
    $requireTargets[$originModule] = relativeRequirePath($currentOutputRelative, $originOutputRelative);
}

/** Prelude exports these without an explicit import in library modules. */
/** @return array<string, string> */
function preludeBuiltinOrigins(): array
{
    return [
        'error' => 'Moggi.Err',
        'undefined' => 'Moggi.Err',
    ];
}

/**
 * @param array<string, Scheme> $env
 * @param array<string, string> $externalFns
 * @param array<string, int> $externalFnRuntimeArity
 * @param array<string, string> $fnOriginModules
 * @param array<string, string> $intrinsicWrappers
 * @param array<string, string> $requireTargets
 * @param array<string, string> $moduleOutputPaths
 * @param array<string, true> $actionReturnFns
 * @param array<string, array<string, mixed>> $units
 */
function mergePreludeBuiltinEnv(
    array &$env,
    array &$externalFns,
    array &$externalFnRuntimeArity,
    array &$actionReturnFns,
    array &$fnOriginModules,
    array &$intrinsicWrappers,
    array &$requireTargets,
    array &$moduleOutputPaths,
    array $units,
    string $currentModule,
    string $currentOutputRelative,
    string $rootPrefix,
    bool $planPhpImports = true,
): void {
    if (\in_array($currentModule, ['Prelude', 'Moggi.Err'], true)) {
        return;
    }

    foreach (preludeBuiltinOrigins() as $symbol => $originModule) {
        if (isset($env[$symbol]) || $currentModule === $originModule || !isset($units[$originModule])) {
            continue;
        }

        $origin = $units[$originModule];
        $exports = $origin['exports'] ?? collectExports(
            $origin['program'],
            $origin['localTypes'],
            $units,
            $originModule,
        );

        $scheme = $exports['env'][$symbol] ?? null;
        if (!$scheme instanceof Scheme) {
            continue;
        }

        $env[$symbol] = $scheme;
        $namespace = $origin['namespace'];
        $externalFns[$symbol] = resolvedSymbol($originModule, $symbol);
        $externalFnRuntimeArity[$symbol] = externalFunctionRuntimeArity(
            $units,
            $originModule,
            $symbol,
            $scheme,
        );
        $fnOriginModules[$symbol] = $originModule;
        if (isset(moduleActionReturnFnNames($units, $originModule)[$symbol])) {
            $actionReturnFns[$symbol] = true;
        }
        if (isset($exports['intrinsicWrappers'][$symbol])) {
            $intrinsicWrappers[$symbol] = $exports['intrinsicWrappers'][$symbol];
        }

        if (!$planPhpImports) {
            continue;
        }

        $originInfo = $exports['origins'][$symbol] ?? [
            'module' => $originModule,
            'namespace' => $namespace,
            'phpName' => $symbol,
        ];
        addOriginRequire(
            $requireTargets,
            $moduleOutputPaths,
            $originInfo,
            $currentModule,
            $currentOutputRelative,
            $units,
            $rootPrefix,
        );
    }
}

/** @param list<string> $lines @return list<string> */
function dedupeUseLines(array $lines): array
{
    $seen = [];
    $out = [];
    foreach ($lines as $line) {
        if (isset($seen[$line])) {
            continue;
        }

        $seen[$line] = true;
        $out[] = $line;
    }

    sort($out, SORT_STRING);

    return $out;
}

function functionUseLine(string $localName, string $resolvedName): string
{
    // `$resolvedName` is a backend-neutral `Module::name`. Split it *before*
    // mangling: an operator name can contain `\` itself (`Data.List::\\`), and
    // splitting on the last backslash of the assembled PHP FQN then cuts the
    // operator in half.
    $parsed = parseResolvedSymbol($resolvedName);
    $namespace = $parsed === null ? '' : moduleNameToNamespace($parsed['module']);
    $rawBase = $parsed === null ? $resolvedName : $parsed['name'];
    $base = basePhpFunctionName($rawBase);
    $localMangled = basePhpFunctionName($localName);
    $resolved = $namespace === '' ? $base : "{$namespace}\\{$base}";

    if ($localMangled === $base) {
        return "function {$resolved}";
    }

    return "function {$resolved} as {$localMangled}";
}

/**
 * Backend import planning for module code generation.
 *
 * Consumes module-origin metadata gathered by `buildImportContext` above, but
 * owns only PHP import pruning derived from IR usage. Semantic import/export
 * resolution belongs in the functions above and in exports.php, not here.
 */
/**
 * @param ?string $absoluteRequireRoot when set, emit `require_once '/abs/...php'` instead of `__DIR__`-relative paths
 */
function filterCodegenImportsForIr(
    IR\Module $ir,
    array $codegen,
    ?string $fromOutputRelative = null,
    ?array $usedCallees = null,
    ?string $absoluteRequireRoot = null,
    array $globalEvidenceMaps = [],
    array $globalFnOrigins = [],
): array {
    if ($usedCallees === null) {
        $usedCallees = collectIrCodegenUsage($ir)['callees'];
    }

    // Emit-time DictCall resolution (PHP) needs instance methods imported even
    // when IR still has dict_call with evidence FnRefs.
    foreach (dictCallResolvedMethods($ir, $globalEvidenceMaps) as $methodName => $originModule) {
        $usedCallees[$methodName] = true;
        if (!isset($codegen['externalFns'][$methodName])) {
            $codegen['externalFns'][$methodName] = resolvedSymbol($originModule, $methodName);
        }
        if (!isset($codegen['fnOriginModules'][$methodName])) {
            $codegen['fnOriginModules'][$methodName] = $originModule;
        }
    }

    // After specialization rewrites concrete DictCalls into direct Calls, those
    // method / evidence-factory IR names must still be importable even though
    // no DictCall remains in the IR.
    foreach (evidenceSymbolOrigins($globalEvidenceMaps) as $symbol => $originModule) {
        if (!isset($usedCallees[$symbol])) {
            continue;
        }
        if (!isset($codegen['externalFns'][$symbol])) {
            $codegen['externalFns'][$symbol] = resolvedSymbol($originModule, $symbol);
        }
        if (!isset($codegen['fnOriginModules'][$symbol])) {
            $codegen['fnOriginModules'][$symbol] = $originModule;
        }
    }

    $localFnNames = [];
    foreach ($ir->functions as $fn) {
        $localFnNames[$fn->name] = true;
    }

    // Tree-shake may drop instanceEvidence while leaving method functions live.
    // Fall back to a whole-program function→module map for remaining callees.
    foreach (\array_keys($usedCallees) as $symbol) {
        if (isset($codegen['externalFns'][$symbol]) || isset($localFnNames[$symbol])) {
            continue;
        }
        $originModule = $globalFnOrigins[$symbol] ?? null;
        if (!\is_string($originModule) || $originModule === '') {
            continue;
        }
        $codegen['externalFns'][$symbol] = resolvedSymbol($originModule, $symbol);
        $codegen['fnOriginModules'][$symbol] = $originModule;
    }

    $usedExternal = [];
    $usedModules = [];

    foreach (\array_keys($usedCallees) as $callee) {
        if (isset($codegen['externalFns'][$callee])) {
            $usedExternal[$callee] = true;
            $originModule = $codegen['fnOriginModules'][$callee] ?? null;
            if ($originModule !== null) {
                $usedModules[$originModule] = true;
            }
            continue;
        }

        $parsed = parseResolvedSymbol($callee);
        if ($parsed !== null) {
            $usedModules[$parsed['module']] = true;
            if (isset($codegen['externalFns'][$parsed['name']])) {
                $usedExternal[$parsed['name']] = true;
            }
            continue;
        }

        if (!str_contains($callee, '\\')) {
            continue;
        }

        $separator = strrpos($callee, '\\');
        $namespace = substr($callee, 0, $separator);
        $member = substr($callee, $separator + 1);
        $originModule = $codegen['namespaceModules'][$namespace] ?? null;
        if ($originModule !== null) {
            $usedModules[$originModule] = true;
        }

        if (isset($codegen['externalFns'][$member])) {
            $usedExternal[$member] = true;
        }
    }

    $externalFns = \array_intersect_key($codegen['externalFns'], $usedExternal);
    $requireLines = [];
    $fromOutput = $fromOutputRelative ?? $codegen['currentOutputRelative'] ?? '';
    $absRoot = $absoluteRequireRoot !== null
        ? rtrim(\str_replace('\\', '/', $absoluteRequireRoot), '/')
        : null;
    foreach (\array_keys($usedModules) as $moduleName) {
        $targetPath = $codegen['moduleOutputPaths'][$moduleName] ?? null;
        if ($targetPath === null) {
            continue;
        }

        if ($absRoot !== null) {
            $requireLines[] = \var_export($absRoot . '/' . \str_replace('\\', '/', $targetPath), true);
        } else {
            $requireLines[] = relativeRequirePath($fromOutput, $targetPath);
        }
    }

    sort($requireLines, SORT_STRING);
    $requireLines = array_values(array_unique($requireLines));

    $localFnNames = [];
    foreach ($ir->functions as $fn) {
        $localFnNames[$fn->name] = true;
    }

    $functionUseLines = [];
    foreach ($externalFns as $local => $resolved) {
        if (\in_array($local, ['True', 'False'], true)) {
            continue;
        }
        // Constructors cross modules as tag values, never as calls, so importing their PHP
        // function would be dead (and the name may not exist).
        if (isConstructorName($local)) {
            continue;
        }
        // Local definitions shadow imported names; emitting `use function`
        // for the import would conflict with the local `function` declaration.
        if (isset($localFnNames[$local])) {
            continue;
        }

        $functionUseLines[] = functionUseLine($local, $resolved);
    }
    $functionUseLines = dedupeUseLines($functionUseLines);

    $namespaceUseLines = [];
    $usedNamespaces = [];
    foreach (\array_keys($usedCallees) as $callee) {
        $parsed = parseResolvedSymbol($callee);
        if ($parsed !== null) {
            $usedNamespaces[moduleNameToNamespace($parsed['module'])] = true;
            continue;
        }
        if (!str_contains($callee, '\\')) {
            continue;
        }

        $usedNamespaces[substr($callee, 0, strrpos($callee, '\\'))] = true;
    }

    foreach ($codegen['namespaceUseLines'] as $line) {
        foreach (\array_keys($usedNamespaces) as $namespace) {
            $asName = $codegen['moduleAsNames'][$namespace] ?? null;
            if ($asName !== null && str_starts_with($line, "{$namespace} as {$asName}")) {
                $namespaceUseLines[] = $line;
            }
        }
    }
    $namespaceUseLines = dedupeUseLines($namespaceUseLines);

    $moduleAsNames = [];
    foreach ($codegen['moduleAsNames'] as $namespace => $asName) {
        if (isset($usedNamespaces[$namespace])) {
            $moduleAsNames[$namespace] = $asName;
        }
    }

    $externalFnRuntimeArity = \array_intersect_key(
        $codegen['externalFnRuntimeArity'] ?? [],
        $usedExternal,
    );

    return [
        'requireLines' => $requireLines,
        'functionUseLines' => $functionUseLines,
        'namespaceUseLines' => $namespaceUseLines,
        'externalFns' => $externalFns,
        'externalFnRuntimeArity' => $externalFnRuntimeArity,
        'moduleAsNames' => $moduleAsNames,
        'constructorRenames' => $codegen['constructorRenames'] ?? [],
    ];
}

/**
 * Map instance-method IR names that PHP/JVM emit will resolve from nullary
 * DictCall evidence FnRefs → defining module name.
 *
 * @param array<string, array{module?: string, methods?: array<string, string>}> $globalEvidenceMaps
 * @return array<string, string> methodIrName => originModule
 */
function dictCallResolvedMethods(IR\Module $ir, array $globalEvidenceMaps): array
{
    if ($globalEvidenceMaps === []) {
        return [];
    }

    $out = [];
    $visit = static function ($node) use (&$visit, &$out, $globalEvidenceMaps): void {
        if ($node instanceof IR\DictCall) {
            $evName = null;
            if ($node->evidence instanceof IR\FnRef) {
                $evName = $node->evidence->name;
            } elseif ($node->evidence instanceof IR\ExprCall && $node->evidence->args === []) {
                $evName = $node->evidence->callee;
            }
            if ($evName !== null && isset($globalEvidenceMaps[$evName])) {
                $info = $globalEvidenceMaps[$evName];
                $methods = $info['methods'] ?? [];
                $methodIr = \is_array($methods) ? ($methods[$node->method] ?? null) : null;
                $module = $info['module'] ?? null;
                if (\is_string($methodIr) && \is_string($module)) {
                    $out[$methodIr] = $module;
                }
            }
        }
        if ($node instanceof IR\Block) {
            foreach ($node->items as $item) {
                $visit($item);
            }
        }
        if ($node instanceof IR\MatchStmt || $node instanceof IR\MatchReturn) {
            foreach ($node->arms as $arm) {
                $visit($arm->body);
            }
        }
        if ($node instanceof IR\FunctionDecl) {
            $visit($node->body);
        }
    };

    foreach ($ir->functions as $fn) {
        $visit($fn);
    }

    return $out;
}

/**
 * Map every evidence factory and its method IR names to the defining module.
 *
 * Used when specialization has already rewritten DictCalls into direct Calls
 * so the symbols still appear in callees without a remaining DictCall.
 *
 * @param array<string, array{module?: string, methods?: array<string, string>}> $globalEvidenceMaps
 * @return array<string, string> irName => originModule
 */
function evidenceSymbolOrigins(array $globalEvidenceMaps): array
{
    $out = [];
    foreach ($globalEvidenceMaps as $evName => $info) {
        if (!\is_string($evName) || $evName === '') {
            continue;
        }
        $module = $info['module'] ?? null;
        if (!\is_string($module) || $module === '') {
            continue;
        }
        $out[$evName] = $module;
        $methods = $info['methods'] ?? [];
        if (!\is_array($methods)) {
            continue;
        }
        foreach ($methods as $methodIr) {
            if (\is_string($methodIr) && $methodIr !== '') {
                $out[$methodIr] = $module;
            }
        }
    }

    return $out;
}
