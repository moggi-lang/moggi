<?php declare(strict_types=1);

namespace Moggi\LSP;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Analysis\addDynamicRegistration;
use function Moggi\LSP\Analysis\ensureDocIndex;
use function Moggi\LSP\Analysis\removeDynamicRegistration;
use function Moggi\LSP\Analysis\setWorkspaceRoot;
use function Moggi\LSP\Analysis\shutdown;
use function Moggi\LSP\Analysis\warm;
use function Moggi\LSP\Protocol\sendNotification;
use function Moggi\LSP\Protocol\sendResult;
use function Moggi\LSP\Protocol\uriToPath;
use function Moggi\LSP\Protocol\writeMessage;

function handleInitialize(AnalysisService $svc, array $params, int|string|null $id): bool
{
    $root = $params['rootUri'] ?? ($params['workspaceFolders'][0]['uri'] ?? null);
    if (\is_string($root) && $root !== '') {
        $svc->setWorkspaceRoot(uriToPath($root));
    }
    $caps = \is_array($params['capabilities'] ?? null) ? $params['capabilities'] : [];
    $svc->clientCapabilities = $caps;
    $workDoneProgress = (bool) ($caps['window']['workDoneProgress'] ?? false);
    sendResult($id, [
        'capabilities' => serverCapabilities(),
        'serverInfo' => SERVER_INFO,
    ]);
    return $workDoneProgress;
}

/**
 * Handle `initialized`: pull (not just push) configuration so clients that
 * never send didChangeConfiguration still get their moggi settings applied,
 * warm the stdlib caches and announce readiness.
 */
function handleInitialized(AnalysisService $svc): void
{
    pullConfiguration();
    sendNotification('moggi/status', ['state' => 'checking', 'message' => 'Warming stdlib…']);
    $svc->warm();
    try {
        $svc->ensureDocIndex();
    } catch (\Throwable) {
    }
    sendNotification('moggi/status', ['state' => 'ready', 'message' => 'Ready']);
    sendNotification('window/logMessage', [
        'type' => 3,
        'message' => 'Moggi language server ready (stdlib warm)',
    ]);
}

/**
 * Server→client configuration pull for the `moggi` section. The reply is
 * matched by id prefix in the dispatch loop (client replies carry no method).
 */
function pullConfiguration(): void
{
    static $configSeq = 0;
    $token = 'moggi-config-' . (++$configSeq);
    writeMessage([
        'jsonrpc' => '2.0',
        'id' => $token,
        'method' => 'workspace/configuration',
        'params' => ['items' => [['section' => 'moggi']]],
    ]);
}

/**
 * Handle `shutdown`. Returns the new $shutdownReceived state.
 */
function handleShutdown(AnalysisService $svc, int|string|null $id): bool
{
    $svc->shutdown();
    if ($id !== null) {
        sendResult($id, null);
    }
    return true;
}

/**
 * Handle `client/registerCapability`: acknowledge and store registrations.
 */
function handleRegisterCapability(AnalysisService $svc, array $params, int|string|null $id): void
{
    $registrations = $params['registrations'] ?? [];
    foreach ($registrations as $reg) {
        $idReg = $reg['id'] ?? null;
        $methodReg = $reg['method'] ?? '';
        if ($idReg !== null && $methodReg !== '') {
            $svc->addDynamicRegistration($idReg, $methodReg, $reg);
        }
    }
    if ($id !== null) {
        sendResult($id, null);
    }
}

/**
 * Handle `client/unregisterCapability`: drop stored registrations.
 */
function handleUnregisterCapability(AnalysisService $svc, array $params, int|string|null $id): void
{
    $unregistrations = $params['unregistrations'] ?? [];
    foreach ($unregistrations as $unreg) {
        $idReg = $unreg['id'] ?? null;
        if ($idReg !== null) {
            $svc->removeDynamicRegistration($idReg);
        }
    }
    if ($id !== null) {
        sendResult($id, null);
    }
}
