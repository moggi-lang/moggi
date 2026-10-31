<?php declare(strict_types=1);

/**
 * Lambdas and applications are checked terms, and the boundary is total for them.
 *
 * These are the two shapes the capture analysis and the eta-expansion seam depend
 * on: a lambda's slots and the names it closes over, and how many arguments an
 * application still wants. Both used to be answered by the lowerer from the node
 * it was handed — the capture set by scanning the AST, the arity off the node's
 * internal type — so a callable that existed only in lowering had to be given a
 * fabricated node, and the *source* lambda's facts were re-derived every time.
 *
 * Now `CheckedLambda` states the slots and the capture set, `CheckedApplication`
 * states the remaining arity, and `lowerNamedLambda()` takes both as facts. That
 * is what keeps `lowerLoweredLambda()` honest: its body is IR, so its captures
 * can only come from the caller, and there is no node-shaped body for the term
 * model to sneak back in.
 *
 * Pinned here, for both shapes:
 *
 * 1. every lambda and every application in a checked body has a term, and the
 *    term's facts equal the ones an independent recomputation gives;
 * 2. the node's own copies are not read — replacing every lambda's parameter list
 *    with a bogus slot, and nulling every application's internal type (the field
 *    the arity used to come from), leaves the dumped IR byte-identical;
 * 3. a site the record does not cover is refused, whichever shape it has, and a
 *    node built after publication cannot be lowered at all.
 *
 * The scan that no lowering pass constructs a term-bearing node any more lives in
 * `semantics/checked_intrinsic_terms`, which covers every published family.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/assert.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\IR;
use Moggi\Modules;
use Moggi\Modules\CheckedApplication;
use Moggi\Modules\CheckedEvidenceMethod;
use Moggi\Modules\CheckedIntrinsicCall;
use Moggi\Modules\CheckedLambda;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Semantics\TypeExpr;
use Moggi\Syntax\Ast;

use function Moggi\IR\freeVarsExpr;
use function Moggi\IR\patternParams;
use function Moggi\IR\remainingArity;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$source = <<<'MOG'
module Main where

-- A class method applied to too few arguments: the eta-expanded lambda's slots
-- come from the application term's remaining arity.
eqZero :: Int -> Bool
eqZero = (==) 0

-- The same, capturing a local of the enclosing declaration.
eqN :: Int -> Int -> Bool
eqN n = (==) n

-- A lambda capturing a dictionary parameter and an ordinary local.
tagged :: Eq a => a -> a -> Bool
tagged x = \y -> x == y

-- A lambda whose only free name is a local.
nested :: Int -> Int -> Int
nested n = \x -> x + n

-- Two slots, one of them discarded by a wildcard pattern.
first :: Int -> Int -> Int
first = \a _ -> a

-- A captured local behind a `let`, and an inner lambda that shadows the outer
-- binding: the capture set is the source name, the env it resolves in is lowering's.
shadow :: Int -> Int -> Int
shadow x = let y = x * 2 in \z -> y + z

-- A recursive local whose members are lambdas: those are letrec bindings, and
-- their slots and captures are terms too.
recCapture :: Int -> Int
recCapture n =
  let go 0 = n
      go k = go (k - 1) + 1
  in go 3

main :: IO ()
main = do
  putStrLn (show (eqZero 1))
  putStrLn (show (eqN 2 2))
  putStrLn (show (tagged 1 1))
  putStrLn (show (nested 10 5))
  putStrLn (show (first 7 9))
MOG;

$work = createTempDir('moggi-checked-lambda');
$path = $work . '/Main.mog';
\file_put_contents($path, $source);

try {
    $context = CompilationContext::for(
        'php',
        CompilePurpose::Executable,
        entryModules: ['Main'],
        roots: [$work],
        sources: [$path],
    );
    $prepared = Modules\moduleCompileContext($path, $context, null);
    $program = $prepared['typedProgram'];
    $core = $program->core;
    $assert($core !== null, 'the check published no checked core for `Main`');

    // The dictionary-parameter answer the capture analysis needs, read from the terms
    // this product published — the same question `lowerNamedLambda()` asks.
    $evidenceParameterFor = static function (Modules\CheckedFunction $record): callable {
        return static fn (Ast\EvidenceMethod $method): ?string
            => $record->termFor($method)
                instanceof CheckedEvidenceMethod
                ? $record->termFor($method)->plan->parameter
                : null;
    };

    $lambdas = 0;
    $applications = 0;
    $applicationsWithArity = 0;
    /** @var array<string, list<list<string>>> $capturesByDeclaration */
    $capturesByDeclaration = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }
        $record = $core->function($item->name);
        $assert($record !== null, "`Main::{$item->name}` is lowered and has no record");
        $evidenceParameter = $evidenceParameterFor($record);

        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use (
            $record,
            $item,
            $evidenceParameter,
            &$lambdas,
            &$applications,
            &$applicationsWithArity,
            &$capturesByDeclaration,
        ): void {
            if ($node instanceof Ast\Lambda) {
                ++$lambdas;
                $term = $record?->termFor($node);
                if (! $term instanceof CheckedLambda) {
                    throw new \RuntimeException(
                        'the lambda at line ' . $node->line . ' of `' . $item->name
                        . '` is not published as a checked term',
                    );
                }

                $slots = patternParams($node->params);
                if ($term->parameterNames !== $slots) {
                    throw new \RuntimeException(
                        'the lambda at line ' . $node->line . ' publishes slots '
                        . \json_encode($term->parameterNames) . ' where the declaration takes '
                        . \json_encode($slots),
                    );
                }
                if ($term->arity() !== \count($node->params)) {
                    throw new \RuntimeException(
                        'the lambda at line ' . $node->line . ' publishes arity ' . $term->arity()
                        . ' for ' . \count($node->params) . ' parameter(s)',
                    );
                }

                $expected = \array_values(\array_unique(freeVarsExpr(
                    $node->body,
                    \array_fill_keys($slots, true),
                    $evidenceParameter,
                )));
                \sort($expected);
                $published = $term->captures;
                $capturesByDeclaration[$item->name][] = $published;
                \sort($published);
                if ($published !== $expected) {
                    throw new \RuntimeException(
                        'the lambda at line ' . $node->line . ' publishes captures '
                        . \json_encode($published) . ' where its body is free in '
                        . \json_encode($expected),
                    );
                }

                return;
            }

            if (! $node instanceof Ast\Apply) {
                return;
            }

            $term = $record?->termFor($node);
            // A primop site is the intrinsic family's, not this one's.
            if ($term instanceof CheckedIntrinsicCall) {
                return;
            }
            ++$applications;
            if (! $term instanceof CheckedApplication) {
                throw new \RuntimeException(
                    'the application at line ' . $node->line . ' of `' . $item->name
                    . '` is not published as a checked term',
                );
            }
            $expectedArity = remainingArity($node->inferredInternalType);
            if ($expectedArity !== $term->remainingArity) {
                throw new \RuntimeException(
                    'the application at line ' . $node->line . ' publishes ' . $term->remainingArity
                    . ' remaining argument(s) where its type says ' . $expectedArity,
                );
            }
            if ($term->remainingArity > 0) {
                ++$applicationsWithArity;
            }
        });
    }

    $normalised = static function (array $lists): array {
        foreach ($lists as &$list) {
            \sort($list);
        }
        \sort($lists);

        return $lists;
    };

    // The capture sets the shape of the env alone cannot tell you: a `let`-bound name,
    // a shadowing inner slot, and a recursive local pre-bound by its own group.
    $assert(
        $normalised($capturesByDeclaration['shadow'] ?? []) === [['y']],
        'the lambda behind a `let` must close over `y` alone, got: '
            . \json_encode($capturesByDeclaration['shadow'] ?? []),
    );
    // A recursive group lowers as curried lambdas — an outer one taking the group's
    // dictionaries, an inner one taking the matched argument — so the assertion is on
    // what every member closes over, not on the exact set (the inner one also names the
    // dictionaries its outer lambda binds).
    $members = $capturesByDeclaration['recCapture'] ?? [];
    $assert(\count($members) === 2, 'recCapture must lower as two lambdas, got: ' . \json_encode($members));
    foreach ($members as $captures) {
        $assert(
            \in_array('go', $captures, true) && \in_array('n', $captures, true),
            'every member of the recursive group must close over the group\'s own name and `n`, got: '
                . \json_encode($members),
        );
    }

    $assert($lambdas >= 2, 'the fixture elaborated ' . $lambdas . ' lambda(s), so it covers too little');
    $assert($applications > 0, 'the fixture elaborated no application, so this test says nothing about them');
    $assert(
        $applicationsWithArity > 0,
        'no application in the fixture is waiting for arguments, so the remaining-arity fact was never non-zero',
    );

    $pristine = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));

    // The eta-expanded lambdas are built from those facts, not from nodes: a partial
    // method application keeps the local it captured, in its own slot order.
    $assert(
        \str_contains($pristine, '->==(n, methodArg'),
        "the eta-expanded method partial lost the local it captured:\n" . $pristine,
    );
    $assert(
        \preg_match('/function λ\d+\(y\)\n\s+t\d+ = dict_call __ev_Eq->==\(x, y\)/', $pristine) === 1,
        "the source lambda is not lowered as its own callable, with its slot and its captured\n"
            . " dictionary:\n" . $pristine,
    );
    $assert(
        \preg_match('/function λ\d+\(a, __wild1\)/', $pristine) === 1,
        "a wildcard parameter did not become a slot of its own:\n" . $pristine,
    );

    // 2. The term is the authority. Nothing this family reads is the node's copy: the
    //    slots lowering used to take from `$expr->params`, and the arity it used to
    //    count off the node's internal type at a method-partial site.
    //
    //    The type is changed rather than cleared, and only on those sites: lowering
    //    itself asks the same field whether an expression is an IO action, and that
    //    question is another family's fact. A two-arrow type of the same IO-ness is
    //    the sharpest input — the old reader would have eta-expanded two slots.
    $scrubbed = 0;
    $arityScrubbed = 0;
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $core->function($item->name) === null) {
            continue;
        }
        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use (&$scrubbed, &$arityScrubbed): void {
            if ($node instanceof Ast\Lambda) {
                $node->params = [new Ast\LambdaParam(new Ast\PatVar('scrubbedSlot'))];
                ++$scrubbed;

                return;
            }
            if (! $node instanceof Ast\Apply) {
                return;
            }

            $head = $node->function;
            while ($head instanceof Ast\Apply) {
                $head = $head->function;
            }
            if (! $head instanceof Ast\EvidenceMethod) {
                return;
            }

            $node->inferredInternalType = new TypeExpr\TArrow(
                new TypeExpr\TInt(),
                new TypeExpr\TArrow(new TypeExpr\TInt(), new TypeExpr\TInt()),
            );
            ++$arityScrubbed;
        });
    }
    $assert($arityScrubbed > 0, 'the fixture elaborated no method partial, so the arity scrub said nothing');
    $assert($scrubbed > 0, 'the fixture elaborated nothing to scrub');
    $after = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));
    $assert(
        $after === $pristine,
        "lowering reads a lambda's or an application's own facts instead of its checked term:\n"
            . diffText($pristine, $after),
    );

    // 3. A site the record does not cover is refused, for each shape.
    $refusals = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl) {
            continue;
        }
        $record = $core->function($item->name);
        $assert($record !== null, "`Main::{$item->name}` is lowered and has no record");
        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use (&$refusals, $program, $path, $record): void {
            $family = $node instanceof Ast\Lambda ? 'lambda' : ($node instanceof Ast\Apply ? 'apply' : null);
            if ($family === null || $record->terms->termFor($node) === null) {
                return;
            }
            $saved = $record->terms->termFor($node);
            $record->terms->forget($node);

            try {
                // A site lowering never reaches on its own (the head of a spine is
                // taken as the tail of its arguments) legitimately lowers anyway; the
                // hand-built nodes below cover the refusal for exactly those shapes.
                IR\lower($program, $program->interface->data(), $path);
            } catch (\RuntimeException $refusal) {
                if (! \str_contains($refusal->getMessage(), 'does not plan')) {
                    throw $refusal;
                }
                $refusals[$family] = ($refusals[$family] ?? 0) + 1;
            } finally {
                $record->terms->restore($node, $saved);
            }
        });
    }
    foreach (['lambda', 'apply'] as $family) {
        $assert(
            ($refusals[$family] ?? 0) > 0,
            'lowering accepted an unplanned ' . $family . ' site; refusals: ' . \json_encode($refusals),
        );
    }

    // A node built after publication is what the eta paths used to hand over; neither
    // shape can be lowered any more.
    $ctx = IR\newCtx([], new IR\LowerState());
    $ctx->moduleName = 'Main';
    $ctx->functionName = 'fabricated';

    foreach ([
        'lambda' => new Ast\Lambda([], new Ast\IntegerLit(1), 1, 1, 1),
        'apply' => new Ast\Apply(new Ast\Variable('f'), new Ast\IntegerLit(1), 1, 1, 1),
    ] as $shape => $fabricated) {
        try {
            IR\lowerExpr($fabricated, $ctx);
            $assert(false, 'lowering a ' . $shape . ' built after publication succeeded');
        } catch (\RuntimeException $refusal) {
            $assert(
                \str_contains($refusal->getMessage(), 'does not plan'),
                'a post-publication ' . $shape . ' node was lowered instead of refused: ' . $refusal->getMessage(),
            );
        }
    }

    echo 'checked lambda term tests passed (' . $lambdas . ' lambdas, ' . $applications
        . " applications, {$scrubbed} sites scrubbed)\n";
} finally {
    removeDirectory($work);
}
