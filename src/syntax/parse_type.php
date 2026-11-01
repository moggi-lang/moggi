<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;

use function Moggi\Syntax\isConstructorOperator;

function parseKind(ParserState $state): Ast\KindNode
{
    $left = parseKindAtom($state);

    if (isAt($state, TokenKind::Op, 0, '->')) {
        advance($state);

        return new Ast\KindArrow($left, parseKind($state));
    }

    return $left;
}

function parseKindAtom(ParserState $state): Ast\KindNode
{
    if (isAt($state, TokenKind::LParen)) {
        advance($state);
        $kind = parseKind($state);
        expect($state, TokenKind::RParen);

        return $kind;
    }

    if (isAt($state, TokenKind::ConId, 0, 'Type')) {
        advance($state);

        return new Ast\KindType();
    }

    if (isAt($state, TokenKind::ConId)) {
        $token = advance($state);

        return new Ast\KindCon($token->lexeme, $token->line, $token->col, $token->col + \max(0, \strlen($token->lexeme) - 1));
    }

    throw unexpected($state, 'kind');
}

function parseType(ParserState $state): Ast\TypeNode
{
    if (isForallStart($state)) {
        return parseForall($state);
    }

    $first = parseTypeInfix($state);

    if (isAt($state, TokenKind::Comma)) {
        $before = $state->pos;
        $constraints = [$first];
        while (isAt($state, TokenKind::Comma)) {
            advance($state);
            $constraints[] = parseTypeInfix($state);
        }

        if (isAt($state, TokenKind::Op) && peek($state)->lexeme === '=>') {
            advance($state);

            return new Ast\TypeConstrained($constraints, parseType($state));
        }

        $state->pos = $before;
    }

    if (isAt($state, TokenKind::Op) && peek($state)->lexeme === '=>') {
        advance($state);

        return new Ast\TypeConstrained([$first], parseType($state));
    }

    if (isAt($state, TokenKind::Op, 0, '->')) {
        advance($state);

        return new Ast\TypeArrow($first, parseType($state));
    }

    return $first;
}

/**
 * @return Ast\TypeNode
 */
function parseTypeNoConstraint(ParserState $state): Ast\TypeNode
{
    if (isForallStart($state)) {
        return parseForall($state);
    }

    $first = parseTypeInfix($state);

    if (isAt($state, TokenKind::Op, 0, '->')) {
        advance($state);

        return new Ast\TypeArrow($first, parseTypeNoConstraint($state));
    }

    return $first;
}

/**
 * Infix type operators (`f :+: g`), left-associative. Reserved ops (`->`, `=>`,
 * `::`, …) are not type operators.
 *
 * @return Ast\TypeNode
 */
function parseTypeInfix(ParserState $state): Ast\TypeNode
{
    $left = parseTypeAtom($state);

    while (
        isAt($state, TokenKind::Op)
        && isConstructorOperator(peek($state)->lexeme)
    ) {
        $opToken = advance($state);
        $right = parseTypeAtom($state);
        $app = new Ast\TypeApp(new Ast\TypeCon($opToken->lexeme), [$left, $right]);
        $app->setLocation(
            $opToken->line,
            $opToken->col,
            $opToken->col + \max(0, \strlen($opToken->lexeme) - 1),
        );
        $left = $app;
    }

    return $left;
}

/**
 * @return Ast\TypeNode
 */
function parseTypeAtom(ParserState $state): Ast\TypeNode
{
    $head = parseTypeHead($state);

    $canApply = $head instanceof Ast\TypeCon
        || $head instanceof Ast\TypeQualified
        || $head instanceof Ast\TypeVar
        || $head instanceof Ast\TypePromoted
        || (
            $head instanceof Ast\TypeApp
            && $head->con instanceof Ast\TypeCon
            && isConstructorOperator($head->con->name)
        );
    if (! $canApply) {
        return $head;
    }

    $args = [];
    $headLine = $state->tokens[$state->pos - 1]->line;
    while (isTypeArgStart($state, $headLine)) {
        $args[] = parseTypeHead($state);
    }

    if (\count($args) === 0) {
        return $head;
    }

    if ($head instanceof Ast\TypeApp) {
        $app = new Ast\TypeApp($head->con, [...$head->args, ...$args]);
        if ($head->line !== 0) {
            $app->setLocation($head->line, $head->col, $head->endCol);
        }

        return $app;
    }

    $app = new Ast\TypeApp($head, $args);
    if ($head instanceof Ast\AstNode && $head->line !== 0) {
        $app->setLocation($head->line, $head->col, $head->endCol);
    }

    return $app;
}

function withTypeLocation(Ast\TypeNode $type, Token $token): Ast\TypeNode
{
    if ($type instanceof Ast\AstNode) {
        $type->setLocation($token->line, $token->col, $token->col + \max(0, \strlen($token->lexeme) - 1));
    }

    return $type;
}

/**
 * @return Ast\TypeNode
 */
function parseTypeHead(ParserState $state): Ast\TypeNode
{
    if (isAt($state, TokenKind::LBracket)) {
        advance($state);
        $inner = parseType($state);
        expect($state, TokenKind::RBracket);

        return new Ast\TypeApp(new Ast\TypeCon('List'), [$inner]);
    }

    if (isAt($state, TokenKind::PromotedCon)) {
        $token = advance($state);

        return withTypeLocation(new Ast\TypePromoted($token->lexeme), $token);
    }

    if (isAt($state, TokenKind::StringLit)) {
        $token = advance($state);
        $node = new Ast\TypeStringLit($token->lexeme);
        $node->setLocation(
            $token->line,
            $token->col,
            $token->col + \strlen($token->lexeme) + 1,
        );

        return $node;
    }

    if (isAt($state, TokenKind::Op, 0, '-') && isAt($state, TokenKind::Integer, 1)) {
        $minus = advance($state);
        $int = advance($state);
        $node = new Ast\TypeNatLit($int->lexeme, true);
        $node->setLocation(
            $minus->line,
            $minus->col,
            $int->col + \max(0, \strlen($int->lexeme) - 1),
        );

        return $node;
    }

    if (isAt($state, TokenKind::Integer)) {
        $token = advance($state);
        $node = new Ast\TypeNatLit($token->lexeme, false);
        $node->setLocation(
            $token->line,
            $token->col,
            $token->col + \max(0, \strlen($token->lexeme) - 1),
        );

        return $node;
    }

    if (isAt($state, TokenKind::VarId) || isAt($state, TokenKind::ConId)) {
        $token = advance($state);
        if (isAt($state, TokenKind::Op, 0, '.') && isAt($state, TokenKind::ConId, 1)) {
            advance($state);
            $name = expect($state, TokenKind::ConId)->lexeme;

            return withTypeLocation(new Ast\TypeQualified($token->lexeme, $name), $token);
        }

        return withTypeLocation(
            $token->kind === TokenKind::VarId
                ? new Ast\TypeVar($token->lexeme)
                : new Ast\TypeCon($token->lexeme),
            $token,
        );
    }

    if (isAt($state, TokenKind::LParen)) {
        advance($state);
        if (isAt($state, TokenKind::RParen)) {
            advance($state);

            return new Ast\TypeUnit();
        }

        if (
            isAt($state, TokenKind::Op)
            && isConstructorOperator(peek($state)->lexeme)
            && isAt($state, TokenKind::RParen, 1)
        ) {
            $opToken = advance($state);
            expect($state, TokenKind::RParen);

            return withTypeLocation(new Ast\TypeCon($opToken->lexeme), $opToken);
        }

        if (isAt($state, TokenKind::VarId) && isAt($state, TokenKind::Op, 1, '::')) {
            $name = expect($state, TokenKind::VarId)->lexeme;
            expectOp($state, '::');
            $kind = parseKind($state);
            expect($state, TokenKind::RParen);

            return new Ast\TypeKindAnnot($name, $kind);
        }

        $saved = $state->pos;
        $first = parseTypeNoConstraint($state);
        if (isAt($state, TokenKind::Comma)) {
            $elements = [$first];
            while (isAt($state, TokenKind::Comma)) {
                advance($state);
                $elements[] = parseTypeNoConstraint($state);
            }
            expect($state, TokenKind::RParen);

            if (isAt($state, TokenKind::Op) && peek($state)->lexeme === '=>') {
                advance($state);

                return new Ast\TypeConstrained($elements, parseType($state));
            }

            return new Ast\TypeApp(new Ast\TypeCon('Tuple' . \count($elements)), $elements);
        }

        if (isAt($state, TokenKind::RParen)) {
            advance($state);

            return $first;
        }

        $state->pos = $saved;
        $type = parseType($state);
        expect($state, TokenKind::RParen);

        return $type;
    }

    throw unexpected($state, 'type');
}

function isTypeArgStart(ParserState $state, ?int $headLine = null): bool
{
    if ($headLine !== null && peek($state)->line !== $headLine) {
        return false;
    }

    if (isAt($state, TokenKind::Op, 0, '->')) {
        return false;
    }

    $kind = peek($state)->kind;

    if ($kind === TokenKind::ConId
        || $kind === TokenKind::PromotedCon
        || $kind === TokenKind::StringLit
        || $kind === TokenKind::Integer
        || $kind === TokenKind::LParen
        || $kind === TokenKind::LBracket
    ) {
        return true;
    }

    if ($kind === TokenKind::Op
        && peek($state)->lexeme === '-'
        && isAt($state, TokenKind::Integer, 1)
        && ($headLine === null || peekAt($state, 1)->line === $headLine)
    ) {
        return true;
    }

    if ($kind === TokenKind::VarId) {
        if (isAt($state, TokenKind::Op, 1, '::')) {
            return false;
        }

        if (isForallStart($state)) {
            return false;
        }

        if (looksLikeSameLineFunctionDecl($state)
            || looksLikeInfixFunctionDecl($state)
            || looksLikeBacktickFunctionDecl($state)) {
            return false;
        }

        return true;
    }

    return false;
}

/** Is the next token the `forall` of an explicit quantifier in type syntax? */
function isForallStart(ParserState $state): bool
{
    return isAt($state, TokenKind::VarId) && peek($state)->lexeme === 'forall';
}

/**
 * `forall a b. body`, `forall (a :: k). body`.
 *
 * `forall` is not a keyword: it is a `VarId` that starts a quantifier only here,
 * in type syntax, so a program may still name a value `forall` (Haskell does the
 * same). The binder list is non-empty and each binder is either a bare variable
 * or a kind-annotated one in parentheses; a single `.` ends the list.
 */
function parseForall(ParserState $state): Ast\TypeNode
{
    $head = parseForallHead($state);
    $node = new Ast\TypeForall($head['binders'], $head['kinds'], parseType($state));

    return withTypeLocation($node, $head['token']);
}

/**
 * The binder half of a `forall`, up to and including its `.`.
 *
 * Separate from {@see parseForall} because a GADT constructor's signature may
 * write a record field block between the quantifier and the result type
 * (`C :: forall a. { get :: a } -> T a`), where the body is not something the
 * ordinary type grammar can parse.
 *
 * @return array{binders: list<string>, kinds: list<?Ast\KindNode>, token: Token}
 */
function parseForallHead(ParserState $state): array
{
    $forallToken = advance($state);
    $binders = [];
    $kinds = [];

    while (true) {
        if (isAt($state, TokenKind::VarId)) {
            $binder = advance($state);
            $binders[] = $binder->lexeme;
            $kinds[] = null;

            continue;
        }

        if (
            isAt($state, TokenKind::LParen)
            && isAt($state, TokenKind::VarId, 1)
            && isAt($state, TokenKind::Op, 2, '::')
        ) {
            advance($state);
            $binder = expect($state, TokenKind::VarId);
            expectOp($state, '::');
            $kind = parseKind($state);
            expect($state, TokenKind::RParen);
            $binders[] = $binder->lexeme;
            $kinds[] = $kind;

            continue;
        }

        break;
    }

    if ($binders === []) {
        throw parseError($state, '`forall` needs at least one type variable: write `forall a. …`');
    }

    $seen = [];
    foreach ($binders as $binder) {
        if (isset($seen[$binder])) {
            throw parseError($state, "`forall` binds `{$binder}` twice; a binder may appear once");
        }
        $seen[$binder] = true;
    }

    if (! isAt($state, TokenKind::Op) || peek($state)->lexeme !== '.') {
        throw parseError($state, 'expected `.` after a `forall` binder list: write `forall a. …`');
    }
    advance($state);

    return ['binders' => $binders, 'kinds' => $kinds, 'token' => $forallToken];
}
