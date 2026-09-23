#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Records in the language server: hovering a field label, and completing field
 * labels inside `{ … }`.
 *
 * A record field belongs to its record rather than to the module, so both
 * features have to start from the record's type — which is what the label's
 * container says (`Person { … }`) or, for `p { … }`, what the receiver's binder
 * says. Completion is asked while the buffer does not parse (a brace is open),
 * so it must also work from the source text.
 */

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcCompletion;
use function Moggi\LSP\TextDocument\svcHover;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tmp = sys_get_temp_dir() . '/moggi-lsp-records-' . bin2hex(random_bytes(3));
$assert(mkdir($tmp), 'create temp dir');

// `name` is declared by two records at different positions, which is the case a
// name-only lookup cannot answer.
$source = <<<'MOG'
module Records where

data Address = Address
  { city :: String
  , zip  :: String
  } deriving (Show)

data Person = Person
  { name    :: String
  , age     :: Int
  , address :: Address
  } deriving (Show)

data Company = Company
  { city :: String
  , name :: String
  } deriving (Show)

alice :: Person
alice = Person { name = "Alice", age = 30, address = Address { city = "B", zip = "1" } }

moggi :: Company
moggi = Company { city = "Berlin", name = "Moggi" }

main :: IO ()
main = do
  putStrLn alice.name
  putStrLn alice.address.city
  putStrLn moggi.name
  putStrLn (show (alice { age = 31 }))
  case alice of
    Person { name = n } -> putStrLn n
MOG;
$file = $tmp . '/Records.mog';
$assert(file_put_contents($file, $source) !== false, 'write Records.mog');

$svc = new AnalysisService([$root . '/lib']);
$svc->setWorkspaceRoot($tmp);
$uri = pathToUri($file);
$svc->openDocument($uri, $source, 1);
$result = $svc->ensureAnalyzed($uri);
$assert($result !== null && $result->diagnostics === [], 'fixture is clean: ' . json_encode(array_map(static fn ($d) => $d['message'] ?? '', $result->diagnostics ?? [])));

$lines = explode("\n", $source);
$assert($lines !== [], 'source lines');

/** 0-based line and character of $needle's Nth occurrence, $offset into it. */
$at = static function (string $needle, int $skip = 0, int $offset = 0) use ($lines): array {
    $hits = 0;
    foreach ($lines as $i => $line) {
        $col = strpos($line, $needle);
        if ($col === false) {
            continue;
        }
        if ($hits++ < $skip) {
            continue;
        }

        return [$i, (int) $col + $offset];
    }
    throw new RuntimeException("needle not found: {$needle}");
};

$hover = static function (array $position) use ($svc, $uri): string {
    $result = svcHover($svc, $uri, ['line' => $position[0], 'character' => $position[1]]);

    return $result === null ? '' : $result['contents']['value'];
};

// ---- Hover on a projected field: the receiver's type chooses the record.
// The dot itself is not the field, so the label is one character further in.
$dot = $at('.name', 0);
$hovered = $hover($at('.name', 0, 1)); // alice.name -> Person.name
$assert(str_contains($hovered, 'name :: String'), 'projection hover shows the field type: ' . $hovered);
$assert(str_contains($hovered, 'field of `Person`'), 'projection hover names Person: ' . $hovered);
$assert(str_contains($hovered, 'Records.mog:9'), 'projection hover points at the field: ' . $hovered);

$hovered = $hover($at('.city', 0, 1)); // alice.address.city -> Address.city
$assert(str_contains($hovered, 'field of `Address`'), 'chained projection resolves Address: ' . $hovered);

$hovered = $hover($at('.name', 1, 1)); // moggi.name -> Company.name
$assert(str_contains($hovered, 'field of `Company`'), 'the same label on another record resolves by receiver: ' . $hovered);
$assert(str_contains($hovered, 'Records.mog:16'), 'Company.name line: ' . $hovered);
$assert(str_contains($hovered, 'city :: String') === false, 'Company.name is not Address.city for the same label: ' . $hovered);

// The dot is part of the projection expression, not the field label, so it
// answers with the projection's own type.
$hovered = $hover($dot);
$assert(str_contains($hovered, 'String'), 'hover on the dot gives the projection type: ' . $hovered);
$assert(!str_contains($hovered, 'field of'), 'hover on the dot is not a field hover: ' . $hovered);

// ---- Hover on a label the reader wrote, in every place a record names fields.
foreach ([
    ['name = "Alice"', 0, 'construction label'],
    ['{ age = 31 }', 2, 'update label'],
    ['{ name = n }', 2, 'pattern label'],
    ['name    :: String', 0, 'declaration label'],
] as [$needle, $offset, $where]) {
    $hovered = $hover($at($needle, 0, $offset));
    $assert(str_contains($hovered, ':: '), "{$where} hover has a type: " . $hovered);
    $assert(str_contains($hovered, 'field of `'), "{$where} hover names its record: " . $hovered);
}

// ---- A projection's hover covers only the label, not the receiver.
$range = svcHover($svc, $uri, ['line' => $dot[0], 'character' => $dot[1] + 1]);
$assert($range !== null && isset($range['range']), 'projection hover carries a range');
$assert(
    (int) $range['range']['start']['character'] === $dot[1] + 1
        && (int) $range['range']['end']['character'] === $dot[1] + 5,
    'range covers just `name`: ' . json_encode($range['range']),
);

$labels = static function (array $position) use ($svc, $uri): array {
    $result = svcCompletion($svc, $uri, ['line' => $position[0], 'character' => $position[1]]);

    return array_map(static fn (array $item): string => (string) $item['label'], $result['items']);
};

// ---- Completion inside braces, while the buffer is one token short of parsing.
$broken = "module Broken where\n\n"
    . "data Address = Address\n  { city :: String\n  , zip  :: String\n  } deriving (Show)\n\n"
    . "data Person = Person\n  { name    :: String\n  , age     :: Int\n  , address :: Address\n  } deriving (Show)\n\n"
    . "alice :: Person\n"
    . "alice = Person { name = \"Alice\", age = 30, address = Address { city = \"B\", zip = \"1\" } }\n\n"
    . "born :: Person\n"
    . "born = Person { name = \"Baby\",\n\n"
    . "renamed :: Person -> Person\n"
    . "renamed p = p { age = 1,\n\n"
    . "nested :: Person\n"
    . "nested = alice { address = Address {\n\n"
    . "bump:: Person -> Person\n"
    . "bump p = go\n  where\n    go q = q { age = 1,\n";
$brokenFile = $tmp . '/Broken.mog';
$assert(file_put_contents($brokenFile, $broken) !== false, 'write Broken.mog');
$brokenUri = pathToUri($brokenFile);
$svc->openDocument($brokenUri, $broken, 1);
$brokenResult = $svc->ensureAnalyzed($brokenUri);
$assert($brokenResult !== null, 'broken buffer analyzed');
$brokenLines = explode("\n", $broken);
$labelsIn = static function (string $needle) use ($svc, $brokenUri, $brokenLines): array {
    foreach ($brokenLines as $i => $line) {
        $col = strpos($line, $needle);
        if ($col === false) {
            continue;
        }
        $result = svcCompletion($svc, $brokenUri, ['line' => $i, 'character' => $col + \strlen($needle)]);

        return array_map(static fn (array $item): string => (string) $item['label'], $result['items']);
    }
    throw new RuntimeException("needle not found: {$needle}");
};

$assert($labelsIn('born = Person { name = "Baby",') === ['age', 'address'], 'construction: the written label is not offered again');
$assert($labelsIn('renamed p = p { age = 1,') === ['name', 'address'], 'update on a parameter resolves through its signature: ' . json_encode($labelsIn('renamed p = p { age = 1,')));
$assert($labelsIn('go q = q { age = 1,') === ['name', 'address'], 'update on a where-bound parameter');
$assert($labelsIn('nested = alice { address = Address {') === ['city', 'zip'], 'nested braces answer for the inner record');

// Outside braces the record-label path stays out of the way.
$plain = $labelsIn('born = Person { name = "Baby",');
$assert($plain !== [], 'labels were offered');

// ---- The same completion on a buffer that parses.
$closed = str_replace('born = Person { name = "Baby",', 'born = Person { name = "Baby" }', $broken);
$svc->changeDocument($brokenUri, [['text' => $closed]], 2);
$svc->ensureAnalyzed($brokenUri);
$closedLines = explode("\n", $closed);
foreach ($closedLines as $i => $line) {
    $col = strpos($line, 'Person { name = "Baby" }');
    if ($col === false) {
        continue;
    }
    $result = svcCompletion($svc, $brokenUri, ['line' => $i, 'character' => $col + \strlen('Person {')]);
    $offered = array_map(static fn (array $item): string => (string) $item['label'], $result['items']);
    $assert($offered === ['name', 'age', 'address'], 'parsed buffer offers the record fields in declaration order: ' . json_encode($offered));
    $detail = $result['items'][0]['detail'] ?? '';
    $assert(str_contains($detail, 'field of Person'), 'completion detail names the record: ' . $detail);
    break;
}
