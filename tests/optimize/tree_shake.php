<?php declare(strict_types=1);

/**
 * What whole-program shaking keeps, and what it must never drop.
 *
 * A function the program can still call has to survive: an entry, an export, a foreign binding, a method of
 * an instance-evidence table (the emitted table names its methods, so nothing in the IR does), and anything
 * such a declaration names. Everything else is dead weight — unless the name cannot be placed exactly, in
 * which case it is kept wherever it appears, because an ambiguous spelling is no evidence of absence.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR as IR;

use function Moggi\IR\Dump\dump;
use function Moggi\Optimize\TreeShake\treeShakeModules;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$fn = static function (
    string $name,
    array $items = [],
    bool $export = false,
    bool $foreign = false,
    ?IR\EntryPointKind $entryKind = null,
): IR\FunctionDecl {
    return new IR\FunctionDecl(
        name: $name,
        params: [],
        type: null,
        body: new IR\Block($items),
        export: $export,
        foreign: $foreign,
        entryKind: $entryKind,
    );
};

$ret = static fn (int $value): IR\Ret => new IR\Ret(new IR\ConstInt($value));
$call = static fn (string $callee): IR\Stmt => new IR\Call($callee, [], 1);

$module = static fn (string $name, array $functions, bool $entry = false, array $evidence = []): IR\Module => new IR\Module(
    $functions,
    [],
    $evidence,
    $entry ? new IR\EntryPoint($functions[0]->name, IR\EntryPointKind::Main) : null,
    $name,
);

$names = static function (IR\Module $module): array {
    return \array_map(static fn (IR\FunctionDecl $fn): string => $fn->name, $module->functions);
};

// ---- The root set, and the edges out of it ----

$program = [
    'Main' => $module('Main', [
        $fn('main', [$call('helper'), $call('Lib::calledHost')], entryKind: IR\EntryPointKind::Main),
        $fn('helper', [$call('helper2')]),
        $fn('helper2', [$ret(1)]),
        $fn('unreachable', [$ret(2)]),
    ], entry: true),
    'Lib' => $module('Lib', [
        $fn('published', [$ret(3)], export: true),
        $fn('calledHost', [$ret(4)], foreign: true),
        $fn('uncalledHost', [$ret(5)], foreign: true),
        $fn('unused', [$ret(6)]),
    ]),
];
$shaken = treeShakeModules($program);
$check($names($shaken['modules']['Main']) === ['main', 'helper', 'helper2'], 'the entry keeps what it calls, transitively');
$check(
    $names($shaken['modules']['Lib']) === ['published', 'calledHost'],
    'an export is a root, and a foreign binding only when something kept calls it',
);
$check($shaken['removed'] === 3 && $shaken['kept'] === 5, 'the counts report what was removed and kept');
$check($shaken['total'] === 8, 'the total counts every declaration the input had');

// ---- A method named only by the emitted evidence table ----

$evidence = new IR\InstanceEvidence('__ev_Describe_Color', ['describe' => 'describeColor']);
$withEvidence = [
    'Main' => $module('Main', [
        $fn('main', [$call('__ev_Describe_Color')], entryKind: IR\EntryPointKind::Main),
        $fn('__ev_Describe_Color', [$ret(0)]),
        $fn('describeColor', [$ret(5)]),
        $fn('deadMethodSibling', [$ret(6)]),
    ], entry: true, evidence: [$evidence]),
];

$shaken = treeShakeModules($withEvidence);
$check(
    $names($shaken['modules']['Main']) === ['main', '__ev_Describe_Color', 'describeColor'],
    'a method the evidence table names survives although no operand refers to it',
);

// ---- An evidence table nothing can reach goes with its methods ----

$unusedEvidence = [
    'Main' => $module('Main', [
        $fn('main', [$ret(1)], entryKind: IR\EntryPointKind::Main),
    ], entry: true, evidence: [
        new IR\InstanceEvidence('__ev_Old', ['method' => 'methodOfOld']),
    ]),
];

$shaken = treeShakeModules($unusedEvidence);
$check($names($shaken['modules']['Main']) === ['main'], 'an unreachable method goes with its table');
$check($shaken['modules']['Main']->instanceEvidence === [], 'and so does the table itself');

$dispatchStillThere = [
    'Main' => $module('Main', [
        $fn('main', [$call('usesDict')], entryKind: IR\EntryPointKind::Main),
        $fn('usesDict', [new IR\DictCall(new IR\Temp(7), 'method', [], 8)]),
        $fn('methodOfLive', [$ret(9)]),
        $fn('methodOfAlsoLive', [$ret(10)]),
    ], entry: true, evidence: [
        new IR\InstanceEvidence('__ev_Live', ['method' => 'methodOfLive']),
        new IR\InstanceEvidence('__ev_AlsoLive', ['method' => 'methodOfAlsoLive']),
    ]),
];

$shaken = treeShakeModules($dispatchStillThere);
$check(
    $names($shaken['modules']['Main']) === ['main', 'usesDict', 'methodOfLive', 'methodOfAlsoLive'],
    'a module that still dispatches keeps the tables a dict_call could name',
);

// ---- A table is kept for the method its module dispatches, not for dispatching at all ----

$methodScoped = [
    'Main' => $module('Main', [
        $fn('main', [$call('usesDict')], entryKind: IR\EntryPointKind::Main),
        $fn('usesDict', [new IR\DictCall(new IR\Temp(7), 'show', [], 8)]),
        $fn('showOf', [$ret(1)]),
        $fn('eqOf', [$ret(2)]),
    ], entry: true, evidence: [
        new IR\InstanceEvidence('__ev_Show', ['show' => 'showOf']),
        new IR\InstanceEvidence('__ev_Eq', ['eq' => 'eqOf']),
    ]),
];

$shaken = treeShakeModules($methodScoped);
$check(
    $names($shaken['modules']['Main']) === ['main', 'usesDict', 'showOf'],
    'a table naming no method its module dispatches goes with its methods',
);
$check(
    \array_map(static fn (IR\InstanceEvidence $e): string => $e->evidenceName, $shaken['modules']['Main']->instanceEvidence) === ['__ev_Show'],
    'the table that does name a dispatched method stays',
);

// ---- A closure keeps its worker and the function it captured ----

$closure = [
    'Main' => $module('Main', [
        $fn('main', [
            new IR\Assign(1, new IR\Partial('__w_lift', 1, [new IR\FnRef('captured')])),
            new IR\CallValue(new IR\Temp(1), [new IR\ConstInt(2)], 2),
            new IR\Ret(new IR\Temp(2)),
        ], entryKind: IR\EntryPointKind::Main),
        $fn('__w_lift', [$ret(3)]),
        $fn('captured', [$ret(4)]),
        $fn('spare', [$ret(5)]),
    ], entry: true),
];

$shaken = treeShakeModules($closure);
$check(
    $names($shaken['modules']['Main']) === ['main', '__w_lift', 'captured'],
    'a closure keeps the worker it names and the function value it captured',
);

// ---- A cross-module shake leaves the module it removed nothing from untouched ----

$crossModule = [
    'Main' => $module('Main', [
        $fn('main', [$ret(0)], entryKind: IR\EntryPointKind::Main),
        $fn('deadInMain', [$ret(1)]),
    ], entry: true),
    'Lib' => $module('Lib', [$fn('live', [$ret(2)], export: true)]),
];

$shaken = treeShakeModules($crossModule);
$check(
    dump($shaken['modules']['Lib']) === dump($crossModule['Lib']),
    'a module the shake removed nothing from is emitted byte-identically',
);
$check(
    dump($shaken['modules']['Main']) !== dump($crossModule['Main']),
    'and the module it did remove from is not',
);

// ---- A name that cannot be placed is kept everywhere ----

$ambiguous = [
    'Main' => $module('Main', [
        $fn('main', [$call('render')], entryKind: IR\EntryPointKind::Main),
        $fn('helper', [$ret(7)]),
    ], entry: true),
    'A' => $module('A', [$fn('render', [$ret(8)]), $fn('unusedA', [$ret(9)])]),
    'B' => $module('B', [$fn('render', [$ret(10)]), $fn('unusedB', [$ret(11)])]),
];

$shaken = treeShakeModules($ambiguous);
$check($names($shaken['modules']['A']) === ['render'], 'an unplaceable bare name keeps the declaration in module A');
$check($names($shaken['modules']['B']) === ['render'], 'and in module B, because the spelling does not say which one');

// ---- A bare name resolves in its own module before anywhere else ----

$localFirst = [
    'Main' => $module('Main', [
        $fn('main', [$call('render')], entryKind: IR\EntryPointKind::Main),
        $fn('render', [$ret(1)]),
    ], entry: true),
    'Lib' => $module('Lib', [$fn('render', [$ret(2)]), $fn('other', [$ret(3)])]),
];

$shaken = treeShakeModules($localFirst);
$check($names($shaken['modules']['Main']) === ['main', 'render'], 'a bare call keeps its own module\'s declaration');
$check(
    $names($shaken['modules']['Lib']) === [],
    'and leaves another module\'s same-named declaration dead instead of keeping every bucket',
);

// ---- A qualified name resolves to exactly one declaration ----

$qualified = [
    'Main' => $module('Main', [
        $fn('main', [new IR\Call('B::render', [], 1)], entryKind: IR\EntryPointKind::Main),
    ], entry: true),
    'A' => $module('A', [$fn('render', [$ret(8)])]),
    'B' => $module('B', [$fn('render', [$ret(10)]), $fn('spare', [$ret(11)])]),
];

$shaken = treeShakeModules($qualified);
$check($names($shaken['modules']['A']) === [], 'a qualified call leaves an unrelated same-named declaration dead');
$check($names($shaken['modules']['B']) === ['render'], 'and keeps the one it names');

// ---- Nothing is mutated, and an untouched module is returned as itself ----

$before = [\count($program['Main']->functions), \count($program['Lib']->functions)];
$shaken = treeShakeModules($program);
$check(
    [\count($program['Main']->functions), \count($program['Lib']->functions)] === $before,
    'shaking does not mutate the modules it was handed',
);
$check($shaken['modules']['Lib'] !== $program['Lib'], 'a module with a removal is rebuilt');

$keepAll = [
    'Main' => $module('Main', [
        $fn('main', [$call('helper')], entryKind: IR\EntryPointKind::Main),
        $fn('helper', [$ret(1)]),
    ], entry: true),
];
$shaken = treeShakeModules($keepAll);
$check($shaken['modules']['Main'] === $keepAll['Main'], 'a module with nothing removed is returned unchanged');
$check($shaken['removed'] === 0, 'and nothing is reported removed');

// ---- An entry that marks no declaration drops nothing ----

$inconsistent = ['Main' => new IR\Module(
    [$fn('main', [$ret(1)]), $fn('other', [$ret(2)])],
    [],
    [],
    new IR\EntryPoint('main', IR\EntryPointKind::Main),
    'Main',
)];
$shaken = treeShakeModules($inconsistent);
$check($shaken['modules'] === $inconsistent, 'an entry whose declaration is not marked keeps the program instead of emptying it');

// ---- A program without an entry is left alone ----

$library = ['Lib' => $module('Lib', [$fn('one', [$ret(1)]), $fn('two', [$ret(2)])])];
$shaken = treeShakeModules($library);
$check($shaken['modules'] === $library, 'without an entry nothing is a root, so nothing is removed');
$check(treeShakeModules([])['total'] === 0, 'an empty program shakes to nothing');

if ($failures !== []) {
    foreach ($failures as $failure) {
        \fwrite(STDERR, 'FAIL ' . $failure . "\n");
    }
    exit(1);
}

echo "ok\n";
