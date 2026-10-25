<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\evidenceRefOperand;

/**
 * What an elaborated evidence site dispatches through.
 *
 * A `parameter` site is given its dictionary as a parameter of the enclosing
 * declaration; a `projection` site names the instance it was projected from
 * (a builder call, acquired once per block); a `reference` site is the same
 * instance dictionary used as a value. The three are kept apart because they
 * lower differently — the projection binds its builder per block, the reference
 * does not — and a plan that merged them would have to guess at lowering time.
 */
enum EvidencePlanKind
{
    case Parameter;
    case Projection;
    case Reference;
}

/**
 * The closed evidence decision for one elaborated site.
 *
 * The facts are the ones lowering needs and nothing else: which parameter
 * carries the dictionary, or which builder and instance head name it, plus the
 * ordered context a constrained instance needs. `site` is a *link* to the
 * elaboration node the decision was made for — lowering uses it to find the
 * plan and never reads the node's own evidence fields again — and `origin` is
 * that node's source origin, so the record can be read, printed and pointed at
 * without the tree.
 */
final class EvidencePlan
{
    /**
     * @param string $origin the site's source origin ({@see CheckedFunction::termOrigin()})
     * @param ?string $parameter the dictionary parameter, for a `Parameter` plan
     * @param ?string $builder the evidence builder's emitted symbol, for a projected plan
     * @param mixed $builderHead the type-head metadata that symbol was derived from
     * @param list<EvidencePlan> $context the instance's own dictionaries, in order
     */
    public function __construct(
        public readonly Ast\AstNode $site,
        public readonly string $origin,
        public readonly EvidencePlanKind $kind,
        public readonly string $class,
        public readonly ?string $parameter = null,
        public readonly ?string $builder = null,
        public readonly mixed $builderHead = null,
        public readonly array $context = [],
    ) {
    }

    /** How many dictionaries the instance this site projects from takes. */
    public function contextWidth(): int
    {
        return \count($this->context);
    }
}

/**
 * The evidence plan for one elaborated evidence site, or null when the node is
 * not one.
 *
 * The site's fields are the checker's elaboration, written when it resolved the
 * reference. This is the **only** read of them: it runs once, while the check is
 * live, and publishes values, so lowering never looks at the node again.
 * A context entry is a dictionary the instance itself needs: either an ambient
 * parameter or another projected instance, and anything else is refused — an
 * unplannable entry would otherwise be lowered as if it were one of those two.
 */
function evidencePlanFor(Ast\AstNode $site): ?EvidencePlan
{
    $origin = CheckedFunction::termOrigin($site);

    if ($site instanceof Ast\EvidenceMethod) {
        $context = evidencePlansFor($site->contextEvidence, $site);
        if ($site->evidenceInstance !== null) {
            $builder = evidenceRefOperand($site->class, $site->evidenceInstance);

            return new EvidencePlan(
                $site,
                $origin,
                EvidencePlanKind::Projection,
                $site->class,
                builder: $builder->name,
                builderHead: $builder->evidenceHead,
                context: $context,
            );
        }

        if ($site->evidence === '') {
            throw new \LogicException(
                "the elaborated method site for `{$site->class}.{$site->method}` names neither a dictionary"
                . ' parameter nor a projected instance',
            );
        }

        return new EvidencePlan($site, $origin, EvidencePlanKind::Parameter, $site->class, parameter: $site->evidence);
    }

    if ($site instanceof Ast\EvidenceRef) {
        $builder = evidenceRefOperand($site->class, $site->head);

        return new EvidencePlan(
            $site,
            $origin,
            EvidencePlanKind::Reference,
            $site->class,
            builder: $builder->name,
            builderHead: $builder->evidenceHead,
            context: evidencePlansFor($site->context, $site),
        );
    }

    return null;
}

/**
 * The plan for every evidence site under one elaboration root, in walk order.
 *
 * Walk order is the record's own order: the same list, read back, states where
 * each decision sits in the elaboration. A plain variable is not a site — only
 * a method reference and an instance reference are planned by the walk — while
 * a variable is still plannable *as context* ({@see evidencePlansFor()}),
 * because that is how an instance receives a dictionary from its scope.
 *
 * One plan per site: a site that is also some instance's context is planned
 * once, not twice, so the list's length is the number of evidence sites the
 * elaboration carries.
 *
 * @return list<EvidencePlan>
 */
function evidencePlansIn(Ast\AstNode $root): array
{
    $plans = [];
    $seen = [];

    walkElaboration($root, static function (Ast\AstNode $node) use (&$plans, &$seen): void {
        $plan = evidencePlanFor($node);
        if ($plan === null) {
            return;
        }

        $id = \spl_object_id($node);
        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $plans[] = $plan;
    });

    return $plans;
}

/**
 * A dictionary an instance receives from its scope, planned as the parameter it
 * already is.
 */
function contextPlanFor(Ast\AstNode $entry, Ast\AstNode $at): EvidencePlan
{
    if ($entry instanceof Ast\Variable) {
        return new EvidencePlan(
            $entry,
            CheckedFunction::termOrigin($entry),
            EvidencePlanKind::Parameter,
            '',
            parameter: $entry->name,
        );
    }

    return evidencePlanFor($entry) ?? throw new \LogicException(
        'the evidence context of the site at line ' . $at->line . ' carries a `' . $entry::class
        . '` the checked core cannot plan',
    );
}

/**
 * @param list<Ast\AstNode> $context
 * @return list<EvidencePlan>
 */
function evidencePlansFor(array $context, Ast\AstNode $at): array
{
    $plans = [];
    foreach ($context as $entry) {
        $plans[] = contextPlanFor($entry, $at);
    }

    return $plans;
}
