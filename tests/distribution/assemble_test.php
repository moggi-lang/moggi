#!/usr/bin/env php
<?php declare(strict_types=1);

// A distribution is what users actually get, so its shape is asserted rather
// than assumed: `bin/moggi` *is* the compiler (the micro PHP runtime with the
// archive appended), the archive is not shipped where PHP is not, no compiler
// sources beside it, user-facing documentation only, examples that run, and an
// archive that unpacks somewhere clean and works from there.
//
// The variant built here is `moggi-minimal`, which bundles no runtime: the
// compiler still runs itself natively, which is the whole point. The variants
// that bundle toolchains are built and tested by the packaging workflow, which
// has to prepare them anyway.
//
// `bin/moggi` is built from the micro PHP runtime, and that runtime is compiled
// rather than downloaded — so a machine that has not prepared it cannot assemble
// any distribution, and the assembly half of this test is skipped there. The
// resolution and preflight checks above it run everywhere.

$root = __DIR__;
while (!is_file($root . '/packaging/assemble.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/distribution.php';
require_once $root . '/packaging/assemble.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$version = \trim((string) \file_get_contents($root . '/VERSION'));
$work = createTempDir('moggi-distribution');
// Canonical before anything is derived from it: the distribution reports the paths it resolves,
// and macOS reaches its temporary directory through a symlink.
$work = \realpath($work) ?: $work;
$out = $work . '/out';
$exe = \PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

// Schnorr is built from C at assembly time, so a machine with no C compiler
// cannot produce a distribution.
$compiler = distributionCompiler();
if ($compiler === null) {
    \fwrite(STDERR, "distribution test: no C compiler on PATH (tried clang, cc, gcc)\n");
    exit(1);
}

$runtimeConfig = \Moggi\Dist\loadRuntimeConfig();
$locked = \Moggi\Dist\lockedRuntimeNames($runtimeConfig);

$assert(
    \in_array('php-native', \Moggi\Dist\pinnedRuntimeNames(), true) && \in_array('spc', \Moggi\Dist\TOOL_NAMES, true),
    'the micro runtime and its build tool must be pinned runtimes',
);
$assert(
    !\in_array('php-native', $locked, true) && \in_array('spc', $locked, true),
    'a derived runtime has no lock entry; its build tool does',
);
// The lock records what each target truly fetches, so a key naming a runtime or
// target the configuration no longer defines is drift: it would be handed to the
// assembler as an asset for a build that can no longer exist.
$lock = \Moggi\Dist\loadRuntimeLock();
$knownTargets = \Moggi\Dist\knownTargets($runtimeConfig);
$strayKeys = \array_values(\array_filter(
    \array_keys($lock),
    static function (string $key) use ($locked, $knownTargets): bool {
        [$runtime, $target] = \explode('/', $key, 2) + ['', ''];

        return !\in_array($runtime, $locked, true) || !\in_array($target, $knownTargets, true);
    },
));
$assert(
    $strayKeys === [],
    'every lock entry must name a locked runtime and a configured target (stray: ' . \implode(', ', $strayKeys) . ')',
);
$assert(\Moggi\Dist\buildRecipe('php-native', $runtimeConfig) !== null, 'php-native must be a derived runtime');
$assert(
    \Moggi\Dist\runtimeAsset('php-native', 'linux-x86_64', $runtimeConfig) === null,
    'a derived runtime has no upstream asset to fetch',
);
$spc = \Moggi\Dist\runtimeAsset('spc', 'linux-x86_64', $runtimeConfig);
$assert(\is_array($spc) && ($spc['format'] ?? '') === 'tar.gz', 'the spc build tool must be a pinned download');
$assert(
    \str_contains((string) \Moggi\Dist\unsupportedReason('php-native', 'windows-aarch64', $runtimeConfig), 'ARM64'),
    'php-native is only as available as spc, which has no Windows ARM64 build',
);
$assert(
    !\in_array('windows-aarch64', \Moggi\Dist\knownTargets($runtimeConfig), true),
    'Windows ARM64 is not a target: the compiler itself cannot be built there',
);
$assert(
    \Moggi\Dist\unsupportedReason('zig', 'macos-x86_64', $runtimeConfig) !== null,
    'the Zig toolchain is a Linux-only build input',
);
// The C-toolchain preflight fails before the build, naming the build that needed
// it, rather than deep inside `configure`. An empty PATH is a host with none.
$noToolchain = $work . '/no-toolchain';
\mkdir($noToolchain, 0777, true);
$realPath = \getenv('PATH');
\putenv('PATH=' . $noToolchain);
try {
    \Moggi\Dist\assertCToolchain('linux-x86_64', 'building the bundled php from source');
    $assert(false, 'a build without a C toolchain must fail before it starts');
} catch (\RuntimeException $e) {
    $assert(
        \str_contains($e->getMessage(), 'C toolchain') && \str_contains($e->getMessage(), 'from source'),
        'the C-toolchain error must name the build that needed it: ' . $e->getMessage(),
    );
} finally {
    \putenv('PATH=' . ($realPath === false ? '' : $realPath));
}

$host = \Moggi\Dist\hostTarget($runtimeConfig);
if (!\Moggi\Dist\runtimeIsPrepared('php-native', $host, $runtimeConfig)) {
    \fwrite(
        STDOUT,
        "distribution: skipped the assembly — php-native for {$host} is not prepared,"
        . " and bin/moggi is built from it (it is compiled, not downloaded)\n",
    );
    removeDirectory($work);
    exit(0);
}

try {
    // `--out` relative to a cwd that is not the staged tree, which is what CI passes.
    $relativeOut = \basename($work) . '/out';
    $built = runCompiledProcess(
        [\PHP_BINARY, $root . '/packaging/assemble.php', '--variants', 'moggi-minimal', '--archives', '--out', $relativeOut],
        900,
        \getenv(),
        \dirname($work),
    );
    if ($built['exitCode'] !== 0) {
        \fwrite(STDERR, "assembling failed:\n" . $built['stdout'] . $built['stderr']);
        exit(1);
    }

    $targets = \array_values(\array_filter(\glob($out . '/*') ?: [], 'is_dir'));
    $assert(\count($targets) === 1, 'exactly one target must be staged');
    $target = \basename($targets[0]);
    $stage = $targets[0] . '/moggi-minimal';
    $compilerExe = $stage . '/bin/moggi' . $exe;

    $assert(\is_file($compilerExe), 'bin/moggi' . $exe . ' must exist');
    $assert(\is_executable($compilerExe), 'the compiler must be executable');
    $assert(
        \Moggi\Dist\isNativeBinary($compilerExe),
        'bin/moggi must be the native compiler, not a script',
    );
    $assert(
        !\is_file($stage . '/bin/moggi.phar'),
        'a variant that bundles no PHP must not ship the compiler archive',
    );
    $assert(\is_file($stage . '/bin/schnorr' . $exe), 'bin/schnorr' . $exe . ' must ship beside the compiler');
    $assert(\is_executable($stage . '/bin/schnorr' . $exe), 'the bundled schnorr must be executable');

    foreach (['src', 'tests', 'scripts', 'dist', 'launcher', 'editors', '.git', '.moggi'] as $leak) {
        $assert(!\file_exists($stage . '/' . $leak), "a distribution must not contain {$leak}");
    }
    foreach (['moggi.php', 'test.php', 'flake.nix', 'composer.json', 'VERSION', '.envrc'] as $file) {
        $assert(!\file_exists($stage . '/' . $file), "a distribution must not contain {$file}");
    }
    /** Every file under a tree that matches a predicate. */
    $find = static function (string $dir, callable $matches) use (&$find): array {
        $found = [];
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (\is_dir($path)) {
                $found = [...$found, ...$find($path, $matches)];
            } elseif ($matches($entry)) {
                $found[] = $path;
            }
        }

        return $found;
    };
    $stray = $find($stage, static fn (string $name): bool => \str_ends_with($name, '.phar'));
    $assert($stray === [], 'a variant with no PHP ships no archive: ' . \implode(', ', $stray));

    // The standard library ships as ordinary sources beside the compiler, not
    // inside it: users can read it, and the installation's LICENSE covers it.
    $assert(\is_file($stage . '/lib/Data/Eq.mog'), 'the standard library sources must be shipped');
    $assert(\is_file($stage . '/lib/base.moggi'), 'the standard library must carry its manifest');

    // The compiler runs itself: no PHP install stands in front of it, and none is
    // on PATH for this run. `version` reports the variant from what is bundled.
    $noRuntimes = $work . '/no-runtimes';
    \mkdir($noRuntimes, 0777, true);
    $env = ['PATH' => $noRuntimes] + \getenv();
    unset($env['JAVA_HOME'], $env['DOTNET_ROOT'], $env['GRAALVM_HOME'], $env['JDK_HOME'], $env['MOGGI_ROOT']);
    $reported = runCompiledProcess([$compilerExe, 'version'], 120, $env, $work);
    $assert($reported['exitCode'] === 0, 'the native compiler must run with no PHP on PATH: ' . $reported['stderr']);
    $assert(
        \preg_match('/^compiler:\s+' . \preg_quote($version, '/') . '/m', $reported['stdout']) === 1,
        'the native compiler must report its version: ' . $reported['stdout'],
    );
    foreach (['php', 'java', 'dotnet', 'native-image'] as $tool) {
        $assert(
            \preg_match('/^' . \preg_quote($tool, '/') . ':\s+\S/m', $reported['stdout']) === 1,
            "a toolchain field must never be empty ({$tool}): " . $reported['stdout'],
        );
    }

    $json = runCompiledProcess([$compilerExe, 'version', '--json'], 120, $env, $work);
    $info = \json_decode($json['stdout'], true);
    $assert($json['exitCode'] === 0 && \is_array($info), '`version --json` must emit JSON: ' . $json['stdout']);
    $assert(
        \in_array($info['channel'] ?? null, ['dev', 'release'], true),
        'a packaged build reports a packaged channel: ' . $json['stdout'],
    );
    $assert(($info['commit'] ?? null) !== null || ($info['channel'] ?? '') === 'release', 'a dev build names the commit it came from: ' . $json['stdout']);
    $assert(($info['variant'] ?? null) === 'moggi-minimal', 'the variant is derived from what is bundled: ' . $json['stdout']);

    // A real compile with no PHP anywhere on PATH: writing the archive is
    // in-process, which is what the INI block in the executable is for.
    $artifact = $work . '/factorial.phar';
    $compiled = runCompiledProcess(
        [$compilerExe, 'compile', $stage . '/examples/factorial', '-o', $artifact],
        300,
        $env,
        $work,
    );
    $assert($compiled['exitCode'] === 0, 'compiling with no PHP on PATH must work: ' . $compiled['stdout'] . $compiled['stderr']);
    $assert(\is_file($artifact), 'the compiler must write the archive');

    // The artifact is an ordinary PHAR, run by any PHP (here, the build host's).
    $ranPhar = runCompiledProcess([\PHP_BINARY, $artifact], 120, null, $work);
    $assert($ranPhar['exitCode'] === 0, 'the packaged archive must run: ' . $ranPhar['stderr']);
    $assert(\str_contains($ranPhar['stdout'], '3628800'), 'the packaged archive must produce its output: ' . $ranPhar['stdout']);

    // The version a distribution reports is derived from git, which is not
    // exercised by the build above unless the checkout happens to be tagged.
    if (distributionFindExecutable('git') !== null) {
        require_once $root . '/packaging/build-phar.php';

        $scratch = createTempDir('moggi-build-version');
        \file_put_contents($scratch . '/VERSION', "1.2.3\n");
        $git = static function (array $args) use ($scratch): void {
            \exec(
                'git -C ' . \escapeshellarg($scratch)
                . ' -c user.email=t@example.com -c user.name=test'
                . ' -c commit.gpgsign=false -c tag.gpgsign=false '
                . \implode(' ', \array_map('escapeshellarg', $args)),
                $output,
                $code,
            );
            if ($code !== 0) {
                throw new \RuntimeException('git ' . \implode(' ', $args) . ' failed');
            }
        };
        $git(['init', '-q']);
        $git(['add', 'VERSION']);
        $git(['commit', '-q', '-m', 'one']);

        $dev = \Moggi\Dist\compilerBuildVersion($scratch);
        $assert(\str_starts_with($dev, '1.2.3-dev.'), 'an untagged build is a dev build: ' . $dev);
        $assert(\preg_match('/\+[0-9a-f]{7,}/', $dev) === 1, 'a dev build names its commit: ' . $dev);

        $git(['tag', '1.2.3']);
        $assert(\Moggi\Dist\compilerBuildVersion($scratch) === '1.2.3', 'a tagged build reports the tag');

        \file_put_contents($scratch . '/VERSION', "1.2.3 \n");
        $assert(\str_ends_with(\Moggi\Dist\compilerBuildVersion($scratch), '.dirty'), 'a modified tree is marked dirty');
    }

    // Documentation ships whole, and every link inside it still resolves — an index that points at pages the
    // archive does not contain is worse than no index.
    $assert(\is_file($stage . '/docs/quickstart.md'), 'the quickstart must be shipped');
    $assert(\is_file($stage . '/docs/README.md'), 'the documentation index must be shipped');
    $assert(\is_file($stage . '/docs/development/architecture.md'), 'the documentation index must not dangle');

    $dangling = [];
    foreach ($find($stage . '/docs', static fn (string $name): bool => \str_ends_with($name, '.md')) as $page) {
        \preg_match_all('/\]\(([^)\s]+)\)/', (string) \file_get_contents($page), $matches);
        foreach ($matches[1] as $link) {
            if (\str_starts_with($link, '#') || \str_contains($link, '://') || \str_starts_with($link, 'mailto:')) {
                continue;
            }
            $linked = \substr($link, 0, (int) \strcspn($link, '#'));
            if (!\file_exists(\dirname($page) . '/' . $linked)) {
                $dangling[] = \substr($page, \strlen($stage) + 1) . ' -> ' . $link;
            }
        }
    }
    $assert($dangling === [], 'shipped documentation must not link to what is not shipped: ' . \implode(', ', $dangling));

    // A deliberate decision, not a copy: the repository README documents the
    // compiler's development, so the archive gets its own front page instead.
    $assert(\is_file($stage . '/README.md'), 'a distribution README must be written');
    $readme = (string) \file_get_contents($stage . '/README.md');
    $assert(\str_contains($readme, 'moggi-minimal'), 'the README must describe the variant it is in');
    $assert(\str_contains($readme, 'bin/moggi'), 'the README must show how to run it');
    $assert(!\str_contains($readme, 'bin/moggi.phar'), 'a README for a PHP-less variant must not name an archive');
    $assert(!\str_contains($readme, 'nix develop'), 'the README must not send users to a development shell');
    $assert(\str_contains($readme, 'No runtime is bundled'), 'the minimal README must say that nothing is bundled');

    $assert(\is_file($stage . '/LICENSE'), 'the licence must be shipped');
    $assert(\is_file($stage . '/examples/factorial/Main.mog'), 'the examples must be shipped');
    $assert(($find($stage . '/examples', static fn (string $name): bool => \str_ends_with($name, '.expected')) === []), 'test goldens are not examples');

    // Nothing bundled in the minimal variant, and no empty `runtime/` pretending
    // otherwise. The schnorr CLI still links two libraries statically, so its
    // notices ship in every variant, this one included.
    $assert(!\file_exists($stage . '/runtime'), 'moggi-minimal must bundle no runtime');
    $assert(\is_file($stage . '/THIRD-PARTY-NOTICES.md'), 'the licences of what schnorr links must ship');
    $notices = (string) \file_get_contents($stage . '/THIRD-PARTY-NOTICES.md');
    foreach (['libsecp256k1-0.8.0', 'libbech32-1.1p1', 'Permission is hereby granted'] as $required) {
        $assert(\str_contains($notices, $required), "the notices must carry {$required}");
    }

    // The installation runs from somewhere else entirely, using the host's PHP
    // for the compiled program (the compiler itself needs none).
    $elsewhere = $work . '/elsewhere';
    \mkdir($elsewhere, 0777, true);
    $ran = runCompiledProcess([$compilerExe, 'run', $stage . '/examples/factorial'], 300, null, $elsewhere);
    $assert($ran['exitCode'] === 0, 'the compiler must run an example from another directory: ' . $ran['stdout'] . $ran['stderr']);
    $assert(\str_contains($ran['stdout'], '3628800'), 'the example must produce its output: ' . $ran['stdout']);

    // The bundled tool, run from the same place: a binary that only exists is not
    // a binary that works.
    $keypair = runCompiledProcess([$stage . '/bin/schnorr' . $exe, 'generate'], 120, null, $elsewhere);
    $assert($keypair['exitCode'] === 0, 'the bundled schnorr must run: ' . $keypair['stdout'] . $keypair['stderr']);
    $assert(
        \str_starts_with(\trim($keypair['stdout']), 'nsec1'),
        'the bundled schnorr must produce a keypair: ' . $keypair['stdout'],
    );

    $build = (string) ($info['compiler'] ?? $version);
    // The archive's format follows the target, not the host running the test: a
    // Windows distribution is a zip (see packaging/assemble.php).
    $windowsTarget = \str_starts_with($target, 'windows-');
    $archive = $out . '/' . $target . '/moggi-minimal-' . $build . '-' . $target
        . ($windowsTarget ? '.zip' : '.tar.gz');
    $assert(\is_file($archive), 'the archive must be named after the build: ' . \basename($archive));

    $clean = $work . '/clean';
    \mkdir($clean, 0777, true);
    $extractArgs = $windowsTarget
        ? ['tar', '-x', '-f', $archive, '-C', $clean]
        : ['tar', '-x', '-z', '-f', $archive, '-C', $clean];
    $extract = runCompiledProcess($extractArgs, 300);
    $assert($extract['exitCode'] === 0, 'the archive must extract: ' . $extract['stderr']);
    $assert(\is_file($clean . '/moggi-minimal/bin/moggi' . $exe), 'the archive must unpack to one directory');
    $assert(!\file_exists($clean . '/moggi-minimal/src'), 'the extracted archive must not carry sources');

    $unpacked = runCompiledProcess([$clean . '/moggi-minimal/bin/moggi' . $exe, 'run', $clean . '/moggi-minimal/examples/twice'], 300, null, $clean);
    $assert($unpacked['exitCode'] === 0, 'the unpacked installation must run: ' . $unpacked['stdout'] . $unpacked['stderr']);
    $assert(\trim($unpacked['stdout']) !== '', 'the unpacked installation must produce output');
} finally {
    removeDirectory($work);
}

\fwrite(STDOUT, "distribution: {$checks} checks passed\n");
