<?php declare(strict_types=1);

/**
 * Direct pass regression for argument materialization in the inliner.
 *
 * A callee that mentions its parameter twice used to receive the caller's argument twice: `tryInlineCall`
 * substituted the argument at every parameter occurrence and bound only an `IR\ForeignCall` to a temp. A
 * bare reference to a parameterless entry runs its body when a backend forces the reference, so two
 * mentions ran it twice — `encodeToString enc = encodeWithPinnedWriter newStringWriter enc` allocated two
 * `StringWriter`s on jvm, the generator wrote one and `toString` read the other, and `encode` returned an
 * empty ByteString (`json`'s jvm leg, `FAIL: encode true — got , want true`).
 *
 * The question is the one `operandIsNonDuplicable()` already answers — the same fact DCE and copy
 * propagation ask — so these cases drive `tryInlineCall` directly and count the work-carrying operand in
 * the inlined result: one parameterless-entry reference, one foreign call, two for an argument that is
 * only a value; and the callee's body is still inlined in every case, so the rule cannot be bought by
 * refusing to inline.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;

use Moggi\Optimize\Facts\Facts;

use function Moggi\IR\Visit\walkStmt;
use function Moggi\Optimize\Interproc\tryInlineCall;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

/** @param list<IR\Stmt> $items */
$count = static function (array $items, callable $matches): int {
    $n = 0;
    foreach ($items as $item) {
        walkStmt(
            $item,
            static function (IR\Stmt $_): void {
            },
            static function (IR\Operand $op) use (&$n, $matches): void {
                if ($matches($op)) {
                    ++$n;
                }
            },
        );
    }

    return $n;
};

/** The callee: `dup p = observe p p`, i.e. two mentions of its one parameter. */
$dup = new IR\FunctionDecl(
    'dup',
    ['p'],
    null,
    new IR\Block([
        new IR\Ret(new IR\ForeignCall('php', 'call', 'observe', 'static', [new IR\Local('p'), new IR\Local('p')])),
    ]),
);
/** A parameterless entry: naming it runs it. */
$seed = new IR\FunctionDecl('mk', [], null, new IR\Block([new IR\Ret(new IR\ForeignCall('php', 'call', 'fresh', 'static', []))]));
/** A function that takes a parameter: a reference to it is a value. */
$closure = new IR\FunctionDecl('takesOne', ['x'], null, new IR\Block([new IR\Ret(new IR\Local('x'))]));

$inlineWith = static function (IR\Operand $arg) use ($dup, $seed, $closure): ?array {
    Facts::install(new Facts());
    Facts::current()->publishFunctions([$seed, $closure, $dup]);
    $index = [];
    foreach ([$seed, $closure, $dup] as $decl) {
        $index[$decl->name] = $decl;
    }
    $nextTemp = 10;

    return tryInlineCall($dup, new IR\Call('dup', [$arg], 9), 'caller', $index, $nextTemp, ['dup' => true]);
};

$seedCall = $inlineWith(new IR\FnRef('mk'));
$check($seedCall !== null, 'a parameterless-entry argument still inlines the callee');
$check(
    $count($seedCall ?? [], static fn (IR\Operand $op): bool => $op instanceof IR\FnRef && $op->name === 'mk') === 1,
    'a parameterless entry is bound once, not once per parameter mention',
);

$foreignCall = $inlineWith(new IR\ForeignCall('php', 'call', 'fresh', 'static', []));
$check($foreignCall !== null, 'a foreign-call argument still inlines the callee');
$check(
    $count($foreignCall ?? [], static fn (IR\Operand $op): bool => $op instanceof IR\ForeignCall && $op->path === 'fresh') === 1,
    'a foreign call is still bound once (the behaviour this rule extends)',
);

$closureCall = $inlineWith(new IR\FnRef('takesOne'));
$check($closureCall !== null, 'a closure argument still inlines the callee');
$check(
    $count($closureCall ?? [], static fn (IR\Operand $op): bool => $op instanceof IR\FnRef && $op->name === 'takesOne') === 2,
    'a reference to a function that takes parameters is a value and is substituted at both mentions',
);

if ($failures !== []) {
    \fwrite(STDERR, "inline_duplicable_args failures:\n");
    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "inline_duplicable_args: ok\n";
