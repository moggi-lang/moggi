<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Syntax\Ast;

use function Moggi\IR\freeVarsExpr;
use function Moggi\IR\patternParams;
use function Moggi\IR\remainingArity;
use function Moggi\Patterns\Walk\patternBoundNames;
use function Moggi\Semantics\Types\bindingGroupSccs;
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
 * ordered context a constrained instance needs. The plan holds no part of the
 * tree — the site it was decided for is the term that carries it
 * ({@see CheckedEvidenceRef}, {@see CheckedEvidenceMethod}), keyed by that
 * term's own id — so a plan can be read, printed and cached without the AST.
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

        return new EvidencePlan($origin, EvidencePlanKind::Parameter, $site->class, parameter: $site->evidence);
    }

    if ($site instanceof Ast\EvidenceRef) {
        $builder = evidenceRefOperand($site->class, $site->head);

        return new EvidencePlan(
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
 * The checked terms under one elaboration root, keyed by their own identity.
 *
 * Walk order is the record's order: ids are allocated in it, so the same source
 * publishes the same ids. Eleven families are published here — evidence (a method
 * reference or an instance reference), a foreign call's resolved host target, the
 * primop a position names ({@see intrinsicNameFor()}), a lambda's slots and
 * captures, the arity an application still wants, a variable's referent
 * ({@see CheckedVariable}), a pattern's shape and the binders it introduces
 * ({@see CheckedPattern}), a literal's value ({@see CheckedLiteral}), a record's
 * constructor and fields ({@see CheckedRecord}),
 * the three that own *structure*: a `case`'s arms with their guards
 * flattened ({@see CheckedCase}), a `let`/`where` group's members in binding
 * order ({@see CheckedLetGroup}) and a bare guard chain ({@see CheckedGuards}). A variable is still plannable *as context* too
 * ({@see evidencePlansFor()}), because that is how an instance receives a
 * dictionary from its scope.
 *
 * One term per site: a site that is also some instance's context is made once,
 * not twice. The id is written back onto the node it elaborates
 * (`$node->checkedTerm`), which is the one link lowering follows; the node's own
 * evidence fields are never read again.
 *
 * A lambda's capture set is completed in a second pass over the sites this walk
 * reserved ids for, because answering it needs the evidence terms *inside* the
 * body — and pre-order visits the lambda first. The ids stay the walk's: only the
 * fill happens later, so the record is the same for the same source.
 *
 * @return array<int, CheckedTerm>
 */
function checkedTermsIn(Ast\AstNode $root): array
{
    $terms = [];
    $seen = [];
    $next = 0;
    /** @var list<array{id: TermId, node: Ast\Lambda}> $lambdas */
    $lambdas = [];

    $publishPattern = static function (Ast\AstNode $pattern) use (&$terms, &$seen, &$next, &$publishPattern): int {
        $key = \spl_object_id($pattern);
        if (isset($seen[$key])) {
            return $pattern->checkedTerm ?? throw new \LogicException(
                'a pattern published a second time lost the id of its first review',
            );
        }

        $children = [];
        $fields = [];
        switch (true) {
            case $pattern instanceof Ast\PatCons:
                $children = [$publishPattern($pattern->head), $publishPattern($pattern->tail)];
                break;
            case $pattern instanceof Ast\PatCon:
                foreach ($pattern->args as $arg) {
                    $children[] = $publishPattern($arg);
                }
                break;
            case $pattern instanceof Ast\PatTuple:
                foreach ($pattern->elements as $element) {
                    $children[] = $publishPattern($element);
                }
                break;
            case $pattern instanceof Ast\PatRecord:
                foreach ($pattern->fields as $field) {
                    $fields[] = ['name' => $field->name, 'id' => $publishPattern($field->pattern)];
                }
                break;
        }

        $id = new TermId(++$next);
        $pattern->checkedTerm = $id->value;
        $seen[$key] = true;
        $terms[$id->value] = new CheckedPattern(
            $id,
            CheckedFunction::termOrigin($pattern),
            patternShape($pattern),
            $pattern instanceof Ast\PatVar ? $pattern->binderId : null,
            $pattern instanceof Ast\PatVar || $pattern instanceof Ast\PatCon || $pattern instanceof Ast\PatRecord
                ? $pattern->name
                : null,
            $pattern instanceof Ast\PatLit || $pattern instanceof Ast\PatChar ? $pattern->value : null,
            $children,
            $fields,
            \array_keys(patternBoundNames($pattern)),
        );

        return $id->value;
    };

    walkElaboration($root, static function (Ast\AstNode $node) use (&$terms, &$seen, &$next, &$lambdas, &$publishPattern): void {
        $intrinsic = intrinsicNameFor($node);
        if ($intrinsic !== null) {
            $key = \spl_object_id($node);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $id = new TermId(++$next);
            $node->checkedTerm = $id->value;
            $terms[$id->value] = new CheckedIntrinsicCall($id, CheckedFunction::termOrigin($node), $intrinsic);

            return;
        }

        if ($node instanceof Ast\Variable) {
            $key = \spl_object_id($node);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $id = new TermId(++$next);
            $node->checkedTerm = $id->value;
            $terms[$id->value] = new CheckedVariable($id, CheckedFunction::termOrigin($node), $node->binderId);

            return;
        }

        if (isLiteralNode($node)) {
            $key = \spl_object_id($node);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $id = new TermId(++$next);
            $node->checkedTerm = $id->value;
            $terms[$id->value] = new CheckedLiteral(
                $id,
                CheckedFunction::termOrigin($node),
                literalShape($node),
                $node->value,
                $node instanceof Ast\IntegerLit ? $node->digits : null,
            );

            return;
        }

        if ($node instanceof Ast\GuardsExpr) {
            $key = \spl_object_id($node);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $id = new TermId(++$next);
            $node->checkedTerm = $id->value;
            $terms[$id->value] = new CheckedGuards($id, CheckedFunction::termOrigin($node), $node->clauses);

            return;
        }

        if ($node instanceof Ast\RecordCon || $node instanceof Ast\RecordUpdate) {
            $key = \spl_object_id($node);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $fields = [];
            foreach ($node->fields as $field) {
                $fields[] = new CheckedRecordField($field->name, $field->expr);
            }

            $id = new TermId(++$next);
            $node->checkedTerm = $id->value;
            $constructor = $node instanceof Ast\RecordCon
                ? $node->name
                : ($node->constructor ?? throw new \LogicException(
                    'a record update at ' . CheckedFunction::termOrigin($node)
                    . ' reached publication without a constructor the check resolved',
                ));
            $terms[$id->value] = new CheckedRecord(
                $id,
                CheckedFunction::termOrigin($node),
                $constructor,
                $fields,
                $node instanceof Ast\RecordUpdate ? $node->object : null,
                $node instanceof Ast\RecordUpdate ? $node->fieldOnNewtype : false,
            );

            return;
        }

        if (isPatternNode($node)) {
            if (isset($seen[\spl_object_id($node)])) {
                return;
            }

            $publishPattern($node);

            return;
        }

        if ($node instanceof Ast\Lambda) {
            $key = \spl_object_id($node);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $lambdas[] = ['id' => new TermId(++$next), 'node' => $node];

            return;
        }

        if ($node instanceof Ast\Apply) {
            $key = \spl_object_id($node);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $id = new TermId(++$next);
            $node->checkedTerm = $id->value;
            $terms[$id->value] = new CheckedApplication(
                $id,
                CheckedFunction::termOrigin($node),
                remainingArity($node->inferredInternalType),
            );

            return;
        }

        if ($node instanceof Ast\CaseExpr || $node instanceof Ast\IoCase) {
            $key = \spl_object_id($node);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $arms = [];
            foreach ($node->alts as $alt) {
                $clauses = [];
                if ($alt->body instanceof Ast\GuardsExpr) {
                    foreach ($alt->body->clauses as $clause) {
                        $clauses[] = ['guard' => $clause->guard, 'body' => $clause->body];
                    }
                } else {
                    $clauses[] = ['guard' => null, 'body' => $alt->body];
                }
                $arms[] = new CheckedCaseArm($alt->pattern, $clauses);
            }

            $id = new TermId(++$next);
            $node->checkedTerm = $id->value;
            $terms[$id->value] = new CheckedCase(
                $id,
                CheckedFunction::termOrigin($node),
                $arms,
                $node->exhaustive,
            );

            return;
        }

        if ($node instanceof Ast\Let || $node instanceof Ast\Where || $node instanceof Ast\IoLet) {
            $key = \spl_object_id($node);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $components = [];
            foreach (bindingGroupSccs($node->bindings) as $component) {
                $group = [];
                foreach ($component['indices'] as $index) {
                    $group[] = $node->bindings[$index];
                }
                $components[] = new CheckedBindingGroup($group, $component['recursive']);
            }

            $body = match (true) {
                $node instanceof Ast\Let => $node->body,
                $node instanceof Ast\Where => $node->expr,
                default => null,
            };

            $id = new TermId(++$next);
            $node->checkedTerm = $id->value;
            $terms[$id->value] = new CheckedLetGroup(
                $id,
                CheckedFunction::termOrigin($node),
                $components,
                $body,
            );

            return;
        }

        $plan = evidencePlanFor($node);
        if ($plan === null) {
            if (! $node instanceof Ast\ForeignCall) {
                return;
            }

            $key = \spl_object_id($node);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $id = new TermId(++$next);
            $node->checkedTerm = $id->value;
            $terms[$id->value] = new CheckedForeignCall(
                $id,
                CheckedFunction::termOrigin($node),
                $node->name,
                $node->backend,
                $node->kind,
                $node->path,
                $node->dispatch,
                $node->classPath,
                $node->member,
                $node->ioWrap,
                $node->phpValueBox,
                $node->handleBox,
                $node->handleUnboxArgs,
                $node->nativeSig,
            );

            return;
        }

        $key = \spl_object_id($node);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;

        $id = new TermId(++$next);
        $node->checkedTerm = $id->value;
        $origin = $plan->origin;

        $terms[$id->value] = $node instanceof Ast\EvidenceMethod
            ? new CheckedEvidenceMethod(
                $id,
                $origin,
                $node->class,
                $node->method,
                $node->methodArity,
                $node->methodNullary,
                $plan,
            )
            : new CheckedEvidenceRef($id, $origin, $node->class, $plan);
    });

    foreach ($lambdas as $lambda) {
        $node = $lambda['node'];
        $parameterNames = patternParams($node->params);
        $parameterBinders = [];
        foreach ($node->params as $param) {
            $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;
            $parameterBinders[] = patternBinder($pattern, $terms);
        }
        $captures = freeVarsExpr(
            $node->body,
            \array_fill_keys($parameterNames, true),
            static fn (Ast\EvidenceMethod $method): ?string
                => ($terms[$method->checkedTerm ?? 0] ?? null) instanceof CheckedEvidenceMethod
                    ? $terms[$method->checkedTerm]->plan->parameter
                    : null,
            $terms,
        );

        $node->checkedTerm = $lambda['id']->value;
        $terms[$lambda['id']->value] = new CheckedLambda(
            $lambda['id'],
            CheckedFunction::termOrigin($node),
            $parameterNames,
            $parameterBinders,
            \array_values(\array_unique($captures)),
        );
    }

    return $terms;
}

/**
 * The binder a pattern site allocates, read from its published term.
 *
 * The question is asked twice — once when a lambda publishes its slots, once when lowering opens a
 * frame for a `let` binding, a `case` arm or a function's parameter — and it is asked of the term
 * both times, so the node's own field is never a second answer. A pattern that binds nothing (a
 * wildcard, a literal) has no term and answers null.
 *
 * @param array<int, CheckedTerm> $terms
 */
function patternBinder(Ast\AstNode $pattern, array $terms): ?int
{
    if (! $pattern instanceof Ast\PatVar) {
        return null;
    }

    $term = $pattern->checkedTerm === null ? null : ($terms[$pattern->checkedTerm] ?? null);

    return $term instanceof CheckedPattern ? $term->binder : null;
}

/**
 * A dictionary an instance receives from its scope, planned as the parameter it
 * already is.
 */
function contextPlanFor(Ast\AstNode $entry, Ast\AstNode $at): EvidencePlan
{
    if ($entry instanceof Ast\Variable) {
        return new EvidencePlan(
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
