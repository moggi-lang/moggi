<?php declare(strict_types=1);

namespace Moggi\Optimize\PassCheckpoints;

use Moggi\IR;
use Moggi\IR\Validate;

/**
 * Per-pass checkpoints. Lifted callables read free locals until
 * `bindLambdaCaptures`, so a `__spec_` clone is excepted inside that window only;
 * nested runs report `d2/<stage>`.
 *
 * The checkpoints are off unless the harness turns them on (see
 * {@see setEnabled()}): validating the whole module after every pass is the
 * suite's cost to pay, not every compile's.
 */
final class State
{
    public static int $depth = 0;

    public static bool $enabled = false;

    /** @var list<array<string, array{label: string, function: string, temps: list<int>}>> */
    public static array $frames = [];

    /** @var list<string> stage labels whose capture-window exception fired */
    public static array $excepted = [];
}

/** Turn the per-pass checkpoints on for this process (the test harness does). */
function setEnabled(bool $enabled): void
{
    State::$enabled = $enabled;
}

function enabled(): bool
{
    return State::$enabled;
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
        if ($captureWindow && isCaptureException($e, $functions)) {
            State::$excepted[] = $qualified . ':' . $e->function;

            return;
        }

        $temps = tempsFromMessage($e->getMessage());
        if ($temps !== []) {
            recordUnbound($qualified, $e->function, $temps);
        }

        throw $e;
    }
}

/**
 * A specialization clone reading a free local before `bindLambdaCaptures`.
 *
 * The clone is identified by its declared kind, not its `__spec_` spelling: the
 * prefix is presentation, and a source function may be named the same way.
 *
 * @param list<IR\FunctionDecl> $functions
 */
function isCaptureException(Validate\InvalidIr $e, array $functions): bool
{
    if (! \str_contains($e->getMessage(), 'unbound local')) {
        return false;
    }

    foreach ($functions as $function) {
        if ($function->name === $e->function) {
            return $function->kind === IR\FunctionKind::SpecClone;
        }
    }

    return false;
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

