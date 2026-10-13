<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Backend;
use Moggi\Cache;
use Moggi\Docs;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\CLI\defaultDocsInput;
use function Moggi\CLI\printUsage;

/**
 * `moggi moogle` — search the API by name or type.
 *
 * The query is the one positional, and `--` takes the rest of the line verbatim,
 * so a query with spaces or leading dashes needs no quoting (`moogle -- "a -> a"`).
 * With no query on the line, a pipe is read instead.
 */
function moogleUsage(): string
{
    return <<<HELP
    usage:
      moggi moogle <query> [options]
      moggi moogle -- <query with spaces>

    Search the API by name or type. With no query argument, the query is read from
    stdin, so it can be piped.

    options:
      --root PATH      the tree to search (default: the stdlib)
      --lib PATH       extra module search root. Repeatable.
      --json           machine-readable output
      --rebuild        rebuild the index instead of using the cached one
      --no-cache       bypass the on-disk cache for this run
      -h, --help       show this help
    HELP;
}

/** @param list<string> $argv */
function runMoogle(array $argv): int
{
    $spec = new CommandSpec('moogle', moogleUsage(), [
        ['name' => 'root', 'value' => true],
        ['name' => 'lib', 'value' => true, 'repeat' => true],
        ['name' => 'rebuild'],
        ['name' => 'noCache'],
        ['name' => 'json'],
    ], positionals: 1, passthrough: true);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec);
        requireDirectories($options['lib'], '--lib');
        if ($options['root'] !== null && !\is_dir($options['root']) && !\is_file($options['root'])) {
            throw new \InvalidArgumentException('--root requires a file or directory');
        }
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $query = $options['rest'] !== []
        ? trim(implode(' ', $options['rest']))
        : trim((string) ($options['positionals'][0] ?? ''));
    if ($query === '' && !stream_isatty(STDIN)) {
        $query = trim((string) stream_get_contents(STDIN));
    }

    try {
        Backend\setCompileBackend('php');
        if ($options['noCache']) {
            Cache\setCacheEnabled(false);
        }
        $index = Docs\loadOrBuildIndex(
            $options['root'] ?? defaultDocsInput(),
            $options['lib'],
            $options['rebuild'],
        );

        if ($query === '') {
            \fwrite(STDERR, "error: moogle requires a query (or pipe one on stdin)\n\n");
            printUsage();

            return 1;
        }

        $hits = Docs\search($index, $query, 20);
        echo Docs\formatSearchResults($hits, $options['json']);

        return 0;
    } catch (LexError|ParseError $e) {
        \fwrite(STDERR, $e->display());

        return 1;
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        return 1;
    }
}
