<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use function Moggi\Backend\Meta\tempReadCounts;
use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;

/**
 * What one module's php emission carries while it runs.
 *
 * One object threads through every emit function, as the jvm and dotnet emitters' `EmitEnv` does:
 * the module facts `emitModule` settles once, the per-function values `emitFunction` sets on a
 * clone, and the per-arm values a nested match sets on its own clone. A derived env is an explicit
 * `clone`, because an object — unlike the array this used to be — does not copy by itself.
 */
final class EmitEnv
{
    /**
     * @param array<string, string> $moduleAsNames @param array<string, string> $constructorRenames
     *  @param array<string, string> $externalFns @param array<string, int> $functionArity
     *  @param array<string, bool> $boolConstructors @param array<string, string> $newtypeConstructors
     *  @param array<string, string> $phpNames */
    public function __construct(
        public ?string $moduleNamespace = null,
        public string $artifactModuleName = '',
        public array $moduleAsNames = [],
        public array $constructorRenames = [],
        public array $externalFns = [],
        public array $functionArity = [],
        public array $boolConstructors = [],
        public array $newtypeConstructors = [],
        public array $phpNames = [],
        public ?SourceMapBuilder $sourceMap = null,
    ) {
        $this->snapshots = new SnapshotCounter();
    }

    /** @var array<string, \Moggi\IR\FunctionDecl> functions emitted as closures, by source name */
    public array $lambdaIndex = [];

    /** @var array<string, array{captures: array<int, string>, params: array<int, string>}> */
    public array $lambdaMeta = [];

    /** @var list<string> the parameters of the function being emitted, captures included */
    public array $functionParams = [];

    /** @var list<string> the declared parameters `IR\TailRecall` overwrites before jumping back */
    public array $tailRecParams = [];

    /** Generated name of the function being emitted, for the frame marks its body writes. */
    public string $frameFunction = '';

    /** @var ?array<int, int> read counts of the body being emitted ({@see tempReadCounts}) */
    public ?array $tempReads = null;

    /** @var array<string, string> pattern binder => the local its scrutinee position was bound to */
    public array $patternLocals = [];

    /** The scrutinee text the current arm's pattern locals were taken from, when it has one. */
    public ?string $matchScrutineeOperand = null;

    /** @var list<bool> whether each argument of the intrinsic being emitted is a leaf */
    public array $intrinsicArgLeaves = [];

    /** How deep the current `match` emission is nested; below 0 the enclosing binders are saved. */
    public int $matchDepth = 0;

    /** Module-wide, so `$__scrut_N` does not repeat across two functions' bodies. */
    public SnapshotCounter $snapshots;
}

/**
 * The module's `$__scrut_N` name supply.
 *
 * It is a field rather than a counter on the env because a clone — the derived env a nested match
 * or a function body emits through — copies values, and these names are unique per module.
 */
final class SnapshotCounter
{
    private int $next = 0;

    public function next(): int
    {
        return $this->next++;
    }
}
