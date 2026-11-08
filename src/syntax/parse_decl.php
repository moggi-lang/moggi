<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;

use function Moggi\Syntax\isConstructorOperator;
use function Moggi\Syntax\Lexer\isIdCont;
use function Moggi\Syntax\Lexer\isLetter;
use function Moggi\Syntax\Lexer\isSymbol;

/**
 * Top-level declaration, or null if the next tokens are an expression/statement.
 *
 * @return Ast\AstNode|null
 */
function parseTopLevelDecl(ParserState $state): ?Ast\AstNode
{
    if (isAt($state, TokenKind::VarId, 0, 'backend')
        && isAt($state, TokenKind::VarId, 1)
        && isAt($state, TokenKind::ConId, 2)
    ) {
        throw parseError(
            $state,
            'a module names its implementations in a `{-# BACKEND … #-}` pragma above the module '
                . 'header, as in `{-# BACKEND php = Data.Char.PHP #-}`',
            peek($state),
            'parse/backend',
        );
    }

    if (isAt($state, TokenKind::Pragma)) {
        throw parseError(
            $state,
            'a pragma comes before the module header',
            peek($state),
            'parse/pragma',
        );
    }

    if (isAt($state, TokenKind::KwImport)) {
        return parseImportDecl($state);
    }

    if (isAt($state, TokenKind::KwForeign)) {
        return parseForeignDecl($state);
    }

    if ($state->backendMap !== [] && isAt($state, TokenKind::VarId) && isAt($state, TokenKind::Op, 1, '::')) {
        return parseSignatureFunction($state);
    }

    if (isAt($state, TokenKind::KwClass)) {
        return parseClassDecl($state);
    }

    if (isAt($state, TokenKind::KwDeriving) && looksLikeStandaloneDeriving($state)) {
        return parseStandaloneDeriving($state);
    }

    if (isAt($state, TokenKind::KwInstance)) {
        return parseInstanceDecl($state);
    }

    if (isAt($state, TokenKind::KwData)) {
        return parseDataDecl($state);
    }

    if (isAt($state, TokenKind::KwNewtype)) {
        return parseNewtypeDecl($state);
    }

    if (isAt($state, TokenKind::KwType)) {
        return parseTypeSynonymDecl($state);
    }

    if (isAt($state, TokenKind::LParen)
        && isAt($state, TokenKind::Op, 1)
        && isAt($state, TokenKind::RParen, 2)
        && isAt($state, TokenKind::Op, 3, '::')) {
        return parseTypedInfixFunction($state);
    }

    if (isAt($state, TokenKind::VarId) && isAt($state, TokenKind::Op, 1, '::')) {
        return parseTypedFunction($state);
    }

    if (looksLikeParenOperatorMethodDecl($state)) {
        return parseParenOperatorFunctionDecl($state);
    }

    if (looksLikeInfixFunctionDecl($state)) {
        return parseInferredInfixFunction($state);
    }

    if (looksLikeBacktickFunctionDecl($state)) {
        return parseInferredBacktickFunction($state);
    }

    if (isAt($state, TokenKind::VarId) && looksLikeFunctionDecl($state)) {
        return parseInferredPrefixFunction($state);
    }

    return null;
}

/**
 * @return Ast\AstNode
 */
function parseTopLevel(ParserState $state): Ast\AstNode
{
    $start = peek($state);
    $decl = parseTopLevelDecl($state) ?? parseExprWithWhere($state);

    if ($decl instanceof Ast\FunctionDecl && $decl->line === 0) {
        $decl->setLocation(
            $start->line,
            $start->col,
            $start->col + \max(0, \strlen($decl->name) - 1),
        );
    }

    return $decl;
}

/**
 * @return Ast\FunctionDecl
 */
function parseInferredPrefixFunction(ParserState $state): Ast\FunctionDecl
{
    $name = expect($state, TokenKind::VarId)->lexeme;
    $params = [];
    while (startsPattern($state) && ! isAt($state, TokenKind::Op, 0, '=') && ! isAt($state, TokenKind::Pipe)) {
        $params[] = parsePatternAtom($state);
    }
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl($name, null, $params, $body);
}

/**
 * @return Ast\FunctionDecl
 */
function parseInferredInfixFunction(ParserState $state): Ast\FunctionDecl
{
    $left = advance($state)->lexeme;
    $name = advance($state)->lexeme;
    $right = expect($state, TokenKind::VarId)->lexeme;
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl($name, null, [new Ast\PatVar($left), new Ast\PatVar($right)], $body);
}

/**
 * @return Ast\FunctionDecl
 */
function parseParenOperatorFunctionDecl(ParserState $state): Ast\FunctionDecl
{
    advance($state);
    $name = expect($state, TokenKind::Op)->lexeme;
    expect($state, TokenKind::RParen);
    $params = [];
    while (startsPattern($state) && ! isAt($state, TokenKind::Op, 0, '=') && ! isAt($state, TokenKind::Pipe)) {
        $params[] = parsePatternAtom($state);
    }
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl($name, null, $params, $body);
}

/**
 * @return Ast\FunctionDecl
 */
function parseInferredBacktickFunction(ParserState $state): Ast\FunctionDecl
{
    $left = advance($state)->lexeme;
    advance($state);
    $name = expect($state, TokenKind::VarId)->lexeme;
    expect($state, TokenKind::Backtick);
    $right = expect($state, TokenKind::VarId)->lexeme;
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl($name, null, [new Ast\PatVar($left), new Ast\PatVar($right)], $body);
}

/**
 * @return Ast\FunctionDecl
 */
function parseTypedFunction(ParserState $state): Ast\FunctionDecl
{
    $name = expect($state, TokenKind::VarId)->lexeme;
    expectOp($state, '::');
    $type = parseType($state);
    $sigTrailing = consumeTrailingDoc($state);

    $decl = finishTypedFunction($state, $name, $type);
    if ($sigTrailing !== null) {
        $decl->doc = mergeDoc($decl->doc, $sigTrailing);
    }

    return $decl;
}

/**
 * @return Ast\FunctionDecl
 */
function parseTypedInfixFunction(ParserState $state): Ast\FunctionDecl
{
    expect($state, TokenKind::LParen);
    $name = expect($state, TokenKind::Op)->lexeme;
    expect($state, TokenKind::RParen);
    expectOp($state, '::');
    $type = parseType($state);
    $sigTrailing = consumeTrailingDoc($state);
    $decl = finishTypedFunction($state, $name, $type);
    if ($sigTrailing !== null) {
        $decl->doc = mergeDoc($decl->doc, $sigTrailing);
    }

    return $decl;
}

/**
 * @return Ast\FunctionDecl
 */
function finishTypedFunction(ParserState $state, string $name, Ast\TypeNode $type): Ast\FunctionDecl
{
    if (looksLikeInfixFunctionDecl($state)) {
        return parseInfixFunctionDecl($state, $name, $type);
    }

    if (looksLikeBacktickFunctionDecl($state)) {
        return parseBacktickFunctionDecl($state, $name, $type);
    }

    if (looksLikeParenOperatorMethodDecl($state)) {
        return parseTypedParenOperatorFunctionDecl($state, $name, $type);
    }

    if (isAt($state, TokenKind::VarId, 0, $name) && looksLikeFunctionDecl($state)) {
        return parsePrefixFunctionDecl($state, $name, $type);
    }

    if (
        ! looksLikeInfixFunctionDecl($state)
        && ! looksLikeBacktickFunctionDecl($state)
        && ! looksLikeParenOperatorMethodDecl($state)
    ) {
        return new Ast\FunctionDecl($name, $type, [], new Ast\SignatureOnly(), true);
    }

    throw parseError($state, "expected function definition for `{$name}`");
}

function looksLikeFunctionDecl(ParserState $state): bool
{
    $saved = $state->pos;

    try {
        $startLine = peek($state)->line;

        while (peek($state)->line === $startLine
            && startsPattern($state)
            && ! isAt($state, TokenKind::Op, 0, '=')
            && ! isAt($state, TokenKind::Pipe)) {
            parsePatternAtom($state);
        }

        if (isAt($state, TokenKind::Op, 0, '=')) {
            return true;
        }

        if (! isAt($state, TokenKind::Pipe)) {
            return false;
        }

        return peek($state)->line > $startLine || ! insideBrackets($state);
    } catch (ParseError) {
        return false;
    } finally {
        $state->pos = $saved;
    }
}

/**
 * @return Ast\AstNode
 */
function parseFunctionBody(ParserState $state): Ast\AstNode
{
    if (isAt($state, TokenKind::Pipe)) {
        return parseGuardBody($state);
    }

    expectOp($state, '=');

    return parseExprWithWhere($state);
}

/**
 * Parse `| guard = body` clauses (or `| guard -> body` in a case alternative).
 *
 * @return Ast\GuardsExpr
 */
function parseGuardBody(ParserState $state, string $separator = '=', bool $caseAlt = false): Ast\GuardsExpr
{
    $clauses = [];

    while (isAt($state, TokenKind::Pipe)) {
        advance($state);
        $state->parsingGuardExpr = true;
        $guard = parseInfix($state, 0);
        $state->parsingGuardExpr = false;
        expectOp($state, $separator);
        $state->stopBeforeGuardClause = true;
        $state->guardRhsLine = peek($state)->line;
        $body = $caseAlt ? parseCaseAltBody($state) : parseExprWithWhere($state);
        $state->stopBeforeGuardClause = false;
        $state->guardRhsLine = null;
        $clauses[] = new Ast\Guarded($guard, $body);
    }

    if ($clauses === []) {
        throw parseError($state, 'expected guarded clause');
    }

    return new Ast\GuardsExpr($clauses);
}

function looksLikeSameLineFunctionDecl(ParserState $state): bool
{
    $saved = $state->pos;
    $startLine = peek($state)->line;

    try {
        while (startsPattern($state) && peek($state)->line === $startLine) {
            parsePatternAtom($state);
        }

        return peek($state)->line === $startLine && isAt($state, TokenKind::Op, 0, '=');
    } catch (ParseError) {
        return false;
    } finally {
        $state->pos = $saved;
    }
}

function looksLikeInfixFunctionDecl(ParserState $state): bool
{
    return isAt($state, TokenKind::VarId)
        && isAt($state, TokenKind::Op, 1)
        && ! isAt($state, TokenKind::Op, 1, '=')
        && ! isAt($state, TokenKind::Op, 1, '::')
        && isAt($state, TokenKind::VarId, 2)
        && isAt($state, TokenKind::Op, 3, '=');
}

function looksLikeBacktickFunctionDecl(ParserState $state): bool
{
    return isAt($state, TokenKind::VarId)
        && isAt($state, TokenKind::Backtick, 1)
        && isAt($state, TokenKind::VarId, 2)
        && isAt($state, TokenKind::Backtick, 3)
        && isAt($state, TokenKind::VarId, 4)
        && isAt($state, TokenKind::Op, 5, '=');
}

/**
 * @return Ast\FunctionDecl
 */
function parseTypedParenOperatorFunctionDecl(ParserState $state, string $name, Ast\TypeNode $type): Ast\FunctionDecl
{
    advance($state);
    $operator = expect($state, TokenKind::Op)->lexeme;
    if ($operator !== $name) {
        throw nameMismatch($state, $name, $operator);
    }
    expect($state, TokenKind::RParen);
    $params = [];
    while (startsPattern($state) && ! isAt($state, TokenKind::Op, 0, '=') && ! isAt($state, TokenKind::Pipe)) {
        $params[] = parsePatternAtom($state);
    }
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl($name, $type, $params, $body);
}

/**
 * @return Ast\FunctionDecl
 */
function parseInfixFunctionDecl(ParserState $state, string $name, Ast\TypeNode $type): Ast\FunctionDecl
{
    $left = advance($state)->lexeme;
    $operator = advance($state)->lexeme;
    if ($operator !== $name) {
        throw nameMismatch($state, $name, $operator);
    }
    $right = expect($state, TokenKind::VarId)->lexeme;
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl($name, $type, [new Ast\PatVar($left), new Ast\PatVar($right)], $body);
}

/**
 * @return Ast\FunctionDecl
 */
function parseBacktickFunctionDecl(ParserState $state, string $name, Ast\TypeNode $type): Ast\FunctionDecl
{
    $left = advance($state)->lexeme;
    advance($state);
    $operator = expect($state, TokenKind::VarId)->lexeme;
    if ($operator !== $name) {
        throw nameMismatch($state, $name, $operator);
    }
    expect($state, TokenKind::Backtick);
    $right = expect($state, TokenKind::VarId)->lexeme;
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl($name, $type, [new Ast\PatVar($left), new Ast\PatVar($right)], $body);
}

/**
 * @return Ast\FunctionDecl
 */
function parsePrefixFunctionDecl(ParserState $state, string $name, Ast\TypeNode $type): Ast\FunctionDecl
{
    $declName = expect($state, TokenKind::VarId)->lexeme;
    if ($declName !== $name) {
        throw nameMismatch($state, $name, $declName);
    }

    $params = [];
    while (startsPattern($state) && ! isAt($state, TokenKind::Op, 0, '=') && ! isAt($state, TokenKind::Pipe)) {
        $params[] = parsePatternAtom($state);
    }
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl($name, $type, $params, $body);
}

/**
 * @return Ast\DataDecl
 */
/**
 * The `where` block of a GADT-style declaration.
 *
 * Each item is `C, D :: τ`, where `τ` is a constructor type: an optional
 * `forall`, an optional context and then the fields and the data type the
 * constructor returns. The result type is kept as written, because that is the
 * whole point of the form — `C :: Int -> T Int` says more than a field list can.
 *
 * The block ends at the first token written at or left of the `data` keyword, so
 * it composes with the layout the rest of the language already uses.
 *
 * @return list<Ast\ConstructorDecl>
 */
function parseGadtConstructorBlock(ParserState $state, Token $dataToken): array
{
    expect($state, TokenKind::KwWhere);

    $constructors = [];
    while (! isAt($state, TokenKind::Eof)) {
        if (peek($state)->col <= $dataToken->col) {
            break;
        }
        if (isAt($state, TokenKind::KwDeriving)) {
            break;
        }
        if (isAt($state, TokenKind::Pragma)) {
            advance($state);

            continue;
        }

        $leadingDoc = consumeLeadingDoc($state);
        $names = [expect($state, TokenKind::ConId)];
        while (isAt($state, TokenKind::Comma)) {
            advance($state);
            $names[] = expect($state, TokenKind::ConId);
        }
        expectOp($state, '::');

        [$fields, $signature] = parseGadtConstructorSignature($state);

        foreach ($names as $nameToken) {
            $ctor = new Ast\ConstructorDecl(
                $nameToken->lexeme,
                $fields,
                $nameToken->line,
                $nameToken->col,
                $nameToken->col + \max(0, \strlen($nameToken->lexeme) - 1),
                signature: $signature,
            );
            if ($leadingDoc !== null) {
                $ctor->doc = $leadingDoc;
            }
            $constructors[] = $ctor;
        }

        $trailing = consumeTrailingDoc($state);
        if ($trailing !== null && $constructors !== []) {
            $last = $constructors[\count($constructors) - 1];
            $last->doc = mergeDoc($last->doc, $trailing);
        }
    }

    if ($constructors === []) {
        throw parseError($state, 'a `where` data declaration needs at least one constructor');
    }

    return $constructors;
}

/**
 * Whether the tokens ahead are a constructor context (`Show a, Eq b =>`) that
 * the record field block of a GADT signature follows.
 *
 * The context's constraint types are ordinary types, so they can only be parsed
 * once it is known that a `{` — which is not a type at all — is what comes
 * after them; this lookahead decides that without consuming anything. Brackets
 * are skipped: `Eq (f a)` is one constraint on an application, not the end of
 * the context, and a `->` before any `=>` means the signature is an ordinary
 * function type with no record block.
 */
function gadtRecordFollowsContext(ParserState $state): bool
{
    $depth = 0;
    $count = \count($state->tokens);
    for ($i = $state->pos; $i < $count; ++$i) {
        $token = $state->tokens[$i];
        switch ($token->kind) {
            case TokenKind::LParen:
            case TokenKind::LBracket:
                ++$depth;

                continue 2;
            case TokenKind::RParen:
            case TokenKind::RBracket:
                --$depth;

                continue 2;
            case TokenKind::LBrace:
            case TokenKind::RBrace:
            case TokenKind::Eof:
                return false;
            case TokenKind::Op:
                break;
            default:

                continue 2;
        }

        if ($depth !== 0) {
            continue;
        }

        if ($token->lexeme === '=>') {
            return $i + 1 < $count && $state->tokens[$i + 1]->kind === TokenKind::LBrace;
        }

        if ($token->lexeme === '->') {
            return false;
        }
    }

    return false;
}

/**
 * One GADT constructor's type, in either field spelling, with the quantifier
 * and context that may precede either:
 *
 *   C :: Int -> Bool -> T Int
 *   C :: { left :: Int, right :: Bool } -> T Int
 *   C :: forall a. Show a => { shown :: a } -> T a
 *
 * A record block states its fields as a record, so the arrow list around it is
 * not a type the ordinary grammar can produce: the fields sit between the
 * prefix (`forall`, context) and the `->` result type. The record form returns
 * its fields (the selectors name them) and one synthetic signature over them,
 * so everything downstream sees one shape.
 *
 * @return array{0: list<Ast\CtorField>, 1: Ast\TypeNode}
 */
function parseGadtConstructorSignature(ParserState $state): array
{
    $forall = isForallStart($state) ? parseForallHead($state) : null;

    if (! isAt($state, TokenKind::LBrace) && ! gadtRecordFollowsContext($state)) {
        $signature = parseType($state);
        if ($forall !== null) {
            $signature = forallSignature($forall, $signature);
        }

        return [[], $signature];
    }

    $constraints = [];
    if (! isAt($state, TokenKind::LBrace)) {
        $constraints[] = parseTypeInfix($state);
        while (isAt($state, TokenKind::Comma)) {
            advance($state);
            $constraints[] = parseTypeInfix($state);
        }
        expectOp($state, '=>');
    }

    $fields = parseRecordFieldDecls($state);
    expectOp($state, '->');
    $signature = parseType($state);
    foreach (\array_reverse($fields) as $field) {
        $signature = new Ast\TypeArrow($field->type, $signature);
    }

    if ($constraints !== []) {
        $signature = new Ast\TypeConstrained($constraints, $signature);
    }

    if ($forall !== null) {
        $signature = forallSignature($forall, $signature);
    }

    return [$fields, $signature];
}

/**
 * Wrap a signature body in the `forall` a constructor head opened.
 *
 * @param array{binders: list<string>, kinds: list<?Ast\KindNode>, token: Token} $head
 */
function forallSignature(array $head, Ast\TypeNode $body): Ast\TypeNode
{
    $node = new Ast\TypeForall($head['binders'], $head['kinds'], $body);

    return withTypeLocation($node, $head['token']);
}

function parseDataDecl(ParserState $state): Ast\DataDecl
{
    $dataToken = expect($state, TokenKind::KwData);
    [$name, $params, $nameToken] = parseDataHead($state);

    $constructors = [];
    if (isAt($state, TokenKind::KwWhere)) {
        $constructors = parseGadtConstructorBlock($state, $dataToken);
    } elseif (isAt($state, TokenKind::Op, 0, '=')) {
        advance($state);
        $constructors = [parseDocumentedConstructor($state)];
        while (isAt($state, TokenKind::Pipe)) {
            advance($state);
            $constructors[] = parseDocumentedConstructor($state);
        }
    }

    $derivingClasses = parseDerivingClause($state);

    return new Ast\DataDecl(
        $name,
        $params,
        $constructors,
        $nameToken->line,
        $nameToken->col,
        $nameToken->col + \max(0, \strlen($name) - 1),
        derivingClasses: $derivingClasses,
    );
}

/**
 * Data/newtype head: `Foo a b`, `(:~:) a b` or `(f :+: g) p`.
 *
 * @return array{0: string, 1: list<Ast\DataParam>, 2: Token}
 */
function parseDataHead(ParserState $state): array
{
    if (isAt($state, TokenKind::LParen)
        && isAt($state, TokenKind::Op, 1)
        && isAt($state, TokenKind::RParen, 2)
    ) {
        return parsePrefixOperatorDataHead($state);
    }

    if (isAt($state, TokenKind::LParen)) {
        return parseInfixDataHead($state);
    }

    $nameToken = expect($state, TokenKind::ConId);
    $params = parseDataParams($state);

    return [$nameToken->lexeme, $params, $nameToken];
}

/**
 * `data (:~:) a b` — a symbolic type constructor declared with its parameters.
 * The parameters are the ones written after the parentheses, so the declared
 * arity is the same as for `data Foo a b`.
 *
 * @return array{0: string, 1: list<Ast\DataParam>, 2: Token}
 */
function parsePrefixOperatorDataHead(ParserState $state): array
{
    expect($state, TokenKind::LParen);
    $opToken = expect($state, TokenKind::Op);
    if (! isConstructorOperator($opToken->lexeme)) {
        throw parseError($state, 'expected a type operator (name starting with `:`)');
    }
    expect($state, TokenKind::RParen);

    return [$opToken->lexeme, parseDataParams($state), $opToken];
}

/**
 * `data (f :+: g) p` — infix type constructor with left/right type params.
 *
 * @return array{0: string, 1: list<Ast\DataParam>, 2: Token}
 */
function parseInfixDataHead(ParserState $state): array
{
    expect($state, TokenKind::LParen);
    $left = parseDataParamAtom($state);
    $opToken = expect($state, TokenKind::Op);
    if (! isConstructorOperator($opToken->lexeme)) {
        throw parseError($state, 'expected a type operator (name starting with `:`)');
    }
    $right = parseDataParamAtom($state);
    expect($state, TokenKind::RParen);

    $params = [$left, $right, ...parseDataParams($state)];

    return [$opToken->lexeme, $params, $opToken];
}

/** @return list<Ast\DataParam> */
function parseDataParams(ParserState $state): array
{
    $params = [];
    while (isAt($state, TokenKind::VarId) || isAt($state, TokenKind::LParen)) {
        if (isAt($state, TokenKind::LParen) && ! looksLikeKindAnnotatedDataParam($state)) {
            break;
        }
        $params[] = isAt($state, TokenKind::LParen)
            ? parseKindAnnotatedDataParam($state)
            : new Ast\DataParam(advance($state)->lexeme, new Ast\KindInfer());
    }

    return $params;
}

function looksLikeKindAnnotatedDataParam(ParserState $state): bool
{
    return isAt($state, TokenKind::LParen)
        && isAt($state, TokenKind::VarId, 1)
        && isAt($state, TokenKind::Op, 2, '::');
}

function parseDataParamAtom(ParserState $state): Ast\DataParam
{
    if (isAt($state, TokenKind::LParen) && looksLikeKindAnnotatedDataParam($state)) {
        return parseKindAnnotatedDataParam($state);
    }

    return new Ast\DataParam(expect($state, TokenKind::VarId)->lexeme, new Ast\KindInfer());
}

/**
 * `newtype`: exactly one constructor with exactly one field.
 *
 * @return Ast\DataDecl
 */
function parseNewtypeDecl(ParserState $state): Ast\DataDecl
{
    $newtypeToken = expect($state, TokenKind::KwNewtype);
    [$name, $params, $nameToken] = parseDataHead($state);

    $constructors = [];
    if (isAt($state, TokenKind::KwWhere)) {
        $constructors = parseGadtConstructorBlock($state, $newtypeToken);
        if (\count($constructors) !== 1) {
            throw parseError($state, 'a newtype must have exactly one constructor');
        }

        $ctor = $constructors[0];
        $fieldCount = $ctor->isGadt()
            ? \count($ctor->fieldTypeAsts())
            : \count($ctor->fields);
        if ($fieldCount !== 1) {
            throw parseError($state, 'a newtype constructor must have exactly one field');
        }
    } else {
        expectOp($state, '=');

        $constructors = [parseDocumentedConstructor($state)];
        if (isAt($state, TokenKind::Pipe)) {
            throw parseError($state, 'a newtype must have exactly one constructor');
        }

        if (\count($constructors[0]->fields) !== 1) {
            throw parseError($state, 'a newtype constructor must have exactly one field');
        }
    }

    $derivingClasses = parseDerivingClause($state);

    return new Ast\DataDecl(
        $name,
        $params,
        $constructors,
        $nameToken->line,
        $nameToken->col,
        $nameToken->col + \max(0, \strlen($name) - 1),
        isNewtype: true,
        derivingClasses: $derivingClasses,
    );
}

/**
 * Zero or more deriving clauses after a data/newtype decl.
 * Supports strategies:
 *   deriving (Eq, Ord)
 *   deriving stock Show
 *   deriving newtype (Num, Show)
 *
 * @return list<Ast\DerivingClassRef>
 */
function parseDerivingClause(ParserState $state): array
{
    $all = [];
    while (isAt($state, TokenKind::KwDeriving) && ! looksLikeStandaloneDeriving($state)) {
        foreach (parseOneDerivingClause($state) as $ref) {
            $all[] = $ref;
        }
    }

    return $all;
}

/**
 * @return list<Ast\DerivingClassRef>
 */
function parseOneDerivingClause(ParserState $state): array
{
    expect($state, TokenKind::KwDeriving);
    $strategy = parseDerivingStrategy($state);

    if ($strategy === 'via') {
        expect($state, TokenKind::LParen);
        $viaType = parseType($state);
        expect($state, TokenKind::RParen);

        if (isAt($state, TokenKind::LParen)) {
            advance($state);
            $classes = [];
            if (! isAt($state, TokenKind::RParen)) {
                $ref = parseDerivingClassRef($state, $strategy);
                $ref->viaType = $viaType;
                $classes[] = $ref;
                while (isAt($state, TokenKind::Comma)) {
                    advance($state);
                    $ref = parseDerivingClassRef($state, $strategy);
                    $ref->viaType = $viaType;
                    $classes[] = $ref;
                }
            }
            expect($state, TokenKind::RParen);

            return $classes;
        }

        $ref = parseDerivingClassRef($state, $strategy);
        $ref->viaType = $viaType;

        return [$ref];
    }

    if (isAt($state, TokenKind::LParen)) {
        advance($state);
        $classes = [];
        if (! isAt($state, TokenKind::RParen)) {
            $classes[] = parseDerivingClassRef($state, $strategy);
            while (isAt($state, TokenKind::Comma)) {
                advance($state);
                $classes[] = parseDerivingClassRef($state, $strategy);
            }
        }
        expect($state, TokenKind::RParen);

        return $classes;
    }

    return [parseDerivingClassRef($state, $strategy)];
}

/** @return 'stock'|'newtype'|'anyclass'|'via'|null */
function parseDerivingStrategy(ParserState $state): ?string
{
    if (isAt($state, TokenKind::VarId, 0, 'stock')) {
        advance($state);

        return 'stock';
    }

    if (isAt($state, TokenKind::KwNewtype)) {
        advance($state);

        return 'newtype';
    }

    if (isAt($state, TokenKind::VarId, 0, 'anyclass')) {
        advance($state);

        return 'anyclass';
    }

    if (isAt($state, TokenKind::VarId, 0, 'via')) {
        advance($state);

        return 'via';
    }

    return null;
}

/**
 * Parse the head of a standalone deriving: optional constraints + class applied to type.
 *
 * @return array{0: list<Ast\TypeNode>, 1: Ast\TypeNode}
 */
function parseStandaloneDerivingHead(ParserState $state): array
{
    $constraints = [];
    $before = $state->pos;
    $firstType = parseTypeInfix($state);

    if ($firstType instanceof Ast\TypeConstrained) {
        $constraints = $firstType->constraints;
        $headType = $firstType->body;
    } elseif (isAt($state, TokenKind::Op) && peek($state)->lexeme === '=>') {
        $constraints = [$firstType];
        advance($state);
        $headType = parseTypeAtom($state);
        $headLine = peek($state)->line;
        while (isTypeArgStart($state, $headLine)) {
            $headType = new Ast\TypeApp($headType, [parseTypeHead($state)]);
        }
    } else {
        $state->pos = $before;
        $headType = parseTypeHead($state);
        $headLine = $state->tokens[$state->pos - 1]->line ?? 0;
        while (isTypeArgStart($state, $headLine)) {
            $headType = new Ast\TypeApp($headType, [parseTypeHead($state)]);
        }
    }

    return [$constraints, $headType];
}

/**
 * True when the next tokens look like a standalone deriving declaration
 * (`deriving instance …` or `deriving <strategy> instance …`).
 * Returns false for inline deriving clauses (`deriving (Eq, Show)` or
 * `deriving newtype Eq`) which should be parsed as part of data/newtype.
 */
function looksLikeStandaloneDeriving(ParserState $state): bool
{
    $saved = $state->pos;
    advance($state);

    if (isAt($state, TokenKind::VarId, 0, 'stock')
        || isAt($state, TokenKind::VarId, 0, 'anyclass')
    ) {
        advance($state);
    } elseif (isAt($state, TokenKind::KwNewtype)) {
        advance($state);
    } elseif (isAt($state, TokenKind::VarId, 0, 'via')) {
        advance($state);
        if (isAt($state, TokenKind::LParen)) {
            $depth = 0;
            while (! isAt($state, TokenKind::Eof)) {
                if (isAt($state, TokenKind::LParen)) {
                    ++$depth;
                } elseif (isAt($state, TokenKind::RParen)) {
                    --$depth;
                }
                advance($state);
                if ($depth === 0) {
                    break;
                }
            }
        }
    }

    $result = isAt($state, TokenKind::KwInstance);
    $state->pos = $saved;

    return $result;
}

/**
 * Standalone deriving declaration:
 *   deriving instance C a => C (T a)
 *   deriving stock instance C a => C (T a)
 *   deriving newtype instance C T
 *   deriving via (V) instance C T
 *   deriving via (V) instance C a => C (T a)
 */
function parseStandaloneDeriving(ParserState $state): Ast\StandaloneDerivingDecl
{
    $startToken = expect($state, TokenKind::KwDeriving);
    $strategy = parseDerivingStrategy($state);

    if ($strategy === 'via') {
        expect($state, TokenKind::LParen);
        $viaType = parseType($state);
        expect($state, TokenKind::RParen);

        expect($state, TokenKind::KwInstance);

        [$constraints, $headType] = parseStandaloneDerivingHead($state);
        [$className, $head] = instanceClassAndHeadFromType($headType, $state);

        return new Ast\StandaloneDerivingDecl(
            strategy: $strategy,
            viaType: $viaType,
            className: $className,
            head: $head,
            constraints: $constraints,
            line: $startToken->line,
            col: $startToken->col,
            endCol: $startToken->col + \max(0, \strlen($startToken->lexeme) - 1),
        );
    }

    expect($state, TokenKind::KwInstance);

    [$constraints, $headType] = parseStandaloneDerivingHead($state);
    [$className, $head] = instanceClassAndHeadFromType($headType, $state);

    return new Ast\StandaloneDerivingDecl(
        strategy: $strategy,
        viaType: null,
        className: $className,
        head: $head,
        constraints: $constraints,
        line: $startToken->line,
        col: $startToken->col,
        endCol: $startToken->col + \max(0, \strlen($startToken->lexeme) - 1),
    );
}

function parseDerivingClassRef(ParserState $state, ?string $strategy = null): Ast\DerivingClassRef
{
    $token = expect($state, TokenKind::ConId);

    return new Ast\DerivingClassRef(
        $token->lexeme,
        $token->line,
        $token->col,
        $token->col + \max(0, \strlen($token->lexeme) - 1),
        strategy: $strategy,
    );
}

function parseKindAnnotatedDataParam(ParserState $state): Ast\DataParam
{
    expect($state, TokenKind::LParen);
    $name = expect($state, TokenKind::VarId)->lexeme;
    expectOp($state, '::');
    $kind = parseKind($state);
    expect($state, TokenKind::RParen);

    return new Ast\DataParam($name, $kind);
}

/**
 * @return Ast\TypeSynonymDecl
 */
function parseTypeSynonymDecl(ParserState $state): Ast\AstNode
{
    $typeToken = expect($state, TokenKind::KwType);
    if (isAt($state, TokenKind::VarId) && peek($state)->lexeme === 'family') {
        return parseTypeFamilyDecl($state, $typeToken);
    }
    if (isAt($state, TokenKind::VarId) && peek($state)->lexeme === 'role') {
        return parseRoleDecl($state, $typeToken);
    }
    if (isAt($state, TokenKind::LParen)
        && isAt($state, TokenKind::Op, 1)
        && isAt($state, TokenKind::RParen, 2)
    ) {
        expect($state, TokenKind::LParen);
        $opToken = advance($state);
        expect($state, TokenKind::RParen);
        $params = [];
        while (isAt($state, TokenKind::VarId)) {
            $params[] = advance($state)->lexeme;
        }
        expectOp($state, '=');

        return new Ast\TypeSynonymDecl(
            $opToken->lexeme,
            parseType($state),
            $opToken->line,
            $opToken->col,
            params: $params,
        );
    }

    if (isAt($state, TokenKind::VarId) && isAt($state, TokenKind::Op, 1)) {
        $left = advance($state)->lexeme;
        $opToken = advance($state);
        $right = expect($state, TokenKind::VarId)->lexeme;
        expectOp($state, '=');

        return new Ast\TypeSynonymDecl(
            $opToken->lexeme,
            parseType($state),
            $opToken->line,
            $opToken->col,
            params: [$left, $right],
        );
    }

    $nameToken = expect($state, TokenKind::ConId);
    $name = $nameToken->lexeme;
    $params = [];
    while (isAt($state, TokenKind::VarId)) {
        $params[] = advance($state)->lexeme;
    }
    expectOp($state, '=');
    $type = parseType($state);

    return new Ast\TypeSynonymDecl($name, $type, $nameToken->line, $nameToken->col, params: $params);
}

/**
 * `type role T phantom representational` -- one role per parameter of a type
 * declared in this module.
 */
function parseRoleDecl(ParserState $state, Token $typeToken): Ast\RoleDecl
{
    advance($state);
    $nameToken = expect($state, TokenKind::ConId);
    $roles = [];
    $last = $nameToken;
    while (isAt($state, TokenKind::VarId)
        && \in_array(peek($state)->lexeme, ['phantom', 'representational', 'nominal'], true)
    ) {
        $last = advance($state);
        $roles[] = $last->lexeme;
    }

    return new Ast\RoleDecl(
        $nameToken->lexeme,
        $roles,
        $typeToken->line,
        $typeToken->col,
        $last->col + \strlen($last->lexeme),
    );
}

/**
 * `type family Not (x :: Bool) where` and its equations, or the head alone for
 * a family with no equations (`type family Compare (a :: k) (b :: k) :: Ordering`).
 *
 * An equation is an indented line of its own, `Not 'True = 'False`, and its
 * left-hand side is parsed as a type: the family's application is an ordinary
 * type application, or an infix operator type when the family was declared with
 * an operator name (`'True && x`).
 */
function parseTypeFamilyDecl(ParserState $state, Token $typeToken): Ast\TypeSynonymDecl
{
    advance($state);
    [$name, $params, $nameToken] = parseTypeFamilyHead($state);

    $resultKind = null;
    if (isAt($state, TokenKind::Op, 0, '::')) {
        advance($state);
        $resultKind = parseKind($state);
    }

    $equations = [];
    if (isAt($state, TokenKind::KwWhere)) {
        advance($state);
        $previousLine = $typeToken->line;
        while (! isAt($state, TokenKind::Eof)) {
            $token = peek($state);
            if ($token->col <= $typeToken->col || $token->line <= $previousLine) {
                break;
            }
            $previousLine = $token->line;
            $lhs = parseFamilyEquationLhs($state);
            expectOp($state, '=');
            $rhs = parseType($state);
            $equations[] = ['lhs' => $lhs, 'rhs' => $rhs];
        }
    }

    return new Ast\TypeSynonymDecl(
        $name,
        null,
        $nameToken->line,
        $nameToken->col,
        isFamily: true,
        familyParams: $params,
        familyResultKind: $resultKind,
        familyEquations: $equations,
    );
}

/**
 * A type family's head: `Not (x :: Bool)`, `(x :: Bool) && (y :: Bool)`, or
 * `(&&) (x :: Bool) (y :: Bool)`.
 *
 * @return array{0: string, 1: list<Ast\DataParam>, 2: Token}
 */
function parseTypeFamilyHead(ParserState $state): array
{
    if (isAt($state, TokenKind::LParen)
        && isAt($state, TokenKind::Op, 1)
        && isAt($state, TokenKind::RParen, 2)
    ) {
        expect($state, TokenKind::LParen);
        $opToken = advance($state);
        expect($state, TokenKind::RParen);

        return [$opToken->lexeme, parseDataParams($state), $opToken];
    }

    if (isAt($state, TokenKind::LParen) && looksLikeKindAnnotatedDataParam($state)) {
        $left = parseDataParamAtom($state);
        $opToken = expect($state, TokenKind::Op);
        if (! isConstructorOperator($opToken->lexeme) && ! isTypeLevelOperator($opToken->lexeme)) {
            throw parseError($state, 'expected a type operator (a name starting with `:`, `~`, `&&` or `||`)');
        }
        $right = parseDataParamAtom($state);

        return [$opToken->lexeme, [$left, $right, ...parseDataParams($state)], $opToken];
    }

    $nameToken = expect($state, TokenKind::ConId);

    return [$nameToken->lexeme, parseDataParams($state), $nameToken];
}

/**
 * An equation's left-hand side: a type application or an infix operator type.
 *
 * It is parsed here rather than by {@see parseType} because the rest of the
 * language has to tell a type from a function's left-hand side
 * (`f x = ...`), and an equation such as `If 'True t _ = t` looks exactly like
 * one. Inside a family's equations there is no ambiguity to resolve: the `=`
 * ends the type, so every name before it is an argument.
 */
function parseFamilyEquationLhs(ParserState $state): Ast\TypeNode
{
    $left = parseFamilyEquationAtom($state);

    while (
        isAt($state, TokenKind::Op)
        && (isConstructorOperator(peek($state)->lexeme)
            || isTypeLevelOperator(peek($state)->lexeme))
    ) {
        $opToken = advance($state);
        $right = parseFamilyEquationAtom($state);
        $left = new Ast\TypeApp(new Ast\TypeCon($opToken->lexeme), [$left, $right]);
    }

    return $left;
}

function parseFamilyEquationAtom(ParserState $state): Ast\TypeNode
{
    $head = parseTypeHead($state);

    $args = [];
    while (isFamilyEquationArgStart($state)) {
        $args[] = parseTypeHead($state);
    }

    if ($args === []) {
        return $head;
    }

    if ($head instanceof Ast\TypeApp) {
        return new Ast\TypeApp($head->con, [...$head->args, ...$args]);
    }

    return new Ast\TypeApp($head, $args);
}

function isFamilyEquationArgStart(ParserState $state): bool
{
    if (isAt($state, TokenKind::Op) && ! isTypeLevelOperator(peek($state)->lexeme)) {
        return false;
    }

    if (isAt($state, TokenKind::VarId) && isAt($state, TokenKind::Op, 1, '::')) {
        return false;
    }

    return match (peek($state)->kind) {
        TokenKind::ConId,
        TokenKind::PromotedCon,
        TokenKind::StringLit,
        TokenKind::Integer,
        TokenKind::LParen,
        TokenKind::LBracket,
        TokenKind::VarId => true,
        default => false,
    };
}

/**
 * Spells the type-level operator a family may be named by that does not start
 * with `:`. They are reserved where a type operator is expected, so a family
 * named `&&` cannot be confused with an expression operator.
 */
function isTypeLevelOperator(string $lexeme): bool
{
    return $lexeme === '~' || $lexeme === '&&' || $lexeme === '||' || $lexeme === '==';
}

/**
 * @return Ast\ClassDecl
 */
function parseClassDecl(ParserState $state): Ast\ClassDecl
{
    $classToken = expect($state, TokenKind::KwClass);

    $superclasses = [];
    $headType = parseTypeAtom($state);

    if ($headType instanceof Ast\TypeConstrained) {
        $superclasses = $headType->constraints;
        $headType = $headType->body;
    } elseif (isAt($state, TokenKind::Op) && peek($state)->lexeme === '=>') {
        advance($state);
        $superclasses = [$headType];
        $headType = parseTypeAtom($state);
    }

    if (isAt($state, TokenKind::Comma)) {
        throw parseError(
            $state,
            'multiple superclasses must share one parenthesized context: '
            . 'write `class (C1 t, C2 t) => Head t`',
        );
    }

    [$name, $params] = classHeadFromType($headType, $state);

    $headCon = $headType instanceof Ast\TypeApp ? $headType->con : $headType;
    $headLoc = $headCon->line ? $headCon : $classToken;

    expect($state, TokenKind::KwWhere);

    $bodyCol = $classToken->col;
    $methods = [];
    $associatedTypes = [];
    $defaults = [];
    $minimalGroups = [];
    while (! isAt($state, TokenKind::Eof) && ! isFollowingClassBodyBoundary($state)) {
        if (peek($state)->col <= $bodyCol) {
            break;
        }

        if (isAt($state, TokenKind::Pragma)) {
            $minimalGroups = parseMinimalPragma($state, advance($state));

            continue;
        }

        $standaloneTrailing = consumeIndentedTrailingDoc($state, $bodyCol);
        if ($standaloneTrailing !== null) {
            attachTrailingDocToPreviousMethod($methods, $standaloneTrailing);

            continue;
        }

        if (isAt($state, TokenKind::KwType)) {
            $associatedTypes[] = parseAssociatedTypeDecl($state);

            continue;
        }

        $leadingDoc = consumeLeadingDoc($state);

        $defaultFn = tryParseClassMethodDefault($state, $bodyCol);
        if ($defaultFn !== null) {
            $defaults[] = ['fn' => $defaultFn, 'doc' => $leadingDoc];
            $standaloneTrailing = consumeIndentedTrailingDoc($state, $bodyCol);
            if ($standaloneTrailing !== null) {
                attachTrailingDocToPreviousMethod($methods, $standaloneTrailing);
            }

            continue;
        }

        $group = parseClassMethodGroup($state);
        if ($leadingDoc !== null && $group !== []) {
            $group[0] = new Ast\ClassMethodSig(
                $group[0]->name,
                $group[0]->type,
                mergeDoc($leadingDoc, $group[0]->doc),
            );
        }
        $methods = [...$methods, ...$group];
        $standaloneTrailing = consumeIndentedTrailingDoc($state, $bodyCol);
        if ($standaloneTrailing !== null) {
            attachTrailingDocToPreviousMethod($methods, $standaloneTrailing);
        }
    }

    foreach ($defaults as $default) {
        attachClassMethodDefault($state, $methods, $default['fn'], $default['doc']);
    }

    foreach ($minimalGroups as $group) {
        foreach ($group as $methodName) {
            foreach ($methods as $method) {
                if ($method->name === $methodName) {
                    continue 2;
                }
            }

            throw parseError(
                $state,
                "MINIMAL pragma names `{$methodName}`, which is not a method of class `{$name}`",
            );
        }
    }

    $headLine = $headLoc->line;
    $headCol = $headLoc->col;
    $headEndCol = $headCol + \max(0, \strlen($name) - 1);

    return new Ast\ClassDecl(
        $name,
        $params,
        $methods,
        $superclasses,
        $headLine,
        $headCol,
        $headEndCol,
        associatedTypes: $associatedTypes,
        minimalGroups: $minimalGroups,
    );
}

/**
 * `{-# MINIMAL a, b | c #-}`: the alternatives (`|`) whose methods an instance
 * has to implement, each alternative a conjunction (`,`).
 *
 * An operator method is named the way the class body spells it, in parentheses:
 * `(==) | (/=)`.
 *
 * @return list<list<string>>
 */
function parseMinimalPragma(ParserState $state, Token $token): array
{
    $body = \trim($token->lexeme);
    if (! \preg_match('/^MINIMAL\b(.*)$/is', $body, $m)) {
        throw parseError($state, "unsupported pragma `{$body}`", $token, 'parse/pragma');
    }

    $parts = minimalPragmaParts($state, \trim($m[1]), $token);

    $pos = 0;
    $groups = minimalExpression($parts, $pos, $token, $state);
    if ($pos !== \count($parts)) {
        throw parseError($state, "unexpected `{$parts[$pos]}` in MINIMAL pragma", $token, 'parse/pragma');
    }

    return $groups;
}

/**
 * The method names and the `(`, `)`, `,` and `|` a MINIMAL body is made of.
 *
 * A name is an identifier (`foldMap`) or a run of operator characters (`==`),
 * and the run is read whole: `(<|>)` names `<|>`, while a separator stands on
 * its own. The parentheses are grouping, so `(==)` is a group holding the one
 * name `==` — which the class body spells the same way — and `(recip | (/))` is
 * a group holding a name and a group.
 *
 * @return list<string>
 */
function minimalPragmaParts(ParserState $state, string $text, Token $token): array
{
    $parts = [];
    $i = 0;
    $len = \strlen($text);

    while ($i < $len) {
        $c = $text[$i];

        if (\ctype_space($c)) {
            ++$i;

            continue;
        }

        if ($c === '(' || $c === ')' || $c === ',') {
            $parts[] = $c;
            ++$i;

            continue;
        }

        if (isSymbol($c)) {
            $start = $i;
            while ($i < $len && isSymbol($text[$i])) {
                ++$i;
            }
            $parts[] = \substr($text, $start, $i - $start);

            continue;
        }

        if (isLetter($c) || $c === '_') {
            $start = $i++;
            while ($i < $len && isIdCont($text[$i])) {
                ++$i;
            }
            $parts[] = \substr($text, $start, $i - $start);

            continue;
        }

        throw parseError(
            $state,
            "MINIMAL pragma needs method names, found `{$c}`",
            $token,
            'parse/pragma',
        );
    }

    return $parts;
}

/**
 * Whether a MINIMAL leaf names an operator method — `==`, `/=`, `<|>`, `:`. The
 * separator `|` is not one, and an identifier is checked before this.
 */
function isMinimalOperatorName(string $name): bool
{
    if ($name === '' || $name === '|') {
        return false;
    }

    for ($i = 0, $len = \strlen($name); $i < $len; ++$i) {
        if (! isSymbol($name[$i])) {
            return false;
        }
    }

    return true;
}

/**
 * Parse a MINIMAL expression into the alternatives an instance has to satisfy,
 * normalised to a disjunction of conjunctions: `a, b | c` is `[a, b]` or `[c]`,
 * and `(a | b), c` distributes to `[a, c]` or `[b, c]`.
 *
 * @param list<string> $parts
 * @return list<list<string>>
 */
function minimalExpression(array $parts, int &$pos, Token $token, ParserState $state): array
{
    $groups = minimalConjunction($parts, $pos, $token, $state);
    while (($parts[$pos] ?? null) === '|') {
        $pos++;
        $groups = [...$groups, ...minimalConjunction($parts, $pos, $token, $state)];
    }

    return $groups;
}

/** @param list<string> $parts @return list<list<string>> */
function minimalConjunction(array $parts, int &$pos, Token $token, ParserState $state): array
{
    $groups = minimalTerm($parts, $pos, $token, $state);
    while (($parts[$pos] ?? null) === ',') {
        $pos++;
        $right = minimalTerm($parts, $pos, $token, $state);
        $product = [];
        foreach ($groups as $left) {
            foreach ($right as $group) {
                $product[] = [...$left, ...$group];
            }
        }
        $groups = $product;
    }

    return $groups;
}

/** @param list<string> $parts @return list<list<string>> */
function minimalTerm(array $parts, int &$pos, Token $token, ParserState $state): array
{
    $part = $parts[$pos] ?? null;
    if ($part === '(') {
        $pos++;
        $groups = minimalExpression($parts, $pos, $token, $state);
        if (($parts[$pos] ?? null) !== ')') {
            throw parseError($state, 'unbalanced `(` in MINIMAL pragma', $token, 'parse/pragma');
        }
        $pos++;

        return $groups;
    }

    if ($part === null || $part === ')' || $part === ',' || $part === '|') {
        throw parseError($state, 'MINIMAL pragma needs at least one method name', $token, 'parse/pragma');
    }

    if (\preg_match('/^[A-Za-z_][A-Za-z0-9_\']*$/', $part) !== 1 && ! isMinimalOperatorName($part)) {
        throw parseError($state, "MINIMAL pragma needs method names, found `{$part}`", $token, 'parse/pragma');
    }

    $pos++;

    return [[$part]];
}

/** @return array{0: string, 1: list<Ast\ClassParam>} */
function classHeadFromType(Ast\TypeNode $type, ParserState $state): array
{
    return match ($type::class) {
        Ast\TypeCon::class => [$type->name, []],
        Ast\TypeApp::class => classHeadFromTypeApp($type, $state),
        default => throw unexpected($state, 'class head'),
    };
}

/** @return array{0: string, 1: list<Ast\ClassParam>} */
function classHeadFromTypeApp(Ast\TypeApp $type, ParserState $state): array
{
    if (! $type->con instanceof Ast\TypeCon) {
        throw unexpected($state, 'class head');
    }

    $params = \array_map(
        static fn (Ast\TypeNode $arg): Ast\ClassParam => match ($arg::class) {
            Ast\TypeVar::class => new Ast\ClassParam($arg->name, new Ast\KindInfer()),
            Ast\TypeKindAnnot::class => new Ast\ClassParam($arg->name, $arg->kind),
            default => throw unexpected($state, 'class parameter'),
        },
        $type->args,
    );

    return [$type->con->name, $params];
}

/**
 * @return list<Ast\ClassMethodSig>
 */
function parseClassMethodGroup(ParserState $state): array
{
    $names = [parseClassMethodName($state)];
    while (isAt($state, TokenKind::Comma)) {
        advance($state);
        $names[] = parseClassMethodName($state);
    }

    expectOp($state, '::');
    $type = parseType($state);
    $trailingDoc = consumeTrailingDoc($state);

    $methods = [];
    foreach ($names as $name) {
        $methods[] = new Ast\ClassMethodSig($name, $type, $trailingDoc);
    }

    return $methods;
}

function parseClassMethodName(ParserState $state): string
{
    if (isAt($state, TokenKind::LParen)) {
        advance($state);
        $name = expect($state, TokenKind::Op)->lexeme;
        expect($state, TokenKind::RParen);

        return $name;
    }

    return expect($state, TokenKind::VarId)->lexeme;
}

/**
 * Parse a default method implementation (`m pats = …`, `(op) pats = …`) when the
 * current line sits inside the class body, or `null` when it is something else.
 */
function tryParseClassMethodDefault(ParserState $state, int $bodyCol): ?Ast\FunctionDecl
{
    if (peek($state)->col <= $bodyCol) {
        return null;
    }

    if (isAt($state, TokenKind::VarId)
        && ! isAt($state, TokenKind::Op, 1, '::')
        && (looksLikeFunctionDecl($state)
            || looksLikeInfixFunctionDecl($state)
            || looksLikeBacktickFunctionDecl($state))) {
        return parseInstanceMethod($state);
    }

    if (isAt($state, TokenKind::LParen)
        && isAt($state, TokenKind::Op, 1)
        && looksLikeParenOperatorMethodDecl($state)) {
        return parseInstanceMethod($state);
    }

    return null;
}

/**
 * Attach a parsed default implementation to its method signature.
 *
 * @param list<Ast\ClassMethodSig> $methods
 */
function attachClassMethodDefault(
    ParserState $state,
    array &$methods,
    Ast\FunctionDecl $fn,
    ?string $leadingDoc,
): void {
    foreach ($methods as $i => $sig) {
        if ($sig->name !== $fn->name) {
            continue;
        }
        if ($sig->body !== null) {
            throw parseError($state, "duplicate default implementation for method `{$fn->name}`");
        }

        $methods[$i] = new Ast\ClassMethodSig(
            $sig->name,
            $sig->type,
            mergeDoc($leadingDoc, $sig->doc),
            $fn->params,
            $fn->body,
        );

        return;
    }

    throw parseError($state, "default implementation for unknown class method `{$fn->name}`");
}

/**
 * @return Ast\InstanceDecl
 */
function parseInstanceDecl(ParserState $state): Ast\AstNode
{
    expect($state, TokenKind::KwInstance);

    $constraints = [];
    $before = $state->pos;
    $firstType = parseTypeInfix($state);

    if ($firstType instanceof Ast\TypeConstrained) {
        $constraints = $firstType->constraints;
        [$className, $head, $line, $col, $endCol] = instanceClassAndHeadFromType($firstType->body, $state);
        [$methods, $associatedEquations] = parseInstanceBodyOrEmpty($state);

        return new Ast\InstanceDecl(
            $className,
            $head,
            $methods,
            $constraints,
            $line,
            $col,
            $endCol,
            associatedEquations: $associatedEquations,
        );
    }

    if (isAt($state, TokenKind::Op) && peek($state)->lexeme === '=>') {
        $constraints = [$firstType];
        advance($state);
    } elseif (isAt($state, TokenKind::Comma)) {
        throw parseError(
            $state,
            'multiple instance constraints must share one parenthesized context: '
            . 'write `instance (C1 a, C2 a) => Head where`',
        );
    } else {
        $state->pos = $before;
    }

    $classToken = expect($state, TokenKind::ConId);
    $className = $classToken->lexeme;
    $head = parseInstanceHead($state);
    [$methods, $associatedEquations] = parseInstanceBodyOrEmpty($state);

    return new Ast\InstanceDecl(
        $className,
        $head,
        $methods,
        $constraints,
        $classToken->line,
        $classToken->col,
        $classToken->col + \max(0, \strlen($className) - 1),
        associatedEquations: $associatedEquations,
    );
}

/**
 * Split `Monoid (Min a)` / `Foo Int Bool` into class name + instance head.
 *
 * @return array{0: string, 1: Ast\AstNode, 2: int, 3: int, 4: int}
 */
function instanceClassAndHeadFromType(Ast\TypeNode $type, ParserState $state): array
{
    $args = [];
    $cur = $type;
    while ($cur instanceof Ast\TypeApp) {
        $args = [...\array_reverse($cur->args), ...$args];
        $cur = $cur->con;
    }

    if (! $cur instanceof Ast\TypeCon) {
        throw unexpected($state, 'class name');
    }

    $className = $cur->name;
    $line = $cur->line;
    $col = $cur->col;
    $endCol = $cur->endCol !== 0 ? $cur->endCol : $col + \max(0, \strlen($className) - 1);

    if ($args === []) {
        return [$className, new Ast\TypeUnit($line, $col, $endCol), $line, $col, $endCol];
    }

    return [$className, Ast\instanceHeadFromArgs($args), $line, $col, $endCol];
}

function isFollowingClassBodyBoundary(ParserState $state): bool
{
    if (isAt($state, TokenKind::KwType)) {
        return peek($state)->col <= classBodyColumn($state);
    }

    if (isDocBeforeTopLevelDecl($state, classBodyColumn($state))) {
        return true;
    }

    if (isFollowingDeclBoundary($state)) {
        return true;
    }

    if (peek($state)->col <= classBodyColumn($state)
        && isAt($state, TokenKind::VarId)
        && ! isAt($state, TokenKind::Op, 1, '::')
        && looksLikeFunctionDecl($state)) {
        return true;
    }

    return false;
}

function isFollowingDeclBoundary(ParserState $state): bool
{
    return match (peek($state)->kind) {
        TokenKind::KwModule,
        TokenKind::KwImport,
        TokenKind::KwData,
        TokenKind::KwNewtype,
        TokenKind::KwType,
        TokenKind::KwClass,
        TokenKind::KwInstance,
        TokenKind::KwForeign,
        TokenKind::KwInfix,
        TokenKind::KwInfixl,
        TokenKind::KwInfixr => true,
        default => false,
    };
}

function classBodyColumn(ParserState $state): int
{
    for ($i = $state->pos - 1; $i >= 0; --$i) {
        $token = $state->tokens[$i];
        if ($token->kind === TokenKind::KwClass) {
            return $token->col;
        }
    }

    return 0;
}

/** True when a doc comment immediately precedes a top-level declaration at or left of `bodyCol`. */
function isDocBeforeTopLevelDecl(ParserState $state, int $bodyCol): bool
{
    if (! isDocTokenKind(peek($state)->kind)) {
        return false;
    }

    $saved = $state->pos;
    while (isDocTokenKind(peek($state)->kind)) {
        advance($state);
    }

    $boundary = isFollowingDeclBoundary($state) && peek($state)->col <= $bodyCol;
    $state->pos = $saved;

    return $boundary;
}

function instanceBodyColumn(ParserState $state): int
{
    for ($i = $state->pos - 1; $i >= 0; --$i) {
        if ($state->tokens[$i]->kind === TokenKind::KwInstance) {
            return $state->tokens[$i]->col;
        }
    }

    return 1;
}

function isFollowingInstanceBoundary(ParserState $state): bool
{
    if (isAt($state, TokenKind::KwType)) {
        return peek($state)->col <= instanceBodyColumn($state);
    }

    if (isDocBeforeTopLevelDecl($state, instanceBodyColumn($state))) {
        return true;
    }

    if (isFollowingDeclBoundary($state)) {
        return true;
    }

    if (isAt($state, TokenKind::VarId) && isAt($state, TokenKind::Op, 1, '::')) {
        return true;
    }

    return looksLikeParenOperatorTypedDecl($state);
}

/**
 * @return Ast\AssociatedTypeDecl
 */
function parseAssociatedTypeDecl(ParserState $state): Ast\AssociatedTypeDecl
{
    expect($state, TokenKind::KwType);
    $nameToken = expect($state, TokenKind::ConId);
    $name = $nameToken->lexeme;
    $params = [];
    while (isAt($state, TokenKind::VarId) && peek($state)->line === $nameToken->line) {
        $params[] = advance($state)->lexeme;
    }

    $resultKind = null;
    if (isAt($state, TokenKind::Op, 0, '::') && peek($state)->line === $nameToken->line) {
        advance($state);
        $resultKind = parseKind($state);
    }

    return new Ast\AssociatedTypeDecl(
        $name,
        $params,
        $resultKind,
        $nameToken->line,
        $nameToken->col,
        $nameToken->col + \max(0, \strlen($name) - 1),
    );
}

/**
 * @return Ast\AssociatedTypeEquation
 */
function parseAssociatedTypeEquation(ParserState $state): Ast\AssociatedTypeEquation
{
    expect($state, TokenKind::KwType);
    $nameToken = expect($state, TokenKind::ConId);
    $name = $nameToken->lexeme;
    $lhsArgs = [];
    $headLine = $nameToken->line;
    while (isTypeArgStart($state, $headLine)) {
        $lhsArgs[] = parseTypeHead($state);
    }
    expectOp($state, '=');
    $rhs = parseType($state);

    return new Ast\AssociatedTypeEquation(
        $name,
        $lhsArgs,
        $rhs,
        $nameToken->line,
        $nameToken->col,
        $nameToken->col + \max(0, \strlen($name) - 1),
    );
}

/**
 * The `where` of an instance is optional: `instance MonadPlus Maybe` declares no
 * methods of its own and takes every one of them from the class defaults.
 *
 * @return array{0: list<Ast\FunctionDecl>, 1: list<Ast\AssociatedTypeEquation>}
 */
function parseInstanceBodyOrEmpty(ParserState $state): array
{
    if (! isAt($state, TokenKind::KwWhere)) {
        return [[], []];
    }

    advance($state);

    return parseInstanceBody($state, instanceBodyColumn($state));
}

/**
 * @return array{0: list<Ast\FunctionDecl>, 1: list<Ast\AssociatedTypeEquation>}
 */
function parseInstanceBody(ParserState $state, int $bodyCol = 0): array
{
    if ($bodyCol === 0) {
        $bodyCol = instanceBodyColumn($state);
    }

    $methods = [];
    $associatedEquations = [];
    while (! isAt($state, TokenKind::Eof) && ! isFollowingInstanceBoundary($state)) {
        if (peek($state)->col <= $bodyCol) {
            break;
        }

        $standaloneTrailing = consumeIndentedTrailingDoc($state, $bodyCol);
        if ($standaloneTrailing !== null) {
            attachTrailingDocToPreviousMethod($methods, $standaloneTrailing);

            continue;
        }

        if (isAt($state, TokenKind::KwType)) {
            $associatedEquations[] = parseAssociatedTypeEquation($state);

            continue;
        }

        $leadingDoc = consumeLeadingDoc($state);
        $method = parseInstanceMethod($state);
        if ($leadingDoc !== null) {
            $method->doc = mergeDoc($leadingDoc, $method->doc);
        }
        $methods[] = $method;
        $standaloneTrailing = consumeIndentedTrailingDoc($state, $bodyCol);
        if ($standaloneTrailing !== null) {
            attachTrailingDocToPreviousMethod($methods, $standaloneTrailing);
        }
    }

    return [mergeFunctionClauses($methods, $state->source, $state->filename), $associatedEquations];
}

/**
 * @return Ast\ConstructorDecl
 */
function parseDocumentedConstructor(ParserState $state): Ast\ConstructorDecl
{
    $leadingDoc = consumeLeadingDoc($state);
    $ctor = parseConstructorAlt($state);
    if ($leadingDoc !== null) {
        $ctor->doc = mergeDoc($leadingDoc, $ctor->doc);
    }

    return $ctor;
}

function parseInstanceHead(ParserState $state): Ast\AstNode
{
    if (isAt($state, TokenKind::KwWhere)) {
        return new Ast\TypeUnit();
    }

    $args = [parseTypeHead($state)];
    $headLine = $state->tokens[$state->pos - 1]->line;
    while (isTypeArgStart($state, $headLine)) {
        $args[] = parseTypeHead($state);
    }

    return Ast\instanceHeadFromArgs($args);
}

function isFollowingTopLevelDecl(ParserState $state): bool
{
    if ($state->inDoBlock
        && $state->doBlockCol !== null
        && peek($state)->col >= $state->doBlockCol
    ) {
        return false;
    }

    if (startsTopLevelDecl($state)) {
        return true;
    }

    if (looksLikeInfixFunctionDecl($state)) {
        return true;
    }

    if (looksLikeParenOperatorMethodDecl($state)) {
        return true;
    }

    if (looksLikeParenOperatorTypedDecl($state)) {
        return true;
    }

    if (isAt($state, TokenKind::VarId) && looksLikeSameLineFunctionDecl($state)) {
        return true;
    }

    return match (peek($state)->kind) {
        TokenKind::KwModule,
        TokenKind::KwImport,
        TokenKind::KwData,
        TokenKind::KwNewtype,
        TokenKind::KwType,
        TokenKind::KwClass,
        TokenKind::KwInstance,
        TokenKind::KwForeign,
        TokenKind::KwInfix,
        TokenKind::KwInfixl,
        TokenKind::KwInfixr => true,
        default => false,
    };
}

/**
 * @return Ast\FunctionDecl
 */
function parseInstanceMethod(ParserState $state): Ast\FunctionDecl
{
    if (looksLikeInfixFunctionDecl($state)) {
        return parseInferredInfixFunction($state);
    }

    if (looksLikeBacktickFunctionDecl($state)) {
        return parseInferredBacktickFunction($state);
    }

    if (isAt($state, TokenKind::LParen) && isAt($state, TokenKind::Op, 1)) {
        $open = expect($state, TokenKind::LParen);
        $name = expect($state, TokenKind::Op)->lexeme;
        expect($state, TokenKind::RParen);
        $params = [];
        while (startsPattern($state) && ! isAt($state, TokenKind::Op, 0, '=')) {
            $params[] = parsePatternAtom($state);
        }
        expectOp($state, '=');
        $body = parseExprWithWhere($state);

        return new Ast\FunctionDecl(
            $name,
            null,
            $params,
            $body,
            line: $open->line,
            col: $open->col,
            endCol: $open->col,
        );
    }

    $nameToken = expect($state, TokenKind::VarId);
    $params = [];
    while (startsPattern($state) && ! isAt($state, TokenKind::Op, 0, '=') && ! isAt($state, TokenKind::Pipe)) {
        $params[] = parsePatternAtom($state);
    }
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl(
        $nameToken->lexeme,
        null,
        $params,
        $body,
        line: $nameToken->line,
        col: $nameToken->col,
        endCol: $nameToken->col + \max(0, \strlen($nameToken->lexeme) - 1),
    );
}

/**
 * @return Ast\ConstructorDecl
 */
function parseConstructorAlt(ParserState $state): Ast\ConstructorDecl
{
    if (isForallStart($state)) {
        throw parseError(
            $state,
            'a data constructor cannot quantify its own type variables: existential constructors are '
                . 'not supported; write the variable in the data declaration instead',
        );
    }

    if (isAt($state, TokenKind::LParen) && isAt($state, TokenKind::Op, 1)) {
        return parsePrefixConstructorOpAlt($state);
    }

    if (isAt($state, TokenKind::ConId)) {
        if (isAt($state, TokenKind::Op, 1) && isConstructorOperator(peek($state, 1)->lexeme)) {
            $nameToken = advance($state);

            return parseInfixConstructorAlt($state, new Ast\TypeCon(
                $nameToken->lexeme,
                $nameToken->line,
                $nameToken->col,
                $nameToken->col + \max(0, \strlen($nameToken->lexeme) - 1),
            ));
        }

        return parsePrefixConstructorIdAlt($state);
    }

    return parseInfixConstructorAlt($state);
}

/**
 * @return Ast\ConstructorDecl
 */
function parsePrefixConstructorIdAlt(ParserState $state): Ast\ConstructorDecl
{
    $nameToken = expect($state, TokenKind::ConId);
    $name = $nameToken->lexeme;

    $endCol = $nameToken->col + \max(0, \strlen($name) - 1);
    if (isAt($state, TokenKind::LBrace)) {
        return new Ast\ConstructorDecl(
            $name,
            parseRecordFieldDecls($state),
            $nameToken->line,
            $nameToken->col,
            $endCol,
        );
    }

    return new Ast\ConstructorDecl(
        $name,
        parseConstructorFields($state, $nameToken->line),
        $nameToken->line,
        $nameToken->col,
        $endCol,
    );
}

/**
 * Prefix form `(:|) a [a]`.
 *
 * @return Ast\ConstructorDecl
 */
function parsePrefixConstructorOpAlt(ParserState $state): Ast\ConstructorDecl
{
    $open = expect($state, TokenKind::LParen);
    $opToken = expect($state, TokenKind::Op);
    $name = $opToken->lexeme;
    if (! isConstructorOperator($name)) {
        throw unexpected($state, 'constructor operator starting with `:`');
    }
    expect($state, TokenKind::RParen);

    if (isAt($state, TokenKind::LBrace)) {
        throw unexpected($state, 'positional constructor fields (record syntax is not allowed for operator constructors)');
    }

    $fixity = fixityFor($state, $name);

    return new Ast\ConstructorDecl(
        $name,
        parseConstructorFields($state, $open->line),
        $open->line,
        $open->col,
        $open->col + \max(0, \strlen($name) - 1),
        fixityAssoc: $fixity['assoc'],
        fixityPrec: $fixity['precedence'],
    );
}

/**
 * Infix form `a :| [a]`.
 *
 * @return Ast\ConstructorDecl
 */
function parseInfixConstructorAlt(ParserState $state, ?Ast\TypeNode $left = null): Ast\ConstructorDecl
{
    $left ??= parseTypeAtom($state);
    if (! isAt($state, TokenKind::Op)) {
        throw unexpected($state, 'constructor');
    }

    $opToken = peek($state);
    $name = $opToken->lexeme;
    if (! isConstructorOperator($name)) {
        throw unexpected($state, 'constructor operator starting with `:`');
    }
    advance($state);

    $right = parseTypeAtom($state);
    $fixity = fixityFor($state, $name);

    return new Ast\ConstructorDecl(
        $name,
        [
            new Ast\CtorField('', $left),
            new Ast\CtorField('', $right),
        ],
        $opToken->line,
        $opToken->col,
        $opToken->col + \max(0, \strlen($name) - 1),
        fixityAssoc: $fixity['assoc'],
        fixityPrec: $fixity['precedence'],
        declaredInfix: true,
    );
}

/**
 * @return list<Ast\CtorField>
 */
/**
 * Positional constructor fields (`C a Int`). Named record fields only come
 * from `{ name :: Type }` via {@see parseRecordFieldDecls}. Bare VarIds are
 * type arguments, not record selectors — otherwise
 * `data Pair a b = MkPair a b` incorrectly derives `conIsRecord True` with
 * selectors `"a"`/`"b"`.
 *
 * @return list<Ast\CtorField>
 */
function parseConstructorFields(ParserState $state, int $nameLine): array
{
    $fields = [];
    while (constructorTypeArgContinues($state, $nameLine)) {
        $fields[] = new Ast\CtorField('', parseTypeHead($state));
    }

    return $fields;
}

function constructorTypeArgContinues(ParserState $state, int $nameLine): bool
{
    if (peek($state)->line !== $nameLine) {
        return false;
    }

    if (isAt($state, TokenKind::Pipe)) {
        return false;
    }

    return isTypeArgStart($state);
}

/**
 * Record fields with documentation (`-- |` before a field documents it, `-- ^`
 * after a field's comma documents that field), mirroring how constructor
 * alternatives and class/instance methods attach docs.
 *
 * @return list<Ast\CtorField>
 */
function parseRecordFieldDecls(ParserState $state): array
{
    expect($state, TokenKind::LBrace);
    $fields = [];

    while (! isAt($state, TokenKind::RBrace)) {
        $leadingDoc = consumeLeadingDoc($state);

        if (isAt($state, TokenKind::DocTrailing)) {
            $trailingDoc = consumeTrailingDoc($state);
            if ($trailingDoc !== null && $fields !== []) {
                $previous = \count($fields) - 1;
                $fields[$previous]->doc = mergeDoc($fields[$previous]->doc, $trailingDoc);
            }
            $leadingDoc = mergeDoc($leadingDoc, consumeLeadingDoc($state));
        }

        if (isAt($state, TokenKind::RBrace)) {
            break;
        }

        $label = expect($state, TokenKind::VarId);
        expectOp($state, '::');
        $type = parseTypeNoConstraint($state);
        $field = new Ast\CtorField($label->lexeme, $type, $label->line, $label->col, $label->col + \strlen($label->lexeme));
        if ($leadingDoc !== null) {
            $field->doc = mergeDoc($leadingDoc, $field->doc);
        }
        $fields[] = $field;

        if (isAt($state, TokenKind::Comma)) {
            advance($state);
        }
    }

    expect($state, TokenKind::RBrace);

    return $fields;
}

function looksLikeParenOperatorTypedDecl(ParserState $state): bool
{
    return isAt($state, TokenKind::LParen)
        && isAt($state, TokenKind::Op, 1)
        && isAt($state, TokenKind::RParen, 2)
        && isAt($state, TokenKind::Op, 3, '::');
}

function looksLikeParenOperatorMethodDecl(ParserState $state): bool
{
    if (! isAt($state, TokenKind::LParen) || ! isAt($state, TokenKind::Op, 1)) {
        return false;
    }

    $saved = $state->pos;

    try {
        advance($state);
        advance($state);
        expect($state, TokenKind::RParen);

        while (startsPattern($state) && ! isAt($state, TokenKind::Op, 0, '=')) {
            parsePatternAtom($state);
        }

        return isAt($state, TokenKind::Op, 0, '=');
    } catch (ParseError) {
        return false;
    } finally {
        $state->pos = $saved;
    }
}

function startsTopLevelDecl(ParserState $state): bool
{
    if (! isAt($state, TokenKind::VarId)) {
        return false;
    }

    if (isAt($state, TokenKind::Op, 1, '::')) {
        return true;
    }

    return looksLikeFunctionDecl($state);
}
