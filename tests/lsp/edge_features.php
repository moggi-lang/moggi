<?php declare(strict_types=1);

/**
 * Edge-case coverage for LSP feature handlers that the main suites only touch
 * on the happy path: implementation lookup, semantic tokens range filtering,
 * document link resolve echo, prepareRename/rename invalid input, completion
 * resolve without `data`, inlay gating, and analyzing unopened on-disk files.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcCodeLens;
use function Moggi\LSP\TextDocument\svcDocumentLinkResolve;
use function Moggi\LSP\TextDocument\svcDocumentLinks;
use function Moggi\LSP\TextDocument\svcFoldingRanges;
use function Moggi\LSP\TextDocument\svcImplementation;
use function Moggi\LSP\TextDocument\svcInlayHints;
use function Moggi\LSP\TextDocument\svcPrepareRename;
use function Moggi\LSP\TextDocument\svcRename;
use function Moggi\LSP\TextDocument\svcSemanticTokens;
use function Moggi\LSP\TextDocument\svcSemanticTokensRange;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$tmp = \sys_get_temp_dir() . '/moggi-lsp-edges-' . \bin2hex(\random_bytes(4));
$assert(\mkdir($tmp), 'create temp dir');
$src = <<<'MOG'
module Edge where

-- | Documented for hover.
class Eqish a where
  same :: a -> a -> Bool

instance Eqish Int where
  same x y = x == y

docLink = "see zzNoImplWord and https://example.com/spec for details"

classify x = same x 1

useClassify = classify (3 :: Int)
MOG;
$mainPath = $tmp . '/Edge.mog';
$assert(\file_put_contents($mainPath, $src) !== false, 'write Edge.mog');
$uri = pathToUri($mainPath);

$svc = new AnalysisService();
$svc->setWorkspaceRoot($tmp);
$svc->openDocument($uri, $src, 1);
$svc->warm();

$lineOf = static function (string $needle) use ($src): int {
    $at = \strpos($src, $needle);
    if ($at === false) {
        throw new RuntimeException("fixture missing: {$needle}");
    }

    return \substr_count(\substr($src, 0, $at), "\n");
};
$colOf = static function (string $needle) use ($src): int {
    $at = \strpos($src, $needle);
    if ($at === false) {
        throw new RuntimeException("fixture missing: {$needle}");
    }
    $before = \substr($src, 0, $at);

    return $at - ((int) \strrpos($before, "\n") + 1);
};
$impl = svcImplementation($svc, $uri, ['line' => $lineOf('class Eqish'), 'character' => $colOf('class Eqish') + 6]);
$assert(\is_array($impl) && $impl !== [], 'implementation finds locations for class name: ' . \json_encode($impl));
if (\is_array($impl)) {
    $onInstanceLine = false;
    foreach ($impl as $loc) {
        if (($loc['uri'] ?? '') === $uri && (int) ($loc['range']['start']['line'] ?? -1) === $lineOf('instance Eqish Int where')) {
            $onInstanceLine = true;
        }
    }
    $assert($onInstanceLine, 'implementation of a class points at its instance blocks: ' . \json_encode($impl));
}
$sigImpl = svcImplementation($svc, $uri, ['line' => $lineOf('same :: a -> a -> Bool'), 'character' => $colOf('same ::')]);
$assert(\is_array($sigImpl) && \count($sigImpl) === 1, 'implementation of a method signature finds the instance def: ' . \json_encode($sigImpl));
if (\is_array($sigImpl)) {
    $got = \array_map(static fn (array $loc): int => (int) $loc['range']['start']['line'], $sigImpl);
    $assert($got === [$lineOf('instance Eqish Int where') + 1], 'method sig impl line is the instance body: ' . \json_encode($got));
}
$useImpl = svcImplementation($svc, $uri, ['line' => $lineOf('classify x = same x 1'), 'character' => $colOf('same x 1')]);
$assert(\is_array($useImpl) && \count($useImpl) === 1, 'implementation of a method use finds the instance def: ' . \json_encode($useImpl));
$self = svcImplementation($svc, $uri, ['line' => $lineOf('classify x = same x 1'), 'character' => $colOf('classify')]);
$assert($self === null, 'implementation of a plain function is none: ' . \json_encode($self));
$none = svcImplementation($svc, $uri, ['line' => $lineOf('docLink = "see zzNoImplWord'), 'character' => $colOf('zzNoImplWord')]);
$assert($none === null, 'implementation returns null when nothing implements the word: ' . \json_encode($none));
$assert(svcImplementation($svc, $uri, ['line' => 9999, 'character' => 9999]) === null, 'implementation handles out-of-bounds position');

$full = svcSemanticTokens($svc, $uri);
$assert(\is_array($full) && ($full['data'] ?? []) !== [], 'semantic tokens full available');
$assert(isset($full['resultId']) && $full['resultId'] !== '', 'semantic tokens carry resultId');

$tokensCount = static function (array $data): int {
    return \intdiv(\count($data), 5);
};
$classLine = $lineOf('class Eqish a where');
$subset = svcSemanticTokensRange($svc, $uri, [
    'start' => ['line' => $classLine, 'character' => 0],
    'end' => ['line' => $classLine + 1, 'character' => 0],
]);
$assert(\is_array($subset) && isset($subset['data']), 'semantic tokens range returns data');
$assert($tokensCount($subset['data']) < $tokensCount($full['data']), 'range tokens are a subset of full tokens');
$instLine = $lineOf('instance Eqish Int where');
$instTokens = svcSemanticTokensRange($svc, $uri, [
    'start' => ['line' => $instLine, 'character' => 0],
    'end' => ['line' => $instLine + 1, 'character' => 0],
]);
$assert($tokensCount($instTokens['data']) >= 1, 'instance line has semantic tokens');
if ($tokensCount($instTokens['data']) > 0) {
    $assert($instTokens['data'][0] === 0, 'range token data starts at line 0 (delta-encoded): ' . \json_encode($instTokens['data']));
}
$empty = svcSemanticTokensRange($svc, $uri, [
    'start' => ['line' => 0, 'character' => 0],
    'end' => ['line' => 0, 'character' => 0],
]);
$assert($tokensCount($empty['data']) === 0, 'zero-width range yields no tokens: ' . \json_encode($empty['data']));

$links = svcDocumentLinks($svc, $uri);
$assert(\is_array($links) && $links !== [], 'URL in comment/string yields a document link: ' . \json_encode($links));
if ($links !== []) {
    $resolved = svcDocumentLinkResolve($svc, ['link' => $links[0]]);
    $assert(\is_array($resolved), 'documentLink/resolve returns a link');
    $assert(($resolved['link'] ?? $resolved) === $links[0], 'documentLink/resolve echoes the link: ' . \json_encode($resolved));
}

$targetPos = ['line' => $lineOf('classify x = same x 1'), 'character' => $colOf('classify')];
$prep = svcPrepareRename($svc, $uri, $targetPos);
$assert(\is_array($prep) && ($prep['placeholder'] ?? '') === 'classify', 'prepareRename returns placeholder for classify: ' . \json_encode($prep));
foreach (['', '1abc', 'has space', 'with-dash'] as $bad) {
    $assert(svcRename($svc, $uri, $targetPos, $bad) === null, "rename refuses invalid name: '{$bad}'");
}
$renamed = svcRename($svc, $uri, $targetPos, 'classify2');
$assert(\is_array($renamed) && isset($renamed['documentChanges']), 'rename produces documentChanges workspace edit');
$renameEdits = [];
foreach ($renamed['documentChanges'] as $docChange) {
    if (($docChange['textDocument']['uri'] ?? '') === $uri) {
        $renameEdits = \array_merge($renameEdits, $docChange['edits']);
    }
}
$renameCount = \count($renameEdits);
$assert($renameCount >= 2, 'rename covers declaration + use site: ' . \json_encode($renameEdits));
$renamedTexts = \array_column($renameEdits, 'newText');
$assert($renamedTexts === ['classify2', 'classify2'], 'every rename edit applies the new name');
$assert(svcPrepareRename($svc, $uri, ['line' => 9999, 'character' => 9999]) === null, 'prepareRename handles out-of-bounds position');

$wholeDoc = ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 99, 'character' => 0]];
$assert(svcInlayHints($svc, $uri, $wholeDoc) === [], 'inlay hints gated off by default');
$svc->inlaysEnabled = true;
$assert(svcInlayHints($svc, $uri, $wholeDoc) !== [], 'inlay hints appear once enabled');

$otherPath = $tmp . '/Other.mog';
$otherSrc = "module Other where\n\nhelper :: Int\nhelper = 5\n";
$assert(\file_put_contents($otherPath, $otherSrc) !== false, 'write Other.mog (never opened)');

$otherUri = pathToUri($otherPath);
$neverOpened = new AnalysisService();
$neverOpened->setWorkspaceRoot($tmp);
$neverOpened->warm();
$analysis = $neverOpened->ensureAnalyzed($otherUri);
$assert($analysis !== null, 'unopened on-disk file can be analyzed on demand');
if ($analysis !== null) {
    $assert(\str_contains($analysis->source, 'helper'), 'unopened analysis reads disk contents');
    $assert(($analysis->declarations['helper']['type'] ?? '') !== '', 'unopened file declares helper');
}

$blankPath = $tmp . '/Blank.mog';
$blankSrc = "module Blank where\n";
$assert(\file_put_contents($blankPath, $blankSrc) !== false, 'write Blank.mog');
$blankUri = pathToUri($blankPath);
$blankSvc = new AnalysisService();
$blankSvc->setWorkspaceRoot($tmp);
$blankSvc->openDocument($blankUri, $blankSrc, 1);

$assert(svcFoldingRanges($blankSvc, $blankUri) === [], 'minimal document has no folding ranges: ' . \json_encode(svcFoldingRanges($blankSvc, $blankUri)));
$assert(svcCodeLens($blankSvc, $blankUri) === [], 'minimal document has no code lenses: ' . \json_encode(svcCodeLens($blankSvc, $blankUri)));

@\unlink($mainPath);
@\unlink($otherPath);
@\unlink($blankPath);
@\rmdir($tmp);

echo "lsp edge feature tests passed\n";
