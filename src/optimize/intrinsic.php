<?php declare(strict_types=1);

namespace Moggi\Optimize\Intrinsic;

use Moggi\IR;

use function Moggi\Optimize\Support\preserveMatchArm;

function eliminateIntrinsicWrappers(IR\Module $module): IR\Module
{
    $referenced = collectReferencedFunctions($module);
    $wrappers = [];
    foreach ($module->functions as $function) {
        $spec = intrinsicWrapperSpec($function);
        if ($spec !== null && !isset($referenced[$function->name])) {
            $wrappers[$function->name] = $spec;
        }
    }

    if ($wrappers === []) {
        return $module;
    }

    $evidenceMethods = [];
    foreach ($module->instanceEvidence as $evidence) {
        foreach ($evidence->methods as $irName) {
            $evidenceMethods[$irName] = true;
        }
    }

    $wrappers = array_filter(
        $wrappers,
        static fn (array $spec, string $name): bool => !isset($evidenceMethods[$name]),
        ARRAY_FILTER_USE_BOTH,
    );

    if ($wrappers === []) {
        return $module;
    }

    $functions = [];
    foreach ($module->functions as $function) {
        if (isset($wrappers[$function->name])) {
            continue;
        }

        $functions[] = $function->withBody(
            new IR\Block(\array_map(
                static fn (IR\Stmt $stmt): IR\Stmt => rewriteIntrinsicWrapperStmt($stmt, $wrappers),
                $function->body->items,
            )),
        );
    }

    return new IR\Module($functions, $module->data, $module->instanceEvidence, $module->entry, $module->moduleName, $module->sourceFile);
}

/**
 * @param IR\FunctionDecl $function
 * @return array{intrinsic: string, arity: int, srcLoc: ?IR\SrcLoc}|null
 */
function intrinsicWrapperSpec(IR\FunctionDecl $function): ?array
{
    if (!($function->instanceMethod)) {
        return null;
    }

    if ($function->export) {
        return null;
    }

    $items = $function->body->items;
    if (count($items) !== 1 || !($items[0] instanceof IR\Ret)) {
        return null;
    }

    $value = $items[0]->value;
    if (!($value instanceof IR\Intrinsic)) {
        return null;
    }

    $params = $function->params;
    if (count($value->args) !== count($params)) {
        return null;
    }

    foreach ($params as $i => $param) {
        $arg = $value->args[$i];
        if (!$arg instanceof IR\Local || $arg->name !== $param) {
            return null;
        }
    }

    return [
        'intrinsic' => $value->name,
        'arity' => count($params),
        'srcLoc' => $value->srcLoc,
    ];
}

/**
 * @param array<string, array{intrinsic: string, arity: int, srcLoc: ?IR\SrcLoc}> $wrappers
 * @param IR\Stmt $stmt
 */
function rewriteIntrinsicWrapperStmt(IR\Stmt $stmt, array $wrappers): IR\Stmt
{
    return match ($stmt::class) {
        IR\Ret::class => new IR\Ret(rewriteIntrinsicWrapperOperand($stmt->value, $wrappers)),
        IR\Assign::class => new IR\Assign(
            $stmt->dest,
            rewriteIntrinsicWrapperOperand($stmt->value, $wrappers),
        ),
        IR\Let::class => new IR\Let(
            $stmt->name,
            rewriteIntrinsicWrapperOperand($stmt->value, $wrappers),
        ),
        IR\Binop::class => new IR\Binop($stmt->op, rewriteIntrinsicWrapperOperand($stmt->left, $wrappers), rewriteIntrinsicWrapperOperand($stmt->right, $wrappers), $stmt->dest),
        IR\Call::class => rewriteIntrinsicWrapperCall($stmt, $wrappers),
        IR\CallValue::class => new IR\CallValue(rewriteIntrinsicWrapperOperand($stmt->callee, $wrappers), \array_map(
            static fn (IR\Operand $operand): IR\Operand => rewriteIntrinsicWrapperOperand($operand, $wrappers),
            $stmt->args,
        ), $stmt->dest, $stmt->srcLoc),
        IR\MatchStmt::class, IR\MatchReturn::class => rewriteIntrinsicWrapperMatch($stmt, $wrappers),
        IR\TailRecall::class => $stmt,
        default => $stmt,
    };
}

/**
 * @param array<string, array{intrinsic: string, arity: int, srcLoc: ?IR\SrcLoc}> $wrappers
 * @param IR\Stmt $stmt
 */
function rewriteIntrinsicWrapperCall(IR\Stmt $stmt, array $wrappers): IR\Stmt
{
    $spec = $wrappers[$stmt->callee] ?? null;
    if ($spec !== null && count($stmt->args) === $spec['arity']) {
        return new IR\Assign(
            $stmt->dest,
            intrinsicOperand($spec['intrinsic'], $stmt->args, $spec['srcLoc']),
        );
    }

    return new IR\Call($stmt->callee, \array_map(
        static fn (IR\Operand $operand): IR\Operand => rewriteIntrinsicWrapperOperand($operand, $wrappers),
        $stmt->args,
    ), $stmt->dest);
}

/**
 * @param array<string, array{intrinsic: string, arity: int, srcLoc: ?IR\SrcLoc}> $wrappers
 * @param IR\Stmt $stmt
 */
function rewriteIntrinsicWrapperMatch(IR\Stmt $stmt, array $wrappers): IR\Stmt
{
    $arms = \array_map(
        static fn (IR\MatchArm $arm): IR\MatchArm => preserveMatchArm(
            $arm,
            new IR\Block(\array_map(
                static fn (IR\Stmt $inner): IR\Stmt => rewriteIntrinsicWrapperStmt($inner, $wrappers),
                $arm->body->items,
            )),
        ),
        $stmt->arms,
    );
    $scrutinee = rewriteIntrinsicWrapperOperand($stmt->scrutinee, $wrappers);

    if ($stmt instanceof IR\MatchStmt) {
        return new IR\MatchStmt($scrutinee, $arms, $stmt->dest, $stmt->exhaustive);
    }

    return new IR\MatchReturn($scrutinee, $arms, $stmt->exhaustive);
}

/** @param list<IR\Operand> $args */
function intrinsicOperand(string $name, array $args, ?IR\SrcLoc $srcLoc = null): IR\Operand
{
    return new IR\Intrinsic($name, $args, $srcLoc);
}

/**
 * @param array<string, array{intrinsic: string, arity: int, srcLoc: ?IR\SrcLoc}> $wrappers
 * @param IR\Operand $operand
 */
function rewriteIntrinsicWrapperOperand(IR\Operand $operand, array $wrappers): IR\Operand
{
    if ($operand instanceof IR\ExprCall && isset($wrappers[$operand->callee])) {
        $spec = $wrappers[$operand->callee];
        if (count($operand->args) === $spec['arity']) {
            return intrinsicOperand($spec['intrinsic'], $operand->args, $spec['srcLoc']);
        }
    }

    return match ($operand::class) {
        IR\ExprBinop::class => new IR\ExprBinop($operand->op, rewriteIntrinsicWrapperOperand($operand->left, $wrappers), rewriteIntrinsicWrapperOperand($operand->right, $wrappers)),
        IR\ExprCall::class => new IR\ExprCall($operand->callee, \array_map(
            static fn (IR\Operand $arg): IR\Operand => rewriteIntrinsicWrapperOperand($arg, $wrappers),
            $operand->args,
        ), $operand->srcLoc),
        IR\ExprCallValue::class => new IR\ExprCallValue(rewriteIntrinsicWrapperOperand($operand->callee, $wrappers), \array_map(
            static fn (IR\Operand $arg): IR\Operand => rewriteIntrinsicWrapperOperand($arg, $wrappers),
            $operand->args,
        ), $operand->srcLoc),
        IR\ExprPartial::class => new IR\ExprPartial($operand->fn, $operand->arity, \array_map(
            static fn (IR\Operand $arg): IR\Operand => rewriteIntrinsicWrapperOperand($arg, $wrappers),
            $operand->args,
        )),
        IR\Partial::class => new IR\Partial($operand->fn, $operand->arity, \array_map(
            static fn (IR\Operand $arg): IR\Operand => rewriteIntrinsicWrapperOperand($arg, $wrappers),
            $operand->args,
        )),
        default => $operand,
    };
}

/** @return array<string, true> */
function collectReferencedFunctions(IR\Module $module): array
{
    $refs = [];
    foreach ($module->functions as $function) {
        collectReferencedFunctionsBlock($function->body, $refs);
    }

    return $refs;
}

/** @param array<string, true> $refs */
function collectReferencedFunctionsBlock(IR\Block $block, array &$refs): void
{
    foreach ($block->items as $item) {
        collectReferencedFunctionsStmt($item, $refs);
    }
}

/** @param array<string, true> $refs */
function collectReferencedFunctionsStmt(IR\Stmt $stmt, array &$refs): void
{
    match ($stmt::class) {
        IR\Call::class => addReferencedFunction($stmt->callee, $refs),
        IR\CallValue::class => collectReferencedFunctionsOperand($stmt->callee, $refs),
        IR\Assign::class, IR\Let::class, IR\Ret::class => collectReferencedFunctionsOperand($stmt->value, $refs),
        IR\Binop::class => (static function () use ($stmt, &$refs): void {
            collectReferencedFunctionsOperand($stmt->left, $refs);
            collectReferencedFunctionsOperand($stmt->right, $refs);
        })(),
        IR\MatchStmt::class, IR\MatchReturn::class => (static function () use ($stmt, &$refs): void {
            collectReferencedFunctionsOperand($stmt->scrutinee, $refs);
            foreach ($stmt->arms as $arm) {
                collectReferencedFunctionsBlock($arm->body, $refs);
            }
        })(),
        IR\Loop::class => collectReferencedFunctionsBlock($stmt->body, $refs),
        IR\TailRecall::class => null,
        default => null,
    };
}

/** @param array<string, true> $refs */
function collectReferencedFunctionsOperand(IR\Operand $operand, array &$refs): void
{
    match ($operand::class) {
        IR\FnRef::class => addReferencedFunction($operand->name, $refs),
        IR\Partial::class, IR\ExprPartial::class => (static function () use ($operand, &$refs): void {
            addReferencedFunction($operand->fn, $refs);
            foreach ($operand->args as $arg) {
                collectReferencedFunctionsOperand($arg, $refs);
            }
        })(),
        IR\ExprCall::class => (static function () use ($operand, &$refs): void {
            addReferencedFunction($operand->callee, $refs);
            foreach ($operand->args as $arg) {
                collectReferencedFunctionsOperand($arg, $refs);
            }
        })(),
        IR\ExprCallValue::class => (static function () use ($operand, &$refs): void {
            collectReferencedFunctionsOperand($operand->callee, $refs);
            foreach ($operand->args as $arg) {
                collectReferencedFunctionsOperand($arg, $refs);
            }
        })(),
        IR\ExprBinop::class => (static function () use ($operand, &$refs): void {
            collectReferencedFunctionsOperand($operand->left, $refs);
            collectReferencedFunctionsOperand($operand->right, $refs);
        })(),
        IR\Intrinsic::class => (static function () use ($operand, &$refs): void {
            foreach ($operand->args as $arg) {
                collectReferencedFunctionsOperand($arg, $refs);
            }
        })(),
        default => null,
    };
}

/** @param array<string, true> $refs */
function addReferencedFunction(string $name, array &$refs): void
{
    if (!str_contains($name, '\\')) {
        $refs[$name] = true;
    }
}
