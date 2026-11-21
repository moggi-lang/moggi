<?php declare(strict_types=1);

/**
 * The suite's probes are the CLI's, so the two worker counts are computed by the same code.
 * `Moggi\Platform` holds them; callers here and in the driver use the namespaced names.
 */
require_once \dirname(__DIR__, 3) . '/src/platform.php';
