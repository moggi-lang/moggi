<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;

use function Moggi\Errors\appendDidYouMean;

/**
 * Parse the body of a `{-# BACKEND … #-}` pragma into backend => implementation module.
 *
 *   {-# BACKEND php = System.Filesystem.PHP #-}
 *
 *   {-# BACKEND
 *     php    = System.Filesystem.PHP
 *     jvm    = System.Filesystem.JVM
 *     dotnet = System.Filesystem.DotNet
 *   #-}
 *
 * Entries are separated by newlines or commas.
 *
 * @return array<string, string>
 */
function parseBackendPragma(ParserState $state, Token $token, string $rest): array
{
    $map = [];
    foreach (preg_split('/[\n,]+/', $rest) ?: [] as $rawEntry) {
        $entry = trim($rawEntry);
        if ($entry === '') {
            continue;
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9]*)\s*=\s*(\S+)$/', $entry, $m) !== 1) {
            throw parseError(
                $state,
                "malformed BACKEND entry `{$entry}`; expected `<backend> = <Module>`",
                $token,
                'parse/pragma',
            );
        }

        $backend = $m[1];
        $module = trim($m[2]);
        if (!isModuleName($module)) {
            throw parseError(
                $state,
                "`{$module}` is not a module name; expected dot-separated constructors, as in `Data.Char.PHP`",
                $token,
                'parse/pragma',
            );
        }
        if (isset($map[$backend])) {
            throw parseError(
                $state,
                "backend `{$backend}` is listed twice in the BACKEND pragma",
                $token,
                'parse/pragma',
            );
        }

        $map[$backend] = $module;
    }

    if ($map === []) {
        throw parseError(
            $state,
            'BACKEND pragma needs at least one `<backend> = <Module>` entry',
            $token,
            'parse/pragma',
        );
    }

    return $map;
}

function isModuleName(string $name): bool
{
    foreach (explode('.', $name) as $segment) {
        if (preg_match("/^[A-Z][A-Za-z0-9_']*$/", $segment) !== 1) {
            return false;
        }
    }

    return true;
}

function parseForeignDecl(ParserState $state): Ast\AstNode
{
    expect($state, TokenKind::KwForeign);
    $backend = expect($state, TokenKind::VarId)->lexeme;

    // Check for `foreign type` (KwType) vs `foreign function/const` (VarId)
    $token = peek($state);

    if ($token->kind === TokenKind::KwType) {
        advance($state);
        $nameToken = expectOneOf($state, TokenKind::VarId, TokenKind::ConId);
        $name = $nameToken->lexeme;
        $hostType = expect($state, TokenKind::StringLit)->lexeme;

        // Positioned at the declared name, like `data`/`class`, so a diagnostic about the
        // declaration can be rendered against the source line it was written on.
        return new Ast\ForeignTypeDecl(
            $backend,
            $name,
            $hostType,
            $nameToken->line,
            $nameToken->col,
            $nameToken->col + max(0, strlen($name) - 1),
        );
    }

    // Must be function or const
    if ($token->kind === TokenKind::VarId) {
        $kindToken = advance($state);
        $kindLexeme = $kindToken->lexeme;

        if ($kindLexeme === 'function' || $kindLexeme === 'const') {
            $nameToken = expect($state, TokenKind::VarId);
            $name = $nameToken->lexeme;
            $path = expect($state, TokenKind::StringLit)->lexeme;
            expectOp($state, '::');
            $type = parseType($state);

            return new Ast\ForeignImportDecl(
                $backend,
                $kindLexeme,
                $name,
                $path,
                $type,
                $nameToken->line,
                $nameToken->col,
                $nameToken->col + max(0, strlen($name) - 1),
            );
        }

        throw parseError(
            $state,
            appendDidYouMean(
                "I do not recognize the foreign declaration kind `{$kindLexeme}`; expected `type`, `function`, or `const`",
                $kindLexeme,
                ['type', 'function', 'const'],
            ),
            $kindToken,
        );
    }

    throw unexpected($state, 'foreign declaration kind (`type`, `function`, or `const`)');
}

function parseSignatureFunction(ParserState $state): Ast\FunctionDecl
{
    $name = expect($state, TokenKind::VarId)->lexeme;
    expectOp($state, '::');
    $type = parseType($state);

    return new Ast\FunctionDecl($name, $type, [], new Ast\SignatureOnly(), true);
}
