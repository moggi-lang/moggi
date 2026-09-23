<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Backend\DotNet\dotnetCliEnv;
use function Moggi\Backend\DotNet\resolveDotnetExecutable;
use function Moggi\Cache\compilerFingerprint;
use function Moggi\Compiler\compilerChannel;
use function Moggi\Compiler\compilerCommit;
use function Moggi\Compiler\compilerVersion;
use function Moggi\Compiler\distributionVariant;
use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\findToolchainExecutable;
use function Moggi\Compiler\stdlibVersion;

/**
 * `moggi version` — the identity of this compiler build.
 *
 * Reports the two version numbers (compiler, stdlib), the build identity
 * (channel, commit, variant), the compiler-source fingerprint the compile cache
 * is keyed by, and the host toolchains the backends need. Toolchain probes are
 * best-effort: a tool that is absent reports as `not found` and never fails the
 * command, because a missing backend toolchain is not a broken compiler.
 */
function runVersionCommand(array $argv): int
{
    $info = versionInfo();

    if (\in_array('--json', $argv, true)) {
        echo \json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        return 0;
    }

    $absent = [
        'compiler' => 'unknown',
        'stdlib' => 'unknown',
        'commit' => '-',
        'variant' => '-',
        'php' => 'not found',
        'java' => 'not found',
        'dotnet' => 'not found',
        'native-image' => 'not found',
    ];
    foreach ($info as $field => $value) {
        \printf("%-14s%s\n", $field . ':', $value ?? ($absent[$field] ?? 'unknown'));
    }

    return 0;
}

/**
 * @return array{
 *   compiler: ?string, stdlib: ?string, fingerprint: string, channel: string,
 *   commit: ?string, variant: ?string, php: string, java: ?string,
 *   dotnet: ?string, 'native-image': ?string
 * }
 */
function versionInfo(): array
{
    $compiler = compilerVersion();

    return [
        'compiler' => $compiler,
        'stdlib' => stdlibVersion(),
        'fingerprint' => compilerFingerprint(),
        'channel' => compilerChannel($compiler),
        'commit' => compilerCommit($compiler),
        'variant' => distributionVariant(),
        'php' => PHP_VERSION,
        'java' => javaVersion(),
        'dotnet' => dotnetVersion(),
        'native-image' => nativeImageVersion(),
    ];
}

/** `java -version` writes its banner to stderr, hence the combined capture. */
function javaVersion(): ?string
{
    $java = resolveJavaExecutable();
    if ($java === null) {
        return null;
    }
    $output = captureProcessOutput([$java, '-version']);
    if ($output === null) {
        return null;
    }

    return \preg_match('/"([^"]+)"/', $output, $m) === 1 ? $m[1] : null;
}

/** `JAVA_HOME/bin/java` when set and executable, else `java` on PATH. */
function resolveJavaExecutable(): ?string
{
    return findToolchainExecutable('JAVA_HOME', 'java') ?? findExecutable('java');
}

/** `native-image --version` prints its build id on the first line. */
function nativeImageVersion(): ?string
{
    $nativeImage = resolveNativeImageExecutable();
    if ($nativeImage === null) {
        return null;
    }
    $output = captureProcessOutput([$nativeImage, '--version']);
    if ($output === null) {
        return null;
    }

    return \preg_match('/^(?:native-image\s+)?(\S+)/', \trim($output), $m) === 1 ? $m[1] : null;
}

function dotnetVersion(): ?string
{
    $dotnet = resolveDotnetExecutable();
    if ($dotnet === null) {
        return null;
    }
    $output = captureProcessOutput([$dotnet, '--version'], dotnetCliEnv());
    if ($output === null) {
        return null;
    }

    return \preg_match('/^v?(\d+\.\d+\S*)/m', $output, $m) === 1 ? $m[1] : null;
}

/**
 * Run a command and return its combined output, or null if it cannot start.
 *
 * The command is passed as an argv list, so nothing goes through a shell.
 *
 * @param list<string> $argv
 * @param ?array<string, string> $env
 */
function captureProcessOutput(array $argv, ?array $env = null): ?string
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = $env === null
        ? \proc_open($argv, $descriptors, $pipes)
        : \proc_open($argv, $descriptors, $pipes, null, $env);
    if (!\is_resource($proc)) {
        return null;
    }
    \fclose($pipes[0]);
    $stdout = (string) \stream_get_contents($pipes[1]);
    $stderr = (string) \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    \proc_close($proc);

    return $stdout . $stderr;
}
