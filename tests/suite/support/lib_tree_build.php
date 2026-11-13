<?php declare(strict_types=1);

/**
 * Build one backend's library tree into a staging directory, in a process of its own.
 *
 * The build typechecks and emits the whole standard library, and its heap reaches a high-water
 * mark an order of magnitude above any other compile the run performs (~1.4 GB for the base
 * library). PHP's allocator does not hand a process's chunks back when the build's live set
 * collapses, so a process that builds the tree and then keeps compiling spends the rest of its
 * `memory_limit` on an arena it cannot give back: `optimize/opt_differential` did exactly that and
 * died inside `unserialize()` at 1.5 GB. Running the build here keeps that mark out of the
 * caller's heap — the tree is published to disk either way, so the process that built it is
 * disposable.
 *
 * argv: <projectRoot> <backend> <stagingDir> <json library roots>
 */

$root = $argv[1] ?? '';
$backend = $argv[2] ?? '';
$staging = $argv[3] ?? '';
$roots = \json_decode($argv[4] ?? '[]', true);

if ($root === '' || $backend === '' || $staging === '' || ! \is_array($roots)) {
    \fwrite(\STDERR, "usage: lib_tree_build.php <projectRoot> <backend> <stagingDir> <roots-json>\n");
    exit(64);
}

\define('MOGGI_PROJECT_ROOT', $root);

require $root . '/src/compiler.php';
require __DIR__ . '/bootstrap.php';

\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');
foreach ($roots as $libraryRoot) {
    if (\is_string($libraryRoot) && $libraryRoot !== '') {
        \Moggi\Modules\addLibraryRoot($libraryRoot);
    }
}

$files = testLibraryFiles($root);
$outputs = \Moggi\Modules\compileProject(
    $files,
    $root,
    \Moggi\Pipeline\CompilationContext::for(
        $backend,
        \Moggi\Pipeline\CompilePurpose::Library,
        sources: $files,
    ),
);

foreach ($outputs as $relative => $source) {
    $target = $staging . '/' . $relative;
    @\mkdir(\dirname($target), 0777, true);
    if (\file_put_contents($target, $source) === false) {
        \fwrite(\STDERR, "cannot write {$target}\n");
        exit(1);
    }
}
