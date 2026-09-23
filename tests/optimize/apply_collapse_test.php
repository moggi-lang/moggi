#!/usr/bin/env php
<?php declare(strict_types=1);

// Regression tests for the apply-collapsing rewrites.
//
//   * `normalizeKnownApplies` — an application whose callee arity is statically
//     known becomes a direct `expr_call` (`expr_partial` when under-applied),
//     and a curried call on a parameter whose declared type fixes its arity
//     flattens into one `expr_call_value`. Both stop the backends from
//     reaching for the runtime `__apply`.
//   * `foldPartialApply` — `t = partial f(a)` followed by `t(b)` fuses into
//     `f(a, b)`, including when the intermediate spelling is `expr_partial`
//     (the shape a preceding fusion in the same pass produces).
//
// The passes are driven directly rather than through `optimize()`, so each
// assertion says exactly what that rewrite is responsible for; the whole-
// pipeline result is pinned by
// `tests/backend/codegen/Core-Apply-Collapse.mog` and
// `tests/optimize/Opt-Apply-Collapse.ir.php`.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\Syntax\Ast;

use function Moggi\IR\Visit\walkModule;
use function Moggi\Optimize\Partial\foldPartialApply;
use function Moggi\Optimize\Partial\normalizeKnownApplies;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$int = static fn (): Ast\TypeCon => new Ast\TypeCon('Int');

/** Right-nested arrow chain, e.g. `t1 -> t2 -> rest`. */
$funcType = static function (array $args, Ast\TypeNode $rest): Ast\TypeNode {
    foreach (array_reverse($args) as $arg) {
        $rest = new Ast\TypeArrow($arg, $rest);
    }

    return $rest;
};

$decl = static fn (string $name, array $params, ?Ast\TypeNode $type, array $items): IR\FunctionDecl
    => new IR\FunctionDecl($name, $params, $type, new IR\Block($items));

/** @return array{stmts: list<class-string>, operands: list<IR\Operand>} */
$shape = static function (IR\FunctionDecl $function): array {
    $stmts = [];
    $operands = [];
    walkModule(
        new IR\Module([$function], [], [], null),
        static function (IR\Stmt $stmt) use (&$stmts): void {
            $stmts[] = $stmt::class;
        },
        static function (IR\Operand $operand) use (&$operands): void {
            $operands[] = $operand;
        },
    );

    return ['stmts' => $stmts, 'operands' => $operands];
};

$countOf = static function (array $nodes, string $class): int {
    return count(array_filter(
        $nodes,
        static fn (mixed $node): bool => (\is_object($node) ? $node::class : $node) === $class,
    ));
};

$findOf = static function (array $operands, string $class): ?IR\Operand {
    foreach ($operands as $operand) {
        if ($operand::class === $class) {
            return $operand;
        }
    }

    return null;
};

// The passes only need the callees' parameter counts.
$add4 = $decl('add4', ['a', 'b', 'c', 'd'], null, [new IR\Ret(new IR\ConstInt(0))]);
$add2 = $decl('add2', ['a', 'b'], null, [new IR\Ret(new IR\ConstInt(0))]);

// 1. `foldPartialApply` fuses a partial with the application that saturates it
$fn = $decl('fusePartial', ['n'], null, [
    new IR\Assign(0, new IR\Partial('add2', 2, [new IR\ConstInt(1)])),
    new IR\CallValue(new IR\Temp(0), [new IR\Local('n')], 1),
    new IR\Ret(new IR\Temp(1)),
]);
$out = foldPartialApply(normalizeKnownApplies([$add2, $fn], 'Main'), 'Main')[1];
$s = $shape($out);
$assert($countOf($s['stmts'], IR\CallValue::class) === 0, 'partial + application must not stay a call_value');
$assert($countOf($s['operands'], IR\Partial::class) === 0, 'the fused partial must be gone');
$call = $findOf($s['operands'], IR\ExprCall::class);
$assert($call instanceof IR\ExprCall, 'partial + application must fuse into an expr_call');
$assert($call->callee === 'add2', 'fused expr_call must target the partial callee');
$assert(count($call->args) === 2, 'fused expr_call must carry the partial args plus the new argument');

// 2. The same fusion must accept an `expr_partial` produced by an earlier
//    fusion. Requiring `IR\Partial` here left the chain as `__partial` cells
//    plus runtime `__apply` on every backend.
$fn = $decl('fuseExprPartial', ['n'], null, [
    new IR\Assign(0, new IR\ExprPartial('add2', 2, [new IR\ConstInt(1)])),
    new IR\CallValue(new IR\Temp(0), [new IR\Local('n')], 1),
    new IR\Ret(new IR\Temp(1)),
]);
$out = foldPartialApply(normalizeKnownApplies([$add2, $fn], 'Main'), 'Main')[1];
$s = $shape($out);
$assert($countOf($s['stmts'], IR\CallValue::class) === 0, 'expr_partial + application must not stay a call_value');
$assert($countOf($s['operands'], IR\ExprPartial::class) === 0, 'the fused expr_partial must be gone');
$call = $findOf($s['operands'], IR\ExprCall::class);
$assert($call instanceof IR\ExprCall && count($call->args) === 2, 'expr_partial + application must fuse into one expr_call');

// 3. An exactly-saturating known apply becomes a direct call
$fn = $decl('exact', [], null, [
    new IR\CallValue(new IR\FnRef('add4'), [
        new IR\ConstInt(1), new IR\ConstInt(2), new IR\ConstInt(3), new IR\ConstInt(4),
    ], 0),
    new IR\Ret(new IR\Temp(0)),
]);
$out = normalizeKnownApplies([$add4, $fn], 'Main')[1];
$s = $shape($out);
$assert($countOf($s['stmts'], IR\CallValue::class) === 0, 'exactly-saturating known apply must not stay a call_value');
$call = $findOf($s['operands'], IR\ExprCall::class);
$assert($call instanceof IR\ExprCall && $call->callee === 'add4', 'exactly-saturating known apply must become a direct expr_call');

// 4. Guards: over-application and an indirect callee are left alone
$fn = $decl('over', ['n'], null, [
    new IR\CallValue(new IR\FnRef('add4'), [
        new IR\ConstInt(1), new IR\ConstInt(2), new IR\ConstInt(3), new IR\ConstInt(4), new IR\Local('n'),
    ], 0),
    new IR\Ret(new IR\Temp(0)),
]);
$out = normalizeKnownApplies([$add4, $fn], 'Main')[1];
$s = $shape($out);
$assert($countOf($s['stmts'], IR\CallValue::class) === 1, 'an over-application must stay a call_value');
$assert($countOf($s['operands'], IR\ExprCall::class) === 0, 'an over-application must not become an expr_call');
$assert($findOf($s['operands'], IR\FnRef::class) instanceof IR\FnRef, 'the over-applied callee must be untouched');

$fn = $decl('indirect', ['f', 'n'], null, [
    new IR\CallValue(new IR\Local('f'), [new IR\Local('n')], 0),
    new IR\Ret(new IR\Temp(0)),
]);
$out = normalizeKnownApplies([$fn], 'Main')[0];
$s = $shape($out);
$assert($countOf($s['stmts'], IR\CallValue::class) === 1, 'an untyped indirect callee must stay a call_value');
$assert($countOf($s['operands'], IR\ExprCall::class) === 0, 'an untyped indirect callee must not become an expr_call');

// 5. Curried application through a parameter with a known function type
//    flattens to a single apply: `f x y` -> one `expr_call_value`, not two.
$intToInt = $funcType([$int()], $int());
$intToIntToInt = $funcType([$int(), $int()], $int());

$flattenBody = [
    new IR\Ret(new IR\ExprCallValue(
        new IR\ExprCallValue(new IR\Local('f'), [new IR\Local('x')]),
        [new IR\Local('y')],
    )),
];

$fn = $decl('typedCurried', ['f', 'x', 'y'], $funcType([$intToIntToInt, $int(), $int()], $int()), $flattenBody);
$out = normalizeKnownApplies([$fn], 'Main')[0];
$value = $out->body->items[0]->value;
$assert($value instanceof IR\ExprCallValue, 'a typed curried call must stay an expr_call_value');
$assert($value->callee instanceof IR\Local, 'the flattened apply must be the one on the parameter');
$assert(count($value->args) === 2, 'the flattened apply must carry both arguments');

// Arity cap: the parameter takes one argument, so `f x y` cannot merge — it
// would turn an exact application into an over-application.
$fn = $decl('typedOver', ['g', 'x', 'y'], $funcType([$intToInt, $int(), $int()], $int()), [
    new IR\Ret(new IR\ExprCallValue(
        new IR\ExprCallValue(new IR\Local('g'), [new IR\Local('x')]),
        [new IR\Local('y')],
    )),
]);
$out = normalizeKnownApplies([$fn], 'Main')[0];
$value = $out->body->items[0]->value;
$assert(
    $value instanceof IR\ExprCallValue && $value->callee instanceof IR\ExprCallValue,
    'a parameter applied past its declared arity must not merge'
);

// No declared function type -> no arity to trust -> leave the shape alone.
$fn = $decl('untypedCurried', ['f', 'x', 'y'], null, $flattenBody);
$out = normalizeKnownApplies([$fn], 'Main')[0];
$value = $out->body->items[0]->value;
$assert(
    $value instanceof IR\ExprCallValue && $value->callee instanceof IR\ExprCallValue,
    'without a declared type the curried call must not be flattened'
);

echo "apply collapse: all assertions passed\n";
