<?php declare(strict_types=1);

/**
 * A fold's generated names are a function of the tree, not of process history.
 *
 * The case folder re-binds a non-duplicable value it bound under a fresh local (`x__seedN`). That
 * number used to come from a function-static counter, so the name a fold produced depended on how
 * many folds had run earlier in the process — selection order and cache-hit pattern, not the module
 * being compiled. The counter is gone; the suffix is the position within the fold's own result.
 *
 * This drives the real entry point: the same match, folded twice in one process with an unrelated
 * fold in between, must produce the same IR both times. Reintroducing any process-order-dependent
 * name source fails the first comparison.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\Optimize\Facts\Facts;

use function Moggi\Optimize\CaseFold\tryFoldMatch;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

/**
 * A match over a list whose head is a host call, which is non-duplicable and so must be re-bound
 * under a fresh seed name before it is substituted into the arm body.
 *
 * @return ?list<IR\Stmt>
 */
$fold = static function (string $patternName): ?array {
    $nextTemp = 10;
    $match = new IR\MatchStmt(
        new IR\Temp(1),
        [new IR\MatchArm(
            new IR\PatCons(new IR\PatVar($patternName), new IR\PatNil()),
            new IR\Block([new IR\Ret(new IR\Local($patternName))]),
        )],
        2,
    );
    $head = new IR\ForeignCall('php', 'function', 'strlen', 'static', [new IR\ConstStr('x')]);

    return tryFoldMatch($match, new IR\ListLit([$head]), [], [], $nextTemp, new Facts());
};

$first = $fold('head');
$check($first !== null, 'the list fold did not fire');

// An unrelated fold between the two, exactly as a different module's would run between them.
$fold('other');

$again = $fold('head');
$check($again !== null, 'the second fold of the same shape did not fire');

$check(
    \serialize($first) === \serialize($again),
    'folding the same match twice in one process produced different IR: a generated name depends on '
    . 'how many folds ran before it rather than on the tree being folded',
);

$dump = \serialize($first);
$check(
    \str_contains($dump, 'head__seed0'),
    'the fold did not re-bind the computed head under its positional seed name: ' . $dump,
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        \fwrite(STDERR, 'FAIL ' . $failure . "\n");
    }
    exit(1);
}

echo "ok\n";
