<?php declare(strict_types=1);

namespace Moggi\Pipeline;

use Moggi\Backend\Backend;
use Moggi\IR\EntryPoint;
use Moggi\IR\Module as IrModule;
use Moggi\Modules\ImportContext;
use Moggi\Syntax\Ast\Program;

use function Moggi\Backend\backendById;
use function Moggi\Modules\hostLibraryCacheMaterial;

/**
 * Why this compilation is happening. Controls entry validation, tree-shake
 * roots, and backend bootstrap — not which backend emits.
 */
enum CompilePurpose: string
{
    case Executable = 'executable';
    case Library = 'library';
    case Repl = 'repl';
}

/** How far Pipeline::run should go before returning artifacts. */
enum PipelineStage: string
{
    case Ast = 'ast';
    case TypedAst = 'typed-ast';
    case Ir = 'ir';
    case IrOpt = 'opt-ir';
    case Emit = 'emit';
}

/**
 * The output modes that print a stage, in pipeline order: the lexer's `tokens` and every stage
 * before codegen, each named by its own value.
 *
 * The CLI's print-mode flags and the modes the compiler entry points accept are this same list,
 * so `--opt-ir` and what it hands the pipeline cannot be spelled differently. Codegen is not an
 * output mode: compiling without one already writes the artifact.
 *
 * @return list<string>
 */
function printModeNames(): array
{
    $names = ['tokens'];
    foreach (PipelineStage::cases() as $stage) {
        if ($stage !== PipelineStage::Emit) {
            $names[] = $stage->value;
        }
    }

    return $names;
}

/**
 * The declared inputs one compilation runs with.
 *
 * Target, purpose, entries, roots and sources are named here, once, by the
 * entry point that knows them. Downstream code reads them from the context
 * instead of from process state, so two compilations in one process cannot
 * disagree about what they are compiling or for which backend. `backendById`
 * is a pure registry lookup; there is no process-wide selection to consult.
 */
final class CompilationContext
{
    /**
     * The backend this compilation emits for, derived from {@see $backendId}.
     *
     * There is deliberately no constructor parameter for it: the id is the single source of truth,
     * so a context cannot be built whose id and instance disagree.
     */
    public readonly Backend $backend;

    /**
     * @param array<string, true> $entries module names treated as entry points
     * @param list<string> $roots module search roots, in search order
     * @param list<string> $sources source paths in the compilation
     * @param string $host the host trees this compilation links against, as
     *   {@see \Moggi\Modules\hostLibraryCacheMaterial} spells them. Held rather than read from the
     *   registry at key time, which can be reset between construction and lookup.
     */
    public function __construct(
        public readonly string $backendId,
        public readonly CompilePurpose $purpose,
        public readonly array $entries,
        public readonly array $roots,
        public readonly array $sources,
        public readonly string $host = '',
    ) {
        $this->backend = backendById($backendId);
    }

    /**
     * @param list<string> $entryModules
     * @param list<string> $roots
     * @param list<string> $sources
     */
    public static function for(
        string $backendId,
        CompilePurpose $purpose,
        array $entryModules = [],
        array $roots = [],
        array $sources = [],
    ): self {
        return new self(
            $backendId,
            $purpose,
            \array_fill_keys($entryModules, true),
            \array_values($roots),
            \array_values($sources),
            hostLibraryCacheMaterial(),
        );
    }

    /**
     * The purpose a module is compiled with under this context.
     *
     * Only an entry module is compiled *as* the compilation: `main` is promoted
     * only under `Executable`, and `Library`/`Repl` promote nothing, so a
     * module's typed product depends on whether it is the entry, not on why the
     * compilation that reached it runs. Everything else is a library module
     * whichever entry point asked for it, which is what lets one compilation
     * answer another's request for the same module
     * ({@see moduleCacheMaterial}).
     */
    public function purposeFor(?string $module): CompilePurpose
    {
        if ($this->isEntry($module)) {
            return $this->purpose;
        }

        return CompilePurpose::Library;
    }

    public function isEntry(?string $module): bool
    {
        return $module !== null && isset($this->entries[$module]);
    }

    /** @param list<string> $sources */
    public function withSources(array $sources): self
    {
        return new self(
            $this->backendId,
            $this->purpose,
            $this->entries,
            $this->roots,
            \array_values($sources),
            $this->host,
        );
    }

    /**
     * The inputs a cache entry depends on, in one stable spelling.
     *
     * `host` is there because host trees are registered outside `roots`; without it a compile that
     * carries a host tree and one that does not would key the same and share products.
     */
    public function cacheKeyMaterial(): string
    {
        $entries = \array_keys($this->entries);
        \sort($entries);
        $sources = $this->sources;
        \sort($sources);

        return \implode("\n", [
            'backend=' . $this->backendId,
            'purpose=' . $this->purpose->value,
            'entries=' . \implode(',', $entries),
            'roots=' . \implode(',', $this->roots),
            'sources=' . \implode(',', $sources),
            'host=' . $this->host,
        ]);
    }

    public function cacheKey(): string
    {
        return \hash('xxh128', $this->cacheKeyMaterial());
    }

    /**
     * The context inputs a project-level memo depends on.
     *
     * `sources` is deliberately absent: the module set a prepared project
     * covers is already part of the memo key (`checkedModuleCacheKey` of every
     * path), so folding the source list in as well would give every file in a
     * process a private memo — no compile could ever reuse the closure another
     * compile prepared.
     */
    public function inputsKey(): string
    {
        $entries = \array_keys($this->entries);
        \sort($entries);

        return \hash('xxh128', \implode("\n", [
            'backend=' . $this->backendId,
            'purpose=' . $this->purpose->value,
            'entries=' . \implode(',', $entries),
            'roots=' . \implode(',', $this->roots),
        ]));
    }

    /**
     * The context inputs a single module's cache entry depends on.
     *
     * Sources are deliberately absent: a module's content key is already a
     * Merkle over its own and its dependencies' sources, so folding the whole
     * source list in would invalidate every module on any edit.
     *
     * Roots are absent for the same reason. A root list is an input to import
     * resolution, not to a module's product: the content key is the Merkle over
     * the closure resolution produced, so two compilations whose roots supply a
     * different `Dep` already disagree on it. Folding the caller's roots in
     * instead gives every compilation that is not the library build itself —
     * each test case, each REPL session, the language server — a private key for
     * every standard-library module, so the products one build wrote are never
     * found again: the shared library cache becomes write-only, and `moduleGet`
     * deletes the entry it found under the other key.
     */
    public function moduleCacheMaterial(?string $module): string
    {
        return \implode(';', [
            'backend=' . $this->backendId,
            'purpose=' . $this->purposeFor($module)->value,
        ]);
    }

    /**
     * The declared cache key for one module product.
     *
     * The content key is a Merkle over the module's own and its dependencies'
     * sources; the context material adds the target, the purpose the module is
     * compiled with and the roots its imports were resolved against. Both are
     * required, which is why this takes the content key rather than defaulting
     * it: a product addressed by source alone would let one compilation's
     * result answer another's request.
     */
    public function moduleKey(?string $module, string $contentKey): string
    {
        return \hash('xxh128', $contentKey . "\n" . $this->moduleCacheMaterial($module));
    }

    /**
     * The key for a module product whose content the carried host tree decides.
     *
     * Two products are: the checked program, because a host declaration is verified against the tree,
     * and the IR, because the `nativeSig` it embeds is resolved from that tree (javap for jvm, the
     * sidecars and CLR inspector for dotnet). A module with no host declaration keeps the shared
     * {@see moduleKey}, as do the export products, which carry neither.
     */
    public function moduleProductKey(?string $module, string $contentKey, bool $declaresForeign): string
    {
        $key = $this->moduleKey($module, $contentKey);
        if (! $declaresForeign) {
            return $key;
        }

        return \hash('xxh128', $key . "\nhost=" . $this->host);
    }
}

/**
 * Inputs for a single-module (or standalone) pipeline run.
 *
 * When `$typedProgram` is set (prepared-project path), parse and typecheck are
 * skipped and lowering starts from that typed AST.
 *
 * There is deliberately no `purpose` here: the context owns it, derived from
 * the module the request names ({@see CompilationContext::purposeFor}). A
 * request that could carry a second opinion about why it is compiling is a
 * request that can disagree with the key its products are cached under.
 *
 * @param array<string, mixed> $emitOptions
 */
final class PipelineRequest
{
    public function __construct(
        public CompilationContext $context,
        public string $source = '',
        public string $filename = '',
        public ?Program $program = null,
        public ?Program $typedProgram = null,
        public bool $optimize = true,
        /** Null means the module imports nothing; `run` supplies that context. */
        public ?ImportContext $importContext = null,
        public array $emitOptions = [],
    ) {
    }
}

/**
 * Stage artifacts produced by Pipeline::run. Null means the stage was not reached.
 *
 * @param array<string, string>|null $emit
 */
final class PipelineArtifacts
{
    public function __construct(
        public ?Program $ast = null,
        public ?Program $typedAst = null,
        public ?IrModule $ir = null,
        public ?IrModule $irOpt = null,
        public ?array $emit = null,
        public ?EntryPoint $entry = null,
        public CompilePurpose $purpose = CompilePurpose::Executable,
    ) {
    }
}
