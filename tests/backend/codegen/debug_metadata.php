#!/usr/bin/env php
<?php declare(strict_types=1);

// A native stack frame can only name a source line if emit wrote the metadata: `LineNumberTable`
// and `SourceFile` in the JVM classfiles, `.line` sequence points in .NET IL. The exception-trace
// goldens would keep passing without them, because the runtime carries its own frame data — this
// asserts what the backends actually emit.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

// Own process, so no harness configured the library roots: set them like
// test.php does. Without the base root the .NET library metadata (valuetype
// flags, assembly scopes) is never discovered and cold compiles mis-render
// foreign types, failing only when the emit cache is cold.
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
        // One backend closure at a time: a suite worker already holds the php
        // closure in its checked-module memos, and two cold elaborations at
        // once blow the 1G limit. The memos are a pure cache — later cases
        // reload them from disk — so drop them before each backend compile.
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
