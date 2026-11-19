<?php declare(strict_types=1);

// A use site's solved dictionaries are written beside its pending constraints
// and read by the evidence passes; the in-compiler walker
// (`assertPendingDictsConsistentInExpr`) checks, on every elaborated body, that
// each node still carrying constraints carries a dictionary list over the same
// obligations, or none. This compiles the fixtures that exercise the boundary --
// class methods, a collapsing multi-parameter superclass, a probed/restored local,
// recursive groups, records, and the runtime shapes -- so a disagreement on any of
// them fails here with the fixture's name on it.
//
// The cache is seeded with the library alone, so every fixture is elaborated here.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

// Seeded with the library only, so no fixture can be answered by a cached product.
$cache = \Moggi\Cache\cacheScratchDir() . '/pending-dicts-walker';
removeDirectory($cache);
seedCacheDir($cache);
\putenv('MOGGI_CACHE_DIR=' . $cache);

$multiline = static fn (string $text): string => \trim(\str_replace("\n", "\n  ", $text));

$fixtures = [
    'tests/semantics/Tc-Collapsing-Superclass-Dict-Arity.mog',
    'tests/semantics/Tc-Class-Method-Dict-Arity.mog',
    'tests/semantics/Tc-Dict-Arity-Invariant.mog',
    'tests/semantics/Tc-Local-Dict-Reuse.mog',
    'tests/semantics/Tc-Recursive-Annotated-Local.mog',
    'tests/semantics/Tc-Record-Constrained-Field.mog',
    'tests/semantics/Tc-Restricted-Local-Signature.mog',
    'tests/backend/runtime/Exec-Typeclasses.mog',
    'tests/backend/runtime/Exec-Let-Constrained-Lambda.mog',
    'tests/backend/runtime/Exec-Local-Constrained.mog',
    'tests/backend/runtime/Exec-Local-Dict-Matrix.mog',
    'tests/backend/runtime/Exec-Traversable.mog',
];

$failures = [];
foreach ($fixtures as $fixture) {
    try {
        \Moggi\Compiler\compileFile($root . '/' . $fixture, 'typed-ast');
    } catch (\Throwable $e) {
        $report = \method_exists($e, 'display') ? $e->display() : $e->getMessage();
        $failures[] = $fixture . ":\n  " . $multiline((string) $report);
    }
}

if ($failures !== []) {
    throw new \RuntimeException(
        "pending dictionary walker failed:\n" . \implode("\n", $failures),
    );
}

echo "pending dictionary walker tests passed\n";
