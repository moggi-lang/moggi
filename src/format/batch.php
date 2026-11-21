<?php declare(strict_types=1);

namespace Moggi\Format;

use parallel\Runtime;
use Throwable;

use function Moggi\Platform\detectedCpuCount;
use function Moggi\Platform\workerLimitForMemory;

/** Memory a formatter thread is budgeted, so an automatic count cannot exhaust the machine. */
const WORKER_MEMORY_BYTES = 512 * 1024 * 1024;

/**
 * How many worker threads a selection should use, or 1 when spreading would cost more than it saves.
 *
 * The count is one per physical core, clipped by the cgroup and free memory, and only when every
 * worker would get more than one file. An explicit count is the caller's decision, capped by the
 * number of files. A machine without ext-parallel always answers 1.
 */
function formatWorkerCount(int $files, ?int $requested = null): int
{
    if ($files < 2 || ! \class_exists(Runtime::class)) {
        return 1;
    }
    if ($requested !== null) {
        return \max(1, \min($requested, $files));
    }

    $workers = \max(1, \min(detectedCpuCount(), $files));
    $affordable = workerLimitForMemory(WORKER_MEMORY_BYTES);
    if ($affordable !== null) {
        $workers = \min($workers, $affordable);
    }

    return $files < 2 * $workers ? 1 : $workers;
}

/**
 * Format a selection of files, in threads when there is more than one worker.
 *
 * A result is `formatted` or a rendered `error`; the caller decides what to write and report. Files
 * are read in the worker that formats them, and the parent returns results in selection order, so
 * neither output nor status depends on which worker finished first. A dead worker fails only its
 * own files. The imported fixity is resolved once for the whole selection, before the workers
 * start, so every file is parsed with the same module graph.
 *
 * @param list<string> $files
 * @return array{workers: int, results: array<string, array{formatted: ?string, error: ?string}>, note: ?string}
 */
function formatFileBatch(array $files, ?int $requested = null): array
{
    $importedFixity = formatImportedFixityByFile($files);
    $workers = formatWorkerCount(\count($files), $requested);
    if ($workers <= 1) {
        return ['workers' => 1, 'results' => formatFilesSerially($files, $importedFixity), 'note' => null];
    }

    $sizes = [];
    foreach ($files as $file) {
        $sizes[$file] = (int) \filesize($file);
    }
    $ordered = $files;
    \usort($ordered, static fn (string $a, string $b): int => $sizes[$b] <=> $sizes[$a]);
    $chunks = \array_fill(0, $workers, []);
    $loads = \array_fill(0, $workers, 0);
    foreach ($ordered as $file) {
        $best = 0;
        for ($index = 1; $index < $workers; ++$index) {
            if ($loads[$index] < $loads[$best]) {
                $best = $index;
            }
        }
        $chunks[$best][] = $file;
        $loads[$best] += $sizes[$file];
    }
    $chunks = \array_values($chunks);
    $root = \dirname(__DIR__, 2);

    $runtimes = [];
    try {
        foreach ($chunks as $chunk) {
            $runtimes[] = new Runtime();
        }
    } catch (Throwable $error) {
        return [
            'workers' => 1,
            'results' => formatFilesSerially($files, $importedFixity),
            'note' => 'cannot start ext-parallel (' . $error->getMessage()
                . '), so the files are formatted one at a time',
        ];
    }

    $futures = [];
    foreach ($runtimes as $index => $runtime) {
        $fixityForChunk = [];
        foreach ($chunks[$index] as $file) {
            $fixityForChunk[$file] = $importedFixity[$file] ?? [];
        }
        $futures[$index] = $runtime->run(static function (array $chunk, string $root, array $importedFixity): array {
            require_once $root . '/src/compiler.php';

            $results = [];
            foreach ($chunk as $file) {
                $results[$file] = formatOneFile($file, $importedFixity[$file] ?? []);
            }

            return $results;
        }, [$chunks[$index], $root, $fixityForChunk]);
    }

    $results = [];
    foreach ($futures as $index => $future) {
        try {
            foreach ($future->value() as $file => $result) {
                $results[$file] = $result;
            }
        } catch (Throwable $error) {
            foreach ($chunks[$index] as $file) {
                $results[$file] = ['formatted' => null, 'error' => 'worker failed: ' . $error->getMessage() . "\n"];
            }
        }
    }

    $orderedResults = [];
    foreach ($files as $file) {
        $orderedResults[$file] = $results[$file] ?? ['formatted' => null, 'error' => "no result from the worker\n"];
    }

    return ['workers' => $workers, 'results' => $orderedResults, 'note' => null];
}

/**
 * @param array<string, array{assoc: string, prec: int}> $importedFixity
 * @return array{formatted: ?string, error: ?string}
 */
function formatOneFile(string $file, array $importedFixity = []): array
{
    $source = @\file_get_contents($file);
    if ($source === false) {
        return ['formatted' => null, 'error' => 'cannot read ' . $file . "\n"];
    }

    try {
        return ['formatted' => formatSource($source, $file, false, $importedFixity), 'error' => null];
    } catch (Throwable $error) {
        return [
            'formatted' => null,
            'error' => $error instanceof FormatError
                ? $error->display()
                : \get_class($error) . ': ' . $error->getMessage() . "\n",
        ];
    }
}

/**
 * @param list<string> $files
 * @param array<string, array<string, array{assoc: string, prec: int}>> $importedFixity
 * @return array<string, array{formatted: ?string, error: ?string}>
 */
function formatFilesSerially(array $files, array $importedFixity = []): array
{
    $results = [];
    foreach ($files as $file) {
        $results[$file] = formatOneFile($file, $importedFixity[$file] ?? []);
    }

    return $results;
}
