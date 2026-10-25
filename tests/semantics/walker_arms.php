<?php declare(strict_types=1);

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

/**
 * The source of one top-level function, from its `function name(` to the next.
 */
$bodyOf = static function (string $file, string $function): string {
    $source = \file_get_contents($file);
    $start = \strpos($source, 'function ' . $function . '(');
    if ($start === false) {
        throw new \RuntimeException("function {$function} not found in {$file}");
    }

    $rest = \substr($source, $start);
    $end = \strpos($rest, "\nfunction ", 1);

    return $end === false ? $rest : \substr($rest, 0, $end);
};

/**
 * The node kinds a function's `match ($expr::class)` names, read out of its own source.
 *
 * The expression passes below are hand-written and walk the same bodies: the
 * resolver that inserts dictionaries and the annotation zonker. They are kept
 * in sync by hand, and a node kind one of them walks but the other does not is
 * a silent skip -- a constrained value inside that node never has its evidence
 * resolved or its type zonked.
 *
 * The guard that fails when an obligation is left behind is no longer one of
 * them: it walks the elaboration generically (`walkAstValues()`), so it cannot
 * skip a shape and this test requires it to stay that way.
 *
 * @return list<string>
 */
$arms = static function (string $body): array {
    \preg_match_all('/([A-Za-z][A-Za-z0-9]*)::class/', $body, $matches);

    return \array_values(\array_unique($matches[1]));
};

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$infer = $root . '/src/semantics/types/infer.php';
$typeCore = $root . '/src/semantics/types/type_core.php';

$guardBody = $bodyOf($infer, 'assertNoPendingConstraintsInExpr');
$guard = $arms($guardBody);
$resolver = $arms($bodyOf($infer, 'resolvePendingEvidenceInExpr'));
$zonk = $arms($bodyOf($typeCore, 'zonkInferredTypesInExpr'));

$assert(
    $guard === [] && \str_contains($guardBody, 'walkAstValues('),
    'the obligation guard must be one generic walk over the elaboration (`walkAstValues()`), not a list of node kinds; it names: '
        . \implode(', ', $guard),
);
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
        $label . ' must name every node kind its sibling walks; missing: '
            . \implode(', ', $missing),
    );
};

$covers('resolvePendingEvidenceInExpr', $zonk, $resolver);
$covers('zonkInferredTypesInExpr', $resolver, $zonk);

echo 'walker arms: ' . \count($resolver) . " node kinds agreed on by resolver and zonk\n";
