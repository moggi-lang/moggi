<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\locToRange;

use function Moggi\Modules\resolvedSymbol;

use Moggi\Syntax\Ast;

function svcCodeLens(AnalysisService $svc, string $uri): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $lenses = [];
    foreach ($analysis->declarations as $name => $decl) {
        $module = $analysis->program instanceof Ast\Program ? ($analysis->program->module ?? 'Main') : 'Main';
        $resolved = resolvedSymbol($module, $name);
        $occs = $svc->occurrences->findByResolved($resolved);
        $locations = [];
        foreach ($occs as $occ) {
            $locations[] = [
                'uri' => $occ->uri,
                'range' => $occ->range,
            ];
        }
        $count = \count($locations);
        $lenses[] = [
            'range' => locToRange($decl['line'], $decl['col'], $decl['endCol'], $analysis->source),
            'command' => [
                'title' => $count === 1 ? '1 reference' : "{$count} references",
                'command' => 'moggi.showReferences',
                'arguments' => [
                    $uri,
                    ['line' => $decl['line'] - 1, 'character' => $decl['col'] - 1],
                    $locations,
                ],
            ],
        ];
    }

    return $lenses;
}
