<?php declare(strict_types=1);

/**
 * Direct pass regression for DCE liveness collection.
 *
 * `collectUsedStmt` previously handled only 17 of 18 statement kinds,
 * silently skipping `IoAssignAction`. Definitions read only inside an
 * action box were therefore dropped by `dceItems`. The source and
 * pipeline regressions are covered by `Exec-Io-Box-Liveness.mog` and
 * `Opt-Io-Box-Liveness.ir.php`; this test isolates the collectors.
 *
 * Operand traversal had the same gap: hand-written descent stopped at a
 * boxed action's `ForeignCall` argument. It now uses `operandChildren` /
 * `walkOperand`, with the table verifying reads are visible through every
 * IR statement kind.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;

use Moggi\Optimize\Effects\Model;
use Moggi\Optimize\Facts\Facts;

use function Moggi\IR\Visit\walkBlock;
use function Moggi\Optimize\GlobalPass\dceBlock;
use function Moggi\Optimize\GlobalPass\usedInItems;
use function Moggi\Optimize\GlobalPass\usedInOperand;
use function Moggi\Optimize\Specialize\unboundTempsInFunction;

$facts = new Facts();
$facts->publishLiftedCaptureNeeds([]);

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$temp = static fn (int $id): IR\Temp => new IR\Temp($id);
$unit = static fn (): IR\Unit => new IR\Unit();

$read = 40;
$armRead = static fn (): IR\MatchArm => new IR\MatchArm(
    new IR\PatWild(),
    new IR\Block([new IR\Ret($temp($read))]),
);
$kinds = [
    'Ret' => new IR\Ret($temp($read)),
    'Assign' => new IR\Assign(41, $temp($read)),
    'Let' => new IR\Let('x', $temp($read)),
    'Call' => new IR\Call('f', [$temp($read)], 43),
    'CallValue' => new IR\CallValue($temp($read), [], 44),
    'DictCall' => new IR\DictCall($temp($read), 'm', [], 45),
    'TailRecall' => new IR\TailRecall([$temp($read)]),
    'MatchStmt' => new IR\MatchStmt($temp($read), [$armRead()], 46),
    'MatchReturn' => new IR\MatchReturn($temp($read), [$armRead()]),
    'Loop' => new IR\Loop(new IR\Block([new IR\Ret($temp($read))])),
    'IoCall' => new IR\IoCall('putStrLn', [$temp($read)], 49),
    'IoRun' => new IR\IoRun($temp($read)),
    'IoMatch' => new IR\IoMatch($temp($read), [$armRead()]),
    'IoAssignAction' => new IR\IoAssignAction(48, new IR\Block([new IR\Ret($temp($read))]), $temp($read)),
    'IoThrow' => new IR\IoThrow($temp($read)),
    'IoCatch' => new IR\IoCatch($temp($read), $temp($read)),
    'IoFinally' => new IR\IoFinally($temp($read), $temp($read)),
];
foreach ($kinds as $kind => $stmt) {
    $check(
        isset(usedInItems([$stmt], $facts)['temps'][$read]),
        "usedInItems does not see t{$read} through {$kind}",
    );
}
$localFunction = new IR\FunctionDecl('main', [], null, new IR\Block([
    new IR\Let('a', new IR\ConstInt(7)),
    new IR\IoAssignAction(
        9,
        new IR\Block([new IR\IoRun($temp(8)), new IR\Assign(8, new IR\Local('a'))]),
        $unit(),
    ),
    new IR\IoRun($temp(9), 10),
    new IR\Ret($unit()),
]));
$check(
    isset(usedInItems($localFunction->body->items, $facts)['locals']['a']),
    'usedInItems misses a local read inside an io_assign_action body',
);

$function = new IR\FunctionDecl('main', [], null, new IR\Block([
    new IR\Call('fib', [new IR\ConstInt(10)], 4),
    new IR\IoAssignAction(
        5,
        new IR\Block([new IR\IoRun($temp(4))]),
        $unit(),
    ),
    new IR\IoRun($temp(5), 6),
    new IR\Ret($unit()),
]));
$totalFib = new IR\FunctionDecl('fib', ['n'], null, new IR\Block([new IR\Ret(new IR\ConstInt(0))]));
$optimized = $function->withBody(dceBlock($function->body, Model::forFunctions([$totalFib, $function], '', $facts)));
$destinations = [];
walkBlock($optimized->body, static function (IR\Stmt $stmt) use (&$destinations): void {
    if ($stmt instanceof IR\Call && $stmt->dest !== null) {
        $destinations[] = $stmt->dest;
    }
}, static function (): void {
});
$check($destinations === [4], 'dceBlock dropped the call read by the box (kept: ' . \implode(',', $destinations) . ')');
$check(unboundTempsInFunction($optimized) === [], 'dceBlock left an unbound temp behind');

$foreign = new IR\ForeignCall('php', 'call', 'strlen', 'static', [$temp(30)]);
$check(
    isset(usedInOperand($foreign)['temps'][30]),
    'usedInOperand misses an argument of a foreign call',
);
$check(
    isset(usedInOperand(new IR\Intrinsic('f', [$temp(32)]))['temps'][32]),
    'usedInOperand misses an argument of an intrinsic call',
);

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " DCE liveness failure(s):\n  " . \implode("\n  ", $failures),
    );
}

echo "DCE liveness tests passed\n";
