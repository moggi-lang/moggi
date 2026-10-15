#!/usr/bin/env php
<?php declare(strict_types=1);

// Bindings Phase 4: `build --bind` turns `[ffi] bindings` into library roots.
// One targeted test only, on the PHP binder (no host tool needed — reflection
// runs in-process): the descriptor round-trip of `[ffi]`, the per-backend
// filter, the marker hit/miss/staleness contract of `bindForBuild`, the
// compile+run against the generated root, the .NET sidecar fold in the emit
// discriminator, and `cache clear bindings` end to end. `moggi build --bind`
// itself needs a signed registry and a lock, so the CLI glue (flag to
// `bindDeclaredForBuild` to `--lib`) is covered by reading, and by the
// `--help` surface assert below — everything the flag feeds on is run here.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/assert.php';

use Moggi\Backend\DotNet\DotNetBackend;

use function Moggi\Backend\DotNet\Dependencies\dotNetSidecarDiscriminator;
use function Moggi\Backend\DotNet\Dependencies\isGeneratedBindPath;
use function Moggi\Cache\runtimeCacheDir;
use function Moggi\Registry\Bind\bindCacheKey;
use function Moggi\Registry\Bind\bindCacheMarkerPath;
use function Moggi\Cache\bindCacheRoots;
use function Moggi\Registry\Bind\bindDeclaredForBuild;
use function Moggi\Registry\Bind\bindForBuild;
use function Moggi\Registry\Bind\ffiBindingsForBackend;
use function Moggi\Registry\descriptorProblems;
use function Moggi\Registry\readDescriptor;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$work = createTempDir('moggi-build-bind');
\putenv('MOGGI_CACHE_DIR=' . $work . '/cache');

// The descriptor round-trips `[ffi] bindings` as a list; the build filter
// keeps this backend's entries and refuses entries no binder could own.
$descriptorPath = $work . '/acme.moggi';
\file_put_contents($descriptorPath, <<<'MOGGI'
[package]
name = acme
version = 1.0.0
backends = php
[author]
name = Test
email = test@example.com
npub = npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm

[ffi]
bindings = php:acme/hello, jvm:com.acme:hello
MOGGI);
$assert(descriptorProblems($descriptorPath) === [], 'the [ffi] descriptor is refused');
$descriptor = readDescriptor($descriptorPath);
$assert(
    ($descriptor['ffi']['bindings'] ?? null) === ['php:acme/hello', 'jvm:com.acme:hello'],
    '[ffi] bindings misread: ' . \json_encode($descriptor['ffi'] ?? null),
);
$assert(
    ffiBindingsForBackend($descriptor, 'php') === ['acme/hello'],
    'php filter: ' . \json_encode(ffiBindingsForBackend($descriptor, 'php')),
);
$assert(
    ffiBindingsForBackend($descriptor, 'jvm') === ['com.acme:hello'],
    'jvm filter: ' . \json_encode(ffiBindingsForBackend($descriptor, 'jvm')),
);
$assert(ffiBindingsForBackend($descriptor, 'dotnet') === [], 'dotnet sees no bindings');
$assert(ffiBindingsForBackend([], 'php') === [], 'a descriptor without [ffi] binds nothing');
foreach (['go:github.com/acme/hello', 'phpacme/hello'] as $bad) {
    try {
        ffiBindingsForBackend(['ffi' => ['bindings' => [$bad]]], 'php');
        $assert(false, "`{$bad}` passed the [ffi] filter");
    } catch (\RuntimeException) {
    }
}

// The tree is a copy: staleness is proven by touching it, never the fixture.
$tree = $work . '/tree';
mkdir($tree, 0777, true);
$fixtureRoot = $root . '/tests/registry/bind/fixtures/acme-hello';
$scan = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($fixtureRoot, \FilesystemIterator::SKIP_DOTS),
);
foreach ($scan as $file) {
    /** @var \SplFileInfo $file */
    $target = $tree . \substr($file->getPathname(), \strlen($fixtureRoot));
    if ($file->isDir()) {
        if (!\is_dir($target)) {
            \mkdir($target, 0777, true);
        }

        continue;
    }
    $parent = \dirname($target);
    if (!\is_dir($parent)) {
        \mkdir($parent, 0777, true);
    }
    \copy($file->getPathname(), $target);
}
$assert(\is_file($tree . '/vendor/acme/hello/src/Greeter.php'), 'the copied tree misses Greeter.php');

// Generate: the root is the bind cache key, the module lands under the
// module/file law, and the marker records the coordinate.
$bound = bindDeclaredForBuild('php', $tree, $descriptor);
$expectedRoot = bindCacheKey('php', 'acme/hello');
$assert($bound['roots'] === [$expectedRoot], 'generated roots: ' . \json_encode($bound['roots']));
$assert(\count($bound['lines']) === 1 && \str_starts_with($bound['lines'][0], 'generated php:acme/hello'), 'summary: ' . \json_encode($bound['lines']));
$assert(\is_file($expectedRoot . '/Composer/Acme/Hello.mog'), 'no generated module under the root');
$marker = \json_decode((string) \file_get_contents(bindCacheMarkerPath($expectedRoot)), true);
$assert(\is_array($marker) && ($marker['coordinate'] ?? '') === 'acme/hello', 'marker: ' . \json_encode($marker));
// `globPaths` answers `/`-spelled paths on every host; the key is built with
// `/` — compare flat.
$flatRoots = \array_map(static fn (string $root): string => \str_replace('\\', '/', $root), bindCacheRoots());
$assert(\in_array(\str_replace('\\', '/', $expectedRoot . '/'), $flatRoots, true), 'bindCacheRoots misses the tree');

// Reuse: an unchanged tree never re-runs the host inspection.
$again = bindForBuild('php', 'acme/hello', $tree);
$assert($again['root'] === $expectedRoot && $again['regenerated'] === false, 'a clean rebuild regenerated');

// Staleness: a touched source regenerates; a deleted module regenerates too.
\file_put_contents($tree . '/vendor/acme/hello/src/Greeter.php', "\n// touched\n", \FILE_APPEND);
$touched = bindForBuild('php', 'acme/hello', $tree);
$assert($touched['regenerated'] === true, 'a touched tree reused the cache');
\unlink($expectedRoot . '/Composer/Acme/Hello.mog');
$restored = bindForBuild('php', 'acme/hello', $tree);
$assert($restored['regenerated'] === true, 'a marker with missing outputs reused the cache');
$assert(\is_file($expectedRoot . '/Composer/Acme/Hello.mog'), 'regeneration did not restore the module');

// End to end: a program compiles and runs against the generated root, with
// the host tree riding as its library root.
\file_put_contents($work . '/Main.mog', <<<'MOG'
module Main where

import Composer.Acme.Hello (greet, greeterVersion)
import Data.Either (Either(..))
import System.IO (putStrLn)

report :: Either String String -> IO ()
report r = case r of
  Left err -> putStrLn err
  Right value -> putStrLn value

main :: IO ()
main = do
  a <- greet "Moggi"
  report a
  putStrLn greeterVersion
MOG . "\n");
$env = \getenv();
$env['MOGGI_CACHE_DIR'] = $work . '/cache';
seedCacheDir($env['MOGGI_CACHE_DIR']);
$moggi = static fn (array $args, int $timeout = 120): array => runCompiledProcess(
    [PHP_BINARY, $root . '/moggi.php', ...$args],
    $timeout,
    $env,
    $root,
);
$run = $moggi(['run', $work . '/Main.mog', '--lib', $expectedRoot, '--lib', $tree]);
$assert(($run['exitCode'] ?? 1) === 0, 'run failed: ' . ($run['stderr'] ?? ''));
$assert(
    \str_ends_with((string) ($run['stdout'] ?? ''), "Hello, Moggi!\n1.0.0\n"),
    'run printed: ' . ($run['stdout'] ?? '') . "\nstderr: " . ($run['stderr'] ?? ''),
);

// The emit discriminator folds sidecar bytes: a new sidecar under a library
// root changes the key, so a regenerated binding beside an unchanged
// declaration still re-emits.
\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');
$before = (new DotNetBackend())->emitCacheDiscriminator();
\mkdir($work . '/sideroot/dotnet', 0777, true);
\file_put_contents($work . '/sideroot/dotnet/extra.json', '{"format": 1, "assemblies": {}}');
\Moggi\Modules\addLibraryRoot($work . '/sideroot');
$after = (new DotNetBackend())->emitCacheDiscriminator();
$assert($after !== '' && $after !== $before, 'the sidecar fold did not move the discriminator');

// The bind-root key excludes generated trees, or the marker could never hit:
// the predicate recognizes the layout textually.
$assert(isGeneratedBindPath(runtimeCacheDir() . '/dotnet/bind-acme-1-plain/dotnet/dependencies.json'), 'bind layout not recognized');
$assert(isGeneratedBindPath($expectedRoot . '/Composer/Acme/Hello.mog'), 'generated module not recognized');
$assert(!isGeneratedBindPath($root . '/lib-meta/Data/Integer/dotnet/dependencies.json'), 'stdlib sidecar misread as generated');
$assert(!isGeneratedBindPath('/tmp/acme-hello-dotnet/dotnet/Acme.Hello.dll'), 'host tree misread as generated');
$assert(dotNetSidecarDiscriminator(false) === dotNetSidecarDiscriminator(false), 'host-only discriminator is not deterministic');

// `cache clear bindings` drops exactly the generated trees.
$cleared = $moggi(['cache', 'clear', 'bindings']);
$assert(($cleared['exitCode'] ?? 1) === 0, 'cache clear bindings failed: ' . ($cleared['stderr'] ?? ''));
$assert(!\is_dir($expectedRoot), 'cache clear bindings left the tree');
$assert(\str_contains((string) ($cleared['stdout'] ?? ''), 'bindings'), 'clear says: ' . ($cleared['stdout'] ?? ''));

// The `build` surface names the flag (the flag-to-roots glue needs a signed
// registry, so this pins the contract it offers).
$help = $moggi(['build', '--help']);
$assert(($help['exitCode'] ?? 1) === 0, 'build --help failed');
$assert(\str_contains((string) ($help['stdout'] ?? ''), '--bind'), 'build --help names no --bind flag');

removeDirectory($work);

echo "build bind tests passed ({$checks} checks)\n";
