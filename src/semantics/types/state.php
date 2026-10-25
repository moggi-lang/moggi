<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Modules\InstanceWorld;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

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

    /**
     * The backend this module is being checked for. A foreign import or type
     * names one backend; it is active only when that is this one.
     */
    public string $compileBackend = 'php';

    /**
     * Entry modules of the compilation this check belongs to.
     *
     * @var array<string, true>
     */
    public array $entryModules = [];

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

    /**
     * Module whose default body is being checked, and the names that body must
     * resolve there.
     *
     * A default body is copied into the instance's AST, but its references
     * belong to the declaring module. Recording that here lets name resolution
     * write the declaring module into `Variable::$resolvedOrigin`, which lowering
     * follows instead of the instance module's same-named binding.
     *
     * @var array<string, string> name => declaring module
     */
    public array $defaultBodyOrigins = [];

    /** @var array<string, string> */
    public array $constructorRenames = [];

    /** @var array<string, array{params: list<array<string, mixed>>, methods: array<string, array<string, mixed>>, superclasses: list<Ast\AstNode>, associatedTypes?: array<string, array<string, mixed>>}> */
    public array $classes = [];

    /**
     * `class name => declared parameter kinds`, derived from {@see $classes}.
     *
     * A kind walk asks for the declared parameter kinds of a class named by a
     * constraint, and a walk builds a context at every step of the way -- once
     * per type argument of every signature the module mentions. Reading them off
     * the classes each time made that a walk over the whole class table (every
     * class the library declares) instead of a lookup, so it is computed once per
     * class table and dropped whenever `$classes` changes.
     *
     * @var ?array<string, list<Kinds\Kind>>
     */
    public ?array $classParamKindsCache = null;

    /**
     * `role id => the method that fulfils it`, derived from {@see $classes}.
     *
     * The roles cost a walk over the class table to find, and a literal or a `do` block asks on every
     * occurrence, so the answer is computed once and dropped whenever `$classes` changes (the same
     * shape as {@see $classParamKindsCache}).
     *
     * @var ?array{methods: array<string, string>, classes: array<string, string>}
     */
    public ?array $roleMethodsCache = null;

    /**
     * Operand types an infix rewrite hands to the call path, keyed by node id.
     *
     * `inferInfix`'s fall-through infers both operands before it can tell whether the infix is a
     * call to an instance method, and the rewrite that follows goes through `inferApply`, which
     * would infer both of them again. See {@see inferRewrittenInfixFromOperands}. Entries live only
     * across that one call: the rewrite sets them and `inferApply` consumes them.
     *
     * @var array<int, Type>
     */
    public array $knownOperandTypes = [];

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

    /**
     * The instance world this module is checked against — the prepared
     * project's, or the module's own at the single-module boundary.
     *
     * Installed by `applyImportContext` from the context's named world, and the
     * one producer of the two indexes below. The checked program publishes its
     * identity; nothing downstream re-derives it.
     */
    public ?InstanceWorld $instanceWorld = null;

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
     * Rigid (skolem) type variables: the fresh skolems a declared signature's
     * variables become while the body they quantify is checked. A rigid
     * variable unifies only with itself; it never takes a substitution, so a
     * body cannot pin a signature variable to a concrete type or merge two
     * of them. A flexible variable may still be bound *to* a rigid one (that
     * is how parameters take their types). Installed per declaration around
     * body checking and restored after; each skolem's name carries its
     * source spelling for diagnostics (see `skolemTypeVarBase`).
     *
     * @var array<string, true>
     */
    public array $rigidVars = [];

    /**
     * Source spelling → skolem name for the signature variables a body is
     * checked against. Type variables are global by spelling: a nested
     * annotation writing the same name means the same variable, and evidence
     * lookup matches it by pruned name. Renaming only the declaration's own
     * signature would sever that sharing (a nested `Num a` would no longer
     * meet the outer `Num a$sk`), so while the scope is installed every
     * spelling parsed from annotations resolves to the one skolem — the
     * sharing stays exactly as without skolems, only rigid. A nested
     * declaration with its own signature overwrites the entries it binds
     * (lexical shadowing) and restores them after. Installed per declaration
     * around body checking and restored after, together with {@see $rigidVars}.
     *
     * @var array<string, string>
     */
    public array $skolemScope = [];

    /** @var array<string, Type> how a use site solved a restricted variable */
    public array $restrictedSolutions = [];

    /**
     * Steps that settled an obligation without unification, in the order they
     * ran: the checked core publishes them as residuals with their provenance.
     *
     * `decl` is the owning declaration's object id, the same key
     * {@see $checkedSchemes} uses, or null when the pass ran with no owner. A
     * span would be the wrong key: declarations the checker synthesized share
     * `0:0`, and two nodes can share one span.
     *
     * @var list<array{decl: ?int, wanted: string, decision: string, provenance: string}>
     */
    public array $defaultingLog = [];

    /**
     * The scheme this check settled, per checked declaration (`spl_object_id`
     * of the `FunctionDecl`), for the duration of one check.
     *
     * `env` is keyed by name and a later writer can take the name over — a
     * module that declares a value a class method already has (`Data.Map.null`
     * beside `Foldable.null`) and then checks an instance of that class finds
     * the instance method's scheme under `null` at the end. A product that
     * publishes a declaration's checked answer reads this map, not `env`.
     *
     * @var array<int, Scheme>
     */
    public array $checkedSchemes = [];

    /**
     * The kinds this check fixed for each checked declaration's own type parameters, per
     * `spl_object_id` of the `FunctionDecl`.
     *
     * Captured while the declaration's kind scope is live: `Kinds\pushKindScope()` restores it once
     * the signature is done, so by the end of the check the scope no longer says whose kinds these
     * were. The checked core publishes the map; nothing else reads it.
     *
     * @var array<int, array<string, string>>
     */
    public array $checkedParameterKinds = [];


    /** @var list<array<string, mixed>> restricted declarations waiting for the module end */
    public array $deferredRestricted = [];

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
        string $compileBackend = 'php',
        array $entryModules = [],
    ) {
        $this->compileBackend = $compileBackend;
        $this->entryModules = $entryModules;
    }
}
