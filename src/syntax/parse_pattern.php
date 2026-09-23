<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;

use function Moggi\Syntax\isConstructorOperator;

/**
 * @return list<Ast\PatField>
 */
function parseRecordPatFields(ParserState $state): array
{
    expect($state, TokenKind::LBrace);
    $fields = [];

    while (!isAt($state, TokenKind::RBrace)) {
        $label = expect($state, TokenKind::VarId);
        expectOp($state, '=');
        $pattern = parsePattern($state);
        $fields[] = new Ast\PatField($label->lexeme, $pattern, $label->line, $label->col, $label->col + \strlen($label->lexeme));

        if (isAt($state, TokenKind::Comma)) {
            advance($state);
        }
    }

    expect($state, TokenKind::RBrace);

    return $fields;
}

/**
 * @return Ast\AstNode
 */
function parsePattern(ParserState $state): Ast\AstNode
{
    // A doc comment may sit in front of a pattern (a `case` alternative, a
    // `where`/`let` binding, a `do` binder, a lambda parameter).
    skipDocComments($state);

    return parsePatternInfix($state, 0);
}

/**
 * Atomic pattern: the form a function argument takes directly, without an
 * function-clause left-hand side takes (`funlhs -> var apat {apat}`), so no
 * top-level application is parsed here: `f True x = …` has the two atomic
 * arguments `True` and `x`, not the single constructor application `True x`.
 * A constructor with arguments must be parenthesised: `f (Just x) = …`.
 *
 * @return Ast\AstNode
 */
function parsePatternArg(ParserState $state): Ast\AstNode
{
    return parsePatternAtom($state);
}

/**
 * @return Ast\AstNode
 */
function parsePatternInfix(ParserState $state, int $minPrec): Ast\AstNode
{
    $left = parsePatternApplication($state);

    while (isAt($state, TokenKind::Op)) {
        $op = peek($state)->lexeme;
        $isListCons = $op === ':';
        $isCtorOp = isConstructorOperator($op);
        if (!$isListCons && !$isCtorOp) {
            break;
        }

        ['prec' => $prec, 'assoc' => $assoc] = fixityFor($state, $op);
        if ($prec < $minPrec) {
            break;
        }

        advance($state);
        $nextMin = $assoc === 'infixr' ? $prec : $prec + 1;
        $right = parsePatternInfix($state, $nextMin);
        $left = $isListCons
            ? new Ast\PatCons($left, $right)
            : new Ast\PatCon($op, [$left, $right]);
    }

    return $left;
}

/**
 * @return Ast\AstNode
 */
function parsePatternApplication(ParserState $state): Ast\AstNode
{
    $pattern = parsePatternAtom($state);

    if (! $pattern instanceof Ast\PatCon) {
        return $pattern;
    }

    $startLine = $pattern->line;

    while (startsPattern($state) && !isAt($state, TokenKind::Op, 0, '->')) {
        if ($state->inCaseAltPattern && caseAltBoundary($state, $startLine)) {
            throw incompleteCaseAltError(
                $state,
                'incomplete pattern `' . formatPatternPreview($pattern)
                . '`; expected `->` and body before alternative `' . describeUpcomingCaseAlt($state) . '`',
            );
        }

        $pattern = $pattern->withArg(parsePatternAtom($state));
    }

    return $pattern;
}

/**
 * @return Ast\AstNode
 */
function parsePatternAtom(ParserState $state): Ast\AstNode
{
    if (isAt($state, TokenKind::Integer)) {
        $token = advance($state);
        $pattern = spanned(new Ast\PatLit(integerLitValue($token->lexeme)), $token);
        if ((string) (int) $token->lexeme !== $token->lexeme) {
            $pattern->digits = $token->lexeme;
        }

        return $pattern;
    }

    if (isAt($state, TokenKind::StringLit)) {
        $token = advance($state);

        return spanned(new Ast\PatLit($token->lexeme), $token);
    }

    if (isAt($state, TokenKind::CharLit)) {
        $token = advance($state);

        return spanned(new Ast\PatChar((int) $token->lexeme), $token);
    }

    if (isAt($state, TokenKind::VarId, 0, '_')) {
        $token = advance($state);

        return spanned(new Ast\PatWild(), $token);
    }

    if (isAt($state, TokenKind::VarId)) {
        $token = advance($state);

        return spanned(new Ast\PatVar($token->lexeme), $token);
    }

    if (isAt($state, TokenKind::ConId)) {
        $token = advance($state);

        if (isAt($state, TokenKind::LBrace)) {
            return spanned(new Ast\PatRecord($token->lexeme, parseRecordPatFields($state)), $token);
        }

        return spanned(new Ast\PatCon($token->lexeme, []), $token);
    }

    if (isAt($state, TokenKind::LParen)) {
        return parsePatternParen($state);
    }

    if (isAt($state, TokenKind::LBracket)) {
        return parseListPattern($state);
    }

    throw unexpected($state, 'pattern');
}

/**
 * @return Ast\AstNode
 */
function parseListPattern(ParserState $state): Ast\AstNode
{
    $start = expect($state, TokenKind::LBracket);

    if (isAt($state, TokenKind::RBracket)) {
        advance($state);

        return spanned(new Ast\PatNil(), $start);
    }

    $first = parsePattern($state);

    if (!isAt($state, TokenKind::Comma)) {
        expect($state, TokenKind::RBracket);

        return spanned(new Ast\PatCons($first, new Ast\PatNil()), $start);
    }

    $elements = [$first];
    while (isAt($state, TokenKind::Comma)) {
        advance($state);
        $elements[] = parsePattern($state);
    }

    expect($state, TokenKind::RBracket);

    $pattern = new Ast\PatNil();
    foreach (array_reverse($elements) as $element) {
        $pattern = new Ast\PatCons($element, $pattern);
    }

    return spanned($pattern, $start);
}

/**
 * @return Ast\AstNode
 */
function parsePatternParen(ParserState $state): Ast\AstNode
{
    $open = expect($state, TokenKind::LParen);

    if (isAt($state, TokenKind::RParen)) {
        advance($state);

        return spanned(new Ast\PatTuple([]), $open);
    }

    $first = parsePattern($state);

    if (isAt($state, TokenKind::Comma)) {
        $elements = [$first];
        while (isAt($state, TokenKind::Comma)) {
            advance($state);
            $elements[] = parsePattern($state);
        }
        $close = expect($state, TokenKind::RParen);

        // The span must cover the opening parenthesis: a pattern's column is how
        // the offside rule locates a `where`/`let` binder, and one that reported
        // 0 let the block continue past a dedented declaration.
        return spannedRange(new Ast\PatTuple($elements), $open, $close);
    }

    expect($state, TokenKind::RParen);

    return $first;
}

function startsPattern(ParserState $state): bool
{
    $kind = peek($state)->kind;

    return $kind === TokenKind::Integer
        || $kind === TokenKind::StringLit
        || $kind === TokenKind::CharLit
        || $kind === TokenKind::VarId
        || $kind === TokenKind::ConId
        || $kind === TokenKind::LParen
        || $kind === TokenKind::LBracket;
}
