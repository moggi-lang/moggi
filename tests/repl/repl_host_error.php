<?php declare(strict_types=1);

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$report = \Moggi\Repl\formatReplThrowable(new \RuntimeException('boom'));
$assert(\str_contains($report, 'boom'), 'a host exception must render its message');
$assert(
    \function_exists('\\Moggi\\formatExceptionReport'),
    'the host-report path must have loaded the runtime',
);

$state = \Moggi\Repl\createSession('php', [$root . '/libraries/base/src']);

$assert(
    \Moggi\Repl\evaluateExpression($state, '1 + 1') === '2',
    'evaluation must survive a runtime already loaded by the host',
);
$assert(
    \Moggi\Repl\evaluateExpression($state, 'putStrLn "hi"') === 'hi',
    'IO evaluation must survive a runtime already loaded by the host',
);

$deps = \Moggi\Repl\EvalRunner\ensurePhpDeps($state);
$shim = \file_get_contents($deps . '/_runtime.php');
$assert(\is_string($shim), 'the deps tree must provide _runtime.php');
$assert(! \str_contains($shim, 'function '), 'the deps runtime must just forward to the shared runtime');
$runtime = \Moggi\Backend\backendById('php')->runtimeFiles();
$assert($runtime !== [], 'the PHP backend must have a runtime to share');
$assert(\Moggi\Paths\isAbsolutePath($runtime[0]), 'the shared runtime path must be absolute');
$assert(
    \str_contains($shim, 'require_once ' . \var_export($runtime[0], true) . ';'),
    'the deps runtime must forward to the compiler runtime by absolute path: ' . $shim,
);
$assert(! \is_file($deps . '/moggi-app.phar'), 'the REPL deps tree must not be packaged');

\Moggi\Repl\destroySession($state);
$assert(! \is_dir($state->sessionDir), 'destroySession must remove the scratch tree');

echo "REPL host-error runtime sharing tests passed\n";
