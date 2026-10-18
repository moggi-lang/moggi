<?php declare(strict_types=1);

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Backend\DotNet\Codegen\EmitEnv;
use Moggi\Backend\DotNet\Codegen\MethodIl;
use function Moggi\Backend\DotNet\Codegen\emitDotNetConstLiteral;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

/** Emit one literal the way `emitForeign` does, answering [accepted, IL]. */
$emit = static function (array $lit, string $desc): array {
    $m = new MethodIl();
    $env = new EmitEnv($m, 'TestOwner', [], []);
    $ok = emitDotNetConstLiteral($env, $lit, $desc);

    return [$ok, $m->render('Test', [])];
};

// `bool` has no BCL literal field, so this is its only execution.
[$ok, $il] = $emit(['kind' => 'bool', 'value' => '1'], 'bool');
$assert($ok === true, 'a true bool literal must be accepted');
$assert(\str_contains($il, 'ldc.i4.1'), 'a true bool literal emits `ldc.i4.1`: ' . $il);
$assert(\str_contains($il, 'box bool'), 'a bool literal boxes as `bool`: ' . $il);

[$ok, $il] = $emit(['kind' => 'bool', 'value' => '0'], 'bool');
$assert($ok === true, 'a false bool literal must be accepted');
$assert(\str_contains($il, 'ldc.i4.0'), 'a false bool literal emits `ldc.i4.0`: ' . $il);

// `float32` has no Moggi type, so this is the r4 arm's only execution.
[$ok, $il] = $emit(['kind' => 'r4', 'value' => '3.1415927'], 'float32');
$assert($ok === true, 'an r4 literal must be accepted');
$assert(\str_contains($il, 'ldc.r4 3.1415927'), 'an r4 literal emits `ldc.r4`: ' . $il);

// Regression pins for the arms the runtime test also executes.
[$ok, $il] = $emit(['kind' => 'i4', 'value' => '2147483647'], 'int32');
$assert($ok === true, 'an i4 literal must be accepted');
$assert(\str_contains($il, 'ldc.i4 2147483647'), 'an i4 literal emits `ldc.i4`: ' . $il);

[$ok, $il] = $emit(['kind' => 'i8', 'value' => '9223372036854775807'], 'int64');
$assert($ok === true, 'an i8 literal must be accepted');
$assert(\str_contains($il, 'ldc.i8 9223372036854775807'), 'an i8 literal emits `ldc.i8`: ' . $il);

[$ok, $il] = $emit(['kind' => 'r8', 'value' => '3.141592653589793'], 'float64');
$assert($ok === true, 'an r8 literal must be accepted');
$assert(\str_contains($il, 'ldc.r8 3.141592653589793'), 'an r8 literal emits `ldc.r8`: ' . $il);

[$ok, $il] = $emit(['kind' => 'string', 'value' => 'PortablePdb'], 'string');
$assert($ok === true, 'a string literal must be accepted');
$assert(\str_contains($il, 'ldstr "PortablePdb"'), 'a string literal emits `ldstr`: ' . $il);

[$ok, $il] = $emit(['kind' => 'string', 'value' => null], 'string');
$assert($ok === true, 'a null string literal must be accepted');
$assert(\str_contains($il, 'ldnull'), 'a null string literal emits `ldnull`: ' . $il);

// An unknown kind keeps the `ldsfld` fallback by answering false.
[$ok] = $emit(['kind' => 'decimal', 'value' => '1.5'], 'object');
$assert($ok === false, 'an unknown literal kind must be refused');

echo "dotnet const literal emitter tests passed\n";
