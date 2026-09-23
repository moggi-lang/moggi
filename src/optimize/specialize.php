<?php declare(strict_types=1);

namespace Moggi\Optimize\Specialize;

use Moggi\IR;
use Moggi\IR\Visit;
use Moggi\Optimize\CaseFold\CtorTree;

use function Moggi\IR\Dump\dump;
use function Moggi\IR\Visit\irPatternBoundNames;
use function Moggi\IR\Visit\mapGuards;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Modules\resolvedSymbol;
use function Moggi\Optimize\CaseFold\clearNullaryCtorCafs;
use function Moggi\Optimize\CaseFold\isPlausibleConstructorName;
use function Moggi\Optimize\CaseFold\nullaryCtorCaf;
use function Moggi\Optimize\CaseFold\setFieldAccessorCafs;
use function Moggi\Optimize\CaseFold\setNullaryCtorCafs;
use function Moggi\Optimize\Interproc\buildTempRemap;
use function Moggi\Optimize\Interproc\clearActiveFnArity;
use function Moggi\Optimize\Interproc\clearActivePeFusionIndex;
use function Moggi\Optimize\Interproc\countLocalUsesInItems;
use function Moggi\Optimize\Interproc\denseStmtItems;
use function Moggi\Optimize\Interproc\isPeFusionInlineCandidate;
use function Moggi\Optimize\Interproc\maxTempInItems;
use function Moggi\Optimize\Interproc\operandHasFreeTemps;
use function Moggi\Optimize\Interproc\operandIsNonDuplicable;
use function Moggi\Optimize\Interproc\remapTempsInItems;
use function Moggi\Optimize\Interproc\setActiveFnArity;
use function Moggi\Optimize\Interproc\setActiveForeignLeaves;
use function Moggi\Optimize\Interproc\setActivePeFusionIndex;
use function Moggi\Optimize\Interproc\substituteStmt;
use function Moggi\Optimize\Support\isLambdaName;
use function Moggi\Optimize\Support\isWiredInBoolCtor;
use function Moggi\Optimize\optimize;

/**
 * Whole-program call-site specialization for concrete evidence / constant args.
 *
 * Clones a callee with known leading FnRef/Const arguments burned in, caches
 * by (callee, arg key), then re-optimizes affected modules.
 *
 * Eligibility (architectural boundary, not an ad-hoc module denylist):
 * - Only *call sites* in modules that have an executable `Main` entry point
 *   are rewritten. Library modules stay polymorphic so evidence factories and
 *   shared dictionaries are not specialized from every consumer.
 * - Callees may live in any module; clones are added to the caller's module
 *   with qualified `Module::name` references (definitions and imports).
 * - Nested ExprCalls inside Ret/Call args are specialized (e.g.
 *   `buildRecord(fieldPairs(…))`), not only top-level Call statements.
 * - Fresh clones are specializeItems'd in the same round (bounded queue) so
 *   nested concrete calls are not stranded on the last outer round.
 * - Clones that would retain unbound `__ev_*` parameters, invented dict
 *   methods, Loop, or TailRecall are refused.
 * - At most 4 specialize→reoptimize rounds.
 *
 * @param array<string, IR\Module> $modules module name => optimized IR
 * @param array<string, array<string, string>> $externalFnsByModule
 *        module => (bare local name => `Origin::name`) from that module's
 *        import context. Required so cross-module clones keep the source
 *        module's binding when the destination imports a different symbol
 *        under the same short name (e.g. `Data.Map.lookup` vs `Data.List.lookup`).
 * @return array{modules: array<string, IR\Module>, stats: array{specializations: int, cacheHits: int}}
 */
function specializeAcrossModules(array $modules, array $externalFnsByModule = []): array
{
    /** @var array<string, array{module: string, name: string, sourceModule?: string}> $cache */
    $cache = [];
    $specializations = 0;
    $cacheHits = 0;
    $changedModules = [];

    // Register nullary ctor CAFs up front so concrete-arg checks can treat
    // library Options/Bool CAFs as burnable without hardcoding their names.
    setNullaryCtorCafs(collectNullaryCtorCafs($modules, $externalFnsByModule));
    setFieldAccessorCafs(collectFieldAccessorCafs($modules));

    // Several rounds: specialize → resolve concrete dict_calls → re-optimize →
    // specialize nested callees exposed by folding (e.g. fieldPairs under
    // buildRecord after conIsRecord PE). Cap keeps compile time bounded.
    for ($round = 0; $round < 4; ++$round) {
        $world = activateSpecializeWorld($modules, $externalFnsByModule);
        $roundChanged = false;
        foreach ($modules as $moduleName => $module) {
            if (!isSpecializationRootModule($module)) {
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

        if (!$roundChanged) {
            break;
        }
    }

    // Final optimize on roots so late-grown thin __spec_* callees (single-Ret)
    // are inlined and case-folded against concrete constructors from `from`.
    // Two root passes: first optimize exposes concrete ExprCall(K1, …) sites
    // after flatten/join; the second PE-reduces those match wrappers.
    setNullaryCtorCafs(collectNullaryCtorCafs($modules, $externalFnsByModule));
    setFieldAccessorCafs(collectFieldAccessorCafs($modules));
    try {
        hoistSpecializeEvidenceCafs($modules, $changedModules);
        optimizeSpecializationRoots($modules, $changedModules, 4);
        hoistSpecializeEvidenceCafs($modules, $changedModules);
        setNullaryCtorCafs(collectNullaryCtorCafs($modules, $externalFnsByModule));
        setFieldAccessorCafs(collectFieldAccessorCafs($modules));
        // After CAF field-fold, concrete Bool/Options args appear. Keep
        // specialize→optimize while the rounds still pay: optimize often exposes
        // wrappers such as encodeC1EncBody only after a clone is case-folded.
        // Only the functions the previous round changed -- and the clones it
        // added -- can hold a new opportunity, so the next round walks those
        // instead of the whole root module; a round adding a tenth of the best
        // round's clones is where the curve flattens.
        $extraBestGain = 0;
        $onlyFunctions = null;
        for ($extra = 0; $extra < 8; ++$extra) {
            $before = $specializations;
            $snapshot = specializeFunctionSnapshots($modules, $changedModules);
            $world = activateSpecializeWorld($modules, $externalFnsByModule);
            $roundChanged = false;
            foreach ($modules as $moduleName => $module) {
                if (!isset($changedModules[$moduleName]) || !isSpecializationRootModule($module)) {
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
            if ($extraGain === 0 && !$roundChanged) {
                break;
            }
            optimizeSpecializationRoots($modules, $changedModules, 1);
            $onlyFunctions = specializeChangedFunctions($modules, $changedModules, $snapshot);
            if ($onlyFunctions === [] || $extraGain * 10 < $extraBestGain) {
                break;
            }
        }
        foreach ($changedModules as $moduleName => $_) {
            if (!isset($modules[$moduleName])) {
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
        if (!isset($modules[$moduleName])) {
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
        if (!isset($modules[$moduleName])) {
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
    /** @var array<string, array<string, true>> $fnsByModule */
    $fnsByModule = [];
    foreach ($modules as $moduleName => $module) {
        foreach ($module->functions as $function) {
            $short = $function->name;
            $fnsByModule[$moduleName][$short] = true;
            $qualified = resolvedSymbol($moduleName, $short);
            $entry = [
                'module' => $moduleName,
                'function' => $function,
            ];
            // Always index qualified names; bare short names only when unique
            // across the program (avoid specializing the wrong `map`, etc.).
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
            $arity = count($function->params);
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
        // A fixpoint round only has to re-walk the functions the previous round
        // touched: `specializeItems` decides from a function's own body and the
        // callee index, so re-walking an unchanged body cannot find anything new.
        if ($onlyFunctions !== null && ! isset($onlyFunctions[$function->name])) {
            $newFunctions[] = $function;
            continue;
        }

        if ($function->ioStraightLine) {
            // A straight-line IO body is not specialized, but a concrete dictionary call is a plain
            // substitution, so `main` gets it too.
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

        // Resolve dict_calls whose evidence is a concrete FnRef / __ev_
        // ExprCall into direct Calls (cross-module allowed here).
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

    // Emit every clone created (call sites point at them); nested specialization is budgeted
    // and leftovers are picked up by the next round.
    $pending = $added;
    $cloneNestBudget = 64;
    while ($pending !== []) {
        $clone = array_shift($pending);
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

    if (!$moduleChanged) {
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
        $beforeByName = [];
        foreach ($updated->functions as $fn) {
            $beforeByName[$fn->name] = $fn;
        }
        $updated = restoreUnboundAfterOptimize(optimize($updated), $beforeByName);
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
            if (count($function->params) !== 1 || $function->foreign) {
                continue;
            }
            $items = denseStmtItems($function->body->items);
            if (count($items) !== 1 || !($items[0] instanceof IR\Ret)) {
                continue;
            }
            $ret = $items[0]->value;
            if (
                !($ret instanceof IR\ExprCall)
                || !str_starts_with($ret->callee, '__field')
                || count($ret->args) !== 1
                || !($ret->args[0] instanceof IR\Local)
                || $ret->args[0]->name !== $function->params[0]
            ) {
                continue;
            }
            $idx = (int) substr($ret->callee, strlen('__field'));
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
            $items = denseStmtItems($function->body->items);
            if (count($items) !== 1 || !($items[0] instanceof IR\Ret)) {
                continue;
            }
            $tree = cafRetToCtorTree($items[0]->value, $moduleName, $externals);
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
 * @param array<string, string> $externals
 */
function cafRetToCtorTree(IR\Operand $value, string $moduleName = '', array $externals = []): ?CtorTree
{
    if ($value instanceof IR\FnRef) {
        if (isPlausibleConstructorName($value->name)) {
            return new CtorTree($value->name, []);
        }

        // Non-constructor CAF leaves (e.g. `id` inside Options CAFs) must stay
        // module-qualified so destination modules can emit/import them.
        return null;
    }
    if ($value instanceof IR\ExprCall && isPlausibleConstructorName($value->callee)) {
        $args = [];
        foreach ($value->args as $arg) {
            if ($arg instanceof IR\FnRef && isPlausibleConstructorName($arg->name)) {
                $args[] = new CtorTree($arg->name, []);
                continue;
            }
            if ($arg instanceof IR\FnRef) {
                $name = $arg->name;
                if ($name !== '' && !str_contains($name, '::') && !str_contains($name, '\\')) {
                    if (isset($externals[$name])) {
                        $args[] = new IR\FnRef($externals[$name]);
                        continue;
                    }
                    if ($moduleName !== '') {
                        $args[] = new IR\FnRef(resolvedSymbol($moduleName, $name));
                        continue;
                    }
                }
                $args[] = $arg;
                continue;
            }
            if ($arg instanceof IR\ExprCall && isPlausibleConstructorName($arg->callee)) {
                $nested = cafRetToCtorTree($arg, $moduleName, $externals);
                if ($nested === null) {
                    return null;
                }
                $args[] = $nested;
                continue;
            }
            $args[] = $arg;
        }

        return new CtorTree($value->callee, $args);
    }

    return null;
}

/**
 * @param array<string, IR\Module> $modules
 * @param array<string, true> $rootNames
 */
function optimizeSpecializationRoots(array &$modules, array $rootNames, int $passes): void
{
    // Refresh define/import maps so pe-fusion qualifyBareName can resolve
    // evidence imports to their origin module (not the pe-fusion source).
    $fnsByModule = [];
    foreach ($modules as $moduleName => $module) {
        foreach ($module->functions as $function) {
            $fnsByModule[$moduleName][$function->name] = true;
        }
        registerModuleConstructorNames($fnsByModule, $moduleName, $module);
    }
    setActiveSpecializeFnsByModule($fnsByModule);
    // externalFnsByModule stays as last setActiveSpecializeExternalFnsByModule.
    $fnArity = [];
    foreach ($modules as $moduleName => $module) {
        foreach ($module->functions as $function) {
            $arity = count($function->params);
            $fnArity[$function->name] = $arity;
            $fnArity[resolvedSymbol((string) $moduleName, $function->name)] = $arity;
        }
    }
    setActiveFnArity($fnArity);

    try {
        foreach ($modules as $moduleName => $module) {
            if (!isset($rootNames[$moduleName]) || !isSpecializationRootModule($module)) {
                continue;
            }
            for ($optPass = 0; $optPass < $passes; ++$optPass) {
                // Qualified-only pe index of pe-fusion candidates (no short names)
                // so list consumers like fieldKeys can unfold at known ListLit sites.
                setPeFusionIndexFromModules($modules);
                $beforeByName = [];
                foreach ($module->functions as $fn) {
                    $beforeByName[$fn->name] = $fn;
                }
                $optimized = restoreUnboundAfterOptimize(optimize($module), $beforeByName);
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
    if ($operand instanceof IR\ExprCall || $operand instanceof IR\Intrinsic) {
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
        // Only register pe-fusion candidates under qualified names. Short-name
        // keys pulled unrelated helpers (e.g. Path.empty) into Main. Skip
        // host/foreign bodies — those must stay in their defining module.
        foreach ($module->functions as $fn) {
            if ($mn === ''
                || !isPeFusionInlineCandidate($fn)
                || functionBodyHasHostOrForeign($fn->body->items)
            ) {
                continue;
            }
            $index[resolvedSymbol($mn, $fn->name)] = $fn;
        }
    }
    setActivePeFusionIndex($index);
}

/**
 * If optimize() drops a temp definition while leaving uses (seen on specialized
 * Enum.enumFromThenTo clones), keep the pre-optimize body for that function.
 *
 * @param array<string, IR\FunctionDecl> $beforeByName
 */
function restoreUnboundAfterOptimize(IR\Module $optimized, array $beforeByName): IR\Module
{
    /** @var array<string, true> $restore */
    $restore = [];
    foreach ($optimized->functions as $fn) {
        if (unboundTempsInFunction($fn) === [] || !isset($beforeByName[$fn->name])) {
            continue;
        }
        if (unboundTempsInFunction($beforeByName[$fn->name]) === []) {
            $restore[$fn->name] = true;
        }
    }
    if ($restore === []) {
        return $optimized;
    }

    // Arity-raising must move with the rollback. A restored body may call a CAF
    // whose parameter list this optimize pass raised, and a raised signature is
    // only valid while every caller was rewritten to match it — the restored
    // callers were not. Restoring the changed definitions keeps the two halves
    // consistent (otherwise JVM/.NET call a method the class no longer has).
    foreach ($optimized->functions as $fn) {
        $prev = $beforeByName[$fn->name] ?? null;
        if ($prev === null || isset($restore[$fn->name])) {
            continue;
        }
        if (count($prev->params) !== count($fn->params) && unboundTempsInFunction($prev) === []) {
            $restore[$fn->name] = true;
        }
    }

    $fns = [];
    foreach ($optimized->functions as $fn) {
        $fns[] = isset($restore[$fn->name]) ? $beforeByName[$fn->name] : $fn;
    }

    return new IR\Module(
        $fns,
        $optimized->data,
        $optimized->instanceEvidence,
        $optimized->entry,
        $optimized->moduleName,
        $optimized->sourceFile,
    );
}

function assertNoUnboundTempsAfterSpecialize(IR\Module $module, string $moduleName): void
{
    if (!moduleHasUnboundTemps($module)) {
        return;
    }
    $bad = [];
    foreach ($module->functions as $fn) {
        $temps = unboundTempsInFunction($fn);
        if ($temps !== []) {
            $bad[] = $fn->name . '[' . implode(',', $temps) . ']';
        }
    }
    throw new \RuntimeException(
        'optimize after specialization produced unbound temps in '
        . $moduleName . ': ' . implode(', ', $bad),
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
            $concreteTemps[$item->dest] = new IR\ExprCall($item->callee, resolveConcreteArgs($item->args, $concreteTemps));
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

        $out[] = $item;
    }

    return ['items' => denseStmtItems($out), 'changed' => $changed];
}

/**
 * @param array<int, IR\Operand> $concreteTemps
 * @param array<string, array{module: string, methods: array<string, string>}> $globalEvidence
 */
function tryResolveConcreteDictCall(IR\DictCall $stmt, array $concreteTemps, array $globalEvidence): ?IR\Stmt
{
    $evidence = resolveConcreteOperand($stmt->evidence, $concreteTemps);
    $binding = null;

    // Cross-module specialize qualifies evidence factories as `Module::__ev_…`;
    // the evidence map is keyed by the bare `__ev_` leaf name.
    if ($evidence instanceof IR\FnRef && isEvidenceFactoryName($evidence->name)) {
        $binding = ['name' => specializationLookupName($evidence->name), 'args' => []];
    } elseif (
        $evidence instanceof IR\ExprCall
        && isEvidenceFactoryName($evidence->callee)
        && isConcreteSpecializeArg($evidence)
    ) {
        $binding = ['name' => specializationLookupName($evidence->callee), 'args' => $evidence->args];
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
    // Do not invent `factory_method` names — hashes often differ from the
    // method IR name, and missing map entries must stay as DictCall.
    if ($methodIr === null) {
        return null;
    }

    // Qualify with the evidence module so a destination that defines the same
    // short name (e.g. derived `compare` for Age) does not bind the call to
    // itself — that caused StackOverflow on newtype Ord/Eq/Show.
    if (
        $methodModule !== ''
        && $methodIr !== ''
        && !str_contains($methodIr, '::')
        && !str_contains($methodIr, '\\')
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
            $concreteTemps[$item->dest] = new IR\ExprCall($item->callee, resolveConcreteArgs($item->args, $concreteTemps));
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
 * Specialize nested ExprCalls inside an operand (e.g. `buildRecord(fieldPairs(…))`).
 * Top-level Call/Ret sites alone miss nested concrete dictionaries after folding.
 *
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
    if ($operand instanceof IR\ExprCall) {
        $nested = specializeOperandList(
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
        $args = resolveConcreteArgs($nested['args'], $concreteTemps);
        $spec = resolveSpecializedCallee(
            $operand->callee,
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
            return [
                'operand' => new IR\ExprCall($spec['name'], \array_slice($args, $spec['burned']), $operand->srcLoc),
                'changed' => true,
            ];
        }
        if ($nested['changed']) {
            return [
                'operand' => new IR\ExprCall($operand->callee, $nested['args']),
                'changed' => true,
            ];
        }

        return ['operand' => $operand, 'changed' => false];
    }

    if ($operand instanceof IR\ExprBinop) {
        $left = specializeOperand(
            $operand->left,
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
        $right = specializeOperand(
            $operand->right,
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
        if ($left['changed'] || $right['changed']) {
            return [
                'operand' => new IR\ExprBinop($operand->op, $left['operand'], $right['operand']),
                'changed' => true,
            ];
        }

        return ['operand' => $operand, 'changed' => false];
    }

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

/** @param array<int, IR\Operand> $concreteTemps */
function resolveConcreteOperand(IR\Operand $arg, array $concreteTemps): IR\Operand
{
    if ($arg instanceof IR\Temp && isset($concreteTemps[$arg->id])) {
        return $concreteTemps[$arg->id];
    }
    if ($arg instanceof IR\ExprCall) {
        return new IR\ExprCall($arg->callee, resolveConcreteArgs($arg->args, $concreteTemps), $arg->srcLoc);
    }

    return $arg;
}

/** @param array<int, IR\Operand> $concreteTemps */
function isConcreteSpecializeCall(IR\Call $call, array $concreteTemps): bool
{
    return isConcreteSpecializeArg(new IR\ExprCall(
        $call->callee,
        resolveConcreteArgs($call->args, $concreteTemps),
        $call->srcLoc,
    ));
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
    if ($lookup === $selfName || isLambdaName($lookup) || str_starts_with($lookup, '__spec_')) {
        return null;
    }

    // Prefer the fully-qualified key when the call site was already qualified;
    // fall back to a unique bare short name.
    $targetInfo = $globalIndex[$callee] ?? $globalIndex[$lookup] ?? null;
    if ($targetInfo === null || !isset($targetInfo['function'])) {
        return null;
    }

    // If the call was already qualified, the origin module must match.
    $parsed = parseResolvedSymbol($callee);
    if ($parsed !== null && ($targetInfo['module'] ?? null) !== $parsed['module']) {
        return null;
    }

    // Cross-module only — specializing within a module (e.g. Generic codecs)
    // explodes into combinatorial clones of mutually-recursive dict methods.
    if ($targetInfo['module'] === $moduleName) {
        return null;
    }

    $target = $targetInfo['function'];
    if ($target->foreign || $target->ioEffect || !isSpecializeCandidate($target)) {
        return null;
    }

    $burned = countConcretePrefix($args);
    if ($burned === 0 || $burned > count($target->params)) {
        return null;
    }

    // Must burn every leading evidence/options param; only ordinary value
    // parameters may remain (otherwise clones retain unbound __ev_* locals).
    $runtimeParams = \array_slice($target->params, $burned);
    foreach ($runtimeParams as $param) {
        if (str_starts_with($param, '__ev_') || $param === 'opts' || str_starts_with($param, '__ev')) {
            return null;
        }
    }

    if (!burnedArgsIncludeFnRef($args, $burned)) {
        return null;
    }

    // Qualify dest-local FnRefs in burned args before cloning so they are not rewritten to the
    // source module's same short λ name.
    $burnedArgs = qualifyBurnedFnRefsForDest(
        \array_slice($args, 0, $burned),
        $moduleName,
        $localNames,
    );

    // Key includes source + dest module so A::foo and B::foo with identical
    // burned args cannot share a clone name that only exists in one module.
    $key = specializationKey(
        $targetInfo['module'] . '::' . $lookup,
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

    $specName = '__spec_' . preg_replace('/[^A-Za-z0-9_]/', '_', $lookup) . '_' . substr(sha1($key), 0, 12);
    // The clone enters the cache *before* its body is rewritten, so a recursive function
    // resolves to this name instead of starting a clone that never terminates.
    $cache[$key] = [
        'module' => $moduleName,
        'sourceModule' => $targetInfo['module'],
        'name' => $specName,
    ];
    $clone = cloneSpecializedFunction($target, $specName, $burnedArgs);
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
        // Refuse clones that would emit free evidence locals (e.g. Match-arm
        // params missed by substitution). Prefer leaving the original call.
        unset($cache[$key]);

        return null;
    }
    if (unboundTempsInFunction($clone) !== []) {
        unset($cache[$key]);

        return null;
    }
    if (unresolvedNonNullaryDictCalls($clone) !== []) {
        // Concrete non-nullary evidence still in a DictCall cannot be emitted
        // safely (method missing from the evidence map).
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
 * @param array<string, array<string, true>> $fnsByModule
 */
function registerModuleConstructorNames(array &$fnsByModule, string $moduleName, IR\Module $module): void
{
    foreach ($module->data as $decl) {
        foreach ($decl->constructors as $ctor) {
            if (isWiredInBoolCtor($ctor->name)) {
                continue;
            }
            $fnsByModule[$moduleName][$ctor->name] = true;
        }
    }
}

/** @param array<string, array<string, true>> $fnsByModule */
function setActiveSpecializeFnsByModule(array $fnsByModule): void
{
    $GLOBALS['__moggi_specialize_fns_by_module'] = $fnsByModule;
}

/** @return array<string, array<string, true>> */
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
    if ($items === [] || count($items) > 12) {
        return false;
    }

    $stmtBudget = 0;
    foreach ($items as $item) {
        // Loop/TailRecall bodies are not substituted today; refuse rather than
        // emit clones with unburned evidence params still free inside the loop.
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
            $n += count(denseStmtItems($arm->body->items));
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
        // Do not burn host allocators / foreign nullaries — substituting them
        // into multi-use params rematerializes a fresh object per use.
        if (operandIsNonDuplicable($arg)) {
            return false;
        }

        return true;
    }
    if ($arg instanceof IR\ConstInt || $arg instanceof IR\ConstStr
        || $arg instanceof IR\ConstChar || $arg instanceof IR\ConstDouble) {
        return true;
    }

    if ($arg instanceof IR\ExprCall) {
        if ($arg->args === [] && operandIsNonDuplicable($arg)) {
            return false;
        }
        foreach ($arg->args as $inner) {
            if (!isConcreteSpecializeArg($inner)) {
                return false;
            }
        }

        // A concrete dictionary is a *nullary* value: the evidence constant
        // itself, a CAF, or a nullary constructor. A call to a dictionary
        // *member* (`__ev_Num_Int_mul(x, y)`) or to a CAF, with arguments, is
        // runtime arithmetic, not a compile-time constant. Burning it makes
        // the recursive call of a specialized function (`powAcc`'s `x * x`)
        // look like a fresh specialization each time it is cloned, and the
        // burned operand doubles per level -- unbounded clone chains.
        if ($arg->args === []) {
            return isEvidenceFactoryName($arg->callee)
                || isSpecCloneName($arg->callee)
                || isPlausibleConstFn($arg->callee)
                || isPlausibleConstructorName($arg->callee);
        }

        return isPlausibleConstructorName($arg->callee);
    }

    return false;
}

function isEvidenceFactoryName(string $name): bool
{
    return str_starts_with(specializationLookupName($name), '__ev_');
}

function isSpecCloneName(string $name): bool
{
    return str_starts_with(specializationLookupName($name), '__spec_');
}

function isPlausibleConstFn(string $name): bool
{
    $leaf = specializationLookupName($name);
    // Hoisted specialize CAFs, or any nullary ctor CAF registered for case-fold
    // (Options, Bool, …). Do not hardcode library binding names here.
    if (str_starts_with($leaf, '__caf_')) {
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
        if (!($arg instanceof IR\FnRef)) {
            $out[] = $arg;
            continue;
        }
        $name = $arg->name;
        if (
            $name !== ''
            && !str_contains($name, '::')
            && !str_contains($name, '\\')
            && !isWiredInBoolCtor($name)
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

    // Hash structured parts so ConstStr payloads cannot collide via `|` / `,`.
    return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: implode("\0", $parts));
}

function concreteArgKey(IR\Operand $arg): string
{
    return match ($arg::class) {
        IR\FnRef::class => 'f:' . $arg->name,
        IR\ConstInt::class => 'i:' . $arg->value,
        IR\ConstStr::class => 's:' . strlen($arg->value) . ':' . $arg->value,
        IR\ConstChar::class => 'c:' . $arg->value,
        IR\ConstDouble::class => 'd:' . $arg->value,
        IR\ExprCall::class => 'e:' . $arg->callee . '(' . implode(',', \array_map(concreteArgKey(...), $arg->args)) . ')',
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
    if ($arg instanceof IR\FnRef) {
        return true;
    }
    if ($arg instanceof IR\ExprCall) {
        foreach ($arg->args as $inner) {
            if (argMentionsFnRef($inner)) {
                return true;
            }
        }
    }

    return false;
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
    if (!($arg instanceof IR\ExprCall) && !($arg instanceof IR\ForeignCall)) {
        return false;
    }
    if (operandHasFreeTemps($arg)) {
        return false;
    }
    if ($arg instanceof IR\ExprCall && isTransparentEvidenceFactoryCall($arg)) {
        return false;
    }
    // Constructor spines must stay transparent for case-fold / record PE.
    if (
        $arg instanceof IR\ExprCall
        && isPlausibleConstructorName(
            specializationLookupName($arg->callee),
        )
    ) {
        return false;
    }

    return true;
}

/** Evidence dict builders (`__ev_Foo` / `__ev_Foo_h_HASH`), not method calls. */
function isTransparentEvidenceFactoryCall(IR\ExprCall $call): bool
{
    $leaf = specializationLookupName($call->callee);
    if (!str_starts_with($leaf, '__ev_')) {
        return false;
    }
    // Method: __ev_…_h_<hash>_<methodName>
    if (preg_match('/^__ev_.+_h_[0-9a-f]+_[A-Za-z]/', $leaf) === 1) {
        return false;
    }

    return true;
}

/** @param list<IR\Operand> $burnedArgs */
function cloneSpecializedFunction(IR\FunctionDecl $target, string $specName, array $burnedArgs): IR\FunctionDecl
{
    $burned = count($burnedArgs);
    $params = \array_slice($target->params, $burned);
    $substParams = $target->params;

    $bodyItems = denseStmtItems($target->body->items);
    $nextTemp = maxTempInItems($bodyItems) + 1;
    $remap = buildTempRemap($bodyItems, $nextTemp);
    $bodyItems = remapTempsInItems($bodyItems, $remap);
    if ($remap !== []) {
        $nextTemp = max($remap) + 1;
    }

    // Multi-use burned args must not rematerialize at every substitute site:
    // - non-duplicable seeds (@newLinkedHashMap) are incorrect if duplicated;
    // - pure computations (e.g. gRecordEncFields → field list) re-run on every
    //   match scrutinee / recursive step after specialize burns the arg.
    // Evidence *factories* stay transparent so nested specialize still sees
    // concrete `__ev_*` spines; evidence *method* calls are computations.
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
        $target->ioBodyKind,
        $target->srcLoc,
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
 * @param array<string, true> $sourceFnNames names defined in the source module
 * @param array<string, true> $destLocalNames unused; kept for call-site compatibility
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
        if ($name === '' || str_contains($name, '::') || str_contains($name, '\\')) {
            return $name;
        }
        // A wired-in `Bool` constructor stays bare: qualifying it (the source
        // module's import binding is `Data.Bool::False`) would hide it from the
        // backends' host-boolean lowering.
        if (isWiredInBoolCtor($name)) {
            return $name;
        }
        // Prefer dest locals for ordinary names, but NEVER for lambdas: Main's
        // `λ2` must not capture Foldable.toList's `λ2` (Partial→MList bugs).
        if (isset($destLocalNames[$name]) && !isLambdaName($name)) {
            return $name;
        }
        if (isset($sourceFnNames[$name])) {
            return resolvedSymbol($sourceModule, $name);
        }
        // Preserve the source module's import binding when short names collide
        // across libraries (Map.lookup vs List.lookup, etc.).
        if (isset($sourceExternalFns[$name])) {
            return $sourceExternalFns[$name];
        }

        return $name;
    };

    $rewriteOperand = null;
    $rewriteOperand = static function (IR\Operand $op) use (&$rewriteOperand, $rewriteName): IR\Operand {
        // Callee/fn names live as strings, not operand children, so they must be rewritten
        // explicitly; everything else goes through Visit\mapOperandChildren.
        if ($op instanceof IR\FnRef) {
            return new IR\FnRef($rewriteName($op->name));
        }
        if ($op instanceof IR\ExprCall) {
            return new IR\ExprCall(
                $rewriteName($op->callee),
                \array_map($rewriteOperand, $op->args),
                $op->srcLoc,
            );
        }
        if ($op instanceof IR\ExprPartial) {
            return new IR\ExprPartial(
                $rewriteName($op->fn),
                $op->arity,
                \array_map($rewriteOperand, $op->args),
            );
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
        if (str_starts_with($name, '__ev_') || $name === 'opts') {
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
        if (!isset($defined[$id])) {
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

    Visit\walkBlock(new IR\Block(denseStmtItems($items)), $visitStmt, $visitOperand);
}

/**
 * DictCalls whose evidence is a concrete non-nullary ExprCall — emit-time
 * nullary resolution cannot handle these, and inventing method names is unsafe.
 *
 * @return list<string> method names
 */
function unresolvedNonNullaryDictCalls(IR\FunctionDecl $function): array
{
    $out = [];
    $visit = static function (array $items) use (&$visit, &$out): void {
        foreach (denseStmtItems($items) as $item) {
            if (
                $item instanceof IR\DictCall
                && $item->evidence instanceof IR\ExprCall
                && $item->evidence->args !== []
                && isConcreteSpecializeArg($item->evidence)
            ) {
                $out[] = $item->method;
            }
            if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
                foreach ($item->arms as $arm) {
                    $visit($arm->body->items);
                }
            }
        }
    };
    $visit($function->body->items);

    return $out;
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
        if (!isset($bound[$operand->name])) {
            $free[$operand->name] = true;
        }

        return;
    }

    // Walk all operand children (ListLit, DictMethod, ForeignCall, Partial, …)
    // so evidence / opts trapped only inside list payloads are still refused.
    foreach (Visit\operandChildren($operand) as $child) {
        collectFreeLocalsInOperand($child, $bound, $free);
    }
}

/**
 * Hoist pure concrete evidence ExprCalls in __spec_* clones to module CAFs.
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
        if (!isset($rootNames[$moduleName]) || !isSpecializationRootModule($module)) {
            continue;
        }
        $existing = [];
        foreach ($module->functions as $fn) {
            $existing[$fn->name] = true;
        }
        $newFunctions = [];
        $addedCafs = [];
        foreach ($module->functions as $fn) {
            if (!str_starts_with($fn->name, '__spec_')) {
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
            && operandIsParamPure(new IR\ExprCall($item->callee, $item->args), $paramSet)
            && isHoistableEvidenceCall($item)) {
            $operand = new IR\ExprCall($item->callee, $item->args);
            $cafName = uniqueCafName($operand, $existingNames);
            $cafs[] = new IR\FunctionDecl(
                $cafName,
                [],
                null,
                new IR\Block([new IR\Ret($operand)]),
            );
            $existingNames[$cafName] = true;
            $out[] = new IR\Call($cafName, [], $item->dest, $item->srcLoc);
            $changed = true;
            continue;
        }
        if ($item instanceof IR\Assign && operandIsParamPure($item->value, $paramSet)
            && isHoistableEvidenceOperand($item->value)) {
            $cafName = uniqueCafName($item->value, $existingNames);
            $cafs[] = new IR\FunctionDecl(
                $cafName,
                [],
                null,
                new IR\Block([new IR\Ret($item->value)]),
            );
            $existingNames[$cafName] = true;
            $out[] = new IR\Assign($item->dest, new IR\ExprCall($cafName, []));
            $changed = true;
            continue;
        }
        $out[] = $item;
    }

    if (!$changed) {
        return ['function' => $function, 'cafs' => []];
    }

    return [
        'function' => $function->withBody(new IR\Block($out)),
        'cafs' => $cafs,
    ];
}

/** @param array<string, true> $paramSet */
function operandIsParamPure(IR\Operand $operand, array $paramSet): bool
{
    $free = [];
    collectFreeLocalsInOperand($operand, [], $free);
    foreach (\array_keys($free) as $name) {
        if (isset($paramSet[$name])) {
            return false;
        }
    }

    return !operandHasForeignOrIo($operand);
}

function operandHasForeignOrIo(IR\Operand $operand): bool
{
    if ($operand instanceof IR\ForeignCall || $operand instanceof IR\ExprCallValue) {
        return true;
    }
    if ($operand instanceof IR\ExprCall) {
        if ($operand->callee === 'perform' || str_starts_with($operand->callee, 'perform#')) {
            return true;
        }
    }
    foreach (Visit\operandChildren($operand) as $child) {
        if (operandHasForeignOrIo($child)) {
            return true;
        }
    }

    return false;
}

function isHoistableEvidenceOperand(IR\Operand $operand): bool
{
    if ($operand instanceof IR\ExprCall) {
        return isConcreteSpecializeArg($operand)
            && (isEvidenceFactoryName($operand->callee)
                || isPlausibleConstFn($operand->callee));
    }

    return false;
}

function isHoistableEvidenceCall(IR\Call $call): bool
{
    if (!isEvidenceFactoryName($call->callee) && !isPlausibleConstFn($call->callee)) {
        return false;
    }

    return isConcreteSpecializeArg(new IR\ExprCall($call->callee, $call->args, $call->srcLoc));
}

/** @param array<string, true> $existingNames */
function uniqueCafName(IR\Operand $operand, array $existingNames): string
{
    $base = '__caf_' . substr(hash('sha256', concreteArgKey($operand)), 0, 12);
    $name = $base;
    $i = 0;
    while (isset($existingNames[$name])) {
        $name = $base . '_' . ++$i;
    }

    return $name;
}
