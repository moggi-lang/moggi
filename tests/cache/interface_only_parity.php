<?php declare(strict_types=1);

/**
 * A module's interface has one producer: its own check. A run that does not check
 * the module reads exactly that product back from the module cache.
 *
 * This pins the two routes against each other. The first compile checks every
 * module of the project and emits the entry's IR. The entry is then edited in a
 * way that cannot change its meaning (a trailing comment), so its content key
 * moves and it is checked again -- while the modules it imports keep their cached
 * interface and are never checked in that run. Both IR dumps must be byte for
 * byte equal: an interface the cache hands over that differs from the one the
 * check produced shows up here as a differing dictionary argument, callable
 * arity or reexport origin at the consumer.
 *
 * The project carries the shapes an interface exists for: an inferred constrained
 * export, an instance whose scheme constrains its argument, a data type with an
 * exported constructor, and a module that only reexports another.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/assert.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$work = createTempDir('moggi-interface-only-parity');

try {
    $project = $work . '/project';
    \mkdir($project, 0o777, true);

    \file_put_contents($project . '/Prov.mog', <<<'MOGGI'
module Prov (Pair(..), renderOne) where

import Prelude

data Pair a = Pair a a

instance Show a => Show (Pair a) where
  show _ = "Pair"

-- Inferred signature: the constraint comes from `show`, and a consumer in
-- another module is what fixes `a`.
renderOne x = show x
MOGGI);
    \file_put_contents($project . '/Hub.mog', <<<'MOGGI'
module Hub (module Prov) where

import Prov
MOGGI);
    $main = $project . '/Main.mog';
    $mainSource = <<<'MOGGI'
module Main where

import Hub

main :: IO ()
main = do
  putStrLn (renderOne (Pair 1 2))
  putStrLn (renderOne (1 :: Int))
MOGGI;
    \file_put_contents($main, $mainSource);

    $ir = static function (string $cacheDir, bool $seed = false) use ($main, $project, $root): array {
        $env = \getenv();
        $env['MOGGI_CACHE_DIR'] = $cacheDir;
        unset($env['MOGGI_NO_CACHE']);
        if ($seed) {
            seedCacheDir($cacheDir);
        }

        return runCompiledProcess(
            [PHP_BINARY, $root . '/moggi.php', 'compile', $main, '--lib', $project, '--ir'],
            60,
            $env,
            $root,
        );
    };

    $checkedAll = $ir($work . '/cache-a', true);
    $assert(($checkedAll['exitCode'] ?? 0) === 0, 'cold compile failed: ' . \trim((string) ($checkedAll['stderr'] ?? '')));
    $cold = (string) $checkedAll['stdout'];
    $assert(
        \str_contains($cold, '__ev_Show_Pair(@__ev_Show_Int)') && \str_contains($cold, 'call renderOne('),
        "the consumer's IR lost the provider's inferred dictionary:\n" . $cold,
    );

    // A module's cached interface records the content key it was written for, and
    // `modulePut` always rewrites its file, so the key says what the entry saw and
    // the mtime says whether anything produced it.
    $product = static function (string $cacheDir, string $module) use ($assert): array {
        $paths = \glob($cacheDir . '/compile/*/*/' . $module . '.localtypes.mogc') ?: [];
        $assert(\count($paths) === 1, "the cache holds " . \count($paths) . " interfaces for {$module}");
        $raw = \unserialize((string) \file_get_contents($paths[0]));

        return ['key' => $raw['key'] ?? null, 'writtenAt' => (int) \filemtime($paths[0])];
    };

    $importedBefore = $product($work . '/cache-a', 'Prov');
    $entryBefore = $product($work . '/cache-a', 'Main');

    // Same meaning, different content key: the entry is checked again, its imports are not.
    \file_put_contents($main, $mainSource . "\n-- a trailing comment moves the entry's content key, not its meaning\n");
    \sleep(1);
    $checkedEntry = $ir($work . '/cache-a');
    $assert(($checkedEntry['exitCode'] ?? 0) === 0, 'warm compile failed: ' . \trim((string) ($checkedEntry['stderr'] ?? '')));
    $warm = (string) $checkedEntry['stdout'];

    $assert(
        $product($work . '/cache-a', 'Main')['key'] !== $entryBefore['key'],
        'the edited entry kept its content key: the warm run checked nothing, so the parity below is vacuous',
    );
    $assert(
        $product($work . '/cache-a', 'Prov')['writtenAt'] === $importedBefore['writtenAt'],
        'the imported module was checked again instead of read from its interface, so the parity below is vacuous',
    );

    $assert(
        $cold === $warm,
        "IR differs between a run that checked the imported modules and one that read their interfaces from the cache:\n"
            . diffText($cold, $warm),
    );

    // A second cold cache, from the same sources, must agree with the first: the
    // parity above is between the check and its product, not between two caches.
    \file_put_contents($main, $mainSource);
    $otherCold = $ir($work . '/cache-b', true);
    $assert(($otherCold['exitCode'] ?? 0) === 0, 'second cold compile failed: ' . \trim((string) ($otherCold['stderr'] ?? '')));
    $assert(
        $cold === (string) $otherCold['stdout'],
        "IR differs between two cold caches:\n" . diffText($cold, (string) $otherCold['stdout']),
    );

    echo "interface-only parity tests passed\n";
} finally {
    removeDirectory($work);
}
