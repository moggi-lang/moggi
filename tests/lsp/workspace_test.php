#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$tmp = sys_get_temp_dir() . '/moggi-lsp-ws-' . bin2hex(random_bytes(4));
$assert(mkdir($tmp), 'create temp dir');
$path = $tmp . '/Main.mog';
$src1 = "module Main where\n\nf :: Int\nf = 1\n";
$src2 = "module Main where\n\nf :: Int\nf = 2\n";
$assert(file_put_contents($path, $src1) !== false, 'write Main.mog');
$uri = pathToUri($path);

$svc = new AnalysisService();
$svc->setWorkspaceRoot($tmp);
$svc->openDocument($uri, $src1, 1);

$dirty = $svc->analyzeDirty();
$assert(isset($dirty[$uri]), 'open schedules analysis');
$assert($svc->ensureAnalyzed($uri) !== null, 'analysis cached');
$assert(($svc->ensureAnalyzed($uri)?->declarations['f']['type'] ?? '') !== '', 'f declared');

$svc->changeDocument($uri, [['text' => $src2]], 2);
$again = $svc->ensureAnalyzed($uri);
$assert($again !== null && $again->source === $src2, 'ensureAnalyzed refreshes after change');
$assert(($again->declarations['f']['type'] ?? '') !== '', 'f still declared after edit');

$svc->saveDocument($uri);
$svc->closeDocument($uri);
$assert($svc->vfs->has($uri) === false, 'close removes document');
$assert(!isset($svc->results[$uri]), 'close clears cached analysis');

@unlink($path);
@rmdir($tmp);

echo "lsp workspace tests passed\n";
