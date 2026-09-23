<?php declare(strict_types=1);

namespace Moggi\Semantics\StrictIoNormalize;

use Moggi\Syntax\Ast;

use function Moggi\Semantics\IoBoundary\functionReturnsIo;
use function Moggi\Semantics\IoBoundary\hasLeadingArrow;
use function Moggi\Semantics\IoBoundary\ioBindParts;
use function Moggi\Semantics\IoBoundary\isIoMonadMethod;
use function Moggi\Semantics\IoBoundary\isIoType;
use function Moggi\Semantics\IoBoundary\stripConstraints;

/**
 * Normalize typed IO expressions into explicit strict sequencing before IR.
 *
 * Moggi IO is strict effect sequencing: these nodes mean "perform the effect
 * now in order". This is not a State monad and not a state token
 * threading — there is no world value at runtime.
 */
function normalize(Ast\Program $program): Ast\Program
{
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl) {
            $counter = 0;
            etaExpandIoBinding($item);
            $item->body = functionReturnsIo($item->type)
                ? normalizeIoBody($item->body, $counter)
                : normalizePureExpr($item->body, $counter);
        }
    }

    return $program;
}

/**
 * Give a parameterless IO-returning declaration the parameters its type
 * promises, so its body is lowered as the body of a function.
 *
 * Two written forms need this, and both are the same rewrite, applied before
 * the IO body is lowered:
 *
 * - `f = \a -> \b -> <io body>` is `f a b = <io body>`. A lambda is not an IO
 *   statement context, so leaving it in place normalizes its body as a *pure*
 *   expression — the effect would be silently dropped.
 * - `f = g` with `f :: A -> IO B` is the eta-reduced `f a = g a`. The type
 *   checker has already turned the body into the function *value* `g` (with its
 *   dictionaries applied), whose type is `A -> IO B`; without a parameter the IO
 *   lowering reads that value as the action itself and calls `g` with the
 *   dictionary arguments only.
 *
 * The remaining parameters come from the declared type, which is why arity is
 * counted off the signature rather than off the body. Pure bindings are left
 * alone: their lambdas and function values already lower correctly.
 */
function etaExpandIoBinding(Ast\FunctionDecl $item): void
{
    if ($item->params !== []
        || !hasLeadingArrow($item->type)
        || !functionReturnsIo($item->type)
    ) {
        return;
    }

    $params = [];
    $body = $item->body;
    while ($body instanceof Ast\Lambda) {
        foreach ($body->params as $param) {
            $params[] = $param instanceof Ast\LambdaParam ? $param->pattern : $param;
        }
        $body = $body->body;
    }

    while (\count($params) < arrowArity($item->type)) {
        $name = '__eta' . (\count($params) + 1);
        $pattern = new Ast\PatVar($name, $item->line, $item->col, $item->endCol);
        $params[] = $pattern;
        $body = new Ast\Apply(
            $body,
            new Ast\Variable($name, $item->line, $item->col, $item->endCol),
        );
    }

    $item->params = $params;
    $item->body = $body;
}

/** Number of leading arrows in a type, ignoring its constraints. */
function arrowArity(?Ast\TypeNode $type): int
{
    $cursor = $type === null ? null : stripConstraints($type);
    $arity = 0;
    while ($cursor instanceof Ast\TypeArrow) {
        ++$arity;
        $cursor = stripConstraints($cursor->to);
    }

    return $arity;
}

function normalizeIoBody(Ast\AstNode $expr, int &$counter): Ast\AstNode
{
    // `(action :: IO a)` is the action; the annotation says only what the type
    // checker already used. Classifying the wrapper instead of the expression
    // made every annotated action an opaque `IoAction`, so `(pure x :: IO a)`
    // stopped being a pure step and was lowered as a value: the effect was
    // built and never run.
    if ($expr instanceof Ast\TypeAsc) {
        $inner = normalizeIoBody($expr->expr, $counter);
        if ($inner->line === 0 && $expr->line > 0) {
            $inner->setLocation($expr->line, $expr->col, $expr->endCol);
        }

        return $inner;
    }

    $bind = ioBindParts($expr);
    if ($bind !== null) {
        return ioBindToSequence($bind['left'], $bind['right'], $counter);
    }

    if ($expr instanceof Ast\IntrinsicCall && $expr->name === 'ioBind#' && count($expr->args) === 2) {
        return ioBindToSequence($expr->args[0], $expr->args[1], $counter);
    }

    $pure = ioPureApply($expr);
    if ($pure !== null) {
        return new Ast\IoPure(normalizePureExpr($pure, $counter), $expr->line, $expr->col, $expr->endCol);
    }

    if ($expr instanceof Ast\IntrinsicCall && $expr->name === 'ioPure#' && count($expr->args) === 1) {
        return new Ast\IoPure(normalizePureExpr($expr->args[0], $counter), $expr->line, $expr->col, $expr->endCol);
    }

    if ($expr instanceof Ast\DoExpr) {
        // Typecheck always elaborates do → >>=; normalize that single path.
        if ($expr->desugared === null) {
            throw new \LogicException('IO do expression missing desugared form');
        }

        // The desugared `>>=` tree is synthesized and unpositioned. Carry the
        // original `do` span onto the result so IR lowering and source maps can
        // still locate the action (otherwise a whole body lowers at line 0).
        $normalized = normalizeIoBody($expr->desugared, $counter);
        if ($normalized->line === 0 && $expr->line > 0) {
            $normalized->setLocation($expr->line, $expr->col, $expr->endCol);
        }

        return $normalized;
    }

    if ($expr instanceof Ast\Let) {
        return normalizeIoLet($expr, $counter);
    }

    if ($expr instanceof Ast\Where) {
        // where-bindings are pure; only the body may perform IO.
        foreach ($expr->bindings as $binding) {
            $binding->value = normalizePureExpr($binding->value, $counter);
        }

        return normalizeIoBody($expr->expr, $counter);
    }

    if ($expr instanceof Ast\GuardsExpr) {
        // A guarded clause's body is an action of its own (`0 -> pure v | otherwise ->
        // …`); the guards are pure tests. The list is kept as a list: the arms
        // are tried in order, so a guard that fails has to fall through to the
        // next alternative rather than end the match.
        foreach ($expr->clauses as $clause) {
            $clause->guard = normalizePureExpr($clause->guard, $counter);
            $clause->body = normalizeIoBody($clause->body, $counter);
        }

        return $expr;
    }

    if ($expr instanceof Ast\CaseExpr) {
        foreach ($expr->alts as $alt) {
            $alt->body = normalizeIoBody($alt->body, $counter);
        }

        return new Ast\IoCase(
            normalizePureExpr($expr->scrutinee, $counter),
            $expr->alts,
            $expr->exhaustive,
            $expr->line,
            $expr->col,
            $expr->endCol,
        );
    }

    return new Ast\IoAction(normalizePureExpr($expr, $counter), $expr->line, $expr->col, $expr->endCol);
}

function normalizeIoLet(Ast\Let $expr, int &$counter): Ast\AstNode
{
    $stmts = [];
    $pureBindings = [];
    foreach ($expr->bindings as $binding) {
        if (exprHasIoType($binding->value)) {
            $stmts[] = new Ast\IoLetAction(
                $binding->pattern,
                normalizeIoBody($binding->value, $counter),
            );
            continue;
        }

        $binding->value = normalizePureExpr($binding->value, $counter);
        $pureBindings[] = $binding;
    }

    if ($pureBindings !== []) {
        $stmts[] = new Ast\IoLet($pureBindings);
    }

    $body = normalizeIoBody($expr->body, $counter);
    if ($body instanceof Ast\IoSequence) {
        array_push($stmts, ...$body->stmts);
    } else {
        $stmts[] = new Ast\IoExpr($body);
    }

    return new Ast\IoSequence($stmts, $expr->line, $expr->col, $expr->endCol);
}

function ioBindToSequence(Ast\AstNode $first, Ast\AstNode $fn, int &$counter): Ast\AstNode
{
    if ($fn instanceof Ast\Lambda && count($fn->params) === 1) {
        $param = $fn->params[0];
        $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;

        // Desugared do uses __doUnit / __doWild for discarded statements; omit the binder.
        if ($pattern instanceof Ast\PatVar
            && ($pattern->name === '__doUnit' || $pattern->name === '__doWild')) {
            return new Ast\IoSequence([
                ...ioBodyAsStmts(normalizeIoBody($first, $counter)),
                ...ioBodyAsStmts(normalizeIoBody($fn->body, $counter)),
            ]);
        }

        return new Ast\IoSequence([
            new Ast\IoBind($pattern, normalizeIoBody($first, $counter)),
            ...ioBodyAsStmts(normalizeIoBody($fn->body, $counter)),
        ]);
    }

    $name = '__ioValue' . $counter++;

    return new Ast\IoSequence([
        new Ast\IoBind(new Ast\PatVar($name), normalizeIoBody($first, $counter)),
        ...ioBodyAsStmts(normalizeIoBody(
            new Ast\Apply(
                normalizePureExpr($fn, $counter),
                new Ast\Variable($name),
            ),
            $counter,
        )),
    ]);
}

/** @return list<Ast\AstNode> */
function ioBodyAsStmts(Ast\AstNode $body): array
{
    if ($body instanceof Ast\IoSequence) {
        return $body->stmts;
    }

    return [new Ast\IoExpr($body)];
}

function normalizePureExpr(Ast\AstNode $expr, int &$counter): Ast\AstNode
{
    return match ($expr::class) {
        Ast\Apply::class => (function () use ($expr, &$counter): Ast\AstNode {
            $expr->function = normalizePureExpr($expr->function, $counter);
            $expr->argument = normalizePureExpr($expr->argument, $counter);

            return $expr;
        })(),
        Ast\Infix::class => (function () use ($expr, &$counter): Ast\AstNode {
            $expr->left = normalizePureExpr($expr->left, $counter);
            $expr->right = normalizePureExpr($expr->right, $counter);

            return $expr;
        })(),
        Ast\Lambda::class => (function () use ($expr, &$counter): Ast\AstNode {
            $expr->body = normalizePureExpr($expr->body, $counter);

            return $expr;
        })(),
        Ast\DoExpr::class => (function () use ($expr, &$counter): Ast\AstNode {
            if ($expr->desugared !== null) {
                $expr->desugared = normalizePureExpr($expr->desugared, $counter);
            }
            foreach ($expr->stmts as $stmt) {
                match ($stmt::class) {
                    Ast\DoBind::class,
                    Ast\DoExprStmt::class => $stmt->expr = normalizePureExpr($stmt->expr, $counter),
                    Ast\DoLet::class => (function () use ($stmt, &$counter): void {
                        foreach ($stmt->bindings as $binding) {
                            $binding->expr = normalizePureExpr($binding->expr, $counter);
                        }
                    })(),
                    default => null,
                };
            }

            return $expr;
        })(),
        Ast\Let::class => (function () use ($expr, &$counter): Ast\AstNode {
            foreach ($expr->bindings as $binding) {
                $binding->value = normalizePureExpr($binding->value, $counter);
            }
            $expr->body = normalizePureExpr($expr->body, $counter);

            return $expr;
        })(),
        Ast\Where::class => (function () use ($expr, &$counter): Ast\AstNode {
            $expr->expr = normalizePureExpr($expr->expr, $counter);
            foreach ($expr->bindings as $binding) {
                $binding->value = normalizePureExpr($binding->value, $counter);
            }

            return $expr;
        })(),
        Ast\GuardsExpr::class => (function () use ($expr, &$counter): Ast\AstNode {
            foreach ($expr->clauses as $clause) {
                $clause->guard = normalizePureExpr($clause->guard, $counter);
                $clause->body = normalizePureExpr($clause->body, $counter);
            }

            return $expr;
        })(),
        Ast\CaseExpr::class => (function () use ($expr, &$counter): Ast\AstNode {
            $expr->scrutinee = normalizePureExpr($expr->scrutinee, $counter);
            foreach ($expr->alts as $alt) {
                $alt->body = normalizePureExpr($alt->body, $counter);
            }

            return $expr;
        })(),
        Ast\RecordCon::class => (function () use ($expr, &$counter): Ast\AstNode {
            foreach ($expr->fields as $field) {
                $field->expr = normalizePureExpr($field->expr, $counter);
            }

            return $expr;
        })(),
        Ast\RecordUpdate::class => (function () use ($expr, &$counter): Ast\AstNode {
            $expr->object = normalizePureExpr($expr->object, $counter);
            foreach ($expr->fields as $field) {
                $field->expr = normalizePureExpr($field->expr, $counter);
            }

            return $expr;
        })(),
        Ast\FieldAccess::class => (function () use ($expr, &$counter): Ast\AstNode {
            $expr->object = normalizePureExpr($expr->object, $counter);

            return $expr;
        })(),
        Ast\TypeAsc::class => (function () use ($expr, &$counter): Ast\AstNode {
            $expr->expr = normalizePureExpr($expr->expr, $counter);

            return $expr;
        })(),
        Ast\IntrinsicCall::class => (function () use ($expr, &$counter): Ast\AstNode {
            foreach ($expr->args as $i => $arg) {
                $expr->args[$i] = normalizePureExpr($arg, $counter);
            }

            return $expr;
        })(),
        Ast\Tuple::class,
        Ast\ListLit::class => (function () use ($expr, &$counter): Ast\AstNode {
            foreach ($expr->elements as $i => $element) {
                $expr->elements[$i] = normalizePureExpr($element, $counter);
            }

            return $expr;
        })(),
        default => $expr,
    };
}

function ioPureApply(Ast\AstNode $expr): ?Ast\AstNode
{
    if (!$expr instanceof Ast\Apply) {
        return null;
    }

    $method = $expr->function;
    if (!isIoMonadMethod($method, 'pure')) {
        return null;
    }

    return $expr->argument;
}

function exprHasIoType(Ast\AstNode $expr): bool
{
    $type = $expr->inferredType;

    return $type !== null && isIoType($type);
}
