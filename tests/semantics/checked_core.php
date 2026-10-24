<?php declare(strict_types=1);

/**
 * The checked core, end to end, for the one category it covers: an ordinary
 * function that spells its own signature and does not call itself.
 *
 * The module below is compiled the way a project compiles it — same prepare, same
 * checker — and the test then reads only the *closed product* the check
 * published: the record per declaration, its scheme, its evidence vector (with
 * the wanted obligation each slot discharges), the residual a numeric default
 * decided and its provenance, the term→type table, and the origin that names the
 * declaration for tooling.
 *
 * Facts pinned beyond the record's contents:
 *
 * - the product is what lowering reads. Replacing a record's evidence binder
 *   changes the emitted IR's evidence scope, so the record — not the mutable
 *   `FunctionDecl::$constraints` — is the authority for the category;
 * - the body's evidence applications are the record's too. Every evidence site
 *   in a recorded declaration's body is planned, and scrubbing the sites' own
 *   `evidence`/`evidenceInstance`/`contextEvidence` fields leaves the emitted IR
 *   byte-identical: lowering reads the plans, never the nodes;
 * - the product survives the module product's own encoding. It has to: a module
 *   read back from `cache/modulePut('checked')` lowers from the same record;
 * - lowering a category function with no record is refused, never re-derived
 *   from the surface tree;
 * - a same-named instance method does not take over the exported scheme of a
 *   declaration the record owns (`Data.Map.null` beside `Foldable.null`).
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\Modules;
use Moggi\Modules\CheckedCore;
use Moggi\Modules\CheckedFunction;
use Moggi\Modules\EvidencePlanKind;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types;
use Moggi\Syntax\Ast;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$source = <<<'MOG'
module Main (twice, shown, both, projected, bigness, bound, chosen, listed, countdown, main) where

class Tagged a where
  tag :: a -> Int

class Tagged a => Labelled a where
  label :: a -> Int

instance Tagged Int where
  tag n = n

instance Labelled Int where
  label n = tag n

-- The category: annotated, ordinary, not recursive.
twice :: Tagged a => a -> Int
twice x = tag x

shown :: String
shown = show 1

-- A superclass obligation reaches the vector as a slot of its own, and each
-- method site inside the infix moves to the dictionary that owns it.
both :: Labelled a => a -> Int
both x = tag x + label x

-- A class method selected by the operand's instance is projected, so this
-- declaration's body carries a projection site, not only parameter sites.
projected :: Int
projected = tag 1

-- Evidence sites inside a `let` binding list and inside a case alternative: both
-- are list-valued children, which is where a walk that takes only node-valued
-- properties stops.
bound :: Tagged a => a -> Int
bound x = let y = tag x in y + tag x

chosen :: Int -> Int
chosen n = case n of
  0 -> tag 1
  _ -> tag n

-- A dictionary the checker passes as an *argument* to a constrained function
-- (`lookup`'s `Eq a`): a reference to the instance dictionary, not a projection
-- of a method out of it.
listed :: Int -> [(Int, String)] -> Maybe String
listed n xs = lookup n xs

-- The aliasing shape: an instance method with the name of an exported,
-- annotated declaration leaves *its* scheme under that name in `env`.
class Peek f where
  bigness :: f a -> Int

data Box a = Box a

instance Peek Box where
  bigness box = 7

bigness :: Box Int -> Int
bigness box = 9

-- Not the category: inferred, and recursive with a signature.
inferred x = twice x

countdown :: Int -> Int
countdown n = countdown n

main :: IO ()
main = do
  putStrLn (show (twice 21))
  putStrLn shown
MOG;

$work = \sys_get_temp_dir() . '/moggi-checked-core-' . \bin2hex(\random_bytes(6));
\mkdir($work, 0777, true);
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

    $check($core instanceof CheckedCore, 'the check published no checked core for `Main`');
    $check(
        $core !== null && $core->module === 'Main',
        'the checked core names the module it was built for',
    );

    $names = $core === null ? [] : \array_keys($core->functions);
    \sort($names);
    $check(
        $names === ['bigness', 'both', 'bound', 'chosen', 'listed', 'main', 'projected', 'shown', 'twice'],
        'the category is annotated nonrecursive ordinary functions, got: ' . \implode(', ', $names),
    );
    $check(
        $core?->function('inferred') === null,
        'an inferred declaration is not in the pilot category',
    );
    $check(
        $core?->function('countdown') === null,
        'a recursive declaration is not in the pilot category',
    );

    $twice = $core?->function('twice');
    $check($twice !== null, '`twice` has no checked record');

    if ($twice !== null) {
        $check(
            Types\typeToString($twice->scheme->type) === 'a -> Int',
            'the record publishes the checked, generalized scheme, got: '
                . Types\typeToString($twice->scheme->type),
        );
        $check(
            $twice->scheme->freeTypeVars === [],
            'the boundary publishes no scheme with an uncommitted variable',
        );

        $evidence = $twice->evidence;
        $check(\count($evidence) === 1, '`twice` carries one dictionary parameter, got: ' . \count($evidence));
        $check(
            ($evidence[0]['class'] ?? '') === 'Tagged' && ($evidence[0]['wanted'] ?? '') === 'Tagged(a)',
            'the slot names the wanted obligation it discharges, got: ' . ($evidence[0]['wanted'] ?? 'none'),
        );
        $check(
            ($evidence[0]['origin'] ?? '') === $twice->origin,
            'the slot names the origin it belongs to, got: ' . ($evidence[0]['origin'] ?? 'none'),
        );
        $check(
            ($evidence[0]['evidence'] ?? '') === $twice->evidenceBinders()[0],
            'the binder list is the vector, in positional ABI order',
        );
        $declLine = 0;
        foreach (\explode("\n", $source) as $index => $lineText) {
            if (\str_starts_with($lineText, 'twice ::')) {
                $declLine = $index + 1;
            }
        }
        $check(
            $twice->origin === 'Main:' . $declLine . ':1',
            'the record names its declaration by origin, got: ' . $twice->origin,
        );

        $check($twice->terms !== [], 'the elaboration published no term types');
        $termsWellFormed = true;
        $ownResult = false;
        foreach ($twice->terms as $term) {
            if (\preg_match('/^[^:]+:\d+:\d+:\d+$/', $term['origin']) !== 1) {
                $termsWellFormed = false;
            }
            if ($term['type'] instanceof Type && Types\typeToString($term['type']) === 'Int') {
                $ownResult = true;
            }
        }
        $check($termsWellFormed, 'every term entry is named by a source origin');
        $check($ownResult, 'the table states the result type of the body');
    }

    $both = $core?->function('both');
    $check($both !== null, '`both` has no checked record');
    if ($both !== null) {
        $classes = [];
        $paired = true;
        foreach ($both->evidence as $slot) {
            $classes[] = $slot['class'];
            $paired = $paired && \str_starts_with($slot['wanted'], $slot['class'] . '(') && $slot['evidence'] !== '';
        }
        $check(
            \count($both->evidence) === 2,
            'a `Labelled a` declaration takes its own dictionary and its superclass\'s, got: '
                . \count($both->evidence),
        );
        $check(
            $classes === ['Tagged', 'Labelled'],
            'the vector is the solved dictionary order, superclass first, got: ' . \implode(', ', $classes),
        );
        $check($paired, 'every slot pairs its wanted obligation with the given dictionary that discharges it');
    }

    $shown = $core?->function('shown');
    $check($shown !== null, '`shown` has no checked record');
    if ($shown !== null) {
        $check(
            \count($shown->residuals) === 1,
            'the numeric default `shown` needed is a residual, got: ' . \count($shown->residuals),
        );
        $residual = $shown->residuals[0] ?? [];
        $check(
            ($residual['decision'] ?? '') === 'Int',
            'the residual states the type the variable was defaulted to, got: ' . ($residual['decision'] ?? 'none'),
        );
        $check(
            \str_contains($residual['wanted'] ?? '', 'Num('),
            'the residual names the obligation it settled, got: ' . ($residual['wanted'] ?? 'none'),
        );
        $check(
            \str_contains($residual['provenance'] ?? '', 'numeric defaulting'),
            'the residual states why it was settled that way, got: ' . ($residual['provenance'] ?? 'none'),
        );
    }

    // One consumer: lowering reads the record's evidence vector.
    $ir = IR\lower($program, $program->interface->data, $path);
    $irTwice = null;
    foreach ($ir->functions as $function) {
        if ($function->name === 'twice') {
            $irTwice = $function;
        }
    }
    $check($irTwice !== null, 'lowering produced no `twice`');
    if ($irTwice !== null && $twice !== null) {
        $check(
            $irTwice->evidenceScope === $twice->evidenceBinders(),
            'the IR evidence scope is the record vector, got: ' . \implode(', ', $irTwice->evidenceScope),
        );
    }

    // The record is the authority: substituting its binder changes the IR.
    if ($twice !== null) {
        $substituted = $twice->withEvidence([[
            'wanted' => 'Tagged(a)',
            'class' => 'Tagged',
            'evidence' => 'evidence_for_the_test',
            'origin' => $twice->origin,
        ]]);
        $substitutedCore = new CheckedCore('Main', ['twice' => $substituted] + $core->functions);
        $program->core = $substitutedCore;
        $substitutedIr = IR\lower($program, $program->interface->data, $path);
        $substitutedTwice = null;
        foreach ($substitutedIr->functions as $function) {
            if ($function->name === 'twice') {
                $substitutedTwice = $function;
            }
        }
        $check(
            $substitutedTwice !== null && $substitutedTwice->evidenceScope === ['evidence_for_the_test'],
            'lowering re-derived the evidence vector instead of reading the record',
        );
        $program->core = $core;
    }

    // The body's evidence applications are the record's as well. Every evidence
    // site in a recorded declaration's body has a plan, the plan names the
    // site's source origin, and scrubbing the site's own fields cannot change
    // the emitted IR — lowering reads the record, not the elaborated node.
    $sites = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $core?->function($item->name) === null) {
            continue;
        }
        $found = [];
        Modules\walkElaboration($item->body, static function ($node) use (&$found): void {
            if ($node instanceof Ast\EvidenceMethod || $node instanceof Ast\EvidenceRef) {
                $found[] = $node;
            }
        });
        $sites[$item->name] = $found;
    }

    $check(
        isset($sites['both']) && \count($sites['both']) === 2,
        'the fixture no longer states two evidence sites in `both`, got: ' . \count($sites['both'] ?? []),
    );
    $check(
        isset($sites['projected']) && \count($sites['projected']) === 1,
        'the fixture no longer states a projection site in `projected`, got: ' . \count($sites['projected'] ?? []),
    );
    $check(
        isset($sites['bound']) && \count($sites['bound']) === 2,
        'the fixture no longer states two sites in a `let` body, got: ' . \count($sites['bound'] ?? []),
    );
    $check(
        isset($sites['chosen']) && \count($sites['chosen']) === 2,
        'the fixture no longer states two sites in case alternatives, got: ' . \count($sites['chosen'] ?? []),
    );
    $check(
        isset($sites['listed']) && \count($sites['listed']) === 1
        && $sites['listed'][0] instanceof Ast\EvidenceRef,
        'the fixture no longer states a reference site in `listed`, got: '
            . \count($sites['listed'] ?? []) . ' site(s)',
    );

    foreach ($sites as $name => $declarationSites) {
        $record = $core?->function($name);
        $check(
            $record !== null && \count($record->bodyEvidence) === \count($declarationSites),
            "`{$name}` does not plan every evidence site its body carries: "
                . \count($record?->bodyEvidence ?? []) . ' plan(s) for ' . \count($declarationSites) . ' site(s)',
        );

        $bySite = $record?->evidencePlanIndex() ?? [];
        $expected = [];
        foreach ($declarationSites as $site) {
            $check(
                isset($bySite[\spl_object_id($site)]),
                "`{$name}` leaves the evidence site at line {$site->line} unplanned",
            );
            $expected[] = CheckedFunction::termOrigin($site);
        }

        $planOrigins = \array_map(static fn ($plan): string => $plan->origin, $record?->bodyEvidence ?? []);
        \sort($planOrigins);
        \sort($expected);
        $check(
            $planOrigins === $expected,
            "`{$name}` plans do not carry their sites' source origins, got: " . \implode(', ', $planOrigins),
        );
    }

    // The two shapes are planned differently: a superclass obligation is a
    // parameter of the declaration, an instance-selected method is a projection
    // of the instance's builder.
    $bothPlans = $core?->function('both')?->bodyEvidence ?? [];
    $bothKinds = \array_map(static fn ($plan): string => $plan->kind->name, $bothPlans);
    $check(
        $bothKinds === ['Parameter', 'Parameter'],
        'a declaration taking its dictionaries as parameters plans them as parameters, got: '
            . \implode(', ', $bothKinds),
    );

    $listedPlan = $core?->function('listed')?->bodyEvidence[0] ?? null;
    $check(
        $listedPlan !== null && $listedPlan->kind === EvidencePlanKind::Reference,
        'a dictionary passed to a constrained function is planned as a reference, got: '
            . ($listedPlan === null ? 'no plan' : $listedPlan->kind->name),
    );
    $check(
        $listedPlan !== null
        && $listedPlan->class === 'Eq'
        && $listedPlan->builder !== null
        && $listedPlan->builderHead !== null
        && $listedPlan->parameter === null,
        'the reference names its class and builder and takes no parameter, got: '
            . ($listedPlan === null ? 'no plan' : \var_export([$listedPlan->class, $listedPlan->builder], true)),
    );

    $projectedSite = $sites['projected'][0] ?? null;
    $projectedPlan = $projectedSite === null
        ? null
        : ($core?->function('projected')?->evidencePlanIndex()[\spl_object_id($projectedSite)] ?? null);
    $check(
        $projectedPlan !== null && $projectedPlan->kind === EvidencePlanKind::Projection,
        'an instance-selected method is planned as a projection, got: '
            . ($projectedPlan === null ? 'no plan' : $projectedPlan->kind->name),
    );
    $check(
        $projectedPlan !== null
        && $projectedPlan->class === 'Tagged'
        && $projectedPlan->builder !== null
        && $projectedPlan->builderHead !== null,
        'the projection names the class, the builder and the head it was derived from, got: '
            . ($projectedPlan === null ? 'no plan' : \var_export([$projectedPlan->class, $projectedPlan->builder], true)),
    );

    // The record's scheme is not taken over by a same-named instance method.
    $aliased = $program->exportedInferredSchemes['bigness'] ?? null;
    $check(
        $aliased === null,
        'the exported inferred scheme map published `bigness`, whose own scheme the record holds: '
            . ($aliased === null ? 'none' : Types\schemeToString($aliased)),
    );
    $check(
        ($core?->function('bigness')?->scheme->type instanceof Type)
        && Types\typeToString($core->function('bigness')->scheme->type) === 'Box Int -> Int',
        'the record owns the annotated declaration\'s scheme, got: '
            . ($core?->function('bigness') === null ? 'no record' : Types\typeToString($core->function('bigness')->scheme->type)),
    );

    // The product travels with the module product: a cache read lowers from it.
    $round = \unserialize(\serialize($program));
    $roundCore = $round instanceof Ast\Program ? $round->core : null;
    $check($roundCore instanceof CheckedCore, 'the record does not survive the module product encoding');
    $roundTwice = $roundCore?->function('twice');
    $check(
        $roundTwice !== null && $roundTwice->evidenceBinders() === $twice?->evidenceBinders(),
        'the record read back from its encoding lost its evidence vector',
    );

    // The scrub that proves it lives last: it mutates the elaborated tree, and
    // everything above must have run on the tree the checker handed over.
    $pristine = IR\Dump\dump($ir);
    $check(
        \str_contains($pristine, '__ev_Eq_Int'),
        "the reference plan did not reach the emitted IR as a dictionary argument, `listed` lowered as:\n"
            . \implode("\n", \array_filter(
                \explode("\n", $pristine),
                static fn (string $line): bool => \str_contains($line, 'lookup') || \str_contains($line, 'listed'),
            )),
    );

    // The scrub drops every fact lowering is not allowed to read: the vector, the
    // instance it came from, the site's own context *and* its class name (which is
    // what a re-derived builder symbol would be computed from).
    foreach ($sites as $declarationSites) {
        foreach ($declarationSites as $site) {
            $site->class = 'Scrubbed';

            if ($site instanceof Ast\EvidenceMethod) {
                $site->evidence = 'scrubbed';
                $site->evidenceInstance = null;
                $site->contextEvidence = [];

                continue;
            }

            $site->context = [];
        }
    }
    $scrubbedIr = IR\lower($program, $program->interface->data, $path);
    $check(
        IR\Dump\dump($scrubbedIr) === $pristine,
        'lowering still reads the elaborated node\'s evidence fields: scrubbing them changed the IR',
    );

    // No fallback: a category declaration without a record is refused.
    $program->core = null;
    $refused = '';
    try {
        IR\lower($program, $program->interface->data, $path);
    } catch (\RuntimeException $error) {
        $refused = $error->getMessage();
    }
    $check(
        \str_contains($refused, 'Main::twice') && \str_contains($refused, 'checked-core record'),
        'lowering a category function without its record must be refused by name, got: ' . $refused,
    );

    if ($failures !== []) {
        throw new \RuntimeException(
            \count($failures) . " checked-core failure(s):\n  " . \implode("\n  ", $failures),
        );
    }

    echo "checked core tests passed\n";
} finally {
    foreach (\glob($work . '/*') ?: [] as $file) {
        \unlink($file);
    }
    \rmdir($work);
}
