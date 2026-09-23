<?php declare(strict_types=1);

namespace Moggi\Repl;

use Moggi\Syntax\Parser\ReplFragment;

use function Moggi\Backend\setCompileBackend;
use function Moggi\Syntax\Parser\parseReplFragment;

/**
 * Run the interactive loop.
 *
 * @param resource|null $input  defaults to STDIN
 */
function runLoop(State $state, $input = null, bool $quiet = false): int
{
    setCompileBackend($state->backend);
    $in = $input ?? STDIN;

    if (!$quiet) {
        \fwrite(STDOUT, "Moggi REPL ({$state->backend}). Type :help for commands, :quit to exit.\n");
    }

    $echoPrompt = stream_isatty($in);

    while (true) {
        $prompt = $state->buffer === '' ? '> ' : '| ';
        $line = readLineFrom($in, $prompt, $echoPrompt);
        if ($line === false) {
            if (!$quiet) {
                \fwrite(STDOUT, "\n");
            }
            break;
        }

        if ($state->buffer === '' && trim($line) === '') {
            continue;
        }

        if ($state->buffer === '' && str_starts_with(ltrim($line), ':')) {
            $state->history[] = $line;
            addReadlineHistory($line);
            $result = handleCommand($state, $line);
            if (($result['output'] ?? '') !== '') {
                \fwrite(STDOUT, $result['output']);
            }
            if ($result['quit'] ?? false) {
                return 0;
            }
            continue;
        }

        $state->buffer = $state->buffer === '' ? $line : ($state->buffer . "\n" . $line);
        $frag = parseReplFragment($state->buffer, '<interactive>');
        if ($frag->kind === ReplFragment::KIND_INCOMPLETE) {
            continue;
        }

        $source = $state->buffer;
        $state->buffer = '';
        $state->history[] = $source;
        addReadlineHistory($source);

        if ($frag->kind === ReplFragment::KIND_ERROR) {
            \fwrite(STDOUT, $frag->error?->display() ?? "parse error\n");
            continue;
        }

        \fwrite(STDOUT, handleFragment($state, $source, $frag));
    }

    return 0;
}

/**
 * Run a non-interactive script of REPL lines (for tests).
 */
function runScript(State $state, string $scriptPath): int
{
    $fh = fopen($scriptPath, 'r');
    if ($fh === false) {
        \fwrite(STDERR, "error: cannot read {$scriptPath}\n");

        return 1;
    }
    try {
        return runLoop($state, $fh, quiet: true);
    } finally {
        fclose($fh);
    }
}
