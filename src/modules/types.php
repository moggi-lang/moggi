<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Cache;
use Moggi\Pipeline\CompilationContext;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Syntax\Ast;

/** A single module after type checking and IO normalization. */
final class CheckedModule
{
    /** @param array<string, Scheme> $exportedInferredSchemes */
    public function __construct(
        public readonly Ast\Program $program,
        public readonly array $exportedInferredSchemes,
    ) {
    }
}

/**
 * A whole import closure, parsed and type-checked, ready to be lowered.
 *
 * `units` holds the per-module source/parse bookkeeping; `checked` and
 * `importContexts` are keyed by module name and only present for modules that
 * were actually checked.
 */
final class PreparedProject
{
    /**
     * @param array<string, array<string, mixed>> $units
     * @param array<string, Ast\Program> $checked
     * @param array<string, ImportContext> $importContexts
     */
    public function __construct(
        public readonly string $rootPrefix,
        public readonly array $units,
        public readonly array $checked,
        public readonly array $importContexts,
    ) {
    }

    /** Whether this project prepared the module at all (checked, synthetic, or read from cache). */
    public function has(string $moduleName): bool
    {
        return isset($this->checked[$moduleName]) || isset($this->importContexts[$moduleName]);
    }

    /**
     * The checked program to lower one module from.
     *
     * A project that released its checked programs (`prepareProject(..., releaseChecked: true)`)
     * holds each module's product on disk and not in memory, so the program is read back here — the
     * same read the next compile of that module would do, and the one record `Cache\modulePut('checked')`
     * wrote. It is a refusal, not a fallback, when the entry cannot answer: a product that was released
     * and then lost means the caller is lowering a module nobody has.
     */
    public function program(string $moduleName, CompilationContext $context): Ast\Program
    {
        $held = $this->checked[$moduleName] ?? null;
        if ($held instanceof Ast\Program) {
            return $held;
        }

        $unit = $this->units[$moduleName] ?? null;
        $contentKey = \is_array($unit) ? ($unit['contentKey'] ?? null) : null;
        $relPath = \is_array($unit) ? ($unit['cacheRelPath'] ?? null) : null;
        if ($contentKey !== null && $relPath !== null) {
            $cached = Cache\moduleGet(
                $relPath,
                'checked',
                $context->moduleKey($moduleName, $contentKey),
                $context->backendId,
            );
            if (\is_array($cached) && ($cached['program'] ?? null) instanceof Ast\Program) {
                return $cached['program'];
            }
        }

        throw new \RuntimeException(
            "the prepared project `{$moduleName}` carries no checked program and its module cache entry"
            . ' does not either; a released program cannot be invented from the source tree',
        );
    }
}

/**
 * Process-lifetime memo tables for the module pipeline.
 *
 * Keys include path, mtime, and compile backend (facades / output extensions /
 * foreign nativeSigs differ per backend). Disk cache in `Moggi\Cache` is the
 * durable tier underneath.
 */
final class ProjectCache
{
    /**
     * @var array<string, PreparedProject>
     *
     * Insertion-ordered: a hit moves its entry to the back, and remembering
     * past the cap drops the least recently used. Only shallow focus bases
     * are stored (see `prepareFocusBase`) — one per backend, so 3 covers a
     * three-backend session. Full prepares embed the whole checked closure
     * (hundreds of MB each) and are never stored: retaining them OOMed 1GB
     * CI shard workers, and no caller repeats a full key in one process.
     * Single-use focus extensions are never stored either.
     */
    private static array $preparedProjects = [];

    private const PREPARED_PROJECT_MAX = 3;

    /** @var array<string, CheckedModule> */
    private static array $checkedModules = [];

    /**
     * The keys in `$checkedModules` that hold a source outside the stdlib — one project's own module,
     * rather than part of the closure every project in the process shares.
     *
     * @var array<string, true>
     */
    private static array $projectModules = [];

    public static function preparedProject(string $key): ?PreparedProject
    {
        $project = self::$preparedProjects[$key] ?? null;
        if ($project !== null) {
            unset(self::$preparedProjects[$key]);
            self::$preparedProjects[$key] = $project;
        }

        return $project;
    }

    public static function rememberPreparedProject(string $key, PreparedProject $project): void
    {
        unset(self::$preparedProjects[$key]);
        self::$preparedProjects[$key] = $project;
        while (\count(self::$preparedProjects) > self::PREPARED_PROJECT_MAX) {
            \array_shift(self::$preparedProjects);
        }
    }

    public static function checkedModule(string $key): ?CheckedModule
    {
        return self::$checkedModules[$key] ?? null;
    }

    /** `$projectModule` marks an entry a caller may release again — see `releaseProjectCheckedModules`. */
    public static function rememberCheckedModule(string $key, CheckedModule $module, bool $projectModule = false): void
    {
        self::$checkedModules[$key] = $module;
        if ($projectModule) {
            self::$projectModules[$key] = true;
        }
    }

    /**
     * Drop the checked modules marked as a project's own sources, keeping the stdlib closure.
     *
     * A project's modules are compiled once and their keys rarely recur, while the stdlib is shared by
     * every project in the process — so a caller that compiles project after project releases these
     * between them and keeps the part that pays for itself. Only the test suite calls this: a worker
     * runs hundreds of unrelated fixtures, where the CLI compiles one project and exits and the LSP
     * revisits the same files.
     */
    public static function releaseProjectCheckedModules(): void
    {
        foreach (self::$projectModules as $key => $_) {
            unset(self::$checkedModules[$key]);
        }

        self::$projectModules = [];
    }

    /**
     * Drop memoized prepared projects so the next prepare re-walks the project.
     */
    public static function clearPreparedProjects(): void
    {
        self::$preparedProjects = [];
    }

    /** Drop memoized checked modules (keyed by path+mtime+size+backend). */
    public static function clearCheckedModules(): void
    {
        self::$checkedModules = [];
        self::$projectModules = [];
    }
}
