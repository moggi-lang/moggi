#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Formatter\formatMoggiSource;
use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcSemanticTokens;
use function Moggi\LSP\TextDocument\svcSemanticTokensFullDelta;
use function Moggi\LSP\Index\binderResolved;
use function Moggi\LSP\Index\isBinderResolved;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$assert(binderResolved('Main', 3) === 'binder:Main:3', 'module-scoped binder key');
$assert(isBinderResolved('binder:Lib:1'), 'is binder');
$assert(!isBinderResolved('Lib::answer'), 'not binder');

$src = <<<'MOG'
module Tok where

-- | docs
data Colour = Red | Green

paint :: Colour -> Int
paint c = case c of
  Red -> 1
  Green -> 2
MOG;

$tmp = sys_get_temp_dir() . '/moggi-lsp-tok-' . bin2hex(random_bytes(3));
mkdir($tmp);
$path = $tmp . '/Tok.mog';
file_put_contents($path, $src);
$uri = pathToUri($path);
$svc = new AnalysisService();
$svc->setWorkspaceRoot($tmp);
$svc->openDocument($uri, $src, 1);
$svc->ensureAnalyzed($uri);

$full = svcSemanticTokens($svc, $uri);
$assert($full !== null && ($full['data'] ?? []) !== [], 'full tokens');
$resultId = (string) ($full['resultId'] ?? '1');

$delta = svcSemanticTokensFullDelta($svc, $uri, ['previousResultId' => $resultId]);
$assert($delta !== null, 'delta response');
$assert(isset($delta['edits']) || isset($delta['data']), 'delta has edits or data');

$formatted = formatMoggiSource($src);
$assert(str_contains($formatted, 'data Colour'), 'format data');
$assert(str_contains($formatted, 'paint'), 'format paint');

@unlink($path);
@rmdir($tmp);

echo "lsp tokens/format tests passed\n";
