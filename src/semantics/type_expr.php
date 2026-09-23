<?php declare(strict_types=1);

namespace Moggi\Semantics\TypeExpr;

use Moggi\Syntax\Ast\PendingConstraint;

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

final class TCon implements Type
{
    /** @param array<int, Type> $args */
    public function __construct(public readonly string $name, public readonly array $args = [])
    {
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
    $trimmed = ltrim($digits, '0');

    return $trimmed === '' ? '0' : $trimmed;
}

final class Scheme
{
    /**
     * @param array<int, string> $bound
     * @param list<PendingConstraint> $constraints
     * @param array<string, true> $freeTypeVars
     */
    public function __construct(
        public readonly Type $type,
        public readonly array $bound,
        public readonly array $constraints,
        public readonly int $runtimeConstraintCount,
        public readonly array $freeTypeVars,
        public readonly bool $classMethod = false,
        public readonly ?string $class = null,
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
     */
    public function withPruned(Type $type, array $constraints, array $freeTypeVars): self
    {
        $out = new self(
            $type,
            $this->bound,
            $constraints,
            $this->runtimeConstraintCount,
            $freeTypeVars,
            $this->classMethod,
            $this->class,
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
        );
        $out->binderId = $this->binderId;

        return $out;
    }
}

/** @param array<int, string> $bound @param list<PendingConstraint> $constraints */
function scheme(Type $type, array $bound, array $constraints = [], ?int $runtimeConstraintCount = null): Scheme
{
    return new Scheme(
        $type,
        $bound,
        $constraints,
        $runtimeConstraintCount ?? count($constraints),
        // Precomputed once so environment-wide free-variable queries during
        // generalization don't re-walk every scheme's type.
        schemeFreeTypeVars($type, $bound, $constraints),
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
 * @param array<string, true> $acc
 * @return array<string, true>
 */
function collectTypeVars(Type $type, array $acc): array
{
    if ($type instanceof TVar) {
        $acc[$type->name] = true;

        return $acc;
    }

    if ($type instanceof TCon) {
        foreach ($type->args as $arg) {
            $acc = collectTypeVars($arg, $acc);
        }

        return $acc;
    }

    if ($type instanceof TArrow) {
        return collectTypeVars($type->to, collectTypeVars($type->from, $acc));
    }

    return $acc;
}

function schemeRuntimeArity(Scheme $scheme): int
{
    return schemeTypeArity($scheme) + $scheme->runtimeConstraintCount;
}

function schemeTypeArity(Scheme $scheme): int
{
    $type = $scheme->type;
    $arity = 0;

    while ($type instanceof TArrow) {
        ++$arity;
        $type = $type->to;
    }

    return $arity;
}
