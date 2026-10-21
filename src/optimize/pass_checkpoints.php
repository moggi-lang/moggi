<?php declare(strict_types=1);

namespace Moggi\Optimize\PassCheckpoints;

use Moggi\IR;
use Moggi\IR\Validate;

/**
 * Per-pass checkpoints (`MOGGI_IR_PASS_CHECKPOINTS=1`) and the stage trace
 * (`MOGGI_IR_TRACE=1`). Lifted callables read free locals until
 * `bindLambdaCaptures`, so a `__spec_` clone is excepted inside that window only;
 * nested runs report `d2/<stage>`.
 */
final class State
{
    public static int $depth = 0;

    /** @var list<array<string, array{label: string, function: string, temps: list<int>}>> */
    public static array $frames = [];

    /** @var list<string> stage labels whose capture-window exception fired */
    public static array $excepted = [];
}

function enabled(): bool
{
    return \getenv('MOGGI_IR_PASS_CHECKPOINTS') === '1';
}

function traceEnabled(): bool
{
    return \getenv('MOGGI_IR_TRACE') === '1';
}

/**
 * Writes a trace line to stderr and, when `MOGGI_IR_TRACE_FILE` names a path,
 * appends it there too.
 */
function emit(string $line): void
{
    \fwrite(\STDERR, $line);

    $file = \getenv('MOGGI_IR_TRACE_FILE');
    if ($file === false || $file === '') {
        return;
    }
    $handle = @\fopen($file, 'ab');
    if ($handle === false) {
        return;
    }
    \flock($handle, \LOCK_EX);
    \fwrite($handle, $line);
    \flock($handle, \LOCK_UN);
    \fclose($handle);
}

function enterOptimize(): void
{
    State::$frames[] = [];
    ++State::$depth;
}

/** Merges nested findings into the parent with their depth prefix. */
function leaveOptimize(): void
{
    $frame = \array_pop(State::$frames) ?? [];
    $depth = State::$depth;
    --State::$depth;

    if ($depth <= 1) {
        return;
    }

    $parent = \count(State::$frames) - 1;
    if ($parent < 0) {
        return;
    }
    foreach ($frame as $function => $info) {
        $info['label'] = 'd' . $depth . '/' . $info['label'];
        State::$frames[$parent][$function] ??= $info;
    }
}

function stageLabel(string $label): string
{
    return State::$depth > 1 ? 'd' . State::$depth . '/' . $label : $label;
}

/**
 * @param list<IR\FunctionDecl> $functions
 * @param callable(list<IR\FunctionDecl>): list<IR\FunctionDecl> $stage
 * @return list<IR\FunctionDecl>
 */
function runOnFunctions(string $label, IR\Module $module, array $functions, callable $stage, bool $captureWindow): array
{
    $result = $stage($functions);
    checkFunctions($label, $module, $result, $captureWindow);

    return $result;
}

/** @param callable(IR\Module): IR\Module $stage */
function runOnModule(string $label, IR\Module $module, callable $stage): IR\Module
{
    $result = $stage($module);
    checkFunctions($label, $result, $result->functions, false);

    return $result;
}

/**
 * @param list<IR\FunctionDecl> $functions
 */
function checkFunctions(string $label, IR\Module $module, array $functions, bool $captureWindow): void
{
    if (! enabled()) {
        return;
    }

    $qualified = stageLabel($label);
    $source = $module->sourceFile !== ''
        ? $module->sourceFile
        : ($module->moduleName !== '' ? $module->moduleName : '<module>');

    try {
        Validate\validateFunctionList($functions, $qualified, $source);
    } catch (Validate\InvalidIr $e) {
        if ($captureWindow && isCaptureException($e)) {
            State::$excepted[] = $qualified . ':' . $e->function;

            return;
        }

        $temps = tempsFromMessage($e->getMessage());
        if ($temps !== []) {
            recordUnbound($qualified, $e->function, $temps);
            emitStageEvent($qualified, $e->function, $temps);
        }

        throw $e;
    }
}

/** A `__spec_` clone reading a free local before `bindLambdaCaptures`. */
function isCaptureException(Validate\InvalidIr $e): bool
{
    return \str_starts_with($e->function, '__spec_')
        && \str_contains($e->getMessage(), 'unbound local');
}

/** @return list<int> */
function tempsFromMessage(string $message): array
{
    if (! \preg_match('/temp t(\d+) read before/', $message, $m)) {
        return [];
    }

    return [(int) $m[1]];
}

/** @param list<int> $temps */
function recordUnbound(string $label, string $function, array $temps): void
{
    $frame = \count(State::$frames) - 1;
    if ($frame < 0) {
        return;
    }
    State::$frames[$frame][$function] ??= [
        'label' => $label,
        'function' => $function,
        'temps' => $temps,
    ];
}

/** @param list<int> $temps */
function emitStageEvent(string $label, string $function, array $temps): void
{
    if (! traceEnabled()) {
        return;
    }

    emit(stageEventLine($label, $function, $temps));
}

/** @param list<int> $temps */
function stageEventLine(string $label, string $function, array $temps): string
{
    return 'MOGGI_IR_TRACE stage-invalid pass=' . $label
        . ' function=' . $function
        . ' temps=[' . \implode(',', $temps) . "]\n";
}
