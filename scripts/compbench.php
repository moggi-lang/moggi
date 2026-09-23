#!/usr/bin/env php
<?php declare(strict_types=1);

// Focused compiler micro-benchmark (CPU-time, load/thermal-invariant).
putenv('MOGGI_NO_CACHE=1');
$_ENV['MOGGI_NO_CACHE'] = '1';

require __DIR__ . '/../src/compiler.php';

use function Moggi\Modules\prepareProjectCached;
use function Moggi\Modules\compilePreparedProject;
use function Moggi\Modules\clearPrepareProjectCaches;

\Moggi\Cache\setCacheEnabled(false);
\Moggi\Backend\setCompileBackend('php');

$root = __DIR__ . '/../lib';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'mog') {
        $files[] = $f->getPathname();
    }
}
sort($files);

$iters = (int) ($argv[1] ?? 8);

$cpu = static function (): float {
    $r = getrusage();
    return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6;
};

// Warm one run (autoload / opcache).
$warm = prepareProjectCached($files, $root);
compilePreparedProject($warm);
clearPrepareProjectCaches();
$GLOBALS['checkedModuleCache'] = [];

$times = [];
$prepTimes = [];
$restTimes = [];
for ($i = 0; $i < $iters; ++$i) {
    clearPrepareProjectCaches();
    $GLOBALS['checkedModuleCache'] = [];
    $start = $cpu();
    $prepared = prepareProjectCached($files, $root);
    $prepEnd = $cpu();
    compilePreparedProject($prepared);
    $end = $cpu();
    $times[] = ($end - $start) * 1000;
    $prepTimes[] = ($prepEnd - $start) * 1000;
    $restTimes[] = ($end - $prepEnd) * 1000;
}

$fmt = static function (array $t): string {
    sort($t);
    return \sprintf('min=%.1f median=%.1f mean=%.1f', $t[0], $t[intdiv(count($t), 2)], array_sum($t) / count($t));
};
\printf("compile lib x%d (no-cache, CPU-time):\n", $iters);
\printf("  total:    %s ms\n", $fmt($times));
\printf("  prepare:  %s ms (parse + typecheck)\n", $fmt($prepTimes));
\printf("  optcodegn:%s ms (lower + optimize + codegen)\n", $fmt($restTimes));
\printf("  peakMem:  %.1f MB\n", memory_get_peak_usage(true) / 1048576);
