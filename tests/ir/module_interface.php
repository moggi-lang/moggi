<?php declare(strict_types=1);

// A module's interface is one value, and the `codegen` array the emitters read is a
// rendering of it (D08 / A8).
//
// What this holds down:
//   1. every module the preparation touches — checked, hydrated, or shallow — publishes
//      one `ModuleInterface`, with the required regions present even when empty and
//      the optional optimizer region empty;
//   2. the checked program carries the interface itself, not a copy of its maps, and the
//      import context keeps no second rendering of it;
//   3. the emission plan is rendered per target from the interface: its ABI facts are the
//      interface's, its require lines are the modules the IR calls, and a plan rendered
//      before the IR exists publishes everything and plans no file;
//   4. an entry keeps its declaring module across a re-export chain, and a module's own
//      names are never published as imports;
//   5. the callable layout an importer reads is the defining module's published record.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/assert.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Modules;
use Moggi\Modules\EmitPlan;
use Moggi\Modules\ModuleInterface;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Pipeline\PipelineRequest;
use Moggi\Pipeline\PipelineStage;

use function Moggi\Modules\planEmission;
use function Moggi\Pipeline\run as pipelineRun;

\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');

// Outside the compiler's own cache root: `.moggi` is emptied whenever a fingerprint
// changes, which would delete this test's sources mid-compile.
$scratch = createTempDir('moggi-module-interface');
\register_shutdown_function(static function () use ($scratch): void {
    removeDirectory($scratch);
});

/** @var list<string> $failures */
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$write = static function (string $relative, string $source) use ($scratch): string {
    $path = $scratch . '/' . $relative;
    $dir = \dirname($path);
    if (! \is_dir($dir)) {
        \mkdir($dir, 0o777, true);
    }
    \file_put_contents($path, $source);

    return $path;
};

// A re-export chain: `Prov` declares, `Hub` re-exports it as its own, `Main` imports the hub.
$write('Prov.mog', <<<'MOGGI'
module Prov (ProvData(..), provValue, bump) where

data ProvData = ProvData Int

provValue :: Int
provValue = 41

bump :: Int -> Int
bump n = n + 1
MOGGI);
$write('Hub.mog', <<<'MOGGI'
module Hub (module Prov) where

import Prov
MOGGI);
$mainPath = $write('Main.mog', <<<'MOGGI'
module Main where

import Hub

main :: IO ()
main = putStrLn (show (bump provValue))
MOGGI);

$backendId = \Moggi\Backend\compileBackend();
[$files, $dir] = Modules\projectSourceClosure(
    [$mainPath],
    [$scratch, $root . '/libraries/base/src'],
    $backendId,
);
$context = CompilationContext::for(
    $backendId,
    CompilePurpose::Executable,
    entryModules: ['Main'],
    roots: [$scratch, $root . '/libraries/base/src'],
    sources: $files,
);

$prepared = Modules\prepareProject($files, $dir, $context);
$module = static function (array $importContexts, string $name): ModuleInterface {
    $interface = ($importContexts[$name] ?? null)?->interface;
    if (! $interface instanceof ModuleInterface) {
        throw new \RuntimeException("module `{$name}` published no ModuleInterface");
    }

    return $interface;
};

$main = $module($prepared->importContexts, 'Main');
$check($main->module === 'Main', 'the interface does not carry its own module name');

// ---- 1 · regions present, summaries empty ----------------------------------

$check($main->summaries === [], 'the optional summary region is not empty by default');
$check(
    $main->externalFns !== [] && $main->fnOriginModules !== [],
    'a module with imports published an empty ABI region',
);
$check(
    \is_array($main->env) && \is_array($main->data) && \is_array($main->typeSynonyms)
        && \is_array($main->qualifiedModules) && \is_array($main->qualifiedOrigins)
        && \is_array($main->qualifiedEnv) && \is_array($main->intrinsicWrappers)
        && \is_array($main->ambiguousNames) && \is_array($main->constructorRenames)
        && \is_array($main->externalFnRuntimeArity) && \is_array($main->callables)
        && \is_array($main->callables) && \is_array($main->actionReturn),
    'the required ABI regions are not all arrays',
);

// ---- 2 · one interface, and no second rendering of it ----------------------

$check(
    ! \property_exists($prepared->importContexts['Main'], 'codegen'),
    'the import context still renders a `codegen` view beside the interface',
);

// ---- 3 · the emission plan is rendered from the interface ------------------

$layout = $prepared->importContexts['Main']->plan;
$check($layout instanceof EmitPlan, 'the import context published no EmitPlan');
$check(
    $layout instanceof EmitPlan && $layout->backendId === $backendId,
    'the plan does not name the target it was laid out for',
);
$check(
    $layout instanceof EmitPlan && $layout->externalFns === [],
    'the target layout already holds ABI facts; the plan is rendered from the interface, not stored',
);

// Without the IR (the REPL's non-emitting option builder) every ABI fact is published and
// no file is planned: planning less must never take a fact away.
$unpruned = planEmission($main, $layout, null);
$check($unpruned->externalFns === $main->externalFns, 'a plan rendered without IR dropped an external entry');
$check($unpruned->requireLines === [], 'a plan rendered without IR planned a require line');
$check($unpruned->importedData === $main->data, 'the plan does not carry the imported data declarations');
$check($unpruned->externalFnRuntimeArity === $main->externalFnRuntimeArity, 'the plan disagrees with the interface on runtime arity');
$check($unpruned->constructorRenames === $main->constructorRenames, 'the plan disagrees with the interface on constructor renames');

// With the IR, the plan holds only what this module calls. `Hub` re-exports `Prov`, so nothing
// needs the hub's file; `Prov` is where every entry is written.
$ir = pipelineRun(new PipelineRequest(
    context: $context,
    filename: $mainPath,
    typedProgram: $prepared->checked['Main'],
    importContext: $prepared->importContexts['Main'],
), PipelineStage::Ir)->ir;
$check($ir !== null, 'the prepared module lowered no IR');
$planned = planEmission($main, $layout, $ir);
$check(
    isset($planned->moduleOutputPaths['Prov'], $planned->moduleOutputPaths['Hub']),
    'the plan lost a module the interface names: '
        . \json_encode(\array_keys($planned->moduleOutputPaths)),
);
$check(
    \in_array("__DIR__ . '/Prov.php'", $planned->requireLines, true),
    'the plan did not plan a require for the module the module calls: '
        . \json_encode($planned->requireLines),
);
$check(
    ! \in_array("__DIR__ . '/Hub.php'", $planned->requireLines, true),
    'the plan required a re-export hub it never calls: ' . \json_encode($planned->requireLines),
);
$check(
    $planned->externalFns !== []
        && \array_diff_key($planned->externalFns, $main->externalFns) === [],
    'the plan invented an external the interface never published: '
        . \json_encode(\array_keys($planned->externalFns)),
);
$check(
    isset($planned->externalFns['bump'], $planned->externalFns['provValue'], $planned->externalFns['putStrLn']),
    'the plan dropped an entry the module calls: ' . \json_encode(\array_keys($planned->externalFns)),
);
$check($planned->functionUseLines !== [], 'the plan planned no `use function` lines for called entries');
$check(
    \array_diff_key($planned->externalFnRuntimeArity, $planned->externalFns) === [],
    'the plan pruned an external but kept its runtime arity: '
        . \json_encode(\array_keys(\array_diff_key($planned->externalFnRuntimeArity, $planned->externalFns))),
);

// Emitting without a plan writes a module with no requires, no `use` lines and no imported
// constructors, so the emitters refuse it instead of degrading.
$refused = false;
try {
    \Moggi\Backend\codegenWith(\Moggi\Backend\backendById($backendId), $ir, $mainPath, [
        'outputRelative' => 'Main.php',
        'artifactModuleName' => 'Main',
    ]);
} catch (\RuntimeException $e) {
    $refused = \str_contains($e->getMessage(), 'EmitPlan');
}
$check($refused, 'an emitter accepted a module with no EmitPlan');

// The program lowering receives carries the interface, not a copy of its facts. `===` would
// only hold for a compile that read its program from memory; a hydrated one decodes the same
// value, so the facts are what the test holds to.
$carried = $prepared->checked['Main']->interface;
$check($carried instanceof ModuleInterface, 'the checked program carries no module interface');
$check(
    $carried->externalFns === $main->externalFns
        && $carried->callableArity() === $main->callableArity()
        && $carried->fnOriginModules === $main->fnOriginModules,
    'the checked program carries a different interface than the import context',
);

// ---- 3 · identity survives a re-export, locals are not imports ------------

$check(
    isset($main->externalFns['bump']),
    'an entry re-exported through a hub is missing from the importer: '
        . \json_encode(\array_keys($main->externalFns)),
);
$check(
    ($main->fnOriginModules['bump'] ?? null) === 'Prov',
    'a re-exported entry lost its declaring module: '
        . \json_encode($main->fnOriginModules['bump'] ?? null),
);
$check(
    ! isset($main->externalFns['main']),
    'the module published its own entry as an import',
);

// ---- 5 · callable facts match the declaring module's publication ----------

$provUnits = Modules\prepareProject($files, $dir, $context, shallow: true)->units;
$published = $provUnits['Prov']['facts']['callables']['entries']['bump'] ?? null;
$check(
    \is_array($published),
    'the shallow closure published no callable record for `bump`',
);
$check(
    ($main->callables['bump'] ?? null) === $published,
    'the importer\'s callable layout for `bump` is not the declaring module\'s record: '
        . \json_encode($main->callables['bump'] ?? null) . ' vs ' . \json_encode($published),
);
$check(
    ($main->callableArity()['bump'] ?? null) === ($published['arity'] ?? null),
    'the importer\'s callable arity for `bump` disagrees with the published layout',
);

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " module-interface failure(s):\n  " . \implode("\n  ", $failures),
    );
}

echo "module interface tests passed\n";
