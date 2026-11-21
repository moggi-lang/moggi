<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use InvalidArgumentException;
use RuntimeException;

use function Moggi\Format\formatFileBatch;
use function Moggi\Modules\findMogFilesUnder;

/**
 * `moggi format` — the canonical layout, written in place.
 *
 * One file, or every `.mog` under a directory. A file the formatter refuses (a comment it cannot
 * place, a construct the printer has not reached) is left exactly as it is: a formatter that wrote
 * the file minus the piece it did not understand would be worse than one that wrote nothing.
 *
 * `--dry-run` writes nothing and reports what would change, so it can gate a commit. It exits 1 when
 * something would change, which is what a `--check` in CI wants.
 *
 * A directory formats in worker threads by default; `--jobs N` says how many, and `--jobs 1` keeps
 * the run in this process. Each file is one lex and one parse, so the work is CPU-bound and
 * independent. The automatic count is the suite's: physical cores clamped by the cgroup and free
 * memory, and a selection too small to keep every worker busy stays in this process.
 */
function formatUsage(): string
{
    return <<<HELP
    usage:
      moggi format <source.mog|dir> [--dry-run | --check] [--jobs [N]]

    Rewrite a Moggi source in the canonical layout. A directory formats every `.mog`
    under it.

    A file that does not parse, or that carries a comment the printer cannot place, is
    reported and left untouched — nothing is ever written with a piece missing.

    options:
      --dry-run            report the files that would change, write nothing
      --check              report every file that is not already canonical
      --jobs [N]           format N files at once in ext-parallel worker threads
                           (default: as many as the physical cores and free memory
                           allow, and 1 for a selection too small to spread). Without
                           ext-parallel the files are formatted one at a time
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runFormatCommand(array $argv): int
{
    $spec = new CommandSpec('format', formatUsage(), [
        ['name' => 'dryRun'],
        ['name' => 'check'],
        ['name' => 'jobs', 'optionalValue' => true],
    ]);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec);
    } catch (InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $path = (string) $options['path'];
    $files = formatTargets($path);
    if ($files === []) {
        \fwrite(STDERR, "error: no .mog files at {$path}\n");

        return 1;
    }

    $requested = \is_string($options['jobs']) ? \max(1, (int) $options['jobs']) : null;
    $batch = formatFileBatch($files, $requested);
    if ($batch['note'] !== null) {
        \fwrite(STDERR, 'note: ' . $batch['note'] . "\n");
    }

    $wouldChange = 0;
    $failed = 0;
    foreach ($files as $file) {
        $result = $batch['results'][$file] ?? ['formatted' => null, 'error' => "no result from the worker\n"];
        if ($result['error'] !== null) {
            \fwrite(STDERR, (string) $result['error']);
            ++$failed;

            continue;
        }

        reportFormattedFile($file, (string) $result['formatted'], $options, $wouldChange);
    }

    if ($failed > 0) {
        return 1;
    }

    return $wouldChange > 0 ? 1 : 0;
}

/** Report a file that came back formatted, writing it unless the run is a dry run. */
function reportFormattedFile(string $file, string $formatted, array $options, int &$wouldChange): void
{
    if ($formatted === (string) \file_get_contents($file)) {
        return;
    }

    if ($options['dryRun'] || $options['check']) {
        \fwrite(STDERR, $options['check'] ? "not formatted: {$file}\n" : "would format {$file}\n");
        ++$wouldChange;

        return;
    }

    writeFormattedFile($file, $formatted);
    \fwrite(STDERR, "formatted {$file}\n");
}

/** @return list<string> */
function formatTargets(string $path): array
{
    if (\is_dir($path)) {
        return findMogFilesUnder($path);
    }
    if (\is_file($path)) {
        return [$path];
    }

    return [];
}

/**
 * Replace a file's contents without a moment where it is half written.
 *
 * A formatter runs on save, so the file is being read by editors and tools while it is rewritten: a
 * temp file in the same directory and a rename makes the new content appear whole or not at all.
 */
function writeFormattedFile(string $file, string $contents): void
{
    $directory = \dirname($file);
    $temp = \tempnam($directory, '.moggi-format-');
    if ($temp === false) {
        throw new RuntimeException("cannot write beside {$file}");
    }

    if (\file_put_contents($temp, $contents) === false || ! \rename($temp, $file)) {
        @\unlink($temp);

        throw new RuntimeException("cannot replace {$file}");
    }

    @\chmod($file, 0644 & ~\umask());
}
