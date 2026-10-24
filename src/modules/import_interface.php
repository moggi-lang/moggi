<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\TypeExpr\Scheme;

/**
 * The facts one module *imports*, in one value.
 *
 * The name says what the value is: it is the view a module has of the modules it
 * imports, named after the importing module. It is **not** the module's own
 * published interface — what a module exports is `PreparedProject`'s
 * `units[...]['localTypes']` and the `facts` built from the check
 * (`facts.callables`, `facts.evidence`, …). A consumer that needs the
 * published side must read those, not this value.
 *
 * Two regions, and nothing else:
 *
 *  - the **required ABI**: identity (`module`), the type facts an importer
 *    installs (`env`, `data`, `typeSynonyms`, `qualifiedModules`,
 *    `qualifiedOrigins`, `qualifiedEnv`, `intrinsicWrappers`, `ambiguousNames`),
 *    kind/constructor identity (`constructorRenames`), the symbol and ownership
 *    facts (`externalFns`, `fnOriginModules`), the published
 *    callable layouts (`callables`), the current action convention (`actionReturn`)
 *    and the imported `true` bindings (`trueBindings`). Every one of these is
 *    mandatory: an unset map is an empty map, never an absent one.
 *
 *    `callables` is the one home for an imported entry's runtime vector: a
 *    function, a data constructor and an evidence factory each publish their
 *    layout there, and `externalFnRuntimeArity` is projected from it in the
 *    constructor, so no consumer can see an arity the list does not carry.
 *  - `summaries`: the optimizer's optional region. It starts empty and may stay
 *    empty; a missing summary may only cost optimization, never a correct
 *    program.
 *
 * Declared foreign ABI is required, and already lives in the type facts: a
 * foreign function's scheme is in `env`, a foreign type's record is in `data`
 * (`foreign`, `owner`), and an entry's declaring module is `fnOriginModules`.
 * Every map is the importer's: names are the spellings the importing module
 * uses, resolved symbols carry the declaring module.
 *
 * Target rendering is deliberately *not* here: where a module's artifact sits,
 * what a module requires and which alias its namespace was bound to are the
 * target's facts, not the module's. {@see EmitPlan} renders them from this value
 * at emission, and the emitters read that plan; no consumer of this class needs
 * a second copy of any region above.
 */
final class ImportInterface
{
    /**
     * @param array<string, Scheme> $env
     * @param array<string, array<string, mixed>> $data
     * @param array<string, array<string, mixed>> $typeSynonyms
     * @param array<string, string> $qualifiedModules
     * @param array<string, array<string, mixed>> $qualifiedOrigins
     * @param array<string, array<string, Scheme>> $qualifiedEnv
     * @param array<string, string> $intrinsicWrappers
     * @param array<string, array{origins: list<string>}> $ambiguousNames
     * @param array<string, string> $constructorRenames
     * @param array<string, string> $externalFns
     * @param array<string, string> $fnOriginModules
     * @param array<string, array{evidence: int, params: int, arity: int}> $callables
     * @param array<string, true> $actionReturn
     * @param array<string, true> $trueBindings imported bindings whose body is the host boolean `true`
     * @param array<string, mixed> $summaries optional optimizer region
     */
    public function __construct(
        public readonly string $module,
        public readonly array $env = [],
        public readonly array $data = [],
        public readonly array $typeSynonyms = [],
        public readonly array $qualifiedModules = [],
        public readonly array $qualifiedOrigins = [],
        public readonly array $qualifiedEnv = [],
        public readonly array $intrinsicWrappers = [],
        public readonly array $ambiguousNames = [],
        public readonly array $constructorRenames = [],
        public readonly array $externalFns = [],
        public readonly array $fnOriginModules = [],
        public readonly array $callables = [],
        public readonly array $actionReturn = [],
        public readonly array $trueBindings = [],
        public readonly array $summaries = [],
    ) {
        $arity = [];
        foreach ($callables as $name => $entry) {
            $arity[$name] = $entry['arity'];
        }
        $this->externalFnRuntimeArity = $arity;
    }

    /**
     * The runtime vector of every imported entry, keyed by the name this module calls it by —
     * projected from `callables`, so it cannot disagree with the published layouts.
     *
     * @var array<string, int>
     */
    public readonly array $externalFnRuntimeArity;

    /** An interface for a unit that imports nothing — the synthetic compiler modules. */
    public static function withoutImports(string $module): self
    {
        return new self($module);
    }

    /**
     * The runtime arity of every imported entry — a function, a data constructor or
     * an evidence factory — keyed by the name this module calls it by. This is what
     * the post-legalize layout check reads, so the check sees every entry an
     * artifact can call.
     *
     * @return array<string, int>
     */
    public function callableArity(): array
    {
        return \array_map(static fn (array $entry): int => $entry['arity'], $this->callables);
    }

    /**
     * Fold the class-scope references a module needs for the default bodies it
     * re-checks into the interface, without overwriting anything the imports
     * already published.
     *
     * The declaring module of each reference is published like any other origin;
     * where that module's artifact sits is target planning, so no path is
     * derived here.
     *
     * @param array{externalFns: array<string, string>, callables: array<string, array{evidence: int, params: int, arity: int}>, origins?: array<string, string>, actionReturn?: array<string, true>} $refs
     * @param array<string, true> $localNames names the module binds itself
     */
    public function withClassScopeRefs(array $refs, array $localNames): self
    {
        $actionReturn = $this->actionReturn;
        foreach ($refs['actionReturn'] ?? [] as $name => $_) {
            if (! isset($localNames[$name])) {
                $actionReturn[$name] = true;
            }
        }

        $externalFns = $this->externalFns;
        $callables = $this->callables;
        $fnOriginModules = $this->fnOriginModules;
        foreach ($refs['externalFns'] as $name => $symbol) {
            if (isset($localNames[$name])) {
                continue;
            }

            $externalFns[$name] ??= $symbol;
            if (! isset($callables[$name])) {
                if (! isset($refs['callables'][$name])) {
                    throw new \LogicException(
                        "class-scope reference `{$name}` has no published callable layout",
                    );
                }
                $callables[$name] = $refs['callables'][$name];
            }
            $originModule = $refs['origins'][$name] ?? null;
            if (! \is_string($originModule) || $originModule === '' || $originModule === $this->module) {
                continue;
            }

            $fnOriginModules[$name] ??= $originModule;
        }

        return $this->with(
            externalFns: $externalFns,
            fnOriginModules: $fnOriginModules,
            callables: $callables,
            actionReturn: $actionReturn,
        );
    }

    /**
     * Add the facade's own types to the interface. A facade publishes the types
     * of the module it stands for, and its importers must see them.
     *
     * @param array<string, array<string, array<string, mixed>>> $extra keyed by fact (`data`, `typeSynonyms`)
     */
    public function withExtraTypes(array $extra): self
    {
        $data = $this->data;
        foreach ($extra['data'] ?? [] as $name => $info) {
            $data[$name] ??= $info;
        }

        $typeSynonyms = $this->typeSynonyms;
        foreach ($extra['typeSynonyms'] ?? [] as $name => $type) {
            $typeSynonyms[$name] ??= $type;
        }

        return $this->with(data: $data, typeSynonyms: $typeSynonyms);
    }

    /** Copy with the named regions replaced; every other region is carried unchanged. */
    private function with(
        ?array $data = null,
        ?array $typeSynonyms = null,
        ?array $externalFns = null,
        ?array $fnOriginModules = null,
        ?array $callables = null,
        ?array $actionReturn = null,
    ): self {
        return new self(
            module: $this->module,
            env: $this->env,
            data: $data ?? $this->data,
            typeSynonyms: $typeSynonyms ?? $this->typeSynonyms,
            qualifiedModules: $this->qualifiedModules,
            qualifiedOrigins: $this->qualifiedOrigins,
            qualifiedEnv: $this->qualifiedEnv,
            intrinsicWrappers: $this->intrinsicWrappers,
            ambiguousNames: $this->ambiguousNames,
            constructorRenames: $this->constructorRenames,
            externalFns: $externalFns ?? $this->externalFns,
            fnOriginModules: $fnOriginModules ?? $this->fnOriginModules,
            callables: $callables ?? $this->callables,
            actionReturn: $actionReturn ?? $this->actionReturn,
            trueBindings: $this->trueBindings,
            summaries: $this->summaries,
        );
    }
}

/**
 * The import context of a module that imports nothing: one source checked on its
 * own. The import interface is empty, not absent, so no consumer needs a null check,
 * and the target layout is empty because there is nothing to place.
 */
function standaloneImportContext(string $module, string $backendId): ImportContext
{
    return new ImportContext(
        interface: ImportInterface::withoutImports($module),
        plan: EmitPlan::forTarget($backendId, ''),
    );
}
