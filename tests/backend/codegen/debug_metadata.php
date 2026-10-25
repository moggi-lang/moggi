<?php declare(strict_types=1);

/**
 * Native stack frames can name source lines only if the backends emit the
 * metadata: JVM `LineNumberTable` and `SourceFile`, .NET IL `.line` sequence
 * points. Exception-trace goldens cannot verify this because runtimes carry
 * their own frame data, so assert the emitted metadata directly.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');
foreach (['containers', 'time'] as $bundled) {
    $extra = $root . '/libraries/' . $bundled . '/src';
    if (\is_dir($extra)) {
        \Moggi\Modules\addLibraryRoot($extra);
    }
}

$fixture = $root . '/tests/backend/runtime/Exec-Exception-Report.mog';
if (! \is_file($fixture)) {
    \fwrite(\STDERR, "missing {$fixture}\n");
    exit(1);
}

foreach (['jvm', 'dotnet'] as $backend) {
    try {
        \Moggi\Modules\ProjectCache::clearCheckedModules();
        \Moggi\Modules\ProjectCache::clearPreparedProjects();
        if (\function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        $outputs = \Moggi\Compiler\compileFile($fixture, 'php', true, null, $backend);
    } catch (\Throwable $e) {
        \fwrite(\STDERR, "{$backend}: compile failed: " . $e->getMessage() . "\n");
        exit(1);
    }
    if (! \is_array($outputs) || $outputs === []) {
        \fwrite(\STDERR, "{$backend}: expected multi-file emit\n");
        exit(1);
    }

    $lineTable = false;
    $sourceFile = false;
    $sequencePoint = false;
    foreach ($outputs as $relative => $bytes) {
        if (! \is_string($relative) || ! \is_string($bytes)) {
            continue;
        }
        if (\str_ends_with($relative, '.class')) {
            $lineTable = $lineTable || \str_contains($bytes, 'LineNumberTable');
            $sourceFile = $sourceFile || \str_contains($bytes, 'SourceFile');
        }
        if (\str_ends_with($relative, '.il')) {
            $sequencePoint = $sequencePoint || \preg_match('/^\s*\.line\s+/m', $bytes) === 1;
        }
    }

    if ($backend === 'jvm') {
        if (! $lineTable) {
            \fwrite(\STDERR, "jvm: no LineNumberTable in the emitted classfiles\n");
            exit(1);
        }
        if (! $sourceFile) {
            \fwrite(\STDERR, "jvm: no SourceFile in the emitted classfiles\n");
            exit(1);
        }
    } elseif (! $sequencePoint) {
        \fwrite(\STDERR, "dotnet: no .line sequence points in the emitted IL\n");
        exit(1);
    }
}

echo "native debug metadata tests passed\n";
