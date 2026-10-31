<?php declare(strict_types=1);

/**
 * A project compile has to work with the cache off.
 *
 * Releasing a checked program is what keeps a whole-project compile inside the
 * memory floor, and it is sound only where the module cache can hand that
 * program back. `--no-cache` and `MOGGI_NO_CACHE=1` are documented switches, so
 * the release has to be conditional on the cache being enabled rather than the
 * compile refusing outright.
 *
 * The module needs no prelude, which keeps the closure to one file: what this
 * case pins is the mode, not the library.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/assert.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Cache;
use Moggi\Modules;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$source = <<<'MOG'
{-# LANGUAGE NoImplicitPrelude #-}
module Plain where

pair x = (x, x)
MOG;

$work = createTempDir('moggi-no-cache-project');
$path = $work . '/Plain.mog';
\file_put_contents($path, $source);

Cache\setCacheEnabled(false);
$assert(! Cache\cacheEnabled(), 'the case did not turn the cache off');

try {
    $context = CompilationContext::for(
        'php',
        CompilePurpose::Library,
        roots: [$work],
        sources: [$path],
    );

    $outputs = Modules\compileProjectBoth([$path], $work, $context);
    $assert($outputs['emit'] !== [], 'a project compile with the cache off emitted nothing');
    $assert($outputs['opt-ir'] !== [], 'a project compile with the cache off lowered nothing');

    $again = Modules\compileProjectBoth([$path], $work, $context);
    $assert(
        $again['emit'] === $outputs['emit'],
        'two compiles of one project with the cache off emitted different artifacts',
    );

    // The invariant the conditional above exists for: no cache, no release.
    $refused = '';
    try {
        Modules\prepareProject([$path], $work, $context, releaseChecked: true);
    } catch (\LogicException $e) {
        $refused = $e->getMessage();
    }
    $assert(
        \str_contains($refused, 'needs the module cache'),
        'a release without a cache must be refused by name, got: '
            . ($refused === '' ? 'no refusal' : $refused),
    );

    echo "no-cache project compile tests passed\n";
} finally {
    removeDirectory($work);
}
