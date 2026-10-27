<?php declare(strict_types=1);

// backends: dotnet

/**
 * `--lib-nuget`: a DLL assembled offline with the sidecar that scopes its type, linked for real.
 * Without the sidecar the type has no assembly of its own, so the call is what fails, naming the
 * type. `moggi build` is then driven against a stub `dotnet` that writes what a real restore writes —
 * a package folder with the DLL nested under `lib/<tfm>/` — and the build has to stage and forward it.
 */

require __DIR__ . '/../suite/support/host_libraries.php';

use function Moggi\Backend\DotNet\resolveDotnetExecutable;
use function Moggi\Backend\DotNet\resolveIlasmExecutable;

$case = new HostLibraryCase(hostLibrariesRoot());
$work = $case->work;

try {
    $ilasm = resolveIlasmExecutable();
    $dotnet = resolveDotnetExecutable();
    if ($ilasm === null || $dotnet === null) {
        $case->skipLeg('the --lib-nuget leg needs ilasm and dotnet on PATH');
        $case->finish('cli/host_libraries_dotnet');
        return;
    }

    $nugetTree = $work . '/nuget-tree';
    \mkdir($nugetTree, 0777, true);
    \file_put_contents(
        $work . '/Probe.il',
        ".assembly extern System.Runtime\n{\n  .publickeytoken = (B0 3F 5F 7F 11 D5 0A 3A)\n  .ver 8:0:0:0\n}\n"
        . ".assembly Probe\n{\n}\n.module Probe.dll\n"
        . ".class public auto ansi abstract sealed beforefieldinit Probe.Hello extends [System.Runtime]System.Object\n{\n"
        . "  .method public hidebysig static string Greet(string name) cil managed\n  {\n    .maxstack 2\n"
        . "    ldstr \"hello \"\n    ldarg.0\n    call string [System.Runtime]System.String::Concat(string, string)\n    ret\n  }\n}\n",
    );
    $assembled = $case->run([$ilasm, '-NOLOGO', '-QUIET', '-DLL', '-OUTPUT=' . $nugetTree . '/Probe.dll', $work . '/Probe.il']);
    $case->assert(($assembled['exitCode'] ?? 1) === 0 && \is_file($nugetTree . '/Probe.dll'), 'ilasm must build the DLL fixture: ' . \trim($assembled['stderr']));
    \file_put_contents(
        $nugetTree . '/Probe.json',
        '{"assemblies": {"Probe": {"types": {"Probe.Hello": {}}}}}',
    );
    \mkdir($work . '/nuget-prog', 0777, true);
    \file_put_contents(
        $work . '/nuget-prog/Main.mog',
        "module Main where\n\nimport Data.String\nimport System.IO\n\n"
        . "foreign dotnet function greet \"Probe.Hello:Greet\" :: String -> String\n\n"
        . "main :: IO ()\nmain = putStrLn (greet \"nuget\")\n",
    );

    $linked = $case->compile([$work . '/nuget-prog/Main.mog', '--backend', 'dotnet', '--lib-nuget', $nugetTree, '-o', $work . '/with-nuget.dll']);
    $case->assert(($linked['exitCode'] ?? 1) === 0, 'a DLL tree named with --lib-nuget must compile: ' . \trim($linked['stderr']));
    $linkedRun = $case->run([$dotnet, $work . '/with-nuget.dll']);
    $case->assert(($linkedRun['exitCode'] ?? 1) === 0, 'the dotnet artifact must run: ' . \trim($linkedRun['stderr']));
    $case->assert(\trim($linkedRun['stdout']) === 'hello nuget', 'the assembly\'s method must answer: ' . $linkedRun['stdout']);

    // Without the sidecar the type has no assembly of its own: the compiler falls back to
    // `System.Runtime` and the call raises TypeLoadException. The gate accepts that tree (it is
    // what NuGet resolves), so the failure is the call's, not the tree's.
    $bareTree = $work . '/nuget-bare';
    \mkdir($bareTree, 0777, true);
    \copy($nugetTree . '/Probe.dll', $bareTree . '/Probe.dll');
    $bare = $case->compile([$work . '/nuget-prog/Main.mog', '--backend', 'dotnet', '--lib-nuget', $bareTree, '-o', $work . '/bare.dll']);
    $bareRun = ($bare['exitCode'] ?? 1) === 0
        ? $case->run([$dotnet, $work . '/bare.dll'])
        : ['exitCode' => 1, 'stderr' => $bare['stderr'], 'stdout' => ''];
    $case->assert(
        ($bareRun['exitCode'] ?? 0) !== 0 && \str_contains($bareRun['stderr'] . ($bare['stderr'] ?? ''), 'Probe.Hello'),
        'a third-party type with no sidecar must fail the call, naming the type: ' . \trim($bareRun['stderr']),
    );

    // `moggi build` on a package with a NuGet coordinate, resolved by a stub `dotnet` that writes
    // what a real restore writes — a package folder with the DLL nested under `lib/<tfm>/` — and
    // delegates its other subcommands to the SDK. The build must stage that DLL and forward it.
    $packageDir = $work . '/nuget-package-folder/probe/1.0.0/lib/net8.0';
    \mkdir($packageDir, 0777, true);
    \copy($nugetTree . '/Probe.dll', $packageDir . '/Probe.dll');
    $nugetProject = $work . '/nuget-build-project';
    \mkdir($nugetProject . '/src', 0777, true);
    \file_put_contents(
        $nugetProject . '/acme-demo.moggi',
        "[package]\nname = acme-demo\nversion = 0.1.0\nbackends = dotnet\n\n"
        . "[lib]\nsource-dirs = src\n\n[dotnet.nuget]\nProbe = 1.0.0\n\n"
        . "[executable]\nmain = Main\nsource-dirs = src\n\n"
        . "[author]\nname = Test Author\nemail = test@example.com\n"
        . "npub = npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm\n",
    );
    \file_put_contents(
        $nugetProject . '/src/Main.mog',
        "module Main where\n\nimport Data.String\nimport System.IO\n\n"
        . "foreign dotnet function parse \"System.Int64:Parse\" :: String -> Int\n\n"
        . "main :: IO ()\nmain = putStrLn (show (parse \"42\"))\n",
    );
    $assets = \json_encode([
        'version' => 3,
        'targets' => ['net8.0' => ['Probe/1.0.0' => ['type' => 'package', 'compile' => ['lib/net8.0/Probe.dll' => new \stdClass()]]]],
        'libraries' => ['Probe/1.0.0' => ['type' => 'package', 'path' => 'probe/1.0.0']],
        'packageFolders' => [$work . '/nuget-package-folder/' => new \stdClass()],
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    $dotnetBin = $work . '/dotnet-bin';
    $case->stubResolver($dotnetBin, 'dotnet', <<<'PHP'
        if (($argv[1] ?? '') === '--version') {
            echo "8.0.425\n";
            exit(0);
        }
        if (($argv[1] ?? '') === 'restore') {
            @\mkdir('obj', 0777, true);
            \copy((string) \getenv('ACME_ASSETS'), 'obj/project.assets.json');
            exit(0);
        }
        $process = \proc_open(
            [(string) \getenv('ACME_DOTNET'), ...\array_slice($argv, 1)],
            [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR],
            $pipes,
        );
        exit(\is_resource($process) ? \proc_close($process) : 1);
        PHP);
    \file_put_contents($work . '/project.assets.json', $assets);
    $dotnetBuildEnv = $case->withPath($case->env, $dotnetBin);
    $dotnetBuildEnv['ACME_ASSETS'] = $work . '/project.assets.json';
    $dotnetBuildEnv['ACME_DOTNET'] = (string) $dotnet;
    $nugetInstalled = $case->cli(['install'], $nugetProject, $dotnetBuildEnv);
    $case->assert(($nugetInstalled['exitCode'] ?? 1) === 0, 'moggi install must write the lock offline: ' . \trim($nugetInstalled['stderr']));
    $dotnetBuilt = $case->cli(['build', '--backend', 'dotnet', '-o', $work . '/built.dll'], $nugetProject, $dotnetBuildEnv);
    $case->assert(($dotnetBuilt['exitCode'] ?? 1) === 0, 'moggi build must forward its NuGet tree: ' . \trim($dotnetBuilt['stderr']));
    $case->assert(
        \str_contains($dotnetBuilt['stdout'] . $dotnetBuilt['stderr'], 'Probe.dll'),
        'the run-resolved assembly must be staged beside the artifact: ' . $dotnetBuilt['stdout'],
    );
    $dotnetBuiltRun = $case->run([$dotnet, $work . '/built.dll']);
    $case->assert(($dotnetBuiltRun['exitCode'] ?? 1) === 0, 'the built dll must run: ' . \trim($dotnetBuiltRun['stderr']));
    $case->assert(trim($dotnetBuiltRun['stdout']) === '42', 'the built dll must answer: ' . $dotnetBuiltRun['stdout']);

    $case->finish('cli/host_libraries_dotnet');
} finally {
    $case->cleanup();
}
