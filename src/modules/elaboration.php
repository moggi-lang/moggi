<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\inferredTypeAstToInternal;

/**
 * Every node of one elaboration, once, in pre-order.
 *
 * The checked core's two derivations — the term→type table and the body's
 * evidence plans — have to see the same terms, or a fact one publishes is
 * missing from the other. So they share this walk rather than each walking
 * `childValues()` itself: a node's children include lists (`Apply` args, tuple
 * elements, `CaseExpr` alternatives, let bindings), and a walk that only
 * descends into node-valued properties silently stops at every list. That is
 * exactly how an elaborated `a + b` (`IntrinsicCall` whose args are the two
 * method sites) lost its evidence plans while its term types were expected.
 *
 * Identity is the node object: a subtree shared twice is one term, so the walk
 * visits it once. Only nodes are deduplicated — the value objects beside them
 * (`Alt`, `PatField`, …) carry no facts of their own here.
 *
 * @param callable(Ast\AstNode): void $visit
 */
function walkElaboration(Ast\AstWalkable $root, callable $visit): void
{
    $seen = [];

    $walk = function (Ast\AstWalkable $value) use (&$walk, &$seen, $visit): void {
        if ($value instanceof Ast\AstNode) {
            $id = \spl_object_id($value);
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;

            $visit($value);
        }

        foreach ($value->childValues() as $child) {
            foreach (\is_array($child) ? $child : [$child] as $entry) {
                if ($entry instanceof Ast\AstWalkable) {
                    $walk($entry);
                }
            }
        }
    };

    $walk($root);
}

/**
 * The elaboration's term→type table.
 *
 * The types come from the checker's own zonked annotations — the one place it
 * publishes a term's type today — so this conversion is the pilot's registered
 * read of the surface tree (adapter 3 in `migrations.md`); when inference hands
 * the internal type over directly, it is deleted.
 *
 * Only terms that have a source origin are published. A node the checker
 * synthesized (a desugared `do`, an elaborated literal, an inserted dictionary
 * application) has no span and therefore no origin a tool could point at; its
 * type stays inside the elaboration instead of being given a made-up key.
 *
 * @return list<array{origin: string, type: Type}>
 */
function termTypesFor(Ast\FunctionDecl $fn): array
{
    $terms = [];

    $visit = static function (Ast\AstNode $node) use (&$terms): void {
        if ($node->inferredType !== null && $node->line > 0) {
            $terms[] = [
                'origin' => CheckedFunction::termOrigin($node),
                'type' => inferredTypeAstToInternal($node->inferredType),
            ];
        }
    };

    foreach ($fn->params as $param) {
        walkElaboration($param, $visit);
    }
    walkElaboration($fn->body, $visit);

    return $terms;
}
