#!/usr/bin/env php
<?php declare(strict_types=1);

// Regression: the server speaks JSON-RPC over stdout, so PHP's own diagnostics
// must never reach that channel. One warning used to land between the previous
// body and the next `Content-Length:` header, and the client reported
// "Header must provide a Content-Length property" before dropping the
// connection.
//
// The warning came from `AnalysisService::analyzeFile()`: it read `$prepared`
// after a failed project preparation (a parse or type error in *any* module of
// the closure, e.g. the very module the editor had open), which is the normal
// case while a file is being edited.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\configureStdioErrors;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

// 1. The server entry point sends PHP diagnostics to stderr. Assert the
//    override actually applies even when the interpreter was started with
//    `display_errors=1` (which is what put the warning on stdout).
$previous = ini_get('display_errors');
ini_set('display_errors', '1');
configureStdioErrors();
$assert(
    ini_get('display_errors') === 'stderr',
    'configureStdioErrors() must route PHP errors away from stdout, got ' . var_export(ini_get('display_errors'), true),
);
ini_set('display_errors', $previous === false ? '' : $previous);

// 2. Analyzing a file whose import closure fails to prepare must stay silent.
$dir = sys_get_temp_dir() . '/moggi-lsp-framing-' . getmypid();
@mkdir($dir, 0777, true);
$main = "module Main where\n\nimport Bad\n\nmain :: Int\nmain = 1\n";
file_put_contents($dir . '/Main.mog', $main);
file_put_contents($dir . '/Bad.mog', "module Bad where\n\nbroken = = 1\n");

$warnings = [];
set_error_handler(
    static function (int $errno, string $errstr, string $errfile, int $errline) use (&$warnings): bool {
        $warnings[] = $errstr . ' (' . $errfile . ':' . $errline . ')';

        return true;
    },
);

$service = new AnalysisService([]);
$service->setWorkspaceRoot($dir);
$uri = 'file://' . $dir . '/Main.mog';
$service->openDocument($uri, $main, 1);
$result = $service->analyzeUri($uri);
restore_error_handler();

$assert(
    $warnings === [],
    'analysis must not emit PHP warnings on the protocol channel: ' . implode('; ', $warnings),
);

// The dependency's parse error is still reported against the open file, so the
// missing project data cost no diagnostics.
$codes = [];
foreach ($result?->diagnostics ?? [] as $diagnostic) {
    $codes[] = $diagnostic['code'] ?? '?';
}
$assert(
    in_array('parse', $codes, true),
    'the dependency parse error must still be reported, got [' . implode(',', $codes) . ']',
);

@unlink($dir . '/Main.mog');
@unlink($dir . '/Bad.mog');
@rmdir($dir);

echo "lsp stdout framing tests passed\n";
