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

    while (! isAt($state, TokenKind::RBrace)) {
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
    skipDocComments($state);

    return parsePatternInfix($state, 0);
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
        if (! $isListCons && ! $isCtorOp) {
            break;
        }

        ['precedence' => $prec, 'assoc' => $assoc] = fixityFor($state, $op);
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

    while (startsPattern($state) && ! isAt($state, TokenKind::Op, 0, '->')) {
        if ($state->inCaseAltPattern && caseAltBoundary($state, $startLine)) {
            throw incompleteCaseAltError(
                $state,
                'incomplete pattern `' . formatPatternPreview($pattern)
                . '`; expected `->` and body before alternative `' . describeUpcomingCaseAlt($state) . '`',
            );
        }

        $pattern = $pattern->withArg(parsePatternAtom($state));
    }

    return expandPatternSynonymUse($state, $pattern);
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
        $name = $token->lexeme;
        while (isAt($state, TokenKind::Op, 0, '.')
            && isAt($state, TokenKind::ConId, 1)
            && isTightProjectionDot($state, peek($state), peekAt($state, 1))
        ) {
            advance($state);
            $name .= '.' . advance($state)->lexeme;
        }

        if (isAt($state, TokenKind::LBrace)) {
            return spanned(new Ast\PatRecord($name, parseRecordPatFields($state)), $token);
        }

        $con = spanned(new Ast\PatCon($name, []), $token);
        $synonym = $state->patternSynonyms[$name] ?? null;
        if ($synonym !== null && $synonym['params'] === []) {
            return expandPatternSynonymUse($state, $con);
        }

        return $con;
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

    if (! isAt($state, TokenKind::Comma)) {
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
    foreach (\array_reverse($elements) as $element) {
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

    $view = tryParseViewPattern($state, $open);
    if ($view !== null) {
        return $view;
    }

    $first = parsePattern($state);

    if (isAt($state, TokenKind::Comma)) {
        $elements = [$first];
        while (isAt($state, TokenKind::Comma)) {
            advance($state);
            $elements[] = parsePattern($state);
        }
        $close = expect($state, TokenKind::RParen);

        return spannedRange(new Ast\PatTuple($elements), $open, $close);
    }

    expect($state, TokenKind::RParen);

    return $first;
}

/**
 * `(expr -> pattern)`: the parenthesised view pattern, or null with the cursor
 * left where it was when the group is an ordinary pattern.
 */
function tryParseViewPattern(ParserState $state, Token $open): ?Ast\AstNode
{
    if (! parenContainsViewArrow($state)) {
        return null;
    }

    $saved = $state->pos;

    try {
        $expression = parseExprWithWhere($state);
        expectOp($state, '->');
        $pattern = parsePattern($state);
        $close = expect($state, TokenKind::RParen);

        return spannedRange(new Ast\PatView($expression, $pattern), $open, $close);
    } catch (ParseError) {
        $state->pos = $saved;

        return null;
    }
}

/**
 * Whether a `->` at this bracket depth closes the group into a view before the
 * group's own `)`.
 *
 * The scan keeps every parenthesised, tuple and record pattern that has no
 * arrow from being parsed as an expression first, so the ordinary case pays a
 * token scan rather than a speculative expression parse.
 */
function parenContainsViewArrow(ParserState $state): bool
{
    $depth = 0;
    $count = \count($state->tokens);

    for ($i = $state->pos; $i < $count; ++$i) {
        $token = $state->tokens[$i];
        $kind = $token->kind;

        if ($kind === TokenKind::LParen || $kind === TokenKind::LBracket || $kind === TokenKind::LBrace) {
            ++$depth;
            continue;
        }

        if ($kind === TokenKind::RParen || $kind === TokenKind::RBracket || $kind === TokenKind::RBrace) {
            if ($depth === 0) {
                return false;
            }
            --$depth;
            continue;
        }

        if ($kind === TokenKind::Op && $token->lexeme === '->' && $depth === 0) {
            return true;
        }

        if ($kind === TokenKind::Eof) {
            return false;
        }
    }

    return false;
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

/**
 * Validate and record `pattern Name p1 … pn = matcher`.
 *
 * Every variable the matcher binds must be one of the parameters, exactly
 * once, so an expansion substitutes arguments for formals and never leaves a
 * name free. `pattern Name :: type` alone carries no matcher and registers
 * nothing to expand.
 */
function registerPatternSynonym(ParserState $state, Ast\PatternSynonymDecl $decl): void
{
    $matcher = $decl->matcher;
    if ($matcher === null) {
        return;
    }

    $params = [];
    foreach ($decl->params as $param) {
        if (isset($params[$param])) {
            throw patternSynonymError($state, $decl, "pattern synonym `{$decl->name}` binds `{$param}` twice");
        }
        $params[$param] = true;
    }

    $bound = [];
    foreach (patternSynonymBinderNames($matcher) as $name) {
        if (! isset($params[$name])) {
            throw patternSynonymError(
                $state,
                $decl,
                "pattern synonym `{$decl->name}` matcher binds `{$name}`, which is not a parameter",
            );
        }
        if (isset($bound[$name])) {
            throw patternSynonymError(
                $state,
                $decl,
                "pattern synonym `{$decl->name}` binds parameter `{$name}` more than once",
            );
        }
        $bound[$name] = true;
    }

    $state->patternSynonyms[$decl->name] = [
        'params' => $decl->params,
        'matcher' => $matcher,
        'builder' => $decl->builder,
    ];
}

/**
 * Replace a synonym's formals with the arguments of one use.
 *
 * The expansion keeps the matcher's own spans, except at the root, which takes
 * the use's span so a later diagnostic points at the pattern the source
 * wrote.
 */
function expandPatternSynonymUse(ParserState $state, Ast\PatCon $use): Ast\AstNode
{
    $synonym = $state->patternSynonyms[$use->name] ?? null;
    if ($synonym === null) {
        return $use;
    }

    $arity = \count($synonym['params']);
    if (\count($use->args) !== $arity) {
        throw parseError(
            $state,
            "pattern synonym `{$use->name}` expects {$arity} argument(s), got " . \count($use->args),
            null,
            'parse/pattern-synonym',
        );
    }

    $expanded = substitutePatternSynonymFormals(
        $synonym['matcher'],
        \array_combine($synonym['params'], $use->args),
    );
    $expanded->setLocation($use->line, $use->col, $use->endCol);

    return $expanded;
}

/**
 * @param array<string, Ast\AstNode> $arguments formal name → argument pattern
 */
function substitutePatternSynonymFormals(Ast\AstNode $pattern, array $arguments): Ast\AstNode
{
    return match ($pattern::class) {
        Ast\PatVar::class => $arguments[$pattern->name] ?? clone $pattern,
        Ast\PatCon::class => new Ast\PatCon(
            $pattern->name,
            \array_map(static fn (Ast\AstNode $arg): Ast\AstNode => substitutePatternSynonymFormals($arg, $arguments), $pattern->args),
            $pattern->line,
            $pattern->col,
            $pattern->endCol,
        ),
        Ast\PatCons::class => new Ast\PatCons(
            substitutePatternSynonymFormals($pattern->head, $arguments),
            substitutePatternSynonymFormals($pattern->tail, $arguments),
            $pattern->line,
            $pattern->col,
            $pattern->endCol,
        ),
        Ast\PatTuple::class => new Ast\PatTuple(
            \array_map(static fn (Ast\AstNode $element): Ast\AstNode => substitutePatternSynonymFormals($element, $arguments), $pattern->elements),
            $pattern->line,
            $pattern->col,
            $pattern->endCol,
        ),
        Ast\PatView::class => new Ast\PatView(
            $pattern->expression,
            substitutePatternSynonymFormals($pattern->pattern, $arguments),
            $pattern->line,
            $pattern->col,
            $pattern->endCol,
        ),
        Ast\PatRecord::class => new Ast\PatRecord(
            $pattern->name,
            \array_map(static fn (Ast\PatField $field): Ast\PatField => new Ast\PatField(
                $field->name,
                substitutePatternSynonymFormals($field->pattern, $arguments),
                $field->line,
                $field->col,
                $field->endCol,
            ), $pattern->fields),
            $pattern->line,
            $pattern->col,
            $pattern->endCol,
        ),
        default => clone $pattern,
    };
}

/**
 * Names a matcher binds, in source order, duplicates kept.
 *
 * @return list<string>
 */
function patternSynonymBinderNames(Ast\AstNode $pattern): array
{
    return match ($pattern::class) {
        Ast\PatVar::class => [$pattern->name],
        Ast\PatCon::class => \array_merge(...\array_map(
            static fn (Ast\AstNode $arg): array => patternSynonymBinderNames($arg),
            $pattern->args,
        ) ?: []),
        Ast\PatCons::class => [
            ...patternSynonymBinderNames($pattern->head),
            ...patternSynonymBinderNames($pattern->tail),
        ],
        Ast\PatTuple::class => \array_merge(...\array_map(
            static fn (Ast\AstNode $element): array => patternSynonymBinderNames($element),
            $pattern->elements,
        ) ?: []),
        Ast\PatRecord::class => \array_merge(...\array_map(
            static fn (Ast\PatField $field): array => patternSynonymBinderNames($field->pattern),
            $pattern->fields,
        ) ?: []),
        Ast\PatView::class => patternSynonymBinderNames($pattern->pattern),
        default => [],
    };
}

function patternSynonymError(ParserState $state, Ast\PatternSynonymDecl $decl, string $message): ParseError
{
    return new ParseError(
        $message,
        $state->filename,
        $state->source,
        $decl->line,
        $decl->col,
        $decl->endCol,
        'parse/pattern-synonym',
    );
}
