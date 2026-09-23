<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;

use function Moggi\Syntax\Lexer\lex;

/**
 * Result of parsing a single REPL input unit (one top-level decl, statement
 * sequence, or expression).
 */
final class ReplFragment
{
    public const KIND_DECL = 'decl';
    public const KIND_STMT = 'stmt';
    public const KIND_EXPR = 'expr';
    public const KIND_INCOMPLETE = 'incomplete';
    public const KIND_ERROR = 'error';

    /**
     * @param self::KIND_* $kind
     */
    public function __construct(
        public string $kind,
        public ?Ast\AstNode $node = null,
        public ?ParseError $error = null,
        public string $source = '',
    ) {
    }
}

/**
 * Parse one REPL fragment. A leading REPL-style `let` is stripped as sugar for a decl
 * unless the input is a do-statement (`let` without `in` among stmts is handled
 * separately).
 *
 * @param array<string, array{assoc: string, prec: int}> $importedFixity
 */
function parseReplFragment(
    string $source,
    string $filename = '<interactive>',
    array $importedFixity = [],
): ReplFragment {
    $trimmed = trim($source);
    if ($trimmed === '') {
        return new ReplFragment(ReplFragment::KIND_INCOMPLETE, source: $source);
    }

    // REPL sugar: `let x = …` → top-level `x = …` (not for multi-stmt / `<-` input).
    if (preg_match('/^let\b/s', $trimmed) === 1) {
        $afterLet = ltrim(substr($trimmed, 3));
        if ($afterLet !== '' && !str_starts_with($afterLet, '{') && !looksLikeReplStatementSource($trimmed)) {
            $trimmed = $afterLet;
        }
    }

    // Common typo / Python-ish binding: `x:10` → `x = 10` when the RHS is a literal.
    // Real list cons (`xs:x`, `1:[]`) is left alone.
    $asDecl = replColonLiteralToAssignment($trimmed);
    if ($asDecl !== null) {
        $trimmed = $asDecl;
    }

    try {
        $tokens = lex($trimmed . "\n", $filename);
        $localFixity = collectFixity($tokens, $trimmed, $filename);
        $fixity = mergeFixity($importedFixity, $localFixity);

        $declState = new ParserState($tokens, 0, $fixity, $trimmed, $filename);
        if (isAt($declState, TokenKind::Eof)) {
            return new ReplFragment(ReplFragment::KIND_INCOMPLETE, source: $source);
        }

        $decl = parseTopLevelDecl($declState);
        if ($decl !== null) {
            if (!isAt($declState, TokenKind::Eof)) {
                throw unexpected($declState, 'end of input');
            }

            return new ReplFragment(ReplFragment::KIND_DECL, $decl, null, $trimmed);
        }

        // Prompt statements: `x <- e`, `let x = e` in stmt position, `;`-sequences.
        // Prefer this over parsing `<-` as a plain infix expression.
        $stmtFrag = tryParseReplStatements($tokens, $trimmed, $filename, $fixity);
        if ($stmtFrag !== null) {
            return $stmtFrag;
        }

        $exprState = new ParserState($tokens, 0, $fixity, $trimmed, $filename);
        $node = parseExprWithWhere($exprState);
        if (!isAt($exprState, TokenKind::Eof)) {
            throw unexpected($exprState, 'end of input');
        }

        return new ReplFragment(ReplFragment::KIND_EXPR, $node, null, $trimmed);
    } catch (ParseError $e) {
        if (isIncompleteParseError($e)) {
            return new ReplFragment(ReplFragment::KIND_INCOMPLETE, error: $e, source: $source);
        }

        return new ReplFragment(ReplFragment::KIND_ERROR, error: $e, source: $source);
    }
}

function looksLikeReplStatementSource(string $source): bool
{
    // `let …` with another stmt (`<-` or `;`) → statement block, not decl sugar.
    return str_contains($source, ';') || str_contains($source, '<-');
}

/**
 * Parse one or more do-statements for the REPL prompt.
 * Returns null when the input is only a single expression statement (use KIND_EXPR).
 *
 * @param array<int, mixed> $tokens
 * @param array<string, array{assoc: string, prec: int}> $fixity
 */
function tryParseReplStatements(
    array $tokens,
    string $source,
    string $filename,
    array $fixity,
): ?ReplFragment {
    try {
        $state = new ParserState($tokens, 0, $fixity, $source, $filename);
        if (isAt($state, TokenKind::Eof)) {
            return null;
        }

        // A leading `do` is an expression, not prompt-statement sugar.
        if (isAt($state, TokenKind::KwDo)) {
            return null;
        }

        $prevInDo = $state->inDoBlock;
        $prevDoCol = $state->doBlockCol;
        $state->inDoBlock = true;
        $state->doBlockCol = peek($state)->col;
        $stmts = [parseDoStmt($state)];
        while (isAt($state, TokenKind::Semicolon) || startsDoStmt($state)) {
            if (isAt($state, TokenKind::Semicolon)) {
                advance($state);
            }
            if (!startsDoStmt($state) || isFollowingTopLevelDecl($state)) {
                break;
            }
            $stmts[] = parseDoStmt($state);
        }
        $state->inDoBlock = $prevInDo;
        $state->doBlockCol = $prevDoCol;

        if (!isAt($state, TokenKind::Eof)) {
            return null;
        }

        // Lone expression statement → normal expression evaluation / printing.
        if (count($stmts) === 1 && $stmts[0] instanceof Ast\DoExprStmt) {
            return null;
        }

        return new ReplFragment(
            ReplFragment::KIND_STMT,
            new Ast\DoExpr($stmts),
            null,
            $source,
        );
    } catch (ParseError) {
        return null;
    }
}

/**
 * `x:10` / `x: "hi"` → `x = 10` / `x = "hi"`. Leaves `x :: T` and list cons alone.
 */
function replColonLiteralToAssignment(string $source): ?string
{
    if (!preg_match(
        '/^([A-Za-z_][A-Za-z0-9_\']*)\s*:\s*(.+)$/s',
        $source,
        $m,
    )) {
        return null;
    }

    $rhs = ltrim($m[2]);
    // Type signature uses `::`, not a single `:`.
    if (str_starts_with($rhs, ':')) {
        return null;
    }

    // Only rewrite when the RHS starts like a literal (not `xs:x` / `1:[]`).
    if (!preg_match('/^(?:-?\d|\"|\'|True\b|False\b)/', $rhs)) {
        return null;
    }

    return $m[1] . ' = ' . $m[2];
}

/**
 * Parse a type expression for `:kind`.
 *
 * @param array<string, array{assoc: string, prec: int}> $importedFixity
 */
function parseReplType(
    string $source,
    string $filename = '<interactive>',
    array $importedFixity = [],
): Ast\TypeNode {
    $trimmed = trim($source);
    $tokens = lex($trimmed . "\n", $filename);
    $state = new ParserState($tokens, 0, $importedFixity, $trimmed, $filename);
    $type = parseType($state);
    if (!isAt($state, TokenKind::Eof)) {
        throw unexpected($state, 'end of input');
    }

    return $type;
}

function isIncompleteParseError(ParseError $e): bool
{
    $msg = $e->getMessage();

    return str_contains($msg, 'incomplete')
        || str_contains($msg, 'end of file')
        || str_contains($msg, 'end of input');
}
