<?php declare(strict_types=1);

// backends: php

/**
 * The structural tree serializes by one rule, shared with the walk that builds it.
 *
 * `structureSemanticFields()` is the single list of properties that are checker state rather than
 * structure. The producer skips them and the serializer drops them, so the payload a structure
 * carries and the payload it writes cannot disagree — the drift that two copies of a string list
 * invites.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Modules\CheckedStructure;
use Moggi\Modules\TermId;
use Moggi\Syntax\Ast;

use function Moggi\Modules\structureSemanticFields;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$assert(
    structureSemanticFields(Ast\Node::class) === ['inferredType', 'inferredInternalType'],
    'a plain node no longer scrubs its inferred-type projection',
);
$assert(
    structureSemanticFields(Ast\EvidenceRef::class) === ['inferredType', 'inferredInternalType', 'class', 'context'],
    'the evidence reference rule changed',
);
$assert(
    structureSemanticFields(Ast\EvidenceMethod::class) === [
        'inferredType',
        'inferredInternalType',
        'class',
        'evidence',
        'evidenceInstance',
        'contextEvidence',
    ],
    'the evidence method rule changed',
);

foreach ([Ast\EvidenceRef::class, Ast\EvidenceMethod::class, Ast\Node::class] as $kind) {
    $payload = ['keep' => 'structure'];
    foreach (structureSemanticFields($kind) as $field) {
        $payload[$field] = 'semantic';
    }

    $structure = new CheckedStructure(new TermId(1), 'Main.mog:1:1', $kind, $payload);
    $decoded = \unserialize(\serialize($structure));
    $assert($decoded instanceof CheckedStructure, "{$kind} did not round-trip");
    $assert($decoded->payload === ['keep' => 'structure'], "{$kind} serialized a field that is not structure");
}

echo "structure serialization tests passed\n";
