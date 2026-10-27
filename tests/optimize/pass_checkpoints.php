<?php declare(strict_types=1);

// Per-pass checkpoints and the specialization boundary check that rejects
// unbound temps.
//
// Pinned: the checkpoint is inert while the switch is off and the harness turns
// it on for its own run; a stage that leaves an undefined temp fails with its own
// label; the capture-binding exception is narrow; and no restoration hook remains
// to hide such a failure.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\IR\Validate;
use Moggi\Optimize\PassCheckpoints;
use Moggi\Optimize\Specialize;

/** @var list<string> $failures */
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$throws = static function (callable $call): ?Validate\InvalidIr {
    try {
        $call();
    } catch (Validate\InvalidIr $e) {
        return $e;
    }

    return null;
};

$local = static fn (string $name): IR\Local => new IR\Local($name);
$temp = static fn (int $id): IR\Temp => new IR\Temp($id);
$fn = static fn (string $name, IR\Block $body, IR\FunctionKind $kind = IR\FunctionKind::Source): IR\FunctionDecl
    => new IR\FunctionDecl($name, [], null, $body, kind: $kind);

$good = $fn('good', new IR\Block([
    new IR\Assign(1, new IR\ConstInt(1)),
    new IR\Ret($temp(1)),
]));
$badTemp = $fn('badTemp', new IR\Block([new IR\Ret($temp(7))]));
$spec = $fn('__spec_clone', new IR\Block([new IR\Ret($local('captured'))]), IR\FunctionKind::SpecClone);
$plain = $fn('plain', new IR\Block([new IR\Ret($local('captured'))]));
$specLookalike = $fn('__spec_lookalike', new IR\Block([new IR\Ret($local('captured'))]));
$module = new IR\Module([$good], [], [], null, 'Main', 'Main.mog');

PassCheckpoints\setEnabled(false);
$off = $throws(static fn () => PassCheckpoints\runOnFunctions(
    'test-stage',
    $module,
    [$good],
    static fn (array $fns): array => [$badTemp],
    false,
));
$check($off === null, 'an invalid stage was rejected with the checkpoint flag off');

PassCheckpoints\setEnabled(true);
PassCheckpoints\enterOptimize();
try {
    $error = $throws(static fn () => PassCheckpoints\runOnFunctions(
        'test-stage',
        $module,
        [$good],
        static fn (array $fns): array => [$badTemp],
        false,
    ));
} finally {
    PassCheckpoints\leaveOptimize();
}
$check($error !== null, 'a stage that left an undefined temp was accepted');
$check($error !== null && $error->pass === 'test-stage', 'the failure does not carry the producing stage label');
$check(
    $error !== null && \str_contains($error->getMessage(), 'temp t7'),
    'the stage failure does not name the undefined temp',
);

PassCheckpoints\setEnabled(true);
PassCheckpoints\enterOptimize();
try {
    $inWindow = $throws(static fn () => PassCheckpoints\runOnFunctions(
        'before-capture-binding',
        $module,
        [$spec],
        static fn (array $fns): array => $fns,
        true,
    ));
    $afterWindow = $throws(static fn () => PassCheckpoints\runOnFunctions(
        'after-capture-binding',
        $module,
        [$spec],
        static fn (array $fns): array => $fns,
        false,
    ));
    $plainInWindow = $throws(static fn () => PassCheckpoints\runOnFunctions(
        'before-capture-binding',
        $module,
        [$plain],
        static fn (array $fns): array => $fns,
        true,
    ));
    $lookalikeInWindow = $throws(static fn () => PassCheckpoints\runOnFunctions(
        'before-capture-binding',
        $module,
        [$specLookalike],
        static fn (array $fns): array => $fns,
        true,
    ));
} finally {
    PassCheckpoints\leaveOptimize();
}
$check($inWindow === null, 'a SpecClone reading a free local was rejected inside the capture window');
$check($afterWindow !== null, 'a SpecClone reading a free local was accepted after capture binding');
$check($plainInWindow !== null, 'the capture exception leaked to a function of another kind');
$check($lookalikeInWindow !== null, 'the capture exception was decided by the `__spec_` spelling, not the kind');

$check(
    ! \function_exists('Moggi\\Optimize\\Specialize\\restoreUnboundAfterOptimize'),
    'a restoration hook exists',
);
$check(
    ! \property_exists(PassCheckpoints\State::class, 'restoreCandidates'),
    'a restore-site counter exists',
);

$boundary = null;
try {
    Specialize\assertNoUnboundTempsAfterSpecialize(
        new IR\Module([$badTemp], [], [], null, 'Main', 'Main.mog'),
        'Main',
    );
} catch (\RuntimeException $e) {
    $boundary = $e->getMessage();
}
$check($boundary !== null, 'a module with unbound temps passed the specialization boundary check');
$check(
    $boundary !== null && \str_contains($boundary, 'Main: badTemp[7]'),
    'the specialization boundary failure does not name the module and function: ' . ($boundary ?? '<no failure>'),
);

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " pass-checkpoint failure(s):\n  " . \implode("\n  ", $failures),
    );
}

echo "pass checkpoint tests passed\n";
