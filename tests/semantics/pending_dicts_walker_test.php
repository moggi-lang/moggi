#!/usr/bin/env php
<?php declare(strict_types=1);

// A use site's solved dictionaries are written beside its pending constraints
// and read by the evidence passes; the in-compiler walker
// (`assertPendingDictsConsistentInExpr`, gated by `MOGGI_ASSERT_PENDING_DICTS`)
// checks that every node still carrying constraints carries a dictionary list
// over the same obligations, or none. This compiles the fixtures that exercise
// the boundary -- class methods, a collapsing multi-parameter superclass, a
// probed/restored local, recursive groups, records, and the runtime shapes --
// with the walker on.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';

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
    $env = \getenv();
    $env['MOGGI_NO_CACHE'] = '1';
    $env['MOGGI_ASSERT_PENDING_DICTS'] = '1';

    $result = runCompiledProcess(
        [PHP_BINARY, $root . '/moggi.php', 'compile', $root . '/' . $fixture, '--typed-ast'],
        120,
        $env,
        $root,
    );

    if (($result['exitCode'] ?? 0) !== 0) {
        $failures[] = $fixture . ":\n  " . $multiline((string) ($result['stderr'] ?? ''));
    }
}

if ($failures !== []) {
    throw new \RuntimeException(
        "pending dictionary walker failed:\n" . \implode("\n", $failures),
    );
}

echo "pending dictionary walker tests passed\n";
