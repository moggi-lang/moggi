<?php declare(strict_types=1);

/**
 * Patterns own their shape in the checked core, and lowering cannot lower one it
 * was not given.
 *
 * A pattern was the last family whose structure lowering read off the node one
 * level below the term that owned it: `lowerPattern()` asked each `PatCon` for its
 * arguments, each `PatRecord` for its fields, each `PatVar` for its spelling, and
 * `ioLetActionName()` asked a `do`-statement's pattern for its name. Both are terms
 * now ({@see \Moggi\Modules\CheckedPattern}): the shape, the names, the value, the
 * sub-patterns by their own ids, and the binders the subtree introduces. This test
 * pins the three consequences:
 *
 * 1. every pattern node of a real program is published with the shape, name, value,
 *    children, fields and bound names the node holds — the publication is not a
 *    subset of the tree, and every pattern shape the parser can produce is covered;
 * 2. lowering reads the term and not the node: emptying every pattern node's payload
 *    (a var's name and identity, a constructor's arguments, a record's fields, a
 *    cons cell's halves) leaves the IR byte-identical;
 * 3. a pattern the record does not cover is refused, and nothing under `src/ir`
 *    builds one of these node shapes — a fabricated pattern has no term by
 *    construction, so the only way lowering keeps working is to stop inventing them.
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
use Moggi\Modules\CheckedPattern;
use Moggi\Modules\PatternShape;
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

data Shape = Circle { radius :: Int } | Square Int

-- A record pattern, a constructor pattern and a literal pattern.
describe :: Shape -> String
describe s =
  case s of
    Circle { radius = r } -> "circle " <> show r
    Square n -> "square " <> show n

-- A tuple pattern as a function parameter and as a lambda slot, and a wildcard.
pairSum :: (Int, Int) -> Int
pairSum (a, b) = a + b

partial :: Int -> Int
partial _ = 0

-- A literal pattern and a character pattern, each as an arm of its own.
zero :: Int -> Bool
zero n =
  case n of
    0 -> True
    _ -> False

isX :: Char -> String
isX c =
  case c of
    'x' -> "x"
    _ -> "other"

-- List patterns: the empty list, a cons cell with a character head, and a wildcard
-- head. The cons cell's tail is a variable, so the arm binds two names.
letters :: [Char] -> String
letters cs =
  case cs of
    [] -> "empty"
    'x' : _ -> "x"
    _ : rest -> "more " <> show (length rest)

-- A `let` that binds by a variable pattern, and a `do` statement that binds by one.
main :: IO ()
main = do
  let f = \p -> pairSum p
  let one = 1 :: Int
  putStrLn (describe (Circle { radius = 3 }))
  putStrLn (describe (Square 4))
  putStrLn (show (f (1, 2)))
  putStrLn (show (partial one))
  putStrLn (show (zero 0))
  putStrLn (isX 'x')
  putStrLn (letters ['x'])
  putStrLn (letters [])
MOG;

$work = createTempDir('moggi-checked-pattern-terms');
$path = $work . '/Main.mog';
\file_put_contents($path, $source);

/**
 * The names a pattern subtree binds, computed from the node by this test alone so
 * the term's answer is not being compared with itself.
 *
 * @return array<string, true>
 */
$namesBoundBy = null;
$namesBoundBy = static function (Ast\AstNode $pattern) use (&$namesBoundBy): array {
    if ($pattern instanceof Ast\PatVar) {
        return [$pattern->name => true];
    }

    $children = match (true) {
        $pattern instanceof Ast\PatCons => [$pattern->head, $pattern->tail],
        $pattern instanceof Ast\PatCon => $pattern->args,
        $pattern instanceof Ast\PatTuple => $pattern->elements,
        $pattern instanceof Ast\PatRecord => \array_map(
            static fn (Ast\PatField $field): Ast\AstNode => $field->pattern,
            $pattern->fields,
        ),
        default => [],
    };

    $names = [];
    foreach ($children as $child) {
        foreach ($namesBoundBy($child) as $name => $_) {
            $names[$name] = true;
        }
    }

    return $names;
};

$shapeOf = static function (Ast\AstNode $node): PatternShape {
    return match (true) {
        $node instanceof Ast\PatWild => PatternShape::Wild,
        $node instanceof Ast\PatVar => PatternShape::Var,
        $node instanceof Ast\PatLit => PatternShape::Lit,
        $node instanceof Ast\PatChar => PatternShape::Char,
        $node instanceof Ast\PatNil => PatternShape::Nil,
        $node instanceof Ast\PatCons => PatternShape::Cons,
        $node instanceof Ast\PatCon => PatternShape::Con,
        $node instanceof Ast\PatTuple => PatternShape::Tuple,
        $node instanceof Ast\PatRecord => PatternShape::Record,
        default => throw new \RuntimeException('`' . $node::class . '` is not a pattern'),
    };
};

/** The pattern children of a pattern node, in the order the term names them. */
$childrenOf = static function (Ast\AstNode $node): array {
    return match (true) {
        $node instanceof Ast\PatCons => [$node->head, $node->tail],
        $node instanceof Ast\PatCon => $node->args,
        $node instanceof Ast\PatTuple => $node->elements,
        default => [],
    };
};

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

    $patterns = [];
    $shapes = [];
    // A pattern reached only through another term — a sub-pattern of a parent pattern, or a
    // lambda's slot, which its lambda term names — is never a lowering *site*: clearing its node's
    // link is not a question lowering asks. These are the sites: what a binding, an arm, a `do`
    // bind or a declaration parameter points at.
    $sites = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl) {
            continue;
        }

        $record = $core->function($item->name);
        $assert($record !== null, "the core records no `{$item->name}`");

        foreach ($item->params as $param) {
            $sites[\spl_object_id($param)] = $param;
        }

        $roots = [...($item->signatureOnly ? [] : [$item->body]), ...$item->params];
        foreach ($roots as $rootNode) {
            Modules\walkElaboration($rootNode, static function (Ast\AstNode $node) use (
                $item,
                $record,
                $shapeOf,
                $childrenOf,
                $namesBoundBy,
                &$patterns,
                &$shapes,
                &$sites,
            ): void {
                if ($node instanceof Ast\CaseExpr || $node instanceof Ast\IoCase) {
                    foreach ($node->alts as $alt) {
                        $sites[\spl_object_id($alt->pattern)] = $alt->pattern;
                    }
                }
                if ($node instanceof Ast\Binding || $node instanceof Ast\IoBind) {
                    $sites[\spl_object_id($node->pattern)] = $node->pattern;
                }

                if (! Modules\isPatternNode($node)) {
                    return;
                }

                $term = $record->termFor($node);
                if (! $term instanceof CheckedPattern) {
                    throw new \RuntimeException(
                        'the pattern `' . $node::class . '` at line ' . $node->line . ' of `'
                        . $item->name . '` is not published as a checked term',
                    );
                }

                $expected = $shapeOf($node);
                if ($term->shape !== $expected) {
                    throw new \RuntimeException(
                        'the pattern at line ' . $node->line . ' publishes shape `' . $term->shape->value
                        . '` for a `' . $node::class . '`',
                    );
                }

                if ($expected === PatternShape::Var) {
                    if ($term->binder === null || $term->binder !== $node->binderId) {
                        throw new \RuntimeException(
                            'the variable pattern at line ' . $node->line . ' publishes binder '
                            . \var_export($term->binder, true) . ' for the identity '
                            . \var_export($node->binderId, true),
                        );
                    }
                    if ($term->name !== $node->name) {
                        throw new \RuntimeException(
                            'the variable pattern at line ' . $node->line . ' publishes another name',
                        );
                    }
                }

                if ($expected === PatternShape::Con || $expected === PatternShape::Record) {
                    if ($term->name !== $node->name) {
                        throw new \RuntimeException(
                            'the constructor/record pattern at line ' . $node->line . ' publishes another name',
                        );
                    }
                }

                if ($expected === PatternShape::Lit || $expected === PatternShape::Char) {
                    if ($term->value !== $node->value) {
                        throw new \RuntimeException(
                            'the literal pattern at line ' . $node->line . ' publishes another value',
                        );
                    }
                }

                $children = $childrenOf($node);
                if (\count($term->children) !== \count($children)) {
                    throw new \RuntimeException(
                        'the pattern at line ' . $node->line . ' publishes ' . \count($term->children)
                        . ' sub-patterns for ' . \count($children) . ' children',
                    );
                }
                foreach (\array_values($children) as $index => $child) {
                    if ($term->children[$index] !== $record->terms->idFor($child)) {
                        throw new \RuntimeException(
                            'sub-pattern ' . $index . ' at line ' . $node->line
                            . ' is published under another id than its child names',
                        );
                    }
                    if (! $record->term($term->children[$index]) instanceof CheckedPattern) {
                        throw new \RuntimeException(
                            'sub-pattern ' . $index . ' at line ' . $node->line . ' does not resolve to a pattern',
                        );
                    }
                }

                if ($expected === PatternShape::Record) {
                    $fieldNames = \array_map(static fn (Ast\PatField $f): string => $f->name, $node->fields);
                    if (\array_column($term->fields, 'name') !== $fieldNames) {
                        throw new \RuntimeException(
                            'the record pattern at line ' . $node->line . ' lost a field or its order',
                        );
                    }
                    foreach ($node->fields as $index => $field) {
                        if ($term->fields[$index]['id'] !== $record->terms->idFor($field->pattern)) {
                            throw new \RuntimeException(
                                'field `' . $field->name . '` at line ' . $node->line
                                . ' is published under another id than its pattern names',
                            );
                        }
                    }
                }

                $bound = $namesBoundBy($node);
                if ($term->boundNames() !== $bound) {
                    throw new \RuntimeException(
                        'the pattern at line ' . $node->line . ' publishes bound names '
                        . \json_encode(\array_keys($term->boundNames())) . ' for '
                        . \json_encode(\array_keys($bound)),
                    );
                }

                $patterns[] = $node;
                $shapes[$expected->value] = ($shapes[$expected->value] ?? 0) + 1;
            });
        }
    }

    $required = ['wild', 'var', 'lit', 'char', 'nil', 'cons', 'con', 'tuple', 'record'];
    foreach ($required as $shape) {
        $assert(
            isset($shapes[$shape]),
            "the fixture elaborated no `{$shape}` pattern, so this test says nothing about it —"
                . ' shapes seen: ' . \json_encode($shapes),
        );
    }

    $pristine = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));

    // 2. The terms are the authority: emptying the node's payload leaves the IR identical.
    //    A cons cell's halves are replaced rather than emptied, because their type does not allow
    //    null — and a termless wildcard standing where a real sub-pattern stood is the strongest
    //    form of the question: if lowering read the node, it would have to answer with it.
    foreach ($patterns as $node) {
        match (true) {
            $node instanceof Ast\PatVar => (function () use ($node): void {
                $node->name = '';
                $node->binderId = null;
            })(),
            $node instanceof Ast\PatLit => $node->value = 0,
            $node instanceof Ast\PatChar => $node->value = 0,
            $node instanceof Ast\PatCon => (function () use ($node): void {
                $node->name = '';
                $node->args = [];
            })(),
            $node instanceof Ast\PatTuple => $node->elements = [],
            $node instanceof Ast\PatRecord => (function () use ($node): void {
                $node->name = '';
                $node->fields = [];
            })(),
            $node instanceof Ast\PatCons => (function () use ($node): void {
                $node->head = new Ast\PatWild($node->line, $node->col, $node->endCol);
                $node->tail = new Ast\PatWild($node->line, $node->col, $node->endCol);
            })(),
            default => null,
        };
    }

    $after = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));
    $assert(
        $after === $pristine,
        "lowering reads a pattern node's own payload instead of its checked term:\n"
            . diffText($pristine, $after),
    );

    // 3. A site the record does not cover is refused, for every shape the family covers.
    $refusals = [];
    $refused = static function (Ast\AstNode $node) use ($program, $path, $core): bool {
        $record = Modules\owningRecord($core, $node);
        if ($record === null) {
            return false;
        }
        $saved = $record->terms->termFor($node);
        $record->terms->forget($node);

        try {
            IR\lower($program, $program->interface->data(), $path);
        } catch (\RuntimeException $refusal) {
            if (\str_contains($refusal->getMessage(), 'that its checked-core record does not plan')) {
                return true;
            }

            throw $refusal;
        } finally {
            $record->terms->restore($node, $saved);
        }

        return false;
    };

    foreach ($sites as $node) {
        if (Modules\owningRecord($core, $node) === null) {
            continue;
        }
        $shape = $shapeOf($node)->value;
        $assert(
            $refused($node),
            "lowering accepted an unplanned `{$shape}` pattern at line {$node->line}",
        );
        $refusals[$shape] = ($refusals[$shape] ?? 0) + 1;
    }
    foreach ($required as $shape) {
        $assert(
            ($refusals[$shape] ?? 0) > 0,
            'lowering refused no unplanned ' . $shape . ' pattern; refused: ' . \json_encode($refusals),
        );
    }

    // And nothing under `src/ir/` may build one of these node shapes: a fabricated pattern
    // has no term by construction, so the only way lowering keeps working is to stop
    // inventing them.
    $fabrications = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/ir'));
    foreach ($iterator as $file) {
        if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $body = (string) \file_get_contents($file->getPathname());
        foreach (['PatWild', 'PatVar', 'PatLit', 'PatChar', 'PatNil', 'PatCons', 'PatCon', 'PatTuple', 'PatRecord'] as $family) {
            if (\preg_match('/new Ast\\\\' . $family . '\s*\(/', $body) === 1) {
                $fabrications[] = \str_replace($root . '/', '', $file->getPathname()) . ' builds `' . $family . '`';
            }
        }
    }
    $assert(
        $fabrications === [],
        "lowering may not construct a pattern node after the elaboration is published — the\n"
            . "node would have no term to read:\n  " . \implode("\n  ", $fabrications),
    );

    echo 'checked pattern term tests passed (' . \count($patterns) . ' patterns ('
        . \json_encode($shapes) . '), ' . \count($patterns) . ' payloads scrubbed, '
        . \array_sum($refusals) . " sites refused)\n";
} finally {
    removeDirectory($work);
}
