<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisResult;
use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Ast;

use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Protocol\splitLines;

function svcCodeActions(AnalysisService $svc, string $uri, array $range, array $context): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $actions = [];
    $diags = $context['diagnostics'] ?? [];
    if ($diags === []) {
        $diags = $analysis->diagnostics;
    }

    foreach ($diags as $diag) {
        $code = $diag['code'] ?? null;
        $msg = (string) ($diag['message'] ?? '');
        $data = \is_array($diag['data'] ?? null) ? $diag['data'] : [];

        if ($code === 'hole' || str_contains($msg, 'hole')) {
            $holeType = (string) ($data['holeType'] ?? '');
            if ($holeType === '' && preg_match('/hole with type `([^`]+)`/', $msg, $hm)) {
                $holeType = $hm[1];
            }
            $stub = holeFillStub($holeType, $svc, $analysis);
            $actions[] = [
                'title' => $stub === 'undefined'
                    ? 'Replace hole with undefined'
                    : "Fill hole with `{$stub}`",
                'kind' => 'quickfix',
                'isPreferred' => true,
                'diagnostics' => [$diag],
                'edit' => documentChangesForEdits($svc, $uri, [[
                            'range' => $diag['range'] ?? $range,
                            'newText' => $stub,
                        ]]),
            ];
            // Extra ctor fills when the type env has several constructors.
            foreach (holeCtorFillCandidates($holeType, $svc, $analysis) as $ctorStub) {
                if ($ctorStub === $stub) {
                    continue;
                }
                $actions[] = [
                    'title' => "Fill hole with `{$ctorStub}`",
                    'kind' => 'quickfix',
                    'diagnostics' => [$diag],
                    'edit' => documentChangesForEdits($svc, $uri, [[
                                'range' => $diag['range'] ?? $range,
                                'newText' => $ctorStub,
                            ]]),
                ];
            }
            if ($stub !== 'undefined') {
                $actions[] = [
                    'title' => 'Replace hole with undefined',
                    'kind' => 'quickfix',
                    'diagnostics' => [$diag],
                    'edit' => documentChangesForEdits($svc, $uri, [[
                                'range' => $diag['range'] ?? $range,
                                'newText' => 'undefined',
                            ]]),
                ];
            }
        }

        if ($code === 'non-exhaustive' || str_contains($msg, 'non-exhaustive')) {
            $missing = $data['missingPatterns'] ?? null;
            if (!\is_array($missing) || $missing === []) {
                $missing = parseMissingPatternsFromMessage($msg);
            }
            if ($missing !== []) {
                $edit = buildExhaustivenessArmEdits($analysis->source, $analysis->program, $diag['range'] ?? $range, $missing);
                if ($edit !== null) {
                    $actions[] = [
                        'title' => 'Insert missing case arms',
                        'kind' => 'quickfix',
                        'isPreferred' => true,
                        'diagnostics' => [$diag],
                        'edit' => documentChangesForEdits($svc, $uri, [$edit]),
                    ];
                }
            }
        }

        if (preg_match('/undefined (?:variable|name) `([^`]+)`/', $msg, $m)) {
            $name = $m[1];
            foreach ($svc->modules->modules as $mod) {
                foreach ($mod['decls'] as $decl) {
                    if ($decl->name === $name && $decl->module !== '') {
                        $edits = buildAutoImportEdits($analysis->source, $uri, $decl->module);
                        if ($edits !== []) {
                            $actions[] = [
                                'title' => "Import {$decl->module} for `{$name}`",
                                'kind' => 'quickfix',
                                'isPreferred' => true,
                                'edit' => documentChangesForEdits($svc, $uri, $edits),
                            ];
                        }
                        break 2;
                    }
                }
            }
        }
    }

    if ($analysis->program instanceof Ast\Program) {
        foreach ($analysis->declarations as $name => $decl) {
            $type = $decl['type'] ?? '';
            if ($type === '' || $type === 'function' || in_array($type, ['data', 'newtype', 'type', 'class'], true)) {
                continue;
            }
            if (preg_match('/^' . preg_quote($name, '/') . '\s*::/m', $analysis->source)) {
                continue;
            }
            $line = $decl['line'] - 1;
            $actions[] = [
                'title' => "Add type signature for `{$name}`",
                'kind' => 'refactor',
                'edit' => documentChangesForEdits($svc, $uri, [[
                            'range' => [
                                'start' => ['line' => $line, 'character' => 0],
                                'end' => ['line' => $line, 'character' => 0],
                            ],
                            'newText' => "{$name} :: {$type}\n",
                        ]]),
            ];
        }
    }

    return $actions;
}

/** Prefer typed stubs; undefined is last resort. */

/** Prefer typed stubs; undefined is last resort. */
function holeFillStub(string $type, ?AnalysisService $svc = null, ?AnalysisResult $analysis = null): string
{
    $t = normalizeHoleType($type);
    if ($t === '' || $t === '?' || $t === '_') {
        return 'undefined';
    }
    if ($t === 'Int' || $t === 'Integer' || $t === 'Word' || preg_match('/^Word\d+$/', $t)) {
        return '0';
    }
    if ($t === 'Double' || $t === 'Float') {
        return '0.0';
    }
    if ($t === 'Bool') {
        return 'False';
    }
    if ($t === 'Ordering') {
        return 'EQ';
    }
    if ($t === 'Char') {
        return "'\\0'";
    }
    if ($t === 'String' || $t === 'Text') {
        return '""';
    }
    if ($t === '()' || $t === 'Unit') {
        return '()';
    }
    if (preg_match('/^List\b/', $t) || str_starts_with($t, '[')) {
        return '[]';
    }
    if (preg_match('/^Maybe\b/', $t)) {
        return 'Nothing';
    }
    if (preg_match('/^Either\b/', $t)) {
        return 'Left undefined';
    }
    if (preg_match('/^IO\s*\(\)/', $t) || $t === 'IO ()') {
        return 'pure ()';
    }
    if (preg_match('/^IO\b/', $t)) {
        return 'pure undefined';
    }
    // Tuple: (a, b, …)
    if (preg_match('/^\((.+)\)$/', $t, $tm) && str_contains($tm[1], ',')) {
        $arity = substr_count($tm[1], ',') + 1;
        return '(' . implode(', ', array_fill(0, $arity, 'undefined')) . ')';
    }
    if (str_contains($t, '->')) {
        $arrows = substr_count($t, '->');
        $params = implode(' ', array_fill(0, min(3, $arrows), '_'));
        return '\\' . $params . ' -> undefined';
    }
    $ctors = holeCtorFillCandidates($t, $svc, $analysis);
    if ($ctors !== []) {
        return $ctors[0];
    }
    return 'undefined';
}

function normalizeHoleType(string $type): string
{
    $t = trim($type);
    while (str_starts_with($t, '(') && str_ends_with($t, ')')) {
        $t = trim(substr($t, 1, -1));
    }
    if (preg_match('/^(.*?)\s*=>\s*(.+)$/', $t, $m)) {
        $t = trim($m[2]);
    }
    // Drop applied args: `Maybe Int` → `Maybe`
    if (preg_match('/^([A-Z][A-Za-z0-9_\']*)\b/', $t, $hm)) {
        if (str_contains($t, ' ') && !str_contains($t, '->')) {
            return $hm[1];
        }
    }
    return $t;
}

/**
 * Constructor stubs from the current program / ModuleIndex (nullary preferred).
 *
 * @return list<string>
 */

/**
 * Constructor stubs from the current program / ModuleIndex (nullary preferred).
 *
 * @return list<string>
 */
function holeCtorFillCandidates(string $type, ?AnalysisService $svc, ?AnalysisResult $analysis): array
{
    $t = normalizeHoleType($type);
    if ($t === '' || !preg_match('/^[A-Z][A-Za-z0-9_\']*$/', $t)) {
        return [];
    }
    $out = [];
    $program = $analysis?->program;
    if ($program instanceof Ast\Program) {
        foreach ($program->items as $item) {
            if (!$item instanceof Ast\DataDecl || $item->name !== $t) {
                continue;
            }
            foreach ($item->constructors as $ctor) {
                $arity = count($ctor->fields);
                if ($arity === 0) {
                    $out[] = $ctor->name;
                } else {
                    $out[] = $ctor->name . ' ' . implode(' ', array_fill(0, $arity, 'undefined'));
                }
            }
        }
    }
    if ($out === [] && $svc !== null) {
        foreach ($svc->modules->defsByResolved as $decl) {
            if ($decl->name !== $t || $decl->kind !== 23) {
                continue;
            }
            foreach ($decl->children as $child) {
                // Index lacks arity — prefer bare name (nullary / newtype unwrap).
                $out[] = $child->name;
            }
            break;
        }
    }
    // Prefer nullary / shorter stubs first.
    usort($out, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));
    return array_values(array_unique($out));
}

/**
 * @return list<string>
 */

/**
 * @return list<string>
 */
function parseMissingPatternsFromMessage(string $msg): array
{
    if (preg_match('/missing patterns:\s*(.+?)(?:;|$)/', $msg, $m)) {
        $parts = preg_split('/,\s*/', trim($m[1])) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
    }
    if (str_contains($msg, 'catch-all')) {
        return ['_'];
    }
    return [];
}

/**
 * Insert missing arms after the last alternative of the nearest case.
 *
 * @param list<string> $missing
 * @return array{range: array, newText: string}|null
 */

/**
 * Insert missing arms after the last alternative of the nearest case.
 *
 * @param list<string> $missing
 * @return array{range: array, newText: string}|null
 */
function buildExhaustivenessArmEdits(string $source, object $program, array $diagRange, array $missing): ?array
{
    $startLine = (int) ($diagRange['start']['line'] ?? 0);
    $caseExpr = findCaseNearLine($program, $startLine + 1);
    $indent = '  ';
    $insertLine = $startLine;
    if ($caseExpr instanceof Ast\CaseExpr && $caseExpr->alts !== []) {
        $last = $caseExpr->alts[count($caseExpr->alts) - 1];
        $insertLine = max($startLine, (int) ($last->line ?? $caseExpr->line ?? $startLine));
        $lines = splitLines($source);
        $armLine = $lines[$insertLine - 1] ?? '';
        if (preg_match('/^(\s*)/', $armLine, $im)) {
            $indent = $im[1] !== '' ? $im[1] : '  ';
        }
    } else {
        $lines = splitLines($source);
        $armLine = $lines[$startLine] ?? '';
        if (preg_match('/^(\s*)/', $armLine, $im) && $im[1] !== '') {
            $indent = $im[1];
        }
    }

    $arms = '';
    foreach ($missing as $pat) {
        $arms .= "\n{$indent}{$pat} -> undefined";
    }
    $lines = splitLines($source);
    $lineText = $lines[$insertLine] ?? ($lines[$insertLine - 1] ?? '');
    // Insert after the end of the last arm line (0-based LSP line = insertLine for 1-based last arm).
    $lspLine = max(0, $insertLine - 1);
    if ($caseExpr instanceof Ast\CaseExpr && $caseExpr->alts !== []) {
        $lspLine = max(0, $insertLine - 1);
        $lineText = $lines[$lspLine] ?? '';
    }
    $endChar = strlen($lineText);
    return [
        'range' => [
            'start' => ['line' => $lspLine, 'character' => $endChar],
            'end' => ['line' => $lspLine, 'character' => $endChar],
        ],
        'newText' => $arms,
    ];
}

function findCaseNearLine(object $node, int $line): ?Ast\CaseExpr
{
    $best = null;
    $bestDist = PHP_INT_MAX;
    $walk = null;
    $walk = static function ($n) use (&$walk, &$best, &$bestDist, $line): void {
        if (!is_object($n)) {
            return;
        }
        if ($n instanceof Ast\CaseExpr && ($n->line ?? 0) > 0) {
            $dist = abs(($n->line ?? 0) - $line);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $n;
            }
        }
        foreach (get_object_vars($n) as $prop) {
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
    $walk($node);
    return $best;
}

/**
 * Rewrite imports when a file is renamed. Supports nested module paths
 * (`Data/Word.mog` → `Data.Word`) and qualified / `as` imports.
 *
 * @return array<string, list<array{range: array, newText: string}>>
 */
/**
 * workspace/willDeleteFiles: when a .mog file (or a directory of them) is
 * deleted, remove the now-dangling `import` lines from open documents.
 *
 * @return array<string, list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}}>
 */

function svcCodeActionResolve(AnalysisService $svc, array $params): array
{
    return $params;
}
