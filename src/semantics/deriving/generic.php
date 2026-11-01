<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Kernel\boolFalseCtor;
use function Moggi\Semantics\Kernel\boolTrueCtor;
use function Moggi\Semantics\Types\typeFail;
use function Moggi\Syntax\isConstructorOperator;

/**
 * The module a derived `MetaData` names.
 *
 * `MetaData`'s second field is the declaring module, which a program can read at runtime
 * (`moduleName`). A check that does not know which module it is checking cannot state it, and
 * spelling `"Main"` instead is a wrong answer that looks right — the one failure mode a derived
 * instance is worst at, because nothing downstream can tell it from a real one. So it is refused.
 */
function declaringModule(TypeCheckState $state, Ast\AstNode $at): string
{
    return $state->currentModule ?? throw typeFail(
        $state,
        'deriving `Generic` needs the module the declaration is checked in: `MetaData` names it',
        $at,
    );
}

/**
 * Stock `deriving Generic`: synthesize `instance Generic …` with
 * `type Rep … = …`, `from`, and `to`. Only emits representation equations and
 * conversions — no special-casing of M1 / :+: / K1 in the type checker.
 */
function deriveGeneric(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    foreach ($decl->constructors as $ctor) {
        if ($ctor->isGadt()) {
            throw typeFail(
                $state,
                "cannot derive Generic: constructor `{$ctor->name}` is GADT-style, "
                    . 'which has no single representation over the type parameter',
                $ref,
            );
        }
    }

    return new DerivedInstance(
        'Generic',
        dataDeclHeadAst($decl),
        [],
        [
            synthesizeGenericFrom($decl, $ref),
            synthesizeGenericTo($decl, $ref),
        ],
        $ref,
        strategy: 'Stock',
        associatedEquations: [
            representationEquation($state, $decl, $ref, 'Generic', 'Rep'),
        ],
        contributesMetadata: true,
    );
}

/**
 * Stock `deriving Generic1`: the same representation over the decl's last type parameter, with the
 * parameter marked `Par1` and the declaration's own recursion marked `Rec1`.
 *
 * The instance head drops the abstracted parameter (`data Pair a b` gets `Generic1 (Pair a)`), which
 * is what makes the class's `f` the declaration applied to its leading parameters.
 */
function deriveGeneric1(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    foreach ($decl->constructors as $ctor) {
        if ($ctor->isGadt()) {
            throw typeFail(
                $state,
                "cannot derive Generic1: constructor `{$ctor->name}` is GADT-style, "
                    . 'which has no single representation over the type parameter',
                $ref,
            );
        }
    }

    $param = lastDataParamName($decl);
    if ($param === null) {
        throw typeFail(
            $state,
            "cannot derive Generic1: `{$decl->name}` has no type parameter to abstract over, "
                . 'and the class expects a type constructor of kind `Type -> Type`',
            $ref,
        );
    }

    foreach ($decl->constructors as $ctor) {
        foreach (constructorDeriveFields($ctor) as $field) {
            $desc = rep1OfType($decl, $param, $field->type);
            if ($desc['tag'] === 'bad') {
                throw typeFail(
                    $state,
                    "cannot derive Generic1: field type `" . Ast\dumpTypeInline($field->type)
                        . "` of `{$ctor->name}` is not representable ({$desc['reason']})",
                    $ref,
                );
            }
        }
    }

    return new DerivedInstance(
        'Generic1',
        functorialHeadAst($decl),
        [],
        [
            synthesizeGeneric1From($decl, $param, $ref),
            synthesizeGeneric1To($decl, $param, $ref),
        ],
        $ref,
        strategy: 'Stock',
        associatedEquations: [
            representationEquation($state, $decl, $ref, 'Generic1', 'Rep1'),
        ],
        contributesMetadata: true,
    );
}

/**
 * How one field contributes to a `Rep1`.
 *
 * - `param`: the field *is* the abstracted parameter -- `Par1`.
 * - `self`: the field is the datatype applied to its own parameters -- `Rec1`.
 * - `const`: the parameter does not occur in it, so it is a constant -- `Rec0`.
 * - `compose`: the parameter is the last argument of another type constructor (`Maybe a`, `[Rose
 *   a]`), which the representation composes -- `Rec1 Maybe :.: Par1`.
 * - `bad`: the parameter is somewhere the representation cannot put it -- a non-final argument, or
 *   under a type *variable* (`f a`), where composing would need a `Functor f` the derived instance
 *   cannot name.
 *
 * @return array{tag: 'param'|'self'|'const'|'compose'|'bad', type?: Ast\TypeNode, head?: Ast\TypeNode, inner?: array{tag: string}, reason?: string}
 */
function rep1OfType(Ast\DataDecl $decl, string $param, Ast\TypeNode $type): array
{
    if (! typeMentionsParam($type, $param)) {
        return ['tag' => 'const', 'type' => $type];
    }

    if ($type instanceof Ast\TypeVar && $type->name === $param) {
        return ['tag' => 'param'];
    }

    if (typeIsSelfAtParams($type, $decl)) {
        return ['tag' => 'self'];
    }

    if (! $type instanceof Ast\TypeApp || $type->args === []) {
        return [
            'tag' => 'bad',
            'type' => $type,
            'reason' => 'the parameter is not the last argument of a type application',
        ];
    }

    $leading = \array_slice($type->args, 0, -1);
    foreach ($leading as $arg) {
        if (typeMentionsParam($arg, $param)) {
            return [
                'tag' => 'bad',
                'type' => $type,
                'reason' => 'the parameter occurs in a non-final argument',
            ];
        }
    }

    if ($type->con instanceof Ast\TypeVar) {
        return [
            'tag' => 'bad',
            'type' => $type,
            'reason' => 'the parameter is under a type variable, which would need a Functor '
                . 'the representation cannot name',
        ];
    }

    $inner = rep1OfType($decl, $param, $type->args[\count($type->args) - 1]);
    if ($inner['tag'] === 'bad') {
        return $inner;
    }

    return [
        'tag' => 'compose',
        'type' => $type,
        'head' => $leading === [] ? $type->con : new Ast\TypeApp($type->con, $leading),
        'inner' => $inner,
    ];
}

/** Whether `$type` is the declaration applied to exactly its own parameters (`Tree a`). */
function typeIsSelfAtParams(Ast\TypeNode $type, Ast\DataDecl $decl): bool
{
    if (! $type instanceof Ast\TypeApp) {
        return false;
    }

    if (! $type->con instanceof Ast\TypeCon || $type->con->name !== $decl->name) {
        return false;
    }

    if (\count($type->args) !== \count($decl->params)) {
        return false;
    }

    foreach ($decl->params as $i => $typeParam) {
        $arg = $type->args[$i];
        if (! $arg instanceof Ast\TypeVar || $arg->name !== $typeParam->name) {
            return false;
        }
    }

    return true;
}

/**
 * The `type <family> … = …` equation a derived representation contributes.
 *
 * `Generic` states it at the declaration's head over its whole parameter list. `Generic1` states
 * it at the head abstracted over the last parameter, which is what makes `Rep1 f` a
 * `Type -> Type` type constructor. One place decides both, so the checked instance and the
 * project-instance stub another module reads cannot disagree about the shape.
 *
 * @return array{lhsArgs: list<Ast\TypeNode>, rhs: Ast\TypeNode}
 */
function representationEquationParts(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    string $className,
): array {
    if ($className === 'Generic1') {
        // A declaration with no parameter has nothing to abstract over, which the check refuses;
        // the stub phase still records the declaration's own head so it never has to invent one.
        return [
            'lhsArgs' => [$decl->params === [] ? dataDeclHeadAst($decl) : functorialHeadAst($decl)],
            'rhs' => synthesizeRep1Type($state, $decl),
        ];
    }

    return [
        'lhsArgs' => [dataDeclHeadAst($decl)],
        'rhs' => synthesizeRepType($state, $decl),
    ];
}

function representationEquation(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
    string $className,
    string $family,
): Ast\AssociatedTypeEquation {
    $parts = representationEquationParts($state, $decl, $className);

    return new Ast\AssociatedTypeEquation(
        $family,
        $parts['lhsArgs'],
        $parts['rhs'],
        $ref->line,
        $ref->col,
        $ref->endCol,
    );
}

function synthesizeRepType(TypeCheckState $state, Ast\DataDecl $decl): Ast\TypeNode
{
    $meta = metaDataType($state, $decl);
    $body = synthesizeRepSum($decl);

    return typeApp('D1', [$meta, $body]);
}

function synthesizeRep1Type(TypeCheckState $state, Ast\DataDecl $decl): Ast\TypeNode
{
    $param = lastDataParamName($decl) ?? '';

    return typeApp('D1', [metaDataType($state, $decl), synthesizeRep1Sum($decl, $param)]);
}

function synthesizeRep1Sum(Ast\DataDecl $decl, string $param): Ast\TypeNode
{
    if ($decl->constructors === []) {
        return new Ast\TypeCon('V1');
    }

    $parts = [];
    foreach ($decl->constructors as $ctor) {
        $parts[] = synthesizeRep1Constructor($decl, $param, $ctor);
    }

    return foldInfixType(':+:', $parts);
}

function synthesizeRep1Constructor(
    Ast\DataDecl $decl,
    string $param,
    Ast\ConstructorDecl $ctor,
): Ast\TypeNode {
    $fields = [];
    foreach (constructorDeriveFields($ctor) as $field) {
        $fields[] = typeApp('S1', [metaSelType($field), rep1TypeOfField($decl, $param, $field->type)]);
    }

    $product = $fields === [] ? new Ast\TypeCon('U1') : foldInfixType(':*:', $fields);

    return typeApp('C1', [metaConsType($ctor), $product]);
}

/** The representation of one field: `Par1`, `Rec1 <self>`, `Rec0 <type>`, or a composition. */
function rep1TypeOfField(Ast\DataDecl $decl, string $param, Ast\TypeNode $fieldType): Ast\TypeNode
{
    return rep1TypeOf($decl, rep1OfType($decl, $param, $fieldType));
}

/** @param array{tag: string, type?: Ast\TypeNode, head?: Ast\TypeNode, inner?: array{tag: string}} $desc */
function rep1TypeOf(Ast\DataDecl $decl, array $desc): Ast\TypeNode
{
    return match ($desc['tag']) {
        'param' => new Ast\TypeCon('Par1'),
        'self' => typeApp('Rec1', [functorialHeadAst($decl)]),
        'compose' => new Ast\TypeApp(new Ast\TypeCon(':.:'), [
            typeApp('Rec1', [$desc['head']]),
            rep1TypeOf($decl, $desc['inner']),
        ]),
        default => typeApp('Rec0', [$desc['type']]),
    };
}

/**
 * A field value as its representation, and the inverse a `to1` binds.
 *
 * The four simple forms wrap a value (`Par1`, `Rec1`, `K1`) and read it back through a pattern.
 * A composition is the one form that is not a wrapper: the container is `map`ped, because the
 * representation lives *inside* it, so there is no way to get from `Maybe a` to
 * `Maybe (Par1 a)` without going through the constructor's own `Functor`.
 *
 * @param array{tag: string, head?: Ast\TypeNode, inner?: array{tag: string}} $desc
 */
function rep1ValueOfField(Ast\DataDecl $decl, array $desc, Ast\AstNode $value): Ast\AstNode
{
    return match ($desc['tag']) {
        'param' => applyCon('Par1', [$value]),
        'self' => applyCon('Rec1', [$value]),
        'compose' => applyCon('Comp1', [
            applyCon('Rec1', [applyVar('map', rep1Lambda($decl, $desc['inner'], false), $value)]),
        ]),
        default => applyCon('K1', [$value]),
    };
}

/**
 * A top-level field, whose pattern already unwrapped `Par1`/`Rec1`/`K1` into a plain binder.
 *
 * A composed field is the exception: its pattern bound the container of representations, so the
 * inner one has to be mapped over and unwrapped.
 *
 * @param array{tag: string, inner?: array{tag: string}} $desc
 */
function rep1FieldOfValue(Ast\DataDecl $decl, array $desc, Ast\AstNode $rep): Ast\AstNode
{
    return $desc['tag'] === 'compose'
        ? applyVar('map', rep1Lambda($decl, $desc['inner'], true), $rep)
        : $rep;
}

/**
 * A representation value as the field value it stands for.
 *
 * This is the inverse of `rep1ValueOfField`, and it is a `case` rather than a projection: the
 * wrapper is a constructor, so reading through it is a match. Inside a composition the inverse is
 * mapped, since the representations are the container's elements.
 *
 * @param array{tag: string, inner?: array{tag: string}} $desc
 */
function rep1FromRep(Ast\DataDecl $decl, array $desc, Ast\AstNode $rep): Ast\AstNode
{
    if ($desc['tag'] === 'compose') {
        return applyVar('map', rep1Lambda($decl, $desc['inner'], true), $rep);
    }

    $wrapper = match ($desc['tag']) {
        'param' => 'Par1',
        'self' => 'Rec1',
        default => 'K1',
    };

    return new Ast\CaseExpr($rep, [
        new Ast\Alt(
            new Ast\PatCon($wrapper, [new Ast\PatVar('__v')]),
            new Ast\Variable('__v'),
        ),
    ]);
}

/** `\__g -> <__g as the inner representation>` (or its inverse). */
function rep1Lambda(Ast\DataDecl $decl, array $desc, bool $fromRep): Ast\AstNode
{
    $var = new Ast\Variable('__g');

    return new Ast\Lambda(
        [new Ast\LambdaParam(new Ast\PatVar('__g'))],
        $fromRep
            ? rep1FromRep($decl, $desc, $var)
            : rep1ValueOfField($decl, $desc, $var),
    );
}

function synthesizeGeneric1From(
    Ast\DataDecl $decl,
    string $param,
    Ast\DerivingClassRef $ref,
): Ast\FunctionDecl {
    return new Ast\FunctionDecl(
        'from1',
        null,
        [new Ast\PatVar('__x')],
        synthesizeFrom1Body($decl, $param, '__x'),
        instanceMethod: true,
        line: $ref->line,
        col: $ref->col,
        endCol: $ref->endCol,
    );
}

function synthesizeGeneric1To(
    Ast\DataDecl $decl,
    string $param,
    Ast\DerivingClassRef $ref,
): Ast\FunctionDecl {
    return new Ast\FunctionDecl(
        'to1',
        null,
        [new Ast\PatVar('__r')],
        synthesizeTo1Body($decl, $param, '__r'),
        instanceMethod: true,
        line: $ref->line,
        col: $ref->col,
        endCol: $ref->endCol,
    );
}

function synthesizeFrom1Body(Ast\DataDecl $decl, string $param, string $scrutinee): Ast\AstNode
{
    if ($decl->constructors === []) {
        return new Ast\Apply(
            new Ast\Variable('from1'),
            new Ast\Variable($scrutinee),
        );
    }

    $n = \count($decl->constructors);
    $alts = [];
    foreach ($decl->constructors as $i => $ctor) {
        $binders = [];
        $patArgs = [];
        foreach (constructorDeriveFields($ctor) as $j => $_) {
            $name = '__f' . $j;
            $binders[] = $name;
            $patArgs[] = new Ast\PatVar($name);
        }
        $rep = injectSum($n, $i, m1(synthesizeFrom1Fields($decl, $param, $ctor, $binders)));
        $alts[] = new Ast\Alt(new Ast\PatCon($ctor->name, $patArgs), m1($rep));
    }

    return new Ast\CaseExpr(new Ast\Variable($scrutinee), $alts);
}

/** @param list<string> $binders */
function synthesizeFrom1Fields(
    Ast\DataDecl $decl,
    string $param,
    Ast\ConstructorDecl $ctor,
    array $binders,
): Ast\AstNode {
    if ($binders === []) {
        return new Ast\ConstructorRef('U1');
    }

    $parts = [];
    foreach (constructorDeriveFields($ctor) as $j => $field) {
        $desc = rep1OfType($decl, $param, $field->type);
        $parts[] = m1(rep1ValueOfField($decl, $desc, new Ast\Variable($binders[$j])));
    }

    return foldInfixExpr(':*:', $parts);
}

function synthesizeTo1Body(Ast\DataDecl $decl, string $param, string $scrutinee): Ast\AstNode
{
    if ($decl->constructors === []) {
        return new Ast\Apply(
            new Ast\Variable('to1'),
            new Ast\Variable($scrutinee),
        );
    }

    $n = \count($decl->constructors);
    $alts = [];
    foreach ($decl->constructors as $i => $ctor) {
        $binders = [];
        $descs = [];
        foreach (constructorDeriveFields($ctor) as $j => $field) {
            $binders[] = '__t' . $j;
            $descs[] = rep1OfType($decl, $param, $field->type);
        }
        $innerPat = synthesizeTo1FieldsPat($descs, $binders);
        $sumPat = injectSumPat($n, $i, new Ast\PatCon('M1', [$innerPat]));
        $outerPat = new Ast\PatCon('M1', [$sumPat]);

        $conArgs = [];
        foreach ($descs as $j => $desc) {
            $conArgs[] = rep1FieldOfValue($decl, $desc, new Ast\Variable($binders[$j]));
        }
        $alts[] = new Ast\Alt($outerPat, applyConstructor($ctor->name, $conArgs));
    }

    return new Ast\CaseExpr(new Ast\Variable($scrutinee), $alts);
}

/** @param list<array{tag: string}> $descs @param list<string> $binders */
function synthesizeTo1FieldsPat(array $descs, array $binders): Ast\AstNode
{
    if ($binders === []) {
        return new Ast\PatCon('U1', []);
    }

    $parts = [];
    foreach ($descs as $j => $desc) {
        $binder = new Ast\PatVar($binders[$j]);
        $parts[] = match ($desc['tag']) {
            'param' => new Ast\PatCon('M1', [new Ast\PatCon('Par1', [$binder])]),
            'self' => new Ast\PatCon('M1', [new Ast\PatCon('Rec1', [$binder])]),
            'compose' => new Ast\PatCon('M1', [
                new Ast\PatCon('Comp1', [new Ast\PatCon('Rec1', [$binder])]),
            ]),
            default => new Ast\PatCon('M1', [new Ast\PatCon('K1', [$binder])]),
        };
    }

    return foldInfixPat(':*:', $parts);
}

function synthesizeRepSum(Ast\DataDecl $decl): Ast\TypeNode
{
    if ($decl->constructors === []) {
        return new Ast\TypeCon('V1');
    }

    $parts = [];
    foreach ($decl->constructors as $ctor) {
        $parts[] = synthesizeRepConstructor($ctor);
    }

    return foldInfixType(':+:', $parts);
}

function synthesizeRepConstructor(Ast\ConstructorDecl $ctor): Ast\TypeNode
{
    $meta = metaConsType($ctor);
    $fields = synthesizeRepProduct($ctor);

    return typeApp('C1', [$meta, $fields]);
}

function synthesizeRepProduct(Ast\ConstructorDecl $ctor): Ast\TypeNode
{
    if ($ctor->fields === []) {
        return new Ast\TypeCon('U1');
    }

    $parts = [];
    foreach ($ctor->fields as $field) {
        $sel = metaSelType($field);
        $rec = typeApp('Rec0', [$field->type]);
        $parts[] = typeApp('S1', [$sel, $rec]);
    }

    return foldInfixType(':*:', $parts);
}

/**
 * @param list<Ast\TypeNode> $parts
 */
function foldInfixType(string $op, array $parts): Ast\TypeNode
{
    if ($parts === []) {
        return new Ast\TypeCon('U1');
    }

    $acc = $parts[\count($parts) - 1];
    for ($i = \count($parts) - 2; $i >= 0; --$i) {
        $acc = new Ast\TypeApp(new Ast\TypeCon($op), [$parts[$i], $acc]);
    }

    return $acc;
}

/** @param list<Ast\TypeNode> $args */
function typeApp(string $name, array $args): Ast\TypeNode
{
    if ($args === []) {
        return new Ast\TypeCon($name);
    }

    return new Ast\TypeApp(new Ast\TypeCon($name), $args);
}

function metaDataType(TypeCheckState $state, Ast\DataDecl $decl): Ast\TypeNode
{
    $module = declaringModule($state, $decl);
    $isNewtype = $decl->isNewtype
        ? new Ast\TypePromoted(boolTrueCtor())
        : new Ast\TypePromoted(boolFalseCtor());

    return typeApp('MetaData', [
        new Ast\TypeStringLit($decl->name),
        new Ast\TypeStringLit($module),
        new Ast\TypeStringLit('moggi'),
        $isNewtype,
    ]);
}

function metaConsType(Ast\ConstructorDecl $ctor): Ast\TypeNode
{
    $fixity = metaConsFixityType($ctor);
    $isRecord = constructorIsRecord($ctor)
        ? new Ast\TypePromoted(boolTrueCtor())
        : new Ast\TypePromoted(boolFalseCtor());

    return typeApp('MetaCons', [
        new Ast\TypeStringLit($ctor->name),
        $fixity,
        $isRecord,
    ]);
}

function metaConsFixityType(Ast\ConstructorDecl $ctor): Ast\TypeNode
{
    if (! $ctor->declaredInfix) {
        return new Ast\TypePromoted('PrefixI');
    }

    $assoc = match ($ctor->fixityAssoc) {
        'infixr' => 'RightAssociative',
        'infix' => 'NotAssociative',
        default => 'LeftAssociative', // infixl, or default infixl 9
    };

    return new Ast\TypeApp(
        new Ast\TypePromoted('InfixI'),
        [new Ast\TypePromoted($assoc)],
    );
}

function metaSelType(Ast\CtorField $field): Ast\TypeNode
{
    $name = $field->name !== ''
        ? typeApp('Just', [new Ast\TypeStringLit($field->name)])
        : new Ast\TypeCon('Nothing');

    return typeApp('MetaSel', [
        $name,
        new Ast\TypePromoted('NoSourceUnpackedness'),
        new Ast\TypePromoted('NoSourceStrictness'),
        new Ast\TypePromoted('DecidedLazy'),
    ]);
}

function constructorIsRecord(Ast\ConstructorDecl $ctor): bool
{
    if ($ctor->fields === []) {
        return false;
    }
    foreach ($ctor->fields as $field) {
        if ($field->name === '') {
            return false;
        }
    }

    return true;
}

function synthesizeGenericFrom(Ast\DataDecl $decl, Ast\DerivingClassRef $ref): Ast\FunctionDecl
{
    return new Ast\FunctionDecl(
        'from',
        null,
        [new Ast\PatVar('__x')],
        synthesizeFromBody($decl, '__x'),
        instanceMethod: true,
        line: $ref->line,
        col: $ref->col,
        endCol: $ref->endCol,
    );
}

function synthesizeGenericTo(Ast\DataDecl $decl, Ast\DerivingClassRef $ref): Ast\FunctionDecl
{
    return new Ast\FunctionDecl(
        'to',
        null,
        [new Ast\PatVar('__r')],
        synthesizeToBody($decl, '__r'),
        instanceMethod: true,
        line: $ref->line,
        col: $ref->col,
        endCol: $ref->endCol,
    );
}

function synthesizeFromBody(Ast\DataDecl $decl, string $scrutinee): Ast\AstNode
{
    if ($decl->constructors === []) {
        return new Ast\Apply(
            new Ast\Variable('from'),
            new Ast\Variable($scrutinee),
        );
    }

    $n = \count($decl->constructors);
    $alts = [];
    foreach ($decl->constructors as $i => $ctor) {
        $binders = [];
        $patArgs = [];
        foreach ($ctor->fields as $j => $_) {
            $name = '__f' . $j;
            $binders[] = $name;
            $patArgs[] = new Ast\PatVar($name);
        }
        $rep = injectSum($n, $i, m1(synthesizeFromFields($ctor, $binders)));
        $alts[] = new Ast\Alt(new Ast\PatCon($ctor->name, $patArgs), m1($rep));
    }

    return new Ast\CaseExpr(new Ast\Variable($scrutinee), $alts);
}

/**
 * @param list<string> $binders
 */
function synthesizeFromFields(Ast\ConstructorDecl $ctor, array $binders): Ast\AstNode
{
    if ($binders === []) {
        return new Ast\ConstructorRef('U1');
    }

    $parts = [];
    foreach ($binders as $name) {
        $parts[] = m1(k1(new Ast\Variable($name)));
    }

    return foldInfixExpr(':*:', $parts);
}

function synthesizeToBody(Ast\DataDecl $decl, string $scrutinee): Ast\AstNode
{
    if ($decl->constructors === []) {
        return new Ast\Apply(
            new Ast\Variable('to'),
            new Ast\Variable($scrutinee),
        );
    }

    $n = \count($decl->constructors);
    $alts = [];
    foreach ($decl->constructors as $i => $ctor) {
        $binders = [];
        foreach ($ctor->fields as $j => $_) {
            $binders[] = '__t' . $j;
        }
        $innerPat = synthesizeToFieldsPat($ctor, $binders);
        $sumPat = injectSumPat($n, $i, new Ast\PatCon('M1', [$innerPat]));
        $outerPat = new Ast\PatCon('M1', [$sumPat]);

        $conArgs = [];
        foreach ($binders as $b) {
            $conArgs[] = new Ast\Variable($b);
        }
        $body = applyConstructor($ctor->name, $conArgs);
        $alts[] = new Ast\Alt($outerPat, $body);
    }

    return new Ast\CaseExpr(new Ast\Variable($scrutinee), $alts);
}

/**
 * @param list<string> $binders
 */
function synthesizeToFieldsPat(Ast\ConstructorDecl $ctor, array $binders): Ast\AstNode
{
    if ($binders === []) {
        return new Ast\PatCon('U1', []);
    }

    $parts = [];
    foreach ($binders as $name) {
        $parts[] = new Ast\PatCon('M1', [new Ast\PatCon('K1', [new Ast\PatVar($name)])]);
    }

    return foldInfixPat(':*:', $parts);
}

/**
 * Wrap value in L1/R1 chain for constructor index $i of $n (right-nested :+:).
 *
 * Right-nested encoding: C0 :+: (C1 :+: (C2 :+: C3))
 * - index 0 → L1 v
 * - index 1 → R1 (L1 v)
 * - index n-1 → R1 (R1 (… v))
 */
function injectSum(int $n, int $i, Ast\AstNode $value): Ast\AstNode
{
    if ($n === 1) {
        return $value;
    }

    if ($i === $n - 1) {
        $expr = $value;
        for ($k = 0; $k < $n - 1; ++$k) {
            $expr = applyCon('R1', [$expr]);
        }

        return $expr;
    }

    $expr = applyCon('L1', [$value]);
    for ($k = 0; $k < $i; ++$k) {
        $expr = applyCon('R1', [$expr]);
    }

    return $expr;
}

function injectSumPat(int $n, int $i, Ast\AstNode $valuePat): Ast\AstNode
{
    if ($n === 1) {
        return $valuePat;
    }

    if ($i === $n - 1) {
        $pat = $valuePat;
        for ($k = 0; $k < $n - 1; ++$k) {
            $pat = new Ast\PatCon('R1', [$pat]);
        }

        return $pat;
    }

    $pat = new Ast\PatCon('L1', [$valuePat]);
    for ($k = 0; $k < $i; ++$k) {
        $pat = new Ast\PatCon('R1', [$pat]);
    }

    return $pat;
}

/** @param list<Ast\AstNode> $parts */
function foldInfixExpr(string $op, array $parts): Ast\AstNode
{
    if ($parts === []) {
        return new Ast\ConstructorRef('U1');
    }

    $acc = $parts[\count($parts) - 1];
    for ($i = \count($parts) - 2; $i >= 0; --$i) {
        $acc = new Ast\Infix($op, $parts[$i], $acc);
    }

    return $acc;
}

/** @param list<Ast\AstNode> $parts */
function foldInfixPat(string $op, array $parts): Ast\AstNode
{
    if ($parts === []) {
        return new Ast\PatCon('U1', []);
    }

    $acc = $parts[\count($parts) - 1];
    for ($i = \count($parts) - 2; $i >= 0; --$i) {
        $acc = new Ast\PatCon($op, [$parts[$i], $acc]);
    }

    return $acc;
}

function m1(Ast\AstNode $inner): Ast\AstNode
{
    return applyCon('M1', [$inner]);
}

function k1(Ast\AstNode $inner): Ast\AstNode
{
    return applyCon('K1', [$inner]);
}

/** @param list<Ast\AstNode> $args */
function applyCon(string $name, array $args): Ast\AstNode
{
    $expr = new Ast\ConstructorRef($name);
    foreach ($args as $arg) {
        $expr = new Ast\Apply($expr, $arg);
    }

    return $expr;
}

/** @param list<Ast\AstNode> $args */
function applyConstructor(string $name, array $args): Ast\AstNode
{
    if (isConstructorOperator($name) && \count($args) === 2) {
        return new Ast\Infix($name, $args[0], $args[1]);
    }

    return applyCon($name, $args);
}

/**
 * Datatype / Constructor / Selector instances for every Meta* in a derived Rep.
 * Bakes Symbol / Bool metadata into method bodies (stock demotion).
 *
 * @return list<DerivedInstance>
 */
function deriveGenericMetadataInstances(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): array {
    $out = [];
    $out[] = deriveDatatypeInstance($state, $decl, $ref);

    $seenCons = [];
    $seenSels = [];
    foreach ($decl->constructors as $ctor) {
        $consKey = $ctor->name . "\0" . (constructorIsRecord($ctor) ? '1' : '0');
        if (! isset($seenCons[$consKey])) {
            $seenCons[$consKey] = true;
            $out[] = deriveConstructorInstance($ctor, $ref);
        }
        foreach ($ctor->fields as $field) {
            $selKey = $field->name;
            if (isset($seenSels[$selKey])) {
                continue;
            }
            $seenSels[$selKey] = true;
            $out[] = deriveSelectorInstance($field, $ref);
        }
    }

    return $out;
}

function deriveDatatypeInstance(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $meta = metaDataType($state, $decl);
    $ignored = new Ast\PatVar('_d');
    $isNt = $decl->isNewtype ? boolTrueCtor() : boolFalseCtor();
    $isAllNullary = datatypeIsAllNullary($decl) ? boolTrueCtor() : boolFalseCtor();
    $isSum = \count($decl->constructors) > 1 ? boolTrueCtor() : boolFalseCtor();

    return new DerivedInstance(
        'Datatype',
        $meta,
        [],
        [
            metaStringMethod('datatypeName', $ignored, $decl->name, $ref),
            metaStringMethod('moduleName', $ignored, declaringModule($state, $ref), $ref),
            metaStringMethod('packageName', $ignored, 'moggi', $ref),
            new Ast\FunctionDecl(
                'isNewtype',
                null,
                [$ignored],
                new Ast\ConstructorRef($isNt),
                instanceMethod: true,
                line: $ref->line,
                col: $ref->col,
                endCol: $ref->endCol,
            ),
            new Ast\FunctionDecl(
                'isAllNullary',
                null,
                [$ignored],
                new Ast\ConstructorRef($isAllNullary),
                instanceMethod: true,
                line: $ref->line,
                col: $ref->col,
                endCol: $ref->endCol,
            ),
            new Ast\FunctionDecl(
                'isSum',
                null,
                [$ignored],
                new Ast\ConstructorRef($isSum),
                instanceMethod: true,
                line: $ref->line,
                col: $ref->col,
                endCol: $ref->endCol,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

function datatypeIsAllNullary(Ast\DataDecl $decl): bool
{
    if ($decl->constructors === []) {
        return false;
    }
    foreach ($decl->constructors as $ctor) {
        if ($ctor->fields !== []) {
            return false;
        }
    }

    return true;
}

function deriveConstructorInstance(
    Ast\ConstructorDecl $ctor,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $meta = metaConsType($ctor);
    $ignored = new Ast\PatVar('_c');
    $isRec = constructorIsRecord($ctor) ? boolTrueCtor() : boolFalseCtor();
    $arity = \count($ctor->fields);

    return new DerivedInstance(
        'Constructor',
        $meta,
        [],
        [
            metaStringMethod('conName', $ignored, $ctor->name, $ref),
            new Ast\FunctionDecl(
                'conIsRecord',
                null,
                [$ignored],
                new Ast\ConstructorRef($isRec),
                instanceMethod: true,
                line: $ref->line,
                col: $ref->col,
                endCol: $ref->endCol,
            ),
            new Ast\FunctionDecl(
                'conArity',
                null,
                [$ignored],
                new Ast\IntegerLit($arity),
                instanceMethod: true,
                line: $ref->line,
                col: $ref->col,
                endCol: $ref->endCol,
            ),
        ],
        $ref,
        strategy: 'Stock',
    );
}

function deriveSelectorInstance(
    Ast\CtorField $field,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $meta = metaSelType($field);
    $ignored = new Ast\PatVar('_s');

    return new DerivedInstance(
        'Selector',
        $meta,
        [],
        [
            metaStringMethod('selName', $ignored, $field->name, $ref),
        ],
        $ref,
        strategy: 'Stock',
    );
}

function metaStringMethod(
    string $name,
    Ast\PatVar $param,
    string $value,
    Ast\DerivingClassRef $ref,
): Ast\FunctionDecl {
    return new Ast\FunctionDecl(
        $name,
        null,
        [$param],
        new Ast\StringLit($value),
        instanceMethod: true,
        line: $ref->line,
        col: $ref->col,
        endCol: $ref->endCol,
    );
}
