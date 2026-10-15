<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Registry\Bind\bindCacheKey;
use function Moggi\Registry\Bind\dotNetBindingSidecarPath;
use function Moggi\Registry\Bind\facadeModuleName;
use function Moggi\Registry\Bind\inspectDotNetTree;
use function Moggi\Registry\Bind\inspectJvmTree;
use function Moggi\Registry\Bind\inspectPhpTree;
use function Moggi\Registry\Bind\parseDotNetCoordinate;
use function Moggi\Registry\Bind\parseJvmCoordinate;
use function Moggi\Registry\Bind\parsePhpCoordinate;
use function Moggi\Registry\Bind\renderBinding;
use function Moggi\Registry\Bind\renderFacade;
use function Moggi\Registry\Bind\writeBinding;
use function Moggi\Registry\Bind\writeDotNetBinding;
use function Moggi\Registry\Bind\writeFacade;
use function Moggi\Registry\prettyJson;

/**
 * `moggi bind` — read a third-party host tree and write the typed Moggi
 * bindings for it, so a popular library is bound once, reviewed, and then used
 * like any other source.
 *
 *   moggi bind php:acme/hello --tree ./acme-hello --out src/
 *
 * The coordinate spelling follows `moggi add` (`php:vendor/package`,
 * `jvm:group:artifact[:version]`, `dotnet:Id[:version]`); `--print` follows
 * `add --print` (show what would be written, write nothing). Bindings are
 * backend-specific — portable code needs a hand-written facade — so only the
 * backend's own prefix binds. Each binder reads a tree from a directory on
 * disk (`--tree`); resolving the tree through the host-tool cache the way
 * `moggi build` does is a later phase. A `dotnet:` bind writes the module
 * plus the versioned `dotnet/dependencies.json` sidecar beside it.
 */
function bindUsage(): string
{
    return <<<HELP
    usage:
      moggi bind <backend>:<coordinate> --tree DIR [--out DIR] [--print] [--json] [--facade]

    Read a third-party host tree and write the typed Moggi bindings for it:
    one `foreign`-declaration module per library, prefixed by ecosystem
    (`Composer`, `Maven`, `Nuget` — the full coordinate table is docs/ffi.md
    §12). Bind once, review the diff, then `moggi publish` —
    a binding is a package like any other.

    A PHP tree is a resolved Composer tree whose installed sources live under
    `vendor/` (only the bound package's own files are read); a JVM tree
    carries jars under a `jvm/` directory, read with `javap`; a .NET tree
    carries assemblies under a `dotnet/` directory, read with the CLR
    inspector (needs a .NET SDK). The .NET bind also writes the versioned
    `dotnet/dependencies.json` sidecar beside the module. `--print` shows the
    module and stops; `--json` shows the inspected descriptor the module is
    rendered from, including every skipped symbol and why.

    `--tree` is mandatory: the bind reads the host tree on disk, it never
    resolves one. `--facade` also writes the portable facade skeleton — the
    bound API behind a `{-# BACKEND #-}` pragma, named for the module without
    its ecosystem prefix (`Composer.Acme.Hello` faced as `Acme.Hello`)
    — so portable code has something to import while the other backends stay
    comment placeholders to bind later.

    examples:
      moggi bind php:acme/hello --tree ./acme-hello --out src/ --print
      moggi bind jvm:com.acme:hello --tree ./acme-hello --out src/
      moggi bind dotnet:Acme.Hello --tree ./acme-hello --out src/
      moggi bind php:acme/hello --tree ./acme-hello --out src/ --facade
    HELP;
}

function runBindCommand(array $argv): int
{
    $spec = new CommandSpec('bind', bindUsage(), [
        ['name' => 'tree', 'value' => true],
        ['name' => 'out', 'value' => true],
        ['name' => 'print'],
        ['name' => 'json'],
        ['name' => 'facade'],
    ]);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $coordinate = (string) ($options['positionals'][0] ?? '');
    if ($coordinate === '') {
        \fwrite(STDERR, "error: `bind` needs a coordinate — `moggi bind php:vendor/package --tree DIR`\n\n" . bindUsage() . "\n");

        return 1;
    }
    $colon = \strpos($coordinate, ':');
    if ($colon === false) {
        \fwrite(STDERR, "error: cannot bind `{$coordinate}` — a bind coordinate is `<backend>:<coordinate>`\n");

        return 1;
    }
    $backend = \substr($coordinate, 0, $colon);
    $rest = \substr($coordinate, $colon + 1);
    if ($backend !== 'php' && $backend !== 'jvm' && $backend !== 'dotnet') {
        \fwrite(STDERR, "error: cannot bind `{$coordinate}` — only `php:`, `jvm:` and `dotnet:` coordinates bind\n");

        return 1;
    }

    $tree = (string) ($options['tree'] ?? '');
    if ($tree === '') {
        \fwrite(STDERR, "error: `bind` needs `--tree DIR` — the directory holding the package's sources\n\n" . bindUsage() . "\n");

        return 1;
    }

    try {
        if ($backend === 'jvm') {
            $parts = parseJvmCoordinate($rest);
            $descriptor = inspectJvmTree($tree, $parts['group'], $parts['artifact']);
        } elseif ($backend === 'dotnet') {
            $parts = parseDotNetCoordinate($rest);
            $descriptor = inspectDotNetTree($tree, $parts['id'], $parts['version']);
        } else {
            $parts = parsePhpCoordinate($rest);
            $descriptor = inspectPhpTree($tree, $parts['vendor'], $parts['package']);
        }
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }

    if ($options['json']) {
        echo prettyJson($descriptor) . "\n";

        return 0;
    }

    $facadeModule = $options['facade'] ? facadeModuleName($descriptor['module']) : null;

    if ($options['print']) {
        echo renderBinding($descriptor);
        if ($facadeModule !== null) {
            echo renderFacade($descriptor, $facadeModule);
        }

        return 0;
    }

    try {
        $outDir = (string) ($options['out'] ?? '.');
        if ($backend === 'dotnet') {
            $path = writeDotNetBinding($outDir, $descriptor);
            $path .= ' + ' . dotNetBindingSidecarPath($outDir, $descriptor['module']);
        } else {
            $path = writeBinding($outDir, $descriptor);
        }
        if ($facadeModule !== null) {
            $path .= ' + ' . writeFacade($outDir, $descriptor, $facadeModule);
        }
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }

    \fwrite(
        STDERR,
        'bound ' . $descriptor['coordinate'] . ' to ' . $path
        . ' (' . \count($descriptor['types']) . ' types, ' . \count($descriptor['functions'])
        . ' functions, ' . \count($descriptor['consts']) . ' consts, '
        . \count($descriptor['skipped']) . ' skipped)'
        . ' [cache ' . bindCacheKey($backend, $descriptor['coordinate']) . "]\n",
    );

    return 0;
}
