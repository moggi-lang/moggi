<?php declare(strict_types=1);

namespace Moggi\Errors;

use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

require_once __DIR__ . '/errors/suggest.php';

/**
 * Machine-readable diagnostic codes, and what each one means.
 *
 * Editors and other tooling branch on the code, so it is part of the compiler's interface: a code
 * is added here before it is ever attached to a diagnostic, and never renamed. The `parse/…` and
 * `unify`/`hole`/`non-exhaustive` split is what lets a caller tell "I could not read this" apart
 * from "I read it and it does not typecheck", which the stage-only wording (`parse error`, `type
 * error`) cannot.
 *
 * @return array<string, string> code => one-line description
 */
function diagnosticRegistry(): array
{
    return [
        'lex' => 'the source could not be tokenized',
        'parse' => 'the token stream is not a well-formed program',
        'parse/late-import' => 'an import appears after a declaration',
        'parse/clause-arity' => 'equations of one function disagree on their parameter count',
        'parse/pragma' => 'a pragma is malformed or names an unknown extension',
        'parse/backend' => 'a module names backend implementations outside a BACKEND pragma',
        'type' => 'an expression does not typecheck',
        'unify' => 'two types could not be unified',
        'hole' => 'a typed hole was left in the program',
        'non-exhaustive' => 'a pattern match does not cover every case',
        'unused-import' => 'an import is never referenced',
    ];
}

/**
 * The stable code for a diagnostic, or null when the throwable is not one.
 *
 * Codes are carried by the diagnostic itself (`LexError`/`ParseError`/`TypeError`) rather than
 * recovered from the message, so a reworded message can never change what tooling sees.
 */
function diagnosticCode(\Throwable $error): ?string
{
    if ($error instanceof LexError
        || $error instanceof ParseError) {
        return $error->diagnosticCode;
    }

    if ($error instanceof TypeError) {
        return $error->diagnosticCode ?? 'type';
    }

    return null;
}

/**
 * The source span a diagnostic points at, or null when it carries none.
 *
 * A diagnostic without a location cannot be rendered against a source line or turned into an LSP
 * range, so callers that need one test for null instead of guessing a position.
 *
 * @return ?array{line: int, col: int, endCol: int}
 */
function diagnosticSpan(\Throwable $error): ?array
{
    if (!($error instanceof LexError
        || $error instanceof ParseError
        || $error instanceof TypeError)) {
        return null;
    }

    if ($error->line() <= 0) {
        return null;
    }

    $col = \max(1, $error->col());

    return ['line' => $error->line(), 'col' => $col, 'endCol' => \max($col, $error->endCol())];
}

function formatDiagnostic(
    string $filename,
    string $kind,
    string $message,
    string $source,
    int $line,
    int $col,
    int $endCol = 0,
): string {
    if ($endCol < $col) {
        $endCol = $col;
    }

    $lines = preg_split('/\R/', $source) ?: [''];
    $lineCount = count($lines);

    while ($line > 1 && ($lines[$line - 1] ?? '') === '') {
        --$line;
    }

    $text = $lines[$line - 1] ?? '';
    $width = max(1, strlen((string) $lineCount));
    $gutter = str_pad((string) $line, $width, ' ', STR_PAD_LEFT);
    $caret = str_repeat(' ', max(0, $col - 1)) . str_repeat('^', max(1, $endCol - $col + 1));

    $location = $filename !== '' ? "{$filename}:{$line}:{$col}" : "line {$line}, column {$col}";
    $output = "{$location}: {$kind}: {$message}\n\n";
    $output .= "  {$gutter} | {$text}\n";
    $output .= '  ' . str_repeat(' ', $width) . " | {$caret}\n";

    return $output;
}
