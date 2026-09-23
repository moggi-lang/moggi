<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\IntrinsicRegistry\standaloneOperatorSchemes;

final class TypeCheckState
{
    /**
     * Span of the top-level declaration currently being checked.
     *
     * Diagnostics raised from declaration-level checks often have no node of their own to point at —
     * a duplicate constructor, a constraint arity error, a desugared match that exists only in the
     * IR-shaped AST. `typeFail` falls back to this span so such a diagnostic still points at a
     * source line instead of rendering against line 0.
     *
     * @var array{line: int, col: int, endCol: int}
     */
    public array $declSpan = ['line' => 0, 'col' => 0, 'endCol' => 0];

    /** @var array<string, Type> */
    public array $subst = [];

    /** @var array<string, Scheme> */
    public array $env = [];

    /**
     * Registered data types, keyed by type-constructor name. `result` is the
     * type constructor applied to its parameters; the remaining keys are set
     * by whichever registration path created the entry (a declared `data`, a
     * bootstrapped primitive, or a `foreign type`).
     *
     * @var array<string, array{
     *   params: list<string>,
     *   result: Type,
     *   constructors: array<string, array<string, mixed>>,
     *   paramKinds?: array<string, Kinds\Kind>,
     *   newtype?: bool,
     *   primitive?: true,
     *   foreign?: array{backend: string, hostType: string},
     *   decl?: Ast\DataDecl
     * }>
     */
    public array $data = [];

    /**
     * Type synonyms: name → `{params: list<string>, rhs: TypeNode}`.
     *
     * @var array<string, array{params: list<string>, rhs: Ast\TypeNode}>
     */
    public array $typeSynonyms = [];

    /** @var array<string, string> */
    public array $qualifiedModules = [];

    /** @var array<string, array<string, array{module: string, namespace: string, phpName: string}>> */
    public array $qualifiedOrigins = [];

    /**
     * Schemes for `import M as P` names, keyed by prefix then name.
     * Kept separate from `$env` so local bindings that shadow an imported
     * name (e.g. NonEmpty.span vs Data.List.span) do not change `P.name`.
     *
     * @var array<string, array<string, Scheme>>
     */
    public array $qualifiedEnv = [];

    /** @var array<string, mixed> */
    public array $intrinsicWrappers = [];

    /**
     * Declaring module's own scope, per module that declares classes with
     * default method bodies.
     *
     * A default body is re-checked at every instance site, in the instance's
     * module; it must resolve names the way it did where it was written.
     *
     * @var array<string, array<string, Scheme>>
     */
    public array $classModuleScopes = [];

    /** @var array<string, string> */
    public array $constructorRenames = [];

    /** @var array<string, array{params: list<array<string, mixed>>, methods: array<string, array<string, mixed>>, superclasses: list<Ast\AstNode>, associatedTypes?: array<string, array<string, mixed>>}> */
    public array $classes = [];

    /**
     * Associated type families keyed by family name in the current scope.
     * Identity is conceptually (ClassId, familyName); ClassId ≈ class name
     * in-scope (import resolution uniquifies class names).
     *
     * @var array<string, array{class: string, params: list<string>, resultKind: Kinds\Kind, kind: Kinds\Kind}>
     */
    public array $associatedFamilies = [];

    /**
     * Associated type equations indexed by class then family name.
     *
     * @var array<string, array<string, list<array{lhsArgs: list<Ast\TypeNode>, rhs: Ast\TypeNode, module: string}>>>
     */
    public array $associatedEquations = [];

    /** @var array<string, true> */
    public array $instanceMethodNames = [];

    /**
     * Names two imports brought in as different entities.
     *
     * Importing them is legal; naming one is not, so the error is raised where
     * the name is used or re-exported, not where it was imported.
     *
     * @var array<string, array{origins: list<string>}>
     */
    public array $ambiguousImports = [];

    /**
     * Value names this module declares itself.
     *
     * An instance in the module provides a specialized scheme under each class
     * method's name, and that must not take the slot of a declaration the module
     * already has: `Data.Map`'s `toList :: Map k v -> [(k, v)]` is its own
     * function, not the `Foldable (Map k)` instance's `Map k a -> [a]`.
     *
     * @var array<string, true>
     */
    public array $localDeclNames = [];

    /** @var array<string, Kinds\Kind> */
    public array $kindEnv = [];

    /**
     * DataKinds: promoted data constructors keyed by name.
     * - Nullary: `{data: string}` — kind is the parent datatype.
     * - With fields: `{data: string, argKinds: list<Kind>}` — unsaturated kind is
     *   `argKinds[0] -> … -> data` (e.g. InfixI :: Associativity -> FixityI).
     *
     * @var array<string, array{data: string, argKinds?: list<Kinds\Kind>}>
     */
    public array $promoted = [];

    /** @var array<string, Kinds\Kind|null> */
    public array $varKinds = [];

    /**
     * Kind-variable substitution for the current elaboration scope
     * (type apps, signatures, data/class registration).
     *
     * @var array<string, Kinds\Kind>
     */
    public array $kindSubst = [];

    /** Fresh counter for internal kind variables (`k0`, `k1`, …). */
    public int $kindFresh = 0;

    /** @var list<array{evidenceName: string, methods: array<string, string>, headAst: Ast\TypeNode, className: string, contextParams: list<string>}> */
    public array $instanceEvidence = [];

    /**
     * Instance methods that may need IR-name uniquification when several
     * instances in the same module define the same surface method (e.g. `==`).
     *
     * @var list<array{fn: Ast\FunctionDecl, evidenceIndex: int, surfaceName: string}>
     */
    public array $instanceMethodsPendingUniq = [];

    /** @var array<string, array<string, mixed>> */
    public array $constraintMethods = [];

    /** @var array<string, true> */
    public array $constraintMethodAmbiguities = [];

    /**
     * The dictionaries an ambiguous method name could come from, in the order
     * they were registered.
     *
     * `negate` while both `Num a` and `Num b` are in scope cannot be resolved by
     * name, and is still only resolvable where the argument says which one is
     * meant. Keeping the candidates is what lets that use site decide.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public array $constraintMethodCandidates = [];

    /** @var array<string, string> */
    public array $classMethodOwners = [];

    /** @var array<string, true> */
    public array $classMethodAmbiguities = [];

    /** @var array<string, bool> */
    public array $instanceLookupCache = [];

    /**
     * Heads that do not match an instance, keyed by class + head shape. The key
     * normalizes type variables away, so only the negative answer is reusable:
     * a mapping binds the head's own variables, and those differ per use.
     *
     * @var array<string, null>
     */
    public array $instanceMappingCache = [];

    /** @var list<array<string, Type>> */
    public array $substStack = [];

    /** @var list<Ast\PendingConstraint> */
    public array $ambientConstraints = [];

    /** @var array<string, list<Ast\PendingConstraint>> */
    public array $ambientConstraintsByClass = [];

    /** @var (callable(Type, Type): string)|null */
    public mixed $unifyMessage = null;

    public ?string $programModuleBackend = null;

    /** @var array<string, true> */
    public array $declaredTypeNames = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $projectInstancesByClass = [];

    /** @var array<string, array<string, list<array<string, mixed>>>> */
    public array $projectInstancesByClassHead = [];

    public ?string $currentModule = null;

    /** @var list<array{class: string, head: Type, module: string}> */
    public array $checkedInstances = [];

    /**
     * When true, instance method checking prefers polymorphic class-method
     * dispatch (pending constraints + solver) over ambient constraintMethods
     * projections and monomorphic self-binding. Used by stock deriving so
     * field `(==)` calls require `Eq τ` via the solver.
     */
    public bool $stockDeriving = false;

    /**
     * Head type of the instance whose method bodies are being checked.
     *
     * An operand at that type is the instance's own value, so a method the
     * instance provides has to dispatch through the instance's own dictionary:
     * `(/=) x y = case x == y of …` compares `Pair a` with `Eq (Pair a)`, and a
     * host operator would compare the representation and ignore the instance.
     */
    public ?Type $instanceHeadInScope = null;

    /**
     * Method names the instance in scope provides, including its superclasses'
     * methods: exactly the references that instance's own dictionary can answer.
     *
     * @var array<string, true>
     */
    public array $instanceOwnMethods = [];

    /** @var list<Ast\ExprHole> */
    public array $holes = [];

    /**
     * Type variables that belong to a restricted (monomorphic) declaration.
     *
     * base settles such a declaration at the end of the module, so a use site
     * anywhere may still pin one of its variables: `n = 1 + 1` is an `Int` when
     * a later `m :: Int; m = n` says so.
     *
     * @var array<string, true>
     */
    public array $restrictedVars = [];

    /**
     * Names in `env` that hold a compiler fallback from
     * {@see standaloneOperatorSchemes()}
     * rather than a declaration in scope. A module that imports the class
     * declaring the operator replaces the fallback with the real scheme, so
     * `+` is `Num`'s method in a module that imports `Data.Num` and stays the
     * Int fallback in a script that imports nothing.
     *
     * @var array<string, true>
     */
    public array $standaloneEnvNames = [];

    /** @var array<string, Type> how a use site solved a restricted variable */
    public array $restrictedSolutions = [];

    /** @var list<array<string, mixed>> restricted declarations waiting for the module end */
    public array $deferredRestricted = [];

    /**
     * Settle a restricted declaration for its *type* only, leaving its body
     * alone.
     *
     * A module's environment is built before any of its bodies are checked for
     * real, and the declarations it cannot finish yet are settled there so the
     * published environment has their defaulted types. That settle is only
     * provisional: the module is checked again afterwards, with every use site
     * of it in scope (`m = n :: Int` pins `n = 1 + 1` to `Int`), and the second
     * pass is the one that has to elaborate the body. Rewriting the body here
     * would leave the literal at the type this earlier pass defaulted it to,
     * and the second pass would have nothing left to re-infer.
     */
    public bool $provisionalRestrictedSettle = false;

    /**
     * Imported (and re-exported) value names → `Module::name` from codegen.
     *
     * @var array<string, string>
     */
    public array $externalFns = [];

    /** Fresh counter for local binder ids (LSP rename/refs). */
    public int $nextBinderId = 0;

    /**
     * Fresh counter for synthesized dictionary-parameter names on local
     * bindings. Must be unique across the whole compilation unit: two in-scope
     * evidence parameters sharing a name would shadow each other's dictionary.
     */
    public int $nextEvidenceParam = 0;

    public function __construct(
        public string $source,
        public string $filename = '',
        public int $fresh = 0,
    ) {
    }
}
