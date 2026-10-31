<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\IR;
use function Moggi\Backend\Jvm\Naming\moduleInternalName;
use function Moggi\Backend\Jvm\Naming\symbolName;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\IR\Projection\parse as projectionParse;
use function Moggi\IR\Projection\tupleArity as projectionTupleArity;

/** A call whose value is on the stack, without storing it in its destination temp. */
function emitCallValue(EmitEnv $env, IR\Call|IR\CallValue $call): void
{
    if ($call instanceof IR\Call) {
        emitStaticCall($env, $call->callee, $call->args);

        return;
    }

    emitOperand($env, $call->callee);
    emitRuntimeApply($env, $call->args);
}


/** @param list<IR\Operand> $args */
function emitStaticCall(EmitEnv $env, string $name, array $args, ?string $ownerModule = null): void
{
    if (emitBuiltinCall($env, $name, $args)) {
        return;
    }
    $callArgs = callArgs($env, $name, $args);
    $argSlots = [];
    foreach ($callArgs as $arg) {
        emitOperand($env, $arg);
        $slot = $env->freshLocal();
        $env->c->astore($slot);
        $argSlots[] = $slot;
    }
    foreach ($argSlots as $slot) {
        $env->c->aload($slot);
    }
    $arity = \count($callArgs);
    $desc = '(' . \str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
    $target = resolveCallableTarget($env->owner, $env->externalFns, $name, $ownerModule, $env->localCallables);
    $env->c->invokestatic($env->cp->methodRef($target['owner'], $target['sym'], $desc), $arity, true);
}

/**
 * @param array<string, string> $externalFns
 * @param array<string, true> $localCallables
 * @return array{owner: string, sym: string}
 */
function resolveCallableTarget(
    string $owner,
    array $externalFns,
    string $name,
    ?string $ownerModule = null,
    array $localCallables = [],
): array {
    $targetOwner = $owner;
    $sym = symbolName($name);
    if ($ownerModule !== null && $ownerModule !== '') {
        $targetOwner = moduleInternalName($ownerModule);
    } else {
        $parsed = parseResolvedSymbol($name);
        if ($parsed !== null) {
            $targetOwner = moduleInternalName($parsed['module']);
            $sym = symbolName($parsed['name']);
        } elseif (! isset($localCallables[$name]) && isset($externalFns[$name])) {
            $extParsed = parseResolvedSymbol($externalFns[$name]);
            if ($extParsed !== null) {
                $targetOwner = moduleInternalName($extParsed['module']);
                $sym = symbolName($extParsed['name']);
            }
        }
    }

    return ['owner' => $targetOwner, 'sym' => $sym];
}

/** @param list<IR\Operand> $args */
function emitBuiltinCall(EmitEnv $env, string $name, array $args): bool
{
    $projection = projectionParse($name);
    if ($projection !== null) {
        emitOperand($env, $args[0]);
        if ($projection['tuple']) {
            $env->c->checkcast($env->cp->class_('[Ljava/lang/Object;'));
        } else {
            $env->c->checkcast($env->cp->class_('moggi/rt/Con'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/Con', 'fields', '[Ljava/lang/Object;'));
        }
        $env->c->iconst($projection['index']);
        $env->c->aaload();

        return true;
    }
    $tupleArity = projectionTupleArity($name);
    if ($tupleArity !== null) {
        if ($tupleArity === 0) {
            $env->c->aconst_null();

            return true;
        }
        pushObjectArray($env, $args);

        return true;
    }

    return false;
}

/**
 * Emit-only foreign intrinsics — no host member is called.
 *
 * `__cast` re-types a value the host already returned: the Moggi ABI keeps
 * every value as an object ref, so the cast is one `checkcast` and the target
 * comes from the descriptor the signature already inferred.
 */
function emitRuntimeApply(EmitEnv $env, array $args): void
{
    if (\count($args) === 1) {
        emitOperand($env, $args[0]);
        $env->c->invokestatic(
            $env->cp->methodRef('moggi/rt/RT', 'apply1', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Object;'),
            2,
            true,
        );

        return;
    }

    pushObjectArray($env, $args);
    $env->c->invokestatic(
        $env->cp->methodRef('moggi/rt/RT', 'apply', '(Ljava/lang/Object;[Ljava/lang/Object;)Ljava/lang/Object;'),
        2,
        true,
    );
}

/**
 * IR after bindLambdaCaptures already includes capture args on Partial/Call.
 * Do not prepend lambdaMeta captures again (that caused unknown-local emit
 * failures and Partial arity -1 → NegativeArraySizeException).
 *
 * @param list<IR\Operand> $args
 * @return list<IR\Operand>
 */
function callArgs(EmitEnv $env, string $name, array $args): array
{
    return $args;
}

/** @param array<string, true> $bound */
function blockRefsUnboundLocal(IR\Block $block, array $bound): bool
{
    foreach ($block->items as $stmt) {
        if (stmtRefsUnboundLocal($stmt, $bound)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, true> $bound */
function stmtRefsUnboundLocal(IR\Stmt $stmt, array $bound): bool
{
    if ($stmt instanceof IR\Ret) {
        return operandRefsUnboundLocal($stmt->value, $bound);
    }
    if ($stmt instanceof IR\Assign) {
        return operandRefsUnboundLocal($stmt->value, $bound);
    }
    if ($stmt instanceof IR\Let) {
        return operandRefsUnboundLocal($stmt->value, $bound);
    }
    if ($stmt instanceof IR\Call) {
        foreach ($stmt->args as $arg) {
            if (operandRefsUnboundLocal($arg, $bound)) {
                return true;
            }
        }

        return false;
    }
    if ($stmt instanceof IR\MatchReturn) {
        if (operandRefsUnboundLocal($stmt->scrutinee, $bound)) {
            return true;
        }
        foreach ($stmt->arms as $arm) {
            $armBound = $bound;
            if ($arm->pattern instanceof IR\PatVar) {
                $armBound[$arm->pattern->name] = true;
            }
            if (blockRefsUnboundLocal($arm->body, $armBound)) {
                return true;
            }
        }
    }

    return false;
}

/** @param array<string, true> $bound */
function operandRefsUnboundLocal(IR\Operand $op, array $bound): bool
{
    if ($op instanceof IR\Local) {
        return ! isset($bound[$op->name]);
    }
    if ($op instanceof IR\Intrinsic || $op instanceof IR\ForeignCall || $op instanceof IR\ListLit) {
        foreach ($op->args ?? [] as $arg) {
            if ($arg instanceof IR\Operand && operandRefsUnboundLocal($arg, $bound)) {
                return true;
            }
        }
    }
    return false;
}
