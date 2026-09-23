<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Index\findByResolved;
use function Moggi\LSP\Index\findInUri;
use function Moggi\LSP\Protocol\compilerPosToLsp;
use function Moggi\LSP\Protocol\locToRange;
use function Moggi\LSP\Protocol\lspPosToCompiler;
use function Moggi\LSP\Protocol\splitLines;

function svcRename(AnalysisService $svc, string $uri, array $pos, string $newName): ?array
{
    if ($newName === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_\']*$/', $newName)) {
        return null;
    }
    $target = resolveNavTarget($svc, $uri, $pos);
    $resolved = $target['resolved'] ?? null;
    $changes = [];
    if ($resolved !== null) {
        foreach ($svc->occurrences->findByResolved($resolved) as $occ) {
            $changes[$occ->uri][] = ['range' => $occ->range, 'newText' => $newName];
        }
    } else {
        $analysis = $svc->ensureAnalyzed($uri);
        if ($analysis === null) {
            return null;
        }
        $comp = lspPosToCompiler($analysis->source, $pos);
        $word = wordAt($analysis->source, $comp['line'], $comp['col']);
        if ($word === null) {
            return null;
        }
        foreach ($svc->occurrences->findInUri($uri, $word) as $occ) {
            $changes[$uri][] = ['range' => $occ->range, 'newText' => $newName];
        }
        if (isset($analysis->declarations[$word])) {
            $d = $analysis->declarations[$word];
            $changes[$uri][] = [
                'range' => locToRange($d['line'], $d['col'], $d['endCol'], $analysis->source),
                'newText' => $newName,
            ];
        }
    }
    if ($changes === []) {
        return null;
    }
    foreach ($changes as $u => $edits) {
        $seen = [];
        $uniq = [];
        foreach ($edits as $e) {
            $k = $e['range']['start']['line'] . ':' . $e['range']['start']['character'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $uniq[] = $e;
        }
        $changes[$u] = $uniq;
    }
    return documentChangesWorkspaceEdit($svc, $changes);
}

/**
 * WorkspaceEdit via `documentChanges` (3.18). `changes` is deprecated by the
 * spec because it cannot carry document versions / resource operations — this
 * implementation is brand new, so it never emits the legacy form.
 *
 * @param array<string, list<array{range: array, newText: string}>> $changes uri → edits
 */
function documentChangesWorkspaceEdit(AnalysisService $svc, array $changes): array
{
    $docEdits = [];
    foreach ($changes as $docUri => $edits) {
        // Ordered by position descending like most clients expect? No — spec
        // applies edits simultaneously; keep source order for readability.
        $docEdits[] = [
            'textDocument' => [
                'uri' => $docUri,
                // Version 0 = unknown on-disk document; open buffers carry
                // their didOpen/didChange version so clients can reject
                // stale rename batches.
                'version' => $svc->vfs->has($docUri) ? $svc->vfs->version($docUri) : 0,
            ],
            'edits' => array_values($edits),
        ];
    }
    return ['documentChanges' => $docEdits];
}

/** WorkspaceEdit (documentChanges form) for edits confined to one document. */
function documentChangesForEdits(AnalysisService $svc, string $uri, array $edits): array
{
    return documentChangesWorkspaceEdit($svc, [$uri => $edits]);
}

function svcPrepareRename(AnalysisService $svc, string $uri, array $pos): ?array
{
    $target = resolveNavTarget($svc, $uri, $pos);
    if ($target !== null) {
        // 3.18: PrepareRenameResult is { range, placeholder } (or range with
        // an embedded placeholder string); a bare Range is not a valid result.
        return ['range' => $target['range'], 'placeholder' => (string) ($target['name'] ?? '')];
    }
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return null;
    }
    $comp = lspPosToCompiler($analysis->source, $pos);
    $word = wordAt($analysis->source, $comp['line'], $comp['col']);
    if ($word === null) {
        return null;
    }
    $lines = splitLines($analysis->source);
    $text = $lines[$comp['line'] - 1] ?? '';
    $idx = max(0, $comp['col'] - 1);
    $left = $idx;
    while ($left > 0 && preg_match('/[A-Za-z0-9_\']/', $text[$left - 1] ?? '')) {
        $left--;
    }
    $right = $idx;
    while ($right < strlen($text) && preg_match('/[A-Za-z0-9_\']/', $text[$right] ?? '')) {
        $right++;
    }
    // 3.18: a bare Range is deprecated here; always return {range, placeholder}
    // so clients can show the rename input with the current name pre-filled.
    return [
        'range' => [
            'start' => compilerPosToLsp($analysis->source, $comp['line'], $left + 1),
            'end' => compilerPosToLsp($analysis->source, $comp['line'], $right + 1),
        ],
        'placeholder' => $word,
    ];
}

/**
 * Find a DeclInfo by name in the given module's index entry, including
 * constructor/method children (which are absent from $analysis->declarations).
 */
