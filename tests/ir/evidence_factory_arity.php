<?php declare(strict_types=1);

/**
 * A module only parsed publishes the same evidence-factory arity the checker would.
 *
 * A factory's slots are the instance context *closed under its classes' superclasses*:
 * `instance Sorted a => Sorted (Box a)` takes `Same a` and `Sorted a`. The checker elaborates every
 * call with that closure, so a module a prepare only parsed — the shallow library a single-file
 * compile prepares — has to publish the same number. Publishing the count the instance *spelled*
 * made an importer's two dictionaries fail post-legalize against one published slot:
 *
 *     function compare: call `__ev_Ord_List_1` supplies 2 of 1 published slot(s) (imported entry)
 *
 * The fixture prepares the project, which checks `Lib`, then republishes `Lib` from its parsed tree
 * alone (no facts, no checked program: exactly the shallow shape) and holds the numbers together:
 *
 *   1. a context that already names its superclass keeps its written count
 *      (`(Same a, Sorted a)` stays 2, not 3);
 *   2. a context that does not spell them gains one slot per superclass, transitively
 *      (`Sorted a` => 2, `Ranked a` => 3);
 *   3. a quantified superclass (`forall x. Pair (Wrapped p) x`) contributes its own slot *and* the
 *      slot of the quantified class's superclass, so a `Handles a` context expands to
 *      `Base (Wrapped a)`, `Pair (Wrapped a) x` and `Handles a` — three, not one;
 *   4. erased obligations take no dictionary: equality (`a ~ b`) and `Coercible` are skipped, so
 *      `(a ~ b, Coercible a b, Same a)` stays one slot;
 *   5. the parsed publication equals the checked publication for every factory of the module;
 *   6. the evidence symbols and the callable record read the same number.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/assert.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Modules;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Syntax\Ast;

use function Moggi\Modules\classSuperclassTable;
use function Moggi\Modules\publishedCallableLayout;
use function Moggi\Modules\unitEvidenceArities;
use function Moggi\Semantics\Types\evidenceFunctionName;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

Modules\setStdlibLibPath($root . '/libraries/base/src');

$work = createTempDir('moggi-evidence-factory-arity');
\register_shutdown_function(static function () use ($work): void {
    removeDirectory($work);
});

$source = <<<'MOG'
module Lib where

import Data.Coerce (Coercible)

data Box a = Box a

data Crate a = Crate a

data Wrap a = Wrap a

data Wrap2 a b = Wrap2 a b

data Wrapped a = Wrapped a

class Same a where
  same :: a -> a -> Bool

class Same a => Sorted a where
  sorted :: a -> Int

class Sorted a => Ranked a where
  ranked :: a -> Int

class Base q where
  base :: q -> Int

class Base (Wrapped p) => Pair p x where
  pair :: p -> x -> String

class (forall x. Pair (Wrapped p) x) => Handles p where
  handle :: p -> Int

instance Same a => Same (Box a) where
  same _ _ = True

instance Sorted a => Sorted (Box a) where
  sorted _ = 0

instance Ranked a => Ranked (Box a) where
  ranked _ = 1

instance Same a => Same (Crate a) where
  same _ _ = True

instance Sorted a => Sorted (Crate a) where
  sorted _ = 2

instance (Same a, Sorted a) => Ranked (Crate a) where
  ranked _ = 3

instance Handles a => Same (Wrap a) where
  same _ _ = True

instance (a ~ b, Coercible a b, Same a) => Same (Wrap2 a b) where
  same _ _ = True
MOG;

$libraryPath = $work . '/Lib.mog';
\file_put_contents($libraryPath, $source . "\n");

// Every factory in declaration order: written constraints, then the superclass closure. `Same` has
// no superclass; `Sorted` needs `Same`; `Ranked` needs both. The third `Ranked` instance writes its
// pair out, so it is already closed and must not be expanded to three. `Handles` carries a
// quantified superclass over `Pair`, which brings `Pair` and, through it, `Base`; the last
// instance's equality and `Coercible` are erased obligations and count for nothing.
$expected = [1, 2, 3, 1, 2, 2, 3, 1];

$program = parse(lex($source, $libraryPath), $source, $libraryPath);

$factories = [];
foreach ($program->items as $item) {
    if ($item instanceof Ast\InstanceDecl) {
        $factories[evidenceFunctionName($item->class, $item->head)] = $item->class . ' (line ' . $item->line . ')';
    }
}
$assert(
    \count($factories) === \count($expected),
    'the fixture declares ' . \count($factories) . ' instance(s), expected ' . \count($expected),
);

// The checked side: the project is prepared in full, so every module is checked and publishes the
// arity its factories really take.
$context = CompilationContext::for(
    'php',
    CompilePurpose::Library,
    roots: [$work],
    sources: [$libraryPath],
);
$project = Modules\prepareProject(
    Modules\moduleFileClosureCached($libraryPath, 'php')[0],
    $work,
    $context,
);
$checkedUnit = $project->units['Lib'] ?? null;
$assert(\is_array($checkedUnit), 'the project prepared no unit for `Lib`');

$checkedArities = $checkedUnit['facts']['evidenceChecked']['arity'] ?? null;
$assert(
    \is_array($checkedArities),
    'the checked `Lib` published no evidence arity; the fixture needs a checked module to compare',
);
$exports = $checkedUnit['exports'] ?? null;
$assert(\is_array($exports), 'the checked `Lib` published no exports');

// The shallow shape: the parsed tree, no facts and no checked program, which is what a prepare that
// only parsed the closure leaves behind.
$shallowUnit = [
    'path' => $libraryPath,
    'source' => $source,
    'program' => $program,
    'exports' => $exports,
    'namespace' => $checkedUnit['namespace'] ?? 'Lib',
];
$shallowUnits = ['Lib' => $shallowUnit];

$symbolArities = unitEvidenceArities(
    $shallowUnit,
    'Lib',
    $shallowUnit['namespace'],
    '',
    classSuperclassTable($shallowUnits),
);

$index = 0;
foreach ($factories as $factory => $origin) {
    $want = $expected[$index++];

    $published = publishedCallableLayout($shallowUnits, 'Lib', $factory);
    $assert(
        $published['evidence'] === $want,
        "{$origin}: a parsed-only publication gives `{$factory}` {$published['evidence']} evidence "
            . "slot(s), the superclass closure is {$want}",
    );

    $assert(
        ($symbolArities[$factory] ?? null) === $want,
        "{$origin}: the evidence symbol `{$factory}` publishes "
            . \var_export($symbolArities[$factory] ?? null, true) . " slot(s), the callable record "
            . "publishes {$published['evidence']}; both read the instance context",
    );

    $checked = $checkedArities[$factory] ?? null;
    $assert(
        $checked === $want,
        "{$origin}: the checked `{$factory}` publishes " . \var_export($checked, true)
            . " slot(s), expected {$want}",
    );
}

echo "evidence factory arity tests passed\n";
