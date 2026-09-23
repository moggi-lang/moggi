#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

// Moggi\__apply, the runtime partial-application hot path: the semantics of every shape the
// backends can emit (the `['__partial', arity, callable, ...applied]` cell layout is an ABI shared
// with the JVM/.NET `Partial` classes and the emitted call sites), plus a differential check against
// the pre-optimization algorithm frozen at the bottom.
//
// Only shapes reachable from well-typed Moggi are asserted as semantics — the backends disagree on
// over-application (PHP drops the surplus argument, JVM/.NET re-apply it) — but the differential
// check does cover the surplus cases, so PHP's side of that divergence cannot change silently.

require_once $root . '/src/backend/php/runtime.php';

use function Moggi\__apply;
use function Moggi\fix;

$failures = [];
$checks = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures, &$checks): void {
    ++$checks;
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};

// Callees (global scope, so `'\name'` partial cells resolve them)

function ap0(): string
{
    return 'z';
}
function ap1(int $a): int
{
    return $a + 1;
}
function ap2(int $a, int $b): int
{
    return $a + $b;
}
function ap3(int $a, int $b, int $c): int
{
    return $a + $b + $c;
}
function ap4(int $a, int $b, int $c, int $d): int
{
    return $a + $b + $c + $d;
}
function ap5(int $a, int $b, int $c, int $d, int $e): int
{
    return $a + $b + $c + $d + $e;
}
function apVar(int ...$xs): int
{
    return array_sum($xs);
}

final class ApStatic
{
    public static function mul(int $a, int $b): int
    {
        return $a * $b;
    }
}

final class ApCallableArray
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public static function statAdd(int $a, int $b): int
    {
        return $a + $b;
    }
}

$cl1 = static fn (int $a): int => $a + 1;
$cl2 = static fn (int $a, int $b): int => $a + $b;
$cl3 = static fn (int $a, int $b, int $c): int => $a + $b + $c;

// 1. Saturation: every arity, every number of already-applied arguments

$saturate = [
    // [$partialCell, $incomingArgs, $expected]
    [['__partial', 0, '\ap0'], [], 'z'],
    [['__partial', 1, '\ap1'], [41], 42],                       // applied 0, need 1
    [['__partial', 1, '\ap1', 41], [], 42],                     // already saturated
    [['__partial', 2, '\ap2'], [1, 2], 3],                      // applied 0, need 2
    [['__partial', 2, '\ap2'], [1], [  // applied 0, need 2 -> still short
        '__partial', 2, '\ap2', 1,
    ]],
    [['__partial', 2, '\ap2', 1], [2], 3],                      // applied 1, need 1
    [['__partial', 2, '\ap2', 1, 2], [], 3],                    // already saturated
    [['__partial', 3, '\ap3', 1], [2, 3], 6],                   // applied 1, need 2
    [['__partial', 3, '\ap3', 1, 2], [3], 6],                   // applied 2, need 1
    [['__partial', 3, '\ap3', 1, 2, 3], [], 6],                 // already saturated
    [['__partial', 4, '\ap4', 1, 2, 3], [4], 10],               // applied 3, need 1
    [['__partial', 4, '\ap4', 1, 2], [3, 4], 10],               // applied 2, need 2
    [['__partial', 4, '\ap4', 1], [2, 3, 4], 10],               // applied 1, need 3
    [['__partial', 5, '\ap5', 1, 2, 3, 4], [5], 15],            // applied 4, need 1
    [['__partial', 5, '\ap5', 1, 2, 3], [4, 5], 15],            // applied 3, need 2
    [['__partial', 5, '\ap5', 1, 2, 3, 4, 5], [], 15],          // already saturated
    [['__partial', 3, '\apVar', 1], [2, 3], 6],                 // variadic callee
];

foreach ($saturate as $i => [$cell, $args, $expected]) {
    $assertSame(
        $expected,
        __apply($cell, ...$args),
        "saturate case #{$i}: " . json_encode($cell) . ' applied ' . json_encode($args),
    );
}

// Stepwise currying: applying one argument at a time must reach the same result
// as a single multi-argument application.
$expectedResult = [1 => 2, 2 => 3, 3 => 6, 4 => 10, 5 => 15];
foreach ([1, 2, 3, 4, 5] as $arity) {
    $name = '\\ap' . $arity;
    $stepwise = ['__partial', $arity, $name];
    for ($i = 1; $i <= $arity; ++$i) {
        $stepwise = __apply($stepwise, $i);
    }
    $assertSame(
        $expectedResult[$arity],
        $stepwise,
        "stepwise saturation at arity {$arity}",
    );
    $assertSame(
        $expectedResult[$arity],
        __apply(['__partial', $arity, $name], ...range(1, $arity)),
        "one-shot saturation at arity {$arity}",
    );
}

// 2. Unsaturated cells keep the exact documented layout

$assertSame(
    ['__partial', 2, '\ap2', 1],
    __apply(['__partial', 2, '\ap2'], 1),
    'extending a 0-applied cell must append in order',
);
$assertSame(
    ['__partial', 3, '\ap3', 1, 2],
    __apply(['__partial', 3, '\ap3', 1], 2),
    'extending a 1-applied cell must append in order',
);
$assertSame(
    ['__partial', 4, '\ap4', 1, 2, 3],
    __apply(['__partial', 4, '\ap4', 1], 2, 3),
    'extending by several arguments must append in order',
);
$assertSame(
    array_keys(['__partial', 3, '\ap3', 1, 2]),
    array_keys(__apply(['__partial', 3, '\ap3', 1], 2)),
    'an extended cell must stay a list (0-indexed)',
);

// The caller's cell must not be mutated — PHP arrays are values.
$original = ['__partial', 3, '\ap3', 1];
__apply($original, 2);
$assertSame(['__partial', 3, '\ap3', 1], $original, 'extending must not mutate the caller cell');

// 3. Bare callables (no cell): names, closures, first-class callables, objects

$assertSame(42, __apply('\ap1', 41), 'string function name, exact arity');
$assertSame(3, __apply('\ap2', 1, 2), 'string function name, two args');
$assertSame(['__partial', 2, '\ap2', 1], __apply('\ap2', 1), 'string name under-applied -> cell');
$assertSame(['__partial', 2, '\ap2', 1], __apply('\ap2', 1), 'arity known without a cell');

$assertSame(42, __apply($cl1, 41), 'closure, exact arity');
$assertSame(3, __apply($cl2, 1, 2), 'closure, two args');
$assertSame('__partial', __apply($cl2, 1)[0] ?? null, 'closure under-applied -> cell');
$assertSame(3, __apply(__apply($cl2, 1), 2), 'closure curried');

$assertSame(42, __apply('\ap1'(...), 41), 'first-class callable, exact arity');
$assertSame('__partial', (__apply('\ap2'(...), 1)[0] ?? null), 'first-class callable curried');
$assertSame(3, __apply(__apply('\ap2'(...), 1), 2), 'first-class callable curried to result');

// Callables that are neither a Closure nor a plain function-name string used to
// crash with a TypeError from ReflectionFunction; they are normalised instead.
$invokable = new class () {
    public function __invoke(int $a, int $b): int
    {
        return $a * $b;
    }
};
$assertSame(12, __apply($invokable, 3, 4), 'invokable object, exact arity');
$assertSame('__partial', (__apply($invokable, 3)[0] ?? null), 'invokable object, under-applied');
$assertSame(12, __apply(__apply($invokable, 3), 4), 'invokable object, curried');

$assertSame(12, __apply('\ApStatic::mul', 3, 4), '`Class::method` string, exact arity');
$assertSame('__partial', (__apply('\ApStatic::mul', 3)[0] ?? null), '`Class::method` string, under-applied');
$assertSame(12, __apply(__apply('\ApStatic::mul', 3), 4), '`Class::method` string, curried');

// 3b. Callable arrays, and why the parameter type is `callable|array`
//
// A partial cell is an array that is NOT callable, so the parameter has to admit
// plain arrays (`array`) while still rejecting stray scalars and objects
// (`callable`). Both members are load-bearing — and because that check runs at
// the boundary, __apply needs no is_callable() of its own.

$assertSame(3, __apply([new ApCallableArray(), 'add'], 1, 2), 'callable array is invoked directly');
$assertSame(3, __apply(['ApCallableArray', 'statAdd'], 1, 2), 'static callable array is invoked directly');

// A callable array is never turned into a partial cell: PHP resolves its own
// arity and reports a short call itself.
try {
    __apply([new ApCallableArray(), 'add'], 1);
    $assertSame('ArgumentCountError', 'no throw', 'under-applied callable array must not build a cell');
} catch (ArgumentCountError) {
    $assertSame('ArgumentCountError', 'ArgumentCountError', 'under-applied callable array raises');
}

// The union's `array` member is what admits a non-callable partial cell...
$assertSame(false, is_callable(['__partial', 2, '\ap2', 1]), 'a partial cell is not callable');
$assertSame(3, __apply(['__partial', 2, '\ap2', 1], 2), 'a non-callable array is still accepted');

// ...and `callable` is what rejects everything else.
foreach ([42, 1.5, null, true, new stdClass()] as $junk) {
    try {
        __apply($junk, 1);
        $assertSame('TypeError', 'no throw', 'non-callable ' . get_debug_type($junk) . ' must be rejected');
    } catch (TypeError) {
        $assertSame('TypeError', 'TypeError', 'non-callable ' . get_debug_type($junk) . ' rejected');
    }
}

// 4. fix# — strict fixpoint, which routes through __apply internally

$fact = fix(static fn (callable $rec): callable => static fn (int $n): int => $n <= 1 ? 1 : $n * $rec($n - 1));
$assertSame(3628800, $fact(10), 'fix# factorial');

$sumTo = fix(
    static fn (callable $rec): callable => static fn (int $n, int $acc): int => $n === 0 ? $acc : $rec($n - 1, $acc + $n),
);
$assertSame(55, $sumTo(10, 0), 'fix# two-parameter recursion');
$assertSame(5050, $sumTo(100, 0), 'fix# two-parameter recursion, deeper');

// 5. Differential check against the pre-optimization algorithm (frozen)

/** The algorithm this file replaced. Kept verbatim so behaviour cannot drift. */
$reference = static function (callable|array $fn, mixed ...$args): mixed {
    if (\is_array($fn) && ($fn[0] ?? null) === '__partial') {
        $arity = $fn[1];
        $name = $fn[2];
        $applied = count($fn) - 3;
        $incoming = count($args);

        if ($applied + $incoming >= $arity) {
            $needFromArgs = $arity - $applied;

            return match (true) {
                $applied === 0 => $name(...\array_slice($args, 0, $arity)),
                $needFromArgs === 0 => $name(...\array_slice($fn, 3, $arity)),
                default => $name(...[...\array_slice($fn, 3), ...\array_slice($args, 0, $needFromArgs)]),
            };
        }

        return ['__partial', $arity, $name, ...\array_slice($fn, 3), ...$args];
    }

    if (is_callable($fn) && !\is_array($fn)) {
        $ref = new \ReflectionFunction($fn);
        $arity = $ref->getNumberOfParameters();
        if (count($args) < $arity) {
            return ['__partial', $arity, $fn, ...$args];
        }

        return $fn(...$args);
    }

    return $fn(...$args);
};

/** Compare two results structurally, treating callables by identity only. */
$canon = static function (mixed $value) use (&$canon): mixed {
    if (\is_array($value) && ($value[0] ?? null) === '__partial') {
        $out = ['__partial', $value[1]];
        for ($i = 2, $n = count($value); $i < $n; ++$i) {
            $out[] = $canon($value[$i]);
        }

        return $out;
    }
    if (\is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $canon($v);
        }

        return $out;
    }

    return \is_object($value) ? 'object:' . $value::class : $value;
};

$matrix = [
    // cells, all arities, all splits, saturated and not
    [['__partial', 0, '\ap0'], []],
    [['__partial', 1, '\ap1'], [41]],
    [['__partial', 2, '\ap2'], [1]],
    [['__partial', 2, '\ap2'], [1, 2]],
    [['__partial', 3, '\ap3', 1], [2]],
    [['__partial', 3, '\ap3', 1], [2, 3]],
    [['__partial', 3, '\ap3', 1, 2], [3]],
    [['__partial', 4, '\ap4', 1, 2, 3], [4]],
    [['__partial', 4, '\ap4', 1], [2, 3, 4]],
    [['__partial', 5, '\ap5', 1, 2, 3], [4, 5]],
    [['__partial', 5, '\ap5', 1, 2, 3, 4], [5]],
    [['__partial', 3, '\apVar', 1], [2, 3]],
    // surplus arguments (backend-divergent; only PHP's side is frozen here)
    [['__partial', 3, '\ap3', 1, 2], [3, 99]],
    [['__partial', 2, '\ap2', 1, 2], [9]],
    // bare callables
    ['\ap2', [1]],
    ['\ap2', [1, 2]],
    ['\ap1', [41]],
    [['__partial', 2, $cl2], [1]],
    [['__partial', 2, $cl2, 1], [2]],
    [['__partial', 3, $cl3, 1], [2, 3]],
    // zero-arity cell, with and without surplus
    [['__partial', 0, '\ap0'], []],
    [['__partial', 0, '\ap0'], [7]],
];

foreach ($matrix as $i => [$cell, $args]) {
    try {
        $expected = $canon($reference($cell, ...$args));
    } catch (\Throwable $e) {
        $expected = 'throw:' . $e::class;
    }
    try {
        $actual = $canon(__apply($cell, ...$args));
    } catch (\Throwable $e) {
        $actual = 'throw:' . $e::class;
    }
    $assertSame(
        $expected,
        $actual,
        "differential case #{$i}: " . json_encode($canon($cell)) . ' applied ' . json_encode($args),
    );
}

// The closure-based bare-callable cases go through ReflectionFunction, which is
// identity-sensitive; cover them separately with explicit expectations.
$assertSame(3, __apply($cl2, 1, 2), 'closure bare callable, exact arity');
$assertSame('__partial', (__apply($cl2, 1)[0] ?? null), 'closure bare callable, under-applied');

// Report

if ($failures !== []) {
    fwrite(STDERR, 'apply (__apply) test FAILED: ' . count($failures) . "\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "apply (__apply) tests passed ({$checks} assertions)\n";
