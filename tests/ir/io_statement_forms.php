<?php declare(strict_types=1);

/**
 * Every `do` statement and every `do` body carries the facts lowering reads, and lowering refuses
 * one that does not.
 *
 * The spine used to answer two questions by trying a lowering and falling back:
 *
 * 1. **Is this a run or a value?** — the checker's, written on the expression
 *    (`Ast\PublishesIoForm::$ioForm`) where the type and the `let`-bound actions in scope are both
 *    known, read in exactly one place (`Lower\Io::ioStatementFormOf()`).
 * 2. **Which statement is this?** — the checker's, written on the statement (`Ast\PublishesIoForm::$ioKind`)
 *    where the desugared form is in hand, read in exactly one place (`Lower\Io::statementKind()`),
 *    which is the walk's *only* dispatch.
 *
 * Four things are worth a case rather than an argument:
 *
 * 1. **The publication covers the tree.** Every statement of a real program, and every `do` body (a
 *    function's, a `case` arm's, a nested `do`'s), carries its form, and each is one of the five the
 *    enum declares; every statement carries a disposition, and each is one of the four the enum
 *    declares. A missing one here would be a shape lowering could only guess at.
 * 2. **A cleared form refuses**, and **a cleared disposition refuses**, each naming the site — not
 *    falling back to a classifier, and not emitting code chosen by what the attempt happened to do.
 *    This is the checked-core families' "a cleared id refuses" applied to the spine's two facts.
 * 3. **Restoring them changes nothing.** The IR is byte-identical to the IR from the untouched tree.
 *
 * The fixture deliberately exercises all five forms and all four dispositions: a pure statement
 * (`pure ()`), a `case` (a run), a `let` group, a `let`-bound action used by a later bind, a nested
 * `do` in bind position, and an ordinary action call.
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

import System.IO
import Text.Show

report :: Int -> IO ()
report n = do
  let greeting = do
        putStrLn "hello"
        putStrLn "again"
  let a = n + 1
      b = a + 1
  x <- (do
          m <- pure b
          pure m)
  putStrLn (show x)
  case n of
    0 -> putStrLn "zero"
    _ -> greeting
  pure ()

main :: IO ()
main = do
  report 0
  report 3
  putStrLn "done"
MOG;

$work = createTempDir('moggi-io-forms');
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

    // Every site that must carry a published fact: a body's form, a statement's form, a statement's kind.
    $sites = [];
    $statements = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }

        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use (&$sites, &$statements, $item): void {
            if ($node instanceof Ast\IoLet || $node instanceof Ast\IoLetAction
                || $node instanceof Ast\IoBind || $node instanceof Ast\IoExpr) {
                $statements[] = ['node' => $node, 'function' => $item->name];
            }

            if ($node instanceof Ast\IoStatementExpression) {
                // A body: a `do` block, a `case` body, a nested `do`, a `pure` body.
                $sites[] = ['node' => $node, 'function' => $item->name];
            } elseif ($node instanceof Ast\IoExpr || $node instanceof Ast\IoBind || $node instanceof Ast\IoLetAction) {
                $sites[] = ['node' => $node->expr, 'function' => $item->name];
            }
        });
    }

    // 1. The publication covers the tree: every form and every disposition the fixture was written for.
    $shapes = [];
    foreach ($sites as $site) {
        $form = $site['node']->ioForm;
        $assert(
            $form !== null,
            'the `' . $site['node']::class . '` at line ' . $site['node']->line . ' of `'
                . $site['function'] . '` carries no published form',
        );
        $shapes[$form->value] = ($shapes[$form->value] ?? 0) + 1;
    }
    $assert(\count($sites) > 5, 'the walk found only ' . \count($sites) . ' statement bodies to check');
    foreach (IR\IoStatementForm::cases() as $case) {
        $assert(
            isset($shapes[$case->value]),
            'the fixture published no `' . $case->value . '` form, so this case says nothing about it'
                . ' — shapes seen: ' . \json_encode($shapes),
        );
    }

    $kinds = [];
    foreach ($statements as $statement) {
        $kind = $statement['node']->ioKind;
        $assert(
            $kind !== null,
            'the `' . $statement['node']::class . '` at line ' . $statement['node']->line . ' of `'
                . $statement['function'] . '` carries no published disposition',
        );
        $kinds[$kind->value] = ($kinds[$kind->value] ?? 0) + 1;
    }
    foreach (IR\IoStatementKind::cases() as $case) {
        $assert(
            isset($kinds[$case->value]),
            'the fixture published no `' . $case->value . '` disposition, so this case says nothing about it'
                . ' — kinds seen: ' . \json_encode($kinds),
        );
    }

    // The IR with every published fact intact, and the reference for the two refusal checks.
    $pristine = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));

    // 2. A cleared fact refuses, naming the site — one at a time, so every position is proved.
    $refuse = static function (Ast\AstNode $node, string $field, string $expected) use ($program, $path): string {
        $saved = $node->{$field};
        $node->{$field} = null;

        try {
            IR\lower($program, $program->interface->data(), $path);

            return '';
        } catch (\RuntimeException $refusal) {
            $message = $refusal->getMessage();
            if (! \str_contains($message, $expected)) {
                return 'refused with an unexpected message: ' . $message;
            }
            if (! \str_contains($message, 'line ' . $node->line)) {
                return 'the refusal does not name its site: ' . $message;
            }

            return 'REFUSED';
        } finally {
            $node->{$field} = $saved;
        }
    };

    $refused = 0;
    foreach ($sites as $site) {
        $verdict = $refuse($site['node'], 'ioForm', 'without a published form');
        $assert(
            $verdict === 'REFUSED',
            'lowering accepted the `' . $site['node']::class . '` at line ' . $site['node']->line
                . ' of `' . $site['function'] . '` with its published `' . $site['node']->ioForm->value
                . '` form cleared' . ($verdict === '' ? ' (it lowered it)' : ': ' . $verdict),
        );
        ++$refused;
    }

    $refusedKinds = 0;
    foreach ($statements as $statement) {
        $verdict = $refuse($statement['node'], 'ioKind', 'with no published disposition');
        $assert(
            $verdict === 'REFUSED',
            'lowering accepted the `' . $statement['node']::class . '` at line ' . $statement['node']->line
                . ' of `' . $statement['function'] . '` with its published `' . $statement['node']->ioKind->value
                . '` disposition cleared' . ($verdict === '' ? ' (it lowered it)' : ': ' . $verdict),
        );
        ++$refusedKinds;
    }

    // 3. With every fact restored the program still lowers to exactly what it did before.
    $after = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));
    $assert(
        $after === $pristine,
        "restoring the published facts changed the IR:\n" . diffText($pristine, $after),
    );

    echo 'IO statement form tests passed (' . \count($sites) . ' forms published (' . \json_encode($shapes)
        . '), ' . \count($statements) . ' dispositions published (' . \json_encode($kinds) . '), '
        . $refused . ' cleared forms and ' . $refusedKinds . " cleared dispositions refused, restoring"
        . " them left the IR byte-identical)\n";
} finally {
    removeDirectory($work);
}
