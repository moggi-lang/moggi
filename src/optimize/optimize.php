<?php declare(strict_types=1);

namespace Moggi\Optimize;

use Moggi\IR\Block;
use Moggi\IR\FunctionDecl;
use Moggi\IR\Module;
use Moggi\IR\Stmt;
use Moggi\Optimize\PassCheckpoints;

use function Moggi\IR\entryFromFunctions;
use function Moggi\IR\Callables\table;
use function Moggi\IR\Callables\workerDeclarations;
use function Moggi\Optimize\CaseFold\foldKnownConstructorsBlock;
use function Moggi\Optimize\CaseFold\foldKnownMatchesInItems;
use function Moggi\Optimize\Dict\indexEvidenceByName;
use function Moggi\Optimize\Dict\specializeDictCallsFunctions;
use Moggi\Optimize\Effects\Analysis;
use Moggi\Optimize\Effects\Model;
use Moggi\Optimize\Facts\Facts;

use function Moggi\Optimize\Effects\annotateIoEffects;
use function Moggi\Optimize\FoldIntrinsic\foldIntrinsicsItems;
use function Moggi\Optimize\GlobalPass\cseBlock;
use function Moggi\Optimize\GlobalPass\dceBlock;
use function Moggi\Optimize\GlobalPass\propagateCopiesBlock;
use function Moggi\Optimize\GlobalPass\publishLiftedCaptureNeeds;
use function Moggi\Optimize\Interproc\inlineFunctions;
use function Moggi\Optimize\Intrinsic\eliminateIntrinsicWrappers;
use function Moggi\Optimize\IoSpecialize\foldIoActionBoxes;
use function Moggi\Optimize\IoSpecialize\functionReturnsIo;
use function Moggi\Optimize\IoSpecialize\recomputeIoStraightLineAll;
use function Moggi\Optimize\Local\foldMatchReturn;
use function Moggi\Optimize\Local\foldSingleUseCallResults;
use function Moggi\Optimize\Local\foldTailReturn;
use function Moggi\Optimize\Local\optimizeBlock;
use function Moggi\Optimize\Partial\legalizeCallables;
use function Moggi\Optimize\Partial\foldPartialApply;
use function Moggi\Optimize\Partial\normalizeKnownApplies;
use function Moggi\Optimize\Partial\publishedCaptures;
use function Moggi\Optimize\Partial\specializePartialReturnFunction;
use function Moggi\Optimize\Tco\eliminateTailRecursion;

/**
 * Publishes the facts the passes below read for this function list: the callable table and the locals a
 * reference to each lifted callable keeps alive. Both are rebuilt whenever the list changes underneath
 * them, so no pass keeps its own copy.
 *
 * @param list<FunctionDecl> $functions
 */
function publishModuleFacts(array $functions, string $moduleName, Facts $facts): void
{
    $facts->publishCallableTable(table($functions, $moduleName));
    publishLiftedCaptureNeeds($functions, $facts);
}

/** Runs the stage sequence, each stage checked under its own checkpoint label. */
function optimize(Module $module, ?Facts $owner = null): Module
{
    PassCheckpoints\enterOptimize();
    $previous = $owner;
    $facts = $previous?->child() ?? new Facts();
    $result = null;

    try {
        $moduleName = $module->moduleName;
        $functions = $module->functions;
        $instanceEvidence = $module->instanceEvidence;
        $evidenceByName = indexEvidenceByName($instanceEvidence);
        $facts->publishModuleEvidence($moduleName, $instanceEvidence);
        $facts->publishFunctions($functions);
        $analysis = new Analysis();

        $functions = PassCheckpoints\runOnFunctions(
            'early-folds',
            $module,
            $functions,
            static function (array $fns) use ($moduleName, $analysis, $facts): array {
                publishModuleFacts($fns, $moduleName, $facts);
                $effects = $analysis->modelFor($fns, $moduleName, $facts);
                $optimized = \array_map(
                    static fn (FunctionDecl $function): FunctionDecl => optimizeFunctionEarly($function, $effects, $facts),
                    $fns,
                );

                return $optimized;
            },
            true,
        );

        $functions = PassCheckpoints\runOnFunctions(
            'specialize-dict-calls-1',
            $module,
            $functions,
            static fn (array $fns): array => specializeDictCallsFunctions($fns, $evidenceByName),
            true,
        );
        $functions = PassCheckpoints\runOnFunctions(
            'inline-functions',
            $module,
            $functions,
            static fn (array $fns): array => inlineFunctions($fns, $facts, $moduleName),
            true,
        );
        $functions = PassCheckpoints\runOnFunctions(
            'specialize-dict-calls-2',
            $module,
            $functions,
            static fn (array $fns): array => specializeDictCallsFunctions($fns, $evidenceByName),
            true,
        );
        $functions = PassCheckpoints\runOnFunctions(
            'after-inline-folds',
            $module,
            $functions,
            static function (array $fns) use ($moduleName, $analysis, $facts): array {
                publishModuleFacts($fns, $moduleName, $facts);
                $effects = $analysis->modelFor($fns, $moduleName, $facts);
                $optimized = \array_map(
                    static fn (FunctionDecl $function): FunctionDecl => optimizeFunctionAfterInline($function, $effects, $facts),
                    $fns,
                );

                return $optimized;
            },
            true,
        );
        $functions = PassCheckpoints\runOnFunctions(
            'normalize-known-applies',
            $module,
            $functions,
            static fn (array $fns): array => normalizeKnownApplies($fns, $moduleName),
            true,
        );
        $functions = PassCheckpoints\runOnFunctions(
            'legalize-callables',
            $module,
            $functions,
            legalizeCallables(...),
            false,
        );
        $functions = PassCheckpoints\runOnFunctions(
            'fold-partial-apply',
            $module,
            $functions,
            static fn (array $fns): array => foldPartialApply($fns, $moduleName),
            false,
        );
        $functions = PassCheckpoints\runOnFunctions(
            'specialize-partial-return',
            $module,
            $functions,
            static function (array $fns) use ($moduleName): array {
                $table = table($fns, $moduleName);

                return \array_map(
                    static fn (FunctionDecl $function): FunctionDecl => specializePartialReturnFunction(
                        $function,
                        publishedCaptures($table, $function->name),
                        $table,
                    ),
                    $fns,
                );
            },
            false,
        );
        $functions = PassCheckpoints\runOnFunctions(
            'fold-partial-apply-2',
            $module,
            $functions,
            static fn (array $fns): array => foldPartialApply($fns, $moduleName),
            false,
        );

        $module = new Module($functions, $module->data, $instanceEvidence, $module->entry, $moduleName, $module->sourceFile);
        $module = PassCheckpoints\runOnModule('eliminate-intrinsic-wrappers', $module, eliminateIntrinsicWrappers(...));
        $module = PassCheckpoints\runOnModule('fold-io-action-boxes', $module, foldIoActionBoxes(...));
        $functions = PassCheckpoints\runOnFunctions(
            'recompute-io-straight-line',
            $module,
            $module->functions,
            recomputeIoStraightLineAll(...),
            false,
        );
        $module = new Module($functions, $module->data, $module->instanceEvidence, $module->entry, $module->moduleName, $module->sourceFile);
        $functions = $module->functions;

        publishModuleFacts($functions, $moduleName, $facts);
        $workers = workerDeclarations($module);
        $effects = $analysis->modelFor($functions, $moduleName, $facts);
        $functions = PassCheckpoints\runOnFunctions(
            'final-folds',
            $module,
            $functions,
            static fn (array $fns): array => \array_map(
                static fn (FunctionDecl $function): FunctionDecl => optimizeFunctionFinal($function, $workers, $effects, $facts),
                $fns,
            ),
            false,
        );
        $functions = PassCheckpoints\runOnFunctions(
            'annotate-io-effects',
            $module,
            $functions,
            static fn (array $fns): array => annotateIoEffects($fns, $moduleName),
            false,
        );
        $effects = $analysis->modelFor($functions, $moduleName, $facts);
        $functions = PassCheckpoints\runOnFunctions(
            'final-dce',
            $module,
            $functions,
            static fn (array $fns): array => \array_map(
                static fn (FunctionDecl $function): FunctionDecl => $function->withBody(
                    $function->shape->ioStraightLine ? $function->body : dceBlock($function->body, $effects),
                ),
                $fns,
            ),
            false,
        );

        $functions = PassCheckpoints\runOnFunctions(
            'legalize-callables-final',
            $module,
            $functions,
            legalizeCallables(...),
            false,
        );

        $result = new Module($functions, $module->data, $instanceEvidence, entryFromFunctions($functions), $moduleName, $module->sourceFile);
    } finally {
        PassCheckpoints\leaveOptimize();
        $previous?->absorb($facts);
    }

    return $result;
}

function optimizeFunctionEarly(FunctionDecl $function, Model $effects, Facts $facts): FunctionDecl
{
    if ($function->shape->ioStraightLine) {
        return $function;
    }

    $body = optimizeBlock($function->body, $facts);
    if (functionReturnsIo($function->type)) {
        return $function->withBody($body);
    }

    $body = cseBlock($body, $effects);
    $body = dceBlock($body, $effects);
    $body = eliminateTailRecursion($function->name, $function->params, $body, $function->shape->kind);
    $body = new Block(foldMatchReturn($body->items));

    return $function->withBody($body);
}

function optimizeFunctionAfterInline(FunctionDecl $function, Model $effects, Facts $facts): FunctionDecl
{
    if ($function->shape->ioStraightLine) {
        return $function->withBody(new Block(foldKnownMatchesInItems($function->body->items, $facts)));
    }

    $body = foldKnownConstructorsBlock($function->body, $facts);
    $body = optimizeBlock($body, $facts);
    $body = foldKnownConstructorsBlock($body, $facts);
    $body = cseBlock($body, $effects);
    $body = propagateCopiesBlock($body, $facts);
    $body = new Block(foldIntrinsicsItems($body->items));
    $body = dceBlock($body, $effects);
    $body = new Block(foldTailReturn($body->items));
    $body = new Block(foldMatchReturn($body->items));

    return $function->withBody($body);
}

/**
 * @param array<string, FunctionDecl> $workers the module's workers, so a run stays a statement
 */
function optimizeFunctionFinal(FunctionDecl $function, array $workers, Model $effects, Facts $facts): FunctionDecl
{
    if ($function->shape->ioStraightLine) {
        $body = propagateCopiesBlock($function->body, $facts);
        $items = foldSingleUseCallResults(foldTailReturn($body->items), $workers);

        return $function->withBody(new Block($items));
    }

    $body = propagateCopiesBlock($function->body, $facts);
    $body = new Block(foldIntrinsicsItems($body->items));
    $body = dceBlock($body, $effects);
    $body = new Block(foldTailReturn($body->items));

    return $function->withBody($body);
}
