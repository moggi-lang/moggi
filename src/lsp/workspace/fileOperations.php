<?php declare(strict_types=1);

namespace Moggi\LSP\Workspace;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Analysis\all;
use function Moggi\LSP\Analysis\content;
use function Moggi\LSP\Analysis\has;
use function Moggi\LSP\Protocol\splitLines;
use function Moggi\LSP\Protocol\uriToPath;

function svcWillDeleteFiles(AnalysisService $svc, array $files): array
{
    $changes = [];
    foreach ($files as $file) {
        $uri = $file['uri'] ?? '';
        if ($uri === '') {
            continue;
        }
        $mod = uriToModuleGuess($uri, $svc->workspaceRoot);
        if ($mod === null) {
            continue;
        }
        $wholePrefix = !str_ends_with(strtolower(uriToPath($uri)), '.mog');
        foreach ($svc->vfs->all() as $docUri => $doc) {
            if ($docUri === $uri) {
                continue;
            }
            $src = $doc['content'];
            $newSrc = removeImportsOf($src, $mod, $wholePrefix);
            if ($newSrc === $src) {
                continue;
            }
            $changes[$docUri][] = [
                'range' => [
                    'start' => ['line' => 0, 'character' => 0],
                    'end' => ['line' => 0, 'character' => 0],
                ],
                'newText' => '',
            ];
            // Store computed text for the test/consumer; the server wraps the
            // uri→edits map as {changes: …} exactly like willRenameFiles.
            $changes[$docUri][0]['newText'] = $newSrc;
            $changes[$docUri][0]['range'] = wholeDocumentRange($newSrc);
        }
    }
    return $changes;
}

/**
 * Drop `import OldMod …` lines (and, for directory deletes, imports of every
 * `OldMod.*` submodule) from the source. Non-import lines are untouched.
 */

/**
 * Drop `import OldMod …` lines (and, for directory deletes, imports of every
 * `OldMod.*` submodule) from the source. Non-import lines are untouched.
 */
function removeImportsOf(string $src, string $mod, bool $wholePrefix): string
{
    $q = preg_quote($mod, '/');
    $prefix = $wholePrefix ? '(?:\.' . $q . ')?' : '';
    $pattern = '/^import(\s+qualified)?\s+' . $q . $prefix . '(\s+qualified)?(\s+as\s+[A-Za-z_][A-Za-z0-9_\']*)?(\s*\([^)]*\))?[^\n\r]*$(?:\r?\n)?/m';
    $out = preg_replace($pattern, '', $src);
    return $out ?? $src;
}

/** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */

/** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
function wholeDocumentRange(string $src): array
{
    $lines = splitLines($src);
    return [
        'start' => ['line' => 0, 'character' => 0],
        'end' => [
            'line' => max(0, count($lines) - 1),
            'character' => strlen($lines[count($lines) - 1] ?? ''),
        ],
    ];
}

function svcWillRenameFiles(AnalysisService $svc, array $files): array
{
    $changes = [];
    foreach ($files as $file) {
        $oldUri = $file['oldUri'] ?? '';
        $newUri = $file['newUri'] ?? '';
        if ($oldUri === '' || $newUri === '') {
            continue;
        }
        $oldMod = uriToModuleGuess($oldUri, $svc->workspaceRoot);
        $newMod = uriToModuleGuess($newUri, $svc->workspaceRoot);
        if ($oldMod === null || $newMod === null || $oldMod === $newMod) {
            continue;
        }
        // Directory rename (URI without a trailing .mog): rewrite the whole
        // dotted prefix (Ancient.Alpha → Modern.Alpha). File rename: rewrite
        // one trailing segment only, so submodule references survive.
        $wholePrefix = !str_ends_with(strtolower(uriToPath($oldUri)), '.mog');
        foreach ($svc->vfs->all() as $uri => $doc) {
            $src = $doc['content'];
            $newSrc = rewriteImportModule($src, $oldMod, $newMod);
            $newSrc = rewriteQualifiedReferences($newSrc, $oldMod, $newMod, $wholePrefix);
            if ($newSrc !== $src) {
                $lines = splitLines($src);
                $changes[$uri][] = [
                    'range' => [
                        'start' => ['line' => 0, 'character' => 0],
                        'end' => [
                            'line' => max(0, count($lines) - 1),
                            'character' => strlen($lines[count($lines) - 1] ?? ''),
                        ],
                    ],
                    'newText' => $newSrc,
                ];
            }
        }
        $content = $svc->vfs->content($newUri) ?? $svc->vfs->content($oldUri);
        if ($content !== null && preg_match('/^module\s+' . preg_quote($oldMod, '/') . '\b/m', $content)) {
            $newContent = preg_replace(
                '/^module\s+' . preg_quote($oldMod, '/') . '\b/m',
                'module ' . $newMod,
                $content,
            );
            if ($newContent !== null && $newContent !== $content) {
                $lines = splitLines($content);
                $target = $svc->vfs->has($newUri) ? $newUri : $oldUri;
                $changes[$target][] = [
                    'range' => [
                        'start' => ['line' => 0, 'character' => 0],
                        'end' => [
                            'line' => max(0, count($lines) - 1),
                            'character' => strlen($lines[count($lines) - 1] ?? ''),
                        ],
                    ],
                    'newText' => $newContent,
                ];
            }
        }
    }
    return $changes;
}

function rewriteImportModule(string $src, string $oldMod, string $newMod): string
{
    $q = preg_quote($oldMod, '/');
    $out = preg_replace(
        '/\bimport(\s+qualified)?\s+' . $q . '\b/',
        'import$1 ' . $newMod,
        $src,
    );
    if ($out === null) {
        return $src;
    }
    // `import M qualified` and `import M as A` / `import M qualified as A`
    $out2 = preg_replace(
        '/\bimport\s+' . $q . '(\s+qualified)?(\s+as\s+[A-Za-z_][A-Za-z0-9_\']*)?/',
        'import ' . $newMod . '$1$2',
        $out,
    );
    return $out2 ?? $out;
}

/**
 * Rewrite qualified references `OldMod.name` → `NewMod.name`.
 *
 * Guard rails:
 * - double-quoted string literals are left untouched (a URL or message
 *   containing the dotted module name is not a code reference),
 * - `--` line comments are left untouched (only import lines and qualified
 *   references affect compilation),
 * - the module name must START a dotted chain (nothing word-like, dotted, or
 *   primed before it), so `Users.OldMod.x` never matches when renaming
 *   `OldMod`.
 *
 * When `$wholePrefix` is false (single file rename), only a single trailing
 * segment is rewritten (`OldMod.f`, `OldMod.Just`), so a submodule reference
 * like `OldMod.Other.f` is left alone. When true (directory rename), the
 * whole dotted prefix is rewritten (`OldDir.X.f` → `NewDir.X.f`), which also
 * fixes `module OldDir.X where` headers of the moved files.
 */

/**
 * Rewrite qualified references `OldMod.name` → `NewMod.name`.
 *
 * Guard rails:
 * - double-quoted string literals are left untouched (a URL or message
 *   containing the dotted module name is not a code reference),
 * - `--` line comments are left untouched (only import lines and qualified
 *   references affect compilation),
 * - the module name must START a dotted chain (nothing word-like, dotted, or
 *   primed before it), so `Users.OldMod.x` never matches when renaming
 *   `OldMod`.
 *
 * When `$wholePrefix` is false (single file rename), only a single trailing
 * segment is rewritten (`OldMod.f`, `OldMod.Just`), so a submodule reference
 * like `OldMod.Other.f` is left alone. When true (directory rename), the
 * whole dotted prefix is rewritten (`OldDir.X.f` → `NewDir.X.f`), which also
 * fixes `module OldDir.X where` headers of the moved files.
 */
function rewriteQualifiedReferences(string $src, string $oldMod, string $newMod, bool $wholePrefix): string
{
    $q = preg_quote($oldMod, '/');
    $pattern = $wholePrefix
        ? '/(?<![\\w\'.])\\b' . $q . '\\./'
        : '/(?<![\\w\'.])\\b' . $q . '\\.([A-Za-z_][A-Za-z0-9_\']*)(?![.\\w\'])/';
    $replacement = $wholePrefix ? $newMod . '.' : $newMod . '.$1';

    // Split into code / double-quoted-literal / line-comment segments so
    // literal contents and `-- comments` are never touched. Block comments
    // are treated as code (they may legitimately contain doc examples).
    $parts = preg_split(
        '/("(?:[^"\\\\\n]|\\\\.)*"|--[^\n\r]*)/',
        $src,
        -1,
        PREG_SPLIT_DELIM_CAPTURE,
    );
    if ($parts === false || $parts === [$src]) {
        return (string) preg_replace($pattern, $replacement, $src);
    }
    $out = '';
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            // Captured literal or line comment: keep verbatim.
            $out .= $part;
            continue;
        }
        $out .= (string) preg_replace($pattern, $replacement, $part);
    }
    return $out;
}

function uriToModuleGuess(string $uri, ?string $workspaceRoot = null): ?string
{
    $path = uriToPath($uri);
    $path = str_replace('\\', '/', $path);
    $isFile = str_ends_with(strtolower($path), '.mog');
    if (!$isFile && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }

    // Strip a trailing .mog for files; directories keep their full relative
    // path so a folder rename maps to a module-name prefix (LibA → LibB).
    $stripExtension = static function (string $rel) use ($isFile): string {
        return $isFile ? (preg_replace('/\.mog$/i', '', $rel) ?? $rel) : $rel;
    };

    // Prefer path relative to lib/ or workspace for nested modules.
    foreach (['/lib/', '/tests/', '/examples/'] as $marker) {
        $pos = strpos($path, $marker);
        if ($pos !== false) {
            $rel = substr($path, $pos + strlen($marker));
            $rel = $stripExtension($rel);
            $parts = array_values(array_filter(explode('/', $rel), static fn ($p) => $p !== ''));
            if ($parts !== [] && preg_match('/^[A-Z]/', $parts[0])) {
                return implode('.', $parts);
            }
        }
    }
    if ($workspaceRoot !== null) {
        $root = rtrim(str_replace('\\', '/', $workspaceRoot), '/');
        if (str_starts_with($path, $root . '/')) {
            $rel = substr($path, strlen($root) + 1);
            $rel = $stripExtension($rel);
            $parts = array_values(array_filter(explode('/', $rel), static fn ($p) => $p !== ''));
            if ($parts !== [] && preg_match('/^[A-Z]/', $parts[0] ?? '')) {
                return implode('.', $parts);
            }
        }
    }
    if (!$isFile) {
        // A directory outside any known root has no reliable module prefix.
        return null;
    }
    $base = basename($path, '.mog');
    if ($base !== '' && preg_match('/^[A-Z]/', $base)) {
        return $base;
    }
    return null;
}
