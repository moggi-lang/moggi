<?php declare(strict_types=1);

namespace Moggi\Pipeline;

use function Moggi\Backend\codegen;

use function Moggi\Backend\currentBackend;
use function Moggi\Backend\setCompileBackend;
use function Moggi\IR\lower;
use function Moggi\Modules\moduleNameToNamespace;
use function Moggi\Optimize\optimize;
use function Moggi\Semantics\Effects\checkAndNormalize;

use Moggi\Syntax\Ast\Program;

use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

/**
 * Purpose-driven compile driver shared by CLI, tests, and (later) the REPL.
 *
 * Stages: parse → typed → ir → opt-ir → emit. `stopAt` cuts the pipeline early.
 */
function run(PipelineRequest $request, PipelineStage $stopAt = PipelineStage::Emit): PipelineArtifacts
{
    if ($request->backend !== null) {
        setCompileBackend($request->backend);
    }

    $artifacts = new PipelineArtifacts(
        purpose: $request->purpose,
    );

    if ($request->typedProgram !== null) {
        $typed = $request->typedProgram;
        $artifacts->ast = $request->program;
        $artifacts->typedAst = $typed;
        if ($stopAt === PipelineStage::Ast || $stopAt === PipelineStage::TypedAst) {
            return $artifacts;
        }
    } else {
        $program = $request->program;
        if ($program === null) {
            $program = parse(lex($request->source, $request->filename), $request->source, $request->filename);
        }

        $artifacts->ast = $program;
        if ($stopAt === PipelineStage::Ast) {
            return $artifacts;
        }

        $typed = checkAndNormalize(
            $program,
            $request->source,
            $request->filename,
            $request->importContext,
            $request->purpose,
        );
        $artifacts->typedAst = $typed;

        if ($stopAt === PipelineStage::TypedAst) {
            return $artifacts;
        }
    }

    $ir = lower($artifacts->typedAst, $request->importContext['data'] ?? [], $request->filename);
    $artifacts->ir = $ir;
    $artifacts->entry = $ir->entry;

    if ($stopAt === PipelineStage::Ir) {
        return $artifacts;
    }

    if ($request->optimize) {
        $irOpt = optimize($ir);
    } else {
        $irOpt = $ir;
    }
    $artifacts->irOpt = $irOpt;
    $artifacts->entry = $irOpt->entry;

    if ($stopAt === PipelineStage::IrOpt) {
        return $artifacts;
    }

    $emitOptions = $request->emitOptions;
    if ($emitOptions === []) {
        $emitOptions = defaultEmitOptions($artifacts->typedAst, $request->filename);
    }

    $artifacts->emit = codegen($irOpt, $request->filename, $emitOptions);

    return $artifacts;
}

/** @return array{outputRelative: string, moduleName?: string, namespace?: string} */
function defaultEmitOptions(Program $ast, string $filename): array
{
    $emitOptions = [
        'outputRelative' => \preg_replace(
            '/\.mog$/',
            currentBackend()->extension(),
            \str_replace('\\', '/', $filename),
        ) ?? $filename,
        'artifactModuleName' => $ast->module ?? '',
    ];
    $moduleName = $ast->module;
    if ($moduleName !== null) {
        $emitOptions['namespace'] = moduleNameToNamespace($moduleName);
        $emitOptions['artifactModuleName'] = $moduleName;
    }

    return $emitOptions;
}
