<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisResult;
use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;

use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Analysis\version;
use function Moggi\LSP\Protocol\codepointColToUtf16;
use function Moggi\LSP\Protocol\splitLines;
use function Moggi\LSP\Protocol\utf16Length;
use function Moggi\Syntax\Lexer\lex;

function &semanticTokenCache(): array
{
    static $cache = [];
    return $cache;
}

function svcSemanticTokens(AnalysisService $svc, string $uri): ?array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return null;
    }
    $data = buildAstSemanticTokens($analysis);
    $version = $svc->vfs->version($uri) ?? 0;
    $cache = &semanticTokenCache();
    $cache[$uri] = ['version' => $version, 'data' => $data];
    return ['data' => $data, 'resultId' => (string) $version];
}

/**
 * @param array{previousResultId?: string} $params
 */

/**
 * @param array{previousResultId?: string} $params
 */
function svcSemanticTokensFullDelta(AnalysisService $svc, string $uri, array $params): ?array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return null;
    }
    $data = buildAstSemanticTokens($analysis);
    $version = $svc->vfs->version($uri) ?? 0;
    $prevId = (string) ($params['previousResultId'] ?? '');
    $cache = &semanticTokenCache();
    $prev = $cache[$uri] ?? null;
    $cache[$uri] = ['version' => $version, 'data' => $data];
    if ($prev !== null && $prevId !== '' && (string) $prev['version'] === $prevId) {
        $edits = semanticTokenDeltaEdits($prev['data'], $data);
        return [
            'resultId' => (string) $version,
            'edits' => $edits,
        ];
    }
    // Client cache miss — full tokens
    return ['resultId' => (string) $version, 'data' => $data];
}

/**
 * Semantic tokens for a range (LSP 3.18 range-based semantic tokens).
 * Returns tokens that intersect with the requested range.
 */

/**
 * Semantic tokens for a range (LSP 3.18 range-based semantic tokens).
 * Returns tokens that intersect with the requested range.
 */
function svcSemanticTokensRange(AnalysisService $svc, string $uri, array $range): ?array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return null;
    }
    $allData = buildAstSemanticTokens($analysis);

    $tokens = dataToTokens($allData);
    $filteredTokens = filterTokensInRange($tokens, $range);
    $rangeData = tokensToRangeData($filteredTokens, $range);

    return ['data' => $rangeData];
}

/**
 * Convert flat semantic token data array to token objects with absolute positions.
 * The flat array is delta-encoded per the LSP spec: line deltas are relative to
 * the previous token's line, character deltas to the previous token's character
 * (reset to the given delta when the line advances).
 */

/**
 * Convert flat semantic token data array to token objects with absolute positions.
 * The flat array is delta-encoded per the LSP spec: line deltas are relative to
 * the previous token's line, character deltas to the previous token's character
 * (reset to the given delta when the line advances).
 */
function dataToTokens(array $data): array
{
    $tokens = [];
    $line = 0;
    $char = 0;
    $pos = 0;
    while ($pos + 4 < count($data)) {
        $deltaLine = $data[$pos];
        $deltaChar = $data[$pos + 1];
        $length = $data[$pos + 2];
        $type = $data[$pos + 3];
        $modifier = $data[$pos + 4];
        if ($deltaLine > 0) {
            $line += $deltaLine;
            $char = $deltaChar;
        } else {
            $char += $deltaChar;
        }
        $tokens[] = [
            'line' => $line,
            'startChar' => $char,
            'length' => $length,
            'type' => $type,
            'modifier' => $modifier,
            'endChar' => $char + $length,
        ];
        $pos += 5;
    }
    return $tokens;
}

function filterTokensInRange(array $tokens, array $range): array
{
    $rangeStartLine = (int) ($range['start']['line'] ?? 0);
    $rangeStartChar = (int) ($range['start']['character'] ?? 0);
    $rangeEndLine = (int) ($range['end']['line'] ?? $rangeStartLine);
    $rangeEndChar = (int) ($range['end']['character'] ?? 0);

    $filtered = [];
    foreach ($tokens as $token) {
        $tokenStartLine = $token['line'];
        $tokenStartChar = $token['startChar'];
        $tokenEndLine = $token['line']; // tokens are single-line
        $tokenEndChar = $token['endChar'];

        // Token line outside the requested line span.
        if ($tokenStartLine > $rangeEndLine || $tokenEndLine < $rangeStartLine) {
            continue;
        }

        // Token lies entirely before the range start (on the start line).
        if ($tokenStartLine === $rangeStartLine && $tokenEndChar <= $rangeStartChar) {
            continue;
        }

        // Token lies entirely after the range end (on the end line; end is exclusive).
        if ($tokenEndLine === $rangeEndLine && $tokenStartChar >= $rangeEndChar) {
            continue;
        }

        $filtered[] = $token;
    }

    return $filtered;
}

/**
 * Convert filtered tokens to range-relative delta data.
 * Positions are relative to the range start.
 */

/**
 * Convert filtered tokens to range-relative delta data.
 * Positions are relative to the range start.
 */
function tokensToRangeData(array $tokens, array $range): array
{
    if (empty($tokens)) {
        return [];
    }

    $rangeStartLine = (int) ($range['start']['line'] ?? 0);
    $rangeStartChar = (int) ($range['start']['character'] ?? 0);

    $data = [];
    $prevLine = 0;
    $prevChar = 0;

    foreach ($tokens as $token) {
        $deltaLine = $token['line'] - $rangeStartLine;
        $deltaChar = ($deltaLine === 0) ? ($token['startChar'] - $rangeStartChar) : $token['startChar'];

        $data[] = $deltaLine;
        $data[] = $deltaChar;
        $data[] = $token['length'];
        $data[] = $token['type'];
        $data[] = $token['modifier'];

        $prevLine = $deltaLine;
        $prevChar = $deltaChar;
    }

    return $data;
}

/**
 * @return list<array{start: int, deleteCount: int, data?: list<int>}>
 */

/**
 * @return list<array{start: int, deleteCount: int, data?: list<int>}>
 */
function semanticTokenDeltaEdits(array $old, array $new): array
{
    if ($old === $new) {
        return [];
    }
    // Cheap whole-replace delta (correct; avoids O(n²) LCS for large buffers).
    return [[
        'start' => 0,
        'deleteCount' => count($old),
        'data' => $new,
    ]];
}

/** @return list<int> */

/** @return list<int> */
function buildAstSemanticTokens(AnalysisResult $analysis): array
{
    $raw = []; // list of [line0, startUtf16, len, type, mod]
    $lines = splitLines($analysis->source);
    $emitAt = static function (int $line1, int $col1, string $text, int $type, int $mod = 0) use (&$raw, $lines): void {
        if ($line1 <= 0 || $text === '') {
            return;
        }
        $lineText = $lines[$line1 - 1] ?? '';
        $start = codepointColToUtf16($lineText, $col1);
        $len = utf16Length($text);
        if ($len <= 0) {
            return;
        }
        $raw[] = [$line1 - 1, $start, $len, $type, $mod];
    };

    // Lexer pass: keywords, comments, literals, operators as base layer.
    try {
        $tokens = lex($analysis->source, '');
        foreach ($tokens as $tok) {
            $type = tokenKindToSemanticType($tok->kind);
            if ($type === null) {
                continue;
            }
            // Skip identifiers — AST pass reclassifies them.
            if ($tok->kind === TokenKind::VarId || $tok->kind === TokenKind::ConId) {
                continue;
            }
            $emitAt($tok->line, $tok->col, $tok->lexeme, $type);
        }
    } catch (\Throwable) {
    }

    $walk = null;
    $walk = static function ($node) use (&$walk, $emitAt): void {
        if (!is_object($node)) {
            return;
        }
        if ($node instanceof Ast\FunctionDecl && ($node->line ?? 0) > 0) {
            $emitAt($node->line, $node->col, $node->name, 12, 0b11); // function + declaration|definition
        } elseif ($node instanceof Ast\Variable && ($node->line ?? 0) > 0) {
            $type = $node->binderId !== null ? 8 : 12; // local var vs function ref
            $emitAt($node->line, $node->col, $node->name, $type);
        } elseif ($node instanceof Ast\PatVar && ($node->line ?? 0) > 0) {
            $emitAt($node->line, $node->col, $node->name, 7, 0b1); // parameter + declaration
        } elseif ($node instanceof Ast\ConstructorRef && ($node->line ?? 0) > 0) {
            $emitAt($node->line, $node->col, $node->name, 10); // enumMember
        } elseif ($node instanceof Ast\OperatorRef && ($node->line ?? 0) > 0) {
            $emitAt($node->line, $node->col, $node->name, 21);
        } elseif ($node instanceof Ast\QualifiedRef && ($node->line ?? 0) > 0) {
            $emitAt($node->line, $node->col, $node->module, 0); // namespace
            $emitAt($node->line, $node->col + strlen($node->module) + 1, $node->name, 12);
        } elseif ($node instanceof Ast\DataDecl && ($node->line ?? 0) > 0) {
            $emitAt($node->line, $node->col, $node->name, 5, 0b11); // struct
        } elseif ($node instanceof Ast\TypeSynonymDecl && ($node->line ?? 0) > 0) {
            $emitAt($node->line, $node->col, $node->name, 1, 0b11);
        } elseif ($node instanceof Ast\ClassDecl && ($node->line ?? 0) > 0) {
            $emitAt($node->line, $node->col, $node->name, 4, 0b11); // interface
        } elseif ($node instanceof Ast\TypeCon && ($node->line ?? 0) > 0) {
            $emitAt($node->line, $node->col, $node->name, 1);
        } elseif ($node instanceof Ast\ExprHole && ($node->line ?? 0) > 0) {
            $emitAt($node->line, $node->col, '_', 22); // decorator
        }
        foreach (get_object_vars($node) as $prop) {
            if (is_object($prop)) {
                $walk($prop);
            } elseif (is_array($prop)) {
                foreach ($prop as $el) {
                    if (is_object($el)) {
                        $walk($el);
                    }
                }
            }
        }
    };
    if ($analysis->program instanceof Ast\Program) {
        $walk($analysis->program);
    }

    usort($raw, static function (array $a, array $b): int {
        return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
    });

    // Deduplicate overlapping same-start tokens (AST wins over later duplicates).
    $dedup = [];
    $seen = [];
    foreach ($raw as $t) {
        $k = $t[0] . ':' . $t[1];
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $dedup[] = $t;
    }

    $data = [];
    $prevLine = 0;
    $prevChar = 0;
    foreach ($dedup as [$line, $start, $len, $type, $mod]) {
        $deltaLine = $line - $prevLine;
        $deltaChar = ($deltaLine === 0) ? ($start - $prevChar) : $start;
        $data[] = $deltaLine;
        $data[] = $deltaChar;
        $data[] = $len;
        $data[] = $type;
        $data[] = $mod;
        $prevLine = $line;
        $prevChar = $start;
    }
    return $data;
}

/**
 * LSP semanticTokens legend used by initialize + tokenKindToSemanticType.
 *
 * @return array{tokenTypes: list<string>, tokenModifiers: list<string>}
 */
function semanticTokensLegend(): array
{
    return [
        'tokenTypes' => [
            'namespace',      // 0
            'type',           // 1
            'class',          // 2
            'enum',           // 3
            'interface',      // 4
            'struct',         // 5
            'typeParameter',  // 6
            'parameter',      // 7
            'variable',       // 8
            'property',       // 9
            'enumMember',     // 10
            'event',          // 11
            'function',       // 12
            'method',         // 13
            'macro',          // 14
            'keyword',        // 15
            'modifier',       // 16
            'comment',        // 17
            'string',         // 18
            'number',         // 19
            'regexp',         // 20
            'operator',       // 21
            'decorator',      // 22
        ],
        'tokenModifiers' => [
            'declaration', 'definition', 'readonly', 'static', 'deprecated',
            'abstract', 'async', 'modification', 'documentation', 'defaultLibrary',
        ],
    ];
}

function tokenKindToSemanticType(TokenKind $kind): ?int
{
    return match ($kind) {
        TokenKind::VarId => 8,
        TokenKind::ConId => 1,
        TokenKind::KwData, TokenKind::KwNewtype => 5,
        TokenKind::KwType => 6,
        TokenKind::KwModule => 0,
        TokenKind::KwPub, TokenKind::KwAbstract, TokenKind::KwQualified => 16,
        TokenKind::KwClass => 4,
        TokenKind::KwInstance => 2,
        TokenKind::KwImport, TokenKind::KwHiding, TokenKind::KwAs,
        TokenKind::KwLet, TokenKind::KwIn, TokenKind::KwWhere,
        TokenKind::KwCase, TokenKind::KwOf, TokenKind::KwDo,
        TokenKind::KwIf, TokenKind::KwThen, TokenKind::KwElse,
        TokenKind::KwDeriving, TokenKind::KwForeign,
        TokenKind::KwInfix, TokenKind::KwInfixl, TokenKind::KwInfixr => 15,
        TokenKind::Integer, TokenKind::Float => 19,
        TokenKind::StringLit, TokenKind::CharLit => 18,
        TokenKind::Op, TokenKind::Backslash,
        TokenKind::LParen, TokenKind::RParen,
        TokenKind::LBracket, TokenKind::RBracket,
        TokenKind::LBrace, TokenKind::RBrace,
        TokenKind::Comma, TokenKind::Semicolon, TokenKind::Pipe => 21,
        default => null,
    };
}
