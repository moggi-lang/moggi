<?php declare(strict_types=1);

/**
 * Artifact paths and inter-artifact `require` paths must use the same
 * root-relative calculation. If only one handles the root prefix correctly,
 * artifacts are written at one depth while their requires resolve from
 * another.
 *
 * The Windows-only case is a closure with sources on the same drive but
 * no common root: an application in a temp directory and the standard
 * library in a checkout. `commonPathPrefix` returns the bare separator,
 * which `realpath()` resolves to the drive root.
 *
 * The inputs use Windows' resolved spelling, `/`, produced by
 * `canonicalSeparators` and left unchanged on this host. This keeps the
 * path arithmetic identical across platforms. Build the resulting tree
 * for real and execute the compiler-generated path expressions against it.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
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
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$appSource = 'C:/Users/runneradmin/AppData/Local/Temp/moggi-entry-module-6192/src/App.mog';
$libSource = 'C:/a/moggi/moggi/libraries/base/src/System/IO/PHP.mog';
$appSourceRelative = 'Users/runneradmin/AppData/Local/Temp/moggi-entry-module-6192/src/App.mog';
$libSourceRelative = 'a/moggi/moggi/libraries/base/src/System/IO/PHP.mog';
$appRelative = \preg_replace('/\.mog$/', '.php', $appSourceRelative) ?? $appSourceRelative;
$libRelative = \preg_replace('/\.mog$/', '.php', $libSourceRelative) ?? $libSourceRelative;

$work = createTempDir('moggi-artifact-require');

try {
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
            if (\realpath($target) === false || ! \is_file((string) \realpath($target))) {
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
