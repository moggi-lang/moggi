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
 * The pipeline uses this with its `CompilationContext`; `codegen()` remains for
 * callers that still resolve the target from process state.
 *
 * @param array<string, mixed> $options
 * @return string|array<string, string>
 */
function codegenWith(Backend $backend, Module $ir, string $sourcePath, array $options = []): string|array
{
    return $backend->emit($ir, $sourcePath, $options);
}

/**
 * @param array<string, mixed> $options
 * @return string|array<string, string>
 */
function codegen(Module $ir, string $sourcePath, array $options = []): string|array
{
    return codegenWith(currentBackend(), $ir, $sourcePath, $options);
}
