<?php declare(strict_types=1);

// A constructor tree read as a value materializes as a scheduled call or as the list primop, never
// as a call to a name that stands for a primop: `(:)` is `listCons#` and no module declares a member
// by that operator, so a cons tree materialized as `Call(':', …)` emitted a call the targets could
// not resolve (dotnet: `Method not found: Moggi.Main._m_3a(System.Object, System.Object)` while
// building `base/Prelude-Basics`).
//
// Drives the materializer directly, the way `apply_collapse.php` drives its passes: what is pinned is
// which node the read becomes, not a whole pipeline's text.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\Optimize\CaseFold\CtorTree;

use function Moggi\Optimize\CaseFold\materializeOperand;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

// `(:)(1, nil)` is the list primop, so the read is the same intrinsic every other cons in the tree is.
$out = [];
$nextTemp = 0;
$value = materializeOperand(new CtorTree(':', [new IR\ConstInt(1), new IR\ListLit([])]), $out, $nextTemp);
$assert(
    $value instanceof IR\Intrinsic
        && $value->name === 'listCons#'
        && \count($value->args) === 2
        && $value->args[0] instanceof IR\ConstInt
        && $value->args[1] instanceof IR\ListLit,
    'a cons tree must materialize as the list primop, got ' . \get_debug_type($value),
);
$assert($out === [], 'a primop is a value, so materializing it schedules nothing');
$assert($nextTemp === 0, 'a primop is a value, so materializing it takes no temp');

// A constructor application is a computation: it is scheduled and the reader takes the temp it wrote.
$out = [];
$nextTemp = 0;
$value = materializeOperand(new CtorTree('MkPair', [new IR\ConstInt(1), new IR\ConstInt(2)]), $out, $nextTemp);
$assert(
    \count($out) === 1 && $out[0] instanceof IR\Call && $out[0]->callee === 'MkPair',
    'a constructor application must be scheduled as one call',
);
$assert(
    $value instanceof IR\Temp && $out[0] instanceof IR\Call && $value->id === $out[0]->dest,
    'the reader must take the temp the scheduled call wrote',
);

// A nullary constructor is already a value.
$out = [];
$nextTemp = 0;
$value = materializeOperand(new CtorTree('Nothing', []), $out, $nextTemp);
$assert($value instanceof IR\FnRef && $value->name === 'Nothing', 'a nullary constructor is its own reference');
$assert($out === [], 'a nullary constructor schedules nothing');

echo "cons materialization: all assertions passed\n";
