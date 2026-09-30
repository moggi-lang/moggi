#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\topLevelBindingSccs;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

/**
 * The top-level dependency graph the discovery fixpoint is replaced by: a
 * declaration's component must come after the components of everything it calls,
 * and members that need each other must land in one component.
 */
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$path = __DIR__ . '/Tc-Dependency-Chains.mog';
$source = (string) \file_get_contents($path);
$program = parse(lex($source, $path), $source, $path);
$components = topLevelBindingSccs($program->items);

$position = [];
$recursive = [];
foreach ($components as $order => $component) {
    foreach ($component['indices'] as $index) {
        $item = $program->items[$index];
        $assert($item instanceof Ast\FunctionDecl, "component holds a non-declaration at {$index}");
        $assert(!isset($position[$item->name]), "{$item->name} appears in two components");
        $position[$item->name] = $order;
        $recursive[$item->name] = $component['recursive'];
    }
}

foreach (['f', 'g', 'h', 'isEven', 'isOdd', 'inGroup', 'chainUse', 'c1', 'c2', 'c3', 'pairUse'] as $name) {
    $assert(isset($position[$name]), "{$name} is missing from the graph");
}

$before = static function (string $producer, string $consumer) use ($position, $assert): void {
    $assert(
        $position[$producer] < $position[$consumer],
        "{$producer} must be laid out before {$consumer}",
    );
};

// f -> g -> h, one edge per step
$before('h', 'g');
$before('g', 'f');

// a signature-less chain is the same shape
$before('c3', 'c2');
$before('c2', 'c1');
$before('c1', 'chainUse');

// mutual recursion is one component, and it is marked recursive
$assert(
    $position['isEven'] === $position['isOdd'],
    'mutually recursive declarations must share a component',
);
$assert($recursive['isEven'], 'a mutual cycle must be marked recursive');

foreach (['f', 'g', 'h', 'inGroup', 'chainUse', 'c1', 'c2', 'c3', 'pairUse'] as $name) {
    $assert(!$recursive[$name], "{$name} is not recursive");
}

// a declaration that calls itself is a recursive component of its own, and one
// that only calls a nested binding is not
$inlinePath = 'self-recursion.mog';
$inlineSource = "loop n = loop (n - 1)\n\nplain = 1\n\nviaWhere = loop 3\n  where\n    helper x = helper x\n";
$inlineProgram = parse(lex($inlineSource, $inlinePath), $inlineSource, $inlinePath);
$inlineRecursive = [];
foreach (topLevelBindingSccs($inlineProgram->items) as $component) {
    foreach ($component['indices'] as $index) {
        $item = $inlineProgram->items[$index];
        if ($item instanceof Ast\FunctionDecl) {
            $inlineRecursive[$item->name] = $component['recursive'];
        }
    }
}

$assert($inlineRecursive['loop'] ?? false, 'a declaration that calls itself must be recursive');
$assert(!($inlineRecursive['plain'] ?? true), 'a declaration with no dependency is not recursive');
$assert(
    !($inlineRecursive['viaWhere'] ?? true),
    'recursion inside a nested where is not a top-level self-edge',
);

echo "top-level binding SCC tests passed (" . count($components) . " component(s))\n";
