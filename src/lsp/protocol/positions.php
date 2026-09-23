<?php declare(strict_types=1);

namespace Moggi\LSP\Protocol;

function lspToCompiler(string $source, array $pos): array
{
    $lines = splitLines($source);
    $line0 = max(0, (int) ($pos['line'] ?? 0));
    $text = $lines[$line0] ?? '';
    $col = utf16ToCodepointCol($text, (int) ($pos['character'] ?? 0));
    return ['line' => $line0 + 1, 'col' => $col];
}

/**
 * Convert a compiler line/col to an LSP position (0-based).
 *
 * @return array{line: int, character: int}
 */
function compilerToLsp(int $line, int $col): array
{
    return ['line' => max(0, $line - 1), 'character' => max(0, $col - 1)];
}

/**
 * Build an LSP Range from a compiler line/col (1-based) plus an end column.
 *
 * @param string|null $source Optional document source so character offsets are
 *   converted from codepoints to UTF-16 units (3.17+ position encoding).
 *   Without it, codepoint columns pass through — correct for ASCII-only lines,
 *   which is the only safe assumption at context-free call sites.
 * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
 */
function locToRange(int $line, int $col, int $endCol, ?string $source = null): array
{
    if ($endCol < $col) {
        $endCol = $col;
    }

    if ($source !== null) {
        return [
            'start' => compilerPosToLsp($source, $line, $col),
            'end' => compilerPosToLsp($source, $line, $endCol + 1),
        ];
    }

    return [
        'start' => compilerToLsp($line, $col),
        'end' => compilerToLsp($line, $endCol + 1),
    ];
}

/**
 * UTF-16 ↔ compiler (1-based codepoint) position helpers.
 *
 * LSP uses UTF-16 code units; the Moggi compiler uses 1-based codepoint columns.
 * Surrogate pairs are handled for correctness on non-BMP text.
 */
function utf16Length(string $text): int
{
    $units = 0;
    $len = strlen($text);
    $i = 0;
    while ($i < $len) {
        $ord = ord($text[$i]);
        if ($ord < 0x80) {
            $cp = $ord;
            $i += 1;
        } elseif (($ord & 0xE0) === 0xC0) {
            $cp = (($ord & 0x1F) << 6) | (ord($text[$i + 1]) & 0x3F);
            $i += 2;
        } elseif (($ord & 0xF0) === 0xE0) {
            $cp = (($ord & 0x0F) << 12)
                | ((ord($text[$i + 1]) & 0x3F) << 6)
                | (ord($text[$i + 2]) & 0x3F);
            $i += 3;
        } else {
            $cp = (($ord & 0x07) << 18)
                | ((ord($text[$i + 1]) & 0x3F) << 12)
                | ((ord($text[$i + 2]) & 0x3F) << 6)
                | (ord($text[$i + 3]) & 0x3F);
            $i += 4;
        }
        $units += ($cp > 0xFFFF) ? 2 : 1;
    }
    return $units;
}

/** Convert 1-based codepoint column to 0-based UTF-16 character offset. */
function codepointColToUtf16(string $lineText, int $col1): int
{
    if ($col1 <= 1) {
        return 0;
    }
    $target = $col1 - 1;
    $cp = 0;
    $units = 0;
    $len = strlen($lineText);
    $i = 0;
    while ($i < $len && $cp < $target) {
        $ord = ord($lineText[$i]);
        if ($ord < 0x80) {
            $code = $ord;
            $i += 1;
        } elseif (($ord & 0xE0) === 0xC0) {
            $code = (($ord & 0x1F) << 6) | (ord($lineText[$i + 1]) & 0x3F);
            $i += 2;
        } elseif (($ord & 0xF0) === 0xE0) {
            $code = (($ord & 0x0F) << 12)
                | ((ord($lineText[$i + 1]) & 0x3F) << 6)
                | (ord($lineText[$i + 2]) & 0x3F);
            $i += 3;
        } else {
            $code = (($ord & 0x07) << 18)
                | ((ord($lineText[$i + 1]) & 0x3F) << 12)
                | ((ord($lineText[$i + 2]) & 0x3F) << 6)
                | (ord($lineText[$i + 3]) & 0x3F);
            $i += 4;
        }
        $units += ($code > 0xFFFF) ? 2 : 1;
        $cp++;
    }
    return $units;
}

/** Convert 0-based UTF-16 character to 1-based codepoint column. */
function utf16ToCodepointCol(string $lineText, int $utf16Char0): int
{
    if ($utf16Char0 <= 0) {
        return 1;
    }
    $units = 0;
    $cp = 0;
    $len = strlen($lineText);
    $i = 0;
    while ($i < $len && $units < $utf16Char0) {
        $ord = ord($lineText[$i]);
        if ($ord < 0x80) {
            $code = $ord;
            $i += 1;
        } elseif (($ord & 0xE0) === 0xC0) {
            $code = (($ord & 0x1F) << 6) | (ord($lineText[$i + 1]) & 0x3F);
            $i += 2;
        } elseif (($ord & 0xF0) === 0xE0) {
            $code = (($ord & 0x0F) << 12)
                | ((ord($lineText[$i + 1]) & 0x3F) << 6)
                | (ord($lineText[$i + 2]) & 0x3F);
            $i += 3;
        } else {
            $code = (($ord & 0x07) << 18)
                | ((ord($lineText[$i + 1]) & 0x3F) << 12)
                | ((ord($lineText[$i + 2]) & 0x3F) << 6)
                | (ord($lineText[$i + 3]) & 0x3F);
            $i += 4;
        }
        $add = ($code > 0xFFFF) ? 2 : 1;
        if ($units + $add > $utf16Char0) {
            break;
        }
        $units += $add;
        $cp++;
    }
    return $cp + 1;
}

/** @return list<string> */
function splitLines(string $source): array
{
    // Occurrence indexing calls nodeToLspRange per AST node; re-splitting the
    // same buffer thousands of times was burning CPU like an infinite loop.
    static $cachedSource = null;
    static $cachedLines = null;
    if ($cachedSource === $source && \is_array($cachedLines)) {
        return $cachedLines;
    }
    $cachedSource = $source;
    $cachedLines = preg_split("/\r\n|\n|\r/", $source) ?: [];

    return $cachedLines;
}

/**
 * @param array{line: int, character: int} $pos
 * @return array{line: int, col: int}
 */
function lspPosToCompiler(string $source, array $pos): array
{
    $lines = splitLines($source);
    $line0 = max(0, (int) ($pos['line'] ?? 0));
    $text = $lines[$line0] ?? '';
    $col = utf16ToCodepointCol($text, (int) ($pos['character'] ?? 0));
    return ['line' => $line0 + 1, 'col' => $col];
}

/**
 * @return array{line: int, character: int}
 */
function compilerPosToLsp(string $source, int $line, int $col): array
{
    $lines = splitLines($source);
    $text = $lines[max(0, $line - 1)] ?? '';
    return [
        'line' => max(0, $line - 1),
        'character' => codepointColToUtf16($text, $col),
    ];
}

/**
 * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
 */
function nodeToLspRange(string $source, object $node): array
{
    $line = (int) ($node->line ?? 1);
    $col = (int) ($node->col ?? 1);
    $endLine = (int) (($node->endLine ?? 0) ?: $line);
    $endCol = (int) ($node->endCol ?? $col);
    return [
        'start' => compilerPosToLsp($source, $line, $col),
        'end' => compilerPosToLsp($source, $endLine, $endCol + 1),
    ];
}

/** Byte offset into $line for a 0-based UTF-16 character index (approx via codepoint walk). */
function utf16ByteOffset(string $line, int $utf16Char0): int
{
    if ($utf16Char0 <= 0) {
        return 0;
    }
    $units = 0;
    $len = strlen($line);
    $i = 0;
    while ($i < $len && $units < $utf16Char0) {
        $ord = ord($line[$i]);
        if ($ord < 0x80) {
            $code = $ord;
            $step = 1;
        } elseif (($ord & 0xE0) === 0xC0) {
            $code = 0x80;
            $step = 2;
        } elseif (($ord & 0xF0) === 0xE0) {
            $code = 0x800;
            $step = 3;
        } else {
            $code = 0x10000;
            $step = 4;
        }
        $add = ($code > 0xFFFF) ? 2 : 1;
        if ($units + $add > $utf16Char0) {
            break;
        }
        $units += $add;
        $i += $step;
    }
    return $i;
}
