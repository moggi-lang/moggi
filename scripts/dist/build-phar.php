<?php declare(strict_types=1);

namespace Moggi\Dist;

use function Moggi\Compiler\runProcess;

require_once __DIR__ . '/../../src/executables.php';

/**
 * Build `bin/moggi.phar` — the compiler as one self-contained PHP archive.
 *
 * The archive carries the compiler and `VERSION`; the standard library ships
 * beside it as ordinary sources (`<installation>/lib`), so users can read it and
 * it stays covered by the installation's LICENSE. Writing a PHAR needs
 * `phar.readonly=0`, which the PHP default forbids, so this re-executes itself
 * with it.
 *
 *   php scripts/dist/build-phar.php [--source <repo>] [--out <path>]
 */

const PHAR_STUB = <<<'PHP'
#!/usr/bin/env php
<?php declare(strict_types=1);

namespace Moggi\Dist;

if (\PHP_VERSION_ID < 80500) {
    \fwrite(STDERR, "moggi: PHP 8.5+ is required, this is " . \PHP_VERSION . "\n");
    exit(1);
}

// `__DIR__` resolves inside the PHAR, so the compiler loads itself from here.
require 'phar://' . __FILE__ . '/src/compiler.php';

exit(\Moggi\CLI\main($argv));

__HALT_COMPILER();
PHP;

/** Files the archive carries, relative to the source root. */
const PHAR_INCLUDES = ['src'];

/** A basename that starts with this is a working note, and is never archived. */
const PHAR_EXCLUDES_PREFIX = '_';

/**
 * The version the archive reports: the tag it was built from, or the version
 * file marked as an unreleased build of the commit that produced it
 * (`0.0.1-dev.20260922+f60fb9f`, plus `.dirty` for a modified tree). Without
 * git the version file is used verbatim, which is what a source tarball is.
 */
function compilerBuildVersion(string $sourceRoot): string
{
    $base = \trim((string) @\file_get_contents($sourceRoot . '/VERSION'));
    if ($base === '') {
        throw new \RuntimeException("cannot read {$sourceRoot}/VERSION");
    }

    $status = gitOutput($sourceRoot, ['status', '--porcelain', '--untracked-files=no']);
    if ($status === '') {
        $tag = gitOutput($sourceRoot, ['describe', '--tags', '--exact-match']);
        if ($tag !== null && $tag !== '') {
            return $tag;
        }
    }

    $sha = gitOutput($sourceRoot, ['rev-parse', '--short', 'HEAD']);
    if ($sha === null || $sha === '') {
        return $base;
    }

    $date = gitOutput($sourceRoot, ['log', '-1', '--format=%cd', '--date=format:%Y%m%d']) ?? '';
    $dirty = $status !== '' && $status !== null ? '.dirty' : '';

    return "{$base}-dev.{$date}+{$sha}{$dirty}";
}

/** One `git` command in `$repo`, or null when git is absent or it fails. */
function gitOutput(string $repo, array $args): ?string
{
    $result = runProcess(['git', '-C', $repo, ...$args]);

    return $result['exitCode'] === 0 ? \trim($result['stdout']) : null;
}

/**
 * @return array{source: string, out: string, reexec: bool}
 */
function parseBuildPharArgv(array $argv): array
{
    $source = null;
    $out = null;
    $reexec = true;

    for ($i = 1; $i < \count($argv); ++$i) {
        $arg = $argv[$i];
        if ($arg === '--source') {
            $source = $argv[++$i] ?? null;
        } elseif (\str_starts_with($arg, '--source=')) {
            $source = \substr($arg, 9);
        } elseif ($arg === '--out') {
            $out = $argv[++$i] ?? null;
        } elseif (\str_starts_with($arg, '--out=')) {
            $out = \substr($arg, 6);
        } elseif ($arg === '--in-process') {
            $reexec = false;
        }
    }

    $repo = \dirname(__DIR__, 2);

    return [
        'source' => $source ?? $repo,
        'out' => $out ?? $repo . '/bin/moggi.phar',
        'reexec' => $reexec,
    ];
}

function buildCompilerPhar(string $sourceRoot, string $outPath): void
{
    if (!\is_dir($sourceRoot . '/src')) {
        throw new \RuntimeException("not a Moggi source root: {$sourceRoot}");
    }

    $version = compilerBuildVersion($sourceRoot);

    $outDir = \dirname($outPath);
    if (!\is_dir($outDir) && !\mkdir($outDir, 0777, true) && !\is_dir($outDir)) {
        throw new \RuntimeException("cannot create {$outDir}");
    }

    // Deterministic: the same tree produces the same archive bytes.
    $tmp = $outPath . '.building';
    if (\is_file($tmp)) {
        \unlink($tmp);
    }

    $phar = new \Phar($tmp, 0, \basename($outPath));
    $phar->startBuffering();

    $count = 0;
    foreach (PHAR_INCLUDES as $include) {
        $absolute = $sourceRoot . '/' . $include;
        if (\is_file($absolute)) {
            $phar->addFile($absolute, $include);
            ++$count;
            continue;
        }
        if (!\is_dir($absolute)) {
            continue;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = \substr($file->getPathname(), \strlen($sourceRoot) + 1);
            if (\str_starts_with(\basename($relative), PHAR_EXCLUDES_PREFIX)) {
                continue;
            }
            $phar->addFile($file->getPathname(), $relative);
            ++$count;
        }
    }

    if ($count === 0) {
        throw new \RuntimeException('nothing to archive');
    }

    $phar->addFromString('VERSION', $version . "\n");
    $phar->setStub(PHAR_STUB);
    $phar->stopBuffering();
    unset($phar);

    if (!\rename($tmp, $outPath)) {
        throw new \RuntimeException("cannot move the built archive to {$outPath}");
    }
    @\chmod($outPath, 0755);
}

function main(array $argv): int
{
    $options = parseBuildPharArgv($argv);

    if ($options['reexec'] && \ini_get('phar.readonly') !== '0') {
        $command = [
            \PHP_BINARY,
            '-d', 'phar.readonly=0',
            '-d', 'phar.require_hash=0',
            __FILE__,
            '--source', $options['source'],
            '--out', $options['out'],
            '--in-process',
        ];
        $descriptors = [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR];
        $process = \proc_open($command, $descriptors, $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('cannot re-execute the PHAR builder');
        }

        return \proc_close($process);
    }

    buildCompilerPhar($options['source'], $options['out']);
    $size = \filesize($options['out']);
    \fwrite(STDOUT, 'phar: ' . $options['out'] . ' (' . \number_format($size / 1048576, 1) . " MiB)\n");

    return 0;
}

if (\realpath($argv[0] ?? '') === \realpath(__FILE__)) {
    try {
        exit(main($argv));
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        exit(1);
    }
}
