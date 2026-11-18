<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/runtimes.php';
require_once __DIR__ . '/../src/executables.php';

use function Moggi\Compiler\findExecutable;

/**
 * The `schnorr` CLI: the C libraries it links against, and the build itself.
 *
 * The libraries are pinned in `schnorr/deps.json` and cloned at those revisions
 * rather than committed — the tool ships as a binary, so their sources are a
 * build input, not project content. The Makefile next to them owns the flags.
 *
 * The binary is always built here, from these sources, with the platform's own
 * toolchain: a distribution says which binary it ships and carries no way to
 * have someone else's substituted for it.
 */

const SCHNORR_DIR = 'schnorr';
const SCHNORR_MANIFEST = 'schnorr/deps.json';

/**
 * @return array<string, array{repo: string, tag: string, commit: string, version: string, license: array{name: string, file: string}}>
 */
function schnorrDependencies(string $repo): array
{
    $manifest = \json_decode((string) @\file_get_contents($repo . '/' . SCHNORR_MANIFEST), true);
    if (! \is_array($manifest) || $manifest === []) {
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
function ensureSchnorrSources(string $repo, bool $windows = false): void
{
    $git = schnorrBuildTool('MOGGI_GIT', ['git'], 'git', $windows);
    foreach (schnorrDependencies($repo) as $name => $dep) {
        $dir = schnorrDependencyDir($repo, $name);
        if (\is_dir($dir)) {
            // `git -C` walks up to the parent repository when the directory is
            // not itself a worktree, which would compare this project's HEAD
            // with the dependency pin. Require a real checkout here.
            if (! \is_dir($dir . '/.git') && ! \is_file($dir . '/.git')) {
                throw new \RuntimeException(
                    "{$name}: {$dir} is not a git checkout, so its pinned commit cannot be verified — remove it and rebuild",
                );
            }
            // The pinned commit is the trust anchor for the verifier. An
            // existing checkout may be a stale or hand-edited tree, so check it
            // before compiling it, not only after a fresh clone.
            [$code, $stdout] = runProcess([$git, '-C', $dir, 'rev-parse', 'HEAD']);
            $head = \trim($stdout);
            if ($code !== 0 || $head !== $dep['commit']) {
                throw new \RuntimeException(
                    "{$name}: existing checkout is at " . ($head !== '' ? $head : '?') . ", expected {$dep['commit']} — remove {$dir} and rebuild",
                );
            }
            [$code, $status] = runProcess([$git, '-C', $dir, 'status', '--porcelain', '--untracked-files=all']);
            if ($code !== 0 || \trim($status) !== '') {
                throw new \RuntimeException(
                    "{$name}: existing checkout has local changes — remove {$dir} and rebuild",
                );
            }

            continue;
        }
        \fwrite(STDOUT, "  fetching {$name} ({$dep['tag']})\n");
        runOrFail([$git, 'clone', '--quiet', '--depth', '1', '--branch', $dep['tag'], $dep['repo'], $dir]);

        [$code, $stdout] = runProcess([$git, '-C', $dir, 'rev-parse', 'HEAD']);
        $head = \trim($stdout);
        if ($code !== 0 || $head !== $dep['commit']) {
            throw new \RuntimeException(
                "{$name}: {$dep['tag']} is at " . ($head !== '' ? $head : '?') . ", expected {$dep['commit']}",
            );
        }
    }
}

/**
 * Resolve a build tool to one absolute path and refuse a shared writable one.
 *
 * The build host is part of the supply chain: a `cc` or `make` found through a
 * poisoned PATH would run instead of the trusted toolchain. An explicit
 * `MOGGI_SCHNORR_CC` / `MOGGI_SCHNORR_MAKE` is the escape hatch for a build root
 * that is not on PATH; otherwise the usual names are resolved once, to an
 * absolute path, with the same mode check as a secret key file.
 *
 * @param list<string> $candidates
 */
function schnorrBuildTool(string $variable, array $candidates, string $what, bool $windows): string
{
    $configured = \getenv($variable);
    if (\is_string($configured) && $configured !== '') {
        $path = \realpath($configured);
        if ($path === false || ! \is_file($path)) {
            throw new \RuntimeException("{$variable}={$configured} is not a file");
        }
    } else {
        $path = null;
        foreach ($candidates as $candidate) {
            $found = findExecutable($candidate);
            if ($found !== null) {
                $path = \realpath($found);
                break;
            }
        }
        if ($path === false || $path === null) {
            throw new \RuntimeException(
                "no {$what} for the schnorr CLI (tried " . \implode(', ', $candidates) . '); install one or set ' . $variable,
            );
        }
    }

    if (! $windows) {
        if (! \is_executable($path)) {
            throw new \RuntimeException("{$what} {$path} is not executable");
        }
        $mode = \fileperms($path);
        if ($mode === false || ($mode & 0o022) !== 0) {
            throw new \RuntimeException("{$what} {$path} is writable by group or other; refusing to build the verifier with it");
        }
    }

    return $path;
}

/** The C compiler the Makefile is driven with. */
function schnorrCompiler(bool $windows): string
{
    $candidates = $windows ? ['x86_64-w64-mingw32-gcc', 'gcc', 'clang'] : ['cc', 'gcc', 'clang'];

    return schnorrBuildTool('MOGGI_SCHNORR_CC', $candidates, 'C compiler', $windows);
}

/** Build the CLI where its Makefile expects to be run, and return the binary's path. */
function buildSchnorr(string $repo, bool $windows = false): string
{
    ensureSchnorrSources($repo, $windows);

    $make = schnorrBuildTool('MOGGI_SCHNORR_MAKE', ['make', 'mingw32-make'], 'make', $windows);

    $dir = $repo . '/' . SCHNORR_DIR;
    // A previous binary or object file is not a build input here. Leaving one in
    // place would let a substituted artifact satisfy `make` without referencing
    // the pinned sources, which is exactly the substitution this build claims to
    // rule out. Remove the outputs, then rebuild them from source.
    removeTree($dir . '/build');
    @\unlink($dir . '/schnorr');
    @\unlink($dir . '/schnorr.exe');

    $jobs = $windows ? [] : ['-j' . cpuCount()];
    runOrFail([$make, 'CC=' . schnorrCompiler($windows), ...$jobs], $dir, true, 600);

    foreach (['schnorr', 'schnorr.exe'] as $name) {
        if (\is_file($dir . '/' . $name)) {
            return $dir . '/' . $name;
        }
    }

    throw new \RuntimeException('the schnorr build produced no binary');
}

if (\realpath($argv[0] ?? '') === \realpath(__FILE__)) {
    try {
        $repo = \dirname(__DIR__);
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
