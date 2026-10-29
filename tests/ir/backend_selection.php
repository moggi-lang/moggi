<?php declare(strict_types=1);

/**
 * The compile target is an argument, never a process global.
 *
 * A process-wide backend selection survived the pipeline refactor as a test seam: the harness and the
 * phar builder could set it, nothing in `src/` read it, and its docblock asked future callers not to
 * grow it. That is the wrong shape for a decision that belongs to one compilation — two compilations
 * in one process could silently disagree about their target, and the "seam" sat in production code
 * where any caller could reach it.
 *
 * It is deleted, not documented. The backend now travels the way every other compilation input does:
 * `CompilationContext::backendId` names it, and the harness passes the backend it was started for
 * down the call chain that needs it. This case pins both halves:
 *
 * 1. **Nothing selects a backend process-wide.** No `moggi_compile_backend` slot, no
 *    `setCompileBackend()`, no `compileBackend()` — in `src/` or in `tests/`.
 * 2. **The harness threads it.** The functions that resolve a runtime verdict take the backend as a
 *    parameter, so a case cannot pick up a neighbour's target through ambient state.
 *
 * A reinstated global, a second reader or a case that forgets to pass its backend fails here.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

/** Every PHP source of the tree, relative to the project root. */
$sources = [];
$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $path = \str_replace('\\', '/', $file->getPathname());
    if (\str_contains($path, '/.moggi/') || \str_contains($path, '/vendor/') || \str_contains($path, '/dist/')) {
        continue;
    }
    $relative = \str_replace($root . '/', '', $path);
    if ($relative === 'tests/ir/backend_selection.php') {
        // This case names what it forbids; a scan that read itself would always find it.
        continue;
    }
    if (! \str_starts_with($relative, 'src/') && ! \str_starts_with($relative, 'tests/')
        && ! \str_starts_with($relative, 'packaging/')) {
        continue;
    }
    $sources[$relative] = (string) \file_get_contents($path);
}
$assert(\count($sources) > 100, 'the scan found only ' . \count($sources) . ' sources');

// 1. No process-wide selection, anywhere.
$patterns = [
    'moggi_compile_backend' => '/moggi_compile_backend/',
    'setCompileBackend' => '/\bsetCompileBackend\b/',
    'compileBackend()' => '/\bcompileBackend\s*\(/',
];
$found = [];
foreach ($patterns as $label => $pattern) {
    foreach ($sources as $relative => $body) {
        if (\preg_match($pattern, $body) === 1) {
            $found[] = "{$relative} → {$label}";
        }
    }
}
$assert(
    $found === [],
    "a process-wide compile-backend selection is back — the target belongs to one compilation,\n"
        . "so it is an argument (`CompilationContext::backendId`), never a slot two compilations share:\n  "
        . \implode("\n  ", $found),
);

// 2. The production path carries it in the context, and the harness carries it in a parameter.
$context = $sources['src/pipeline/types.php'] ?? '';
$assert(
    \preg_match('/public\s+(?:readonly\s+)?string\s+\$backendId/', $context) === 1,
    '`CompilationContext` no longer names its backend; the target has to live somewhere explicit',
);

$threaded = [
    'tests/suite/kinds.php' => '/function runTestCase\(TestCase \$case, string \$backend,/',
    'tests/suite/runners/runtime.php' => '/function runExecTest\(string \$path, string \$projectRoot, string \$backend\)/',
    'tests/suite/runners/runtime.php' => '/function runExecTestOnce\(string \$path, string \$projectRoot, string \$backend,/',
    'tests/suite/runners/runtime.php' => '/function runReplCase\(string \$scriptPath, string \$projectRoot, string \$backend\)/',
];
foreach ($threaded as $relative => $pattern) {
    $assert(
        \preg_match($pattern, $sources[$relative] ?? '') === 1,
        "`{$relative}` no longer takes its backend as a parameter — a case would be reading an ambient\n"
            . ' target again instead of the one the run gave it',
    );
}

echo 'compile backend selection tests passed (' . \count($sources) . " sources scanned, no process-wide\n"
    . "selection, 4 harness entry points thread the backend)\n";
