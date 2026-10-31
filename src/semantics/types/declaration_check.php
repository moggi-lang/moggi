<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\IR\EntryPointKind;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;
use function Moggi\Semantics\TypeExpr\scheme;

/**
 * Make the class methods of the given obligations the ones a body in scope can
 * call, projecting each through the dictionary the obligation names.
 *
 * Installed once per declaration, for the declaration's own vocabulary, before
 * its body is checked. A local annotated group installs its own map over this
 * one for the duration of its body (see `abstractAnnotatedLocalBinding`), so a
 * method call never resolves through a dictionary that is not in scope where the
 * call sits.
 *
 * Whether a dictionary is one the declaration *wrote* (rather than one a
 * superclass brought along) is the allocation's answer, so an obligation an open
 * declaration takes on mid-body is marked exactly like one in its signature.
 *
 * @param list<Ast\\PendingConstraint> $constraintList
 * @param array<string, Scheme> $bodyEnv names that shadow a method are unset here
 */
function installConstraintMethods(
    TypeCheckState $state,
    array $constraintList,
    array &$bodyEnv,
): void {
    foreach ($constraintList as $constraint) {
        $classInfo = $state->classes[$constraint->class];
        $mapping = [];
        foreach ($classInfo['params'] as $i => $param) {
            $mapping[$param['name']] = constructorMappingValue($state, $param, $constraint->args[$i]);
        }

        foreach ($classInfo['methods'] as $methodName => $methodInfo) {
            $substituted = substituteClassMethod(
                $state,
                $methodInfo['type'],
                $methodInfo['userConstraints'] ?? [],
                $classInfo['params'],
                $mapping,
            );
            $userConstraints = $substituted['constraints'];
            $methodType = peelDictArrows(
                $substituted['type'],
                \count($userConstraints),
            );
            $info = [
                'class' => $constraint->class,
                'method' => $methodName,
                'evidence' => $constraint->evidence,
                'type' => $methodType,
                'userConstraints' => $userConstraints,
                'constraintHead' => prune($state, $constraint->args[0]),
                'constraintArgs' => $constraint->args,
                'implicit' => $constraint->implicit,
                'instanceHeadAst' => $constraint->instanceHeadAst,
                'declared' => $state->allocation?->declares($state, $constraint) ?? false,
            ];

            if (isset($state->constraintMethodCandidates[$methodName])) {
                $state->constraintMethodCandidates[$methodName][] = $info;
            }

            if (isset($state->constraintMethods[$methodName])) {
                $existing = $state->constraintMethods[$methodName];
                if (($existing['class'] ?? null) !== $info['class']
                    || constraintArgKey($existing['constraintHead']) !== constraintArgKey($info['constraintHead'])
                ) {
                    unset($state->constraintMethods[$methodName]);
                    $state->constraintMethodAmbiguities[$methodName] = true;
                    $state->constraintMethodCandidates[$methodName] = [$existing, $info];
                    unset($bodyEnv[$methodName]);
                }

                continue;
            }

            if (isset($state->constraintMethodAmbiguities[$methodName])) {
                unset($bodyEnv[$methodName]);

                continue;
            }

            $state->constraintMethods[$methodName] = $info;
            unset($bodyEnv[$methodName]);
        }
    }

}

/**
 * The dictionary parameters a declaration's body reads its evidence from: one
 * `__ev_` pattern per dictionary, in parameter order, in front of the function's
 * own parameters.
 *
 * One producer for a signature's obligations and for the ones an inferred
 * declaration takes on at its checkpoint -- the two lists are the same contract,
 * so they must be spelled the same way.
 *
 * @param list<Ast\PendingConstraint> $constraints
 */
function prependEvidenceParams(Ast\FunctionDecl $fn, array $constraints): void
{
    if ($constraints === []) {
        return;
    }

    $patterns = [];
    foreach ($constraints as $constraint) {
        $patterns[] = new Ast\PatVar($constraint->evidence);
    }

    $fn->params = [...$patterns, ...$fn->params];
    $fn->constraints = $constraints;
}

function checkFunction(TypeCheckState $state, Ast\FunctionDecl $fn): Ast\FunctionDecl
{
    $hadDeclaredSignature = Ast\hasDeclaredSignature($fn);
    $savedAllocation = $state->allocation;
    $state->subst = [];
    $state->holes = [];
    if (! $fn->instanceMethod && isset($state->instanceMethodNames[$fn->name])) {
        unset($state->env[$fn->name]);
    }

    $hasSignature = $hadDeclaredSignature || $fn->inferredSignatureType !== null;
    $restricted = isRestrictedDeclaration($fn);

    $env = $state->env;
    $paramTypes = [];
    $constraints = [];
    $userConstraints = [];
    $constraintEnv = [];
    $group = new SolvedGroup([], []);
    $origFnType = null;
    $origConstraints = [];
    $origUserConstraints = [];
    $skolemNames = [];
    $skolemScopeAdd = [];

    if ($hasSignature) {
        $kindScope = Kinds\pushKindScope($state);

        try {
            [$constraintAsts, $bodyTypeAst] = splitTypeAst($fn->inferredSignatureType ?? $fn->type);
            $userConstraints = parseConstraints($state, $constraintAsts, $fn);
            if ($fn->inferredSignatureType !== null) {
                $fn->params = userParamsOf($fn);
            }

            $allocation = DeclarationAllocation::closed($state, $userConstraints);
            $userConstraints = $allocation->userObligations();
            $constraints = $allocation->obligations();
            $group = new SolvedGroup($userConstraints, $constraints);
            $constraintEnv = buildConstraintEnv($state, $constraints);
            $fnType = astType($state, $bodyTypeAst);
            Kinds\assertKind($state, $fnType, new Kinds\KType(), $state->varKinds, $fn);
            $state->checkedParameterKinds[\spl_object_id($fn)] = Kinds\declarationParameterKinds($state, $fnType);
            foreach (\array_reverse($constraints) as $constraint) {
                $fnType = new TArrow(new TCon('__Dict_' . $constraint->class), $fnType);
            }
        } finally {
            Kinds\restoreKindScope($state, $kindScope);
        }

        prependEvidenceParams($fn, $constraints);

        $origFnType = $fnType;
        $origConstraints = $constraints;
        $origUserConstraints = $userConstraints;
        $skolemSubst = skolemizeSignatureVars($state, $fnType, $constraints);
        if ($skolemSubst !== []) {
            $fnType = substitute($fnType, $skolemSubst);
            $constraints = substitutePendingConstraints($constraints, $skolemSubst);
            $userConstraints = substitutePendingConstraints($userConstraints, $skolemSubst);
            $allocation->substitute($skolemSubst);
            $constraintEnv = buildConstraintEnv($state, $constraints);
            $skolemNames = \array_map(
                static fn (TVar $skolem): string => $skolem->name,
                \array_values($skolemSubst),
            );
            foreach ($skolemSubst as $source => $skolem) {
                $skolemScopeAdd[$source] = $skolem->name;
            }
        }

        $expected = $fnType;
        foreach ($fn->params as $param) {
            if (! $expected instanceof TArrow) {
                throw typeFail($state, "function `{$fn->name}` has too many parameters", $fn);
            }
            $paramTypes[] = $expected->from;
            $expected = $expected->to;
        }
        $bodyExpected = $expected;
    } else {
        $allocation = DeclarationAllocation::open();
        $bodyExpected = freshType($state);
        $fnType = $bodyExpected;
        foreach (\array_reverse($fn->params) as $_) {
            $fnType = new TArrow(freshType($state), $fnType);
        }
        $expected = $fnType;
        foreach ($fn->params as $param) {
            $paramTypes[] = $expected->from;
            $expected = $expected->to;
        }
    }

    $savedRigidVars = $state->rigidVars;
    $savedSkolemScope = $state->skolemScope;
    $savedVarKinds = $state->varKinds;
    if ($skolemNames !== []) {
        $state->rigidVars = \array_fill_keys($skolemNames, true) + $savedRigidVars;
        $state->skolemScope = $skolemScopeAdd + $savedSkolemScope;
    }

    assertParamListLinear($state, $fn->params);
    $state->pushBinderScope();
    foreach ($fn->params as $i => $param) {
        [$_, $env] = bindPattern($state, $param, $paramTypes[$i], $env, generalize: false);
    }

    $bodyEnv = $constraintEnv === [] ? $env : [...$env, ...$constraintEnv];
    $selfType = peelDictArrows($fnType, \count($constraints));
    $bodyEnv[$fn->name] = scheme($selfType, [], $userConstraints, \count($constraints), $constraints);

    $state->allocation = $allocation;
    $savedConstraintMethods = $state->constraintMethods;
    $savedConstraintMethodAmbiguities = $state->constraintMethodAmbiguities;
    $savedConstraintMethodCandidates = $state->constraintMethodCandidates;
    $state->constraintMethods = [];
    $state->constraintMethodAmbiguities = [];
    $state->constraintMethodCandidates = [];
    $methodConstraints = $constraints;

    installConstraintMethods($state, $methodConstraints, $bodyEnv);
    $methodConstraintEnv = buildConstraintEnv($state, $methodConstraints);
    if ($methodConstraintEnv !== []) {
        $bodyEnv = [...$bodyEnv, ...$methodConstraintEnv];
    }

    $savedAmbientConstraints = $state->ambientConstraints;
    $savedAmbientByClass = $state->ambientConstraintsByClass;
    $state->ambientConstraints = $methodConstraints;
    $byClass = [];
    foreach ($methodConstraints as $constraint) {
        $byClass[$constraint->class][] = $constraint;
    }
    $state->ambientConstraintsByClass = $byClass;

    $bodyType = inferExpr($state, $fn->body, $bodyEnv);
    assertPendingDictsConsistentInExpr($state, $fn->body);
    $state->constraintMethods = $savedConstraintMethods;
    $state->constraintMethodAmbiguities = $savedConstraintMethodAmbiguities;
    $state->constraintMethodCandidates = $savedConstraintMethodCandidates;
    unify($state, $bodyType, $bodyExpected, $fn->body);

    if ($hasSignature) {
        $fnType = $origFnType;
        $constraints = $origConstraints;
        $userConstraints = $origUserConstraints;
    } else {
        if (! $restricted) {
            $found = inferredFunctionConstraints($state, $fn->body, $fnType);
            if ($found !== []) {
                $found = \array_values(\array_filter(
                    $found,
                    static fn (Ast\PendingConstraint $constraint): bool => ! constraintOverRestrictedVar($state, $constraint),
                ));
            }
            $allocation->adoptPending($state, $found);
        }

        $userConstraints = $allocation->userObligations();
        $constraints = $allocation->obligations();
        $group = new SolvedGroup($userConstraints, $constraints);

        foreach (\array_reverse($constraints) as $constraint) {
            $fnType = new TArrow(new TCon('__Dict_' . $constraint->class), $fnType);
        }

        prependEvidenceParams($fn, $constraints);
        if ($constraints !== []) {
            $state->ambientConstraints = $constraints;
            $byClass = [];
            foreach ($constraints as $constraint) {
                $byClass[$constraint->class][] = $constraint;
            }
            $state->ambientConstraintsByClass = $byClass;
        }
    }

    $ownsOpenVars = $restricted
        && quantifiedVars($state, peelDictArrows($fnType, \count($constraints))) !== [];
    $openRestricted = openRestrictedVarsInExpr($state, $fn->body);

    if ($ownsOpenVars || $openRestricted !== []) {
        deferDeclaration(
            $state,
            $fn,
            $fnType,
            $restricted,
            $hadDeclaredSignature,
            $group,
            $ownsOpenVars,
            $openRestricted,
            $skolemNames,
            $state->skolemScope,
        );
        recordRestrictedSolutions($state);
        $state->ambientConstraints = $savedAmbientConstraints;
        $state->ambientConstraintsByClass = $savedAmbientByClass;
        $state->rigidVars = $savedRigidVars;
        $state->skolemScope = $savedSkolemScope;
        $state->varKinds = $savedVarKinds;
        $state->allocation = $savedAllocation;
        $state->popBinderScope();

        return $fn;
    }

    $fnType = settleCheckedBody($state, $fn, $fnType, $restricted, $hadDeclaredSignature, $group, $skolemNames);

    $wrapper = trivialIntrinsicWrapper($fn);
    resolveInstanceIntrinsicMethods($state, $fn->body);
    $fn->body = rewriteIntrinsicApplies($state, $fn->body);

    if ($wrapper !== null) {
        $fn->intrinsicWrapper = $wrapper;
        $state->intrinsicWrappers[$fn->name] = $wrapper;
    }

    recordRestrictedSolutions($state);
    $state->rigidVars = $savedRigidVars;
    $state->skolemScope = $savedSkolemScope;
    $state->varKinds = $savedVarKinds;
    $state->allocation = $savedAllocation;
    $state->popBinderScope();

    return $fn;
}

/**
 * Fresh rigid variables for every variable a declared signature quantifies
 * over: the free variables of its type plus those of its constraint
 * arguments (the `__Dict_` arrows already carry the constraint arguments, so
 * walking the type alone would nearly suffice; the constraints are walked too
 * so nothing is missed).
 *
 * Each skolem's name carries the source spelling it replaces (`a$sk3`), so
 * diagnostics still name the variable the programmer wrote while the checker
 * treats every skolem as a distinct rigid variable.
 *
 * Only genuine type variables are skolemized (`TVar` names and `TVarApp`
 * heads): the walk deliberately uses {@see typeVars} rather than
 * {@see quantifiedVars}, because the latter also reports unknown type
 * *constructor* names — and the synthetic `__Dict_*` arrows must stay
 * constructors, never become variables.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return array<string, TVar> source variable name → fresh skolem
 */
function skolemizeSignatureVars(TypeCheckState $state, Type $fnType, array $constraints): array
{
    $quantified = typeVars($fnType);
    foreach ($constraints as $constraint) {
        foreach ($constraint->args as $arg) {
            $quantified += typeVars($arg);
        }
    }

    $mapping = [];
    foreach (\array_keys($quantified) as $name) {
        $mapping[$name] = freshSkolem($state, $name);
    }

    return $mapping;
}

/**
 * The variables the declaration's own obligations mention.
 *
 * A literal under `Num a` is not ambiguous even when `a` is not written in the
 * declaration's type: the obligation is part of what the declaration takes, so
 * defaulting it to `Integer` would contradict the dictionary it is about to be
 * handed. A local annotated binding that turns out to constrain the enclosing
 * declaration (`f n = go n [] where go :: Num a => a -> [a]`) is exactly that
 * shape -- the group's variable never appears in `f`'s type, but `Num` of it is
 * `f`'s dictionary.
 *
 * @param list<Ast\PendingConstraint> $obligations
 * @return array<string, true>
 */
function obligationVars(TypeCheckState $state, array $obligations): array
{
    $known = \array_flip(knownTypeConstructorNames($state));
    $vars = [];
    foreach ($obligations as $obligation) {
        foreach ($obligation->args as $arg) {
            foreach (signatureVarsInType(prune($state, $arg), $known) as $name => $_) {
                $vars[$name] = true;
            }
        }
    }

    return $vars;
}

/**
 * Finish a declaration whose body has been inferred: default what its own type
 * does not determine, resolve the dictionaries its calls need, elaborate its
 * literals, and record its scheme.
 *
 * A restricted declaration normally reaches this only from the end of the
 * module, once every use site has had its say about the declaration's
 * variables.
 *
 * @param SolvedGroup $group the declaration's constraints, solved once
 * @param list<string> $skolemNames skolems the body was checked against:
 *   still bound by the signature, so never defaulted, even though the
 *   restored signature no longer names them
 */
function settleCheckedBody(
    TypeCheckState $state,
    Ast\FunctionDecl $fn,
    Type $fnType,
    bool $restricted,
    bool $hadDeclaredSignature,
    SolvedGroup $group,
    array $skolemNames = [],
): Type {
    defaultAmbiguousNumericVars(
        $state,
        $fn->body,
        $restricted
            ? []
            : quantifiedVars($state, peelDictArrows($fnType, \count($group->dicts)))
                + \array_fill_keys($skolemNames, true)
                + obligationVars($state, $group->dicts),
        $fn,
    );

    resolveDeferredInfixPrimops($state, $fn->body);
    $fn->body = resolvePendingEvidenceInExpr($state, $fn->body);
    $fn->body = tryResolveValueEvidence($state, $fn->body);
    $fn->body = elaborateNumericLiterals($state, $fn->body);
    assertNoPendingConstraintsInExpr($state, $fn->body, $fn);
    zonkInferredTypesInExpr($state, $fn->body);
    foreach ($fn->params as $param) {
        zonkInferredTypesInPattern($state, $param);
    }
    reportTypedHoles($state);
    $fnType = prune($state, $fnType);
    $renderedType = $fnType;
    $renderedUserType = null;
    $renderedUser = [];
    $prunedUserConstraints = refreshConstraintArgs($state, $group->user);
    $prunedDicts = refreshConstraintArgs($state, $group->dicts);
    if (! $hadDeclaredSignature) {
        $userType = peelDictArrows($fnType, \count($group->dicts));
        $userArgs = [];
        foreach ($prunedUserConstraints as $constraint) {
            foreach ($constraint->args as $arg) {
                $userArgs[] = $arg;
            }
        }
        $rename = friendlyTypeVarNames([$userType, ...$userArgs]);
        if ($rename !== []) {
            $mapping = [];
            foreach ($rename as $from => $to) {
                $mapping[$from] = new TVar($to);
            }
            $renderedType = substitute($fnType, $mapping);
            $userType = substitute($userType, $mapping);
            $renderedUser = \array_map(
                static fn (Ast\PendingConstraint $constraint): Ast\PendingConstraint => new Ast\PendingConstraint(
                    $constraint->class,
                    \array_map(
                        static fn (Type $arg): Type => substitute($arg, $mapping),
                        $constraint->args,
                    ),
                    $constraint->evidence,
                    $constraint->implicit,
                    $constraint->instanceHeadAst,
                ),
                $prunedUserConstraints,
            );
        } else {
            $renderedUser = $prunedUserConstraints;
        }
        $renderedUserType = $userType;
    }

    $fn->type = signatureTypeAst(
        $renderedType,
        \array_flip(knownTypeConstructorNames($state)),
        $hadDeclaredSignature ? $state->subst : [],
    );
    $fn->typeInferred = ! $hadDeclaredSignature;
    if ($renderedUserType !== null) {
        $fn->inferredSignatureType = inferredSignatureAst(
            $renderedUserType,
            $renderedUser,
            \array_flip(knownTypeConstructorNames($state)),
        );
    }
    $userFnType = peelDictArrows($fnType, \count($group->dicts));
    if (! \array_key_exists(\spl_object_id($fn), $state->checkedParameterKinds)) {
        $dictArgs = [];
        foreach ($prunedDicts as $dict) {
            foreach ($dict->args as $arg) {
                $dictArgs[] = $arg;
            }
        }
        $state->checkedParameterKinds[\spl_object_id($fn)] = Kinds\declarationParameterKinds(
            $state,
            $userFnType,
            $dictArgs,
        );
    }
    $checkedScheme = $restricted
        ? scheme($userFnType, [], [])
        : scheme(
            $userFnType,
            schemeBoundVars($userFnType, $state->env, $prunedUserConstraints),
            $prunedUserConstraints,
            \count($prunedDicts),
            $prunedDicts,
        );
    $state->env[$fn->name] = $checkedScheme;
    $state->checkedSchemes[\spl_object_id($fn)] = $checkedScheme;

    return $fnType;
}

/**
 * The variables of a restricted declaration this body still needs an answer for.
 *
 * `show justValue` is typed against the declaration's open variable -- the
 * `Show t` it needs is one the module end decides -- so the declaration using it
 * cannot be finished until then either.
 *
 * @return array<string, true>
 */
function openRestrictedVarsInExpr(TypeCheckState $state, Ast\AstNode $body): array
{
    if ($state->restrictedVars === []) {
        return [];
    }

    $known = \array_flip(knownTypeConstructorNames($state));
    $open = [];
    foreach (pendingConstraintsDeep($body, $state) as $constraint) {
        foreach ($constraint->args as $arg) {
            foreach (signatureVarsInType(prune($state, $arg), $known) as $name => $_) {
                if (isset($state->restrictedVars[$name])) {
                    $open[$name] = true;
                }
            }
        }
    }

    return $open;
}

/**
 * Hold a declaration back for the end of the module.
 *
 * A restricted declaration is registered monomorphically, so every use site sees
 * the same variables and each use is remembered
 * ({@see TypeCheckState::$restrictedSolutions}) for the pass that finishes it
 * once no use can say anything more. A declaration that merely *uses* one of
 * those variables is finished in the same pass, after the declaration owning it.
 *
 * @param SolvedGroup $group the declaration's constraints, solved once
 */
function deferDeclaration(
    TypeCheckState $state,
    Ast\FunctionDecl $fn,
    Type $fnType,
    bool $restricted,
    bool $hadDeclaredSignature,
    SolvedGroup $group,
    bool $ownsOpenVars,
    array $openVars,
    array $skolemNames = [],
    array $skolemScope = [],
): void {
    reportTypedHoles($state);
    $state->holes = [];

    $fnType = prune($state, $fnType);
    if (! $hadDeclaredSignature) {
        $userFnType = peelDictArrows($fnType, \count($group->dicts));
        if ($restricted) {
            $state->env[$fn->name] = scheme($userFnType, [], []);
        } else {
            $state->env[$fn->name] = scheme(
                $userFnType,
                schemeBoundVars($userFnType, $state->env, $group->user),
                $group->user,
                \count($group->dicts),
                $group->dicts,
            );
        }
    }

    $vars = [];
    if ($ownsOpenVars) {
        $vars = \array_keys(quantifiedVars($state, $fnType));
        foreach ($vars as $var) {
            $state->restrictedVars[$var] = true;
        }
    }

    $state->deferredRestricted[] = [
        'fn' => $fn,
        'type' => $fnType,
        'vars' => $vars,
        'openVars' => $openVars,
        'owns' => $ownsOpenVars,
        'restricted' => $restricted,
        'declared' => $hadDeclaredSignature,
        'skolemNames' => $skolemNames,
        'skolemScope' => $skolemScope,
        'bindingGroup' => $group,
        'subst' => $state->subst,
        'methods' => $state->constraintMethods,
        'ambiguities' => $state->constraintMethodAmbiguities,
        'methodCandidates' => $state->constraintMethodCandidates,
        'declarationSpan' => $state->declSpan,
    ];
}

/**
 * Remember what the declaration just checked pinned the variables of a
 * restricted declaration to.
 *
 * A use site solves such a variable by unifying it with a fresh variable of its
 * own, so what the use site meant is only readable once that whole declaration
 * has been inferred.
 *
 * @param list<string>|null $vars defaults to every restricted variable
 */
function recordRestrictedSolutions(TypeCheckState $state, ?array $vars = null): void
{
    foreach ($vars ?? \array_keys($state->restrictedVars) as $var) {
        $var = (string) $var;
        $solved = resolveRestrictedSolution($state, $var);
        if ($solved !== null) {
            $state->restrictedSolutions[$var] = $solved;
        }
    }
}

/**
 * What a restricted variable stands for: what this declaration's substitution
 * says about it, followed through what earlier declarations settled, or null
 * while nothing has an answer.
 */
function resolveRestrictedSolution(TypeCheckState $state, string $var): ?Type
{
    $type = $state->subst[$var] ?? $state->restrictedSolutions[$var] ?? null;
    $seen = [$var => true];

    while ($type !== null) {
        $type = prune($state, $type);
        if (! $type instanceof TVar) {
            return $type;
        }

        if (isset($seen[$type->name])) {
            return null;
        }
        $seen[$type->name] = true;
        $type = $state->subst[$type->name] ?? $state->restrictedSolutions[$type->name] ?? null;
    }

    return null;
}

/**
 * Settle the declarations the monomorphism restriction held back (`n = 1 + 1`
 * is an `Integer` unless a later `m = n :: Int` says otherwise) now that every
 * use site has been checked.
 *
 * A declaration that owns restricted variables settles before one that merely
 * uses them, so a use site can read the owner's settled type.
 *
 * @return list<string> the names whose scheme the module end settled, for the
 *   interface the module publishes
 */
function finishRestrictedDeclarations(TypeCheckState $state): array
{
    $deferred = $state->deferredRestricted;
    $state->deferredRestricted = [];

    $owners = [];
    $users = [];
    $settled = [];
    foreach ($deferred as $entry) {
        if ($entry['owns']) {
            $owners[] = $entry;
        } else {
            $users[] = $entry;
        }
    }

    foreach ([...$owners, ...$users] as $entry) {
        settleDeferredDeclaration($state, $entry);
        $settled[] = $entry['fn']->name;
    }

    return $settled;
}

/** @param array<string, mixed> $entry a record of {@see deferDeclaration} */
function settleDeferredDeclaration(TypeCheckState $state, array $entry): void
{
    $fn = $entry['fn'];
    $saved = [
        $state->subst,
        $state->constraintMethods,
        $state->constraintMethodAmbiguities,
        $state->constraintMethodCandidates,
        $state->declSpan,
        $state->ambientConstraints,
        $state->ambientConstraintsByClass,
        $state->rigidVars,
        $state->skolemScope,
    ];

    $state->subst = $entry['subst'];
    $waiting = \array_unique([
        ...\array_keys($state->restrictedSolutions),
        ...\array_keys($entry['vars']),
        ...\array_keys($entry['openVars']),
    ]);
    for ($round = 0; $round <= \count($waiting); ++$round) {
        foreach ($waiting as $var) {
            $var = (string) $var;
            if (isset($state->subst[$var])) {
                continue;
            }

            $solved = resolveRestrictedSolution($state, $var);
            if ($solved !== null) {
                $state->subst[$var] = $solved;
            }
        }
    }

    $state->constraintMethods = $entry['methods'];
    $state->constraintMethodAmbiguities = $entry['ambiguities'];
    $state->constraintMethodCandidates = $entry['methodCandidates'];
    $state->declSpan = $entry['declarationSpan'];
    $own = $entry['bindingGroup'] instanceof SolvedGroup ? $entry['bindingGroup']->dicts : [];
    $state->ambientConstraints = $own;
    $state->ambientConstraintsByClass = [];
    foreach ($own as $constraint) {
        $state->ambientConstraintsByClass[$constraint->class][] = $constraint;
    }
    $state->rigidVars = \array_fill_keys($entry['skolemNames'] ?? [], true) + $state->rigidVars;
    $settleScope = $entry['skolemScope'] ?? [];
    if ($settleScope !== []) {
        $state->skolemScope = $settleScope + $state->skolemScope;
    }

    try {
        $fnType = settleCheckedBody(
            $state,
            $fn,
            $entry['type'],
            $entry['restricted'],
            $entry['declared'],
            $entry['bindingGroup'],
            $entry['skolemNames'] ?? [],
        );
        recordRestrictedSolutions($state);
        $wrapper = trivialIntrinsicWrapper($fn);
        resolveInstanceIntrinsicMethods($state, $fn->body);
        $fn->body = rewriteIntrinsicApplies($state, $fn->body);
        if ($wrapper !== null) {
            $fn->intrinsicWrapper = $wrapper;
            $state->intrinsicWrappers[$fn->name] = $wrapper;
        }
    } finally {
        [
            $state->subst,
            $state->constraintMethods,
            $state->constraintMethodAmbiguities,
            $state->constraintMethodCandidates,
            $state->declSpan,
            $state->ambientConstraints,
            $state->ambientConstraintsByClass,
            $state->rigidVars,
            $state->skolemScope,
        ] = $saved;
    }
}

/**
 * Whether a constraint is over a variable a restricted declaration owns.
 *
 * Such a constraint is not this declaration's to abstract: `targetFunction x =
 * x + targetValue` only adds `Num` because `targetValue`'s type is not settled
 * yet, and the module end settles it (`targetValue = 123` is an `Integer`), so a
 * dictionary parameter here would be one the declaration does not really take.
 */
function constraintOverRestrictedVar(TypeCheckState $state, Ast\PendingConstraint $constraint): bool
{
    if ($state->restrictedVars === []) {
        return false;
    }

    $known = \array_flip(knownTypeConstructorNames($state));
    foreach ($constraint->args as $arg) {
        foreach (\array_keys(signatureVarsInType(prune($state, $arg), $known)) as $name) {
            if (isset($state->restrictedVars[$name])) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The dependency-ordered components a set of inferred declarations is inferred
 * in, with the members resolved back to the declarations.
 *
 * @param list<Ast\FunctionDecl> $functions
 * @return list<array{members: list<Ast\FunctionDecl>, recursive: bool}>
 */
function inferredSignatureComponents(array $functions): array
{
    $components = [];
    foreach (topLevelBindingSccs($functions) as $component) {
        $members = [];
        foreach ($component['indices'] as $index) {
            $members[] = $functions[$index];
        }

        $components[] = ['members' => $members, 'recursive' => $component['recursive']];
    }

    return $components;
}

/**
 * Infer every inferred declaration once, in the order its dependencies allow.
 *
 * An acyclic declaration needs no signature before its body is checked: by the
 * time it is reached, every declaration it can call already publishes its
 * scheme, so the check itself is what discovers its type, takes on the
 * obligations its body raised at its own checkpoint, and generalizes it. Such a
 * declaration is therefore checked here and its checked form handed back for the
 * source walk to place -- one inference, one elaboration, no copied body.
 *
 * A pattern binding is one of those: its body check *is* its inference. The
 * monomorphism restriction does not change when the body is checked, only what
 * the checkpoint does with the result -- `deferDeclaration()` hands its variables
 * to the module end instead of generalizing them.
 *
 * A cycle has no such order (`isEven`/`isOdd` need each other before either
 * body can be checked), so the whole component is checked once as a group:
 * monotypes for every member, one body each, one decision about the group's
 * dictionaries ({@see inferRecursiveDeclarations()}).
 *
 * @param list<Ast\FunctionDecl> $functions
 * @return array<int, Ast\FunctionDecl> the declarations checked here, by object id
 */
function inferInferredDeclarations(TypeCheckState $state, array $functions): array
{
    $checked = [];
    foreach (inferredSignatureComponents($functions) as $component) {
        $members = $component['members'];
        if (! $component['recursive']) {
            $fn = $members[0];
            $state->declSpan = [
                'line' => $fn->line,
                'col' => $fn->col,
                'endCol' => $fn->endCol,
            ];
            $checked[\spl_object_id($fn)] = checkFunction($state, $fn);

            continue;
        }

        foreach (inferRecursiveDeclarations($state, $members) as $id => $fn) {
            $checked[$id] = $fn;
        }
    }

    return $checked;
}

/**
 * Whether a declaration is *restricted*: a pattern binding -- `x = e`,
 * `f = \p -> e`, `(a, b) = e` -- with no type signature, where the pattern on
 * the left is the whole left-hand side.
 *
 * The monomorphism restriction does not generalize such a declaration, so `p
 * = 1 + 1` is an `Integer` (`Show Integer`, bignum arithmetic) rather than a
 * dictionary-taking `Num a => a`, while `p x = x + 1` -- a function binding --
 * is generalized as usual.
 */
function isRestrictedDeclaration(Ast\FunctionDecl $fn): bool
{
    return ! Ast\hasDeclaredSignature($fn)
        && $fn->inferredSignatureType === null
        && userParamsOf($fn) === [];
}

/**
 * The user-written parameters of a function, without the dictionary parameters
 * an earlier check of the same declaration prepended.
 *
 * @return array<int, Ast\AstNode>
 */
function userParamsOf(Ast\FunctionDecl $fn): array
{
    $params = $fn->params;
    while ($params !== []
        && $params[0] instanceof Ast\PatVar
        && \str_starts_with($params[0]->name, '__ev_')) {
        \array_shift($params);
    }

    return \array_values($params);
}

/**
 * Mark `main` as EntryPointKind::Main under Executable purpose only, in the
 * module this compilation names in `TypeCheckState::$entryModules`.
 *
 * A package may declare several executables, each with its own entry, so the
 * entry module is named by the compilation rather than fixed at `Main`; the
 * default policy marks only `Main`, which keeps single-file and REPL compiles
 * unchanged, and Library / Repl purposes promote no entry at all.
 *
 * @param list<Ast\AstNode> $items
 */
function validateEntryPoint(
    TypeCheckState $state,
    array &$items,
    ?string $moduleName = null,
    CompilePurpose $purpose = CompilePurpose::Executable,
): void {
    if ($purpose !== CompilePurpose::Executable) {
        return;
    }

    if ($moduleName === null || ! isset($state->entryModules[$moduleName])) {
        return;
    }

    $mainIndexes = [];
    foreach ($items as $index => $item) {
        if ($item instanceof Ast\FunctionDecl && $item->name === 'main') {
            $mainIndexes[] = $index;
        }
    }

    if ($mainIndexes === []) {
        return;
    }

    if (\count($mainIndexes) > 1) {
        throw typeFail($state, 'duplicate entry point `main`', $items[$mainIndexes[1]]);
    }

    $index = $mainIndexes[0];
    $main = $items[$index];

    if (! $main instanceof Ast\FunctionDecl) {
        return;
    }

    if ($main->type === null) {
        return;
    }

    [$constraintAsts, $bodyTypeAst] = splitTypeAst($main->type);
    $resultType = astType($state, $bodyTypeAst);

    if (! isIoTypeCon($state, $resultType)) {
        return;
    }

    if ($constraintAsts !== []) {
        throw typeFail($state, 'entry point `main` may not have constraints', $main);
    }

    $evidenceParams = \count($main->constraints);
    if (\count($main->params) > $evidenceParams) {
        throw typeFail($state, 'entry point `main` must not take parameters', $main);
    }

    $main->entryKind = EntryPointKind::Main;
    $items[$index] = $main;
}
