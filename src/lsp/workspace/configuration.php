<?php declare(strict_types=1);

namespace Moggi\LSP\Workspace;

use Moggi\LSP\Analysis\AnalysisService;

function applyMoggiSettings(AnalysisService $svc, array $settings): void
{
    if (isset($settings['inlayHints'])) {
        $svc->inlaysEnabled = (bool) $settings['inlayHints'];
    }
}

/**
 * Handle `workspace/didChangeConfiguration`.
 */
function handleDidChangeConfiguration(AnalysisService $svc, array $params): void
{
    applyMoggiSettings($svc, $params['settings']['moggi'] ?? []);
}

/**
 * Handle the reply to our workspace/configuration pull. Client replies to
 * server→client requests carry no `method`; match by the id prefix that
 * Moggi\LSP\pullConfiguration() generates.
 */
function handleConfigurationReply(AnalysisService $svc, int|string|null $id, mixed $result): bool
{
    if (!is_string($id) || !str_starts_with($id, 'moggi-config-')) {
        return false;
    }
    $settings = (is_array($result) && isset($result[0]) && is_array($result[0])) ? $result[0] : [];
    applyMoggiSettings($svc, $settings);
    return true;
}
