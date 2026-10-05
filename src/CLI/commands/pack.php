<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Backend\setCompileBackend;
use function Moggi\Docs\generateMogdoc;
use function Moggi\Docs\loadOrBuildIndex;
use function Moggi\Registry\archiveDigest;
use function Moggi\Registry\directoryDigest;
use function Moggi\Registry\directoryEntries;
use function Moggi\Registry\entriesDigest;
use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\moggiIgnorePatterns;
use function Moggi\Registry\packEntries;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\readDescriptor;

/**
 * Render the package's API documentation into `[docs] target-dir`.
 *
 * The tree carries its own digest, which is what a release record's `docs` field
 * names, and the fingerprint file mogdoc leaves behind is left out of it: it names
 * the sources the last render saw, which is not documentation.
 *
 * @param array<string, mixed> $descriptor
 * @return array{digest: string, path: string, modules: int}
 */
function packDocs(string $directory, array $descriptor): array
{
    $docsDir = \rtrim($directory, '/') . '/' . \trim((string) $descriptor['docs']['targetDir'], '/');
    [$input, $libDirs] = packDocsRoots($directory, $descriptor);

    setCompileBackend('php');

    $index = loadOrBuildIndex($input, $libDirs, true);
    generateMogdoc($index, $docsDir);

    return [
        'digest' => directoryDigest($docsDir, ['.mogdoc-source-fingerprint']),
        'path' => $docsDir,
        'modules' => \count($index->byModule),
    ];
}

/**
 * What the documentation covers: the library's source dirs when the package has a
 * library, otherwise the first executable's. Every other source dir is handed over
 * as a library root, so the module closure still resolves across them.
 *
 * @param array<string, mixed> $descriptor
 * @return array{string, list<string>}
 */
function packDocsRoots(string $directory, array $descriptor): array
{
    $dirs = $descriptor['lib']['sourceDirs'];
    if ($dirs === []) {
        foreach ($descriptor['executables'] as $executable) {
            $dirs = $executable['sourceDirs'];
            break;
        }
    }

    $absolute = [];
    foreach ($dirs as $dir) {
        $absolute[] = \rtrim($directory, '/') . '/' . \trim($dir, '/');
    }
    if ($absolute === []) {
        return [$directory, []];
    }

    $input = \array_shift($absolute);

    return [$input, $absolute];
}

/**
 * `moggi pack` — one directory, two digests, no variance.
 *
 * The archive is what a registry stores as `blobs/<sha256>`, so packing the same
 * tree twice has to give the same bytes anywhere. `src/registry/pack.php` is the
 * implementation of that rule, and `--list` prints the records the content digest
 * is taken over, so a digest can be argued with rather than believed.
 *
 * Two digests, two questions: the **content** digest identifies the directory
 * (what a `dir` source names), and the **archive** digest is the blob (`install`
 * checks it after downloading). Nothing is compressed — zlib's output is not
 * identical across versions, and compression is transport anyway.
 *
 * A package that declares `[docs] target-dir` also gets its API documentation
 * rendered as part of packing, and **the docs tree gets a third digest** of its
 * own — the one the release record's `docs` field names. The docs are kept out of
 * the pack for the same reason the compiler cache is kept out of a build: they are
 * derived, and a content digest that moved when the renderer changed would be
 * identifying the compiler rather than the bytes.
 */
function packUsage(): string
{
    return <<<HELP
    usage:
      moggi pack [<dir>] [-o FILE] [--list] [--json]

    Pack a directory into the canonical archive, and print both digests: the
    content digest of the tree (what a `dir` source names) and the digest of the
    archive (the blob a registry stores).

    The archive is deterministic: paths sorted by their UTF-8 bytes, no
    timestamps, no owners, and no permissions — a file is 644 and a link 777,
    because the executable bit is not portable — ustar with pax records for long
    names, and no compression.

    options:
      -o, --output FILE    write here (default: <name>-<version>.tar beside the
                           descriptor)
      --list               print every record the content digest covers, and
                           write nothing
      --no-docs            do not render [docs] target-dir (see below)
      --json               machine-readable output

    A package that declares [docs] target-dir has its API documentation rendered
    there while it is packed, and the digest of that tree is reported as `docs`.
    The tree is excluded from the pack: it is generated, so it is not part of the
    content, and its own digest is what a release record points at.

    Rendering reads the sources the way `moggi mogdoc` does, so it type-checks
    them — a package whose sources do not compile cannot be packed, which is what
    a release should mean — and the documentation covers everything the closure
    reaches, the standard library included. `--no-docs` packs without any of it.
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runPackCommand(array $argv): int
{
    foreach (\array_slice($argv, 2) as $argument) {
        if ($argument === 'help' || $argument === '--help' || $argument === '-h') {
            echo packUsage() . "\n";

            return 0;
        }
    }

    try {
        $options = packagingOptions($argv, ['list', 'json', 'noDocs'], []);
    } catch (\InvalidArgumentException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n\n" . packUsage() . "\n");

        return 1;
    }

    try {
        $descriptorPath = findDescriptor($options['path']);
        $descriptor = readDescriptor($descriptorPath);
        $directory = \dirname($descriptorPath);

        $output = $options['output'] ?? \sprintf('%s/%s-%s.tar', $directory, $descriptor['name'], $descriptor['version']);

        $exclude = [];
        if (\str_starts_with($output, \rtrim($directory, '/') . '/')) {
            $exclude[] = \ltrim(\substr($output, \strlen(\rtrim($directory, '/')) + 1), '/');
        }
        $docsTarget = $descriptor['docs']['targetDir'];
        if ($docsTarget !== null) {
            $exclude[] = \trim($docsTarget, '/');
        }

        $ignore = moggiIgnorePatterns($directory);
        $entries = directoryEntries($directory, $exclude, $ignore);
        $content = entriesDigest($entries);

        if ($options['list']) {
            if ($options['json']) {
                echo prettyJson(['content' => $content, 'entries' => \array_map(
                    static fn (array $entry): array => [
                        'type' => $entry['type'],
                        'size' => $entry['size'],
                        'hash' => 'sha256:' . $entry['hash'],
                        'path' => $entry['path'],
                        'target' => $entry['extra'],
                    ],
                    $entries,
                )]) . "\n";

                return 0;
            }

            \printf("%s %s — %d entr%s\n\n", $descriptor['name'], $descriptor['version'], \count($entries), \count($entries) === 1 ? 'y' : 'ies');
            foreach ($entries as $entry) {
                \printf(
                    "  %-4s %8d  %s%s\n",
                    $entry['type'] === 'link' ? 'link' : 'file',
                    $entry['size'],
                    $entry['path'],
                    $entry['extra'] === '' ? '' : ' -> ' . $entry['extra'],
                );
            }
            \printf("\ncontent    %s\n", $content);

            return 0;
        }

        $archive = packEntries($entries);
        $digest = archiveDigest($archive);

        $docs = $docsTarget === null || $options['noDocs'] ? null : packDocs($directory, $descriptor);

        if (@\file_put_contents($output, $archive) === false) {
            throw new \RuntimeException("cannot write {$output}");
        }

        if ($options['json']) {
            echo prettyJson([
                'name' => $descriptor['name'],
                'version' => $descriptor['version'],
                'content' => $content,
                'archive' => $digest,
                'docs' => $docs === null ? null : ['digest' => $docs['digest'], 'path' => $docs['path'], 'modules' => $docs['modules']],
                'bytes' => \strlen($archive),
                'entries' => \count($entries),
                'path' => $output,
            ]) . "\n";

            return 0;
        }

        \printf(
            "%s %s — %d entr%s\ncontent    %s\narchive    %s\nwrote      %s (%d bytes)\n",
            $descriptor['name'],
            $descriptor['version'],
            \count($entries),
            \count($entries) === 1 ? 'y' : 'ies',
            $content,
            $digest,
            $output,
            \strlen($archive),
        );
        if ($docs !== null) {
            \printf("docs       %s\n           %d module(s) -> %s\n", $docs['digest'], $docs['modules'], $docs['path']);
        }

        return 0;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}
