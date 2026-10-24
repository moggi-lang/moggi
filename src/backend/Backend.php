<?php declare(strict_types=1);

namespace Moggi\Backend;

use Moggi\IR\Module;
use Moggi\Syntax\Ast\TypeNode;

/**
 * Compile target backend. Implementations plug in here so module/CLI plumbing
 * does not hardcode target paths.
 */
interface Backend
{
    /** Stable id: "php", "jvm", "dotnet", … */
    /**
     * Primary on-disk unit for a compiled module (before packaging).
     * e.g. ".php", ".class", ".il".
     */
    public function extension(): string;

    /**
     * Name-derived relative path of a module's primary artifact, or null when
     * the backend's layout is source-path-derived instead.
     *
     * The PHP backend maps a module to a file by the module's source path, so it
     * has no name-derived answer; the JVM and .NET backends derive a fixed
     * package layout from the module name. Callers use this to locate a
     * module's artifact without hardcoding a backend id.
     */
    public function artifactPath(string $moduleName): ?string;

    /** Mangle a Moggi binding to a legal backend symbol. */
    public function symbolName(string $name): string;

    /**
     * Language-runtime files to ship with a build (paths relative to repo root,
     * or absolute). Empty if the emitter embeds/generates runtime itself.
     *
     * @return list<string>
     */
    public function runtimeFiles(): array;

    /**
     * Emit one compiled module. Return value is backend-specific
     * (source string, or map of relative-path => bytes/text).
     *
     * @param array<string, mixed> $options
     * @return string|array<string, string> string body, or map of relative-path => bytes
     */
    public function emit(Module $ir, string $sourcePath, array $options = []): string|array;

    /**
     * Extra state this backend's `emit()` reads that the module closure does not
     * carry, folded into the emit cache key.
     *
     * Emitted output is cached per project, so an input the key cannot see is
     * how a cached artifact gets reused for a build that would have emitted
     * something else. The PHP backend is the case in point: when the library
     * roots offer a Composer tree, its entry module also depends on the
     * autoloader being there, which is not a module in the closure. Empty when
     * `emit()` depends on the IR alone.
     */
    public function emitCacheDiscriminator(): string;

    /**
     * Post-process a whole-program emit map (relative path => bytes) once every
     * module's `emit()` output is merged.
     *
     * A backend whose per-module emit fans out into auxiliary files consolidates
     * them here: entries are deduplicated by path before this runs, so the fold
     * only has to gather, sort for determinism, and re-emit. Runs before the
     * emit cache stores the map, so the folded shape is what builds cache-hit.
     * The identity when a backend has nothing to consolidate.
     *
     * @param array<string, string> $emit
     * @return array<string, string>
     */
    public function foldProgramEmit(array $emit): array;

    /**
     * Human-readable dump of an `emit()` result for tooling (`:emit` /
     * `:dump emit`). Each backend owns the shape of its own artifacts, so
     * callers never have to sniff file extensions.
     *
     * @param string|array<string, string> $emit
     * @param list<string> $focusNames Moggi names to keep (empty = whole module)
     */
    public function describeEmit(string|array $emit, array $focusNames = []): string;

    /**
     * Finalize a build directory into a runnable artifact.
     *
     * @param array<string, mixed> $options entryModule?, outputRoot, …
     */
    public function packageOutput(string $outputRoot, array $options = []): void;

    /**
     * Infer a native calling signature for a foreign import, if this backend
     * needs one. Paths stay descriptor-free.
     *
     * @param 'function'|'const' $kind
     * @param array{dispatch: string, classPath: ?string, hostMember: ?string, path?: string} $pathInfo
     * @param string $name the Moggi binding name of the foreign import
     */
    public function foreignNativeSig(
        TypeNode $type,
        string $kind,
        array $pathInfo,
        string $name,
    ): ?string;

    /**
     * Backend-specific foreign-import rules (path shape, receiver constraints).
     * Return an error message, or null when the import is valid for this backend.
     *
     * @param 'function'|'const' $kind
     * @param array{dispatch: string, classPath: ?string, hostMember: ?string, path?: string} $pathInfo
     */
    public function validateForeignImport(
        string $kind,
        array $pathInfo,
        bool $instanceReceiverIsHandle,
    ): ?string;

    /**
     * Verify a foreign import against the host, when the host can be asked at compile time.
     *
     * A `foreign import` is an axiom — the compiler emits what it is told to emit — so a mistyped
     * host name, a wrong member kind or a wrong arity would otherwise surface as a runtime failure
     * (`HostException`, `NoSuchMethodError`, `MissingMethodException`). Return an error message when
     * the host was consulted and disagrees with the declaration, and null when the declaration
     * matches or when the host simply has no answer (a class that may come from a tree this scan
     * cannot see).
     *
     * A missing tool is the opposite case: a tool the verification needs in order to keep the
     * promise it makes is an error, not a stand-down — throw {@see VerificationUnavailable} and
     * the compile refuses the declaration with a positioned diagnostic. A verifier that *breaks*
     * trying (a crash inside the check, a tool failing in a way no verdict covers) still warns on
     * stderr and lets the compile continue; that path is for broken tools, not missing ones.
     *
     * The check is against `$nativeSig` — the signature this import is about to emit — so what the
     * host is asked about is exactly what codegen will call.
     *
     * @param 'function'|'const'|'type' $kind
     * @param array{dispatch: string, classPath: ?string, hostMember: ?string, path?: string} $pathInfo
     * @param ?string $nativeSig the descriptor or signature the emitter will use (null if the
     *                           backend does not carry one)
     */
    public function verifyForeignImport(
        string $kind,
        array $pathInfo,
        TypeNode $type,
        ?string $nativeSig,
        string $name,
    ): ?string;
}

/**
 * The verifier cannot keep its promise: the tool it needs is not installed.
 *
 * This is not a diagnostic — it carries no position — and it must not be
 * swallowed into a stderr warning the way a *broken* verifier is. It propagates
 * to the foreign-import check, which turns it into a positioned type error
 * naming the missing tool.
 */
final class VerificationUnavailable extends \RuntimeException
{
}

/** @return list<string> */
function knownBackendIds(): array
{
    return ['php', 'jvm', 'dotnet'];
}

/**
 * Process-wide backend selection, for the test harness and the phar builder only.
 *
 * No production compile path reads this: the pipeline takes its target from
 * `CompilationContext`, and the emitter is the one that context names. `setCompileBackend()`
 * exists for tests that drive a whole suite as one backend and for `build_phar.php`; a new
 * caller outside those two is a regression to the global this refactor removed.
 *
 * The `'php'` default stays because the harness reads it before it has a context; it is not a
 * target that a compile silently falls back to.
 */
function compileBackend(): string
{
    return $GLOBALS['moggi_compile_backend'] ?? 'php';
}

function isBackendImplemented(string $backend): bool
{
    return \in_array($backend, implementedBackendIds(), true);
}

function setCompileBackend(string $backend): void
{
    assertBackend($backend);
    if (! isBackendImplemented($backend)) {
        throw new \InvalidArgumentException(
            "code generation for backend `{$backend}` is not implemented",
        );
    }
    $GLOBALS['moggi_compile_backend'] = $backend;
}

/** @param list<string> $allowed */
function assertBackend(string $backend, array $allowed = []): void
{
    if ($allowed === []) {
        $allowed = knownBackendIds();
    }
    if (! \in_array($backend, $allowed, true)) {
        throw new \InvalidArgumentException("unknown compile backend `{$backend}`");
    }
}

/** @return array<string, Backend> */
function backendRegistry(): array
{
    static $registry = null;
    if ($registry === null) {
        $registry = [
            'php' => new Php\PhpBackend(),
            'jvm' => new Jvm\JvmBackend(),
            'dotnet' => new DotNet\DotNetBackend(),
        ];
    }

    return $registry;
}

function backendById(string $id): Backend
{
    $registry = backendRegistry();
    if (! isset($registry[$id])) {
        throw new \InvalidArgumentException("unknown or unimplemented compile backend `{$id}`");
    }

    return $registry[$id];
}

/** @return list<string> */
function implementedBackendIds(): array
{
    return \array_keys(backendRegistry());
}
