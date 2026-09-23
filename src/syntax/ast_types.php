<?php declare(strict_types=1);

namespace Moggi\Syntax\Ast;

use Moggi\IR;
use Moggi\Semantics\TypeExpr\Type;

/** Surface-syntax type AST (distinct from `Moggi\Semantics\TypeExpr\Type`). */
interface TypeNode
{
}

/** Surface-syntax kind AST. */
interface KindNode
{
}

/**
 * A value a name-reference walk can visit: an AST node, or one of the
 * node-adjacent value objects that hold nodes without being nodes themselves
 * (`Alt`, `PatField`, `CtorField`, `RecordField`, `ClassMethodSig`, `DataParam`,
 * `PendingConstraint`). Each value says what it refers to and what to visit
 * next, so a walk never has to ask a value what class it is.
 */
interface AstWalkable
{
    /**
     * Names this value refers to, each with the qualifier it was written
     * through: `M.x` reports name `x` with qualifier `M`, a bare `x` reports
     * `null`. Declarations and binders refer to nothing and report `[]`.
     *
     * @return list<array{name: string, qualifier: ?string}>
     */
    public function nameRefs(): array;

    /** Module a reference was resolved to during type checking, or null. */
    public function resolvedModule(): ?string;

    /**
     * Modules re-exported wholesale by this value (`module M (module Data.Bool)`).
     *
     * @return list<string> full module paths
     */
    public function reExportedModules(): array;

    /**
     * Values to visit next: nodes, and the value objects above.
     *
     * @return list<mixed>
     */
    public function childValues(): array;
}

/**
 * Defaults for the node-adjacent value objects: they hold children but name
 * nothing themselves, except where a subclass overrides `nameRefs()`.
 */
abstract class AstValue implements AstWalkable
{
    public function nameRefs(): array
    {
        return [];
    }

    public function resolvedModule(): ?string
    {
        return null;
    }

    public function reExportedModules(): array
    {
        return [];
    }
}

/**
 * A type-class constraint carried through inference: pending on an expression,
 * ambient in a function body, or attached to a function/scheme.
 */
final class PendingConstraint extends AstValue
{
    /**
     * @param list<Type> $args
     * @param ?TypeNode $instanceHeadAst surface AST used for implicit IO evidence naming
     */
    public function __construct(
        public string $class,
        public array $args,
        public string $evidence = '',
        public bool $implicit = false,
        public ?TypeNode $instanceHeadAst = null,
    ) {
    }

    /** The constraint names its class. */
    public function nameRefs(): array
    {
        return [['name' => $this->class, 'qualifier' => null]];
    }

    public function childValues(): array
    {
        return [$this->instanceHeadAst];
    }
}

abstract class AstNode implements AstWalkable
{
    public ?TypeNode $inferredType = null;

    /** Doc comment attached by the parser (`-- |`, `{-|`, `-- ^`). */
    public ?string $doc = null;

    /** @var list<PendingConstraint> */
    public array $pendingConstraints = [];

    public function __construct(
        public int $line = 0,
        public int $col = 0,
        public int $endCol = 0,
        public int $endLine = 0,
    ) {
        if ($this->endLine === 0 && $this->line !== 0) {
            $this->endLine = $this->line;
        }
    }

    public function setLocation(int $line, int $col, int $endCol, int $endLine = 0): void
    {
        $this->line = $line;
        $this->col = $col;
        $this->endCol = $endCol;
        $this->endLine = $endLine > 0 ? $endLine : $line;
    }

    /**
     * Names this node refers to, each with the qualifier it was written
     * through: `M.x` reports name `x` with qualifier `M`, a bare `x` reports
     * `null`. Tools that map names back to imports walk the tree and ask every
     * node, so the nodes themselves declare what they reference.
     *
     * @return list<array{name: string, qualifier: ?string}>
     */
    public function nameRefs(): array
    {
        return [];
    }

    /** Module a reference was resolved to during type checking, or null. */
    public function resolvedModule(): ?string
    {
        return null;
    }

    /**
     * Modules re-exported wholesale by this node (`module M (module Data.Bool)`).
     *
     * @return list<string> full module paths
     */
    public function reExportedModules(): array
    {
        return [];
    }

    /**
     * Every value held in a public property. The walk filters these down to the
     * `AstWalkable` ones, so semantic types, environments and plain scalars are
     * skipped by the value that consumes them.
     */
    public function childValues(): array
    {
        return array_values(get_object_vars($this));
    }

    /** Module part of a resolved `Module::name` symbol, or null. */
    protected function moduleOfSymbol(?string $symbol): ?string
    {
        return $symbol !== null && str_contains($symbol, '::') ? explode('::', $symbol, 2)[0] : null;
    }
}

/** A `data` type parameter: bare `c` (kind inferred) or kind-annotated `(c :: Color)`. */
final class DataParam extends AstValue
{
    public function __construct(
        public string $name,
        public KindNode $kind,
    ) {
    }

    public function childValues(): array
    {
        return [$this->kind];
    }
}

final class ClassParam extends AstNode
{
    public function __construct(
        public string $name,
        public KindNode $kind,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class CtorField extends AstValue
{
    /** Span of the field *label* in a record declaration; see `RecordField`. */
    public function __construct(
        public string $name,
        public TypeNode $type,
        public int $line = 0,
        public int $col = 0,
        public int $endCol = 0,
    ) {
    }

    public function childValues(): array
    {
        return [$this->type];
    }
}

final class ClassMethodSig
{
    /**
     * @param array<int, AstNode> $params Default-implementation binders (empty when
     *   the class only declares the signature).
     * @param ?AstNode $body Default-implementation body; `null` means the method
     *   has no default and every instance must implement it.
     */
    public function __construct(
        public string $name,
        public TypeNode $type,
        public ?string $doc = null,
        public array $params = [],
        public ?AstNode $body = null,
    ) {
    }

    public function childValues(): array
    {
        return [$this->type, $this->params, $this->body];
    }
}

/**
 * Class-associated type family declaration: `type Rep a :: Type -> Type`.
 * Identity is (ClassId, name); ClassId ≈ class name in-scope for now.
 *
 * @param list<string> $params binder names (arity of the family)
 */
final class AssociatedTypeDecl extends AstNode
{
    public function __construct(
        public string $name,
        public array $params = [],
        public ?KindNode $resultKind = null,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

/**
 * Instance equation for an associated type: `type Rep (Maybe a) = …`.
 *
 * @param list<TypeNode> $lhsArgs arguments after the family name
 */
final class AssociatedTypeEquation extends AstNode
{
    public function __construct(
        public string $name,
        public array $lhsArgs,
        public TypeNode $rhs,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class RecordField extends AstValue
{
    /**
     * Span of the field *label* (not of the whole `name = expr`), so a tool can
     * point at the name the reader typed. Value objects carry no positions
     * otherwise; a bare record field is the one place a name has its own token.
     */
    public function __construct(
        public string $name,
        public AstNode $expr,
        public int $line = 0,
        public int $col = 0,
        public int $endCol = 0,
    ) {
    }

    /** A field label names the record selector it belongs to. */
    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }

    public function childValues(): array
    {
        return [$this->expr];
    }
}

final class PatField extends AstValue
{
    /** Span of the field *label* in a record pattern; see `RecordField`. */
    public function __construct(
        public string $name,
        public AstNode $pattern,
        public int $line = 0,
        public int $col = 0,
        public int $endCol = 0,
    ) {
    }

    /** A field label names the record selector it belongs to. */
    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }

    public function childValues(): array
    {
        return [$this->pattern];
    }
}

final class ImportItem extends AstNode
{
    /**
     * @param array<int, string>|string|null $methods The class's method
     *   sublist: a list of names, `'all'` for `C(..)`, and `null` for a plain
     *   name (which brings a class's methods along, as importing a class does).
     */
    public function __construct(
        public string $name,
        public ?string $asName = null,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
        public array|string|null $methods = null,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class Binding extends AstNode
{
    /**
     * True when the binding is written `f p = e` -- arguments on the left
     * rather than a pattern. The monomorphism restriction turns on this
     * syntactic distinction: a *pattern* binding (`x = e`, `f = \p -> e`,
     * `(a, b) = e`) keeps its constrained type variables monomorphic, while a
     * function binding generalizes them.
     */
    public bool $functionBinding = false;

    public function __construct(
        public AstNode $pattern,
        public AstNode $value,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class Alt extends AstValue
{
    public function __construct(
        public AstNode $pattern,
        public AstNode $body,
    ) {
    }

    public function childValues(): array
    {
        return [$this->pattern, $this->body];
    }
}

final class Guarded extends AstNode
{
    public function __construct(
        public AstNode $guard,
        public AstNode $body,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class LambdaParam extends AstNode
{
    public function __construct(
        public AstNode $pattern,
        public ?TypeNode $type = null,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

// ---------------------------------------------------------------------------
// Top-level / declarations
// ---------------------------------------------------------------------------

final class Program extends AstNode
{
    /**
     * @param array<int, AstNode> $items
     * @param list<ImportDecl> $imports
     * @param ?list<array{tag: string, name: string, path?: list<string>, children?: array{mode: string, names: list<string>}}> $exports
     * @param array<string, string> $externalFns
     * @param array<string, string> $constructorRenames
     * @param list<array<string, mixed>> $instanceEvidence
     * @param array<string, int> $externalFnRuntimeArity
     * @param array<string, true> $externalActionReturnFns imported functions whose body is a boxed action
     * @param array<string, string> $backendMap
     * @param array<string, true> $language LANGUAGE pragma flags (e.g. NoImplicitPrelude)
     */
    public function __construct(
        public array $items,
        public ?string $module = null,
        public array $imports = [],
        public ?array $exports = null,
        public array $externalFns = [],
        public array $constructorRenames = [],
        public array $instanceEvidence = [],
        public array $externalFnRuntimeArity = [],
        public ?string $moduleBackend = null,
        public array $backendMap = [],
        public array $exportedInferredSchemes = [],
        /** True when the header was omitted and synthesized as `module Main(main) where`. */
        public bool $implicitMain = false,
        public array $language = [],
        public ?string $moduleDoc = null,
        /** @var list<array{doc: string, operators: list<string>, assoc: string, prec: int}> */
        public array $fixityDocs = [],

        /** Imported names whose body is an action value; callers must run the box. */
        public array $externalActionReturnFns = [],
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    /**
     * Every name the export list re-exports: a plain value or type export, and
     * the explicitly listed children of a type (`Bool(.., True)`).
     */
    public function nameRefs(): array
    {
        $refs = [];
        foreach ($this->exports ?? [] as $export) {
            if (($export['tag'] ?? '') === 'module') {
                continue; // A module re-export is not a name; see reExportedModules().
            }
            $names = [$export['name'] ?? null, ...($export['children']['names'] ?? [])];
            foreach ($names as $name) {
                if (is_string($name)) {
                    $refs[] = ['name' => $name, 'qualifier' => null];
                }
            }
        }

        return $refs;
    }

    public function reExportedModules(): array
    {
        $modules = [];
        foreach ($this->exports ?? [] as $export) {
            $path = $export['path'] ?? null;
            if (($export['tag'] ?? '') === 'module' && is_array($path)) {
                $modules[] = implode('.', $path);
            }
        }

        return $modules;
    }
}

/** A class name in a `deriving (Eq, …)` clause, with source location. */
final class DerivingClassRef extends AstNode
{
    /**
     * @param 'stock'|'newtype'|'anyclass'|'via'|null $strategy Explicit deriving strategy
     *   (`null` = default resolution: prefer stock, else newtype on `newtype`).
     * @param ?TypeNode $viaType The via type when strategy is 'via'.
     */
    public function __construct(
        public string $name,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
        public ?string $strategy = null,
        public ?TypeNode $viaType = null,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }
}

/**
 * Standalone deriving declaration: `deriving instance Eq a => Eq (Foo a)`
 * or `deriving via (Sum Int) instance Monoid T`.
 */
final class StandaloneDerivingDecl extends AstNode
{
    /**
     * @param 'stock'|'newtype'|'anyclass'|'via'|null $strategy
     * @param ?TypeNode $viaType The via type when strategy is 'via'.
     * @param list<TypeNode> $constraints
     */
    public function __construct(
        public ?string $strategy,
        public ?TypeNode $viaType,
        public string $className,
        public TypeNode $head,
        public array $constraints = [],
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->className, 'qualifier' => null]];
    }
}

final class DataDecl extends AstNode
{
    /**
     * @param array<int, DataParam> $params
     * @param array<int, ConstructorDecl> $constructors
     * @param list<DerivingClassRef> $derivingClasses
     */
    public function __construct(
        public string $name,
        public array $params,
        public array $constructors,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
        /** True for `newtype` declarations. */
        public bool $isNewtype = false,
        public array $derivingClasses = [],
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class TypeSynonymDecl extends AstNode
{
    /**
     * @param list<string> $params
     */
    public function __construct(
        public string $name,
        public TypeNode $type,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
        public array $params = [],
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class ConstructorDecl extends AstNode
{
    /**
     * @param list<CtorField> $fields
     * @param 'infixl'|'infixr'|'infix'|null $fixityAssoc fixity for operator
     *   constructors (`null` = no operator name). Used by stock `deriving
     *   Generic` for MetaCons FixityI.
     * @param ?int $fixityPrec the operator's precedence, `null` for a
     *   non-operator name.
     * @param bool $declaredInfix `a :| [a]` declares the constructor infix; the
     *   parenthesized `(:|) a [a]` form declares the same operator prefix, and
     *   the two render differently. Stock `deriving Show` only uses the infix
     *   form for the former.
     */
    public function __construct(
        public string $name,
        public array $fields,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
        public ?string $fixityAssoc = null,
        public ?int $fixityPrec = null,
        public bool $declaredInfix = false,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    /** True when every field is a named record field. */
    public function isRecord(): bool
    {
        return $this->fields !== [] && $this->fields[0]->name !== '';
    }
}

final class FunctionDecl extends AstNode
{
    /**
     * @param ?TypeNode $type
     * @param array<int, AstNode> $params
     */
    public function __construct(
        public string $name,
        public ?TypeNode $type,
        public array $params,
        public AstNode $body,
        public bool $signatureOnly = false,
        public bool $foreign = false,
        public bool $instanceMethod = false,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    /** @var list<PendingConstraint> */
    public array $constraints = [];

    /**
     * The signature `checkFunction` inferred for a declaration that has none,
     * from the constraints its body turned out to need. Once set, the
     * declaration is checked like one that spells its context out, and a later
     * check of the same AST (modules with exported inferred functions are
     * type-checked twice) reuses this signature instead of re-deriving it -- by
     * then the body's dictionary projections are already in place.
     */
    public ?TypeNode $inferredSignatureType = null;

    /**
     * Set once the body has been checked against `inferredSignatureType`. A
     * later check of the same declaration reuses the scheme instead of inferring
     * the body again, which would add a second copy of the dictionaries the
     * first check already placed at the call sites.
     */
    public bool $inferredSignatureChecked = false;

    public ?string $intrinsicWrapper = null;

    /** Set by validateEntryPoint (Main) or the REPL session compiler (ReplExpression). */
    public ?IR\EntryPointKind $entryKind = null;

    public bool $export = false;

    public IR\IoBodyKind $ioBodyKind = IR\IoBodyKind::StraightLine;

    /**
     * True once `checkFunction` has filled `$type` in from the inferred body type.
     *
     * A module is inferred more than once per process and the preliminary pass sees no
     * imported values, so its inference is not a source of truth; `hasDeclaredSignature`
     * uses this to tell a user-written signature from an inferred one.
     */
    public bool $typeInferred = false;
}

/**
 * Whether the function carries a signature the user actually wrote.
 *
 * `$type` alone cannot answer this: `checkFunction` overwrites it with the resolved
 * inferred type, so every later "is this annotated?" check must use this instead.
 */
function hasDeclaredSignature(FunctionDecl $fn): bool
{
    return $fn->type !== null && !$fn->typeInferred;
}

final class ClassDecl extends AstNode
{
    /**
     * @param array<int, ClassParam> $params
     * @param list<ClassMethodSig> $methods
     * @param list<TypeNode> $superclasses
     * @param list<AssociatedTypeDecl> $associatedTypes
     * @param list<list<string>> $minimalGroups `{-# MINIMAL … #-}` alternatives;
     *   an instance has to implement every method of at least one group. Empty
     *   when the class has no pragma, and then every method without a default
     *   is what an instance must implement.
     */
    public function __construct(
        public string $name,
        public array $params,
        public array $methods,
        public array $superclasses = [],
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
        public array $associatedTypes = [],
        public array $minimalGroups = [],
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class InstanceDecl extends AstNode
{
    /**
     * @param list<FunctionDecl> $methods
     * @param list<TypeNode> $constraints
     * @param list<AssociatedTypeEquation> $associatedEquations
     */
    public function __construct(
        public string $class,
        public TypeNode $head,
        public array $methods,
        public array $constraints = [],
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
        public array $associatedEquations = [],
    ) {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->class, 'qualifier' => null]];
    }
}

final class TypeKindAnnot extends AstNode implements TypeNode
{
    public function __construct(
        public string $name,
        public KindNode $kind,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class ForeignTypeDecl extends AstNode
{
    public function __construct(
        public string $backend,
        public string $name,
        public string $hostType,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class ForeignImportDecl extends AstNode
{
    public function __construct(
        public string $backend,
        public string $kind,
        public string $name,
        public string $path,
        public TypeNode $type,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class ImportDecl extends AstNode
{
    public function __construct(
        public array $path,
        public string $kind,
        public array $items = [],
        public array $hiding = [],
        public ?string $asName = null,
        public bool $implicit = false,
        /** When true (`import M qualified [as A]`), names bind only under the qualifier. */
        public bool $qualifiedOnly = false,
        public ?string $doc = null,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class SignatureOnly extends AstNode
{
    public function __construct(
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

// ---------------------------------------------------------------------------
// Expressions
// ---------------------------------------------------------------------------

final class IntegerLit extends AstNode
{
    /**
     * The decimal digits as written, with their sign, when they do not fit the
     * host `int`. `$value` is then the clamped reading the module sees if it
     * pins the literal to `Int`; a type that can hold the whole number is built
     * from these digits instead.
     */
    public ?string $digits = null;

    public function __construct(public int $value, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class DoubleLit extends AstNode
{
    public function __construct(public float $value, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class StringLit extends AstNode
{
    public function __construct(public string $value, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

/** Character literal; value is a Unicode scalar (int). */
final class CharLit extends AstNode
{
    public function __construct(public int $value, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class Variable extends AstNode
{
    public ?string $intrinsicWrapper = null;

    /** Resolved `Module::name` for imported (or local) value refs; set during typecheck. */
    public ?string $resolvedOrigin = null;

    /** Local binder identity (same-file rename/refs); set during typecheck. */
    public ?int $binderId = null;

    public function __construct(public string $name, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }

    public function resolvedModule(): ?string
    {
        return $this->moduleOfSymbol($this->resolvedOrigin);
    }
}

final class ConstructorRef extends AstNode
{
    /** Resolved `Module::name` when known. */
    public ?string $resolvedOrigin = null;

    public function __construct(public string $name, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }

    public function resolvedModule(): ?string
    {
        return $this->moduleOfSymbol($this->resolvedOrigin);
    }
}

final class OperatorRef extends AstNode
{
    /** Resolved `Module::name` when known. */
    public ?string $resolvedOrigin = null;

    public function __construct(public string $name, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }

    public function resolvedModule(): ?string
    {
        return $this->moduleOfSymbol($this->resolvedOrigin);
    }
}

final class Apply extends AstNode
{
    public ?string $intrinsicWrapper = null;

    public ?string $intrinsicId = null;

    public function __construct(
        public AstNode $function,
        public AstNode $argument,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class Infix extends AstNode
{
    public ?string $resolvedIntrinsic = null;

    /**
     * An infix the compiler built itself, already resolved to an intrinsic.
     *
     * Unlike {@see self::$resolvedIntrinsic} this is set before inference, so the
     * operator never has to be in scope: a literal pattern's conjunction is
     * `boolAnd#` and is built where the module's `&&` need not be visible.
     */
    public ?string $compilerIntrinsic = null;

    public function __construct(
        public string $operator,
        public AstNode $left,
        public AstNode $right,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class Tuple extends AstNode
{
    /** @param array<int, AstNode> $elements */
    public function __construct(public array $elements, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class ListLit extends AstNode
{
    /** @param array<int, AstNode> $elements */
    public function __construct(public array $elements, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class Lambda extends AstNode
{
    /**
     * Class constraints the type checker abstracted into this lambda's leading
     * dictionary parameters. Empty for a source-level lambda; set only on the
     * synthetic lambda that implements a constrained let/where binding, so a
     * re-check of the same AST recognises it instead of wrapping it again.
     *
     * @var list<PendingConstraint>
     */
    public array $abstractedConstraints = [];

    /**
     * The scheme a binding group was generalized to when it was abstracted.
     *
     * A group of recursive bindings is abstracted over its dictionaries only
     * once, but the same AST is inferred again on every later run, and the
     * obligations it carries were read off the bodies of that first run. Its
     * variables are renamed to names no inference run allocates, so a later run
     * cannot alias them with variables of its own; the recorded scheme is what a
     * later run installs.
     *
     * @var array{type: TypeNode, bound: list<string>, constraints: list<array{class: string, args: list<TypeNode>}>}|null
     */
    public ?array $abstractedScheme = null;

    /** @param array<int, LambdaParam> $params */
    public function __construct(
        public array $params,
        public AstNode $body,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class Let extends AstNode
{
    /**
     * @param array<int, Binding> $bindings
     * @param bool $generalizeBindings false for `let` desugared from a `do`
     *   statement, whose bindings are typed monomorphically so their type is
     *   fixed by the surrounding effect context (e.g. `IO`).
     */
    public function __construct(
        public array $bindings,
        public AstNode $body,
        public bool $generalizeBindings = true,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class Where extends AstNode
{
    /** @param array<int, Binding> $bindings */
    public function __construct(
        public AstNode $expr,
        public array $bindings,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class CaseExpr extends AstNode
{
    public bool $exhaustive = false;

    /** @param array<int, Alt> $alts */
    public function __construct(
        public AstNode $scrutinee,
        public array $alts,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class GuardsExpr extends AstNode
{
    /** @param array<int, Guarded> $clauses */
    public function __construct(public array $clauses, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class DoExpr extends AstNode
{
    public ?AstNode $desugared = null;

    /** @param list<AstNode> $stmts */
    public function __construct(public array $stmts, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class DoBind extends AstNode
{
    public function __construct(
        public AstNode $pattern,
        public AstNode $expr,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class DoLet extends AstNode
{
    /** @param array<int, DoBind> $bindings */
    public function __construct(public array $bindings, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class DoExprStmt extends AstNode
{
    public function __construct(public AstNode $expr, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class QualGen extends AstNode
{
    public function __construct(
        public AstNode $pattern,
        public AstNode $expr,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class QualGuard extends AstNode
{
    public function __construct(public AstNode $expr, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class TypeAsc extends AstNode
{
    public function __construct(
        public AstNode $expr,
        public TypeNode $type,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

/** Typed hole: bare `_` in expression position. */
final class ExprHole extends AstNode
{
    public function __construct(int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IntrinsicCall extends AstNode
{
    public ?string $intrinsicId = null;

    /** @param array<int, AstNode> $args */
    public function __construct(
        public string $name,
        public array $args,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class QualifiedRef extends AstNode
{
    public ?string $backendResolved = null;
    public ?string $intrinsicWrapper = null;

    public function __construct(
        public string $module,
        public string $name,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => $this->module]];
    }

    public function resolvedModule(): ?string
    {
        return $this->moduleOfSymbol($this->backendResolved);
    }
}

final class EvidenceRef extends AstNode
{
    /**
     * @param list<AstNode> $context leading dictionaries for `C a => C (F a)` instances
     */
    public function __construct(
        public string $class,
        public TypeNode $head,
        public array $context = [],
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

/** Created during type inference by `applyEvidenceMethodInfo` (was in-place tag rewrite). */
final class EvidenceMethod extends AstNode
{
    /**
     * @param list<AstNode> $contextEvidence leading dicts for constrained instances
     *        (e.g. `Bounded Int` when projecting `maxBound` from `Bounded (Min Int)`)
     */
    public function __construct(
        public string $class,
        public string $method,
        public string $evidence,
        public bool $methodNullary = false,
        public ?TypeNode $evidenceInstance = null,
        public array $contextEvidence = [],
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class ForeignCall extends AstNode
{
    /**
     * @param array<int, AstNode> $args
     * @param array<int, int> $handleUnboxArgs indexes of args passed as unboxed handles
     */
    public function __construct(
        public string $backend,
        public string $kind,
        public string $name,
        public string $path,
        public string $dispatch,
        public array $args,
        public ?string $classPath = null,
        public ?string $member = null,
        public IR\IoWrap $ioWrap = IR\IoWrap::None,
        public bool $phpValueBox = false,
        public bool $handleBox = false,
        public array $handleUnboxArgs = [],
        /** Inferred native calling signature (e.g. JVM method descriptor); not from source. */
        public ?string $nativeSig = null,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoAction extends AstNode
{
    public function __construct(public AstNode $expr, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoSequence extends AstNode
{
    /** @param list<AstNode> $stmts */
    public function __construct(public array $stmts, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoLet extends AstNode
{
    /** @param list<Binding> $bindings */
    public function __construct(public array $bindings, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoLetAction extends AstNode
{
    public bool $ioFoldHint = false;

    public function __construct(
        public AstNode $pattern,
        public AstNode $expr,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoBind extends AstNode
{
    public function __construct(
        public AstNode $pattern,
        public AstNode $expr,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoExpr extends AstNode
{
    public function __construct(public AstNode $expr, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoPure extends AstNode
{
    public function __construct(public AstNode $expr, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoCase extends AstNode
{
    /** @param list<Alt> $alts */
    public function __construct(
        public AstNode $scrutinee,
        public array $alts,
        public bool $exhaustive = false,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class RecordCon extends AstNode
{
    /** @param list<RecordField> $fields */
    public function __construct(
        public string $name,
        public array $fields,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class RecordUpdate extends AstNode
{
    /**
     * The constructor of the record being updated, filled in by the type checker
     * from the object's type: the update names fields, and only the record type
     * says which constructor holds them and in what order.
     */
    public ?string $constructor = null;

    /** True when the record is a `newtype`, whose update is the new field's value. */
    public bool $fieldOnNewtype = false;

    /** @param list<RecordField> $fields */
    public function __construct(
        public AstNode $object,
        public array $fields,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class FieldAccess extends AstNode
{
    /**
     * The field's position in its record, filled in by the type checker from the
     * receiver's type. Two records may declare the same field name at different
     * positions, so the name alone does not say where the value is; the
     * constructor name of the receiver's type does.
     */
    public ?int $fieldIndex = null;

    /** True when the field came from a `newtype` record, where reading it is identity. */
    public bool $fieldOnNewtype = false;

    public function __construct(
        public AstNode $object,
        public string $field,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

// ---------------------------------------------------------------------------
// Patterns
// ---------------------------------------------------------------------------

final class PatWild extends AstNode
{
    public function __construct(int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class PatVar extends AstNode
{
    /** Local binder identity shared with Variable uses of this binding. */
    public ?int $binderId = null;

    public function __construct(public string $name, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class PatLit extends AstNode
{
    /** The digits of a numeric pattern the host `int` cannot hold; @see IntegerLit::$digits. */
    public ?string $digits = null;

    public function __construct(public int|string $value, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

/** Character pattern; value is a Unicode scalar (int). */
final class PatChar extends AstNode
{
    public function __construct(public int $value, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class PatNil extends AstNode
{
    public function __construct(int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class PatCons extends AstNode
{
    public function __construct(
        public AstNode $head,
        public AstNode $tail,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class PatCon extends AstNode
{
    /** @param list<AstNode> $args constructor-pattern arguments */
    public function __construct(
        public string $name,
        public array $args,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }

    public function withArg(AstNode $arg): self
    {
        $node = clone $this;
        $node->args[] = $arg;
        return $node;
    }
}

final class PatTuple extends AstNode
{
    /** @param array<int, AstNode> $elements */
    public function __construct(public array $elements, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class PatRecord extends AstNode
{
    /** @param list<PatField> $fields */
    public function __construct(
        public string $name,
        public array $fields,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }
}

// ---------------------------------------------------------------------------
// Surface type AST (distinct from internal `Moggi\Semantics\TypeExpr\Type`)
// ---------------------------------------------------------------------------

final class TypeVar extends AstNode implements TypeNode
{
    public function __construct(public string $name, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class TypeCon extends AstNode implements TypeNode
{
    public function __construct(public string $name, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }
}

/** A promoted data constructor used as a type (DataKinds), e.g. `'Red`. */
final class TypePromoted extends AstNode implements TypeNode
{
    public function __construct(public string $name, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }
}

/** A string type literal of kind `Symbol` (DataKinds), e.g. `"hello"`. */
final class TypeStringLit extends AstNode implements TypeNode
{
    public function __construct(public string $value, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

/**
 * A numeric type literal of kind `Nat` (DataKinds), e.g. `1024`.
 *
 * `$digits` is the decimal digit lexeme (may include leading zeros). Negatives
 * are parsed for a precise type error and never elaborate to an internal Nat.
 */
final class TypeNatLit extends AstNode implements TypeNode
{
    public function __construct(
        public string $digits,
        public bool $negative = false,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class TypeUnit extends AstNode implements TypeNode
{
    public function __construct(int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class TypeApp extends AstNode implements TypeNode
{
    /**
     * @param list<TypeNode> $args
     */
    public function __construct(
        public TypeNode $con,
        public array $args,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class TypeArrow extends AstNode implements TypeNode
{
    public function __construct(
        public TypeNode $from,
        public TypeNode $to,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class TypeConstrained extends AstNode implements TypeNode
{
    /** @param list<TypeNode> $constraints */
    public function __construct(
        public array $constraints,
        public TypeNode $body,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

final class TypeQualified extends AstNode implements TypeNode
{
    public function __construct(
        public string $module,
        public string $name,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => $this->module]];
    }
}

// ---------------------------------------------------------------------------
// Kind AST
// ---------------------------------------------------------------------------

final class KindType extends AstNode implements KindNode
{
    public function __construct(int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class KindInfer extends AstNode implements KindNode
{
    public function __construct(int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

/** A named kind, e.g. `Color` in `data OnlyColor (c :: Color) = …` (DataKinds). */
final class KindCon extends AstNode implements KindNode
{
    public function __construct(public string $name, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class KindArrow extends AstNode implements KindNode
{
    public function __construct(
        public KindNode $from,
        public KindNode $to,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }
}
