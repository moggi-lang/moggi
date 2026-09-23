<?php declare(strict_types=1);

/**
 * Frontend fuzz smoke: random inputs must end in either output or a diagnostic, never a hang or a
 * crash. The corpus comes from a fixed seed (so a failure is reproducible) and runs in a child
 * process — the failure mode being hunted is one this process could not survive.
 *
 * The assertions are deliberately loose; the fixtures assert precise diagnostics. A failure names
 * its seed and input index, keeps the input under `.moggi/fuzz-failures/`, and prints the
 * `fuzz_replay.php` command that reruns it.
 */

use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Semantics\Effects\checkAndNormalize;
use function Moggi\Syntax\Ast\dump as dumpAst;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

/** Fragments fed to the generator. Anything the frontend has to survive, valid or not. */
const FUZZ_FRAGMENTS = [
    'module Fuzz where', 'import Data.Int', 'import System.IO as IO',
    'main :: IO ()', 'main = putStrLn "hi"', 'x :: Int', 'x = 1', 'f y = y + 1',
    'data Color = Red | Green | Blue deriving (Show, Eq)', 'type Name = String',
    'class Sized a where', 'instance Sized Int where', 'foreign php function p "f" :: Int -> IO Int',
    'case x of', 'let y = 2 in y', 'do', 'if x then 1 else 2', 'where',
    // Tokens that stress the lexer.
    '->', '=>', '::', '|', '\\', '_', '..', '@', '~', '#', '$', '`', '%', '^', '&', '!', '?', ';',
    '(', ')', '[', ']', '{', '}', ',', "'a'", '"str"', '"\\x41\\o101\\&"', '0', '0x1f', '1.5e-3',
    '0123', '0b12', 'VeryLongIdentifierNameThatKeepsGoingAndGoing', 'Foo', 'foo_bar', 'é', 'λ', '→',
    '-- line comment', '{- {- nested -} -}', '{- unterminated', '"unterminated', "'u",
    "\t", ' ', '', "\r", '\\0', 'a{1,3}', '<-', '<>', '<$>', '.$', '()', '[]', '(:%)',
];

/**
 * Deterministic corpus: `$count` sources, four in ten of them token soup, four in ten generated
 * programs, and the rest generated programs that were then mutated (a line dropped, the source
 * cut in half, a line doubled).
 *
 * The mix is the point: soup is what the frontend must *reject* without falling over, while the
 * generated programs are what get past the parser and into inference, and the mutations are the
 * near-misses that only a fuzzer produces. Nothing here is random beyond the seed.
 *
 * @return list<string>
 */
function fuzzFrontendCorpus(int $seed, int $count): array
{
    \mt_srand($seed);
    $corpus = [];

    for ($i = 0; $i < $count; ++$i) {
        $corpus[] = match (\mt_rand(0, 9)) {
            0, 1, 2, 3 => fuzzFragmentSoup(),
            4, 5, 6, 7 => fuzzGeneratedProgram(),
            default => fuzzMutatedProgram(),
        };
    }

    return $corpus;
}

/** Random fragments on random lines: almost nothing here should parse. */
function fuzzFragmentSoup(): string
{
    $pool = FUZZ_FRAGMENTS;
    $last = \count($pool) - 1;
    $source = '';

    for ($line = 0, $lines = \mt_rand(1, 8); $line < $lines; ++$line) {
        $parts = [];
        for ($part = 0, $count = \mt_rand(1, 7); $part < $count; ++$part) {
            $parts[] = $pool[\mt_rand(0, $last)];
        }
        $source .= \implode(' ', $parts) . "\n";
    }

    return $source;
}

/** One of a few shapes that do parse, with random names and literals. */
function fuzzGeneratedProgram(): string
{
    $name = fuzzIdentifier();
    $ctor = 'Ctor' . \ucfirst(fuzzIdentifier());
    $literal = (string) \mt_rand(0, 999);
    $op = ['+', '-', '*'][\mt_rand(0, 2)];

    return match (\mt_rand(0, 3)) {
        0 => "{$name} :: Int -> Int\n{$name} x = x {$op} {$literal}\n",
        1 => "data {$ctor} = A{$ctor} | B{$ctor}\n\npick :: {$ctor} -> Int\npick c = case c of\n  A{$ctor} -> {$literal}\n  B{$ctor} -> {$op} {$literal}\n",
        2 => "{$name} :: (Int, Int)\n{$name} = let x = {$literal} in (x, x {$op} 1)\n",
        default => "{$name} :: [Int]\n{$name} = [{$literal}, {$literal}, {$literal}]\n",
    };
}

/** A generated program with one random mutation: the near-miss case. */
function fuzzMutatedProgram(): string
{
    $source = fuzzGeneratedProgram();
    $lines = \explode("\n", $source);

    return match (\mt_rand(0, 3)) {
        // Cut anywhere: unterminated literals, half a declaration, empty input.
        0 => \substr($source, 0, \mt_rand(0, \strlen($source))),
        1 => \str_replace(' ', '', $source),
        2 => \implode("\n", [$lines[0] ?? '', ...$lines]),
        default => \strtoupper($source),
    };
}

function fuzzIdentifier(): string
{
    $letters = 'abcdefghijklmnopqrstuvwxyz';
    $name = '';
    for ($i = 0, $length = \mt_rand(1, 6); $i < $length; ++$i) {
        $name .= $letters[\mt_rand(0, 25)];
    }

    return $name;
}

/**
 * One input through the whole frontend, classified.
 *
 * Lexing, parsing, the AST dump and inference all count: an input is only accounted for once the
 * dump has walked what the parser built and inference has either accepted it or rejected it with a
 * diagnostic.
 *
 * @return array{kind: 'ok'|'diagnostic'|'crash', message?: string, class?: class-string}
 */
function fuzzFrontendOutcome(string $source, string $path): array
{
    try {
        $ast = parse(lex($source, $path), $source, $path);
        dumpAst($ast);
        checkAndNormalize($ast, $source, $path);

        return ['kind' => 'ok'];
    } catch (LexError | ParseError | TypeError $e) {
        return ['kind' => 'diagnostic', 'message' => $e->display()];
    } catch (\Throwable $e) {
        return ['kind' => 'crash', 'class' => \get_class($e), 'message' => $e->getMessage()];
    }
}

/**
 * Shrink a crashing input while it keeps crashing the same way.
 *
 * Minimising is what turns "input #48291 of 600" into something a person can read and reason about.
 * Whole lines go first — a crash rarely needs the program it was found in, and dropping lines keeps
 * the result legible — then single characters, so one long unbroken line still gets smaller. The
 * budget bounds the work, so a pathological input cannot turn one fuzz failure into a long run.
 */
function fuzzMinimize(string $source, string $path, string $crashClass, int $budget = 400): string
{
    $runs = 0;
    $stillCrashes = static function (string $candidate) use ($path, $crashClass, &$runs, $budget): bool {
        if ($candidate === '' || ++$runs > $budget) {
            return false;
        }

        $outcome = fuzzFrontendOutcome($candidate, $path);

        return ($outcome['kind'] ?? '') === 'crash' && ($outcome['class'] ?? '') === $crashClass;
    };

    $best = fuzzShrink($source, fuzzDropLine(...), $stillCrashes);

    return fuzzShrink($best, static fn (string $s, int $i): string => \substr($s, 0, $i) . \substr($s, $i + 1), $stillCrashes);
}

/**
 * Drop one line, or null when there is no line $index.
 */
function fuzzDropLine(string $source, int $index): ?string
{
    $lines = \explode("\n", $source);
    if ($index >= \count($lines)) {
        return null;
    }

    return \implode("\n", [...\array_slice($lines, 0, $index), ...\array_slice($lines, $index + 1)]);
}

/**
 * Greedily remove one unit at a time while `$fails` still holds, restarting after every success.
 *
 * @param callable(string, int): ?string $drop
 * @param callable(string): bool $fails
 */
function fuzzShrink(string $source, callable $drop, callable $fails): string
{
    $changed = true;
    while ($changed) {
        $changed = false;
        // `$drop` decides where the units end: null *or* an unchanged string means there is no unit
        // left at that index (a substring past the end returns the input untouched).
        for ($i = 0; ; ++$i) {
            $candidate = $drop($source, $i);
            if ($candidate === null || $candidate === $source) {
                break;
            }
            if ($fails($candidate)) {
                $source = $candidate;
                $changed = true;
                break;
            }
        }
    }

    return $source;
}

/**
 * Run the corpus through the frontend in a child process.
 *
 * @return array{passed: bool, message: string}
 */
function fuzzFrontendSmoke(string $projectRoot, int $seed, int $count): array
{
    $workDir = createTempDir('moggi-fuzz-');
    $child = runCompiledProcess(
        [PHP_BINARY, __DIR__ . '/fuzz_child.php', (string) $seed, (string) $count, $workDir],
        180,
        null,
        $projectRoot,
    );

    $failed = static function (string $message) use ($workDir, $seed, $count): array {
        return ['passed' => false, 'message' => $message];
    };

    if (($child['timedOut'] ?? false) === true) {
        return $failed(fuzzFailure($workDir, $seed, $count, null, 'the frontend did not terminate within 180s; it must never hang'));
    }

    $report = \json_decode(\trim((string) ($child['stdout'] ?? '')), true);
    if (!\is_array($report)) {
        return $failed(fuzzFailure(
            $workDir,
            $seed,
            $count,
            null,
            \sprintf(
                'the frontend died (exit %d); every input must end in output or a diagnostic:%s',
                (int) ($child['exitCode'] ?? -1),
                "\n" . \trim((string) ($child['stderr'] ?? '')),
            ),
        ));
    }

    foreach ($report['unexpected'] as $failure) {
        return $failed(fuzzFailure(
            $workDir,
            $seed,
            $count,
            (int) $failure['index'],
            \sprintf('input escaped the frontend as %s: %s', $failure['kind'], $failure['message']),
            (string) ($failure['source'] ?? ''),
        ));
    }

    if ($report['spanless'] !== []) {
        $first = $report['spanless'][0];

        return $failed(fuzzFailure(
            $workDir,
            $seed,
            $count,
            (int) $first['index'],
            'diagnostic without a source location: ' . $first['message'],
            (string) ($first['source'] ?? ''),
        ));
    }

    $accounted = $report['ok'] + $report['diagnostics'];
    if ($accounted !== $count) {
        return $failed(fuzzFailure($workDir, $seed, $count, null, \sprintf(
            'input(s) went missing: %d accepted + %d rejected != %d generated',
            $report['ok'],
            $report['diagnostics'],
            $count,
        )));
    }

    removeDirectory($workDir);

    return [
        'passed' => true,
        'message' => sprintf(
            '%d inputs (seed %d): %d accepted, %d rejected as diagnostics',
            $count,
            $seed,
            $report['ok'],
            $report['diagnostics'],
        ),
    ];
}

/**
 * A failure report that can be replayed: which seed and input, the input itself, where a copy was
 * kept, and the command that runs it on its own.
 */
function fuzzFailure(string $workDir, int $seed, int $count, ?int $index, string $message, string $minimized = ''): string
{
    // The child writes each input before running it, so the highest-numbered one is the input it
    // died on — including when it died without printing a report at all.
    $index ??= fuzzLastInputIndex($workDir);
    $source = $minimized !== '' ? $minimized : fuzzInputSource($workDir, $index);
    $saved = fuzzSaveReproducer($seed, $index, $source);

    $lines = [sprintf('seed %d, input %s', $seed, $index === null ? '(unidentified)' : '#' . $index)];
    $lines[] = $message;
    if ($source !== '') {
        $lines[] = 'input:';
        $lines[] = \rtrim($source);
    }
    if ($index !== null) {
        $lines[] = sprintf('repro: php tests/suite/support/fuzz_replay.php %d %d %d', $seed, $index, $count);
    }
    if ($saved !== null) {
        $lines[] = 'saved: ' . $saved;
    }

    return \implode("\n", $lines);
}

/** The index of the input the child was on when it stopped. */
function fuzzLastInputIndex(string $workDir): ?int
{
    $last = null;
    foreach (\glob($workDir . '/input-*.mog') ?: [] as $path) {
        if (\preg_match('/input-(\d+)\.mog$/', $path, $m) === 1) {
            $last = $last === null ? (int) $m[1] : \max($last, (int) $m[1]);
        }
    }

    return $last;
}

function fuzzInputSource(string $workDir, ?int $index): string
{
    if ($index === null) {
        return '';
    }

    $path = $workDir . '/input-' . $index . '.mog';

    return \is_file($path) ? (string) \file_get_contents($path) : '';
}

/**
 * Keep a failing input where it outlives the temp work directory, so a crash found in CI can be
 * opened and re-checked locally.
 */
function fuzzSaveReproducer(int $seed, ?int $index, string $source): ?string
{
    if ($source === '') {
        return null;
    }

    $dir = fuzzReproducerDir();
    if (!@\mkdir($dir, 0777, true) && !\is_dir($dir)) {
        return null;
    }

    $path = \sprintf('%s/seed-%d-input-%s.mog', $dir, $seed, $index === null ? 'unknown' : (string) $index);

    return @\file_put_contents($path, $source) === false ? null : $path;
}

/**
 * Where fuzz reproducers are kept: beside the case logs, under the cache directory.
 *
 * `MOGGI_FUZZ_DIR` overrides it, which is how the unit test asserts a reproducer was saved without
 * writing into the working copy's cache.
 */
function fuzzReproducerDir(): string
{
    $override = \getenv('MOGGI_FUZZ_DIR');
    if (\is_string($override) && $override !== '') {
        return \rtrim($override, '/');
    }

    $base = \function_exists('Moggi\\Cache\\cacheScratchDir')
        ? \Moggi\Cache\cacheScratchDir()
        : \dirname(__DIR__, 2) . '/.moggi';

    return $base . '/fuzz-failures';
}
