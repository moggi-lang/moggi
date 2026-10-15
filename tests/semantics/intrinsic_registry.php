#!/usr/bin/env php
<?php declare(strict_types=1);

// The intrinsic naming rule: a primop has exactly one name, the MagicHash name (`intAdd#`,
// `word32Eq#`), and the registry, the synthesized Prim/IO modules and the stdlib all agree on it.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Modules;
use Moggi\Semantics\IntrinsicRegistry;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$schemes = IntrinsicRegistry\typeSchemes();
$assert(\count($schemes) > 100, 'registry should hold a substantial primop table');

// 1. Every key is a MagicHash name: `#` suffix, no snake_case, and resolving it
//    returns the name itself (the id is the name).
foreach (\array_keys($schemes) as $name) {
    $assert(\str_ends_with($name, '#'), "primop {$name} must end with `#`");
    $assert(! \str_contains($name, '_'), "primop {$name} must not contain `_` (no snake_case ids)");
    $assert(
        IntrinsicRegistry\resolveIntrinsicName($name) === $name,
        "resolveIntrinsicName({$name}) must round-trip",
    );
}

// 2. Canonical names resolve; the old snake_case spellings never do.
$assert(IntrinsicRegistry\resolveIntrinsicName('word32Eq#') === 'word32Eq#', 'word32Eq# must resolve');
$assert(IntrinsicRegistry\resolveIntrinsicName('intAdd#') === 'intAdd#', 'intAdd# must resolve');
$assert(IntrinsicRegistry\resolveIntrinsicName('error#') === 'error#', 'error# must resolve');
$assert(IntrinsicRegistry\resolveIntrinsicName('word32_eq') === null, 'word32_eq must not resolve');
$assert(IntrinsicRegistry\resolveIntrinsicName('int_add') === null, 'int_add must not resolve');
$assert(IntrinsicRegistry\resolveIntrinsicName('error') === null, 'bare `error` must not resolve');

// `fix#` is the one entry that is not derivable from any surface operation:
// Moggi is strict, so `fix :: (a -> a) -> a` needs compiler support.
$assert(isset($schemes['fix#']), 'fix# must be registered');
$assert(IntrinsicRegistry\schemeArity($schemes['fix#']) === 1, 'fix# takes the function to fix');
$assert(IntrinsicRegistry\ownerModule('fix#') === IntrinsicRegistry\MODULE_PRIM, 'fix# belongs to Prim');

// 3. Every `#` name used in the stdlib is registered, so registry and lib cannot drift apart.
$unregistered = [];
$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root . '/libraries/base/src', \FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'mog') {
        continue;
    }
    $source = \file_get_contents($file->getPathname());
    if ($source === false) {
        continue;
    }
    // Strip line comments and string literals so we only see real identifiers.
    $code = \preg_replace('/(?:\{-.*?-\}|--[^\n]*)/s', ' ', $source) ?? $source;
    $code = \preg_replace('/"[^"\n]*"/', '""', $code) ?? $code;
    \preg_match_all('/\b([A-Za-z][A-Za-z0-9_]*#[A-Za-z0-9_#]*)/', $code, $matches);
    foreach ($matches[1] as $used) {
        if (isset($schemes[$used])) {
            continue;
        }
        // `Integer#`, `Word32#`, … are primitive *type* names, not primops.
        if (IntrinsicRegistry\isKnownPrimitiveType($used)) {
            continue;
        }
        $unregistered[$used] = $file->getPathname();
    }
}
$assert(
    $unregistered === [],
    'stdlib uses unregistered primops: ' . \json_encode($unregistered),
);

// 4. A synthesized compiler module holds its own names: the primops it owns (each
//    wrapping to itself, since a primop is not translated to another spelling),
//    plus -- in Prim, the module the library reaches the machine through -- the
//    machine's public surface: the primitive types and the primops the machine
//    spells as operators (`:` for `listCons#`). Every other public name (`Int`,
//    `Bool`, `List`, ...) is a library declaration, in the module that owns it.
foreach ([IntrinsicRegistry\MODULE_PRIM, IntrinsicRegistry\MODULE_IO] as $moduleName) {
    $localTypes = Modules\syntheticLocalTypes($moduleName);
    $expected = [];
    foreach (\array_keys($schemes) as $name) {
        if (IntrinsicRegistry\ownerModule($name) === $moduleName) {
            $expected[$name] = true;
        }
    }
    $assert($expected !== [], "{$moduleName} should own at least one primop");

    $surface = [];
    if ($moduleName === IntrinsicRegistry\MODULE_PRIM) {
        $typeTable = IntrinsicRegistry\primTypeTable();
        foreach (\array_keys($typeTable) as $primitive) {
            $assert(
                isset($localTypes['data'][$primitive]),
                "Prim must declare its primitive type `{$primitive}`",
            );
        }
        $assert(
            \array_keys($localTypes['data']) === \array_keys($typeTable),
            'Prim must declare the primitive types and nothing else: '
                . \json_encode(\array_keys($localTypes['data'])),
        );
        $assert($localTypes['typeSynonyms'] === [], 'Prim spells no type synonyms');

        foreach (IntrinsicRegistry\publicPrimopAliases() as $alias => $primop) {
            $surface[] = $alias;
            $assert(isset($schemes[$primop]), "the public name `{$alias}` must stand for a primop");
            $assert(
                ($localTypes['intrinsicWrappers'][$alias] ?? null) === $primop,
                "the public name `{$alias}` must lower to `{$primop}`",
            );
        }
        foreach (Modules\syntheticExports($moduleName, $localTypes)['env'] as $name => $_) {
            $assert(
                \str_ends_with($name, '#') || isset(IntrinsicRegistry\publicPrimopAliases()[$name]),
                "Prim exports `{$name}`, which is neither a primop nor a machine operator",
            );
        }
    }

    $localNames = \array_keys($localTypes['env']);
    \sort($localNames);
    $expectedNames = \array_keys($expected);
    \sort($expectedNames);
    $beyondPrimops = \array_values(\array_diff($localNames, $expectedNames));
    \sort($surface);
    $assert(
        $beyondPrimops === $surface,
        "{$moduleName} env holds names beyond its primops: " . \json_encode($beyondPrimops),
    );

    foreach ($localTypes['intrinsicWrappers'] as $name => $target) {
        if (isset($expected[$name])) {
            $assert($target === $name, "{$moduleName} wrapper {$name} must wrap itself");
        }
        $assert(isset($schemes[$target]), "{$name} must wrap a registered primop, not `{$target}`");
    }

    $exports = Modules\syntheticExports($moduleName, $localTypes);
    $exportNames = \array_keys($exports['env']);
    \sort($exportNames);
    $assert($exportNames === $localNames, "{$moduleName} exports must match the names it holds");
}

echo "intrinsic registry tests passed\n";
