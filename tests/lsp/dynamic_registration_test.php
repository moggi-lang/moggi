#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Dynamic registration support: the AnalysisService must actually store and
 * remove client registrations (client/registerCapability /
 * client/unregisterCapability payloads), since the server acknowledges both.
 *
 * Note: registerCapability/unregisterCapability are *requests the server
 * receives*, so there is no server-side reply to assert here without running
 * the whole stdio loop — the raw framing layer is covered by e2e-lsp.mjs.
 * This file pins the service-level contract the dispatch relies on.
 */

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\readMessage;
use function Moggi\LSP\Protocol\writeMessage;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$svc = new AnalysisService();

$assert($svc->getDynamicRegistrations() === [], 'registrations start empty');

$registration = ['method' => 'textDocument/hover', 'registerOptions' => []];
$svc->addDynamicRegistration('reg-1', 'textDocument/hover', $registration);
$svc->addDynamicRegistration('reg-2', 'textDocument/documentSymbol', ['registerOptions' => []]);

$all = $svc->getDynamicRegistrations();
$assert(count($all) === 2, 'two registrations stored');
$assert(($all['reg-1']['method'] ?? '') === 'textDocument/hover', 'reg-1 keeps its method');
$assert(($all['reg-1']['registration'] ?? null) === $registration, 'reg-1 keeps full registration payload');

$svc->removeDynamicRegistration('reg-1');
$all = $svc->getDynamicRegistrations();
$assert(count($all) === 1 && isset($all['reg-2']), 'reg-1 removed, reg-2 kept');

$svc->removeDynamicRegistration('reg-1'); // idempotent: unknown id must not throw
$assert(count($svc->getDynamicRegistrations()) === 1, 'removing unknown id is a no-op');

$svc->removeDynamicRegistration('reg-2');
$assert($svc->getDynamicRegistrations() === [], 'all registrations removed');

// Framing sanity: writeMessage → readMessage round-trips a JSON-RPC frame.
$stream = fopen('php://memory', 'r+');
if ($stream === false) {
    throw new RuntimeException('Cannot create memory stream');
}
$frame = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'client/registerCapability', 'params' => ['registrations' => []]];
writeMessage($frame, $stream);
rewind($stream);
$malformed = false;
$echoed = readMessage($stream, $malformed);
fclose($stream);
$assert(!$malformed, 'echoed frame is not malformed');
$assert($echoed === $frame, 'writeMessage/readMessage round-trips the frame');

echo "lsp dynamic registration tests passed\n";
