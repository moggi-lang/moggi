<?php declare(strict_types=1);

// backends: php

/**
 * The backend surface, as a contract rather than a convention.
 *
 * Every target's `src/backend/<id>/` holds exactly the shared files plus the ones its host
 * language needs, whatever their extension and however deeply they nest; every role name in
 * `$roles` is declared *and called* by every target; every role that walks IR starts its argument
 * list with the shared prefix; and each registered target implements `Backend`.
 *
 * The asymmetry that is allowed is stated in `$hostOnly`, not inferred: php's host is the
 * compiler's own process, the stack backends carry `frames.php` and their runtime, and .NET also
 * ships an IL inspector the verifier compiles.
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
    'dotnet' => [
        'DotNetBackend.php',
        'frames.php',
        'il.php',
        'il_size.php',
        'inspect/Program.cs',
        'runtime_abi.php',
    ],
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
    'emitMatchReturn',
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

$signatureRoles = [
    'emitBlock' => ['IR\\Block'],
    'emitCallValue' => ['IR\\Call|IR\\CallValue'],
    'emitForeign' => ['IR\\ForeignCall'],
    'emitIntrinsic' => ['IR\\Intrinsic'],
    'emitIntrinsicCompare' => ['IR\\Intrinsic'],
    'emitIntrinsicPrimop' => ['IR\\Intrinsic'],
    'emitIntrinsicState' => ['IR\\Intrinsic'],
    'emitIntrinsicValue' => ['IR\\Intrinsic'],
    'emitIoAssignAction' => ['IR\\IoAssignAction'],
    'emitIoCall' => ['IR\\IoCall'],
    'emitMatchIo' => ['IR\\IoMatch'],
    'emitMatchReturn' => ['IR\\MatchReturn'],
    'emitMatchStmt' => ['IR\\MatchStmt'],
    'emitOperand' => ['IR\\Operand'],
    'emitPartialValue' => ['string', 'int', 'array'],
    'emitPatternTest' => ['IR\\Pattern'],
    'emitSrcLocValue' => ['?IR\\SrcLoc'],
    'emitStaticCall' => ['string', 'array'],
    'emitStmt' => ['IR\\Stmt'],
    'emitTailRecall' => ['IR\\TailRecall'],
    'emitTopLevelFnValue' => ['string', 'int'],
];

$normalizeType = static function (string $type): string {
    $type = \trim($type);

    return match ($type) {
        'SrcLoc' => 'IR\\SrcLoc',
        '?SrcLoc' => '?IR\\SrcLoc',
        default => $type,
    };
};

/** Every file under a backend directory, as a path relative to it. @return list<string> */
$filesUnder = static function (string $dir) use ($fail, &$failures): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $path = \str_replace('\\', '/', \substr($file->getPathname(), \strlen($dir) + 1));
        $files[] = $path;
    }
    \sort($files);

    return $files;
};

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

    $declared = $filesUnder($dir);
    $expected = [...$shared, ...$extra];
    \sort($expected);
    foreach (\array_diff($expected, $declared) as $missing) {
        $fail("{$backendId}: missing {$missing} (the shared set plus this host's own files)");
    }
    foreach (\array_diff($declared, $expected) as $stray) {
        $fail("{$backendId}: {$stray} is not in the documented file set; add it to \$shared or \$hostOnly and to architecture.md");
    }

    $sources = '';
    $declarations = [];
    $calls = [];
    foreach (\array_diff($declared, \array_diff($declared, $expected)) as $file) {
        $text = \file_get_contents($dir . '/' . $file);
        if ($text === false) {
            $fail("{$backendId}: cannot read {$file}");

            continue;
        }
        $sources .= $text . "\n";
    }
    \preg_match_all('/^function\s+([A-Za-z0-9_]+)\s*\(([^)]*)\)/m', $sources, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $declarations[$match[1]] = [];
        $params = [];
        foreach (\explode(',', $match[2]) as $param) {
            $param = \trim($param);
            if ($param === '') {
                continue;
            }
            $dollar = \strpos($param, '$');
            $params[] = $dollar === false ? 'mixed' : \trim(\substr($param, 0, $dollar));
        }
        $declarations[$match[1]] = $params;
    }
    foreach ($roles as $role) {
        if (! isset($declarations[$role])) {
            $fail("{$backendId}: no `function {$role}(`; the role names are shared by every backend");

            continue;
        }
        $calls[$role] = \preg_match_all('/\b' . \preg_quote($role, '/') . '\s*\(/', $sources);
        if (($calls[$role] ?? 0) < 2) {
            $fail("{$backendId}: `{$role}` is declared but never called, so it is not a role any traversal uses");
        }
    }

    foreach ($signatureRoles as $role => $expectedPrefix) {
        $params = $declarations[$role] ?? null;
        if ($params === null) {
            continue;
        }
        if (($params[0] ?? null) !== 'EmitEnv') {
            $fail("{$backendId}: {$role} must take `EmitEnv \$env` first, got `" . \implode(', ', $params) . '`');

            continue;
        }
        $actual = \array_map($normalizeType, \array_slice($params, 1, \count($expectedPrefix)));
        if ($actual !== $expectedPrefix) {
            $fail("{$backendId}: {$role} signature `(" . \implode(', ', $params) . ')` does not start with the shared prefix `(EmitEnv, ' . \implode(', ', $expectedPrefix) . ')`');
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
    . \count($roles) . ' shared roles, ' . \count($signatureRoles) . " checked signatures\n";
