<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Syntax\Ast;

/**
 * The checked core's own terms.
 *
 * A `CheckedFunction` used to carry only *metadata about* the elaborated AST: a
 * type table keyed by a source origin that the class itself documents as
 * non-unique, and evidence plans keyed by `spl_object_id` of the site node. That
 * made the AST the only real term tree — copying or reparsing a node lost its
 * plan, and "the type of this term" was answered by position.
 *
 * This is the family the lowering reads instead. A term owns its identity
 * ({@see TermId}), its payload and its evidence decision; a source origin stays
 * beside it as the link tooling follows, never as a key. The family is closed and
 * grows one construct at a time — the cut-over is whole per construct, and the
 * lowerer's arm for it reads a term or refuses, so a migrated construct is never
 * readable two ways.
 *
 * Families published today: evidence ({@see CheckedEvidenceRef},
 * {@see CheckedEvidenceMethod}), foreign calls ({@see CheckedForeignCall}),
 * primops ({@see CheckedIntrinsicCall}), binders — a lambda's slots and the
 * names it closes over ({@see CheckedLambda}), the arity an application still
 * wants ({@see CheckedApplication}) — a variable's referent
 * ({@see CheckedVariable}), a binding site's identity ({@see CheckedPattern}),
 * a `case`'s arms ({@see CheckedCase}) and a `let`/`where` group's members and
 * their order ({@see CheckedLetGroup}). All are keyed the same way, in one id
 * space per declaration ({@see checkedTermsIn()}), and each has exactly one place
 * that says where a site is written: {@see evidencePlanFor()}, `checkedTermsIn()`'s
 * foreign, case and binding-group arms, {@see intrinsicNameFor()}, and
 * `checkedTermsIn()`'s lambda, application, variable and pattern arms.
 *
 * A family that owns *structure* — `case`, `let`, `where` — publishes the
 * arrangement lowering used to read off the node's own children: the arms in
 * source order with their guards flattened, and the members in binding order with
 * the recursive components already decided. The child nodes they name stay
 * children (a pattern, a guard, a body is lowered in place, the way a foreign
 * call's arguments are); what the term removes is the node's list and the
 * question of how it decomposes.
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
    public function __construct(
        TermId $id,
        string $origin,
        public readonly int $remainingArity,
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
        || $node instanceof Ast\PatRecord;
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
     * @param Ast\AstNode $pattern the arm's pattern, lowered as a child
     * @param list<array{guard: ?Ast\AstNode, body: Ast\AstNode}> $clauses in source order;
     *   a null guard is the unconditional case
     */
    public function __construct(
        public readonly Ast\AstNode $pattern,
        public readonly array $clauses,
    ) {
    }

    /** The guard of the last clause, which is what decides whether the arm always matches. */
    public function lastGuard(): ?Ast\AstNode
    {
        return $this->clauses === [] ? null : $this->clauses[\count($this->clauses) - 1]['guard'];
    }
}

/**
 * A `case`: its arms in source order and whether the checker proved them total.
 *
 * The scrutinee stays a child of the node, because it is one expression lowered in
 * place; the *arms* are the term's, because their order, their guard flattening and
 * the match's totality are what lowering used to re-derive from `CaseExpr::$alts`
 * and `CaseExpr::$exhaustive`.
 */
final class CheckedCase extends CheckedTerm
{
    /** @param list<CheckedCaseArm> $arms */
    public function __construct(
        TermId $id,
        string $origin,
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
    /** @param list<Ast\Binding> $bindings */
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
        public readonly ?Ast\AstNode $body,
    ) {
        parent::__construct($id, $origin);
    }

    /** @return list<Ast\Binding> every member, in binding order */
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
        public readonly Ast\AstNode $value,
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
        public readonly ?Ast\AstNode $receiver = null,
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
    /** @param list<Ast\Guarded> $clauses in source order */
    public function __construct(
        TermId $id,
        string $origin,
        public readonly array $clauses,
    ) {
        parent::__construct($id, $origin);
    }

    /** The guard of the last clause, which decides whether the chain always succeeds. */
    public function lastGuard(): ?Ast\AstNode
    {
        return $this->clauses === [] ? null : $this->clauses[\count($this->clauses) - 1]->guard;
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
