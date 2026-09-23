<?php declare(strict_types=1);

namespace Moggi\LSP\Index;

use Moggi\Syntax\Lexer\TokenKind;

use function Moggi\Syntax\Lexer\lex;

function declTokenMaps(string $source, string $filename): array
{
    try {
        $tokens = lex($source, $filename);
    } catch (\Throwable) {
        return ['names' => [], 'defs' => [], 'ctors' => [], 'endLines' => []];
    }

    $names = [];
    $defs = [];
    $ctors = [];
    $endLines = [];
    $count = count($tokens);
    $currentData = null;  // name of the data/newtype/type/class block being scanned
    $currentName = null;  // column-1 name that started the current top-level item

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if ($tok->col !== 1) {
            // Inside a data/newtype/type/class block: constructor names appear
            // directly after `=` or `|`. Anything else (field types like
            // `(f :: Type)`, kind annotations) is NOT a constructor.
            if ($currentData !== null && $tok->kind === TokenKind::ConId) {
                $prev = $tokens[$i - 1] ?? null;
                if ($prev !== null && $prev->kind === TokenKind::Op
                    && ($prev->lexeme === '=' || $prev->lexeme === '|')) {
                    $ctors[$tok->lexeme] ??= $tok;
                }
            }
            continue;
        }

        // A column-1 token ends the previous top-level item's span.
        if ($currentName !== null) {
            $endLines[$currentName] = max($endLines[$currentName] ?? 1, $tok->line - 1);
            $currentName = null;
        }
        $currentData = null;

        if ($tok->kind === TokenKind::VarId) {
            $currentName = $tok->lexeme;
            $names[$tok->lexeme] ??= $tok;
            // Every column-1 occurrence of a name is a defining line: the
            // type signature plus every pattern-matching equation of a
            // multi-equation function.
            $defs[$tok->lexeme][] = $tok;
            continue;
        }
        if ($tok->kind === TokenKind::KwData || $tok->kind === TokenKind::KwNewtype
            || $tok->kind === TokenKind::KwType || $tok->kind === TokenKind::KwClass) {
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && $next->kind === TokenKind::ConId) {
                $currentName = $next->lexeme;
                $currentData = $next->lexeme;
                $names[$next->lexeme] ??= $next;
                $defs[$next->lexeme][] = $next;
                $i++;
            }
            continue;
        }
        if ($tok->kind === TokenKind::KwPub) {
            // `pub` [abstract…] name — register the declared name; its token
            // is indented, but positions are still correct for ranges.
            $j = $i + 1;
            while ($j < $count && $tokens[$j]->kind === TokenKind::KwAbstract) {
                $j++;
            }
            $follow = $tokens[$j] ?? null;
            if ($follow !== null && ($follow->kind === TokenKind::VarId || $follow->kind === TokenKind::ConId)) {
                $currentName = $follow->lexeme;
                $names[$follow->lexeme] ??= $follow;
                $defs[$follow->lexeme][] = $follow;
                $i = $j;
            }
            continue;
        }
        // `module`, `import`, `backend`, comments, … — not declarations.
    }
    if ($currentName !== null) {
        $endLines[$currentName] = max($endLines[$currentName] ?? 1, substr_count($source, "\n") + 1);
    }

    // Trim trailing blank lines from every span.
    $lines = $source === '' ? [''] : explode("\n", $source);
    foreach ($endLines as $name => $end) {
        $start = $names[$name]->line ?? $end;
        while ($end > $start && trim($lines[$end - 1] ?? '') === '') {
            $end--;
        }
        $endLines[$name] = $end;
    }

    return ['names' => $names, 'defs' => $defs, 'ctors' => $ctors, 'endLines' => $endLines];
}

/**
 * LSP name range for a token (0-based, end-exclusive).
 *
 * @return array{start: array{line:int,character:int}, end: array{line:int,character:int}}
 */
function tokenNameRange(object $tok): array
{
    return [
        'start' => ['line' => $tok->line - 1, 'character' => $tok->col - 1],
        'end' => ['line' => $tok->line - 1, 'character' => $tok->col - 1 + max(1, mb_strlen($tok->lexeme))],
    ];
}

/**
 * The parser does not assign source positions to top-level declarations, so
 * ranges derived from AST nodes can be missing (line 0) or cover only the
 * body. Re-derive them from the token stream: the selection range is the
 * declaration name; the full range spans the declaration block.
 *
 * @param list<DeclInfo> $decls
 */
function fixDeclRangesByTokenScan(string $uri, string $source, array $decls): void
{
    $maps = declTokenMaps($source, $uri);
    $names = $maps['names'];
    $ctors = $maps['ctors'];
    $endLines = $maps['endLines'];
    $lines = $source === '' ? [''] : explode("\n", $source);

    foreach ($decls as $decl) {
        $nameTok = $names[$decl->name] ?? null;
        if ($nameTok === null) {
            continue;
        }
        $sel = tokenNameRange($nameTok);
        $hasInvalidSel = ($decl->selectionRange['start']['line'] ?? 0) === 0
            && ($decl->selectionRange['start']['character'] ?? 0) === 0;
        $hasInvalidRange = ($decl->range['start']['line'] ?? 0) === 0
            && ($decl->range['start']['character'] ?? 0) === 0;
        $endLine = $endLines[$decl->name] ?? $nameTok->line;
        $range = [
            'start' => $sel['start'],
            'end' => [
                'line' => $endLine - 1,
                'character' => mb_strlen($lines[$endLine - 1] ?? ''),
            ],
        ];
        if ($hasInvalidSel) {
            $decl->selectionRange = $sel;
        }
        if ($hasInvalidRange || ($decl->range['end']['line'] ?? 0) < ($decl->range['start']['line'] ?? 0)) {
            $decl->range = $range;
        }
        foreach ($decl->children as $child) {
            $cSel = $child->selectionRange;
            if (($cSel['start']['line'] ?? 0) === 0 && ($cSel['start']['character'] ?? 0) === 0) {
                $ctorTok = $ctors[$child->name] ?? null;
                $child->selectionRange = $ctorTok !== null ? tokenNameRange($ctorTok) : $sel;
                $child->range = $child->selectionRange;
            }
        }
    }
}
