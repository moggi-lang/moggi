<?php declare(strict_types=1);

/**
 * IR traversal must be total: every node's children can be enumerated and
 * mapped consistently, with no silent acceptance of deep chains or unknown
 * node classes.
 *
 * Coverage tables list every `IrNode` family member and fail when one is
 * missing, and the depth bound is loud rather than truncating.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\IR\Visit;

/** @var list<string> $failures */
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$throws = static function (callable $call): ?\Throwable {
    try {
        $call();
    } catch (\Throwable $e) {
        return $e;
    }

    return null;
};

$local = static fn (string $name): IR\Local => new IR\Local($name);
$temp = static fn (int $id): IR\Temp => new IR\Temp($id);

// ---- operands: one row per head -----------------------------------------

$operands = [
    IR\ConstInt::class => [new IR\ConstInt(1), 0],
    IR\ConstStr::class => [new IR\ConstStr('s'), 0],
    IR\ConstChar::class => [new IR\ConstChar(65), 0],
    IR\ConstDouble::class => [new IR\ConstDouble(1.5), 0],
    IR\Local::class => [$local('x'), 0],
    IR\Temp::class => [$temp(0), 0],
    IR\FnRef::class => [new IR\FnRef('f'), 0],
    IR\ListLit::class => [new IR\ListLit([$local('a'), $temp(1)]), 2],
    IR\Unit::class => [new IR\Unit(), 0],
    IR\Partial::class => [new IR\Partial('f', 2, [$local('a'), $local('b')]), 2],
    IR\Intrinsic::class => [new IR\Intrinsic('intAdd#', [$local('a')]), 1],
    IR\DictMethod::class => [new IR\DictMethod($local('dict'), 'method'), 1],
    IR\ForeignCall::class => [new IR\ForeignCall('php', 'static', 'Path', 'd', [$local('a')]), 1],
];

foreach ($operands as $class => [$operand, $children]) {
    $check(
        \count(Visit\operandChildren($operand)) === $children,
        "{$class}: operandChildren count is not {$children}",
    );

    $mapped = Visit\mapOperandChildren(
        $operand,
        static fn (IR\Operand $child): IR\Operand => new IR\Local('sentinel'),
    );
    $mappedChildren = Visit\operandChildren($mapped);
    $check(
        \count($mappedChildren) === $children,
        "{$class}: mapOperandChildren changed the child count",
    );
    foreach ($mappedChildren as $child) {
        $check(
            $child instanceof IR\Local && $child->name === 'sentinel',
            "{$class}: mapOperandChildren did not rewrite a child",
        );
    }
}

// ---- statements: one row per head ---------------------------------------

$guard = new IR\Guard(new IR\Block([new IR\Let('g', $local('x'))]), $local('ok'));
$arm = new IR\MatchArm(new IR\PatVar('p'), new IR\Block([new IR\Ret($local('p'))]), [$guard]);

$statements = [
    IR\Ret::class => [new IR\Ret($local('a')), 1, 0],
    IR\Call::class => [new IR\Call('f', [$local('a')], 0), 1, 0],
    IR\CallValue::class => [new IR\CallValue($local('callee'), [$local('a')], 0), 2, 0],
    IR\DictCall::class => [new IR\DictCall($local('dict'), 'm', [$local('a')], 0), 2, 0],
    IR\Assign::class => [new IR\Assign(0, $local('a')), 1, 0],
    IR\Let::class => [new IR\Let('n', $local('a')), 1, 0],
    IR\MatchStmt::class => [new IR\MatchStmt($local('s'), [$arm], 0), 2, 2],
    IR\MatchReturn::class => [new IR\MatchReturn($local('s'), [$arm]), 2, 2],
    IR\IoMatch::class => [new IR\IoMatch($local('s'), [$arm]), 2, 2],
    IR\TailRecall::class => [new IR\TailRecall([$local('a')]), 1, 0],
    IR\Loop::class => [new IR\Loop(new IR\Block([new IR\Ret($local('a'))])), 0, 1],
    IR\IoCall::class => [new IR\IoCall('f', [$local('a')], 0), 1, 0],
    IR\IoRun::class => [new IR\IoRun($local('action'), 0), 1, 0],
    IR\IoAssignAction::class => [
        new IR\IoAssignAction(0, new IR\Block([new IR\Let('m', $local('a'))]), $local('m')),
        1,
        1,
    ],
    IR\IoThrow::class => [new IR\IoThrow($local('e'), 0), 1, 0],
    IR\IoCatch::class => [new IR\IoCatch($local('a'), $local('h'), 0), 2, 0],
    IR\IoFinally::class => [new IR\IoFinally($local('a'), $local('c'), 0), 2, 0],
];

foreach ($statements as $class => [$stmt, $direct, $nested]) {
    $check(
        \count(Visit\stmtDirectOperands($stmt)) === $direct,
        "{$class}: stmtDirectOperands count is not {$direct}",
    );
    $check(
        \count(Visit\stmtNestedBlocks($stmt)) === $nested,
        "{$class}: stmtNestedBlocks count is not {$nested}",
    );
}

$match = new IR\MatchStmt($local('s'), [$arm], 0);
$check(
    \count(Visit\stmtDirectOperands($match)) === 2,
    'a match guard condition is not a direct operand of the match',
);
$check(
    \count(Visit\stmtNestedBlocks($match)) === 2,
    'a guard preparation is not a nested block of the match',
);
$mappedGuards = Visit\mapGuards(
    [$guard],
    static fn (IR\Operand $cond): IR\Operand => $local('mapped-cond'),
    static fn (IR\Stmt $stmt): IR\Stmt => $stmt,
);
$check($mappedGuards[0]->cond->name === 'mapped-cond', 'mapGuards did not rewrite the guard condition');
$check(\count($mappedGuards[0]->prep->items) === 1, 'mapGuards dropped the guard preparation');

// ---- patterns: one row per head ------------------------------------------

$patterns = [
    IR\PatWild::class => [new IR\PatWild(), []],
    IR\PatVar::class => [new IR\PatVar('p'), ['p']],
    IR\PatLit::class => [new IR\PatLit(1), []],
    IR\PatChar::class => [new IR\PatChar(65), []],
    IR\PatNil::class => [new IR\PatNil(), []],
    IR\PatCons::class => [new IR\PatCons(new IR\PatVar('h'), new IR\PatVar('t')), ['h', 't']],
    IR\PatCon::class => [new IR\PatCon('C', [new IR\PatVar('x'), new IR\PatVar('y')]), ['x', 'y']],
    IR\PatTuple::class => [new IR\PatTuple([new IR\PatVar('a'), new IR\PatVar('b')]), ['a', 'b']],
];

foreach ($patterns as $class => [$pattern, $names]) {
    $check(
        Visit\irPatternBoundNames($pattern) === $names,
        "{$class}: pattern binders are not [" . \implode(',', $names) . ']',
    );
}

// ---- constructor completeness -------------------------------------------

$declared = [];
foreach (\get_declared_classes() as $class) {
    if (\is_a($class, IR\IrNode::class, true)) {
        $declared[$class] = true;
    }
}
$covered = \array_fill_keys(\array_merge(
    \array_keys($operands),
    \array_keys($statements),
    \array_keys($patterns),
    [
        IR\Module::class,
        IR\FunctionDecl::class,
        IR\DataDecl::class,
        IR\Block::class,
        IR\Guard::class,
        IR\MatchArm::class,
    ],
), true);
$check(\count($declared) === \count($covered), 'declared IR nodes: ' . \count($declared) . ' vs tables ' . \count($covered));
foreach (\array_keys($declared) as $class) {
    $check(isset($covered[$class]), "IR node `{$class}` has no traversal case");
}

// ---- depth is loud, not silent ------------------------------------------

$deep = $local('x');
$nodes = 1;
for ($i = 0; $i < 70; ++$i) {
    $deep = new IR\Partial('f', 2, [$deep]);
    ++$nodes;
}
$check(
    $throws(static fn () => Visit\walkOperand($deep, static function (): void {
    })) instanceof Visit\TraversalDepthExceeded,
    'walkOperand silently truncated a chain deeper than the documented bound',
);
$check(
    $throws(static fn () => Visit\mapOperandTree($deep, static fn (IR\Operand $o): IR\Operand => $o))
        instanceof Visit\TraversalDepthExceeded,
    'mapOperandTree silently truncated a chain deeper than the documented bound',
);
$shallowVisits = 0;
Visit\walkOperand(new IR\Intrinsic('f', [$local('a')]), static function () use (&$shallowVisits): void {
    ++$shallowVisits;
});
$check($shallowVisits === 2, 'walkOperand no longer visits a shallow tree in full');

// ---- unknown nodes are rejected by name ---------------------------------

$unknownOperand = new class implements IR\Operand {
};
$unknownStmt = new class implements IR\Stmt {
};
$unknownPattern = new class implements IR\Pattern {
};

foreach ([
    'operandChildren' => static fn () => Visit\operandChildren($unknownOperand),
    'mapOperandChildren' => static fn () => Visit\mapOperandChildren(
        $unknownOperand,
        static fn (IR\Operand $o): IR\Operand => $o,
    ),
    'stmtDirectOperands' => static fn () => Visit\stmtDirectOperands($unknownStmt),
    'stmtNestedBlocks' => static fn () => Visit\stmtNestedBlocks($unknownStmt),
    'irPatternBoundNames' => static fn () => Visit\irPatternBoundNames($unknownPattern),
] as $surface => $call) {
    $error = $throws($call);
    $check(
        $error instanceof Visit\UnknownIrNode,
        "{$surface} accepted an unknown node class",
    );
}

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " traversal-law failure(s):\n  " . \implode("\n  ", \array_slice($failures, 0, 12)),
    );
}

echo 'traversal law tests passed (' . \count($operands) . ' operands, ' . \count($statements) . ' statements, '
    . \count($patterns) . " patterns)\n";
