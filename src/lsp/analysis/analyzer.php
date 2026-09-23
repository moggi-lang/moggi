<?php declare(strict_types=1);

namespace Moggi\LSP\Analysis;

use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Lexer\TokenKind;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Backend\setCompileBackend;
use function Moggi\Docs\declarationTypeSignature;
use function Moggi\LSP\Protocol\locToRange;
use function Moggi\LSP\Protocol\uriToPath;
use function Moggi\Modules\moduleFileClosureCached;
use function Moggi\Modules\prepareProjectCached;
use function Moggi\Modules\setStdlibLibPath;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

class AnalysisResult
{
    /**
     * @param list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, severity: int, message: string, source: string, code?: string, data?: array<string, mixed>}> $diagnostics
     * @param list<array{name: string, kind: int, detail?: string, location: array{uri: string, range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}}}> $symbols
     * @param array<string, array{line: int, col: int, endCol: int, type: string, name: string, kind: int, doc?: string, completionKind: int}> $declarations
     * @param object $program
     * @param array<string, ?string> $imports
     * @param string $source
     * @param string $uri
     */
    public function __construct(
        public readonly array $diagnostics,
        public readonly array $symbols,
        public readonly array $declarations,
        public readonly object $program,
        public readonly array $imports,
        public readonly string $source,
        public readonly string $uri,
    ) {
    }
}
/**
 * Analyze a .mog source file: parse, typecheck, collect diagnostics, symbols,
 * and declaration positions. Unsaved buffers are overlaid onto the real path
 * for the duration of analysis so module/import resolution stays correct.
 *
 * @param list<string> $libDirs
 */
function analyze(string $uri, string $content, array $libDirs = []): AnalysisResult
{
    $path = uriToPath($uri);
    $filename = $path;
    if ($path === '' || str_contains($path, '://')) {
        // untitled: or unknown scheme — fall back to a temp file named after the module.
        $tmpTokens = lex($content, $uri);
        $tmpProg = parse($tmpTokens, $content, $uri);
        $moduleName = $tmpProg->module ?? 'Main';
        $tmpDir = sys_get_temp_dir() . '/moggi-lsp/' . md5($uri);
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Cannot create temporary directory for LSP analysis');
        }
        $filename = $tmpDir . '/' . $moduleName . '.mog';
    }

    foreach ($libDirs as $dir) {
        try {
            setStdlibLibPath($dir);
        } catch (\Throwable) {
            // Ignore invalid extra lib dirs; keep analyzing with discovery defaults.
        }
    }

    setCompileBackend('php');

    return withOverlayFile($filename, $content, static function (string $filename) use ($uri, $content): AnalysisResult {
        $diagnostics = [];
        $parsedProgram = null;
        $checkedProgram = null;
        $source = $content;

        try {
            $tokens = lex($source, $filename);
            $parsedProgram = parse($tokens, $source, $filename);
        } catch (LexError|ParseError $e) {
            $diagnostics[] = errorToDiagnostic($e, $uri, $source);
            return new AnalysisResult(
                $diagnostics,
                [],
                [],
                new Ast\Program([]),
                [],
                $source,
                $uri,
            );
        }

        try {
            [$files, $root] = moduleFileClosureCached($filename);
            $prepared = prepareProjectCached($files, $root, $parsedProgram->module ?? null);
            if ($parsedProgram->module !== null && isset($prepared->checked[$parsedProgram->module])) {
                $checkedProgram = $prepared->checked[$parsedProgram->module];
            } elseif (isset($prepared->checked['Main'])) {
                $checkedProgram = $prepared->checked['Main'];
            }
        } catch (TypeError|LexError|ParseError $e) {
            $diagnostics[] = errorToDiagnostic($e, $uri, $source);
        } catch (\Throwable $e) {
            $diagnostics[] = [
                'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
                'severity' => 1,
                'message' => $e->getMessage(),
                'source' => 'moggi',
                'code' => 'internal',
            ];
        }

        $declarations = locateTopLevelDecls($parsedProgram, $source, $uri, $checkedProgram);
        $symbols = buildDocumentSymbols($declarations, $uri, $source);

        // Unused-import hints (Unnecessary tag) come from the parsed program,
        // cross-checked against the project's checked declaration names.
        foreach (unusedImportDiagnostics($parsedProgram, $uri, $prepared ?? null) as $unusedDiag) {
            $diagnostics[] = $unusedDiag;
        }

        $imports = [];
        foreach ($parsedProgram->imports as $imp) {
            $imports[implode('.', $imp->path)] = $imp->asName ?? null;
        }

        return new AnalysisResult(
            $diagnostics,
            $symbols,
            $declarations,
            $checkedProgram ?? $parsedProgram,
            $imports,
            $source,
            $uri,
        );
    });
}
/**
 * Write $content over $path for the duration of $fn, then restore.
 *
 * @template T
 * @param callable(string): T $fn
 * @return T
 */
function withOverlayFile(string $path, string $content, callable $fn): mixed
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new \RuntimeException("Cannot create directory for LSP overlay: $dir");
    }

    $existed = is_file($path);
    $previous = $existed ? file_get_contents($path) : null;
    if ($previous === false) {
        $previous = null;
        $existed = false;
    }

    if (file_put_contents($path, $content) === false) {
        throw new \RuntimeException("Cannot write LSP overlay: $path");
    }

    try {
        return $fn($path);
    } finally {
        if ($existed && $previous !== null) {
            file_put_contents($path, $previous);
        } elseif (!$existed && is_file($path)) {
            @unlink($path);
        }
    }
}
/**
 * Locate top-level declarations in source by scanning tokens.
 *
 * @return array<string, array{line: int, col: int, endCol: int, type: string, name: string, kind: int, doc?: string, completionKind: int}>
 */
function locateTopLevelDecls(Ast\Program $program, string $source, string $uri, ?Ast\Program $checked): array
{
    $result = [];
    try {
        $tokens = lex($source, $uri);
    } catch (LexError) {
        return $result;
    }

    // SymbolKind: File=1 … Function=12, Variable=13, Constant=14, …
    // CompletionItemKind: Text=1 … Function=3, … Class=7, … Struct=22, …
    $i = 0;
    $count = count($tokens);
    while ($i < $count) {
        $tok = $tokens[$i];
        $kind = $tok->kind;
        // Only treat left-aligned tokens as top-level declarations. Nested
        // binders / calls (`x =`, `pure ()`) must not enter the outline.
        $topLevel = $tok->col === 1;

        if ($topLevel && $kind === TokenKind::VarId) {
            $name = $tok->lexeme;
            $next = ($i + 1 < $count) ? $tokens[$i + 1] : null;
            if ($next !== null && $next->kind === TokenKind::Op && $next->lexeme === '::') {
                $result[$name] = [
                    'name' => $name,
                    'line' => $tok->line,
                    'col' => $tok->col,
                    'endCol' => $tok->col + max(1, mb_strlen($tok->lexeme)) - 1,
                    'kind' => 12, // SymbolKind.Function
                    'completionKind' => 3, // CompletionItemKind.Function
                    'type' => 'function',
                ];
                $i += 2;
                continue;
            }
            if ($next !== null && (
                $next->kind === TokenKind::VarId
                || $next->kind === TokenKind::ConId
                || $next->kind === TokenKind::LParen
                || $next->kind === TokenKind::Backslash
                || ($next->kind === TokenKind::Op && $next->lexeme === '=')
            )) {
                if (!isset($result[$name])) {
                    $result[$name] = [
                        'name' => $name,
                        'line' => $tok->line,
                        'col' => $tok->col,
                        'endCol' => $tok->col + max(1, mb_strlen($tok->lexeme)) - 1,
                        'kind' => 12,
                        'completionKind' => 3,
                        'type' => 'function',
                    ];
                }
                $i++;
                continue;
            }
        }

        if ($topLevel && ($kind === TokenKind::KwData || $kind === TokenKind::KwNewtype)) {
            $next = ($i + 1 < $count) ? $tokens[$i + 1] : null;
            if ($next !== null && $next->kind === TokenKind::ConId) {
                $name = $next->lexeme;
                $result[$name] = [
                    'name' => $name,
                    'line' => $next->line,
                    'col' => $next->col,
                    'endCol' => $next->col + max(1, mb_strlen($next->lexeme)) - 1,
                    'kind' => 23, // SymbolKind.Struct
                    'completionKind' => 22, // CompletionItemKind.Struct
                    'type' => $kind === TokenKind::KwNewtype ? 'newtype' : 'data',
                ];
                $i += 2;
                continue;
            }
        }

        if ($topLevel && $kind === TokenKind::KwType) {
            $next = ($i + 1 < $count) ? $tokens[$i + 1] : null;
            if ($next !== null && $next->kind === TokenKind::ConId) {
                $next2 = ($i + 2 < $count) ? $tokens[$i + 2] : null;
                if ($next2 !== null && $next2->kind === TokenKind::Op && $next2->lexeme === '=') {
                    $name = $next->lexeme;
                    $result[$name] = [
                        'name' => $name,
                        'line' => $next->line,
                        'col' => $next->col,
                        'endCol' => $next->col + max(1, mb_strlen($next->lexeme)) - 1,
                        'kind' => 5,
                        'completionKind' => 25,
                        'type' => 'type',
                    ];
                    $i += 3;
                    continue;
                }
            }
        }

        if ($topLevel && $kind === TokenKind::KwClass) {
            $next = ($i + 1 < $count) ? $tokens[$i + 1] : null;
            if ($next !== null && $next->kind === TokenKind::ConId) {
                $name = $next->lexeme;
                $result[$name] = [
                    'name' => $name,
                    'line' => $next->line,
                    'col' => $next->col,
                    'endCol' => $next->col + max(1, mb_strlen($next->lexeme)) - 1,
                    'kind' => 11,
                    'completionKind' => 7,
                    'type' => 'class',
                ];
                $i += 2;
                continue;
            }
        }

        // `pub` then a declaration — treat the following name as top-level even
        // when it is indented past column 1.
        if ($topLevel && $kind === TokenKind::KwPub) {
            $j = $i + 1;
            while ($j < $count && $tokens[$j]->kind === TokenKind::KwAbstract) {
                $j++;
            }
            if ($j < $count) {
                $follow = $tokens[$j];
                if ($follow->kind === TokenKind::VarId) {
                    $name = $follow->lexeme;
                    $next = ($j + 1 < $count) ? $tokens[$j + 1] : null;
                    if ($next !== null && $next->kind === TokenKind::Op && $next->lexeme === '::') {
                        $result[$name] = [
                            'name' => $name,
                            'line' => $follow->line,
                            'col' => $follow->col,
                            'endCol' => $follow->col + max(1, mb_strlen($follow->lexeme)) - 1,
                            'kind' => 12,
                            'completionKind' => 3,
                            'type' => 'function',
                        ];
                    } elseif ($next !== null && (
                        $next->kind === TokenKind::VarId
                        || $next->kind === TokenKind::ConId
                        || $next->kind === TokenKind::LParen
                        || $next->kind === TokenKind::Backslash
                        || ($next->kind === TokenKind::Op && $next->lexeme === '=')
                    )) {
                        $result[$name] = [
                            'name' => $name,
                            'line' => $follow->line,
                            'col' => $follow->col,
                            'endCol' => $follow->col + max(1, mb_strlen($follow->lexeme)) - 1,
                            'kind' => 12,
                            'completionKind' => 3,
                            'type' => 'function',
                        ];
                    }
                } elseif ($follow->kind === TokenKind::KwData || $follow->kind === TokenKind::KwNewtype
                    || $follow->kind === TokenKind::KwType || $follow->kind === TokenKind::KwClass) {
                    // Fall through: rewrite current token scan from the keyword.
                    $i = $j;
                    continue;
                }
            }
            $i++;
            continue;
        }

        $i++;
    }

    // Attach doc comments + surface types from the checked / parsed program.
    $prog = $checked ?? $program;
    foreach ($prog->items as $item) {
        if ($item instanceof Ast\FunctionDecl) {
            $name = $item->name;
            if (!isset($result[$name])) {
                continue;
            }
            $typeStr = declarationTypeSignature($item);
            if ($typeStr !== null) {
                $result[$name]['type'] = $typeStr;
            }
            if ($item->doc !== null && $item->doc !== '') {
                $result[$name]['doc'] = $item->doc;
            }
        } elseif ($item instanceof Ast\DataDecl || $item instanceof Ast\TypeSynonymDecl || $item instanceof Ast\ClassDecl) {
            $name = $item->name;
            if (!isset($result[$name])) {
                continue;
            }
            if ($item->doc !== null && $item->doc !== '') {
                $result[$name]['doc'] = $item->doc;
            }
        }
    }

    return $result;
}
/**
 * @param array<string, array{line: int, col: int, endCol: int, type: string, name: string, kind: int, doc?: string, completionKind: int}> $declarations
 * @return list<array{name: string, kind: int, detail?: string, location: array{uri: string, range: array}}>
 */
function buildDocumentSymbols(array $declarations, string $uri, ?string $source = null): array
{
    $symbols = [];
    foreach ($declarations as $name => $info) {
        $entry = [
            'name' => $name,
            'kind' => $info['kind'],
            'location' => [
                'uri' => $uri,
                'range' => locToRange($info['line'], $info['col'], $info['endCol'], $source),
            ],
        ];
        if (($info['type'] ?? '') !== '' && ($info['type'] ?? '') !== 'function') {
            $entry['detail'] = $info['type'];
        } elseif (isset($info['type']) && str_contains((string) $info['type'], '->')) {
            $entry['detail'] = $info['type'];
        }
        $symbols[] = $entry;
    }
    return $symbols;
}
/**
 * Find the deepest AST node whose source span contains (line, col).
 *
 * AstNode only stores line/col/endCol (typically one line). Parents with
 * children are always descended so multi-line expressions still resolve.
 *
 * @param object $ast
 * @return object|null
 */
function findNodeAt($ast, int $line, int $col, array &$seen = []): ?object
{
    $chain = findNodeChainAt($ast, $line, $col, $seen);
    return $chain === [] ? null : $chain[array_key_last($chain)];
}
/**
 * Covering AST chain from outermost positioned node to innermost (inclusive).
 *
 * @param object $ast
 * @return list<object>
 */
function findNodeChainAt($ast, int $line, int $col, array &$seen = []): array
{
    if (!is_object($ast)) {
        return [];
    }

    $oid = spl_object_id($ast);
    if (isset($seen[$oid])) {
        return [];
    }
    $seen[$oid] = true;

    $hasPosition = property_exists($ast, 'line') && (($ast->line ?? 0) !== 0);
    $startLine = $hasPosition ? (int) $ast->line : 0;
    $startCol = $hasPosition ? (int) ($ast->col ?? 1) : 1;
    $endCol = $hasPosition ? (int) ($ast->endCol ?? $startCol) : $startCol;
    $endLine = property_exists($ast, 'endLine') ? (int) ($ast->endLine ?? $startLine) : $startLine;

    $covers = true;
    if ($hasPosition) {
        // Same-line leaf/span: require column within [startCol, endCol].
        // Different line: still allow descent into children (parent spans are incomplete).
        if ($line === $startLine) {
            if ($col < $startCol || $col > $endCol) {
                $covers = false;
            }
        } elseif ($line < $startLine || $line > $endLine) {
            $covers = false;
        }
    }

    $bestChildChain = [];
    foreach (get_object_vars($ast) as $key => $prop) {
        // Type ASTs / inferredType are shared and irrelevant for selection nesting.
        if ($key === 'inferredType' || $prop instanceof Ast\TypeNode) {
            continue;
        }
        if (is_object($prop)) {
            $child = findNodeChainAt($prop, $line, $col, $seen);
            if ($child !== []) {
                $bestChildChain = $child; // last match wins (same as findNodeAt)
            }
        } elseif (is_array($prop)) {
            foreach ($prop as $elem) {
                if (is_object($elem) && !$elem instanceof Ast\TypeNode) {
                    $child = findNodeChainAt($elem, $line, $col, $seen);
                    if ($child !== []) {
                        $bestChildChain = $child;
                    }
                }
            }
        }
    }

    if (!$hasPosition) {
        return $bestChildChain;
    }
    // Parent spans are frequently only the declaration/operator token rather
    // than the full expression. A matching child proves that the positioned
    // ancestor contains the cursor even when its own same-line endCol does not.
    if ($covers || $bestChildChain !== []) {
        return [$ast, ...$bestChildChain];
    }
    return [];
}
