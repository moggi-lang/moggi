<?php declare(strict_types=1);

// `--no-opt` must still produce a runnable program: the optimizer is not what writes the entry
// bootstrap, so the unoptimized PHP source has to keep the uncaught-report try/catch and the
// `main()` call, and the emitted tree has to run as written.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
\define('MOGGI_PROJECT_ROOT', $root);
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/bootstrap.php';
\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');
$libRoots = [$root . '/libraries/base/src'];
foreach (['containers', 'time'] as $bundled) {
    $extra = $root . '/libraries/' . $bundled . '/src';
    if (\is_dir($extra)) {
        \Moggi\Modules\addLibraryRoot($extra);
        $libRoots[] = $extra;
    }
}

$fixture = __DIR__ . '/noopt-entry-bootstrap.mog';

[$files, $projectRoot] = \Moggi\Modules\projectSourceClosure([$fixture], $libRoots, 'php');
$context = \Moggi\Pipeline\CompilationContext::for(
    'php',
    \Moggi\Pipeline\CompilePurpose::Executable,
    entryModules: ['Main'],
    roots: $libRoots,
    sources: $files,
);

try {
    $outputs = \Moggi\Modules\compileProjectBoth($files, $projectRoot, $context, optimize: false);
} catch (\Throwable $e) {
    \fwrite(\STDERR, "compile failed:\n" . diagnosticText($e) . "\n");
    exit(1);
}

$entryRelative = $outputs['entryRelative'] ?? null;
if (! \is_string($entryRelative) || ! isset($outputs['emit'][$entryRelative])) {
    \fwrite(\STDERR, "the unoptimized compile produced no entry artifact\n");
    exit(1);
}

$source = $outputs['emit'][$entryRelative];
foreach (['reportUncaught', 'main();', '_runtime.php'] as $marker) {
    if (! \str_contains($source, $marker)) {
        \fwrite(\STDERR, "unoptimized output is missing the entry bootstrap `{$marker}`\n");
        exit(1);
    }
}

$dir = createTempDir('moggi-noopt');

try {
    \Moggi\Backend\backendById('php')->packageOutput($dir);
    foreach ($outputs['emit'] as $relative => $bytes) {
        $target = $dir . '/' . $relative;
        $parent = \dirname($target);
        if (! \is_dir($parent)) {
            \mkdir($parent, 0777, true);
        }
        \file_put_contents($target, $bytes);
    }

    $run = runCompiledProcess([PHP_BINARY, $dir . '/' . $entryRelative], 60, null, $root);
    if (($run['exitCode'] ?? 1) !== 0) {
        \fwrite(\STDERR, "unoptimized program exited {$run['exitCode']}:\n" . \trim((string) ($run['stderr'] ?? '')) . "\n");
        exit(1);
    }
} finally {
    removeDirectory($dir);
}
