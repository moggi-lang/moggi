<?php declare(strict_types=1);

/**
 * Who owns the facts an optimize run reads, and for how long.
 *
 * The passes ask one shared owner what the program is; a run installs it. Nothing creates one
 * implicitly, and the run that installed it restores what it found on the way out — so a pass can
 * never answer from a program that has finished compiling.
 *
 * A run nested inside an installed owner gets its *own* child owner, seeded with the program-wide
 * facts and restored afterwards. The published function index and callable table describe the one
 * body being rewritten, so a nested run must not publish them onto its host (whole-program
 * specialization optimizes module after module inside one owner); the evidence tables and CAF
 * trees it discovers are program-wide, so those are handed up.
 *
 * The owner also learns the evidence tables of the module it was handed at the point the run starts,
 * so a first per-module pass and a whole-program pass answer the same question about a dictionary.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\Optimize\Facts\Facts;

use function Moggi\Optimize\Specialize\specializeAcrossModules;
use function Moggi\Optimize\optimize;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$check(Facts::installed() === null, 'an owner was installed before any run asked for one');

$refused = false;
try {
    Facts::current();
} catch (\LogicException) {
    $refused = true;
}
$check($refused, 'a pass that asks for facts without an owner invented one instead of failing');

/** @param list<IR\InstanceEvidence> $evidence */
$module = static fn (array $evidence = []): IR\Module => new IR\Module(
    [new IR\FunctionDecl(
        'main',
        [],
        null,
        new IR\Block([new IR\Ret(new IR\ConstInt(1))]),
        new IR\FunctionShape(entryKind: IR\EntryPointKind::Main),
    )],
    [],
    $evidence,
    new IR\EntryPoint('main', IR\EntryPointKind::Main),
    'Main',
);

$optimized = optimize($module());
$check($optimized instanceof IR\Module, 'a top-level run did not optimize');
$check(Facts::installed() === null, 'the run that installed the owner did not clear it');

$owner = new Facts();
Facts::install($owner);
optimize($module());
$check(Facts::installed() === $owner, 'a run inside an installed owner replaced or cleared it');

optimize($module([new IR\InstanceEvidence('__ev_Foo_Int', ['show' => 'showInt'])]));
$check($owner->isEvidence('__ev_Foo_Int'), 'the run did not publish the module\'s own evidence table onto the owner it found');
$check($owner->isEvidence('Main::__ev_Foo_Int'), 'the owner did not answer the qualified spelling of the table it published');
$check(
    $owner->callableTable === null,
    'a nested run published its own callable table onto the owner it found: the host run would '
    . 'then read one module\'s layouts while rewriting another',
);
$check(
    $owner->fnsIn('Main') === [],
    'a nested run left the function index of the body it optimized on the owner it found',
);
$check(! $owner->isEvidence('__ev_Unrelated_9'), 'a name that only looks like evidence was answered as a table');
specializeAcrossModules([]);
$check(Facts::installed() === $owner, 'the whole-program run replaced the owner it found instead of restoring it');

// The function facts carry the revision they were published for. A reader that captured the
// revision before a rewrite cannot read the map afterwards: the map describes the old program.
$facts = new Facts();
Facts::install($facts);
$before = new IR\FunctionDecl(
    'bump',
    ['n'],
    null,
    new IR\Block([new IR\Ret(new IR\Local('n'))]),
);
$after = $before->withBody(new IR\Block([new IR\Ret(new IR\ConstInt(2))]));
$facts->publishFnsByModule(['Prov' => ['bump' => $before->shape->kind]]);
$revision = $facts->functionsRevision();
$check(
    $facts->fnsIn('Prov', $revision) === ['bump' => $before->shape->kind],
    'a reader could not read the facts it had just captured the revision of',
);
$facts->publishFnsByModule(['Prov' => ['bump' => $after->shape->kind, 'bump2' => $after->shape->kind]]);
$stale = null;
try {
    $facts->fnsIn('Prov', $revision);
} catch (\LogicException $e) {
    $stale = $e->getMessage();
}
$check($stale !== null, 'a fact set published before the body changed was read after it');
$check(
    $stale !== null && \str_contains($stale, 'cannot be read after it'),
    'the refusal does not say the facts are older than the reader: ' . ($stale ?? '<no failure>'),
);
$check(
    $facts->fnsIn('Prov', $facts->functionsRevision()) === ['bump' => $after->shape->kind, 'bump2' => $after->shape->kind],
    'a reader at the current revision was refused or answered with the old map',
);
$check(
    $facts->fnsIn('Prov') === ['bump' => $after->shape->kind, 'bump2' => $after->shape->kind],
    'an unguarded read changed behaviour; it still answers the latest published map',
);
$staleExternal = null;
try {
    $facts->externalFnsIn('Prov', $revision);
} catch (\LogicException $e) {
    $staleExternal = $e->getMessage();
}
$check($staleExternal !== null, 'the external-function map answered a pre-rewrite revision');
Facts::clear();
$check(Facts::installed() === null, 'clearing an installed owner did not release it');

if ($failures !== []) {
    foreach ($failures as $failure) {
        \fwrite(STDERR, 'FAIL ' . $failure . "\n");
    }
    exit(1);
}

echo "ok\n";
