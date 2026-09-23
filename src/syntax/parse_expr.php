<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;

/**
 * An integer literal, keeping the digits when they do not fit the host `int`:
 * `123456789012345678901234567890` must not become the clamped `int` before the
 * type checker can decide what type it denotes.
 */
function integerLit(string $lexeme, Token $token): Ast\IntegerLit
{
    $lit = spanned(new Ast\IntegerLit(integerLitValue($lexeme)), $token);
    if ((string) (int) $lexeme !== $lexeme) {
        $lit->digits = $lexeme;
    }

    return $lit;
}

function guardClauseBoundary(ParserState $state, int $continueLine): bool
{
    return isAt($state, TokenKind::Pipe) && peek($state)->line > $continueLine;
}

function startsUnaryOperand(ParserState $state, int $pos): bool
{
    if ($pos >= count($state->tokens)) {
        return false;
    }

    return match ($state->tokens[$pos]->kind) {
        TokenKind::Integer,
        TokenKind::Float,
        TokenKind::StringLit,
        TokenKind::CharLit,
        TokenKind::VarId,
        TokenKind::ConId,
        TokenKind::LParen,
        TokenKind::LBracket,
        TokenKind::Backslash,
        TokenKind::KwLet,
        TokenKind::KwCase,
        TokenKind::KwDo,
        TokenKind::KwIf => true,
        TokenKind::Op => $state->tokens[$pos]->lexeme === '-',
        default => false,
    };
}

/**
 * @return list<Ast\RecordField>
 */
function parseRecordConFields(ParserState $state): array
{
    expect($state, TokenKind::LBrace);
    $fields = [];

    while (!isAt($state, TokenKind::RBrace)) {
        $label = expect($state, TokenKind::VarId);
        expectOp($state, '=');
        $expr = parseExprWithWhere($state);
        $fields[] = new Ast\RecordField($label->lexeme, $expr, $label->line, $label->col, $label->col + \strlen($label->lexeme));

        if (isAt($state, TokenKind::Comma)) {
            advance($state);
        }
    }

    expect($state, TokenKind::RBrace);

    return $fields;
}

function parseExprWithWhere(ParserState $state): Ast\AstNode
{
    $expr = parseInfix($state, 0);

    if (!isAt($state, TokenKind::KwWhere)) {
        return $expr;
    }

    requireDeeperThanDecl($state, 'where');
    advance($state);

    return new Ast\Where($expr, parseBindings($state));
}

/**
 * Offside rule for continuation keywords (`in`, `where`): they close a
 * `let`/`where` block, so they may be dedented below the block's binders — but
 * never as deep as the declaration that encloses them. A token at the
 * declaration's own column starts a sibling declaration; accepting it here
 * would silently splice a following declaration into this item's expression.
 */
function requireDeeperThanDecl(ParserState $state, string $keyword): void
{
    if ($state->declCol <= 0) {
        return;
    }
    $token = peek($state);
    if ($token->col > $state->declCol) {
        return;
    }

    throw parseError(
        $state,
        sprintf(
            "`%s` must be indented deeper than the enclosing declaration: "
            . 'dedenting it to the declaration\'s own column (%d) ends the declaration '
            . 'instead of continuing it',
            $keyword,
            $state->declCol,
        ),
        $token,
    );
}

/**
 * @return list<Ast\Binding>
 */
function parseBindings(ParserState $state): array
{
    skipDocComments($state);
    // The block's column comes from the token, not the parsed item: an item with no
    // column would leave the block unbounded and swallow following declarations.
    $blockCol = peek($state)->col;
    $items = [parseLetItem($state)];

    while (true) {
        if (isAt($state, TokenKind::Semicolon)) {
            advance($state);
        }

        // Docs before a local binding must not end the group; skip them only when a binding
        // at this block's column follows, so dedented docs stay with their declaration.
        skipDocCommentsBefore($state, static function (ParserState $s) use ($blockCol): bool {
            return looksLikeLetItem($s) && peek($s)->col >= $blockCol;
        });

        if (!looksLikeLetItem($state)) {
            break;
        }

        // Offside: a less-indented binder ends the let/where group (so a
        // following top-level `main ::` is not swallowed as a local signature).
        if (peek($state)->col < $blockCol) {
            break;
        }

        $items[] = parseLetItem($state);
    }

    return desugarLetItems($items, [
        'source' => $state->source,
        'filename' => $state->filename,
    ]);
}

/**
 * @return array<string, mixed>
 */
function parseLetItem(ParserState $state): array
{
    if (looksLikeLocalSignature($state)) {
        $nameTok = expect($state, TokenKind::VarId);
        expectOp($state, '::');
        $type = parseType($state);

        return [
            'kind' => 'sig',
            'name' => $nameTok->lexeme,
            'type' => $type,
            'line' => $nameTok->line,
            'col' => $nameTok->col,
            'endCol' => $type->endCol !== 0 ? $type->endCol : $nameTok->col + strlen($nameTok->lexeme),
        ];
    }

    if (looksLikeFunEquation($state)) {
        $nameTok = expect($state, TokenKind::VarId);
        $params = [];
        while (startsPattern($state)
            && !isAt($state, TokenKind::Op, 0, '=')
            && !isAt($state, TokenKind::Pipe)
        ) {
            $params[] = parsePatternArg($state);
        }
        $body = parseFunctionBody($state);

        return [
            'kind' => 'fun',
            'name' => $nameTok->lexeme,
            'params' => $params,
            'body' => $body,
            'line' => $nameTok->line,
            'col' => $nameTok->col,
            'endCol' => $body->endCol !== 0 ? $body->endCol : $nameTok->col + strlen($nameTok->lexeme),
        ];
    }

    $pattern = parsePattern($state);
    expectOp($state, '=');
    $value = parseExprWithWhere($state);

    return [
        'kind' => 'pat',
        'pattern' => $pattern,
        'value' => $value,
        'line' => $pattern->line,
        'col' => $pattern->col,
        'endCol' => $value->endCol !== 0 ? $value->endCol : $pattern->endCol,
    ];
}

function looksLikeLetItem(ParserState $state): bool
{
    return looksLikeLocalSignature($state)
        || looksLikeFunEquation($state)
        || looksLikePatternBinding($state);
}

function looksLikeLocalSignature(ParserState $state): bool
{
    return isAt($state, TokenKind::VarId) && isAt($state, TokenKind::Op, 1, '::');
}

function looksLikeFunEquation(ParserState $state): bool
{
    if (!isAt($state, TokenKind::VarId)) {
        return false;
    }

    $saved = $state->pos;
    try {
        advance($state); // name
        while (startsPattern($state)
            && !isAt($state, TokenKind::Op, 0, '=')
            && !isAt($state, TokenKind::Pipe)
        ) {
            parsePatternArg($state);
        }

        // `name =` is a nullary fun equation so signatures attach cleanly;
        // ConId/tuple pattern binds use looksLikePatternBinding.
        return isAt($state, TokenKind::Op, 0, '=') || isAt($state, TokenKind::Pipe);
    } catch (ParseError) {
        return false;
    } finally {
        $state->pos = $saved;
    }
}

/** Pattern binding `pat = expr` that is not a varid fun equation / signature. */
function looksLikePatternBinding(ParserState $state): bool
{
    if (looksLikeLocalSignature($state) || looksLikeFunEquation($state)) {
        return false;
    }

    if (!startsPattern($state)) {
        return false;
    }

    $saved = $state->pos;
    try {
        parsePattern($state);
        return isAt($state, TokenKind::Op, 0, '=');
    } catch (ParseError) {
        return false;
    } finally {
        $state->pos = $saved;
    }
}

/**
 * @return Ast\AstNode
 */
function parseLambda(ParserState $state): Ast\AstNode
{
    $start = expect($state, TokenKind::Backslash);
    $params = [parseLambdaParam($state)];

    while (startsPattern($state)) {
        $params[] = parseLambdaParam($state);
    }

    expectOp($state, '->');
    $body = parseExprWithWhere($state);

    return spannedRange(new Ast\Lambda($params, $body), $start, $body);
}

function parseLambdaParam(ParserState $state): Ast\LambdaParam
{
    $pattern = parsePattern($state);
    $type = null;

    if (isAt($state, TokenKind::Op, 0, '::')) {
        advance($state);
        $type = parseTypeAtom($state);
    }

    // The parameter stands for a real piece of source ("lambda parameters must be variables" is
    // reported against it), so it carries the pattern's own position.
    return spannedRange(new Ast\LambdaParam($pattern, $type), $pattern, $type ?? $pattern);
}

/**
 * @return Ast\AstNode
 */
function parseLet(ParserState $state): Ast\AstNode
{
    expect($state, TokenKind::KwLet);
    $bindings = parseBindings($state);
    requireDeeperThanDecl($state, 'in');
    expect($state, TokenKind::KwIn);
    $body = parseExprWithWhere($state);

    return new Ast\Let($bindings, $body);
}

/**
 * `if c then a else b`.
 *
 * `if` is syntax, not a function: it desugars to a `case` on the condition with
 * `True`/`False` alternatives. That keeps one
 * conditional form in the language — and one shape for the type checker and the
 * backends to optimize.
 *
 * @return Ast\AstNode
 */
function parseIf(ParserState $state): Ast\AstNode
{
    $start = expect($state, TokenKind::KwIf);
    $cond = parseExprWithWhere($state);
    expectIfKeyword($state, TokenKind::KwThen, 'then');
    $then = parseExprWithWhere($state);
    $else = expectIfKeyword($state, TokenKind::KwElse, 'else');
    $otherwise = parseExprWithWhere($state);

    $alts = [
        new Ast\Alt(spanned(new Ast\PatCon('True', []), $start), $then),
        new Ast\Alt(spanned(new Ast\PatCon('False', []), $else), $otherwise),
    ];

    return spannedRange(new Ast\CaseExpr($cond, $alts), $start, $cond);
}

/**
 * Consume `then`/`else`.
 *
 * The offside check only runs once the keyword is actually present: a *missing*
 * keyword has to report itself, not whatever was at that column.
 */
function expectIfKeyword(ParserState $state, TokenKind $kind, string $name): Token
{
    if (!isAt($state, $kind)) {
        throw unexpected($state, "keyword `{$name}`");
    }

    requireDeeperThanDecl($state, $name);

    return expect($state, $kind);
}

/**
 * @return Ast\AstNode
 */
function parseCase(ParserState $state): Ast\AstNode
{
    $start = expect($state, TokenKind::KwCase);
    $state->parsingCaseScrutinee = true;
    $scrutinee = parseInfix($state, 0);
    $state->parsingCaseScrutinee = false;
    expectCaseOf($state, $scrutinee);
    // Layout: the first alternative's column is the offside column; without it an outer
    // arm after a nested arm is absorbed as a further alternative.
    skipDocComments($state);
    $altCol = peek($state)->col;
    $alts = [parseAlt($state)];

    $afterSemi = false;
    while (true) {
        if (isAt($state, TokenKind::Semicolon)) {
            advance($state);
            $afterSemi = true;
        }

        // Docs before an alternative are comments; skip them only when the
        // alternative belongs to this case (same column, or after a `;`).
        skipDocCommentsBefore($state, static function (ParserState $s) use ($altCol, $afterSemi): bool {
            return startsPattern($s)
                && !startsTopLevelDecl($s)
                && ($afterSemi || peek($s)->col === $altCol);
        });

        if (!startsPattern($state) || startsTopLevelDecl($state)) {
            break;
        }

        // Layout continuation: same column as the first alt. Semicolon-separated
        // alts (`A -> 1; B -> 2`) may sit deeper than `$altCol` — accept those.
        if (!$afterSemi && peek($state)->col !== $altCol) {
            break;
        }
        $afterSemi = false;

        $alts[] = parseAlt($state);
    }

    return spannedRange(new Ast\CaseExpr($scrutinee, $alts), $start, $scrutinee);
}

/**
 * @return Ast\AstNode
 */
function parseDo(ParserState $state): Ast\AstNode
{
    $start = expect($state, TokenKind::KwDo);
    $prevInDo = $state->inDoBlock;
    $prevDoCol = $state->doBlockCol;
    $state->inDoBlock = true;
    // Offside column for this do: first statement's column. Binders/stmts at
    // this column or deeper stay inside the do; less-indented `name =` is a
    // following top-level decl.
    skipDocComments($state);
    $state->doBlockCol = peek($state)->col;
    $stmts = [parseDoStmt($state)];

    while (true) {
        if (isAt($state, TokenKind::Semicolon)) {
            advance($state);
        }

        skipDocCommentsBefore($state, static function (ParserState $s): bool {
            return startsDoStmt($s) && !isFollowingTopLevelDecl($s);
        });

        if (!startsDoStmt($state) || isFollowingTopLevelDecl($state)) {
            break;
        }

        $stmts[] = parseDoStmt($state);
    }

    $state->inDoBlock = $prevInDo;
    $state->doBlockCol = $prevDoCol;
    $expr = new Ast\DoExpr($stmts);

    return spannedRange($expr, $start, $expr);
}

function startsDoStmt(ParserState $state): bool
{
    if (isFollowingTopLevelDecl($state)) {
        return false;
    }

    if (isAt($state, TokenKind::KwLet)) {
        return true;
    }

    if (startsPattern($state) && isAt($state, TokenKind::Op, 1, '<-')) {
        return true;
    }

    return isExprStart($state);
}

/**
 * @return Ast\AstNode
 */
function parseDoStmt(ParserState $state): Ast\AstNode
{
    if (isAt($state, TokenKind::KwLet)) {
        advance($state);
        $bindings = parseDoBindings($state);

        return new Ast\DoLet($bindings);
    }

    if (startsPattern($state) && isAt($state, TokenKind::Op, 1, '<-')) {
        $pattern = parsePattern($state);
        advance($state);
        $state->doExprStartLine = peek($state)->line;
        $expr = parseExprWithWhere($state);
        $state->doExprStartLine = null;

        return new Ast\DoBind($pattern, $expr);
    }

    $state->doExprStartLine = peek($state)->line;
    $expr = parseExprWithWhere($state);
    $state->doExprStartLine = null;

    return new Ast\DoExprStmt($expr);
}

/**
 * @return array<int, Ast\DoBind>
 */
function parseDoBindings(ParserState $state): array
{
    skipDocComments($state);
    $items = [parseDoLetItem($state)];
    $blockCol = $items[0]['col'] ?? peek($state)->col;

    // Same continuation rule as parseBindings, but not startsDoStmt: every binder also
    // looks like an expression start and would abort the group.
    while (true) {
        if (isAt($state, TokenKind::Semicolon)) {
            advance($state);
        }

        skipDocCommentsBefore($state, static function (ParserState $s) use ($blockCol): bool {
            return looksLikeLetItem($s) && peek($s)->col >= $blockCol;
        });

        if (!looksLikeLetItem($state) || isFollowingTopLevelDecl($state)) {
            break;
        }

        if (peek($state)->col < $blockCol) {
            break;
        }

        $items[] = parseDoLetItem($state);
    }

    $bindings = desugarLetItems($items, [
        'source' => $state->source,
        'filename' => $state->filename,
    ]);

    return \array_map(
        static fn (Ast\Binding $b): Ast\DoBind => new Ast\DoBind(
            $b->pattern,
            $b->value,
            $b->line,
            $b->col,
            $b->endCol,
        ),
        $bindings,
    );
}

/**
 * @return array<string, mixed>
 */
function parseDoLetItem(ParserState $state): array
{
    $state->doExprStartLine = peek($state)->line;
    try {
        return parseLetItem($state);
    } finally {
        $state->doExprStartLine = null;
    }
}

function reachedDoExprBoundary(ParserState $state): bool
{
    if (!$state->inDoBlock || $state->doExprStartLine === null) {
        return false;
    }

    // Case arms manage layout via stopBeforeCaseAlt / startsCaseAlt, so a same-line
    // argument (`Left err -> putStrLn err`) is not read as a new do-statement.
    if (
        $state->stopBeforeCaseAlt
        && $state->caseAltBodyLine !== null
        && $state->doExprStartLine < $state->caseAltBodyLine
    ) {
        return false;
    }

    if (peek($state)->line <= $state->doExprStartLine) {
        return false;
    }

    return startsDoStmt($state) || isFollowingTopLevelDecl($state);
}

function parseAlt(ParserState $state): Ast\Alt
{
    $state->inCaseAltPattern = true;
    $pattern = parsePattern($state);
    $state->inCaseAltPattern = false;

    // `p | guard -> body`: the alternative's body is a guard list, exactly like a
    // function clause's, only terminated by `->`.
    if (isAt($state, TokenKind::Pipe)) {
        return new Ast\Alt($pattern, parseGuardBody($state, '->', caseAlt: true));
    }

    expectOp($state, '->');
    $arrowLine = $state->tokens[$state->pos - 1]->line;
    // A doc comment between `->` and the body is a comment, not the body's line.
    skipDocComments($state);

    if (peek($state)->line === $arrowLine
        && !isExprStart($state)
        && caseAltBoundary($state, $arrowLine)) {
        throw incompleteCaseAltError($state, 'incomplete expression in case alternative');
    }

    $body = parseCaseAltBody($state);

    return new Ast\Alt($pattern, $body);
}

/**
 * @return Ast\AstNode
 */
function parseCaseAltBody(ParserState $state): Ast\AstNode
{
    // Layout for the body is measured from its first *real* token, so a doc
    // comment on the line before it does not look like a nested body.
    skipDocComments($state);
    $prevStop = $state->stopBeforeCaseAlt;
    $prevLine = $state->caseAltBodyLine;
    $state->stopBeforeCaseAlt = true;
    $state->caseAltBodyLine = peek($state)->line;
    $body = parseExprWithWhere($state);
    $state->stopBeforeCaseAlt = $prevStop;
    $state->caseAltBodyLine = $prevLine;

    return $body;
}

function caseAltBoundary(ParserState $state, int $continueLine): bool
{
    return startsCaseAlt($state) && peek($state)->line > $continueLine;
}

function startsCaseAlt(ParserState $state): bool
{
    if (!startsPattern($state)) {
        return false;
    }

    // Ask the pattern parser rather than listing pattern-start tokens: a missing one
    // silently ends the alternative list (`(_, _)` becoming an application).
    $saved = $state->pos;
    $savedInAltPattern = $state->inCaseAltPattern;
    $state->inCaseAltPattern = true;
    try {
        parsePattern($state);

        if (isAt($state, TokenKind::Op, 0, '->')) {
            return true;
        }

        // A guarded alternative starts the same way: `p | g -> e`. A list
        // comprehension element (`[x | x <- xs]`) has the same prefix but is
        // inside brackets, where no alternative can begin.
        return isAt($state, TokenKind::Pipe) && !insideBrackets($state);
    } catch (ParseError) {
        return false;
    } finally {
        $state->pos = $saved;
        $state->inCaseAltPattern = $savedInAltPattern;
    }
}

/**
 * @return Ast\AstNode
 */
function parseInfix(ParserState $state, int $minPrec): Ast\AstNode
{
    // Doc comments in front of an expression are comments (bodies, list
    // elements, `do` statements, operator right-hand sides, …).
    skipDocComments($state);

    $left = parseAscription($state);

    while (true) {
        if (isAt($state, TokenKind::Op)
            && !isAt($state, TokenKind::Op, 0, '=')
            && !isAt($state, TokenKind::Op, 0, '->')
            && !isAt($state, TokenKind::Op, 0, '::')
            && !isAt($state, TokenKind::Op, 0, '..')) {
            $op = peek($state)->lexeme;
            ['prec' => $prec, 'assoc' => $assoc] = fixityFor($state, $op);

            if ($prec < $minPrec) {
                break;
            }

            $opToken = advance($state);

            if ($state->stopBeforeCaseAlt && caseAltBoundary($state, $opToken->line)) {
                throw incompleteCaseAltError($state, 'incomplete expression in case alternative');
            }

            if ($state->stopBeforeGuardClause
                && $state->guardRhsLine !== null
                && guardClauseBoundary($state, $state->guardRhsLine)) {
                throw parseError($state, 'incomplete expression in guarded clause');
            }

            $nextMin = $assoc === 'infixr' ? $prec : $prec + 1;
            $right = parseInfix($state, $nextMin);
            $left = spannedRange(new Ast\Infix($op, $left, $right), $left, $right);
            continue;
        }

        if (isAt($state, TokenKind::Backtick)
            && isAt($state, TokenKind::VarId, 1)
            && isAt($state, TokenKind::Backtick, 2)) {
            $op = peekAt($state, 1)->lexeme;
            ['prec' => $prec, 'assoc' => $assoc] = fixityFor($state, $op);

            if ($prec < $minPrec) {
                break;
            }

            $start = peek($state);
            advance($state);
            advance($state);
            $opToken = advance($state);

            if ($state->stopBeforeCaseAlt && caseAltBoundary($state, $start->line)) {
                throw incompleteCaseAltError($state, 'incomplete expression in case alternative');
            }

            $nextMin = $assoc === 'infixr' ? $prec : $prec + 1;
            $right = parseInfix($state, $nextMin);
            $left = spannedRange(new Ast\Infix($op, $left, $right), $left, $right);
            continue;
        }

        break;
    }

    return $left;
}

function parseAscription(ParserState $state): Ast\AstNode
{
    $expr = parseApplication($state);

    while (isAt($state, TokenKind::Op, 0, '::')) {
        advance($state);
        $type = parseType($state);
        $expr = spannedRange(new Ast\TypeAsc($expr, $type), $expr, $expr);
    }

    return $expr;
}

function parseApplication(ParserState $state): Ast\AstNode
{
    $expr = parseProjection($state);

    while (true) {
        // A doc comment before an argument is a comment, not the end of the
        // application (only when a real argument follows it).
        while (isArgumentStart($state) || skipDocCommentsBefore($state, isArgumentStart(...))) {
            if ($state->parsingCaseScrutinee && startsCaseAlt($state)) {
                break 2;
            }

            if ($state->stopBeforeCaseAlt
                && $state->caseAltBodyLine !== null
                && peek($state)->line > $state->caseAltBodyLine
                && startsCaseAlt($state)) {
                break 2;
            }

            if ($state->stopBeforeGuardClause
                && $state->guardRhsLine !== null
                && peek($state)->line > $state->guardRhsLine
                && guardClauseBoundary($state, $state->guardRhsLine)) {
                break 2;
            }

            if (reachedDoExprBoundary($state)) {
                break 2;
            }

            // Nothing can start a declaration inside a guard expression: the
            // `=` that follows `| positive n` closes the clause, it does not
            // bind a new `n`. And a mid-line token is never a declaration.
            if (!$state->parsingGuardExpr
                && tokenStartsLine($state)
                && (startsTopLevelDecl($state) || isFollowingTopLevelDecl($state) || looksLikeParenOperatorMethodDecl($state) || looksLikeParenOperatorTypedDecl($state))) {
                break 2;
            }

            $arg = parseProjection($state);
            $expr = spannedRange(new Ast\Apply($expr, $arg), $expr, $arg);
        }

        break;
    }

    return $expr;
}

/**
 * One application argument: an atom, plus the projections that belong to it.
 *
 * Projection binds tighter than application, so `f p.age` is `f (p.age)` and
 * `p.address.city` chains left to right — the same binding `.` has in the host
 * languages users arrive from.
 */
function parseProjection(ParserState $state): Ast\AstNode
{
    $expr = parseAtom($state);

    while (true) {
        if (isAt($state, TokenKind::Op, 0, '.')
            && (isAt($state, TokenKind::VarId, 1) || isAt($state, TokenKind::ConId, 1))
            && isTightProjectionDot($state, peek($state), peekAt($state, 1))) {
            advance($state);
            $fieldToken = peek($state);
            if ($fieldToken->kind === TokenKind::ConId) {
                $name = advance($state)->lexeme;
                $module = qualifiedModuleFromExpr($expr);
                if ($module === null) {
                    throw unexpected($state, 'record field');
                }
                $expr = spannedRange(new Ast\QualifiedRef($module, $name), $expr, $expr);
                continue;
            }

            $field = expect($state, TokenKind::VarId);
            $expr = spannedRange(new Ast\FieldAccess($expr, $field->lexeme), $expr, $field);
            continue;
        }

        // `p { age = 31 }` — the record the expression already is, with the named
        // fields replaced. It is an atom-level suffix like projection, so
        // `f p { age = 31 }` updates `p` and passes the result to `f`.
        if (isAt($state, TokenKind::LBrace)) {
            $brace = peek($state);
            $fields = parseRecordConFields($state);
            if ($fields === []) {
                throw parseError($state, 'a record update needs at least one field', $brace, 'parse/record-update');
            }
            $expr = spannedRange(new Ast\RecordUpdate($expr, $fields), $brace, $brace);
            continue;
        }

        break;
    }

    return $expr;
}

/**
 * True when `.` is record/qualified projection (`receiver.field`) rather than
 * the infix composition operator exported by `Data.Function`.
 *
 * Record-dot rule: the dot must abut its receiver on the left
 * and the field name on the right. Whitespace on either side makes it the
 * ordinary `infixr 9 .` operator, so `f . g` composes while `f.g` projects.
 */
function isTightProjectionDot(ParserState $state, Token $dot, Token $field): bool
{
    $prev = $state->pos > 0 ? $state->tokens[$state->pos - 1] : null;
    if ($prev === null) {
        return false;
    }

    return $prev->line === $dot->line
        && $prev->col + strlen($prev->lexeme) === $dot->col
        && $field->line === $dot->line
        && $dot->col + 1 === $field->col;
}

function qualifiedModuleFromExpr(Ast\AstNode $expr): ?string
{
    return match ($expr::class) {
        Ast\Variable::class,
        Ast\ConstructorRef::class => $expr->name,
        default => null,
    };
}

function isExprStart(ParserState $state): bool
{
    $kind = peek($state)->kind;

    return $kind === TokenKind::Integer
        || $kind === TokenKind::Float
        || $kind === TokenKind::StringLit
        || $kind === TokenKind::CharLit
        || $kind === TokenKind::VarId
        || $kind === TokenKind::ConId
        || $kind === TokenKind::LParen
        || $kind === TokenKind::LBracket
        || $kind === TokenKind::Backslash
        || $kind === TokenKind::KwLet
        || $kind === TokenKind::KwCase
        || $kind === TokenKind::KwDo
        || $kind === TokenKind::KwIf;
}

function isArgumentStart(ParserState $state): bool
{
    // A guard expression cannot be followed by a declaration: `| positive n =
    // "yes"` applies `positive` to `n`, the `=` closes the clause. And only a
    // token that begins a line can begin a declaration at all.
    if (!$state->parsingGuardExpr
        && tokenStartsLine($state)
        && (isFollowingTopLevelDecl($state) || looksLikeParenOperatorMethodDecl($state) || looksLikeParenOperatorTypedDecl($state))) {
        return false;
    }

    if ($state->stopApplyAtLine !== null && peek($state)->line > $state->stopApplyAtLine) {
        return false;
    }

    return isExprStart($state);
}

/**
 * @return Ast\AstNode
 */
function parseAtom(ParserState $state): Ast\AstNode
{
    $token = peek($state);

    if ($state->stopBeforeCaseAlt
        && $state->caseAltBodyLine !== null
        && peek($state)->line > $state->caseAltBodyLine
        && caseAltBoundary($state, $state->caseAltBodyLine)) {
        throw incompleteCaseAltError($state, 'incomplete expression in case alternative');
    }

    if ($state->stopBeforeGuardClause
        && $state->guardRhsLine !== null
        && peek($state)->line > $state->guardRhsLine
        && guardClauseBoundary($state, $state->guardRhsLine)) {
        throw parseError($state, 'incomplete expression in guarded clause');
    }

    if ($token->kind === TokenKind::Op && $token->lexeme === '-' && startsUnaryOperand($state, $state->pos + 1)) {
        advance($state);
        $operand = parseAtom($state);

        // A negated literal is still a literal, so `-2147483648` is a constant
        // even in a module that has no `Num` in scope.
        if ($operand instanceof Ast\IntegerLit) {
            // Negating at the literal keeps `-9223372036854775808` a constant
            // (its own negation wraps back to itself) instead of a float.
            $lexeme = $operand->digits ?? (string) $operand->value;
            $negated = spanned(new Ast\IntegerLit(integerLitValue('-' . $lexeme)), $token);
            if ($operand->digits !== null) {
                $negated->digits = '-' . $operand->digits;
            }

            return $negated;
        }

        if ($operand instanceof Ast\DoubleLit) {
            return spanned(new Ast\DoubleLit(-$operand->value), $token);
        }

        // Prefix minus on anything else is `negate`, not `0 - x`: the zero
        // would pin the operand to the `Int` instance and reject
        // Double/Integer/Word ones.
        return spanned(new Ast\Apply(new Ast\Variable('negate'), $operand), $token);
    }

    if ($token->kind === TokenKind::Backslash) {
        return parseLambda($state);
    }

    if ($token->kind === TokenKind::KwLet) {
        return parseLet($state);
    }

    if ($token->kind === TokenKind::KwCase) {
        return parseCase($state);
    }

    if ($token->kind === TokenKind::KwIf) {
        return parseIf($state);
    }

    if ($token->kind === TokenKind::KwDo) {
        return parseDo($state);
    }

    if ($token->kind === TokenKind::Integer) {
        advance($state);

        return integerLit($token->lexeme, $token);
    }

    if ($token->kind === TokenKind::Float) {
        advance($state);

        return spanned(new Ast\DoubleLit((float) $token->lexeme), $token);
    }

    if ($token->kind === TokenKind::StringLit) {
        advance($state);

        return spanned(new Ast\StringLit($token->lexeme), $token);
    }

    if ($token->kind === TokenKind::CharLit) {
        advance($state);

        return spanned(new Ast\CharLit((int) $token->lexeme), $token);
    }

    if ($token->kind === TokenKind::VarId) {
        advance($state);

        if ($token->lexeme === '_') {
            return spanned(new Ast\ExprHole(), $token);
        }

        return spanned(new Ast\Variable($token->lexeme), $token);
    }

    if ($token->kind === TokenKind::ConId) {
        $name = $token->lexeme;
        advance($state);

        if (isAt($state, TokenKind::LBrace)) {
            return spanned(new Ast\RecordCon($name, parseRecordConFields($state)), $token);
        }

        return spanned(new Ast\ConstructorRef($name), $token);
    }

    if ($token->kind === TokenKind::LParen) {
        return parseParen($state);
    }

    if ($token->kind === TokenKind::LBracket) {
        return parseList($state);
    }

    throw unexpected($state, 'expression');
}

/**
 * The index of the `)` closing the `(` at `$open`.
 */
function matchingParenIndex(ParserState $state, int $open): ?int
{
    $depth = 0;
    $count = \count($state->tokens);
    for ($i = $open; $i < $count; $i++) {
        $kind = $state->tokens[$i]->kind;
        if ($kind === TokenKind::LParen) {
            ++$depth;
        } elseif ($kind === TokenKind::RParen) {
            --$depth;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * An operator starting at `$at`: a symbolic operator, or a backticked name.
 *
 * @return array{end: int, lexeme: string}|null `end` is exclusive
 */
function operatorTokensAt(ParserState $state, int $at): ?array
{
    $token = $state->tokens[$at] ?? null;
    if ($token === null) {
        return null;
    }

    if ($token->kind === TokenKind::Op) {
        return ['end' => $at + 1, 'lexeme' => $token->lexeme];
    }

    if ($token->kind === TokenKind::Backtick
        && ($state->tokens[$at + 1] ?? null)?->kind === TokenKind::VarId
        && ($state->tokens[$at + 2] ?? null)?->kind === TokenKind::Backtick
    ) {
        return ['end' => $at + 3, 'lexeme' => $state->tokens[$at + 1]->lexeme];
    }

    return null;
}

/**
 * Whether an operator may stand for a missing section operand. `-` is never a
 * right section (`(- 7)` and `(-7)` are negation: write `(subtract 7)`), but it
 * does close one (`(2 -)`). The type-signature and arrow tokens are not
 * operators an expression can be sectioned on.
 */
function isSectionOperator(string $lexeme, bool $leading): bool
{
    if ($lexeme === '-') {
        return !$leading;
    }

    return !\in_array($lexeme, ['::', '->', '=', '..'], true);
}

/**
 * Splice the missing operand of an operator section into the token stream as
 * `__section`, so `(* 2)` and `(2 *)` parse as the expression a hand-written
 * `\x -> …` would: precedence and associativity inside the section are then
 * the parser's, not the section's (`(a - b -)` is `(a - b) - x`, and
 * `(* 2 + 3)` is `\x -> x * 2 + 3`).
 *
 * @param int $open index of the `(`
 */
function spliceOperatorSection(ParserState $state, int $open): bool
{
    $close = matchingParenIndex($state, $open);
    if ($close === null || $close <= $open + 1 || commaInsideParens($state, $open, $close)) {
        return false;
    }

    // A right section's operand goes in front of the operator (`(* 2)`); a
    // left section's behind it (`(2 *)`).
    $first = operatorTokensAt($state, $open + 1);
    if ($first !== null && $first['end'] < $close && isSectionOperator($first['lexeme'], leading: true)) {
        return spliceSectionVar($state, $open + 1);
    }

    // The closing operator is the section's: a symbolic one is the token before
    // `)`, a backticked one the three tokens ending there.
    foreach ([$close - 1, $close - 3] as $at) {
        $last = operatorTokensAt($state, $at);
        if ($last !== null && $last['end'] === $close && $at > $open + 1 && isSectionOperator($last['lexeme'], leading: false)) {
            return spliceSectionVar($state, $close);
        }
    }

    return false;
}

function spliceSectionVar(ParserState $state, int $at): bool
{
    $anchor = $state->tokens[$at] ?? $state->tokens[$at - 1] ?? null;
    if ($anchor === null) {
        return false;
    }

    \array_splice(
        $state->tokens,
        $at,
        0,
        [new Token(TokenKind::VarId, '__section', $anchor->line, $anchor->col)],
    );

    return true;
}

/** A comma at the paren's own level means the parens hold a tuple, not a section. */
function commaInsideParens(ParserState $state, int $open, int $close): bool
{
    $depth = 0;
    for ($i = $open + 1; $i < $close; $i++) {
        $kind = $state->tokens[$i]->kind;
        if ($kind === TokenKind::LParen) {
            ++$depth;
        } elseif ($kind === TokenKind::RParen) {
            --$depth;
        } elseif ($kind === TokenKind::Comma && $depth === 0) {
            return true;
        }
    }

    return false;
}

/**
 * @return Ast\AstNode
 */
function parseParen(ParserState $state): Ast\AstNode
{
    $start = expect($state, TokenKind::LParen);

    if (isAt($state, TokenKind::RParen)) {
        advance($state);

        return spanned(new Ast\Tuple([]), $start);
    }

    // `(+)` is an operator reference, but `(-7)` and `(- 7)` are negated
    // literals: the operator-reference branch needs the closing paren.
    if (isAt($state, TokenKind::Op) && peekAt($state, 1)->kind === TokenKind::RParen) {
        $opToken = advance($state);
        advance($state);

        return spanned(new Ast\OperatorRef($opToken->lexeme), $opToken);
    }

    $section = spliceOperatorSection($state, $state->pos - 1);

    $first = parseExprWithWhere($state);

    if (isAt($state, TokenKind::Comma)) {
        $elements = [$first];
        while (isAt($state, TokenKind::Comma)) {
            advance($state);
            $elements[] = parseExprWithWhere($state);
        }
        expect($state, TokenKind::RParen);

        return spannedRange(new Ast\Tuple($elements), $start, $elements[count($elements) - 1]);
    }

    expect($state, TokenKind::RParen);

    if ($section) {
        $param = spanned(new Ast\PatVar('__section'), $start);

        return spannedRange(
            new Ast\Lambda([new Ast\LambdaParam($param, null)], $first),
            $start,
            $first,
        );
    }

    return $first;
}

/**
 * @return Ast\AstNode
 */
function parseList(ParserState $state): Ast\AstNode
{
    $start = expect($state, TokenKind::LBracket);

    if (isAt($state, TokenKind::RBracket)) {
        advance($state);

        return spanned(new Ast\ListLit([]), $start);
    }

    $first = parseExprWithWhere($state);

    if (isAt($state, TokenKind::Pipe)) {
        advance($state);

        return spanned(parseListComp($first, $state), $start);
    }

    if (isAt($state, TokenKind::Op, 0, '..')) {
        advance($state);
        $last = parseExprWithWhere($state);
        $end = expect($state, TokenKind::RBracket);

        return spannedRange(Ast\desugarEnumFromTo($first, $last), $start, $end);
    }

    if (isAt($state, TokenKind::Comma)) {
        advance($state);
        $second = parseExprWithWhere($state);

        if (isAt($state, TokenKind::Op, 0, '..')) {
            advance($state);
            $last = parseExprWithWhere($state);
            $end = expect($state, TokenKind::RBracket);

            return spannedRange(Ast\desugarEnumFromThenTo($first, $second, $last), $start, $end);
        }

        $elements = [$first, $second];
        while (isAt($state, TokenKind::Comma)) {
            advance($state);
            $elements[] = parseExprWithWhere($state);
        }

        expect($state, TokenKind::RBracket);

        return spannedRange(new Ast\ListLit($elements), $start, $elements[count($elements) - 1]);
    }

    expect($state, TokenKind::RBracket);

    return spannedRange(new Ast\ListLit([$first]), $start, $first);
}

/**
 * @return Ast\AstNode
 */
function parseListComp(Ast\AstNode $expr, ParserState $state): Ast\AstNode
{
    $qualifiers = [];

    while (!isAt($state, TokenKind::RBracket)) {
        if (startsPattern($state) && isAt($state, TokenKind::Op, 1, '<-')) {
            $pattern = parsePattern($state);
            advance($state);
            $qualifiers[] = new Ast\QualGen($pattern, parseExprWithWhere($state));
        } else {
            $qualifiers[] = new Ast\QualGuard(parseExprWithWhere($state));
        }

        if (isAt($state, TokenKind::Comma)) {
            advance($state);
            continue;
        }

        break;
    }

    expect($state, TokenKind::RBracket);

    return Ast\desugarListComp($expr, $qualifiers);
}
