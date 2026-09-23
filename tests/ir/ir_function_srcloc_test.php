#!/usr/bin/env php
<?php declare(strict_types=1);

// A function's IR location is the start of its body, not its signature: backends record it as the
// function's debug line, so a signature position would point a runtime frame at the type annotation.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Pipeline\CompilePurpose;
use Moggi\Pipeline\PipelineRequest;
use Moggi\Pipeline\PipelineStage;
use Moggi\Syntax\Ast;

use function Moggi\Pipeline\run as pipelineRun;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$filename = 'InlineSrcLoc.mog';
$source = <<<'MOGGI'
module Main where

boom :: Int
boom = twice 1

twice :: Int -> Int
twice x = x
MOGGI;

$program = parse(lex($source, $filename), $source, $filename);
$artifacts = pipelineRun(new PipelineRequest(
    purpose: CompilePurpose::Library,
    source: $source,
    filename: $filename,
    program: $program,
    optimize: false,
    backend: 'php',
), PipelineStage::Ir);

$decls = [];
foreach ($program->items as $item) {
    if ($item instanceof Ast\FunctionDecl) {
        $decls[$item->name] = $item;
    }
}

$irFunctions = [];
foreach ($artifacts->ir->functions as $function) {
    $irFunctions[$function->name] = $function;
}

foreach (['boom', 'twice'] as $name) {
    $decl = $decls[$name];
    $ir = $irFunctions[$name]->srcLoc;
    $assert(
        $ir->line === $decl->body->line && $ir->col === $decl->body->col,
        "{$name}: IR location {$ir->line}:{$ir->col} is not the body start {$decl->body->line}:{$decl->body->col}",
    );
}

// The declaration span is a diagnostic position (line 3, the signature); a runtime frame points
// at the call on line 4 instead.
$assert(
    $irFunctions['boom']->srcLoc->line === 4 && $irFunctions['boom']->srcLoc->col === 8,
    'boom: IR location is not the `twice 1` call',
);
