<?php declare(strict_types=1);

namespace Moggi\LSP\Analysis;

use Moggi\Docs\DocIndex;
use Moggi\LSP\Index\DeclInfo;
use Moggi\LSP\Index\ModuleIndex;
use Moggi\LSP\Index\OccurrenceIndex;
use Moggi\Modules\PreparedProject;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Backend\setCompileBackend;
use function Moggi\Docs\dumpTypeForDocs;
use function Moggi\Docs\loadOrBuildIndex;
use function Moggi\LSP\Index\buildDeclsFromProgram;
use function Moggi\LSP\Index\buildOccurrenceIndex;
use function Moggi\LSP\Index\clearUri;
use function Moggi\LSP\Index\indexTypeRelations;
use function Moggi\LSP\Index\merge;
use function Moggi\LSP\Index\put;
use function Moggi\LSP\Protocol\nodeToLspRange;
use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\Protocol\uriToPath;
use function Moggi\LSP\applyContentChanges;
use function Moggi\Modules\bundledStdlibLibPath;
use function Moggi\Modules\moduleFileClosureCached;
use function Moggi\Modules\prepareProjectCached;
use function Moggi\Modules\projectSourceClosure;
use function Moggi\Modules\setStdlibLibPath;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

final class VirtualFS
{
    /** @var array<string, array{content: string, version: int, languageId: string}> */
    private array $docs = [];

    private string $sessionRoot;

    public function __construct(?string $sessionRoot = null)
    {
        $this->sessionRoot = $sessionRoot ?? (sys_get_temp_dir() . '/moggi-lsp-vfs/' . bin2hex(random_bytes(8)));
        if (!is_dir($this->sessionRoot) && !mkdir($this->sessionRoot, 0777, true) && !is_dir($this->sessionRoot)) {
            throw new \RuntimeException('Cannot create LSP VFS root');
        }
    }

    public function sessionRoot(): string
    {
        return $this->sessionRoot;
    }

    public function open(string $uri, string $content, int $version, string $languageId = 'moggi'): void
    {
        $this->docs[$uri] = [
            'content' => $content,
            'version' => $version,
            'languageId' => $languageId,
        ];
    }

    public function update(string $uri, string $content, int $version): void
    {
        if (!isset($this->docs[$uri])) {
            $this->open($uri, $content, $version);
            return;
        }
        $this->docs[$uri]['content'] = $content;
        $this->docs[$uri]['version'] = $version;
    }

    /** @param list<array{text?: string, range?: mixed}> $changes */
    public function applyChanges(string $uri, array $changes, int $version): void
    {
        $prev = $this->docs[$uri]['content'] ?? '';
        $this->update($uri, applyContentChanges($prev, $changes), $version);
    }

    public function close(string $uri): void
    {
        unset($this->docs[$uri]);
    }

    public function has(string $uri): bool
    {
        return isset($this->docs[$uri]);
    }

    public function content(string $uri): ?string
    {
        return $this->docs[$uri]['content'] ?? null;
    }

    public function version(string $uri): int
    {
        return $this->docs[$uri]['version'] ?? 0;
    }

    /** @return array<string, array{content: string, version: int, languageId: string}> */
    public function all(): array
    {
        return $this->docs;
    }

    public function fingerprint(string $uri): string
    {
        $c = $this->content($uri) ?? '';
        return hash('sha256', $c);
    }

    /**
     * Write overlay content for $uri into the session tree and return the
     * absolute path used for analysis. Skips rewrite when fingerprint matches.
     *
     * @param array<string, string> $overlayFingerprints uri → sha256
     */
    public function materializeOverlay(string $uri, string $projectRoot, array &$overlayFingerprints): string
    {
        $content = $this->content($uri);
        if ($content === null) {
            return uriToPath($uri);
        }
        $real = uriToPath($uri);
        $rel = $real;
        if (str_starts_with($real, $projectRoot)) {
            $rel = ltrim(substr($real, strlen($projectRoot)), '/');
        } else {
            $rel = ltrim($real, '/');
        }
        $dest = $this->sessionRoot . '/files/' . $rel;
        $fp = hash('sha256', $content);
        if (($overlayFingerprints[$uri] ?? null) === $fp && is_file($dest)) {
            return $dest;
        }
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create VFS path $dir");
        }
        file_put_contents($dest, $content);
        $overlayFingerprints[$uri] = $fp;
        return $dest;
    }

    public function cleanup(): void
    {
        $this->rmTree($this->sessionRoot);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/**
 * Long-lived language analysis service: VFS + project + indexes.
 *
 * Keystroke path: didChange updates VFS + schedules a debounced typecheck.
 * Parse/lex diagnostics publish immediately; full prepare runs after idle.
 */
final class AnalysisService
{
    public const DEBOUNCE_SEC = 0.2;

    public VirtualFS $vfs;
    public ModuleIndex $modules;
    public OccurrenceIndex $occurrences;
    /** @var list<string> */
    public array $libDirs;
    public ?string $workspaceRoot = null;
    public ?PreparedProject $project = null;
    /** @var array<string, AnalysisResult> */
    public array $results = [];
    /** @var array<string, true> */
    private array $dirty = [];

    /** @var array<string, array{version: int, dueAt: float}> */
    private array $pendingAnalyze = [];

    /** @var array<string, string> uri → last written overlay fingerprint */
    private array $overlayFingerprints = [];

    /** @var array<string, int> last completed analyze version per uri */
    private array $completedVersions = [];

    /** @var array<string, array{method: string, registration: array}> dynamic capability registrations */
    private array $dynamicRegistrations = [];

    public ?DocIndex $docIndex = null;
    public bool $warmed = false;

    /** Client capabilities / config */
    public bool $inlaysEnabled = false;
    public string $backend = 'php';
    /** Raw initialize-request client capabilities (linkSupport etc.). */
    public array $clientCapabilities = [];

    /**
     * @param list<string> $libDirs
     */
    public function __construct(array $libDirs = [])
    {
        $this->vfs = new VirtualFS();
        $this->modules = new ModuleIndex();
        $this->occurrences = new OccurrenceIndex();
        $this->libDirs = $libDirs;
    }

    public function setWorkspaceRoot(?string $root): void
    {
        $this->workspaceRoot = $root;
    }

    /** @return list<string> URIs of all currently open documents. */
    public function openUris(): array
    {
        return array_keys($this->vfs->all());
    }

    public function isOpen(string $uri): bool
    {
        return isset($this->vfs->all()[$uri]);
    }

    public function documentVersion(string $uri): ?int
    {
        $all = $this->vfs->all();

        return isset($all[$uri]) ? $all[$uri]['version'] : null;
    }

    public function openDocument(string $uri, string $content, int $version, string $languageId = 'moggi'): void
    {
        $this->vfs->open($uri, $content, $version, $languageId);
        $this->dirty[$uri] = true;
        if ($this->workspaceRoot === null) {
            $path = uriToPath($uri);
            $this->workspaceRoot = dirname($path);
        }
        // Open: analyze soon (short debounce) so the first hover is warm.
        $this->scheduleAnalyze($uri, 0.05);
    }

    public function changeDocument(string $uri, array $changes, int $version): void
    {
        $this->vfs->applyChanges($uri, $changes, $version);
        $this->dirty[$uri] = true;
        $this->scheduleAnalyze($uri);
    }

    public function saveDocument(string $uri): void
    {
        $this->dirty[$uri] = true;
        $this->scheduleAnalyze($uri, 0.0);
    }

    public function closeDocument(string $uri): void
    {
        $this->vfs->close($uri);
        unset(
            $this->results[$uri],
            $this->dirty[$uri],
            $this->pendingAnalyze[$uri],
            $this->overlayFingerprints[$uri],
            $this->completedVersions[$uri],
        );
        $this->occurrences->clearUri($uri);
    }

    public function invalidatePath(string $path): void
    {
        $uri = pathToUri($path);
        if ($this->vfs->has($uri)) {
            $this->dirty[$uri] = true;
            $this->scheduleAnalyze($uri, 0.05);
        }
        foreach ($this->vfs->all() as $openUri => $_) {
            $openPath = uriToPath($openUri);
            if ($openPath === $path || str_starts_with(dirname($openPath), dirname($path))) {
                $this->dirty[$openUri] = true;
                $this->scheduleAnalyze($openUri, 0.05);
            }
        }
    }

    public function scheduleAnalyze(string $uri, ?float $delaySec = null): void
    {
        $delay = $delaySec ?? self::DEBOUNCE_SEC;
        $this->pendingAnalyze[$uri] = [
            'version' => $this->vfs->version($uri),
            'dueAt' => microtime(true) + $delay,
        ];
    }

    /** Seconds until the soonest pending analyze is due, or null if none. */
    public function secondsUntilNextPending(): ?float
    {
        if ($this->pendingAnalyze === []) {
            return null;
        }
        $now = microtime(true);
        $min = null;
        foreach ($this->pendingAnalyze as $p) {
            $wait = $p['dueAt'] - $now;
            if ($min === null || $wait < $min) {
                $min = $wait;
            }
        }
        return $min;
    }

    /** True when at least one pending typecheck is due (or any pending if $force). */
    public function hasDueAnalyzes(bool $force = false): bool
    {
        if ($this->pendingAnalyze === []) {
            return false;
        }
        if ($force) {
            return true;
        }
        $now = microtime(true);
        foreach ($this->pendingAnalyze as $p) {
            if ($p['dueAt'] <= $now) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run due (or all, if $force) pending typechecks. Stale versions are skipped.
     *
     * @return array<string, AnalysisResult>
     */
    public function flushDueAnalyzes(bool $force = false): array
    {
        $now = microtime(true);
        $out = [];
        foreach ($this->pendingAnalyze as $uri => $pending) {
            if (!$force && $pending['dueAt'] > $now) {
                continue;
            }
            $ver = $this->vfs->version($uri);
            // Superseded: a newer edit already rescheduled; drop this ticket.
            if ($ver !== $pending['version']) {
                unset($this->pendingAnalyze[$uri]);
                continue;
            }
            unset($this->pendingAnalyze[$uri]);
            $r = $this->analyzeUri($uri, $ver);
            if ($r !== null) {
                $out[$uri] = $r;
            }
        }
        return $out;
    }

    /**
     * Fast lex/parse diagnostics only (no project typecheck).
     *
     * @return list<array<string, mixed>>
     */
    public function parseDiagnostics(string $uri): array
    {
        $content = $this->vfs->content($uri);
        if ($content === null) {
            return [];
        }
        $filename = uriToPath($uri);
        try {
            $tokens = lex($content, $filename);
            parse($tokens, $content, $filename);
            return [];
        } catch (LexError|ParseError $e) {
            return [errorToDiagnostic($e, $uri, $content)];
        } catch (\Throwable $e) {
            return [[
                'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
                'severity' => 1,
                'message' => $e->getMessage(),
                'source' => 'moggi',
                'code' => 'parse',
            ]];
        }
    }

    /**
     * Merge fresh parse diags with last typecheck diags (excluding prior parse/lex).
     *
     * @return list<array<string, mixed>>
     */
    public function quickDiagnostics(string $uri): array
    {
        $parseDiags = $this->parseDiagnostics($uri);
        $prev = $this->results[$uri]->diagnostics ?? [];
        $kept = [];
        foreach ($prev as $d) {
            $code = $d['code'] ?? '';
            if ($code === 'parse' || $code === 'lex') {
                continue;
            }
            $kept[] = $d;
        }
        // If parse failed, prefer parse-only (type diags are stale/misleading).
        if ($parseDiags !== []) {
            return $parseDiags;
        }
        return $kept;
    }

    public function seedResult(string $uri, AnalysisResult $result): void
    {
        unset($this->dirty[$uri], $this->pendingAnalyze[$uri]);
        $this->results[$uri] = $result;
        $this->completedVersions[$uri] = $this->vfs->version($uri);
        if (!$this->vfs->has($uri)) {
            $this->vfs->open($uri, $result->source, 1);
        }
        $this->indexResult($uri, $result);
    }

    public function ensureAnalyzed(string $uri): ?AnalysisResult
    {
        if (isset($this->dirty[$uri]) || !isset($this->results[$uri]) || isset($this->pendingAnalyze[$uri])) {
            unset($this->pendingAnalyze[$uri]);
            $this->analyzeUri($uri, $this->vfs->version($uri));
        }
        return $this->results[$uri] ?? null;
    }

    /**
     * @return array<string, AnalysisResult>
     */
    public function analyzeDirty(): array
    {
        return $this->flushDueAnalyzes(true);
    }

    public function analyzeUri(string $uri, ?int $expectedVersion = null): ?AnalysisResult
    {
        $ver = $this->vfs->version($uri);
        if ($expectedVersion !== null && $ver !== $expectedVersion) {
            // Stale request — a newer edit won.
            return $this->results[$uri] ?? null;
        }

        unset($this->dirty[$uri]);
        $content = $this->vfs->content($uri);
        if ($content === null) {
            $path = uriToPath($uri);
            if (!is_file($path)) {
                return null;
            }
            $content = (string) file_get_contents($path);
        }

        foreach ($this->libDirs as $dir) {
            try {
                setStdlibLibPath($dir);
            } catch (\Throwable) {
            }
        }
        setCompileBackend($this->backend);

        $path = uriToPath($uri);
        if (str_contains($uri, '://') && !str_starts_with($uri, 'file:')) {
            $tmp = $this->vfs->sessionRoot() . '/untitled/' . md5($uri) . '.mog';
            $dir = dirname($tmp);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($tmp, $content);
            $path = $tmp;
        }

        $root = $this->workspaceRoot ?? dirname($path);

        try {
            $disk = (is_file($path) && str_starts_with($uri, 'file:'))
                ? (string) file_get_contents($path)
                : null;
            if ($disk !== null && $disk === $content) {
                $filename = $path;
            } else {
                $filename = $this->vfs->materializeOverlay($uri, $root, $this->overlayFingerprints);
            }
            // Bail if a newer keystroke arrived mid-flight before heavy work.
            if ($expectedVersion !== null && $this->vfs->version($uri) !== $expectedVersion) {
                $this->dirty[$uri] = true;
                return $this->results[$uri] ?? null;
            }
            $result = $this->analyzeFile($uri, $content, $filename, $root);
        } catch (\Throwable $e) {
            $result = new AnalysisResult(
                [[
                    'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
                    'severity' => 1,
                    'message' => $e->getMessage(),
                    'source' => 'moggi',
                    'code' => 'internal',
                ]],
                [],
                [],
                new Ast\Program([]),
                [],
                $content,
                $uri,
            );
        }

        // Supersede: discard if version moved during analyze.
        if ($expectedVersion !== null && $this->vfs->version($uri) !== $expectedVersion) {
            $this->dirty[$uri] = true;
            $this->scheduleAnalyze($uri);
            return $this->results[$uri] ?? null;
        }

        $this->results[$uri] = $result;
        $this->completedVersions[$uri] = $ver;
        $this->indexResult($uri, $result);
        return $result;
    }

    private function analyzeFile(string $uri, string $content, string $filename, string $root): AnalysisResult
    {
        $diagnostics = [];
        $parsed = null;
        $checked = null;
        // Null when the project fails to prepare, so the unused-import hints are skipped instead
        // of reading an undefined variable.
        $prepared = null;

        try {
            $tokens = lex($content, $filename);
            $parsed = parse($tokens, $content, $filename);
        } catch (LexError|ParseError $e) {
            $diagnostics[] = errorToDiagnostic($e, $uri, $content);
            return new AnalysisResult(
                $diagnostics,
                [],
                [],
                new Ast\Program([]),
                [],
                $content,
                $uri,
            );
        }

        // Instances are desugared out of the checked AST — index hierarchy from parse.
        indexTypeRelations($this->modules, $uri, $content, $parsed);

        try {
            // Lib-dir precedence (last wins): explicit --lib dirs override the stdlib, and the implicit
            // workspace scan is weakest, so stray fixtures never shadow it.
            $libs = [];
            if ($this->workspaceRoot !== null && is_dir($this->workspaceRoot)) {
                $libs[] = $this->workspaceRoot;
            }
            $stdlib = bundledStdlibLibPath();
            if ($stdlib !== null) {
                $libs[] = $stdlib;
            }
            foreach ($this->libDirs as $userLib) {
                $libs[] = $userLib;
            }
            $libs = array_values(array_unique($libs));

            // Overlay dirty open buffers keyed by realpath; do NOT seed every open buffer as a project
            // input, which typechecks unrelated scratch modules together.
            /** @var array<string, string> realpath => overlayOrDiskPath */
            $overlayByReal = [];
            foreach ($this->vfs->all() as $openUri => $_) {
                $otherPath = uriToPath($openUri);
                $otherContent = $this->vfs->content($openUri);
                if ($otherContent === null) {
                    continue;
                }
                $real = realpath($otherPath) ?: $otherPath;
                $disk = is_file($otherPath) ? (string) file_get_contents($otherPath) : null;
                if ($disk !== null && $disk === $otherContent) {
                    $overlayByReal[$real] = $otherPath;
                } else {
                    $overlayByReal[$real] = $this->vfs->materializeOverlay(
                        $openUri,
                        $root,
                        $this->overlayFingerprints,
                    );
                }
            }

            try {
                [$files, $projRoot] = projectSourceClosure([$filename], $libs);
            } catch (\Throwable) {
                [$files, $projRoot] = moduleFileClosureCached($filename);
            }
            $files = array_map(
                static function (string $path) use ($overlayByReal): string {
                    $real = realpath($path) ?: $path;

                    return $overlayByReal[$real] ?? $path;
                },
                $files,
            );
            // Ensure the buffer being analyzed is in the file set (overlay path).
            if (!in_array($filename, $files, true)) {
                $files[] = $filename;
            }

            $prepared = prepareProjectCached($files, $projRoot, $parsed->module ?? null);
            $this->project = $prepared;
            $mod = $parsed->module ?? 'Main';
            // Modules the current file directly imports: index these even when
            // they are stdlib, so go-to-definition / hover resolve across the
            // import boundary without re-walking the whole stdlib.
            $importNames = [];
            foreach ($parsed->imports as $imp) {
                $importNames[implode('.', $imp->path)] = true;
            }
            if (isset($prepared->checked[$mod])) {
                $checked = $prepared->checked[$mod];
            }
            // Reindex the current module plus open / workspace modules only.
            // Re-walking the entire stdlib on every analyze hangs unit tests and
            // editor keystrokes (occurrence walk × preg_split per node).
            $stdlibRoot = $stdlib !== null ? realpath($stdlib) : false;
            $wsRoot = $this->workspaceRoot !== null ? realpath($this->workspaceRoot) : false;
            /** @var array<string, string> path => uri */
            $openByPath = [];
            foreach ($this->vfs->all() as $openUri => $_) {
                $p = realpath(uriToPath($openUri));
                if ($p !== false) {
                    $openByPath[$p] = $openUri;
                } else {
                    $openByPath[uriToPath($openUri)] = $openUri;
                }
            }
            foreach ($prepared->checked as $mName => $prog) {
                $unit = $prepared->units[$mName] ?? null;
                $mPath = \is_array($unit) ? (string) ($unit['path'] ?? '') : '';
                if ($mPath === '') {
                    continue;
                }
                $mReal = realpath($mPath) ?: $mPath;
                $mUri = pathToUri($mPath);
                $mSource = \is_array($unit) ? (string) ($unit['source'] ?? '') : '';
                if ($mSource === '' && is_file($mPath)) {
                    $mSource = (string) file_get_contents($mPath);
                }
                $isCurrent = $mName === $mod;
                $isOpen = isset($openByPath[$mReal]) || isset($openByPath[$mPath]);
                $inWorkspace = $wsRoot !== false && str_starts_with($mReal, $wsRoot . DIRECTORY_SEPARATOR);
                $inStdlib = $stdlibRoot !== false && str_starts_with($mReal, $stdlibRoot . DIRECTORY_SEPARATOR);
                $isDirectImport = isset($importNames[$mName]);
                if (!$isCurrent && !$isOpen && !$isDirectImport && ($inStdlib || !$inWorkspace)) {
                    continue;
                }
                // Prefer open-buffer content for this module when available.
                if ($isCurrent) {
                    $mUri = $uri;
                    $mSource = $content;
                } elseif ($isOpen) {
                    $mUri = $openByPath[$mReal] ?? $openByPath[$mPath] ?? $mUri;
                    $mSource = $this->vfs->content($mUri) ?? $mSource;
                }
                $this->indexModule($mUri, $mSource, $prog, $mName, $prog->externalFns ?? []);
            }
        } catch (TypeError|LexError|ParseError $e) {
            // The project is prepared from the file's whole closure, so the
            // failure can belong to a module this file merely imports. Publishing
            // it here would paint a squiggle at a position from that other
            // module's source; that module reports it when it is opened.
            if (!$e instanceof TypeError || $e->filename === '' || $e->filename === $filename) {
                $diagnostics[] = enrichDiagnostic(errorToDiagnostic($e, $uri, $content), $e);
            }
        } catch (\Throwable $e) {
            $diagnostics[] = [
                'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
                'severity' => 1,
                'message' => $e->getMessage(),
                'source' => 'moggi',
                'code' => 'internal',
            ];
        }

        $program = $checked ?? $parsed;
        $declarations = locateTopLevelDecls($parsed, $content, $uri, $checked);
        $symbols = buildDocumentSymbols($declarations, $uri, $content);
        $imports = [];
        foreach ($parsed->imports as $imp) {
            $imports[implode('.', $imp->path)] = $imp->asName ?? null;
        }

        if ($checked !== null) {
            $this->collectHoleDiagnostics($checked, $uri, $content, $diagnostics);
        }

        // Unused-import hints (Unnecessary tag) come from the parsed program's
        // import list, cross-checked against the project's checked declaration
        // names, reading the *checked* program for the usage walk. A file that
        // did not check (or whose project did not prepare) gets no hints: a
        // half-typed file would otherwise report most of its imports.
        if ($checked !== null && $prepared !== null) {
            foreach (unusedImportDiagnostics($parsed, $uri, $prepared, $checked) as $unusedDiag) {
                $diagnostics[] = $unusedDiag;
            }
        }

        return new AnalysisResult(
            $diagnostics,
            $symbols,
            $declarations,
            $program,
            $imports,
            $content,
            $uri,
        );
    }

    private function indexResult(string $uri, AnalysisResult $result): void
    {
        $moduleName = 'Main';
        if ($result->program instanceof Ast\Program && $result->program->module !== null) {
            $moduleName = $result->program->module;
        }
        $external = $result->program instanceof Ast\Program ? $result->program->externalFns : [];
        $this->indexModule($uri, $result->source, $result->program, $moduleName, $external);
    }

    /**
     * @param array<string, string> $externalFns
     */
    private function indexModule(string $uri, string $source, object $program, string $moduleName, array $externalFns): void
    {
        $this->occurrences->clearUri($uri);
        if ($program instanceof Ast\Program) {
            $decls = buildDeclsFromProgram($uri, $source, $program, $moduleName);
            $this->modules->put($moduleName, uriToPath($uri), $uri, $decls, $externalFns);
            $frag = buildOccurrenceIndex($uri, $source, $program, $moduleName, $externalFns);
            $this->occurrences->merge($frag);
            // Do NOT re-run indexTypeRelations here: checked AST has instances
            // desugared away; analyzeFile already indexed the parse tree.
        }
    }

    private function collectHoleDiagnostics(Ast\Program $program, string $uri, string $source, array &$diagnostics): void
    {
        $walk = null;
        $walk = static function ($node) use (&$walk, &$diagnostics, $source, $uri): void {
            if (!is_object($node) || $node instanceof Ast\TypeNode) {
                return;
            }
            if ($node instanceof Ast\ExprHole && ($node->line ?? 0) > 0) {
                $type = '';
                if ($node->inferredType !== null) {
                    $type = dumpTypeForDocs($node->inferredType);
                }
                $diagnostics[] = [
                    'range' => nodeToLspRange($source, $node),
                    'severity' => 2,
                    'message' => $type !== '' ? "hole with type `{$type}`" : 'typed hole',
                    'source' => 'moggi',
                    'code' => 'hole',
                    'data' => ['holeType' => $type],
                    'relatedInformation' => $type !== '' ? [[
                        'location' => [
                            'uri' => $uri,
                            'range' => nodeToLspRange($source, $node),
                        ],
                        'message' => "expected type: {$type}",
                    ]] : [],
                ];
            }
            foreach (get_object_vars($node) as $key => $prop) {
                // inferredType is a type AST / can share structure — never walk it.
                if ($key === 'inferredType') {
                    continue;
                }
                if (is_object($prop)) {
                    $walk($prop);
                } elseif (is_array($prop)) {
                    foreach ($prop as $el) {
                        if (is_object($el)) {
                            $walk($el);
                        }
                    }
                }
            }
        };
        $walk($program);
    }

    /** Warm Merkle prepare caches. DocIndex loads lazily on first moogle query. */
    public function warm(): void
    {
        if ($this->warmed) {
            return;
        }
        $this->warmed = true;
        $stdlib = bundledStdlibLibPath();
        if ($stdlib !== null) {
            try {
                setStdlibLibPath($stdlib);
            } catch (\Throwable) {
            }
        }
        $seed = $stdlib !== null ? $stdlib . '/Prelude.mog' : null;
        if ($seed === null || !is_file($seed)) {
            $seed = $stdlib !== null && is_dir($stdlib)
                ? (glob($stdlib . '/*.mog')[0] ?? null)
                : null;
        }
        if ($seed !== null && is_file($seed)) {
            try {
                [$files, $root] = moduleFileClosureCached($seed);
                prepareProjectCached($files, $root, null);
            } catch (\Throwable) {
            }
        }
    }

    /** Lazily build/load DocIndex for moogle-backed ranking (may be slow once). */
    public function ensureDocIndex(): ?DocIndex
    {
        if ($this->docIndex !== null) {
            return $this->docIndex;
        }
        $stdlib = bundledStdlibLibPath();
        $libs = $this->libDirs;
        if ($stdlib !== null) {
            $libs[] = $stdlib;
        }
        $seed = $stdlib !== null && is_file($stdlib . '/Prelude.mog')
            ? $stdlib . '/Prelude.mog'
            : ($this->workspaceRoot !== null ? $this->workspaceRoot : null);
        if ($seed === null || (!is_file($seed) && !is_dir((string) $seed))) {
            return null;
        }
        if (is_dir($seed)) {
            $first = glob(rtrim($seed, '/') . '/*.mog')[0] ?? null;
            if ($first === null) {
                return null;
            }
            $seed = $first;
        }
        try {
            $this->docIndex = loadOrBuildIndex($seed, array_values(array_unique($libs)));
        } catch (\Throwable) {
            $this->docIndex = null;
        }
        return $this->docIndex;
    }

    /** @return list<DeclInfo> */
    public function workspaceSymbols(string $query): array
    {
        $q = strtolower($query);
        $out = [];
        $seen = [];
        foreach ($this->modules->modules as $mod) {
            foreach ($mod['decls'] as $decl) {
                if ($q === '' || str_contains(strtolower($decl->name), $q)
                    || str_contains(strtolower($decl->module), $q)) {
                    $key = ($decl->resolved ?? $decl->module . '::' . $decl->name);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = $decl;
                }
            }
        }
        return $out;
    }

    public function shutdown(): void
    {
        $this->vfs->cleanup();
    }

    public function addDynamicRegistration(string $id, string $method, array $registration): void
    {
        $this->dynamicRegistrations[$id] = ['method' => $method, 'registration' => $registration];
    }

    public function removeDynamicRegistration(string $id): void
    {
        unset($this->dynamicRegistrations[$id]);
    }

    /** @return array<string, array{method: string, registration: array}> */
    public function getDynamicRegistrations(): array
    {
        return $this->dynamicRegistrations;
    }
}

function getAnalysisService(?array $libDirs = null): AnalysisService
{
    static $svc = null;
    if ($libDirs !== null || $svc === null) {
        $svc = new AnalysisService($libDirs ?? []);
    }
    return $svc;
}
