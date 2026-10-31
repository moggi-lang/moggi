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
 *
 * `tryInlineCall` splices a body three ways — a match wrapper, a PE-fusion clone and a straight-line body
 * — and the decision is taken once before any of them, so every shape is driven here with the same seed:
 * binding in one arm only is the defect this rule removed.
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
/** A match wrapper: `wrap p = match p { 0 -> p; _ -> p }`, i.e. two mentions of its one parameter. */
$wrap = new IR\FunctionDecl(
    'wrap',
    ['p'],
    null,
    new IR\Block([
        new IR\MatchReturn(new IR\Local('p'), [
            new IR\MatchArm(new IR\PatLit(0), new IR\Block([new IR\Ret(new IR\Local('p'))])),
            new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Ret(new IR\Local('p'))])),
        ]),
    ]),
);
/** A PE-fusion clone: `pe p = observe p p`, the same two mentions through the third splice shape. */
$pe = new IR\FunctionDecl(
    'pe',
    ['p'],
    null,
    new IR\Block([new IR\Ret(new IR\ForeignCall('php', 'call', 'observe', 'static', [new IR\Local('p'), new IR\Local('p')]))]),
    new IR\FunctionShape(kind: IR\FunctionKind::SpecClone),
);
/** A function that takes a parameter: a reference to it is a value. */
$closure = new IR\FunctionDecl('takesOne', ['x'], null, new IR\Block([new IR\Ret(new IR\Local('x'))]));

$inlineWith = static function (IR\FunctionDecl $callee, IR\Operand $arg) use ($seed, $closure): ?array {
    $facts = new Facts();
    $facts->publishFunctions([$seed, $closure, $callee]);
    $index = [];
    foreach ([$seed, $closure, $callee] as $decl) {
        $index[$decl->name] = $decl;
    }
    $nextTemp = 10;

    return tryInlineCall($callee, new IR\Call($callee->name, [$arg], 9), 'caller', $index, $nextTemp, [$callee->name => true], $facts);
};

$seedCall = $inlineWith($dup, new IR\FnRef('mk'));
$check($seedCall !== null, 'a parameterless-entry argument still inlines the callee');
$check(
    $count($seedCall ?? [], static fn (IR\Operand $op): bool => $op instanceof IR\FnRef && $op->name === 'mk') === 1,
    'a parameterless entry is bound once, not once per parameter mention',
);

$wrapCall = $inlineWith($wrap, new IR\FnRef('mk'));
$check($wrapCall !== null, 'a match-wrapper callee still inlines');
$check(
    $count($wrapCall ?? [], static fn (IR\Operand $op): bool => $op instanceof IR\FnRef && $op->name === 'mk') === 1,
    'the match-wrapper shape binds a parameterless entry once, like the straight-line shape',
);

$peCall = $inlineWith($pe, new IR\FnRef('mk'));
$check($peCall !== null, 'a PE-fusion clone still inlines');
$check(
    $count($peCall ?? [], static fn (IR\Operand $op): bool => $op instanceof IR\FnRef && $op->name === 'mk') === 1,
    'the PE-fusion shape binds a parameterless entry once, like the straight-line shape',
);

$foreignCall = $inlineWith($dup, new IR\ForeignCall('php', 'call', 'fresh', 'static', []));
$check($foreignCall !== null, 'a foreign-call argument still inlines the callee');
$check(
    $count($foreignCall ?? [], static fn (IR\Operand $op): bool => $op instanceof IR\ForeignCall && $op->path === 'fresh') === 1,
    'a foreign call is still bound once (the behaviour this rule extends)',
);

$closureCall = $inlineWith($dup, new IR\FnRef('takesOne'));
$check($closureCall !== null, 'a closure argument still inlines the callee');
$check(
    $count($closureCall ?? [], static fn (IR\Operand $op): bool => $op instanceof IR\FnRef && $op->name === 'takesOne') === 2,
    'a reference to a function that takes parameters is a value and is substituted at both mentions',
);

// An operand may name the entry bare or qualified; both are the same entry, so both answer the same
// fact. Keying the owner by the spelling an operand happened to carry would have missed this one.
$qualifiedSeed = $inlineWith($dup, new IR\FnRef('Other::mk'));
$check($qualifiedSeed !== null, 'a qualified parameterless reference still inlines the callee');
$check(
    $count($qualifiedSeed ?? [], static fn (IR\Operand $op): bool => $op instanceof IR\FnRef && $op->name === 'Other::mk') === 1,
    'a parameterless entry named by its qualified spelling is bound once too',
);

// The other half of the same rule: the owner publishes by leaf too, because a declaration's own name may
// be qualified while the operand that asks about it carries its own spelling. Without this the read-side
// keying would only work for names that arrive qualified and are published bare.
$owner = new Facts();
$owner->publishFunctions([
    new IR\FunctionDecl('Main::mk', [], null, new IR\Block([new IR\Ret(new IR\ConstInt(1))])),
    new IR\FunctionDecl('Main::takesOne', ['x'], null, new IR\Block([new IR\Ret(new IR\Local('x'))])),
]);
$check($owner->runsOnReference('mk'), 'a declaration named by its qualified spelling is published under its leaf');
$check($owner->runsOnReference('Main::mk'), 'the qualified spelling of a nullary entry is answered');
$check(! $owner->runsOnReference('takesOne'), 'an entry that takes a parameter is not a nullary fact');

if ($failures !== []) {
    \fwrite(STDERR, "inline_duplicable_args failures:\n");
    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

// Each case installs the owner it needs; a run that leaves one installed models a state the
// production path never leaves behind.


echo "inline_duplicable_args: ok\n";
