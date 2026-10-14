#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcCompletion;
use function Moggi\LSP\TextDocument\svcCompletionResolve;
use function Moggi\LSP\TextDocument\svcDeclaration;
use function Moggi\LSP\TextDocument\svcDefinition;
use function Moggi\LSP\TextDocument\svcDocumentHighlight;
use function Moggi\LSP\TextDocument\svcDocumentSymbols;
use function Moggi\LSP\TextDocument\svcHover;
use function Moggi\LSP\TextDocument\svcPrepareRename;
use function Moggi\LSP\TextDocument\svcReferences;
use function Moggi\LSP\TextDocument\svcRename;
use function Moggi\LSP\TextDocument\svcSignatureHelp;
use function Moggi\LSP\TextDocument\svcTypeDefinition;
use function Moggi\LSP\TextDocument\svcWorkspaceSymbol;

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

/**
 * 0-based {line, character} of the Nth occurrence of a substring in the source.
 */
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
    throw new RuntimeException("unreachable");
};

$at = static fn (string $needle, int $occ = 0): array => ['line' => $find($needle, $occ)['line'], 'character' => $find($needle, $occ)['character']];

// Go to definition

// targetValue: use site → declaration
$p = $find('useTarget = targetValue');
$def = svcDefinition($svc, $basicUri, ['line' => $p['line'], 'character' => $p['character'] + 12]);
$assert($def !== null, 'definition resolves targetValue');
$assert(($def['uri'] ?? '') === $basicUri, 'targetValue defined in Basic.mog');
$declTarget = $find('targetValue = 123');
$assert((int) $def['range']['start']['line'] === $declTarget['line'], 'targetValue definition line');

// Person constructor used in `alice = Person "Alice" 42`
$p = $find('alice = Person');
$def = svcDefinition($svc, $basicUri, ['line' => $p['line'], 'character' => $p['character'] + 8]);
$assert($def !== null, 'definition resolves Person constructor');
$assert((int) $def['range']['start']['line'] === $find('data Person')['line'], 'Person definition at data decl');

// Red constructor used in `red = Red` (R at column offset +6)
$p = $find('red = Red');
$def = svcDefinition($svc, $basicUri, ['line' => $p['line'], 'character' => $p['character'] + 6]);
$assert($def !== null, 'definition resolves Red constructor');
$assert((int) $def['range']['start']['line'] === $find('  = Red')['line'], 'Red definition at constructor alt');

// Imported declaration: fromJust → Data.Maybe
$p = $find('fromJust justValue');
$def = svcDefinition($svc, $basicUri, $p);
$assert($def !== null, 'definition resolves imported fromJust');
$assert(str_ends_with((string) ($def['uri'] ?? ''), 'Maybe.mog'), 'fromJust defined in Maybe.mog: ' . json_encode($def));

// Local binding: use of incrementedLocal inside localExample (2nd occurrence)
$def = svcDefinition($svc, $basicUri, $find('incrementedLocal', 1));
$assert($def !== null, 'definition resolves local binding');
$assert((int) $def['range']['start']['line'] === $find('incrementedLocal = doubledLocal')['line'], 'local binding definition line');

// Find references

$p = $at('targetValue = 123');
$refs = svcReferences($svc, $basicUri, $p, true);
$refLines = array_map(static fn (array $r): int => (int) $r['range']['start']['line'], $refs);
// declaration + two uses in useTarget + one use in targetFunction
$assert(count($refs) >= 4, "targetValue has >= 4 occurrences: " . json_encode($refLines));
$assert(in_array($find('useTarget = targetValue + targetValue')['line'], $refLines, true), 'refs include useTarget line');

$refs = svcReferences($svc, $basicUri, $find('bump x = x + 1'), true);
$bumpUses = array_map(static fn (array $r): int => (int) $r['range']['start']['line'], $refs);
$assert(in_array($find('incremented = bump 41')['line'], $bumpUses, true), 'bump refs include incremented');
$assert(in_array($find('mapped = map bump numbers')['line'], $bumpUses, true), 'bump refs include mapped');

// Constructor definition occurrences (token-derived positions)
$p = $find('red = Red');
$redAt = ['line' => $p['line'], 'character' => $p['character'] + 6];
$redRefs = svcReferences($svc, $basicUri, $redAt, true);
$redLines = array_map(static fn (array $r): int => (int) $r['range']['start']['line'], $redRefs);
$assert(in_array($find('  = Red')['line'], $redLines, true), 'Red refs include constructor def: ' . json_encode($redLines));
$assert(in_array($p['line'], $redLines, true), 'Red refs include use site');
$redUsesOnly = svcReferences($svc, $basicUri, $redAt, false);
$redUseLines = array_map(static fn (array $r): int => (int) $r['range']['start']['line'], $redUsesOnly);
$assert(!in_array($find('  = Red')['line'], $redUseLines, true), 'includeDeclaration=false drops ctor def');
$assert(in_array($p['line'], $redUseLines, true), 'includeDeclaration=false keeps use site');

// Hover

$hoverText = static function (array $pos) use ($svc, $basicUri): string {
    $hover = svcHover($svc, $basicUri, $pos);
    if ($hover === null) {
        return '';
    }
    return preg_replace('/\s+/', ' ', (string) ($hover['contents']['value'] ?? ''));
};

// TargetFunction: hover shows inferred/function type
$value = $hoverText($find('targetFunction x = x + targetValue'));
$assert(str_contains($value, 'targetFunction :: Int -> Int'), "hover targetFunction type: {$value}");

// Just: hover shows the constructor's own signature, not the bare result type
$p = $find('justValue = Just 42');
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 12]);
$assert(str_contains($value, 'Just :: a -> Maybe a'), "hover Just shows ctor signature: {$value}");
$assert(!preg_match('/\bt\d+\b/', $value), "hover Just has no raw type vars: {$value}");
// The value's own hover still shows the constructed type Maybe Int
$value = $hoverText($find('justValue = Just 42'));
$assert(str_contains($value, 'Maybe Int'), "hover justValue shows Maybe Int: {$value}");

// FromJust: reference hover shows the imported declaration's scheme
$value = $hoverText($find('fromJust justValue'));
$assert(str_contains($value, 'fromJust :: Maybe a -> a'), "hover fromJust shows declared scheme: {$value}");
$assert(!preg_match('/\bt\d+\b/', $value), "hover fromJust has no raw type vars: {$value}");
// Hovering the whole application instantiates the element type to `Int`
$appHover = svcHover($svc, $basicUri, ['line' => $find('fromJust justValue')['line'], 'character' => $find('fromJust justValue')['character'] + 11]);
$appType = (string) ($appHover['contents']['value'] ?? '');
$assert(
    $appHover !== null && preg_match('/\bInt\b/', $appType) === 1,
    'hover fromJust application shows Int: ' . json_encode($appHover)
);

// Numbers: hover shows [Int] (a restricted binding defaults to Int)
$value = $hoverText($find('numbers = [1, 2, 3, 4, 5]'));
$assert(str_contains($value, '[Int]'), "hover numbers shows [Int]: {$value}");

// Mapped: hover shows [Int]
$value = $hoverText($find('mapped = map bump numbers'));
$assert(str_contains($value, '[Int]'), "hover mapped shows [Int]: {$value}");

// Alice: hover shows Person
$value = $hoverText($find('alice = Person "Alice" 42'));
$assert(str_contains($value, 'Person'), "hover alice shows Person: {$value}");

// Increment (bump): hover + references
$value = $hoverText($find('bump x = x + 1'));
$assert(str_contains($value, 'bump :: Num a => a -> a'), "hover bump shows signature: {$value}");

// incrementedLocal inside localExample: the local binding is monomorphic in the
// enclosing variable (`localExample :: Num a => a -> a`), i.e. base's monomorphism
// restriction does not generalize a binding without arguments (2nd occurrence).
$value = $hoverText($find('incrementedLocal', 1));
$assert(str_contains($value, 'incrementedLocal :: a'), "hover local binding shows its monomorphic type: {$value}");

// Red: constructor hover resolves
$p = $find('red = Red');
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 6]);
$assert(str_contains($value, 'Red'), "hover Red shows constructor: {$value}");

// foo = 1 shows inferred Int
$value = $hoverText($find('anInt = 42'));
$assert(str_contains($value, 'Int'), "hover anInt shows Int: {$value}");

// Mogdoc documentation exposed in hover
$value = $hoverText($find('documentedValue :: Int'));
$assert(str_contains($value, 'Documented value for hover tests.'), "hover shows mogdoc: {$value}");

// Hover regressions: single type block, friendly vars, decl hovers

$noRawVars = static fn (string $v): bool => preg_match('/\bt\d+\b/', $v) !== 1;
$typeBlocks = static function (string $value): int {
    return substr_count($value, '```moggi');
};

// anInt: exactly one type block (dedupe), friendly content
$value = $hoverText($find('anInt = 42'));
$assert($typeBlocks($value) === 1, "hover anInt has exactly one type block: {$value}");
$assert(str_contains($value, 'anInt :: Int'), "hover anInt signature once: {$value}");

// bump: exactly one type block
$value = $hoverText($find('bump x = x + 1'));
$assert($typeBlocks($value) === 1, "hover bump has exactly one type block: {$value}");
$assert(str_contains($value, 'bump :: Num a => a -> a'), "hover bump signature once: {$value}");

// compose: nested arrows parenthesized, friendly letters, single block
$value = $hoverText($find('compose f g x = f (g x)'));
$assert($typeBlocks($value) === 1, "hover compose has exactly one type block: {$value}");
$assert(str_contains($value, 'compose :: (a -> b) -> (c -> a) -> c -> b'), "hover compose parenthesized: {$value}");
$assert($noRawVars($value), "hover compose has no raw t-vars: {$value}");

// compose parameter hovers: sliced from the function scheme, shared letters
$p = $find('compose f g x = f (g x)');
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 8]); // f
$assert(str_contains($value, 'f :: a -> b'), "hover param f: {$value}");
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 10]); // g
$assert(str_contains($value, 'g :: c -> a'), "hover param g: {$value}");
// body uses of f/g/x keep the same letters as the declaration
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 16]); // f use
$assert(str_contains($value, 'f :: a -> b'), "hover body f use: {$value}");
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 19]); // g use
$assert(str_contains($value, 'g :: c -> a'), "hover body g use: {$value}");
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 21]); // x use
$assert(str_contains($value, 'x :: c'), "hover body x use: {$value}");
// hovering one char past a 1-char binder still resolves
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 22]); // past x
$assert(str_contains($value, 'x :: c'), "hover past end of x: {$value}");
$assert($noRawVars($value), "hover param has no raw t-vars: {$value}");

// data decl hover: full declaration once, no duplicated junk, no 'Name :: data'
$p = $find('data Person');
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 5]); // on Person
$assert($typeBlocks($value) === 1, "hover Person data has one type block: {$value}");
$assert(str_contains($value, 'data Person = Person String Int'), "hover Person data decl: {$value}");
$assert(!str_contains($value, 'Person :: data'), "no 'Person :: data' junk: {$value}");

// constructor inside data decl: its own signature
$p = $find('  = Person String Int');
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 4]);
$assert(str_contains($value, 'Person :: String -> Int -> Person'), "hover ctor in data decl: {$value}");

// constructor use site: signature + constructor label
$p = $find('alice = Person "Alice" 42');
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 8]);
$assert(str_contains($value, 'Person :: String -> Int -> Person'), "hover ctor use signature: {$value}");
$assert(str_contains($value, '_constructor_'), "hover ctor use label: {$value}");

// type name in a signature annotation: resolves the type decl (Maybe.mog)
$p = $find('describeMaybe value =');
$value = $hoverText(['line' => $p['line'], 'character' => $p['character'] + 16]); // on `value`
$assert(str_contains($value, 'Maybe'), "hover describeMaybe param shows Maybe: {$value}");
$assert($noRawVars($value), "hover describeMaybe param has no raw t-vars: {$value}");

// all local hovers carry a sensible defined-in line (1-based, on the decl line)
$p = $find('bump x = x + 1');
$value = $hoverText($p);
$assert(
    preg_match('/defined in:\*\* Basic\.mog:' . ($p['line'] + 1) . '\b/', $value) === 1,
    "hover bump defined-in line: {$value}"
);

// Type definition

// Red → data Color
$p = $find('red = Red');
$typedef = svcTypeDefinition($svc, $basicUri, ['line' => $p['line'], 'character' => $p['character'] + 6]);
$assert($typedef !== null, 'typeDefinition resolves Red');
$assert((int) $typedef['range']['start']['line'] === $find('data Color')['line'], 'Red typedef lands on data Color');

// justValue value → data Maybe
$p = $find('justValue = Just 42');
$typedef = svcTypeDefinition($svc, $basicUri, ['line' => $p['line'], 'character' => $p['character'] + 1]);
$assert($typedef !== null, 'typeDefinition resolves justValue');
$assert(str_ends_with((string) ($typedef['uri'] ?? ''), 'Maybe.mog'), 'justValue typedef lands in Maybe.mog');

// Declaration

$p = $find('fromJust justValue');
$decl = svcDeclaration($svc, $basicUri, $p);
$assert($decl !== null, 'declaration resolves imported fromJust');
$assert(str_ends_with((string) ($decl['uri'] ?? ''), 'Maybe.mog'), 'declaration uri is Maybe.mog');

// Document symbols (hierarchical)

$syms = svcDocumentSymbols($svc, $basicUri);
$assert(count($syms) >= 30, 'document symbols cover top-level decls');
$names = array_column($syms, 'name');
$assert(in_array('Color', $names, true), 'symbols include Color');
$assert(in_array('Person', $names, true), 'symbols include Person');
$colorSym = null;
foreach ($syms as $s) {
    if ($s['name'] === 'Color') {
        $colorSym = $s;
        break;
    }
}
$assert($colorSym !== null && isset($colorSym['children']), 'Color has hierarchical children');
$childNames = array_column($colorSym['children'] ?? [], 'name');
$assert(in_array('Red', $childNames, true) && in_array('Blue', $childNames, true), 'Color children are constructors');
foreach ($syms as $s) {
    $assert(
        isset($s['range'], $s['selectionRange']) && (int) $s['selectionRange']['start']['line'] > 0,
        'symbol ' . ($s['name'] ?? '?') . ' has valid ranges',
    );
    break;
}

// Document highlight

$p = $find('useTarget = targetValue');
$hl = svcDocumentHighlight($svc, $basicUri, ['line' => $p['line'], 'character' => $p['character'] + 12]);
$assert(count($hl) >= 4, 'highlight covers all targetValue occurrences: ' . count($hl));

// Rename

$prep = svcPrepareRename($svc, $basicUri, $find('bump x = x + 1'));
$assert($prep !== null, 'prepareRename accepts bump');
$renamed = svcRename($svc, $basicUri, $find('bump x = x + 1'), 'bump2');
$assert($renamed !== null && isset($renamed['documentChanges']), 'rename returns documentChanges edit');
$assert(!isset($renamed['changes']), 'rename does not emit legacy changes key');
$renameEdits = [];
foreach ($renamed['documentChanges'] as $change) {
    if (($change['textDocument']['uri'] ?? '') === $basicUri) {
        $renameEdits = array_merge($renameEdits, $change['edits']);
    }
}
$renameLines = array_map(static fn (array $e): int => (int) $e['range']['start']['line'], $renameEdits);
$assert(in_array($find('bump x = x + 1')['line'], $renameLines, true), 'rename touches declaration');
$assert(in_array($find('incremented = bump 41')['line'], $renameLines, true), 'rename touches uses');

// Signature help

$p = $find('maybeValue = maybe 0 bump justValue');
$sig = svcSignatureHelp($svc, $basicUri, ['line' => $p['line'], 'character' => $p['character'] + 18]);
$assert($sig !== null && isset($sig['signatures'][0]['label']), 'signature help returns label: ' . json_encode($sig));
$assert(str_contains((string) $sig['signatures'][0]['label'], 'maybe'), 'signature label mentions maybe');

// Completion + resolve

$completion = svcCompletion($svc, $basicUri, ['line' => 0, 'character' => 0]);
$labels = array_column($completion['items'], 'label');
$assert(in_array('bump', $labels, true), 'completion includes local decl');
$assert(in_array('fromJust', $labels, true), 'completion includes imported decl');
$assert(in_array('data', $labels, true), 'completion includes keywords');
$withData = null;
foreach ($completion['items'] as $item) {
    if ($item['label'] === 'fromJust') {
        $withData = $item;
        break;
    }
}
$assert($withData !== null, 'fromJust completion item found');
$resolved = svcCompletionResolve($svc, $withData);
$assert(is_array($resolved), 'completion resolve returns item');

// Prefix-filtered completion (cursor inside the documentedValue reference)
$p = $find('documentedFunction n = n + documentedValue');
$completion2 = svcCompletion($svc, $basicUri, ['line' => $p['line'], 'character' => $p['character'] + 42]);
$labels2 = array_column($completion2['items'], 'label');
$assert(in_array('documentedValue', $labels2, true), 'completion at prefix finds documentedValue');
$firstRanked = null;
foreach ($completion2['items'] as $item) {
    if ($item['label'] === 'documentedValue') {
        $firstRanked = $item;
        break;
    }
}
$assert($firstRanked !== null && str_starts_with((string) ($firstRanked['sortText'] ?? ''), '00-'), 'exact prefix match ranks first');

// Workspace symbols

$ws = svcWorkspaceSymbol($svc, 'targetValue');
$assert(is_array($ws) && $ws !== [], 'workspace symbol finds targetValue');
$wsNames = array_column($ws, 'name');
$assert(in_array('targetValue', $wsNames, true), 'workspace symbol name match');

echo "lsp handlers tests passed\n";
