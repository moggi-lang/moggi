<?php declare(strict_types=1);

/**
 * The five structural laws agree on every type head.
 *
 * The table below is the law's own coverage report: each of the 22 constructors
 * of the internal type representation gets one case, and the test fails if the
 * compiler declares a head the table does not cover. The promoted (`'Pair a`)
 * and variable-headed (`f a`) cases are the ones that used to disagree: free
 * variables said they had none, prune left their arguments unsubstituted and
 * normalization skipped synonyms inside a variable-headed application.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Semantics\TypeExpr;
use Moggi\Semantics\Types;

/** @var list<string> $failures */
$failures = [];

$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$throws = static function (callable $call): ?\Throwable {
    try {
        $call();
    } catch (\Throwable $e) {
        return $e;
    }

    return null;
};

/** Free variables of a type, as sorted names. */
$freeVars = static function (TypeExpr\Type $type): array {
    $names = \array_keys(Types\typeVars($type));
    \sort($names);

    return $names;
};

$var = static fn (string $name): TypeExpr\TVar => new TypeExpr\TVar($name);
//

$heads = [
    TypeExpr\TInt::class => [new TypeExpr\TInt(), 0, []],
    TypeExpr\TStr::class => [new TypeExpr\TStr(), 0, []],
    TypeExpr\TChar::class => [new TypeExpr\TChar(), 0, []],
    TypeExpr\TWord::class => [new TypeExpr\TWord(), 0, []],
    TypeExpr\TWord8::class => [new TypeExpr\TWord8(), 0, []],
    TypeExpr\TWord16::class => [new TypeExpr\TWord16(), 0, []],
    TypeExpr\TWord32::class => [new TypeExpr\TWord32(), 0, []],
    TypeExpr\TWord64::class => [new TypeExpr\TWord64(), 0, []],
    TypeExpr\TInt8::class => [new TypeExpr\TInt8(), 0, []],
    TypeExpr\TInt16::class => [new TypeExpr\TInt16(), 0, []],
    TypeExpr\TInt32::class => [new TypeExpr\TInt32(), 0, []],
    TypeExpr\TInt64::class => [new TypeExpr\TInt64(), 0, []],
    TypeExpr\TBytes::class => [new TypeExpr\TBytes(), 0, []],
    TypeExpr\TDouble::class => [new TypeExpr\TDouble(), 0, []],
    TypeExpr\TUnit::class => [new TypeExpr\TUnit(), 0, []],
    TypeExpr\TVar::class => [$var('a'), 0, ['a']],
    TypeExpr\TStringLit::class => [new TypeExpr\TStringLit('hello'), 0, []],
    TypeExpr\TNatLit::class => [new TypeExpr\TNatLit('1024'), 0, []],
    TypeExpr\TVarApp::class => [new TypeExpr\TVarApp('f', [$var('a')]), 1, ['a', 'f']],
    TypeExpr\TCon::class => [new TypeExpr\TCon('List', [$var('a')]), 1, ['a']],
    TypeExpr\TArrow::class => [new TypeExpr\TArrow($var('a'), $var('b')), 2, ['a', 'b']],
    TypeExpr\TPromoted::class => [new TypeExpr\TPromoted('Pair', [$var('a')]), 1, ['a']],
];

$declared = [];
foreach (\get_declared_classes() as $class) {
    if (\is_a($class, TypeExpr\Type::class, true)) {
        $declared[$class] = true;
    }
}
$check(\count($declared) === \count($heads), 'declared type heads: ' . \count($declared) . ' vs table ' . \count($heads));
foreach (\array_keys($declared) as $class) {
    $check(isset($heads[$class]), "type head `{$class}` has no law case");
}

foreach ($heads as $class => [$sample, $children, $free]) {
    $check(
        \count(Types\typeChildren($sample)) === $children,
        "{$class}: typeChildren count is not {$children}",
    );
    $check(
        $freeVars($sample) === $free,
        "{$class}: typeVars is [" . \implode(',', $freeVars($sample)) . '], expected [' . \implode(',', $free) . ']',
    );
    $collected = \array_keys(TypeExpr\collectTypeVars($sample, []));
    \sort($collected);
    $check($collected === $free, "{$class}: collectTypeVars disagrees with typeVars");
    $check(
        $freeVars(Types\substitute($sample, [])) === $free,
        "{$class}: substitute with an empty mapping changed free variables",
    );
    $check(
        $freeVars(Types\prune(new Types\TypeCheckState(''), $sample)) === $free,
        "{$class}: prune with an empty substitution changed free variables",
    );
}

$promoted = new TypeExpr\TPromoted('Pair', [$var('a')]);
$state = Types\newState('');
$state->subst = ['a' => new TypeExpr\TInt()];
$check(
    Types\typeToString(Types\prune($state, $promoted)) === "'Pair Int",
    'prune does not descend into a promoted application',
);
$check(
    Types\typeToString(Types\substitute($promoted, ['a' => new TypeExpr\TStr()])) === "'Pair String",
    'substitute does not descend into a promoted application',
);
$check(
    \array_keys(TypeExpr\schemeFreeTypeVars($promoted, [], [])) === ['a'],
    'a scheme over a promoted application reports no free variable',
);
$check(
    $throws(static fn () => Types\occurs(new Types\TypeCheckState(''), 'a', $promoted)) instanceof Types\TypeError,
    'occurs does not reject a promoted application that mentions the variable',
);
$check(
    $throws(static fn () => Types\occurs(new Types\TypeCheckState(''), 'z', $promoted)) === null,
    'occurs rejects a variable the promoted application does not mention',
);
$check(
    Types\typeToString(Types\normalizeType(new Types\TypeCheckState(''), $promoted)) === "'Pair a",
    'normalization changed a promoted application it should have left alone',
);

$nestedPromoted = new TypeExpr\TPromoted('InfixI', [new TypeExpr\TPromoted('LeftAssociative')]);
$check(
    $freeVars($nestedPromoted) === [],
    'nested promoted heads gained a free variable',
);
//
$familyState = new Types\TypeCheckState('');
$familyState->associatedFamilies['Rep'] = ['params' => ['x'], 'class' => 'Generic'];
$familyState->associatedEquations['Generic']['Rep'] = [[
    'lhsArgs' => [new \Moggi\Syntax\Ast\TypeCon('Color')],
    'rhs' => new \Moggi\Syntax\Ast\TypeCon('Colors'),
    'module' => '',
]];
$familyApp = new TypeExpr\TCon('Rep', [$var('a')]);

$stuck = Types\normalizeType($familyState, $familyApp, reduceFamilies: false);
$check(
    Types\typeToString($stuck) === 'Rep a',
    'a family application is no longer left stuck when reduction is off',
);
$check(
    Types\typeToString(Types\normalizeType($familyState, $familyApp)) === 'Rep a',
    'a polymorphic family application reduced against an equation it does not match',
);
$check($freeVars($familyApp) === ['a'], 'free variables do not reach the argument of a family application');
$check(\count(Types\typeChildren($familyApp)) === 1, 'a family application is not enumerated as one child deep');
$check(
    Types\typeToString(Types\prune((static function (): Types\TypeCheckState {
        $s = new Types\TypeCheckState('');
        $s->subst = ['a' => new TypeExpr\TInt()];

        return $s;
    })(), $familyApp)) === 'Rep Int',
    'prune does not descend into a family application argument',
);
$check(
    Types\typeToString(Types\substitute($familyApp, ['a' => new TypeExpr\TStr()])) === 'Rep String',
    'substitute does not descend into a family application argument',
);
$check(
    $throws(static fn () => Types\occurs(new Types\TypeCheckState(''), 'a', $familyApp)) instanceof Types\TypeError,
    'occurs does not reject a family application that mentions the variable',
);

$applied = new TypeExpr\TVarApp('f', [$var('a')]);
$check(\array_keys(Types\typeVars($applied)) === ['f', 'a'], 'typeVars lost the variable head of an application');
$check(
    Types\typeToString(Types\prune((static function (): Types\TypeCheckState {
        $s = new Types\TypeCheckState('');
        $s->subst = ['f' => new TypeExpr\TCon('Maybe')];

        return $s;
    })(), $applied)) === 'Maybe a',
    'prune did not rewrite the head of a variable-headed application',
);
$check(
    Types\typeToString(Types\substitute($applied, ['f' => new TypeExpr\TCon('Maybe')])) === 'Maybe a',
    'substitute did not rewrite the head of a variable-headed application',
);
$synonymState = new Types\TypeCheckState('');
$synonymState->typeSynonyms['Alias'] = [
    'params' => ['x'],
    'rhs' => new \Moggi\Syntax\Ast\TypeVar('x'),
];
$insideCon = new TypeExpr\TCon('List', [new TypeExpr\TCon('Alias', [new TypeExpr\TInt()])]);
$insideApp = new TypeExpr\TVarApp('f', [new TypeExpr\TCon('Alias', [new TypeExpr\TInt()])]);
$check(
    Types\typeToString(Types\normalizeType($synonymState, $insideCon)) === '[Int]',
    'normalization no longer expands a synonym inside a constructor application',
);
$check(
    Types\typeToString(Types\normalizeType($synonymState, $insideApp)) === 'f Int',
    'normalization leaves a synonym inside a variable-headed application unexpanded',
);

$unknown = new class implements TypeExpr\Type {
};

$unknownChecks = [
    'typeChildren' => static fn () => Types\typeChildren($unknown),
    'typeVars' => static fn () => Types\typeVars($unknown),
    'collectTypeVars' => static fn () => TypeExpr\collectTypeVars($unknown, []),
    'prune' => static fn () => Types\prune(new Types\TypeCheckState(''), $unknown),
    'substitute' => static fn () => Types\substitute($unknown, []),
    'occurs' => static fn () => Types\occurs(new Types\TypeCheckState(''), 'a', $unknown),
    'normalize' => static fn () => Types\normalizeType(new Types\TypeCheckState(''), $unknown),
];
foreach ($unknownChecks as $law => $call) {
    $error = $throws($call);
    $check(
        $error instanceof Types\UnknownTypeHead,
        "{$law} did not reject an unknown type head (got " . ($error === null ? 'no error' : $error::class) . ')',
    );
    $check(
        $error instanceof Types\UnknownTypeHead && \str_contains($error->getMessage(), 'unknown type head `'),
        "{$law}: the unknown-head error does not name the head",
    );
}

$generator = static function (int $depth) use (&$generator, $var): TypeExpr\Type {
    $pick = \mt_rand(0, $depth <= 0 ? 1 : 5);
    $atom = $pick === 0
        ? $var(['a', 'b', 'c'][\mt_rand(0, 2)])
        : new TypeExpr\TInt();

    return match ($pick) {
        0, 1 => $atom,
        2 => new TypeExpr\TCon('List', [$generator($depth - 1)]),
        3 => new TypeExpr\TPromoted('Pair', [$generator($depth - 1), $generator($depth - 1)]),
        4 => new TypeExpr\TVarApp(['f', 'g'][\mt_rand(0, 1)], [$generator($depth - 1)]),
        default => new TypeExpr\TArrow($generator($depth - 1), $generator($depth - 1)),
    };
};

\mt_srand(20261020);
$mapping = ['a' => new TypeExpr\TCon('Maybe', [$var('b')]), 'b' => new TypeExpr\TStr()];
$mappingState = new Types\TypeCheckState('');
$mappingState->subst = $mapping;

for ($i = 0; $i < 120; ++$i) {
    $type = $generator(3);
    $text = Types\typeToString($type);

    $prunedOnce = Types\prune($mappingState, $type);
    $prunedTwice = Types\prune($mappingState, $prunedOnce);
    $check(
        Types\typeToString($prunedTwice) === Types\typeToString($prunedOnce),
        "prune is not idempotent on {$text}",
    );

    $substituted = Types\substitute($type, $mapping);
    $check(
        Types\typeToString(Types\substitute($substituted, [])) === Types\typeToString($substituted),
        "substitute with an empty mapping changed {$text}",
    );
    $freeAfter = Types\typeVars($substituted);
    foreach (\array_keys($mapping) as $bound) {
        unset($freeAfter[$bound]);
    }
    $check(
        \array_diff_key($freeAfter, Types\typeVars($type) + ['b' => true]) === [],
        "substitute introduced a free variable into {$text}",
    );

    $check(
        Types\typeToString(Types\normalizeType(new Types\TypeCheckState(''), $type)) === $text,
        "normalization changed a type with no synonyms or families: {$text}",
    );
}

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " type-law failure(s):\n  " . \implode("\n  ", \array_slice($failures, 0, 12)),
    );
}

echo 'type law tests passed (' . \count($heads) . " heads)\n";
