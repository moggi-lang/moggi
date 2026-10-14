#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Syntax\Ast;

use function Moggi\Semantics\IoBoundary\ioBindParts;
use function Moggi\Semantics\IoBoundary\isIoMethodWithRole;
use function Moggi\Semantics\Kernel\bindRole;
use function Moggi\Semantics\Kernel\pureRole;

/**
 * `do`'s bind is an ordinary class method, so the IO boundary has exactly one way to recognise it: the
 * *resolved evidence* the checker left in the tree — an `EvidenceMethod` projected from an `IO`
 * instance, carrying the role its class declaration binds the method to. Nothing here may look at the
 * surface operator: a local redefinition of `>>=` (`(>>=) :: String -> (Char -> String) -> String`, or
 * any other type's bind) is a different declaration and must not be taken for the monad's.
 */
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$bind = static fn (Ast\AstNode $instance, ?string $role = null): Ast\AstNode => new Ast\Apply(
    new Ast\Apply(
        new Ast\EvidenceMethod('Monad', '>>=', '__ev', evidenceInstance: $instance, role: $role),
        new Ast\Variable('act'),
    ),
    new Ast\Variable('f'),
);

$ioInstance = static fn (): Ast\AstNode => new Ast\TypeCon('IO');
$ioApplied = static fn (): Ast\AstNode => new Ast\TypeApp(new Ast\TypeCon('IO'), [new Ast\TypeVar('a')]);

$parts = ioBindParts($bind($ioInstance(), bindRole()));
$assert($parts !== null, 'a bind projected from the IO instance is recognised');
$assert($parts['left'] instanceof Ast\Variable && $parts['left']->name === 'act', 'the left operand is the action');
$assert($parts['right'] instanceof Ast\Variable && $parts['right']->name === 'f', 'the right operand is the continuation');

$assert(ioBindParts($bind($ioApplied(), bindRole())) !== null, '`IO a` is the same IO instance');

// A local operator or another type's bind: the same method name, a different resolution.
$assert(
    ioBindParts(new Ast\Infix('>>=', new Ast\Variable('a'), new Ast\Variable('f'))) === null,
    'the surface operator is not a bind — only the resolved evidence is',
);
$assert(
    ioBindParts($bind(new Ast\TypeCon('Box'), bindRole())) === null,
    'a bind projected from another instance is not the IO monad bind',
);
$assert(
    ioBindParts($bind($ioInstance())) === null,
    'a method projected from IO without the role is not the bind — a local `>>=` resolves to a plain variable',
);
$assert(
    ioBindParts($bind($ioInstance(), 'not-a-role')) === null,
    'a made-up role is not the bind',
);
$assert(
    ioBindParts(new Ast\Apply(
        new Ast\Apply(
            new Ast\EvidenceMethod('Monad', '>>', '__ev', evidenceInstance: $ioInstance()),
            new Ast\Variable('act'),
        ),
        new Ast\Variable('f'),
    )) === null,
    'another Monad method of the same instance is not the bind',
);
$assert(ioBindParts(new Ast\Variable('act')) === null, 'a bare action is not a bind');

// The unit: `pure` is recognised the same way, by its role rather than its spelling.
$pure = static fn (?string $role, string $instance = 'IO'): Ast\AstNode => new Ast\EvidenceMethod(
    'Applicative',
    'pure',
    '__ev',
    evidenceInstance: new Ast\TypeCon($instance),
    role: $role,
);
$assert(isIoMethodWithRole($pure(null), pureRole()) === false, 'no role, no unit');
$assert(isIoMethodWithRole($pure(pureRole()), pureRole()) === true, 'the role names the unit');
$assert(
    isIoMethodWithRole($pure(pureRole(), 'Box'), pureRole()) === false,
    'another instance\'s unit is not IO\'s',
);

echo "io bind evidence test: ok\n";
