#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Unused-import hints must count every way a module can be referenced: through
 * a value, a constructor, a type constructor, a class named only by an instance
 * head, a primop from a compiler-synthesized module (which has no source AST to
 * scan), a constructor that only ever appears as a pattern, or a name the
 * module re-exports from its own export list. Genuinely unused imports must
 * still be reported.
 */

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

/** @return list<string> module paths reported as unused imports */
$unusedImports = static function (AnalysisService $svc, string $path): array {
    $src = (string) file_get_contents($path);
    $uri = pathToUri($path);
    $svc->openDocument($uri, $src, 1);
    $analysis = $svc->ensureAnalyzed($uri);
    $unused = [];
    foreach ($analysis->diagnostics as $d) {
        $code = $d['code'] ?? '';
        if ($code === 'unused-import') {
            $unused[] = (string) ($d['data']['module'] ?? '?');
            continue;
        }
        // Any other diagnostic means the file did not check, which would make
        // the unused-import hints conservatively blank: fail loudly instead.
        throw new \RuntimeException(sprintf(
            '%s: unexpected %s diagnostic: %s',
            basename($path),
            $code,
            (string) ($d['message'] ?? ''),
        ));
    }

    return $unused;
};

$fixturesDir = $root . '/tests/lsp';
$svc = new AnalysisService();
$svc->setWorkspaceRoot($fixturesDir);
$svc->warm();

// A class named only by an instance head, a primitive type named only by a type
// synonym, and primops: all three imports are used.
$names = $unusedImports($svc, $fixturesDir . '/UnusedInstanceHead.mog');
$assert($names === [], 'instance-head class / primitive type / primop imports count as used: ' . json_encode($names));

// A type constructor named only by a signature is a use.
$names = $unusedImports($svc, $fixturesDir . '/UnusedTypeOnly.mog');
$assert($names === [], 'type-level use in a signature counts as used: ' . json_encode($names));

// A type constructor named only in a signature is a use, and so are
// constructors that appear only as case patterns or in the export list.
$names = $unusedImports($svc, $fixturesDir . '/UnusedCtorPattern.mog');
$assert($names === [], 'constructor-pattern use counts as used: ' . json_encode($names));

$names = $unusedImports($svc, $fixturesDir . '/UnusedReExport.mog');
$assert($names === [], 're-exported names count as used: ' . json_encode($names));

// An import nothing refers to is still reported.
$names = $unusedImports($svc, $fixturesDir . '/UnusedTruly.mog');
$assert($names === ['Data.Enum'], 'genuinely unused import is reported: ' . json_encode($names));

// The bundled stdlib has to look clean in the editor as well. `Data.Foldable`
// names `Monoid`, `Semigroup` and `Num` only inside its class body and uses
// `<$>`/`<>` in instance method bodies, so a parse-tree-only usage walk reports
// every one of its imports; and a default method body that needs a name the
// importing module does not have (as base's own `Foldable` defaults do) shows up
// as a type error in whatever module derives it.
foreach (['Data/Foldable.mog', 'Data/List.mog', 'Data/Either.mog'] as $stdlibModule) {
    $names = $unusedImports($svc, $root . '/lib/' . $stdlibModule);
    $assert($names === [], $stdlibModule . ' reports no unused imports and no type errors: ' . json_encode($names));
}

// The stdlib's own modules must not report unused imports.
$svcRoot = new AnalysisService();
$svcRoot->setWorkspaceRoot($root);
$svcRoot->warm();
$names = $unusedImports($svcRoot, $root . '/lib/Numeric/Natural.mog');
$assert($names === [], 'lib/Numeric/Natural.mog reports no unused imports: ' . json_encode($names));

echo "lsp unused-import tests passed\n";
