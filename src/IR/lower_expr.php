<?php declare(strict_types=1);

namespace Moggi\IR;

use Moggi\Semantics\TypeExpr;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\IntrinsicRegistry\schemeArity;
use function Moggi\Semantics\IntrinsicRegistry\typeSchemes;
use function Moggi\Semantics\IoBoundary\isIoType;
use function Moggi\Semantics\Types\bindingGroupSccs;
use function Moggi\Semantics\Types\evidenceRefOperand;
use function Moggi\Semantics\Types\peelBindingAnnotation;
use function Moggi\Semantics\Types\prune;

function lowerExpr(Ast\AstNode $expr, LowerCtx $ctx): Operand
{
    return match ($expr::class) {
        Ast\IntegerLit::class => new ConstInt($expr->value),
        Ast\DoubleLit::class => new ConstDouble($expr->value),
        Ast\StringLit::class => new ConstStr($expr->value),
        Ast\CharLit::class => new ConstChar($expr->value),
        Ast\Variable::class => (static function () use ($expr, $ctx): Operand {
            // A primop used as a value: nullary primops are values already,
            // others are eta-expanded (see lowerIntrinsicValue).
            if ($expr->intrinsicWrapper !== null) {
                return lowerIntrinsicValue($expr->intrinsicWrapper, [], $expr, $ctx);
            }

            return $ctx->env[$expr->name]
                ?? throw new \RuntimeException(\sprintf("undefined variable `%s` in IR lowering", $expr->name));
        })(),
        Ast\ConstructorRef::class => new FnRef(
            $ctx->constructorRenames[$expr->name] ?? $expr->name,
        ),
        Ast\OperatorRef::class => new FnRef($expr->name),
        Ast\Infix::class => lowerInfix($expr, $ctx),
        Ast\Apply::class => lowerApplyFromExpr($expr, $ctx),
        Ast\Tuple::class => lowerTuple($expr->elements, $ctx),
        Ast\ListLit::class => lowerList($expr->elements, $ctx),
        Ast\Lambda::class => lowerLambda($expr->params, $expr->body, $ctx),
        Ast\DoExpr::class => lowerDoExpr($expr, $ctx),
        Ast\Let::class => lowerLet($expr->bindings, $expr->body, $ctx),
        Ast\Where::class => lowerWhere($expr->expr, $expr->bindings, $ctx),
        Ast\CaseExpr::class => lowerCase($expr->scrutinee, $expr->alts, $ctx, $expr->exhaustive),
        Ast\GuardsExpr::class => lowerGuards($expr, $ctx),
        Ast\RecordCon::class => lowerRecordCon($expr, $ctx),
        Ast\RecordUpdate::class => lowerRecordUpdate($expr, $ctx),
        Ast\FieldAccess::class => lowerFieldAccess($expr, $ctx),
        Ast\QualifiedRef::class => (static function () use ($expr, $ctx): Operand {
            if ($expr->intrinsicWrapper !== null) {
                return lowerIntrinsicValue($expr->intrinsicWrapper, [], $expr, $ctx);
            }

            return new FnRef($expr->backendResolved ?? $expr->name);
        })(),
        Ast\TypeAsc::class => lowerExpr($expr->expr, $ctx),
        Ast\EvidenceRef::class => lowerEvidenceRef($expr, $ctx),
        Ast\EvidenceMethod::class => lowerEvidenceMethod($expr, $ctx),
        Ast\IntrinsicCall::class => (static function () use ($expr, $ctx): Operand {
            if (\in_array($expr->name, ['exceptionThrowIo#', 'exceptionCatch#', 'exceptionFinally#'], true)) {
                return lowerIoActionToBox(new Ast\IoAction($expr), $ctx);
            }

            return lowerIntrinsic($expr, $ctx);
        })(),
        Ast\ForeignCall::class => lowerForeignCall($expr, $ctx),
        Ast\ExprHole::class => throw new \RuntimeException('typed hole reached IR lowering'),
        default => throw new \RuntimeException('unsupported expression in IR lowering: ' . $expr::class),
    };
}

function lowerEvidenceMethod(Ast\EvidenceMethod $expr, LowerCtx $ctx): Operand
{
    $method = new DictMethod(evidenceMethodOperand($expr, $ctx), $expr->method);

    if ($expr->methodNullary) {
        return lowerDictMethodCallFromValue($method, [], $ctx, srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile));
    }

    return $method;
}

function lowerDoExpr(Ast\DoExpr $expr, LowerCtx $ctx): Operand
{
    if ($expr->desugared === null) {
        throw new \RuntimeException('do expression reached IR lowering before desugaring');
    }

    return lowerExpr($expr->desugared, $ctx);
}

function lowerIntrinsic(Ast\IntrinsicCall $expr, LowerCtx $ctx): Intrinsic
{
    $args = [];
    foreach ($expr->args as $arg) {
        $args[] = lowerExpr($arg, $ctx);
    }

    return new Intrinsic(
        $expr->name,
        $args,
        srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
    );
}

function lowerForeignCall(Ast\ForeignCall $expr, LowerCtx $ctx): ForeignCall
{
    $args = [];
    foreach ($expr->args as $arg) {
        $args[] = lowerExpr($arg, $ctx);
    }

    return new ForeignCall(
        backend: $expr->backend,
        kind: $expr->kind,
        path: $expr->path,
        dispatch: $expr->dispatch,
        args: $args,
        classPath: $expr->classPath,
        member: $expr->member,
        ioWrap: $expr->ioWrap,
        phpValueBox: $expr->phpValueBox,
        handleBox: $expr->handleBox,
        handleUnboxArgs: $expr->handleUnboxArgs,
        nativeSig: $expr->nativeSig,
    );
}

function evidenceMethodOperand(Ast\EvidenceMethod $expr, LowerCtx $ctx): Operand
{
    if ($expr->evidenceInstance !== null) {
        return lowerEvidenceRefValue(
            $expr->class,
            $expr->evidenceInstance,
            $expr->contextEvidence,
            $ctx,
        );
    }

    return new Local($expr->evidence);
}

function lowerEvidenceRef(Ast\EvidenceRef $expr, LowerCtx $ctx): Operand
{
    return lowerEvidenceRefValue($expr->class, $expr->head, $expr->context, $ctx);
}

/**
 * @param list<Ast\AstNode> $context
 */
function lowerEvidenceRefValue(string $class, Ast\TypeNode $head, array $context, LowerCtx $ctx): Operand
{
    $fn = evidenceRefOperand($class, $head);
    if ($context === []) {
        return $fn;
    }

    // Constrained instances (`Num a => Monoid (Sum a)`) are real functions of
    // their context dicts. Emit a saturated Call (not CallValue/`__apply`).
    $args = [];
    foreach ($context as $ev) {
        $args[] = lowerExpr($ev, $ctx);
    }

    return lowerCall($fn->name, $args, $ctx);
}

function lowerInfix(Ast\Infix $expr, LowerCtx $ctx): Operand
{
    // `&&`/`||` are conditional syntax: `a && b` lowers as `case a of True -> b` and its
    // mirror, so the right operand sits in a branch and backends see an `if` shape.
    if ($expr->resolvedIntrinsic === 'boolAnd#' || $expr->resolvedIntrinsic === 'boolOr#') {
        return lowerShortCircuitAndOr($expr, $ctx);
    }

    if ($expr->resolvedIntrinsic !== null) {
        return new Intrinsic($expr->resolvedIntrinsic, [
            lowerExpr($expr->left, $ctx),
            lowerExpr($expr->right, $ctx),
        ], srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile));
    }

    // A user-defined infix function is a normal top-level function: `($) f = f` has one
    // parameter and is applied to two operands, so arity-aware lowering applies it.
    if (isIdentifierInfixFunction($expr->operator) || !isPrimitiveSymbolicBinop($expr->operator)) {
        $args = [lowerExpr($expr->left, $ctx), lowerExpr($expr->right, $ctx)];

        $infixLoc = srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile);

        return lowerNamedApply($expr->operator, $args, $ctx, $infixLoc)
            ?? lowerCall($expr->operator, $args, $ctx, $infixLoc);
    }

    // Symbolic primitives without a monomorphic intrinsic (e.g. derived Eq on
    // an ADT): emit host Binop. Integer overloads are rewritten to evidence
    // Apply during inference so they never reach this path.
    $leftOp = lowerExpr($expr->left, $ctx);
    $rightOp = lowerExpr($expr->right, $ctx);
    $dest = freshTemp($ctx);
    $ctx->items[] = new Binop($expr->operator, $leftOp, $rightOp, $dest);

    return new Temp($dest);
}

/**
 * `a && b` / `a || b` as the conditional they mean.
 *
 * Both operands are `Bool` by the time an infix resolves to `boolAnd#`/
 * `boolOr#`, so `True`/`False` cover the scrutinee and the match cannot fall
 * through.
 */
function lowerShortCircuitAndOr(Ast\Infix $expr, LowerCtx $ctx): Operand
{
    $isAnd = $expr->resolvedIntrinsic === 'boolAnd#';

    return lowerCase($expr->left, [
        new Ast\Alt(new Ast\PatCon('True', []), $isAnd ? $expr->right : new Ast\ConstructorRef('True')),
        new Ast\Alt(new Ast\PatCon('False', []), $isAnd ? new Ast\ConstructorRef('False') : $expr->right),
    ], $ctx, true);
}

function isIdentifierInfixFunction(string $operator): bool
{
    return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $operator) === 1;
}

function isPrimitiveSymbolicBinop(string $operator): bool
{
    return \in_array($operator, ['+', '-', '*', '/', '<', '>', '<=', '>=', '==', '/=', '&&', '||', ':'], true);
}

function lowerApplyFromExpr(Ast\Apply $expr, LowerCtx $ctx): Operand
{
    if ($expr->intrinsicWrapper !== null) {
        $astArgs = [];
        $callee = $expr;
        while ($callee instanceof Ast\Apply) {
            array_unshift($astArgs, $callee->argument);
            $callee = $callee->function;
        }

        return lowerIntrinsicValue($expr->intrinsicWrapper, $astArgs, $expr, $ctx);
    }

    $partial = lowerDictMethodPartial($expr, $ctx);
    if ($partial !== null) {
        return $partial;
    }

    return lowerApply($expr->function, $expr->argument, $ctx);
}

/**
 * A class method that is applied to fewer arguments than it takes (`(+) 1`, the
 * unsectioned form) has no saturated `DictCall` to lower to: the dictionary slot
 * is a function of the method's full arity. The missing parameters are
 * eta-expanded into a lambda, the same way an unsaturated primop is (see
 * {@see lowerIntrinsicValue}); a saturated call still lowers to a plain
 * `DictCall` with no closure.
 */
function lowerDictMethodPartial(Ast\Apply $expr, LowerCtx $ctx): ?Operand
{
    $callee = $expr->function;
    while ($callee instanceof Ast\Apply) {
        $callee = $callee->function;
    }

    if (!($callee instanceof Ast\EvidenceMethod)) {
        return null;
    }

    $missing = remainingArity($ctx, $expr->inferredType);
    if ($missing <= 0) {
        return null;
    }

    $params = [];
    $body = $expr;
    for ($i = 0; $i < $missing; ++$i) {
        $param = 'methodArg' . $i . '_' . $ctx->state->nextLambda;
        $params[] = new Ast\PatVar($param);
        $body = new Ast\Apply($body, new Ast\Variable($param));
    }

    return lowerLambda($params, $body, $ctx);
}

/**
 * How many user-facing arrows an expression's type still has -- the arguments
 * it is still waiting for. 0 when the type is not known here. The typechecker
 * leaves either the internal type or the surface type it was written from, and
 * both carry the arrow spine.
 */
function remainingArity(LowerCtx $ctx, ?Ast\TypeNode $type): int
{
    $remaining = 0;
    while (true) {
        if ($type instanceof Ast\TypeArrow) {
            $type = $type->to;
        } elseif ($type instanceof TypeExpr\Type) {
            $type = prune($ctx->state, $type);
            if (!($type instanceof TypeExpr\TArrow)) {
                return $remaining;
            }
            $type = $type->to;
        } else {
            return $remaining;
        }
        ++$remaining;
    }
}

/**
 * Lower a primop (`#`) in value position. A primop exists in the IR only as a
 * saturated `Intrinsic`, so an unsaturated reference is eta-expanded into a
 * lambda that applies it — `intAdd#` becomes `\a b -> intAdd# a b` and a
 * partial application `intAdd# 1` becomes `\b -> intAdd# 1 b`. That is what
 * makes bare aliases (`fix = fix#`, `not = boolNot#`), primops passed as
 * arguments (`map boolNot# xs`) and partial applications first-class values.
 * Saturated applications still lower to a plain `Intrinsic` (no closure).
 *
 * @param list<Ast\AstNode> $astArgs arguments already supplied (possibly none)
 */
function lowerIntrinsicValue(string $name, array $astArgs, Ast\AstNode $node, LowerCtx $ctx): Operand
{
    $scheme = typeSchemes()[$name] ?? null;
    $arity = $scheme === null ? 0 : schemeArity($scheme);
    $missing = $arity - count($astArgs);

    if ($missing <= 0) {
        $args = [];
        foreach ($astArgs as $arg) {
            $args[] = lowerExpr($arg, $ctx);
        }

        return new Intrinsic(
            $name,
            $args,
            srcLocFromAst($node, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
        );
    }

    $params = [];
    $callArgs = $astArgs;
    for ($i = 0; $i < $missing; ++$i) {
        $param = 'primopArg' . $i . '_' . $ctx->state->nextLambda;
        $params[] = new Ast\PatVar($param);
        $callArgs[] = new Ast\Variable($param);
    }

    return lowerLambda(
        $params,
        new Ast\IntrinsicCall($name, $callArgs, $node->line, $node->col, $node->endCol),
        $ctx,
    );
}

/**
 * Lower a call to a known top-level function by name, honouring the number of
 * parameters it is declared with: exact fit is a direct call, fewer arguments
 * build a partial application, more arguments apply the returned value (a
 * function may be eta-reduced, e.g. `($) f = f`). Returns null when the callee
 * is not a known top-level function.
 *
 * @param list<Operand> $args
 */
function lowerNamedApply(string $name, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): ?Operand
{
    $arity = $ctx->functionArity[$name] ?? null;
    if ($arity === null) {
        return null;
    }

    if (count($args) === $arity) {
        return lowerCall($name, $args, $ctx, $srcLoc);
    }

    if (count($args) < $arity) {
        return new Partial($name, $arity, $args);
    }

    if ($arity === 0) {
        return applyToValue(lowerCall($name, [], $ctx, $srcLoc), $args, $ctx, $srcLoc);
    }

    $first = \array_slice($args, 0, $arity);
    $rest = \array_slice($args, $arity);

    return applyToValue(lowerCall($name, $first, $ctx, $srcLoc), $rest, $ctx, $srcLoc);
}

function lowerApply(Ast\AstNode $function, Ast\AstNode $argument, LowerCtx $ctx): Operand
{
    $callee = $function;
    $args = [lowerExpr($argument, $ctx)];

    while ($callee instanceof Ast\Apply) {
        array_unshift($args, lowerExpr($callee->argument, $ctx));
        $callee = $callee->function;
    }

    $srcLoc = srcLocFromAst($callee, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile);

    if ($callee instanceof Ast\EvidenceMethod) {
        return lowerDictMethodCall($callee, $args, $ctx, $srcLoc);
    }

    $name = applyCalleeName($callee, $ctx);
    if ($name !== null) {
        $lowered = lowerNamedApply($name, $args, $ctx, $srcLoc);
        if ($lowered !== null) {
            return $lowered;
        }
    }

    $result = lowerExpr($callee, $ctx);

    if ($result instanceof DictMethod) {
        return lowerDictMethodCallFromValue($result, $args, $ctx, $srcLoc);
    }

    return applyToValue($result, $args, $ctx, $srcLoc);
}

/** @param list<Operand> $args */
function lowerDictMethodCall(Ast\EvidenceMethod $expr, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): Operand
{
    $dest = freshTemp($ctx);
    $ctx->items[] = new DictCall(
        evidenceMethodOperand($expr, $ctx),
        $expr->method,
        $args,
        $dest,
        $ctx->stmtSrcLoc
            ?? $srcLoc
            ?? srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
    );

    return new Temp($dest);
}

/** @param list<Operand> $args */
function lowerDictMethodCallFromValue(DictMethod $value, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): Operand
{
    $dest = freshTemp($ctx);
    $ctx->items[] = new DictCall($value->evidence, $value->method, $args, $dest, $ctx->stmtSrcLoc ?? $srcLoc);

    return new Temp($dest);
}

/** @param list<Operand> $args */
function applyToValue(Operand $value, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): Operand
{
    $result = $value;
    foreach ($args as $arg) {
        if ($result instanceof Partial) {
            $nextArgs = [...$result->args, $arg];
            if (count($nextArgs) === $result->arity) {
                $result = lowerCall($result->fn, $nextArgs, $ctx, $srcLoc);
                continue;
            }

            $result = new Partial($result->fn, $result->arity, $nextArgs);
            continue;
        }

        if ($result instanceof FnRef) {
            $arity = $ctx->functionArity[$result->name] ?? null;
            if ($arity !== null) {
                if ($arity === 0) {
                    $result = applyToValue(lowerCall($result->name, [], $ctx, $srcLoc), [$arg], $ctx, $srcLoc);
                    continue;
                }

                if ($arity === 1) {
                    $result = lowerCall($result->name, [$arg], $ctx, $srcLoc);
                    continue;
                }

                $result = new Partial($result->name, $arity, [$arg]);
                continue;
            }

            $result = lowerCallValue($result, [$arg], $ctx, $srcLoc);
            continue;
        }

        if ($result instanceof Local || $result instanceof Temp) {
            $result = lowerCallValue($result, [$arg], $ctx, $srcLoc);
            continue;
        }

        if ($result instanceof DictMethod) {
            $result = lowerDictMethodCallFromValue($result, [$arg], $ctx, $srcLoc);
            continue;
        }

        throw new \RuntimeException('unsupported apply target in IR lowering');
    }

    return $result;
}

function applyCalleeName(Ast\AstNode $expr, LowerCtx $ctx): ?string
{
    return match ($expr::class) {
        Ast\ConstructorRef::class,
        Ast\OperatorRef::class => $expr->name,
        Ast\QualifiedRef::class => $expr->backendResolved ?? $expr->name,
        // The *resolved* name, not the written one: a local binding may share a
        // name with a function the module imports (`even 0 = True` in a `where`
        // while `Data.Integral.even` is in scope), and the lifted function it
        // became is what the call means. Reporting the written name here would
        // hand it to `lowerNamedApply`, which knows the imported function by
        // that name and would call it instead.
        Ast\Variable::class => ($binding = $ctx->env[$expr->name] ?? null) instanceof FnRef
            ? $binding->name
            : (\in_array($expr->name, $ctx->externalFnNames, true) ? $expr->name : null),
        default => null,
    };
}

/** @param list<Operand> $args */
function lowerCall(string $callee, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): Operand
{
    $callee = $ctx->constructorRenames[$callee] ?? $callee;

    // Newtype constructors are identity at runtime (backends emit `return $payload`).
    // Erase them at lower so IR does not allocate transparent M1-style wrappers that
    // later block case-of-known / field folding for Generic consumers.
    if (isset($ctx->newtypes[$callee]) && count($args) === 1) {
        return $args[0];
    }

    $dest = freshTemp($ctx);
    $ctx->items[] = new Call($callee, $args, $dest, $ctx->stmtSrcLoc ?? $srcLoc);

    return new Temp($dest);
}

/** @param list<Operand> $args */
function lowerCallValue(Operand $callee, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): Operand
{
    $dest = freshTemp($ctx);
    $ctx->items[] = new CallValue($callee, $args, $dest, $ctx->stmtSrcLoc ?? $srcLoc);

    return new Temp($dest);
}

/** @param array<int, Ast\AstNode> $elements */
function lowerTuple(array $elements, LowerCtx $ctx): Operand
{
    $args = [];
    foreach ($elements as $element) {
        $args[] = lowerIoValueExpr($element, $ctx);
    }

    return lowerCall('__tuple' . count($elements), $args, $ctx);
}

function lowerIoValueExpr(Ast\AstNode $element, LowerCtx $ctx): Operand
{
    if (astExprReturnsIo($element)) {
        return lowerIoActionToBox(
            $element instanceof Ast\IoAction ? $element : new Ast\IoAction($element),
            $ctx,
        );
    }

    return lowerExpr($element, $ctx);
}

/** @param array<int, Ast\AstNode> $elements */
function lowerList(array $elements, LowerCtx $ctx): ListLit
{
    $lowered = [];
    foreach ($elements as $element) {
        $lowered[] = lowerIoValueExpr($element, $ctx);
    }

    return new ListLit($lowered);
}

/**
 * @param array<int, Ast\LambdaParam|Ast\AstNode> $params
 */
function lowerLambda(array $params, Ast\AstNode $body, LowerCtx $ctx): FnRef
{
    $name = 'λ' . $ctx->state->nextLambda++;

    return lowerNamedLambda($name, $params, $body, $ctx);
}

/**
 * @param array<int, Ast\LambdaParam|Ast\AstNode> $params
 */
function lowerNamedLambda(string $name, array $params, Ast\AstNode $body, LowerCtx $ctx): FnRef
{
    $paramNames = patternParams($params);
    $fnCtx = newCtx($paramNames, $ctx->state, $ctx->data, $ctx->newtypes, $ctx);
    $fnCtx->functionArity = $ctx->functionArity;
    $fnCtx->externalFnNames = $ctx->externalFnNames;
    $fnCtx->functionName = $name;
    // The lambda body is its own statement scope.
    if ($body->line > 0) {
        $fnCtx->stmtSrcLoc = srcLocFromAst($body, $ctx->moduleName, $name, $ctx->sourceFile);
    }

    foreach (freeVarsExpr($body, array_fill_keys($paramNames, true)) as $free) {
        if (isset($ctx->env[$free])) {
            $fnCtx->env[$free] = $ctx->env[$free];
        }
    }

    $tail = lowerExpr($body, $fnCtx);
    $fnCtx->items[] = new Ret($tail);
    $ctx->state->functions[] = new FunctionDecl(
        $name,
        $paramNames,
        null,
        new Block($fnCtx->items),
        srcLoc: srcLocFromAst($body, $ctx->moduleName, $name, $ctx->sourceFile),
    );
    $ctx->functionArity[$name] = count($paramNames);

    return new FnRef($name);
}

function lowerNamedNullary(string $name, Ast\AstNode $body, LowerCtx $ctx): FnRef
{
    return lowerNamedLambda($name, [], $body, $ctx);
}

/** @param array<int, Ast\Binding> $bindings */
function lowerLet(array $bindings, Ast\AstNode $body, LowerCtx $ctx): Operand
{
    $savedEnv = $ctx->env;
    lowerBindingGroups($bindings, $ctx);
    $result = lowerExpr($body, $ctx);
    $ctx->env = $savedEnv;

    return $result;
}

/** @param array<int, Ast\Binding> $bindings */
function lowerWhere(Ast\AstNode $expr, array $bindings, LowerCtx $ctx): Operand
{
    $savedEnv = $ctx->env;
    lowerBindingGroups($bindings, $ctx);
    $result = lowerExpr($expr, $ctx);
    $ctx->env = $savedEnv;

    return $result;
}

/** @param list<Ast\Binding> $bindings */
function lowerBindingGroups(array $bindings, LowerCtx $ctx): void
{
    foreach (bindingGroupSccs($bindings) as $component) {
        $group = [];
        foreach ($component['indices'] as $index) {
            $group[] = $bindings[$index];
        }
        if ($component['recursive']) {
            lowerRecursiveBindings($group, $ctx);
            continue;
        }
        foreach ($group as $binding) {
            [$rhs] = peelBindingAnnotation($binding->value);
            bindPattern(
                lowerPattern($binding->pattern, $ctx->constructorRenames, $ctx->data),
                lowerExpr($rhs, $ctx),
                $ctx,
            );
        }
    }
}

/**
 * Recursive let group: pre-bind names as function refs (lambdas or nullary
 * value thunks), then lower bodies so self/mutual refs resolve.
 *
 * @param list<Ast\Binding> $bindings
 */
function lowerRecursiveBindings(array $bindings, LowerCtx $ctx): void
{
    /** @var array<string, array{kind: 'lambda'|'nullary', fnName: string, rhs: Ast\AstNode}> $planned */
    $planned = [];

    foreach ($bindings as $binding) {
        if (!$binding->pattern instanceof Ast\PatVar) {
            continue;
        }
        [$rhs] = peelBindingAnnotation($binding->value);
        $name = $binding->pattern->name;
        // Non-λ names so backends emit real functions (direct self-calls work;
        // capturing λ wrappers break recursive arity).
        $fnName = '__letrec' . $ctx->state->nextLambda++;
        if ($rhs instanceof Ast\Lambda) {
            $planned[$name] = ['kind' => 'lambda', 'fnName' => $fnName, 'rhs' => $rhs];
            $ctx->functionArity[$fnName] = count($rhs->params);
        } else {
            $planned[$name] = ['kind' => 'nullary', 'fnName' => $fnName, 'rhs' => $rhs];
            $ctx->functionArity[$fnName] = 0;
        }
        $ctx->env[$name] = new FnRef($fnName);
    }

    foreach ($planned as $plan) {
        if ($plan['kind'] === 'lambda') {
            /** @var Ast\Lambda $lambda */
            $lambda = $plan['rhs'];
            lowerNamedLambda($plan['fnName'], $lambda->params, $lambda->body, $ctx);
        } else {
            lowerNamedNullary($plan['fnName'], $plan['rhs'], $ctx);
        }
    }

    foreach ($bindings as $binding) {
        if ($binding->pattern instanceof Ast\PatVar) {
            continue;
        }
        [$rhs] = peelBindingAnnotation($binding->value);
        bindPattern(
            lowerPattern($binding->pattern, $ctx->constructorRenames, $ctx->data),
            lowerExpr($rhs, $ctx),
            $ctx,
        );
    }
}

/** @param array<int, Ast\Alt> $alts */
function lowerCase(Ast\AstNode $scrutinee, array $alts, LowerCtx $ctx, bool $exhaustive = false): Operand
{
    $scrutineeOp = lowerExpr($scrutinee, $ctx);
    $dest = freshTemp($ctx);
    $arms = [];
    // Matching every pattern is not enough to have a value: a guarded clause
    // whose guards all fail is no more taken than one whose pattern failed, and
    // when it is the last one that is a pattern-match failure. The exhaustiveness
    // the type checker proved says nothing about the guards, so the match stays
    // fallible as long as one of them can fail.
    $guardsAlwaysHold = true;

    foreach ($alts as $alt) {
        // A failing guard falls through to the next clause, not out of the whole
        // match, so a guarded clause contributes one arm per guard: the clause's
        // own pattern plus that guard as the arm's condition.
        if ($alt->body instanceof Ast\GuardsExpr) {
            $guardsAlwaysHold = $guardsAlwaysHold && guardsExhaustive($alt->body);
            $clauses = \array_map(
                static fn (Ast\Guarded $guarded): array => [$guarded->guard, $guarded->body],
                $alt->body->clauses,
            );
        } else {
            $clauses = [[null, $alt->body]];
        }

        foreach ($clauses as [$guard, $body]) {
            $armCtx = newArmCtx($ctx, $body);
            $pattern = lowerPattern($alt->pattern, $ctx->constructorRenames, $ctx->data);
            bindPattern($pattern, $scrutineeOp, $armCtx);
            if ($guard === null) {
                $guards = [];
            } else {
                // A guard is tested before the arm body, so whatever it reads has
                // to exist by then. A variable pattern is bound by a statement
                // of its own (`let m = _p0`) and that statement runs with the
                // guard; every other pattern's binders are bound by the pattern
                // test itself, so their statements stay in the body.
                $bindings = $armCtx->items;
                $isVarPattern = $alt->pattern instanceof Ast\PatVar;
                $armCtx->items = [];
                $guards = [lowerArmGuard($guard, $armCtx, $isVarPattern ? $bindings : [])];
                $armCtx->items = $isVarPattern ? [] : $bindings;
            }
            $value = lowerExpr($body, $armCtx);
            $arms[] = new MatchArm(
                eraseNewtypesInPattern($pattern, $armCtx->newtypes),
                new Block([...$armCtx->items, new Assign($dest, $value)]),
                $guards,
            );
        }
    }

    $ctx->items[] = new MatchStmt($scrutineeOp, $arms, $dest, $exhaustive && $guardsAlwaysHold);

    return new Temp($dest);
}

function lowerGuards(Ast\GuardsExpr $expr, LowerCtx $ctx): Operand
{
    $dest = freshTemp($ctx);
    $arms = [];

    foreach ($expr->clauses as $clause) {
        // A guard of a bare `GuardsExpr` has no pattern to wait for, so its
        // statements belong to the enclosing block, before the match that tests
        // them.
        $guard = lowerGuardExpr($clause->guard, $ctx);
        $armCtx = newArmCtx($ctx, $clause->body);
        $value = lowerExpr($clause->body, $armCtx);
        $arms[] = new MatchArm(
            new PatWild(),
            new Block([...$armCtx->items, new Assign($dest, $value)]),
            [new Guard(new Block([]), $guard)],
        );
    }

    $ctx->items[] = new MatchStmt(new ConstInt(0), $arms, $dest, guardsExhaustive($expr));

    return new Temp($dest);
}

/**
 * A case arm and a guarded clause are the same kind of scope: their pattern
 * bindings and the statements their guard/body need stay inside the arm.
 */
function newArmCtx(LowerCtx $ctx, Ast\AstNode $body): LowerCtx
{
    $armCtx = newCtx([], $ctx->state, $ctx->data, $ctx->newtypes, $ctx);
    $armCtx->env = $ctx->env;
    // An arm body is its own statement: a call in it is this frame, not the
    // enclosing `case`.
    if ($body->line > 0) {
        $armCtx->stmtSrcLoc = srcLocFromAst(
            $body,
            $ctx->moduleName,
            $ctx->functionName,
            $ctx->sourceFile,
        );
    }

    return $armCtx;
}

/**
 * Lower one arm guard into a guard that owns its preparation.
 *
 * Guard expressions are not always operand-shaped (`| ok (g x) = …`), so the
 * statements that compute one belong to the guard: they run right after the
 * pattern bound its variables and before the guard is tested. The pattern's own
 * bindings are part of that preparation, since a guard reads them.
 *
 * @param list<Stmt> $bindings
 */
function lowerArmGuard(Ast\AstNode $guard, LowerCtx $armCtx, array $bindings): Guard
{
    $cond = lowerGuardExpr($guard, $armCtx);
    $prep = new Block([...$bindings, ...$armCtx->items]);
    $armCtx->items = [];

    return new Guard($prep, $cond);
}

function guardsExhaustive(Ast\GuardsExpr $expr): bool
{
    $last = $expr->clauses[count($expr->clauses) - 1]->guard ?? null;

    return $last instanceof Ast\Variable && $last->name === 'otherwise';
}

function lowerGuardExpr(Ast\AstNode $guard, LowerCtx $ctx): Operand
{
    if ($guard instanceof Ast\Variable && $guard->name === 'otherwise') {
        $guard = new Ast\ConstructorRef('True');
    }

    return lowerExpr($guard, $ctx);
}

function lowerRecordCon(Ast\RecordCon $expr, LowerCtx $ctx): Operand
{
    $order = $ctx->data[$expr->name]
        ?? throw new \RuntimeException("unknown record constructor `{$expr->name}` in IR lowering");
    $byName = [];
    foreach ($expr->fields as $field) {
        $byName[$field->name] = $field->expr;
    }

    $args = \array_map(
        static fn (string $fieldName): Operand => lowerExpr($byName[$fieldName], $ctx),
        $order,
    );

    return lowerCall($expr->name, $args, $ctx);
}

/**
 * `r { f = e }`: build the constructor the type checker named, with `e` at the
 * field's position and a read of the receiver at every other one.
 */
function lowerRecordUpdate(Ast\RecordUpdate $expr, LowerCtx $ctx): Operand
{
    $constructor = $expr->constructor
        ?? throw new \RuntimeException('record update reached IR lowering without a constructor');
    $order = $ctx->data[$constructor]
        ?? throw new \RuntimeException("unknown record constructor `{$constructor}` in IR lowering");

    $byName = [];
    foreach ($expr->fields as $field) {
        $byName[$field->name] = $field->expr;
    }

    // The receiver is forced first, as `base` does, and read once per field the
    // update keeps — hence the temp when the operand is not one already.
    $receiver = stabilizeOperand(lowerExpr($expr->object, $ctx), $ctx);
    $args = [];
    foreach ($order as $position => $name) {
        if (isset($byName[$name])) {
            $args[] = lowerExpr($byName[$name], $ctx);
            continue;
        }

        // A newtype record is its one field, so reading that field is identity.
        $args[] = $expr->fieldOnNewtype
            ? $receiver
            : lowerCall('__field' . $position, [$receiver], $ctx);
    }

    return lowerCall($constructor, $args, $ctx);
}

/**
 * Bind a value to a temp so it can be read more than once.
 *
 * An operand is usually a `Temp` a previous statement wrote, but some shapes are
 * the expression themselves (`ExprBinop`, `Intrinsic`, `Partial`) and would be
 * recomputed by every read. A record update reads its receiver once per field it
 * keeps, so the receiver goes through here first.
 */
function stabilizeOperand(Operand $value, LowerCtx $ctx): Operand
{
    return match (true) {
        $value instanceof ConstInt,
        $value instanceof ConstStr,
        $value instanceof ConstChar,
        $value instanceof ConstDouble,
        $value instanceof Local,
        $value instanceof Temp,
        $value instanceof FnRef,
        $value instanceof Unit => $value,
        default => (static function () use ($value, $ctx): Operand {
            $dest = freshTemp($ctx);
            $ctx->items[] = new Assign($dest, $value);

            return new Temp($dest);
        })(),
    };
}

function lowerFieldAccess(Ast\FieldAccess $expr, LowerCtx $ctx): Operand
{
    $index = $expr->fieldIndex
        ?? throw new \RuntimeException("field access `.{$expr->field}` reached lowering without a type");
    $object = lowerExpr($expr->object, $ctx);

    return $expr->fieldOnNewtype ? $object : lowerCall('__field' . $index, [$object], $ctx);
}

function astExprReturnsIo(Ast\AstNode $expr): bool
{
    $type = $expr->inferredType;

    return $type !== null && isIoType($type);
}
