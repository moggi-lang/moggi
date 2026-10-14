#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

/**
 * The node kinds a top-level function's `match ($expr::class)` names, read out
 * of its own source.
 *
 * The expression passes below are hand-written and walk the same bodies: the
 * resolver that inserts dictionaries, the annotation zonker, and the guard that
 * fails when an obligation is left behind. They are kept in sync by hand, and a
 * node kind one of them walks but another does not is a silent skip -- a
 * constrained value inside that node never has its evidence resolved, and only
 * the guard notices (as an "unresolved type class constraint" on valid source).
 *
 * @return list<string>
 */
$arms = static function (string $file, string $function): array {
    $source = \file_get_contents($file);
    $start = \strpos($source, 'function ' . $function . '(');
    if ($start === false) {
        throw new \RuntimeException("function {$function} not found in {$file}");
    }

    $rest = \substr($source, $start);
    $end = \strpos($rest, "\nfunction ", 1);
    $body = $end === false ? $rest : \substr($rest, 0, $end);

    \preg_match_all('/([A-Za-z][A-Za-z0-9]*)::class/', $body, $matches);

    return \array_values(\array_unique($matches[1]));
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$infer = $root . '/src/semantics/types/infer.php';
$typeCore = $root . '/src/semantics/types/type_core.php';

$guard = $arms($infer, 'assertNoPendingConstraintsInExpr');
$resolver = $arms($infer, 'resolvePendingEvidenceInExpr');
$zonk = $arms($typeCore, 'zonkInferredTypesInExpr');

$assert($guard !== [], 'the guard pass must name at least one node kind');
$assert($resolver !== [], 'the resolver pass must name at least one node kind');
$assert($zonk !== [], 'the zonk pass must name at least one node kind');

/**
 * @param list<string> $required
 * @param list<string> $have
 */
$covers = static function (string $label, array $required, array $have) use ($assert): void {
    $missing = \array_values(\array_diff($required, $have));
    \sort($missing);
    $assert(
        $missing === [],
        $label . ' must name every node kind its siblings walk; missing: '
            . \implode(', ', $missing),
    );
};

$covers('resolvePendingEvidenceInExpr', $guard, $resolver);
$covers('zonkInferredTypesInExpr', $guard, $zonk);
$covers('zonkInferredTypesInExpr', $resolver, $zonk);

echo 'walker arms: ' . \count($guard) . " node kinds agreed on by guard, resolver and zonk\n";
