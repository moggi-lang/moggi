<?php declare(strict_types=1);

namespace Moggi\Semantics\IoEscape;

use Moggi\IR\ActionConvention;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\IoBoundary\functionReturnsIo;
use function Moggi\Semantics\IoBoundary\isIoType;
use function Moggi\Semantics\IoBoundary\isIoUnitType;
use function Moggi\Semantics\IoBoundary\stripConstraints;

function analyze(Ast\Program $program): Ast\Program
{
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl) {
            continue;
        }

        if ($item->signatureOnly) {
            continue;
        }

        if (! functionReturnsIo($item->type) && ! returnsMonadicValue($item->type)) {
            continue;
        }

        annotateFunction($item);
    }

    return $program;
}

/**
 * The one producer of a declaration's result convention.
 *
 * A foreign import is the FFI worker: it performs the host call and answers with the value. Every
 * other declaration whose result is `IO a` — or a monadic variable application, which is an action
 * at any instantiation — answers with the action value instead.
 *
 * Nothing here looks at the body's shape, the module's name or the declaration's instance status;
 * the body is walked only to record the do-block binding hints lowering uses, which say where an
 * action can be run in place, not what the entry's convention is.
 */
function annotateFunction(Ast\FunctionDecl $fn): void
{
    if ($fn->body instanceof Ast\IoSequence) {
        $hasNonFoldAction = false;
        annotateIoSequence($fn->body->stmts, $hasNonFoldAction);
    }

    $fn->actionConvention = ($fn->foreign || isForeignIoBody($fn->body))
        ? ActionConvention::Executes
        : ActionConvention::ReturnsAction;
}

/** @param list<Ast\AstNode> $stmts */
function annotateIoSequence(array &$stmts, bool &$hasNonFoldAction): void
{
    foreach ($stmts as $i => $stmt) {
        if (! $stmt instanceof Ast\IoLetAction) {
            continue;
        }

        $name = actionBindingName($stmt->pattern);
        if ($name === null) {
            $stmt->ioFoldHint = false;
            $hasNonFoldAction = true;

            continue;
        }

        $uses = collectActionUses($name, $stmts, $i + 1);
        $foldHint = $uses['run'] === 1 && $uses['escape'] === 0;
        $stmt->ioFoldHint = $foldHint;

        if (! $foldHint) {
            $hasNonFoldAction = true;
        }
    }
}

function actionBindingName(Ast\AstNode $pattern): ?string
{
    return $pattern instanceof Ast\PatVar ? $pattern->name : null;
}

/**
 * @param list<Ast\AstNode> $stmts
 * @return array{run: int, escape: int}
 */
function collectActionUses(string $name, array $stmts, int $start): array
{
    $run = 0;
    $escape = 0;

    for ($i = $start, $n = \count($stmts); $i < $n; ++$i) {
        $stmt = $stmts[$i];
        if ($stmt instanceof Ast\IoBind) {
            if (exprIsVariableRef($stmt->expr, $name)) {
                ++$run;

                continue;
            }

            if (exprMentionsVariable($stmt->expr, $name)) {
                ++$escape;
            }

            continue;
        }

        if ($stmt instanceof Ast\IoExpr) {
            $expr = $stmt->expr;
            if (exprIsVariableRef($expr, $name)) {
                ++$run;

                continue;
            }

            if (exprMentionsVariable($expr, $name)) {
                ++$escape;
            }
        }
    }

    return ['run' => $run, 'escape' => $escape];
}

function exprIsVariableRef(Ast\AstNode $expr, string $name): bool
{
    if ($expr instanceof Ast\IoAction) {
        $expr = $expr->expr;
    }

    return $expr instanceof Ast\Variable && $expr->name === $name;
}

function exprMentionsVariable(Ast\AstNode $expr, string $name): bool
{
    if ($expr instanceof Ast\Variable) {
        return $expr->name === $name;
    }

    foreach (\get_object_vars($expr) as $key => $value) {
        if ($key === 'inferredType' || $key === 'inferredInternalType' || $key === 'pendingConstraints') {
            continue;
        }

        if ($value instanceof Ast\AstNode && exprMentionsVariable($value, $name)) {
            return true;
        }

        if (\is_array($value)) {
            foreach ($value as $child) {
                if ($child instanceof Ast\AstNode && exprMentionsVariable($child, $name)) {
                    return true;
                }
            }
        }
    }

    return false;
}

function isForeignIoBody(Ast\AstNode $body): bool
{
    if ($body instanceof Ast\ForeignCall) {
        return true;
    }

    return $body instanceof Ast\IoAction && $body->expr instanceof Ast\ForeignCall;
}

/**
 * The declared result is a *monadic* type variable applied to arguments (`f ()`, `m a`): at any
 * instantiation — `IO` included — such a value is a description of an action, which callers receive
 * boxed and run.
 *
 * A bare type variable (`b`, as `maybe` and `id` return) is deliberately not included: it is a plain
 * value at whatever type the call site chose, and only the call site's position says whether it is an
 * action to run or an action to hand on.
 */
function returnsMonadicValue(?Ast\AstNode $type): bool
{
    if ($type === null) {
        return false;
    }

    $result = stripConstraints($type);
    while ($result instanceof Ast\TypeArrow) {
        $result = $result->to;
    }

    return $result instanceof Ast\TypeApp && $result->con instanceof Ast\TypeVar;
}

