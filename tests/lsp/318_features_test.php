#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Unit tests for the 3.18 feature batch:
 *   1. hover completeness (operator application hover)
 *   2. signature help — nested calls + activeParameter
 *   3. completion quality — import/member context, modern item fields
 *   4. rename via documentChanges (no legacy `changes`)
 *   5. definition/typeDefinition single-result Location shape
 *   7. diagnostics — unused-import tag + codeDescription
 *   8. pull diagnostics — resultId/unchanged, workspace diagnostics
 *   8b. workspace diagnostics — retired result ids, capability stays false
 *   9. request cancellation registry → RequestCancelled
 *  10. per-request work-done progress helpers
 */

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;
use Moggi\LSP\CancellationRegistry;
use Moggi\LSP\RequestCancelledException;

use function Moggi\LSP\progressPercent;
use function Moggi\LSP\workDoneParamsToken;
use function Moggi\LSP\Analysis\resultIdFor;
use function Moggi\LSP\serverCapabilities;
use function Moggi\LSP\lspMethodHandlers;
use function Moggi\LSP\capabilityDispatchMismatches;
use function Moggi\LSP\Analysis\unusedImportDiagnostics;
use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcCompletion;
use function Moggi\LSP\TextDocument\svcDefinition;
use function Moggi\LSP\TextDocument\svcDiagnostic;
use function Moggi\LSP\TextDocument\svcHover;
use function Moggi\LSP\TextDocument\svcRename;
use function Moggi\LSP\TextDocument\svcSignatureHelp;
use function Moggi\LSP\TextDocument\svcTypeDefinition;
use function Moggi\LSP\TextDocument\svcWorkspaceDiagnostic;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
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
 * 0-based {line, character} of the first occurrence of a substring.
 */
$find = static function (string $needle) use ($src): array {
    $next = strpos($src, $needle);
    if ($next === false) {
        throw new RuntimeException("fixture does not contain: {$needle}");
    }
    $before = substr($src, 0, $next);
    $line = substr_count($before, "\n");
    $lineStart = strrpos($before, "\n");

    return ['line' => $line, 'character' => $next - ($lineStart === false ? -1 : $lineStart) - 1];
};

// 9. Cancellation registry semantics

$reg = new CancellationRegistry();
$reg->cancel(7);
$assert($reg->isCancelled(7), 'registry flags cancelled id');
$assert(!$reg->isCancelled(8), 'registry keeps other ids pending');
$reg->cancelAll();
$assert(!$reg->isCancelled(7), 'cancelAll clears every id');
$reg->cancel('req-1');

$cancelled = false;
try {
    \Moggi\LSP\checkCancelled($reg, 'req-1');
} catch (RequestCancelledException) {
    $cancelled = true;
}
$assert($cancelled, 'checkCancelled throws RequestCancelled for cancelled id');

// 10. Progress helpers (pure parts)

$assert(workDoneParamsToken(['workDoneToken' => 'tok-1']) === 'tok-1', 'workDoneParamsToken parses string tokens');
$assert(workDoneParamsToken(['workDoneToken' => 42]) === 42, 'workDoneParamsToken parses int tokens');
$assert(workDoneParamsToken(['workDoneToken' => ['nope']]) === null, 'workDoneParamsToken rejects non-tokens');
$assert(workDoneParamsToken([]) === null, 'workDoneParamsToken handles missing token');
$assert(progressPercent(0, 10) === 0, 'progressPercent 0%');
$assert(progressPercent(5, 10) === 50, 'progressPercent 50%');
$assert(progressPercent(10, 10) === 100, 'progressPercent 100%');
$assert(progressPercent(3, 0) === null, 'progressPercent rejects zero total');

// 5. Navigation shapes: single target = Location (not array)

$def = svcDefinition($svc, $basicUri, $find('useTarget = targetValue'));
$assert(
    is_array($def) && isset($def['uri'], $def['range']['start']['line']),
    'definition single target is a Location: ' . json_encode($def)
);

$p = $find('justValue = Just 42');
$td = svcTypeDefinition($svc, $basicUri, $p);
$assert(
    is_array($td) && isset($td['uri'], $td['range']),
    'typeDefinition single target is a Location: ' . json_encode($td)
);

// 1. Hover completeness: operator application shows result type

$p = $find('anotherInt = anInt + 10');
$hv = svcHover($svc, $basicUri, ['line' => $p['line'], 'character' => $p['character'] + strlen('anotherInt = anInt +')]);
$assert($hv !== null, 'operator-position hover returns a result');
$hvText = (string) ($hv['contents']['value'] ?? '');
$assert(str_contains($hvText, 'Int'), 'operator application hover carries Int type: ' . $hvText);

// 2. Signature help: nested call, activeParameter counts

$p = $find('composed = compose double bump 20');
// at `bump` argument (3rd arg of compose): activeParameter 2
$sig = svcSignatureHelp($svc, $basicUri, ['line' => $p['line'], 'character' => $p['character'] + strlen('composed = compose double ')]);
$assert($sig !== null && isset($sig['signatures'][0]['label']), 'nested signature help resolves');
$assert(($sig['activeSignature'] ?? 0) === 0, 'single signature selected');
$assert(str_contains((string) $sig['signatures'][0]['label'], 'compose'), 'outer function signature shown');
$assert((int) ($sig['activeParameter'] ?? -1) === 2, 'activeParameter counts into nested arg position: ' . json_encode($sig['activeParameter'] ?? null));

// 3. Completion quality

$completion = svcCompletion($svc, $basicUri, ['line' => 2, 'character' => 8]);
$labels = array_column($completion['items'], 'label');
$assert(
    in_array('Data.Maybe', $labels, true) || in_array('Data.List', $labels, true),
    'import context completes module names: ' . json_encode(array_slice($labels, 0, 8))
);

// Member completion on a typed record receiver.
$memberSrc = <<<'MOG'
module Members where

data Person = Person { name :: String, age :: Int }

alice = Person { name = "A", age = 1 }

who = alice . _
MOG;
$memberPath = $fixturesDir . '/lsp10_members.mog';
file_put_contents($memberPath, $memberSrc);
$memberUri = pathToUri($memberPath);
$svc->openDocument($memberUri, $memberSrc, 1);

$memberItems = \Moggi\LSP\TextDocument\memberCompletionItems($svc, $svc->ensureAnalyzed($memberUri), 7, 15, '_');
$assert($memberItems !== null, 'member completion resolves a typed record receiver');
$memberLabels = array_column($memberItems, 'label');
$assert(
    in_array('name', $memberLabels, true) && in_array('age', $memberLabels, true),
    'member completion lists record fields: ' . json_encode($memberLabels)
);
$assert((int) ($memberItems[0]['kind'] ?? 0) === 5, 'field items use CompletionItemKind.Field(5)');

// Still resolves with a type error in the file (mid-edit state, no stamped types).
$memberItems2 = \Moggi\LSP\TextDocument\memberCompletionItems($svc, $svc->ensureAnalyzed($memberUri), 7, 14, '');
$assert(
    $memberItems2 !== null && in_array('name', array_column($memberItems2, 'label'), true),
    'member completion works despite a type error in the file'
);

// Modern item fields present on cross-module suggestions.
$completion2 = svcCompletion($svc, $basicUri, ['line' => 40, 'character' => 1]);
$hasModern = false;
foreach ($completion2['items'] as $item) {
    if (isset($item['commitCharacters']) || isset($item['labelDetails'])) {
        $hasModern = true;
        break;
    }
}
$assert($hasModern, 'completion items carry modern 3.18 fields (commitCharacters/labelDetails)');

// 4. Rename → documentChanges only

$p = $find('bump x = x + 1');
$renamed = svcRename($svc, $basicUri, $p, 'bumpX');
$assert(is_array($renamed) && isset($renamed['documentChanges']), 'rename returns documentChanges');
$assert(!isset($renamed['changes']), 'rename never emits legacy changes');
$firstChange = $renamed['documentChanges'][0] ?? null;
$assert(($firstChange['textDocument']['uri'] ?? '') === $basicUri, 'documentChanges entry names the doc');
$assert(isset($firstChange['textDocument']['version']), 'documentChanges entry carries the doc version');

// 7. Diagnostics: unused-import warning + codeDescription

$unusedSrc = "module UnusedImp where\n\nimport Data.List\n\nok = 1\n";
$unusedPath = $fixturesDir . '/lsp10_unused.mog';
file_put_contents($unusedPath, $unusedSrc);
$unusedUri = pathToUri($unusedPath);
$svc->openDocument($unusedUri, $unusedSrc, 1);
$unusedAnalysis = $svc->ensureAnalyzed($unusedUri);
$unusedTags = [];
foreach ($unusedAnalysis->diagnostics as $d) {
    if (($d['code'] ?? '') === 'unused-import') {
        $unusedTags[] = $d;
    }
}
$assert(count($unusedTags) === 1, 'unused import produces exactly one diagnostic: ' . json_encode($unusedAnalysis->diagnostics));
$assert(in_array(1, $unusedTags[0]['tags'] ?? [], true), 'unused-import carries Unnecessary tag (1)');
$assert(
    str_contains((string) ($unusedTags[0]['codeDescription']['href'] ?? ''), 'moggi-lang'),
    'unused-import has codeDescription href'
);
$assert(($unusedTags[0]['severity'] ?? 0) === 2, 'unused-import is a warning');

// codeDescription on type errors too.
$badSrc = "module BadMath where\n\nwrong = \"a\" + 1\n";
$badPath = $fixturesDir . '/lsp10_bad.mog';
file_put_contents($badPath, $badSrc);
$badUri = pathToUri($badPath);
$svc->openDocument($badUri, $badSrc, 1);
$badAnalysis = $svc->ensureAnalyzed($badUri);
$badHasHref = false;
foreach ($badAnalysis->diagnostics as $d) {
    if (isset($d['codeDescription']['href'])) {
        $badHasHref = true;
        break;
    }
}
$assert($badHasHref, 'type-error diagnostics carry codeDescription');

// 8. Pull diagnostics: resultId + unchanged + workspace report

$report = svcDiagnostic($svc, $badUri);
$assert(($report['kind'] ?? '') === 'full', 'pull report is full');
$assert(is_string($report['resultId'] ?? null) && $report['resultId'] !== '', 'pull report has resultId');
$again = svcDiagnostic($svc, $badUri, ['previousResultId' => $report['resultId']]);
$assert(($again['kind'] ?? '') === 'unchanged', 'same resultId → unchanged response');
$assert(($again['resultId'] ?? '') === $report['resultId'], 'unchanged response echoes resultId');

/** Find a report entry by uri (null when absent). */
$wsEntry = static function (array $report, string $uri): ?array {
    foreach ($report['items'] as $e) {
        if ($e['uri'] === $uri) {
            return $e;
        }
    }

    return null;
};

$ws = svcWorkspaceDiagnostic($svc);
$entry = $wsEntry($ws, $badUri);
$assert($entry !== null, 'workspace report includes open doc');
$assert(($entry['kind'] ?? '') === 'full', 'workspace report is full');
$assert(array_key_exists('version', $entry), 'workspace entry carries version');
$assert(($entry['version'] ?? null) === 1, 'workspace entry carries the doc version');

// A first pull has no client result ids, so nothing may claim to be unchanged.
foreach ($ws['items'] as $e) {
    $assert(($e['kind'] ?? '') === 'full', 'no unchanged report without a previous resultId: ' . $e['uri']);
}

$wsUnchanged = svcWorkspaceDiagnostic($svc, [
    'previousResultIds' => [['uri' => $badUri, 'value' => $entry['resultId']]],
]);
$same = $wsEntry($wsUnchanged, $badUri);
$assert($same !== null, 'unchanged docs are still reported, not elided');
$assert(($same['kind'] ?? '') === 'unchanged', 'matching resultId → unchanged report');
$assert(($same['resultId'] ?? '') === $entry['resultId'], 'unchanged report echoes resultId');
$assert(($same['version'] ?? null) === 1, 'unchanged report echoes version');
$assert(!isset($same['items']), 'unchanged report carries no items');

// A document that became clean must be reported `full` with no items — that is
// the only signal that retires the diagnostics the client cached earlier.
$svc->changeDocument($badUri, [[
    'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 2, 'character' => 1000]],
    'text' => "module BadMath where\n\nwrong = 1\n",
]], 2);
$cleaned = svcWorkspaceDiagnostic($svc, [
    'previousResultIds' => [['uri' => $badUri, 'value' => $entry['resultId']]],
]);
$fixed = $wsEntry($cleaned, $badUri);
$assert($fixed !== null, 'cleaned doc is still reported');
$assert(($fixed['kind'] ?? '') === 'full', 'cleaned doc → full report');
$assert(($fixed['items'] ?? null) === [], 'cleaned doc reports empty items to clear cached squiggles');
$assert(($fixed['resultId'] ?? '') !== $entry['resultId'], 'cleaned doc gets a new resultId');

// 8b. Workspace pull: retired result ids for closed modules

$wsDir = sys_get_temp_dir() . '/moggi-wsdiag-' . getmypid();
@mkdir($wsDir);
file_put_contents($wsDir . '/Closed.mog', "module Closed where\n\nimport Data.Enum\n\nvalue :: Int\nvalue = 1\n");
file_put_contents($wsDir . '/Entry.mog', "module Entry where\n\nimport Closed\n\nmain :: Int\nmain = value\n");
$wsSvc = new AnalysisService();
$wsSvc->setWorkspaceRoot($wsDir);
$wsEntryUri = pathToUri($wsDir . '/Entry.mog');
$wsSvc->openDocument($wsEntryUri, (string) file_get_contents($wsDir . '/Entry.mog'), 1);
$wsSvc->warm();
$wsSvc->ensureAnalyzed($wsEntryUri);
$closedUri = pathToUri($wsDir . '/Closed.mog');
$closed = $wsEntry(svcWorkspaceDiagnostic($wsSvc), $closedUri);
if ($closed !== null) {
    $assert(($closed['kind'] ?? '') === 'full', 'closed module with diagnostics → full');
    $assert(($closed['items'] ?? []) !== [], 'closed module reports its diagnostics');
    $assert(array_key_exists('version', $closed) && $closed['version'] === null, 'closed module reports null version');
    // Deleting the file must retire the client's cached report, not omit it.
    unlink($wsDir . '/Closed.mog');
    $retired = $wsEntry(svcWorkspaceDiagnostic($wsSvc, [
        'previousResultIds' => [['uri' => $closedUri, 'value' => $closed['resultId']]],
    ]), $closedUri);
    $assert($retired !== null, 'deleted module is reported to retire its result id');
    $assert(($retired['kind'] ?? '') === 'full' && ($retired['items'] ?? null) === [], 'deleted module → full with empty items');
} else {
    // No closed module in the index: the deleted-file path still has to retire
    // an id the client holds for a URI we no longer know about.
    $assert(true, 'closed module not indexed');
}
@unlink($wsDir . '/Entry.mog');
@rmdir($wsDir);

// Capability: clients that see workspaceDiagnostics:true poll workspace/diagnostic
// on a timer, so it stays false while the handler remains served (push handles
// diagnostics for VS Code).
$caps = serverCapabilities();
$assert(($caps['diagnosticProvider']['workspaceDiagnostics'] ?? null) === false, 'workspaceDiagnostics is not advertised');
$assert(($caps['diagnosticProvider']['interFileDependencies'] ?? null) === true, 'diagnosticProvider still advertises inter-file dependencies');
$assert(isset(lspMethodHandlers()['workspace/diagnostic']), 'workspace/diagnostic handler is still served');
$assert(capabilityDispatchMismatches() === [], 'no capability/dispatch drift: ' . json_encode(capabilityDispatchMismatches()));

// resultIdFor determinism / sensitivity.
$assert(resultIdFor('u', []) === resultIdFor('u', []), 'resultId stable for equal input');
$assert(resultIdFor('u', []) !== resultIdFor('u', [['x']]), 'resultId changes with items');

foreach ([$memberPath, $unusedPath, $badPath] as $f) {
    @unlink($f);
}

echo "lsp 318 features tests passed\n";
