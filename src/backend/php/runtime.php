<?php declare(strict_types=1);

namespace Moggi;

/**
 * Apply one or more arguments to a partial application or a PHP callable.
 *
 * A partial application is a plain value, not a deferred computation: Moggi is
 * strict, so every argument in the cell is already evaluated and supplying the
 * last one calls the target there and then. Cells are
 * `['__partial', arity, callableName, ...appliedArgs]`; a callable array has no
 * `__partial` head and is invoked directly.
 *
 * Hot path. Every unsaturated curried call funnels through here, so the
 * saturation cases are spelled out for the shapes the backends actually emit
 * (one remaining argument is by far the most common) and the partial-extension
 * case grows the cell in place instead of rebuilding it with array_slice.
 *
 * `$fn` is a by-value parameter, so `$fn[] = ...` is safe: PHP copies on write
 * only if the caller still holds a reference to the same cell.
 *
 * The parameter is `callable|array`, not just `callable`: a partial cell is an
 * array that is *not* callable, so the `array` member is what admits it, while
 * `callable` is what rejects a stray int/float/null/object. Both members carry
 * weight. Because the type check runs at the boundary, the body needs no
 * `is_callable()` of its own — a non-array argument is callable by construction.
 *
 * @param callable|array<int|string, mixed> $fn
 */
function __apply(callable|array $fn, mixed ...$args): mixed
{
    if (\is_array($fn)) {
        if (($fn[0] ?? null) !== '__partial') {
            // A callable array (`[$object, 'method']`, `['Class', 'method']`) is
            // invoked directly: PHP resolves its own arity and raises
            // ArgumentCountError on a short call, so there is no cell to build.
            return $fn(...$args);
        }

        $arity = $fn[1];
        $applied = count($fn) - 3;
        $incoming = count($args);

        if ($applied + $incoming >= $arity) {
            $need = $arity - $applied;
            $name = $fn[2];

            if ($need === 0) {
                return $name(...\array_slice($fn, 3, $arity));
            }
            if ($applied === 0) {
                return $name(...\array_slice($args, 0, $arity));
            }
            if ($need === 1) {
                $last = $args[0];

                return match ($applied) {
                    1 => $name($fn[3], $last),
                    2 => $name($fn[3], $fn[4], $last),
                    3 => $name($fn[3], $fn[4], $fn[5], $last),
                    default => $name(...[...\array_slice($fn, 3), $last]),
                };
            }

            $result = [];
            if ($applied === 1) {
                $result[0] = $fn[3];
                $i = 1;
            } elseif ($applied === 2) {
                $result[0] = $fn[3];
                $result[1] = $fn[4];
                $i = 2;
            } elseif ($applied === 3) {
                $result[0] = $fn[3];
                $result[1] = $fn[4];
                $result[2] = $fn[5];
                $i = 3;
            } else {
                $i = 0;
                for (; $i < $applied; ++$i) {
                    $result[$i] = $fn[$i + 3];
                }
            }
            for ($j = 0; $j < $need; ++$j) {
                $result[$i++] = $args[$j];
            }

            return $name(...$result);
        }

        if ($incoming === 1) {
            $fn[] = $args[0];

            return $fn;
        }
        foreach ($args as $arg) {
            $fn[] = $arg;
        }

        return $fn;
    }

    $callable = $fn;
    if (!($callable instanceof \Closure) && !(\is_string($callable) && !str_contains($callable, '::'))) {
        $callable = \Closure::fromCallable($callable);
    }

    $ref = new \ReflectionFunction($callable);
    $arity = $ref->getNumberOfParameters();
    if (count($args) < $arity) {
        return ['__partial', $arity, $callable, ...$args];
    }

    return $callable(...$args);
}

/**
 * Strict fixpoint — `Data.Function.fix` (`fix#`).
 *
 * `fix f` applies `f` to a self-reference that memoises `fix f` and forwards
 * every further argument to it, i.e. a value behaving like `\x -> fix f x`.
 * The reference is only forced when `f` actually uses it, so recursion is
 * expressible under strict evaluation:
 *
 *     fix (\rec -> \n -> if n <= 1 then 1 else n * rec (n - 1))
 *
 * The self-reference is declared with arity 0 so `__apply` never mistakes a
 * bare use of it for a partial application; real arguments arrive through
 * func_get_args().
 *
 * @param callable|array<int|string, mixed> $f
 */
function fix(callable|array $f): mixed
{
    $value = null;
    $done = false;
    $self = null;
    $self = static function () use ($f, &$value, &$done, &$self): mixed {
        if (!$done) {
            $done = true;
            $value = __apply($f, $self);
        }

        $args = \func_get_args();

        return $args === [] ? $value : __apply($value, ...$args);
    };

    return __apply($f, $self);
}

/**
 * Box a native PHP value as a Moggi Platform.PHP.PHPValue tagged ADT.
 * List arrays become PhpArray; string-keyed arrays / stdClass become PhpObject.
 *
 * Only structurally reifiable values are accepted. Opaque class instances
 * must use a concrete foreign php type — they are not silently PhpNull/PhpObject.
 *
 * @return array<int|string, mixed>
 */
function phpValueBox(mixed $value): array
{
    // Order matters: PHP scalars are matched before the container cases, and
    // anything else is a host value we refuse to invent a shape for.
    return match (true) {
        $value === null => ['PhpNull'],
        \is_bool($value) => ['PhpBool', $value],
        \is_int($value) => ['PhpInt', $value],
        \is_float($value) => ['PhpDouble', $value],
        \is_string($value) => ['PhpString', $value],
        \is_array($value) => \array_is_list($value)
            ? ['PhpArray', \array_map(phpValueBox(...), $value)]
            : ['PhpObject', phpMapEntries($value)],
        $value instanceof \stdClass => ['PhpObject', phpMapEntries((array) $value)],
        \is_resource($value) => ['PhpResource', ['MkResource', $value]],
        default => throw new \RuntimeException(
            'phpValueBox: cannot reify opaque host value of type `'
            . (\is_object($value) ? $value::class : \get_debug_type($value))
            . '` as PHPValue; declare a concrete foreign php type instead',
        ),
    };
}

/**
 * `MapEntry key value` for every member of a string-keyed array or object.
 *
 * @param array<int|string, mixed> $entries
 * @return list<array{0: 'MapEntry', 1: string, 2: array<int|string, mixed>}>
 */
function phpMapEntries(array $entries): array
{
    $out = [];
    foreach ($entries as $key => $item) {
        $out[] = ['MapEntry', (string) $key, phpValueBox($item)];
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Exception runtime (SomeException# = ['__se', stableTag, payload, display])
// ---------------------------------------------------------------------------

/** Stable exception type tags (must match Control.Exception / Moggi.IO.Exception). */
const EX_TAG_ERROR_CALL = 'ErrorCall';
const EX_TAG_IO_EXCEPTION = 'IOException';
const EX_TAG_HOST = 'HostException';

/**
 * Compact throw-site stamp (no absolute paths; map resolves siteId when present).
 *
 * @return array{siteId: int, symbolId: string, displayPath: string, line: int, col: int}
 */
function throwSite(int $siteId, string $symbolId, string $displayPath, int $line, int $col): array
{
    return [
        'siteId' => $siteId,
        'symbolId' => $symbolId,
        'displayPath' => $displayPath,
        'line' => $line,
        'col' => $col,
    ];
}

/**
 * Carry the wrapper a caught payload came from, so a rethrow recovers the site
 * the exception was originally thrown at instead of the rethrow site.
 */
function exceptionAttachWrapper(array $se, MoggiException $e): array
{
    $se[4] = $e;

    return $se;
}

/**
 * The map of the module a generated function belongs to. Every compiled module
 * carries its own map as `__MOGGI_MAP`, so a report reads it out of the loaded
 * module — no sidecar file, no lookup of generated paths, and nothing at all
 * until a frame in that module is reported.
 */
function moduleMap(string $function): ?array
{
    /** @var array<string, ?array> module namespace => its map, or null */
    static $maps = [];
    if ($function === '') {
        return null;
    }
    $slash = \strrpos($function, '\\');
    $namespace = $slash === false ? '' : \substr($function, 0, $slash);
    if (\array_key_exists($namespace, $maps)) {
        return $maps[$namespace];
    }
    $const = $namespace === '' ? '__MOGGI_MAP' : $namespace . '\\__MOGGI_MAP';
    $map = null;
    if (\defined($const)) {
        $value = \constant($const);
        if (\is_array($value) && \is_array($value[0] ?? null) && \is_array($value[1] ?? null)) {
            $map = ['rows' => $value[0], 'functions' => $value[1]];
        }
    }

    return $maps[$namespace] = $map;
}

/**
 * @param array{siteId?: int, symbolId?: string, displayPath?: string, line?: int, col?: int, module?: string, function?: string, file?: string} $site
 * @return array{symbolId: string, displayPath: string, line: int, col: int}
 */
function resolveThrowSite(array $site): array
{
    // The stamped site is authoritative and self-describing. `siteId` values
    // are allocated per module, so looking one up across every loaded map can
    // resolve to an unrelated module's site; the compact fields cannot.
    return [
        'symbolId' => (string) ($site['symbolId'] ?? ''),
        'displayPath' => displaySourcePath((string) ($site['displayPath'] ?? '')),
        'line' => (int) ($site['line'] ?? 0),
        'col' => (int) ($site['col'] ?? 0),
    ];
}

final class MoggiException extends \RuntimeException
{
    /**
     * @param array{0: '__se', 1: string, 2: mixed} $someException
     * @param ?array{siteId?: int, symbolId?: string, displayPath?: string, line?: int, col?: int} $throwSite
     */
    public function __construct(
        public array $someException,
        ?\Throwable $previous = null,
        public ?array $throwSite = null,
    ) {
        $tag = $someException[1] ?? 'SomeException';
        $payload = $someException[2] ?? null;
        $stored = $someException[3] ?? null;
        $msg = \is_string($stored) ? $stored : exceptionDisplayMessage($tag, $payload);
        // Only true host throwables are causes — never nest MoggiException as previous.
        $cause = ($previous instanceof MoggiException) ? null : $previous;
        parent::__construct($msg, 0, $cause);
    }
}

/**
 * Build a SomeException#: tag, payload, and the display text rendered where the
 * value still had its `Exception` dictionary.
 *
 * The display travels with the exception because the runtime has no dictionaries
 * of its own: a rethrow or an uncaught report can print it verbatim instead of
 * reconstructing it from the payload's shape. Payloads the runtime builds itself
 * (host throwables, IO failures) pass their message instead.
 */
function exceptionWrap(string $tag, string $display, mixed $payload): array
{
    return ['__se', $tag, $payload, $display];
}

/** @return array{'Nothing'}|array{0: 'Just', 1: mixed} */
function exceptionUnwrap(string $tag, mixed $se): array
{
    if (\is_array($se) && ($se[0] ?? null) === '__se' && ($se[1] ?? null) === $tag) {
        return ['Just', $se[2]];
    }

    return ['Nothing'];
}

/**
 * @param ?array{siteId?: int, symbolId?: string, displayPath?: string, line?: int, col?: int} $throwSite
 */
function throwErrorCall(string $message, ?array $throwSite = null): never
{
    throw new MoggiException(
        exceptionWrap(EX_TAG_ERROR_CALL, $message, ['ErrorCall', $message]),
        null,
        $throwSite,
    );
}

/**
 * @param ?array{siteId?: int, symbolId?: string, displayPath?: string, line?: int, col?: int} $throwSite
 */
function throwSomeException(mixed $se, ?array $throwSite = null): never
{
    if ($se instanceof MoggiException) {
        throw $se;
    }

    if (\is_array($se) && ($se[0] ?? null) === '__se') {
        // A payload handed to a catch handler names the wrapper it was caught as
        // (`exceptionAttachWrapper`), which holds the original throwSite.
        $origin = $se[4] ?? null;
        if ($origin instanceof MoggiException) {
            throw $origin;
        }

        throw new MoggiException($se, null, $throwSite);
    }

    throw new MoggiException(
        hostExceptionPayload('php', 'unknown', (string) $se),
        null,
        $throwSite,
    );
}

/** Rethrow preserving MoggiException; normalize other host throwables first. */
function rethrowNormalized(\Throwable $e): never
{
    if ($e instanceof MoggiException) {
        throw $e;
    }

    throw new MoggiException(normalizeHostException($e), $e, null);
}

/**
 * Format a user-facing Moggi exception report (stderr / REPL).
 *
 * Two sections, never blended:
 *   - the Mogg trace: locations translated through the artifact map, each
 *     naming the function executing and the statement in it that made the call;
 *   - the native cause: the host throwable's own stack, in host terms.
 *
 * Nothing is fabricated and nothing is hidden: a frame we cannot translate is
 * still printed, in host terms. Must never throw.
 */
function formatExceptionReport(\Throwable $e): string
{
    try {
        if (!($e instanceof MoggiException)) {
            $wrapped = new MoggiException(normalizeHostException($e), $e, null);

            return formatExceptionReport($wrapped);
        }

        $tag = (string) ($e->someException[1] ?? 'SomeException');
        $msg = $e->getMessage();
        $out = 'moggi: ' . formatExceptionHeader($tag, $e->someException[2] ?? null, $msg) . "\n";

        $cause = $e->getPrevious();
        $hasHostSection = $cause !== null && !($cause instanceof MoggiException);

        $shown = [];
        $frames = [];
        $throwKey = null;
        if ($e->throwSite !== null) {
            $loc = resolveThrowSite($e->throwSite);
            $frames[] = $loc;
            $throwKey = frameKey($loc);
            $shown[$throwKey] = true;
        }

        // Untranslatable frames are printed in host terms only when no host section follows;
        // repeats are kept (recursion depth), only the throw site is deduplicated.
        foreach (moggCallerFrames($e, !$hasHostSection) as $frame) {
            $key = frameKey($frame);
            if ($throwKey !== null && $key === $throwKey) {
                continue;
            }
            $frames[] = $frame;
            $shown[$key] = true;
        }

        $out .= formatMoggFrames($frames);

        if ($hasHostSection) {
            $out .= 'caused by: ' . $cause::class . ': ' . $cause->getMessage() . "\n";
            $out .= hostCauseFrames($cause, $shown);
        }

        return $out;
    } catch (\Throwable) {
        return "moggi: error: failed to format exception report\n";
    }
}

/** Frames rendered per section before the tail is elided. */
const MOGGI_TRACE_FRAME_LIMIT = 50;

/**
 * Render a frame list, eliding the tail past the limit.
 *
 * @param list<array{symbolId: string, displayPath: string, line: int, col: int}> $frames
 */
function formatMoggFrames(array $frames): string
{
    $out = '';
    foreach (\array_slice($frames, 0, MOGGI_TRACE_FRAME_LIMIT) as $frame) {
        $out .= formatStackFrame($frame['symbolId'], $frame['displayPath'], $frame['line'], $frame['col']);
    }
    $elided = count($frames) - MOGGI_TRACE_FRAME_LIMIT;
    if ($elided > 0) {
        $out .= "  ... {$elided} more\n";
    }

    return $out;
}

/** Header line: tag, plus the IOException subtype when it carries one. */
function formatExceptionHeader(string $tag, mixed $payload, string $message): string
{
    if ($tag === EX_TAG_IO_EXCEPTION && \is_array($payload)) {
        $ioType = (string) (($payload[2][0] ?? '') ?: '');
        if ($ioType !== '') {
            return "{$tag} ({$ioType}): {$message}";
        }
    }

    return "{$tag}: {$message}";
}

/** @param array{symbolId: string, displayPath: string, line: int, col: int} $loc */
function frameKey(array $loc): string
{
    return $loc['symbolId'] . '@' . $loc['displayPath'] . ':' . $loc['line'] . ':' . $loc['col'];
}

function formatStackFrame(string $symbolId, string $displayPath, int $line, int $col): string
{
    $file = displaySourcePath($displayPath);
    if ($file !== '' && $line > 0) {
        $name = $symbolId !== '' ? $symbolId : '<unknown>';

        return "  at {$name} ({$file}:{$line}:{$col})\n";
    }
    if ($symbolId !== '') {
        return "  at {$symbolId}\n";
    }

    return '';
}

/**
 * The Mogg call trace: host frames translated through the artifact map.
 *
 * PHP's `getTrace()` entry N describes the call to `[N]['function']` made at
 * `[N]['file']:[N]['line']`. A trace frame names the function executing and the
 * statement in it that made the call, so the locations shift one entry up.
 *
 * Frames belonging to the Moggi runtime itself are suppressed here (they are
 * the same constant noise on every report); they still appear in the native
 * cause section, which is raw.
 *
 * @return list<array{symbolId: string, displayPath: string, line: int, col: int}>
 */
function moggCallerFrames(MoggiException $e, bool $includeUnmapped = true): array
{
    try {
        $cause = $e->getPrevious();
        // A native failure wrapped at report time has no Mogg stack of its own
        // (the wrapper was built here, in the reporter), so translate the native
        // stack — it is the same call chain.
        $frames = ($e->throwSite === null && $cause !== null && !($cause instanceof MoggiException))
            ? javaStyleFrames($cause->getFile(), $cause->getLine(), $cause->getTrace())
            : javaStyleFrames($e->getFile(), $e->getLine(), $e->getTrace());

        $out = [];
        $prevName = null;
        $prevKey = null;
        foreach ($frames as $frame) {
            if (isMoggiRuntimeFile($frame['file'])) {
                continue;
            }
            // A generated line the emitter did not mark still names its function, and the map knows
            // where it was declared — the fallback the other backends get from debug info.
            $resolved = lookupGeneratedFrame($frame['line'], $frame['name'])
                ?? lookupGeneratedFunction($frame['name']);
            if ($resolved !== null) {
                // A raise site and its caller can share one generated line, which is one physical call
                // and is kept once; repeats of the same host function are recursion depth.
                $key = frameKey($resolved);
                if ($key === $prevKey && $frame['name'] !== $prevName) {
                    continue;
                }
                $out[] = $resolved;
                $prevKey = $key;
                $prevName = $frame['name'];
                continue;
            }
            // Not translatable with a real location: print it in host terms
            // rather than dropping it.
            if (!$includeUnmapped || $frame['name'] === '' || $frame['line'] <= 0) {
                continue;
            }
            $out[] = [
                'symbolId' => $frame['name'],
                'displayPath' => displaySourcePath($frame['file']),
                'line' => $frame['line'],
                'col' => 0,
            ];
        }

        return $out;
    } catch (\Throwable) {
        return [];
    }
}

/**
 * Native cause stack, in host terms, skipping frames the Mogg trace already
 * showed.
 *
 * @param array<string, true> $shown
 */
function hostCauseFrames(\Throwable $cause, array $shown): string
{
    try {
        $out = '';
        $index = 0;
        foreach (javaStyleFrames($cause->getFile(), $cause->getLine(), $cause->getTrace()) as $frame) {
            if ($frame['name'] === '' || $frame['line'] <= 0) {
                continue;
            }
            $mapped = lookupGeneratedFrame($frame['line'], $frame['name']);
            // The innermost frame is what faulted (native mechanism, exact generated line); callers
            // the Mogg trace already carries are dropped.
            if ($index > 0 && $mapped !== null && isset($shown[frameKey($mapped)])) {
                continue;
            }
            if ($index >= MOGGI_TRACE_FRAME_LIMIT) {
                $elided = count($cause->getTrace()) - $index;
                $out .= '  ... ' . max(1, $elided) . " more\n";

                break;
            }
            $out .= '  #' . $index . ' ' . $frame['name']
                . ' (' . displaySourcePath($frame['file']) . ':' . $frame['line'] . ")\n";
            ++$index;
        }

        return $out;
    } catch (\Throwable) {
        return '';
    }
}

/**
 * Re-shape a host backtrace so each frame names the executing function and the
 * statement in it that made the call.
 *
 * @param list<array<string, mixed>> $trace
 * @return list<array{name: string, file: string, line: int}>
 */
function javaStyleFrames(string $throwFile, int $throwLine, array $trace): array
{
    if ($trace === []) {
        return [];
    }

    $frames = [[
        'name' => (string) ($trace[0]['function'] ?? ''),
        'file' => $throwFile,
        'line' => $throwLine,
    ]];

    $count = count($trace);
    for ($i = 0; $i + 1 < $count; ++$i) {
        $name = (string) ($trace[$i + 1]['function'] ?? '');
        if ($name === '' || str_starts_with($name, '{')) {
            continue;
        }
        $frames[] = [
            'name' => $name,
            'file' => (string) ($trace[$i]['file'] ?? ''),
            'line' => (int) ($trace[$i]['line'] ?? 0),
        ];
    }

    return $frames;
}

function isMoggiRuntimeFile(string $file): bool
{
    $file = str_replace('\\', '/', $file);

    return $file === str_replace('\\', '/', __FILE__) || str_ends_with($file, '/_runtime.php');
}

/**
 * Translate a generated artifact position to its Mogg source location.
 *
 * @return ?array{symbolId: string, displayPath: string, line: int, col: int}
 */
function lookupGeneratedFrame(int $line, string $function = ''): ?array
{
    if ($line <= 0) {
        return null;
    }
    $map = moduleMap($function);
    $row = $map === null ? null : ($map['rows'][$line] ?? null);
    if ($row === null) {
        return null;
    }

    return [
        'symbolId' => (string) $row[0],
        'displayPath' => (string) $row[1],
        'line' => (int) $row[2],
        'col' => (int) $row[3],
    ];
}

/**
 * Translate a generated *function* to the site its declaration maps to, for
 * frames whose generated line has no mapping of its own.
 *
 * @return ?array{symbolId: string, displayPath: string, line: int, col: int}
 */
function lookupGeneratedFunction(string $name): ?array
{
    $map = $name === '' ? null : moduleMap($name);
    $fn = $map === null ? null : ($map['functions'][$name] ?? null);

    return $fn === null ? null : [
        'symbolId' => (string) $fn[0],
        'displayPath' => (string) $fn[1],
        'line' => (int) $fn[2],
        'col' => (int) $fn[3],
    ];
}



function displaySourcePath(string $file): string
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

    return basename($file);
}

function reportUncaught(\Throwable $e): never
{
    fwrite(STDERR, formatExceptionReport($e));
    exit(1);
}

/**
 * Map a host Throwable to a Moggi SomeException#.
 *
 *   MoggiException -> unwrap payload
 *   other Throwable -> HostException (never IOException or ErrorCall)
 *
 * Classification into `IOException` belongs to `System.IO`, which knows the
 * path an operation used; the runtime only reports the raw host failure.
 * ErrorCall is produced only by error# / explicit ErrorCall, not by host catches.
 */
function normalizeHostException(\Throwable $e): array
{
    if ($e instanceof MoggiException) {
        return $e->someException;
    }

    return hostExceptionPayload('php', $e::class, $e->getMessage() ?: $e::class);
}

/** @return array{0: '__se', 1: 'HostException', 2: mixed} */
function hostExceptionPayload(string $backend, string $nativeType, string $message): array
{
    $payload = ['HostException', $backend, $nativeType, $message];

    return exceptionWrap(EX_TAG_HOST, $message, $payload);
}

/**
 * Display text for a payload that carries none of its own.
 *
 * Everything the *library* wraps stores its display (see `exceptionWrap`); this
 * is the fallback for payloads the runtime builds from a host throwable or an
 * IO failure, whose shapes are known here.
 */
function exceptionDisplayMessage(string $tag, mixed $payload): string
{
    if ($tag === EX_TAG_ERROR_CALL && \is_array($payload) && ($payload[0] ?? null) === 'ErrorCall') {
        return (string) ($payload[1] ?? 'error');
    }
    if ($tag === EX_TAG_HOST && \is_array($payload)) {
        // HostException backend nativeType message
        if (($payload[0] ?? null) === 'HostException') {
            return (string) ($payload[3] ?? $payload[2] ?? 'host exception');
        }

        return (string) ($payload[1] ?? 'host exception');
    }
    if (\is_string($payload)) {
        return $payload;
    }

    return $tag;
}

function exceptionDisplay(mixed $se): string
{
    if (\is_array($se) && ($se[0] ?? null) === '__se') {
        $stored = $se[3] ?? null;
        if (\is_string($stored)) {
            return $stored;
        }

        return exceptionDisplayMessage((string) ($se[1] ?? 'SomeException'), $se[2] ?? null);
    }

    return 'SomeException';
}
