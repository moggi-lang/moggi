<?php declare(strict_types=1);

namespace Moggi\LSP;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Analysis\changeDocument;
use function Moggi\LSP\Analysis\closeDocument;
use function Moggi\LSP\Analysis\openDocument;
use function Moggi\LSP\Analysis\quickDiagnostics;
use function Moggi\LSP\Analysis\saveDocument;
use function Moggi\LSP\Protocol\sendNotification;
use function Moggi\LSP\Protocol\splitLines;
use function Moggi\LSP\Protocol\utf16ByteOffset;

function applyFullContentChanges(string $previous, array $changes): string
{
    if ($changes === []) {
        return $previous;
    }
    $last = $changes[array_key_last($changes)];
    if (!\is_array($last) || !array_key_exists('text', $last)) {
        return $previous;
    }

    return (string) $last['text'];
}

/**
 * Apply LSP contentChanges (full or incremental).
 *
 * @param list<array{text?: string, range?: array{start: array{line:int,character:int}, end: array{line:int,character:int}}}> $changes
 */
function applyContentChanges(string $content, array $changes): string
{
    // 3.18: '\n', '\r\n' and '\r' are all valid EOL sequences. Remember the
    // document's dominant EOL so ranged edits don't rewrite the whole file's
    // line endings when splicing back together.
    $crlf = substr_count($content, "\r\n");
    $lf = substr_count($content, "\n") - $crlf;
    $cr = substr_count($content, "\r") - $crlf;
    $eol = ($crlf >= $lf && $crlf >= $cr) ? "\r\n" : ($cr > $lf ? "\r" : "\n");

    foreach ($changes as $change) {
        if (!\is_array($change) || !array_key_exists('text', $change)) {
            continue;
        }
        $text = (string) $change['text'];
        if (!isset($change['range']) || !\is_array($change['range'])) {
            $content = $text;
            continue;
        }
        $start = $change['range']['start'] ?? ['line' => 0, 'character' => 0];
        $end = $change['range']['end'] ?? $start;
        $lines = splitLines($content);
        $sLine = (int) ($start['line'] ?? 0);
        $eLine = (int) ($end['line'] ?? 0);
        $sChar = (int) ($start['character'] ?? 0);
        $eChar = (int) ($end['character'] ?? 0);
        while (count($lines) <= max($sLine, $eLine)) {
            $lines[] = '';
        }
        $before = substr($lines[$sLine] ?? '', 0, utf16ByteOffset($lines[$sLine] ?? '', $sChar));
        $after = substr($lines[$eLine] ?? '', utf16ByteOffset($lines[$eLine] ?? '', $eChar));
        $insertLines = splitLines($text);
        if (count($insertLines) === 1) {
            $newMid = [$before . $insertLines[0] . $after];
        } else {
            $insertLines[0] = $before . $insertLines[0];
            $insertLines[count($insertLines) - 1] .= $after;
            $newMid = $insertLines;
        }
        array_splice($lines, $sLine, $eLine - $sLine + 1, $newMid);
        $content = implode($eol, $lines);
    }
    return $content;
}

/**
 * Handle `textDocument/didOpen`: insert into the VFS, schedule the debounced
 * typecheck and immediately publish quick parse diagnostics.
 *
 * @return array{uri: string, version: int} the synced document identity.
 */
function handleDidOpen(AnalysisService $svc, array $params, ?callable $driver = null): array
{
    $doc = $params['textDocument'] ?? [];
    $uri = (string) ($doc['uri'] ?? '');
    $version = (int) ($doc['version'] ?? 1);
    if ($uri !== '') {
        $svc->openDocument(
            $uri,
            (string) ($doc['text'] ?? ''),
            $version,
            (string) ($doc['languageId'] ?? 'moggi'),
        );
        sendNotification('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'version' => $version,
            'diagnostics' => $svc->quickDiagnostics($uri),
        ]);
    }
    return ['uri' => $uri, 'version' => $version];
}

/**
 * Handle `textDocument/didChange`: VFS + mark dirty + schedule only — no
 * synchronous typecheck — then publish quick parse diagnostics.
 *
 * @return array{uri: string, version: int}
 */
function handleDidChange(AnalysisService $svc, array $params, ?callable $driver = null): array
{
    $doc = $params['textDocument'] ?? [];
    $uri = (string) ($doc['uri'] ?? '');
    $version = (int) ($doc['version'] ?? 1);
    if ($uri !== '') {
        $svc->changeDocument($uri, $params['contentChanges'] ?? [], $version);
        sendNotification('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'version' => $version,
            'diagnostics' => $svc->quickDiagnostics($uri),
        ]);
    }
    return ['uri' => $uri, 'version' => $version];
}

/**
 * Handle `textDocument/didSave`: record the on-disk sync point.
 */
function handleDidSave(AnalysisService $svc, array $params, ?callable $driver = null): void
{
    $uri = (string) ($params['textDocument']['uri'] ?? '');
    if ($uri !== '') {
        $svc->saveDocument($uri);
    }
}

/**
 * Handle `textDocument/didClose`: drop the VFS overlay and clear diagnostics.
 */
function handleDidClose(AnalysisService $svc, array $params): void
{
    $uri = (string) ($params['textDocument']['uri'] ?? '');
    if ($uri !== '') {
        $svc->closeDocument($uri);
        sendNotification('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'version' => null,
            'diagnostics' => [],
        ]);
    }
}
