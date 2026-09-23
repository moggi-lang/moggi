<?php declare(strict_types=1);

namespace Moggi\Optimize\Interproc;

use Moggi\IR;

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
use function Moggi\Optimize\CaseFold\nullaryCtorCaf;
use function Moggi\Optimize\Support\isLambdaName;
use function Moggi\Optimize\Support\isWiredInBoolCtor;
use function Moggi\Optimize\Support\mapStmtNestedBlocks;
use function Moggi\Optimize\Support\tempUsedInItems;

/** @param array<int, mixed> $items @return list<IR\Stmt> */
function denseStmtItems(array $items): array
{
    // Fast path: the overwhelming majority of inputs are already dense
    // statement lists (contiguous integer keys, every entry a tagged stmt), so
    // detect that and return the original array instead of rebuilding a copy on
    // every one of the many inliner passes.
    $dense = array_is_list($items);
    if ($dense) {
        foreach ($items as $item) {
            if (!$item instanceof IR\Stmt) {
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

    return $items[count($items) - 1];
}

/** @param list<IR\FunctionDecl> $functions @return array<string, IR\FunctionDecl> */
function indexFunctions(array $functions, string $moduleName = ''): array
{
    $index = [];
    foreach ($functions as $function) {
        $index[$function->name] = $function;
        // Specialize qualifies same-module callees as `Module::name`; dual-index
        // so post-specialize inline / match-wrapper PE still finds them.
        if ($moduleName !== '' && !str_contains($function->name, '::')) {
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
    $pe = activePeFusionIndex();
    if (isset($pe[$callee])) {
        return $pe[$callee];
    }

    // Only resolve `Module::__spec_*` / `Module::__ev_*` to a local short name
    // — never bare names like `map`, which collide across modules.
    $leaf = specializationLookupNameSafe($callee);
    if ($leaf !== $callee && (str_starts_with($leaf, '__spec_') || str_starts_with($leaf, '__ev_'))) {
        if (isset($index[$leaf])) {
            return $index[$leaf];
        }
        if (isset($pe[$leaf])) {
            return $pe[$leaf];
        }
    }

    return null;
}

/** @var array<string, IR\FunctionDecl> */
function setActivePeFusionIndex(array $index): void
{
    $GLOBALS['__moggi_pe_fusion_index'] = $index;
}

function clearActivePeFusionIndex(): void
{
    unset($GLOBALS['__moggi_pe_fusion_index']);
}

/** @return array<string, IR\FunctionDecl> */
function activePeFusionIndex(): array
{
    $idx = $GLOBALS['__moggi_pe_fusion_index'] ?? [];

    return \is_array($idx) ? $idx : [];
}

/** @param list<IR\FunctionDecl> $functions */
function inlineFunctions(array $functions, string $moduleName = ''): array
{
    for ($round = 0; $round < 4; ++$round) {
        $index = indexFunctions($functions, $moduleName);
        // Mutual recursion (e.g. where-bound even/odd) must not be inlined into
        // itself across SCC members — that unrolls into nested intSub# towers.
        $recursive = functionNamesInRecursiveSccs($index);
        $next = [];
        $changed = false;
        /** @var array<string, true> $rewrittenNames */
        $rewrittenNames = [];
        $boundNames = [];
        foreach ($index as $name => $callee) {
            $boundNames[$name] = functionBoundNames($callee);
        }
        setActiveInlineTargetBoundNames($boundNames);

        foreach ($functions as $function) {
            if ($function->ioStraightLine) {
                $next[] = $function;
                continue;
            }

            $peBlocked = [];
            $nextTemp = null;
            setActiveInlineProtectedNames(array_fill_keys(
                [...$function->params, ...collectLocalsInBlock($function->body)],
                true,
            ));
            $inlinedBody = inlineBlock(
                $function->body,
                $function->name,
                $index,
                $recursive,
                0,
                $peBlocked,
                $nextTemp,
                $rewrittenNames,
            );
            clearActiveInlineProtectedNames();
            $joined = new IR\Block(joinMatchStmtConsumer($inlinedBody->items));
            if ($joined !== $function->body) {
                $changed = true;
            }

            $next[] = $function->withBody($joined);
        }

        // Sync mutated callees from `$index` back into `$next`.
        clearActiveInlineTargetBoundNames();

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

        if (!$changed) {
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
 * @return array<string, true>
 */
function functionNamesInRecursiveSccs(array $index): array
{
    /** @var array<string, list<string>> $edges */
    $edges = [];
    foreach ($index as $name => $function) {
        $callees = [];
        collectLocalCalleesInBlock($function->body, $index, $callees);
        $edges[$name] = \array_keys($callees);
    }

    // Tarjan SCC; any component with a self-loop or size > 1 is recursive.
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
            if (!isset($indexOf[$w])) {
                $strongconnect($w);
                $lowlink[$v] = min($lowlink[$v], $lowlink[$w]);
            } elseif (isset($onStack[$w])) {
                $lowlink[$v] = min($lowlink[$v], $indexOf[$w]);
            }
        }

        if ($lowlink[$v] === $indexOf[$v]) {
            $component = [];
            do {
                $w = \array_pop($stack);
                unset($onStack[$w]);
                $component[] = $w;
            } while ($w !== $v);

            $isRecursive = count($component) > 1;
            if (!$isRecursive) {
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
        if (!isset($indexOf[$name])) {
            $strongconnect($name);
        }
    }

    return $recursive;
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @param array<string, true> $callees
 */
function collectLocalCalleesInBlock(IR\Block $block, array $index, array &$callees): void
{
    foreach ($block->items as $item) {
        collectLocalCalleesInStmt($item, $index, $callees);
    }
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @param array<string, true> $callees
 */
function collectLocalCalleesInStmt(IR\Stmt $stmt, array $index, array &$callees): void
{
    if ($stmt instanceof IR\Call && isset($index[$stmt->callee])) {
        $callees[$stmt->callee] = true;
    }

    if ($stmt instanceof IR\Ret && $stmt->value instanceof IR\ExprCall && isset($index[$stmt->value->callee])) {
        $callees[$stmt->value->callee] = true;
    }

    if ($stmt instanceof IR\Assign || $stmt instanceof IR\Let) {
        if ($stmt->value instanceof IR\ExprCall && isset($index[$stmt->value->callee])) {
            $callees[$stmt->value->callee] = true;
        }
        if ($stmt->value instanceof IR\FnRef && isset($index[$stmt->value->name])) {
            // Reference alone is not a call edge for SCC purposes.
        }
    }

    if ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\MatchReturn) {
        foreach ($stmt->arms as $arm) {
            collectLocalCalleesInBlock($arm->body, $index, $callees);
        }
    }

    if ($stmt instanceof IR\Loop) {
        collectLocalCalleesInBlock($stmt->body, $index, $callees);
    }
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @param array<string, true> $recursive
 * @param array<string, true> $peBlocked
 * @param-out int $nextTemp
 */
function inlineBlock(
    IR\Block $block,
    string $selfName,
    array $index,
    array $recursive = [],
    int $depth = 0,
    array &$peBlocked = [],
    ?int &$nextTemp = null,
): IR\Block {
    // Temps are single-assignment: nested match-arm inlineBlock calls must not recompute
    // nextTemp from the arm alone (it reused outer dests and poisoned bytesFromString#).
    $localMax = maxTempInItems($block->items) + 1;
    if ($nextTemp === null) {
        $nextTemp = $localMax;
    } else {
        $nextTemp = max($nextTemp, $localMax);
    }

    return new IR\Block(inlineItems($block->items, $selfName, $index, $nextTemp, $recursive, $depth, $peBlocked));
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, IR\FunctionDecl> $index
 * @param array<string, true> $recursive
 * @param array<string, true> $peBlocked
 * @return list<IR\Stmt>
 */
function inlineItems(
    array $items,
    string $selfName,
    array $index,
    int &$nextTemp,
    array $recursive = [],
    int $depth = 0,
    array &$peBlocked = [],
): array {
    $out = [];

    foreach (denseStmtItems(flattenNestedRetExprCalls($items, $nextTemp)) as $item) {
        // Reduce local match-wrapper ExprCalls nested in Call args (i.e. after
        // flattening a nested call into a call to an imported function that
        // still carries ExprCall args).
        if ($item instanceof IR\Call) {
            $item = new IR\Call(
                $item->callee,
                \array_map(
                    static fn (IR\Operand $arg): IR\Operand => inlineCallsInExpr(
                        $arg,
                        $selfName,
                        $index,
                        $nextTemp,
                        $recursive,
                    ),
                    $item->args,
                ),
                $item->dest,
                $item->srcLoc,
            );
        }

        if ($item instanceof IR\Call) {
            $target = lookupIndexedFunction($index, $item->callee);
            if ($target !== null) {
                $inlined = tryInlineCall($target, $item, $selfName, $index, $nextTemp, $recursive);
                if ($inlined !== null) {
                    foreach (denseStmtItems($inlined) as $inlinedItem) {
                        $out[] = $inlinedItem;
                    }
                    continue;
                }
            }
        }

        $out[] = inlineStmt($item, $selfName, $index, $recursive, $depth, $peBlocked, $nextTemp);
    }

    return denseStmtItems(inlineNestedRetCalls(
        inlineRetCalls($out, $selfName, $index, $nextTemp, $recursive),
        $selfName,
        $index,
        $nextTemp,
        $recursive,
    ));
}

/**
 * Turn `ret call f(call g(x), …)` into statement Calls so match-wrappers can inline.
 *
 * @param list<IR\Stmt> $items
 * @return list<IR\Stmt>
 */
function flattenNestedRetExprCalls(array $items, int &$nextTemp): array
{
    $out = [];
    foreach (denseStmtItems($items) as $item) {
        if (!($item instanceof IR\Ret) || !($item->value instanceof IR\ExprCall)) {
            if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
                $out[] = match ($item::class) {
                    IR\MatchStmt::class => new IR\MatchStmt(
                        $item->scrutinee,
                        \array_map(
                            static function (IR\MatchArm $arm) use (&$nextTemp): IR\MatchArm {
                                return new IR\MatchArm(
                                    $arm->pattern,
                                    new IR\Block(flattenNestedRetExprCalls($arm->body->items, $nextTemp)),
                                    $arm->guards,
                                );
                            },
                            $item->arms,
                        ),
                        $item->dest,
                        $item->exhaustive,
                    ),
                    IR\MatchReturn::class => new IR\MatchReturn(
                        $item->scrutinee,
                        \array_map(
                            static function (IR\MatchArm $arm) use (&$nextTemp): IR\MatchArm {
                                return new IR\MatchArm(
                                    $arm->pattern,
                                    new IR\Block(flattenNestedRetExprCalls($arm->body->items, $nextTemp)),
                                    $arm->guards,
                                );
                            },
                            $item->arms,
                        ),
                        $item->exhaustive,
                    ),
                };
                continue;
            }
            $out[] = $item;
            continue;
        }

        $args = [];
        $prefix = [];
        $changed = false;
        foreach ($item->value->args as $arg) {
            if ($arg instanceof IR\ExprCall) {
                $dest = $nextTemp++;
                $prefix[] = new IR\Call($arg->callee, $arg->args, $dest, $arg->srcLoc);
                $args[] = new IR\Temp($dest);
                $changed = true;
            } else {
                $args[] = $arg;
            }
        }

        if (!$changed) {
            $out[] = $item;
            continue;
        }

        foreach ($prefix as $stmt) {
            $out[] = $stmt;
        }
        $out[] = new IR\Ret(new IR\ExprCall($item->value->callee, $args, $item->value->srcLoc));
    }

    return denseStmtItems($out);
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, IR\FunctionDecl> $index
 * @return list<IR\Stmt>
 */
function inlineNestedRetCalls(array $items, string $selfName, array $index, int &$nextTemp): array
{
    return \array_map(
        static function (IR\Stmt $item) use ($selfName, $index, &$nextTemp): IR\Stmt {
            if (!($item instanceof IR\Ret)) {
                return $item;
            }

            return new IR\Ret(inlineCallsInExpr($item->value, $selfName, $index, $nextTemp));
        },
        denseStmtItems($items),
    );
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @param IR\Operand $expr
 */
function inlineCallsInExpr(IR\Operand $expr, string $selfName, array $index, int &$nextTemp): IR\Operand
{
    if ($expr instanceof IR\ExprCall && isset($index[$expr->callee])) {
        $fakeCall = new IR\Call($expr->callee, $expr->args, $nextTemp++, $expr->srcLoc);
        $inlined = tryInlineCall($index[$expr->callee], $fakeCall, $selfName, $index, $nextTemp);
        if ($inlined !== null) {
            // Fold prefix temp bindings into the Ret. Taking only the last Ret
            // dropped statements like `t2 = listMap(c2w, cs)` and left
            // `pack(t2)` with an unbound temp (Char8.dropSpace / JVM emit).
            $folded = foldInlinedItemsToExpr($inlined);
            if ($folded !== null) {
                return inlineCallsInExpr($folded, $selfName, $index, $nextTemp);
            }
        }
    }

    if ($expr instanceof IR\ExprBinop) {
        return new IR\ExprBinop(
            $expr->op,
            inlineCallsInExpr($expr->left, $selfName, $index, $nextTemp),
            inlineCallsInExpr($expr->right, $selfName, $index, $nextTemp),
        );
    }

    if ($expr instanceof IR\ExprCallValue) {
        return new IR\ExprCallValue(
            inlineCallsInExpr($expr->callee, $selfName, $index, $nextTemp),
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => inlineCallsInExpr($arg, $selfName, $index, $nextTemp),
                $expr->args,
            ),
            $expr->srcLoc,
        );
    }

    return $expr;
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, IR\FunctionDecl> $index
 * @return list<IR\Stmt>
 */
function inlineRetCalls(array $items, string $selfName, array $index, int &$nextTemp): array
{
    $out = [];

    foreach (denseStmtItems($items) as $item) {
        if (!($item instanceof IR\Ret) || !($item->value instanceof IR\ExprCall)) {
            // Also lift nested ExprCalls in Call args so match wrappers like
            // gRecordPairsLeft(K1(…)) become statement Calls (inlinable).
            if ($item instanceof IR\Call) {
                $args = [];
                $prefix = [];
                $changed = false;
                foreach ($item->args as $arg) {
                    if ($arg instanceof IR\ExprCall) {
                        $dest = $nextTemp++;
                        $prefix[] = new IR\Call($arg->callee, $arg->args, $dest, $item->srcLoc);
                        $args[] = new IR\Temp($dest);
                        $changed = true;
                    } else {
                        $args[] = $arg;
                    }
                }
                if ($changed) {
                    foreach ($prefix as $stmt) {
                        $out[] = $stmt;
                    }
                    $out[] = new IR\Call($item->callee, $args, $item->dest, $item->srcLoc);
                    continue;
                }
            }
            $out[] = $item;
            continue;
        }

        $callee = $item->value->callee;
        if (!isset($index[$callee])) {
            $out[] = $item;
            continue;
        }

        $target = $index[$callee];
        $fakeCall = new IR\Call($callee, $item->value->args, 0);
        $inlined = tryInlineCall($target, $fakeCall, $selfName, $index, $nextTemp);
        if ($inlined === null) {
            $out[] = $item;
            continue;
        }

        $converted = inlinedItemsToRet($inlined);
        if ($converted !== null) {
            foreach (denseStmtItems($converted) as $convertedItem) {
                $out[] = $convertedItem;
            }
            continue;
        }

        // Match-wrapper inlining yields MatchStmt; convert to MatchReturn in ret position.
        $inlined = denseStmtItems($inlined);
        if (count($inlined) === 1 && $inlined[0] instanceof IR\MatchStmt) {
            $ms = $inlined[0];
            $arms = [];
            foreach ($ms->arms as $arm) {
                $arms[] = new IR\MatchArm(
                    $arm->pattern,
                    new IR\Block(matchArmAssignsToRets($arm->body->items, $ms->dest)),
                    $arm->guards,
                );
            }
            $out[] = new IR\MatchReturn($ms->scrutinee, $arms, $ms->exhaustive);
            continue;
        }

        $out[] = $item;
    }

    return denseStmtItems($out);
}

/**
 * What the inline pass running right now has to avoid rebinding.
 *
 * One pass, deep recursive rewrites, so the context rides along here rather
 * than through every `inlineBlock`/`tryInlineCall` signature.
 */
function &inlinePassState(): array
{
    static $state = ['protected' => [], 'targetBound' => []];

    return $state;
}

/**
 * Names the function being inlined *into* reads or binds for itself.
 *
 * An inlined body brings its match-arm binders and `let` names into the
 * caller's scope verbatim, so any of them that appear there would take over the
 * caller's value, and the function is left alone.
 *
 * @param array<string, true> $names
 */
function setActiveInlineProtectedNames(array $names): void
{
    inlinePassState()['protected'] = $names;
}

function clearActiveInlineProtectedNames(): void
{
    inlinePassState()['protected'] = [];
}

/** @return array<string, true> */
function activeInlineProtectedNames(): array
{
    return inlinePassState()['protected'];
}

/**
 * Bound names per candidate callee, computed once per pass.
 *
 * @param array<string, list<string>> $names
 */
function setActiveInlineTargetBoundNames(array $names): void
{
    inlinePassState()['targetBound'] = $names;
}

function clearActiveInlineTargetBoundNames(): void
{
    inlinePassState()['targetBound'] = [];
}

/** @return array<string, list<string>> */
function activeInlineTargetBoundNames(): array
{
    return inlinePassState()['targetBound'];
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
    $protected = activeInlineProtectedNames();
    if ($protected === []) {
        return false;
    }

    $bound = activeInlineTargetBoundNames()[$target->name] ?? functionBoundNames($target);
    foreach ($bound as $name) {
        if (isset($protected[$name])) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @return list<IR\Stmt>|null
 */
function tryInlineCall(IR\FunctionDecl $target, IR\Stmt $call, string $selfName, array $index, int &$nextTemp): ?array
{
    if ($target->foreign) {
        return null;
    }

    if ($target->ioEffect) {
        return null;
    }

    if ($target->name === $selfName || !isInlineCandidate($target)) {
        return null;
    }

    if (inliningWouldRebindCallerName($target)) {
        return null;
    }

    if (bodyReferencesCapturedLambdas($target, $index)) {
        return null;
    }

    if (count($call->args) !== count($target->params)) {
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

    if (isMatchWrapperCandidate($target)) {
        $inlined = tryInlineMatchWrapper($target, $call, $nextTemp);
        if ($inlined === null) {
            return null;
        }
        $srcModule = calleeModuleName($call->callee);
        if ($srcModule !== null && $srcModule !== '') {
            $inlined = qualifyBareCalleesInItems($inlined, $srcModule, $index);
        }

        return $inlined;
    }

    if (isPeFusionInlineCandidate($target)) {
        return tryInlinePeFusionBody($target, $call, $nextTemp);
    }

    $body = denseStmtItems($target->body->items);
    $last = lastStmtItem($body);
    if ($last === null || !($last instanceof IR\Ret)) {
        return null;
    }

    $remap = buildTempRemap($body, $nextTemp);
    if ($remap !== []) {
        $nextTemp = max($remap) + 1;
    }
    $body = denseStmtItems(remapTempsInItems($target->body->items, $remap));
    $srcModule = calleeModuleName($call->callee);
    if ($srcModule !== null && $srcModule !== '') {
        $body = qualifyBareCalleesInItems($body, $srcModule, $index);
    }
    $last = lastStmtItem($body);
    if ($last === null || !($last instanceof IR\Ret)) {
        return null;
    }

    // Materialize effectful / allocating args once before substitute so
    // nullary foreign constructors (e.g. newStringWriter) are not
    // rematerialized at every param use after inlining.
    $materialized = materializeInlineArgs($call->args, $nextTemp);
    $prefix = $materialized['prefix'];
    $args = $materialized['args'];
    $nextTemp = $materialized['nextTemp'];

    $bound = [];
    foreach (\array_slice($body, 0, -1) as $item) {
        $prefix[] = substituteStmt($item, $target->params, $args, $bound);
        if ($item instanceof IR\Let) {
            $bound[$item->name] = true;
        }
    }
    // $body (and $last) are already remapped; do not apply $remap again.
    $value = substituteExpr($last->value, $target->params, $args, $bound);

    $inlined = denseStmtItems([...$prefix, ...inlineExprAsItems($value, $call->dest)]);

    return alignInlineCallDest($inlined, $call->dest);
}

/**
 * Bind ExprCall / ForeignCall args to temps so substitute cannot duplicate them.
 *
 * @param list<IR\Operand> $args
 * @return array{prefix: list<IR\Stmt>, args: list<IR\Operand>, nextTemp: int}
 */
function materializeInlineArgs(array $args, int $nextTemp): array
{
    $prefix = [];
    $out = [];
    foreach ($args as $arg) {
        if ($arg instanceof IR\ExprCall || $arg instanceof IR\ForeignCall || $arg instanceof IR\ExprCallValue) {
            $dest = $nextTemp++;
            $prefix[] = new IR\Assign($dest, $arg);
            $out[] = new IR\Temp($dest);
            continue;
        }
        // Nullary function-as-value (e.g. newLinkedHashMap): emit invokes the
        // FnRef at every use. Bind once so substitute / expr-fold cannot
        // allocate a fresh host object per occurrence.
        if ($arg instanceof IR\FnRef && operandIsNonDuplicable($arg)) {
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
function tryInlineMatchWrapper(IR\FunctionDecl $target, IR\Stmt $call, int &$nextTemp): ?array
{
    $items = denseStmtItems($target->body->items);
    $match = $items[0];
    if (!($match instanceof IR\MatchReturn)) {
        return null;
    }

    $remap = buildTempRemap(matchReturnTempItems($match), $nextTemp);
    if ($remap !== []) {
        $nextTemp = max($remap) + 1;
    }

    $match = remapTempsInStmt($match, $remap);
    if (!($match instanceof IR\MatchReturn)) {
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

    $last = $items[count($items) - 1];
    if ($last instanceof IR\Ret) {
        array_pop($items);
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
    if (!($arg instanceof IR\FnRef) || !isLambdaName($arg->name)) {
        return false;
    }

    $lambda = $index[$arg->name] ?? null;
    if ($lambda === null) {
        return false;
    }

    foreach (collectLocalsInBlock($lambda->body) as $local) {
        if (!\in_array($local, $lambda->params, true)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, IR\FunctionDecl> $index */
function bodyReferencesCapturedLambdas(IR\FunctionDecl $function, array $index): bool
{
    foreach (collectLambdaRefsInBlock($function->body, isLambdaName(...)) as $lambdaName) {
        $lambda = $index[$lambdaName] ?? null;
        if ($lambda === null) {
            continue;
        }
        foreach (collectLocalsInBlock($lambda->body) as $local) {
            if (!\in_array($local, $lambda->params, true)) {
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

    if (!isset($last->dest) || $last->dest === $callDest) {
        return $inlined;
    }

    return [...$inlined, new IR\Assign($callDest, new IR\Temp($last->dest))];
}

/** @param list<IR\Stmt> $items @return list<IR\Stmt> */
function matchArmAssignsToRets(array $items, int $dest): array
{
    $items = denseStmtItems($items);
    if ($items === []) {
        return $items;
    }

    $last = $items[count($items) - 1];
    if ($last instanceof IR\Assign && $last->dest === $dest) {
        array_pop($items);
        $items[] = new IR\Ret($last->value);

        return denseStmtItems($items);
    }

    if ($last instanceof IR\Call && $last->dest === $dest) {
        array_pop($items);
        $items[] = new IR\Ret(new IR\ExprCall($last->callee, $last->args));

        return denseStmtItems($items);
    }

    if ($last instanceof IR\CallValue && $last->dest === $dest) {
        array_pop($items);
        $items[] = new IR\Ret(new IR\ExprCallValue($last->callee, $last->args));

        return denseStmtItems($items);
    }

    if ($last instanceof IR\Ret) {
        return $items;
    }

    return $items;
}

/** @param list<IR\Stmt> $inlined @return list<IR\Stmt>|null */
function inlinedItemsToRet(array $inlined): ?array
{
    $inlined = denseStmtItems($inlined);
    if ($inlined === []) {
        return null;
    }

    $last = lastStmtItem($inlined);
    if ($last === null) {
        return null;
    }

    // DictCall defines $dest; dropping it and returning Temp(dest) leaves an
    // unbound temp. Keep the defining statement, then Ret the dest.
    if ($last instanceof IR\DictCall) {
        return denseStmtItems([...$inlined, new IR\Ret(new IR\Temp($last->dest))]);
    }

    $ret = match ($last::class) {
        IR\Binop::class => new IR\Ret(new IR\ExprBinop($last->op, $last->left, $last->right)),
        IR\Assign::class => new IR\Ret($last->value),
        IR\Call::class => new IR\Ret(new IR\ExprCall($last->callee, $last->args, $last->srcLoc)),
        IR\CallValue::class => new IR\Ret(new IR\ExprCallValue($last->callee, $last->args, $last->srcLoc)),
        default => null,
    };
    if ($ret === null) {
        return null;
    }

    return denseStmtItems([...\array_slice($inlined, 0, -1), $ret]);
}

/**
 * Collapse a statement-form inline into one operand for expression contexts.
 * Prefix temp defs are substituted into the Ret value; refuses if any stmt
 * cannot be represented as a pure expression binding.
 *
 * @param list<IR\Stmt> $inlined
 */
function foldInlinedItemsToExpr(array $inlined): ?IR\Operand
{
    $asRet = inlinedItemsToRet($inlined);
    if ($asRet === null) {
        return null;
    }

    return reduceMatchArmBodyToExpr($asRet, [], [], []);
}

/** @return list<IR\Stmt> */
function inlineExprAsItems(IR\Operand $expr, int $dest): array
{
    return match ($expr::class) {
        IR\ExprBinop::class => [new IR\Binop($expr->op, $expr->left, $expr->right, $dest)],
        IR\ExprCall::class => [new IR\Call($expr->callee, $expr->args, $dest, $expr->srcLoc)],
        IR\ExprCallValue::class => [new IR\CallValue($expr->callee, $expr->args, $dest, $expr->srcLoc)],
        default => [new IR\Assign($dest, $expr)],
    };
}

/**
 * Expression-level case-of-known for match wrappers: when the wrapper's
 * scrutinee is a concrete constructor application at the call site, return the
 * matching arm's Ret value with pattern binders substituted.
 *
 * Multi-statement arms are reduced when every prefix statement is a simple
 * value binding (Assign / Call / CallValue / Let / Binop) that can be folded
 * into the Ret expression — the common shape of specialized record-field
 * encoders after dictionary specialization.
 *
 * @param list<IR\Operand> $args
 */
function tryReduceMatchWrapperToExpr(IR\FunctionDecl $target, array $args): ?IR\Operand
{
    if (!isMatchWrapperCandidate($target) || count($args) !== count($target->params)) {
        return null;
    }

    $items = denseStmtItems($target->body->items);
    $match = $items[0];
    if (!($match instanceof IR\MatchReturn) || count($match->arms) === 0) {
        return null;
    }

    $bound = [];
    $scrutinee = substituteOperand($match->scrutinee, $target->params, $args, $bound);
    if (!($scrutinee instanceof IR\ExprCall) || $scrutinee->args === []) {
        return null;
    }
    if ($scrutinee->callee === '' || str_starts_with($scrutinee->callee, '__')) {
        return null;
    }

    foreach ($match->arms as $arm) {
        $pat = $arm->pattern;
        if (!($pat instanceof IR\PatCon) || $pat->name !== $scrutinee->callee) {
            continue;
        }
        if (count($pat->args) !== count($scrutinee->args)) {
            continue;
        }

        $envParams = $target->params;
        $envArgs = $args;
        $patParams = [];
        $patArgs = [];
        foreach ($pat->args as $i => $subPat) {
            if (!($subPat instanceof IR\PatVar)) {
                return null;
            }
            $patParams[] = $subPat->name;
            $patArgs[] = $scrutinee->args[$i];
        }

        $armBound = $bound;
        foreach (matchArmPatternBoundNames($pat) as $name) {
            // Protect pattern binders from outer-param substitution when names
            // shadow (e.g. both the wrapper param and K1 binder are `x`).
            $armBound[$name] = true;
        }

        $reduced = reduceMatchArmBodyToExpr($arm->body->items, $envParams, $envArgs, $armBound);
        if ($reduced !== null) {
            // Bind pattern vars to constructor payloads (armBound blocked this above).
            return substituteOperand($reduced, $patParams, $patArgs, []);
        }
    }

    return null;
}

/**
 * @param list<IR\Stmt> $items
 * @param list<string> $envParams
 * @param list<IR\Operand> $envArgs
 * @param array<string, true> $armBound
 */
function reduceMatchArmBodyToExpr(
    array $items,
    array $envParams,
    array $envArgs,
    array $armBound,
): ?IR\Operand {
    $armItems = denseStmtItems($items);
    if ($armItems === []) {
        return null;
    }

    $last = $armItems[count($armItems) - 1];
    if (!($last instanceof IR\Ret)) {
        return null;
    }

    // Refuse when a non-duplicable arg would be substituted at multiple Local
    // uses across the arm (not only the Ret). Expr-folding after that rematerializes
    // allocators like newLinkedHashMap at each use.
    foreach ($envParams as $i => $param) {
        $arg = $envArgs[$i] ?? null;
        if ($arg === null || !operandIsNonDuplicable($arg)) {
            continue;
        }
        if (countLocalUsesInItems($armItems, $param) > 1) {
            return null;
        }
    }

    /** @var array<int, IR\Operand> $tempEnv */
    $tempEnv = [];
    /** @var array<int, true> $sticky non-duplicable temps — do not expand early */
    $sticky = [];
    foreach (\array_slice($armItems, 0, -1) as $item) {
        $stmt = substituteStmt($item, $envParams, $envArgs, $armBound);
        if ($stmt instanceof IR\Let) {
            $val = replaceTempsInOperand($stmt->value, $tempEnv, $sticky);
            if (operandIsNonDuplicable($val) && countLocalUsesInItems($armItems, $stmt->name) > 1) {
                return null;
            }
            $envParams[] = $stmt->name;
            $envArgs[] = $val;
            $armBound[$stmt->name] = true;
            continue;
        }
        if ($stmt instanceof IR\Assign) {
            $val = replaceTempsInOperand($stmt->value, $tempEnv, $sticky);
            if (operandContainsNonDuplicable($val)) {
                $sticky[$stmt->dest] = true;
            }
            $tempEnv[$stmt->dest] = $val;
            continue;
        }
        if ($stmt instanceof IR\Call) {
            $val = new IR\ExprCall(
                $stmt->callee,
                \array_map(
                    static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv, $sticky),
                    $stmt->args,
                ),
            );
            if (operandContainsNonDuplicable($val)) {
                $sticky[$stmt->dest] = true;
            }
            $tempEnv[$stmt->dest] = $val;
            continue;
        }
        if ($stmt instanceof IR\CallValue) {
            $val = new IR\ExprCallValue(
                replaceTempsInOperand($stmt->callee, $tempEnv, $sticky),
                \array_map(
                    static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv, $sticky),
                    $stmt->args,
                ),
            );
            if (operandContainsNonDuplicable($val)) {
                $sticky[$stmt->dest] = true;
            }
            $tempEnv[$stmt->dest] = $val;
            continue;
        }
        if ($stmt instanceof IR\DictCall) {
            // Represent unresolved dictionary method calls as expressions so
            // case-of-known match wrappers (e.g. specialized gRecordPairs*) can
            // still PE when the arm body uses dict_call for field codecs.
            $val = new IR\ExprCallValue(
                new IR\DictMethod(
                    replaceTempsInOperand($stmt->evidence, $tempEnv, $sticky),
                    $stmt->method,
                ),
                \array_map(
                    static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv, $sticky),
                    $stmt->args,
                ),
            );
            if (operandContainsNonDuplicable($val)) {
                $sticky[$stmt->dest] = true;
            }
            $tempEnv[$stmt->dest] = $val;
            continue;
        }
        if ($stmt instanceof IR\Binop) {
            $val = new IR\ExprBinop(
                $stmt->op,
                replaceTempsInOperand($stmt->left, $tempEnv, $sticky),
                replaceTempsInOperand($stmt->right, $tempEnv, $sticky),
            );
            if (operandContainsNonDuplicable($val)) {
                $sticky[$stmt->dest] = true;
            }
            $tempEnv[$stmt->dest] = $val;
            continue;
        }

        return null;
    }

    $retOp = substituteOperand($last->value, $envParams, $envArgs, $armBound);
    foreach ($sticky as $id => $_) {
        $uses = countTempUsesInOperand($retOp, $id);
        foreach ($tempEnv as $otherId => $otherVal) {
            if ($otherId === $id) {
                continue;
            }
            $uses += countTempUsesInOperand($otherVal, $id);
        }
        if ($uses > 1) {
            return null;
        }
    }
    foreach ($tempEnv as $id => $value) {
        if (!operandIsNonDuplicable($value) || isset($sticky[$id])) {
            continue;
        }
        $uses = countTempUsesInOperand($retOp, $id);
        foreach ($tempEnv as $otherId => $otherVal) {
            if ($otherId === $id) {
                continue;
            }
            $uses += countTempUsesInOperand($otherVal, $id);
        }
        if ($uses > 1) {
            return null;
        }
    }

    return replaceTempsInOperand($retOp, $tempEnv, []);
}

/**
 * Host allocators / foreign seeds must not be copied into multiple use sites.
 * Only nullary FnRefs/calls rematerialize on emit (arity-0 → static call).
 * Higher-arity FnRefs are function values and are safe to duplicate.
 */
function operandIsNonDuplicable(IR\Operand $op): bool
{
    if ($op instanceof IR\ForeignCall) {
        return true;
    }
    if ($op instanceof IR\ExprCall && $op->args === []) {
        return nullaryFnNameIsNonDuplicableSeed($op->callee);
    }
    if ($op instanceof IR\FnRef) {
        return nullaryFnNameIsNonDuplicableSeed($op->name);
    }

    return false;
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

function nullaryFnNameIsNonDuplicableSeed(string $name): bool
{
    $leaf = specializationLookupNameSafe($name);
    if (isPlausibleConstructorName($leaf)) {
        return false;
    }
    if (nullaryCtorCaf($name) !== null) {
        return false;
    }
    // Evidence dictionaries / known multi-arg values are never nullary seeds.
    if (str_starts_with($leaf, '__ev_')) {
        return false;
    }
    $arity = activeFnArity($name);
    if ($arity === null) {
        $fn = lookupIndexedFunction(activePeFusionIndex(), $name);
        if ($fn !== null) {
            $arity = count($fn->params);
        }
    }
    if ($arity === null) {
        // Fail closed: an unknown nullary FnRef/call may be a foreign seed
        // (e.g. a host-writer constructor). Expanding it at every use
        // rematerializes a fresh host object. Pure unknowns only lose a
        // copy-prop; that is preferable to silent double-allocation.
        return true;
    }

    return $arity === 0;
}

/** @param array<string, int> $arity */
function setActiveFnArity(array $arity): void
{
    $GLOBALS['__moggi_active_fn_arity'] = $arity;
}

function clearActiveFnArity(): void
{
    unset($GLOBALS['__moggi_active_fn_arity']);
}

function activeFnArity(string $name): ?int
{
    $map = $GLOBALS['__moggi_active_fn_arity'] ?? null;
    if (!\is_array($map)) {
        return null;
    }
    if (isset($map[$name]) && \is_int($map[$name])) {
        return $map[$name];
    }
    $leaf = specializationLookupNameSafe($name);
    if ($leaf !== $name && isset($map[$leaf]) && \is_int($map[$leaf])) {
        return $map[$leaf];
    }

    return null;
}

function operandContainsNonDuplicable(IR\Operand $op): bool
{
    $found = false;
    walkOperand($op, static function (IR\Operand $inner) use (&$found): void {
        if (operandIsNonDuplicable($inner)) {
            $found = true;
        }
    });

    return $found;
}

/**
 * @param list<string> $params
 * @param list<IR\Operand> $args
 */
function substitutingWouldDuplicateNonDuplicable(array $params, array $args, IR\Operand $body): bool
{
    foreach ($params as $i => $param) {
        $arg = $args[$i] ?? null;
        if ($arg === null || !operandIsNonDuplicable($arg)) {
            continue;
        }
        if (countLocalUsesInOperand($body, $param) > 1) {
            return true;
        }
    }

    return false;
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

function countTempUsesInOperand(IR\Operand $operand, int $id): int
{
    $n = 0;
    walkOperand($operand, static function (IR\Operand $op) use (&$n, $id): void {
        if ($op instanceof IR\Temp && $op->id === $id) {
            ++$n;
        }
    });

    return $n;
}

function countLocalUsesInOperand(IR\Operand $operand, string $name): int
{
    $n = 0;
    walkOperand($operand, static function (IR\Operand $op) use (&$n, $name): void {
        if ($op instanceof IR\Local && $op->name === $name) {
            ++$n;
        }
    });

    return $n;
}

/** @param array<int, IR\Operand> $tempEnv @param array<int, true> $sticky */
function replaceTempsInOperand(IR\Operand $operand, array $tempEnv, array $sticky = []): IR\Operand
{
    if ($tempEnv === []) {
        return $operand;
    }

    if ($operand instanceof IR\Temp && isset($tempEnv[$operand->id])) {
        // Sticky non-duplicable bindings stay as Temp so multi-use can be
        // detected; final expansion happens only when uses ≤ 1.
        if (isset($sticky[$operand->id])) {
            return $operand;
        }

        return replaceTempsInOperand($tempEnv[$operand->id], $tempEnv, $sticky);
    }

    if ($operand instanceof IR\ExprCall) {
        return new IR\ExprCall(
            $operand->callee,
            \array_map(
                static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv, $sticky),
                $operand->args,
            ),
            $operand->srcLoc,
        );
    }

    if ($operand instanceof IR\ExprCallValue) {
        return new IR\ExprCallValue(
            replaceTempsInOperand($operand->callee, $tempEnv, $sticky),
            \array_map(
                static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv, $sticky),
                $operand->args,
            ),
            $operand->srcLoc,
        );
    }

    if ($operand instanceof IR\DictMethod) {
        return new IR\DictMethod(
            replaceTempsInOperand($operand->evidence, $tempEnv, $sticky),
            $operand->method,
        );
    }

    if ($operand instanceof IR\ExprBinop) {
        return new IR\ExprBinop(
            $operand->op,
            replaceTempsInOperand($operand->left, $tempEnv, $sticky),
            replaceTempsInOperand($operand->right, $tempEnv, $sticky),
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

    if ($operand instanceof IR\ExprPartial) {
        return new IR\ExprPartial(
            $operand->fn,
            $operand->arity,
            \array_map(
                static fn (IR\Operand $a): IR\Operand => replaceTempsInOperand($a, $tempEnv, $sticky),
                $operand->args,
            ),
        );
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
    if ($name === 'listHead#' && count($args) === 1) {
        $xs = $args[0];
        if ($xs instanceof IR\Intrinsic && $xs->name === 'listCons#' && count($xs->args) === 2) {
            return $xs->args[0];
        }
        if ($xs instanceof IR\ListLit && $xs->elements !== []) {
            return $xs->elements[0];
        }
    }
    if ($name === 'listTail#' && count($args) === 1) {
        $xs = $args[0];
        if ($xs instanceof IR\Intrinsic && $xs->name === 'listCons#' && count($xs->args) === 2) {
            return $xs->args[1];
        }
        if ($xs instanceof IR\ListLit && $xs->elements !== []) {
            return new IR\ListLit(\array_slice($xs->elements, 1));
        }
    }

    return null;
}

/**
 * True when any Call/FnRef targets a `foreign` FunctionDecl in `$index`
 * (including the active pe-fusion index).
 *
 * @param list<IR\Stmt> $items
 * @param array<string, IR\FunctionDecl> $index
 */
function bodyReferencesForeignFunctions(array $items, array $index): bool
{
    $foreignLeaves = activeForeignLeaves();
    $isForeignName = static function (string $callee) use ($index, $foreignLeaves): bool {
        $t = lookupIndexedFunction($index, $callee);
        if ($t !== null && $t->foreign) {
            return true;
        }
        if (isset($foreignLeaves[$callee])) {
            return true;
        }
        $leaf = specializationLookupNameSafe($callee);

        return isset($foreignLeaves[$leaf]);
    };

    $visitOp = null;
    $visitOp = static function (IR\Operand $op) use (&$visitOp, $isForeignName): bool {
        if ($op instanceof IR\FnRef) {
            return $isForeignName($op->name);
        }
        if ($op instanceof IR\ExprCall) {
            if ($isForeignName($op->callee)) {
                return true;
            }
            foreach ($op->args as $arg) {
                if ($visitOp($arg)) {
                    return true;
                }
            }

            return false;
        }
        if ($op instanceof IR\ForeignCall) {
            return true;
        }
        if ($op instanceof IR\Intrinsic || $op instanceof IR\ListLit || $op instanceof IR\Partial
            || $op instanceof IR\ExprCallValue || $op instanceof IR\ExprPartial
        ) {
            $args = $op instanceof IR\ListLit ? $op->elements
                : ($op instanceof IR\ExprCallValue
                    ? array_merge([$op->callee], $op->args)
                    : $op->args);
            if ($op instanceof IR\ExprCallValue && $visitOp($op->callee)) {
                return true;
            }
            foreach ($args as $arg) {
                if ($arg instanceof IR\Operand && $visitOp($arg)) {
                    return true;
                }
            }
        }

        return false;
    };

    $visit = null;
    $visit = static function (array $stmts) use (&$visit, $visitOp, $isForeignName): bool {
        foreach (denseStmtItems($stmts) as $item) {
            if ($item instanceof IR\Call) {
                if ($isForeignName($item->callee)) {
                    return true;
                }
                foreach ($item->args as $arg) {
                    if ($visitOp($arg)) {
                        return true;
                    }
                }
            } elseif ($item instanceof IR\Ret || $item instanceof IR\Assign || $item instanceof IR\Let) {
                if ($visitOp($item->value)) {
                    return true;
                }
            } elseif ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
                if ($visitOp($item->scrutinee)) {
                    return true;
                }
                foreach ($item->arms as $arm) {
                    if ($visit($arm->body->items)) {
                        return true;
                    }
                }
            } elseif ($item instanceof IR\CallValue) {
                if ($visitOp($item->callee)) {
                    return true;
                }
                foreach ($item->args as $arg) {
                    if ($visitOp($arg)) {
                        return true;
                    }
                }
            }
        }

        return false;
    };

    return $visit($items);
}

/** @param array<string, true> $leaves */
function setActiveForeignLeaves(array $leaves): void
{
    $GLOBALS['__moggi_active_foreign_leaves'] = $leaves;
}

function clearActiveForeignLeaves(): void
{
    unset($GLOBALS['__moggi_active_foreign_leaves']);
}

/** @return array<string, true> */
function activeForeignLeaves(): array
{
    $leaves = $GLOBALS['__moggi_active_foreign_leaves'] ?? [];

    return \is_array($leaves) ? $leaves : [];
}

/**
 * True when any Call/ExprCall in `$items` targets a known recursive/SCC name
 * (including specialization-leaf aliases).
 *
 * @param list<IR\Stmt> $items
 * @param array<string, true> $recursive
 */
function bodyCallsRecursiveScc(array $items, array $recursive, string $selfName, string $selfLeaf): bool
{
    if ($recursive === [] && $selfName === '' && $selfLeaf === '') {
        return false;
    }
    $isRec = static function (string $callee) use ($recursive, $selfName, $selfLeaf): bool {
        if ($callee === $selfName || $callee === $selfLeaf) {
            return true;
        }
        $leaf = specializationLookupNameSafe($callee);
        if ($leaf === $selfName || $leaf === $selfLeaf) {
            return true;
        }

        return isset($recursive[$callee]) || isset($recursive[$leaf]);
    };

    $visit = null;
    $visit = static function (array $stmts) use (&$visit, $isRec): bool {
        foreach (denseStmtItems($stmts) as $item) {
            if ($item instanceof IR\Call && $isRec($item->callee)) {
                return true;
            }
            if ($item instanceof IR\Ret && $item->value instanceof IR\ExprCall && $isRec($item->value->callee)) {
                return true;
            }
            if ($item instanceof IR\Assign || $item instanceof IR\Let) {
                if ($item->value instanceof IR\ExprCall && $isRec($item->value->callee)) {
                    return true;
                }
            }
            if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
                foreach ($item->arms as $arm) {
                    if ($visit($arm->body->items)) {
                        return true;
                    }
                }
            }
        }

        return false;
    };

    return $visit($items);
}

function isInlineCandidate(IR\FunctionDecl $function): bool
{
    if ($function->foreign) {
        return false;
    }

    if ($function->ioEffect) {
        return false;
    }

    if (isLambdaName($function->name)) {
        return false;
    }

    if (isMatchWrapperCandidate($function)) {
        return true;
    }

    $items = $function->body->items;
    if ($items === [] || count($items) > 4) {
        return false;
    }

    $last = $items[count($items) - 1];
    if (!($last instanceof IR\Ret)) {
        return false;
    }

    // Allow `ret @True` / `ret @False` (metadata predicates, bool CAF). Other
    // bare FnRefs stay non-inlineable to avoid materializing large function
    // values at every call site.
    if ($last->value instanceof IR\FnRef && !isWiredInBoolCtor($last->value->name)) {
        return false;
    }

    if (functionBodyCallsSelf($items, $function->name)) {
        return false;
    }

    foreach ($items as $item) {
        if (\in_array($item::class, [IR\MatchStmt::class, IR\MatchReturn::class, IR\Loop::class, IR\TailRecall::class, IR\DictCall::class], true)) {
            return false;
        }
    }

    return true;
}

/**
 * Single MatchReturn body with shallow arms — typical ADT converters (from/to).
 * Kept separate from general inlining so we do not re-open Match+DictCall blow-up.
 */
function isMatchWrapperCandidate(IR\FunctionDecl $function): bool
{
    if ($function->foreign || $function->ioEffect || isLambdaName($function->name)) {
        return false;
    }

    $items = denseStmtItems($function->body->items);
    if (count($items) !== 1 || !($items[0] instanceof IR\MatchReturn)) {
        return false;
    }

    $match = $items[0];
    if (count($match->arms) > 4) {
        return false;
    }

    if (functionBodyCallsSelf($items, $function->name)) {
        return false;
    }

    foreach ($match->arms as $arm) {
        $armItems = denseStmtItems($arm->body->items);
        if (count($armItems) > 24) {
            return false;
        }
        foreach ($armItems as $item) {
            if (\in_array($item::class, [IR\MatchStmt::class, IR\MatchReturn::class, IR\Loop::class, IR\TailRecall::class], true)) {
                return false;
            }
            if (estimateStmtExprSize($item) > 64) {
                return false;
            }
        }
    }

    return true;
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
        IR\Assign::class, IR\Let::class => operandReferencesFunction($stmt->value, $name),
        IR\Binop::class => operandReferencesFunction($stmt->left, $name)
            || operandReferencesFunction($stmt->right, $name),
        IR\CallValue::class => operandReferencesFunction($stmt->callee, $name)
            || operandsReferenceFunction($stmt->args, $name),
        IR\Ret::class => exprReferencesFunction($stmt->value, $name),
        IR\MatchStmt::class, IR\MatchReturn::class => (static function () use ($stmt, $name): bool {
            if (operandReferencesFunction($stmt->scrutinee, $name)) {
                return true;
            }

            foreach ($stmt->arms as $arm) {
                if (functionBodyCallsSelf($arm->body->items, $name)) {
                    return true;
                }
            }

            return false;
        })(),
        IR\TailRecall::class => operandsReferenceFunction($stmt->args, $name),
        IR\Loop::class => functionBodyCallsSelf($stmt->body->items, $name),
        default => false,
    };
}

/** @param list<IR\Operand> $operands */
function operandsReferenceFunction(array $operands, string $name): bool
{
    foreach ($operands as $operand) {
        if (operandReferencesFunction($operand, $name)) {
            return true;
        }
    }

    return false;
}

function operandReferencesFunction(IR\Operand $operand, string $name): bool
{
    return match ($operand::class) {
        IR\ExprCall::class => $operand->callee === $name
            || operandsReferenceFunction($operand->args, $name),
        IR\ExprCallValue::class => operandReferencesFunction($operand->callee, $name)
            || operandsReferenceFunction($operand->args, $name),
        IR\ExprBinop::class => operandReferencesFunction($operand->left, $name)
            || operandReferencesFunction($operand->right, $name),
        default => false,
    };
}

function exprReferencesFunction(IR\Operand $expr, string $name): bool
{
    return operandReferencesFunction($expr, $name);
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
        IR\Binop::class => new IR\Binop($stmt->op, substituteOperand($stmt->left, $params, $args, $bound), substituteOperand($stmt->right, $params, $args, $bound), $stmt->dest),
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
        ), $stmt->dest, $stmt->srcLoc),
        IR\Ret::class => new IR\Ret(substituteExpr($stmt->value, $params, $args, $bound)),
        IR\MatchStmt::class => new IR\MatchStmt(
            substituteOperand($stmt->scrutinee, $params, $args, $bound),
            \array_map(
                static function (IR\MatchArm $arm) use ($params, $args, $bound): IR\MatchArm {
                    $armBound = $bound;
                    $items = [];
                    foreach (denseStmtItems($arm->body->items) as $item) {
                        $items[] = substituteStmt($item, $params, $args, $armBound);
                        if ($item instanceof IR\Let) {
                            $armBound[$item->name] = true;
                        }
                    }

                    return new IR\MatchArm($arm->pattern, new IR\Block($items), substituteGuards($arm->guards, $params, $args, $armBound));
                },
                $stmt->arms,
            ),
            $stmt->dest,
            $stmt->exhaustive,
        ),
        IR\MatchReturn::class => new IR\MatchReturn(
            substituteOperand($stmt->scrutinee, $params, $args, $bound),
            \array_map(
                static function (IR\MatchArm $arm) use ($params, $args, $bound): IR\MatchArm {
                    $armBound = $bound;
                    $items = [];
                    foreach (denseStmtItems($arm->body->items) as $item) {
                        $items[] = substituteStmt($item, $params, $args, $armBound);
                        if ($item instanceof IR\Let) {
                            $armBound[$item->name] = true;
                        }
                    }

                    return new IR\MatchArm($arm->pattern, new IR\Block($items), substituteGuards($arm->guards, $params, $args, $armBound));
                },
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
        default => $stmt,
    };
}

/**
 * @param array<int, string> $params
 * @param list<IR\Operand> $args
 * @param array<string, true> $bound
 */
function substituteOperand(IR\Operand $operand, array $params, array $args, array $bound = []): IR\Operand
{
    // Capture-safe: replace Locals in the *callee* tree without walking into replacement
    // arguments, which would stack substitutions until the visit depth cap.
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
            $max = max($max, $id);
        }
    }

    return $max;
}

/** @param list<IR\Stmt> $items @return array<int, int> */
function buildTempRemap(array $items, int $nextFree): array
{
    $remap = [];
    foreach (collectTempIdsInItems($items) as $id) {
        if (!isset($remap[$id])) {
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
function collectTempIdsInOperands(array $operands): array
{
    $ids = [];
    foreach ($operands as $operand) {
        foreach (collectTempIdsInOperand($operand) as $id) {
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
        IR\Binop::class => new IR\Binop($stmt->op, remapTempsInOperand($stmt->left, $remap), remapTempsInOperand($stmt->right, $remap), remapTempId($stmt->dest, $remap)),
        IR\Call::class => new IR\Call($stmt->callee, \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $stmt->args), remapTempId($stmt->dest, $remap), $stmt->srcLoc),
        IR\CallValue::class => new IR\CallValue(remapTempsInOperand($stmt->callee, $remap), \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $stmt->args), remapTempId($stmt->dest, $remap), $stmt->srcLoc),
        IR\DictCall::class => new IR\DictCall(
            remapTempsInOperand($stmt->evidence, $remap),
            $stmt->method,
            \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $stmt->args),
            remapTempId($stmt->dest, $remap),
            $stmt->srcLoc,
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
        default => $stmt,
    };
}

/** @param array<int, int> $remap */
function remapTempsInOperand(IR\Operand $operand, array $remap): IR\Operand
{
    return match ($operand::class) {
        IR\Temp::class => new IR\Temp(remapTempId($operand->id, $remap)),
        IR\ExprBinop::class => new IR\ExprBinop(
            $operand->op,
            remapTempsInOperand($operand->left, $remap),
            remapTempsInOperand($operand->right, $remap),
        ),
        IR\ExprCall::class => new IR\ExprCall(
            $operand->callee,
            \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $operand->args),
            $operand->srcLoc,
        ),
        IR\ExprCallValue::class => new IR\ExprCallValue(
            remapTempsInOperand($operand->callee, $remap),
            \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $operand->args),
            $operand->srcLoc,
        ),
        IR\Intrinsic::class => new IR\Intrinsic($operand->name, \array_map(
            static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap),
            $operand->args,
        ), $operand->srcLoc),
        // List literals carry temps (e.g. `buildRecord([t1, t2])`); skipping them
        // leaves stale ids after inline remapping while the defining Calls are
        // remapped — DCE then drops the Calls as unused and emit references
        // unbound `$tN`.
        IR\ListLit::class => new IR\ListLit(\array_map(
            static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap),
            $operand->elements,
        )),
        IR\Partial::class => new IR\Partial(
            $operand->fn,
            $operand->arity,
            \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $operand->args),
        ),
        IR\ExprPartial::class => new IR\ExprPartial(
            $operand->fn,
            $operand->arity,
            \array_map(static fn (IR\Operand $a): IR\Operand => remapTempsInOperand($a, $remap), $operand->args),
        ),
        IR\DictMethod::class => new IR\DictMethod(
            remapTempsInOperand($operand->evidence, $remap),
            $operand->method,
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
    // Prefer substituteOperand for nested operands so DictMethod/ForeignCall/ListLit evidence
    // rematerializes; substituteExpr left unbound `__ev_*` locals.
    return match ($expr::class) {
        IR\ConstInt::class, IR\FnRef::class => $expr,
        IR\Local::class => substituteLocal($expr, $params, $args, $bound),
        IR\Temp::class => $expr,
        IR\ExprBinop::class => new IR\ExprBinop(
            $expr->op,
            substituteExpr($expr->left, $params, $args, $bound),
            substituteExpr($expr->right, $params, $args, $bound),
        ),
        IR\ExprCall::class => new IR\ExprCall(
            $expr->callee,
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => substituteOperand($arg, $params, $args, $bound),
                $expr->args,
            ),
            $expr->srcLoc,
        ),
        IR\ExprCallValue::class => new IR\ExprCallValue(
            substituteOperand($expr->callee, $params, $args, $bound),
            \array_map(
                static fn (IR\Operand $arg): IR\Operand => substituteOperand($arg, $params, $args, $bound),
                $expr->args,
            ),
            $expr->srcLoc,
        ),
        IR\Partial::class, IR\ExprPartial::class, IR\Intrinsic::class, IR\ListLit::class,
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
            if (!\array_key_exists($i, $args)) {
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
 * @param array<string, true> $recursive
 * @param array<string, true> $peBlocked
 */
function inlineStmt(
    IR\Stmt $stmt,
    string $selfName,
    array $index,
    array $recursive = [],
    int $depth = 0,
    array &$peBlocked = [],
    ?int &$nextTemp = null,
): IR\Stmt {
    return mapStmtNestedBlocks(
        $stmt,
        static function (IR\Block $block) use ($selfName, $index, $recursive, $depth, &$peBlocked, &$nextTemp): IR\Block {
            return inlineBlock(
                $block,
                $selfName,
                $index,
                $recursive,
                $depth + 1,
                $peBlocked,
                $nextTemp,
            );
        },
    );
}

/** @param list<IR\FunctionDecl> $functions */
function fuseAppliedLambdas(array $functions, string $moduleName = ''): array
{
    $index = indexFunctions($functions, $moduleName);

    return \array_map(
        static fn (IR\FunctionDecl $function): IR\FunctionDecl => $function->withBody(
            fuseBlock($function->body, $index),
        ),
        $functions,
    );
}

/** @param array<string, IR\FunctionDecl> $index */
function fuseBlock(IR\Block $block, array $index): IR\Block
{
    return new IR\Block(fuseItems($block->items, $index));
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, IR\FunctionDecl> $index
 * @return list<IR\Stmt>
 */
function fuseItems(array $items, array $index): array
{
    $out = [];

    for ($i = 0; $i < count($items); ++$i) {
        $item = $items[$i];
        $next = $items[$i + 1] ?? null;

        if ($item instanceof IR\Call && $next !== null) {
            if ($next instanceof IR\CallValue) {
                $fused = tryFuseLambdaCall($item, $next, $index);
                if ($fused !== null) {
                    $out[] = $fused;
                    ++$i;
                    continue;
                }
            }

            if ($next instanceof IR\Ret && $next->value instanceof IR\ExprCallValue) {
                $fused = tryFuseLambdaRet($item, $next, $index);
                if ($fused !== null) {
                    $out[] = $fused;
                    ++$i;
                    continue;
                }
            }
        }

        $out[] = fuseStmt($item, $index);
    }

    return $out;
}

/**
 * @param array<string, IR\FunctionDecl> $index
 */
function tryFuseLambdaCall(IR\Stmt $call, IR\CallValue $callValue, array $index): ?IR\Stmt
{
    if (!($callValue->callee instanceof IR\Temp) || $callValue->callee->id !== $call->dest) {
        return null;
    }

    $provider = $index[$call->callee] ?? null;
    if ($provider === null) {
        return null;
    }

    $lambdaName = returnedLambdaName($provider);
    if ($lambdaName === null) {
        return null;
    }

    $lambda = $index[$lambdaName] ?? null;
    if ($lambda === null || !isSimpleLambda($lambda)) {
        return null;
    }

    $body = $lambda->body->items;
    $ret = $body[count($body) - 1];
    if (!($ret instanceof IR\Ret) || !($ret->value instanceof IR\ExprBinop)) {
        return null;
    }

    $left = mapCapture($ret->value->left, $lambda, $provider->params, $call->args);
    $right = mapCapture($ret->value->right, $lambda, $provider->params, $call->args, $callValue->args);

    return new IR\Assign(
        $callValue->dest,
        new IR\ExprBinop($ret->value->op, $left, $right),
    );
}

/**
 * @param array<string, IR\FunctionDecl> $index
 */
function tryFuseLambdaRet(IR\Stmt $call, IR\Ret $ret, array $index): ?array
{
    $value = $ret->value;
    if (!($value->callee instanceof IR\Temp) || $value->callee->id !== $call->dest) {
        return null;
    }

    $synthetic = new IR\CallValue(new IR\Temp($call->dest), $value->args, 0);
    $fused = tryFuseLambdaCall($call, $synthetic, $index);
    if ($fused === null) {
        return null;
    }

    return new IR\Ret($fused->value);
}

/** @param IR\FunctionDecl $provider */
function returnedLambdaName(IR\FunctionDecl $provider): ?string
{
    $items = $provider->body->items;
    if (count($items) !== 1 || !($items[0] instanceof IR\Ret)) {
        return null;
    }

    $value = $items[0]->value;
    if (!($value instanceof IR\FnRef) || !isLambdaName($value->name)) {
        return null;
    }

    return $value->name;
}

/** @param IR\FunctionDecl $lambda */
function isSimpleLambda(IR\FunctionDecl $lambda): bool
{
    if (!isLambdaName($lambda->name) || count($lambda->params) !== 1) {
        return false;
    }

    $items = $lambda->body->items;

    return count($items) === 1 && $items[0] instanceof IR\Ret;
}

/**
 * @param list<IR\Operand> $providerArgs
 * @param list<IR\Operand> $applyArgs
 */
function mapCapture(
    IR\Operand $operand,
    IR\FunctionDecl $lambda,
    array $providerParams,
    array $providerArgs,
    array $applyArgs = [],
): IR\Operand {
    if ($operand instanceof IR\Local) {
        foreach ($lambda->params as $i => $param) {
            if ($param === $operand->name) {
                return $applyArgs[$i] ?? $operand;
            }
        }

        foreach ($providerParams as $i => $param) {
            if ($param === $operand->name) {
                return $providerArgs[$i];
            }
        }
    }

    return $operand;
}

/** @param array<string, IR\FunctionDecl> $index */
function fuseStmt(IR\Stmt $stmt, array $index): IR\Stmt
{
    return mapStmtNestedBlocks(
        $stmt,
        static fn (IR\Block $block): IR\Block => fuseBlock($block, $index),
    );
}

function appendConsumerToArm(array $armItems, int $dest, array $consumer): array
{
    $armItems = denseStmtItems($armItems);
    if ($armItems === []) {
        $value = new IR\Temp($dest);
        if ($consumer['kind'] === 'ret_temp') {
            return [new IR\Ret($value)];
        }
        $args = $consumer['args'];
        $args[$consumer['scrutIndex']] = $value;

        return [new IR\Ret(new IR\ExprCall($consumer['callee'], $args))];
    }

    $last = $armItems[count($armItems) - 1];

    // Nested MatchReturn: push the consumer into each leaf (same as
    // appendMatchReturnToArm) — do not append `ret $dest` after it.
    if ($last instanceof IR\MatchReturn) {
        array_pop($armItems);
        $arms = [];
        foreach ($last->arms as $arm) {
            $arms[] = new IR\MatchArm(
                $arm->pattern,
                new IR\Block(appendConsumerToArm($arm->body->items, $dest, $consumer)),
                $arm->guards,
            );
        }
        $armItems[] = new IR\MatchReturn($last->scrutinee, $arms, $last->exhaustive);

        return denseStmtItems($armItems);
    }

    $value = null;
    if ($last instanceof IR\Assign && $last->dest === $dest) {
        $value = $last->value;
        array_pop($armItems);
    } elseif ($last instanceof IR\Ret) {
        $value = $last->value;
        array_pop($armItems);
    } elseif ($last instanceof IR\Call && $last->dest === $dest) {
        $value = new IR\ExprCall($last->callee, $last->args);
        array_pop($armItems);
    } elseif ($last instanceof IR\CallValue && $last->dest === $dest) {
        $value = new IR\ExprCallValue($last->callee, $last->args);
        array_pop($armItems);
    }

    if ($value === null) {
        $value = new IR\Temp($dest);
    }

    if ($consumer['kind'] === 'ret_temp') {
        $armItems[] = new IR\Ret($value);

        return denseStmtItems($armItems);
    }

    $args = $consumer['args'];
    $args[$consumer['scrutIndex']] = $value;
    $armItems[] = new IR\Ret(new IR\ExprCall($consumer['callee'], $args));

    return denseStmtItems($armItems);
}

function appendMatchReturnToArm(array $armItems, int $dest, IR\MatchReturn $consumer): array
{
    $armItems = denseStmtItems($armItems);
    $value = null;
    if ($armItems !== []) {
        $last = $armItems[count($armItems) - 1];
        if ($last instanceof IR\Assign && $last->dest === $dest) {
            $value = $last->value;
            array_pop($armItems);
        } elseif ($last instanceof IR\Call && $last->dest === $dest) {
            $value = new IR\ExprCall($last->callee, $last->args);
            array_pop($armItems);
        } elseif ($last instanceof IR\Ret) {
            $value = $last->value;
            array_pop($armItems);
        }
    }

    if ($value === null) {
        $value = new IR\Temp($dest);
    }

    $armItems[] = new IR\MatchReturn($value, $consumer->arms, $consumer->exhaustive);

    return denseStmtItems($armItems);
}

function callResultIsImmediatelyMatched(int $dest, array $rest): bool
{
    $rest = denseStmtItems($rest);
    if ($rest === []) {
        return false;
    }
    $next = $rest[0];
    if ($next instanceof IR\MatchStmt || $next instanceof IR\MatchReturn) {
        if ($next->scrutinee instanceof IR\Temp && $next->scrutinee->id === $dest) {
            return !tempUsedInItems(\array_slice($rest, 1), $dest);
        }
    }

    return false;
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
    if ($operand instanceof IR\ExprCall || $operand instanceof IR\ForeignCall) {
        $n = 1;
        foreach ($operand->args as $arg) {
            $n += estimateOperandTreeSize($arg, $depth + 1);
        }

        return $n;
    }
    if ($operand instanceof IR\ExprCallValue) {
        $n = 1 + estimateOperandTreeSize($operand->callee, $depth + 1);
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
    if ($function->foreign || $function->ioEffect || isLambdaName($function->name)) {
        return false;
    }

    // Only specialization clones — unrestricted PE-fusion inlining of ordinary
    // match-heavy locals (e.g. `/=`) explodes via recursive re-inline.
    $leaf = specializationLookupNameSafe($function->name);
    if (!str_starts_with($leaf, '__spec_')) {
        return false;
    }

    $items = denseStmtItems($function->body->items);
    if ($items === [] || count($items) > 12) {
        return false;
    }

    $last = lastStmtItem($items);
    if (!($last instanceof IR\Ret || $last instanceof IR\MatchReturn)) {
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
                if (count($item->arms) > 4) {
                    return false;
                }
                foreach ($item->arms as $arm) {
                    if (!$visit($arm->body->items)) {
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
    if ($operand instanceof IR\ExprCall) {
        return new IR\ExprCall(
            qualifyBareName($operand->callee, $module, $index),
            \array_map(static fn (IR\Operand $a): IR\Operand => qualifyBareCalleesInOperand($a, $module, $index), $operand->args),
            $operand->srcLoc,
        );
    }
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
    if ($operand instanceof IR\ExprCallValue) {
        return new IR\ExprCallValue(
            qualifyBareCalleesInOperand($operand->callee, $module, $index),
            \array_map(static fn (IR\Operand $a): IR\Operand => qualifyBareCalleesInOperand($a, $module, $index), $operand->args),
            $operand->srcLoc,
        );
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
    if ($name === '' || str_contains($name, '::') || str_contains($name, '\\')) {
        return $name;
    }
    // Data constructors stay unqualified — they are resolved by backend/data.
    if (isPlausibleConstructorName($name)) {
        return $name;
    }
    // Only qualify callees actually defined in the inlined function's module.
    // Imported symbols (e.g. a helper from an imported lib module) must stay
    // bare for destination codegen to resolve via use/function imports.
    if ($index !== [] && !isset($index[$name])) {
        return $name;
    }

    return resolvedSymbol($module, $name);
}

function specializationLookupNameSafe(string $callee): string
{
    $parsed = parseResolvedSymbol($callee);

    return $parsed !== null ? $parsed['name'] : $callee;
}

function tryInlinePeFusionBody(IR\FunctionDecl $target, IR\Stmt $call, int &$nextTemp): ?array
{
    if (!($call instanceof IR\Call)) {
        return null;
    }

    $body = denseStmtItems($target->body->items);
    $remap = buildTempRemap($body, $nextTemp);
    if ($remap !== []) {
        $nextTemp = max($remap) + 1;
    }
    $body = denseStmtItems(remapTempsInItems($body, $remap));

    $materialized = materializeInlineArgs($call->args, $nextTemp);
    $prefix = $materialized['prefix'];
    $args = $materialized['args'];
    $nextTemp = $materialized['nextTemp'];

    $bound = [];
    $out = $prefix;
    $lastIdx = count($body) - 1;
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
                $armLast = $armItems !== [] ? $armItems[count($armItems) - 1] : null;
                if ($armLast instanceof IR\Ret) {
                    array_pop($armItems);
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
