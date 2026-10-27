<?php declare(strict_types=1);

// backends: jvm

/**
 * `--lib-maven`: a jar directory built offline and linked for real. The flag is what makes the call
 * resolvable — the same program without it fails at run time — and `moggi build` forwards the
 * artifact directory a stub `mvn` resolved rather than the tree around it, which is what decides
 * whether the jar reaches the artifact.
 */

require __DIR__ . '/../suite/support/host_libraries.php';

use function Moggi\Compiler\findExecutable;

$case = new HostLibraryCase(hostLibrariesRoot());
$work = $case->work;

try {
    $javac = findExecutable('javac');
    $jarTool = findExecutable('jar');
    $java = findExecutable('java');
    if ($javac === null || $jarTool === null || $java === null) {
        $case->skipLeg('the --lib-maven leg needs javac, jar and java on PATH');
        $case->finish('cli/host_libraries_jvm');
        return;
    }

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
    $javacRun = $case->run([$javac, '-d', $mavenClasses, $work . '/maven-src/acme/Hello.java']);
    $case->assert(($javacRun['exitCode'] ?? 1) === 0, 'javac must build the jar fixture: ' . \trim($javacRun['stderr']));
    $jarRun = $case->run([$jarTool, 'cf', $mavenTree . '/acme-hello.jar', '-C', $mavenClasses, '.']);
    $case->assert(($jarRun['exitCode'] ?? 1) === 0, 'jar must package the class: ' . \trim($jarRun['stderr']));

    $jarred = $case->compile([$work . '/maven-prog/Main.mog', '--backend', 'jvm', '--lib-maven', $mavenTree, '-o', $work . '/with-maven.jar']);
    $case->assert(($jarred['exitCode'] ?? 1) === 0, 'a jar directory named with --lib-maven must compile: ' . \trim($jarred['stderr']));
    $jarredRun = $case->run([$java, '-jar', $work . '/with-maven.jar']);
    $case->assert(($jarredRun['exitCode'] ?? 1) === 0, 'the jvm artifact must run: ' . \trim($jarredRun['stderr']));
    $case->assert(\trim($jarredRun['stdout']) === 'hello maven', 'the jar\'s method must answer: ' . $jarredRun['stdout']);

    $unlinked = $case->compile([$work . '/maven-prog/Main.mog', '--backend', 'jvm', '-o', $work . '/without-maven.jar']);
    $unlinkedRun = ($unlinked['exitCode'] ?? 1) === 0
        ? $case->run([$java, '-jar', $work . '/without-maven.jar'])
        : ['exitCode' => 1, 'stderr' => $unlinked['stderr'], 'stdout' => ''];
    $case->assert(
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
    $case->stubResolver($stubBin, 'mvn', <<<'PHP'
        foreach ($argv as $arg) {
            if ($arg === '-version' || $arg === '--version') {
                echo "Apache Maven 3.9.12 (stub)\n";
                exit(0);
            }
        }
        @\mkdir('jvm', 0777, true);
        \copy((string) \getenv('ACME_JAR'), 'jvm/acme-hello.jar');
        PHP);
    $buildEnv = $case->withPath($case->env, $stubBin);
    $buildEnv['ACME_JAR'] = $mavenTree . '/acme-hello.jar';
    $installed = $case->cli(['install'], $project, $buildEnv);
    $case->assert(($installed['exitCode'] ?? 1) === 0, 'moggi install must write the lock offline: ' . \trim($installed['stderr']));
    $built = $case->cli(['build', '--backend', 'jvm', '-o', $work . '/built.jar'], $project, $buildEnv);
    $case->assert(($built['exitCode'] ?? 1) === 0, 'moggi build must forward its host tree: ' . \trim($built['stderr']));
    $builtRun = $case->run([$java, '-jar', $work . '/built.jar']);
    $case->assert(($builtRun['exitCode'] ?? 1) === 0, 'the built artifact must run: ' . \trim($builtRun['stderr']));
    $case->assert(\trim($builtRun['stdout']) === 'hello maven', 'the built artifact must carry the host jar: ' . $builtRun['stdout']);

    $case->finish('cli/host_libraries_jvm');
} finally {
    $case->cleanup();
}
