#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Per-instance implementation lookup (textDocument/implementation): a class name yields its
 * instance blocks, a class method the concrete definitions inside them, and same-name methods of
 * other classes are never mixed in.
 */

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcImplementation;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tmp = sys_get_temp_dir() . '/moggi-lsp-impl-' . bin2hex(random_bytes(4));
$assert(mkdir($tmp), 'create temp dir');

// Two classes that both define `same`, to prove results are filtered by class
// membership rather than by name.
$instSrc = <<<'MOG'
module Inst where

class Eqish a where
  same :: a -> a -> Bool

instance Eqish Int where
  same x y = x == y

instance Eqish Bool where
  same x y = x == y

class Pairish a where
  same :: a -> a -> Bool

instance Pairish (a, b) where
  same x y = x == y
MOG;
$useSrc = <<<'MOG'
module Use where

import Inst

check :: Bool
check = 3 `same` 4
MOG;
$instPath = $tmp . '/Inst.mog';
$usePath = $tmp . '/Use.mog';
$assert(file_put_contents($instPath, $instSrc) !== false, 'write Inst.mog');
$assert(file_put_contents($usePath, $useSrc) !== false, 'write Use.mog');
$instUri = pathToUri($instPath);
$useUri = pathToUri($usePath);

$svc = new AnalysisService();
$svc->setWorkspaceRoot($tmp);
$svc->openDocument($instUri, $instSrc, 1);
$svc->openDocument($useUri, $useSrc, 1);
$svc->ensureAnalyzed($instUri);
$svc->ensureAnalyzed($useUri);

$lineOf = static function (string $needle, string $src): int {
    $at = strpos($src, $needle);
    if ($at === false) {
        throw new RuntimeException("fixture missing: {$needle}");
    }
    return substr_count(substr($src, 0, $at), "\n");
};
$colOf = static function (string $needle, string $src): int {
    $at = strpos($src, $needle);
    if ($at === false) {
        throw new RuntimeException("fixture missing: {$needle}");
    }
    return $at - (int) (strrpos(substr($src, 0, $at + 1), "\n") ?: -1);
};
$lines = static function (array $locs): array {
    return array_map(static fn (array $loc): int => (int) $loc['range']['start']['line'], $locs);
};

// Class name → all instance blocks of that class (and nothing else)
$inst = svcImplementation($svc, $instUri, ['line' => $lineOf('class Eqish', $instSrc), 'character' => $colOf('class Eqish', $instSrc) + 6]);
$assert(is_array($inst) && count($inst) === 2, 'Eqish implements to both instance blocks: ' . json_encode($inst));
if (is_array($inst)) {
    $got = $lines($inst);
    sort($got);
    $assert($got === [$lineOf('instance Eqish Int', $instSrc), $lineOf('instance Eqish Bool', $instSrc)], 'Eqish instance lines exact: ' . json_encode($got));
}

// Class-method signature → concrete defs of that class only. The Pairish
// method with the same name must NOT appear; the signature itself and the
// instance heads are never part of the result.
$eqSig = svcImplementation($svc, $instUri, ['line' => $lineOf('same :: a -> a -> Bool', $instSrc), 'character' => $colOf('same ::', $instSrc)]);
$assert(is_array($eqSig) && count($eqSig) === 2, 'Eqish.same signature → 2 concrete defs: ' . json_encode($eqSig));
if (is_array($eqSig)) {
    $got = $lines($eqSig);
    sort($got);
    $assert($got === [$lineOf('instance Eqish Int', $instSrc) + 1, $lineOf('instance Eqish Bool', $instSrc) + 1], 'Eqish.same def lines exact: ' . json_encode($got));
}

$pairSig = svcImplementation($svc, $instUri, ['line' => $lineOf('class Pairish', $instSrc) + 1, 'character' => 2]);
$assert(is_array($pairSig) && count($pairSig) === 1, 'Pairish.same signature → 1 concrete def: ' . json_encode($pairSig));
if (is_array($pairSig)) {
    $got = $lines($pairSig);
    $assert($got === [$lineOf('instance Pairish', $instSrc) + 1], 'Pairish.same def line exact, no Eqish mixing: ' . json_encode($got));
}

// Use site of a method from another module (backtick infix) → the concrete
// instance-method definitions of the resolved class.
// A backtick operator use has no Variable node, so the class is not resolvable
// from the position; the handler then returns the concrete defs of ALL classes
// with that method name (never instance heads, never unrelated decls).
$useImpl = svcImplementation($svc, $useUri, ['line' => $lineOf('`same`', $useSrc), 'character' => $colOf('`same`', $useSrc) + 1]);
$assert(is_array($useImpl) && count($useImpl) === 3, 'method use across modules → all concrete defs (2 Eqish + 1 Pairish): ' . json_encode($useImpl));
if (is_array($useImpl)) {
    foreach ($useImpl as $loc) {
        $assert(($loc['uri'] ?? '') === $instUri, 'cross-module impl locations point at Inst.mog: ' . json_encode($loc));
    }
    $got = $lines($useImpl);
    sort($got);
    $assert($got === [$lineOf('instance Eqish Int', $instSrc) + 1, $lineOf('instance Eqish Bool', $instSrc) + 1, $lineOf('instance Pairish', $instSrc) + 1], 'cross-module def lines are the instance bodies: ' . json_encode($got));
}

// None-cases: unknown word (probed inside a string literal) and out-of-bounds.
$noneSrcLine = $lineOf('module Inst', $instSrc);
$none = svcImplementation($svc, $instUri, ['line' => $noneSrcLine, 'character' => 0]);
$assert($none === null || is_array($none), 'module keyword probe does not crash');

$assert(svcImplementation($svc, $instUri, ['line' => 9999, 'character' => 9999]) === null, 'out-of-bounds position is none');

echo "lsp implementation tests passed\n";
