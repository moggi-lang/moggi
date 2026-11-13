<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\Kernel;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TForall;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Deriving\newtypeLayer;
use function Moggi\Semantics\IntrinsicRegistry\isKnownPrimitiveType;

require_once __DIR__ . '/type_core.php';
require_once __DIR__ . '/../deriving/via.php';

/** How a parameter reaches the fields of a declaration. */
enum DataRole: string
{
    /** No constructor field mentions the parameter, so it changes nothing at runtime. */
    case Phantom = 'phantom';

    /** Fields mention the parameter only where the representation is carried through. */
    case Representational = 'representational';

    /** Fields mention the parameter where its identity decides the representation. */
    case Nominal = 'nominal';
}

/** Whether a class is the compiler's representational equality, `Coercible`. */
function isCoercionClass(string $className): bool
{
    return $className === Kernel\coercionClass();
}

/**
 * The role each parameter of a data or newtype declaration plays in its
 * representation.
 *
 * A parameter no constructor field mentions is 'phantom', so changing it changes
 * nothing a value carries. A parameter that reaches a field through transparent
 * positions is 'representational': the field's representation is the argument's,
 * which is what lets a newtype's argument stand for the newtype itself. A
 * parameter under a type family, or under a variable constructor (`f a` for an
 * abstract `f`), is 'nominal': the representation there depends on which type the
 * argument is, not only on its representation, so only an equal argument will do.
 *
 * Inference starts every parameter at phantom and raises each occurrence to the
 * position it was found in. Roles are stored on the declaration, so an importer
 * reads the answer the declaring module computed, and a recursive field (through
 * a synonym, a family or a recursive newtype) terminates on the conservative
 * seed instead of recursing forever.
 *
 * @return array<string, DataRole>
 */
function dataParameterRoles(TypeCheckState $state, string $dataName): array
{
    $info = $state->data[$dataName] ?? null;
    if ($info === null) {
        return [];
    }

    $stored = $info['roles'] ?? null;
    if (\is_array($stored) && $stored !== []) {
        return $stored;
    }

    $params = $info['params'] ?? [];
    $roles = [];
    foreach ($params as $param) {
        $roles[$param] = DataRole::Phantom;
    }

    $state->data[$dataName]['roles'] = $roles;

    foreach ($info['constructors'] ?? [] as $ctor) {
        $fields = $ctor['surfaceFieldTypes'] ?? $ctor['fieldTypes'] ?? [];
        foreach ($fields as $fieldType) {
            collectParameterRoles($state, $fieldType, DataRole::Representational, $roles);
        }
    }

    $state->data[$dataName]['roles'] = $roles;

    return $roles;
}

/**
 * Raise each parameter's role to the position the type places it in.
 *
 * @param array<string, DataRole> $roles
 */
function collectParameterRoles(TypeCheckState $state, Type $type, DataRole $role, array &$roles): void
{
    $type = prune($state, $type);

    if ($type instanceof TForall) {
        collectParameterRoles($state, $type->body, $role, $roles);

        return;
    }

    if ($type instanceof TVar) {
        if (isset($roles[$type->name])) {
            $roles[$type->name] = strongerRole($roles[$type->name], $role);
        }

        return;
    }

    if ($type instanceof TVarApp) {
        if (isset($roles[$type->name])) {
            $roles[$type->name] = strongerRole($roles[$type->name], $role);
        }

        foreach ($type->args as $arg) {
            collectParameterRoles($state, $arg, DataRole::Nominal, $roles);
        }

        return;
    }

    if ($type instanceof TArrow) {
        collectParameterRoles($state, $type->from, $role, $roles);
        collectParameterRoles($state, $type->to, $role, $roles);

        return;
    }

    if ($type instanceof TCon) {
        foreach ($type->args as $i => $arg) {
            $position = composeRole($role, constructorPositionRole($state, $type->name, $i));
            collectParameterRoles($state, $arg, $position, $roles);
        }

        return;
    }

    if ($type instanceof TPromoted) {
        foreach ($type->args as $arg) {
            collectParameterRoles($state, $arg, DataRole::Nominal, $roles);
        }
    }
}

/**
 * The role of an argument of a type constructor the language itself provides.
 *
 * `[]` and the tuple constructors have no declaration to read roles from, yet
 * their parameters *are* their representation: a list or tuple of newtypes has
 * the same representation as the list or tuple of the types they wrap.
 */
function intrinsicParameterRole(string $name): ?DataRole
{
    if ($name === 'List' || \preg_match('/^Tuple\d+$/', $name) === 1) {
        return DataRole::Representational;
    }

    return null;
}

/** The role a type constructor's argument at `$i` plays in its representation. */
function constructorPositionRole(TypeCheckState $state, string $name, int $i): DataRole
{
    $cacheKey = $name . '#' . $i;
    if (isset($state->constructorPositionRoles[$cacheKey])) {
        return $state->constructorPositionRoles[$cacheKey];
    }

    if (isKnownPrimitiveType($name)) {
        return DataRole::Representational;
    }

    if (isset($state->associatedFamilies[$name])) {
        return DataRole::Nominal;
    }

    if (isset($state->typeSynonyms[$name]) && ! empty($state->typeSynonyms[$name]['family'])) {
        return DataRole::Nominal;
    }

    $intrinsicRole = intrinsicParameterRole($name);
    if ($intrinsicRole !== null) {
        return $state->constructorPositionRoles[$cacheKey] = $intrinsicRole;
    }

    $info = $state->data[$name] ?? null;
    if ($info !== null) {
        $params = $info['params'] ?? [];
        $param = $params[$i] ?? null;
        if ($param === null) {
            return $state->constructorPositionRoles[$cacheKey] = DataRole::Nominal;
        }

        return $state->constructorPositionRoles[$cacheKey]
            = dataParameterRoles($state, $name)[$param] ?? DataRole::Nominal;
    }

    return $state->constructorPositionRoles[$cacheKey] = DataRole::Nominal;
}

/** The role that carries more information of the two: nominal over representational over phantom. */
function composeRole(DataRole $outer, DataRole $inner): DataRole
{
    return strongerRole($outer, $inner);
}

function strongerRole(DataRole $left, DataRole $right): DataRole
{
    $rank = static fn (DataRole $role): int => match ($role) {
        DataRole::Phantom => 0,
        DataRole::Representational => 1,
        DataRole::Nominal => 2,
    };

    return $rank($left) >= $rank($right) ? $left : $right;
}

/** The role a written word names, or null when it names none. */
function roleFromWord(string $word): ?DataRole
{
    return match ($word) {
        'phantom' => DataRole::Phantom,
        'representational' => DataRole::Representational,
        'nominal' => DataRole::Nominal,
        default => null,
    };
}

/**
 * A written `type role` declaration, checked against what inference finds and
 * stored on the declaration it describes.
 *
 * Inference is the lower bound: the fields decide how strongly a parameter
 * reaches the representation, and a declaration may only strengthen that. A
 * weaker role would let `coerce` change a parameter whose representation the
 * fields carry, which is the one thing the roles exist to prevent. Roles are
 * stored on the declaration, so `dataParameterRoles` hands the written answer to
 * every reader, and the parameter-position cache is dropped because a stronger
 * role changes what positions are allowed to carry.
 */
function recordDeclaredRoles(TypeCheckState $state, Ast\RoleDecl $decl): void
{
    $info = $state->data[$decl->name] ?? null;
    if ($info === null) {
        throw typeFail($state, "unknown type `{$decl->name}` in role declaration", $decl);
    }

    $declaringModule = $info['declaringModule'] ?? null;
    $here = (string) ($state->currentModule ?? '');
    if (\is_string($declaringModule) && $declaringModule !== ''
        && $here !== '' && $declaringModule !== $here
    ) {
        throw typeFail(
            $state,
            "role declaration for `{$decl->name}` must be in the module declaring the type, not `{$here}`",
            $decl,
        );
    }

    $params = $info['params'] ?? [];
    if (\count($decl->roles) !== \count($params)) {
        throw typeFail(
            $state,
            "role declaration for `{$decl->name}` names " . \count($decl->roles)
                . ' roles and the type has ' . \count($params) . ' parameters',
            $decl,
        );
    }

    $declared = [];
    foreach ($params as $i => $param) {
        $word = $decl->roles[$i];
        $role = roleFromWord($word) ?? throw typeFail(
            $state,
            "unknown role `{$word}`: a role is `phantom`, `representational` or `nominal`",
            $decl,
        );
        $declared[$param] = $role;
    }

    $state->data[$decl->name]['roles'] = [];
    $inferred = dataParameterRoles($state, $decl->name);
    foreach ($params as $param) {
        $needed = $inferred[$param] ?? DataRole::Nominal;
        if (strongerRole($needed, $declared[$param]) !== $declared[$param]) {
            throw typeFail(
                $state,
                "role `{$declared[$param]->value}` for `{$param}` in `{$decl->name}` is weaker than the "
                    . "`{$needed->value}` its fields need",
                $decl,
            );
        }
    }

    $state->data[$decl->name]['roles'] = $declared;
    $state->constructorPositionRoles = [];
}

/**
 * Put written roles on a declared type without checking them again: the module
 * that declared the type checked them, and a module importing it reads the
 * answer that module settled on.
 *
 * @param list<string> $roles one role per parameter, as written
 */
function installDeclaredRoles(TypeCheckState $state, string $name, array $roles): void
{
    $params = $state->data[$name]['params'] ?? null;
    if ($params === null || \count($roles) !== \count($params)) {
        return;
    }

    $installed = [];
    foreach ($roles as $i => $word) {
        $role = roleFromWord($word)
            ?? throw new TypeError("unknown role `{$word}` in `{$name}`");
        $installed[$params[$i]] = $role;
    }

    $state->data[$name]['roles'] = $installed;
    $state->constructorPositionRoles = [];
}

/**
 * Whether two types have the same representation, which is what a `Coercible`
 * obligation claims.
 *
 * The answer follows the declaration of the types involved:
 *
 *  - equal types are equal, and a function is a pair of positions, each compared
 *    on its own;
 *  - a newtype is its representation, so either side may be unwrapped one layer
 *    at a time (this is why `Coercible Age Int` holds for `newtype Age = Age Int`
 *    and why the backends can erase the constructor);
 *  - the same constructor answers per parameter role: a phantom argument is not
 *    looked at, a representational one is compared recursively, and a nominal one
 *    must already be equal;
 *  - anything else differs: two different constructors are generative, and a type
 *    family application has no representation until its equations reduce it.
 */
function representationalEquality(TypeCheckState $state, Type $left, Type $right): bool
{
    $seen = [];

    return representationalEqualityIn($state, $left, $right, $seen);
}

/** @param array<string, true> $seen */
function representationalEqualityIn(TypeCheckState $state, Type $left, Type $right, array &$seen): bool
{
    $left = prune($state, $left);
    $right = prune($state, $right);

    $leftKey = freshenStableTypeKey($left);
    $rightKey = freshenStableTypeKey($right);

    if ($leftKey === $rightKey) {
        return true;
    }

    $pair = $leftKey . '|' . $rightKey;
    if (isset($seen[$pair])) {
        return true;
    }
    $seen[$pair] = true;

    if ($left instanceof TArrow && $right instanceof TArrow) {
        return representationalEqualityIn($state, $left->from, $right->from, $seen)
            && representationalEqualityIn($state, $left->to, $right->to, $seen);
    }

    $unwrapped = newtypeLayer($state, $left);
    if ($unwrapped !== null) {
        return representationalEqualityIn($state, $unwrapped['field'], $right, $seen);
    }

    $unwrapped = newtypeLayer($state, $right);
    if ($unwrapped !== null) {
        return representationalEqualityIn($state, $left, $unwrapped['field'], $seen);
    }

    if (! $left instanceof TCon || ! $right instanceof TCon
        || $left->name !== $right->name
        || \count($left->args) !== \count($right->args)
    ) {
        return false;
    }

    foreach ($left->args as $i => $leftArg) {
        $rightArg = $right->args[$i];
        $role = constructorPositionRole($state, $left->name, $i);

        if ($role === DataRole::Phantom) {
            continue;
        }

        if ($role === DataRole::Nominal) {
            if (freshenStableTypeKey(prune($state, $leftArg)) !== freshenStableTypeKey(prune($state, $rightArg))) {
                return false;
            }

            continue;
        }

        if (! representationalEqualityIn($state, $leftArg, $rightArg, $seen)) {
            return false;
        }
    }

    return true;
}

/**
 * The representational equalities the current givens state, as a graph.
 *
 * The graph depends only on which givens are in scope, and building it keys
 * every given's two types, so it is built once per given set rather than on
 * every question about a pair. `Coercible` is symmetric, so each given is an
 * edge in both directions.
 *
 * @return array<string, array<string, true>>
 */
function coercionAmbientEdges(TypeCheckState $state): array
{
    $fingerprint = ambientEvidenceFingerprint($state);
    if (isset($state->coercionAmbientEdges[$fingerprint])) {
        return $state->coercionAmbientEdges[$fingerprint];
    }

    $bucket = [
        ...$state->gadtAmbientConstraints,
        ...($state->ambientConstraintsByClass[Kernel\coercionClass()] ?? $state->ambientConstraints),
    ];

    $edges = [];
    foreach ($bucket as $ambient) {
        if ($ambient->class !== Kernel\coercionClass() || \count($ambient->args) !== 2) {
            continue;
        }

        $first = freshenStableTypeKey(prune($state, $ambient->args[0]));
        $second = freshenStableTypeKey(prune($state, $ambient->args[1]));

        $edges[$first][$second] = true;
        $edges[$second][$first] = true;
    }

    return $state->coercionAmbientEdges[$fingerprint] = $edges;
}

/**
 * Whether the givens already prove `Coercible left right`.
 *
 * The givens are representational equalities the scope may assume: a matched
 * `Coercion` proves `Coercible a b`, and `Coercible` is symmetric and
 * transitive, so a given proves the pair either way round and a chain of givens
 * proves the pair at its ends. That is what lets `sym` return `Coercible b a`
 * from `Coercible a b` and `trans` join two givens without inventing evidence:
 * a `Coercible` dictionary carries no method of its own, so the proof is the
 * given itself and nothing has to be passed for it.
 */
function coercibleAmbientHolds(TypeCheckState $state, Type $left, Type $right): bool
{
    $leftKey = freshenStableTypeKey(prune($state, $left));
    $rightKey = freshenStableTypeKey(prune($state, $right));

    $bucket = [
        ...$state->gadtAmbientConstraints,
        ...($state->ambientConstraintsByClass[Kernel\coercionClass()] ?? $state->ambientConstraints),
    ];

    foreach ($bucket as $ambient) {
        if ($ambient->class !== Kernel\coercionClass()
            || \count($ambient->args) !== 2
        ) {
            continue;
        }

        $firstArg = $ambient->args[0];
        $secondArg = $ambient->args[1];

        if ((typesMatchForAmbient($state, $firstArg, $left)
                && typesMatchForAmbient($state, $secondArg, $right))
            || (typesMatchForAmbient($state, $firstArg, $right)
                && typesMatchForAmbient($state, $secondArg, $left))
        ) {
            return true;
        }
    }

    $edges = coercionAmbientEdges($state);

    if ($edges === [] || $leftKey === $rightKey) {
        return false;
    }

    $reached = [$leftKey => true];
    $queue = [$leftKey];
    while ($queue !== []) {
        $current = \array_shift($queue);
        foreach (\array_keys($edges[$current] ?? []) as $next) {
            if (isset($reached[$next])) {
                continue;
            }

            if ($next === $rightKey) {
                return true;
            }

            $reached[$next] = true;
            $queue[] = $next;
        }
    }

    return false;
}

/**
 * Solve a parked `Coercible` by taking the flexible variables it still contains
 * from the other side.
 *
 * A `Coercible` obligation parked during inference may mention a metavariable
 * that nothing else fixes (`coerce Nothing :: Maybe TTL` leaves `Nothing`'s
 * element open until the signature says `TTL`). Once the declaration's types are
 * settled, that variable is the one the obligation itself decides, so it is
 * instantiated from the other side — what GHC's solver does with
 * `Coercible alpha ty`. Only positions the two sides share are reached: a
 * variable *constructor* applied to arguments (`f Int`) is not a type
 * application whose parts can be matched, exactly as GHC's decomposition rule
 * requires matching type constructors.
 */
function solveDeferredCoercion(TypeCheckState $state, Ast\PendingConstraint $constraint): bool
{
    if (\count($constraint->args) !== 2) {
        return false;
    }

    if (coercionObligation($state, $constraint) === CoercionObligation::Satisfied) {
        return true;
    }

    if (! unifyCoercibleComponents($state, $constraint->args[0], $constraint->args[1])) {
        return false;
    }

    return coercionObligation($state, $constraint) === CoercionObligation::Satisfied;
}

/**
 * Instantiate the flexible variables two representational-equal types differ in.
 *
 * Positions are compared the way {@see representationalEqualityIn()} compares
 * them — arrows, then type constructors by role — but a position that is a free
 * variable is *taken* from the other side instead of being required equal. A
 * nominal position still has to match exactly: nothing there is a choice.
 */
function unifyCoercibleComponents(TypeCheckState $state, Type $left, Type $right): bool
{
    $left = prune($state, $left);
    $right = prune($state, $right);

    if (freshenStableTypeKey($left) === freshenStableTypeKey($right)) {
        return true;
    }

    foreach ([[$left, $right], [$right, $left]] as [$variable, $other]) {
        if (! $variable instanceof TVar || isset($state->rigidVars[$variable->name])) {
            continue;
        }

        try {
            unify($state, $variable, $other);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    if ($left instanceof TArrow && $right instanceof TArrow) {
        return unifyCoercibleComponents($state, $left->from, $right->from)
            && unifyCoercibleComponents($state, $left->to, $right->to);
    }

    if (! $left instanceof TCon || ! $right instanceof TCon
        || $left->name !== $right->name
        || \count($left->args) !== \count($right->args)
    ) {
        return false;
    }

    foreach ($left->args as $i => $leftArg) {
        $rightArg = $right->args[$i];
        $role = constructorPositionRole($state, $left->name, $i);

        if ($role === DataRole::Phantom) {
            continue;
        }

        if ($role === DataRole::Nominal) {
            if (freshenStableTypeKey(prune($state, $leftArg)) !== freshenStableTypeKey(prune($state, $rightArg))) {
                return false;
            }

            continue;
        }

        if (! unifyCoercibleComponents($state, $leftArg, $rightArg)) {
            return false;
        }
    }

    return true;
}

/**
 * Discharge the `Coercible` obligations left parked on a settled expression.
 *
 * Inference parks an obligation while its types are open; the passes that
 * elaborate evidence then either supply it or leave it for this check. A node
 * whose outstanding obligations are all erased (`Coercible` carries no
 * dictionary) is settled here by {@see solveDeferredCoercion()}, so an
 * obligation whose variable the enclosing declaration fixes afterwards is not
 * reported as a representation mismatch.
 */
function solveDeferredCoercionsInExpr(TypeCheckState $state, Ast\AstNode $expr): void
{
    walkAstValues($expr, static function (Ast\AstNode $node) use ($state): void {
        $pending = pendingConstraintsOf($state, $node);
        if ($pending === []) {
            return;
        }

        foreach ($pending as $constraint) {
            if (! constraintIsErased($constraint)) {
                return;
            }
        }

        foreach ($pending as $constraint) {
            if (! solveDeferredCoercion($state, $constraint)) {
                return;
            }
        }

        clearPendingConstraints($state, $node);
    });
}

/** How a `Coercible` obligation stands at the moment it is asked about. */
enum CoercionObligation: string
{
    /** The givens prove it, or the types' representations agree. */
    case Satisfied = 'satisfied';

    /** A variable is still flexible: nothing about the pair is decided yet. */
    case Undecided = 'undecided';

    /** Both types are known and their representations differ. */
    case Impossible = 'impossible';
}

function coercionObligation(TypeCheckState $state, Ast\PendingConstraint $constraint): CoercionObligation
{
    if (\count($constraint->args) !== 2) {
        return CoercionObligation::Impossible;
    }

    $left = prune($state, $constraint->args[0]);
    $right = prune($state, $constraint->args[1]);

    if (coercibleAmbientHolds($state, $left, $right)) {
        return CoercionObligation::Satisfied;
    }

    if (representationalEquality($state, $left, $right)) {
        return CoercionObligation::Satisfied;
    }

    if (constraintHeadHasFlexibleVar($state, $left)
        || constraintHeadHasFlexibleVar($state, $right)
    ) {
        return CoercionObligation::Undecided;
    }

    return CoercionObligation::Impossible;
}

/**
 * The message a failed coercion reports.
 *
 * The two types are named as written, because the point of the error is which
 * representation was asked for and why it is not there.
 *
 * @param list<Type> $args
 */
function coercionMessage(TypeCheckState $state, array $args): string
{
    $left = isset($args[0]) ? prune($state, $args[0]) : null;
    $right = isset($args[1]) ? prune($state, $args[1]) : null;
    $rename = friendlyTypeVarNames(\array_values(\array_filter([$left, $right])));

    return 'cannot coerce `' . ($left === null ? '?' : typeToStringArgument($left, $rename))
        . '` to `' . ($right === null ? '?' : typeToStringArgument($right, $rename))
        . '`: their representations differ';
}

/**
 * Whether a constraint costs a dictionary at run time.
 *
 * `Coercible` is proved from the roles of the types involved, and its dictionary
 * is empty, so the obligation is checked where it is used and then erased: no
 * parameter carries it, no argument passes it, and an erased constraint is never
 * a dictionary the backends can look at.
 */
function constraintIsErased(Ast\PendingConstraint $constraint): bool
{
    return isCoercionClass($constraint->class);
}

/**
 * The ambient `Coercible` givens a lookup's answer depends on, as one key.
 *
 * An instance whose context mentions `Coercible` is answered from the givens of
 * the body being checked, so the same head can resolve one way in one body and
 * another way in the next. Naming the givens lets the instance lookup cache
 * keep an entry per body instead of every body walking the whole context again.
 */
function ambientCoercionFingerprint(TypeCheckState $state): string
{
    $bucket = [
        ...$state->gadtAmbientConstraints,
        ...($state->ambientConstraintsByClass[Kernel\coercionClass()] ?? $state->ambientConstraints),
    ];

    $parts = [];
    foreach ($bucket as $ambient) {
        if ($ambient->class !== Kernel\coercionClass() || \count($ambient->args) !== 2) {
            continue;
        }

        $parts[] = freshenStableTypeKey(prune($state, $ambient->args[0]))
            . '~' . freshenStableTypeKey(prune($state, $ambient->args[1]));
    }

    return \implode('|', $parts);
}

/**
 * The constraints of a list that a definition takes a dictionary for, in order.
 *
 * Every list a definition's parameters and a call site's arguments are
 * projected from goes through here, so the two sides keep the same length and
 * order whether or not the list mentions an erased constraint.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return list<Ast\PendingConstraint>
 */
function dictionaryConstraints(array $constraints): array
{
    return \array_values(\array_filter(
        $constraints,
        static fn (Ast\PendingConstraint $constraint): bool => ! constraintIsErased($constraint),
    ));
}

/**
 * Refuse a `Coercible` instance written outside the class's own declaration.
 *
 * Representational equality is not something a module can add an instance for:
 * it is decided from the roles of the types involved, and an instance would let
 * a program claim a coercion the compiler did not prove. The one instance the
 * class has is declared with it and stands for every pair the rule accepts.
 */
function assertCoercionInstanceAllowed(TypeCheckState $state, Ast\InstanceDecl $decl): void
{
    if (! isCoercionClass($decl->class)) {
        return;
    }

    $declaringModule = $state->classes[$decl->class]['module'] ?? '';
    if ($declaringModule !== '' && $declaringModule === ($state->currentModule ?? '')) {
        return;
    }

    throw typeFail(
        $state,
        'an instance for `' . Kernel\coercionClass() . '` cannot be declared: '
            . 'representational equality is decided from the roles of the types',
        $decl,
    );
}
