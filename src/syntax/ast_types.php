<?php declare(strict_types=1);

namespace Moggi\Syntax\Ast;

use Moggi\IR;
use Moggi\Modules\CheckedCore;
use Moggi\Modules\ImportInterface;
use Moggi\Modules\InstanceWorldId;
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
    /**
     * The checked term's type, in the checker's own algebra: the authority a
     * consumer reads.
     *
     * {@see $inferredType} is this type's projection onto source syntax, written
     * for tooling (hover, inlay hints, completion). Both halves are published
     * together — {@see \Moggi\Semantics\Types\publishInferredType()} — so a
     * reader never has to convert the projection back to recover the fact.
     */
    public ?Type $inferredInternalType = null;

    public ?TypeNode $inferredType = null;

    /** Doc comment attached by the parser (`-- |`, `{-|`, `-- ^`). */
    public ?string $doc = null;

    /**
     * Whether the doc came from a `-- ^` after the declaration rather than a `-- |` before it.
     *
     * The text alone does not say: a `-- ^` documents the construct it follows (`data Colour`'s last
     * constructor), so the formatter has to print it there and not above the declaration.
     */
    public bool $docIsTrailing = false;

    /**
     * The file this item was written in, when that is not the unit's own file: a
     * module with a backend half is one unit whose declarations come from two
     * files, and a diagnostic or a frame has to name the one it is written in.
     */
    public ?string $sourceFile = null;

    /**
     * The list comprehension this node was desugared from, when the source wrote one.
     *
     * `[e | p <- xs, g]` is sugar for a `concatMap`/`case` tree, which is the only shape the checker
     * and the backends see. Printing that tree back would turn every comprehension into a call to a
     * function the module may not even have in scope, so the parser keeps the parts here for the
     * formatter: `['expr' => e, 'qualifiers' => [QualGen|QualGuard, …]]`, null for a list written as
     * a list. The marker repeats children the desugared node already holds, so `childValues()` skips
     * it: walking it would infer the same qualifier twice.
     *
     * It is private because not every walk goes through `childValues()`: the strict-IO pass and the
     * LSP's source walks iterate `get_object_vars()`, and a public copy of the same children made
     * each qualifier count twice. Only the formatter reads it, through `listComp()`.
     *
     * @var null|array{expr: AstNode, qualifiers: list<AstNode>}
     */
    private ?array $listComp = null;

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
        $values = \get_object_vars($this);
        unset($values['listComp']);

        return \array_values($values);
    }

    /** The comprehension this node was desugared from, or null for a node the source did not write as one. */
    public function listComp(): ?array
    {
        return $this->listComp;
    }

    /** @param array{expr: AstNode, qualifiers: list<AstNode>} $comp */
    public function setListComp(array $comp): void
    {
        $this->listComp = $comp;
    }

    /** Module part of a resolved `Module::name` symbol, or null. */
    protected function moduleOfSymbol(?string $symbol): ?string
    {
        return $symbol !== null && \str_contains($symbol, '::') ? \explode('::', $symbol, 2)[0] : null;
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
        public ?string $doc = null,
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
        public bool $infixDefinition = false,
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
     *   sublist: a list of names, `'every'` for `C(..)`, and `null` for a plain
     *   name (which brings a class's methods along, as importing a class does).
     */
    public function __construct(
        public string $name,
        public ?string $asName = null,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
        public array|string|null $methods = null,
        /** Written `pattern P`: the item names a pattern synonym, not a value. */
        public bool $pattern = false,
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

// --- Top-level / declarations -------------------------------------------------

final class Program extends AstNode
{
    /**
     * @param array<int, AstNode> $items
     * @param list<ImportDecl> $imports
     * @param ?list<array{tag: string, name: string, path?: list<string>, children?: array{mode: string, names: list<string>}}> $exports
     * @param array<string, string> $constructorRenames
     * @param list<array<string, mixed>> $instanceEvidence
     * @param array<string, string> $backendMap
     * @param array<string, true> $language LANGUAGE pragma flags (e.g. NoImplicitPrelude)
     */
    public function __construct(
        public array $items,
        public ?string $module = null,
        public array $imports = [],
        public ?array $exports = null,

        /**
         * What the compilation published to this module: the imported ABI, one value
         * (`Modules\ImportInterface`). Null only on a program that was parsed and not
         * checked — lowering requires it.
         */
        public ?ImportInterface $interface = null,
        public array $constructorRenames = [],
        public array $instanceEvidence = [],
        public ?string $moduleBackend = null,
        public array $backendMap = [],
        public array $exportedInferredSchemes = [],
        /** True when the header was omitted and synthesized as `module Main(main) where`. */
        public bool $implicitMain = false,
        public array $language = [],
        public ?string $moduleDoc = null,
        /** @var list<array{doc: string, operators: list<string>, assoc: string, prec: int}> */
        public array $fixityDocs = [],
        /**
         * Every fixity declaration the source wrote, for the formatter.
         *
         * `fixityDocs` keeps only the ones carrying a doc comment, because the docs index is what
         * reads them. The printer needs all of them: reprinting a module must not drop its
         * `infixl`/`infixr`/`infix` lines.
         *
         * @var list<array{operators: list<string>, assoc: string, precedence: int}>
         */
        public array $fixityDecls = [],
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    /**
     * The closed checked product for this module (see `Modules\CheckedCore`).
     *
     * Set by the check that produced this program, and carried inside every
     * module product that the check publishes, so a module read back from the
     * cache lowers from the same record. Null on a program nobody checked
     * (parsing and dumping stop before this point); lowering refuses a category
     * function without its record instead of falling back to the mutable tree.
     */
    public ?CheckedCore $core = null;

    /**
     * The instance world this module was checked against, as an identity rather
     * than a copy of its records (see `Modules\InstanceWorldId`).
     *
     * Set by the check that produced this program, so a module product says
     * which project-wide instance set its schemes mean — including one read back
     * from the cache, which records the world it was checked against rather than
     * the one that happens to be current. Null only on a program nobody checked.
     */
    public ?InstanceWorldId $instanceWorld = null;

    /**
     * Every name the export list re-exports: a plain value or type export, and
     * the explicitly listed children of a type (`Bool(.., True)`).
     */
    public function nameRefs(): array
    {
        $refs = [];
        foreach ($this->exports ?? [] as $export) {
            if (($export['tag'] ?? '') === 'module') {
                continue;
            }
            $names = [$export['name'] ?? null, ...($export['children']['names'] ?? [])];
            foreach ($names as $name) {
                if (\is_string($name)) {
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
            if (($export['tag'] ?? '') === 'module' && \is_array($path)) {
                $modules[] = \implode('.', $path);
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

/**
 * A type synonym, or -- with `isFamily` -- a closed type family.
 *
 * A family has no single expansion, so its `type` is null and its equations
 * carry the expansions: each one matches a family application whose arguments
 * look like `lhsArgs` and stands for `rhs`. A family's parameters are the
 * `familyParams`, which keep their kind annotations (a synonym's own
 * parameters are bare names).
 */
final class TypeSynonymDecl extends AstNode
{
    /**
     * @param list<string> $params
     * @param list<DataParam> $familyParams
     * @param list<array{lhs: TypeNode, rhs: TypeNode}> $familyEquations
     */
    public function __construct(
        public string $name,
        public ?TypeNode $type,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
        public array $params = [],
        public bool $isFamily = false,
        public array $familyParams = [],
        public ?KindNode $familyResultKind = null,
        public array $familyEquations = [],
    ) {
        parent::__construct($line, $col, $endCol);
    }
}

/**
 * `pattern Nil = []`, `pattern Cons x xs = x : xs`, or a signature.
 *
 * The declaration is a matcher pattern over its parameters; it has no runtime
 * representation of its own.
 */
final class PatternSynonymDecl extends AstNode
{
    /** @param list<string> $params */
    public function __construct(
        public string $name,
        public array $params,
        public ?AstNode $matcher,
        public ?TypeNode $signature = null,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
        public ?AstNode $builder = null,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    /** Whether the source wrote `=` (bidirectional) rather than `<-` (unidirectional). */
    public bool $bidirectional = false;

    public function nameRefs(): array
    {
        return [['name' => $this->name, 'qualifier' => null]];
    }
}

/**
 * `type role T phantom representational` -- the role each of a type's parameters
 * plays in its representation, written rather than inferred.
 *
 * A declaration can only strengthen what inference finds: a parameter a field
 * carries may be declared representational or nominal, never phantom, because
 * two values with different representations would then be coerced to each other.
 * A declaration with no role in it is `type role T`, which says nothing about
 * a parameter and means the inferred roles stay as they are.
 */
final class RoleDecl extends AstNode
{
    /** @param list<string> $roles one role per parameter, in declaration order */
    public function __construct(
        public string $name,
        public array $roles,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
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
        /**
         * A GADT-style constructor's signature, `C :: Int -> T Int`, exactly as
         * written (a leading `forall` and context included). `null` for the
         * ordinary `C Int` form, whose field types say everything.
         */
        public ?TypeNode $signature = null,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    /**
     * The constructor's fields as the checked declaration registered them, in
     * runtime order.
     *
     * A GADT constructor writes its fields in its signature, so `$fields` is
     * empty for it; the checker resolves the signature into field types (and
     * prepends the context's dictionary fields) and records them here. Lowering
     * reads this, so the runtime constructor has the arity its construction and
     * pattern matching were elaborated at.
     *
     * @var ?list<string>
     */
    public ?array $resolvedFieldNames = null;

    /** Whether this constructor declares its type with `::` (GADT syntax). */
    public function isGadt(): bool
    {
        return $this->signature !== null;
    }

    /**
     * The constructor's runtime fields: the resolved ones when the checker
     * recorded them, otherwise the field list as written.
     *
     * @return list<string>
     */
    public function runtimeFieldNames(): array
    {
        if ($this->resolvedFieldNames !== null) {
            return $this->resolvedFieldNames;
        }

        return \array_map(static fn (CtorField $field): string => $field->name, $this->fields);
    }

    /**
     * How many fields the constructor has at runtime.
     *
     * A GADT constructor a module published but this process has not checked
     * has no resolved field list yet; its signature still states the arity, so
     * the count comes from there — one field per arrow past the quantifier and
     * context, plus one stored dictionary per constraint. Publishing the arity
     * is what lets another module *call* the constructor: a wrong zero would
     * make `MkGad 3` a call of a nullary constructor as a function value.
     */
    public function runtimeFieldCount(): int
    {
        if ($this->resolvedFieldNames === null && $this->signature !== null) {
            return \count($this->fieldTypeAsts()) + $this->contextConstraintCount();
        }

        return \count($this->runtimeFieldNames());
    }

    /** How many constraints the constructor's context carries (each one a stored dictionary). */
    public function contextConstraintCount(): int
    {
        $node = $this->signature;
        while ($node instanceof TypeForall) {
            $node = $node->body;
        }

        return $node instanceof TypeConstrained ? \count($node->constraints) : 0;
    }

    /**
     * The field types the constructor declares, in order.
     *
     * For the `C Int Bool` form that is the field list itself. A signature is
     * peeled the way a function type is: past its quantifier and context, then
     * one type per arrow, with the last type standing as the result.
     *
     * @return list<TypeNode>
     */
    public function fieldTypeAsts(): array
    {
        if ($this->signature === null) {
            return \array_map(static fn (CtorField $field): TypeNode => $field->type, $this->fields);
        }

        $types = [];
        $node = $this->signatureBodyAst();
        while ($node instanceof TypeArrow) {
            $types[] = $node->from;
            $node = $node->to;
        }

        return $types;
    }

    /**
     * The data type a GADT constructor returns, `T Int` for `C :: Int -> T Int`;
     * `null` for an ordinary constructor, whose result is the datatype applied to
     * its own parameters.
     */
    public function resultTypeAst(): ?TypeNode
    {
        if ($this->signature === null) {
            return null;
        }

        $node = $this->signatureBodyAst();
        while ($node instanceof TypeArrow) {
            $node = $node->to;
        }

        return $node;
    }

    /** The signature with its quantifier(s) and context peeled off. */
    private function signatureBodyAst(): TypeNode
    {
        $node = $this->signature;
        while ($node instanceof TypeConstrained) {
            $node = $node->body;
        }
        while ($node instanceof TypeForall) {
            $node = $node->body;
        }

        return $node;
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
     * declaration is checked like one that spells its context out.
     */
    public ?TypeNode $inferredSignatureType = null;

    public ?string $intrinsicWrapper = null;

    /**
     * Where the declaration is written when that is not the node's own span.
     *
     * An instance method synthesized from a class default has the class's body,
     * not this module's: without this, the record it publishes would name a line
     * of the wrong file. `module:line:col`, exactly the shape the record uses.
     */
    public ?string $sourceOrigin = null;

    /** Set by validateEntryPoint (Main) or the REPL session compiler (ReplExpression). */
    public ?IR\EntryPointKind $entryKind = null;

    public bool $export = false;

    public IR\ActionConvention $actionConvention = IR\ActionConvention::Executes;

    /**
     * True once `checkFunction` has filled `$type` in from the inferred body type.
     *
     * A module is inferred more than once per process and the preliminary pass sees no
     * imported values, so its inference is not a source of truth; `hasDeclaredSignature`
     * uses this to tell a user-written signature from an inferred one.
     */
    public bool $typeInferred = false;

    /**
     * The clauses the source wrote, kept for the formatter.
     *
     * A multi-clause function (`f 0 = …` / `f n = …`) is merged into one declaration whose body is
     * a `case` over the parameters, because that is the shape the checker and the backends work
     * with. Printing that `case` back would rewrite two clauses into one definition, so the parser
     * records the original clauses here: a list of `['params' => list<AstNode>, 'body' => AstNode]`,
     * or null when the declaration had a single clause and needs no such record.
     *
     * @var null|list<array{params: array<int, AstNode>, body: AstNode}>
     */
    public ?array $clauses = null;

    /**
     * True when the source wrote the binding between its two operands (`xs <+> ys = …`).
     *
     * The tree keeps only the name and the parameters, which is the same shape a prefix definition
     * (`(<+>) xs ys = …`) produces. The formatter needs the spelling to give it back, so the parser
     * records which one the source used. Kept after clause merging: it belongs to the declaration.
     */
    public bool $infixDefinition = false;
}

/**
 * Whether the function carries a signature the user actually wrote.
 *
 * `$type` alone cannot answer this: `checkFunction` overwrites it with the resolved
 * inferred type, so every later "is this annotated?" check must use this instead.
 */
function hasDeclaredSignature(FunctionDecl $fn): bool
{
    return $fn->type !== null && ! $fn->typeInferred;
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
     * @param ?string $minimalPragma the pragma as written, minus its delimiters;
     *   the printer writes it back and the checker reads `minimalGroups`
     * @param array{types: int, methods: int} $minimalPragmaAfter how many associated
     *   types and methods preceded the pragma, so the printer can put it back there
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
        public ?string $minimalPragma = null,
        /** @var array{types: int, methods: int} */
        public array $minimalPragmaAfter = ['types' => 0, 'methods' => 0],
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

/**
 * An explicit universal quantifier: `forall a. a -> a`, `forall a b. ...`.
 *
 * The binders are a list because order is part of the syntax (source order is
 * what a reader and, later, visible type application mean by it). `kinds` is
 * parallel to `binders`; a null entry is an unannotated binder, whose kind is
 * inferred. A `forall` in constraint position is this same node: whether it
 * quantifies a type or a constraint is decided by where it appears, not by a
 * second syntactic form.
 */
final class TypeForall extends AstNode implements TypeNode
{
    /**
     * @param list<string> $binders
     * @param list<?KindNode> $kinds
     */
    public function __construct(
        public array $binders,
        public array $kinds,
        public TypeNode $body,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    public function childValues(): array
    {
        return [...\array_values(\array_filter($this->kinds)), $this->body];
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

// --- Expressions --------------------------------------------------------------

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
    /**
     * The literal as the source spelled it (`1.50`, `1e-3`).
     *
     * `$value` cannot round-trip: `1.50` and `1.5` are the same float, so printing the value would
     * reformat a literal the author wrote. The formatter prints this instead when it is present.
     */
    public ?string $lexeme = null;

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

    /** True when this ref belongs to a copied default body and must keep its declaring module. */
    public bool $declarationScoped = false;

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

    /** True when this ref belongs to a copied default body and must keep its declaring module. */
    public bool $declarationScoped = false;

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

    /**
     * The enum range this application was built from, when the source wrote `[a .. b]`.
     *
     * A range is sugar for `enumFromTo a b` (or `enumFromThenTo`), which is the only shape the
     * checker sees. Printing that back would turn `[1 .. 10]` into `enumFromTo 1 10`, so the sugar
     * records its parts here for the formatter: `['kind' => 'to', 'from' => …, 'to' => …]` or
     * `['kind' => 'then-to', 'from' => …, 'then' => …, 'to' => …]`.
     *
     * @var null|array{kind: string, from: AstNode, then?: AstNode, to: AstNode}
     */
    public ?array $enumForm = null;

    public function __construct(
        public AstNode $function,
        public AstNode $argument,
        int $line = 0,
        int $col = 0,
        int $endCol = 0,
    ) {
        parent::__construct($line, $col, $endCol);
    }

    /**
     * The real children: `enumForm` records sugar and repeats these two nodes, so walking it would
     * infer the same operand twice and publish a term type naming an already-solved variable.
     */
    public function childValues(): array
    {
        return [$this->function, $this->argument];
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
        public bool $breakAfterOperator = false,
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

    /**
     * Whether this lambda is how the parser spells an operator section.
     *
     * `(* 2)` becomes `\__section -> __section * 2` so precedence inside the section is the
     * parser's. The formatter rebuilds the section from the flag rather than printing the lambda.
     */
    public bool $section = false;
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

    /**
     * The `if`/`then`/`else` this case was built from, when the source wrote one.
     *
     * `if` is sugar for a two-alternative case over its condition, and that is the only shape the
     * checker and backends want. A formatter has to print back what the author wrote, so the parser
     * keeps the three parts here: `['cond' => …, 'then' => …, 'else' => …]`, and null for a `case`
     * the source spelled as a `case`.
     *
     * @var null|array{cond: AstNode, then: AstNode, else: AstNode}
     */
    public ?array $ifParts = null;

    /**
     * Whether the checker proves this `case` total. A generated record selector
     * takes its receiver apart by the constructor the field belongs to and is
     * partial when the type has other constructors, so its case opts out: the
     * receiver of the wrong shape is a match failure at run time, not a
     * compile error in a declaration the user did not write.
     */
    public bool $exhaustiveCheck = true;

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

    /**
     * The real children: `ifParts` repeats the condition and both branches (which `alts` already
     * holds), so walking it would infer those subtrees twice and publish a term type naming an
     * already-solved variable.
     */
    public function childValues(): array
    {
        return [$this->scrutinee, $this->alts];
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
     * @param ?string $role the role the method's own declaration implies
     *        ({@see \Moggi\Semantics\Kernel}), recorded when this node was built. A reader that asks
     *        what a resolved method is *for* reads this, never the spelling.
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
        public ?string $role = null,
        public ?int $methodArity = null,
        public ?PendingConstraint $classObligation = null,
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

/**
 * One of the four expressions a `do` statement (or a `do` body) can be.
 *
 * The marker is the single answer to "may a form have been published for this node?": the checker
 * fills `$ioForm` on every node carrying it ({@see \Moggi\Semantics\IoEscape\analyze}), and the IR
 * spine reads that field instead of asking what the node's class is.
 */
interface IoStatementExpression
{
}

/**
 * What the checker publishes on an IO statement's node: the two answers lowering used to re-derive.
 *
 * A statement *expression* (`IoPure`, `IoCase`, `IoSequence`, `IoAction`) carries `$ioForm` — run or
 * value. A `do` statement (`IoLet`, `IoLetAction`, `IoBind`, `IoExpr`) carries `$ioKind` — what it does
 * with its expression. Both are written once, where the type and the `let`-bound actions in scope are
 * both known ({@see \Moggi\Semantics\IoEscape}), and read once, by lowering. One carrier, because
 * they are one question asked at two levels of the same construct.
 */
trait PublishesIoForm
{
    public ?\Moggi\IR\IoStatementForm $ioForm = null;

    public ?\Moggi\IR\IoStatementKind $ioKind = null;
}


final class IoAction extends AstNode implements IoStatementExpression
{
    use PublishesIoForm;

    public function __construct(public AstNode $expr, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoSequence extends AstNode implements IoStatementExpression
{
    use PublishesIoForm;

    /** @param list<AstNode> $stmts */
    public function __construct(public array $stmts, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoLet extends AstNode
{
    use PublishesIoForm;


    /** @param list<Binding> $bindings */
    public function __construct(public array $bindings, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoLetAction extends AstNode
{
    use PublishesIoForm;


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
    use PublishesIoForm;


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
    use PublishesIoForm;


    public function __construct(public AstNode $expr, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoPure extends AstNode implements IoStatementExpression
{
    use PublishesIoForm;

    public function __construct(public AstNode $expr, int $line = 0, int $col = 0, int $endCol = 0)
    {
        parent::__construct($line, $col, $endCol);
    }
}

final class IoCase extends AstNode implements IoStatementExpression
{
    use PublishesIoForm;

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

// --- Patterns -----------------------------------------------------------------

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

/**
 * `(expr -> pattern)`: apply `expr` to the scrutinee and match the result.
 *
 * The expression is written in the scope around the pattern, so it may read
 * the function's parameters; the pattern binds the names of the matched
 * result.
 */
final class PatView extends AstNode
{
    public function __construct(
        public AstNode $expression,
        public AstNode $pattern,
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

// --- Surface type AST (distinct from `Moggi\Semantics\TypeExpr\Type`) ---------

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

    /**
     * Whether the source wrote a tuple type `(a, b)`.
     *
     * It parses as `Tuple2 a b`, which is what the checker wants; a type written as
     * `Tuple2 a b` must keep printing that way, so the sugar is recorded rather than inferred.
     */
    public bool $tupleSugar = false;

    /**
     * Whether the source wrote this application infix (`f :+: g`).
     *
     * Prefix application of a symbolic constructor (`(:*:) f g`) parses to the same shape, so the
     * spelling is recorded rather than inferred from the head.
     */
    public bool $infixSpelling = false;
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

// --- Kind AST -----------------------------------------------------------------

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
