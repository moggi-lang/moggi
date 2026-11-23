<?php declare(strict_types=1);

/**
 * `--native --package` must produce an executable and a sibling bundle that run
 * from a different working directory.
 *
 * The CI matrix sets MOGGI_NATIVE_BACKEND on each OS/backend leg. Without it,
 * the file stays inert so a normal local suite does not add a second native
 * build per backend.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require_once $root . '/src/compiler.php';
require_once $root . '/tests/suite/support/process.php';

use function Moggi\Backend\Php\resolveMicroSfx;
use function Moggi\CLI\Commands\removeOutputTree;

$backend = \getenv('MOGGI_NATIVE_BACKEND');
if (! \is_string($backend) || ! \in_array($backend, ['php', 'jvm', 'dotnet'], true)) {
    echo "native package tests skipped (set MOGGI_NATIVE_BACKEND to php, jvm or dotnet)\n";
    exit(0);
}
if ($backend === 'php' && resolveMicroSfx() === null) {
    echo "native package tests skipped for php (no micro runtime)\n";
    exit(0);
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$output = \sys_get_temp_dir() . '/moggi-native-package-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
$bundle = null;
$relink = null;
$cleanup = static function () use ($output, &$bundle, &$relink): void {
    foreach ([
        $output,
        $output . '.exe',
        $output . '.phar',
        $output . '.jar',
        $output . '.dll',
        $output . '.deps.json',
        $output . '.runtimeconfig.json',
    ] as $path) {
        @\unlink($path);
    }
    if (\is_string($bundle)) {
        removeOutputTree($bundle);
    }
    if (\is_string($relink)) {
        removeOutputTree($relink);
    }
    foreach (['', '.exe'] as $suffix) {
        removeOutputTree($output . $suffix . '.bundle');
    }
};

try {
    $built = runCompiledProcess(
        [
            PHP_BINARY, $root . '/moggi.php', 'compile', $root . '/examples/factorial',
            '--backend', $backend, '--native', '--package', '-o', $output,
        ],
        600,
        \getenv(),
        $root,
    );
    $assert(
        ($built['exitCode'] ?? 1) === 0,
        "native --package build failed:\n" . ($built['stdout'] ?? '') . "\n" . ($built['stderr'] ?? ''),
    );

    $exe = $output;
    if (! \is_file($exe) && \is_file($exe . '.exe')) {
        $exe .= '.exe';
    }
    $assert(\is_file($exe), '--package wrote no executable');
    $bundle = $exe . '.bundle';
    $assert(\is_dir($bundle), '--package wrote no sibling bundle');
    $assert(\is_file($bundle . '/README.txt'), '--package wrote no bundle README');
    $assert(
        \is_file($bundle . DIRECTORY_SEPARATOR . \basename($exe)),
        '--package wrote no executable inside the bundle',
    );

    $direct = runCompiledProcess([$exe], 60, \getenv(), \sys_get_temp_dir());
    $assert(
        ($direct['exitCode'] ?? 1) === 0,
        'the packaged executable failed from another cwd: ' . ($direct['stderr'] ?? ''),
    );
    $assert(
        \trim((string) ($direct['stdout'] ?? '')) === '3628800',
        'the packaged executable printed: ' . ($direct['stdout'] ?? ''),
    );

    $bundled = $bundle . DIRECTORY_SEPARATOR . \basename($exe);
    $fromBundle = runCompiledProcess([$bundled], 60, \getenv(), \sys_get_temp_dir());
    $assert(
        ($fromBundle['exitCode'] ?? 1) === 0,
        'the bundled executable failed from another cwd: ' . ($fromBundle['stderr'] ?? ''),
    );
    $assert(
        \trim((string) ($fromBundle['stdout'] ?? '')) === '3628800',
        'the bundled executable printed: ' . ($fromBundle['stdout'] ?? ''),
    );

    if (\PHP_OS_FAMILY === 'Linux') {
        $cc = \Moggi\Compiler\findExecutable('gcc') ?? \Moggi\Compiler\findExecutable('cc');
        $patchelf = \Moggi\Compiler\findExecutable('patchelf');
        if ($cc !== null && $patchelf !== null) {
            $relink = $output . '-relink';
            \mkdir($relink . '/lib', 0777, true);
            \file_put_contents($relink . '/helper.c', "int helper_value(void) { return 40; }\n");
            \file_put_contents($relink . '/answer.c', "int helper_value(void);\nint answer(void) { return helper_value() + 2; }\n");
            \file_put_contents(
                $relink . '/main.c',
                "#include <stdio.h>\nint answer(void);\nint main(void) { printf(\"%d\\n\", answer()); return 0; }\n",
            );
            $compile = static function (array $argv, string $what) use ($cc, $relink, $assert): void {
                $result = \Moggi\Compiler\runProcess($argv, $relink);
                $assert(
                    $result['exitCode'] === 0,
                    "{$what} failed:\n" . $result['stdout'] . "\n" . $result['stderr'],
                );
            };
            $lib = $relink . '/lib';
            $compile([$cc, '-fPIC', '-shared', '-Wl,-soname,libhelper.so.1', '-o', $lib . '/libhelper.so.1', $relink . '/helper.c'], 'building libhelper');
            $compile([
                $cc, '-fPIC', '-shared', '-Wl,-soname,libanswer.so.1', '-o', $lib . '/libanswer.so.1',
                $relink . '/answer.c', $lib . '/libhelper.so.1', '-Wl,-rpath,$ORIGIN',
            ], 'building libanswer');
            $compile([
                $cc, '-o', $relink . '/app', $relink . '/main.c',
                $lib . '/libanswer.so.1', '-Wl,-rpath,' . $lib,
            ], 'building the relink test executable');

            $relinkBundle = \Moggi\CLI\Commands\bundleNativeExecutable($relink . '/app');
            $assert($relinkBundle !== null, 'the synthetic executable could not be bundled');
            $relinkBundle = (string) $relinkBundle;
            foreach (['app', 'libanswer.so.1', 'libhelper.so.1'] as $name) {
                $assert(\is_file($relinkBundle . '/' . $name), "the bundle is missing {$name}");
                $rpath = \Moggi\Compiler\runProcess([$patchelf, '--print-rpath', $relinkBundle . '/' . $name], $relink);
                $assert(
                    $rpath['exitCode'] === 0 && \trim($rpath['stdout']) === '$ORIGIN',
                    "{$name} was copied but not relinked: " . \trim($rpath['stdout'] . $rpath['stderr']),
                );
            }

            removeOutputTree($lib);
            $relinkRun = runCompiledProcess([$relinkBundle . '/app'], 30, \getenv(), \sys_get_temp_dir());
            $assert(
                ($relinkRun['exitCode'] ?? 1) === 0 && \trim((string) ($relinkRun['stdout'] ?? '')) === '42',
                'the relinked bundle failed with its original libraries removed: '
                    . ($relinkRun['stderr'] ?? '') . ' ' . ($relinkRun['stdout'] ?? ''),
            );
        }
    }
} finally {
    $cleanup();
}

echo "native package tests passed for {$backend} ({$checks} checks)\n";
