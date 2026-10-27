<?php declare(strict_types=1);

/**
 * Return-call fusion: the rule, its contract, and what each backend emits for it.
 *
 * `Moggi\Backend\Meta\returnedCall()` decides; the three emitters only render the decision. A
 * `Call`/`CallValue` statement whose destination is read exactly once, by the `Ret` immediately
 * after it, is emitted as a direct return — the store and the load of that temp disappear, which on
 * the JVM and .NET is two instructions fewer per returned call. The IR does not change: a call
 * stays a statement that defines its destination, `Ret` still reads an operand, and no operand
 * operand comes back.
 *
 * The refusal cases are what make the decision sound: a destination with a second reader (a read
 * after the return included) keeps its store, a statement between the call and the return keeps it,
 * and the call's arguments are evaluated where the call stands — which is why fusing can neither
 * reorder nor duplicate them.
 *
 * The JVM half needs `javap` (`Backend::describeEmit` runs it).
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\Modules\EmitPlan;

use function Moggi\Backend\backendById;
use function Moggi\Backend\Meta\returnedCall;
use function Moggi\Backend\Meta\tempReadCounts;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$decl = static fn (string $name, array $params, array $items): IR\FunctionDecl => new IR\FunctionDecl(
    $name,
    $params,
    null,
    new IR\Block($items),
);

$call = static fn (): IR\Call => new IR\Call('add2', [new IR\Local('x'), new IR\ConstInt(2)], 0);

// --- The rule -----------------------------------------------------------------------------------
// Each case is one block; the expected answer is the call itself or null.
$table = [
    'the return is the destination\'s only reader' => [
        [$call(), new IR\Ret(new IR\Temp(0))],
        true,
    ],
    'the destination is read again after the return' => [
        [$call(), new IR\Ret(new IR\Temp(0)), new IR\Assign(1, new IR\Temp(0))],
        false,
    ],
    'a statement sits between the call and the return' => [
        [$call(), new IR\Assign(1, new IR\ConstInt(7)), new IR\Ret(new IR\Temp(0))],
        false,
    ],
    'the return answers with another temp' => [
        [$call(), new IR\Ret(new IR\Temp(1))],
        false,
    ],
    'the pair is an arm body, on its own' => [
        [$call(), new IR\Ret(new IR\Temp(0))],
        true,
    ],
];
foreach ($table as $case => [$items, $fuses]) {
    $block = new IR\Block($items);
    $decision = returnedCall($items[0], $items[1] ?? null, tempReadCounts($block));
    $assert(
        ($decision === $items[0]) === $fuses,
        "`{$case}` must " . ($fuses ? 'fuse' : 'keep its store'),
    );
}

// --- What the emitters print --------------------------------------------------------------------
$add2 = $decl('add2', ['a', 'b'], [new IR\Ret(new IR\ConstInt(0))]);

/** The emitted text of one function, with the backend's own rendering. */
$emitted = static function (string $backendId, IR\FunctionDecl $function) use ($add2): string {
    $relative = match ($backendId) {
        'jvm' => 'Fusion.class',
        'dotnet' => 'Fusion.il',
        default => 'Fusion.php',
    };
    $backend = backendById($backendId);
    $emit = $backend->emit(
        new IR\Module([$add2, $function], [], [], null),
        'Fusion.mog',
        [
            'artifactModuleName' => 'Fusion',
            'outputRelative' => $relative,
            'plan' => EmitPlan::forTarget($backendId, $relative),
        ],
    );

    return $backend->describeEmit($emit, [$function->name]);
};

$fusing = $decl('fusing', ['x'], [$call(), new IR\Ret(new IR\Temp(0))]);
$refusing = $decl('refusing', ['x'], [
    $call(),
    new IR\Assign(1, new IR\ConstInt(7)),
    new IR\Ret(new IR\Temp(0)),
]);

$phpFusing = $emitted('php', $fusing);
$phpRefusing = $emitted('php', $refusing);
$assert(
    \str_contains($phpFusing, 'return add2($x, 2);') && ! \str_contains($phpFusing, '$__t'),
    "PHP must return the call's value directly, got:\n{$phpFusing}",
);
$assert(
    \str_contains($phpRefusing, '$__t0 = add2($x, 2);') && \str_contains($phpRefusing, 'return $__t0;'),
    "PHP must keep the store when the call is not the block's last action, got:\n{$phpRefusing}",
);

$ilFusing = $emitted('dotnet', $fusing);
$ilRefusing = $emitted('dotnet', $refusing);
$assert(
    ! \str_contains($ilFusing, 'stloc'),
    ".NET must return the call's value without a local, got:\n{$ilFusing}",
);
$assert(
    \str_contains($ilRefusing, 'stloc') && \str_contains($ilRefusing, 'ldloc'),
    ".NET must keep the store and the load when the call is not the block's last action, got:\n{$ilRefusing}",
);

$jvmFusing = $emitted('jvm', $fusing);
$jvmRefusing = $emitted('jvm', $refusing);
// The JVM gives a function's parameters slots of their own, so the claim is not "no astore at all"
// but "the call's value reaches the return without being stored".
$assert(
    \preg_match('/invokestatic[^\n]*add2[^\n]*\n\s*\d+: areturn/', $jvmFusing) === 1,
    "JVM must return the call's value straight away, got:\n{$jvmFusing}",
);
$assert(
    \preg_match('/invokestatic[^\n]*add2[^\n]*\n\s*\d+: astore/', $jvmRefusing) === 1
        && \preg_match('/aload[^\n]*\n\s*\d+: areturn/', $jvmRefusing) === 1,
    "JVM must store the call's value and load it back when the call is not the block's last action, got:\n{$jvmRefusing}",
);

echo "return-call fusion: rule and all three backends\n";
echo "--- php, fusing:\n{$phpFusing}";
echo "--- php, refusing:\n{$phpRefusing}";
echo "--- jvm, fusing:\n{$jvmFusing}";
echo "--- jvm, refusing:\n{$jvmRefusing}";
echo "--- dotnet, fusing:\n{$ilFusing}";
echo "--- dotnet, refusing:\n{$ilRefusing}";
