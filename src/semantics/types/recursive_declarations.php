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
 * A cycle has no order in which its members can be checked one after another:
 * `isEven` cannot publish a scheme before `isOdd`'s body is typed, and vice
 * versa. The group therefore allocates a *monotype* for every member up front,
 * checks each body exactly once against those monotypes, decides the group's
 * dictionaries from what those bodies reported, and only then lifts each member
 * and publishes its scheme.
 *
 * This is the same shape a local `let`/`where` group is checked with
 * ({@see inferRecursiveBindings()}) and it has the same consequence: the
 * recursion's own evidence is in scope for every member body, and a member's
 * recursive calls are given the dictionaries of the member they call
 * ({@see markRecursiveGroupEvidence()}). What it removes is the copied-body
 * probe: no member's body is inferred twice, and nothing the check discovers is
 * read back out of a printed signature.
 *
 * The one thing the group cannot decide on its own is a restricted member
 * (`f = e`, no parameters): its variables belong to the module end, not to the
 * group. Such a member is inferred here like the rest and then deferred
 * ({@see deferDeclaration()}).
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
 * A restricted member's body is checked before the group decides its
 * dictionaries, so a call it makes carries no evidence; the call site would be
 * lowered as a partial application of a dictionary-taking function, which is a
 * program that type-checks and then fails at run time. Nothing here can repair
 * it after the fact -- the instantiation the call site would have to hand
 * dictionaries over was discarded with the monotype -- so the shape is refused
 * by name instead. A signature on the restricted member moves it out of this
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
 * The monotype is the member's spine — one fresh variable per parameter and one
 * for the result — with no dictionary arrows: what the group will be lifted with
 * is not known until its bodies are. A restricted member gets a single variable,
 * because its body is not a function of anything.
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
 * The body is typed with the group's monotypes in scope and with no dictionaries
 * of its own yet, exactly like a signature-less declaration whose obligations
 * are decided at its checkpoint. Nothing here decides those obligations: the
 * body reports what it wants as pending constraints at its call sites, and the
 * group solves them.
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
    }
}

/**
 * Decide each member's dictionaries from what the group's bodies reported.
 *
 * A member's own body says what it needs. It also needs what the *members it
 * calls* need — a recursive call has to hand its callee the callee's
 * dictionaries — so each round marks every intra-group reference with the
 * constraints its callee currently carries and re-reads the bodies' reports.
 * That is a monotone fixpoint over a finite set of obligations, so it settles
 * after at most one round per obligation; in the common case, where every member
 * of a cycle reaches every other, the first round already gives the group one
 * vocabulary.
 *
 * Annotated members are not here: their signature is their own, registered
 * before any of this ran, so their call sites already carry the dictionaries
 * they hand over.
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
            markRecursiveGroupEvidence($member->fn->body, $byName);

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
 * Give a member's references to its group the dictionaries the callee takes.
 *
 * A member's body was inferred while its callees were monotypes, so its
 * recursive calls carry no evidence yet. Each reference to a group name is
 * marked with that name's obligations here, and the evidence pass that follows
 * settlement resolves them exactly as it resolves any other call site: on the
 * member's own dictionaries when the obligation is one it was lifted with.
 *
 * @param array<string, list<Ast\PendingConstraint>> $byName
 */
function markRecursiveGroupEvidence(Ast\AstNode $expr, array $byName): void
{
    if ($byName === []) {
        return;
    }

    walkAstValues($expr, static function (Ast\AstNode $node) use ($byName): void {
        if ($node instanceof Ast\Variable && isset($byName[$node->name])) {
            $node->pendingConstraints = $byName[$node->name];
        }
    });
}

/**
 * Lift one member with the dictionaries the group decided and publish it.
 *
 * The lift is the declaration's ordinary one — prepend the evidence parameters,
 * make them the ambient dictionaries, settle the body through them — so the
 * published scheme, the elaborated body and the checked-core record come from
 * the check itself rather than from a signature discovered earlier and re-parsed.
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
    $fn->body = rewriteIntrinsicApplies($fn->body);
    if ($wrapper !== null) {
        $fn->intrinsicWrapper = $wrapper;
        $state->intrinsicWrappers[$fn->name] = $wrapper;
    }
}
