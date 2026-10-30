<?php declare(strict_types=1);

namespace Moggi\Optimize\Facts;

use Moggi\IR;
use Moggi\IR\Callables\CallableTable;
use Moggi\Optimize\CaseFold\CtorTree;

use function Moggi\IR\Projection\leafName;

/**
 * What one optimize run knows about the program it is rewriting, in one place.
 *
 * Facts that used to ride in session globals and per-file static state with twenty-two setters and
 * getters between them: the evidence map, each module's functions, each module's imported functions,
 * the nullary constructor-carrying functions case-fold unfolds, the fusion index, the inline pass's
 * callable table and rebinding tables. They are all derived from the same module set, so one object holds
 * them, one install publishes them, and a reader asks for the fact it needs.
 *
 * The object is ambient because the passes that read it sit at the bottom of rewrite recursion that
 * carries no context of its own; the size of that ambient surface is this one object, not four keys.
 *
 * Name identity, where the maps differ and why: the boolean facts ({@see runsOnReference()}) are keyed by
 * leaf on both sides, so any spelling of an operand answers and a leaf collision over-answers — which only
 * costs a binding. A map that carries a *payload* cannot do that: {@see nullaryCtor()} is published under
 * every spelling its callers use, because a leaf collision there would hand back another module's tree.
 * The evidence map is keyed by the name the table declares, and a qualified lookup falls back to the leaf.
 */
final class Facts
{
    private static ?self $current = null;

    /**
     * Incremented every time the published function/evidence index is replaced.
     *
     * A reader that captured this number before a rewrite can refuse to read the
     * facts afterwards, so a fact set built for the old body cannot answer for the
     * new one. The map is a fact of the *program*, and the program changes under a
     * run; this is the revision that makes that visible instead of conventional.
     */
    private int $functionsRevision = 0;

    /**
     * @param array<string, array{module: string, methods: array<string, string>}> $evidence
     * @param array<string, array<string, mixed>> $fnsByModule
     * @param array<string, array<string, string>> $externalFnsByModule
     * @param array<string, CtorTree> $nullaryCtorCafs
     */
    public function __construct(
        public array $evidence = [],
        public array $fnsByModule = [],
        public readonly array $externalFnsByModule = [],
        public array $nullaryCtorCafs = [],
        public ?CallableTable $callableTable = null,
        /** @var ?array<string, list<string>> the locals a reference to each lifted callable keeps alive */
        public ?array $liftedCaptureNeeds = null,
        public array $fusionIndex = [],
        public array $inlineProtectedNames = [],
        public array $inlineTargetBoundNames = [],
        /** @var array<string, true> the entries a reference runs instead of closing over */
        public array $nullary = [],
    ) {
    }

    public static function install(self $facts): void
    {
        self::$current = $facts;
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    /**
     * The owner the current run installed, or null when none is.
     *
     * `optimize()` installs one for the module set it is rewriting and clears it afterwards, and
     * `specializeAcrossModules()` installs the whole-program owner for its own duration; a run that
     * nests inside either reuses the owner that is already there. Nothing creates one implicitly: a
     * pass that reads facts without an owner is a lifecycle bug, not a program with no facts.
     */
    public static function installed(): ?self
    {
        return self::$current;
    }

    public static function current(): self
    {
        return self::$current ?? throw new \LogicException(
            'no optimizer facts owner is installed; the run that calls this pass must install one',
        );
    }

    /** @param array<string, CtorTree> $cafs */
    public function publishNullaryCtorCafs(array $cafs): void
    {
        $this->nullaryCtorCafs = $cafs;
    }

    /**
     * This round's evidence and function maps. Published onto the installed facts rather than
     * installed as a new owner, so the facts collected from the module set (the nullary constructor
     * trees) survive every round.
     *
     * @param array<string, array{module: string, methods: array<string, string>}> $evidence
     * @param array<string, array<string, mixed>> $fnsByModule
     */
    public function publishIndex(array $evidence, array $fnsByModule): void
    {
        $this->evidence = $evidence;
        $this->fnsByModule = $fnsByModule;
        ++$this->functionsRevision;
    }

    /** @param array<string, array<string, mixed>> $fnsByModule */
    public function publishFnsByModule(array $fnsByModule): void
    {
        $this->fnsByModule = $fnsByModule;
        ++$this->functionsRevision;
    }

    /** The revision of the published function/evidence index this owner answers for. */
    public function functionsRevision(): int
    {
        return $this->functionsRevision;
    }

    /**
     * The callable table of the module being inlined, and the fusion index built from the module set.
     *
     * Both are per-run facts about the program, so they live here rather than in a session global; publishing
     * nothing is how a pass drops one when it is done with it. The table is the publication every capture
     * question is answered from, so no pass keeps a second capture map of its own.
     */
    public function publishCallableTable(?CallableTable $table): void
    {
        $this->callableTable = $table;
    }

    /** @param array<string, list<string>> $needs */
    public function publishLiftedCaptureNeeds(array $needs): void
    {
        $this->liftedCaptureNeeds = $needs;
    }

    /** @param array<string, IR\FunctionDecl> $index */
    public function publishFusionIndex(array $index): void
    {
        $this->fusionIndex = $index;
    }

    /**
     * What the inline pass running right now has to avoid rebinding: the names the function being
     * inlined into reads or binds, and the names each candidate body binds itself. Publishing an empty
     * array is how the pass drops one when it moves on.
     *
     * @param array<string, true> $names
     */
    public function publishInlineProtected(array $names): void
    {
        $this->inlineProtectedNames = $names;
    }

    /** @param array<string, list<string>> $byName */
    public function publishInlineTargetBound(array $byName): void
    {
        $this->inlineTargetBoundNames = $byName;
    }

    /**
     * Is this name an instance-evidence table the program declares?
     *
     * A dictionary travels as a value, so a callee may name an evidence function by its bare or its qualified
     * spelling; both answer from the evidence map, never from the `__ev_` a generated name happens to carry.
     */
    public function isEvidence(string $name): bool
    {
        return $this->evidenceFor($name) !== null;
    }

    /**
     * What an instance-evidence table declares: its module and its method table, by bare or qualified
     * name; null when the program declares no such table. The one lookup every question about a
     * dictionary's identity and its methods answers from.
     *
     * @return array{module: string, methods: array<string, string>}|null
     */
    public function evidenceFor(string $name): ?array
    {
        $found = $this->evidence[$name] ?? $this->evidence[leafName($name)] ?? null;

        return \is_array($found) ? $found : null;
    }

    /**
     * The instance-evidence tables one module declares.
     *
     * A table reaches its consumer as a value, so a callee may name it; the module that declares it is
     * where that fact becomes known. Publishing it here is what makes the owner answer the same question in
     * a single-module run as it does once the whole-program pass has published every module's.
     *
     * @param list<IR\InstanceEvidence> $tables
     */
    public function publishModuleEvidence(string $moduleName, array $tables): void
    {
        foreach ($tables as $table) {
            $this->evidence[$table->evidenceName] = [
                'module' => $moduleName,
                'methods' => $table->methods,
            ];
        }
    }


    /**
     * The constructor tree a nullary function's name stands for, or null when it is not one.
     *
     * A CAF is reached as an `FnRef`, so the tree has to be known by name; an ordinary declaration that
     * happens to produce a constructor is in the map only when the collector recognised its body.
     */
    public function nullaryCtor(string $name): ?CtorTree
    {
        $tree = $this->nullaryCtorCafs[$name] ?? null;

        return $tree instanceof CtorTree ? $tree : null;
    }

    /**
     * Whether naming this entry runs it, because it takes no parameters.
     *
     * A function of no parameters is a CAF: the IR reaches it as a bare `FnRef` and the backends force
     * that reference into a call, so two references run the body twice — which for an allocating host
     * call means two objects. A reference to a function that *does* take parameters is a closure value
     * and may be shared.
     *
     * Keyed by leaf name, like {@see evidenceFor()}: an operand may name an entry bare or qualified and
     * both spellings are the same entry. A leaf shared by two modules answers *true*, which binds a value
     * to a temp; that is the safe direction, because the alternative answer is the duplicate work this
     * fact exists to prevent.
     */
    public function runsOnReference(string $name): bool
    {
        return isset($this->nullary[leafName($name)]);
    }

    /**
     * @param list<IR\FunctionDecl> $functions the entries this run knows, merged into what it already had
     *
     * Merging is what makes a nested run safe: the whole-program owner publishes every module's entries,
     * and a per-module run inside it adds its own rather than narrowing the owner to one module.
     */
    public function publishFunctions(array $functions): void
    {
        foreach ($functions as $function) {
            if ($function->params === []) {
                $this->nullary[leafName($function->name)] = true;
            }
        }
    }

    /**
     * One module's function facts, as of the revision the reader captured.
     *
     * A null revision keeps the old, unguarded read; a captured one is checked
     * against the live revision, so a map that was replaced between the capture and
     * this call (a rewritten body republished its module) is refused rather than
     * answered.
     *
     * @return array<string, mixed>
     */
    public function fnsIn(string $module, ?int $revision = null): array
    {
        $this->requireRevision($revision);

        return $this->fnsByModule[$module] ?? [];
    }

    /** @return array<string, string> */
    public function externalFnsIn(string $module, ?int $revision = null): array
    {
        $this->requireRevision($revision);

        return $this->externalFnsByModule[$module] ?? [];
    }

    private function requireRevision(?int $revision): void
    {
        if ($revision === null || $revision === $this->functionsRevision) {
            return;
        }

        throw new \LogicException(
            "the optimizer facts were rebuilt (revision {$this->functionsRevision}) after this reader "
            . "captured revision {$revision}; a fact set from before a rewrite cannot be read after it",
        );
    }
}
