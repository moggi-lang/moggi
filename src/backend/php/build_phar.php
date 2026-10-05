<?php declare(strict_types=1);

namespace Moggi\Backend\Php;

use function Moggi\Backend\setCompileBackend;

/**
 * Standalone PHAR builder for the PHP backend.
 *
 * The moggi process usually runs with `phar.readonly=On` (the PHP default),
 * which forbids creating PHARs in-process. packagePhar() then has a second
 * process *require* this file with `php -d phar.readonly=0` to write the
 * artifact — required, never named as a script, since PHP's CLI cannot open a
 * `phar://` argument and a distribution runs the compiler from inside one:
 *
 *   php -d phar.readonly=0 -r 'require <this file>;' -- <outputRoot> <pharPath>
 *
 * It reconstructs the backend object (runtime files, stub template) and
 * performs the same build writePhar() would do in-process.
 */

require dirname(__DIR__, 3) . '/src/compiler.php';

if ($argc !== 3 && $argc !== 4) {
    \fwrite(STDERR, "usage: php build_phar.php <output-root> <phar-path> [entry-relative]\n");

    exit(1);
}

[, $outputRoot, $pharPath] = $argv;
// The entry module's path inside the archive, when the caller knows it; the
// stub scans for it otherwise. The compiler always passes it, which keeps a
// bundled `vendor/` from being read on every startup.
$entryRelative = $argv[3] ?? '';

if (!\is_dir($outputRoot)) {
    \fwrite(STDERR, "error: output directory does not exist: {$outputRoot}\n");

    exit(1);
}

try {
    setCompileBackend('php');
    writePhar($outputRoot, $pharPath, ['entryRelative' => $entryRelative]);
} catch (\Throwable $e) {
    \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

    exit(1);
}
