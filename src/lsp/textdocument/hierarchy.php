<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Index\declForResolved;
use function Moggi\LSP\Index\findByResolved;
use function Moggi\LSP\Index\incomingCalls;
use function Moggi\LSP\Index\outgoingCalls;
use function Moggi\LSP\Protocol\lspPosToCompiler;
use function Moggi\Modules\parseResolvedSymbol;

function svcCallHierarchyPrepare(AnalysisService $svc, string $uri, array $pos): array
{
    $target = resolveNavTarget($svc, $uri, $pos);
    if ($target === null || ($target['resolved'] ?? null) === null) {
        return [];
    }
    // Only advertise prepare when we can produce at least one edge or a def.
    $resolved = $target['resolved'];
    $hasEdge = $svc->occurrences->incomingCalls($resolved) !== []
        || $svc->occurrences->outgoingCalls($resolved) !== []
        || $svc->occurrences->findByResolved($resolved) !== [];
    if (!$hasEdge) {
        return [];
    }
    return [[
        'name' => $target['name'],
        'kind' => 12,
        'uri' => $target['uri'],
        'range' => $target['range'],
        'selectionRange' => $target['range'],
        'data' => ['resolved' => $resolved],
    ]];
}

function svcCallHierarchyIncoming(AnalysisService $svc, array $item): array
{
    $resolved = $item['data']['resolved'] ?? null;
    if ($resolved === null || $resolved === '') {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($svc->occurrences->incomingCalls($resolved) as $edge) {
        $key = $edge->fromResolved . '@' . $edge->range['start']['line'] . ':' . $edge->range['start']['character'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $fromDecl = $svc->modules->declForResolved($edge->fromResolved);
        $out[] = [
            'from' => [
                'name' => $fromDecl->name ?? $edge->fromName,
                'kind' => 12,
                'uri' => $fromDecl->uri ?? $edge->uri,
                'range' => $fromDecl->range ?? $edge->range,
                'selectionRange' => $fromDecl->selectionRange ?? $edge->range,
                'data' => ['resolved' => $edge->fromResolved],
            ],
            'fromRanges' => [$edge->range],
        ];
    }
    // Fallback: uses of the symbol whose enclosing function is known
    if ($out === []) {
        foreach ($svc->occurrences->findByResolved($resolved) as $occ) {
            if ($occ->kind === 'def' || $occ->enclosing === null) {
                continue;
            }
            $fromDecl = $svc->modules->declForResolved($occ->enclosing);
            $out[] = [
                'from' => [
                    'name' => $fromDecl->name ?? $occ->enclosing,
                    'kind' => 12,
                    'uri' => $fromDecl->uri ?? $occ->uri,
                    'range' => $fromDecl->range ?? $occ->range,
                    'selectionRange' => $fromDecl->selectionRange ?? $occ->range,
                    'data' => ['resolved' => $occ->enclosing],
                ],
                'fromRanges' => [$occ->range],
            ];
        }
    }
    return $out;
}

function svcCallHierarchyOutgoing(AnalysisService $svc, array $item): array
{
    $resolved = $item['data']['resolved'] ?? null;
    if ($resolved === null || $resolved === '') {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($svc->occurrences->outgoingCalls($resolved) as $edge) {
        $key = $edge->toResolved . '@' . $edge->range['start']['line'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $toDecl = $svc->modules->declForResolved($edge->toResolved);
        $out[] = [
            'to' => [
                'name' => $toDecl->name ?? $edge->toName,
                'kind' => 12,
                'uri' => $toDecl->uri ?? $edge->uri,
                'range' => $toDecl->range ?? $edge->range,
                'selectionRange' => $toDecl->selectionRange ?? $edge->range,
                'data' => ['resolved' => $edge->toResolved],
            ],
            'fromRanges' => [$edge->range],
        ];
    }
    return $out;
}

function svcTypeHierarchyPrepare(AnalysisService $svc, string $uri, array $pos): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $comp = lspPosToCompiler($analysis->source, $pos);
    $word = wordAt($analysis->source, $comp['line'], $comp['col']);
    if ($word === null) {
        return [];
    }
    foreach ($svc->modules->defsByResolved as $res => $decl) {
        $parts = parseResolvedSymbol($res);
        if ($parts === null || $parts['name'] !== $word) {
            continue;
        }
        $isClass = ($decl->type === 'class') || isset($svc->modules->classSupers[$word]);
        $isData = in_array($decl->type, ['data', 'newtype', 'type'], true);
        if (!$isClass && !$isData) {
            continue;
        }
        // Every remaining candidate is a class or data declaration, so there is
        // nothing left to filter: advertise it (its supertypes/instances may be
        // empty, and `typeHierarchySupertypes` returns `[]` for those).
        return [[
            'name' => $decl->name,
            'kind' => $decl->kind,
            'uri' => $decl->uri,
            'range' => $decl->range,
            'selectionRange' => $decl->selectionRange,
            'data' => ['resolved' => $res, 'name' => $word, 'kind' => $isClass ? 'class' : 'data'],
        ]];
    }
    return [];
}

function svcTypeHierarchySupertypes(AnalysisService $svc, array $item): array
{
    $name = $item['data']['name'] ?? $item['name'] ?? null;
    if ($name === null || $name === '') {
        return [];
    }
    $out = [];
    foreach ($svc->modules->classSupers[$name] ?? [] as $super) {
        $itemOut = typeHierarchyItemForName($svc, $super);
        if ($itemOut !== null) {
            $out[] = $itemOut;
        }
    }
    return $out;
}

function svcTypeHierarchySubtypes(AnalysisService $svc, array $item): array
{
    $name = $item['data']['name'] ?? $item['name'] ?? null;
    if ($name === null || $name === '') {
        return [];
    }
    $out = [];
    // Instances of this class are subtypes in the LSP type-hierarchy sense.
    foreach ($svc->modules->classInstances[$name] ?? [] as $inst) {
        $out[] = [
            'name' => $inst['name'],
            'kind' => 5, // Class
            'uri' => $inst['uri'],
            'range' => $inst['range'],
            'selectionRange' => $inst['range'],
            'data' => ['name' => $name, 'kind' => 'instance'],
        ];
    }
    // Classes that list this as a superclass
    foreach ($svc->modules->classSupers as $cls => $supers) {
        if (!in_array($name, $supers, true)) {
            continue;
        }
        $itemOut = typeHierarchyItemForName($svc, $cls);
        if ($itemOut !== null) {
            $out[] = $itemOut;
        }
    }
    return $out;
}

function typeHierarchyItemForName(AnalysisService $svc, string $name): ?array
{
    foreach ($svc->modules->defsByResolved as $res => $decl) {
        $parts = parseResolvedSymbol($res);
        if ($parts !== null && $parts['name'] === $name) {
            return [
                'name' => $decl->name,
                'kind' => $decl->kind,
                'uri' => $decl->uri,
                'range' => $decl->range,
                'selectionRange' => $decl->selectionRange,
                'data' => ['resolved' => $res, 'name' => $name, 'kind' => $decl->type ?? 'type'],
            ];
        }
    }
    // Synthetic leaf when we know the name but not a decl (stdlib not indexed)
    return null;
}
