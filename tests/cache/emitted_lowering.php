<?php declare(strict_types=1);

/**
 * A project compile lowers the modules it writes, and the export memo outlives the project.
 *
 * Both are properties of the compiler rather than of a fixture's speed, so neither needs a
 * stopwatch to pin — and a timing assertion would be the first thing to flake on a busy machine.
 *
 *   1. An executable compile lowers the entry's closure and nothing else. What it reads is a much
 *      larger set: the import closure carries the standard library, and lowering all of it was the
 *      bulk of a backend case's cost. The invariant is `lowered ⇒ written` — every module with an
 *      `opt-ir` artifact also has an emitted artifact — plus an unreachable module in the same
 *      directory appearing in neither.
 *   2. The export memo may now be reused by the next project in the process, which is where a
 *      worker's hundred-odd fixtures pay the standard library once instead of once each. It is
 *      keyed by module content, so two projects that both declare `Shared` must each see their
 *      own: a memo made process-lifetime but left keyed by name answers the second project with
 *      the first project's exports.
 *
 * What this fails on: lowering the whole closure again (part 1), and re-keying the memo by name
 * (part 2).
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/assert.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Modules;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;

use function Moggi\Modules\bundledStdlibLibPath;
use function Moggi\Modules\projectSourceClosure;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

/**
 * The artifact whose key ends with `$suffix`. A closure's artifact keys are relative to the root the
 * closure was built from, which for a temp project is above it, so the module's own path is the
 * only part of the key a case can name.
 */
$artifact = static function (array $artifacts, string $suffix): ?string {
    foreach ($artifacts as $key => $bytes) {
        if (\is_string($key) && \is_string($bytes) && \str_ends_with($key, $suffix)) {
            return $bytes;
        }
    }

    return null;
};

/** Write one module and return its path. */
$module = static function (string $dir, string $name, string $source): string {
    $path = $dir . '/' . $name . '.mog';
    \file_put_contents($path, $source);

    return $path;
};

/** A project's import closure, built the way the suite builds one: its sources plus the stdlib. */
$closure = static function (array $sources): array {
    $libDirs = [];
    $bundled = bundledStdlibLibPath();
    if ($bundled !== null) {
        $libDirs[] = $bundled;
    }

    [$files, $root] = projectSourceClosure($sources, $libDirs, 'php');

    return [$files, $root, $libDirs];
};

$work = createTempDir('moggi-emitted-lowering');

try {
    // ---- 1 · the lowered set is the written set ----
    $dir = $work . '/reached';
    \mkdir($dir, 0777, true);
    $sources = [
        $module($dir, 'Main', <<<'MOG'
module Main where

import Used
import Text.Show

main :: IO ()
main = putStrLn (show (answer + 0))
MOG),
        $module($dir, 'Used', <<<'MOG'
module Used where

answer = 42
MOG),
        // In the prepared closure (same directory, passed in), reached by no plan.
        $module($dir, 'Unused', <<<'MOG'
module Unused where

nobody = 7
MOG),
    ];

    [$files, $projectRoot, $libDirs] = $closure($sources);
    $context = CompilationContext::for(
        'php',
        CompilePurpose::Executable,
        entryModules: ['Main'],
        roots: [$dir, ...$libDirs],
        sources: $files,
    );

    $outputs = Modules\compileProjectBoth($files, $projectRoot, $context);

    $assert(
        $artifact($outputs['emit'], '/Used.php') !== null,
        'the module the entry calls was not emitted: ' . \json_encode(\array_keys($outputs['emit'])),
    );
    $assert(
        $artifact($outputs['emit'], '/Unused.php') === null,
        'a module nothing reaches was emitted: ' . \json_encode(\array_keys($outputs['emit'])),
    );
    $assert(
        $artifact($outputs['opt-ir'], '/Unused.opt-ir') === null,
        'a module nothing reaches was lowered: ' . \json_encode(\array_keys($outputs['opt-ir'])),
    );

    foreach (\array_keys($outputs['opt-ir']) as $lowered) {
        $written = \preg_replace('/\.opt-ir$/', '.php', (string) $lowered);
        $assert(
            isset($outputs['emit'][$written]),
            "the compile lowered `{$lowered}` but wrote no artifact for it, so it lowered a module"
                . ' this compilation does not write (a module set it read, not the entry closure)',
        );
    }

    $again = Modules\compileProjectBoth($files, $projectRoot, $context);
    $assert($again['emit'] === $outputs['emit'], 'a repeated compile of one project emitted different artifacts');
    $assert($again['opt-ir'] === $outputs['opt-ir'], 'a repeated compile of one project lowered a different set');

    // ---- 2 · the export memo is keyed by content, not by module name ----
    $shared = static function (string $dir, string $export) use ($module): array {
        \mkdir($dir, 0777, true);

        return [
            $module($dir, 'Main', <<<MOG
module Main where

import Shared
import Text.Show

main :: IO ()
main = putStrLn (show ({$export} + 0))
MOG),
            $module($dir, 'Shared', <<<MOG
module Shared where

{$export} = 1
MOG),
        ];
    };

    $compile = static function (string $dir, array $sources) use ($closure): array {
        [$files, $projectRoot, $libDirs] = $closure($sources);
        $context = CompilationContext::for(
            'php',
            CompilePurpose::Executable,
            entryModules: ['Main'],
            roots: [$dir, ...$libDirs],
            sources: $files,
        );

        return Modules\compileProjectBoth($files, $projectRoot, $context);
    };

    $firstOutputs = $compile($work . '/first', $shared($work . '/first', 'firstValue'));
    // Same module names, different contents, same process: a name-keyed memo would answer this
    // project with the first project's `Shared`, which does not export `secondValue`.
    $secondOutputs = $compile($work . '/second', $shared($work . '/second', 'secondValue'));

    $firstShared = $artifact($firstOutputs['emit'], '/Shared.php');
    $secondShared = $artifact($secondOutputs['emit'], '/Shared.php');
    $assert(
        $secondShared !== null,
        'the second project did not emit its own `Shared`: ' . \json_encode(\array_keys($secondOutputs['emit'])),
    );
    $assert(
        $secondShared !== $firstShared,
        'two projects that declare `Shared` with different contents emitted the same artifact, so the'
            . ' export memo answered with exports from the other project',
    );

    echo "emitted-closure lowering tests passed\n";
} finally {
    removeDirectory($work);
}
