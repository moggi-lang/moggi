<?php declare(strict_types=1);

namespace Moggi\IR\Callables;

use Moggi\IR;
use Moggi\Syntax\Ast\TypeArrow;

use function Moggi\IR\Visit\boundLocalsInBlock;
use function Moggi\IR\Visit\collectLambdaRefsInBlock;
use function Moggi\IR\Visit\freeLocalsInBlock;
use function Moggi\Semantics\IoBoundary\functionReturnsIo;
use function Moggi\Semantics\IoBoundary\stripConstraints;
use function Moggi\Syntax\Ast\generatedWorkerPrefix;

/**
 * The callable table: one authoritative record per entry, and the one producer of a lifted
 * callable's capture layout.
 *
 * Every entry has a published `CallableSig` — evidence slots, capture slots and user value
 * parameters as three distinct things, plus the result and acquisition conventions —
 * computed once per compilation revision. Before this module the same logical
 * layout was derived at seven independent sites (`bindLambdaCaptures`, the optimizer's inline
 * metadata, the module arity table, and each of the three backends), and the two sides of a call
 * could only agree because every one of them sorted the capture names alphabetically. Nothing
 * here changes the runtime order: `captures` is the list the binder already produced, with the
 * evidence slots taken out of it, because making `E ++ C` the runtime order is a separate slice.
 *
 * What this module does not yet publish: imported entries — their facts travel in the module
 * `facts` record — and the action worker/action-value split at the IR node level, which
 * `IR\FunctionDecl::$kind` (`Worker`) and `$actionConvention` carry in the tree.
 */
const ABI_VERSION = 1;

/**
 * The local name of an entry's worker.
 *
 * A `ReturnsAction` entry answers with an action value and its worker performs the body. One
 * spelling for the module that creates the worker and for every importer that calls it.
 */
function workerName(string $entry): string
{
    return generatedWorkerPrefix() . $entry;
}

/**
 * The workers a lowered module declares, by worker name.
 *
 * The fact is `FunctionKind::Worker` on the lowered declaration — what lowering allocated — so a
 * pass asks {@see calleeIsRun()} with a declaration instead of the generated spelling.
 *
 * @return array<string, IR\FunctionDecl>
 */
function workerDeclarations(IR\Module $module): array
{
    $workers = [];
    foreach ($module->functions as $decl) {
        if ($decl->kind === IR\FunctionKind::Worker) {
            $workers[$decl->name] = $decl;
        }
    }

    return $workers;
}

/**
 * Whether a call to `$callee` is a run.
 *
 * A run site calls the producer's worker, so the answer is the declaration's kind whenever the
 * callee is at hand. For a callee from another module — an inlined body's imported call, where
 * only the resolved symbol is left — the answer is the generated namespace, which is exact
 * because the checker refuses a source declaration that names itself into it
 * (`assertNotGeneratedWorkerName()`): a `__w_` name can only have been written by
 * {@see workerName()}.
 */
function calleeIsRun(?IR\FunctionDecl $decl, string $callee): bool
{
    if ($decl !== null) {
        return $decl->kind === IR\FunctionKind::Worker;
    }

    $prefix = generatedWorkerPrefix();
    $tail = \strrpos($callee, '::');

    return \str_starts_with($tail === false ? $callee : \substr($callee, $tail + 2), $prefix);
}

/** Whether an entry's result is a plain value, a callable value, or an action value. */
enum ResultConvention: string
{
    case Value = 'value';
    case Callable = 'callable';
    case Action = 'action';

    /**
     * The declaration carries no signature, so the tree has no fact to classify it from — a
     * lifted callable today. It is a named gap rather than a default: a consumer that needs a
     * result convention may not treat it as a value, and the checker publishing the convention it
     * already knows is what closes the gap.
     */
    case Unclassified = 'unclassified';
}

/**
 * How a reference to the entry becomes a value.
 *
 * `EvaluateBinding` is a declaration with no user parameters whose body produces the value; a
 * callable with parameters is acquired as itself. `ConstructorValue` is for an entry that is a
 * value by construction; no source declaration reaches it today.
 */
enum AcquisitionKind: string
{
    case Callable = 'callable';
    case EvaluateBinding = 'evaluate-binding';
    case ConstructorValue = 'constructor-value';
}

/** One evidence slot: its name in the IR, and the class it is a dictionary for. */
final class EvidenceSlot
{
    public function __construct(
        public string $slot,
        public string $className = '',
    ) {
    }
}

/**
 * The published contract of one entry.
 *
 * Lengths are authoritative: `hidden()` == |evidence| + |captures| is the number of leading
 * runtime slots that are not user arguments, and `runtimeArity()` == |E| + |C| + |V|.
 */
final class CallableSig
{
    /**
     * @param list<EvidenceSlot> $evidence
     * @param list<string> $captures
     * @param list<string> $params user value parameters
     * @param ?bool $returnsCallable the declaration's own result shape, null when it has no signature
     */
    public function __construct(
        public string $entryId,
        public IR\FunctionKind $kind,
        public string $module,
        public array $evidence,
        public array $captures,
        public array $params,
        public ResultConvention $result,
        public AcquisitionKind $acquisition,
        public ?bool $returnsCallable = null,
        public int $abiVersion = ABI_VERSION,
    ) {
    }

    /** @return list<string> */
    public function evidenceNames(): array
    {
        return \array_map(static fn (EvidenceSlot $slot): string => $slot->slot, $this->evidence);
    }

    public function hiddenSlots(): int
    {
        return \count($this->evidence) + \count($this->captures);
    }

    public function runtimeArity(): int
    {
        return $this->hiddenSlots() + \count($this->params);
    }

    /**
     * The runtime slot names in the order the binder produces them: the evidence slots, then the
     * captures, then the user parameters. `captures` keeps the binder's order, so this is the
     * vector a call has to match today; a slice that adopts `E ++ C` only changes `captures`.
     *
     * @return list<string>
     */
    public function slotNames(): array
    {
        return [...$this->evidenceNames(), ...$this->captures, ...$this->params];
    }
}

/** A module's entries, keyed by the name their declarations use. */
final class CallableTable
{
    /**
     * @param array<string, CallableSig> $byEntry
     * @param array<string, array{captures: list<string>, params: list<string>}> $meta
     */
    public function __construct(
        public array $byEntry,
        public array $meta,
        public string $module = '',
    ) {
    }

    public function sig(string $entry): ?CallableSig
    {
        if (isset($this->byEntry[$entry])) {
            return $this->byEntry[$entry];
        }

        $separator = \strrpos($entry, '::');
        if ($separator !== false && $this->module !== '' && \substr($entry, 0, $separator) === $this->module) {
            return $this->byEntry[\substr($entry, $separator + 2)] ?? null;
        }

        return null;
    }

    /** @return list<string> */
    public function capturesOf(string $entry): array
    {
        return $this->meta[$entry]['captures'] ?? [];
    }

    /** @return array<string, array{captures: list<string>, params: list<string>}> */
    public function meta(): array
    {
        return $this->meta;
    }
}

/**
 * The capture layout of every lifted callable, plus its transitive closure.
 *
 * `$capturesOf` supplies each lifted callable's direct free locals; the closure adds what a
 * callable needs from the callables it references, to a fixpoint. What a body binds itself — its
 * parameters, its `let`s, its match binders — is never added, because a nested lambda's local is
 * that body's own and passing it would name an undefined local at the caller.
 *
 * The returned lists are sorted. Direct captures miss the locals hidden inside nested lifted
 * references (they only appear once captures are bound), so first-seen order depends on when in
 * the pipeline the set is computed; sorting is what has kept the use site and the definition site
 * agreeing so far. It is a workaround, not a contract: the order a binder produced is what the
 * signature has to publish (`CallableSig::$captures` is where that lands).
 *
 * @param array<string, IR\FunctionDecl> $byName
 * @param callable(IR\FunctionDecl): list<string> $capturesOf
 * @return array<string, array{captures: list<string>, params: list<string>}>
 */
function captureClosure(array $byName, callable $capturesOf): array
{
    $meta = [];
    $owned = [];
    foreach ($byName as $name => $function) {
        $meta[$name] = [
            'captures' => $capturesOf($function),
            'params' => $function->params,
        ];
        $owned[$name] = boundLocalsInBlock($function->body)
            + \array_fill_keys($function->params, true);
    }

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($meta as $name => &$info) {
            $needed = [];
            foreach (collectLambdaRefsInBlock(
                $byName[$name]->body,
                static fn (string $ref): bool => isset($byName[$ref]) && $byName[$ref]->kind->takesCaptures(),
            ) as $ref) {
                $child = $meta[$ref] ?? null;
                if ($child === null) {
                    continue;
                }
                foreach ($child['captures'] as $capture) {
                    if (isset($owned[$name][$capture]) || \in_array($capture, $info['captures'], true)) {
                        continue;
                    }

                    $needed[] = $capture;
                }
            }
            $needed = \array_values(\array_unique($needed));
            if ($needed !== []) {
                $info['captures'] = [...$info['captures'], ...$needed];
                $changed = true;
            }
        }
        unset($info);
    }

    foreach ($meta as &$info) {
        \sort($info['captures']);
    }
    unset($info);

    return $meta;
}

/**
 * The capture layout every consumer reads: the one producer, with the same shape the optimizer
 * and the backends already expected.
 *
 * @param array<string, IR\FunctionDecl> $byName
 * @return array<string, array{captures: list<string>, params: list<string>}>
 */
function captureMeta(array $byName): array
{
    return captureClosure(
        $byName,
        static fn (IR\FunctionDecl $function): array => freeLocalsInBlock(
            $function->body,
            \array_fill_keys($function->params, true),
        ),
    );
}

/** The lifted callables of a list of functions, keyed by name. @param list<IR\FunctionDecl> $functions */
function captureCandidates(array $functions): array
{
    $index = [];
    foreach ($functions as $function) {
        if ($function->kind->takesCaptures()) {
            $index[$function->name] = $function;
        }
    }

    return $index;
}

/**
 * Build the table for one module.
 *
 * Evidence slots come from the declaration itself (`FunctionDecl::$evidenceScope`, recorded where
 * the declaration was created), never from a name prefix:
 *
 * - a source declaration's `E` is its solved constraint list, in definition-time order and
 *   multiplicity, and `V` is its parameter list minus that prefix;
 * - a lifted callable's `E` and `C` are a **split of the one capture vector the binder built** —
 *   position i is evidence when that name is in the enclosing scope's slot list. They are never
 *   derived independently, so the published `|E| + |C|` is the vector's length by construction.
 *
 * @param list<IR\FunctionDecl> $functions
 */
function table(array $functions, string $module = ''): CallableTable
{
    $meta = captureMeta(captureCandidates($functions));
    $byEntry = [];

    foreach ($functions as $function) {
        $scope = $function->evidenceScope;
        $evidenceNames = [];
        $captures = $meta[$function->name]['captures'] ?? [];

        if ($function->kind === IR\FunctionKind::Source || $function->kind === IR\FunctionKind::Worker) {
            $evidenceNames = $scope;
        } else {
            $inScope = \array_fill_keys($scope, true);
            $captures = [];
            foreach ($meta[$function->name]['captures'] ?? [] as $capture) {
                if (isset($inScope[$capture])) {
                    $evidenceNames[] = $capture;

                    continue;
                }

                $captures[] = $capture;
            }
        }

        $params = \array_values(\array_diff($function->params, $evidenceNames));

        $evidence = [];
        foreach ($evidenceNames as $slot) {
            $evidence[] = new EvidenceSlot($slot);
        }

        [$result, $returnsCallable] = resultConventions($function, \count($evidenceNames));

        $byEntry[$function->name] = new CallableSig(
            $function->name,
            $function->kind,
            $module,
            $evidence,
            $captures,
            $params,
            $result,
            $result === ResultConvention::Action || $result === ResultConvention::Unclassified
                ? AcquisitionKind::Callable
                : (($params === [] && $function->kind === IR\FunctionKind::Source)
                    ? AcquisitionKind::EvaluateBinding
                    : AcquisitionKind::Callable),
            $returnsCallable,
        );
    }

    return new CallableTable($byEntry, $meta, $module);
}

/**
 * The conventions a declaration publishes, from its own type and body kind.
 *
 * `$evidenceSlots` is how many leading parameters the declaration's type does not mention (its
 * solved dictionaries): they do not consume arrows, so the value parameters are
 * `|params| - |E|`.
 *
 * A declaration whose type is unknown is `Unclassified` — a named gap, never a default. Since
 * lowering records the checker's type on lifted callables too, that case is now limited to a
 * synthetic lambda the checker left no type on (`\x -> …` desugared inside lowering).
 *
 * @return array{ResultConvention, ?bool}
 */
function resultConventions(IR\FunctionDecl $function, int $evidenceSlots = 0): array
{
    if ($function->kind === IR\FunctionKind::Worker) {
        return [ResultConvention::Value, null];
    }

    $type = $function->type;
    if ($type === null) {
        return [ResultConvention::Unclassified, null];
    }

    if (functionReturnsIo($type)) {
        return [ResultConvention::Action, null];
    }

    $arrows = 0;
    $cursor = stripConstraints($type);
    while ($cursor instanceof TypeArrow) {
        ++$arrows;
        $cursor = $cursor->to;
    }

    $valueParameters = \count($function->params) - $evidenceSlots;
    $returnsCallable = $arrows > $valueParameters;

    return [
        $returnsCallable ? ResultConvention::Callable : ResultConvention::Value,
        $returnsCallable,
    ];
}
