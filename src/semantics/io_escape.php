<?php declare(strict_types=1);

namespace Moggi\Semantics\IoEscape;

use Moggi\IR\IoBodyKind;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\IoBoundary\functionReturnsIo;
use function Moggi\Semantics\IoBoundary\isIoType;
use function Moggi\Semantics\IoBoundary\isIoUnitType;
use function Moggi\Semantics\IoBoundary\stripConstraints;

/** True for Data.Foo.PHP / Data.Foo.JVM / Data.Foo.DotNet / … backend implementation modules. */
function isBackendImplModuleName(string $moduleName): bool
{
    $lower = strtolower($moduleName);
    foreach (['.php', '.jvm', '.js', '.dotnet'] as $suffix) {
        if (str_ends_with($lower, $suffix)) {
            return true;
        }
    }

    return false;
}

function analyze(Ast\Program $program): Ast\Program
{
    $backendImpl = isBackendImplModuleName($program->module ?? '');

    foreach ($program->items as $item) {
        if (!$item instanceof Ast\FunctionDecl || !functionReturnsIo($item->type)) {
            continue;
        }

        if ($item->signatureOnly) {
            continue;
        }

        annotateFunction($item, $backendImpl);
    }

    return $program;
}

function annotateFunction(Ast\FunctionDecl $fn, bool $backendImpl = false): void
{
    $body = $fn->body;
    $hasNonFoldAction = false;
    $hasIoParam = functionHasIoParam($fn->type);

    if ($body instanceof Ast\IoSequence) {
        annotateIoSequence($body->stmts, $hasNonFoldAction);
    }

    if ($backendImpl || $fn->foreign || isForeignIoBody($body)) {
        $fn->ioBodyKind = IoBodyKind::StraightLine;
        return;
    }

    // Functor/Applicative/Monad IO methods are dictionary entries: callers must
    // receive a boxed action, not an eagerly executed effect.
    if ($fn->instanceMethod) {
        $fn->ioBodyKind = IoBodyKind::ActionReturn;
        return;
    }

    if (isActionReturnBody($body, $fn->type)) {
        $fn->ioBodyKind = IoBodyKind::ActionReturn;
    } elseif ($hasIoParam || $hasNonFoldAction) {
        $fn->ioBodyKind = IoBodyKind::Mixed;
    } else {
        $fn->ioBodyKind = IoBodyKind::StraightLine;
    }
}

/** @param list<Ast\AstNode> $stmts */
function annotateIoSequence(array &$stmts, bool &$hasNonFoldAction): void
{
    foreach ($stmts as $i => $stmt) {
        if (!$stmt instanceof Ast\IoLetAction) {
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

        if (!$foldHint) {
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

    for ($i = $start, $n = count($stmts); $i < $n; ++$i) {
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

    foreach (get_object_vars($expr) as $key => $value) {
        if ($key === 'inferredType' || $key === 'pendingConstraints') {
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

function isActionReturnBody(Ast\AstNode $body, ?Ast\AstNode $type): bool
{
    if (!$body instanceof Ast\IoAction) {
        return false;
    }

    return !functionReturnsIoUnit($type);
}

function functionReturnsIoUnit(?Ast\AstNode $type): bool
{
    if ($type === null) {
        return false;
    }

    $result = stripConstraints($type);
    while ($result instanceof Ast\TypeArrow) {
        $result = $result->to;
    }

    return isIoType($result) && isIoUnitType($result);
}

function functionHasIoParam(?Ast\AstNode $type): bool
{
    if ($type === null) {
        return false;
    }

    $cursor = stripConstraints($type);
    while ($cursor instanceof Ast\TypeArrow) {
        if (isIoType($cursor->from)) {
            return true;
        }
        $cursor = $cursor->to;
    }

    return false;
}
