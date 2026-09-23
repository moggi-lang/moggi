<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Ast;

use function Moggi\Docs\search;
use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Analysis\findNodeChainAt;
use function Moggi\LSP\Analysis\workspaceSymbols;
use function Moggi\LSP\Index\declForResolved;
use function Moggi\LSP\Index\declTokenMaps;
use function Moggi\LSP\Protocol\lspPosToCompiler;
use function Moggi\LSP\Protocol\nodeToLspRange;
use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\Protocol\splitLines;
use function Moggi\Modules\resolvedSymbol;

function svcDocumentSymbols(AnalysisService $svc, string $uri): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $module = $analysis->program instanceof Ast\Program ? ($analysis->program->module ?? '') : '';
    $modEntry = $svc->modules->modules[$module] ?? null;
    if ($modEntry !== null) {
        $out = [];
        foreach ($modEntry['decls'] as $decl) {
            // Skip declarations with invalid ranges (e.g., constructors with zero positions)
            if (($decl->range['start']['line'] ?? 0) === 0 && ($decl->range['start']['character'] ?? 0) === 0) {
                continue;
            }
            $sym = [
                'name' => $decl->name,
                'kind' => $decl->kind,
                'range' => $decl->range,
                'selectionRange' => $decl->selectionRange,
                'detail' => $decl->type,
            ];
            if ($decl->children !== []) {
                $children = [];
                foreach ($decl->children as $c) {
                    if (($c->range['start']['line'] ?? 0) === 0 && ($c->range['start']['character'] ?? 0) === 0) {
                        continue;
                    }
                    $children[] = [
                        'name' => $c->name,
                        'kind' => $c->kind,
                        'range' => $c->range,
                        'selectionRange' => $c->selectionRange,
                        'detail' => $c->type,
                    ];
                }
                if ($children !== []) {
                    $sym['children'] = $children;
                }
            }
            $out[] = $sym;
        }
        return $out;
    }
    if (!empty($analysis->symbols)) {
        $out = [];
        foreach ($analysis->symbols as $sym) {
            $range = $sym['location']['range'] ?? null;
            if (!$range) {
                continue;
            }
            $out[] = [
                'name' => $sym['name'],
                'kind' => $sym['kind'],
                'range' => $range,
                'selectionRange' => $range,
                'detail' => $sym['detail'] ?? null,
            ];
        }
        return $out;
    }
    return [];
}

function svcFoldingRanges(AnalysisService $svc, string $uri): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $ranges = [];
    if ($analysis->program instanceof Ast\Program) {
        // How far a declaration reaches is the indentation block its name starts, which only the
        // token map knows: a decl carries the line of its own name and no extent.
        $maps = declTokenMaps($analysis->source, $uri);
        $endLines = $maps['endLines'];
        $seenLines = [];
        foreach ($analysis->program->items as $item) {
            $name = (string) ($item->name ?? '');
            $tok = $maps['names'][$name] ?? null;
            $line = (int) ($item->line ?? 0) ?: (int) ($tok->line ?? 0);
            $endLine = $endLines[$name] ?? (int) (($item->endLine ?? 0) ?: $line);
            if ($line <= 0) {
                continue;
            }
            if (isset($seenLines[$line])) {
                continue;
            }
            $seenLines[$line] = true;
            $ranges[] = [
                'startLine' => $line - 1,
                'endLine' => max($line - 1, $endLine - 1),
                'kind' => 'region',
            ];
        }
    }
    $lines = splitLines($analysis->source);
    $lineCount = count($lines);
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*--\s*region\b/', $line)) {
            $start = $i;
            for ($j = $i + 1; $j < $lineCount; $j++) {
                if (preg_match('/^\s*--\s*endregion\b/', $lines[$j])) {
                    $ranges[] = ['startLine' => $start, 'endLine' => $j, 'kind' => 'region'];
                    break;
                }
            }
        }
    }
    return $ranges;
}

function svcSelectionRange(AnalysisService $svc, string $uri, array $positions): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $out = [];
    foreach ($positions as $pos) {
        $comp = lspPosToCompiler($analysis->source, $pos);
        $chain = findNodeChainAt($analysis->program, $comp['line'], $comp['col']);
        if ($chain === []) {
            $out[] = ['range' => ['start' => $pos, 'end' => $pos]];
            continue;
        }
        // Chain is outer→inner. Nest so result is innermost with parent=larger spans.
        $nested = null;
        foreach ($chain as $node) {
            if (($node->line ?? 0) <= 0) {
                continue;
            }
            $entry = ['range' => nodeToLspRange($analysis->source, $node)];
            if ($nested !== null) {
                $entry['parent'] = $nested;
            }
            $nested = $entry;
        }
        $out[] = $nested ?? ['range' => ['start' => $pos, 'end' => $pos]];
    }
    return $out;
}

function svcWorkspaceSymbol(AnalysisService $svc, string $query): array
{
    $out = [];
    $seen = [];

    // Moogle-ranked hits first when DocIndex is available.
    $docIndex = $svc->docIndex;
    if ($query !== '' && $docIndex !== null) {
        try {
            $hits = search($docIndex, $query, 40);
            foreach ($hits as $hit) {
                $e = $hit->entity;
                $key = $e->module . '::' . $e->name;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $resolved = resolvedSymbol($e->module, $e->name);
                $decl = $svc->modules->declForResolved($resolved);
                $uri = $decl?->uri;
                $range = $decl?->selectionRange;
                if ($uri === null && ($e->sourcePath ?? '') !== '') {
                    $uri = pathToUri((string) $e->sourcePath);
                    $line = max(0, ((int) ($e->sourceLine ?? 1)) - 1);
                    $range = [
                        'start' => ['line' => $line, 'character' => 0],
                        'end' => ['line' => $line, 'character' => max(1, strlen($e->name))],
                    ];
                }
                if ($uri === null || $range === null) {
                    continue;
                }
                $out[] = [
                    'name' => $e->name,
                    'kind' => match ($e->kind) {
                        'data', 'newtype' => 23,
                        'type' => 5,
                        'class' => 11,
                        'ctor' => 9,
                        default => 12,
                    },
                    'containerName' => $e->module,
                    'location' => ['uri' => $uri, 'range' => $range],
                ];
            }
        } catch (\Throwable) {
        }
    }

    foreach ($svc->workspaceSymbols($query) as $decl) {
        $key = ($decl->resolved ?? $decl->module . '::' . $decl->name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = [
            'name' => $decl->name,
            'kind' => $decl->kind,
            'containerName' => $decl->module,
            'location' => [
                'uri' => $decl->uri,
                'range' => $decl->selectionRange,
            ],
        ];
    }
    return $out;
}

function declKindLabel(array $decl): string
{
    return match ($decl['type'] ?? '') {
        'data' => 'algebraic data type',
        'newtype' => 'newtype',
        'type' => 'type synonym',
        'class' => 'type class',
        default => (isset($decl['type']) && str_contains((string) $decl['type'], '->'))
            ? 'function'
            : (($decl['type'] ?? '') === 'function' ? 'function' : ''),
    };
}
