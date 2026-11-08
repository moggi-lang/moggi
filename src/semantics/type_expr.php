<?php declare(strict_types=1);

namespace Moggi\Semantics\TypeExpr;

require_once __DIR__ . '/types/type_laws.php';
require_once __DIR__ . '/kernel.php';

use Moggi\Syntax\Ast\PendingConstraint;

use function Moggi\Semantics\Types\typeChildren;

/**
 * Marker interface for the compiler's internal type representation.
 */
interface Type
{
}

final class TInt implements Type
{
}

final class TStr implements Type
{
}

final class TChar implements Type
{
}

final class TWord implements Type
{
}

final class TWord8 implements Type
{
}

final class TWord16 implements Type
{
}

final class TWord32 implements Type
{
}

final class TWord64 implements Type
{
}

final class TInt8 implements Type
{
}

final class TInt16 implements Type
{
}

final class TInt32 implements Type
{
}

final class TInt64 implements Type
{
}

final class TBytes implements Type
{
}

final class TDouble implements Type
{
}

final class TUnit implements Type
{
}

final class TVar implements Type
{
    public function __construct(public readonly string $name)
    {
    }
}

/**
 * A type *variable* applied to arguments: `f a` where `f` is a variable, not a
 * type constructor.
 *
 * The distinction is the whole point of the node: an applied variable is not a
 * constructor application, so kind checking, unification, instance search and
 * printing can dispatch on it instead of inspecting the name of a `TCon` and
 * asking whether the kind environment happens to know it.
 */
final class TVarApp implements Type
{
    /** @param list<Type> $args */
    public function __construct(public readonly string $name, public readonly array $args)
    {
    }
}

final class TCon implements Type
{
    /**
     * @param array<int, Type> $args
     * @param ?string $module the module that declares the type, when the head knows it: a
     *        spelling denotes one declaration per module, so `A.T` and `B.T` are two types
     *        and an instance for one must not answer for the other. Null where inference
     *        built the head rather than a declaration or an interface (`List`, `Bool`,
     *        `__Dict_C`), and an unknown module matches any (see `typeConModulesMatch`).
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args = [],
        public readonly ?string $module = null,
    ) {
    }
}

final class TArrow implements Type
{
    public function __construct(public readonly Type $from, public readonly Type $to)
    {
    }
}

/** A promoted data constructor used as a type (DataKinds), e.g. `'Red` or `'InfixI 'LeftAssociative`. */
final class TPromoted implements Type
{
    /** @param list<Type> $args */
    public function __construct(public readonly string $name, public readonly array $args = [])
    {
    }
}

/** A string type literal of kind `Symbol` (DataKinds), e.g. `"hello"`. */
final class TStringLit implements Type
{
    public function __construct(public readonly string $value)
    {
    }
}

/**
 * A numeric type literal of kind `Nat` (DataKinds), e.g. `1024`.
 *
 * `$digits` is a canonical non-negative decimal string (`"0"`, `"1024"`) —
 * never a Peano encoding and never a PHP int (so large literals stay exact).
 */
final class TNatLit implements Type
{
    public function __construct(public readonly string $digits)
    {
    }
}

/** Strip leading zeros from a decimal digit string; `"0"` stays `"0"`. */
function canonicalNatDigits(string $digits): string
{
    $trimmed = \ltrim($digits, '0');

    return $trimmed === '' ? '0' : $trimmed;
}

final class Scheme
{
    /**
     * @param array<int, string> $bound
     * @param list<PendingConstraint> $constraints the obligations as written
     * @param array<string, true> $freeTypeVars
     * @param list<PendingConstraint> $dicts the solved dictionary list: the same
     *   obligations closed under their classes' superclasses, computed once
     *   while this scheme's own variables were still distinct. A use site
     *   substitutes this list instead of expanding `$constraints` itself, so the
     *   dictionaries a definition takes and the arguments a call passes come
     *   from one solve and cannot disagree
     */
    public function __construct(
        public readonly Type $type,
        public readonly array $bound,
        public readonly array $constraints,
        public readonly int $runtimeConstraintCount,
        public readonly array $freeTypeVars,
        public readonly bool $classMethod = false,
        public readonly ?string $class = null,
        public readonly array $dicts = [],
    ) {
    }

    /** Local binder id for LSP rename/refs (not part of scheme identity). */
    public ?int $binderId = null;

    /**
     * Rebuild this scheme with a freshly pruned type/constraints (and matching
     * free-variable cache), preserving every other field. Used where the old
     * array encoding mutated the scheme in place.
     *
     * @param list<PendingConstraint> $constraints
     * @param array<string, true> $freeTypeVars
     * @param ?list<PendingConstraint> $dicts the solved list pruned to match, or
     *   null to keep this scheme's own (its variables are unchanged)
     */
    public function withPruned(Type $type, array $constraints, array $freeTypeVars, ?array $dicts = null): self
    {
        $out = new self(
            $type,
            $this->bound,
            $constraints,
            $this->runtimeConstraintCount,
            $freeTypeVars,
            $this->classMethod,
            $this->class,
            $dicts ?? $this->dicts,
        );
        $out->binderId = $this->binderId;

        return $out;
    }

    /** Rebuild this scheme tagged as a class method belonging to `$class`. */
    public function asClassMethod(string $class): self
    {
        $out = new self(
            $this->type,
            $this->bound,
            $this->constraints,
            $this->runtimeConstraintCount,
            $this->freeTypeVars,
            true,
            $class,
            $this->dicts,
        );
        $out->binderId = $this->binderId;

        return $out;
    }
}

/** @param array<int, string> $bound @param list<PendingConstraint> $constraints @param list<PendingConstraint> $dicts */
function scheme(Type $type, array $bound, array $constraints = [], ?int $runtimeConstraintCount = null, array $dicts = []): Scheme
{
    return new Scheme(
        $type,
        $bound,
        $constraints,
        $runtimeConstraintCount ?? \count(\array_filter(
            $constraints,
            static fn ($constraint): bool => $constraint->class !== \Moggi\Semantics\Kernel\coercionClass(),
        )),
        schemeFreeTypeVars($type, $bound, $constraints),
        false,
        null,
        $dicts,
    );
}

/**
 * Free type variables of a scheme: `(vars(type) ∪ vars(constraint args)) − bound`.
 *
 * @param array<int, string> $bound
 * @param list<PendingConstraint> $constraints
 * @return array<string, true>
 */
function schemeFreeTypeVars(Type $type, array $bound, array $constraints): array
{
    $free = collectTypeVars($type, []);
    foreach ($constraints as $constraint) {
        foreach ($constraint->args as $arg) {
            $free = collectTypeVars($arg, $free);
        }
    }

    foreach ($bound as $name) {
        unset($free[$name]);
    }

    return $free;
}

/**
 * Structural type-variable collector mirroring Types\typeVars.
 *
 * The children come from the same law module `typeVars` uses, so a promoted or
 * variable-headed application frees its arguments here too: a scheme built from
 * `'Pair a` used to report no free variable at all, which made it unsound to
 * generalise.
 *
 * @param array<string, true> $acc
 * @return array<string, true>
 */
function collectTypeVars(Type $type, array $acc, array $bound = []): array
{
    if ($type instanceof TVar) {
        if (! isset($bound[$type->name])) {
            $acc[$type->name] = true;
        }

        return $acc;
    }

    if ($type instanceof TForall) {
        foreach ($type->binders as $binder) {
            $bound[$binder->name] = true;
        }

        return collectTypeVars($type->body, $acc, $bound);
    }

    if ($type instanceof TVarApp && ! isset($bound[$type->name])) {
        $acc[$type->name] = true;
    }

    foreach (typeChildren($type) as $child) {
        $acc = collectTypeVars($child, $acc, $bound);
    }

    return $acc;
}


/**
 * One binder of a `forall`: a name and the kind it was written with (null = `Type`).
 *
 * Kinds are carried on the binder rather than in a side table so a quantified
 * type stays self-describing through substitution, zonking and printing.
 */
final class ForallBinder
{
    public function __construct(
        public readonly string $name,
        public readonly ?\Moggi\Semantics\Kinds\Kind $kind = null,
    ) {
    }
}

/**
 * An explicit universal quantifier: `forall a. body`.
 *
 * The checker identifies type variables by name, so the binders are names, not
 * de Bruijn indices; the invariant is that a name is bound once within its own
 * `TForall` and may shadow a binder of an enclosing one. `explicit` records
 * whether the source wrote the quantifier (it is printed only then); it never
 * affects checking.
 */
final class TForall implements Type
{
    /** @param list<ForallBinder> $binders */
    public function __construct(
        public readonly array $binders,
        public readonly Type $body,
        public readonly bool $explicit = true,
    ) {
    }
}
