<?php declare(strict_types=1);

/**
 * Which callee names are field projections, and which only look like one.
 *
 * Lowering writes a field read as a call to `__fieldN`, so the index is the callee's spelling and the
 * spelling is the whole identity. Recovering it by casting whatever follows the prefix reads an
 * ordinary function's name as a field index, which folds a call to something the IR never said was a
 * projection.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\IR\Projection\index;
use function Moggi\IR\Projection\parse;
use function Moggi\IR\Projection\tupleArity;

$failures = [];
$check = static function (mixed $actual, mixed $expected, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message . ' (got ' . \var_export($actual, true) . ')';
    }
};

$check(index('__field0'), 0, 'the first field is a projection');
$check(index('__field12'), 12, 'a two-digit index is a projection');
$check(index('__tuple_field3'), 3, 'a tuple projection is a projection');
$check(index('Data.Map::__field1'), 1, 'a qualified projection is a projection');
$check(index('Data\\Map\\__field1'), 1, 'a namespaced projection is a projection');

$check(index('__fieldTotal'), null, 'a name that only starts like a field was read as a field');
$check(index('__field'), null, 'a bare prefix names no field');
$check(index('__field1x'), null, 'trailing junk is not an index');
$check(index('__field-1'), null, 'a negative spelling is not an index');
$check(index('__field01'), null, 'a non-canonical index is not a projection');
$check(index('__tuple_fieldNote'), null, 'a tuple look-alike is not a projection');
$check(index('my__field2'), null, 'the prefix has to start the leaf name');
$check(index('field2'), null, 'an ordinary field-shaped name is not a projection');

$check(parse('__field1')['tuple'] ?? null, false, 'a record projection is not the tuple spelling');
$check(parse('__tuple_field1')['tuple'] ?? null, true, 'the tuple spelling is reported');
$check(parse('__fieldTotal'), null, 'a look-alike names no projection at all');

$check(tupleArity('__tuple3'), 3, 'tuple construction names its arity');
$check(tupleArity('__tuple0'), 0, 'the unit tuple is arity zero');
$check(tupleArity('__tuple2abc'), null, 'trailing junk is not an arity');
$check(tupleArity('__tuple'), null, 'a bare prefix names no arity');
$check(tupleArity('__field0'), null, 'a record projection is not a tuple');

if ($failures !== []) {
    foreach ($failures as $failure) {
        \fwrite(STDERR, 'FAIL ' . $failure . "\n");
    }
    exit(1);
}

echo "ok\n";
