#!/usr/bin/env php
<?php declare(strict_types=1);

// `check` holds hand-written `foreign` against the `[ffi] bindings` a package
// declares: uncovered foreign warns, and once bindings exist the shape of the
// declaration is checked instead (malformed entries, narrowed backends with
// nothing bound, facades pointing at missing modules, several bound backends
// with no facade). All warnings — a package `check` passes may bind nothing.

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

$npub = 'npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm';
$work = createTempDir('moggi-check-ffi');
$env = \getenv();
$env['MOGGI_CACHE_DIR'] = $work . '/cache';
seedCacheDir($env['MOGGI_CACHE_DIR']);

$package = static function (string $dir, string $name, string $extra = '') use ($npub): void {
    \mkdir($dir . '/src', 0777, true);
    \file_put_contents(
        $dir . '/' . $name . '.moggi',
        "[package]\nname = {$name}\nversion = 0.1.0\n{$extra}\n"
        . "[author]\nname = Test\nnpub = {$npub}\n\n[lib]\nsource-dirs = src\n",
    );
};
$foreignModule = static function (string $dir, string $name): void {
    \file_put_contents(
        $dir . '/src/' . $name . '.mog',
        "module {$name} where\n\nforeign import php \"thing\" :: Int -> Int\nthing :: Int -> Int\nthing = _thing\n",
    );
};
$check = static function (string $dir) use ($root, $env): array {
    return runCompiledProcess([PHP_BINARY, $root . '/moggi.php', 'check', $dir], 120, $env, $root);
};

try {
    // Uncovered foreign warns; a comment and a longer name do not.
    $uncovered = $work . '/uncovered';
    $package($uncovered, 'uncovered');
    $foreignModule($uncovered, 'Probe');
    \file_put_contents(
        $uncovered . '/src/Clean.mog',
        "module Clean where\n\n-- mentions foreign in a comment\nforeignish :: Int\nforeignish = 1\n",
    );
    $ran = $check($uncovered);
    $assert(($ran['exitCode'] ?? 1) === 0, 'uncovered foreign must warn, not fail');
    $assert(\str_contains($ran['stdout'], 'src/Probe.mog: hand-written `foreign`'), 'uncovered foreign not flagged: ' . $ran['stdout']);
    $assert(! \str_contains($ran['stdout'], 'Clean.mog'), 'comment/name lookalikes flagged as foreign: ' . $ran['stdout']);

    // A declared binding silences the foreign it covers.
    $bound = $work . '/bound';
    $package($bound, 'bound');
    \file_put_contents($bound . '/bound.moggi', "\n[ffi]\nbindings = php:com.acme:thing:1.0\n", FILE_APPEND);
    $foreignModule($bound, 'Probe');
    $ran = $check($bound);
    $assert(($ran['exitCode'] ?? 1) === 0, 'a bound package failed check: ' . $ran['stdout'] . $ran['stderr']);
    $assert(! \str_contains($ran['stdout'], 'hand-written `foreign`'), 'covered foreign still flagged: ' . $ran['stdout']);

    // Malformed entries and narrowed backends with nothing bound warn.
    $shape = $work . '/shape';
    $package($shape, 'shape', 'backends = php, jvm');
    \file_put_contents($shape . '/shape.moggi', "\n[ffi]\nbindings = php:com.acme:thing:1.0, bogus\n", FILE_APPEND);
    $foreignModule($shape, 'Probe');
    $ran = $check($shape);
    $assert(\str_contains($ran['stdout'], 'entry `bogus` is not `backend:coordinate`'), 'malformed entry not flagged: ' . $ran['stdout']);
    $assert(\str_contains($ran['stdout'], 'backend `jvm` is in [package] backends'), 'backend asymmetry not flagged: ' . $ran['stdout']);
    $assert(! \str_contains($ran['stdout'], 'hand-written `foreign`'), 'declared bindings re-flagged the foreign: ' . $ran['stdout']);

    // A facade with its implementation present is clean; a missing one warns.
    $facade = $work . '/facade';
    $package($facade, 'facade');
    \file_put_contents($facade . '/facade.moggi', "\n[ffi]\nbindings = php:com.acme:thing:1.0\n", FILE_APPEND);
    \file_put_contents(
        $facade . '/src/Facade.mog',
        "{-# BACKEND\n  php = FacadePHP\n#-}\nmodule Facade (x) where\n\nx :: Int\nx = 1\n",
    );
    $foreignModule($facade, 'FacadePHP');
    $ran = $check($facade);
    $assert(\str_contains($ran['stdout'], 'no problems found'), 'facade with implementation not clean: ' . $ran['stdout']);

    $dangling = $work . '/dangling';
    $package($dangling, 'dangling');
    \file_put_contents($dangling . '/dangling.moggi', "\n[ffi]\nbindings = php:com.acme:thing:1.0\n", FILE_APPEND);
    \file_put_contents(
        $dangling . '/src/Facade.mog',
        "{-# BACKEND\n  php = Gone\n#-}\nmodule Facade (x) where\n\nx :: Int\nx = 1\n",
    );
    $ran = $check($dangling);
    $assert(\str_contains($ran['stdout'], 'maps backend `php` to `Gone`'), 'dangling facade not flagged: ' . $ran['stdout']);

    // Bindings for two backends with no facade between them warn.
    $multi = $work . '/multi';
    $package($multi, 'multi');
    \file_put_contents($multi . '/multi.moggi', "\n[ffi]\nbindings = php:com.acme:thing:1.0, jvm:com.acme:thing:1.0\n", FILE_APPEND);
    \file_put_contents($multi . '/src/Plain.mog', "module Plain (x) where\n\nx :: Int\nx = 1\n");
    $ran = $check($multi);
    $assert(\str_contains($ran['stdout'], 'with no `{-# BACKEND #-}` facade'), 'facade-less multi-backend bindings not flagged: ' . $ran['stdout']);

    // `--strict-ffi` fails on `[ffi]` findings and nothing else.
    $strict = static fn (string $dir): array => runCompiledProcess(
        [PHP_BINARY, $root . '/moggi.php', 'check', '--strict-ffi', $dir],
        120,
        $env,
        $root,
    );
    $ran = $strict($uncovered);
    $assert(($ran['exitCode'] ?? 0) === 1, 'strict accepted uncovered foreign: ' . $ran['stdout']);
    $assert(\str_contains($ran['stdout'], 'error') && \str_contains($ran['stdout'], 'src/Probe.mog: hand-written `foreign`'), 'strict did not promote the finding: ' . $ran['stdout']);
    $ran = $strict($bound);
    $assert(($ran['exitCode'] ?? 1) === 0, 'strict failed a bound package: ' . $ran['stdout'] . $ran['stderr']);
    \file_put_contents($bound . '/src/Entry.mog', "main :: IO ()\nmain = putStrLn \"x\"\n");
    $ran = $strict($bound);
    $assert(($ran['exitCode'] ?? 1) === 0, 'strict promoted a non-ffi warning: ' . $ran['stdout']);
    $assert(\str_contains($ran['stdout'], '1 warning'), 'non-ffi warning lost under strict: ' . $ran['stdout']);

    echo "check ffi tests passed ({$checks} checks)\n";
} finally {
    removeDirectory($work);
}
