<?php declare(strict_types=1);

namespace Moggi\Optimize\Interproc;

use Moggi\IR;
use Moggi\Optimize\Facts\Facts;

use function Moggi\IR\Callables\captureMeta;
use function Moggi\IR\Callables\calleeIsRun;
use function Moggi\IR\Visit\collectLambdaRefsInBlock;
use function Moggi\IR\Visit\collectLocalsInBlock;
use function Moggi\IR\Visit\irPatternBoundNames;
use function Moggi\IR\Visit\mapGuards;
use function Moggi\IR\Visit\mapOperandChildren;
use function Moggi\IR\Visit\stmtDirectOperands;
use function Moggi\IR\Visit\stmtNestedBlocks;
use function Moggi\IR\Visit\walkBlock;
use function Moggi\IR\Visit\walkOperand;
use function Moggi\IR\Visit\walkStmt;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Modules\resolvedSymbol;
use function Moggi\Optimize\CaseFold\isPlausibleConstructorName;
use function Moggi\Optimize\CaseFold\joinMatchStmtConsumer;
use function Moggi\Optimize\Support\indexCapturedFunctions;
use function Moggi\Optimize\Support\mapStmtNestedBlocks;
use function Moggi\Optimize\Support\tempUsedInItems;
use function Moggi\Semantics\IntrinsicRegistry\isListConsForm;
use function Moggi\Semantics\Kernel\isBoolCtor;

/** @param array<int, mixed> $items @return list<IR\Stmt> */
function denseStmtItems(array $items): array
{
    $dense = \array_is_list($items);
    if ($dense) {
        foreach ($items as $item) {
            if (! $item instanceof IR\Stmt) {
                $dense = false;

                break;
            }
        }
        if ($dense) {
            return $items;
        }
    }

    $out = [];
    foreach ($items as $item) {
        if ($item instanceof IR\Stmt) {
            $out[] = $item;
        }
    }

    return $out;
}

/** @param array<int, mixed> $items */
function lastStmtItem(array $items): ?IR\Stmt
{
    $items = denseStmtItems($items);
    if ($items === []) {
        return null;
    }

    return $items[\count($items) - 1];
}

/** @param list<IR\FunctionDecl> $functions @return array<string, IR\FunctionDecl> */
function indexFunctions(array $functions, string $moduleName = ''): array
{
    $index = [];
    foreach ($functions as $function) {
        $index[$function->name] = $function;
        if ($moduleName !== '' && ! \str_contains($function->name, '::')) {
            $index[resolvedSymbol($moduleName, $function->name)] = $function;
        }
    }

    return $index;
}

/**
 * Resolve a callee against a local function index, including `Module::name`
 * aliases created by cross-module specialization.
 *
 * @param array<string, IR\FunctionDecl> $index
 */
function lookupIndexedFunction(array $index, string $callee): ?IR\FunctionDecl
{
    if (isset($index[$callee])) {
        return $index[$callee];
    }
    $pe = Facts::current()->fusionIndex;
    if (isset($pe[$callee])) {
        return $pe[$callee];
    }

    $leaf = specializationLookupNameSafe($callee);
    if ($leaf !== $callee && (
        (isset($index[$leaf]) && $index[$leaf]->kind === IR\FunctionKind::SpecClone)
        || Facts::current()->isEvidence($leaf)
    )) {
        if (isset($index[$leaf])) {
            return $index[$leaf];
        }
        if (isset($pe[$leaf])) {
            return $pe[$leaf];
        }
    }

    return null;
}

/** @param list<IR\FunctionDecl> $functions */
function inlineFunctions(array $functions, string $moduleName = ''): array
{
    Facts::current()->publishCaptureMeta(captureMeta(indexCapturedFunctions($functions)));
    try {
        return inlineFunctionsRounds($functions, $moduleName);
    } finally {
        Facts::current()->publishCaptureMeta([]);
    }
}

/** @param list<IR\FunctionDecl> $functions @return list<IR\FunctionDecl> */
function inlineFunctionsRounds(array $functions, string $moduleName = ''): array
{
    for ($round = 0; $round < 4; ++$round) {
        $index = indexFunctions($functions, $moduleName);
        $inlineable = inlineableCandidates($functions);
        $next = [];
        $changed = false;
        /** @var array<string, true> $rewrittenNames */
        $rewrittenNames = [];
        $boundNames = [];
        foreach ($index as $name => $callee) {
            $boundNames[$name] = functionBoundNames($callee);
        }
        Facts::current()->publishInlineTargetBound($boundNames);

        foreach ($functions as $function) {
            if ($function->ioStraightLine) {
                $next[] = $function;

                continue;
            }

            $peBlocked = [];
            $nextTemp = null;
            Facts::current()->publishInlineProtected(\array_fill_keys(
                [...$function->params, ...collectLocalsInBlock($function->body)],
                true,
            ));
            $inlinedBody = inlineBlock(
                $function->body,
                $function->name,
                $index,
                $inlineable,
                0,
                $peBlocked,
                $nextTemp,
                $rewrittenNames,
            );
            Facts::current()->publishInlineProtected([]);
            $joined = new IR\Block(joinMatchStmtConsumer($inlinedBody->items));
            if ($joined !== $function->body) {
                $changed = true;
            }

            $next[] = $function->withBody($joined);
        }

        Facts::current()->publishInlineTargetBound([]);

        if ($rewrittenNames !== []) {
            $changed = true;
            $byName = [];
            foreach ($next as $i => $fn) {
                $byName[$fn->name] = $i;
            }
            foreach ($rewrittenNames as $name => $_) {
                $rewritten = $index[$name] ?? null;
                if ($rewritten instanceof IR\FunctionDecl && isset($byName[$rewritten->name])) {
                    $next[$byName[$rewritten->name]] = $rewritten;
                }
            }
        }

        if (! $changed) {
            return $next;
        }

        $functions = $next;
    }

    return $functions;
}

/**
 * Names of local functions that participate in a self- or mutually-recursive
 * strongly connected component of the direct Call graph.
 *
 * @param array<string, IR\FunctionDecl> $index
 * @param array<string, array{names: array<string, true>, mentions: array<string, true>, unresolved: bool, throws: bool, loops: bool, dictMethods: array<string, true>}> $bodies the scan the model was built from
 * @return array<string, true>
 */
function functionNamesInRecursiveSccs(array $index, array $bodies): array
{
    /** @var array<string, list<string>> $edges */
    $edges = [];
    foreach ($index as $name => $function) {
        $callees = [];
        foreach (\array_keys($bodies[$function->name]['names']) as $callee) {
            if (isset($index[$callee])) {
                $callees[$callee] = true;
            }
        }
        $edges[$name] = \array_keys($callees);
    }

    $indexOf = [];
    $lowlink = [];
    $onStack = [];
    $stack = [];
    $dfsNum = 0;
    /** @var array<string, true> $recursive */
    $recursive = [];

    $strongconnect = static function (string $v) use (
        &$strongconnect,
        &$edges,
        &$indexOf,
        &$lowlink,
        &$onStack,
        &$stack,
        &$dfsNum,
        &$recursive,
    ): void {
        $indexOf[$v] = $dfsNum;
        $lowlink[$v] = $dfsNum;
        ++$dfsNum;
        $stack[] = $v;
        $onStack[$v] = true;

        foreach ($edges[$v] ?? [] as $w) {
            if (! isset($indexOf[$w])) {
                $strongconnect($w);
                $lowlink[$v] = \min($lowlink[$v], $lowlink[$w]);
            } elseif (isset($onStack[$w])) {
                $lowlink[$v] = \min($lowlink[$v], $indexOf[$w]);
            }
        }

        if ($lowlink[$v] === $indexOf[$v]) {
            $component = [];
            do {
                $w = \array_pop($stack);
                unset($onStack[$w]);
                $component[] = $w;
            } while ($w !== $v);

            $isRecursive = \count($component) > 1;
            if (! $isRecursive) {
                $only = $component[0];
                foreach ($edges[$only] ?? [] as $callee) {
                    if ($callee === $only) {
                        $isRecursive = true;

                        break;
                    }
                }
            }
            if ($isRecursive) {
                foreach ($component as $name) {
                    $recursive[$name] = true;
                }
            }
        }
    };

    foreach (\array_keys($edges) as $name) {
        if (! isset($indexOf[$name])) {
            $strongconnect($name);
        }
    }

    return $recursive;
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @param array<string, true> $inlineable
 * @param array<string, true> $peBlocked
 * @param-out int $nextTemp
 */
function inlineBlock(
    IR\Block $block,
    string $selfName,
    array $index,
    array $inlineable = [],
    int $depth = 0,
    array &$peBlocked = [],
    ?int &$nextTemp = null,
): IR\Block {
    $localMax = maxTempInItems($block->items) + 1;
    if ($nextTemp === null) {
        $nextTemp = $localMax;
    } else {
        $nextTemp = \max($nextTemp, $localMax);
    }

    return new IR\Block(inlineItems($block->items, $selfName, $index, $nextTemp, $inlineable, $depth, $peBlocked));
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, IR\FunctionDecl> $index
 * @param array<string, true> $inlineable
 * @param array<string, true> $peBlocked
 * @return list<IR\Stmt>
 */
function inlineItems(
    array $items,
    string $selfName,
    array $index,
    int &$nextTemp,
    array $inlineable = [],
    int $depth = 0,
    array &$peBlocked = [],
): array {
    $out = [];

    foreach (denseStmtItems($items) as $item) {
        if ($item instanceof IR\Call) {
            $target = lookupIndexedFunction($index, $item->callee);
            if ($target !== null) {
                $inlined = tryInlineCall($target, $item, $selfName, $index, $nextTemp, $inlineable);
                if ($inlined !== null) {
                    foreach (denseStmtItems($inlined) as $inlinedItem) {
                        $out[] = $inlinedItem;
                    }

                    continue;
                }
            }
        }

        $out[] = inlineStmt($item, $selfName, $index, $inlineable, $depth, $peBlocked, $nextTemp);
    }

    return denseStmtItems($out);
}

/**
 * Names a function body binds itself: `let` names and match-arm binders.
 *
 * @return list<string>
 */
function functionBoundNames(IR\FunctionDecl $function): array
{
    $names = [];
    walkBlock(
        $function->body,
        static function (IR\Stmt $stmt) use (&$names): void {
            if ($stmt instanceof IR\Let) {
                $names[] = $stmt->name;

                return;
            }

            if ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\MatchReturn || $stmt instanceof IR\IoMatch) {
                foreach ($stmt->arms as $arm) {
                    foreach (irPatternBoundNames($arm->pattern) as $name) {
                        $names[] = $name;
                    }
                }
            }
        },
        static function (IR\Operand $operand): void {
        },
    );

    return \array_values(\array_unique($names));
}

/**
 * Whether the inlined body would rebind one of the caller's own names.
 *
 * The derived `Show` for `data Tree a = Leaf a | Node (Tree a) (Tree a)` binds
 * `__f0`, `__f1` and `__f0__0` in its match arms, and the `Node` arm's `ShowS`
 * lambda reads exactly those out of the enclosing match. Inlining the one into
 * the other made the lambda render a field of whatever the inlined arms bound,
 * so the guard keeps an inline from capturing the caller's variables.
 */
function inliningWouldRebindCallerName(IR\FunctionDecl $target): bool
{
    $protected = Facts::current()->inlineProtectedNames;
    if ($protected === []) {
        return false;
    }

    $bound = Facts::current()->inlineTargetBoundNames[$target->name] ?? functionBoundNames($target);
    foreach ($bound as $name) {
        if (isset($protected[$name])) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @param array<string, true> $inlineable
 * @return list<IR\Stmt>|null
 */
function tryInlineCall(IR\FunctionDecl $target, IR\Call $call, string $selfName, array $index, int &$nextTemp, array $inlineable): ?array
{
    if ($target->name === $selfName || ! isset($inlineable[$target->name])) {
        return null;
    }

    if (inliningWouldRebindCallerName($target)) {
        return null;
    }

    if (bodyReferencesCapturedLambdas($target, $index)) {
        return null;
    }

    if (\count($call->args) !== \count($target->params)) {
        return null;
    }

    foreach ($call->args as $arg) {
        if ($arg instanceof IR\Partial) {
            return null;
        }
        if (isCapturedLambdaArg($arg, $index)) {
            return null;
        }
    }

    // Decided once, before any variant splices a body: every inlined form substitutes these args into
    // parameter positions, so work-carrying arguments are bound here rather than three times over.
    $materialized = materializeInlineArgs($call->args, $nextTemp);
    $nextTemp = $materialized['nextTemp'];
    $prefix = $materialized['prefix'];
    $call = new IR\Call($call->callee, $materialized['args'], $call->dest, $call->srcLoc);

    if (isMatchWrapperBody($target)) {
        $inlined = tryInlineMatchWrapper($target, $call, $nextTemp);
        if ($inlined === null) {
            return null;
        }
        $srcModule = calleeModuleName($call->callee);
        if ($srcModule !== null && $srcModule !== '') {
            $inlined = qualifyBareCalleesInItems($inlined, $srcModule, $index);
        }

        return [...$prefix, ...$inlined];
    }

    if (isPeFusionInlineCandidate($target)) {
        $inlined = tryInlinePeFusionBody($target, $call, $nextTemp);

        return $inlined === null ? null : [...$prefix, ...$inlined];
    }

    $body = denseStmtItems($target->body->items);
    $last = lastStmtItem($body);
    if ($last === null || ! ($last instanceof IR\Ret)) {
        return null;
    }

    $remap = buildTempRemap($body, $nextTemp);
    if ($remap !== []) {
        $nextTemp = \max($remap) + 1;
    }
    $body = denseStmtItems(remapTempsInItems($target->body->items, $remap));
    $srcModule = calleeModuleName($call->callee);
    if ($srcModule !== null && $srcModule !== '') {
        $body = qualifyBareCalleesInItems($body, $srcModule, $index);
    }
    $last = lastStmtItem($body);
    if ($last === null || ! ($last instanceof IR\Ret)) {
        return null;
    }

    $args = $call->args;

    $bound = [];
    foreach (\array_slice($body, 0, -1) as $item) {
        $prefix[] = substituteStmt($item, $target->params, $args, $bound);
        if ($item instanceof IR\Let) {
            $bound[$item->name] = true;
        }
    }
    $value = substituteExpr($last->value, $target->params, $args, $bound);

    $inlined = denseStmtItems([...$prefix, ...inlineExprAsItems($value, $call->dest)]);

    return alignInlineCallDest($inlined, $call->dest);
}

/**
 * Bind an argument that runs work to a temp, so substitution can only read it.
 *
 * An operand position holds values, except the two kinds the fact owner answers for
 * ({@see operandIsNonDuplicable()}): a foreign call, and a bare reference to a parameterless entry,
 * which the backends force into a call. Substituting either into a callee that mentions its parameter
 * more than once runs the work once per mention — two `StringWriter`s for one encoder — so each is bound
 * once, before the body, and every mention reads the temp.
 *
 * @param list<IR\Operand> $args
 * @return array{prefix: list<IR\Stmt>, args: list<IR\Operand>, nextTemp: int}
 */
function materializeInlineArgs(array $args, int $nextTemp): array
{
    $prefix = [];
    $out = [];
    foreach ($args as $arg) {
        if (operandIsNonDuplicable($arg)) {
            $dest = $nextTemp++;
            $prefix[] = new IR\Assign($dest, $arg);
            $out[] = new IR\Temp($dest);

            continue;
        }
        $out[] = $arg;
    }

    return ['prefix' => $prefix, 'args' => $out, 'nextTemp' => $nextTemp];
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @return list<IR\Stmt>|null
 */
function tryInlineMatchWrapper(IR\FunctionDecl $target, IR\Call $call, int &$nextTemp): ?array
{
    $items = denseStmtItems($target->body->items);
    $match = $items[0];
    if (! ($match instanceof IR\MatchReturn)) {
        return null;
    }

    $remap = buildTempRemap(matchReturnTempItems($match), $nextTemp);
    if ($remap !== []) {
        $nextTemp = \max($remap) + 1;
    }

    $match = remapTempsInStmt($match, $remap);
    if (! ($match instanceof IR\MatchReturn)) {
        return null;
    }

    $bound = [];
    $scrutinee = substituteOperand($match->scrutinee, $target->params, $call->args, $bound);
    $arms = [];
    foreach ($match->arms as $arm) {
        $armBound = $bound;
        foreach (matchArmPatternBoundNames($arm->pattern) as $name) {
            $armBound[$name] = true;
        }
        $armItems = [];
        foreach (denseStmtItems($arm->body->items) as $item) {
            $armItems[] = substituteStmt($item, $target->params, $call->args, $armBound);
            if ($item instanceof IR\Let) {
                $armBound[$item->name] = true;
            }
        }
        $arms[] = new IR\MatchArm(
            $arm->pattern,
            new IR\Block(assignMatchArmToDest($armItems, $call->dest)),
            substituteGuards($arm->guards, $target->params, $call->args, $armBound),
        );
    }

    return [new IR\MatchStmt($scrutinee, $arms, $call->dest, $match->exhaustive)];
}

/**
 * The temps of an arm: its body and the statements its guards need. A guard's
 * preparation is emitted with the arm, so its temps have to be remapped with
 * the rest — otherwise they keep the callee's ids and collide with the
 * caller's.
 *
 * @return list<IR\Stmt>
 */
function matchReturnTempItems(IR\MatchReturn $match): array
{
    $items = [];
    foreach ($match->arms as $arm) {
        foreach ($arm->body->items as $item) {
            $items[] = $item;
        }
        foreach ($arm->guards as $guard) {
            foreach ($guard->prep->items as $item) {
                $items[] = $item;
            }
        }
    }

    return $items;
}

/**
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function assignMatchArmToDest(array $items, int $dest): array
{
    $items = denseStmtItems($items);
    if ($items === []) {
        return $items;
    }

    $last = $items[\count($items) - 1];
    if ($last instanceof IR\Ret) {
        \array_pop($items);
        foreach (inlineExprAsItems($last->value, $dest) as $stmt) {
            $items[] = $stmt;
        }

        return denseStmtItems($items);
    }

    if ($last instanceof IR\Assign && $last->dest === $dest) {
        return $items;
    }

    if ($last instanceof IR\Call && $last->dest === $dest) {
        return $items;
    }

    if ($last instanceof IR\CallValue && $last->dest === $dest) {
        return $items;
    }

    return alignInlineCallDest($items, $dest);
}

/** @param array<string, IR\FunctionDecl> $index */
function isCapturedLambdaArg(IR\Operand $arg, array $index): bool
{
    if (! $arg instanceof IR\FnRef) {
        return false;
    }

    $lambda = $index[$arg->name] ?? null;
    if ($lambda === null || ! $lambda->kind->takesCaptures()) {
        return false;
    }

    foreach (collectLocalsInBlock($lambda->body) as $local) {
        if (! \in_array($local, $lambda->params, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Whether inlining this body into another function could leave a lifted local
 * function call resolving its captures in the wrong scope.
 *
 * A lifted `λ`/`__letrec` function reads its free locals as leading parameters:
 * `bindLambdaCaptures` prepends them at every call site, after inlining has
 * run, so the capture names only mean something in a scope that binds them.
 * Copying such a call into another function leaves the prepended names
 * dangling (or, worse, bound to an unrelated same-named local), which the
 * emitters report as an unknown local. A body that only mentions a captured
 * function with no free locals is safe: there is nothing to prepend.
 */
function bodyReferencesCapturedLambdas(IR\FunctionDecl $function, array $index): bool
{
    $leafCaptured = static function (string $name) use ($index): bool {
        $lambda = $index[$name]
            ?? $index[specializationLookupNameSafe($name)]
            ?? lookupIndexedFunction($index, $name);

        return $lambda !== null && $lambda->kind->takesCaptures();
    };
    $names = collectLambdaRefsInBlock($function->body, $leafCaptured);
    walkBlock(
        $function->body,
        static function (IR\Stmt $stmt) use (&$names, $leafCaptured): void {
            if ($stmt instanceof IR\Call && $leafCaptured($stmt->callee)) {
                $names[] = $stmt->callee;
            }
        },
        static function (IR\Operand $operand): void {
        },
    );
    $meta = Facts::current()->captureMeta;
    foreach (\array_unique($names) as $lambdaName) {
        $leaf = specializationLookupNameSafe($lambdaName);
        $info = $meta[$leaf] ?? null;
        if ($info !== null) {
            if ($info['captures'] !== []) {
                return true;
            }
            continue;
        }
        $lambda = $index[$lambdaName]
            ?? $index[$leaf]
            ?? lookupIndexedFunction($index, $lambdaName);
        if ($lambda === null) {
            continue;
        }
        foreach (collectLocalsInBlock($lambda->body) as $local) {
            if (! \in_array($local, $lambda->params, true)) {
                return true;
            }
        }
    }

    return false;
}

/** @param list<IR\Stmt> $inlined @return list<IR\Stmt> */
function alignInlineCallDest(array $inlined, int $callDest): array
{
    $inlined = denseStmtItems($inlined);
    if ($inlined === []) {
        return $inlined;
    }

    $last = lastStmtItem($inlined);
    if ($last === null) {
        return $inlined;
    }

    if ($last instanceof IR\Assign && ($last->dest ?? null) === $callDest) {
        return $inlined;
    }

    if (! isset($last->dest) || $last->dest === $callDest) {
        return $inlined;
    }

    return [...$inlined, new IR\Assign($callDest, new IR\Temp($last->dest))];
}

/** @return list<IR\Stmt> */
function inlineExprAsItems(IR\Operand $expr, int $dest): array
{
    return [new IR\Assign($dest, $expr)];
}

/**
 * Whether copying this operand into a second use would run something twice.
 *
 * Operand position holds values; the one computation the canonical IR still admits there is a foreign
 * call, which executes on every copy. Everything else is a value the emitter re-materializes from the
 * same inputs, so duplicating it re-computes a value rather than performing work.
 */
function operandIsNonDuplicable(IR\Operand $op): bool
{
    return $op instanceof IR\ForeignCall
        || ($op instanceof IR\FnRef && Facts::current()->runsOnReference($op->name));
}

/** True when `$op` mentions any Temp (not safe to hoist into a specialized clone). */
function operandHasFreeTemps(IR\Operand $op): bool
{
    $found = false;
    walkOperand($op, static function (IR\Operand $inner) use (&$found): void {
        if ($inner instanceof IR\Temp) {
            $found = true;
        }
    });

    return $found;
}

/** @param list<IR\Stmt> $items */
function countLocalUsesInItems(array $items, string $name): int
{
    $n = 0;
    foreach (denseStmtItems($items) as $item) {
        walkStmt(
            $item,
            static function (IR\Stmt $_): void {
            },
            static function (IR\Operand $op) use (&$n, $name): void {
                if ($op instanceof IR\Local && $op->name === $name) {
                    ++$n;
                }
            },
        );
    }

    return $n;
}

/** @param array<int, IR\Operand> $tempEnv @param array<int, true> $sticky */
function replaceTempsInOperand(IR\Operand $operand, array $tempEnv, array $sticky = []): IR\Operand
{
    if ($tempEnv === []) {
        return $operand;
    }

    if ($operand instanceof IR\Temp && isset($tempEnv[$operand->id])) {
        if (isset($sticky[$operand->id])) {
            return $operand;
        }

        return replaceTempsInOperand($tempEnv[$operand->id], $tempEnv, $sticky);
    }

    if ($operand instanceof IR\DictMethod) {
        return new IR\DictMethod(
            replaceTempsInOperand($operand->evidence, $tempEnv, $sticky),
            $operand->method,
            $operand->localEvidence,
        );
    }

    if ($operand instanceof IR\ListLit) {
        return new IR\ListLit(\array_map(
            static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv, $sticky),
            $operand->elements,
        ));
    }

    if ($operand instanceof IR\Intrinsic) {
        $args = \array_map(
            static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv, $sticky),
            $operand->args,
        );
        $folded = foldListIntrinsicExpr($operand->name, $args);
        if ($folded !== null) {
            return replaceTempsInOperand($folded, $tempEnv, $sticky);
        }

        return new IR\Intrinsic($operand->name, $args, $operand->srcLoc);
    }

    if ($operand instanceof IR\Partial) {
        return new IR\Partial(
            $operand->fn,
            $operand->arity,
            \array_map(
                static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv, $sticky),
                $operand->args,
            ),
        );
    }

    if ($operand instanceof IR\ForeignCall) {
        return new IR\ForeignCall(
            $operand->backend,
            $operand->kind,
            $operand->path,
            $operand->dispatch,
            \array_map(
                static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv, $sticky),
                $operand->args,
            ),
            $operand->classPath,
            $operand->member,
            $operand->ioWrap,
            $operand->phpValueBox,
            $operand->handleBox,
            $operand->handleUnboxArgs,
            $operand->nativeSig,
        );
    }

    return $operand;
}

/**
 * Fold listHead#/listTail# of a known listCons# / empty ListLit.
 *
 * @param list<IR\Operand> $args
 */
function foldListIntrinsicExpr(string $name, array $args): ?IR\Operand
{
    if ($name === 'listHead#' && \count($args) === 1) {
        $xs = $args[0];
        if ($xs instanceof IR\Intrinsic && isListConsForm($xs->name) && \count($xs->args) === 2) {
            return $xs->args[0];
        }
        if ($xs instanceof IR\ListLit && $xs->elements !== []) {
            return $xs->elements[0];
        }
    }
    if ($name === 'listTail#' && \count($args) === 1) {
        $xs = $args[0];
        if ($xs instanceof IR\Intrinsic && isListConsForm($xs->name) && \count($xs->args) === 2) {
            return $xs->args[1];
        }
        if ($xs instanceof IR\ListLit && $xs->elements !== []) {
            return new IR\ListLit(\array_slice($xs->elements, 1));
        }
    }

    return null;
}

/**
 * Whether this callee may be copied into a caller at all.
 *
 * Replacing one call with one body executes the same work once, at the same place, so a body's own
 * effects say nothing about whether it may be inlined -- what would duplicate is an *argument*
 * substituted into several parameter uses, which is why {@see materializeInlineArgs()} binds the one
 * operand kind that executes and `partial`/captured-lambda arguments are refused outright. What is
 * decided here is shape: a loop, a scoped return or a dictionary call has its own legality rules
 * elsewhere, and splicing one into a caller's block would bypass them.
 */
function inlineIsLegal(IR\FunctionDecl $function): bool
{
    if ($function->foreign || $function->kind->takesCaptures()) {
        return false;
    }

    return isMatchWrapperBody($function) || inlineBodyIsSpliceable($function);
}

/** A straight-line body ending in its single `ret`. */
function inlineBodyIsSpliceable(IR\FunctionDecl $function): bool
{
    $items = denseStmtItems($function->body->items);
    $last = $items[\count($items) - 1] ?? null;
    if (! ($last instanceof IR\Ret)) {
        return false;
    }

    if ($last->value instanceof IR\FnRef && ! isBoolCtor($last->value->name)) {
        return false;
    }

    if (functionBodyCallsSelf($items, $function->name)) {
        return false;
    }

    foreach ($items as $item) {
        if (\in_array($item::class, [
            IR\MatchStmt::class, IR\MatchReturn::class, IR\Loop::class,
            IR\TailRecall::class, IR\DictCall::class,
        ], true)) {
            return false;
        }
    }

    $rets = 0;
    walkBlock($function->body, static function (IR\Stmt $stmt) use (&$rets): void {
        if ($stmt instanceof IR\Ret) {
            ++$rets;
        }
    }, static function (): void {
    });

    return $rets === 1;
}

/**
 * Whether copying this body pays for itself.
 *
 * A budget, and only a budget: the answer may move without changing what the program computes, so a
 * later tuning change edits this function and nothing else.
 */
function inlineIsWorthwhile(IR\FunctionDecl $function): bool
{
    if (isMatchWrapperBody($function)) {
        return matchWrapperIsWithinBudget($function);
    }

    return \count(denseStmtItems($function->body->items)) <= 4;
}

/**
 * A single MatchReturn body whose arms hold no nested control -- a typical ADT converter (from/to).
 * Spliced as a whole match, so the arms have to be straight-line for the caller's block to stay legal.
 */
function isMatchWrapperBody(IR\FunctionDecl $function): bool
{
    if ($function->foreign || $function->ioEffect || $function->kind->takesCaptures()) {
        return false;
    }

    $items = denseStmtItems($function->body->items);
    $match = $items[0] ?? null;
    if (\count($items) !== 1 || ! ($match instanceof IR\MatchReturn)) {
        return false;
    }

    if (functionBodyCallsSelf($items, $function->name)) {
        return false;
    }

    foreach ($match->arms as $arm) {
        foreach (denseStmtItems($arm->body->items) as $item) {
            if (\in_array($item::class, [IR\MatchStmt::class, IR\MatchReturn::class, IR\Loop::class, IR\TailRecall::class], true)) {
                return false;
            }
        }
    }

    return true;
}

/** Arm count and arm size: a converter's match is copied until it costs more than the call. */
function matchWrapperIsWithinBudget(IR\FunctionDecl $function): bool
{
    $match = denseStmtItems($function->body->items)[0] ?? null;
    if (! ($match instanceof IR\MatchReturn) || \count($match->arms) > 4) {
        return false;
    }

    foreach ($match->arms as $arm) {
        $armItems = denseStmtItems($arm->body->items);
        if (\count($armItems) > 24) {
            return false;
        }
        foreach ($armItems as $item) {
            if (estimateStmtExprSize($item) > 64) {
                return false;
            }
        }
    }

    return true;
}

/**
 * The callees this round may copy, and nothing else.
 *
 * Both halves of the decision are taken once per callee, because neither depends on which call reaches
 * it: legality is a property of the body's shape, profitability a budget on its size.
 *
 * @param list<IR\FunctionDecl> $functions
 * @return array<string, true>
 */
function inlineableCandidates(array $functions): array
{
    $inlineable = [];
    foreach ($functions as $function) {
        if (inlineIsLegal($function) && inlineIsWorthwhile($function)) {
            $inlineable[$function->name] = true;
        }
    }

    return $inlineable;
}

/** @param list<IR\Stmt> $items */
function functionBodyCallsSelf(array $items, string $name): bool
{
    foreach ($items as $item) {
        if (stmtReferencesFunction($item, $name)) {
            return true;
        }
    }

    return false;
}

function stmtReferencesFunction(IR\Stmt $stmt, string $name): bool
{
    return match ($stmt::class) {
        IR\Call::class => $stmt->callee === $name,
        IR\MatchStmt::class, IR\MatchReturn::class => (static function () use ($stmt, $name): bool {
            foreach ($stmt->arms as $arm) {
                if (functionBodyCallsSelf($arm->body->items, $name)) {
                    return true;
                }
            }

            return false;
        })(),
        IR\Loop::class => functionBodyCallsSelf($stmt->body->items, $name),
        default => false,
    };
}

/**
 * A guard owns the statements that compute it, and those read the callee's
 * parameters (`let __litpat0 = _p0`) exactly like the arm body does, so they
 * have to be substituted with it — leaving them alone inlined the guard's
 * prep and condition with the callee's parameter names still in them.
 *
 * @param list<IR\Guard> $guards
 * @param array<int, string> $params
 * @param list<IR\Operand> $args
 * @param array<string, true> $bound
 * @return list<IR\Guard>
 */
function substituteGuards(array $guards, array $params, array $args, array $bound): array
{
    return mapGuards(
        $guards,
        static fn (IR\Operand $cond): IR\Operand => substituteOperand($cond, $params, $args, $bound),
        static fn (IR\Stmt $item): IR\Stmt => substituteStmt($item, $params, $args, $bound),
    );
}

/**
 * @param array<int, string> $params
 * @param list<IR\Operand> $args
 */
function substituteStmt(IR\Stmt $stmt, array $params, array $args, array $bound = []): IR\Stmt
{
    return match ($stmt::class) {
        IR\Let::class => new IR\Let($stmt->name, substituteOperand($stmt->value, $params, $args, $bound)),
        IR\Assign::class => new IR\Assign($stmt->dest, substituteOperand($stmt->value, $params, $args, $bound)),
        IR\Call::class => new IR\Call($stmt->callee, \array_map(
            static fn (IR\Operand $arg): IR\Operand => substituteOperand($arg, $params, $args, $bound),
            $stmt->args,
        ), $stmt->dest, $stmt->srcLoc),
        IR\CallValue::class => new IR\CallValue(substituteOperand($stmt->callee, $params, $args, $bound), \array_map(
            static fn (IR\Operand $arg): IR\Operand => substituteOperand($arg, $params, $args, $bound),
            $stmt->args,
        ), $stmt->dest, $stmt->srcLoc),
        IR\DictCall::class => new IR\DictCall(substituteOperand($stmt->evidence, $params, $args, $bound), $stmt->method, \array_map(
            static fn (IR\Operand $arg): IR\Operand => substituteOperand($arg, $params, $args, $bound),
            $stmt->args,
        ), $stmt->dest, $stmt->srcLoc, $stmt->localEvidence),
        IR\Ret::class => new IR\Ret(substituteExpr($stmt->value, $params, $args, $bound)),
        IR\MatchStmt::class => new IR\MatchStmt(
            substituteOperand($stmt->scrutinee, $params, $args, $bound),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => substituteArm($arm, $params, $args, $bound),
                $stmt->arms,
            ),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\MatchReturn::class => new IR\MatchReturn(
            substituteOperand($stmt->scrutinee, $params, $args, $bound),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => substituteArm($arm, $params, $args, $bound),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        IR\TailRecall::class => new IR\TailRecall(\array_map(
            static fn (IR\Operand $arg): IR\Operand => substituteOperand($arg, $params, $args, $bound),
            $stmt->args,
        )),
        IR\Loop::class => new IR\Loop(new IR\Block(\array_map(
            static fn (IR\Stmt $item): IR\Stmt => substituteStmt($item, $params, $args, $bound),
            denseStmtItems($stmt->body->items),
        ))),
        IR\IoCall::class => new IR\IoCall(
            $stmt->callee,
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => substituteOperand($arg, $params, $args, $bound),
                $stmt->args,
            ),
            $stmt->dest,
            $stmt->intrinsic,
            $stmt->runtime,
            $stmt->foreign === null
                ? null
                : substituteOperand($stmt->foreign, $params, $args, $bound),
            $stmt->srcLoc,
        ),
        IR\IoRun::class => new IR\IoRun(
            substituteOperand($stmt->action, $params, $args, $bound),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\IoMatch::class => new IR\IoMatch(
            substituteOperand($stmt->scrutinee, $params, $args, $bound),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => substituteArm($arm, $params, $args, $bound),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        IR\IoAssignAction::class => new IR\IoAssignAction(
            $stmt->dest,
            new IR\Block(\array_map(
                static fn (IR\Stmt $item): IR\Stmt => substituteStmt($item, $params, $args, $bound),
                denseStmtItems($stmt->body->items),
            )),
            substituteOperand($stmt->result, $params, $args, $bound),
            $stmt->srcLoc,
        ),
        IR\IoThrow::class => new IR\IoThrow(
            substituteOperand($stmt->exception, $params, $args, $bound),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\IoCatch::class => new IR\IoCatch(
            substituteOperand($stmt->action, $params, $args, $bound),
            substituteOperand($stmt->handler, $params, $args, $bound),
            $stmt->dest,
        ),
        IR\IoFinally::class => new IR\IoFinally(
            substituteOperand($stmt->action, $params, $args, $bound),
            substituteOperand($stmt->cleanup, $params, $args, $bound),
            $stmt->dest,
        ),
        default => $stmt,
    };
}

/**
 * @param array<int, string> $params
 * @param list<IR\Operand> $args
 * @param array<string, true> $bound
 */
function substituteArm(IR\MatchArm $arm, array $params, array $args, array $bound = []): IR\MatchArm
{
    $armBound = $bound;
    foreach (matchArmPatternBoundNames($arm->pattern) as $name) {
        $armBound[$name] = true;
    }
    $items = [];
    foreach (denseStmtItems($arm->body->items) as $item) {
        $items[] = substituteStmt($item, $params, $args, $armBound);
        if ($item instanceof IR\Let) {
            $armBound[$item->name] = true;
        }
    }

    return new IR\MatchArm($arm->pattern, new IR\Block($items), substituteGuards($arm->guards, $params, $args, $armBound));
}

/**
 * @param array<int, string> $params
 * @param list<IR\Operand> $args
 * @param array<string, true> $bound
 */
function substituteOperand(IR\Operand $operand, array $params, array $args, array $bound = []): IR\Operand
{
    if ($operand instanceof IR\Local) {
        return substituteLocal($operand, $params, $args, $bound);
    }

    return mapOperandChildren(
        $operand,
        static fn (IR\Operand $child): IR\Operand => substituteOperand($child, $params, $args, $bound),
    );
}

/** @return list<string> */
function matchArmPatternBoundNames(IR\Pattern $pattern): array
{
    if ($pattern instanceof IR\PatVar) {
        return [$pattern->name];
    }
    if ($pattern instanceof IR\PatCon) {
        $names = [];
        foreach ($pattern->args as $arg) {
            foreach (matchArmPatternBoundNames($arg) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }
    if ($pattern instanceof IR\PatTuple) {
        $names = [];
        foreach ($pattern->elements as $el) {
            foreach (matchArmPatternBoundNames($el) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }
    if ($pattern instanceof IR\PatCons) {
        return [...matchArmPatternBoundNames($pattern->head), ...matchArmPatternBoundNames($pattern->tail)];
    }

    return [];
}

/** @param list<IR\Stmt> $items */
function maxTempInItems(array $items): int
{
    $max = -1;
    foreach ($items as $item) {
        foreach (collectTempIdsInStmt($item) as $id) {
            $max = \max($max, $id);
        }
    }

    return $max;
}

/** @param list<IR\Stmt> $items @return array<int, int> */
function buildTempRemap(array $items, int $nextFree): array
{
    $remap = [];
    foreach (collectTempIdsInItems($items) as $id) {
        if (! isset($remap[$id])) {
            $remap[$id] = $nextFree++;
        }
    }

    return $remap;
}

/** @param list<IR\Stmt> $items @return array<int, int> */
function collectTempIdsInItems(array $items): array
{
    $ids = [];
    foreach ($items as $item) {
        foreach (collectTempIdsInStmt($item) as $id) {
            $ids[$id] = true;
        }
    }

    return \array_keys($ids);
}

/**
 * Every temp id mentioned by `$stmt`, including its nested blocks. Walked
 * generically so node kinds the original hand-written `match` did not list
 * (`tail_recall`, `Loop`, the IO nodes) cannot silently contribute nothing to
 * the inliner's liveness set.
 *
 * @return list<int>
 */
function collectTempIdsInStmt(IR\Stmt $stmt): array
{
    $ids = [];
    if (isset($stmt->dest) && \is_int($stmt->dest)) {
        $ids[$stmt->dest] = true;
    }

    foreach (stmtDirectOperands($stmt) as $operand) {
        foreach (collectTempIdsInOperand($operand) as $id) {
            $ids[$id] = true;
        }
    }
    foreach (stmtNestedBlocks($stmt) as $block) {
        foreach (collectTempIdsInItems($block->items) as $id) {
            $ids[$id] = true;
        }
    }

    return \array_map(intval(...), \array_keys($ids));
}

/** @return list<int> */
function collectTempIdsInOperand(IR\Operand $operand): array
{
    $ids = [];
    walkOperand($operand, static function (IR\Operand $op) use (&$ids): void {
        if ($op instanceof IR\Temp) {
            $ids[$op->id] = true;
        }
    });

    return \array_map(intval(...), \array_keys($ids));
}

/** @param array<int, int> $remap @param list<IR\Stmt> $items @return list<IR\Stmt> */
function remapTempsInItems(array $items, array $remap): array
{
    return denseStmtItems(\array_map(static fn (IR\Stmt $item): IR\Stmt => remapTempsInStmt($item, $remap), $items));
}

/** @param array<int, int> $remap */
function remapTempsInStmt(IR\Stmt $stmt, array $remap): IR\Stmt
{
    return match ($stmt::class) {
        IR\Let::class => new IR\Let($stmt->name, remapTempsInOperand($stmt->value, $remap)),
        IR\Assign::class => new IR\Assign(remapTempId($stmt->dest, $remap), remapTempsInOperand($stmt->value, $remap)),
        IR\Call::class => new IR\Call($stmt->callee, \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $stmt->args), remapTempId($stmt->dest, $remap), $stmt->srcLoc),
        IR\CallValue::class => new IR\CallValue(remapTempsInOperand($stmt->callee, $remap), \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $stmt->args), remapTempId($stmt->dest, $remap), $stmt->srcLoc),
        IR\DictCall::class => new IR\DictCall(
            remapTempsInOperand($stmt->evidence, $remap),
            $stmt->method,
            \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $stmt->args),
            remapTempId($stmt->dest, $remap),
            $stmt->srcLoc,
            $stmt->localEvidence,
        ),
        IR\Ret::class => new IR\Ret(remapTempsInOperand($stmt->value, $remap)),
        IR\MatchStmt::class => new IR\MatchStmt(
            remapTempsInOperand($stmt->scrutinee, $remap),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(remapTempsInItems($arm->body->items, $remap)),
                    mapGuards(
                        $arm->guards,
                        static fn (IR\Operand $cond): IR\Operand => remapTempsInOperand($cond, $remap),
                        static fn (IR\Stmt $item): IR\Stmt => remapTempsInStmt($item, $remap),
                    ),
                ),
                $stmt->arms,
            ),
            remapTempId($stmt->dest, $remap),
            $stmt->exhaustive,
        ),
        IR\MatchReturn::class => new IR\MatchReturn(
            remapTempsInOperand($stmt->scrutinee, $remap),
            \array_map(
                static fn (IR\MatchArm $arm): IR\MatchArm => new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(remapTempsInItems($arm->body->items, $remap)),
                    mapGuards(
                        $arm->guards,
                        static fn (IR\Operand $cond): IR\Operand => remapTempsInOperand($cond, $remap),
                        static fn (IR\Stmt $item): IR\Stmt => remapTempsInStmt($item, $remap),
                    ),
                ),
                $stmt->arms,
            ),
            $stmt->exhaustive,
        ),
        IR\TailRecall::class => new IR\TailRecall(\array_map(
            static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap),
            $stmt->args,
        )),
        IR\Loop::class => new IR\Loop(new IR\Block(remapTempsInItems($stmt->body->items, $remap))),
        IR\IoCall::class => new IR\IoCall(
            $stmt->callee,
            \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $stmt->args),
            remapNullableTempId($stmt->dest, $remap),
            $stmt->intrinsic,
            $stmt->runtime,
            $stmt->foreign === null ? null : remapTempsInOperand($stmt->foreign, $remap),
            $stmt->srcLoc,
        ),
        IR\IoRun::class => new IR\IoRun(
            remapTempsInOperand($stmt->action, $remap),
            remapNullableTempId($stmt->dest, $remap),
            $stmt->srcLoc,
        ),
        IR\IoMatch::class => new IR\IoMatch(
            remapTempsInOperand($stmt->scrutinee, $remap),
            \array_map(static fn (IR\MatchArm $arm): IR\MatchArm => remapTempsInArm($arm, $remap), $stmt->arms),
            $stmt->exhaustive,
        ),
        IR\IoAssignAction::class => new IR\IoAssignAction(
            remapTempId($stmt->dest, $remap),
            new IR\Block(remapTempsInItems($stmt->body->items, $remap)),
            remapTempsInOperand($stmt->result, $remap),
            $stmt->srcLoc,
        ),
        IR\IoThrow::class => new IR\IoThrow(
            remapTempsInOperand($stmt->exception, $remap),
            remapNullableTempId($stmt->dest, $remap),
            $stmt->srcLoc,
        ),
        IR\IoCatch::class => new IR\IoCatch(
            remapTempsInOperand($stmt->action, $remap),
            remapTempsInOperand($stmt->handler, $remap),
            remapNullableTempId($stmt->dest, $remap),
        ),
        IR\IoFinally::class => new IR\IoFinally(
            remapTempsInOperand($stmt->action, $remap),
            remapTempsInOperand($stmt->cleanup, $remap),
            remapNullableTempId($stmt->dest, $remap),
        ),
        default => $stmt,
    };
}

/** @param array<int, int> $remap */
function remapNullableTempId(?int $id, array $remap): ?int
{
    return $id === null ? null : remapTempId($id, $remap);
}

/** @param array<int, int> $remap */
function remapTempsInArm(IR\MatchArm $arm, array $remap): IR\MatchArm
{
    return new IR\MatchArm(
        $arm->pattern,
        new IR\Block(remapTempsInItems($arm->body->items, $remap)),
        mapGuards(
            $arm->guards,
            static fn (IR\Operand $cond): IR\Operand => remapTempsInOperand($cond, $remap),
            static fn (IR\Stmt $item): IR\Stmt => remapTempsInStmt($item, $remap),
        ),
    );
}

/** @param array<int, int> $remap */
function remapTempsInOperand(IR\Operand $operand, array $remap): IR\Operand
{
    return match ($operand::class) {
        IR\Temp::class => new IR\Temp(remapTempId($operand->id, $remap)),
        IR\Intrinsic::class => new IR\Intrinsic($operand->name, \array_map(
            static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap),
            $operand->args,
        ), $operand->srcLoc),
        IR\ListLit::class => new IR\ListLit(\array_map(
            static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap),
            $operand->elements,
        )),
        IR\Partial::class => new IR\Partial(
            $operand->fn,
            $operand->arity,
            \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $operand->args),
        ),
        IR\DictMethod::class => new IR\DictMethod(
            remapTempsInOperand($operand->evidence, $remap),
            $operand->method,
            $operand->localEvidence,
        ),
        IR\ForeignCall::class => new IR\ForeignCall(
            $operand->backend,
            $operand->kind,
            $operand->path,
            $operand->dispatch,
            \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $operand->args),
            $operand->classPath,
            $operand->member,
            $operand->ioWrap,
            $operand->phpValueBox,
            $operand->handleBox,
            $operand->handleUnboxArgs,
            $operand->nativeSig,
        ),
        default => $operand,
    };
}

/** @param array<int, int> $remap */
function remapTempId(int $id, array $remap): int
{
    return $remap[$id] ?? $id;
}

/**
 * @param array<int, string> $params
 * @param list<IR\Operand> $args
 * @param array<string, true> $bound
 */
function substituteExpr(IR\Operand $expr, array $params, array $args, array $bound = []): IR\Operand
{
    return match ($expr::class) {
        IR\ConstInt::class, IR\FnRef::class => $expr,
        IR\Local::class => substituteLocal($expr, $params, $args, $bound),
        IR\Temp::class => $expr,
        IR\Partial::class, IR\Intrinsic::class, IR\ListLit::class,
        IR\DictMethod::class, IR\ForeignCall::class => substituteOperand($expr, $params, $args, $bound),
        default => substituteOperand($expr, $params, $args, $bound),
    };
}

/**
 * @param array<int, string> $params
 * @param list<IR\Operand> $args
 * @param array<string, true> $bound
 */
function substituteLocal(IR\Local $local, array $params, array $args, array $bound = []): IR\Operand
{
    if (isset($bound[$local->name])) {
        return $local;
    }

    foreach ($params as $i => $param) {
        if ($param === $local->name) {
            if (! \array_key_exists($i, $args)) {
                throw new \RuntimeException(
                    'substituteLocal: no argument for parameter `' . $param . '` (arity mismatch)',
                );
            }

            return $args[$i];
        }
    }

    return $local;
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @param array<string, true> $inlineable
 * @param array<string, true> $peBlocked
 */
function inlineStmt(
    IR\Stmt $stmt,
    string $selfName,
    array $index,
    array $inlineable = [],
    int $depth = 0,
    array &$peBlocked = [],
    ?int &$nextTemp = null,
): IR\Stmt {
    return mapStmtNestedBlocks(
        $stmt,
        static function (IR\Block $block) use ($selfName, $index, $inlineable, $depth, &$peBlocked, &$nextTemp): IR\Block {
            return inlineBlock(
                $block,
                $selfName,
                $index,
                $inlineable,
                $depth + 1,
                $peBlocked,
                $nextTemp,
            );
        },
    );
}

/** @param IR\FunctionDecl $lambda */
function isSimpleLambda(IR\FunctionDecl $lambda): bool
{
    if ($lambda->kind !== IR\FunctionKind::Lambda || \count($lambda->params) !== 1) {
        return false;
    }

    $items = $lambda->body->items;

    return \count($items) === 1 && $items[0] instanceof IR\Ret;
}

function calleeModuleName(string $callee): ?string
{
    $parsed = parseResolvedSymbol($callee);

    return $parsed !== null ? $parsed['module'] : null;
}

function estimateOperandTreeSize(IR\Operand $operand, int $depth = 0): int
{
    if ($depth > 64) {
        return 10_000;
    }
    if ($operand instanceof IR\ForeignCall) {
        $n = 1;
        foreach ($operand->args as $arg) {
            $n += estimateOperandTreeSize($arg, $depth + 1);
        }

        return $n;
    }
    if ($operand instanceof IR\Intrinsic || $operand instanceof IR\Partial) {
        $n = 1;
        foreach ($operand->args as $arg) {
            $n += estimateOperandTreeSize($arg, $depth + 1);
        }

        return $n;
    }
    if ($operand instanceof IR\ListLit) {
        $n = 1;
        foreach ($operand->elements as $el) {
            $n += estimateOperandTreeSize($el, $depth + 1);
        }

        return $n;
    }

    return 0;
}

function estimateStmtExprSize(IR\Stmt $stmt): int
{
    $n = 0;
    if ($stmt instanceof IR\Assign) {
        $n += estimateOperandTreeSize($stmt->value);
    } elseif ($stmt instanceof IR\Ret) {
        $n += estimateOperandTreeSize($stmt->value);
    } elseif ($stmt instanceof IR\Call || $stmt instanceof IR\CallValue) {
        foreach ($stmt->args as $arg) {
            $n += estimateOperandTreeSize($arg);
        }
        if ($stmt instanceof IR\CallValue) {
            $n += estimateOperandTreeSize($stmt->callee);
        }
    } elseif ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\MatchReturn) {
        $n += estimateOperandTreeSize($stmt->scrutinee);
    }

    return $n;
}

function isPeFusionInlineCandidate(IR\FunctionDecl $function): bool
{
    if ($function->foreign || $function->ioEffect) {
        return false;
    }

    if ($function->kind !== IR\FunctionKind::SpecClone) {
        return false;
    }

    $items = denseStmtItems($function->body->items);
    if ($items === [] || \count($items) > 12) {
        return false;
    }

    $last = lastStmtItem($items);
    if (! ($last instanceof IR\Ret || $last instanceof IR\MatchReturn)) {
        return false;
    }

    if (functionBodyCallsSelf($items, $function->name)) {
        return false;
    }

    $flat = 0;
    $visit = static function (array $stmts) use (&$visit, &$flat): bool {
        foreach (denseStmtItems($stmts) as $item) {
            if ($item instanceof IR\Loop || $item instanceof IR\TailRecall || $item instanceof IR\DictCall) {
                return false;
            }
            ++$flat;
            if ($flat > 24) {
                return false;
            }
            if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
                if (\count($item->arms) > 4) {
                    return false;
                }
                foreach ($item->arms as $arm) {
                    if (! $visit($arm->body->items)) {
                        return false;
                    }
                }
            }
        }

        return true;
    };

    return $visit($items);
}

/** @param array<string, IR\FunctionDecl> $index */
function qualifyBareCalleesInItems(array $items, string $module, array $index = []): array
{
    $out = [];
    foreach (denseStmtItems($items) as $item) {
        $out[] = qualifyBareCalleesInStmt($item, $module, $index);
    }

    return $out;
}

/** @param array<string, IR\FunctionDecl> $index */
function qualifyBareCalleesInOperand(IR\Operand $operand, string $module, array $index = []): IR\Operand
{
    if ($operand instanceof IR\FnRef) {
        return new IR\FnRef(qualifyBareName($operand->name, $module, $index));
    }
    if ($operand instanceof IR\Intrinsic) {
        return new IR\Intrinsic(
            $operand->name,
            \array_map(static fn (IR\Operand $a): IR\Operand => qualifyBareCalleesInOperand($a, $module, $index), $operand->args),
        );
    }
    if ($operand instanceof IR\ListLit) {
        return new IR\ListLit(\array_map(
            static fn (IR\Operand $a): IR\Operand => qualifyBareCalleesInOperand($a, $module, $index),
            $operand->elements,
        ));
    }
    return $operand;
}

/** @param array<string, IR\FunctionDecl> $index */
function qualifyBareCalleesInStmt(IR\Stmt $stmt, string $module, array $index = []): IR\Stmt
{
    if ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\MatchReturn) {
        $arms = [];
        foreach ($stmt->arms as $arm) {
            $arms[] = new IR\MatchArm(
                $arm->pattern,
                new IR\Block(qualifyBareCalleesInItems($arm->body->items, $module, $index)),
                mapGuards(
                    $arm->guards,
                    static fn (IR\Operand $cond): IR\Operand => qualifyBareCalleesInOperand($cond, $module, $index),
                    static fn (IR\Stmt $item): IR\Stmt => qualifyBareCalleesInStmt($item, $module, $index),
                ),
            );
        }

        return $stmt instanceof IR\MatchStmt
            ? new IR\MatchStmt($stmt->scrutinee, $arms, $stmt->dest, $stmt->exhaustive)
            : new IR\MatchReturn($stmt->scrutinee, $arms, $stmt->exhaustive);
    }

    return match ($stmt::class) {
        IR\Call::class => new IR\Call(
            qualifyBareName($stmt->callee, $module, $index),
            \array_map(static fn (IR\Operand $a): IR\Operand => qualifyBareCalleesInOperand($a, $module, $index), $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\Ret::class => new IR\Ret(qualifyBareCalleesInOperand($stmt->value, $module, $index)),
        IR\Assign::class => new IR\Assign($stmt->dest, qualifyBareCalleesInOperand($stmt->value, $module, $index)),
        IR\Let::class => new IR\Let($stmt->name, qualifyBareCalleesInOperand($stmt->value, $module, $index)),
        IR\CallValue::class => new IR\CallValue(
            qualifyBareCalleesInOperand($stmt->callee, $module, $index),
            \array_map(static fn (IR\Operand $a): IR\Operand => qualifyBareCalleesInOperand($a, $module, $index), $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        default => $stmt,
    };
}

/** @param array<string, IR\FunctionDecl> $index */
function qualifyBareName(string $name, string $module, array $index = []): string
{
    if ($name === '' || \str_contains($name, '::') || \str_contains($name, '\\')) {
        return $name;
    }
    if (isPlausibleConstructorName($name)) {
        return $name;
    }
    if ($index !== [] && ! isset($index[$name])) {
        return $name;
    }

    return resolvedSymbol($module, $name);
}

function specializationLookupNameSafe(string $callee): string
{
    $parsed = parseResolvedSymbol($callee);

    return $parsed !== null ? $parsed['name'] : $callee;
}

function tryInlinePeFusionBody(IR\FunctionDecl $target, IR\Call $call, int &$nextTemp): ?array
{
    $body = denseStmtItems($target->body->items);
    $remap = buildTempRemap($body, $nextTemp);
    if ($remap !== []) {
        $nextTemp = \max($remap) + 1;
    }
    $body = denseStmtItems(remapTempsInItems($body, $remap));
    $args = $call->args;

    $bound = [];
    $out = [];
    $lastIdx = \count($body) - 1;
    foreach ($body as $i => $item) {
        if ($i === $lastIdx && $item instanceof IR\Ret) {
            $value = substituteExpr($item->value, $target->params, $args, $bound);
            foreach (inlineExprAsItems($value, $call->dest) as $stmt) {
                $out[] = $stmt;
            }

            continue;
        }
        if ($i === $lastIdx && $item instanceof IR\MatchReturn) {
            $scrutinee = substituteOperand($item->scrutinee, $target->params, $args, $bound);
            $arms = [];
            foreach ($item->arms as $arm) {
                $armBound = $bound;
                foreach (irPatternBoundNames($arm->pattern) as $name) {
                    $armBound[$name] = true;
                }
                $armItems = [];
                foreach (denseStmtItems($arm->body->items) as $armItem) {
                    $armItems[] = substituteStmt($armItem, $target->params, $args, $armBound);
                    if ($armItem instanceof IR\Let) {
                        $armBound[$armItem->name] = true;
                    }
                }
                $armLast = $armItems !== [] ? $armItems[\count($armItems) - 1] : null;
                if ($armLast instanceof IR\Ret) {
                    \array_pop($armItems);
                    foreach (inlineExprAsItems($armLast->value, $call->dest) as $stmt) {
                        $armItems[] = $stmt;
                    }
                } else {
                    $armItems = alignInlineCallDest($armItems, $call->dest);
                }
                $arms[] = new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(denseStmtItems($armItems)),
                    substituteGuards($arm->guards, $target->params, $args, $armBound),
                );
            }
            $out[] = new IR\MatchStmt($scrutinee, $arms, $call->dest, $item->exhaustive);

            continue;
        }

        $out[] = substituteStmt($item, $target->params, $args, $bound);
        if ($item instanceof IR\Let) {
            $bound[$item->name] = true;
        }
    }

    return denseStmtItems($out);
}
