<?php declare(strict_types=1);

namespace Moggi\Pipeline;

use Moggi\Syntax\Ast\Program;

use function Moggi\Backend\codegen;
use function Moggi\Backend\currentBackend;
use function Moggi\Backend\setCompileBackend;
use function Moggi\IR\Dump\dump as dumpIr;
use function Moggi\IR\lower;
use function Moggi\Modules\moduleNameToNamespace;
use function Moggi\Optimize\optimize;
use function Moggi\Semantics\Effects\checkAndNormalize;
use function Moggi\Syntax\Ast\dump as dumpAst;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

/**
 * Purpose-driven compile driver shared by CLI, tests, and (later) the REPL.
 *
 * Stages: parse → typed → ir → ir-opt → emit. `stopAt` cuts the pipeline early.
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
        $artifacts->ast = $request->program ?? $typed;
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

/**
 * Map legacy CLI mode strings onto PipelineStage.
 *
 * Tokens, the parse tree and the typed tree never reach the pipeline: `compile()` answers those
 * before it runs (see `dumpSourceStage`).
 */
function stageFromOutputMode(string $mode): PipelineStage
{
    return match ($mode) {
        'ast' => PipelineStage::Ast,
        'typed-ast' => PipelineStage::TypedAst,
        'ir' => PipelineStage::Ir,
        'opt-ir' => PipelineStage::IrOpt,
        'php', 'emit' => PipelineStage::Emit,
        default => throw new \InvalidArgumentException("unknown output mode `{$mode}`"),
    };
}

/** Format pipeline artifacts for `compile()`'s string/array return values. */
function selectOutput(PipelineArtifacts $artifacts, string $mode): string|array
{
    $mode = match ($mode) {
        'php', 'emit', 'ir', 'opt-ir', 'ast', 'typed-ast' => $mode,
        default => throw new \InvalidArgumentException("unknown output mode `{$mode}`"),
    };

    return match ($mode) {
        'ast' => dumpAst($artifacts->ast ?? throw new \RuntimeException('missing AST')),
        'typed-ast' => dumpAst($artifacts->typedAst ?? throw new \RuntimeException('missing typed AST')),
        'ir' => dumpIr($artifacts->ir ?? throw new \RuntimeException('missing IR')),
        'opt-ir' => dumpIr($artifacts->irOpt ?? throw new \RuntimeException('missing optimized IR')),
        'php', 'emit' => $artifacts->emit ?? throw new \RuntimeException('missing emit'),
    };
}

/** @return array{outputRelative: string, moduleName?: string, namespace?: string} */
function defaultEmitOptions(Program $ast, string $filename): array
{
    $emitOptions = [
        'outputRelative' => preg_replace(
            '/\.mog$/',
            currentBackend()->extension(),
            \str_replace('\\', '/', $filename),
        ) ?? $filename,
        'moduleName' => $ast->module ?? '',
    ];
    $moduleName = $ast->module;
    if ($moduleName !== null) {
        $emitOptions['namespace'] = moduleNameToNamespace($moduleName);
        $emitOptions['moduleName'] = $moduleName;
    }

    return $emitOptions;
}
