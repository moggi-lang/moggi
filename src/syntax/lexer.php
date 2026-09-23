<?php declare(strict_types=1);

namespace Moggi\Syntax\Lexer;

use Moggi\Syntax\Parser\Token;

use function Moggi\Errors\formatDiagnostic;

final class LexError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $filename,
        public readonly string $source,
        private int $errorLine,
        private int $errorCol,
        private int $errorEndCol = 0,
        public readonly string $diagnosticCode = 'lex',
    ) {
        parent::__construct($message);
    }

    public function display(): string
    {
        return formatDiagnostic(
            $this->filename,
            'lex error',
            $this->getMessage(),
            $this->source,
            $this->errorLine,
            $this->errorCol,
            $this->errorEndCol,
        );
    }

    public function line(): int
    {
        return $this->errorLine;
    }

    public function col(): int
    {
        return $this->errorCol;
    }

    public function endCol(): int
    {
        return $this->errorEndCol;
    }
}

enum TokenKind: string
{
    case Integer = 'integer';
    case Float = 'float';
    case StringLit = 'string';
    case CharLit = 'char';
    /** `'Red` — a promoted data constructor used as a type (DataKinds). Lexeme excludes the leading tick. */
    case PromotedCon = 'promoted_con';
    case VarId = 'varid';
    case ConId = 'conid';
    case KwData = 'kw_data';
    case KwNewtype = 'kw_newtype';
    case KwType = 'kw_type';
    case KwModule = 'kw_module';
    case KwPub = 'kw_pub';
    case KwAbstract = 'kw_abstract';
    case KwImport = 'kw_import';
    case KwQualified = 'kw_qualified';
    case KwHiding = 'kw_hiding';
    case KwAs = 'kw_as';
    case KwInfix = 'kw_infix';
    case KwInfixl = 'kw_infixl';
    case KwInfixr = 'kw_infixr';
    case KwLet = 'kw_let';
    case KwIn = 'kw_in';
    case KwWhere = 'kw_where';
    case KwCase = 'kw_case';
    case KwOf = 'kw_of';
    case KwIf = 'kw_if';
    case KwThen = 'kw_then';
    case KwElse = 'kw_else';
    case KwDo = 'kw_do';
    case KwClass = 'kw_class';
    case KwInstance = 'kw_instance';
    case KwDeriving = 'kw_deriving';
    case KwForeign = 'kw_foreign';
    case Backslash = 'backslash';
    case Backtick = 'backtick';
    case LParen = 'lparen';
    case RParen = 'rparen';
    case LBracket = 'lbracket';
    case RBracket = 'rbracket';
    case LBrace = 'lbrace';
    case RBrace = 'rbrace';
    case Comma = 'comma';
    case Semicolon = 'semicolon';
    case Pipe = 'pipe';
    case Op = 'op';
    /** Body of `{-# … #-}` (trimmed, without delimiters). */
    case Pragma = 'pragma';
    /** Doc comment `-- |` (may include continuation lines). */
    case DocComment = 'doc_comment';
    /** Export-list section heading `-- *`. */
    case DocSection = 'doc_section';
    /** End-of-line `-- ^` documentation. */
    case DocTrailing = 'doc_trailing';
    /** Block documentation `{-| … -}` or `{-^ … -}`. */
    case DocBlock = 'doc_block';
    case Eof = 'eof';
}

require_once __DIR__ . '/parser_types.php';

function isSmall(string $c): bool
{
    return ($c >= 'a' && $c <= 'z') || $c === '_';
}

function isLarge(string $c): bool
{
    return $c >= 'A' && $c <= 'Z';
}

function isDigit(string $c): bool
{
    return $c >= '0' && $c <= '9';
}

function isLetter(string $c): bool
{
    return isSmall($c) || isLarge($c);
}

function isIdCont(string $c): bool
{
    return isLetter($c) || isDigit($c) || $c === '\'';
}

/**
 * Printable label for the character that starts at byte `$i`.
 *
 * The lexer walks bytes, so a non-ASCII mistake would otherwise be reported as
 * `json_encode` of one continuation byte — which is not valid UTF-8, and prints
 * as nothing at all.
 */
function charLabel(string $source, int $i): string
{
    if (preg_match('/^./us', substr($source, $i, 4), $m) === 1) {
        return json_encode($m[0], JSON_UNESCAPED_UNICODE);
    }

    return \sprintf('"\\x%02X"', ord($source[$i]));
}

function isSymbol(string $c): bool
{
    return str_contains('!#$%&*+./<=>?@\\^|-~:', $c);
}

/** @return array{kind: TokenKind, lexeme: string} */
function keyword(string $lexeme): array
{
    return match ($lexeme) {
        'data' => ['kind' => TokenKind::KwData, 'lexeme' => $lexeme],
        'newtype' => ['kind' => TokenKind::KwNewtype, 'lexeme' => $lexeme],
        'type' => ['kind' => TokenKind::KwType, 'lexeme' => $lexeme],
        'module' => ['kind' => TokenKind::KwModule, 'lexeme' => $lexeme],
        'pub' => ['kind' => TokenKind::KwPub, 'lexeme' => $lexeme],
        'abstract' => ['kind' => TokenKind::KwAbstract, 'lexeme' => $lexeme],
        'import' => ['kind' => TokenKind::KwImport, 'lexeme' => $lexeme],
        'qualified' => ['kind' => TokenKind::KwQualified, 'lexeme' => $lexeme],
        'hiding' => ['kind' => TokenKind::KwHiding, 'lexeme' => $lexeme],
        'as' => ['kind' => TokenKind::KwAs, 'lexeme' => $lexeme],
        'infix' => ['kind' => TokenKind::KwInfix, 'lexeme' => $lexeme],
        'infixl' => ['kind' => TokenKind::KwInfixl, 'lexeme' => $lexeme],
        'infixr' => ['kind' => TokenKind::KwInfixr, 'lexeme' => $lexeme],
        'let' => ['kind' => TokenKind::KwLet, 'lexeme' => $lexeme],
        'in' => ['kind' => TokenKind::KwIn, 'lexeme' => $lexeme],
        'where' => ['kind' => TokenKind::KwWhere, 'lexeme' => $lexeme],
        'case' => ['kind' => TokenKind::KwCase, 'lexeme' => $lexeme],
        'of' => ['kind' => TokenKind::KwOf, 'lexeme' => $lexeme],
        'if' => ['kind' => TokenKind::KwIf, 'lexeme' => $lexeme],
        'then' => ['kind' => TokenKind::KwThen, 'lexeme' => $lexeme],
        'else' => ['kind' => TokenKind::KwElse, 'lexeme' => $lexeme],
        'do' => ['kind' => TokenKind::KwDo, 'lexeme' => $lexeme],
        'class' => ['kind' => TokenKind::KwClass, 'lexeme' => $lexeme],
        'instance' => ['kind' => TokenKind::KwInstance, 'lexeme' => $lexeme],
        'deriving' => ['kind' => TokenKind::KwDeriving, 'lexeme' => $lexeme],
        'foreign' => ['kind' => TokenKind::KwForeign, 'lexeme' => $lexeme],
        default => ['kind' => TokenKind::VarId, 'lexeme' => $lexeme],
    };
}

function lexError(string $message, string $source, string $filename, int $line, int $col, int $endCol = 0): LexError
{
    return new LexError($message, $filename, $source, $line, $col, $endCol);
}

/**
 * A Unicode scalar value is any code point in 0..0x10FFFF except the
 * surrogate range U+D800..U+DFFF (those only exist as UTF-16 surrogate
 * halves and can never stand on their own as a real character).
 */
function isUnicodeScalar(int $cp): bool
{
    return $cp >= 0 && $cp <= 0x10FFFF && !($cp >= 0xD800 && $cp <= 0xDFFF);
}

/**
 * Decodes a single UTF-8 sequence starting at $source[$i], advancing $i past
 * it, and returns the decoded scalar value. Callers are expected to only use
 * this on source that already passed the whole-source UTF-8 validity check
 * in lex(), so malformed sequences are not expected here; the error branches
 * exist purely as a defensive fallback.
 *
 * @param-out int $i
 */
function utf8DecodeScalar(string $source, int &$i, int $len): int
{
    $byte = ord($source[$i]);

    if ($byte < 0x80) {
        ++$i;

        return $byte;
    }

    if (($byte & 0xE0) === 0xC0) {
        $extra = 1;
        $cp = $byte & 0x1F;
    } elseif (($byte & 0xF0) === 0xE0) {
        $extra = 2;
        $cp = $byte & 0x0F;
    } elseif (($byte & 0xF8) === 0xF0) {
        $extra = 3;
        $cp = $byte & 0x07;
    } else {
        throw new \RuntimeException('invalid UTF-8 lead byte');
    }

    if ($i + $extra >= $len) {
        throw new \RuntimeException('truncated UTF-8 sequence');
    }

    for ($k = 1; $k <= $extra; ++$k) {
        $cont = ord($source[$i + $k]);
        if (($cont & 0xC0) !== 0x80) {
            throw new \RuntimeException('invalid UTF-8 continuation byte');
        }
        $cp = ($cp << 6) | ($cont & 0x3F);
    }

    $i += $extra + 1;

    return $cp;
}

/**
 * Parses a `\xHH` or `\u{HEX}` numeric escape. On entry $source[$i] must be
 * the backslash and $source[$i + 1] must be 'x' or 'u'; advances $i and $col
 * past the whole escape sequence and returns the decoded scalar value.
 *
 * @param-out int $i
 * @param-out int $col
 */
function lexNumericEscape(
    string $source,
    int &$i,
    int &$col,
    int $len,
    int $line,
    string $filename,
): int {
    $startCol = $col;
    $kind = $source[$i + 1];
    $i += 2;
    $col += 2;

    if ($kind === 'x') {
        $digits = '';
        for ($k = 0; $k < 2; ++$k) {
            if ($i >= $len || !ctype_xdigit($source[$i])) {
                throw lexError('invalid \\x escape: expected exactly 2 hex digits', $source, $filename, $line, $startCol, $col);
            }
            $digits .= $source[$i];
            ++$i;
            ++$col;
        }
        $cp = (int) hexdec($digits);
    } else {
        if ($i >= $len || $source[$i] !== '{') {
            throw lexError('invalid \\u escape: expected { after \\u', $source, $filename, $line, $startCol, $col);
        }
        ++$i;
        ++$col;

        $digits = '';
        while ($i < $len && $source[$i] !== '}') {
            if (!ctype_xdigit($source[$i])) {
                throw lexError('invalid \\u escape: expected hex digits inside { }', $source, $filename, $line, $startCol, $col);
            }
            $digits .= $source[$i];
            ++$i;
            ++$col;
        }

        if ($i >= $len) {
            throw lexError('invalid \\u escape: unterminated, expected }', $source, $filename, $line, $startCol, $col);
        }

        if ($digits === '') {
            throw lexError('invalid \\u escape: expected at least one hex digit inside { }', $source, $filename, $line, $startCol, $col);
        }

        ++$i;
        ++$col;

        // More than 6 hex digits can never fit in 0..0x10FFFF; avoid feeding
        // hexdec() an arbitrarily large string and just force the range check below to fail.
        $cp = strlen($digits) > 6 ? 0x110000 : (int) hexdec($digits);
    }

    if (!isUnicodeScalar($cp)) {
        throw lexError(
            \sprintf('invalid Unicode scalar value in numeric escape (U+%X is out of range or a surrogate)', $cp),
            $source,
            $filename,
            $line,
            $startCol,
            $col,
        );
    }

    return $cp;
}

/**
 * Lex `{-# … #-}` into a Pragma token whose lexeme is the trimmed body.
 *
 * @param-out int $i
 * @param-out int $line
 * @param-out int $col
 */
function lexPragma(
    string $source,
    int $len,
    int &$i,
    int &$line,
    int &$col,
    string $filename,
): Token {
    $startLine = $line;
    $startCol = $col;
    // Skip `{-#`
    $i += 3;
    $col += 3;
    $bodyStart = $i;

    while ($i < $len) {
        if ($source[$i] === "\n") {
            ++$i;
            ++$line;
            $col = 1;
            continue;
        }

        if ($source[$i] === '#'
            && $i + 2 < $len
            && $source[$i + 1] === '-'
            && $source[$i + 2] === '}'
        ) {
            $body = trim(str_replace("\r\n", "\n", substr($source, $bodyStart, $i - $bodyStart)));
            $i += 3;
            $col += 3;

            return new Token(TokenKind::Pragma, $body, $startLine, $startCol);
        }

        ++$i;
        ++$col;
    }

    throw lexError('unterminated pragma', $source, $filename, $startLine, $startCol);
}

/**
 * @param-out int $i
 * @param-out int $line
 * @param-out int $col
 */
function lexDocCommentLine(
    string $source,
    int $len,
    int &$i,
    int &$line,
    int &$col,
    int $startLine,
    int $startCol,
    TokenKind $kind,
): Token {
    $i += 4;
    $col += 4;
    $text = '';
    while ($i < $len && $source[$i] !== "\n") {
        $text .= $source[$i];
        ++$i;
        ++$col;
    }
    $text = trim($text);

    if ($kind === TokenKind::DocComment || $kind === TokenKind::DocTrailing) {
        while ($i < $len && $source[$i] === "\n") {
            ++$i;
            ++$line;
            $col = 1;
            $nextStart = $i;
            if ($nextStart >= $len || $source[$nextStart] === "\n") {
                break;
            }
            // Match the following line on its own (not the rest of the
            // source), so any non-comment line always ends the doc comment.
            $lineEnd = strpos($source, "\n", $nextStart);
            $lineText = $lineEnd === false
                ? substr($source, $nextStart)
                : substr($source, $nextStart, $lineEnd - $nextStart);
            // A CRLF source keeps its `\r` in the line: dropping it here makes the
            // continuation test below and the text it captures identical to LF's.
            $lineText = rtrim($lineText, "\r");

            $pattern = $kind === TokenKind::DocTrailing
                ? '/^[ \t]*-- \^[ \t]*(.*)$/'
                // Continue on `-- …` (including a bare `--`), but stop at a
                // `---` rule or a new `-- |`/`-- ^` doc comment.
                : '/^[ \t]*--(?!-|\|)(?: (.*))?$/';
            if (!preg_match($pattern, $lineText, $m)) {
                break;
            }

            $contLine = $m[1] ?? '';
            $text .= ($text === '' ? '' : "\n") . $contLine;

            // Leave `$i` on the newline; the loop head consumes it so the next
            // line is examined too (multi-line doc comments).
            $i = $lineEnd === false ? $len : $lineEnd;
        }
    }

    return new Token($kind, $text, $startLine, $startCol);
}

/**
 * @param-out int $i
 * @param-out int $line
 * @param-out int $col
 */
function lexDocCommentBlock(
    string $source,
    int $len,
    int &$i,
    int &$line,
    int &$col,
    int $startLine,
    int $startCol,
    string $filename = '',
): Token {
    $i += 3;
    $col += 3;
    $text = '';
    $closed = false;
    while ($i < $len) {
        if ($source[$i] === '-' && $i + 1 < $len && $source[$i + 1] === '}') {
            $i += 2;
            $col += 2;
            $closed = true;
            break;
        }
        if ($source[$i] === "\n") {
            $text .= "\n";
            ++$i;
            ++$line;
            $col = 1;
            continue;
        }
        $text .= $source[$i];
        ++$i;
        ++$col;
    }

    if (!$closed) {
        throw lexError('unterminated documentation block', $source, $filename, $startLine, $startCol);
    }

    return new Token(TokenKind::DocBlock, trim(str_replace("\r\n", "\n", $text)), $startLine, $startCol);
}

/**
 * @param-out int $i
 * @param-out int $line
 * @param-out int $col
 */
function skipBlockComment(
    string $source,
    int $len,
    int &$i,
    int &$line,
    int &$col,
    int $startLine,
    int $startCol,
    string $filename,
): void {
    $i += 2;
    $col += 2;
    $depth = 1;

    while ($i < $len && $depth > 0) {
        $c = $source[$i];

        if ($c === "\n") {
            ++$i;
            ++$line;
            $col = 1;
            continue;
        }

        if ($c === '{' && $i + 1 < $len && $source[$i + 1] === '-') {
            $i += 2;
            $col += 2;
            ++$depth;
            continue;
        }

        if ($c === '-' && $i + 1 < $len && $source[$i + 1] === '}') {
            $i += 2;
            $col += 2;
            --$depth;
            continue;
        }

        ++$i;
        ++$col;
    }

    if ($depth > 0) {
        throw lexError('unterminated block comment', $source, $filename, $startLine, $startCol, $startCol + 1);
    }
}

/** @return list<Token> */
function lex(string $source, string $filename = ''): array
{
    if (!mb_check_encoding($source, 'UTF-8')) {
        throw lexError('invalid UTF-8 in source', $source, $filename, 1, 1);
    }

    $tokens = [];
    $len = strlen($source);
    $i = 0;
    $line = 1;
    $col = 1;

    while ($i < $len) {
        $c = $source[$i];

        if ($c === ' ' || $c === "\t" || $c === "\r") {
            ++$i;
            ++$col;
            continue;
        }

        if ($c === "\n") {
            ++$i;
            ++$line;
            $col = 1;
            continue;
        }

        if ($c === '-' && $i + 1 < $len && $source[$i + 1] === '-') {
            $third = $i + 2 < $len ? $source[$i + 2] : '';
            // A run of dashes is a line comment (`-----`); `-->` stays an operator.
            if ($third !== '>') {
                $startLine = $line;
                $startCol = $col;
                if ($i + 3 < $len && $source[$i + 2] === ' ' && $source[$i + 3] === '|') {
                    $tokens[] = lexDocCommentLine(
                        $source,
                        $len,
                        $i,
                        $line,
                        $col,
                        $startLine,
                        $startCol,
                        TokenKind::DocComment,
                    );
                    continue;
                }
                if ($i + 3 < $len && $source[$i + 2] === ' ' && $source[$i + 3] === '*') {
                    $tokens[] = lexDocCommentLine(
                        $source,
                        $len,
                        $i,
                        $line,
                        $col,
                        $startLine,
                        $startCol,
                        TokenKind::DocSection,
                    );
                    continue;
                }
                if ($i + 3 < $len && $source[$i + 2] === ' ' && $source[$i + 3] === '^') {
                    $tokens[] = lexDocCommentLine(
                        $source,
                        $len,
                        $i,
                        $line,
                        $col,
                        $startLine,
                        $startCol,
                        TokenKind::DocTrailing,
                    );
                    continue;
                }
                while ($i < $len && $source[$i] !== "\n") {
                    ++$i;
                }
                continue;
            }
        }

        if ($c === '{' && $i + 1 < $len && $source[$i + 1] === '-') {
            if ($i + 2 < $len && $source[$i + 2] === '#') {
                $tokens[] = lexPragma($source, $len, $i, $line, $col, $filename);
                continue;
            }

            if ($i + 2 < $len && ($source[$i + 2] === '|' || $source[$i + 2] === '^')) {
                $tokens[] = lexDocCommentBlock($source, $len, $i, $line, $col, $line, $col, $filename);
                continue;
            }

            skipBlockComment($source, $len, $i, $line, $col, $line, $col, $filename);
            continue;
        }

        $startLine = $line;
        $startCol = $col;

        if (isDigit($c)) {
            $start = $i;
            while ($i < $len && isDigit($source[$i])) {
                ++$i;
                ++$col;
            }
            $isFloat = false;
            if ($i < $len && $source[$i] === '.' && $i + 1 < $len && isDigit($source[$i + 1])) {
                $isFloat = true;
                ++$i;
                ++$col;
                while ($i < $len && isDigit($source[$i])) {
                    ++$i;
                    ++$col;
                }
            }
            // An exponent needs at least one digit after the optional sign;
            // `1e` stays an integer followed by an identifier `e`.
            if ($i < $len && ($source[$i] === 'e' || $source[$i] === 'E')) {
                $afterSign = $i + 1;
                if ($afterSign < $len && ($source[$afterSign] === '+' || $source[$afterSign] === '-')) {
                    ++$afterSign;
                }
                if ($afterSign < $len && isDigit($source[$afterSign])) {
                    $isFloat = true;
                    $col += $afterSign - $i;
                    $i = $afterSign;
                    while ($i < $len && isDigit($source[$i])) {
                        ++$i;
                        ++$col;
                    }
                }
            }
            $kind = $isFloat ? TokenKind::Float : TokenKind::Integer;
            $tokens[] = new Token($kind, substr($source, $start, $i - $start), $startLine, $startCol);
            continue;
        }

        if ($c === '"') {
            ++$i;
            ++$col;
            $value = '';
            while ($i < $len && $source[$i] !== '"') {
                if ($source[$i] === '\\' && $i + 1 < $len) {
                    $next = $source[$i + 1];

                    if ($next === 'x' || $next === 'u') {
                        $cp = lexNumericEscape($source, $i, $col, $len, $line, $filename);
                        $value .= mb_chr($cp, 'UTF-8');
                        continue;
                    }

                    $value .= match ($next) {
                        'n' => "\n",
                        't' => "\t",
                        'r' => "\r",
                        '\\' => '\\',
                        '"' => '"',
                        default => $next,
                    };
                    $i += 2;
                    $col += 2;
                    continue;
                }

                $start = $i;
                utf8DecodeScalar($source, $i, $len);
                $value .= substr($source, $start, $i - $start);
                ++$col;
            }

            if ($i >= $len) {
                throw lexError('unterminated string literal', $source, $filename, $startLine, $startCol);
            }

            ++$i;
            ++$col;
            $tokens[] = new Token(TokenKind::StringLit, $value, $startLine, $startCol);
            continue;
        }

        if ($c === '\'') {
            // DataKinds: `'Red` (tick immediately followed by an uppercase letter,
            // with no closing tick right after) promotes a data constructor to a
            // type. A lone uppercase char literal like `'A'` still lexes as CharLit
            // since the closing tick follows immediately.
            if ($i + 1 < $len && isLarge($source[$i + 1]) && !($i + 2 < $len && $source[$i + 2] === '\'')) {
                ++$i;
                ++$col;
                $start = $i;
                while ($i < $len && isIdCont($source[$i])) {
                    ++$i;
                    ++$col;
                }
                $lexeme = substr($source, $start, $i - $start);
                $tokens[] = new Token(TokenKind::PromotedCon, $lexeme, $startLine, $startCol);
                continue;
            }

            ++$i;
            ++$col;
            $scalars = [];

            while ($i < $len && $source[$i] !== '\'' && $source[$i] !== "\n") {
                if ($source[$i] === '\\' && $i + 1 < $len) {
                    $next = $source[$i + 1];

                    if ($next === 'x' || $next === 'u') {
                        $scalars[] = lexNumericEscape($source, $i, $col, $len, $line, $filename);
                        continue;
                    }

                    $cp = match ($next) {
                        'n' => 0x0A,
                        't' => 0x09,
                        'r' => 0x0D,
                        '\\' => 0x5C,
                        '\'' => 0x27,
                        default => null,
                    };

                    if ($cp === null) {
                        throw lexError(
                            \sprintf('invalid escape sequence \\%s in character literal', $next),
                            $source,
                            $filename,
                            $line,
                            $col,
                            $col + 2,
                        );
                    }

                    $scalars[] = $cp;
                    $i += 2;
                    $col += 2;
                    continue;
                }

                $scalars[] = utf8DecodeScalar($source, $i, $len);
                ++$col;
            }

            if ($i >= $len || $source[$i] !== '\'') {
                throw lexError('unterminated character literal', $source, $filename, $startLine, $startCol);
            }

            ++$i;
            ++$col;

            if (count($scalars) === 0) {
                throw lexError('empty character literal', $source, $filename, $startLine, $startCol, $col);
            }

            if (count($scalars) > 1) {
                throw lexError(
                    'character literal must contain exactly one Unicode scalar value',
                    $source,
                    $filename,
                    $startLine,
                    $startCol,
                    $col,
                );
            }

            $tokens[] = new Token(TokenKind::CharLit, (string) $scalars[0], $startLine, $startCol);
            continue;
        }

        if (isLarge($c)) {
            $start = $i;
            ++$i;
            ++$col;
            while ($i < $len && isIdCont($source[$i])) {
                ++$i;
                ++$col;
            }
            // MagicHash-style: `List#` is one constructor id, not `List` + op `#`.
            if ($i < $len && $source[$i] === '#') {
                ++$i;
                ++$col;
            }
            $lexeme = substr($source, $start, $i - $start);
            $tokens[] = new Token(TokenKind::ConId, $lexeme, $startLine, $startCol);
            continue;
        }

        if (isSmall($c)) {
            $start = $i;
            ++$i;
            ++$col;
            while ($i < $len && isIdCont($source[$i])) {
                ++$i;
                ++$col;
            }
            if ($i < $len && $source[$i] === '#') {
                ++$i;
                ++$col;
            }
            $lexeme = substr($source, $start, $i - $start);
            ['kind' => $kind, 'lexeme' => $kwLexeme] = keyword($lexeme);
            $tokens[] = new Token($kind, $kwLexeme, $startLine, $startCol);
            continue;
        }

        if ($c === '(') {
            $tokens[] = new Token(TokenKind::LParen, '(', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        if ($c === ')') {
            $tokens[] = new Token(TokenKind::RParen, ')', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        if ($c === '[') {
            $tokens[] = new Token(TokenKind::LBracket, '[', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        if ($c === ']') {
            $tokens[] = new Token(TokenKind::RBracket, ']', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        if ($c === '{') {
            $tokens[] = new Token(TokenKind::LBrace, '{', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        if ($c === '}') {
            $tokens[] = new Token(TokenKind::RBrace, '}', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        if ($c === ',') {
            $tokens[] = new Token(TokenKind::Comma, ',', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        if ($c === ';') {
            $tokens[] = new Token(TokenKind::Semicolon, ';', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        if ($c === '|') {
            if ($i + 1 < $len && $source[$i + 1] === '|') {
                $tokens[] = new Token(TokenKind::Op, '||', $startLine, $startCol);
                $i += 2;
                $col += 2;
                continue;
            }

            $tokens[] = new Token(TokenKind::Pipe, '|', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        if ($c === '`') {
            $tokens[] = new Token(TokenKind::Backtick, '`', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        // A lone `\` is the lambda token; a longer run of symbol characters is
        // an operator, so `\\` (Data.List's list difference) lexes as one Op.
        if ($c === '\\' && !($i + 1 < $len && isSymbol($source[$i + 1]))) {
            $tokens[] = new Token(TokenKind::Backslash, '\\', $startLine, $startCol);
            ++$i;
            ++$col;
            continue;
        }

        if (isSymbol($c)) {
            $start = $i;
            ++$i;
            ++$col;
            while ($i < $len && isSymbol($source[$i])) {
                ++$i;
                ++$col;
            }
            $tokens[] = new Token(TokenKind::Op, substr($source, $start, $i - $start), $startLine, $startCol);
            continue;
        }

        throw lexError(
            \sprintf('unexpected character %s', charLabel($source, $i)),
            $source,
            $filename,
            $line,
            $col,
        );
    }

    $tokens[] = new Token(TokenKind::Eof, '', $line, $col);

    return $tokens;
}

/**
 * The token stream as introspection prints it: kind, lexeme, position, one token per line.
 *
 * Both `moggi compile F --tokens` and the suite's `tokens` golden come through here, so a flag and a
 * golden cannot describe the same stream differently.
 *
 * @param list<Token> $tokens
 */
function dump(array $tokens): string
{
    $lines = [];
    foreach ($tokens as $token) {
        $lines[] = \sprintf(
            '%s %s @ %d:%d',
            $token->kind->value,
            json_encode($token->lexeme, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $token->line,
            $token->col,
        );
    }

    return \implode("\n", $lines) . "\n";
}
