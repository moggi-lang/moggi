<?php declare(strict_types=1);

// backends: php

/**
 * What each host flag refuses and accepts, judged at registration: a flag names the tree its own
 * package manager resolved — a Composer project or its `vendor/`, a directory of jars, a directory
 * of DLLs — so a `composer.json`, a renamed `vendor/`, a NuGet package cache nesting DLLs under
 * `lib/<tfm>/`, or a flag aimed at another backend is refused instead of half-used. No target
 * toolchain is needed: every compile here stops before codegen.
 */

require __DIR__ . '/../suite/support/host_libraries.php';

$case = new HostLibraryCase(hostLibrariesRoot());
$work = $case->work;

try {
    $project = $work . '/composer-project';
    $case->copyTree($case->composerFixture(), $project);
    $empty = $work . '/no-autoloader';
    \mkdir($empty, 0777, true);
    \file_put_contents($work . '/Acme.mog', hostLibrariesForeignProgram());

    $wrongKind = $case->compile([$work . '/Acme.mog', '--lib-maven', $empty, '-o', $work . '/wrong-kind.phar']);
    $case->assert(($wrongKind['exitCode'] ?? 0) !== 0, 'a maven tree must be refused by a php build');
    $case->assert(
        \str_contains($wrongKind['stderr'], '--lib-maven names the jvm host tree'),
        'the refusal must say which build the flag belongs to: ' . $wrongKind['stderr'],
    );

    $noAutoloader = $case->compile([$work . '/Acme.mog', '--lib-composer', $empty, '-o', $work . '/no-autoloader.phar']);
    $case->assert(($noAutoloader['exitCode'] ?? 0) !== 0, 'a directory with no Composer tree must be refused, not ignored');
    $case->assert(
        \str_contains($noAutoloader['stderr'], 'is not a Composer project'),
        'the refusal must say what the tree is missing: ' . $noAutoloader['stderr'],
    );

    // A `composer.json` names dependencies and holds no code, and a compile never installs: the flag
    // takes a directory, and the one whose autoloader the artifact carries.
    $jsonFile = $case->compile([$work . '/Acme.mog', '--lib-composer', $project . '/composer.json', '-o', $work . '/json-file.phar']);
    $case->assert(($jsonFile['exitCode'] ?? 0) !== 0, 'a composer.json is not a host tree');
    $case->assert(
        \str_contains($jsonFile['stderr'], 'requires a directory'),
        'the refusal must say a host tree is a directory: ' . $jsonFile['stderr'],
    );

    // `composer install` with `config.vendor-dir` elsewhere is a real layout the artifact cannot
    // express — its require path is `vendor/autoload.php` — so it is refused rather than half-used.
    $relocated = $work . '/relocated-vendor';
    $case->copyTree($case->composerFixture(), $relocated);
    \rename($relocated . '/vendor', $relocated . '/libs');
    $renamed = $case->compile([$work . '/Acme.mog', '--lib-composer', $relocated . '/libs', '-o', $work . '/renamed-vendor.phar']);
    $case->assert(($renamed['exitCode'] ?? 0) !== 0, 'a renamed vendor directory must be refused, not half-registered');
    $case->assert(
        \str_contains($renamed['stderr'], 'must hold `vendor/autoload.php`'),
        'the refusal must name the autoloader path the artifact carries: ' . $renamed['stderr'],
    );

    // What a DLL tree is: the assemblies NuGet resolved. A `.json` type sidecar is metadata a Moggi
    // library adds, never a condition of the tree being usable — `cli/host_libraries_dotnet` links
    // both layouts for real, so only the refusals are pinned here.
    $jsonOnly = $work . '/json-only';
    \mkdir($jsonOnly, 0777, true);
    \file_put_contents($jsonOnly . '/probe.json', '{"assemblies": {}}');

    // What NuGet keeps is nested — `<package>/<version>/lib/<tfm>/Probe.dll` — and that cache is not
    // the set a compile is handed: the refusal names the directory that is.
    $packagesRoot = $work . '/nuget-packages';
    \mkdir($packagesRoot . '/probe/1.0.0/lib/net8.0', 0777, true);
    \file_put_contents($packagesRoot . '/probe/1.0.0/lib/net8.0/probe.dll', '');
    $nested = $case->compile([$work . '/Acme.mog', '--lib-nuget', $packagesRoot, '--backend', 'dotnet', '-o', $work . '/nested.dll']);
    $case->assert(($nested['exitCode'] ?? 0) !== 0, 'a NuGet package cache is not the resolved directory');
    $case->assert(
        \str_contains($nested['stderr'], 'lib/<tfm>/'),
        'the refusal must name the directory that is: ' . $nested['stderr'],
    );

    $sidecarOnly = $case->compile([$work . '/Acme.mog', '--lib-nuget', $jsonOnly, '--backend', 'dotnet', '-o', $work . '/sidecar-only.dll']);
    $case->assert(($sidecarOnly['exitCode'] ?? 0) !== 0, 'a tree with no assembly must be refused');
    $case->assert(
        \str_contains($sidecarOnly['stderr'], 'is not the assemblies NuGet resolved'),
        'the refusal must say what a NuGet tree is missing: ' . $sidecarOnly['stderr'],
    );

    $case->finish('cli/host_libraries_shapes');
} finally {
    $case->cleanup();
}
