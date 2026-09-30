<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Formatter\formatMoggiSource;
use function Moggi\LSP\Protocol\splitLines;

function svcOnTypeFormatting(AnalysisService $svc, string $uri, array $position, string $ch, array $options): array
{
    if (!in_array($ch, ['{', '}', ';'], true)) {
        return [];
    }
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $formatted = formatMoggiSource($analysis->source);
    if ($formatted === $analysis->source) {
        return [];
    }
    $range = [
        'start' => ['line' => 0, 'character' => 0],
        'end' => ['line' => count(splitLines($analysis->source)) - 1, 'character' => 0],
    ];
    return [
        ['range' => $range, 'newText' => $formatted],
    ];
}
