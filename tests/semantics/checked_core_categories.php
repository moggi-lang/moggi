<?php declare(strict_types=1);

/**
 * The checked core covers every producer, not only ordinary declarations.
 *
 * The four shapes that used to reach lowering without a record are checked here
 * through the product itself:
 *
 * - an **instance method** whose body the source wrote;
 * - a **class-default method**, synthesized from the class's default body
 *   because the instance does not override it;
 * - a **derived member**, synthesized by `deriving (Eq)`;
 * - a **foreign import**, declared as an axiom with a `ForeignCall` body.
 *
 * Each is found the way lowering finds it — by the name the module lowers — and
 * each record is required to be as complete as an ordinary one: a closed scheme,
 * an evidence vector whose slots are the declaration's own dictionary
 * parameters, and a kind for every parameter the scheme binds.
 *
 * The kinds are held against the declarations, not against the record's own
 * claim: a method-level type function is `Type -> Type` because the signature
 * applies it, `s` in `Label s` is `Symbol` because `data Label (s :: Symbol)`
 * says so, and a family-headed signature has to reduce to a closed type instead
 * of leaving its head in the record.
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
use Moggi\Modules\CheckedCore;
use Moggi\Modules\CheckedFunction;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr;
use Moggi\Semantics\Types;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\typeToString;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$source = <<<'MOG'
module Main where

import Data.Functor (Functor)

class Named a where
  name :: a -> String
  name _ = "anonymous"

-- A method-level type function: the `Functor` constraint fixes `f` at kind
-- `Type -> Type`, and the instance's record has to carry that kind.
class Carried t where
  weigh :: Functor f => f a -> t a -> Int
  weigh _ _ = 0

-- A class parameter whose kind the declaration fixes (DataKinds), used through a
-- method-level variable: `s` is `Symbol`, not `Type`.
data SymbolProxy (s :: Symbol) = SymbolProxy

class KnownSymbol (s :: Symbol) where
  symbolVal :: SymbolProxy s -> String

class Tagged t where
  tag :: KnownSymbol s => t -> String
  tag _ = "tag"

-- A class-associated family in a signature.
class Stored t where
  type Element t
  peek :: t -> Element t
  store :: Element t -> t -> t

data Color = Red | Green
  deriving (Eq)

data Wrap a = Wrap a

data Box = Box Int
  deriving (Show)

instance Named Color where

-- A written method whose signature quantifies a type function.
instance Carried Wrap where
  weigh _ (Wrap n) = n

-- The class default is the declaration this instance lowers.
instance Tagged Color where

instance Stored Box where
  type Element Box = Int
  peek (Box n) = n
  store _ b = b

tone :: Color -> String
tone c = name c

same :: Color -> Color -> Bool
same a b = a == b

foreign php function hostLength "strlen" :: String -> Int

main :: IO ()
main = do
  putStrLn (tone Red)
  putStrLn (show (same Red Green))
  putStrLn (show (hostLength "abc"))
  putStrLn (show (peek (Box 9)))
  putStrLn (show (store 1 (Box 2)))
MOG;

$work = createTempDir('moggi-core-categories');
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
    $assert($core instanceof CheckedCore, 'the check published no checked core for `Main`');

    // The kind facts this module declares for the heads its records mention: its
    // datatypes with their promoted constructors, and the associated families of
    // the classes that declare them. Registered by the checker's own routines, so
    // a published kind below is held against a declaration rather than against
    // this file's opinion of what it should be.
    $kindFacts = Types\newState('', '');
    foreach ($prepared['units'] as $unit) {
        Modules\mergeExportedTypes($kindFacts, $unit, $prepared['units']);
    }
    foreach ($prepared['unit']['program']->items as $item) {
        if ($item instanceof Ast\ClassDecl && $item->associatedTypes !== []) {
            Types\registerClassAssociatedTypes($kindFacts, $item, Kinds\inferClassParamKinds($kindFacts, $item));
        }
    }

    /**
     * The record for the method an instance provides, found through the product's
     * own links: the instance's evidence entry names the method it dispatches to.
     * `$evidenceName` is the fragment that names the class and head (`__ev_Eq_Color`).
     */
    $methodRecord = static function (string $evidenceName, string $surface) use ($program, $core): ?CheckedFunction {
        foreach ($program->instanceEvidence as $evidence) {
            if (! \is_array($evidence) || ! \str_contains((string) ($evidence['evidenceName'] ?? ''), $evidenceName)) {
                continue;
            }
            $methodName = $evidence['methods'][$surface] ?? null;
            if (\is_string($methodName)) {
                return $core->function($methodName);
            }
        }

        return null;
    };

    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }

        $record = $core->function($item->name);
        $assert($record !== null, "`Main::{$item->name}` is lowered and has no record");
        $assert(
            $record->scheme->freeTypeVars === [],
            "the record for `Main::{$item->name}` states an open scheme: "
                . \implode(', ', \array_keys($record->scheme->freeTypeVars)),
        );
        $assert(
            \count($record->evidence) === $record->scheme->runtimeConstraintCount,
            "the record for `Main::{$item->name}` publishes " . \count($record->evidence)
                . ' evidence slots for a scheme that takes ' . $record->scheme->runtimeConstraintCount,
        );
        $assert(
            \array_keys($record->parameterKinds) === $record->scheme->bound,
            "the record for `Main::{$item->name}` does not state a kind for every parameter its scheme"
                . ' binds: ' . \json_encode($record->parameterKinds) . ' for ' . \json_encode($record->scheme->bound),
        );
        foreach (Kinds\signatureKindErrors($kindFacts, $record->scheme->type, $record->parameterKinds) as $error) {
            $assert(false, "the record for `Main::{$item->name}` {$error}");
        }
    }

    // A derived member: `deriving (Eq)` synthesizes `==` for `Color`, and the
    // record is the same shape an instance method written by hand gets.
    $derived = $methodRecord('__ev_Eq', '==');
    $assert($derived !== null, 'the derived `Eq Color` method has no record');
    $assert(
        $derived !== null && $derived->scheme->freeTypeVars === [] && $derived->scheme->bound === [],
        'the derived method\'s record states a closed, monomorphic scheme',
    );

    // A class-default method: the instance does not override `name`, so the
    // class's default body is the declaration the module lowers.
    $defaulted = $methodRecord('__ev_Named', 'name');
    $assert($defaulted !== null, 'the class-default method `name` has no record');

    // A method's own type function is `Type -> Type`, because the signature
    // applies it once in a position that has to be a `Type`.
    $weigh = $methodRecord('__ev_Carried', 'weigh');
    $assert($weigh !== null, 'the written instance method `weigh` has no record');
    $assert(
        \in_array('Type -> Type', \array_values($weigh->parameterKinds), true),
        'the record for `weigh` must state the method\'s type function at `Type -> Type`, got: '
            . \json_encode($weigh->parameterKinds),
    );

    // A class parameter's kind is a declaration fact: `KnownSymbol (s :: Symbol)`
    // fixes `s`, so the default's record has to state `Symbol`, not `Type` — and
    // it has to do so on the class-default path, after the class's kind scope is
    // gone.
    $tag = $methodRecord('__ev_Tagged', 'tag');
    $assert($tag !== null, 'the class-default method `tag` has no record');
    $assert(
        \in_array('Symbol', \array_values($tag->parameterKinds), true),
        'the record for `tag` must state `s` at the `Symbol` its constraint fixes, got: '
            . \json_encode($tag->parameterKinds),
    );


    // A family-headed signature is carried as written, with no variable left over
    // for a kind to be wrong about: the head keeps its argument, at `Type`.
    $peek = $methodRecord('__ev_Stored', 'peek');
    $assert($peek !== null, 'the family-headed method `peek` has no record');
    $peekType = typeToString($peek->scheme->type);
    $assert(
        $peek->scheme->bound === [] && \str_contains($peekType, 'Element Box'),
        'the record for `peek` must state its family-headed type closed, got: ' . $peekType
            . ' bound ' . \json_encode($peek->scheme->bound),
    );

    // A family head is answered by its class's declaration, not by the arity the
    // type happens to use: `type Element t` fixes `Element` at `Type -> Type`, so
    // the argument of `Element (Wrap x)` is required at `Type` — a requirement
    // only the family's own kind produces — and publishing `x` as a type function
    // is refused.
    $familyHead = new TypeExpr\TCon('Element', [new TypeExpr\TCon('Wrap', [new TypeExpr\TVar('x')])]);
    $required = Kinds\typeKindRequirements($kindFacts, $familyHead)['vars'];
    $assert(
        isset($required['x']) && Kinds\kindToString($required['x']) === 'Type',
        'the family head `Element` answered nothing about the kind of its argument: '
            . \json_encode(\array_map(Kinds\kindToString(...), $required)),
    );
    $assert(
        Kinds\signatureKindErrors($kindFacts, $familyHead, ['x' => 'Type -> Type']) !== [],
        'a type function published for the argument of a family head was accepted',
    );

    // A foreign import: an axiom with a `ForeignCall` body and no evidence.
    $foreign = null;
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl && $item->foreign) {
            $foreign = $core->function($item->name);
        }
    }
    $assert($foreign !== null, 'the foreign import has no record');
    $assert(
        $foreign !== null && $foreign->scheme->freeTypeVars === [] && $foreign->evidence === [],
        'the foreign import\'s record states a closed scheme and no dictionaries',
    );

    // The foreign call is a term of its own: the host target the checker resolved is
    // the record's, not the node's.
    $foreignSites = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || ! $item->foreign) {
            continue;
        }
        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use (&$foreignSites): void {
            if ($node instanceof Ast\ForeignCall) {
                $foreignSites[] = $node;
            }
        });
    }
    $assert($foreignSites !== [], 'the foreign import has no `ForeignCall` body');
    $foreignTerm = null;
    foreach ($foreignSites as $site) {
        $term = $foreign?->termFor($site);
        $assert(
            $term instanceof Modules\CheckedForeignCall,
            'the foreign call at line ' . $site->line . ' is not published as a term',
        );
        $foreignTerm = $term;
    }
    $assert(
        $foreignTerm !== null
        && $foreignTerm->name === 'hostLength'
        && $foreignTerm->backend === 'php'
        && $foreignTerm->path === 'strlen'
        && $foreignTerm->nativeSig === null,
        'the foreign term does not carry the host target the import resolved: '
            . \json_encode($foreignTerm === null ? null : [
                'name' => $foreignTerm->name,
                'backend' => $foreignTerm->backend,
                'path' => $foreignTerm->path,
                'nativeSig' => $foreignTerm->nativeSig,
            ]),
    );

    // The product is what lowering reads: scrubbing every recorded body's own
    // evidence fields changes no IR, because the plans are the authority.
    $pristine = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));
    $scrubbed = 0;
    $scrubbedForeign = 0;
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $core->function($item->name) === null) {
            continue;
        }
        Modules\walkElaboration($item->body, static function (Ast\AstNode $node) use (&$scrubbed, &$scrubbedForeign): void {
            if ($node instanceof Ast\EvidenceMethod) {
                $node->class = 'Scrubbed';
                $node->evidence = 'scrubbed';
                $node->evidenceInstance = null;
                $node->contextEvidence = [];
                ++$scrubbed;

                return;
            }
            if ($node instanceof Ast\EvidenceRef) {
                $node->class = 'Scrubbed';
                $node->context = [];
                ++$scrubbed;

                return;
            }
            if ($node instanceof Ast\ForeignCall) {
                $node->name = 'scrubbed';
                $node->path = 'scrubbed';
                $node->classPath = 'scrubbed';
                $node->member = 'scrubbed';
                $node->nativeSig = '(scrubbed)scrubbed';
                $node->handleUnboxArgs = [];
                ++$scrubbedForeign;
            }
        });
    }
    $assert($scrubbed > 0, 'the fixture elaborated no evidence site, so the scrub proved nothing');
    $assert(
        $scrubbedForeign > 0,
        'the fixture elaborated no foreign call, so the scrub said nothing about that family',
    );
    $after = IR\Dump\dump(IR\lower($program, $program->interface->data(), $path));
    $assert(
        $after === $pristine,
        "lowering reads the elaborated nodes' evidence fields in a category declaration:\n"
            . diffText($pristine, $after),
    );

    echo "checked core category tests passed ({$scrubbed} sites scrubbed)\n";
} finally {
    removeDirectory($work);
}
