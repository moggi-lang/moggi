<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

/**
 * The dictionaries one declaration's body is checked against.
 *
 * A declaration's evidence vocabulary has exactly one owner: this object. It is
 * created before the body is checked and complete at the declaration's
 * checkpoint, and it is the only place a dictionary parameter name for the
 * declaration is allocated. Two shapes:
 *
 * - **Closed** — a declaration that spelled its signature out. Its obligations
 *   are fixed at creation; nothing the body reports can add to them.
 * - **Open** — an inferred declaration, whose type is still being inferred when
 *   the body starts. It holds no obligation at creation; the body reports the
 *   ones it wants as pending constraints at its call sites, and the checkpoint
 *   adopts them.
 *
 * A local annotated binding group is deliberately *not* a source for this
 * vocabulary. Its annotation is its own scheme with its own dictionary
 * parameters, and its body is checked against those; the group's use sites are
 * what turn "this group needs `Show a` here" into a wanted constraint of the
 * enclosing declaration. Registering the group's obligations directly, or
 * letting its body resolve class methods through the declaration's dictionaries,
 * unifies the annotation's variables with the declaration's -- which makes the
 * group monomorphic and collapses two independent parameters of the declaration
 * into one.
 *
 * Obligations are compared by what they *are* -- class and resolved argument
 * list -- so the same one reached twice (a call site and the checkpoint's report)
 * stays one parameter. The object allocates and records; it never reads or
 * writes checker state but the expansion and pruning it asks for.
 */
final class DeclarationAllocation
{
    /**
     * @param list<Ast\PendingConstraint> $user obligations as written, unexpanded (the scheme's list)
     * @param list<Ast\PendingConstraint> $dicts one entry per parameter, superclasses expanded
     */
    private function __construct(
        private array $user,
        private array $dicts,
    ) {
    }

    /** A declaration whose obligations are settled only when its body is. */
    public static function open(): self
    {
        return new self([], []);
    }

    /**
     * A body that already solved its own vocabulary -- a local binding group's
     * constrained annotation, whose parameters were allocated at the annotation
     * before its body was checked.
     *
     * It has the same owner as a declaration's because the same questions are
     * asked of it: which obligations are written (the disambiguation rule
     * prefers a written class over one a superclass brought along), and which
     * dictionaries belong to this body. Answering from the enclosing
     * declaration's list would mark the annotation's own `Ord` as derived and
     * resolve its methods through the declaration's dictionaries.
     *
     * @param list<Ast\PendingConstraint> $user the annotation's constraints, unexpanded
     * @param list<Ast\PendingConstraint> $dicts the same list expanded, one entry per parameter
     */
    public static function ofSolved(array $user, array $dicts): self
    {
        return new self($user, $dicts);
    }

    /**
     * A declaration whose obligations are already known: it spelled them out.
     *
     * The list is the signature's own solve, with no obligation folded into
     * another: a written signature's dictionary vector is a contract with every
     * caller, so `(Integral a, Bits a)` carries `Eq a` twice, at two positions.
     *
     * @param list<Ast\PendingConstraint> $user the signature's constraints
     */
    public static function closed(TypeCheckState $state, array $user): self
    {
        $user = refreshConstraintArgs($state, $user);
        $dicts = expandConstraintsWithSuperclasses($state, $user);
        applyConstraintVarKinds($state, $dicts);

        return new self($user, $dicts);
    }

    /**
     * Rewrite the vocabulary into the spelling the body will be checked under.
     *
     * A declaration with a written signature checks its body against fresh rigid
     * variables, so its dictionaries and the types they are over name those
     * skolems; the allocation has to follow, or "is this dictionary one the
     * declaration declared?" would compare two spellings of one obligation.
     *
     * @param array<string, Type> $mapping
     */
    public function substitute(array $mapping): void
    {
        $this->user = substitutePendingConstraints($this->user, $mapping);
        $this->dicts = substitutePendingConstraints($this->dicts, $mapping);
    }

    /** @return list<Ast\PendingConstraint> */
    public function userObligations(): array
    {
        return $this->user;
    }

    /** @return list<Ast\PendingConstraint> */
    public function obligations(): array
    {
        return $this->dicts;
    }

    /**
     * Take on the given obligations, allocating a parameter for each dictionary
     * they need that the declaration does not already have.
     *
     * @param list<Ast\PendingConstraint> $userConstraints
     * @return list<Ast\PendingConstraint> the dictionaries this call allocated, in
     *   parameter order
     */
    private function register(TypeCheckState $state, array $userConstraints): array
    {
        $allocated = [];
        foreach (refreshConstraintArgs($state, $userConstraints) as $constraint) {
            if ($this->covers($state, $this->user, $constraint)) {
                continue;
            }

            $this->user[] = $constraint;

            foreach (expandConstraintsWithSuperclasses($state, [$constraint]) as $dict) {
                $this->dicts[] = new Ast\PendingConstraint(
                    $dict->class,
                    $dict->args,
                    evidenceParamName($dict->class, \count($this->dicts)),
                    $dict->implicit,
                    $dict->instanceHeadAst,
                );
                $allocated[] = $this->dicts[\count($this->dicts) - 1];
            }
        }

        if ($allocated !== []) {
            applyConstraintVarKinds($state, $this->dicts);
        }

        return $allocated;
    }

    /**
     * Adopt the obligations a finished body reported, at the declaration's
     * checkpoint.
     *
     * An inferred body reports at its call sites what it still had unresolved.
     * Two reports of the same obligation name the same types by the time the
     * body is finished (a use site ties the instantiated variables), so they are
     * one dictionary; two reports at two different variables are two
     * obligations and get two parameters. The comparison is therefore exact
     * identity of class and *resolved* arguments -- never "both sides are still
     * flexible, so fold them", which would silently unify the declaration's own
     * parameters through an accident of the report's spelling.
     *
     * @param list<Ast\PendingConstraint> $pending
     * @return list<Ast\PendingConstraint> the dictionaries this call allocated
     */
    public function adoptPending(TypeCheckState $state, array $pending): array
    {
        $allocated = [];
        foreach ($pending as $constraint) {
            if ($this->covers($state, $this->dicts, $constraint)) {
                continue;
            }

            foreach ($this->register($state, [$constraint]) as $dict) {
                $allocated[] = $dict;
            }
        }

        return $allocated;
    }

    /**
     * The identity of an obligation: its class and its resolved arguments.
     */
    private function obligationKey(TypeCheckState $state, Ast\PendingConstraint $constraint): string
    {
        if ($constraint->args === []) {
            return $constraint->class;
        }

        return $constraint->class . ':' . constraintArgsKey(\array_map(
            static fn (Type $arg): Type => prune($state, $arg),
            $constraint->args,
        ));
    }

    /**
     * Whether this dictionary is one of the declaration's *written* obligations,
     * as opposed to one a superclass expansion brought along.
     *
     * The disambiguation rule for a method two classes offer prefers the class a
     * declaration spelled out, and the answer has to come from the same list the
     * declaration publishes -- otherwise a dictionary a superclass expansion
     * brought along would count as declared and its method would win.
     */
    public function declares(TypeCheckState $state, Ast\PendingConstraint $dict): bool
    {
        return $this->covers($state, $this->user, $dict);
    }

    /**
     * Whether the list already holds this obligation: same class, same arity,
     * and each argument the same *resolved* type.
     *
     * Resolved, so the obligation a local annotation registered before the use
     * site tied its variable to the declaration's is the obligation the
     * checkpoint reports afterwards. Same type, not merely compatible: `Num a`
     * and `Num b` with `a` and `b` still flexible are two obligations, and
     * folding them would hand one dictionary where the program needs two.
     *
     * @param list<Ast\PendingConstraint> $held
     */
    private function covers(TypeCheckState $state, array $held, Ast\PendingConstraint $constraint): bool
    {
        $key = $this->obligationKey($state, $constraint);
        foreach ($held as $existing) {
            if ($this->obligationKey($state, $existing) === $key) {
                return true;
            }
        }

        return false;
    }
}
