<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Lexer\TokenKind;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Ast\AstNode;

function isAt(ParserState $state, TokenKind $kind, int $offset = 0, ?string $lexeme = null): bool
{
    $token = peekAt($state, $offset);

    if ($token->kind !== $kind) {
        return false;
    }

    return $lexeme === null || $token->lexeme === $lexeme;
}

function peekAt(ParserState $state, int $offset = 0): Token
{
    return $state->tokens[$state->pos + $offset];
}

function peek(ParserState $state): Token
{
    return peekAt($state, 0);
}

function advance(ParserState $state): Token
{
    return $state->tokens[$state->pos++];
}

function expect(ParserState $state, TokenKind $kind): Token
{
    $token = peek($state);

    if ($token->kind !== $kind) {
        throw unexpected($state, describeTokenKind($kind));
    }

    return advance($state);
}

function expectOneOf(ParserState $state, TokenKind ...$kinds): Token
{
    $token = peek($state);

    foreach ($kinds as $kind) {
        if ($token->kind === $kind) {
            return advance($state);
        }
    }

    throw unexpected($state, describeTokenKindList($kinds));
}

function describeTokenKindList(array $kinds): string
{
    $names = array_map(fn (TokenKind $k): string => $k->value, $kinds);
    return implode(' or ', $names);
}

function expectOp(ParserState $state, string $lexeme): void
{
    $token = peek($state);

    if ($token->kind !== TokenKind::Op || $token->lexeme !== $lexeme) {
        throw unexpected($state, '`' . $lexeme . '`');
    }

    advance($state);
}

/**
 * @param AstNode $scrutinee
 */
function expectCaseOf(ParserState $state, AstNode $scrutinee): void
{
    if (isAt($state, TokenKind::KwOf)) {
        advance($state);

        return;
    }

    $message = startsPattern($state)
        ? 'case expression requires `of` before alternatives; write `case <expr> of`'
        : 'expected ' . describeTokenKind(TokenKind::KwOf) . ', found ' . describeToken(peek($state));

    throw parseError($state, $message, errorAtEndOfNode($scrutinee));
}

function errorAtEndOfNode(AstNode $node): Token
{
    if ($node->endCol) {
        return new Token(TokenKind::Eof, '', $node->line, $node->endCol);
    }

    if ($node->col) {
        return new Token(TokenKind::Eof, '', $node->line, $node->col);
    }

    return new Token(TokenKind::Eof, '', 1, 1);
}

function unexpected(ParserState $state, string $expected): ParseError
{
    return parseError(
        $state,
        'expected ' . $expected . ', found ' . describeToken(peek($state)),
    );
}

function describeToken(Token $token): string
{
    return match ($token->kind) {
        TokenKind::Integer => 'integer ' . $token->lexeme,
        TokenKind::StringLit => 'string ' . json_encode($token->lexeme),
        TokenKind::CharLit => 'character ' . \sprintf("'\\u{%X}'", (int) $token->lexeme),
        TokenKind::VarId => "identifier '{$token->lexeme}'",
        TokenKind::ConId => "constructor '{$token->lexeme}'",
        TokenKind::PromotedCon => "promoted constructor '{$token->lexeme}'",
        TokenKind::Op => "operator '{$token->lexeme}'",
        TokenKind::Eof => 'end of file',
        default => describeTokenKind($token->kind),
    };
}

function describeTokenKind(TokenKind $kind): string
{
    return match ($kind) {
        TokenKind::Integer => 'integer literal',
        TokenKind::Float => 'float literal',
        TokenKind::StringLit => 'string literal',
        TokenKind::CharLit => 'character literal',
        TokenKind::VarId => 'identifier',
        TokenKind::ConId => 'constructor',
        TokenKind::PromotedCon => 'promoted constructor',
        TokenKind::KwData => "keyword 'data'",
        TokenKind::KwNewtype => "keyword 'newtype'",
        TokenKind::KwType => "keyword 'type'",
        TokenKind::KwModule => "keyword 'module'",
        TokenKind::KwPub => "keyword 'pub'",
        TokenKind::KwForeign => "keyword 'foreign'",
        TokenKind::KwAbstract => "keyword 'abstract'",
        TokenKind::KwImport => "keyword 'import'",
        TokenKind::KwQualified => "keyword 'qualified'",
        TokenKind::KwHiding => "keyword 'hiding'",
        TokenKind::KwAs => "keyword 'as'",
        TokenKind::KwInfix => "keyword 'infix'",
        TokenKind::KwInfixl => "keyword 'infixl'",
        TokenKind::KwInfixr => "keyword 'infixr'",
        TokenKind::KwLet => "keyword 'let'",
        TokenKind::KwIn => "keyword 'in'",
        TokenKind::KwWhere => "keyword 'where'",
        TokenKind::KwCase => "keyword 'case'",
        TokenKind::KwOf => "keyword 'of'",
        TokenKind::KwIf => "keyword 'if'",
        TokenKind::KwThen => "keyword 'then'",
        TokenKind::KwElse => "keyword 'else'",
        TokenKind::KwDo => "keyword 'do'",
        TokenKind::KwClass => "keyword 'class'",
        TokenKind::KwInstance => "keyword 'instance'",
        TokenKind::KwDeriving => "keyword 'deriving'",
        TokenKind::Backslash => "symbol '\\'",
        TokenKind::Backtick => 'backtick',
        TokenKind::LParen => "'('",
        TokenKind::RParen => "')'",
        TokenKind::LBracket => "'['",
        TokenKind::RBracket => "']'",
        TokenKind::LBrace => "'{'",
        TokenKind::RBrace => "'}'",
        TokenKind::Comma => "','",
        TokenKind::Semicolon => "';'",
        TokenKind::Pipe => "'|'",
        TokenKind::Op => 'operator',
        TokenKind::Pragma => 'pragma',
        TokenKind::DocComment => 'documentation comment',
        TokenKind::DocSection => 'export section heading',
        TokenKind::DocTrailing => 'trailing documentation',
        TokenKind::DocBlock => 'block documentation',
        TokenKind::Eof => 'end of file',
    };
}

function nameMismatch(ParserState $state, string $expected, string $got): ParseError
{
    return parseError($state, "expected definition for `{$expected}`, got `{$got}`");
}

function errorLocation(ParserState $state, Token $token): array
{
    if ($token->kind !== TokenKind::Eof) {
        return [
            'line' => $token->line,
            'col' => $token->col,
            'endCol' => $token->col + max(0, strlen($token->lexeme) - 1),
        ];
    }

    if ($state->pos > 0) {
        $prev = $state->tokens[$state->pos - 1];
        $endCol = $prev->col + strlen($prev->lexeme);

        return ['line' => $prev->line, 'col' => $endCol, 'endCol' => $endCol];
    }

    return ['line' => 1, 'col' => 1, 'endCol' => 1];
}

function parseError(ParserState $state, string $message, ?Token $at = null, string $code = 'parse'): ParseError
{
    if ($at === null) {
        $loc = errorLocation($state, peek($state));
    } else {
        // A token can span lines (a multi-line pragma, a `{-| … -}` block). The caret stops at the
        // end of the line the token starts on instead of running past it.
        $firstLineEnd = strpos($at->lexeme, "\n");
        $span = $firstLineEnd === false ? strlen($at->lexeme) : $firstLineEnd;
        $loc = [
            'line' => $at->line,
            'col' => $at->col,
            'endCol' => $at->col + max(0, $span - 1),
        ];
    }

    return new ParseError(
        $message,
        $state->filename,
        $state->source,
        $loc['line'],
        $loc['col'],
        $loc['endCol'],
        $code,
    );
}

function incompleteCaseAltError(ParserState $state, string $message): ParseError
{
    $at = $state->pos > 0 ? $state->tokens[$state->pos - 1] : peek($state);

    return parseError($state, $message, $at);
}

function describeUpcomingCaseAlt(ParserState $state): string
{
    $pos = $state->pos;
    $count = count($state->tokens);
    $parts = [];

    while ($pos < $count) {
        $token = $state->tokens[$pos];

        if ($token->kind === TokenKind::Op && $token->lexeme === '->') {
            $parts[] = '->';
            break;
        }

        if ($token->kind === TokenKind::Integer
            || $token->kind === TokenKind::VarId
            || $token->kind === TokenKind::ConId
            || $token->kind === TokenKind::LParen
            || $token->kind === TokenKind::RParen
            || $token->kind === TokenKind::Comma) {
            $parts[] = $token->lexeme;
            ++$pos;
            continue;
        }

        break;
    }

    return \implode(' ', $parts);
}

function formatPatternPreview(AstNode $pattern): string
{
    return match ($pattern::class) {
        Ast\PatCon::class => $pattern->name . (empty($pattern->args)
            ? ''
            : ' ' . \implode(' ', \array_map(formatPatternPreview(...), $pattern->args))),
        Ast\PatVar::class => $pattern->name,
        Ast\PatWild::class => '_',
        Ast\PatLit::class => (string) $pattern->value,
        Ast\PatChar::class => \sprintf("'\\u{%X}'", $pattern->value),
        Ast\PatTuple::class => '(' . \implode(', ', \array_map(formatPatternPreview(...), $pattern->elements)),
        Ast\PatNil::class => '[]',
        Ast\PatCons::class => formatPatternPreview($pattern->head) . ' : ' . formatPatternPreview($pattern->tail),
        Ast\PatRecord::class => $pattern->name . ' { '
            . \implode(', ', \array_map(
                static fn (Ast\PatField $field): string => $field->name . ' = ' . formatPatternPreview($field->pattern),
                $pattern->fields,
            )) . ' }',
        default => '?',
    };
}

/**
 * Is the current token the first one on its line?
 *
 * A declaration can only begin a line, so an application argument in the middle
 * of one is never the start of one: in `x + sumTo (n - 1) xs` the trailing `xs`
 * is an argument even when the next line opens another guard clause.
 */
function tokenStartsLine(ParserState $state): bool
{
    return $state->pos === 0 || $state->tokens[$state->pos - 1]->line < peek($state)->line;
}

/**
 * Is the parser currently inside `(`, `[` or `{`? Declaration heuristics ask
 * this because no declaration can begin there, while an expression that merely
 * starts like one (a comprehension element, a tuple element) can.
 */
function insideBrackets(ParserState $state): bool
{
    $state->bracketDepthBefore ??= bracketDepthBefore($state->tokens);

    return ($state->bracketDepthBefore[$state->pos] ?? 0) > 0;
}

/**
 * Depth of open brackets before each token index.
 *
 * @param list<Token> $tokens
 * @return list<int>
 */
function bracketDepthBefore(array $tokens): array
{
    $depths = [];
    $depth = 0;

    foreach ($tokens as $index => $token) {
        $depths[$index] = $depth;
        if ($token->kind === TokenKind::LParen
            || $token->kind === TokenKind::LBracket
            || $token->kind === TokenKind::LBrace) {
            ++$depth;
        } elseif ($token->kind === TokenKind::RParen
            || $token->kind === TokenKind::RBracket
            || $token->kind === TokenKind::RBrace) {
            $depth = \max(0, $depth - 1);
        }
    }

    // The position past the last token (EOF) needs an entry too.
    $depths[\count($tokens)] = $depth;

    return $depths;
}

function spanned(AstNode $node, Token $token): AstNode
{
    $node->setLocation($token->line, $token->col, $token->col + max(0, strlen($token->lexeme) - 1));
    return $node;
}

/**
 * The value a decimal integer literal has as a machine `Int`: the digits taken
 * modulo 2^64, two's complement. A host `int` cast clamps instead, which would
 * turn `9223372036854775808` into `maxBound` rather than `minBound`.
 */
function integerLitValue(string $lexeme): int
{
    $value = (int) $lexeme;
    if ((string) $value === $lexeme) {
        return $value;
    }

    $negative = \str_starts_with($lexeme, '-');
    $digits = $negative ? \substr($lexeme, 1) : $lexeme;
    $wrapped = 0;
    foreach (\str_split($digits, 9) as $chunk) {
        $wrapped = int64WrapAdd(int64WrapMul($wrapped, 10 ** \strlen($chunk)), (int) $chunk);
    }

    return $negative ? int64WrapSub(0, $wrapped) : $wrapped;
}

/**
 * The 64-bit pattern helpers below mirror the ones the PHP backend emits for
 * `Int` arithmetic: PHP promotes to float past `PHP_INT_MAX` instead of
 * wrapping, so each operation below keeps the result on the host int.
 */
function int64WrapAdd(int $a, int $b): int
{
    $sum = $a + $b;
    if (\is_int($sum)) {
        return $sum;
    }

    $ao = $a & 0xffffffff;
    $bo = $b & 0xffffffff;
    $lo = $ao + $bo;
    $hi = (($a >> 32) + ($b >> 32) + (($lo >> 32) & 0xffffffff)) & 0xffffffff;

    return ($hi << 32) | ($lo & 0xffffffff);
}

function int64WrapSub(int $a, int $b): int
{
    $diff = $a - $b;
    if (\is_int($diff)) {
        return $diff;
    }

    $lo = ($a & 0xffffffff) - ($b & 0xffffffff);
    $hi = (($a >> 32) - ($b >> 32) - ($lo < 0 ? 1 : 0)) & 0xffffffff;

    return ($hi << 32) | ($lo & 0xffffffff);
}

function int64WrapMul(int $a, int $b): int
{
    $product = $a * $b;
    if (\is_int($product)) {
        return $product;
    }

    $a0 = $a & 0xffff;
    $a1 = ($a >> 16) & 0xffff;
    $a2 = ($a >> 32) & 0xffff;
    $a3 = ($a >> 48) & 0xffff;
    $b0 = $b & 0xffff;
    $b1 = ($b >> 16) & 0xffff;
    $b2 = ($b >> 32) & 0xffff;
    $b3 = ($b >> 48) & 0xffff;
    $low = $a0 * $b0;
    $carry = ($low >> 16) & 0xffff;
    $mid1 = $a0 * $b1 + $a1 * $b0 + $carry;
    $carry = ($mid1 >> 16) & 0xffffffff;
    $mid2 = $a0 * $b2 + $a1 * $b1 + $a2 * $b0 + $carry;
    $carry = ($mid2 >> 16) & 0xffffffff;
    $high = $a0 * $b3 + $a1 * $b2 + $a2 * $b1 + $a3 * $b0 + $carry;

    return (($high & 0xffff) << 48) | (($mid2 & 0xffff) << 32) | (($mid1 & 0xffff) << 16) | ($low & 0xffff);
}

function spannedRange(AstNode $node, AstNode|Token $from, AstNode|Token $to): AstNode
{
    $fromLine = $from->line;
    $fromCol = $from->col;
    $fromEnd = $from instanceof Token
        ? $from->col + max(0, strlen($from->lexeme) - 1)
        : $from->endCol;

    $toLine = $to->line;
    $toEnd = $to instanceof Token
        ? $to->col + max(0, strlen($to->lexeme) - 1)
        : $to->endCol;

    $node->setLocation($fromLine, $fromCol, $toLine === $fromLine ? $toEnd : $fromEnd);
    return $node;
}

function isDocTokenKind(TokenKind $kind): bool
{
    return $kind === TokenKind::DocComment
        || $kind === TokenKind::DocSection
        || $kind === TokenKind::DocTrailing
        || $kind === TokenKind::DocBlock;
}

function skipDocSections(ParserState $state): void
{
    while (isAt($state, TokenKind::DocSection)) {
        advance($state);
    }
}

/**
 * A doc-comment token (`-- |`, `-- *`, `-- ^`, `{-| -}`) at the cursor.
 */
function isAtDocComment(ParserState $state): bool
{
    return isDocTokenKind(peek($state)->kind);
}

/**
 * Skip the doc-comment tokens at the cursor.
 *
 * Doc comments are only *tokens* because the grammar attaches them to the
 * declaration they precede (module header, export/import list, top-level item,
 * class/instance method). Everywhere else — inside an expression, a pattern, an
 * argument, a case alternative or a local binding group — they are ordinary
 * comments and must not reach the expression/pattern parser, which would report
 * `expected expression, found documentation comment`.
 */
function skipDocComments(ParserState $state): void
{
    while (isAtDocComment($state)) {
        advance($state);
    }
}

/**
 * Skip the doc comments before the construct `$starts` recognises, leaving the
 * cursor on that construct's first token.
 *
 * Returns false with the cursor untouched when the docs are followed by
 * something else, so a parser that *does* attach docs (or that ends here
 * because the next thing is dedented out of this block) can still see them.
 *
 * @param callable(ParserState): bool $starts
 */
function skipDocCommentsBefore(ParserState $state, callable $starts): bool
{
    if (!isAtDocComment($state)) {
        return false;
    }

    $saved = $state->pos;
    skipDocComments($state);
    if (!$starts($state)) {
        $state->pos = $saved;

        return false;
    }

    return true;
}

function skipModuleHeaderDocs(ParserState $state): void
{
    if (!docsPrecedeImport($state)) {
        return;
    }

    while (isDocTokenKind(peek($state)->kind) || isAt($state, TokenKind::DocSection)) {
        if (isAt($state, TokenKind::DocSection)) {
            advance($state);
            continue;
        }
        consumeLeadingDoc($state);
    }
}

function docsPrecedeImport(ParserState $state): bool
{
    $saved = $state->pos;
    while (isDocTokenKind(peek($state)->kind) || isAt($state, TokenKind::DocSection)) {
        if (isAt($state, TokenKind::DocSection)) {
            advance($state);
            continue;
        }
        consumeLeadingDoc($state);
    }
    $isImport = isAt($state, TokenKind::KwImport);
    $state->pos = $saved;

    return $isImport;
}

function consumeIndentedTrailingDoc(ParserState $state, int $bodyCol): ?string
{
    if (!isAt($state, TokenKind::DocTrailing)) {
        return null;
    }

    if (peek($state)->col <= $bodyCol) {
        return null;
    }

    return consumeTrailingDoc($state);
}

function attachTrailingDocToPreviousMethod(array &$methods, ?string $trailingDoc): void
{
    if ($trailingDoc === null || $methods === []) {
        return;
    }

    $last = array_pop($methods);
    if ($last instanceof Ast\ClassMethodSig) {
        $methods[] = new Ast\ClassMethodSig($last->name, $last->type, mergeDoc($last->doc, $trailingDoc));
    } elseif ($last instanceof Ast\FunctionDecl) {
        $last->doc = mergeDoc($last->doc, $trailingDoc);
        $methods[] = $last;
    } else {
        $methods[] = $last;
    }
}

function consumeLeadingDoc(ParserState $state): ?string
{
    $parts = [];
    while (!isAt($state, TokenKind::Eof) && isDocTokenKind(peek($state)->kind)) {
        $kind = peek($state)->kind;
        if ($kind === TokenKind::DocSection || $kind === TokenKind::DocTrailing) {
            break;
        }
        $parts[] = advance($state)->lexeme;
    }

    return $parts === [] ? null : implode("\n", $parts);
}

function consumeTrailingDoc(ParserState $state): ?string
{
    $parts = [];
    while (isAt($state, TokenKind::DocTrailing) || isAt($state, TokenKind::DocBlock)) {
        $parts[] = advance($state)->lexeme;
    }

    return $parts === [] ? null : implode("\n", $parts);
}

function mergeDoc(?string $leading, ?string $trailing): ?string
{
    if ($leading !== null && $trailing !== null) {
        return $leading . "\n" . $trailing;
    }

    return $leading ?? $trailing;
}

function attachDoc(AstNode $node, ?string $leading, ?string $trailing = null): AstNode
{
    $doc = mergeDoc($leading, mergeDoc($node->doc, $trailing));
    if ($doc !== null) {
        $node->doc = $doc;
    }

    return $node;
}
