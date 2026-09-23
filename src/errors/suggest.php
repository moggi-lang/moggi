<?php declare(strict_types=1);

namespace Moggi\Errors;

/** @param list<string> $candidates @return list<string> */
function suggestNames(string $unknown, array $candidates, int $limit = 4): array
{
    if ($candidates === []) {
        return [];
    }

    $unknownLower = strtolower($unknown);
    $scored = [];

    foreach ($candidates as $candidate) {
        if ($candidate === $unknown) {
            continue;
        }

        $candidateLower = strtolower($candidate);
        if ($candidateLower === $unknownLower) {
            $scored[] = ['name' => $candidate, 'score' => 0];
            continue;
        }

        $maxDistance = max(2, (int) floor(strlen($unknown) / 3));
        $distance = levenshtein($unknownLower, $candidateLower);
        if ($distance > $maxDistance) {
            if (str_starts_with($candidateLower, $unknownLower) || str_starts_with($unknownLower, $candidateLower)) {
                $scored[] = ['name' => $candidate, 'score' => $maxDistance + 1];
            }
            continue;
        }

        $scored[] = ['name' => $candidate, 'score' => $distance];
    }

    usort($scored, static fn (array $a, array $b): int => $a['score'] <=> $b['score'] ?: strcmp($a['name'], $b['name']));

    $names = [];
    foreach ($scored as $row) {
        if (!\in_array($row['name'], $names, true)) {
            $names[] = $row['name'];
        }
        if (count($names) >= $limit) {
            break;
        }
    }

    return $names;
}

/** @param list<string> $suggestions */
function formatDidYouMean(string $unknown, array $suggestions): string
{
    if ($suggestions === []) {
        return '';
    }

    $lines = \array_map(static fn (string $name): string => "    {$name}", $suggestions);
    $hint = count($suggestions) === 1 ? 'Did you mean this?' : 'Did you mean one of these?';

    return "\n\n{$hint}\n" . \implode("\n", $lines);
}

/** @param list<string> $alternatives */
function formatKnownAlternatives(string $message, array $alternatives): string
{
    $alternatives = array_values(array_unique($alternatives));
    if ($alternatives === []) {
        return $message;
    }

    $lines = \array_map(static fn (string $name): string => "    {$name}", $alternatives);
    $hint = count($alternatives) === 1 ? 'Did you mean this?' : 'Did you mean one of these?';

    return "{$message}\n\n{$hint}\n" . \implode("\n", $lines);
}

/** @param list<string> $candidates */
function appendDidYouMean(string $message, string $unknown, array $candidates, int $limit = 4): string
{
    return $message . formatDidYouMean($unknown, suggestNames($unknown, $candidates, $limit));
}
