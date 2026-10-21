<?php declare(strict_types=1);

// A1 follow-up: the gate `via` deriving uses to decide whether a class method
// can be delegated, or must be left to its class default.
//
// `typeHasNestedClassParam` reads a method's type and asks whether the class
// parameter sits *under a container*. Its contract names three shapes: `[a]`,
// `Maybe a` and `f a` all need a structural conversion of the whole container,
// which this language has no primitive for, so the method is skipped and the
// class default runs. A parameter directly in an arrow position (`a -> Bool`)
// is wrapped or unwrapped, which every backend can do, so it is derived.
//
// The children come from `typeChildren`, which is why `f a` and `'Pair a` are
// seen at all: walking `->args` by hand made a variable-headed or promoted
// application look like a leaf, and a method matching the contract's own
// example was synthesized as if its parameter were bare.
//
// Why this is a unit pin and not a program fixture: the type checker currently
// rejects both source spellings of the interesting arms —
//
//   class C a where m :: f a -> String
//     → "kind error in class `C`: could not unify kind `Type` with `Type -> k1`"
//       (an applied parameter is inferred higher-kinded, so `a` cannot also be
//        the class parameter)
//   m :: OnlyBox ('Box a) -> String        (Box a data type with a param)
//     → "data constructor `Box` is not promotable" / kind error
//
// so no program in the suite exercises them. The gate is still the place the
// decision is made, and a walker that quietly went back to `->args` would
// reintroduce the hole without any suite case noticing; this file is what
// notices.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Semantics\Deriving\internalTypeMentionsParam;
use function Moggi\Semantics\Deriving\typeHasNestedClassParam;

use Moggi\Semantics\TypeExpr;

/** @var list<string> $failures */
$failures = [];

$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$var = static fn (string $name): TypeExpr\TVar => new TypeExpr\TVar($name);
$arrow = static fn (TypeExpr\Type $from, TypeExpr\Type $to): TypeExpr\TArrow => new TypeExpr\TArrow($from, $to);

// ---- the gate: what `via` deriving delegates ------------------------------

// A parameter directly in an arrow position is wrapped/unwrapped, not
// structural — this is the delegation path, and it must stay open.
$delegated = [
    'a -> Bool' => $arrow($var('a'), new TypeExpr\TInt()),
    'Bool -> a' => $arrow(new TypeExpr\TInt(), $var('a')),
    'a -> a' => $arrow($var('a'), $var('a')),
    'a -> b' => $arrow($var('a'), $var('b')),
    'Int -> Bool' => $arrow(new TypeExpr\TInt(), new TypeExpr\TStr()),
];
foreach ($delegated as $shape => $type) {
    $check(
        ! typeHasNestedClassParam($type, 'a'),
        "{$shape}: a parameter in an arrow position was treated as nested, so the method cannot be derived",
    );
}
$check(
    ! typeHasNestedClassParam(new TypeExpr\TVar('a'), 'a'),
    'a bare parameter was treated as nested',
);
$check(
    ! typeHasNestedClassParam(new TypeExpr\TCon('Maybe', [$var('b')]), 'a'),
    'a container not mentioning the parameter was treated as nested',
);

// A parameter under a container needs a structural conversion: the method is
// left to its class default rather than synthesized.
$nested = [
    '[a]' => new TypeExpr\TCon('List', [$var('a')]),
    'Maybe a' => new TypeExpr\TCon('Maybe', [$var('a')]),
    'f a' => new TypeExpr\TVarApp('f', [$var('a')]),
    "'Pair a" => new TypeExpr\TPromoted('Pair', [$var('a')]),
    'Maybe [a]' => new TypeExpr\TCon('Maybe', [new TypeExpr\TCon('List', [$var('a')])]),
    'a -> Maybe a' => $arrow($var('a'), new TypeExpr\TCon('Maybe', [$var('a')])),
    'Maybe a -> a' => $arrow(new TypeExpr\TCon('Maybe', [$var('a')]), $var('a')),
    'f a -> Bool' => $arrow(new TypeExpr\TVarApp('f', [$var('a')]), new TypeExpr\TInt()),
];
foreach ($nested as $shape => $type) {
    $check(
        typeHasNestedClassParam($type, 'a'),
        "{$shape}: a parameter under a container was treated as bare, so the method would be synthesized",
    );
}

// The parameter appearing as a *head* is a different question, and this
// predicate deliberately answers "not nested": `f`'s own application is what a
// delegation would unwrap. Pinned so a later change has to decide, not drift.
$check(
    ! typeHasNestedClassParam(new TypeExpr\TVarApp('f', [$var('b')]), 'f'),
    'a parameter used as an application head was treated as nested',
);

// ---- the recursive reader underneath it -----------------------------------

$mentions = static fn (TypeExpr\Type $type, string $param): bool => internalTypeMentionsParam($type, $param);

$check($mentions($var('a'), 'a'), 'internalTypeMentionsParam misses a bare variable');
$check(! $mentions($var('a'), 'b'), 'internalTypeMentionsParam invents a mention');
$check(
    $mentions(new TypeExpr\TCon('List', [$var('a')]), 'a'),
    'internalTypeMentionsParam does not reach a constructor argument',
);
$check(
    ! $mentions(new TypeExpr\TCon('List', [new TypeExpr\TInt()]), 'a'),
    'internalTypeMentionsParam rejects a type that does not mention the parameter',
);
$check(
    $mentions(new TypeExpr\TVarApp('f', [$var('a')]), 'a'),
    'internalTypeMentionsParam reads a variable-headed application as a leaf',
);
$check(
    $mentions(new TypeExpr\TPromoted('Pair', [$var('a')]), 'a'),
    'internalTypeMentionsParam reads a promoted application as a leaf',
);
$check(
    ! $mentions(new TypeExpr\TVarApp('f', [$var('b')]), 'f'),
    'internalTypeMentionsParam counts an application head as a child',
);
$check(
    $mentions(new TypeExpr\TArrow($var('b'), new TypeExpr\TPromoted('Pair', [$var('a')])), 'a'),
    'internalTypeMentionsParam does not reach a promoted argument in an arrow codomain',
);

// The gate is what `deriveVia` consults; make sure it is still wired to the
// predicate rather than to a private copy. A source-level run would be better,
// but the checker rejects both spellings (see the header), so the wiring is
// asserted by reading the caller instead: the derivation that skips a nested
// method and keeps a bare one is Tc-Derive-Via-UserClass and
// Tc-Derive-Via-Strategies, whose goldens would change if the gate inverted.
$via = \file_get_contents($root . '/src/semantics/deriving/via.php');
$check(
    \is_string($via) && \str_contains($via, 'typeHasNestedClassParam($methodInfo'),
    'deriveVia no longer consults typeHasNestedClassParam, so a nested method would be synthesized',
);
$check(
    \function_exists('Moggi\\Semantics\\Deriving\\typeHasNestedClassParam'),
    'the gate is not autoloaded through src/compiler.php',
);

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " deriving-gate failure(s):\n  " . \implode("\n  ", $failures),
    );
}

echo 'deriving gate tests passed (' . \count($delegated) . ' delegated, ' . \count($nested) . " nested shapes)\n";
