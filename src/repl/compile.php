<?php declare(strict_types=1);

namespace Moggi\Repl;

use Moggi\Modules\PreparedProject;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Ast\Program;
use Moggi\Pipeline\PipelineArtifacts;
use Moggi\Pipeline\PipelineRequest;
use Moggi\Pipeline\PipelineStage;

use function Moggi\Backend\backendById;
use function Moggi\Modules\planEmission;
use function Moggi\Paths\moduleNameToPath;
use function Moggi\Pipeline\run as pipelineRun;
use function Moggi\Syntax\Parser\parse;

/**
 * Compiling the interactive module: the session prepares a typed module
 * ({@see prepareInteractive}), and this layer turns it into pipeline
 * artifacts for a requested stop stage.
 *
 * The PHP backend has its own emit path ({@see EvalRunner\emitInteractivePhp})
 * so that `:emit` resolves `require_once`s against the prebuilt dependency tree
 * instead of recompiling the library for every line.
 */

/**
 * The pipeline request for the interactive module's current typed context.
 *
 * `:ast` names the syntactic stage, and type checking annotates the tree it was
 * handed in place — so that stage is served by the parse of the module, taken
 * from the tokens and fixity `prepareInteractive` parsed it with, rather than by
 * the typed tree under another name.
 */
function interactivePipelineRequest(
    State $state,
    array $ctx,
    bool $optimize,
    ?PipelineStage $stopAt = null,
): PipelineRequest {
    return new PipelineRequest(
        filename: $state->interactivePath(),
        program: $stopAt === PipelineStage::Ast ? interactiveParseTree($state, $ctx) : null,
        typedProgram: $ctx['typedProgram'],
        optimize: $optimize,
        context: $state->context,
        importContext: $ctx['importContext'],
        emitOptions: emitOptionsForInteractive($state, $ctx),
    );
}

/**
 * The interactive module's own parse tree: the same tokens, imports and fixity
 * the module was parsed with, parsed again — the unit's stored `program` has
 * been typed through by the time a REPL command reads it.
 *
 * @param array{prepared: PreparedProject} $ctx
 */
function interactiveParseTree(State $state, array $ctx): Ast\Program
{
    $unit = $ctx['prepared']->units[$state->moduleName];

    return parse(
        $unit['tokens'],
        $unit['source'],
        $unit['path'],
        $unit['importedFixity'],
        $unit['localFixity'],
        $unit['imports'],
    );
}

/** Compile the prepared session module, stopping at `$stopAt`. */
function compileInteractiveModule(State $state, PipelineStage $stopAt, bool $optimize): PipelineArtifacts
{
    return pipelineRun(
        interactivePipelineRequest($state, prepareInteractive($state), $optimize, $stopAt),
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

    return pipelineRun(interactivePipelineRequest($state, $ctx, $optimize, $stopAt), $stopAt);
}

/**
 * @param array{prepared: PreparedProject, checked: Program, importContext: array<string, mixed>} $ctx
 * @return array<string, mixed>
 */
function emitOptionsForInteractive(State $state, array $ctx): array
{
    $unit = $ctx['prepared']->units[$state->moduleName];

    return [
        'namespace' => $unit['namespace'],
        'artifactModuleName' => $state->moduleName,
        // The pipeline has not produced the interactive IR yet, so the plan is
        // rendered without usage: every ABI fact, no file lines. The PHP emit
        // path renders it again once it has the IR, where pruning matters.
        'plan' => planEmission(
            $ctx['importContext']['interface'],
            $ctx['importContext']['plan'],
            null,
        ),
        'outputRelative' => moduleNameToPath($state->moduleName)
            . backendById($state->backend)->extension(),
    ];
}
