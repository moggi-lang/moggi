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
     * @param array{dispatch: string, classPath: ?string, member: ?string, path?: string} $pathInfo
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
     * @param array{dispatch: string, classPath: ?string, member: ?string, path?: string} $pathInfo
     */
    public function validateForeignImport(
        string $kind,
        array $pathInfo,
        bool $instanceReceiverIsHandle,
    ): ?string;
}


/** @return list<string> */
function knownBackendIds(): array
{
    return ['php', 'jvm', 'dotnet'];
}

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
    if (!isBackendImplemented($backend)) {
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
    if (!\in_array($backend, $allowed, true)) {
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
    if (!isset($registry[$id])) {
        throw new \InvalidArgumentException("unknown or unimplemented compile backend `{$id}`");
    }

    return $registry[$id];
}

function currentBackend(): Backend
{
    return backendById(compileBackend());
}

/** @return list<string> */
function implementedBackendIds(): array
{
    return \array_keys(backendRegistry());
}
