<?php declare(strict_types=1);

// A callable's kind is a fact recorded at creation, and capture handling follows
// the kind — not the name. The generated prefixes (`λ`, `__letrec`, `__spec_`)
// are presentation: an IR FunctionDecl can carry such a name while being Source,
// and a lifted function can be spelled anything.
//
// This pins both directions:
//   1. lowering records Source / Lambda / Letrec on the declarations it makes;
//   2. `indexCapturedFunctions` classifies by kind, so a Source named `λ0` is not
//      a capture binding and a Lambda named `plain` is.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\IR;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Pipeline\PipelineRequest;
use Moggi\Pipeline\PipelineStage;
use Moggi\Syntax\Ast;

use function Moggi\Optimize\Support\indexCapturedFunctions;
use function Moggi\Pipeline\run as pipelineRun;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

/** @var list<string> $failures */
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$filename = 'FunctionKind.mog';
$source = <<<'MOGGI'
module Main where

main :: ()
main =
  let go k = go k
      add = \x -> x
  in add (go ())
MOGGI;

$program = parse(lex($source, $filename), $source, $filename);
$artifacts = pipelineRun(new PipelineRequest(
    source: $source,
    filename: $filename,
    program: $program,
    optimize: false,
    context: \Moggi\Pipeline\CompilationContext::for('php', CompilePurpose::Library),
), PipelineStage::Ir);

$byName = [];
foreach ($artifacts->ir->functions as $function) {
    $byName[$function->name] = $function;
}

$check(($byName['main'] ?? null)?->kind === IR\FunctionKind::Source, 'a declared function is not Source');

$lambdas = \array_filter($byName, static fn (IR\FunctionDecl $fn): bool => $fn->kind === IR\FunctionKind::Lambda);
$letrecs = \array_filter($byName, static fn (IR\FunctionDecl $fn): bool => $fn->kind === IR\FunctionKind::Letrec);
$check(\count($lambdas) === 1, 'expected one Lambda declaration, found ' . \count($lambdas));
$check(\count($letrecs) === 1, 'expected one Letrec declaration, found ' . \count($letrecs));
$check(
    \array_key_exists('λ0', $lambdas) || \array_key_exists('λ1', $lambdas),
    'the anonymous lambda is not recorded as Lambda: ' . \implode(', ', \array_keys($lambdas)),
);
$check(
    \array_key_exists('__letrec0', $letrecs) || \array_key_exists('__letrec1', $letrecs),
    'the recursive local group is not recorded as Letrec: ' . \implode(', ', \array_keys($letrecs)),
);

// Every function the kind classifies as capture-taking is exactly the lifted set;
// the names agree today, but the classification must not read them.
$captured = indexCapturedFunctions(\array_values($byName));
$capturedNames = \array_keys($captured);
$kindNames = [...\array_keys($lambdas), ...\array_keys($letrecs)];
\sort($capturedNames);
\sort($kindNames);
$check(
    $capturedNames === $kindNames,
    'indexCapturedFunctions disagrees with the declared kinds: captured='
        . \implode(',', $capturedNames) . ' kinds=' . \implode(',', $kindNames),
);

// ---- spelling is not classification --------------------------------------

$free = new IR\Block([new IR\Ret(new IR\Local('free'))]);
$lookalike = new IR\FunctionDecl('λ0', [], null, $free);
$liftedOddly = new IR\FunctionDecl('plain', [], null, $free, kind: IR\FunctionKind::Lambda);

$index = indexCapturedFunctions([$lookalike, $liftedOddly]);
$check(! isset($index['λ0']), 'a Source function named λ0 was indexed as a capture binding');
$check(isset($index['plain']), 'a Lambda named plain was not indexed as a capture binding');

$check(
    $liftedOddly->withBody($free)->kind === IR\FunctionKind::Lambda,
    'withBody dropped the function kind',
);
$check(
    $lookalike->kind->takesCaptures() === false
        && $liftedOddly->kind->takesCaptures() === true
        && IR\FunctionKind::SpecClone->takesCaptures() === false
        && IR\FunctionKind::Generated->takesCaptures() === false,
    'FunctionKind::takesCaptures disagrees with the documented capture set',
);

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " function-kind failure(s):\n  " . \implode("\n  ", $failures),
    );
}

echo "function kind tests passed\n";
