<?php declare(strict_types=1);

// F1a/F2a: per-pass checkpoints, the stage trace, and the specialization
// boundary check that rejects unbound temps.
//
// Pinned: the checkpoint is inert unless `MOGGI_IR_PASS_CHECKPOINTS=1`; a stage
// that leaves an undefined temp fails with its own label; the capture-binding
// exception is narrow; the trace line is the exact `stage-invalid` form; and no
// restoration hook remains to hide such a failure.

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
$fn = static fn (string $name, IR\Block $body): IR\FunctionDecl => new IR\FunctionDecl($name, [], null, $body);

$good = $fn('good', new IR\Block([
    new IR\Assign(1, new IR\ConstInt(1)),
    new IR\Ret($temp(1)),
]));
$badTemp = $fn('badTemp', new IR\Block([new IR\Ret($temp(7))]));
$spec = $fn('__spec_clone', new IR\Block([new IR\Ret($local('captured'))]));
$plain = $fn('plain', new IR\Block([new IR\Ret($local('captured'))]));
$module = new IR\Module([$good], [], [], null, 'Main', 'Main.mog');

// ---- the flag is inert by default ---------------------------------------

\putenv('MOGGI_IR_PASS_CHECKPOINTS=0');
$off = $throws(static fn () => PassCheckpoints\runOnFunctions(
    'test-stage',
    $module,
    [$good],
    static fn (array $fns): array => [$badTemp],
    false,
));
$check($off === null, 'an invalid stage was rejected with the checkpoint flag off');

// ---- a failing stage names itself ---------------------------------------

\putenv('MOGGI_IR_PASS_CHECKPOINTS=1');
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

// ---- the trace line is the exact stage-invalid form ----------------------

$check(
    PassCheckpoints\stageEventLine('final-dce', 'show', [211])
        === "MOGGI_IR_TRACE stage-invalid pass=final-dce function=show temps=[211]\n",
    'the stage trace line changed shape: ' . PassCheckpoints\stageEventLine('final-dce', 'show', [211]),
);

// ---- the capture window exception is narrow ------------------------------

\putenv('MOGGI_IR_PASS_CHECKPOINTS=1');
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
} finally {
    PassCheckpoints\leaveOptimize();
}
$check($inWindow === null, 'a __spec_ clone reading a free local was rejected inside the capture window');
$check($afterWindow !== null, 'a __spec_ clone reading a free local was accepted after capture binding');
$check($plainInWindow !== null, 'the capture exception leaked to a plainly named function');

// ---- no restoration hook, and the boundary check names its finding ------

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

echo "pass checkpoint and stage trace tests passed\n";
