<?php declare(strict_types=1);

// A2: the current-IR validator's positive and malformed-negative cases.
//
// The legal shapes are the ones this tree really emits: a match whose arms share
// a destination, two matches writing the same destination, an action body whose
// `let` the action's result reads, a lifted lambda reading its captures, and a
// guard preparation bound for the arm that owns it. None of them is SSA.
//
// The rejected shapes are an unbound local in a function that has no capture
// mechanism, a temp read before any statement defines it, and an entry point
// that names no function. Return regions follow D17 as the tree actually emits
// them (D17, answered 2026-10-21): `Ret` in a match arm is that arm's yield,
// dead statements after a `Ret` stay legal, and **both** `Ret` and `MatchReturn`
// inside a `Loop` body are legal — TCO rewrites a tail-recursive function into
// such a loop, and the standard library compiles that way. There is no return
// legality rule to enforce, so these are pinned as accepted shapes.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\IR\Validate;

/** @var list<string> $failures */
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$rejects = static function (callable $call): ?Validate\InvalidIr {
    try {
        $call();
    } catch (Validate\InvalidIr $e) {
        return $e;
    }

    return null;
};

$local = static fn (string $name): IR\Local => new IR\Local($name);
$temp = static fn (int $id): IR\Temp => new IR\Temp($id);
$fn = static fn (string $name, array $params, IR\Block $body): IR\FunctionDecl
    => new IR\FunctionDecl($name, $params, null, $body);
$module = static fn (array $functions): IR\Module => new IR\Module($functions, []);

// ---- legal current IR ----------------------------------------------------

$sharedArm = new IR\MatchArm(new IR\PatVar('a'), new IR\Block([new IR\Assign(0, $local('a'))]));
$sharedArm2 = new IR\MatchArm(new IR\PatVar('b'), new IR\Block([new IR\Assign(0, $local('b'))]));
$sharedMatch = new IR\MatchStmt($local('x'), [$sharedArm, $sharedArm2], 0);

$guard = new IR\Guard(new IR\Block([new IR\Let('ok', $local('x'))]), $local('ok'));
$guardedArm = new IR\MatchArm(
    new IR\PatVar('n'),
    new IR\Block([new IR\Assign(1, $local('n'))]),
    [$guard],
);
$guardedMatch = new IR\MatchStmt($local('x'), [$guardedArm], 1);

$action = new IR\IoAssignAction(
    2,
    new IR\Block([new IR\Let('marr', $local('x'))]),
    $local('marr'),
);

$legal = $module([
    // Two matches writing the same destination, then a read of it, is legal.
    $fn('main', [], new IR\Block([
        new IR\Let('x', new IR\ConstInt(1)),
        $sharedMatch,
        $guardedMatch,
        $action,
        new IR\Assign(3, $temp(0)),
        new IR\Ret($temp(3)),
    ])),
    // A lifted lambda's free locals are its captures.
    $fn('λ0', ['p'], new IR\Block([new IR\Ret($local('outerCapture'))])),
]);
$error = $rejects(static fn () => Validate\validateModule($legal, 'post-lower'));
$check($error === null, 'legal current IR was rejected: ' . ($error?->getMessage() ?? ''));

// ---- malformed IR --------------------------------------------------------

$unbound = $module([$fn('bad', [], new IR\Block([new IR\Ret($local('missing'))]))]);
$error = $rejects(static fn () => Validate\validateModule($unbound, 'post-opt'));
$check($error !== null, 'an unbound local was accepted');
$check(
    $error !== null && \str_contains($error->getMessage(), 'unbound local `missing`'),
    'the unbound-local error does not name the local',
);
$check($error !== null && \str_contains($error->getMessage(), 'bad'), 'the error does not name the function');
$check($error !== null && \str_contains($error->getMessage(), 'post-opt'), 'the error does not carry the pass label');
$check($error !== null && $error->function === 'bad', 'the error does not expose the function name');

$early = $module([$fn('tempy', [], new IR\Block([
    new IR\Ret($temp(7)),
    new IR\Assign(7, new IR\ConstInt(1)),
]))]);
$error = $rejects(static fn () => Validate\validateModule($early, 'post-lower'));
$check($error !== null, 'a temp read before its definition was accepted');
$check(
    $error !== null && \str_contains($error->getMessage(), 'temp t7'),
    'the temp error does not name the temp',
);

// A temp defined by only one arm is undefined on the other path, so reading it
// after the match is rejected; a temp defined by every arm is available.
$branchPartial = $module([$fn('partialTemp', [], new IR\Block([
    new IR\MatchStmt(new IR\ConstInt(0), [
        new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Assign(4, new IR\ConstInt(1))])),
        new IR\MatchArm(new IR\PatWild(), new IR\Block([])),
    ], 0),
    new IR\Ret($temp(4)),
]))]);
$error = $rejects(static fn () => Validate\validateModule($branchPartial, 'post-lower'));
$check($error !== null, 'a temp defined by one branch only was accepted after the match');
$check(
    $error !== null && \str_contains($error->getMessage(), 'temp t4'),
    'the branch-partial temp error does not name the temp',
);

$allBranches = $module([$fn('allTemps', [], new IR\Block([
    new IR\MatchStmt(new IR\ConstInt(0), [
        new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Assign(5, new IR\ConstInt(1))])),
        new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Assign(5, new IR\ConstInt(2))])),
    ], 0),
    new IR\Ret($temp(5)),
]))]);
$check(
    $rejects(static fn () => Validate\validateModule($allBranches, 'post-lower')) === null,
    'a temp defined by every arm was rejected after the match',
);

// D17: the legal return regions. An arm may yield via `Ret`, a statement after
// a `Ret` is dead code (legal IR, not validated), and a loop body may contain
// anything except a return.
$legalReturns = $module([
    $fn('armYield', [], new IR\Block([
        new IR\MatchStmt(new IR\ConstInt(0), [
            new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Ret(new IR\ConstInt(1))])),
            new IR\MatchArm(new IR\PatVar('n'), new IR\Block([new IR\Ret($local('n'))])),
        ], 0),
        new IR\Ret(new IR\Unit()),
    ])),
    $fn('deadAfterRet', [], new IR\Block([
        new IR\Ret(new IR\Unit()),
        new IR\Assign(6, new IR\ConstInt(1)),
    ])),
    $fn('loopWithoutReturn', [], new IR\Block([
        new IR\Loop(new IR\Block([new IR\Assign(7, new IR\ConstInt(1))])),
        new IR\Ret(new IR\Unit()),
    ])),
    // The shapes TCO really emits: a loop body returning through MatchReturn,
    // and a loop arm returning through Ret (`Data/Foldable`'s `__letrec5`).
    $fn('loopWithMatchReturn', [], new IR\Block([
        new IR\Loop(new IR\Block([
            new IR\MatchReturn(new IR\ConstInt(0), [
                new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Ret(new IR\Unit())])),
            ]),
        ])),
    ])),
    $fn('loopArmRet', [], new IR\Block([
        new IR\Loop(new IR\Block([
            new IR\MatchStmt(new IR\ConstInt(0), [
                new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Ret(new IR\Unit())])),
            ], 8),
        ])),
    ])),
]);
$check(
    $rejects(static fn () => Validate\validateModule($legalReturns, 'post-opt')) === null,
    'a legal return region was rejected',
);

$missingEntry = new IR\Module(
    [$fn('main', [], new IR\Block([new IR\Ret(new IR\Unit())]))],
    [],
    [],
    new IR\EntryPoint('notAFunction', IR\EntryPointKind::Main),
);
$check(
    $rejects(static fn () => Validate\validateModule($missingEntry, 'post-opt')) !== null,
    'an entry point naming no function was accepted',
);

// A guard preparation's binder is available to that arm's body, and to nothing
// else: reading it from a sibling arm is an availability error.
$leaky = $module([$fn('leaky', [], new IR\Block([
    new IR\MatchStmt($local('x'), [
        new IR\MatchArm(new IR\PatVar('a'), new IR\Block([]), [
            new IR\Guard(new IR\Block([new IR\Let('onlyHere', $local('a'))]), new IR\ConstInt(1)),
        ]),
        new IR\MatchArm(new IR\PatVar('b'), new IR\Block([new IR\Ret($local('onlyHere'))])),
    ], 0),
]))]);
$check(
    $rejects(static fn () => Validate\validateModule($leaky, 'post-lower')) !== null,
    'a guard binder leaked into a sibling arm',
);

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " validator failure(s):\n  " . \implode("\n  ", $failures),
    );
}

echo "current-IR validator tests passed\n";
