<?php declare(strict_types=1);

/**
 * `case` and binding groups own their structure in the checked core, and lowering
 * cannot lower one it was not given.
 *
 * These two families were the last places where the "checked core" was metadata
 * over the AST: a `case` arm list and a group's binding order were read from
 * `CaseExpr::$alts` / `Let::$bindings` at lowering time, and the SCC decomposition
 * inference had already computed was recomputed by the lowerer from the same
 * nodes. Both are now terms ({@see \Moggi\Modules\CheckedCase},
 * {@see \Moggi\Modules\CheckedLetGroup}), and a `do` statement's group is the same
 * term as a `let`'s. This test pins the three consequences:
 *
 * 1. every `case`, `let`, `where` and `do`-`let` of a real program is published
 *    with the arms, guards and components the node holds — the publication is not
 *    a subset of the tree;
 * 2. lowering reads the term and not the node: emptying every `CaseExpr::$alts`
 *    (and `$exhaustive`) and every group's `$bindings` leaves the IR byte-identical;
 * 3. a `case` or a group the record does not cover is refused, and nothing under
 *    `src/ir` builds one of these node shapes — the short-circuit `&&`/`||` match
 *    used to be built from two fabricated `Ast\Alt` nodes, and is IR now.
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
use Moggi\Modules\CheckedCase;
use Moggi\Modules\CheckedLetGroup;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\bindingGroupSccs;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$source = <<<'MOG'
module Main where

import Moggi.Internal.Prim (intAdd#)

-- A `case` with a guarded arm whose three clauses read its own binder, a literal arm and a
-- catch-all. (A guarded alternative has to come first: the parser reads a guard after a plain
-- alternative as an equation's guard. That limitation is the parser's, not this family's.)
sign :: Int -> String
sign n =
  case n of
    m | m < 0 -> "neg"
      | m > 100 -> "big"
      | otherwise -> "pos"
    0 -> "zero"
    _ -> "other"

-- A guard that is not operand-shaped, so lowering gives the guard statements of its own.
classify :: [Int] -> String
classify xs =
  case xs of
    x : _ | x > 0 -> "pos-head"
    _ -> "other"

-- Nested `let`, a member bound to a lambda, and a member that reads an earlier one.
nested :: Int -> Int
nested n =
  let a = 1 :: Int
      b = \k -> k + a
  in b (n + a)

-- A `where` group whose members must bind together.
evenOdd :: Int -> Bool
evenOdd n = isEven n
  where
    isEven 0 = True
    isEven k = isOdd (k - 1)
    isOdd 0 = False
    isOdd k = isEven (k - 1)

-- A primop eta-expanded over an argument that is a `case`: capture analysis runs over that
-- argument, so it too has to read the term rather than the node. The arm names an outer binder
-- the scrutinee does not, so a capture set taken from an emptied node would lose `k`.
bump :: Int -> Int -> Int -> Int
bump n k = intAdd# (case n of
                      0 -> k
                      _ -> n)

-- A recursive group's nullary member whose body is a `case`: the member's own captures come from
-- it. Again the arm names a binder the scrutinee does not.
pick :: Int -> Int -> Int
pick n m = go
  where
    go = case n of
      0 -> m
      _ -> n

-- A partially applied dictionary method whose supplied argument is a `let`.
plusOne :: Int -> Int
plusOne n = (+) (let d = 1 :: Int in d) n

-- A `case` and a `let` inside a `do` block: the case is the same family in its IO shape, and the
-- group's scope is the statement list rather than an expression.
pairSum :: Int -> IO ()
pairSum n = do
  let a = 1 :: Int
      b = 2 :: Int
  case n of
    m | m < 0 -> putStrLn ("neg " <> show (a + b))
    0 -> putStrLn (show (a + b))
    _ -> putStrLn (show (n + a + b))

main :: IO ()
main = do
  putStrLn (sign (-3))
  putStrLn (sign 0)
  putStrLn (classify [2, 4])
  putStrLn (show (nested 3))
  putStrLn (show (evenOdd 4))
  putStrLn (show (True && evenOdd 4))
  putStrLn (show (False || evenOdd 3))
  putStrLn (show (bump 0 5 7))
  putStrLn (show (pick 0 9))
  putStrLn (show (plusOne 4))
  pairSum 0
MOG;

$shapeOf = static function (Ast\AstNode $node): string {
    return match (true) {
        $node instanceof Ast\CaseExpr => 'case',
        $node instanceof Ast\IoCase => 'io-case',
        $node instanceof Ast\Let => 'let',
        $node instanceof Ast\Where => 'where',
        default => 'do-let',
    };
};

$work = createTempDir('moggi-checked-case-let');
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

    $flatten = static function (Ast\Alt $alt): array {
        if ($alt->body instanceof Ast\GuardsExpr) {
            return \array_map(
                static fn (Ast\Guarded $guarded): array => ['guard' => $guarded->guard, 'body' => $guarded->body],
                $alt->body->clauses,
            );
        }

        return [['guard' => null, 'body' => $alt->body]];
    };

    // 1. Publication covers the tree: every `case` and every binding group is a term
    //    whose structure is what the node holds.
    $caseNodes = [];
    $groupNodes = [];
    $guardedArms = 0;
    $recursiveComponents = 0;
    $shapes = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }
        $record = $core->function($item->name);
        $assert($record !== null, "the core records no `{$item->name}`");

        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use (
            $record,
            $item,
            $flatten,
            $shapeOf,
            &$caseNodes,
            &$groupNodes,
            &$guardedArms,
            &$recursiveComponents,
            &$shapes,
        ): void {
            if ($node instanceof Ast\CaseExpr || $node instanceof Ast\IoCase) {
                $term = $node->checkedTerm === null ? null : $record->term($node->checkedTerm);
                if (! $term instanceof CheckedCase) {
                    throw new \RuntimeException(
                        'the `case` at line ' . $node->line . ' of `' . $item->name
                        . '` is not published as a checked term',
                    );
                }
                if (\count($term->arms) !== \count($node->alts)) {
                    throw new \RuntimeException(
                        'the `case` at line ' . $node->line . ' publishes ' . \count($term->arms)
                        . ' arms for ' . \count($node->alts) . ' alternatives',
                    );
                }
                foreach (\array_values($node->alts) as $index => $alt) {
                    $arm = $term->arms[$index];
                    if ($arm->pattern !== $alt->pattern) {
                        throw new \RuntimeException(
                            'arm ' . $index . ' of the `case` at line ' . $node->line . ' names another pattern',
                        );
                    }
                    if ($arm->clauses !== $flatten($alt)) {
                        throw new \RuntimeException(
                            'arm ' . $index . ' of the `case` at line ' . $node->line
                            . ' flattened its clauses differently from the source',
                        );
                    }
                    if ($arm->lastGuard() !== null) {
                        ++$guardedArms;
                    }
                }
                if ($term->exhaustive !== $node->exhaustive) {
                    throw new \RuntimeException(
                        'the `case` at line ' . $node->line . ' publishes a totality the check did not decide',
                    );
                }

                $caseNodes[] = $node;
                $shape = $shapeOf($node);
                $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;

                return;
            }

            if (! $node instanceof Ast\Let && ! $node instanceof Ast\Where && ! $node instanceof Ast\IoLet) {
                return;
            }

            $term = $node->checkedTerm === null ? null : $record->term($node->checkedTerm);
            if (! $term instanceof CheckedLetGroup) {
                throw new \RuntimeException(
                    'the `' . $node::class . '` at line ' . $node->line . ' of `' . $item->name
                    . '` is not published as a checked term',
                );
            }

            $members = $term->members();
            if (\count($members) !== \count($node->bindings)) {
                throw new \RuntimeException(
                    'the group at line ' . $node->line . ' publishes ' . \count($members)
                    . ' members for ' . \count($node->bindings) . ' bindings',
                );
            }

            // The components are the members in dependency order, each carrying whether its members
            // bind each other — which is exactly what the check's decomposition answers.
            $ordered = [];
            $expectedOrder = [];
            foreach (bindingGroupSccs($node->bindings) as $component) {
                foreach ($component['indices'] as $index) {
                    $expectedOrder[] = $node->bindings[$index];
                }
            }
            foreach ($term->components as $component) {
                if ($component->recursive) {
                    ++$recursiveComponents;
                }
                foreach ($component->bindings as $binding) {
                    $ordered[] = $binding;
                }
            }
            if ($ordered !== $members) {
                throw new \RuntimeException('the group at line ' . $node->line . ' lost a member in its components');
            }
            if ($members !== $expectedOrder) {
                throw new \RuntimeException(
                    'the group at line ' . $node->line . ' publishes a binding order the check did not compute',
                );
            }
            if ($node instanceof Ast\Let && $term->body !== $node->body) {
                throw new \RuntimeException('the `let` at line ' . $node->line . ' publishes another body');
            }
            if ($node instanceof Ast\Where && $term->body !== $node->expr) {
                throw new \RuntimeException('the `where` at line ' . $node->line . ' publishes another body');
            }
            if ($node instanceof Ast\IoLet && $term->body !== null) {
                throw new \RuntimeException('a `do` statement group at line ' . $node->line . ' names a body');
            }

            $groupNodes[] = $node;
            $shape = $shapeOf($node);
            $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
        });
    }

    foreach (['case', 'io-case', 'let', 'where', 'do-let'] as $shape) {
        $assert(
            isset($shapes[$shape]),
            "the fixture elaborated no `{$shape}`, so this test says nothing about it — shapes seen: "
                . \json_encode($shapes),
        );
    }
    $assert($guardedArms > 0, 'the fixture detailed no guarded arm');
    $assert($recursiveComponents > 0, 'the fixture carries no recursive binding component');

    $pristine = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));

    // 2. The terms are the authority: emptying the node's own structure leaves the IR identical.
    //    The nodes are collected first because emptying a `where`'s bindings would otherwise hide
    //    the cases inside its members from the walk that is doing the emptying.
    foreach ($caseNodes as $node) {
        $node->alts = [];
        $node->exhaustive = false;
    }
    foreach ($groupNodes as $node) {
        $node->bindings = [];
    }

    $after = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));
    $assert(
        $after === $pristine,
        "lowering reads a node's own arms or bindings instead of its checked term:\n"
            . diffText($pristine, $after),
    );

    // 3. A site the record does not cover is refused, for every shape the family covers.
    $refusals = [];
    $refused = static function (Ast\AstNode $node) use ($program, $path): bool {
        $saved = $node->checkedTerm;
        $node->checkedTerm = null;

        try {
            IR\lower($program, $program->interface->data(), $path);
        } catch (\RuntimeException $refusal) {
            if (\str_contains($refusal->getMessage(), 'that its checked-core record does not plan')) {
                return true;
            }

            throw $refusal;
        } finally {
            $node->checkedTerm = $saved;
        }

        return false;
    };

    foreach ([...$caseNodes, ...$groupNodes] as $node) {
        if ($node->checkedTerm === null) {
            continue;
        }
        $shape = $shapeOf($node);
        $assert(
            $refused($node),
            "lowering accepted an unplanned `{$shape}` at line {$node->line} of `" . $node::class . '`',
        );
        $refusals[$shape] = ($refusals[$shape] ?? 0) + 1;
    }
    foreach (['case', 'io-case', 'let', 'where', 'do-let'] as $shape) {
        $assert(
            ($refusals[$shape] ?? 0) > 0,
            'lowering refused no unplanned ' . $shape . '; refused: ' . \json_encode($refusals),
        );
    }

    // And nothing under `src/ir/` may build one of these node shapes: a fabricated
    // `case` or group has no term by construction, so the only way lowering keeps
    // working is to stop inventing them — the short-circuit match is IR now.
    $fabrications = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/ir'));
    foreach ($iterator as $file) {
        if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $body = (string) \file_get_contents($file->getPathname());
        foreach (['CaseExpr', 'IoCase', 'Let', 'Where', 'IoLet', 'Alt', 'GuardsExpr'] as $family) {
            if (\preg_match('/new Ast\\\\' . $family . '\\s*\\(/', $body) === 1) {
                $fabrications[] = \str_replace($root . '/', '', $file->getPathname()) . ' builds `' . $family . '`';
            }
        }
    }
    $assert(
        $fabrications === [],
        "lowering may not construct a node of a structural term family after the elaboration is\n"
            . "published — the node would have no term to read:\n  " . \implode("\n  ", $fabrications),
    );

    echo 'checked case/let term tests passed (' . \count($caseNodes) . ' cases, ' . \count($groupNodes)
        . ' groups (' . \json_encode($shapes) . '), ' . $guardedArms . ' guarded arms, '
        . $recursiveComponents . ' recursive components, '
        . (\count($caseNodes) + \count($groupNodes)) . ' structures scrubbed, '
        . \array_sum($refusals) . " sites refused)\n";
} finally {
    removeDirectory($work);
}
