<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\TypeExpr\scheme;

require_once __DIR__ . '/type_core.php';
require_once __DIR__ . '/allocation.php';
require_once __DIR__ . '/constraints.php';

/**
 * A recursive top-level binding group, checked once.
 *
 * A cycle has no order in which its members could publish a scheme one after
 * another, so the group allocates a *monotype* for every member up front, checks
 * each body once against those monotypes, decides the group's dictionaries from
 * what the bodies reported, then lifts each member: the recursion's own evidence
 * is in scope for every body, and a recursive call is given its callee's
 * dictionaries. No body is inferred twice.
 *
 * A restricted member (`f = e`, no parameters) is inferred here too, but its
 * variables belong to the module end, so it is deferred (@see deferDeclaration()).
 */
function inferRecursiveDeclarations(TypeCheckState $state, array $members): array
{
    $savedState = [
        $state->allocation,
        $state->rigidVars,
        $state->varKinds,
        $state->ambientConstraints,
        $state->ambientConstraintsByClass,
    ];
    $state->subst = [];
    $state->holes = [];

    try {
        $group = allocateRecursiveMonotypes($state, $members);
        foreach ($group as $member) {
            inferRecursiveMemberBody($state, $member);
        }

        $solved = solveRecursiveGroupConstraints($state, $group);
        assertRestrictedMembersNeedNoEvidence($state, $group, $solved);

        $groupEnv = $state->env;
        foreach ($group as $member) {
            unset($state->env[$member->name]);
        }

        $checked = [];
        $published = [];
        try {
            foreach ([true, false] as $restrictedOnly) {
                foreach ($group as $member) {
                    if ($member->restricted !== $restrictedOnly) {
                        continue;
                    }

                    finishRecursiveMember($state, $member, $solved[$member->name] ?? []);
                    $checked[\spl_object_id($member->fn)] = $member->fn;
                }
            }

            foreach ($group as $member) {
                $published[$member->name] = $state->env[$member->name];
            }
        } finally {
            $state->env = $published + $groupEnv;
        }

        recordRestrictedSolutions($state);

        return $checked;
    } finally {
        [
            $state->allocation,
            $state->rigidVars,
            $state->varKinds,
            $state->ambientConstraints,
            $state->ambientConstraintsByClass,
        ] = $savedState;
    }
}

/**
 * Refuse a restricted member that calls a member the group gave dictionaries.
 *
 * Its body is checked before the group decides those dictionaries, so the call
 * would be lowered as a partial application and fail at run time; the shape is
 * refused by name. A signature on the restricted member moves it out of this
 * producer, where the call is checked against the published scheme.
 *
 * @param list<RecursiveMember> $group
 * @param array<string, list<Ast\PendingConstraint>> $solved
 */
function assertRestrictedMembersNeedNoEvidence(TypeCheckState $state, array $group, array $solved): void
{
    $withDictionaries = [];
    foreach ($group as $member) {
        if (! $member->restricted && ($solved[$member->name] ?? []) !== []) {
            $withDictionaries[$member->name] = true;
        }
    }

    if ($withDictionaries === []) {
        return;
    }

    foreach ($group as $member) {
        if (! $member->restricted) {
            continue;
        }

        walkAstValues($member->fn->body, static function (Ast\AstNode $node) use ($state, $member, $withDictionaries): void {
            if ($node instanceof Ast\Variable && isset($withDictionaries[$node->name])) {
                throw typeFail(
                    $state,
                    "`{$node->name}` takes dictionaries, and the pattern binding `{$member->name}` calls it "
                    . 'from a recursive group that has not decided them yet; give the caller a signature',
                    $node,
                );
            }
        });
    }
}

/**
 * One member of a group while its body is being checked: the monotype every
 * other member sees for it, the allocation that owns its dictionaries, and
 * whether its variables are the module end's to settle.
 */
final class RecursiveMember
{
    public function __construct(
        public readonly Ast\FunctionDecl $fn,
        public readonly string $name,
        public readonly Type $monotype,
        public readonly DeclarationAllocation $allocation,
        public readonly bool $restricted,
    ) {
    }
}

/**
 * Give every member a monotype and publish it, before any body is checked.
 *
 * One fresh variable per parameter and one for the result, with no dictionary
 * arrows: the lift is unknown until the bodies are typed. A restricted member
 * gets a single variable.
 *
 * @param list<Ast\FunctionDecl> $members
 * @return list<RecursiveMember>
 */
function allocateRecursiveMonotypes(TypeCheckState $state, array $members): array
{
    $group = [];
    foreach ($members as $fn) {
        if (! $fn->instanceMethod && isset($state->instanceMethodNames[$fn->name])) {
            unset($state->env[$fn->name]);
        }

        $restricted = isRestrictedDeclaration($fn);
        $type = freshType($state);
        if (! $restricted) {
            foreach (\array_reverse($fn->params) as $_) {
                $type = new TArrow(freshType($state), $type);
            }
        } else {
            foreach (\array_keys(quantifiedVars($state, $type)) as $var) {
                $state->restrictedVars[$var] = true;
            }
        }

        $state->env[$fn->name] = scheme($type, [], []);

        $group[] = new RecursiveMember($fn, $fn->name, $type, DeclarationAllocation::open(), $restricted);
    }

    return $group;
}

/**
 * Infer one member's body, once, against the monotype the group published.
 *
 * No dictionaries are in scope yet: the body reports what it wants as pending
 * constraints and the group solves them.
 */
function inferRecursiveMemberBody(TypeCheckState $state, RecursiveMember $member): void
{
    $fn = $member->fn;
    $state->declSpan = [
        'line' => $fn->line,
        'col' => $fn->col,
        'endCol' => $fn->endCol,
    ];

    $expected = $member->monotype;
    $paramTypes = [];
    foreach ($fn->params as $param) {
        if (! $expected instanceof TArrow) {
            throw typeFail($state, "function `{$fn->name}` has too many parameters", $fn);
        }
        $paramTypes[] = $expected->from;
        $expected = $expected->to;
    }

    $env = $state->env;
    assertParamListLinear($state, $fn->params);
    $state->pushBinderScope();
    foreach ($fn->params as $i => $param) {
        [$_, $env] = bindPattern($state, $param, $paramTypes[$i], $env, generalize: false);
    }

    $bodyEnv = $env;
    $bodyEnv[$fn->name] = scheme($member->monotype, [], []);

    $state->allocation = $member->allocation;
    $savedMethods = [
        $state->constraintMethods,
        $state->constraintMethodAmbiguities,
        $state->constraintMethodCandidates,
    ];
    $state->constraintMethods = [];
    $state->constraintMethodAmbiguities = [];
    $state->constraintMethodCandidates = [];
    $bodyAmbient = installAmbientConstraints($state, []);

    try {
        installConstraintMethods($state, [], $bodyEnv);
        $bodyType = inferExpr($state, $fn->body, $bodyEnv);
        assertPendingDictsConsistentInExpr($state, $fn->body);
        unify($state, $bodyType, $expected, $fn->body);
    } finally {
        restoreAmbientConstraints($state, $bodyAmbient);
        [
            $state->constraintMethods,
            $state->constraintMethodAmbiguities,
            $state->constraintMethodCandidates,
        ] = $savedMethods;
        $state->popBinderScope();
    }
}

/**
 * Decide each member's dictionaries from what the group's bodies reported.
 *
 * A member needs its own obligations and the ones of the members it calls, so a
 * round marks every intra-group reference with its callee's current constraints
 * and re-reads the reports. That is a monotone fixpoint over a finite set of
 * obligations. Annotated members are absent: their signature is their own.
 *
 * @param list<RecursiveMember> $group
 * @return array<string, list<Ast\PendingConstraint>> member name => its obligations
 */
function solveRecursiveGroupConstraints(TypeCheckState $state, array $group): array
{
    $byName = [];
    $inferred = [];
    foreach ($group as $member) {
        if ($member->restricted) {
            continue;
        }

        $byName[$member->name] = [];
        $inferred[] = $member;
    }

    if ($inferred === []) {
        return $byName;
    }

    do {
        $changed = false;
        foreach ($inferred as $member) {
            markRecursiveGroupEvidence(
                $state,
                $member->fn->body,
                static fn (Ast\Variable $use): ?array => $use->binderId === null
                    ? ($byName[$use->name] ?? null)
                    : null,
            );

            $found = inferredFunctionConstraints($state, $member->fn->body, $member->monotype);
            if ($found !== []) {
                $found = \array_values(\array_filter(
                    $found,
                    static fn (Ast\PendingConstraint $constraint): bool => ! constraintOverRestrictedVar($state, $constraint),
                ));
            }

            if ($member->allocation->adoptPending($state, $found) !== []) {
                $changed = true;
            }

            $byName[$member->name] = $member->allocation->userObligations();
        }
    } while ($changed);

    return $byName;
}

/**
 * Mark a member's references to its group with the dictionaries the callee takes.
 *
 * The calls were inferred while the callees were monotypes, so they carry no
 * evidence yet; the pass that runs after settlement resolves them like any other
 * call site.
 *
 * Which member a reference means is the caller's question, answered by a predicate: a local group
 * asks the use's binder identity, and a module-level group asks the spelling only of a use that names
 * no local binder (a `let` inside a declaration may shadow a member's name). Marking a same-spelling
 * neighbour would apply the group's dictionaries to a binding that takes none.
 *
 * @param callable(Ast\Variable): (?list<Ast\PendingConstraint>) $constraintsFor
 */
function markRecursiveGroupEvidence(
    TypeCheckState $state,
    Ast\AstNode $expr,
    callable $constraintsFor,
): void
{
    walkAstValues($expr, static function (Ast\AstNode $node) use ($state, $constraintsFor): void {
        if (! $node instanceof Ast\Variable) {
            return;
        }

        $constraints = $constraintsFor($node);
        if ($constraints !== null) {
            setPendingObligations($state, $node, $constraints, pendingDictsOf($state, $node));
        }
    });
}

/**
 * Lift one member with the dictionaries the group decided and publish it.
 *
 * The lift is the declaration's ordinary one: prepend the evidence parameters,
 * install them as the ambient dictionaries, settle the body through them.
 *
 * @param list<Ast\PendingConstraint> $userConstraints
 */
function finishRecursiveMember(TypeCheckState $state, RecursiveMember $member, array $userConstraints): void
{
    $fn = $member->fn;
    $state->declSpan = [
        'line' => $fn->line,
        'col' => $fn->col,
        'endCol' => $fn->endCol,
    ];

    if ($member->restricted) {
        $openVars = openRestrictedVarsInExpr($state, $fn->body);
        $ownsOpenVars = quantifiedVars($state, $member->monotype) !== [];
        if ($ownsOpenVars || $openVars !== []) {
            deferDeclaration(
                $state,
                $fn,
                $member->monotype,
                true,
                false,
                new SolvedGroup([], []),
                $ownsOpenVars,
                $openVars,
            );

            return;
        }

        settleCheckedBody($state, $fn, $member->monotype, true, false, new SolvedGroup([], []));

        return;
    }

    $allocation = $member->allocation;
    $solved = new SolvedGroup($userConstraints, $allocation->obligations());
    $fnType = $member->monotype;
    foreach (\array_reverse($solved->dicts) as $constraint) {
        $fnType = new TArrow(new TCon('__Dict_' . $constraint->class), $fnType);
    }

    prependEvidenceParams($fn, $solved->dicts);

    $openRestricted = openRestrictedVarsInExpr($state, $fn->body);
    if ($openRestricted !== []) {
        deferDeclaration($state, $fn, $fnType, false, false, $solved, false, $openRestricted);

        return;
    }

    $ambient = installAmbientConstraints($state, $solved->dicts);

    try {
        settleCheckedBody($state, $fn, $fnType, false, false, $solved);
    } finally {
        restoreAmbientConstraints($state, $ambient);
    }

    $wrapper = trivialIntrinsicWrapper($fn);
    resolveInstanceIntrinsicMethods($state, $fn->body);
    $fn->body = rewriteIntrinsicApplies($state, $fn->body);
    if ($wrapper !== null) {
        $fn->intrinsicWrapper = $wrapper;
        $state->intrinsicWrappers[$fn->name] = $wrapper;
    }
}
