<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;
use function Moggi\Semantics\IntrinsicRegistry\capturesCallSite;
use function Moggi\Semantics\IntrinsicRegistry\isShortCircuitPrimop;
use function Moggi\Semantics\IntrinsicRegistry\resolveIntrinsicName;
use function Moggi\Semantics\IntrinsicRegistry\schemeArity;
use function Moggi\Semantics\IntrinsicRegistry\typeSchemes;
use function Moggi\Semantics\TypeExpr\scheme;

// Primop rewriting: saturated `#` applications become `IntrinsicCall`s, and a class method an instance declares as a primop lowers to that primop.

/**
 * Rewrite saturated applications of imported `#` primops into IntrinsicCall
 * nodes named by the IR/backend id (`intAdd# a b` → `intrinsic intAdd# a b`).
 */
function rewriteIntrinsicApplies(Ast\AstNode $expr): Ast\AstNode
{
    return match ($expr::class) {
        Ast\Variable::class, Ast\QualifiedRef::class => (static function () use ($expr): Ast\AstNode {
            $wrapper = $expr->intrinsicWrapper;
            if ($wrapper === null) {
                return $expr;
            }

            $internal = resolveIntrinsicName($wrapper) ?? $wrapper;
            $scheme = typeSchemes()[$internal] ?? null;
            if ($scheme === null || schemeArity($scheme) !== 0) {
                return $expr;
            }

            $call = new Ast\IntrinsicCall($internal, [], $expr->line, $expr->col, $expr->endCol);
            $call->intrinsicId = $internal;
            copyInferredType($call, $expr);
            $call->pendingConstraints = $expr->pendingConstraints;

            return $call;
        })(),
        Ast\Apply::class => (static function () use ($expr): Ast\AstNode {
            $expr->function = rewriteIntrinsicApplies($expr->function);
            $expr->argument = rewriteIntrinsicApplies($expr->argument);

            $parts = flattenApplyForIntrinsicWrapper($expr);
            $callee = $parts['function'];
            $wrapper = ($callee instanceof Ast\Variable || $callee instanceof Ast\QualifiedRef)
                ? $callee->intrinsicWrapper
                : null;
            $wrapper ??= $expr->intrinsicWrapper;
            if ($wrapper === null) {
                return $expr;
            }

            $internal = resolveIntrinsicName($wrapper) ?? $wrapper;
            $scheme = typeSchemes()[$internal] ?? null;
            $args = $parts['args'];
            if ($callee instanceof Ast\EvidenceMethod) {
                $args = stripLeadingEvidenceApplyArgs($args);
            }
            if ($scheme === null || \count($args) !== schemeArity($scheme)) {
                return $expr;
            }

            $call = new Ast\IntrinsicCall($internal, $args, $expr->line, $expr->col, $expr->endCol);
            $call->intrinsicId = $internal;
            copyInferredType($call, $expr);
            $call->pendingConstraints = $expr->pendingConstraints;

            return $call;
        })(),
        Ast\IntrinsicCall::class => (static function () use ($expr): Ast\AstNode {
            $internal = resolveIntrinsicName($expr->name) ?? $expr->name;
            $expr->name = $internal;
            $expr->intrinsicId = $internal;
            foreach ($expr->args as $i => $arg) {
                $expr->args[$i] = rewriteIntrinsicApplies($arg);
            }

            return $expr;
        })(),
        Ast\Lambda::class => (static function () use ($expr): Ast\AstNode {
            $expr->body = rewriteIntrinsicApplies($expr->body);

            return $expr;
        })(),
        Ast\Let::class => (static function () use ($expr): Ast\AstNode {
            foreach ($expr->bindings as $binding) {
                $binding->value = rewriteIntrinsicApplies($binding->value);
            }
            $expr->body = rewriteIntrinsicApplies($expr->body);

            return $expr;
        })(),
        Ast\Where::class => (static function () use ($expr): Ast\AstNode {
            foreach ($expr->bindings as $binding) {
                $binding->value = rewriteIntrinsicApplies($binding->value);
            }
            $expr->expr = rewriteIntrinsicApplies($expr->expr);

            return $expr;
        })(),
        Ast\CaseExpr::class => (static function () use ($expr): Ast\AstNode {
            $expr->scrutinee = rewriteIntrinsicApplies($expr->scrutinee);
            foreach ($expr->alts as $alt) {
                $alt->body = rewriteIntrinsicApplies($alt->body);
            }

            return $expr;
        })(),
        Ast\GuardsExpr::class => (static function () use ($expr): Ast\AstNode {
            foreach ($expr->clauses as $clause) {
                $clause->guard = rewriteIntrinsicApplies($clause->guard);
                $clause->body = rewriteIntrinsicApplies($clause->body);
            }

            return $expr;
        })(),
        Ast\DoExpr::class => (static function () use ($expr): Ast\AstNode {
            if ($expr->desugared !== null) {
                $expr->desugared = rewriteIntrinsicApplies($expr->desugared);
            }

            return $expr;
        })(),
        Ast\Infix::class => (static function () use ($expr): Ast\AstNode {
            $expr->left = rewriteIntrinsicApplies($expr->left);
            $expr->right = rewriteIntrinsicApplies($expr->right);

            $primop = $expr->resolvedIntrinsic;
            if ($primop === null || isShortCircuitPrimop($primop)) {
                return $expr;
            }

            $internal = resolveIntrinsicName($primop) ?? $primop;
            $scheme = typeSchemes()[$internal] ?? null;
            if ($scheme === null || schemeArity($scheme) !== 2) {
                return $expr;
            }

            $call = new Ast\IntrinsicCall(
                $internal,
                [$expr->left, $expr->right],
                $expr->line,
                $expr->col,
                $expr->endCol,
            );
            $call->intrinsicId = $internal;
            copyInferredType($call, $expr);

            return $call;
        })(),
        Ast\Tuple::class, Ast\ListLit::class => (static function () use ($expr): Ast\AstNode {
            foreach ($expr->elements as $i => $el) {
                $expr->elements[$i] = rewriteIntrinsicApplies($el);
            }

            return $expr;
        })(),
        Ast\TypeAsc::class => (static function () use ($expr): Ast\AstNode {
            $expr->expr = rewriteIntrinsicApplies($expr->expr);

            return $expr;
        })(),
        Ast\RecordCon::class => (static function () use ($expr): Ast\AstNode {
            foreach ($expr->fields as $field) {
                $field->expr = rewriteIntrinsicApplies($field->expr);
            }

            return $expr;
        })(),
        Ast\RecordUpdate::class => (static function () use ($expr): Ast\AstNode {
            $expr->object = rewriteIntrinsicApplies($expr->object);
            foreach ($expr->fields as $field) {
                $field->expr = rewriteIntrinsicApplies($field->expr);
            }

            return $expr;
        })(),
        Ast\FieldAccess::class => (static function () use ($expr): Ast\AstNode {
            $expr->object = rewriteIntrinsicApplies($expr->object);

            return $expr;
        })(),
        default => $expr,
    };
}

/**
 * `f x y = primop# x y` (and the nullary alias `f = primop#`) is a trivial
 * wrapper around a primop: every use site can be rewritten to the intrinsic.
 *
 * The callee is recognised either by its resolved intrinsic wrapper (set while
 * inferring the body) or directly by name against the intrinsic registry — the
 * latter is what lets a *signatured* definition like `fix :: (a -> a) -> a;
 * fix = fix#` be recognised while its scheme is registered, i.e. before its
 * body has been inferred. User functions can never carry a `#` name, so the
 * name test can only match real intrinsics.
 */
function trivialIntrinsicWrapper(Ast\FunctionDecl $fn): ?string
{
    $body = $fn->body;
    $parts = $body instanceof Ast\IntrinsicCall
        ? ['function' => $body, 'args' => $body->args]
        : flattenApplyForIntrinsicWrapper($body);
    $callee = $parts['function'];

    $internalId = null;
    if ($callee instanceof Ast\IntrinsicCall) {
        $internalId = resolveIntrinsicName($callee->name)
            ?? $callee->name;
    } elseif ($callee instanceof Ast\Variable || $callee instanceof Ast\QualifiedRef) {
        $internalId = $callee->intrinsicWrapper
            ?? resolveIntrinsicName($callee->name);
    }

    if ($internalId !== null && capturesCallSite($internalId)) {
        return null;
    }

    if ($internalId === null) {
        return null;
    }

    $params = [];
    foreach ($fn->params as $param) {
        $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;
        if (! $pattern instanceof Ast\PatVar) {
            return null;
        }
        $params[] = $pattern->name;
    }

    if (\count($params) !== \count($parts['args'])) {
        return null;
    }

    foreach ($parts['args'] as $i => $arg) {
        if (! $arg instanceof Ast\Variable || $arg->name !== $params[$i]) {
            return null;
        }
    }

    return $internalId;
}

/** @return array{function: Ast\AstNode, args: list<Ast\AstNode>} */
function flattenApplyForIntrinsicWrapper(Ast\AstNode $expr): array
{
    $args = [];
    while ($expr instanceof Ast\Apply) {
        $args[] = $expr->argument;
        $expr = $expr->function;
    }
    if ($args !== []) {
        $args = \array_reverse($args);
    }

    return ['function' => $expr, 'args' => $args];
}

/**
 * Drop the leading evidence arguments of a flattened application spine.
 *
 * Elaboration prepends dictionaries for a method's own constraints ahead of
 * the value arguments, so a method call can carry more leading arguments than
 * the primop it stands for takes: `bounds arr` at `Array` elaborates with the
 * `Ix` dictionaries (and their superclass context) applied ahead of `arr`.
 * Counting those as value arguments misreads a partial evidence application
 * as a saturated primop call, so the intrinsic rewrite must only see the
 * trailing values. Evidence is pure dictionary plumbing, so dropping it from
 * the rewritten call is semantics-preserving.
 *
 * @param list<Ast\AstNode> $args
 * @return list<Ast\AstNode>
 */
function stripLeadingEvidenceApplyArgs(array $args): array
{
    $values = $args;
    while ($values !== [] && isEvidenceApplyArg($values[0])) {
        \array_shift($values);
    }

    return \array_values($values);
}

/** Whether a flattened call argument is compiler-inserted evidence. */
function isEvidenceApplyArg(Ast\AstNode $arg): bool
{
    return $arg instanceof Ast\EvidenceRef
        || $arg instanceof Ast\EvidenceMethod
        || ($arg instanceof Ast\Variable && \str_starts_with($arg->name, '__ev_'));
}

/**
 * Resolve an infix whose operands only settled after the use site was typed.
 *
 * `replicateM n ma = case n <= 0 of ... replicateM (n - 1) ma` types `n - 1`
 * while `n` is still the fresh variable the parameter was bound to, before the
 * signature's `Int` reaches the binder. Neither operand then names an instance,
 * so the use is typed through the class's dictionary and keeps the constraint it
 * carries. By the time the body is settled the operands do name one, and the
 * constraints are exactly the dispatch the primop replaces: the node takes the
 * primop and drops them, the deferred form of what {@see instanceOperatorPrimop}
 * does at the use site when the operands are already known.
 *
 * Nothing is guessed -- an operand whose settled type names no instance leaves
 * the node and its constraint alone, and so does a constraint that is not a
 * dictionary the primop makes unnecessary.
 */
function resolveDeferredInfixPrimops(TypeCheckState $state, Ast\AstNode $expr): void
{
    walkAstValues($expr, static function (Ast\AstNode $node) use ($state): void {
        if ($node->pendingConstraints === [] && $node->pendingDicts === []) {
            return;
        }

        if ($node instanceof Ast\Infix) {
            $operator = $node->operator;
            $operands = [$node->left, $node->right];
            $target = $node;
        } elseif ($node instanceof Ast\Apply) {
            $parts = flattenApplyForIntrinsicWrapper($node);
            if (! $parts['function'] instanceof Ast\OperatorRef || \count($parts['args']) !== 2) {
                return;
            }

            $operator = $parts['function']->name;
            $operands = $parts['args'];
            $target = $node;
        } else {
            return;
        }

        $types = [];
        foreach ($operands as $operand) {
            if ($operand->inferredInternalType === null) {
                return;
            }

            $types[] = canonicalPrimitiveType(
                prune($state, $operand->inferredInternalType),
            );
        }

        $primop = instanceOperatorPrimop($state, $operator, ...$types);
        if ($primop === null) {
            return;
        }

        if ($target instanceof Ast\Infix) {
            $target->resolvedIntrinsic = $primop;
            $target->pendingConstraints = [];
            $target->pendingDicts = [];

            return;
        }

        $target->intrinsicWrapper = $primop;
        $cursor = $target;
        while ($cursor instanceof Ast\Apply) {
            $cursor->pendingConstraints = [];
            $cursor->pendingDicts = [];
            $cursor = $cursor->function;
        }
        if ($cursor instanceof Ast\AstNode) {
            $cursor->pendingConstraints = [];
            $cursor->pendingDicts = [];
        }
    });
}

/**
 * Lower a use of a class method whose instance declares the method as a primop
 * to that primop.
 *
 * `(+)` on `Int` is `(+) a b = intAdd# a b`, so `x + 1` is the primop and needs
 * neither a dictionary nor a call. Only a *known* instance is rewritten, and
 * only when the application supplies exactly the primop's own arity: a partial
 * application, or one that still carries context dictionaries, keeps the method
 * call (those need a dictionary at runtime).
 *
 * The mark is what {@see rewriteIntrinsicApplies} turns into an `IntrinsicCall`,
 * exactly as it does for an inlined wrapper.
 */
function resolveInstanceIntrinsicMethods(TypeCheckState $state, Ast\AstNode $body): void
{
    walkAstValues($body, static function (Ast\AstNode $node) use ($state): void {
        if (! $node instanceof Ast\Apply || $node->intrinsicWrapper !== null) {
            return;
        }

        $parts = flattenApplyForIntrinsicWrapper($node);
        $callee = $parts['function'];
        if (! $callee instanceof Ast\EvidenceMethod) {
            return;
        }

        $intrinsic = instanceMethodIntrinsic($state, $callee);
        if ($intrinsic === null) {
            return;
        }

        $scheme = typeSchemes()[$intrinsic] ?? null;
        if ($scheme === null) {
            return;
        }

        $values = stripLeadingEvidenceApplyArgs($parts['args']);
        if (\count($values) !== schemeArity($scheme)) {
            return;
        }

        $node->intrinsicWrapper = $intrinsic;
    });
}

/**
 * The primop a resolved class-method reference stands for, when the instance it
 * came from declares the method as that primop.
 *
 * The reference records the instance's own head, so the project's instance
 * index names the declaration. `*` is the index's "no head constructor" key:
 * a use still open in the class variable has no instance to read and keeps its
 * dictionary.
 */
function instanceMethodIntrinsic(TypeCheckState $state, Ast\EvidenceMethod $method): ?string
{
    $head = $method->evidenceInstance;
    if (! $head instanceof Ast\TypeNode) {
        return null;
    }

    $key = instanceHeadIndexKeyFromAst($head);
    if ($key === '*') {
        return null;
    }

    foreach ($state->projectInstancesByClassHead[$method->class][$key] ?? [] as $instance) {
        if (($instance['constraints'] ?? []) !== []) {
            continue;
        }

        $intrinsic = ($instance['intrinsicMethods'] ?? [])[$method->method] ?? null;
        if (\is_string($intrinsic) && $intrinsic !== '') {
            return $intrinsic;
        }
    }

    return null;
}

/**
 * The primop an operator *is* at these operand types, read off the library's
 * instance for them.
 *
 * `instance Num Int where (+) a b = intAdd# a b` says `+` at `Int` *is*
 * `intAdd#`, and that declaration is the only place the mapping lives: a use
 * site that reaches it needs neither the operator's name in scope nor a
 * dictionary. The operand that names an instance decides -- `x + y` is `intAdd#`
 * when either side is an `Int` -- so a use whose operands leave the type open
 * (`Num a` at a type variable) names no instance and stays the call the
 * dictionary path builds. `:` needs none of this: it is the language's list
 * cons, and {@see inferInfix} resolves it on its own.
 */
function instanceOperatorPrimop(TypeCheckState $state, string $op, Type ...$operands): ?string
{
    $class = classDeclaringMethod($state, $op);
    if ($class === null) {
        return null;
    }

    foreach ($operands as $operand) {
        $key = instanceHeadIndexKeyFromType(normalizeType($state, $operand, reduceFamilies: false));
        if ($key === '*') {
            continue;
        }

        foreach ($state->projectInstancesByClassHead[$class][$key] ?? [] as $instance) {
            if (($instance['constraints'] ?? []) !== []) {
                continue;
            }

            $primop = ($instance['intrinsicMethods'] ?? [])[$op] ?? null;
            if (\is_string($primop) && isset(typeSchemes()[$primop])) {
                return $primop;
            }
        }
    }

    return null;
}

/**
 * The one class that declares `$op` as a method, or `null` when none or several
 * do.
 *
 * Two classes claiming one name is an ambiguity to report, not a method to read
 * a scheme off.
 */
function classDeclaringMethod(TypeCheckState $state, string $op): ?string
{
    if (isset($state->classMethodOwners[$op])) {
        return $state->classMethodOwners[$op];
    }

    $found = null;
    foreach ($state->classes as $className => $classInfo) {
        if (! isset($classInfo['methods'][$op])) {
            continue;
        }
        if ($found !== null) {
            return null;
        }
        $found = $className;
    }

    return $found;
}

/**
 * Resolve an infix expression as the primop the library declares for it.
 *
 * The primop's own scheme is what types the expression: its parameters are
 * unified with the operands, which is what makes `x + 1` put the literal at the
 * machine type the instance names rather than leaving it to default, and its
 * result is the expression's. No result variable of its own is allocated, so the
 * body's fresh-variable numbering does not depend on how many operators it
 * spells.
 *
 * The node stays the infix it was written as, marked with the primop;
 * {@see rewriteIntrinsicApplies} lowers the mark into an `IntrinsicCall` -- and
 * leaves `&&`/`||` alone, whose short-circuit lowering reads the infix itself.
 */
function applyOperatorPrimop(
    TypeCheckState $state,
    Ast\AstNode $expr,
    string $primop,
    Type $leftType,
    Type $rightType,
): Type {
    if (! $expr instanceof Ast\Infix) {
        throw new \InvalidArgumentException('applyOperatorPrimop expects an infix expression');
    }

    $scheme = typeSchemes()[$primop] ?? throw new \LogicException("primop `{$primop}` has no scheme");
    $instantiated = instantiateScheme($state, $scheme);
    $type = $instantiated['type'];
    if (! $type instanceof TArrow || ! $type->to instanceof TArrow) {
        throw new \LogicException("primop `{$primop}` is not a binary operator");
    }

    unify($state, $type->from, $leftType, $expr);
    unify($state, $type->to->from, $rightType, $expr);
    $expr->resolvedIntrinsic = $primop;

    return $type->to->to;
}

/**
 * Whether this operator is one the instance under check provides and either
 * operand sits at that instance's head type. Such a use has to dispatch through
 * the instance's own dictionary (`(/=) x y = case x == y of …` at `Pair a`).
 *
 * A host operator cannot stand in: it compares the representation and ignores
 * the instance. Operators of other classes (`==` inside an unrelated `MyEq`
 * instance, whose head has no `Eq`) are left alone.
 */
function instanceHeadOperand(TypeCheckState $state, string $op, Type $left, Type $right): bool
{
    $head = $state->instanceHeadInScope;
    if ($head === null || ! isset($state->instanceOwnMethods[$op])) {
        return false;
    }

    return typesMatchForAmbient($state, $head, $left)
        || typesMatchForAmbient($state, $head, $right);
}

/**
 * Whether either operand is still a type variable.
 *
 * Such an operand has no host representation yet, so settling the operator on its instance's primop
 * now would pick a method before the type is known; the constraint is carried instead.
 */
function operandTypeIsUnresolved(TypeCheckState $state, Type $left, Type $right): bool
{
    foreach ([$left, $right] as $type) {
        $type = prune($state, $type);
        if ($type instanceof TVar) {
            return true;
        }
    }

    return false;
}

function inferIntrinsic(TypeCheckState $state, Ast\IntrinsicCall $expr, array $env): Type
{
    $internal = resolveIntrinsicName($expr->name);
    if ($internal === null) {
        throw typeFail($state, \sprintf('unknown intrinsic `%s`', $expr->name), $expr);
    }

    $schemes = typeSchemes();
    $scheme = $schemes[$internal];
    $type = instantiate($state, $scheme);
    foreach ($expr->args as &$arg) {
        $argType = inferExpr($state, $arg, $env);
        $result = freshType($state);
        unify($state, $type, new TArrow($argType, $result), $expr);
        $type = $result;
    }
    unset($arg);

    $expr->name = $internal;
    $expr->intrinsicId = $internal;

    return prune($state, $type);
}

function inferForeignCall(TypeCheckState $state, Ast\ForeignCall $expr, array $env): Type
{
    $scheme = $env[$expr->name] ?? throw typeFail($state, "undefined foreign import `{$expr->name}`", $expr);
    $type = instantiate($state, $scheme);

    if ($expr->args === []) {
        return prune($state, $type);
    }

    foreach ($expr->args as &$arg) {
        $argType = inferExpr($state, $arg, $env);
        $result = freshType($state);
        unify($state, $type, new TArrow($argType, $result), $expr);
        $type = $result;
    }
    unset($arg);

    return prune($state, $type);
}
