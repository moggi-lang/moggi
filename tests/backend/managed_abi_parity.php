<?php declare(strict_types=1);

// backends: php

/**
 * The managed runtime protocol, checked as one fact set instead of two implementations agreeing
 * by inspection.
 *
 * `src/backend/managed/abi_facts.php` names each protocol type once, in each language's spelling.
 * This renders both runtimes and asserts every fact is there, so renaming or dropping a type in
 * one backend fails here rather than on a user's program.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require_once $root . '/src/compiler.php';

use function Moggi\Backend\DotNet\languageRuntime as dotNetRuntime;
use function Moggi\Backend\Jvm\languageRuntime as jvmRuntime;
use function Moggi\Backend\Managed\missingDotNetProtocol;
use function Moggi\Backend\Managed\missingJvmProtocol;
use function Moggi\Backend\Managed\protocolTypes;

$jvm = jvmRuntime();
$il = dotNetRuntime();

$failures = [];
foreach (missingJvmProtocol($jvm) as $missing) {
    $failures[] = "jvm: the runtime does not define {$missing}";
}
foreach (missingDotNetProtocol($il) as $missing) {
    $failures[] = "dotnet: the runtime does not define {$missing}";
}

if ($failures !== []) {
    \fwrite(STDERR, 'managed runtime ABI FAILED: ' . \count($failures) . " problem(s)\n");
    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }

    exit(1);
}

echo 'managed runtime abi: ' . \count(protocolTypes()) . " protocol types in both runtimes\n";
