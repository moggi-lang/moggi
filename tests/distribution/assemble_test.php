#!/usr/bin/env php
<?php declare(strict_types=1);

// A distribution is what users actually get, so its shape is asserted rather
// than assumed: one launcher and one compiler archive, no compiler sources
// beside it, user-facing documentation only, examples that run, and an archive
// that unpacks somewhere clean and works from there.
//
// The variant built here is `moggi-minimal`, which bundles no runtimes — that
// keeps the check honest (it exercises the system-runtime path, which is the
// path a minimal installation really takes) and keeps it cheap enough to run
// with the rest of the suite. The variants that do bundle runtimes are built and
// tested by the packaging workflow, which has to download them anyway.

$root = __DIR__;
while (!is_file($root . '/scripts/dist/assemble.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/distribution.php';

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

// Clang is what a distribution is built with; a machine that has another C
// compiler says so explicitly, exactly as a maintainer without Clang would.
$compiler = distributionCompiler();
if ($compiler === null) {
    \fwrite(STDERR, "distribution test: no C compiler on PATH (tried clang, cc, gcc)\n");
    exit(1);
}

try {
    $built = runCompiledProcess(
        [\PHP_BINARY, $root . '/scripts/dist/assemble.php', '--variants', 'moggi-minimal', '--archives', '--out', $out],
        600,
        ['MOGGI_DIST_CC' => $compiler] + \getenv(),
        $root,
    );
    if ($built['exitCode'] !== 0) {
        \fwrite(STDERR, "assembling failed:\n" . $built['stdout'] . $built['stderr']);
        exit(1);
    }

    $targets = \array_values(\array_filter(\glob($out . '/*') ?: [], 'is_dir'));
    $assert(\count($targets) === 1, 'exactly one target must be staged');
    $target = \basename($targets[0]);
    $stage = $targets[0] . '/moggi-minimal';

    $assert(\is_file($stage . '/bin/moggi' . $exe), 'bin/moggi' . $exe . ' must exist');
    $assert(\is_executable($stage . '/bin/moggi' . $exe), 'the launcher must be executable');
    $assert(\is_file($stage . '/bin/moggi.phar'), 'bin/moggi.phar must exist');
    $assert(\is_file($stage . '/bin/schnorr' . $exe), 'bin/schnorr' . $exe . ' must ship beside the launcher');
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
    $assert($stray === [$stage . '/bin/moggi.phar'], 'bin/moggi.phar is the only archive: ' . \implode(', ', $stray));

    // The standard library ships as ordinary sources next to the archive, not
    // inside it: users can read it, and the installation's LICENSE covers it.
    $assert(\is_file($stage . '/lib/Data/Eq.mog'), 'the standard library sources must be shipped');
    $assert(\is_file($stage . '/lib/VERSION'), 'the standard library must carry its version');
    $archived = new \Phar($stage . '/bin/moggi.phar');
    $assert(!isset($archived['lib/Data/Eq.mog']), 'the archive must not carry the standard library');
    $assert(isset($archived['src/compiler.php']), 'the archive must carry the compiler');

    // The version travels inside the archive, so a distribution carries no file
    // for a user to edit by accident, and a build that is not a release says so.
    $php = \PHP_BINARY;
    $reported = runCompiledProcess([$php, $stage . '/bin/moggi.phar', 'version'], 120, null, $work);
    $assert($reported['exitCode'] === 0, 'the packaged archive must run: ' . $reported['stderr']);
    $assert(
        \preg_match('/^compiler:\s+' . \preg_quote($version, '/') . '/m', $reported['stdout']) === 1,
        'the packaged compiler must report its version: ' . $reported['stdout'],
    );

    $json = runCompiledProcess([$php, $stage . '/bin/moggi.phar', 'version', '--json'], 120, null, $work);
    $info = \json_decode($json['stdout'], true);
    $assert($json['exitCode'] === 0 && \is_array($info), '`version --json` must emit JSON: ' . $json['stdout']);
    $assert(
        \in_array($info['channel'] ?? null, ['dev', 'release'], true),
        'a packaged build reports a packaged channel: ' . $json['stdout'],
    );
    $assert(($info['commit'] ?? null) !== null || ($info['channel'] ?? '') === 'release', 'a dev build names the commit it came from: ' . $json['stdout']);
    $assert(($info['variant'] ?? null) === 'moggi-minimal', 'the variant is derived from what is bundled: ' . $json['stdout']);

    // The version a distribution reports is derived from git, which is not
    // exercised by the build above unless the checkout happens to be tagged.
    if (distributionFindExecutable('git') !== null) {
        require_once $root . '/scripts/dist/build-phar.php';

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

    // A backend that is neither bundled nor on the host has to be reported, not
    // skipped and not fatal: the answer is `not found`, never an empty field.
$noRuntimes = $work . '/no-runtimes';
\mkdir($noRuntimes, 0777, true);
$env = ['PATH' => $noRuntimes] + \getenv();
unset($env['JAVA_HOME'], $env['DOTNET_ROOT'], $env['GRAALVM_HOME'], $env['JDK_HOME']);
    $bare = runCompiledProcess([$php, $stage . '/bin/moggi.phar', 'version'], 120, $env, $work);
    $assert($bare['exitCode'] === 0, 'a missing backend toolchain must not fail `version`: ' . $bare['stderr']);
    foreach (['php', 'java', 'dotnet', 'native-image'] as $tool) {
        $assert(
            \preg_match('/^' . \preg_quote($tool, '/') . ':\s+\S/m', $bare['stdout']) === 1,
            "a toolchain field must never be empty ({$tool}): " . $bare['stdout'],
        );
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

    // The installation runs from somewhere else entirely, using the host's PHP.
    $elsewhere = $work . '/elsewhere';
    \mkdir($elsewhere, 0777, true);
    $ran = runCompiledProcess([$stage . '/bin/moggi' . $exe, 'run', $stage . '/examples/factorial'], 300, null, $elsewhere);
    $assert($ran['exitCode'] === 0, 'the launcher must run an example from another directory: ' . $ran['stdout'] . $ran['stderr']);
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
    $archive = $out . '/' . $target . '/moggi-minimal-' . $build . '-' . $target . '.tar.gz';
    $assert(\is_file($archive), 'the archive must be named after the build: ' . \basename($archive));

    $clean = $work . '/clean';
    \mkdir($clean, 0777, true);
    $extract = runCompiledProcess(['tar', '-x', '-z', '-f', $archive, '-C', $clean], 300);
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
