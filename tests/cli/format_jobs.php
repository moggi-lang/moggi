<?php declare(strict_types=1);

/**
 * `moggi format --jobs` formats a directory in worker threads, while the default already uses as
 * many workers as the machine allows. The report must not depend on the worker count or completion
 * order, and a writing run followed by `--check` must be clean.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$work = createTempDir('moggi-format-jobs');
$sources = $work . '/sources';
\mkdir($sources, 0777, true);
$files = [];
foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $name) {
    $file = $sources . '/' . $name . '.mog';
    \file_put_contents($file, "module {$name} where\n\nvalue={$name}\n");
    $files[$file] = "module {$name} where\n\nvalue = {$name}\n";
}
\file_put_contents($sources . '/Epsilon.mog', "module Epsilon where\n\nvalue=Epsilon\n");

$env = \getenv();
$env['MOGGI_CACHE_DIR'] = $work . '/cache';
$cli = static fn (array $arguments): array => runCompiledProcess(
    [PHP_BINARY, $root . '/moggi.php', 'format', ...$arguments],
    120,
    $env,
    $root,
);

$help = \runCompiledProcess([PHP_BINARY, $root . '/moggi.php', 'format', '--help'], 30, $env, $root);
$assert(($help['exitCode'] ?? 1) === 0, 'format --help must succeed: ' . $help['stderr']);
$assert(\str_contains($help['stdout'], '--jobs'), 'format --help names --jobs: ' . $help['stdout']);
$assert(! \str_contains($help['stdout'], '--parallel'), 'format --help no longer offers --parallel: ' . $help['stdout']);

try {
    $serial = $cli([$sources, '--check', '--jobs', '1']);
    $auto = $cli([$sources, '--check']);
    $explicit = $cli([$sources, '--check', '--jobs=2']);
    $assert(($serial['exitCode'] ?? 0) === 1, 'a non-canonical tree makes --check exit 1: ' . $serial['stderr']);
    $assert(($auto['exitCode'] ?? 0) === 1, 'the automatic worker count still checks every file: ' . $auto['stderr']);
    $assert($serial['stderr'] === $auto['stderr'], 'the automatic and serial reports differ');
    $assert($serial['stderr'] === $explicit['stderr'], 'explicit --jobs changed the report or its order');

    $written = $cli([$sources, '--jobs', '2']);
    $assert(($written['exitCode'] ?? 1) === 0, 'formatting the tree must succeed: ' . $written['stderr']);
    foreach ($files as $file => $expected) {
        $assert(\file_get_contents($file) === $expected, 'formatted file differs: ' . $file);
    }
    $assert(\file_get_contents($sources . '/Epsilon.mog') === "module Epsilon where\n\nvalue = Epsilon\n", 'the last file was not written');

    $clean = $cli([$sources, '--check']);
    $assert(($clean['exitCode'] ?? 1) === 0, '--check after formatting must be clean: ' . $clean['stderr']);
    $assert(\trim($clean['stderr']) === '', 'a clean --check must report nothing: ' . $clean['stderr']);

    $fixity = $work . '/fixity';
    \mkdir($fixity, 0777, true);
    \file_put_contents($fixity . '/Ops.mog', <<<'MOG'
module Ops where

infixr 5 <+>

(<+>) :: Int -> Int -> Int
(<+>) a b = a + b
MOG
    );
    \file_put_contents($fixity . '/Main.mog', <<<'MOG'
module Main where

import Ops

value=a <+> b <+> c
mixed = a <+> b * c
MOG
    );

    $fixityWritten = $cli([$fixity, '--jobs', '1']);
    $assert(($fixityWritten['exitCode'] ?? 1) === 0, 'formatting imported fixity must succeed: ' . $fixityWritten['stderr']);
    $fixitySource = (string) \file_get_contents($fixity . '/Main.mog');
    $assert(\str_contains($fixitySource, "value = a <+> b <+> c\n"), 'an imported right-associative chain was re-parenthesized: ' . $fixitySource);
    $assert(\str_contains($fixitySource, "mixed = a <+> b * c\n"), 'imported precedence was not used: ' . $fixitySource);
    $assert(! \str_contains($fixitySource, '(a <+> b) * c'), 'the formatter parsed the imported operator with default fixity: ' . $fixitySource);

    echo "format jobs tests passed ({$checks} checks)\n";
} finally {
    removeDirectory($work);
}
