<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Ast;

use function Moggi\Docs\dumpAstTypeForDocs;
use function Moggi\Docs\dumpTypeForDocs;
use function Moggi\Docs\friendlyAstTypeVarNames;
use function Moggi\Docs\surfaceTypeSignature;
use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Index\declTokenMaps;
use function Moggi\LSP\Index\tokenNameRange;
use function Moggi\LSP\Protocol\nodeToLspRange;

function svcInlayHints(AnalysisService $svc, string $uri, array $range): array
{
    if (!$svc->inlaysEnabled) {
        return [];
    }
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $hints = [];
    $walk = null;
    $walk = static function ($node) use (&$walk, &$hints, $analysis): void {
        if (!is_object($node)) {
            return;
        }
        // Function parameters: slice each parameter's type out of the
        // function's own type so the letters match its hover, then continue
        // with the body only (params are handled here, not re-walked).
        if ($node instanceof Ast\FunctionDecl) {
            if ($node->type !== null) {
                $rename = friendlyAstTypeVarNames([$node->type]);
                foreach ($node->params as $i => $param) {
                    if (!($param instanceof Ast\PatVar)
                        || $param->inferredType === null
                        || ($param->line ?? 0) <= 0
                    ) {
                        continue;
                    }
                    $slice = functionParamTypeSlice($node, (int) $i);
                    $t = $slice !== null
                        ? dumpAstTypeForDocs($slice, $rename)
                        : dumpTypeForDocs($param->inferredType);
                    if ($t !== '' && $t !== '()') {
                        $r = nodeToLspRange($analysis->source, $param);
                        $hints[] = [
                            'position' => $r['end'],
                            'label' => ' :: ' . $t,
                            'kind' => 1,
                            'paddingLeft' => true,
                        ];
                    }
                }
            }
            // Non-trivial parameter patterns (tuples, lists, …) keep their
            // inner binders: descend so the generic PatVar branch hints them.
            foreach ($node->params as $param) {
                if (!$param instanceof Ast\PatVar) {
                    $walk($param);
                }
            }
            $walk($node->body);

            return;
        }
        // Other binders (case patterns, let/where, lambda params) and typed
        // holes: render with a per-node friendly rename (t62 → a).
        if ($node instanceof Ast\PatVar
            && $node->inferredType !== null
            && ($node->line ?? 0) > 0
        ) {
            $t = dumpTypeForDocs($node->inferredType);
            if ($t !== '' && $t !== '()') {
                $r = nodeToLspRange($analysis->source, $node);
                $hints[] = [
                    'position' => $r['end'],
                    'label' => ' :: ' . $t,
                    'kind' => 1,
                    'paddingLeft' => true,
                ];
            }
        }
        if ($node instanceof Ast\ExprHole
            && $node->inferredType !== null
            && ($node->line ?? 0) > 0
        ) {
            $t = dumpTypeForDocs($node->inferredType);
            if ($t !== '') {
                $r = nodeToLspRange($analysis->source, $node);
                $hints[] = [
                    'position' => $r['end'],
                    'label' => ' :: ' . $t,
                    'kind' => 1,
                    'paddingLeft' => true,
                ];
            }
        }
        foreach (get_object_vars($node) as $prop) {
            if (is_object($prop)) {
                $walk($prop);
            } elseif (is_array($prop)) {
                foreach ($prop as $el) {
                    if (is_object($el)) {
                        $walk($el);
                    }
                }
            }
        }
    };
    $walk($analysis->program);
    // Also surface hole types from diagnostics when check aborted on the hole
    // (checked AST unavailable, but TypeError carried the refined type).
    foreach ($analysis->diagnostics as $diag) {
        if (($diag['code'] ?? '') !== 'hole') {
            continue;
        }
        $ht = (string) (($diag['data']['holeType'] ?? '') ?: '');
        if ($ht === '' && preg_match('/Found hole with type:\s*`([^`]+)`/', (string) ($diag['message'] ?? ''), $m)) {
            $ht = $m[1];
        }
        if ($ht === '' || !isset($diag['range']['end'])) {
            continue;
        }
        $hints[] = [
            'position' => $diag['range']['end'],
            'label' => ' :: ' . $ht,
            'kind' => 1,
            'paddingLeft' => true,
        ];
    }
    return array_slice($hints, 0, 100);
}

/** @param list<array<string, mixed>> $diagnostics */

/** @param list<array<string, mixed>> $diagnostics */
function holeTypeFromDiagnostics(array $diagnostics, array $pos): string
{
    $line = (int) ($pos['line'] ?? -1);
    $ch = (int) ($pos['character'] ?? -1);
    foreach ($diagnostics as $diag) {
        if (($diag['code'] ?? '') !== 'hole' && !str_contains((string) ($diag['message'] ?? ''), 'hole')) {
            continue;
        }
        $r = $diag['range'] ?? null;
        if (!\is_array($r)) {
            continue;
        }
        $sl = (int) ($r['start']['line'] ?? -2);
        $sc = (int) ($r['start']['character'] ?? 0);
        $el = (int) ($r['end']['line'] ?? $sl);
        $ec = (int) ($r['end']['character'] ?? $sc + 1);
        if ($line < $sl || $line > $el) {
            continue;
        }
        if ($line === $sl && $ch < $sc) {
            continue;
        }
        if ($line === $el && $ch > $ec) {
            continue;
        }
        $ht = (string) (($diag['data']['holeType'] ?? '') ?: '');
        if ($ht === '' && preg_match('/Found hole with type:\s*`([^`]+)`/', (string) ($diag['message'] ?? ''), $m)) {
            $ht = $m[1];
        }
        if ($ht !== '') {
            return $ht;
        }
    }
    return '';
}

function svcInlayHintResolve(AnalysisService $svc, array $params): array
{
    return $params;
}

function svcInlineValues(AnalysisService $svc, string $uri, array $range): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $result = [];
    if ($analysis->program instanceof Ast\Program) {
        $startLine = (int) ($range['start']['line'] ?? 0);
        $endLine = (int) ($range['end']['line'] ?? $startLine);
        // The parser leaves top-level decls unpositioned; derive the decl
        // location from the token map so inline values are not silently empty.
        $maps = declTokenMaps($analysis->source, $uri);
        foreach ($analysis->program->items as $item) {
            if (!($item instanceof Ast\FunctionDecl) || $item->signatureOnly) {
                continue;
            }
            // Types do not live on the decl node: prefer the checked signature,
            // then the declaration table entry, then the node's inferred type.
            $typeStr = null;
            if ($item->type !== null) {
                $typeStr = surfaceTypeSignature($item->type);
            }
            if (($typeStr === null || $typeStr === '') && isset($analysis->declarations[$item->name]['type'])) {
                $candidate = (string) $analysis->declarations[$item->name]['type'];
                if ($candidate !== '' && $candidate !== 'function') {
                    $typeStr = $candidate;
                }
            }
            if (($typeStr === null || $typeStr === '') && $item->inferredType !== null) {
                $typeStr = dumpTypeForDocs($item->inferredType);
            }
            if ($typeStr === null || $typeStr === '') {
                continue;
            }
            $declRange = nodeToLspRange($analysis->source, $item);
            if ((int) $declRange['start']['line'] === 0) {
                $tok = $maps['names'][$item->name] ?? null;
                if ($tok === null) {
                    continue;
                }
                $declRange = tokenNameRange($tok);
            }
            if ((int) $declRange['start']['line'] >= $startLine
                && (int) $declRange['start']['line'] <= $endLine) {
                // InlineValueText {range, text} — the spec's text variant.
                $result[] = [
                    'range' => $declRange,
                    'text' => "{$item->name} :: {$typeStr}",
                ];
            }
        }
    }
    return $result;
}
