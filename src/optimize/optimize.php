<?php declare(strict_types=1);

namespace Moggi\Optimize;

use Moggi\IR\Block;
use Moggi\IR\FunctionDecl;
use Moggi\IR\Module;
use Moggi\IR\Stmt;

use function Moggi\IR\entryFromFunctions;
use function Moggi\Optimize\CaseFold\foldKnownConstructorsBlock;
use function Moggi\Optimize\CaseFold\foldKnownMatchesInItems;
use function Moggi\Optimize\Dict\indexEvidenceByName;
use function Moggi\Optimize\Dict\specializeDictCallsFunctions;
use function Moggi\Optimize\Effects\annotateIoEffects;
use function Moggi\Optimize\Effects\hostEffectFunctionNames;
use function Moggi\Optimize\FoldIntrinsic\foldIntrinsicsItems;
use function Moggi\Optimize\GlobalPass\clearHostEffectFunctions;
use function Moggi\Optimize\GlobalPass\cseBlock;
use function Moggi\Optimize\GlobalPass\dceBlock;
use function Moggi\Optimize\GlobalPass\propagateCopiesBlock;
use function Moggi\Optimize\GlobalPass\setHostEffectFunctions;
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
use function Moggi\Optimize\Partial\bindLambdaCaptures;
use function Moggi\Optimize\Partial\foldPartialApply;
use function Moggi\Optimize\Partial\normalizeKnownApplies;
use function Moggi\Optimize\Partial\specializePartialReturnFunction;
use function Moggi\Optimize\Support\buildLambdaMeta;
use function Moggi\Optimize\Support\indexCapturedFunctions;
use function Moggi\Optimize\Tco\eliminateTailRecursion;

function optimize(Module $module): Module
{
    $functions = $module->functions;
    $instanceEvidence = $module->instanceEvidence;
    $evidenceByName = indexEvidenceByName($instanceEvidence);

    setPropagateCopiesLambdaBodies($functions);
    setHostEffectFunctions(hostEffectFunctionNames($functions, $module->moduleName));
    $functions = \array_map(optimizeFunctionEarly(...), $functions);
    clearHostEffectFunctions();

    $functions = fuseAppliedLambdas($functions, $module->moduleName);
    // Specialize known local dict_calls before inlining so match-wrappers
    // like Generic from/to become direct Call targets.
    $functions = specializeDictCallsFunctions($functions, $evidenceByName);
    $functions = inlineFunctions($functions, $module->moduleName);
    $functions = specializeDictCallsFunctions($functions, $evidenceByName);
    setPropagateCopiesLambdaBodies($functions);
    setHostEffectFunctions(hostEffectFunctionNames($functions, $module->moduleName));
    $functions = \array_map(optimizeFunctionAfterInline(...), $functions);
    clearHostEffectFunctions();
    // Canonicalize applies whose callee arity is statically known, so the
    // partial fold below can turn them into direct calls instead of runtime
    // `__apply`. Runs after inlining, which is what creates those shapes.
    $functions = normalizeKnownApplies($functions, $module->moduleName);
    // Rewrite `@λN` uses into `partial λN(captures…)` so specialize can burn captures; a bare
    // `@Module::λN` would make foldr return `__partial` thunks.
    $functions = bindLambdaCaptures($functions);
    $functions = foldPartialApply($functions, $module->moduleName);
    $captureMeta = buildLambdaMeta(indexCapturedFunctions($functions));
    $functions = \array_map(
        static fn (FunctionDecl $function): FunctionDecl => specializePartialReturnFunction(
            $function,
            $captureMeta[$function->name]['captures'] ?? [],
        ),
        $functions,
    );
    if (shouldRunSecondPartialFold($functions)) {
        $functions = foldPartialApply($functions, $module->moduleName);
    }

    $module = new Module($functions, $module->data, $instanceEvidence, $module->entry, $module->moduleName, $module->sourceFile);
    $module = eliminateIntrinsicWrappers($module);
    $module = foldIoActionBoxes($module);
    $functions = recomputeIoStraightLineAll($module->functions);
    $module = new Module($functions, $module->data, $module->instanceEvidence, $module->entry, $module->moduleName, $module->sourceFile);
    $functions = $module->functions;

    setPropagateCopiesLambdaBodies($functions);
    setHostEffectFunctions(hostEffectFunctionNames($functions, $module->moduleName));
    $functions = \array_map(optimizeFunctionFinal(...), $functions);
    $functions = annotateIoEffects($functions, $module->moduleName);
    // Recompute host effects after io annotation; final DCE must still see them
    // so Unit-returning foreign calls are not deleted as unused.
    setHostEffectFunctions(hostEffectFunctionNames($functions, $module->moduleName));
    $functions = \array_map(static fn (FunctionDecl $function): FunctionDecl => $function->withBody(
        $function->ioStraightLine ? $function->body : dceBlock($function->body),
    ), $functions);
    clearHostEffectFunctions();

    return new Module($functions, $module->data, $instanceEvidence, entryFromFunctions($functions), $module->moduleName, $module->sourceFile);
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
        $stmtCount += count($function->body->items);
    }

    return count($functions) > 2 || $stmtCount > 24;
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
    // Every backend emits IR\Loop / IR\TailRecall, so self-tail-recursion is
    // always rewritten (no per-backend capability flag).
    $body = eliminateTailRecursion($function->name, $function->params, $body);
    $body = new Block(foldMatchReturn($body->items));

    return $function->withBody($body);
}

function optimizeFunctionAfterInline(FunctionDecl $function): FunctionDecl
{
    if ($function->ioStraightLine) {
        // A straight-line IO body is already in its final effect order, so the
        // passes that move statements around stay off it. Folding a match whose
        // scrutinee is a known constructor is not one of them: it selects the one
        // arm that can run and drops the others, so nothing is duplicated or
        // reordered. Without it a conditional over a known value — `if`, and
        // `&&`/`||`, which lower to one — emits `if (true === true) …`.
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

function optimizeFunctionFinal(FunctionDecl $function): FunctionDecl
{
    if ($function->ioStraightLine) {
        // Same reasoning as the pass above: copy propagation only rewrites a
        // value into its use sites, and it keeps a compound binding when the
        // temp is used more than once, so a straight-line IO body gets it too.
        // Without it `putStrLn (show x)` keeps a temp for every literal and
        // every call result.
        $body = propagateCopiesBlock($function->body);
        $items = foldSingleUseCallResults(foldTailReturn($body->items));

        return $function->withBody(new Block($items));
    }

    $body = propagateCopiesBlock($function->body);
    $body = new Block(foldIntrinsicsItems($body->items));
    $body = dceBlock($body);
    $body = new Block(foldTailReturn($body->items));

    return $function->withBody($body);
}
