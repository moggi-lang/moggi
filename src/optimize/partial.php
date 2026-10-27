<?php declare(strict_types=1);

namespace Moggi\Optimize\Partial;

use Moggi\IR;
use Moggi\Syntax\Ast;

use function Moggi\IR\Callables\captureMeta;
use function Moggi\IR\Visit\mapOperandChildren;
use function Moggi\IR\Visit\stmtDirectOperands;
use function Moggi\IR\Visit\stmtNestedBlocks;
use function Moggi\Optimize\GlobalPass\rewriteStmtOperands;
use function Moggi\Optimize\Interproc\indexFunctions;
use function Moggi\Optimize\Interproc\isSimpleLambda;
use function Moggi\Optimize\Interproc\maxTempInItems;
use function Moggi\Optimize\Support\indexCapturedFunctions;
use function Moggi\Optimize\Support\mapStmtNestedBlocks;
use function Moggi\Optimize\Support\scheduleCall;

/**
 * Legalize calls against published callable layouts: prepend hidden evidence
 * and capture slots, and convert under-supplied calls into explicit IR\Partial.
 *
 * Ensures emitters never need to construct partials or repair call layouts.
 *
 * @param list<IR\FunctionDecl> $functions
 */
function legalizeCallables(array $functions): array
{
    $meta = captureMeta(indexCapturedFunctions($functions));
    $arity = callableArities($functions, $meta);

    return \array_map(
        static fn (IR\FunctionDecl $function): IR\FunctionDecl => $function->withBody(
            new IR\Block(bindLambdaCapturesItems($function->body->items, $meta, $arity)),
        ),
        $functions,
    );
}

/**
 * Every entry's runtime vector length: the hidden slots it takes before its declared
 * parameters. A lifted function's signature is its captures followed by its parameters; a
 * source declaration's already includes its evidence slots in front.
 *
 * @param list<IR\FunctionDecl> $functions
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 * @return array<string, int>
 */
function callableArities(array $functions, array $meta): array
{
    $arity = [];
    foreach ($functions as $function) {
        $arity[$function->name] = $function->kind->takesCaptures()
            ? \count($meta[$function->name]['captures'] ?? []) + \count($function->params)
            : \count($function->params);
    }

    return $arity;
}

/**
 * A direct call that supplies fewer slots than its entry publishes is a partial: the runtime
 * holds the supplied prefix and waits for the rest. Making that an `IR\Partial` here keeps the
 * judgement in one place — the table's own arity — instead of in each emitter.
 *
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 * @param array<string, int> $arity
 */
function underapplied(IR\Call $call, array $args, array $arity): ?IR\Partial
{
    $full = $arity[$call->callee] ?? null;
    if ($full === null || \count($args) >= $full) {
        return null;
    }

    return new IR\Partial($call->callee, $full, $args);
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 * @param array<string, int> $arity
 * @return list<IR\Stmt>
 */
function bindLambdaCapturesItems(array $items, array $meta, array $arity = []): array
{
    return \array_map(
        static fn (IR\Stmt $item): IR\Stmt => bindLambdaCapturesStmt($item, $meta, $arity),
        $items,
    );
}

/**
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 * @param array<string, int> $arity
 */
function bindLambdaCapturesStmt(IR\Stmt $stmt, array $meta, array $arity = []): IR\Stmt
{
    if ($stmt instanceof IR\Call) {
        return legalizeCall($stmt, $meta, $arity);
    }

    $rewrite = static fn (IR\Operand $operand): IR\Operand => bindLambdaCapturesOperand($operand, $meta, $arity);

    $rewritten = $stmt instanceof IR\CallValue
        ? new IR\CallValue(
            legalizeCallee($rewrite($stmt->callee), \count($stmt->args), $arity),
            \array_map($rewrite, $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        )
        : rewriteStmtOperands($stmt, $rewrite);

    return mapStmtNestedBlocks(
        $rewritten,
        static fn (IR\Block $block): IR\Block => new IR\Block(bindLambdaCapturesItems($block->items, $meta, $arity)),
    );
}

/**
 * A call by name: its arguments, its captures, then either the call or — when the vector is a
 * proper prefix of what the entry publishes — the partial that holds it.
 *
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 * @param array<string, int> $arity
 */
function legalizeCall(IR\Call $call, array $meta, array $arity): IR\Stmt
{
    $args = captureArgsForCall($call->callee, \array_map(
        static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta, $arity),
        $call->args,
    ), $meta);

    $partial = underapplied($call, $args, $arity);
    if ($partial !== null) {
        return new IR\Assign($call->dest, $partial);
    }

    return new IR\Call($call->callee, $args, $call->dest, $call->srcLoc);
}

/**
 * The callee of an application: a reference to a local entry applied to fewer slots than it
 * publishes is the partial that holds them, so the emitter never has to decide.
 *
 * @param array<string, int> $arity
 */
function legalizeCallee(IR\Operand $callee, int $supplied, array $arity): IR\Operand
{
    if ($callee instanceof IR\FnRef) {
        $full = $arity[$callee->name] ?? null;
        if ($full !== null && $supplied < $full) {
            return new IR\Partial($callee->name, $full, []);
        }

        return $callee;
    }

    return $callee;
}

/**
 * Captures a direct call has to pass before the arguments the source wrote.
 *
 * A call by name carries only the declared arguments, so a lifted function's
 * free locals are missing from it: a recursive `let`/`where` group lowers to a
 * plain `__letrecN` call, and an immediately applied lambda is called by name.
 * A specialized call, an `IR\Partial`, and the adapter a constrained local
 * binding lowers to (`λ(dict, p) -> λ3(dict, p)`) already carry them. Such a
 * call runs past the captures into the declared parameters, so it is only
 * left alone when it carries more than the captures -- exactly the declared
 * parameters can still need the prepend (the same evidence serving as the
 * local binding's own dictionaries), where skipping it would bind the
 * trailing arguments to the wrong parameters.
 *
 * @param list<IR\Operand> $args
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 * @return list<IR\Operand>
 */
function captureArgsForCall(string $name, array $args, array $meta): array
{
    $info = $meta[$name] ?? null;
    $captures = $info['captures'] ?? [];
    if ($captures === [] || \count($args) !== \count($info['params'])) {
        return $args;
    }
    if (\count($info['params']) > \count($captures) && callArgsLeadWithCaptures($args, $captures)) {
        return $args;
    }

    return [
        ...\array_map(static fn (string $capture): IR\Operand => new IR\Local($capture), $captures),
        ...$args,
    ];
}

/**
 * Whether a call already passes its callee's captures in front of its
 * arguments, in which case they must not be added a second time.
 *
 * @param list<IR\Operand> $args
 * @param list<string> $captures
 */
function callArgsLeadWithCaptures(array $args, array $captures): bool
{
    if (\count($args) < \count($captures)) {
        return false;
    }

    foreach ($captures as $i => $capture) {
        $arg = $args[$i] ?? null;
        if (! ($arg instanceof IR\Local) || $arg->name !== $capture) {
            return false;
        }
    }

    return true;
}

/**
 * A partial of a lifted function counts its captures in `arity` as well, so one
 * built from the declared parameters alone is widened to the full arity.
 *
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 * @param array<string, int> $arity
 */
function bindCapturedPartial(IR\Partial $partial, array $meta, array $arity = []): IR\Partial
{
    $args = \array_map(
        static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta, $arity),
        $partial->args,
    );
    $info = $meta[$partial->fn] ?? null;
    $captures = $info['captures'] ?? [];
    if ($captures === [] || $partial->arity !== \count($info['params'])) {
        return new IR\Partial($partial->fn, $partial->arity, $args);
    }
    if (\count($info['params']) > \count($captures) && callArgsLeadWithCaptures($args, $captures)) {
        return new IR\Partial($partial->fn, $partial->arity, $args);
    }

    $arity = $partial->arity + \count($captures);
    $args = [
        ...\array_map(static fn (string $capture): IR\Operand => new IR\Local($capture), $captures),
        ...$args,
    ];

    return new IR\Partial($partial->fn, $arity, $args);
}

/**
 * @param array<string, array{captures: list<string>, params: list<string>}> $meta
 * @param array<string, int> $arity
 */
function bindLambdaCapturesOperand(IR\Operand $operand, array $meta, array $arity = []): IR\Operand
{
    if ($operand instanceof IR\FnRef) {
        $info = $meta[$operand->name] ?? null;
        if ($info !== null && ($info['captures'] ?? []) !== []) {
            $captureArgs = \array_map(
                static fn (string $name): IR\Operand => new IR\Local($name),
                $info['captures'],
            );
            $full = \count($info['captures']) + \count($info['params']);

            return new IR\Partial($operand->name, $full, $captureArgs);
        }
    }

    return match ($operand::class) {
        IR\Partial::class => bindCapturedPartial($operand, $meta, $arity),
        IR\Intrinsic::class => new IR\Intrinsic(
            $operand->name,
            \array_map(static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta, $arity), $operand->args),
            $operand->srcLoc,
        ),
        IR\ListLit::class => new IR\ListLit(
            \array_map(static fn (IR\Operand $arg): IR\Operand => bindLambdaCapturesOperand($arg, $meta, $arity), $operand->elements),
        ),
        IR\DictMethod::class => new IR\DictMethod(
            bindLambdaCapturesOperand($operand->evidence, $meta, $arity),
            $operand->method,
            $operand->localEvidence,
        ),
        default => $operand,
    };
}

/** @param list<IR\FunctionDecl> $functions */
function foldPartialApply(array $functions, string $moduleName = ''): array
{
    $index = indexFunctions($functions, $moduleName);

    return \array_map(
        static fn (IR\FunctionDecl $function): IR\FunctionDecl => $function->withBody(
            new IR\Block(foldPartialApplyItems($function->body->items, $index)),
        ),
        $functions,
    );
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, IR\FunctionDecl> $index
 * @return list<IR\Stmt>
 */
/**
 * How often each temp is read across these items, nested blocks included.
 *
 * The fusions below replace the statement that defines a partial with the expression that applies
 * it. That is only sound when the definition's temp is read exactly once — otherwise the other
 * readers are left naming a temp nothing defines.
 *
 * @param list<IR\Stmt> $items
 * @return array<int, int>
 */
function tempReadCounts(array $items): array
{
    $reads = [];
    foreach ($items as $item) {
        IR\Visit\walkStmt(
            $item,
            static function (): void {
            },
            static function (IR\Operand $operand) use (&$reads): void {
                if ($operand instanceof IR\Temp) {
                    $reads[$operand->id] = ($reads[$operand->id] ?? 0) + 1;
                }
            },
        );
    }

    return $reads;
}

function foldPartialApplyItems(array $items, array $index): array
{
    $fused = [];
    $reads = tempReadCounts($items);
    $nextTemp = maxTempInItems($items) + 1;

    for ($i = 0; $i < \count($items); ++$i) {
        $item = $items[$i];
        $next = $items[$i + 1] ?? null;

        if ($item instanceof IR\CallValue) {
            $folded = tryFoldCallValuePartial($item, $nextTemp);
            if ($folded !== null) {
                $fused = [...$fused, ...$folded];

                continue;
            }
        }

        if ($item instanceof IR\Assign && $next !== null && $next instanceof IR\CallValue) {
            $fusedAssign = ($reads[$item->dest] ?? 0) === 1
                ? tryFuseAssignPartialCallValue($item, $next, $nextTemp)
                : null;
            if ($fusedAssign !== null) {
                $fused = [...$fused, ...$fusedAssign];
                ++$i;

                continue;
            }
        }

        if ($item instanceof IR\Call && $next !== null && ($reads[$item->dest] ?? 0) === 1) {
            $fusedPair = tryFusePartialCall($item, $next, $index, $nextTemp);
            if ($fusedPair !== null) {
                $fused = [...$fused, ...$fusedPair];
                ++$i;

                continue;
            }
        }

        $fused[] = $item;
    }

    $fused = \array_map(
        static fn (IR\Stmt $item): IR\Stmt => mapStmtNestedBlocks(
            $item,
            static fn (IR\Block $block): IR\Block => new IR\Block(foldPartialApplyItems($block->items, $index)),
        ),
        $fused,
    );

    return $fused;
}

/**
 * The value a read of `$expr` applied to `$args` takes, scheduling the computation it stands for.
 *
 * A saturated application is a computation, so it becomes a `Call` statement in the block that reads
 * it — writing `$dest` when the reader owns one, a fresh temp otherwise — and the value returned is
 * that destination. An application below the partial's arity is already a value and schedules
 * nothing. A value-shaped call is what the canonical schema forbids, so this never builds one: a
 * caller gets back a `Temp` or a `Partial`, never a call.
 *
 * @param list<IR\Operand> $args applied to the partial
 * @param list<IR\Stmt> $out appended to, in evaluation order
 * @return IR\Operand|null the value, or null when `$expr` is not a partial application
 */
function appliedPartialValue(IR\Operand $expr, array $args, array &$out, int &$nextTemp, ?int $dest = null): ?IR\Operand
{
    if (! $expr instanceof IR\Partial) {
        return null;
    }

    $all = [...$expr->args, ...$args];
    if (\count($all) > $expr->arity) {
        return null;
    }
    if (\count($all) < $expr->arity) {
        return new IR\Partial($expr->fn, $expr->arity, $all);
    }

    [$value, $nextTemp] = scheduleCall($expr->fn, $all, $out, $nextTemp, null, $dest);

    return $value;
}

/**
 * A partial application folded into the statements a reader needs.
 *
 * The reader's own `dest` is written by the scheduled `Call` when the application saturates, so it
 * needs no statement; a `Ret` takes the temp; an under-applied application is a value and becomes the
 * reader alone.
 *
 * @param list<IR\Operand> $args applied to the partial
 * @return list<IR\Stmt>|null null when `$expr` is not a partial application
 */
function foldedApplicationItems(IR\Operand $expr, array $args, int &$nextTemp, ?int $dest): ?array
{
    $out = [];
    $value = appliedPartialValue($expr, $args, $out, $nextTemp, $dest);
    if ($value === null) {
        return null;
    }
    if ($dest !== null && $value instanceof IR\Temp && $value->id === $dest) {
        return $out;
    }
    $out[] = $dest === null ? new IR\Ret($value) : new IR\Assign($dest, $value);

    return $out;
}

/**
 * @return list<IR\Stmt>|null
 */
function tryFoldCallValuePartial(IR\CallValue $callValue, int &$nextTemp): ?array
{
    $partial = partialFromOperand($callValue->callee);
    if ($partial === null) {
        return null;
    }

    return foldedApplicationItems($partial, $callValue->args, $nextTemp, $callValue->dest);
}

/**
 * @return list<IR\Stmt>|null
 */
function tryFuseAssignPartialCallValue(IR\Assign $assign, IR\CallValue $callValue, int &$nextTemp): ?array
{
    if (! ($callValue->callee instanceof IR\Temp) || $callValue->callee->id !== $assign->dest) {
        return null;
    }

    $partial = partialFromOperand($assign->value);
    if ($partial === null) {
        return null;
    }

    return foldedApplicationItems($partial, $callValue->args, $nextTemp, $callValue->dest);
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @return list<IR\Stmt>|null
 */
function tryFusePartialCall(IR\Stmt $call, IR\Stmt $next, array $index, int &$nextTemp): ?array
{
    if ($next instanceof IR\CallValue) {
        return tryFusePartialCallValue($call, $next, $index, $nextTemp);
    }

    return null;
}

/**
 * @param array<string, IR\FunctionDecl> $index
 * @return list<IR\Stmt>|null
 */
function tryFusePartialCallValue(IR\Stmt $call, IR\CallValue $callValue, array $index, int &$nextTemp): ?array
{
    if (! ($callValue->callee instanceof IR\Temp) || $callValue->callee->id !== $call->dest) {
        return null;
    }

    $application = partialApplicationOfCall($call, $callValue->args, $index);
    if ($application === null) {
        $inner = resolveCallValueCalleeExpr($callValue->callee);
        $application = $inner === null ? null : [$inner, $callValue->args];
    }
    if ($application === null) {
        return null;
    }
    [$expr, $args] = $application;

    return foldedApplicationItems($expr, $args, $nextTemp, $callValue->dest);
}

/**
 * The partial application a call's callee stands for, with the arguments still to apply.
 *
 * The provider either returns a partial — applied to the call's own arguments — or is an identity the
 * call's single argument completes. Saturation belongs to the reader's block
 * ({@see appliedPartialValue}), so what this hands back is a partial and its arguments, never a call.
 *
 * @param list<IR\Operand> $args
 * @param array<string, IR\FunctionDecl> $index
 * @return array{0: IR\Operand, 1: list<IR\Operand>}|null
 */
function partialApplicationOfCall(IR\Stmt $call, array $args, array $index): ?array
{
    $provider = $index[$call->callee] ?? null;
    if ($provider !== null) {
        $partial = returnedPartialExpr($provider, $call->args);
        if ($partial !== null) {
            return [$partial, $args];
        }

        $identity = returnedIdentityArg($provider, $call->args);
        if ($identity !== null) {
            return [$identity, $args];
        }
    }

    return null;
}

/** @param list<IR\Operand> $providerArgs */
function returnedPartialExpr(IR\FunctionDecl $provider, array $providerArgs): ?IR\Operand
{
    $items = $provider->body->items;
    if (\count($items) !== 1 || ! ($items[0] instanceof IR\Ret)) {
        return null;
    }

    $value = $items[0]->value;
    if (! ($value instanceof IR\Partial)) {
        return null;
    }

    return substituteProviderParams($value, $provider, $providerArgs);
}

/**
 * Rewrite a partial lifted out of `$provider` so that its arguments name the
 * caller's values instead of the provider's parameters.
 *
 * `bindLambdaCaptures` turns a `@λN` value into `Partial(λN, arity, [captures])`
 * whose arguments are `Local`s of the *enclosing* function — for
 * `adder n = \x -> x + n` the body becomes `ret partial λ0(n)`. Fusing that
 * return with the call site's application (`t = call adder(10)` then
 * `call_value t(5)` → `call λ0(10, 5)`) is only sound when `n` is replaced by the
 * call's own argument, since the provider's parameter does not exist there.
 *
 * Returns `null` when the arguments cannot stand in for every captured name —
 * the call's argument count does not line up with the parameters, or a capture
 * is a `let`/pattern-bound local rather than a parameter. The caller then
 * leaves the application to the runtime `__apply`, which builds the closure
 * inside the provider where the names still exist.
 *
 * @param list<IR\Operand> $providerArgs
 */
function substituteProviderParams(IR\Partial $expr, IR\FunctionDecl $provider, array $providerArgs): ?IR\Operand
{
    if (\count($provider->params) !== \count($providerArgs)) {
        return null;
    }

    $subst = \array_combine($provider->params, $providerArgs);
    $resolved = true;

    $substitute = null;
    $substitute = static function (IR\Operand $operand) use (&$substitute, $subst, &$resolved): IR\Operand {
        if ($operand instanceof IR\Local) {
            if (\array_key_exists($operand->name, $subst)) {
                return $subst[$operand->name];
            }
            $resolved = false;

            return $operand;
        }

        return mapOperandChildren($operand, $substitute);
    };

    $out = $substitute($expr);

    return $resolved ? $out : null;
}

/**
 * @param list<IR\Operand> $providerArgs
 * @return IR\Operand|null
 */
function returnedIdentityArg(IR\FunctionDecl $provider, array $providerArgs): ?IR\Operand
{
    if (\count($provider->params) !== 1 || \count($providerArgs) !== 1) {
        return null;
    }

    $items = $provider->body->items;
    if (\count($items) !== 1 || ! ($items[0] instanceof IR\Ret)) {
        return null;
    }

    $value = $items[0]->value;
    if (! ($value instanceof IR\Local) || $value->name !== $provider->params[0]) {
        return null;
    }

    return $providerArgs[0];
}

/**
 * Normalize known-arity applications into canonical Call / CallValue statements.
 *
 * Only module-level functions with complete surface-type parameter coverage;
 * never flatten beyond declared arity.
 *
 * @param list<IR\FunctionDecl> $functions
 * @return list<IR\FunctionDecl>
 */
function normalizeKnownApplies(array $functions, string $moduleName = ''): array
{
    $arity = knownFunctionArity($functions, $moduleName);

    return \array_map(
        static fn (IR\FunctionDecl $function): IR\FunctionDecl => $function->withBody(
            new IR\Block(normalizeApplyItems($function->body->items, $arity, declaredParamArities($function))),
        ),
        $functions,
    );
}

/**
 * @param list<IR\FunctionDecl> $functions
 * @return array<string, int>
 */
function knownFunctionArity(array $functions, string $moduleName): array
{
    $arity = [];
    foreach ($functions as $function) {
        if ($function->kind->takesCaptures()) {
            continue;
        }
        $count = \count($function->params);
        $arity[$function->name] = $count;
        if ($moduleName !== '') {
            $arity[$moduleName . '::' . $function->name] ??= $count;
        }
    }

    return $arity;
}

/**
 * Map each parameter to the arity of its declared function type.
 *
 * Empty unless the declared surface type covers exactly the declared
 * parameters — instance methods and context-taking functions carry evidence
 * slots the surface type does not mention.
 *
 * @return array<string, int>
 */
function declaredParamArities(IR\FunctionDecl $function): array
{
    $type = $function->type;
    while ($type instanceof Ast\TypeConstrained) {
        $type = $type->body;
    }

    $paramTypes = [];
    while ($type instanceof Ast\TypeArrow) {
        $paramTypes[] = $type->from;
        $type = $type->to;
    }

    if ($paramTypes === [] || \count($paramTypes) !== \count($function->params)) {
        return [];
    }

    $out = [];
    foreach ($function->params as $i => $name) {
        $arrows = functionTypeArity($paramTypes[$i]);
        if ($arrows > 0) {
            $out[$name] = $arrows;
        }
    }

    return $out;
}

function functionTypeArity(Ast\TypeNode $type): int
{
    $arrows = 0;
    while ($type instanceof Ast\TypeArrow) {
        ++$arrows;
        $type = $type->to;
    }

    return $arrows;
}

/**
 * The statement shapes of a block, scope by scope: a known callee applied to fewer slots than it
 * declares is a `Partial`, and at full arity the application *is* a call statement, in the block
 * that reads it — a saturated apply becomes a statement of its own and may not be hoisted out of
 * the scope it was evaluated in.
 *
 * @param list<IR\Stmt> $items
 * @param array<string, int> $arity
 * @param array<string, int> $paramArities
 * @return list<IR\Stmt>
 */
function normalizeApplyItems(array $items, array $arity, array $paramArities): array
{
    return \array_map(
        static fn (IR\Stmt $item): IR\Stmt => normalizeApplyStmt($item, $arity, $paramArities),
        $items,
    );
}

/**
 * The statement shapes: a known callee applied to fewer slots than it declares is a `Partial`, and
 * at full arity the application *is* a call statement in the scope that reads it.
 *
 * @param array<string, int> $arity
 * @param array<string, int> $paramArities
 */
function normalizeApplyStmt(IR\Stmt $stmt, array $arity, array $paramArities): IR\Stmt
{
    if ($stmt instanceof IR\CallValue && $stmt->callee instanceof IR\FnRef) {
        $declared = $arity[$stmt->callee->name] ?? null;
        if ($declared !== null && \count($stmt->args) <= $declared) {
            if (\count($stmt->args) === $declared) {
                return new IR\Call($stmt->callee->name, $stmt->args, $stmt->dest, $stmt->srcLoc);
            }

            return new IR\Assign(
                $stmt->dest,
                new IR\Partial($stmt->callee->name, $declared, $stmt->args),
            );
        }
    }

    $mapBlock = static fn (IR\Block $block): IR\Block => new IR\Block(
        normalizeApplyItems($block->items, $arity, $paramArities),
    );

    if ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\MatchReturn) {
        $arms = [];
        foreach ($stmt->arms as $arm) {
            $guards = [];
            foreach ($arm->guards as $guard) {
                $guards[] = new IR\Guard($mapBlock($guard->prep), $guard->cond);
            }
            $arms[] = new IR\MatchArm($arm->pattern, $mapBlock($arm->body), $guards);
        }

        return $stmt instanceof IR\MatchReturn
            ? new IR\MatchReturn($stmt->scrutinee, $arms, $stmt->exhaustive)
            : new IR\MatchStmt($stmt->scrutinee, $arms, $stmt->dest, $stmt->exhaustive);
    }

    return mapStmtNestedBlocks($stmt, $mapBlock);
}

/**
 * Absorb a returned partial's missing arguments into the function's declared
 * parameters, accounting for captures in its effective arity.
 *
 * @param list<string> $captures
 */
function specializePartialReturnFunction(IR\FunctionDecl $function, array $captures = []): IR\FunctionDecl
{
    $items = $function->body->items;
    if (\count($items) !== 1 || ! ($items[0] instanceof IR\Ret)) {
        return $function;
    }

    $partial = partialFromOperand($items[0]->value);
    if ($partial === null) {
        return $function;
    }

    $missing = $partial->arity - \count($partial->args);
    if ($missing <= 0) {
        return $function;
    }

    $existing = $function->params;
    if (\count($captures) + \count($existing) >= $missing) {
        return $function;
    }

    $newParams = [...$existing];
    while (\count($newParams) < $missing) {
        $newParams[] = freshPartialParamName($newParams);
    }

    $callArgs = [
        ...$partial->args,
        ...\array_map(static fn (string $param): IR\Operand => new IR\Local($param), \array_slice($newParams, \count($existing))),
    ];

    $body = [];
    [$value] = scheduleCall($partial->fn, $callArgs, $body, maxTempInItems($items) + 1);
    $body[] = new IR\Ret($value);

    return new IR\FunctionDecl(
        $function->name,
        $newParams,
        $function->type,
        new IR\Block($body),
        $function->export,
        $function->instanceMethod,
        $function->entryKind,
        $function->ioEffect,
        $function->ioStraightLine,
        $function->foreign,
        $function->actionConvention,
        $function->srcLoc,
        $function->kind,
        $function->origin,
    );
}

function partialFromOperand(IR\Operand $value): ?IR\Partial
{
    return $value instanceof IR\Partial ? $value : null;
}

/** @param list<string> $existing */
function freshPartialParamName(array $existing): string
{
    $used = \array_fill_keys($existing, true);
    $i = 1;
    while (isset($used["_p{$i}"])) {
        ++$i;
    }

    return "_p{$i}";
}

function moduleUsesPartialApply(IR\Module $module): bool
{
    $lambdaIndex = indexFunctions($module->functions);

    foreach ($module->functions as $function) {
        if (blockNeedsRuntimeApply($function->body, $lambdaIndex)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, IR\FunctionDecl> $lambdaIndex */
function blockNeedsRuntimeApply(IR\Block $block, array $lambdaIndex): bool
{
    foreach ($block->items as $item) {
        if (stmtNeedsRuntimeApply($item, $lambdaIndex)) {
            return true;
        }
    }

    return false;
}

/**
 * Walked generically over {@see stmtDirectOperands} / {@see stmtNestedBlocks}:
 * a hand-written `match` kept missing node kinds (declared `default => false`),
 * so `tail_recall` — and every other statement added later — silently answered
 * "no runtime apply" and codegen emitted `__apply` without importing it.
 *
 * @param array<string, IR\FunctionDecl> $lambdaIndex
 */
function stmtNeedsRuntimeApply(IR\Stmt $stmt, array $lambdaIndex): bool
{
    if ($stmt instanceof IR\CallValue && calleeNeedsRuntimeApply($stmt->callee, $lambdaIndex)) {
        return true;
    }

    foreach (stmtDirectOperands($stmt) as $operand) {
        if (operandExprNeedsRuntimeApply($operand, $lambdaIndex)) {
            return true;
        }
    }

    return \array_any(
        stmtNestedBlocks($stmt),
        static fn (IR\Block $nested): bool => blockNeedsRuntimeApply($nested, $lambdaIndex),
    );
}

/** @param array<string, IR\FunctionDecl> $lambdaIndex */
function exprNeedsRuntimeApply(IR\Operand $expr, array $lambdaIndex): bool
{
    return match ($expr::class) {
        IR\Intrinsic::class => \array_any(
            $expr->args,
            static fn (IR\Operand $arg): bool => operandExprNeedsRuntimeApply($arg, $lambdaIndex),
        ),
        IR\ListLit::class => \array_any(
            $expr->elements,
            static fn (IR\Operand $arg): bool => operandExprNeedsRuntimeApply($arg, $lambdaIndex),
        ),
        IR\Partial::class => \array_any(
            $expr->args,
            static fn (IR\Operand $arg): bool => operandExprNeedsRuntimeApply($arg, $lambdaIndex),
        ),
        default => false,
    };
}

/** @param array<string, IR\FunctionDecl> $lambdaIndex */
function operandExprNeedsRuntimeApply(IR\Operand $operand, array $lambdaIndex): bool
{
    return match ($operand::class) {
        IR\Partial::class, IR\Intrinsic::class, IR\ListLit::class
            => exprNeedsRuntimeApply($operand, $lambdaIndex),
        default => false,
    };
}

/**
 * Whether applying this callee has to go through the runtime `__apply`.
 *
 * @param array<string, IR\FunctionDecl> $lambdaIndex
 */
function calleeNeedsRuntimeApply(IR\Operand $callee, array $lambdaIndex): bool
{
    return match ($callee::class) {
        IR\Partial::class => false,
        IR\FnRef::class => ! (isset($lambdaIndex[$callee->name])
            && isSimpleLambda($lambdaIndex[$callee->name])),
        IR\Local::class, IR\Temp::class => true,
        IR\DictMethod::class => true,
        IR\Intrinsic::class, IR\ForeignCall::class => true,
        default => false,
    };
}

/** A callee that is itself a `Partial` is the application's own function value. */
function resolveCallValueCalleeExpr(IR\Operand $callee): ?IR\Operand
{
    return $callee instanceof IR\Partial ? $callee : null;
}
