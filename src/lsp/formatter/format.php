<?php declare(strict_types=1);

namespace Moggi\LSP\Formatter;

use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Ast;

use function Moggi\Docs\surfaceTypeSignature;
use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Protocol\splitLines;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

function svcFormatDocument(AnalysisService $svc, string $uri): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $formatted = formatMoggiSource($analysis->source);
    if ($formatted === $analysis->source) {
        return [];
    }
    $lines = splitLines($analysis->source);
    $lastLine = max(0, count($lines) - 1);
    $lastChar = strlen($lines[$lastLine] ?? '');
    return [[
        'range' => [
            'start' => ['line' => 0, 'character' => 0],
            'end' => ['line' => $lastLine, 'character' => $lastChar],
        ],
        'newText' => $formatted,
    ]];
}

/**
 * Format only top-level regions that intersect `$range` (not a whole-file rewrite).
 *
 * Regions are recovered from source (AST item `line` is often 0 after check).
 *
 * @param array{start?: array{line?:int,character?:int}, end?: array{line?:int,character?:int}} $range
 * @return list<array{range: array, newText: string}>
 */
function svcFormatRange(AnalysisService $svc, string $uri, array $range): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $source = $analysis->source;
    $selStart = ((int) ($range['start']['line'] ?? 0)) + 1;
    $selEnd = ((int) ($range['end']['line'] ?? 0)) + 1;
    if ($selEnd < $selStart) {
        [$selStart, $selEnd] = [$selEnd, $selStart];
    }

    $regions = topLevelSourceRegions($source);
    $picked = [];
    foreach ($regions as $region) {
        if ($region['end'] < $selStart || $region['start'] > $selEnd) {
            continue;
        }
        // Skip module / import headers for range format — only value/type decls.
        if ($region['kind'] === 'header') {
            continue;
        }
        $picked[] = $region;
    }
    if ($picked === []) {
        return [];
    }

    $spanStart = $picked[0]['start'];
    $spanEnd = $picked[array_key_last($picked)]['end'];
    $slice = sourceLinesSlice($source, $spanStart, $spanEnd);

    // Pretty-print the slice as a synthetic module body.
    $synthetic = "module RangeFmt where\n\n" . $slice;
    if (!str_ends_with($synthetic, "\n")) {
        $synthetic .= "\n";
    }
    $formattedFull = formatMoggiSource($synthetic);
    $formattedBody = preg_replace('/^module\s+RangeFmt\b.*\n+/', '', $formattedFull) ?? $formattedFull;
    $formattedBody = ltrim($formattedBody, "\n");
    $newText = rtrim($formattedBody);
    $oldText = rtrim($slice);
    if ($newText === $oldText) {
        return [];
    }

    $lines = splitLines($source);
    $startIdx = max(0, $spanStart - 1);
    $endIdx = min(count($lines) - 1, $spanEnd - 1);
    $endChar = strlen($lines[$endIdx] ?? '');

    return [[
        'range' => [
            'start' => ['line' => $startIdx, 'character' => 0],
            'end' => ['line' => $endIdx, 'character' => $endChar],
        ],
        'newText' => $newText,
    ]];
}

/**
 * Top-level source regions (1-based inclusive line spans).
 *
 * @return list<array{start: int, end: int, kind: string}>
 */
function topLevelSourceRegions(string $source): array
{
    $lines = splitLines($source);
    $n = count($lines);
    $starts = [];
    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--')) {
            continue;
        }
        $kind = null;
        if (preg_match('/^(module|import)\b/', $trim)) {
            $kind = 'header';
        } elseif (preg_match('/^(data|newtype|type|class|instance)\b/', $trim)) {
            $kind = 'decl';
        } elseif (preg_match('/^[A-Za-z_][A-Za-z0-9_\']*(\s*::|\s|=)/', $trim)) {
            $kind = 'decl';
        }
        if ($kind !== null) {
            $starts[] = ['line' => $i + 1, 'kind' => $kind, 'name' => topLevelBindingName($trim)];
        }
    }
    if ($starts === []) {
        return [];
    }

    // Merge signature + body for the same name into one region.
    $merged = [];
    $m = count($starts);
    for ($i = 0; $i < $m; $i++) {
        $cur = $starts[$i];
        $endLine = ($i + 1 < $m) ? ($starts[$i + 1]['line'] - 1) : $n;
        while (
            $i + 1 < $m
            && $cur['kind'] === 'decl'
            && $starts[$i + 1]['kind'] === 'decl'
            && $cur['name'] !== null
            && $cur['name'] === $starts[$i + 1]['name']
        ) {
            $i++;
            $endLine = ($i + 1 < $m) ? ($starts[$i + 1]['line'] - 1) : $n;
        }
        while ($endLine > $cur['line']) {
            $t = trim($lines[$endLine - 1] ?? '');
            if ($t === '') {
                $endLine--;
                continue;
            }
            break;
        }
        // Include leading `--` comments immediately above.
        $startLine = $cur['line'];
        for ($l = $startLine - 1; $l >= 1; $l--) {
            $t = $lines[$l - 1] ?? '';
            if (preg_match('/^\s*--/', $t)) {
                $startLine = $l;
                continue;
            }
            if (trim($t) === '') {
                break;
            }
            break;
        }
        $merged[] = [
            'start' => $startLine,
            'end' => max($startLine, $endLine),
            'kind' => $cur['kind'],
        ];
    }
    return $merged;
}

function topLevelBindingName(string $trim): ?string
{
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_\']*)\s*::/', $trim, $m)) {
        return $m[1];
    }
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_\']*)(\s|=)/', $trim, $m)) {
        return $m[1];
    }
    return null;
}

/** @return string */
function sourceLinesSlice(string $source, int $startLine, int $endLine): string
{
    $lines = splitLines($source);
    $slice = array_slice($lines, max(0, $startLine - 1), max(0, $endLine - $startLine + 1));
    return implode("\n", $slice) . "\n";
}

/**
 * @deprecated Prefer topLevelSourceRegions; kept for callers that still have positioned AST.
 * @param list<object> $items
 */
function topLevelItemEndLine(object $item, array $items, int $index, string $source): int
{
    $end = (int) ($item->endLine ?? 0);
    if ($end > 0) {
        $nextStart = null;
        for ($j = $index + 1; $j < count($items); $j++) {
            $nl = (int) ($items[$j]->line ?? 0);
            if ($nl > 0) {
                $nextStart = $nl;
                break;
            }
        }
        if ($nextStart !== null && $nextStart - 1 > $end) {
            $lines = splitLines($source);
            $last = $nextStart - 1;
            while ($last > $end) {
                $text = $lines[$last - 1] ?? '';
                if (trim($text) === '' || preg_match('/^\s*--/', $text)) {
                    $last--;
                    continue;
                }
                break;
            }
            return max($end, $last);
        }
        if ($nextStart === null) {
            return max($end, count(splitLines($source)));
        }
        return $end;
    }
    $start = (int) ($item->line ?? 1);
    for ($j = $index + 1; $j < count($items); $j++) {
        $nl = (int) ($items[$j]->line ?? 0);
        if ($nl > 0) {
            return max($start, $nl - 1);
        }
    }
    return count(splitLines($source));
}

function formatMoggiSource(string $source): string
{
    try {
        $tokens = lex($source, '<format>');
        $program = parse($tokens, $source, '<format>');
    } catch (\Throwable) {
        return formatMoggiSourceWhitespace($source);
    }

    $commentsByLine = collectLineComments($source);
    $parts = [];

    if ($program->module !== null && !$program->implicitMain) {
        $modLine = 1;
        foreach (splitLines($source) as $i => $line) {
            if (preg_match('/^\s*module\b/', $line)) {
                $modLine = $i + 1;
                break;
            }
        }
        $parts = array_merge($parts, commentsBefore($commentsByLine, $modLine));
        $exports = '';
        if ($program->exports !== null && $program->exports !== []) {
            $names = [];
            foreach ($program->exports as $ex) {
                if (\is_array($ex) && isset($ex['name'])) {
                    $names[] = (string) $ex['name'];
                }
            }
            if ($names !== []) {
                $exports = ' (' . implode(', ', $names) . ')';
            }
        }
        $parts[] = 'module ' . $program->module . $exports . ' where';
        $parts[] = '';
    }

    foreach ($program->imports as $imp) {
        if ($imp->implicit) {
            continue;
        }
        $path = implode('.', $imp->path);
        $line = (int) ($imp->line ?? 0);
        $parts = array_merge($parts, commentsBefore($commentsByLine, $line));
        $s = 'import ' . $path;
        if ($imp->qualifiedOnly) {
            $s .= ' qualified';
        }
        if ($imp->asName !== null) {
            $s .= ' as ' . $imp->asName;
        }
        $parts[] = $s;
    }
    if ($program->imports !== []) {
        $parts[] = '';
    }

    $prevWasSig = false;
    $prevSigName = null;
    foreach ($program->items as $item) {
        $line = (int) ($item->line ?? 0);
        $parts = array_merge($parts, commentsBefore($commentsByLine, $line));
        // When the parser emits the signature as its own item, the following
        // body item for the same name must not print the signature again.
        $skipSig = $prevWasSig
            && $item instanceof Ast\FunctionDecl
            && $item->name === $prevSigName;
        $chunk = formatDecl($item, $skipSig);
        if ($chunk === null || $chunk === '') {
            continue;
        }
        $parts[] = $chunk;
        $prevWasSig = $item instanceof Ast\FunctionDecl && $item->signatureOnly;
        $prevSigName = $prevWasSig ? $item->name : null;
        if (!$prevWasSig) {
            $parts[] = '';
        }
    }

    $text = implode("\n", $parts);
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return rtrim($text) . "\n";
}

function formatMoggiSourceWhitespace(string $source): string
{
    $lines = splitLines($source);
    $out = [];
    $blankRun = 0;
    foreach ($lines as $line) {
        $trimmed = rtrim($line);
        if ($trimmed === '') {
            $blankRun++;
            if ($blankRun <= 2) {
                $out[] = '';
            }
            continue;
        }
        $blankRun = 0;
        $out[] = $trimmed;
    }
    $text = implode("\n", $out);
    if ($text !== '' && !str_ends_with($text, "\n")) {
        $text .= "\n";
    }
    return $text;
}

/** @return array<int, list<string>> */
function collectLineComments(string $source): array
{
    $byLine = [];
    foreach (splitLines($source) as $i => $line) {
        if (preg_match('/^\s*--/', $line)) {
            $byLine[$i + 1][] = rtrim($line);
        }
    }
    return $byLine;
}

/** @param array<int, list<string>> $byLine @return list<string> */
function commentsBefore(array &$byLine, int $line): array
{
    if ($line <= 0) {
        return [];
    }
    $out = [];
    for ($l = max(1, $line - 8); $l < $line; $l++) {
        if (!isset($byLine[$l])) {
            continue;
        }
        foreach ($byLine[$l] as $c) {
            $out[] = $c;
        }
        unset($byLine[$l]);
    }
    return $out;
}

function formatDecl(object $item, bool $skipSignature = false): ?string
{
    if ($item instanceof Ast\FunctionDecl) {
        $sig = null;
        if ($item->type !== null && !$skipSignature) {
            $s = surfaceTypeSignature($item->type);
            $sig = $s !== null ? $item->name . ' :: ' . $s : null;
        }
        if ($item->signatureOnly) {
            return $sig;
        }
        $params = [];
        foreach ($item->params as $p) {
            $params[] = formatPattern($p);
        }
        $lhs = $item->name . ($params !== [] ? ' ' . implode(' ', $params) : '');
        $body = formatExpr($item->body, 0);
        $bodyChunk = str_contains($body, "\n")
            ? $lhs . " =\n  " . str_replace("\n", "\n  ", $body)
            : $lhs . ' = ' . $body;
        // The parser merges `name :: T` + `name = …` into one decl; keep the
        // signature instead of silently dropping it.
        return $sig !== null ? $sig . "\n" . $bodyChunk : $bodyChunk;
    }
    if ($item instanceof Ast\DataDecl) {
        $kw = $item->isNewtype ? 'newtype' : 'data';
        $params = [];
        foreach ($item->params as $p) {
            $params[] = $p->name;
        }
        $lhs = $kw . ' ' . $item->name . ($params !== [] ? ' ' . implode(' ', $params) : '');
        $ctors = [];
        foreach ($item->constructors as $ctor) {
            $args = [];
            foreach ($ctor->fields as $f) {
                $args[] = formatType($f->type);
            }
            $ctors[] = $ctor->name . ($args !== [] ? ' ' . implode(' ', $args) : '');
        }
        if ($ctors === []) {
            return $lhs;
        }
        if (count($ctors) === 1) {
            return $lhs . ' = ' . $ctors[0];
        }
        return $lhs . "\n  = " . implode("\n  | ", $ctors);
    }
    if ($item instanceof Ast\TypeSynonymDecl) {
        $ps = $item->params !== [] ? ' ' . implode(' ', $item->params) : '';
        return 'type ' . $item->name . $ps . ' = ' . formatType($item->type);
    }
    if ($item instanceof Ast\ClassDecl) {
        $params = [];
        foreach ($item->params as $p) {
            $params[] = $p->name;
        }
        $head = 'class ' . $item->name . ($params !== [] ? ' ' . implode(' ', $params) : '');
        $methods = [];
        foreach ($item->methods as $m) {
            $sig = surfaceTypeSignature($m->type);
            if ($sig !== null) {
                $methods[] = '  ' . $m->name . ' :: ' . $sig;
            }
        }
        return $methods === [] ? $head . ' where' : $head . " where\n" . implode("\n", $methods);
    }
    if ($item instanceof Ast\InstanceDecl) {
        $head = 'instance ' . $item->class . ' ' . formatType($item->head) . ' where';
        $methods = [];
        foreach ($item->methods as $m) {
            $chunk = formatDecl($m);
            if ($chunk !== null) {
                $methods[] = '  ' . str_replace("\n", "\n  ", $chunk);
            }
        }
        return $methods === [] ? $head : $head . "\n" . implode("\n", $methods);
    }
    return null;
}

function formatPattern(object $p): string
{
    if ($p instanceof Ast\PatVar) {
        return $p->name;
    }
    if ($p instanceof Ast\PatWild) {
        return '_';
    }
    if ($p instanceof Ast\PatLit) {
        return (string) $p->value;
    }
    if ($p instanceof Ast\PatChar) {
        return "'" . addcslashes(mb_chr($p->value) ?: '', "'\\") . "'";
    }
    if ($p instanceof Ast\PatCon) {
        $args = array_map(formatPattern(...), $p->args);
        return $p->name . ($args !== [] ? ' ' . implode(' ', $args) : '');
    }
    if ($p instanceof Ast\PatTuple) {
        return '(' . implode(', ', array_map(formatPattern(...), $p->elements)) . ')';
    }
    if ($p instanceof Ast\PatNil) {
        return '[]';
    }
    if ($p instanceof Ast\PatCons) {
        return '(' . formatPattern($p->head) . ' : ' . formatPattern($p->tail) . ')';
    }
    return '_';
}

function formatExpr(object $e, int $prec): string
{
    if ($e instanceof Ast\Variable || $e instanceof Ast\ConstructorRef || $e instanceof Ast\OperatorRef) {
        return $e->name;
    }
    if ($e instanceof Ast\QualifiedRef) {
        return $e->module . '.' . $e->name;
    }
    if ($e instanceof Ast\IntegerLit) {
        return (string) $e->value;
    }
    if ($e instanceof Ast\DoubleLit) {
        return (string) $e->value;
    }
    if ($e instanceof Ast\StringLit) {
        return json_encode($e->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }
    if ($e instanceof Ast\CharLit) {
        return "'" . addcslashes(mb_chr($e->value) ?: '', "'\\") . "'";
    }
    if ($e instanceof Ast\ExprHole) {
        return '_';
    }
    if ($e instanceof Ast\Apply) {
        $s = formatExpr($e->function, 10) . ' ' . formatExpr($e->argument, 11);
        return $prec > 10 ? '(' . $s . ')' : $s;
    }
    if ($e instanceof Ast\Infix) {
        $s = formatExpr($e->left, 1) . ' ' . $e->operator . ' ' . formatExpr($e->right, 1);
        return $prec > 0 ? '(' . $s . ')' : $s;
    }
    if ($e instanceof Ast\Lambda) {
        $params = [];
        foreach ($e->params as $lp) {
            $params[] = formatPattern($lp->pattern);
        }
        return '\\' . implode(' ', $params) . ' -> ' . formatExpr($e->body, 0);
    }
    if ($e instanceof Ast\CaseExpr) {
        $alts = [];
        foreach ($e->alts as $alt) {
            $alts[] = '  ' . formatPattern($alt->pattern) . ' -> ' . formatExpr($alt->body, 0);
        }
        return 'case ' . formatExpr($e->scrutinee, 0) . " of\n" . implode("\n", $alts);
    }
    if ($e instanceof Ast\Let) {
        $binds = [];
        foreach ($e->bindings as $b) {
            $binds[] = formatPattern($b->pattern) . ' = ' . formatExpr($b->value, 0);
        }
        return 'let ' . implode('; ', $binds) . ' in ' . formatExpr($e->body, 0);
    }
    if ($e instanceof Ast\Where) {
        $binds = [];
        foreach ($e->bindings as $b) {
            $binds[] = formatPattern($b->pattern) . ' = ' . formatExpr($b->value, 0);
        }
        return formatExpr($e->expr, 0) . "\n  where\n    " . implode("\n    ", $binds);
    }
    if ($e instanceof Ast\DoExpr) {
        $stmts = [];
        foreach ($e->stmts as $st) {
            $stmts[] = '  ' . formatDoStmt($st);
        }
        return "do\n" . implode("\n", $stmts);
    }
    if ($e instanceof Ast\Tuple) {
        return '(' . implode(', ', array_map(static fn ($x) => formatExpr($x, 0), $e->elements)) . ')';
    }
    if ($e instanceof Ast\ListLit) {
        return '[' . implode(', ', array_map(static fn ($x) => formatExpr($x, 0), $e->elements)) . ']';
    }
    if ($e instanceof Ast\TypeAsc) {
        return formatExpr($e->expr, 0) . ' :: ' . formatType($e->type);
    }
    return '…';
}

function formatDoStmt(object $st): string
{
    if ($st instanceof Ast\DoBind) {
        return formatPattern($st->pattern) . ' <- ' . formatExpr($st->expr, 0);
    }
    if ($st instanceof Ast\DoExprStmt) {
        return formatExpr($st->expr, 0);
    }
    if ($st instanceof Ast\DoLet) {
        $binds = [];
        foreach ($st->bindings as $b) {
            if ($b instanceof Ast\DoBind) {
                $binds[] = formatPattern($b->pattern) . ' = ' . formatExpr($b->expr, 0);
            }
        }
        return 'let ' . implode('; ', $binds);
    }
    return formatExpr($st, 0);
}

function formatType(object $t): string
{
    $sig = surfaceTypeSignature($t);
    if ($sig !== null) {
        return $sig;
    }
    if ($t instanceof Ast\TypeCon) {
        return $t->name;
    }
    if ($t instanceof Ast\TypeVar) {
        return $t->name;
    }
    if ($t instanceof Ast\TypeApp) {
        $args = array_map(formatType(...), $t->args);
        return formatType($t->con) . ($args !== [] ? ' ' . implode(' ', $args) : '');
    }
    if ($t instanceof Ast\TypeArrow) {
        return formatType($t->from) . ' -> ' . formatType($t->to);
    }
    return '…';
}
