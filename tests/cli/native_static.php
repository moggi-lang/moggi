<?php declare(strict_types=1);

/**
 * `--static` and `--musl` are requests about a native executable's linking.
 *
 * Both are only meaningful with `--native`, the linker flags differ per
 * backend, and the result is verified rather than trusted. The native builds
 * themselves are covered by the smoke and distribution tests; this file pins
 * the command construction and the refusal paths that do not need a native
 * toolchain.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require_once $root . '/src/compiler.php';
require_once $root . '/src/backend/dotnet/package.php';
require_once $root . '/tests/suite/support/process.php';

use function Moggi\Backend\DotNet\dotnetMuslRuntimeIdentifier;
use function Moggi\Backend\DotNet\dotnetNativePublishCommand;
use function Moggi\CLI\Commands\executableCompileFlags;
use function Moggi\CLI\Commands\jvmNativeImageCommand;
use function Moggi\CLI\Commands\muslLinkingProblem;
use function Moggi\CLI\Commands\staticLinkingProblem;
use function Moggi\Compiler\findExecutable;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$jvm = jvmNativeImageCommand('/graalvm/bin/native-image', 'moggi-app.jar', 'app', true);
$assert($jvm === [
    '/graalvm/bin/native-image', '--static', '--libc=musl', '-jar',
    'moggi-app.jar', '--no-fallback', 'app',
], 'jvm --static must request GraalVM static musl mode: ' . \implode(' ', $jvm));

$jvmMusl = jvmNativeImageCommand('/graalvm/bin/native-image', 'moggi-app.jar', 'app', false, true);
$assert($jvmMusl === [
    '/graalvm/bin/native-image', '--libc=musl', '-jar',
    'moggi-app.jar', '--no-fallback', 'app',
], 'jvm --musl must request GraalVM musl mode: ' . \implode(' ', $jvmMusl));

$jvmBoth = jvmNativeImageCommand('/graalvm/bin/native-image', 'moggi-app.jar', 'app', true, true);
$assert($jvmBoth === [
    '/graalvm/bin/native-image', '--static', '--libc=musl', '-jar',
    'moggi-app.jar', '--no-fallback', 'app',
], 'jvm --static --musl must request both modes: ' . \implode(' ', $jvmBoth));

$jvmDynamic = jvmNativeImageCommand('/graalvm/bin/native-image', 'moggi-app.jar', 'app', false);
$assert(! \in_array('--static', $jvmDynamic, true), 'jvm without --static must not add --static');
$assert(! \in_array('--libc=musl', $jvmDynamic, true), 'jvm without --musl must not change libc');

$descriptorFlags = executableCompileFlags(
    ['native' => true, 'static' => true, 'musl' => false, 'package' => true],
    [],
);
$assert(
    $descriptorFlags === ['--native', '--static', '--package'],
    'descriptor flags must become compile flags: ' . \implode(' ', $descriptorFlags),
);
$deduped = executableCompileFlags(
    ['native' => true, 'static' => true, 'musl' => true, 'package' => true],
    ['--native', '--package'],
);
$assert(
    $deduped === ['--static', '--musl'],
    'a CLI flag must not be passed twice: ' . \implode(' ', $deduped),
);
$assert(executableCompileFlags([], []) === [], 'no descriptor flags means no flags');

$muslRid = dotnetMuslRuntimeIdentifier();
$dotnet = dotnetNativePublishCommand('/dotnet', 'app.ilproj', '/out', 'app', true);
$assert(
    \in_array('-p:StaticExecutable=true', $dotnet, true),
    'dotnet --static must pass StaticExecutable=true: ' . \implode(' ', $dotnet),
);
$assert(
    \in_array($muslRid, $dotnet, true),
    'dotnet --static must use musl, whose static link is the supported one: ' . \implode(' ', $dotnet),
);
$assert(
    \in_array('-p:InvariantGlobalization=true', $dotnet, true),
    'dotnet --static must not depend on a host ICU: ' . \implode(' ', $dotnet),
);
$assert(
    \in_array('-p:LinkerFlavor=lld', $dotnet, true),
    'dotnet --static must use lld for its static link: ' . \implode(' ', $dotnet),
);
$dotnetDynamic = dotnetNativePublishCommand('/dotnet', 'app.ilproj', '/out', 'app', false);
$assert(
    ! \in_array('-p:StaticExecutable=true', $dotnetDynamic, true),
    'dotnet without --static must not pass StaticExecutable=true',
);
$assert(
    ! \in_array($muslRid, $dotnetDynamic, true),
    'dotnet without --musl or --static must keep the host runtime identifier',
);
$assert(
    ! \in_array('-p:InvariantGlobalization=true', $dotnetDynamic, true),
    'dotnet without --musl or --static must keep the host globalization mode',
);
$assert(
    ! \in_array('-p:LinkerFlavor=lld', $dotnetDynamic, true),
    'dotnet without --static must keep the host linker',
);

$dotnetMusl = dotnetNativePublishCommand('/dotnet', 'app.ilproj', '/out', 'app', false, true);
$assert(
    \in_array('-r', $dotnetMusl, true) && \in_array($muslRid, $dotnetMusl, true),
    'dotnet --musl must select the musl runtime identifier: ' . \implode(' ', $dotnetMusl),
);
$assert(
    \in_array('-p:InvariantGlobalization=true', $dotnetMusl, true),
    'dotnet --musl must not depend on a host ICU: ' . \implode(' ', $dotnetMusl),
);
$dotnetBoth = dotnetNativePublishCommand('/dotnet', 'app.ilproj', '/out', 'app', true, true);
$assert(
    \in_array('-p:StaticExecutable=true', $dotnetBoth, true) && \in_array($muslRid, $dotnetBoth, true),
    'dotnet --static --musl must select both modes: ' . \implode(' ', $dotnetBoth),
);
$assert(
    \count(\array_keys($dotnetBoth, $muslRid, true)) === 1,
    'dotnet --static --musl must name the musl runtime identifier once: ' . \implode(' ', $dotnetBoth),
);

if (\PHP_OS_FAMILY === 'Linux' && findExecutable('readelf') !== null) {
    $dynamic = '/bin/true';
    $readelf = findExecutable('readelf');
    if (\is_file($dynamic) && $readelf !== null) {
        $headers = \Moggi\Compiler\runProcess([$readelf, '-l', $dynamic]);
        if (\preg_match('/^\s*INTERP\b/m', $headers['stdout']) === 1) {
            $assert(
                staticLinkingProblem($dynamic) !== null,
                'a dynamically linked host executable must not pass the static check',
            );
        }
    }

    $micro = $root . '/.dist-cache/runtimes/linux-x86_64/php-native/micro.sfx';
    if (\is_file($micro)) {
        $assert(
            staticLinkingProblem($micro) === null,
            'the static micro PHP runtime must pass the static check',
        );
        $assert(
            muslLinkingProblem($micro) === null,
            'the static micro PHP runtime must pass the musl check',
        );
    }
}

$app = $root . '/examples/factorial';
$refused = runCompiledProcess(
    [PHP_BINARY, $root . '/moggi.php', 'compile', $app, '--static'],
    60,
    \getenv(),
    $root,
);
$assert(($refused['exitCode'] ?? 0) === 1, '--static without --native must be refused');
$assert(
    \str_contains($refused['stderr'], '--static requires --native'),
    'the refusal must name both flags: ' . $refused['stderr'],
);

$muslRefused = runCompiledProcess(
    [PHP_BINARY, $root . '/moggi.php', 'compile', $app, '--musl'],
    60,
    \getenv(),
    $root,
);
$assert(($muslRefused['exitCode'] ?? 0) === 1, '--musl without --native must be refused');
$assert(
    \str_contains($muslRefused['stderr'], '--musl requires --native'),
    'the refusal must name both flags: ' . $muslRefused['stderr'],
);

$runRefused = runCompiledProcess(
    [PHP_BINARY, $root . '/moggi.php', 'run', $app, '--static'],
    60,
    \getenv(),
    $root,
);
$assert(($runRefused['exitCode'] ?? 0) === 1, 'run --static without --native must be refused');
$assert(
    \str_contains($runRefused['stderr'], '--static requires --native'),
    'run refusal must name both flags: ' . $runRefused['stderr'],
);

$runMuslRefused = runCompiledProcess(
    [PHP_BINARY, $root . '/moggi.php', 'run', $app, '--musl'],
    60,
    \getenv(),
    $root,
);
$assert(($runMuslRefused['exitCode'] ?? 0) === 1, 'run --musl without --native must be refused');
$assert(
    \str_contains($runMuslRefused['stderr'], '--musl requires --native'),
    'run refusal must name both flags: ' . $runMuslRefused['stderr'],
);

$packageRefused = runCompiledProcess(
    [PHP_BINARY, $root . '/moggi.php', 'compile', $app, '--package'],
    60,
    \getenv(),
    $root,
);
$assert(($packageRefused['exitCode'] ?? 0) === 1, '--package without --native must be refused');
$assert(
    \str_contains($packageRefused['stderr'], '--package requires --native'),
    'the package refusal must name both flags: ' . $packageRefused['stderr'],
);

if (\PHP_OS_FAMILY !== 'Linux') {
    $platformStatic = runCompiledProcess(
        [PHP_BINARY, $root . '/moggi.php', 'compile', $app, '--native', '--static'],
        60,
        \getenv(),
        $root,
    );
    $assert(($platformStatic['exitCode'] ?? 0) === 1, '--static must be refused on this platform');
    $assert(
        \str_contains($platformStatic['stderr'], '--static is not possible'),
        'the platform refusal must explain why static is impossible: ' . $platformStatic['stderr'],
    );

    $platformMusl = runCompiledProcess(
        [PHP_BINARY, $root . '/moggi.php', 'compile', $app, '--native', '--musl'],
        60,
        \getenv(),
        $root,
    );
    $assert(($platformMusl['exitCode'] ?? 0) === 1, '--musl must be refused on this platform');
    $assert(
        \str_contains($platformMusl['stderr'], '--musl is only supported'),
        'the platform refusal must say musl is Linux-only: ' . $platformMusl['stderr'],
    );
}

foreach (['compile', 'run'] as $command) {
    $help = runCompiledProcess(
        [PHP_BINARY, $root . '/moggi.php', $command, '--help'],
        30,
        \getenv(),
        $root,
    );
    $assert(($help['exitCode'] ?? 1) === 0, "{$command} --help failed");
    $helpText = (string) ($help['stdout'] ?? '');
    $assert(\str_contains($helpText, '--static'), "{$command} --help names no --static flag");
    $assert(\str_contains($helpText, '--musl'), "{$command} --help names no --musl flag");
    if ($command === 'compile') {
        $assert(\str_contains($helpText, '--package'), "{$command} --help names no --package flag");
    }
}

if (\PHP_OS_FAMILY === 'Linux' && findExecutable('readelf') !== null) {
    $micro = \getenv('MOGGI_MICRO_SFX');
    if (\is_string($micro) && $micro !== '' && \is_file($micro)) {
        $output = \sys_get_temp_dir() . '/moggi-static-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        try {
            $built = runCompiledProcess(
                [PHP_BINARY, $root . '/moggi.php', 'compile', $app, '--native', '--static', '--musl', '--package', '-o', $output],
                180,
                \getenv(),
                $root,
            );
            $assert(
                ($built['exitCode'] ?? 1) === 0,
                'native --static --musl --package build failed: ' . ($built['stderr'] ?? ''),
            );
            $assert(\is_file($output), 'native --static --musl build wrote no executable');
            $assert(staticLinkingProblem($output) === null, 'the built executable is not static');
            $assert(muslLinkingProblem($output) === null, 'the built executable is not musl');
            $ran = runCompiledProcess([$output], 30);
            $assert(($ran['exitCode'] ?? 1) === 0, 'the static executable failed: ' . ($ran['stderr'] ?? ''));
            $assert(\trim((string) ($ran['stdout'] ?? '')) === '3628800', 'the static executable printed: ' . ($ran['stdout'] ?? ''));

            $bundle = $output . '.bundle';
            $bundled = $bundle . '/' . \basename($output);
            $assert(\is_file($bundled), '--package wrote no executable inside the bundle');
            $assert(\is_file($bundle . '/README.txt'), '--package wrote no bundle README');
            $bundledRun = runCompiledProcess([$bundled], 30);
            $assert(($bundledRun['exitCode'] ?? 1) === 0, 'the bundled executable failed: ' . ($bundledRun['stderr'] ?? ''));
            $assert(
                \trim((string) ($bundledRun['stdout'] ?? '')) === '3628800',
                'the bundled executable printed: ' . ($bundledRun['stdout'] ?? ''),
            );
        } finally {
            @\unlink($output);
            @\unlink($output . '.phar');
            \Moggi\CLI\Commands\removeOutputTree($output . '.bundle');
        }
    }
}

echo "native link tests passed ({$checks} checks)\n";
