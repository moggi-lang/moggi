<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\TypeExpr\Scheme;

/**
 * Everything one module publishes to the compilation, in one value (D08 / A8).
 *
 * Two regions, and nothing else:
 *
 *  - the **required ABI**: identity (`module`), the type facts an importer
 *    installs (`env`, `data`, `typeSynonyms`, `qualifiedModules`,
 *    `qualifiedOrigins`, `qualifiedEnv`, `intrinsicWrappers`, `ambiguousNames`),
 *    kind/constructor identity (`constructorRenames`), the symbol and ownership
 *    facts (`externalFns`, `fnOriginModules`), every imported entry's runtime
 *    arity (`externalFnRuntimeArity`), the published
 *    callable layouts (`callables`, whose arity `callableArity()` projects), the
 *    current action convention (`actionReturn`) and the imported `true` bindings
 *    (`trueBindings`). Every one of these is
 *    mandatory: an unset map is an empty map, never an absent one.
 *  - `summaries`: the optimizer's optional region. It starts empty and may stay
 *    empty; a missing summary may only cost optimization, never a correct
 *    program.
 *
 * Declared foreign ABI is required, and already lives in the type facts: a
 * foreign function's scheme is in `env`, a foreign type's record is in `data`
 * (`foreign`, `owner`), and an entry's declaring module is `fnOriginModules`.
 *
 * Target rendering is deliberately *not* here: where a module's artifact sits,
 * what a module requires and which alias its namespace was bound to are the
 * target's facts, not the module's. {@see EmitPlan} renders them from this value
 * at emission, and the emitters read that plan; no consumer of this class needs
 * a second copy of any region above.
 */
final class ModuleInterface
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
     * @param array<string, int> $externalFnRuntimeArity
     * @param array<string, array{arity: int, evidence?: int, params?: int}> $callables
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
        public readonly array $externalFnRuntimeArity = [],
        public readonly array $callables = [],
        public readonly array $actionReturn = [],
        public readonly array $trueBindings = [],
        public readonly array $summaries = [],
    ) {
    }

    /** An interface for a unit that imports nothing — the synthetic compiler modules. */
    public static function withoutImports(string $module): self
    {
        return new self($module);
    }

    /**
     * The runtime arity of every imported *function* entry, keyed by the name this
     * module calls it by. Narrower than `externalFnRuntimeArity`, which also carries
     * the instance-evidence builders: those are not exported entries and their
     * argument list is an evidence slice, not a callable layout.
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
     * @param array{externalFns: array<string, string>, arity: array<string, int>, origins?: array<string, string>, actionReturn?: array<string, true>} $refs
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
        $externalFnRuntimeArity = $this->externalFnRuntimeArity;
        $fnOriginModules = $this->fnOriginModules;
        foreach ($refs['externalFns'] as $name => $symbol) {
            if (isset($localNames[$name])) {
                continue;
            }

            $externalFns[$name] ??= $symbol;
            $externalFnRuntimeArity[$name] ??= $refs['arity'][$name] ?? 0;
            $originModule = $refs['origins'][$name] ?? null;
            if (! \is_string($originModule) || $originModule === '' || $originModule === $this->module) {
                continue;
            }

            $fnOriginModules[$name] ??= $originModule;
        }

        return $this->with(
            externalFns: $externalFns,
            externalFnRuntimeArity: $externalFnRuntimeArity,
            fnOriginModules: $fnOriginModules,
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
        ?array $externalFnRuntimeArity = null,
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
            externalFnRuntimeArity: $externalFnRuntimeArity ?? $this->externalFnRuntimeArity,
            callables: $this->callables,
            actionReturn: $actionReturn ?? $this->actionReturn,
            trueBindings: $this->trueBindings,
            summaries: $this->summaries,
        );
    }
}

/**
 * The import context of a module that imports nothing: one source checked on its
 * own. The interface is empty, not absent, so no consumer needs a null check,
 * and the target layout is empty because there is nothing to place.
 *
 * @return array{interface: ModuleInterface, plan: EmitPlan}
 */
function standaloneImportContext(string $module, string $backendId): array
{
    return [
        'interface' => ModuleInterface::withoutImports($module),
        'plan' => EmitPlan::forTarget($backendId, ''),
    ];
}
