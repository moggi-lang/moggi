<?php declare(strict_types=1);

namespace Moggi\Optimize\Effects;

use Moggi\IR;
use Moggi\Syntax\Ast;

use function Moggi\Modules\resolvedSymbol;
use function Moggi\Optimize\Interproc\indexFunctions;
use function Moggi\Semantics\IoBoundary\typeMentionsIo;

/** @param list<IR\FunctionDecl> $functions @return list<IR\FunctionDecl> */
function annotateIoEffects(array $functions, string $moduleName = ''): array
{
    $index = indexFunctions($functions, $moduleName);
    $changed = true;

    while ($changed) {
        $changed = false;
        foreach ($functions as $i => $function) {
            $ioEffect = functionIoEffect($function, $index);
            if ($function->ioEffect !== $ioEffect) {
                $function->ioEffect = $ioEffect;
                $index[$function->name]->ioEffect = $ioEffect;
                $changed = true;
            }
        }
    }

    return $functions;
}

/** @param array<string, IR\FunctionDecl> $index */
function functionIoEffect(IR\FunctionDecl $function, array $index): bool
{
    if ($function->type !== null && astTypeMentionsIo($function->type)) {
        return true;
    }

    return blockHasIoEffect($function->body, $index);
}

function astTypeMentionsIo(Ast\AstNode $type): bool
{
    return typeMentionsIo($type);
}

/** @param array<string, IR\FunctionDecl> $index */
function blockHasIoEffect(IR\Block $block, array $index): bool
{
    foreach ($block->items as $item) {
        if (stmtHasIoEffect($item, $index)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, IR\FunctionDecl> $index */
function stmtHasIoEffect(IR\Stmt $stmt, array $index): bool
{
    return match ($stmt::class) {
        IR\IoCall::class, IR\IoRun::class,
        IR\IoThrow::class, IR\IoCatch::class, IR\IoFinally::class => true,
        IR\IoAssignAction::class => false,
        IR\Call::class => calleeIoEffect($stmt->callee, $index),
        IR\CallValue::class => operandHasIoEffect($stmt->callee, $index)
            || operandsHaveIoEffect($stmt->args, $index),
        IR\Assign::class, IR\Let::class => operandHasIoEffect($stmt->value, $index),
        IR\Binop::class => operandHasIoEffect($stmt->left, $index) || operandHasIoEffect($stmt->right, $index),
        IR\Ret::class => operandHasIoEffect($stmt->value, $index),
        IR\MatchStmt::class, IR\MatchReturn::class, IR\IoMatch::class => operandHasIoEffect($stmt->scrutinee, $index)
            || matchArmsHaveIoEffect($stmt->arms, $index),
        IR\Loop::class => blockHasIoEffect($stmt->body, $index),
        default => false,
    };
}

/** @param list<IR\MatchArm> $arms @param array<string, IR\FunctionDecl> $index */
function matchArmsHaveIoEffect(array $arms, array $index): bool
{
    foreach ($arms as $arm) {
        if (blockHasIoEffect($arm->body, $index)) {
            return true;
        }
    }

    return false;
}

/** @param list<IR\Operand> $operands @param array<string, IR\FunctionDecl> $index */
function operandsHaveIoEffect(array $operands, array $index): bool
{
    foreach ($operands as $operand) {
        if (operandHasIoEffect($operand, $index)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, IR\FunctionDecl> $index */
function calleeIoEffect(string $callee, array $index): bool
{
    return $index[$callee]->ioEffect ?? false;
}

/** @param array<string, IR\FunctionDecl> $index */
function operandHasIoEffect(IR\Operand $operand, array $index): bool
{
    return match ($operand::class) {
        IR\Intrinsic::class => false,
        IR\ExprCall::class => calleeIoEffect($operand->callee, $index),
        IR\ExprCallValue::class => operandHasIoEffect($operand->callee, $index)
            || operandsHaveIoEffect($operand->args, $index),
        IR\ExprBinop::class => operandHasIoEffect($operand->left, $index)
            || operandHasIoEffect($operand->right, $index),
        IR\FnRef::class => calleeIoEffect($operand->name, $index),
        default => false,
    };
}

/**
 * Host-effect = may execute foreign imports (including Unit-returning ones).
 * Used by DCE so unused temps that only exist to sequence FFI are not deleted
 * after Unit case-fold (which would drop the foreign call entirely).
 *
 * @param list<IR\FunctionDecl> $functions
 * @return array<string, true> callee names with host effect
 */
function hostEffectFunctionNames(array $functions, string $moduleName = ''): array
{
    $index = indexFunctions($functions, $moduleName);
    /** @var array<string, true> $host */
    $host = [];
    foreach ($functions as $function) {
        if ($function->foreign || blockHasDirectForeign($function->body)) {
            $host[$function->name] = true;
            if ($moduleName !== '' && !str_contains($function->name, '::')) {
                $host[resolvedSymbol($moduleName, $function->name)] = true;
            }
        }
    }

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($functions as $function) {
            if (isset($host[$function->name])) {
                continue;
            }
            if (blockCallsHostEffect($function->body, $host, $index)) {
                $host[$function->name] = true;
                if ($moduleName !== '' && !str_contains($function->name, '::')) {
                    $host[resolvedSymbol($moduleName, $function->name)] = true;
                }
                $changed = true;
            }
        }
    }

    return $host;
}

function blockHasDirectForeign(IR\Block $block): bool
{
    foreach ($block->items as $item) {
        if (stmtHasDirectForeign($item)) {
            return true;
        }
    }

    return false;
}

function stmtHasDirectForeign(IR\Stmt $stmt): bool
{
    return match ($stmt::class) {
        IR\Assign::class, IR\Let::class, IR\Ret::class => operandHasDirectForeign($stmt->value),
        IR\Binop::class => operandHasDirectForeign($stmt->left) || operandHasDirectForeign($stmt->right),
        IR\Call::class => operandsHaveDirectForeign($stmt->args),
        IR\CallValue::class => operandHasDirectForeign($stmt->callee) || operandsHaveDirectForeign($stmt->args),
        IR\DictCall::class => operandHasDirectForeign($stmt->evidence) || operandsHaveDirectForeign($stmt->args),
        IR\MatchStmt::class, IR\MatchReturn::class, IR\IoMatch::class => operandHasDirectForeign($stmt->scrutinee)
            || matchArmsHaveDirectForeign($stmt->arms),
        IR\Loop::class => blockHasDirectForeign($stmt->body),
        IR\IoCall::class => $stmt->foreign !== null,
        default => false,
    };
}

/** @param list<IR\MatchArm> $arms */
function matchArmsHaveDirectForeign(array $arms): bool
{
    foreach ($arms as $arm) {
        if (blockHasDirectForeign($arm->body)) {
            return true;
        }
    }

    return false;
}

/** @param list<IR\Operand> $operands */
function operandsHaveDirectForeign(array $operands): bool
{
    foreach ($operands as $operand) {
        if (operandHasDirectForeign($operand)) {
            return true;
        }
    }

    return false;
}

function operandHasDirectForeign(IR\Operand $operand): bool
{
    return match ($operand::class) {
        IR\ForeignCall::class => true,
        IR\ExprCall::class => operandsHaveDirectForeign($operand->args),
        IR\ExprCallValue::class => operandHasDirectForeign($operand->callee)
            || operandsHaveDirectForeign($operand->args),
        IR\ExprBinop::class => operandHasDirectForeign($operand->left)
            || operandHasDirectForeign($operand->right),
        IR\ListLit::class => operandsHaveDirectForeign($operand->elements),
        IR\Partial::class => operandsHaveDirectForeign($operand->args),
        IR\DictMethod::class => operandHasDirectForeign($operand->evidence),
        default => false,
    };
}

/**
 * @param array<string, true> $host
 * @param array<string, IR\FunctionDecl> $index
 */
function blockCallsHostEffect(IR\Block $block, array $host, array $index): bool
{
    foreach ($block->items as $item) {
        if (stmtCallsHostEffect($item, $host, $index)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, true> $host
 * @param array<string, IR\FunctionDecl> $index
 */
function stmtCallsHostEffect(IR\Stmt $stmt, array $host, array $index): bool
{
    return match ($stmt::class) {
        IR\Call::class => isset($host[$stmt->callee]),
        IR\Assign::class, IR\Let::class, IR\Ret::class => operandCallsHostEffect($stmt->value, $host),
        IR\CallValue::class => operandCallsHostEffect($stmt->callee, $host)
            || operandsCallHostEffect($stmt->args, $host),
        IR\MatchStmt::class, IR\MatchReturn::class, IR\IoMatch::class => operandCallsHostEffect($stmt->scrutinee, $host)
            || matchArmsCallHostEffect($stmt->arms, $host, $index),
        IR\Loop::class => blockCallsHostEffect($stmt->body, $host, $index),
        default => false,
    };
}

/**
 * @param list<IR\MatchArm> $arms
 * @param array<string, true> $host
 * @param array<string, IR\FunctionDecl> $index
 */
function matchArmsCallHostEffect(array $arms, array $host, array $index): bool
{
    foreach ($arms as $arm) {
        if (blockCallsHostEffect($arm->body, $host, $index)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, true> $host */
function operandCallsHostEffect(IR\Operand $operand, array $host): bool
{
    if ($operand instanceof IR\ExprCall) {
        return isset($host[$operand->callee]) || operandsCallHostEffect($operand->args, $host);
    }
    if ($operand instanceof IR\FnRef) {
        return isset($host[$operand->name]);
    }
    if ($operand instanceof IR\ExprCallValue) {
        return operandCallsHostEffect($operand->callee, $host)
            || operandsCallHostEffect($operand->args, $host);
    }
    if ($operand instanceof IR\Partial) {
        return isset($host[$operand->fn]) || operandsCallHostEffect($operand->args, $host);
    }

    return false;
}

/**
 * @param list<IR\Operand> $operands
 * @param array<string, true> $host
 */
function operandsCallHostEffect(array $operands, array $host): bool
{
    foreach ($operands as $operand) {
        if (operandCallsHostEffect($operand, $host)) {
            return true;
        }
    }

    return false;
}
