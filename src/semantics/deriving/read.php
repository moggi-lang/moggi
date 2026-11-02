<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

/**
 * Stock `deriving Read`: the parse of the declaration's `Show` form, one
 * alternative per constructor in declaration order. A nullary constructor is
 * its name, a prefix one reads its fields at `appPrec`, a record one reads
 * `C {f = v, g = w}` in declaration order, and an infix one reads both
 * operands around its own name. Fields parse through `readsPrec`, so a compound
 * field parses by its own instance and hands back what follows it.
 */
function deriveRead(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    return new DerivedInstance(
        'Read',
        dataDeclHeadAst($decl),
        paramClassConstraints($decl, 'Read'),
        [
            methodDecl(
                'readsPrec',
                [new Ast\PatVar('__d'), new Ast\PatVar('__s')],
                synthesizeReadsPrec($decl),
                $ref,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

/** Application precedence: a prefix constructor and its fields. */
const READ_APP_PREC = 10;

/** An infix constructor with no `infix*` declaration is `infixl 9`. */
const READ_DEFAULT_OP_PREC = 9;

function synthesizeReadsPrec(Ast\DataDecl $decl): Ast\AstNode
{
    if ($decl->constructors === []) {
        return applyVar('error', new Ast\StringLit('Read.read: empty data type'));
    }

    $alts = [];
    foreach ($decl->constructors as $i => $ctor) {
        $alts[] = readConstructorParser($ctor, 'c' . $i);
    }

    $body = null;
    foreach (\array_reverse($alts) as $alt) {
        $call = new Ast\Apply($alt, new Ast\Variable('__s'));
        $body = $body === null ? $call : applyOp('++', $call, $body);
    }

    return $body;
}

/** One constructor's alternative: `readParen (__d > prec) (\__r -> …)`. */
function readConstructorParser(Ast\ConstructorDecl $ctor, string $tag): Ast\AstNode
{
    $fields = constructorDeriveFields($ctor);
    $prec = $ctor->declaredInfix
        ? ($ctor->fixityPrec ?? READ_DEFAULT_OP_PREC)
        : READ_APP_PREC;

    if ($ctor->declaredInfix && \count($fields) === 2) {
        $body = readValue($prec, '__r', $tag . 'l', function (string $left, string $rest) use ($ctor, $prec, $tag): Ast\AstNode {
            return readExpectToken($ctor->name, $rest, $tag . 'o', function (string $opRest) use ($ctor, $prec, $tag, $left): Ast\AstNode {
                return readValue($prec, $opRest, $tag . 'r', function (string $right, string $rest) use ($ctor, $left): Ast\AstNode {
                    return new Ast\ListLit([new Ast\Tuple([
                        applyOp($ctor->name, new Ast\Variable($left), new Ast\Variable($right)),
                        new Ast\Variable($rest),
                    ])]);
                });
            });
        });
    } else {
        $body = readExpectToken($ctor->name, '__r', $tag . 'n', function (string $rest) use ($ctor, $fields, $tag): Ast\AstNode {
            if ($fields === []) {
                return new Ast\ListLit([new Ast\Tuple([
                    new Ast\ConstructorRef($ctor->name),
                    new Ast\Variable($rest),
                ])]);
            }

            if ($ctor->isRecord()) {
                return readExpectToken('{', $rest, $tag . 'b', function (string $braceRest) use ($ctor, $fields, $tag): Ast\AstNode {
                    return readRecordFields($ctor->name, $fields, 0, $braceRest, [], $tag);
                });
            }

            return readPrefixFields($ctor->name, \count($fields), 0, $rest, [], $tag);
        });
    }

    $lambda = new Ast\Lambda([new Ast\LambdaParam(new Ast\PatVar('__r'))], $body);

    return applyVar(
        'readParen',
        applyOp('>', new Ast\Variable('__d'), new Ast\IntegerLit($prec)),
        $lambda,
    );
}

/**
 * Expect one lexeme, then continue with whatever follows it.
 *
 * @param callable(string): Ast\AstNode $onMatch receives the rest of the input
 */
function readExpectToken(string $token, string $restVar, string $tag, callable $onMatch): Ast\AstNode
{
    $name = '__k' . $tag;
    $rest = '__t' . $tag;

    $arm = new Ast\Alt(
        new Ast\PatCons(
            new Ast\PatTuple([new Ast\PatVar($name), new Ast\PatVar($rest)]),
            new Ast\PatWild(),
        ),
        new Ast\CaseExpr(new Ast\Variable($name), [
            new Ast\Alt(new Ast\PatLit($token), $onMatch($rest)),
            new Ast\Alt(new Ast\PatWild(), new Ast\ListLit([])),
        ]),
    );

    return new Ast\CaseExpr(applyVar('lex', new Ast\Variable($restVar)), [
        $arm,
        new Ast\Alt(new Ast\PatNil(), new Ast\ListLit([])),
    ]);
}

/**
 * Read one value at the given precedence.
 *
 * @param callable(string, string): Ast\AstNode $onMatch receives the value's
 *        variable and the rest of the input
 */
function readValue(int $prec, string $restVar, string $tag, callable $onMatch): Ast\AstNode
{
    $value = '__v' . $tag;
    $rest = '__t' . $tag;

    $arm = new Ast\Alt(
        new Ast\PatCons(
            new Ast\PatTuple([new Ast\PatVar($value), new Ast\PatVar($rest)]),
            new Ast\PatWild(),
        ),
        $onMatch($value, $rest),
    );

    return new Ast\CaseExpr(
        applyVar('readsPrec', new Ast\IntegerLit($prec), new Ast\Variable($restVar)),
        [$arm, new Ast\Alt(new Ast\PatNil(), new Ast\ListLit([]))],
    );
}

/**
 * A prefix constructor's fields, left to right at `appPrec + 1`.
 *
 * @param list<string> $values the variables bound so far
 */
function readPrefixFields(
    string $ctorName,
    int $count,
    int $index,
    string $restVar,
    array $values,
    string $tag,
): Ast\AstNode {
    if ($index === $count) {
        return new Ast\ListLit([new Ast\Tuple([
            applyCtor($ctorName, array_map(
                static fn (string $name): Ast\AstNode => new Ast\Variable($name),
                $values,
            )),
            new Ast\Variable($restVar),
        ])]);
    }

    return readValue(
        READ_APP_PREC,
        $restVar,
        $tag . 'f' . $index,
        function (string $value, string $rest) use ($ctorName, $count, $index, $values, $tag): Ast\AstNode {
            return readPrefixFields($ctorName, $count, $index + 1, $rest, [...$values, $value], $tag);
        },
    );
}

/**
 * A record constructor's fields: `{f = v, g = w}`, names in declaration order.
 *
 * @param list<Ast\CtorField> $fields
 * @param list<string> $values the variables bound so far
 */
function readRecordFields(
    string $ctorName,
    array $fields,
    int $index,
    string $restVar,
    array $values,
    string $tag,
): Ast\AstNode {
    if ($index === \count($fields)) {
        return readExpectToken('}', $restVar, $tag . 'z', function (string $rest) use ($ctorName, $values): Ast\AstNode {
            return new Ast\ListLit([new Ast\Tuple([
                applyCtor($ctorName, array_map(
                    static fn (string $name): Ast\AstNode => new Ast\Variable($name),
                    $values,
                )),
                new Ast\Variable($rest),
            ])]);
        });
    }

    $label = $fields[$index]->name;

    $fieldStep = function (string $at) use ($ctorName, $fields, $index, $values, $label, $tag): Ast\AstNode {
        return readExpectToken($label, $at, $tag . 'n' . $index, function (string $afterLabel) use ($ctorName, $fields, $index, $values, $tag): Ast\AstNode {
            return readExpectToken('=', $afterLabel, $tag . 'e' . $index, function (string $afterEq) use ($ctorName, $fields, $index, $values, $tag): Ast\AstNode {
                return readValue(0, $afterEq, $tag . 'v' . $index, function (string $value, string $rest) use ($ctorName, $fields, $index, $values, $tag): Ast\AstNode {
                    return readRecordFields($ctorName, $fields, $index + 1, $rest, [...$values, $value], $tag);
                });
            });
        });
    };

    if ($index === 0) {
        return $fieldStep($restVar);
    }

    return readExpectToken(',', $restVar, $tag . 's' . $index, static fn (string $afterComma): Ast\AstNode => $fieldStep($afterComma));
}
