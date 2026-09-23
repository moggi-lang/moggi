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
