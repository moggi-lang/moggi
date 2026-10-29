<?php declare(strict_types=1);

/**
 * Records, literals and bare guard chains own their shape in the checked core, and
 * lowering cannot lower one it was not given.
 *
 * These three families were the last expression shapes lowered straight from the AST:
 * `lowerRecordCon()`/`lowerRecordUpdate()` read `RecordCon::$name`, `RecordUpdate::$constructor`,
 * `::$fieldOnNewtype` and both `$fields` lists; the four literal arms read `$expr->value` (and the
 * big-integer digits were read by nobody); `lowerGuards()` read `GuardsExpr::$clauses` and asked the
 * node whether its last guard was a constant `True`. Each is a term now
 * ({@see \Moggi\Modules\CheckedRecord}, {@see \Moggi\Modules\CheckedLiteral},
 * {@see \Moggi\Modules\CheckedGuards}), and this test pins the three consequences:
 *
 * 1. every site of the three families in a real program is published with the facts the node holds
 *    — constructor, field labels and their value nodes in source order, the receiver and the
 *    newtype flag of an update, a literal's shape/value/digits, a guard chain's clauses;
 * 2. lowering reads the term and not the node: emptying every record's fields, renaming its
 *    constructor, flipping the newtype flag, replacing an update's receiver with a termless literal,
 *    rewriting every literal's value and emptying every guard chain leaves the IR byte-identical;
 * 3. a site the record does not cover is refused, and nothing under `src/ir` builds one of these
 *    node shapes — a fabricated one has no term by construction.
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
use Moggi\Modules\CheckedGuards;
use Moggi\Modules\CheckedLiteral;
use Moggi\Modules\CheckedRecord;
use Moggi\Modules\LiteralShape;
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

data Point = Point { x :: Int, y :: Int }

newtype Wrap = Wrap { unwrapped :: Int }

-- A full construction and one that names its fields out of order.
mkPoint :: Point
mkPoint = Point { y = 2, x = 1 }

-- An update that keeps a field (a read of the receiver) and one that replaces both.
shiftX :: Point -> Point
shiftX p = p { x = 5 }

bothShift :: Point -> Point
bothShift p = p { x = 7, y = 8 }

-- A `newtype` update: the field is the value, not a constructor call over the receiver.
rewrap :: Wrap -> Wrap
rewrap w = w { unwrapped = 9 }

readWrap :: Wrap -> Int
readWrap w =
  case w of
    Wrap { unwrapped = n } -> n

describe :: Point -> String
describe p = "point " <> show p.x <> "," <> show p.y

-- A guard chain as a declaration body: the bare family, not a `case` arm.
sign :: Int -> String
sign n
  | n > 0 = "pos"
  | n == 0 = "zero"
  | otherwise = "neg"

-- A guard chain whose last clause is not `otherwise`, so the match is not total.
onlyOne :: Int -> String
onlyOne n
  | n == 1 = "one"

charName :: Char -> String
charName c
  | c == 'c' = "c"
  | otherwise = "other"

main :: IO ()
main = do
  putStrLn (describe mkPoint)
  putStrLn (describe (shiftX mkPoint))
  putStrLn (describe (bothShift mkPoint))
  putStrLn (show (readWrap (rewrap (Wrap { unwrapped = 1 }))))
  putStrLn (sign 3)
  putStrLn (sign 0)
  putStrLn (sign (-3))
  putStrLn (onlyOne 1)
  putStrLn (charName 'c')
  putStrLn (show (1 :: Int))
  putStrLn (show (-2 :: Int))
  putStrLn (show (3.5 :: Double))
  putStrLn "text"
  -- A big integer that does not fit the host `int`, so its digits are what the term keeps.
  putStrLn (show (123456789012345678901234567890 :: Integer))
MOG;

$work = createTempDir('moggi-checked-record-literal-guards');
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

    $records = [];
    $updates = [];
    $literals = [];
    $guards = [];
    $shapes = [];
    $newtypeUpdates = 0;

    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }

        $record = $core->function($item->name);
        $assert($record !== null, "the core records no `{$item->name}`");

        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use (
            $item,
            $record,
            &$records,
            &$updates,
            &$literals,
            &$guards,
            &$shapes,
            &$newtypeUpdates,
        ): void {
            if ($node instanceof Ast\RecordCon || $node instanceof Ast\RecordUpdate) {
                $term = $node->checkedTerm === null ? null : $record->term($node->checkedTerm);
                if (! $term instanceof CheckedRecord) {
                    throw new \RuntimeException(
                        'the record at line ' . $node->line . ' of `' . $item->name
                        . '` is not published as a checked term',
                    );
                }

                $constructor = $node instanceof Ast\RecordCon ? $node->name : $node->constructor;
                if ($constructor === null || $term->constructor !== $constructor) {
                    throw new \RuntimeException(
                        'the record at line ' . $node->line . ' publishes constructor `'
                        . $term->constructor . '` for `' . $constructor . '`',
                    );
                }

                $expectedFields = \array_map(
                    static fn (Ast\RecordField $field): array => [$field->name, $field->expr],
                    $node->fields,
                );
                $actualFields = \array_map(
                    static fn ($field): array => [$field->name, $field->value],
                    $term->fields,
                );
                if ($actualFields != $expectedFields) {
                    throw new \RuntimeException(
                        'the record at line ' . $node->line . ' publishes another field list or order',
                    );
                }

                if ($node instanceof Ast\RecordCon) {
                    if ($term->isUpdate()) {
                        throw new \RuntimeException(
                            'the construction at line ' . $node->line . ' publishes a receiver',
                        );
                    }
                    $records[] = $node;

                    return;
                }

                if ($term->receiver !== $node->object) {
                    throw new \RuntimeException(
                        'the update at line ' . $node->line . ' publishes another receiver',
                    );
                }
                if ($term->fieldOnNewtype !== $node->fieldOnNewtype) {
                    throw new \RuntimeException(
                        'the update at line ' . $node->line . ' publishes another newtype flag',
                    );
                }
                if ($node->fieldOnNewtype) {
                    ++$newtypeUpdates;
                }
                $updates[] = $node;

                return;
            }

            if (Modules\isLiteralNode($node)) {
                $term = $node->checkedTerm === null ? null : $record->term($node->checkedTerm);
                if (! $term instanceof CheckedLiteral) {
                    throw new \RuntimeException(
                        'the literal at line ' . $node->line . ' of `' . $item->name
                        . '` is not published as a checked term',
                    );
                }
                if ($term->shape !== Modules\literalShape($node)) {
                    throw new \RuntimeException(
                        'the literal at line ' . $node->line . ' publishes shape `' . $term->shape->value
                        . '` for a `' . $node::class . '`',
                    );
                }
                if ($term->value !== $node->value) {
                    throw new \RuntimeException(
                        'the literal at line ' . $node->line . ' publishes another value',
                    );
                }
                if ($term->digits !== ($node instanceof Ast\IntegerLit ? $node->digits : null)) {
                    throw new \RuntimeException(
                        'the literal at line ' . $node->line . ' publishes other digits',
                    );
                }

                $literals[] = $node;
                $shapes[$term->shape->value] = ($shapes[$term->shape->value] ?? 0) + 1;
                if ($term->digits !== null) {
                    $shapes['big-int'] = ($shapes['big-int'] ?? 0) + 1;
                }

                return;
            }

            if ($node instanceof Ast\GuardsExpr) {
                $term = $node->checkedTerm === null ? null : $record->term($node->checkedTerm);
                if (! $term instanceof CheckedGuards) {
                    throw new \RuntimeException(
                        'the guard chain at line ' . $node->line . ' of `' . $item->name
                        . '` is not published as a checked term',
                    );
                }
                if ($term->clauses !== $node->clauses) {
                    throw new \RuntimeException(
                        'the guard chain at line ' . $node->line . ' publishes other clauses',
                    );
                }

                $guards[] = $node;
            }
        });
    }

    $required = ['record', 'update', 'newtype-update', 'int', 'double', 'string', 'char', 'guards'];
    $seen = [
        'record' => \count($records),
        'update' => \count($updates),
        'newtype-update' => $newtypeUpdates,
        'guards' => \count($guards),
        'int' => $shapes[LiteralShape::Int->value] ?? 0,
        'double' => $shapes[LiteralShape::Double->value] ?? 0,
        'string' => $shapes[LiteralShape::Str->value] ?? 0,
        'char' => $shapes[LiteralShape::Char->value] ?? 0,
        'big-int' => $shapes['big-int'] ?? 0,
    ];
    foreach ($required as $shape) {
        $assert(
            $seen[$shape] > 0,
            "the fixture elaborated no `{$shape}`, so this test says nothing about it — seen: "
                . \json_encode($seen),
        );
    }

    // The digits half needs an integer literal the host `int` cannot hold, and this fixture's form
    // lets the checker rewrite the literal before publication. The repository's own literal fixture
    // supplies one, so the digits check is not vacuous.
    $literalsPath = $root . '/tests/backend/runtime/Literals.mog';
    $bigContext = CompilationContext::for(
        'php',
        CompilePurpose::Executable,
        entryModules: ['Literals'],
        roots: [\dirname($literalsPath)],
        sources: [$literalsPath],
    );
    $bigProgram = Modules\moduleCompileContext($literalsPath, $bigContext, null)['typedProgram'];
    $withDigits = 0;
    foreach ($bigProgram->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }
        $bigRecord = $bigProgram->core->function($item->name);
        $assert($bigRecord !== null, "the core records no `{$item->name}` in the literal fixture");
        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use ($bigRecord, &$withDigits): void {
            if (! Modules\isLiteralNode($node)) {
                return;
            }
            $term = $node->checkedTerm === null ? null : $bigRecord->term($node->checkedTerm);
            if (! $term instanceof CheckedLiteral) {
                throw new \RuntimeException(
                    'the literal at line ' . $node->line . ' of the literal fixture is not a term',
                );
            }
            if ($node instanceof Ast\IntegerLit && $node->digits !== null) {
                if ($term->digits !== $node->digits) {
                    throw new \RuntimeException(
                        'the big integer at line ' . $node->line . ' publishes other digits',
                    );
                }
                ++$withDigits;
            }
        });
    }
    $assert(
        $withDigits > 0,
        'no literal in the repository fixture kept its digits, so their publication is untested',
    );

    $pristine = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));

    // 2. The terms are the authority: emptying the node's payload leaves the IR identical.
    foreach ($records as $node) {
        $node->name = '';
        $node->fields = [];
    }
    foreach ($updates as $node) {
        $node->constructor = null;
        $node->fields = [];
        $node->fieldOnNewtype = ! $node->fieldOnNewtype;
        $node->object = new Ast\IntegerLit(0, $node->line, $node->col, $node->endCol);
    }
    foreach ($literals as $node) {
        match (true) {
            $node instanceof Ast\IntegerLit => $node->value = 0,
            $node instanceof Ast\DoubleLit => $node->value = 1.0,
            $node instanceof Ast\StringLit => $node->value = '',
            $node instanceof Ast\CharLit => $node->value = 0,
            default => null,
        };
        if ($node instanceof Ast\IntegerLit) {
            $node->digits = null;
        }
    }
    foreach ($guards as $node) {
        $node->clauses = [];
    }

    $after = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));
    $assert(
        $after === $pristine,
        "lowering reads a record, literal or guard node's own payload instead of its checked term:\n"
            . diffText($pristine, $after),
    );

    // 3. A site the record does not cover is refused, for every family.
    $sites = ['construction' => 0, 'update' => 0, 'literal' => 0, 'guards' => 0];
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

    foreach (['construction' => $records, 'update' => $updates, 'literal' => $literals, 'guards' => $guards] as $name => $family) {
        foreach ($family as $node) {
            if ($node->checkedTerm === null) {
                continue;
            }
            $assert(
                $refused($node),
                "lowering accepted an unplanned `{$name}` at line {$node->line}",
            );
            $sites[$name] = ($sites[$name] ?? 0) + 1;
        }
    }
    foreach ($sites as $name => $count) {
        $assert($count > 0, "no unplanned `{$name}` site was refused; refused: " . \json_encode($sites));
    }

    // And nothing under `src/ir/` may build one of these node shapes.
    $fabrications = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/ir'));
    foreach ($iterator as $file) {
        if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $body = (string) \file_get_contents($file->getPathname());
        foreach (['IntegerLit', 'DoubleLit', 'StringLit', 'CharLit', 'RecordCon', 'RecordUpdate', 'GuardsExpr'] as $family) {
            if (\preg_match('/new Ast\\\\' . $family . '\s*\(/', $body) === 1) {
                $fabrications[] = \str_replace($root . '/', '', $file->getPathname()) . ' builds `' . $family . '`';
            }
        }
    }
    $assert(
        $fabrications === [],
        "lowering may not construct a record, literal or guard node after the elaboration is\n"
            . "published — the node would have no term to read:\n  " . \implode("\n  ", $fabrications),
    );

    echo 'checked record/literal/guard term tests passed (' . \count($records) . ' constructions, '
        . \count($updates) . ' updates (' . $newtypeUpdates . ' newtype), ' . \count($literals)
        . ' literals (' . \json_encode($shapes) . ', ' . $withDigits . ' with digits), '
        . \count($guards) . ' guard chains, '
        . (\count($records) + \count($updates) + \count($literals) + \count($guards))
        . ' payloads scrubbed, ' . \array_sum($sites) . " sites refused)\n";
} finally {
    removeDirectory($work);
}
