<?php declare(strict_types=1);

/**
 * Compile the dictionary-boundary fixtures from a cold checker: probed and
 * restored locals, recursive groups (inferred and declared), restricted local
 * signatures, superclass chains, class methods, overlapping superclass closures,
 * and the runtime shapes that follow from them.
 *
 * Run all fixtures in one process: the first compile elaborates the standard
 * library, which subsequent fixtures reuse. Disable the cache because a cache
 * hit bypasses the checker this case is exercising.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

\putenv('MOGGI_NO_CACHE=1');

require $root . '/src/compiler.php';

$multiline = static fn (string $text): string => \trim(\str_replace("\n", "\n  ", $text));

$fixtures = [
    'tests/semantics/Tc-Class-Method-Dict-Arity.mog',
    'tests/semantics/Tc-Dict-Arity-Invariant.mog',
    'tests/semantics/Tc-Local-Dict-Reuse.mog',
    'tests/semantics/Tc-Recursive-Dict-Group.mog',
    'tests/semantics/Tc-Recursive-Annotated-Local.mog',
    'tests/semantics/Tc-Restricted-Local-Signature.mog',
    'tests/backend/runtime/Exec-Let-Constrained-Lambda.mog',
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
        "dictionary arity assertion failed:\n" . \implode("\n", $failures),
    );
}

echo "dictionary arity assertion tests passed\n";
