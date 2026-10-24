<?php declare(strict_types=1);

// A rewrite that turns a `dict_call` into a direct instance-method call must not leave the
// dictionary binding behind. `t2 = call __ev_Show_Int()` is pure, but DCE keeps calls unless it
// can prove them inert, so the unread binding used to survive — and with it every method of the
// instance. `Show Int`'s `showList` calls `Text.Show.showListWith`, so the artifact grew a whole
// module for a value nobody read.
//
// Pins the repair in `Optimize\Specialize`: a specialized dictionary binding that nothing reads is
// dropped, so the factory and the methods only it reached disappear with it.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
\define('MOGGI_PROJECT_ROOT', $root);
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/bootstrap.php';

\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');

use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;

use function Moggi\Modules\compileProjectBoth;
use function Moggi\Modules\projectSourceClosure;

$dir = createTempDir('moggi-dead-evidence');
$entry = $dir . '/Main.mog';
\file_put_contents($entry, <<<'MOG'
module Main where

import Text.Show

main :: IO ()
main = putStrLn (show (1 + 2))
MOG);

try {
    [$files, $projectRoot] = projectSourceClosure([$entry], [$root . '/libraries/base/src'], 'php');
    $context = CompilationContext::for(
        'php',
        CompilePurpose::Executable,
        entryModules: ['Main'],
        roots: [],
        sources: $files,
    );
    $outputs = compileProjectBoth($files, $projectRoot, $context, strip: true);
    $mainIr = null;
    foreach ($outputs['opt-ir'] as $path => $text) {
        if (\str_ends_with((string) $path, 'Main.opt-ir')) {
            $mainIr = (string) $text;

            break;
        }
    }
    if ($mainIr === null) {
        \fwrite(\STDERR, "no optimized IR for Main: " . \json_encode(\array_keys($outputs['opt-ir'])) . "\n");
        exit(1);
    }

    $ir = $mainIr;
    if (\preg_match('/^\s*t\d+ = call __ev_/m', $ir, $m) === 1) {
        \fwrite(\STDERR, "the optimized IR still binds an evidence factory nothing reads: {$m[0]}\n{$ir}");
        exit(1);
    }

    $emitted = $outputs['emit'];
    $entryRelative = (string) ($outputs['entryRelative'] ?? '');
    $entryText = \is_array($emitted)
        ? (string) ($emitted[$entryRelative] ?? '')
        : (string) $emitted;
    if ($entryText === '') {
        \fwrite(\STDERR, "no entry artifact at `{$entryRelative}`: " . \json_encode(\array_keys(\is_array($emitted) ? $emitted : [])) . "\n");
        exit(1);
    }
    if (\str_contains($entryText, '__ev_Show_Int(')) {
        \fwrite(\STDERR, "the entry still builds the unread `Show Int` dictionary\n");
        exit(1);
    }
    foreach (\array_keys(\is_array($emitted) ? $emitted : []) as $path) {
        if (\str_ends_with((string) $path, 'Text/Show.php')) {
            \fwrite(\STDERR, "the unread dictionary kept `Text/Show.php` in the emitted set\n");
            exit(1);
        }
    }
} finally {
    removeDirectory($dir);
}

echo "dead evidence binding tests passed\n";
