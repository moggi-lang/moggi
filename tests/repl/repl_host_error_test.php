#!/usr/bin/env php
<?php declare(strict_types=1);
// Regression: rendering a host exception loads the compiler's runtime into the
// REPL process, and the evaluated module tree must not load a second copy of it.
// Top-level functions are bound while PHP *compiles* a file, so a second copy is
// an uncatchable `Cannot redeclare` fatal, not an error the REPL can report.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

// A failing command such as `:load /missing.mog` renders through the runtime,
// before anything has been evaluated.
$report = \Moggi\Repl\formatReplThrowable(new \RuntimeException('boom'));
$assert(str_contains($report, 'boom'), 'a host exception must render its message');
$assert(
    \function_exists('\\Moggi\\formatExceptionReport'),
    'the host-report path must have loaded the runtime',
);

$state = \Moggi\Repl\createSession('php', [$root . '/lib']);

$assert(
    \Moggi\Repl\evaluateExpression($state, '1 + 1') === '2',
    'evaluation must survive a runtime already loaded by the host',
);
$assert(
    \Moggi\Repl\evaluateExpression($state, 'putStrLn "hi"') === 'hi',
    'IO evaluation must survive a runtime already loaded by the host',
);

// The dependency tree the REPL emits forwards to that single runtime instead of
// shipping its own copy, and it is never packaged into an unused phar.
$deps = \Moggi\Repl\EvalRunner\ensurePhpDeps($state);
$shim = file_get_contents($deps . '/_runtime.php');
$assert(\is_string($shim), 'the deps tree must provide _runtime.php');
$assert(!str_contains($shim, 'function '), 'the deps runtime must just forward to the shared runtime');
$assert(
    str_contains(
        \Moggi\Paths\canonicalSeparators($shim),
        \Moggi\Paths\canonicalSeparators($root) . '/src/backend/php/runtime.php',
    ),
    'the deps runtime must forward to the compiler runtime by absolute path',
);
$assert(!is_file($deps . '/moggi-app.phar'), 'the REPL deps tree must not be packaged');

// And the session scratch tree goes away with the session.
\Moggi\Repl\destroySession($state);
$assert(!is_dir($state->sessionDir), 'destroySession must remove the scratch tree');

echo "REPL host-error runtime sharing tests passed\n";
