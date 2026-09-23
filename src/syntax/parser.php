<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;

require_once __DIR__ . '/fixity.php';
require_once __DIR__ . '/parser_types.php';
require_once __DIR__ . '/parse_util.php';
require_once __DIR__ . '/parse_type.php';
require_once __DIR__ . '/parse_pattern.php';
require_once __DIR__ . '/parse_module.php';
require_once __DIR__ . '/foreign_parse.php';
require_once __DIR__ . '/parse_decl.php';
require_once __DIR__ . '/parse_expr.php';
require_once __DIR__ . '/desugar_binds.php';

/**
 * @param list<Token> $tokens
 * @return array{
 *   module: ?string,
 *   imports: list<array<string, mixed>>,
 *   exports: mixed,
 *   localFixity: array<string, array{assoc: string, prec: int}>,
 *   language: array<string, true>,
 *   backendMap: array<string, string>
 * }
 */
function parseModuleHeader(array $tokens, string $source = '', string $filename = ''): array
{
    $localFixity = collectFixity($tokens, $source, $filename);
    $state = new ParserState($tokens, 0, $localFixity, $source, $filename);
    $pragmas = parseHeaderPragmas($state);
    $language = $pragmas['language'];

    $moduleDoc = consumeLeadingDoc($state);

    $module = null;
    $exports = null;
    $moduleBackend = null;
    $implicitMain = false;
    $moduleNameToken = null;
    if (isAt($state, TokenKind::KwModule)) {
        // Kept for diagnostics about the module as a whole — a facade with no implementation for
        // the compile backend, say — which are rendered against the name here. This header pass is
        // the only one that sees the tokens of a module that never gets parsed into a program.
        $moduleNameToken = isAt($state, TokenKind::ConId, 1) ? peekAt($state, 1) : null;
        [$module, $exports, $moduleBackend] = parseModuleDecl($state);
    } else {
        $implicit = implicitMainModuleHeader();
        $module = $implicit['module'];
        $exports = $implicit['exports'];
        $implicitMain = true;
    }

    $imports = [];
    while (true) {
        $importDoc = null;
        if ($imports === []) {
            skipModuleHeaderDocs($state);
        } else {
            $importDoc = consumeLeadingDoc($state);
        }
        if (!isAt($state, TokenKind::KwImport)) {
            break;
        }
        $import = parseImportDecl($state);
        if ($importDoc !== null && $importDoc !== '') {
            $import->doc = $importDoc;
        }
        $imports[] = $import;
    }

    return [
        'module' => $module,
        'imports' => $imports,
        'exports' => $exports,
        'localFixity' => $localFixity,
        'backendMap' => $pragmas['backendMap'],
        'moduleBackend' => $moduleBackend,
        'implicitMain' => $implicitMain,
        'language' => $language,
        'moduleDoc' => $moduleDoc,
        'headerSpan' => $moduleNameToken === null ? null : [
            'line' => $moduleNameToken->line,
            'col' => $moduleNameToken->col,
            'endCol' => $moduleNameToken->col + \max(0, \strlen($moduleNameToken->lexeme) - 1),
        ],
    ];
}

/**
 * @param list<Token> $tokens
 * @param array<string, array{assoc: string, prec: int}> $importedFixity
 * @param array<string, array{assoc: string, prec: int}>|null $localFixity
 * @param list<Ast\ImportDecl> $imports
 * @return Ast\Program
 */
function parse(
    array $tokens,
    string $source = '',
    string $filename = '',
    array $importedFixity = [],
    ?array $localFixity = null,
    array $imports = [],
): Ast\Program {
    $localFixity ??= collectFixity($tokens, $source, $filename);
    $state = new ParserState(
        $tokens,
        0,
        mergeFixity($importedFixity, $localFixity),
        $source,
        $filename,
    );
    $pragmas = parseHeaderPragmas($state);
    $language = $pragmas['language'];

    $moduleDoc = consumeLeadingDoc($state);
    $module = null;
    $exports = null;
    $moduleBackend = null;
    $implicitMain = false;
    if (isAt($state, TokenKind::KwModule)) {
        [$module, $exports, $moduleBackend] = parseModuleDecl($state);
    } else {
        $implicit = implicitMainModuleHeader();
        $module = $implicit['module'];
        $exports = $implicit['exports'];
        $implicitMain = true;
    }

    // Prepare may pre-seed imports (header parse + injectPrelude). Consume the
    // source import decls without appending so they are not doubled.
    if ($imports !== []) {
        while (isAt($state, TokenKind::KwImport)) {
            skipModuleHeaderDocs($state);
            parseImportDecl($state);
        }
    } else {
        while (true) {
            $importDoc = null;
            if ($imports === []) {
                skipModuleHeaderDocs($state);
            } else {
                $importDoc = consumeLeadingDoc($state);
            }
            if (!isAt($state, TokenKind::KwImport)) {
                break;
            }
            $import = parseImportDecl($state);
            if ($importDoc !== null && $importDoc !== '') {
                $import->doc = $importDoc;
            }
            $imports[] = $import;
        }
    }

    $backendMap = $pragmas['backendMap'];

    $items = [];
    $fixityDocs = [];
    $state->backendMap = $backendMap;

    while (!isAt($state, TokenKind::Eof)) {
        if (isAt($state, TokenKind::KwImport)) {
            throw lateImportError($state, $items);
        }

        skipDocSections($state);
        $leadingDoc = consumeLeadingDoc($state);

        if (isAt($state, TokenKind::KwInfix)
            || isAt($state, TokenKind::KwInfixl)
            || isAt($state, TokenKind::KwInfixr)) {
            $decl = parseFixityDecl($state);
            $trailingDoc = consumeTrailingDoc($state);
            $doc = mergeDoc($leadingDoc, $trailingDoc);
            if ($doc !== null && $doc !== '') {
                $fixityDocs[] = [
                    'doc' => $doc,
                    'operators' => $decl['operators'],
                    'assoc' => $decl['assoc'],
                    'prec' => (int) ($decl['precedence'] ?? 9),
                ];
            }
            continue;
        }

        // Offside boundary for this declaration: continuation keywords (`in`,
        // `where`) must stay strictly deeper than the item's first column.
        $state->declCol = peek($state)->col;
        $item = parseTopLevel($state);
        $trailingDoc = consumeTrailingDoc($state);
        attachDoc($item, $leadingDoc, $trailingDoc);
        $items[] = $item;
    }

    $program = new Ast\Program(
        mergeFunctionClauses($items, $source, $filename),
        $module,
        $imports,
        $exports,
        [],
        [],
        [],
        [],
        $moduleBackend,
        $backendMap,
        [],
        $implicitMain,
        $language,
        $moduleDoc,
        $fixityDocs,
    );

    return $program;
}

/**
 * Consume leading `{-# LANGUAGE … #-}` pragmas.
 *
 * @return array<string, true>
 */
/**
 * The pragmas that may precede the module header: `{-# LANGUAGE … #-}` and
 * `{-# BACKEND … #-}`. Any other pragma is rejected here.
 *
 * @return array{language: array<string, true>, backendMap: array<string, string>}
 */
function parseHeaderPragmas(ParserState $state): array
{
    $language = [];
    $backendMap = [];
    while (isAt($state, TokenKind::Pragma)) {
        $token = advance($state);
        $body = trim($token->lexeme);
        if (preg_match('/^BACKEND\b(.*)$/is', $body, $m) === 1) {
            foreach (parseBackendPragma($state, $token, $m[1]) as $backend => $module) {
                $backendMap[$backend] = $module;
            }
            continue;
        }
        foreach (languageExtensionsFromPragma($state, $token) as $ext) {
            $language[$ext] = true;
        }
    }

    return ['language' => $language, 'backendMap' => $backendMap];
}

/**
 * @return list<string>
 */
function languageExtensionsFromPragma(ParserState $state, Token $token): array
{
    $body = trim($token->lexeme);
    if ($body === '') {
        throw parseError($state, 'empty pragma', $token, 'parse/pragma');
    }

    if (!preg_match('/^LANGUAGE\b(.*)$/is', $body, $m)) {
        throw parseError($state, "unsupported pragma `{$body}`", $token, 'parse/pragma');
    }

    $rest = trim($m[1]);
    if ($rest === '') {
        throw parseError($state, 'LANGUAGE pragma needs at least one extension', $token, 'parse/pragma');
    }

    $extensions = [];
    foreach (preg_split('/\s*,\s*/', $rest) ?: [] as $raw) {
        $name = trim($raw);
        if ($name === '') {
            continue;
        }
        if ($name !== 'NoImplicitPrelude') {
            throw parseError($state, "unknown LANGUAGE extension `{$name}`", $token, 'parse/pragma');
        }
        $extensions[] = $name;
    }

    if ($extensions === []) {
        throw parseError($state, 'LANGUAGE pragma needs at least one extension', $token, 'parse/pragma');
    }

    return $extensions;
}

/**
 * @param list<Token> $tokens
 * @return array<string, array{assoc: string, prec: int}>
 */
function collectFixity(array $tokens, string $source = '', string $filename = ''): array
{
    $fixity = [];
    $count = count($tokens);

    for ($pos = 0; $pos < $count; ++$pos) {
        $kind = $tokens[$pos]->kind;
        if ($kind !== TokenKind::KwInfix
            && $kind !== TokenKind::KwInfixl
            && $kind !== TokenKind::KwInfixr) {
            continue;
        }

        $state = new ParserState($tokens, $pos, [], $source, $filename);
        $decl = parseFixityDecl($state);
        $precedence = $decl['precedence'] ?? 9;
        foreach ($decl['operators'] as $operator) {
            $fixity[$operator] = ['assoc' => $decl['assoc'], 'prec' => $precedence];
        }

        $pos = $state->pos - 1;
    }

    return $fixity;
}

/**
 * Intermediate accumulator used only while merging same-named equations.
 * Not an AST node — finalize collapses it back into FunctionDecl.
 *
 * @param list<Ast\AstNode> $items
 * @return list<Ast\AstNode>
 */
function mergeFunctionClauses(array $items, string $source = '', string $filename = ''): array
{
    $merged = [];
    /** @var ?array{name: string, type: ?Ast\AstNode, span: ?array{0: int, 1: int, 2: int}, clauses: list<array{params: array<int, Ast\AstNode>, body: Ast\AstNode}>} $pending */
    $pending = null;
    $context = ['source' => $source, 'filename' => $filename];

    foreach ($items as $item) {
        if (!($item instanceof Ast\FunctionDecl)) {
            if ($pending !== null) {
                $merged[] = finalizeFunctionDecl($pending, $context);
                $pending = null;
            }
            $merged[] = $item;
            continue;
        }

        if ($pending !== null && $pending['name'] === $item->name) {
            // A leading `name :: T` (parsed as a signature-only clause in
            // backend modules) only carries the type; the following
            // definition supplies the real body. Replace the placeholder
            // rather than accumulating it as a second clause, so that
            // `name :: T` + `name x = ...` is one typed function instead of
            // two clauses with mismatched arities.
            $pendingIsSigOnly = count($pending['clauses']) === 1
                && $pending['clauses'][0]['body'] instanceof Ast\SignatureOnly;
            $incomingIsSigOnly = $item->signatureOnly || $item->body instanceof Ast\SignatureOnly;
            if ($pendingIsSigOnly && !$incomingIsSigOnly) {
                $pending['clauses'] = [['params' => $item->params, 'body' => $item->body]];
            } else {
                $pending['clauses'][] = ['params' => $item->params, 'body' => $item->body];
            }
            // The first positioned equation names the declaration; later ones are the same
            // declaration and must not move it.
            $pending['span'] ??= declarationSpan($item);
            if ($pending['type'] === null && $item->type !== null) {
                $pending['type'] = $item->type;
            }
            if ($item->doc !== null) {
                if ($pendingIsSigOnly && !$incomingIsSigOnly) {
                    $pending['doc'] = mergeDoc($pending['doc'] ?? null, $item->doc);
                } elseif (($pending['doc'] ?? null) === null) {
                    $pending['doc'] = $item->doc;
                } elseif (!$incomingIsSigOnly) {
                    $pending['doc'] = mergeDoc($pending['doc'], $item->doc);
                }
            }
            continue;
        }

        if ($pending !== null) {
            $merged[] = finalizeFunctionDecl($pending, $context);
        }

        $pending = [
            'name' => $item->name,
            'type' => $item->type,
            'doc' => $item->doc,
            'span' => declarationSpan($item),
            'clauses' => [['params' => $item->params, 'body' => $item->body]],
        ];
    }

    if ($pending !== null) {
        $merged[] = finalizeFunctionDecl($pending, $context);
    }

    return $merged;
}

/**
 * @param array{name: string, type: ?Ast\AstNode, doc: ?string, clauses: list<array{params: array<int, Ast\AstNode>, body: Ast\AstNode}>} $fn
 * @param array{source: string, filename: string} $context
 */
function finalizeFunctionDecl(array $fn, array $context): Ast\FunctionDecl
{
    if (count($fn['clauses']) === 1) {
        $body = $fn['clauses'][0]['body'];
        if ($body instanceof Ast\SignatureOnly) {
            $type = $fn['type'] ?? throw parseError(
                new ParserState([], 0, [], $context['source'], $context['filename']),
                "signature-only function `{$fn['name']}` requires a type annotation",
            );

            $decl = new Ast\FunctionDecl($fn['name'], $type, [], $body, true);
            locateDeclaration($decl, $fn);
            $decl->doc = $fn['doc'] ?? null;

            return $decl;
        }

        $decl = new Ast\FunctionDecl($fn['name'], $fn['type'], $fn['clauses'][0]['params'], $body);
        locateDeclaration($decl, $fn);
        $decl->doc = $fn['doc'] ?? null;

        return $decl;
    }

    $decl = desugarMultiClauseFunction($fn, $context);
    $decl->doc = $fn['doc'] ?? null;

    return $decl;
}

/**
 * A declaration's own span, if the parser recorded one.
 *
 * @return ?array{0: int, 1: int, 2: int}
 */
function declarationSpan(Ast\AstNode $decl): ?array
{
    return $decl->line === 0 ? null : [$decl->line, $decl->col, $decl->endCol];
}

/**
 * @param array{name: string, type: ?Ast\AstNode, doc: ?string, span: ?array{0: int, 1: int, 2: int}, clauses: list<array{params: array<int, Ast\AstNode>, body: Ast\AstNode}>} $fn
 */
function locateDeclaration(Ast\AstNode $decl, array $fn): void
{
    $span = $fn['span'] ?? null;
    if ($span !== null) {
        $decl->setLocation($span[0], $span[1], $span[2]);
    }
}

/**
 * @param array{name: string, type: ?Ast\AstNode, clauses: list<array{params: array<int, Ast\AstNode>, body: Ast\AstNode}>} $fn
 * @param array{source: string, filename: string} $context
 */
function desugarMultiClauseFunction(array $fn, array $context): Ast\FunctionDecl
{
    $clauses = $fn['clauses'];
    $paramCount = count($clauses[0]['params']);

    foreach ($clauses as $clause) {
        if (count($clause['params']) !== $paramCount) {
            $at = $clause['params'][0] ?? null;
            if ($at instanceof Ast\AstNode) {
                throw parseError(
                    new ParserState([], 0, [], $context['source'], $context['filename']),
                    "function `{$fn['name']}` has inconsistent clause arities",
                    new Token(TokenKind::Eof, '', $at->line, $at->col),
                    'parse/clause-arity',
                );
            }

            throw new ParseError(
                "function `{$fn['name']}` has inconsistent clause arities",
                $context['filename'],
                $context['source'],
                1,
                1,
                1,
                'parse/clause-arity',
            );
        }
    }

    $slots = functionParamSlotNames($clauses[0]['params']);
    $params = \array_map(
        static fn (string $slot): Ast\PatVar => new Ast\PatVar($slot),
        $slots,
    );

    $decl = new Ast\FunctionDecl($fn['name'], $fn['type'], $params, desugarClauseDispatch($clauses, $slots));
    locateDeclaration($decl, $fn);

    return $decl;
}

/** @param array<int, Ast\AstNode> $patterns @return list<string> */
function functionParamSlotNames(array $patterns): array
{
    $names = [];
    foreach ($patterns as $i => $pattern) {
        if ($pattern instanceof Ast\PatVar) {
            $names[] = $pattern->name;
            continue;
        }

        $names[] = '_p' . $i;
    }

    return $names;
}
