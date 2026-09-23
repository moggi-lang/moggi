<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\IR\EntryPointKind;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TBytes;
use Moggi\Semantics\TypeExpr\TChar;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TDouble;
use Moggi\Semantics\TypeExpr\TInt;
use Moggi\Semantics\TypeExpr\TInt16;
use Moggi\Semantics\TypeExpr\TInt32;
use Moggi\Semantics\TypeExpr\TInt64;
use Moggi\Semantics\TypeExpr\TInt8;
use Moggi\Semantics\TypeExpr\TNatLit;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TStr;
use Moggi\Semantics\TypeExpr\TStringLit;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TWord;
use Moggi\Semantics\TypeExpr\TWord16;
use Moggi\Semantics\TypeExpr\TWord32;
use Moggi\Semantics\TypeExpr\TWord64;
use Moggi\Semantics\TypeExpr\TWord8;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Errors\appendDidYouMean;
use function Moggi\Modules\resolvedSymbol;
use function Moggi\Patterns\Walk\patternDuplicateBinder;
use function Moggi\Patterns\Walk\patternVariableNames;
use function Moggi\Patterns\Walk\patternVariableNamesOrdered;
use function Moggi\Semantics\Deriving\newtypeChain;
use function Moggi\Semantics\Exhaustiveness\caseExhaustivenessProven;
use function Moggi\Semantics\Exhaustiveness\checkCaseExhaustiveness;
use function Moggi\Semantics\IntrinsicRegistry\capturesCallSite;
use function Moggi\Semantics\IntrinsicRegistry\primitiveTypeOwnerModule;
use function Moggi\Semantics\IntrinsicRegistry\resolveIntrinsicName;
use function Moggi\Semantics\IntrinsicRegistry\resolveMonomorphicOperator;
use function Moggi\Semantics\IntrinsicRegistry\schemeArity;
use function Moggi\Semantics\IntrinsicRegistry\typeSchemes;
use function Moggi\Semantics\TypeExpr\canonicalNatDigits;
use function Moggi\Semantics\TypeExpr\scheme;
use function Moggi\Semantics\TypeExpr\schemeFreeTypeVars;
use function Moggi\Syntax\Ast\desugarDo;
use function Moggi\Syntax\isConstructorName;
use function Moggi\Syntax\isConstructorOperator;

require_once __DIR__ . '/../../patterns/walk.php';
require_once __DIR__ . '/let_groups.php';
require_once __DIR__ . '/type_core.php';
require_once __DIR__ . '/constraints.php';
require_once __DIR__ . '/instances.php';
require_once __DIR__ . '/../exhaustiveness.php';

function isIoTypeCon(TypeCheckState $state, Type $type): bool
{
    $type = prune($state, $type);

    return $type instanceof TCon
        && $type->name === 'IO'
        && count($type->args) === 1;
}

function checkFunction(TypeCheckState $state, Ast\FunctionDecl $fn): Ast\FunctionDecl
{
    $hadDeclaredSignature = Ast\hasDeclaredSignature($fn);
    $state->subst = [];
    $state->holes = [];
    if (! $fn->instanceMethod && isset($state->instanceMethodNames[$fn->name])) {
        unset($state->env[$fn->name]);
    }

    // A body without a signature is scanned for its constraints, materialized as a
    // signature and checked like any other function; that signature is then the truth.
    if (! $hadDeclaredSignature && $fn->inferredSignatureType === null && ! isRestrictedDeclaration($fn)) {
        $discovered = discoverFunctionConstraints($state, $fn);
        // Only a declaration that takes dictionaries gets a signature here; the rest is left
        // to `discoverInferredSignatures`.
        if ($discovered !== null && $discovered['constraints'] !== []) {
            materializeInferredSignature(
                $state,
                $fn,
                $discovered['type'],
                $discovered['constraints'],
            );
        }
    }

    $hasSignature = $hadDeclaredSignature || $fn->inferredSignatureType !== null;

    $env = $state->env;
    $paramTypes = [];
    $constraints = [];
    $userConstraints = [];
    $constraintEnv = [];

    if ($hasSignature) {
        $kindScope = Kinds\pushKindScope($state);
        try {
            [$constraintAsts, $bodyTypeAst] = splitTypeAst($fn->inferredSignatureType ?? $fn->type);
            $userConstraints = parseConstraints($state, $constraintAsts, $fn);
            if ($fn->inferredSignatureType !== null) {
                // Dictionary parameters a previous check of this declaration
                // prepended are dropped here and rebuilt below.
                $fn->params = userParamsOf($fn);
            }

            $constraints = expandConstraintsWithSuperclasses($state, $userConstraints);
            applyConstraintVarKinds($state, $constraints);
            $constraintEnv = buildConstraintEnv($state, $constraints);
            // `astType` reads the variable kinds `applyConstraintVarKinds` set, so
            // the body type is only elaborated once those are in place.
            $fnType = astType($state, $bodyTypeAst);
            Kinds\assertKind($state, $fnType, new Kinds\KType(), $state->varKinds, $fn);
            foreach (array_reverse($constraints) as $constraint) {
                $fnType = new TArrow(new TCon('__Dict_' . $constraint->class), $fnType);
            }
        } finally {
            Kinds\restoreKindScope($state, $kindScope);
        }

        if ($constraints !== []) {
            $evidencePatterns = [];
            foreach ($constraints as $constraint) {
                $evidencePatterns[] = new Ast\PatVar($constraint->evidence);
            }
            $fn->params = [...$evidencePatterns, ...$fn->params];
            $fn->constraints = $constraints;
        }

        $expected = $fnType;
        foreach ($fn->params as $param) {
            if (!$expected instanceof TArrow) {
                throw typeFail($state, "function `{$fn->name}` has too many parameters", $fn);
            }
            $paramTypes[] = $expected->from;
            $expected = $expected->to;
        }
        $bodyExpected = $expected;
    } else {
        $bodyExpected = freshType($state);
        $fnType = $bodyExpected;
        foreach (array_reverse($fn->params) as $_) {
            $fnType = new TArrow(freshType($state), $fnType);
        }
        $expected = $fnType;
        foreach ($fn->params as $param) {
            $paramTypes[] = $expected->from;
            $expected = $expected->to;
        }
    }

    // An inferred signature is checked once: a later walk would infer the body again and
    // insert the dictionaries of its call sites a second time.
    if (! $hadDeclaredSignature && $fn->inferredSignatureChecked) {
        $userFnType = peelDictArrows($fnType, count($constraints));
        $prunedUserConstraints = refreshConstraintArgs($state, $userConstraints);
        $state->env[$fn->name] = scheme(
            $userFnType,
            schemeBoundVars($userFnType, $state->env, $prunedUserConstraints),
            $prunedUserConstraints,
            count($constraints),
        );
        $fn->typeInferred = true;

        return $fn;
    }

    assertParamListLinear($state, $fn->params);
    foreach ($fn->params as $i => $param) {
        [$_, $env] = bindPattern($state, $param, $paramTypes[$i], $env, generalize: false);
    }

    $bodyEnv = $constraintEnv === [] ? $env : [...$env, ...$constraintEnv];
    // The self-reference carries the user-facing type (dict arrows peeled) plus the
    // runtime constraint count, exactly like the exported scheme below.
    $selfType = peelDictArrows($fnType, count($constraints));
    // As written, not expanded: a recursive call re-expands them into the same
    // dictionary arguments, which is only stable while the list is the
    // user-level one (see expandConstraintsWithSuperclasses).
    $bodyEnv[$fn->name] = scheme($selfType, [], $userConstraints, count($constraints));

    $savedConstraintMethods = $state->constraintMethods;
    $savedConstraintMethodAmbiguities = $state->constraintMethodAmbiguities;
    $savedConstraintMethodCandidates = $state->constraintMethodCandidates;
    $state->constraintMethods = [];
    $state->constraintMethodAmbiguities = [];
    $state->constraintMethodCandidates = [];
    // `$constraints` is already the superclass-expanded list (and the list of
    // dictionary parameters); expanding it again would re-add every superclass
    // under the next root's identity.
    $methodConstraints = $constraints;

    // The constraints written as opposed to superclass-implied: a derived constraint is
    // a weaker provider, so the operand decides first and the written one after.
    $declaredConstraintKeys = [];
    foreach ($userConstraints as $userConstraint) {
        $declaredConstraintKeys[$userConstraint->class . ':' . constraintArgsKey($userConstraint->args)] = true;
    }

    $registerConstraintMethods = static function (array $constraintList) use ($state, &$bodyEnv, $declaredConstraintKeys): void {
        foreach ($constraintList as $constraint) {
            $classInfo = $state->classes[$constraint->class];
            $mapping = [];
            foreach ($classInfo['params'] as $i => $param) {
                $mapping[$param['name']] = constructorMappingValue($state, $param, $constraint->args[$i]);
            }

            foreach ($classInfo['methods'] as $methodName => $methodInfo) {
                $userConstraints = \array_map(
                    static function (Ast\PendingConstraint $constraint) use ($mapping): Ast\PendingConstraint {
                        return new Ast\PendingConstraint(
                            $constraint->class,
                            \array_map(
                                static fn (Type $arg): Type => substitute($arg, $mapping),
                                $constraint->args,
                            ),
                            $constraint->evidence,
                            $constraint->implicit,
                            $constraint->instanceHeadAst,
                        );
                    },
                    $methodInfo['userConstraints'] ?? [],
                );
                $methodType = peelDictArrows(
                    substitute($methodInfo['type'], $mapping),
                    count($userConstraints),
                );
                $info = [
                    'class' => $constraint->class,
                    'method' => $methodName,
                    'evidence' => $constraint->evidence,
                    'type' => $methodType,
                    'userConstraints' => $userConstraints,
                    'constraintHead' => prune($state, $constraint->args[0]),
                    'implicit' => $constraint->implicit,
                    'instanceHeadAst' => $constraint->instanceHeadAst,
                    'declared' => isset($declaredConstraintKeys[$constraint->class . ':' . constraintArgsKey($constraint->args)]),
                ];

                if (isset($state->constraintMethodCandidates[$methodName])) {
                    $state->constraintMethodCandidates[$methodName][] = $info;
                }

                if (isset($state->constraintMethods[$methodName])) {
                    $existing = $state->constraintMethods[$methodName];
                    // A second dictionary of the same class at the same head is
                    // the same provider (`(Monad m, Functor m)`); a different
                    // head is genuinely ambiguous (`(Functor f, Functor g)`).
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
    };

    $registerConstraintMethods($methodConstraints);
    $methodConstraintEnv = buildConstraintEnv($state, $methodConstraints);
    if ($methodConstraintEnv !== []) {
        $bodyEnv = [...$bodyEnv, ...$methodConstraintEnv];
    }

    // Ambient constraints let calls to *other* constrained functions (and this
    // function's own recursive calls) forward the enclosing evidence parameters
    // instead of demanding a concrete instance for a still-polymorphic type var.
    $savedAmbientConstraints = $state->ambientConstraints;
    $savedAmbientByClass = $state->ambientConstraintsByClass;
    $state->ambientConstraints = $methodConstraints;
    $byClass = [];
    foreach ($methodConstraints as $constraint) {
        $byClass[$constraint->class][] = $constraint;
    }
    $state->ambientConstraintsByClass = $byClass;

    $bodyType = inferExpr($state, $fn->body, $bodyEnv);
    $state->constraintMethods = $savedConstraintMethods;
    $state->constraintMethodAmbiguities = $savedConstraintMethodAmbiguities;
    $state->constraintMethodCandidates = $savedConstraintMethodCandidates;
    unify($state, $bodyType, $bodyExpected, $fn->body);

    $restricted = isRestrictedDeclaration($fn);
    $ownsOpenVars = $restricted
        && quantifiedVars($state, peelDictArrows($fnType, count($constraints))) !== [];
    $openRestricted = openRestrictedVarsInExpr($state, $fn->body);

    if ($ownsOpenVars || $openRestricted !== []) {
        // The monomorphism restriction leaves a declaration's variables open to module end:
        // what no use pinned is defaulted, and bodies using such a variable wait.
        deferDeclaration(
            $state,
            $fn,
            $fnType,
            $restricted,
            $hadDeclaredSignature,
            $constraints,
            $userConstraints,
            $ownsOpenVars,
            $openRestricted,
        );
        recordRestrictedSolutions($state);
        $state->ambientConstraints = $savedAmbientConstraints;
        $state->ambientConstraintsByClass = $savedAmbientByClass;

        return $fn;
    }

    $fnType = settleCheckedBody($state, $fn, $fnType, $restricted, $hadDeclaredSignature, $constraints, $userConstraints);

    // Lower saturated `#` primop applications to IntrinsicCall so IO normalize
    // and IR lowering carry the canonical `#` name end to end.
    resolveMachineIntPow($state, $fn->body);
    $fn->body = rewriteIntrinsicApplies($fn->body);

    $wrapper = trivialIntrinsicWrapper($fn);
    if ($wrapper !== null) {
        $fn->intrinsicWrapper = $wrapper;
        $state->intrinsicWrappers[$fn->name] = $wrapper;
    }

    // Latch the check only when the body carries dictionaries (a second inference would
    // duplicate them); a declaration needing none must be resolved again.
    if (! $hadDeclaredSignature && $fn->inferredSignatureType !== null && $constraints !== []) {
        $fn->inferredSignatureChecked = true;
    }

    // This declaration's use of a restricted declaration is the last chance to
    // read what it pinned it to.
    recordRestrictedSolutions($state);

    return $fn;
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
 * @param list<Ast\PendingConstraint> $constraints expanded, with superclasses
 * @param list<Ast\PendingConstraint> $userConstraints as written
 */
function settleCheckedBody(
    TypeCheckState $state,
    Ast\FunctionDecl $fn,
    Type $fnType,
    bool $restricted,
    bool $hadDeclaredSignature,
    array $constraints,
    array $userConstraints,
): Type {
    // A literal the declaration's own type does not determine is defaulted before the
    // evidence pass; a restricted declaration exempts none of its constraints.
    defaultAmbiguousNumericVars(
        $state,
        $fn->body,
        $restricted
            ? []
            : quantifiedVars($state, peelDictArrows($fnType, count($constraints))),
    );
    // Everything below writes into the body, so it belongs to the pass with the last
    // word: a provisional settle stops after the type.
    if (! $state->provisionalRestrictedSettle) {
        // Before the evidence pass: an operator whose operands the signature pinned to a
        // primitive numeric type must be decided while the `infix` node still exists.
        resolveDeferredNativeInfixes($state, $fn->body);
        resolvePendingEvidenceInExpr($state, $fn->body);
        tryResolveValueEvidence($state, $fn->body);
        // While the declaration's dictionaries are still in scope: a literal of a
        // polymorphic type is its `fromInteger`, projected from that dictionary.
        $fn->body = elaborateNumericLiterals($state, $fn->body);
        assertNoPendingConstraintsInExpr($state, $fn->body, $fn);
        zonkInferredTypesInExpr($state, $fn->body);
        foreach ($fn->params as $param) {
            zonkInferredTypesInPattern($state, $param);
        }
    }
    reportTypedHoles($state);
    $fnType = prune($state, $fnType);
    // `signatureTypeAst` rather than `internalTypeToAst`: an unbound higher-kinded
    // parameter written as a type application could not be elaborated later.
    $fn->type = signatureTypeAst(
        $fnType,
        \array_flip(knownTypeConstructorNames($state)),
        $state->subst,
    );
    // `$type` is the resolved type either way now, so record where it came from
    // for the later passes over this AST (`Ast\hasDeclaredSignature`).
    $fn->typeInferred = !$hadDeclaredSignature;
    $userFnType = peelDictArrows($fnType, count($constraints));
    // A signature type variable can be bound to a fresh internal var while checking the
    // body, so the constraints must be pruned along with the function type.
    $prunedUserConstraints = refreshConstraintArgs($state, $userConstraints);
    // A restricted declaration is registered *monomorphically*: every use site
    // has to see the same type variables, which is what lets `n = 1 + 1` and a
    // later `m = n :: Int` agree on `Int`.
    $state->env[$fn->name] = $restricted
        ? scheme($userFnType, [], [])
        : scheme(
            $userFnType,
            schemeBoundVars($userFnType, $state->env, $prunedUserConstraints),
            $prunedUserConstraints,
            count($constraints),
        );

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
    foreach (pendingConstraintsDeep($body) as $constraint) {
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
 * @param list<Ast\PendingConstraint> $constraints expanded, with superclasses
 * @param list<Ast\PendingConstraint> $userConstraints as written
 */
function deferDeclaration(
    TypeCheckState $state,
    Ast\FunctionDecl $fn,
    Type $fnType,
    bool $restricted,
    bool $hadDeclaredSignature,
    array $constraints,
    array $userConstraints,
    bool $ownsOpenVars,
    array $openVars,
): void {
    reportTypedHoles($state);
    $state->holes = [];

    $fnType = prune($state, $fnType);
    if (! $hadDeclaredSignature) {
        $userFnType = peelDictArrows($fnType, count($constraints));
        if ($restricted) {
            // Monomorphically (no bound variables): every use site sees the same
            // type variables, which is the point of holding the declaration
            // back.
            $state->env[$fn->name] = scheme($userFnType, [], []);
        } else {
            // A declaration held back for what it *uses* is called like any
            // other, with the dictionaries its own constraints need.
            $state->env[$fn->name] = scheme(
                $userFnType,
                schemeBoundVars($userFnType, $state->env, $userConstraints),
                $userConstraints,
                count($constraints),
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
        'constraints' => $constraints,
        'userConstraints' => $userConstraints,
        // The body was typed against these, and the passes at the module end
        // still need them: what the declaration solved for itself is not
        // repeated there.
        'subst' => $state->subst,
        // Those passes resolve against the class-method and declaration context
        // of this check -- the next declaration's check overwrites both.
        'methods' => $state->constraintMethods,
        'ambiguities' => $state->constraintMethodAmbiguities,
        'methodCandidates' => $state->constraintMethodCandidates,
        'span' => $state->declSpan,
    ];
}

/**
 * Settle the declarations the monomorphism restriction held back (`n = 1 + 1`
 * is an `Integer` unless a later `m = n :: Int` says otherwise) now that every
 * use site has been checked.
 */
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

function finishRestrictedDeclarations(TypeCheckState $state): void
{
    $deferred = $state->deferredRestricted;
    $state->deferredRestricted = [];

    // The declarations that own the open variables go first: what they settle is
    // what the declarations using them are waiting for.
    foreach ($deferred as $entry) {
        if ($entry['owns']) {
            settleDeferredDeclaration($state, $entry);
        }
    }
    foreach ($deferred as $entry) {
        if (! $entry['owns']) {
            settleDeferredDeclaration($state, $entry);
        }
    }
}

/** @param array<string, mixed> $entry a record of {@see deferDeclaration} */function settleDeferredDeclaration(TypeCheckState $state, array $entry): void
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
    ];

    // What the use sites pinned, on top of the substitution this declaration
    // was typed against. A solution can itself be stated in terms of another
    // open variable, so the table is walked until every entry sees the ones
    // installed before it; a chain is at most as long as the table.
    $state->subst = $entry['subst'];
    $waiting = \array_unique([
        ...\array_keys($state->restrictedSolutions),
        ...\array_keys($entry['vars']),
        ...\array_keys($entry['openVars']),
    ]);
    for ($round = 0; $round <= count($waiting); ++$round) {
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
    $state->declSpan = $entry['span'];
    $state->ambientConstraints = [];
    $state->ambientConstraintsByClass = [];

    try {
        $fnType = settleCheckedBody(
            $state,
            $fn,
            $entry['type'],
            $entry['restricted'],
            $entry['declared'],
            $entry['constraints'],
            $entry['userConstraints'],
        );
        // A declaration that only mentioned another restricted declaration now
        // has an answer for itself.
        recordRestrictedSolutions($state);
        resolveMachineIntPow($state, $fn->body);
        $fn->body = rewriteIntrinsicApplies($fn->body);
        $wrapper = trivialIntrinsicWrapper($fn);
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
        ] = $saved;
    }
}

/**
 * Discover what every inferred declaration needs to be called with before any
 * body is checked.
 *
 * A call site is lowered with the dictionaries of the function it calls, so a
 * declaration checked later can be called correctly by an earlier one -- but a
 * mutually recursive pair has no order in which both are already known, and
 * `isEven` calling `isOdd` would be lowered without a dictionary. Discovering
 * everything first, and repeating until no declaration gains a constraint, is
 * what a chain (`f n = g n`, `g n = show n`) needs: `g` learns `Show` in the
 * first round and `f` only in the second.
 *
 * @param list<Ast\FunctionDecl> $functions
 */
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

function discoverInferredSignatures(TypeCheckState $state, array $functions): void
{
    do {
        $materialized = false;
        foreach ($functions as $fn) {
            if (! needsInferredSignature($fn)) {
                continue;
            }

            $discovered = discoverFunctionConstraints($state, $fn);
            if ($discovered === null || $discovered['constraints'] === []) {
                continue;
            }

            materializeInferredSignature(
                $state,
                $fn,
                $discovered['type'],
                $discovered['constraints'],
            );
            registerInferredSignatureScheme($state, $fn);
            $materialized = true;
        }
    } while ($materialized);

    materializeDictionaryFreeSignatures($state, $functions);
}

/**
 * Give the declarations that need no dictionaries the type the probe found.
 *
 * A call site can only use such a declaration once its type is known. While it
 * is a bare placeholder its variables are unrelated to each other, so `k :
 * findIndicesGo p xs (0 :: ?)` cannot tell that the counter is the list's index
 * type and the literal is defaulted instead of unified -- which left
 * `findIndices` passing an `Integer` into a function whose parameter the body
 * uses as `Int`. Every declaration that gains dictionaries is materialized
 * above, so what the probe reports here is final.
 *
 * @param list<Ast\FunctionDecl> $functions
 */
function materializeDictionaryFreeSignatures(TypeCheckState $state, array $functions): void
{
    foreach ($functions as $fn) {
        if (! needsInferredSignature($fn)) {
            continue;
        }

        $discovered = discoverFunctionConstraints($state, $fn);
        if ($discovered === null || ($discovered['deferredOverRestricted'] ?? false)) {
            continue;
        }

        materializeInferredSignature(
            $state,
            $fn,
            $discovered['type'],
            $discovered['constraints'],
        );
        registerInferredSignatureScheme($state, $fn);
    }
}

/**
 * Whether a declaration is still waiting for the signature the probe discovers:
 * one the user did not spell out, that is not a pattern binding (the
 * monomorphism restriction settles those, not generalization), and that no
 * earlier walk has already materialized.
 */
function needsInferredSignature(Ast\FunctionDecl $fn): bool
{
    return ! Ast\hasDeclaredSignature($fn)
        && $fn->inferredSignatureType === null
        && ! isRestrictedDeclaration($fn);
}

/**
 * Infer a signature-less function's body once, only to find out which
 * constraints its own type carries.
 *
 * The body is typed against throwaway variables and the substitution is rolled
 * back: the caller re-checks the body with the constraints in scope, so this run
 * must leave nothing behind but the answer. Pending constraints are cleared for
 * the same reason -- they are re-recorded by the real pass.
 *
 * @return ?array{type: Type, constraints: list<Ast\PendingConstraint>}
 *   null only when the probe cannot see the body's type at all
 */
function discoverFunctionConstraints(TypeCheckState $state, Ast\FunctionDecl $fn): ?array
{
    $savedSubst = $state->subst;
    $savedHoles = $state->holes;
    // Those probe variables are rolled back with the substitution, so their names can be
    // handed out again and reported types do not shift.
    $savedFresh = $state->fresh;
    $savedMethods = [
        $state->constraintMethods,
        $state->constraintMethodAmbiguities,
        $state->constraintMethodCandidates,
    ];
    $savedSpan = $state->declSpan;
    $savedAmbient = installAmbientConstraints($state, []);
    // The probe checks this declaration's body, so a diagnostic it raises belongs
    // to the declaration: parts of a desugared body (the `case` matching function
    // clauses) carry no position of their own and are reported at the span.
    $state->declSpan = [
        'line' => $fn->line,
        'col' => $fn->col,
        'endCol' => $fn->endCol,
    ];
    $state->constraintMethods = [];
    $state->constraintMethodAmbiguities = [];
    $state->constraintMethodCandidates = [];

    try {
        $bodyEnv = $state->env;
        $fnType = freshType($state);
        foreach (array_reverse($fn->params) as $_) {
            $fnType = new TArrow(freshType($state), $fnType);
        }

        $expected = $fnType;
        foreach ($fn->params as $param) {
            if (! $expected instanceof TArrow) {
                throw typeFail($state, "function `{$fn->name}` has too many parameters", $fn);
            }
            [$_, $bodyEnv] = bindPattern($state, $param, $expected->from, $bodyEnv, generalize: false);
            $expected = $expected->to;
        }

        // The self reference carries no dictionaries here: this run only has to
        // find out which ones the function needs, and adding them later would
        // give the recursive call site a dictionary argument it does not have.
        $bodyEnv[$fn->name] = scheme($fnType, [], [], 0);
        $bodyType = inferExpr($state, $fn->body, $bodyEnv);
        unify($state, $bodyType, $expected, $fn->body);

        $found = inferredFunctionConstraints($state, $fn->body, $fnType);
        $constraints = \array_values(\array_filter(
            $found,
            static fn (Ast\PendingConstraint $constraint): bool => !constraintOverRestrictedVar($state, $constraint),
        ));

        return [
            // Pruned against this run's substitution, so the caller can turn the
            // type into a signature without the variables that have been solved.
            'type' => prune($state, $fnType),
            'constraints' => refreshConstraintArgs($state, $constraints),
            // A constraint dropped because it belongs to a restricted declaration's open
            // variable: the declaration's type is not its own to name (`targetFunction`).
            'deferredOverRestricted' => $constraints === [] && $found !== [],
        ];
    } finally {
        restoreAmbientConstraints($state, $savedAmbient);
        [
            $state->constraintMethods,
            $state->constraintMethodAmbiguities,
            $state->constraintMethodCandidates,
        ] = $savedMethods;
        $state->subst = $savedSubst;
        $state->holes = $savedHoles;
        $state->fresh = $savedFresh;
        $state->declSpan = $savedSpan;
        clearPendingConstraintsDeep($fn->body);
        // ...and the types it annotated the body with: they belong to the
        // substitution that was just rolled back (`clearInferredAnnotationsDeep`).
        clearInferredAnnotationsDeep($fn->body);
    }
}

/**
 * The user-written parameters of a function, without the dictionary parameters
 * an earlier check of the same declaration prepended.
 *
 * @return array<int, Ast\AstNode>
 */
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

function userParamsOf(Ast\FunctionDecl $fn): array
{
    $params = $fn->params;
    while ($params !== []
        && $params[0] instanceof Ast\PatVar
        && \str_starts_with($params[0]->name, '__ev_')) {
        array_shift($params);
    }

    return array_values($params);
}

/**
 * Turn the constraints found in a signature-less body into a real signature on
 * the declaration, so the rest of the checker treats it like one the user wrote:
 * `g x = show x` becomes `g :: Show a => a -> String`.
 *
 * The discovered variables are renamed to readable letters, and the constraint
 * arguments are renamed with them, keeping each argument tied to the type it was
 * inferred from.
 *
 * @param list<Ast\PendingConstraint> $constraints
 */
function materializeInferredSignature(
    TypeCheckState $state,
    Ast\FunctionDecl $fn,
    Type $userType,
    array $constraints,
): void {
    $args = [];
    foreach ($constraints as $constraint) {
        foreach ($constraint->args as $arg) {
            $args[] = $arg;
        }
    }

    $rename = friendlyTypeVarNames([$userType, ...$args]);
    if ($rename !== []) {
        $mapping = [];
        foreach ($rename as $from => $to) {
            $mapping[$from] = new TVar($to);
        }
        $userType = substitute($userType, $mapping);
        $constraints = \array_map(
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
            $constraints,
        );
    }

    $known = \array_flip(knownTypeConstructorNames($state));
    $toSignatureType = static fn (Type $type): Ast\TypeNode => signatureTypeAst(
        $type,
        $known,
    );

    $constraintNodes = [];
    foreach ($constraints as $constraint) {
        $constraintNodes[] = new Ast\TypeApp(
            new Ast\TypeCon($constraint->class),
            \array_map($toSignatureType, $constraint->args),
        );
    }

    // A declaration that needs no dictionaries carries the type itself: there is
    // nothing to constrain, and ` => ` around it would only be noise in every
    // later reader of the signature (`splitTypeAst` reads both shapes).
    $fn->inferredSignatureType = $constraintNodes === []
        ? $toSignatureType($userType)
        : new Ast\TypeConstrained(
            $constraintNodes,
            $toSignatureType($userType),
            $fn->line,
            $fn->col,
            $fn->endCol,
        );
    $fn->typeInferred = true;
}

/**
 * An inferred type as a signature spells it. A name that is not a known type
 * constructor is a higher-kinded parameter -- the checker carries those as type
 * constructors (`Foldable f` is `TCon('f')`, applied as `TCon('f', [a])`) -- and
 * becomes a `TypeVar` here, so re-elaborating the signature yields the variable
 * again instead of applying an unknown constructor.
 *
 * @param array<string, int> $known type constructors in scope
 * @param array<string, Type> $subst
 */
function signatureTypeAst(Type $type, array $known, array $subst = []): Ast\TypeNode
{
    if ($type instanceof TCon) {
        $head = isset($known[$type->name]) || isInternalTypeConName($type->name)
            ? new Ast\TypeCon($type->name)
            : new Ast\TypeVar($type->name);
        if ($type->args === []) {
            return $head;
        }

        return new Ast\TypeApp(
            $head,
            \array_map(
                static fn (Type $arg): Ast\TypeNode => signatureTypeAst($arg, $known, $subst),
                $type->args,
            ),
        );
    }

    if ($type instanceof TArrow) {
        return new Ast\TypeArrow(
            signatureTypeAst($type->from, $known, $subst),
            signatureTypeAst($type->to, $known, $subst),
        );
    }

    return internalTypeToAst($type, $subst);
}

/** Names the checker owns, which no signature would ever quantify over. */
function isInternalTypeConName(string $name): bool
{
    return \str_starts_with($name, '__Dict_') || \str_ends_with($name, '#');
}

/**
 * The constraints a function without a signature has to abstract: the pending
 * constraints left in its body that mention a type variable occurring in the
 * function's own type.
 *
 * Constraints over a head that is already known (`Show Integer` after a literal
 * has been pinned) are left alone -- they are resolved against the instance,
 * not turned into a parameter. A variable the function's type never mentions
 * cannot be generalized at all, so its constraint is left for the unresolved
 * constraint report.
 *
 * @return list<Ast\PendingConstraint>
 */
function inferredFunctionConstraints(TypeCheckState $state, Ast\AstNode $body, Type $fnType): array
{
    $pending = pendingConstraintsDeep($body, $state);
    if ($pending === []) {
        return [];
    }

    $known = \array_flip(knownTypeConstructorNames($state));
    $ownVars = signatureVarsInType(prune($state, $fnType), $known);
    $abstract = [];
    foreach ($pending as $constraint) {
        foreach ($constraint->args as $arg) {
            foreach (signatureVarsInType(prune($state, $arg), $known) as $name => $_) {
                if (isset($ownVars[$name])) {
                    $abstract[] = $constraint;
                    continue 3;
                }
            }
        }
    }

    return $abstract;
}

/**
 * Every variable a signature could quantify over: type variables, and the names
 * standing for higher-kinded parameters -- an unbound `Functor f` is carried as
 * a type constructor until something pins it down (`TCon('f', [arg])` applied,
 * `TCon('f')` bare), and it is a variable in both shapes.
 *
 * @param array<string, int> $known
 * @return array<string, true>
 */
function signatureVarsInType(Type $type, array $known): array
{
    if ($type instanceof TVar) {
        return [$type->name => true];
    }

    if ($type instanceof TArrow) {
        return [
            ...signatureVarsInType($type->from, $known),
            ...signatureVarsInType($type->to, $known),
        ];
    }

    if (!$type instanceof TCon) {
        return [];
    }

    $variables = isset($known[$type->name]) ? [] : [$type->name => true];
    foreach ($type->args as $arg) {
        $variables += signatureVarsInType($arg, $known);
    }

    return $variables;
}

/**
 * Rewrite saturated applications of imported `#` primops into IntrinsicCall
 * nodes named by the IR/backend id (`intAdd# a b` → `intrinsic intAdd# a b`).
 */
function rewriteIntrinsicApplies(Ast\AstNode $expr): Ast\AstNode
{
    if ($expr instanceof Ast\Variable || $expr instanceof Ast\QualifiedRef) {
        $wrapper = $expr->intrinsicWrapper;
        if ($wrapper !== null) {
            $internal = resolveIntrinsicName($wrapper) ?? $wrapper;
            $schemes = typeSchemes();
            $scheme = $schemes[$internal] ?? null;
            if ($scheme !== null && schemeArity($scheme) === 0) {
                $call = new Ast\IntrinsicCall($internal, [], $expr->line, $expr->col, $expr->endCol);
                $call->intrinsicId = $internal;
                $call->inferredType = $expr->inferredType;
                $call->pendingConstraints = $expr->pendingConstraints;

                return $call;
            }
        }

        return $expr;
    }

    if ($expr instanceof Ast\Apply) {
        $expr->function = rewriteIntrinsicApplies($expr->function);
        $expr->argument = rewriteIntrinsicApplies($expr->argument);

        $parts = flattenApplyForIntrinsicWrapper($expr);
        $callee = $parts['function'];
        $wrapper = null;
        if ($callee instanceof Ast\Variable || $callee instanceof Ast\QualifiedRef) {
            $wrapper = $callee->intrinsicWrapper;
        }
        if ($wrapper === null && $expr->intrinsicWrapper !== null) {
            $wrapper = $expr->intrinsicWrapper;
        }
        if ($wrapper !== null) {
            $internal = resolveIntrinsicName($wrapper) ?? $wrapper;
            $schemes = typeSchemes();
            $scheme = $schemes[$internal] ?? null;
            if ($scheme !== null
                && count($parts['args']) === schemeArity($scheme)
            ) {
                $call = new Ast\IntrinsicCall($internal, $parts['args'], $expr->line, $expr->col, $expr->endCol);
                $call->intrinsicId = $internal;
                $call->inferredType = $expr->inferredType;
                $call->pendingConstraints = $expr->pendingConstraints;

                return $call;
            }
        }

        return $expr;
    }

    if ($expr instanceof Ast\IntrinsicCall) {
        $internal = resolveIntrinsicName($expr->name) ?? $expr->name;
        $expr->name = $internal;
        $expr->intrinsicId = $internal;
        foreach ($expr->args as $i => $arg) {
            $expr->args[$i] = rewriteIntrinsicApplies($arg);
        }

        return $expr;
    }

    if ($expr instanceof Ast\Lambda) {
        $expr->body = rewriteIntrinsicApplies($expr->body);

        return $expr;
    }

    if ($expr instanceof Ast\Let) {
        foreach ($expr->bindings as $binding) {
            $binding->value = rewriteIntrinsicApplies($binding->value);
        }
        $expr->body = rewriteIntrinsicApplies($expr->body);

        return $expr;
    }

    if ($expr instanceof Ast\Where) {
        foreach ($expr->bindings as $binding) {
            $binding->value = rewriteIntrinsicApplies($binding->value);
        }
        $expr->expr = rewriteIntrinsicApplies($expr->expr);

        return $expr;
    }

    if ($expr instanceof Ast\CaseExpr) {
        $expr->scrutinee = rewriteIntrinsicApplies($expr->scrutinee);
        foreach ($expr->alts as $alt) {
            $alt->body = rewriteIntrinsicApplies($alt->body);
        }

        return $expr;
    }

    if ($expr instanceof Ast\GuardsExpr) {
        foreach ($expr->clauses as $clause) {
            $clause->guard = rewriteIntrinsicApplies($clause->guard);
            $clause->body = rewriteIntrinsicApplies($clause->body);
        }

        return $expr;
    }

    if ($expr instanceof Ast\DoExpr) {
        if ($expr->desugared !== null) {
            $expr->desugared = rewriteIntrinsicApplies($expr->desugared);
        }

        return $expr;
    }

    if ($expr instanceof Ast\Infix) {
        $expr->left = rewriteIntrinsicApplies($expr->left);
        $expr->right = rewriteIntrinsicApplies($expr->right);

        return $expr;
    }

    if ($expr instanceof Ast\Tuple) {
        foreach ($expr->elements as $i => $el) {
            $expr->elements[$i] = rewriteIntrinsicApplies($el);
        }

        return $expr;
    }

    if ($expr instanceof Ast\ListLit) {
        foreach ($expr->elements as $i => $el) {
            $expr->elements[$i] = rewriteIntrinsicApplies($el);
        }

        return $expr;
    }

    if ($expr instanceof Ast\TypeAsc) {
        $expr->expr = rewriteIntrinsicApplies($expr->expr);

        return $expr;
    }

    if ($expr instanceof Ast\RecordCon) {
        foreach ($expr->fields as $field) {
            $field->expr = rewriteIntrinsicApplies($field->expr);
        }

        return $expr;
    }

    if ($expr instanceof Ast\RecordUpdate) {
        $expr->object = rewriteIntrinsicApplies($expr->object);
        foreach ($expr->fields as $field) {
            $field->expr = rewriteIntrinsicApplies($field->expr);
        }

        return $expr;
    }

    if ($expr instanceof Ast\FieldAccess) {
        $expr->object = rewriteIntrinsicApplies($expr->object);

        return $expr;
    }

    return $expr;
}

/**
 * Mark `Main.main` as EntryPointKind::Main under Executable purpose only.
 * Library and Repl never promote `main` to an application entry.
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

    // Only `Main.main` is the program entry; elsewhere `main` is ordinary.
    if ($moduleName !== 'Main') {
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

    if (count($mainIndexes) > 1) {
        throw typeFail($state, 'duplicate entry point `main`', $items[$mainIndexes[1]]);
    }

    $index = $mainIndexes[0];
    $main = $items[$index];

    if (!$main instanceof Ast\FunctionDecl) {
        return;
    }

    if ($main->type === null) {
        return;
    }

    [$constraintAsts, $bodyTypeAst] = splitTypeAst($main->type);
    $resultType = astType($state, $bodyTypeAst);

    if (!isIoTypeCon($state, $resultType)) {
        return;
    }

    if ($constraintAsts !== []) {
        throw typeFail($state, 'entry point `main` may not have constraints', $main);
    }

    $evidenceParams = count($main->constraints);
    if (count($main->params) > $evidenceParams) {
        throw typeFail($state, 'entry point `main` must not take parameters', $main);
    }

    $main->entryKind = EntryPointKind::Main;
    $items[$index] = $main;
}

/**
 * `f x y = primop# x y` (and the nullary alias `f = primop#`) is a trivial
 * wrapper around a primop: every use site can be rewritten to the intrinsic.
 *
 * The callee is recognised either by its resolved intrinsic wrapper (set while
 * inferring the body) or directly by name against the intrinsic registry — the
 * latter is what lets a *signatured* definition like `fix :: (a -> a) -> a;
 * fix = fix#` be recognised while its scheme is registered, i.e. before its
 * body has been inferred. User functions can never carry a `#` name, so the
 * name test can only match real intrinsics.
 */
function trivialIntrinsicWrapper(Ast\FunctionDecl $fn): ?string
{
    $body = $fn->body;
    $parts = $body instanceof Ast\IntrinsicCall
        ? ['function' => $body, 'args' => $body->args]
        : flattenApplyForIntrinsicWrapper($body);
    $callee = $parts['function'];

    $internalId = null;
    if ($callee instanceof Ast\IntrinsicCall) {
        $internalId = resolveIntrinsicName($callee->name)
            ?? $callee->name;
    } elseif ($callee instanceof Ast\Variable || $callee instanceof Ast\QualifiedRef) {
        $internalId = $callee->intrinsicWrapper
            ?? resolveIntrinsicName($callee->name);
    }

    // Inlining is fine for pure primops, but not for ones whose lowering embeds the call
    // site (error, throw): those must stay real functions.
    if ($internalId !== null && capturesCallSite($internalId)) {
        return null;
    }

    if ($internalId === null) {
        return null;
    }

    $params = [];
    foreach ($fn->params as $param) {
        $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;
        if (!$pattern instanceof Ast\PatVar) {
            return null;
        }
        $params[] = $pattern->name;
    }

    if (count($params) !== count($parts['args'])) {
        return null;
    }

    foreach ($parts['args'] as $i => $arg) {
        if (!$arg instanceof Ast\Variable || $arg->name !== $params[$i]) {
            return null;
        }
    }

    return $internalId;
}

/** @return array{function: Ast\AstNode, args: list<Ast\AstNode>} */
function flattenApplyForIntrinsicWrapper(Ast\AstNode $expr): array
{
    $args = [];
    while ($expr instanceof Ast\Apply) {
        $args[] = $expr->argument;
        $expr = $expr->function;
    }
    // Collect then reverse: array_unshift in a loop is O(n²) on long spines.
    if ($args !== []) {
        $args = array_reverse($args);
    }

    return ['function' => $expr, 'args' => $args];
}

function inferExpr(TypeCheckState $state, Ast\AstNode &$expr, array $env): Type
{
    $type = match ($expr::class) {
        Ast\IntegerLit::class => inferIntegerLit($state, $expr),
        Ast\DoubleLit::class => new TDouble(),
        Ast\StringLit::class => new TStr(),
        Ast\CharLit::class => new TChar(),
        Ast\Variable::class => inferVariable($state, $expr, $env),
        Ast\ConstructorRef::class => instantiate($state, $env[$expr->name] ?? throw typeFail(
            $state,
            appendDidYouMean(
                "undefined constructor `{$expr->name}`",
                $expr->name,
                constructorCandidateNames($env),
            ),
            $expr,
        )),
        Ast\OperatorRef::class => inferOperatorRef($state, $expr, $env),
        Ast\Apply::class => inferApply($state, $expr, $env),
        Ast\Infix::class => inferInfix($state, $expr, $env),
        Ast\Tuple::class => inferTuple($state, $expr, $env),
        Ast\ListLit::class => inferList($state, $expr, $env),
        Ast\Lambda::class => inferLambda($state, $expr, $env),
        Ast\Let::class => inferLet($state, $expr, $env),
        Ast\Where::class => inferWhere($state, $expr, $env),
        Ast\CaseExpr::class => inferCase($state, $expr, $env),
        Ast\GuardsExpr::class => inferGuards($state, $expr, $env),
        Ast\DoExpr::class => inferDoExpr($state, $expr, $env),
        Ast\RecordCon::class => inferRecordCon($state, $expr, $env),
        Ast\FieldAccess::class => inferFieldAccess($state, $expr, $env),
        Ast\RecordUpdate::class => inferRecordUpdate($state, $expr, $env),
        Ast\QualifiedRef::class => inferQualifiedRef($state, $expr, $env),
        Ast\TypeAsc::class => inferTypeAsc($state, $expr, $env),
        Ast\ExprHole::class => inferHole($state, $expr),
        Ast\IntrinsicCall::class => inferIntrinsic($state, $expr, $env),
        Ast\ForeignCall::class => inferForeignCall($state, $expr, $env),
        Ast\EvidenceRef::class => new TCon('__Dict_' . $expr->class),
        Ast\EvidenceMethod::class => inferEvidenceMethod($state, $expr),
        default => throw typeFail($state, \sprintf("unsupported expression `%s`", $expr::class), $expr),
    };

    annotateExprType($state, $expr, $type);

    return $type;
}

function inferHole(TypeCheckState $state, Ast\ExprHole $expr): Type
{
    $type = freshType($state);
    $state->holes[] = $expr;

    return $type;
}

/**
 * After the body's unifications are zonked, reject any typed holes with their
 * refined types (`_` holes).
 */
function reportTypedHoles(TypeCheckState $state): void
{
    if ($state->holes === []) {
        return;
    }

    $hole = $state->holes[0];
    $type = $hole->inferredType !== null
        ? inferredTypeAstToInternal($hole->inferredType)
        : freshType($state);
    $type = prune($state, $type);
    $rename = friendlyTypeVarNames([$type]);
    $typeStr = typeToString($type, $rename);

    $err = typeFail(
        $state,
        'Found hole with type: `' . $typeStr . '`',
        $hole,
    );
    $err->diagnosticCode = 'hole';
    $err->holeType = $typeStr;
    // Extra holes (same function) as related info — CLI still fails on first.
    if (count($state->holes) > 1) {
        $related = [];
        for ($i = 1, $n = count($state->holes); $i < $n; $i++) {
            $h = $state->holes[$i];
            $ht = $h->inferredType !== null
                ? typeToString(prune($state, inferredTypeAstToInternal($h->inferredType)), $rename)
                : '?';
            $related[] = [
                'message' => "also hole with type: `{$ht}`",
                'filename' => $state->filename,
                'line' => (int) ($h->line ?? 0),
                'col' => (int) ($h->col ?? 0),
                'endCol' => (int) (($h->endCol ?? 0) ?: ($h->col ?? 0)),
            ];
        }
        $err->relatedLocations = $related;
    }
    throw $err;
}

function inferDoExpr(TypeCheckState $state, Ast\DoExpr $expr, array $env): Type
{
    // Single path: do → >>= → (later) strict_io_normalize. Concrete IO no longer
    // has a separate typer; Monad IO is an ordinary instance.
    $desugared = desugarDo($expr->stmts);
    $type = inferExpr($state, $desugared, $env);
    $expr->desugared = $desugared;

    return $type;
}

function annotateExprType(TypeCheckState $state, Ast\AstNode $expr, Type $type): void
{
    $pruned = prune($state, $type);
    $expr->inferredType = internalTypeToAst($pruned, $state->subst);
}

function inferTypeAsc(TypeCheckState $state, Ast\TypeAsc $expr, array $env): Type
{
    $exprType = inferExpr($state, $expr->expr, $env);
    $annotType = astType($state, $expr->type);
    unify($state, $exprType, $annotType, $expr);

    return prune($state, $annotType);
}

function inferVariable(TypeCheckState $state, Ast\AstNode &$expr, array $env): Type
{
    if (!$expr instanceof Ast\Variable) {
        throw typeFail($state, 'expected variable', $expr);
    }

    if (isset($state->constraintMethodAmbiguities[$expr->name])) {
        throw typeFail($state, "ambiguous class method `{$expr->name}`", $expr);
    }

    if (isset($state->constraintMethods[$expr->name])) {
        $info = $state->constraintMethods[$expr->name];
        applyEvidenceMethodInfo($state, $expr, $info);
        $instantiated = freshenTypeWithConstraints(
            $state,
            $info['type'],
            $info['userConstraints'] ?? [],
        );
        if ($instantiated['constraints'] !== []) {
            $expr->pendingConstraints = $instantiated['constraints'];
        }

        return $instantiated['type'];
    }

    if (isset($state->classMethodAmbiguities[$expr->name])) {
        throw typeFail($state, \sprintf("ambiguous class method `%s`", $expr->name), $expr);
    }

    if (isset($state->ambiguousImports[$expr->name])) {
        throw typeFail($state, ambiguousImportMessage($expr->name, $state->ambiguousImports[$expr->name]), $expr);
    }

    $scheme = $env[$expr->name] ?? throw typeFail(
        $state,
        appendDidYouMean(
            \sprintf("undefined variable `%s`", $expr->name),
            $expr->name,
            \array_keys($env),
        ),
        $expr,
    );
    if (isset($state->intrinsicWrappers[$expr->name])) {
        $expr->intrinsicWrapper = $state->intrinsicWrappers[$expr->name];
    } else if (isset($env[$expr->name]) && resolveIntrinsicName($expr->name) !== null) {
        // A class default body re-checked outside its module still sees its primops: scope is
        // what makes a primop visible, so being in `$env` is the whole test.
        $expr->intrinsicWrapper = $expr->name;
    }
    if ($scheme->binderId !== null) {
        $expr->binderId = $scheme->binderId;
    }
    if (isset($state->externalFns[$expr->name])) {
        $expr->resolvedOrigin = $state->externalFns[$expr->name];
    } elseif ($state->currentModule !== null) {
        $expr->resolvedOrigin = resolvedSymbol($state->currentModule, $expr->name);
    }
    $instantiated = instantiateScheme($state, $scheme);
    if ($instantiated['constraints'] !== []) {
        $expr->pendingConstraints = $instantiated['constraints'];
    }
    return $instantiated['type'];
}

function inferOperatorRef(TypeCheckState $state, Ast\AstNode &$expr, array $env): Type
{
    if (!$expr instanceof Ast\OperatorRef) {
        throw typeFail($state, 'expected operator_ref', $expr);
    }

    // Parenthesized constructor operators `(:|)` are ordinary constructors.
    if (isConstructorOperator($expr->name) && lookupConstructorMeta($state, $expr->name) !== null) {
        $expr = new Ast\ConstructorRef($expr->name);

        return inferExpr($state, $expr, $env);
    }

    if (isset($state->constraintMethodAmbiguities[$expr->name])) {
        throw typeFail($state, "ambiguous class method `{$expr->name}`", $expr);
    }

    if (isset($state->constraintMethods[$expr->name])) {
        $info = $state->constraintMethods[$expr->name];
        applyEvidenceMethodInfo($state, $expr, $info);
        $instantiated = freshenTypeWithConstraints(
            $state,
            $info['type'],
            $info['userConstraints'] ?? [],
        );
        if ($instantiated['constraints'] !== []) {
            $expr->pendingConstraints = $instantiated['constraints'];
        }

        return $instantiated['type'];
    }

    if (isset($state->classMethodAmbiguities[$expr->name])) {
        throw typeFail($state, "ambiguous class method `{$expr->name}`", $expr);
    }

    if (isset($state->ambiguousImports[$expr->name])) {
        throw typeFail($state, ambiguousImportMessage($expr->name, $state->ambiguousImports[$expr->name]), $expr);
    }

    $scheme = $env[$expr->name] ?? throw typeFail(
        $state,
        appendDidYouMean(
            "undefined operator `{$expr->name}`",
            $expr->name,
            operatorCandidateNames($env, $state),
        ),
        $expr,
    );

    if (isset($state->externalFns[$expr->name])) {
        $expr->resolvedOrigin = $state->externalFns[$expr->name];
    } elseif ($state->currentModule !== null) {
        $expr->resolvedOrigin = resolvedSymbol($state->currentModule, $expr->name);
    }

    $instantiated = instantiateScheme($state, $scheme);
    if ($instantiated['constraints'] !== []) {
        $expr->pendingConstraints = $instantiated['constraints'];
    }

    return $instantiated['type'];
}

/** @param array<string, array<string, mixed>> $env @return list<string> */
function constructorCandidateNames(array $env): array
{
    $names = [];
    foreach (\array_keys($env) as $name) {
        if (isConstructorName($name)) {
            $names[] = $name;
        }
    }

    return $names;
}

/** @param array<string, array<string, mixed>> $env */
function operatorCandidateNames(array $env, TypeCheckState $state): array
{
    $names = [];
    foreach ([...\array_keys($env), ...\array_keys($state->constraintMethods)] as $name) {
        if ($name !== '' && !ctype_alpha($name[0]) && $name[0] !== '_') {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

function inferEvidenceMethod(TypeCheckState $state, Ast\AstNode &$expr): Type
{
    if (!$expr instanceof Ast\EvidenceMethod) {
        throw typeFail($state, 'expected evidence_method', $expr);
    }

    if (isset($state->constraintMethodAmbiguities[$expr->method])) {
        throw typeFail($state, "ambiguous class method `{$expr->method}`", $expr);
    }

    $info = $state->constraintMethods[$expr->method] ?? null;
    if ($info !== null && $expr->class === $info['class']) {
        applyEvidenceMethodInfo($state, $expr, $info);
        $instantiated = freshenTypeWithConstraints(
            $state,
            $info['type'],
            $info['userConstraints'] ?? [],
        );
        if ($instantiated['constraints'] !== []) {
            $expr->pendingConstraints = $instantiated['constraints'];
        }

        return $instantiated['type'];
    }

    $classInfo = $state->classes[$expr->class] ?? throw typeFail(
        $state,
        appendDidYouMean(
            "unknown class `{$expr->class}`",
            $expr->class,
            \array_keys($state->classes),
        ),
        $expr,
    );

    $methodInfo = $classInfo['methods'][$expr->method];
    $userConstraints = $methodInfo['userConstraints'] ?? [];
    $instantiated = freshenTypeWithConstraints(
        $state,
        peelDictArrows($methodInfo['type'], count($userConstraints)),
        $userConstraints,
    );
    if ($instantiated['constraints'] !== []) {
        $expr->pendingConstraints = $instantiated['constraints'];
    }

    return $instantiated['type'];
}

function inferApply(TypeCheckState $state, Ast\Apply $expr, array $env): Type
{
    $fnType = prune($state, inferMethodByArgument($state, $expr, $env));
    $argType = prune($state, inferExpr($state, $expr->argument, $env));

    // A dictionary handed over as an explicit argument. A call to a constrained
    // function resolves its evidence by inserting the dictionary at the call
    // site, and that rewritten body is inferred again whenever the enclosing
    // declaration is checked more than once (a module is inferred more than
    // once, and the rewrite is part of the body). Dictionaries are erased at run
    // time and never show up in a type, so consuming one leaves the callee's
    // type as it is -- the callee's own arrows are the ones that remain.
    if ($argType instanceof TCon && \str_starts_with($argType->name, '__Dict_')) {
        return $fnType;
    }

    // Peel the arrow directly instead of `unify(fn, arg -> ρ)`. Unifying whole
    // arrows runs `normalizeType` on both sides and can reduce `Rep Foo x` to a
    // concrete M1-tree, which then fails to unify with polymorphic `Rep a b`
    // from class methods like `to`/`from`.
    if ($fnType instanceof TVar) {
        $result = freshType($state);
        unify($state, $fnType, new TArrow($argType, $result), $expr);
        $pending = pendingConstraintsFromExpr($expr->function);
        if ($pending !== []) {
            $constraints = expandConstraintsWithSuperclasses(
                $state,
                refreshConstraintArgs($state, $pending),
            );
            if (constraintsResolvable($state, $constraints)) {
                clearPendingConstraints($expr);
                $expr = prependEvidenceAtRoot($state, $expr, resolveConstraintEvidence($state, $constraints, $expr));
            } else {
                $expr->pendingConstraints = $pending;
            }
        }
        propagateCallMetadata($expr);

        return $result;
    }

    $fnType = normalizeType($state, $fnType, reduceFamilies: false);
    if (!$fnType instanceof TArrow) {
        // Fall back to full-arrow unify so non-function heads keep the familiar
        // `could not unify τ with σ -> ρ` diagnostic (see Bad-Letpoly-Captured-Var).
        $result = freshType($state);
        unify($state, $fnType, new TArrow($argType, $result), $expr);
        $pending = pendingConstraintsFromExpr($expr->function);
        if ($pending !== []) {
            $constraints = expandConstraintsWithSuperclasses(
                $state,
                refreshConstraintArgs($state, $pending),
            );
            if (constraintsResolvable($state, $constraints)) {
                clearPendingConstraints($expr);
                $expr = prependEvidenceAtRoot($state, $expr, resolveConstraintEvidence($state, $constraints, $expr));
            } else {
                $expr->pendingConstraints = $pending;
            }
        }
        propagateCallMetadata($expr);

        return $result;
    }

    unify($state, $fnType->from, $argType, $expr);
    $result = $fnType->to;

    $pending = pendingConstraintsFromExpr($expr->function);
    if ($pending !== []) {
        $constraints = expandConstraintsWithSuperclasses(
            $state,
            refreshConstraintArgs($state, $pending),
        );
        if (constraintsResolvable($state, $constraints)) {
            clearPendingConstraints($expr);
            $expr = prependEvidenceAtRoot($state, $expr, resolveConstraintEvidence($state, $constraints, $expr));
        } else {
            $expr->pendingConstraints = $pending;
        }
    }

    propagateCallMetadata($expr);

    return $result;
}

function propagateCallMetadata(Ast\Apply $expr): void
{
    $callee = rootCalleeExpr($expr);
    if (property_exists($callee, 'intrinsicWrapper') && $callee->intrinsicWrapper !== null) {
        $expr->intrinsicWrapper = $callee->intrinsicWrapper;
    }
    if (property_exists($callee, 'intrinsicId') && $callee->intrinsicId !== null) {
        $expr->intrinsicId = $callee->intrinsicId;
    }
}

function rootCalleeExpr(Ast\AstNode $expr): Ast\AstNode
{
    while ($expr instanceof Ast\Apply) {
        $expr = $expr->function;
    }

    return $expr;
}

function resolvePendingEvidenceInExpr(TypeCheckState $state, Ast\AstNode &$expr): void
{
    match ($expr::class) {
        Ast\Apply::class => (static function () use ($state, $expr): void {
            // Resolve at the top of the application spine *first*: inferApply
            // copies pendingConstraints onto every apply node, so resolving
            // bottom-up would let an outer node's stale copy prepend evidence a
            // second time. Clearing the whole spine here prevents that.
            tryResolveApplyEvidence($state, $expr);
            resolvePendingEvidenceInExpr($state, $expr->function);
            resolvePendingEvidenceInExpr($state, $expr->argument);
            tryResolveValueEvidence($state, $expr->argument);
        })(),
        Ast\Infix::class => (static function () use ($state, &$expr): void {
            if (! $expr instanceof Ast\Infix) {
                return;
            }

            $left = $expr->left;
            $right = $expr->right;
            resolvePendingEvidenceInExpr($state, $left);
            resolvePendingEvidenceInExpr($state, $right);
            $expr->left = $left;
            $expr->right = $right;
            tryResolveValueEvidence($state, $expr->left);
            tryResolveValueEvidence($state, $expr->right);

            // A symbolic operator whose operand is not a machine integer needs
            // its dictionary, and an operator is just a class method: rewrite it
            // into the call every other method call takes, so the same evidence
            // machinery applies. The node is replaced, not mutated -- the parent
            // sees the call.
            if ($expr->pendingConstraints === []) {
                return;
            }

            $constraints = expandConstraintsWithSuperclasses(
                $state,
                refreshConstraintArgs($state, $expr->pendingConstraints),
            );
            $evidence = tryResolveEvidenceExprs($state, $constraints, $expr);
            if ($evidence === null) {
                return;
            }

            $operator = $expr->operator;
            $expr = prependEvidenceAtRoot(
                $state,
                new Ast\Apply(
                    new Ast\Apply(new Ast\OperatorRef($operator), $expr->left),
                    $expr->right,
                ),
                $evidence,
            );
        })(),
        Ast\IntrinsicCall::class => (static function () use ($state, $expr): void {
            foreach ($expr->args as &$arg) {
                resolvePendingEvidenceInExpr($state, $arg);
                tryResolveValueEvidence($state, $arg);
            }
            unset($arg);
        })(),
        Ast\Lambda::class => (static function () use ($state, $expr): void {
            resolvePendingEvidenceInExpr($state, $expr->body);
            // A lambda whose body is a bare constrained value (`\_ -> mempty`).
            tryResolveValueEvidence($state, $expr->body);
        })(),
        Ast\Let::class => (static function () use ($state, $expr): void {
            foreach ($expr->bindings as $binding) {
                resolvePendingEvidenceInExpr($state, $binding->value);
                // A bare nullary class method (`mempty`, `maxBound`) bound in a
                // let keeps its pending constraint on the reference itself.
                tryResolveValueEvidence($state, $binding->value);
            }
            resolvePendingEvidenceInExpr($state, $expr->body);
            // The body may itself be a bare constrained value (`let go = top in go`).
            tryResolveValueEvidence($state, $expr->body);
        })(),
        Ast\Where::class => (static function () use ($state, $expr): void {
            foreach ($expr->bindings as $binding) {
                resolvePendingEvidenceInExpr($state, $binding->value);
                tryResolveValueEvidence($state, $binding->value);
            }
            resolvePendingEvidenceInExpr($state, $expr->expr);
            // The body may be a bare constrained value (`go where go :: C a => ...`).
            tryResolveValueEvidence($state, $expr->expr);
        })(),
        Ast\CaseExpr::class => (static function () use ($state, $expr): void {
            resolvePendingEvidenceInExpr($state, $expr->scrutinee);
            // A nullary class method as scrutinee is a bare value reference.
            tryResolveValueEvidence($state, $expr->scrutinee);
            foreach ($expr->alts as $alt) {
                resolvePendingEvidenceInExpr($state, $alt->body);
                // An alternative's body may itself be a bare constrained value
                // (`case xs of [] -> mempty; ...`), not just an application.
                tryResolveValueEvidence($state, $alt->body);
            }
        })(),
        Ast\GuardsExpr::class => (static function () use ($state, $expr): void {
            // A guard and the body it selects are elaborated on their own: a
            // constrained value in either (`pure 1` at `IO`, `n <= 0` at a
            // dictionary type) keeps its pending evidence until this walk.
            foreach ($expr->clauses as $clause) {
                resolvePendingEvidenceInExpr($state, $clause->guard);
                tryResolveValueEvidence($state, $clause->guard);
                resolvePendingEvidenceInExpr($state, $clause->body);
                tryResolveValueEvidence($state, $clause->body);
            }
        })(),
        Ast\ListLit::class => (static function () use ($state, $expr): void {
            // List elements that are constrained apps (`showOne x`) keep pending
            // evidence until this walk; skipping them left PHP partials in lists.
            foreach ($expr->elements as &$elem) {
                resolvePendingEvidenceInExpr($state, $elem);
                if ($elem instanceof Ast\Apply) {
                    tryResolveApplyEvidence($state, $elem);
                }
                tryResolveValueEvidence($state, $elem);
            }
            unset($elem);
        })(),
        Ast\Tuple::class => (static function () use ($state, $expr): void {
            foreach ($expr->elements as &$elem) {
                resolvePendingEvidenceInExpr($state, $elem);
                if ($elem instanceof Ast\Apply) {
                    tryResolveApplyEvidence($state, $elem);
                }
                tryResolveValueEvidence($state, $elem);
            }
            unset($elem);
        })(),
        Ast\DoExpr::class => (static function () use ($state, $expr): void {
            if (isset($expr->desugared)) {
                resolvePendingEvidenceInExpr($state, $expr->desugared);
            }
        })(),
        Ast\TypeAsc::class => (static function () use ($state, $expr): void {
            resolvePendingEvidenceInExpr($state, $expr->expr);
            // Nullary class methods used with a type annotation
            // (`mempty :: Min Int`) keep pending constraints on the inner
            // reference; resolve them here (Apply only value-resolves its
            // argument when that argument is already a Variable).
            tryResolveValueEvidence($state, $expr->expr);
        })(),
        default => null,
    };
}

function tryResolveApplyEvidence(TypeCheckState $state, Ast\Apply $expr): void
{
    $pending = pendingConstraintsFromExpr($expr->function);
    if ($pending === []) {
        $pending = $expr->pendingConstraints;
    }

    if ($pending === []) {
        return;
    }

    $constraints = expandConstraintsWithSuperclasses(
        $state,
        refreshConstraintArgs($state, $pending),
    );
    $evidence = tryResolveEvidenceExprs($state, $constraints, $expr);
    if ($evidence === null) {
        return;
    }

    clearPendingConstraints($expr);
    prependEvidenceAtRoot($state, $expr, $evidence);
}

/**
 * Resolve a constrained function that is used as a bare *value* (e.g. passed as
 * a higher-order argument like `foldr maxOfF ...`). Its dictionaries must be
 * supplied as leading arguments so the value has its concrete `a -> a -> ...`
 * type. Only fires for reference nodes that directly carry pending constraints;
 * apply spines are handled by tryResolveApplyEvidence.
 */
function tryResolveValueEvidence(TypeCheckState $state, Ast\AstNode &$expr): void
{
    if (!$expr instanceof Ast\Variable && !$expr instanceof Ast\OperatorRef && !$expr instanceof Ast\QualifiedRef) {
        return;
    }

    if ($expr->pendingConstraints === []) {
        return;
    }

    $constraints = expandConstraintsWithSuperclasses(
        $state,
        refreshConstraintArgs($state, $expr->pendingConstraints),
    );
    $evidence = tryResolveEvidenceExprs($state, $constraints, $expr);
    if ($evidence === null) {
        return;
    }

    $expr->pendingConstraints = [];
    // Same projection path as applied calls: class methods become
    // EvidenceMethod; ordinary constrained functions keep leading dict Applies.
    $expr = prependEvidenceToCall($state, $expr, $evidence);
}

function refreshConstraintArgs(TypeCheckState $state, array $constraints): array
{
    return \array_map(
        static fn (Ast\PendingConstraint $constraint): Ast\PendingConstraint => new Ast\PendingConstraint(
            $constraint->class,
            \array_map(
                static fn (Type $arg): Type => prune($state, $arg),
                $constraint->args,
            ),
            $constraint->evidence,
            $constraint->implicit,
            $constraint->instanceHeadAst,
        ),
        $constraints,
    );
}

/**
 * Operators that codegen may emit as a native PHP or intrinsic binary
 * operation. These keep the fast `infix` lowering path; every other operator
 * is treated as a plain function/method call.
 */
function isPrimitiveInfixOperator(string $op): bool
{
    return \in_array(
        $op,
        ['+', '-', '*', '/', '<', '>', '<=', '>=', '==', '/=', '&&', '||', '<>', ':'],
        true,
    );
}

/**
 * True when `$op` is a type-class method (locally declared or imported).
 * Class-method operators are dispatched through evidence, so they must not be
 * treated as ordinary function operators.
 */
function isClassMethodOperator(TypeCheckState $state, string $op): bool
{
    if (isset($state->classMethodOwners[$op])) {
        return true;
    }

    foreach ($state->classes as $classInfo) {
        if (isset($classInfo['methods'][$op])) {
            return true;
        }
    }

    return false;
}

function inferInfix(TypeCheckState $state, Ast\AstNode &$expr, array $env): Type
{
    if (!$expr instanceof Ast\Infix) {
        throw new \InvalidArgumentException('inferInfix expects an infix expression');
    }

    // An infix the compiler built itself is already resolved: a literal pattern's
    // conjunction is `boolAnd#`, and such a node is built where the module's `&&`
    // is not necessarily in scope.
    if ($expr->compilerIntrinsic !== null) {
        $bool = new TCon('Bool');
        unify($state, inferExpr($state, $expr->left, $env), $bool, $expr);
        unify($state, inferExpr($state, $expr->right, $env), $bool, $expr);
        $expr->resolvedIntrinsic = $expr->compilerIntrinsic;

        return $bool;
    }

    $op = $expr->operator;

    // A module is inferred more than once per process -- a preliminary pass that has no
    // imported values, then the authoritative one -- and both walk the same AST, so a
    // resolution recorded by the earlier pass must be cleared before deciding again.
    $expr->resolvedIntrinsic = null;

    // Infix data constructors lower to ordinary constructor application.
    if (lookupConstructorMeta($state, $op) !== null) {
        $left = $expr->left;
        $right = $expr->right;
        $surface = $expr;
        $expr = new Ast\Apply(
            new Ast\Apply(
                new Ast\ConstructorRef($op),
                $left,
            ),
            $right,
        );

        return inferRewrittenInfix($state, $surface, $expr, $env);
    }

    // Non-primitive class-method operators (`<|>`, `>>=`) need the Apply/evidence path;
    // primitive-shaped ones stay on the fast infix path.
    if (isset($state->constraintMethods[$op])
        || (isClassMethodOperator($state, $op)
            && !isPrimitiveInfixOperator($op))) {
        $left = $expr->left;
        $right = $expr->right;
        $surface = $expr;
        $expr = new Ast\Apply(
            new Ast\Apply(
                new Ast\OperatorRef($op),
                $left,
            ),
            $right,
        );

        return inferRewrittenInfix($state, $surface, $expr, $env);
    }

    // Operators mapping to a host/intrinsic binary op stay on the fast infix path; any
    // other in-scope operator must lower as a call `op a b` with evidence.
    if (!isPrimitiveInfixOperator($op)
        && !isClassMethodOperator($state, $op)
        && (isset($env[$op]) || isset($state->env[$op]))) {
        $left = $expr->left;
        $right = $expr->right;
        $surface = $expr;
        $expr = new Ast\Apply(
            new Ast\Apply(
                new Ast\OperatorRef($op),
                $left,
            ),
            $right,
        );

        return inferRewrittenInfix($state, $surface, $expr, $env);
    }

    $opScheme = $env[$op] ?? $state->env[$op] ?? throw typeFail($state, "undefined operator `{$op}`", $expr);
    // The scheme's own constraints are part of the operator's type: `+` at a
    // variable operand means `Num a`, and the constraint is what carries the
    // operation once `a` is not a machine integer. `instantiate` alone drops it.
    $instantiated = instantiateScheme($state, $opScheme);
    $opType = $instantiated['type'];
    $leftType = inferExpr($state, $expr->left, $env);
    $rightType = inferExpr($state, $expr->right, $env);
    $result = freshType($state);
    unify($state, $opType, new TArrow($leftType, new TArrow($rightType, $result)), $expr);

    $resolved = resolveMonomorphicOperator(
        $op,
        prune($state, $leftType),
        prune($state, $rightType),
    );
    if ($resolved !== null) {
        $expr->resolvedIntrinsic = $resolved;

        return $result;
    }

    $classOperator = isClassMethodOperator($state, $op) || isset($state->constraintMethods[$op]);
    $constraints = $instantiated['constraints'] === []
        ? []
        : expandConstraintsWithSuperclasses(
            $state,
            refreshConstraintArgs($state, $instantiated['constraints']),
        );

    // A still-polymorphic operand dispatches through its dictionary; the host Binop it
    // would otherwise become is an Int/Double assumption. With no dictionary in scope the
    // constraint stays on the node for the declaration to abstract.
    if ($classOperator
        && $constraints !== []
        && isset($state->constraintMethodAmbiguities[$op])
        && operandTypeIsUnresolved($state, $leftType, $rightType)
        && constraintsResolvable($state, $constraints)) {
        $chosen = constraintMethodForOperandTypes(
            $state,
            $op,
            prune($state, $leftType),
            prune($state, $rightType),
        );
        if ($chosen !== null) {
            $savedMethods = $state->constraintMethods;
            $savedAmbiguities = $state->constraintMethodAmbiguities;
            $state->constraintMethods[$op] = $chosen;
            unset($state->constraintMethodAmbiguities[$op]);

            try {
                $left = $expr->left;
                $right = $expr->right;
                $expr = new Ast\Apply(
                    new Ast\Apply(new Ast\OperatorRef($op), $left),
                    $right,
                );

                return inferApply($state, $expr, $env);
            } finally {
                $state->constraintMethods = $savedMethods;
                $state->constraintMethodAmbiguities = $savedAmbiguities;
            }
        }
    }

    if ($classOperator
        && $constraints !== []
        && !isset($state->constraintMethodAmbiguities[$op])
        && operandTypeIsUnresolved($state, $leftType, $rightType)) {
        if (constraintsResolvable($state, $constraints)) {
            $left = $expr->left;
            $right = $expr->right;
            $expr = new Ast\Apply(
                new Ast\Apply(new Ast\OperatorRef($op), $left),
                $right,
            );

            return inferApply($state, $expr, $env);
        }

        $expr->pendingConstraints = $instantiated['constraints'];

        return $result;
    }

    // Integer class-method ops must take the evidence Apply path: an unresolved symbolic
    // Infix is emitted as an Int/Double Binop and unboxed as Int64/Long.
    if (
        (isClassMethodOperator($state, $op) || isset($state->constraintMethods[$op]))
        && (typeIsIntegerLike($state, $leftType)
            || typeIsIntegerLike($state, $rightType)
            || arithmeticNeedsEvidence($state, $op, $leftType, $rightType)
            || semigroupNeedsEvidence($state, $op, $leftType, $rightType)
            || orderingNeedsEvidence($state, $op, $leftType, $rightType)
            || instanceHeadOperand($state, $op, $leftType, $rightType)
            || equalityNeedsEvidence($state, $op, $leftType, $rightType, $constraints))
    ) {
        $left = $expr->left;
        $right = $expr->right;
        $surface = $expr;
        $expr = new Ast\Apply(
            new Ast\Apply(new Ast\OperatorRef($op), $left),
            $right,
        );

        return inferRewrittenInfix($state, $surface, $expr, $env);
    }

    return $result;
}

/**
 * Infer an operator use that was rewritten into a call, and mirror the call's
 * type onto the `Infix` node the rewrite replaced.
 *
 * The rewrite swaps the node in the tree it was handed, but a do block keeps a
 * second handle on the same node: its statement list holds the surface shape
 * while inference runs over the desugared tree. Passes that read the surface
 * tree (the IO boundary pass reads `>>=` there) need a type on the nodes they
 * hold, and the node they hold is the one the rewrite moved away from.
 */
/**
 * The type of a call head, resolving a method name two in-scope dictionaries
 * provide by looking at the argument.
 *
 * `negate` while both `Num a` and `Num b` hold has no single meaning, so the
 * bare name is rejected; `negate n` does have one as soon as `n`'s type is
 * known, and that is the case the argument decides. The candidate whose
 * constraint head *is* the argument's type is the dictionary the call means.
 *
 * Nothing is guessed: an argument whose type does not name one of the heads
 * still leaves the name ambiguous and is reported as before.
 */
function inferMethodByArgument(TypeCheckState $state, Ast\Apply $expr, array $env): Type
{
    $name = $expr->function instanceof Ast\Variable ? $expr->function->name : null;
    if ($name === null || !isset($state->constraintMethodCandidates[$name])) {
        return inferExpr($state, $expr->function, $env);
    }

    $argType = argumentType($state, $expr->argument, $env);
    $chosen = $argType === null ? null : constraintMethodForOperandTypes($state, $name, $argType);
    if ($chosen === null) {
        // Nothing in the argument decides it: a written constraint is what the method's type
        // variable meets, a superclass projection is not (`fromIntegral`).
        $chosen = declaredMethodCandidate($state, $name);
    }
    if ($chosen === null) {
        return inferExpr($state, $expr->function, $env);
    }

    // Resolving the name once, for this call only: the surrounding body keeps
    // seeing it as ambiguous, so a second use has to decide again.
    $savedMethods = $state->constraintMethods;
    $savedAmbiguities = $state->constraintMethodAmbiguities;
    $state->constraintMethods[$name] = $chosen;
    unset($state->constraintMethodAmbiguities[$name]);

    try {
        return inferExpr($state, $expr->function, $env);
    } finally {
        $state->constraintMethods = $savedMethods;
        $state->constraintMethodAmbiguities = $savedAmbiguities;
    }
}

/**
 * The argument's type, when it has one already.
 *
 * A parameter is bound to a scheme before the body is checked, so its type is
 * the rigid one from the signature, which is what a constraint head is compared
 * against. An argument the call itself builds (`negate (x + 1)`) has no type yet
 * and is not consulted.
 */
function argumentType(TypeCheckState $state, Ast\AstNode $argument, array $env): ?Type
{
    if ($argument instanceof Ast\Variable) {
        $scheme = $env[$argument->name] ?? $state->env[$argument->name] ?? null;

        return $scheme instanceof Scheme ? $scheme->type : null;
    }

    return $argument->inferredType === null
        ? null
        : astType($state, $argument->inferredType);
}

/**
 * The one candidate a written constraint provides, when the others come from a
 * superclass. `null` when two written constraints provide the name (a real
 * ambiguity) or when every candidate is derived.
 */
function declaredMethodCandidate(TypeCheckState $state, string $method): ?array
{
    $declared = [];
    $derived = false;
    foreach ($state->constraintMethodCandidates[$method] ?? [] as $candidate) {
        if (($candidate['declared'] ?? false) === true) {
            $declared[($candidate['class'] ?? '') . ':' . constraintArgKey($candidate['constraintHead'])] = $candidate;
        } else {
            $derived = true;
        }
    }

    $candidates = \array_values($declared);

    return $derived && \count($candidates) === 1 ? $candidates[0] : null;
}

/**
 * The candidate whose constraint head is one of the given operand types.
 *
 * Two candidates can both match (`(Num a, Num a)`, or an operand that is itself
 * a variable): then the name stays ambiguous and the answer is `null`.
 */
function constraintMethodForOperandTypes(TypeCheckState $state, string $method, Type ...$types): ?array
{
    // Pruned on both sides: a signature's type variable is an alias of the fresh
    // variable its constraint carries (`a` is what the candidate calls `t318`),
    // so the two only meet after following that chain.
    $keys = [];
    foreach ($types as $type) {
        $keys[constraintArgKey(prune($state, $type))] = true;
    }

    $match = null;
    foreach ($state->constraintMethodCandidates[$method] ?? [] as $candidate) {
        if (!isset($keys[constraintArgKey(prune($state, $candidate['constraintHead']))])) {
            continue;
        }
        if ($match !== null) {
            return null;
        }
        $match = $candidate;
    }

    return $match;
}

function inferRewrittenInfix(TypeCheckState $state, Ast\AstNode $surface, Ast\Apply $call, array $env): Type
{
    $type = inferApply($state, $call, $env);
    annotateExprType($state, $call, $type);
    $surface->inferredType = $call->inferredType;

    return $type;
}

/**
 * Mark `x ^ n` at machine `Int` as the `intPow#` primop.
 *
 * The library `^` is `Num a, Integral b`: a call site carries two dictionaries
 * plus `Integral`'s superclasses and runs a square-and-multiply loop that knows
 * nothing the operands do not already say. At `Int` the operands say everything,
 * so the call is that loop without the dispatch. Marked once the operand types
 * are settled, because only then is a literal or an inferred binding known to be
 * an `Int`; every other instantiation -- `Integer`, `Double`, a user `Num` --
 * keeps the call, and so does a `^` the module declares itself (its scheme
 * carries no dictionaries, which is what tells the two apart).
 *
 * The mark is what {@see rewriteIntrinsicApplies} turns into an `IntrinsicCall`,
 * exactly as it does for an inlined wrapper.
 */
function resolveMachineIntPow(TypeCheckState $state, Ast\AstNode $body): void
{
    $scheme = $state->env['^'] ?? null;
    if ($scheme === null || $scheme->runtimeConstraintCount === 0) {
        return;
    }

    walkAstValues($body, static function (Ast\AstNode $node) use ($state): void {
        if (! $node instanceof Ast\Apply || $node->intrinsicWrapper !== null) {
            return;
        }

        // The operands are the last two arguments, and the ones before them are
        // the evidence. A `^` without that evidence is a plain function's call
        // site and not this operator at all.
        $parts = flattenApplyForIntrinsicWrapper($node);
        $callee = $parts['function'];
        $argCount = \count($parts['args']);
        if (! $callee instanceof Ast\OperatorRef
            || $callee->name !== '^'
            || $argCount < 3) {
            return;
        }
        foreach (\array_slice($parts['args'], 0, -2) as $evidence) {
            if (! $evidence instanceof Ast\EvidenceRef) {
                return;
            }
        }

        $operands = \array_slice($parts['args'], -2);
        // The exponent is the primop's own machine `Int` slot: any other
        // integral type keeps the library body, which is where that type's own
        // `even`/`quot` live.
        if (! operandIsMachineInt($state, $operands[1])) {
            return;
        }

        $intrinsic = powPrimopForOperand($state, $operands[0]);
        if ($intrinsic === null) {
            return;
        }

        // Two operands and the operator is the whole call: drop the evidence
        // spine so the ordinary wrapper lowering turns this into the primop.
        $spine = $node->function;
        if (! $spine instanceof Ast\Apply) {
            return;
        }

        $spine->function = $callee;
        $node->line = $operands[0]->line;
        $node->col = $operands[0]->col;
        $node->endCol = $operands[1]->endCol;
        $node->intrinsicWrapper = $intrinsic;
    });
}

/**
 * The exponentiation primop for a base operand of this type, if there is one.
 *
 * An operand's annotation has been through the type printer and back, so a
 * machine type arrives as the canonical constructor `TCon('Int64')` at least as
 * often as it does as a `TInt64` of its own.
 */
function powPrimopForOperand(TypeCheckState $state, Ast\AstNode $operand): ?string
{
    $type = $operand->inferredType;
    if (! $type instanceof Ast\TypeNode) {
        return null;
    }

    $pruned = prune($state, inferredTypeAstToInternal($type));
    $name = $pruned instanceof TCon ? $pruned->name : match ($pruned::class) {
        TInt::class => 'Int',
        TInt8::class => 'Int8',
        TInt16::class => 'Int16',
        TInt32::class => 'Int32',
        TInt64::class => 'Int64',
        TWord::class => 'Word',
        TWord8::class => 'Word8',
        TWord16::class => 'Word16',
        TWord32::class => 'Word32',
        TWord64::class => 'Word64',
        TDouble::class => 'Double',
        default => null,
    };

    return match ($name) {
        'Int' => 'intPow#',
        'Int8' => 'int8Pow#',
        'Int16' => 'int16Pow#',
        'Int32' => 'int32Pow#',
        'Int64' => 'int64Pow#',
        // `Word` has no primop of its own: its whole `Num` instance is the
        // `Int` one on the same bits (`wordFromInt# (intMul# (wordToInt# a) …)`,)
        // and both conversions are identity on every backend.
        'Word' => 'intPow#',
        'Word8' => 'word8Pow#',
        'Word16' => 'word16Pow#',
        'Word32' => 'word32Pow#',
        'Word64' => 'word64Pow#',
        'Double' => 'doublePow#',
        default => null,
    };
}

/**
 * Whether this operand's inferred type is the machine `Int`.
 *
 * An operand's annotation has been through the type printer and back, so machine
 * `Int` arrives as the canonical constructor `TCon('Int')` at least as often as
 * it does as `TInt` itself.
 */
function operandIsMachineInt(TypeCheckState $state, Ast\AstNode $operand): bool
{
    $type = $operand->inferredType;
    if (! $type instanceof Ast\TypeNode) {
        return false;
    }

    $pruned = prune($state, inferredTypeAstToInternal($type));

    return $pruned instanceof TInt
        || ($pruned instanceof TCon && $pruned->name === 'Int');
}

/**
 * Whether this operator is one the instance under check provides and either
 * operand sits at that instance's head type. Such a use has to dispatch through
 * the instance's own dictionary (`(/=) x y = case x == y of …` at `Pair a`).
 *
 * A host operator cannot stand in: it compares the representation and ignores
 * the instance. Operators of other classes (`==` inside an unrelated `MyEq`
 * instance, whose head has no `Eq`) are left alone.
 */
function instanceHeadOperand(TypeCheckState $state, string $op, Type $left, Type $right): bool
{
    $head = $state->instanceHeadInScope;
    if ($head === null || !isset($state->instanceOwnMethods[$op])) {
        return false;
    }

    return typesMatchForAmbient($state, $head, $left)
        || typesMatchForAmbient($state, $head, $right);
}

/**
 * Whether either operand is still a type variable. Such an operand has no host
 * representation yet, so the native Binop path cannot be right for it.
 */
function operandTypeIsUnresolved(TypeCheckState $state, Type $left, Type $right): bool
{
    foreach ([$left, $right] as $type) {
        $type = prune($state, $type);
        if ($type instanceof TVar) {
            return true;
        }
    }

    return false;
}

/** True for Arbitrary-precision Integer / Integer# after pruning. */
function typeIsIntegerLike(TypeCheckState $state, Type $type): bool
{
    $type = prune($state, $type);
    if ($type instanceof TCon) {
        return $type->name === 'Integer' || $type->name === 'Integer#';
    }

    return false;
}

/**
 * True for the types codegen represents as a machine integer (JVM `long`,
 * CLR `int64`, boxing aside): the host Binop comparison path unboxes exactly
 * these, and nothing else.
 */
function typeIsMachineInt(Type $type): bool
{
    return $type instanceof TInt
        || $type instanceof TChar
        || $type instanceof TWord
        || $type instanceof TWord8
        || $type instanceof TWord16
        || $type instanceof TWord32
        || $type instanceof TWord64
        || $type instanceof TInt8
        || $type instanceof TInt16
        || $type instanceof TInt32
        || $type instanceof TInt64;
}

/**
 * Whether an ordering comparison has to dispatch through `Ord` evidence rather
 * than the host Binop fast path.
 *
 * Codegen lowers a leftover symbolic `<`/`<=`/`>`/`>=` Binop by unboxing both
 * operands as a machine integer, so that path is only correct for `Int`-shaped
 * types. Rewriting to the instance lowers to the type's own `compare` instead
 * (`orderingIsLt#(stringCompare#(a, b))` for `String`, `... doubleCompare# ...`
 * for `Double`), which every backend implements.
 *
 * Unresolved type variables stay on the fast path: they are usually about to be
 * unified with `Int`, and a genuinely polymorphic operand already carries an
 * `Ord` constraint, so it never reaches the infix path.
 */
function orderingNeedsEvidence(TypeCheckState $state, string $op, Type $left, Type $right): bool
{
    if (!\in_array($op, ['<', '<=', '>', '>='], true)) {
        return false;
    }

    $left = prune($state, $left);
    $right = prune($state, $right);
    if ($left instanceof TVar || $right instanceof TVar) {
        return false;
    }

    if (!typeIsMachineInt($left) && !typeIsMachineInt($right)) {
        return true;
    }

    // `Word`/`Word64` hold the full unsigned range, so a value above `maxBound::Int` has a
    // negative host representation; their `Ord` compares the bits unsigned.
    return typeIsUnsigned64($left) || typeIsUnsigned64($right);
}

/**
 * Whether `==`/`/=` has to dispatch through its `Eq` evidence rather than the
 * host Binop fast path.
 *
 * A host comparison asks whether the two runtime representations are the same,
 * which is the same question as `Eq` only while the operand is a machine
 * scalar or a type the monomorphic table resolved to an exact intrinsic
 * (`Int`, `String`, `Double`, `Bool`, `List`, `Maybe`, ...) -- those return
 * before this point. At every other concrete type the instance is the
 * authority: with `data W = W Int` and `(==) (W a) (W b) = a < b`, the answer
 * for `W 1 == W 3` is `True`, while comparing representations answers `False`.
 *
 * A type nobody wrote an instance for keeps the previous lowering rather than
 * becoming a new diagnostic here; a still-polymorphic operand never reaches
 * this point with a selectable instance.
 */
function equalityNeedsEvidence(TypeCheckState $state, string $op, Type $left, Type $right, array $constraints): bool
{
    if ($op !== '==' && $op !== '/=') {
        return false;
    }

    $left = prune($state, $left);
    $right = prune($state, $right);
    if ($left instanceof TVar || $right instanceof TVar) {
        return false;
    }

    if (typeIsMachineInt($left) && typeIsMachineInt($right)) {
        return false;
    }

    return $constraints !== [] && constraintsResolvable($state, $constraints);
}

/**
 * Whether `<>` has to dispatch through its `Semigroup` evidence.
 *
 * `String`, `ByteString` and `List` are resolved by the monomorphic table;
 * every other operand type reaches codegen as a symbolic `<>` Binop, which is
 * emitted as a direct reference to whichever `<>` implementation happens to be
 * in scope -- an instance method that no backend emits under that name, so the
 * call site fails at run time (`null is not callable`, `MissingMethod`) rather
 * than combining the operands. `Sum`, `Product`, `Min`, `Max` and
 * `Numeric.Natural` are all affected, so the instance has to be used.
 */
function semigroupNeedsEvidence(TypeCheckState $state, string $op, Type $left, Type $right): bool
{
    if ($op !== '<>') {
        return false;
    }

    $left = prune($state, $left);
    $right = prune($state, $right);
    if ($left instanceof TVar || $right instanceof TVar) {
        return false;
    }

    return !typeIsMachineInt($left) || !typeIsMachineInt($right);
}

/** True for the unsigned types whose host representation can be negative. */
function typeIsUnsigned64(Type $type): bool
{
    return $type instanceof TWord || $type instanceof TWord64;
}

/**
 * Whether `+`/`-`/`*` has to dispatch through its `Num` evidence rather than
 * the host Binop fast path.
 *
 * Codegen lowers a leftover symbolic arithmetic Binop by unboxing both
 * operands as a machine integer, so that path is only correct while both
 * operands are shaped like one. `Integer` was the first case the evidence
 * rewrite covered; any other type with its own `Num` instance whose runtime
 * representation is not a machine scalar (a wrapper around `Integer` for
 * instance, as `Numeric.Natural` is) hits the same cast — and the unbox is a
 * hard cast to `Int64`/`Long`, so it fails at run time rather than wrapping.
 * Types the monomorphic table already resolved never reach here.
 */
function arithmeticNeedsEvidence(TypeCheckState $state, string $op, Type $left, Type $right): bool
{
    if (!\in_array($op, ['+', '-', '*'], true)) {
        return false;
    }

    $left = prune($state, $left);
    $right = prune($state, $right);
    if ($left instanceof TVar || $right instanceof TVar) {
        return false;
    }

    return !hasMachineIntRepresentation($state, $left)
        || !hasMachineIntRepresentation($state, $right);
}

/**
 * Whether the machine-integer Binop path is valid for `$type`: following
 * newtype constructors (which are erased at run time) its representation is one
 * of the host integer types. `newtype Age = Age Int` therefore keeps the fast
 * path, while `newtype Natural = Natural Integer` does not -- its value is a
 * bignum, not an i64.
 */
function hasMachineIntRepresentation(TypeCheckState $state, Type $type): bool
{
    if (typeIsMachineInt($type)) {
        return true;
    }

    $chain = newtypeChain($state, $type);
    $representation = prune($state, $chain['representation']);
    if ($representation instanceof TVar) {
        return false;
    }

    return typeIsMachineInt($representation);
}

function inferTuple(TypeCheckState $state, Ast\Tuple $expr, array $env): Type
{
    if (count($expr->elements) === 0) {
        return new TUnit();
    }

    $types = [];
    foreach ($expr->elements as &$element) {
        $types[] = inferExpr($state, $element, $env);
    }
    unset($element);

    return new TCon('Tuple' . count($types), $types);
}

function inferList(TypeCheckState $state, Ast\ListLit $expr, array $env): Type
{
    if (count($expr->elements) === 0) {
        return new TCon('List', [freshType($state)]);
    }

    $elemType = inferExpr($state, $expr->elements[0], $env);
    for ($i = 1, $n = count($expr->elements); $i < $n; ++$i) {
        unify($state, inferExpr($state, $expr->elements[$i], $env), $elemType, $expr->elements[$i]);
    }

    return new TCon('List', [$elemType]);
}

function inferLambda(TypeCheckState $state, Ast\Lambda $expr, array $env): Type
{
    $bodyExpected = freshType($state);
    $bodyEnv = $env;
    $paramTypes = [];
    $paramPatterns = [];

    foreach ($expr->params as $param) {
        $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;
        if (!$pattern instanceof Ast\PatVar && !$pattern instanceof Ast\PatWild) {
            throw typeFail($state, 'lambda parameters must be variables', $param);
        }
        $paramPatterns[] = $pattern;
    }
    assertParamListLinear($state, $paramPatterns);

    foreach ($expr->params as $param) {
        $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;

        $paramType = $param instanceof Ast\LambdaParam && $param->type !== null
            ? astType($state, $param->type)
            : freshType($state);
        [, $bodyEnv] = bindPattern($state, $pattern, $paramType, $bodyEnv);
        $paramTypes[] = $paramType;
    }

    unify($state, inferExpr($state, $expr->body, $bodyEnv), $bodyExpected);

    $fnType = $bodyExpected;
    foreach (array_reverse($paramTypes) as $paramType) {
        $fnType = new TArrow($paramType, $fnType);
    }

    return $fnType;
}

function inferLet(TypeCheckState $state, Ast\Let $expr, array $env): Type
{
    assertBindingGroupLinear($state, $expr->bindings);
    $local = inferBindingGroups($state, $expr->bindings, $env, $expr->generalizeBindings);

    return inferExpr($state, $expr->body, $local);
}

function inferWhere(TypeCheckState $state, Ast\Where $expr, array $env): Type
{
    assertBindingGroupLinear($state, $expr->bindings);
    $local = inferBindingGroups($state, $expr->bindings, $env, true);

    return inferExpr($state, $expr->expr, $local);
}

/**
 * Typecheck a let/where binding list with recursive groups (SCCs).
 *
 * @param list<Ast\Binding> $bindings
 * @param array<string, Scheme> $env
 * @return array<string, Scheme>
 */
function inferBindingGroups(TypeCheckState $state, array $bindings, array $env, bool $generalize): array
{
    $local = $env;
    foreach (bindingGroupSccs($bindings) as $component) {
        $group = [];
        foreach ($component['indices'] as $index) {
            $group[] = $bindings[$index];
        }
        $groupGeneralize = $generalize && ! isRestrictedBindingGroup($group);
        $local = $component['recursive']
            ? inferRecursiveBindings($state, $group, $local, $groupGeneralize)
            : inferSequentialBindings($state, $group, $local, $groupGeneralize);
    }

    return $local;
}

/**
 * Whether a let/where group is *restricted* in base's sense: a single pattern
 * binding -- `x = e`, `f = \p -> e`, `(a, b) = e` -- with no type signature.
 *
 * Such a group is not generalized: its constrained type variables stay
 * monomorphic, take the dictionaries of the enclosing declaration, and are
 * quantified there if its type mentions them. `f p = e` (arguments on the left)
 * and a binding that spells a signature out are unrestricted and do get a
 * scheme of their own.
 *
 * @param list<Ast\Binding> $group
 */
function isRestrictedBindingGroup(array $group): bool
{
    if (\count($group) !== 1) {
        return false;
    }

    $binding = $group[0];
    if ($binding->functionBinding) {
        return false;
    }

    return ! ($binding->value instanceof Ast\TypeAsc
        || $binding->value instanceof Ast\TypeConstrained);
}

/**
 * @param list<Ast\Binding> $bindings
 * @param array<string, Scheme> $env
 * @return array<string, Scheme>
 */
function inferSequentialBindings(TypeCheckState $state, array $bindings, array $env, bool $generalize): array
{
    $local = $env;
    foreach ($bindings as $binding) {
        $alreadyAbstracted = abstractedLocalConstraints($binding->value);
        $annotatedConstraints = localConstrainedAnnotation($binding->value);
        if ($alreadyAbstracted !== []) {
            // Re-checking an already-abstracted binding (the same AST is checked
            // more than once): reuse the recorded constraints and peel the
            // dictionary arrows the synthetic lambda contributed to the type.
            $constraints = $alreadyAbstracted;
            $rawType = inferExpr($state, $binding->value, $local);
            $valueType = peelDictArrows($rawType, \count($constraints));
        } elseif ($annotatedConstraints !== null) {
            // `go :: C a => a -> T` in a let/where: check the body against the
            // annotation's dictionaries and make the binding a function of
            // them, exactly like a top-level signature does.
            [$binding->value, $valueType, $constraints] = abstractAnnotatedLocalBinding(
                $state,
                $binding->value,
                $local,
            );
        } else {
            $valueType = inferExpr($state, $binding->value, $local);
            // A literal whose type the binding already pins is that type's value, converted before
            // the constraints are collected; a restricted binding stays a constraint.
            if ($generalize) {
                $binding->value = elaborateNumericLiterals($state, $binding->value);
            }
            // The binding's value can carry its constraints anywhere: `where double y = y + y` is
            // a lambda whose body is the constrained operator.
            $constraints = pendingConstraintsDeep($binding->value, $state);
            if ($generalize && $constraints !== []) {
                // Abstract the constraints into dictionary parameters so the binding
                // can be generalized (`where go = show` is `Show a => a -> String`,
                // exactly like a top-level function of the same signature).
                [$binding->value, $constraints] = abstractLocalConstraints($state, $binding->value, $constraints);
            }
        }
        [, $local] = bindPattern(
            $state,
            $binding->pattern,
            $valueType,
            $local,
            generalize: $generalize || $annotatedConstraints !== null,
            constraints: $constraints,
        );
    }

    return $local;
}

/**
 * The constrained type annotation on a local binding value, if it has one.
 *
 * `go :: C a => a -> T` parses as a `TypeAsc` whose type is a
 * `TypeConstrained`; such a binding needs dictionary parameters exactly like a
 * top-level function of the same signature.
 */
function localConstrainedAnnotation(Ast\AstNode $value): ?Ast\TypeConstrained
{
    if ($value instanceof Ast\TypeAsc && $value->type instanceof Ast\TypeConstrained) {
        return $value->type;
    }

    return null;
}

/** Surface parameter type nodes of a (possibly curried) function type AST. */
function functionParamTypeAsts(Ast\TypeNode $type): array
{
    $params = [];
    $cursor = $type;
    while ($cursor instanceof Ast\TypeArrow) {
        $params[] = $cursor->from;
        $cursor = $cursor->to;
    }

    return $params;
}

/**
 * Check `go :: C a => a -> T` (a constrained local signature) the way a
 * top-level function is checked: the annotation's constraints become leading
 * dictionary parameters, the function's own parameters take the annotation's
 * types, and the body resolves its evidence through the new dictionaries.
 *
 * @param array<string, Scheme> $env
 * @return array{0: Ast\AstNode, 1: Type, 2: list<Ast\PendingConstraint>}
 */
function abstractAnnotatedLocalBinding(TypeCheckState $state, Ast\AstNode $value, array $env): array
{
    if (!$value instanceof Ast\TypeAsc || !$value->type instanceof Ast\TypeConstrained) {
        throw new \InvalidArgumentException('abstractAnnotatedLocalBinding expects a constrained type annotation');
    }

    [$constraintAsts, $bodyTypeAst] = splitTypeAst($value->type);
    $userConstraints = parseConstraints($state, $constraintAsts, $value);
    $constraints = expandConstraintsWithSuperclasses($state, $userConstraints);
    applyConstraintVarKinds($state, $constraints);

    [$params, $abstracted, $dictTypes] = allocateDictParams($state, $constraints);

    // Give the function's parameters the annotation's types, so the body is
    // checked against the annotation's type variables and its class-method
    // calls match the synthesized dictionary parameters.
    $inner = $value->expr;
    if ($inner instanceof Ast\Lambda) {
        $paramAsts = functionParamTypeAsts($bodyTypeAst);
        foreach ($inner->params as $i => $param) {
            if (
                $i < \count($paramAsts)
                && $param instanceof Ast\LambdaParam
                && $param->type === null
            ) {
                $param->type = $paramAsts[$i];
            }
        }
    }

    $saved = installAmbientConstraints($state, $abstracted);
    try {
        $valueType = inferExpr($state, $inner, $env);
        $bodyExpected = astType($state, $bodyTypeAst);
        unify($state, $valueType, $bodyExpected, $value);
        $valueType = prune($state, $bodyExpected);
        // Resolve any evidence the body left pending against the new
        // dictionaries (a call to a superclass method, say).
        if (pendingConstraintsFromExpr($inner) !== []) {
            resolvePendingEvidenceInExpr($state, $inner);
            tryResolveValueEvidence($state, $inner);
        }
    } finally {
        restoreAmbientConstraints($state, $saved);
    }

    $lambda = wrapDictLambda($state, $inner, $params, $dictTypes);
    $lambda->abstractedConstraints = $abstracted;

    return [$lambda, $valueType, $abstracted];
}

/**
 * Turn an inferred local binding into a function of its class constraints.
 *
 * A let/where binding with a constrained type is checked the same way a
 * top-level constrained function is: the constraints become leading dictionary
 * parameters, and references in the RHS that need those dictionaries resolve to
 * the new parameters instead of demanding a concrete instance. Without this a
 * local helper such as `where go = show` could only ever be used at the single
 * type its body happened to fix.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return array{0: Ast\AstNode, 1: list<Ast\PendingConstraint>}
 */
function abstractLocalConstraints(TypeCheckState $state, Ast\AstNode $rhs, array $constraints): array
{
    [$params, $abstracted, $dictTypes] = allocateDictParams($state, $constraints);

    $saved = installAmbientConstraints($state, $abstracted);
    try {
        resolvePendingEvidenceInExpr($state, $rhs);
        tryResolveValueEvidence($state, $rhs);
        // Every constraint in the value was abstracted into a parameter, so a
        // literal in it is its `fromInteger` projected from one of them.
        $rhs = elaborateNumericLiterals($state, $rhs);
    } finally {
        restoreAmbientConstraints($state, $saved);
    }

    $lambda = wrapDictLambda($state, $rhs, $params, $dictTypes);
    // Marks the lambda as compiler-synthesized so re-checking this binding (the
    // same AST is checked more than once) reuses the constraints instead of
    // wrapping the already-wrapped lambda again.
    $lambda->abstractedConstraints = $abstracted;

    return [$lambda, $abstracted];
}

/**
 * The constraints a local binding was already abstracted over, if any.
 *
 * The same AST is checked more than once: a binding this pass turned into a
 * dictionary-taking adapter comes back as that adapter, and its dictionaries
 * are read off it instead of being allocated a second time.
 *
 * @return list<Ast\PendingConstraint>
 */
function abstractedLocalConstraints(Ast\AstNode $value): array
{
    return $value instanceof Ast\Lambda ? $value->abstractedConstraints : [];
}


/**
 * The constraints a recursive binding group is generalized over: the union of
 * the obligations its members' bodies carry, one dictionary per class and type.
 *
 * A group is generalized as a whole, because its members share the variables
 * their bodies tie together: `go` calling itself at the variable its caller
 * pinned is one obligation, not one per member. Members with a signature are
 * left out -- their dictionaries come from the annotation.
 *
 * @param list<Ast\Binding> $bindings
 * @param array<int, list<Ast\PendingConstraint>> $bodyConstraints
 * @param array<string, true> $annotated
 * @return list<Ast\PendingConstraint>
 */
/**
 * The scheme a group member was generalized to when its group was abstracted.
 *
 * @return array{type: Ast\TypeNode, bound: list<string>, constraints: list<array{class: string, args: list<Ast\TypeNode>}>}|null
 */
function recordedGroupScheme(Ast\AstNode $value): ?array
{
    [$peeled] = peelBindingAnnotation($value);

    return $peeled instanceof Ast\Lambda ? $peeled->abstractedScheme : null;
}

/**
 * Record the scheme a member of a just-abstracted group was generalized to.
 *
 * The scheme's variables are renamed first: what is recorded outlives the run
 * that produced it, and every later run allocates its own variables from the
 * same `t<n>` names. The rename keeps the member's type and its obligations
 * sharing their variables -- which is what makes a later run resolve the right
 * dictionary at a use site -- while making it impossible for that run to alias
 * them with a variable of its own.
 */
function recordGroupScheme(Ast\AstNode $value, Type $type, array $constraints): void
{
    [$peeled] = peelBindingAnnotation($value);
    if (! $peeled instanceof Ast\Lambda) {
        return;
    }

    [$renamedType, $renamedConstraints, $bound] = renameRecordedVars($type, $constraints);
    $recorded = [];
    foreach ($renamedConstraints as $constraint) {
        $args = [];
        foreach ($constraint->args as $arg) {
            $args[] = internalTypeToAst($arg, []);
        }
        $recorded[] = ['class' => $constraint->class, 'args' => $args];
    }

    $peeled->abstractedScheme = [
        'type' => internalTypeToAst($renamedType, []),
        'bound' => $bound,
        'constraints' => $recorded,
    ];
}

/**
 * @param list<Ast\PendingConstraint> $constraints
 * @return array{0: Type, 1: list<Ast\PendingConstraint>, 2: list<string>}
 */
function renameRecordedVars(Type $type, array $constraints): array
{
    static $recordId = 0;

    $names = [];
    foreach ([...typeVars($type), ...constraintTypeVars($constraints)] as $name => $_) {
        $names[$name] = true;
    }

    $prefix = 'r' . (++$recordId) . '_';
    $mapping = [];
    $bound = [];
    $index = 0;
    foreach (array_keys($names) as $name) {
        $renamed = $prefix . $index++;
        $mapping[$name] = new TVar($renamed);
        $bound[] = $renamed;
    }

    $renamedConstraints = [];
    foreach ($constraints as $constraint) {
        $renamedConstraints[] = new Ast\PendingConstraint(
            $constraint->class,
            array_map(static fn (Type $arg): Type => substitute($arg, $mapping), $constraint->args),
        );
    }

    return [substitute($type, $mapping), $renamedConstraints, $bound];
}

/** @param list<Ast\PendingConstraint> $constraints @return array<string, true> */
function constraintTypeVars(array $constraints): array
{
    $vars = [];
    foreach ($constraints as $constraint) {
        foreach ($constraint->args as $arg) {
            foreach (typeVars($arg) as $name => $_) {
                $vars[$name] = true;
            }
        }
    }

    return $vars;
}

/**
 * @param array{type: Ast\TypeNode, bound: list<string>, constraints: list<array{class: string, args: list<Ast\TypeNode>}>} $record
 */
function materializeGroupScheme(TypeCheckState $state, array $record): Scheme
{
    $constraints = [];
    foreach ($record['constraints'] as $constraint) {
        $args = [];
        foreach ($constraint['args'] as $arg) {
            $args[] = astType($state, $arg);
        }
        $constraints[] = new Ast\PendingConstraint($constraint['class'], $args);
    }

    return scheme(
        astType($state, $record['type']),
        $record['bound'],
        $constraints,
        count(expandConstraintsWithSuperclasses($state, $constraints)),
    );
}

/**
 * Whether the group already carries its dictionary parameters.
 *
 * The same AST is checked more than once, so a group that was abstracted stays
 * abstracted: inferring its bodies again re-derives a constraint for every
 * literal in them, and taking those as obligations to abstract would put a
 * second set of dictionary parameters in front of the first.
 *
 * @param list<Ast\Binding> $bindings
 * @param array<string, true> $annotated
 */
function groupWasAbstracted(array $bindings, array $annotated): bool
{
    foreach ($bindings as $binding) {
        if (! $binding->pattern instanceof Ast\PatVar
            || isset($annotated[$binding->pattern->name])
        ) {
            continue;
        }

        [$value] = peelBindingAnnotation($binding->value);
        if (abstractedLocalConstraints($value) !== []) {
            return true;
        }
    }

    return false;
}

/**
 * The constraints a recursive binding group is generalized over: the union of
 * the obligations its members' bodies carry, one dictionary per class and type.
 *
 * A group is generalized as a whole, because its members share the variables
 * their bodies tie together: `go` calling itself at the variable its caller
 * pinned is one obligation, not one per member. Members with a signature are
 * left out -- their dictionaries come from the annotation.
 *
 * @param list<Ast\Binding> $bindings
 * @param array<int, list<Ast\PendingConstraint>> $bodyConstraints
 * @param array<string, true> $annotated
 * @return list<Ast\PendingConstraint>
 */
function recursiveGroupConstraints(
    TypeCheckState $state,
    array $bindings,
    array $bodyConstraints,
    array $annotated,
): array {
    $merged = [];
    $seen = [];
    foreach ($bindings as $bi => $binding) {
        if (! $binding->pattern instanceof Ast\PatVar
            || isset($annotated[$binding->pattern->name])
        ) {
            continue;
        }

        foreach (refreshConstraintArgs($state, $bodyConstraints[$bi] ?? []) as $constraint) {
            $key = $constraint->class . ':' . constraintArgsKey($constraint->args);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $constraint;
        }
    }

    return $merged;
}

/**
 * Give the group's own references the dictionaries their callee takes.
 *
 * A member's body was inferred before the group was generalized, when its own
 * name had no constraints, so its recursive calls carry no evidence yet. The
 * names are marked here and the evidence pass that follows resolves them: in a
 * member's body the group's dictionaries are that member's own parameters.
 *
 * @param array<int, string> $members binding index to name
 * @param list<Ast\PendingConstraint> $constraints
 */
function markGroupCallEvidence(Ast\AstNode $expr, array $members, array $constraints): void
{
    $names = \array_flip($members);
    walkAstValues($expr, static function (Ast\AstNode $node) use ($names, $constraints): void {
        if ($node instanceof Ast\Variable && isset($names[$node->name])) {
            $node->pendingConstraints = $constraints;
        }
    });
}

/**
 * Allocate a dictionary parameter for each class constraint.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return array{0: list<Ast\LambdaParam>, 1: list<Ast\PendingConstraint>, 2: list<Type>}
 */
function allocateDictParams(TypeCheckState $state, array $constraints): array
{
    $params = [];
    $abstracted = [];
    $dictTypes = [];
    foreach ($constraints as $constraint) {
        $name = '__ev_dict' . $state->nextEvidenceParam++;
        $dictType = new TCon('__Dict_' . $constraint->class);
        $dictTypes[] = $dictType;
        $param = new Ast\PatVar($name);
        $dictAst = internalTypeToAst($dictType, $state->subst);
        $param->inferredType = $dictAst;
        // The parameter carries its type as well as its pattern does: a synthesized lambda has
        // to come back with the dictionary arrow it was built with.
        $params[] = new Ast\LambdaParam($param, $dictAst);
        $abstracted[] = new Ast\PendingConstraint(
            $constraint->class,
            $constraint->args,
            $name,
            $constraint->implicit,
            $constraint->instanceHeadAst,
        );
    }

    return [$params, $abstracted, $dictTypes];
}

/**
 * Make the given constraints the in-scope dictionaries for evidence resolution.
 *
 * @param list<Ast\PendingConstraint> $constraints
 * @return array{0: list<Ast\PendingConstraint>, 1: array<string, list<Ast\PendingConstraint>>}
 */
function installAmbientConstraints(TypeCheckState $state, array $constraints): array
{
    $saved = [$state->ambientConstraints, $state->ambientConstraintsByClass];
    $state->ambientConstraints = $constraints;
    $byClass = [];
    foreach ($constraints as $constraint) {
        $byClass[$constraint->class][] = $constraint;
    }
    $state->ambientConstraintsByClass = $byClass;

    return $saved;
}

/** @param array{0: list<Ast\PendingConstraint>, 1: array<string, list<Ast\PendingConstraint>>} $saved */
function restoreAmbientConstraints(TypeCheckState $state, array $saved): void
{
    [$state->ambientConstraints, $state->ambientConstraintsByClass] = $saved;
}

/**
 * Wrap a checked expression in a lambda over leading dictionary parameters, so
 * the binding's runtime value expects the dictionaries the type promises.
 *
 * @param list<Ast\LambdaParam> $params
 * @param list<Type> $dictTypes
 */
function wrapDictLambda(TypeCheckState $state, Ast\AstNode $rhs, array $params, array $dictTypes): Ast\Lambda
{
    $lambda = new Ast\Lambda($params, $rhs);
    $fnType = $rhs->inferredType !== null
        ? astType($state, $rhs->inferredType)
        : freshType($state);
    foreach (array_reverse($dictTypes) as $dictType) {
        $fnType = new TArrow($dictType, $fnType);
    }
    $lambda->inferredType = internalTypeToAst($fnType, $state->subst);

    return $lambda;
}

/**
 * @param list<Ast\Binding> $bindings
 * @param array<string, Scheme> $env
 * @return array<string, Scheme>
 */
function inferRecursiveBindings(TypeCheckState $state, array $bindings, array $env, bool $generalize): array
{
    $local = $env;
    /** @var array<string, Type> $expected */
    $expected = [];
    /** @var array<string, true> $annotated */
    $annotated = [];

    foreach ($bindings as $binding) {
        [, $annot] = peelBindingAnnotation($binding->value);
        if ($annot !== null && $binding->pattern instanceof Ast\PatVar) {
            $annotType = astType($state, $annot);
            $name = $binding->pattern->name;
            $local[$name] = scheme($annotType, schemeBoundVars($annotType, $env, []));
            $expected[$name] = $annotType;
            $annotated[$name] = true;
            continue;
        }
        foreach (patternVariableNames($binding->pattern) as $name) {
            $fresh = freshType($state);
            $local[$name] = scheme($fresh, []);
            $expected[$name] = $fresh;
        }
    }

    /** @var array<int, list<Ast\PendingConstraint>> $rhsConstraints */
    $rhsConstraints = [];
    /** @var array<int, list<Ast\PendingConstraint>> $bodyConstraints */
    $bodyConstraints = [];

    foreach ($bindings as $bi => $binding) {
        [$rhs, $annot] = peelBindingAnnotation($binding->value);
        $abstracted = abstractedLocalConstraints($rhs);
        $valueType = peelDictArrows(
            inferExpr($state, $rhs, $local),
            \count($abstracted),
        );
        if ($annot !== null) {
            $annotType = $binding->pattern instanceof Ast\PatVar
                ? $expected[$binding->pattern->name]
                : astType($state, $annot);
            unify($state, $valueType, $annotType, $binding->value);
            $valueType = prune($state, $annotType);
        }
        // A literal this binding's type already pins is that type's value, not a
        // constraint to generalize over, and a restricted group is not
        // generalized at all (see `inferSequentialBindings`).
        if ($generalize) {
            if ($binding->value instanceof Ast\TypeAsc) {
                $binding->value->expr = elaborateNumericLiterals($state, $rhs);
            } else {
                $binding->value = elaborateNumericLiterals($state, $binding->value);
            }
        }
        $constraints = $abstracted !== [] ? $abstracted : pendingConstraintsFromExpr($rhs);
        $rhsConstraints[$bi] = $constraints;
        $bodyConstraints[$bi] = $generalize ? pendingConstraintsDeep($rhs, $state) : [];
        if ($binding->value instanceof Ast\TypeAsc) {
            $binding->value->pendingConstraints = $constraints;
        }

        if ($binding->pattern instanceof Ast\PatVar) {
            unify($state, $valueType, $expected[$binding->pattern->name], $binding->pattern);
            continue;
        }

        [, $local] = bindPattern(
            $state,
            $binding->pattern,
            $valueType,
            $local,
            generalize: false,
            constraints: $constraints,
        );
    }

    // Generalize the group over the constraints its bodies carry, the way a
    // top-level function is generalized over the constraints of its signature:
    // each member becomes a function of the group's dictionaries, and each use
    // site supplies them -- its own parameters when the caller is a member, an
    // instance when the type is concrete.
    $groupConstraints = $generalize && !groupWasAbstracted($bindings, $annotated)
        ? recursiveGroupConstraints($state, $bindings, $bodyConstraints, $annotated)
        : [];
    // The members are abstracted over the *expanded* constraints -- one
    // dictionary per superclass, exactly what a use site's re-expansion of the
    // recorded list yields; the scheme keeps the list as written.
    $groupDictConstraints = $groupConstraints === []
        ? []
        : expandConstraintsWithSuperclasses($state, $groupConstraints);
    if ($groupConstraints !== []) {
        $members = [];
        foreach ($bindings as $bi => $binding) {
            if (! $binding->pattern instanceof Ast\PatVar) {
                continue;
            }

            $name = $binding->pattern->name;
            if (! isset($annotated[$name])) {
                $members[$bi] = $name;
            }
        }

        foreach ($members as $bi => $name) {
            $binding = $bindings[$bi];
            [$rhs] = peelBindingAnnotation($binding->value);
            markGroupCallEvidence($rhs, $members, $groupConstraints);
            [$wrapped, $abstracted] = abstractLocalConstraints($state, $rhs, $groupDictConstraints);
            if ($binding->value instanceof Ast\TypeAsc) {
                $binding->value->expr = $wrapped;
            } else {
                $binding->value = $wrapped;
            }

            $rhsConstraints[$bi] = $abstracted;
        }
    }

    $abstractedThisPass = $groupConstraints !== [];

    foreach ($bindings as $bi => $binding) {
        // The obligations are read off the bodies, so their arguments have to be
        // the types those arguments have *here* -- the same ones the scheme's own
        // type is built from -- or a use site would instantiate the type and the
        // constraints from two different variables.
        $constraints = refreshConstraintArgs($state, $rhsConstraints[$bi] ?? []);
        if ($binding->pattern instanceof Ast\PatVar) {
            $name = $binding->pattern->name;
            if (! $abstractedThisPass) {
                $recorded = recordedGroupScheme($binding->value);
                if ($recorded !== null) {
                    $local[$name] = materializeGroupScheme($state, $recorded);
                    continue;
                }
            }

            $pruned = prune($state, $expected[$name]);
            $schemeEnv = isset($annotated[$name]) ? $env : $local;
            if (!isset($annotated[$name])) {
                unset($schemeEnv[$name]);
            }
            $quantify = isset($annotated[$name]) || $generalize;
            // A generalized group member carries the group's constraints as
            // written, with the dictionary count every member was abstracted
            // over: a use site re-expands the written list into those.
            $schemeConstraints = $abstractedThisPass ? $groupConstraints : $constraints;
            $local[$name] = scheme(
                $pruned,
                $quantify ? schemeBoundVars($pruned, $schemeEnv, $schemeConstraints) : [],
                $quantify ? $schemeConstraints : [],
                $abstractedThisPass ? count($groupDictConstraints) : null,
            );
            if ($abstractedThisPass && $quantify) {
                recordGroupScheme($binding->value, $pruned, $groupConstraints);
            }
            continue;
        }

        if ($generalize) {
            $local = generalizePatternBindings($state, $binding->pattern, $local, $constraints);
        }
    }

    return $local;
}

function inferCase(TypeCheckState $state, Ast\CaseExpr $expr, array $env): Type
{
    $scrutineeType = inferExpr($state, $expr->scrutinee, $env);
    $altEnvs = [];

    foreach ($expr->alts as $alt) {
        $altEnv = $env;
        assertPatternLinear($state, $alt->pattern);
        [$patType, $altEnv] = bindPattern($state, $alt->pattern, freshType($state), $altEnv, generalize: false);
        unify($state, $patType, $scrutineeType, $expr);
        $altEnvs[] = $altEnv;
    }

    checkCaseExhaustiveness($state, $expr, $scrutineeType);
    $expr->exhaustive = caseExhaustivenessProven($state, $expr, $scrutineeType);

    // After exhaustiveness has read the literals, and before the bodies are
    // checked: an overloaded literal pattern becomes a binder compared with the
    // literal. Doing it here is what keeps `f 0 = …` free of the `Int`
    // assumption while still reporting a gap when no alternative is a catch-all.
    foreach ($expr->alts as $i => $alt) {
        $altEnvs[$i] = overloadedLiteralPatterns($state, $alt, $altEnvs[$i]);
    }

    $result = null;
    foreach ($expr->alts as $i => $alt) {
        $bodyType = inferExpr($state, $alt->body, $altEnvs[$i]);
        if ($result === null) {
            $result = $bodyType;
        } else {
            unifyCaseBranches($state, $bodyType, $result, $alt->body);
        }
    }

    return $result ?? new TUnit();
}

/**
 * Whether the literal's type compares with a host primitive, i.e. the pattern
 * test the lowering emits (`$x === 0`) is already the literal's own.
 */
function literalPatternHostPrimitive(Type $type): bool
{
    if ($type instanceof TInt
        || $type instanceof TInt8
        || $type instanceof TInt16
        || $type instanceof TInt32
        || $type instanceof TInt64
        || $type instanceof TWord
        || $type instanceof TWord8
        || $type instanceof TWord16
        || $type instanceof TWord32
        || $type instanceof TWord64) {
        return true;
    }

    // The same machine integers also travel as plain type constructors
    // (`TCon('Int')`), depending on where the type came from.
    return $type instanceof TCon && \in_array($type->name, [
        'Int', 'Int8', 'Int16', 'Int32', 'Int64',
        'Word', 'Word8', 'Word16', 'Word32', 'Word64',
    ], true);
}

/**
 * A numeric literal in a pattern is an overloaded literal, not an `Int` pattern:
 * `f 0 = "zero"` has to mean `Integral i => i -> String` and test `i`'s own zero,
 * so the same clause works at every `Integral` instance. base spells that out by
 * hand as an equality against the literal; this is the same thing.
 *
 * At a host primitive (`Int`, `Int64`, `Word8`, `String`, …) the pattern test the
 * lowering already emits is the literal's own, so the literal stays in the
 * pattern and nothing changes for it.
 *
 * @param array<string, Scheme> $env
 * @return array<string, Scheme>
 */
function overloadedLiteralPatterns(TypeCheckState $state, Ast\Alt $alt, array $env): array
{
    $tests = [];
    $alt->pattern = overloadedLiteralPatternNode($state, $alt->pattern, $tests, $env);
    if ($tests === []) {
        return $env;
    }

    $at = $alt->pattern;
    $alt->body = new Ast\GuardsExpr(
        [new Ast\Guarded(literalPatternCondition($tests), $alt->body, $at->line, $at->col, $at->endCol)],
        $at->line,
        $at->col,
        $at->endCol,
    );

    return $env;
}

/**
 * Whether a numeric literal pattern is an *overloaded* literal.
 *
 * `f 0 = …` means what it means in `base`: the pattern is `0` at the pattern's
 * own type, tested with that type's `==`, so the clause works at every `Num`
 * instance and its type is whatever the use sites pin it to. A pattern at a
 * machine type keeps the host comparison instead (see
 * {@see literalPatternHostPrimitive}).
 */
function literalPatternIsOverloaded(TypeCheckState $state, Type $type): bool
{
    return isset($state->classes['Num']) && !literalPatternHostPrimitive($type);
}

function overloadedLiteralPatternNode(TypeCheckState $state, Ast\AstNode $pattern, array &$tests, array &$env): Ast\AstNode
{
    if ($pattern instanceof Ast\PatLit && \is_int($pattern->value)) {
        $type = prune($state, inferredTypeAstToInternal($pattern->inferredType ?? throw typeFail(
            $state,
            'literal pattern has no inferred type',
            $pattern,
        )));
        if (!literalPatternIsOverloaded($state, $type)) {
            // A host machine type: the pattern test the lowering emits is the
            // native comparison (`$x === 0`), not an `Eq`-dispatch.
            return $pattern;
        }

        $binder = new Ast\PatVar('__litpat', $pattern->line, $pattern->col, $pattern->endCol);
        $binder->binderId = ++$state->nextBinderId;
        $name = '__litpat' . $binder->binderId;
        $binder->name = $name;
        $binder->inferredType = internalTypeToAst($type, $state->subst);
        $env[$name] = scheme($type, []);
        $literal = new Ast\IntegerLit($pattern->value, $pattern->line, $pattern->col, $pattern->endCol);
        $literal->digits = $pattern->digits;
        $tests[] = new Ast\Infix(
            '==',
            new Ast\Variable($name, $pattern->line, $pattern->col, $pattern->endCol),
            $literal,
            $pattern->line,
            $pattern->col,
            $pattern->endCol,
        );

        return $binder;
    }

    if ($pattern instanceof Ast\PatCons) {
        $pattern->head = overloadedLiteralPatternNode($state, $pattern->head, $tests, $env);
        $pattern->tail = overloadedLiteralPatternNode($state, $pattern->tail, $tests, $env);

        return $pattern;
    }

    if ($pattern instanceof Ast\PatCon) {
        foreach ($pattern->args as $i => $arg) {
            $pattern->args[$i] = overloadedLiteralPatternNode($state, $arg, $tests, $env);
        }

        return $pattern;
    }

    if ($pattern instanceof Ast\PatTuple) {
        foreach ($pattern->elements as $i => $element) {
            $pattern->elements[$i] = overloadedLiteralPatternNode($state, $element, $tests, $env);
        }

        return $pattern;
    }

    if ($pattern instanceof Ast\PatRecord) {
        foreach ($pattern->fields as $field) {
            $field->pattern = overloadedLiteralPatternNode($state, $field->pattern, $tests, $env);
        }

        return $pattern;
    }

    return $pattern;
}

/**
 * The condition all of an alternative's literal tests have to satisfy.
 *
 * The conjunction is `boolAnd#` rather than `&&` on purpose: the node is built
 * here, where the module's `&&` need not be in scope.
 *
 * @param list<Ast\Infix> $tests
 */
function literalPatternCondition(array $tests): Ast\AstNode
{
    $condition = $tests[0];
    foreach (\array_slice($tests, 1) as $test) {
        $condition = new Ast\Infix('&&', $condition, $test, $test->line, $test->col, $test->endCol);
        $condition->compilerIntrinsic = 'boolAnd#';
    }

    return $condition;
}

function inferGuards(TypeCheckState $state, Ast\GuardsExpr $expr, array $env): Type
{
    $result = null;
    foreach ($expr->clauses as $clause) {
        $guardType = inferGuardExpr($state, $clause->guard, $env);
        unify($state, $guardType, new TCon('Bool'), $clause->guard);
        $bodyType = inferExpr($state, $clause->body, $env);
        if ($result === null) {
            $result = $bodyType;
        } else {
            unifyCaseBranches($state, $bodyType, $result, $clause->body);
        }
    }

    return $result ?? new TUnit();
}

function inferGuardExpr(TypeCheckState $state, Ast\AstNode &$guard, array $env): Type
{
    if ($guard instanceof Ast\Variable && $guard->name === 'otherwise') {
        return new TCon('Bool');
    }

    // By reference: a guard whose operator was replaced by a dictionary call must keep the
    // replacement, or it stays a host comparison (an `Int` assumption).
    return inferExpr($state, $guard, $env);
}

function describeUnresolvedConstraints(TypeCheckState $state, array $constraints): string
{
    $rendered = [];
    foreach ($constraints as $constraint) {
        $class = $constraint->class;
        if ($class === null) {
            continue;
        }

        $args = \array_map(
            static fn (Type $arg): Type => prune($state, $arg),
            $constraint->args,
        );
        $rename = friendlyTypeVarNames($args);
        $parts = \array_map(static fn (Type $arg): string => typeToStringArgument($arg, $rename), $args);
        $rendered[$class . ' ' . \implode(' ', $parts)] = $class . (($parts === []) ? '' : ' ' . \implode(' ', $parts));
    }

    if ($rendered === []) {
        return 'unresolved type class constraint';
    }

    $list = array_values($rendered);
    $noun = count($list) === 1 ? 'constraint' : 'constraints';

    return "unresolved type class {$noun}: `" . \implode('`, `', $list) . '`';
}

/**
 * Drop the class constraint a symbolic operator recorded when its operand type
 * was still open and the body has since pinned it to a machine integer.
 *
 * `inferInfix` records `Num a` on the node when it cannot yet decide between the
 * native operation and the dictionary, and `let x = 5 in x + x` only learns that
 * `x` is an `Int` afterwards. For `Int`/`Word64` the native operation is exactly
 * right, so the constraint is answered here rather than reported as unresolved
 * (and the same node is recorded as the intrinsic the lowering must emit).
 */
function resolveDeferredNativeInfixes(TypeCheckState $state, Ast\AstNode $expr): void
{
    walkAstValues($expr, static function (Ast\AstNode $node) use ($state): void {
        if (! $node instanceof Ast\Infix || $node->pendingConstraints === []) {
            return;
        }

        $left = $node->left->inferredType;
        $right = $node->right->inferredType;
        if ($left === null || $right === null) {
            return;
        }

        // These annotations round-tripped through the type printer, so a machine type arrives
        // as `TCon('Int')`; normalise or the operands stay unresolved.
        $resolved = resolveMonomorphicOperator(
            $node->operator,
            canonicalPrimitiveType(prune($state, inferredTypeAstToInternal($left))),
            canonicalPrimitiveType(prune($state, inferredTypeAstToInternal($right))),
        );
        if ($resolved === null) {
            return;
        }

        $node->resolvedIntrinsic = $resolved;
        $node->pendingConstraints = [];
    });
}

function assertNoPendingConstraintsInExpr(TypeCheckState $state, Ast\AstNode $expr, ?Ast\AstNode $at = null): void
{
    if ($expr->pendingConstraints !== []) {
        throw typeFail($state, describeUnresolvedConstraints($state, $expr->pendingConstraints), $at ?? $expr);
    }

    match ($expr::class) {
        Ast\Apply::class => (static function () use ($state, $expr, $at): void {
            assertNoPendingConstraintsInExpr($state, $expr->function, $at);
            assertNoPendingConstraintsInExpr($state, $expr->argument, $at);
        })(),
        Ast\Infix::class => (static function () use ($state, $expr, $at): void {
            assertNoPendingConstraintsInExpr($state, $expr->left, $at);
            assertNoPendingConstraintsInExpr($state, $expr->right, $at);
        })(),
        Ast\Lambda::class => assertNoPendingConstraintsInExpr($state, $expr->body, $at),
        Ast\Let::class => (static function () use ($state, $expr, $at): void {
            foreach ($expr->bindings as $binding) {
                assertNoPendingConstraintsInExpr($state, $binding->value, $at);
            }

            assertNoPendingConstraintsInExpr($state, $expr->body, $at);
        })(),
        Ast\Where::class => (static function () use ($state, $expr, $at): void {
            foreach ($expr->bindings as $binding) {
                assertNoPendingConstraintsInExpr($state, $binding->value, $at);
            }

            assertNoPendingConstraintsInExpr($state, $expr->expr, $at);
        })(),
        Ast\CaseExpr::class => (static function () use ($state, $expr, $at): void {
            assertNoPendingConstraintsInExpr($state, $expr->scrutinee, $at);
            foreach ($expr->alts as $alt) {
                assertNoPendingConstraintsInExpr($state, $alt->body, $at);
            }
        })(),
        Ast\GuardsExpr::class => (static function () use ($state, $expr, $at): void {
            foreach ($expr->clauses as $clause) {
                assertNoPendingConstraintsInExpr($state, $clause->guard, $at);
                assertNoPendingConstraintsInExpr($state, $clause->body, $at);
            }
        })(),
        Ast\DoExpr::class => (static function () use ($state, $expr, $at): void {
            if (isset($expr->desugared)) {
                assertNoPendingConstraintsInExpr($state, $expr->desugared, $at);
            }
        })(),
        Ast\TypeAsc::class => assertNoPendingConstraintsInExpr($state, $expr->expr, $at),
        Ast\IntrinsicCall::class => (static function () use ($state, $expr, $at): void {
            foreach ($expr->args as $arg) {
                assertNoPendingConstraintsInExpr($state, $arg, $at);
            }
        })(),
        Ast\Tuple::class => (static function () use ($state, $expr, $at): void {
            foreach ($expr->elements as $elem) {
                assertNoPendingConstraintsInExpr($state, $elem, $at);
            }
        })(),
        Ast\ListLit::class => (static function () use ($state, $expr, $at): void {
            foreach ($expr->elements as $elem) {
                assertNoPendingConstraintsInExpr($state, $elem, $at);
            }
        })(),
        Ast\RecordCon::class => (static function () use ($state, $expr, $at): void {
            foreach ($expr->fields as $field) {
                assertNoPendingConstraintsInExpr($state, $field->expr, $at);
            }
        })(),
        Ast\RecordUpdate::class => (static function () use ($state, $expr, $at): void {
            assertNoPendingConstraintsInExpr($state, $expr->object, $at);
            foreach ($expr->fields as $field) {
                assertNoPendingConstraintsInExpr($state, $field->expr, $at);
            }
        })(),
        Ast\FieldAccess::class => assertNoPendingConstraintsInExpr($state, $expr->object, $at),
        default => null,
    };
}

function unifyCaseBranches(TypeCheckState $state, Type $left, Type $right, Ast\AstNode $at): void
{
    $saved = $state->unifyMessage;
    $state->unifyMessage = static function (Type $l, Type $r): string {
        $rename = friendlyTypeVarNames([$l, $r]);

        return 'case branches disagree: expected `'
            . typeToString($r, $rename) . '`, found `' . typeToString($l, $rename) . '`';
    };

    try {
        unify($state, $left, $right, $at);
    } finally {
        if ($saved === null) {
            $state->unifyMessage = null;
        } else {
            $state->unifyMessage = $saved;
        }
    }
}

/**
 * @param array<int, Ast\Binding> $bindings
 */
function assertBindingGroupLinear(TypeCheckState $state, array $bindings): void
{
    $seen = [];
    foreach ($bindings as $binding) {
        assertPatternLinear($state, $binding->pattern);
        foreach (patternVariableNamesOrdered($binding->pattern) as $name) {
            if (isset($seen[$name])) {
                throw typeFail(
                    $state,
                    "duplicate binding `{$name}` in the same group",
                    $binding->pattern,
                );
            }
            $seen[$name] = true;
        }
    }
}

/** @param array<int, Ast\AstNode> $params */
function assertParamListLinear(TypeCheckState $state, array $params): void
{
    $seen = [];
    foreach ($params as $param) {
        assertPatternLinear($state, $param);
        foreach (patternVariableNamesOrdered($param) as $name) {
            if (isset($seen[$name])) {
                throw typeFail($state, "duplicate binding `{$name}` in the same group", $param);
            }
            $seen[$name] = true;
        }
    }
}

function assertPatternLinear(TypeCheckState $state, Ast\AstNode $pattern): void
{
    $dup = patternDuplicateBinder($pattern);
    if ($dup !== null) {
        throw typeFail($state, "conflicting definitions for `{$dup[0]}`", $dup[1]);
    }
}

function bindPattern(TypeCheckState $state, Ast\AstNode $pattern, Type $expected, array $env, bool $generalize = false, array $constraints = []): array
{
    return match ($pattern::class) {
        Ast\PatWild::class => [$expected, $env],
        Ast\PatVar::class => (static function () use ($state, $pattern, $expected, $env, $generalize, $constraints): array {
            $pattern->binderId = ++$state->nextBinderId;
            [$type, $env] = bindVar($state, $pattern->name, $expected, $env, $generalize, $constraints);
            if (isset($env[$pattern->name])) {
                $env[$pattern->name]->binderId = $pattern->binderId;
            }
            $pattern->inferredType = internalTypeToAst(prune($state, $type), $state->subst);

            return [$type, $env];
        })(),
        Ast\PatLit::class => (static function () use ($state, $pattern, $expected, $env): array {
            if (\is_string($pattern->value)) {
                unify($state, $expected, new TStr());
            }
            // A numeric literal pattern is typed here only far enough to record
            // where it lands: whether it is the host `Int`'s literal or an
            // overloaded one is decided in `inferCase`, once the pattern has been
            // unified with the scrutinee.
            $pattern->inferredType = internalTypeToAst(prune($state, $expected), $state->subst);

            return [$expected, $env];
        })(),
        Ast\PatChar::class => (static function () use ($state, $expected, $env): array {
            unify($state, $expected, new TChar());

            return [$expected, $env];
        })(),
        Ast\PatCon::class => bindConPattern($state, $pattern, $expected, $env, $generalize, $constraints),
        Ast\PatRecord::class => bindRecordPattern($state, $pattern, $expected, $env, $generalize, $constraints),
        Ast\PatTuple::class => bindTuplePattern($state, $pattern, $expected, $env, $generalize, $constraints),
        Ast\PatNil::class => bindNilPattern($state, $expected, $env),
        Ast\PatCons::class => bindConsPattern($state, $pattern, $expected, $env, $generalize, $constraints),
        default => throw typeFail($state, "unsupported pattern " . $pattern::class),
    };
}

/**
 * @param array<string, Scheme> $env
 * @return array{0: Type, 1: array<string, Scheme>}
 */

function bindVar(TypeCheckState $state, string $name, Type $type, array $env, bool $generalize, array $constraints = []): array
{
    if ($generalize) {
        $type = prune($state, $type);
        $constraints = refreshConstraintArgs($state, $constraints);
        $env[$name] = scheme($type, schemeBoundVars($type, $env, $constraints), $constraints);
    } else {
        $env[$name] = scheme(prune($state, $type), []);
    }

    return [$type, $env];
}

function generalizePatternBindings(TypeCheckState $state, Ast\AstNode $pattern, array $env, array $constraints = []): array
{
    $monomorphicEnv = pruneEnvSchemes($state, $env);
    foreach (patternVariableNames($pattern) as $name) {
        if (!isset($monomorphicEnv[$name])) {
            continue;
        }

        $type = prune($state, $monomorphicEnv[$name]->type);
        $schemeEnv = $monomorphicEnv;
        unset($schemeEnv[$name]);
        $refreshedConstraints = refreshConstraintArgs($state, $constraints);
        $newScheme = scheme($type, schemeBoundVars($type, $schemeEnv, $refreshedConstraints), $refreshedConstraints);
        $newScheme->binderId = $monomorphicEnv[$name]->binderId ?? $env[$name]->binderId ?? null;
        $env[$name] = $newScheme;
    }

    return $env;
}

function pruneEnvSchemes(TypeCheckState $state, array $env): array
{
    foreach ($env as $name => $entry) {
        $type = prune($state, $entry->type);
        $constraints = $entry->constraints;
        foreach ($constraints as $ci => $constraint) {
            $args = $constraint->args;
            foreach ($args as $ai => $arg) {
                $args[$ai] = prune($state, $arg);
            }
            $constraints[$ci] = new Ast\PendingConstraint(
                $constraint->class,
                $args,
                $constraint->evidence,
                $constraint->implicit,
                $constraint->instanceHeadAst,
            );
        }
        // Keep the cached free-variable set consistent with the freshly pruned
        // type/constraints so envFreeVars (used right after by schemeBoundVars)
        // reflects the substitution instead of a stale pre-prune snapshot.
        $env[$name] = $entry->withPruned(
            $type,
            $constraints,
            schemeFreeTypeVars($type, $entry->bound, $constraints),
        );
    }

    return $env;
}

function bindConPattern(TypeCheckState $state, Ast\PatCon $pattern, Type $expected, array $env, bool $generalize, array $constraints = []): array
{
    $ctorScheme = $state->env[$pattern->name] ?? throw typeFail($state, "unknown constructor {$pattern->name}", $pattern);
    $ctorType = instantiate($state, $ctorScheme);
    $result = $ctorType;

    foreach ($pattern->args as $arg) {
        if (!$result instanceof TArrow) {
            throw typeFail($state, "constructor {$pattern->name} applied to too many arguments", $pattern);
        }
        [$argType, $env] = bindPattern($state, $arg, $result->from, $env, generalize: false, constraints: $constraints);
        $result = $result->to;
    }

    unify($state, $expected, $result);
    if ($generalize) {
        $env = generalizePatternBindings($state, $pattern, $env, $constraints);
    }

    return [$expected, $env];
}

/**
 * @param array<string, Scheme> $env
 * @return array{0: Type, 1: array<string, Scheme>}
 */

function bindNilPattern(TypeCheckState $state, Type $expected, array $env): array
{
    unify($state, $expected, new TCon('List', [freshType($state)]));

    return [$expected, $env];
}

/**
 * @param array<string, Scheme> $env
 * @return array{0: Type, 1: array<string, Scheme>}
 */

function bindConsPattern(TypeCheckState $state, Ast\PatCons $pattern, Type $expected, array $env, bool $generalize, array $constraints = []): array
{
    $elemType = freshType($state);
    unify($state, $expected, new TCon('List', [$elemType]));
    [$_, $env] = bindPattern($state, $pattern->head, $elemType, $env, generalize: false, constraints: $constraints);
    [$_, $env] = bindPattern($state, $pattern->tail, new TCon('List', [$elemType]), $env, generalize: false, constraints: $constraints);
    if ($generalize) {
        $env = generalizePatternBindings($state, $pattern, $env, $constraints);
    }

    return [$expected, $env];
}

/**
 * @param array<string, Scheme> $env
 * @return array{0: Type, 1: array<string, Scheme>}
 */

function bindTuplePattern(TypeCheckState $state, Ast\PatTuple $pattern, Type $expected, array $env, bool $generalize, array $constraints = []): array
{
    $parts = [];
    foreach ($pattern->elements as $element) {
        $part = freshType($state);
        [$_, $env] = bindPattern($state, $element, $part, $env, generalize: false, constraints: $constraints);
        $parts[] = $part;
    }

    $tupleType = count($parts) === 0 ? new TUnit() : new TCon('Tuple' . count($parts), $parts);
    unify($state, $expected, $tupleType);
    if ($generalize) {
        $env = generalizePatternBindings($state, $pattern, $env, $constraints);
    }

    return [$expected, $env];
}

/**
 * @param array<string, Scheme> $env
 * @return array{0: Type, 1: array<string, Scheme>}
 */

function bindRecordPattern(TypeCheckState $state, Ast\PatRecord $pattern, Type $expected, array $env, bool $generalize, array $constraints = []): array
{
    $meta = lookupConstructorMeta($state, $pattern->name)
        ?? throw typeFail($state, "unknown constructor {$pattern->name}", $pattern);
    $subpatterns = recordPatternFields($state, $pattern, $meta);

    $ctorScheme = $state->env[$pattern->name] ?? throw typeFail($state, "unknown constructor {$pattern->name}", $pattern);
    $ctorType = instantiate($state, $ctorScheme);
    $result = $ctorType;

    foreach ($subpatterns as $subpattern) {
        if (!$result instanceof TArrow) {
            throw typeFail($state, "constructor {$pattern->name} applied to too many arguments", $pattern);
        }
        [$_, $env] = bindPattern($state, $subpattern, $result->from, $env, generalize: false, constraints: $constraints);
        $result = $result->to;
    }

    unify($state, $expected, $result);
    if ($generalize) {
        $env = generalizePatternBindings($state, $pattern, $env, $constraints);
    }

    return [$expected, $env];
}

function inferRecordCon(TypeCheckState $state, Ast\RecordCon $expr, array $env): Type
{
    $meta = lookupConstructorMeta($state, $expr->name)
        ?? throw typeFail($state, "unknown constructor {$expr->name}", $expr);
    recordValueFields($state, $expr, $meta);
    [$fieldTypes, $resultType] = instantiateConstructorTypes($state, $expr->name);

    foreach ($meta['fields'] as $i => $fieldName) {
        $fieldExpr = recordValueFieldExpr($expr, $fieldName);
        unify($state, inferExpr($state, $fieldExpr, $env), $fieldTypes[$i], $fieldExpr);
    }

    return $resultType;
}

function instantiateConstructorTypes(TypeCheckState $state, string $ctorName): array
{
    $ctorScheme = $state->env[$ctorName] ?? throw typeFail($state, "unknown constructor {$ctorName}");
    $type = instantiate($state, $ctorScheme);
    $fieldTypes = [];
    while ($type instanceof TArrow) {
        $fieldTypes[] = $type->from;
        $type = $type->to;
    }

    return [$fieldTypes, $type];
}

function recordValueFieldExpr(Ast\RecordCon $expr, string $fieldName): Ast\AstNode
{
    foreach ($expr->fields as $field) {
        if ($field->name === $fieldName) {
            return $field->expr;
        }
    }

    throw new \LogicException("missing record field `{$fieldName}`");
}

function inferQualifiedRef(TypeCheckState $state, Ast\QualifiedRef $expr, array $env): Type
{
    $module = $expr->module;
    $name = $expr->name;
    $origin = $state->qualifiedOrigins[$module][$name] ?? null;
    if ($origin !== null) {
        $scheme = $state->qualifiedEnv[$module][$name]
            ?? $env[$name]
            ?? throw typeFail($state, "undefined name `{$origin['module']}.{$name}`", $expr);
        $expr->backendResolved = resolvedSymbol($origin['module'], $origin['phpName']);
        if (isset($state->intrinsicWrappers[$name])) {
            $expr->intrinsicWrapper = $state->intrinsicWrappers[$name];
        }

        return instantiateQualifiedScheme($state, $expr, $scheme);
    }

    $originModule = $state->qualifiedModules[$module] ?? null;
    if ($originModule === null) {
        throw typeFail(
            $state,
            appendDidYouMean(
                "unknown module prefix `{$module}`",
                $module,
                \array_keys($state->qualifiedModules),
            ),
            $expr,
        );
    }

    $scheme = $state->qualifiedEnv[$module][$name]
        ?? $env[$name]
        ?? throw typeFail($state, "undefined name `{$originModule}.{$name}`", $expr);
    $expr->backendResolved = resolvedSymbol($originModule, $name);
    if (isset($state->intrinsicWrappers[$name])) {
        $expr->intrinsicWrapper = $state->intrinsicWrappers[$name];
    }

    return instantiateQualifiedScheme($state, $expr, $scheme);
}

/**
 * Instantiate a qualified reference's scheme and keep the constraints on the
 * node. A qualified name is a value like any other, so `Mod.constrained` used
 * as an argument still has to carry its dictionaries to the use site
 * (`tryResolveValueEvidence` reads them off the node); dropping them here left
 * the call with a bare function value, e.g. Data.List.NonEmpty's
 * `liftList (DL.sort)`. `inferVariable` has always done this.
 */
function instantiateQualifiedScheme(TypeCheckState $state, Ast\QualifiedRef $expr, Scheme $scheme): Type
{
    $instantiated = instantiateScheme($state, $scheme);
    if ($instantiated['constraints'] !== []) {
        $expr->pendingConstraints = $instantiated['constraints'];
    }

    return $instantiated['type'];
}

function inferFieldAccess(TypeCheckState $state, Ast\AstNode &$expr, array $env): Type
{
    if (!$expr instanceof Ast\FieldAccess) {
        throw typeFail($state, 'expected field_access', $expr);
    }

    $object = $expr->object;
    if (($object instanceof Ast\ConstructorRef || $object instanceof Ast\Variable)
        && isset($state->qualifiedModules[$object->name])) {
        $expr = new Ast\QualifiedRef($object->name, $expr->field, $object->line, $object->col, $object->endCol);

        return inferQualifiedRef($state, $expr, $env);
    }

    $objectType = prune($state, inferExpr($state, $expr->object, $env));

    if ($objectType instanceof TCon && isset($state->data[$objectType->name])) {
        $fieldInfo = lookupFieldOnType($state, $objectType->name, $expr->field)
            ?? throw typeFail(
                $state,
                appendDidYouMean(
                    "record type `{$objectType->name}` has no field `{$expr->field}`",
                    $expr->field,
                    recordFieldNames($state, $objectType->name),
                ),
                $expr,
            );
        annotateFieldAccess($state, $expr, $objectType->name, $fieldInfo['index']);

        return instantiateFieldTypeForObject($state, $objectType, $fieldInfo['type']);
    }

    $candidates = fieldCandidates($state, $expr->field);
    if ($candidates === []) {
        throw typeFail(
            $state,
            appendDidYouMean(
                "unknown field `{$expr->field}`",
                $expr->field,
                allFieldNames($state),
            ),
            $expr,
        );
    }

    if ($objectType instanceof TVar) {
        if (count($candidates) === 1) {
            $candidate = $candidates[0];
            $freshDataType = freshDataTypeFromName($state, $candidate['dataName']);
            unify($state, $objectType, $freshDataType, $expr->object);
            annotateFieldAccess($state, $expr, $candidate['dataName'], $candidate['index']);

            return instantiateFieldTypeForObject($state, $freshDataType, $candidate['type']);
        }

        throw typeFail(
            $state,
            "ambiguous field `{$expr->field}`; add a type signature",
            $expr,
        );
    }

    throw typeFail(
        $state,
        'field access requires a record value, found `' . typeToString($objectType, friendlyTypeVarNames([$objectType])) . '`',
        $expr,
    );
}

/**
 * `r { f = e }`: the receiver with the named fields replaced, and the receiver's
 * own type.
 *
 * The written fields name the constructor to rebuild; the fields the update does
 * not mention are read back off the receiver at each constructor position (IR
 * lowering does that from the constructor it is told here).
 */
function inferRecordUpdate(TypeCheckState $state, Ast\RecordUpdate $expr, array $env): Type
{
    if ($expr->fields === []) {
        throw typeFail($state, 'a record update names at least one field', $expr);
    }

    $objectType = prune($state, inferExpr($state, $expr->object, $env));
    if ($objectType instanceof TVar) {
        $objectType = resolveRecordUpdateReceiver($state, $expr, $objectType);
    }

    if (!($objectType instanceof TCon) || !isset($state->data[$objectType->name])) {
        throw typeFail(
            $state,
            'record update requires a record value, found `'
                . typeToString($objectType, friendlyTypeVarNames([$objectType])) . '`',
            $expr,
        );
    }

    $dataName = $objectType->name;
    [$constructor, $positions] = recordUpdateConstructor($state, $expr, $dataName);
    $expr->constructor = $constructor;
    $expr->fieldOnNewtype = (bool) ($state->data[$dataName]['newtype'] ?? false);

    [$fieldTypes, $resultType] = instantiateConstructorTypes($state, $constructor);
    unify($state, $resultType, $objectType, $expr->object);
    foreach ($expr->fields as $field) {
        $type = $fieldTypes[$positions[$field->name]];
        unify($state, inferExpr($state, $field->expr, $env), $type, $field->expr);
    }

    return $objectType;
}

/**
 * A receiver whose type is not known yet is pinned by the fields the update names,
 * the same way a projection is: the record that has them all is the record here.
 *
 * @return TCon
 */
function resolveRecordUpdateReceiver(TypeCheckState $state, Ast\RecordUpdate $expr, TVar $objectType): Type
{
    $candidates = null;
    foreach ($expr->fields as $field) {
        $here = [];
        foreach (fieldCandidates($state, $field->name) as $candidate) {
            $here[$candidate['dataName']] = true;
        }
        $candidates = $candidates === null ? $here : \array_intersect_key($candidates, $here);
    }

    $names = array_keys($candidates ?? []);
    if (count($names) === 1) {
        $fresh = freshDataTypeFromName($state, $names[0]);
        unify($state, $objectType, $fresh, $expr->object);

        return $fresh;
    }

    $first = $expr->fields[0];
    if ($names === []) {
        throw typeFail(
            $state,
            appendDidYouMean(
                "unknown field `{$first->name}`",
                $first->name,
                allFieldNames($state),
            ),
            $expr,
        );
    }

    throw typeFail(
        $state,
        "ambiguous record update: `{$first->name}` is a field of " . implode(' and ', $names)
            . '; add a type signature',
        $expr,
    );
}

/**
 * The constructor an update rebuilds, and each written field's position in it.
 *
 * A field name can be shared by records, and by constructors of one record, so
 * the update's own field names pick the constructor — the one that carries every
 * one of them. It has to be the type's only constructor: the answer to "what does
 * the receiver look like now" has to hold for the value that arrives at runtime,
 * and with a second constructor it cannot. `base` reaches for a selector and
 * raises when the value was built by the other one; Moggi refuses the shape at
 * compile time, which is the whole point of not having a runtime case for it.
 *
 * @return array{0: string, 1: array<string, int>}
 */
function recordUpdateConstructor(TypeCheckState $state, Ast\RecordUpdate $expr, string $dataName): array
{
    $data = $state->data[$dataName];
    $carrying = [];
    foreach ($data['constructors'] as $constructor => $ctor) {
        $positions = [];
        foreach ($ctor['fields'] as $position => $name) {
            if ($name !== null && $name !== '') {
                $positions[$name] = $position;
            }
        }

        $holds = true;
        foreach ($expr->fields as $field) {
            if (!isset($positions[$field->name])) {
                $holds = false;
                break;
            }
        }

        if ($holds) {
            $carrying[] = [$constructor, $positions];
        }
    }

    if ($carrying === []) {
        $known = recordFieldNames($state, $dataName);
        foreach ($expr->fields as $field) {
            if (!\in_array($field->name, $known, true)) {
                throw typeFail(
                    $state,
                    appendDidYouMean(
                        "record type `{$dataName}` has no field `{$field->name}`",
                        $field->name,
                        $known,
                    ),
                    $expr,
                );
            }
        }

        throw typeFail(
            $state,
            'no constructor of `' . $dataName . '` carries every field the update names: `'
                . implode('`, `', \array_map(static fn (Ast\RecordField $f): string => $f->name, $expr->fields))
                . '`',
            $expr,
        );
    }

    $constructors = array_keys($data['constructors']);
    if (count($carrying) > 1 || count($constructors) > 1) {
        throw typeFail(
            $state,
            "record update needs a `{$dataName}` with one constructor, but it has: `"
                . implode('`, `', $constructors) . '`',
            $expr,
        );
    }

    return $carrying[0];
}

function recordFieldNames(TypeCheckState $state, string $dataName): array
{
    $names = [];
    foreach ($state->data[$dataName]['constructors'] ?? [] as $ctor) {
        foreach ($ctor['fields'] as $name) {
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }
    }

    return array_values(array_unique($names));
}

function allFieldNames(TypeCheckState $state): array
{
    $names = [];
    foreach ($state->data as $data) {
        foreach ($data['constructors'] as $ctor) {
            foreach ($ctor['fields'] as $name) {
                if ($name !== null && $name !== '') {
                    $names[] = $name;
                }
            }
        }
    }

    return array_values(array_unique($names));
}

function fieldCandidates(TypeCheckState $state, string $fieldName): array
{
    $candidates = [];
    foreach ($state->data as $dataName => $data) {
        foreach ($data['constructors'] as $ctor) {
            foreach ($ctor['fields'] as $index => $name) {
                if ($name === $fieldName) {
                    $candidates[] = [
                        'dataName' => $dataName,
                        'index' => $index,
                        'type' => $ctor['fieldTypes'][$index],
                    ];
                }
            }
        }
    }

    return $candidates;
}

function lookupConstructorMeta(TypeCheckState $state, string $ctorName): ?array
{
    foreach ($state->data as $dataName => $data) {
        if (!isset($data['constructors'][$ctorName])) {
            continue;
        }

        return ['dataName' => $dataName, ...$data['constructors'][$ctorName]];
    }

    return null;
}

/** Records where the field lives, so IR lowering reads the position the receiver's type gives it. */
function annotateFieldAccess(
    TypeCheckState $state,
    Ast\FieldAccess $expr,
    string $dataName,
    int $index,
): void {
    $expr->fieldIndex = $index;
    $expr->fieldOnNewtype = (bool) ($state->data[$dataName]['newtype'] ?? false);
}

function lookupFieldOnType(TypeCheckState $state, string $dataName, string $fieldName): ?array
{
    $data = $state->data[$dataName] ?? null;
    if ($data === null) {
        return null;
    }

    foreach ($data['constructors'] as $ctor) {
        foreach ($ctor['fields'] as $index => $name) {
            if ($name === $fieldName) {
                return ['index' => $index, 'type' => $ctor['fieldTypes'][$index]];
            }
        }
    }

    return null;
}

function freshDataTypeFromName(TypeCheckState $state, string $dataName): Type
{
    $data = $state->data[$dataName];
    $paramTypes = \array_map(static fn (string $_): Type => freshType($state), $data['params']);

    return new TCon($dataName, $paramTypes);
}

function instantiateFieldTypeForObject(TypeCheckState $state, Type $objectType, Type $fieldType): Type
{
    $objectType = prune($state, $objectType);
    if (!$objectType instanceof TCon || !isset($state->data[$objectType->name])) {
        return $fieldType;
    }

    $mapping = [];
    foreach ($state->data[$objectType->name]['params'] as $i => $param) {
        if (isset($objectType->args[$i])) {
            $mapping[$param] = $objectType->args[$i];
        }
    }

    return substitute($fieldType, $mapping);
}

/**
 * @param array{dataName: string, name: string, fields: array<int, string>, fieldTypes: array<int, array<string, mixed>>} $meta
 * @return list<Ast\AstNode> sub-patterns in constructor field order
 */
function recordPatternFields(TypeCheckState $state, Ast\PatRecord $pattern, array $meta): array
{
    $byName = [];
    foreach ($pattern->fields as $field) {
        if (isset($byName[$field->name])) {
            throw typeFail($state, "duplicate field `{$field->name}` in pattern", $pattern);
        }
        $byName[$field->name] = $field->pattern;
    }

    $subpatterns = [];
    foreach ($meta['fields'] as $fieldName) {
        // A field the pattern does not name is not an error: it is not read.
        $subpatterns[] = $byName[$fieldName] ?? new Ast\PatWild($pattern->line, $pattern->col, $pattern->endCol);
    }

    foreach (\array_keys($byName) as $extra) {
        if (!\in_array($extra, $meta['fields'], true)) {
            throw typeFail($state, "unknown field `{$extra}` in record pattern", $pattern);
        }
    }

    return $subpatterns;
}

/**
 * @param array{dataName: string, name: string, fields: array<int, string>, fieldTypes: array<int, array<string, mixed>>} $meta
 * @return array<string, array<string, mixed>>
 */

function recordValueFields(TypeCheckState $state, Ast\RecordCon $expr, array $meta): array
{
    $byName = [];
    foreach ($expr->fields as $field) {
        if (isset($byName[$field->name])) {
            throw typeFail($state, "duplicate field `{$field->name}` in record construction", $expr);
        }
        $byName[$field->name] = $field->expr;
    }

    foreach ($meta['fields'] as $fieldName) {
        if (!isset($byName[$fieldName])) {
            throw typeFail($state, "missing field `{$fieldName}` in record construction", $expr);
        }
    }

    foreach (\array_keys($byName) as $extra) {
        if (!\in_array($extra, $meta['fields'], true)) {
            throw typeFail($state, "unknown field `{$extra}` in record construction", $expr);
        }
    }

    return $byName;
}

function astTypeSignature(TypeCheckState $state, Ast\TypeNode $typeAst, array $expanding = [], ?Ast\AstNode $at = null): Type
{
    [$constraintAsts, $bodyTypeAst] = splitTypeAst($typeAst);
    if ($constraintAsts === []) {
        return astType($state, $bodyTypeAst, $expanding);
    }

    $signatureConstraints = parseConstraints($state, $constraintAsts, $at);
    applyConstraintVarKinds($state, $signatureConstraints);
    $result = astType($state, $bodyTypeAst, $expanding);
    foreach (array_reverse($signatureConstraints) as $constraint) {
        $result = new TArrow(new TCon('__Dict_' . $constraint->class), $result);
    }

    return $result;
}

function astType(TypeCheckState $state, Ast\TypeNode $type, array $expanding = []): Type
{
    return match ($type::class) {
        Ast\TypeVar::class => new TVar($type->name),
        Ast\TypeUnit::class => new TUnit(),
        Ast\TypeCon::class => resolveTypeCon($state, $type->name, $expanding, $type),
        Ast\TypePromoted::class => resolvePromotedType($state, $type->name, $type),
        Ast\TypeStringLit::class => new TStringLit($type->value),
        Ast\TypeNatLit::class => astTypeNatLit($state, $type),
        Ast\TypeApp::class => applyTypeApp($state, $type->con, $type->args, $expanding),
        Ast\TypeArrow::class => astTypeArrow($state, $type, $expanding),
        Ast\TypeConstrained::class => astTypeSignature($state, $type, $expanding),
        Ast\TypeQualified::class => resolveQualifiedType($state, $type->module, $type->name, $expanding, $type),
        default => throw typeFail($state, "unknown type " . $type::class),
    };
}

function astTypeArrow(TypeCheckState $state, Ast\TypeArrow $type, array $expanding): Type
{
    $from = astType($state, $type->from, $expanding);
    $to = astType($state, $type->to, $expanding);
    Kinds\assertKind($state, $from, new Kinds\KType(), $state->varKinds, $type->from);
    Kinds\assertKind($state, $to, new Kinds\KType(), $state->varKinds, $type->to);

    return new TArrow($from, $to);
}

function resolveQualifiedType(TypeCheckState $state, string $module, string $name, array $expanding, Ast\AstNode|Ast\TypeNode|null $at = null): Type
{
    $namespace = $state->qualifiedModules[$module] ?? null;
    if ($namespace === null) {
        throw typeFail(
            $state,
            appendDidYouMean(
                "unknown module prefix `{$module}` in type",
                $module,
                \array_keys($state->qualifiedModules),
            ),
            $at,
        );
    }

    $prim = resolveMagicHashTypeIfInScope($state, $name, $expanding, $at);
    if ($prim !== null) {
        return $prim;
    }

    if (isset($state->typeSynonyms[$name])) {
        if (isset($expanding[$name])) {
            throw typeFail($state, "cyclic type synonym `{$name}`", $at);
        }

        $syn = $state->typeSynonyms[$name];
        if ($syn['params'] !== []) {
            throw typeFail(
                $state,
                "type synonym `{$name}` is parametric and must be applied to "
                    . count($syn['params']) . ' argument(s)',
                $at,
            );
        }

        return $syn['body'] ?? astType($state, $syn['rhs'], [...$expanding, $name => true]);
    }

    if (isset($state->data[$name])) {
        $info = $state->data[$name];

        return $info['result'];
    }

    if ($name === 'Ordering') {
        return new TCon('Ordering');
    }

    if ($name === 'Bool') {
        return new TCon('Bool');
    }

    rejectIfKindNameUsedAsType($state, $name, $at);

    return new TCon($name);
}

/**
 * MagicHash primitive types (Int#, IO#, …) resolve when imported from
 * Moggi.Internal.Prim / Moggi.Internal.IO, or while expanding a public synonym
 * whose RHS mentions them (`type IO = IO#` → use of `IO` without Magichash import).
 *
 * @param array<string, true> $expanding
 */
function resolveMagicHashTypeIfInScope(
    TypeCheckState $state,
    string $name,
    array $expanding = [],
    Ast\AstNode|Ast\TypeNode|null $at = null,
): ?Type {
    $resolved = magicHashInternalType($name);
    if ($resolved === null) {
        return null;
    }

    if (!isset($state->data[$name]) && $expanding === []) {
        $owner = primitiveTypeOwnerModule($name) ?? 'Moggi.Internal.Prim';
        throw typeFail(
            $state,
            "unknown type constructor `{$name}` (import `{$owner}` to use compiler primitives)",
            $at,
        );
    }

    return $resolved;
}

function magicHashInternalType(string $name): ?Type
{
    return match ($name) {
        'String#' => new TStr(),
        'Bytes#' => new TBytes(),
        'Int#' => new TInt(),
        'Integer#' => new TCon('Integer'),
        'Natural#' => new TCon('Natural'),
        'Char#' => new TChar(),
        'Word#' => new TWord(),
        'Word8#' => new TWord8(),
        'Word16#' => new TWord16(),
        'Word32#' => new TWord32(),
        'Word64#' => new TWord64(),
        'Int8#' => new TInt8(),
        'Int16#' => new TInt16(),
        'Int32#' => new TInt32(),
        'Int64#' => new TInt64(),
        'Double#' => new TDouble(),
        'List#' => new TCon('List'),
        'IO#' => new TCon('IO'),
        'SomeException#' => new TCon('SomeException'),
        default => null,
    };
}

/** DataKinds: elaborate `'Red` into the internal promoted-type representation. */
function resolvePromotedType(TypeCheckState $state, string $name, Ast\AstNode|Ast\TypeNode|null $at = null): Type
{
    if (!isset($state->promoted[$name])) {
        if (constructorNameExists($state, $name)) {
            throw typeFail(
                $state,
                "data constructor `{$name}` is not promotable (fields must have promotable kinds)",
                $at,
            );
        }

        throw typeFail($state, "unknown promoted constructor `'{$name}`", $at);
    }

    return new TPromoted($name);
}

/** Reject built-in kind names (`Type`, `Symbol`, `Nat`) used where a type is expected. */
function rejectIfKindNameUsedAsType(TypeCheckState $state, string $name, Ast\AstNode|Ast\TypeNode|null $at = null): void
{
    if ($name === 'Type' || $name === 'Symbol' || $name === 'Nat') {
        throw typeFail($state, "`{$name}` is a kind, not a type", $at);
    }
}

function astTypeNatLit(TypeCheckState $state, Ast\TypeNatLit $type): TNatLit
{
    if ($type->negative) {
        throw typeFail(
            $state,
            'numeric type literal must be a natural number (Nat), got -' . $type->digits,
            $type,
        );
    }

    return new TNatLit(canonicalNatDigits($type->digits));
}

function resolveTypeCon(TypeCheckState $state, string $name, array $expanding, Ast\AstNode|Ast\TypeNode|null $at = null): Type
{
    $prim = resolveMagicHashTypeIfInScope($state, $name, $expanding, $at);
    if ($prim !== null) {
        return $prim;
    }

    if ($name === 'Ordering') {
        return new TCon('Ordering');
    }

    if ($name === 'Bool') {
        return new TCon('Bool');
    }

    rejectIfKindNameUsedAsType($state, $name, $at);

    if (isset($state->typeSynonyms[$name])) {
        if (isset($expanding[$name])) {
            throw typeFail($state, "cyclic type synonym `{$name}`", $at);
        }

        $syn = $state->typeSynonyms[$name];
        if ($syn['params'] !== []) {
            throw typeFail(
                $state,
                "type synonym `{$name}` is parametric and must be applied to "
                    . count($syn['params']) . ' argument(s)',
                $at,
            );
        }

        return $syn['body'] ?? astType($state, $syn['rhs'], [...$expanding, $name => true]);
    }

    if (isUndeclaredNominalTypeCon($state, $name)) {
        throw typeFail(
            $state,
            appendDidYouMean(
                "unknown type constructor `{$name}`",
                $name,
                knownTypeConstructorNames($state),
            ),
            $at,
        );
    }

    return new TCon($name);
}

function isUndeclaredNominalTypeCon(TypeCheckState $state, string $name): bool
{
    if (isset($state->data[$name]) || isset($state->kindEnv[$name]) || isset($state->declaredTypeNames[$name])) {
        return false;
    }

    return $name !== '' && ctype_upper($name[0]);
}

/**
 * @param list<Ast\TypeNode> $argAsts
 */
function applyTypeApp(TypeCheckState $state, Ast\TypeNode $con, array $argAsts, array $expanding): Type
{
    if ($argAsts === []) {
        throw typeFail($state, 'type application requires arguments', $con);
    }

    if ($con instanceof Ast\TypeVar) {
        $args = [];
        foreach ($argAsts as $argAst) {
            $args[] = astType($state, $argAst, $expanding);
        }
        applyTypeAppKinds($state, $con->name, $args, $argAsts, $con, isVarHead: true);

        return new TCon($con->name, $args);
    }

    // Nested TypeApp head (e.g. from synonym expand-under-app AST shaping): flatten.
    if ($con instanceof Ast\TypeApp) {
        $flat = flattenTypeAppAst($con);
        if ($flat !== null) {
            return applyTypeApp($state, $flat[0], [...$flat[1], ...$argAsts], $expanding);
        }
    }

    if ($con instanceof Ast\TypePromoted) {
        return applyPromotedTypeApp($state, $con, $argAsts, $expanding);
    }

    $baseName = match ($con::class) {
        Ast\TypeCon::class => $con->name,
        Ast\TypeQualified::class => $con->name,
        default => throw typeFail($state, 'invalid type application head', $con),
    };

    if ($con instanceof Ast\TypeQualified) {
        resolveQualifiedType($state, $con->module, $con->name, $expanding, $con);
    }

    if (isset($state->typeSynonyms[$baseName])) {
        return expandSynonymApp($state, $baseName, $argAsts, $expanding, $con);
    }

    $args = [];
    foreach ($argAsts as $argAst) {
        $args[] = astType($state, $argAst, $expanding);
    }

    resolveTypeCon($state, $baseName, $expanding, $con);

    $canonical = Kinds\canonicalTypeConName($baseName);
    applyTypeAppKinds($state, $canonical, $args, $argAsts, $con, isVarHead: false);

    return new TCon($canonical, $args);
}

/**
 * DataKinds: `'InfixI 'LeftAssociative` — promoted constructor as type head.
 *
 * @param list<Ast\TypeNode> $argAsts
 */
function applyPromotedTypeApp(
    TypeCheckState $state,
    Ast\TypePromoted $con,
    array $argAsts,
    array $expanding,
): Type {
    resolvePromotedType($state, $con->name, $con);

    $args = [];
    foreach ($argAsts as $argAst) {
        $args[] = astType($state, $argAst, $expanding);
    }

    applyPromotedTypeAppKinds($state, $con->name, $args, $argAsts, $con);

    return new TPromoted($con->name, $args);
}

/**
 * @param list<Type> $args
 * @param list<Ast\TypeNode> $argAsts
 */
function applyPromotedTypeAppKinds(
    TypeCheckState $state,
    string $headName,
    array $args,
    array $argAsts,
    Ast\AstNode|Ast\TypeNode $headAt,
): void {
    $headKind = Kinds\kindOfPromoted($state, $headName);
    $label = "promoted constructor `'{$headName}`";

    foreach ($args as $i => $arg) {
        $ctx = Kinds\kindInferCtxFromState($state);
        $headKind = Kinds\pruneKind($ctx, $headKind);

        if (!$headKind instanceof Kinds\KArrow && !$headKind instanceof Kinds\KVar) {
            throw typeFail($state, "{$label} applied to too many arguments", $headAt);
        }

        $argExpected = Kinds\freshKindVar($ctx);
        $result = Kinds\freshKindVar($ctx);
        try {
            Kinds\unifyKind($ctx, $headKind, new Kinds\KArrow($argExpected, $result));
        } catch (\InvalidArgumentException $e) {
            throw typeFail($state, "{$label} applied to too many arguments", $headAt);
        }
        Kinds\commitKindInferCtx($state, $ctx);

        checkTypeAppArgKind($state, $arg, Kinds\pruneKind($ctx, $argExpected), $argAsts[$i], $headAt);

        $ctx = Kinds\kindInferCtxFromState($state);
        $headKind = Kinds\pruneKind($ctx, $result);
    }
}

/**
 * @return array{0: Ast\TypeNode, 1: list<Ast\TypeNode>}|null
 */
function flattenTypeAppAst(Ast\TypeNode $type): ?array
{
    // Iterative flatten — see flattenInstanceHeadTypeApp.
    $args = [];
    while ($type instanceof Ast\TypeApp) {
        for ($i = count($type->args) - 1; $i >= 0; $i--) {
            $args[] = $type->args[$i];
        }
        $type = $type->con;
    }

    if (
        $type instanceof Ast\TypeCon
        || $type instanceof Ast\TypeVar
        || $type instanceof Ast\TypeQualified
        || $type instanceof Ast\TypePromoted
    ) {
        return [$type, array_reverse($args)];
    }

    return null;
}

/**
 * Expand a (possibly parametric) type synonym under application, then continue
 * elaborating any remaining arguments (`type T = Maybe` then `T Int`).
 *
 * @param list<Ast\TypeNode> $argAsts
 */
function expandSynonymApp(
    TypeCheckState $state,
    string $name,
    array $argAsts,
    array $expanding,
    Ast\AstNode|Ast\TypeNode $at,
): Type {
    if (isset($expanding[$name])) {
        throw typeFail($state, "cyclic type synonym `{$name}`", $at);
    }

    $syn = $state->typeSynonyms[$name];
    $params = $syn['params'];
    $arity = count($params);
    if (count($argAsts) < $arity) {
        throw typeFail(
            $state,
            "type synonym `{$name}` applied to too few arguments (expected {$arity})",
            $at,
        );
    }

    $body = $syn['body'] ?? null;
    if ($body !== null) {
        $bodyMapping = [];
        for ($i = 0; $i < $arity; ++$i) {
            $bodyMapping[$params[$i]] = astType($state, $argAsts[$i], $expanding);
        }
        $headType = substitute($body, $bodyMapping);
        $remaining = \array_slice($argAsts, $arity);
        if ($remaining === []) {
            return $headType;
        }

        return applyTypeHeadToArgAsts($state, $headType, $remaining, $expanding, $at);
    }

    $mapping = [];
    for ($i = 0; $i < $arity; ++$i) {
        $mapping[$params[$i]] = $argAsts[$i];
    }
    $expanded = substituteTypeAstParams($syn['rhs'], $mapping);
    $remaining = \array_slice($argAsts, $arity);
    $rhsExpanding = [...$expanding, $name => true];

    $headType = astType($state, $expanded, $rhsExpanding);
    if ($remaining === []) {
        return $headType;
    }

    return applyTypeHeadToArgAsts($state, $headType, $remaining, $expanding, $at);
}

/**
 * @param list<Ast\TypeNode> $argAsts
 */
function applyTypeHeadToArgAsts(
    TypeCheckState $state,
    Type $headType,
    array $argAsts,
    array $expanding,
    Ast\AstNode|Ast\TypeNode $at,
): Type {
    $headType = prune($state, $headType);
    if (!$headType instanceof TCon) {
        throw typeFail($state, 'type synonym expanded to a non-constructor type that cannot take arguments', $at);
    }

    $newArgs = [];
    foreach ($argAsts as $argAst) {
        $newArgs[] = astType($state, $argAst, $expanding);
    }
    $allArgs = [...$headType->args, ...$newArgs];
    $allAsts = [
        ...\array_fill(0, count($headType->args), $at),
        ...$argAsts,
    ];

    applyTypeAppKinds($state, $headType->name, $allArgs, $allAsts, $at, isVarHead: false);

    return new TCon($headType->name, $allArgs);
}

/**
 * Incremental kind rule for each argument: `f :: k1 -> k2`, `a :: k1` ⊢ `f a :: k2`.
 * Kind variables are introduced when the head kind is not yet an arrow.
 *
 * @param list<Type> $args
 * @param list<Ast\TypeNode> $argAsts
 */
function applyTypeAppKinds(
    TypeCheckState $state,
    string $headName,
    array $args,
    array $argAsts,
    Ast\AstNode|Ast\TypeNode $headAt,
    bool $isVarHead,
): void {
    if ($isVarHead) {
        if (!isset($state->varKinds[$headName])) {
            $state->varKinds[$headName] = Kinds\freshStateKindVar($state);
        }
        $headKind = $state->varKinds[$headName];
    } else {
        $headKind = $state->kindEnv[$headName] ?? new Kinds\KType();
    }

    $label = $isVarHead ? "type variable `{$headName}`" : "type constructor `{$headName}`";

    foreach ($args as $i => $arg) {
        $ctx = Kinds\kindInferCtxFromState($state);
        $headKind = Kinds\pruneKind($ctx, $headKind);

        if ($isVarHead && ($headKind instanceof Kinds\KType || $headKind instanceof Kinds\KCon)) {
            throw typeFail($state, "type variable `{$headName}` is not a type constructor", $headAt);
        }

        // Concrete type-constructor spines peel in O(1); `isVarHead` params must keep their
        // full kind across apps, so the unify path below handles those.
        if (!$isVarHead && $headKind instanceof Kinds\KArrow) {
            checkTypeAppArgKind($state, $arg, $headKind->from, $argAsts[$i], $headAt);
            $headKind = $headKind->to;
            continue;
        }

        if (!$headKind instanceof Kinds\KArrow && !$headKind instanceof Kinds\KVar) {
            throw typeFail($state, "{$label} applied to too many arguments", $headAt);
        }

        $argExpected = Kinds\freshKindVar($ctx);
        $result = Kinds\freshKindVar($ctx);
        try {
            Kinds\unifyKind($ctx, $headKind, new Kinds\KArrow($argExpected, $result));
        } catch (\InvalidArgumentException $e) {
            throw typeFail($state, "{$label} applied to too many arguments", $headAt);
        }
        Kinds\commitKindInferCtx($state, $ctx);

        checkTypeAppArgKind($state, $arg, Kinds\pruneKind($ctx, $argExpected), $argAsts[$i], $headAt);

        $ctx = Kinds\kindInferCtxFromState($state);
        if ($isVarHead) {
            $headKind = Kinds\pruneKind($ctx, $state->varKinds[$headName]);
            $state->varKinds[$headName] = $headKind;
        } else {
            $headKind = Kinds\pruneKind($ctx, $result);
        }
    }
}

/**
 * Kind-check one argument of a type application. Free type variables take on
 * the expected argument kind (so `OnlyColor c` gives `c :: Color`).
 */
function checkTypeAppArgKind(
    TypeCheckState $state,
    Type $arg,
    Kinds\Kind $expected,
    Ast\AstNode|Ast\TypeNode $argAt,
    Ast\AstNode|Ast\TypeNode $headAt,
): void {
    $arg = prune($state, $arg);
    $ctx = Kinds\kindInferCtxFromState($state);
    $expected = Kinds\pruneKind($ctx, $expected);

    if ($arg instanceof TVar) {
        if (!isset($state->varKinds[$arg->name])) {
            $state->varKinds[$arg->name] = $expected;

            return;
        }

        try {
            Kinds\unifyKind($ctx, $state->varKinds[$arg->name], $expected);
        } catch (\InvalidArgumentException $e) {
            throw typeFail($state, $e->getMessage(), $argAt);
        }
        Kinds\commitKindInferCtx($state, $ctx);

        return;
    }

    $actual = Kinds\kindOfType($state, $arg, $state->varKinds);
    try {
        Kinds\unifyKind($ctx, $actual, $expected);
    } catch (\InvalidArgumentException $e) {
        $head = $headAt instanceof Ast\TypeCon || $headAt instanceof Ast\TypeVar
            ? $headAt->name
            : ($headAt instanceof Ast\TypeQualified ? $headAt->name : 'type');
        throw typeFail(
            $state,
            'kind mismatch applying `' . $head . '`: expected `'
                . Kinds\kindToString(Kinds\pruneKind($ctx, $expected)) . '`, got `'
                . Kinds\kindToString(Kinds\pruneKind($ctx, $actual)) . '`',
            $argAt,
        );
    }
    Kinds\commitKindInferCtx($state, $ctx);
}

function inferIntrinsic(TypeCheckState $state, Ast\IntrinsicCall $expr, array $env): Type
{
    $internal = resolveIntrinsicName($expr->name);
    if ($internal === null) {
        throw typeFail($state, \sprintf("unknown intrinsic `%s`", $expr->name), $expr);
    }

    // IntrinsicCall is compiler-internal AST (rewritten `#` apps, Ord defaults).
    // User-facing scope is enforced when resolving `#` names as ordinary Vars.
    $schemes = typeSchemes();
    $scheme = $schemes[$internal];
    $type = instantiate($state, $scheme);
    foreach ($expr->args as &$arg) {
        $argType = inferExpr($state, $arg, $env);
        $result = freshType($state);
        unify($state, $type, new TArrow($argType, $result), $expr);
        $type = $result;
    }
    unset($arg);

    $expr->name = $internal;
    $expr->intrinsicId = $internal;

    return prune($state, $type);
}

function inferForeignCall(TypeCheckState $state, Ast\ForeignCall $expr, array $env): Type
{
    $scheme = $env[$expr->name] ?? throw typeFail($state, "undefined foreign import `{$expr->name}`", $expr);
    $type = instantiate($state, $scheme);

    if ($expr->args === []) {
        return prune($state, $type);
    }

    foreach ($expr->args as &$arg) {
        $argType = inferExpr($state, $arg, $env);
        $result = freshType($state);
        unify($state, $type, new TArrow($argType, $result), $expr);
        $type = $result;
    }
    unset($arg);

    return prune($state, $type);
}
