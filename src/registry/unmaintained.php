<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * A package the registry has marked unmaintained.
 *
 * `unmaintained` is a package-level field in the signed catalog shard, beside
 * `bad`, and travels with the version list so a client reads it without fetching
 * a package file:
 *
 *   "json": {
 *     "unmaintained": { "at": "2026-09-01", "note": "no releases since 0.9" }
 *   }
 *
 * Unlike `bad` it does not block: the package still works, so it is a
 * maintenance signal a client warns about rather than a reason to refuse. The
 * marker is signed by the same catalog signature as everything beside it, so it
 * is as trustworthy as the version list. A bare `true` is a marker with no note
 * — the fact travels, the explanation may not.
 */

/**
 * The unmaintained marker on a catalog entry, or null when it is not marked.
 *
 * @param array<string, mixed> $entry one catalog entry
 * @return ?array{note: ?string, at: ?string}
 */
function unmaintainedMarker(array $entry): ?array
{
    if (!\array_key_exists('unmaintained', $entry) || $entry['unmaintained'] === null || $entry['unmaintained'] === false) {
        return null;
    }
    $marker = $entry['unmaintained'];
    if (\is_string($marker)) {
        $note = \trim($marker);
    } elseif (\is_array($marker)) {
        $note = \trim((string) ($marker['note'] ?? ''));
    } else {
        $note = '';
    }

    return [
        'note' => $note === '' ? null : $note,
        'at' => \is_array($marker) ? optionalText($marker['at'] ?? null) : null,
    ];
}

/**
 * The packages among a set of catalog entries the registry has marked
 * unmaintained.
 *
 * @param array<string, array<string, mixed>> $entries name => catalog entry
 * @return array<string, array{note: ?string, at: ?string}>
 */
function unmaintainedPackagesAmong(array $entries): array
{
    $marked = [];
    foreach ($entries as $name => $entry) {
        $marker = unmaintainedMarker((array) $entry);
        if ($marker !== null) {
            $marked[(string) $name] = $marker;
        }
    }

    return $marked;
}

/**
 * One warning line for an unmaintained package, naming the note and the date when
 * the registry has them.
 *
 * @param array{note: ?string, at: ?string} $marker
 */
function unmaintainedPackageNote(string $name, array $marker): string
{
    $at = $marker['at'] === null ? '' : ' since ' . $marker['at'];
    $note = $marker['note'] === null ? '' : ': ' . $marker['note'];

    return "`{$name}` is marked unmaintained{$at}{$note} — it still works, but expect no further releases";
}
