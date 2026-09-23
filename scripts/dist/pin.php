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
 *   php scripts/dist/pin.php [--target T]… [--runtime R]… [--check] [--quiet]
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
];

function usage(): int
{
    \fwrite(STDOUT, "usage: php scripts/dist/pin.php [--target T]… [--runtime R]… [--check]\n");

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

/**
 * The architecture/OS token the asset for a target must carry in its filename.
 *
 * This is what catches the failure mode that matters most: a URL that resolves
 * and extracts, but belongs to another architecture. An upstream source release
 * is architecture-independent, so it names none and is exempt.
 */
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
        default => null,
    };
}

function assertAssetMatchesTarget(string $runtime, string $url, string $target, array $config): void
{
    $token = expectedAssetToken($runtime, $target, $config);
    if ($token === null || $token === '') {
        return;
    }
    if (!\str_contains(\basename((string) \parse_url($url, \PHP_URL_PATH)), $token)) {
        throw new \RuntimeException("{$runtime}: asset name does not name {$target} ({$token})");
    }
}

/**
 * Fetch the first byte of an asset: proves the pinned URL still resolves to a
 * real file, without downloading it.
 */
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

/**
 * Upstream's own checksum for an asset, when it publishes one: a `.sha256`
 * sidecar, .NET's release metadata (SHA-512 per RID), php.net's release metadata
 * (source archives only), or Adoptium's asset API. Null when there is none.
 */
function upstreamSha256(string $runtime, string $url, array $asset, string $target, array $config): ?array
{
    if (isset($asset['sha256Url'])) {
        $sidecar = expandAssetTemplate((string) $asset['sha256Url'], $runtime, $target, $config);
        try {
            $file = httpGet($sidecar);
            $text = (string) \file_get_contents($file);
            @\unlink($file);
            if (\preg_match('/\b([0-9a-f]{64})\b/i', $text, $m) === 1) {
                return ['sha256' => \strtolower($m[1]), 'source' => 'sidecar'];
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

/**
 * The hash to pin: upstream's own when it publishes one, otherwise a download
 * of the asset (the release is still real and archived, but with no published
 * digest, the only way to pin it is to hash what upstream served now).
 */
function resolveChecksum(string $runtime, string $url, array $asset, string $target, array $config): array
{
    $published = upstreamSha256($runtime, $url, $asset, $target, $config);
    if ($published !== null) {
        return $published;
    }

    $cacheDir = distRoot() . '/../.dist-cache/downloads';
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

function main(array $argv): int
{
    $options = parsePinArgv($argv);
    $config = loadRuntimeConfig();
    $targets = selectTargets($config, $options['targets']);
    $runtimes = $options['runtimes'] !== [] ? $options['runtimes'] : RUNTIME_NAMES;

    $lockPath = distRoot() . '/runtimes.lock.json';
    $lock = ['generated' => \gmdate('c'), 'assets' => []];
    if (!$options['check'] && \is_file($lockPath)) {
        $existing = \json_decode((string) \file_get_contents($lockPath), true);
        if (\is_array($existing['assets'] ?? null)) {
            $lock['assets'] = $existing['assets'];
        }
    }

    $report = [];
    $failed = false;
    $cacheDir = distRoot() . '/../.dist-cache';
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
    unset($cacheDir);

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
