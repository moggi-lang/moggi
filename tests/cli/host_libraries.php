<?php declare(strict_types=1);

/**
 * `--lib` names Moggi library roots; host code arrives through the flag of the package manager that
 * resolved it. This drives the real CLI for all three resolvers: a Composer tree compiles, carries its
 * autoloader and runs — named by the project and by its `vendor/` — while the same tree passed as
 * `--lib` is not host wiring, a `--lib-maven` tree is refused by a php build, and a directory with no
 * Composer autoloader is refused instead of being dropped silently. The Maven and NuGet legs build
 * their fixtures from source (`javac`+`jar`, the backend's own `ilasm`) and link them for real, and the
 * NuGet leg also pins what the shape gate accepts: DLLs are the resolver's artifact, a sidecar-only
 * tree is refused, and a third-party call with no sidecar fails at the call rather than at the tree.
 * `moggi build` is then driven end to end against a stub `mvn`, `composer` or `dotnet` resolver, so
 * each backend's forwarding is judged by an artifact that runs rather than by one that was assumed;
 * and the layouts a real resolver leaves that the flags cannot name — a NuGet package cache nesting
 * DLLs under `lib/<tfm>/`, a Composer install whose `config.vendor-dir` is not `vendor/` — are
 * pinned as refusals that say so.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';

use function Moggi\Backend\DotNet\resolveDotnetExecutable;
use function Moggi\Backend\DotNet\resolveIlasmExecutable;
use function Moggi\Compiler\findExecutable;

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

    // A `composer.json` names dependencies and holds no code, and a compile never installs: the flag
    // takes a directory, and the one whose autoloader the artifact carries.
    $jsonFile = $compile(['compile', $work . '/Acme.mog', '--lib-composer', $project . '/composer.json', '-o', $work . '/json-file.phar']);
    $assert(($jsonFile['exitCode'] ?? 0) !== 0, 'a composer.json is not a host tree');
    $assert(
        \str_contains($jsonFile['stderr'], 'requires a directory'),
        'the refusal must say a host tree is a directory: ' . $jsonFile['stderr'],
    );

    // `composer install` with `config.vendor-dir` elsewhere is a real layout the artifact cannot
    // express — its require path is `vendor/autoload.php` — so it is refused rather than half-used.
    $relocated = $work . '/relocated-vendor';
    $copyTree($fixture, $relocated);
    \rename($relocated . '/vendor', $relocated . '/libs');
    $renamed = $compile(['compile', $work . '/Acme.mog', '--lib-composer', $relocated . '/libs', '-o', $work . '/renamed-vendor.phar']);
    $assert(($renamed['exitCode'] ?? 0) !== 0, 'a renamed vendor directory must be refused, not half-registered');
    $assert(
        \str_contains($renamed['stderr'], 'must hold `vendor/autoload.php`'),
        'the refusal must name the autoloader path the artifact carries: ' . $renamed['stderr'],
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
    \mkdir($composerBin, 0777, true);
    \file_put_contents(
        $composerBin . '/composer',
        "#!/usr/bin/env bash\n"
        . "for arg in \"\$@\"; do\n"
        . "  if [ \"\$arg\" = \"--version\" ]; then\n    echo 'Composer version 2.10.3 (stub)'\n    exit 0\n  fi\ndone\n"
        . "rm -rf vendor\ncp -R \"\$ACME_VENDOR\" vendor\n",
    );
    \chmod($composerBin . '/composer', 0755);
    $composerEnv = $env;
    $composerEnv['PATH'] = $composerBin . ':' . (string) \getenv('PATH');
    $composerEnv['ACME_VENDOR'] = $fixture . '/vendor';
    $phpInstalled = runCompiledProcess([\PHP_BINARY, $root . '/moggi.php', 'install'], 300, $composerEnv, $phpProject);
    $assert(($phpInstalled['exitCode'] ?? 1) === 0, 'moggi install must write the lock offline: ' . \trim($phpInstalled['stderr']));
    $phpBuilt = runCompiledProcess([\PHP_BINARY, $root . '/moggi.php', 'build', '--backend', 'php', '-o', $work . '/built.phar'], 300, $composerEnv, $phpProject);
    $assert(($phpBuilt['exitCode'] ?? 1) === 0, 'moggi build must forward its Composer tree: ' . \trim($phpBuilt['stderr']));
    $phpBuiltRun = runCompiledProcess([\PHP_BINARY, $work . '/built.phar'], 60, $env, $work);
    $assert(($phpBuiltRun['exitCode'] ?? 1) === 0, 'the built phar must run: ' . \trim($phpBuiltRun['stderr']));
    $assert(trim($phpBuiltRun['stdout']) === 'Hello, composer!', 'the built phar must carry the host tree: ' . $phpBuiltRun['stdout']);

    // A NuGet tree is what NuGet resolves: DLLs. A `.json` type sidecar beside them is metadata a
    // Moggi library adds — it scopes a third-party CLR type to its own assembly — not a condition of
    // the tree being usable, because a program that calls only framework types is served by the DLLs
    // alone and a sidecar-only tree has nothing that can be staged or linked.
    $dllOnly = $work . '/dll-only';
    $jsonOnly = $work . '/json-only';
    $dllAndSidecar = $work . '/dll-and-sidecar';
    foreach ([$dllOnly, $jsonOnly, $dllAndSidecar] as $dir) {
        \mkdir($dir, 0777, true);
    }
    \file_put_contents($jsonOnly . '/probe.json', '{"assemblies": {}}');
    foreach ([$dllOnly, $dllAndSidecar] as $dir) {
        \file_put_contents($dir . '/probe.dll', '');
    }
    \file_put_contents($dllAndSidecar . '/probe.json', '{"assemblies": {}}');

    // What NuGet keeps is nested — `<package>/<version>/lib/<tfm>/Probe.dll` — and that cache is not
    // the set a compile is handed: the refusal names the directory that is.
    $packagesRoot = $work . '/nuget-packages';
    \mkdir($packagesRoot . '/probe/1.0.0/lib/net8.0', 0777, true);
    \file_put_contents($packagesRoot . '/probe/1.0.0/lib/net8.0/probe.dll', '');
    $nested = $compile(['compile', $work . '/Acme.mog', '--lib-nuget', $packagesRoot, '--backend', 'dotnet', '-o', $work . '/nested.dll']);
    $assert(($nested['exitCode'] ?? 0) !== 0, 'a NuGet package cache is not the resolved directory');
    $assert(
        \str_contains($nested['stderr'], 'lib/<tfm>/'),
        'the refusal must name the directory that is: ' . $nested['stderr'],
    );

    $sidecarOnly = $compile(['compile', $work . '/Acme.mog', '--lib-nuget', $jsonOnly, '--backend', 'dotnet', '-o', $work . '/sidecar-only.dll']);
    $assert(($sidecarOnly['exitCode'] ?? 0) !== 0, 'a tree with no assembly must be refused');
    $assert(
        \str_contains($sidecarOnly['stderr'], 'is not the assemblies NuGet resolved'),
        'the refusal must say what a NuGet tree is missing: ' . $sidecarOnly['stderr'],
    );
    foreach ([$dllOnly => 'DLLs alone', $dllAndSidecar => 'a DLL with its sidecar'] as $dir => $what) {
        $accepted = $compile(['compile', $work . '/Acme.mog', '--lib-nuget', $dir, '--backend', 'dotnet', '-o', $work . '/accepted.dll']);
        $assert(
            ! \str_contains($accepted['stderr'], 'is not the assemblies NuGet resolved'),
            $what . ' must pass the shape gate: ' . $accepted['stderr'],
        );
    }

    // `--lib-maven`: a jar built offline and linked for real. The flag is what makes the call
    // resolvable — the same program without it fails at run time.
    $javac = findExecutable('javac');
    $jarTool = findExecutable('jar');
    $java = findExecutable('java');
    if ($javac !== null && $jarTool !== null && $java !== null) {
        $mavenTree = $work . '/maven-tree';
        $mavenClasses = $work . '/maven-classes';
        \mkdir($mavenTree, 0777, true);
        \mkdir($work . '/maven-src/acme', 0777, true);
        \file_put_contents(
            $work . '/maven-src/acme/Hello.java',
            "package acme;\n\npublic class Hello {\n"
            . "    public static String greet(String name) {\n        return \"hello \" + name;\n    }\n}\n",
        );
        \mkdir($work . '/maven-prog', 0777, true);
        \file_put_contents(
            $work . '/maven-prog/Main.mog',
            "module Main where\n\nimport Data.String\nimport System.IO\n\n"
            . "foreign jvm function greet \"acme.Hello:greet\" :: String -> String\n\n"
            . "main :: IO ()\nmain = putStrLn (greet \"maven\")\n",
        );
        $javacRun = runCompiledProcess([$javac, '-d', $mavenClasses, $work . '/maven-src/acme/Hello.java'], 120, $env, $work);
        $assert(($javacRun['exitCode'] ?? 1) === 0, 'javac must build the jar fixture: ' . \trim($javacRun['stderr']));
        $jarRun = runCompiledProcess([$jarTool, 'cf', $mavenTree . '/acme-hello.jar', '-C', $mavenClasses, '.'], 120, $env, $work);
        $assert(($jarRun['exitCode'] ?? 1) === 0, 'jar must package the class: ' . \trim($jarRun['stderr']));

        $jarred = $compile(['compile', $work . '/maven-prog/Main.mog', '--backend', 'jvm', '--lib-maven', $mavenTree, '-o', $work . '/with-maven.jar']);
        $assert(($jarred['exitCode'] ?? 1) === 0, 'a jar directory named with --lib-maven must compile: ' . \trim($jarred['stderr']));
        $jarredRun = runCompiledProcess([$java, '-jar', $work . '/with-maven.jar'], 120, $env, $work);
        $assert(($jarredRun['exitCode'] ?? 1) === 0, 'the jvm artifact must run: ' . \trim($jarredRun['stderr']));
        $assert(\trim($jarredRun['stdout']) === 'hello maven', 'the jar\'s method must answer: ' . $jarredRun['stdout']);

        $unlinked = $compile(['compile', $work . '/maven-prog/Main.mog', '--backend', 'jvm', '-o', $work . '/without-maven.jar']);
        $unlinkedRun = ($unlinked['exitCode'] ?? 1) === 0
            ? runCompiledProcess([$java, '-jar', $work . '/without-maven.jar'], 120, $env, $work)
            : ['exitCode' => 1, 'stderr' => $unlinked['stderr'], 'stdout' => ''];
        $assert(
            ($unlinkedRun['exitCode'] ?? 0) !== 0,
            'without the host tree the class is not on the artifact: ' . \trim($unlinkedRun['stderr']),
        );

        // `moggi build` on a package with a Maven coordinate, resolved by a stub `mvn` (no network) and
        // forwarded to the compile. The jar has to arrive in the artifact — which is exactly what
        // forwarding the artifact directory rather than the resolution tree around it decides.
        $project = $work . '/maven-project';
        \mkdir($project . '/src', 0777, true);
        \file_put_contents(
            $project . '/acme-demo.moggi',
            "[package]\nname = acme-demo\nversion = 0.1.0\nbackends = jvm\n\n"
            . "[lib]\nsource-dirs = src\n\n"
            . "[jvm.maven]\nacme:hello = 1.0.0\n\n"
            . "[executable]\nmain = Main\nsource-dirs = src\n\n"
            . "[author]\nname = Test Author\nemail = test@example.com\n"
            . "npub = npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm\n",
        );
        \copy($work . '/maven-prog/Main.mog', $project . '/src/Main.mog');
        $stubBin = $work . '/stub-bin';
        \mkdir($stubBin, 0777, true);
        \file_put_contents(
            $stubBin . '/mvn',
            "#!/usr/bin/env bash\n"
            . "for arg in \"\$@\"; do\n"
            . "  if [ \"\$arg\" = \"-version\" ] || [ \"\$arg\" = \"--version\" ]; then\n"
            . "    echo 'Apache Maven 3.9.12 (stub)'\n    exit 0\n  fi\ndone\n"
            . "mkdir -p jvm\ncp \"\$ACME_JAR\" jvm/acme-hello.jar\n",
        );
        \chmod($stubBin . '/mvn', 0755);
        $buildEnv = $env;
        $buildEnv['PATH'] = $stubBin . ':' . (string) \getenv('PATH');
        $buildEnv['ACME_JAR'] = $mavenTree . '/acme-hello.jar';
        $installed = runCompiledProcess([\PHP_BINARY, $root . '/moggi.php', 'install'], 300, $buildEnv, $project);
        $assert(($installed['exitCode'] ?? 1) === 0, 'moggi install must write the lock offline: ' . \trim($installed['stderr']));
        $built = runCompiledProcess([\PHP_BINARY, $root . '/moggi.php', 'build', '--backend', 'jvm', '-o', $work . '/built.jar'], 300, $buildEnv, $project);
        $assert(($built['exitCode'] ?? 1) === 0, 'moggi build must forward its host tree: ' . \trim($built['stderr']));
        $builtRun = runCompiledProcess([$java, '-jar', $work . '/built.jar'], 120, $env, $work);
        $assert(($builtRun['exitCode'] ?? 1) === 0, 'the built artifact must run: ' . \trim($builtRun['stderr']));
        $assert(\trim($builtRun['stdout']) === 'hello maven', 'the built artifact must carry the host jar: ' . $builtRun['stdout']);
    } else {
        \fwrite(STDERR, "cli/host_libraries: skipped the --lib-maven leg (no javac/jar/java on PATH)\n");
    }

    // `--lib-nuget`: a DLL assembled offline, with the sidecar that scopes its type, linked for real.
    $ilasm = Moggi\Backend\DotNet\resolveIlasmExecutable();
    $dotnet = Moggi\Backend\DotNet\resolveDotnetExecutable();
    if ($ilasm !== null && $dotnet !== null) {
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
        $assembled = runCompiledProcess([$ilasm, '-NOLOGO', '-QUIET', '-DLL', '-OUTPUT=' . $nugetTree . '/Probe.dll', $work . '/Probe.il'], 120, $env, $work);
        $assert(($assembled['exitCode'] ?? 1) === 0 && \is_file($nugetTree . '/Probe.dll'), 'ilasm must build the DLL fixture: ' . \trim($assembled['stderr']));
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

        $linked = $compile(['compile', $work . '/nuget-prog/Main.mog', '--backend', 'dotnet', '--lib-nuget', $nugetTree, '-o', $work . '/with-nuget.dll']);
        $assert(($linked['exitCode'] ?? 1) === 0, 'a DLL tree named with --lib-nuget must compile: ' . \trim($linked['stderr']));
        $linkedRun = runCompiledProcess([$dotnet, $work . '/with-nuget.dll'], 120, $env, $work);
        $assert(($linkedRun['exitCode'] ?? 1) === 0, 'the dotnet artifact must run: ' . \trim($linkedRun['stderr']));
        $assert(\trim($linkedRun['stdout']) === 'hello nuget', 'the assembly\'s method must answer: ' . $linkedRun['stdout']);

        // Without the sidecar the type has no assembly of its own: the compiler falls back to
        // `System.Runtime` and the call raises TypeLoadException. The gate accepts that tree (it is
        // what NuGet resolves), so the failure is the call's, not the tree's.
        $bareTree = $work . '/nuget-bare';
        \mkdir($bareTree, 0777, true);
        \copy($nugetTree . '/Probe.dll', $bareTree . '/Probe.dll');
        $bare = $compile(['compile', $work . '/nuget-prog/Main.mog', '--backend', 'dotnet', '--lib-nuget', $bareTree, '-o', $work . '/bare.dll']);
        $bareRun = ($bare['exitCode'] ?? 1) === 0
            ? runCompiledProcess([$dotnet, $work . '/bare.dll'], 120, $env, $work)
            : ['exitCode' => 1, 'stderr' => $bare['stderr'], 'stdout' => ''];
        $assert(
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
        \mkdir($dotnetBin, 0777, true);
        \file_put_contents(
            $dotnetBin . '/dotnet',
            "#!/usr/bin/env bash\n"
            . "if [ \"\$1\" = \"--version\" ]; then\n  echo '8.0.425'\n  exit 0\nfi\n"
            . "if [ \"\$1\" = \"restore\" ]; then\n  mkdir -p obj\n  cat > obj/project.assets.json <<'JSON'\n"
            . $assets . "\nJSON\n  exit 0\nfi\n"
            . 'exec ' . \escapeshellarg($dotnet) . " \"\$@\"\n",
        );
        \chmod($dotnetBin . '/dotnet', 0755);
        $dotnetBuildEnv = $env;
        $dotnetBuildEnv['PATH'] = $dotnetBin . ':' . (string) \getenv('PATH');
        $nugetInstalled = runCompiledProcess([\PHP_BINARY, $root . '/moggi.php', 'install'], 300, $dotnetBuildEnv, $nugetProject);
        $assert(($nugetInstalled['exitCode'] ?? 1) === 0, 'moggi install must write the lock offline: ' . \trim($nugetInstalled['stderr']));
        $dotnetBuilt = runCompiledProcess([\PHP_BINARY, $root . '/moggi.php', 'build', '--backend', 'dotnet', '-o', $work . '/built.dll'], 300, $dotnetBuildEnv, $nugetProject);
        $assert(($dotnetBuilt['exitCode'] ?? 1) === 0, 'moggi build must forward its NuGet tree: ' . \trim($dotnetBuilt['stderr']));
        $assert(
            \str_contains($dotnetBuilt['stdout'] . $dotnetBuilt['stderr'], 'Probe.dll'),
            'the run-resolved assembly must be staged beside the artifact: ' . $dotnetBuilt['stdout'],
        );
        $dotnetBuiltRun = runCompiledProcess([$dotnet, $work . '/built.dll'], 120, $env, $work);
        $assert(($dotnetBuiltRun['exitCode'] ?? 1) === 0, 'the built dll must run: ' . \trim($dotnetBuiltRun['stderr']));
        $assert(trim($dotnetBuiltRun['stdout']) === '42', 'the built dll must answer: ' . $dotnetBuiltRun['stdout']);
    } else {
        \fwrite(STDERR, "cli/host_libraries: skipped the --lib-nuget leg (no ilasm or dotnet)\n");
    }

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
