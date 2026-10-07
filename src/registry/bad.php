<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * A package the registry has marked as bad.
 *
 * `bad` is a package-level field in the signed catalog shard — not per version —
 * so the marker covers every release published before and after it. It means
 * "do not use this package": clients refuse to install or build it, and the
 * reason travels with it so a reader sees why. A refusal rather than a warning,
 * because a security advisory is exactly where quietly downgrading to a warning
 * is the wrong default.
 *
 * The field is signed by the same catalog signature as everything beside it, so a
 * marker is as trustworthy as the version list:
 *
 *   "json": {
 *     "owner": "npub1…",
 *     "bad": { "reason": "RCE in the decoder, CVE-2026-…", "at": "2026-10-05", "by": "npub1…" }
 *   }
 *
 * A `bad` value without a `reason` still refuses — the marker is the fact, and a
 * missing reason must not become a way to slip past it.
 */

/**
 * The bad marker on a catalog entry, or null when the package is not marked.
 *
 * @param array<string, mixed> $entry one catalog entry
 * @return ?array{reason: string, at: ?string, by: ?string}
 */
function badPackageMarker(array $entry): ?array
{
    if (!\array_key_exists('bad', $entry) || $entry['bad'] === null || $entry['bad'] === false) {
        return null;
    }
    $marker = $entry['bad'];
    if (\is_string($marker)) {
        $reason = \trim($marker);
    } elseif (\is_array($marker)) {
        $reason = \trim((string) ($marker['reason'] ?? ''));
    } else {
        $reason = '';
    }

    return [
        'reason' => $reason === '' ? 'no reason given' : $reason,
        'at' => \is_array($marker) ? optionalText($marker['at'] ?? null) : null,
        'by' => \is_array($marker) ? optionalText($marker['by'] ?? null) : null,
    ];
}

/** A field that is present and non-empty, or null. */
function optionalText(mixed $value): ?string
{
    if (!\is_string($value)) {
        return null;
    }
    $text = \trim($value);

    return $text === '' ? null : $text;
}

/**
 * The packages among a set of catalog entries the registry has marked bad.
 *
 * @param array<string, array<string, mixed>> $entries name => catalog entry
 * @return array<string, array{reason: string, at: ?string, by: ?string}>
 */
function badPackagesAmong(array $entries): array
{
    $bad = [];
    foreach ($entries as $name => $entry) {
        $marker = badPackageMarker((array) $entry);
        if ($marker !== null) {
            $bad[(string) $name] = $marker;
        }
    }

    return $bad;
}

/**
 * One refusal line for a bad package, naming the reason and who marked it.
 *
 * @param array{reason: string, at: ?string, by: ?string} $marker
 */
function badPackageProblem(string $name, array $marker): string
{
    $by = $marker['by'] === null ? '' : ' (marked by ' . shortNpub($marker['by']) . ')';
    $at = $marker['at'] === null ? '' : ' on ' . $marker['at'];

    return "`{$name}` is marked bad{$at}{$by}: {$marker['reason']} — do not use it";
}

/**
 * The refusal message for a set of bad packages, one line each.
 *
 * One place builds the sentence, so `install`, `build` and `update` refuse a bad
 * package with the same words and only the situation differs.
 *
 * @param array<string, array{reason: string, at: ?string, by: ?string}> $bad
 */
function badPackagesRefusal(array $bad, string $context, string $escape): string
{
    $lines = [];
    foreach ($bad as $name => $marker) {
        $lines[] = badPackageProblem($name, $marker);
    }

    return 'the registry has marked ' . \count($bad) . ' package' . (\count($bad) === 1 ? '' : 's')
        . " {$context} as bad:\n  " . \implode("\n  ", $lines) . "\n{$escape}";
}
