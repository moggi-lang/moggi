<?php declare(strict_types=1);

namespace Moggi\Modules;

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
     * @param array<string, array<string, mixed>> $importContexts
     */
    public function __construct(
        public readonly string $rootPrefix,
        public readonly array $units,
        public readonly array $checked,
        public readonly array $importContexts,
    ) {
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
    /** @var array<string, PreparedProject> */
    private static array $preparedProjects = [];

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
        return self::$preparedProjects[$key] ?? null;
    }

    public static function rememberPreparedProject(string $key, PreparedProject $project): void
    {
        self::$preparedProjects[$key] = $project;
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
