<?php declare(strict_types=1);

namespace Moggi\Dist;

/**
 * The distribution manifest: what a target is and which runtime it gets.
 *
 * `dist/runtimes.json` states intent — versions, per-target coordinates, asset
 * templates and the build recipe of a derived runtime — and
 * `dist/runtimes.lock.json` states what was actually resolved. This module reads
 * both, expands an asset's `{placeholders}` from the target's coordinates, and
 * answers which runtime a target can ship and why not. It touches the filesystem
 * only to read the two JSON files: downloading, building and installing a runtime
 * live in `runtimes.php` and `phpbuild.php`.
 */

const DIST_ROOT = __DIR__ . '/../dist';

/** Runtimes a distribution may bundle, in the order they appear in `runtime/`. */
const RUNTIME_NAMES = ['php', 'dotnet', 'jvm', 'graalvm', 'composer', 'maven', 'php-native'];

/**
 * Build tools a packaging run downloads and runs but never bundles.
 *
 * `spc` is static-php-cli: it compiles `php-native` and is not shipped, so it
 * does not appear in `runtime/` and a variant that bundles the micro runtime is
 * not a different variant for bundling the tool that built it. `zig` is the
 * compiler `spc` builds with on Linux, pinned like every other build input and
 * installed where `spc` looks for it (see `installZigToolchain`).
 */
const TOOL_NAMES = ['spc', 'zig'];

/** Every runtime a resolution run reports on: the bundled ones and the build tools. */
function pinnedRuntimeNames(): array
{
    return [...RUNTIME_NAMES, ...TOOL_NAMES];
}

/**
 * The runtimes the lock actually holds entries for.
 *
 * A derived runtime is never locked — there is no upstream archive, only a
 * recipe — so it is reported by `pin.php` but has nothing to pin. `pin.php` uses
 * this to drop entries for a runtime that stops being a plain download.
 *
 * @return list<string>
 */
function lockedRuntimeNames(array $config): array
{
    return \array_values(\array_filter(
        pinnedRuntimeNames(),
        static fn (string $runtime): bool => buildRecipe($runtime, $config) === null,
    ));
}

function distRoot(): string
{
    return \realpath(DIST_ROOT) ?: DIST_ROOT;
}

/** @return array<string, mixed> */
function loadRuntimeConfig(): array
{
    $path = distRoot() . '/runtimes.json';
    $raw = \file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("cannot read {$path}");
    }
    $config = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    if (!\is_array($config)) {
        throw new \RuntimeException("{$path} is not a JSON object");
    }

    return $config;
}

/** @return array<string, array{url: string, sha256: string, size?: int, version?: string}> */
function loadRuntimeLock(): array
{
    $path = distRoot() . '/runtimes.lock.json';
    if (!\is_file($path)) {
        throw new \RuntimeException(
            'missing ' . $path . "; run `php packaging/pin.php` to resolve runtime URLs and hashes",
        );
    }
    $raw = \file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("cannot read {$path}");
    }

    $lock = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

    return $lock['assets'];
}

/** @return list<string> */
function knownTargets(array $config): array
{
    return \array_keys($config['targets']);
}

/** Expand `{placeholders}` in an asset template from the target's coordinates. */
function expandAssetTemplate(string $template, string $runtime, string $target, array $config): string
{
    $coords = $config['targets'][$target] ?? throw new \RuntimeException("unknown target {$target}");
    $spec = $config['runtimes'][$runtime];
    $values = [
        'version' => (string) $spec['version'],
        'target' => $target,
        'os' => (string) $coords['os'],
        'arch' => (string) $coords['arch'],
        'dotnetRid' => (string) $coords['dotnetRid'],
        'jvmOs' => (string) $coords['jvmOs'],
        'jvmArch' => (string) $coords['jvmArch'],
        'graalvmAsset' => (string) ($coords['graalvmAsset'] ?? ''),
        'spcAsset' => (string) ($coords['spcAsset'] ?? ''),
        'zigAsset' => (string) ($coords['zigAsset'] ?? ''),
    ];

    return \preg_replace_callback(
        '/\{([A-Za-z]+)\}/',
        static function (array $m) use ($values, $runtime, $target): string {
            if (!\array_key_exists($m[1], $values)) {
                throw new \RuntimeException("unknown placeholder {{$m[1]}} in the {$runtime} asset for {$target}");
            }

            return $values[$m[1]];
        },
        $template,
    ) ?? $template;
}

/**
 * The build recipe of a derived runtime, or null when the runtime is a plain
 * download.
 *
 * A recipe means there is no upstream archive to pin: what identifies the
 * runtime is the tool, the PHP series, the SAPI and the extension list, and
 * `derivedRuntimeKey` is what the cache is keyed on.
 *
 * @return array<string, mixed>|null
 */
function buildRecipe(string $runtime, array $config): ?array
{
    $recipe = $config['runtimes'][$runtime]['build'] ?? null;

    return \is_array($recipe) && $recipe !== [] ? $recipe : null;
}

/** @return array{format: string, url: string, stripComponents: int, build?: bool, binary: string, lockKey: string}|null */
function runtimeAsset(string $runtime, string $target, array $config): ?array
{
    $targetCoords = $config['targets'][$target] ?? throw new \RuntimeException("unknown target {$target}");
    $spec = $config['runtimes'][$runtime];

    if (buildRecipe($runtime, $config) !== null) {
        return null;
    }

    if (isset($spec['unsupported'][$target])) {
        return null;
    }

    $asset = $spec[$target] ?? null;
    if (!\is_array($asset) || !isset($asset['url'])) {
        $platform = (string) $targetCoords['phpPlatform'];
        $candidate = $platform !== 'unavailable' ? ($spec[$platform] ?? null) : null;
        $asset = \is_array($candidate) && isset($candidate['url']) ? $candidate : null;
    }
    if ($asset === null) {
        $asset = ($targetCoords['archiveExt'] ?? '') === 'zip'
            ? ($spec['assetZip'] ?? $spec['asset'] ?? null)
            : ($spec['asset'] ?? null);
    }
    if (!\is_array($asset) || !isset($asset['url'])) {
        return null;
    }

    $url = expandAssetTemplate((string) $asset['url'], $runtime, $target, $config);
    $asset['url'] = $url;
    $asset['format'] = $asset['format'] ?? $targetCoords['archiveExt'];
    $asset['lockKey'] = $runtime . '/' . $target;

    return $asset;
}

/** Why a runtime cannot be shipped for a target, for the build's report. A target
 *  with no PHP (`phpPlatform: unavailable`) still gets its other runtimes. */
function unsupportedReason(string $runtime, string $target, array $config): ?string
{
    $spec = $config['runtimes'][$runtime];
    if (isset($spec['unsupported'][$target])) {
        return (string) $spec['unsupported'][$target];
    }
    $platform = (string) ($config['targets'][$target]['phpPlatform'] ?? '');
    if ($runtime === 'php' && $platform === 'unavailable') {
        return 'no official PHP build for this platform';
    }
    if ($runtime === 'graalvm' && ($config['targets'][$target]['graalvmAsset'] ?? null) === null) {
        return 'no GraalVM build for this platform';
    }

    $recipe = buildRecipe($runtime, $config);
    if ($recipe !== null) {
        $tool = (string) ($recipe['tool'] ?? '');

        return $tool === '' ? 'its build recipe names no tool' : unsupportedReason($tool, $target, $config);
    }

    return null;
}

/**
 * The archive a variant produces for a target: `<variant>-<version>-<target>`, a
 * zip on Windows and a tarball elsewhere. The website's literal download links
 * are named the same way, so the release rewriter and the assembler cannot drift.
 */
function archiveName(string $variant, string $version, string $target): string
{
    $format = \str_starts_with($target, 'windows-') ? 'zip' : 'tar.gz';

    return "{$variant}-{$version}-{$target}.{$format}";
}
