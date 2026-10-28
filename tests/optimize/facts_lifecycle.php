<?php declare(strict_types=1);

/**
 * Who owns the facts an optimize run reads, and for how long.
 *
 * The passes ask one shared owner what the program is; a run installs it. Nothing creates one
 * implicitly, a run that finds one already installed leaves it in place (whole-program
 * specialization optimizes modules inside its own owner), and the run that installed it clears it on
 * the way out — so a pass can never answer from a program that has finished compiling.
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
        entryKind: IR\EntryPointKind::Main,
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
$check(! $owner->isEvidence('__ev_Unrelated_9'), 'a name that only looks like evidence was answered as a table');
specializeAcrossModules([]);
$check(Facts::installed() === $owner, 'the whole-program run replaced the owner it found instead of restoring it');
Facts::clear();
$check(Facts::installed() === null, 'clearing an installed owner did not release it');

if ($failures !== []) {
    foreach ($failures as $failure) {
        \fwrite(STDERR, 'FAIL ' . $failure . "\n");
    }
    exit(1);
}

echo "ok\n";
