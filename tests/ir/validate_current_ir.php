<?php declare(strict_types=1);

// The current-IR validator's positive and malformed-negative cases.
//
// The legal shapes are the ones this tree really emits: a match whose arms share
// a destination, two matches writing the same destination, an action body whose
// `let` the action's result reads, a lifted lambda reading its captures, and a
// guard preparation bound for the arm that owns it. None of them is SSA.
//
// A region that declares a result names it in its own slot: every arm of a
// value-position match defines the region's destination temp, which is what the
// standard library does (5347 lowered and 2446 optimized arms, and the two
// fixtures below). An arm that returns instead of naming the region's result is
// refused by name, because a `Ret` in that position would leave the destination
// unnamed. An action box's deferred body has one such slot too — the statement's
// own `result` — so a body whose last statement returns is refused the same way
// (0 of the corpus's 221 lowered and 231 optimized boxes answer by return).
//
// The rejected shapes are an unbound local in a function that has no capture
// mechanism, a temp read before any statement defines it, an arm that returns
// instead of naming its region's result, and an entry point that names no
// function. Return regions are otherwise unrestricted, following the tree
// as it actually emits them: dead statements after a `Ret` stay legal, a `Ret`
// inside an arm stays legal as long as the arm still names its result, and
// **both** `Ret` and `MatchReturn` inside a `Loop` body are legal — TCO rewrites
// a tail-recursive function into such a loop, and the standard library compiles
// that way. There is no return legality rule to enforce, so these are pinned as
// accepted shapes.

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
$fn = static fn (
    string $name,
    array $params,
    IR\Block $body,
    IR\FunctionKind $kind = IR\FunctionKind::Source,
): IR\FunctionDecl => new IR\FunctionDecl($name, $params, null, $body, new IR\FunctionShape(kind: $kind));
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
    $fn('main', [], new IR\Block([
        new IR\Let('x', new IR\ConstInt(1)),
        $sharedMatch,
        $guardedMatch,
        $action,
        new IR\Assign(3, $temp(0)),
        new IR\Ret($temp(3)),
    ])),
    $fn('λ0', ['p'], new IR\Block([new IR\Ret($local('outerCapture'))]), IR\FunctionKind::Lambda),
]);
$error = $rejects(static fn () => Validate\validateModule($legal, 'post-lower'));
$check($error === null, 'legal current IR was rejected: ' . ($error?->getMessage() ?? ''));

$lookalike = $module([
    $fn('λ0', ['p'], new IR\Block([new IR\Ret($local('outerCapture'))])),
]);
$error = $rejects(static fn () => Validate\validateModule($lookalike, 'post-lower'));
$check($error !== null, 'a declared function named like a lambda was treated as a capture binding');
$check(
    $error !== null && \str_contains($error->getMessage(), 'unbound local `outerCapture`'),
    'the lookalike-name failure does not name the free local: ' . ($error?->getMessage() ?? ''),
);

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

$branchPartial = $module([$fn('partialTemp', [], new IR\Block([
    new IR\MatchStmt(new IR\ConstInt(0), [
        new IR\MatchArm(new IR\PatWild(), new IR\Block([
            new IR\Assign(0, new IR\ConstInt(1)),
            new IR\Assign(4, new IR\ConstInt(1)),
        ])),
        new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Assign(0, new IR\ConstInt(2))])),
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
        new IR\MatchArm(new IR\PatWild(), new IR\Block([
            new IR\Assign(0, new IR\ConstInt(1)),
            new IR\Assign(5, new IR\ConstInt(1)),
        ])),
        new IR\MatchArm(new IR\PatWild(), new IR\Block([
            new IR\Assign(0, new IR\ConstInt(2)),
            new IR\Assign(5, new IR\ConstInt(2)),
        ])),
    ], 0),
    new IR\Ret($temp(5)),
]))]);
$check(
    $rejects(static fn () => Validate\validateModule($allBranches, 'post-lower')) === null,
    'a temp defined by every arm was rejected after the match',
);

$legalReturns = $module([
    // The canonical value-position match: every arm names the region's result in its own slot.
    $fn('armNamesResult', [], new IR\Block([
        new IR\MatchStmt(new IR\ConstInt(0), [
            new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Assign(0, new IR\ConstInt(1))])),
            new IR\MatchArm(new IR\PatVar('n'), new IR\Block([new IR\Assign(0, $local('n'))])),
        ], 0),
        new IR\Ret($temp(0)),
    ])),
    $fn('armReturnsThenNames', [], new IR\Block([
        new IR\MatchStmt(new IR\ConstInt(0), [
            new IR\MatchArm(new IR\PatWild(), new IR\Block([
                new IR\Ret(new IR\Unit()),
                new IR\Assign(9, new IR\ConstInt(1)),
            ])),
        ], 9),
        new IR\Ret($temp(9)),
    ])),
    $fn('deadAfterRet', [], new IR\Block([
        new IR\Ret(new IR\Unit()),
        new IR\Assign(6, new IR\ConstInt(1)),
    ])),
    $fn('loopWithoutReturn', [], new IR\Block([
        new IR\Loop(new IR\Block([new IR\Assign(7, new IR\ConstInt(1))])),
        new IR\Ret(new IR\Unit()),
    ])),
    $fn('loopWithMatchReturn', [], new IR\Block([
        new IR\Loop(new IR\Block([
            new IR\MatchReturn(new IR\ConstInt(0), [
                new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Ret(new IR\Unit())])),
            ]),
        ])),
    ])),
    $fn('loopArmNamesResult', [], new IR\Block([
        new IR\Loop(new IR\Block([
            new IR\MatchStmt(new IR\ConstInt(0), [
                new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Assign(8, new IR\Unit())])),
            ], 8),
        ])),
    ])),
]);
$check(
    $rejects(static fn () => Validate\validateModule($legalReturns, 'post-opt')) === null,
    'a legal return region was rejected',
);

$armReturns = $module([$fn('armReturns', [], new IR\Block([
    new IR\MatchStmt(new IR\ConstInt(0), [
        new IR\MatchArm(new IR\PatWild(), new IR\Block([new IR\Assign(0, new IR\ConstInt(1))])),
        new IR\MatchArm(new IR\PatVar('n'), new IR\Block([new IR\Ret($local('n'))])),
    ], 0),
    new IR\Ret($temp(0)),
]))]);
$error = $rejects(static fn () => Validate\validateModule($armReturns, 'post-lower'));
$check($error !== null, 'an arm that returns instead of naming the region\'s result was accepted');
$check(
    $error !== null && \str_contains($error->getMessage(), 't0') && \str_contains($error->getMessage(), 'arm[1]'),
    'the unnamed-region-result error does not name the arm and the slot: ' . ($error?->getMessage() ?? ''),
);

// The action region's second shape: a deferred body that answers by return while the region also
// names a `result`. Every emitter would render the body's return and then the region's value, so the
// tree would carry two answers and emission order would pick one.
$boxAnswersByReturn = $module([$fn('boxAnswersByReturn', [], new IR\Block([
    new IR\IoAssignAction(
        2,
        new IR\Block([new IR\Ret(new IR\ConstInt(1))]),
        new IR\Unit(),
    ),
    new IR\Ret(new IR\Unit()),
]))]);
$error = $rejects(static fn () => Validate\validateModule($boxAnswersByReturn, 'post-lower'));
$check($error !== null, 'a deferred body that answers by return was accepted beside its region result');
$check(
    $error !== null && \str_contains($error->getMessage(), 't2') && \str_contains($error->getMessage(), 'Ret'),
    'the deferred-body error does not name the region and the answering statement: ' . ($error?->getMessage() ?? ''),
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
