#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Syntax\Ast;

use function Moggi\Semantics\Exhaustiveness\guardIsConstantTrue;
use function Moggi\Semantics\Exhaustiveness\trueBindingOrigins;

/**
 * A guard chain is exhaustive when its last predicate resolves to a binding whose *body* is the host
 * boolean `true` — the library's `otherwise :: Bool; otherwise = True`. Nothing here may look at the
 * spelling `otherwise`: a local binder of that name is a different declaration, and a guard that
 * cannot be resolved is never claimed exhaustive (claiming it would drop the match-failure arm a real
 * fall-through needs).
 */
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$boolData = static fn (): Ast\DataDecl => new Ast\DataDecl('Bool', [], [
    new Ast\ConstructorDecl('False', []),
    new Ast\ConstructorDecl('True', []),
]);

$binding = static fn (string $name, Ast\AstNode $body): Ast\FunctionDecl
    => new Ast\FunctionDecl($name, null, [], $body);

// The declaration says which of the two constructors is true: the second one, by its own order.
$origins = trueBindingOrigins([
    $boolData(),
    $binding('otherwise', new Ast\ConstructorRef('True')),
    $binding('neverTrue', new Ast\ConstructorRef('False')),
    $binding('notNullary', new Ast\Variable('x')),
], 'Data.Bool');

$assert(isset($origins['Data.Bool::otherwise']), 'a nullary binding of the true constructor is a true binding');
$assert(! isset($origins['Data.Bool::neverTrue']), 'the false constructor is not a true binding');
$assert(! isset($origins['Data.Bool::notNullary']), 'a binding of a variable is not a true binding');

// A module that declares no two-nullary type cannot name one.
$assert(trueBindingOrigins([$binding('otherwise', new Ast\ConstructorRef('True'))], 'M') === [], 'no declaration, no true binding');

// A parameterised binding is a function, not a constant.
$assert(trueBindingOrigins([
    $boolData(),
    new Ast\FunctionDecl('takesArg', null, [new Ast\PatVar('p')], new Ast\ConstructorRef('True')),
], 'Data.Bool') === [], 'a binding with parameters is not a constant');

// The resolved reference the library's `otherwise` produces is recognised …
$guard = new Ast\Variable('otherwise');
$guard->resolvedOrigin = 'Data.Bool::otherwise';
$assert(guardIsConstantTrue($guard, $origins), 'a guard resolved to the library otherwise is exhaustive');

// … and a shadowing local binding is not: `let otherwise = False in f x | otherwise = …` resolves
// to the local binder, which carries no origin, so the chain is not claimed exhaustive.
$shadowed = new Ast\Variable('otherwise');
$assert(! guardIsConstantTrue($shadowed, $origins), 'a guard with no resolved origin is not exhaustive');

// A reference resolved elsewhere is not exhaustive either, even under the same spelling.
$elsewhere = new Ast\Variable('otherwise');
$elsewhere->resolvedOrigin = 'Main::otherwise';
$assert(! guardIsConstantTrue($elsewhere, $origins), 'a guard resolved to another module is not exhaustive');

// A guard that is not a plain reference is never claimed exhaustive by this route.
$assert(! guardIsConstantTrue(new Ast\IntrinsicCall('intEq#', []), $origins), 'a non-reference guard is not exhaustive');
$assert(! guardIsConstantTrue(null, $origins), 'a missing guard is not exhaustive');

echo "guard true-binding test: ok\n";
