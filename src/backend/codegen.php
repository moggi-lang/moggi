<?php declare(strict_types=1);

namespace Moggi\Backend;

use Moggi\IR\Module;

require_once __DIR__ . '/Backend.php';
require_once __DIR__ . '/inspect.php';
require_once __DIR__ . '/php/PhpBackend.php';
require_once __DIR__ . '/jvm/JvmBackend.php';
require_once __DIR__ . '/dotnet/DotNetBackend.php';

/**
 * @param array<string, mixed> $options
 * @return string|array<string, string>
 */
function codegen(Module $ir, string $sourcePath, array $options = []): string|array
{
    return currentBackend()->emit($ir, $sourcePath, $options);
}
