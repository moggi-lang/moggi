<?php declare(strict_types=1);

namespace Moggi\LSP\Analysis;

use Moggi\Syntax\Ast;

/**
 * The child nodes a *source* walk may follow.
 *
 * An AST node carries more than its source children: `Program::$core` holds the checked term tree,
 * which names the same source nodes again, and `inferredType` / `inferredInternalType` hold
 * semantic types. A generic `get_object_vars()` walk that followed those would visit every node
 * twice (counting each occurrence twice — the duplicate-occurrence bug), or wander into a type
 * graph that has no source positions. Type nodes are shared and non-positional, so they are not
 * source children either.
 *
 * Every LSP walk that follows the tree goes through here, so a new non-structural property on
 * `AstNode` is skipped once rather than remembered by six walkers.
 *
 * @return \Generator<int, object>
 */
function sourceChildren(object $node): \Generator
{
    foreach (\get_object_vars($node) as $key => $prop) {
        if ($key === 'core' || $key === 'inferredInternalType' || $prop instanceof Ast\TypeNode) {
            continue;
        }
        if (\is_object($prop)) {
            yield $prop;

            continue;
        }
        if (\is_array($prop)) {
            foreach ($prop as $elem) {
                if (\is_object($elem) && ! $elem instanceof Ast\TypeNode) {
                    yield $elem;
                }
            }
        }
    }
}
