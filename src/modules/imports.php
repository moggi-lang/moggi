<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\IR;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;

use function Moggi\Backend\backendById;
use function Moggi\Backend\Php\Naming\basePhpFunctionName;
use function Moggi\Errors\appendDidYouMean;
use function Moggi\Semantics\Exhaustiveness\trueBindingOrigins;
use function Moggi\Semantics\Kinds\dataKind;
use function Moggi\Semantics\Kinds\registerTypeKind;
use function Moggi\Semantics\TypeExpr\schemeRuntimeArity;
use function Moggi\Semantics\Types\evidenceFunctionName;
use function Moggi\Syntax\Ast\moduleName;

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
    static $cache = [];
    $key = $cacheKey !== ''
        ? $cacheKey
        : ($moduleName . "\0" . $namespace . "\0" . \spl_object_id($program));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $origins = [];
    $arity = [];
    foreach ($program->instanceEvidence as $ev) {
        if (\is_array($ev)) {
            $name = $ev['evidenceName'] ?? null;
            if (! \is_string($name)) {
                continue;
            }
            $arity[$name] = \count($ev['contextParams'] ?? []);
            $origins[$name] = [
                'namespace' => $namespace,
                'phpName' => $name,
                'module' => $moduleName,
            ];

            continue;
        }
        $arity[$ev->evidenceName] = \count($ev->contextParams);
        $origins[$ev->evidenceName] = [
            'namespace' => $namespace,
            'phpName' => $ev->evidenceName,
            'module' => $moduleName,
        ];
    }

    foreach ($program->items as $item) {
        if (! $item instanceof Ast\InstanceDecl) {
            continue;
        }
        $name = evidenceFunctionName($item->class, $item->head);
        if (! isset($origins[$name])) {
            $origins[$name] = [
                'namespace' => $namespace,
                'phpName' => $name,
                'module' => $moduleName,
            ];
        }
        if (! isset($arity[$name])) {
            $arity[$name] = \count($item->constraints);
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
    $facts = $unit['facts']['evidence'] ?? null;
    if (\is_array($facts) && isset($facts['origins'], $facts['arity'])) {
        return $facts;
    }

    $metas = [];
    foreach (['program', 'checkedProgram'] as $slot) {
        $program = $unit[$slot] ?? null;
        if (! ($program instanceof Ast\Program)) {
            continue;
        }

        $key = $cacheKey === '' ? '' : $cacheKey . "\0" . $slot;
        $metas[] = moduleEvidenceMeta($program, $moduleName, $namespace, $key);
    }

    return mergeEvidenceMetas($metas);
}

/**
 * First-wins merge of evidence metas (the pre-check tree first, so
 * hand-written instances keep their spellings over elaborated ones).
 *
 * @param list<array{origins: array<string, array{namespace: string, phpName: string, module: string}>, arity: array<string, int>}> $metas
 * @return array{origins: array<string, array{namespace: string, phpName: string, module: string}>, arity: array<string, int>}
 */
function mergeEvidenceMetas(array $metas): array
{
    $origins = [];
    $arity = [];
    foreach ($metas as $meta) {
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
    $facts = \is_array($unit) ? ($unit['facts']['actionReturn'] ?? null) : null;
    if (\is_array($facts)) {
        return $facts;
    }
    $key = \is_array($unit)
        ? $moduleName . "\0" . \hash('xxh3', (string) ($unit['source'] ?? '') . "\0" . (string) ($unit['path'] ?? ''))
        : $moduleName;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $program = \is_array($unit) ? ($unit['checkedProgram'] ?? null) : null;

    return $cache[$key] = ($program instanceof Ast\Program)
        ? actionReturnFnNamesFromProgram($program)
        : [];
}

/** @return array<string, true> */
function actionReturnFnNamesFromProgram(Ast\Program $program): array
{
    $names = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl && $item->ioBodyKind === IR\IoBodyKind::ActionReturn) {
            $names[$item->name] = true;
        }
    }

    return $names;
}

/**
 * A module's bindings whose body is the host boolean `true`, keyed `<Module>::<name>` — the fact the
 * guard desugaring reads instead of recognising `otherwise` by spelling. `ioBodyKind` has a sibling
 * accessor above for the same reason: the fact is computed where the definition is visible.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array<string, true>
 */
function moduleTrueBindingOrigins(array $units, string $moduleName): array
{
    /** @var array<string, array<string, true>> $cache */
    static $cache = [];

    $unit = $units[$moduleName] ?? null;
    $key = \is_array($unit)
        ? $moduleName . "\0" . \hash('xxh3', (string) ($unit['source'] ?? '') . "\0" . (string) ($unit['path'] ?? ''))
        : $moduleName;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $facts = \is_array($unit) ? ($unit['facts']['trueBindings'] ?? null) : null;
    if (\is_array($facts)) {
        return $facts;
    }

    $program = \is_array($unit) ? ($unit['checkedProgram'] ?? null) : null;

    return $cache[$key] = $program instanceof Ast\Program
        ? trueBindingOrigins($program->items, $moduleName)
        : [];
}

/**
 * Every unit's true bindings, qualified, so a reference resolved into any imported module can be
 * recognised without knowing which module the reference came from.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array<string, true>
 */
function moduleTrueBindingOriginsAll(array $units): array
{
    $origins = [];
    foreach (\array_keys($units) as $unitName) {
        $origins += moduleTrueBindingOrigins($units, (string) $unitName);
    }

    return $origins;
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
    $factsArity = \is_array($unit) ? ($unit['facts']['evidenceChecked']['arity'] ?? null) : null;
    if (\is_array($factsArity) && \array_key_exists($evName, $factsArity)) {
        return (int) $factsArity[$evName];
    }
    $program = \is_array($unit)
        ? ($unit['checkedProgram'] ?? $unit['program'] ?? null)
        : null;
    if (! ($program instanceof Ast\Program)) {
        return 0;
    }
    $namespace = (string) ($unit['namespace'] ?? '');
    $cacheKey = $moduleName . "\0" . $namespace . "\0" . \hash(
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
    if (! \is_array($unit)) {
        return null;
    }

    $callable = moduleCallableEntry($units, $moduleName, $name);
    if ($callable !== null) {
        return (int) $callable['arity'];
    }

    $factsArity = $unit['facts']['fnArity'] ?? null;
    if (\is_array($factsArity)) {
        return $factsArity[$name] ?? null;
    }

    $program = $unit['checkedProgram'] ?? null;
    if (! ($program instanceof Ast\Program)) {
        return null;
    }

    static $cache = [];
    $key = $moduleName . "\0" . \hash(
        'xxh3',
        (string) ($unit['source'] ?? '') . "\0" . (string) ($unit['path'] ?? ''),
    );
    if (! isset($cache[$key])) {
        $cache[$key] = declaredFunctionArityMapFromProgram($program);
    }

    return $cache[$key][$name] ?? null;
}

/** @return array<string, int> */
function declaredFunctionArityMapFromProgram(Ast\Program $program): array
{
    $arities = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl && ! $item->signatureOnly) {
            $arities[$item->name] = \count($item->params);
        }
    }

    return $arities;
}

/**
 * Elaboration facts a checked module publishes for importers, compact enough
 * to load without the checked program itself: merged instance-evidence
 * metadata (hand-written instances from the parsed tree plus derived ones,
 * which exist only post-check), the checked-only evidence arities, and the
 * elaborated function facts (`ioBodyKind`, guard bindings, runtime arities)
 * that never reach the exported schemes. Computed with the same readers the
 * live path uses, so the values are identical by construction.
 *
 * @param array<string, mixed> $unit
 * @return array{
 *   evidence: array{origins: array<string, array{namespace: string, phpName: string, module: string}>, arity: array<string, int>},
 *   evidenceChecked: array{origins: array<string, array{namespace: string, phpName: string, module: string}>, arity: array<string, int>},
 *   actionReturn: array<string, true>,
 *   trueBindings: array<string, true>,
 *   fnArity: array<string, int>,
 *   callables: array{abi: int, entries: array<string, array{evidence: int, params: int, arity: int}>}
 * }
 */
function preparedModuleFacts(array $unit, Ast\Program $checked, string $moduleName): array
{
    $namespace = (string) ($unit['namespace'] ?? '');
    $metas = [];
    $parsed = $unit['program'] ?? null;
    if ($parsed instanceof Ast\Program) {
        $metas[] = moduleEvidenceMeta($parsed, $moduleName, $namespace);
    }
    $checkedMeta = moduleEvidenceMeta($checked, $moduleName, $namespace);
    $metas[] = $checkedMeta;

    return [
        'evidence' => mergeEvidenceMetas($metas),
        'evidenceChecked' => $checkedMeta,
        'actionReturn' => actionReturnFnNamesFromProgram($checked),
        'trueBindings' => trueBindingOrigins($checked->items, $moduleName),
        'fnArity' => declaredFunctionArityMapFromProgram($checked),
        'callables' => callableFactsFromProgram($checked),
    ];
}

/**
 * The callable layout a module publishes for its exported entries — the same shape the in-module
 * table publishes (`IR\Callables\CallableSig`), narrowed to what an importer can use: the runtime
 * arity and its evidence/value split, under the entry's own name in the defining module.
 *
 * Publishing it here is what makes an imported entry's arity a published fact rather than a
 * guess. Before this record the importer looked the entry up by the name it imported it under, so
 * a re-export that renamed the entry found nothing and fell back to counting the scheme's arrows
 * (`schemeRuntimeArity`), which disagrees with the declared arity of every eta-reduced definition.
 *
 * Imported entries are source declarations, so their capture layout is empty: `|E| + |V|` is the
 * whole runtime vector. `evidence` and `params` are published separately so an importer can tell
 * a hidden slot from a value parameter without re-deriving either.
 *
 * @return array{abi: int, entries: array<string, array{evidence: int, params: int, arity: int}>}
 */
function callableFactsFromProgram(Ast\Program $program): array
{
    $entries = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }

        $arity = \count($item->params);
        $evidence = 0;
        foreach ($item->constraints as $constraint) {
            if ($constraint->evidence !== '') {
                ++$evidence;
            }
        }

        $entries[$item->name] = [
            'evidence' => $evidence,
            'params' => $arity - $evidence,
            'arity' => $arity,
        ];
    }

    return ['abi' => \Moggi\IR\Callables\ABI_VERSION, 'entries' => $entries];
}

/**
 * The callable half of a module's facts, from its parsed declarations.
 *
 * A single-file compile prepares the standard library *shallow*: interfaces without checked
 * bodies. A parsed declaration still states two of the three facts the record needs — its declared
 * parameter count (an eta-reduced definition declares fewer parameters than its scheme has arrows)
 * and, from the exported scheme, how many evidence slots the checker published
 * (`runtimeConstraintCount`). Their sum is the same `|E| + |V|` the full check publishes, so an
 * importer reads one arity whether the library was checked in this process or not.
 *
 * @param array<string, mixed> $exports the module's collected exports (`env` holds the schemes)
 * @return array{abi: int, entries: array<string, array{evidence: int, params: int, arity: int}>}
 */
function parsedCallableFacts(Ast\Program $program, array $exports): array
{
    $env = $exports['env'] ?? null;
    $entries = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }

        $params = \count($item->params);
        $scheme = \is_array($env) ? ($env[$item->name] ?? null) : null;
        $evidence = $scheme instanceof Scheme ? $scheme->runtimeConstraintCount : 0;

        $entries[$item->name] = [
            'evidence' => $evidence,
            'params' => $params,
            'arity' => $evidence + $params,
        ];
    }

    return ['abi' => \Moggi\IR\Callables\ABI_VERSION, 'entries' => $entries];
}

/**
 * The layout an importer reads for one imported entry: the declaring module's
 * published split (`evidence`/`params`) alongside the runtime arity the importer
 * already computed. Only the arity is present when the declaring module published
 * no record — an evidence builder, or a facade entry with no source declaration.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array{arity: int, evidence?: int, params?: int}
 */
function importedCallableLayout(array $units, string $originModule, string $name, int $arity): array
{
    $entry = moduleCallableEntry($units, $originModule, $name);

    return $entry === null ? ['arity' => $arity] : $entry;
}

/**
 * The published callable facts of one entry, looked up by the name the defining module uses.
 *
 * @param array<string, array<string, mixed>> $units
 * @return ?array{evidence: int, params: int, arity: int}
 */
function moduleCallableEntry(array $units, string $moduleName, string $name): ?array
{
    $unit = $units[$moduleName] ?? null;
    if (! \is_array($unit)) {
        return null;
    }

    $entries = $unit['facts']['callables']['entries'] ?? null;
    if (! \is_array($entries)) {
        return null;
    }

    $entry = $entries[$name] ?? null;

    return \is_array($entry) && isset($entry['arity']) ? $entry : null;
}

/**
 * Runtime arity of an imported function: the vector its definition publishes, not the arrow
 * count of its type. The two differ for eta-reduced definitions — `($) f = f` has type
 * `(a -> b) -> a -> b` but a single parameter — and handing such a function more arguments than
 * it declares has to go through the runtime `__apply` trampoline instead of a direct call.
 *
 * `$name` is the entry's name in `$moduleName`, not the name the importing file selected it as:
 * a re-export renames the entry, and the defining module knows only its own spelling.
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
        $callable = moduleCallableEntry($units, $moduleName, $name);
        if ($callable !== null) {
            return (int) $callable['arity'];
        }

        $declared = moduleDeclaredFunctionArity($units, $moduleName, $name);
        if ($declared !== null) {
            return $declared;
        }
    }

    return schemeRuntimeArity($scheme);
}

/**
 * Everything a module needs from the modules it imports: the facts, published
 * as one `ModuleInterface`, and the target layout those facts were resolved
 * against, as an `EmitPlan` the emitter renders from.
 *
 * @param list<Ast\ImportDecl> $imports
 * @param array<string, array<string, mixed>> $units
 * @return array<string, mixed>
 */
function buildImportContext(
    array $imports,
    array $units,
    string $currentModule,
    string $currentOutputRelative,
    string $rootPrefix,
    string $backendId,
): array {
    $planPhpImports = $backendId === 'php';
    $outputExtension = backendById($backendId)->extension();
    $env = [];
    $typeSynonyms = [];
    $data = [];
    $qualifiedModules = [];
    $constructorRenames = [];
    $moduleAsNames = [];
    $qualifiedOrigins = [];
    $qualifiedEnv = [];
    $externalFns = [];
    $externalFnRuntimeArity = [];
    $callables = [];
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
        if (! isset($units[$targetName])) {
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
            if (! (($target['synthetic'] ?? false) === true)) {
                $targetOutputRelative = mogPathToOutputRelative($target['path'], $rootPrefix, $outputExtension);
                $moduleOutputPaths[$targetName] = $targetOutputRelative;
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
            $origin = (! $scheme->classMethod && isset($selected['origins'][$name]))
                ? $selected['origins'][$name]
                : null;

            $qualifiedEnv[$localPrefix][$name] = $scheme;
            if (! $scheme->classMethod) {
                if ($origin !== null && $origin['module'] !== $targetName) {
                    $qualifiedOrigins[$localPrefix][$name] = $origin;
                }
                if ($origin !== null && $planPhpImports) {
                    recordOriginOutputPath($moduleOutputPaths, $origin, $currentModule, $units, $rootPrefix, $outputExtension);
                }
                if (isset($selected['intrinsicWrappers'][$name])) {
                    $intrinsicWrappers[$name] = $selected['intrinsicWrappers'][$name];
                }
            }

            if ($import->qualifiedOnly) {
                if ($origin !== null && ! isset($fnOriginModules[$name])) {
                    $externalFns[$name] = resolvedSymbol($origin['module'], $origin['phpName']);
                    $externalFnRuntimeArity[$name] = externalFunctionRuntimeArity(
                        $units,
                        $origin['module'],
                        $origin['phpName'],
                        $scheme,
                    );
                    $callables[$name] = importedCallableLayout($units, $origin['module'], $origin['phpName'], $externalFnRuntimeArity[$name]);
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
                } elseif (! isset($implicitNames[$name])
                    && ! ($env[$name]->classMethod && $scheme->classMethod)
                    && (($fnOriginModules[$name] ?? null) !== ($selected['origins'][$name]['module'] ?? null))) {
                    $ambiguousNames[$name] = [
                        'origins' => \array_values(\array_unique([
                            ...($ambiguousNames[$name]['origins'] ?? [$fnOriginModules[$name] ?? '']),
                            $selected['origins'][$name]['module'] ?? '',
                        ])),
                    ];
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
                $origin['phpName'],
                $scheme,
            );
            $callables[$name] = importedCallableLayout($units, $origin['module'], $origin['phpName'], $externalFnRuntimeArity[$name]);
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

        if (! $import->qualifiedOnly) {
            foreach ($selected['constructorRenames'] as $local => $canonical) {
                $constructorRenames[$local] = $canonical;
            }
        }

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
            }
        } elseif ($import->kind === 'glob' || $import->kind === 'named') {
            foreach ($exports['qualifiedModules'] as $prefix => $originModule) {
                $qualifiedModules[$prefix] = $originModule;
            }
        }

        if ($planPhpImports && $import->kind === 'qualifiedAs' && $import->asName !== null) {
            $moduleAsNames[$namespace] = $import->asName;
        }
    }

    foreach ($units as $unitName => $unit) {
        if ($unitName === $currentModule) {
            continue;
        }

        $cacheKey = $unitName . "\0" . $unit['namespace'] . "\0" . \hash(
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
                recordOriginOutputPath(
                    $moduleOutputPaths,
                    $origin,
                    $currentModule,
                    $units,
                    $rootPrefix,
                    $outputExtension,
                );
            }
        }
    }

    mergeFacadeBackendExports(
        $env,
        $externalFns,
        $externalFnRuntimeArity,
        $callables,
        $actionReturnFns,
        $fnOriginModules,
        $intrinsicWrappers,
        $moduleOutputPaths,
        $units,
        $currentModule,
        $rootPrefix,
        $planPhpImports,
        $outputExtension,
    );

    return [
        'interface' => new ModuleInterface(
            module: $currentModule,
            env: $env,
            data: $data,
            typeSynonyms: $typeSynonyms,
            qualifiedModules: $qualifiedModules,
            qualifiedOrigins: $qualifiedOrigins,
            qualifiedEnv: $qualifiedEnv,
            intrinsicWrappers: $intrinsicWrappers,
            ambiguousNames: $ambiguousNames,
            constructorRenames: $constructorRenames,
            externalFns: $externalFns,
            fnOriginModules: $fnOriginModules,
            externalFnRuntimeArity: $externalFnRuntimeArity,
            callables: $callables,
            actionReturn: $actionReturnFns,
            trueBindings: moduleTrueBindingOriginsAll($units),
        ),
        'plan' => EmitPlan::forTarget(
            $backendId,
            $currentOutputRelative,
            $planPhpImports ? $moduleOutputPaths : [],
            $moduleAsNames,
            $namespaceModules,
        ),
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
    if (! $currentProgram instanceof Ast\Program || ! isFacadeProgram($currentProgram)) {
        return;
    }

    $implName = facadeImplModuleNameFor($currentModule, $units);
    if ($implName === null || ! isset($units[$implName])) {
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
                    dataKind(\count($info['params'] ?? [])),
                );
            }
        }
    }
}

/**
 * @param array<string, Scheme> $env
 * @param array<string, string> $externalFns
 * @param array<string, int> $externalFnRuntimeArity
 * @param array<string, array{arity: int, evidence?: int, params?: int}> $callables
 * @param array<string, string> $fnOriginModules
 * @param array<string, string> $intrinsicWrappers
 * @param array<string, string> $moduleOutputPaths
 * @param array<string, true> $actionReturnFns
 * @param array<string, array<string, mixed>> $units
 */
function mergeFacadeBackendExports(
    array &$env,
    array &$externalFns,
    array &$externalFnRuntimeArity,
    array &$callables,
    array &$actionReturnFns,
    array &$fnOriginModules,
    array &$intrinsicWrappers,
    array &$moduleOutputPaths,
    array $units,
    string $currentModule,
    string $rootPrefix,
    bool $planPhpImports,
    string $outputExtension,
): void {
    mergeFacadeBackendEnv($env, $intrinsicWrappers, $units, $currentModule);

    $currentProgram = $units[$currentModule]['program'] ?? null;
    if (! $currentProgram instanceof Ast\Program || ! isFacadeProgram($currentProgram)) {
        return;
    }

    $implName = facadeImplModuleNameFor($currentModule, $units);
    if ($implName === null || ! isset($units[$implName])) {
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
            $origin['phpName'],
            $scheme,
        );
        $callables[$name] = importedCallableLayout($units, $origin['module'], $origin['phpName'], $externalFnRuntimeArity[$name]);
        $fnOriginModules[$name] = $origin['module'];
        if (isset(moduleActionReturnFnNames($units, $origin['module'])[$origin['phpName']])) {
            $actionReturnFns[$name] = true;
        }

        if ($planPhpImports) {
            recordOriginOutputPath(
                $moduleOutputPaths,
                $origin,
                $currentModule,
                $units,
                $rootPrefix,
                $outputExtension,
            );
        }
    }
}

/**
 * Remember where an entry's declaring module is written, for the target that
 * links files. The relative path from this module's artifact is computed when
 * the plan is rendered, from whichever spelling the caller settled on.
 *
 * @param array<string, string> $moduleOutputPaths
 * @param array{module: string, namespace: string, phpName: string} $origin
 * @param array<string, array<string, mixed>> $units
 */
function recordOriginOutputPath(
    array &$moduleOutputPaths,
    array $origin,
    string $currentModule,
    array $units,
    string $rootPrefix,
    string $outputExtension,
): void {
    $originModule = $origin['module'];
    if ($originModule === $currentModule || ! isset($units[$originModule])) {
        return;
    }

    $moduleOutputPaths[$originModule] = mogPathToOutputRelative(
        $units[$originModule]['path'],
        $rootPrefix,
        $outputExtension,
    );
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

    \sort($out, SORT_STRING);

    return $out;
}

function functionUseLine(string $localName, string $resolvedName): string
{
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
