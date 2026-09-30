#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Syntax\Ast;

/**
 * The AST values a walk is expected to reach, and the bookkeeping fields it is
 * not: annotation, constraints, doc, and source spans describe a node rather
 * than being children of it.
 */
$notChildren = ['inferredType', 'pendingConstraints', 'doc', 'line', 'col', 'endCol', 'endLine'];

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

/** Declared property type names, flattened out of a nullable or union type. */
$typeNames = static function (\ReflectionProperty $property): array {
    $type = $property->getType();
    if ($type === null) {
        return [];
    }

    $names = [];
    foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type] as $arm) {
        if ($arm instanceof \ReflectionNamedType) {
            $names[] = $arm->getName();
        }
    }

    return $names;
};

/** Whether a property holds a value a walk must be able to reach. */
$holdsWalkable = static function (\ReflectionProperty $property) use ($typeNames): bool {
    foreach ($typeNames($property) as $name) {
        if ($name !== 'null' && \is_a($name, Ast\AstWalkable::class, true)) {
            return true;
        }
    }

    return false;
};

/** The `$this->name` reads in a method's own body. */
$readsIn = static function (\ReflectionMethod $method): array {
    $file = $method->getFileName();
    if ($file === false) {
        return [];
    }

    $lines = \file($file);
    $body = \implode('', \array_slice(
        $lines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));
    \preg_match_all('/\\$this->([A-Za-z_][A-Za-z0-9_]*)/', $body, $matches);

    return $matches[1];
};

$classes = [];
foreach (\get_declared_classes() as $class) {
    if (\str_starts_with($class, 'Moggi\\Syntax\\Ast\\') && \is_a($class, Ast\AstWalkable::class, true)) {
        $classes[$class] = new \ReflectionClass($class);
    }
}
$assert($classes !== [], 'no walkable AST classes were loaded');

$checked = 0;
foreach ($classes as $name => $reflection) {
    $method = new \ReflectionMethod($name, 'childValues');
    if (\in_array($method->getDeclaringClass()->getName(), [Ast\AstNode::class, Ast\AstValue::class], true)) {
        continue;
    }

    $reads = \array_flip($readsIn($method));
    foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->isStatic() || \in_array($property->getName(), $notChildren, true)) {
            continue;
        }

        if (! $holdsWalkable($property)) {
            continue;
        }

        $assert(
            isset($reads[$property->getName()]),
            "{$name}::childValues() does not surface its `\${$property->getName()}` value",
        );
    }

    ++$checked;
}

$assert($checked > 0, 'no childValues() override was checked');

echo "AST traversal completeness tests passed ({$checked} override(s))\n";
