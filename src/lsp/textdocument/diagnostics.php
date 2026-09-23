<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\checkCancelledPoint;
use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Analysis\resultIdFor;
use function Moggi\LSP\Protocol\pathToUri;

/**
 * Document pull diagnostics (spec `textDocument/diagnostic`).
 *
 * When the client passes a `previousResultId` and the diagnostics are
 * unchanged since that snapshot, respond `{kind: 'unchanged'}` with the same
 * id instead of shipping items again. Otherwise respond `{kind: 'full',
 * items, resultId}` where resultId fingerprints the current diagnostics.
 */
function svcDiagnostic(AnalysisService $svc, string $uri, array $params = []): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    $items = $analysis->diagnostics ?? [];
    $resultId = resultIdFor($uri, $items);

    $previousResultId = $params['previousResultId'] ?? null;
    if (\is_string($previousResultId)
        && $previousResultId !== ''
        && $previousResultId === $resultId) {
        return ['kind' => 'unchanged', 'resultId' => $resultId];
    }

    return [
        'kind' => 'full',
        'resultId' => $resultId,
        'items' => $items,
    ];
}

/**
 * One `WorkspaceDocumentDiagnosticReport` entry.
 *
 * A report is a total statement about a document: `unchanged` when the
 * fingerprint still equals the result id the client sent for that URI, `full`
 * otherwise — including a document that is now clean, where `items: []` is the
 * only way the client can learn to drop the diagnostics it cached earlier.
 *
 * `unchanged` is emitted only when the client actually supplied a result id
 * for the URI, as the spec requires.
 *
 * @param list<array<string, mixed>> $diags
 * @param array<string, string> $previousByUri
 * @return array<string, mixed>
 */
function workspaceReport(string $uri, array $diags, ?int $version, array $previousByUri): array
{
    $resultId = resultIdFor($uri, $diags);
    if (($previousByUri[$uri] ?? null) === $resultId) {
        return [
            'uri' => $uri,
            'kind' => 'unchanged',
            'resultId' => $resultId,
            'version' => $version,
        ];
    }

    return [
        'uri' => $uri,
        'kind' => 'full',
        'resultId' => $resultId,
        'items' => $diags,
        'version' => $version,
    ];
}

/**
 * Workspace pull diagnostics (spec `workspace/diagnostic`). Reports every open
 * document plus every indexed module that has something to say: a document the
 * client already knows a result id for is reported `unchanged`/`full`, and a
 * closed module is reported once it has diagnostics — or once the client holds
 * a result id that has to be retired, e.g. because the file became clean or was
 * deleted (an omission would leave those diagnostics in the client forever).
 */
function svcWorkspaceDiagnostic(AnalysisService $svc, array $params = []): array
{
    checkCancelledPoint();
    $items = [];

    $previousByUri = [];
    foreach ($params['previousResultIds'] ?? [] as $prev) {
        if (isset($prev['uri'], $prev['value'])) {
            $previousByUri[(string) $prev['uri']] = (string) $prev['value'];
        }
    }

    // Open documents: authoritative current state.
    foreach ($svc->openUris() as $uri) {
        $analysis = $svc->ensureAnalyzed($uri);
        $items[] = workspaceReport(
            $uri,
            $analysis->diagnostics ?? [],
            $svc->documentVersion($uri),
            $previousByUri,
        );
    }

    // Indexed-but-closed modules. Reported when they have diagnostics, or when
    // the client holds a result id for them and they no longer do.
    foreach ($svc->modules->modules as $module) {
        $uri = (string) ($module['uri'] ?? '');
        if ($uri === '' || $svc->isOpen($uri)) {
            continue;
        }
        $known = isset($previousByUri[$uri]);
        if (!is_file(substr($uri, strlen('file://')))) {
            // Gone from disk: retire the client's cached diagnostics for it.
            if ($known) {
                $items[] = workspaceReport($uri, [], null, []);
            }
            continue;
        }
        $analysis = $svc->ensureAnalyzed($uri);
        if ($analysis === null) {
            continue;
        }
        $diags = $analysis->diagnostics ?? [];
        if ($diags === [] && !$known) {
            continue;
        }
        $items[] = workspaceReport($uri, $diags, null, $previousByUri);
    }

    return ['items' => $items];
}
