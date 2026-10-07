<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * The cross-package search index — `registry-spec.md` §3.7.
 *
 * A search must not make a client gather every docs tree a registry might hold,
 * so the names a shard's packages document are collected once, at publish, into
 * `search/<prefix>.json` — a file the shard names and digests, and never a member
 * of the shard itself.
 *
 * The source is the per-version `docs/<name>/<version>/search-index.json` a
 * rendered tree already carries. That tree is covered by the release's signed
 * `docs` digest, so this index adds no authority of its own: it is a map from a
 * name to the page that documents it, and a hit is a link into the tree where the
 * text was signed.
 *
 * This is the only builder on the PHP side; the Worker has the same one in
 * `src/lib/search.ts`. Both fix the same member order and the same ordering rule,
 * so the same trees give the same bytes whichever side wrote them — a re-signed
 * example and a published shard cannot disagree.
 */

/**
 * One package's entries, in the order its own tree lists them.
 *
 * A package that documents nothing — no tree, no `search-index.json`, a file that
 * is not a JSON object — contributes no entries rather than a failure: an index
 * is derived and optional, and a shard is not broken because one package under it
 * has no docs.
 *
 * `doc`, `section` and `aliases` are dropped and `package`/`version` added, so an
 * entry is what a search needs to show a hit and build the link to it — and no
 * document text, which is read from the tree rather than copied into the index.
 *
 * @return list<\stdClass>
 */
function searchEntries(string $package, string $version, string $tree): array
{
    $path = \rtrim($tree, '/') . '/search-index.json';
    if (!\is_file($path)) {
        return [];
    }
    $index = \json_decode((string) \file_get_contents($path), true);
    if (!\is_array($index) || !\is_array($index['search'] ?? null)) {
        return [];
    }

    $entries = [];
    foreach ($index['search'] as $entry) {
        if (!\is_array($entry)) {
            continue;
        }
        $piece = new \stdClass();
        $piece->package = $package;
        $piece->version = $version;
        $piece->module = (string) ($entry['module'] ?? '');
        $piece->name = (string) ($entry['name'] ?? '');
        $piece->kind = (string) ($entry['kind'] ?? '');
        $piece->signature = \is_string($entry['signature'] ?? null) ? $entry['signature'] : null;
        $piece->href = (string) ($entry['href'] ?? '');
        $entries[] = $piece;
    }

    return $entries;
}

/**
 * A shard's index document, or null when nothing under it documents anything.
 *
 * Packages arrive keyed by name so the order is a decision, not an accident: the
 * file is hashed and two implementations must agree on its bytes, so packages are
 * sorted by name and a shard with no entries has no document rather than an empty
 * one. An entry keeps the order of its tree, which is the docs generator's.
 *
 * @param array<string, array{version: string, tree: string}> $packages
 */
function searchIndexDocument(string $prefix, array $packages): ?\stdClass
{
    \ksort($packages, \SORT_STRING);
    $entries = [];
    foreach ($packages as $package => $tree) {
        foreach (searchEntries($package, $tree['version'], $tree['tree']) as $entry) {
            $entries[] = $entry;
        }
    }
    if ($entries === []) {
        return null;
    }

    $document = new \stdClass();
    $document->format = 1;
    $document->prefix = $prefix;
    $document->entries = $entries;

    return $document;
}
