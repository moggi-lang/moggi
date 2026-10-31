<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Syntax\Ast;

/**
 * One node of the checked core's structural tree.
 *
 * The checked terms in {@see CheckedTerm} publish decisions; this family publishes the
 * arrangement those decisions are written over. A node owns its class, its scalar payload and
 * references to child structures by their own ids — never the source node it was published
 * from. Lowering materializes its view from this tree, so a scrubbed or discarded source AST
 * cannot change what is lowered.
 *
 * A structure is generic on purpose: the elaborated AST has many leaves and containers whose
 * arrangement is not a decision (tuples, lists, field access, type ascription, do blocks), and
 * giving each one a bespoke term would duplicate the node classes without adding a fact. The
 * classes that do publish decisions keep their typed terms; both families share one declaration
 * id space and one site map.
 */
final class CheckedStructure
{
    /**
     * @param string $kind the elaborated node/value class this structure reconstructs
     * @param array<string, mixed> $payload public properties, with every child replaced by
     *   `['__checked_ref' => id]` and arrays encoded recursively
     */
    public function __construct(
        public readonly TermId $id,
        public readonly string $origin,
        public readonly string $kind,
        public readonly array $payload,
    ) {
    }

    /**
     * The inferred-type projection is tooling state, not structure. Omitting it here keeps the
     * structural tree from duplicating the checker's type graphs in the module cache; lowering
     * materializes the view and never reads those fields (F1's scrub tests pin that).
     */
    public function __serialize(): array
    {
        $payload = $this->payload;
        unset($payload['inferredType'], $payload['inferredInternalType']);
        if ($this->kind === Ast\EvidenceRef::class) {
            unset($payload['class'], $payload['context']);
        } elseif ($this->kind === Ast\EvidenceMethod::class) {
            unset($payload['class'], $payload['evidence'], $payload['evidenceInstance'], $payload['contextEvidence']);
        }

        return [
            'id' => $this->id,
            'origin' => $this->origin,
            'kind' => $this->kind,
            'payload' => $payload,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->origin = $data['origin'];
        $this->kind = $data['kind'];
        $this->payload = $data['payload'];
    }
}

/**
 * Encode one property value, replacing every walkable child with its structure id.
 *
 * @param callable(Ast\AstWalkable): int $walk
 */
function encodeStructureValue(mixed $value, callable $walk): mixed
{
    if ($value instanceof Ast\AstWalkable) {
        return ['__checked_ref' => $walk($value)];
    }

    if (\is_array($value)) {
        $encoded = [];
        foreach ($value as $key => $entry) {
            $encoded[$key] = encodeStructureValue($entry, $walk);
        }

        return $encoded;
    }

    return $value;
}

/**
 * Publish the structural tree under one declaration.
 *
 * Children are published before the parent, so every reference in a payload names a structure
 * that already exists. The source node is read once, here, while the check is live; after this
 * walk the structure map is the authority and the AST is only a source location.
 *
 * With `$withPayload` false the same walk assigns the same ids and returns the source map, but
 * does not build the structural payloads. Cache wakeup uses that mode to rebuild object links
 * without allocating a second copy of the tree it just decoded.
 *
 * @return array{structures: array<int, CheckedStructure>, sites: array<int, int>, sources: array<int, Ast\AstWalkable>}
 */
function checkedStructuresIn(Ast\AstWalkable $root, bool $withPayload = true): array
{
    $structures = [];
    $sites = [];
    $sources = [];
    $seen = [];
    $next = 0;

    $isStructureField = static function (Ast\AstWalkable $value, string $name): bool {
        if ($value instanceof Ast\TypeNode) {
            return false;
        }
        if ($name === 'inferredType' || $name === 'inferredInternalType') {
            return false;
        }
        if ($value instanceof Ast\EvidenceRef) {
            return ! \in_array($name, ['class', 'context'], true);
        }
        if ($value instanceof Ast\EvidenceMethod) {
            return ! \in_array($name, ['class', 'evidence', 'evidenceInstance', 'contextEvidence'], true);
        }

        return true;
    };

    $recurse = null;
    $walk = static function (Ast\AstWalkable $value) use (&$walk, &$recurse, &$structures, &$sites, &$sources, &$seen, &$next, $withPayload, $isStructureField): int {
        $key = \spl_object_id($value);
        if (isset($seen[$key])) {
            return $seen[$key];
        }

        $payload = [];
        if ($withPayload) {
            foreach (\get_object_vars($value) as $name => $property) {
                if (! $isStructureField($value, $name)) {
                    continue;
                }
                $payload[$name] = encodeStructureValue($property, $walk);
            }
        } else {
            foreach (\get_object_vars($value) as $name => $property) {
                if (! $isStructureField($value, $name)) {
                    continue;
                }
                $recurse($property);
            }
        }

        $id = ++$next;
        if ($withPayload) {
            $structures[$id] = new CheckedStructure(
                new TermId($id),
                $value instanceof Ast\AstNode ? CheckedFunction::termOrigin($value) : '',
                $value::class,
                $payload,
            );
        }
        $sources[$id] = $value;
        $seen[$key] = $id;
        if ($value instanceof Ast\AstNode) {
            $sites[$key] = $id;
        }

        return $id;
    };

    $recurse = static function (mixed $value) use (&$recurse, &$walk): void {
        if ($value instanceof Ast\AstWalkable) {
            $walk($value);

            return;
        }
        if (\is_array($value)) {
            foreach ($value as $entry) {
                $recurse($entry);
            }
        }
    };

    $walk($root);

    return ['structures' => $structures, 'sites' => $sites, 'sources' => $sources];
}
