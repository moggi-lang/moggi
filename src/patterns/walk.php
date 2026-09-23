<?php declare(strict_types=1);

namespace Moggi\Patterns\Walk;

use Moggi\Syntax\Ast;

/** @return array<string, true> */
function patternBoundNames(Ast\AstNode $pattern): array
{
    return match ($pattern::class) {
        Ast\PatWild::class,
        Ast\PatLit::class,
        Ast\PatChar::class,
        Ast\PatNil::class => [],
        Ast\PatVar::class => [$pattern->name => true],
        Ast\PatCon::class => patternBoundNamesInList($pattern->args),
        Ast\PatRecord::class => patternBoundNamesInRecordFields($pattern->fields),
        Ast\PatTuple::class => patternBoundNamesInList($pattern->elements),
        Ast\PatCons::class => (static function () use ($pattern): array {
            $bound = patternBoundNames($pattern->head);
            foreach (patternBoundNames($pattern->tail) as $name => $_) {
                $bound[$name] = true;
            }

            return $bound;
        })(),
        default => throw new \RuntimeException('unsupported pattern in patternBoundNames: ' . $pattern::class),
    };
}

/** @return list<string> */
function patternVariableNames(Ast\AstNode $pattern): array
{
    return \array_keys(patternBoundNames($pattern));
}

/**
 * Collect pattern-bound variable names in left-to-right order (duplicates kept).
 *
 * @return list<string>
 */
function patternVariableNamesOrdered(Ast\AstNode $pattern): array
{
    return match ($pattern::class) {
        Ast\PatWild::class,
        Ast\PatLit::class,
        Ast\PatChar::class,
        Ast\PatNil::class => [],
        Ast\PatVar::class => [$pattern->name],
        Ast\PatCon::class => patternVariableNamesOrderedInList($pattern->args),
        Ast\PatRecord::class => patternVariableNamesOrderedInRecordFields($pattern->fields),
        Ast\PatTuple::class => patternVariableNamesOrderedInList($pattern->elements),
        Ast\PatCons::class => [
            ...patternVariableNamesOrdered($pattern->head),
            ...patternVariableNamesOrdered($pattern->tail),
        ],
        default => throw new \RuntimeException('unsupported pattern in patternVariableNamesOrdered: ' . $pattern::class),
    };
}

/** @param array<int, Ast\AstNode> $patterns @return list<string> */
function patternVariableNamesOrderedInList(array $patterns): array
{
    $names = [];
    foreach ($patterns as $pattern) {
        foreach (patternVariableNamesOrdered($pattern) as $name) {
            $names[] = $name;
        }
    }

    return $names;
}

/** @param list<Ast\PatField> $fields @return list<string> */
function patternVariableNamesOrderedInRecordFields(array $fields): array
{
    $names = [];
    foreach ($fields as $field) {
        foreach (patternVariableNamesOrdered($field->pattern) as $name) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * First duplicate variable binder in a single pattern, if any.
 *
 * @return array{0: string, 1: Ast\AstNode}|null name and the duplicate PatVar node
 */
function patternDuplicateBinder(Ast\AstNode $pattern): ?array
{
    $seen = [];
    foreach (patternVariableBindersOrdered($pattern) as [$name, $node]) {
        if (isset($seen[$name])) {
            return [$name, $node];
        }
        $seen[$name] = true;
    }

    return null;
}

/**
 * First duplicate variable name in a single pattern, if any.
 */
function patternDuplicateName(Ast\AstNode $pattern): ?string
{
    $dup = patternDuplicateBinder($pattern);

    return $dup === null ? null : $dup[0];
}

/**
 * @return list<array{0: string, 1: Ast\AstNode}>
 */
function patternVariableBindersOrdered(Ast\AstNode $pattern): array
{
    return match ($pattern::class) {
        Ast\PatWild::class,
        Ast\PatLit::class,
        Ast\PatChar::class,
        Ast\PatNil::class => [],
        Ast\PatVar::class => [[$pattern->name, $pattern]],
        Ast\PatCon::class => patternVariableBindersOrderedInList($pattern->args),
        Ast\PatRecord::class => patternVariableBindersOrderedInRecordFields($pattern->fields),
        Ast\PatTuple::class => patternVariableBindersOrderedInList($pattern->elements),
        Ast\PatCons::class => [
            ...patternVariableBindersOrdered($pattern->head),
            ...patternVariableBindersOrdered($pattern->tail),
        ],
        default => throw new \RuntimeException('unsupported pattern in patternVariableBindersOrdered: ' . $pattern::class),
    };
}

/** @param array<int, Ast\AstNode> $patterns @return list<array{0: string, 1: Ast\AstNode}> */
function patternVariableBindersOrderedInList(array $patterns): array
{
    $out = [];
    foreach ($patterns as $pattern) {
        foreach (patternVariableBindersOrdered($pattern) as $pair) {
            $out[] = $pair;
        }
    }

    return $out;
}

/** @param list<Ast\PatField> $fields @return list<array{0: string, 1: Ast\AstNode}> */
function patternVariableBindersOrderedInRecordFields(array $fields): array
{
    $out = [];
    foreach ($fields as $field) {
        foreach (patternVariableBindersOrdered($field->pattern) as $pair) {
            $out[] = $pair;
        }
    }

    return $out;
}

/** @param array<int, Ast\AstNode> $patterns @return array<string, true> */
function patternBoundNamesInList(array $patterns): array
{
    $bound = [];
    foreach ($patterns as $pattern) {
        foreach (patternBoundNames($pattern) as $name => $_) {
            $bound[$name] = true;
        }
    }

    return $bound;
}

/** @param list<Ast\PatField> $fields @return array<string, true> */
function patternBoundNamesInRecordFields(array $fields): array
{
    $bound = [];
    foreach ($fields as $field) {
        foreach (patternBoundNames($field->pattern) as $name => $_) {
            $bound[$name] = true;
        }
    }

    return $bound;
}

