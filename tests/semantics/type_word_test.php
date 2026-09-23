#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

$word = new \Moggi\Semantics\TypeExpr\TWord();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$assert(
    \Moggi\Semantics\Types\samePrimitiveType($word, new \Moggi\Semantics\TypeExpr\TWord()),
    'machine Word must unify with itself',
);
$assert(
    \Moggi\Semantics\Types\magicHashInternalType('Word#') instanceof \Moggi\Semantics\TypeExpr\TWord,
    'Word# must resolve to TWord',
);
$assert(\Moggi\Semantics\Kinds\canonicalTypeConName('Word#') === 'Word', 'Word# kind must canonicalize');

echo "machine Word tests passed\n";
