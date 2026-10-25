<?php declare(strict_types=1);

/**
 * Assert the distribution users receive: `bin/moggi` is the compiler
 * (micro PHP runtime with the archive appended), no PHP runtime or compiler
 * sources are shipped separately, documentation is user-facing, examples
 * run, and the archive works when unpacked into a clean directory.
 *
 * This builds `moggi-minimal`, which bundles no runtime but runs natively.
 * Toolchain-bundling variants are built and tested by the packaging workflow.
 *
 * The micro PHP runtime must be compiled locally, so distribution assembly
 * is skipped when it is unavailable. Resolution and preflight checks still
 * run everywhere.
 */

$root = __DIR__;
while (! \is_file($root . '/packaging/assemble.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/distribution.php';
require_once $root . '/packaging/assemble.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$version = \trim((string) \file_get_contents($root . '/VERSION'));
$work = createTempDir('moggi-distribution');
$work = \realpath($work) ?: $work;
$out = $work . '/out';
$exe = \PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

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
    ! \in_array('php-native', $locked, true) && \in_array('spc', $locked, true),
    'a derived runtime has no lock entry; its build tool does',
);
$lock = \Moggi\Dist\loadRuntimeLock();
$knownTargets = \Moggi\Dist\knownTargets($runtimeConfig);
$strayKeys = \array_values(\array_filter(
    \array_keys($lock),
    static function (string $key) use ($locked, $knownTargets): bool {
        [$runtime, $target] = \explode('/', $key, 2) + ['', ''];

        return ! \in_array($runtime, $locked, true) || ! \in_array($target, $knownTargets, true);
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
    ! \in_array('windows-aarch64', \Moggi\Dist\knownTargets($runtimeConfig), true),
    'Windows ARM64 is not a target: the compiler itself cannot be built there',
);
$assert(
    \Moggi\Dist\unsupportedReason('zig', 'macos-x86_64', $runtimeConfig) !== null,
    'the Zig toolchain is a Linux-only build input',
);
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
if (! \Moggi\Dist\runtimeIsPrepared('php-native', $host, $runtimeConfig)) {
    \fwrite(
        STDOUT,
        "distribution: skipped the assembly — php-native for {$host} is not prepared,"
        . " and bin/moggi is built from it (it is compiled, not downloaded)\n",
    );
    removeDirectory($work);
    exit(0);
}

try {
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
        ! \is_file($stage . '/bin/moggi.phar'),
        'a variant that bundles no PHP must not ship the compiler archive',
    );
    $assert(\is_file($stage . '/bin/schnorr' . $exe), 'bin/schnorr' . $exe . ' must ship beside the compiler');
    $assert(\is_executable($stage . '/bin/schnorr' . $exe), 'the bundled schnorr must be executable');

    foreach (['src', 'tests', 'scripts', 'dist', 'launcher', 'editors', '.git', '.moggi'] as $leak) {
        $assert(! \file_exists($stage . '/' . $leak), "a distribution must not contain {$leak}");
    }
    foreach (['moggi.php', 'test.php', 'flake.nix', 'composer.json', 'VERSION', '.envrc'] as $file) {
        $assert(! \file_exists($stage . '/' . $file), "a distribution must not contain {$file}");
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

    $assert(\is_file($stage . '/libraries/base/src/Data/Eq.mog'), 'the standard library sources must be shipped');
    $assert(\is_file($stage . '/lib/base.moggi'), 'the standard library must carry its manifest');

    $noRuntimes = $work . '/no-runtimes';
    \mkdir($noRuntimes, 0777, true);
    $env = ['PATH' => $noRuntimes] + \getenv();
    unset($env['JAVA_HOME'], $env['DOTNET_ROOT'], $env['GRAALVM_HOME'], $env['JDK_HOME']);
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

    $artifact = $work . '/factorial.phar';
    $compiled = runCompiledProcess(
        [$compilerExe, 'compile', $stage . '/examples/factorial', '-o', $artifact],
        300,
        $env,
        $work,
    );
    $assert($compiled['exitCode'] === 0, 'compiling with no PHP on PATH must work: ' . $compiled['stdout'] . $compiled['stderr']);
    $assert(\is_file($artifact), 'the compiler must write the archive');

    $ranPhar = runCompiledProcess([\PHP_BINARY, $artifact], 120, null, $work);
    $assert($ranPhar['exitCode'] === 0, 'the packaged archive must run: ' . $ranPhar['stderr']);
    $assert(\str_contains($ranPhar['stdout'], '3628800'), 'the packaged archive must produce its output: ' . $ranPhar['stdout']);

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
            if (! \file_exists(\dirname($page) . '/' . $linked)) {
                $dangling[] = \substr($page, \strlen($stage) + 1) . ' -> ' . $link;
            }
        }
    }
    $assert($dangling === [], 'shipped documentation must not link to what is not shipped: ' . \implode(', ', $dangling));

    $assert(\is_file($stage . '/README.md'), 'a distribution README must be written');
    $readme = (string) \file_get_contents($stage . '/README.md');
    $assert(\str_contains($readme, 'moggi-minimal'), 'the README must describe the variant it is in');
    $assert(\str_contains($readme, 'bin/moggi'), 'the README must show how to run it');
    $assert(! \str_contains($readme, 'bin/moggi.phar'), 'a README for a PHP-less variant must not name an archive');
    $assert(! \str_contains($readme, 'nix develop'), 'the README must not send users to a development shell');
    $assert(\str_contains($readme, 'No runtime is bundled'), 'the minimal README must say that nothing is bundled');

    $assert(\is_file($stage . '/LICENSE'), 'the licence must be shipped');
    $assert(\is_file($stage . '/examples/factorial/Main.mog'), 'the examples must be shipped');
    $assert(($find($stage . '/examples', static fn (string $name): bool => \str_ends_with($name, '.expected')) === []), 'test goldens are not examples');

    $assert(! \file_exists($stage . '/runtime'), 'moggi-minimal must bundle no runtime');
    $assert(\is_file($stage . '/THIRD-PARTY-NOTICES.md'), 'the licences of what schnorr links must ship');
    $notices = (string) \file_get_contents($stage . '/THIRD-PARTY-NOTICES.md');
    foreach (['libsecp256k1-0.8.0', 'libbech32-1.1p1', 'Permission is hereby granted'] as $required) {
        $assert(\str_contains($notices, $required), "the notices must carry {$required}");
    }

    $elsewhere = $work . '/elsewhere';
    \mkdir($elsewhere, 0777, true);
    $ran = runCompiledProcess([$compilerExe, 'run', $stage . '/examples/factorial'], 300, null, $elsewhere);
    $assert($ran['exitCode'] === 0, 'the compiler must run an example from another directory: ' . $ran['stdout'] . $ran['stderr']);
    $assert(\str_contains($ran['stdout'], '3628800'), 'the example must produce its output: ' . $ran['stdout']);

    $keypair = runCompiledProcess([$stage . '/bin/schnorr' . $exe, 'generate'], 120, null, $elsewhere);
    $assert($keypair['exitCode'] === 0, 'the bundled schnorr must run: ' . $keypair['stdout'] . $keypair['stderr']);
    $assert(
        \str_starts_with(\trim($keypair['stdout']), 'nsec1'),
        'the bundled schnorr must produce a keypair: ' . $keypair['stdout'],
    );

    $build = (string) ($info['compiler'] ?? $version);
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
    $assert(! \file_exists($clean . '/moggi-minimal/src'), 'the extracted archive must not carry sources');

    $unpacked = runCompiledProcess([$clean . '/moggi-minimal/bin/moggi' . $exe, 'run', $clean . '/moggi-minimal/examples/twice'], 300, null, $clean);
    $assert($unpacked['exitCode'] === 0, 'the unpacked installation must run: ' . $unpacked['stdout'] . $unpacked['stderr']);
    $assert(\trim($unpacked['stdout']) !== '', 'the unpacked installation must produce output');
} finally {
    removeDirectory($work);
}

\fwrite(STDOUT, "distribution: {$checks} checks passed\n");
