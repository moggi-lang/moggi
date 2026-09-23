<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeCheckState;

use function Moggi\Syntax\isConstructorOperator;

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
    $head = dataDeclHeadAst($decl);
    $repRhs = synthesizeRepType($state, $decl);
    $repArgs = [$head];

    return new DerivedInstance(
        'Generic',
        $head,
        [],
        [
            synthesizeGenericFrom($decl, $ref),
            synthesizeGenericTo($decl, $ref),
        ],
        $ref,
        strategy: 'Stock',
        associatedEquations: [
            new Ast\AssociatedTypeEquation(
                'Rep',
                $repArgs,
                $repRhs,
                $ref->line,
                $ref->col,
                $ref->endCol,
            ),
        ],
    );
}

function synthesizeRepType(TypeCheckState $state, Ast\DataDecl $decl): Ast\TypeNode
{
    $meta = metaDataType($state, $decl);
    $body = synthesizeRepSum($decl);
    return typeApp('D1', [$meta, $body]);
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

    $acc = $parts[count($parts) - 1];
    for ($i = count($parts) - 2; $i >= 0; --$i) {
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
    $module = $state->currentModule ?? 'Main';
    $isNewtype = $decl->isNewtype
        ? new Ast\TypePromoted('True')
        : new Ast\TypePromoted('False');

    return typeApp('MetaData', [
        new Ast\TypeStringLit($decl->name),
        new Ast\TypeStringLit($module),
        new Ast\TypeStringLit('moggi'),
        $isNewtype,
    ]);
}

function metaConsType(Ast\ConstructorDecl $ctor): Ast\TypeNode
{
    // The `FixityI` metadata carries associativity (and precedence via
    // a separate Fixity form). Moggi's FixityI is PrefixI | InfixI Associativity.
    $fixity = metaConsFixityType($ctor);
    $isRecord = constructorIsRecord($ctor)
        ? new Ast\TypePromoted('True')
        : new Ast\TypePromoted('False');

    return typeApp('MetaCons', [
        new Ast\TypeStringLit($ctor->name),
        $fixity,
        $isRecord,
    ]);
}

function metaConsFixityType(Ast\ConstructorDecl $ctor): Ast\TypeNode
{
    // `(:|) a [a]` is a prefix declaration even though the name is an operator,
    // so only `a :| [a]` is an infix `MetaCons`.
    if (!$ctor->declaredInfix) {
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
        // Uninhabited datatype: wildcard is exhaustive; result is also uninhabited
        // (Rep = D1 … V1). Looping `from` is a total inhabitant of an empty type.
        return new Ast\Apply(
            new Ast\Variable('from'),
            new Ast\Variable($scrutinee),
        );
    }

    $n = count($decl->constructors);
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

    $n = count($decl->constructors);
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

    $acc = $parts[count($parts) - 1];
    for ($i = count($parts) - 2; $i >= 0; --$i) {
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

    $acc = $parts[count($parts) - 1];
    for ($i = count($parts) - 2; $i >= 0; --$i) {
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
    if (isConstructorOperator($name) && count($args) === 2) {
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
        if (!isset($seenCons[$consKey])) {
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
    $isNt = $decl->isNewtype ? 'True' : 'False';
    $isAllNullary = datatypeIsAllNullary($decl) ? 'True' : 'False';
    $isSum = count($decl->constructors) > 1 ? 'True' : 'False';

    return new DerivedInstance(
        'Datatype',
        $meta,
        [],
        [
            metaStringMethod('datatypeName', $ignored, $decl->name, $ref),
            metaStringMethod('moduleName', $ignored, $state->currentModule ?? 'Main', $ref),
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
    $isRec = constructorIsRecord($ctor) ? 'True' : 'False';
    $arity = count($ctor->fields);

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
