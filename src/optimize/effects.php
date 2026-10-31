<?php declare(strict_types=1);

namespace Moggi\Optimize\Effects;

use Moggi\IR;
use Moggi\Optimize\Facts\Facts;

use function Moggi\IR\Visit\walkBlock;
use function Moggi\Modules\isQualifiedSymbol;
use function Moggi\Modules\resolvedSymbol;
use function Moggi\Optimize\Interproc\functionNamesInRecursiveSccs;
use function Moggi\Optimize\Interproc\indexFunctions;
use function Moggi\Semantics\IntrinsicRegistry\reportsSourceLocation;
use function Moggi\Semantics\IoBoundary\typeMentionsIo;

/** @param list<IR\FunctionDecl> $functions @return list<IR\FunctionDecl> */
function annotateIoEffects(array $functions, string $moduleName = ''): array
{
    $index = indexFunctions($functions, $moduleName);
    $changed = true;

    while ($changed) {
        $changed = false;
        foreach ($functions as $i => $function) {
            $ioEffect = functionIoEffect($function, $index);
            if ($function->shape->ioEffect !== $ioEffect) {
                $function->shape = $function->shape->with(['ioEffect' => $ioEffect]);
                $index[$function->name]->shape = $index[$function->name]->shape->with(['ioEffect' => $ioEffect]);
                $changed = true;
            }
        }
    }

    return $functions;
}

/** @param array<string, IR\FunctionDecl> $index */
function functionIoEffect(IR\FunctionDecl $function, array $index): bool
{
    if ($function->type !== null && typeMentionsIo($function->type)) {
        return true;
    }

    return blockHasIoEffect($function->body, $index);
}

/** @param array<string, IR\FunctionDecl> $index */
function blockHasIoEffect(IR\Block $block, array $index): bool
{
    foreach ($block->items as $item) {
        if (stmtHasIoEffect($item, $index)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, IR\FunctionDecl> $index */
function stmtHasIoEffect(IR\Stmt $stmt, array $index): bool
{
    return match ($stmt::class) {
        IR\IoCall::class, IR\IoRun::class,
        IR\IoThrow::class, IR\IoCatch::class, IR\IoFinally::class => true,
        IR\IoAssignAction::class => false,
        IR\Call::class => calleeIoEffect($stmt->callee, $index),
        IR\CallValue::class => operandHasIoEffect($stmt->callee, $index)
            || operandsHaveIoEffect($stmt->args, $index),
        IR\Assign::class, IR\Let::class => operandHasIoEffect($stmt->value, $index),
        IR\Ret::class => operandHasIoEffect($stmt->value, $index),
        IR\MatchStmt::class, IR\MatchReturn::class, IR\IoMatch::class => operandHasIoEffect($stmt->scrutinee, $index)
            || matchArmsHaveIoEffect($stmt->arms, $index),
        IR\Loop::class => blockHasIoEffect($stmt->body, $index),
        default => false,
    };
}

/** @param list<IR\MatchArm> $arms @param array<string, IR\FunctionDecl> $index */
function matchArmsHaveIoEffect(array $arms, array $index): bool
{
    foreach ($arms as $arm) {
        if (blockHasIoEffect($arm->body, $index)) {
            return true;
        }
    }

    return false;
}

/** @param list<IR\Operand> $operands @param array<string, IR\FunctionDecl> $index */
function operandsHaveIoEffect(array $operands, array $index): bool
{
    foreach ($operands as $operand) {
        if (operandHasIoEffect($operand, $index)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, IR\FunctionDecl> $index */
function calleeIoEffect(string $callee, array $index): bool
{
    return $index[$callee]->shape->ioEffect ?? false;
}

/** @param array<string, IR\FunctionDecl> $index */
function operandHasIoEffect(IR\Operand $operand, array $index): bool
{
    return match ($operand::class) {
        IR\Intrinsic::class => false,
        IR\FnRef::class => calleeIoEffect($operand->name, $index),
        default => false,
    };
}

/**
 * Callees whose call is an observable effect, so DCE may not delete one whose result is unused.
 *
 * Three authorities, because they become available at different times:
 *  - a foreign import executes the host call;
 *  - a worker executes the effects its producer's box deferred — a run site is a plain call to it,
 *    which is why this is not "foreign only" any more;
 *  - a body that performs IO, once `annotateIoEffects` has marked it.
 *
 * @param list<IR\FunctionDecl> $functions
 * @param array<string, IR\FunctionDecl> $index the same functions, indexed by bare and qualified name
 * @return array<string, true>
 */
function effectfulCalleeNames(array $functions, array $index, string $moduleName = ''): array
{
    /** @var array<string, true> $effectful */
    $effectful = [];
    foreach ($functions as $function) {
        if ($function->shape->foreign || $function->shape->kind === IR\FunctionKind::Worker || $function->shape->ioEffect) {
            $effectful[$function->name] = true;
            if ($moduleName !== '' && ! \str_contains($function->name, '::')) {
                $effectful[resolvedSymbol($moduleName, $function->name)] = true;
            }
        }
    }

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($functions as $function) {
            if (isset($effectful[$function->name])) {
                continue;
            }
            if (blockCallsHostEffect($function->body, $effectful, $index)) {
                $effectful[$function->name] = true;
                if ($moduleName !== '' && ! \str_contains($function->name, '::')) {
                    $effectful[resolvedSymbol($moduleName, $function->name)] = true;
                }
                $changed = true;
            }
        }
    }

    return $effectful;
}

function blockHasDirectForeign(IR\Block $block): bool
{
    foreach ($block->items as $item) {
        if (stmtHasDirectForeign($item)) {
            return true;
        }
    }

    return false;
}

function stmtHasDirectForeign(IR\Stmt $stmt): bool
{
    return match ($stmt::class) {
        IR\Assign::class, IR\Let::class, IR\Ret::class => operandHasDirectForeign($stmt->value),
        IR\Call::class => operandsHaveDirectForeign($stmt->args),
        IR\CallValue::class => operandHasDirectForeign($stmt->callee) || operandsHaveDirectForeign($stmt->args),
        IR\DictCall::class => operandHasDirectForeign($stmt->evidence) || operandsHaveDirectForeign($stmt->args),
        IR\MatchStmt::class, IR\MatchReturn::class, IR\IoMatch::class => operandHasDirectForeign($stmt->scrutinee)
            || matchArmsHaveDirectForeign($stmt->arms),
        IR\Loop::class => blockHasDirectForeign($stmt->body),
        IR\IoCall::class => $stmt->op instanceof IR\IoForeign,
        default => false,
    };
}

/** @param list<IR\MatchArm> $arms */
function matchArmsHaveDirectForeign(array $arms): bool
{
    foreach ($arms as $arm) {
        if (blockHasDirectForeign($arm->body)) {
            return true;
        }
    }

    return false;
}

/** @param list<IR\Operand> $operands */
function operandsHaveDirectForeign(array $operands): bool
{
    foreach ($operands as $operand) {
        if (operandHasDirectForeign($operand)) {
            return true;
        }
    }

    return false;
}

function operandHasDirectForeign(IR\Operand $operand): bool
{
    return match ($operand::class) {
        IR\ForeignCall::class => true,
        IR\ListLit::class => operandsHaveDirectForeign($operand->elements),
        IR\Partial::class => operandsHaveDirectForeign($operand->args),
        IR\DictMethod::class => operandHasDirectForeign($operand->evidence),
        default => false,
    };
}

/**
 * @param array<string, true> $host
 * @param array<string, IR\FunctionDecl> $index
 */
function blockCallsHostEffect(IR\Block $block, array $host, array $index): bool
{
    foreach ($block->items as $item) {
        if (stmtCallsHostEffect($item, $host, $index)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, true> $host
 * @param array<string, IR\FunctionDecl> $index
 */
function stmtCallsHostEffect(IR\Stmt $stmt, array $host, array $index): bool
{
    return match ($stmt::class) {
        IR\Call::class => isset($host[$stmt->callee]),
        IR\Assign::class, IR\Let::class, IR\Ret::class => operandCallsHostEffect($stmt->value, $host),
        IR\CallValue::class => operandCallsHostEffect($stmt->callee, $host)
            || operandsCallHostEffect($stmt->args, $host),
        IR\MatchStmt::class, IR\MatchReturn::class, IR\IoMatch::class => operandCallsHostEffect($stmt->scrutinee, $host)
            || matchArmsCallHostEffect($stmt->arms, $host, $index),
        IR\Loop::class => blockCallsHostEffect($stmt->body, $host, $index),
        default => false,
    };
}

/**
 * @param list<IR\MatchArm> $arms
 * @param array<string, true> $host
 * @param array<string, IR\FunctionDecl> $index
 */
function matchArmsCallHostEffect(array $arms, array $host, array $index): bool
{
    foreach ($arms as $arm) {
        if (blockCallsHostEffect($arm->body, $host, $index)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, true> $host */
function operandCallsHostEffect(IR\Operand $operand, array $host): bool
{
    if ($operand instanceof IR\FnRef) {
        return isset($host[$operand->name]);
    }
    if ($operand instanceof IR\Partial) {
        return isset($host[$operand->fn]) || operandsCallHostEffect($operand->args, $host);
    }

    return false;
}

/**
 * @param list<IR\Operand> $operands
 * @param array<string, true> $host
 */
function operandsCallHostEffect(array $operands, array $host): bool
{
    foreach ($operands as $operand) {
        if (operandCallsHostEffect($operand, $host)) {
            return true;
        }
    }

    return false;
}

/**
 * What running a piece of the program can do, in the four categories the optimizer decides on.
 *
 * A construction is unobservable on its own; executing, throwing and diverging are observable even
 * when the result is never read, so a discard may only drop a construction.
 */
final class Effect
{
    public function __construct(
        public readonly bool $constructs,
        public readonly bool $executes,
        public readonly bool $mayThrow,
        public readonly bool $mayDiverge,
    ) {
    }

    public static function none(): self
    {
        return new self(false, false, false, false);
    }

    public static function constructor(): self
    {
        return new self(true, false, false, false);
    }

    public static function throws(): self
    {
        return new self(false, false, true, false);
    }

    public function union(self $other): self
    {
        return new self(
            $this->constructs || $other->constructs,
            $this->executes || $other->executes,
            $this->mayThrow || $other->mayThrow,
            $this->mayDiverge || $other->mayDiverge,
        );
    }

    /** Whether dropping the computation would change what the program does. */
    public function discardIsObservable(): bool
    {
        return $this->executes || $this->mayThrow || $this->mayDiverge;
    }
}

/**
 * The calls one body can make, and the two things that make a body observable without a named call.
 *
 * `call` and `io_call` name their callee. A `call_value` names it when the value is a function
 * reference or a partial application; applied through a temp, a local or a dictionary method the IR
 * does not say which function runs, and a `dict_call`'s method is named by the evidence table rather
 * than by the statement — both are reported as `unresolved`, because a body that calls something it
 * cannot name may do whatever that call can.
 *
 * One traversal with three readers: {@see Model} for may-throw and may-diverge, the recursive-SCC
 * detector for the call graph, and the tree shake for the names a body mentions.
 *
 * @return array{names: array<string, true>, mentions: array<string, true>, unresolved: bool, throws: bool, loops: bool, dictMethods: array<string, true>}
 */
function scanBody(IR\Block $block): array
{
    $names = [];
    $mentions = [];
    $unresolved = false;
    $throws = false;
    $loops = false;
    /** @var array<string, true> $dictMethods the methods reachable code dispatches through a dictionary */
    $dictMethods = [];

    walkBlock(
        $block,
        static function (IR\Stmt $stmt) use (&$names, &$mentions, &$unresolved, &$loops, &$dictMethods): void {
            if ($stmt instanceof IR\Call || $stmt instanceof IR\IoCall) {
                $names[$stmt->callee] = true;
                $mentions[$stmt->callee] = true;
            } elseif ($stmt instanceof IR\CallValue) {
                $callee = namedCallee($stmt->callee);
                if ($callee === null) {
                    $unresolved = true;
                } else {
                    $names[$callee] = true;
                }
            } elseif ($stmt instanceof IR\DictCall) {
                $dictMethods[$stmt->method] = true;
                $unresolved = true;
            }
            if ($stmt instanceof IR\Loop || $stmt instanceof IR\TailRecall) {
                $loops = true;
            }
        },
        static function (IR\Operand $operand) use (&$throws, &$mentions): void {
            if ($operand instanceof IR\Intrinsic && reportsSourceLocation($operand->name)) {
                $throws = true;
            }
            if ($operand instanceof IR\ForeignCall) {
                $throws = true;
            }
            if ($operand instanceof IR\FnRef) {
                $mentions[$operand->name] = true;
            }
            if ($operand instanceof IR\Partial) {
                $mentions[$operand->fn] = true;
            }
        },
    );

    return [
        'names' => $names,
        'mentions' => $mentions,
        'unresolved' => $unresolved,
        'throws' => $throws,
        'loops' => $loops,
        'dictMethods' => $dictMethods,
    ];
}

/**
 * Every body's scan, keyed by function name — the one table a model and its revision are both built from.
 *
 * @param list<IR\FunctionDecl> $functions
 * @return array<string, array{names: array<string, true>, mentions: array<string, true>, unresolved: bool, throws: bool, loops: bool, dictMethods: array<string, true>}>
 */
function scanFunctions(array $functions): array
{
    $scanned = [];
    foreach ($functions as $function) {
        $scanned[$function->name] = scanBody($function->body);
    }

    return $scanned;
}

/** The function a value calls when it is applied, or null when the IR does not say which one. */
function namedCallee(IR\Operand $callee): ?string
{
    return match ($callee::class) {
        IR\FnRef::class => $callee->name,
        IR\Partial::class => $callee->fn,
        default => null,
    };
}

/**
 * The one owner of "what can this do", for the optimizer passes that decide whether a computation may
 * be dropped or reused. A call is resolved through the module's own functions; a callee outside the
 * module is an import and is treated as executing, throwing and possibly diverging, so it can never be
 * deleted.
 *
 * A callee the IR does not place in this module and does not qualify is a name this module does not
 * declare: a constructor, a projection or an entry a pass generated. It is a construction, which is
 * why `ofCall()` answers `none()` for it rather than the conservative answer a qualified import gets.
 *
 * One question it does not answer from the IR is whether naming an entry runs it: a function of no
 * parameters is a CAF the backends force, so a bare reference to one is an evaluation. That is the facts
 * owner's answer (`Facts::runsOnReference()`), and this model asks it rather than deriving a second one.
 */
final class Model
{
    /** @var array<string, bool> */
    private array $throwMemo = [];

    /** @var array<string, bool> */
    private array $divergeMemo = [];

    /** @var array<string, array{names: array<string, true>, unresolved: bool, throws: bool, loops: bool, dictMethods: array<string, true>}> */
    private array $bodies = [];

    /**
     * @param array<string, IR\FunctionDecl> $index
     * @param array<string, true> $executes
     * @param array<string, true> $recursive
     */
    private function __construct(
        private array $index,
        private array $executes,
        private array $recursive,
    ) {
    }

    /** @param list<IR\FunctionDecl> $functions */
    public static function forFunctions(array $functions, string $moduleName = ''): self
    {
        return self::fromScan($functions, scanFunctions($functions), $moduleName);
    }

    /**
     * The model over one program, from the scan its caller already holds.
     *
     * {@see Analysis} keys its revision on that scan and then hands it here, so the model must be built from
     * the same one: scanning again would answer a body the key no longer describes.
     *
     * @param list<IR\FunctionDecl> $functions
     * @param array<string, array{names: array<string, true>, mentions: array<string, true>, unresolved: bool, throws: bool, loops: bool, dictMethods: array<string, true>}> $bodies
     */
    public static function fromScan(array $functions, array $bodies, string $moduleName = ''): self
    {
        $index = indexFunctions($functions, $moduleName);
        $model = new self(
            $index,
            effectfulCalleeNames($functions, $index, $moduleName),
            \array_fill_keys(functionNamesInRecursiveSccs($index, $bodies), true),
        );
        $model->bodies = $bodies;

        return $model;
    }

    public function ofCall(string $callee): Effect
    {
        if (! isset($this->index[$callee])) {
            return isQualifiedSymbol($callee)
                ? new Effect(false, true, true, true)
                : Effect::none();
        }

        return new Effect(false, isset($this->executes[$callee]), $this->mayThrow($callee), $this->mayDiverge($callee));
    }

    public function ofOperand(IR\Operand $operand): Effect
    {
        return match ($operand::class) {
            IR\ConstInt::class, IR\ConstDouble::class, IR\ConstStr::class, IR\ConstChar::class,
            IR\Unit::class, IR\Temp::class, IR\Local::class => Effect::none(),
            IR\FnRef::class => $this->ofCall($operand->name),
            IR\ListLit::class => Effect::constructor()->union($this->ofOperands($operand->elements)),
            IR\Intrinsic::class => (reportsSourceLocation($operand->name) ? Effect::throws() : Effect::none())
                ->union($this->ofOperands($operand->args)),
            IR\Partial::class => $this->ofCall($operand->fn)->union($this->ofOperands($operand->args)),
            IR\DictMethod::class => $this->ofOperand($operand->evidence),
            IR\ForeignCall::class => new Effect(false, true, true, false),
            default => new Effect(false, true, true, true),
        };
    }

    /** @param list<IR\Operand> $operands */
    public function ofOperands(array $operands): Effect
    {
        $effect = Effect::none();
        foreach ($operands as $operand) {
            $effect = $effect->union($this->ofOperand($operand));
        }

        return $effect;
    }

    /**
     * What evaluating this expression costs, as opposed to what applying it would do ({@see ofOperand}, which
     * answers that for a value in callee position). A function reference to a function that takes parameters
     * evaluates to itself, so it is not an execution — but a reference to an entry with no parameters is the
     * forced call the backends make of it, which is why that one arm asks the facts owner. A partial
     * application constructs a closure without running the function it wraps; a faulting primop throws where
     * it stands.
     */
    public function evaluationOf(IR\Operand $operand): Effect
    {
        return match ($operand::class) {
            IR\ConstInt::class, IR\ConstDouble::class, IR\ConstStr::class, IR\ConstChar::class,
            IR\Unit::class, IR\Temp::class, IR\Local::class => Effect::none(),
            IR\FnRef::class => Facts::current()->runsOnReference($operand->name)
                ? $this->ofCall($operand->name)
                : Effect::none(),
            IR\ListLit::class => Effect::constructor()->union($this->evaluationsOf($operand->elements)),
            IR\Intrinsic::class => (reportsSourceLocation($operand->name) ? Effect::throws() : Effect::none())
                ->union($this->evaluationsOf($operand->args)),
            IR\Partial::class => $this->evaluationsOf($operand->args),
            IR\DictMethod::class => $this->evaluationOf($operand->evidence),
            IR\ForeignCall::class => new Effect(false, true, true, false),
            default => new Effect(false, true, true, true),
        };
    }

    /** @param list<IR\Operand> $operands */
    public function evaluationsOf(array $operands): Effect
    {
        $effect = Effect::none();
        foreach ($operands as $operand) {
            $effect = $effect->union($this->evaluationOf($operand));
        }

        return $effect;
    }

    private function mayThrow(string $name): bool
    {
        if (isset($this->throwMemo[$name])) {
            return $this->throwMemo[$name];
        }

        $body = $this->bodies[$name] ?? null;
        if ($body === null) {
            return $this->throwMemo[$name] = true;
        }

        $this->throwMemo[$name] = false;
        $throws = $body['throws'] || $body['unresolved'];
        foreach (\array_keys($body['names']) as $callee) {
            if ($this->mayThrow($callee)) {
                $throws = true;

                break;
            }
        }

        return $this->throwMemo[$name] = $throws;
    }

    private function mayDiverge(string $name): bool
    {
        if (isset($this->divergeMemo[$name])) {
            return $this->divergeMemo[$name];
        }

        $body = $this->bodies[$name] ?? null;
        if ($body === null) {
            return $this->divergeMemo[$name] = true;
        }

        $this->divergeMemo[$name] = true;
        $diverges = isset($this->recursive[$name]) || $body['loops'] || $body['unresolved'];
        foreach (\array_keys($body['names']) as $callee) {
            if ($this->mayDiverge($callee)) {
                $diverges = true;

                break;
            }
        }

        return $this->divergeMemo[$name] = $diverges;
    }
}

/**
 * The model a module's optimize run asks for, held across the whole run.
 *
 * Four points in the pipeline need the model, and between them passes rewrite the program it was built
 * from, so the answers cannot simply be computed once. They can be keyed, though: the model reads a
 * function's name, its kind, whether it is foreign, whether `annotateIoEffects` marked it, and the scan of
 * its body, so a run that has not moved any of those is a run whose model still holds. This object keeps
 * the revision it last answered for, so each of the four asks is a question — is this still the program I
 * answered for — rather than a rebuild it cannot avoid, and a hit reuses both the model and its scan.
 *
 * The marks are part of the revision because they are the one fact the model reads that a pass changes in
 * place (`annotateIoEffects` sets `ioEffect` on the declaration); a rewrite replaces the declaration and a
 * mark does not, and both have to invalidate.
 */
final class Analysis
{
    private ?Model $model = null;

    /** @var array{0: string, 1: array<string, array{IR\FunctionKind, bool, bool}>, 2: array<string, array{names: array<string, true>, mentions: array<string, true>, unresolved: bool, throws: bool, loops: bool, dictMethods: array<string, true>}>}|null */
    private ?array $revision = null;

    /** @param list<IR\FunctionDecl> $functions */
    public function modelFor(array $functions, string $moduleName = ''): Model
    {
        $bodies = scanFunctions($functions);
        $marks = [];
        foreach ($functions as $function) {
            $marks[$function->name] = [$function->shape->kind, $function->shape->foreign, $function->shape->ioEffect];
        }
        $revision = [$moduleName, $marks, $bodies];

        if ($this->model === null || $revision !== $this->revision) {
            $this->revision = $revision;
            $this->model = Model::fromScan($functions, $bodies, $moduleName);
        }

        return $this->model;
    }
}
