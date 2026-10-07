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
 * The PHP version a micro SAPI runtime is, e.g. `8.5.7`.
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
        'extensions' => microExtensions($runtime, $config),
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
 * names a set of its own, so what a native executable can call is what a PHAR
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
 * The tool is prepared first — it is a pinned download, not a bundled runtime —
 * and `spc craft` then fetches the PHP source and every library the extension
 * list needs, builds them, and leaves the result at `buildroot/bin/micro.sfx`.
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
    $spc = $toolDir . '/' . (string) ($toolEntry['binary'] ?? 'spc');
    if (!\is_file($spc)) {
        throw new \RuntimeException("the {$tool} build tool is missing at {$spc}");
    }

    assertCToolchain($target, "compiling the {$runtime} runtime");

    $extensions = microExtensions($runtime, $config);
    $php = (string) ($recipe['php'] ?? '');
    $sapi = (string) ($recipe['sapi'] ?? 'micro');

    $work = distCacheRoot() . '/build/' . $target . '/' . $runtime;
    if (!\is_dir($work) && !\mkdir($work, 0777, true) && !\is_dir($work)) {
        throw new \RuntimeException("cannot create {$work}");
    }

    if (unsupportedReason('zig', $target, $config) === null) {
        installZigToolchain($target, $config, $work);
    }

    $craft = $work . '/craft.yml';
    \file_put_contents($craft, craftFile($php, $sapi, $extensions));

    $env = microBuildEnvironment($work, $target);

    assertChildCwd($work);
    runOrFail([$spc, 'craft', '--no-motd', '--no-interaction', $craft], $work, true, null, $env);

    $micro = $work . '/buildroot/bin/micro.sfx';
    if (!\is_file($micro)) {
        throw new \RuntimeException("spc built no micro.sfx (expected {$micro})");
    }
    if (!\copy($micro, $dir . '/micro.sfx')) {
        throw new \RuntimeException("cannot copy the built micro runtime into {$dir}");
    }
    @\chmod($dir . '/micro.sfx', 0755);
    writeRuntimeExtensionManifest($dir, $runtime, $extensions);

    runOrFail(
        [
            $spc,
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
    if (!\is_dir($dir . '/license')) {
        throw new \RuntimeException('spc dumped no licences for the micro runtime');
    }

    $version = microSfxVersion($dir . '/micro.sfx');

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
 * @return array<string, string>
 */
function microToolchainEnv(string $target): array
{
    if (!\str_starts_with($target, 'linux-')) {
        return [];
    }

    return [
        'SPC_TARGET' => 'native-native-musl',
        'SPC_SKIP_DOCTOR_CHECK_ITEMS' => 'if musl-wrapper is installed,if musl-cross-make is installed',
    ];
}

/**
 * The `zig-cc` wrapper static-php-cli generates beside the Zig it installs.
 *
 * `spc` puts that directory on `PATH`, and every library build calls this wrapper
 * rather than `zig` directly: it translates the flags a unix `configure` writes
 * into what Zig accepts (`-isystem` for this build's own buildroot,
 * `-march=x86-64` for `-march=x86_64`), and it is what turns `SPC_TARGET` into
 * `zig cc -target …`. A pinned Zig has to arrive with the same wrappers, or `spc`
 * would not recognise the toolchain and would install its own Zig over it.
 *
 * Copied from static-php-cli 2.8.5 (MIT), `src/SPC/scripts/zig-cc.sh`.
 */
const ZIG_CC_WRAPPER = <<<'SH'
#!/usr/bin/env bash

SCRIPT_DIR="$(dirname "${BASH_SOURCE[0]}")"
BUILDROOT_ABS="$(realpath "$SCRIPT_DIR/../../../buildroot/include" 2>/dev/null || true)"
PARSED_ARGS=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        -isystem)
            shift
            ARG="$1"
            shift
            ARG_ABS="$(realpath "$ARG" 2>/dev/null || true)"
            [[ "$ARG_ABS" == "$BUILDROOT_ABS" ]] && PARSED_ARGS+=("-I$ARG") || PARSED_ARGS+=("-isystem" "$ARG")
            ;;
        -isystem*)
            ARG="${1#-isystem}"
            shift
            ARG_ABS="$(realpath "$ARG" 2>/dev/null || true)"
            [[ "$ARG_ABS" == "$BUILDROOT_ABS" ]] && PARSED_ARGS+=("-I$ARG") || PARSED_ARGS+=("-isystem$ARG")
            ;;
        -march=*|-mcpu=*)
            OPT_NAME="${1%%=*}"
            OPT_VALUE="${1#*=}"
            # Skip armv8- flags entirely as Zig doesn't support them
            if [[ "$OPT_VALUE" == armv8-* ]]; then
                shift
                continue
            fi
            # replace -march=x86-64 with -march=x86_64
            OPT_VALUE="${OPT_VALUE//-/_}"
            PARSED_ARGS+=("${OPT_NAME}=${OPT_VALUE}")
            shift
            ;;
        *)
            PARSED_ARGS+=("$1")
            shift
            ;;
    esac
done

[[ -n "$SPC_TARGET" ]] && TARGET="-target $SPC_TARGET" || TARGET=""

if [[ "$SPC_TARGET" =~ \.[0-9]+\.[0-9]+ ]]; then
    output=$(zig cc $TARGET $SPC_COMPILER_EXTRA "${PARSED_ARGS[@]}" 2>&1)
    status=$?

    if [[ $status -eq 0 ]]; then
        echo "$output"
        exit 0
    fi

    if echo "$output" | grep -qE "version '.*' in target triple"; then
        filtered_output=$(echo "$output" | grep -vE "version '.*' in target triple")
        echo "$filtered_output"
        exit 0
    fi
fi

exec zig cc $TARGET $SPC_COMPILER_EXTRA "${PARSED_ARGS[@]}"
SH;

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
function installZigToolchain(string $target, array $config, string $work): void
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

    $ccWrapper = ZIG_CC_WRAPPER . "\n";
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
    return \implode("\n", [
        '# Written by packaging/runtimes.php for one distribution build.',
        '# static-php-cli compiles the micro PHP runtime from it; see',
        '# dist/runtimes.json for the recipe it is generated from.',
        'php-version: "' . $php . '"',
        'extensions: "' . \implode(',', $extensions) . '"',
        'sapi:',
        '  - ' . $sapi,
        '',
    ]);
}

function buildPhpFromSource(string $runtime, string $target, array $config, string $archive, string $dir, array $entry): string
{
    $spec = $config['runtimes'][$runtime];
    $work = distCacheRoot() . '/build/' . $target . '/' . $runtime . '-' . $spec['version'];
    $source = $work . '/src';

    removeTree($source);
    if (!\mkdir($source, 0777, true) && !\is_dir($source)) {
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

    $jobs = (string) max(1, cpuCount());
    $make = findExecutable('make') ?? 'make';
    $extensions = distributionPhpExtensions();
    runOrFail([$tree . '/configure', '--prefix=' . $work . '/install', ...extensionBuildFlags($extensions, $config)], $tree);
    runOrFail([$make, '-j' . $jobs], $tree);

    if (\is_file($tree . '/LICENSE')) {
        \copy($tree . '/LICENSE', $dir . '/LICENSE');
    }

    $binary = $tree . '/sapi/cli/php';
    if (!\is_file($binary)) {
        throw new \RuntimeException("the source build produced no CLI binary at {$binary}");
    }
    if (!\is_dir($dir . '/bin') && !\mkdir($dir . '/bin', 0777, true) && !\is_dir($dir . '/bin')) {
        throw new \RuntimeException("cannot create {$dir}/bin");
    }
    if (!\copy($binary, $dir . '/bin/php')) {
        throw new \RuntimeException("cannot copy the built PHP binary");
    }
    @\chmod($dir . '/bin/php', 0755);
    stripBinary($dir . '/bin/php');
    copyNonBaselineLibraries($dir . '/bin/php', $dir . '/lib');

    $output = \PHP_OS_FAMILY === 'Windows'
        ? runtimeProcess([$dir . '/bin/php', '-n', '-r', 'echo PHP_VERSION, " ", PHP_OS_FAMILY, "-", php_uname("m");'])
        : runWithLibraryPath([$dir . '/bin/php', '-n', '-r', 'echo PHP_VERSION, " ", PHP_OS_FAMILY, "-", php_uname("m");'], $dir . '/lib');

    return assertRuntimeVersion($runtime, $output, $config, $target);
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
    if (!\str_starts_with($target, 'windows-')
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
