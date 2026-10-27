<?php declare(strict_types=1);

/**
 * A compile that carries a host tree and one that does not must never answer for each other.
 *
 * A `foreign php` declaration is checked against the tree the compile carries, so that module's
 * checked product is valid only for the host identity it was checked against. Host trees live in a
 * process-global registry rather than in the module roots, so unless that identity reaches the cache
 * key the second compile reuses the first one's product: exit 0, and an artifact that dies on
 * `Call to undefined function` where a cold compile would have refused it. Pinned through the real
 * CLI and one cache directory: with the flag, without it, warm, cold, a tree edited in place, and the
 * reverse order.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

/** Copy the Composer fixture (descriptor + `vendor/`) into the workspace. */
$copyTree = static function (string $from, string $to) use (&$copyTree): void {
    \mkdir($to, 0777, true);
    foreach (\scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        \is_dir($source) ? $copyTree($source, $to . '/' . $entry) : \copy($source, $to . '/' . $entry);
    }
};

$work = createTempDir('moggi-cache-host-identity');

$compile = static function (string $cacheDir, array $arguments) use ($root, $work): array {
    $env = \getenv();
    $env['MOGGI_CACHE_DIR'] = $cacheDir;
    unset($env['MOGGI_NO_CACHE']);

    return runCompiledProcess([\PHP_BINARY, $root . '/moggi.php', 'compile', $work . '/Main.mog', ...$arguments], 120, $env, $root);
};

$runArtifact = static function (string $artifact) use ($work): array {
    return runCompiledProcess([\PHP_BINARY, $artifact], 60, null, $work);
};

$program = <<<'MOG'
module Main where

import Data.String
import System.IO

foreign php function greet "Acme\\Hello\\greet" :: String -> String

main :: IO ()
main = putStrLn (greet "composer")
MOG;

try {
    \file_put_contents($work . '/Main.mog', $program . "\n");
    $project = $work . '/project';
    $copyTree($root . '/tests/registry/bind/fixtures/acme-hello-php', $project);

    // Each cache dir starts with the library products but nothing of this case: what is being pinned
    // is the host identity in *this program's* key, not the standard library's elaboration time.
    $shared = $work . '/cache-shared';
    seedCacheDir($shared);
    $flag = ['--lib-composer', $project];

    $cold = $compile($shared, [...$flag, '-o', $work . '/cold.phar']);
    $assert(($cold['exitCode'] ?? 1) === 0, 'a Composer tree must compile: ' . \trim((string) $cold['stderr']));
    $ran = $runArtifact($work . '/cold.phar');
    $assert(\trim((string) $ran['stdout']) === 'Hello, composer!', 'the artifact must call into the tree: ' . \trim((string) $ran['stdout'] . $ran['stderr']));

    // The same cache dir with no host flag must refuse the flagged compile's product instead of
    // inheriting it.
    $unflagged = $compile($shared, ['-o', $work . '/unflagged.phar']);
    $assert(($unflagged['exitCode'] ?? 0) !== 0, 'a compile without the host tree must not accept the flagged compile\'s checked product');
    $assert(
        \str_contains((string) $unflagged['stderr'], 'php has no function `Acme\\Hello\\greet`'),
        'the refusal must name the unbacked declaration: ' . \trim((string) ($unflagged['stdout'] . $unflagged['stderr'])),
    );
    $assert(! \is_file($work . '/unflagged.phar'), 'a refused compile must not leave an artifact behind');

    $warm = $compile($shared, [...$flag, '-o', $work . '/warm.phar']);
    $assert(($warm['exitCode'] ?? 1) === 0, 'the flagged compile must still be a warm hit: ' . \trim((string) $warm['stderr']));
    $warmRun = $runArtifact($work . '/warm.phar');
    $assert(\trim((string) $warmRun['stdout']) === 'Hello, composer!', 'a warm compile must emit the same program: ' . \trim((string) ($warmRun['stdout'] . $warmRun['stderr'])));

    $coldOtherDir = $work . '/cache-cold';
    seedCacheDir($coldOtherDir);
    $coldOther = $compile($coldOtherDir, [...$flag, '-o', $work . '/cold-other.phar']);
    $assert(($coldOther['exitCode'] ?? 1) === 0, 'a cold cache dir must compile: ' . \trim((string) $coldOther['stderr']));
    $coldOtherRun = $runArtifact($work . '/cold-other.phar');
    $assert(
        \trim((string) $coldOtherRun['stdout']) === \trim((string) $warmRun['stdout']),
        'cold and warm must agree on what the program prints',
    );

    // Same path, different contents: the tree's identity is its files, not its directory name.
    \unlink($project . '/vendor/acme/hello/src/functions.php');
    $edited = $compile($shared, [...$flag, '-o', $work . '/edited.phar']);
    $assert(($edited['exitCode'] ?? 0) !== 0, 'a tree whose backing file was deleted must not be served the stale product');
    $assert(
        \str_contains((string) $edited['stderr'], 'php has no function `Acme\\Hello\\greet`'),
        'the edited tree must be refused for the reason it no longer backs: ' . \trim((string) ($edited['stdout'] . $edited['stderr'])),
    );

    // Reverse order: the refusal above caches nothing, so a flagged compile in a fresh dir works.
    $reverse = $work . '/cache-reverse';
    seedCacheDir($reverse);
    $reversePlain = $compile($reverse, ['-o', $work . '/reverse-plain.phar']);
    $assert(($reversePlain['exitCode'] ?? 0) !== 0, 'without the tree the compile must refuse here too');
    $reverseFlagged = $compile($reverse, ['--lib-composer', $root . '/tests/registry/bind/fixtures/acme-hello-php', '-o', $work . '/reverse-flagged.phar']);
    $assert(($reverseFlagged['exitCode'] ?? 1) === 0, 'a refusal must not be cached against the flagged compile: ' . \trim((string) $reverseFlagged['stderr']));
} finally {
    removeDirectory($work);
}

echo "cache host library identity tests passed ({$checks} checks)\n";
