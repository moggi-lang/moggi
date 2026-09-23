<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/runtimes.php';
require_once __DIR__ . '/../../src/executables.php';

use function Moggi\Compiler\findExecutable;

/**
 * The `schnorr` CLI: the C libraries it links against, and the build itself.
 *
 * The libraries are pinned in `schnorr/deps.json` and cloned at those revisions
 * rather than committed — the tool ships as a binary, so their sources are a
 * build input, not project content. The Makefile next to them owns the flags.
 *
 * `MOGGI_DIST_SCHNORR` names an already-built binary to use instead of building
 * (Windows, where `make` needs an MSYS2 shell), and `MOGGI_DIST_SCHNORR_CC`
 * names the compiler.
 */

const SCHNORR_DIR = 'schnorr';
const SCHNORR_MANIFEST = 'schnorr/deps.json';

/**
 * @return array<string, array{repo: string, tag: string, commit: string, version: string, license: array{name: string, file: string}}>
 */
function schnorrDependencies(string $repo): array
{
    $manifest = \json_decode((string) @\file_get_contents($repo . '/' . SCHNORR_MANIFEST), true);
    if (!\is_array($manifest) || $manifest === []) {
        throw new \RuntimeException('cannot read ' . SCHNORR_MANIFEST);
    }

    return $manifest;
}

/** Where one pinned dependency's sources live: `schnorr/<name>`. */
function schnorrDependencyDir(string $repo, string $name): string
{
    return $repo . '/' . SCHNORR_DIR . '/' . $name;
}

/** Clone every pinned dependency that is not checked out yet, at its exact commit. */
function ensureSchnorrSources(string $repo): void
{
    foreach (schnorrDependencies($repo) as $name => $dep) {
        $dir = schnorrDependencyDir($repo, $name);
        if (\is_dir($dir)) {
            continue;
        }
        \fwrite(STDOUT, "  fetching {$name} ({$dep['tag']})\n");
        runOrFail(['git', 'clone', '--quiet', '--depth', '1', '--branch', $dep['tag'], $dep['repo'], $dir]);

        [$code, $stdout] = runProcess(['git', '-C', $dir, 'rev-parse', 'HEAD']);
        $head = \trim($stdout);
        if ($code !== 0 || $head !== $dep['commit']) {
            throw new \RuntimeException(
                "{$name}: {$dep['tag']} is at " . ($head !== '' ? $head : '?') . ", expected {$dep['commit']}",
            );
        }
    }
}

/** The C compiler the Makefile is driven with. */
function schnorrCompiler(bool $windows): string
{
    $wanted = \getenv('MOGGI_DIST_SCHNORR_CC');
    $candidates = \is_string($wanted) && $wanted !== ''
        ? [$wanted]
        : ($windows ? ['x86_64-w64-mingw32-gcc', 'gcc', 'clang'] : ['cc', 'gcc', 'clang']);

    foreach ($candidates as $candidate) {
        if (findExecutable($candidate) !== null) {
            return $candidate;
        }
    }

    throw new \RuntimeException(
        'no C compiler for the schnorr CLI (tried ' . \implode(', ', $candidates) . '); '
        . 'install one, or point MOGGI_DIST_SCHNORR_CC at it',
    );
}

/** Build the CLI where its Makefile expects to be run, and return the binary's path. */
function buildSchnorr(string $repo, bool $windows = false): string
{
    $prebuilt = \getenv('MOGGI_DIST_SCHNORR');
    if (\is_string($prebuilt) && $prebuilt !== '') {
        if (!\is_file($prebuilt)) {
            throw new \RuntimeException("MOGGI_DIST_SCHNORR names {$prebuilt}, which is not a file");
        }
        ensureSchnorrSources($repo);

        return $prebuilt;
    }

    ensureSchnorrSources($repo);

    $make = findExecutable('make') ?? findExecutable('mingw32-make');
    if ($make === null) {
        throw new \RuntimeException('no make to build the schnorr CLI with');
    }

    $dir = $repo . '/' . SCHNORR_DIR;
    // Run make from the directory rather than with `-C`: under MSYS2 the recipe
    // shell is POSIX but the path Windows PHP would hand over is not.
    runOrFail([$make, 'CC=' . schnorrCompiler($windows), '-j' . cpuCount()], $dir);

    foreach (['schnorr', 'schnorr.exe'] as $name) {
        if (\is_file($dir . '/' . $name)) {
            return $dir . '/' . $name;
        }
    }

    throw new \RuntimeException('the schnorr build produced no binary');
}

if (\realpath($argv[0] ?? '') === \realpath(__FILE__)) {
    try {
        $repo = \dirname(__DIR__, 2);
        for ($i = 1; $i < \count($argv); ++$i) {
            if ($argv[$i] === '--source') {
                $repo = $argv[++$i] ?? $repo;
            }
        }
        \fwrite(STDOUT, 'schnorr: ' . buildSchnorr($repo, \PHP_OS_FAMILY === 'Windows') . "\n");

        exit(0);
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        exit(1);
    }
}
