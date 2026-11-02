<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\typeFail;

/**
 * Stock `deriving Ix`: an enumeration, or the one constructor whose fields are
 * themselves `Ix`.
 *
 * Both shapes are the ones the reference implementation accepts. An enumeration
 * has positions to index by itself, and a single constructor is indexed exactly
 * like the tuple of its fields (row-major, the last field varying fastest), so
 * the derived bodies are the tuple instances of `Data.Ix` with the constructor
 * wrapped around the components:
 *
 *   - `range` is the cartesian product of the field ranges, in order;
 *   - `unsafeIndex` is the tuple offset, its last component's stride being the
 *     size of that component's range;
 *   - `inRange` is the conjunction of the components' membership.
 *
 * An enumeration reads its positions through `fromEnum`/`enumFromTo` rather than
 * a tabulated constructor order, so a hand-written `Enum` instance is followed
 * instead of overruled; that is also why deriving `Ix` for an enumeration needs
 * `Enum` to exist for the type, exactly as deriving `Ord` needs `Eq`. `index`,
 * `rangeSize` and `unsafeRangeSize` all come from the class defaults in terms of
 * `range` and `unsafeIndex`.
 */
function deriveIx(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    if ($decl->constructors === []) {
        throw typeFail(
            $state,
            'cannot derive Ix: empty data type has no constructors',
            $ref,
        );
    }

    if (\count($decl->constructors) === 1 && constructorDeriveFields($decl->constructors[0]) !== []) {
        return deriveIxProduct($decl, $decl->constructors[0], $ref);
    }

    foreach ($decl->constructors as $ctor) {
        if (constructorDeriveFields($ctor) !== []) {
            throw typeFail(
                $state,
                "cannot derive Ix: constructor `{$ctor->name}` has fields; only an enumeration "
                    . 'or a single-constructor product can be indexed',
                $ref,
            );
        }
    }

    if ($decl->params !== []) {
        throw typeFail(
            $state,
            'cannot derive Ix: type has parameters (an enumeration requires a nullary type)',
            $ref,
        );
    }

    return new DerivedInstance(
        'Ix',
        dataDeclHeadAst($decl),
        [],
        [
            methodDecl(
                'range',
                [new Ast\PatVar('__b')],
                synthesizeIxRange('__b'),
                $ref,
            ),
            methodDecl(
                'unsafeIndex',
                [new Ast\PatVar('__b'), new Ast\PatVar('__i')],
                synthesizeIxUnsafeIndex('__b', '__i'),
                $ref,
            ),
            methodDecl(
                'inRange',
                [new Ast\PatVar('__b'), new Ast\PatVar('__i')],
                synthesizeIxInRange('__b', '__i'),
                $ref,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

/** A single constructor indexed like the tuple of its fields. */
function deriveIxProduct(
    Ast\DataDecl $decl,
    Ast\ConstructorDecl $ctor,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $arity = \count(constructorDeriveFields($ctor));

    return new DerivedInstance(
        'Ix',
        dataDeclHeadAst($decl),
        paramClassConstraints($decl, 'Ix'),
        [
            methodDecl(
                'range',
                [new Ast\PatVar('__b')],
                synthesizeIxProductRange($ctor, $arity),
                $ref,
            ),
            methodDecl(
                'unsafeIndex',
                [new Ast\PatVar('__b'), new Ast\PatVar('__x')],
                synthesizeIxProductUnsafeIndex($ctor, $arity),
                $ref,
            ),
            methodDecl(
                'inRange',
                [new Ast\PatVar('__b'), new Ast\PatVar('__x')],
                synthesizeIxProductInRange($ctor, $arity),
                $ref,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

/**
 * `range (C l1, C u1) = concatMap (\i1 -> … [C i1] …) (range (l1, u1))`
 *
 * The fields are mapped in order, so the last one varies fastest, which is the
 * row-major order `Ix` is defined by.
 */
function synthesizeIxProductRange(Ast\ConstructorDecl $ctor, int $arity): Ast\AstNode
{
    $lows = varNames('__l', $arity);
    $highs = varNames('__u', $arity);
    $indices = varNames('__i', $arity);

    $body = new Ast\ListLit([applyCtor($ctor->name, array_map(
        static fn (string $name): Ast\AstNode => new Ast\Variable($name),
        $indices,
    ))]);

    for ($k = $arity - 1; $k >= 0; --$k) {
        $body = applyVar(
            'concatMap',
            new Ast\Lambda([new Ast\LambdaParam(new Ast\PatVar($indices[$k]))], $body),
            applyVar('range', new Ast\Tuple([new Ast\Variable($lows[$k]), new Ast\Variable($highs[$k])])),
        );
    }

    return new Ast\CaseExpr(new Ast\Variable('__b'), [
        new Ast\Alt(
            new Ast\PatTuple([
                new Ast\PatCon($ctor->name, patternVars($lows)),
                new Ast\PatCon($ctor->name, patternVars($highs)),
            ]),
            $body,
        ),
    ]);
}

/** `unsafeIndex (C l…, C u…) (C i…) = unsafeIndex (ln, un) in + size (ln, un) * …` */
function synthesizeIxProductUnsafeIndex(Ast\ConstructorDecl $ctor, int $arity): Ast\AstNode
{
    $lows = varNames('__l', $arity);
    $highs = varNames('__u', $arity);
    $indices = varNames('__i', $arity);

    $body = ixProjection('unsafeIndex', $lows, $highs, $indices, 0);
    for ($k = 1; $k < $arity; ++$k) {
        $body = applyOp(
            '+',
            ixProjection('unsafeIndex', $lows, $highs, $indices, $k),
            applyOp('*', ixProjection('unsafeRangeSize', $lows, $highs, $indices, $k), $body),
        );
    }

    return new Ast\CaseExpr(new Ast\Variable('__b'), [
        new Ast\Alt(
            new Ast\PatTuple([
                new Ast\PatCon($ctor->name, patternVars($lows)),
                new Ast\PatCon($ctor->name, patternVars($highs)),
            ]),
            new Ast\CaseExpr(new Ast\Variable('__x'), [
                new Ast\Alt(new Ast\PatCon($ctor->name, patternVars($indices)), $body),
            ]),
        ),
    ]);
}

/** `inRange (C l…, C u…) (C i…) = inRange (l1, u1) i1 && …` */
function synthesizeIxProductInRange(Ast\ConstructorDecl $ctor, int $arity): Ast\AstNode
{
    $lows = varNames('__l', $arity);
    $highs = varNames('__u', $arity);
    $indices = varNames('__i', $arity);

    $body = ixProjection('inRange', $lows, $highs, $indices, 0);
    for ($k = 1; $k < $arity; ++$k) {
        $body = applyOp('&&', ixProjection('inRange', $lows, $highs, $indices, $k), $body);
    }

    return new Ast\CaseExpr(new Ast\Variable('__b'), [
        new Ast\Alt(
            new Ast\PatTuple([
                new Ast\PatCon($ctor->name, patternVars($lows)),
                new Ast\PatCon($ctor->name, patternVars($highs)),
            ]),
            new Ast\CaseExpr(new Ast\Variable('__x'), [
                new Ast\Alt(new Ast\PatCon($ctor->name, patternVars($indices)), $body),
            ]),
        ),
    ]);
}

/**
 * One field's contribution: `f (lk, uk) ik` for the two-argument class methods,
 * `f (lk, uk)` for the range size.
 *
 * @param list<string> $lows
 * @param list<string> $highs
 * @param list<string> $indices
 */
function ixProjection(string $method, array $lows, array $highs, array $indices, int $k): Ast\AstNode
{
    $bounds = new Ast\Tuple([new Ast\Variable($lows[$k]), new Ast\Variable($highs[$k])]);

    if ($method === 'unsafeRangeSize') {
        return applyVar($method, $bounds);
    }

    return applyVar($method, $bounds, new Ast\Variable($indices[$k]));
}

/** @return list<string> */
function varNames(string $prefix, int $count): array
{
    $names = [];
    for ($i = 0; $i < $count; ++$i) {
        $names[] = $prefix . $i;
    }

    return $names;
}

/** @param list<string> $names */
function patternVars(array $names): array
{
    $patterns = [];
    foreach ($names as $name) {
        $patterns[] = new Ast\PatVar($name);
    }

    return $patterns;
}

/** `range (__l, __u) = enumFromTo __l __u` */
function synthesizeIxRange(string $b): Ast\AstNode
{
    return new Ast\CaseExpr(new Ast\Variable($b), [
        new Ast\Alt(
            new Ast\PatTuple([new Ast\PatVar('__l'), new Ast\PatVar('__u')]),
            applyVar('enumFromTo', new Ast\Variable('__l'), new Ast\Variable('__u')),
        ),
    ]);
}

/** `unsafeIndex (__l, _) __i = fromEnum __i - fromEnum __l` */
function synthesizeIxUnsafeIndex(string $b, string $i): Ast\AstNode
{
    return new Ast\CaseExpr(new Ast\Variable($b), [
        new Ast\Alt(
            new Ast\PatTuple([new Ast\PatVar('__l'), new Ast\PatWild()]),
            applyOp('-', ixCode($i), ixCode('__l')),
        ),
    ]);
}

/** `inRange (__l, __u) __i = fromEnum __i >= fromEnum __l && fromEnum __i <= fromEnum __u` */
function synthesizeIxInRange(string $b, string $i): Ast\AstNode
{
    return new Ast\CaseExpr(new Ast\Variable($b), [
        new Ast\Alt(
            new Ast\PatTuple([new Ast\PatVar('__l'), new Ast\PatVar('__u')]),
            applyOp(
                '&&',
                applyOp('>=', ixCode($i), ixCode('__l')),
                applyOp('<=', ixCode($i), ixCode('__u')),
            ),
        ),
    ]);
}

/** The constructor's position: `fromEnum __i`. */
function ixCode(string $var): Ast\AstNode
{
    return applyVar('fromEnum', new Ast\Variable($var));
}
