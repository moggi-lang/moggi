<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Semantics\Kinds;

use function Moggi\Semantics\Kinds\classParamKind;

use Moggi\Semantics\Types\TypeCheckState;

use Moggi\Syntax\Ast;

/**
 * The class facts a deriving-strategy decision needs, from whichever view of the
 * class is in scope.
 *
 * Two questions decide a strategy before any method is derived: does the class
 * have associated types (then newtype deriving has no underlying instance to
 * delegate to), and does its single parameter take one type argument (then the
 * instance is over the declaration with its last parameter dropped). Both are
 * facts about the class's *declaration* -- `class Generic a where type Rep a ::
 * Type` says the first, `map :: (a -> b) -> (f a) -> (f b)` says the second --
 * so they can be read from the parse tree as well as from the registry entry a
 * type check leaves behind.
 *
 * That is what lets the stub phase stop naming classes. It builds the project's
 * instance records before any type checking has run, so it has no class table:
 * it reads the declarations instead, and the two views agree because a parameter
 * applied to one type argument is higher-kinded by construction.
 */
final class class_facts
{
    /**
     * @param callable(string): ?array<string, mixed> $entry the class's registry-shaped facts, or null
     * @param ?TypeCheckState $state the state the entries came from, when there is one -- it is what
     *        resolves a parameter's declared kind; without it the entry's own kind is used
     */
    public function __construct(
        private $entry,
        private ?TypeCheckState $state = null,
    ) {
    }

    /** The facts of a type-check state's class table. */
    public static function fromState(TypeCheckState $state): self
    {
        return new self(
            static fn (string $className): ?array => $state->classes[$className] ?? null,
            $state,
        );
    }

    /**
     * The facts of parsed class declarations: the parse tree's view of the same
     * two questions, for a phase that has never run a type check.
     *
     * @param list<Ast\ClassDecl> $declarations
     */
    public static function fromClassDeclarations(array $declarations): self
    {
        $byName = [];
        foreach ($declarations as $declaration) {
            $byName[$declaration->name] ??= $declaration;
        }

        return new self(static fn (string $className): ?array => isset($byName[$className])
            ? declaredClassFacts($byName[$className])
            : null);
    }

    /**
     * Whether the class forces structural deriving: an associated type means
     * there is no hidden representation to reuse, so `deriving newtype` has
     * nothing to delegate to.
     */
    public function refusesNewtypeDeriving(string $className): bool
    {
        return (($this->entry)($className)['associatedTypes'] ?? []) !== [];
    }

    /**
     * The class's own associated types, by name.
     *
     * The class that declares `type Rep a :: Type -> Type` is the one whose
     * project-instance stub needs a `Rep` equation, so reading the family name
     * here keeps it a fact of the declaration rather than a name the deriving
     * framework spells for itself.
     *
     * @return list<string>
     */
    public function associatedTypeNames(string $className): array
    {
        return \array_keys(($this->entry)($className)['associatedTypes'] ?? []);
    }

    /**
     * Whether the class's parameter is a type constructor of one argument --
     * `Functor`, `Foldable`, `Traversable`, and any class written the same way.
     */
    public function takesOneTypeArgument(string $className): bool
    {
        $entry = ($this->entry)($className);
        if ($entry === null) {
            return false;
        }
        $params = $entry['params'] ?? [];
        if (\count($params) !== 1) {
            return false;
        }
        $kind = $this->state !== null
            ? classParamKind($this->state, $params[0])
            : ($params[0]['resolvedKind'] ?? null);

        return $kind instanceof Kinds\KArrow && $kind->to instanceof Kinds\KType;
    }
}

/**
 * The entry shape a type check builds, from the declaration alone: the parameter
 * kinds the signatures imply and the associated types the class writes.
 *
 * @return array{params: list<array{name: string, resolvedKind: ?Kinds\Kind}>, associatedTypes: array<string, true>}
 */
function declaredClassFacts(Ast\ClassDecl $decl): array
{
    $params = [];
    foreach ($decl->params as $param) {
        $params[] = [
            'name' => $param->name,
            'resolvedKind' => declaredParamKind($decl, $param->name),
        ];
    }

    $associatedTypes = [];
    foreach ($decl->associatedTypes as $associated) {
        $associatedTypes[$associated->name] = true;
    }

    return ['params' => $params, 'associatedTypes' => $associatedTypes];
}

/**
 * The kind a class parameter's own uses imply, or null when no method applies it
 * (the parameter used as a type, at kind `Type`).
 *
 * A parameter that appears applied at all is a type constructor, and the widest
 * application in the class's signatures gives it its arity -- `f a` is
 * `* -> *`, `p a b` is `* -> * -> *`.
 */
function declaredParamKind(Ast\ClassDecl $decl, string $param): ?Kinds\Kind
{
    $arity = 0;
    foreach ($decl->methods as $method) {
        $arity = \max($arity, appliedParamArity($method->type, $param));
    }
    if ($arity === 0) {
        return null;
    }

    $kind = new Kinds\KType();
    for ($i = 0; $i < $arity; ++$i) {
        $kind = new Kinds\KArrow(new Kinds\KType(), $kind);
    }

    return $kind;
}

/**
 * The widest `$param` application in a declared type: `f a` is 1, `p a b` is 2,
 * none is 0.
 *
 * A signature's application is flat (`parseTypeApplication` merges an applied
 * head's arguments into one node), so `f a b` is a single `TypeApp` whose head is
 * the parameter.
 */
function appliedParamArity(Ast\TypeNode $type, string $param): int
{
    if ($type instanceof Ast\TypeApp) {
        $widest = $type->con instanceof Ast\TypeVar && $type->con->name === $param
            ? \count($type->args)
            : 0;
        foreach ($type->args as $arg) {
            $widest = \max($widest, appliedParamArity($arg, $param));
        }

        return $widest;
    }

    if ($type instanceof Ast\TypeArrow) {
        return \max(
            appliedParamArity($type->from, $param),
            appliedParamArity($type->to, $param),
        );
    }

    if ($type instanceof Ast\TypeConstrained) {
        $widest = appliedParamArity($type->body, $param);
        foreach ($type->constraints as $constraint) {
            $widest = \max($widest, appliedParamArity($constraint, $param));
        }

        return $widest;
    }

    return 0;
}
