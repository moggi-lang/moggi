<?php declare(strict_types=1);

namespace Moggi\Semantics\IoEscape;

use Moggi\IR\ActionConvention;
use Moggi\IR\IoStatementKind;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\IoBoundary\functionReturnsIo;
use function Moggi\Semantics\IoBoundary\ioStatementForm;
use function Moggi\Semantics\IoBoundary\isIoStatementExpression;
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

        // Every body's form is published, whatever the declaration returns: an IO body can sit in a
        // declaration the callback convention never asks about.
        if (isIoStatementExpression($item->body)) {
            annotateIoBodies($item->body);
        }

        if (! functionReturnsIo($item->type) && ! returnsMonadicValue($item->type)) {
            continue;
        }

        annotateFunction($item);
    }

    return $program;
}

/**
 * Publish every `do` statement's form, in the one place the shapes and their bindings are both known.
 *
 * The walk is the statement list's structure, not lowering's: each statement's expression is
 * classified by {@see ioStatementForm()} with the `let`-bound actions in scope at that point, and a
 * statement expression outside the four shapes this boundary knows refuses by class and line. Bodies
 * nested inside a statement (a `case` arm, a nested `do`) are walked with a *copy* of the deferred
 * set, because lowering copies its store into a nested body and does not take its writes back.
 *
 * @param array<int, true> $deferred the `let`-bound action binders this sequence inherits
 */
function annotateIoBodies(Ast\AstNode $expr, array $deferred = []): void
{
    // A `case` alternative may carry guards (`k | k > 0 -> …`), which reach the body as one
    // `GuardsExpr` whose clauses are that alternative's arms. The arm bodies are the IO bodies —
    // the case term flattens the clauses and lowering asks each of them for its form — so publish
    // on each clause body. The guard chain itself is neither a statement nor a body and no reader
    // asks it for a form, which is why it is not given one.
    if ($expr instanceof Ast\GuardsExpr) {
        foreach ($expr->clauses as $clause) {
            annotateIoBodies($clause->body, $deferred);
        }

        return;
    }

    if (! $expr instanceof Ast\IoStatementExpression) {
        throw new \RuntimeException(
            'the IO body at line ' . $expr->line . ' is a `' . $expr::class
            . '`, which this compiler does not classify as a statement expression',
        );
    }

    // The body's own form, exactly as a statement's: the two positions ask one question.
    $expr->ioForm = ioStatementForm($expr, $deferred);

    if ($expr instanceof Ast\IoSequence) {
        annotateIoSequence($expr->stmts, $deferred);

        return;
    }

    if ($expr instanceof Ast\IoCase) {
        foreach ($expr->alts as $alt) {
            annotateIoBodies($alt->body, $deferred);
        }

        return;
    }

    // A bare body (`main = putStrLn "hi"`): an action, and nothing more to publish than the form
    // already written above.
    if ($expr instanceof Ast\IoAction && $expr->expr instanceof Ast\IoSequence) {
        annotateIoBodies($expr->expr, $deferred);
    }
}

/**
 * @param list<Ast\AstNode> $stmts
 * @param array<int, true> $deferred
 */
function annotateIoSequence(array $stmts, array $deferred): void
{
    foreach ($stmts as $stmt) {
        // What the statement does with its expression, published where the whole list is in view:
        // lowering dispatches on this instead of asking the statement's class.
        $stmt->ioKind = match (true) {
            $stmt instanceof Ast\IoLet => IoStatementKind::Let,
            $stmt instanceof Ast\IoLetAction => IoStatementKind::LetAction,
            $stmt instanceof Ast\IoBind => IoStatementKind::Bind,
            $stmt instanceof Ast\IoExpr => IoStatementKind::Expr,
            default => throw new \RuntimeException(
                'the `do` statement at line ' . $stmt->line . ' is a `' . $stmt::class
                . '`, which has no published disposition',
            ),
        };

        if ($stmt instanceof Ast\IoLet) {
            continue;
        }

        if (! isIoStatementExpression($stmt->expr)) {
            throw new \RuntimeException(
                'the `do` statement at line ' . $stmt->line . ' is a `' . $stmt->expr::class
                . '`, which this compiler does not classify as a statement expression',
            );
        }

        $stmt->expr->ioForm = ioStatementForm($stmt->expr, $deferred);

        if ($stmt->expr instanceof Ast\IoSequence || $stmt->expr instanceof Ast\IoCase) {
            annotateIoBodies($stmt->expr, $deferred);
        } elseif ($stmt->expr instanceof Ast\IoAction
            && ($stmt->expr->expr instanceof Ast\IoSequence || $stmt->expr->expr instanceof Ast\IoCase)) {
            annotateIoBodies($stmt->expr->expr, $deferred);
        }

        if ($stmt instanceof Ast\IoLetAction && $stmt->pattern instanceof Ast\PatVar && $stmt->pattern->binderId !== null) {
            $deferred[$stmt->pattern->binderId] = true;
        }
    }
}

/**
 * The one producer of a declaration's result convention.
 *
 * A foreign import is the FFI worker: it performs the host call and answers with the value. Every
 * other declaration whose result is `IO a` — or a monadic variable application, which is an action
 * at any instantiation — answers with the action value instead.
 *
 * Nothing here looks at the body's shape, the module's name or the declaration's instance status.
 */
function annotateFunction(Ast\FunctionDecl $fn): void
{
    $fn->actionConvention = ($fn->foreign || isForeignIoBody($fn->body))
        ? ActionConvention::Executes
        : ActionConvention::ReturnsAction;

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

