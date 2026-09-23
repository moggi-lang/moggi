<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;

/**
 * Raw let/where items before merging signatures and function clauses.
 *
 * @phpstan-type FunClause array{kind: 'fun', name: string, params: list<Ast\AstNode>, body: Ast\AstNode, line: int, col: int, endCol: int}
 * @phpstan-type PatItem array{kind: 'pat', pattern: Ast\AstNode, value: Ast\AstNode, line: int, col: int, endCol: int}
 * @phpstan-type SigItem array{kind: 'sig', name: string, type: Ast\TypeNode, line: int, col: int, endCol: int}
 * @phpstan-type LetItem FunClause|PatItem|SigItem
 */

/**
 * Dispatch a clause list as a case over its parameter slots, one arm per
 * clause. A guarded clause body stays a `GuardsExpr`: a guard that fails moves
 * on to the next clause, which is what the arm chain does with it.
 *
 * @param non-empty-list<array{params: array<int, Ast\AstNode>, body: Ast\AstNode}> $clauses
 * @param list<string> $slots parameter slot names of the enclosing function
 */
function desugarClauseDispatch(array $clauses, array $slots): Ast\CaseExpr
{
    $clauses = \array_values($clauses);
    $paramCount = count($clauses[0]['params']);
    $alts = [];

    foreach ($clauses as $clause) {
        $pattern = $paramCount === 1
            ? $clause['params'][0]
            : new Ast\PatTuple($clause['params']);
        $alts[] = new Ast\Alt($pattern, $clause['body']);
    }

    $scrutinee = $paramCount === 1
        ? new Ast\Variable($slots[0])
        : new Ast\Tuple(\array_map(static fn (string $slot): Ast\Variable => new Ast\Variable($slot), $slots));

    return new Ast\CaseExpr($scrutinee, $alts);
}

/**
 * Merge signatures + function equations into plain `Binding` nodes
 * (`name = expr`, with optional `TypeAsc` from a local signature).
 *
 * @param list<LetItem> $items
 * @param array{source: string, filename: string} $context
 * @return list<Ast\Binding>
 */
function desugarLetItems(array $items, array $context): array
{
    /** @var array<string, Ast\TypeNode> $pendingSigs */
    $pendingSigs = [];
    /** @var array<string, array{line: int, col: int, endCol: int}> $sigSpans */
    $sigSpans = [];
    /** @var list<Ast\Binding> $bindings */
    $bindings = [];
    /** @var ?array{name: string, type: ?Ast\TypeNode, clauses: list<array{params: list<Ast\AstNode>, body: Ast\AstNode}>, line: int, col: int, endCol: int} $pendingFun */
    $pendingFun = null;

    $flushFun = static function () use (&$pendingFun, &$pendingSigs, &$bindings, $context): void {
        if ($pendingFun === null) {
            return;
        }
        $name = $pendingFun['name'];
        if ($pendingFun['type'] === null && isset($pendingSigs[$name])) {
            $pendingFun['type'] = $pendingSigs[$name];
            unset($pendingSigs[$name]);
        }
        $bindings[] = finalizeLocalFunctionBinding($pendingFun, $context);
        $pendingFun = null;
    };

    foreach ($items as $item) {
        if ($item['kind'] === 'sig') {
            $flushFun();
            if (isset($pendingSigs[$item['name']])) {
                throw new ParseError(
                    "duplicate type signature for `{$item['name']}`",
                    $context['filename'],
                    $context['source'],
                    $item['line'],
                    $item['col'],
                    $item['endCol'],
                );
            }
            $pendingSigs[$item['name']] = $item['type'];
            // Kept so an unmatched signature can still be reported at the line it was written on.
            $sigSpans[$item['name']] = ['line' => $item['line'], 'col' => $item['col'], 'endCol' => $item['endCol']];
            continue;
        }

        if ($item['kind'] === 'fun') {
            if ($pendingFun !== null && $pendingFun['name'] === $item['name']) {
                $pendingArity = count($pendingFun['clauses'][0]['params']);
                $incomingArity = count($item['params']);
                if ($pendingArity === 0 && $incomingArity === 0) {
                    throw new ParseError(
                        "duplicate binding `{$item['name']}` in the same group",
                        $context['filename'],
                        $context['source'],
                        $item['line'],
                        $item['col'],
                        $item['endCol'],
                    );
                }
                $pendingFun['clauses'][] = [
                    'params' => $item['params'],
                    'body' => $item['body'],
                ];
                continue;
            }
            $flushFun();
            $type = $pendingSigs[$item['name']] ?? null;
            if ($type !== null) {
                unset($pendingSigs[$item['name']]);
            }
            $pendingFun = [
                'name' => $item['name'],
                'type' => $type,
                'clauses' => [['params' => $item['params'], 'body' => $item['body']]],
                'line' => $item['line'],
                'col' => $item['col'],
                'endCol' => $item['endCol'],
            ];
            continue;
        }

        // pattern binding
        $flushFun();
        $pattern = $item['pattern'];
        $value = $item['value'];
        if ($pattern instanceof Ast\PatVar && isset($pendingSigs[$pattern->name])) {
            $value = new Ast\TypeAsc($value, $pendingSigs[$pattern->name], $item['line'], $item['col'], $item['endCol']);
            unset($pendingSigs[$pattern->name]);
        }
        $bindings[] = new Ast\Binding($pattern, $value, $item['line'], $item['col'], $item['endCol']);
    }

    $flushFun();

    if ($pendingSigs !== []) {
        $name = array_key_first($pendingSigs);
        $type = $pendingSigs[$name];
        $span = $sigSpans[$name] ?? ['line' => 1, 'col' => 1, 'endCol' => 1];
        throw new ParseError(
            "type signature for `{$name}` lacks an accompanying binding",
            $context['filename'],
            $context['source'],
            $span['line'],
            $span['col'],
            $span['endCol'],
        );
    }

    return $bindings;
}

/**
 * @param array{name: string, type: ?Ast\TypeNode, clauses: list<array{params: list<Ast\AstNode>, body: Ast\AstNode}>, line: int, col: int, endCol: int} $fn
 * @param array{source: string, filename: string} $context
 */
function finalizeLocalFunctionBinding(array $fn, array $context): Ast\Binding
{
    $value = desugarLocalFunctionClauses($fn, $context);
    if ($fn['type'] !== null) {
        $value = new Ast\TypeAsc(
            $value,
            $fn['type'],
            $fn['line'],
            $fn['col'],
            $fn['endCol'],
        );
    }

    $binding = new Ast\Binding(
        new Ast\PatVar($fn['name'], $fn['line'], $fn['col'], $fn['endCol']),
        $value,
        $fn['line'],
        $fn['col'],
        $fn['endCol'],
    );
    // `f p = e` is a function binding, `x = e` a pattern binding; the distinction is
    // kept here because the monomorphism restriction depends on it.
    $binding->functionBinding = ($fn['clauses'][0]['params'] ?? []) !== [];

    return $binding;
}

/**
 * @param array{name: string, type: ?Ast\TypeNode, clauses: list<array{params: list<Ast\AstNode>, body: Ast\AstNode}>, line: int, col: int, endCol: int} $fn
 * @param array{source: string, filename: string} $context
 */
function desugarLocalFunctionClauses(array $fn, array $context): Ast\AstNode
{
    $clauses = $fn['clauses'];
    if (count($clauses) === 1) {
        $body = $clauses[0]['body'];
        if ($clauses[0]['params'] === []) {
            return $body;
        }
        if (localClauseParamsAreVars($clauses[0]['params'])) {
            $params = [];
            foreach ($clauses[0]['params'] as $param) {
                $params[] = new Ast\LambdaParam($param);
            }

            return new Ast\Lambda($params, $body, $fn['line'], $fn['col'], $fn['endCol']);
        }
    }

    $paramCount = count($clauses[0]['params']);
    foreach ($clauses as $clause) {
        if (count($clause['params']) !== $paramCount) {
            $at = $clause['params'][0] ?? null;
            if ($at instanceof Ast\AstNode) {
                throw parseError(
                    new ParserState([], 0, [], $context['source'], $context['filename']),
                    "function `{$fn['name']}` has inconsistent clause arities",
                    new Token(TokenKind::Eof, '', $at->line, $at->col),
                );
            }

            throw new ParseError(
                "function `{$fn['name']}` has inconsistent clause arities",
                $context['filename'],
                $context['source'],
                $fn['line'],
                $fn['col'],
                $fn['endCol'],
            );
        }
    }

    if ($paramCount === 0) {
        throw new ParseError(
            "duplicate binding `{$fn['name']}` in the same group",
            $context['filename'],
            $context['source'],
            $fn['line'],
            $fn['col'],
            $fn['endCol'],
        );
    }

    $slots = functionParamSlotNames($clauses[0]['params']);
    $params = \array_map(
        static fn (string $slot): Ast\LambdaParam => new Ast\LambdaParam(
            new Ast\PatVar($slot, $fn['line'], $fn['col'], $fn['endCol']),
        ),
        $slots,
    );

    return new Ast\Lambda(
        $params,
        desugarClauseDispatch($clauses, $slots),
        $fn['line'],
        $fn['col'],
        $fn['endCol'],
    );
}

/** @param list<Ast\AstNode> $params */
function localClauseParamsAreVars(array $params): bool
{
    foreach ($params as $param) {
        if (!$param instanceof Ast\PatVar && !$param instanceof Ast\PatWild) {
            return false;
        }
    }

    return true;
}
