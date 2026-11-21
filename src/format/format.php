<?php declare(strict_types=1);

namespace Moggi\Format;

use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

/**
 * A source text the formatter will not format.
 *
 * Raised for two different reasons, and the distinction matters: a *comment it cannot place* (which
 * must never be dropped, so the file is refused instead), and a *construct the printer has not
 * reached yet*. Both fail closed — the alternative is rewriting a file with pieces missing.
 */
final class FormatError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $path = null,
        public readonly ?int $atLine = null,
        public readonly ?int $atColumn = null,
    ) {
        parent::__construct($message);
    }

    public function display(): string
    {
        $where = $this->path ?? '<input>';
        if ($this->atLine !== null && $this->atColumn !== null) {
            $where .= ':' . $this->atLine . ':' . $this->atColumn;
        }

        return $where . ': format error: ' . $this->getMessage() . "\n";
    }
}

/**
 * The canonical source of `$source`, at the fixed width where the text has a break point.
 *
 * `lex → parse → print`, and nothing else: no checker, no backend. `$importedFixity` is the fixity
 * the file's imports bring into scope, so the parser builds the tree the compiler would and the
 * printer can flatten an imported chain without inventing parentheses. A caller with no module
 * context can omit it; standard operators still have their language fixity. A text that does not
 * parse is not formatted at all, so the lexer's or parser's own diagnostic is what the caller sees.
 * The 100-column target is best effort: an expression or literal with no valid break point is
 * printed on one line even when it is longer, rather than being refused or split.
 *
 * @param array<string, array{assoc: string, prec: int}> $importedFixity
 *
 * Comments are the one thing the tree cannot speak for. Plain comments are dropped by the lexer, so
 * they are read out of the source separately and placed by position while printing; whatever cannot
 * be placed is a refusal, never a deletion. Doc comments are in the tree where the parser attached
 * them and are checked against the output, so one written where the parser does not look is a
 * refusal too.
 *
 * Parsing the output again would catch a printer bug that emits something unparseable, but it is a
 * second full parse of the file — the same work the first one did — so it is off by default and the
 * suite turns it on (`verifyOutput: true`), where a printer bug is a test failure instead of a slow
 * save. Formatting a file stays one lex and one parse.
 */
function formatSource(
    string $source,
    ?string $path = null,
    bool $verifyOutput = false,
    array $importedFixity = [],
): string {
    $path ??= '<input>';

    $tokens = lex($source, $path);
    $program = parse($tokens, $source, $path, $importedFixity);

    $lineStarts = null;
    $plainComments = scanPlainComments($source, $tokens, $lineStarts);
    Trivia::begin(commentsNotInDocs($plainComments, $tokens, $source, $lineStarts));
    try {
        $formatted = printProgram($program, $importedFixity);
        $leftover = Trivia::pending();
    } finally {
        Trivia::clear();
    }

    $fileTrailing = [];
    $lines = $leftover === [] ? [] : \explode("\n", $source);
    foreach (commentBlocks($leftover) as $block) {
        $first = $block[0];
        if (! followsBlankLine($lines, $first['line'])) {
            throw new FormatError(
                'cannot place a comment here, and dropping it is not an option',
                $path,
                $first['line'],
                $first['col'],
            );
        }

        foreach ($block as $comment) {
            $fileTrailing[] = $comment;
        }
    }

    if ($fileTrailing !== []) {
        $formatted = \rtrim($formatted, "\n") . "\n\n"
            . \implode("\n", commentLines($fileTrailing, 0)) . "\n";
    }

    assertEveryDocCommentSurvives($tokens, $formatted, $path);

    if ($verifyOutput) {
        verifyFormattedTextParses($formatted, $path, $importedFixity);
    }

    return $formatted;
}

/**
 * Consecutive comments grouped into the blocks the author wrote them as.
 *
 * A block belongs to the file when a blank line precedes its *first* line; checking every line of it
 * refused the second line onwards of a block that sits at the end of a file.
 *
 * @param list<array{text: string, line: int, col: int, endLine: int, after: bool}> $comments
 * @return list<list<array{text: string, line: int, col: int, endLine: int, after: bool}>>
 */
function commentBlocks(array $comments): array
{
    $blocks = [];
    $lastEnd = null;
    $last = -1;
    foreach ($comments as $comment) {
        if ($last < 0 || $comment['line'] > $lastEnd + 1) {
            $blocks[] = [$comment];
            ++$last;
        } else {
            $blocks[$last][] = $comment;
        }

        $lastEnd = $comment['endLine'];
    }

    return $blocks;
}

/**
 * Whether the line above `$line` is blank, so a comment there belongs to the file.
 *
 * @param list<string> $lines the source split once by the caller; splitting it per trailing
 *   comment made the refusal path O(comments × file size)
 */
function followsBlankLine(array $lines, int $line): bool
{
    if ($line <= 1) {
        return false;
    }

    $previous = $lines[$line - 2] ?? null;

    return $previous !== null && \trim($previous) === '';
}

/**
 * Drop the comments a doc already carries.
 *
 * The parser folds the `--` lines that continue a doc comment into the doc text, and the printer
 * writes them back out of the tree; the scan reads them as comments of their own. Dropping these
 * by text alone was wrong: a real comment elsewhere with the same text as a doc line disappeared
 * without a word, which is the one thing a formatter must never do. A comment is only taken to be
 * part of a doc when it sits on a line the doc occupies and holds the same text.
 *
 * @param list<array{text: string, line: int, col: int, endLine: int, after: bool}> $comments
 * @param list<\Moggi\Syntax\Lexer\Token> $tokens
 * @param null|array<int, int> $starts the line table already built by the comment scan, if any
 * @return list<array{text: string, line: int, col: int, endLine: int, after: bool}>
 */
function commentsNotInDocs(array $comments, array $tokens, string $source, ?array $starts = null): array
{
    /** @var array<int, array<string, true>> $docLines line => texts the doc there holds */
    $docLines = [];
    $sourceLines = null;
    foreach ($tokens as $token) {
        $kind = $token->kind->value ?? '';

        if ($kind !== 'doc_comment' && $kind !== 'doc_section' && $kind !== 'doc_trailing'
            && $kind !== 'doc_block') {
            continue;
        }

        $starts ??= lineStarts($source);

        if ($kind === 'doc_block') {
            $start = ($starts[$token->line] ?? 0) + $token->col - 1;
            $last = lineOf($starts, \max($start, (blockCommentEnd($source, $start) ?? $start + 1) - 1));
            $sourceLines ??= \explode("\n", $source);
            for ($line = $token->line; $line <= $last; ++$line) {
                $docLines[$line][docText($sourceLines[$line - 1] ?? '')] = true;
            }

            continue;
        }

        $first = $token->line;
        if (docMarkerOnly($source, $starts, $first)) {
            ++$first;
        }

        foreach (\explode("\n", $token->lexeme) as $offset => $line) {
            $docLines[$first + $offset][docText($line)] = true;
        }
    }

    if ($docLines === []) {
        return $comments;
    }

    $kept = [];
    foreach ($comments as $comment) {
        if (! holdsOnlyDocText($comment, $docLines)) {
            $kept[] = $comment;
        }
    }

    return $kept;
}

/**
 * Whether `$line` holds a doc marker and nothing else, as `-- |` on a line of its own does.
 *
 * A doc token's lexeme begins at its first line of *text*, so a marker alone on its line leaves the
 * lexeme one line shorter than the source. Mapping the lexeme from the marker's line put every
 * later line one line off, and those lines were then read as plain comments, with nothing left to
 * place them and the whole file refused.
 *
 * @param array<int, int> $starts
 */
function docMarkerOnly(string $source, array $starts, int $line): bool
{
    $start = $starts[$line] ?? null;
    if ($start === null) {
        return false;
    }

    $end = \strpos($source, "\n", $start);
    $text = \substr($source, $start, $end === false ? null : $end - $start);

    return \preg_match('/^\s*(?:--\s*[|^]?|\{-[|^]?)\s*$/', $text) === 1;
}

/**
 * Whether every line of a scanned comment is a line a doc comment already holds.
 *
 * A block comment is one scanned comment over several lines, so each of its lines has to line up
 * with the doc's own text before it counts as belonging to the doc.
 *
 * @param array{text: string, line: int, col: int, endLine: int, after: bool} $comment
 * @param array<int, array<string, true>> $docLines
 */
function holdsOnlyDocText(array $comment, array $docLines): bool
{
    foreach (\explode("\n", $comment['text']) as $offset => $line) {
        if (! isset($docLines[$comment['line'] + $offset][docText($line)])) {
            return false;
        }
    }

    return true;
}

/**
 * The prose a doc or comment line carries, with its marker and leading `|`/`^` removed.
 *
 * Repeatedly: a doc line that is a commented-out example (`-- -- equivalent to [1]`) is stored with
 * one marker stripped, while the scan reads it with both, and comparing the two forms made the
 * example look like a plain comment of its own — printed a second time inside the export list.
 */
function docText(string $line): string
{
    $text = \trim($line);
    while (\str_starts_with($text, '-') || \str_starts_with($text, '{')) {
        $stripped = \trim((string) \preg_replace('/^(-{2,}|\{-)?\s*[|^]?\s*/', '', $text));
        if ($stripped === $text) {
            break;
        }
        $text = $stripped;
    }

    return $text;
}

/**
 * Refuse a printed text that does not parse back.
 *
 * A layout bug must be a refusal, not a write the next save would fight with. Only worth its second
 * parse where a bug should be loud rather than fast: the suite, not every keystroke.
 */
function verifyFormattedTextParses(string $formatted, string $path, array $importedFixity = []): void
{
    try {
        parse(lex($formatted, $path), $formatted, $path, $importedFixity);
    } catch (\Throwable $e) {
        throw new FormatError(
            'the formatted text does not parse, so it is not written: ' . $e->getMessage(),
            $path,
        );
    }
}

/**
 * Refuse an output that does not carry every doc comment the source had.
 *
 * The parser attaches a doc comment to the module, an import or a declaration, and the printer writes
 * it back from there. One written where the parser does not look — `-- |` on a record field, for
 * instance — is not in the tree at all, so printing would delete it. Its text appearing in the output
 * is the cheap, exact check: doc text is reproduced verbatim, minus the marker.
 *
 * @param list<\Moggi\Syntax\Lexer\Token> $tokens
 */
function assertEveryDocCommentSurvives(array $tokens, string $formatted, string $path): void
{
    foreach ($tokens as $token) {
        $kind = $token->kind->value ?? '';
        if ($kind !== 'doc_comment' && $kind !== 'doc_trailing' && $kind !== 'doc_block') {
            continue;
        }

        foreach (\preg_split('/\R/', $token->lexeme) ?: [] as $line) {
            $text = \trim(docText($line), "} \t");
            if ($text === '' || \str_contains($formatted, $text)) {
                continue;
            }

            throw new FormatError(
                'a doc comment here would be lost, so the file is not written: ' . $text,
                $path,
                $token->line,
                $token->col,
            );
        }
    }
}
