<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/filesystem.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/extensions.php';
require_once __DIR__ . '/../src/executables.php';

use function Moggi\Compiler\findExecutable;

/**
 * Building the runtime that has no upstream archive.
 *
 * `php-native` is compiled for its target: static-php-cli's `spc` builds the
 * micro PHP runtime, and the tool itself is a pinned download that `runtimes.php`
 * prepares. Everything that compiles anything lives here — the `spc` and Zig
 * toolchain setup, the `craft.yml` recipe, the from-source PHP build — together
 * with the content-addressed cache key that decides when a build can be reused.
 *
 * This module is loaded beside `runtimes.php` and completes it: the build below
 * calls back into the downloader (`fetchRuntimeAsset`, `prepareRuntime`) and the
 * version check (`assertRuntimeVersion`) that `runtimes.php` defines.
 */

/**
 * The PHP version a micro SAPI runtime is, e.g. `8.6.0`.
 *
 * It has no command line — anything appended to it is the program, and its own
 * banner is what a bare run prints — so its identity comes from that banner
 * rather than from a version command, and the exit status says nothing.
 */
function microSfxVersion(string $binary): string
{
    [$code, $stdout, $stderr] = runProcess([$binary, '-v']);
    $text = \trim($stdout . "\n" . $stderr);
    if (\preg_match('/micro SAPI for PHP\s*v?([0-9][0-9A-Za-z.\-]*)/', $text, $match) !== 1) {
        throw new \RuntimeException(
            "the micro PHP runtime printed no version (exit {$code}): " . \substr($text, 0, 200),
        );
    }

    return $match[1];
}

/**
 * The cache key of a derived runtime: what it is built from, and nothing else.
 *
 * It deliberately does not include the compiler's own sources, so a change to
 * Moggi never rebuilds the micro runtime — only a new `spc`, a new PHP series, a
 * new SAPI or a new extension list does. Everything in it is independent of the
 * checkout, so a shared cache could key on the same value.
 */
function derivedRuntimeKey(string $runtime, string $target, array $config): string
{
    $recipe = buildRecipe($runtime, $config) ?? [];
    $tool = (string) ($recipe['tool'] ?? '');
    $toolSpec = $tool !== '' ? ($config['runtimes'][$tool] ?? []) : [];
    $toolEntry = $tool !== '' ? (loadRuntimeLock()[$tool . '/' . $target] ?? []) : [];
    $extensions = microExtensions($runtime, $config);

    return 'sha256:' . \hash('sha256', (string) \json_encode([
        'runtime' => $runtime,
        'target' => $target,
        'tool' => [
            $tool,
            (string) ($toolSpec['version'] ?? ''),
            (string) ($toolEntry['sha256'] ?? $toolEntry['sha512'] ?? $toolEntry['url'] ?? ''),
        ],
        'php' => (string) ($recipe['php'] ?? ''),
        'sapi' => (string) ($recipe['sapi'] ?? ''),
        'extensions' => $extensions,
        'zts' => \in_array('parallel', \array_map('strtolower', $extensions), true),
        'toolchain' => microToolchainEnv($target),
        'zig' => zigToolchainPin($target, $config),
        'host' => \PHP_OS_FAMILY . '-' . \php_uname('m'),
    ], \JSON_UNESCAPED_SLASHES));
}

/**
 * What the runtime's Zig toolchain is, for the cache key.
 *
 * The compiler is as much a build input as the recipe is, so a change to the pin
 * has to change the key too — otherwise a runtime built by the previous compiler
 * would be served as if it were this one.
 *
 * @return array{string, string}|null the pinned version and lock hash, or null
 *                                     where the target's toolchain is not Zig
 */
function zigToolchainPin(string $target, array $config): ?array
{
    if (unsupportedReason('zig', $target, $config) !== null) {
        return null;
    }
    $entry = loadRuntimeLock()['zig/' . $target] ?? [];

    return [
        (string) ($config['runtimes']['zig']['version'] ?? ''),
        (string) ($entry['sha256'] ?? $entry['url'] ?? ''),
    ];
}

/**
 * The extensions a derived runtime is built with.
 *
 * The micro PHP runtime carries the `php` runtime's own set unless its recipe
 * names a set of its own, so a native executable can call at least what a PHAR
 * can call — the invariant the previous pre-built runtime broke by shipping no
 * `intl` (see the `build` recipe in `dist/runtimes.json`).
 *
 * @return list<string>
 */
function microExtensions(string $runtime, array $config): array
{
    $recipe = buildRecipe($runtime, $config) ?? [];
    $extensions = $recipe['extensions'] ?? null;
    if (\is_array($extensions) && $extensions !== []) {
        return \array_values(\array_map('strval', $extensions));
    }

    return distributionPhpExtensions();
}

/**
 * Build one derived runtime for one target.
 *
 * @return array{version: string, reported: string, binary: string}
 */
function buildDerivedRuntime(string $runtime, string $target, array $config, string $dir): array
{
    $recipe = buildRecipe($runtime, $config) ?? [];
    $tool = (string) ($recipe['tool'] ?? '');
    if ($tool !== 'spc') {
        throw new \RuntimeException("{$runtime} names an unknown build tool `{$tool}`");
    }

    return buildMicroWithSpc($runtime, $target, $config, $dir, $recipe);
}

/**
 * Compile the micro PHP runtime with static-php-cli's `spc`.
 *
 * The tool is prepared first — it is pinned, and never bundled with a
 * distribution — and `spc craft` then fetches the PHP source and every library
 * the extension list needs, builds them, and leaves the result at
 * `buildroot/bin/micro.sfx`. A pinned `spc` is a source tree, so the command is
 * run by this build's PHP (`toolCommand`).
 *
 * static-php-cli reads its output, source and download paths from the
 * environment, so pointing all three inside `.dist-cache` is what makes a
 * re-build cheap: nothing is fetched twice and the library builds are kept. The
 * cache key is the recipe (`derivedRuntimeKey`), so an unchanged recipe is not
 * rebuilt at all.
 *
 * @param array<string, mixed> $recipe
 * @return array{version: string, reported: string, binary: string}
 */
function buildMicroWithSpc(string $runtime, string $target, array $config, string $dir, array $recipe): array
{
    $tool = (string) ($recipe['tool'] ?? 'spc');
    $toolDir = prepareRuntime($tool, $target, $config);
    $toolEntry = loadRuntimeLock()[$tool . '/' . $target] ?? [];
    $spc = toolCommand($tool, $toolDir, $toolEntry, $config);
    $spcBinary = $spc[\count($spc) - 1];
    if (! \is_file($spcBinary)) {
        throw new \RuntimeException("the {$tool} build tool is missing at {$spcBinary}");
    }

    assertCToolchain($target, "compiling the {$runtime} runtime");

    $extensions = microExtensions($runtime, $config);
    $php = (string) ($recipe['php'] ?? '');
    $sapi = (string) ($recipe['sapi'] ?? 'micro');

    $work = distCacheRoot() . '/build/' . $target . '/' . $runtime;
    if (! \is_dir($work) && ! \mkdir($work, 0777, true) && ! \is_dir($work)) {
        throw new \RuntimeException("cannot create {$work}");
    }

    if (unsupportedReason('zig', $target, $config) === null) {
        installZigToolchain($target, $config, $work, $toolDir);
    }

    $craft = $work . '/craft.yml';
    \file_put_contents($craft, craftFile($php, $sapi, $extensions));

    $env = microBuildEnvironment($work, $target);

    assertChildCwd($work);
    runOrFail([...$spc, 'craft', '--no-motd', '--no-interaction', $craft], $work, true, null, $env);

    $micro = $work . '/buildroot/bin/micro.sfx';
    if (! \is_file($micro)) {
        throw new \RuntimeException("spc built no micro.sfx (expected {$micro})");
    }
    if (! \copy($micro, $dir . '/micro.sfx')) {
        throw new \RuntimeException("cannot copy the built micro runtime into {$dir}");
    }
    @\chmod($dir . '/micro.sfx', 0755);
    writeRuntimeExtensionManifest($dir, $runtime, $extensions);

    runOrFail(
        [
            ...$spc,
            'dump-license',
            '--no-motd',
            '--no-interaction',
            '--for-extensions=' . \implode(',', $extensions),
            '--dump-dir=' . $dir . '/license',
        ],
        $work,
        false,
        null,
        $env,
    );
    if (! \is_dir($dir . '/license')) {
        throw new \RuntimeException('spc dumped no licences for the micro runtime');
    }

    $version = microSfxVersion($dir . '/micro.sfx');
    assertMicroRuntime($dir . '/micro.sfx', $target, $extensions);

    return [
        'version' => $version,
        'reported' => assertRuntimeVersion($runtime, 'PHP' . $version, $config, $target),
        'binary' => 'micro.sfx',
    ];
}

/**
 * The environment `spc` builds in.
 *
 * spc supplies the defaults in its `env.ini` only for variables that are *unset*,
 * so a compiler variable the caller happens to export silently overrides the
 * toolchain spc chose: a shell that exports `CC=gcc` (a Nix dev shell does) makes
 * the Zig/musl toolchain inert, and every library that does not read the CMake
 * toolchain file is then built by the host compiler instead — ICU's
 * `runConfigureICU` is one, and on a host without a static libc its `-static`
 * link cannot succeed at all. Dropping them is what lets the selected toolchain
 * be the one that runs.
 *
 * @return array<string, string>
 */
function microBuildEnvironment(string $work, string $target): array
{
    $env = \getenv();
    foreach (['CC', 'CXX', 'CPP', 'AR', 'LD', 'CFLAGS', 'CXXFLAGS', 'CPPFLAGS', 'LDFLAGS', 'SPC_LIBC', 'SPC_TOOLCHAIN'] as $inherited) {
        unset($env[$inherited]);
    }

    $env['BUILD_ROOT_PATH'] = $work . '/buildroot';
    $env['SOURCE_PATH'] = $work . '/source';
    $env['DOWNLOAD_PATH'] = $work . '/downloads';
    $env['PKG_ROOT_PATH'] = $work . '/pkgroot';
    foreach (microToolchainEnv($target) as $key => $value) {
        $env[$key] = $value;
    }

    return $env;
}

/**
 * The static-php-cli toolchain settings a target's build needs, as environment
 * variables for `spc`.
 *
 * On Linux the default toolchain is the musl wrapper, which spc installs into
 * `/usr` and `/lib` with `sudo` — a packaging run must not write there, and a
 * build host without root could not finish the install at all. The Zig toolchain
 * links the same static musl libc using the `zig` that spc installs inside the
 * build directory, so Linux targets build with it and the micro runtime stays
 * statically linked (see `static` in `dist/runtimes.json`).
 *
 * `SPC_TARGET` is what selects it (`ToolchainManager` only falls back to the musl
 * wrapper while no target is set), and naming the target is also what pins the
 * link: `native-native-musl` is this host's architecture and OS, statically linked
 * against musl.
 *
 * The two doctor items skipped here are the ones that then misfire: they exist to
 * install the musl wrapper and musl-cross-make the Zig toolchain replaces, and
 * they check for a musl loader that a statically linked runtime never asks for.
 * Every other doctor item still runs, and still installs what it can find a
 * packager for.
 *
 * The compiler flags ride along here because they are toolchain input: see
 * `spcCompilerFlags`, and `derivedRuntimeKey` keeps a change to them from
 * reusing a runtime built with the old ones.
 *
 * @return array<string, string>
 */
function microToolchainEnv(string $target): array
{
    if (! \str_starts_with($target, 'linux-')) {
        return [];
    }

    return [
        'SPC_TARGET' => 'native-native-musl',
        'SPC_SKIP_DOCTOR_CHECK_ITEMS' => 'if musl-wrapper is installed,if musl-cross-make is installed',
        'SPC_DEFAULT_CFLAGS' => spcCompilerFlags(),
        'SPC_DEFAULT_CXXFLAGS' => spcCompilerFlags(),
    ];
}

/**
 * The C flags a Zig build runs with, besides what spc appends itself.
 *
 * spc reads `SPC_DEFAULT_CFLAGS` and keeps whatever it finds, so setting it
 * *replaces* the defaults in its `config/env.ini` — these are those defaults for
 * spc 3.0.0-alpha1, spelled out, plus one diagnostic. Dropping them is not an
 * option worth considering: without `-fPIC` the libraries it builds (libcurl,
 * first) are position-dependent and PHP's own configure rejects them with
 * "the libcurl check failed".
 *
 * The extra `-Wno-gcc-install-dir-libstdcxx` exists for hosts with more than one
 * GCC install directory. Zig 0.17.1 warns that a future release will prefer the
 * one holding libstdc++ headers, and a bare `-Werror` probe — xz's configure runs
 * one — turns that warning into a failure before anything is compiled.
 */
function spcCompilerFlags(): string
{
    return '-fPIC -O3 -pipe -fno-plt -fno-semantic-interposition -fstack-clash-protection'
        . ' -fno-omit-frame-pointer -mno-omit-leaf-frame-pointer -ffunction-sections -fdata-sections'
        . ' -Wno-unused-command-line-argument -Wno-gcc-install-dir-libstdcxx';
}

/**
 * static-php-cli's own `zig-cc` wrapper, read from the pinned source tree.
 *
 * `spc` puts the wrapper directory on `PATH`, and every library build calls this
 * script rather than `zig` directly: it rewrites configure's flags into what Zig
 * accepts (`-isystem` for the build's own buildroot, `-march=x86_64` for
 * `-march=x86-64`, `-mtune=baseline` for `-mtune=generic`) and turns
 * `SPC_TARGET` into `zig cc -target …`. A pinned Zig has to arrive with the
 * wrapper the pinned `spc` expects, or `spc` would not recognise the toolchain
 * and would install its own Zig over it. Reading it from the pinned tree keeps
 * the copy in step with the `spc` commit instead of carrying a second one that
 * can drift.
 */
function zigCcWrapper(string $toolDir): string
{
    $path = $toolDir . '/src/globals/scripts/zig-cc.sh';
    $script = @\file_get_contents($path);
    if (! \is_string($script) || $script === '') {
        throw new \RuntimeException("the pinned spc tree has no zig-cc wrapper at {$path}");
    }

    return $script;
}

/**
 * Install the pinned Zig, and the wrappers `spc` looks for beside it, into a
 * build's `pkgroot`.
 *
 * `spc` installs a Zig of its own whenever `pkgroot/zig` is not already complete,
 * and takes whatever version ziglang.org lists as newest. That is not
 * reproducible, and it is how this runtime came to be compiled by Zig 0.16.0,
 * whose `strnlen` reads past the buffer it is given — every static-musl binary it
 * produces, the micro runtime included, segfaults before it can run. So the
 * compiler is pinned in `dist/runtimes.json` and provisioned here instead.
 *
 * A `buildroot` built by another Zig is discarded first: `spc` only checks that a
 * library archive exists, so archives from a different compiler would be reused
 * and then fail at the final link on symbols that libc does not provide.
 */
function installZigToolchain(string $target, array $config, string $work, string $toolDir): void
{
    $dir = $work . '/pkgroot/zig';
    $version = (string) $config['runtimes']['zig']['version'];
    $stamp = $dir . '/.pinned';

    if (\is_file($dir . '/zig') && \is_file($stamp) && \trim((string) \file_get_contents($stamp)) === $version) {
        return;
    }

    $fetched = fetchRuntimeAsset('zig', $target, $config);
    removeTree($work . '/buildroot');
    removeTree($dir);
    extractArchive(
        $fetched['archive'],
        (string) $fetched['entry']['format'],
        $dir,
        (int) $fetched['entry']['stripComponents'],
    );

    $ccWrapper = zigCcWrapper($toolDir);
    if (! \str_ends_with($ccWrapper, "\n")) {
        $ccWrapper .= "\n";
    }
    $oneLiner = static fn (string $command): string => "#!/usr/bin/env bash\nexec {$command} $@";
    $wrappers = [
        'zig-cc' => $ccWrapper,
        'zig-c++' => \str_replace('zig cc', 'zig c++', $ccWrapper),
        'zig-ar' => $oneLiner('zig ar'),
        'zig-ld.lld' => $oneLiner('zig ld.lld'),
        'zig-ranlib' => $oneLiner('zig ranlib'),
        'zig-objcopy' => $oneLiner('zig objcopy'),
    ];
    foreach ($wrappers as $name => $wrapper) {
        if (\file_put_contents($dir . '/' . $name, $wrapper) === false) {
            throw new \RuntimeException("cannot write the {$name} wrapper into {$dir}");
        }
        @\chmod($dir . '/' . $name, 0755);
    }
    \file_put_contents($stamp, $version . "\n");
    \fwrite(STDOUT, "  zig {$version}\n");
}

/**
 * The `craft.yml` static-php-cli builds from.
 *
 * `craft-options.doctor` keeps its default (on): it checks the host and installs
 * what a build server most often lacks, before hours of compiling rather than
 * during them. `prefer-pre-built` is deliberately left off — a pre-built PHP
 * binary cannot carry the micro SAPI, so this runtime is always compiled from
 * source.
 *
 * @param list<string> $extensions
 */
function craftFile(string $php, string $sapi, array $extensions): string
{
    $lines = [
        '# Written by packaging/runtimes.php for one distribution build.',
        '# static-php-cli compiles the micro PHP runtime from it; see',
        '# dist/runtimes.json for the recipe it is generated from.',
        'php-version: "' . $php . '"',
        'extensions: "' . \implode(',', $extensions) . '"',
        'sapi:',
        '  - ' . $sapi,
    ];
    if (\in_array('parallel', \array_map('strtolower', $extensions), true)) {
        // static-php-cli defaults to NTS; ext-parallel is ZTS-only and will not
        // build or load in the default runtime.
        $lines[] = 'build-options:';
        $lines[] = '  enable-zts: true';
    }
    $lines[] = '';

    return \implode("\n", $lines);
}

/**
 * Refuse a micro runtime that does not satisfy the runtime contract.
 *
 * The micro runtime is what a `--native` executable runs, so it has to carry
 * exactly the extensions the build asked for — a runtime that loads fewer is a
 * program that dies on the call it was compiled to make, and the micro runtime
 * cannot load one at run time (it is statically linked and has no `dl()`).
 *
 * static-php-cli will also build an NTS runtime happily when only the extension
 * list is checked, and an NTS runtime cannot load `parallel`; the probe checks
 * the thread-safety the extension set implies as well.
 *
 * @param list<string> $extensions
 */
function assertMicroRuntime(string $binary, string $target, array $extensions): void
{
    $ztsRequired = \in_array('parallel', \array_map('strtolower', $extensions), true);

    // micro.sfx has no command line of its own: it runs the PHP code appended
    // to it. Build a throwaway probe executable, run it, and remove it.
    $probe = \sys_get_temp_dir() . '/moggi-micro-probe-' . \bin2hex(\random_bytes(6))
        . (\PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    try {
        if (! \copy($binary, $probe)) {
            throw new \RuntimeException("cannot copy {$binary} to a micro runtime probe");
        }
        $payload = '<?php $missing = []; foreach (' . \var_export($extensions, true) . ' as $extension) {'
            . ' if (! extension_loaded($extension)) { $missing[] = $extension; } }'
            . ' echo PHP_ZTS ? "zts " : "nts ";'
            . ' echo $missing === [] ? "complete" : "missing:" . implode(",", $missing);'
            . ' exit($missing === [] ? 0 : 1);';
        if (\file_put_contents($probe, $payload, \FILE_APPEND) === false) {
            throw new \RuntimeException('cannot append the micro runtime probe payload');
        }
        @\chmod($probe, 0755);

        [$code, $stdout, $stderr] = runProcess([$probe]);
        $verdict = \trim($stdout);
        $complete = \str_ends_with($verdict, 'complete');
        if ($code !== 0 || ! $complete || ($ztsRequired && ! \str_starts_with($verdict, 'zts '))) {
            throw new \RuntimeException(
                "the micro PHP runtime for {$target} does not satisfy its extension contract ({$verdict})\n"
                . \substr(\trim($stderr), -1000),
            );
        }
    } finally {
        @\unlink($probe);
    }
}

function buildPhpFromSource(string $runtime, string $target, array $config, string $archive, string $dir, array $entry): string
{
    $spec = $config['runtimes'][$runtime];
    $work = distCacheRoot() . '/build/' . $target . '/' . $runtime . '-' . $spec['version'];
    $source = $work . '/src';

    removeTree($source);
    if (! \mkdir($source, 0777, true) && ! \is_dir($source)) {
        throw new \RuntimeException("cannot create {$source}");
    }
    extractArchive($archive, (string) $entry['format'], $source, (int) $entry['stripComponents']);

    $roots = \array_values(\array_filter(\scandir($source) ?: [], static fn (string $n): bool => $n !== '.' && $n !== '..' && \is_dir($source . '/' . $n)));
    if (\count($roots) !== 1) {
        throw new \RuntimeException("expected one source directory in {$source}, found " . \count($roots));
    }
    $tree = $source . '/' . $roots[0];

    assertCToolchain($target, "building the bundled {$runtime} from source");
    assertChildCwd($tree);

    $jobs = (string) \max(1, cpuCount());
    $make = findExecutable('make') ?? findExecutable('gmake');
    if ($make === null) {
        throw new \RuntimeException('no make to build the bundled PHP with');
    }
    $resolvedMake = \realpath($make);
    if ($resolvedMake === false || ! \is_file($resolvedMake)) {
        throw new \RuntimeException("the make used to build PHP is not a file: {$make}");
    }
    if (! \str_starts_with($target, 'windows-')) {
        if (! \is_executable($resolvedMake)) {
            throw new \RuntimeException("the make used to build PHP is not executable: {$resolvedMake}");
        }
        $mode = \fileperms($resolvedMake);
        if ($mode === false || ($mode & 0o022) !== 0) {
            throw new \RuntimeException(
                "the make used to build PHP is writable by group or other; refusing to build with it: {$resolvedMake}",
            );
        }
    }
    $make = $resolvedMake;
    $extensions = distributionPhpExtensions();
    $prefix = $work . '/install';
    runOrFail([$tree . '/configure', '--prefix=' . $prefix, ...extensionBuildFlags($extensions, $config)], $tree);
    runOrFail([$make, '-j' . $jobs], $tree);
    // PIE installs into the prefix, and it needs the installed php-config.
    runOrFail([$make, 'install'], $tree);

    if (\in_array('parallel', $extensions, true)) {
        installParallelExtension($prefix, $dir);
    }

    if (\is_file($tree . '/LICENSE')) {
        \copy($tree . '/LICENSE', $dir . '/LICENSE');
    }

    $binary = $prefix . '/bin/php';
    if (! \is_file($binary)) {
        $binary = $tree . '/sapi/cli/php';
    }
    if (! \is_file($binary)) {
        throw new \RuntimeException("the source build produced no CLI binary at {$binary}");
    }
    if (! \is_dir($dir . '/bin') && ! \mkdir($dir . '/bin', 0777, true) && ! \is_dir($dir . '/bin')) {
        throw new \RuntimeException("cannot create {$dir}/bin");
    }
    if (! \copy($binary, $dir . '/bin/php')) {
        throw new \RuntimeException('cannot copy the built PHP binary');
    }
    @\chmod($dir . '/bin/php', 0755);
    stripBinary($dir . '/bin/php');
    copyNonBaselineLibraries($dir . '/bin/php', $dir . '/lib');
    installPhpizeTree($prefix, $dir, $target);

    $output = \PHP_OS_FAMILY === 'Windows'
        ? runtimeProcess([$dir . '/bin/php', '-n', '-r', 'echo PHP_VERSION, " ", PHP_OS_FAMILY, "-", php_uname("m");'])
        : runWithLibraryPath([$dir . '/bin/php', '-n', '-r', 'echo PHP_VERSION, " ", PHP_OS_FAMILY, "-", php_uname("m");'], $dir . '/lib');

    return assertRuntimeVersion($runtime, $output, $config, $target);
}

/**
 * Copy the PHP build's extension-development files beside the runtime.
 *
 * `moggi runtime php install-ext` compiles an extension against the bundled PHP,
 * and that needs three things the program runtime does not: `php-config` (which
 * reports
 * the ABI and the build flags), `phpize` and the `include/php` headers plus the
 * `lib/php/build` makefiles it drives. They are copied into the runtime's own
 * tree and made relocatable, so a project's extension is built against the exact
 * PHP the compiler verifies with — not the build host's.
 *
 * `php-config` is a shell script with its prefix baked in, so the prefix is
 * rewritten to be computed from the script's own location. A build-cache path
 * left in there is how an extension ends up linked against a directory that no
 * longer exists after installation.
 *
 * A Windows archive is a binary distribution with no `phpize`, so there is
 * nothing to install there: PIE uses its prebuilt DLLs instead of compiling.
 */
function installPhpizeTree(string $prefix, string $dir, string $target): void
{
    if (\str_starts_with($target, 'windows-')) {
        return;
    }

    foreach (['bin/php-config', 'bin/phpize'] as $relative) {
        $source = $prefix . '/' . $relative;
        if (! \is_file($source)) {
            throw new \RuntimeException("the PHP build left no {$relative} in {$prefix}");
        }
        if (! \is_dir(\dirname($dir . '/' . $relative))) {
            \mkdir(\dirname($dir . '/' . $relative), 0777, true);
        }
        if (! \copy($source, $dir . '/' . $relative)) {
            throw new \RuntimeException("cannot copy {$relative} into the runtime");
        }
        @\chmod($dir . '/' . $relative, 0755);
    }

    foreach (['include/php', 'lib/php/build'] as $relative) {
        if (\is_dir($prefix . '/' . $relative)) {
            copyTree($prefix . '/' . $relative, $dir . '/' . $relative);
        }
    }

    $computed = '$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)';
    foreach (['bin/php-config', 'bin/phpize'] as $relative) {
        $path = $dir . '/' . $relative;
        $text = (string) \file_get_contents($path);
        $text = \str_replace('prefix="' . $prefix . '"', 'prefix="' . $computed . '"', $text);
        $text = \str_replace("prefix='" . $prefix . "'", 'prefix="' . $computed . '"', $text);
        $text = \str_replace($prefix, '${prefix}', $text);
        \file_put_contents($path, $text);
    }
}

/**
 * The ext-parallel release the distribution pins.
 *
 * PIE otherwise resolves the newest compatible release when the runtime is
 * built, which makes the same PHP tarball produce different archives on
 * different days. The version is part of the distribution contract too:
 * `parallel` is a ZTS-only extension, so both the PHP build and the extension
 * have to be the intended pair.
 */
const PARALLEL_PACKAGE = 'pecl/parallel:1.2.15';

/**
 * Install ext-parallel into a PHP prefix or a downloaded Windows runtime.
 *
 * ext-parallel is not in php-src, so `configure` cannot enable it. PIE is the
 * extension installer the distribution uses instead of PECL: it reads the
 * target's `php-config` (or `php.exe` on Windows), compiles or downloads the
 * extension against exactly that runtime, and places it in the extension
 * directory. The shared object is copied beside the runtime, where
 * `assertPhpExtensions()` finds it and enables it in the bundled `php.ini`.
 */
function installParallelExtension(string $prefix, string $runtimeDir): void
{
    $windows = \PHP_OS_FAMILY === 'Windows';
    $php = $windows ? $prefix . '/php.exe' : $prefix . '/bin/php';
    $phpConfig = $prefix . '/bin/php-config';
    if (! \is_file($php) || (! $windows && ! \is_file($phpConfig))) {
        throw new \RuntimeException("the PHP install left no usable php in {$prefix}");
    }

    $pie = findExecutable('pie') ?? findExecutable('pie.phar');
    if ($pie === null) {
        $configured = \getenv('MOGGI_PIE');
        if (\is_string($configured) && $configured !== '') {
            $pie = $configured;
        }
    }
    if ($pie === null) {
        throw new \RuntimeException(
            'installing ext-parallel needs PIE (https://github.com/php/pie): put `pie` on PATH or set MOGGI_PIE to pie.phar',
        );
    }

    $target = $windows
        ? ['--with-php-path=' . $php]
        : ['--with-php-config=' . $phpConfig];
    // A PHAR runs under the build-script PHP, not the target runtime: the
    // target is named explicitly above and does not need phar support itself.
    $command = \str_ends_with($pie, '.phar') ? [\PHP_BINARY, $pie] : [$pie];
    runOrFail([
        ...$command,
        'install',
        PARALLEL_PACKAGE,
        ...$target,
        '--skip-enable-extension',
        '--no-interaction',
        ...($windows ? [] : ['--auto-install-build-tools']),
    ], $prefix);

    $built = \glob($prefix . '/lib/php/extensions/*/parallel.so') ?: [];
    if ($built === []) {
        $built = \glob($prefix . '/ext/php_parallel.dll') ?: [];
    }
    if ($built === []) {
        $built = \glob($prefix . '/ext/parallel.so') ?: [];
    }
    if ($built === []) {
        throw new \RuntimeException("PIE reported success but no parallel extension exists under {$prefix}");
    }

    $extDir = $runtimeDir . '/ext';
    if (! \is_dir($extDir) && ! \mkdir($extDir, 0777, true) && ! \is_dir($extDir)) {
        throw new \RuntimeException("cannot create {$extDir}");
    }
    $destination = $extDir . '/' . \basename($built[0]);
    if (\realpath(\dirname($built[0])) !== \realpath($extDir)
        && ! \copy($built[0], $destination)) {
        throw new \RuntimeException('cannot copy the built parallel extension into the runtime');
    }
}

/**
 * The C compilers a from-source build may be driven by.
 *
 * A packaging host builds both the bundled PHP and (through static-php-cli) the
 * micro runtime, so it needs one either way. Naming the candidates is what lets
 * the check fail before the build rather than inside `configure`, whose own
 * message (`no acceptable C compiler found`) does not say which build wanted it.
 *
 * @return list<string>
 */
function cCompilerCandidates(string $target): array
{
    return \str_starts_with($target, 'windows-')
        ? ['cl', 'clang', 'gcc']
        : ['cc', 'clang', 'gcc'];
}

/**
 * Fail clearly, before a build starts, when the host has no C toolchain.
 *
 * A from-source PHP build needs a C compiler and `make`; static-php-cli's own
 * `doctor` step installs what it can find a packager for, but it cannot conjure
 * a compiler out of nothing. The error names the build that needed the
 * toolchain, so a caller that only asked for an unbundled runtime still knows
 * why the packaging run stopped.
 */
function assertCToolchain(string $target, string $what): void
{
    $missing = [];
    $compilers = cCompilerCandidates($target);
    if (\array_filter($compilers, static fn (string $c): bool => findExecutable($c) !== null) === []) {
        $missing[] = \implode(', ', $compilers);
    }
    if (! \str_starts_with($target, 'windows-')
        && findExecutable('make') === null
        && findExecutable('gmake') === null) {
        $missing[] = 'make';
    }
    if ($missing !== []) {
        throw new \RuntimeException(
            "{$what} needs a C toolchain, but no " . \implode(' and no ', $missing) . ' is on PATH',
        );
    }
}
