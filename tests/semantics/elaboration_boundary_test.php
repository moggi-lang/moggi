#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

/**
 * The inference/elaboration boundary, checked in source.
 *
 * Inference generates and parks constraints; evidence elaboration is a separate
 * step. `inferApply` must therefore contain no decision at all -- no
 * resolvability test, no solve, no rewrite. It parks what a callee owes
 * (`parkApplyConstraints`) and the caller elaborates through
 * `inferApplyAndElaborate`. `inferInfix` may choose an AST shape but must not
 * insert evidence itself.
 *
 * A call to one of these primitives creeping back into either function is
 * exactly how evidence insertion drifted into inference in the first place, so
 * it is pinned here rather than left to review.
 */

/** The text of a top-level function, from `function name(` to the next one. */
$body = static function (string $file, string $function): string {
    $source = \file_get_contents($file);
    if ($source === false) {
        throw new \RuntimeException("cannot read {$file}");
    }

    $start = \strpos($source, "\nfunction " . $function . '(');
    if ($start === false) {
        throw new \RuntimeException("function {$function} not found in {$file}");
    }

    $rest = \substr($source, $start + 1);
    $end = \strpos($rest, "\nfunction ", 1);

    return $end === false ? $rest : \substr($rest, 0, $end);
};

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$infer = $root . '/src/semantics/types/infer.php';

/** Calls that make a function an elaborator rather than a generator. */
$elaboration = [
    'prependEvidenceAtRoot',
    'prependEvidenceToCall',
    'clearPendingConstraints',
    'resolveConstraintEvidence',
];

/** Solving, the other half of what generation must not do. */
$solving = [
    'dictsForPending',
    'solveConstraints',
    'constraintsResolvable',
];

$inferApply = $body($infer, 'inferApply');
$inferInfix = $body($infer, 'inferInfix');

foreach ([...$elaboration, ...$solving] as $name) {
    $assert(
        !\str_contains($inferApply, $name . '('),
        "inferApply must not call {$name}: inference generates obligations, it does not solve or elaborate them",
    );
}

foreach ($elaboration as $name) {
    $assert(
        !\str_contains($inferInfix, $name . '('),
        "inferInfix must not call {$name}: it may choose an AST shape but not insert evidence",
    );
}

$assert(
    \str_contains($inferApply, 'parkApplyConstraints('),
    'inferApply must park the obligation it generates',
);
$assert(
    \str_contains($body($infer, 'elaborateApplyEvidence'), 'prependEvidenceAtRoot('),
    'elaborateApplyEvidence must be the step that inserts a call\'s evidence',
);

echo "elaboration boundary: inferApply generates only; elaboration is one step\n";
