#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcRename;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

/**
 * Collect the edit start positions ("line:character") of a rename result.
 *
 * @return list<string>
 */
$editStarts = static function (?array $result, string $uri): array {
    $starts = [];
    foreach ($result['documentChanges'] ?? [] as $change) {
        if (($change['textDocument']['uri'] ?? '') !== $uri) {
            continue;
        }
        foreach ($change['edits'] as $edit) {
            $starts[] = $edit['range']['start']['line'] . ':' . $edit['range']['start']['character'];
        }
    }
    sort($starts);
    return $starts;
};

// Case 1: value with type signature + implementation + usage.
// Renaming must update the signature, the equation, and the usage.
$src1 = <<<'MOG'
module Main where

documentedValue :: Int
documentedValue = 7

useDocumentedValue = documentedValue + 1
MOG;

$tmp1 = sys_get_temp_dir() . '/moggi-lsp-rename1-' . bin2hex(random_bytes(3));
mkdir($tmp1);
$uri1 = pathToUri($tmp1 . '/Rename1.mog');

$svc1 = new AnalysisService();
$svc1->setWorkspaceRoot($tmp1);
$svc1->openDocument($uri1, $src1, 1);
$svc1->ensureAnalyzed($uri1);

// 3 occurrences: signature (compiler line 3), equation (line 4), usage (line 6).
$occs1 = $svc1->occurrences->findInUri($uri1, 'documentedValue');
$assert(count($occs1) === 3, 'signature+equation+usage occurrences: ' . json_encode(
    array_map(static fn ($o) => ['kind' => $o->kind, 'line' => $o->range['start']['line']], $occs1)
));

// Rename from the signature line (LSP line 2 = compiler line 3).
$result1 = svcRename($svc1, $uri1, ['line' => 2, 'character' => 0], 'renamedValue');
$assert($result1 !== null, 'rename from signature returns a WorkspaceEdit');
$assert(
    $editStarts($result1, $uri1) === ['2:0', '3:0', '5:21'],
    'signature rename covers sig+equation+usage: ' . json_encode($editStarts($result1, $uri1))
);

// Rename from the equation line (LSP line 3) resolves the same symbol.
$result1b = svcRename($svc1, $uri1, ['line' => 3, 'character' => 0], 'renamedValue');
$assert(
    $result1b !== null && $editStarts($result1b, $uri1) === ['2:0', '3:0', '5:21'],
    'rename from equation covers the same occurrences: ' . json_encode($result1b === null ? null : $editStarts($result1b, $uri1))
);

// Case 2: Multi-equation function. Every equation is a defining
// occurrence; the signature and all usages rename with them.
$src2 = <<<'MOG'
module Main where

someFun :: [a] -> Int
someFun [] = 0
someFun (a : xs) = 1 + someFun xs

useSomeFun = someFun [1, 2, 3]
anotherUse = someFun []
MOG;

$tmp2 = sys_get_temp_dir() . '/moggi-lsp-rename2-' . bin2hex(random_bytes(3));
mkdir($tmp2);
$uri2 = pathToUri($tmp2 . '/Rename2.mog');

$svc2 = new AnalysisService();
$svc2->setWorkspaceRoot($tmp2);
$svc2->openDocument($uri2, $src2, 1);
$svc2->ensureAnalyzed($uri2);

// 6 occurrences: 3 defs (signature + two equations) and 3 uses
// (one recursive, two at call sites).
$defs2 = array_filter(
    $svc2->occurrences->findInUri($uri2, 'someFun'),
    static fn ($o) => $o->kind === 'def'
);
$assert(count($defs2) === 3, 'all three equations count as defs: ' . json_encode(
    array_map(static fn ($o) => $o->range['start']['line'], $svc2->occurrences->findInUri($uri2, 'someFun'))
));
$assert(
    $editStarts(svcRename($svc2, $uri2, ['line' => 2, 'character' => 0], 'renamedFun'), $uri2)
        === ['2:0', '3:0', '4:0', '4:23', '6:13', '7:13'],
    'multi-equation rename covers every equation and usage: '
        . json_encode($editStarts(svcRename($svc2, $uri2, ['line' => 2, 'character' => 0], 'renamedFun'), $uri2))
);

// Case 3: same scenarios against the shared Basic.mog workspace fixture.
$fixturesDir = $root . '/tests/lsp';
$basicPath = $fixturesDir . '/Basic.mog';
$basicUri = pathToUri($basicPath);
$basicSrc = (string) file_get_contents($basicPath);

$svc3 = new AnalysisService();
$svc3->setWorkspaceRoot($fixturesDir);
$svc3->openDocument($basicUri, $basicSrc, 1);
$svc3->warm();
$svc3->ensureAnalyzed($basicUri);

// Multi-equation function in the fixture: 3 defs (signature + two
// equations) + 3 uses (one recursive).
$someFunStarts = array_map(
    static fn ($o) => ['kind' => $o->kind, 'line' => $o->range['start']['line'], 'character' => $o->range['start']['character']],
    $svc3->occurrences->findInUri($basicUri, 'someFun')
);
$assert(count($someFunStarts) === 6, 'Basic.mog someFun has 6 occurrences: ' . json_encode($someFunStarts));

// Anchored to the fixture's own occurrences, so a fixture that gains lines does not silently move
// the target: the rename must edit exactly the occurrences the analysis found, from the signature
// and from an equation line alike.
$someFunPositions = array_map(
    static fn (array $o): string => $o['line'] . ':' . $o['character'],
    $someFunStarts
);
sort($someFunPositions);
$someFunDefLines = array_values(array_unique(array_map(
    static fn (array $o): int => $o['line'],
    array_filter($someFunStarts, static fn (array $o): bool => $o['kind'] === 'def')
)));
sort($someFunDefLines);
$assert(count($someFunDefLines) === 3, 'Basic.mog someFun is defined on three equations');

$rename3 = svcRename($svc3, $basicUri, ['line' => $someFunDefLines[0], 'character' => 0], 'renamedFun');
$assert(
    $rename3 !== null && $editStarts($rename3, $basicUri) === $someFunPositions,
    'Basic.mog someFun rename covers every equation and usage: ' . json_encode($editStarts($rename3, $basicUri))
);
$rename3Eq = svcRename($svc3, $basicUri, ['line' => $someFunDefLines[1], 'character' => 0], 'renamedFun');
$assert(
    $rename3Eq !== null && count($editStarts($rename3Eq, $basicUri)) === 6,
    'rename from an equation line edits all occurrences'
);

// Value with signature + implementation + usage in the fixture.
$docValStarts = array_map(
    static fn ($o) => ['kind' => $o->kind, 'line' => $o->range['start']['line'], 'character' => $o->range['start']['character']],
    $svc3->occurrences->findInUri($basicUri, 'documentedValue')
);
$assert(count($docValStarts) === 3, 'Basic.mog documentedValue has 3 occurrences: ' . json_encode($docValStarts));
$rename3b = svcRename($svc3, $basicUri, ['line' => min(array_map(static fn (array $o): int => $o['line'], $docValStarts)), 'character' => 0], 'renamedDocValue');
$assert($rename3b !== null && count($editStarts($rename3b, $basicUri)) === 3, 'Basic.mog documentedValue rename edits all occurrences');

// No duplicate edit ranges anywhere.
foreach ([$rename3, $rename3b] as $res) {
    foreach ($res['documentChanges'] ?? [] as $docChange) {
        $edits = $docChange['edits'];
        $seen = [];
        foreach ($edits as $e) {
            $key = $e['range']['start']['line'] . ':' . $e['range']['start']['character'];
            $assert(!isset($seen[$key]), 'no duplicate edit at ' . $key);
            $seen[$key] = true;
        }
    }
}

foreach ([$tmp1, $tmp2] as $tmp) {
    foreach (scandir($tmp) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            @unlink($tmp . '/' . $f);
        }
    }
    @rmdir($tmp);
}

echo "lsp rename tests passed\n";
