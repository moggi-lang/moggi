#!/usr/bin/env php
<?php declare(strict_types=1);

// A module's artifact path and the `require`s between artifacts are two
// computations of one thing. A root prefix that only one of them can subtract
// leaves the tree written at one depth and its requires counting from another,
// so the file that opens is not the file the require resolves against.
//
// The shape that exposes it is Windows-only: a closure whose sources share no
// root (an application in a temp directory, the standard library in a checkout
// on the same drive), so `commonPathPrefix` reports the bare separator and
// `realpath()` turns it into a drive root. The inputs below are the spelling
// Windows resolves to — `/`, which `canonicalSeparators` produces and this host
// leaves alone — so the arithmetic under test is the same on both. The tree the
// compiler would write is then built for real, and the expressions it computes
// are executed against it.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

use function Moggi\Backend\Php\Codegen\runtimeRequirePath;
use function Moggi\Compiler\runProcess;
use function Moggi\Modules\outputRelativePath;
use function Moggi\Paths\relativeRequirePath;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$appSource = 'C:/Users/runneradmin/AppData/Local/Temp/moggi-entry-module-6192/src/App.mog';
$libSource = 'C:/a/moggi/moggi/lib/System/IO/PHP.mog';
$appSourceRelative = 'Users/runneradmin/AppData/Local/Temp/moggi-entry-module-6192/src/App.mog';
$libSourceRelative = 'a/moggi/moggi/lib/System/IO/PHP.mog';
$appRelative = \preg_replace('/\.mog$/', '.php', $appSourceRelative) ?? $appSourceRelative;
$libRelative = \preg_replace('/\.mog$/', '.php', $libSourceRelative) ?? $libSourceRelative;

$work = createTempDir('moggi-artifact-require');
try {
    // The prefix as `realpath()` returns it with the compiler's separator
    // appended: the raw shape that used to lose characters to a prefix the path
    // did not have, and the canonicalised one the compiler builds. Both have to
    // answer with the same path, or the tree and its requires disagree.
    foreach (['C://', 'C:/'] as $prefix) {
        $assert(
            outputRelativePath($appSource, $prefix) === $appSourceRelative,
            "the application's artifact path under prefix `{$prefix}` lost characters",
        );
        $assert(
            outputRelativePath($libSource, $prefix) === $libSourceRelative,
            "the module's artifact path under prefix `{$prefix}` lost characters",
        );
    }
    $assert(
        \substr($appSource, \strlen('C://')) !== $appSourceRelative,
        'the case is only interesting while a blind substring would answer differently',
    );

    foreach ([\dirname($work . '/' . $appRelative), \dirname($work . '/' . $libRelative)] as $dir) {
        \mkdir($dir, 0777, true);
    }
    \file_put_contents($work . '/_runtime.php', "<?php declare(strict_types=1);\necho \"the runtime loaded\\n\";\n");
    \file_put_contents($work . '/' . $libRelative, "<?php declare(strict_types=1);\necho \"the module loaded\\n\";\n");

    $moduleRequire = relativeRequirePath($appRelative, $libRelative);
    $runtimeRequire = runtimeRequirePath($appSource, ['outputRelative' => $appRelative]);
    \file_put_contents(
        $work . '/' . $appRelative,
        "<?php declare(strict_types=1);\nrequire_once {$runtimeRequire};\nrequire_once {$moduleRequire};\necho \"the app ran\\n\";\n",
    );

    $ran = runProcess([PHP_BINARY, $work . '/' . $appRelative]);
    $assert(($ran['exitCode'] ?? 1) === 0, 'the emitted application did not run: ' . \trim($ran['stderr']));
    $assert(
        \str_contains($ran['stdout'], 'the runtime loaded'),
        "the require to the runtime did not resolve from {$appRelative}:\n" . $ran['stdout'],
    );
    $assert(
        \str_contains($ran['stdout'], 'the module loaded'),
        "the require to the module did not resolve from {$appRelative}:\n" . $ran['stdout'],
    );
    $assert(\str_contains($ran['stdout'], 'the app ran'), 'the application did not finish: ' . $ran['stdout']);

    // The arithmetic above is only half of it: what broke was the compiler
    // writing a file where one computation said and its requires where the
    // other did. So compile a real program — from a directory outside the
    // checkout, which is the shape that has no common root — and hold the tree
    // it emitted to its own `require_once` lines.
    \mkdir($work . '/app/src', 0777, true);
    \file_put_contents(
        $work . '/app/src/App.mog',
        "module App (main) where\n\nimport System.IO\n\nmain :: IO ()\nmain = putStrLn \"hello, world\"\n",
    );
    $compile = runProcess(
        [PHP_BINARY, $root . '/moggi.php', 'compile', $work . '/app/src/App.mog', '-o', $work . '/tree', '--backend', 'php', '--unpacked'],
        $work,
    );
    $assert(($compile['exitCode'] ?? 1) === 0, 'the compile failed: ' . \trim($compile['stdout'] . $compile['stderr']));

    $emitted = [];
    $walk = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($work . '/tree', \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($walk as $file) {
        if ($file->isFile() && \str_ends_with($file->getFilename(), '.php')) {
            $emitted[] = $file->getPathname();
        }
    }
    $assert($emitted !== [], 'the compile emitted no PHP to check');

    $unresolved = [];
    $required = 0;
    foreach ($emitted as $file) {
        $text = (string) \file_get_contents($file);
        \preg_match_all("#require_once __DIR__ \\. '([^']+)'#", $text, $matches);
        foreach ($matches[1] as $relative) {
            ++$required;
            $target = \dirname($file) . '/' . $relative;
            if (\realpath($target) === false || !\is_file((string) \realpath($target))) {
                $unresolved[] = \substr($file, \strlen($work)) . ' requires ' . $relative;
            }
        }
    }
    $assert($required > 0, 'the emitted tree carried no require to check');
    $assert(
        $unresolved === [],
        "the emitted tree does not satisfy its own requires:\n  " . \implode("\n  ", $unresolved),
    );

    echo "artifact require tree tests passed ({$checks} checks)\n";
} finally {
    removeDirectory($work);
}
