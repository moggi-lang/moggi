<?php declare(strict_types=1);

namespace Moggi\Docs;

final class SearchHit
{
    public function __construct(
        public readonly DocEntity $entity,
        public readonly float $score,
    ) {
    }
}

function searchEntities(DocIndex $index): array
{
    return $index->search !== [] ? $index->search : $index->all;
}

/**
 * @return list<SearchHit>
 */
function search(DocIndex $index, string $query, int $limit = 20): array
{
    $entities = searchEntities($index);
    $byKey = [];
    $rows = [];
    foreach ($entities as $entity) {
        $row = entityToSearchRow($entity);
        $byKey[searchRowKey($row)] = $entity;
        $rows[] = $row;
    }

    $hits = searchInRows($rows, $query, $limit);
    $result = [];
    foreach ($hits as $hit) {
        $key = searchRowKey($hit['entity']);
        if (!isset($byKey[$key])) {
            continue;
        }
        $result[] = new SearchHit($byKey[$key], (float) $hit['score']);
    }

    return $result;
}

function docSnippet(string $text, int $maxLen): string
{
    if (strlen($text) <= $maxLen) {
        return $text;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLen);
    }

    return substr($text, 0, $maxLen);
}

/**
 * @param list<SearchHit> $hits
 */
function formatSearchResults(array $hits, bool $json): string
{
    if ($json) {
        $rows = [];
        foreach ($hits as $hit) {
            $e = $hit->entity;
            $rows[] = [
                'module' => $e->module,
                'name' => $e->name,
                'kind' => $e->kind,
                'signature' => $e->signature,
                'doc' => $e->doc,
                'aliases' => $e->aliases,
                'score' => $hit->score,
                'href' => $e->href,
            ];
        }

        return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    if ($hits === []) {
        return "No results\n";
    }

    $lines = [];
    foreach ($hits as $hit) {
        $e = $hit->entity;
        $sig = $e->signature ?? ($e->kind . ' ' . $e->name);
        $mods = $e->module;
        if ($e->aliases !== []) {
            $mods .= ' (also ' . implode(', ', $e->aliases) . ')';
        }
        $lines[] = $mods . '.' . $e->name . ' — ' . $sig;
    }

    return implode("\n", $lines) . "\n";
}
