#!/usr/bin/env php
<?php declare(strict_types=1);

// Bindings Phase 1: `moggi bind php:acme/hello` reads the fixture tree and
// writes the `Composer.Acme.Hello` module, which then compiles and runs. One
// targeted test only: the inspector/writer units, the golden render, the CLI
// surface (`--print`, `--out`, the non-php refusal), the `[ffi]` descriptor
// key, and the emit-cache discriminator in the bind cache key.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/assert.php';

use Moggi\Backend\Php\PhpBackend;

use function Moggi\Cache\runtimeCacheDir;
use function Moggi\Registry\Bind\bindCacheKey;
use function Moggi\Registry\Bind\inspectPhpTree;
use function Moggi\Registry\Bind\parsePhpCoordinate;
use function Moggi\Registry\Bind\renderPhpBinding;
use function Moggi\Registry\descriptorProblems;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$fixture = $root . '/tests/registry/bind/fixtures/acme-hello';
$golden = (string) \file_get_contents($root . '/tests/registry/bind/Composer.Acme.Hello.mog.expected');

// The `[ffi]` section declares a binding package; anything else in it is a
// key the closed schema does not declare.
$work = createTempDir('moggi-bind');
$npub = 'npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm';
$writeDescriptor = static function (string $ffi) use ($work, $npub): string {
    $text = "[package]\nname = acme\nversion = 1.0.0\nbackends = php\n"
        . "[author]\nname = Test\nemail = test@example.com\nnpub = {$npub}\n\n[ffi]\n{$ffi}\n";
    $path = $work . '/acme.moggi';
    \file_put_contents($path, $text);

    return $path;
};
$assert(
    descriptorProblems($writeDescriptor("bindings = php:acme/hello\n")) === [],
    "`[ffi] bindings` is refused:\n" . \implode("\n", descriptorProblems($writeDescriptor("bindings = php:acme/hello\n"))),
);
$badFfi = \implode("\n", descriptorProblems($writeDescriptor("bindings = php:acme/hello\nbogus = 1\n")));
$assert(\str_contains($badFfi, '[ffi] does not take `bogus`'), "unexpected `[ffi]` verdict: {$badFfi}");

// The inspector: coordinate shape, module naming, and the fixture's symbols.
$parts = parsePhpCoordinate('acme/hello');
$assert($parts === ['vendor' => 'acme', 'package' => 'hello'], 'coordinate misparsed');
try {
    parsePhpCoordinate('hello');
    $assert(false, 'a bare name parsed as a Composer coordinate');
} catch (\RuntimeException) {
}

$descriptor = inspectPhpTree($fixture, 'acme', 'hello');
$assert($descriptor['module'] === 'Composer.Acme.Hello', 'module is ' . $descriptor['module']);
$assert($descriptor['skipped'] === [], 'unexpected skips: ' . \json_encode($descriptor['skipped']));
$assert(
    \array_column($descriptor['types'], 'moggi') === ['Greeter'],
    'types: ' . \json_encode($descriptor['types']),
);
$assert(
    \array_column($descriptor['functions'], 'moggi') === ['greet', 'greeterGreet', 'greeterGreetOrNull'],
    'functions: ' . \json_encode($descriptor['functions']),
);
$assert(
    \array_column($descriptor['consts'], 'moggi') === ['greeterVersion'],
    'consts: ' . \json_encode($descriptor['consts']),
);

// The render is the golden, byte for byte.
$rendered = renderPhpBinding($descriptor);
$assert($rendered === $golden, "render differs from golden:\n" . diffText($golden, $rendered));

// The bind cache key folds the emit discriminator in: a tree carried at build
// time must never reuse a binding emitted without one, or back.
$key = bindCacheKey('php', 'acme/hello');
$discriminator = (new PhpBackend())->emitCacheDiscriminator();
$assert(
    $key === runtimeCacheDir() . '/php/bind-acme-hello-' . ($discriminator !== '' ? $discriminator : 'plain'),
    'cache key is ' . $key,
);
$assert(bindCacheKey('php', 'acme/hello') === $key, 'cache key is not deterministic');
$assert(bindCacheKey('php', 'other/pkg') !== $key, 'cache key ignores the coordinate');

// The CLI: `--print` renders the golden, other backends are refused.
$env = \getenv();
$env['MOGGI_CACHE_DIR'] = $work . '/cache';
seedCacheDir($env['MOGGI_CACHE_DIR']);
$moggi = static fn (array $args): array => runCompiledProcess(
    [PHP_BINARY, $root . '/moggi.php', ...$args],
    120,
    $env,
    $root,
);

$printed = $moggi(['bind', 'php:acme/hello', '--tree', $fixture, '--print']);
$assert(($printed['exitCode'] ?? 1) === 0, 'bind --print failed: ' . ($printed['stderr'] ?? ''));
$assert(($printed['stdout'] ?? '') === $golden, "bind --print differs:\n" . diffText($golden, $printed['stdout'] ?? ''));

// `jvm:` binds since Phase 2 (see jvm_bind_test.php), `dotnet:` since Phase 3
// (see dotnet_bind_test.php); an unknown backend is refused.
$refused = $moggi(['bind', 'go:github.com/acme/hello', '--tree', $fixture]);
$assert(($refused['exitCode'] ?? 0) !== 0, 'a go: coordinate bound');
$assert(\str_contains((string) ($refused['stderr'] ?? ''), 'only `php:`, `jvm:` and `dotnet:`'), 'refusal says: ' . ($refused['stderr'] ?? ''));

// End to end: bind to a directory, compile a program against it, run it. The
// fixture root rides along as a library root, so the verifier reads its maps
// and the artifact requires its autoloader.
$gen = $work . '/gen';
$written = $moggi(['bind', 'php:acme/hello', '--tree', $fixture, '--out', $gen]);
$assert(($written['exitCode'] ?? 1) === 0, 'bind --out failed: ' . ($written['stderr'] ?? ''));
$assert(
    \is_file($gen . '/Composer/Acme/Hello.mog'),
    'binding not written under the module/file law',
);

// `--facade` faces the binding: the skeleton prints after the module and
// writes beside it under the module/file law, named without the ecosystem.
$faced = $moggi(['bind', 'php:acme/hello', '--tree', $fixture, '--print', '--facade']);
$assert(($faced['exitCode'] ?? 1) === 0, 'bind --print --facade failed: ' . ($faced['stderr'] ?? ''));
$assert(\str_starts_with(($faced['stdout'] ?? ''), $golden), 'facade print does not start with the binding');
$assert(\str_contains(($faced['stdout'] ?? ''), 'module Acme.Hello'), 'facade skeleton missing: ' . ($faced['stdout'] ?? ''));
$assert(\str_contains(($faced['stdout'] ?? ''), 'php = Composer.Acme.Hello'), 'facade pragma names the wrong module');

$genFaced = $work . '/gen-facade';
$faceWritten = $moggi(['bind', 'php:acme/hello', '--tree', $fixture, '--out', $genFaced, '--facade']);
$assert(($faceWritten['exitCode'] ?? 1) === 0, 'bind --out --facade failed: ' . ($faceWritten['stderr'] ?? ''));
$assert(\is_file($genFaced . '/Composer/Acme/Hello.mog'), 'binding not written beside the facade');
$assert(\is_file($genFaced . '/Acme/Hello.mog'), 'facade not written under the module/file law');

\file_put_contents($work . '/Main.mog', <<<'MOG'
module Main where

import Composer.Acme.Hello (greet, greeterGreet, greeterGreetOrNull, greeterVersion)
import Data.Either (Either(..))
import Data.Maybe (Maybe(..))
import System.IO (putStrLn)

report :: Either String String -> IO ()
report r = case r of
  Left err -> putStrLn err
  Right value -> putStrLn value

reportMaybe :: Maybe String -> IO ()
reportMaybe m = case m of
  Nothing -> putStrLn "nothing"
  Just value -> putStrLn value

main :: IO ()
main = do
  a <- greet "Moggi"
  report a
  b <- greeterGreet "Moggi"
  report b
  putStrLn greeterVersion
  c <- greeterGreetOrNull ""
  reportMaybe c
  d <- greeterGreetOrNull "Moggi"
  reportMaybe d
MOG . "\n");

$run = $moggi(['run', $work . '/Main.mog', '--lib', $gen, '--lib', $fixture]);
$assert(($run['exitCode'] ?? 1) === 0, 'run failed: ' . ($run['stderr'] ?? ''));
// `run` logs the build to stdout ahead of the program, so the program's
// output is the tail, not the whole stream.
$expected = "Hello, Moggi!\nHello, Moggi!\n1.0.0\nnothing\nHello, Moggi!\n";
$assert(\str_ends_with((string) ($run['stdout'] ?? ''), $expected), 'run printed: ' . ($run['stdout'] ?? '') . "\nstderr: " . ($run['stderr'] ?? ''));

removeDirectory($work);

echo "bind tests passed ({$checks} checks)\n";
