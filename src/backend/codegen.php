<?php declare(strict_types=1);

namespace Moggi\Backend;

use Moggi\IR\Module;

require_once __DIR__ . '/Backend.php';
require_once __DIR__ . '/inspect.php';
require_once __DIR__ . '/php/PhpBackend.php';
require_once __DIR__ . '/jvm/JvmBackend.php';
require_once __DIR__ . '/dotnet/DotNetBackend.php';

/**
 * Emit through an explicitly chosen backend.
 *
 * The backend is an argument, never process state: the pipeline passes the one its
 * `CompilationContext` names. There is no `codegen()` that resolves a target from a global.
 *
 * @param array<string, mixed> $options
 * @return array<string, string>
 */
function codegenWith(Backend $backend, Module $ir, string $sourcePath, array $options = []): array
{
    if (! ($options['plan'] ?? null) instanceof \Moggi\Modules\EmitPlan) {
        $options['plan'] = $backend->emitPlan((string) ($options['outputRelative'] ?? ''), $options);
    }

    return $backend->emit($ir, $sourcePath, $options);
}
