<?php declare(strict_types=1);

/**
 * A shard's cross-package search index is hashed by the shard, so its bytes are
 * part of the signed tree: member order, package order and per-package order all
 * have to be fixed. The worker's `search.ts` and this builder are two
 * implementations of one format, and this pins the PHP side to a golden string.
 */

$root = __DIR__;
while (! \is_file($root . '/src/registry/search.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

use function Moggi\Registry\searchIndexDocument;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$work = createTempDir('moggi-search');

try {
    $write = static function (string $tree, string $json) use ($work): string {
        $directory = $work . '/docs/' . $tree;
        if (! \is_dir($directory)) {
            \mkdir($directory, 0777, true);
        }
        \file_put_contents($directory . '/search-index.json', $json);

        return $directory;
    };

    $alpha = $write('alpha/1.0.0', \json_encode([
        'search' => [
            ['module' => 'Alpha', 'name' => 'z', 'kind' => 'value', 'signature' => null, 'href' => 'Alpha.html#z'],
            ['module' => 'Alpha', 'name' => 'a', 'kind' => 'type', 'signature' => 'a :: Int', 'href' => 'Alpha.html#a'],
        ],
    ], \JSON_THROW_ON_ERROR));
    $beta = $write('beta/2.0.0', \json_encode([
        'search' => [
            ['module' => 'Beta', 'name' => 'b', 'kind' => 'value', 'signature' => 'b :: Bool', 'href' => 'Beta.html#b'],
        ],
    ], \JSON_THROW_ON_ERROR));

    $document = searchIndexDocument('al', [
        'beta' => ['version' => '2.0.0', 'tree' => $beta],
        'alpha' => ['version' => '1.0.0', 'tree' => $alpha],
    ]);
    $assert($document !== null, 'two documenting packages must produce an index');
    $assert($document->format === 1, 'the format is 1');
    $assert($document->prefix === 'al', 'the prefix is the shard prefix');
    $assert(\count($document->entries) === 3, 'every search row is carried');

    $golden = '{"format":1,"prefix":"al","entries":['
        . '{"package":"alpha","version":"1.0.0","module":"Alpha","name":"z","kind":"value","signature":null,"href":"Alpha.html#z"},'
        . '{"package":"alpha","version":"1.0.0","module":"Alpha","name":"a","kind":"type","signature":"a :: Int","href":"Alpha.html#a"},'
        . '{"package":"beta","version":"2.0.0","module":"Beta","name":"b","kind":"value","signature":"b :: Bool","href":"Beta.html#b"}'
        . ']}';
    $actual = \json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    $assert($actual === $golden, "the index changed shape or order:\n{$actual}");

    // A package whose tree is missing, malformed or carries no row contributes
    // nothing; one bad tree must not stop a shard from being signed.
    $bad = $write('bad/1.0.0', '{not json');
    $empty = $write('empty/1.0.0', '{"search":[]}');
    $notArray = $write('not-array/1.0.0', '{"search":{}}');
    $missing = $work . '/docs/missing/1.0.0';
    $partial = searchIndexDocument('ba', [
        'bad' => ['version' => '1.0.0', 'tree' => $bad],
        'empty' => ['version' => '1.0.0', 'tree' => $empty],
        'not-array' => ['version' => '1.0.0', 'tree' => $notArray],
        'missing' => ['version' => '1.0.0', 'tree' => $missing],
    ]);
    $assert($partial === null, 'a shard with no usable row has no search document');

    $assert(searchIndexDocument('zz', []) === null, 'an empty shard has no search document');
} finally {
    removeDirectory($work);
}

\fwrite(STDOUT, "registry search index tests passed ({$checks} checks)\n");
