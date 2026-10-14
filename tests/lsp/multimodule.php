#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcCodeActions;
use function Moggi\LSP\TextDocument\svcCompletion;
use function Moggi\LSP\TextDocument\svcCompletionResolve;
use function Moggi\LSP\TextDocument\svcDefinition;
use function Moggi\LSP\TextDocument\svcHover;
use function Moggi\LSP\TextDocument\svcReferences;
use function Moggi\LSP\TextDocument\svcRename;
use function Moggi\LSP\TextDocument\svcWorkspaceSymbol;
use function Moggi\LSP\TextDocument\holeFillStub;
use function Moggi\LSP\TextDocument\svcCallHierarchyPrepare;
use function Moggi\LSP\TextDocument\svcCallHierarchyOutgoing;
use function Moggi\LSP\TextDocument\svcSemanticTokens;
use function Moggi\LSP\Formatter\formatMoggiSource;
use function Moggi\LSP\TextDocument\svcTypeHierarchyPrepare;
use function Moggi\LSP\TextDocument\svcTypeHierarchySubtypes;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$tmp = sys_get_temp_dir() . '/moggi-lsp-multi-' . bin2hex(random_bytes(3));
mkdir($tmp);
$lib = $tmp . '/Lib.mog';
$main = $tmp . '/Main.mog';

file_put_contents($lib, <<<'MOG'
module Lib where

-- | Exported constant.
answer :: Int
answer = 42
MOG);

file_put_contents($main, <<<'MOG'
module Main where

import Lib

useAnswer :: Int
useAnswer = answer
MOG);

$svc = new AnalysisService();
$svc->setWorkspaceRoot($tmp);
$mainUri = pathToUri($main);
$libUri = pathToUri($lib);
$svc->openDocument($libUri, (string) file_get_contents($lib), 1);
$svc->openDocument($mainUri, (string) file_get_contents($main), 1);
$svc->ensureAnalyzed($libUri);
$mainResult = $svc->ensureAnalyzed($mainUri);
$assert($mainResult !== null, 'main analyzed');
$assert(
    isset($svc->modules->modules['Lib']) || isset($svc->modules->modules['Main']),
    'module index populated',
);

$lines = explode("\n", (string) file_get_contents($main));
$useLine = null;
foreach ($lines as $i => $line) {
    if (str_contains($line, 'answer') && str_contains($line, '=')) {
        $useLine = $i;
        break;
    }
}
$assert($useLine !== null, 'found use line');
$col = strpos($lines[$useLine], 'answer');
$def = svcDefinition($svc, $mainUri, ['line' => $useLine, 'character' => (int) $col]);
$assert($def !== null, 'cross-module definition got: ' . json_encode($def) . ' diags=' . json_encode($mainResult->diagnostics));
$assert(
    str_contains((string) ($def['uri'] ?? ''), 'Lib.mog') || ($def['uri'] ?? '') === $libUri,
    'def uri is Lib: ' . json_encode($def),
);

$refs = svcReferences($svc, $mainUri, ['line' => $useLine, 'character' => (int) $col], true);
$assert(count($refs) >= 1, 'references non-empty');

$renamed = svcRename($svc, $mainUri, ['line' => $useLine, 'character' => (int) $col], 'solution');
$assert($renamed !== null && isset($renamed['documentChanges']), 'cross-file rename workspace edit');

// Slice 2: edit Lib → Main's import of answer stays navigable after reindex
$newLib = <<<'MOG'
module Lib where

-- | Renamed export.
answer :: Int
answer = 99
MOG;
file_put_contents($lib, $newLib);
$svc->changeDocument($libUri, [['text' => $newLib]], 2);
$svc->ensureAnalyzed($libUri);
$svc->ensureAnalyzed($mainUri);
$def2 = svcDefinition($svc, $mainUri, ['line' => $useLine, 'character' => (int) $col]);
$assert($def2 !== null, 'def still works after Lib edit');
$assert(
    str_contains((string) ($def2['uri'] ?? ''), 'Lib.mog') || ($def2['uri'] ?? '') === $libUri,
    'def still Lib after reindex',
);

// Warm prepare caches (DocIndex is lazy — avoid cold rebuild in unit tests)
$svc->warm();
$ws = svcWorkspaceSymbol($svc, 'answer');
$assert(\is_array($ws) && $ws !== [], 'workspace symbol finds answer from module index');

$comp = svcCompletion($svc, $mainUri, ['line' => $useLine, 'character' => (int) $col]);
$assert(isset($comp['items']), 'completion has items');
if ($comp['items'] !== []) {
    $resolved = svcCompletionResolve($svc, $comp['items'][0]);
    $assert(\is_array($resolved), 'completion resolve returns item');
}

$hover = svcHover($svc, $mainUri, ['line' => $useLine, 'character' => (int) $col]);
$assert($hover !== null, 'hover on imported answer');
$assert(
    str_contains((string) ($hover['contents']['value'] ?? ''), 'origin')
    || str_contains((string) ($hover['contents']['value'] ?? ''), 'Int')
    || str_contains((string) ($hover['contents']['value'] ?? ''), 'answer'),
    'richer hover content: ' . json_encode($hover)
);

$assert(holeFillStub('Int') === '0', 'hole stub Int');
$assert(holeFillStub('Maybe a') === 'Nothing', 'hole stub Maybe');
$assert(holeFillStub('Foo') === 'undefined', 'hole stub fallback');
$assert(holeFillStub('Bool') === 'False', 'hole stub Bool');
$assert(holeFillStub('Int -> Int') === '\\_ -> undefined', 'hole stub arrow');

// Typed hole `_`: diagnostic must include expected type + fill code action
$typedHoleSrc = <<<'MOG'
module TypedHole where

needInt :: Int
needInt = _
MOG;
$typedHolePath = $tmp . '/TypedHole.mog';
file_put_contents($typedHolePath, $typedHoleSrc);
$typedHoleUri = pathToUri($typedHolePath);
$svc->openDocument($typedHoleUri, $typedHoleSrc, 1);
$thr = $svc->ensureAnalyzed($typedHoleUri);
$assert($thr !== null, 'typed hole module analyzed');
$holeDiag = null;
foreach ($thr->diagnostics as $d) {
    $msg = (string) ($d['message'] ?? '');
    $code = (string) ($d['code'] ?? '');
    if ($code === 'hole' || str_contains($msg, 'Found hole with type') || str_contains($msg, 'hole with type')) {
        $holeDiag = $d;
        break;
    }
}
$assert($holeDiag !== null, 'expected hole diagnostic: ' . json_encode($thr->diagnostics));
$assert(
    ($holeDiag['code'] ?? '') === 'hole',
    'hole diagnostic code: ' . json_encode($holeDiag),
);
$holeType = (string) (($holeDiag['data']['holeType'] ?? '') ?: '');
if ($holeType === '' && preg_match('/hole with type:\s*`([^`]+)`/i', (string) ($holeDiag['message'] ?? ''), $hm)) {
    $holeType = $hm[1];
}
$assert($holeType !== '', 'hole type in diagnostic data/message: ' . json_encode($holeDiag));
$assert(
    str_contains($holeType, 'Int') || str_contains((string) ($holeDiag['message'] ?? ''), 'Int'),
    'hole type mentions Int: ' . json_encode($holeDiag),
);
$holeActions = svcCodeActions($svc, $typedHoleUri, $holeDiag['range'], ['diagnostics' => [$holeDiag]]);
$holeTitles = array_map(static fn ($a) => $a['title'] ?? '', $holeActions);
$assert(
    count(array_filter($holeTitles, static fn ($t) => str_contains((string) $t, 'Fill hole') || str_contains((string) $t, 'undefined'))) >= 1,
    'hole fill code action: ' . json_encode($holeTitles),
);
$fill = null;
foreach ($holeActions as $a) {
    if (str_contains((string) ($a['title'] ?? ''), 'Fill hole')) {
        $fill = $a;
        break;
    }
}
$assert($fill !== null, 'preferred fill action present');
$newText = $fill['edit']['documentChanges'][0]['edits'][0]['newText'] ?? null;
$assert($newText === '0', 'Int hole fills with 0, got: ' . json_encode($fill));

$thLines = explode("\n", $typedHoleSrc);
$holeLine = null;
foreach ($thLines as $i => $line) {
    if (str_contains($line, '= _')) {
        $holeLine = $i;
        break;
    }
}
$assert($holeLine !== null, 'hole line');
$holeCol = strpos($thLines[$holeLine], '_');
$holeHover = svcHover($svc, $typedHoleUri, ['line' => $holeLine, 'character' => (int) $holeCol]);
$assert($holeHover !== null, 'hover on hole');
$hoverVal = (string) ($holeHover['contents']['value'] ?? '');
$assert(
    str_contains($hoverVal, 'Int') || str_contains($hoverVal, 'hole'),
    'hover shows hole type: ' . $hoverVal,
);

// Slice 3: exhaustiveness actions
$holeSrc = <<<'MOG'
module Hole where

data Colour = Red | Green | Blue

f :: Colour -> Int
f c = case c of
  Red -> 1
MOG;
$holePath = $tmp . '/Hole.mog';
file_put_contents($holePath, $holeSrc);
$holeUri = pathToUri($holePath);
$svc->openDocument($holeUri, $holeSrc, 1);
$hr = $svc->ensureAnalyzed($holeUri);
$assert($hr !== null, 'hole module analyzed');
$hasNonEx = false;
foreach ($hr->diagnostics as $d) {
    if (($d['code'] ?? '') === 'non-exhaustive' || str_contains((string) ($d['message'] ?? ''), 'non-exhaustive')) {
        $hasNonEx = true;
        $actions = svcCodeActions($svc, $holeUri, $d['range'], ['diagnostics' => [$d]]);
        $titles = array_map(static fn ($a) => $a['title'] ?? '', $actions);
        $assert(
            in_array('Insert missing case arms', $titles, true),
            'exhaustiveness code action: ' . json_encode($titles) . ' diags=' . json_encode($hr->diagnostics),
        );
        break;
    }
}
$assert($hasNonEx, 'expected non-exhaustive diagnostic: ' . json_encode($hr->diagnostics));

// Binder-safe local rename
$bindSrc = <<<'MOG'
module Bind where

outer :: Int -> Int
outer x =
  let y = x in
  let x = y + 1 in
  x
MOG;
$bindPath = $tmp . '/Bind.mog';
file_put_contents($bindPath, $bindSrc);
$bindUri = pathToUri($bindPath);
$svc->openDocument($bindUri, $bindSrc, 1);
$br = $svc->ensureAnalyzed($bindUri);
$assert($br !== null && $br->diagnostics === [], 'binder module clean: ' . json_encode($br->diagnostics ?? null));
$bindLines = explode("\n", $bindSrc);
$innerLine = null;
foreach ($bindLines as $i => $line) {
    if (preg_match('/^\s*let x =/', $line)) {
        $innerLine = $i;
        break;
    }
}
$assert($innerLine !== null, 'found inner binder line');
$innerCol = strpos($bindLines[$innerLine], 'x');
$ren = svcRename($svc, $bindUri, ['line' => $innerLine, 'character' => (int) $innerCol], 'z');
$assert($ren !== null && isset($ren['documentChanges']), 'local rename produced edits');
$edits = [];
foreach ($ren['documentChanges'] as $docChange) {
    if (($docChange['textDocument']['uri'] ?? '') === $bindUri) {
        $edits = array_merge($edits, $docChange['edits']);
    }
}
$assert(count($edits) >= 1, 'at least one rename edit');
// Must not rename the outer parameter or its uses
$badLines = [];
foreach ($edits as $e) {
    $ln = (int) ($e['range']['start']['line'] ?? -1);
    // line 2: `outer x =`, line 3: `let y = x in` (outer x)
    if ($ln === 2 || $ln === 3) {
        $badLines[] = $ln;
    }
}
$assert($badLines === [], 'binder-safe rename must not touch outer x: ' . json_encode($edits));

// Cross-module binder isolation: local `x` in A and B must not collide
$aSrc = <<<'MOG'
module A where

f :: Int -> Int
f x = x + 1
MOG;
$bSrc = <<<'MOG'
module B where

g :: Int -> Int
g x = x + 2
MOG;
$aPath = $tmp . '/A.mog';
$bPath = $tmp . '/B.mog';
file_put_contents($aPath, $aSrc);
file_put_contents($bPath, $bSrc);
$aUri = pathToUri($aPath);
$bUri = pathToUri($bPath);
$svc->openDocument($aUri, $aSrc, 1);
$svc->openDocument($bUri, $bSrc, 1);
$svc->ensureAnalyzed($aUri);
$svc->ensureAnalyzed($bUri);
$aLines = explode("\n", $aSrc);
$aParamLine = null;
foreach ($aLines as $i => $line) {
    if (preg_match('/^f x =/', $line)) {
        $aParamLine = $i;
        break;
    }
}
$assert($aParamLine !== null, 'found A param line');
$aCol = strpos($aLines[$aParamLine], 'x');
$renA = svcRename($svc, $aUri, ['line' => $aParamLine, 'character' => (int) $aCol], 'n');
$assert($renA !== null, 'rename in A');
$aEditCount = 0;
$bEditCount = 0;
foreach ($renA['documentChanges'] ?? [] as $docChange) {
    $u = $docChange['textDocument']['uri'] ?? '';
    if ($u === $aUri) {
        $aEditCount += count($docChange['edits']);
    }
    if ($u === $bUri) {
        $bEditCount += count($docChange['edits']);
    }
}
$assert($aEditCount > 0, 'A has edits');
$assert($bEditCount === 0, 'rename in A must not touch B: ' . json_encode($renA));

// Hierarchy: outgoing calls from a caller
$callSrc = <<<'MOG'
module Call where

helper :: Int -> Int
helper n = n + 1

caller :: Int -> Int
caller n = helper n
MOG;
$callPath = $tmp . '/Call.mog';
file_put_contents($callPath, $callSrc);
$callUri = pathToUri($callPath);
$svc->openDocument($callUri, $callSrc, 1);
$svc->ensureAnalyzed($callUri);
$callLines = explode("\n", $callSrc);
$callerLine = null;
foreach ($callLines as $i => $line) {
    if (str_starts_with($line, 'caller ')) {
        $callerLine = $i;
        break;
    }
}
$assert($callerLine !== null, 'caller line');
$prep = svcCallHierarchyPrepare($svc, $callUri, ['line' => $callerLine, 'character' => 0]);
$assert($prep !== [], 'call hierarchy prepare: ' . json_encode($prep));
$outCalls = svcCallHierarchyOutgoing($svc, $prep[0]);
$assert($outCalls !== [], 'outgoing calls non-empty: ' . json_encode($outCalls));

$toks = svcSemanticTokens($svc, $callUri);
$assert($toks !== null && isset($toks['data']) && $toks['data'] !== [], 'AST semantic tokens');

$formatted = formatMoggiSource($callSrc);
$assert(str_contains($formatted, 'helper'), 'formatter keeps helper');
$assert(str_ends_with($formatted, "\n"), 'formatter EOF newline');

$classSrc = <<<'MOG'
module Hier where

class Showable a where
  showIt :: a -> Int

instance Showable Int where
  showIt x = x
MOG;
$hierPath = $tmp . '/Hier.mog';
file_put_contents($hierPath, $classSrc);
$hierUri = pathToUri($hierPath);
$svc->openDocument($hierUri, $classSrc, 1);
$svc->ensureAnalyzed($hierUri);
$hierLines = explode("\n", $classSrc);
$eqLine = null;
foreach ($hierLines as $i => $line) {
    if (str_starts_with($line, 'class Showable')) {
        $eqLine = $i;
        break;
    }
}
$assert($eqLine !== null, 'class Showable line');
$th = svcTypeHierarchyPrepare($svc, $hierUri, ['line' => $eqLine, 'character' => 6]);
if ($th !== []) {
    $subs = svcTypeHierarchySubtypes($svc, $th[0]);
    $assert($subs !== [], 'type hierarchy subtypes include instance: ' . json_encode($subs));
}

@unlink($lib);
@unlink($main);
@unlink($holePath);
@unlink($typedHolePath);
@unlink($bindPath);
@unlink($aPath);
@unlink($bPath);
@unlink($callPath);
@unlink($hierPath);
@rmdir($tmp);

echo "lsp multimodule tests passed\n";
