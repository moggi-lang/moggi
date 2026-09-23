#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Compiler test suite entry point (`runtest` in the dev shell wraps this).
 *
 * What runs is the tree itself (`tests/<subject>/`, `examples/`); how it runs lives in
 * tests/suite (support · runners · driver) with the kind table in tests/suite/kinds.php.
 */

const MOGGI_PROJECT_ROOT = __DIR__;

if (getenv('MOGGI_CACHE_DIR') === false && getenv('MOGGI_NO_CACHE') === false) {
    putenv('MOGGI_CACHE_DIR=' . MOGGI_PROJECT_ROOT . '/.moggi');
}

require MOGGI_PROJECT_ROOT . '/src/compiler.php';
require MOGGI_PROJECT_ROOT . '/tests/suite/support/bootstrap.php';

$testArgs = parseTestArgs($argv);

if ($testArgs['help']) {
    \fwrite(STDERR, testUsage());
    exit(0);
}

\Moggi\Modules\setStdlibLibPath(MOGGI_PROJECT_ROOT . '/lib');

exit(runSuite());
