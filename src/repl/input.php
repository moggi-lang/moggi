<?php declare(strict_types=1);

namespace Moggi\Repl;

/**
 * Readline / multi-line buffer helpers.
 *
 * @param resource $in
 */
function readLineFrom($in, string $prompt, bool $echoPrompt = true): string|false
{
    // Interactive TTY: use readline so ↑/↓ walk command history instead of
    // dumping raw CSI sequences like `^[[A`. Call the global `\readline`
    // (case-insensitive name would otherwise recurse into a local helper).
    if ($echoPrompt && $in === STDIN && \stream_isatty($in) && \function_exists('readline')) {
        return \readline($prompt);
    }

    if ($echoPrompt && \stream_isatty($in)) {
        \fwrite(STDOUT, $prompt);
    }
    $line = \fgets($in);
    if ($line === false) {
        return false;
    }

    return rtrim($line, "\r\n");
}

function addReadlineHistory(string $line): void
{
    if (!\function_exists('readline_add_history')) {
        return;
    }
    $line = rtrim($line, "\r\n");
    if ($line === '') {
        return;
    }
    \readline_add_history($line);
}
