<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Registry\FetchLog;

use function Moggi\Registry\badPackageProblem;
use function Moggi\Registry\badPackagesAmong;
use function Moggi\Registry\badPackagesRefusal;
use function Moggi\Registry\collectHostToolCoordinates;
use function Moggi\Registry\declaredBackends;
use function Moggi\Registry\fetchVerifiedRelease;
use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\hostToolCoordinates;
use function Moggi\Registry\hostToolDescriptor;
use function Moggi\Registry\isInstalled;
use function Moggi\Registry\libraryRoots;
use function Moggi\Registry\lockDisagreements;
use function Moggi\Registry\lockPath;
use function Moggi\Registry\lockRegistryProblem;
use function Moggi\Registry\lockedInstallDir;
use function Moggi\Registry\mergeThirdParty;
use function Moggi\Registry\phpExtensionDescriptors;
use function Moggi\Registry\phpExtensionProblems;
use function Moggi\Registry\providedDependencyProblems;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\readLock;
use function Moggi\Registry\resolveHostTools;
use function Moggi\Registry\thirdPartyProblems;
use function Moggi\Registry\unmaintainedPackageNote;
use function Moggi\Registry\unmaintainedPackagesAmong;

/**
 * `moggi build` — the descriptor's build front end.
 *
 * It reads the entry point, the library roots and the backend out of the
 * descriptor and **delegates to `moggi compile`**; no code generation lives here,
 * so there is one compiler and one entry point to it.
 *
 * Before compiling it re-runs `install`'s lock check and requires every locked
 * package to be unpacked. A build that quietly picked up different bytes than the
 * lock names — or linked against a missing library tree — is the failure this
 * file exists to prevent.
 */
function buildUsage(): string
{
    return <<<HELP
    usage:
      moggi build [<dir|<name>.moggi>] [options] [-- <compile options>]

    Build the descriptor's executable: read the entry point, the source
    directories and the backend from [executable], add the installed
    dependencies as library roots, and delegate to `moggi compile`.

    Refuses to build when the lock does not match the registry catalog (run
    `moggi update`), when a package the registry has marked bad would be linked,
    or when a locked package is not installed (run `moggi install`).

    options:
      --exe NAME           build [executable.NAME] instead of [executable]
      --backend B          override the descriptor's backend
      --allow-bad          build even though a linked package is marked bad
      -o, --output PATH    artifact to produce (passed to compile; with
                           --unpacked, an output directory)
      --unpacked           keep the generated tree (passed to compile)
      --registry URL|DIR   registry to re-check the lock against
      --no-cache           re-fetch metadata instead of using what is held
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runBuildCommand(array $argv): int
{
    $spec = new CommandSpec('build', buildUsage(), [
        ['name' => 'unpacked'],
        ['name' => 'noCache'],
        ['name' => 'allowBad'],
        ['name' => 'exe', 'value' => true],
        ['name' => 'backend', 'value' => true],
    ]);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $split = \array_search('--', $argv, true);
        $forwarded = $split === false ? [] : \array_slice($argv, $split + 1);
        $own = $split === false ? $argv : \array_slice($argv, 0, $split);

        $options = parseArgs($own, $spec);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    try {
        $descriptorPath = findDescriptor($options['path']);
        $descriptor = readDescriptor($descriptorPath);
        $root = \dirname($descriptorPath);

        $provided = providedDependencyProblems($descriptor['dependencies']);
        if ($provided !== []) {
            throw new \RuntimeException(\implode("\n", $provided));
        }

        $executable = selectExecutable($descriptor, $options['exe']);
        if ($executable === null) {
            throw new \RuntimeException(
                $options['exe'] !== null
                    ? "no [executable.{$options['exe']}] in {$descriptorPath}"
                    : "no [executable] in {$descriptorPath} — this package declares nothing to build",
            );
        }

        $entry = findEntryFile($executable, $root);
        if ($entry === null) {
            throw new \RuntimeException("cannot find module `{$executable['main']}` under " . \implode(', ', $executable['sourceDirs']));
        }

        $log = new FetchLog();
        $catalog = loadVerifiedCatalog($options['registry'], !$options['noCache'], $log);

        $lockFile = lockPath($descriptorPath, null);
        $lock = readLock($lockFile);
        if ($lock === null) {
            throw new \RuntimeException("no lock at {$lockFile} — run `moggi install` first");
        }
        $registryProblem = lockRegistryProblem($lock, $catalog->npub());
        if ($registryProblem !== null) {
            throw new \RuntimeException($registryProblem . ' — run `moggi update` against the registry you mean');
        }

        $entries = $catalog->entries(lockedPackageNames($lock));
        $problems = lockDisagreements($lock, $entries);
        if ($problems !== []) {
            throw new \RuntimeException(
                "the lock does not match the catalog:\n  " . \implode("\n  ", $problems)
                . "\nrun `moggi update` to re-resolve",
            );
        }

        $bad = badPackagesAmong($entries);
        if ($bad !== [] && !$options['allowBad']) {
            throw new \RuntimeException(badPackagesRefusal(
                $bad,
                'this build links',
                'pass --allow-bad to build anyway',
            ));
        }
        foreach ($bad as $name => $marker) {
            \fwrite(STDERR, 'warning: ' . badPackageProblem($name, $marker) . "\n");
        }
        foreach (unmaintainedPackagesAmong($entries) as $name => $marker) {
            \fwrite(STDERR, 'warning: ' . unmaintainedPackageNote($name, $marker) . "\n");
        }

        $backend = (string) ($options['backend'] ?? $executable['backend'] ?? 'php');
        assertBackendSupported($descriptor['name'], $descriptor['backends'], $backend, 'this package');

        if ($backend === 'php') {
            $extensionProblems = phpExtensionProblems(phpExtensionDescriptors($descriptor, $lock));
            foreach ($extensionProblems['problems'] as $problem) {
                \fwrite(STDERR, "warning: {$problem}\n");
            }
            if (\in_array('--native', $forwarded, true) && $extensionProblems['blocking'] !== []) {
                throw new \RuntimeException(
                    'a --native build needs PHP extensions the micro runtime lacks: '
                    . \implode(', ', $extensionProblems['blocking'])
                    . ' — build with a micro runtime that carries them',
                );
            }
        }

        $roots = [];
        $linked = [];
        $signedThirdParty = [];
        foreach (($lock['packages'] ?? []) as $name => $locked) {
            $name = (string) $name;
            $locked = (array) $locked;
            if (!isInstalled($name, $locked['digest'] ?? null)) {
                throw new \RuntimeException("{$name} is not installed — run `moggi install`");
            }
            $verified = fetchVerifiedRelease(
                $options['registry'],
                $name,
                (string) ($locked['version'] ?? ''),
                $locked['digest'] ?? null,
                (array) ($entries[$name] ?? []),
                !$options['noCache'],
                null,
                $log,
            );
            if (!$verified['ok']) {
                throw new \RuntimeException("{$name}: {$verified['detail']}");
            }
            $signedThirdParty[] = (array) (($verified['release']['third_party'] ?? []));
            $install = lockedInstallDir($name, $locked['digest'] ?? null);
            $descriptorFile = \rtrim($install, '/') . '/' . $name . '.moggi';
            if (\is_file($descriptorFile)) {
                $bytes = (string) \file_get_contents($descriptorFile);
                assertBackendSupported($name, declaredBackends($bytes), $backend, "installed package `{$name}`");
                foreach (libraryRoots($install, $bytes) as $libraryRoot) {
                    $roots[] = $libraryRoot;
                }
                $linked[] = hostToolDescriptor($bytes);
            }
        }

        $coordinates = collectHostToolCoordinates([$descriptor, ...$linked], $backend);
        $tools = resolveHostTools($backend, $coordinates, $descriptor['name'], !$options['noCache']);
        if (!$tools['ok']) {
            throw new \RuntimeException((string) $tools['detail']);
        }

        if ($tools['dir'] !== null && $coordinates !== []) {
            $merged = mergeThirdParty($signedThirdParty);
            if ($merged['problems'] !== []) {
                throw new \RuntimeException("the releases disagree about third-party artifacts:\n  " . \implode("\n  ", $merged['problems']));
            }
            $localCoordinates = hostToolCoordinates($descriptor, $backend);
            $mismatches = thirdPartyProblems(
                $backend,
                (string) $tools['dir'],
                (array) ($merged['map'][$backend] ?? []),
                $localCoordinates === [],
            );
            if ($mismatches !== []) {
                throw new \RuntimeException(
                    "the resolved third-party artifacts do not match the signed third_party map:\n  "
                    . \implode("\n  ", $mismatches)
                    . "\nrun `moggi install --no-cache` and rebuild, or republish the dependency that pins the wrong bytes",
                );
            }
        }

        $localRoots = \array_map(
            static fn (string $directory): string => \rtrim($root, '/') . '/' . \trim($directory, '/'),
            \array_merge(\array_slice($executable['sourceDirs'], 1), $descriptor['lib']['sourceDirs']),
        );
        $toolRoots = $tools['dir'] !== null && $tools['artifacts'] !== []
            ? [(string) $tools['dir']]
            : [];
        $libraryRoots = \array_merge($localRoots, $roots, $toolRoots);

        $compileArgv = ['moggi', 'compile', $entry];
        foreach ($libraryRoots as $libraryRoot) {
            $compileArgv[] = '--lib';
            $compileArgv[] = $libraryRoot;
        }
        $compileArgv[] = '--backend';
        $compileArgv[] = (string) $backend;
        if ($options['output'] !== null) {
            $compileArgv[] = '-o';
            $compileArgv[] = (string) $options['output'];
        }
        if ($options['unpacked']) {
            $compileArgv[] = '--unpacked';
        }
        foreach ($forwarded as $argument) {
            $compileArgv[] = $argument;
        }

        \printf(
            "build      %s %s -> %s (%s)\nentry      %s\n%slibraries   %d root%s\n",
            $descriptor['name'],
            $descriptor['version'],
            $executable['main'],
            $backend,
            $entry,
            $roots === [] ? '' : 'deps       ' . \count($roots) . " installed root(s)\n",
            \count($libraryRoots),
            \count($libraryRoots) === 1 ? '' : 's',
        );
        if ($coordinates !== []) {
            \printf(
                "tools      %s — %d coordinate%s, %d artifact%s\n           %s\n\n",
                $tools['detail'],
                \count($coordinates),
                \count($coordinates) === 1 ? '' : 's',
                \count($tools['artifacts']),
                \count($tools['artifacts']) === 1 ? '' : 's',
                (string) $tools['dir'],
            );
        } else {
            echo "tools      nothing declared for {$backend}\n\n";
        }

        return runCompileCommand($compileArgv);
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * @param array<string, mixed> $descriptor
 * @return ?array{main: string, sourceDirs: list<string>, backend: ?string}
 */
function selectExecutable(array $descriptor, ?string $name): ?array
{
    $executables = $descriptor['executables'] ?? [];
    $key = $name ?? '';
    $executable = $executables[$key] ?? null;
    if (!\is_array($executable) || $executable['main'] === '' || $executable['sourceDirs'] === []) {
        return null;
    }

    return $executable;
}

/**
 * The file a module name names: `Foo.Bar` is `Foo/Bar.mog`, looked for in the
 * executable's source directories in order.
 *
 * @param array{main: string, sourceDirs: list<string>, backend: ?string} $executable
 */
function findEntryFile(array $executable, string $root): ?string
{
    $relative = \str_replace('.', '/', $executable['main']) . '.mog';
    foreach ($executable['sourceDirs'] as $directory) {
        $candidate = \rtrim($root, '/') . '/' . \trim($directory, '/') . '/' . $relative;
        if (\is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

