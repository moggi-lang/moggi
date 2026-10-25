<?php declare(strict_types=1);

/**
 * The instance world is one named input with one identity, and every preparation
 * route that sees the same module set publishes the same one.
 *
 * The world is collected from the *prepared project's* modules rather than from
 * any module's import closure, so a module sees instances of modules it never
 * imports — that is the semantics every route keeps. What this fixture pins is
 * that the product says which world its schemes mean, and that the answer does
 * not depend on which entry point prepared the units or in what order it walked
 * them.
 *
 * The fixture is a project whose `Wide` module is imported by nobody, so its
 * instance exists only in the project-wide world:
 *
 *   Base   the `Tag` class and a constrained `label`
 *   Wide   `data Widget` with `instance Tag Widget` — not imported anywhere
 *   Main   imports `Base`, declares its own `Flag` instance, and typechecks `greet`
 *
 * Asserted:
 *
 *   1. the full project route and the focus route (what a single-file compile,
 *      docs page and the language server use) publish the same world for the
 *      same module set — same digest, module set, records and evidence;
 *   2. that world really contains the unimported module's instance;
 *   3. a route that sees a smaller set publishes a *different* identity naming
 *      that set, instead of silently inheriting the project's;
 *   4. the single-file entry point, a different route over that smaller set,
 *      publishes the same identity as a focus prepare of it;
 *   5. the identity is order-independent, which is why (1) can hold at all;
 *   6. a record outside the named set is refused rather than silently added.
 *
 * Each route runs against a cache of its own, seeded only with the shared
 * library products, so no route reads another's checked module and the
 * comparison is not made equal by the cache.
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
use Moggi\Modules\CheckedFunction;
use Moggi\Modules\InstanceWorld;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Semantics\Types;
use Moggi\Syntax\Ast;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

// Inside the workspace, not under the system temp dir: a module root is bounded
// by the workspace that owns the standard library, and `moduleFileClosure()`
// refuses to index modules from a directory outside it.
$work = $root . '/.moggi/test/instance-world-' . \getmypid() . '-' . \bin2hex(\random_bytes(6));
if (! @\mkdir($work, 0700, true) && ! \is_dir($work)) {
    throw new \RuntimeException("cannot create the fixture project at {$work}");
}
$sources = [
    'Base' => <<<'MOG'
module Base where

class Tag a where
  tag :: a -> String

label :: Tag a => a -> String
label x = tag x
MOG,
    'Wide' => <<<'MOG'
module Wide where

import Base

data Widget = Widget

instance Tag Widget where
  tag _ = "widget"
MOG,
    'Main' => <<<'MOG'
module Main where

import Base

data Flag = On | Off

instance Tag Flag where
  tag _ = "flag"

greet :: Tag a => a -> String
greet x = label x

main :: IO ()
main = putStrLn (greet On)
MOG,
];

$paths = [];
foreach ($sources as $moduleName => $source) {
    $paths[$moduleName] = $work . '/' . $moduleName . '.mog';
    \file_put_contents($paths[$moduleName], $source . "\n");
}

// The project a compile sees is each entry file's import closure; `Wide` is in
// it only because the project route hands its path over, never by import.
$projectFiles = [];
foreach ($paths as $path) {
    foreach (Modules\moduleFileClosureCached($path, 'php')[0] as $file) {
        $projectFiles[$file] = true;
    }
}
$projectFiles = \array_keys($projectFiles);
\sort($projectFiles);
$mainClosure = Modules\moduleFileClosureCached($paths['Main'], 'php')[0];
\sort($mainClosure);

/** The project's shared library cache, which every route's own cache is seeded from. */
$sharedCache = Cache\cacheBaseDir();

/**
 * Give one route a cache of its own: the library products are hard-linked out of
 * the run's cache, everything the route derives stays in the new directory.
 */
$freshCache = static function (string $route) use ($work, $sharedCache): void {
    $dir = $work . '/cache-' . $route;
    @\mkdir($dir, 0700, true);

    \putenv('MOGGI_CACHE_DIR=' . $sharedCache);
    seedCacheDir($dir);
    \putenv('MOGGI_CACHE_DIR=' . $dir);

    Modules\ProjectCache::clearPreparedProjects();
    Modules\ProjectCache::clearCheckedModules();
};

/** @param list<string> $files */
$context = static fn (array $files): CompilationContext => CompilationContext::for(
    'php',
    CompilePurpose::Executable,
    entryModules: ['Main'],
    roots: [$work],
    sources: $files,
);

/** @param list<array<string, mixed>> $records */
$recordsOfModule = static function (array $records, string $module): array {
    return \array_values(\array_filter(
        $records,
        static fn (array $record): bool => $record['module'] === $module,
    ));
};

/** The modules a preparation's units name, minus the compiler's synthetic ones. */
$preparedModules = static function (array $units): array {
    $modules = [];
    foreach ($units as $moduleName => $unit) {
        if (($unit['synthetic'] ?? false) !== true) {
            $modules[] = (string) $moduleName;
        }
    }
    \sort($modules);

    return $modules;
};

try {
    // --- 1. The full project route: every path handed over at once. -----------
    $freshCache('project');
    $project = Modules\prepareProject($projectFiles, $work, $context($projectFiles));
    $projectMain = $project->checked['Main'];
    $projectWorld = $project->importContexts['Main']->instanceWorld;

    $assert($projectWorld !== null, 'the prepared project gave `Main` no instance world');
    $assert($projectMain->instanceWorld !== null, 'the checked program published no instance world identity');
    $assert(
        $projectMain->instanceWorld->digest === $projectWorld->id->digest,
        'the checked program published a different world than the one it was checked against',
    );
    $assert(
        $projectMain->instanceWorld->modules === $preparedModules($project->units),
        "the project world does not name the module set that was prepared:\n"
            . '  recorded: ' . \implode(', ', $projectMain->instanceWorld->modules) . "\n"
            . '  prepared: ' . \implode(', ', $preparedModules($project->units)),
    );
    foreach (['Base', 'Wide', 'Main'] as $moduleName) {
        $assert(
            \in_array($moduleName, $projectMain->instanceWorld->modules, true),
            "the project world is missing `{$moduleName}`",
        );
    }

    // --- 2. That world really holds the instance of the unimported module. ----
    $wide = $recordsOfModule($projectWorld->records, 'Wide');
    $assert(\count($wide) === 1, 'the project world lost `Wide`\'s instance');
    $assert($wide[0]['class'] === 'Tag', '`Wide`\'s record is not a `Tag` instance');
    $assert(
        Ast\dumpTypeInline($wide[0]['instanceHead']) === 'Widget',
        '`Wide`\'s record has the wrong head: ' . Ast\dumpTypeInline($wide[0]['instanceHead']),
    );
    $assert(
        \count($recordsOfModule($projectWorld->records, 'Main')) === 1,
        'the project world lost `Main`\'s own instance',
    );

    // --- 3. The focus route over the same set: same identity, same evidence. --
    $freshCache('focus');
    $focus = Modules\prepareProjectCached($projectFiles, $work, $context($projectFiles), 'Main');
    $focusMain = $focus->checked['Main'];
    $focusWorld = $focus->importContexts['Main']->instanceWorld;

    $assert($focusWorld !== null, 'the focus route gave `Main` no instance world');
    $assert(
        $focusMain->instanceWorld->digest === $projectMain->instanceWorld->digest,
        "the focus route published a different instance world for the same module set:\n"
            . '  project: ' . $projectMain->instanceWorld->digest
            . ' (' . $projectMain->instanceWorld->records . ' records over '
            . \count($projectMain->instanceWorld->modules) . " modules)\n"
            . '  focus:   ' . $focusMain->instanceWorld->digest
            . ' (' . $focusMain->instanceWorld->records . ' records over '
            . \count($focusMain->instanceWorld->modules) . " modules)\n"
            . '  modules only in project: '
            . \implode(', ', \array_diff($projectMain->instanceWorld->modules, $focusMain->instanceWorld->modules)) . "\n"
            . '  modules only in focus:   '
            . \implode(', ', \array_diff($focusMain->instanceWorld->modules, $projectMain->instanceWorld->modules)),
    );
    $assert(
        $focusMain->instanceWorld->modules === $projectMain->instanceWorld->modules,
        'the focus route published a different module set for the same modules',
    );
    $assert(
        $focusMain->instanceWorld->records === $projectMain->instanceWorld->records,
        'the focus route published a different number of instance records',
    );
    $canonical = static fn (array $records): array => \array_map(
        'Moggi\\Modules\\canonicalInstanceRecord',
        $records,
    );
    $assert(
        $canonical($focusWorld->records) === $canonical($projectWorld->records),
        'the two routes disagree on the instance records themselves',
    );

    $projectGreet = $projectMain->core?->function('greet');
    $assert($projectGreet !== null, 'the project check published no checked-core record for `greet`');
    $focusGreet = $focusMain->core?->function('greet');
    $assert($focusGreet instanceof CheckedFunction, 'the focus check published no checked-core record for `greet`');
    $assert(
        $focusGreet->evidence === $projectGreet->evidence,
        "the two routes disagree on `greet`'s evidence vector:\n"
            . '  project: ' . \json_encode($projectGreet->evidence) . "\n"
            . '  focus:   ' . \json_encode($focusGreet->evidence),
    );
    $assert(
        $focusGreet->evidence !== [],
        '`greet` carries a `Tag a` obligation but its record has no evidence parameter',
    );
    $assert(
        Types\typeToString($focusGreet->scheme->type) === Types\typeToString($projectGreet->scheme->type),
        'the two routes disagree on `greet`\'s checked scheme: '
            . Types\typeToString($focusGreet->scheme->type) . ' vs '
            . Types\typeToString($projectGreet->scheme->type),
    );

    // --- 4. A route that sees a smaller set says so. -------------------------
    $freshCache('narrow');
    $narrow = Modules\prepareProjectCached($mainClosure, $work, $context($mainClosure), 'Main');
    $narrowMain = $narrow->checked['Main'];
    $narrowWorld = $narrow->importContexts['Main']->instanceWorld;

    $assert($narrowWorld !== null, 'the narrower route gave `Main` no instance world');
    $assert(
        $narrowMain->instanceWorld->digest !== $projectMain->instanceWorld->digest,
        'a preparation without `Wide` published the three-module project\'s instance world',
    );
    $assert(
        $narrowMain->instanceWorld->modules === $preparedModules($narrow->units),
        'the narrower world does not name the set it prepared',
    );
    $assert(
        ! \in_array('Wide', $narrowMain->instanceWorld->modules, true),
        'the narrower world names `Wide`, which it never prepared',
    );
    $assert(
        $recordsOfModule($narrowWorld->records, 'Wide') === [],
        'the narrower world still holds `Wide`\'s instance',
    );
    $assert(
        \count($recordsOfModule($narrowWorld->records, 'Main')) === 1,
        'the narrower world lost `Main`\'s own instance',
    );
    $assert(
        $narrowMain->instanceWorld->digest === $narrowWorld->id->digest,
        'the narrower check published a different world than the one it was checked against',
    );

    // --- 5. The single-file entry point publishes that same smaller world. ---
    $freshCache('single-file');
    $single = Modules\moduleCompileContext($paths['Main'], $context($mainClosure));
    $singleProgram = $single['typedProgram'];
    $assert($singleProgram->instanceWorld !== null, 'the single-file route published no instance world identity');
    $assert(
        $singleProgram->instanceWorld->digest === $narrowMain->instanceWorld->digest,
        "two routes over the same module set published different worlds:\n"
            . '  focus:       ' . $narrowMain->instanceWorld->digest . "\n"
            . '  single-file: ' . $singleProgram->instanceWorld->digest,
    );
    $assert(
        $singleProgram->instanceWorld->modules === $preparedModules($single['units']),
        'the single-file route\'s identity does not name its own module set',
    );

    // --- 6. The identity is a function of the records, not of their order. ---
    $forward = InstanceWorld::over($projectWorld->records, $projectMain->instanceWorld->modules);
    $reverse = InstanceWorld::over(
        \array_reverse($projectWorld->records),
        \array_reverse($projectMain->instanceWorld->modules),
    );
    $assert(
        $forward->id->digest === $reverse->id->digest,
        'the same records in a different order produced a different instance world digest',
    );
    $assert(
        \array_map(static fn (array $record): string => $record['module'], $forward->records)
            === \array_map(static fn (array $record): string => $record['module'], $reverse->records),
        'the same records in a different order were not put into one canonical order',
    );

    // --- 7. A record outside the named set is refused, not silently added. ---
    $refused = false;
    try {
        InstanceWorld::over(
            [[
                'module' => 'Ghost',
                'class' => 'Tag',
                'instanceHead' => new Ast\TypeCon('Widget'),
                'constraints' => [],
                'associatedEquations' => [],
            ]],
            $projectMain->instanceWorld->modules,
        );
    } catch (\LogicException) {
        $refused = true;
    }
    $assert($refused, 'a world accepted a record of a module outside its module set');

    echo "instance world tests passed\n";
} finally {
    \putenv('MOGGI_CACHE_DIR=' . $sharedCache);
    removeDirectory($work);
}
