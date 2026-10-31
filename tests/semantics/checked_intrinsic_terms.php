<?php declare(strict_types=1);

/**
 * The primop family is a checked term, and lowering cannot lower a primop it was
 * not given.
 *
 * A primop decision is written at four node shapes — a saturated `IntrinsicCall`,
 * an operator `Infix`, and the `intrinsicWrapper` a bare or partially applied
 * primop leaves on a `Variable`, an `Apply` or a `QualifiedRef`. Each is planned
 * once, by `checkedTermsIn()`, through the single shape question
 * `Moggi\Modules\intrinsicNameFor()`; each is read by `plannedIntrinsic()`, which
 * refuses a site the record does not cover.
 *
 * Eta-expansion used to be the exception: an unsaturated primop was lowered by
 * *building* an `Ast\IntrinsicCall` for the lambda body after publication and then
 * asking that node for its primop. That is the inversion the checked core exists
 * to remove, and it is why the family could not be cut over. This test pins the
 * three consequences:
 *
 * 1. every primop site of a real program is published as a term carrying the
 *    checker's name, for all four shapes — a `CheckedIntrinsicCall`, or a
 *    `CheckedApplication` that names the primop it also supplies an argument to;
 * 2. lowering reads that term and not the node — scrubbing every site's payload
 *    field leaves the IR byte-identical;
 * 3. a primop node built after publication cannot be lowered at all, so eta
 *    expansion has to produce IR (which it now does, with capture analysis taken
 *    from the checked arguments the caller passes).
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
use Moggi\Modules\CheckedIntrinsicCall;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Syntax\Ast;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$source = <<<'MOG'
module Main where

import Moggi.Internal.Prim (boolNot#, intAdd#)

-- A bare primop reference: a `Variable` carries the wrapper and lowering
-- eta-expands it.
notAlias :: Bool -> Bool
notAlias = boolNot#

-- A partially applied primop: the wrapper sits on the `Apply` spine, and its
-- argument is a checked term the lambda has to capture.
addOne :: Int -> Int
addOne = intAdd# 1

-- A saturated primop: the checker rewrote the application to an
-- `IntrinsicCall`.
twice :: Int
twice = intAdd# 1 1

-- An operator that resolved to a primop keeps it on the `Infix`, and `&&` also
-- decides the shape lowering emits (a match, not an `Intrinsic`).
guarded :: Bool -> Bool -> Bool
guarded a b = a && b

main :: IO ()
main = do
  putStrLn (show (notAlias False))
  putStrLn (show (addOne 41))
  putStrLn (show twice)
  putStrLn (show (guarded True False))
MOG;

$work = createTempDir('moggi-checked-intrinsic');
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

    // 1. Every primop site is a term, of every shape the family covers.
    $sites = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }
        $record = $core->function($item->name);
        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use ($item, $record, &$sites): void {
            $name = Modules\intrinsicNameFor($node);
            if ($name === null) {
                return;
            }
            $term = $record?->termFor($node);
            $planned = $term instanceof CheckedIntrinsicCall
                ? $term->intrinsic
                : ($term instanceof CheckedApplication ? $term->intrinsic : null);
            if ($planned === null) {
                throw new \RuntimeException(
                    'the primop site `' . $name . '` on a ' . $node::class . ' at line ' . $node->line
                    . ' of `' . $item->name . '` is not published as a checked term',
                );
            }
            if ($planned !== $name) {
                throw new \RuntimeException(
                    'the term for the site at line ' . $node->line . ' names `' . $planned
                    . '` where the checker wrote `' . $name . '`',
                );
            }
            $sites[$node::class] = ($sites[$node::class] ?? 0) + 1;
        });
    }
    foreach ([Ast\Variable::class, Ast\Apply::class, Ast\IntrinsicCall::class, Ast\Infix::class] as $shape) {
        $assert(
            isset($sites[$shape]),
            'the fixture elaborated no ' . $shape . ' primop site, so this test says nothing about that shape'
                . ' — shapes seen: ' . \json_encode($sites),
        );
    }
    $assert(
        ($sites[Ast\Infix::class] ?? 0) >= 1 && ($sites[Ast\Variable::class] ?? 0) >= 1,
        'the fixture must exercise both the operator and the bare-reference shapes',
    );

    $pristine = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));

    // The eta-expanded lambdas are IR the lowering decided: the primop is applied
    // to the lambda's own slot, named after the parameter the builder was given.
    $assert(
        \str_contains($pristine, 'ret intrinsic boolNot#(primopArg0_0)'),
        "the eta-expanded `boolNot#` is not the IR application this pass builds:\n" . $pristine,
    );
    $assert(
        \str_contains($pristine, 'ret intrinsic intAdd#(1, primopArg0_1)'),
        "the eta-expanded partial `intAdd# 1` lost its captured argument:\n" . $pristine,
    );

    // 2. The term is the authority: the nodes' own payload fields are not read.
    $scrubbed = 0;
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $core->function($item->name) === null) {
            continue;
        }
        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use (&$scrubbed): void {
            if (Modules\intrinsicNameFor($node) === null) {
                return;
            }
            if ($node instanceof Ast\IntrinsicCall) {
                $node->name = 'scrubbed#';
            } elseif ($node instanceof Ast\Infix) {
                $node->resolvedIntrinsic = 'scrubbed#';
            } else {
                $node->intrinsicWrapper = 'scrubbed#';
            }
            ++$scrubbed;
        });
    }
    $assert($scrubbed > 0, 'the fixture elaborated no primop site, so the scrub proved nothing');
    $after = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));
    $assert(
        $after === $pristine,
        "lowering reads a primop site's own payload instead of its checked term:\n" . diffText($pristine, $after),
    );

    // 3. A primop node that publication never saw is refused, wherever lowering
    //    reads it. This is the property eta-expansion could not have kept while it
    //    built one — every reachable remedy is "the record plans it".
    //
    //    Not every planned site is a position lowering reads on its own: the head of
    //    an application spine is taken as the tail of its arguments, so clearing the
    //    inner `Variable` of `intAdd# 1` changes nothing. The refusal is therefore
    //    required per *shape*, on at least one site each, rather than per site.
    $refusals = [];
    $unreached = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl) {
            continue;
        }
        $record = $core->function($item->name);
        $assert($record !== null, "`Main::{$item->name}` is lowered and has no record");
        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use (&$refusals, &$unreached, $program, $path, $record): void {
            if (Modules\intrinsicNameFor($node) === null || $record->terms->termFor($node) === null) {
                return;
            }
            $saved = $record->terms->termFor($node);
            $record->terms->forget($node);

            $refused = false;
            try {
                IR\lower($program, $program->interface->data(), $path);
            } catch (\RuntimeException $refusal) {
                if (! \str_contains($refusal->getMessage(), 'that its checked-core record does not plan')) {
                    throw $refusal;
                }
                $refused = true;
                $refusals[$node::class] = ($refusals[$node::class] ?? 0) + 1;
            } finally {
                $record->terms->restore($node, $saved);
            }

            if (! $refused) {
                $unreached[$node::class] = ($unreached[$node::class] ?? 0) + 1;
            }
        });
    }
    foreach ([Ast\Variable::class, Ast\Apply::class, Ast\IntrinsicCall::class, Ast\Infix::class] as $shape) {
        $assert(
            ($refusals[$shape] ?? 0) > 0,
            'lowering accepted an unplanned primop site of shape ' . $shape . '; refused: '
                . \json_encode($refusals) . ', not read here: ' . \json_encode($unreached),
        );
    }
    $refusals = \array_sum($refusals);

    // A node built after publication is the shape eta-expansion used to hand
    // lowering — and it can no longer be lowered at all.
    $fabricated = new Ast\IntrinsicCall('boolNot#', [], 1, 1, 1);
    $ctx = IR\newCtx([], new IR\LowerState());
    $ctx->moduleName = 'Main';
    $ctx->functionName = 'fabricated';
    try {
        IR\lowerExpr($fabricated, $ctx);
        $assert(false, 'lowering an `IntrinsicCall` built after publication succeeded');
    } catch (\RuntimeException $refusal) {
        $assert(
            \str_contains($refusal->getMessage(), 'does not plan'),
            'a post-publication primop node was lowered instead of refused: ' . $refusal->getMessage(),
        );
    }

    // And nothing under `src/ir/` may build one of the term-bearing node shapes:
    // a fabricated node of a published family has no term by construction, so the
    // only way lowering keeps working is to stop inventing them. This is the one
    // scan for all six families; the lambda/application case names it in its own
    // docblock rather than repeating it.
    $fabrications = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/ir'));
    foreach ($iterator as $file) {
        if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $body = (string) \file_get_contents($file->getPathname());
        // Every published family, not only this test's: a lambda's slots and captures
        // are terms now (`semantics/checked_lambda_terms`), so a lambda built here
        // would be just as unliftable as a primop.
        foreach (['IntrinsicCall', 'ForeignCall', 'EvidenceRef', 'EvidenceMethod', 'Lambda', 'Apply'] as $family) {
            if (\str_contains($body, 'new Ast\\' . $family . '(')) {
                $fabrications[] = \str_replace($root . '/', '', $file->getPathname()) . ' builds `' . $family . '`';
            }
        }
    }
    $assert(
        $fabrications === [],
        "lowering may not construct a node of a published term family after the elaboration is\n"
            . "published — the node would have no term to read:\n  " . \implode("\n  ", $fabrications),
    );

    echo 'checked intrinsic term tests passed (' . $scrubbed . ' sites scrubbed, '
        . $refusals . " sites refused)\n";
} finally {
    removeDirectory($work);
}
