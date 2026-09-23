<?php declare(strict_types=1);

/**
 * Test-harness library: `kinds.php` (what a golden means, and the case dispatcher), `support/`
 * (services), `runners/` (one file per family of case), `driver/` (walk → select → run → report).
 *
 * Everything is a plain global function returning `['passed' => bool, 'message' => string]`.
 */

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/process.php';
require_once __DIR__ . '/workspace.php';
require_once __DIR__ . '/results.php';
require_once __DIR__ . '/report.php';
require_once __DIR__ . '/progress.php';
require_once __DIR__ . '/diagnostics.php';
require_once __DIR__ . '/ir_fixture.php';
require_once __DIR__ . '/discovery.php';

require_once __DIR__ . '/../kinds.php';

require_once __DIR__ . '/../runners/standalone.php';
require_once __DIR__ . '/../runners/stage.php';
require_once __DIR__ . '/../runners/runtime.php';

require_once __DIR__ . '/args.php';
require_once __DIR__ . '/../driver/select.php';
require_once __DIR__ . '/../driver/run.php';
require_once __DIR__ . '/../driver/list.php';
require_once __DIR__ . '/../driver/parallel.php';
