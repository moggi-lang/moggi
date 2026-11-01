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
 *   3. A library compile is not entry-gated: with no entry there is no closure to prune to, so every
 *      prepared module is lowered *and* written — the counterpart of part 1.
 *   4. The dump view is its own cached product. An emit-only compile writes the emit product; the
 *      dumps live under a second key, so the dump build that follows still answers with optimized
 *      IR. A payload whose shape a caller flag decided would answer `[]` here instead.
 *
 * What this fails on: lowering the whole closure again (part 1), re-keying the memo by name
 * (part 2), pruning a library build (part 3), and folding the dumps back into the emit payload
 * under a shape flag (part 4).
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
$closure = static function (array $sources, string $backend = 'php'): array {
    $libDirs = [];
    $bundled = bundledStdlibLibPath();
    if ($bundled !== null) {
        $libDirs[] = $bundled;
    }

    [$files, $root] = projectSourceClosure($sources, $libDirs, $backend);

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

main :: IO ()
main = putStrLn message
MOG),
        $module($dir, 'Used', <<<'MOG'
module Used where

message = "ok"
MOG),
        // In the prepared closure (same directory, passed in), reached by no plan.
        $module($dir, 'Unused', <<<'MOG'
module Unused where

nobody = 7
MOG),
    ];

    foreach ([
        ['php', '.php', true],
        ['jvm', '.class', true],
        ['dotnet', '.il', false],
    ] as [$backend, $extension, $optimize]) {
        [$files, $projectRoot, $libDirs] = $closure($sources, $backend);
        $context = CompilationContext::for(
            $backend,
            CompilePurpose::Executable,
            entryModules: ['Main'],
            roots: [$dir, ...$libDirs],
            sources: $files,
        );

        $outputs = Modules\compileProjectBoth($files, $projectRoot, $context, $optimize);

        $named = static function (array $artifacts, string $name): bool {
            foreach (\array_keys($artifacts) as $key) {
                if (\basename((string) $key) === $name) {
                    return true;
                }
            }

            return false;
        };

        $assert(
            $named($outputs['emit'], 'Used' . $extension),
            "{$backend}: the module the entry calls was not emitted: " . \json_encode(\array_keys($outputs['emit'])),
        );
        $assert(
            ! $named($outputs['emit'], 'Unused' . $extension),
            "{$backend}: a module nothing reaches was emitted: " . \json_encode(\array_keys($outputs['emit'])),
        );
        $assert(
            ! $named($outputs['opt-ir'], 'Unused.opt-ir'),
            "{$backend}: a module nothing reaches was lowered: " . \json_encode(\array_keys($outputs['opt-ir'])),
        );

        foreach (\array_keys($outputs['opt-ir']) as $lowered) {
            $assert(
                $named($outputs['emit'], \basename((string) \preg_replace('/\.opt-ir$/', $extension, (string) $lowered))),
                "{$backend}: the compile lowered `{$lowered}` but wrote no artifact for it, so it lowered"
                    . ' a module this compilation does not write',
            );
        }

        $again = Modules\compileProjectBoth($files, $projectRoot, $context, $optimize);
        $assert($again['emit'] === $outputs['emit'], "{$backend}: a repeated compile emitted different artifacts");
        $assert($again['opt-ir'] === $outputs['opt-ir'], "{$backend}: a repeated compile lowered a different set");
    }

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

    // ---- 3 · a library compile is not entry-gated ----
    $named = static function (array $artifacts, string $name): bool {
        foreach (\array_keys($artifacts) as $key) {
            if (\basename((string) $key) === $name) {
                return true;
            }
        }

        return false;
    };

    foreach ([
        ['php', '.php', true],
        ['jvm', '.class', true],
        ['dotnet', '.il', false],
    ] as [$backend, $extension, $optimize]) {
        [$files, $projectRoot, $libDirs] = $closure($sources, $backend);
        $context = CompilationContext::for(
            $backend,
            CompilePurpose::Library,
            roots: [$dir, ...$libDirs],
            sources: $files,
        );

        $library = Modules\compileProjectBoth($files, $projectRoot, $context, $optimize);

        $assert(
            $named($library['emit'], 'Unused' . $extension),
            "{$backend}: a library compile wrote no artifact for a prepared module, so it pruned a build"
                . ' with no entry to prune to',
        );
        $assert(
            $named($library['opt-ir'], 'Unused.opt-ir'),
            "{$backend}: a library compile did not lower every prepared module",
        );
    }

    // ---- 4 · the dump view is a separate cached product ----
    [$files, $projectRoot, $libDirs] = $closure($sources);
    $context = CompilationContext::for(
        'php',
        CompilePurpose::Executable,
        entryModules: ['Main'],
        roots: [$dir, ...$libDirs],
        sources: $files,
    );

    $emitOnly = Modules\compileProject($files, $projectRoot, $context);
    $withDumps = Modules\compileProjectBoth($files, $projectRoot, $context);

    $assert(
        $withDumps['emit'] === $emitOnly,
        'a dump build emitted different artifacts than the emit-only build before it',
    );
    $assert(
        $withDumps['opt-ir'] !== [],
        'a dump build after an emit-only build answered with no optimized IR: the cached payload still'
            . ' carries a shape a caller argument decides',
    );
    echo "emitted-closure lowering tests passed\n";
} finally {
    removeDirectory($work);
}
