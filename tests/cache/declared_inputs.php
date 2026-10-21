<?php declare(strict_types=1);

// A cached product answers exactly the compilation that asked for it. Two requests in one process
// that differ in target, purpose, entry module, module root or source must not see each other's
// products, and a fresh process with the same declared inputs must derive the same artifacts as the
// warm one. The case exercises the real entry points — `compileProjectBoth` for a project and
// `moggi compile` for a fresh process — and checks products by the artifacts they produce.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Backend;
use Moggi\Cache;
use Moggi\Modules;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$work = createTempDir('moggi-cache-declared-inputs');
$cacheDir = $work . '/cache';
\putenv('MOGGI_CACHE_DIR=' . $cacheDir);
Cache\setCacheEnabled(true);
Modules\setStdlibLibPath($root . '/libraries/base/src');

$project = $work . '/project';
\mkdir($project, 0777, true);
\file_put_contents(
    $project . '/Main.mog',
    "module Main where\n\nimport Dep\n\nmain :: IO ()\nmain = putStrLn label\n",
);
\file_put_contents(
    $project . '/Alt.mog',
    "module Alt where\n\nimport Dep\n\nmain :: IO ()\nmain = putStrLn label\n",
);

$writeRoot = static function (string $dir, string $label): void {
    \mkdir($dir, 0777, true);
    \file_put_contents($dir . '/Dep.mog', "module Dep where\n\nlabel :: String\nlabel = \"{$label}\"\n");
};
$rootA = $work . '/root-a';
$rootB = $work . '/root-b';
$writeRoot($rootA, 'root-a');
$writeRoot($rootB, 'root-b');

// A process-global library root would make the roots argument decorative: the context declares the
// roots, and the closure is resolved against exactly those roots.
$closureFor = static function (string $libRoot, string $backendId = 'php') use ($project, $root): array {
    [$files] = Modules\projectSourceClosure(
        [$project . '/Main.mog', $project . '/Alt.mog'],
        [$libRoot, $root . '/libraries/base/src'],
        $backendId,
    );

    return $files;
};

$compile = static function (array $files, string $projectRoot, CompilationContext $context): array {
    // A second process has no in-memory memo; clearing it models that, so what answers the request
    // is the declared keys and the on-disk products, not a leftover object graph.
    Modules\clearPrepareProjectCaches();

    return Modules\compileProjectBoth($files, $projectRoot, $context, false, false);
};

$filesA = $closureFor($rootA);
$filesB = $closureFor($rootB);
$php = static fn (array $files): CompilationContext => CompilationContext::for(
    'php',
    CompilePurpose::Executable,
    ['Main'],
    [$rootA],
    $files,
);

try {
    // 1. Cold then warm: the same declared inputs produce the same artifacts.
    $cold = $compile($filesA, $project, $php($filesA));
    $warm = $compile($filesA, $project, $php($filesA));
    $assert($cold['emit'] === $warm['emit'], 'a warm run did not reproduce the cold artifacts');
    $assert(
        \str_contains($cold['emit']['Main.php'] ?? '', 'root-a'),
        'the cold run did not emit the module the root resolves',
    );

    // 2. Two targets over the same sources: each emits its own artifact and does not disturb the
    // other's cached products.
    $jvmFiles = $closureFor($rootA, 'jvm');
    $jvm = CompilationContext::for('jvm', CompilePurpose::Executable, ['Main'], [$rootA], $jvmFiles);
    $jvmRun = $compile($jvmFiles, $project, $jvm);
    $jvmExtension = Backend\backendById('jvm')->extension();
    $assert(
        \array_any(
            \array_keys($jvmRun['emit']),
            static fn (string $path): bool => \str_ends_with($path, $jvmExtension),
        ),
        "the jvm run emitted no {$jvmExtension} artifact",
    );
    $assert(
        $jvmRun['emit'] !== $cold['emit'],
        'the jvm run served the php run\'s products',
    );
    $phpAgain = $compile($filesA, $project, $php($filesA));
    $assert($phpAgain['emit'] === $cold['emit'], 'the php products changed after a jvm run');

    // 3. Alternating entry modules in one process: the promoted `main` follows the request, and
    // returning to the first entry sees the original products again.
    $altContext = CompilationContext::for(
        'php',
        CompilePurpose::Executable,
        ['Alt'],
        [$rootA],
        $filesA,
    );
    $alt = $compile($filesA, $project, $altContext);
    $assert(
        \str_contains($cold['emit']['Main.php'] ?? '', 'reportUncaught')
            && ! \str_contains($alt['emit']['Main.php'] ?? '', 'reportUncaught'),
        'Main kept the entry treatment another request gave it',
    );
    $assert(
        \str_contains($alt['emit']['Alt.php'] ?? '', 'reportUncaught')
            && ! \str_contains($cold['emit']['Alt.php'] ?? '', 'reportUncaught'),
        'Alt was not treated as this request\'s entry',
    );
    $backToMain = $compile($filesA, $project, $php($filesA));
    $assert(
        $backToMain['emit'] === $cold['emit'],
        'switching back to the first entry did not reproduce its artifacts',
    );

    // 4. Alternating module roots in one process: the second request resolves its own `Dep`, and
    // the first root's artifacts come back unchanged afterwards.
    $contextB = CompilationContext::for(
        'php',
        CompilePurpose::Executable,
        ['Main'],
        [$rootB],
        $filesB,
    );
    $rootBRun = $compile($filesB, $project, $contextB);
    $assert(
        \str_contains($rootBRun['emit']['Main.php'] ?? '', 'root-b'),
        'the second root did not supply its own module',
    );
    $assert(
        ($rootBRun['emit']['Main.php'] ?? null) !== ($cold['emit']['Main.php'] ?? null),
        'the second root served the first root\'s artifact',
    );
    $backToRootA = $compile($filesA, $project, $php($filesA));
    $assert(
        \str_contains($backToRootA['emit']['Main.php'] ?? '', 'root-a'),
        'returning to the first root did not reproduce its artifact',
    );

    // 5. A failure between two successful requests: the next good request is unaffected, and no
    // partial product is served.
    \file_put_contents($project . '/Broken.mog', "module Broken where\n\nbad :: Int\nbad = \"not an int\"\n");
    $brokenFiles = [...$filesA, $project . '/Broken.mog'];
    try {
        $compile($brokenFiles, $project, $php($brokenFiles));
        $assert(false, 'a type-incorrect module compiled');
    } catch (\Moggi\Semantics\Types\TypeError) {
        // Expected: the failing request wrote nothing another request may read.
    }
    \unlink($project . '/Broken.mog');
    $afterFailure = $compile($filesA, $project, $php($filesA));
    $assert($afterFailure['emit'] === $cold['emit'], 'a failed request changed a later successful one');

    // 6. Fresh-process parity: two CLI runs over the same declared inputs, sharing one cache
    // directory, derive the same artifact, and the module root they were given is the one that
    // supplied `Dep`.
    $env = \getenv();
    $env['MOGGI_CACHE_DIR'] = $cacheDir;
    $cliCompile = static function (string $libRoot, string $outDir) use ($root, $project, $env): string {
        removeDirectory($outDir);
        $run = runCompiledProcess(
            [PHP_BINARY, $root . '/moggi.php', 'compile', $project . '/Main.mog', '--lib', $libRoot, '--unpacked', '--no-opt', '--no-strip', '-o', $outDir],
            120,
            $env,
            $root,
        );
        if (($run['exitCode'] ?? 1) !== 0) {
            throw new \RuntimeException("fresh-process compile failed:\n" . $run['stderr'] . $run['stdout']);
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($outDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getFilename() === 'Main.php') {
                return (string) \file_get_contents($file->getPathname());
            }
        }

        throw new \RuntimeException("the fresh process wrote no Main.php under {$outDir}");
    };

    $firstProcess = $cliCompile($rootA, $work . '/out-a');
    $secondProcess = $cliCompile($rootA, $work . '/out-b');
    $assert($firstProcess === $secondProcess, 'two fresh processes derived different artifacts for the same declared inputs');
    $assert(\str_contains($firstProcess, 'root-a'), 'the fresh process did not use the root it was given');
    $otherRootProcess = $cliCompile($rootB, $work . '/out-c');
    $assert(
        \str_contains($otherRootProcess, 'root-b') && $otherRootProcess !== $firstProcess,
        'a fresh process given another root served the first root\'s artifact',
    );

    // 7. Incompatible cached products are invalidated, not served. The project artifact answers
    // before the module products do, so drop it first and then tamper the module entry the prepare
    // pass would read.
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'blob') {
            \unlink($file->getPathname());
        }
    }

    $moduleCache = null;
    $cacheFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS));
    foreach ($cacheFiles as $file) {
        if ($file->isFile() && $file->getFilename() === 'Main.checked.mogc') {
            $moduleCache = $file->getPathname();

            break;
        }
    }
    $assert(\is_string($moduleCache), 'the compile wrote no module product to tamper with');
    $tampered = \unserialize((string) \file_get_contents($moduleCache));
    $assert(\is_array($tampered), 'the module product did not decode');
    $tampered['target'] = 'jvm';
    \file_put_contents($moduleCache, \serialize($tampered));
    $afterTamper = $compile($filesA, $project, $php($filesA));
    $assert($afterTamper['emit'] === $cold['emit'], 'an incompatible cached entry was served');
    $rebuilt = \unserialize((string) \file_get_contents($moduleCache));
    $assert(
        \is_array($rebuilt) && ($rebuilt['target'] ?? null) === 'php',
        'the incompatible module entry was not replaced',
    );

    // The project artifact is keyed the same way: an entry whose key does not match is a miss, and
    // the next request writes its own.
    $artifact = null;
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir . '/compile/artifacts', \FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'blob') {
            $artifact = $file->getPathname();

            break;
        }
    }
    $assert(\is_string($artifact), 'the compile wrote no project artifact to tamper with');
    $artifactValue = \unserialize((string) \file_get_contents($artifact));
    $assert(\is_array($artifactValue), 'the project artifact did not decode');
    $artifactValue['key'] = 'other-compilation';
    \file_put_contents($artifact, \serialize($artifactValue));
    $afterArtifactTamper = $compile($filesA, $project, $php($filesA));
    $assert($afterArtifactTamper['emit'] === $cold['emit'], 'an incompatible project artifact was served');
    $artifactRebuilt = \unserialize((string) \file_get_contents($artifact));
    $assert(
        \is_array($artifactRebuilt) && ($artifactRebuilt['key'] ?? null) !== 'other-compilation',
        'the incompatible project artifact was not replaced',
    );

    // 8. The key itself names each declared input: two contexts that differ in target, purpose or
    // roots address different products for the same module content, and the entry set decides a
    // module's purpose.
    $contentKey = 'content-key';
    $libraryContext = CompilationContext::for('php', CompilePurpose::Library, ['Main'], [$rootA], $filesA);
    $keys = [
        $php($filesA)->moduleKey('Main', $contentKey),
        $jvm->moduleKey('Main', $contentKey),
        $libraryContext->moduleKey('Main', $contentKey),
        $contextB->moduleKey('Main', $contentKey),
    ];
    $assert(\count(\array_unique($keys)) === \count($keys), 'two distinct declared inputs shared a module cache key');
    $assert(
        $php($filesA)->purposeFor('Main') !== $php($filesA)->purposeFor('Alt'),
        'the entry set did not decide which module is compiled as the entry',
    );
    $assert(
        $php($filesA)->cacheKey() !== $php([...$filesA, $project . '/Alt.mog'])->cacheKey(),
        'changing the sources did not change the project cache key',
    );

    echo "declared input cache tests passed ({$checks} checks)\n";
} finally {
    removeDirectory($work);
}
