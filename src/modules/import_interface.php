<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\Deriving\ClassDeriveFacts;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Registry\projectInstancesFromProgram;

/**
 * The facts this module imports, keyed by the names it uses.
 *
 * Contains the required imported ABI (type, symbol, ownership, callable and
 * action facts) and optional optimizer summaries. Required maps are always
 * present, even when empty.
 *
 * `callables` is the single source of truth for imported runtime layouts;
 * `externalFnRuntimeArity` is derived from it. Foreign declarations are
 * represented in the type facts and origins.
 *
 * Target-specific artifact paths, dependencies and namespace aliases belong
 * to `EmitPlan`, not this interface.
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
 * @param array<string, string> $workers imported action entries => the worker their declaring module
 *        publishes, so a run site calls it instead of building the action value and running it
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
        public readonly array $workers = [],
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
     * @param array{externalFns: array<string, string>, callables: array<string, array{evidence: int, params: int, arity: int}>, origins?: array<string, string>, actionReturn?: array<string, true>, workers?: array<string, string>} $refs
     * @param array<string, true> $localNames names the module binds itself
     */
    public function withClassScopeRefs(array $refs, array $localNames): self
    {
        $actionReturn = $this->actionReturn;
        $workers = $this->workers;
        foreach ($refs['actionReturn'] ?? [] as $name => $_) {
            if (! isset($localNames[$name])) {
                $actionReturn[$name] = true;
            }
        }
        foreach ($refs['workers'] ?? [] as $name => $symbol) {
            if (! isset($localNames[$name])) {
                $workers[$name] ??= $symbol;
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
            workers: $workers,
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
        ?array $workers = null,
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
            workers: $workers ?? $this->workers,
            trueBindings: $this->trueBindings,
            summaries: $this->summaries,
        );
    }
}

/**
 * Build the import context for a standalone module, treating it as its own
 * project with an empty import interface and target layout.
 */
function standaloneImportContext(Ast\Program $program, string $backendId): ImportContext
{
    $module = $program->module ?? '';
    $classDecls = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\ClassDecl) {
            $classDecls[] = $item;
        }
    }

    return new ImportContext(
        interface: ImportInterface::withoutImports($module),
        plan: EmitPlan::forTarget($backendId, ''),
        instanceWorld: InstanceWorld::over(
            projectInstancesFromProgram(
                $program,
                ClassDeriveFacts::fromClassDeclarations($classDecls),
                $module,
            ),
            [$module],
        ),
        currentModule: $module,
    );
}
