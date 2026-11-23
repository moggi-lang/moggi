<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * Build one shard's cross-package search index — `registry-spec.md` §3.7.
 *
 * The source is each package's latest `docs/<name>/<version>/search-index.json`,
 * a tree the release's signed `docs` digest already covers. The result is a map
 * from a documented name to the page that documents it, not a second copy of the
 * documentation: a hit links into the tree where the text was signed.
 *
 * The worker's `search.ts` builds the same document. Both implementations fix the
 * member order, the package order and the per-package order, so the same trees
 * give the same bytes whichever side wrote them — a re-signed seed and a publish
 * cannot disagree about the digest a shard names.
 *
 * A package with no release, or whose latest release names no docs, contributes
 * nothing. A missing or malformed per-package index likewise contributes nothing:
 * the cross-package index is derived and optional, so one bad tree must not stop
 * the tree from being signed. The caller asserts the docs digests separately.
 *
 * @param array<string, array{version: string, tree: string}> $trees package name => its docs tree
 */
function searchIndexDocument(string $prefix, array $trees): ?\stdClass
{
    \ksort($trees, \SORT_STRING);

    $entries = [];
    foreach ($trees as $package => $info) {
        $version = (string) ($info['version'] ?? '');
        $tree = \rtrim((string) ($info['tree'] ?? ''), '/');
        if ($version === '' || $tree === '') {
            continue;
        }

        $path = $tree . '/search-index.json';
        if (! \is_file($path)) {
            continue;
        }
        $text = @\file_get_contents($path);
        if (! \is_string($text)) {
            continue;
        }
        try {
            $index = \json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            continue;
        }
        if (! \is_array($index) || ! \is_array($index['search'] ?? null)) {
            continue;
        }

        foreach ($index['search'] as $entry) {
            if (! \is_array($entry)) {
                continue;
            }
            $entries[] = (object) [
                'package' => (string) $package,
                'version' => $version,
                'module' => \is_scalar($entry['module'] ?? null) ? (string) $entry['module'] : '',
                'name' => \is_scalar($entry['name'] ?? null) ? (string) $entry['name'] : '',
                'kind' => \is_scalar($entry['kind'] ?? null) ? (string) $entry['kind'] : '',
                'signature' => \is_string($entry['signature'] ?? null) ? $entry['signature'] : null,
                'href' => \is_scalar($entry['href'] ?? null) ? (string) $entry['href'] : '',
            ];
        }
    }

    if ($entries === []) {
        return null;
    }

    return (object) [
        'format' => 1,
        'prefix' => $prefix,
        'entries' => $entries,
    ];
}
