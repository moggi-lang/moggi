<?php declare(strict_types=1);

namespace Moggi\Repl;

use Moggi\Modules\PreparedProject;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Pipeline\PipelineArtifacts;
use Moggi\Pipeline\PipelineRequest;
use Moggi\Pipeline\PipelineStage;
use Moggi\Syntax\Ast\Program;

use function Moggi\Backend\backendById;
use function Moggi\Paths\moduleNameToPath;
use function Moggi\Pipeline\run as pipelineRun;

/**
 * Compiling the interactive module: the session prepares a typed module
 * ({@see prepareInteractive}), and this layer turns it into pipeline
 * artifacts for a requested stop stage.
 *
 * The PHP backend has its own emit path ({@see EvalRunner\emitInteractivePhp})
 * so that `:emit` resolves `require_once`s against the prebuilt dependency tree
 * instead of recompiling the library for every line.
 */

/** The pipeline request for the interactive module's current typed context. */
function interactivePipelineRequest(State $state, array $ctx, bool $optimize): PipelineRequest
{
    return new PipelineRequest(
        purpose: CompilePurpose::Repl,
        filename: $state->interactivePath(),
        typedProgram: $ctx['checked'],
        optimize: $optimize,
        backend: $state->backend,
        importContext: $ctx['importContext'],
        emitOptions: emitOptionsForInteractive($state, $ctx),
    );
}

/** Compile the prepared session module, stopping at `$stopAt`. */
function compileInteractiveModule(State $state, PipelineStage $stopAt, bool $optimize): PipelineArtifacts
{
    return pipelineRun(
        interactivePipelineRequest($state, prepareInteractive($state), $optimize),
        $stopAt,
    );
}

/** Compile an already-prepared context, stopping at `$stopAt`. */
function compileInteractiveInspect(State $state, PipelineStage $stopAt, bool $optimize): PipelineArtifacts
{
    return compileFromInteractiveCtx($state, prepareInteractive($state), $stopAt, $optimize);
}

/**
 * @param array{prepared: PreparedProject, checked: Program, importContext: array<string, mixed>} $ctx
 */
function compileFromInteractiveCtx(
    State $state,
    array $ctx,
    PipelineStage $stopAt,
    bool $optimize,
): PipelineArtifacts {
    if ($state->backend === 'php' && $stopAt === PipelineStage::Emit) {
        $depsRoot = EvalRunner\ensurePhpDeps($state);

        return EvalRunner\emitInteractivePhp(
            $state,
            $ctx,
            $ctx['prepared']->units[$state->moduleName]['namespace'] ?? 'Interactive',
            $depsRoot,
            $optimize,
        );
    }

    return pipelineRun(interactivePipelineRequest($state, $ctx, $optimize), $stopAt);
}

/**
 * @param array{prepared: PreparedProject, checked: Program, importContext: array<string, mixed>} $ctx
 * @return array<string, mixed>
 */
function emitOptionsForInteractive(State $state, array $ctx): array
{
    $unit = $ctx['prepared']->units[$state->moduleName];
    $codegen = $ctx['importContext']['codegen'] ?? [];

    return [
        'namespace' => $unit['namespace'],
        'moduleName' => $state->moduleName,
        'imports' => $codegen,
        'outputRelative' => moduleNameToPath($state->moduleName)
            . backendById($state->backend)->extension(),
        'externalFns' => $codegen['externalFns'] ?? [],
        'externalFnRuntimeArity' => $ctx['importContext']['externalFnRuntimeArity'] ?? [],
    ];
}
