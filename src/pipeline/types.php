<?php declare(strict_types=1);

namespace Moggi\Pipeline;

use Moggi\IR\EntryPoint;
use Moggi\IR\Module as IrModule;
use Moggi\Syntax\Ast\Program;

/**
 * Why this compilation is happening. Controls entry validation, tree-shake
 * roots, and backend bootstrap — not which backend emits.
 */
enum CompilePurpose: string
{
    case Executable = 'executable';
    case Library = 'library';
    case Repl = 'repl';
}

/**
 * The module names this compilation treats as entry points.
 *
 * A package can declare several executables and test suites, so an entry point
 * cannot be identified by name alone — `Main` is only the default. The CLI sets
 * the declared entry (the file it was handed, or the descriptor's `main`) before
 * compiling; every other caller keeps the conventional `Main`.
 *
 * A static holder rather than a function local, so the getter and setter share
 * one slot; `null` means "never configured", which reads as `Main` alone.
 */
final class EntryPointPolicy
{
    public static ?array $modules = null;
}

/**
 * @param ?list<string> $modules entry module names, or null for the `Main` default
 */
function setEntryModules(?array $modules): void
{
    EntryPointPolicy::$modules = $modules === null ? null : \array_fill_keys($modules, true);
}

/** @return array<string, true> */
function entryModules(): array
{
    return EntryPointPolicy::$modules ?? ['Main' => true];
}

/** Whether `$module` is one of this compilation's entry points. */
function isEntryModule(?string $module): bool
{
    return $module !== null && isset(entryModules()[$module]);
}

/**
 * The purpose of compiling `$module`: an entry module is the executable, every
 * other module is a library. Deriving it in one place keeps the entry-point
 * policy from drifting between the single-file, project and focus paths.
 */
function entryPurpose(?string $module): CompilePurpose
{
    return isEntryModule($module) ? CompilePurpose::Executable : CompilePurpose::Library;
}

/** How far Pipeline::run should go before returning artifacts. */
enum PipelineStage: string
{
    case Ast = 'ast';
    case TypedAst = 'typed-ast';
    case Ir = 'ir';
    case IrOpt = 'ir-opt';
    case Emit = 'emit';
}

/**
 * Inputs for a single-module (or standalone) pipeline run.
 *
 * When `$typedProgram` is set (prepared-project path), parse and typecheck are
 * skipped and lowering starts from that typed AST.
 *
 * @param array<string, mixed> $importContext
 * @param array<string, mixed> $emitOptions
 */
final class PipelineRequest
{
    public function __construct(
        public CompilePurpose $purpose = CompilePurpose::Executable,
        public string $source = '',
        public string $filename = '',
        public ?Program $program = null,
        public ?Program $typedProgram = null,
        public bool $optimize = true,
        public ?string $backend = null,
        public array $importContext = [],
        public array $emitOptions = [],
    ) {
    }
}

/**
 * Stage artifacts produced by Pipeline::run. Null means the stage was not reached.
 *
 * @param string|array<string, string>|null $emit
 */
final class PipelineArtifacts
{
    public function __construct(
        public ?Program $ast = null,
        public ?Program $typedAst = null,
        public ?IrModule $ir = null,
        public ?IrModule $irOpt = null,
        public string|array|null $emit = null,
        public ?EntryPoint $entry = null,
        public CompilePurpose $purpose = CompilePurpose::Executable,
    ) {
    }
}
