<?php declare(strict_types=1);

namespace Moggi\Optimize;

use Moggi\IR\Block;
use Moggi\IR\FunctionDecl;
use Moggi\IR\Module;
use Moggi\IR\Stmt;
use Moggi\Optimize\PassCheckpoints;

use function Moggi\IR\entryFromFunctions;
use function Moggi\IR\Callables\captureMeta;
use function Moggi\IR\Callables\workerDeclarations;
use function Moggi\Optimize\CaseFold\foldKnownConstructorsBlock;
use function Moggi\Optimize\CaseFold\foldKnownMatchesInItems;
use function Moggi\Optimize\Dict\indexEvidenceByName;
use function Moggi\Optimize\Dict\specializeDictCallsFunctions;
use function Moggi\Optimize\Effects\annotateIoEffects;
use function Moggi\Optimize\Effects\effectfulCalleeNames;
use function Moggi\Optimize\FoldIntrinsic\foldIntrinsicsItems;
use function Moggi\Optimize\GlobalPass\clearEffectfulCallees;
use function Moggi\Optimize\GlobalPass\cseBlock;
use function Moggi\Optimize\GlobalPass\dceBlock;
use function Moggi\Optimize\GlobalPass\propagateCopiesBlock;
use function Moggi\Optimize\GlobalPass\setEffectfulCallees;
use function Moggi\Optimize\GlobalPass\setPropagateCopiesLambdaBodies;
use function Moggi\Optimize\Interproc\fuseAppliedLambdas;
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
use function Moggi\Optimize\Partial\specializePartialReturnFunction;
use function Moggi\Optimize\Support\indexCapturedFunctions;
use function Moggi\Optimize\Tco\eliminateTailRecursion;

/** Runs the stage sequence, each stage checked under its own checkpoint label. */
function optimize(Module $module): Module
{
    PassCheckpoints\enterOptimize();
    $result = null;

    try {
        $moduleName = $module->moduleName;
        $functions = $module->functions;
        $instanceEvidence = $module->instanceEvidence;
        $evidenceByName = indexEvidenceByName($instanceEvidence);

        $functions = PassCheckpoints\runOnFunctions(
            'early-folds',
            $module,
            $functions,
            static function (array $fns) use ($moduleName): array {
                setPropagateCopiesLambdaBodies($fns);
                setEffectfulCallees(effectfulCalleeNames($fns, $moduleName));
                $optimized = \array_map(optimizeFunctionEarly(...), $fns);
                clearEffectfulCallees();

                return $optimized;
            },
            true,
        );

        $functions = PassCheckpoints\runOnFunctions(
            'fuse-applied-lambdas',
            $module,
            $functions,
            static fn (array $fns): array => fuseAppliedLambdas($fns, $moduleName),
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
            static fn (array $fns): array => inlineFunctions($fns, $moduleName),
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
            static function (array $fns) use ($moduleName): array {
                setPropagateCopiesLambdaBodies($fns);
                setEffectfulCallees(effectfulCalleeNames($fns, $moduleName));
                $optimized = \array_map(optimizeFunctionAfterInline(...), $fns);
                clearEffectfulCallees();

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
            static function (array $fns): array {
                $captureMeta = captureMeta(indexCapturedFunctions($fns));

                return \array_map(
                    static fn (FunctionDecl $function): FunctionDecl => specializePartialReturnFunction(
                        $function,
                        $captureMeta[$function->name]['captures'] ?? [],
                    ),
                    $fns,
                );
            },
            false,
        );
        if (shouldRunSecondPartialFold($functions)) {
            $functions = PassCheckpoints\runOnFunctions(
                'fold-partial-apply-2',
                $module,
                $functions,
                static fn (array $fns): array => foldPartialApply($fns, $moduleName),
                false,
            );
        }

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

        setPropagateCopiesLambdaBodies($functions);
        setEffectfulCallees(effectfulCalleeNames($functions, $moduleName));
        $workers = workerDeclarations($module);
        $functions = PassCheckpoints\runOnFunctions(
            'final-folds',
            $module,
            $functions,
            static fn (array $fns): array => \array_map(
                static fn (FunctionDecl $function): FunctionDecl => optimizeFunctionFinal($function, $workers),
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
        setEffectfulCallees(effectfulCalleeNames($functions, $moduleName));
        $functions = PassCheckpoints\runOnFunctions(
            'final-dce',
            $module,
            $functions,
            static fn (array $fns): array => \array_map(
                static fn (FunctionDecl $function): FunctionDecl => $function->withBody(
                    $function->ioStraightLine ? $function->body : dceBlock($function->body),
                ),
                $fns,
            ),
            false,
        );
        clearEffectfulCallees();

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
    }

    return $result;
}

/**
 * @param list<Stmt> $items
 * @param callable(Stmt): Stmt $fn
 * @return list<Stmt>
 */
function mapStmtItems(array $items, callable $fn): array
{
    foreach ($items as $i => $item) {
        $mapped = $fn($item);
        if ($mapped !== $item) {
            $items[$i] = $mapped;
        }
    }

    return $items;
}

/** @param list<FunctionDecl> $functions */
function shouldRunSecondPartialFold(array $functions): bool
{
    $stmtCount = 0;
    foreach ($functions as $function) {
        $stmtCount += \count($function->body->items);
    }

    return \count($functions) > 2 || $stmtCount > 24;
}

function optimizeFunctionEarly(FunctionDecl $function): FunctionDecl
{
    if ($function->ioStraightLine) {
        return $function;
    }

    $body = optimizeBlock($function->body);
    if (functionReturnsIo($function->type)) {
        return $function->withBody($body);
    }

    $body = cseBlock($body);
    $body = dceBlock($body);
    $body = eliminateTailRecursion($function->name, $function->params, $body, $function->kind);
    $body = new Block(foldMatchReturn($body->items));

    return $function->withBody($body);
}

function optimizeFunctionAfterInline(FunctionDecl $function): FunctionDecl
{
    if ($function->ioStraightLine) {
        return $function->withBody(new Block(foldKnownMatchesInItems($function->body->items)));
    }

    $body = foldKnownConstructorsBlock($function->body);
    $body = optimizeBlock($body);
    $body = foldKnownConstructorsBlock($body);
    $body = cseBlock($body);
    $body = propagateCopiesBlock($body);
    $body = new Block(foldIntrinsicsItems($body->items));
    $body = dceBlock($body);
    $body = new Block(foldTailReturn($body->items));
    $body = new Block(foldMatchReturn($body->items));

    return $function->withBody($body);
}

/**
 * @param array<string, FunctionDecl> $workers the module's workers, so a run stays a statement
 */
function optimizeFunctionFinal(FunctionDecl $function, array $workers): FunctionDecl
{
    if ($function->ioStraightLine) {
        $body = propagateCopiesBlock($function->body);
        $items = foldSingleUseCallResults(foldTailReturn($body->items), $workers);

        return $function->withBody(new Block($items));
    }

    $body = propagateCopiesBlock($function->body);
    $body = new Block(foldIntrinsicsItems($body->items));
    $body = dceBlock($body);
    $body = new Block(foldTailReturn($body->items));

    return $function->withBody($body);
}
