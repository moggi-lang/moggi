<?php declare(strict_types=1);

namespace Moggi\CLI;

use function Moggi\Backend\assertBackend;
use function Moggi\Backend\implementedBackendIds;
use function Moggi\Backend\isBackendImplemented;

function parseBackendValue(?string $value): string
{
    $backend = $value ?? 'php';

    try {
        assertBackend($backend);
        if (!isBackendImplemented($backend)) {
            throw new \InvalidArgumentException(
                "code generation for backend `{$backend}` is not implemented (available: "
                . \implode(', ', implementedBackendIds()) . ')',
            );
        }
    } catch (\InvalidArgumentException $e) {
        \fwrite(STDERR, "error: {$e->getMessage()}\n\n");
        printUsage();
        exit(1);
    }

    return $backend;
}
