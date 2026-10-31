<?php declare(strict_types=1);

/**
 * What a discard and a reuse may drop, asked of the effect model the passes share.
 *
 * A computation that can throw or diverge is observable even when its result is never read, so DCE
 * must keep it; a construction or a total call is not, so DCE still deletes it. CSE reuses a call's
 * result, which is the same question, and it identifies calls by a typed structural key, so two
 * arguments that merely read alike never collapse. A call's arguments are evaluated before it runs, so
 * their evaluation belongs to both questions — but a function reference passed along is not applied.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\Optimize\Effects\Analysis;
use Moggi\Optimize\Effects\Model;
use Moggi\Optimize\Facts\Facts;

use function Moggi\Optimize\Effects\scanBody;
use function Moggi\Optimize\GlobalPass\cseBlock;
use function Moggi\Optimize\GlobalPass\dceBlock;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$block = static fn (array $items): IR\Block => new IR\Block($items);
$function = static fn (string $name, IR\Block $body): IR\FunctionDecl => new IR\FunctionDecl($name, ['n'], null, $body);

$total = $function('total', $block([new IR\Ret(new IR\Intrinsic('intAdd#', [new IR\Local('n'), new IR\ConstInt(1)]))]));
$faulting = $function('faulting', $block([new IR\Ret(new IR\Intrinsic('intDiv#', [new IR\Local('n'), new IR\ConstInt(0)]))]));
$indirectBody = $function('indirect', $block([
    new IR\Call('faulting', [new IR\ConstInt(1)], 90),
    new IR\Ret(new IR\Temp(90)),
]));
// The same call one edge away, spelled three ways: a direct call, a function value applied, and a
// partial application. The IR names the callee in all three, so all three must answer the same.
$indirectApply = $function('indirectApply', $block([
    new IR\CallValue(new IR\FnRef('faulting'), [new IR\ConstInt(1)], 91),
    new IR\Ret(new IR\Temp(91)),
]));
$indirectPartial = $function('indirectPartial', $block([
    new IR\CallValue(new IR\Partial('faulting', 1, [new IR\ConstInt(1)]), [], 92),
    new IR\Ret(new IR\Temp(92)),
]));

// The model asks the run's facts owner which entries a reference runs, and DCE asks it which locals a
// reference to a lifted callable keeps alive, so a case that builds a model directly hands it the
// owner a pass driving an optimize run would, minus the lifted callables this module has.
$facts = new Facts();
$facts->publishLiftedCaptureNeeds([]);

$module = [$total, $faulting, $indirectBody, $indirectApply, $indirectPartial];
$effects = Model::forFunctions($module, '', $facts);

$check($effects->ofCall('total')->discardIsObservable() === false, 'a total call should be discardable');
$check($effects->ofCall('faulting')->discardIsObservable(), 'a call reaching a faulting primop should not be discardable');
$check($effects->ofCall('indirect')->discardIsObservable(), 'a call reaching one through a callee should not be discardable');
$check(
    $effects->ofCall('indirectApply')->discardIsObservable(),
    'a throw one applied function value away should not be discardable',
);
$check(
    $effects->ofCall('indirectPartial')->discardIsObservable(),
    'a throw one partial application away should not be discardable',
);

$appliedNames = scanBody($indirectApply->body);
$check(isset($appliedNames['names']['faulting']), 'an applied function value must name its callee as an edge');
$partialNames = scanBody($indirectPartial->body);
$check(isset($partialNames['names']['faulting']), 'an applied partial must name its callee as an edge');
$throughTemp = scanBody($block([
    new IR\CallValue(new IR\Temp(5), [new IR\ConstInt(1)], 6),
    new IR\Ret(new IR\Temp(6)),
]));
$check($throughTemp['unresolved'], 'a callee the IR does not name must be reported unresolved');

$dispatches = $function('dispatches', $block([
    new IR\DictCall(new IR\Temp(1), 'method', [], 2),
    new IR\Ret(new IR\Temp(2)),
]));
$check(
    Model::forFunctions([$dispatches])->ofCall('dispatches')->mayThrow,
    'a body that dispatches through a dictionary may throw',
);

$guarded = $function('guarded', $block([
    new IR\MatchReturn(
        new IR\Local('n'),
        [new IR\MatchArm(
            new IR\PatWild(),
            $block([new IR\Ret(new IR\ConstInt(0))]),
            [new IR\Guard($block([new IR\Call('faulting', [new IR\ConstInt(1)], 7)]), new IR\Temp(7))],
        )],
        true,
    ),
]));
$check(
    Model::forFunctions([$guarded, $faulting])->ofCall('guarded')->mayThrow,
    "a throw in a guard's preparation must be seen",
);
$check(
    $effects->ofCall('Other::imported')->discardIsObservable(),
    'a callee outside the module should be conservative',
);
$check(
    $effects->ofCall('__field0')->discardIsObservable() === false,
    'a generated entry of this module should stay discardable',
);
$looping = Model::forFunctions([
    $function('loop', $block([new IR\Loop($block([new IR\Ret(new IR\Unit())]))])),
    $total,
]);
$check($looping->ofCall('loop')->mayDiverge, 'a body with a loop should be able to diverge');
$check(
    $looping->ofCall('total')->mayDiverge === false,
    'a function after a loop was classified by its predecessor',
);

$kept = dceBlock($block([
    new IR\Call('faulting', [new IR\ConstInt(1)], 10),
    new IR\Ret(new IR\Unit()),
]), $effects);
$check(\count($kept->items) === 2, 'DCE dropped a call that can throw');

$keptApply = dceBlock($block([
    new IR\CallValue(new IR\FnRef('indirectApply'), [new IR\ConstInt(1)], 11),
    new IR\Ret(new IR\Unit()),
]), $effects);
$check(\count($keptApply->items) === 2, 'DCE dropped an apply whose callee throws one edge away');

$dropped = dceBlock($block([
    new IR\Call('total', [new IR\ConstInt(1)], 10),
    new IR\Ret(new IR\Unit()),
]), $effects);
$check(\count($dropped->items) === 1, 'DCE kept a total call nothing reads');

$stringArg = cseBlock($block([
    new IR\Call('total', [new IR\ConstStr('1')], 10),
    new IR\Call('total', [new IR\ConstInt(1)], 11),
    new IR\Ret(new IR\Unit()),
]), Model::forFunctions([$total]));
$check(
    $stringArg->items[1] instanceof IR\Call,
    'CSE collapsed a string argument into the number it reads like',
);

$same = cseBlock($block([
    new IR\Call('total', [new IR\ConstInt(1)], 10),
    new IR\Call('total', [new IR\ConstInt(1)], 11),
    new IR\Ret(new IR\Unit()),
]), Model::forFunctions([$total]));
$check(
    \count($same->items) === 3
        && $same->items[1] instanceof IR\Assign
        && $same->items[1]->value instanceof IR\Temp
        && $same->items[1]->value->id === 10,
    'CSE no longer reuses an identical call',
);

// A total callee is not the whole question: the arguments are evaluated here, so one that throws keeps
// the call alive and keeps two identical calls apart. A function value in argument position is a value,
// though — it is not applied — so passing one must not make the call unshareable.
$throwingArg = new IR\Intrinsic('intDiv#', [new IR\ConstInt(1), new IR\ConstInt(0)]);
$keptArg = dceBlock($block([
    new IR\Call('total', [$throwingArg], 12),
    new IR\Ret(new IR\Unit()),
]), $effects);
$check(\count($keptArg->items) === 2, 'DCE dropped a total call whose argument throws');

$fnArgDropped = dceBlock($block([
    new IR\Call('total', [new IR\FnRef('faulting')], 13),
    new IR\Ret(new IR\Unit()),
]), $effects);
$check(\count($fnArgDropped->items) === 1, 'DCE kept a call only because a function value was passed to it');

$keptArgCse = cseBlock($block([
    new IR\Call('total', [$throwingArg], 14),
    new IR\Call('total', [$throwingArg], 15),
    new IR\Ret(new IR\Unit()),
]), $effects);
$check($keptArgCse->items[1] instanceof IR\Call, 'CSE shared a call whose argument throws');

$fnArgCse = cseBlock($block([
    new IR\Call('total', [new IR\FnRef('faulting')], 16),
    new IR\Call('total', [new IR\FnRef('faulting')], 17),
    new IR\Ret(new IR\Unit()),
]), $effects);
$check(
    $fnArgCse->items[1] instanceof IR\Assign && $fnArgCse->items[1]->value instanceof IR\Temp,
    'CSE stopped sharing two identical calls that only pass a function value',
);

// A reference to an entry that takes *no* parameters is not a value: the backends force it into a call,
// so passing one evaluates work in argument position and the same arity question decides both halves.
// The effectful one keeps the call alive and apart; the pure one is still a construction.
$quirk = new IR\FunctionDecl('quirk', [], null, $block([
    new IR\Ret(new IR\Intrinsic('intDiv#', [new IR\ConstInt(1), new IR\ConstInt(0)])),
]));
$pureCaf = new IR\FunctionDecl('pureCaf', [], null, $block([new IR\Ret(new IR\ConstInt(7))]));
$facts->publishFunctions([...$module, $quirk, $pureCaf]);
$withCafs = Model::forFunctions([...$module, $quirk, $pureCaf], '', $facts);

$check(
    $withCafs->evaluationOf(new IR\FnRef('quirk'))->discardIsObservable(),
    'evaluating a reference to a parameterless effectful entry was treated as producing a value',
);
$check(
    $withCafs->evaluationOf(new IR\FnRef('pureCaf'))->discardIsObservable() === false,
    'a reference to a parameterless total entry should still be a construction',
);
$check(
    $withCafs->evaluationOf(new IR\FnRef('total'))->discardIsObservable() === false,
    'a reference to a function that takes parameters is a closure, not an evaluation',
);

$keptCafArg = dceBlock($block([
    new IR\Call('total', [new IR\FnRef('quirk')], 18),
    new IR\Ret(new IR\Unit()),
]), $withCafs);
$check(\count($keptCafArg->items) === 2, 'DCE dropped a total call whose argument runs a parameterless entry');

$pureCafArg = dceBlock($block([
    new IR\Call('total', [new IR\FnRef('pureCaf')], 19),
    new IR\Ret(new IR\Unit()),
]), $withCafs);
$check(\count($pureCafArg->items) === 1, 'DCE kept a call whose argument is a parameterless total entry');

$cafArgCse = cseBlock($block([
    new IR\Call('total', [new IR\FnRef('quirk')], 20),
    new IR\Call('total', [new IR\FnRef('quirk')], 21),
    new IR\Ret(new IR\Unit()),
]), $withCafs);
$check($cafArgCse->items[1] instanceof IR\Call, 'CSE shared two calls whose argument runs a parameterless entry');

// The run's one analysis owner answers for the program it was handed. An unchanged program is one scan and
// one model; a rewritten body has to be a different one — and an `ioEffect` mark is the change a pass makes
// in place rather than by replacing the declaration, so the owner must see that too.
$analysis = new Analysis();
$first = $analysis->modelFor($module, '', $facts);
$check(
    $first === $analysis->modelFor($module, '', $facts),
    'the analysis owner rebuilt a model for an unchanged program',
);

$quiet = $faulting->withBody($block([new IR\Ret(new IR\ConstInt(0))]));
$rewritten = $analysis->modelFor([$total, $quiet, $indirectBody, $indirectApply, $indirectPartial], '', $facts);
$check($rewritten !== $first, 'the analysis owner kept its model across a rewritten body');
$check(
    $rewritten->ofCall('faulting')->discardIsObservable() === false,
    'the analysis owner answered from the scan of the body it replaced',
);

// A shape is a readonly record, so marking a declaration is a replacement, not a field write.
$marked = new IR\FunctionDecl('faulting', ['n'], null, $quiet->body);
$marked->shape = $marked->shape->with(['ioEffect' => true]);
$withIo = $analysis->modelFor([$total, $marked, $indirectBody, $indirectApply, $indirectPartial], '', $facts);
$check($withIo !== $rewritten, 'the analysis owner kept its model across an adopted io mark');
$check($withIo->ofCall('faulting')->executes, 'the analysis owner missed an io mark while reusing its scan');

if ($failures !== []) {
    foreach ($failures as $failure) {
        \fwrite(STDERR, 'FAIL ' . $failure . "\n");
    }
    exit(1);
}

echo "ok\n";
