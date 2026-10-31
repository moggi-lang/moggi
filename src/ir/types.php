<?php declare(strict_types=1);

namespace Moggi\IR;

use Moggi\Syntax\Ast;

/**
 * How a caller obtains a declaration's result.
 *
 * `ReturnsAction` is every Moggi-level entry whose result is `IO a`: it answers with an action
 * value, and `IoRun` executes exactly one layer of it. `Executes` is the FFI worker — a foreign
 * import — which performs the host call and answers with the value itself.
 *
 * Decided once, at the declaration, from its kind and its result type; every consumer reads the
 * decision instead of re-deriving it from a body, a module's suffix or an instance's status.
 */
enum ActionConvention: string
{
    case ReturnsAction = 'returns-action';
    case Executes = 'executes';
}

/**
 * What a `do` statement's expression is, decided by the checker and read once by lowering.
 *
 * This is the fact the IO spine used to re-derive with `instanceof` chains inside lowering: which
 * shape a statement has decides whether it is run, boxed, deferred through the action store or
 * evaluated as a value. Publishing it makes the question total — the producer throws on an IO shape
 * it does not know, so a new node class cannot reach lowering as an unclassified expression (a
 * nested `do` in bind position did exactly that and cost two passes).
 *
 * `Deferred` is a use of a binder that a `let` bound to an action; the set of such binders is
 * lexical, so the checker answers from the bindings it made and lowering from the entries its store
 * holds — the same rule, asked by the one producer.
 */
enum IoStatementForm: string
{
    /** `pure e`: a value to evaluate, never an action to run. */
    case Pure = 'pure';
    /** A `case` in a `do` block: a computation boxed or run in place. */
    case Case = 'case';
    /** A nested `do`: a computation boxed or run in place. */
    case Sequence = 'sequence';
    /** A variable bound by a `let` to an action: the store holds the action it defers. */
    case Deferred = 'deferred';
    /** Everything else: a call, a foreign op, a primop — the action ABI classifies it. */
    case Action = 'action';
}

/**
 * What a `do` statement does with its expression, decided by the checker and read once by lowering.
 *
 * The form above answers run-or-value, which is not the same question: `x <- e` and `let x = e` give
 * the same form for the same `e` while one runs it and binds the result and the other keeps it as an
 * action for a later use. So the spine used to ask the statement's *class* — the dispatch a new
 * statement shape had to be taught by hand. This is that question, published where the statement
 * list is walked and the `let`-bound actions in scope are known ({@see \Moggi\Semantics\IoEscape}),
 * so lowering dispatches on the answer instead of on a class.
 */
enum IoStatementKind: string
{
    /** `let …`: opens a binding group. */
    case Let = 'let';
    /** `let x = <action>`: binds an action for a later use, without running it. */
    case LetAction = 'let-action';
    /** `p <- <statement>`: runs the statement and binds its result to a pattern. */
    case Bind = 'bind';
    /** A bare statement: evaluate it, or run it for its effect. */
    case Expr = 'expr';
}

/** How a foreign call's `IO` result is unwrapped by the backend. */
enum IoWrap: string
{
    case None = 'none';
    case Either = 'either';
    case MaybeString = 'maybe_string';
}

/**
 * How a callable came to exist. Capture arity and identity follow this fact,
 * never the spelling of its name: every generated prefix is presentation.
 */
enum FunctionKind: string
{
    /** Declared at a module's top level: identity is `module + declared name`. */
    case Source = 'source';
    /** An anonymous `\x -> …` lowered to a lifted function: lowering-local identity. */
    case Lambda = 'lambda';
    /** A lifted recursive `let`/`where` group: lowering-local identity. */
    case Letrec = 'letrec';
    /** A specialization clone: identity is its declaration site's. */
    case SpecClone = 'spec-clone';
    /** A compiler-synthesized helper (CAF, …): no source identity. */
    case Generated = 'generated';

    /**
     * The direct half of a `ReturnsAction` entry: same parameter vector, performs the body and
     * answers with the value. Created by one producer (`IR\lower`), reached only through
     * `origin`, never published in a module interface.
     */
    case Worker = 'worker';

    /**
     * Whether the callable is invoked at its free locals followed by its
     * declared parameters, i.e. whether capture binding applies.
     */
    public function takesCaptures(): bool
    {
        return $this === self::Lambda || $this === self::Letrec;
    }

}

/**
 * Kind of module entry point. Backends bootstrap `Main` as an application
 * entry; `ReplExpression` is a callable eval thunk without auto-run.
 */
enum EntryPointKind: string
{
    case Main = 'main';
    case ReplExpression = 'repl-expression';
}

/** Named entry binding plus how backends should treat it. */
final class EntryPoint
{
    public function __construct(
        public string $name,
        public EntryPointKind $kind,
    ) {
    }
}

/**
 * Marker for any IR tree node (operands, statements, modules, blocks, patterns).
 *
 * Dispatch with `match ($node::class)`; nodes carry no discriminator field.
 */
interface IrNode
{
}

/** Operand/expression slot inside a statement or another operand. */
interface Operand extends IrNode
{
}

/** Top-level item inside a block body. */
interface Stmt extends IrNode
{
}

/** Match / bind pattern in IR. */
interface Pattern extends IrNode
{
}

/* ---------------------------------------------------------------------------
   Top-level / structural
   --------------------------------------------------------------------------- */

final class Module implements IrNode
{
    /**
     * @param list<FunctionDecl> $functions
     * @param list<DataDecl> $data
     * @param list<InstanceEvidence> $instanceEvidence
     * @param ?EntryPoint $entry application or REPL entry, when present
     */
    public function __construct(
        public array $functions,
        public array $data,
        public array $instanceEvidence = [],
        public ?EntryPoint $entry = null,
        public string $moduleName = '',
        public string $sourceFile = '',
    ) {
    }
}

/** Logical Moggi source location (exception context / debug). */
final class SrcLoc
{
    public function __construct(
        public string $module,
        public string $function,
        public string $file,
        public int $line,
        public int $col,
    ) {
    }
}

/**
 * @param list<FunctionDecl> $functions
 */
function entryFromFunctions(array $functions): ?EntryPoint
{
    foreach ($functions as $function) {
        if ($function->shape->entryKind !== null) {
            return new EntryPoint($function->name, $function->shape->entryKind);
        }
    }

    return null;
}

/** The evidence dictionary a single `instance` declaration lowers to. */
final class InstanceEvidence
{
    /**
     * @param array<string, string> $methods surface method name => IR function name
     * @param list<string> $contextParams evidence parameter names for instance context dicts
     */
    public function __construct(
        public string $evidenceName,
        public array $methods,
        public array $contextParams = [],
    ) {
    }
}

/**
 * The traits of an emitted declaration: how it is reached, how its body talks to the host, and where
 * it came from. Everything that is not its identity, signature, body or source site.
 *
 * These nine fields travel together — a rewrite copies the whole shape, never three of its flags — so
 * they are one record with one copy helper instead of nine constructor slots. A new trait lands in
 * this constructor (with a default), which is also the only place that has to change for it: no call
 * site passes the shape positionally, so no call site changes.
 */
final class FunctionShape
{
    /**
     * @param bool $export reachable from outside its module
     * @param bool $instanceMethod a class or instance method, not a top-level binding
     * @param ?EntryPointKind $entryKind how the runtime enters the program here, when it does
     * @param bool $foreign performs a host call rather than running a body of its own
     * @param ActionConvention $actionConvention whether the body runs, or answers with, its action
     * @param bool $ioEffect the body performs effects
     * @param bool $ioStraightLine the body allocates or runs no action of its own
     * @param FunctionKind $kind source, worker, lifted callable, specialization or generated stub
     * @param ?string $origin the declaration this function was cloned or lifted from
     */
    /** The traits this record carries, in constructor order. */
    private const TRAITS = [
        'export',
        'instanceMethod',
        'entryKind',
        'foreign',
        'actionConvention',
        'ioEffect',
        'ioStraightLine',
        'kind',
        'origin',
    ];

    /**
     * Every trait is `readonly`: a pass that wants to change one calls {@see with()} and adopts
     * the returned copy, so a shape held by a published `FunctionDecl` cannot change under a
     * reader that already looked at it. `FunctionDecl` itself stays mutable for `body`/`params`,
     * which rewriting passes replace by design; this record is the part that had no such need.
     */
    public function __construct(
        public readonly bool $export = false,
        public readonly bool $instanceMethod = false,
        public readonly ?EntryPointKind $entryKind = null,
        public readonly bool $foreign = false,
        public readonly ActionConvention $actionConvention = ActionConvention::Executes,
        public readonly bool $ioEffect = false,
        public readonly bool $ioStraightLine = false,
        public readonly FunctionKind $kind = FunctionKind::Source,
        public readonly ?string $origin = null,
    ) {
    }

    /**
     * A copy of this shape with the named traits replaced.
     *
     * A rewrite changes a trait or two; listing them by name reads better than re-passing nine
     * positionals, and a trait that does not exist is a caller error rather than a silent drop.
     * The copy is a new record rather than a clone-write, because the fields are `readonly`.
     *
     * @param array<string, mixed> $fields
     */
    public function with(array $fields): self
    {
        foreach (\array_keys($fields) as $field) {
            if (! \in_array($field, self::TRAITS, true)) {
                throw new \InvalidArgumentException("FunctionShape has no `{$field}` trait");
            }
        }

        return new self(...\array_merge(\get_object_vars($this), $fields));
    }
}

final class FunctionDecl implements IrNode
{
    /**
     * @param list<string> $params parameter slot names
     * @param ?Ast\TypeNode $type declared surface type, when the source had a signature
     * @param FunctionShape $shape the declaration's emitted traits
     * @param ?SrcLoc $srcLoc where the declaration is written, when that is not the body's span
     * @param list<string> $evidenceScope the evidence slots in scope where it was created, in order
     */
    public function __construct(
        public string $name,
        public array $params,
        public ?Ast\TypeNode $type,
        public Block $body,
        public FunctionShape $shape = new FunctionShape(),
        public ?SrcLoc $srcLoc = null,
        /**
         * The evidence slots in scope where this declaration was created, in definition order.
         *
         * For a source declaration that is its solved constraint list, so `E` is exactly the
         * slots the typechecker committed to, with the multiplicity it committed to. For a lifted
         * callable it is the enclosing scope's list, and the table splits the callable's capture
         * vector against it — the same vector the binder built, so the two cannot disagree.
         *
         * Never re-read from the spelling of `params`: a dictionary slot and a value parameter are
         * different layouts even though both are positional at the runtime.
         */
        public array $evidenceScope = [],
    ) {
    }

    public function withBody(Block $body): self
    {
        return $this->with(['body' => $body]);
    }

    /**
     * A copy of this declaration with the named fields replaced.
     *
     * A rewrite changes a field or two, so one copy helper reads the field list
     * from the object instead of re-listing all fifteen in every `with*`. A
     * property that does not exist is a caller error, not a silently dropped
     * field. A shape trait is addressed by its own name (`with(['ioEffect' => …])`),
     * so a rewrite of one trait does not have to build a whole new shape.
     *
     * @param array<string, mixed> $fields
     */
    public function with(array $fields): self
    {
        $copy = clone $this;
        $shape = [];
        foreach ($fields as $field => $value) {
            if ($field === 'shape') {
                $copy->shape = $value;

                continue;
            }
            if (\property_exists($copy->shape, $field)) {
                $shape[$field] = $value;

                continue;
            }
            if (! \property_exists($copy, $field)) {
                throw new \InvalidArgumentException("FunctionDecl has no `{$field}` field");
            }
            $copy->{$field} = $value;
        }
        if ($shape !== []) {
            $copy->shape = $copy->shape->with($shape);
        }

        return $copy;
    }
}

final class DataDecl implements IrNode
{
    /** @param list<string> $params @param list<DataConstructor> $constructors */
    public function __construct(
        public string $name,
        public array $params,
        public array $constructors,
        public bool $isNewtype = false,
    ) {
    }
}

final class DataConstructor
{
    /** @param list<string> $fields field names, positional fields included */
    public function __construct(
        public string $name,
        public array $fields,
    ) {
    }
}

final class Block implements IrNode
{
    /** @param list<Stmt> $items */
    public function __construct(public array $items)
    {
    }
}

/**
 * One guard of a match arm: `arm p when cond -> body`.
 *
 * A guard is tested right after the arm's pattern bindings, before its body, and
 * a guard that fails falls through to the next arm instead of taking the arm.
 * Guard expressions are not always operand-shaped (`f x | ok (g x) = …`), so the
 * statements that compute a guard travel with it: `prep` runs before `cond` is
 * tested, and only for the arm whose pattern matched.
 */
final class Guard implements IrNode
{
    /** @param list<Stmt> $prep */
    public function __construct(
        public Block $prep,
        public Operand $cond,
    ) {
    }

}

final class MatchArm implements IrNode
{
    /** @param list<Guard> $guards */
    public function __construct(
        public Pattern $pattern,
        public Block $body,
        public array $guards = [],
    ) {
    }

    public function withBody(Block $body): self
    {
        return new self($this->pattern, $body, $this->guards);
    }
}

/* ---------------------------------------------------------------------------
   Patterns
   --------------------------------------------------------------------------- */

final class PatWild implements Pattern
{
}

final class PatVar implements Pattern
{
    /**
     * @param ?int $binderId the checker's identity for this binder, when the pattern came from a
     *        checked tree; lowering binds the slot under that identity as well as under the name,
     *        so a use resolves to the binder it names rather than to a name that happens to be
     *        in scope
     */
    public function __construct(
        public string $name,
        public ?int $binderId = null,
    ) {
    }
}

final class PatLit implements Pattern
{
    public function __construct(public int|string $value)
    {
    }
}

final class PatChar implements Pattern
{
    public function __construct(public int $value)
    {
    }
}

final class PatNil implements Pattern
{
}

final class PatCons implements Pattern
{
    public function __construct(
        public Pattern $head,
        public Pattern $tail,
    ) {
    }
}

final class PatCon implements Pattern
{
    /** @param list<Pattern> $args */
    public function __construct(
        public string $name,
        public array $args,
    ) {
    }
}

final class PatTuple implements Pattern
{
    /** @param list<Pattern> $elements */
    public function __construct(public array $elements)
    {
    }
}

/* ---------------------------------------------------------------------------
   Operands
   --------------------------------------------------------------------------- */

final class ConstInt implements Operand
{
    public function __construct(public int $value)
    {
    }
}

final class ConstStr implements Operand
{
    public function __construct(public string $value)
    {
    }
}

/** Unicode scalar value (int). */
final class ConstChar implements Operand
{
    public function __construct(public int $value)
    {
    }
}

final class ConstDouble implements Operand
{
    public function __construct(public float $value)
    {
    }
}

final class Local implements Operand
{
    public function __construct(public string $name)
    {
    }
}

final class Temp implements Operand
{
    public function __construct(public int $id)
    {
    }
}

final class FnRef implements Operand
{
    /**
     * @param mixed $evidenceHead optional type-head metadata for evidence fn refs
     */
    public function __construct(
        public string $name,
        public ?string $evidenceClass = null,
        public mixed $evidenceHead = null,
    ) {
    }
}

final class ListLit implements Operand
{
    /** @param list<Operand> $elements */
    public function __construct(public array $elements)
    {
    }
}

final class Unit implements Operand
{
}

final class Partial implements Operand
{
    /**
     * A callable value with a prefix of its runtime slots already supplied.
     *
     * `$arity` is the entry's published runtime arity ({@see \Moggi\IR\Callables\CallableSig::runtimeArity()}),
     * so `count($args) <= $arity` always holds: a partial is under-applied by definition, and a
     * full application is a `Call`. The post-lower validator checks `$arity` against the table at
     * every seam; the two checks below are the shapes no later pass could make sense of.
     *
     * @param list<Operand> $args
     */
    public function __construct(
        public string $fn,
        public int $arity,
        public array $args,
    ) {
        if ($fn === '') {
            throw new \InvalidArgumentException('a partial must name the callable it holds');
        }
        if ($arity < \count($args)) {
            throw new \InvalidArgumentException(
                "partial of `{$fn}` supplies " . \count($args) . " of {$arity} slots; a full "
                . 'application is a call, not a partial',
            );
        }
    }
}

final class Intrinsic implements Operand
{
    /**
     * A primop application: one of the two operand forms that carry a computation (the other is
     * {@see ForeignCall}), where the statement/operand split otherwise keeps computations in
     * statements.
     *
     * They stay operands because that is what an argument-position expression is. A `do` block passed
     * to a function is the sharpest case: in a pure position it keeps its desugared action-building
     * primops, so it arrives here and the callee decides when to run the action. Promoting every such
     * argument to a temp would add a statement without changing what the program means.
     *
     * What makes that honest is the ordering rule: an operand's own operands are evaluated **left to
     * right, before the statement that consumes this one**, and every emitter walks `$args` in list
     * order (`emitIntrinsic()` in all three backends; `Exec-Foreign-Operand-Order` pins it through
     * observable host calls). A rewrite may duplicate this operand only when it is duplicable — an
     * effectful primop argument is not, and the inliner asks before substituting.
     *
     * @param list<Operand> $args
     */
    public function __construct(
        public string $name,
        public array $args,
        public ?SrcLoc $srcLoc = null,
    ) {
    }
}

final class DictMethod implements Operand
{
    /**
     * `localEvidence` is how many dictionaries the *instance* this method was projected from takes
     * — its context (`instance Monoid a => Monoid (Dual a)` is one), which is exactly the argument
     * list of the owner builder the projection calls.
     *
     * It is not a prefix of an application's arguments. Those dictionaries are already closed into
     * the owner dictionary the projection produced, so a call's leading arguments are the method's
     * remaining evidence slots followed by its values; for a projected `show` the instance's
     * context can be two dicts while the call carries a single value. Here the fact is recorded
     * because the producer knows it and no consumer can recover it from the argument list; the call
     * vector itself is unchanged by this field. The validator requires it at both seams, so null
     * (a hand-built pre-validation operand) is refused rather than read as zero.
     */
    public function __construct(
        public Operand $evidence,
        public string $method,
        public ?int $localEvidence = null,
    ) {
    }
}

final class ForeignCall implements Operand
{
    /**
     * A host call: the second of the two operand forms that carry a computation ({@see Intrinsic}).
     *
     * A foreign call used as an argument is not hoisted to a temp — it performs its host call when
     * that operand list is evaluated, **left to right, before the enclosing statement**, which is why
     * a rewrite must treat it as effectful: substituting it into two uses would run the call twice.
     * `Exec-Foreign-Operand-Order` pins the order and `Exec-IO-Operand-Position` the surrounding
     * action-position shapes, on all three backends where the fixture is backend-neutral.
     *
     * @param list<Operand> $args
     * @param list<int> $handleUnboxArgs indexes of args passed as unboxed handles
     */
    public function __construct(
        public string $backend,
        public string $kind,
        public string $path,
        public string $dispatch,
        public array $args,
        public ?string $classPath = null,
        public ?string $member = null,
        public IoWrap $ioWrap = IoWrap::None,
        public bool $phpValueBox = false,
        public bool $handleBox = false,
        public array $handleUnboxArgs = [],
        /** Inferred native calling signature (JVM descriptor); not present in source paths. */
        public ?string $nativeSig = null,
    ) {
    }
}

/* ---------------------------------------------------------------------------
   Statements
   --------------------------------------------------------------------------- */

final class Ret implements Stmt
{
    public function __construct(public Operand $value)
    {
    }
}

final class Call implements Stmt
{
    /** @param list<Operand> $args */
    public function __construct(
        public string $callee,
        public array $args,
        public int $dest,
        public ?SrcLoc $srcLoc = null,
    ) {
    }
}

final class CallValue implements Stmt
{
    /** @param list<Operand> $args */
    public function __construct(
        public Operand $callee,
        public array $args,
        public int $dest,
        public ?SrcLoc $srcLoc = null,
    ) {
    }
}

final class DictCall implements Stmt
{
    /**
     * @param list<Operand> $args the method's own evidence slots (when the projection did not close
     *        them) followed by its value arguments
     * @param ?int $localEvidence how many dictionaries the projected instance takes — its context,
     *        i.e. the owner builder's argument list (`DictMethod::$localEvidence`). Recorded by
     *        lowering where the projection is built; it describes the projection, not a prefix of
     *        `$args`, so it cannot be re-derived from the call.
     *
     *        **Required by the validator at both seams.** Lowering is the only producer and always
     *        states it, so `validateModule` rejects a `dict_call` whose value is null instead of
     *        letting a missing fact read as "nullary" later. No emitter reads it today — every
     *        `dict_call` dispatches through the runtime dictionary — and it is not part of
     *        `CallableSig`; the null case exists only for a hand-built pre-validation IR, which the
     *        validator refuses.
     */
    public function __construct(
        public Operand $evidence,
        public string $method,
        public array $args,
        public int $dest,
        public ?SrcLoc $srcLoc = null,
        public ?int $localEvidence = null,
    ) {
    }
}

final class Assign implements Stmt
{
    public function __construct(
        public int $dest,
        public Operand $value,
    ) {
    }
}

final class Let implements Stmt
{
    public function __construct(
        public string $name,
        public Operand $value,
    ) {
    }
}

final class MatchStmt implements Stmt
{
    /** @param list<MatchArm> $arms */
    public function __construct(
        public Operand $scrutinee,
        public array $arms,
        public int $dest,
        public bool $exhaustive = false,
    ) {
    }
}

final class MatchReturn implements Stmt
{
    /** @param list<MatchArm> $arms */
    public function __construct(
        public Operand $scrutinee,
        public array $arms,
        public bool $exhaustive = false,
    ) {
    }
}

final class TailRecall implements Stmt
{
    /** @param list<Operand> $args */
    public function __construct(public array $args)
    {
    }
}

final class Loop implements Stmt
{
    public function __construct(public Block $body)
    {
    }
}

/**
 * What an `IoCall` statement invokes. One variant per shape; the three nullable
 * properties it used to carry admitted eight combinations, of which four were legal by convention
 * alone.
 *
 * `IoForeign` carries its runtime wrapper because a foreign call is emitted as a call *through*
 * that wrapper (`foreign:either`); a plain runtime helper has no foreign call. There is no
 * variant that combines an intrinsic with either companion, because a primop is not a host call.
 */
abstract class IoOp
{
}

/** An ordinary action call: the callee is a Moggi-level entry. */
final class IoPlain extends IoOp
{
}

/** A primop performed in action position. */
final class IoIntrinsic extends IoOp
{
    public function __construct(public readonly string $name)
    {
    }
}

/** A runtime-helper call (`__apply`, `foreign:either`, …). */
final class IoRuntime extends IoOp
{
    public function __construct(public readonly string $runtime)
    {
    }
}

/** A host call, optionally through the runtime wrapper that unwraps its `IO` result. */
final class IoForeign extends IoOp
{
    public function __construct(
        public readonly ForeignCall $call,
        public readonly ?string $runtime = null,
    ) {
    }
}

final class IoCall implements Stmt
{
    /**
     * One action call and exactly one operation variant.
     *
     * The statement stores the variant itself rather than projecting it onto independent nullable
     * fields: a primop, a runtime helper, a host call and a plain call are four shapes, and there is
     * no value of this class that is two of them at once. A reader matches the variant it handles;
     * a rewrite copies it unchanged.
     *
     * @param list<Operand> $args
     */
    public function __construct(
        public string $callee,
        public array $args,
        public ?int $dest,
        public readonly IoOp $op = new IoPlain(),
        public ?SrcLoc $srcLoc = null,
    ) {
    }
}

final class IoRun implements Stmt
{
    public function __construct(
        public Operand $action,
        public ?int $dest = null,
        public ?SrcLoc $srcLoc = null,
    ) {
    }
}

final class IoMatch implements Stmt
{
    /**
     * A `case` inside an action body: a statement, not a value region — every arm answers by
     * returning, so the region names no result and has no slot to name it in.
     *
     * @param list<MatchArm> $arms
     */
    public function __construct(
        public Operand $scrutinee,
        public array $arms,
        public bool $exhaustive = false,
    ) {
    }
}

final class IoAssignAction implements Stmt
{
    public function __construct(
        public int $dest,
        public Block $body,
        public Operand $result,
        public ?SrcLoc $srcLoc = null,
    ) {
    }
}

/** Throw a SomeException value while running IO (precise throwIO). */
final class IoThrow implements Stmt
{
    public function __construct(
        public Operand $exception,
        public ?int $dest = null,
        public ?SrcLoc $srcLoc = null,
    ) {
    }
}

/**
 * Run an IO action; on Moggi exception invoke handler (SomeException -> IO a).
 * Non-matching typed catches are handled in library code before rethrow.
 */
final class IoCatch implements Stmt
{
    public function __construct(
        public Operand $action,
        public Operand $handler,
        public ?int $dest = null,
    ) {
    }
}

/** Run action; always run cleanup; preserve/rethrow per sync finally semantics. */
final class IoFinally implements Stmt
{
    public function __construct(
        public Operand $action,
        public Operand $cleanup,
        public ?int $dest = null,
    ) {
    }
}

/* ---------------------------------------------------------------------------
   Lowering context (shared by lower*.php)
   --------------------------------------------------------------------------- */

final class LowerState
{
    /**
     * @param list<FunctionDecl> $functions
     * @param array<string, true> $usedLocals IR local names allocated in the current function
     */
    public function __construct(
        public int $nextTemp = 0,
        public int $nextLambda = 0,
        public array $functions = [],
        public array $usedLocals = [],
        public int $nextLocalSuffix = 0,
        /** @var array<string, true> `<Module>::<name>` of module bindings whose body is `True` */
        public array $trueBindings = [],
    ) {
    }
}

final class LowerCtx
{
    /**
     * @param list<Stmt> $items
     * @param array<string, Operand> $env
     * @param array<string, list<string>> $data
     * @param array<string, true> $newtypes constructor names declared via `newtype`
     * @param list<string> $externalFnNames
     * @param array<int, Ast\AstNode> $ioActionStore pending IO actions, keyed by binder identity
     * @param array<string, int> $functionArity
     * @param array<string, true> $actionReturningEntries
     */
    public function __construct(
        public array $items = [],
        public array $env = [],
        public LowerState $state = new LowerState(),
        public array $data = [],
        public array $newtypes = [],
        public array $externalFnNames = [],
        public array $ioActionStore = [],
        public bool $hasIoRun = false,
        public bool $hasIoAssignAction = false,
        public array $functionArity = [],
        public array $actionReturningEntries = [],
        /** entry name → the direct worker `IR\lower` created for it, in this module */
        public array $workers = [],
        /** @var array<string, true> FFI workers this module can call: a call answers with the value */
        public array $executingEntries = [],
        /**
         * The operands bound by the binders in scope, keyed by the checker's binder identity.
         *
         * A name-keyed environment cannot tell two binders of the same spelling apart, so a use
         * resolves through this map when its checked term names a binder, and through `$env` (a
         * top-level entry, an imported name, a synthesized local) when it does not.
         *
         * @var array<int, Operand>
         */
        public array $binders = [],
        /** @var array<string, true> `<Module>::<name>` of bindings whose body is the host boolean `true` */
        public array $trueBindings = [],
        public bool $inActionBox = false,
        public string $moduleName = '',
        public string $sourceFile = '',
        public string $functionName = '',
        /** @var array<string, string> alias → canonical constructor tag */
        public array $constructorRenames = [],
        /**
         * Source location of the statement currently being lowered.
         *
         * A stack frame names the *statement* that is executing, not a nested
         * expression inside it, so every statement built while lowering one
         * source statement is stamped with this start rather than with the
         * call site it happens to contain (`do putStrLn (show boom)` reports
         * the `putStrLn` column, and `show`/`boom` become operand sites).
         */
        public ?SrcLoc $stmtSrcLoc = null,
        /**
         * Evidence slot names in scope, in the enclosing declaration's solved order.
         *
         * A lifted callable closes over some of them; which ones is a fact of this scope, so it
         * is carried here rather than recovered later from a name prefix.
         *
         * @var list<string>
         */
        public array $evidenceNames = [],
        /**
         * The owner dictionary a projected method carries, keyed by the builder that makes it.
         *
         * A projection names its instance's dictionary rather than capturing the enclosing scope,
         * so the builder used to run again at every use (`@__ev_Labelled_Int()` per call). The
         * first use in a block binds the builder's result and stores that operand here; later uses
         * in the same block read it, which is the "instance-context closure": the projected method
         * owns its dictionary instead of re-materialising it.
         *
         * The map is per block, and a nested block starts with its own, so a dictionary is never
         * built for a region that does not run.
         *
         * @var array<string, Operand>
         */
        public array $evidenceBindings = [],
        /**
         * The checked core's terms for the declaration being lowered, by the id each term owns.
         *
         * The record owns every evidence decision in this declaration: an evidence position
         * whose term is missing here is a producer bug, and lowering refuses it rather than
         * reading the elaborated node's own fields. Empty for a declaration built outside a
         * checked core (the suite's hand-built IR), which then has no evidence positions at all.
         *
         * @var array<int, \Moggi\Modules\CheckedTerm>
         */
        public array $terms = [],
    ) {
    }
}
