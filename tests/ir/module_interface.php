<?php declare(strict_types=1);

/**
 * A module's interface is one value; the `codegen` array emitters read is
 * a rendering of it.
 *
 * What this holds down:
 *   1. Every prepared module — checked, hydrated or shallow — publishes one
 *      `ImportInterface`, with required regions present even when empty and
 *      the optional optimizer region empty.
 *   2. The checked program carries the interface itself, not copies of its
 *      maps; the import context keeps no second rendering.
 *   3. The emission plan is rendered per target from the interface: ABI facts
 *      come from the interface and require lines from the modules the IR
 *      calls, so a plan needs the IR and there is no IR-less planning.
 *   4. Re-exports preserve the declaring module; a module never publishes
 *      its own names as imports.
 *   5. Importers read the callable layout published by the defining module.
 *   6. The pipeline refuses a checked program that carries no import
 *      interface.
 *   7. The emitted set is the entry's closure, and a require naming a module
 *      the compile does not emit is refused.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/assert.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Modules;
use Moggi\Modules\EmitPlan;
use Moggi\Modules\ImportInterface;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Pipeline\PipelineRequest;
use Moggi\Pipeline\PipelineStage;
use Moggi\Syntax\Ast;

use function Moggi\IR\Visit\collectIrCodegenUsage;
use function Moggi\Modules\planEmission;
use function Moggi\Pipeline\run as pipelineRun;

\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');

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
$module = static function (array $importContexts, string $name): ImportInterface {
    $interface = ($importContexts[$name] ?? null)?->interface;
    if (! $interface instanceof ImportInterface) {
        throw new \RuntimeException("module `{$name}` published no ImportInterface");
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

$ir = pipelineRun(new PipelineRequest(
    context: $context,
    filename: $mainPath,
    typedProgram: $prepared->checked['Main'],
    importContext: $prepared->importContexts['Main'],
), PipelineStage::Ir)->ir;
$check($ir !== null, 'the prepared module lowered no IR');
$planned = planEmission($main, $layout, $ir, collectIrCodegenUsage($ir)['callees']);
$check(
    $planned->importedData === $main->data,
    'the plan dropped an imported type fact; constructor renames and data travel whole because a '
        . 'match arm names a constructor that is not a callee',
);
$check(
    $planned->constructorRenames === $main->constructorRenames,
    'the plan dropped a constructor rename the emitted code may still need',
);
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
    ! \in_array("__DIR__ . '/Main.php'", $planned->requireLines, true),
    'the plan required the module\'s own artifact: ' . \json_encode($planned->requireLines),
);
$check(
    $planned->externalFns !== []
        && \array_diff_key($planned->externalFns, $main->externalFns) === [],
    'the plan invented an external the interface never published: '
        . \json_encode(\array_keys($planned->externalFns)),
);
$check(
    isset($planned->externalFns['bump'], $planned->externalFns['provValue']),
    'the plan dropped an entry the module calls: ' . \json_encode(\array_keys($planned->externalFns)),
);
$check(
    ! isset($planned->externalFns['putStrLn']),
    'the plan kept an entry the module only reaches through its worker: '
        . \json_encode(\array_keys($planned->externalFns)),
);
$check($planned->functionUseLines !== [], 'the plan planned no `use function` lines for called entries');
$check(
    \array_diff_key($planned->externalFnRuntimeArity, $planned->externalFns) === [],
    'the plan pruned an external but kept its runtime arity: '
        . \json_encode(\array_keys(\array_diff_key($planned->externalFnRuntimeArity, $planned->externalFns))),
);

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

$carried = $prepared->checked['Main']->interface;
$check($carried instanceof ImportInterface, 'the checked program carries no import interface');
$check(
    $carried->externalFns === $main->externalFns
        && $carried->callableArity() === $main->callableArity()
        && $carried->fnOriginModules === $main->fnOriginModules,
    'the checked program carries a different interface than the import context',
);

// ---- 4 · identity survives a re-export, locals are not imports ------------

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

// ---- 6 · the pipeline refuses a checked program without its import interface ----

$stripped = new Ast\Program(items: $prepared->checked['Main']->items, module: 'Main');
$refusedLowering = false;
try {
    pipelineRun(new PipelineRequest(
        context: $context,
        filename: $mainPath,
        typedProgram: $stripped,
        importContext: $prepared->importContexts['Main'],
    ), PipelineStage::Ir);
} catch (\LogicException $e) {
    $refusedLowering = \str_contains($e->getMessage(), 'import interface');
}
$check($refusedLowering, 'the pipeline lowered a checked program that carries no import interface');

// ---- 7 · the emitted set is the entry's own closure, and a gap is refused ----

$planRequiring = static fn (string $relative, array $requires): EmitPlan => new EmitPlan(
    backendId: $backendId,
    outputRelative: $relative,
    requiredModules: \array_fill_keys($requires, true),
);
$closure = Modules\emittedClosure(
    [
        'Main' => $planRequiring('Main.php', ['Prov']),
        'Prov' => $planRequiring('Prov.php', ['Leaf']),
        'Leaf' => $planRequiring('Leaf.php', []),
        'Unused' => $planRequiring('Unused.php', []),
    ],
    'Main',
);
\sort($closure);
$check(
    $closure === ['Leaf', 'Main', 'Prov'],
    'the emitted set is not the entry\'s transitive closure: ' . \json_encode($closure),
);

$refusedGap = false;
try {
    Modules\emittedClosure([
        'Main' => $planRequiring('Main.php', ['Missing']),
    ], 'Main');
} catch (\LogicException $e) {
    $refusedGap = \str_contains($e->getMessage(), 'does not emit');
}
$check($refusedGap, 'the compiler accepted a require naming a module it does not emit');

$refusedMissingEntry = false;
try {
    Modules\emittedClosure([], 'Main');
} catch (\LogicException $e) {
    $refusedMissingEntry = \str_contains($e->getMessage(), 'no emission plan');
}
$check($refusedMissingEntry, 'the compiler emitted a project whose entry has no plan');

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " module-interface failure(s):\n  " . \implode("\n  ", $failures),
    );
}

echo "module interface tests passed\n";
