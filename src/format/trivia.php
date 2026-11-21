<?php declare(strict_types=1);

namespace Moggi\Format;

/**
 * The plain comments of a source, and where the printer has put them.
 *
 * Doc comments are in the tree: the parser attaches them to the module, an import or a declaration,
 * and the printer writes them back from there. Plain `--` and `{- -}` comments are not: the lexer
 * drops them, so the tree has no record of them at all. Printing that tree would delete them, which
 * is the one outcome a formatter must never choose.
 *
 * So they are read out of the source separately (this file) and placed by position as the printer
 * walks the tree in source order: a comment before a node is emitted above it, one at the end of a
 * line is appended to that line, and one marked `-- ^` belongs to the node just printed. Placement
 * is best effort, but *loss is not*: whatever is left over at the end is a refusal, not a silent
 * deletion.
 *
 * The cursor is static because the printer is a set of functions rather than an object, and every
 * one of them is a pure layout function whose only ambient state is this list.
 */
final class Trivia
{
    /** @var list<array{text: string, line: int, col: int, endLine: int, after: bool}> */
    private static array $pending = [];

    /**
     * Read position in `$pending`.
     *
     * Consuming from the front with `array_shift` renumbered the whole list on every placement, so a
     * file with a comment above each of n declarations paid O(n²) pointer moves. The cursor makes
     * every take O(1); the list itself is never mutated until the next file.
     */
    private static int $cursor = 0;

    /**
     * The line the construct being printed ends at: what an `-- ^` comment may belong to.
     *
     * Set by the caller that knows the next declaration, so `printData` and friends can take the
     * comments that trail them without swallowing one that documents a later declaration.
     */
    public static int $boundary = PHP_INT_MAX;

    /** @param list<array{text: string, line: int, col: int, endLine: int, after: bool}> $comments */
    public static function begin(array $comments): void
    {
        self::$pending = $comments;
        self::$cursor = 0;
    }

    public static function at(int $line): void
    {
        self::$boundary = $line;
    }

    /** Whether a comment begins before `$line`, without consuming it. */
    public static function hasBefore(int $line): bool
    {
        return isset(self::$pending[self::$cursor]) && self::$pending[self::$cursor]['line'] < $line;
    }

    /** @return list<array{text: string, line: int, col: int, endLine: int, after: bool}> */
    public static function pending(): array
    {
        return \array_slice(self::$pending, self::$cursor);
    }

    /** Whether an unplaced comment starts on `$line`; the cursor is not moved. */
    public static function hasCommentOnLine(int $line): bool
    {
        for ($index = self::$cursor; isset(self::$pending[$index]); ++$index) {
            $commentLine = self::$pending[$index]['line'];
            if ($commentLine === $line) {
                return true;
            }
            if ($commentLine > $line) {
                return false;
            }
        }

        return false;
    }

    /**
     * Comments that begin before `$line:$col`, taken off the front of the list.
     *
     * @return list<array{text: string, line: int, col: int, endLine: int, after: bool}>
     */
    public static function takeBefore(int $line, int $col): array
    {
        $taken = [];
        while (isset(self::$pending[self::$cursor])) {
            $comment = self::$pending[self::$cursor];
            if ($comment['line'] > $line || ($comment['line'] === $line && $comment['col'] >= $col)) {
                break;
            }
            $taken[] = $comment;
            ++self::$cursor;
        }

        return $taken;
    }

    /**
     * A comment on `$line` before column `$col`, printed inline in front of what follows it.
     *
     * @return null|array{text: string, line: int, col: int, endLine: int, after: bool}
     */
    public static function takeInline(int $line, int $col): ?array
    {
        $comment = self::$pending[self::$cursor] ?? null;
        if ($comment === null
            || $comment['line'] !== $line || $comment['col'] >= $col || $comment['endLine'] !== $line) {
            return null;
        }

        ++self::$cursor;

        return $comment;
    }

    /**
     * A comment that trails the code on `$line`, printed at the end of that line.
     *
     * @return null|array{text: string, line: int, col: int, endLine: int, after: bool}
     */
    public static function takeTrailing(int $line): ?array
    {
        return self::takeTrailingBefore($line, PHP_INT_MAX);
    }

    /**
     * A comment trailing code on `$line` but starting before `$col`.
     *
     * A comprehension can put several qualifiers on one line, so "same line" is not enough: the
     * comment after `x <- xs, x > 0` belongs to the second qualifier, and taking it for the first
     * moved it one slot left on every save.
     *
     * @return null|array{text: string, line: int, col: int, endLine: int, after: bool}
     */
    public static function takeTrailingBefore(int $line, int $col): ?array
    {
        $comment = self::$pending[self::$cursor] ?? null;
        if ($comment === null || $comment['line'] !== $line || $comment['col'] >= $col) {
            return null;
        }

        ++self::$cursor;

        return $comment;
    }

    /**
     * `-- ^ …` comments that document the construct just printed, before `$line`.
     *
     * @return list<array{text: string, line: int, col: int, endLine: int, after: bool}>
     */
    public static function takeAfter(int $line): array
    {
        $taken = [];
        while (isset(self::$pending[self::$cursor])) {
            $comment = self::$pending[self::$cursor];
            if ($comment['line'] >= $line || ! $comment['after']) {
                break;
            }
            $taken[] = $comment;
            ++self::$cursor;
        }

        return $taken;
    }

    /** Drop every comment: used when the printer refuses, so state cannot leak between files. */
    public static function clear(): void
    {
        self::$pending = [];
        self::$cursor = 0;
        self::$boundary = PHP_INT_MAX;
    }

    /** The first comment still unplaced, or null when every one of them has a home. */
    public static function leftover(): ?array
    {
        return self::$pending[self::$cursor] ?? null;
    }
}

/**
 * Read every plain comment out of a source.
 *
 * The scan is over the gaps between tokens, because a `--` inside a string literal is text and not a
 * comment. The spans the tree owns — string and char literals, and the pragma and doc comment tokens
 * the parser keeps — are collected once and merged, so "is this offset inside the tree's own text?"
 * is a binary search instead of a source copy per token.
 *
 *
 * Look for a comment opening before building the line table and the token spans: a module with
 * no comments is common, and both tables are pure overhead for it.
 * A `--` inside a `--` comment is text, not a second comment: without this, every comment
 * containing a double dash was split in two, and the extra copy made the next pass split two.
 * Unterminated: the lexer has already refused this source, so there is nothing to place.
 * @param list<\Moggi\Syntax\Lexer\Token> $tokens
 * @param null|array<int, int> $lineStarts receives the line table when the scan needs one
 * @return list<array{text: string, line: int, col: int, endLine: int, after: bool}>
 */
function scanPlainComments(string $source, array $tokens, ?array &$lineStarts = null): array
{
    $length = \strlen($source);
    if ($length === 0) {
        return [];
    }

    if (\preg_match_all('/--|\{-/', $source, $matches, \PREG_OFFSET_CAPTURE) === 0) {
        return [];
    }

    $lineStarts = lineStarts($source);
    $spans = treeSpans($source, $tokens, $lineStarts);

    $comments = [];
    $skipUntil = 0;
    foreach ($matches[0] as [$opening, $at]) {
        if ($at < $skipUntil || insideAnySpan($spans, $at)) {
            continue;
        }

        if ($opening === '--') {
            $end = \strpos($source, "\n", $at);
            $end ??= $length;
            $skipUntil = $end + 1;
            $text = \rtrim(\substr($source, $at, $end - $at));
            $line = lineOf($lineStarts, $at);
            $comments[] = [
                'text' => $text,
                'line' => $line,
                'col' => $at - ($lineStarts[$line] ?? 0) + 1,
                'endLine' => $line,
                'after' => \str_starts_with($text, '-- ^'),
            ];

            continue;
        }

        $close = blockCommentEnd($source, $at);
        if ($close === null) {
            continue;
        }
        $skipUntil = $close;

        $text = \rtrim(\substr($source, $at, $close - $at));
        $line = lineOf($lineStarts, $at);
        $endLine = lineOf($lineStarts, $close - 1);
        $comments[] = [
            'text' => $text,
            'line' => $line,
            'col' => $at - ($lineStarts[$line] ?? 0) + 1,
            'endLine' => $endLine,
            'after' => \str_starts_with($text, '{- ^'),
        ];
    }

    return $comments;
}

/**
 * The byte offset just past the `-}` that closes the block comment opening at `$from`.
 *
 * Moggi block comments nest, so the closing brace is the one that brings the depth back to zero.
 */
function blockCommentEnd(string $source, int $from): ?int
{
    $length = \strlen($source);
    $depth = 0;
    for ($at = $from; $at < $length - 1; $at++) {
        $char = $source[$at];
        $next = $source[$at + 1];
        if ($char === '{' && $next === '-') {
            $depth++;
            $at++;

            continue;
        }
        if ($char === '-' && $next === '}') {
            $depth--;
            $at++;
            if ($depth === 0) {
                return $at + 1;
            }
        }
    }

    return null;
}

/**
 * Byte offset of the start of every line; key 1 is the first line.
 *
 * @return array<int, int>
 */
function lineStarts(string $source): array
{
    $starts = [1 => 0];
    $offset = 0;
    foreach (\explode("\n", $source) as $index => $line) {
        $starts[$index + 2] = $offset + \strlen($line) + 1;
        $offset += \strlen($line) + 1;
    }

    return $starts;
}

/** @param array<int, int> $lineStarts */
function lineOf(array $lineStarts, int $offset): int
{
    $low = 1;
    $high = \count($lineStarts);
    while ($low < $high) {
        $mid = ($low + $high + 1) >> 1;
        if (($lineStarts[$mid] ?? PHP_INT_MAX) <= $offset) {
            $low = $mid;
        } else {
            $high = $mid - 1;
        }
    }

    return $low;
}

/**
 * The merged, sorted byte spans whose text the tree reproduces.
 *
 *
 * `doc_block` is a doc comment too: leaving it out of the spans made the scan read `{-| … -}`
 * as a plain comment, and the printer then wrote it out a second time from the tree.
 * A block doc spans every line of its lexeme, so its length is the span, not the line.
 * @param list<\Moggi\Syntax\Lexer\Token> $tokens
 * @param array<int, int> $lineStarts
 * @return list<array{0: int, 1: int}>
 */
function treeSpans(string $source, array $tokens, array $lineStarts): array
{
    $length = \strlen($source);
    $spans = [];

    foreach ($tokens as $token) {
        $kind = $token->kind->value ?? '';
        if ($kind !== 'pragma' && $kind !== 'doc_comment' && $kind !== 'doc_trailing'
            && $kind !== 'doc_block' && $kind !== 'string' && $kind !== 'char') {
            continue;
        }

        $start = ($lineStarts[$token->line] ?? 0) + $token->col - 1;
        if ($start < 0 || $start >= $length) {
            continue;
        }

        if ($kind === 'pragma' || $kind === 'doc_comment' || $kind === 'doc_trailing') {
            $lineEnd = \strpos($source, "\n", $start);
            $end = $lineEnd === false ? $length : $lineEnd;
        } else {
            $end = $start + \strlen($token->lexeme);
        }

        if ($end > $start) {
            $spans[] = [$start, $end];
        }
    }

    \usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

    $merged = [];
    foreach ($spans as [$start, $end]) {
        $last = \count($merged) - 1;
        if ($last >= 0 && $start <= $merged[$last][1]) {
            $merged[$last][1] = \max($merged[$last][1], $end);

            continue;
        }
        $merged[] = [$start, $end];
    }

    return $merged;
}

/** @param list<array{0: int, 1: int}> $spans */
function insideAnySpan(array $spans, int $at): bool
{
    $low = 0;
    $high = \count($spans) - 1;
    while ($low <= $high) {
        $mid = ($low + $high) >> 1;
        if ($spans[$mid][0] > $at) {
            $high = $mid - 1;
        } elseif ($at >= $spans[$mid][1]) {
            $low = $mid + 1;
        } else {
            return true;
        }
    }

    return false;
}

/**
 * A comment as lines at a given indent.
 *
 * A block comment keeps the shape the author gave it: its continuation lines are already indented
 * relative to the first, and the enclosing indent is added in front of each one.
 *
 * @param list<array{text: string, line: int, col: int, endLine: int, after: bool}> $comments
 * @return list<string>
 */
function commentLines(array $comments, int $indent): array
{
    $pad = \str_repeat(' ', $indent);
    $lines = [];
    foreach ($comments as $comment) {
        foreach (\explode("\n", $comment['text']) as $line) {
            $lines[] = $pad . $line;
        }
    }

    return $lines;
}
