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
 * One declaration of the checked core's first category: an ordinary, annotated,
 * nonrecursive function.
 *
 * The record is the *closed* answer the rest of the compiler reads: the checked
 * scheme, the evidence its dictionary parameters carry, the residual decisions
 * that were taken while solving it (with their provenance), the type of every
 * term in its elaboration, and the source origin that names the declaration and
 * its terms for tooling. Nothing here is recomputed from the mutable source tree
 * afterwards.
 */
final class CheckedFunction
{
    /**
     * @param string $origin the declaration's tooling link: `module:line:col`
     * @param list<array{wanted: string, class: string, evidence: string, origin: string}> $evidence
     *   one entry per dictionary parameter, in positional ABI order: the wanted
     *   obligation (`wanted`, its class applied to its arguments) and the given
     *   dictionary that discharges it (`evidence`, the emitted parameter's name)
     * @param list<array{wanted: string, decision: string, provenance: string, origin: string}> $residuals
     *   obligations that were not solved by unification, with the step that settled them
     * @param list<array{origin: string, type: Type}> $terms the elaboration's term→type
     *   table, in elaboration order, each entry named by its source origin
     *   (see {@see termOrigin()})
     * @param list<EvidencePlan> $bodyEvidence the evidence decision for every
     *   evidence site in the declaration's elaboration, in walk order, each
     *   naming its site's source origin; a category declaration is lowered from
     *   these, never from the site's own evidence fields
     */
    public function __construct(
        public readonly string $name,
        public readonly string $origin,
        public readonly Scheme $scheme,
        public readonly array $evidence,
        public readonly array $residuals,
        public readonly array $terms = [],
        public readonly array $bodyEvidence = [],
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
        );
    }    /**
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
        );
    }

    /**
     * The evidence plans by the elaboration site each belongs to.
 *
 * Keyed by object id: a plan's `site` is the very node the elaborated tree
 * carries, and PHP's serializer preserves that shared identity, so the map
 * is correct in the process that checked the module and in one that read it
 * back from the cache. The plan's `origin` is the fact a reader outside the
 * process gets; this index is only how lowering finds the plan for a node it is
 * already holding. It is built once per lowered declaration, not per use.
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
 * The closed checked product for one module: every declaration of the pilot
 * category, indexed by the name the rest of the compiler uses.
 */
final class CheckedCore
{
    /** @param array<string, CheckedFunction> $functions */
    public function __construct(
        public readonly string $module,
        public readonly array $functions,
    ) {
    }

    public function function(string $name): ?CheckedFunction
    {
        return $this->functions[$name] ?? null;
    }

    /** This product with one declaration's record replaced. */
    public function withFunction(CheckedFunction $function): self
    {
        return new self($this->module, [$function->name => $function] + $this->functions);
    }
}

/**
 * Whether a declaration is in the first category: an ordinary function that
 * spells its own signature and does not call itself.
 *
 * The other categories (inferred signatures, instance and class-default
 * methods, foreign imports, recursive declarations) keep the compiler's
 * existing paths; {@see checkedCoreFor()} records only what it covers, and
 * `IR\lower` asks this predicate before it requires a record.
 */
function isCheckedCoreFunction(Ast\FunctionDecl $fn): bool
{
    return ! $fn->signatureOnly
        && ! $fn->foreign
        && ! $fn->instanceMethod
        && Ast\hasDeclaredSignature($fn)
        && ! bodyRefersToOwnName($fn);
}

/**
 * Whether the declaration's own body mentions the declaration.
 *
 * Deliberately conservative: a reference that resolves to the declaration (same
 * module, name equal) and is not bound by a local pattern is treated as
 * recursion, so an ambiguous shape stays out of the category instead of being
 * published as if it were closed.
 */
function bodyRefersToOwnName(Ast\FunctionDecl $fn): bool
{
    $found = false;

    walkElaboration($fn->body, static function (Ast\AstNode $node) use (&$found, $fn): void {
        if ($found || ! $node instanceof Ast\Variable) {
            return;
        }

        if ($node->name === $fn->name
            && $node->binderId === null
            && ($node->resolvedOrigin === null
                || $node->resolvedOrigin === $fn->name
                || \str_ends_with($node->resolvedOrigin, '::' . $fn->name))
        ) {
            $found = true;
        }
    });

    return $found;
}

/**
 * Build the module's checked core from the checker's own results.
 *
 * This is the single producer: it runs once, at the end of checking, and every
 * fact it publishes comes from the state that just checked the declaration. It
 * refuses to publish a declaration whose obligation is still open, whose type is
 * not generalized, or whose evidence vector does not match the scheme it
 * publishes — so a hole cannot enter the product and be discovered later as a
 * lowering surprise.
 */
function checkedCoreFor(TypeCheckState $state, array $items, string $module): CheckedCore
{
    $functions = [];
    foreach ($items as $item) {
        if (! $item instanceof Ast\FunctionDecl || ! isCheckedCoreFunction($item)) {
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

        $functions[$item->name] = new CheckedFunction(
            $item->name,
            $origin,
            $scheme->withPruned(prune($state, $scheme->type), $scheme->constraints, $scheme->freeTypeVars),
            $evidence,
            residualsFor($state, $item, $origin),
        );
    }

    return new CheckedCore($module, $functions);
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
        if ($declaration === null || ! isCheckedCoreFunction($declaration)) {
            throw new \LogicException(
                "the checked core records `{$core->module}::{$name}` but the elaboration has no such"
                . ' declaration of the pilot category',
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
