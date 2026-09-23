<?php

declare(strict_types=1);

/**
 * `runtest` / `test.php` command line. Selectors are paths or logical test names; backends are
 * repeatable and `all` expands to php → jvm → dotnet with one aggregate verdict.
 */

/**
 * The backends the suite knows about, in the order `--backend all` runs them (cheapest first).
 *
 * @return list<string>
 */
function knownTestBackends(): array
{
    return ['php', 'jvm', 'dotnet'];
}

/** @return array{
 *   backends: list<string>,
 *   backendsExplicit: bool,
 *   paths: list<string>,
 *   groups: list<string>,
 *   list: bool,
 *   json: bool,
 *   log: ?string,
 *   stopOnFailure: bool,
 *   jobs: int,
 *   progress: ?bool,
 *   native: bool,
 *   help: bool,
 *   errors: list<string>
 * } */
function parseTestArgs(array $argv): array
{
    $args = [
        'backends' => [],
        'backendsExplicit' => false,
        'paths' => [],
        'groups' => [],
        'list' => false,
        'json' => false,
        'log' => null,
        'stopOnFailure' => false,
        'jobs' => 0,
        // null = decide from the terminal; true/false = `--progress`/`--no-progress`.
        'progress' => null,
        'native' => false,
        // The `--jobs` value as given (0 = auto), kept so the run can report how the count was
        // arrived at even when a fallback changes how many workers actually run.
        'jobsRequested' => 0,
        'shard' => null,
        'help' => false,
        'errors' => [],
    ];

    /** The value of a flag that takes one (`--backend` and friends). */
    $takeValue = static function (string $flag) use ($argv, &$i): ?string {
        return $argv[$i + 1] ?? null;
    };

    for ($i = 1, $count = \count($argv); $i < $count; ++$i) {
        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h') {
            $args['help'] = true;
            return $args;
        }
        if ($arg === '--backend') {
            $value = $takeValue($arg);
            if ($value === null || $value === '') {
                $args['errors'][] = '--backend requires php, jvm, dotnet or all';
                continue;
            }
            ++$i;
            foreach (\explode(',', $value) as $one) {
                $one = \trim($one);
                if ($one === 'all') {
                    foreach (knownTestBackends() as $backend) {
                        if (!\in_array($backend, $args['backends'], true)) {
                            $args['backends'][] = $backend;
                        }
                    }
                    $args['backendsExplicit'] = true;
                    continue;
                }
                if (!\in_array($one, knownTestBackends(), true)) {
                    $args['errors'][] = "unknown backend `{$one}` (expected php, jvm, dotnet or all)";
                    continue;
                }
                if (!\in_array($one, $args['backends'], true)) {
                    $args['backends'][] = $one;
                }
            }
            $args['backendsExplicit'] = true;
            continue;
        }
        if ($arg === '--group' || \str_starts_with($arg, '--group=')) {
            $value = \str_starts_with($arg, '--group=') ? \substr($arg, 8) : $takeValue($arg);
            if (!\str_starts_with($arg, '--group=')) {
                ++$i;
            }
            if ($value === null || $value === '') {
                $args['errors'][] = '--group requires a group name';
                continue;
            }
            foreach (\explode(',', $value) as $one) {
                $one = \trim($one, " \t/");
                if ($one !== '') {
                    $args['groups'][] = $one;
                }
            }
            continue;
        }
        if ($arg === '--list') {
            $args['list'] = true;
            continue;
        }
        if ($arg === '--json') {
            $args['json'] = true;
            continue;
        }
        if ($arg === '--native') {
            $args['native'] = true;
            continue;
        }
        if ($arg === '--progress') {
            $args['progress'] = true;
            continue;
        }
        if ($arg === '--no-progress') {
            $args['progress'] = false;
            continue;
        }
        if ($arg === '--log') {
            $value = $takeValue($arg);
            ++$i;
            if ($value === null || $value === '') {
                $args['errors'][] = '--log requires a path';
                continue;
            }
            $args['log'] = $value;
            continue;
        }
        if ($arg === '--stop-on-failure') {
            $args['stopOnFailure'] = true;
            continue;
        }
        if ($arg === '--jobs' || $arg === '-j') {
            $value = $takeValue($arg);
            ++$i;
            if ($value === null || !\ctype_digit($value) || (int) $value < 1) {
                $args['errors'][] = '--jobs requires a positive integer';
                continue;
            }
            $args['jobs'] = (int) $value;
            continue;
        }
        if ($arg === '--shard') {
            // Internal: `test.php --shard I/N` runs one shard of the selection and prints
            // JSON only. Used by `--jobs N`; not part of the public CLI.
            $value = $takeValue($arg);
            ++$i;
            if ($value === null || \preg_match('#^(\d+)/(\d+)$#', $value, $m) !== 1
                || (int) $m[1] >= (int) $m[2] || (int) $m[2] < 1) {
                $args['errors'][] = '--shard requires I/N with 0 <= I < N';
                continue;
            }
            $args['shard'] = [(int) $m[1], (int) $m[2]];
            continue;
        }
        if (\str_starts_with($arg, '-') && $arg !== '-') {
            $args['errors'][] = "unknown option `{$arg}`";
            continue;
        }

        $args['paths'][] = $arg;
    }

    return $args;
}

function testUsage(): string
{
    return <<<'TXT'
usage: runtest [options] [selector...]

Backends
  --backend php|jvm|dotnet   repeatable; `all` runs php, then jvm, then dotnet with one
                             aggregate verdict and exit 1 if any backend failed
                             (default: php)

Modes
  --native                   also build and run the native-executable smoke: GraalVM
                             native-image on jvm, .NET Native AOT on dotnet. Slow. Skipped
                             when the toolchain is not installed; needs one of those two
                             backends to be selected, since php has no native toolchain.

Selection
  --group <name>             group or a subject path below one, repeatable
                             (e.g. semantics, syntax/parser, backend/runtime)
  <path|name>...             test path (file or directory subtree) or logical test name

Failure handling
  --stop-on-failure          stop at the first failure (default: keep going)

Progress
  --progress | --no-progress the block that reports each group as it runs; with it on, the
                             run ends with that block, and the failures or the success line
                             print under it. With it off, every case is named as it finishes
                             instead. Default: on when stderr is a terminal.

Output
  --list                       discover the selected tests, print their case names grouped
                               by directory, and exit without running them
  --json                       machine-readable report (`--list --json` prints the same
                               case names, plus group/files/backends, as JSON)
  --log <path>                 also write the human report to a file
  --jobs N / -j N              run N worker processes (default: one per physical CPU when the
                               selection gives each worker more than one case; 1 below that)

Every case that runs is named in the report; the recap re-lists everything that did not pass,
with its full diagnostic. Nothing is truncated.

Examples
  runtest
  runtest --backend all --jobs 4
  runtest --group semantics
  runtest --backend php syntax/parser/Backend-Map
  runtest --list --json

TXT;
}
