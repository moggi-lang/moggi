<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Registry\FetchLog;

use function Moggi\Cache\atomicWrite;
use function Moggi\Registry\badPackageProblem;
use function Moggi\Registry\badPackagesAmong;
use function Moggi\Registry\collectHostToolCoordinates;
use function Moggi\Registry\descriptorAdditionProblems;
use function Moggi\Registry\descriptorProblems;
use function Moggi\Registry\descriptorWithCoordinate;
use function Moggi\Registry\descriptorWithFfiBinding;
use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\hostToolDescriptor;
use function Moggi\Registry\installLockedPackages;
use function Moggi\Registry\newestFirst;
use function Moggi\Registry\parseAddCoordinate;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\resolveHostTools;
use function Moggi\Registry\shortNpub;
use function Moggi\Registry\unmaintainedPackageNote;
use function Moggi\Registry\unmaintainedPackagesAmong;
use function Moggi\Registry\withAddConstraint;

/**
 * `moggi add` — one requirement, into the table it belongs to.
 *
 * **A bare name is a package of ours, a prefix is somebody else's ecosystem.**
 *
 *   moggi add json                         `[dependencies] json = 0.4`
 *   moggi add jvm:com.google:gson:2.11.0   `[jvm.maven] com.google:gson = 2.11.0`
 *
 * The two halves are one verb because they are one operation from the user's side
 * — "this project depends on something else" — and two different machines
 * underneath, which is why the table decides the rest:
 *
 * * A package of ours is resolved by the registry we already have: the catalog is
 *   loaded and verified, the lock is written, and the release is fetched and
 *   unpacked — the same `writeResolution()`/`installLockedPackages()` pair that
 *   `moggi update` and `moggi install` run, so `add` cannot drift from them. The
 *   descriptor entry comes first here, because the dependency *is* what the
 *   resolver reads; a package the registry does not list is refused before that,
 *   so the common typo leaves nothing behind.
 * * A host coordinate is resolved by the ecosystem's own tool through
 *   `resolveHostTools()`, and resolution comes *before* the write, the way
 *   `sky add` runs `go get` before it touches its manifest: a missing tool or an
 *   artifact that does not exist then leaves the descriptor exactly as it was.
 *
 * `--print` shows the lines it would write and stops — nothing fetched, nothing
 * written. For a package of ours that means it will not ask the registry which
 * version to record, so `--print` wants `--version`.
 */

function addUsage(): string
{
    $default = DEFAULT_REGISTRY;

    return <<<HELP
    usage:
      moggi add <name> [--version CONSTRAINT] [options]
      moggi add <backend>:<coordinate> [--version VERSION] [options]

    Declare one dependency and make it real: a bare `name` is a package of ours
    ([dependencies], resolved, locked, fetched), and a `backend:` prefix is a
    third-party host package (php `[php] composer`, jvm `[jvm.maven]`, dotnet
    `[dotnet.nuget]`, resolved through the same cache tree `moggi build` uses).

    coordinates:
      name                          a package of ours — moggi add json
      php:vendor/package:constraint a Composer package — php:monolog/monolog:^3.0
      jvm:group:artifact:version    a Maven artifact — jvm:com.google.code.gson:gson:2.11.0
      dotnet:Id:version             a NuGet package — dotnet:Newtonsoft.Json:13.0.3

    A host coordinate takes `--bind` to also generate the typed binding module
    from the resolved tree (into the bind cache, recorded under `[ffi]
    bindings` so `moggi build --bind` reuses it), and `--facade` with it to
    write the portable `{-# BACKEND #-}` skeleton beside the binding — the
    hand-finish for the backends not bound yet.

    A package of ours takes `--version` as a constraint (`0.4`, `>=0.4.1`); without
    one the newest release's major line is written, so `moggi update` may move
    within it. A host coordinate takes `--version` when its spelling leaves the
    version out (`jvm:group:artifact`, `dotnet:Id`) — mvn needs one, NuGet floats.

    A `host:` prefix is the *only* way to name a host package: `monolog/monolog` is
    not a package name, and `add` says so and tells you to write `php:monolog/monolog`
    rather than guessing which of the four tables you meant.

    options:
      --root DIR           the package to edit (default: the current directory)
      --version VALUE      the constraint (ours) or version (theirs); see above
      --registry URL|DIR   registry to resolve a package of ours against
                           (default: MOGGI_REGISTRY, else {$default})
      --print              show the descriptor edit and stop — nothing is written
                           and nothing is fetched (needs --version for ours)
      --bind               also generate the typed binding from the resolved
                           host tree (host coordinates only)
      --facade             also write the portable facade skeleton (needs --bind)
      --no-cache           re-fetch instead of using what is held
      --json               machine-readable report
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runAddCommand(array $argv): int
{
    $spec = new CommandSpec('add', addUsage(), [
        ['name' => 'print'],
        ['name' => 'version', 'value' => true],
        ['name' => 'root', 'value' => true],
        ['name' => 'json'],
        ['name' => 'noCache'],
        ['name' => 'bind'],
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

    $arguments = $options['positionals'];
    if ($arguments === []) {
        \fwrite(STDERR, "error: `add` needs a name or a `backend:coordinate`\n\n" . addUsage() . "\n");

        return 1;
    }
    if (\count($arguments) > 1) {
        \fwrite(STDERR, "error: `add` takes one dependency, got: " . \implode(' ', $arguments) . "\n\n" . addUsage() . "\n");

        return 1;
    }

    if ($options['facade'] && !$options['bind']) {
        \fwrite(STDERR, "error: `add --facade` needs `--bind` — the skeleton faces a binding\n\n" . addUsage() . "\n");

        return 1;
    }

    try {
        $report = addDependency(
            $options['root'] === null ? '.' : (string) $options['root'],
            (string) $arguments[0],
            $options['version'] === null ? null : (string) $options['version'],
            $options['print'],
            !$options['noCache'],
            (string) $options['registry'],
            (bool) $options['bind'],
            (bool) $options['facade'],
        );
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }

    if ($options['json']) {
        echo prettyJson($report) . "\n";

        return $report['ok'] ? 0 : 1;
    }

    reportAdd($report);

    return $report['ok'] ? 0 : 1;
}

/**
 * Declare one requirement in a package's descriptor, and make it real.
 *
 * Both halves end with the same three promises: the descriptor is the last thing
 * that changes when resolution can happen first, a requirement already declared
 * identically is a no-op, and the file it wrote is one the parser accepts
 * (`descriptorAdditionProblems()`). What differs is the resolver, and therefore
 * what can be checked before the write.
 *
 * @return array<string, mixed>
 */
function addDependency(string $root, string $argument, ?string $version, bool $print, bool $useCache, string $registry, bool $bind = false, bool $facade = false): array
{
    $descriptorPath = findDescriptor($root);
    $problems = descriptorProblems($descriptorPath);
    if ($problems !== []) {
        throw new \RuntimeException(
            "{$descriptorPath} is not a descriptor this compiler accepts, so `add` will not edit it:\n  "
            . \implode("\n  ", $problems),
        );
    }

    $text = @\file_get_contents($descriptorPath);
    if ($text === false) {
        throw new \RuntimeException("cannot read {$descriptorPath}");
    }

    $descriptor = readDescriptor($descriptorPath);
    $edit = parseAddCoordinate($argument, $version);

    if (($edit['kind'] === 'registry') && ($bind || $facade)) {
        throw new \RuntimeException(
            'only a host coordinate binds — a package of ours carries its own sources, nothing to generate',
        );
    }

    return ($edit['kind'] === 'registry')
        ? addRegistryDependency($descriptor, $descriptorPath, $text, $edit, $print, $useCache, $registry)
        : addHostCoordinate($descriptor, $descriptorPath, $text, $edit, $print, $useCache, $bind, $facade);
}

/**
 * A package of ours: `[dependencies]` now, then the lock, then the archive.
 *
 * The order is the mirror image of the host half, and for a reason: a host
 * coordinate is checked *against its tool* before it is recorded, while a
 * dependency of ours is checked against the catalog before it is recorded — that
 * is what refuses a name the registry does not list — and the fetch that follows
 * is `moggi install`'s work, with `moggi install`'s own retry.
 *
 * @param array<string, mixed> $descriptor
 * @param array<string, mixed> $edit
 * @return array<string, mixed>
 */
function addRegistryDependency(array $descriptor, string $descriptorPath, string $text, array $edit, bool $print, bool $useCache, string $registry): array
{
    $name = (string) $edit['package'];
    if (($edit['deferred'] ?? false) === true && $print) {
        throw new \RuntimeException(
            "--print will not ask a registry which version to record — say `--version CONSTRAINT`, or run without --print",
        );
    }

    $log = new FetchLog();
    $catalog = $print ? null : loadVerifiedCatalog($registry, $useCache, $log);

    if ($catalog !== null) {
        $entry = $catalog->entry($name);
        if ($entry === null) {
            throw new \RuntimeException("the registry at {$registry} lists no package `{$name}`");
        }
        if (($edit['deferred'] ?? false) === true) {
            $edit = withAddConstraint($edit, registryConstraintFor($entry, $name));
        }
    }

    $candidate = descriptorWithCoordinate($text, $edit, $descriptorPath);
    $changed = $candidate['text'] !== $text;

    $report = addReport($descriptor, $descriptorPath, $edit, $candidate, $print);
    if ($candidate['status'] === 'conflict') {
        return ['ok' => false, 'reason' => (string) $candidate['detail']] + $report;
    }

    if ($changed) {
        $additionProblems = descriptorAdditionProblems($candidate['text'], $descriptorPath);
        if ($additionProblems !== []) {
            throw new \RuntimeException(
                "writing this dependency would produce a descriptor this compiler refuses:\n  "
                . \implode("\n  ", $additionProblems),
            );
        }
    }

    if ($print) {
        return $report;
    }

    if ($changed && !atomicWrite($descriptorPath, $candidate['text'])) {
        throw new \RuntimeException("cannot write {$descriptorPath}");
    }

    $updated = $descriptor;
    $updated['dependencies'][$name] = (string) $edit['value'];
    $resolution = writeResolution($updated, $descriptorPath, $registry, $catalog, null, false, false);
    $lock = $resolution['document'];

    $entries = $catalog->entries(lockedPackageNames($lock));
    foreach (badPackagesAmong($entries) as $bad => $marker) {
        \fwrite(STDERR, 'warning: ' . badPackageProblem((string) $bad, $marker) . "\n");
    }
    foreach (unmaintainedPackagesAmong($entries) as $unmaintained => $marker) {
        \fwrite(STDERR, 'warning: ' . unmaintainedPackageNote((string) $unmaintained, $marker) . "\n");
    }

    $installed = installLockedPackages($registry, $lock, $entries, $useCache, null, $log, $catalog->blobsBase());

    return [
        'written' => $changed,
        'registry' => $registry,
        'registryNpub' => shortNpub((string) ($catalog->npub() ?? '—')),
        'resolved' => (string) ($resolution['chosen'][$name] ?? ''),
        'lock' => $resolution['lockFile'],
        'dependencies' => \array_keys((array) ($lock['packages'] ?? [])),
        'installed' => $installed['results'],
        'ok' => $installed['ok'],
    ] + $report;
}

/**
 * The constraint a dependency with no `--version` records: the newest release's
 * major line.
 *
 * `0.4` is the syntax's own floor-within-a-major (`>=0.4`, and below the next
 * major), so `moggi update` may move the package within that line but not out of
 * it — the useful default for a dependency somebody just added, and a decision the
 * `--version` flag overrides.
 *
 * @param array<string, mixed> $entry
 */
function registryConstraintFor(array $entry, string $name): string
{
    $versions = \array_keys((array) ($entry['versions'] ?? []));
    if ($versions === []) {
        throw new \RuntimeException("the registry lists no released version of `{$name}`, so there is no line to record");
    }
    \usort($versions, newestFirst(...));
    $parts = \explode('.', (string) $versions[0]);

    return \count($parts) >= 2 ? $parts[0] . '.' . $parts[1] : (string) $versions[0];
}

/**
 * Declare one host coordinate, then resolve it through the ecosystem's tool.
 *
 * With `--bind`, the resolved tree is also generated into a binding (plus the
 * portable facade skeleton with `--facade`), and the `[ffi] bindings` entry is
 * recorded beside the coordinate, so `moggi build --bind` reuses the binding.
 *
 * @param array<string, mixed> $descriptor
 * @param array<string, mixed> $edit
 * @return array<string, mixed>
 */
function addHostCoordinate(array $descriptor, string $descriptorPath, string $text, array $edit, bool $print, bool $useCache, bool $bind = false, bool $facade = false): array
{
    $ecosystem = (string) $edit['ecosystem'];

    if (!\in_array($ecosystem, $descriptor['backends'], true)) {
        throw new \RuntimeException(
            "{$descriptor['name']} does not declare the `{$ecosystem}` backend ([package] backends = "
            . \implode(', ', $descriptor['backends']) . ") — add it there first, or add for one of those",
        );
    }

    $candidate = descriptorWithCoordinate($text, $edit, $descriptorPath);
    $changed = $candidate['text'] !== $text;

    $report = addReport($descriptor, $descriptorPath, $edit, $candidate, $print);
    if ($candidate['status'] === 'conflict') {
        return ['ok' => false, 'reason' => (string) $candidate['detail']] + $report;
    }

    $ffiEntry = $ecosystem . ':' . (string) $edit['package'];
    $ffi = $bind
        ? descriptorWithFfiBinding($candidate['text'], $ffiEntry)
        : ['text' => $candidate['text'], 'status' => 'present', 'line' => null];
    $ffiChanged = $ffi['text'] !== $candidate['text'];

    $boundModule = $bind ? \Moggi\Registry\Bind\bindModuleName($ecosystem, (string) $edit['package']) : null;
    $facadeModule = ($bind && $facade && $boundModule !== null)
        ? \Moggi\Registry\Bind\facadeModuleName($boundModule)
        : null;

    if ($print) {
        return [
            'ffi' => $bind ? [
                'entry' => $ffiEntry,
                'status' => $ffi['status'],
                'line' => $ffi['line'],
            ] : null,
            'binding' => $bind ? [
                'module' => $boundModule,
                'generated' => false,
            ] : null,
            'facade' => $facadeModule === null ? null : [
                'module' => $facadeModule,
                'generated' => false,
            ],
        ] + $report;
    }

    if ($changed || $ffiChanged) {
        $additionProblems = descriptorAdditionProblems($ffi['text'], $descriptorPath);
        if ($additionProblems !== []) {
            throw new \RuntimeException(
                "writing this coordinate would produce a descriptor this compiler refuses:\n  "
                . \implode("\n  ", $additionProblems),
            );
        }
    }

    $coordinates = collectHostToolCoordinates([hostToolDescriptor($candidate['text'])], $ecosystem);
    $tools = resolveHostTools($ecosystem, $coordinates, $descriptor['name'], $useCache);
    if (!$tools['ok']) {
        throw new \RuntimeException((string) $tools['detail']);
    }

    if (($changed || $ffiChanged) && !atomicWrite($descriptorPath, $ffi['text'])) {
        throw new \RuntimeException("cannot write {$descriptorPath}");
    }

    $binding = null;
    $facadePath = null;
    if ($bind) {
        $bound = \Moggi\Registry\Bind\bindForBuild($ecosystem, (string) $edit['package'], (string) $tools['dir'], $facadeModule);
        $binding = [
            'module' => $boundModule,
            'root' => $bound['root'],
            'regenerated' => $bound['regenerated'],
            'types' => $bound['types'],
            'functions' => $bound['functions'],
            'consts' => $bound['consts'],
            'skipped' => $bound['skipped'],
            'generated' => true,
        ];
        if ($facadeModule !== null) {
            $facadePath = $bound['facade'];
        }
    }

    return [
        'written' => $changed || $ffiChanged,
        'tools' => [
            'tool' => $tools['tool'],
            'version' => $tools['version'],
            'dir' => $tools['dir'],
            'cached' => $tools['cached'],
            'coordinates' => $coordinates,
            'artifacts' => $tools['artifacts'],
        ],
        'ffi' => $bind ? [
            'entry' => $ffiEntry,
            'status' => $ffi['status'],
            'line' => $ffi['line'],
        ] : null,
        'binding' => $binding,
        'facade' => $facadeModule === null ? null : [
            'module' => $facadeModule,
            'path' => $facadePath,
            'generated' => true,
        ],
    ] + $report;
}

/**
 * The report both halves start from: what the descriptor edit is.
 *
 * @param array<string, mixed> $descriptor
 * @param array<string, mixed> $edit
 * @param array<string, mixed> $candidate
 * @return array<string, mixed>
 */
function addReport(array $descriptor, string $descriptorPath, array $edit, array $candidate, bool $print): array
{
    return [
        'ok' => true,
        'package' => $descriptor['name'],
        'root' => \dirname(\realpath($descriptorPath) ?: $descriptorPath),
        'descriptor' => $descriptorPath,
        'kind' => $edit['kind'],
        'ecosystem' => $edit['ecosystem'],
        'dependency' => $edit['package'],
        'coordinate' => $edit['coordinate'],
        'section' => $edit['section'],
        'key' => $edit['key'],
        'value' => ($edit['list'] ?? false) === true ? $edit['entry'] : $edit['value'],
        'status' => $candidate['status'],
        'sectionCreated' => $candidate['sectionCreated'],
        'written' => false,
        'print' => $print,
        'diff' => addCoordinateDiff($candidate),
        'reason' => null,
        'tools' => null,
        'resolved' => null,
        'installed' => null,
        'registry' => null,
        'lock' => null,
        'ffi' => null,
        'binding' => null,
        'facade' => null,
    ];
}

/**
 * The lines the edit would write, as a `-`/`+` pair, for `--print`.
 *
 * A line-based diff would be a general algorithm for a change that is by
 * construction one line, so the edit reports its own two lines instead.
 *
 * @param array<string, mixed> $candidate
 * @return list<array{op: string, text: string}>
 */
function addCoordinateDiff(array $candidate): array
{
    $diff = [];
    if (($candidate['sectionCreated'] ?? false) === true) {
        $diff[] = ['op' => '+', 'text' => '[' . (string) $candidate['section'] . ']'];
    }
    if ($candidate['status'] === 'added' && $candidate['previous'] !== null) {
        $diff[] = ['op' => '-', 'text' => (string) $candidate['previous']];
    }
    if ($candidate['line'] !== null) {
        $diff[] = ['op' => $candidate['status'] === 'added' ? '+' : '=', 'text' => (string) $candidate['line']];
    }

    return $diff;
}

/** @param array<string, mixed> $report */
function reportAdd(array $report): void
{
    \printf("%-10s %s %s\n", 'add', (string) $report['kind'], (string) $report['coordinate']);
    \printf("%-10s %s\n", 'package', (string) $report['package']);
    \printf("%-10s %s\n", 'descriptor', (string) $report['descriptor']);
    foreach ((array) $report['diff'] as $line) {
        \printf("  %s %s\n", (string) $line['op'], (string) $line['text']);
    }

    if ($report['reason'] !== null) {
        \printf("\n%s\n", (string) $report['reason']);

        return;
    }

    if ($report['status'] === 'present') {
        echo "\nalready declared\n";
    }

    reportBindingAddition($report);

    if ($report['print']) {
        if ($report['status'] === 'added') {
            echo "\nnothing written (--print)\n";
        }

        return;
    }

    if ($report['kind'] === 'registry') {
        reportRegistryAddition($report);

        return;
    }

    $tools = $report['tools'];
    if (!\is_array($tools)) {
        return;
    }
    $count = \count((array) $tools['coordinates']);
    \printf(
        "\n%-10s %s %s — %d coordinate%s, %d artifact%s%s\n",
        'tools',
        (string) ($tools['tool'] ?? '?'),
        (string) ($tools['version'] ?? '?'),
        $count,
        $count === 1 ? '' : 's',
        \count((array) $tools['artifacts']),
        \count((array) $tools['artifacts']) === 1 ? '' : 's',
        ($tools['cached'] ?? false) === true ? ' (cached)' : '',
    );
    if (\is_string($tools['dir'])) {
        \printf("%-10s %s\n", '', $tools['dir']);
    }
    echo $report['written'] ? "\ndescriptor updated\n" : "\ndescriptor already had it\n";
}

/** @param array<string, mixed> $report */
function reportBindingAddition(array $report): void
{
    $ffi = $report['ffi'];
    if (\is_array($ffi)) {
        \printf("%-10s %s", 'ffi', (string) ($ffi['entry'] ?? ''));
        if (($ffi['status'] ?? '') === 'present') {
            echo " (already recorded)\n";
        } else {
            echo "\n";
        }
    }

    $binding = $report['binding'];
    if (\is_array($binding)) {
        \printf(
            "%-10s %s\n",
            'bind',
            (string) ($binding['module'] ?? '?')
            . (($binding['generated'] ?? false) === true ? '' : ' (planned — nothing written)'),
        );
        if (\is_string($binding['root'] ?? null)) {
            \printf("%-10s %s%s\n", '', (string) $binding['root'], (($binding['regenerated'] ?? false) === true ? '' : ' (reused)'));
            \printf(
                "%-10s %d types, %d functions, %d consts, %d skipped\n",
                '',
                (int) ($binding['types'] ?? 0),
                (int) ($binding['functions'] ?? 0),
                (int) ($binding['consts'] ?? 0),
                (int) ($binding['skipped'] ?? 0),
            );
        }
    }

    $facade = $report['facade'];
    if (\is_array($facade)) {
        \printf(
            "%-10s %s\n",
            'facade',
            (string) ($facade['module'] ?? '?')
            . (($facade['generated'] ?? false) === true ? '' : ' (planned — nothing written)'),
        );
        if (\is_string($facade['path'] ?? null)) {
            \printf("%-10s %s\n", '', (string) $facade['path']);
        }
    }
}

/** @param array<string, mixed> $report */
function reportRegistryAddition(array $report): void
{
    \printf("\n%-10s %s (%s)\n", 'registry', (string) $report['registry'], (string) $report['registryNpub']);
    \printf("%-10s %s = %s\n", 'resolved', (string) $report['dependency'], (string) $report['resolved']);
    \printf("%-10s %s\n", 'lock', (string) $report['lock']);

    foreach ((array) $report['installed'] as $result) {
        $result = \is_array($result) ? $result : [];
        \printf(
            "  %-20s %-12s %s\n",
            (string) ($result['name'] ?? '?'),
            (string) ($result['version'] ?? '?'),
            (string) ($result['status'] ?? '?'),
        );
        if (($result['status'] ?? '') === 'refused') {
            \printf("    %s\n", (string) ($result['detail'] ?? ''));
        }
    }

    echo $report['written'] ? "\ndescriptor updated\n" : "\ndescriptor already had it\n";
}
