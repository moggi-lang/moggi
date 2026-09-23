<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;

use function Moggi\Syntax\isConstructorOperator;
use function Moggi\Syntax\isTypeOperator;

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

    // A `FunctionDecl` reaches here unpositioned (the clause parsers carry no span), so
    // the declaration is located by the token its name starts at.
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
    while (startsPattern($state) && !isAt($state, TokenKind::Op, 0, '=') && !isAt($state, TokenKind::Pipe)) {
        $params[] = parsePatternArg($state);
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
    while (startsPattern($state) && !isAt($state, TokenKind::Op, 0, '=') && !isAt($state, TokenKind::Pipe)) {
        $params[] = parsePatternArg($state);
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
        !looksLikeInfixFunctionDecl($state)
        && !looksLikeBacktickFunctionDecl($state)
        && !looksLikeParenOperatorMethodDecl($state)
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
        while (startsPattern($state)
            && !isAt($state, TokenKind::Op, 0, '=')
            && !isAt($state, TokenKind::Pipe)) {
            parsePatternArg($state);
        }

        if (isAt($state, TokenKind::Op, 0, '=')) {
            return peek($state)->line === $startLine;
        }

        if (!isAt($state, TokenKind::Pipe)) {
            return false;
        }

        // `f x | g = e` on one line is the same shape as a comprehension's
        // `[head ys | ys <- xss]`, so a same-line guard only counts outside
        // brackets, where no declaration can begin.
        return peek($state)->line > $startLine || !insideBrackets($state);
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
        // A case alternative's guards end where the next alternative begins:
        // `case x of p | g -> e; q -> f` must not read `q` as an argument of `e`.
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
            parsePatternArg($state);
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
        && !isAt($state, TokenKind::Op, 1, '=')
        && !isAt($state, TokenKind::Op, 1, '::')
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
    while (startsPattern($state) && !isAt($state, TokenKind::Op, 0, '=') && !isAt($state, TokenKind::Pipe)) {
        $params[] = parsePatternArg($state);
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
    while (startsPattern($state) && !isAt($state, TokenKind::Op, 0, '=') && !isAt($state, TokenKind::Pipe)) {
        $params[] = parsePatternArg($state);
    }
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl($name, $type, $params, $body);
}

/**
 * @return Ast\DataDecl
 */
function parseDataDecl(ParserState $state): Ast\DataDecl
{
    expect($state, TokenKind::KwData);
    [$name, $params, $nameToken] = parseDataHead($state);

    $constructors = [];
    if (isAt($state, TokenKind::Op, 0, '=')) {
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
        $nameToken->col + max(0, strlen($name) - 1),
        derivingClasses: $derivingClasses,
    );
}

/**
 * Data/newtype head: `Foo a b` or `(f :+: g) p`.
 *
 * @return array{0: string, 1: list<Ast\DataParam>, 2: Token}
 */
function parseDataHead(ParserState $state): array
{
    if (isAt($state, TokenKind::LParen)) {
        return parseInfixDataHead($state);
    }

    $nameToken = expect($state, TokenKind::ConId);
    $params = parseDataParams($state);

    return [$nameToken->lexeme, $params, $nameToken];
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
    if (!isTypeOperator($opToken->lexeme)) {
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
        // Stop before `=` or a following top-level construct; kind-annotated
        // params are `(c :: k)` and still start with LParen.
        if (isAt($state, TokenKind::LParen) && !looksLikeKindAnnotatedDataParam($state)) {
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
    expect($state, TokenKind::KwNewtype);
    [$name, $params, $nameToken] = parseDataHead($state);

    expectOp($state, '=');

    $ctor = parseDocumentedConstructor($state);
    if (isAt($state, TokenKind::Pipe)) {
        throw parseError($state, 'a newtype must have exactly one constructor');
    }

    if (count($ctor->fields) !== 1) {
        throw parseError($state, 'a newtype constructor must have exactly one field');
    }

    $derivingClasses = parseDerivingClause($state);

    return new Ast\DataDecl(
        $name,
        $params,
        [$ctor],
        $nameToken->line,
        $nameToken->col,
        $nameToken->col + max(0, strlen($name) - 1),
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
    while (isAt($state, TokenKind::KwDeriving) && !looksLikeStandaloneDeriving($state)) {
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

    // `deriving via (ViaType) ClassName` or `deriving via (ViaType) (C1, C2)`
    if ($strategy === 'via') {
        expect($state, TokenKind::LParen);
        $viaType = parseType($state);
        expect($state, TokenKind::RParen);

        if (isAt($state, TokenKind::LParen)) {
            advance($state);
            $classes = [];
            if (!isAt($state, TokenKind::RParen)) {
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
        if (!isAt($state, TokenKind::RParen)) {
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
    $firstType = parseTypeAtom($state);

    if ($firstType instanceof Ast\TypeConstrained) {
        $constraints = $firstType->constraints;
        $headType = $firstType->body;
    } elseif (isAt($state, TokenKind::Op) && peek($state)->lexeme === '=>') {
        $constraints = [$firstType];
        advance($state);
        $headType = parseTypeAtom($state);
        // Continue to parse type application args (e.g. `C a b`).
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
    advance($state); // consuming `deriving`

    if (isAt($state, TokenKind::VarId, 0, 'stock')
        || isAt($state, TokenKind::VarId, 0, 'anyclass')
    ) {
        advance($state);
    } elseif (isAt($state, TokenKind::KwNewtype)) {
        advance($state);
    } elseif (isAt($state, TokenKind::VarId, 0, 'via')) {
        // `deriving via (Type) instance ...` — skip the via type in parens.
        advance($state);
        if (isAt($state, TokenKind::LParen)) {
            $depth = 0;
            while (!isAt($state, TokenKind::Eof)) {
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
        // `deriving via (ViaType) instance C T` or `deriving via (ViaType) instance C a => C (T a)`
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
            endCol: $startToken->col + max(0, strlen($startToken->lexeme) - 1),
        );
    }

    // `deriving instance C a => C (T a)` or
    // `deriving stock instance C a => C (T a)` or
    // `deriving newtype instance C T`
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
        endCol: $startToken->col + max(0, strlen($startToken->lexeme) - 1),
    );
}

function parseDerivingClassRef(ParserState $state, ?string $strategy = null): Ast\DerivingClassRef
{
    $token = expect($state, TokenKind::ConId);

    return new Ast\DerivingClassRef(
        $token->lexeme,
        $token->line,
        $token->col,
        $token->col + max(0, strlen($token->lexeme) - 1),
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
function parseTypeSynonymDecl(ParserState $state): Ast\TypeSynonymDecl
{
    expect($state, TokenKind::KwType);
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
 * @return Ast\ClassDecl
 */
function parseClassDecl(ParserState $state): Ast\ClassDecl
{
    $classToken = expect($state, TokenKind::KwClass);

    $superclasses = [];
    $headType = parseTypeAtom($state);

    // `class (C1 t, C2 t) => Head t`: parseTypeHead folds a parenthesized
    // constraint tuple into TypeConstrained; a bare comma is not a context
    // separator, so `class C1 t, C2 t => Head t` is rejected below.
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
    while (!isAt($state, TokenKind::Eof) && !isFollowingClassBodyBoundary($state)) {
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

    // Defaults may be written before the signature they implement, so attach
    // them only once the whole class body is parsed.
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

    // `$headLoc` is a token or the head's type node; both carry a position, so the class decl is
    // located rather than span-less (a diagnostic on a class needs somewhere to point).
    $headLine = $headLoc->line;
    $headCol = $headLoc->col;
    $headEndCol = $headCol + max(0, strlen($name) - 1);

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
 * @return list<list<string>>
 */
function parseMinimalPragma(ParserState $state, Token $token): array
{
    $body = trim($token->lexeme);
    if (! preg_match('/^MINIMAL\b(.*)$/is', $body, $m)) {
        throw parseError($state, "unsupported pragma `{$body}`", $token, 'parse/pragma');
    }

    $parts = [];
    foreach (preg_split('/\s*([|,()])\s*/', trim($m[1]), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
        if ($part !== '') {
            $parts[] = $part;
        }
    }

    $pos = 0;
    $groups = minimalExpression($parts, $pos, $token, $state);
    if ($pos !== \count($parts)) {
        throw parseError($state, "unexpected `{$parts[$pos]}` in MINIMAL pragma", $token, 'parse/pragma');
    }

    return $groups;
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

    if (preg_match('/^[A-Za-z_][A-Za-z0-9_\']*$/', $part) !== 1) {
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
    if (!$type->con instanceof Ast\TypeCon) {
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
        && !isAt($state, TokenKind::Op, 1, '::')
        && looksLikeFunctionDecl($state)) {
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
    $firstType = parseTypeAtom($state);

    // `instance (Ord a, Bounded a) => Class Head where`
    // parseTypeHead already folds `(C1, C2) => Body` into TypeConstrained.
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

    // One bare `Eq a =>` per instance; several constraints must share one parenthesized
    // context — a bare comma is not a context separator.
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
        $classToken->col + max(0, strlen($className) - 1),
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
        // Prepend in reverse of each app's args without O(n²) array_unshift loops.
        $args = [...array_reverse($cur->args), ...$args];
        $cur = $cur->con;
    }

    if (!$cur instanceof Ast\TypeCon) {
        throw unexpected($state, 'class name');
    }

    $className = $cur->name;
    $line = $cur->line;
    $col = $cur->col;
    $endCol = $cur->endCol !== 0 ? $cur->endCol : $col + max(0, strlen($className) - 1);

    if ($args === []) {
        return [$className, new Ast\TypeUnit($line, $col, $endCol), $line, $col, $endCol];
    }

    if (count($args) === 1) {
        return [$className, $args[0], $line, $col, $endCol];
    }

    $head = $args[0];
    for ($i = 1, $n = count($args); $i < $n; ++$i) {
        $head = new Ast\TypeApp($head, [$args[$i]]);
    }

    return [$className, $head, $line, $col, $endCol];
}

function isFollowingClassBodyBoundary(ParserState $state): bool
{
    // Associated `type F a` belongs in the class body only when indented past
    // the `class` keyword. A same-column `type` starts a top-level synonym.
    if (isAt($state, TokenKind::KwType)) {
        return peek($state)->col <= classBodyColumn($state);
    }

    if (isDocBeforeTopLevelDecl($state, classBodyColumn($state))) {
        return true;
    }

    if (isFollowingDeclBoundary($state)) {
        return true;
    }

    // Method signatures use `name :: Type`; a bare `name pat = …` starts a
    // top-level function only when it sits at (or left of) the `class` keyword.
    // Indented past it, the same shape is a default method implementation.
    if (peek($state)->col <= classBodyColumn($state)
        && isAt($state, TokenKind::VarId)
        && !isAt($state, TokenKind::Op, 1, '::')
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
    if (!isDocTokenKind(peek($state)->kind)) {
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
    // Associated `type F … = …` belongs in the instance body only when indented
    // past the `instance` keyword. A same-column `type` is a top-level synonym
    // (removing a following `data` decl previously hid this bug: `data` was a
    // hard boundary, so `type Object` after instances never ran this path).
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
    // Binders must stay on the declaration line so the next method (`get :: …`)
    // is not consumed as a type parameter.
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
        $nameToken->col + max(0, strlen($name) - 1),
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
        $nameToken->col + max(0, strlen($name) - 1),
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
    if (!isAt($state, TokenKind::KwWhere)) {
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
    while (!isAt($state, TokenKind::Eof) && !isFollowingInstanceBoundary($state)) {
        // Same-column (or left) tokens end the instance body — including a
        // top-level `type` synonym. Associated type equations must be indented.
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

    // Consecutive equations of one name are clauses of one binding, as at top
    // level; only a repeated method with something else in between is a
    // duplicate.
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

    $head = parseTypeHead($state);
    $headLine = $state->tokens[$state->pos - 1]->line;
    while (isTypeArgStart($state, $headLine)) {
        $head = new Ast\TypeApp($head, [parseTypeHead($state)]);
    }

    return $head;
}

function isFollowingTopLevelDecl(ParserState $state): bool
{
    // Inside a do, only less-indented tokens can close the block as a
    // subsequent top-level declaration. Same-column `putStrLn` / deeper
    // `b = 2` let binders must stay in the do.
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
    if (isAt($state, TokenKind::LParen) && isAt($state, TokenKind::Op, 1)) {
        $open = expect($state, TokenKind::LParen);
        $name = expect($state, TokenKind::Op)->lexeme;
        expect($state, TokenKind::RParen);
        $params = [];
        while (startsPattern($state) && !isAt($state, TokenKind::Op, 0, '=')) {
            $params[] = parsePatternArg($state);
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
    while (startsPattern($state) && !isAt($state, TokenKind::Op, 0, '=') && !isAt($state, TokenKind::Pipe)) {
        $params[] = parsePatternArg($state);
    }
    $body = parseFunctionBody($state);

    return new Ast\FunctionDecl(
        $nameToken->lexeme,
        null,
        $params,
        $body,
        line: $nameToken->line,
        col: $nameToken->col,
        endCol: $nameToken->col + max(0, strlen($nameToken->lexeme) - 1),
    );
}

/**
 * @return Ast\ConstructorDecl
 */
function parseConstructorAlt(ParserState $state): Ast\ConstructorDecl
{
    if (isAt($state, TokenKind::LParen) && isAt($state, TokenKind::Op, 1)) {
        return parsePrefixConstructorOpAlt($state);
    }

    if (isAt($state, TokenKind::ConId)) {
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

    $endCol = $nameToken->col + max(0, strlen($name) - 1);
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
    if (!isConstructorOperator($name)) {
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
        $open->col + max(0, strlen($name) - 1),
        fixityAssoc: $fixity['assoc'],
        fixityPrec: $fixity['prec'],
    );
}

/**
 * Infix form `a :| [a]`.
 *
 * @return Ast\ConstructorDecl
 */
function parseInfixConstructorAlt(ParserState $state): Ast\ConstructorDecl
{
    $left = parseTypeAtom($state);
    if (!isAt($state, TokenKind::Op)) {
        throw unexpected($state, 'constructor');
    }

    $opToken = peek($state);
    $name = $opToken->lexeme;
    if (!isConstructorOperator($name)) {
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
        $opToken->col + max(0, strlen($name) - 1),
        fixityAssoc: $fixity['assoc'],
        fixityPrec: $fixity['prec'],
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
 * @return list<Ast\CtorField>
 */
function parseRecordFieldDecls(ParserState $state): array
{
    expect($state, TokenKind::LBrace);
    $fields = [];

    while (!isAt($state, TokenKind::RBrace)) {
        $label = expect($state, TokenKind::VarId);
        expectOp($state, '::');
        $type = parseTypeNoConstraint($state);
        $fields[] = new Ast\CtorField($label->lexeme, $type, $label->line, $label->col, $label->col + \strlen($label->lexeme));

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
    if (!isAt($state, TokenKind::LParen) || !isAt($state, TokenKind::Op, 1)) {
        return false;
    }

    $saved = $state->pos;

    try {
        advance($state);
        advance($state);
        expect($state, TokenKind::RParen);

        while (startsPattern($state) && !isAt($state, TokenKind::Op, 0, '=')) {
            parsePatternArg($state);
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
    if (!isAt($state, TokenKind::VarId)) {
        return false;
    }

    if (isAt($state, TokenKind::Op, 1, '::')) {
        return true;
    }

    return looksLikeFunctionDecl($state);
}
