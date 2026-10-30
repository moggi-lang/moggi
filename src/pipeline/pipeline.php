<?php declare(strict_types=1);

namespace Moggi\Pipeline;

use function Moggi\Backend\codegenWith;
use function Moggi\Backend\Jvm\Foreign\jvmInterfaceNamesFromPrograms;
use function Moggi\IR\lower;
use function Moggi\IR\Validate\validateCallableLayouts;
use function Moggi\IR\Validate\validateModule;
use function Moggi\IR\Visit\collectIrCodegenUsage;
use function Moggi\Modules\moduleNameToNamespace;
use function Moggi\Modules\planEmission;
use function Moggi\Modules\standaloneImportContext;
use function Moggi\Optimize\optimize;
use function Moggi\Optimize\PassCheckpoints\enabled;
use function Moggi\Optimize\Partial\legalizeCallables;
use function Moggi\Semantics\Effects\checkAndNormalize;

use Moggi\IR\Module;
use Moggi\Modules\EmitPlan;
use Moggi\Modules\ImportInterface;
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
    $artifacts = new PipelineArtifacts(
        purpose: $request->context->purposeFor(
            $request->typedProgram?->module ?? $request->program?->module,
        ),
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
            $request->importContext
                ?? standaloneImportContext($program, $request->context->backendId),
            $request->context->purposeFor($program->module),
            entryModules: $request->context->entries,
            backendId: $request->context->backendId,
        );
        $artifacts->typedAst = $typed;

        if ($stopAt === PipelineStage::TypedAst) {
            return $artifacts;
        }
    }

    $checked = $artifacts->typedAst;
    $interface = $checked->interface;
    if ($interface === null) {
        throw new \LogicException(
            "the checked program `{$checked->module}` carries no import interface; a checked "
            . 'program without one is a producer bug, not a module that imports nothing',
        );
    }
    $ir = lower($checked, $interface->data(), $request->filename);
    $artifacts->ir = $ir;
    $artifacts->entry = $ir->entry;

    if (enabled()) {
        validateModule($ir, 'post-lower');
    }

    if ($stopAt === PipelineStage::Ir) {
        return $artifacts;
    }

    if ($request->optimize) {
        $irOpt = optimize($ir);
    } else {
        $irOpt = new Module(
            legalizeCallables($ir->functions),
            $ir->data,
            $ir->instanceEvidence,
            $ir->entry,
            $ir->moduleName,
            $ir->sourceFile,
        );
    }
    $artifacts->irOpt = $irOpt;
    $artifacts->entry = $irOpt->entry;

    validateModule($irOpt, 'post-opt');
    $layoutInterface = $artifacts->typedAst->interface;
    if ($layoutInterface === null) {
        throw new \LogicException(
            'the post-legalize layout check has no import interface; the checked program must '
            . 'carry the arities of the entries it calls',
        );
    }
    validateCallableLayouts($irOpt, 'post-legalize', $layoutInterface->callableArity());

    if ($stopAt === PipelineStage::IrOpt) {
        return $artifacts;
    }

    $emitOptions = $request->emitOptions;
    if ($emitOptions === []) {
        $emitOptions = defaultEmitOptions($artifacts->typedAst, $request->filename, $request->context);
    } elseif (($emitOptions['plan'] ?? null) instanceof EmitPlan) {
        $fromOutput = $emitOptions['outputRelative'] ?? null;
        $emitOptions['plan'] = planEmission(
            $interface,
            $emitOptions['plan'],
            $irOpt,
            collectIrCodegenUsage($irOpt)['callees'],
            \is_string($fromOutput) ? $fromOutput : null,
        );
    }

    $artifacts->emit = codegenWith($request->context->backend, $irOpt, $request->filename, $emitOptions);

    return $artifacts;
}

/** @return array{outputRelative: string, moduleName?: string, namespace?: string, plan: EmitPlan} */
function defaultEmitOptions(Program $ast, string $filename, CompilationContext $context): array
{
    $emitOptions = [
        'outputRelative' => \preg_replace(
            '/\.mog$/',
            $context->backend->extension(),
            \str_replace('\\', '/', $filename),
        ) ?? $filename,
        'artifactModuleName' => $ast->module ?? '',
    ];
    $moduleName = $ast->module;
    if ($moduleName !== null) {
        $emitOptions['namespace'] = moduleNameToNamespace($moduleName);
        $emitOptions['artifactModuleName'] = $moduleName;
    }

    if ($context->backendId === 'jvm') {
        $emitOptions['foreignInterfaces'] = jvmInterfaceNamesFromPrograms([$ast]);
    }

    if ($ast->interface === null) {
        throw new \LogicException(
            "the program `{$ast->module}` has no import interface to render its emission plan from",
        );
    }

    $emitOptions['plan'] = EmitPlan::forTarget($context->backendId, $emitOptions['outputRelative']);

    return $emitOptions;
}
