#!/usr/bin/env php
<?php declare(strict_types=1);

// Capability ↔ dispatch drift test: the advertised initialize capabilities
// and the dispatch map (lspMethodHandlers) must stay aligned — the executable
// version of the rule stated in docs/lsp.md ("Architecture").

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\LSP\capabilityDispatchMismatches;
use function Moggi\LSP\lspMethodHandlers;
use function Moggi\LSP\serverCapabilities;

use const Moggi\LSP\FEATURE_CAPABILITIES;
use const Moggi\LSP\UNADVERTISED_METHODS;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

// Core invariant: no drift between handlers and capabilities

$mismatches = capabilityDispatchMismatches();
$assert(
    $mismatches === [],
    "capability/dispatch drift detected:\n  " . implode("\n  ", $mismatches),
);

// Structural checks on the inputs the checker compares

// Every feature-capability method must really be dispatched.
$handlers = lspMethodHandlers();
foreach (FEATURE_CAPABILITIES as $method => $cap) {
    $assert(
        isset($handlers[$method]),
        "FEATURE_CAPABILITIES lists '$method' but lspMethodHandlers() has no handler for it",
    );
}

// Unadvertised methods must be dispatched but must never appear in the
// capability table (that would silently make them "advertised").
foreach (UNADVERTISED_METHODS as $method) {
    $assert(
        isset($handlers[$method]),
        "UNADVERTISED_METHODS lists '$method' but there is no handler to back it",
    );
    $assert(
        !isset(FEATURE_CAPABILITIES[$method]),
        "UNADVERTISED_METHODS lists '$method' but FEATURE_CAPABILITIES advertises it",
    );
}

// The advertised capabilities payload must contain every
// capability key referenced by FEATURE_CAPABILITIES

$caps = serverCapabilities();

// Resolve a dotted capability path (e.g. workspace.fileOperations.willRename)
// against the capabilities array; provider keys are top-level.
$hasCap = static function (string $cap) use ($caps): bool {
    if (!str_contains($cap, '.')) {
        return isset($caps[$cap]);
    }
    $cur = $caps;
    foreach (explode('.', $cap) as $seg) {
        if (!is_array($cur) || !isset($cur[$seg])) {
            return false;
        }
        $cur = $cur[$seg];
    }
    return true;
};

foreach (FEATURE_CAPABILITIES as $method => $cap) {
    $assert(
        $hasCap($cap),
        "FEATURE_CAPABILITIES maps '$method' to capability '$cap' but serverCapabilities() does not advertise it",
    );
}

echo "OK: no capability/dispatch drift\n";
