<?php declare(strict_types=1);

/**
 * A variable use is a checked term that names its binder, and lowering asks the term.
 *
 * The spelling used to be the identity: a use was resolved by looking its name up in the frame's
 * environment, so two binders that shared a spelling could answer for each other and whichever
 * binding the walk had settled last won. `CheckedVariable` now carries the binder the checker
 * resolved, `CheckedLambda` publishes each slot's identity beside its name, and the lowering frame
 * is opened from those facts.
 *
 * Pinned here:
 *
 * 1. every variable use in a checked body is a `CheckedVariable`, and its binder is the binder of
 *    the binding that lexically encloses it — including where an inner scope reuses a spelling;
 * 2. scrubbing the *names* of the uses that have a binder leaves the dumped IR byte-identical,
 *    because the operand comes from the identity and not from the spelling;
 * 3. a cold and a warm (cached) compile of the same module emit the same artifact and the same
 *    program output, so the identity survives the module-cache round trip and the optimizer that
 *    runs after it.
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
use Moggi\Modules\CheckedVariable;
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

-- The lambda's own slot shadows the declaration's parameter.
shadow :: Int -> Int -> Int
shadow x = \x -> x + x

-- The shadowing slot and the shadowed parameter are used in the same expression, either
-- side of the application that introduces the inner one.
shadowTop :: Int -> Int
shadowTop x = (\x -> x + 1) 2 + x

-- The same, with the outer binding a `let` rather than a parameter.
shadowLocal :: Int -> Int
shadowLocal n = let x = n + 1 in (\x -> x * 2) 3 + x

-- The shadowing slot applied to the value of the shadowed parameter.
nestedLets :: Int -> Int
nestedLets x = (\x -> x + x) (x + 1) + x

-- A case arm's binder shadowing the declaration's parameter.
armShadow :: Int -> Int -> Int
armShadow x n = case n of
  0 -> x
  x -> x + 1

-- A recursive `where` group whose member shadows the declaration's parameter: the group's own
-- recursion is its binder's, and the body's `go 3` is the group's `go`, not the parameter.
recursiveShadow :: (Int -> Int) -> Int
recursiveShadow go = go 3
  where
    go 0 = 7
    go n = go (n - 1)

-- The same for a recursive `let` group, which shadows a lambda slot; the slot is still the
-- identity `go 10` resolves to once the group has closed.
letShadow :: Int -> Int
letShadow n = (\go -> (let go 0 = n
                           go k = go (k - 1)
                       in go 2) + go 10) (\x -> x)

-- A member's spelling reused by a lambda slot inside a member's own body: the slot is a different
-- binder, so the group's dictionaries are not applied to it.
slotInGroup :: Int -> Int
slotInGroup n = go 2
  where
    go 0 = n
    go k = (\go -> go) (go (k - 1))

-- Mutual members of one `where` group, one of them shadowing a parameter of the declaration.
mutualShadow :: (Int -> Bool) -> Int -> Bool
mutualShadow isOdd n = isEven n
  where
    isEven 0 = True
    isEven k = isOdd (k - 1)
    isOdd 0 = False
    isOdd k = isEven (k - 1)

main :: IO ()
main = do
  putStrLn (show (shadow 1 2))
  putStrLn (show (shadowTop 5))
  putStrLn (show (shadowLocal 2))
  putStrLn (show (nestedLets 3))
  putStrLn (show (armShadow 5 0))
  putStrLn (show (armShadow 5 3))
  putStrLn (show (recursiveShadow (\x -> x)))
  putStrLn (show (letShadow 5))
  putStrLn (show (slotInGroup 4))
  putStrLn (show (mutualShadow (\_ -> True) 3))
MOG;

$work = createTempDir('moggi-checked-variable');
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

    // 1. The term is the use's own resolution, and it agrees with the lexical scope.
    $uses = 0;
    $shadowed = 0;
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }
        $record = $core->function($item->name);
        $assert($record !== null, "`Main::{$item->name}` is lowered and has no record");

        /** @var list<array{name: string, binder: ?int}> $scope */
        $scope = [];
        $walk = static function (Ast\AstNode $node) use (&$walk, &$scope, $record, $item, &$uses, &$shadowed): void {
            if ($node instanceof Ast\PatVar) {
                return;
            }
            if ($node instanceof Ast\Variable) {
                $term = $node->checkedTerm === null ? null : $record?->term($node->checkedTerm);
                if (! $term instanceof CheckedVariable) {
                    throw new \RuntimeException(
                        'the use of `' . $node->name . '` at line ' . $node->line . ' of `'
                        . $item->name . '` is not published as a checked variable term',
                    );
                }
                ++$uses;
                $enclosing = null;
                for ($i = \count($scope) - 1; $i >= 0; --$i) {
                    if ($scope[$i]['name'] === $node->name) {
                        $enclosing = $scope[$i]['binder'];

                        break;
                    }
                }
                if ($enclosing !== null && $enclosing !== $term->binder) {
                    throw new \RuntimeException(
                        'the use of `' . $node->name . '` at line ' . $node->line . ' of `'
                        . $item->name . '` names binder ' . \json_encode($term->binder)
                        . ' where the enclosing binding is binder ' . \json_encode($enclosing),
                    );
                }
                if ($enclosing !== null) {
                    ++$shadowed;
                }

                return;
            }
            if ($node instanceof Ast\Lambda) {
                foreach ($node->params as $param) {
                    $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;
                    if ($pattern instanceof Ast\PatVar) {
                        $scope[] = ['name' => $pattern->name, 'binder' => $pattern->binderId];
                    }
                }
                $walk($node->body);
                \array_splice($scope, \count($scope) - \count($node->params));

                return;
            }
            if ($node instanceof Ast\Let || $node instanceof Ast\Where) {
                $bodyField = $node instanceof Ast\Let ? 'body' : 'expr';
                foreach ($node->bindings as $binding) {
                    if ($binding->pattern instanceof Ast\PatVar) {
                        // A group's member is in scope in every member's value and in the body: the
                        // checker resolves those uses to the member's binder, which is why the
                        // binding site's identity has to be allocated before any value is checked.
                        $scope[] = ['name' => $binding->pattern->name, 'binder' => $binding->pattern->binderId];
                    }
                }
                foreach ($node->bindings as $binding) {
                    $walk($binding->value);
                }
                $walk($node->{$bodyField});
                \array_splice($scope, \count($scope) - \count($node->bindings));

                return;
            }
            foreach (\get_object_vars($node) as $value) {
                if ($value instanceof Ast\AstNode) {
                    $walk($value);

                    continue;
                }
                if (! \is_array($value)) {
                    continue;
                }
                foreach ($value as $element) {
                    if ($element instanceof Ast\AstNode) {
                        $walk($element);
                    }
                }
            }
        };
        $walk($item->body);
    }
    $assert($uses >= 8, 'the fixture lowered ' . $uses . ' variable use(s), so it covers too little');
    $assert($shadowed >= 4, 'the fixture produced ' . $shadowed . ' bound use(s), so shadowing is not exercised');

    $pristine = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));

    // The scopes really do collide: one spelling, two slots, both live across the expression.
    $assert(
        \preg_match('/function shadowTop\(x\)/', $pristine) === 1,
        "the fixture changed shape; `shadowTop` is not a one-slot function:\n" . $pristine,
    );

    // 2. Nothing the family reads is the node's spelling: renaming every bound use leaves the IR
    //    alone, while the binders' own identities (and the slots they allocate) stay put.
    $scrubbed = 0;
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $core->function($item->name) === null) {
            continue;
        }
        $record = $core->function($item->name);
        $walk = static function (Ast\AstNode $node) use (&$walk, $record, &$scrubbed): void {
            if ($node instanceof Ast\Variable) {
                $term = $node->checkedTerm === null ? null : $record?->term($node->checkedTerm);
                if ($term instanceof CheckedVariable && $term->binder !== null) {
                    $node->name = 'scrubbedName';
                    ++$scrubbed;
                }

                return;
            }
            foreach (\get_object_vars($node) as $value) {
                if ($value instanceof Ast\AstNode) {
                    $walk($value);

                    continue;
                }
                if (! \is_array($value)) {
                    continue;
                }
                foreach ($value as $element) {
                    if ($element instanceof Ast\AstNode) {
                        $walk($element);
                    }
                }
            }
        };
        $walk($item->body);
    }
    $assert($scrubbed >= 8, 'no bound use was scrubbed, so the identity claim is untested');
    $after = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));
    $assert(
        $after === $pristine,
        "lowering resolved a variable use by its spelling instead of its binder:\n"
            . diffText($pristine, $after),
    );

    // 2b. The binding sites are terms too. Clearing the checker's copy on every pattern — a
    //     declaration's parameter, a `let`/`where` binding, a `case` arm, nested — and on every use
    //     leaves the IR alone, because each frame is opened from the pattern's term. A tree that was
    //     scrubbed, re-parsed or restored from a cache carries none of those copies.
    $sites = 0;
    $binders = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $core->function($item->name) === null) {
            continue;
        }
        $record = $core->function($item->name);
        $scour = static function (Ast\AstNode $node) use (&$scour, &$sites, &$binders, $record): void {
            if ($node instanceof Ast\PatVar && $node->binderId !== null) {
                $binders['pattern'][$node->binderId] = true;
                $node->binderId = null;
                ++$sites;
            }
            if ($node instanceof Ast\Variable) {
                $term = $node->checkedTerm === null ? null : $record?->term($node->checkedTerm);
                if ($term instanceof CheckedVariable && $term->binder !== null) {
                    $binders['use'][$term->binder] = true;
                    $node->binderId = null;
                    ++$sites;
                }

                return;
            }
            foreach (\get_object_vars($node) as $value) {
                if ($value instanceof Ast\AstNode) {
                    $scour($value);

                    continue;
                }
                if (! \is_array($value)) {
                    continue;
                }
                foreach ($value as $element) {
                    if ($element instanceof Ast\AstNode) {
                        $scour($element);
                    }
                }
            }
        };
        foreach ($item->params as $param) {
            if ($param instanceof Ast\AstNode) {
                $scour($param);
            }
        }
        $scour($item->body);
    }
    $assert(
        \count($binders['pattern'] ?? []) >= 3 && \count($binders['use'] ?? []) >= 3,
        'the fixture did not allocate enough binders to test the site terms: '
            . \json_encode(\array_map('count', $binders)),
    );
    $afterSites = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));
    $assert(
        $afterSites === $pristine,
        "lowering opened a frame from the pattern node's own copy instead of the site's term:\n"
            . diffText($pristine, $afterSites),
    );

    // 2c. The same tree, restored through a serialization round trip — the shape a cache hands back —
    //     with no node-side identity anywhere on it. The terms are the only place a binder is left.
    $restored = \unserialize(\serialize($program));
    $assert($restored instanceof Ast\Program, 'the elaborated program did not survive serialization');
    $roundTripped = IR\Dump\dump(IR\lower($restored, $restored->interface->data(), $path));
    $assert(
        $roundTripped === $pristine,
        "a cache-restored elaboration lowered differently from the one that produced it:\n"
            . diffText($pristine, $roundTripped),
    );

    // 3. The identity survives the module-cache round trip: the second, cache-warm compile re-parses
    //    the source (so no binder is on the node) and reads the checked core the first one cached.
    $cache = $work . '/.cache';
    $out = $work . '/out';
    $run = static function (string $outDir) use ($root, $work, $cache): array {
        $command = 'MOGGI_CACHE_DIR=' . \escapeshellarg($cache)
            . ' ' . \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg($root . '/moggi.php')
            . ' compile ' . \escapeshellarg($work)
            . ' --unpacked --backend php -o ' . \escapeshellarg($outDir);
        $lines = [];
        $status = 0;
        \exec($command . ' 2>&1', $lines, $status);

        return [$status, \implode("\n", $lines)];
    };

    $coldOut = $out . '-cold';
    [$coldStatus, $coldLog] = $run($coldOut);
    $assert($coldStatus === 0, "the cold compile failed:\n" . $coldLog);
    $warmOut = $out . '-warm';
    [$warmStatus, $warmLog] = $run($warmOut);
    $assert($warmStatus === 0, "the cache-warm compile failed:\n" . $warmLog);

    $generated = static function (string $dir): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[\str_replace($dir . '/', '', $file->getPathname())] = \file_get_contents($file->getPathname());
            }
        }
        \ksort($files);

        return $files;
    };
    $coldFiles = $generated($coldOut);
    $warmFiles = $generated($warmOut);
    $assert($coldFiles !== [], 'the cold compile emitted no PHP');
    $assert(
        $coldFiles === $warmFiles,
        'the cache-warm compile does not emit what the cold one does: '
            . \json_encode(\array_keys(\array_diff_key($coldFiles, $warmFiles)))
            . ' missing, '
            . \json_encode(\array_keys(\array_diff_key($warmFiles, $coldFiles))) . ' extra',
    );

    $entry = null;
    foreach (\array_keys($coldFiles) as $rel) {
        if (\basename($rel) === 'Main.php') {
            $entry = $coldOut . '/' . $rel;
        }
    }
    $assert($entry !== null, 'the compiled tree has no `Main.php`: ' . \json_encode(\array_keys($coldFiles)));

    $lines = [];
    $status = 0;
    \exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($entry) . ' 2>&1', $lines, $status);
    $assert($status === 0, "the compiled program failed:\n" . \implode("\n", $lines));
    $expected = "4\n8\n9\n11\n5\n4\n7\n15\n4\nFalse";
    $assert(
        \implode("\n", $lines) === $expected,
        "the compiled program printed:\n" . \implode("\n", $lines) . "\nexpected:\n" . $expected,
    );

    echo 'checked variable binding tests passed (' . $uses . ' uses, ' . $shadowed
        . ' shadowed, ' . $scrubbed . " scrubbed, cold and warm artifacts identical)\n";
} finally {
    removeDirectory($work);
}
