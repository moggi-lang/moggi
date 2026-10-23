<?php declare(strict_types=1);

// A cached product answers exactly the compilation that asked for it. Two requests in one process
// that differ in target, purpose, entry module, module root or source must not see each other's
// products, and a fresh process with the same declared inputs must derive the same artifacts as the
// warm one. The case exercises the real entry points — `compileProjectBoth` for a project and
// `moggi compile` for a fresh process — and checks products by the artifacts they produce.
//
// Three scenarios, each one question, and every request answers as many assertions as it can:
//   1. in-process cache behavior — cold/warm, php/jvm/dotnet isolation, entry modules, module
//      roots, and recovery after a failed compilation
//   2. cross-process persistence — a fresh process derives the warm artifact, and its own root
//   3. cache integrity and the key contract — tampered products are rebuilt, and the key names
//      exactly the inputs it claims to (no compilation)

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

$contextFor = static fn (
    string $backendId,
    array $entries,
    array $roots,
    array $files,
): CompilationContext => CompilationContext::for(
    $backendId,
    CompilePurpose::Executable,
    $entries,
    $roots,
    $files,
);

$emitsExtension = static function (array $emit, string $extension): bool {
    return \array_any(
        \array_keys($emit),
        static fn (string $path): bool => \str_ends_with($path, $extension),
    );
};

/** Every file in `$dir` with `$extension`, recursively; `[]` when the directory is absent. */
$cachePaths = static function (string $dir, string $extension): array {
    if (! \is_dir($dir)) {
        return [];
    }

    $paths = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === $extension) {
            $paths[] = $file->getPathname();
        }
    }

    return $paths;
};

$filesA = $closureFor($rootA);
$filesB = $closureFor($rootB);
$phpMainA = $contextFor('php', ['Main'], [$rootA], $filesA);

try {
    // ==== 1 · in-process cache behavior ====================================
    // One declared input, asked for twice in a row: cold and warm must agree.
    $cold = $compile($filesA, $project, $phpMainA);
    $warm = $compile($filesA, $project, $phpMainA);
    $assert($cold['emit'] === $warm['emit'], 'a warm run did not reproduce the cold artifacts');
    $assert(
        \str_contains($cold['emit']['Main.php'] ?? '', 'root-a'),
        'the cold run did not emit the module the root resolves',
    );

    // The same sources as two other targets: each emits its own artifact and disturbs neither the
    // other target's products nor php's.
    $jvmFiles = $closureFor($rootA, 'jvm');
    $jvmContext = $contextFor('jvm', ['Main'], [$rootA], $jvmFiles);
    $jvmExtension = Backend\backendById('jvm')->extension();
    $jvmRun = $compile($jvmFiles, $project, $jvmContext);
    $assert(
        $emitsExtension($jvmRun['emit'], $jvmExtension),
        "the jvm run emitted no {$jvmExtension} artifact",
    );
    $assert($jvmRun['emit'] !== $cold['emit'], 'the jvm run served the php run\'s products');

    $dotnetFiles = $closureFor($rootA, 'dotnet');
    $dotnetContext = $contextFor('dotnet', ['Main'], [$rootA], $dotnetFiles);
    $dotnetExtension = Backend\backendById('dotnet')->extension();
    $dotnetRun = $compile($dotnetFiles, $project, $dotnetContext);
    $assert(
        $emitsExtension($dotnetRun['emit'], $dotnetExtension),
        "the dotnet run emitted no {$dotnetExtension} artifact",
    );
    $assert($dotnetRun['emit'] !== $cold['emit'], 'the dotnet run served the php run\'s products');
    $assert($dotnetRun['emit'] !== $jvmRun['emit'], 'the dotnet run served the jvm run\'s products');

    // The same sources with another entry: the promoted `main` follows the request, and Main loses
    // the entry treatment it had.
    $alt = $compile($filesA, $project, $contextFor('php', ['Alt'], [$rootA], $filesA));
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

    // A second module root for the same sources: the request resolves its own `Dep`, and the first
    // root's artifact is not what answers it.
    $rootBRun = $compile($filesB, $project, $contextFor('php', ['Main'], [$rootB], $filesB));
    $assert(
        \str_contains($rootBRun['emit']['Main.php'] ?? '', 'root-b'),
        'the second root did not supply its own module',
    );
    $assert(
        ($rootBRun['emit']['Main.php'] ?? null) !== ($cold['emit']['Main.php'] ?? null),
        'the second root served the first root\'s artifact',
    );

    // A failed request writes nothing a later request reads. This last compile is also the return
    // leg for everything above: it asks for Main under root A after a cross-target request, an
    // entry change and a root change, and must get the cold artifacts back. One compile, both
    // claims — a separate "php again" request would prove the same thing and cost the same work.
    \file_put_contents($project . '/Broken.mog', "module Broken where\n\nbad :: Int\nbad = \"not an int\"\n");
    $brokenFiles = [...$filesA, $project . '/Broken.mog'];
    try {
        $compile($brokenFiles, $project, $contextFor('php', ['Main'], [$rootA], $brokenFiles));
        $assert(false, 'a type-incorrect module compiled');
    } catch (\Moggi\Semantics\Types\TypeError) {
        // Expected: the failing request wrote nothing another request may read.
    }
    \unlink($project . '/Broken.mog');
    $afterFailure = $compile($filesA, $project, $phpMainA);
    $assert(
        $afterFailure['emit'] === $cold['emit'],
        'the php products did not survive the cross-target, entry, root and failed requests',
    );

    // ==== 2 · cross-process persistence ====================================
    // A cold process with the same declared inputs derives the artifact the warm in-process one
    // did — the parity claim, and it replaces a second same-root CLI run: that comparison already
    // answers determinism against `$cold`, so the remaining run asks the root question.
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

    // The CLI mirrors the source tree under `-o`, so its `__DIR__`-relative `require`s carry a
    // different depth than an artifact keyed at the project root — and the in-process emitter spells
    // a library at its absolute path while the CLI spells the same file as a `..` walk. Canonicalize
    // that one line shape (the last two path segments, after dropping `..` and the root) and require
    // everything else to be byte-identical: same requires, same module body.
    $canonicalRequire = static function (string $path): string {
        $parts = \array_values(\array_filter(
            \explode('/', $path),
            static fn (string $segment): bool => $segment !== '' && $segment !== '..',
        ));

        return \implode('/', \array_slice($parts, -2));
    };
    $artifactBody = static function (string $source) use ($canonicalRequire): string {
        return \preg_replace_callback(
            '~require_once __DIR__ \. \'([^\']*)\';~',
            static fn (array $match): string => "require_once __DIR__ . '" . $canonicalRequire($match[1]) . "';",
            $source,
        ) ?? $source;
    };

    $firstProcess = $cliCompile($rootA, $work . '/out-a');
    $assert(
        $artifactBody($firstProcess) === $artifactBody($cold['emit']['Main.php']),
        'a fresh process derived a different artifact for the same declared inputs',
    );
    $assert(\str_contains($firstProcess, 'root-a'), 'the fresh process did not use the root it was given');
    $otherRootProcess = $cliCompile($rootB, $work . '/out-b');
    $assert(
        \str_contains($otherRootProcess, 'root-b') && $otherRootProcess !== $firstProcess,
        'a fresh process given another root served the first root\'s artifact',
    );

    // ==== 3 · cache integrity and the key contract =========================
    // The project artifact answers before the module products do, so drop it first and then tamper
    // the module entry the prepare pass would read.
    foreach ($cachePaths($cacheDir, 'blob') as $blob) {
        \unlink($blob);
    }

    // One product per target: scenario 1 compiled php, jvm and dotnet, so `Main.checked.mogc`
    // exists three times under `compile/<backend>/…`. Pick the php one by the target it records —
    // the file the next php compile reads — not the first the directory iterator returns: that
    // order is the filesystem's, not the cache's. Returning another target's product turned the
    // tamper into a no-op on a file no php compile touches, which is the macOS failure, and it
    // reproduces on ext4 by selecting that product deliberately.
    $phpModuleCaches = \array_values(\array_filter(
        $cachePaths($cacheDir, 'mogc'),
        static function (string $path): bool {
            if (\basename($path) !== 'Main.checked.mogc') {
                return false;
            }
            $value = \unserialize((string) \file_get_contents($path));

            return \is_array($value) && ($value['target'] ?? null) === 'php';
        },
    ));
    $moduleCache = $phpModuleCaches[0] ?? null;
    $assert(\is_string($moduleCache), 'the compile wrote no php module product to tamper with');
    $tampered = \unserialize((string) \file_get_contents($moduleCache));
    $assert(\is_array($tampered), 'the module product did not decode');
    $tampered['target'] = 'jvm';
    \file_put_contents($moduleCache, \serialize($tampered));
    $afterTamper = $compile($filesA, $project, $phpMainA);
    $assert($afterTamper['emit'] === $cold['emit'], 'an incompatible cached entry was served');
    $rebuilt = \unserialize((string) \file_get_contents($moduleCache));
    $assert(
        \is_array($rebuilt) && ($rebuilt['target'] ?? null) === 'php',
        'the incompatible module entry was not replaced',
    );

    // The project artifact is keyed by the whole compilation rather than by `cacheKey()` alone, so
    // it is identified by what it holds: the one whose payload is this run's php emit. Position
    // would do today — the `.blob` sweep above leaves nothing else — but not by construction, and
    // position is exactly what made the module-product tamper pass on ext4 and fail on APFS.
    $artifact = \array_find(
        $cachePaths($cacheDir . '/compile/artifacts', 'blob'),
        static function (string $path) use ($cold): bool {
            $value = \unserialize((string) \file_get_contents($path));

            return \is_array($value) && ($value['payload']['emit'] ?? null) === $cold['emit'];
        },
    );
    $assert(\is_string($artifact), 'the compile wrote no php project artifact to tamper with');
    $artifactValue = \unserialize((string) \file_get_contents($artifact));
    $assert(\is_array($artifactValue), 'the project artifact did not decode');
    $artifactValue['key'] = 'other-compilation';
    \file_put_contents($artifact, \serialize($artifactValue));
    $afterArtifactTamper = $compile($filesA, $project, $phpMainA);
    $assert($afterArtifactTamper['emit'] === $cold['emit'], 'an incompatible project artifact was served');
    $artifactRebuilt = \unserialize((string) \file_get_contents($artifact));
    $assert(
        \is_array($artifactRebuilt) && ($artifactRebuilt['key'] ?? null) !== 'other-compilation',
        'the incompatible project artifact was not replaced',
    );

    // The key names the module's own inputs: the target, whether the module is the entry, and its
    // content Merkle. The caller's roots and a purpose that promotes nothing do not enter it,
    // because the Merkle already pins what those roots resolved
    // (see CompilationContext::moduleCacheMaterial); a product built once is the one every later
    // compilation finds. No compilation needed for any of this.
    $contentKey = 'content-key';
    $keys = [
        $phpMainA->moduleKey('Main', $contentKey),
        $phpMainA->moduleKey('Alt', $contentKey),
        $jvmContext->moduleKey('Main', $contentKey),
        $jvmContext->moduleKey('Alt', $contentKey),
        $dotnetContext->moduleKey('Main', $contentKey),
        $dotnetContext->moduleKey('Alt', $contentKey),
    ];
    $assert(\count(\array_unique($keys)) === \count($keys), 'two distinct declared inputs shared a module cache key');

    $replFromB = CompilationContext::for('php', CompilePurpose::Repl, roots: [$rootB]);
    $libraryContext = CompilationContext::for('php', CompilePurpose::Library, ['Main'], [$rootA], $filesA);
    $shared = [
        $phpMainA->moduleKey('Dep', $contentKey),
        $contextFor('php', ['Main'], [$rootB], $filesB)->moduleKey('Dep', $contentKey),
        $libraryContext->moduleKey('Dep', $contentKey),
        $replFromB->moduleKey('Dep', $contentKey),
    ];
    $assert(
        \count(\array_unique($shared)) === 1,
        'the caller\'s roots or a purpose that promotes nothing gave a library module a private key',
    );
    $assert(
        $phpMainA->purposeFor('Main') !== $phpMainA->purposeFor('Alt'),
        'the entry set did not decide which module is compiled as the entry',
    );
    $assert(
        $phpMainA->cacheKey() !== $contextFor('php', ['Main'], [$rootA], [...$filesA, $project . '/Alt.mog'])->cacheKey(),
        'changing the sources did not change the project cache key',
    );
    $assert(
        $phpMainA->cacheKey() !== $dotnetContext->cacheKey(),
        'the target did not change the project cache key',
    );

    echo "declared input cache tests passed ({$checks} checks)\n";
} finally {
    removeDirectory($work);
}
