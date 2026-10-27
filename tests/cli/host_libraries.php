<?php declare(strict_types=1);

// backends: php

/**
 * `--lib` names Moggi library roots; host code arrives through the flag of the package manager that
 * resolved it. This drives `--lib-composer` through the real CLI: a Composer tree compiles, carries
 * its autoloader and runs — named by the project and by its `vendor/` — while a `.mog` module inside
 * that tree does not become importable. `moggi build` then forwards the tree a stub `composer`
 * resolved, so the artifact is judged by running it.
 */

require __DIR__ . '/../suite/support/host_libraries.php';

$case = new HostLibraryCase(hostLibrariesRoot());
$work = $case->work;

try {
    $project = $work . '/composer-project';
    $case->copyTree($case->composerFixture(), $project);

    \file_put_contents($work . '/Acme.mog', hostLibrariesForeignProgram());

    $byProject = $case->compile([$work . '/Acme.mog', '--lib-composer', $project, '-o', $work . '/by-project.phar']);
    $case->assert(($byProject['exitCode'] ?? 1) === 0, 'a Composer project named with --lib-composer must compile: ' . \trim($byProject['stderr']));
    $ran = $case->run([\PHP_BINARY, $work . '/by-project.phar'], 60);
    $case->assert(($ran['exitCode'] ?? 1) === 0, 'the artifact must run: ' . \trim($ran['stderr']));
    $case->assert(\trim($ran['stdout']) === 'Hello, composer!', 'the host function must answer: ' . $ran['stdout']);

    $byVendor = $case->compile([$work . '/Acme.mog', '--lib-composer', $project . '/vendor', '-o', $work . '/by-vendor.phar']);
    $case->assert(($byVendor['exitCode'] ?? 1) === 0, 'the `vendor/` directory is the same tree: ' . \trim($byVendor['stderr']));
    $vendorRan = $case->run([\PHP_BINARY, $work . '/by-vendor.phar'], 60);
    $case->assert(\trim($vendorRan['stdout']) === 'Hello, composer!', 'both spellings must run the same host call: ' . $vendorRan['stdout']);

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
    $ghost = $case->compile([$work . '/Imports.mog', '--lib-composer', $project, '-o', $work . '/ghost.phar']);
    $case->assert(($ghost['exitCode'] ?? 0) !== 0, 'a host tree must not serve as a Moggi library root');
    $case->assert(
        \str_contains($ghost['stderr'] . $ghost['stdout'], 'Ghost'),
        'the refusal must name the module the host tree does not provide: ' . $ghost['stderr'],
    );

    // `moggi build` on a package with a Composer coordinate, resolved by a stub `composer` that
    // materializes a real `vendor/` tree. The build has to forward the directory holding the
    // autoloader, and the artifact has to carry it.
    $phpProject = $work . '/composer-build-project';
    \mkdir($phpProject . '/src', 0777, true);
    \file_put_contents(
        $phpProject . '/acme-demo.moggi',
        "[package]\nname = acme-demo\nversion = 0.1.0\nbackends = php\n\n"
        . "[lib]\nsource-dirs = src\n\n[php]\ncomposer = acme/hello:^1.0\n\n"
        . "[executable]\nmain = Main\nsource-dirs = src\n\n"
        . "[author]\nname = Test Author\nemail = test@example.com\n"
        . "npub = npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm\n",
    );
    \copy($work . '/Acme.mog', $phpProject . '/src/Main.mog');
    $composerBin = $work . '/composer-bin';
    $case->stubResolver($composerBin, 'composer', <<<'PHP'
        foreach ($argv as $arg) {
            if ($arg === '--version') {
                echo "Composer version 2.10.3 (stub)\n";
                exit(0);
            }
        }
        $copy = static function (string $from, string $to) use (&$copy): void {
            @\mkdir($to, 0777, true);
            foreach (\scandir($from) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                \is_dir($from . '/' . $entry)
                    ? $copy($from . '/' . $entry, $to . '/' . $entry)
                    : \copy($from . '/' . $entry, $to . '/' . $entry);
            }
        };
        $copy((string) \getenv('ACME_VENDOR'), 'vendor');
        PHP);
    $composerEnv = $case->withPath($case->env, $composerBin);
    $composerEnv['ACME_VENDOR'] = $case->composerFixture() . '/vendor';
    $phpInstalled = $case->cli(['install'], $phpProject, $composerEnv);
    $case->assert(($phpInstalled['exitCode'] ?? 1) === 0, 'moggi install must write the lock offline: ' . \trim($phpInstalled['stderr']));
    $phpBuilt = $case->cli(['build', '--backend', 'php', '-o', $work . '/built.phar'], $phpProject, $composerEnv);
    $case->assert(($phpBuilt['exitCode'] ?? 1) === 0, 'moggi build must forward its Composer tree: ' . \trim($phpBuilt['stderr']));
    $phpBuiltRun = $case->run([\PHP_BINARY, $work . '/built.phar'], 60);
    $case->assert(($phpBuiltRun['exitCode'] ?? 1) === 0, 'the built phar must run: ' . \trim($phpBuiltRun['stderr']));
    $case->assert(trim($phpBuiltRun['stdout']) === 'Hello, composer!', 'the built phar must carry the host tree: ' . $phpBuiltRun['stdout']);

    $case->finish('cli/host_libraries');
} finally {
    $case->cleanup();
}
