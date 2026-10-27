<?php declare(strict_types=1);

/**
 * Regression tests for apply-collapsing rewrites.
 *
 * - `normalizeKnownApplies` converts statically known applications into
 *   direct `call` statements / `partial` values and flattens known curried
 *   calls into one `call_value` statement, avoiding runtime `__apply`.
 * - `foldPartialApply` fuses `partial f(a)` followed by `t(b)` into
 *   `f(a, b)` — a direct call *statement* whose temp the reader takes, because a
 *   computation is never an operand.
 *
 * Drive each pass directly to isolate its responsibility. Whole-pipeline
 * behavior is covered by `Core-Apply-Collapse.mog` and
 * `Opt-Apply-Collapse.ir.php`.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;

use function Moggi\IR\Visit\walkModule;

use function Moggi\Optimize\Partial\foldPartialApply;
use function Moggi\Optimize\Partial\normalizeKnownApplies;

use Moggi\Syntax\Ast;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$int = static fn (): Ast\TypeCon => new Ast\TypeCon('Int');

/** Right-nested arrow chain, e.g. `t1 -> t2 -> rest`. */
$funcType = static function (array $args, Ast\TypeNode $rest): Ast\TypeNode {
    foreach (\array_reverse($args) as $arg) {
        $rest = new Ast\TypeArrow($arg, $rest);
    }

    return $rest;
};

$decl = static fn (string $name, array $params, ?Ast\TypeNode $type, array $items): IR\FunctionDecl => new IR\FunctionDecl($name, $params, $type, new IR\Block($items));

/** @return array{stmts: list<IR\Stmt>, operands: list<IR\Operand>} */
$shape = static function (IR\FunctionDecl $function): array {
    $stmts = [];
    $operands = [];
    walkModule(
        new IR\Module([$function], [], [], null),
        static function (IR\Stmt $stmt) use (&$stmts): void {
            $stmts[] = $stmt;
        },
        static function (IR\Operand $operand) use (&$operands): void {
            $operands[] = $operand;
        },
    );

    return ['stmts' => $stmts, 'operands' => $operands];
};

$countOf = static function (array $nodes, string $class): int {
    return \count(\array_filter(
        $nodes,
        static fn (mixed $node): bool => (\is_object($node) ? $node::class : $node) === $class,
    ));
};

$findOf = static function (array $operands, string $class): ?object {
    foreach ($operands as $operand) {
        if ($operand::class === $class) {
            return $operand;
        }
    }

    return null;
};
$add4 = $decl('add4', ['a', 'b', 'c', 'd'], null, [new IR\Ret(new IR\ConstInt(0))]);
$add2 = $decl('add2', ['a', 'b'], null, [new IR\Ret(new IR\ConstInt(0))]);
$fn = $decl('fusePartial', ['n'], null, [
    new IR\Assign(0, new IR\Partial('add2', 2, [new IR\ConstInt(1)])),
    new IR\CallValue(new IR\Temp(0), [new IR\Local('n')], 1),
    new IR\Ret(new IR\Temp(1)),
]);
$out = foldPartialApply(normalizeKnownApplies([$add2, $fn], 'Main'), 'Main')[1];
$s = $shape($out);
$assert($countOf($s['stmts'], IR\CallValue::class) === 0, 'partial + application must not stay a call_value');
$assert($countOf($s['operands'], IR\Partial::class) === 0, 'the fused partial must be gone');
$call = $findOf($s['stmts'], IR\Call::class);
$assert($call instanceof IR\Call && $call->callee === 'add2', 'partial + application must fuse into a direct call of the partial callee');
$assert(\count($call->args) === 2, 'the fused call must carry the partial args plus the new argument');
$ret = $findOf($s['stmts'], IR\Ret::class);
$assert(
    $ret instanceof IR\Ret && $ret->value instanceof IR\Temp && $ret->value->id === $call->dest,
    'the reader must take the temp the fused call defines'
);
$fn = $decl('exact', [], null, [
    new IR\CallValue(new IR\FnRef('add4'), [
        new IR\ConstInt(1), new IR\ConstInt(2), new IR\ConstInt(3), new IR\ConstInt(4),
    ], 0),
    new IR\Ret(new IR\Temp(0)),
]);
$out = normalizeKnownApplies([$add4, $fn], 'Main')[1];
$s = $shape($out);
$assert($countOf($s['stmts'], IR\CallValue::class) === 0, 'exactly-saturating known apply must not stay a call_value');
$call = $findOf($s['stmts'], IR\Call::class);
$assert(
    $call instanceof IR\Call && $call->callee === 'add4' && \count($call->args) === 4,
    'exactly-saturating known apply must become a direct call statement'
);
$fn = $decl('over', ['n'], null, [
    new IR\CallValue(new IR\FnRef('add4'), [
        new IR\ConstInt(1), new IR\ConstInt(2), new IR\ConstInt(3), new IR\ConstInt(4), new IR\Local('n'),
    ], 0),
    new IR\Ret(new IR\Temp(0)),
]);
$out = normalizeKnownApplies([$add4, $fn], 'Main')[1];
$s = $shape($out);
$assert($countOf($s['stmts'], IR\CallValue::class) === 1, 'an over-application must stay a call_value');
$assert($findOf($s['operands'], IR\FnRef::class) instanceof IR\FnRef, 'the over-applied callee must be untouched');

$fn = $decl('indirect', ['f', 'n'], null, [
    new IR\CallValue(new IR\Local('f'), [new IR\Local('n')], 0),
    new IR\Ret(new IR\Temp(0)),
]);
$out = normalizeKnownApplies([$fn], 'Main')[0];
$s = $shape($out);
$assert($countOf($s['stmts'], IR\CallValue::class) === 1, 'an untyped indirect callee must stay a call_value');
$intToInt = $funcType([$int()], $int());

$flattenBody = [
    new IR\CallValue(new IR\Local('f'), [new IR\Local('x')], 0),
    new IR\CallValue(new IR\Temp(0), [new IR\Local('y')], 1),
    new IR\Ret(new IR\Temp(1)),
];

/** A curried call is two apply statements, each reading the temp the one before it defined. */
$assertStaysTwoCalls = static function (IR\FunctionDecl $function, string $message) use ($assert): void {
    $items = $function->body->items;
    $assert(\count($items) === 3, $message);
    $assert(
        $items[0] instanceof IR\CallValue && \count($items[0]->args) === 1,
        $message . ': the inner apply is a statement of its own'
    );
    $assert(
        $items[1] instanceof IR\CallValue && $items[1]->callee instanceof IR\Temp
            && $items[1]->callee->id === $items[0]->dest,
        $message . ": the outer apply reads the inner call's temp"
    );
    $assert(
        $items[2] instanceof IR\Ret && $items[2]->value instanceof IR\Temp && $items[2]->value->id === $items[1]->dest,
        $message . ": the reader takes the outer call's temp"
    );
};

$fn = $decl('typedOver', ['g', 'x', 'y'], $funcType([$intToInt, $int(), $int()], $int()), [
    new IR\CallValue(new IR\Local('g'), [new IR\Local('x')], 0),
    new IR\CallValue(new IR\Temp(0), [new IR\Local('y')], 1),
    new IR\Ret(new IR\Temp(1)),
]);
$assertStaysTwoCalls(
    normalizeKnownApplies([$fn], 'Main')[0],
    'a parameter applied past its declared arity stays two calls'
);

$fn = $decl('untypedCurried', ['f', 'x', 'y'], null, $flattenBody);
$assertStaysTwoCalls(
    normalizeKnownApplies([$fn], 'Main')[0],
    'a curried application stays two calls'
);

// A collapsed apply belongs to the scope that reads it: nothing may be hoisted out of an arm or out
// of a guard, because a match tests its guards and runs one arm — a call hoisted in front of the
// match would run unconditionally.
$saturatedApply = static fn (): array => [
    new IR\CallValue(new IR\FnRef('add2'), [new IR\ConstInt(1), new IR\ConstInt(2)], 0),
    new IR\Ret(new IR\Temp(0)),
];

$fn = $decl('armScope', ['n'], null, [
    new IR\MatchReturn(
        new IR\Local('n'),
        [new IR\MatchArm(new IR\PatWild(), new IR\Block($saturatedApply()))],
        true,
    ),
]);
$out = normalizeKnownApplies([$add2, $fn], 'Main')[1];
$s = $shape($out);
$assert(\count($out->body->items) === 1, 'an arm\'s apply must not be scheduled in front of the match');
$arm = $out->body->items[0]->arms[0]->body->items;
$assert(
    $arm[0] instanceof IR\Call && $arm[0]->callee === 'add2' && \count($arm[0]->args) === 2,
    'an arm\'s apply must be a call statement inside the arm'
);
$assert(
    $arm[1] instanceof IR\Ret && $arm[1]->value instanceof IR\Temp && $arm[1]->value->id === $arm[0]->dest,
    'an arm\'s call must define the temp the arm returns'
);

$fn = $decl('guardedScope', ['n'], null, [
    new IR\MatchReturn(
        new IR\Local('n'),
        [
            new IR\MatchArm(
                new IR\PatWild(),
                new IR\Block([new IR\Ret(new IR\ConstInt(0))]),
                [new IR\Guard(
                    new IR\Block([new IR\CallValue(new IR\FnRef('add2'), [new IR\ConstInt(1), new IR\ConstInt(2)], 0)]),
                    new IR\Temp(0),
                )],
            ),
        ],
        true,
    ),
]);
$out = normalizeKnownApplies([$add2, $fn], 'Main')[1];
$assert(\count($out->body->items) === 1, 'a guard\'s apply must not be scheduled in front of the match');
$arm = $out->body->items[0]->arms[0];
$prep = $arm->guards[0]->prep->items;
$assert(\count($prep) === 1 && $prep[0] instanceof IR\Call, 'a guard\'s apply must be scheduled in the guard\'s own preparation');
$assert(
    $arm->guards[0]->cond instanceof IR\Temp && $arm->guards[0]->cond->id === $prep[0]->dest,
    'a guard must read the temp its own preparation defined'
);
$assert($arm->body->items[0] instanceof IR\Ret, 'a guard\'s apply must not be scheduled into the arm body');

echo "apply collapse: all assertions passed\n";
