<?php declare(strict_types=1);

/**
 * Every statement and operand kind in the schema must be one the emitters know.
 *
 * A kind no backend names cannot be emitted: nothing can produce it, because the first program that
 * did would fail codegen, so every walker, dump, validator and mapping arm it has is unreachable and
 * any change to those arms is compared by nothing. Two kinds have been removed from this schema for
 * that reason (`Binop`, whose arms three backends kept for a shape no lowering ever built, and
 * `IoAction`, which no backend named at all). This is the mechanical half of that rule — the
 * producer side is measured by `probes/phase-10/kind-producers.php`, because deciding whether a
 * construction site builds a shape or rebuilds one needs the pass's intent, not its text.
 *
 * Coverage is the three emitters; patterns are excluded because they are handled through the
 * pattern surface (`emitPatternTest`, `emitPhpMatchKey`) rather than per class.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

/** @var list<string> $failures */
$failures = [];

$types = (string) \file_get_contents($root . '/src/ir/types.php');
\preg_match_all('/final class (\w+) implements (Stmt|Operand)\b/', $types, $declared, \PREG_SET_ORDER);

$backend = '';
foreach (['php', 'jvm', 'dotnet'] as $target) {
    foreach (\glob($root . '/src/backend/' . $target . '/*.php') ?: [] as $file) {
        $backend .= (string) \file_get_contents($file);
    }
}

foreach ($declared as [, $kind, $surface]) {
    $named = \preg_match('/\b' . $kind . '::class\b/', $backend) > 0
        || \preg_match('/\binstanceof\s+' . $kind . '\b/', $backend) > 0
        || \preg_match('/\bnew\s+(?:IR\\\\)?' . $kind . '\s*\(/', $backend) > 0;
    if (! $named) {
        $failures[] = "{$kind} ({$surface}) is declared but no backend names it, so nothing can emit it";
    }
}

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " schema/emitter gap(s):\n  " . \implode("\n  ", $failures),
    );
}

echo 'schema/emitter surface: ' . \count($declared) . " kinds, every one emitted\n";
