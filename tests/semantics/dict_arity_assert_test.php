#!/usr/bin/env php
<?php declare(strict_types=1);

// The in-compiler dictionary assertions cannot run on every compile, so this
// test compiles the fixtures that pin the dictionary boundary with them on:
// `MOGGI_ASSERT_DICT_ARITY` catches a use site whose scheme expanded its
// superclasses one more time than the definition did, and
// `MOGGI_ASSERT_EXPAND_CLOSED` catches a superclass expansion that did not
// reach a fixed point (the property that makes expanding twice a no-op). The
// fixtures cover a probed/restored local, a recursive group -- inferred and
// with a declared signature -- a restricted local signature, a superclass
// chain, a class method and the multi-obligation shape whose superclass
// closures overlap, and the runtime shapes those feed.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';

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
    $env = \getenv();
    $env['MOGGI_NO_CACHE'] = '1';
    $env['MOGGI_ASSERT_DICT_ARITY'] = '1';
    $env['MOGGI_ASSERT_EXPAND_CLOSED'] = '1';

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
        "dictionary arity assertion failed:\n" . \implode("\n", $failures),
    );
}

echo "dictionary arity assertion tests passed\n";
