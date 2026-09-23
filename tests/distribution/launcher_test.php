#!/usr/bin/env php
<?php declare(strict_types=1);

// The launcher is the only part of a distribution that is neither PHP nor Moggi,
// and everything a user notices about an installed Moggi goes through it: which
// PHP runs, which runtime wins, what the process sees, and what it exits with.
//
// These checks build the launcher for real and drive it against a probe that
// stands in for the compiler archive, so what is asserted is the launcher and
// nothing else. Each case gets its own installation directory, built by copying
// the one compiled launcher and writing the runtime markers the case is about.

$root = __DIR__;
while (!is_file($root . '/launcher/moggi.c') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/distribution.php';

/** What the probe prints: the process the launcher handed over to. */
const LAUNCHER_PROBE = <<<'PHP'
<?php
echo json_encode([
    'php' => PHP_BINARY,
    'args' => array_slice($argv, 1),
    'cwd' => getcwd(),
    'marker' => getenv('MOGGI_TEST_MARKER') ?: '',
    'bundledPhp' => getenv('MOGGI_TEST_BUNDLED_PHP') ?: '',
    'path' => getenv('PATH') ?: '',
    'loader' => (getenv('LD_LIBRARY_PATH') ?: '') . (getenv('DYLD_FALLBACK_LIBRARY_PATH') ?: ''),
    'phpRc' => getenv('PHPRC') ?: '',
    'extDir' => (string) ini_get('extension_dir'),
    'scanDir' => getenv('PHP_INI_SCAN_DIR') ?: '',
    'dotnetRoot' => getenv('DOTNET_ROOT') ?: '',
], JSON_UNESCAPED_SLASHES);
exit((int) (getenv('MOGGI_TEST_EXIT') ?: 0));
PHP;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$isWindows = \PHP_OS_FAMILY === 'Windows';
$exe = $isWindows ? '.exe' : '';

$compiler = distributionCompiler();
if ($compiler === null) {
    \fwrite(STDERR, "launcher test: no C compiler on PATH (tried clang, cc, gcc)\n");
    exit(1);
}

$work = createTempDir('moggi-launcher');
$launcher = $work . '/moggi' . $exe;

$hostConf = $work . '/host-conf.d';
\mkdir($hostConf, 0777, true);
\file_put_contents($hostConf . '/10-absent.ini', "extension=moggi_absent_extension.so\n");

$emptyConf = $work . '/empty-conf.d';
\mkdir($emptyConf, 0777, true);

$build = runCompiledProcess([$compiler, '-std=c11', '-O2', '-Wall', '-Wextra', '-Werror', '-o', $launcher, $root . '/launcher/moggi.c'], 120);
if ($build['exitCode'] !== 0) {
    \fwrite(STDERR, "launcher test: the launcher does not build\n" . $build['stdout'] . $build['stderr']);
    removeDirectory($work);
    exit(1);
}

/**
 * An installation with the launcher, the probe where the compiler archive goes,
 * and the bundled runtimes the case asks for.
 *
 * @param list<string> $runtimes
 */
$install = static function (string $name, array $runtimes) use ($work, $launcher, $exe, $isWindows): string {
    $dir = $work . '/' . $name;
    \mkdir($dir . '/bin', 0777, true);
    \copy($launcher, $dir . '/bin/moggi' . $exe);
    \chmod($dir . '/bin/moggi' . $exe, 0755);
    \file_put_contents($dir . '/bin/moggi.phar', LAUNCHER_PROBE);

    foreach ($runtimes as $runtime) {
        switch ($runtime) {
            case 'php':
                // On Windows the bundled PHP must be the real interpreter, so the
                // installation carries a copy of this one. Everywhere else a
                // wrapper records that it was the one that ran, which is a more
                // precise statement than PHP_BINARY being some path.
                \mkdir($dir . '/runtime/php', 0777, true);
                if ($isWindows) {
                    $source = \dirname(\PHP_BINARY);
                    \copy(\PHP_BINARY, $dir . '/runtime/php/php.exe');
                    foreach (\glob($source . '/*.dll') ?: [] as $dll) {
                        \copy($dll, $dir . '/runtime/php/' . \basename($dll));
                    }
                } else {
                    \mkdir($dir . '/runtime/php/bin', 0777, true);
                    \file_put_contents(
                        $dir . '/runtime/php/bin/php',
                        "#!/bin/sh\nMOGGI_TEST_BUNDLED_PHP=1\nexport MOGGI_TEST_BUNDLED_PHP\nexec " . \escapeshellarg(\PHP_BINARY) . " \"\$@\"\n",
                    );
                    \chmod($dir . '/runtime/php/bin/php', 0755);
                }
                // The ini is what makes the launcher set PHPRC, the `lib`
                // directory is what makes it extend the loader path, and `ext`
                // is what makes it point PHP at the bundled extensions.
                \file_put_contents($dir . '/runtime/php/php.ini', "; test\n");
                \mkdir($dir . '/runtime/php/lib', 0777, true);
                \mkdir($dir . '/runtime/php/ext', 0777, true);
                break;
            case 'jvm':
                \mkdir($dir . '/runtime/jvm/bin', 0777, true);
                \file_put_contents($dir . '/runtime/jvm/bin/java', "#!/bin/sh\nexit 0\n");
                \chmod($dir . '/runtime/jvm/bin/java', 0755);
                break;
            case 'graalvm':
                \mkdir($dir . '/runtime/graalvm/bin', 0777, true);
                \file_put_contents($dir . '/runtime/graalvm/bin/native-image', "#!/bin/sh\nexit 0\n");
                \chmod($dir . '/runtime/graalvm/bin/native-image', 0755);
                break;
            case 'dotnet':
                \mkdir($dir . '/runtime/dotnet', 0777, true);
                \file_put_contents($dir . '/runtime/dotnet/dotnet' . $exe, "#!/bin/sh\nexit 0\n");
                \chmod($dir . '/runtime/dotnet/dotnet' . $exe, 0755);
                break;
        }
    }

    return $dir;
};

/** @param list<string> $args */
/**
 * Run the launcher in an installation.
 *
 * The environment is explicit wherever the host's runtimes matter: the rest of
 * the suite may run on a machine with a JDK installed, and "a missing runtime is
 * an error" must mean the same thing everywhere.
 */
$run = static function (string $installDir, array $args, ?array $env = null, ?string $cwd = null) use ($exe, $work, $hostConf): array {
    $env ??= \getenv();
    if (\is_file($installDir . '/runtime/php/php.ini') && !\array_key_exists('PHP_INI_SCAN_DIR', $env)) {
        $env['PHP_INI_SCAN_DIR'] = $hostConf;
    }
    $cwd ??= $work . '/elsewhere';
    if (!\is_dir($cwd)) {
        \mkdir($cwd, 0777, true);
    }
    $launcher = $installDir . '/bin/moggi' . $exe;
    $command = [$launcher, ...$args];
    $result = runCompiledProcess($command, 60, $env, $cwd);
    if ($result['exitCode'] === 0 && $result['stdout'] !== '' && !\str_starts_with(\ltrim($result['stdout']), '{"php"')) {
        throw new \RuntimeException('the launcher was expected to hand over to the probe, got: ' . $result['stdout']);
    }

    return $result;
};

/** @return array<string, mixed> */
$probe = static function (array $result): array {
    $decoded = \json_decode($result['stdout'], true);
    if (!\is_array($decoded)) {
        throw new \RuntimeException('the probe printed no JSON: ' . $result['stdout'] . $result['stderr']);
    }

    return $decoded;
};

/** Paths are compared with one separator, since Windows writes the other one. */
$norm = static fn (string $path): string => \str_replace('\\', '/', $path);

try {
    // A bundled runtime wins over the host's, wherever the command is run from.
    $full = $install('full', ['php', 'jvm', 'graalvm', 'dotnet']);
    $result = $run($full, ['version'], ['MOGGI_TEST_MARKER' => 'kept'] + \getenv(), $work . '/from-here');
    $assert($result['exitCode'] === 0, 'a bundled PHP must run the compiler');
    $seen = $probe($result);
    $assert($seen['args'] === ['version'], 'the launcher must forward the command');
    if ($isWindows) {
        $assert($norm($seen['phpRc']) === $norm($full . '/runtime/php'), 'a bundled PHP must be the one PHPRC points at');
    } else {
        $assert($seen['bundledPhp'] === '1', 'the bundled PHP must be the interpreter that runs the compiler');
    }
    $assert($seen['marker'] === 'kept', 'the environment must reach the compiler unchanged');
    $assert($norm((string) $seen['cwd']) === $norm($work . '/from-here'), 'the launcher must not change the working directory');
    $assert($norm($seen['phpRc']) === $norm($full . '/runtime/php'), 'a bundled php.ini must be handed over through PHPRC');
    if (!$isWindows) {
        $assert(
            \str_contains($norm($seen['loader']), $norm($full . '/runtime/php/lib')),
            'the bundled PHP library directory must be on the loader path',
        );
    }
    $assert(
        $norm($seen['dotnetRoot']) === $norm($full . '/runtime/dotnet'),
        'a bundled .NET SDK must be handed over through DOTNET_ROOT',
    );
    $assert(
        \str_starts_with(\rtrim($norm($seen['extDir']), '/'), $norm($full . '/runtime/php/ext')),
        'a bundled extension directory must be handed to PHP as an absolute path: ' . $seen['extDir'],
    );
    $assert(
        $seen['scanDir'] === '',
        'a bundled PHP must not scan the ini directory of the host: ' . $seen['scanDir'],
    );

    // Bundled directories come first, JDK before GraalVM so the JDK's own
    // `java`/`javac` win and GraalVM only supplies `native-image`.
    $expectedOrder = \array_map($norm, [
        $full . '/runtime/jvm/bin',
        $full . '/runtime/graalvm/bin',
        $full . '/runtime/dotnet',
        $full . '/runtime/php/bin',
    ]);
    $entries = \array_map($norm, \explode(\PATH_SEPARATOR, (string) $seen['path']));
    $assert(\array_slice($entries, 0, 4) === $expectedOrder, 'bundled runtimes must precede the host, JDK before GraalVM');

    // Nothing bundled: the host's PHP is used. A PATH holding PHP and nothing
    // else is what makes the backend cases below mean what they say.
    $plain = $install('plain', []);
    $phpOnlyPath = distributionPhpOnlyPath();
    $seen = $probe($run($plain, ['version'], ['PATH' => $phpOnlyPath, 'PHP_INI_SCAN_DIR' => $emptyConf] + \getenv()));
    $assert($seen['bundledPhp'] === '', 'with no bundled PHP the host one must run');
    $assert($seen['phpRc'] === '', 'a host PHP must keep its own ini');
    $assert($seen['scanDir'] === $emptyConf, 'a host PHP must keep its own scan directory');
    $assert($seen['dotnetRoot'] === (string) (\getenv('DOTNET_ROOT') ?: ''), 'the host environment must not be rewritten');

    // No PHP anywhere: a clear error, not a confusing failure inside the compiler.
    $empty = $work . '/empty-path';
    \mkdir($empty, 0777, true);
    $missing = $run($plain, ['version'], ['PATH' => $empty] + \getenv());
    $assert($missing['exitCode'] === 127, 'a missing PHP runtime must exit 127, got ' . $missing['exitCode']);
    $assert(\str_contains($missing['stderr'], 'no PHP runtime'), 'the error must say what is missing: ' . $missing['stderr']);
    $assert(
        \str_contains($norm($missing['stderr']), 'runtime/php/bin/php'),
        'the error must name the bundled PHP it looked for: ' . $missing['stderr'],
    );

    // Arguments, including the awkward ones, and the exit status.
    $awkward = ['run', 'a b', '', '--', '--weird=1', 'é', '"quoted"', "it's", '-back-end'];
    $result = $run($full, $awkward, ['MOGGI_TEST_EXIT' => '42'] + \getenv());
    $seen = $probe($result);
    $assert($seen['args'] === $awkward, 'arguments must be forwarded byte for byte: ' . \json_encode($seen['args']));
    $assert($result['exitCode'] === 42, 'the exit status must be preserved, got ' . $result['exitCode']);

    // The archive is resolved next to the launcher, so a launcher copied
    // elsewhere without its archive says so instead of failing inside PHP.
    $orphan = $work . '/orphan/bin';
    \mkdir($orphan, 0777, true);
    \copy($launcher, $orphan . '/moggi' . $exe);
    \chmod($orphan . '/moggi' . $exe, 0755);
    $result = $run(\dirname($orphan), ['version']);
    $assert($result['exitCode'] === 127, 'a launcher without its archive must exit 127');
    $assert(\str_contains($result['stderr'], 'compiler archive'), 'the error must name the archive: ' . $result['stderr']);

    // A backend is required only when it is asked for, and only when it is not
    // on `PATH` either.
    $phpOnly = $install('php-only', ['php']);
    $withoutBackends = ['PATH' => $phpOnlyPath] + \getenv();
    foreach (['--backend jvm', '--backend dotnet', '--backend=dotnet'] as $request) {
        $args = \array_merge(['compile', 'Main.mog'], \explode(' ', $request));
        $result = $run($phpOnly, $args, $withoutBackends);
        $assert($result['exitCode'] === 127, "`{$request}` without that runtime must exit 127, got " . $result['exitCode']);
        $assert(\str_contains($result['stderr'], 'bundled at'), "`{$request}` must say what it looked for: " . $result['stderr']);
    }
    $assert(\str_contains($run($phpOnly, ['--backend', 'dotnet', 'compile'], $withoutBackends)['stderr'], '.NET'), 'the .NET error must name .NET');
    $assert(\str_contains($run($phpOnly, ['--backend', 'jvm', 'compile'], $withoutBackends)['stderr'], 'JDK'), 'the JVM error must name the JDK');

    // Finding a JDK is not finding GraalVM: `--native` needs the second one.
    $withJdk = $install('with-jdk', ['php', 'jvm']);
    $assert(
        $run($withJdk, ['compile', 'Main.mog', '--backend', 'jvm'], $withoutBackends)['exitCode'] === 0,
        'a JDK alone must satisfy the JVM backend',
    );
    $native = $run($withJdk, ['compile', 'Main.mog', '--backend', 'jvm', '--native'], $withoutBackends);
    $assert($native['exitCode'] === 127, '`--native` without GraalVM must fail');
    $assert(\str_contains($native['stderr'], 'GraalVM'), 'the native error must name GraalVM: ' . $native['stderr']);

    $withGraalvm = $install('with-graalvm', ['php', 'jvm', 'graalvm']);
    $seen = $probe($run($withGraalvm, ['compile', 'Main.mog', '--backend', 'jvm', '--native'], $withoutBackends));
    $assert(
        $seen['args'] === ['compile', 'Main.mog', '--backend', 'jvm', '--native'],
        'a GraalVM installation must accept `--native` and forward it unchanged',
    );
    $assert(\str_contains($norm($seen['path']), 'graalvm/bin'), 'the GraalVM bin directory must be on PATH');

    // `--native` on .NET is the .NET SDK's own, so it needs the SDK and no GraalVM.
    $assert(
        $run($withJdk, ['compile', 'Main.mog', '--backend', 'dotnet', '--native'], $withoutBackends)['exitCode'] === 127,
        '`--native` on .NET without the SDK must fail',
    );
    $assert(
        $run($install('dotnet-native', ['php', 'dotnet']), ['compile', 'Main.mog', '--backend', 'dotnet', '--native'], $withoutBackends)['exitCode'] === 0,
        '`--native` on .NET must be satisfied by a bundled SDK',
    );
} finally {
    removeDirectory($work);
}

\fwrite(STDOUT, "launcher: {$checks} checks passed\n");
