<?php declare(strict_types=1);

/**
 * `--native --static --package` must produce an executable that is genuinely
 * static, runs, and runs from its bundle.
 *
 * CI sets MOGGI_NATIVE_BACKEND on each OS/backend leg and sets
 * MOGGI_STATIC_REQUIRED=1 where the build is expected to run. Linux builds and
 * checks the result; macOS and Windows cannot make a fully static executable,
 * so there the test asserts that the request is refused for the selected
 * backend rather than treating an impossible link as a pass.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require_once $root . '/src/compiler.php';
require_once $root . '/src/backend/dotnet/package.php';
require_once $root . '/tests/suite/support/process.php';

use function Moggi\Backend\Php\resolveMicroSfx;
use function Moggi\CLI\Commands\removeOutputTree;
use function Moggi\CLI\Commands\resolveNativeImageExecutable;
use function Moggi\CLI\Commands\staticLinkingProblem;
use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\runProcess;

$backend = \getenv('MOGGI_NATIVE_BACKEND');
if (! \is_string($backend) || ! \in_array($backend, ['php', 'jvm', 'dotnet'], true)) {
    if (\getenv('MOGGI_STATIC_REQUIRED') === '1') {
        \fwrite(STDERR, "native static package tests require MOGGI_NATIVE_BACKEND=php, jvm or dotnet\n");
        exit(1);
    }
    echo "native static package tests skipped (set MOGGI_NATIVE_BACKEND to php, jvm or dotnet)\n";
    exit(0);
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};
$skip = static function (string $message): void {
    if (\getenv('MOGGI_STATIC_REQUIRED') === '1') {
        \fwrite(STDERR, $message . "\n");
        exit(1);
    }
    echo $message . "\n";
    exit(0);
};

$output = \sys_get_temp_dir() . '/moggi-native-static-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
$app = $root . '/examples/factorial';
$privateApp = null;
$env = \getenv();
if (! \is_array($env)) {
    $env = [];
}
$env['MOGGI_CACHE_DIR'] = $output . '-cache';
$cleanup = static function () use ($output, &$privateApp): void {
    removeOutputTree($output . '-cache');
    if ($privateApp !== null) {
        removeOutputTree($privateApp);
    }
    foreach ([
        $output,
        $output . '.exe',
        $output . '.phar',
        $output . '.jar',
        $output . '.dll',
        $output . '.runtimeconfig.json',
        $output . '.deps.json',
    ] as $path) {
        @\unlink($path);
    }
    foreach (['', '.exe'] as $suffix) {
        removeOutputTree($output . $suffix . '.bundle');
    }
};

try {
    if (\PHP_OS_FAMILY !== 'Linux') {
        $static = runCompiledProcess(
            [PHP_BINARY, $root . '/moggi.php', 'compile', $app, '--backend', $backend, '--native', '--static'],
            60,
            \getenv(),
            $root,
        );
        $assert(($static['exitCode'] ?? 0) === 1, '--static must be refused on this platform');
        $assert(
            \str_contains((string) ($static['stderr'] ?? ''), '--static is not possible'),
            'the static refusal must explain why the platform cannot do it: ' . ($static['stderr'] ?? ''),
        );

        $musl = runCompiledProcess(
            [PHP_BINARY, $root . '/moggi.php', 'compile', $app, '--backend', $backend, '--native', '--musl'],
            60,
            \getenv(),
            $root,
        );
        $assert(($musl['exitCode'] ?? 0) === 1, '--musl must be refused on this platform');
        $assert(
            \str_contains((string) ($musl['stderr'] ?? ''), '--musl is only supported'),
            'the musl refusal must say it is Linux-only: ' . ($musl['stderr'] ?? ''),
        );

        echo "native static package tests passed for {$backend} ({$checks} checks)\n";
        exit(0);
    }

    if ($backend === 'php') {
        $micro = resolveMicroSfx();
        if ($micro === null) {
            $skip('native static package tests skipped for php (no micro runtime)');
        }
        $env['MOGGI_MICRO_SFX'] = $micro;
        \putenv('MOGGI_MICRO_SFX=' . $micro);
    }

    if ($backend === 'jvm') {
        $machine = \strtolower(\php_uname('m'));
        $arch = \in_array($machine, ['aarch64', 'arm64'], true) ? 'aarch64' : 'x86_64';
        if (resolveNativeImageExecutable() === null || findExecutable($arch . '-linux-musl-gcc') === null) {
            $skip('native static package tests skipped for jvm (no native-image or musl C toolchain)');
        }
    }

    if ($backend === 'dotnet' && ! \is_file('/etc/alpine-release')) {
        $skip('native static package tests skipped for dotnet (a musl host is required)');
    }

    $privateApp = $output . '-app';
    \mkdir($privateApp, 0777, true);
    \copy($root . '/examples/factorial/Main.mog', $privateApp . '/Main.mog');
    $app = $privateApp;

    $built = runCompiledProcess(
        [
            PHP_BINARY, $root . '/moggi.php', 'compile', $app,
            '--backend', $backend, '--native', '--static', '--package', '-o', $output,
        ],
        $backend === 'php' ? 300 : 900,
        $env,
        $root,
    );

    $assert(
        ($built['exitCode'] ?? 1) === 0,
        "native --static --package build failed:\n" . ($built['stdout'] ?? '') . "\n" . ($built['stderr'] ?? ''),
    );

    $exe = $output;
    if (! \is_file($exe) && \is_file($exe . '.exe')) {
        $exe .= '.exe';
    }
    $assert(\is_file($exe), '--static wrote no executable');
    $assert(staticLinkingProblem($exe) === null, 'the built executable is not static');

    $ldd = findExecutable('ldd');
    if ($ldd !== null) {
        $linked = runProcess([$ldd, $exe]);
        $text = \trim((string) $linked['stdout'] . (string) $linked['stderr']);
        $muslLoader = \is_file('/etc/alpine-release')
            && \preg_match('/^\/lib\/ld-musl-[^\s]+ \(0x[0-9a-f]+\)$/i', $text) === 1;
        $assert(
            $muslLoader
                || \preg_match('/not a dynamic executable|statically linked|not a valid dynamic program/i', $text) === 1,
            'ldd did not report a static executable: ' . $text,
        );
    }

    $ran = runCompiledProcess([$exe], 30, $env, \sys_get_temp_dir());
    $assert(
        ($ran['exitCode'] ?? 1) === 0,
        'the static executable failed: ' . ($ran['stderr'] ?? ''),
    );
    $assert(
        \trim((string) ($ran['stdout'] ?? '')) === '3628800',
        'the static executable printed: ' . ($ran['stdout'] ?? ''),
    );

    $bundle = $exe . '.bundle';
    $bundled = $bundle . DIRECTORY_SEPARATOR . \basename($exe);
    $assert(\is_file($bundled), '--package wrote no executable inside the bundle');
    $assert(\is_file($bundle . '/README.txt'), '--package wrote no bundle README');
    $assert(staticLinkingProblem($bundled) === null, 'the bundled executable is not static after relinking');

    $fromBundle = runCompiledProcess([$bundled], 30, $env, \sys_get_temp_dir());
    $assert(
        ($fromBundle['exitCode'] ?? 1) === 0,
        'the bundled static executable failed: ' . ($fromBundle['stderr'] ?? ''),
    );
    $assert(
        \trim((string) ($fromBundle['stdout'] ?? '')) === '3628800',
        'the bundled static executable printed: ' . ($fromBundle['stdout'] ?? ''),
    );
    $assert(
        \glob($bundle . '/*.so*') === [],
        'a static bundle must not carry shared libraries',
    );
} finally {
    $cleanup();
}

echo "native static package tests passed for {$backend} ({$checks} checks)\n";
