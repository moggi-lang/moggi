<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

/**
 * The dictionaries one declaration's body is checked against.
 *
 * One owner per declaration: it is created before the body is checked, complete
 * at the declaration's checkpoint, and the only place a dictionary parameter
 * name is allocated for it.
 *
 * - **Closed** — a declaration that spelled its signature out. Its obligations
 *   are fixed at creation; nothing the body reports can add to them.
 * - **Open** — an inferred declaration. It holds no obligation at creation, and
 *   its checkpoint adopts the ones its body's call sites reported.
 *
 * A local annotated binding group is deliberately *not* a source for this
 * vocabulary. Its annotation is its own scheme; letting its body resolve class
 * methods through the declaration's dictionaries would unify the annotation's
 * variables with the declaration's and make the group monomorphic.
 *
 * Obligations are compared by class and *resolved* arguments, so the same one
 * reached twice stays one parameter. The object allocates and records; it writes
 * no checker state beyond the expansion and pruning it asks for.
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
     * The body's own vocabulary, already solved — a local binding group's
     * constrained annotation, whose parameters were allocated at the annotation.
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
     * The list is the signature's own solve, with nothing folded into anything
     * else: a written signature's dictionary vector is a contract with every
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
     * Rewrite the vocabulary into the spelling the body is checked under: a
     * written signature's body uses fresh rigid variables, and its dictionaries
     * name those skolems.
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
     * @return list<Ast\PendingConstraint> the dictionaries this call allocated, in parameter order
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
     * Adopt the obligations a finished body reported, at its checkpoint.
     *
     * Two reports of one obligation name the same types once the body is
     * finished, so they are one dictionary; two reports at two different
     * variables are two obligations and get two parameters.
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

    /** The identity of an obligation: its class and its resolved arguments. */
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
     * declaration spelled out, so the answer comes from the list the declaration
     * publishes, not from the expanded one.
     */
    public function declares(TypeCheckState $state, Ast\PendingConstraint $dict): bool
    {
        return $this->covers($state, $this->user, $dict);
    }

    /**
     * Whether the list already holds this obligation: same class, same arity,
     * each argument the same *resolved* type.
     *
     * Resolved, so an obligation a local annotation registered before a use site
     * tied its variable is the one the checkpoint reports afterwards. Same type,
     * never merely compatible: `Num a` and `Num b` with `a`, `b` flexible are two
     * obligations, and folding them hands one dictionary where the program needs
     * two.
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
