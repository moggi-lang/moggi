#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\LSP\applyContentChanges;
use function Moggi\LSP\applyFullContentChanges;
use function Moggi\LSP\Protocol\codepointColToUtf16;
use function Moggi\LSP\Protocol\compilerToLsp;
use function Moggi\LSP\Protocol\locToRange;
use function Moggi\LSP\Protocol\compilerPosToLsp;
use function Moggi\LSP\Protocol\lspPosToCompiler;
use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\Protocol\readMessage;
use function Moggi\LSP\Protocol\readMessageOrTimeout;
use function Moggi\LSP\Protocol\utf16Length;
use function Moggi\LSP\Protocol\uriToPath;
use function Moggi\LSP\Protocol\writeMessage;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$assert(lspPosToCompiler('', ['line' => 0, 'character' => 0]) === ['line' => 1, 'col' => 1], 'lspPosToCompiler origin');
$assert(compilerToLsp(1, 1) === ['line' => 0, 'character' => 0], 'compilerToLsp origin');
$range = locToRange(2, 3, 5);
$assert($range['start']['line'] === 1 && $range['end']['character'] === 5, 'locToRange end exclusive');

// UTF-16 position encoding (3.17+): codepoint cols must convert when source is given.
$astr = "a\u{1F609}b c"; // a 🙂 b c — 🙂 is 1 codepoint / 2 UTF-16 units
$u16 = locToRange(1, 2, 3, $astr);
$assert($u16['start']['character'] === 1, 'locToRange source start before astral char');
$assert($u16['end']['character'] === 4, 'locToRange source end counts UTF-16 units');
$assert(codepointColToUtf16($astr, 3) === 3, 'codepointColToUtf16 astral → 2 units');
$assert(utf16Length("\u{1F609}") === 2, 'utf16Length astral = 2 units');
$plain = locToRange(3, 2, 4);
$assert($plain['start']['line'] === 2 && $plain['end']['character'] === 4, 'locToRange passthrough without source');

// Incremental changes must preserve the document's dominant EOL (3.18 EOL set).
$crlf = "line1\r\nline2\r\nline3";
$edited = applyContentChanges($crlf, [[
    'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 5]],
    'text' => 'TWO',
]]);
$assert($edited === "line1\r\nTWO\r\nline3", 'CRLF preserved by ranged edit: ' . json_encode($edited));
$cr = "a\rb\rc";
$editedCr = applyContentChanges($cr, [[
    'range' => ['start' => ['line' => 0, 'character' => 1], 'end' => ['line' => 0, 'character' => 1]],
    'text' => 'X',
]]);
$assert($editedCr === "aX\rb\rc", 'CR preserved by ranged edit: ' . json_encode($editedCr));

// Malformed frames: report and continue, do not die.
$goodBody = json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'x']);
$bad = fopen('php://memory', 'r+');
fwrite($bad, "Content-Length: 5\r\n\r\n{oops");
fwrite($bad, "Content-Length: " . strlen((string) $goodBody) . "\r\n\r\n" . $goodBody);
rewind($bad);
$malformed = false;
$badMsg = readMessage($bad, $malformed);
$assert($badMsg === false && $malformed === true, 'invalid JSON body → false + malformed flag');
$good = readMessage($bad);
$assert(\is_array($good) && ($good['id'] ?? null) === 7, 'stream resyncs after bad body');
fclose($bad);

$noLen = fopen('php://memory', 'r+');
fwrite($noLen, "garbage\r\n\r\nnext-frame-would-follow");
rewind($noLen);
$malformed2 = false;
$noLenMsg = readMessage($noLen, $malformed2);
$assert($noLenMsg === false && $malformed2 === true, 'missing Content-Length → false + malformed flag');
fclose($noLen);

$eofAfterHeader = fopen('php://memory', 'r+');
fwrite($eofAfterHeader, "Content-Length: 10\r\n");
rewind($eofAfterHeader);
$assert(readMessage($eofAfterHeader) === false, 'EOF mid-frame → malformed partial frame');
$assert(readMessage($eofAfterHeader) === null, 'EOF afterwards → clean EOF');
fclose($eofAfterHeader);

$uri = pathToUri('/tmp/moggi-lsp-test/Foo.mog');
$assert(str_starts_with($uri, 'file://'), 'pathToUri scheme');
$assert(!str_contains($uri, '%2F'), 'pathToUri must not encode slashes');
$assert(str_ends_with(uriToPath($uri), '/Foo.mog') || str_ends_with(uriToPath($uri), '\\Foo.mog'), 'uriToPath round-trip basename');

$assert(uriToPath('file:///C%3A/opt/Foo.mog') === 'C:/opt/Foo.mog', 'uriToPath drops the URI slash before an encoded drive');
$assert(uriToPath('file:///C:/opt/Foo.mog') === 'C:/opt/Foo.mog', 'uriToPath drops the URI slash before a bare drive');
$assert(uriToPath('file:///C:') === 'C:', 'uriToPath keeps a bare drive alone');
$assert(uriToPath('file:///opt/Foo.mog') === '/opt/Foo.mog', 'uriToPath keeps a POSIX root');

$assert(applyFullContentChanges('old', [['text' => 'new']]) === 'new', 'full sync replace');
$assert(applyFullContentChanges('old', [['range' => [], 'text' => 'patched'], ['text' => 'final']]) === 'final', 'last change wins');
$assert(applyFullContentChanges('old', []) === 'old', 'empty changes keep previous');

// UTF-16 position handling with non-BMP characters
$nonBmpSource = "a" . json_decode("\"\u{1F600}\"") . "b" . json_decode("\"\u{1F601}\"") . "c"; // 😀 and 😁 between a, b, c
$nonBmpLine = $nonBmpSource;

// UTF-16 uses 2 units per emoji, so positions are:
// UTF-16 0=a, 1-2=first emoji, 3=b, 4-5=second emoji, 6=c
$pos1 = lspPosToCompiler($nonBmpLine, ['line' => 0, 'character' => 0]);
$assert($pos1['line'] === 1 && $pos1['col'] === 1, 'UTF-16 pos 0 → codepoint 1 (a)');
$pos2 = lspPosToCompiler($nonBmpLine, ['line' => 0, 'character' => 1]);
$assert($pos2['line'] === 1 && $pos2['col'] === 2, 'UTF-16 pos 1 → codepoint 2 (start of first emoji)');
$pos3 = lspPosToCompiler($nonBmpLine, ['line' => 0, 'character' => 3]);
$assert($pos3['line'] === 1 && $pos3['col'] === 3, 'UTF-16 pos 3 → codepoint 3 (b, after first emoji)');
$pos4 = lspPosToCompiler($nonBmpLine, ['line' => 0, 'character' => 5]);
$assert($pos4['line'] === 1 && $pos4['col'] === 4, 'UTF-16 pos 5 → codepoint 4 (start of second emoji)');
$pos5 = lspPosToCompiler($nonBmpLine, ['line' => 0, 'character' => 6]);
$assert($pos5['line'] === 1 && $pos5['col'] === 5, 'UTF-16 pos 6 → codepoint 5 (c, after second emoji)');

// Test round-trip: compiler → LSP → compiler
$rt1 = compilerPosToLsp($nonBmpLine, 1, 1);
$assert($rt1['line'] === 0 && $rt1['character'] === 0, 'codepoint (1,1) → UTF-16 (0,0) [a]');
$rt2 = compilerPosToLsp($nonBmpLine, 1, 2);
$assert($rt2['line'] === 0 && $rt2['character'] === 1, 'codepoint (1,2) → UTF-16 (0,1) [start of emoji]');
$rt3 = compilerPosToLsp($nonBmpLine, 1, 3);
$assert($rt3['line'] === 0 && $rt3['character'] === 3, 'codepoint (1,3) → UTF-16 (0,3) [b]');
$rt4 = compilerPosToLsp($nonBmpLine, 1, 4);
$assert($rt4['line'] === 0 && $rt4['character'] === 4, 'codepoint (1,4) → UTF-16 (0,4) [start of second emoji]');

// Mixed ASCII/emoji line: hello + emoji + world
$mixedLine = "hello" . json_decode("\"\u{1F600}\"") . "world";
// UTF-16: 0-4=hello, 5-6=😀, 7-11=world
$mixedPos = lspPosToCompiler($mixedLine, ['line' => 0, 'character' => 5]);
$assert($mixedPos['col'] === 6, 'UTF-16 pos 5 → codepoint 6 (start of emoji, after hello)');
$mixedPos2 = lspPosToCompiler($mixedLine, ['line' => 0, 'character' => 7]);
$assert($mixedPos2['col'] === 7, 'UTF-16 pos 7 → codepoint 7 (w, after emoji)');
$mixedPos3 = lspPosToCompiler($mixedLine, ['line' => 0, 'character' => 10]);
$assert($mixedPos3['col'] === 10, 'UTF-16 pos 10 → codepoint 10 (r, in world)');

$stream = fopen('php://memory', 'r+');
$assert($stream !== false, 'memory stream');
writeMessage(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]], $stream);
rewind($stream);
$decoded = readMessage($stream);
$assert(\is_array($decoded) && ($decoded['id'] ?? null) === 1, 'readMessage round-trip id');
$assert(($decoded['result']['ok'] ?? false) === true, 'readMessage round-trip body');
fclose($stream);

// Timeout path: empty stream → false (idle tick), not null EOF until closed
$idle = fopen('php://memory', 'r+');
$assert($idle !== false, 'idle stream');
$timed = readMessageOrTimeout($idle, 0.0);
$assert($timed === false, 'readMessageOrTimeout returns false on idle');
fclose($idle);

echo "lsp protocol tests passed\n";
