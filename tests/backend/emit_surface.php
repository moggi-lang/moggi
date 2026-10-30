<?php declare(strict_types=1);

// backends: php

/**
 * The backend surface, as a contract rather than a convention.
 *
 * `docs/development/architecture.md` promises that every target has the same file set and the same
 * role function names, and that the `Backend` interface is what a target implements. Nothing checked
 * it: a file could be dropped from one backend, or a role renamed in one, and the suite stayed green
 * because each backend is exercised through its own goldens.
 *
 * So the promise is written down twice — in prose and here — and this case is the executable copy:
 *
 *   - every target's `src/backend/<id>/` holds exactly the shared files plus the ones its host
 *     language genuinely needs (`$shared` + `$hostOnly`), so a new file is a deliberate act that
 *     states which column it belongs to;
 *   - every role name in `$roles` is declared by every target, so a reader who learned where
 *     `emitIntrinsicValue` lives in one backend finds it in the other two;
 *   - each of the three targets the registry answers for implements `Backend`.
 *
 * The asymmetry that is allowed is *stated*, not inferred: php's host is the compiler's own process,
 * so it carries `native.php` and `runtime.php` and no classfile/IL module, and the stack backends
 * carry `frames.php` and (jvm) `classfile.php` / (.NET) `il.php` + `il_size.php` / both
 * `runtime_abi.php`. `foreign.php` is the one place a *shared* file has unequal weight: php
 * registers no host types, so its copy is small — same name, same job, different amount of work.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require_once $root . '/src/compiler.php';

use Moggi\Backend\Backend;

use function Moggi\Backend\implementedBackendIds;

$failures = [];
$fail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
};

$shared = [
    'codegen.php',
    'dependencies.php',
    'emit_call.php',
    'emit_data.php',
    'emit_env.php',
    'emit_expr.php',
    'emit_fn_value.php',
    'emit_foreign.php',
    'emit_intrinsic.php',
    'emit_io.php',
    'emit_literal.php',
    'emit_match.php',
    'emit_pattern.php',
    'emit_primop.php',
    'emit_stmt.php',
    'emit_value.php',
    'foreign.php',
    'naming.php',
    'package.php',
    'verify.php',
];

$hostOnly = [
    'php' => ['PhpBackend.php', 'build_phar.php', 'native.php', 'runtime.php'],
    'jvm' => ['JvmBackend.php', 'classfile.php', 'frames.php', 'runtime_abi.php'],
    'dotnet' => ['DotNetBackend.php', 'frames.php', 'il.php', 'il_size.php', 'runtime_abi.php'],
];

$roles = [
    'emitBlock',
    'emitCallValue',
    'emitDataDecl',
    'emitEvidence',
    'emitForeign',
    'emitForeignIoWrap',
    'emitFunction',
    'emitFunctionReturn',
    'emitIntrinsic',
    'emitIntrinsicCompare',
    'emitIntrinsicPrimop',
    'emitIntrinsicState',
    'emitIntrinsicValue',
    'emitIoAssignAction',
    'emitIoCall',
    'emitMain',
    'emitMatchIo',
    'emitMatchStmt',
    'emitOperand',
    'emitPartialValue',
    'emitPatternTest',
    'emitSrcLocValue',
    'emitStaticCall',
    'emitStmt',
    'emitTailRecall',
    'emitTopLevelFnValue',
    'emitModule',
    'entryReturnsAction',
    'formatDoubleLiteral',
];

$backendIds = implementedBackendIds();
if ($backendIds !== \array_keys($hostOnly)) {
    $fail('the registry answers for [' . \implode(', ', $backendIds) . '], not [' . \implode(', ', \array_keys($hostOnly)) . ']');
}

foreach ($hostOnly as $backendId => $extra) {
    $dir = $root . '/src/backend/' . $backendId;
    if (! \is_dir($dir)) {
        $fail("{$backendId}: missing {$dir}");

        continue;
    }

    $declared = [];
    foreach (\glob($dir . '/*.php') ?: [] as $file) {
        $declared[] = \basename($file);
    }
    $expected = [...$shared, ...$extra];
    \sort($declared);
    \sort($expected);
    foreach (\array_diff($expected, $declared) as $missing) {
        $fail("{$backendId}: missing {$missing} (the shared set plus this host's own files)");
    }
    foreach (\array_diff($declared, $expected) as $stray) {
        $fail("{$backendId}: {$stray} is not in the documented file set; add it to \$shared or \$hostOnly and to architecture.md");
    }

    $sources = '';
    foreach ($declared as $file) {
        $text = \file_get_contents($dir . '/' . $file);
        if ($text === false) {
            $fail("{$backendId}: cannot read {$file}");

            continue;
        }
        $sources .= $text . "\n";
    }
    \preg_match_all('/^function\s+([A-Za-z0-9_]+)\s*\(/m', $sources, $matches);
    $declares = \array_flip($matches[1]);
    foreach ($roles as $role) {
        if (! isset($declares[$role])) {
            $fail("{$backendId}: no `function {$role}(`; the role names are shared by every backend");
        }
    }
}

foreach ($backendIds as $backendId) {
    if (! \Moggi\Backend\backendById($backendId) instanceof Backend) {
        $fail("{$backendId}: registry entry does not implement Backend");
    }
}

if ($failures !== []) {
    \fwrite(STDERR, 'backend emit surface FAILED: ' . \count($failures) . " problem(s)\n");
    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }

    exit(1);
}

echo 'backend emit surface: ' . \count($backendIds) . ' targets, ' . \count($shared) . " shared files, "
    . \count($roles) . " shared roles\n";
