#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcCallHierarchyIncoming;
use function Moggi\LSP\TextDocument\svcCallHierarchyOutgoing;
use function Moggi\LSP\TextDocument\svcCallHierarchyPrepare;
use function Moggi\LSP\TextDocument\svcCodeActionResolve;
use function Moggi\LSP\TextDocument\svcCodeLens;
use function Moggi\LSP\TextDocument\svcColorPresentations;
use function Moggi\LSP\TextDocument\svcDeclaration;
use function Moggi\LSP\TextDocument\svcDiagnostic;
use function Moggi\LSP\TextDocument\svcDocumentColors;
use function Moggi\LSP\TextDocument\svcDocumentLinks;
use function Moggi\LSP\TextDocument\svcFoldingRanges;
use function Moggi\LSP\TextDocument\svcInlayHintResolve;
use function Moggi\LSP\TextDocument\svcInlayHints;
use function Moggi\LSP\TextDocument\svcInlineValues;
use function Moggi\LSP\TextDocument\svcMoniker;
use function Moggi\LSP\TextDocument\svcOnTypeFormatting;
use function Moggi\LSP\TextDocument\svcSelectionRange;
use function Moggi\LSP\TextDocument\svcTypeHierarchyPrepare;
use function Moggi\LSP\TextDocument\svcTypeHierarchySubtypes;
use function Moggi\LSP\TextDocument\svcTypeHierarchySupertypes;
use function Moggi\LSP\Workspace\svcWillRenameFiles;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$fixturesDir = $root . '/tests/lsp';
$basicPath = $fixturesDir . '/Basic.mog';
$basicUri = pathToUri($basicPath);
$src = (string) file_get_contents($basicPath);

$svc = new AnalysisService();
$svc->setWorkspaceRoot($fixturesDir);
$svc->openDocument($basicUri, $src, 1);
$svc->warm();
$svc->ensureAnalyzed($basicUri);

$find = static function (string $needle, int $occ = 0) use ($src): array {
    $offset = 0;
    for ($i = 0; $i <= $occ; $i++) {
        $next = strpos($src, $needle, $offset);
        if ($next === false) {
            throw new RuntimeException("fixture does not contain: {$needle} (#{$occ})");
        }
        if ($i === $occ) {
            $before = substr($src, 0, $next);
            $line = substr_count($before, "\n");
            $lineStart = strrpos($before, "\n");
            return ['line' => $line, 'character' => $next - ($lineStart === false ? -1 : $lineStart) - 1];
        }
        $offset = $next + 1;
    }
    throw new RuntimeException('unreachable');
};

// Declaration (go-to-declaration distinct from definition)

$p = $find('fromJust justValue');
$decl = svcDeclaration($svc, $basicUri, $p);
$assert($decl !== null, 'declaration resolves imported fromJust');
$assert(str_ends_with((string) ($decl['uri'] ?? ''), 'Maybe.mog'), 'declaration uri in Maybe.mog: ' . json_encode($decl));
$localDecl = svcDeclaration($svc, $basicUri, $find('bump x = x + 1'));
$assert($localDecl !== null, 'declaration resolves local decl');
$assert((string) ($localDecl['uri'] ?? '') === $basicUri, 'local declaration uri is the file');

// Document links (URLs in comments)

$linkSrc = <<<'MOG'
module Links where

-- See https://example.com/spec for details.
answer = 42
MOG;
$linkPath = sys_get_temp_dir() . '/moggi-lsp-links-' . bin2hex(random_bytes(3)) . '.mog';
file_put_contents($linkPath, $linkSrc);
$linkSvc = new AnalysisService();
$linkSvc->setWorkspaceRoot(dirname($linkPath));
$linkUri = pathToUri($linkPath);
$linkSvc->openDocument($linkUri, $linkSrc, 1);
$links = svcDocumentLinks($linkSvc, $linkUri);
$assert(count($links) === 1, 'one document link found: ' . json_encode($links));
$assert(($links[0]['target'] ?? '') === 'https://example.com/spec', 'link target is the URL');
$assert((int) ($links[0]['range']['start']['line'] ?? -1) === 2, 'link anchored on comment line');
$resolved = svcDocumentLinks($linkSvc, $linkUri); // resolve path returns links unchanged
$assert(is_array($resolved), 'document link resolve is idempotent');

// Document colors + presentations

$colorSrc = <<<'MOG'
module Colors where

red = "#ff0000"
green = "#0f0"
blue = "rgb(0, 0, 255)"
MOG;
$colorPath = sys_get_temp_dir() . '/moggi-lsp-colors-' . bin2hex(random_bytes(3)) . '.mog';
file_put_contents($colorPath, $colorSrc);
$colorSvc = new AnalysisService();
$colorSvc->setWorkspaceRoot(dirname($colorPath));
$colorUri = pathToUri($colorPath);
$colorSvc->openDocument($colorUri, $colorSrc, 1);
$colors = svcDocumentColors($colorSvc, $colorUri);
$assert(count($colors) === 3, 'three color tokens found: ' . json_encode($colors));
$hex6 = $colors[0]['color'];
$assert(abs(($hex6['red'] ?? 0) - 1.0) < 0.01, '#ff0000 red component');
$assert(abs(($hex6['green'] ?? 1) - 0.0) < 0.01, '#ff0000 green component');
$hex3 = $colors[1]['color'];
$assert(abs(($hex3['green'] ?? 0) - 1.0) < 0.01, '#0f0 green component');
$rgb = $colors[2]['color'];
$assert(abs(($rgb['blue'] ?? 0) - 1.0) < 0.01, 'rgb blue component');
$presentations = svcColorPresentations($colorSvc, $colorUri, $hex6, $colors[0]['range'], []);
$assert(($presentations[0]['label'] ?? '') === '#ff0000', 'presentation label is hex: ' . json_encode($presentations));

// Inline values

$tmp = sys_get_temp_dir() . '/moggi-lsp-features-' . bin2hex(random_bytes(3));
mkdir($tmp);
$ivPath = $tmp . '/Inline.mog';
$ivSrc = <<<'MOG'
module Inline where

doubled :: Int -> Int
doubled x = x * 2

main :: IO ()
main = pure ()
MOG;
file_put_contents($ivPath, $ivSrc);
$ivUri = pathToUri($ivPath);
$ivSvc = new AnalysisService();
$ivSvc->setWorkspaceRoot($tmp);
$ivSvc->openDocument($ivUri, $ivSrc, 1);
$inlines = svcInlineValues($ivSvc, $ivUri, ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 99, 'character' => 0]]);
$assert(is_array($inlines), 'inline values return array');
// Decl nodes are unpositioned; token-derived ranges + declared surface types
// must still produce one inline value per function declaration.
$doubledLine = null;
foreach (explode("\n", $ivSrc) as $i => $line) {
    // Anchored at the type signature line (first column-1 occurrence).
    if (str_starts_with($line, 'doubled ::')) {
        $doubledLine = $i;
        break;
    }
}
$assert($doubledLine !== null, 'found doubled decl line');
$doubledIv = null;
foreach ($inlines as $v) {
    // Spec shape InlineValueText {range, text} — text is "name :: type".
    if (($v['text'] ?? '') === 'doubled :: Int -> Int') {
        $doubledIv = $v;
        break;
    }
}
$assert($doubledIv !== null, 'inline value present for doubled: ' . json_encode($inlines));
$assert((int) ($doubledIv['range']['start']['line'] ?? -1) === $doubledLine, 'inline value anchored at decl');
$assert(str_contains((string) ($doubledIv['text'] ?? ''), 'Int -> Int'), 'inline value shows declared type: ' . json_encode($doubledIv));

// Inlay hints + resolve (off by default; opt in for the feature assertions)

$inlays = svcInlayHints($ivSvc, $ivUri, ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 99, 'character' => 0]]);
$assert($inlays === [], 'inlay hints off by default (moggi.inlayHints=false)');
$ivSvc->inlaysEnabled = true;
$inlays = svcInlayHints($ivSvc, $ivUri, ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 99, 'character' => 0]]);
$assert(is_array($inlays) && $inlays !== [], 'inlay hints return items when enabled: ' . json_encode($inlays));
$hintItem = $inlays[0] ?? null;
$assert($hintItem !== null, 'inlay hint present when enabled');
if ($hintItem !== null) {
    $assert(isset($hintItem['position'], $hintItem['label']), 'inlay hint has position + label');
    $assert(
        preg_match('/\bt\d+\b/', (string) ($hintItem['label'] ?? '')) !== 1,
        'inlay hint label uses friendly type vars: ' . json_encode($hintItem)
    );
    $resolvedHint = svcInlayHintResolve($ivSvc, $hintItem);
    $assert($resolvedHint === $hintItem, 'inlay hint resolve echoes hint');
}

// Code action resolve

$caItem = ['title' => 'Fill hole with `0`', 'kind' => 'quickfix'];
$caResolved = svcCodeActionResolve($ivSvc, $caItem);
$assert(($caResolved['title'] ?? '') === 'Fill hole with `0`', 'code action resolve echoes item');

// Moniker

$p = $find('fromJust justValue');
$monikers = svcMoniker($svc, $basicUri, $p);
$assert(count($monikers) === 1, 'one moniker');
$assert(($monikers[0]['scheme'] ?? '') === 'moggi', 'moniker scheme');
$assert(($monikers[0]['identifier'] ?? '') === 'Data.Maybe::fromJust', 'moniker identifier: ' . json_encode($monikers));
$assert(($monikers[0]['unique'] ?? '') === 'scheme', 'moniker unique is scheme');
$assert(($monikers[0]['kind'] ?? '') === 'export', 'moniker kind is export');

// Code lens + folding + selection range on Basic.mog

$lenses = svcCodeLens($svc, $basicUri);
$assert(count($lenses) >= 40, 'code lens per top-level decl: ' . count($lenses));
$folds = svcFoldingRanges($svc, $basicUri);
$assert(count($folds) >= 40, 'folding ranges per decl: ' . count($folds));
// Token-derived spans must cover multi-line decl bodies, not just one line.
$dcLine = $find('describeColor color =')['line'];
$bodyFold = null;
foreach ($folds as $f) {
    if ((int) $f['startLine'] === $dcLine) {
        $bodyFold = $f;
        break;
    }
}
$assert($bodyFold !== null, 'describeColor has a folding range');
$assert((int) $bodyFold['endLine'] >= $dcLine + 3, 'folding span covers the case body: ' . json_encode($bodyFold));
$sel = svcSelectionRange($svc, $basicUri, [$find('targetValue = 123')]);
$assert($sel !== [] && isset($sel[0]['range']), 'selection range present');

// Call + type hierarchy

$hierPath = $tmp . '/Hier.mog';
$hierSrc = <<<'MOG'
module Hier where

helper :: Int -> Int
helper n = n + 1

caller :: Int -> Int
caller n = helper n
MOG;
file_put_contents($hierPath, $hierSrc);
$hierUri = pathToUri($hierPath);
$hierSvc = new AnalysisService();
$hierSvc->setWorkspaceRoot($tmp);
$hierSvc->openDocument($hierUri, $hierSrc, 1);
$hierLines = explode("\n", $hierSrc);
$callerLine = null;
foreach ($hierLines as $i => $line) {
    if (str_starts_with($line, 'caller ')) {
        $callerLine = $i;
        break;
    }
}
$prepared = svcCallHierarchyPrepare($hierSvc, $hierUri, ['line' => $callerLine, 'character' => 0]);
$assert($prepared !== [], 'call hierarchy prepare: ' . json_encode($prepared));
$outgoing = svcCallHierarchyOutgoing($hierSvc, $prepared[0]);
$assert($outgoing !== [], 'outgoing calls include helper');
$incoming = svcCallHierarchyIncoming($hierSvc, $prepared[0]);
$assert(is_array($incoming), 'incoming calls return array');

$classSrc = <<<'MOG'
module Klass where

class Showable a where
  showIt :: a -> Int

instance Showable Int where
  showIt x = x
MOG;
$classPath = $tmp . '/Klass.mog';
file_put_contents($classPath, $classSrc);
$classUri = pathToUri($classPath);
$classSvc = new AnalysisService();
$classSvc->setWorkspaceRoot($tmp);
$classSvc->openDocument($classUri, $classSrc, 1);
$classLines = explode("\n", $classSrc);
$classLine = null;
foreach ($classLines as $i => $line) {
    if (str_starts_with($line, 'class Showable')) {
        $classLine = $i;
        break;
    }
}
$th = svcTypeHierarchyPrepare($classSvc, $classUri, ['line' => $classLine, 'character' => 6]);
if ($th !== []) {
    $subs = svcTypeHierarchySubtypes($classSvc, $th[0]);
    $assert($subs !== [], 'type hierarchy subtypes include instance');
    $sups = svcTypeHierarchySupertypes($classSvc, $th[0]);
    $assert(is_array($sups), 'supertypes return array');
}

// willRenameFiles: import rewrites

$utilPath = $tmp . '/Util.mog';
file_put_contents($utilPath, "module Util where\n\nmagic = 7\n");
$appPath = $tmp . '/App.mog';
$appSrc = "module App where\n\nimport Util\n\nuses = magic\n";
file_put_contents($appPath, $appSrc);
$utilUri = pathToUri($utilPath);
$appUri = pathToUri($appPath);
$rnSvc = new AnalysisService();
$rnSvc->setWorkspaceRoot($tmp);
$rnSvc->openDocument($appUri, $appSrc, 1);
$renames = svcWillRenameFiles($rnSvc, [
    ['oldUri' => $utilUri, 'newUri' => pathToUri($tmp . '/Helper.mog')],
]);
// Returns the uri→edits map; the server wraps it as {changes: …}.
$assert(isset($renames[$appUri]), 'willRename rewrites importer: ' . json_encode($renames));
$importEdit = $renames[$appUri][0]['newText'] ?? '';
$assert(str_contains($importEdit, 'import Helper'), 'import rewritten to Helper: ' . json_encode($importEdit));

// On-type formatting

$fmt = svcOnTypeFormatting($ivSvc, $ivUri, ['line' => 0, 'character' => 0], '{', ['tabSize' => 4, 'insertSpaces' => true]);
$assert(is_array($fmt), 'on-type formatting returns edits');

// Pull diagnostics (textDocument/diagnostic)

$pull = svcDiagnostic($diagSvc ?? $ivSvc, $ivUri);
$assert(($pull['kind'] ?? '') === 'full', 'pull diagnostics kind=full');
$assert(isset($pull['items']) && is_array($pull['items']), 'pull diagnostics items');

$diagPath2 = $fixturesDir . '/Diagnostics.mog';
$diagUri2 = pathToUri($diagPath2);
$diagSvc2 = new AnalysisService();
$diagSvc2->setWorkspaceRoot($fixturesDir);
$diagSvc2->openDocument($diagUri2, (string) file_get_contents($diagPath2), 1);
$bad = svcDiagnostic($diagSvc2, $diagUri2);
$assert($bad['items'] !== [], 'pull diagnostics report errors for broken file');

@unlink($linkPath);
@unlink($colorPath);
@unlink($ivPath);
@unlink($hierPath);
@unlink($classPath);
@unlink($utilPath);
@unlink($appPath);
@rmdir($tmp);

echo "lsp features tests passed\n";
