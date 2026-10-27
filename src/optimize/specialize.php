<?php declare(strict_types=1);

namespace Moggi\Optimize\Specialize;

use Moggi\IR;
use Moggi\IR\Visit;
use Moggi\Optimize\CaseFold\CtorTree;

use function Moggi\IR\Visit\irPatternBoundNames;
use function Moggi\IR\Visit\mapGuards;
use function Moggi\IR\Visit\mapOperandChildren;
use function Moggi\IR\Visit\walkBlock;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Modules\resolvedSymbol;
use function Moggi\Optimize\CaseFold\clearNullaryCtorCafs;
use function Moggi\Optimize\CaseFold\isPlausibleConstructorName;
use function Moggi\Optimize\CaseFold\nullaryCtorCaf;
use function Moggi\Optimize\CaseFold\recordCtorDef;
use function Moggi\Optimize\CaseFold\setFieldAccessorCafs;
use function Moggi\Optimize\CaseFold\setNullaryCtorCafs;
use function Moggi\Optimize\GlobalPass\rewriteStmtOperands;
use function Moggi\Optimize\Interproc\buildTempRemap;
use function Moggi\Optimize\Interproc\clearActiveFnArity;
use function Moggi\Optimize\Interproc\clearActivePeFusionIndex;
use function Moggi\Optimize\Interproc\countLocalUsesInItems;
use function Moggi\Optimize\Interproc\denseStmtItems;
use function Moggi\Optimize\Interproc\isPeFusionInlineCandidate;
use function Moggi\Optimize\Interproc\maxTempInItems;
use function Moggi\Optimize\Interproc\nullaryFnNameIsNonDuplicableSeed;
use function Moggi\Optimize\Interproc\operandHasFreeTemps;
use function Moggi\Optimize\Interproc\operandIsNonDuplicable;
use function Moggi\Optimize\Interproc\remapTempsInItems;
use function Moggi\Optimize\Interproc\setActiveFnArity;
use function Moggi\Optimize\Interproc\setActiveForeignLeaves;
use function Moggi\Optimize\Interproc\setActivePeFusionIndex;
use function Moggi\Optimize\Interproc\substituteStmt;
use function Moggi\Optimize\Support\callArgsAreLeaves;
use function Moggi\Optimize\Support\dropUnreadTemps;
use function Moggi\Optimize\optimize;
use function Moggi\Semantics\IntrinsicRegistry\primopForPublicOperator;
use function Moggi\Semantics\Kernel\isBoolCtor;


/**
 * Specialize concrete call arguments by cloning callees with leading FnRef/Const
 * arguments burned in, then re-optimize affected modules.
 *
 * Only executable modules initiate specialization; clones may cross module
 * boundaries. Reject unsafe clones and bound specialization to four rounds.
 *
 * @param array<string, IR\Module> $modules
 * @param array<string, array<string, string>> $externalFnsByModule
 * @return array{modules: array<string, IR\Module>, stats: array{specializations: int, cacheHits: int}}
 */
function specializeAcrossModules(array $modules, array $externalFnsByModule = []): array
{
    /** @var array<string, array{module: string, name: string, sourceModule?: string}> $cache */
    $cache = [];
    $specializations = 0;
    $cacheHits = 0;
    $changedModules = [];

    setNullaryCtorCafs(collectNullaryCtorCafs($modules, $externalFnsByModule));
    setFieldAccessorCafs(collectFieldAccessorCafs($modules));

    for ($round = 0; $round < 4; ++$round) {
        $world = activateSpecializeWorld($modules, $externalFnsByModule);
        $roundChanged = false;
        foreach ($modules as $moduleName => $module) {
            if (! isSpecializationRootModule($module)) {
                continue;
            }
            $result = specializeOneRootModule(
                $module,
                $moduleName,
                $world['globalIndex'],
                $world['globalEvidence'],
                $cache,
                $specializations,
                $cacheHits,
                true,
            );
            if ($result['changed']) {
                $modules[$moduleName] = $result['module'];
                $changedModules[$moduleName] = true;
                $roundChanged = true;
            }
        }

        if (! $roundChanged) {
            break;
        }
    }

    setNullaryCtorCafs(collectNullaryCtorCafs($modules, $externalFnsByModule));
    setFieldAccessorCafs(collectFieldAccessorCafs($modules));

    try {
        hoistSpecializeEvidenceCafs($modules, $changedModules);
        optimizeSpecializationRoots($modules, $changedModules, 4);
        hoistSpecializeEvidenceCafs($modules, $changedModules);
        setNullaryCtorCafs(collectNullaryCtorCafs($modules, $externalFnsByModule));
        setFieldAccessorCafs(collectFieldAccessorCafs($modules));
        $extraBestGain = 0;
        $onlyFunctions = null;
        for ($extra = 0; $extra < 8; ++$extra) {
            $before = $specializations;
            $snapshot = specializeFunctionSnapshots($modules, $changedModules);
            $world = activateSpecializeWorld($modules, $externalFnsByModule);
            $roundChanged = false;
            foreach ($modules as $moduleName => $module) {
                if (! isset($changedModules[$moduleName]) || ! isSpecializationRootModule($module)) {
                    continue;
                }
                $result = specializeOneRootModule(
                    $module,
                    $moduleName,
                    $world['globalIndex'],
                    $world['globalEvidence'],
                    $cache,
                    $specializations,
                    $cacheHits,
                    true,
                    $onlyFunctions[$moduleName] ?? null,
                );
                if ($result['changed']) {
                    $modules[$moduleName] = $result['module'];
                    $roundChanged = true;
                }
            }
            $extraGain = $specializations - $before;
            $extraBestGain = \max($extraBestGain, $extraGain);
            if ($extraGain === 0 && ! $roundChanged) {
                break;
            }
            optimizeSpecializationRoots($modules, $changedModules, 1);
            $onlyFunctions = specializeChangedFunctions($modules, $changedModules, $snapshot);
            if ($onlyFunctions === [] || $extraGain * 10 < $extraBestGain) {
                break;
            }
        }
        foreach ($changedModules as $moduleName => $_) {
            if (! isset($modules[$moduleName])) {
                continue;
            }
            assertNoUnboundTempsAfterSpecialize($modules[$moduleName], (string) $moduleName);
        }
    } finally {
        clearNullaryCtorCafs();
        clearActiveFnArity();
    }

    return [
        'modules' => $modules,
        'stats' => [
            'specializations' => $specializations,
            'cacheHits' => $cacheHits,
        ],
    ];
}

/**
 * Fingerprint of what the specialization pass can act on: the function's body
 * and parameters. Effect annotations and flags are re-derived on every optimize
 * pass and say nothing about whether a new call site is available.
 */
function specializeBodyFingerprint(IR\FunctionDecl $function): string
{
    return \md5(\serialize([$function->params, $function->body]));
}

/**
 * Per-function fingerprints of the modules a fixpoint round may rewrite.
 *
 * Taken before a round so the round's changes -- the bodies it rewrote and the
 * clones it added -- can be diffed out afterwards.
 *
 * @param array<string, IR\Module> $modules
 * @param array<string, true> $moduleNames
 * @return array<string, array<string, string>>
 */
function specializeFunctionSnapshots(array $modules, array $moduleNames): array
{
    $snapshots = [];
    foreach ($moduleNames as $moduleName => $_) {
        if (! isset($modules[$moduleName])) {
            continue;
        }
        foreach ($modules[$moduleName]->functions as $function) {
            $snapshots[$moduleName][$function->name] = specializeBodyFingerprint($function);
        }
    }

    return $snapshots;
}

/**
 * The functions a round changed or added: exactly what the next round has to
 * walk, since an unchanged body cannot offer the specialization pass anything
 * its own last walk did not already take.
 *
 * @param array<string, IR\Module> $modules
 * @param array<string, true> $moduleNames
 * @param array<string, array<string, string>> $snapshots
 * @return array<string, array<string, true>>
 */
function specializeChangedFunctions(array $modules, array $moduleNames, array $snapshots): array
{
    $changed = [];
    foreach ($moduleNames as $moduleName => $_) {
        if (! isset($modules[$moduleName])) {
            continue;
        }
        $seen = $snapshots[$moduleName] ?? [];
        foreach ($modules[$moduleName]->functions as $function) {
            if (($seen[$function->name] ?? null) !== specializeBodyFingerprint($function)) {
                $changed[$moduleName][$function->name] = true;
            }
        }
    }

    return $changed;
}

/**
 * Build call/evidence indexes and install them in the specialize/interproc
 * session globals. Shared by the main specialize loop and the post-CAF extra
 * rounds (avoids rebuilding the world once per root module).
 *
 * @param array<string, IR\Module> $modules
 * @param array<string, array<string, string>> $externalFnsByModule
 * @return array{
 *   globalIndex: array<string, array{module: string, function: IR\FunctionDecl}>,
 *   globalEvidence: array<string, array{module: string, methods: array<string, string>}>
 * }
 */
function activateSpecializeWorld(array $modules, array $externalFnsByModule): array
{
    $globalIndex = [];
    $globalEvidence = [];
    /** @var array<string, true> $ambiguousShortNames */
    $ambiguousShortNames = [];
    /** @var array<string, array<string, IR\FunctionKind|true>> $fnsByModule */
    $fnsByModule = [];
    foreach ($modules as $moduleName => $module) {
        foreach ($module->functions as $function) {
            $short = $function->name;
            $fnsByModule[$moduleName][$short] = $function->kind;
            $qualified = resolvedSymbol($moduleName, $short);
            $entry = [
                'module' => $moduleName,
                'function' => $function,
            ];
            $globalIndex[$qualified] = $entry;
            if (isset($ambiguousShortNames[$short])) {
                continue;
            }
            if (isset($globalIndex[$short]) && ($globalIndex[$short]['module'] ?? null) !== $moduleName) {
                $ambiguousShortNames[$short] = true;
                unset($globalIndex[$short]);

                continue;
            }
            $globalIndex[$short] = $entry;
        }
        registerModuleConstructorNames($fnsByModule, $moduleName, $module);
        foreach ($module->instanceEvidence as $ev) {
            $globalEvidence[$ev->evidenceName] = [
                'module' => $moduleName,
                'methods' => $ev->methods,
            ];
        }
    }
    setActiveSpecializeEvidence($globalEvidence);
    setActiveSpecializeFnsByModule($fnsByModule);
    setActiveSpecializeExternalFnsByModule($externalFnsByModule);
    $foreignLeaves = [];
    foreach ($modules as $moduleName => $module) {
        foreach ($module->functions as $function) {
            if ($function->foreign) {
                $foreignLeaves[$function->name] = true;
                $foreignLeaves[resolvedSymbol((string) $moduleName, $function->name)] = true;
            }
        }
    }
    setActiveForeignLeaves($foreignLeaves);
    $fnArity = [];
    foreach ($modules as $moduleName => $module) {
        foreach ($module->functions as $function) {
            $arity = \count($function->params);
            $fnArity[$function->name] = $arity;
            $fnArity[resolvedSymbol((string) $moduleName, $function->name)] = $arity;
        }
    }
    setActiveFnArity($fnArity);

    return [
        'globalIndex' => $globalIndex,
        'globalEvidence' => $globalEvidence,
    ];
}

/**
 * Specialize (and nested-specialize clones of) one root module.
 *
 * @param array<string, array{module: string, function: IR\FunctionDecl}> $globalIndex
 * @param array<string, array{module: string, methods: array<string, string>}> $globalEvidence
 * @param array<string, array{module: string, name: string, sourceModule?: string}> $cache
 * @return array{changed: bool, module: IR\Module}
 */
function specializeOneRootModule(
    IR\Module $module,
    string $moduleName,
    array $globalIndex,
    array $globalEvidence,
    array &$cache,
    int &$specializations,
    int &$cacheHits,
    bool $reoptimize,
    ?array $onlyFunctions = null,
): array {
    $localNames = [];
    foreach ($module->functions as $function) {
        $localNames[$function->name] = true;
    }

    $newFunctions = [];
    $added = [];
    $moduleChanged = false;

    foreach ($module->functions as $function) {
        if ($onlyFunctions !== null && ! isset($onlyFunctions[$function->name])) {
            $newFunctions[] = $function;

            continue;
        }

        if ($function->ioStraightLine) {
            $resolved = resolveConcreteDictCallsInItems($function->body->items, $globalEvidence);
            if ($resolved['changed']) {
                $moduleChanged = true;
                $function = $function->withBody(new IR\Block($resolved['items']));
            }
            $newFunctions[] = $function;

            continue;
        }

        $nextTemp = maxTempInItems($function->body->items) + 1;
        $bodyItems = $function->body->items;

        $resolved = resolveConcreteDictCallsInItems($bodyItems, $globalEvidence);
        if ($resolved['changed']) {
            $moduleChanged = true;
            $bodyItems = $resolved['items'];
        }

        $result = specializeItems(
            $bodyItems,
            $function->name,
            $moduleName,
            $localNames,
            $globalIndex,
            $cache,
            $added,
            $nextTemp,
            $specializations,
            $cacheHits,
        );
        if ($result['changed']) {
            $moduleChanged = true;
        }
        $newFunctions[] = $function->withBody(new IR\Block($result['items']));
    }

    $pending = $added;
    $cloneNestBudget = 64;
    while ($pending !== []) {
        $clone = \array_shift($pending);
        if ($cloneNestBudget <= 0) {
            $newFunctions[] = $clone;
            $localNames[$clone->name] = true;

            continue;
        }
        --$cloneNestBudget;
        $nextTemp = maxTempInItems($clone->body->items) + 1;
        $bodyItems = $clone->body->items;
        $resolved = resolveConcreteDictCallsInItems($bodyItems, $globalEvidence);
        if ($resolved['changed']) {
            $moduleChanged = true;
            $bodyItems = $resolved['items'];
        }
        $fresh = [];
        $result = specializeItems(
            $bodyItems,
            $clone->name,
            $moduleName,
            $localNames,
            $globalIndex,
            $cache,
            $fresh,
            $nextTemp,
            $specializations,
            $cacheHits,
        );
        if ($result['changed']) {
            $moduleChanged = true;
        }
        $newFunctions[] = $clone->withBody(new IR\Block($result['items']));
        $localNames[$clone->name] = true;
        foreach ($fresh as $more) {
            $pending[] = $more;
            $moduleChanged = true;
        }
    }

    if (! $moduleChanged) {
        return ['changed' => false, 'module' => $module];
    }

    $updated = new IR\Module(
        $newFunctions,
        $module->data,
        $module->instanceEvidence,
        $module->entry,
        $module->moduleName !== '' ? $module->moduleName : $moduleName,
        $module->sourceFile,
    );
    if ($reoptimize) {
        $updated = optimize($updated);
    }

    return ['changed' => true, 'module' => $updated];
}

/**
 * Unary functions that are pure `__fieldN` projections — fold like `__fieldN`
 * when the argument is a known constructor tree.
 *
 * @param array<string, IR\Module> $modules
 * @return array<string, int>
 */
function collectFieldAccessorCafs(array $modules): array
{
    /** @var array<string, int> $accessors */
    $accessors = [];
    foreach ($modules as $moduleName => $module) {
        foreach ($module->functions as $function) {
            if (\count($function->params) !== 1 || $function->foreign) {
                continue;
            }
            $items = denseStmtItems($function->body->items);
            if (
                \count($items) !== 2
                || ! ($items[0] instanceof IR\Call)
                || ! \str_starts_with($items[0]->callee, '__field')
                || \count($items[0]->args) !== 1
                || ! ($items[0]->args[0] instanceof IR\Local)
                || $items[0]->args[0]->name !== $function->params[0]
                || ! ($items[1] instanceof IR\Ret)
                || ! ($items[1]->value instanceof IR\Temp)
                || $items[1]->value->id !== $items[0]->dest
            ) {
                continue;
            }
            $idx = (int) \substr($items[0]->callee, \strlen('__field'));
            $accessors[$function->name] = $idx;
            if ($moduleName !== '') {
                $accessors[resolvedSymbol($moduleName, $function->name)] = $idx;
            }
        }
    }

    return $accessors;
}

/**
 * Nullary functions whose body is `ret Ctor(...)` or `ret @True`/`@False` —
 * usable as CAF unfoldings for `__fieldN(@caf)` during case-fold.
 *
 * @param array<string, IR\Module> $modules
 * @param array<string, array<string, string>> $externalFnsByModule
 * @return array<string, CtorTree>
 */
function collectNullaryCtorCafs(array $modules, array $externalFnsByModule = []): array
{
    /** @var array<string, CtorTree> $cafs */
    $cafs = [];
    foreach ($modules as $moduleName => $module) {
        $externals = $externalFnsByModule[$moduleName] ?? [];
        foreach ($module->functions as $function) {
            if ($function->params !== [] || $function->foreign) {
                continue;
            }
            $tree = cafRetToCtorTree(denseStmtItems($function->body->items), $moduleName, $externals);
            if ($tree === null) {
                continue;
            }
            $cafs[$function->name] = $tree;
            if ($moduleName !== '') {
                $cafs[resolvedSymbol($moduleName, $function->name)] = $tree;
            }
        }
    }

    return $cafs;
}

/**
 * The constructor tree a function's body describes, or null when the body is not one call whose
 * value it returns.
 *
 * A constructor application is a `Call` statement that writes a temp and a `Ret` that reads it, so
 * the tree is read back through the same `CtorTree` records the case folder keeps: a nested
 * constructor is the argument temp's own tree.
 *
 * @param list<IR\Stmt> $items
 * @param array<string, string> $externals
 */
function cafRetToCtorTree(array $items, string $moduleName = '', array $externals = []): ?CtorTree
{
    $last = $items === [] ? null : $items[\count($items) - 1];
    if (! ($last instanceof IR\Ret)) {
        return null;
    }

    if ($last->value instanceof IR\FnRef) {
        return isPlausibleConstructorName($last->value->name)
            ? qualifyCtorTreeFns(new CtorTree($last->value->name, []), $moduleName, $externals)
            : null;
    }

    if (! ($last->value instanceof IR\Temp)) {
        return null;
    }

    /** @var array<int, CtorTree|IR\Operand> $ctors */
    $ctors = [];
    /** @var array<int, IR\Operand> $aliases */
    $aliases = [];
    foreach ($items as $item) {
        recordCtorDef($item, $ctors, $aliases);
    }

    $tree = $ctors[$last->value->id] ?? null;

    return $tree instanceof CtorTree ? qualifyCtorTreeFns($tree, $moduleName, $externals) : null;
}

/**
 * The name a constructor tree and its bare leaves go by outside the module they were read in.
 *
 * A tree names a constructor the way its own body spelled it, which is enough to match on but not to
 * emit: a module that reads another module's nullary CAF gets a constructor its own imports never
 * named, and a bare name resolves against the reader. The name the reading module's interface knows
 * it by, or its own qualification, is what the reader can emit.
 *
 * @param array<string, string> $externals
 */
function qualifyCtorRefName(string $name, string $moduleName, array $externals): string
{
    if ($name === '' || \str_contains($name, '::') || \str_contains($name, '\\')
        || primopForPublicOperator($name) !== null) {
        return $name;
    }
    if (isset($externals[$name])) {
        return $externals[$name];
    }

    return $moduleName !== '' ? resolvedSymbol($moduleName, $name) : $name;
}

/** Qualify the name of a constructor tree and of its `FnRef` leaves. @param array<string, string> $externals */
function qualifyCtorTreeFns(CtorTree $tree, string $moduleName, array $externals): CtorTree
{
    $args = [];
    foreach ($tree->args as $arg) {
        if ($arg instanceof CtorTree) {
            $args[] = qualifyCtorTreeFns($arg, $moduleName, $externals);

            continue;
        }
        if ($arg instanceof IR\FnRef) {
            $qualified = qualifyCtorRefName($arg->name, $moduleName, $externals);
            $args[] = $qualified === $arg->name ? $arg : new IR\FnRef($qualified);

            continue;
        }
        $args[] = $arg;
    }

    return new CtorTree(qualifyCtorRefName($tree->name, $moduleName, $externals), $args, $tree->srcLoc);
}

/**
 * @param array<string, IR\Module> $modules
 * @param array<string, true> $rootNames
 */
function optimizeSpecializationRoots(array &$modules, array $rootNames, int $passes): void
{
    $fnsByModule = [];
    foreach ($modules as $moduleName => $module) {
        foreach ($module->functions as $function) {
            $fnsByModule[$moduleName][$function->name] = $function->kind;
        }
        registerModuleConstructorNames($fnsByModule, $moduleName, $module);
    }
    setActiveSpecializeFnsByModule($fnsByModule);
    $fnArity = [];
    foreach ($modules as $moduleName => $module) {
        foreach ($module->functions as $function) {
            $arity = \count($function->params);
            $fnArity[$function->name] = $arity;
            $fnArity[resolvedSymbol((string) $moduleName, $function->name)] = $arity;
        }
    }
    setActiveFnArity($fnArity);

    try {
        foreach ($modules as $moduleName => $module) {
            if (! isset($rootNames[$moduleName]) || ! isSpecializationRootModule($module)) {
                continue;
            }
            for ($optPass = 0; $optPass < $passes; ++$optPass) {
                setPeFusionIndexFromModules($modules);
                $optimized = optimize($module);
                $modules[$moduleName] = $optimized;
                $module = $optimized;
            }
        }
    } finally {
        clearActivePeFusionIndex();
    }
}

/** @param list<IR\Stmt> $items */
function functionBodyHasHostOrForeign(array $items): bool
{
    foreach (denseStmtItems($items) as $item) {
        if ($item instanceof IR\IoCall || $item instanceof IR\IoRun || $item instanceof IR\IoThrow
            || $item instanceof IR\IoCatch || $item instanceof IR\IoFinally
        ) {
            return true;
        }
        if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
            foreach ($item->arms as $arm) {
                if (functionBodyHasHostOrForeign($arm->body->items)) {
                    return true;
                }
            }
        }
        if ($item instanceof IR\Ret || $item instanceof IR\Assign || $item instanceof IR\Let) {
            if (operandHasHostOrForeign($item instanceof IR\Ret ? $item->value : $item->value)) {
                return true;
            }
        }
        if ($item instanceof IR\Call) {
            foreach ($item->args as $arg) {
                if (operandHasHostOrForeign($arg)) {
                    return true;
                }
            }
        }
    }

    return false;
}

function operandHasHostOrForeign(IR\Operand $operand): bool
{
    if ($operand instanceof IR\ForeignCall) {
        return true;
    }
    if ($operand instanceof IR\Intrinsic) {
        foreach ($operand->args as $arg) {
            if (operandHasHostOrForeign($arg)) {
                return true;
            }
        }
    }
    if ($operand instanceof IR\ListLit) {
        foreach ($operand->elements as $el) {
            if (operandHasHostOrForeign($el)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Cross-module function index for producer/consumer PE fusion during root
 * optimize. Keys are both short and `Module::name` qualified.
 *
 * @param array<string, IR\Module> $modules
 */
function setPeFusionIndexFromModules(array $modules): void
{
    $index = [];
    foreach ($modules as $moduleName => $module) {
        $mn = $module->moduleName !== '' ? $module->moduleName : (string) $moduleName;
        foreach ($module->functions as $fn) {
            if ($mn === ''
                || ! isPeFusionInlineCandidate($fn)
                || functionBodyHasHostOrForeign($fn->body->items)
            ) {
                continue;
            }
            $index[resolvedSymbol($mn, $fn->name)] = $fn;
        }
    }
    setActivePeFusionIndex($index);
}

/** Rejects a module with a temp read but never defined. */
function assertNoUnboundTempsAfterSpecialize(IR\Module $module, string $moduleName): void
{
    if (! moduleHasUnboundTemps($module)) {
        return;
    }
    $bad = [];
    foreach ($module->functions as $fn) {
        $temps = unboundTempsInFunction($fn);
        if ($temps !== []) {
            $bad[] = $fn->name . '[' . \implode(',', $temps) . ']';
        }
    }

    throw new \RuntimeException(
        'optimize after specialization produced unbound temps in '
        . $moduleName . ': ' . \implode(', ', $bad),
    );
}

function isSpecializationRootModule(IR\Module $module): bool
{
    return $module->entry !== null
        && $module->entry->kind === IR\EntryPointKind::Main;
}

/**
 * Rewrite DictCall with compile-time-known evidence into a direct Call.
 *
 * @param list<IR\Stmt> $items
 * @param array<string, array{module: string, methods: array<string, string>}> $globalEvidence
 * @return array{items: list<IR\Stmt>, changed: bool}
 */
function resolveConcreteDictCallsInItems(array $items, array $globalEvidence): array
{
    $out = [];
    $changed = false;
    $concreteTemps = [];

    foreach (denseStmtItems($items) as $item) {
        if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
            $arms = [];
            foreach ($item->arms as $arm) {
                $nested = resolveConcreteDictCallsInItems($arm->body->items, $globalEvidence);
                if ($nested['changed']) {
                    $changed = true;
                }
                $arms[] = new IR\MatchArm($arm->pattern, new IR\Block($nested['items']), $arm->guards);
            }
            $out[] = $item instanceof IR\MatchStmt
                ? new IR\MatchStmt($item->scrutinee, $arms, $item->dest, $item->exhaustive)
                : new IR\MatchReturn($item->scrutinee, $arms, $item->exhaustive);

            continue;
        }

        if ($item instanceof IR\Call && isConcreteSpecializeCall($item, $concreteTemps)) {
            $concreteTemps[$item->dest] = ['callee' => $item->callee, 'args' => $item->args];
        } elseif ($item instanceof IR\Assign && isConcreteSpecializeArg(resolveConcreteOperand($item->value, $concreteTemps))) {
            $concreteTemps[$item->dest] = resolveConcreteOperand($item->value, $concreteTemps);
        }

        if ($item instanceof IR\DictCall) {
            $rewritten = tryResolveConcreteDictCall($item, $concreteTemps, $globalEvidence);
            if ($rewritten !== null) {
                $changed = true;
                $out[] = $rewritten;

                continue;
            }
        }

        if (! $item instanceof IR\MatchStmt && ! $item instanceof IR\MatchReturn) {
            $hit = false;
            $item = rewriteStmtOperands(
                $item,
                static fn (IR\Operand $op): IR\Operand => rewriteConcreteDictMethodOperand($op, $concreteTemps, $globalEvidence, $hit),
            );
            $changed = $changed || $hit;
        }

        $out[] = $item;
    }

    return ['items' => dropUnreadEvidenceBindings(denseStmtItems($out), $globalEvidence), 'changed' => $changed];
}

/**
 * A rewrite can make the dictionary a binding built unreachable while the call it fed stays.
 *
 * `t2 = call __ev_Show_Int()` followed by `t1 = dict_call t2->show(t0)` becomes a direct call to
 * the instance method, and then nothing reads `t2`. Keeping it keeps the whole dictionary — every
 * method of the instance, and with `showList` the module that method calls — in the artifact for
 * a value nobody uses.
 *
 * A call is a factory only when its callee is a name in the evidence index this pass published;
 * the index is what says "this name is an instance dictionary of this layout". An ordinary
 * declaration that happens to start with `__ev_` is not in the index and is left alone.
 *
 * @param list<IR\Stmt> $items
 * @param array<string, mixed> $globalEvidence
 * @return list<IR\Stmt>
 */
function dropUnreadEvidenceBindings(array $items, array $globalEvidence): array
{
    $dests = [];
    foreach ($items as $item) {
        if ($item instanceof IR\Call
            && isset($globalEvidence[$item->callee])
            && callArgsAreLeaves($item->args)) {
            $dests[$item->dest] = true;
        }
    }

    return dropUnreadTemps($items, $dests);
}

/**
 * A method read off compile-time-known evidence is the instance's method itself.
 *
 * `(__ev_Num_Int()["+"])(x, y)` is a `DictMethod` whose evidence is a nullary
 * evidence factory call, so the method is `Num Int`'s `+` and needs no
 * dictionary at runtime. Only nullary evidence is rewritten: its methods take
 * no context dictionaries, so the reference is exact.
 *
 * @param array<int, IR\Operand> $concreteTemps
 * @param array<string, array{module: string, methods: array<string, string>}> $globalEvidence
 */
function rewriteConcreteDictMethodOperand(
    IR\Operand $operand,
    array $concreteTemps,
    array $globalEvidence,
    bool &$hit,
): IR\Operand {
    if ($operand instanceof IR\DictMethod) {
        $evidence = resolveConcreteOperand($operand->evidence, $concreteTemps);
        $call = concreteCallOf($operand->evidence, $concreteTemps);
        $evName = null;
        if ($call !== null && $call['args'] === [] && isEvidenceFactoryName($call['callee'])) {
            $evName = specializationLookupName($call['callee']);
        } elseif ($evidence instanceof IR\FnRef && isEvidenceFactoryName($evidence->name)) {
            $evName = specializationLookupName($evidence->name);
        }

        $evInfo = $evName === null ? null : ($globalEvidence[$evName] ?? null);
        $methodIr = \is_array($evInfo) ? ($evInfo['methods'][$operand->method] ?? null) : null;
        if (\is_string($methodIr) && $methodIr !== '') {
            $methodModule = (string) ($evInfo['module'] ?? '');
            if ($methodModule !== ''
                && ! \str_contains($methodIr, '::')
                && ! \str_contains($methodIr, '\\')) {
                $methodIr = resolvedSymbol($methodModule, $methodIr);
            }
            $hit = true;

            return new IR\FnRef($methodIr);
        }
    }

    return mapOperandChildren(
        $operand,
        static fn (IR\Operand $child): IR\Operand => rewriteConcreteDictMethodOperand($child, $concreteTemps, $globalEvidence, $hit),
    );
}

/**
 * @param array<int, IR\Operand> $concreteTemps
 * @param array<string, array{module: string, methods: array<string, string>}> $globalEvidence
 */
function tryResolveConcreteDictCall(IR\DictCall $stmt, array $concreteTemps, array $globalEvidence): ?IR\Stmt
{
    $evidence = resolveConcreteOperand($stmt->evidence, $concreteTemps);
    $call = concreteCallOf($stmt->evidence, $concreteTemps);
    $binding = null;

    if ($call !== null && isEvidenceFactoryName($call['callee']) && isConcreteApplication($call, $concreteTemps)) {
        $binding = ['name' => specializationLookupName($call['callee']), 'args' => $call['args']];
    } elseif ($evidence instanceof IR\FnRef && isEvidenceFactoryName($evidence->name)) {
        $binding = ['name' => specializationLookupName($evidence->name), 'args' => []];
    }

    if ($binding === null) {
        return null;
    }

    $evInfo = $globalEvidence[$binding['name']] ?? null;
    $methodIr = null;
    $methodModule = '';
    if (\is_array($evInfo)) {
        $methods = $evInfo['methods'] ?? [];
        if (\is_array($methods) && isset($methods[$stmt->method]) && \is_string($methods[$stmt->method])) {
            $methodIr = $methods[$stmt->method];
        }
        if (\is_string($evInfo['module'] ?? null)) {
            $methodModule = $evInfo['module'];
        }
    }
    if ($methodIr === null) {
        return null;
    }

    if (
        $methodModule !== ''
        && $methodIr !== ''
        && ! \str_contains($methodIr, '::')
        && ! \str_contains($methodIr, '\\')
    ) {
        $methodIr = resolvedSymbol($methodModule, $methodIr);
    }

    return new IR\Call($methodIr, [...$binding['args'], ...$stmt->args], $stmt->dest);
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, true> $localNames
 * @param array<string, array{module: string, function: IR\FunctionDecl}> $globalIndex
 * @param array<string, array{module: string, name: string}> $cache
 * @param list<IR\FunctionDecl> $added
 * @return array{items: list<IR\Stmt>, changed: bool}
 */
function specializeItems(
    array $items,
    string $selfName,
    string $moduleName,
    array &$localNames,
    array &$globalIndex,
    array &$cache,
    array &$added,
    int &$nextTemp,
    int &$specializations,
    int &$cacheHits,
): array {
    $out = [];
    $changed = false;
    /** @var array<int, IR\Operand> $concreteTemps */
    $concreteTemps = [];

    foreach (denseStmtItems($items) as $item) {
        if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
            $arms = [];
            foreach ($item->arms as $arm) {
                $nested = specializeItems(
                    $arm->body->items,
                    $selfName,
                    $moduleName,
                    $localNames,
                    $globalIndex,
                    $cache,
                    $added,
                    $nextTemp,
                    $specializations,
                    $cacheHits,
                );
                if ($nested['changed']) {
                    $changed = true;
                }
                $arms[] = new IR\MatchArm($arm->pattern, new IR\Block($nested['items']), $arm->guards);
            }
            $out[] = $item instanceof IR\MatchStmt
                ? new IR\MatchStmt($item->scrutinee, $arms, $item->dest, $item->exhaustive)
                : new IR\MatchReturn($item->scrutinee, $arms, $item->exhaustive);

            continue;
        }

        if ($item instanceof IR\Call && isConcreteSpecializeCall($item, $concreteTemps)) {
            $concreteTemps[$item->dest] = ['callee' => $item->callee, 'args' => $item->args];
        } elseif ($item instanceof IR\Assign && isConcreteSpecializeArg(resolveConcreteOperand($item->value, $concreteTemps))) {
            $concreteTemps[$item->dest] = resolveConcreteOperand($item->value, $concreteTemps);
        }

        if ($item instanceof IR\Ret) {
            $rewritten = specializeOperand(
                $item->value,
                $selfName,
                $moduleName,
                $localNames,
                $globalIndex,
                $cache,
                $added,
                $specializations,
                $cacheHits,
                $concreteTemps,
            );
            if ($rewritten['changed']) {
                $changed = true;
                $out[] = new IR\Ret($rewritten['operand']);

                continue;
            }
        }

        if ($item instanceof IR\Call) {
            $rewrittenArgs = specializeOperandList(
                $item->args,
                $selfName,
                $moduleName,
                $localNames,
                $globalIndex,
                $cache,
                $added,
                $specializations,
                $cacheHits,
                $concreteTemps,
            );
            $args = resolveConcreteArgs($rewrittenArgs['args'], $concreteTemps);
            $spec = resolveSpecializedCallee(
                $item->callee,
                $args,
                $selfName,
                $moduleName,
                $localNames,
                $globalIndex,
                $cache,
                $added,
                $specializations,
                $cacheHits,
            );
            if ($spec !== null) {
                $changed = true;
                $out[] = new IR\Call($spec['name'], \array_slice($args, $spec['burned']), $item->dest, $item->srcLoc);

                continue;
            }
            if ($rewrittenArgs['changed']) {
                $changed = true;
                $out[] = new IR\Call($item->callee, $rewrittenArgs['args'], $item->dest);

                continue;
            }
        }

        if ($item instanceof IR\Assign) {
            $rewritten = specializeOperand(
                $item->value,
                $selfName,
                $moduleName,
                $localNames,
                $globalIndex,
                $cache,
                $added,
                $specializations,
                $cacheHits,
                $concreteTemps,
            );
            if ($rewritten['changed']) {
                $changed = true;
                $out[] = new IR\Assign($item->dest, $rewritten['operand']);

                continue;
            }
        }

        if ($item instanceof IR\Let) {
            $rewritten = specializeOperand(
                $item->value,
                $selfName,
                $moduleName,
                $localNames,
                $globalIndex,
                $cache,
                $added,
                $specializations,
                $cacheHits,
                $concreteTemps,
            );
            if ($rewritten['changed']) {
                $changed = true;
                $out[] = new IR\Let($item->name, $rewritten['operand']);

                continue;
            }
        }

        $out[] = $item;
    }

    return ['items' => denseStmtItems($out), 'changed' => $changed];
}

/**
 * Specialize the operands of a statement's call — arguments still to be resolved against the
 * concrete records the block has seen.
 * @param array<string, true> $localNames
 * @param array<string, array{module: string, function: IR\FunctionDecl}> $globalIndex
 * @param array<string, array{module: string, name: string}> $cache
 * @param list<IR\FunctionDecl> $added
 * @param array<int, IR\Operand> $concreteTemps
 * @return array{operand: IR\Operand, changed: bool}
 */
function specializeOperand(
    IR\Operand $operand,
    string $selfName,
    string $moduleName,
    array &$localNames,
    array &$globalIndex,
    array &$cache,
    array &$added,
    int &$specializations,
    int &$cacheHits,
    array $concreteTemps,
): array {
    if ($operand instanceof IR\ListLit) {
        $els = specializeOperandList(
            $operand->elements,
            $selfName,
            $moduleName,
            $localNames,
            $globalIndex,
            $cache,
            $added,
            $specializations,
            $cacheHits,
            $concreteTemps,
        );
        if ($els['changed']) {
            return ['operand' => new IR\ListLit($els['args']), 'changed' => true];
        }

        return ['operand' => $operand, 'changed' => false];
    }

    if ($operand instanceof IR\Intrinsic) {
        $args = specializeOperandList(
            $operand->args,
            $selfName,
            $moduleName,
            $localNames,
            $globalIndex,
            $cache,
            $added,
            $specializations,
            $cacheHits,
            $concreteTemps,
        );
        if ($args['changed']) {
            return [
                'operand' => new IR\Intrinsic($operand->name, $args['args'], $operand->srcLoc),
                'changed' => true,
            ];
        }

        return ['operand' => $operand, 'changed' => false];
    }

    return ['operand' => $operand, 'changed' => false];
}

/**
 * @param list<IR\Operand> $operands
 * @param array<string, true> $localNames
 * @param array<string, array{module: string, function: IR\FunctionDecl}> $globalIndex
 * @param array<string, array{module: string, name: string}> $cache
 * @param list<IR\FunctionDecl> $added
 * @param array<int, IR\Operand> $concreteTemps
 * @return array{args: list<IR\Operand>, changed: bool}
 */
function specializeOperandList(
    array $operands,
    string $selfName,
    string $moduleName,
    array &$localNames,
    array &$globalIndex,
    array &$cache,
    array &$added,
    int &$specializations,
    int &$cacheHits,
    array $concreteTemps,
): array {
    $out = [];
    $changed = false;
    foreach ($operands as $operand) {
        $rewritten = specializeOperand(
            $operand,
            $selfName,
            $moduleName,
            $localNames,
            $globalIndex,
            $cache,
            $added,
            $specializations,
            $cacheHits,
            $concreteTemps,
        );
        if ($rewritten['changed']) {
            $changed = true;
        }
        $out[] = $rewritten['operand'];
    }

    return ['args' => $out, 'changed' => $changed];
}

/**
 * @param list<IR\Operand> $args
 * @param array<int, IR\Operand> $concreteTemps
 * @return list<IR\Operand>
 */
function resolveConcreteArgs(array $args, array $concreteTemps): array
{
    return \array_map(
        static fn (IR\Operand $arg): IR\Operand => resolveConcreteOperand($arg, $concreteTemps),
        $args,
    );
}

/** @param array<int, IR\Operand|array{callee: string, args: list<IR\Operand>}> $concreteTemps */
function resolveConcreteOperand(IR\Operand $arg, array $concreteTemps): IR\Operand
{
    if ($arg instanceof IR\Temp && isset($concreteTemps[$arg->id])) {
        $record = $concreteTemps[$arg->id];

        return $record instanceof IR\Operand ? $record : $arg;
    }
    return $arg;
}

/** @param array<int, IR\Operand|array{callee: string, args: list<IR\Operand>}> $concreteTemps */
function isConcreteSpecializeCall(IR\Call $call, array $concreteTemps): bool
{
    return isConcreteApplication(['callee' => $call->callee, 'args' => $call->args], $concreteTemps);
}

/**
 * Whether a call this pass recorded may be named in a specialized call.
 *
 * The record holds the call's *description* — callee and arguments — because a computation is not an
 * operand; these are the rules {@see isConcreteSpecializeArg} applies to a value, applied to that
 * description.
 *
 * @param array{callee: string, args: list<IR\Operand>} $call
 * @param array<int, IR\Operand|array{callee: string, args: list<IR\Operand>}> $concreteTemps
 * @param array<int, true> $seen temps whose record this walk already entered
 */
function isConcreteApplication(array $call, array $concreteTemps, array $seen = []): bool
{
    if ($call['args'] === []) {
        if (operandIsNonDuplicable(new IR\FnRef($call['callee']))) {
            return false;
        }

        return isEvidenceFactoryName($call['callee'])
            || isPlausibleConstFn($call['callee'])
            || isPlausibleConstructorName($call['callee']);
    }

    foreach ($call['args'] as $arg) {
        if (! isConcreteRecordArg($arg, $concreteTemps, $seen)) {
            return false;
        }
    }

    return isPlausibleConstructorName($call['callee']);
}

/**
 * A temp id may be defined more than once, so a record can read the temp it defines and the walk
 * would follow that read back into the record it is already inside: such a read is not the record
 * but the temp, which nothing can name, so it answers not-concrete.
 *
 * @param array<int, IR\Operand|array{callee: string, args: list<IR\Operand>}> $concreteTemps
 * @param array<int, true> $seen records this walk is already inside
 */
function isConcreteRecordArg(IR\Operand $arg, array $concreteTemps, array $seen = []): bool
{
    if ($arg instanceof IR\Temp && isset($concreteTemps[$arg->id])) {
        if (isset($seen[$arg->id])) {
            return false;
        }
        $seen[$arg->id] = true;
        $record = $concreteTemps[$arg->id];

        return \is_array($record)
            ? isConcreteApplication($record, $concreteTemps, $seen)
            : isConcreteSpecializeArg($record);
    }

    return isConcreteSpecializeArg($arg);
}

/**
 * The call description a read holds, or null when its record is a plain value.
 *
 * @param array<int, IR\Operand|array{callee: string, args: list<IR\Operand>}> $concreteTemps
 * @return array{callee: string, args: list<IR\Operand>}|null
 */
function concreteCallOf(IR\Operand $operand, array $concreteTemps): ?array
{
    if (! ($operand instanceof IR\Temp) || ! isset($concreteTemps[$operand->id])) {
        return null;
    }

    $record = $concreteTemps[$operand->id];

    return \is_array($record) ? $record : null;
}

/**
 * @param list<IR\Operand> $args
 * @param array<string, true> $localNames
 * @param array<string, array{module: string, function: IR\FunctionDecl}> $globalIndex
 * @param array<string, array{module: string, name: string}> $cache
 * @param list<IR\FunctionDecl> $added
 * @return null|array{name: string, burned: int}
 */
function resolveSpecializedCallee(
    string $callee,
    array $args,
    string $selfName,
    string $moduleName,
    array &$localNames,
    array &$globalIndex,
    array &$cache,
    array &$added,
    int &$specializations,
    int &$cacheHits,
): ?array {
    $lookup = specializationLookupName($callee);
    if ($lookup === $selfName) {
        return null;
    }

    $targetInfo = $globalIndex[$callee] ?? $globalIndex[$lookup] ?? null;
    if ($targetInfo === null || ! isset($targetInfo['function'])) {
        return null;
    }

    $parsed = parseResolvedSymbol($callee);
    if ($parsed !== null && ($targetInfo['module'] ?? null) !== $parsed['module']) {
        return null;
    }

    if ($targetInfo['module'] === $moduleName) {
        return null;
    }

    $target = $targetInfo['function'];
    if ($target->kind !== IR\FunctionKind::Source) {
        return null;
    }
    if ($target->foreign || $target->ioEffect || ! isSpecializeCandidate($target)) {
        return null;
    }

    $burned = countConcretePrefix($args);
    if ($burned === 0 || $burned > \count($target->params)) {
        return null;
    }

    $runtimeParams = \array_slice($target->params, $burned);
    foreach ($runtimeParams as $param) {
        if (\str_starts_with($param, '__ev_') || $param === 'opts' || \str_starts_with($param, '__ev')) {
            return null;
        }
    }

    if (! burnedArgsIncludeFnRef($args, $burned)) {
        return null;
    }

    $burnedArgs = qualifyBurnedFnRefsForDest(
        \array_slice($args, 0, $burned),
        $moduleName,
        $localNames,
    );

    $key = specializationKey(
        $targetInfo['module'] . '::' . $target->name,
        $moduleName,
        $burnedArgs,
    );
    if (isset($cache[$key])
        && ($cache[$key]['module'] ?? null) === $moduleName
        && ($cache[$key]['sourceModule'] ?? null) === $targetInfo['module']
    ) {
        ++$cacheHits;

        return ['name' => $cache[$key]['name'], 'burned' => $burned];
    }

    $specName = '__spec_' . \substr(\sha1($key), 0, 24);
    $cache[$key] = [
        'module' => $moduleName,
        'sourceModule' => $targetInfo['module'],
        'name' => $specName,
    ];
    $clone = cloneSpecializedFunction($target, $specName, $burnedArgs, $target->name);
    $clone = qualifySourceModuleCallees(
        $clone,
        $targetInfo['module'],
        $moduleName,
        activeSpecializeFnsByModule()[$targetInfo['module']] ?? [],
        $localNames,
        activeSpecializeExternalFnsByModule()[$targetInfo['module']] ?? [],
    );
    $resolved = resolveConcreteDictCallsInItems($clone->body->items, activeSpecializeEvidence());
    if ($resolved['changed']) {
        $clone = $clone->withBody(new IR\Block($resolved['items']));
    }
    $runtimeParams = \array_slice($target->params, $burned);
    if (unboundEvidenceLocals($clone, $runtimeParams) !== []) {
        unset($cache[$key]);

        return null;
    }
    if (unboundTempsInFunction($clone) !== []) {
        unset($cache[$key]);

        return null;
    }
    $added[] = $clone;
    $localNames[$specName] = true;
    $globalIndex[$specName] = ['module' => $moduleName, 'function' => $clone];
    ++$specializations;

    return ['name' => $specName, 'burned' => $burned];
}

/** @var array<string, array{module: string, methods: array<string, string>}> */
function setActiveSpecializeEvidence(array $evidence): void
{
    $GLOBALS['__moggi_specialize_evidence'] = $evidence;
}

/** @return array<string, array{module: string, methods: array<string, string>}> */
function activeSpecializeEvidence(): array
{
    $ev = $GLOBALS['__moggi_specialize_evidence'] ?? [];

    return \is_array($ev) ? $ev : [];
}

/**
 * Data constructors are callable like functions after specialize clones a body
 * into another module. Register them so qualifySourceModuleCallees rewrites
 * bare `MkMap` → `Data.Map::MkMap` instead of leaving an unmapped short name.
 *
 * @param array<string, array<string, IR\FunctionKind|true>> $fnsByModule
 */
function registerModuleConstructorNames(array &$fnsByModule, string $moduleName, IR\Module $module): void
{
    foreach ($module->data as $decl) {
        foreach ($decl->constructors as $ctor) {
            if (isBoolCtor($ctor->name)) {
                continue;
            }
            $fnsByModule[$moduleName][$ctor->name] = true;
        }
    }
}

/** @param array<string, array<string, IR\FunctionKind|true>> $fnsByModule */
function setActiveSpecializeFnsByModule(array $fnsByModule): void
{
    $GLOBALS['__moggi_specialize_fns_by_module'] = $fnsByModule;
}

/** @return array<string, array<string, IR\FunctionKind|true>> */
function activeSpecializeFnsByModule(): array
{
    $fns = $GLOBALS['__moggi_specialize_fns_by_module'] ?? [];

    return \is_array($fns) ? $fns : [];
}

/** @param array<string, array<string, string>> $externalFnsByModule */
function setActiveSpecializeExternalFnsByModule(array $externalFnsByModule): void
{
    $GLOBALS['__moggi_specialize_external_fns_by_module'] = $externalFnsByModule;
}

/** @return array<string, array<string, string>> */
function activeSpecializeExternalFnsByModule(): array
{
    $fns = $GLOBALS['__moggi_specialize_external_fns_by_module'] ?? [];

    return \is_array($fns) ? $fns : [];
}

/** Bare IR name for specialization lookup (`Module::fn` → `fn`). */
function specializationLookupName(string $callee): string
{
    $parsed = parseResolvedSymbol($callee);

    return $parsed !== null ? $parsed['name'] : $callee;
}

function isSpecializeCandidate(IR\FunctionDecl $function): bool
{
    $items = denseStmtItems($function->body->items);
    if ($items === [] || \count($items) > 12) {
        return false;
    }

    $stmtBudget = 0;
    foreach ($items as $item) {
        if ($item instanceof IR\Loop || $item instanceof IR\TailRecall) {
            return false;
        }
        $stmtBudget += estimateStmtSize($item);
        if ($stmtBudget > 24) {
            return false;
        }
    }

    return true;
}

function estimateStmtSize(IR\Stmt $stmt): int
{
    if ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\MatchReturn) {
        $n = 1;
        foreach ($stmt->arms as $arm) {
            $n += \count(denseStmtItems($arm->body->items));
        }

        return $n;
    }

    return 1;
}

/** @param list<IR\Operand> $args */
function countConcretePrefix(array $args): int
{
    $n = 0;
    foreach ($args as $arg) {
        if (isConcreteSpecializeArg($arg)) {
            ++$n;

            continue;
        }

        break;
    }

    return $n;
}

function isConcreteSpecializeArg(IR\Operand $arg): bool
{
    if ($arg instanceof IR\FnRef) {
        if (operandIsNonDuplicable($arg)) {
            return false;
        }

        return true;
    }
    if ($arg instanceof IR\ConstInt || $arg instanceof IR\ConstStr
        || $arg instanceof IR\ConstChar || $arg instanceof IR\ConstDouble) {
        return true;
    }

    return false;
}

/**
 * A call's description answers what an operand-carried call used to: is this application concrete
 * enough to specialize on, key on, or hoist? A call is a statement, so the callee and its arguments
 * are asked about directly.
 *
 * @param list<IR\Operand> $args
 */
function isConcreteCall(string $callee, array $args): bool
{
    foreach ($args as $inner) {
        if (! isConcreteSpecializeArg($inner)) {
            return false;
        }
    }

    if ($args === []) {
        if (nullaryFnNameIsNonDuplicableSeed($callee)) {
            return false;
        }

        return isEvidenceFactoryName($callee)
            || isPlausibleConstFn($callee)
            || isPlausibleConstructorName($callee);
    }

    return isPlausibleConstructorName($callee);
}

/** The key two calls with the same callee and arguments share. @param list<IR\Operand> $args */
function callKey(string $callee, array $args): string
{
    return 'call:' . $callee . '(' . \implode(',', \array_map(concreteArgKey(...), $args)) . ')';
}

function isEvidenceFactoryName(string $name): bool
{
    return \str_starts_with(specializationLookupName($name), '__ev_');
}

function isPlausibleConstFn(string $name): bool
{
    $leaf = specializationLookupName($name);
    if (\str_starts_with($leaf, '__caf_')) {
        return true;
    }

    return nullaryCtorCaf($leaf) !== null
        || nullaryCtorCaf($name) !== null;
}

/**
 * @param list<IR\Operand> $burned
 * @param array<string, true> $destLocalNames
 * @return list<IR\Operand>
 */
function qualifyBurnedFnRefsForDest(array $burned, string $destModule, array $destLocalNames): array
{
    $out = [];
    foreach ($burned as $arg) {
        if (! ($arg instanceof IR\FnRef)) {
            $out[] = $arg;

            continue;
        }
        $name = $arg->name;
        if (
            $name !== ''
            && ! \str_contains($name, '::')
            && ! \str_contains($name, '\\')
            && ! isBoolCtor($name)
            && isset($destLocalNames[$name])
        ) {
            $out[] = new IR\FnRef(resolvedSymbol($destModule, $name));

            continue;
        }
        $out[] = $arg;
    }

    return $out;
}

/**
 * @param list<IR\Operand> $burned
 */
function specializationKey(string $sourceCallee, string $destModule, array $burned): string
{
    $parts = [$sourceCallee, '->' . $destModule];
    foreach ($burned as $arg) {
        $parts[] = concreteArgKey($arg);
    }

    return \hash('xxh3', \json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: \implode("\0", $parts));
}

function concreteArgKey(IR\Operand $arg): string
{
    return match ($arg::class) {
        IR\FnRef::class => 'f:' . $arg->name,
        IR\ConstInt::class => 'i:' . $arg->value,
        IR\ConstStr::class => 's:' . \strlen($arg->value) . ':' . $arg->value,
        IR\ConstChar::class => 'c:' . $arg->value,
        IR\ConstDouble::class => 'd:' . $arg->value,
        default => throw new \RuntimeException(
            'concreteArgKey: non-concrete operand ' . $arg::class
            . ' (refuse specialization rather than collide on "?")',
        ),
    };
}

/**
 * @param list<IR\Operand> $args
 * @return bool
 */
function burnedArgsIncludeFnRef(array $args, int $burned): bool
{
    foreach (\array_slice($args, 0, $burned) as $arg) {
        if (argMentionsFnRef($arg)) {
            return true;
        }
    }

    return false;
}

function argMentionsFnRef(IR\Operand $arg): bool
{
    return $arg instanceof IR\FnRef;
}

/**
 * True when a burned specialize arg must be assigned once before substitute.
 *
 * @param list<IR\Stmt> $bodyItems
 */
function burnedSpecializeArgMustBindOnce(IR\Operand $arg, array $bodyItems, string $param): bool
{
    if (countLocalUsesInItems($bodyItems, $param) <= 1) {
        return false;
    }
    if (operandIsNonDuplicable($arg)) {
        return true;
    }
    if (! ($arg instanceof IR\ForeignCall)) {
        return false;
    }

    return ! operandHasFreeTemps($arg);
}

/** @param list<IR\Operand> $burnedArgs */
function cloneSpecializedFunction(
    IR\FunctionDecl $target,
    string $specName,
    array $burnedArgs,
    string $origin,
): IR\FunctionDecl
{
    $burned = \count($burnedArgs);
    $params = \array_slice($target->params, $burned);
    $substParams = $target->params;

    $bodyItems = denseStmtItems($target->body->items);
    $nextTemp = maxTempInItems($bodyItems) + 1;
    $remap = buildTempRemap($bodyItems, $nextTemp);
    $bodyItems = remapTempsInItems($bodyItems, $remap);
    if ($remap !== []) {
        $nextTemp = \max($remap) + 1;
    }

    $prefix = [];
    $substArgs = [];
    foreach ($target->params as $i => $param) {
        if ($i < $burned) {
            $arg = $burnedArgs[$i];
            if (burnedSpecializeArgMustBindOnce($arg, $bodyItems, $param)) {
                $dest = $nextTemp++;
                $prefix[] = new IR\Assign($dest, $arg);
                $substArgs[] = new IR\Temp($dest);
            } else {
                $substArgs[] = $arg;
            }

            continue;
        }
        $substArgs[] = new IR\Local($param);
    }

    $bound = [];
    $out = $prefix;
    foreach ($bodyItems as $item) {
        $out[] = substituteStmt($item, $substParams, $substArgs, $bound);
        if ($item instanceof IR\Let) {
            $bound[$item->name] = true;
        }
    }

    return new IR\FunctionDecl(
        $specName,
        $params,
        null,
        new IR\Block(denseStmtItems($out)),
        false,
        false,
        null,
        $target->ioEffect,
        $target->ioStraightLine,
        false,
        $target->actionConvention,
        $target->srcLoc,
        IR\FunctionKind::SpecClone,
        $origin,
    );
}

/**
 * Rewrite bare Calls/FnRefs that refer to bindings from the clone's source
 * module into `Module::name` so destination codegen cannot bind a different
 * import under the same short name (e.g. Main's `Data.List.lookup` vs
 * Generic's `Data.Map.lookup`).
 *
 * Also qualifies source-local lambdas (`λN`) even when the destination already
 * defines the same short name — otherwise Foldable.toList's `@λ2` silently
 * binds Main's zipWith3 lambda. Burned dest-local FnRefs must already be
 * qualified (`Main::λN`) via qualifyBurnedFnRefsForDest before cloning.
 *
 * Resolves both locally defined source functions and names imported into the
 * source module (`$sourceExternalFns`).
 *
 * @param array<string, IR\FunctionKind|true> $sourceFnNames names defined in the source module
 * @param array<string, true> $destLocalNames names the destination module defines itself
 * @param array<string, string> $sourceExternalFns bare => `Origin::name`
 */
function qualifySourceModuleCallees(
    IR\FunctionDecl $clone,
    string $sourceModule,
    string $destModule,
    array $sourceFnNames,
    array $destLocalNames,
    array $sourceExternalFns = [],
): IR\FunctionDecl {
    if ($sourceModule === $destModule) {
        return $clone;
    }

    $rewriteName = static function (string $name) use (
        $sourceModule,
        $sourceFnNames,
        $destLocalNames,
        $sourceExternalFns,
    ): string {
        if ($name === '' || \str_contains($name, '::') || \str_contains($name, '\\')) {
            return $name;
        }
        if (isBoolCtor($name)) {
            return $name;
        }
        $sourceKind = $sourceFnNames[$name] ?? null;
        if (isset($destLocalNames[$name]) && ! ($sourceKind instanceof IR\FunctionKind && $sourceKind->takesCaptures())) {
            return $name;
        }
        if (isset($sourceFnNames[$name])) {
            return resolvedSymbol($sourceModule, $name);
        }
        if (isset($sourceExternalFns[$name])) {
            return $sourceExternalFns[$name];
        }

        return $name;
    };

    $rewriteOperand = null;
    $rewriteOperand = static function (IR\Operand $op) use (&$rewriteOperand, $rewriteName): IR\Operand {
        if ($op instanceof IR\FnRef) {
            return new IR\FnRef($rewriteName($op->name));
        }
        if ($op instanceof IR\Partial) {
            return new IR\Partial(
                $rewriteName($op->fn),
                $op->arity,
                \array_map($rewriteOperand, $op->args),
            );
        }

        return Visit\mapOperandChildren($op, $rewriteOperand);
    };

    $rewriteStmt = null;
    $rewriteStmt = static function (IR\Stmt $stmt) use (&$rewriteStmt, $rewriteOperand, $rewriteName): IR\Stmt {
        return match ($stmt::class) {
            IR\Call::class => new IR\Call(
                $rewriteName($stmt->callee),
                \array_map($rewriteOperand, $stmt->args),
                $stmt->dest,
                $stmt->srcLoc,
            ),
            IR\CallValue::class => new IR\CallValue(
                $rewriteOperand($stmt->callee),
                \array_map($rewriteOperand, $stmt->args),
                $stmt->dest,
                $stmt->srcLoc,
            ),
            IR\DictCall::class => new IR\DictCall(
                $rewriteOperand($stmt->evidence),
                $stmt->method,
                \array_map($rewriteOperand, $stmt->args),
                $stmt->dest,
                $stmt->srcLoc,
                $stmt->localEvidence,
            ),
            IR\Assign::class => new IR\Assign($stmt->dest, $rewriteOperand($stmt->value)),
            IR\Ret::class => new IR\Ret($rewriteOperand($stmt->value)),
            IR\Let::class => new IR\Let($stmt->name, $rewriteOperand($stmt->value)),
            IR\MatchStmt::class => new IR\MatchStmt(
                $rewriteOperand($stmt->scrutinee),
                \array_map(
                    static fn (IR\MatchArm $arm): IR\MatchArm => new IR\MatchArm(
                        $arm->pattern,
                        new IR\Block(\array_map($rewriteStmt, denseStmtItems($arm->body->items))),
                        mapGuards(
                            $arm->guards,
                            $rewriteOperand,
                            static fn (IR\Stmt $s): IR\Stmt => $rewriteStmt($s),
                        ),
                    ),
                    $stmt->arms,
                ),
                $stmt->dest,
                $stmt->exhaustive,
            ),
            IR\MatchReturn::class => new IR\MatchReturn(
                $rewriteOperand($stmt->scrutinee),
                \array_map(
                    static fn (IR\MatchArm $arm): IR\MatchArm => new IR\MatchArm(
                        $arm->pattern,
                        new IR\Block(\array_map($rewriteStmt, denseStmtItems($arm->body->items))),
                        mapGuards(
                            $arm->guards,
                            $rewriteOperand,
                            static fn (IR\Stmt $s): IR\Stmt => $rewriteStmt($s),
                        ),
                    ),
                    $stmt->arms,
                ),
                $stmt->exhaustive,
            ),
            default => $stmt,
        };
    };

    $items = \array_map($rewriteStmt, denseStmtItems($clone->body->items));

    return $clone->withBody(new IR\Block(denseStmtItems($items)));
}

function unboundEvidenceLocals(IR\FunctionDecl $function, array $allowedParams): array
{
    $allowed = \array_fill_keys($allowedParams, true);
    $free = [];
    collectFreeLocalsInItems($function->body->items, $allowed, $free);
    $out = [];
    foreach (\array_keys($free) as $name) {
        if (\str_starts_with($name, '__ev_') || $name === 'opts') {
            $out[] = $name;
        }
    }

    return $out;
}

/**
 * Temps referenced but never defined in a function — invalid IR (JVM emit
 * throws `unknown temp tN`).
 *
 * @return list<int>
 */
function unboundTempsInFunction(IR\FunctionDecl $function): array
{
    $defined = [];
    $used = [];
    collectTempDefsUsesInItems($function->body->items, $defined, $used);
    $out = [];
    foreach (\array_keys($used) as $id) {
        if (! isset($defined[$id])) {
            $out[] = $id;
        }
    }

    return $out;
}

function moduleHasUnboundTemps(IR\Module $module): bool
{
    foreach ($module->functions as $function) {
        if (unboundTempsInFunction($function) !== []) {
            return true;
        }
    }

    return false;
}

/**
 * Every statement form is covered by the shared IR walker, including the bodies
 * nested in the IO statements (`io_match`, `io_assign_action`, `io_catch`,
 * `io_finally`); a hand-rolled chain of `instanceof` checks misses them and
 * reports the `io_assign_action` dest as an unbound temp.
 *
 * @param list<IR\Stmt> $items
 * @param array<int, true> $defined
 * @param array<int, true> $used
 */
function collectTempDefsUsesInItems(array $items, array &$defined, array &$used): void
{
    $visitStmt = static function (IR\Stmt $stmt) use (&$defined): void {
        if (isset($stmt->dest) && \is_int($stmt->dest)) {
            $defined[$stmt->dest] = true;
        }
    };
    $visitOperand = static function (IR\Operand $operand) use (&$used): void {
        if ($operand instanceof IR\Temp) {
            $used[$operand->id] = true;
        }
    };

    walkBlock(new IR\Block(denseStmtItems($items)), $visitStmt, $visitOperand);
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, true> $bound
 * @param array<string, true> $free
 */
function collectFreeLocalsInItems(array $items, array $bound, array &$free): void
{
    foreach (denseStmtItems($items) as $item) {
        if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
            collectFreeLocalsInOperand($item->scrutinee, $bound, $free);
            foreach ($item->arms as $arm) {
                $armBound = $bound;
                foreach (irPatternBoundNames($arm->pattern) as $name) {
                    $armBound[$name] = true;
                }
                collectFreeLocalsInItems($arm->body->items, $armBound, $free);
            }

            continue;
        }

        if ($item instanceof IR\Let) {
            collectFreeLocalsInOperand($item->value, $bound, $free);
            $bound[$item->name] = true;

            continue;
        }

        match ($item::class) {
            IR\Ret::class => collectFreeLocalsInOperand($item->value, $bound, $free),
            IR\Assign::class => collectFreeLocalsInOperand($item->value, $bound, $free),
            IR\Binop::class => (static function () use ($item, $bound, &$free): void {
                collectFreeLocalsInOperand($item->left, $bound, $free);
                collectFreeLocalsInOperand($item->right, $bound, $free);
            })(),
            IR\Call::class => (static function () use ($item, $bound, &$free): void {
                foreach ($item->args as $arg) {
                    collectFreeLocalsInOperand($arg, $bound, $free);
                }
            })(),
            IR\CallValue::class => (static function () use ($item, $bound, &$free): void {
                collectFreeLocalsInOperand($item->callee, $bound, $free);
                foreach ($item->args as $arg) {
                    collectFreeLocalsInOperand($arg, $bound, $free);
                }
            })(),
            IR\DictCall::class => (static function () use ($item, $bound, &$free): void {
                collectFreeLocalsInOperand($item->evidence, $bound, $free);
                foreach ($item->args as $arg) {
                    collectFreeLocalsInOperand($arg, $bound, $free);
                }
            })(),
            default => null,
        };
    }
}

/** @param array<string, true> $bound @param array<string, true> $free */
function collectFreeLocalsInOperand(IR\Operand $operand, array $bound, array &$free): void
{
    if ($operand instanceof IR\Local) {
        if (! isset($bound[$operand->name])) {
            $free[$operand->name] = true;
        }

        return;
    }

    foreach (Visit\operandChildren($operand) as $child) {
        collectFreeLocalsInOperand($child, $bound, $free);
    }
}

/**
 * Hoist pure concrete evidence calls in __spec_* clones to module CAFs.
 *
 * Specialized Generic codecs rebuild large __ev_* trees on every call; when
 * those trees are parameter-pure, lift them to nullary __caf_* bindings so
 * runtime pays once per process instead of once per encode/decode.
 *
 * @param array<string, IR\Module> $modules
 * @param array<string, true> $rootNames
 */
function hoistSpecializeEvidenceCafs(array &$modules, array $rootNames): void
{
    foreach ($modules as $moduleName => $module) {
        if (! isset($rootNames[$moduleName]) || ! isSpecializationRootModule($module)) {
            continue;
        }
        $existing = [];
        foreach ($module->functions as $fn) {
            $existing[$fn->name] = true;
        }
        $newFunctions = [];
        $addedCafs = [];
        foreach ($module->functions as $fn) {
            if ($fn->kind !== IR\FunctionKind::SpecClone) {
                $newFunctions[] = $fn;

                continue;
            }
            $hoisted = hoistEvidenceCafsInFunction($fn, $existing);
            foreach ($hoisted['cafs'] as $caf) {
                $addedCafs[] = $caf;
                $existing[$caf->name] = true;
            }
            $newFunctions[] = $hoisted['function'];
        }
        $modules[$moduleName] = new IR\Module(
            [...$newFunctions, ...$addedCafs],
            $module->data,
            $module->instanceEvidence,
            $module->entry,
            $module->moduleName,
            $module->sourceFile,
        );
    }
}

/** @param array<string, true> $existingNames @return array{function: IR\FunctionDecl, cafs: list<IR\FunctionDecl>} */
function hoistEvidenceCafsInFunction(IR\FunctionDecl $function, array &$existingNames): array
{
    $paramSet = \array_fill_keys($function->params, true);
    $cafs = [];
    $items = denseStmtItems($function->body->items);
    $out = [];
    $changed = false;

    foreach ($items as $item) {
        if ($item instanceof IR\Call && $item->callee !== ''
            && callIsParamPure($item->callee, $item->args, $paramSet)
            && isHoistableEvidenceCall($item)) {
            $cafName = uniqueCafName(callKey($item->callee, $item->args), $existingNames);
            $cafs[] = new IR\FunctionDecl(
                $cafName,
                [],
                null,
                new IR\Block([new IR\Call($item->callee, $item->args, 0, $item->srcLoc), new IR\Ret(new IR\Temp(0))]),
                kind: IR\FunctionKind::Generated,
            );
            $existingNames[$cafName] = true;
            $out[] = new IR\Call($cafName, [], $item->dest, $item->srcLoc);
            $changed = true;

            continue;
        }
        $out[] = $item;
    }

    if (! $changed) {
        return ['function' => $function, 'cafs' => []];
    }

    return [
        'function' => $function->withBody(new IR\Block($out)),
        'cafs' => $cafs,
    ];
}function isHoistableEvidenceCall(IR\Call $call): bool
{
    if (! isEvidenceFactoryName($call->callee) && ! isPlausibleConstFn($call->callee)) {
        return false;
    }

    return isConcreteCall($call->callee, $call->args);
}

/** True when a call's arguments name no parameter and run nothing foreign. @param array<string, true> $paramSet @param list<IR\Operand> $args */
function callIsParamPure(string $callee, array $args, array $paramSet): bool
{
    if ($callee === 'perform' || \str_starts_with($callee, 'perform#')) {
        return false;
    }
    foreach ($args as $arg) {
        $free = [];
        collectFreeLocalsInOperand($arg, [], $free);
        foreach (\array_keys($free) as $name) {
            if (isset($paramSet[$name])) {
                return false;
            }
        }
        if (operandRunsForeign($arg)) {
            return false;
        }
    }

    return true;
}

/** True when the operand tree reaches a host call. */
function operandRunsForeign(IR\Operand $operand): bool
{
    if ($operand instanceof IR\ForeignCall) {
        return true;
    }
    foreach (Visit\operandChildren($operand) as $child) {
        if (operandRunsForeign($child)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, true> $existingNames */
function uniqueCafName(string $key, array $existingNames): string
{
    $base = '__caf_' . \substr(\hash('sha256', $key), 0, 12);
    $name = $base;
    $i = 0;
    while (isset($existingNames[$name])) {
        $name = $base . '_' . ++$i;
    }

    return $name;
}
