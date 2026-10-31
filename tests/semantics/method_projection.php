<?php declare(strict_types=1);

/**
 * A projected class method keeps the dictionary argument that an associated type
 * reduces on.
 *
 * `substituteClassMethod` writes the resolved constraint's arguments into the
 * method's type, so a use site must not freshen the rigid variable that reaches
 * an associated-family application: freshening it hands back `Rep t` where the
 * constraint had already fixed the argument, and the result is a family
 * application that can never reduce. The method's own variables are still
 * freshened, a flexible head variable is still freshened (it is not the
 * dictionary's answer yet), and a rigid head variable outside a family
 * application keeps the tree's existing behaviour.
 *
 * The same rule reaches the other seam: a class method used *without* a
 * projected dictionary (`to r`, `from x`) has its head fixed by the operand, and
 * the method's whole type is then instantiated at it. Section 2 pins that here,
 * where it is one call on internal types rather than a program whose shape would
 * have to be read.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Semantics\TypeExpr;
use Moggi\Semantics\Types;
use Moggi\Syntax\Ast;

/** @var list<string> $failures */
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$typeToString = static fn (TypeExpr\Type $type): string => Types\typeToString($type);
$project = static function (TypeExpr\Type $type, array $args, array $rigid = []) use (&$state, $typeToString): string {
    if (! isset($state)) {
        $state = Types\newState('');
        $state->associatedFamilies['Rep'] = ['params' => ['a'], 'class' => 'Has'];
    }
    $previous = $state->rigidVars;
    $state->rigidVars = $rigid;
    try {
        return $typeToString(Types\freshenConstraintMethod($state, new Ast\EvidenceMethod('Has', 'from', ''), [
            'class' => 'Has',
            'method' => 'from',
            'type' => $type,
            'constraintArgs' => $args,
        ]));
    } finally {
        $state->rigidVars = $previous;
    }
};

$methodType = new TypeExpr\TArrow(
    new TypeExpr\TVar('a'),
    new TypeExpr\TCon('Rep', [new TypeExpr\TVar('a'), new TypeExpr\TVar('i')]),
);
$rigid = ['a' => true];

$used = $project($methodType, [new TypeExpr\TVar('a')], $rigid);
$check(
    \preg_match('/^a -> Rep a t\d+$/', $used) === 1,
    "the associated type's argument was freshened away from the dictionary head, or the method's own index was not freshened: {$used}",
);

$second = $project($methodType, [new TypeExpr\TVar('a')], $rigid);
$check(
    \str_starts_with($second, 'a -> '),
    "the second use lost the dictionary head: {$second}",
);
$check(
    $second !== $used,
    "two uses of one method share the method-local variable: {$used}",
);

$flexible = $project($methodType, [new TypeExpr\TVar('a')]);
$check(
    \preg_match('/^t\d+ -> Rep t\d+ t\d+$/', $flexible) === 1,
    "a flexible head variable was kept instead of freshened: {$flexible}",
);

// Outside a family application the head keeps the tree's existing behaviour.
$plainType = new TypeExpr\TArrow(new TypeExpr\TVar('a'), new TypeExpr\TArrow(new TypeExpr\TVar('a'), new TypeExpr\TUnit()));
$plain = $project($plainType, [new TypeExpr\TVar('a')], $rigid);
$check(
    \preg_match('/^t\d+ -> t\d+ -> \(\)$/', $plain) === 1,
    "a head variable outside an associated type changed behaviour: {$plain}",
);

// ---- 2. The instance-selected seam: the operand fixes the head ----

/**
 * A callee carrying one obligation `Has <head>`, instantiated at `$parameter`
 * (the method's domain) and applied to an operand of `$operand`.
 */
$resolve = static function (TypeExpr\Type $parameter, TypeExpr\Type $operand, array $rigid = []) use ($typeToString): string {
    $state = Types\newState('');
    $state->associatedFamilies['Rep'] = ['params' => ['a'], 'class' => 'Has'];
    $state->rigidVars = $rigid;

    $head = new TypeExpr\TVar('h');
    $callee = new Ast\Variable('from');
    Types\setPendingObligations($state, $callee, [new Ast\PendingConstraint('Has', [$head], '', false, null)]);

    Types\resolveMethodHeadFromArgument($state, $callee, $parameter, $operand);

    return $typeToString(Types\prune($state, $head));
};

$parameter = new TypeExpr\TCon('Rep', [new TypeExpr\TVar('h'), new TypeExpr\TVar('i')]);

$resolved = $resolve(
    $parameter,
    new TypeExpr\TCon('Rep', [new TypeExpr\TCon('Foo'), new TypeExpr\TVar('j')]),
);
$check(
    $resolved === 'Foo',
    "the operand's head did not instantiate the method's dictionary: {$resolved}",
);

// A rigid head is an answer too: the skolem is the dictionary's, and it stays rigid.
$skolem = $resolve(
    $parameter,
    new TypeExpr\TCon('Rep', [new TypeExpr\TVar('a$sk'), new TypeExpr\TVar('j')]),
    ['a$sk' => true],
);
$check(
    $skolem === 'a$sk',
    "a rigid operand head did not reach the dictionary, or was renamed: {$skolem}",
);

// A stuck operand says nothing: no equation mentions it, so nothing is learned.
$stuck = $resolve(
    $parameter,
    new TypeExpr\TCon('Rep', [new TypeExpr\TVar('b'), new TypeExpr\TVar('j')]),
);
$check(
    $stuck === 'h',
    "a stuck family application was guessed into a head: {$stuck}",
);

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " method-projection failure(s):\n  " . \implode("\n  ", $failures),
    );
}

echo "method projection tests passed\n";
