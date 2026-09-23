<?php declare(strict_types=1);

/**
 * Producing the thing a pre-codegen (or emit) golden compares, from the fixture beside it.
 * `kinds.php` picks the producer by the golden's kind.
 */

use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Backend\Php\Codegen\emit;
use function Moggi\Backend\compileBackend;
use function Moggi\Backend\setCompileBackend;
use function Moggi\Compiler\compileFile;
use function Moggi\IR\Dump\dump as dumpIr;
use function Moggi\Optimize\optimize;
use function Moggi\Test\IrFixture\moduleFromArray;

/** A compiler diagnostic as the goldens store it: what the stage printed, verbatim. */
function diagnosticText(\Throwable $e): string
{
    if ($e instanceof LexError || $e instanceof ParseError || $e instanceof TypeError) {
        return $e->display();
    }

    return get_class($e) . ': ' . $e->getMessage() . "\n";
}

/**
 * One pre-lowering stage of a source, dumped by the compiler — the same producer that answers the
 * CLI's `--tokens`/`--ast`/`--typed-ast`, so a flag and a golden cannot describe a stage differently.
 */
function sourceStageDump(string $path, string $stage): string
{
    $dump = compileFile($path, $stage);
    if (!\is_string($dump)) {
        throw new TestFailure("{$stage} dump unexpectedly produced multiple artifacts");
    }

    return $dump;
}

/** The unoptimized IR of a `.mog` fixture, as the lowering golden stores it (backend-neutral). */
function irLoweringDump(string $path): string
{
    $actual = withCompileBackend('php', static fn () => compileFile($path, 'ir', false));
    if (!\is_string($actual)) {
        throw new TestFailure('lowering dump unexpectedly produced multiple artifacts');
    }

    return $actual;
}

/** The optimized IR of an `.ir.php` fixture (backend-neutral, like the lowering dump). */
function optimizedIrDump(string $path): string
{
    $module = loadIrFixture($path);

    return withCompileBackend('php', static fn (): string => dumpIr(optimize($module)));
}

/** The PHP module `emit` produces for an IR fixture, with the source map dropped. */
function emitPhpArtifact(string $path): string
{
    $module = loadIrFixture($path);
    $sourcePath = testRelativePath($path);
    $actual = emit($module, $sourcePath, [
        'outputRelative' => preg_replace('/\.ir\.php$/', '.php', $sourcePath) ?? $sourcePath,
    ]);
    if (\is_string($actual)) {
        return $actual;
    }

    foreach ($actual as $relative => $bytes) {
        if (\is_string($relative) && \is_string($bytes) && str_ends_with($relative, '.php') && !str_ends_with($relative, '.moggi.map')) {
            return $bytes;
        }
    }

    throw new TestFailure("no PHP artifact emitted for {$path}");
}

/**
 * The IR module an `.ir.php` fixture carries: either the module itself or `['module' => ...]`. The
 * fixture names no paths of its own, so a moved fixture can never point at its old home.
 */
function loadIrFixture(string $path): \Moggi\IR\Module
{
    $fixture = require $path;
    if (!\is_array($fixture)) {
        throw new TestFailure("IR fixture {$path} must return an array");
    }

    if (($fixture['tag'] ?? null) === 'module') {
        return moduleFromArray($fixture);
    }

    if (!isset($fixture['module']) || !\is_array($fixture['module'])) {
        throw new TestFailure("IR fixture {$path} must return an IR module or ['module' => ...]");
    }

    return moduleFromArray($fixture['module']);
}

/** Run $body with the compile backend pinned: stage goldens are backend-neutral. */
function withCompileBackend(string $backend, callable $body): mixed
{
    $previous = compileBackend();
    setCompileBackend($backend);
    try {
        return $body();
    } finally {
        setCompileBackend($previous);
    }
}
