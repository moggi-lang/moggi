<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Syntax\Ast;

require_once __DIR__ . '/checked_structure.php';

/**
 * The checked core's own terms.
 *
 * A `CheckedFunction` used to carry only *metadata about* the elaborated AST: a
 * type table keyed by a source origin that the class itself documents as
 * non-unique, and evidence plans keyed by `spl_object_id` of the site node. That
 * made the AST the only real term tree — copying or reparsing a node lost its
 * plan, and "the type of this term" was answered by position.
 *
 * This is the family the lowering reads for the decisions a construct leaves to
 * inference. A term owns its identity ({@see TermId}), its payload and its
 * evidence decision; a source origin stays beside it as the link tooling follows,
 * never as a key. The family is closed *as a family* and grows one construct at a
 * time — the cut-over is whole per construct, and the lowerer's arm for it reads a
 * term or refuses, so a migrated construct is never readable two ways.
 *
 * Structure is published too. Every elaborated site has a {@see CheckedStructure} whose payload
 * holds the scalar fields and whose children are other structure ids; no structure points at the
 * source node it was published from. A typed term names its children by those ids
 * (`CheckedLambda::$body`, `CheckedApplication::$callee`/`$argument`, a case arm's pattern and
 * clauses, a group's bindings), and lowering materializes a source-located view from the
 * structure tree before it runs. The AST is therefore a *view* of the checked core, not its
 * storage protocol: a scrubbed, reparsed or discarded source tree cannot change a decision or a
 * structural child. Lowering still dispatches on the materialized view's classes because the IR
 * lowerer is written against source-shaped nodes; that view is rebuilt from terms, not read from
 * the tree the checker was handed.
 *
 * Families published today: evidence ({@see CheckedEvidenceRef},
 * {@see CheckedEvidenceMethod}), foreign calls ({@see CheckedForeignCall}),
 * primops ({@see CheckedIntrinsicCall}), binders — a lambda's slots and the
 * names it closes over ({@see CheckedLambda}), the arity an application still
 * wants ({@see CheckedApplication}) — a variable's referent
 * ({@see CheckedVariable}), a binding site's identity ({@see CheckedPattern}),
 * a `case`'s scrutinee and arms ({@see CheckedCase}) and a `let`/`where` group's members and
 * their order ({@see CheckedLetGroup}). All are keyed the same way, in one id
 * space per declaration ({@see checkedTermsIn()}), and each has exactly one place
 * that says where a site is written: {@see evidencePlanFor()}, `checkedTermsIn()`'s
 * foreign, case and binding-group arms, {@see intrinsicNameFor()}, and
 * `checkedTermsIn()`'s lambda, application, variable and pattern arms.
 *
 * A family that owns *structure* — `case`, `let`, `where` — publishes the
 * arrangement lowering used to read off the node's own children: the arms in
 * source order with their guards flattened, and the members in binding order with
 * the recursive components already decided. Their patterns, guards and bodies are
 * structure ids too, so the family is a term tree from the root down.
 */
abstract class CheckedTerm
{
    public function __construct(
        public readonly TermId $id,
        public readonly string $origin,
    ) {
    }

    /**
     * Where this term is written, for diagnostics: the origin, which names a file
     * and position a reader can open.
     */
    public function site(): string
    {
        return $this->origin;
    }
}

/**
 * The identity of one term inside one declaration's elaboration.
 *
 * Allocated by the producer in walk order, so it is stable for the same source,
 * comparable across a cache round-trip (unlike an object id) and independent of
 * how many nodes share a span (unlike an origin). Every reader that needs "the
 * term at this position" asks the term for its id, not a side map.
 */
final class TermId
{
    public function __construct(public readonly int $value)
    {
    }
}

/**
 * One declaration's terms, by id and by the source node each one elaborates.
 *
 * The record owns the node→term link. It is deliberately not a public field on `AstNode`: a
 * parsed node has no term, a copied node must not inherit one, and a scrubbed tree must keep
 * answering from the record rather than from whatever a node still happens to carry. The map is
 * the one place that link is written and read.
 *
 * Implements `ArrayAccess`/`IteratorAggregate` so existing readers that address a term by its
 * `TermId` value stay direct; new code should prefer {@see termFor()} when it has the site.
 */
final class CheckedTerms implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var array<int, CheckedTerm> */
    private array $byId = [];

    /** @var ?string serialized decision terms, decoded for the declaration currently lowering */
    private ?string $termsBlob = null;

    /** @var array<int, int> spl_object_id(site) => term id */
    private array $siteIds = [];

    /** @var array<int, Ast\AstNode> the retained node for each site id, to defeat id reuse */
    private array $siteNodes = [];

    /** @var array<int, CheckedStructure> the declaration's structural tree, by its own id */
    private array $structures = [];

    /** @var ?string the structural tree, serialized so a warm read does not keep every module decoded */
    private ?string $structuresBlob = null;

    /** @var array<int, int> spl_object_id(site) => structural term id */
    private array $structureSites = [];

    /** @var array<int, Ast\AstWalkable> the retained value for each structural site */
    private array $structureNodes = [];

    /** @var array<int, Ast\AstWalkable> structural id => the value it was published from */
    private array $structureSource = [];

    /** @var \WeakMap<Ast\AstNode, CheckedTerm> generated nodes => the term they materialize */
    private \WeakMap $materializedTerms;

    /** @var array<int, ?int> structural id => the decision term id it publishes */
    private array $structureTermIds = [];

    /** @var bool whether the process-local site maps have been rebuilt for the live AST */
    private bool $siteMapsReady = false;

    /** @var ?Ast\AstWalkable the root this record was published from, retained so cache wakeup can rebuild node links */
    private ?Ast\AstWalkable $root;

    public function __construct(?Ast\AstWalkable $root = null)
    {
        $this->root = $root;
        $this->termsBlob = null;
        $this->structuresBlob = null;
        $this->siteMapsReady = false;
        $this->materializedTerms = new \WeakMap();
    }

    public function add(Ast\AstNode $site, CheckedTerm $term): void
    {
        $this->byId[$term->id->value] = $term;
        $objectId = \spl_object_id($site);
        $this->siteIds[$objectId] = $term->id->value;
        $this->siteNodes[$objectId] = $site;
        $this->siteMapsReady = true;
    }

    /** Add one structural node, published from the elaborated value it belongs to. */
    public function addStructure(Ast\AstWalkable $site, CheckedStructure $structure): void
    {
        $this->structures[$structure->id->value] = $structure;
        $this->structureSource[$structure->id->value] = $site;
        if ($site instanceof Ast\AstNode) {
            $objectId = \spl_object_id($site);
            $this->structureSites[$objectId] = $structure->id->value;
            $this->structureNodes[$objectId] = $site;
        }
        $this->siteMapsReady = true;
    }

    /** The structural term published for a source site, or null when it has none. */
    public function structureFor(Ast\AstNode $site): ?CheckedStructure
    {
        $this->ensureSiteMaps();
        $this->ensureStructures();
        $objectId = \spl_object_id($site);
        if (($this->structureNodes[$objectId] ?? null) !== $site) {
            return null;
        }

        $id = $this->structureSites[$objectId] ?? null;

        return $id === null ? null : ($this->structures[$id] ?? null);
    }

    /** The structural term with one id. */
    public function structure(int $id): ?CheckedStructure
    {
        $this->ensureStructures();

        return $this->structures[$id] ?? null;
    }

    /** The decision term published for one structural id, or null. */
    public function termForStructure(int $id): ?CheckedTerm
    {
        $this->ensureTerms();
        $termId = $this->structureTermIds[$id] ?? null;

        return $termId === null ? null : ($this->byId[$termId] ?? null);
    }

    /**
     * Bind each structural node to the decision term published for its source site.
     *
     * The binding is captured once, after publication, rather than looked up from the source
     * node when lowering materializes the view: a scrub test is allowed to remove the source
     * link, and the checked core must still answer with the term the structure owns.
     */
    public function bindStructureTerms(): void
    {
        $this->ensureSiteMaps();
        $this->structureTermIds = [];
        foreach ($this->structureSource as $id => $source) {
            if ($source instanceof Ast\AstNode) {
                $this->structureTermIds[$id] = $this->idFor($source);
            }
        }
    }

    /**
     * Materialize the lowering view of a site from its structural term.
     *
     * Every generated node is recorded in `$generatedTerms` with the declaration term that was
     * published for the source site it materializes. Lowering therefore resolves the same term
     * from a view whose structure no longer points at the source tree; a source tree that has
     * been scrubbed after publication cannot change the answer.
     *
     * @param \WeakMap<Ast\AstNode, CheckedTerm> $generatedTerms
     */
    public function materialize(Ast\AstNode $site, \WeakMap $generatedTerms): ?Ast\AstNode
    {
        $structure = $this->structureFor($site);
        if ($structure === null) {
            return null;
        }

        $value = $this->materializeStructure($structure, $generatedTerms);

        return $value instanceof Ast\AstNode ? $value : null;
    }

    /**
     * Materialize one structural id directly.
     *
     * The typed terms name their children by these ids, so lowering a lambda body, an
     * application's callee/argument, a case arm or a record field follows the structure tree
     * rather than a source-node pointer.
     *
     * @param \WeakMap<Ast\AstNode, CheckedTerm> $generatedTerms
     */
    public function materializeId(int $id, \WeakMap $generatedTerms): mixed
    {
        $this->ensureSiteMaps();
        $this->ensureStructures();
        $structure = $this->structures[$id] ?? null;

        return $structure === null
            ? throw new \LogicException("a checked term references structure #{$id}, which its record does not own")
            : $this->materializeStructure($structure, $generatedTerms);
    }

    /** @param \WeakMap<Ast\AstNode, CheckedTerm> $generatedTerms */
    private function materializeStructure(CheckedStructure $structure, \WeakMap $generatedTerms): mixed
    {
        $id = $structure->id->value;
        $this->ensureTerms();
        $reflection = new \ReflectionClass($structure->kind);
        $source = $this->structureSource[$id] ?? null;
        $object = $reflection->newInstanceWithoutConstructor();

        foreach ($structure->payload as $name => $value) {
            $object->{$name} = $this->materializeValue($value, $generatedTerms);
        }

        if ($source instanceof Ast\AstNode && \property_exists($source, 'type')) {
            foreach (\get_object_vars($source) as $name => $value) {
                if ($value instanceof Ast\TypeNode && ! \array_key_exists($name, $structure->payload)) {
                    $object->{$name} = $value;
                }
            }
        }

        if ($object instanceof Ast\AstNode) {
            foreach (['inferredType', 'inferredInternalType'] as $name) {
                if (! \array_key_exists($name, $structure->payload)) {
                    $object->{$name} = $source instanceof Ast\AstNode ? ($source->{$name} ?? null) : null;
                }
            }
        }

        if ($object instanceof Ast\AstNode) {
            $termId = $this->structureTermIds[$id] ?? null;
            $term = $termId === null ? null : ($this->byId[$termId] ?? null);
            if ($term !== null) {
                $generatedTerms[$object] = $term;
                $this->materializedTerms[$object] = $term;
            }
        }

        return $object;
    }

    /** @param \WeakMap<Ast\AstNode, CheckedTerm> $generatedTerms */
    private function materializeValue(mixed $value, \WeakMap $generatedTerms): mixed
    {
        if (\is_array($value)) {
            if (\array_key_exists('__checked_ref', $value) && \count($value) === 1) {
                $child = $this->structures[$value['__checked_ref']] ?? null;

                return $child === null
                    ? throw new \LogicException('a checked structure references an id it does not own')
                    : $this->materializeStructure($child, $generatedTerms);
            }

            $decoded = [];
            foreach ($value as $key => $entry) {
                $decoded[$key] = $this->materializeValue($entry, $generatedTerms);
            }

            return $decoded;
        }

        return $value;
    }

    /** The term published for a site, or null when the record does not cover it. */
    public function termFor(Ast\AstNode $site): ?CheckedTerm
    {
        $materialized = $this->materializedTerms[$site] ?? null;
        if ($materialized !== null) {
            return $materialized;
        }

        $this->ensureSiteMaps();
        $this->ensureTerms();
        $id = $this->idFor($site);

        return $id === null ? null : ($this->byId[$id] ?? null);
    }

    /** The id published for a site, or null when the record does not cover it. */
    public function idFor(Ast\AstNode $site): ?int
    {
        $this->ensureSiteMaps();
        $objectId = \spl_object_id($site);
        if (($this->siteNodes[$objectId] ?? null) !== $site) {
            return null;
        }

        return $this->siteIds[$objectId] ?? null;
    }

    /** Forget a site's link without deleting the term it named. Used by scrub tests. */
    public function forget(Ast\AstNode $site): void
    {
        $this->ensureSiteMaps();
        $objectId = \spl_object_id($site);
        $structureId = $this->structureSites[$objectId] ?? null;
        if ($structureId !== null) {
            unset($this->structureTermIds[$structureId]);
        }
        $this->materializedTerms = new \WeakMap();
        unset($this->siteIds[$objectId], $this->siteNodes[$objectId]);
    }

    /** Serialize the structure tree once; a warm read keeps it compressed until this declaration lowers. */
    public function __serialize(): array
    {
        $termsBlob = $this->termsBlob;
        if ($termsBlob === null && $this->byId !== []) {
            $termsBlob = \serialize($this->byId);
        }
        $blob = $this->structuresBlob;
        if ($blob === null && $this->structures !== []) {
            $blob = \gzcompress(\serialize($this->structures));
        }

        return [
            'byId' => [],
            'termsBlob' => $termsBlob,
            'structureTermIds' => $this->structureTermIds,
            'root' => $this->root,
            'structuresBlob' => $blob,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->byId = $data['byId'] ?? [];
        $termsBlob = $data['termsBlob'] ?? null;
        $this->termsBlob = \is_string($termsBlob) ? $termsBlob : null;
        $this->structureTermIds = $data['structureTermIds'] ?? [];
        $root = $data['root'] ?? null;
        $this->root = $root instanceof Ast\AstWalkable ? $root : null;
        $blob = $data['structuresBlob'] ?? null;
        if (! \is_string($blob) && isset($data['structures']) && \is_array($data['structures'])) {
            $blob = \gzcompress(\serialize($data['structures']));
        }
        $this->structuresBlob = \is_string($blob) ? $blob : null;
        $this->structures = [];
        $this->structureSites = [];
        $this->structureNodes = [];
        $this->structureSource = [];
        $this->siteIds = [];
        $this->siteNodes = [];
        $this->siteMapsReady = false;
        $this->materializedTerms = new \WeakMap();
    }

    /** Legacy property-format payloads still land here; shape validation drops them after decode. */
    public function __wakeup(): void
    {
        $this->__unserialize(\get_object_vars($this));
    }

    private function ensureSiteMaps(): void
    {
        if ($this->siteMapsReady) {
            return;
        }

        $this->siteMapsReady = true;
        $this->siteIds = [];
        $this->siteNodes = [];
        $this->structureSites = [];
        $this->structureNodes = [];
        $this->structureSource = [];
        if (! isset($this->root) || $this->root === null) {
            return;
        }

        $index = checkedStructuresIn($this->root, false);
        $this->structureSites = $index['sites'];
        $this->structureSource = $index['sources'];
        foreach ($index['sources'] as $id => $source) {
            if (! $source instanceof Ast\AstNode) {
                continue;
            }
            $objectId = \spl_object_id($source);
            $this->structureNodes[$objectId] = $source;
            $termId = $this->structureTermIds[$id] ?? null;
            if ($termId !== null) {
                $this->siteIds[$objectId] = $termId;
                $this->siteNodes[$objectId] = $source;
            }
        }
    }

    /** Decode the structural tree only for the declaration currently lowering. */
    private function ensureStructures(): void
    {
        if ($this->structures !== [] || $this->structuresBlob === null) {
            return;
        }

        $raw = @\gzuncompress($this->structuresBlob);
        if (! \is_string($raw)) {
            $raw = $this->structuresBlob;
        }
        $decoded = @\unserialize($raw);
        if (\is_array($decoded)) {
            $this->structures = $decoded;
        }
    }

    /** Release the decoded tree; the blob remains for a later scrub/lower pass. */
    public function releaseStructures(): void
    {
        if ($this->byId !== [] && $this->termsBlob === null) {
            $this->termsBlob = \serialize($this->byId);
        }
        $this->byId = [];
        if ($this->structures !== [] && $this->structuresBlob === null) {
            $this->structuresBlob = \gzcompress(\serialize($this->structures));
        }
        $this->structures = [];
    }

    private function ensureTerms(): void
    {
        if ($this->byId !== [] || $this->termsBlob === null) {
            return;
        }

        $decoded = @\unserialize($this->termsBlob);
        if (\is_array($decoded)) {
            $this->byId = $decoded;
        }
    }

    /** Restore a link previously removed by {@see forget()}; a null term stays forgotten. */
    public function restore(Ast\AstNode $site, ?CheckedTerm $term): void
    {
        $this->ensureSiteMaps();
        if ($term === null) {
            $this->forget($site);

            return;
        }

        $this->add($site, $term);
        $this->bindStructureTerms();
    }

    public function get(int $id): ?CheckedTerm
    {
        $this->ensureTerms();

        return $this->byId[$id] ?? null;
    }

    /** @return array<int, CheckedTerm> */
    public function all(): array
    {
        $this->ensureTerms();

        return $this->byId;
    }

    public function count(): int
    {
        $this->ensureTerms();

        return \count($this->byId);
    }

    public function getIterator(): \Traversable
    {
        $this->ensureTerms();

        return new \ArrayIterator($this->byId);
    }

    public function offsetExists(mixed $offset): bool
    {
        $this->ensureTerms();

        return \is_int($offset) && isset($this->byId[$offset]);
    }

    public function offsetGet(mixed $offset): ?CheckedTerm
    {
        $this->ensureTerms();

        return \is_int($offset) ? ($this->byId[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('checked terms are published through `add()` so the site link is never lost');
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->ensureTerms();
        if (\is_int($offset)) {
            unset($this->byId[$offset]);
        }
    }
}

/** A dictionary reference: the evidence for a class, as a value. */
final class CheckedEvidenceRef extends CheckedTerm
{
    public function __construct(
        TermId $id,
        string $origin,
        public readonly string $class,
        public readonly EvidencePlan $plan,
    ) {
        parent::__construct($id, $origin);
    }
}

/**
 * A class-method reference, resolved to the dictionary it dispatches through.
 *
 * `arity` and `nullary` are the method's own shape, recorded by the checker when
 * it resolved the site; lowering reads them here rather than off the AST node.
 */
final class CheckedEvidenceMethod extends CheckedTerm
{
    public function __construct(
        TermId $id,
        string $origin,
        public readonly string $class,
        public readonly string $method,
        public readonly ?int $arity,
        public readonly bool $nullary,
        public readonly EvidencePlan $plan,
    ) {
        parent::__construct($id, $origin);
    }
}

/**
 * A primop decided at one site.
 *
 * A primop exists in the IR only as a saturated `Intrinsic`, so which primop a
 * position names is the checker's answer for that position — the operator it
 * resolved, the `#` name it rewrote to, the wrapper it left on a value for
 * lowering to eta-expand. Lowering therefore reads it here and refuses a site the
 * record does not cover, instead of re-deciding it from the node it was handed;
 * that refusal is what makes a lowering-built tree impossible to lower, which is
 * why lowering builds none.
 *
 * The arguments stay on the node: they are sub-terms of this one, lowered in
 * place, and the node is where the elaboration keeps them.
 */
final class CheckedIntrinsicCall extends CheckedTerm
{
    public function __construct(
        TermId $id,
        string $origin,
        public readonly string $intrinsic,
    ) {
        parent::__construct($id, $origin);
    }
}

/**
 * The primop a node names, for the positions a primop decision is written at, or
 * null when the node names none.
 *
 * This is the only place that says which node shapes name a primop: the producer
 * plans exactly these and lowering asks exactly these, so a shape that gains such
 * a field is covered by adding it here rather than at one of two readers. A
 * public operator reference is not one of them: `(+)` derives its primop from its
 * own name through the intrinsic registry at lowering time, which is a total
 * function of syntax rather than a decision that can go stale.
 */
function intrinsicNameFor(Ast\AstNode $node): ?string
{
    return match (true) {
        $node instanceof Ast\IntrinsicCall => $node->name,
        $node instanceof Ast\Infix => $node->resolvedIntrinsic,
        $node instanceof Ast\Apply,
        $node instanceof Ast\Variable,
        $node instanceof Ast\QualifiedRef => $node->intrinsicWrapper,
        default => null,
    };
}

/**
 * A lambda: the slots it takes and the names it closes over.
 *
 * Both are facts of the lifted callable, not of the syntax: the slot names are
 * the ones the declaration is emitted with (a pattern parameter is one slot with
 * a generated name), and the capture set is what the body is free in — the answer
 * lowering used to compute for itself at the moment it built the lambda, from a
 * tree it was already walking for another reason. Naming them here makes one
 * answer for both readers, and keeps `lowerLoweredLambda()` honest: a body built
 * as IR has no node to scan, so its captures have to be handed to it.
 *
 * The body stays on the node: it is this term's child, lowered in place, the way
 * a foreign call's arguments are.
 */
final class CheckedLambda extends CheckedTerm
{
    /**
     * @param list<string> $parameterNames the lambda's slots, in positional order
     * @param list<int|null> $parameterBinders each slot's binder identity, aligned with
     *   `$parameterNames`; null where the pattern binds no variable at all (a wildcard)
     * @param list<string> $captures the free names of the body, minus its own slots
     */
    public function __construct(
        TermId $id,
        string $origin,
        public readonly array $parameterNames,
        public readonly array $parameterBinders,
        public readonly array $captures,
        /** The body this lambda scopes, a child lowered in place. */
        public readonly ?int $body = null,
        /** The checker's inferred type for the lambda, kept here because the structural view drops overlays. */
        public readonly ?Ast\AstNode $type = null,
    ) {
        parent::__construct($id, $origin);
    }

    public function arity(): int
    {
        return \count($this->parameterNames);
    }
}

/**
 * An application: how many arguments the applied expression is still waiting for
 * after this one.
 *
 * That number decides whether a dictionary method applied to too few arguments
 * becomes an eta-expanded lambda, and it used to be read off the node's internal
 * type — the checker's settled answer, consumed as syntax. The term states it, so
 * lowering never has to reconstruct it from the node; the callee and arguments
 * stay on the node, as children and as the spine lowering flattens.
 */
final class CheckedApplication extends CheckedTerm
{
    /**
     * @param ?Ast\AstNode $callee the expression applied, a child lowered in place; null only for a
     *   term built without a node (a synthetic application lowering never reads)
     * @param ?Ast\AstNode $argument the argument this application supplies, a child lowered in place
     * @param ?string $intrinsic the primop this application names, when the site is both a primop
     *   site and an application (an under-applied primop): one term then carries both facts, so the
     *   spine is not lost when the primop decision replaces it
     */
    public function __construct(
        TermId $id,
        string $origin,
        public readonly int $remainingArity,
        public readonly ?int $callee = null,
        public readonly ?int $argument = null,
        /** The primop this application names, when the site is also a primop site. */
        public readonly ?string $intrinsic = null,
    ) {
        parent::__construct($id, $origin);
    }
}

/**
 * A variable use, resolved to the binder it refers to.
 *
 * A binder has an identity of its own (`PatVar::$binderId`, allocated when the
 * checker binds the pattern), and the checker already writes it onto the use site
 * it resolved; this term publishes that answer so lowering reads it once instead
 * of asking whether the node's *spelling* happens to be in a name-keyed
 * environment. Two binders that share a spelling in nested scopes are then two
 * different identities with two different IR slots, and a use of the inner one
 * cannot be answered with the outer one because the two names collided in a map.
 *
 * `binder` is null for a use that names no local binder — a top-level entry, an
 * imported name, a synthesized `__litpat`/evidence local. Those keep their own
 * resolution (origin, imported symbol, name-keyed environment); this term states
 * only that no binder owns the use, rather than leaving the question unasked.
 */
final class CheckedVariable extends CheckedTerm
{
    public function __construct(
        TermId $id,
        string $origin,
        public readonly ?int $binder,
    ) {
        parent::__construct($id, $origin);
    }
}

/**
/** The shapes a pattern can have. One per node class the parser produces. */
enum PatternShape: string
{
    case Wild = 'wild';
    case Var = 'var';
    case Lit = 'lit';
    case Char = 'char';
    case Nil = 'nil';
    case Cons = 'cons';
    case Con = 'con';
    case Tuple = 'tuple';
    case Record = 'record';
    case View = 'view';
}

/**
 * A pattern: its shape, the binders it introduces and its sub-patterns.
 *
 * A pattern is both the thing a `case` arm matches and the thing a binder is allocated for, so this
 * term answers both questions. The identity half is the one every reader of a variable depends on:
 * one identity per site is allocated when the checker binds the pattern, and a use's referent
 * ({@see CheckedVariable}), the frame a lambda opens and the slot a `let` or a `case` arm allocates
 * are all answered from it. The shape half is what lowering used to read off the node one level
 * below the family that owns it: `lowerPattern()` asked each `PatCon` for its arguments, each
 * `PatRecord` for its fields and each `PatVar` for its spelling.
 *
 * The sub-patterns are named by their own term ids, not by nodes, so the family is a term tree: a
 * parent is built after its children publish ({@see checkedTermsIn()}), and lowering walks ids. Both
 * halves come from the checker: `PatVar::$binderId` is where the identity was *allocated*, and it
 * is read here, at publication, exactly once — a tree that was scrubbed, re-parsed or restored from
 * a cache is not a place to look for either half afterwards.
 */
final class CheckedPattern extends CheckedTerm
{
    /**
     * @param ?int $binder the identity a variable pattern binds, null for every other shape and for a
     *   pattern that binds nothing
     * @param ?string $name a variable's occurrence name, or a constructor's/record's source name
     * @param int|string|null $value a literal or character pattern's value
     * @param list<int> $children sub-pattern terms: `cons` is `[head, tail]`, `con`/`tuple` are the
     *   arguments in source order
     * @param list<array{name: string, id: int}> $fields a record pattern's fields in source order
     * @param list<string> $binderNames every name this pattern subtree binds, in source order
     */
    public function __construct(
        TermId $id,
        string $origin,
        public readonly PatternShape $shape,
        public readonly ?int $binder = null,
        public readonly ?string $name = null,
        public readonly int|string|null $value = null,
        public readonly array $children = [],
        public readonly array $fields = [],
        public readonly array $binderNames = [],
    ) {
        parent::__construct($id, $origin);
    }

    /**
     * The names this subtree binds, keyed for a bound-set question.
     *
     * Free-variable analysis asks "is this name introduced here?"; the answer is published with
     * the term so it is the checker's one read of the node rather than a second walk of it.
     *
     * @return array<string, true>
     */
    public function boundNames(): array
    {
        return \array_fill_keys($this->binderNames, true);
    }
}

/**
 * Whether a node is one of the pattern shapes this family owns.
 *
 * The one place that says which node classes a pattern can be: the producer reserves an id for
 * exactly these and lowering asks for their term, so a new pattern shape is added here rather than
 * at one of two readers. The same question, answered the same way, is what makes a pattern site no
 * lowering path can reach without a term.
 */
function isPatternNode(Ast\AstNode $node): bool
{
    return $node instanceof Ast\PatWild
        || $node instanceof Ast\PatVar
        || $node instanceof Ast\PatLit
        || $node instanceof Ast\PatChar
        || $node instanceof Ast\PatNil
        || $node instanceof Ast\PatCons
        || $node instanceof Ast\PatCon
        || $node instanceof Ast\PatTuple
        || $node instanceof Ast\PatRecord
        || $node instanceof Ast\PatView;
}

/**
 * The shape a pattern node has, as the term records it.
 *
 * The mapping from node class to shape lives here, beside {@see isPatternNode()}, so the
 * producer and the lowerer cannot drift: a pattern the producer publishes always has a shape
 * the lowerer knows, and a node class this refuses is one neither side handles.
 */
function patternShape(Ast\AstNode $node): PatternShape
{
    return match ($node::class) {
        Ast\PatWild::class => PatternShape::Wild,
        Ast\PatVar::class => PatternShape::Var,
        Ast\PatLit::class => PatternShape::Lit,
        Ast\PatChar::class => PatternShape::Char,
        Ast\PatNil::class => PatternShape::Nil,
        Ast\PatCons::class => PatternShape::Cons,
        Ast\PatCon::class => PatternShape::Con,
        Ast\PatTuple::class => PatternShape::Tuple,
        Ast\PatRecord::class => PatternShape::Record,
        Ast\PatView::class => PatternShape::View,
        default => throw new \LogicException('`' . $node::class . '` is not a pattern node'),
    };
}

/**
 * One arm of a `case`: the pattern it matches and the guard clauses it offers.
 *
 * The clauses are flattened from whatever the parser produced — an arm with no
 * guards is one clause whose guard is null, an arm with guards is one clause per
 * `| g = e` — so lowering never asks whether an arm's body happens to be a
 * `GuardsExpr`. That question is structure, and structure is the term's.
 */
final class CheckedCaseArm
{
    /**
     * @param int $pattern the arm's pattern structure id
     * @param list<array{guard: ?Ast\AstNode, body: Ast\AstNode}> $clauses in source order;
     *   a null guard is the unconditional case
     */
    public function __construct(
        public readonly int $pattern,
        /** @var list<array{guard: ?int, body: int}> */
        public readonly array $clauses,
    ) {
    }

    /** The guard of the last clause, which is what decides whether the arm always matches. */
    public function lastGuard(): ?int
    {
        return $this->clauses === [] ? null : $this->clauses[\count($this->clauses) - 1]['guard'];
    }
}

/**
 * A `case`: its arms in source order and whether the checker proved them total.
 *
 * The scrutinee and the arms are both the term's. Their order, their guard
 * flattening and the match's totality are what lowering used to re-derive from
 * `CaseExpr::$scrutinee`, `CaseExpr::$alts` and `CaseExpr::$exhaustive`.
 */
final class CheckedCase extends CheckedTerm
{
    /** @param list<CheckedCaseArm> $arms */
    public function __construct(
        TermId $id,
        string $origin,
        public readonly int $scrutinee,
        public readonly array $arms,
        public readonly bool $exhaustive,
    ) {
        parent::__construct($id, $origin);
    }
}

/**
 * One component of a binding group: the members that bind together, in source
 * order, and whether they bind each other.
 */
final class CheckedBindingGroup
{
    /** @param list<int> $bindings binding structure ids, in source order */
    public function __construct(
        public readonly array $bindings,
        public readonly bool $recursive,
    ) {
    }
}

/**
 * A `let` or `where` group — or a `do` statement's group — as its members and
 * their binding order.
 *
 * Bindings bind in dependency order, and the components that need each other are
 * one recursive unit; that decomposition is what `bindingGroupSccs()` decides, and
 * lowering used to run it again on the node's own list at the moment it lowered.
 * Publishing it means the order is the check's answer, computed once on the final
 * tree, instead of a second derivation that could disagree with the one inference
 * used.
 *
 * `body` is the expression the group scopes (`let … in b`, `e where …`); it is null
 * for a `do` statement's group, whose scope is the surrounding statement list.
 */
final class CheckedLetGroup extends CheckedTerm
{
    /**
     * @param list<CheckedBindingGroup> $components in dependency order, producers first
     */
    public function __construct(
        TermId $id,
        string $origin,
        public readonly array $components,
        public readonly ?int $body,
    ) {
        parent::__construct($id, $origin);
    }

    /** @return list<int> every member's structure id, in binding order */
    public function members(): array
    {
        $members = [];
        foreach ($this->components as $component) {
            $members = [...$members, ...$component->bindings];
        }

        return $members;
    }
}

/**
 * A foreign call, with the host target the checker resolved for it.
 *
 * A foreign import's body is synthesized by the checker, which is where the host
 * member, the native signature and the boxing convention are decided; those are
 * decisions, not syntax, so they are recorded here once. Lowering reads them off
 * the term and refuses a foreign site the record does not cover, instead of
 * reading a payload the node happens to carry.
 *
 * The arguments stay on the node: they are sub-terms of this one, lowered in
 * place, and the node is where the elaboration keeps them.
 */
final class CheckedForeignCall extends CheckedTerm
{
    /** @param array<int, int> $handleUnboxArgs indexes of args passed as unboxed handles */
    public function __construct(
        TermId $id,
        string $origin,
        public readonly string $name,
        public readonly string $backend,
        public readonly string $kind,
        public readonly string $path,
        public readonly string $dispatch,
        public readonly ?string $classPath,
        public readonly ?string $member,
        public readonly \Moggi\IR\IoWrap $ioWrap,
        public readonly bool $phpValueBox,
        public readonly bool $handleBox,
        public readonly array $handleUnboxArgs,
        public readonly ?string $nativeSig,
    ) {
        parent::__construct($id, $origin);
    }
}

/** The shapes a literal can have. One per node class the parser produces. */
enum LiteralShape: string
{
    case Int = 'int';
    case Double = 'double';
    case Str = 'string';
    case Char = 'char';
}

/**
 * A literal, with the value the checker settled.
 *
 * A literal looks like the one construct that could stay on the node — its value
 * is in the source — but it is also where a numeric literal's *type* is decided
 * (defaulted to a concrete `Int`/`Double`, pinned by an annotation, or widened to
 * a big-integer type), and "which of these literals is which" is a checker answer
 * written onto the node. Publishing the value and the big-integer digits here is
 * what lets lowering read the term and refuse an unplanned literal. What this
 * term deliberately does **not** publish yet is the settled machine type: consuming
 * it would change the constants lowering emits, and the defect it would fix (a
 * defaulted numeric binding reaching the JVM emitter as the wrong type) is its own
 * change with its own golden. The value is published so the node path can go; the
 * type stays a named remainder rather than a fact this pass invents.
 */
final class CheckedLiteral extends CheckedTerm
{
    /**
     * @param int|float|string $value the literal's value in its own shape: an int, a float,
     *   a string, or a character's Unicode scalar
     * @param ?string $digits the decimal digits when an integer literal is too large for the
     *   host `int`; a type that can hold the whole number is built from these instead
     */
    public function __construct(
        TermId $id,
        string $origin,
        public readonly LiteralShape $shape,
        public readonly int|float|string $value,
        public readonly ?string $digits = null,
    ) {
        parent::__construct($id, $origin);
    }
}

/** One field of a record construction or update: the label and the value written for it. */
final class CheckedRecordField
{
    public function __construct(
        public readonly string $name,
        public readonly int $value,
    ) {
    }
}

/**
 * A record construction or a record update.
 *
 * A construction names fields and the checker names the constructor; an update
 * names fields, the checker names the constructor *from the receiver's type*, and
 * every field the source left out is a read of the receiver — by position, or as
 * identity when the record is a `newtype`. All of that is a decision about
 * arrangement, and each piece used to be read off the node at lowering time
 * (`RecordCon::$name`, `RecordUpdate::$constructor`, `::$fieldOnNewtype`, the
 * `$fields` lists). One term covers both shapes, because they are one family: a
 * construction is an update with no receiver.
 *
 * The field *values* and the receiver stay children (lowered in place); the term
 * owns which fields there are, in which order they were written, and what the
 * unspecified positions mean.
 */
final class CheckedRecord extends CheckedTerm
{
    /**
     * @param string $constructor the constructor the checker resolved
     * @param list<CheckedRecordField> $fields in source order
     * @param ?Ast\AstNode $receiver the record being updated; null for a construction
     */
    public function __construct(
        TermId $id,
        string $origin,
        public readonly string $constructor,
        public readonly array $fields,
        public readonly ?int $receiver = null,
        public readonly bool $fieldOnNewtype = false,
    ) {
        parent::__construct($id, $origin);
    }

    public function isUpdate(): bool
    {
        return $this->receiver !== null;
    }
}

/**
 * A bare `GuardsExpr`: its clauses in source order.
 *
 * A guarded clause is a guard and a body, and a chain of them is a sequence of
 * alternatives — structure, not a payload. The clause bodies and guards stay
 * children (lowered in place); the term owns the order and the count, so lowering
 * neither reads `GuardsExpr::$clauses` nor asks the node whether its last guard is
 * a constant `True` (that question is answered beside the clauses, from the same
 * declaration-based `trueBindings` it has always used).
 */
final class CheckedGuards extends CheckedTerm
{
    /** @param list<array{guard: int, body: int}> $clauses in source order */
    public function __construct(
        TermId $id,
        string $origin,
        public readonly array $clauses,
    ) {
        parent::__construct($id, $origin);
    }

    /** The guard of the last clause, which decides whether the chain always succeeds. */
    public function lastGuard(): ?int
    {
        return $this->clauses === [] ? null : $this->clauses[\count($this->clauses) - 1]['guard'];
    }
}

/**
 * The shape a literal node has, as the term records it.
 *
 * Beside {@see isPatternNode()} and {@see patternShape()} for the same reason: one
 * place says which node classes a family covers, so the producer and the lowerer
 * cannot drift.
 */
function literalShape(Ast\AstNode $node): LiteralShape
{
    return match ($node::class) {
        Ast\IntegerLit::class => LiteralShape::Int,
        Ast\DoubleLit::class => LiteralShape::Double,
        Ast\StringLit::class => LiteralShape::Str,
        Ast\CharLit::class => LiteralShape::Char,
        default => throw new \LogicException('`' . $node::class . '` is not a literal node'),
    };
}

/** Whether a node is one of the literal shapes this family owns. */
function isLiteralNode(Ast\AstNode $node): bool
{
    return $node instanceof Ast\IntegerLit
        || $node instanceof Ast\DoubleLit
        || $node instanceof Ast\StringLit
        || $node instanceof Ast\CharLit;
}
