#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Compiler test suite entry point (`runtest` in the dev shell wraps this).
 *
 * What runs is the tree itself (`tests/<subject>/`, `examples/`); how it runs lives in
 * tests/suite (support · runners · driver) with the kind table in tests/suite/kinds.php.
 */

const MOGGI_PROJECT_ROOT = __DIR__;

if (\getenv('MOGGI_CACHE_DIR') === false && \getenv('MOGGI_NO_CACHE') === false) {
    \putenv('MOGGI_CACHE_DIR=' . MOGGI_PROJECT_ROOT . '/.moggi');
}

require MOGGI_PROJECT_ROOT . '/src/compiler.php';
require MOGGI_PROJECT_ROOT . '/tests/suite/support/bootstrap.php';

$testArgs = parseTestArgs($argv);

if ($testArgs['help']) {
    \fwrite(STDERR, testUsage());
    exit(0);
}

\Moggi\Modules\setStdlibLibPath(MOGGI_PROJECT_ROOT . '/libraries/base/src');
// Only the bundled standard library: packages (`json`) ship from their own repo, and a checkout
// of one joins the library roots through `--lib` or `addLibraryRoot()`.
$arrayRoot = MOGGI_PROJECT_ROOT . '/libraries/array/src';
if (\is_dir($arrayRoot)) {
    // The bundled array package is part of the standard distribution, so
    // fixtures can import Data.Array like any program built on it (the
    // bundled containers package builds on it).
    \Moggi\Modules\addLibraryRoot($arrayRoot);
}
$containersRoot = MOGGI_PROJECT_ROOT . '/libraries/containers/src';
if (\is_dir($containersRoot)) {
    // The bundled containers package is part of the standard distribution, so
    // fixtures can import Data.Set / Data.Map like any program built on it.
    \Moggi\Modules\addLibraryRoot($containersRoot);
}
$timeRoot = MOGGI_PROJECT_ROOT . '/libraries/time/src';
if (\is_dir($timeRoot)) {
    // The bundled time package is part of the standard distribution, so
    // fixtures can import Data.Time.* like any program built on it.
    \Moggi\Modules\addLibraryRoot($timeRoot);
}
$filepathRoot = MOGGI_PROJECT_ROOT . '/libraries/filepath/src';
if (\is_dir($filepathRoot)) {
    // The bundled filepath package is part of the standard distribution, so
    // fixtures can import System.FilePath like any program built on it.
    \Moggi\Modules\addLibraryRoot($filepathRoot);
}
$directoryRoot = MOGGI_PROJECT_ROOT . '/libraries/directory/src';
if (\is_dir($directoryRoot)) {
    // The bundled directory package is part of the standard distribution, so
    // fixtures can import System.Directory like any program built on it.
    \Moggi\Modules\addLibraryRoot($directoryRoot);
}

exit(runSuite());
