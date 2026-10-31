<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\IR;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;

use const Moggi\IR\Callables\ABI_VERSION;

use function Moggi\Backend\backendById;
use function Moggi\Backend\Php\Naming\basePhpFunctionName;
use function Moggi\Errors\appendDidYouMean;
use function Moggi\Semantics\Exhaustiveness\trueBindingOrigins;
use function Moggi\Semantics\IoBoundary\functionReturnsIo;
use function Moggi\Semantics\Kinds\dataKind;
use function Moggi\Semantics\Kinds\registerTypeKind;
use function Moggi\Semantics\Types\evidenceFunctionName;
use function Moggi\Syntax\Ast\moduleName;
use function Moggi\Syntax\exportedChildrenSpec;
use function Moggi\Syntax\surfaceConstructorAlias;

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
            if (! isset($meta['arity'][$evName])) {
                throw new \LogicException(
                    "evidence `{$evName}` was published by module `{$origin['module']}` without a runtime arity",
                );
            }
            $arity[$evName] = $meta['arity'][$evName];
        }
    }

    return ['origins' => $origins, 'arity' => $arity];
}

/**
 * Runtime arity of every evidence factory a unit publishes.
 *
 * The checked program is authoritative: elaborating an instance adds the superclass dictionaries its
 * pre-check declaration does not spell, so `instance Monoid (Dual a)` takes two slots where the
 * parsed declaration shows one. A name only the parsed tree carries (a module this run did not
 * check) keeps its declared count.
 *
 * @param array<string, mixed> $unit
 * @return array<string, int>
 */
function unitEvidenceArities(array $unit, string $moduleName, string $namespace): array
{
    $checked = $unit['facts']['evidenceChecked']['arity'] ?? null;
    if (! \is_array($checked)) {
        $program = $unit['checkedProgram'] ?? null;
        $checked = $program instanceof Ast\Program
            ? moduleEvidenceMeta($program, $moduleName, $namespace)['arity']
            : [];
    }

    return $checked + unitEvidenceMeta($unit, $moduleName, $namespace)['arity'];
}

/**
 * A module's action facts: the entries that answer with an action value, and the subset lowering
 * gives a worker.
 *
 * `actionReturn` is the result convention `IoEscape\annotateFunction()` decided and nothing else, so
 * a caller knows to run what the entry returns. `workers` is the part of that set whose result is
 * literally `IO a` — the only shape with a body to move into `__w_<name>`. An entry returning a
 * monadic variable answers with an action but is called through its entry, with no worker allocated.
 *
 * Both sets are read in this one walk, so the worker an importer is told exists and the worker a
 * module lowers are one answer, not two that happen to agree.
 *
 * @param list<Ast\AstNode> $items
 * @return array{actionReturn: array<string, true>, workers: array<string, true>}
 */
function actionEntryFacts(array $items): array
{
    $actionReturn = [];
    $workers = [];
    foreach ($items as $item) {
        if (! $item instanceof Ast\FunctionDecl
            || $item->actionConvention !== IR\ActionConvention::ReturnsAction) {
            continue;
        }

        $actionReturn[$item->name] = true;
        if (functionReturnsIo($item->type)) {
            $workers[$item->name] = true;
        }
    }

    return ['actionReturn' => $actionReturn, 'workers' => $workers];
}

/**
 * A module's action facts, from what it published or from the program it was checked as.
 *
 * A unit with neither published nothing and was not checked in this run, so there is no answer to
 * read: refusing names the module rather than answering "no workers", which an importer would
 * believe and then emit a call to a worker no module lowered.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array{actionReturn: array<string, true>, workers: array<string, true>}
 */
function moduleActionFacts(array $units, string $moduleName): array
{
    /** @var array<string, array{actionReturn: array<string, true>, workers: array<string, true>}> $cache */
    static $cache = [];

    $unit = $units[$moduleName] ?? null;
    if (! \is_array($unit)) {
        throw new \RuntimeException("module `{$moduleName}` is not part of this compilation");
    }

    $actionReturn = $unit['facts']['actionReturn'] ?? null;
    $workers = $unit['facts']['workers'] ?? null;
    if (\is_array($actionReturn) && \is_array($workers)) {
        return ['actionReturn' => $actionReturn, 'workers' => $workers];
    }

    $key = $moduleName . "\0" . \hash('xxh3', (string) ($unit['source'] ?? '') . "\0" . (string) ($unit['path'] ?? ''));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $program = $unit['checkedProgram'] ?? null;
    if (! $program instanceof Ast\Program) {
        throw new \RuntimeException(
            "module `{$moduleName}` published no action facts and was not checked in this run",
        );
    }

    return $cache[$key] = actionEntryFacts($program->items);
}

/** @return array<string, true> */
function moduleActionReturnFnNames(array $units, string $moduleName): array
{
    return moduleActionFacts($units, $moduleName)['actionReturn'];
}

/**
 * The worker an imported entry is run through, when its declaring module publishes one.
 *
 * The declaring module owns the fact — an entry has a worker exactly when its facts name it — so
 * the importer reads the set and names the symbol here, rather than deciding from the scheme and
 * spelling the worker itself.
 *
 * @param array<string, array<string, mixed>> $units
 * @param array{module: string, phpName: string} $origin
 */
function importedWorkerSymbol(array $units, array $origin): ?string
{
    if (! isset(moduleActionFacts($units, $origin['module'])['workers'][$origin['phpName']])) {
        return null;
    }

    return resolvedSymbol($origin['module'], IR\Callables\workerName($origin['phpName']));
}

/**
 * A module's bindings whose body is the host boolean `true`, keyed `<Module>::<name>` — the fact the
 * guard desugaring reads instead of recognising `otherwise` by spelling. `actionConvention` has a sibling
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
 * Elaboration facts a checked module publishes for importers, compact enough
 * to load without the checked program itself: merged instance-evidence
 * metadata (hand-written instances from the parsed tree plus derived ones,
 * which exist only post-check), the checked-only evidence arities, and the
 * elaborated function facts (`actionConvention`, guard bindings, runtime arities)
 * that never reach the exported schemes. Computed with the same readers the
 * live path uses, so the values are identical by construction.
 *
 * @param array<string, mixed> $unit
 * @return array{
 *   evidence: array{origins: array<string, array{namespace: string, phpName: string, module: string}>, arity: array<string, int>},
 *   evidenceChecked: array{origins: array<string, array{namespace: string, phpName: string, module: string}>, arity: array<string, int>},
 *   actionReturn: array<string, true>,
 *   workers: array<string, true>,
 *   trueBindings: array<string, true>,
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

    $evidence = mergeEvidenceMetas($metas);

    $actions = actionEntryFacts($checked->items);

    return [
        'evidence' => $evidence,
        'evidenceChecked' => $checkedMeta,
        'actionReturn' => $actions['actionReturn'],
        'workers' => $actions['workers'],
        'trueBindings' => trueBindingOrigins($checked->items, $moduleName),
        'callables' => callableFactsFromProgram(
            $checked,
            $checkedMeta['arity'] + $evidence['arity'],
        ),
    ];
}

/**
 * The callable layout a module publishes for its exported entries — the same shape the in-module
 * table publishes (`IR\Callables\CallableSig`), narrowed to what an importer can use: the runtime
 * arity and its evidence/value split, under the entry's own name in the defining module.
 *
 * Publishing it here is what makes an imported entry's arity a published fact rather than a
 * guess. Before this record the importer looked the entry up by the name it imported it under, so
 * a re-export that renamed the entry found nothing and fell back to counting the scheme's arrows,
 * which disagrees with the declared arity of every eta-reduced definition.
 *
 * Imported entries are source declarations, so their capture layout is empty: `|E| + |V|` is the
 * whole runtime vector. `evidence` and `params` are published separately so an importer can tell
 * a hidden slot from a value parameter without re-deriving either.
 *
 * @param array<string, int> $evidenceArities evidence factory name => dictionary count
 * @return array{abi: int, entries: array<string, array{evidence: int, params: int, arity: int}>}
 */
function callableFactsFromProgram(Ast\Program $program, array $evidenceArities = []): array
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

    foreach (programConstructorArities($program) as $name => $arity) {
        $entries[$name] ??= ['evidence' => 0, 'params' => $arity, 'arity' => $arity];
    }

    foreach ($evidenceArities as $name => $arity) {
        $entries[$name] ??= evidenceCallableLayout($arity);
    }

    return ['abi' => ABI_VERSION, 'entries' => $entries];
}

/**
 * Runtime arity of every constructor a program declares: its field count.
 *
 * A type whose single constructor the surface lets callers spell as the type name itself publishes
 * that spelling too, so both names resolve to one layout.
 *
 * @return array<string, int>
 */
function programConstructorArities(Ast\Program $program): array
{
    $arities = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\DataDecl) {
            continue;
        }

        $declared = [];
        foreach ($item->constructors as $constructor) {
            $declared[] = $constructor->name;
            $arities[$constructor->name] = \count($constructor->fields);
        }

        $alias = surfaceConstructorAlias(
            $item->name,
            $declared,
            exportedChildrenSpec($program->exports, $item->name),
        );
        if ($alias !== null && isset($arities[$alias])) {
            $arities[$item->name] ??= $arities[$alias];
        }
    }

    return $arities;
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
 * @param array<string, int> $evidenceArities evidence factory name => dictionary count
 * @return array{abi: int, entries: array<string, array{evidence: int, params: int, arity: int}>}
 */
function parsedCallableFacts(Ast\Program $program, array $exports, array $evidenceArities = []): array
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

    foreach (programConstructorArities($program) as $name => $arity) {
        $entries[$name] ??= ['evidence' => 0, 'params' => $arity, 'arity' => $arity];
    }
    foreach ($evidenceArities as $name => $arity) {
        $entries[$name] ??= evidenceCallableLayout($arity);
    }

    return ['abi' => ABI_VERSION, 'entries' => $entries];
}

/**
 * The layout of an evidence factory: it takes one dictionary per instance-context constraint and no
 * value parameters.
 *
 * @return array{evidence: int, params: int, arity: int}
 */
function evidenceCallableLayout(int $dictionaryCount): array
{
    return ['evidence' => $dictionaryCount, 'params' => 0, 'arity' => $dictionaryCount];
}

/**
 * The tree a unit's callable record is rendered from: its checked program when it has one, else its
 * parsed program.
 *
 * @param array<string, mixed> $unit
 */
function unitCallableProgram(array $unit, string $moduleName): Ast\Program
{
    $program = $unit['checkedProgram'] ?? $unit['program'] ?? null;
    if (! $program instanceof Ast\Program) {
        throw new \LogicException(
            "module `{$moduleName}` has neither a checked nor a parsed program to publish callables from",
        );
    }

    return $program;
}

/**
 * The callable layouts a unit publishes for its callable entries.
 *
 * `facts.callables` is the record, and {@see unitCallableProgram()} names the one tree it is
 * rendered from: the checked program once the unit has one, the parsed program before that. The
 * two trees are not interchangeable — checking a backend facade synthesizes its foreign bindings,
 * so the parsed declarations are a strict subset of the checked ones — which is why the choice is
 * made once, here and in {@see publishCallableFacts()}, rather than at each reader.
 *
 * @param array<string, mixed> $unit
 * @param array<string, array<string, mixed>> $units
 * @return array<string, array{evidence: int, params: int, arity: int}>
 */
function unitCallableEntries(array $unit, array $units, string $moduleName): array
{
    $entries = $unit['facts']['callables']['entries'] ?? null;
    if (\is_array($entries)) {
        return $entries;
    }

    $namespace = (string) ($unit['namespace'] ?? '');
    $program = unitCallableProgram($unit, $moduleName);

    $exports = $unit['exports'] ?? null;
    if (! \is_array($exports)) {
        $localTypes = $unit['localTypes'] ?? null;
        if (! \is_array($localTypes)) {
            throw new \LogicException(
                "module `{$moduleName}` has neither exports nor a type environment, so it publishes no "
                . 'function layouts',
            );
        }
        $exports = collectExports($program, $localTypes, $units, $moduleName);
    }

    return parsedCallableFacts($program, $exports, unitEvidenceArities($unit, $moduleName, $namespace))['entries'];
}

/**
 * The layout a module publishes for one of its callable entries, looked up by the name the defining
 * module uses.
 *
 * Every entry an importer can call has one — a function (its declared parameters, with the
 * constraint slots the check published), a data constructor (its field count) or an evidence factory
 * (its dictionary count) — so a consumer reads one record. A name the module does not publish is a
 * producer defect: the entry exists in the module's exports, so its layout has to exist too.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array{evidence: int, params: int, arity: int}
 */
function publishedCallableLayout(array $units, string $moduleName, string $name): array
{
    $unit = $units[$moduleName] ?? null;
    if (! \is_array($unit)) {
        throw new \LogicException(
            "callable `{$moduleName}::{$name}` has no unit in this compilation, so it publishes no layout",
        );
    }

    $entry = unitCallableEntries($unit, $units, $moduleName)[$name] ?? null;
    if (! \is_array($entry) || ! isset($entry['evidence'], $entry['params'], $entry['arity'])) {
        throw new \LogicException(
            "module `{$moduleName}` publishes no callable layout for `{$name}`; a function, data "
            . 'constructor and evidence factory each have to publish one where the module is checked '
            . 'or parsed',
        );
    }

    return [
        'evidence' => (int) $entry['evidence'],
        'params' => (int) $entry['params'],
        'arity' => (int) $entry['arity'],
    ];
}

/**
 * Runtime arity of an imported function: the vector its definition publishes, not the arrow count of
 * its type. The two differ for eta-reduced definitions — `($) f = f` has type `(a -> b) -> a -> b`
 * but a single parameter — and handing such a function more arguments than it declares has to go
 * through the runtime `__apply` trampoline instead of a direct call.
 *
 * `$name` is the entry's name in `$moduleName`, not the name the importing file selected it as: a
 * re-export renames the entry, and the defining module knows only its own spelling.
 *
 * @param array<string, array<string, mixed>> $units
 */
function externalFunctionRuntimeArity(array $units, ?string $moduleName, string $name): int
{
    if ($moduleName === null) {
        throw new \LogicException("imported entry `{$name}` has no declaring module, so it publishes no layout");
    }

    return publishedCallableLayout($units, $moduleName, $name)['arity'];
}

/**
 * Everything a module needs from the modules it imports: the facts, published
 * as one `ImportInterface`, and the target layout those facts were resolved
 * against, as an `EmitPlan` the emitter renders from.
 *
 * @param list<Ast\ImportDecl> $imports
 * @param array<string, array<string, mixed>> $units
 */
function buildImportContext(
    array $imports,
    array $units,
    string $currentModule,
    string $currentOutputRelative,
    string $rootPrefix,
    string $backendId,
    InstanceWorld $instanceWorld,
): ImportContext {
    $planPhpImports = $backendId === 'php';
    $outputExtension = backendById($backendId)->extension();
    /** @var array<string, ImportSymbol> $symbols the one table: every fact about every imported spelling */
    $symbols = [];
    $qualifiedModules = [];
    $moduleAsNames = [];
    $moduleOutputPaths = [];
    $namespaceModules = [];
    $implicitNames = [];

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
            $existing = $symbols[$name] ?? null;

            $reExported = ! $scheme->classMethod && $origin !== null && $origin['module'] !== $targetName;
            $symbols[$name] = ($existing ?? new ImportSymbol())->withQualified(
                $localPrefix,
                new ImportQualified(
                    module: $targetName,
                    scheme: $scheme,
                    originModule: $reExported ? $origin['module'] : null,
                    originName: $reExported ? $origin['phpName'] : null,
                ),
            );
            if (! $scheme->classMethod) {
                if ($origin !== null && $planPhpImports) {
                    recordOriginOutputPath($moduleOutputPaths, $origin, $currentModule, $units, $rootPrefix, $outputExtension);
                }
                if (isset($selected['intrinsicWrappers'][$name])) {
                    $symbols[$name] = $symbols[$name]->withIntrinsicWrapper($selected['intrinsicWrappers'][$name]);
                }
            }

            if ($import->qualifiedOnly) {
                if ($origin !== null && $symbols[$name]->symbol === null) {
                    $symbols[$name] = $symbols[$name]->withSymbol(
                        resolvedSymbol($origin['module'], $origin['phpName']),
                        $origin['module'],
                        publishedCallableLayout($units, $origin['module'], $origin['phpName']),
                    );
                    if (isset(moduleActionReturnFnNames($units, $origin['module'])[$origin['phpName']])) {
                        $symbols[$name] = $symbols[$name]->withActionReturn();
                    }
                    $worker = importedWorkerSymbol($units, $origin);
                    if ($worker !== null) {
                        $symbols[$name] = $symbols[$name]->withWorker($worker);
                    }
                }

                continue;
            }

            if ($existing !== null) {
                if ($import->implicit && $existing->scheme !== null) {
                    continue;
                }

                if ($existing->scheme?->classMethod !== $scheme->classMethod) {
                } elseif (! isset($implicitNames[$name])
                    && ! ($existing->scheme?->classMethod && $scheme->classMethod)
                    && ($existing->originModule !== ($selected['origins'][$name]['module'] ?? null))) {
                    $symbols[$name] = $symbols[$name]->withAmbiguousOrigins(\array_values(\array_unique([
                        ...($existing->ambiguousOrigins !== []
                            ? $existing->ambiguousOrigins
                            : [$existing->originModule ?? '']),
                        $selected['origins'][$name]['module'] ?? '',
                    ])));
                }
            }

            if ($symbols[$name]->ambiguousOrigins !== []) {
                continue;
            }

            $symbols[$name] = $symbols[$name]->withScheme($scheme);
            if ($import->implicit) {
                $implicitNames[$name] = true;
            }

            if ($scheme->classMethod || $origin === null) {
                continue;
            }

            $symbols[$name] = $symbols[$name]->withSymbol(
                resolvedSymbol($origin['module'], $origin['phpName']),
                $origin['module'],
                publishedCallableLayout($units, $origin['module'], $origin['phpName']),
            );
            if (isset(moduleActionReturnFnNames($units, $origin['module'])[$origin['phpName']])) {
                $symbols[$name] = $symbols[$name]->withActionReturn();
            }
            $worker = importedWorkerSymbol($units, $origin);
            if ($worker !== null) {
                $symbols[$name] = $symbols[$name]->withWorker($worker);
            }
        }

        if ($targetName !== $currentModule) {
            $targetMeta = unitEvidenceMeta($target, $targetName, $namespace);
            $targetArities = unitEvidenceArities($target, $targetName, $namespace);
            foreach ($targetMeta['origins'] as $evName => $origin) {
                $symbols[$evName] = ($symbols[$evName] ?? new ImportSymbol())->withSymbol(
                    resolvedSymbol($origin['module'], $origin['phpName']),
                    $origin['module'],
                    evidenceCallableLayout(
                        $targetArities[$evName]
                            ?? throw new \LogicException(
                                "evidence `{$evName}` was published by module `{$targetName}` without a runtime arity",
                            ),
                    ),
                );
            }
        }

        if (! $import->qualifiedOnly) {
            foreach ($selected['constructorRenames'] as $local => $canonical) {
                $symbols[$local] = ($symbols[$local] ?? new ImportSymbol())->withConstructorName($canonical);
            }
        }

        foreach ($selected['typeSynonyms'] as $name => $type) {
            $symbols[$name] = ($symbols[$name] ?? new ImportSymbol())->withTypeSynonym($type);
        }

        foreach ($selected['data'] as $name => $info) {
            $symbols[$name] = ($symbols[$name] ?? new ImportSymbol())->withData($info);
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
        $unitArities = unitEvidenceArities($unit, $unitName, $unit['namespace']);
        foreach ($meta['origins'] as $evName => $origin) {
            if (isset($symbols[$evName])) {
                continue;
            }

            $symbols[$evName] = ($symbols[$evName] ?? new ImportSymbol())->withSymbol(
                resolvedSymbol($origin['module'], $origin['phpName']),
                $origin['module'],
                evidenceCallableLayout(
                    $unitArities[$evName]
                        ?? throw new \LogicException(
                            "evidence `{$evName}` was published by module `{$unitName}` without a runtime arity",
                        ),
                ),
            );
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

    return new ImportContext(
        instanceWorld: $instanceWorld,
        interface: new ImportInterface(
            module: $currentModule,
            symbols: $symbols,
            qualifiedModules: $qualifiedModules,
            trueBindings: moduleTrueBindingOriginsAll($units),
        ),
        plan: EmitPlan::forTarget(
            $backendId,
            $currentOutputRelative,
            $planPhpImports ? $moduleOutputPaths : [],
            $moduleAsNames,
            $namespaceModules,
        ),
    );
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
