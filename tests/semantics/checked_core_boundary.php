<?php declare(strict_types=1);

/**
 * The checked boundary refuses, by name, everything it promises to refuse.
 *
 * Two kinds of case:
 *
 * - a body that still carries an obligation, wherever in the body it sits. The
 *   guard used to be a hand-written `match` over expression kinds that fell
 *   through on everything it did not list, so an obligation under a `ForeignCall`
 *   argument or an evidence site's context was never looked at; the second gate
 *   is the body that is actually published (after normalization), because that
 *   is the tree lowering reads;
 * - one broken input per guard the producer runs, so the refusal cannot be lost
 *   silently. Each guard is a named function — the one `checkedCoreFor()` and
 *   `publishElaboration()` call — and its message is required here by name: a
 *   scheme still open, an obligation without a dictionary, one dictionary for two
 *   obligations, a vector that disagrees with the scheme, a parameter without a
 *   kind, a kind for a parameter the scheme never binds, a declaration with no
 *   source site, a term type naming a variable the check already solved.
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
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\assertNoPendingConstraintsInExpr;
use function Moggi\Semantics\Types\setPendingObligations;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$state = new TypeCheckState('', 'checked_core_boundary.mog');
$obligation = new Ast\PendingConstraint('Eq', [new TCon('Int', [])]);

$carrying = static function () use ($obligation, $state): Ast\IntegerLit {
    $lit = new Ast\IntegerLit(1, 1, 1, 2);
    setPendingObligations($state, $lit, [$obligation]);

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
Modules\publishElaboration($settled, $state);
$assert(
    $settled->core?->function('probe') !== null,
    'publishing a settled body dropped the record it published',
);

$unsettled = $publishable($carrying());
$message = '';
try {
    Modules\publishElaboration($unsettled, $state);
} catch (\LogicException $e) {
    $message = $e->getMessage();
}
$assert(
    \str_contains($message, 'Main::probe') && \str_contains($message, 'unresolved obligation'),
    'a published body carrying an obligation must be refused by name, got: '
        . ($message === '' ? 'no refusal' : $message),
);

// --- One broken input per guard, refused by name. --------------------------

/** Run `$guard` and answer the message it refused with, or ''. */
$refusal = static function (callable $guard): string {
    try {
        $guard();
    } catch (\Throwable $e) {
        return (string) $e->getMessage();
    }

    return '';
};

$declaration = static function (bool $declared, string $name = 'probe'): Ast\FunctionDecl {
    $fn = new Ast\FunctionDecl($name, null, [], new Ast\Variable('x', 2, 1, 2), line: 1, col: 1, endCol: 2);
    if ($declared) {
        $fn->type = new Ast\TypeCon('Int');
    }

    return $fn;
};

$obligations = static fn (string ...$evidence): array => \array_map(
    static fn (string $name): Ast\PendingConstraint => new Ast\PendingConstraint(
        'Eq',
        [new TCon('Int', [])],
        $name,
    ),
    $evidence,
);

$oneDictionary = new Scheme(new TCon('Int', []), [], [], 1, []);
$twoDictionaries = new Scheme(new TCon('Int', []), [], [], 2, []);

// A scheme that still has an uncommitted variable: refused when the declaration
// promised a closed one, and recorded as it stands when the signature was
// inferred — the monomorphism restriction is the module end's decision, not a
// reason to leave the declaration for a second producer.
$openScheme = new Scheme(new TCon('Int', []), [], [], 0, ['t3' => true]);
$message = $refusal(static fn () => Modules\assertSignatureClosed($state, $declaration(true), $openScheme));
$assert(
    \str_contains($message, 'still has the uncommitted') && \str_contains($message, 't3'),
    'an open scheme on a declared signature must be refused by name, got: ' . $message,
);
Modules\assertSignatureClosed($state, $declaration(false), $openScheme);
Modules\assertSignatureClosed($state, $declaration(true), $oneDictionary);

// The evidence vector.
$message = $refusal(static function () use ($state, $declaration, $obligations, $oneDictionary): void {
    $fn = $declaration(true);
    $fn->constraints = $obligations('');
    Modules\evidenceVectorFor($state, $fn, $oneDictionary, 'Main:1:1');
});
$assert(
    \str_contains($message, 'has no evidence parameter'),
    'an obligation without a dictionary parameter must be refused, got: ' . $message,
);

$message = $refusal(static function () use ($state, $declaration, $obligations, $twoDictionaries): void {
    $fn = $declaration(true);
    $fn->constraints = $obligations('ev1', 'ev1');
    Modules\evidenceVectorFor($state, $fn, $twoDictionaries, 'Main:1:1');
});
$assert(
    \str_contains($message, 'names two dictionary parameters'),
    'one dictionary for two obligations must be refused, got: ' . $message,
);

$message = $refusal(static function () use ($state, $declaration, $obligations, $twoDictionaries): void {
    $fn = $declaration(true);
    $fn->constraints = $obligations('ev1');
    Modules\evidenceVectorFor($state, $fn, $twoDictionaries, 'Main:1:1');
});
$assert(
    \str_contains($message, 'its scheme takes 2 dictionaries but its evidence vector has 1'),
    'a vector that disagrees with the scheme must be refused, got: ' . $message,
);

$vector = Modules\evidenceVectorFor(
    $state,
    (static function () use ($declaration, $obligations): Ast\FunctionDecl {
        $fn = $declaration(true);
        $fn->constraints = $obligations('ev1');

        return $fn;
    })(),
    $oneDictionary,
    'Main:1:1',
);
$assert(
    \count($vector) === 1 && $vector[0]['wanted'] === 'Eq(Int)' && $vector[0]['evidence'] === 'ev1',
    'a consistent vector is published as one slot per obligation, got: ' . \json_encode($vector),
);

// The parameter kinds.
$kindsState = new TypeCheckState('', 'checked_core_boundary.mog');
$withParameters = new Scheme(new TCon('Int', []), ['a'], [], 0, []);
$message = $refusal(static fn () => Modules\parameterKindsFor($kindsState, $declaration(true), $withParameters, 'Main'));
$assert(
    \str_contains($message, 'recorded no kind for'),
    'a declaration whose kinds the check never recorded must be refused, got: ' . $message,
);

$fn = $declaration(true);
$kindsState->checkedParameterKinds[\spl_object_id($fn)] = [];
$message = $refusal(static fn () => Modules\parameterKindsFor($kindsState, $fn, $withParameters, 'Main'));
$assert(
    \str_contains($message, 'fixed no kind for it'),
    'a scheme parameter without a recorded kind must be refused, got: ' . $message,
);

$kindsState->checkedParameterKinds[\spl_object_id($fn)] = ['a' => 'Type'];
$assert(
    Modules\parameterKindsFor($kindsState, $fn, $withParameters, 'Main') === ['a' => 'Type'],
    'a recorded kind is published for the parameter the scheme binds',
);

// The origin a record publishes.
$originState = new TypeCheckState('', 'checked_core_boundary.mog');
$written = $declaration(true);
$assert(
    Modules\originFor($originState, $written, 'Main') === 'Main:checked_core_boundary.mog:1:1',
    'a declaration with a span and no file of its own publishes the file the unit being checked is'
        . ' written in, got: ' . Modules\originFor($originState, $written, 'Main'),
);
$written->sourceFile = 'libraries/base/src/Data/Int/PHP.mog';
$assert(
    Modules\originFor($originState, $written, 'Main') === 'Main:libraries/base/src/Data/Int/PHP.mog:1:1',
    'a declaration written in another file of its unit publishes that file, got: '
        . Modules\originFor($originState, $written, 'Main'),
);
$written->sourceOrigin = 'Provider:libraries/base/src/Provider.mog:10:14';
$assert(
    Modules\originFor($originState, $written, 'Main') === 'Provider:libraries/base/src/Provider.mog:10:14',
    'a synthesized declaration publishes the site its producer recorded, in the file it is written in',
);
$written->sourceOrigin = null;
$written->sourceFile = null;
$written->line = 0;
$written->col = 0;
$message = $refusal(static fn () => Modules\originFor($originState, $written, 'Main'));
$assert(
    \str_contains($message, 'no source site'),
    'a declaration with no site must be refused by name, got: ' . $message,
);

// A published term type that still names a solved variable.
$solved = new TypeCheckState('', 'checked_core_boundary.mog');
$solved->subst = ['t7' => new TCon('Int', [])];
$zonkedFn = $declaration(true);
$zonkedFn->body->inferredInternalType = new TCon('Int', []);
Modules\assertTermsCommitted($solved, $zonkedFn, 'Main');
$zonkedFn->body->inferredInternalType = new TArrow(new TVar('t7'), new TCon('Int', []));
$message = $refusal(static fn () => Modules\assertTermsCommitted($solved, $zonkedFn, 'Main'));
$assert(
    \str_contains($message, 'already solved variable(s) t7'),
    'a term type still naming a solved variable must be refused, got: ' . $message,
);
$zonkedFn->body->inferredInternalType = new TArrow(new TVar('t9'), new TCon('Int', []));
Modules\assertTermsCommitted($solved, $zonkedFn, 'Main');

$strict = new TypeCheckState('', 'checked_core_boundary.mog');
$strict->subst = ['a$sk8' => new TCon('Int', [])];
$skolemFn = $declaration(true);
$skolemFn->body->inferredInternalType = new TVar('a$sk8');
$message = $refusal(static fn () => Modules\assertTermsCommitted($strict, $skolemFn, 'Main'));
$assert(
    \str_contains($message, 'already solved variable(s) a$sk8'),
    'a skolem the check solved is a stale published type too, got: ' . $message,
);

echo "checked-core boundary guard tests passed\n";
