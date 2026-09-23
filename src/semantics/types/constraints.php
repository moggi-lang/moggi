<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Ast\AstNode;

use function Moggi\Errors\appendDidYouMean;
use function Moggi\Semantics\TypeExpr\scheme;

require_once __DIR__ . '/type_core.php';

/**
 * Instantiate one declared instance-context argument at a wanted instance head.
 *
 * The instance's own head variables are what a context constraint is written
 * in terms of (`Eq a` on `Eq (Pair a)`, used at `Pair t` needs `Eq t`), and they
 * bind *inside* the argument, not only when the argument is a bare variable.
 * The class-parameter mapping is deliberately not consulted: for a
 * single-parameter class it names the whole head, so `Pair a` would come back as
 * `Pair (Pair t)` and the instance would appear to require itself.
 *
 * @param array<string, Type> $headVars
 */
function instantiateContextArg(TypeCheckState $state, Ast\AstNode $arg, array $headVars): Type
{
    if ($headVars !== []) {
        $arg = substituteInstanceTypeAst($arg, $headVars);
    }

    return prune($state, astType($state, $arg));
}

/**
 * Rewrite `$expr` in place into an {@see Ast\EvidenceMethod} for a resolved
 * class-method reference
 *
 * @param array{class: string, method: string, evidence: string, type?: Type, constraintHead?: Type, implicit?: bool, instanceHeadAst?: Ast\TypeNode, contextEvidence?: list<AstNode>} $info
 */
function applyEvidenceMethodInfo(TypeCheckState $state, Ast\AstNode &$expr, array $info): void
{
    $methodType = $info['type'] ?? ($state->classes[$info['class']]['methods'][$info['method']]['type'] ?? null);
    $methodNullary = $methodType !== null
        && !(prune($state, $methodType) instanceof TArrow);

    $method = new Ast\EvidenceMethod(
        $info['class'],
        $info['method'],
        $info['evidence'],
        $methodNullary,
        ($info['implicit'] ?? false) ? ($info['instanceHeadAst'] ?? null) : null,
        $info['contextEvidence'] ?? [],
        $expr->line,
        $expr->col,
        $expr->endCol,
    );
    $method->pendingConstraints = $expr->pendingConstraints;
    $method->inferredType = $expr->inferredType;
    $expr = $method;
}

/**
 * @return array{0: list<Ast\TypeNode>, 1: Ast\TypeNode}
 */
function splitTypeAst(Ast\TypeNode $type): array
{
    if ($type instanceof Ast\TypeConstrained) {
        return [$type->constraints, $type->body];
    }

    return [[], $type];
}

/**
 * @param list<Ast\TypeNode> $constraints
 * @return list<Ast\PendingConstraint>
 */
function parseConstraints(TypeCheckState $state, array $constraints, ?AstNode $at = null): array
{
    $parsed = [];
    foreach ($constraints as $i => $constraint) {
        if (!$constraint instanceof Ast\TypeApp || !$constraint->con instanceof Ast\TypeCon) {
            throw typeFail($state, 'constraint must be a class application', $at);
        }

        $className = $constraint->con->name;
        if (!isset($state->classes[$className])) {
            throw typeFail(
                $state,
                appendDidYouMean(
                    "unknown class `{$className}` in constraint",
                    $className,
                    \array_keys($state->classes),
                ),
                $at,
            );
        }

        $classInfo = $state->classes[$className];
        assertConstraintArity($state, $className, $classInfo, $constraint, $at);
        $savedVarKinds = [];
        foreach ($classInfo['params'] as $j => $param) {
            $savedVarKinds[$param['name']] = $state->varKinds[$param['name']] ?? null;
            $state->varKinds[$param['name']] = Kinds\classParamKind($state, $param, $at);
        }

        $args = [];
        foreach ($classInfo['params'] as $j => $param) {
            $argAst = $constraint->args[$j] ?? throw typeFail($state, "class `{$className}` applied to too few arguments", $at);
            $args[] = astType($state, $argAst);
        }

        foreach ($savedVarKinds as $name => $kind) {
            if ($kind === null) {
                unset($state->varKinds[$name]);
                continue;
            }

            $state->varKinds[$name] = $kind;
        }

        $parsed[] = new Ast\PendingConstraint(
            $className,
            $args,
            evidenceParamName($className, $i),
        );
    }

    return $parsed;
}

/**
 * @param array<string, mixed> $classInfo
 */
function assertConstraintArity(
    TypeCheckState $state,
    string $className,
    array $classInfo,
    Ast\TypeApp $constraint,
    ?AstNode $at = null,
): void {
    $expected = count($classInfo['params']);
    $actual = count($constraint->args);
    if ($actual < $expected) {
        throw typeFail($state, "class `{$className}` applied to too few arguments", $at ?? $constraint);
    }

    if ($actual > $expected) {
        throw typeFail($state, "class `{$className}` applied to too many arguments", $at ?? $constraint);
    }
}

/**
 * @param list<Ast\PendingConstraint> $constraints
 * @return list<Ast\PendingConstraint>
 */
function expandConstraintsWithSuperclasses(TypeCheckState $state, array $constraints): array
{
    $expanded = [];

    // `seen` guards superclass diamonds within one constraint's expansion and is reset
    // per constraint: two constraints are two dictionary parameters.
    foreach ($constraints as $constraint) {
        $seen = [];
        $add = function (Ast\PendingConstraint $constraint) use ($state, &$expanded, &$seen, &$add): void {
            $key = $constraint->class . ':' . constraintArgsKey($constraint->args);
            if (isset($seen[$key])) {
                return;
            }

            $classInfo = $state->classes[$constraint->class]
                ?? throw typeFail($state, "unknown class `{$constraint->class}` in constraint");

            // Keep higher-kinded parameters as TVar (or concrete TCon heads): the TCon('f')
            // encoding does not prune through subst and breaks instance lookup.
            $mapping = [];
            foreach ($classInfo['params'] as $i => $param) {
                $mapping[$param['name']] = prune($state, $constraint->args[$i]);
            }

            foreach ($classInfo['superclasses'] as $super) {
                if (!$super instanceof Ast\TypeApp || !$super->con instanceof Ast\TypeCon) {
                    continue;
                }

                $add(constraintFromSuperclassAst($state, $super, $mapping));
            }

            $seen[$key] = true;
            $expanded[] = $constraint;
        };

        $add($constraint);
    }

    // Rebuild rather than assign in place: callers keep their own list (e.g. the
    // unexpanded user constraints) and must not see the renamed evidence.
    foreach ($expanded as $i => $constraint) {
        $expanded[$i] = new Ast\PendingConstraint(
            $constraint->class,
            $constraint->args,
            evidenceParamName($constraint->class, $i),
            $constraint->implicit,
            $constraint->instanceHeadAst,
        );
    }

    return $expanded;
}

/** @param list<Type> $args */
function constraintArgsKey(array $args): string
{
    return join(',', \array_map(constraintArgKey(...), $args));
}

function constraintArgKey(Type $arg): string
{
    if ($arg instanceof TVar) {
        return 'v:' . $arg->name;
    }

    if ($arg instanceof TCon) {
        return 'c:' . $arg->name . '[' . constraintArgsKey($arg->args) . ']';
    }

    if ($arg instanceof TUnit) {
        return '()';
    }

    if ($arg instanceof TPromoted) {
        return "'" . $arg->name . '[' . constraintArgsKey($arg->args) . ']';
    }

    return typeToString($arg);
}

/**
 * @param array<string, Type> $mapping
 */
function constraintFromSuperclassAst(TypeCheckState $state, Ast\TypeApp $super, array $mapping): Ast\PendingConstraint
{
    $className = $super->con->name;
    $classInfo = $state->classes[$className]
        ?? throw typeFail($state, "unknown class `{$className}` in superclass constraint");

    $substituted = substituteInstanceTypeAst($super, $mapping);
    if (!$substituted instanceof Ast\TypeApp) {
        throw typeFail($state, "class `{$className}` superclass did not stay an application");
    }

    $args = [];
    foreach ($classInfo['params'] as $j => $param) {
        $argAst = $substituted->args[$j]
            ?? throw typeFail($state, "class `{$className}` applied to too few arguments");
        $args[] = astType($state, $argAst);
    }

    return new Ast\PendingConstraint($className, $args);
}

/** @param list<Ast\PendingConstraint> $constraints */
function applyConstraintVarKinds(TypeCheckState $state, array $constraints): void
{
    foreach ($constraints as $constraint) {
        $classInfo = $state->classes[$constraint->class] ?? null;
        if ($classInfo === null) {
            continue;
        }

        foreach ($classInfo['params'] as $i => $param) {
            $arg = $constraint->args[$i] ?? null;
            if (!$arg instanceof TVar) {
                continue;
            }

            $state->varKinds[$arg->name] = Kinds\classParamKind($state, $param);
        }
    }
}

function evidenceParamName(string $className, int $index): string
{
    return $index === 0 ? "__ev_{$className}" : "__ev_{$className}_{$index}";
}

/**
 * @param list<Ast\PendingConstraint> $constraints
 * @return array<string, Scheme>
 */
function buildConstraintEnv(TypeCheckState $state, array $constraints): array
{
    $env = [];
    $owners = [];
    foreach ($constraints as $constraint) {
        $className = $constraint->class;
        $classInfo = $state->classes[$className] ?? throw typeFail($state, "unknown class `{$className}` in constraint");
        $mapping = [];
        foreach ($classInfo['params'] as $i => $param) {
            $mapping[$param['name']] = constructorMappingValue(
                $state,
                $param,
                $constraint->args[$i],
            );
        }

        foreach ($classInfo['methods'] as $methodName => $methodInfo) {
            $mappedType = prune($state, substitute($methodInfo['type'], $mapping));
            // Nullary methods must remain polymorphic class methods so
            // `maxBound` / `mempty = maxBound` can select a different head.
            if (!$mappedType instanceof TArrow) {
                continue;
            }

            $owner = $className . "\0" . $constraint->evidence;
            if (isset($owners[$methodName]) && $owners[$methodName] !== $owner) {
                unset($env[$methodName]);
                continue;
            }

            $owners[$methodName] = $owner;
            $env[$methodName] = scheme(
                prune($state, freshenTypeVars($state, $mappedType)),
                [],
            );
        }
    }

    return $env;
}

/**
 * @param list<Ast\PendingConstraint> $constraints
 * @return list<AstNode>
 */
function resolveConstraintEvidence(TypeCheckState $state, array $constraints, ?AstNode $at = null): array
{
    $exprs = [];
    foreach ($constraints as $constraint) {
        $className = $constraint->class;
        $classInfo = $state->classes[$className] ?? throw typeFail($state, "unknown class `{$className}`", $at);

        // A given/context dictionary wins over a global instance (see
        // `tryResolveEvidenceExprs`): instance contexts such as `Eq (Pair a)`
        // from `deriving via` must be forwarded, not replaced by a fresh lookup.
        $ambient = ambientEvidenceFor($state, $constraint);
        if ($ambient !== null) {
            $exprs[] = new Ast\Variable($ambient);
            continue;
        }

        $instanceHead = instanceHeadFromConstraintArgs($state, $classInfo['params'], $constraint->args, $at);

        if (!findProjectInstance($state, $className, $instanceHead)) {
            throw typeFail(
                $state,
                'missing instance for `' . $className . ' ' . typeToString($instanceHead) . '`',
                $at,
            );
        }

        $exprs[] = new Ast\EvidenceRef(
            $className,
            findProjectInstanceHeadAst($state, $className, $instanceHead),
            resolveProjectInstanceContextEvidence($state, $className, $instanceHead, $at),
        );
    }

    return $exprs;
}

/**
 * Find the evidence parameter of an in-scope ambient constraint that matches
 * the given (still-polymorphic) constraint, so calls to constrained functions
 * can forward the enclosing dictionary instead of requiring a concrete instance.
 *
 * @param Ast\PendingConstraint $constraint
 */
function ambientEvidenceFor(TypeCheckState $state, Ast\PendingConstraint $constraint): ?string
{
    $bucket = $state->ambientConstraintsByClass[$constraint->class]
        ?? $state->ambientConstraints;

    foreach ($bucket as $ambient) {
        if ($ambient->class !== $constraint->class) {
            continue;
        }

        if (count($ambient->args) !== count($constraint->args)) {
            continue;
        }

        $match = true;
        foreach ($ambient->args as $i => $ambientArg) {
            if (!typesMatchForAmbient($state, $ambientArg, $constraint->args[$i])) {
                $match = false;
                break;
            }
        }

        if ($match && $ambient->evidence !== '') {
            return $ambient->evidence;
        }
    }

    return null;
}

/** Structural equality for ambient constraint args — avoids typeToString on hot path. */
function typesMatchForAmbient(TypeCheckState $state, Type $left, Type $right): bool
{
    $left = prune($state, $left);
    $right = prune($state, $right);

    if ($left instanceof TVar && $right instanceof TVar) {
        return $left->name === $right->name;
    }

    if ($left instanceof TCon && $right instanceof TCon) {
        if ($left->name !== $right->name || count($left->args) !== count($right->args)) {
            return false;
        }
        foreach ($left->args as $i => $arg) {
            if (!typesMatchForAmbient($state, $arg, $right->args[$i])) {
                return false;
            }
        }

        return true;
    }

    if ($left instanceof TUnit && $right instanceof TUnit) {
        return true;
    }

    if ($left instanceof TPromoted && $right instanceof TPromoted) {
        if ($left->name !== $right->name || count($left->args) !== count($right->args)) {
            return false;
        }
        foreach ($left->args as $i => $arg) {
            if (!typesMatchForAmbient($state, $arg, $right->args[$i])) {
                return false;
            }
        }

        return true;
    }

    return false;
}

/**
 * Resolve a constraint list into evidence expressions, using a concrete project
 * instance where the head is known and otherwise forwarding an ambient evidence
 * parameter. Returns null if any constraint cannot be resolved either way.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return list<AstNode>|null
 */
function tryResolveEvidenceExprs(
    TypeCheckState $state,
    array $constraints,
    ?Ast\AstNode $at = null,
): ?array {
    $exprs = [];
    foreach ($constraints as $constraint) {
        $classInfo = $state->classes[$constraint->class] ?? null;
        if ($classInfo === null) {
            return null;
        }

        try {
            $head = instanceHeadFromConstraintArgs($state, $classInfo['params'], $constraint->args);
        } catch (TypeError) {
            return null;
        }

        $head = prune($state, $head);

        // An in-scope given/context dictionary wins over a global instance,
        // including when the constraint head is an applied type (`Eq (Pair a)`):
        // `deriving via` puts the via type's instance in the instance context.
        $ambient = ambientEvidenceFor($state, $constraint);
        if ($ambient !== null) {
            $exprs[] = new Ast\Variable($ambient);
            continue;
        }

        if ($head instanceof TVar) {
            return null;
        }

        if (!findProjectInstance($state, $constraint->class, $head)) {
            return null;
        }

        $exprs[] = new Ast\EvidenceRef(
            $constraint->class,
            findProjectInstanceHeadAst($state, $constraint->class, $head),
            resolveProjectInstanceContextEvidence($state, $constraint->class, $head, $at),
        );
    }

    return $exprs;
}

/**
 * A class and its superclasses, superclasses first, in the order
 * {@see expandConstraintsWithSuperclasses} emits them.
 *
 * @return list<string>
 */
function superclassChainNames(TypeCheckState $state, string $class): array
{
    $names = [];
    $visit = function (string $name) use ($state, &$names, &$visit): void {
        if (isset($names[$name]) || !isset($state->classes[$name])) {
            return;
        }

        foreach ($state->classes[$name]['superclasses'] as $super) {
            if ($super instanceof Ast\TypeApp && $super->con instanceof Ast\TypeCon) {
                $visit($super->con->name);
            }
        }

        $names[$name] = $name;
    };
    $visit($class);

    return \array_values($names);
}

/**
 * Indexes of the owning class's own dictionary group in `$evidence`, i.e. the
 * class and its superclasses at the instance head. A class-method scheme lists
 * the owning class last, so the group is the trailing run; these dictionaries
 * are only for projection inside the method body and are not parameters of the
 * method, while a method-local constraint's expansion (`Applicative f` giving
 * `Functor f`) is. Matching the run rather than the class name is what keeps
 * `traverse`'s `Functor f` when `f` unifies with the instance head.
 *
 * @param list<AstNode> $evidence
 * @return array<int, true>
 */
function ownerEvidenceIndexes(TypeCheckState $state, array $evidence, Ast\EvidenceRef $ev): array
{
    $group = superclassChainNames($state, $ev->class);
    $size = \count($group);
    $count = \count($evidence);
    if ($size === 0 || $size > $count) {
        return [];
    }

    $ownHead = canonicalTypeKey($ev->head);
    $start = $count - $size;
    for ($i = 0; $i < $size; $i++) {
        $entry = $evidence[$start + $i];
        if (
            !($entry instanceof Ast\EvidenceRef)
            || $entry->class !== $group[$i]
            || canonicalTypeKey($entry->head) !== $ownHead
        ) {
            return [];
        }
    }

    $indexes = [];
    for ($i = $start; $i < $count; $i++) {
        $indexes[$i] = true;
    }

    return $indexes;
}

/**
 * Prepend evidence dictionaries onto a call/value expression.
 *
 * Class-method references become {@see Ast\EvidenceMethod} (dictionary
 * projection). Ordinary constrained functions keep leading `Apply`s of dicts.
 *
 * @param list<AstNode> $evidence
 */
function prependEvidenceToCall(TypeCheckState $state, Ast\AstNode $expr, array $evidence): Ast\AstNode
{
    if (
        $expr instanceof Ast\Variable
        || $expr instanceof Ast\OperatorRef
        || $expr instanceof Ast\QualifiedRef
    ) {
        // Superclass dictionaries may precede the owning class in `$evidence`
        // (e.g. Functor before Applicative for `pure`). Project the method from
        // the owning class's evidence, not blindly from `$evidence[0]`.
        foreach ($evidence as $ev) {
            if (
                $ev instanceof Ast\EvidenceRef
                && isClassMethodName($state, $ev->class, $expr->name)
            ) {
                $methodExpr = $expr;
                applyEvidenceMethodInfo($state, $methodExpr, [
                    'class' => $ev->class,
                    'method' => $expr->name,
                    'evidence' => '',
                    'implicit' => true,
                    'instanceHeadAst' => $ev->head,
                    'contextEvidence' => $ev->context,
                ]);

                // Method-local constraints stay ordinary leading dictionary arguments; the owning
                // class's group is only for projection.
                $ownerIndexes = ownerEvidenceIndexes($state, $evidence, $ev);
                foreach ($evidence as $i => $other) {
                    if (isset($ownerIndexes[$i]) || $other === $ev) {
                        continue;
                    }
                    $methodExpr = new Ast\Apply($methodExpr, $other);
                }

                return $methodExpr;
            }

            // Ambient dictionary parameter: project the method from it instead of leaving a bare
            // `Variable` IR cannot lower (and uniquify would rewrite into a self-reference).
            if (
                $ev instanceof Ast\Variable
                && ($className = classNameForAmbientEvidence($state, $ev->name)) !== null
                && isClassMethodName($state, $className, $expr->name)
            ) {
                $methodExpr = $expr;
                applyEvidenceMethodInfo($state, $methodExpr, [
                    'class' => $className,
                    'method' => $expr->name,
                    'evidence' => $ev->name,
                    'implicit' => false,
                ]);

                foreach ($evidence as $other) {
                    if ($other === $ev) {
                        continue;
                    }
                    if (
                        $other instanceof Ast\EvidenceRef
                        && (
                            $other->class === $className
                            || isSuperclassNameOf($state, $other->class, $className)
                        )
                    ) {
                        continue;
                    }
                    if (
                        $other instanceof Ast\Variable
                        && ($otherClass = classNameForAmbientEvidence($state, $other->name)) !== null
                        && (
                            $otherClass === $className
                            || isSuperclassNameOf($state, $otherClass, $className)
                        )
                    ) {
                        continue;
                    }
                    $methodExpr = new Ast\Apply($methodExpr, $other);
                }

                return $methodExpr;
            }
        }

        $fn = $expr;
        foreach ($evidence as $ev) {
            $fn = new Ast\Apply($fn, $ev);
        }

        return $fn;
    }

    // Method-local constraints on an already-projected EvidenceMethod
    // (e.g. Applicative on Traversable.traverse) are ordinary leading dict args.
    if ($expr instanceof Ast\EvidenceMethod) {
        $fn = $expr;
        foreach ($evidence as $ev) {
            $fn = new Ast\Apply($fn, $ev);
        }

        return $fn;
    }

    if (!$expr instanceof Ast\Apply) {
        throw new \InvalidArgumentException('prependEvidenceToCall expects a call or reference, got ' . $expr::class);
    }

    $fn = $expr->function;
    foreach ($evidence as $ev) {
        $fn = new Ast\Apply($fn, $ev);
    }

    return new Ast\Apply($fn, $expr->argument, $expr->line, $expr->col, $expr->endCol);
}

function isClassMethodName(TypeCheckState $state, string $className, string $methodName): bool
{
    return isset($state->classes[$className]['methods'][$methodName]);
}

/**
 * Map an ambient evidence parameter name (`__ev_Bounded`, `__ev_Eq_1`) back to
 * its class, preferring an in-scope ambient constraint match.
 */
function classNameForAmbientEvidence(TypeCheckState $state, string $evidenceName): ?string
{
    foreach ($state->ambientConstraints as $ambient) {
        if ($ambient->evidence === $evidenceName) {
            return $ambient->class;
        }
    }

    if (!\str_starts_with($evidenceName, '__ev_')) {
        return null;
    }

    $rest = \substr($evidenceName, 5);
    if (isset($state->classes[$rest])) {
        return $rest;
    }

    if (\preg_match('/^(.+)_(\d+)$/', $rest, $m) === 1 && isset($state->classes[$m[1]])) {
        return $m[1];
    }

    return null;
}

/**
 * Dictionaries that a project instance needs from its context
 * (`Eq Int` + `Ord Int` when selecting `Ord (Min Int)`).
 *
 * @return list<AstNode>
 */
function resolveProjectInstanceContextEvidence(
    TypeCheckState $state,
    string $className,
    Type|Ast\TypeNode $head,
    ?Ast\AstNode $at = null,
): array {
    $headType = $head instanceof Type ? prune($state, $head) : prune($state, astType($state, $head));
    $match = findProjectInstanceRecord($state, $className, $headType);
    if ($match === null) {
        return [];
    }

    /** @var array<string, Type> $headVars */
    $headVars = $match['mapping']['__headVars'] ?? [];
    $raw = [];
    foreach ($match['instance']['constraints'] ?? [] as $constraintAst) {
        if (!$constraintAst instanceof Ast\TypeApp || !$constraintAst->con instanceof Ast\TypeCon) {
            return [];
        }

        $ctxClass = $constraintAst->con->name;
        $args = [];
        foreach ($constraintAst->args as $arg) {
            $args[] = instantiateContextArg($state, $arg, $headVars);
        }
        $raw[] = [
            'constraint' => new Ast\PendingConstraint($ctxClass, $args, ''),
            // Kept for the diagnostic below: the declared context names its own
            // variables (`Show a`), which reads better and does not move when
            // the instantiated type variable is renamed.
            'declared' => $constraintAst,
        ];
    }

    $exprs = [];
    // Expand each instance-context constraint independently. Deduping across
    // the whole list collapses `C f, C g` when f≡g after
    // substitution, under-applying the evidence factory (arity mismatch).
    // Superclass expansion still runs per constraint so `Ord a` yields Eq+Ord.
    foreach ($raw as $rawEntry) {
        foreach (expandConstraintsWithSuperclasses($state, [$rawEntry['constraint']]) as $constraint) {
            $ctxHead = count($constraint->args) === 1
                ? prune($state, $constraint->args[0])
                : new TCon('__InstanceHead', \array_map(
                    static fn (Type $arg): Type => prune($state, $arg),
                    $constraint->args,
                ));

            // A given/context dictionary wins over a global instance here as it
            // does at every other constraint site. Inside a `deriving via`
            // instance the candidate's own context supplies exactly the via
            // type's dictionary, which is what makes the derived method bodies
            // (and the recursive reference they make to the dictionary being
            // built) resolve.
            $ambient = ambientEvidenceFor($state, $constraint);
            if ($ambient !== null) {
                $exprs[] = new Ast\Variable($ambient);
                continue;
            }

            if ($ctxHead instanceof TVar) {
                // The instance context is headed by a type variable that
                // nothing determines (`show []` reaches `Show a` through
                // `Show [a]`), so no dictionary can be selected. Returning
                // an empty context left the evidence factory partially
                // applied: it compiled and then failed on the dictionary
                // lookup at run time, so this is an error here.
                throw typeFail(
                    $state,
                    'ambiguous constraint `' . $constraint->class . ' '
                        . (isset($rawEntry['declared']->args[0])
                            ? Ast\dumpTypeInline($rawEntry['declared']->args[0])
                            : typeToString($ctxHead))
                        . '`; add a type signature',
                    $at,
                );
            }

            if (!findProjectInstance($state, $constraint->class, $ctxHead)) {
                return [];
            }

            $exprs[] = new Ast\EvidenceRef(
                $constraint->class,
                findProjectInstanceHeadAst($state, $constraint->class, $ctxHead),
                resolveProjectInstanceContextEvidence($state, $constraint->class, $ctxHead, $at),
            );
        }
    }

    return $exprs;
}

/** True when `$maybeSuper` is a superclass (directly or transitively) of `$className`. */
function isSuperclassNameOf(TypeCheckState $state, string $maybeSuper, string $className): bool
{
    $classInfo = $state->classes[$className] ?? null;
    if ($classInfo === null) {
        return false;
    }

    foreach ($classInfo['superclasses'] as $super) {
        if (!$super instanceof Ast\TypeApp || !$super->con instanceof Ast\TypeCon) {
            continue;
        }
        $superName = $super->con->name;
        if ($superName === $maybeSuper || isSuperclassNameOf($state, $maybeSuper, $superName)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<AstNode> $evidence
 */
function prependEvidenceAtRoot(TypeCheckState $state, Ast\AstNode $expr, array $evidence): Ast\AstNode
{
    if ($expr instanceof Ast\Apply) {
        // Dictionaries already at the head of this call are not handed over again: a call
        // site is rewritten in place and a body may be inferred more than once.
        $evidence = \array_values(\array_filter(
            $evidence,
            static fn (Ast\AstNode $ev): bool => !\in_array(evidenceNodeKey($ev), appliedEvidenceKeys($expr), true),
        ));
        if ($evidence === []) {
            return $expr;
        }

        $expr->function = prependEvidenceAtRoot($state, $expr->function, $evidence);

        return $expr;
    }

    return prependEvidenceToCall($state, $expr, $evidence);
}

/**
 * A stable key for a dictionary expression, used to recognise one that a call
 * site already carries.
 */
function evidenceNodeKey(Ast\AstNode $ev): string
{
    return match ($ev::class) {
        Ast\EvidenceRef::class => 'ref:' . $ev->class . ':' . Ast\dumpTypeInline($ev->head),
        Ast\Variable::class => 'var:' . $ev->name,
        Ast\EvidenceMethod::class => 'method:' . $ev->class . ':' . $ev->method,
        default => 'other:' . $ev::class,
    };
}

/**
 * The keys of the dictionaries a call already hands to its callee: the leading
 * arguments of the application spine, up to the first real argument.
 *
 * @return list<string>
 */
function appliedEvidenceKeys(Ast\Apply $expr): array
{
    $keys = [];
    $args = [];
    $cursor = $expr;
    while ($cursor instanceof Ast\Apply) {
        $args[] = $cursor->argument;
        $cursor = $cursor->function;
    }

    foreach (\array_reverse($args) as $arg) {
        if (! $arg instanceof Ast\EvidenceRef
            && ! $arg instanceof Ast\EvidenceMethod
            && ! ($arg instanceof Ast\Variable && \str_starts_with($arg->name, '__ev_'))
        ) {
            break;
        }

        $keys[] = evidenceNodeKey($arg);
    }

    return $keys;
}

/**
 * @return list<Ast\PendingConstraint>
 */
function pendingConstraintsFromExpr(Ast\AstNode $expr): array
{
    if ($expr->pendingConstraints !== []) {
        return $expr->pendingConstraints;
    }

    if ($expr instanceof Ast\Apply) {
        return pendingConstraintsFromExpr($expr->function);
    }

    return [];
}

function clearPendingConstraints(Ast\AstNode $expr): void
{
    $expr->pendingConstraints = [];
    if ($expr instanceof Ast\Apply) {
        clearPendingConstraints($expr->function);
    }
}

/**
 * Every pending constraint anywhere in an expression, deduplicated by class and
 * arguments, in first-seen order.
 *
 * The shallow {@see pendingConstraintsFromExpr} is enough for a local binding,
 * whose value is the constrained call itself, but a function body can carry
 * constraints in any sub-expression (`f x = if p then show x else "-"`), and
 * each of those has to become a dictionary parameter.
 *
 * With a state, constraints are keyed by the type their arguments have been
 * resolved to: two literals unified with the same variable are one `Num`
 * obligation, not two dictionary parameters.
 *
 * @return list<Ast\PendingConstraint>
 */
function pendingConstraintsDeep(Ast\AstNode $expr, ?TypeCheckState $state = null): array
{
    $found = [];
    $seen = [];
    $visit = static function (Ast\AstNode $node) use (&$found, &$seen, $state): void {
        foreach ($node->pendingConstraints as $constraint) {
            $args = $state === null
                ? $constraint->args
                : \array_map(static fn (Type $arg): Type => prune($state, $arg), $constraint->args);
            $key = $constraint->class . ':' . constraintArgsKey($args);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $found[] = $constraint;
        }
    };

    walkAstValues($expr, $visit);

    return $found;
}

/** Drop every pending constraint in an expression, recursively. */
function clearPendingConstraintsDeep(Ast\AstNode $expr): void
{
    walkAstValues($expr, static function (Ast\AstNode $node): void {
        $node->pendingConstraints = [];
    });
}

/**
 * Drop the annotations an inference run left on a body.
 *
 * The probe that discovers a signature-less function's constraints infers the
 * body with throwaway variables and rolls the substitution back, so anything it
 * wrote on the shared AST describes a typing that no longer exists: an operand
 * annotated with a type the probe guessed, or an operator it resolved for that
 * guess, would be read back by the passes that do have the last word -- the
 * native-operator pass in particular, which turns an `infix` node into the
 * operation of its operands' type while the node still exists. The real check
 * re-derives all of this, so the probe leaves the body as it found it.
 */
function clearInferredAnnotationsDeep(Ast\AstNode $expr): void
{
    walkAstValues($expr, static function (Ast\AstNode $node): void {
        $node->inferredType = null;
        if ($node instanceof Ast\Infix) {
            $node->resolvedIntrinsic = null;
        }
    });
}

/**
 * Visit an expression and every walkable value below it. The tree nests nodes
 * in plain arrays, so one list level is flattened, and visited objects are
 * remembered because a node may be reachable twice.
 *
 * @param callable(Ast\AstNode): void $visit
 */
function walkAstValues(Ast\AstNode $expr, callable $visit): void
{
    $visited = [];
    $walk = static function (Ast\AstWalkable $root) use (&$walk, &$visited, $visit): void {
        if ($root instanceof Ast\AstNode) {
            if (isset($visited[\spl_object_id($root)])) {
                return;
            }

            $visited[\spl_object_id($root)] = true;
            $visit($root);
        }

        foreach ($root->childValues() as $child) {
            foreach (\is_array($child) ? $child : [$child] as $value) {
                if ($value instanceof Ast\AstWalkable) {
                    $walk($value);
                }
            }
        }
    };
    $walk($expr);
}

/** @param list<Ast\PendingConstraint> $constraints */
function constraintsResolvable(TypeCheckState $state, array $constraints): bool
{
    foreach ($constraints as $constraint) {
        $classInfo = $state->classes[$constraint->class] ?? null;
        if ($classInfo === null) {
            return false;
        }

        try {
            $head = instanceHeadFromConstraintArgs($state, $classInfo['params'], $constraint->args);
        } catch (TypeError) {
            return false;
        }

        $head = prune($state, $head);

        // A matching given/context dictionary is evidence by itself — this is
        // what lets instance methods dispatch through a `C (V a)` context.
        if (ambientEvidenceFor($state, $constraint) !== null) {
            continue;
        }

        if ($head instanceof TVar) {
            return false;
        }

        // The instance's own context has to be selectable too. `Show (List t)`
        // needs `Show t`, and while `t` is open no dictionary can be picked for
        // it yet -- a later pass either knows the type, or defaults it.
        foreach (instanceLeafConstraints($state, $constraint) ?? [$constraint] as $leaf) {
            $leafHead = count($leaf->args) === 1
                ? prune($state, $leaf->args[0])
                : new TCon('__InstanceHead', $leaf->args);
            if ($leafHead instanceof TVar && ambientEvidenceFor($state, $leaf) === null) {
                return false;
            }
        }

        if (!findProjectInstance($state, $constraint->class, $head)) {
            return false;
        }
    }

    return true;
}
