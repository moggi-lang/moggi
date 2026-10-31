<?php declare(strict_types=1);

/**
 * The foreign-type registry is keyed by the module that declared the name.
 *
 * Two modules may each declare `Path` against a different host type — this corpus does,
 * `java.lang.String` and `java.nio.file.Path` — so the facts cannot live in one bare-name
 * map: the last module checked would answer for both. These cases pin the three behaviours
 * the key has to provide, and the one it must still allow: re-checking the same module (what
 * the REPL and LSP do on every edit) replaces its own entry instead of colliding with it.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Backend\DotNet\Foreign\dotNetDeclaredForeignHostTypes;
use function Moggi\Backend\DotNet\Foreign\dotNetForeignTypeDeclarations;
use function Moggi\Backend\DotNet\Foreign\lookupDotNetForeignType;
use function Moggi\Backend\DotNet\Foreign\registerDotNetForeignType;
use function Moggi\Backend\Jvm\Foreign\jvmDeclaredForeignHostTypes;
use function Moggi\Backend\Jvm\Foreign\jvmForeignTypeDeclarations;
use function Moggi\Backend\Jvm\Foreign\jvmForeignTypeDescriptorOrFail;
use function Moggi\Backend\Jvm\Foreign\jvmIsInterface;
use function Moggi\Backend\Jvm\Foreign\lookupJvmForeignType;
use function Moggi\Backend\Jvm\Foreign\registerJvmForeignType;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$jvmSaved = $GLOBALS['moggi_jvm_foreign_types'] ?? null;
$dotnetSaved = $GLOBALS['moggi_dotnet_foreign_types'] ?? null;

try {
    $GLOBALS['moggi_jvm_foreign_types'] = [];
    $GLOBALS['moggi_dotnet_foreign_types'] = [];

    // A re-check of the same module replaces its own declaration; it is not a collision.
    registerJvmForeignType('Paths', 'Path', 'java.nio.file.Path');
    registerJvmForeignType('Paths', 'Path', 'java.lang.String');
    $assert(
        lookupJvmForeignType('Path', 'Paths') === 'java.lang.String',
        'a second declaration in the same module replaces the first, got: '
        . \var_export(lookupJvmForeignType('Path', 'Paths'), true),
    );

    // Two modules, one name, different host types: each module reads its own.
    registerJvmForeignType('FilePaths', 'Path', 'java.nio.file.Path');
    $assert(
        lookupJvmForeignType('Path', 'Paths') === 'java.lang.String',
        'the first module still answers with what it declared',
    );
    $assert(
        lookupJvmForeignType('Path', 'FilePaths') === 'java.nio.file.Path',
        'the second module answers with what it declared',
    );
    $assert(
        \count(jvmForeignTypeDeclarations('Path')) === 2,
        'both declarations are facts about the name, one per declaring module',
    );

    // With no module to answer from, agreement resolves and disagreement is refused.
    $refused = null;

    try {
        lookupJvmForeignType('Path');
    } catch (\RuntimeException $e) {
        $refused = $e->getMessage();
    }
    $assert($refused !== null, 'a module-less read of a disagreeing name must be refused');
    $assert(
        \str_contains($refused, '`Paths` says java.lang.String')
        && \str_contains($refused, '`FilePaths` says java.nio.file.Path'),
        'the refusal names both modules and both host types, got: ' . $refused,
    );

    registerJvmForeignType('Places', 'Place', 'java.lang.String');
    registerJvmForeignType('Spots', 'Place', 'java.lang.String');
    $assert(
        lookupJvmForeignType('Place') === 'java.lang.String',
        'modules that agree answer a module-less read with the one host type they all declared',
    );

    // The signature builder is where the answer turns into an emitted descriptor: each module's
    // declaration must be the one that answers, and the path that arrives without a module refuses.
    $assert(
        jvmForeignTypeDescriptorOrFail('Path', 'Paths') === 'Ljava/lang/String;',
        'the descriptor builder did not read the declaring module\'s host type, got: '
        . jvmForeignTypeDescriptorOrFail('Path', 'Paths'),
    );
    $assert(
        jvmForeignTypeDescriptorOrFail('Path', 'FilePaths') === 'Ljava/nio/file/Path;',
        'the descriptor builder answered one module with another module\'s host type, got: '
        . jvmForeignTypeDescriptorOrFail('Path', 'FilePaths'),
    );
    $descriptorRefused = null;

    try {
        jvmForeignTypeDescriptorOrFail('Path');
    } catch (\RuntimeException $e) {
        $descriptorRefused = $e->getMessage();
    }
    $assert(
        $descriptorRefused !== null && \str_contains($descriptorRefused, '`FilePaths` says java.nio.file.Path'),
        'a descriptor built with no module to answer from was resolved instead of refused, got: '
        . ($descriptorRefused ?? '<no refusal>'),
    );

    // Interface membership is a set question: the declaring module is not part of it.
    registerJvmForeignType('Streams', 'Closeable', 'interface java.io.Closeable');
    $assert(jvmIsInterface('java/io/Closeable'), 'a declared interface is an interface');
    $assert(! jvmIsInterface('java/io/Closeable2'), 'a name nobody declared is not');
    $assert(
        \in_array('interface java.io.Closeable', jvmDeclaredForeignHostTypes(), true),
        'membership walks every declaring module, not one flat name map',
    );

    // The .NET registry carries the same key, and the same two answers.
    registerDotNetForeignType('Streams', 'Stream', 'System.IO.MemoryStream');
    registerDotNetForeignType('Streams', 'Stream', 'System.IO.Stream');
    $assert(
        lookupDotNetForeignType('Stream', 'Streams') === 'System.IO.Stream',
        'a re-declaration in the same .NET module replaces the first',
    );

    registerDotNetForeignType('Streams2', 'Stream', 'System.IO.MemoryStream');
    $assert(
        lookupDotNetForeignType('Stream', 'Streams') === 'System.IO.Stream'
        && lookupDotNetForeignType('Stream', 'Streams2') === 'System.IO.MemoryStream',
        'each .NET module reads the host type it declared',
    );
    $assert(
        \count(dotNetForeignTypeDeclarations('Stream')) === 2,
        'both .NET declarations are recorded under their own modules',
    );
    $assert(
        \in_array('System.IO.MemoryStream', dotNetDeclaredForeignHostTypes(), true),
        'value-type membership reads every declaring module',
    );

    echo "foreign type registry tests passed\n";
} finally {
    if ($jvmSaved === null) {
        unset($GLOBALS['moggi_jvm_foreign_types']);
    } else {
        $GLOBALS['moggi_jvm_foreign_types'] = $jvmSaved;
    }
    if ($dotnetSaved === null) {
        unset($GLOBALS['moggi_dotnet_foreign_types']);
    } else {
        $GLOBALS['moggi_dotnet_foreign_types'] = $dotnetSaved;
    }
}
