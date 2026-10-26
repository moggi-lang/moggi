<?php declare(strict_types=1);

/**
 * `--lib` names Moggi library roots; host code arrives through the flag of the package manager that
 * resolved it. This drives the real CLI for the php side: a Composer tree compiles, carries its
 * autoloader and runs — named by the project and by its `vendor/` — while the same tree passed as
 * `--lib` is not host wiring, a `--lib-maven` tree is refused by a php build, and a directory with no
 * Composer autoloader is refused instead of being dropped silently.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$fixture = $root . '/tests/registry/bind/fixtures/acme-hello-php';
$work = createTempDir('moggi-host-libraries');
$env = \getenv();
$env['MOGGI_CACHE_DIR'] = $work . '/cache';
seedCacheDir($env['MOGGI_CACHE_DIR']);

/** Copy the fixture Composer project (descriptor + `vendor/`) into the workspace. */
$copyTree = static function (string $from, string $to) use (&$copyTree): void {
    \mkdir($to, 0777, true);
    foreach (\scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        \is_dir($source) ? $copyTree($source, $to . '/' . $entry) : \copy($source, $to . '/' . $entry);
    }
};

$compile = static function (array $arguments) use ($root, $env): array {
    return runCompiledProcess([\PHP_BINARY, $root . '/moggi.php', ...$arguments], 120, $env, $root);
};

try {
    $project = $work . '/composer-project';
    $copyTree($fixture, $project);
    $empty = $work . '/no-autoloader';
    \mkdir($empty, 0777, true);

    \file_put_contents(
        $work . '/Acme.mog',
        "module Main where\n\nimport Data.String\nimport System.IO\n\n"
        . "foreign php function greet \"Acme\\\\Hello\\\\greet\" :: String -> String\n\n"
        . "main :: IO ()\nmain = putStrLn (greet \"composer\")\n",
    );

    $byProject = $compile(['compile', $work . '/Acme.mog', '--lib-composer', $project, '-o', $work . '/by-project.phar']);
    $assert(($byProject['exitCode'] ?? 1) === 0, 'a Composer project named with --lib-composer must compile: ' . \trim($byProject['stderr']));
    $ran = runCompiledProcess([\PHP_BINARY, $work . '/by-project.phar'], 60, $env, $work);
    $assert(($ran['exitCode'] ?? 1) === 0, 'the artifact must run: ' . \trim($ran['stderr']));
    $assert(\trim($ran['stdout']) === 'Hello, composer!', 'the host function must answer: ' . $ran['stdout']);

    $byVendor = $compile(['compile', $work . '/Acme.mog', '--lib-composer', $project . '/vendor', '-o', $work . '/by-vendor.phar']);
    $assert(($byVendor['exitCode'] ?? 1) === 0, 'the `vendor/` directory is the same tree: ' . \trim($byVendor['stderr']));
    $vendorRan = runCompiledProcess([\PHP_BINARY, $work . '/by-vendor.phar'], 60, $env, $work);
    $assert(\trim($vendorRan['stdout']) === 'Hello, composer!', 'both spellings must run the same host call: ' . $vendorRan['stdout']);

    // A host tree is not a Moggi module root: a `.mog` module inside the named tree must not become
    // importable just because the tree is visible to the compile.
    \file_put_contents(
        $project . '/vendor/Ghost.mog',
        "module Ghost where\n\nghost :: Int\nghost = 1\n",
    );
    \file_put_contents(
        $work . '/Imports.mog',
        "module Main where\n\nimport Data.String\nimport System.IO\nimport Ghost\n\n"
        . "main :: IO ()\nmain = putStrLn \"ghost\"\n",
    );
    $ghost = $compile(['compile', $work . '/Imports.mog', '--lib-composer', $project, '-o', $work . '/ghost.phar']);
    $assert(($ghost['exitCode'] ?? 0) !== 0, 'a host tree must not serve as a Moggi library root');
    $assert(
        \str_contains($ghost['stderr'] . $ghost['stdout'], 'Ghost'),
        'the refusal must name the module the host tree does not provide: ' . $ghost['stderr'],
    );

    $wrongKind = $compile(['compile', $work . '/Acme.mog', '--lib-maven', $empty, '-o', $work . '/wrong-kind.phar']);
    $assert(($wrongKind['exitCode'] ?? 0) !== 0, 'a maven tree must be refused by a php build');
    $assert(
        \str_contains($wrongKind['stderr'], '--lib-maven names the jvm host tree'),
        'the refusal must say which build the flag belongs to: ' . $wrongKind['stderr'],
    );

    $noAutoloader = $compile(['compile', $work . '/Acme.mog', '--lib-composer', $empty, '-o', $work . '/no-autoloader.phar']);
    $assert(($noAutoloader['exitCode'] ?? 0) !== 0, 'a directory with no Composer tree must be refused, not ignored');
    $assert(
        \str_contains($noAutoloader['stderr'], 'is not a Composer project'),
        'the refusal must say what the tree is missing: ' . $noAutoloader['stderr'],
    );

    \printf("cli/host_libraries: %d checks passed\n", $checks);
} finally {
    $remove = static function (string $dir) use (&$remove): void {
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            \is_dir($path) && ! \is_link($path) ? $remove($path) : @\unlink($path);
        }
        @\rmdir($dir);
    };
    $remove($work);
}
