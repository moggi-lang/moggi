<?php declare(strict_types=1);

namespace Moggi\Repl;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Parser\ReplFragment;

use function Moggi\Patterns\Walk\patternVariableNames;
use function Moggi\Syntax\Parser\parseReplFragment;

/**
 * Rename interactive OccName `$from` to `$to` in a single declaration source:
 * - the top-level binder, if it defines `$from`
 * - free uses of `$from` (not shadowed by local binders)
 *
 * Used to keep prior REPL bindings alive under a unique name when a later
 * interactive declaration shadows the same OccName.
 */
function rewriteDeclSourceForShadow(string $source, string $from, string $to): string
{
    $frag = parseReplFragment($source, '<interactive>');
    if ($frag->kind !== ReplFragment::KIND_DECL || $frag->node === null) {
        return renameLeadingBinder($source, $from, $to);
    }

    $replacements = [];
    $node = $frag->node;

    if ($node instanceof Ast\FunctionDecl) {
        if ($node->name === $from) {
            $binderSpan = leadingBinderSpan($source, $from);
            if ($binderSpan !== null) {
                $replacements[] = $binderSpan + ['text' => $to];
            }
        }

        $bound = [];
        foreach ($node->params as $param) {
            foreach (patternVariableNames($param) as $name) {
                $bound[$name] = true;
            }
        }
        collectFreeRenameSpans($node->body, $from, $bound, $replacements, $to);
    } else {
        // data / class / instance / etc.: only rename a leading value-like binder if present
        return renameLeadingBinder($source, $from, $to);
    }

    return applySourceReplacements($source, $replacements);
}

function renameLeadingBinder(string $source, string $from, string $to): string
{
    if (leadingBindingName($source) !== $from) {
        return $source;
    }

    return preg_replace(
        '/^(\s*)' . preg_quote($from, '/') . '\b/',
        '${1}' . $to,
        $source,
        1,
    ) ?? $source;
}

/**
 * @return ?array{line: int, col: int, endCol: int}
 */
function leadingBinderSpan(string $source, string $from): ?array
{
    if (preg_match('/^(\s*)' . preg_quote($from, '/') . '\b/', $source, $m) !== 1) {
        return null;
    }

    $col = strlen($m[1]) + 1;

    return ['line' => 1, 'col' => $col, 'endCol' => $col + strlen($from)];
}

/**
 * @param array<string, true> $bound
 * @param list<array{line: int, col: int, endCol: int, text: string}> $out
 */
function collectFreeRenameSpans(Ast\AstNode $expr, string $from, array $bound, array &$out, string $to): void
{
    match ($expr::class) {
        Ast\Variable::class => (static function () use ($expr, $from, $bound, &$out, $to): void {
            if ($expr->name === $from && !isset($bound[$from]) && $expr->line > 0 && $expr->col > 0) {
                $out[] = [
                    'line' => $expr->line,
                    'col' => $expr->col,
                    'endCol' => $expr->endCol > $expr->col ? $expr->endCol : $expr->col + strlen($from),
                    'text' => $to,
                ];
            }
        })(),
        Ast\IntegerLit::class,
        Ast\DoubleLit::class,
        Ast\StringLit::class,
        Ast\CharLit::class,
        Ast\ConstructorRef::class,
        Ast\OperatorRef::class,
        Ast\ExprHole::class,
        Ast\EvidenceRef::class => null,
        Ast\TypeAsc::class => collectFreeRenameSpans($expr->expr, $from, $bound, $out, $to),
        Ast\Apply::class => (static function () use ($expr, $from, $bound, &$out, $to): void {
            collectFreeRenameSpans($expr->function, $from, $bound, $out, $to);
            collectFreeRenameSpans($expr->argument, $from, $bound, $out, $to);
        })(),
        Ast\Infix::class => (static function () use ($expr, $from, $bound, &$out, $to): void {
            collectFreeRenameSpans($expr->left, $from, $bound, $out, $to);
            collectFreeRenameSpans($expr->right, $from, $bound, $out, $to);
        })(),
        Ast\Tuple::class, Ast\ListLit::class => (static function () use ($expr, $from, $bound, &$out, $to): void {
            foreach ($expr->elements as $el) {
                collectFreeRenameSpans($el, $from, $bound, $out, $to);
            }
        })(),
        Ast\Lambda::class => (static function () use ($expr, $from, $bound, &$out, $to): void {
            $local = $bound;
            foreach ($expr->params as $param) {
                $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;
                foreach (patternVariableNames($pattern) as $name) {
                    $local[$name] = true;
                }
            }
            collectFreeRenameSpans($expr->body, $from, $local, $out, $to);
        })(),
        Ast\Let::class => collectFreeRenameSpansInBindings($expr->bindings, $expr->body, $from, $bound, $out, $to),
        Ast\Where::class => collectFreeRenameSpansInBindings($expr->bindings, $expr->expr, $from, $bound, $out, $to),
        Ast\CaseExpr::class => (static function () use ($expr, $from, $bound, &$out, $to): void {
            collectFreeRenameSpans($expr->scrutinee, $from, $bound, $out, $to);
            foreach ($expr->alts as $alt) {
                $local = $bound;
                foreach (patternVariableNames($alt->pattern) as $name) {
                    $local[$name] = true;
                }
                collectFreeRenameSpans($alt->body, $from, $local, $out, $to);
            }
        })(),
        Ast\GuardsExpr::class => (static function () use ($expr, $from, $bound, &$out, $to): void {
            foreach ($expr->clauses as $clause) {
                collectFreeRenameSpans($clause->guard, $from, $bound, $out, $to);
                collectFreeRenameSpans($clause->body, $from, $bound, $out, $to);
            }
        })(),
        Ast\DoExpr::class => (static function () use ($expr, $from, $bound, &$out, $to): void {
            if ($expr->desugared !== null) {
                collectFreeRenameSpans($expr->desugared, $from, $bound, $out, $to);
            }
        })(),
        Ast\RecordCon::class => (static function () use ($expr, $from, $bound, &$out, $to): void {
            foreach ($expr->fields as $field) {
                collectFreeRenameSpans($field->expr, $from, $bound, $out, $to);
            }
        })(),
        Ast\RecordUpdate::class => (static function () use ($expr, $from, $bound, &$out, $to): void {
            collectFreeRenameSpans($expr->object, $from, $bound, $out, $to);
            foreach ($expr->fields as $field) {
                collectFreeRenameSpans($field->expr, $from, $bound, $out, $to);
            }
        })(),
        Ast\FieldAccess::class => collectFreeRenameSpans($expr->object, $from, $bound, $out, $to),
        Ast\QualifiedRef::class,
        Ast\EvidenceMethod::class,
        Ast\IntrinsicCall::class => null,
        default => null,
    };
}

/**
 * @param list<Ast\Binding> $bindings
 * @param array<string, true> $bound
 * @param list<array{line: int, col: int, endCol: int, text: string}> $out
 */
function collectFreeRenameSpansInBindings(
    array $bindings,
    Ast\AstNode $body,
    string $from,
    array $bound,
    array &$out,
    string $to,
): void {
    $local = $bound;
    foreach ($bindings as $binding) {
        collectFreeRenameSpans($binding->value, $from, $local, $out, $to);
        foreach (patternVariableNames($binding->pattern) as $name) {
            $local[$name] = true;
        }
    }
    collectFreeRenameSpans($body, $from, $local, $out, $to);
}

/**
 * @param list<array{line: int, col: int, endCol: int, text: string}> $replacements
 */
function applySourceReplacements(string $source, array $replacements): string
{
    if ($replacements === []) {
        return $source;
    }

    usort(
        $replacements,
        static function (array $a, array $b): int {
            if ($a['line'] !== $b['line']) {
                return $b['line'] <=> $a['line'];
            }

            return $b['col'] <=> $a['col'];
        },
    );

    foreach ($replacements as $rep) {
        $start = sourceOffset($source, $rep['line'], $rep['col']);
        $end = sourceOffset($source, $rep['line'], $rep['endCol']);
        if ($start < 0 || $end < $start || $end > strlen($source)) {
            continue;
        }
        $source = substr($source, 0, $start) . $rep['text'] . substr($source, $end);
    }

    return $source;
}

function sourceOffset(string $source, int $line, int $col): int
{
    $lines = explode("\n", $source);
    if ($line < 1 || $line > count($lines)) {
        return -1;
    }

    $offset = 0;
    for ($i = 0; $i < $line - 1; $i++) {
        $offset += strlen($lines[$i]) + 1;
    }

    return $offset + max(0, $col - 1);
}

function isShadowedInteractiveName(string $name): bool
{
    return preg_match('/__s\d+$/', $name) === 1;
}
