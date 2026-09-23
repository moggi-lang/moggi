<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Backend;
use Moggi\Cache;
use Moggi\CLI\ArgCursor;
use Moggi\Docs;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\CLI\parseDocsToolOptions;
use function Moggi\CLI\printUsage;
use function Moggi\CLI\takeDocsSharedFlag;
use function Moggi\CLI\unknownDocsOption;

function parseMoogleArgs(array $argv): array
{
    $query = '';
    $start = 2;

    if (isset($argv[2]) && !str_starts_with($argv[2], '-')) {
        $query = $argv[2];
        $start = 3;
    }

    $cursor = new ArgCursor($argv, $start);
    $shared = parseDocsToolOptions($cursor);
    $json = false;

    while (($arg = $cursor->current()) !== null) {
        if ($arg === '--') {
            $cursor->take();
            $query = trim(implode(' ', \array_slice($argv, $cursor->index)));
            break;
        }
        if (takeDocsSharedFlag($cursor, $shared)) {
            continue;
        }
        if ($arg === '--json') {
            $cursor->take();
            $json = true;
            continue;
        }
        if (str_starts_with($arg, '-')) {
            unknownDocsOption('moogle', $arg);
        }
        if ($query === '') {
            $query = $cursor->take() ?? '';
            continue;
        }
        \fwrite(STDERR, "error: unexpected argument {$arg}\n\n");
        printUsage();
        exit(1);
    }

    if ($query === '' && !stream_isatty(STDIN)) {
        $query = trim((string) stream_get_contents(STDIN));
    }

    return [
        'query' => $query,
        'libDirs' => $shared['libDirs'],
        'json' => $json,
        'rebuild' => $shared['rebuild'],
        'noCache' => $shared['noCache'],
        'inputPath' => $shared['inputPath'],
    ];
}

function runMoogle(array $argv): int
{
    $parsed = parseMoogleArgs($argv);
    $inputPath = $parsed['inputPath'];

    try {
        Backend\setCompileBackend('php');
        if ($parsed['noCache']) {
            Cache\setCacheEnabled(false);
        }
        $index = Docs\loadOrBuildIndex(
            $inputPath,
            $parsed['libDirs'],
            $parsed['rebuild'],
        );

        if ($parsed['query'] === '') {
            \fwrite(STDERR, "error: moogle requires a query (or pipe one on stdin)\n\n");
            printUsage();

            return 1;
        }

        $hits = Docs\search($index, $parsed['query'], 20);
        echo Docs\formatSearchResults($hits, $parsed['json']);

        return 0;
    } catch (LexError|ParseError $e) {
        \fwrite(STDERR, $e->display());

        return 1;
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        return 1;
    }
}

function runMoogleCommand(array $argv): int
{
    return runMoogle($argv);
}
