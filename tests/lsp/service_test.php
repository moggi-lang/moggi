#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\VirtualFS;
use Moggi\LSP\Analysis\AnalysisService;
use Moggi\LSP\Index\OccurrenceIndex;
use Moggi\Syntax\Ast;

use function Moggi\LSP\applyContentChanges;
use function Moggi\LSP\Index\buildOccurrenceIndex;
use function Moggi\LSP\Protocol\codepointColToUtf16;
use function Moggi\LSP\Formatter\formatMoggiSource;
use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\Formatter\svcFormatDocument;
use function Moggi\LSP\Formatter\svcFormatRange;
use function Moggi\LSP\TextDocument\svcLinkedEditing;
use function Moggi\LSP\TextDocument\svcSelectionRange;
use function Moggi\LSP\TextDocument\svcWorkspaceSymbol;
use function Moggi\LSP\Protocol\utf16Length;
use function Moggi\LSP\Protocol\utf16ToCodepointCol;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$assert(utf16Length('abc') === 3, 'ascii utf16 len');
$assert(codepointColToUtf16('abc', 2) === 1, 'col2 → utf16 1');
$assert(utf16ToCodepointCol('abc', 1) === 2, 'utf16 1 → col2');

$inc = applyContentChanges("ab\ncd", [[
    'range' => [
        'start' => ['line' => 0, 'character' => 1],
        'end' => ['line' => 0, 'character' => 2],
    ],
    'text' => 'X',
]]);
$assert($inc === "aX\ncd", 'incremental middle replace: ' . json_encode($inc));

$vfs = new VirtualFS();
$vfs->open('file:///tmp/a.mog', 'module A where\n', 1);
$assert($vfs->content('file:///tmp/a.mog') === 'module A where\n', 'vfs content');
$vfs->applyChanges('file:///tmp/a.mog', [['text' => 'module A where\nx=1\n']], 2);
$assert(str_contains((string) $vfs->content('file:///tmp/a.mog'), 'x=1'), 'vfs full replace');

$fps = [];
$overlay = $vfs->materializeOverlay('file:///tmp/a.mog', '/tmp', $fps);
$assert(is_file($overlay), 'overlay written');
$fp1 = $fps['file:///tmp/a.mog'] ?? '';
$mtime1 = filemtime($overlay);
usleep(20000);
$overlay2 = $vfs->materializeOverlay('file:///tmp/a.mog', '/tmp', $fps);
$assert($overlay2 === $overlay, 'same overlay path');
$assert(($fps['file:///tmp/a.mog'] ?? '') === $fp1, 'fingerprint unchanged skips rewrite');
$assert(filemtime($overlay2) === $mtime1, 'mtime unchanged when fingerprint matches');

$formatted = formatMoggiSource("a  \n\n\n\nb\n");
$assert(!str_contains($formatted, "a  "), 'trim trailing ws');
$assert(!preg_match('/\n{4,}/', $formatted), 'collapse 3+ blank lines to at most 2');

$prog = new Ast\Program([
    new Ast\FunctionDecl('f', null, [], new Ast\Variable('f', 2, 1, 1), false, false, false, 1, 1, 1),
]);
$prog->module = 'Main';
$idx = buildOccurrenceIndex('file:///x.mog', "f = f\n", $prog, 'Main', []);
$assert($idx instanceof OccurrenceIndex, 'build occurrence index');
$assert($idx->findInUri('file:///x.mog', 'f') !== [], 'find name in uri');

$svc = new AnalysisService();
$tmp = sys_get_temp_dir() . '/moggi-lsp-svc-' . bin2hex(random_bytes(3));
mkdir($tmp);
$path = $tmp . '/Main.mog';
$src = "module Main where\n\nhello :: Int\nhello = 1\n";
file_put_contents($path, $src);
$uri = pathToUri($path);
$svc->openDocument($uri, $src, 1);
$svc->setWorkspaceRoot($tmp);
$r = $svc->ensureAnalyzed($uri);
$assert($r !== null && $r->diagnostics === [], 'service analyze clean: ' . json_encode($r->diagnostics ?? null));
$assert(isset($r->declarations['hello']), 'hello declared');
$syms = svcWorkspaceSymbol($svc, 'hel');
$assert($syms !== [], 'workspace symbol finds hello');
$fmt = svcFormatDocument($svc, $uri);
$assert(\is_array($fmt), 'format returns edits array');

// Range formatting: only intersecting top-level items (not whole-file rewrite).
$messy = "module Main where\n\nhello :: Int\nhello = 1\n\nworld :: Int\nworld=2\n";
file_put_contents($path, $messy);
$svc->changeDocument($uri, [['text' => $messy]], 3);
$svc->ensureAnalyzed($uri);
$rangeEdits = svcFormatRange($svc, $uri, [
    'start' => ['line' => 5, 'character' => 0],
    'end' => ['line' => 6, 'character' => 8],
]);
$assert($rangeEdits !== [], 'range format produces edit: ' . json_encode($rangeEdits));
$assert(count($rangeEdits) === 1, 'single range edit');
$newText = $rangeEdits[0]['newText'];
$assert(str_contains($newText, 'world'), 'range edit covers world');
$assert(!str_contains($newText, 'hello'), 'range edit does not rewrite hello: ' . $newText);
$editStart = (int) ($rangeEdits[0]['range']['start']['line'] ?? -1);
$assert($editStart >= 4, 'range edit starts near world, not file start: ' . $editStart);

// Selection range parent chain (inner lit → surrounding expr/decl)
$selSrc = "module Main where\n\nadd = 1 + 2\n";
file_put_contents($path, $selSrc);
$svc->changeDocument($uri, [['text' => $selSrc]], 5);
$svc->ensureAnalyzed($uri);
$sel = svcSelectionRange($svc, $uri, [['line' => 2, 'character' => 6]]);
$assert($sel !== [] && isset($sel[0]['range']), 'selection range present: ' . json_encode($sel));
$depth = 0;
$cur = $sel[0];
while (isset($cur['parent'])) {
    $depth++;
    $cur = $cur['parent'];
}
$assert($depth >= 1, 'selection range has parent chain depth=' . $depth);

// Binder-safe linked editing on hello (restore messy for hello decl)
file_put_contents($path, $messy);
$svc->changeDocument($uri, [['text' => $messy]], 6);
$svc->ensureAnalyzed($uri);
$link = svcLinkedEditing($svc, $uri, ['line' => 2, 'character' => 0]);
$assert($link !== null && ($link['ranges'] ?? []) !== [], 'linked editing ranges');

// Slice 1: didChange must not sync-typecheck; only schedule
$svc->changeDocument($uri, [['text' => $messy . "\n"]], 7);
$assert(isset((function () use ($svc) {
    $ref = new ReflectionClass($svc);
    $p = $ref->getProperty('pendingAnalyze');
    return $p->getValue($svc);
})()[$uri]), 'change schedules pending analyze');
$quick = $svc->quickDiagnostics($uri);
$assert(\is_array($quick), 'quick diags without full typecheck');
// ensureAnalyzed still works (forces sync)
$r2 = $svc->ensureAnalyzed($uri);
$assert($r2 !== null, 'ensureAnalyzed forces sync');

// Warm stdlib
$svc->warm();
$assert($svc->warmed === true, 'warmed flag');

@unlink($path);
@rmdir($tmp);

echo "lsp service tests passed\n";
