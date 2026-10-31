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
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

/** @return list<string> module paths reported as unused imports */
$unusedImports = static function (AnalysisService $svc, string $path): array {
    $src = (string) \file_get_contents($path);
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
        throw new \RuntimeException(\sprintf(
            '%s: unexpected %s diagnostic: %s',
            \basename($path),
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
$names = $unusedImports($svc, $fixturesDir . '/UnusedInstanceHead.mog');
$assert($names === [], 'instance-head class / primitive type / primop imports count as used: ' . \json_encode($names));
$names = $unusedImports($svc, $fixturesDir . '/UnusedTypeOnly.mog');
$assert($names === [], 'type-level use in a signature counts as used: ' . \json_encode($names));
$names = $unusedImports($svc, $fixturesDir . '/UnusedCtorPattern.mog');
$assert($names === [], 'constructor-pattern use counts as used: ' . \json_encode($names));

$names = $unusedImports($svc, $fixturesDir . '/UnusedReExport.mog');
$assert($names === [], 're-exported names count as used: ' . \json_encode($names));
$names = $unusedImports($svc, $fixturesDir . '/UnusedTruly.mog');
$assert($names === ['Data.Enum'], 'genuinely unused import is reported: ' . \json_encode($names));
foreach (['Data/Foldable.mog', 'Data/List.mog', 'Data/Either.mog'] as $stdlibModule) {
    $names = $unusedImports($svc, $root . '/lib/' . $stdlibModule);
    $assert($names === [], $stdlibModule . ' reports no unused imports and no type errors: ' . \json_encode($names));
}
$svcRoot = new AnalysisService();
$svcRoot->setWorkspaceRoot($root);
$svcRoot->warm();
$names = $unusedImports($svcRoot, $root . '/libraries/base/src/Numeric/Natural.mog');
$assert($names === [], 'libraries/base/src/Numeric/Natural.mog reports no unused imports: ' . \json_encode($names));

echo "lsp unused-import tests passed\n";
