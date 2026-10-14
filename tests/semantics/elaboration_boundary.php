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
 * Inference generates and parks constraints; solving and evidence elaboration
 * are separate steps. `inferApply` must therefore contain no decision at all --
 * no resolvability test, no solve, no rewrite. It parks what a callee owes
 * (`parkApplyConstraints`) and the caller elaborates through
 * `inferApplyAndElaborate`. `inferInfix` may choose an AST shape but must not
 * solve or insert evidence either.
 *
 * Evidence reaches the tree from exactly one function during inference --
 * `elaborateApplyEvidence` -- and from the named elaboration functions of the
 * post-pass. This test pins that whole set, so a new insertion point cannot
 * appear anywhere without being named here, and no `infer*` function can insert
 * at all.
 *
 * `constraintsResolvable` is the one solver-adjacent thing `inferInfix` is
 * allowed to call: it only answers whether this operator can be turned into a
 * call *now* (a shape decision, not a solve), and it is load-bearing there --
 * dropping it and always rewriting to a call breaks eight fixtures.
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

/**
 * Every top-level function in a file, name => body. Docblocks are stripped so a
 * comment that names a primitive does not read as a call to it.
 *
 * @return array<string, string>
 */
$functions = static function (string $file): array {
    $source = \file_get_contents($file);
    if ($source === false) {
        throw new \RuntimeException("cannot read {$file}");
    }

    $functions = [];
    foreach (\preg_split('/\n(?=function )/', $source) ?: [] as $part) {
        if (!\preg_match('/^function ([A-Za-z_][A-Za-z0-9_]*)\(/', $part, $match)) {
            continue;
        }

        $functions[$match[1]] = \preg_replace('#/\*\*.*?\*/#s', '', $part) ?? $part;
    }

    return $functions;
};

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

/** The calls that actually attach a dictionary expression to the tree. */
$insertion = ['prependEvidenceAtRoot', 'prependEvidenceToCall'];

/** The functions allowed to make them, and the phase each belongs to. */
$expectedInfer = [
    // inference: the one place an apply's obligation is elaborated
    'elaborateApplyEvidence',
    // the post-pass over a checked body
    'resolvePendingEvidenceInExpr',
    'tryResolveApplyEvidence',
    'tryResolveValueEvidence',
];
$expectedLiterals = [
    // literal elaboration
    'elaborateNumericLiteral',
];

$inserters = static function (array $functions) use ($insertion): array {
    $found = [];
    foreach ($functions as $name => $text) {
        foreach ($insertion as $primitive) {
            if (\str_contains($text, $primitive . '(')) {
                $found[] = $name;
                break;
            }
        }
    }
    \sort($found);

    return $found;
};

$typeRoot = $root . '/src/semantics/types';
$inferFile = $typeRoot . '/infer.php';
$inferFunctions = $functions($inferFile);
$literalFunctions = $functions($typeRoot . '/literals.php');

$expectation = $expectedInfer;
\sort($expectation);

$assert(
    $inserters($inferFunctions) === $expectation,
    'infer.php may insert evidence only from [' . \implode(', ', $expectedInfer) . ']; found ['
        . \implode(', ', $inserters($inferFunctions)) . ']',
);
$assert(
    $inserters($literalFunctions) === $expectedLiterals,
    'literals.php may insert evidence only from [' . \implode(', ', $expectedLiterals) . ']; found ['
        . \implode(', ', $inserters($literalFunctions)) . ']',
);

$inferApply = $inferFunctions['inferApply'] ?? throw new \RuntimeException('inferApply not found');
$inferInfix = $inferFunctions['inferInfix'] ?? throw new \RuntimeException('inferInfix not found');

foreach ([...$insertion, 'clearPendingConstraints', 'resolveConstraintEvidence', 'dictsForPending', 'solveConstraints', 'constraintsResolvable'] as $name) {
    $assert(
        !\str_contains($inferApply, $name . '('),
        "inferApply must not call {$name}: inference generates obligations, it does not solve or elaborate them",
    );
}

foreach ([...$insertion, 'clearPendingConstraints', 'resolveConstraintEvidence', 'dictsForPending', 'solveConstraints'] as $name) {
    $assert(
        !\str_contains($inferInfix, $name . '('),
        "inferInfix must not call {$name}: it may pick an AST shape but not solve or insert evidence",
    );
}

// No inference-phase function may insert evidence: `elaborateApplyEvidence`
// (reached through `inferApplyAndElaborate`) is the only way in.
foreach ($inferFunctions as $name => $text) {
    if (!\str_starts_with($name, 'infer')) {
        continue;
    }

    foreach ($insertion as $primitive) {
        $assert(
            !\str_contains($text, $primitive . '('),
            "inference function {$name} must not insert evidence; elaborateApplyEvidence is the only one that does",
        );
    }
}

$applyCallers = [];
foreach ($inferFunctions as $name => $text) {
    if ($name !== 'inferApply' && \preg_match('/\binferApply\(/', $text)) {
        $applyCallers[] = $name;
    }
}
$assert(
    $applyCallers === ['inferApplyAndElaborate'],
    'inferApplyAndElaborate must be the only entry to inferApply; found [' . \implode(', ', $applyCallers) . ']',
);
$assert(
    \str_contains($inferFunctions['inferApplyAndElaborate'], 'elaborateApplyEvidence('),
    'inferApplyAndElaborate must elaborate through elaborateApplyEvidence',
);
$assert(
    \str_contains($inferApply, 'parkApplyConstraints('),
    'inferApply must park the obligation it generates',
);
$assert(
    \str_contains($inferInfix, 'constraintsResolvable('),
    'the rewritten-or-parked operator decision is expected to stay a shape check in inferInfix',
);

echo 'elaboration boundary: evidence enters only from ['
    . \implode(', ', [...$expectedInfer, ...$expectedLiterals]) . "]\n";
