#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Unit tests for file/folder rename import rewriting (workspace/willRenameFiles)
 * and on-type formatting trigger variants (textDocument/onTypeFormatting).
 */

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcOnTypeFormatting;
use function Moggi\LSP\Workspace\svcWillDeleteFiles;
use function Moggi\LSP\Workspace\svcWillRenameFiles;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tmp = sys_get_temp_dir() . '/moggi-lsp-rename-' . bin2hex(random_bytes(4));
$assert(mkdir($tmp), 'create temp dir');

// willRenameFiles: single file rename rewrites importers + module decl

$svc = new AnalysisService();
$svc->setWorkspaceRoot($tmp);

$oldPath = $tmp . '/Olde.mog';
$newPath = $tmp . '/Newe.mog';
$moduleSrc = "module Olde where\n\nmarker :: Int\nmarker = 1\n";
$importerSrc = "module Importer where\n\nimport Olde\nimport Olde as O\nimport Olde qualified as OQ\n\nusesMarker = Olde.marker\nusesSub = Olde.Sub.thing\nnote = \"Olde.marker stays a string\"\n-- Olde.marker stays a comment\n";
$svc->openDocument(pathToUri($oldPath), $moduleSrc, 1);
$importerUri = pathToUri($tmp . '/Importer.mog');
$svc->openDocument($importerUri, $importerSrc, 1);

$changes = svcWillRenameFiles($svc, [
    ['oldUri' => pathToUri($oldPath), 'newUri' => pathToUri($newPath)],
]);

$importerEdits = $changes[$importerUri] ?? [];
$assert($importerEdits !== [], 'file rename edits the importer document');
$importerNew = $importerEdits[0]['newText'] ?? '';
$assert(str_contains($importerNew, 'import Newe'), 'plain import rewritten');
$assert(str_contains($importerNew, 'import Newe as O'), 'as-import rewritten');
$assert(str_contains($importerNew, 'import Newe qualified as OQ'), 'import-qualified-as rewritten');
$assert(str_contains($importerNew, 'usesMarker = Newe.marker'), 'qualified usage rewritten: ' . json_encode($importerNew));
$assert(str_contains($importerNew, 'Olde.Sub.thing'), 'submodule reference NOT rewritten for file rename: ' . json_encode($importerNew));
$assert(str_contains($importerNew, '"Olde.marker stays a string"'), 'string literals untouched');
$assert(str_contains($importerNew, '-- Olde.marker stays a comment'), 'line comments untouched');
$assert(!preg_match('/^import .*\bOlde\b/m', $importerNew), 'no import line references the old module');

// The renamed document itself gets its `module` declaration rewritten.
$moduleEdits = $changes[pathToUri($newPath)] ?? $changes[pathToUri($oldPath)] ?? [];
$assert($moduleEdits !== [], 'module declaration is rewritten in the renamed file');
$moduleNew = $moduleEdits[0]['newText'] ?? '';
$assert(str_contains($moduleNew, 'module Newe where'), 'module decl renamed: ' . json_encode($moduleNew));

// willRenameFiles: DIRECTORY rename rewrites the dotted module prefix
// (3.18 fileOperations.willRename — VS Code sends folder renames too)

$dirSvc = new AnalysisService();
$dirSvc->setWorkspaceRoot($tmp);

$dirOldUri = pathToUri($tmp . '/Ancient');
$dirNewUri = pathToUri($tmp . '/Modern');
$aUri = pathToUri($tmp . '/Ancient/Alpha.mog');
$bUri = pathToUri($tmp . '/Ancient/Beta.mog');
$userUri = pathToUri($tmp . '/Uses.mog');
$dirSvc->openDocument($aUri, "module Ancient.Alpha where\n\nav :: Int\nav = 1\n", 1);
$dirSvc->openDocument($bUri, "module Ancient.Beta where\n\nimport Ancient.Alpha\n\nbv = Ancient.Alpha.av\n", 1);
$dirSvc->openDocument($userUri, "module Uses where\n\nimport Ancient.Alpha (av)\nimport Ancient.Beta\n\nuv = av\n", 1);

$dirChanges = svcWillRenameFiles($dirSvc, [
    ['oldUri' => $dirOldUri, 'newUri' => $dirNewUri],
]);

$betaEdits = $dirChanges[$bUri] ?? [];
$assert($betaEdits !== [], 'directory rename rewrites sibling module: ' . json_encode(array_keys($dirChanges)));
$betaNew = $betaEdits[0]['newText'] ?? '';
$assert(str_contains($betaNew, 'module Modern.Beta where'), 'directory rename rewrites module prefix');
$assert(str_contains($betaNew, 'import Modern.Alpha'), 'directory rename rewrites sibling import');
$assert(!str_contains($betaNew, 'Ancient'), 'no stale prefix remains in sibling (incl. qualified uses)');

$userEdits = $dirChanges[$userUri] ?? [];
$assert($userEdits !== [], 'directory rename rewrites external importers');
$userNew = $userEdits[0]['newText'] ?? '';
$assert(str_contains($userNew, 'import Modern.Alpha (av)'), 'partial-import list preserved: ' . json_encode($userNew));
$assert(str_contains($userNew, 'import Modern.Beta'), 'external import of renamed dir rewritten');
$assert(!str_contains($userNew, 'Ancient'), 'no stale prefix remains in external importer');

// willRenameFiles: no-op cases return no edits

$sameSvc = new AnalysisService();
$sameSvc->setWorkspaceRoot($tmp);
$sameSvc->openDocument(pathToUri($oldPath), $moduleSrc, 1);

$assert(svcWillRenameFiles($sameSvc, []) === [], 'empty file list yields no edits');
// Missing URIs are skipped, not fatal.
$assert(svcWillRenameFiles($sameSvc, [['oldUri' => '', 'newUri' => '']]) === [], 'empty URIs are skipped');
// Renaming to the same module guess yields no edits.
$sameChanges = svcWillRenameFiles($sameSvc, [
    ['oldUri' => pathToUri($oldPath), 'newUri' => pathToUri($tmp . '/sub/Olde.mog')],
]);
// sub/Olde.mog maps to module `sub.Olde`? No — lowercase dir is skipped by the
// uppercase rule, so the module guess stays `Olde` → no edits.
$assert(($sameChanges[pathToUri($oldPath)] ?? []) === [], 'same-module rename is a no-op');

// willDeleteFiles: dangling import cleanup

$delSvc = new AnalysisService();
$delSvc->setWorkspaceRoot($tmp);
$goneUri = pathToUri($tmp . '/Goner.mog');
$user2Uri = pathToUri($tmp . '/User2.mog');
$delSvc->openDocument($goneUri, "module Goner where\n\ng :: Int\ng = 1\n", 1);
$delSvc->openDocument($user2Uri, "module User2 where\n\nimport Goner\nimport Goner qualified as G\nimport Data.Maybe\n\nu = G.g\n", 1);

$delChanges = svcWillDeleteFiles($delSvc, [
    ['uri' => $goneUri],
]);
$user2Edits = $delChanges[$user2Uri] ?? [];
$assert($user2Edits !== [], 'file delete rewrites documents importing it');
$user2New = $user2Edits[0]['newText'] ?? '';
$assert(!str_contains($user2New, 'import Goner'), 'both Goner imports removed: ' . json_encode($user2New));
$assert(str_contains($user2New, 'import Data.Maybe'), 'unrelated imports preserved');
$assert(str_contains($user2New, 'module User2 where'), 'module decl preserved');

// Deleting an unrelated file yields no edits.
$unrelated = svcWillDeleteFiles($delSvc, [
    ['uri' => pathToUri($tmp . '/Unrelated.mog')],
]);
$assert(($unrelated[$user2Uri] ?? []) === [], 'deleting an unimported file yields no edits');

// Directory delete: dotted-prefix imports are removed too.
$dirDelSvc = new AnalysisService();
$dirDelSvc->setWorkspaceRoot($tmp);
$oldDirUri = pathToUri($tmp . '/Archive');
$a2Uri = pathToUri($tmp . '/Archive/A2.mog');
$user3Uri = pathToUri($tmp . '/User3.mog');
$dirDelSvc->openDocument($a2Uri, "module Archive.A2 where\n\nx = 1\n", 1);
$dirDelSvc->openDocument($user3Uri, "module User3 where\n\nimport Archive.A2\nimport Data.List\n\ny = 2\n", 1);
$dirDelChanges = svcWillDeleteFiles($dirDelSvc, [
    ['uri' => $oldDirUri],
]);
$user3Edits = $dirDelChanges[$user3Uri] ?? [];
$assert($user3Edits !== [], 'directory delete rewrites importers');
$user3New = $user3Edits[0]['newText'] ?? '';
$assert(!str_contains($user3New, 'import Archive.A2'), 'dotted-prefix import removed on dir delete');
$assert(str_contains($user3New, 'import Data.List'), 'unrelated imports preserved on dir delete');

// onTypeFormatting: trigger variants

$fmtSvc = new AnalysisService();
$fmtSvc->setWorkspaceRoot($tmp);
$messyUri = pathToUri($tmp . '/Messy.mog');
$messySrc = "module Messy where\n\ng :: Int\ng=1\n\nh x = x\n";
$fmtSvc->openDocument($messyUri, $messySrc, 1);
$fmtSvc->ensureAnalyzed($messyUri);
$anywhere = ['line' => 3, 'character' => 4];

// Advertised trigger characters produce a whole-document TextEdit.
foreach (['{', '}', ';'] as $trigger) {
    $edits = svcOnTypeFormatting($fmtSvc, $messyUri, $anywhere, $trigger, ['tabSize' => 4, 'insertSpaces' => true]);
    $assert(is_array($edits), "trigger '{$trigger}' returns an array");
    $assert($edits !== [], "trigger '{$trigger}' formats the document");
    if ($edits !== []) {
        $assert(str_contains($edits[0]['newText'], 'g = 1'), "trigger '{$trigger}' edit fixes spacing: " . json_encode($edits[0]['newText']));
    }
}

// Unadvertised trigger characters must be rejected (empty result, no reformat).
foreach (['=', "\n", '(', 'x'] as $unadvertised) {
    $edits = svcOnTypeFormatting($fmtSvc, $messyUri, $anywhere, $unadvertised, ['tabSize' => 4]);
    $assert($edits === [], "unadvertised trigger '{$unadvertised}' yields no edits: " . json_encode($edits));
}

// Already-formatted document → no edits (server must not loop reformatting).
$tidyUri = pathToUri($tmp . '/Tidy.mog');
$tidySrc = "module Tidy where\n\ng :: Int\ng = 1\n";
$fmtSvc->openDocument($tidyUri, $tidySrc, 1);
$fmtSvc->ensureAnalyzed($tidyUri);
$assert(svcOnTypeFormatting($fmtSvc, $tidyUri, ['line' => 3, 'character' => 0], '{', []) === [], 'already-formatted document yields no edits');

// Unknown document → no edits, no crash.
$assert(svcOnTypeFormatting($fmtSvc, pathToUri($tmp . '/Missing.mog'), $anywhere, '{', []) === [], 'unknown document yields no edits');

@unlink($oldPath);
@unlink($messyPath ?? $tmp . '/Messy.mog');
@unlink($tidyUri ? $tmp . '/Tidy.mog' : '');
@unlink($tmp . '/Importer.mog');
@unlink($tmp . '/Uses.mog');
@rmdir($tmp);

echo "lsp rename + on-type formatting tests passed\n";
