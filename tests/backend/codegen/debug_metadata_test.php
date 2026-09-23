#!/usr/bin/env php
<?php declare(strict_types=1);

// A native stack frame can only name a source line if emit wrote the metadata: `LineNumberTable`
// and `SourceFile` in the JVM classfiles, `.line` sequence points in .NET IL. The exception-trace
// goldens would keep passing without them, because the runtime carries its own frame data — this
// asserts what the backends actually emit.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

$fixture = $root . '/tests/backend/runtime/Exec-Exception-Report.mog';
if (!\is_file($fixture)) {
    \fwrite(\STDERR, "missing {$fixture}\n");
    exit(1);
}

foreach (['jvm', 'dotnet'] as $backend) {
    try {
        $outputs = \Moggi\Compiler\compileFile($fixture, 'php', true, null, $backend);
    } catch (\Throwable $e) {
        \fwrite(\STDERR, "{$backend}: compile failed: " . $e->getMessage() . "\n");
        exit(1);
    }
    if (!\is_array($outputs) || $outputs === []) {
        \fwrite(\STDERR, "{$backend}: expected multi-file emit\n");
        exit(1);
    }

    $lineTable = false;
    $sourceFile = false;
    $sequencePoint = false;
    foreach ($outputs as $relative => $bytes) {
        if (!\is_string($relative) || !\is_string($bytes)) {
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
        if (!$lineTable) {
            \fwrite(\STDERR, "jvm: no LineNumberTable in the emitted classfiles\n");
            exit(1);
        }
        if (!$sourceFile) {
            \fwrite(\STDERR, "jvm: no SourceFile in the emitted classfiles\n");
            exit(1);
        }
    } elseif (!$sequencePoint) {
        \fwrite(\STDERR, "dotnet: no .line sequence points in the emitted IL\n");
        exit(1);
    }
}

echo "native debug metadata tests passed\n";
