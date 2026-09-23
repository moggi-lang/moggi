<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Syntax\Ast;

use function Moggi\Patterns\Walk\patternBoundNames;
use function Moggi\Patterns\Walk\patternVariableNames;

/**
 * Strongly connected components of a let/where binding group.
 *
 * Components are in dependency order (producers before consumers). Each entry
 * carries whether the component is recursive (self-edge or mutual cycle).
 *
 * @param list<Ast\Binding> $bindings
 * @return list<array{indices: list<int>, recursive: bool}>
 */
function bindingGroupSccs(array $bindings): array
{
    $n = count($bindings);
    if ($n === 0) {
        return [];
    }

    /** @var array<string, list<int>> $binderIndex */
    $binderIndex = [];
    for ($i = 0; $i < $n; ++$i) {
        foreach (patternVariableNames($bindings[$i]->pattern) as $name) {
            $binderIndex[$name][] = $i;
        }
    }

    /** @var array<int, array<int, int>> $deps adjacency: i → binders that i's RHS uses */
    $deps = [];
    for ($i = 0; $i < $n; ++$i) {
        $deps[$i] = [];
        // Own pattern names stay free on the RHS so self-refs become self-edges.
        foreach (letExprFreeVars($bindings[$i]->value, []) as $free) {
            foreach ($binderIndex[$free] ?? [] as $j) {
                $deps[$i][$j] = $j;
            }
        }
    }

    $components = [];
    foreach (tarjanScc($deps) as $indices) {
        $recursive = count($indices) > 1
            || ($indices !== [] && isset($deps[$indices[0]][$indices[0]]));
        $components[] = [
            'indices' => $indices,
            'recursive' => $recursive,
        ];
    }

    return $components;
}

/**
 * @param array<int, array<int, int>> $deps
 * @return list<list<int>>
 */
function tarjanScc(array $deps): array
{
    $n = count($deps);
    $index = 0;
    /** @var array<int, int> $indices */
    $indices = [];
    /** @var array<int, int> $lowlink */
    $lowlink = [];
    /** @var array<int, true> $onStack */
    $onStack = [];
    /** @var list<int> $stack */
    $stack = [];
    /** @var list<list<int>> $sccs */
    $sccs = [];

    $strongconnect = function (int $v) use (
        &$strongconnect,
        &$index,
        &$indices,
        &$lowlink,
        &$onStack,
        &$stack,
        &$sccs,
        $deps,
    ): void {
        $indices[$v] = $index;
        $lowlink[$v] = $index;
        ++$index;
        $stack[] = $v;
        $onStack[$v] = true;

        foreach ($deps[$v] as $w) {
            if (!isset($indices[$w])) {
                $strongconnect($w);
                $lowlink[$v] = min($lowlink[$v], $lowlink[$w]);
            } elseif (isset($onStack[$w])) {
                $lowlink[$v] = min($lowlink[$v], $indices[$w]);
            }
        }

        if ($lowlink[$v] === $indices[$v]) {
            $component = [];
            do {
                $w = array_pop($stack);
                unset($onStack[$w]);
                $component[] = $w;
            } while ($w !== $v);
            // Stable binder order within the component.
            $sccs[] = array_reverse($component);
        }
    };

    for ($v = 0; $v < $n; ++$v) {
        if (!isset($indices[$v])) {
            $strongconnect($v);
        }
    }

    // Tarjan emits sinks first when edges mean "depends on" → producers first.
    return $sccs;
}

/**
 * Free variable names in an expression, relative to `$bound`.
 *
 * @param array<string, true> $bound
 * @return list<string>
 */
function letExprFreeVars(Ast\AstNode $expr, array $bound = []): array
{
    return array_keys(letExprFreeVarsSet($expr, $bound));
}

/**
 * @param array<string, true> $bound
 * @return array<string, true>
 */
function letExprFreeVarsSet(Ast\AstNode $expr, array $bound): array
{
    return match ($expr::class) {
        Ast\IntegerLit::class,
        Ast\DoubleLit::class,
        Ast\StringLit::class,
        Ast\CharLit::class,
        Ast\ConstructorRef::class,
        Ast\OperatorRef::class,
        Ast\EvidenceRef::class,
        Ast\ExprHole::class => [],
        Ast\Variable::class,
        Ast\QualifiedRef::class => isset($bound[$expr->name]) ? [] : [$expr->name => true],
        Ast\TypeAsc::class => letExprFreeVarsSet($expr->expr, $bound),
        Ast\Apply::class => letExprFreeVarsSet($expr->function, $bound)
            + letExprFreeVarsSet($expr->argument, $bound),
        Ast\Infix::class => letExprFreeVarsSet($expr->left, $bound)
            + letExprFreeVarsSet($expr->right, $bound),
        Ast\Lambda::class => letExprFreeVarsSet(
            $expr->body,
            $bound + patternBoundNamesInLambdaParams($expr->params),
        ),
        Ast\Let::class => letExprFreeVarsInBindingGroup($expr->bindings, $expr->body, $bound),
        Ast\Where::class => letExprFreeVarsInBindingGroup($expr->bindings, $expr->expr, $bound),
        Ast\CaseExpr::class => letExprFreeVarsInCase($expr, $bound),
        Ast\GuardsExpr::class => (static function () use ($expr, $bound): array {
            $free = [];
            foreach ($expr->clauses as $clause) {
                $free += letExprFreeVarsSet($clause->guard, $bound);
                $free += letExprFreeVarsSet($clause->body, $bound);
            }

            return $free;
        })(),
        Ast\Tuple::class,
        Ast\ListLit::class => letExprFreeVarsInList($expr->elements, $bound),
        Ast\RecordCon::class => letExprFreeVarsInList(
            array_map(static fn (Ast\RecordField $f): Ast\AstNode => $f->expr, $expr->fields),
            $bound,
        ),
        Ast\RecordUpdate::class => letExprFreeVarsInList(
            [$expr->object, ...array_map(static fn (Ast\RecordField $f): Ast\AstNode => $f->expr, $expr->fields)],
            $bound,
        ),
        Ast\FieldAccess::class => letExprFreeVarsSet($expr->object, $bound),
        Ast\IntrinsicCall::class,
        Ast\ForeignCall::class => letExprFreeVarsInList($expr->args, $bound),
        Ast\DoExpr::class => $expr->desugared !== null
            ? letExprFreeVarsSet($expr->desugared, $bound)
            : [],
        Ast\EvidenceMethod::class => isset($bound[$expr->evidence]) ? [] : [$expr->evidence => true],
        default => [],
    };
}

/**
 * @param list<Ast\LambdaParam|Ast\AstNode> $params
 * @return array<string, true>
 */
function patternBoundNamesInLambdaParams(array $params): array
{
    $bound = [];
    foreach ($params as $param) {
        $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;
        foreach (patternBoundNames($pattern) as $name => $_) {
            $bound[$name] = true;
        }
    }

    return $bound;
}

/**
 * @param list<Ast\Binding> $bindings
 * @param array<string, true> $bound
 * @return array<string, true>
 */
function letExprFreeVarsInBindingGroup(array $bindings, Ast\AstNode $body, array $bound): array
{
    $local = $bound;
    foreach ($bindings as $binding) {
        foreach (patternBoundNames($binding->pattern) as $name => $_) {
            $local[$name] = true;
        }
    }

    $free = [];
    foreach ($bindings as $binding) {
        $free += letExprFreeVarsSet($binding->value, $local);
    }

    return $free + letExprFreeVarsSet($body, $local);
}

/**
 * @param array<string, true> $bound
 * @return array<string, true>
 */
function letExprFreeVarsInCase(Ast\CaseExpr $expr, array $bound): array
{
    $free = letExprFreeVarsSet($expr->scrutinee, $bound);
    foreach ($expr->alts as $alt) {
        $free += letExprFreeVarsSet($alt->body, $bound + patternBoundNames($alt->pattern));
    }

    return $free;
}

/**
 * @param list<Ast\AstNode> $exprs
 * @param array<string, true> $bound
 * @return array<string, true>
 */
function letExprFreeVarsInList(array $exprs, array $bound): array
{
    $free = [];
    foreach ($exprs as $expr) {
        $free += letExprFreeVarsSet($expr, $bound);
    }

    return $free;
}

/**
 * Peel a local `TypeAsc` wrapper used for signatures attached to bindings.
 *
 * @return array{0: Ast\AstNode, 1: ?Ast\TypeNode}
 */
function peelBindingAnnotation(Ast\AstNode $value): array
{
    if ($value instanceof Ast\TypeAsc) {
        return [$value->expr, $value->type];
    }

    return [$value, null];
}
