#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

require $root . '/src/compiler.php';

use Moggi\Docs;

$failures = 0;

function hitsToNames(array $hits): array
{
    $rows = [];
    foreach ($hits as $hit) {
        $rows[] = [
            'name' => $hit['entity']['name'] ?? '',
            'score' => $hit['score'],
        ];
    }

    return $rows;
}

$fixture = __DIR__ . '/../fixture';
$index = Docs\loadOrBuildIndex($fixture, [], true);
$rows = [];
foreach ($index->search as $entity) {
    $rows[] = Docs\entityToSearchRow($entity);
}

$queries = [
    ['query' => 'a -> a', 'limit' => 5, 'expected' => null],
    ['query' => '[a] -> [a]', 'limit' => 5, 'expected' => null],
    ['query' => 'is:module', 'limit' => 10, 'expected' => null],
    ['query' => 'is:value', 'limit' => 20, 'expected' => null],
    ['query' => 'id', 'limit' => 5, 'expected' => null],
    ['query' => 'id :: a -> a', 'limit' => 5, 'expected' => null],
];

foreach ($queries as &$case) {
    $case['expected'] = hitsToNames(Docs\searchInRows($rows, $case['query'], $case['limit']));
}
unset($case);

$tmpDir = sys_get_temp_dir() . '/moggi-moogle-parity-' . getmypid();
if (!mkdir($tmpDir) && !is_dir($tmpDir)) {
    fwrite(STDERR, "FAIL cannot create temp dir\n");
    exit(1);
}

$jsPath = $tmpDir . '/moogle.js';
$rowsPath = $tmpDir . '/rows.json';
$queriesPath = $tmpDir . '/queries.json';

file_put_contents($jsPath, Docs\emitMoogleSearchJs());
file_put_contents($rowsPath, json_encode($rows, JSON_UNESCAPED_UNICODE));
file_put_contents($queriesPath, json_encode($queries, JSON_UNESCAPED_UNICODE));

$node = trim((string) shell_exec('command -v node'));
if ($node === '') {
    fwrite(STDERR, "FAIL moogle parity: node is required\n");
    array_map(static fn ($f) => @unlink($f), glob($tmpDir . '/*') ?: []);
    @rmdir($tmpDir);
    exit(1);
}

$runner = __DIR__ . '/parity.js';
$cmd = escapeshellarg($node) . ' '
    . escapeshellarg($runner) . ' '
    . escapeshellarg($jsPath) . ' '
    . escapeshellarg($rowsPath) . ' '
    . escapeshellarg($queriesPath);

exec($cmd, $output, $exitCode);
foreach ($output as $line) {
    fwrite(STDERR, $line . "\n");
}

array_map(static fn ($f) => @unlink($f), glob($tmpDir . '/*') ?: []);
@rmdir($tmpDir);

if ($exitCode !== 0) {
    exit(1);
}

$stringMiss = Docs\searchInRows($rows, '[Char] -> Int', 5);
foreach ($stringMiss as $hit) {
    if (($hit['entity']['name'] ?? '') === 'id'
        && str_contains((string) ($hit['entity']['signature'] ?? ''), 'String')) {
        ++$failures;
        fwrite(STDERR, "FAIL String must not match [Char] for id\n");
    }
}

exit($failures === 0 ? 0 : 1);
