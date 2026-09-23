<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/runtimes.php';
require_once __DIR__ . '/build-phar.php';
require_once __DIR__ . '/schnorr.php';
require_once __DIR__ . '/../../src/paths.php';

use function Moggi\Compiler\findExecutable;
use function Moggi\Paths\isAbsolutePath;

/**
 * Assemble the Moggi distributions.
 *
 * One target is built once — archive, launcher, docs, examples, one prepared copy
 * of every runtime it can ship — and the five distributions are derived from it by
 * copying it and adding the runtime directories a variant bundles, so nothing is
 * downloaded or built twice. The result is unpack-and-run: `bin/moggi` finds
 * `bin/moggi.phar` next to itself and puts any bundled runtime in front of `PATH`.
 *
 *   php scripts/dist/assemble.php [--target T] [--out DIR] [--variants a,b]
 *                                 [--archives] [--force-runtimes] [--glibc-floor]
 *
 * The target defaults to the host; the launcher is built with Clang (`MOGGI_DIST_CC`
 * names another one).
 */

const LAUNCHER_SOURCE = __DIR__ . '/../../launcher/moggi.c';
const EXAMPLES_EXCLUDED_SUFFIX = '.expected';

function assembleUsage(): int
{
    \fwrite(STDOUT, <<<'TEXT'
        usage: php scripts/dist/assemble.php [--target T] [--out DIR] [--variants a,b]
                                             [--archives] [--force-runtimes] [--glibc-floor]

          --target T          target to build (default: this host); one of
                              linux-x86_64 linux-aarch64 macos-x86_64 macos-aarch64
                              windows-x86_64 windows-aarch64
          --out DIR           where the distributions are staged (default: .dist)
          --variants a,b      variants to produce (default: all that this target supports)
          --archives          also write the release archives
          --force-runtimes    re-prepare cached runtimes instead of reusing them
          --glibc-floor       fail if a Linux artifact needs a newer libc than the
                              configured floor (only passes inside the pinned
                              build root, so releases pass it and local builds do not)

        TEXT);
    \fwrite(STDOUT, 'targets:  ' . \implode(' ', knownTargets(loadRuntimeConfig())) . "\n");
    \fwrite(STDOUT, 'variants: ' . \implode(' ', \array_keys(loadRuntimeConfig()['variants'])) . "\n");

    return 0;
}

function absoluteOutDir(string $dir): string
{
    if ($dir === '' || isAbsolutePath($dir)) {
        return $dir;
    }

    $cwd = \getcwd();

    return $cwd === false ? $dir : \rtrim($cwd, '/\\') . \DIRECTORY_SEPARATOR . $dir;
}

/** @return array{target: ?string, out: string, variants: list<string>, archives: bool, forceRuntimes: bool, glibcFloor: bool} */
function parseAssembleArgv(array $argv): array
{
    $repo = \dirname(__DIR__, 2);
    $options = [
        'target' => null,
        'out' => $repo . '/.dist',
        'variants' => [],
        'archives' => false,
        'forceRuntimes' => false,
        'glibcFloor' => false,
    ];

    for ($i = 1; $i < \count($argv); ++$i) {
        $arg = $argv[$i];
        $name = $arg;
        $value = null;
        if (\str_starts_with($arg, '--') && \str_contains($arg, '=')) {
            [$name, $value] = \explode('=', $arg, 2);
        }

        switch ($name) {
            case '--target':
                $options['target'] = $value ?? (string) ($argv[++$i] ?? '');
                break;
            case '--out':
                $options['out'] = $value ?? (string) ($argv[++$i] ?? '');
                break;
            case '--variants':
                $options['variants'] = variantNames($value ?? (string) ($argv[++$i] ?? ''));
                break;
            case '--archives':
                $options['archives'] = true;
                break;
            case '--force-runtimes':
                $options['forceRuntimes'] = true;
                break;
            case '--glibc-floor':
                $options['glibcFloor'] = true;
                break;
            case '--help':
            case '-h':
                exit(assembleUsage());
            default:
                throw new \RuntimeException("unknown option {$arg}");
        }
    }

    return [
        'target' => $options['target'],
        'out' => absoluteOutDir(\rtrim((string) $options['out'], '/\\')),
        'variants' => $options['variants'],
        'archives' => $options['archives'],
        'forceRuntimes' => $options['forceRuntimes'],
        'glibcFloor' => $options['glibcFloor'],
    ];
}

/** @return list<string> */
function variantNames(string $list): array
{
    return \array_values(\array_filter(\array_map('trim', \explode(',', $list)), static fn (string $n): bool => $n !== ''));
}

/** The configured target this machine is, so a local build needs no arguments. */
function hostTarget(array $config): string
{
    $os = match (\PHP_OS_FAMILY) {
        'Linux' => 'linux',
        'Darwin' => 'macos',
        'Windows' => 'windows',
        default => throw new \RuntimeException('unsupported host OS ' . \PHP_OS_FAMILY),
    };
    $machine = \strtolower(\php_uname('m'));
    $arch = match (true) {
        \in_array($machine, ['x86_64', 'amd64', 'x64'], true) => 'x86_64',
        \in_array($machine, ['aarch64', 'arm64'], true) => 'aarch64',
        default => throw new \RuntimeException('unsupported host architecture ' . $machine),
    };

    foreach ($config['targets'] as $target => $coords) {
        if ($coords['os'] === $os && $coords['arch'] === $arch) {
            return (string) $target;
        }
    }

    throw new \RuntimeException("no target is configured for {$os}-{$arch}");
}

/**
 * The variants to produce, in the config's order, each with the runtimes it
 * bundles and the reason it cannot be produced when one is missing.
 *
 * @return list<array{variant: string, runtimes: list<string>, reason: ?string}>
 */
function variantPlan(array $config, string $target, array $requested): array
{
    $plan = [];
    foreach ($config['variants'] as $variant => $spec) {
        if ($requested !== [] && !\in_array($variant, $requested, true)) {
            continue;
        }
        $runtimes = [];
        $reason = null;
        foreach ((array) $spec['runtimes'] as $runtime) {
            $unsupported = unsupportedReason($runtime, $target, $config);
            if ($unsupported !== null) {
                $reason ??= "{$runtime}: {$unsupported}";

                continue;
            }
            if (runtimeAsset($runtime, $target, $config) === null) {
                $reason ??= "{$runtime}: no asset configured for {$target}";

                continue;
            }
            $runtimes[] = $runtime;
        }
        $plan[] = ['variant' => (string) $variant, 'runtimes' => $runtimes, 'reason' => $reason];
    }

    if ($requested !== []) {
        $known = \array_column($plan, 'variant');
        foreach ($requested as $variant) {
            if (!\in_array($variant, $known, true)) {
                throw new \RuntimeException("unknown variant {$variant}");
            }
        }
    }

    return $plan;
}

function stageBaseInstallation(string $repo, string $base, string $target): void
{
    removeTree($base);
    foreach (['bin', 'docs', 'examples', 'lib'] as $dir) {
        if (!\mkdir($base . '/' . $dir, 0777, true) && !\is_dir($base . '/' . $dir)) {
            throw new \RuntimeException("cannot create {$base}/{$dir}");
        }
    }

    buildCompilerPhar($repo, $base . '/bin/moggi.phar');

    copyTree($repo . '/lib', $base . '/lib');
    copyTree($repo . '/docs', $base . '/docs');
    copyTree(
        $repo . '/examples',
        $base . '/examples',
        static fn (string $relative): bool => !\str_ends_with($relative, EXAMPLES_EXCLUDED_SUFFIX),
    );

    if (!\copy($repo . '/LICENSE', $base . '/LICENSE')) {
        throw new \RuntimeException('cannot copy LICENSE');
    }

    buildLauncher($repo, $base . '/bin/' . launcherFileName($target), $target);
    stageSchnorr($repo, $base . '/bin', $target);
    \fwrite(
        STDOUT,
        "  compiler archive, launcher, schnorr, standard library, docs, examples, LICENSE\n",
    );
}

function launcherFileName(string $target): string
{
    return (\PHP_OS_FAMILY === 'Windows' && \str_starts_with($target, 'windows-')) ? 'moggi.exe' : 'moggi';
}

function schnorrFileName(string $target): string
{
    return \str_starts_with($target, 'windows-') ? 'schnorr.exe' : 'schnorr';
}

function stageSchnorr(string $repo, string $binDir, string $target): void
{
    $name = schnorrFileName($target);
    if (!\copy(buildSchnorr($repo, \str_starts_with($target, 'windows-')), $binDir . '/' . $name)) {
        throw new \RuntimeException("cannot write {$binDir}/{$name}");
    }
    @\chmod($binDir . '/' . $name, 0755);
}

function buildLauncher(string $repo, string $out, string $target): void
{
    $wanted = \getenv('MOGGI_DIST_CC');
    $wanted = \is_string($wanted) && $wanted !== '' ? $wanted : 'clang';
    $clang = findExecutable($wanted);
    if ($clang === null) {
        throw new \RuntimeException(
            "a C11 compiler is needed to build the launcher, and `{$wanted}` is not on PATH.\n"
            . 'install LLVM/Clang, or point MOGGI_DIST_CC at another compiler',
        );
    }
    unset($target);

    /* Windows: `CommandLineToArgvW` is in shell32, which no C runtime links by default. */
    $windows = \PHP_OS_FAMILY === 'Windows' ? ['-lshell32'] : [];
    runOrFail([$clang, '-std=c11', '-O2', '-Wall', '-Wextra', '-o', $out, LAUNCHER_SOURCE, ...$windows]);
    @\chmod($out, 0755);
}

/**
 * A bundled runtime has to arrive with the terms it is distributed under.
 *
 * @param list<string> $runtimes
 */
function assertBundledLicenses(string $stage, array $runtimes, array $config, string $repo): void
{
    foreach ($runtimes as $runtime) {
        $file = $config['runtimes'][$runtime]['license']['file'] ?? null;
        if (\is_string($file) && $file !== '' && !\is_file($stage . '/runtime/' . $runtime . '/' . $file)) {
            throw new \RuntimeException("the bundled {$runtime} carries no {$file}");
        }
    }

    foreach (schnorrDependencies($repo) as $name => $dep) {
        if (!\is_file(schnorrDependencyDir($repo, $name) . '/' . $dep['license']['file'])) {
            throw new \RuntimeException("{$name} carries no {$dep['license']['file']}");
        }
    }
}

/** @param list<string> $runtimes */
function thirdPartyNotices(array $runtimes, string $target, array $config, string $repo): string
{
    $lines = ['# Third-party notices', ''];

    if ($runtimes !== []) {
        $lines[] = 'The runtimes below are bundled as the unmodified upstream builds;';
        $lines[] = 'their own licence files sit beside them in `runtime/<name>/`.';
        $lines[] = '';

        foreach ($runtimes as $runtime) {
            $spec = $config['runtimes'][$runtime] ?? [];
            $marker = \json_decode((string) @\file_get_contents(preparedRuntimeDir($runtime, $target) . '/.prepared'), true);
            $lines[] = \sprintf(
                '- **%s** %s — %s (%s)',
                $runtime,
                (string) ($marker['version'] ?? $spec['version'] ?? ''),
                (string) ($spec['license']['name'] ?? 'see runtime/' . $runtime),
                (string) ($spec['upstream'] ?? ''),
            );
        }

        $acknowledgements = [];
        foreach ($runtimes as $runtime) {
            $acknowledgement = (string) ($config['runtimes'][$runtime]['license']['acknowledgement'] ?? '');
            if ($acknowledgement !== '') {
                $acknowledgements[] = $acknowledgement;
            }
        }
        if ($acknowledgements !== []) {
            $lines[] = '';
            foreach ($acknowledgements as $acknowledgement) {
                $lines[] = $acknowledgement;
                $lines[] = '';
            }
        }

        $lines[] = '';
    }

    $lines[] = '`bin/schnorr` is built from this project\'s own source and statically links';
    $lines[] = 'the libraries below, fetched at the pinned revisions and not part of this';
    $lines[] = 'distribution:';

    foreach (schnorrDependencies($repo) as $name => $dep) {
        $text = (string) @\file_get_contents(schnorrDependencyDir($repo, $name) . '/' . $dep['license']['file']);
        $lines[] = '';
        $lines[] = \sprintf('## %s — %s', $name, $dep['license']['name']);
        $lines[] = '';
        $lines[] = '```text';
        foreach (\preg_split('/\R/', \rtrim($text)) ?: [] as $licenceLine) {
            $lines[] = $licenceLine;
        }
        $lines[] = '```';
    }

    return \implode("\n", $lines);
}

/** @param list<string> $runtimes */
function distributionReadme(string $variant, string $target, array $runtimes, string $version): string
{
    $bundled = $runtimes === []
        ? 'No runtime is bundled: `moggi` uses the `php` it finds on `PATH`, plus `java`/`javac` '
            . 'for the JVM backend and `dotnet` for the .NET backend.'
        : 'Bundled runtimes: ' . \implode(', ', \array_map(
            static fn (string $runtime): string => match ($runtime) {
                'php' => 'PHP',
                'dotnet' => '.NET SDK',
                'jvm' => 'JDK',
                'graalvm' => 'GraalVM (native-image)',
                default => $runtime,
            },
            $runtimes,
        )) . '. They are used in preference to any runtime on `PATH`.';

    return <<<TEXT
        # Moggi {$version} — {$variant} for {$target}

        Moggi is a general-purpose, purely functional, strictly evaluated
        programming language that compiles to PHP, JVM and .NET.

        This is a standalone installation. Unpack it and run `bin/moggi` — there
        is nothing to install, configure or build.

        {$bundled}

        ## First steps

        ```bash
        bin/moggi version                       # what is in this installation
        bin/moggi run examples/factorial        # compile and run an example
        bin/moggi repl                          # try expressions interactively
        bin/moggi run examples/host-php         # call the host from Moggi
        ```

        Compile to an artifact you can deploy on its own:

        ```bash
        bin/moggi compile examples/factorial -o factorial.phar
        php factorial.phar
        bin/moggi compile examples/factorial --backend jvm -o factorial.jar
        java -jar factorial.jar
        ```

        ## Where to go next

        * `docs/quickstart.md` — install, build, run, in one page
        * `docs/tour.md` — the language: types, classes, IO, modules
        * `docs/repl.md` — the interactive environment and the compiler's stage flags
        * `docs/stdlib.md` — finding your way around the standard library
        * `docs/ffi.md` — calling PHP, Java and .NET from Moggi
        * `docs/README.md` — the full documentation index

        Examples live in `examples/`; each one is a complete program. The
        standard library is the `lib/` tree: ordinary Moggi sources, covered by
        this installation's LICENSE, there to be read. `bin/schnorr` is a
        standalone BIP-340 CLI that ships with the toolchain; nothing in Moggi
        uses it yet.


        TEXT;
}

/**
 * @param list<string> $runtimes
 * @return list<string> what was checked, for the report
 */
function smokeTest(string $stage, string $target, array $runtimes, string $work): array
{
    $launcher = $stage . '/bin/' . launcherFileName($target);
    if (!\is_file($launcher)) {
        throw new \RuntimeException("no launcher at {$launcher}");
    }

    removeTree($work);
    if (!\mkdir($work, 0777, true) && !\is_dir($work)) {
        throw new \RuntimeException("cannot create {$work}");
    }

    $env = \array_diff_key(\getenv(), ['MOGGI_ROOT' => '']);

    $checked = [];
    [$code, $stdout, $stderr] = runProcess([$launcher, 'version'], $work, $env);
    if ($code !== 0 || !\str_contains($stdout, 'compiler:')) {
        throw new \RuntimeException("`moggi version` failed ({$code}): " . \trim($stdout . ' ' . $stderr));
    }
    $checked[] = 'version';

    $schnorr = $stage . '/bin/' . schnorrFileName($target);
    [$code, $stdout, $stderr] = runProcess([$schnorr, 'generate'], $work, $env);
    if ($code !== 0 || !\str_starts_with(\trim($stdout), 'nsec1')) {
        throw new \RuntimeException(
            "the bundled schnorr CLI failed ({$code})\n" . \substr(\trim($stdout . "\n" . $stderr), -2000),
        );
    }
    $checked[] = 'schnorr';

    $example = $stage . '/examples/factorial';
    foreach (['php', 'jvm', 'dotnet'] as $backend) {
        if ($backend !== 'php' && !\in_array($backend, $runtimes, true)) {
            continue;
        }
        $args = [$launcher, 'run', $example];
        if ($backend !== 'php') {
            $args[] = '--backend';
            $args[] = $backend;
        }
        [$code, $stdout, $stderr] = runProcess($args, $work, $env);
        if ($code !== 0 || !\str_contains($stdout, '3628800')) {
            throw new \RuntimeException(
                "running examples/factorial on {$backend} failed ({$code})\n"
                . \substr(\trim($stdout . "\n" . $stderr), -2000),
            );
        }
        $checked[] = 'run ' . $backend;
    }

    $artifact = $work . '/factorial.phar';
    [$code, $stdout, $stderr] = runProcess([$launcher, 'compile', $example, '-o', $artifact], $work, $env);
    if ($code !== 0 || !\is_file($artifact)) {
        throw new \RuntimeException(
            "packaging a PHAR failed ({$code})\n" . \substr(\trim($stdout . "\n" . $stderr), -2000),
        );
    }

    [$code, $stdout, $stderr] = runProcess([\PHP_BINARY, $artifact], $work, $env);
    if ($code !== 0 || !\str_contains($stdout, '3628800')) {
        throw new \RuntimeException(
            "running the packaged PHAR failed ({$code})\n" . \substr(\trim($stdout . "\n" . $stderr), -2000),
        );
    }
    $checked[] = 'compile phar';

    removeTree($work);

    return $checked;
}

/** Write one release archive, with the variant as the archive's top-level directory. */
function createArchive(string $stage, string $archive, string $format): void
{
    $parent = \dirname($stage);
    $name = \basename($stage);
    @\unlink($archive);

    if ($format === 'zip') {
        [$code] = runProcess(['zip', '-q', '-r', '-X', $archive, $name], $parent);
        if ($code !== 0) {
            runOrFail(['tar', '-a', '-c', '-f', $archive, '-C', $parent, $name]);
        }

        return;
    }

    runOrFail(['tar', '-c', '-z', '-f', $archive, '-C', $parent, $name]);
}

function archiveName(string $variant, string $version, string $target): string
{
    $format = \str_starts_with($target, 'windows-') ? 'zip' : 'tar.gz';

    return "{$variant}-{$version}-{$target}.{$format}";
}

function assembleMain(array $argv): int
{
    $options = parseAssembleArgv($argv);
    $config = loadRuntimeConfig();
    $repo = \dirname(__DIR__, 2);
    $target = $options['target'] ?? hostTarget($config);
    if (!isset($config['targets'][$target])) {
        throw new \RuntimeException("unknown target {$target}");
    }
    if ($options['out'] === '' || $options['out'] === '/') {
        throw new \RuntimeException('refusing to use that as the output directory');
    }

    $version = compilerBuildVersion($repo);
    $out = $options['out'] . '/' . $target;
    $base = $out . '/base';

    \fwrite(STDOUT, "target:  {$target}\n");
    \fwrite(STDOUT, "version: {$version}\n");
    \fwrite(STDOUT, "out:     {$out}\n\n");

    $plan = variantPlan($config, $target, $options['variants']);

    \fwrite(STDOUT, "staging the base installation\n");
    stageBaseInstallation($repo, $base, $target);

    $needed = [];
    foreach ($plan as $entry) {
        $needed = [...$needed, ...$entry['runtimes']];
    }
    $needed = \array_values(\array_unique($needed));
    if ($needed !== []) {
        \fwrite(STDOUT, "\npreparing runtimes: " . \implode(', ', $needed) . "\n");
        foreach ($needed as $runtime) {
            $prepared = prepareRuntime($runtime, $target, $config, $options['forceRuntimes']);
            $marker = \json_decode((string) @\file_get_contents($prepared . '/.prepared'), true);
            \fwrite(STDOUT, \sprintf(
                "  %-8s %s%s\n",
                $runtime,
                (string) ($marker['version'] ?? '?'),
                ($marker['built'] ?? false) === true ? ' (built from source)' : '',
            ));
        }
    }

    if ($options['glibcFloor'] && \str_starts_with($target, 'linux-')) {
        $floor = glibcFloor($config);
        \fwrite(STDOUT, "\nchecking the glibc {$floor} floor\n");
        assertGlibcFloor($base . '/bin/' . launcherFileName($target), $floor, 'the launcher');
        assertGlibcFloor($base . '/bin/' . schnorrFileName($target), $floor, 'schnorr');
        foreach ($needed as $runtime) {
            $dir = preparedRuntimeDir($runtime, $target);
            $marker = \json_decode((string) @\file_get_contents($dir . '/.prepared'), true);
            $binary = (string) ($marker['binary'] ?? '');
            if ($binary !== '') {
                assertGlibcFloor($dir . '/' . $binary, $floor, "the bundled {$runtime}");
            }
        }
        \fwrite(STDOUT, "  launcher and bundled runtimes are within the floor\n");
    }

    $report = [];
    $failures = [];
    $archives = 0;
    \fwrite(STDOUT, "\nassembling\n");
    if ($options['archives']) {
        \fwrite(STDOUT, 'archives go to: ' . $out . "\n");
    }
    foreach ($plan as $entry) {
        $variant = $entry['variant'];
        $stage = $out . '/' . $variant;

        if ($entry['reason'] !== null) {
            $report[] = \sprintf('%-14s not produced  %s', $variant, $entry['reason']);
            continue;
        }

        removeTree($stage);
        copyTree($base, $stage);
        foreach ($entry['runtimes'] as $runtime) {
            copyTree(
                preparedRuntimeDir($runtime, $target),
                $stage . '/runtime/' . $runtime,
                static fn (string $relative, string $name): bool => $name !== '.prepared',
            );
        }
        \file_put_contents(
            $stage . '/README.md',
            distributionReadme($variant, $target, $entry['runtimes'], $version),
        );
        \file_put_contents(
            $stage . '/THIRD-PARTY-NOTICES.md',
            thirdPartyNotices($entry['runtimes'], $target, $config, $repo),
        );

        try {
            assertBundledLicenses($stage, $entry['runtimes'], $config, $repo);
            $checked = \implode(', ', smokeTest($stage, $target, $entry['runtimes'], $out . '/.smoke'));
        } catch (\Throwable $e) {
            $failures[] = "{$variant}: " . $e->getMessage();
            $checked = 'FAILED';
        }

        $archive = null;
        if ($options['archives'] && $checked !== 'FAILED') {
            $archive = $out . '/' . archiveName($variant, $version, $target);
            createArchive($stage, $archive, \str_starts_with($target, 'windows-') ? 'zip' : 'tar.gz');
            ++$archives;
        }

        $report[] = \sprintf(
            '%-14s ok   runtimes: %-28s %s%s',
            $variant,
            $entry['runtimes'] === [] ? 'none' : \implode(', ', $entry['runtimes']),
            $checked,
            $archive !== null ? '  → ' . \basename($archive) . ' (' . \number_format(\filesize($archive) / 1048576, 1) . ' MiB)' : '',
        );
    }

    \fwrite(STDOUT, \implode("\n", $report) . "\n");

    removeTree($base);

    if ($failures !== []) {
        \fwrite(STDERR, "\n" . \implode("\n", $failures) . "\n");

        return 1;
    }

    if ($options['archives'] && $archives === 0) {
        \fwrite(STDERR, "\nerror: no archive was produced for {$target} under {$out}\n");

        return 1;
    }

    return 0;
}

/**
 * `phar.readonly` cannot be changed at runtime, so this re-runs itself with it off.
 */
function reexecForPharWrites(array $argv): void
{
    if (\ini_get('phar.readonly') === '0') {
        return;
    }

    $command = [\PHP_BINARY, '-d', 'phar.readonly=0', '-d', 'phar.require_hash=0', __FILE__, ...\array_slice($argv, 1)];
    $process = \proc_open($command, [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes);
    if (!\is_resource($process)) {
        throw new \RuntimeException('cannot re-execute the assembler with PHAR writes allowed');
    }

    exit(\proc_close($process));
}

if (\realpath($argv[0] ?? '') === \realpath(__FILE__)) {
    try {
        reexecForPharWrites($argv);
        exit(assembleMain($argv));
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        exit(1);
    }
}
