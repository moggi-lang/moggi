<?php declare(strict_types=1);

// A constructor tree read out of another module's body names its constructors the way the reading
// module can emit them.
//
// `System.IO.Error::doesNotExistErrorType` is a nullary CAF whose body is `NoSuchThing`, and the
// module that reads it never named that constructor: the tree kept the bare name and jvm and dotnet
// resolved it against the reader (`Method not found: Moggi.Main.NoSuchThing()` while running
// `base/System/IO-Error`). A name the reading module cannot emit is replaced by the one its interface
// knows, or by its own qualification.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\Optimize\CaseFold\CtorTree;

use function Moggi\Optimize\Specialize\cafRetToCtorTree;
use function Moggi\Optimize\Specialize\qualifyCtorRefName;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$errorModule = 'System.IO.Error';
$qualified = 'Moggi.IO.Exception::NoSuchThing';
$retNullary = [new IR\Ret(new IR\FnRef('NoSuchThing'))];

// A nullary constructor returned by value: the reading module's interface names it.
$tree = cafRetToCtorTree($retNullary, $errorModule, ['NoSuchThing' => $qualified]);
$assert(
    $tree instanceof CtorTree && $tree->name === $qualified,
    'a nullary constructor must take the name the reading module knows, got '
        . ($tree instanceof CtorTree ? $tree->name : \get_debug_type($tree)),
);

// With no interface entry, the reading module's own qualification is what it can emit.
$tree = cafRetToCtorTree($retNullary, $errorModule, []);
$assert(
    $tree instanceof CtorTree && $tree->name === $errorModule . '::NoSuchThing',
    'a nullary constructor must be qualified by the reading module when its interface is silent',
);

// A nested constructor: the root of the tree and the argument trees it carries, each a constructor
// the reading module has to be able to emit.
$nested = [
    new IR\Call('MkPair', [new IR\FnRef('Nothing'), new IR\FnRef('NoSuchThing')], 0),
    new IR\Ret(new IR\Temp(0)),
];
$tree = cafRetToCtorTree($nested, $errorModule, [
    'Nothing' => 'Data.Maybe::Nothing',
    'NoSuchThing' => $qualified,
]);
$assert($tree instanceof CtorTree && $tree->name === $errorModule . '::MkPair', 'the tree root must be qualified too');
$assert(
    $tree->args[0] instanceof CtorTree && $tree->args[0]->name === 'Data.Maybe::Nothing'
        && $tree->args[1] instanceof CtorTree && $tree->args[1]->name === $qualified,
    'an argument constructor must take the name the reading module knows',
);

// A name that already means the machine's primop, or is already qualified, is left as it is.
$assert(qualifyCtorRefName(':', $errorModule, []) === ':', 'an operator alias must not be qualified');
$assert(
    qualifyCtorRefName($qualified, $errorModule, []) === $qualified,
    'an already-qualified name must not be qualified twice',
);

echo "constructor tree names: all assertions passed\n";
