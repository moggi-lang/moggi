<?php declare(strict_types=1);

namespace Moggi\IR;

use Moggi\Syntax\Ast;

enum IoBodyKind: string
{
    case StraightLine = 'straight-line';
    case ActionReturn = 'action_return';
    case Mixed = 'mixed';
}

/** How a foreign call's `IO` result is unwrapped by the backend. */
enum IoWrap: string
{
    case None = 'none';
    case Either = 'either';
    case FgetsLine = 'fgets_line';
    case MaybeString = 'maybe_string';
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

// ---------------------------------------------------------------------------
// Top-level / structural
// ---------------------------------------------------------------------------

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
        if ($function->entryKind !== null) {
            return new EntryPoint($function->name, $function->entryKind);
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

final class FunctionDecl implements IrNode
{
    /**
     * @param list<string> $params parameter slot names
     * @param ?Ast\TypeNode $type declared surface type, when the source had a signature
     */
    public function __construct(
        public string $name,
        public array $params,
        public ?Ast\TypeNode $type,
        public Block $body,
        public bool $export = false,
        public bool $instanceMethod = false,
        public ?EntryPointKind $entryKind = null,
        public bool $ioEffect = false,
        public bool $ioStraightLine = false,
        public bool $foreign = false,
        public IoBodyKind $ioBodyKind = IoBodyKind::StraightLine,
        public ?SrcLoc $srcLoc = null,
    ) {
    }

    public function withBody(Block $body): self
    {
        return new self(
            $this->name,
            $this->params,
            $this->type,
            $body,
            $this->export,
            $this->instanceMethod,
            $this->entryKind,
            $this->ioEffect,
            $this->ioStraightLine,
            $this->foreign,
            $this->ioBodyKind,
            $this->srcLoc,
        );
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

    public function withPrep(Block $prep): self
    {
        return new self($prep, $this->cond);
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

// ---------------------------------------------------------------------------
// Patterns
// ---------------------------------------------------------------------------

final class PatWild implements Pattern
{
}

final class PatVar implements Pattern
{
    public function __construct(public string $name)
    {
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

// ---------------------------------------------------------------------------
// Operands
// ---------------------------------------------------------------------------

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
    /** @param list<Operand> $args */
    public function __construct(
        public string $fn,
        public int $arity,
        public array $args,
    ) {
    }
}

final class ExprPartial implements Operand
{
    /** @param list<Operand> $args */
    public function __construct(
        public string $fn,
        public int $arity,
        public array $args,
    ) {
    }
}

final class ExprBinop implements Operand
{
    public function __construct(
        public string $op,
        public Operand $left,
        public Operand $right,
    ) {
    }
}

final class ExprCall implements Operand
{
    /** @param list<Operand> $args */
    public function __construct(
        public string $callee,
        public array $args,
        public ?SrcLoc $srcLoc = null,
    ) {
    }
}

final class ExprCallValue implements Operand
{
    /** @param list<Operand> $args */
    public function __construct(
        public Operand $callee,
        public array $args,
        public ?SrcLoc $srcLoc = null,
    ) {
    }
}

final class Intrinsic implements Operand
{
    /** @param list<Operand> $args */
    public function __construct(
        public string $name,
        public array $args,
        public ?SrcLoc $srcLoc = null,
    ) {
    }
}

final class DictMethod implements Operand
{
    public function __construct(
        public Operand $evidence,
        public string $method,
    ) {
    }
}

final class ForeignCall implements Operand
{
    /**
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

final class IoAction implements Operand
{
    public function __construct(public Operand $expr)
    {
    }
}

// ---------------------------------------------------------------------------
// Statements
// ---------------------------------------------------------------------------

final class Ret implements Stmt
{
    public function __construct(public Operand $value)
    {
    }
}

final class Binop implements Stmt
{
    public function __construct(
        public string $op,
        public Operand $left,
        public Operand $right,
        public int $dest,
    ) {
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
    /** @param list<Operand> $args */
    public function __construct(
        public Operand $evidence,
        public string $method,
        public array $args,
        public int $dest,
        public ?SrcLoc $srcLoc = null,
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

final class IoCall implements Stmt
{
    /** @param list<Operand> $args */
    public function __construct(
        public string $callee,
        public array $args,
        public ?int $dest,
        public ?string $intrinsic = null,
        public ?string $runtime = null,
        public ?ForeignCall $foreign = null,
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
    /** @param list<MatchArm> $arms */
    public function __construct(
        public Operand $scrutinee,
        public array $arms,
        public ?int $dest = null,
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

// ---------------------------------------------------------------------------
// Lowering context (shared by lower*.php)
// ---------------------------------------------------------------------------

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
     * @param array<string, Ast\AstNode> $ioActionStore pending IO actions, keyed by bound name
     * @param array<string, int> $functionArity
     * @param array<string, true> $ioActionReturnFns
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
        public array $ioActionReturnFns = [],
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
    ) {
    }
}
