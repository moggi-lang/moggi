<?php declare(strict_types=1);

/**
 * The optimizer must not change what a program does.
 *
 * Compiles one source twice through the compiler's own single-file surface — once the way the
 * compiler ships (optimized) and once with `--no-opt` — runs both, and requires the same observable
 * program: exit status, stdout and stderr. Nothing here reads an internal (no IR, no pass trace, no
 * `--opt-ir`): the comparison is what the program does, which is the only thing the optimizer is
 * allowed to leave alone.
 *
 * It runs by default and needs no switch. The two modes differ only in the passes between lowering
 * and emission, so a divergence is a pass that changed behaviour or the emitter feeding on one.
 *
 * The corpus is chosen per construct, not per count: one program for each optimizer family that
 * rewrites a program's shape — application collapse, lambda lifting and CAFs, specialization,
 * dictionaries, method dictionaries, local dictionary groups, IO-box liveness, the IO spine, mutual
 * groups, guards and case folding, records under constraints, TCO, intrinsic folding, constant folding
 * and shadowing (which is about identity, not rewrite, and is the one that would catch a pass that
 * resolves by spelling). Adding a program here is how a new family gets covered; the cost is one extra
 * pair of compiles, not another run of a group.
 *
 * **The named baseline, and it is the only one.** `Exec-Exception-HostFrame` is a report fixture:
 * its stderr names a stack frame inside the *compiled PHP artifact*, and a line number there moves
 * when a pass drops statements. Those artifact lines are dropped from both sides for that program
 * and everything else in its report — the Mogg frames, the message, the exit status — is still
 * compared. A second entry here would be a divergence someone stopped looking at, so the gate
 * refuses to grow: the baseline is a map with one key and the case asserts that.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
\define('MOGGI_PROJECT_ROOT', $root);
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/bootstrap.php';

\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');
foreach (testLibraryRoots($root) as $libraryRoot) {
    \Moggi\Modules\addLibraryRoot($libraryRoot);
}

/** The programs, one per construct the optimizer rewrites. */
const OPT_DIFFERENTIAL_PROGRAMS = [
    'backend/runtime/Exec-Apply-Collapse.mog' => 'application collapse',
    'backend/runtime/Exec-Compose-Named-CAF.mog' => 'lambda lifting and CAFs',
    'backend/runtime/Specialize-Bool-Const.mog' => 'specialization',
    'backend/runtime/Exec-Generic.mog' => 'dictionaries',
    'backend/runtime/Exec-Class-Method-Dict-Arity.mog' => 'method dictionaries',
    'backend/runtime/Exec-Local-Dict-Matrix.mog' => 'local dictionary groups',
    'backend/runtime/Exec-Io-Box-Liveness.mog' => 'IO-box liveness',
    'backend/runtime/Exec-Do-Nested-Bind.mog' => 'the IO spine',
    'backend/runtime/Exec-Mutual-Group.mog' => 'mutual groups',
    'backend/runtime/Exec-Guard.mog' => 'guards and case folding',
    'backend/runtime/Exec-Record-Constrained-Field.mog' => 'records under constraints',
    'backend/runtime/Exec-Tco-TailRecall-Argument.mog' => 'TCO',
    'backend/runtime/Exec-Pow-Widths.mog' => 'intrinsic folding',
    'optimize/opt-differential-folds.mog' => 'constant folding',
    'backend/runtime/Exec-Shadowing.mog' => 'shadowing and identity',
    'backend/runtime/Exec-Exception-HostFrame.mog' => 'a report whose host frame moves',
];

/**
 * The one program whose stderr legitimately differs between modes, by name, with its reason.
 *
 * `host` drops the lines that name a line inside the compiled artifact; the rest of the report is
 * compared like any other program's.
 */
const OPT_DIFFERENTIAL_BASELINE = [
    'Exec-Exception-HostFrame' => 'host',
];

// The baseline is a named exception, not a place divergences accumulate, and it is checked before
// anything is compiled so a second entry cannot be added and quietly honoured.
if (\count(OPT_DIFFERENTIAL_BASELINE) !== 1) {
    \fwrite(\STDERR, "the mode baseline must name exactly one program, it names "
        . \count(OPT_DIFFERENTIAL_BASELINE) . "\n");
    exit(1);
}

$failures = [];
$compiled = 0;

foreach (OPT_DIFFERENTIAL_PROGRAMS as $relative => $construct) {
    $path = $root . '/tests/' . $relative;
    $name = \basename($relative, '.mog');
    if (! \is_file($path)) {
        $failures[] = "{$name} ({$construct}): missing {$relative}";
        continue;
    }

    $observations = [];
    foreach (['optimized' => true, 'unoptimized' => false] as $mode => $optimize) {
        $result = \Moggi\Compiler\compileFileCapturingErrors($path, 'php', $optimize, 'php');
        if ($result['exitCode'] !== 0) {
            $failures[] = "{$name} ({$construct}): the {$mode} compile failed\n" . $result['output'];
            continue 2;
        }

        // The entry keeps the source's depth: the emitted file requires the runtime and the
        // library tree by the path it was emitted for (`../../../_runtime.php`), the same way the
        // runtime runner's own single-file compile writes it.
        $relativeEntry = \preg_replace('/\.mog$/', '.' . $mode . '.php', testRelativePath($path))
            ?? ($name . '.' . $mode . '.php');
        $entry = execTreeRoot($root) . '/' . $relativeEntry;
        @\mkdir(\dirname($entry), 0777, true);
        if (\file_put_contents($entry, $result['output']) === false) {
            $failures[] = "{$name} ({$construct}): cannot write {$entry}";
            continue 2;
        }

        $run = runCompiledProcess([PHP_BINARY, $entry], 60, stdin: fixtureStdin($path));
        if (($run['timedOut'] ?? false) === true) {
            $failures[] = "{$name} ({$construct}): the {$mode} program timed out";
            continue 2;
        }

        $observations[$mode] = [
            'exit' => (int) ($run['exitCode'] ?? -1),
            'stdout' => (string) ($run['stdout'] ?? ''),
            'stderr' => (string) ($run['stderr'] ?? ''),
        ];
        @\unlink($entry);
        $compiled++;
    }

    $baseline = OPT_DIFFERENTIAL_BASELINE[$name] ?? null;
    $baselineStream = static function (string $stream, string $text) use ($baseline): string {
        if ($baseline === 'host' && $stream === 'stderr') {
            $lines = \explode("\n", $text);
            $kept = \array_filter($lines, static fn (string $line): bool => \preg_match('/\.php:\d+/', $line) !== 1);

            return \implode("\n", $kept);
        }

        return $text;
    };

    // Agreement is only evidence when the program actually ran. Two identical crashes agree with
    // each other and prove nothing, and neither does a program that prints nothing at all: the
    // corpus is programs whose output is the thing under comparison, plus exactly one report whose
    // exit status and message are. (This check is not decorative: an early revision of this gate
    // wrote its entries where `require ../../../_runtime.php` could not resolve, and "both modes
    // agree" was two copies of the same fatal error.)
    if ($baseline === null) {
        if ($observations['optimized']['exit'] !== 0) {
            $failures[] = "{$name} ({$construct}): the program did not run (exit "
                . $observations['optimized']['exit'] . ")\n" . $observations['optimized']['stdout']
                . $observations['optimized']['stderr'];
            continue;
        }
        if (\trim($observations['optimized']['stdout']) === '') {
            $failures[] = "{$name} ({$construct}): the program printed nothing, so the two modes agree on nothing";
            continue;
        }
    } elseif ($observations['optimized']['exit'] === 0) {
        $failures[] = "{$name} ({$construct}): the report fixture is expected to exit nonzero";
        continue;
    }

    $differences = [];
    if ($observations['optimized']['exit'] !== $observations['unoptimized']['exit']) {
        $differences[] = 'exit ' . $observations['optimized']['exit']
            . ' optimized, ' . $observations['unoptimized']['exit'] . ' unoptimized';
    }
    foreach (['stdout', 'stderr'] as $stream) {
        $a = $baselineStream($stream, $observations['optimized'][$stream]);
        $b = $baselineStream($stream, $observations['unoptimized'][$stream]);
        if ($a !== $b) {
            $differences[] = "{$stream} differs\n--- optimized\n{$a}\n--- unoptimized\n{$b}";
        }
    }

    if ($differences !== []) {
        $failures[] = "{$name} ({$construct}): " . \implode("\n", $differences);
    }
}


if ($failures !== []) {
    \fwrite(\STDERR, \implode("\n\n", $failures) . "\n");
    exit(1);
}

\fwrite(\STDOUT, \count(OPT_DIFFERENTIAL_PROGRAMS) . " programs, {$compiled} compiles, both modes agree\n");
