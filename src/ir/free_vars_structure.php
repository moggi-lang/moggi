<?php declare(strict_types=1);

namespace Moggi\IR;

use Moggi\Modules\CheckedCase;
use Moggi\Modules\CheckedEvidenceRef;
use Moggi\Modules\CheckedEvidenceMethod;
use Moggi\Modules\CheckedGuards;
use Moggi\Modules\CheckedLambda;
use Moggi\Modules\CheckedLetGroup;
use Moggi\Modules\CheckedPattern;
use Moggi\Modules\CheckedRecord;
use Moggi\Modules\CheckedStructure;
use Moggi\Modules\CheckedTerms;
use Moggi\Modules\PatternShape;
use Moggi\Modules\EvidencePlan;
use Moggi\Modules\EvidencePlanKind;
use Moggi\Syntax\Ast;

/**
 * The free names an evidence decision carries.
 *
 * Evidence fields are decisions, not structure: the structural tree deliberately omits them, so
 * lowering and free-variable analysis read the plan the term publishes. A projection's builder is
 * a global symbol; only the dictionary parameters in its context can be free.
 *
 * @param array<string, true> $bound
 * @return list<string>
 */
function evidencePlanFreeVars(EvidencePlan $plan, array $bound): array
{
    if ($plan->kind === EvidencePlanKind::Parameter) {
        $name = $plan->parameter;

        return $name === null || isset($bound[$name]) ? [] : [$name];
    }

    $free = [];
    foreach ($plan->context as $inner) {
        $free = [...$free, ...evidencePlanFreeVars($inner, $bound)];
    }

    return \array_values(\array_unique($free));
}

/**
 * A child id named by one structural node, or null.
 */
function structureChildId(CheckedStructure $structure, string $field): ?int
{
    $value = $structure->payload[$field] ?? null;

    return \is_array($value) && \array_key_exists('__checked_ref', $value)
        ? $value['__checked_ref']
        : null;
}

/**
 * The child ids named by an array-valued structural field, in source order.
 *
 * @return list<int>
 */
function structureChildIds(CheckedStructure $structure, string $field): array
{
    $value = $structure->payload[$field] ?? null;
    if (! \is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $entry) {
        if (\is_array($entry) && \array_key_exists('__checked_ref', $entry)) {
            $ids[] = $entry['__checked_ref'];
        }
    }

    return $ids;
}

/**
 * Every child id anywhere in a payload.
 *
 * @return list<int>
 */
function structureAllChildIds(CheckedStructure $structure): array
{
    $ids = [];
    $collect = static function (mixed $value) use (&$collect, &$ids): void {
        if (\is_array($value)) {
            if (\array_key_exists('__checked_ref', $value)) {
                $ids[] = $value['__checked_ref'];

                return;
            }
            foreach ($value as $entry) {
                $collect($entry);
            }
        }
    };
    $collect($structure->payload);

    return $ids;
}

/** The pattern names a structure id binds. */
function structurePatternBoundSet(int $id, CheckedTerms $terms): array
{
    $term = $terms->termForStructure($id);
    if ($term instanceof CheckedPattern) {
        return $term->boundNames();
    }

    $structure = $terms->structure($id);
    if ($structure === null) {
        return [];
    }

    throw new \RuntimeException(
        'the checked structure at ' . $structure->origin . ' publishes no pattern term',
    );
}

/**
 * The slot names a lambda structure introduces.
 *
 * The lambda's own CheckedLambda term is published only after its capture set is known, so the
 * capture walk reads the parameter patterns from the structure tree rather than from a term that
 * does not exist yet.
 *
 * @return list<string>
 */
function structureLambdaSlotNames(CheckedStructure $lambda, CheckedTerms $terms): array
{
    $names = [];
    foreach (structureChildIds($lambda, 'params') as $index => $paramId) {
        $param = $terms->structure($paramId);
        if ($param === null) {
            throw new \RuntimeException('a lambda parameter structure is missing');
        }

        $patternId = structureChildId($param, 'pattern');
        $pattern = $patternId === null ? null : $terms->structure($patternId);
        $term = $patternId === null ? null : $terms->termForStructure($patternId);
        if (! $pattern instanceof CheckedStructure || ! $term instanceof CheckedPattern) {
            throw new \RuntimeException('a lambda parameter publishes no pattern term');
        }

        $name = match ($term->shape) {
            PatternShape::Var => $term->name,
            PatternShape::Wild => '__wild' . $index,
            default => throw new \RuntimeException('lambda parameters must be variables for now'),
        };
        if ($name === null) {
            throw new \RuntimeException('a variable lambda parameter publishes no name');
        }
        $names[] = $name;
    }

    return $names;
}

/**
 * The names an expression is free in, walked over the checked structure tree.
 *
 * This is the published-tree counterpart of {@see freeVarsExpr()}: every child is an id, every
 * binder is the term the checker published, and the source AST is never consulted. The fallback
 * AST walk remains only for a tree that has no record (the checker's pre-publication copy).
 *
 * @param array<string, true> $bound
 * @return list<string>
 */
function freeVarsStructure(
    int $id,
    array $bound,
    callable $evidenceParameter,
    CheckedTerms $terms,
): array {
    $structure = $terms->structure($id);
    if ($structure === null) {
        throw new \RuntimeException("checked structure #{$id} is missing");
    }

    $walk = static fn (int $child, array $names): array
        => freeVarsStructure($child, $names, $evidenceParameter, $terms);

    $term = $terms->termForStructure($id);
    if ($term instanceof CheckedCase) {
        $free = freeVarsStructure($term->scrutinee, $bound, $evidenceParameter, $terms);
        foreach ($term->arms as $arm) {
            $armBound = [...$bound, ...structurePatternBoundSet($arm->pattern, $terms)];
            foreach ($arm->clauses as $clause) {
                if ($clause['guard'] !== null) {
                    $free = [...$free, ...freeVarsStructure($clause['guard'], $armBound, $evidenceParameter, $terms)];
                }
                $free = [...$free, ...freeVarsStructure($clause['body'], $armBound, $evidenceParameter, $terms)];
            }
        }

        return \array_values(\array_unique($free));
    }

    if ($term instanceof CheckedLetGroup) {
        $groupBound = $bound;
        $bindings = [];
        foreach ($term->members() as $bindingId) {
            $binding = $terms->structure($bindingId);
            if ($binding === null) {
                throw new \RuntimeException('a binding group names a missing binding structure');
            }
            $bindings[] = $binding;
            $patternId = structureChildId($binding, 'pattern');
            if ($patternId !== null) {
                $groupBound = [...$groupBound, ...structurePatternBoundSet($patternId, $terms)];
            }
        }

        $free = [];
        foreach ($bindings as $binding) {
            $valueId = structureChildId($binding, 'value');
            if ($valueId !== null) {
                $free = [...$free, ...freeVarsStructure($valueId, $groupBound, $evidenceParameter, $terms)];
            }
        }
        if ($term->body !== null) {
            $free = [...$free, ...freeVarsStructure($term->body, $groupBound, $evidenceParameter, $terms)];
        }

        return \array_values(\array_unique($free));
    }

    return match ($structure->kind) {
        Ast\Variable::class => isset($bound[$structure->payload['name']])
            ? []
            : [$structure->payload['name']],
        Ast\IntegerLit::class,
        Ast\DoubleLit::class,
        Ast\StringLit::class,
        Ast\CharLit::class,
        Ast\ConstructorRef::class,
        Ast\OperatorRef::class,
        Ast\QualifiedRef::class,
        Ast\ExprHole::class => [],
        Ast\TypeAsc::class => $walk(structureChildId($structure, 'expr') ?? 0, $bound),
        Ast\Apply::class => [
            ...$walk(structureChildId($structure, 'function') ?? 0, $bound),
            ...$walk(structureChildId($structure, 'argument') ?? 0, $bound),
        ],
        Ast\Infix::class => [
            ...$walk(structureChildId($structure, 'left') ?? 0, $bound),
            ...$walk(structureChildId($structure, 'right') ?? 0, $bound),
        ],
        Ast\Tuple::class,
        Ast\ListLit::class => freeVarsStructureList(
            structureChildIds($structure, 'elements'),
            $bound,
            $evidenceParameter,
            $terms,
        ),
        Ast\Lambda::class => (static function () use ($structure, $bound, $evidenceParameter, $terms): array {
            $names = structureLambdaSlotNames($structure, $terms);
            $body = structureChildId($structure, 'body');

            return $body === null
                ? []
                : freeVarsStructure($body, [...$bound, ...\array_fill_keys($names, true)], $evidenceParameter, $terms);
        })(),
        Ast\DoExpr::class => (static function () use ($structure, $bound, $evidenceParameter, $terms): array {
            $desugared = structureChildId($structure, 'desugared');

            return $desugared === null
                ? throw new \RuntimeException('do expression reached free-variable analysis before desugaring')
                : freeVarsStructure($desugared, $bound, $evidenceParameter, $terms);
        })(),
        Ast\Let::class,
        Ast\Where::class => (static function () use ($structure, $id, $bound, $evidenceParameter, $terms): array {
            $term = $terms->termForStructure($id);
            if (! $term instanceof CheckedLetGroup) {
                throw new \RuntimeException('a binding group publishes no checked group term');
            }

            $groupBound = $bound;
            $bindings = [];
            foreach ($term->members() as $bindingId) {
                $binding = $terms->structure($bindingId);
                if ($binding === null) {
                    throw new \RuntimeException('a binding group names a missing binding structure');
                }
                $bindings[] = $binding;
                $patternId = structureChildId($binding, 'pattern');
                if ($patternId !== null) {
                    $groupBound = [...$groupBound, ...structurePatternBoundSet($patternId, $terms)];
                }
            }

            $free = [];
            foreach ($bindings as $binding) {
                $valueId = structureChildId($binding, 'value');
                if ($valueId !== null) {
                    $free = [...$free, ...freeVarsStructure($valueId, $groupBound, $evidenceParameter, $terms)];
                }
            }
            if ($term->body !== null) {
                $free = [...$free, ...freeVarsStructure($term->body, $groupBound, $evidenceParameter, $terms)];
            }

            return \array_values(\array_unique($free));
        })(),
        Ast\CaseExpr::class => (static function () use ($structure, $id, $bound, $evidenceParameter, $terms): array {
            $term = $terms->termForStructure($id);
            if (! $term instanceof CheckedCase) {
                throw new \RuntimeException('a case publishes no checked case term');
            }

            $free = freeVarsStructure($term->scrutinee, $bound, $evidenceParameter, $terms);
            foreach ($term->arms as $arm) {
                $armBound = [...$bound, ...structurePatternBoundSet($arm->pattern, $terms)];
                foreach ($arm->clauses as $clause) {
                    if ($clause['guard'] !== null) {
                        $free = [...$free, ...freeVarsStructure($clause['guard'], $armBound, $evidenceParameter, $terms)];
                    }
                    $free = [...$free, ...freeVarsStructure($clause['body'], $armBound, $evidenceParameter, $terms)];
                }
            }

            return \array_values(\array_unique($free));
        })(),
        Ast\GuardsExpr::class => (static function () use ($id, $bound, $evidenceParameter, $terms): array {
            $term = $terms->termForStructure($id);
            if (! $term instanceof CheckedGuards) {
                throw new \RuntimeException('a guard chain publishes no checked guard term');
            }

            $free = [];
            foreach ($term->clauses as $clause) {
                $free = [
                    ...$free,
                    ...freeVarsStructure($clause['guard'], $bound, $evidenceParameter, $terms),
                    ...freeVarsStructure($clause['body'], $bound, $evidenceParameter, $terms),
                ];
            }

            return \array_values(\array_unique($free));
        })(),
        Ast\RecordCon::class,
        Ast\RecordUpdate::class => (static function () use ($structure, $id, $bound, $evidenceParameter, $terms): array {
            $term = $terms->termForStructure($id);
            if (! $term instanceof CheckedRecord) {
                throw new \RuntimeException('a record publishes no checked record term');
            }

            $free = [];
            if ($term->receiver !== null) {
                $free = [...$free, ...freeVarsStructure($term->receiver, $bound, $evidenceParameter, $terms)];
            }
            foreach ($term->fields as $field) {
                $free = [...$free, ...freeVarsStructure($field->value, $bound, $evidenceParameter, $terms)];
            }

            return \array_values(\array_unique($free));
        })(),
        Ast\FieldAccess::class => $walk(structureChildId($structure, 'object') ?? 0, $bound),
        Ast\IntrinsicCall::class => freeVarsStructureList(
            structureChildIds($structure, 'args'),
            $bound,
            $evidenceParameter,
            $terms,
        ),
        Ast\EvidenceRef::class => (static function () use ($structure, $id, $bound, $evidenceParameter, $terms): array {
            $term = $terms->termForStructure($id);
            if ($term instanceof CheckedEvidenceRef) {
                return evidencePlanFreeVars($term->plan, $bound);
            }

            return freeVarsStructureList(
                structureChildIds($structure, 'context'),
                $bound,
                $evidenceParameter,
                $terms,
            );
        })(),
        Ast\EvidenceMethod::class => (static function () use ($structure, $id, $bound, $evidenceParameter, $terms): array {
            $term = $terms->termForStructure($id);
            if ($term instanceof CheckedEvidenceMethod) {
                return evidencePlanFreeVars($term->plan, $bound);
            }

            $parameter = null;

            return [
                ...($parameter === null || isset($bound[$parameter]) ? [] : [$parameter]),
                ...freeVarsStructureList(
                    structureChildIds($structure, 'contextEvidence'),
                    $bound,
                    $evidenceParameter,
                    $terms,
                ),
            ];
        })(),
        default => freeVarsStructureList(
            structureAllChildIds($structure),
            $bound,
            $evidenceParameter,
            $terms,
        ),
    };
}

/**
 * @param list<int> $ids
 * @param array<string, true> $bound
 * @return list<string>
 */
function freeVarsStructureList(
    array $ids,
    array $bound,
    callable $evidenceParameter,
    CheckedTerms $terms,
): array {
    $free = [];
    foreach ($ids as $id) {
        $free = [...$free, ...freeVarsStructure($id, $bound, $evidenceParameter, $terms)];
    }

    return \array_values(\array_unique($free));
}
