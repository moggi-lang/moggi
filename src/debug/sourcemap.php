<?php declare(strict_types=1);

namespace Moggi\Debug;

use Moggi\IR;

/**
 * Build one compiled module's source map. PHP carries it in the module itself
 * (`toRuntimeConst()`); the jvm/.NET packagers consume the portable JSON form
 * (`toJson()`) to bake their frame tables.
 * siteId values allocated here must match throwSite() constants emitted into the artifact.
 */
final class SourceMapBuilder
{
    /** @var list<array{sourceId: string, displayPath: string}> */
    private array $sources = [];

    /** @var array<string, true> */
    private array $sourceSeen = [];

    /** @var list<array<string, mixed>> */
    private array $symbols = [];

    /** @var array<string, true> */
    private array $symbolSeen = [];

    /** @var list<array<string, mixed>> */
    private array $sites = [];

    /**
     * generated line => site id, for exact stack-frame translation.
     *
     * @var array<int, int>
     */
    private array $mappings = [];

    /**
     * Host-stack frame rows for native backends: the `(class, method, source
     * line)` triple a host stack trace reports, translated to Mogg terms.
     *
     * `offset` is only set by {@see addIlFrame} (backends without a line
     * table), `text` is the pre-rendered line.
     *
     * @var list<array{class: string, method: string, line: int, col: int, path: string, symbol: string, offset?: int, text?: string}>
     */
    private array $frames = [];

    /** @var array<string, true> */
    private array $frameSeen = [];

    private int $nextSiteId = 1;

    /** Zero-width marker delimiting a mapping site inside emitted code. */
    public const MARK_OPEN = "\0M";
    public const MARK_CLOSE = "\0";

    public function __construct(
        public readonly string $module,
        public readonly string $backend,
        public readonly string $artifact,
        public readonly string $compiler = 'moggi',
    ) {
    }

    /** Ensure a function-level symbol entry exists; returns symbolId. */
    public function ensureSymbol(?IR\SrcLoc $loc, string $generated): string
    {
        $module = $loc->module ?? $this->module;
        $function = $loc->function ?? '';
        $symbolId = symbolId($module, $function);
        if (isset($this->symbolSeen[$symbolId])) {
            return $symbolId;
        }
        $this->symbolSeen[$symbolId] = true;
        $display = normalizeDisplayPath($loc->file ?? '');
        $sourceId = $display !== '' ? $display : $this->module;
        $this->addSource($sourceId, $display !== '' ? $display : $sourceId);
        $this->symbols[] = [
            'symbolId' => $symbolId,
            'generated' => $generated,
            'module' => $module,
            'function' => $function,
            'sourceId' => $sourceId,
            'line' => (int) ($loc->line ?? 0),
            'col' => (int) ($loc->col ?? 0),
            'endLine' => (int) ($loc->line ?? 0),
            'endCol' => (int) ($loc->col ?? 0),
        ];

        return $symbolId;
    }

    /**
     * Record one host-stack frame location. Native debug info carries the
     * generated class/method/source line; this table adds the Mogg display path
     * and column, which those formats do not carry.
     */
    public function addFrame(string $class, string $method, ?IR\SrcLoc $loc): void
    {
        if ($loc === null || $loc->line <= 0) {
            return;
        }
        $class = str_replace('/', '.', $class);
        $key = $class . '#' . $method . '#' . $loc->line;
        if (isset($this->frameSeen[$key])) {
            return;
        }
        $this->frameSeen[$key] = true;
        $line = (int) $loc->line;
        $col = (int) ($loc->col > 0 ? $loc->col : 0);
        $path = normalizeDisplayPath($loc->file ?? '');
        $symbol = symbolId((string) ($loc->module ?? ''), (string) ($loc->function ?? ''));
        $this->frames[] = [
            'class' => $class,
            'method' => $method,
            'line' => $line,
            'col' => $col,
            'path' => $path,
            'symbol' => $symbol,
            // Pre-rendered so native runtimes need no formatting logic.
            'text' => formatFrameLine($symbol, $path, $line, $col),
        ];
    }

    /**
     * Record a host-stack frame by *IL offset* rather than source line.
     *
     * Backends whose native debug info has no line table (no PDB) still
     * expose the IL offset at throw time; the runtime resolves it to the
     * nearest preceding sequence point, so every offset here is a statement
     * boundary the host stack can land on or after.
     */
    public function addIlFrame(string $class, string $method, int $offset, ?IR\SrcLoc $loc): void
    {
        if ($loc === null || $loc->line <= 0 || $offset < 0) {
            return;
        }
        $class = str_replace('/', '.', $class);
        $key = $class . '#' . $method . '#' . $offset;
        if (isset($this->frameSeen[$key])) {
            return;
        }
        $this->frameSeen[$key] = true;
        $line = (int) $loc->line;
        $col = (int) ($loc->col > 0 ? $loc->col : 0);
        $path = normalizeDisplayPath($loc->file ?? '');
        $symbol = symbolId((string) ($loc->module ?? ''), (string) ($loc->function ?? ''));
        $this->frames[] = [
            'class' => $class,
            'method' => $method,
            'offset' => $offset,
            'line' => $line,
            'col' => $col,
            'path' => $path,
            'symbol' => $symbol,
            // Pre-rendered so native runtimes need no formatting logic.
            'text' => formatFrameLine($symbol, $path, $line, $col),
        ];
    }

    /** @return list<array{class: string, method: string, line: int, col: int, path: string, symbol: string, offset?: int, text?: string}> */
    public function frames(): array
    {
        return $this->frames;
    }

    /**
     * Allocate a throw/expression site. Returns compact throwSite fields for emission.
     *
     * @return array{siteId: int, symbolId: string, displayPath: string, line: int, col: int}
     */
    public function allocSite(?IR\SrcLoc $loc, string $generated = ''): array
    {
        $module = $loc->module ?? $this->module;
        $function = $loc->function ?? '';
        if ($generated === '') {
            $generated = $function;
        }
        $symbolId = $this->ensureSymbol($loc, $generated);
        $displayPath = normalizeDisplayPath($loc->file ?? '');
        $sourceId = $displayPath !== '' ? $displayPath : $this->module;
        $this->addSource($sourceId, $displayPath !== '' ? $displayPath : $sourceId);
        $line = (int) ($loc->line ?? 0);
        $col = (int) ($loc->col ?? 0);
        $siteId = $this->nextSiteId++;
        $this->sites[] = [
            'siteId' => $siteId,
            'symbolId' => $symbolId,
            'sourceId' => $sourceId,
            'line' => $line,
            'col' => $col,
        ];

        return [
            'siteId' => $siteId,
            'symbolId' => $symbolId,
            'displayPath' => $displayPath,
            'line' => $line,
            'col' => $col,
        ];
    }

    /**
     * Allocate a stack-frame site for one call site in emitted code and return
     * the zero-width marker to place at that position. The marker is stripped
     * (and turned into a line mapping) by {@see resolveFrames()}.
     */
    public function allocFrame(?IR\SrcLoc $loc, string $generated = ''): string
    {
        if ($loc === null || $loc->line <= 0) {
            return '';
        }

        $site = $this->allocSite($loc, $generated);

        return self::MARK_OPEN . $site['siteId'] . self::MARK_CLOSE;
    }

    /**
     * Strip frame markers from emitted artifact text and record generated
     * line => site mappings.
     *
     * Two markers on one generated line are a collision: the same generated
     * line cannot stand for two different source lines. Same-source-line
     * markers (a nested call inside one statement) keep the outermost call,
     * which is the one the host reports for that line.
     */
    public function resolveFrames(string $text): string
    {
        $line = 1;
        $out = '';
        $offset = 0;
        $length = \strlen($text);
        while ($offset < $length) {
            $nl = strpos($text, "\n", $offset);
            if ($nl === false) {
                $nl = $length;
            }
            $segment = substr($text, $offset, $nl - $offset);
            $offset = $nl + 1;

            if (str_contains($segment, self::MARK_OPEN)) {
                $ids = [];
                $clean = preg_replace_callback(
                    '/' . preg_quote(self::MARK_OPEN, '/') . '(\d+)' . preg_quote(self::MARK_CLOSE, '/') . '/',
                    static function (array $m) use (&$ids): string {
                        $ids[] = (int) $m[1];

                        return '';
                    },
                    $segment,
                );
                $segment = $clean ?? $segment;
                foreach ($ids as $id) {
                    $sourceLine = $this->siteSourceLine($id);
                    if (!isset($this->mappings[$line])) {
                        $this->mappings[$line] = $id;
                        continue;
                    }
                    if ($sourceLine > 0 && $sourceLine !== $this->siteSourceLine($this->mappings[$line])) {
                        throw new \RuntimeException(
                            "source-map collision at generated line {$line}: sites "
                            . $this->mappings[$line] . ' and ' . $id . ' map to different source lines',
                        );
                    }
                }
            }

            $out .= $segment;
            if ($nl < $length) {
                $out .= "\n";
            }
            ++$line;
        }

        return $out;
    }

    private function siteSourceLine(int $siteId): int
    {
        return (int) ($this->siteById($siteId)['line'] ?? 0);
    }

    /** @return ?array<string, mixed> */
    private function siteById(int $siteId): ?array
    {
        foreach ($this->sites as $site) {
            if ((int) ($site['siteId'] ?? 0) === $siteId) {
                return $site;
            }
        }

        return null;
    }

    /** Register all function symbols from IR (even if they never throw). */
    public function addModuleSymbols(IR\Module $ir, callable $generatedName): void
    {
        foreach ($ir->functions as $fn) {
            $gen = (string) $generatedName($fn);
            $this->ensureSymbol($fn->srcLoc ?? new IR\SrcLoc($this->module, $fn->name, $ir->sourceFile, 0, 0), $gen);
        }
    }

    /** Whether this module has anything a report could resolve. */
    public function carriesRuntimeMap(): bool
    {
        return $this->symbols !== [];
    }

    /**
     * The module's map as a namespace constant: `[rows, functions]`, where a
     * row is `generatedLine => [symbol, displayPath, line, col]` (keyed and
     * pre-resolved, so the runtime resolves a frame with one array access) and
     * `functions` is `generated function name => [symbol, path, line, col]` for
     * frames whose generated line carries no mapping of its own.
     *
     * Null when the module has neither, so a module that can never appear in a
     * report carries nothing.
     */
    public function toRuntimeConst(string $name = '__MOGGI_MAP'): ?string
    {
        $functions = [];
        foreach ($this->runtimeFunctions() as $generated => $fn) {
            $functions[$generated] = [$fn['symbol'], $fn['path'], $fn['line'], $fn['col']];
        }

        $rows = [];
        foreach ($this->mappings as $generatedLine => $siteId) {
            $site = $this->siteById($siteId);
            if ($site === null) {
                continue;
            }
            $rows[$generatedLine] = [
                (string) ($site['symbolId'] ?? ''),
                $this->displayPathFor((string) ($site['sourceId'] ?? '')),
                (int) ($site['line'] ?? 0),
                (int) ($site['col'] ?? 0),
            ];
        }

        if ($rows === [] && $functions === []) {
            return null;
        }

        return 'const ' . $name . ' = ' . self::phpLiteral([$rows, $functions]) . ';';
    }

    /** One-line PHP literal for map data (ints, strings, and arrays of both). */
    private static function phpLiteral(mixed $value): string
    {
        if (\is_array($value)) {
            $list = array_is_list($value);
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = ($list ? '' : (\is_int($key) ? (string) $key : var_export($key, true)) . ' => ')
                    . self::phpLiteral($item);
            }

            return '[' . \implode(', ', $parts) . ']';
        }
        if (\is_string($value)) {
            return "'" . \str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }

        return var_export($value, true);
    }

    public function toJson(): string
    {
        $payload = [
            'version' => 1,
            'compiler' => $this->compiler,
            'backend' => $this->backend,
            'module' => $this->module,
            'artifact' => $this->artifact,
            'sources' => $this->sources,
            'symbols' => $this->symbols,
            'sites' => $this->sites,
            'mappings' => $this->mappingRows(),
            'frames' => $this->frames,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('failed to encode .moggi.map');
        }

        return $json . "\n";
    }

    /**
     * Flat `[generatedLine, siteId]` rows, sorted by generated line.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function mappingRows(): array
    {
        $rows = [];
        foreach ($this->mappings as $generatedLine => $siteId) {
            $rows[] = [$generatedLine, $siteId];
        }

        return $rows;
    }

    /**
     * `generated function name => declaration site`, with the display path and
     * symbol text pre-resolved so the runtime needs one hash lookup per frame.
     *
     * @return array<string, array{symbol: string, path: string, line: int, col: int}>
     */
    private function runtimeFunctions(): array
    {
        $out = [];
        foreach ($this->symbols as $symbol) {
            $generated = (string) ($symbol['generated'] ?? '');
            $line = (int) ($symbol['line'] ?? 0);
            if ($generated === '' || $line <= 0) {
                continue;
            }
            $out[$generated] = [
                'symbol' => (string) ($symbol['symbolId'] ?? ''),
                'path' => $this->displayPathFor((string) ($symbol['sourceId'] ?? '')),
                'line' => $line,
                'col' => (int) ($symbol['col'] ?? 0),
            ];
        }

        return $out;
    }

    private function displayPathFor(string $sourceId): string
    {
        foreach ($this->sources as $source) {
            if (($source['sourceId'] ?? null) === $sourceId) {
                return (string) ($source['displayPath'] ?? $sourceId);
            }
        }

        return $sourceId;
    }

    private function addSource(string $sourceId, string $displayPath): void
    {
        if (isset($this->sourceSeen[$sourceId])) {
            return;
        }
        $this->sourceSeen[$sourceId] = true;
        $this->sources[] = [
            'sourceId' => $sourceId,
            'displayPath' => $displayPath,
        ];
    }
}

/** One rendered `.mog` stack frame, exactly as the Mogg trace shows it. */
function formatFrameLine(string $symbol, string $path, int $line, int $col): string
{
    $name = $symbol !== '' ? $symbol : '<unknown>';
    if ($path !== '' && $line > 0) {
        return $col > 0
            ? "  at {$name} ({$path}:{$line}:{$col})\n"
            : "  at {$name} ({$path}:{$line})\n";
    }

    return "  at {$name}\n";
}

function symbolId(string $module, string $function): string
{
    if ($module === '') {
        return $function !== '' ? $function : '<unknown>';
    }
    if ($function === '') {
        return $module;
    }

    return $module . '.' . $function;
}

/** Project-relative display path — never an absolute developer filesystem path as identity. */
function normalizeDisplayPath(string $file): string
{
    if ($file === '' || $file === '<interactive>') {
        return $file;
    }
    $file = str_replace('\\', '/', $file);
    foreach (['/lib/', '/tests/', '/examples/'] as $marker) {
        $pos = strpos($file, $marker);
        if ($pos !== false) {
            return substr($file, $pos + 1);
        }
    }
    foreach (['lib/', 'tests/', 'examples/'] as $marker) {
        if (str_starts_with($file, $marker)) {
            return $file;
        }
    }
    // Strip common absolute prefixes down to basename if unknown.
    if (str_starts_with($file, '/') || preg_match('#^[A-Za-z]:/#', $file) === 1) {
        return basename($file);
    }

    return $file;
}

/**
 * The `frames` table of a map payload, which the jvm/.NET packagers bake into
 * their runtime frame lookup. Empty when the payload is not a map.
 *
 * @return list<array<string, mixed>>
 */
function sourceMapFrames(string $json): array
{
    $data = json_decode($json, true);
    $frames = \is_array($data) ? ($data['frames'] ?? []) : [];
    if (!\is_array($frames)) {
        return [];
    }
    $out = [];
    foreach ($frames as $frame) {
        if (\is_array($frame)) {
            $out[] = $frame;
        }
    }

    return $out;
}

function mapPathForArtifact(string $artifactRelative): string
{
    $base = preg_replace('/\.(php|class|il)$/', '', str_replace('\\', '/', $artifactRelative))
        ?? str_replace('\\', '/', $artifactRelative);

    return $base . '.moggi.map';
}
