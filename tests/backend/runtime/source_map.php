<?php declare(strict_types=1);

/**
 * Each compiled module carries its own `__MOGGI_MAP`, keyed by the namespace
 * of its generated functions. Frames from modules without a map, unmapped
 * lines, or malformed maps must never resolve or crash reporting.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/backend/php/runtime.php';

use function Moggi\formatExceptionReport;
use function Moggi\lookupGeneratedFrame;
use function Moggi\lookupGeneratedFunction;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

\define('MapProbeA\__MOGGI_MAP', [
    [12 => ['Main.boom', 'App.mog', 3, 9], 40 => ['Main.late', 'App.mog', 7, 5]],
    ['MapProbeA\boom' => ['Main.boom', 'App.mog', 3, 9], 'MapProbeA\late' => ['Main.late', 'App.mog', 7, 5]],
]);
\define('MapProbeB\__MOGGI_MAP', []);
\define('MapProbeC\__MOGGI_MAP', 'not a map');
\define('__MOGGI_MAP', [[12 => ['Root.boom', 'Root.mog', 1, 2]], []]);

$hit = lookupGeneratedFrame(12, 'MapProbeA\boom');
$assert(\is_array($hit), 'frames: expected a mapped frame for MapProbeA\boom:12');
$assert(($hit['symbolId'] ?? null) === 'Main.boom', 'frames: wrong symbol id: ' . \json_encode($hit));
$assert(($hit['displayPath'] ?? null) === 'App.mog', 'frames: wrong display path: ' . \json_encode($hit));
$assert((int) ($hit['line'] ?? 0) === 3 && (int) ($hit['col'] ?? 0) === 9, 'frames: wrong location: ' . \json_encode($hit));

$late = lookupGeneratedFrame(40, 'MapProbeA\late');
$assert(($late['symbolId'] ?? null) === 'Main.late', 'frames: a later generated line must resolve: ' . \json_encode($late));

$fallback = lookupGeneratedFunction('MapProbeA\late');
$assert(($fallback['symbolId'] ?? null) === 'Main.late', 'functions: wrong declaration site: ' . \json_encode($fallback));
$assert(lookupGeneratedFunction('MapProbeA\unknown') === null, 'functions: an undeclared function must not resolve');

$global = lookupGeneratedFrame(12, 'boom');
$assert(($global['symbolId'] ?? null) === 'Root.boom', 'frames: a global-namespace module must resolve: ' . \json_encode($global));

$assert(lookupGeneratedFrame(999, 'MapProbeA\boom') === null, 'frames: an unmapped generated line must not resolve');
$assert(lookupGeneratedFrame(0, 'MapProbeA\boom') === null, 'frames: a zero line must not resolve');
$assert(lookupGeneratedFrame(-1, 'MapProbeA\boom') === null, 'frames: a negative line must not resolve');
$assert(lookupGeneratedFrame(12) === null, 'frames: a frame without a function name must not resolve');
$assert(lookupGeneratedFrame(12, 'NoMapProbe\boom') === null, 'frames: an unloaded module must not resolve');
$assert(lookupGeneratedFrame(12, 'MapProbeB\boom') === null, 'frames: an empty map must not resolve');
$assert(lookupGeneratedFrame(12, 'MapProbeC\boom') === null, 'frames: a malformed map must not resolve');
$assert(lookupGeneratedFrame(12, 'MapProbeD\boom') === null, 'frames: an unrelated module must not bind another module\'s line');

$site = \Moggi\throwSite(7, 'Main.boom', 'App.mog', 3, 9);
$exception = new \Moggi\MoggiException(
    \Moggi\exceptionWrap(\Moggi\EX_TAG_ERROR_CALL, 'boom', ['ErrorCall', 'boom']),
    null,
    $site,
);
$report = formatExceptionReport($exception);
$assert(\str_contains($report, 'App.mog:3:9'), "report: expected the stamped location, got:\n{$report}");
$assert(\str_contains($report, 'Main.boom'), "report: expected the symbol id, got:\n{$report}");

echo "source map tests passed\n";
