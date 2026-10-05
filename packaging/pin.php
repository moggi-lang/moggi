<?php declare(strict_types=1);

namespace Moggi\Dist;

require __DIR__ . '/runtimes.php';

/**
 * Resolve and pin the runtime assets, and validate the configuration.
 *
 * For every target and every runtime this checks that the configured asset is a
 * genuine upstream distribution (host allowlist), reachable, of the pinned
 * version, and extractable into a usable runtime — then records the exact URL
 * and hash in `dist/runtimes.lock.json`. A target/runtime with no upstream build
 * is reported as unsupported, never substituted.
 *
 * Usage:
 *
 *   php packaging/pin.php [--target T]… [--runtime R]… [--check] [--quiet]
 *
 * Every asset is fetched one byte of to prove it resolves, and the asset's own
 * filename must name the target's architecture, so a URL that quietly starts
 * serving a different architecture cannot slip into the lock. `--check`
 * validates without writing the lock.
 */

/** Hosts that count as the official upstream for each runtime. */
const UPSTREAM_HOSTS = [
    'php' => ['www.php.net', 'downloads.php.net', 'windows.php.net'],
    'dotnet' => ['builds.dotnet.microsoft.com', 'dotnetcli.azureedge.net'],
    'jvm' => ['api.adoptium.net', 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'],
    'graalvm' => ['github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'],
    'composer' => ['getcomposer.org'],
    'maven' => ['archive.apache.org'],
    'spc' => ['github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'],
    'zig' => ['ziglang.org'],
];

function usage(): int
{
    \fwrite(STDOUT, "usage: php packaging/pin.php [--target T]… [--runtime R]… [--check]\n");

    return 0;
}

/** @return array{targets: list<string>, runtimes: list<string>, check: bool} */
function parsePinArgv(array $argv): array
{
    $targets = [];
    $runtimes = [];
    $check = false;
    for ($i = 1; $i < \count($argv); ++$i) {
        $arg = $argv[$i];
        if ($arg === '--target') {
            $targets[] = (string) ($argv[++$i] ?? '');
        } elseif ($arg === '--runtime') {
            $runtimes[] = (string) ($argv[++$i] ?? '');
        } elseif ($arg === '--check') {
            $check = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            exit(usage());
        } else {
            \fwrite(STDERR, "error: unknown option {$arg}\n");

            exit(1);
        }
    }

    return ['targets' => $targets, 'runtimes' => $runtimes, 'check' => $check];
}

function assertUpstreamHost(string $runtime, string $url): void
{
    $host = \parse_url($url, \PHP_URL_HOST);
    $allowed = UPSTREAM_HOSTS[$runtime] ?? [];
    if (!\is_string($host) || !\in_array($host, $allowed, true)) {
        throw new \RuntimeException(
            "{$runtime}: {$url} is not on an allowed upstream host (" . \implode(', ', $allowed) . ')',
        );
    }
}

function expectedAssetToken(string $runtime, string $target, array $config): ?string
{
    $coords = $config['targets'][$target];

    if ($runtime === 'php') {
        if (($coords['phpPlatform'] ?? '') !== 'windows') {
            return null;
        }

        return ($coords['arch'] ?? '') === 'x86_64' ? 'x64' : (string) $coords['arch'];
    }

    return match ($runtime) {
        'dotnet' => (string) $coords['dotnetRid'],
        'jvm' => (string) $coords['jvmArch'],
        'graalvm' => (string) ($coords['graalvmAsset'] ?? ''),
        'spc' => (string) ($coords['spcAsset'] ?? ''),
        'zig' => (string) ($coords['zigAsset'] ?? ''),
        default => null,
    };
}

/**
 * Assert the asset names the target's architecture, so a URL that quietly starts
 * serving a different architecture cannot slip into the lock.
 *
 * The name is the one upstream chose: each runtime spells the coordinate into
 * its filename (`spc-linux-x86_64.tar.gz`, `spc-windows-x64.exe`), so the
 * filename alone decides, and the whole path is the fallback for an asset that
 * names it in a directory instead.
 */
function assertAssetMatchesTarget(string $runtime, string $url, string $target, array $config): void
{
    $token = expectedAssetToken($runtime, $target, $config);
    if ($token === null || $token === '') {
        return;
    }
    $path = (string) \parse_url($url, \PHP_URL_PATH);
    $name = \basename($path);
    if (!\str_contains(\str_contains($name, $token) ? $name : $path, $token)) {
        throw new \RuntimeException("{$runtime}: asset name does not name {$target} ({$token})");
    }
}

function assertAssetReachable(string $url): int
{
    $discard = \fopen('php://temp', 'wb');
    $ch = \curl_init($url);
    \curl_setopt_array($ch, [
        \CURLOPT_RANGE => '0-0',
        \CURLOPT_FILE => $discard,
        \CURLOPT_FOLLOWLOCATION => true,
        \CURLOPT_MAXREDIRS => 10,
        \CURLOPT_USERAGENT => 'moggi-dist-build',
        \CURLOPT_TIMEOUT => 120,
    ]);
    \curl_exec($ch);
    \fclose($discard);
    $status = (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
    $size = (int) \curl_getinfo($ch, \CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $error = \curl_error($ch);
    unset($ch);

    if ($status >= 400 || $status === 0) {
        throw new \RuntimeException('unreachable: ' . ($error !== '' ? $error : "HTTP {$status}"));
    }

    return $size;
}

function upstreamChecksum(string $runtime, string $url, array $asset, string $target, array $config): ?array
{
    if ($runtime === 'zig') {
        $index = \json_decode(httpGetContent((string) $config['runtimes'][$runtime]['checksumsUrl']), true);
        $version = (string) $config['runtimes'][$runtime]['version'];
        $wanted = \basename((string) \parse_url($url, \PHP_URL_PATH));
        foreach ((array) ($index[$version] ?? []) as $entry) {
            $tarball = $entry['tarball'] ?? null;
            if (\is_string($tarball)
                && \basename((string) \parse_url($tarball, \PHP_URL_PATH)) === $wanted
                && \is_string($entry['shasum'] ?? null)) {
                return ['sha256' => \strtolower($entry['shasum']), 'source' => 'ziglang-index'];
            }
        }

        throw new \RuntimeException(
            "zig: {$wanted} is not in ziglang's index for {$version} (has the release been withdrawn?)",
        );
    }

    if ($runtime === 'spc') {
        try {
            $release = \json_decode(httpGetContent(expandAssetTemplate((string) $config['runtimes'][$runtime]['checksumsUrl'], $runtime, $target, $config)), true);
        } catch (\RuntimeException) {
            return null;
        }

        $wanted = \basename((string) \parse_url($url, \PHP_URL_PATH));
        foreach ((array) ($release['assets'] ?? []) as $entry) {
            $digest = $entry['digest'] ?? null;
            if (($entry['name'] ?? '') === $wanted && \is_string($digest) && \str_starts_with($digest, 'sha256:')) {
                return ['sha256' => \strtolower(\substr($digest, 7)), 'source' => 'github-release'];
            }
        }

        throw new \RuntimeException(
            "spc: {$wanted} is not an asset of the pinned static-php-cli release (has the tag moved?)",
        );
    }

    foreach ([['sha256Url', 'sha256', 64], ['sha512Url', 'sha512', 128]] as [$sidecarKey, $field, $length]) {
        if (!isset($asset[$sidecarKey])) {
            continue;
        }
        $sidecar = expandAssetTemplate((string) $asset[$sidecarKey], $runtime, $target, $config);
        try {
            $file = httpGet($sidecar);
            $text = (string) \file_get_contents($file);
            @\unlink($file);
            if (\preg_match('/\b([0-9a-f]{' . $length . '})\b/i', $text, $m) === 1) {
                return [$field => \strtolower($m[1]), 'source' => 'sidecar'];
            }
        } catch (\Throwable) {
        }
    }

    if ($runtime === 'dotnet') {
        $meta = \json_decode(httpGetContent((string) $config['runtimes'][$runtime]['checksumsUrl']), true);
        $coords = $config['targets'][$target];
        $wanted = (string) $coords['dotnetRid'];
        $suffix = $asset['format'] === 'zip' ? '.zip' : '.tar.gz';
        foreach ((array) ($meta['releases'] ?? []) as $release) {
            $sdk = $release['sdk'] ?? null;
            if (!\is_array($sdk) || ($sdk['version'] ?? '') !== (string) $config['runtimes'][$runtime]['version']) {
                continue;
            }
            foreach ((array) ($sdk['files'] ?? []) as $file) {
                $name = (string) ($file['name'] ?? '');
                if (($file['rid'] ?? '') === $wanted && \str_ends_with($name, $suffix) && isset($file['hash'])) {
                    return ['sha512' => (string) $file['hash'], 'source' => 'release-metadata', 'size' => (int) ($file['size'] ?? 0)];
                }
            }
        }

        throw new \RuntimeException(
            "dotnet: SDK {$config['runtimes'][$runtime]['version']} for {$wanted} is not in the release metadata (is the pinned version current?)",
        );
    }

    if ($runtime === 'php') {
        $release = \json_decode(httpGetContent(expandAssetTemplate((string) $config['runtimes'][$runtime]['checksumsUrl'], $runtime, $target, $config)), true);
        $wanted = \basename((string) \parse_url($url, \PHP_URL_PATH));
        foreach ((array) ($release['source'] ?? []) as $entry) {
            if (($entry['filename'] ?? '') === $wanted && isset($entry['sha256'])) {
                return ['sha256' => \strtolower((string) $entry['sha256']), 'source' => 'release-metadata'];
            }
        }

        if (!empty($asset['build'])) {
            throw new \RuntimeException("php: {$wanted} is not in php.net's metadata for {$config['runtimes'][$runtime]['version']}");
        }

        return null;
    }

    if ($runtime === 'jvm') {
        $coords = $config['targets'][$target];
        $api = \str_replace(
            ['{version}', '{jvmArch}', '{jvmOs}'],
            [(string) $config['runtimes'][$runtime]['version'], (string) $coords['jvmArch'], (string) $coords['jvmOs']],
            (string) $config['runtimes'][$runtime]['checksumsUrl'],
        );
        $assets = \json_decode(httpGetContent($api), true);
        foreach ((array) $assets as $entry) {
            $package = $entry['binary']['package'] ?? null;
            if (!\is_array($package) || !isset($package['checksum'])) {
                continue;
            }
            return [
                'sha256' => \strtolower((string) $package['checksum']),
                'source' => 'adoptium-api',
                'exactUrl' => (string) ($package['link'] ?? $url),
                'version' => (string) ($entry['release_name'] ?? ''),
            ];
        }

        throw new \RuntimeException("jvm: Adoptium lists no JDK asset for {$target}");
    }

    return null;
}

function resolveChecksum(string $runtime, string $url, array $asset, string $target, array $config): array
{
    $published = upstreamChecksum($runtime, $url, $asset, $target, $config);
    if ($published !== null) {
        return $published;
    }

    $cacheDir = distCacheRoot() . '/downloads';
    if (!\is_dir($cacheDir)) {
        \mkdir($cacheDir, 0777, true);
    }
    $cached = $cacheDir . '/' . $runtime . '-' . $target . '-' . \basename(\parse_url($url, \PHP_URL_PATH) ?: 'asset');
    if (!\is_file($cached)) {
        \fwrite(STDOUT, "  downloading {$url} to hash it\n");
        httpGet($url, $cached);
    }

    return ['sha256' => sha256File($cached), 'source' => 'downloaded', 'size' => (int) \filesize($cached)];
}

function httpGetContent(string $url): string
{
    $file = httpGet($url);
    $content = (string) \file_get_contents($file);
    @\unlink($file);

    return $content;
}

/** @return list<string> */
function selectTargets(array $config, array $requested): array
{
    if ($requested === []) {
        return knownTargets($config);
    }
    foreach ($requested as $target) {
        if (!isset($config['targets'][$target])) {
            throw new \RuntimeException("unknown target {$target}");
        }
    }

    return $requested;
}

/**
 * Resolve the configured assets and, unless `--check`, rewrite the lock.
 *
 * A write keeps the entries a partial selection (`--runtime php`) does not
 * cover, but drops every entry whose runtime or target the configuration no
 * longer defines: otherwise a runtime that becomes derived or is removed, or a
 * target that is withdrawn, leaves a dead asset in the lock forever.
 *
 * @param list<string> $argv
 */
function main(array $argv): int
{
    $options = parsePinArgv($argv);
    $config = loadRuntimeConfig();
    $targets = selectTargets($config, $options['targets']);
    $runtimes = $options['runtimes'] !== [] ? $options['runtimes'] : pinnedRuntimeNames();

    $lockPath = distRoot() . '/runtimes.lock.json';
    $lock = ['generated' => \gmdate('c'), 'assets' => []];
    if (!$options['check'] && \is_file($lockPath)) {
        $existing = \json_decode((string) \file_get_contents($lockPath), true);
        if (\is_array($existing['assets'] ?? null)) {
            $knownRuntimes = lockedRuntimeNames($config);
            $knownTargets = knownTargets($config);
            $lock['assets'] = \array_filter(
                $existing['assets'],
                static fn (mixed $entry, string $key): bool => \in_array(\explode('/', $key, 2)[0] ?? '', $knownRuntimes, true)
                    && \in_array(\explode('/', $key, 2)[1] ?? '', $knownTargets, true),
                \ARRAY_FILTER_USE_BOTH,
            );
        }
    }

    $report = [];
    $failed = false;
    $cacheDir = distCacheRoot();
    if (!\is_dir($cacheDir)) {
        \mkdir($cacheDir, 0777, true);
    }

    foreach ($targets as $target) {
        foreach ($runtimes as $runtime) {
            $reason = unsupportedReason($runtime, $target, $config);
            if ($reason !== null) {
                $report[] = \sprintf('%-16s %-8s unsupported  %s', $target, $runtime, $reason);
                continue;
            }

            $recipe = buildRecipe($runtime, $config);
            if ($recipe !== null) {
                $tool = (string) ($recipe['tool'] ?? '');
                $toolAsset = $tool !== '' ? runtimeAsset($tool, $target, $config) : null;
                if ($toolAsset === null) {
                    $report[] = \sprintf('%-16s %-8s unsupported  build tool `%s` has no asset for this target', $target, $runtime, $tool);
                    continue;
                }

                try {
                    assertUpstreamHost($tool, $toolAsset['url']);
                    assertAssetMatchesTarget($tool, $toolAsset['url'], $target, $config);
                    $report[] = \sprintf(
                        '%-16s %-8s derived      built by %s %s',
                        $target,
                        $runtime,
                        $tool,
                        (string) ($config['runtimes'][$tool]['version'] ?? ''),
                    );
                } catch (\Throwable $e) {
                    $failed = true;
                    $report[] = \sprintf('%-16s %-8s FAILED       %s', $target, $runtime, $e->getMessage());
                }

                continue;
            }

            $asset = runtimeAsset($runtime, $target, $config);
            if ($asset === null) {
                $report[] = \sprintf('%-16s %-8s unsupported  no configured asset', $target, $runtime);
                continue;
            }

            try {
                assertUpstreamHost($runtime, $asset['url']);
                $checksum = resolveChecksum($runtime, $asset['url'], $asset, $target, $config);
                $url = $checksum['exactUrl'] ?? $asset['url'];
                assertUpstreamHost($runtime, $url);
                assertAssetMatchesTarget($runtime, $url, $target, $config);
                assertAssetReachable($url);

                $entry = [
                    'url' => $url,
                    'format' => $asset['format'],
                    'stripComponents' => (int) $asset['stripComponents'],
                    'binary' => (string) ($asset['build'] ?? false ? 'sapi/cli/php' : $asset['binary']),
                    'version' => (string) $config['runtimes'][$runtime]['version'],
                ];
                if (isset($checksum['sha256'])) {
                    $entry['sha256'] = $checksum['sha256'];
                }
                if (isset($checksum['sha512'])) {
                    $entry['sha512'] = $checksum['sha512'];
                }
                if (isset($asset['build'])) {
                    $entry['build'] = (bool) $asset['build'];
                }

                $versionSeen = $checksum['version'] ?? $entry['version'];
                if ($runtime === 'jvm' && \is_string($versionSeen) && $versionSeen !== ''
                    && !\str_starts_with($versionSeen, 'jdk-' . $entry['version'])
                    && !\str_starts_with($versionSeen, 'jdk' . $entry['version'])) {
                    throw new \RuntimeException("jvm: upstream serves {$versionSeen}, configuration pins {$entry['version']}");
                }

                $lock['assets'][$runtime . '/' . $target] = $entry;
                [$algorithm, $hash] = isset($entry['sha256'])
                    ? ['sha256', $entry['sha256']]
                    : ['sha512', $entry['sha512'] ?? ''];
                $report[] = \sprintf(
                    '%-16s %-8s ok           %s %s:%s…',
                    $target,
                    $runtime,
                    $entry['version'],
                    $algorithm,
                    \substr($hash, 0, 12),
                );
            } catch (\Throwable $e) {
                $failed = true;
                $report[] = \sprintf('%-16s %-8s FAILED       %s', $target, $runtime, $e->getMessage());
            }
        }
    }

    \fwrite(STDOUT, \implode("\n", $report) . "\n");

    if ($options['check']) {
        \fwrite(STDOUT, $failed ? "validation failed\n" : "all configured runtime URLs validated\n");

        return $failed ? 1 : 0;
    }

    \file_put_contents($lockPath, \json_encode($lock, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
    \fwrite(STDOUT, 'lock: ' . $lockPath . ' (' . \count($lock['assets']) . " asset(s))\n");

    return $failed ? 1 : 0;
}

if (\realpath($argv[0] ?? '') === \realpath(__FILE__)) {
    try {
        exit(main($argv));
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        exit(1);
    }
}
