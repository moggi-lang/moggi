#!/usr/bin/env php
<?php declare(strict_types=1);

// A multi-parameter instance head is carried by the synthetic type constructor
// `__InstanceHead`, which must never be a user type constructor. User type
// constructors are lexed as upper-case `ConId`s, so a name starting with `_`
// cannot be spelled in source; `registerData` / `registerTypeSynonym` still
// reject it, so the marker cannot be shadowed by an AST built programmatically.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$marker = Ast\instanceHeadMarker();
$assert($marker === '__InstanceHead', 'the marker name is `__InstanceHead`');

$state = \Moggi\Semantics\Types\newState('');

$rejected = static function (callable $register) use ($assert): void {
    try {
        $register();
    } catch (TypeError $e) {
        $assert(
            \str_contains($e->getMessage(), 'reserved for the compiler'),
            'the rejection explains the reserved name, got: ' . $e->getMessage(),
        );

        return;
    }

    throw new \RuntimeException('registering the marker name must fail');
};

$rejected(static fn () => \Moggi\Semantics\Types\registerData($state, new Ast\DataDecl($marker, [], [])));
$rejected(static fn () => \Moggi\Semantics\Types\registerTypeSynonym($state, new Ast\TypeSynonymDecl($marker, new Ast\TypeCon('Int'))));

\Moggi\Semantics\Types\registerData($state, new Ast\DataDecl('Foo', [], []));
$assert(isset($state->data['Foo']), 'an ordinary data type still registers');

echo "instance head marker guard tests passed\n";
