<?php declare(strict_types=1);

namespace Moggi\Docs;

/**
 * Pure row-based Moogle search (CLI). Browser parity: moogle.client.js
 *
 * @param list<array<string, mixed>> $entities
 * @return list<array{entity: array<string, mixed>, score: float}>
 */
function searchInRows(array $entities, string $query, int $limit = 20): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    [$query, $filters] = parseSearchFilters($query);

    if ($query === '' && $filters !== []) {
        return searchByFilters($entities, $filters, $limit);
    }

    if (str_contains($query, '::')) {
        [$namePart, $typePart] = array_map('trim', explode('::', $query, 2));

        return applySearchFilters(
            searchByNameAndType($entities, $namePart, $typePart, $limit),
            $filters,
        );
    }

    if (looksLikeTypeQuery($query)) {
        return applySearchFilters(searchByType($entities, $query, $limit), $filters);
    }

    return applySearchFilters(searchByName($entities, $query, $limit), $filters);
}

/** @return array{0: string, 1: array<string, string>} */
function parseSearchFilters(string $query): array
{
    $filters = [];
    $patterns = [
        '/\bis:module\b/i' => ['kind' => 'module'],
        '/\bis:class\b/i' => ['kind' => 'class'],
        '/\bis:type\b/i' => ['kind' => 'type'],
        '/\bis:value\b/i' => ['kind' => 'value'],
        '/\bmodule:([A-Za-z][A-Za-z0-9_.]*)\b/' => null,
    ];

    foreach ($patterns as $pattern => $filter) {
        if ($filter !== null) {
            if (preg_match($pattern, $query) === 1) {
                $filters = [...$filters, ...$filter];
                $query = trim(preg_replace($pattern, '', $query) ?? $query);
            }
            continue;
        }

        if (preg_match($pattern, $query, $m) === 1) {
            $filters['module'] = $m[1];
            $query = trim(preg_replace($pattern, '', $query) ?? $query);
        }
    }

    return [$query, $filters];
}

function entityMatchesKindFilter(array $entity, string $kind): bool
{
    if ($kind === 'value') {
        return in_array($entity['kind'] ?? '', ['value', 'foreign', 'primop'], true);
    }

    return ($entity['kind'] ?? '') === $kind;
}

/**
 * @param list<array<string, mixed>> $entities
 * @param array<string, string> $filters
 * @return list<array{entity: array<string, mixed>, score: float}>
 */
function searchByFilters(array $entities, array $filters, int $limit): array
{
    $hits = [];
    foreach ($entities as $entity) {
        if (isset($filters['kind']) && !entityMatchesKindFilter($entity, $filters['kind'])) {
            continue;
        }
        if (isset($filters['module']) && !str_starts_with($entity['module'] ?? '', $filters['module'])) {
            continue;
        }
        $hits[] = ['entity' => $entity, 'score' => 50.0];
    }

    usort($hits, static function (array $a, array $b): int {
        $mod = ($a['entity']['module'] ?? '') <=> ($b['entity']['module'] ?? '');
        if ($mod !== 0) {
            return $mod;
        }

        return ($a['entity']['name'] ?? '') <=> ($b['entity']['name'] ?? '');
    });

    return array_slice($hits, 0, $limit);
}

/**
 * @param list<array{entity: array<string, mixed>, score: float}> $hits
 * @param array<string, string> $filters
 * @return list<array{entity: array<string, mixed>, score: float}>
 */
function applySearchFilters(array $hits, array $filters): array
{
    if ($filters === []) {
        return $hits;
    }

    $filtered = [];
    foreach ($hits as $hit) {
        $entity = $hit['entity'];
        if (isset($filters['kind']) && !entityMatchesKindFilter($entity, $filters['kind'])) {
            continue;
        }
        if (isset($filters['module']) && !str_starts_with($entity['module'] ?? '', $filters['module'])) {
            continue;
        }
        $filtered[] = $hit;
    }

    return $filtered;
}

function looksLikeTypeQuery(string $query): bool
{
    return str_contains($query, '->')
        || str_contains($query, '=>')
        || preg_match('/\b[a-z][a-zA-Z0-9_\']*\s*(->|=>)/', $query) === 1;
}

/** @return list<string> */
function searchNameVariants(string $query): array
{
    $variants = [$query];
    if (str_starts_with($query, '(') && str_ends_with($query, ')')) {
        $variants[] = substr($query, 1, -1);
    } elseif ($query !== '' && !str_contains($query, ' ')) {
        $variants[] = '(' . $query . ')';
    }

    return array_values(array_unique($variants));
}

/**
 * @param list<array<string, mixed>> $entities
 * @return list<array{entity: array<string, mixed>, score: float}>
 */
function searchByName(array $entities, string $query, int $limit): array
{
    $hits = [];
    $variants = searchNameVariants($query);

    foreach ($entities as $entity) {
        if (($entity['kind'] ?? '') === 'module') {
            continue;
        }

        $score = 0.0;
        foreach ($variants as $variant) {
            $score = max($score, nameScore($entity['name'] ?? '', $variant, strtolower($variant)));
        }
        $module = $entity['module'] ?? '';
        if ($module === $query || str_ends_with($module, '.' . $query)) {
            $score = max($score, 90.0);
        }
        if ($score > 0) {
            $hits[] = ['entity' => $entity, 'score' => $score];
        }
    }

    usort($hits, static function (array $a, array $b): int {
        $scoreCmp = ($b['score'] <=> $a['score']);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }

        return ($a['entity']['name'] ?? '') <=> ($b['entity']['name'] ?? '');
    });

    return array_slice($hits, 0, $limit);
}

function nameScore(string $name, string $query, string $qLower): float
{
    if ($name === $query) {
        return 100.0;
    }
    if (strtolower($name) === $qLower) {
        return 95.0;
    }
    if (str_starts_with($name, $query)) {
        return 80.0;
    }
    if (str_starts_with(strtolower($name), $qLower)) {
        return 75.0;
    }
    if (str_contains(strtolower($name), $qLower)) {
        return 50.0;
    }

    return 0.0;
}

/**
 * @param list<array<string, mixed>> $entities
 * @return list<array{entity: array<string, mixed>, score: float}>
 */
function searchByNameAndType(array $entities, string $namePart, string $typePart, int $limit): array
{
    $hits = searchByType($entities, $typePart, $limit * 3);
    if ($namePart === '') {
        return array_slice($hits, 0, $limit);
    }

    $filtered = [];
    foreach ($hits as $hit) {
        $name = $hit['entity']['name'] ?? '';
        if (str_contains($name, $namePart)) {
            $filtered[] = [
                'entity' => $hit['entity'],
                'score' => $hit['score'] + nameScore($name, $namePart, strtolower($namePart)),
            ];
        }
    }

    if ($filtered === []) {
        return searchByName($entities, $namePart, $limit);
    }

    usort($filtered, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

    return array_slice($filtered, 0, $limit);
}

/**
 * @param list<array<string, mixed>> $entities
 * @return list<array{entity: array<string, mixed>, score: float}>
 */
function searchByType(array $entities, string $typeQuery, int $limit): array
{
    $queryParts = splitTypeParts($typeQuery);
    $hits = [];

    foreach ($entities as $entity) {
        $signature = $entity['signature'] ?? null;
        if ($signature === null) {
            continue;
        }
        $sig = extractTypeBody($signature);
        if ($sig === null) {
            continue;
        }
        $score = typeMatchScore($queryParts, splitTypeParts($sig));
        if ($score > 0) {
            $hits[] = ['entity' => $entity, 'score' => $score];
        }
    }

    usort($hits, static function (array $a, array $b): int {
        $scoreCmp = ($b['score'] <=> $a['score']);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }

        return ($a['entity']['name'] ?? '') <=> ($b['entity']['name'] ?? '');
    });

    return array_slice($hits, 0, $limit);
}

function extractTypeBody(string $signature): ?string
{
    $pos = strpos($signature, ' :: ');
    if ($pos === false) {
        return $signature;
    }

    return trim(substr($signature, $pos + 4));
}

/** @return list<string> */
function splitTypeParts(string $type): array
{
    $type = preg_replace('/\s+/', ' ', trim($type)) ?? $type;
    if (str_contains($type, '=>')) {
        $parts = explode('=>', $type, 2);
        $type = trim($parts[1]);
    }
    if (!str_contains($type, '->')) {
        return [normalizeListSugar($type)];
    }

    $parts = [];
    $depth = 0;
    $buf = '';
    $len = strlen($type);
    for ($i = 0; $i < $len; ++$i) {
        $ch = $type[$i];
        if ($ch === '(') {
            ++$depth;
        } elseif ($ch === ')') {
            --$depth;
        }
        if ($ch === '-' && $i + 1 < $len && $type[$i + 1] === '>' && $depth === 0) {
            $parts[] = trim($buf);
            $buf = '';
            ++$i;
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') {
        $parts[] = normalizeListSugar(trim($buf));
    }

    return array_map(normalizeListSugar(...), $parts);
}

/**
 * @param list<string> $queryParts
 * @param list<string> $sigParts
 */
function typeMatchScore(array $queryParts, array $sigParts): float
{
    if ($queryParts === [] || $sigParts === []) {
        return 0.0;
    }

    if (count($queryParts) === count($sigParts) && count($queryParts) <= 4) {
        $best = 0.0;
        foreach (permute($queryParts) as $perm) {
            $mapping = [];
            $score = 0.0;
            foreach ($perm as $i => $qPart) {
                $score += typePartScore($qPart, $sigParts[$i], $mapping);
            }
            $best = max($best, $score / count($queryParts));
        }

        return $best;
    }

    $mapping = [];
    $score = 0.0;

    if (count($queryParts) === 1 && count($sigParts) === 1) {
        return typePartScore($queryParts[0], $sigParts[0], $mapping);
    }

    if (count($queryParts) !== count($sigParts)) {
        if (count($queryParts) < count($sigParts)) {
            $offset = count($sigParts) - count($queryParts);
            $sigTail = array_slice($sigParts, $offset);
            foreach ($queryParts as $i => $qPart) {
                $score += typePartScore($qPart, $sigTail[$i], $mapping);
            }

            return $score / count($queryParts);
        }

        return 0.0;
    }

    foreach ($queryParts as $i => $qPart) {
        $score += typePartScore($qPart, $sigParts[$i], $mapping);
    }

    return $score / count($queryParts);
}

/** @param list<mixed> $items @return list<list<mixed>> */
function permute(array $items): array
{
    if (count($items) <= 1) {
        return [$items];
    }

    $result = [];
    foreach ($items as $i => $item) {
        $rest = $items;
        array_splice($rest, $i, 1);
        foreach (permute($rest) as $perm) {
            $result[] = [$item, ...$perm];
        }
    }

    return $result;
}

/** @param array<string, string> $mapping */
function typePartScore(string $query, string $sig, array &$mapping): float
{
    $query = trim($query);
    $sig = trim($sig);

    if ($query === $sig) {
        return 100.0;
    }

    if (preg_match('/^[a-z][a-z0-9]*$/', $query) === 1) {
        if (!isset($mapping[$query])) {
            $mapping[$query] = $sig;

            return 80.0;
        }

        return $mapping[$query] === $sig ? 80.0 : 0.0;
    }

    if (preg_match('/^[a-z][a-z0-9]*$/', $sig) === 1) {
        return 60.0;
    }

    if (strcasecmp($query, $sig) === 0) {
        return 70.0;
    }

    if (normalizeListSugar($query) === normalizeListSugar($sig)) {
        return 90.0;
    }

    return 0.0;
}

function normalizeListSugar(string $type): string
{
    $type = trim($type);
    if ($type === 'String') {
        return 'String';
    }
    if (preg_match('/^\[([^\]]+)\]$/', $type, $m) === 1) {
        return 'List ' . $m[1];
    }
    if ($type === '()') {
        return '()';
    }
    if (preg_match('/^\(([^(),]+),\s*([^(),]+)\)$/', $type, $m) === 1) {
        return 'Tuple2 ' . $m[1] . ' ' . $m[2];
    }

    return $type;
}

function searchRowKey(array $row): string
{
    return ($row['module'] ?? '') . "\0" . ($row['name'] ?? '') . "\0" . ($row['kind'] ?? '');
}
