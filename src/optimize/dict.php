<?php declare(strict_types=1);

namespace Moggi\Optimize\Dict;

use Moggi\IR;

use function Moggi\Optimize\Support\preserveMatchArm;
use function Moggi\Semantics\Types\evidenceIs;
use function Moggi\Semantics\Types\sanitizeEvidenceNamePart;

/**
 * Rewrite known dictionary method invocations to direct calls.
 *
 * Handles:
 * - DictCall with nullary evidence FnRef → Call(instanceMethod, args)
 * - DictCall with Temp/Local that is a known Call to an evidence factory
 *   → Call(instanceMethod, factoryArgs ++ methodArgs)
 * - DictMethod with known nullary evidence → FnRef(instanceMethod)
 *
 * Kept: Monad List >>= → list_bind intrinsic (representation special case).
 *
 * @param array<string, IR\InstanceEvidence> $evidenceByName
 */
function specializeDictCallsStmt(IR\Stmt $stmt, array $evidenceByName = [], array $tempEvidence = [], array $localEvidence = [], array $localFns = []): IR\Stmt
{
    if ($stmt instanceof IR\DictCall) {
        return specializeDictCall($stmt, $evidenceByName, $tempEvidence, $localEvidence, $localFns);
    }

    return match ($stmt::class) {
        IR\Assign::class => new IR\Assign($stmt->dest, specializeDictCallsOperand($stmt->value, $evidenceByName)),
        IR\Let::class => new IR\Let($stmt->name, specializeDictCallsOperand($stmt->value, $evidenceByName)),
        IR\Binop::class => new IR\Binop(
            $stmt->op,
            specializeDictCallsOperand($stmt->left, $evidenceByName),
            specializeDictCallsOperand($stmt->right, $evidenceByName),
            $stmt->dest,
        ),
        IR\Call::class => new IR\Call(
            $stmt->callee,
            \array_map(
                static fn (IR\Operand $a): IR\Operand => specializeDictCallsOperand($a, $evidenceByName),
                $stmt->args,
            ),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\CallValue::class => new IR\CallValue(
            specializeDictCallsOperand($stmt->callee, $evidenceByName),
            \array_map(
                static fn (IR\Operand $a): IR\Operand => specializeDictCallsOperand($a, $evidenceByName),
                $stmt->args,
            ),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\Ret::class => new IR\Ret(specializeDictCallsOperand($stmt->value, $evidenceByName)),
        IR\MatchStmt::class, IR\MatchReturn::class => specializeDictCallsMatch($stmt, $evidenceByName, $tempEvidence, $localEvidence, $localFns),
        default => $stmt,
    };
}

/**
 * @param array<string, IR\InstanceEvidence> $evidenceByName
 * @param array<int, array{name: string, args: list<IR\Operand>}> $tempEvidence
 * @param array<string, array{name: string, args: list<IR\Operand>}> $localEvidence
 */
function specializeDictCallsMatch(
    IR\MatchStmt|IR\MatchReturn $stmt,
    array $evidenceByName,
    array $tempEvidence = [],
    array $localEvidence = [],
    array $localFns = [],
): IR\Stmt {
    $arms = \array_map(
        static function ($arm) use ($evidenceByName, $tempEvidence, $localEvidence, $localFns) {
            return preserveMatchArm(
                $arm,
                specializeDictCallsBlockWithEnv($arm->body, $evidenceByName, $tempEvidence, $localEvidence, $localFns),
            );
        },
        $stmt->arms,
    );

    if ($stmt instanceof IR\MatchStmt) {
        return new IR\MatchStmt($stmt->scrutinee, $arms, $stmt->dest, $stmt->exhaustive);
    }

    return new IR\MatchReturn($stmt->scrutinee, $arms, $stmt->exhaustive);
}

/**
 * @param array<string, IR\InstanceEvidence> $evidenceByName
 * @param array<int, array{name: string, args: list<IR\Operand>}> $tempEvidence
 * @param array<string, array{name: string, args: list<IR\Operand>}> $localEvidence
 */
function specializeDictCallsBlockWithEnv(
    IR\Block $block,
    array $evidenceByName,
    array $tempEvidence,
    array $localEvidence,
    array $localFns = [],
): IR\Block {
    $out = [];

    foreach ($block->items as $item) {
        if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn) {
            $out[] = specializeDictCallsMatch($item, $evidenceByName, $tempEvidence, $localEvidence, $localFns);
            continue;
        }

        $item = specializeDictCallsStmt($item, $evidenceByName, $tempEvidence, $localEvidence, $localFns);

        if ($item instanceof IR\Call
            && (isset($evidenceByName[$item->callee]) || str_starts_with($item->callee, '__ev_'))) {
            $tempEvidence[$item->dest] = [
                'name' => $item->callee,
                'args' => $item->args,
            ];
        }

        if ($item instanceof IR\Assign) {
            $bound = resolveEvidenceBinding($item->value, $evidenceByName, $tempEvidence, $localEvidence);
            if ($bound !== null) {
                $tempEvidence[$item->dest] = $bound;
            }
        }

        if ($item instanceof IR\Let) {
            $bound = resolveEvidenceBinding($item->value, $evidenceByName, $tempEvidence, $localEvidence);
            if ($bound !== null) {
                $localEvidence[$item->name] = $bound;
            }
        }

        $out[] = $item;
    }

    return new IR\Block($out);
}

/**
 * @param array<string, IR\InstanceEvidence> $evidenceByName
 * @param array<int, array{name: string, args: list<IR\Operand>}> $tempEvidence
 * @param array<string, array{name: string, args: list<IR\Operand>}> $localEvidence
 * @return array{name: string, args: list<IR\Operand>}|null
 */
function resolveEvidenceBinding(
    IR\Operand $value,
    array $evidenceByName,
    array $tempEvidence,
    array $localEvidence,
): ?array {
    if ($value instanceof IR\FnRef
        && (isset($evidenceByName[$value->name]) || str_starts_with($value->name, '__ev_'))) {
        return ['name' => $value->name, 'args' => []];
    }

    if ($value instanceof IR\ExprCall
        && (isset($evidenceByName[$value->callee]) || str_starts_with($value->callee, '__ev_'))) {
        return ['name' => $value->callee, 'args' => $value->args];
    }

    if ($value instanceof IR\Temp && isset($tempEvidence[$value->id])) {
        return $tempEvidence[$value->id];
    }

    if ($value instanceof IR\Local && isset($localEvidence[$value->name])) {
        return $localEvidence[$value->name];
    }

    return null;
}

/**
 * @param array<string, IR\InstanceEvidence> $evidenceByName
 * @param array<int, array{name: string, args: list<IR\Operand>}> $tempEvidence
 * @param array<string, array{name: string, args: list<IR\Operand>}> $localEvidence
 */
function specializeDictCall(
    IR\DictCall $stmt,
    array $evidenceByName,
    array $tempEvidence = [],
    array $localEvidence = [],
    array $localFns = [],
): IR\Stmt {
    $evidence = $stmt->evidence;
    $method = $stmt->method;
    $args = $stmt->args;
    $dest = $stmt->dest;

    if ($evidence instanceof IR\FnRef) {
        if ($method === '>>=' && count($args) === 2) {
            if (evidenceIs($evidence, 'Monad', 'List')) {
                return new IR\Assign($dest, new IR\Intrinsic('list_bind', $args));
            }
        }

        $resolved = resolveMethodCallee($evidence->name, $method, $evidenceByName);
        if ($resolved !== null) {
            // Nullary evidence FnRef: method takes only the dictionary method args.
            $ev = $evidenceByName[$evidence->name] ?? null;
            if ($ev !== null && $ev->contextParams !== []) {
                // Unsaturated factory — not a dictionary value.
                return $stmt;
            }

            if (isset($localFns[$resolved])) {
                return new IR\Call($resolved, $args, $dest);
            }

            return $stmt;
        }

        return $stmt;
    }

    $binding = null;
    if ($evidence instanceof IR\Temp) {
        $binding = $tempEvidence[$evidence->id] ?? null;
    } elseif ($evidence instanceof IR\Local) {
        $binding = $localEvidence[$evidence->name] ?? null;
    }

    if ($binding === null) {
        return $stmt;
    }

    $resolved = resolveMethodCallee($binding['name'], $method, $evidenceByName);
    if ($resolved === null) {
        return $stmt;
    }

    if ($localFns !== [] && !isset($localFns[$resolved])) {
        return $stmt;
    }

    return new IR\Call($resolved, [...$binding['args'], ...$args], $dest);
}

/**
 * @param array<string, IR\InstanceEvidence> $evidenceByName
 */
function resolveMethodCallee(
    string $evidenceName,
    string $method,
    array $evidenceByName,
): ?string {
    $ev = $evidenceByName[$evidenceName] ?? null;
    if ($ev !== null) {
        return $ev->methods[$method] ?? null;
    }

    // Do not invent `$evidence_$method` names for unknown evidence. Cross-module
    // evidence stays as DictCall until import maps / specialize resolve it —
    // inventing hashed names that are not in the IR is unsafe.
    return null;
}

/** @param array<string, IR\InstanceEvidence> $evidenceByName */
function specializeDictCallsOperand(IR\Operand $operand, array $evidenceByName = []): IR\Operand
{
    return match ($operand::class) {
        IR\ExprBinop::class => new IR\ExprBinop(
            $operand->op,
            specializeDictCallsOperand($operand->left, $evidenceByName),
            specializeDictCallsOperand($operand->right, $evidenceByName),
        ),
        IR\ExprCall::class => new IR\ExprCall(
            $operand->callee,
            \array_map(
                static fn (IR\Operand $a): IR\Operand => specializeDictCallsOperand($a, $evidenceByName),
                $operand->args,
            ),
            $operand->srcLoc,
        ),
        IR\ExprCallValue::class => new IR\ExprCallValue(
            specializeDictCallsOperand($operand->callee, $evidenceByName),
            \array_map(
                static fn (IR\Operand $a): IR\Operand => specializeDictCallsOperand($a, $evidenceByName),
                $operand->args,
            ),
            $operand->srcLoc,
        ),
        IR\ExprPartial::class => new IR\ExprPartial(
            $operand->fn,
            $operand->arity,
            \array_map(
                static fn (IR\Operand $a): IR\Operand => specializeDictCallsOperand($a, $evidenceByName),
                $operand->args,
            ),
        ),
        IR\Intrinsic::class => new IR\Intrinsic(
            $operand->name,
            \array_map(
                static fn (IR\Operand $a): IR\Operand => specializeDictCallsOperand($a, $evidenceByName),
                $operand->args,
            ),
            $operand->srcLoc,
        ),
        IR\DictMethod::class => specializeDictMethodOperand($operand, $evidenceByName),
        default => $operand,
    };
}

/** @param array<string, IR\InstanceEvidence> $evidenceByName */
function specializeDictMethodOperand(IR\DictMethod $operand, array $evidenceByName): IR\Operand
{
    $evidence = $operand->evidence;
    if ($evidence instanceof IR\FnRef) {
        $ev = $evidenceByName[$evidence->name] ?? null;
        if ($ev !== null && $ev->contextParams === []) {
            $ir = $ev->methods[$operand->method] ?? null;
            if ($ir !== null) {
                return new IR\FnRef($ir);
            }
        }
        // Cross-module DictMethod stays as DictMethod; emit-time resolution
        // + import filtering handle nullary calls.
    }

    return new IR\DictMethod(specializeDictCallsOperand($evidence, $evidenceByName), $operand->method);
}

/**
 * @param list<IR\InstanceEvidence> $evidence
 * @return array<string, IR\InstanceEvidence>
 */
function indexEvidenceByName(array $evidence): array
{
    $out = [];
    foreach ($evidence as $ev) {
        $out[$ev->evidenceName] = $ev;
    }

    return $out;
}

/**
 * @param list<IR\FunctionDecl> $functions
 * @param array<string, IR\InstanceEvidence> $evidenceByName
 * @return list<IR\FunctionDecl>
 */
function specializeDictCallsFunctions(array $functions, array $evidenceByName): array
{
    $localFns = [];
    foreach ($functions as $function) {
        $localFns[$function->name] = true;
    }

    return \array_map(
        static function (IR\FunctionDecl $function) use ($evidenceByName, $localFns): IR\FunctionDecl {
            if ($function->ioStraightLine) {
                return $function;
            }

            return $function->withBody(
                specializeDictCallsBlockWithEnv($function->body, $evidenceByName, [], [], $localFns),
            );
        },
        $functions,
    );
}
