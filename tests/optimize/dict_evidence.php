<?php declare(strict_types=1);

/**
 * A `dict_call` becomes a direct call only when the evidence it dispatches on is known.
 *
 * Two facts decide it, and neither may be assumed: which instance the evidence names, and whether the
 * method it resolves to is a function this pass can see. Unknown evidence leaves the statement exactly
 * as it was — a dictionary dispatch at runtime — instead of inventing a callee from the method's
 * spelling.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;

use function Moggi\Optimize\Dict\specializeDictCall;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$evidence = ['__ev_Show_Int' => new IR\InstanceEvidence('__ev_Show_Int', ['show' => 'showInt'])];
$localFns = ['showInt' => true];

$unknown = new IR\DictCall(new IR\Temp(7), 'show', [new IR\Temp(1)], 2);
$check(
    specializeDictCall($unknown, $evidence, [], [], $localFns) === $unknown,
    'a dict_call on evidence nothing binds should stay a dict_call',
);

$known = new IR\DictCall(new IR\FnRef('__ev_Show_Int'), 'show', [new IR\Temp(1)], 2);
$resolved = specializeDictCall($known, $evidence, [], [], $localFns);
$check(
    $resolved instanceof IR\Call && $resolved->callee === 'showInt',
    'a dict_call on known evidence should become a direct call',
);

$bound = new IR\DictCall(new IR\Temp(7), 'show', [new IR\Temp(1)], 2);
$boundEvidence = [7 => ['name' => '__ev_Show_Int', 'args' => []]];
$check(
    specializeDictCall($bound, $evidence, $boundEvidence, [], []) === $bound,
    'a resolved method this pass cannot see should stay a dict_call',
);
$check(
    specializeDictCall($bound, $evidence, $boundEvidence, [], $localFns) instanceof IR\Call,
    'a resolved method this pass can see should become a direct call',
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        \fwrite(STDERR, 'FAIL ' . $failure . "\n");
    }
    exit(1);
}

echo "ok\n";
