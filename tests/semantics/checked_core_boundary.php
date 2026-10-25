<?php declare(strict_types=1);

/**
 * The checked boundary refuses a body that still carries an obligation —
 * wherever in the body it sits.
 *
 * The guard used to be a hand-written `match` over expression kinds that fell
 * through on everything it did not list, so an obligation under a `ForeignCall`
 * argument or an evidence site's context was never looked at. These cases plant
 * one obligation in each of those shapes and require the refusal, and then pin
 * the second gate: the body that is actually published (after normalization)
 * must be settled too, for the same reason — it is the tree lowering reads.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Modules;
use Moggi\Modules\CheckedCore;
use Moggi\Modules\CheckedFunction;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\assertNoPendingConstraintsInExpr;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$state = new TypeCheckState('', 'checked_core_boundary.mog');
$obligation = new Ast\PendingConstraint('Eq', [new TCon('Int', [])]);

$carrying = static function () use ($obligation): Ast\IntegerLit {
    $lit = new Ast\IntegerLit(1, 1, 1, 2);
    $lit->pendingConstraints = [$obligation];

    return $lit;
};

$refused = static function (Ast\AstNode $root) use ($assert, $state): void {
    $message = '';
    try {
        assertNoPendingConstraintsInExpr($state, $root);
    } catch (\Throwable $e) {
        $message = (string) $e->getMessage();
    }

    $assert(
        \str_contains($message, 'unresolved type class'),
        'an unresolved obligation inside a ' . $root::class . ' was not refused; got: '
            . ($message === '' ? 'no refusal' : $message),
    );
};

// The shapes the previous walk did reach, kept as the control.
$refused(new Ast\Apply(new Ast\Variable('f'), $carrying(), 1, 1, 2));
$refused(new Ast\Infix('+', $carrying(), $carrying(), 1, 1, 2));
$refused(new Ast\CaseExpr($carrying(), [new Ast\Alt(new Ast\PatWild(), $carrying())], 1, 1, 2));

// The shapes it did not.
$refused(new Ast\ForeignCall(
    backend: 'php',
    kind: 'static',
    name: 'f',
    path: 'probe:F',
    dispatch: 'static',
    args: [$carrying()],
    line: 1,
    col: 1,
    endCol: 2,
));
$refused(new Ast\EvidenceRef('Eq', new Ast\TypeCon('Int'), [$carrying()], 1, 1, 2));
$refused(new Ast\IoSequence([$carrying()], 1, 1, 2));
$refused(new Ast\IoBind(new Ast\PatWild(), $carrying(), 1, 1, 2));

// A settled body is not refused: the walk must not fail every tree it visits.
assertNoPendingConstraintsInExpr($state, new Ast\Apply(new Ast\Variable('f'), new Ast\IntegerLit(1, 1, 1, 2), 1, 1, 2));

// --- The published body is the second gate. --------------------------------

$publishable = static function (Ast\AstNode $body): Ast\Program {
    $declaration = new Ast\FunctionDecl(
        'probe',
        null,
        [new Ast\PatVar('x', 1, 1, 2)],
        $body,
        line: 1,
        col: 1,
        endCol: 2,
    );

    $program = new Ast\Program([$declaration], 'Main');
    $program->core = new CheckedCore('Main', [
        'probe' => new CheckedFunction(
            'probe',
            'Main:1:1',
            new Scheme(new TCon('Int', []), [], [], 0, []),
            [],
            [],
        ),
    ]);

    return $program;
};

$settled = $publishable(new Ast\Variable('x', 2, 1, 2));
Modules\publishElaboration($settled);
$assert(
    $settled->core?->function('probe') !== null,
    'publishing a settled body dropped the record it published',
);

$unsettled = $publishable($carrying());
$message = '';
try {
    Modules\publishElaboration($unsettled);
} catch (\LogicException $e) {
    $message = $e->getMessage();
}
$assert(
    \str_contains($message, 'Main::probe') && \str_contains($message, 'unresolved obligation'),
    'a published body carrying an obligation must be refused by name, got: '
        . ($message === '' ? 'no refusal' : $message),
);

echo "checked-core boundary guard tests passed\n";
