<?php declare(strict_types=1);

// The specialization walk over recorded calls terminates: a record that reads the temp it defines
// answers not-concrete instead of following that read back into the record it is already inside.
//
// A temp id may be defined more than once, so a record can name its own temp (measured in
// `base/System/IO-Error`: `t95 = call Moggi.IO.Exception::IOError(…, t95)`). Following it never
// terminated — the compiler ran out of memory inside `specializationLookupNameSafe()` instead of
// declining to specialize. The `$seen` set is the guard `resolveOperand()` already uses for the same
// reason.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\Optimize\Facts\Facts;

use function Moggi\Optimize\Specialize\isConcreteSpecializeCall;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

/** @return array{callee: string, args: list<IR\Operand>} */
$record = static fn (string $callee, array $args): array => ['callee' => $callee, 'args' => $args];

/** @var list<array{string, array<int, IR\Operand|array{callee: string, args: list<IR\Operand>}>, IR\Call, bool}> */
$rows = [
    // A record that reads the temp it defines: the read is the temp, which nothing can name.
    [
        'a record that reads its own temp',
        [7 => $record('IOError', [new IR\Temp(7)])],
        new IR\Call('IOError', [new IR\Temp(7)], 0),
        false,
    ],
    // The same cycle one record longer: the guard is the walk's, not a self-read special case.
    [
        'two records that read each other',
        [
            8 => $record('MkA', [new IR\Temp(9)]),
            9 => $record('MkB', [new IR\Temp(8)]),
        ],
        new IR\Call('MkA', [new IR\Temp(9)], 0),
        false,
    ],
    // A recorded value is concrete; the record's own read of it is not a cycle.
    [
        'a record behind a recorded value',
        [12 => new IR\ConstInt(5)],
        new IR\Call('MkF', [new IR\Temp(12)], 0),
        true,
    ],
    // Two reads of one record are not a cycle: the walk enters it afresh on each path.
    [
        'one record read twice as siblings',
        [11 => $record('MkD', [new IR\ConstInt(1)])],
        new IR\Call('MkE', [new IR\Temp(11), new IR\Temp(11)], 0),
        true,
    ],
];

foreach ($rows as [$name, $records, $call, $expected]) {
    $concrete = isConcreteSpecializeCall($call, $records, new Facts());
    $assert(
        $concrete === $expected,
        $name . ': expected ' . ($expected ? 'concrete' : 'not concrete') . ', got '
            . ($concrete ? 'concrete' : 'not concrete'),
    );
}

echo "specialize record cycle: all assertions passed\n";
