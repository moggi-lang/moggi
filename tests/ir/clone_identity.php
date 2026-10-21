<?php declare(strict_types=1);

// A specialization clone's identity is its declaration site, carried as facts:
// kind SpecClone and the origin callable's declared name. Its generated name is
// presentation — no consumer may answer a question about it by parsing the
// spelling, and renaming it must not change the answer.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Backend;

use function Moggi\Backend\codegenWith;
use function Moggi\IR\Dump\identityLines;
use function Moggi\Optimize\Partial\specializePartialReturnFunction;
use function Moggi\Optimize\Specialize\cloneSpecializedFunction;
use function Moggi\Optimize\Interproc\isPeFusionInlineCandidate;

use Moggi\IR;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;

/** @var list<string> $failures */
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$target = new IR\FunctionDecl(
    'originFn',
    [],
    null,
    new IR\Block([new IR\Ret(new IR\ConstInt(1))]),
);

$clone = cloneSpecializedFunction($target, '__spec_0123456789abcdef01234567', [], $target->name);
$renamed = cloneSpecializedFunction($target, 'q', [], $target->name);

$check($clone->kind === IR\FunctionKind::SpecClone, 'a clone is not SpecClone');
$check($clone->origin === 'originFn', 'a clone does not carry its declaration origin');
$check($renamed->origin === 'originFn', 'renaming a clone changed its origin');
$check(
    ! \str_contains($clone->name, 'originFn') && ! \str_contains($renamed->name, 'originFn'),
    'a clone name embeds the origin callable',
);
$check(
    $clone->withBody(new IR\Block([new IR\Ret(new IR\ConstInt(2))]))->origin === 'originFn',
    'withBody dropped the origin',
);

$partialClone = cloneSpecializedFunction(
    new IR\FunctionDecl(
        'originFn',
        [],
        null,
        new IR\Block([new IR\Ret(new IR\Partial('originFn', 2, []))]),
    ),
    '__spec_partial',
    [],
    'originFn',
);
$wrapped = specializePartialReturnFunction($partialClone);
$check(
    $wrapped->kind === IR\FunctionKind::SpecClone && $wrapped->origin === 'originFn',
    'a partial-apply wrapper dropped the clone identity',
);

$lookalike = new IR\FunctionDecl(
    '__spec_0123456789abcdef01234567',
    [],
    null,
    new IR\Block([new IR\Ret(new IR\ConstInt(1))]),
);

$check(isPeFusionInlineCandidate($clone), 'a SpecClone is not an inline candidate');
$check(isPeFusionInlineCandidate($renamed), 'renaming a SpecClone changed its candidacy');
$check(! isPeFusionInlineCandidate($lookalike), 'a Source named like a clone is treated as one');

$module = new IR\Module([$target, $clone, $renamed], [], [], null, 'Main', 'Main.mog');
$identity = identityLines($module);
$check($identity === identityLines($module), 'identity lines are not deterministic');
$check(
    \count($identity) === 3
        && \in_array('Main::originFn kind=source', $identity, true)
        && \in_array('Main::q kind=spec-clone origin=originFn', $identity, true),
    'identity lines do not carry kind and origin: ' . \implode(' | ', $identity),
);

foreach (Backend\implementedBackendIds() as $backendId) {
    $context = CompilationContext::for($backendId, CompilePurpose::Library);
    $before = identityLines($module);
    $emitted = codegenWith($context->backend, $module, 'Main.mog', [
        'outputRelative' => 'Main.out',
        'artifactModuleName' => 'Main',
        'namespace' => 'Main',
    ]);
    $check($emitted !== '', "{$backendId}: emit produced nothing");
    $check(identityLines($module) === $before, "{$backendId}: emit changed the identity lines");
    $check($before === $identity, "{$backendId}: identity differs from the other targets");
}

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " clone-identity failure(s):\n  " . \implode("\n  ", $failures),
    );
}

echo "clone identity tests passed\n";
