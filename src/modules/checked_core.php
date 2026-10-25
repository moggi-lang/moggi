<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\assertNoPendingConstraintsInExpr;
use function Moggi\Semantics\Types\prune;
use function Moggi\Semantics\Types\typeFail;
use function Moggi\Semantics\Types\typeToString;

/**
 * One checked-core record for an owned ordinary function, whether its
 * signature was declared or inferred and generalized.
 *
 * Captures the checker's closed result: scheme, dictionary evidence,
 * residual decisions and provenance, elaborated term types, and source
 * origins for tooling. Nothing is recomputed from the mutable source tree.
 */
final class CheckedFunction
{
    /**
     * @param string $origin Tooling link for the declaration (`module:line:col`).
     * @param list<array{wanted: string, class: string, evidence: string, origin: string}> $evidence
     *   Dictionary parameters in positional ABI order: the wanted obligation
     *   and the given dictionary that discharges it.
     * @param list<array{wanted: string, decision: string, provenance: string, origin: string}> $residuals
     *   Obligations not solved by unification, with their resolution step.
     * @param list<array{origin: string, type: Type}> $terms
     *   Term-to-type entries in elaboration order, keyed by source origin
     *   (see {@see termOrigin()}).
     * @param list<EvidencePlan> $bodyEvidence
     *   Evidence decisions for every site in walk order. Category declarations
     *   use these plans, never the site's own evidence fields.
     * @param array<string, string> $parameterKinds
     *   Each type parameter's kind in this declaration's signature scope.
     *   Kinds are declaration-local, so identical names may have different
     *   kinds in different declarations.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $origin,
        public readonly Scheme $scheme,
        public readonly array $evidence,
        public readonly array $residuals,
        public readonly array $terms = [],
        public readonly array $bodyEvidence = [],
        public readonly array $parameterKinds = [],
    ) {
    }

    /**
     * This declaration's record with its elaboration attached.
     *
     * The declaration's own facts are settled at the end of the check, but the
     * elaboration it will be lowered from is only final once normalization has
     * rewritten the body ({@see publishElaboration()}), so the two halves are
     * built at different moments and joined here.
     *
     * @param list<array{origin: string, type: Type}> $terms
     * @param list<EvidencePlan> $bodyEvidence
     */
    public function withElaboration(array $terms, array $bodyEvidence): self
    {
        return new self(
            $this->name,
            $this->origin,
            $this->scheme,
            $this->evidence,
            $this->residuals,
            $terms,
            $bodyEvidence,
            $this->parameterKinds,
        );
    }

    /**
     * This record with a different evidence vector — the same declaration, the
     * same elaboration, other dictionary parameters.
     *
     * @param list<array{wanted: string, class: string, evidence: string, origin: string}> $evidence
     */
    public function withEvidence(array $evidence): self
    {
        return new self(
            $this->name,
            $this->origin,
            $this->scheme,
            $evidence,
            $this->residuals,
            $this->terms,
            $this->bodyEvidence,
            $this->parameterKinds,
        );
    }

    /**
     * Evidence plans indexed by their elaboration site.
     *
     * The key is the node's object id, preserving identity across checking
     * and PHP serialization. `origin` provides the external identity; this
     * index lets lowering find the plan for a node it already holds.
     * Built once per lowered declaration, not per use.
     *
     * @return array<int, EvidencePlan>
     */
    public function evidencePlanIndex(): array
    {
        $index = [];
        foreach ($this->bodyEvidence as $plan) {
            $index[\spl_object_id($plan->site)] = $plan;
        }

        return $index;
    }

    /**
     * The dictionary parameter names in the order the emitted signature takes
     * them — the positional evidence ABI, stated once for this declaration.
     *
     * @return list<string>
     */
    public function evidenceBinders(): array
    {
        return \array_map(static fn (array $slot): string => $slot['evidence'], $this->evidence);
    }

    /**
     * The checked types of the terms at this origin, in elaboration order.
     *
     * Most origins name one term. An origin can name more than one — the
     * parser and the desugarer give several nodes the same span — so the answer
     * is a list rather than a single type: the record never invents an identity
     * to pick one of them, and never silently keeps only the last.
     *
     * @return list<Type>
     */
    public function typesAt(string $origin): array
    {
        $types = [];
        foreach ($this->terms as $term) {
            if ($term['origin'] === $origin) {
                $types[] = $term['type'];
            }
        }

        return $types;
    }

    /**
     * The origin that names a term inside one elaboration.
     *
     * It is a *link*, not an identity the checker maintains: an outside reader
     * can point at the file and line it names. A synthesized node has no span
     * (line 0) and so no origin; several nodes can share one span, which is why
     * {@see terms} is a list and {@see typesAt()} answers with a list.
     */
    public static function termOrigin(Ast\AstNode $node): string
    {
        return \sprintf('%s:%d:%d:%d', $node::class, $node->line, $node->col, $node->endCol);
    }
}

/**
 * The module's closed checked product: records for declarations with
 * settled schemes, plus the published split of ordinary declarations
 * left unowned and why.
 *
 * `IR\lower` uses this split to distinguish required records from
 * legitimate omissions. A declaration in neither map is a producer
 * defect and causes lowering to fail; downstream code must not
 * re-derive the decision.
 */
final class CheckedCore
{
    /**
     * @param array<string, CheckedFunction> $functions the declarations this check closed
     * @param array<string, string> $unowned the ordinary declarations it left to the existing
     *   producer, by name, each with the fact that kept it out (an inferred signature that did not
     *   close)
     */
    public function __construct(
        public readonly string $module,
        public readonly array $functions,
        public readonly array $unowned = [],
    ) {
    }

    public function function(string $name): ?CheckedFunction
    {
        return $this->functions[$name] ?? null;
    }

    /** This product with one declaration's record replaced. */
    public function withFunction(CheckedFunction $function): self
    {
        return new self(
            $this->module,
            [$function->name => $function] + $this->functions,
            $this->unowned,
        );
    }
}

/**
 * Whether a declaration is eligible for checked-core ownership: ordinary,
 * not a signature stub, foreign import, or instance method.
 *
 * Eligibility also requires a closed scheme. Recursive declarations qualify
 * because their group signature is settled before any body is checked.
 * {@see checkedCoreFor()} records the final decision in
 * {@see CheckedCore::$unowned}; downstream code uses that split rather
 * than re-deriving it. `IR\lower` reads the split to determine which
 * declarations require a record.
 */
function isOrdinaryDeclaration(Ast\FunctionDecl $fn): bool
{
    return ! $fn->signatureOnly
        && ! $fn->foreign
        && ! $fn->instanceMethod;
}

/**
 * Build the module's checked core from the checker's results.
 *
 * The single producer runs at the end of checking and publishes only
 * settled declarations: obligations must be closed, types generalized,
 * and evidence vectors consistent with their schemes.
 *
 * Ordinary declarations are owned when checking settles a closed scheme.
 * Recursive groups qualify too: their shared signature is settled before
 * any member body is checked.
 *
 * `$unowned` records declarations whose inferred signatures remain open,
 * such as under the monomorphism restriction; they keep their existing
 * producer. An explicitly declared signature must be closed or publication
 * fails.
 */
function checkedCoreFor(TypeCheckState $state, array $items, string $module): CheckedCore
{
    $functions = [];
    $unowned = [];

    foreach ($items as $item) {
        if (! $item instanceof Ast\FunctionDecl || ! isOrdinaryDeclaration($item)) {
            continue;
        }

        $scheme = $state->checkedSchemes[\spl_object_id($item)] ?? null;
        if (! $scheme instanceof Scheme) {
            throw typeFail(
                $state,
                "the checked core needs the scheme this check settled for `{$module}::{$item->name}`",
                $item,
            );
        }

        if ($scheme->freeTypeVars !== []) {
            if (! Ast\hasDeclaredSignature($item)) {
                $unowned[$item->name] = 'an inferred signature that did not close';

                continue;
            }

            throw typeFail(
                $state,
                "the checked boundary refuses `{$item->name}`: its type still has the uncommitted"
                . ' variable(s) ' . \implode(', ', \array_keys($scheme->freeTypeVars)),
                $item,
            );
        }

        $origin = $module . ':' . $item->line . ':' . $item->col;

        $evidence = [];
        $seen = [];
        foreach ($item->constraints as $constraint) {
            if ($constraint->evidence === '') {
                throw typeFail(
                    $state,
                    "the checked boundary refuses `{$item->name}`: the `{$constraint->class}` obligation has"
                    . ' no evidence parameter',
                    $item,
                );
            }
            if (isset($seen[$constraint->evidence])) {
                throw typeFail(
                    $state,
                    "the checked boundary refuses `{$item->name}`: evidence `{$constraint->evidence}` names"
                    . ' two dictionary parameters',
                    $item,
                );
            }
            $seen[$constraint->evidence] = true;

            $evidence[] = [
                'wanted' => constraintKey($constraint),
                'class' => $constraint->class,
                'evidence' => $constraint->evidence,
                'origin' => $origin,
            ];
        }

        if (\count($evidence) !== $scheme->runtimeConstraintCount) {
            throw typeFail(
                $state,
                "the checked boundary refuses `{$module}::{$item->name}` (line {$item->line}): its scheme takes "
                . $scheme->runtimeConstraintCount . ' dictionaries but its evidence vector has '
                . \count($evidence),
                $item,
            );
        }

        assertNoPendingConstraintsInExpr($state, $item->body, $item);

        $fixedKinds = $state->checkedParameterKinds[\spl_object_id($item)] ?? null;
        if ($fixedKinds === null) {
            throw typeFail(
                $state,
                "the checked boundary refuses `{$module}::{$item->name}`: the check recorded no kind for"
                . ' the parameters of its signature',
                $item,
            );
        }

        $parameterKinds = [];
        foreach ($scheme->bound as $parameter) {
            if (! isset($fixedKinds[$parameter])) {
                throw typeFail(
                    $state,
                    "the checked boundary refuses `{$module}::{$item->name}`: the scheme binds"
                    . " `{$parameter}` but the declaration's signature fixed no kind for it",
                    $item,
                );
            }

            $parameterKinds[$parameter] = $fixedKinds[$parameter];
        }

        $functions[$item->name] = new CheckedFunction(
            $item->name,
            $origin,
            $scheme->withPruned(prune($state, $scheme->type), $scheme->constraints, $scheme->freeTypeVars),
            $evidence,
            residualsFor($state, $item, $origin),
            parameterKinds: $parameterKinds,
        );
    }

    return new CheckedCore($module, $functions, $unowned);
}

/**
 * Attach each recorded declaration's elaboration to its record.
 *
 * A declaration's own facts are settled at the end of the check, but the body
 * lowering receives is the one left by normalization, which rewrites it (strict
 * IO sequencing, eta-expanded IO bindings). Terms and evidence plans are links
 * into that tree — the plans by site identity — so they are published here, once
 * the tree is final, rather than during checking. Every entry point that lowers
 * a checked program calls this; a record without it is a record of the wrong
 * tree, and lowering refuses an evidence site it cannot plan.
 */
function publishElaboration(Ast\Program $program): Ast\Program
{
    $core = $program->core;
    if ($core === null) {
        return $program;
    }

    $declarations = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl && $core->function($item->name) !== null) {
            $declarations[$item->name] = $item;
        }
    }

    foreach ($core->functions as $name => $record) {
        $declaration = $declarations[$name] ?? null;
        if ($declaration === null || ! isOrdinaryDeclaration($declaration)) {
            throw new \LogicException(
                "the checked core records `{$core->module}::{$name}` but the elaboration has no such"
                . ' declaration in its category',
            );
        }

        $core = $core->withFunction($record->withElaboration(
            termTypesFor($declaration),
            evidencePlansIn($declaration->body),
        ));
    }

    $program->core = $core;

    return $program;
}

/**
 * The residual decisions this declaration carries.
 *
 * Today the one producer is numeric defaulting: it commits a variable the body
 * left ambiguous, and the record names the variable, the type it was defaulted
 * to, and the step that chose it. Anything else that settles an obligation
 * without unification belongs here, not in a comment.
 *
 * @return list<array{wanted: string, decision: string, provenance: string, origin: string}>
 */
function residualsFor(TypeCheckState $state, Ast\FunctionDecl $fn, string $origin): array
{
    $residuals = [];
    foreach ($state->defaultingLog as $entry) {
        if (($entry['decl'] ?? null) !== $fn->line . ':' . $fn->col) {
            continue;
        }
        $residuals[] = [
            'wanted' => $entry['wanted'],
            'decision' => $entry['decision'],
            'provenance' => $entry['provenance'],
            'origin' => $origin,
        ];
    }

    return $residuals;
}

/** The identity of a wanted obligation: its class applied to its arguments. */
function constraintKey(Ast\PendingConstraint $constraint): string
{
    $args = \array_map(
        static fn (Type $arg): string => typeToString($arg),
        $constraint->args,
    );

    return $constraint->class . '(' . \implode(', ', $args) . ')';
}
