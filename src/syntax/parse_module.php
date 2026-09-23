<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Ast\ImportDecl;
use Moggi\Syntax\Lexer\TokenKind;

use function Moggi\Syntax\Ast\moduleName;
use function Moggi\Syntax\isTypeOperator;

/**
 * @return array{assoc: string, precedence: ?int, operators: array<int, string>}
 */
function parseFixityDecl(ParserState $state): array
{
    $token = peek($state);
    $assoc = match ($token->kind) {
        TokenKind::KwInfix => 'infix',
        TokenKind::KwInfixl => 'infixl',
        TokenKind::KwInfixr => 'infixr',
        default => throw unexpected($state, 'fixity declaration'),
    };
    advance($state);

    $precedence = null;
    if (isAt($state, TokenKind::Integer)) {
        $precedence = (int) advance($state)->lexeme;
    }

    $operators = [parseFixityOperator($state)];
    while (isAt($state, TokenKind::Comma)) {
        advance($state);
        $operators[] = parseFixityOperator($state);
    }

    return ['assoc' => $assoc, 'precedence' => $precedence, 'operators' => $operators];
}

function parseFixityOperator(ParserState $state): string
{
    if (isAt($state, TokenKind::Backtick)) {
        advance($state);
        $name = expect($state, TokenKind::VarId)->lexeme;
        expect($state, TokenKind::Backtick);

        return $name;
    }

    if (isAt($state, TokenKind::Op)) {
        return advance($state)->lexeme;
    }

    throw unexpected($state, 'operator name');
}

function parseModuleDecl(ParserState $state): array
{
    expect($state, TokenKind::KwModule);
    $path = parseModulePath($state);
    $exports = null;

    if (isAt($state, TokenKind::LParen)) {
        $exports = parseModuleExportList($state);
        expect($state, TokenKind::KwWhere);
    } elseif (isAt($state, TokenKind::KwWhere)) {
        advance($state);
    }

    return [Ast\moduleName($path), $exports, null];
}

/**
 * Synthesize `module Main(main) where` for an omitted module header.
 *
 * @return array{module: string, exports: list<array{tag: string, name: string}>, implicitMain: true}
 */
function implicitMainModuleHeader(): array
{
    return [
        'module' => 'Main',
        'exports' => [['tag' => 'value', 'name' => 'main']],
        'implicitMain' => true,
    ];
}

/**
 * @return list<array{tag: string, name: string, path?: list<string>, children?: array{mode: string, names: list<string>}, section?: string}>
 */
function parseModuleExportList(ParserState $state): array
{
    expect($state, TokenKind::LParen);
    $exports = [];
    $currentSection = null;
    $afterComma = false;

    while (!isAt($state, TokenKind::RParen)) {
        while (isAt($state, TokenKind::DocSection)) {
            $currentSection = advance($state)->lexeme;
        }
        if (isAt($state, TokenKind::RParen)) {
            break;
        }
        if (isAt($state, TokenKind::Comma)) {
            advance($state);
            $afterComma = true;
            continue;
        }
        if ($exports !== [] && !$afterComma) {
            throw parseError($state, 'expected `,` between export items', peek($state));
        }
        $afterComma = false;

        $exports[] = parseModuleExportItem($state, $currentSection);
    }

    expect($state, TokenKind::RParen);

    return $exports;
}

/**
 * @return array{tag: string, name: string, path?: list<string>, children?: array{mode: string, names: list<string>}, section?: string}
 */
function parseModuleExportItem(ParserState $state, ?string $section = null): array
{
    $start = peek($state);

    if (isAt($state, TokenKind::KwModule)) {
        advance($state);
        $path = parseModulePath($state);
        $item = ['tag' => 'module', 'name' => moduleName($path), 'path' => $path];
        if ($section !== null) {
            $item['section'] = $section;
        }

        return exportItemWithSpan($item, $start);
    }

    if (isAt($state, TokenKind::LParen)) {
        $name = parseParenthesizedOperator($state);
        if (isTypeOperator($name)) {
            $item = ['tag' => 'type', 'name' => $name];
            if (isAt($state, TokenKind::LParen)) {
                $item['children'] = parseModuleExportChildren($state);
            }
            if ($section !== null) {
                $item['section'] = $section;
            }

            return exportItemWithSpan($item, $start);
        }

        $item = ['tag' => 'value', 'name' => $name];
        if ($section !== null) {
            $item['section'] = $section;
        }

        return exportItemWithSpan($item, $start);
    }

    if (isAt($state, TokenKind::ConId)) {
        $name = advance($state)->lexeme;
        $item = ['tag' => 'type', 'name' => $name];
        if (isAt($state, TokenKind::LParen)) {
            $item['children'] = parseModuleExportChildren($state);
        }
        if ($section !== null) {
            $item['section'] = $section;
        }

        return exportItemWithSpan($item, $start);
    }

    $item = ['tag' => 'value', 'name' => expect($state, TokenKind::VarId)->lexeme];
    if ($section !== null) {
        $item['section'] = $section;
    }

    return exportItemWithSpan($item, $start);
}

/**
 * Record where an export item was written, so a diagnostic about it can point at the name.
 *
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function exportItemWithSpan(array $item, Token $start): array
{
    $item['line'] = $start->line;
    $item['col'] = $start->col;
    $item['endCol'] = $start->col + \max(0, \strlen($start->lexeme) - 1);

    return $item;
}

/**
 * @return array{mode: string, names: list<string>}
 */
function parseModuleExportChildren(ParserState $state): array
{
    expect($state, TokenKind::LParen);
    if (isAt($state, TokenKind::RParen)) {
        advance($state);

        return ['mode' => 'none', 'names' => []];
    }

    if (isAt($state, TokenKind::Op, 0, '..')) {
        advance($state);
        expect($state, TokenKind::RParen);

        return ['mode' => 'all', 'names' => []];
    }

    $names = [parseModuleExportChildName($state)];
    while (isAt($state, TokenKind::Comma)) {
        advance($state);
        if (isAt($state, TokenKind::RParen)) {
            break;
        }

        $names[] = parseModuleExportChildName($state);
    }

    expect($state, TokenKind::RParen);

    return ['mode' => 'some', 'names' => $names];
}

function parseModuleExportChildName(ParserState $state): string
{
    if (isAt($state, TokenKind::LParen)) {
        return parseParenthesizedOperator($state);
    }

    if (isAt($state, TokenKind::VarId) || isAt($state, TokenKind::ConId)) {
        return advance($state)->lexeme;
    }

    throw unexpected($state, 'export name');
}

function parseParenthesizedOperator(ParserState $state): string
{
    expect($state, TokenKind::LParen);
    $name = expect($state, TokenKind::Op)->lexeme;
    expect($state, TokenKind::RParen);

    return $name;
}

/**
 * @return ImportDecl
 */
function parseImportDecl(ParserState $state): ImportDecl
{
    expect($state, TokenKind::KwImport);
    if (isAt($state, TokenKind::KwQualified)) {
        throw parseError(
            $state,
            'use `import M qualified as Alias`, not `import qualified M`',
            peek($state),
        );
    }

    $pathStartToken = $state->tokens[$state->pos] ?? null;
    $path = parseModulePath($state);
    $lastPathToken = $state->tokens[$state->pos - 1] ?? null;
    $items = [];
    $kind = 'qualified';
    $asName = null;
    $qualifiedOnly = false;

    if (isAt($state, TokenKind::LBrace)) {
        throw unexpected($state, 'parenthesized import list `(…)`');
    } elseif (isAt($state, TokenKind::LParen)) {
        $kind = 'named';
        $items = parseImportItems($state);
    }

    if (isAt($state, TokenKind::KwQualified)) {
        advance($state);
        $qualifiedOnly = true;
    }

    if (isAt($state, TokenKind::KwAs)) {
        advance($state);
        $asName = expectImportName($state);
        if ($kind === 'qualified') {
            $kind = 'qualifiedAs';
        }
    }

    $hiding = [];
    if (isAt($state, TokenKind::KwHiding)) {
        $hiding = parseHidingList($state);
    }

    $line = 0;
    $col = 0;
    $endCol = 0;
    if ($pathStartToken !== null) {
        $line = $pathStartToken->line;
        $col = $pathStartToken->col;
        $endToken = $lastPathToken ?? $pathStartToken;
        $endCol = $endToken->col + max(0, strlen($endToken->lexeme) - 1);
    }

    return new ImportDecl(
        $path,
        $kind,
        $items,
        $hiding,
        $asName,
        false,
        $qualifiedOnly,
        null,
        $line,
        $col,
        $endCol,
    );
}

/**
 * @return array{0: list<string>, 1: bool}
 */
function parseModulePath(ParserState $state): array
{
    $path = [expectModuleSegment($state)];

    while (isAt($state, TokenKind::Op, 0, '.')) {
        advance($state);
        $path[] = expectModuleSegment($state);
    }

    return $path;
}

function expectModuleSegment(ParserState $state): string
{
    if (isAt($state, TokenKind::ConId)) {
        return advance($state)->lexeme;
    }

    if (isAt($state, TokenKind::VarId)) {
        $token = peek($state);

        throw parseError(
            $state,
            "module name segments must start with an uppercase letter; found `{$token->lexeme}`",
            $token,
        );
    }

    throw unexpected($state, 'module name');
}

/**
 * @return list<array{name: string, asName: ?string}>
 */
function parseImportItems(ParserState $state): array
{
    expect($state, TokenKind::LParen);
    $items = [];

    if (!isAt($state, TokenKind::RParen)) {
        $items[] = parseImportItem($state);
        while (isAt($state, TokenKind::Comma)) {
            advance($state);
            $items[] = parseImportItem($state);
        }
    }

    expect($state, TokenKind::RParen);

    return $items;
}

function parseImportItem(ParserState $state): Ast\ImportItem
{
    // A name or a parenthesized operator, the way `C(…)` names methods.
    $name = parseImportChildName($state);
    $asName = null;
    $methods = null;

    // `C(..)` or `C(a, b)`: the methods of a class to bring along.
    if (isAt($state, TokenKind::LParen)) {
        advance($state);
        if (isAt($state, TokenKind::Op, 0, '..')) {
            advance($state);
            $methods = 'all';
        } else {
            $methods = [];
            if (!isAt($state, TokenKind::RParen)) {
                $methods[] = parseImportChildName($state);
                while (isAt($state, TokenKind::Comma)) {
                    advance($state);
                    $methods[] = parseImportChildName($state);
                }
            }
        }
        expect($state, TokenKind::RParen);
    }

    if (isAt($state, TokenKind::KwAs)) {
        advance($state);
        $asName = expectImportName($state);
    }

    return new Ast\ImportItem($name, $asName, methods: $methods);
}

/**
 * @return list<string>
 */
/**
 * A child name inside `C(…)`: a plain name, or a parenthesized operator as in
 * `List((:), Nil)`.
 */
function parseImportChildName(ParserState $state): string
{
    if (!isAt($state, TokenKind::LParen)) {
        return expectImportName($state);
    }

    advance($state);
    $token = expect($state, TokenKind::Op);
    expect($state, TokenKind::RParen);

    return $token->lexeme;
}

function parseHidingList(ParserState $state): array
{
    expect($state, TokenKind::KwHiding);
    expect($state, TokenKind::LParen);
    $names = [expectImportName($state)];

    while (isAt($state, TokenKind::Comma)) {
        advance($state);
        $names[] = expectImportName($state);
    }

    expect($state, TokenKind::RParen);

    return $names;
}

function expectImportName(ParserState $state): string
{
    if (isAt($state, TokenKind::VarId) || isAt($state, TokenKind::ConId)) {
        return advance($state)->lexeme;
    }

    throw unexpected($state, 'import name');
}

/**
 * @param list<Ast\AstNode> $priorItems
 */
function lateImportError(ParserState $state, array $priorItems): ParseError
{
    $message = 'all `import` declarations must appear at the top of the module, before `backend` declarations and other top-level items (foreign, data, type, function, etc.)';
    if ($priorItems !== []) {
        $prior = describeTopLevelDecl($priorItems[\count($priorItems) - 1]);
        $message = 'all `import` declarations must appear at the top of the module, before `backend` declarations and other top-level items (found `import` after ' . $prior . ')';
    }

    return parseError($state, $message, peek($state), 'parse/late-import');
}

function describeTopLevelDecl(Ast\AstNode $item): string
{
    return match (true) {
        $item instanceof Ast\ForeignTypeDecl => "foreign type declaration `{$item->name}`",
        $item instanceof Ast\ForeignImportDecl => "foreign {$item->kind} declaration `{$item->name}`",
        $item instanceof Ast\DataDecl => ($item->isNewtype ? 'newtype' : 'data') . " declaration `{$item->name}`",
        $item instanceof Ast\TypeSynonymDecl => "type synonym `{$item->name}`",
        $item instanceof Ast\FunctionDecl => $item->signatureOnly
            ? "type signature for `{$item->name}`"
            : "function declaration `{$item->name}`",
        $item instanceof Ast\ClassDecl => "class declaration `{$item->name}`",
        $item instanceof Ast\InstanceDecl => 'instance declaration',
        default => 'another top-level declaration',
    };
}
