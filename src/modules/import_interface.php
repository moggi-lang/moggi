<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\Deriving\ClassDeriveFacts;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Registry\projectInstancesFromProgram;

/**
 * One imported spelling reached through a module prefix — `M.foo` for the `M` this module imports.
 *
 * A qualified answer belongs to the imported name it resolves, not to a map beside it: the same
 * spelling can be ambiguous unqualified (`foo` from two modules) while both `A.foo` and `B.foo` are
 * well defined, so a record states both and neither can outlive the other.
 */
final class ImportQualified
{
    /**
     * @param string $module the module this prefix names
     * @param ?Scheme $scheme the value's type, as that module publishes it
     * @param ?string $originModule the module that declares the entry, when it is not `$module`
     *        (a re-export); null when the prefix's own module declares it
     * @param ?string $originName the entry's spelling in the declaring module, beside `$originModule`
     * @param ?string $symbol the entry's resolved symbol (`Data.Map::lookup`): the spelling a call
     *        through this prefix names, which is not necessarily the unqualified spelling's entry
     * @param ?array{evidence: int, params: int, arity: int}|null $layout the entry's runtime layout,
     *        published beside the prefix because a qualified spelling can name a different entry
     *        than the same spelling imported unqualified
     */
    public function __construct(
        public readonly string $module,
        public readonly ?Scheme $scheme = null,
        public readonly ?string $originModule = null,
        public readonly ?string $originName = null,
        public readonly ?string $symbol = null,
        public readonly ?array $layout = null,
    ) {
    }
}

/**
 * One spelling this module imports, and every fact recorded under it.
 *
 * The payloads that used to be four tables — the value's scheme, a data declaration, a type synonym,
 * the callable's symbol/origin/layout — are facts about the *same* name, so they are one record. A
 * reader cannot see the scheme and miss the layout, a rename cannot land in one table and not the
 * others, and "what does this name mean here" is answered by one lookup.
 *
 * Every fact is nullable because a spelling carries only the facts that apply to it: a type has data
 * or a synonym and no callable, a class method has a scheme and no symbol, a constructor has all
 * three. `asCallable()` is the single place that decides "this is a call the module may make by
 * name", and it answers null both for a name with no symbol and for one that is ambiguous.
 */
/**
 * The callable facts one imported spelling publishes, in one record.
 *
 * Layout, action result and worker are three answers about the same entry, so they travel
 * together; every import-side consumer projects the field it needs from this table instead of
 * rebuilding a map of its own.
 */
final class ImportAbi
{
    /** @param array{evidence: int, params: int, arity: int} $layout */
    public function __construct(
        public readonly array $layout,
        public readonly bool $actionReturn,
        public readonly ?string $worker,
    ) {
    }
}

final class ImportSymbol
{
    /**
     * @param array{evidence: int, params: int, arity: int}|null $layout null until the entry's layout
     *        is published; a nullary callable's layout is a real record with zeros, not an absence
     * @param array<string, mixed>|null $data the imported data/newtype declaration, by type name
     * @param array<string, mixed>|null $typeSynonym the imported synonym's expansion
     * @param array<string, ImportQualified> $qualified the same name through each module prefix
     * @param list<string> $ambiguousOrigins the modules that export this spelling unqualified, when
     *        more than one does — the bare name is then not usable, and the qualified answers on the
     *        same record are what remains
     */
    public function __construct(
        public readonly ?string $symbol = null,
        public readonly ?string $originModule = null,
        public readonly ?array $layout = null,
        public readonly ?Scheme $scheme = null,
        public readonly ?array $data = null,
        public readonly ?array $typeSynonym = null,
        public readonly ?string $constructorName = null,
        public readonly ?string $intrinsicWrapper = null,
        public readonly array $qualified = [],
        public readonly array $ambiguousOrigins = [],
        public readonly bool $actionReturn = false,
        public readonly ?string $worker = null,
        /** @var array<string, mixed>|null the pattern synonym this spelling imports */
        public readonly ?array $patternSynonym = null,
        /** Whether only a module prefix may use this spelling's pattern synonym. */
        public readonly bool $patternSynonymQualifiedOnly = false,
    ) {
    }

    /** The callable facts this record publishes, or null when it names no callable. */
    public function abi(): ?ImportAbi
    {
        if ($this->layout === null) {
            return null;
        }

        return new ImportAbi($this->layout, $this->actionReturn, $this->worker);
    }

    /** Whether this spelling names a callable the module may call by this name. */
    public function isCallable(): bool
    {
        return $this->symbol !== null && $this->ambiguousOrigins === [];
    }

    /** This record as an imported callable, or null when the name is not one (or is ambiguous). */
    public function asCallable(): ?self
    {
        return $this->isCallable() ? $this : null;
    }

    /** The same spelling, with the callable facts the defining module published. */
    public function withSymbol(string $symbol, ?string $originModule, array $layout): self
    {
        return new self(
            symbol: $symbol,
            originModule: $originModule,
            layout: $layout,
            scheme: $this->scheme,
            data: $this->data,
            typeSynonym: $this->typeSynonym,
            constructorName: $this->constructorName,
            intrinsicWrapper: $this->intrinsicWrapper,
            qualified: $this->qualified,
            ambiguousOrigins: $this->ambiguousOrigins,
            actionReturn: $this->actionReturn,
            worker: $this->worker,
            patternSynonym: $this->patternSynonym,
            patternSynonymQualifiedOnly: $this->patternSynonymQualifiedOnly,
        );
    }

    /** The same import, with the declaring module filled in when it was unknown. */
    public function withOrigin(?string $originModule): self
    {
        return $originModule === null || $originModule === $this->originModule
            ? $this
            : $this->copy(originModule: $originModule);
    }

    /** The same import, with the runtime layout filled in when it was unpublished. */
    public function withLayout(?array $layout): self
    {
        return $layout === null || $this->layout !== null
            ? $this
            : $this->copy(layout: $layout);
    }

    /** The same name, whose type is the scheme the importing module's scope records. */
    public function withScheme(Scheme $scheme): self
    {
        return $this->copy(scheme: $scheme);
    }

    /** The same name, carrying the data/newtype declaration it imports. */
    public function withData(array $data): self
    {
        return $this->copy(data: $data);
    }

    /** The same name, carrying the synonym expansion it imports. */
    public function withTypeSynonym(array $typeSynonym): self
    {
        return $this->copy(typeSynonym: $typeSynonym);
    }

    /** The same constructor, which this module spells with a different name. */
    public function withConstructorName(string $constructorName): self
    {
        return $this->copy(constructorName: $constructorName);
    }

    /** The same primop spelling, which this module imports under a wrapper. */
    public function withIntrinsicWrapper(string $intrinsicWrapper): self
    {
        return $this->copy(intrinsicWrapper: $intrinsicWrapper);
    }

    /** The same spelling, which is also an imported pattern synonym. */
    public function withPatternSynonym(array $patternSynonym, bool $qualifiedOnly = false): self
    {
        return $this->patternSynonym !== null
            ? $this
            : $this->copy(patternSynonym: $patternSynonym, patternSynonymQualifiedOnly: $qualifiedOnly);
    }

    /** The same name, reached through one more module prefix. */
    public function withQualified(string $prefix, ImportQualified $entry): self
    {
        return $this->copy(qualified: [...$this->qualified, $prefix => $entry]);
    }

    /** The same name, which more than one module exports unqualified. */
    public function withAmbiguousOrigins(array $ambiguousOrigins): self
    {
        return $this->copy(ambiguousOrigins: $ambiguousOrigins);
    }

    /** The same entry, which answers with an action value. */
    public function withActionReturn(): self
    {
        return $this->actionReturn ? $this : $this->copy(actionReturn: true);
    }

    /** The same entry, which its declaring module lowers through a worker. */
    public function withWorker(string $worker): self
    {
        return $this->copy(worker: $worker);
    }

    private function copy(
        ?string $symbol = null,
        ?string $originModule = null,
        ?array $layout = null,
        ?Scheme $scheme = null,
        ?array $data = null,
        ?array $typeSynonym = null,
        ?string $constructorName = null,
        ?string $intrinsicWrapper = null,
        ?array $qualified = null,
        ?array $ambiguousOrigins = null,
        ?bool $actionReturn = null,
        ?string $worker = null,
        ?array $patternSynonym = null,
        ?bool $patternSynonymQualifiedOnly = null,
    ): self {
        return new self(
            symbol: $symbol ?? $this->symbol,
            originModule: $originModule ?? $this->originModule,
            layout: $layout ?? $this->layout,
            scheme: $scheme ?? $this->scheme,
            data: $data ?? $this->data,
            typeSynonym: $typeSynonym ?? $this->typeSynonym,
            constructorName: $constructorName ?? $this->constructorName,
            intrinsicWrapper: $intrinsicWrapper ?? $this->intrinsicWrapper,
            qualified: $qualified ?? $this->qualified,
            ambiguousOrigins: $ambiguousOrigins ?? $this->ambiguousOrigins,
            actionReturn: $actionReturn ?? $this->actionReturn,
            worker: $worker ?? $this->worker,
            patternSynonym: $patternSynonym ?? $this->patternSynonym,
            patternSynonymQualifiedOnly: $patternSynonymQualifiedOnly ?? $this->patternSynonymQualifiedOnly,
        );
    }
}

/**
 * The facts this module imports, keyed by the names it uses.
 *
 * One table: {@see $symbols} holds a record per imported spelling, and every payload the interface
 * used to keep beside it — the value environment, the data and synonym facts, the constructor
 * renames, the intrinsic wrappers, the callable layouts, the qualified answers, the ambiguity
 * reports, the action conventions and worker symbols — is a projection of those records, computed on
 * demand. There is no second place to write one of them, so two facts about one name cannot disagree.
 *
 * What is *not* here, deliberately: `qualifiedModules` is the prefix table `import M as N` declares
 * (prefix → module, not a name), `trueBindings` is keyed by `<Module>::<name>` because it is a fact
 * another module owns, and `summaries` is the optional optimizer region.
 *
 * Target-specific artifact paths, dependencies and namespace aliases belong to `EmitPlan`, not this
 * interface.
 */
final class ImportInterface
{
    /**
     * @param array<string, ImportSymbol> $symbols every spelling this module imports, by the spelling
     *        it uses — the one table all the projections below read
     * @param array<string, string> $qualifiedModules the module each import prefix names
     * @param array<string, true> $trueBindings imported bindings whose body is the host boolean `true`
     * @param array<string, mixed> $summaries optional optimizer region
     * @param array<string, int> $classScopeArity runtime arity of the class-scope references the
     *        module's inherited default bodies make, keyed by the symbol each one resolves to
     */
    public function __construct(
        public readonly string $module,
        public readonly array $symbols = [],
        public readonly array $qualifiedModules = [],
        public readonly array $trueBindings = [],
        public readonly array $summaries = [],
        public readonly array $classScopeArity = [],
        /** @var array<string, array<string, array<string, mixed>>> data declarations by import prefix */
        public readonly array $qualifiedData = [],
        /** @var array<string, array<string, array<string, mixed>>> type synonyms by import prefix */
        public readonly array $qualifiedTypeSynonyms = [],
    ) {
    }

    /** An interface for a unit that imports nothing — the synthetic compiler modules. */
    public static function withoutImports(string $module): self
    {
        return new self($module);
    }

    /** Every fact recorded for one imported spelling, or null when the module imports nothing by it. */
    public function record(string $name): ?ImportSymbol
    {
        return $this->symbols[$name] ?? null;
    }

    /** The imported callable this module calls by `$name`, if it imports one under that spelling. */
    public function symbol(string $name): ?ImportSymbol
    {
        return $this->record($name)?->asCallable();
    }

    /**
     * The pattern synonyms this module imports, by the spelling it uses.
     *
     * A synonym travels as a fact on the spelling's record, beside the scheme a
     * bidirectional synonym also has; an ambiguous spelling publishes none, the
     * same way it publishes no scheme.
     *
     * @return array<string, array<string, mixed>>
     */
    public function patternSynonyms(): array
    {
        $definitions = [];
        foreach ($this->symbols as $name => $imported) {
            if ($imported->patternSynonym !== null
                && ! $imported->patternSynonymQualifiedOnly
                && $imported->ambiguousOrigins === []
            ) {
                $definitions[$name] = $imported->patternSynonym;
            }
        }

        return $definitions;
    }

    /**
     * Every imported pattern synonym, including the ones only a module prefix may
     * use; the caller decides which prefix reaches each declaring module.
     *
     * @return array<string, array<string, mixed>>
     */
    public function qualifiedPatternSynonyms(): array
    {
        $definitions = [];
        foreach ($this->symbols as $name => $imported) {
            if ($imported->patternSynonym !== null) {
                $definitions[$name] = $imported->patternSynonym;
            }
        }

        return $definitions;
    }

    /**
     * Every imported callable: the name this module uses => the symbol its artifact calls.
     *
     * @return array<string, string>
     */
    public function externalFns(): array
    {
        $externalFns = [];
        foreach ($this->symbols as $name => $imported) {
            if ($imported->isCallable()) {
                $externalFns[$name] = $imported->symbol;
            }
        }

        return $externalFns;
    }

    /** The module that declares the callable this module imports under `$name`, if any. */
    public function fnOrigin(string $name): ?string
    {
        return $this->record($name)?->originModule;
    }

    /**
     * The runtime ABI of every imported entry, as one table.
     *
     * This is the import side's single derivation: layout, action result and worker are read
     * from each symbol once, here, and the projections below only select a field. A second map
     * built from the symbol fields would be a second answer.
     *
     * @return array<string, ImportAbi>
     */
    public function abi(): array
    {
        $abi = [];
        foreach ($this->symbols as $name => $imported) {
            $record = $imported->abi();
            if ($record !== null) {
                $abi[$name] = $record;
            }
        }

        return $abi;
    }

    /**
     * The runtime layout of every imported entry — a function, a data constructor or an evidence
     * factory — by the name this module calls it by.
     *
     * @return array<string, array{evidence: int, params: int, arity: int}>
     */
    public function callables(): array
    {
        $callables = [];
        foreach ($this->abi() as $name => $record) {
            $callables[$name] = $record->layout;
        }

        return $callables;
    }

    /**
     * The runtime arity of every imported entry, keyed by the name this module calls it by — projected
     * from the same records as {@see callables()}, so the published layouts and the arity check cannot
     * disagree.
     *
     * @return array<string, int>
     */
    public function externalFnRuntimeArity(): array
    {
        $arity = [];
        foreach ($this->abi() as $name => $record) {
            $arity[$name] = $record->layout['arity'];
        }

        return $arity;
    }

    /**
     * The runtime arity of every entry reached through a module prefix, keyed by resolved symbol.
     *
     * A qualified spelling can name a different entry than the same spelling imported unqualified —
     * `M.lookup` is `Data.Map::lookup` while a bare `lookup` may be `Data.List::lookup` — so the
     * qualified answer is published beside its own record, under the symbol the call names, instead
     * of under the bare spelling.
     *
     * @return array<string, int>
     */
    public function qualifiedFnRuntimeArity(): array
    {
        $arity = [];
        foreach ($this->symbols as $imported) {
            foreach ($imported->qualified as $entry) {
                if ($entry->symbol === null) {
                    continue;
                }

                $layout = $entry->layout
                    ?? ($entry->symbol === $imported->symbol ? $imported->layout : null);
                if ($layout === null) {
                    continue;
                }

                $arity[$entry->symbol] = $layout['arity'];
            }
        }

        return $arity;
    }

    /**
     * The runtime arity of every imported entry, by name. This is what the post-legalize layout check
     * reads, so the check sees every entry an artifact can call.
     *
     * @return array<string, int>
     */
    public function callableArity(): array
    {
        return $this->externalFnRuntimeArity();
    }

    /**
     * The type environment the imports add: one scheme per imported name.
     *
     * An ambiguous name keeps the scheme of the import that introduced it; the ambiguity itself is a
     * separate fact ({@see ambiguousNames()}) and the checker reports it before resolving a bare use,
     * which is why this table and {@see externalFns()} may hold different name sets for one spelling.
     *
     * @return array<string, Scheme>
     */
    public function env(): array
    {
        $env = [];
        foreach ($this->symbols as $name => $imported) {
            if ($imported->scheme !== null) {
                $env[$name] = $imported->scheme;
            }
        }

        return $env;
    }

    /** @return array<string, array<string, mixed>> */
    public function data(): array
    {
        $data = [];
        foreach ($this->symbols as $name => $imported) {
            if ($imported->data !== null) {
                $data[$name] = $imported->data;
            }
        }

        return $data;
    }

    /** @return array<string, array<string, array<string, mixed>>> data declarations by prefix */
    public function qualifiedData(): array
    {
        return $this->qualifiedData;
    }

    /** @return array<string, array<string, array<string, mixed>>> type synonyms by prefix */
    public function qualifiedTypeSynonyms(): array
    {
        return $this->qualifiedTypeSynonyms;
    }

    /** @return array<string, array<string, mixed>> */
    public function typeSynonyms(): array
    {
        $typeSynonyms = [];
        foreach ($this->symbols as $name => $imported) {
            if ($imported->typeSynonym !== null) {
                $typeSynonyms[$name] = $imported->typeSynonym;
            }
        }

        return $typeSynonyms;
    }

    /**
     * The constructors this module spells differently from the module that declares them:
     * the local spelling => the canonical one.
     *
     * @return array<string, string>
     */
    public function constructorRenames(): array
    {
        $renames = [];
        foreach ($this->symbols as $name => $imported) {
            if ($imported->constructorName !== null) {
                $renames[$name] = $imported->constructorName;
            }
        }

        return $renames;
    }

    /** @return array<string, string> the imported name => the primop wrapper it is imported as */
    public function intrinsicWrappers(): array
    {
        $wrappers = [];
        foreach ($this->symbols as $name => $imported) {
            if ($imported->intrinsicWrapper !== null) {
                $wrappers[$name] = $imported->intrinsicWrapper;
            }
        }

        return $wrappers;
    }

    /**
     * The spellings more than one import brings in unqualified, with the modules that export them.
     *
     * @return array<string, array{origins: list<string>}>
     */
    public function ambiguousNames(): array
    {
        $ambiguous = [];
        foreach ($this->symbols as $name => $imported) {
            if ($imported->ambiguousOrigins !== []) {
                $ambiguous[$name] = ['origins' => $imported->ambiguousOrigins];
            }
        }

        return $ambiguous;
    }

    /**
     * The type of every imported name, as the module prefix it is reached through publishes it.
     *
     * @return array<string, array<string, Scheme>>
     */
    public function qualifiedEnv(): array
    {
        $env = [];
        foreach ($this->symbols as $name => $imported) {
            foreach ($imported->qualified as $prefix => $entry) {
                if ($entry->scheme !== null) {
                    $env[$prefix][$name] = $entry->scheme;
                }
            }
        }

        return $env;
    }

    /**
     * The entries a module prefix reaches that its own module does not declare — the re-exports, whose
     * declaring module and spelling the qualified reference resolves through.
     *
     * @return array<string, array<string, array{module: string, phpName: string}>>
     */
    public function qualifiedOrigins(): array
    {
        $origins = [];
        foreach ($this->symbols as $name => $imported) {
            foreach ($imported->qualified as $prefix => $entry) {
                if ($entry->originModule !== null && $entry->originName !== null) {
                    $origins[$prefix][$name] = [
                        'module' => $entry->originModule,
                        'phpName' => $entry->originName,
                    ];
                }
            }
        }

        return $origins;
    }

    /** @return array<string, true> imported entries that answer with an action value */
    public function actionReturn(): array
    {
        $actionReturn = [];
        foreach ($this->abi() as $name => $record) {
            if ($record->actionReturn) {
                $actionReturn[$name] = true;
            }
        }

        return $actionReturn;
    }

    /**
     * The runtime arity of every class-scope reference, keyed by resolved symbol.
     *
     * A default body is re-checked in the module that inherits it, so it can call an entry the
     * importing module also binds — and then the bare spelling belongs to the local binding and
     * nothing is published under it. The reference still names the declaring module's entry, so the
     * arity is kept under the symbol the call resolves to and lowering can tell a direct call from
     * an application of the value.
     *
     * @return array<string, int>
     */
    public function classScopeFnRuntimeArity(): array
    {
        return $this->classScopeArity;
    }

    /** @return array<string, string> imported entry => the worker its declaring module lowers it through */
    public function workers(): array
    {
        $workers = [];
        foreach ($this->abi() as $name => $record) {
            if ($record->worker !== null) {
                $workers[$name] = $record->worker;
            }
        }

        return $workers;
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
        $symbols = $this->symbols;
        $scopeArity = $this->classScopeArity;
        foreach ($refs['externalFns'] as $name => $symbol) {
            $layout = $refs['callables'][$name] ?? null;
            if (! \is_string($symbol) || $symbol === '' || $layout === null) {
                continue;
            }

            $scopeArity[$symbol] ??= $layout['arity'];
        }
        $at = static function (string $name) use (&$symbols): ImportSymbol {
            return $symbols[$name] ?? new ImportSymbol();
        };

        foreach ($refs['actionReturn'] ?? [] as $name => $_) {
            if (! isset($localNames[$name])) {
                $symbols[$name] = $at($name)->withActionReturn();
            }
        }
        foreach ($refs['workers'] ?? [] as $name => $symbol) {
            if (! isset($localNames[$name]) && $at($name)->worker === null) {
                $symbols[$name] = $at($name)->withWorker($symbol);
            }
        }

        foreach ($refs['externalFns'] as $name => $symbol) {
            if (isset($localNames[$name])) {
                continue;
            }

            $origin = $refs['origins'][$name] ?? null;
            $originModule = \is_string($origin) && $origin !== '' && $origin !== $this->module
                ? $origin
                : null;

            $existing = $symbols[$name] ?? null;
            $layout = $existing?->layout;
            if ($layout === null) {
                $layout = $refs['callables'][$name] ?? throw new \LogicException(
                    "class-scope reference `{$name}` has no published callable layout",
                );
            }

            $symbols[$name] = ($existing ?? new ImportSymbol(symbol: $symbol))
                ->withOrigin($originModule)
                ->withLayout($layout);
        }

        return $this->withSymbols($symbols, $scopeArity);
    }

    /**
     * Add the facade's own types to the interface. A facade publishes the types
     * of the module it stands for, and its importers must see them.
     *
     * @param array<string, array<string, array<string, mixed>>> $extra keyed by fact (`data`, `typeSynonyms`)
     */
    public function withExtraTypes(array $extra): self
    {
        $symbols = $this->symbols;
        foreach ($extra['data'] ?? [] as $name => $info) {
            $existing = $symbols[$name] ?? null;
            if ($existing?->data === null) {
                $symbols[$name] = ($existing ?? new ImportSymbol())->withData($info);
            }
        }

        foreach ($extra['typeSynonyms'] ?? [] as $name => $type) {
            $existing = $symbols[$name] ?? null;
            if ($existing?->typeSynonym === null) {
                $symbols[$name] = ($existing ?? new ImportSymbol())->withTypeSynonym($type);
            }
        }

        return $this->withSymbols($symbols);
    }

    /** Copy with the one table replaced; every other region is carried unchanged. */
    private function withSymbols(array $symbols, ?array $classScopeArity = null): self
    {
        return new self(
            module: $this->module,
            symbols: $symbols,
            qualifiedModules: $this->qualifiedModules,
            trueBindings: $this->trueBindings,
            summaries: $this->summaries,
            classScopeArity: $classScopeArity ?? $this->classScopeArity,
            qualifiedData: $this->qualifiedData,
            qualifiedTypeSynonyms: $this->qualifiedTypeSynonyms,
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
    $dataDecls = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\ClassDecl) {
            $classDecls[] = $item;
        }
        if ($item instanceof Ast\DataDecl) {
            $dataDecls[$item->name] ??= $item;
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
                $dataDecls,
            ),
            [$module],
        ),
        currentModule: $module,
    );
}
