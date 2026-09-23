#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

// describeTokenKind() must describe every `TokenKind::cases()` entry: a token kind
// missing from the `match` used to turn a parse diagnostic into an UnhandledMatchError.

require_once $root . '/src/compiler.php';

use Moggi\Syntax\Lexer\TokenKind;
use Moggi\Syntax\Parser\ParseError;
use Moggi\Syntax\Parser\Token;

use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\describeToken;
use function Moggi\Syntax\Parser\describeTokenKind;
use function Moggi\Syntax\Parser\parse;

$failures = [];
$checks = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures, &$checks): void {
    ++$checks;
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};

// 1. Every TokenKind has a description

foreach (TokenKind::cases() as $kind) {
    try {
        $description = describeTokenKind($kind);
    } catch (UnhandledMatchError $e) {
        $failures[] = "TokenKind::{$kind->name} has no describeTokenKind() arm";
        ++$checks;
        continue;
    }
    ++$checks;
    if ($description === '') {
        $failures[] = "TokenKind::{$kind->name} has an empty description";
    }
}

// 2. `float` and `qualified`, the two kinds that used to crash

$assertSame('float literal', describeTokenKind(TokenKind::Float), 'Float description');
$assertSame("keyword 'qualified'", describeTokenKind(TokenKind::KwQualified), 'KwQualified description');

// `describeToken()` delegates through its `default` arm, so it was affected too.
$assertSame(
    'float literal',
    describeToken(new Token(TokenKind::Float, '1.5', 1, 1)),
    'describeToken() on a float literal',
);
$assertSame(
    "keyword 'qualified'",
    describeToken(new Token(TokenKind::KwQualified, 'qualified', 1, 1)),
    'describeToken() on the qualified keyword',
);

// 3. End to end: the parse error itself, not an UnhandledMatchError

/** @return array{0: string, 1: string} [error class, message] for a snippet */
$parseError = static function (string $source): array {
    try {
        parse(lex($source, 't.mog'), $source, 't.mog');
    } catch (ParseError $e) {
        return [ParseError::class, $e->getMessage()];
    } catch (Throwable $e) {
        return [$e::class, $e->getMessage()];
    }

    return ['(no error)', ''];
};

[$class, $message] = $parseError('x = \\1.5 -> 2');
$assertSame(ParseError::class, $class, 'a float literal in pattern position must be a ParseError');
$assertSame('expected pattern, found float literal', $message, 'float literal diagnostic');

[$class, $message] = $parseError('x = qualified');
$assertSame(ParseError::class, $class, 'the qualified keyword must be a ParseError');
$assertSame("expected expression, found keyword 'qualified'", $message, 'qualified keyword diagnostic');

// Report

if ($failures !== []) {
    fwrite(STDERR, 'token-kind description test FAILED: ' . count($failures) . " of {$checks} checks\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "token-kind description tests passed ({$checks} assertions)\n";
