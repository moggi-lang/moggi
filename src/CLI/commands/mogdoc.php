<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Backend;
use Moggi\Cache;
use Moggi\CLI\ArgCursor;
use Moggi\Docs;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\CLI\defaultDocsInput;
use function Moggi\CLI\parseDocsToolOptions;
use function Moggi\CLI\printUsage;
use function Moggi\CLI\takeDocsPort;
use function Moggi\CLI\takeDocsSharedFlag;
use function Moggi\CLI\unknownDocsOption;

function parseMogdocArgs(array $argv): array
{
    $sub = 'generate';
    $input = null;
    $output = null;
    $port = 8080;
    $start = 2;

    if (($argv[2] ?? '') === 'serve') {
        $sub = 'serve';
        $start = 3;
        $input = \Moggi\CLI\defaultDocsInput();
    }

    $cursor = new ArgCursor($argv, $start);
    $shared = parseDocsToolOptions($cursor, $input ?? '');
    if ($shared['inputPath'] !== '') {
        $input = $shared['inputPath'];
    }

    while (($arg = $cursor->current()) !== null) {
        if (takeDocsSharedFlag($cursor, $shared)) {
            if ($shared['inputPath'] !== '') {
                $input = $shared['inputPath'];
            }
            continue;
        }
        if ($arg === '--port') {
            $port = takeDocsPort($cursor);
            continue;
        }
        if ($arg === '-o') {
            $output = $cursor->takeValue('-o');
            continue;
        }
        if (str_starts_with($arg, '-')) {
            unknownDocsOption('mogdoc', $arg);
        }
        if ($input === null) {
            $input = $cursor->take();
            continue;
        }
        \fwrite(STDERR, "error: unexpected argument {$arg}\n\n");
        printUsage();
        exit(1);
    }

    return [
        'sub' => $sub,
        'input' => $input,
        'output' => $output,
        'libDirs' => $shared['libDirs'],
        'rebuild' => $shared['rebuild'],
        'noCache' => $shared['noCache'],
        'port' => $port,
    ];
}

function runMogdoc(array $argv): int
{
    $parsed = parseMogdocArgs($argv);

    try {
        Backend\setCompileBackend('php');
        if ($parsed['noCache']) {
            Cache\setCacheEnabled(false);
        }
        $input = $parsed['input'] ?? defaultDocsInput();
        $sourceFingerprint = '';
        $index = Docs\loadOrBuildIndex(
            $input,
            $parsed['libDirs'],
            $parsed['rebuild'],
            $sourceFingerprint,
        );

        if ($parsed['sub'] === 'serve') {
            $docDir = $parsed['output'];
            if ($docDir === null) {
                $docDir = rtrim(getenv('MOGGI_CACHE_DIR') ?: (getcwd() ?: '.') . '/.moggi', '/')
                    . '/mogdoc-serve';
            }
            $docDir = rtrim($docDir, '/');
            $fingerprintPath = $docDir . '/.mogdoc-source-fingerprint';
            $needsGenerate = $parsed['rebuild']
                || !\is_file($docDir . '/index.html')
                || !\is_file($fingerprintPath)
                || trim((string) file_get_contents($fingerprintPath)) !== $sourceFingerprint;
            if ($needsGenerate) {
                if (!\is_dir($docDir) && !mkdir($docDir, 0777, true) && !\is_dir($docDir)) {
                    \fwrite(STDERR, "error: cannot create {$docDir}\n");
                    return 1;
                }
                @unlink($fingerprintPath);
                Docs\generateMogdoc($index, $docDir);
                file_put_contents($fingerprintPath, $sourceFingerprint, LOCK_EX);
                if ($parsed['output'] === null) {
                    \fwrite(STDERR, "mogdoc: generated static site at {$docDir}\n");
                }
            }

            return Docs\serveDocs($index, $parsed['port'], $docDir);
        }

        if ($parsed['output'] === null) {
            \fwrite(STDERR, "error: mogdoc requires <input-dir> and -o <output-dir>\n\n");
            printUsage();

            return 1;
        }

        Docs\generateMogdoc($index, $parsed['output']);
        echo 'mogdoc: wrote ' . count($index->byModule) . " module(s) to {$parsed['output']}\n";

        return 0;
    } catch (LexError|ParseError $e) {
        \fwrite(STDERR, $e->display());

        return 1;
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        return 1;
    }
}

function runMogdocCommand(array $argv): int
{
    return runMogdoc($argv);
}
