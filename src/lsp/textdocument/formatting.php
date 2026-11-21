<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Formatter\formatMoggiSource;
use function Moggi\LSP\Formatter\wholeDocumentRange;

function svcOnTypeFormatting(AnalysisService $svc, string $uri, array $position, string $ch, array $options): array
{
    if (! \in_array($ch, ['{', '}', ';'], true)) {
        return [];
    }
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $formatted = formatMoggiSource($analysis->source, $analysis->importedFixity);
    if ($formatted === null || $formatted === $analysis->source) {
        return [];
    }

    return [
        ['range' => wholeDocumentRange($analysis->source), 'newText' => $formatted],
    ];
}
