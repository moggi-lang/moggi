<?php declare(strict_types=1);

namespace Moggi\LSP\Formatter;

use Moggi\Format\FormatError;
use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Format\formatSource;
use function Moggi\LSP\Protocol\splitLines;

/**
 * `textDocument/formatting` — the canonical layout, as one edit over the whole document.
 *
 * The formatter is `Moggi\Format\formatSource`, the same one `moggi format` writes with and the test
 * suite pins. There used to be a second, simpler formatter here; a language server whose editor
 * "Format Document" and `moggi format` disagree about the layout gives every file two canonical
 * answers, so this side now only decides *whether* to send an edit.
 *
 * A source the formatter refuses is not formatted: no edit goes back, and the buffer is left exactly
 * as the user typed it. That is what keeps a comment the formatter could not place (or a file caught
 * mid-edit) from being rewritten with a piece missing.
 */
function svcFormatDocument(AnalysisService $svc, string $uri): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }

    $formatted = formatMoggiSource($analysis->source, $analysis->importedFixity);
    if ($formatted === null || $formatted === $analysis->source) {
        return [];
    }

    return [[
        'range' => wholeDocumentRange($analysis->source),
        'newText' => $formatted,
    ]];
}

/**
 * Format only the top-level declarations that intersect `$range`.
 *
 * The selection is formatted as a synthetic module: the formatter only ever sees complete
 * declarations, so a range that cuts through one still produces a layout the file can hold. A
 * selection the formatter refuses produces no edit at all.
 *
 * @param array{start?: array{line?:int,character?:int}, end?: array{line?:int,character?:int}} $range
 * @return list<array{range: array, newText: string}>
 */
function svcFormatRange(AnalysisService $svc, string $uri, array $range): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $source = $analysis->source;
    $selStart = ((int) ($range['start']['line'] ?? 0)) + 1;
    $selEnd = ((int) ($range['end']['line'] ?? 0)) + 1;
    if ($selEnd < $selStart) {
        [$selStart, $selEnd] = [$selEnd, $selStart];
    }

    $lines = splitLines($source);
    $picked = [];
    foreach (topLevelSourceRegions($source, $lines) as $region) {
        if ($region['end'] < $selStart || $region['start'] > $selEnd || $region['kind'] === 'header') {
            continue;
        }
        $picked[] = $region;
    }
    if ($picked === []) {
        return [];
    }

    $spanStart = $picked[0]['start'];
    $spanEnd = $picked[\array_key_last($picked)]['end'];
    $slice = sourceLinesSlice($source, $spanStart, $spanEnd, $lines);

    $synthetic = "module RangeFmt where\n\n" . $slice;
    if (! \str_ends_with($synthetic, "\n")) {
        $synthetic .= "\n";
    }

    $formattedFull = formatMoggiSource($synthetic, $analysis->importedFixity);
    if ($formattedFull === null) {
        return [];
    }
    $formattedBody = \preg_replace('/^module\s+RangeFmt\b.*\n+/', '', $formattedFull) ?? $formattedFull;
    $newText = \rtrim(\ltrim($formattedBody, "\n"));
    if ($newText === \rtrim($slice)) {
        return [];
    }

    $startIdx = \max(0, $spanStart - 1);
    $endIdx = \min(\count($lines) - 1, $spanEnd - 1);

    return [[
        'range' => [
            'start' => ['line' => $startIdx, 'character' => 0],
            'end' => ['line' => $endIdx, 'character' => \strlen($lines[$endIdx] ?? '')],
        ],
        'newText' => $newText,
    ]];
}

/** The whole document, as an LSP range. */
function wholeDocumentRange(string $source): array
{
    $lines = splitLines($source);
    $lastLine = \max(0, \count($lines) - 1);

    return [
        'start' => ['line' => 0, 'character' => 0],
        'end' => ['line' => $lastLine, 'character' => \strlen($lines[$lastLine] ?? '')],
    ];
}

/**
 * The canonical text, or null when the formatter refuses this source.
 *
 * A refusal is not an error the server reports on every keystroke: the document is simply left as it
 * is, which is the same thing `moggi format` does with the file.
 */
function formatMoggiSource(string $source, array $importedFixity = []): ?string
{
    try {
        return formatSource($source, '<format>', false, $importedFixity);
    } catch (FormatError|LexError|ParseError) {
        return null;
    }
}

/**
 * Top-level source regions (1-based inclusive line spans).
 *
 * @return list<array{start: int, end: int, kind: string}>
 */
function topLevelSourceRegions(string $source, ?array $lines = null): array
{
    $lines ??= splitLines($source);
    $n = \count($lines);
    $starts = [];
    for ($i = 0; $i < $n; $i++) {
        $trim = \ltrim($lines[$i]);
        if ($trim === '' || \str_starts_with($trim, '--')) {
            continue;
        }
        $kind = null;
        if (\preg_match('/^(module|import)\b/', $trim)) {
            $kind = 'header';
        } elseif (\preg_match('/^(data|newtype|type|class|instance|foreign|pattern|infix)\b/', $trim)) {
            $kind = 'decl';
        } elseif (\preg_match('/^[A-Za-z_][A-Za-z0-9_\']*(\s*::|\s*=)/', $trim)) {
            $kind = 'decl';
        }
        if ($kind !== null) {
            $starts[] = ['line' => $i + 1, 'kind' => $kind, 'name' => topLevelBindingName($trim)];
        }
    }
    if ($starts === []) {
        return [];
    }

    $merged = [];
    $m = \count($starts);
    for ($i = 0; $i < $m; $i++) {
        $cur = $starts[$i];
        $endLine = ($i + 1 < $m) ? ($starts[$i + 1]['line'] - 1) : $n;
        while (
            $i + 1 < $m
            && $cur['kind'] === 'decl'
            && $starts[$i + 1]['kind'] === 'decl'
            && $cur['name'] !== null
            && $cur['name'] === $starts[$i + 1]['name']
        ) {
            $i++;
            $endLine = ($i + 1 < $m) ? ($starts[$i + 1]['line'] - 1) : $n;
        }
        while ($endLine > $cur['line'] && \trim($lines[$endLine - 1] ?? '') === '') {
            $endLine--;
        }

        $startLine = $cur['line'];
        for ($l = $startLine - 1; $l >= 1; $l--) {
            $line = $lines[$l - 1] ?? '';
            if (\preg_match('/^\s*--/', $line)) {
                $startLine = $l;

                continue;
            }
            if (\trim($line) === '') {
                break;
            }

            break;
        }
        $merged[] = [
            'start' => $startLine,
            'end' => \max($startLine, $endLine),
            'kind' => $cur['kind'],
        ];
    }

    return $merged;
}

function topLevelBindingName(string $trim): ?string
{
    if (\preg_match('/^([A-Za-z_][A-Za-z0-9_\']*)\s*::/', $trim, $m)) {
        return $m[1];
    }
    if (\preg_match('/^([A-Za-z_][A-Za-z0-9_\']*)(\s|=)/', $trim, $m)) {
        return $m[1];
    }

    return null;
}

function sourceLinesSlice(string $source, int $startLine, int $endLine, ?array $lines = null): string
{
    $lines ??= splitLines($source);
    $slice = \array_slice($lines, \max(0, $startLine - 1), \max(0, $endLine - $startLine + 1));

    return \implode("\n", $slice) . "\n";
}
