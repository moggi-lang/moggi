#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$npub = 'npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm';
$work = createTempDir('moggi-entry-module');
$env = \getenv();
$env['MOGGI_CACHE_DIR'] = $work . '/cache';

$descriptor = static function (string $name, string $section, string $main, string $dirs) use ($npub): string {
    return "[package]\nname = {$name}\nversion = 0.1.0\n\n[author]\nname = Test\nnpub = {$npub}\n\n"
        . "[{$section}]\nmain = {$main}\nsource-dirs = {$dirs}\n";
};

try {
    \mkdir($work . '/src', 0777, true);
    \file_put_contents(
        $work . '/src/App.mog',
        "module App (main) where\n\nmain :: IO ()\nmain = putStrLn \"hello, world\"\n",
    );

    $ran = runCompiledProcess([PHP_BINARY, $root . '/moggi.php', 'run', $work . '/src/App.mog'], 120, $env, $root);
    $assert(($ran['exitCode'] ?? 1) === 0, 'running an entry module not called `Main` failed: ' . \trim($ran['stderr']));
    $assert(\str_contains($ran['stdout'], 'hello, world'), 'the named entry module did not run: ' . $ran['stdout']);

    $positive = $work . '/pkg';
    \mkdir($positive . '/src', 0777, true);
    \copy($work . '/src/App.mog', $positive . '/src/App.mog');
    \file_put_contents(
        $positive . '/pkg.moggi',
        $descriptor('pkg', 'executable', 'App', 'src') . "\n[test-suite]\nmain = App\nsource-dirs = src\n",
    );

    $ok = runCompiledProcess([PHP_BINARY, $root . '/moggi.php', 'check', $positive], 120, $env, $root);
    $assert(($ok['exitCode'] ?? 1) === 0, "`check` refused a named entry module:\n" . $ok['stdout'] . $ok['stderr']);

    $negative = $work . '/bad';
    \mkdir($negative . '/tests', 0777, true);
    \file_put_contents($negative . '/tests/Suite.mog', "main :: IO ()\nmain = putStrLn \"x\"\n");
    \file_put_contents($negative . '/bad.moggi', $descriptor('bad', 'test-suite', 'Suite', 'tests'));

    $mismatch = runCompiledProcess([PHP_BINARY, $root . '/moggi.php', 'check', $negative], 120, $env, $root);
    $assert(($mismatch['exitCode'] ?? 0) === 1, '`check` accepted a headerless file as a `main` it does not declare');
    $assert(
        \str_contains($mismatch['stdout'], 'declares module `Main`, but `main = Suite`'),
        "the mismatch was not reported by name:\n" . $mismatch['stdout'],
    );

    \file_put_contents($negative . '/bad.moggi', $descriptor('bad', 'test-suite', 'Missing', 'tests'));
    $absent = runCompiledProcess([PHP_BINARY, $root . '/moggi.php', 'check', $negative], 120, $env, $root);
    $assert(($absent['exitCode'] ?? 0) === 1, '`check` accepted a `main` with no file');
    $assert(
        \str_contains($absent['stdout'], 'main module `Missing` has no file `Missing.mog`'),
        "the missing entry file was not reported:\n" . $absent['stdout'],
    );

    echo "entry module tests passed ({$checks} checks)\n";
} finally {
    removeDirectory($work);
}
