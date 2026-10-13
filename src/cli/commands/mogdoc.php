<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Backend;
use Moggi\Cache;
use Moggi\Docs;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Cache\docsCacheDir;
use function Moggi\CLI\defaultDocsInput;
use function Moggi\CLI\printUsage;

/**
 * `moggi mogdoc [serve]` — render API documentation, or serve it live.
 *
 * `serve` is a verb rather than a flag because it changes what the command *is*:
 * generate writes a static tree and exits, while serve renders once and then
 * answers HTTP until interrupted. Both read the same sources, so they declare the
 * same options.
 */
function mogdocUsage(): string
{
    return <<<HELP
    usage:
      moggi mogdoc <input-dir> -o <output-dir> [options]
      moggi mogdoc serve [--port N] [-o DIR] [options]

    Generate HTML documentation for the modules the sources reach, or serve it live.

    options:
      -o PATH          generate: where to write the tree (required);
                       serve: the tree to serve (default: the docs cache)
      --root PATH      the directory (or file) to document (default: the stdlib)
      --lib PATH       extra module search root. Repeatable.
      --port N         serve: the port to listen on (default: 8080)
      --rebuild        re-render even when the cached tree is current
      --no-cache       bypass the on-disk cache for this run
      -h, --help       show this help
    HELP;
}

/** @param list<string> $argv */
function runMogdoc(array $argv): int
{
    $serve = ($argv[2] ?? '') === 'serve';
    $start = $serve ? 3 : 2;

    $spec = new CommandSpec('mogdoc', mogdocUsage(), [
        ['name' => 'root', 'value' => true],
        ['name' => 'lib', 'value' => true, 'repeat' => true],
        ['name' => 'rebuild'],
        ['name' => 'noCache'],
        ['name' => 'port', 'value' => true],
    ], positionals: 1);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec, $start);
        requireDirectories($options['lib'], '--lib');
        if ($options['root'] !== null && !\is_dir($options['root']) && !\is_file($options['root'])) {
            throw new \InvalidArgumentException('--root requires a file or directory');
        }
        $port = 8080;
        if ($options['port'] !== null) {
            if (\preg_match('/^\d+$/', $options['port']) !== 1
                || (int) $options['port'] < 1 || (int) $options['port'] > 65535) {
                throw new \InvalidArgumentException('--port requires a port number between 1 and 65535');
            }
            $port = (int) $options['port'];
        }
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $output = $options['output'];
    $input = $options['root'] ?? ($options['positionals'][0] ?? null);

    try {
        Backend\setCompileBackend('php');
        if ($options['noCache']) {
            Cache\setCacheEnabled(false);
        }
        $input ??= defaultDocsInput();
        $sourceFingerprint = '';
        $index = Docs\loadOrBuildIndex(
            $input,
            $options['lib'],
            $options['rebuild'],
            $sourceFingerprint,
        );
        $siteTitle = Docs\docSiteTitle($input);

        if ($serve) {
            $docDir = $output;
            if ($docDir === null) {
                $docDir = docsCacheDir();
            }
            $docDir = rtrim($docDir, '/');
            $fingerprintPath = $docDir . '/.mogdoc-source-fingerprint';
            $needsGenerate = $options['rebuild']
                || !\is_file($docDir . '/index.html')
                || !\is_file($fingerprintPath)
                || trim((string) file_get_contents($fingerprintPath)) !== $sourceFingerprint;
            if ($needsGenerate) {
                if (!\is_dir($docDir) && !mkdir($docDir, 0777, true) && !\is_dir($docDir)) {
                    \fwrite(STDERR, "error: cannot create {$docDir}\n");

                    return 1;
                }
                @unlink($fingerprintPath);
                Docs\generateMogdoc($index, $docDir, $siteTitle);
                file_put_contents($fingerprintPath, $sourceFingerprint, LOCK_EX);
                if ($output === null) {
                    \fwrite(STDERR, "mogdoc: generated static site at {$docDir}\n");
                }
            }

            return Docs\serveDocs($index, $port, $docDir, $siteTitle);
        }

        if ($output === null) {
            \fwrite(STDERR, "error: mogdoc requires <input-dir> and -o <output-dir>\n\n");
            printUsage();

            return 1;
        }

        Docs\generateMogdoc($index, $output, $siteTitle);
        echo 'mogdoc: wrote ' . count($index->byModule) . " module(s) to {$output}\n";

        return 0;
    } catch (LexError|ParseError $e) {
        \fwrite(STDERR, $e->display());

        return 1;
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        return 1;
    }
}
