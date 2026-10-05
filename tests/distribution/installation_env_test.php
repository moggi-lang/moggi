#!/usr/bin/env php
<?php declare(strict_types=1);

// A distribution's `bin/moggi` is the compiler itself, so the environment the
// retired C launcher used to prepare — bundled runtimes in front of PATH, and
// the roots the compiler looks for handed to its child processes — is prepared
// in PHP before any command runs. This drives the installation layout by hand
// and asserts what `activateBundledRuntimes()` leaves behind.

$root = __DIR__;
while (!is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

$work = \sys_get_temp_dir() . '/moggi-install-' . \bin2hex(\random_bytes(6));
\mkdir($work, 0777, true);

require $root . '/src/compiler.php';

\define(\Moggi\Install\INSTALL_ROOT_CONSTANT, $work);

use function Moggi\Install\activateBundledRuntimes;
use function Moggi\Install\bundledPhpBinDirectory;
use function Moggi\Install\installationRoot;
use function Moggi\Install\loaderVariable;
use function Moggi\Install\platformPath;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$remove = static function (string $path) use (&$remove): void {
    if (!\file_exists($path) && !\is_link($path)) {
        return;
    }
    if (\is_dir($path) && !\is_link($path)) {
        foreach (\scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $remove($path . '/' . $name);
            }
        }
        @\rmdir($path);

        return;
    }
    @\unlink($path);
};

try {
    $assert(installationRoot() === $work, 'installationRoot() reads the stub constant');

    foreach (['jvm/bin', 'graalvm/bin', 'php/bin', 'php/lib', 'maven/bin', 'composer/bin'] as $dir) {
        \mkdir($work . '/runtime/' . $dir, 0777, true);
    }
    \mkdir($work . '/runtime/dotnet', 0777, true);
    \file_put_contents($work . '/runtime/php/php.ini', "; test\n");

    \putenv('PATH=/usr/bin:/bin');
    activateBundledRuntimes();

    $entries = \explode(\PATH_SEPARATOR, (string) \getenv('PATH'));
    $expected = [
        $work . '/runtime/jvm/bin',
        $work . '/runtime/graalvm/bin',
        $work . '/runtime/dotnet',
        $work . '/runtime/php/bin',
        $work . '/runtime/maven/bin',
        $work . '/runtime/composer/bin',
    ];
    $assert(
        \array_slice($entries, 0, \count($expected)) === $expected,
        'bundled runtimes must precede the host, JDK before GraalVM: ' . \implode(' | ', \array_slice($entries, 0, \count($expected))),
    );
    $assert(\in_array('/usr/bin', $entries, true), 'the host PATH must be kept after the bundled directories');

    $assert(\getenv('JAVA_HOME') === $work . '/runtime/jvm', 'a bundled JDK must be handed over through JAVA_HOME');
    $assert(\getenv('DOTNET_ROOT') === $work . '/runtime/dotnet', 'a bundled .NET SDK must be handed over through DOTNET_ROOT');
    $assert(\getenv('PHPRC') === $work . '/runtime/php', 'a bundled php.ini must be handed over through PHPRC');
    $assert(\getenv('PHP_INI_SCAN_DIR') === '', 'a bundled PHP must not scan the host ini directory');

    $loader = (string) \getenv('LD_LIBRARY_PATH') . (string) \getenv('DYLD_FALLBACK_LIBRARY_PATH');
    $assert(
        \str_contains($loader, $work . '/runtime/php/lib'),
        'the bundled PHP library directory must be on the loader path',
    );

    $assert(!\is_dir($work . '/runtime/php-native'), 'the micro runtime is a file, not a directory of commands');

    // The platform decisions are pure, so all three are asserted here, not only
    // the one this machine runs. Windows has no loader variable and a flat PHP
    // build; macOS reads dyld's fallback path; the exported paths are spelled
    // the host's way.
    $assert(loaderVariable('Windows') === null, 'Windows has no dynamic-loader variable to prepend to');
    $assert(
        loaderVariable('Darwin') === 'DYLD_FALLBACK_LIBRARY_PATH',
        'macOS reads the dyld fallback path, which never overrides a host library',
    );
    $assert(loaderVariable('Linux') === 'LD_LIBRARY_PATH', 'Linux reads LD_LIBRARY_PATH');

    $assert(bundledPhpBinDirectory('/r/php', 'Windows') === '/r/php', 'the Windows PHP build is flat');
    $assert(bundledPhpBinDirectory('/r/php', 'Linux') === '/r/php/bin', 'the Linux PHP build is under bin/');
    $assert(bundledPhpBinDirectory('/r/php', 'Darwin') === '/r/php/bin', 'the macOS PHP build is under bin/');

    $assert(platformPath('C:/a/b', 'Windows') === 'C:\\a\\b', 'a Windows path is spelled with backslashes');
    $assert(platformPath('/a/b', 'Linux') === '/a/b', 'a unix path is left alone');
} finally {
    $remove($work);
}

\fwrite(STDOUT, "installation environment: {$checks} checks passed\n");
