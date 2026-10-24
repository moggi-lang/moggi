<?php declare(strict_types=1);

/**
 * The server runs the compiler it started with, for as long as it runs, so an
 * edit to `src/` under a live editor leaves it checking with a stale one. These
 * assertions cover the notice that says so.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Cache\compilerFingerprint;
use function Moggi\Cache\freshCompilerFingerprint;
use function Moggi\LSP\compilerRevisionWarning;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$assert(compilerRevisionWarning('abc123', 'abc123') === null, 'equal revisions stay quiet');
$warning = compilerRevisionWarning('abc123', 'def456');
$assert($warning !== null, 'a moved-on compiler warns');
$assert($warning['type'] === 2, 'the notice is a warning: ' . $warning['type']);
$assert(
    \str_contains($warning['message'], 'abc123') && \str_contains($warning['message'], 'def456'),
    'both revisions are named: ' . $warning['message'],
);
$assert(
    \stripos($warning['message'], 'restart') !== false,
    'the notice says what to do: ' . $warning['message'],
);
$running = compilerFingerprint();
$assert($running === freshCompilerFingerprint(), 'fresh fingerprint matches the memoized one when nothing moved');
$assert(\preg_match('/^[0-9a-f]{32}$/', $running) === 1, 'a fingerprint is 32 hex chars: ' . $running);

echo "lsp/staleness: all assertions passed\n";
