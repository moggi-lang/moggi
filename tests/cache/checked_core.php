<?php declare(strict_types=1);

/**
 * The checked core has to survive the module cache, because that is the
 * product a warm project compile lowers from.
 *
 * `prepareProject()` stores the checked program under `modulePut(…, 'checked', …)`
 * and reads it back with `moduleGet()`; a module read that way is handed to
 * lowering as the `typedProgram`. The record inside it names each evidence
 * site's plan by the *object* the elaborated tree carries, so the question this
 * pins is whether that link survives the cache's encoding — and whether the IR
 * lowered from the decoded product is the IR the cold run emitted.
 *
 * The program is a checked-core declaration (an annotated, nonrecursive function)
 * with a dictionary parameter and a projected instance method, so the record
 * carries both plan kinds.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/assert.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Cache;
use Moggi\IR;
use Moggi\Modules;
use Moggi\Modules\CheckedCore;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$source = <<<'MOG'
module Main where

class Tag a where
  tag :: a -> Int

instance Tag Int where
  tag n = n

scaled :: Tag a => a -> Int
scaled x = tag x

picked :: Int
picked = tag 1

main :: IO ()
main = do
  putStrLn (show (scaled 1))
  putStrLn (show picked)
MOG;

$work = createTempDir('moggi-cache-checked-core');
$path = $work . '/Main.mog';
\file_put_contents($path, $source);

try {
    $context = CompilationContext::for(
        'php',
        CompilePurpose::Executable,
        entryModules: ['Main'],
        roots: [$work],
        sources: [$path],
    );
    $prepared = Modules\moduleCompileContext($path, $context, null);
    $program = $prepared['typedProgram'];
    $core = $program->core;

    $assert($core instanceof CheckedCore, 'the check published no checked core for `Main`');
    $names = \array_keys($core->functions);
    \sort($names);
    $assert(
        $names === ['main', 'picked', 'scaled'],
        'the category is annotated nonrecursive ordinary functions, got: ' . \implode(', ', $names),
    );

    $cold = IR\Dump\dump(IR\lower($program, $program->interface->data, $path));
    $assert(\str_contains($cold, 'scaled'), 'the cold lowering produced no `scaled`');

    $relPath = 'checked-core-cache-test/' . \bin2hex(\random_bytes(6));
    $key = 'checked-core-' . \bin2hex(\random_bytes(6));
    $payload = [
        'program' => $program,
        'exportedInferredSchemes' => $program->exportedInferredSchemes,
    ];
    Cache\modulePut($relPath, 'checked', $key, $payload, 'php');
    $back = Cache\moduleGet($relPath, 'checked', $key, 'php');

    $assert(\is_array($back) && isset($back['program']), 'the checked product did not read back from the cache');
    $round = $back['program'];
    $assert($round instanceof \Moggi\Syntax\Ast\Program, 'the cached checked product is not a program');

    $roundCore = $round->core;
    $assert($roundCore instanceof CheckedCore, 'the record did not survive the cache encoding');
    $roundNames = \array_keys($roundCore->functions);
    \sort($roundNames);
    $assert($roundNames === $names, 'the cached record lost declarations, got: ' . \implode(', ', $roundNames));
    $assert(
        $roundCore->function('scaled')?->evidenceBinders() === $core->function('scaled')?->evidenceBinders(),
        'the cached record lost the evidence vector',
    );
    $assert(
        \count($roundCore->function('scaled')?->bodyEvidence ?? []) === \count($core->function('scaled')?->bodyEvidence ?? []),
        'the cached record lost the body evidence plans',
    );
    $assert(
        ($core->function('scaled')?->parameterKinds ?? []) === ['a' => 'Type'],
        'the record does not state the kind `scaled`\'s signature fixed: '
            . \json_encode($core->function('scaled')?->parameterKinds),
    );
    $assert(
        $roundCore->function('scaled')?->parameterKinds === $core->function('scaled')?->parameterKinds,
        'the cached record lost the parameter kinds: '
            . \json_encode($roundCore->function('scaled')?->parameterKinds),
    );

    $warm = IR\Dump\dump(IR\lower($round, $round->interface->data, $path));
    $assert(
        $warm === $cold,
        "IR differs between the checked program and the same program read back from the module cache:\n"
            . diffText($cold, $warm),
    );

    $bytes = static function (string $rel, string $key, array $payload): string {
        Cache\modulePut($rel, 'checked', $key, $payload, 'php');

        return (string) \file_get_contents(Cache\modulePath($rel, 'checked', 'php'));
    };
    $first = $bytes($relPath, $key, $payload);
    $second = $bytes($relPath, $key, $payload);
    $assert($first === $second, 'the saved checked product is not byte-identical across two writes');

    echo "checked core cache tests passed\n";
} finally {
    removeDirectory($work);
}
